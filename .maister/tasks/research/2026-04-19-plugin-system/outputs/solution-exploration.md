# Solution Exploration: Plugin System for phpBB Rebuild

**Date**: 2026-04-19
**Research Question**: How to design a unified plugin system for phpBB rebuild (PHP 8.2+, Symfony DI, PDO) that supports request/response decorators, events, JSON metadata on domain records, and plugin-owned tables with schema management.
**Confidence Level**: High (strong research base across 4 frameworks, validated against 8 domain services)

---

## Problem Reframing

The phpBB rebuild already has the primitives — DecoratorPipeline, EventDispatcher, JSON columns, tagged DI, and a migration framework. The missing layer is **orchestration**: how plugins declare themselves, how they transition through lifecycle states, how they safely wire into domain services, and how they store data without corrupting core records. Six key decision areas shape the plugin system's developer experience, runtime safety, and long-term extensibility.

### How Might We Questions

1. **HMW make plugin registration zero-friction** while still validating capabilities and dependencies at install time?
2. **HMW provide a lifecycle model** that supports both simple on/off plugins and complex ones needing multi-step migrations?
3. **HMW let plugins decorate any service** without duplicating interfaces per service or losing type safety?
4. **HMW allow plugins to attach data to core records** without schema migrations, while keeping query performance acceptable?
5. **HMW let plugins own their own tables** with safe creation, migration, and cleanup — including rollback on failure?
6. **HMW isolate plugins from each other and from core** without making the development experience painful?

---

## Decision Area 1: Plugin Manifest & Discovery

### Context

The manifest determines how plugins identify themselves, declare capabilities, and specify dependencies. This affects every downstream concern — dependency resolution, admin UI display, DI integration, and cleanup orchestration. Getting the manifest wrong creates friction for plugin developers (if too complex) or for the platform (if too loose for validation).

### Alternative 1A: Single-File Manifest (`plugin.yml` Only)

All plugin metadata lives in a single `plugin.yml` file. Package identity, autoloading, capabilities, and dependencies are declared in one place. Composer is not involved in plugin discovery.

**Pros:**
- Single file to understand and maintain — lowest cognitive overhead for plugin authors
- Full control over schema design; no compromise with Composer conventions
- Simpler parser (one format, one source of truth)
- No dependency on `composer dump-autoload` for plugin registration

**Cons:**
- Reinvents package identity (name, version, license) already standardized by Composer
- Autoloading must be handled separately (custom classmap or spl_autoload_register)
- No IDE support for custom YAML schema without additional tooling (JSON Schema for `plugin.yml`)
- Disconnected from PHP ecosystem — can't use `composer require vendor/plugin`
- Version constraint syntax must be reinvented or borrowed

**Best when:** The plugin ecosystem is entirely self-contained, plugins are not distributed via Packagist, and you want maximum simplicity for non-Composer-savvy developers.

**Evidence:** WordPress uses file-header comments (simplest possible manifest) — but this is universally criticized for fragility and lack of validation (literature-patterns §7.1).

### Alternative 1B: Dual-File Manifest (`composer.json` + `plugin.yml`)

Package identity and autoloading live in `composer.json` (standard Composer); phpBB-specific capabilities (decorators, events, metadata keys, tables) live in `plugin.yml`. The `composer.json` contains a `"type": "phpbb-plugin"` marker and an `"extra"` section pointing to the plugin class.

**Pros:**
- Leverages the entire Composer ecosystem (autoloading, dependency resolution, Packagist distribution)
- Clean separation: `composer.json` = "what is this package", `plugin.yml` = "what does it do in phpBB"
- IDE support for `composer.json` is universal; `plugin.yml` can have a JSON Schema
- Aligns with both Shopware (`composer.json` + plugin class) and phpBB legacy (`composer.json` as marker)
- Composer's semver constraint system is battle-tested for dependency resolution

**Cons:**
- Two files to maintain — plugin authors must keep version in sync across both
- Version duplication (`composer.json` version vs `plugin.yml` version) unless one is canonical
- Slightly higher onboarding barrier for developers unfamiliar with Composer
- `plugin.yml` is metadata-only (declarative); actual registration still happens via DI tags — potential confusion about what's authoritative

**Best when:** The system is designed for a mature PHP developer audience, plugins may be distributed via Packagist, and you want to maximize ecosystem compatibility.

**Evidence:** Both Shopware 6 and Laravel use `composer.json` with `extra` section for framework integration (literature-patterns §4, §3). Synthesis report §3.1 recommends this approach.

### Alternative 1C: Attribute-Based Manifest (PHP 8 Attributes)

Plugin identity and capabilities are declared via PHP 8 attributes on the plugin class. No YAML/JSON manifest files — the code IS the manifest.

```php
#[Plugin(name: 'acme/polls', version: '1.0.0', minPhpbb: '4.0.0')]
#[DecoratesService(service: 'threads', type: 'request')]
#[ListensTo(TopicCreatedEvent::class)]
#[MetadataKey(table: 'phpbb_topics', key: 'acme.polls.has_poll')]
class AcmePollsPlugin extends AbstractPlugin { ... }
```

**Pros:**
- Code and metadata are co-located — impossible to forget updating the manifest
- Full IDE support (autocomplete, refactoring, go-to-definition on event classes)
- Compile-time validation via PHP's attribute system (type-checked parameters)
- No YAML parsing at all; attributes are read via reflection (cached by PHP opcache)
- Modern PHP feel — leverages PHP 8.2's attribute system idiomatically

**Cons:**
- Requires instantiating/reflecting the plugin class to read metadata — heavier than YAML parsing
- Capabilities are scattered across decorators/subscribers (not just the plugin class) — or the plugin class becomes a massive attribute dump
- Can't read metadata without loading PHP code — makes tooling (admin UI, CLI listing) dependent on autoloading
- No file can be read/shipped independently of the PHP source (e.g., for marketplace metadata)
- Composer integration still needed for autoloading — you'd end up with `composer.json` + attributes

**Best when:** The plugin system is small-scale, developer audience is highly PHP-literate, and external tooling (marketplace, CI validation) is not needed.

**Evidence:** Symfony 6.1+ uses `#[AutoconfigureTag]` for service tagging (literature-patterns §2). However, no major framework uses attributes as the primary manifest format — all keep a separate metadata source.

### Alternative 1D: Convention-Over-Configuration (Directory Structure as Manifest)

Plugin identity is derived from directory structure (`plugins/{vendor}/{name}/`). Capabilities are discovered by scanning for files in conventional locations: `src/Decorator/`, `src/EventSubscriber/`, `migrations/`, `config/services.yml`. No manifest file at all.

**Pros:**
- Zero configuration — just place files in the right directories
- Identical to how core services are already organized (familiar pattern)
- No manifest drift — the actual files ARE the capability declaration
- Encourages consistent directory structure across all plugins

**Cons:**
- Version, dependencies, and human-readable description must live elsewhere (or are missing)
- Filesystem scanning on every install/update — slower than parsing a manifest
- Cannot validate capabilities before installation (must load code to discover what it does)
- No way to declare metadata keys or table names without inspection
- Missing explicit intent — a decorator file existing doesn't mean it should be registered

**Best when:** Plugins are simple (1-2 files), there's no dependency resolution needed, and convention enforcement is done by tooling (scaffold CLI).

**Evidence:** No production framework relies solely on convention for plugin discovery. All four studied frameworks use explicit manifests. Convention is always supplementary.

### Recommendation: **Alternative 1B — Dual-File Manifest**

`composer.json` for package identity + `plugin.yml` for phpBB capabilities. Version is canonical in `composer.json`; `plugin.yml` references it or omits it. This maximizes ecosystem compatibility while keeping phpBB-specific metadata clean and validatable.

**Rationale:** The PHP ecosystem is built on Composer; fighting that creates friction without benefit. The `plugin.yml` adds a schema-validated capability declaration that Composer's `extra` section can't cleanly provide.

