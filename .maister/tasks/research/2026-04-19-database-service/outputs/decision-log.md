# Decision Log

---

## ADR-DB-1: Thin Connection Interface

### Status
Accepted

### Context
All 8 domain services currently inject `\PDO` directly via constructor for database access. They write raw SQL with prepared statements and do manual entity hydration. The database service needs a connection abstraction that adds infrastructure benefits (lazy initialization, testability, table prefix access, driver detection) without imposing abstraction tax on repositories' raw SQL workflows. Four options were evaluated: bare PDO (no wrapper), thin interface, full DBAL-style, and multiplexed connection manager.

### Decision Drivers
- Repositories must retain full control over their SQL — no forced query builder or type conversion
- Lazy connection saves unnecessary PDO instantiation for cache-hit requests
- Table prefix (`phpbb_`) and driver name are cross-cutting needs used by all services
- Unit tests require mockable database access without real DB connections
- Decorator pattern must be applicable for future logging/profiling

### Considered Options
1. **1A: Bare PDO** — no wrapper, DI factory creates PDO directly
2. **1B: Thin Connection Interface** — 5-method interface wrapping PDO with lazy init
3. **1C: Full DBAL-style Connection** — insert/update/fetchAssoc helpers, type conversion, identifier quoting
4. **1D: Multiplexed Connection Manager** — named connections for read/write splitting

### Decision Outcome
Chosen option: **1B: Thin Connection Interface**, because it provides the exact infrastructure benefits needed (lazy init, testability, prefix/driver accessors) with minimal surface area (5 methods). Repositories change only their type-hint from `\PDO` to `ConnectionInterface` — their SQL remains untouched.

### Consequences

#### Good
- Lazy connection eliminates PDO instantiation for cache-hit requests
- `getTablePrefix()` and `getDriver()` centralize the two most common cross-cutting needs
- 5-method interface is trivially mockable for unit tests
- Decorator pattern enables future logging/profiling without modifying Connection
- No abstraction tax — `prepare()` and `exec()` delegate directly to PDO

#### Bad
- All 8 domain services must update their constructor type-hints from `\PDO` to `ConnectionInterface`
- `getPdo()` escape hatch exists but may be overused if not governed by convention

---

## ADR-DB-2: Enum-Based Type System

### Status
Accepted

### Context
The type system maps ~19 abstract YAML types (uint, varchar, json, etc.) to engine-specific SQL types across MySQL, PostgreSQL, and SQLite. It's consumed by 4 components: SchemaCompiler (validation), DdlGenerator (SQL rendering), SchemaIntrospector (reverse mapping), and SchemaDiffer (comparison normalization). The research recommended a simple mapping array (2A), but the user chose enum-based type objects (2B) for type safety and IDE support. The core tension: enums are closed sets, but plugins may rarely need custom types.

### Decision Drivers
- Type safety: invalid type names caught at compile time, not runtime
- IDE support: autocomplete for all type cases and their methods
- Per-case behavior: `validate()` can enforce type-specific rules (varchar needs length)
- Single file discoverability: all types visible in one enum
- Plugin extensibility must remain possible despite closed enum

### Considered Options
1. **2A: Simple Mapping Array** — TypeRegistry with `$abstractToSql` / `$sqlToAbstract` arrays
2. **2B: Enum-Based Type Objects** — PHP 8.1 backed enum with `toSql()`, `fromSql()`, `validate()` methods
3. **2C: Doctrine-Inspired Type Classes** — one class per type implementing TypeInterface
4. **2D: Value Objects with Engine Visitors** — ColumnType value object + per-engine visitor

### Decision Outcome
Chosen option: **2B: Enum-Based Type Objects**, because it provides compile-time type safety, IDE autocomplete, and per-case validation in a single discoverable file. Plugin extensibility is handled via a `TypeExtensionInterface` + DI-tagged services, making the `TypeRegistry` a composite of the core enum + registered extensions.

### Consequences

#### Good
- `match($col->type)` expressions are exhaustive — compiler catches missing cases
- IDE autocomplete lists all 19 types with their methods
- `AbstractType::Varchar->validate($col)` enforces type-specific rules (length required)
- `toSql('mysql')` and `fromSql('INT UNSIGNED', 'mysql')` are discoverable methods on the enum
- Single file (~300 lines) contains all core type logic

#### Bad
- PHP enums don't support per-case properties — each method uses `match($this)` which is verbose
- Adding a 20th core type requires modifying the enum (acceptable — core types change rarely)
- Plugin custom types are second-class: registered via `TypeExtensionInterface`, no enum autocomplete, no `match()` exhaustiveness
- Reverse mapping (`fromSql`) must scan all cases + extensions — O(n) but n≤25 is negligible
- The `match($this)` pattern within each method results in more lines than array lookups

