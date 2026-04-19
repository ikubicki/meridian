# Decision Log: Unified Plugin System

---

## ADR-001: Dual-File Plugin Manifest (composer.json + plugin.yml)

### Status
Accepted

### Context
The plugin system needs a manifest format that declares plugin identity (name, version, license, autoload) and phpBB-specific capabilities (decorators, events, metadata keys, tables). The manifest must support plugin discovery, dependency resolution, admin UI display, and cleanup orchestration. The PHP ecosystem is built on Composer for package management; ignoring it creates friction.

### Decision Drivers
- Composer is the standard PHP package manager — plugins should be installable via `composer require`
- phpBB-specific capabilities (decorator tags, metadata keys, event subscriptions) don't fit cleanly into `composer.json` `extra` section
- Plugin discovery needs a reliable marker file (`"type": "phpbb-plugin"`)
- Schema validation of capabilities requires a well-defined format (YAML with JSON Schema)
- Version must have a single canonical source to prevent drift

### Considered Options
1. **Single-file `plugin.yml` only** — all metadata in one custom file. Disconnects from Composer ecosystem, reinvents package identity.
2. **Dual-file `composer.json` + `plugin.yml`** — Composer for package identity, YAML for phpBB capabilities.
3. **PHP 8 attributes on plugin class** — code-as-manifest. Requires loading PHP to read metadata; can't ship metadata independently.
4. **Convention-over-configuration** — directory structure implies capabilities. Can't declare versions or dependencies.

### Decision Outcome
Chosen option: **Dual-file (Option 2)**, because it leverages the full Composer ecosystem (autoloading, PSR-4, Packagist distribution, semver dependency resolution) while providing a clean, schema-validatable YAML format for phpBB-specific capabilities. Version is canonical in `composer.json`; `plugin.yml` omits it.

### Consequences

#### Good
- Standard PHP packaging — IDE support, Packagist distribution, `composer require` workflow
- `plugin.yml` is independently readable by tooling (admin UI, CLI listing) without loading PHP code
- Clean separation of concerns: `composer.json` = "what is this package", `plugin.yml` = "what does it do in phpBB"
- JSON Schema validation over `plugin.yml` catches manifest errors at install time

#### Bad
- Two files to maintain per plugin — slight onboarding overhead
- Capabilities in `plugin.yml` are metadata-only; actual registration is via DI tags — potential confusion about what's authoritative
- Version sync between files must be documented (Composer is canonical)

---

## ADR-002: 5-Stage Shopware Lifecycle with Step-Based Returns

### Status
Accepted

### Context
Plugins range from trivial (add a CSS class to posts) to complex (create 5 tables, seed data, run background indexing). The lifecycle model must support both extremes. Long operations (table creation on large databases, data backfilling) must not exceed PHP's `max_execution_time`. The phpBB codebase already has a proven step-based execution pattern in its migration framework.

### Decision Drivers
- Complex plugins need clear separation between "install infrastructure" and "enable services"
- Admins need a "disable temporarily without data loss" action (deactivate)
- Plugin updates between versions need a dedicated hook for data transformations
- Uninstall must support the admin's choice of keeping or dropping plugin data
- Long migrations must be resumable across HTTP requests

### Considered Options
1. **Simple on/off toggle** — two states: enabled/disabled. No separate install. Conflates "first enable" with "re-enable".
2. **5-stage Shopware lifecycle** — install/activate/deactivate/update/uninstall with typed context objects.
3. **Event-driven lifecycle** — no explicit methods; lifecycle events dispatched, listeners react. No precedent in PHP frameworks.
4. **Database state machine with fine-grained sub-states** — `installing:step_3`, `updating:v1.2:step_1`. Maximum resumability, maximum complexity.

### Decision Outcome
Chosen option: **5-stage Shopware lifecycle (Option 2) with step-based return values from Option 4**, because it provides the right abstraction boundaries for all real-world scenarios while step-based returns `(false = done, string = more work)` elegantly handle resumable execution. Simple plugins return `false` from all methods — zero overhead.

### Consequences

#### Good
- Clear mental model: install = create infrastructure, activate = enable services, deactivate = disable without data loss, uninstall = full cleanup
- `update()` is a first-class hook — handles data migrations between versions explicitly
- `uninstall(UninstallContext{keepData})` gives admin control over data retention
- Context objects provide version info, PDO access, and table prefix
- Step-based returns reuse phpBB's proven pattern for timeout-safe execution

#### Bad
- Five methods to understand (even though all default to `noop`)
- State machine has more transitions to test (what if `install()` fails midway, then admin retries?)
- Requires `phpbb_plugins` table for state tracking
- Plugin developers must understand when `install()` vs `activate()` runs

---

## ADR-003: Shared Decorator Interfaces with Per-Service Pipelines

### Status
Accepted

### Context
Three services (Threads, Hierarchy, Users) already define identical but separate `RequestDecoratorInterface` and `ResponseDecoratorInterface`. All four example plugins (Polls, Badges, Wiki, Attachments) need to decorate multiple services. Per-service duplicate interfaces create boilerplate for plugin authors and maintenance burden for core.