### Interaction Notes

- **Lifecycle (DA-2):** Manifest declares the plugin class path for lifecycle method invocation.
- **Decorator Integration (DA-3):** `plugin.yml` `capabilities.decorators` section drives admin UI display and cleanup, but actual registration is via DI tags — the manifest is metadata, not registration.
- **Metadata Storage (DA-4):** `plugin.yml` `capabilities.metadata` declares which tables/keys the plugin uses — enables cleanup on uninstall.
- **Schema Management (DA-5):** `plugin.yml` `capabilities.tables` lists owned tables — enables admin visibility and uninstall cleanup.
- **Isolation (DA-6):** `composer.json` namespace + service prefix convention feeds into compiler pass validation.

---

## Decision Area 2: Plugin Lifecycle Model

### Context

The lifecycle model determines how plugins move from "a folder on disk" to "running in production" and back. It must handle both trivial plugins (add a CSS class to posts) and complex ones (create 5 tables with seed data). Getting this wrong means either: (a) simple plugins have ceremony overhead, or (b) complex plugins can't safely install/update without risking data corruption.

### Alternative 2A: Simple On/Off Toggle

Two states: **enabled** and **disabled**. No separate install/uninstall. Enabling a plugin runs its migrations and rebuilds the container. Disabling it removes services from the container but keeps data. Uninstall is a separate admin action that drops data.

```
[Disabled] ──toggle()──► [Enabled]
[Enabled] ──toggle()──► [Disabled]
[Disabled] ──purge()──► [Removed from disk]
```

**Pros:**
- Simplest possible mental model — two states, one toggle
- Fast iteration during development (toggle on/off quickly)
- No ambiguity between "installed" and "active"
- Minimal state machine code to maintain
- Familiar to WordPress users (activate/deactivate)

**Cons:**
- Conflates "install" (create tables/data) with "enable" (register services) — enabling a complex plugin triggers migrations, which can be slow and fail
- No clean separation between "I want to disable temporarily" and "I want to start fresh"
- Toggle ON must handle first-enable (install migrations) AND re-enable (skip migrations) — conditional logic leaks into the toggle
- No `update()` hook — version changes must be detected otherwise (migration framework handles schema, but what about data transformations?)
- No `keepData` flag — must add ad-hoc logic to the purge action

**Best when:** All plugins are simple (no migrations, no tables), and the system needs the absolute minimum lifecycle complexity.

**Evidence:** WordPress uses essentially this model (activate/deactivate + uninstall). It works but leads to activation hooks doing too much (literature-patterns §1).

### Alternative 2B: Shopware 5-Stage Lifecycle

Five explicit states with transitions: **install**, **activate**, **deactivate**, **update**, **uninstall**. Each stage has a dedicated method on the plugin class with a typed context object.

```
[Not Installed] ──install()──► [Installed/Disabled]
[Installed/Disabled] ──activate()──► [Active]
[Active] ──deactivate()──► [Installed/Disabled]
[Installed/Disabled] ──uninstall(keepData?)──► [Not Installed]
[Active] ──update(oldVer, newVer)──► [Active]
```

**Pros:**
- Clear separation of concerns: install = create infrastructure, activate = turn on services, deactivate = turn off without data loss
- `update()` is first-class — handles data migrations between versions explicitly
- `uninstall(keepData)` gives the admin control over data retention
- Context objects provide version info, migration runner access, and DI container
- Most expressive model — handles every real-world scenario (install fails midway, update from v1→v3 skipping v2, temporary disable for debugging)

**Cons:**
- Five methods to implement (even if most return empty/noop) — boilerplate for simple plugins
- State machine complexity — more transitions to test and more edge cases (what if update() fails mid-way?)
- Plugin developers must understand the state model (when does install run vs activate?)
- Requires a `phpbb_plugins` state table to track transitions

**Best when:** The plugin ecosystem includes complex plugins with tables, migrations, and data transformations. The target audience is experienced PHP developers.

**Evidence:** Shopware 6's lifecycle is the most mature in the PHP ecosystem (literature-patterns §4). The synthesis report recommends this model (§3.2) with phpBB's step-based execution for resumability.

### Alternative 2C: Event-Driven Lifecycle

No explicit state methods on the plugin class. Instead, the PluginManager dispatches lifecycle events (`PluginInstallingEvent`, `PluginActivatedEvent`, etc.) and listeners respond. The plugin itself is just a service bag — its behavior during lifecycle transitions is defined by event subscribers.

```php
// Plugin doesn't implement lifecycle methods
class AcmePollsPlugin extends AbstractPlugin {
    // No install(), activate(), etc.
}

// Separate subscriber handles lifecycle
class PollsLifecycleSubscriber implements EventSubscriberInterface {
    public static function getSubscribedEvents(): array {
        return [
            PluginInstallingEvent::class => 'onInstall',
            PluginActivatedEvent::class => 'onActivate',
        ];
    }
}
```

**Pros:**
- Decoupled — lifecycle behavior is just another service, testable in isolation
- Multiple listeners can react to lifecycle events (e.g., core cleanup listener + plugin-specific listener)
- Cross-cutting concerns (logging all installs, cache clearing on any plugin change) are trivial
- Familiar pattern for developers already using event subscribers
- Plugin class stays thin — logic lives in dedicated subscribers

**Cons:**
- Ordering of lifecycle subscribers is implicit (priority-based) — hard to reason about "what runs when"
- No compile-time guarantee that lifecycle requirements are met (a plugin that needs tables but forgot to register an install listener)
- Debugging lifecycle failures requires tracing through an event chain rather than reading a single method
- The `PluginManager` must still track state transitions — events just add indirection
- Less discoverable: "where does my plugin's install logic live?" requires searching for event references

**Best when:** The system heavily leverages events already and the team values consistency of patterns over explicitness of lifecycle contracts.

**Evidence:** No major PHP framework uses a purely event-driven lifecycle. Symfony's EventDispatcher is used for kernel lifecycle but bundles have explicit `boot()` methods. This is a novel approach without proven precedent.

### Alternative 2D: Database State Machine with Resumable Steps

Each plugin's lifecycle state is stored in a database row with fine-grained sub-states. Long operations (large table creation, data backfilling) are decomposed into steps. Each step is atomic and idempotent. If a step fails, the system records where it stopped and can resume.

```
State column: 'not_installed' | 'installing:step_3' | 'installed' | 'activating' | 'active' | 'deactivating' | 'disabled' | 'uninstalling:step_2' | 'updating:v1.2:step_1'
```

**Pros:**
- Handles long migrations gracefully — no timeout risk even for millions-of-rows data transformations
- Resumable — admin can retry a failed install from exactly where it stopped
- Fine-grained state tracking — admin UI can show "Installing step 3 of 7"
- Atomic steps prevent partial corruption (each step is a transaction)
- Aligns with phpBB's existing step-based migration pattern

**Cons:**
- State machine complexity is high — many possible states and transitions
- Step definition burden falls on plugin developers (must decompose work into resumable steps)
- Simple plugins pay the complexity tax for infrastructure they don't need
- Testing all state transitions (including failure/resume paths) is expensive
- State serialization format must be designed and maintained

**Best when:** The platform hosts complex plugins on large-scale installations where PHP timeout limits are a real constraint (large forums with millions of posts, huge table migrations).

**Evidence:** phpBB's existing migration framework uses step-based execution (synthesis §3.2). This alternative extends that pattern to all lifecycle transitions, not just schema changes.

### Recommendation: **Alternative 2B — Shopware 5-Stage Lifecycle** with step-based execution from 2D

Adopt the 5-stage model for its expressiveness and real-world coverage. For the implementation, lifecycle methods return `false` (done) or a step state value (more work needed) — inheriting phpBB's proven resumability pattern. Simple plugins just return `false` from all methods — zero overhead.