---

## ADR-DB-3: Abstract YAML with Engine Overrides

### Status
Accepted

### Context
The YAML schema format is the developer-facing API for declaring database tables. Plugin developers (external contributors) and the core team both write schemas. The format must be readable, portable across 3 engines by default, yet allow engine-specific tuning for advanced cases. The key tension: simplicity for plugin developers vs expressiveness for core optimizations.

### Decision Drivers
- Plugin developers must be able to write schemas without knowing engine differences
- Core team needs engine-specific table options (InnoDB, charset)
- Abstract types in columns must be the default — engine-specific overrides must be opt-in
- Foreign keys must be declarable but optional (loose coupling preferred for plugins)
- Format must be validated by SchemaCompiler with clear error messages

### Considered Options
1. **3A: Minimal Format** — types + constraints only, no FK, no engine options
2. **3B: Rich Format** — per-engine column overrides, engine-specific columns, full FK
3. **3C: Abstract + Engine Overrides** — clean abstract columns, optional `overrides:` per table
4. **3D: Convention-Heavy** — column name patterns infer types (`*_id` → uint)

### Decision Outcome
Chosen option: **3C: Abstract + Engine Overrides**, because it keeps the primary `columns:` section clean and portable while providing an opt-in `overrides:` section for engine-specific table options. FK section is included but optional. This mirrors Doctrine DBAL's approach and is familiar to the PHP ecosystem.

### Consequences

#### Good
- Plugin developers only interact with abstract types — zero engine knowledge required
- `overrides:` section is clearly separated and ignorable
- SchemaCompiler validates the abstract section independently of engine-specific overrides
- ForeignKey section enables referential integrity for core tables while remaining optional
- Format proven by Doctrine ecosystem

#### Bad
- Two places to check for table configuration (main columns + overrides section)
- `overrides:` scope must be clearly documented — it's table-level options only, not column type overrides
- Marginally more complex than the minimal format (3A)

---

## ADR-DB-4: Hybrid Schema Diff Strategy

### Status
Accepted

### Context
Schema diffing detects what DDL to execute during install, upgrade, and plugin lifecycle. The strategy must handle: fresh install, core upgrade (version N→N+1), plugin update (old state→new YAML), and drift detection (YAML vs actual DB). Pure snapshot diff is deterministic but drift-blind. Pure live introspection is accurate but requires DB access and is non-deterministic. The plugin system HLD already stores schema snapshots in plugin state JSON.

### Decision Drivers
- Deterministic diff for CI/CD — must generate migration SQL offline without live DB
- Drift detection for production safety — must catch manual DB changes
- Plugin system already uses snapshots — natural extension of existing pattern
- Fresh install must work without existing snapshots (fallback to live introspection)
- Core upgrades must produce identical DDL regardless of DB state

### Considered Options
1. **4A: Live-Diff-Only** — always introspect DB, compare to YAML
2. **4B: Snapshot-Only** — store applied SchemaDefinition, diff against new YAML
3. **4C: Changelog-Based** — explicit change declarations, no automatic diff
4. **4D: Hybrid** — snapshot-based planning + live introspection for verification/drift

### Decision Outcome
Chosen option: **4D: Hybrid (Snapshot + Live Verification)**, because it combines deterministic offline planning (snapshot diff) with accuracy insurance (live introspection). Normal operations (install, upgrade, plugin update) use snapshot comparison for speed and determinism. Drift detection is a separate explicit operation that uses live introspection. Missing snapshots fall back to introspection for graceful bootstrapping.

### Consequences

#### Good
- CI can generate migration SQL without a live database
- Same YAML versions always produce identical DiffOperation[] from snapshots
- Drift detection catches manual DB changes (DBA fixes, failed partial migrations)
- Plugin system snapshot pattern extends naturally
- Fallback to introspection handles missing-snapshot edge case

#### Bad
- Two code paths to maintain (snapshot diff + live introspection diff)
- Snapshot-live mismatch requires a resolution policy (documented: snapshot wins for operations, live wins for drift reports)
- Teams must actively run drift detection — passive snapshot path won't catch undeclared changes
- SnapshotManager adds storage overhead (JSON per schema version)

---

## ADR-DB-5: Full Fluent Mutable Query Builder

### Status
Accepted

### Context
All 8 domain services use raw SQL exclusively. The query builder is optional sugar — it must not replace raw SQL and must coexist with it. Its primary audience: plugin developers writing CRUD, common patterns (IN clauses, pagination, upsert), and engine-specific syntax smoothing (boolean rendering, identifier quoting, LIMIT). The builder is Phase 4 (post-MVP) but interfaces must be stable now because plugin development APIs depend on them.

