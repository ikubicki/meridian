# Infrastructure Needs: Plugin System Requirements

## 1. Schema Management Requirements

### Current Migration System

The project already has a mature migration framework at `src/phpbb/forums/db/migration/migration.php`:

**Key interfaces** (`migration_interface.php`):
- `depends_on()` — declares migration ordering via dependency graph
- `effectively_installed()` — idempotency check
- `update_schema()` / `revert_schema()` — DDL declarations (add_tables, add_columns, drop_tables, etc.)
- `update_data()` / `revert_data()` — data operations (config.add, module.add, custom callables)

**Schema execution** (`tools_interface.php` — `perform_schema_changes()`):
- Supported operations: `drop_tables`, `add_tables`, `change_columns`, `add_columns`, `drop_keys`, `drop_columns`, `add_primary_keys`, `add_unique_index`, `add_index`
- All DDL goes through `db_tools` abstraction (cross-DBMS)

**Dependency resolution** (`schema_generator.php`):
- Topological sort via `depends_on()` declarations
- Tree-based ordering ensures core migrations run first

**Migrator** (`src/phpbb/forums/db/migrator.php`):
- Container-aware (Symfony `ContainerInterface`)
- Tracks migration state in `phpbb_migrations` table
- Step-based execution (can pause between HTTP requests for long migrations)
- Tool collection via tagged services (`migrator.tool` tag)

### What Plugins Need for Schema Management

| Requirement | Current Support | Gap |
|-------------|----------------|-----|
| Declare plugin tables | ✅ `update_schema()` with `add_tables` | None — pattern exists |
| Migration ordering (core-first) | ✅ `depends_on()` references core migration classes | None |
| Plugin uninstall (revert schema) | ✅ `revert_schema()` + `purge_step()` in extension lifecycle | None — but decision needed: DROP vs KEEP |
| ALTER TABLE for JSON columns | ✅ `add_columns` in `update_schema()` | Need convention: `metadata JSON` column pattern |
| Foreign keys to core tables | ⚠️ db_tools supports index/keys but FK enforcement varies across DBMS | Recommend: **loose coupling** (app-level constraints, not DB FK) |
| Rollback on failed migration | ✅ `revert_schema()` exists | Need error boundary: failed plugin migration must not break core |

### Recommended Plugin Schema Convention

```php
// plugins/vendor/plugin-name/migrations/install_001.php
class install_001 extends \phpbb\db\migration\migration
{
    static public function depends_on(): array
    {
        return [
            '\phpbb\db\migration\data\v400\v400',  // core baseline
        ];
    }

    public function update_schema(): array
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'plugin_vendor_tablename' => [
                    'COLUMNS' => [...],
                    'PRIMARY_KEY' => 'id',
                ],
            ],
        ];
    }
}
```

### Uninstall Policy Decision

- **Option A: DROP tables on purge** — Clean removal, data loss (legacy phpBB approach via `purge_step()`)
- **Option B: Keep tables, mark orphaned** — Safe but accumulates dead tables
- **Recommendation**: Option A (default) with optional `preserve_data: true` in plugin manifest

### JSON Metadata Column Strategy

For plugins extending core entities without DDL:
- Core tables provide a `metadata JSON` column (e.g., `phpbb_topics.metadata`, `phpbb_users.profile_fields`)
- Plugins write to namespaced keys: `{"vendor.plugin_name.key": value}`
- No ALTER TABLE needed — only initial column creation in core migration
- Index strategy: Generated columns for frequently-queried JSON paths (`CREATE INDEX ... ON (JSON_EXTRACT(metadata, '$.vendor.plugin.key'))`)

---

## 2. DI Container Integration Patterns

### Current DI Infrastructure

**Container Builder** (`src/phpbb/forums/di/container_builder.php`):
- Symfony `ContainerBuilder` with `ParameterBag`
- Extensions system: `container_extensions[]` array (core, per-ext, tables, config)
- Compiler passes: `collection_pass` (tag → service_collection), `RegisterListenersPass` (event.listener + kernel.event_subscriber)
- Per-extension DI: `extension_base.php` loads `config/services.yml` from each extension directory
- Extension autoloader generation: dumps `require()` statements for extension vendor/autoload.php
- Proxy/lazy loading: `ProxyManager` via `proxy_instantiator.php` for lazy service instantiation
- Compiled container caching: produces `phpbb_cache_container` PHP class