**Rationale:** The 5-stage model provides the right abstraction boundaries, and step-based returns elegantly handle both simple and complex plugins without separate code paths.

### Interaction Notes

- **Manifest (DA-1):** `plugin.yml` declares the plugin class (pointing to lifecycle methods). `composer.json` provides version for `update()` context.
- **Decorator Integration (DA-3):** `activate()` triggers container rebuild that includes plugin decorators. `deactivate()` triggers rebuild that excludes them.
- **Metadata Storage (DA-4):** `uninstall(keepData=false)` triggers `MetadataAccessor::removeAllForPlugin()`.
- **Schema Management (DA-5):** `install()` invokes the migration runner. `update()` runs new migrations. `uninstall()` runs `revert_schema()`.
- **Isolation (DA-6):** Container rebuild during activate/deactivate is where compiler passes enforce isolation rules.

---

## Decision Area 3: Decorator Integration Architecture

### Context

Decorators are the primary mechanism for plugins to modify request processing and response enrichment across 8 domain services. Currently, Threads, Hierarchy, and Users each define identical but separate `RequestDecoratorInterface` and `ResponseDecoratorInterface`. Plugins like Polls need to decorate multiple services — the integration architecture determines how much boilerplate, type safety, and error isolation they get.

### Alternative 3A: Shared Interfaces with Per-Service Pipelines (Recommended in Synthesis)

Extract `RequestDecoratorInterface` and `ResponseDecoratorInterface` into `phpbb\plugin\decorator\`. Each service keeps its own `DecoratorPipeline` instance. Plugins implement the shared interfaces and register via service-specific DI tags (`phpbb.threads.request_decorator`, etc.). An error boundary (try/catch) wraps each decorator invocation.

```php
// Shared interface
namespace phpbb\plugin\decorator;
interface RequestDecoratorInterface {
    public function supports(object $request): bool;
    public function decorateRequest(object $request): object;
    public function getPriority(): int;
}

// Plugin implements once, tags per service
class PollRequestDecorator implements RequestDecoratorInterface { ... }
// Tagged: phpbb.threads.request_decorator, phpbb.hierarchy.request_decorator
```

**Pros:**
- Single interface to learn and implement — minimal boilerplate for multi-service plugins
- Per-service pipelines maintain clear ownership and ordering
- `supports()` method enables one decorator class to handle multiple services (checks request type)
- Error boundary isolates plugin failures — one broken decorator doesn't crash the pipeline
- DI tags provide compile-time wiring — no runtime discovery overhead

**Cons:**
- `object` typing on `supports()` and `decorateRequest()` loses type safety vs per-service interfaces that could type-hint `CreateTopicRequest`
- A decorator tagged for the wrong service will silently return false from `supports()` — no compile-time detection
- Each service must individually implement the error boundary wrapping (or share a base pipeline class)
- The `object` return type means decorators could return wrong DTO types — runtime error only

**Best when:** There are many services with identical decorator patterns and cross-service plugins are common. The team is willing to trade some type safety for reduced duplication.

**Evidence:** Cross-service analysis identified 3 services with identical interfaces (synthesis gap 5.1). All 4 example plugins decorate multiple services (synthesis §3.3).

### Alternative 3B: Generic Typed Decorator with Intersection Types

Use PHP 8.1 intersection types and generics (via PHPDoc) to create a single decorator interface that maintains type safety per service.

```php
namespace phpbb\plugin\decorator;

/**
 * @template TRequest of object
 * @template TResponse of object
 */
interface RequestDecoratorInterface {
    /** @param TRequest $request */
    public function supports(object $request): bool;
    /** @param TRequest $request @return TRequest */
    public function decorateRequest(object $request): object;
    public function getPriority(): int;
}

// Each service pipeline is typed:
/** @var DecoratorPipeline<CreateTopicRequest, TopicResponse> */
private DecoratorPipeline $threadsPipeline;
```

Combined with per-service marker interfaces for compile-time tag validation:

```php
interface ThreadsRequestDecorator extends RequestDecoratorInterface {}
// Compiler pass: phpbb.threads.request_decorator tag → must implement ThreadsRequestDecorator
```

**Pros:**
- Type safety preserved via PHPDoc generics (PHPStan/Psalm can validate)
- Marker interfaces enable compile-time validation (compiler pass checks interface implementation per tag)
- IDE autocomplete works with generic parameters
- Gradual adoption — start with `object`, add generic bounds per service over time
- Per-service marker interfaces document which services are decoratable

**Cons:**
- PHP doesn't have runtime generics — type safety is static-analysis-only
- Marker interfaces add a per-service interface file (mini-boilerplate)
- Plugin developers must understand PHP generics (higher barrier)
- Compiler pass that validates tag → interface mapping adds complexity
- More interfaces to maintain as new services are added

**Best when:** The team uses PHPStan at high levels, values type safety highly, and is comfortable with generic patterns.

**Evidence:** Symfony uses marker interfaces for tagged services (e.g., `EventSubscriberInterface`). PHPDoc generics are standard in modern PHP codebases (PHPStan level 6+).

### Alternative 3C: PSR-15-Inspired Middleware Pipeline

Replace the current decorator pattern with a middleware pipeline where each plugin is a middleware. Requests flow through the pipeline; each middleware can modify the request, call the next handler, and modify the response.

```php
interface PluginMiddlewareInterface {
    public function process(object $request, MiddlewareHandler $handler): object;
}

