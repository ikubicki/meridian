# Plugin/Extension Architecture Patterns in phpBB

## 1. Extension System

### 1.1. Extension Registration

Extensions live in `src/phpbb/ext/{vendor}/{name}/` and are registered via two mechanisms:

**composer.json** — Extension metadata and dependencies:
```json
// Source: src/phpbb/ext/phpbb/viglink/composer.json
{
    "name": "phpbb/viglink",
    "type": "phpbb-extension",
    "version": "1.0.5",
    "require": {
        "php": ">=5.4",
        "phpbb/phpbb": ">=3.2.0"
    },
    "extra": {
        "display-name": "VigLink",
        "soft-require": {
            "phpbb/phpbb": ">=3.2.0-b1,<4.0.0@dev"
        }
    }
}
```
- `type: phpbb-extension` is conventional
- `extra.display-name` provides human-readable name
- `extra.soft-require` defines version compatibility range

**ext.php** — Extension meta class (lifecycle hooks):
```php
// Source: src/phpbb/ext/phpbb/viglink/ext.php
namespace phpbb\viglink;

class ext extends \phpbb\extension\base
{
    public function is_enableable()
    {
        return phpbb_version_compare(PHPBB_VERSION, '3.2.0-b1', '>=');
    }

    public function enable_step($old_state)
    {
        // Custom enable logic (e.g., set config values)
        // Returns false when done, otherwise intermediate state
    }
}
```

**Database registration**: The `extension_manager` stores state in the `phpbb_ext` table with columns: `ext_name`, `ext_active`, `ext_state`.

**Discovery** (`extension\manager::all_available()`, line ~390): Recursively scans `src/phpbb/ext/` for `composer.json` files at depth ≤2. Extension name = subdirectory path (e.g., `phpbb/viglink`).

### 1.2. Extension Lifecycle

**Source**: `src/phpbb/forums/extension/extension_interface.php`, `src/phpbb/forums/extension/manager.php`

The lifecycle has three phases, each supporting **multi-step execution** for long-running operations:

| Phase | Method | Description |
|-------|--------|-------------|
| **Enable** | `enable_step($old_state)` | Runs migrations, returns `false` when complete |
| **Disable** | `disable_step($old_state)` | Cleanup, returns `false` when complete |
| **Purge** | `purge_step($old_state)` | Reverts migrations, removes data, returns `false` when complete |

**Key detail**: Steps return intermediate state (serialized and stored in DB) or `false` when finished. The manager loops: `while ($this->enable_step($name));` — enabling long-running migration sequences across multiple HTTP requests.

**Gatekeeper**: `is_enableable()` — called before enabling. Returns `true`, `false`, or array of error messages.

### 1.3. Extension Interface Hierarchy

```
extension_interface          — Contract: is_enableable(), enable_step(), disable_step(), purge_step()
  └── base                   — Default implementation: auto-discovers and runs migrations
       └── ext (per-extension) — Custom overrides (e.g., viglink\ext)
```

**`extension\base`** (source: `src/phpbb/forums/extension/base.php`):
- Constructor receives: `ContainerInterface`, `\phpbb\finder`, `\phpbb\db\migrator`, extension name, extension path
- `enable_step()`: Auto-discovers migration files in `{ext_path}/migrations/`, runs them via migrator
- `disable_step()`: No-op by default (returns `false`)
- `purge_step()`: Reverts all installed migrations

### 1.4. Metadata Manager

**Source**: `src/phpbb/forums/extension/metadata_manager.php`

Reads and validates `composer.json` for each extension. Provides `get_metadata($element)` supporting:
- `'all'` — full metadata + validation
- `'version'`, `'name'` — specific fields
- `'display-name'` — from `extra.display-name` or falls back to `name`

---

## 2. Event Dispatcher Pattern

### 2.1. Architecture

**Source**: `src/phpbb/forums/event/dispatcher.php`, `src/phpbb/forums/event/dispatcher_interface.php`