**Extension service loading** (`extension_base.php`):
- Searches for `config/{environment}/container/environment.yml`
- Falls back to `config/default/container/environment.yml`
- Falls back to `config/services.yml`
- Uses `YamlFileLoader` with realpath resolution

**Tagged services pattern** (`services_event.yml`, `services_migrator.yml`):
- `event.listener` tag → phpBB-style event subscribers
- `kernel.event_subscriber` tag → Symfony-style subscribers
- `migrator.tool` tag → migration tool collection
- `service_collection` tag → generic tagged collection discovery

**CompilerPass registration** (`register_ext_compiler_pass()`):
- Finds `*_pass.php` files in `ext/*/di/pass/` directories
- Auto-registers classes implementing `CompilerPassInterface`
- Extension-specific compiler passes for custom tag resolution

### Plugin DI Integration Design

| Pattern | How It Works | Plugin Usage |
|---------|------------|-------------|
| **Per-plugin services.yml** | `extension_base.php` already loads per-extension DI config | Reuse — each plugin declares services in `config/services.yml` |
| **Tagged services** | `collection_pass` collects services by tag name | Plugin decorators tagged `phpbb.{service}.request_decorator` |
| **CompilerPass** | Extension-specific passes in `di/pass/` directories | Plugin can register custom compiler passes for complex scenarios |
| **Lazy loading** | `ProxyManager` + `InstantiatorInterface` | Plugin services marked `lazy: true` in YAML → proxy until first use |
| **Service isolation** | N/A currently | **Gap**: Need mechanism to prevent plugins overriding core service IDs |

### Service Isolation Strategy

**Problem**: A plugin could define a service with ID `cache` or `dbal.conn`, overriding core services.

**Solutions**:
1. **Namespace enforcement** (recommended): Plugin service IDs MUST be prefixed with `plugin.{vendor}.{name}.` — enforced by a `PluginServicePrefixPass` compiler pass
2. **Private core services**: Mark all core services as `public: false` after container compilation (breaks BC — not recommended)
3. **Service aliasing**: Plugin services that want to "extend" core services must use decoration pattern (`decorates:` in YAML), not replacement

**Recommended CompilerPass**:
```php
class PluginServicePrefixPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Verify all services from plugin configs are properly prefixed
        // Remove/error on any that override protected core service IDs
    }
}
```

### Plugin Service Registration Flow

```
1. Container build starts
2. Core extensions loaded (services.yml imports)
3. Plugin extensions loaded (extension_base.php per plugin)
4. Plugin compiler passes registered (di/pass/*_pass.php)
5. collection_pass: collects tagged services (decorators, subscribers)
6. RegisterListenersPass: collects event.listener tags
7. PluginServicePrefixPass: validates service ID prefixes (NEW)
8. Container compiled + cached
```

---

## 3. Autoloading Strategy

### Current Autoloading Mechanisms

**Composer PSR-4** (`composer.json`):
```json
{
  "autoload": {
    "psr-4": {
      "phpbb\\core\\": "src/phpbb/core/",
      "phpbb\\": "src/phpbb/forums/",
      "phpbb\\admin\\": "src/phpbb/admin/",
      "phpbb\\common\\": "src/phpbb/common/",
      "phpbb\\api\\": "src/phpbb/api/",
      "phpbb\\install\\": "src/phpbb/install/"
    }
  }
}
```