// Usage:
class PollMiddleware implements PluginMiddlewareInterface {
    public function process(object $request, MiddlewareHandler $handler): object {
        // Modify request (pre-processing)
        $request = $this->enrichWithPollData($request);
        // Call next middleware (eventually hits core service)
        $response = $handler->handle($request);
        // Modify response (post-processing)
        return $this->attachPollResults($response);
    }
}
```

**Pros:**
- Single class handles both request and response decoration — no separate interfaces
- Familiar pattern (PSR-15 is widely known in PHP community)
- Natural control flow — request goes in, response comes out
- Middleware can short-circuit (return without calling `$handler->handle()` — useful for caching, authorization)
- Encourages thinking about the full request/response cycle in one place

**Cons:**
- **Breaks the existing architecture** — all 8 services use separate request/response decorator phases. Adopting middleware requires significant refactoring
- Short-circuit capability is dangerous — a buggy plugin can prevent core logic from executing
- Response type is not known until core service runs — the middleware must handle `object` generically
- Error isolation is harder — a middleware that throws before calling `handler->handle()` prevents all subsequent middlewares AND core logic
- Requires a new `MiddlewareHandler` chain implementation replacing `DecoratorPipeline`

**Best when:** The system is designed from scratch without existing decorator patterns, or you want a complete architectural shift toward PSR-15 style.

**Evidence:** PSR-15 is HTTP-layer middleware. Adapting it to domain service decoration is non-standard and would be novel in the PHP framework ecosystem. No prior art for this exact use case.

### Alternative 3D: Aspect-Oriented Interceptor Pattern

Plugins register interceptors that are woven into service method calls at compile time (via proxy generation). No explicit decorator pipeline — the DI container generates proxy classes that intercept method calls.

```php
#[Intercept(service: 'phpbb.threads', method: 'createTopic', phase: 'before')]
class PollInterceptor {
    public function beforeCreateTopic(CreateTopicRequest $request): CreateTopicRequest {
        return $request->withMetadata(['acme.polls.has_poll' => true]);
    }
}
```

The container generates a proxy:
```php
class ThreadsServiceProxy extends ThreadsService {
    public function createTopic(CreateTopicRequest $request): TopicResponse {
        // Before interceptors run here (generated code)
        $request = $this->interceptors['before']->run($request);
        $response = parent::createTopic($request);
        // After interceptors run here (generated code)
        return $this->interceptors['after']->run($response);
    }
}
```

**Pros:**
- Full type safety — interceptors reference actual method signatures, not generic `object`
- Compile-time generation means zero runtime dispatch overhead
- No separate pipeline classes needed — interception is woven into existing services
- Method-level granularity (intercept `createTopic` but not `getTopic`)
- Familiar to developers from Java/Spring AOP or Symfony security voters

**Cons:**
- Proxy generation complexity — must generate valid PHP classes that extend service classes
- Services must not be `final` (or must use interface + delegation instead of inheritance)
- Debugging through proxies is harder — stack traces show generated code
- Plugin developers must know exact method signatures (tight coupling to service API)
- Any service method change breaks interceptor registration — version fragility
- Compile-time cost increases with number of intercepted methods

**Best when:** You want maximum type safety and performance, the service API is stable, and the team is comfortable with code generation.

**Evidence:** Ocramius ProxyManager (already in vendor) can generate proxies. However, no PHP framework uses AOP for plugin decoration. This is a high-investment, high-reward approach.

### Recommendation: **Alternative 3A — Shared Interfaces with Per-Service Pipelines**

Shared `RequestDecoratorInterface` and `ResponseDecoratorInterface` in `phpbb\plugin\decorator\`, registered via per-service DI tags, with try/catch error boundaries.

**Rationale:** Minimal disruption to existing architecture (services already use this exact pattern with per-service interfaces). Cross-service decorator registration becomes trivial. The `supports()` method provides adequate runtime type discrimination without complex generic machinery.

**Type safety enhancement:** Consider adding PHPDoc `@template` annotations (from 3B) as a progressive improvement — start with `object`, add generic bounds when static analysis tooling is in place.

### Interaction Notes

- **Manifest (DA-1):** `plugin.yml` `capabilities.decorators` lists which services a plugin decorates — purely for admin UI/documentation. DI tags are authoritative.
- **Lifecycle (DA-2):** Container rebuild on activate/deactivate includes/excludes decorator services. Error boundaries mean a broken decorator in an active plugin is logged but doesn't crash.
- **Metadata Storage (DA-4):** Response decorators are the primary consumer — they attach metadata to response DTOs.
- **Isolation (DA-6):** Error boundary is the front-line isolation mechanism. Compiler pass validates tag usage (a plugin can only use tags matching its vendor prefix or standard phpbb.* tags).

---

## Decision Area 4: Metadata Storage Strategy

### Context

Plugins need to store lightweight data on core domain records — per-forum settings ("enable polls in this forum"), per-topic flags ("this topic has a poll"), per-user preferences ("user has opted into badges"). The storage strategy determines: (a) how efficiently this data can be queried, (b) how cleanly it can be removed on plugin uninstall, and (c) whether schema changes are needed per plugin.

### Alternative 4A: JSON Column Per Core Table

Add a `metadata JSON DEFAULT NULL` column to key tables (`phpbb_forums`, `phpbb_topics`, `phpbb_users`). Plugins read/write namespaced keys (`vendor.plugin.key`) via a `MetadataAccessor` service.

```sql
-- Write
UPDATE phpbb_topics SET metadata = JSON_SET(COALESCE(metadata, '{}'), '$.acme.polls.has_poll', true) WHERE topic_id = 42;

-- Read
SELECT JSON_EXTRACT(metadata, '$.acme.polls.has_poll') FROM phpbb_topics WHERE topic_id = 42;

-- Cleanup on uninstall
UPDATE phpbb_topics SET metadata = JSON_REMOVE(metadata, '$.acme.polls') WHERE JSON_CONTAINS_PATH(metadata, 'one', '$.acme.polls');
```

**Pros:**
- Zero schema changes for plugins — no ALTER TABLE per plugin install
- Atomic per-row updates via `JSON_SET` (no read-modify-write race condition)
- All plugin metadata for a record is co-located (single column read gets everything)
- Clean removal on uninstall via `JSON_REMOVE` with prefix matching
- Already proven in the codebase — Users service uses `profile_fields` and `preferences` JSON columns
- MySQL 8.0+/PostgreSQL/SQLite all support JSON functions

**Cons:**
- `JSON_EXTRACT` in WHERE clauses is slower than column index lookups (no B-tree on JSON paths without generated columns)
- JSON values are not type-enforced at the database level — a plugin can write a string where an int is expected
- No foreign key relationships from JSON values to other tables
- Large JSON blobs degrade row read performance (even when you only need one key)
- Hot-path queries (e.g., "list all topics with polls") require full-table `JSON_EXTRACT` without generated column indexes

**Best when:** Most plugin metadata is read alongside the parent record (enriching a topic view) rather than queried independently (find all topics with polls).

**Evidence:** Shopware 6 uses this exact pattern for custom fields (literature-patterns §4). Synthesis §3.5 recommends this with `MetadataAccessor` API.

### Alternative 4B: EAV (Entity-Attribute-Value) Pattern

A single shared table stores all plugin metadata as typed key-value rows linked to any core entity.

```sql
CREATE TABLE phpbb_entity_metadata (
    id INT AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(50) NOT NULL,    -- 'topic', 'forum', 'user'
    entity_id INT NOT NULL,
    plugin_name VARCHAR(100) NOT NULL,   -- 'acme/polls'
    meta_key VARCHAR(100) NOT NULL,      -- 'has_poll'
    meta_value TEXT,
    meta_type ENUM('string', 'int', 'bool', 'json') DEFAULT 'string',
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_plugin (plugin_name),
    UNIQUE idx_entity_key (entity_type, entity_id, plugin_name, meta_key)
);
```

**Pros:**
- Standard indexing — queries like "find all topics where acme/polls.has_poll = 'true'" use B-tree indexes
- No core table modifications — the metadata table is additive
- Uninstall is trivial: `DELETE FROM phpbb_entity_metadata WHERE plugin_name = 'acme/polls'`
- Type column enables basic type enforcement
- Works with any core table (including future tables) without schema changes

**Cons:**
- N+1 query risk — loading metadata for 50 topics requires 50 extra queries (or a JOIN + pivot)
- Sparse data representation bloats row count (1 row per key per entity per plugin)
- JOINs to the metadata table add complexity to every query that needs plugin data
- Text storage for all values (no native int/bool at SQL level, despite type hint)
- WordPress `wp_postmeta` uses this pattern and is universally recognized as a performance bottleneck (literature-patterns §7.2)

**Best when:** There are many entities with few metadata keys each, the system needs maximum flexibility, and read performance is non-critical.

**Evidence:** WordPress `wp_postmeta`, `wp_usermeta` tables use EAV. The pattern is criticised at scale but works for simple installs (literature-patterns §1).

### Alternative 4C: Dedicated Metadata Table Per Core Table (Polymorphic)

Each core table with metadata support gets a companion table. A `phpbb_topic_metadata`, `phpbb_forum_metadata`, `phpbb_user_metadata` table with foreign keys.

```sql
CREATE TABLE phpbb_topic_metadata (
    topic_id INT NOT NULL,
    plugin_name VARCHAR(100) NOT NULL,
    meta_key VARCHAR(100) NOT NULL,
    meta_value JSON,
    PRIMARY KEY (topic_id, plugin_name, meta_key),
    FOREIGN KEY (topic_id) REFERENCES phpbb_topics(topic_id) ON DELETE CASCADE
);
```

**Pros:**
- Proper foreign keys — cascading deletes handle orphan cleanup automatically
- Separate indexes per entity type (no entity_type discrimination in queries)
- `JSON` value column supports typed data (MySQL JSON type validation)
- Uninstall cleanup: `DELETE FROM phpbb_topic_metadata WHERE plugin_name = 'acme/polls'`
- Can be JOINed efficiently with the specific core table

**Cons:**
- More tables to create and maintain (one per core entity with metadata support)
- Still requires JOINs to access metadata (not co-located with entity)
- Adding metadata support to a new core table requires a new companion table + migration
- Multiple rows per entity per plugin — same sparsity issue as EAV (but with better indexing)
- Cascading deletes may cause unexpected data loss if not carefully considered

**Best when:** Referential integrity is critical and the number of metadata-supporting core tables is small and stable.

**Evidence:** No major PHP framework uses this pattern for plugin metadata. It's a hybrid between EAV and JSON columns — more normalized than JSON but more verbose.

### Alternative 4D: Custom Fields Registry (Shopware-Style)

Plugins declare "custom fields" with types, validation rules, and searchability flags. A registry service manages field definitions. Data is stored in JSON columns but with a metadata layer that enables type validation, admin UI generation, and selective indexing via generated columns.

```php
// Plugin registers fields during install()
$fieldRegistry->register('acme.polls', [
    new BoolField('has_poll', table: 'phpbb_topics', indexed: true),
    new DateTimeField('poll_end_time', table: 'phpbb_topics'),
    new IntField('max_votes', table: 'phpbb_topics', default: 1),
]);
```

```sql
-- Auto-generated for indexed fields:
ALTER TABLE phpbb_topics ADD COLUMN _meta_acme_polls_has_poll BOOL
    GENERATED ALWAYS AS (JSON_EXTRACT(metadata, '$.acme.polls.has_poll')) VIRTUAL;
