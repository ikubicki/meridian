# Legacy phpBB Extension System Analysis

## 1. Discovery Mechanism

**Source**: `src/phpbb/forums/extension/manager.php:370-410`

Extensions are discovered via **filesystem scanning** of `src/phpbb/ext/` directory:

```php
$iterator = new \RecursiveIteratorIterator(
    new \phpbb\recursive_dot_prefix_filter_iterator(
        new \RecursiveDirectoryIterator($this->phpbb_root_path . 'src/phpbb/ext/', ...)
    ),
    \RecursiveIteratorIterator::SELF_FIRST
);
$iterator->setMaxDepth(2);

foreach ($iterator as $file_info) {
    if ($file_info->isFile() && $file_info->getFilename() == 'composer.json') {
        $ext_name = $iterator->getInnerIterator()->getSubPath();
        // validates availability
    }
}
```

**Key characteristics**:
- Uses `RecursiveDirectoryIterator` with max depth 2 (enforces `vendor/name` structure)
- Looks for `composer.json` as the marker file
- Extension path pattern: `src/phpbb/ext/{vendor}/{name}/`
- No Composer autoloading for discovery — purely directory scan
- Extensions have their own `vendor/autoload.php` loaded if present (`container_builder.php:482`)
- Results are cached via `phpbb\cache\service` under `_ext` key

## 2. Manifest Format

**Source**: `src/phpbb/forums/extension/metadata_manager.php`

Uses **standard `composer.json`** with phpBB-specific fields:

```json
{
    "name": "phpbb/viglink",
    "type": "phpbb-extension",
    "version": "1.0.5",
    "license": "GPL-2.0-only",
    "authors": [{ "name": "...", "email": "..." }],
    "require": {
        "php": ">=5.4",
        "phpbb/phpbb": ">=3.2.0"
    },
    "extra": {
        "display-name": "VigLink",
        "soft-require": {
            "phpbb/phpbb": ">=3.2.0-b1,<4.0.0@dev"
        },
        "version-check": {
            "host": "...",
            "directory": "...",
            "filename": "..."
        }
    }
}
```

**Validation rules** (`metadata_manager.php:138-145`):
- `name` must match `vendor/name` pattern (2+ chars each): `#^[a-zA-Z0-9_\x7f-\xff]{2,}/[a-zA-Z0-9_\x7f-\xff]{2,}$#`
- `type` must be exactly `phpbb-extension`
- `license` required
- `version` required
- `authors` must have at least one with `name`
- `extra.soft-require.phpbb/phpbb` required for enable validation
- Directory structure must match `name` field

## 3. Lifecycle Management

**Source**: `src/phpbb/forums/extension/manager.php:210-350`, `extension_interface.php`

Three states: **enabled**, **disabled**, **purged** (not in DB).

### State Machine:
```
[Not Installed] → enable_step() → [Enabled]
[Enabled] → disable_step() → [Disabled]
[Disabled] → purge_step() → [Not Installed]
[Disabled] → enable_step() → [Enabled]
```

### Database table `phpbb_ext`:
| Column | Type | Description |
|--------|------|-------------|
| `ext_name` | varchar(255) UNIQUE | Extension identifier (vendor/name) |
| `ext_active` | tinyint(1) | 1=enabled, 0=disabled |
| `ext_state` | text | Serialized intermediate state |

### Step-based execution:
The lifecycle uses a **step pattern** to handle long-running operations within PHP's `max_execution_time`:

```php
public function enable_step($name) {
    $old_state = unserialize($this->extensions[$name]['ext_state']);
    $extension = $this->get_extension($name);
    $state = $extension->enable_step($old_state);
    $active = ($state === false); // false means "done"
    // ... save state
    return !$active; // true = more steps needed
}
```

Each step returns either:
- `false` → operation complete
- any other value → serialized and passed as `$old_state` to next step

### Extension class resolution:
```php
// Tries: \vendor\name\ext class
$extension_class_name = str_replace('/', '\\', $name) . '\\ext';
// Falls back to: \phpbb\extension\base
```

### `is_enableable()` gate:
Extensions can block enable via `is_enableable()` returning `false` or an array of reasons.

## 4. Migration System

**Source**: `src/phpbb/forums/db/migration/migration.php`, `migration_interface.php`, `src/phpbb/ext/phpbb/viglink/migrations/`

### Structure:
```php
abstract class migration implements migration_interface {
    static public function depends_on(); // dependency chain
    public function effectively_installed(); // skip if true
    public function update_schema(); // DDL changes
    public function revert_schema(); // DDL rollback
    public function update_data(); // DML changes
    public function revert_data(); // DML rollback
}
```

### Data operations use a DSL:
```php
public function update_data() {
    return array(
        array('config.add', array('viglink_enabled', 0)),
        array('config.remove', array('viglink_api_key')),
        array('module.add', array('acp', 'ACP_BOARD_CONFIGURATION', array(...))),
    );
}
```

### Discovery of migrations:
```php
// base.php - finds migrations automatically
$migrations = $this->extension_finder
    ->extension_directory('/migrations')
    ->find_from_extension($this->extension_name, $this->extension_path);
$migrations = $this->extension_finder->get_classes_from_files($migrations);
$this->migrator->set_migrations($migrations);
```

### Dependency chain:
```php
public static function depends_on() {
    return array('\phpbb\db\migration\data\v31x\v312');
}
```

### Migrator (`db/migrator.php`):
- Tracks state in a `migrations` table
- Resolves dependency order
- Supports forward migration (`update()`) and rollback (`revert()`)
- Used by `base::enable_step()` and `base::purge_step()`

## 5. Event/Hook System

**Source**: `src/phpbb/forums/event/dispatcher.php`, viewtopic.php examples, viglink services.yml

