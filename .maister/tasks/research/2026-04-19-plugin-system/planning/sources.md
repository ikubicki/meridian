# Research Sources

## Category 1: existing-patterns

### DecoratorPipeline Pattern
- `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md` (lines 890-1070) — `RequestDecoratorInterface`, `ResponseDecoratorInterface`, `DecoratorPipeline` contracts, DI tag registration
- `.maister/tasks/research/2026-04-18-hierarchy-service/outputs/high-level-design.md` — Request/Response Decorator Pipeline section, `CreateForumRequest` decorator chain example

### EventDispatcher Usage
- `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md` — Domain Events Catalog (TopicCreatedEvent, PostCreatedEvent, etc.), event subscriber registration via `kernel.event_subscriber` tag
- `.maister/tasks/research/2026-04-18-auth-service/outputs/high-level-design.md` — PermissionsClearedEvent, PermissionDeniedEvent, AuthorizationSubscriber at priority 8
- `.maister/tasks/research/2026-04-19-notifications-service/outputs/high-level-design.md` — NotificationCreatedEvent, CacheInvalidationSubscriber, event-driven architecture

### JSON Column Implementations
- `.maister/tasks/research/2026-04-19-users-service/outputs/high-level-design.md` — `profile_fields JSON` column (schema-free, plugins add keys without DDL), `preferences JSON` column (typed DTO, sparse storage), ADR-002 and ADR-003

### DI Tag Registration
- `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md` (Plugin Registration section) — `phpbb.threads.request_decorator`, `phpbb.threads.response_decorator` tags, services.yaml examples

---

## Category 2: cross-service-analysis

### All Service HLD Files
| Service | HLD Path | Key Extension Points |
|---------|----------|---------------------|
| Auth | `.maister/tasks/research/2026-04-18-auth-service/outputs/high-level-design.md` | Events (PermissionsClearedEvent, PermissionDeniedEvent), AuthorizationSubscriber |
| Threads | `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md` | DecoratorPipeline (request+response), domain events (Topic/Post CRUD), event subscribers |
| Hierarchy | `.maister/tasks/research/2026-04-18-hierarchy-service/outputs/high-level-design.md` | DecoratorPipeline, domain events, ForumTypeRegistry (plugin types) |
| Users | `.maister/tasks/research/2026-04-19-users-service/outputs/high-level-design.md` | DecoratorPipeline (registration, profile, search), JSON columns, domain events |
| Search | `.maister/tasks/research/2026-04-19-search-service/outputs/high-level-design.md` | ISP backend interfaces, pluggable backends, IndexingStrategyInterface |
| Notifications | `.maister/tasks/research/2026-04-19-notifications-service/outputs/high-level-design.md` | TypeRegistry (pluggable notification types), MethodManager, domain events |
| Cache | `.maister/tasks/research/2026-04-19-cache-service/outputs/high-level-design.md` | TagAwareCacheInterface, cache pool configuration |
| Storage | `.maister/tasks/research/2026-04-19-storage-service/outputs/high-level-design.md` | Storage adapter interfaces |

### Pattern to Extract per Service
- DI tag namespace (`phpbb.{service}.request_decorator`, etc.)
- Events dispatched (domain events catalog)
- Events consumed (subscriber registrations)
- JSON fields available for plugin metadata
- Pluggable interfaces (registries, backends, strategies)

---

## Category 3: legacy-ext-system

### Extension Manager
- `src/phpbb/forums/extension/manager.php` — Extension lifecycle management (enable, disable, purge), container-aware, DB-backed state, cache integration
- `src/phpbb/forums/extension/extension_interface.php` — Lifecycle contract: `is_enableable()`, `enable_step($old_state)`, `disable_step($old_state)`, `purge_step($old_state)` — multi-step with state carry
- `src/phpbb/forums/extension/base.php` — Default implementation of extension_interface
- `src/phpbb/forums/extension/provider.php` — Extension discovery/loading
- `src/phpbb/forums/extension/metadata_manager.php` — `composer.json`-based metadata (name, version, dependencies, authors)