CREATE INDEX idx_topics_has_poll ON phpbb_topics (_meta_acme_polls_has_poll);
```

**Pros:**
- Best of both worlds: JSON storage flexibility + generated column indexing for hot-path queries
- Type validation at the application level (field definitions enforce types before write)
- Admin UI can auto-generate configuration screens from field definitions
- Selective indexing — only fields declared as `indexed: true` get generated columns
- Clean uninstall: drop generated columns + JSON_REMOVE

**Cons:**
- Higher complexity — registry service, field type system, generated column management
- Generated columns require DDL (ALTER TABLE) — not instant for very large tables
- Field registry is a new abstraction layer on top of JSON columns — more code to maintain
- Plugin developers must learn the field type system (not just raw JSON access)
- Over-engineered for simple use cases (a plugin storing one boolean flag)

**Best when:** There's a significant number of plugins storing metadata that needs to be queried independently (e.g., "show all topics with active polls" in a list view).

**Evidence:** Shopware 6 custom fields system (literature-patterns §4). Built for large-scale multi-tenant stores. May be overengineered for phpBB's initial needs.

### Recommendation: **Alternative 4A — JSON Column Per Core Table**

Start with raw JSON columns + `MetadataAccessor` service. This is the simplest approach that leverages already-proven infrastructure (Users service JSON columns).

**Rationale:** The metadata pattern is proven in the codebase, requires minimal new code, and handles the primary use case (enriching entity views) efficiently. Generated columns (from 4D) can be added as a progressive enhancement when specific plugins demonstrate query performance needs — no architecture change required.

### Interaction Notes

- **Manifest (DA-1):** `plugin.yml` `capabilities.metadata` declares table/key pairs — enables validation and cleanup planning.
- **Lifecycle (DA-2):** `uninstall(keepData=false)` calls `MetadataAccessor::removeAllForPlugin()` for each declared table.
- **Decorator Integration (DA-3):** Response decorators are the primary consumers — they read metadata and attach it to response DTOs.
- **Schema Management (DA-5):** Core migration adds JSON columns. Plugins don't need schema changes for metadata. Generated column indexing (4D enhancement) would use plugin migrations.
- **Isolation (DA-6):** Namespaced keys (`vendor.plugin.key`) prevent collisions. Compile-time validation can check key prefixes match plugin namespace.

---

## Decision Area 5: Schema Management Approach

### Context

Complex plugins need their own database tables — polls need `phpbb_poll_options` and `phpbb_poll_votes`, badges need `phpbb_badges` and `phpbb_badge_awards`. The schema management approach determines how these tables are created, versioned, migrated between versions, and cleaned up on uninstall. The approach must handle: dependency ordering (poll tables depend on core tables existing), rollback on failure, and step-based execution for large data migrations.

### Alternative 5A: Reuse Core Migration Framework

Plugins place migration classes in their `src/Migration/` directory. The `phpbb\db\migrator` discovers them, resolves `depends_on()` ordering (DAG), and executes `update_schema()` / `revert_schema()` with step-based execution.

```php
namespace Acme\Polls\Migration;

class Install001 extends \phpbb\db\migration\migration
{
    public static function depends_on(): array
    {
        return ['\phpbb\db\migration\data\v400\v400'];
    }

    public function update_schema(): array { /* table definitions */ }
    public function revert_schema(): array { /* drop tables */ }
    public function update_data(): array { /* seed data */ }
}
```

**Pros:**
- Zero new code — the migration framework already exists and handles DAG ordering, step execution, and schema diffing
- Plugin migrations and core migrations share the same ordering graph — dependency on core tables is naturally expressed
- Resumable step-based execution handles large migrations within PHP time limits
- `revert_schema()` provides clean rollback for uninstall
- Single pattern for both core and plugin developers to learn

**Cons:**
- Migration class names must be globally unique (namespace prevents this in practice, but requires discipline)
- The migrator stores migration state globally — mixing core and plugin migration state can be confusing
- `depends_on()` references core migration class names — tight coupling to core migration identifiers
- No way to mark a migration as "reversible" vs "irreversible" — all have `revert_schema()` but not all data transformations are reversible
- Plugin migrations are discovered from filesystem scan — could be slow with many plugins

**Best when:** The existing migration framework is well-tested, and the team values consistency between core and plugin schema management.

**Evidence:** Synthesis §3.6 explicitly recommends this approach. The migration framework already supports `depends_on()`, `update_schema()`, `revert_schema()`, and step-based execution.

### Alternative 5B: Plugin-Specific Migration Runner

A separate lightweight migration runner for plugins. Plugin migrations use a simpler format and are tracked independently from core migrations. The `PluginMigrator` maintains its own state table (`phpbb_plugin_migrations`).

```php
namespace Acme\Polls\Migration;

class V1_0_0 implements PluginMigrationInterface
{
    public function getVersion(): string { return '1.0.0'; }
    public function up(SchemaManager $schema, Connection $db): void {
        $schema->createTable('poll_options', function (Table $table) {
            $table->addColumn('option_id', 'integer', ['autoincrement' => true]);
            $table->addColumn('topic_id', 'integer');
            $table->setPrimaryKey(['option_id']);
        });
    }
    public function down(SchemaManager $schema, Connection $db): void {
        $schema->dropTable('poll_options');
    }
}
```

**Pros:**
- Clean separation: core migrations and plugin migrations in different state tables — no confusion
- Simpler API — `up()` / `down()` with a `SchemaManager` is more intuitive than phpBB's migration arrays
- Version-string-based ordering is simpler than class-name-based `depends_on()` for plugin-internal migrations
- Can enforce stricter rules (all migrations must be reversible) without affecting core
- Independent lifecycle — plugin migration state is fully owned by the plugin

**Cons:**
- New code to write and maintain — a separate migration runner (~200-300 lines)
- Plugin dependencies on core schema are implicit (assumes core tables exist) — no cross-graph dependency resolution
- Plugin-to-plugin schema dependencies are harder (no shared DAG)
- Two different migration patterns for developers to learn
- Duplicates infrastructure (state tracking, step execution, error handling) that already exists

**Best when:** The core migration framework is too complex or too coupled for plugin use, and a simpler standalone pattern is preferred.

**Evidence:** Laravel packages have their own migrations (separate from app migrations) tracked in the same `migrations` table but published separately (literature-patterns §3).

### Alternative 5C: Declarative Schema (YAML → DDL)

Plugins declare their schema in a YAML/JSON file. A schema compiler converts declarations to DDL statements. Schema changes between versions are automatically diffed.

```yaml
# schema.yml
tables:
  poll_options:
    columns:
      option_id: { type: uint, auto_increment: true }
      topic_id: { type: uint, index: true }
      option_text: { type: varchar, length: 255 }
      vote_count: { type: uint, default: 0 }
    primary_key: option_id
    indexes:
      idx_topic: [topic_id]