### Two systems coexist:

#### A) Legacy Hooks (pre-3.1)
**Source**: `src/phpbb/forums/hook/finder.php`
- Scans `src/phpbb/common/hooks/` directory for files named `hook_*.php`
- Simple, procedural hook system
- **Deprecated** — only used for backward compat

#### B) Event Dispatcher (3.1+)
Based on **Symfony EventDispatcher** with phpBB sugar:

```php
// In core code (viewtopic.php):
/**
 * @event core.viewtopic_modify_forum_id
 * @var string forum_id
 * @var array topic_data
 * @since 3.2.5-RC1
 */
$vars = array('forum_id', 'topic_data');
extract($phpbb_dispatcher->trigger_event('core.viewtopic_modify_forum_id', compact($vars)));
```

**Pattern**: `compact()` → `trigger_event()` → `extract()` — allows listeners to modify local variables.

#### Extension Listeners:
Extensions subscribe via `EventSubscriberInterface`:

```php
class listener implements EventSubscriberInterface {
    public static function getSubscribedEvents() {
        return array(
            'core.viewtopic_post_row_after' => 'display_viglink',
        );
    }
}
```

#### Registration:
Services tagged with `event.listener` are auto-registered:
```yaml
services:
    phpbb.viglink.listener:
        class: phpbb\viglink\event\listener
        tags:
            - { name: event.listener }
```

The `RegisterListenersPass` compiler pass connects tagged services to the dispatcher (`container_builder.php:212`).

## 6. DI Integration

**Source**: `src/phpbb/forums/extension/di/extension_base.php`, `src/phpbb/forums/di/container_builder.php:460-500`

### Service Registration:
Each extension can provide a `config/services.yml` file (or environment-specific variants):

**Resolution order** (`extension_base.php:68-93`):
1. `ext_path/config/{environment}/container/environment.yml`
2. `ext_path/config/default/container/environment.yml`
3. `ext_path/config/services.yml` (fallback)

### Container Extension Class:
```php
// Tries to load: \vendor\name\di\extension
$extension_class = '\\' . str_replace('/', '\\', $ext_name) . '\\di\\extension';
// Falls back to:
$extension_class = '\\phpbb\\extension\\di\\extension_base';
```

### Compiler Passes:
Extensions can register custom compiler passes (`container_builder.php:677-700`):
- Scans `ext/{vendor}/{name}/di/pass/*_pass.php`
- Must implement `CompilerPassInterface`
- Auto-detected via Symfony Finder

### Autoloading:
Extension vendor autoloaders are aggregated and cached:
```php
$filename = $path . 'vendor/autoload.php';
if (file_exists($filename)) {
    $autoloaders .= "require('{$filename}');\n";
}
```

## 7. Strengths to Preserve

1. **Step-based lifecycle** — Handles long-running operations gracefully in web context; state can be resumed across requests.

2. **Migration system with dependencies** — `depends_on()` creates a DAG of migrations; automatic ordering; revert support. Migrations are the single source of truth for schema.

3. **Declarative data operations** — `array('config.add', ...)` DSL in migrations is auto-revertable. Clean and auditable.

4. **Symfony-based DI** — Leveraging Symfony's container, compiler passes, and YAML service definitions is battle-tested and flexible.

5. **Event subscriber pattern** — `EventSubscriberInterface` is familiar to Symfony developers, and the tag-based auto-registration is clean.

6. **`is_enableable()` pre-check** — Prevents enabling incompatible extensions with clear error messages.

7. **Version checking** — Built-in mechanism via `extra.version-check` in composer.json.

8. **Composer-based metadata** — Using composer.json avoids a custom format; ecosystem tooling works.

## 8. Weaknesses to Avoid

1. **`extract()`/`compact()` event pattern** — Extremely fragile and error-prone:
   - No type safety
   - Variables silently created/overwritten in caller scope
   - Hard to trace data flow in IDE
   - No way to type-hint event parameters

2. **Filesystem-only discovery** — No registry beyond the DB `phpbb_ext` table. Extensions must physically exist in `src/phpbb/ext/`. No support for Composer-installed locations, symlinks are fragile.

3. **Serialized state in DB** — `ext_state` column stores PHP `serialize()` output. Opaque, hard to debug, potential security issue with unserialize.

4. **No dependency resolution between extensions** — Extensions can't declare dependencies on other extensions (only on core versions via `soft-require`). No inter-extension dependency graph.

5. **Global namespace pollution** — Legacy hooks still scan a shared directory. Extensions' PHP classes must follow a rigid namespace/directory mapping.

6. **No sandboxing or capability system** — Any enabled extension has full access to the container, database, and filesystem. No permission model.

7. **Cache invalidation is coarse** — `cache->deferred_purge()` on any state change. No granular invalidation.

8. **No versioned API contract** — Extensions depend on internal classes and event names. No semver contract for what's a breaking change in the extension API.

9. **Monolithic enable/disable** — Extensions are all-or-nothing. Can't partially enable features within an extension.

10. **No async/queue support** — Migration steps are synchronous. Long migrations can timeout despite the step mechanism.

11. **Brittle DI extension detection** — Relies on class naming convention (`\vendor\name\di\extension`). No explicit declaration of DI integration.

---

## Summary

The legacy system is a **directory-scanned, Symfony-DI-integrated extension framework** with:
- Composer JSON manifests
- Step-based enable/disable/purge lifecycle
- A powerful but independent migration subsystem
- Symfony EventDispatcher for hooks (with an unfortunate extract/compact pattern)
- YAML-based service registration

The architecture is fundamentally sound but suffers from tight coupling to filesystem layout, lack of inter-extension dependencies, coarse security model, and the fragile event variable extraction pattern.