### DI Integration
- `src/phpbb/forums/extension/di/extension_base.php` — Per-extension DI configuration loading
- `src/phpbb/forums/di/extension/core.php` — Core DI extension
- `src/phpbb/forums/di/extension/tables.php` — Table name injection into DI
- `src/phpbb/forums/di/extension/config.php` — Config injection
- `src/phpbb/forums/di/extension/container_configuration.php` — Container configuration from extensions

### Migration System
- `src/phpbb/ext/phpbb/viglink/migrations/` — Example plugin migrations (viglink_data.php, viglink_cron.php, etc.)
- Legacy migration base class (likely `phpbb\db\migration\migration`) — schema + data migration pattern

### Example Extension (viglink)
- `src/phpbb/ext/phpbb/viglink/ext.php` — Extension entry point implementing extension_interface
- `src/phpbb/ext/phpbb/viglink/event/listener.php` — Event subscriber pattern
- `src/phpbb/ext/phpbb/viglink/acp/` — Admin module pattern

---

## Category 4: literature-patterns

### WordPress Plugin Architecture
- **Concepts**: Action hooks (do_action), filter hooks (apply_filters), priority-based execution, activation/deactivation hooks, uninstall.php
- **Relevance**: Mature hook system with 20+ years of plugin ecosystem; filter pattern maps to DecoratorPipeline; lifecycle hooks map to extension_interface

### Symfony Bundle System
- **Concepts**: Bundle class, Extension class (DI loading), CompilerPass (tag collection), Configuration (validated config), services.yaml per bundle
- **Relevance**: Closest technology match (already using Symfony DI); CompilerPass pattern for collecting tagged services; per-bundle DI namespace

### Laravel Package (Service Provider)
- **Concepts**: ServiceProvider (register + boot), package discovery (composer.json extra), publishable config/migrations, tagged bindings
- **Relevance**: Clean lifecycle (register → boot), auto-discovery via composer, migration publishing pattern for plugin tables

### Shopware App System
- **Concepts**: manifest.xml, permission system, custom fields (JSON metadata on entities), app lifecycle events, webhook-based extension, rule builder
- **Relevance**: Custom fields pattern = JSON metadata; manifest-declared permissions; lifecycle events; isolation via webhooks (extreme isolation model)

### Drupal Module System
- **Concepts**: Hook system, plugin types (annotations/attributes), service tagging, schema API (hook_schema), config entities
- **Relevance**: schema API for plugin tables; typed plugin annotations map to PHP 8.2 attributes

---

## Category 5: infrastructure-needs

### Schema Management
- Legacy migration pattern: `src/phpbb/ext/phpbb/viglink/migrations/` — ordered migrations with dependencies
- PDO DDL execution — `CREATE TABLE`, `ALTER TABLE` via prepared statements
- Migration ordering — dependency graph between migrations (legacy uses `depends_on()`)
- Rollback support — `revert_schema()` / `revert_data()` methods in legacy

### DI Container Integration
- Symfony DI tagged services — how `DecoratorPipeline` collects decorators at compile time
- Per-plugin `services.yaml` loading — `extension_base.php` loads per-extension DI config
- CompilerPass pattern — collecting tagged services, sorting by priority
- Service isolation — preventing plugins from overriding core services

### PSR-4 Autoloading
- Current namespace: `phpbb\` (PSR-4 under `src/phpbb/`)
- Plugin namespace pattern: `phpbb\plugin\{vendor}\{name}\` or separate vendor namespace?
- Composer autoload merging or custom classloader

### Isolation & Error Boundaries
- Namespace isolation — each plugin in its own namespace
- Exception handling at plugin boundaries — plugins can't crash core
- Resource limits — preventing runaway plugins
- Dependency constraints — plugin A depends on plugin B version ^2.0

### Dependency Resolution
- `composer.json` per plugin (legacy pattern from metadata_manager)
- Version constraint checking (semver)
- Load order based on dependency DAG
- Circular dependency detection