```

On update, the system compares old and new `schema.yml`, generates migration DDL:
```sql
-- Auto-generated diff: v1.0 → v1.1
ALTER TABLE phpbb_poll_options ADD COLUMN created_at INT DEFAULT 0;
```

**Pros:**
- Non-PHP developers can understand schema definitions (YAML is universal)
- Auto-diffing eliminates manual migration writing for most schema changes
- Schema validation at install time (check for reserved names, unsupported types, etc.)
- Single source of truth — the YAML IS the current schema, not a chain of migrations
- Versioning is implicit (diff old → new YAML) rather than explicit (numbered migration files)

**Cons:**
- Data migrations (inserting seed data, transforming existing data) cannot be expressed in YAML
- Complex schema operations (rename column, split table) may not diff correctly
- New custom code: YAML parser, schema differ, DDL generator — significant investment
- No step-based execution for large tables (DDL is all-or-nothing)
- Loss of developer control — can't add custom logic during migration
- The diff algorithm must be perfect or data loss can occur

**Best when:** Schema changes are almost always additive (add columns, add tables) and data transformations are rare.

**Evidence:** Shopware explored declarative entities (literature-patterns §4) but still requires migrations for data changes. No PHP framework uses YAML-only schema management.

### Alternative 5D: Doctrine-Style Schema Diff

Plugins define entity classes with Doctrine-style annotations or attributes. A schema diff tool compares the defined entities to the current database state and generates migration SQL.

```php
#[Entity(table: 'phpbb_poll_options')]
class PollOption {
    #[Column(type: 'integer', autoIncrement: true)]
    #[PrimaryKey]
    public int $optionId;

    #[Column(type: 'integer')]
    #[Index]
    public int $topicId;
}
```

```bash
phpbb plugin:diff acme/polls  # generates migration SQL from entity diff
```

**Pros:**
- Entity classes serve as both schema definition and domain model
- Automatic diff generation saves manual migration writing
- PHP attributes provide IDE support and type safety
- Familiar to developers who've used Doctrine ORM
- Can generate both forward and reverse migrations

**Cons:**
- Introduces a mini-ORM layer — phpBB intentionally chose PDO over Doctrine ORM
- Entity annotations are tightly coupled to database structure (leaky abstraction)
- `doctrine/dbal` is in vendor, but schema comparison is a heavy dependency
- Auto-generated migrations for schema diffs are unreliable for renames and complex changes
- Conflicts with phpBB's design decision to use raw PDO + query builder (not entity mapping)

**Best when:** The project already uses Doctrine ORM, and schema-code co-location is valued.

**Evidence:** Doctrine migrations bundle (literature-patterns §2). Powerful but conflicts with phpBB's anti-ORM design decision.

### Recommendation: **Alternative 5A — Reuse Core Migration Framework**

Plugin migrations extend `\phpbb\db\migration\migration` and are discovered in `src/Migration/`. The existing migrator handles DAG ordering, step execution, and rollback.

**Rationale:** The infrastructure already exists, is proven, and handles edge cases (step-based execution, dependency ordering, rollback). Writing a new migration system is pure overhead with no architectural benefit.

### Interaction Notes

- **Manifest (DA-1):** `plugin.yml` `capabilities.tables` lists tables for admin visibility. Migration discovery is by filesystem convention.
- **Lifecycle (DA-2):** `install()` triggers migration execution. `update()` runs pending migrations. `uninstall(keepData=false)` runs `revert_schema()`.
- **Decorator Integration (DA-3):** Plugin tables are accessed by plugin services (repositories) that are injected into decorators. No special integration needed.
- **Metadata Storage (DA-4):** Plugins needing indexed metadata queries can create generated columns via migrations — bridging DA-4 and DA-5.
- **Isolation (DA-6):** Migration class naming convention and `depends_on()` prevent cross-plugin schema conflicts.

---

## Decision Area 6: Service Isolation Model

### Context

The plugin system must prevent: (a) a plugin accidentally or maliciously overriding core services, (b) plugins interfering with each other's services, and (c) plugin failures cascading into core functionality. The isolation model determines the balance between developer freedom and system safety — stricter isolation means fewer plugin bugs but more developer friction.

### Alternative 6A: Convention-Only

Isolation through documentation and naming conventions. Plugin service IDs must follow `{vendor}.{plugin}.*` pattern. No enforcement beyond code review and plugin guidelines. Core services are implicitly trusted not to be overridden.

**Pros:**
- Zero overhead — no compiler passes, no proxies, no enforcement code
- Maximum developer flexibility — plugins can do anything Symfony DI allows
- Fastest development cycles (no rules to work around)
- Simple to understand — "follow the naming convention"
- Good enough for small, trusted plugin ecosystems

**Cons:**
- A single careless plugin can override `phpbb.threads.service` and break the entire forum
- No protection against service ID collisions between plugins
- "Trust but verify" without the "verify" — violations are discovered only at runtime (or never)
- Scales poorly — as the plugin ecosystem grows, convention violations become more likely
- Security risk — a malicious plugin can replace any core service (including auth)

**Best when:** The plugin ecosystem is small, all plugin authors are trusted (e.g., internal team only), and speed of development is paramount.

**Evidence:** No production framework relies solely on convention for isolation. All provide at least some enforcement mechanism (literature-patterns §7.9 anti-pattern).

### Alternative 6B: Compile-Time Enforcement (Compiler Passes)

Symfony compiler passes validate plugin service definitions during container compilation. A `PluginServicePrefixPass` rejects any plugin-registered service ID not matching its `{vendor}.{plugin}.*` prefix. A `PluginTagValidationPass` ensures plugins only use allowed DI tags. Violations are hard errors (container fails to compile).

```php
final class PluginServicePrefixPass implements CompilerPassInterface {
    public function process(ContainerBuilder $container): void {
        foreach ($this->getPluginServiceFiles() as $plugin => $file) {
            $allowedPrefix = str_replace('/', '.', $plugin); // acme/polls → acme.polls
            foreach ($this->getServicesFromFile($file) as $serviceId) {
                if (!str_starts_with($serviceId, $allowedPrefix)) {
                    throw new \LogicException(
                        "Plugin '$plugin' defines service '$serviceId' — must start with '$allowedPrefix'"
                    );
                }
            }
        }
    }
}
```

**Pros:**
- Hard guarantee — a plugin CANNOT override core services (container won't compile)
- Zero runtime overhead — all validation happens at compile time
- Clear error messages at install/activate time — developer knows exactly what's wrong
- Leverages Symfony's existing compiler pass infrastructure (proven pattern)
- Tag validation can prevent plugins from registering as core service providers

**Cons:**
- Compiler passes add compilation time (negligible for reasonable plugin counts)
- Developers hitting validation errors during development may find it frustrating
- Must maintain allowlists for legitimate cross-cutting concerns (what if a plugin needs a `kernel.*` tagged service?)
- Doesn't protect against runtime misbehavior (a decorator that corrupts data passes compilation fine)
- Service prefix enforcement is necessary but not sufficient — plugins can still read from any public service

**Best when:** The plugin ecosystem includes untrusted authors, and service ID integrity is a security requirement.

**Evidence:** Synthesis §3.7 recommends `PluginServicePrefixPass`. Shopware and Symfony both use compiler passes for service validation (literature-patterns §2, §4).

### Alternative 6C: Runtime Sandboxing

Each plugin runs in a restricted execution context. Plugin services are wrapped in proxies that catch exceptions (error boundary), enforce timeouts, and restrict access to core services. Only explicitly exposed "plugin API" services are accessible.

```php
// Plugin service access is proxied
class PluginServiceProxy {
    public function __call(string $method, array $args): mixed {
        try {
            set_time_limit(5); // per-call timeout
            return $this->inner->$method(...$args);
        } catch (\Throwable $e) {
            $this->logger->error("Plugin '{$this->pluginName}' failed: " . $e->getMessage());
            $this->circuitBreaker->recordFailure($this->pluginName);
            return $this->fallback;
        }
    }
}
```

**Pros:**
- Strongest isolation — runtime failures are contained per-plugin
- Circuit breaker can auto-disable plugins that fail repeatedly
- Timeout enforcement prevents plugins from hanging requests
- Plugin API surface is explicitly defined (whitelist approach)
- Closest to "plugin can't break core" guarantee

**Cons:**
- Significant runtime overhead — every plugin service call goes through a proxy
- Proxy magic (`__call`) loses type safety and IDE support
- Debugging through proxies is painful (opaque stack traces)
- Timeout enforcement in PHP is crude (`set_time_limit` affects the whole process)
- Extremely complex to implement correctly (especially with DI wiring)
- Over-engineered for a forum — this is cloud-platform-level isolation

**Best when:** Plugins are from untrusted sources and absolute runtime safety is required (e.g., multi-tenant SaaS where one tenant's plugin shouldn't affect others).

**Evidence:** No PHP framework implements runtime sandboxing for plugins. This is more common in browser extension systems (Chrome extensions) or cloud function platforms.

### Alternative 6D: Interface-Contract-Only Access

Plugins can only interact with core services through defined interfaces (contracts). Core implementation classes are marked `@internal`. The DI container exposes only interface aliases. A compiler pass validates that plugin constructor arguments reference only allowed interfaces.

```php
// Core exposes interface only
interface ThreadsServiceInterface {
    public function createTopic(CreateTopicRequest $request): TopicResponse;
    public function getTopic(int $topicId): TopicResponse;
}