phpBB wraps Symfony EventDispatcher (v3.x, `symfony/event-dispatcher` requiring PHP ^5.5.9|>=7.0.8) with a custom `trigger_event()` method:

```php
// Source: src/phpbb/forums/event/dispatcher.php
class dispatcher extends EventDispatcher implements dispatcher_interface
{
    public function trigger_event($eventName, $data = [])
    {
        $event = new \phpbb\event\data($data);
        $this->dispatch($eventName, $event);
        return $event->get_data_filtered(array_keys($data));
    }

    public function dispatch($eventName, Event $event = null)
    {
        if ($this->disabled) { return $event; }
        foreach ((array) $eventName as $name) {
            $event = parent::dispatch($name, $event);
        }
        return $event;
    }
}
```

**Key features**:
- `disable()`/`enable()` — global event dispatcher toggle
- `dispatch()` accepts array of event names (multi-dispatch)
- `trigger_event()` — syntactic sugar using compact/extract pattern

### 2.2. Event Data Model

**Source**: `src/phpbb/forums/event/data.php`

```php
class data extends Event implements \ArrayAccess
{
    private $data;

    public function get_data_filtered($keys) {
        return array_intersect_key($this->data, array_flip($keys));
    }

    public function update_subarray($subarray, $key, $value) {
        $this->data[$subarray][$key] = $value;
    }
}
```

- Implements `\ArrayAccess` — listeners modify data via `$event['key'] = $value`
- `get_data_filtered()` — prevents listeners from injecting new keys (security/stability)

### 2.3. How Events Are Dispatched in Core

**Pattern** (found extensively in `acp_forums.php` and other core files):

```php
// Source: src/phpbb/common/acp/acp_forums.php:171
$vars = array('page_title');
extract($phpbb_dispatcher->trigger_event('core.index', compact($vars)));
```

This compact/extract pattern:
1. Defines which variables to expose: `$vars = ['var1', 'var2']`
2. Creates associative array: `compact($vars)` → `['var1' => $value1, ...]`
3. Dispatches event, listeners can modify values
4. `extract()` writes modified values back to local scope

**Event naming convention**: `core.{component}_{action}` (e.g., `core.acp_manage_forums_request_data`, `core.viewtopic_post_row_after`)

### 2.4. How Listeners Are Registered

**Two mechanisms**:

**A) Symfony EventSubscriberInterface** (for extensions and core kernel subscribers):

```php
// Source: src/phpbb/ext/phpbb/viglink/event/listener.php
class listener implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return [
            'core.viewtopic_post_row_after' => 'display_viglink',
        ];
    }

    public function display_viglink() { /* ... */ }
}
```

Registered in YAML with `event.listener` tag:
```yaml
# Source: src/phpbb/ext/phpbb/viglink/config/services.yml
phpbb.viglink.listener:
    class: phpbb\viglink\event\listener
    arguments: ['@config', '@template']
    tags:
        - { name: event.listener }
```

**B) Kernel event subscribers** (tagged `kernel.event_subscriber` for Symfony HttpKernel events):

```yaml
# Source: services_event.yml
kernel_exception_subscriber:
    class: phpbb\event\kernel_exception_subscriber
    tags:
        - { name: kernel.event_subscriber }
```

---

## 3. Dependency Injection Integration

### 3.1. Service Definitions (YAML)

**Source**: `src/phpbb/common/config/default/container/services.yml`

Core services are defined in modular YAML files, imported from a master `services.yml`:
```yaml
imports:
    - { resource: services_auth.yml }
    - { resource: services_notification.yml }
    - { resource: services_event.yml }
    # ... 25+ service files
```

The dispatcher itself:
```yaml
# Source: services_event.yml
dispatcher:
    class: phpbb\event\dispatcher
    arguments: ['@service_container']
```

### 3.2. How Extensions Add Services

**Source**: `src/phpbb/forums/extension/di/extension_base.php`