**Legacy class_loader** (DI-registered):
- `class_loader` service: prefix `phpbb\` → `src/phpbb/forums/`
- `class_loader.ext` service: prefix `\` → `src/phpbb/ext/`
- Cached class→path mapping for performance
- Resolves `vendor\extension\class` to `ext/vendor/extension/class.php`

**Extension autoloader generation** (`container_builder.php:load_extensions()`):
- Generates `autoload_*.php` file in cache directory
- `require()`s each extension's `vendor/autoload.php` if present
- Cached and only rebuilt when extension list changes

### Plugin Namespace Convention

**Option A: Under `phpbb\plugin\` namespace** (integrated):
```
Namespace: phpbb\plugin\{vendor}\{name}\
Directory: plugins/{vendor}/{name}/
```
- Pro: Single autoloader, consistent PSR-4
- Con: Implies "part of phpBB" — plugins aren't core

**Option B: Vendor-owned namespace** (recommended):
```
Namespace: {Vendor}\{PluginName}\
Directory: plugins/{vendor}/{plugin-name}/src/
Composer autoload: plugins/{vendor}/{plugin-name}/composer.json
```
- Pro: True isolation, plugin owns its namespace
- Con: Requires Composer autoloader merge or runtime registration

**Recommendation: Option B** with Composer path repositories:

```json
// Root composer.json (generated/managed by plugin installer)
{
  "autoload": {
    "psr-4": {
      "Acme\\Polls\\": "plugins/acme/polls/src/"
    }
  }
}
```

Or at runtime via the existing class_loader mechanism:
```yaml
# Auto-generated during plugin install
services:
    class_loader.plugin.acme.polls:
        class: phpbb\class_loader
        arguments:
            - 'Acme\Polls\'
            - '%core.filesystem_root_path%plugins/acme/polls/src/'
            - '%core.php_ext%'
        calls:
            - [register, []]
            - [set_cache, ['@cache.driver']]
```

### Plugin Directory Structure

```
plugins/
└── {vendor}/
    └── {plugin-name}/
        ├── composer.json          # Metadata, dependencies, autoload
        ├── plugin.yml             # Plugin manifest (capabilities, version)
        ├── src/
        │   ├── Decorator/         # Request/Response decorators
        │   ├── EventSubscriber/   # Event listeners
        │   ├── Migration/         # Schema migrations
        │   └── Service/           # Internal services
        ├── config/
        │   └── services.yml       # DI service definitions
        └── di/
            └── pass/              # Custom compiler passes (optional)
                └── *_pass.php
```

---

## 4. Isolation & Security Model

### Plugin Sandboxing in PHP

**Reality check**: True sandboxing in PHP is extremely limited. Unlike JavaScript sandboxes (V8 isolates) or Docker containers, PHP has no process-level isolation for "plugins" running in the same process.

**Practical isolation strategies**:

| Strategy | Mechanism | Effectiveness |
|----------|-----------|---------------|
| **Namespace isolation** | Each plugin in own namespace, no `use` of internal core classes | Medium — convention-based |
| **Interface contracts** | Plugins interact only via published interfaces | High — enforced at compile time |
| **DI scoping** | Plugin services can only inject whitelisted dependencies | High — enforced by compiler pass |
| **Error boundaries** | try/catch at plugin invocation points | High — prevents plugin crash propagating |
| **Resource limits** | `set_time_limit()`, memory tracking per plugin call | Low — PHP limitation |
| **Static analysis** | CI/code review rules forbidding certain class access | Medium — pre-deployment only |

### What Plugins CAN Access (Public Contract)

```php
// Interfaces plugins may implement:
phpbb\plugin\contract\RequestDecoratorInterface
phpbb\plugin\contract\ResponseDecoratorInterface
phpbb\plugin\contract\EventSubscriberInterface

