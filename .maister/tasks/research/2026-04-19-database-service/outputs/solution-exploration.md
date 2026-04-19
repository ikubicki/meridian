# Solution Exploration: Database Service for phpBB Rebuild

**Research Question**: How to design a database service (PHP 8.2+, PDO, Symfony DI, no ORM) that provides declarative YAML→DDL schema management, schema versioning, and multi-engine support (MySQL 8+, PostgreSQL 14+, SQLite)?

**Date**: 2026-04-19

---

## Problem Reframing

### Research Question

The database service must serve as a **layered infrastructure foundation** for 8 domain services using repository pattern with raw PDO, plus a plugin system requiring a full YAML→DDL schema management pipeline. The core tension: keep the service thin enough that raw-SQL repositories lose nothing, yet build enough abstraction for multi-engine DDL generation, schema diffing, and a useful optional query builder.

### How Might We Questions

1. **HMW keep the PDO wrapper thin enough that repositories don't feel abstraction tax, yet extensible enough for logging, events, and lazy initialization?**
2. **HMW create a type system that reliably maps between YAML, PHP, and 3 SQL dialects without becoming a maintenance bottleneck?**
3. **HMW design YAML schema format that's readable to non-DBA plugin developers while still expressing engine-specific edge cases?**
4. **HMW detect schema drift accurately without requiring live DB access at plan time?**
5. **HMW offer a query builder that serves 80% of CRUD needs without becoming a leaky abstraction that fights raw SQL?**
6. **HMW generate DDL across 3 engines from abstract operations without combinatorial explosion of engine×operation classes?**

---

## Decision Area 1: Connection Abstraction Depth

### Context

All 8 domain services inject `\PDO` directly today. The connection wrapper is the foundation every other layer depends on. Too thin — no room for lifecycle management, logging, or testability. Too thick — repositories carry abstraction weight for features they don't use. This decision cascades into every service's constructor signature.

### Alternative 1A: Bare PDO (No Wrapper)

**Description**: Services continue injecting `\PDO` directly. DI container provides a factory that creates configured PDO. No interface, no wrapper class.

**Pros**:
- Zero abstraction overhead — developers use native API they know
- No BC breaks — existing service code unchanged
- Smallest possible surface area to maintain
- Full PDO feature set available without pass-through methods

**Cons**:
- No lazy connection (PDO connects at instantiation)
- Cannot add logging/metrics without AOP or subclassing PDO (fragile)
- Unit testing requires real DB or PDO mock (complex)
- No table prefix accessor — each repository manages prefix independently
- Cannot swap to read-replica or connection pool later

### Alternative 1B: Thin Connection Interface (Logging + Lifecycle)

**Description**: A `ConnectionInterface` wrapping PDO with `prepare()`, `exec()`, `lastInsertId()`, `getTablePrefix()`, `getDriver()`. Lazy initialization — PDO created on first use. No type mapping, no auto-quoting, no result abstraction.

**Pros**:
- Lazy connection avoids connecting for cache-only requests
- Table prefix centralized — repositories use `$this->db->getTablePrefix()`
- `getDriver()` enables engine-specific branches where needed
- Easy to mock for unit tests (5 methods to implement)
- Decorator pattern enables logging/profiling without touching Connection

**Cons**:
- Repositories must change type-hints from `\PDO` to `ConnectionInterface`
- `getPdo()` escape hatch exposes underlying PDO (leaky abstraction if overused)
- No higher-level result mapping — every repository does manual `fetch()`
- Additional object allocation (marginal, offset by lazy connect savings)

### Alternative 1C: Full DBAL-Style Connection (Type Mapping + Auto-Quoting)

**Description**: Connection provides `insert(table, data)`, `update(table, data, where)`, `fetchAssoc(sql, params)`, type conversion on read/write, automatic identifier quoting, and result abstraction wrapping `PDOStatement`.

**Pros**:
- Repositories use higher-level methods — less boilerplate
- Type conversion eliminates manual `(int)` casting in hydration
- Identifier quoting prevents reserved-word collisions across engines
- Closer to Doctrine DBAL — familiar to many PHP developers
- Could reduce raw SQL mistakes in plugin code

**Cons**:
- **Contradicts the project's explicit raw-SQL philosophy** — 8 services write their own SQL
- High surface area — more methods to test, document, maintain
- Type conversion adds hidden behavior — debugging becomes harder
- Overlaps with query builder layer — unclear boundary
- Performance: type introspection on every read adds overhead for manual hydration pattern

### Alternative 1D: Multiplexed Connection Manager

**Description**: A `ConnectionManager` that holds multiple named connections (default, read-replica, migration). Services request connections by name or role. Each connection is independently lazy.

**Pros**:
- Read/write splitting achievable without service changes
- Schema operations can use dedicated connection (isolation)
- CLI workers can hold long-lived connections separately
- Foundation for future sharding or tenant-per-DB

**Cons**:
- Massive over-engineering for phpBB's single-server reality
- Repository constructors become more complex (which connection?)
- Transaction spanning multiple connections is error-prone
- DI wiring complexity increases significantly
- Research confirms: "single shared connection per request" is the established pattern

### Recommended Approach: **1B — Thin Connection Interface**

Matches the research consensus: repositories own their SQL, the connection provides infrastructure only. Lazy init saves the unnecessary connection cost for cache-hit requests. `getTablePrefix()` and `getDriver()` are the two most common cross-cutting needs identified across all 8 services. Testability via interface is a major win over raw PDO.

### Interaction Notes

- **Affects Query Builder Style**: Query builder receives `ConnectionInterface`, uses `prepare()` internally.
- **Affects DDL Generator**: DdlGenerator uses `getDriver()` for engine detection via factory.
- **Affects Type System**: Type system operates on YAML/schema models, NOT on Connection layer. Connection does NOT do type conversion.

---