Extensions provide services via `config/services.yml` (or environment-specific variants):

```
{ext_path}/config/{environment}/container/environment.yml   ← priority 1
{ext_path}/config/default/container/environment.yml          ← priority 2
{ext_path}/config/services.yml                               ← priority 3 (legacy)
```

The `extension_base` DI Extension (Symfony `Extension` subclass) loads YAML files automatically when the extension is registered in the container.

### 3.3. Service Tags and Collection Pattern

**Source**: `src/phpbb/forums/di/pass/collection_pass.php`

phpBB uses a **service_collection** pattern — a custom compiler pass that auto-discovers tagged services and registers them into collection objects:

```php
// Source: collection_pass.php
class collection_pass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        foreach ($container->findTaggedServiceIds('service_collection') as $id => $data) {
            foreach ($container->findTaggedServiceIds($data[0]['tag']) as $service_id => $service_data) {
                $definition->addMethodCall('add', [$service_id]);
                // For ordered: addMethodCall('add', [$service_id, $order])
                // For class_name_aware: addMethodCall('add_service_class', [$service_id, $class])
            }
        }
    }
}
```

**Usage example — Auth providers** (`services_auth.yml`):
```yaml
auth.provider_collection:
    class: phpbb\auth\provider_collection
    arguments: ['@service_container', '@config']
    tags:
        - { name: service_collection, tag: auth.provider }

auth.provider.db:
    class: phpbb\auth\provider\db
    tags:
        - { name: auth.provider }

auth.provider.ldap:
    class: phpbb\auth\provider\ldap
    tags:
        - { name: auth.provider }
```

**Usage example — Notification types** (`services_notification.yml`):
```yaml
notification.type_collection:
    class: phpbb\di\service_collection
    tags:
        - { name: service_collection, tag: notification.type }

notification.type.bookmark:
    class: phpbb\notification\type\bookmark
    shared: false
    parent: notification.type.post
    tags:
        - { name: notification.type }
```

### 3.4. Service Collection Classes

**Source**: `src/phpbb/forums/di/service_collection.php`, `ordered_service_collection.php`

```
service_collection extends \ArrayObject
  ├── Lazy loading: offsetGet($index) → $container->get($index)
  ├── add($name) — registers service ID
  ├── add_service_class($service_id, $class) — class mapping
  ├── get_by_class($class) — reverse lookup
  └── ordered_service_collection extends service_collection
       └── add($service_id, $order) — with ordering priority
```

Key: Services are lazily loaded from the container — the collection stores IDs, not instances.

---

## 4. Real Extension Example: VigLink

**Source**: `src/phpbb/ext/phpbb/viglink/`

### Directory Structure
```
viglink/
├── composer.json          — Metadata
├── ext.php                — Lifecycle (is_enableable, enable_step)
├── config/
│   └── services.yml       — DI service definitions
├── event/
│   ├── listener.php       — EventSubscriberInterface (core.viewtopic_post_row_after)
│   └── acp_listener.php   — EventSubscriberInterface (core.acp_main_notice, core.acp_help_phpbb_submit_before)
├── migrations/
│   ├── viglink_data.php   — Config values + ACP module registration
│   ├── viglink_data_v2.php
│   ├── viglink_cron.php
│   └── ...
├── acp/                   — Admin module
├── cron/                  — Cron tasks
├── language/              — Language files
└── styles/                — Template overrides
```

### Key Patterns Demonstrated

1. **Event hooking**: Two listeners registered via `services.yml` with `event.listener` tag
2. **Migrations**: Database schema + config changes in migration classes extending `\phpbb\db\migration\migration`
3. **Service dependencies**: Listeners receive core services (`@config`, `@template`, `@language`) via constructor injection
4. **ACP integration**: Module registered via migration `module.add` call
5. **Cron tasks**: Separate cron module in `cron/` directory
6. **Version gating**: `is_enableable()` checks phpBB version

---