### Decision Drivers
- Cross-service plugins are the common case — Polls decorator needs Threads + Hierarchy
- Decorator interfaces are functionally identical across services (same method signatures)
- Error isolation is critical — one broken plugin decorator must not crash the entire request
- DI tags already provide per-service wiring; a shared interface doesn't affect this

### Considered Options
1. **Shared interfaces** (`RequestDecoratorInterface`/`ResponseDecoratorInterface` in `phpbb\plugin\decorator\`) with per-service pipelines and error boundaries.
2. **Generic typed decorators** — PHPDoc `@template` generics + per-service marker interfaces. Higher type safety but static-analysis-only; adds marker interface boilerplate.
3. **PSR-15-inspired middleware** — single class handles both request + response. Breaks existing architecture; short-circuit capability is dangerous for plugin code.
4. **AOP interceptor pattern** — proxy generation, method-level interception. High complexity; no PHP framework precedent; tight coupling to method signatures.

### Decision Outcome
Chosen option: **Shared interfaces (Option 1)**, because it eliminates duplicate interface definitions, makes cross-service decoration trivial (one interface to implement, multiple DI tags to register), and the `supports()` method provides adequate runtime type discrimination. Per-service `DecoratorPipeline` instances maintain clear ownership and ordering. Error boundaries (try/catch per decorator) ensure one broken decorator doesn't block the pipeline.

### Consequences

#### Good
- Single interface to learn — `RequestDecoratorInterface` + `ResponseDecoratorInterface` cover all services
- Cross-service plugins implement one class, tag it for multiple services
- Error boundary isolates failures — logged and skipped, pipeline continues
- DI tags provide compile-time wiring — no runtime discovery overhead

#### Bad
- `object` parameter typing loses compile-time type safety vs per-service interfaces that could type-hint `CreateTopicRequest`
- A decorator tagged for the wrong service silently returns false from `supports()` — no compile-time detection of mis-tagging
- PHPDoc `@template` annotations (for static analysis) could be added later as progressive enhancement

---

## ADR-004: JSON Metadata Columns with MetadataAccessor

### Status
Accepted

### Context
Plugins need to store lightweight data on core domain records — per-forum settings, per-topic flags, per-user preferences. This data is typically read alongside the parent record (enriching a view) rather than queried independently. The Users service already uses `profile_fields JSON` and `preferences JSON` columns, proving the pattern in this codebase.

### Decision Drivers
- Zero schema changes per plugin install — adding a JSON key doesn't require ALTER TABLE
- Atomic per-row updates via `JSON_SET` prevent read-modify-write race conditions
- Clean removal on uninstall via `JSON_REMOVE` with prefix matching
- Primary use case: enrich entity views (read-alongside), not independent queries (WHERE metadata.key = value)
- Already proven in the codebase (Users service JSON columns)

### Considered Options
1. **JSON columns per core table** — `metadata JSON DEFAULT NULL` on forums/topics/users. `MetadataAccessor` for namespaced read/write.
2. **EAV (Entity-Attribute-Value)** — single `phpbb_entity_metadata` table. Standard indexing but N+1 query risk, sparse row bloat. WordPress's `wp_postmeta` uses this and is a known performance bottleneck.
3. **Per-table metadata companion tables** — `phpbb_topic_metadata`, `phpbb_forum_metadata`. Better indexing than JSON, but more tables and still requires JOINs.
4. **Custom fields registry (Shopware-style)** — typed field definitions + generated column indexes. Over-engineered for initial needs; generated columns can be added as progressive enhancement.

### Decision Outcome
Chosen option: **JSON columns (Option 1)**, because it's the simplest approach that leverages already-proven infrastructure, requires minimal new code (`MetadataAccessor` service), and efficiently handles the primary use case of enriching entity views. Generated column indexes (from Option 4) are a progressive enhancement that can be added when specific plugins demonstrate query performance needs.

### Consequences

#### Good
- Zero schema changes per plugin — no ALTER TABLE on install
- Atomic per-row updates via `JSON_SET` (no read-modify-write)
- Batch read via `getBatch()` prevents N+1
- Clean uninstall via `JSON_REMOVE` with prefix matching
- MySQL 8.0+/PostgreSQL both support JSON functions natively

#### Bad
- `JSON_EXTRACT` in WHERE clauses is slower than B-tree indexed column lookups
- JSON values are not type-enforced at database level
- Large JSON blobs degrade row read performance (even for single key access)
- Hot-path queries (e.g., "list all topics with polls") require workarounds (generated columns) if not read-alongside

---

## ADR-005: Declarative YAML→DDL Schema Management

### Status
Accepted

### Context
Complex plugins need their own database tables. The schema management approach determines how tables are created, versioned, migrated, and cleaned up. The phpBB codebase has an existing migration framework (DAG-ordered, step-based), but the user chose a declarative approach where plugins define desired state in YAML rather than writing imperative migration classes. This is the least proven choice — no PHP framework currently uses YAML-only schema management.

### Decision Drivers
- Single source of truth — the YAML file IS the current schema, not a chain of numbered migrations
- Non-PHP developers can understand YAML schema definitions
- Auto-diffing eliminates manual migration writing for common additive changes (add column, add index)
- On uninstall, the system knows exactly which tables to drop from the YAML declaration
- Schema validation at install time catches errors before any DDL runs
- Data transformations (backfills, renames, seed data) cannot be expressed in YAML — a complementary mechanism is required

### Considered Options
1. **Reuse core migration framework** — plugins extend `\phpbb\db\migration\migration`. Zero new code, proven infrastructure. But requires imperative migration classes, version ordering is by class name + `depends_on()`, and the single source of truth is the migration chain (not the final desired state).
2. **Plugin-specific migration runner** — simpler `up()`/`down()` API, separate state table. Duplicates existing infrastructure.
3. **Declarative YAML→DDL** — plugins declare desired state in YAML; system auto-diffs and generates DDL. Complementary PHP data migrations for transforms.
4. **Doctrine-style schema diff** — entity classes with attributes → auto-diff. Conflicts with phpBB's anti-ORM design decision.

### Decision Outcome
Chosen option: **Declarative YAML→DDL (Option 3)**, because it provides a single source of truth for plugin schemas, auto-diffs eliminate boilerplate for common additive changes, and the YAML format is tool-friendly for validation and admin UI display. A complementary PHP `DataMigrationInterface` mechanism handles data transformations that DDL cannot express.

### Consequences

#### Good
- Single source of truth: `schema.yml` = desired state at current version
- Auto-diffing handles 80% of schema changes (add column, add table, add index) automatically
- Schema validation at install time catches reserved names, invalid types, missing primary keys
- On uninstall, system knows exactly which tables to drop without inspecting migration history
- Non-PHP developers can read and understand schema definitions
- Stored schema snapshots enable accurate diffs between versions

#### Bad
- **New infrastructure cost**: SchemaCompiler, SchemaDiffer, DdlGenerator, SchemaIntrospector — significant investment (~4 new classes, ~500-800 LOC)
- Complex schema operations (column rename, table split) cannot be auto-diffed safely — require PHP data migrations
- Diff algorithm must be correct — incorrect diff could cause data loss (mitigated by destructive operation confirmation)
- No step-based execution for individual DDL statements (DDL is per-operation transactional, not row-by-row)
- No precedent in PHP framework ecosystem — novel approach without battle-tested reference implementations
- Plugin developers must learn two mechanisms: YAML for schema + PHP for data migrations

---

## ADR-006: Interface-Contract-Only Service Isolation

### Status
Accepted

### Context
Plugins must be isolated from core internals so that: (a) core can refactor implementations without breaking plugins, (b) plugins cannot accidentally or maliciously override core services, and (c) a stable API surface exists that plugin developers can rely on. The system has 8 domain services, each of which may be consumed by plugins.

### Decision Drivers
- Core service refactoring should not break plugins (stable API surface)
- Plugins must program to contracts (interfaces), not implementations
- The DI container should enforce this at compile time, not just by convention
- `@internal` annotations are developer guidance but have no runtime enforcement — a compiler pass provides hard enforcement
- All 8 services need public interface definitions for plugin consumption
- Versioned interfaces enable backward compatibility over time

### Considered Options
1. **Convention-only** — naming conventions and documentation. No enforcement. A single careless plugin can override `phpbb.threads.service` and break the forum.
2. **Compile-time enforcement (compiler passes)** — `ServicePrefixPass` rejects non-prefixed service IDs; `ContractValidationPass` rejects dependencies on `@internal` classes. Hard guarantee at zero runtime cost.
3. **Runtime sandboxing** — proxied service access, per-call timeouts, circuit breakers. Over-engineered for a forum; significant runtime overhead; no PHP precedent.
4. **Interface-contract-only access** — every core service exposes a public interface; implementations are `@internal`; compiler pass validates plugins depend only on interfaces.

### Decision Outcome
Chosen option: **Interface-contract-only (Option 4) with compile-time enforcement from Option 2**, because it creates a stable, versioned API surface for plugins while allowing core internals to be refactored freely. The `ContractValidationPass` compiler pass ensures plugins depend only on interface contracts — violations are caught at activation time with clear error messages. The `ServicePrefixPass` prevents plugins from overriding core service IDs.

### Consequences

#### Good
- Stable API surface — plugins code against interfaces that change infrequently
- Core can refactor, rename, restructure implementation classes without breaking plugins
- Compiler pass enforcement catches violations at activation time (not runtime) with clear error messages
- Versioned interfaces enable backward compatibility (`ThreadsServiceV1Interface` → `ThreadsServiceV2Interface`)
- Encourages interface-first design discipline across all core services

#### Bad
- Every core service needs a public interface AND an `@internal` implementation — per-service boilerplate
- Some operations are hard to express via interfaces (e.g., raw DB access for advanced queries) — may lead to feature requests for broader contracts
- `@internal` is a doc annotation only — runtime PHP doesn't enforce it; compiler pass is the enforcement
- Plugin developers may find the indirection frustrating when debugging (type-hint says interface, actual object is implementation)
- Initial setup cost: 9 interface files (one per service) + DI aliases + contract catalog YAML