## Decision Area 2: Type System Architecture

### Context

The type system is the **linchpin** of the entire schema pipeline (synthesis Insight #6). It maps 20+ abstract YAML types to engine-specific SQL types, feeds into DDL generation, schema introspection (reverse mapping), and diff comparison. Getting it wrong breaks DDL generation, causes introspection mismatches (false diffs), and silently corrupts data migrations. The type system is consumed by 4 components: SchemaCompiler, DdlGenerator, SchemaIntrospector, and SchemaDiffer.

### Alternative 2A: Simple Mapping Array

**Description**: A single `TypeRegistry` class with two associative arrays: `$abstractToSql` maps `['uint']['mysql'] => 'INT UNSIGNED'` and `$sqlToAbstract` maps `['INT UNSIGNED']['mysql'] => 'uint'`. Column declaration assembled by string concatenation with length/precision/nullable substitution.

**Pros**:
- Extremely simple to implement and understand
- All mappings visible in one file — easy audit
- No class hierarchy overhead — just array lookups
- Fast: O(1) hash table access
- Easy to extend — add a row to the array

**Cons**:
- Column declaration logic (length, precision, unsigned, auto_increment order) embedded in string templates — fragile
- Reverse mapping is ambiguous: `INT` in MySQL could map to `int` or `timestamp` (both are INT)
- No validation of type+modifier combinations (e.g., `varchar` without length)
- Adding engine-specific behaviors (e.g., PostgreSQL CHECK for unsigned) requires special-casing in the array consumer
- Doesn't scale well to complex column declarations (generated columns, partial indexes)

### Alternative 2B: Enum-Based Type Objects

**Description**: A PHP 8.1 `AbstractType` backed enum where each case (`Uint`, `Varchar`, `Json`, etc.) carries methods: `toSql(string $driver): string`, `fromSql(string $sqlType, string $driver): ?self`, `validate(ColumnDefinition $col): void`. The enum IS the type registry.

**Pros**:
- Type-safe: compiler catches invalid type names at the enum level
- Methods on enum cases allow per-type behavior (e.g., `Varchar->validate()` checks length exists)
- Single enum file — all types discoverable, IDE autocomplete
- Pattern match in DdlGenerator: `match($col->type) { AbstractType::Uint => ... }`
- Inherently closed set — impossible to add invalid types at runtime

**Cons**:
- PHP enums can't have per-case static properties (workaround: methods with `match($this)`, verbose)
- Adding a new type requires modifying the enum — not extensible by plugins
- Complex type declarations (decimal with precision+scale) need separate format logic outside the enum
- Reverse mapping from SQL type strings is messy with enums (requires static method scanning all cases)
- If plugins need custom types (spatial, custom), the closed enum blocks them

### Alternative 2C: Doctrine-Inspired Type Classes

**Description**: Each abstract type is a class implementing `TypeInterface` with methods: `getName(): string`, `getSqlDeclaration(ColumnDefinition $col, string $driver): string`, `convertToPHP(mixed $value): mixed`, `convertToDatabase(mixed $value, string $driver): mixed`. A `TypeRegistry` maps names to instances. Reverse mapping via `SchemaIntrospector` calls each type to check if it owns a SQL type.

**Pros**:
- Maximum extensibility — plugins register custom Type classes
- Per-type conversion logic (JSON encode/decode, boolean normalization)
- Each type owns its full SQL declaration including modifiers
- Well-proven pattern (Doctrine DBAL has used it for 10+ years)
- Open/Closed principle: new types without modifying existing code

**Cons**:
- **Over-engineered for this project** — services do manual hydration, not type conversion
- 20+ type classes plus registry — high file count for simple mappings
- `convertToPHP()` / `convertToDatabase()` imply ORM-like behavior this project explicitly rejects
- Reverse mapping is O(n) — iterate all types to find which claims a SQL type
- Type classes need driver as parameter everywhere — not truly polymorphic

### Alternative 2D: Value Objects with Engine Visitors

**Description**: Each YAML type parsed into a `ColumnType` value object holding the abstract name + modifiers (length, precision, scale, unsigned). DDL generation uses a Visitor pattern: `MySQLTypeVisitor`, `PostgreSQLTypeVisitor` each implement `visit(ColumnType): string`. Reverse mapping via per-engine `TypeResolver` that matches SQL patterns to `ColumnType`.

**Pros**:
- Clean separation: type data (value object) vs type rendering (visitor per engine)
- Adding a new engine means writing one new visitor — no changes to existing code
- Value objects are immutable, testable, serializable
- Visitor can handle complex types (UNSIGNED + CHECK generation) with full engine context
- Reverse resolvers can use engine-specific regex patterns for accuracy

**Cons**:
- Visitor pattern adds indirection — more classes than arrays/enums
- For 20 types × 3 engines = each visitor has 20+ methods (or a switch/match)
- Double dispatch in PHP requires interface + accept() on value object — boilerplate
- Plugin custom types need visitor extension mechanism (visitor per plugin per engine)
- Unfamiliar pattern to some PHP developers compared to simple registry

### Recommended Approach: **2A — Simple Mapping Array, enhanced with validation methods**

The project explicitly rejects ORM-like type conversion (synthesis Insight #1, #5). Services do manual hydration. The type system needs to map types for DDL generation and introspection comparison — that's it. A `TypeRegistry` class with mapping arrays, a `getColumnDeclarationSQL(ColumnDefinition, driver)` method that handles modifier ordering, and a `validateColumn(ColumnDefinition)` method covers all needs. If plugin custom types are needed later, the registry can accept additional mappings via DI config. The enum (2B) is too closed; Doctrine classes (2C) imply conversion we don't want; visitors (2D) are overkill for a closed set of 20 types.

### Interaction Notes

- **Affects YAML Schema Format**: YAML type names must be the abstract type keys in the registry.
- **Affects DDL Generator**: DdlGenerator delegates column SQL rendering to `TypeRegistry::getColumnDeclarationSQL()` rather than owning type knowledge.
- **Affects Schema Diff**: Differ normalizes columns to abstract types via the registry before comparison — prevents false diffs from engine-specific type aliases.

---

## Decision Area 3: YAML Schema Format Design

### Context

The YAML schema is the **developer-facing API** for table declaration. Plugin developers will write it. Core team will maintain 30+ core tables in it. Readability, error-detection, and sensible defaults matter more than expressiveness. The format must support 3 engines without requiring engine-specific YAML in the common case, yet allow overrides when needed.

### Alternative 3A: Minimal Format (Types + Constraints Only)

**Description**: YAML declares columns (type, nullable, default), primary key, indexes, and unique keys. No foreign key section. No engine-specific options. No column comments. Convention-driven: all columns NOT NULL by default, no default unless specified, table engine/charset set globally.

```yaml
tables:
    topics:
        columns:
            topic_id: { type: uint, auto_increment: true }
            forum_id: { type: uint }
            title: { type: varchar, length: 255 }
        primary_key: [topic_id]
        indexes:
            idx_forum: [forum_id]
```

**Pros**:
- Extremely easy to read and write — low barrier for plugin developers
- Fewest possible incorrect configurations (less surface, fewer bugs)
- Fastest to implement — compiler has minimal parsing logic
- Forces consistent conventions across all tables

**Cons**:
- Cannot express engine-specific needs (MySQL fulltext indexes, PG partial indexes)
- No FK declarations — referential integrity is application-only
- No column comments — schema is self-documenting only via column names
- Inflexible for edge cases — escape hatch is... write raw SQL migration?
- Core team may need features that plugins don't (fulltext, generated columns)

### Alternative 3B: Rich Format (Full DDL Control Per Engine)

**Description**: YAML has engine-specific override sections. Columns can have `mysql:`, `pgsql:`, `sqlite:` sub-keys for per-engine type overrides, default expressions, and constraints. Table-level engine options (storage engine, charset, tablespace). Full FK section with cascade rules.

```yaml
tables:
    topics:
        columns:
            topic_id:
                type: uint
                auto_increment: true
            metadata:
                type: json
                nullable: true
                pgsql: { type: "jsonb" }  # override for PG
            search_vector:
                pgsql: { type: "tsvector" }  # PG-only column
        primary_key: [topic_id]
        options:
            mysql: { engine: InnoDB, charset: utf8mb4 }
            pgsql: { tablespace: fast_ssd }
```

**Pros**:
- Full control — can express any engine-specific DDL feature
- Engine-specific columns allow leveraging native features (tsvector, spatial)
- FK section enables referential integrity enforcement at DB level
- Core team has flexibility for advanced optimization

**Cons**:
- **Complex for plugin developers** — they must understand engine differences
- YAML files become verbose and harder to review
- SchemaCompiler must handle conditional columns, type overrides, engine selection
- Testing burden: every schema must be tested against all 3 engines
- Encourages writing engine-specific schemas — undermines portability goal

### Alternative 3C: Doctrine-Inspired (Abstract + Engine Overrides)

**Description**: YAML uses abstract types exclusively in the main column definition. A separate `overrides:` section per table (optional) allows engine-specific adjustments. Columns are always cross-engine; overrides fine-tune DDL generation. FK section is present but clearly separated.

```yaml
tables:
    topics:
        columns:
            topic_id: { type: uint, auto_increment: true }
            forum_id: { type: uint, default: 0 }
            title: { type: varchar, length: 255 }
            metadata: { type: json, nullable: true }
        primary_key: [topic_id]
        indexes:
            idx_forum: [forum_id]
        foreign_keys:
            fk_forum:
                columns: [forum_id]
                references: { table: forums, columns: [forum_id] }
                on_delete: CASCADE
        overrides:
            mysql: { engine: InnoDB, charset: utf8mb4 }
```

**Pros**:
- Main column section stays clean and abstract — portable by default
- Overrides section is opt-in — plugin devs can ignore it entirely
- FK section available but clearly separated (optional per research decision #8)
- Mirrors Doctrine DBAL's approach — familiar to PHP ecosystem
- SchemaCompiler can validate abstract section independently of overrides

**Cons**:
- Two places to look for column configuration (main + overrides)
- Overrides section scope is ambiguous — can it override column types? Add columns? Only table options?
- More complex than minimal format (3A) for simple cases
- Plugin devs may overuse overrides, degrading portability

### Alternative 3D: Convention-Heavy (Minimize YAML via Defaults)

**Description**: YAML is ultra-concise using aggressive convention-over-configuration. Column name patterns infer types: `*_id` → `uint`, `*_at` → `timestamp`, `is_*` → `bool`, `*_count` → `uint DEFAULT 0`. Only non-conventional columns need explicit type declarations. Primary key inferred from first `*_id` column. Indexes can be declared with shorthand.

```yaml
tables:
    topics:
        columns:
            topic_id: { auto_increment: true }  # type inferred: uint
            forum_id: ~                          # type inferred: uint
            title: { type: varchar, length: 255 } # explicit: no convention
            is_locked: ~                          # type inferred: bool
            post_count: ~                         # type inferred: uint, default: 0
            created_at: ~                         # type inferred: timestamp
        indexes:
            idx_forum: [forum_id]
```

**Pros**:
- Most concise format — 30-50% less YAML than explicit formats
- Enforces naming conventions (which the project already follows)
- Reduces errors from mismatched type+name (e.g., `*_count` is always uint)
- Fast to write for developers who know the conventions
- Convention documentation doubles as naming standards

**Cons**:
- **Convention magic is hard to debug** — "why is this column an INT?"
- New developers must learn conventions before understanding existing schemas
- Conventions may not cover all cases — explicit fallback still needed
- Convention conflicts: `plugin_id` looks like uint but plugin might want varchar UUID
- SchemaCompiler must implement convention resolution + explicit override merging
- Convention errors are silent — misspelled suffix infers wrong type with no warning

### Recommended Approach: **3C — Doctrine-Inspired (Abstract + Engine Overrides)**

The research report Section 4 already defines this format almost exactly. It keeps the main column section clean and abstract (portable), while the `overrides:` section is opt-in for engine-specific table options. FK section present but optional (matching research decision #8). Plugin developers write only abstract types; the SchemaCompiler + TypeRegistry handle engine translation. This is the sweet spot between readability (vs 3B) and expressiveness (vs 3A), without magic (vs 3D).

### Interaction Notes

- **Affects Type System**: YAML `type:` values must be valid abstract types in the TypeRegistry.
- **Affects Schema Diff**: Differ compares SchemaDefinition models, not raw YAML — format doesn't affect diff algorithm.
- **Affects DDL Generator**: DdlGenerator receives abstract SchemaDefinition; engine-specific overrides already resolved by SchemaCompiler.

---

## Decision Area 4: Schema Diff Strategy

### Context

Schema diffing is how the system detects what DDL to execute during install, upgrade, and plugin lifecycle. The strategy must handle: fresh install (no current state), core upgrade (old version → new version), plugin update (snapshot → new YAML), and drift detection (YAML vs live DB). The choice directly impacts whether diff results are deterministic (reproducible offline) or require live DB access.

### Alternative 4A: Live-Diff-Only (Always Compare YAML vs DB)

**Description**: Every diff operation introspects the current database state via `SchemaIntrospector`, then compares against the declared YAML. No snapshots stored. The live DB is always the source of truth for "current state."

**Pros**:
- Always accurate — detects manual DB changes, drift, and partial migrations
- No snapshot storage or versioning required — simpler state management
- Single code path for all scenarios (install, upgrade, plugin)
- Impossible to have stale snapshot causing wrong diff

**Cons**:
- **Requires live DB connection at plan time** — can't generate migration SQL offline
- Introspection queries are engine-specific and fragile (INFORMATION_SCHEMA quirks)
- Performance: introspecting 30+ tables on every schema check is slow
- Reverse type mapping is lossy (MySQL `INT` → was it `int` or `timestamp`?)
- Non-deterministic: same YAML may generate different DDL depending on DB state
- Cannot "preview" migrations in CI without a DB instance

### Alternative 4B: Snapshot-Only (Store Last Applied State)

**Description**: After every schema operation, the resolved `SchemaDefinition` is serialized (JSON/PHP) as a snapshot. Diff compares old snapshot vs new YAML-compiled SchemaDefinition. No live introspection.

**Pros**:
- Fully deterministic — same YAML versions always produce same diff
- Works offline — no DB connection needed at plan time
- Fast — model-to-model comparison, no I/O
- Snapshot is portable — can generate migration SQL in CI without DB
- Already partially designed: Plugin HLD stores schema in `state` JSON

**Cons**:
- Drift-blind — manual DB changes (DBA fixes, failed migrations) go undetected
- Snapshot can become stale if manual interventions happen
- Must bootstrap snapshot for existing installations (one-time introspection)
- Additional storage: snapshot per version, per plugin
- If snapshot format changes, old snapshots need migration (meta-migration problem)

### Alternative 4C: Changelog-Based (Explicit Changes Tracked)

**Description**: Instead of diffing schemas, developers write explicit change declarations: "add column X to table Y", "modify index Z". Changes are versioned and applied in order. No automatic diff detection.

**Pros**:
- Maximum developer control — no surprises from automatic diff
- Natural fit for data migrations (rails-style: schema change + data backfill in one file)
- Human-readable migration history
- No reverse type mapping needed — changes are forward declarations

**Cons**:
- **Loses the declarative YAML benefit entirely** — we're back to imperative migrations
- Developer must manually track every change — error-prone for complex schema evolutions
- No drift detection — DB state is assumed correct
- Contradicts the research question which specifically asks for declarative YAML→DDL
- Plugin developers must write migrations instead of updating schema.yml

### Alternative 4D: Hybrid (Snapshot for Detection + Live for Verification)

**Description**: Primary diff uses snapshot comparison (old snapshot vs new YAML = DiffOperation[]). Before execution, optionally verify by introspecting live DB to check snapshot accuracy. Drift mode: run YAML vs live introspection to detect undeclared changes. SchemaExecutor stores snapshot after successful execution.

**Pros**:
- Best of both: deterministic planning (snapshot) + accuracy verification (live)
- Drift detection available as explicit operation (not on every schema change)
- Plugin system already stores snapshots — natural extension
- CI can generate DDL offline via snapshot; production can verify before executing
- Graceful degradation: if no snapshot exists, falls back to live introspection

**Cons**:
- Two code paths to maintain (snapshot diff + live introspection)
- Snapshot+live mismatch creates an additional decision: which to trust?
- More complex SchemaExecutor (plan from snapshot, verify from live, reconcile)
- Drift detection is optional — teams that don't run it get stale-snapshot bugs
- Initial implementation is larger than any single approach

### Recommended Approach: **4D — Hybrid (Snapshot + Live Verification)**

This is the research report's own recommendation (§7, synthesis §1.1 "Hybrid schema versioning: snapshot + live introspection fallback" at 85% confidence). Snapshot-based diff gives deterministic, offline-capable planning. Live introspection provides drift detection and safety verification. The Plugin HLD already stores schema snapshots in `state` JSON — this extends that pattern to core. The fallback from snapshot to live introspection handles edge cases (missing snapshot, first install) gracefully.

### Interaction Notes

- **Affects Connection Abstraction**: SchemaIntrospector requires `ConnectionInterface` for live introspection path.
- **Affects YAML Format**: SchemaCompiler produces `SchemaDefinition` that becomes the snapshot format.
- **Affects DDL Generator**: DdlGenerator receives `DiffOperation[]` regardless of how they were produced (snapshot or live diff).

---

## Decision Area 5: Query Builder Style

### Context

Research confirms all 8 services use raw SQL with prepared statements exclusively (synthesis §1.1, 95% confidence). The query builder is **optional sugar** — it must not replace raw SQL. Its primary audience is: (1) plugin developers writing simple CRUD, (2) common patterns that benefit from abstraction (IN clauses, pagination, upsert), (3) engine-specific syntax that the builder can smooth over (UPSERT, boolean rendering, LIMIT syntax). The builder must coexist with raw SQL in the same repositories.

### Alternative 5A: No Query Builder (Raw PDO Only)

**Description**: The database service provides Connection + Schema Management only. No query builder at all. Repositories write all SQL by hand using `$db->prepare()`. Engine-specific syntax handled by services checking `$db->getDriver()`.

**Pros**:
- Simplest possible service — fewer components to build and maintain
- Forces SQL literacy — every developer understands every query
- No abstraction leaks — what you write is what executes
- Zero overhead — no builder object allocation, no compilation step
- Fastest possible path to MVP

**Cons**:
- Plugin developers must handle engine-specific syntax (UPSERT, boolean, quoting) themselves
- Repetitive boilerplate for common patterns (IN clause, pagination, batch insert)
- No table prefix abstraction in queries — every SQL string includes `{prefix}topics`
- Engine-specific branches scattered across consumer code
- No `whereIn()` with safe parameter expansion — developers tend to string-interpolate arrays

### Alternative 5B: Thin SQL Helper (Parameter Binding + Fragments)

**Description**: Not a full query builder. A stateless helper class providing methods: `buildIn(array $values): [sqlFragment, bindings]`, `buildInsertValues(array $columns, array $rows): [sql, bindings]`, `quoteIdentifier(string $name): string`, `buildUpsert(...)`. Repositories compose SQL strings manually but use helpers for tricky parts.

**Pros**:
- Solves the specific pain points (IN clauses, batch inserts, identifier quoting)
- No fluent API complexity — each method is a standalone utility
- Easy to understand — no query model, no compilation, no method chaining
- Minimal implementation effort — 5-6 methods covering 80% of the boilerplate
- Can coexist naturally with raw SQL (it produces SQL fragments, not full queries)

**Cons**:
- Not composable — can't build a full query incrementally
- Still requires manual SQL concatenation — more error-prone than a builder
- No engine-specific SQL generation (boolean, LIMIT syntax) — just helpers for parameters
- Plugin developers still need SQL knowledge for anything beyond simple CRUD
- Doesn't smooth over UPSERT syntax differences (biggest engine divergence)

### Alternative 5C: Full Fluent Builder (Doctrine/Laravel Style)

**Description**: Complete query builder with `SelectQuery`, `InsertQuery`, `UpdateQuery`, `DeleteQuery` classes. Fluent method chaining API: `$qf->select('*')->from('topics')->where('forum_id', $id)->limit(10)->fetchAll()`. Engine-specific SQL generated by per-engine `Compiler`. `Raw` escape hatch for SQL the builder can't express.

**Pros**:
- Industry-standard pattern — Doctrine DBAL, Laravel, CakePHP, Cycle all use it
- Handles all engine differences transparently: UPSERT, quoting, booleans, LIMIT
- Table prefix applied automatically by Compiler — repositories use logical names
- Parameter binding always automatic — eliminates SQL injection risk in builder-generated SQL
- Plugin developers write PHP, not SQL — lower barrier to entry
- Composable: subqueries, joins, expressions all nest naturally

**Cons**:
- Significant implementation effort — 4 builder classes + compiler per engine + expression builder
- Some queries can't be expressed — Raw escape hatch is always needed
- Performance overhead: object allocation + compilation (microseconds, but measurable at scale)
- Risk of builder becoming "the way" — depletes raw SQL literacy in the team over time
- Testing: must verify generated SQL per engine × query type × clause combination

### Alternative 5D: Immutable Builder with Typed Expressions

**Description**: Like 5C but every method returns a new builder instance (immutable). Type-safe expression objects replace string-based operators. `Operator` enum constrains comparisons. Column references are validated against schema at build time (optional).

```php
$query = $qf->select('topics', 't')
    ->columns('t.topic_id', 't.title')
    ->where(Expr::eq('t.forum_id', $forumId))
    ->where(Expr::gt('t.created_at', $since))
    ->orderBy('t.created_at', Order::Desc)
    ->limit(10);

$next = $query->where(Expr::eq('t.is_pinned', true)); // $query unchanged
```

**Pros**:
- Immutable — safe to pass builders around, create variants from a base query
- Type-safe expressions — `Expr::eq()` catches operator errors at code level
- Schema-validated columns catch typos before SQL generation (if schema loaded)
- Functional style aligns with modern PHP trends
- Branching is natural: $base is reusable template

**Cons**:
- **GC pressure**: complex query with 10 method calls creates 10 objects
- Unfamiliar in PHP ecosystem — every major framework uses mutable builders
- Verbose: `Expr::eq('col', $val)` vs `->where('col', $val)`
- Schema validation at build time requires schema loading — heavyweight for simple queries
- `Raw` escape hatch breaks immutability semantics
- Industry evidence against: research report §11 decision #2 notes "A: Safe but verbose + GC pressure"

### Recommended Approach: **5C — Full Fluent Builder (Mutable, with clone support)**

Research report is explicit: "Mutable + clone support — matches all major frameworks" (§11, decision #2). The full builder handles the key engine divergences (UPSERT, boolean, quoting, LIMIT) that would otherwise scatter across consumer code. `Raw` escape hatch is mandatory for the 20% of queries the builder can't express. Table prefix handled by Compiler — biggest ergonomic win for plugin developers. The thin helper (5B) solves fragments but not composition; the immutable builder (5D) fights PHP conventions. Mark as post-MVP per research §14 Phase 4. Build it, but build Connection + Schema first.

### Interaction Notes

- **Affects Connection Abstraction**: Builder gets `ConnectionInterface` via `QueryFactory` for executing terminal methods (`fetchAll()`, `execute()`).
- **Affects DDL Generator**: Shares the engine-detection factory pattern (engine-specific Compiler per engine-specific DdlGenerator).
- **Affects Type System**: Compiler may use TypeRegistry for boolean compilation and parameter type inference, but this is optional.

---

## Decision Area 6: DDL Generator Architecture

### Context

The DDL generator converts abstract `DiffOperation` objects (CreateTable, AddColumn, ModifyColumn, etc.) into engine-specific SQL strings. With 10 operation types × 3 engines = 30 combinations. The architecture must handle engine-specific syntax (MySQL AUTO_INCREMENT vs PG IDENTITY, SQLite table rebuild), operation ordering (drop FKs before columns), and safety checks (pre-flight queries for destructive operations). This is the component with the highest engine-specific complexity.

### Alternative 6A: Single Class with Switch Statements

**Description**: One `DdlGenerator` class with methods per operation type. Each method contains a `match($driver)` to emit engine-specific SQL. All 30 combinations live in one file.

```php
class DdlGenerator {
    public function generate(DiffOperation $op): array {
        return match(true) {
            $op instanceof CreateTable => $this->generateCreateTable($op),
            $op instanceof AddColumn => $this->generateAddColumn($op),
            // ...
        };
    }
    
    private function generateCreateTable(CreateTable $op): array {
        return match($this->driver) {
            'mysql' => [...],
            'pgsql' => [...],
            'sqlite' => [...],
        };
    }
}
```

**Pros**:
- Everything in one place — easy to find, easy to search
- No class hierarchy overhead — minimal file count
- Simple to implement initially
- Debugging: one file to step through

**Cons**:
- **God class** — grows to 500+ lines as engine-specific logic accumulates
- Open/Closed violation: adding an engine means modifying every method
- No isolation: MySQL bug fix risks breaking PostgreSQL path
- Cannot test one engine in isolation without the others
- TypeRegistry + column rendering mixed into same class as table DDL

### Alternative 6B: Strategy Pattern (Interface Per Engine)

**Description**: `DdlGeneratorInterface` defines `generate(DiffOperation): string[]`. One implementation per engine: `MySQLDdlGenerator`, `PostgreSQLDdlGenerator`, `SQLiteDdlGenerator`. Factory selects implementation based on connection driver. Each generator handles all operation types for its engine.

**Pros**:
- **Clean engine isolation** — each generator is tested independently
- Adding a new engine = adding a new class (Open/Closed principle)
- Each class is 150-200 lines — manageable size
- Factory pattern with DI enables automatic engine selection
- Consistent with Query Compiler design — same architectural pattern through the service

**Cons**:
- Shared logic (column declaration ordering, operation dispatch) must be in abstract base class
- 10 operation types × 3 generators = some duplication where engines have identical DDL (e.g., DROP INDEX)
- Abstract base must avoid becoming a god class itself
- Cannot easily compare engines side-by-side for the same operation

### Alternative 6C: Template-Based (SQL Templates with Placeholders)

**Description**: DDL is generated from SQL templates stored as strings with named placeholders. A `TemplateRenderer` resolves placeholders from DiffOperation properties and TypeRegistry mappings. Templates can be per-engine or shared.

```php
// templates/mysql/create_table.sql.tpl
'CREATE TABLE {table_name} ({columns}) ENGINE={engine} DEFAULT CHARSET={charset}'

// templates/pgsql/create_table.sql.tpl  
'CREATE TABLE {table_name} ({columns})'
```

**Pros**:
- SQL is visible as SQL — easy for DBAs to review templates
- Custom templates enable engine-specific extensions without code changes
- Separation of SQL shape from rendering logic
- Plugins could override templates for custom DDL behavior

**Cons**:
- Template language needs conditional logic (IF column.nullable, IF column.auto_increment) — becomes its own DSL
- Dynamic SQL construction (variable number of columns, indexes) doesn't fit templates well
- More indirection: debug path goes template → renderer → SQL
- Template discovery and loading adds file I/O at generation time
- Industry DDL generators do NOT use templates — no precedent to follow

### Alternative 6D: Visitor Pattern on Schema Model

**Description**: `DiffOperation` objects accept a `DdlVisitor` which dispatches to per-operation methods. Each engine implements `MySQLDdlVisitor`, `PostgreSQLDdlVisitor`. The visitor traverses the diff operation tree and accumulates SQL statements.

```php
interface DdlVisitor {
    public function visitCreateTable(CreateTable $op): array;
    public function visitAddColumn(AddColumn $op): array;
    public function visitModifyColumn(ModifyColumn $op): array;
    // ... one method per operation type
}

class MySQLDdlVisitor implements DdlVisitor { ... }
```

**Pros**:
- True double dispatch — operation type × engine resolved at compile time
- Adding a new operation type forces all visitors to implement it (compiler enforcement)
- Each visitor method is small and focused — no switch/match inside
- Standard GoF pattern — well-understood in the industry

**Cons**:
- **Heavy boilerplate** in PHP — `accept()` method on every DiffOperation, visitor interface with 10+ methods
- Adding a new operation type requires modifying the interface + all implementations (visitor anti-pattern)
- Overkill: the set of operation types is small and stable (10 types, unlikely to grow much)
- PHP doesn't support sealed hierarchies — can't enforce exhaustive visiting
- DiffOperation objects need `accept()` method that couples them to the visitor contract

### Recommended Approach: **6B — Strategy Pattern (Interface Per Engine)**

Research report consensus (§6, §10, synthesis §2.1): Strategy pattern is used for DDL generation, query compilation, and schema introspection — three parallel subsystems. Using the same pattern everywhere reduces cognitive load. Each engine generator is independently testable. The `AbstractDdlGenerator` base class holds shared logic (operation dispatch, column ordering) while engine subclasses override the engine-specific methods. The template approach (6C) doesn't fit dynamic DDL well. The visitor (6D) adds boilerplate that the stable, small operation set doesn't justify.

### Interaction Notes

- **Affects Type System**: DdlGenerator delegates `getColumnDeclarationSQL()` to TypeRegistry — it does NOT own type-to-SQL mapping.
- **Affects Schema Diff**: SchemaDiffer produces engine-agnostic `DiffOperation[]`; DdlGenerator translates to engine-specific SQL.
- **Affects Connection**: DdlGenerator is selected by factory based on `ConnectionInterface::getDriver()`.

---

## Trade-Off Analysis

### 5-Perspective Comparison Matrix

| Decision Area | Recommended | Tech Feasibility | User Impact | Simplicity | Risk | Scalability |
|---|---|---|---|---|---|---|
| **1. Connection** | 1B: Thin Interface | ★★★★★ (trivial) | ★★★★☆ (type-hint change) | ★★★★★ (5 methods) | ★★★★★ (low risk) | ★★★★☆ (decorator-extensible) |
| **2. Type System** | 2A: Mapping Array | ★★★★★ (arrays) | ★★★★☆ (invisible) | ★★★★★ (one file) | ★★★☆☆ (reverse mapping fragile) | ★★★☆☆ (harder for plugins) |
| **3. YAML Format** | 3C: Abstract+Override | ★★★★☆ (two sections) | ★★★★★ (clean default) | ★★★★☆ (moderate) | ★★★★☆ (Doctrine-proven) | ★★★★★ (overrides for edge cases) |
| **4. Schema Diff** | 4D: Hybrid | ★★★☆☆ (two paths) | ★★★★★ (reliable) | ★★★☆☆ (complex) | ★★★★☆ (drift-safe) | ★★★★★ (offline + online) |
| **5. Query Builder** | 5C: Full Fluent | ★★★☆☆ (significant) | ★★★★★ (ergonomic) | ★★★☆☆ (many classes) | ★★★★☆ (industry-proven) | ★★★★★ (all engines) |
| **6. DDL Generator** | 6B: Strategy | ★★★★☆ (standard) | ★★★★☆ (transparent) | ★★★★☆ (per-engine) | ★★★★★ (isolated) | ★★★★★ (add engine = add class) |

### Key Trade-Offs Accepted

1. **Simplicity vs Plugin Extensibility (Type System)**: Choosing 2A (mapping array) over 2C (type classes) trades plugin-defined custom types for implementation simplicity. If plugins need custom SQL types, the registry must accept additional mappings via DI — possible but less elegant than a class-per-type system.

2. **Implementation Effort vs User Experience (Query Builder)**: Choosing 5C (full builder) is Phase 4 / post-connection work. It's the most effort but the biggest DX win for plugin developers. The `Raw` escape hatch ensures no one is forced to use it.

3. **Complexity vs Reliability (Schema Diff)**: Choosing 4D (hybrid) means two code paths (snapshot + live). The complexity is justified by drift detection and offline planning capability — but the team must actually run drift checks, or the live path becomes dead code.

---

## User Preferences

Based on the original research question and architecture context:

- **No ORM**: Explicitly rejected. Rules out Doctrine-style type conversion, entity mapping, and any builder that implies object-relational mapping.
- **Raw PDO + Prepared Statements**: The established pattern across 8 services. Must not be disrupted.
- **Symfony DI**: All components must be registrable as Symfony services with factory-based engine resolution.
- **Greenfield**: No legacy migration burden. Can make clean architectural choices.
- **Plugin-friendly**: The schema pipeline's primary dynamic consumer is the plugin system.
- **Multi-engine parity**: MySQL 8+ and PostgreSQL 14+ are first-class; SQLite is dev/test, with deferred ALTER support.

---

## Recommended Approach: Integrated Summary

The recommended combination forms a **layered service** where each decision reinforces the others:

1. **Thin Connection Interface** (1B) provides the infrastructure foundation every layer builds on.
2. **Simple Type Mapping Array** (2A) serves as the single source of truth consumed by DDL generators, schema compiler, and introspectors — no ORM-style conversion.
3. **Abstract YAML + Engine Overrides** (3C) presents a clean, portable developer API that scales to edge cases when needed.
4. **Hybrid Schema Diff** (4D) enables deterministic offline planning with live safety verification.
5. **Full Fluent Query Builder** (5C) is optional syntactic sugar that handles engine-specific syntax transparently — built last, in Phase 4.
6. **Strategy-Pattern DDL Generator** (6B) provides clean engine isolation, mirroring the same pattern used for query compilation and schema introspection.

### Key Assumptions

- Single shared connection per request remains valid (no connection pooling needed).
- 20 abstract types cover all phpBB and plugin needs — custom types are rare.
- Developers will run drift detection periodically; snapshots won't silently drift in production.
- SQLite ALTER support (table rebuild) is deferrable to post-MVP without blocking the test suite.

### Confidence: **High (85-90%)**

The combination closely matches the research report's own recommendations (§11, §13). The highest uncertainty is in the type system scalability (2A may need upgrade if plugins demand custom types) and schema diff complexity (4D has two code paths to maintain).

---

## Why Not Others

| Decision Area | Rejected | Rationale |
|---|---|---|
| **Connection** | 1A (Bare PDO) | No lazy init, no logging hook, not testable without real DB |
| **Connection** | 1C (Full DBAL) | Contradicts raw-SQL philosophy; overlaps with query builder |
| **Connection** | 1D (Multiplexed) | Over-engineering for single-server phpBB |
| **Type System** | 2B (Enum) | Closed set blocks plugin custom types; verbose per-case methods |
| **Type System** | 2C (Doctrine Classes) | Implies conversion we don't want; 20+ classes for lookup tables |
| **Type System** | 2D (Visitors) | Overkill indirection for 20 stable types |
| **YAML Format** | 3A (Minimal) | No FK support, no engine overrides — too restrictive for core |
| **YAML Format** | 3B (Rich) | Too complex for plugin developers; encourages non-portable schemas |
| **YAML Format** | 3D (Convention) | Magic inference is hard to debug; convention conflicts are silent |
| **Schema Diff** | 4A (Live only) | Requires DB at plan time; non-deterministic |
| **Schema Diff** | 4B (Snapshot only) | Drift-blind; manual DB changes go undetected |
| **Schema Diff** | 4C (Changelog) | Destroys declarative benefits; back to imperative migrations |
| **Query Builder** | 5A (None) | Plugin developers need help with engine-specific syntax |
| **Query Builder** | 5B (Thin helper) | Fragments but not composition; doesn't handle UPSERT portably |
| **Query Builder** | 5D (Immutable) | GC pressure; fights PHP ecosystem conventions |
| **DDL Generator** | 6A (Single class) | God class; no engine isolation; violates Open/Closed |
| **DDL Generator** | 6C (Templates) | Dynamic DDL doesn't fit templates; no industry precedent |
| **DDL Generator** | 6D (Visitor) | Boilerplate for stable, small operation set |

---

## Combination Patterns

### How Recommended Choices Work Together

**Pattern 1: Schema Lifecycle (Install/Upgrade)**
```
YAML file → SchemaCompiler (validates via TypeRegistry 2A)
         → SchemaDefinition (abstract model per format 3C)
         → SchemaDiffer (snapshot diff per 4D)
         → DiffOperation[] (engine-agnostic)
         → DdlGenerator (strategy per engine, 6B, column SQL via TypeRegistry 2A)
         → SQL[]
         → SchemaExecutor (via Connection 1B)
         → New snapshot stored
```

**Pattern 2: Repository Read (Existing Pattern, Unchanged)**
```
Repository → $this->db->prepare($sql) → PDOStatement
          → $stmt->execute($params) → fetch()
          → Entity::hydrate($row)
          (Connection 1B; no query builder, no type system involvement)
```

**Pattern 3: Plugin CRUD (New, Optional)**
```
PluginRepository → $this->qf->select('plugin_items')
               → ->where('plugin_id', $id)->fetchAll()
               → Compiler (5C) adds table prefix, quotes identifiers
               → CompiledQuery → $db->prepare($sql)->execute($bindings)
               (Query Builder 5C uses Connection 1B; Compiler is engine-specific)
```

**Pattern 4: Drift Detection (Maintenance)**
```
CLI command → SchemaCompiler → declared SchemaDefinition
           → SchemaIntrospector (via Connection 1B) → live SchemaDefinition
           → SchemaDiffer → DiffOperation[] (if any = drift detected)
           → Report to admin
           (Hybrid diff 4D, live path; Connection 1B provides introspection access)
```

### Cross-Cutting Concerns

| Concern | How It's Handled |
|---------|-----------------|
| **Engine detection** | `ConnectionInterface::getDriver()` → factory selects engine-specific Strategy class |
| **Table prefix** | Connection stores prefix; Compiler (QB) auto-prefixes; DdlGenerator receives prefix in constructor |
| **Type resolution** | TypeRegistry (2A) is singleton injected into SchemaCompiler, DdlGenerator, SchemaIntrospector |
| **Testing** | ConnectionInterface (1B) is mockable; each Strategy class testable in isolation; SQL output assertions |
| **DI wiring** | All factory-resolved via Symfony DI: `DdlGeneratorInterface` → `DdlGeneratorFactory::create()` using driver |

---

## Deferred Ideas

| Idea | Rationale for Deferral | When to Consider |
|------|----------------------|------------------|
| **Read/write connection splitting** | phpBB is single-server; no replica setup expected. Connection 1D rejected but 1B is decorator-extensible if needed later. | If phpBB cloud hosting or large forums require read replicas |
| **Schema-validated query builder columns** | 5D proposed schema validation; too heavyweight. Could be an optional dev-mode decorator on Compiler. | If plugin developers frequently misspell column names |
| **Generated columns in YAML** | MySQL `GENERATED ALWAYS AS (expr)` and PG `GENERATED STORED`. Useful for search/denormalization. Add `generated:` key to column definition. | When fulltext search or computed columns become a requirement |
| **Partial indexes** | PG supports `CREATE INDEX WHERE condition`. MySQL 8 supports functional indexes. Add optional `condition:` to index YAML. | When performance optimization requires filtered indexes |
| **Query builder CTEs / window functions** | Modern SQL features (WITH, ROW_NUMBER). Add `->with()` to SelectQuery. | When complex analytics or recursive queries appear in domain requirements |
| **Database event hooks** | `Connection::onQuery(callback)`, `onCommit(callback)`. Useful for audit logging, cache invalidation. | When cross-cutting query observation is needed (debugging, profiling) |
| **Multi-database transaction coordinator** | Two-phase commit across connections. | Very unlikely for phpBB; only if sharding is ever considered |
| **SQLite table rebuild for ALTER** | Full ALTER emulation via temporary table copy. Research §11 decision #7 defers to post-MVP. | When test suite needs ALTER operations on SQLite |
| **Custom plugin type registration** | Extend TypeRegistry to accept plugin-defined abstract types. 2A's array is extensible but not as clean as 2C's class registration. | When a plugin needs a type not in the standard 20+ set (spatial, composite) |