## 5. Pluggable Component Patterns

phpBB uses three main patterns for swappable/extensible components:

### 5.1. Interface + Collection Pattern (Primary)

Used for: auth providers, notification types, notification methods, cron tasks, OAuth services

```
interface (contract)
  └── base (abstract, shared logic)
       ├── concrete_a
       ├── concrete_b
       └── ... (extensions can add more)

service_collection (tagged container)
  └── Collects all tagged implementations at compile time
```

**Examples found**:

| Component | Interface | Tag | Collection |
|-----------|-----------|-----|------------|
| Auth providers | `auth\provider\provider_interface` | `auth.provider` | `auth.provider_collection` |
| Notification types | `notification\type\type_interface` | `notification.type` | `notification.type_collection` |
| Notification methods | `notification\method\method_interface` | `notification.method` | `notification.method_collection` |
| Cron tasks | `cron\task\task` | (via provider) | cron manager |
| OAuth services | — | `auth.provider.oauth.service` | `oauth.service_collection` |

### 5.2. Event Hook Pattern

Used for: modifying core behavior without replacing services. Extensions listen to named events and modify the event data.

**Flow**: Core dispatches → Listeners modify `$event['data']` → Core extracts modified values

### 5.3. Provider Pattern

**Source**: `src/phpbb/forums/extension/provider.php`

Abstract class wrapping extension manager discovery:
```php
abstract class provider implements \IteratorAggregate
{
    protected $extension_manager;

    abstract protected function find();

    public function getIterator() {
        if ($this->items === null) {
            $this->items = $this->find();
        }
        return new \ArrayIterator($this->items);
    }
}
```

Used for components that need to discover items across extensions (e.g., template paths, cron task names).

---

## 6. Modern PHP 8.2 Recommendations for Hierarchy Service

Based on the patterns analyzed, here are recommendations for designing an extensible hierarchy service:

### 6.1. Service Collection Pattern (Recommended Core Pattern)

Define a tagged service collection for hierarchy providers/plugins:

```yaml
# services_hierarchy.yml
hierarchy.provider_collection:
    class: phpbb\di\service_collection
    arguments: ['@service_container']
    tags:
        - { name: service_collection, tag: hierarchy.provider }

hierarchy.provider.nested_set:
    class: phpbb\hierarchy\provider\nested_set
    arguments: ['@dbal.conn']
    tags:
        - { name: hierarchy.provider }
```

Extensions can then register additional hierarchy providers by tagging services with `hierarchy.provider`.

### 6.2. PHP 8.2 Interface Contracts

```php
namespace phpbb\hierarchy\provider;

interface hierarchy_provider_interface
{
    public function get_type(): string;
    public function get_children(int $parent_id): array;
    public function move_node(int $node_id, int $new_parent_id, int $position): void;
    public function supports(string $entity_type): bool;
}
```

Use `readonly` classes and constructor promotion for value objects:
```php
readonly class HierarchyNode
{
    public function __construct(
        public int $id,
        public int $parentId,
        public int $left,
        public int $right,
        public int $depth,
    ) {}
}
```

### 6.3. Event Hooks for Hierarchy Operations

Follow phpBB's existing `trigger_event` pattern for key extensibility points:

```php
// Before move
$vars = ['node_id', 'new_parent_id', 'position', 'entity_type'];
extract($dispatcher->trigger_event('core.hierarchy_move_before', compact($vars)));

// After move
$vars = ['node_id', 'old_parent_id', 'new_parent_id', 'entity_type'];
extract($dispatcher->trigger_event('core.hierarchy_move_after', compact($vars)));
```

**Suggested events**:
- `core.hierarchy_node_create_before` / `_after`
- `core.hierarchy_node_move_before` / `_after`
- `core.hierarchy_node_delete_before` / `_after`
- `core.hierarchy_tree_rebuild_after`

### 6.4. PHP Attributes (Future Enhancement)