// Services plugins may inject:
phpbb\db\driver\driver_interface        // Database (own tables only — by convention)
phpbb\cache\service                     // Cache (namespaced keys)
phpbb\config\config                     // Read-only config access
phpbb\event\dispatcher_interface        // Event dispatch
phpbb\language\language                 // Translations
phpbb\log\log_interface                 // Logging
```

### What Plugins MUST NOT Access

- `service_container` directly (no `$container->get(...)` pattern)
- Other plugin services (unless declared dependency)
- Core internal repositories/services not in the public contract
- File system outside `plugins/{vendor}/{name}/` and `store/`
- Raw `$_GET`, `$_POST`, `$_SERVER` (use `request` service)

### Error Boundary Pattern

```php
// In DecoratorPipeline execution
foreach ($decorators as $decorator) {
    try {
        $request = $decorator->decorate($request);
    } catch (\Throwable $e) {
        // Log error, skip decorator, continue pipeline
        $this->logger->error('Plugin decorator failed', [
            'plugin' => $decorator->getPluginName(),
            'error' => $e->getMessage(),
        ]);
        // Optional: disable plugin after N failures
    }
}
```

### Plugin-to-Plugin Dependencies

- Declared in `plugin.yml` manifest:
  ```yaml
  requires:
    acme/base-plugin: "^2.0"
  ```
- Resolved at install time (topological sort, like migration `depends_on()`)
- Circular dependency detection → install failure
- Plugin can't access another plugin's services unless declared dependency exists
- Dependency injection: dependent plugin's public services become injectable

### Security Considerations

1. **CSRF**: Plugin state-changing endpoints MUST use `check_form_key()` (enforced by base controller)
2. **SQL injection**: Plugin migrations use `db_tools` abstraction; plugin queries must use `$db->sql_build_query()` or parameterized queries
3. **XSS**: Plugin templates go through Twig autoescape
4. **Privilege escalation**: Plugin decorator pipeline runs AFTER auth middleware (enforced by pipeline ordering)
5. **File uploads**: Plugin file access restricted to `store/plugins/{vendor}/{name}/`

---

## 5. Performance Considerations

### Lazy Decorator Loading

**Current mechanism**: `proxy_instantiator.php` uses `ProxyManager\Factory\LazyLoadingValueHolderFactory`:
- Services marked `lazy: true` in YAML get proxy objects
- Real object instantiated only on first method call
- Already supported in the container builder

**Application to plugins**:
```yaml
# Plugin services should be lazy by default
services:
    plugin.acme.polls.decorator:
        class: Acme\Polls\Decorator\AddPollOptions
        lazy: true
        tags:
            - { name: phpbb.threads.request_decorator, priority: 100 }
```

**Pipeline optimization**: `DecoratorPipeline` can use `service_collection` which resolves services on iteration (already lazy due to `service_collection.offsetGet()` calling `$container->get($index)`).

### Event Subscriber Performance

**Current pattern**: `RegisterListenersPass` collects all `event.listener` tagged services at compilation.

**With many plugins**: Each plugin adds subscribers → larger dispatch map.

**Mitigations**:
1. **Compiled container**: Event listener map is baked into cached container (already done)
2. **Lazy subscribers**: Subscriber services instantiated only when their event fires (DI container handles this)
3. **Event filtering**: Each subscriber declares which events it listens to → dispatcher only resolves relevant ones
4. **Priority ordering**: Already supported via tag attribute: `{ name: event.listener, priority: -10 }`

**Cost analysis**: With 50 plugins × 3 events each = 150 subscriber registrations. This is a compile-time cost (once per cache rebuild), not a runtime cost.

### JSON Metadata Performance

**Storage**: JSON column in MySQL 8+ / PostgreSQL 12+

| Operation | Strategy |
|-----------|----------|
| Read full metadata | Single column read, `json_decode()` in PHP |
| Read specific key | `JSON_EXTRACT(metadata, '$.vendor.key')` in SQL |
| Write specific key | `JSON_SET(metadata, '$.vendor.key', value)` — partial update |
| Index specific key | Generated column + B-tree index: `ALTER TABLE ADD COLUMN vkey VARCHAR(255) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.vendor.key')))` |
| Full scan on metadata | GIN index (PostgreSQL) or Multi-valued index (MySQL 8.0.17+) |

**Recommendation**:
- Default: No index on metadata (sufficient for decorator augmentation)
- Plugin manifest can declare indexed paths for search-heavy metadata
- Migration creates generated columns + indexes for declared paths

### Plugin Cache Integration

**Current cache service** (`phpbb\cache\service`):
- Key-value cache with `get($var_name)` / `put($var_name, $var, $ttl)`
- Uses driver abstraction (file, memcached, redis)

**Plugin cache namespacing**:
```php
// Plugins get a namespaced cache facade
$cache->get('plugin.acme.polls.topic_42');
// Or a dedicated cache pool per plugin (preferred for isolation)
```

**Cache invalidation**: Plugin responsible for invalidating own keys; core never touches plugin cache entries.

### Boot Performance Summary