### Decision Drivers
- Engine-specific syntax divergences (UPSERT, boolean, quoting, LIMIT) must be transparent to callers
- Plugin developers benefit from a PHP API that eliminates manual SQL for simple CRUD
- Table prefix must be applied automatically by the compiler — not by callers
- `Raw` escape hatch is mandatory for the ~20% of queries the builder can't express
- Mutable builder matches PHP ecosystem conventions (Doctrine, Laravel, CakePHP)
- Raw SQL via `prepare()` must remain a first-class path — builder is never forced

### Considered Options
1. **5A: No Query Builder** — raw PDO only
2. **5B: Thin SQL Helper** — stateless helper for IN clauses, batch inserts, quoting
3. **5C: Full Fluent Mutable Builder** — SelectQuery/InsertQuery/etc. with per-engine Compiler
4. **5D: Immutable Builder with Typed Expressions** — each method returns new instance

### Decision Outcome
Chosen option: **5C: Full Fluent Mutable Builder**, because it handles the key engine divergences transparently, provides familiar API matching all major PHP frameworks, and auto-prefixes tables via Compiler. `Raw` escape hatch ensures no one is forced to use it. Built in Phase 4 but interfaces designed now.

### Consequences

#### Good
- Handles all engine differences transparently: UPSERT, boolean rendering, identifier quoting, LIMIT
- Table prefix applied automatically — biggest ergonomic win for plugin developers
- Parameter binding always automatic — eliminates SQL injection risk in builder-generated queries
- Familiar API — developers with Doctrine/Laravel experience are immediately productive
- Coexists with raw SQL — repositories can mix `$db->prepare()` and `$qf->select()`

#### Bad
- Significant implementation effort — 4 builder classes + 3 compilers + expression builder
- Some queries can't be expressed — Raw escape hatch is always needed for complex SQL
- Risk of builder becoming "the way" — could diminish raw SQL literacy over time
- Object allocation overhead (microseconds per query — negligible but measurable)
- Must test generated SQL per engine × query type × clause combination

---

## ADR-DB-6: Strategy Pattern DDL Generator

### Status
Accepted

### Context
The DDL generator converts abstract DiffOperation objects (CreateTable, AddColumn, etc.) into engine-specific SQL strings. With ~10 operation types × 3 engines = 30 combinations. The architecture must handle engine-specific syntax (MySQL AUTO_INCREMENT vs PG IDENTITY, SQLite limitations), operation ordering, and safety checks. The same pattern is already used for QueryCompiler and SchemaIntrospector — architectural consistency matters.

### Decision Drivers
- Each engine's DDL syntax is sufficiently different to warrant isolated implementations
- Same Strategy pattern used for QueryCompiler and SchemaIntrospector — consistent architecture reduces cognitive load
- Each engine generator must be independently testable
- Adding a new engine (e.g., MariaDB variant) should mean adding a new class, not modifying existing ones
- Shared logic (operation dispatch, column ordering) belongs in an abstract base class

### Considered Options
1. **6A: Single Class with Switch** — one DdlGenerator with `match($driver)` in every method
2. **6B: Strategy Pattern** — `DdlGeneratorInterface` with one implementation per engine
3. **6C: Template-Based** — SQL templates with placeholders, rendered per engine
4. **6D: Visitor Pattern** — DiffOperation accepts DdlVisitor, double dispatch

### Decision Outcome
Chosen option: **6B: Strategy Pattern**, because it provides clean engine isolation, independent testability, and architectural consistency with QueryCompiler and SchemaIntrospector. `AbstractDdlGenerator` holds shared logic while engine-specific subclasses override per-engine methods. Factory selects implementation based on `ConnectionInterface::getDriver()`.

### Consequences

#### Good
- Clean engine isolation — MySQL DDL changes don't risk breaking PostgreSQL
- Each generator is independently testable with focused test suites
- Adding a new engine = adding a new class (Open/Closed principle)
- Consistent with QueryCompiler and SchemaIntrospector — one architectural pattern for all engine-specific subsystems
- Factory pattern with DI enables automatic engine selection

#### Bad
- Some DDL is identical across engines (e.g., DROP INDEX) — minor duplication in generators
- Abstract base class must avoid becoming a god class as shared logic grows
- Cannot easily compare side-by-side how different engines handle the same operation
- Three parallel class hierarchies (DdlGenerator, QueryCompiler, SchemaIntrospector) is a lot of engine-specific code