While phpBB currently uses Symfony 3.x EventSubscriber, for new code consider PHP 8.2 attributes for cleaner listener registration:

```php
#[AsEventListener('core.hierarchy_node_move_after')]
public function onNodeMoved(data $event): void
{
    // Invalidate cache, update permissions, etc.
}
```

This would require a custom compiler pass or Symfony 6+ attribute support. For initial implementation, stick with `EventSubscriberInterface` + `event.listener` tag for compatibility.

### 6.5. DI Extension Integration

The hierarchy service should follow the `extension_base` pattern so that extensions can override/extend hierarchy behavior:

```
ext/{vendor}/{name}/config/services.yml:
    my_ext.hierarchy.custom_provider:
        class: my_ext\hierarchy\custom_provider
        tags:
            - { name: hierarchy.provider }
```

### 6.6. Summary of Recommended Architecture

```
phpbb\hierarchy\
├── hierarchy_interface.php          — Core contract
├── hierarchy_manager.php            — Orchestrator (uses service_collection)
├── provider\
│   ├── provider_interface.php       — Plugin contract
│   ├── nested_set_provider.php      — Default implementation
│   └── (extensions add more)
├── event\
│   └── (events dispatched from manager)
└── di\
    └── (compiler passes if needed)
```

**Pattern**: Manager receives `service_collection` of providers, iterates to find the one that `supports()` the requested entity type. Extensions add new providers by tagging services. Events allow hooks at key lifecycle points without replacing services.

---

## Source Citations

| File | Lines | Content |
|------|-------|---------|
| `src/phpbb/forums/extension/extension_interface.php` | 1-74 | Extension lifecycle contract |
| `src/phpbb/forums/extension/base.php` | 1-150 | Default extension implementation with migration support |
| `src/phpbb/forums/extension/manager.php` | 1-530 | Extension management (enable/disable/purge/discovery) |
| `src/phpbb/forums/extension/metadata_manager.php` | 1-100 | composer.json metadata reading |
| `src/phpbb/forums/extension/provider.php` | 1-75 | Abstract provider pattern |
| `src/phpbb/forums/extension/di/extension_base.php` | 1-120 | DI extension for loading extension services |
| `src/phpbb/forums/event/dispatcher_interface.php` | 1-50 | Event dispatcher contract |
| `src/phpbb/forums/event/dispatcher.php` | 1-85 | Event dispatcher implementation |
| `src/phpbb/forums/event/data.php` | 1-90 | ArrayAccess event data model |
| `src/phpbb/forums/di/pass/collection_pass.php` | 1-65 | Compiler pass for service collections |
| `src/phpbb/forums/di/service_collection.php` | 1-125 | Lazy service collection |
| `src/phpbb/forums/di/ordered_service_collection.php` | 1-120 | Ordered service collection |
| `src/phpbb/forums/di/extension/core.php` | 1-120 | Core DI extension loading |
| `src/phpbb/common/config/default/container/services.yml` | 1-100 | Master service imports |
| `src/phpbb/common/config/default/container/services_auth.yml` | 1-80 | Auth provider collection example |
| `src/phpbb/common/config/default/container/services_notification.yml` | 1-90 | Notification type collection example |
| `src/phpbb/common/config/default/container/services_event.yml` | 1-30 | Dispatcher + kernel subscriber registration |
| `src/phpbb/ext/phpbb/viglink/` | entire | Real extension example |
| `src/phpbb/forums/auth/provider/provider_interface.php` | 1-80 | Auth provider interface (pluggable pattern) |
| `src/phpbb/forums/notification/type/type_interface.php` | 1-200 | Notification type interface (pluggable pattern) |
| `src/phpbb/forums/notification/method/method_interface.php` | 1-50 | Notification method interface |
| `src/phpbb/forums/cron/task/task.php` | 1-55 | Cron task interface |
| `vendor/symfony/event-dispatcher/composer.json` | — | Symfony EventDispatcher v3.x |
