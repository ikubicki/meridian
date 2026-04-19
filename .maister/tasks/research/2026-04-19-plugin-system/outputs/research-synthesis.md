# Plugin System — Research Synthesis Report

**Research Type**: Mixed (Technical + Requirements + Literature)
**Date**: 2026-04-19
**Scope**: Unified plugin system for phpBB rebuild (PHP 8.2+, Symfony DI, PDO)

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Architecture Overview](#2-architecture-overview)
3. [Core Components](#3-core-components)
4. [Design Decisions Required](#4-design-decisions-required)
5. [Risk Analysis](#5-risk-analysis)
6. [Recommendations](#6-recommendations)
7. [MVP Scope Definition](#7-mvp-scope-definition)
8. [Open Questions](#8-open-questions)

---

## 1. Executive Summary

The phpBB rebuild already implements the core extension primitives — DecoratorPipeline, Symfony EventDispatcher, JSON columns, tagged DI services, and a migration framework with DAG-based dependency ordering. What is missing is an **orchestration layer**: a `PluginManager` that manages plugin lifecycle (install → activate → deactivate → update → uninstall), a manifest format declaring plugin capabilities, shared decorator interfaces across services, a metadata accessor for JSON columns, and compilation-time isolation enforcement. The recommended design combines Shopware's lifecycle model, Symfony's DI-driven registration, and the existing decorator + event patterns into a system where plugins declare capabilities in `composer.json` + `plugin.yml`, register services via YAML tags (compiled into the container), extend domain services through shared decorator interfaces and event subscribers, store lightweight data in JSON metadata columns, and manage their own tables through the migration framework. An MVP covering lifecycle management, shared decorator interfaces, and event subscriptions can be built with ~8 new classes on top of existing infrastructure.

---

## 2. Architecture Overview

### High-Level Plugin System

```
┌─────────────────────────────────────────────────────────┐
│                     PluginManager                        │
│  (lifecycle, dependency resolution, state management)    │
├─────────────┬───────────────┬───────────────┬───────────┤
│  Manifest   │  Migration    │  DI Container │  Error    │
│  Parser     │  Executor     │  Rebuild      │  Boundary │
│  (plugin.yml│  (reuse       │  (compiler    │  (try/    │
│  + composer)│  phpbb migr.) │  passes)      │  catch)   │
└──────┬──────┴───────┬───────┴───────┬───────┴─────┬─────┘
       │              │               │             │
┌──────▼──────┐ ┌─────▼─────┐ ┌──────▼──────┐ ┌───▼────────────┐
│ Plugin A    │ │ Plugin B  │ │ Plugin C    │ │ Plugin D       │
│ decorators  │ │ events    │ │ metadata    │ │ own tables     │
│ + events    │ │ + tables  │ │ + events    │ │ + decorators   │
└─────────────┘ └───────────┘ └─────────────┘ └────────────────┘
       │              │               │              │
┌──────▼──────────────▼───────────────▼──────────────▼─────────┐
│                   Domain Services Layer                        │
│  Threads │ Hierarchy │ Users │ Search │ Notifications │ ...   │
│  (DecoratorPipeline + EventDispatcher + JSON metadata)        │
└──────────────────────────────────────────────────────────────┘
```

### Extension Flow (Per-Request)

```
HTTP Request
  → Controller
    → Service method called
      → DecoratorPipeline::decorateRequest()     ← Plugin request decorators run here
        → Core service logic (DB operations)
          → EventDispatcher::dispatch(EntityEvent)  ← Plugin event subscribers run here
        → DecoratorPipeline::decorateResponse()    ← Plugin response decorators run here
      → Response DTO returned
    → Controller returns HTTP response
```

### Four Extension Mechanisms

| Mechanism | Purpose | When to Use | Registration |
|-----------|---------|-------------|--------------|
| **Request Decorators** | Modify/enrich request before core logic | Add data, validate, transform input | DI tag: `phpbb.{service}.request_decorator` |
| **Response Decorators** | Modify/enrich response after core logic | Attach extra display data, computed fields | DI tag: `phpbb.{service}.response_decorator` |
| **Event Subscribers** | React to domain events (side effects) | Create related data, notify, index, cache | DI tag: `kernel.event_subscriber` |
| **JSON Metadata** | Store lightweight plugin data on core records | Per-forum settings, per-topic flags | Via MetadataAccessor, namespaced keys |
| **Plugin-Owned Tables** | Full schema control for complex plugin data | Polls, attachments, badges, reactions | Migration framework with `depends_on()` |

---

## 3. Core Components

### 3.1 Plugin Manifest

**Format**: `composer.json` for package identity + `plugin.yml` for phpBB-specific capabilities.

**Evidence**: Shopware and Laravel both use `composer.json` extra section for framework integration. phpBB's legacy system already uses `composer.json` as the marker file. The `plugin.yml` adds phpBB-specific metadata without polluting composer conventions.

**`composer.json`** (package identity):
```json
{
    "name": "acme/polls",
    "type": "phpbb-plugin",
    "description": "Poll system for topics",
    "version": "1.0.0",
    "license": "GPL-2.0-only",
    "require": {
        "php": ">=8.2"
    },
    "extra": {
        "phpbb-plugin-class": "Acme\\Polls\\Plugin"
    },
    "autoload": {
        "psr-4": { "Acme\\Polls\\": "src/" }
    }
}
```

**`plugin.yml`** (phpBB capabilities):
```yaml
name: acme/polls
label: "Topic Polls"
version: "1.0.0"
min_phpbb_version: "4.0.0"

requires:
    # Plugin-to-plugin dependencies (optional)
    # acme/base: "^1.0"

capabilities:
    decorators:
        - { service: threads, type: request }
        - { service: threads, type: response }
    events:
        - phpbb\threads\event\TopicCreatedEvent
        - phpbb\threads\event\TopicDeletedEvent
    metadata:
        - { table: phpbb_topics, keys: ["acme.polls.has_poll", "acme.polls.end_time"] }
    tables:
        - phpbb_poll_options
        - phpbb_poll_votes

uninstall:
    keep_data: false   # DROP tables on purge (user can override at uninstall time)
```

**Rationale**: The `capabilities` section is **declarative metadata**, not the registration itself. Registration happens via DI tags (compiled). The manifest serves for dependency resolution, admin UI display, and cleanup orchestration.

### 3.2 Plugin Lifecycle

**Model**: Shopware 5-stage lifecycle adapted with phpBB's step-based execution.

**State Machine**:
```
[Not Installed] ──install()──► [Installed/Disabled]
[Installed/Disabled] ──activate()──► [Active]
[Active] ──deactivate()──► [Installed/Disabled]
[Installed/Disabled] ──uninstall(keepData?)──► [Not Installed]
[Active] ──update()──► [Active] (version change)
```

**Database table `phpbb_plugins`**:

| Column | Type | Description |
|--------|------|-------------|
| `plugin_name` | `VARCHAR(255) PK` | `vendor/name` identifier |
| `plugin_version` | `VARCHAR(50)` | Installed version |
| `is_active` | `TINYINT(1)` | 1 = active, 0 = disabled |
| `installed_at` | `INT` | Unix timestamp |
| `state` | `JSON DEFAULT NULL` | Intermediate step state for long migrations |

**Plugin base class**:
```php
namespace phpbb\plugin;

abstract class AbstractPlugin
{
    public function install(InstallContext $context): bool { return false; }
    public function activate(ActivateContext $context): bool { return false; }
    public function deactivate(DeactivateContext $context): bool { return false; }
    public function update(UpdateContext $context): bool { return false; }
    public function uninstall(UninstallContext $context): bool { return false; }
    public function isEnableable(): true|array { return true; }
}
```

Each method returns `false` when complete, or truthy value (serialized as step state) when more steps are needed — preserving phpBB's resumable step pattern for long migrations.

**Evidence**: Shopware provides the richest lifecycle model (install/activate/deactivate/update/uninstall with context objects, `keepUserData` flag). Step-based execution pattern ensures long migrations don't exceed PHP `max_execution_time`.

### 3.3 Shared Decorator Interfaces

**Current state**: Threads, Hierarchy, Users each define identical `RequestDecoratorInterface` and `ResponseDecoratorInterface` in their own namespaces.

**Recommendation**: Extract to shared package.

```php
namespace phpbb\plugin\decorator;

interface RequestDecoratorInterface
{
    public function supports(object $request): bool;
    public function decorateRequest(object $request): object;
    public function getPriority(): int;
}

interface ResponseDecoratorInterface
{
    public function supports(object $response): bool;
    public function decorateResponse(object $response, object $request): object;
    public function getPriority(): int;
}
```

Per-service `DecoratorPipeline` instances still exist (each service has its own pipeline), but plugin decorators implement the **shared interfaces**. Services can type-hint the shared interface or provide a service-specific alias.

**Error boundary** (NEW — wraps decorator execution):
```php
foreach ($this->requestDecorators as $decorator) {
    if ($decorator->supports($request)) {
        try {
            $request = $decorator->decorateRequest($request);
        } catch (\Throwable $e) {
            $this->logger->error('Decorator failed', [
                'decorator' => $decorator::class,
                'error' => $e->getMessage(),
            ]);
            // Continue pipeline — one broken decorator doesn't block the request
        }
    }
}
```

**Evidence**: Cross-service analysis identified 3 services with identical but separate interfaces (gap 5.1). All 4 example plugins (Polls, Badges, Wiki, Attachments) use decorators on multiple services — confirming need for shared interfaces.

### 3.4 Event Integration

**Status**: Already built. Symfony EventDispatcher + `kernel.event_subscriber` tag.

**No changes needed** — plugins register event subscribers exactly as internal services do:

```yaml
services:
    acme.polls.subscriber:
        class: Acme\Polls\EventSubscriber\PollLifecycleSubscriber
        tags: ['kernel.event_subscriber']
```

```php
final class PollLifecycleSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            TopicCreatedEvent::class => 'onTopicCreated',
            TopicDeletedEvent::class => 'onTopicDeleted',
        ];
    }
}
```

**Cross-service event topology** (plugins hook into any service's events):
- Threads emits 19 events (richest producer)
- Hierarchy emits 10 events
- Users emits 16 events
- Search emits 6 events (including mutable `Pre/PostSearchEvent`)
- Notifications emits 3 events
- Storage emits 7 events

**Total**: 61+ events available for plugin subscription.

### 3.5 JSON Metadata Accessor

**Pattern**: Namespaced key-value storage in JSON columns on core tables.

**Currently implemented on**: `phpbb_users` (`profile_fields`, `preferences`)

**Recommended additions** (core migrations):
- `phpbb_forums.metadata JSON DEFAULT NULL`
- `phpbb_topics.metadata JSON DEFAULT NULL`
- NOT `phpbb_posts` (respects Threads ADR-001: "raw text only")

**MetadataAccessor service** (NEW):
```php
namespace phpbb\plugin\metadata;

final class MetadataAccessor
{
    /**
     * Read a plugin's metadata key from a record.
     * Key format: "vendor.plugin.keyname"
     */
    public function get(string $table, int $recordId, string $key, mixed $default = null): mixed;

    /**
     * Write a plugin's metadata key to a record.
     * Uses JSON_SET for partial update (no read-modify-write needed).
     */
    public function set(string $table, int $recordId, string $key, mixed $value): void;

    /**
     * Remove all metadata keys for a plugin (used during uninstall).
     * Pattern: JSON_REMOVE for all keys matching "vendor.plugin.*"
     */
    public function removeAllForPlugin(string $table, string $pluginPrefix): void;

    /**
     * Batch read for multiple records (avoids N+1).
     */
    public function getBatch(string $table, array $recordIds, string $key): array;
}
```

**Key naming convention**: `{vendor}.{plugin}.{key}` — e.g., `acme.polls.has_poll`, `acme.polls.end_time`.

**Evidence**: Shopware custom fields use the same JSON column + prefixed keys pattern, with `JSON_REMOVE` cleanup on uninstall. Users service already proves the pattern works in this codebase (`profile_fields`, `preferences`).

### 3.6 Plugin-Owned Tables (Schema Management)

**Mechanism**: Reuse existing migration framework entirely.

```php
namespace Acme\Polls\Migration;

class Install001 extends \phpbb\db\migration\migration
{
    public static function depends_on(): array
    {
        return ['\phpbb\db\migration\data\v400\v400'];
    }

    public function update_schema(): array
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'poll_options' => [
                    'COLUMNS' => [
                        'option_id'  => ['UINT', null, 'auto_increment'],
                        'topic_id'   => ['UINT', 0],
                        'option_text' => ['VCHAR:255', ''],
                        'vote_count' => ['UINT', 0],
                    ],
                    'PRIMARY_KEY' => 'option_id',
                    'KEYS' => [
                        'topic_id' => ['INDEX', 'topic_id'],
                    ],
                ],
            ],
        ];
    }

    public function revert_schema(): array
    {
        return ['drop_tables' => [$this->table_prefix . 'poll_options']];
    }
}
```

**Discovery**: Migrations auto-discovered from `plugins/{vendor}/{name}/migrations/` directory during install. The `migrator` orchestration handles ordering and step-based execution.

**Uninstall behavior**: `revert_schema()` called during uninstall unless `keepUserData` flag is set. Decision is surfaced to the admin at uninstall time (Shopware convention).

**FK strategy**: No database-level foreign keys. Plugin tables reference core record IDs by convention. Referential integrity enforced at application level (event subscribers react to deletion events).

**Evidence**: The migration framework fully supports this workflow (`update_schema`, `revert_schema`, `depends_on`, step-based execution). Infrastructure analysis confirms the pattern works well.

### 3.7 DI Container Integration

**Service registration**: Plugin services defined in `plugins/{vendor}/{name}/config/services.yml`:

```yaml
services:
    acme.polls.request_decorator:
        class: Acme\Polls\Decorator\PollRequestDecorator
        arguments: ['@acme.polls.repository']
        tags:
            - { name: phpbb.threads.request_decorator, priority: 100 }

    acme.polls.response_decorator:
        class: Acme\Polls\Decorator\PollResponseDecorator
        arguments: ['@acme.polls.repository']
        tags:
            - { name: phpbb.threads.response_decorator, priority: 100 }

    acme.polls.subscriber:
        class: Acme\Polls\EventSubscriber\PollLifecycleSubscriber
        arguments: ['@acme.polls.repository']
        tags: ['kernel.event_subscriber']

    acme.polls.repository:
        class: Acme\Polls\Repository\PollRepository
        arguments: ['@dbal.conn']
```

**Service ID enforcement** (NEW): `PluginServicePrefixPass` compiler pass validates all plugin-registered services are prefixed with the plugin vendor/name namespace. Prevents plugins from overriding core services.

```php
namespace phpbb\plugin\di;

final class PluginServicePrefixPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // For each enabled plugin, verify its services.yml only defines
        // services matching the pattern: {vendor}.{plugin}.*
        // Remove/error on any service overriding a core service ID
    }
}
```

**Lazy loading**: Plugin services should default to `lazy: true`. Symfony's ProxyManager (already in the codebase) generates proxy classes instantiated only on first use. Combined with `service_collection` for decorator pipelines, this means zero cost for inactive decorators.

**Container rebuild**: Enabling/disabling a plugin triggers a container cache invalidation and rebuild. This is a one-time operation (admin action), not per-request.

### 3.8 Plugin Directory Structure

```
plugins/
└── acme/
    └── polls/
        ├── composer.json              # Package identity, autoload
        ├── plugin.yml                 # phpBB capabilities manifest
        ├── src/
        │   ├── Plugin.php             # Lifecycle class (extends AbstractPlugin)
        │   ├── Decorator/
        │   │   ├── PollRequestDecorator.php
        │   │   └── PollResponseDecorator.php
        │   ├── EventSubscriber/
        │   │   └── PollLifecycleSubscriber.php
        │   ├── Repository/
        │   │   └── PollRepository.php
        │   └── Migration/
        │       └── Install001.php
        └── config/
            └── services.yml           # DI service definitions
```

### 3.9 PluginManager

The orchestration service tying everything together:

```php
namespace phpbb\plugin;

final class PluginManager
{
    public function __construct(
        private readonly PluginManifestParser $manifestParser,
        private readonly PluginDependencyResolver $dependencyResolver,
        private readonly \phpbb\db\migrator $migrator,
        private readonly ContainerBuilder $containerHelper,
        private readonly \phpbb\db\driver\driver_interface $db,
        private readonly \phpbb\cache\service $cache,
    ) {}

    /** Discover plugins in plugins/ directory */
    public function getAvailable(): array;

    /** Install plugin: parse manifest, resolve deps, run migrations */
    public function install(string $pluginName): bool;

    /** Activate: enable DI services, rebuild container */
    public function activate(string $pluginName): bool;

    /** Deactivate: disable DI services, rebuild container */
    public function deactivate(string $pluginName): bool;

    /** Uninstall: revert migrations, clean metadata, remove state */
    public function uninstall(string $pluginName, bool $keepData = false): bool;

    /** Update: run new migrations, call plugin update() */
    public function update(string $pluginName, string $newVersion): bool;
}
```

**Discovery**: Scans `plugins/` for directories containing `composer.json` with `"type": "phpbb-plugin"`.

**Dependency resolution**: Topological sort of plugin-to-plugin `requires` declarations. Circular dependencies → install failure with clear error.

**Container rebuild**: After any state change (activate/deactivate), the compiled container is invalidated and rebuilt on next request. Only active plugins' `config/services.yml` files are included.

**No legacy considerations**: This system is designed from scratch — no backward compatibility with previous extension systems is required.

---

## 4. Design Decisions Required

| # | Decision | Options | Recommended | Rationale |
|---|----------|---------|-------------|-----------|
| **DD-1** | Plugin directory location | A) `src/phpbb/plugins/` (within src) <br> B) `plugins/` (top-level directory) | **B) `plugins/`** | Clean separation from core code; matches Shopware/Laravel convention (infrastructure-needs) |
| **DD-2** | Manifest format | A) `composer.json` extra only <br> B) `plugin.yml` only <br> C) Both | **C) Both** | `composer.json` for package identity/autoload (ecosystem standard); `plugin.yml` for phpBB capabilities (keeps composer clean). composer.json alone is insufficient for capability declaration (literature-patterns) |
| **DD-3** | Autoloading strategy | A) Composer PSR-4 (requires `dump-autoload`) <br> B) Composer path repos <br> C) Runtime class map | **A) Composer PSR-4** | Standard PHP ecosystem approach; classmap optimization for production. Composer path repos (B) are fragile. Runtime class map (C) adds overhead. (infrastructure-needs) |
| **DD-4** | Shared vs per-service decorator interfaces | A) Keep per-service (status quo) <br> B) Shared interfaces in `phpbb\plugin\decorator\` | **B) Shared** | Interfaces are identical in shape across all 3 services. A unified interface reduces plugin boilerplate and enables cross-service decorator patterns. (cross-service gap 5.1) |
| **DD-5** | JSON metadata on which tables | A) Users only (status quo) <br> B) Users + Forums + Topics <br> C) All main tables including Posts | **B) Users + Forums + Topics** | Forums/Topics have clear plugin use cases (per-forum settings, per-topic flags). Posts excluded per Threads ADR-001. Shopware adds custom_fields to all main entities. (existing-patterns, literature-patterns) |
| **DD-6** | Uninstall data handling | A) Always DROP tables <br> B) Always keep <br> C) User choice (`keepData` flag) | **C) User choice** | Shopware's `keepUserData` is the standard. Admin chooses at uninstall time. Default: DROP. (literature-patterns §4) |
| **DD-7** | Service isolation enforcement | A) Convention only <br> B) Compile-time compiler pass <br> C) Both | **C) Both** | Convention for developer guidance; compiler pass for enforcement. Without compile-time checks, a malicious/careless plugin can override core services. (infrastructure-needs §4) |
| **DD-8** | Search extension model | A) Require decorator pattern (break compatibility) <br> B) Accept mutable events as equivalent <br> C) Support both | **B) Accept mutable events** | Search's `PreSearchEvent`/`PostSearchEvent` is functionally equivalent to request/response decorators. Forcing decorator pattern on Search adds complexity without benefit. Document both patterns as first-class. (cross-service gap 5.2) |

---

## 5. Risk Analysis

### Technical Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **Broken decorator crashes request** — No error boundary in DecoratorPipeline. A throwing decorator prevents remaining decorators from running. | High (one plugin bug = service outage) | High | Add try/catch wrapper in pipeline. Log error, skip decorator, continue. Optional: disable plugin after N failures (circuit breaker). |
| **JSON metadata key collision** — Two plugins use same key name on same table. | Medium | Medium | Enforce `{vendor}.{plugin}.{key}` naming. Validate at install time via manifest inspection. |
| **Plugin overrides core service** — Plugin defines service with ID matching a core service. | Medium | Critical | `PluginServicePrefixPass` compiler pass rejects non-prefixed services from plugin configs. |
| **Container rebuild performance** — Many plugins = slow container compilation on activate/deactivate. | Low | Low | Container rebuild is admin-only operation (not per-request). Symfony compilation is fast for hundreds of services. |
| **Migration failure leaves partial state** — Plugin migration crashes mid-way. | Medium | Medium | Step-based execution (existing) + transaction wrapping per step. Record intermediate state for resumability. |
| **Circular event dispatch** — Plugin event subscriber triggers another event that loops back. | Low | High | Document anti-pattern. Consider max-depth counter on event dispatcher (fail-safe). |

### Migration Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **JSON column on large tables** — ALTER TABLE on tables with many rows (users, topics). | Medium | Medium | Run as online DDL (`ALGORITHM=INPLACE` in MySQL). JSON column with `DEFAULT NULL` is instant in MySQL 8.0+. |

### Ecosystem Risks

| Risk | Likelihood | Impact | Mitigation |
|------|-----------|--------|------------|
| **Developer adoption barrier** — Plugin development requires Symfony DI + YAML + decorator pattern knowledge. | Medium | High | Provide scaffold CLI tool (`phpbb plugin:create acme/my-plugin`). Write comprehensive plugin dev guide with examples. |
| **Plugin quality variance** — No review process; broken plugins degrade platform. | High | Medium | Error boundaries isolate failures. Optional quality hints in admin UI (test coverage, static analysis badge). |
| **Insufficient extension points** — Auth and Cache services have minimal plugin hooks. | Medium | Low | Accept for MVP. Add hooks as real demand emerges (YAGNI). |

---

## 6. Recommendations

Ordered by priority (do first → do later):

### Priority 1 — Foundation (Must Have for Plugin System)

**R1. Create `phpbb\plugin\` namespace with shared contracts**
- `AbstractPlugin` (lifecycle base class)
- `RequestDecoratorInterface`, `ResponseDecoratorInterface` (shared)
- `PluginManifestParser`
- `InstallContext`, `ActivateContext`, `DeactivateContext`, `UpdateContext`, `UninstallContext`
- **Effort**: Low (interface extraction + thin classes)
- **Evidence**: cross-service gap 5.1, infrastructure-needs §2

**R2. Build `PluginManager`**
- Lifecycle orchestration (install/activate/deactivate/update/uninstall)
- Plugin discovery from `plugins/` directory
- State tracking in `phpbb_plugins` table
- Delegates to existing Migrator for schema management
- **Effort**: Medium (new service, ~300 lines)
- **Evidence**: infrastructure-needs §6, legacy-ext lifecycle analysis

**R3. Add error boundaries to `DecoratorPipeline`**
- Wrap each decorator invocation in try/catch
- Log failures with plugin context
- Continue pipeline on decorator failure
- **Effort**: Low (~20 line change per pipeline instance)
- **Evidence**: existing-patterns gap 5, infrastructure-needs §4

### Priority 2 — Extension Mechanisms

**R4. Add JSON metadata columns to `phpbb_forums` and `phpbb_topics`**
- Core migration adds `metadata JSON DEFAULT NULL` to both tables
- Build `MetadataAccessor` service for namespaced read/write
- **Effort**: Low-Medium (migration + 1 service class)
- **Evidence**: existing-patterns (Users already has this), literature-patterns (Shopware custom fields)

**R5. Implement `PluginServicePrefixPass`**
- Compiler pass validating plugin service ID prefixes
- Prevents plugins from overriding core services
- **Effort**: Low (single compiler pass, ~50 lines)
- **Evidence**: infrastructure-needs §2

**R6. Standardize decorator tag naming**
- Convention: `phpbb.{service}.request_decorator`, `phpbb.{service}.response_decorator`
- Audit existing services (Threads uses `phpbb.threads.*`, Hierarchy uses `hierarchy.*` — inconsistency)
- Document tag catalog for plugin developers
- **Effort**: Low (naming convention + docs)
- **Evidence**: existing-patterns tag table

### Priority 3 — Developer Experience

**R7. Create plugin scaffold CLI command**
- `phpbb plugin:create vendor/name` generates directory structure, `composer.json`, `plugin.yml`, empty services.yml
- **Effort**: Low-Medium
- **Evidence**: Shopware `plugin:create`, Laravel `make:*` commands

**R8. Write plugin development guide**
- Step-by-step tutorial: create a Polls plugin
- Reference docs: all available events (61+), decorator tags, metadata tables
- **Effort**: Medium (documentation, not code)

### Priority 4 — Future Enhancements (Post-MVP)

**R9. Async event support** — Queue integration for expensive subscribers (email, search reindex).

**R10. Plugin admin UI** — List installed plugins, enable/disable, configure, view status.

**R11. Plugin-to-plugin dependency resolution** — Topological sort of `requires` declarations.

**R12. Pre-Store event for Storage service** — Mutable, cancellable event before file storage (virus scan, watermark).

---

## 7. MVP Scope Definition

### In MVP

| Component | Deliverable |
|-----------|-------------|
| `AbstractPlugin` | Base class with 5 lifecycle methods |
| `PluginManager` | Install/activate/deactivate/uninstall with step-based execution |
| `PluginManifestParser` | Parse `composer.json` + `plugin.yml` |
| Shared decorator interfaces | `RequestDecoratorInterface`, `ResponseDecoratorInterface` in `phpbb\plugin\decorator\` |
| Error boundaries | try/catch in DecoratorPipeline |
| `PluginServicePrefixPass` | Compiler pass for service ID validation |
| `phpbb_plugins` table | State tracking (migration) |
| JSON metadata on forums/topics | Core migration + `MetadataAccessor` |
| Plugin schema via migrations | Reuse existing migrator (no new code) |
| Plugin directory convention | `plugins/{vendor}/{name}/` with documented structure |

### NOT in MVP

| Component | Reason |
|-----------|--------|
| Async events / queuing | Not needed until performance demands it |
| Plugin admin UI | CLI-only management sufficient for alpha |
| Plugin-to-plugin dependencies | Complexity overhead; single-plugin installs first |
| Plugin marketplace / distribution | Community infrastructure, not core system |
| Custom auth extension points | Auth service is read-only; low demand |
| `PreStoreEvent` for Storage | Can be added when a real plugin needs it |
| Scaffold CLI tool | DX improvement, not architectural |

### Estimated Component Count

| Category | New Classes | Modified Classes | New Migrations |
|----------|:-:|:-:|:-:|
| Plugin lifecycle | 6 (Manager, Parser, AbstractPlugin, 4 Contexts) | 0 | 1 (`phpbb_plugins` table) |
| Shared decorators | 3 (2 interfaces, 1 pipeline base) | 3 (Threads, Hierarchy, Users pipelines) | 0 |
| Metadata | 1 (MetadataAccessor) | 0 | 1 (add JSON columns) |
| DI integration | 1 (PluginServicePrefixPass) | 1 (container_builder) | 0 |
| **Total** | **~11** | **~4** | **2** |

---

## 8. Open Questions

| # | Question | Context | Impact if Unresolved |
|---|----------|---------|---------------------|
| **OQ-1** | Should `phpbb_posts` ever get a `metadata JSON` column? | Threads ADR-001 explicitly chose "raw text only". Polls, reactions, etc. need per-post data → currently forces separate tables. | Plugin developers must maintain more tables. Revisit after MVP feedback. |
| **OQ-2** | How to handle plugin updates that change decorator behavior? | If a plugin update changes its decorator logic, cached responses may be stale. | Define cache invalidation strategy for plugin updates (full cache purge on update is simplest). |
| **OQ-3** | Should Messaging service adopt standard Request/Response decorators? | Currently uses `MessageSendDecorator` — different from the shared interface. | Minor inconsistency for plugins extending messaging. Decide during Messaging HLD finalization. |
| **OQ-4** | What is the Auth service extensibility story? | Auth has 2 events and no decorator pipeline. Custom permission logic can't be plugged in. | Limits auth-extending plugins. Accept for MVP; add `PreAuthorizationCheckEvent` if demand emerges. |
| **OQ-5** | Should plugins be able to register new console commands? | Not covered in any findings. Shopware and Laravel both support this. | Missing DX feature. Low priority — add when CLI tooling matures. |
| **OQ-6** | How to handle JSON metadata performance for hot paths? | `JSON_EXTRACT` in queries is slower than column access. Generated columns + indexes help but add DDL. | Plugin manifest declares "indexed metadata paths" → migration creates generated columns. Defer to when performance data is available. |
| **OQ-7** | Plugin testing infrastructure? | No findings cover how plugins should be tested (fixtures, service mocking). | Plugin quality will suffer without test guidance. Add to plugin development guide (R8). |