| Concern | Impact | Mitigation |
|---------|--------|-----------|
| Many services.yml files loaded | One-time compile cost | Container cached as PHP class |
| Large autoloader | Negligible with classmap | `composer dump-autoload --optimize` |
| Many decorators registered | Lazy instantiation | `service_collection` defers `->get()` |
| Many event subscribers | Compile-time map | Only instantiated when event fires |
| Plugin metadata reads | Per-request JSON decode | Application-level cache for hot paths |

---

## 6. Existing Project Infrastructure Constraints

### Backend Standards (from `.maister/docs/standards/backend/STANDARDS.md`)

| Standard | Impact on Plugin System |
|----------|----------------------|
| `declare(strict_types=1)` mandatory | Plugin classes MUST declare strict types |
| PSR-4 under `phpbb\` namespace | Plugin contracts live in `phpbb\plugin\contract\`; plugin code in own namespace |
| Constructor injection only | Plugin services use constructor DI, no `global` |
| `readonly` for injected deps | Plugin service properties should be `readonly` |
| Parameterized SQL queries | Plugin migrations and queries must use `db_tools` / `sql_build_query()` |
| Service definitions in YAML | Plugin services in `config/services.yml` (per-extension pattern) |

### Container Configuration Path

- Main config: `src/phpbb/common/config/default/container/services.yml`
- Per-environment: `src/phpbb/common/config/{env}/container/`
- Extension services loaded by `extension_base.php` from per-extension `config/` directory
- Tables registered as DI parameters: `tables.{name}` → `%core.table_prefix%{table}`

### Extension/Plugin Directory

- Current extensions: `src/phpbb/ext/{vendor}/{name}/`
- **New plugin location** (recommendation): `plugins/{vendor}/{name}/` (separate from legacy ext)
- Legacy class_loader.ext service scans `src/phpbb/ext/` for `\` namespace prefix
- New plugin system should NOT reuse `src/phpbb/ext/` to avoid confusion with legacy extensions

### Existing Infrastructure That Can Be Reused

| Component | Location | Reusable For |
|-----------|----------|-------------|
| Migration framework | `src/phpbb/forums/db/migration/` | Plugin schema management (100% reusable) |
| Migrator service | `src/phpbb/forums/db/migrator.php` | Plugin migration execution |
| Extension lifecycle | `extension_interface.php` | Plugin enable/disable/purge pattern |
| DI extension_base | `extension/di/extension_base.php` | Plugin service file loading |
| Collection pass | `di/pass/collection_pass.php` | Tagged service collection for decorators |
| Proxy instantiator | `di/proxy_instantiator.php` | Lazy plugin services |
| Service collection | `di/service_collection.php` | Lazy iteration over plugin services |
| Class loader | `class_loader.php` | Plugin namespace registration (alternative to Composer) |
| RegisterListenersPass | Symfony bundle | Event subscriber auto-registration |

### What Needs To Be Built New

| Component | Purpose |
|-----------|---------|
| `PluginManager` | Enable/disable/install/uninstall plugins (replaces ext.manager) |
| `PluginManifestParser` | Read `plugin.yml` manifest, validate structure |
| `PluginServicePrefixPass` | CompilerPass enforcing service ID namespacing |
| `PluginDependencyResolver` | Resolve plugin-to-plugin dependencies (DAG) |
| `PluginErrorBoundary` | Wrap plugin invocations with try/catch + circuit breaker |
| `DecoratorPipeline` | Already designed in service HLDs — needs shared implementation |
| `MetadataAccessor` | Typed access to JSON metadata columns with namespace isolation |
| `PluginCachePool` | Per-plugin cache namespace (or prefix enforcement) |

### Key Technical Decisions Required

1. **Plugin location**: `plugins/{vendor}/{name}/` (recommended) vs extending `src/phpbb/ext/`
2. **Autoloading**: Composer PSR-4 addition (requires `composer dump-autoload`) vs runtime `class_loader` registration
3. **Manifest format**: YAML (`plugin.yml`) vs JSON (`plugin.json`) vs `composer.json` extra section
4. **Uninstall behavior**: DROP tables by default vs preserve (configurable per-plugin)
5. **FK strategy**: No database FKs (loose coupling) vs optional FK declarations
6. **Service isolation enforcement**: Compile-time (compiler pass) vs convention-only
7. **Plugin-to-plugin communication**: Via public events only vs injectable public services