// Implementation is internal
/** @internal */
final class ThreadsService implements ThreadsServiceInterface { ... }

// Plugin depends on interface
class PollService {
    public function __construct(
        private readonly ThreadsServiceInterface $threads, // ✅ allowed
        // private readonly ThreadsService $threads,       // ❌ blocked by compiler pass
    ) {}
}
```

**Pros:**
- Clean API boundary — plugins code against stable contracts, not volatile implementations
- Core can refactor internals without breaking plugins (as long as interface is maintained)
- Compiler pass validates constructor injection — no access to `@internal` services
- Encourages good design (program to an interface, not an implementation)
- Versioned interfaces enable backward compatibility (deprecate v1, introduce v2)

**Cons:**
- Every core service needs a public interface and an `@internal` implementation — boilerplate
- Some operations can't be cleanly expressed via interfaces (e.g., accessing the raw DB connection)
- Plugin developers may need access to things not in the interface — leads to feature requests
- Doesn't prevent runtime misbehavior (a decorator can still corrupt data through the interface)
- `@internal` is a PHPDoc annotation — no runtime enforcement in PHP

**Best when:** The core API is stable, backward compatibility is a priority, and the team is willing to maintain a public interface layer.

**Evidence:** Laravel Contracts package, Shopware store plugin guidelines (literature-patterns §3, §4).

### Recommendation: **Combined 6B + Error Boundaries from DA-3**

Compile-time enforcement via compiler passes (service prefix validation, tag validation) + runtime error boundaries in decorator pipelines (try/catch per decorator). This provides strong prevention (compile-time) with graceful degradation (runtime).

**Rationale:** Compiler passes catch the most dangerous violations (core service override) at install time — zero runtime cost. Error boundaries in decorator pipelines (already recommended in DA-3) handle runtime failures gracefully. This combination covers both "prevent bad things" and "survive when bad things happen" without the overhead of full sandboxing.

**Progressive enhancement:** Interface-contract-only access (6D) can be added incrementally as core service interfaces stabilize — it's a documentation/architecture discipline, not new infrastructure.

### Interaction Notes

- **Manifest (DA-1):** Plugin vendor/name from `composer.json` defines the allowed service prefix.
- **Lifecycle (DA-2):** Compiler passes run during container rebuild (on activate/deactivate). Validation failures block activation with clear errors.
- **Decorator Integration (DA-3):** Error boundaries in `DecoratorPipeline` are the runtime isolation layer for decorator execution.
- **Metadata Storage (DA-4):** `MetadataAccessor` enforces key prefix matching plugin namespace — isolation at the data layer.
- **Schema Management (DA-5):** Table naming convention (`phpbb_{vendor}_{plugin}_*`) can be validated by a compiler pass checking migration class output.

---

## Trade-Off Analysis

### 5-Perspective Comparison Matrix

| Decision Area | Recommended | Tech Feasibility | User Impact | Simplicity | Risk | Scalability |
|:--|:--|:--:|:--:|:--:|:--:|:--:|
| **DA-1: Manifest** | Dual-file (composer + yml) | High | Medium | Medium | Low | High |
| **DA-2: Lifecycle** | 5-stage + step returns | High | High | Medium | Low | High |
| **DA-3: Decorators** | Shared interfaces + pipelines | High | High | High | Low | High |
| **DA-4: Metadata** | JSON columns + accessor | High | Medium | High | Medium | Medium |
| **DA-5: Schema** | Reuse core migrator | High | Medium | High | Low | High |
| **DA-6: Isolation** | Compiler passes + error bounds | High | Medium | Medium | Low | High |

### Key Trade-Offs Accepted

| Decision | What We Gain | What We Give Up |
|----------|-------------|-----------------|
| DA-1: Dual manifest | Ecosystem compatibility, validation | Slight version sync overhead between files |
| DA-2: 5-stage lifecycle | Full coverage of all real-world scenarios | More methods to implement (mitigated: all optional/noop) |
| DA-3: Shared interfaces | Reduced duplication, cross-service simplicity | Compile-time type safety (`object` vs specific DTO types) |
| DA-4: JSON columns | Zero schema changes per plugin, proven pattern | Query performance for large-scale metadata filtering |
| DA-5: Core migrator | Zero new code, proven infrastructure | Plugin migration state mixed with core state |
| DA-6: Compiler passes | Hard safety guarantee at zero runtime cost | Developer friction when hitting validation errors |

### Perspective-Level Analysis

**Technical Feasibility** — All recommendations score High. Every recommended approach either reuses existing infrastructure or requires minimal new code. No decisions require experimental technology.

**User Impact** — Lifecycle model (DA-2) and decorator integration (DA-3) score highest because they directly shape the plugin developer's daily experience. JSON metadata (DA-4) is Medium because the `MetadataAccessor` API must be well-documented for plugin developers to use effectively.

**Simplicity** — Shared decorator interfaces (DA-3), JSON columns (DA-4), and core migrator reuse (DA-5) score High because they minimize new abstractions. Dual manifest (DA-1) and compiler pass isolation (DA-6) are Medium — they add configuration/validation layers.

**Risk** — JSON columns (DA-4) score Medium-risk due to query performance on hot paths (mitigated by generated columns as progressive enhancement). All others are Low because they build on proven infrastructure.

**Scalability** — JSON columns (DA-4) are Medium-scalability — works well up to tens of plugins, may need generated column indexes for high-traffic forums with heavy metadata queries. All others scale linearly.

---

## User Preferences

Based on the architecture context:
- **Greenfield system** — no legacy compatibility needed. This enables clean decisions without compromise.
- **8 domain services already designed** — decorator and event patterns are established. Plugin system must integrate, not reinvent.
- **PHP 8.2+ target** — enables attributes, enums, fibers, readonly properties. Modern PHP idioms expected.
- **Symfony DI already in use** — compiler passes, tagged services, container compilation are available.
- **Existing migration framework** — step-based execution, DAG ordering, schema abstraction already built.

---

## Recommended Approach

### Summary

| Decision Area | Recommendation |
|---------------|---------------|
| DA-1: Manifest | **Dual-file** — `composer.json` (identity) + `plugin.yml` (capabilities) |
| DA-2: Lifecycle | **5-stage Shopware model** with step-based return values for resumability |
| DA-3: Decorators | **Shared interfaces** (`RequestDecoratorInterface`, `ResponseDecoratorInterface`) with per-service pipelines and error boundaries |
| DA-4: Metadata | **JSON columns** on forums/topics/users with `MetadataAccessor` service |
| DA-5: Schema | **Reuse core migration framework** — plugins extend `\phpbb\db\migration\migration` |
| DA-6: Isolation | **Compile-time compiler passes** + runtime error boundaries in decorator pipelines |

### Primary Rationale

The recommended approach maximizes infrastructure reuse (migrations, DI, event dispatcher) while adding the minimum orchestration layer needed: ~11 new classes on top of existing infrastructure, producing a plugin system competitive with Shopware 6's expressiveness at a fraction of the complexity.

### Key Assumptions

1. **MySQL 8.0+ / PostgreSQL 12+** — JSON functions (`JSON_SET`, `JSON_REMOVE`, `JSON_EXTRACT`) work correctly
2. **PHP max_execution_time > 30s** for admin operations — step-based execution handles longer ops
3. **Plugin ecosystem starts small** (<20 plugins) — JSON column approach scales adequately
4. **Plugin developers are PHP/Symfony-literate** — not targeting WordPress-level simplicity
5. **Domain service interfaces remain stable** — decorator `supports()` check relies on DTO class names

### Confidence: **High**

All recommendations are backed by evidence from 4 framework analyses, cross-validated against 8 domain service designs, and build primarily on existing infrastructure.

---

## Why Not Others

| Alternative | Why Rejected |
|---|---|
| **1A: Single YAML manifest** | Disconnects from Composer ecosystem; reinvents package identity |
| **1C: PHP 8 attributes** | Can't read metadata without loading code; no standalone manifest for tooling |
| **1D: Convention-only discovery** | Can't declare dependencies/versions; insufficient for dependency resolution |
| **2A: Simple on/off toggle** | Conflates install and enable; no update hook; insufficient for complex plugins |
| **2C: Event-driven lifecycle** | No precedent; implicit ordering; harder to debug than explicit methods |
| **2D: Pure state machine** | Overengineered as primary approach (but step-based returns are borrowed as implementation detail) |
| **3B: Generic typed decorators** | PHPDoc generics are static-analysis-only; marker interfaces add boilerplate without runtime benefit |
| **3C: PSR-15 middleware** | Breaks existing architecture; short-circuit capability is dangerous for plugin code |
| **3D: AOP interceptors** | High complexity; tight coupling to method signatures; no PHP framework precedent |
| **4B: EAV pattern** | WordPress proved this doesn't scale; N+1 queries; sparse row bloat |
| **4C: Per-table metadata tables** | More tables without clear benefit over JSON columns; still requires JOINs |
| **4D: Custom fields registry** | Over-engineered for initial needs (but generated columns borrowed as progressive enhancement) |
| **5B: Separate migration runner** | Duplicates existing infrastructure; no cross-graph dependency resolution |
| **5C: Declarative YAML schema** | Can't handle data migrations; diff algorithm reliability risk; large investment |
| **5D: Doctrine schema diff** | Conflicts with phpBB's anti-ORM design decision |
| **6A: Convention-only isolation** | No enforcement; a single careless plugin can break the entire system |
| **6C: Runtime sandboxing** | Over-engineered for a forum; significant runtime overhead; no PHP precedent |
| **6D: Interface-only access** | Good principle but deferred — requires interface layer for all services (progressive enhancement) |

---

## Combination Patterns

### How the Recommended Choices Work Together

**Plugin Installation Flow:**

```
Developer creates plugin:
  1. composer.json (identity, autoload)     ← DA-1
  2. plugin.yml (capabilities)              ← DA-1
  3. src/Plugin.php (lifecycle methods)     ← DA-2
  4. src/Decorator/*.php (decorators)       ← DA-3
  5. src/EventSubscriber/*.php              ← (existing)
  6. src/Migration/*.php                    ← DA-5
  7. config/services.yml (DI tags)          ← DA-3, DA-6

Admin installs plugin:
  1. PluginManager reads composer.json + plugin.yml       ← DA-1
  2. Dependency resolution (topological sort)              ← DA-1
  3. Plugin::install() called (step-based)                ← DA-2
  4. Migrations executed (reuse core migrator)             ← DA-5
  5. Plugin state → 'installed' in phpbb_plugins          ← DA-2

Admin activates plugin:
  6. PluginServicePrefixPass validates services.yml       ← DA-6
  7. Container rebuilt with plugin services included       ← DA-3
  8. Decorator tags wired into service pipelines           ← DA-3
  9. Error boundaries wrap each decorator                  ← DA-6
  10. Plugin state → 'active'                             ← DA-2

At runtime (HTTP request):
  11. Controller calls service method
  12. DecoratorPipeline runs request decorators (try/catch each)  ← DA-3, DA-6
  13. Core logic executes (reads/writes metadata via accessor)    ← DA-4
  14. Events dispatched (plugin subscribers react)                ← (existing)
  15. DecoratorPipeline runs response decorators (try/catch each) ← DA-3, DA-6
  16. Response returned

Admin uninstalls plugin:
  17. Plugin::uninstall(keepData?) called                   ← DA-2
  18. MetadataAccessor::removeAllForPlugin() per table      ← DA-4
  19. Migration revert_schema() drops plugin tables          ← DA-5
  20. Container rebuilt without plugin services              ← DA-3, DA-6
  21. Plugin row removed from phpbb_plugins                  ← DA-2
```

**Cross-Cutting Patterns:**

- **Error recovery chain**: Compiler pass catches service violations → error boundary catches runtime decorator failures → circuit breaker (future) auto-disables repeatedly failing plugins
- **Data cleanup chain**: `plugin.yml` declares capabilities → `uninstall()` uses declarations to call `MetadataAccessor::removeAllForPlugin()` + `migrator::revert_schema()` → filesystem cleanup removes plugin directory
- **Discovery chain**: `composer.json` `"type": "phpbb-plugin"` → filesystem scan finds plugins → `plugin.yml` parsed for capabilities → DI compiler passes validate and wire services

---

## Deferred Ideas

| Idea | Category | Why Deferred | Revisit When |
|------|----------|-------------|-------------|
| **Plugin marketplace / distribution** | Ecosystem | Infrastructure concern, not core architecture. Requires trust/review systems. | Community grows beyond 20+ plugins |
| **Async event support** (queue integration) | Performance | No queue infrastructure yet. Sync events sufficient for MVP. | Heavy event subscribers cause visible latency |
| **Plugin admin UI** | DX | CLI-only management is sufficient for alpha. Web UI is a frontend concern. | Post-MVP, when non-technical admins need self-service |
| **Generated column indexes for metadata** (from DA-4D) | Performance | JSON columns work without indexes initially. Generated columns are a per-table DDL operation. | Plugin developers report slow metadata queries on hot paths |
| **Interface-contract-only access** (from DA-6D) | Architecture | Requires interface extraction for all services. Good discipline but significant upfront work. | Service APIs stabilize after 2-3 major releases |
| **Plugin-to-plugin API contracts** | Extensibility | Initial plugins should be self-contained. Cross-plugin APIs add interop complexity. | Two or more plugins need to share data/services |
| **Plugin testing framework** | Quality | Fixtures, mocking helpers, and test base classes. Important but not architectural. | Plugin development guide phase |
| **Plugin configuration UI generation** | DX | Auto-generating admin forms from plugin config schema. Shopware does this. | Admin UI is built and needs plugin config screens |
| **Metadata type validation** | Safety | `MetadataAccessor` stores raw JSON values. Application-level type checking could prevent data corruption. | Multiple plugins store complex metadata structures |
| **Circuit breaker pattern** | Resilience | Auto-disable plugins after N decorator failures. Good for production but premature for MVP. | Operating at scale with untrusted plugins |
