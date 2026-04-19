# Database Service — Cross-Source Synthesis

## Research Question

How to design a database service for phpBB rebuild that provides declarative YAML→DDL schema management, schema versioning, and multi-engine support (MySQL 8+, PostgreSQL 14+, SQLite)?

---

## Executive Summary

The database service emerges as a **layered infrastructure foundation** that 8 existing domain services and the plugin system depend upon. Cross-referencing all five finding sources reveals strong convergence: existing services unanimously use PDO with prepared statements, manual hydration, repository pattern with `array $data` writes, and post-commit event dispatch. The YAML→DDL pipeline is already structurally defined in the plugin system HLD. The key design tension is between keeping the service thin (raw PDO pass-through as existing services expect) and providing enough abstraction for multi-engine DDL generation and a useful query builder. The resolution: a **layered architecture** where Connection/Transaction form the base, Query Builder is optional sugar, and Schema Management is a self-contained subsystem invoked only during install/upgrade/plugin lifecycle — never at runtime.

---

## 1. Cross-Source Analysis

### 1.1 Validated Findings (Confirmed by Multiple Sources)

| Finding | Confidence | Sources |
|---------|-----------|---------|
| All services use `\PDO` injected via constructor | **High (95%)** | internal-patterns (all 5 services), versioning-connection |
| Repository pattern with `array $data` writes, entity hydration on reads | **High (95%)** | internal-patterns §2.1, §2.2 |
| Prepared statements exclusively, never raw interpolation | **High (95%)** | internal-patterns §2.5, query-builder §4 |
| Events dispatched AFTER transaction commit | **High (95%)** | internal-patterns §2.4, versioning-connection §6 |
| Table prefix is injected as string, concatenated into SQL | **High (95%)** | internal-patterns §3.4, versioning-connection §7 |
| YAML→DDL pipeline: SchemaCompiler → SchemaIntrospector → SchemaDiffer → DdlGenerator → SchemaExecutor | **High (95%)** | schema-management §2, internal-patterns §4.1 |
| Strategy pattern for DDL generation (one class per engine) | **High (90%)** | schema-management §4, multi-engine §7, query-builder §5 |
| Savepoint-based transaction nesting (Doctrine pattern) | **High (90%)** | versioning-connection §6 |
| Lazy connection (connect on first use) | **High (90%)** | versioning-connection §5 |
| Advisory locks for tree mutations (GET_LOCK/pg_advisory_lock) | **High (90%)** | internal-patterns §2.4, versioning-connection §6 |
| Hybrid schema versioning: snapshot + live introspection fallback | **High (85%)** | versioning-connection §4, schema-management §3 |
| Abstract type system mapping YAML types to per-engine DDL | **High (90%)** | schema-management §1, multi-engine §1 |

### 1.2 Contradictions Resolved

| Contradiction | Resolution |
|---------------|-----------|
| **Query builder vs raw SQL**: Internal patterns show services write raw SQL exclusively; query-builder findings recommend a full fluent API | **Resolution**: Query builder is OPTIONAL layer. Existing services continue using raw SQL via `ConnectionInterface::prepare()`. New code MAY use query builder. The QB primarily benefits plugin developers and common operations (IN clauses, pagination). It does NOT replace raw SQL. |
| **Single PDO vs ConnectionInterface**: Internal patterns inject `\PDO` directly; versioning-connection proposes `ConnectionInterface` wrapper | **Resolution**: Transition to `ConnectionInterface` that exposes `prepare()`, `exec()`, `lastInsertId()`, `getTablePrefix()`, `getDriver()`. Repositories type-hint `ConnectionInterface`, not `\PDO`. The wrapper adds lazy connection without changing the API surface. |
| **`uint` type handling**: schema-management maps `uint` → `INT UNSIGNED` (MySQL) / `INTEGER CHECK(col >= 0)` (PG); multi-engine warns CHECK doesn't change storage size | **Resolution**: Accept the discrepancy. For overflow protection, document that `uint` maps to BIGINT on PG when MySQL range is needed. Default mapping uses INTEGER + CHECK for simplicity; explicit `ubigint` type available for large ranges. |
| **Auto-increment portability**: MySQL uses `AUTO_INCREMENT`, PG uses `GENERATED ALWAYS AS IDENTITY`, SQLite needs `INTEGER PRIMARY KEY` | **Resolution**: Abstract `serial` / `bigserial` types. The DdlGenerator per engine handles the syntax. `lastInsertId()` abstracted via `ConnectionInterface::lastInsertId()` which maps to engine-specific mechanism internally. |

### 1.3 Confidence Assessment

| Area | Confidence | Gap |
|------|-----------|-----|
| Connection management | **95%** | Minor: reconnect error codes need runtime validation |
| Transaction management | **95%** | Savepoint pattern is Doctrine-proven |
| YAML schema format | **90%** | Edge cases: partial indexes, generated columns not fully specified |
| Abstract type mapping | **90%** | SQLite DECIMAL → REAL lossy; documented but not solved |
| Schema diff algorithm | **85%** | Column rename detection is heuristic; needs explicit YAML mapping |
| DDL generation | **85%** | SQLite table rebuild pattern needs exhaustive ALTER limitation detection |
| Query builder API | **80%** | API design validated against frameworks but untested in phpBB context |
| Schema versioning | **75%** | Migration tracking table needs implementation-time validation |

---

## 2. Patterns and Themes

### 2.1 Architectural Patterns

| Pattern | Prevalence | Quality | Evidence |
|---------|-----------|---------|----------|
| **Repository with PDO injection** | Universal (all 8 services) | Established, consistent | internal-patterns §2.1 |
| **Strategy pattern for engine abstraction** | Universal (DDL, Query Compiler, Introspector) | Industry standard (Doctrine, Laravel) | schema-management §4, query-builder §5 |
| **Value Object for configuration** | Recommended, not yet implemented | Standard PHP 8.2 pattern | versioning-connection §7 |
| **Lazy initialization** | Recommended for Connection | Standard (Doctrine, Symfony) | versioning-connection §5 |
| **Two-phase separation (plan then execute)** | Schema pipeline | Clean, testable | schema-management §2 |

### 2.2 Implementation Patterns

| Pattern | Prevalence | Quality |
|---------|-----------|---------|
| **Prepared statements for all queries** | Universal | Security-critical, non-negotiable |
| **Manual entity hydration via `hydrate(array): Entity`** | Universal | Explicit, fast, no magic |
| **`array $data` for writes** | Universal | Decouples entity from write path |
| **Atomic counter updates (`col = col + ?`)** | Common (Threads, Users) | Required for concurrent safety |
| **Two-phase pagination (SELECT IDs, then SELECT rows)** | Threads (large sets) | Performance optimization |
| **Post-commit event dispatch** | Universal | Prevents inconsistency, deadlocks |

### 2.3 Design Themes

| Theme | Assessment |
|-------|-----------|
| **Consistency** | Very high — all services follow identical repository/PDO/hydration patterns |
| **Maturity** | Established conventions (8 services designed); DB service is the missing foundation |
| **Complexity** | Schema management pipeline is the most complex subsystem; other layers are thin |
| **Abstraction depth** | Deliberately minimal — services own their SQL; DB service provides infrastructure only |

---

## 3. Key Insights

### Insight 1: The DB Service is Infrastructure, Not Abstraction

**Supporting evidence**: All 8 services write raw SQL. No service uses a query builder. The DB service must provide PDO access, not hide it. The query builder is additive convenience, not a mandatory layer.

**Implication**: `ConnectionInterface` should expose `prepare()` and `exec()` directly. Services that want raw SQL lose nothing. Services that want the query builder opt into it.

### Insight 2: Schema Management is Lifecycle-Only, Not Runtime

**Supporting evidence**: SchemaCompiler, SchemaDiffer, DdlGenerator are invoked during install/upgrade/plugin lifecycle only. No service calls them during request handling.

**Implication**: Schema management components can have heavier dependencies (YAML parser, introspection queries) without impacting request performance. They should be registered as separate DI services, not bundled into the connection.

### Insight 3: SQLite is the Weakest Link for Schema Operations

**Supporting evidence**: multi-engine §4 shows SQLite cannot ALTER column type, add NOT NULL, add FK after creation, add constraints. All require full table rebuild.

**Implication**: The `SqliteDdlGenerator` must implement an automatic table rebuild strategy for any unsupported ALTER. This adds significant complexity but is essential for dev/test parity. Could be deferred to post-MVP if SQLite is initially used only for testing with `CREATE TABLE` (no migrations).

### Insight 4: The Plugin System is the Primary Consumer of Schema Pipeline

**Supporting evidence**: internal-patterns §4 shows SchemaCompiler, SchemaIntrospector, SchemaDiffer, DdlGenerator are all required by the plugin system. Core phpBB also uses them for initial installation and version upgrades, but plugins are the dynamic consumer.

**Implication**: The schema pipeline API must be plugin-friendly: stateless, composable, with clear input/output contracts. Plugin system passes YAML + current state → gets back SQL statements.

### Insight 5: Query Builder Must Coexist with Raw SQL

**Supporting evidence**: Existing services use raw SQL for complex queries (visibility fragments, JSON operations, advisory locks, engine-specific syntax). The query builder cannot express all of these.

**Implication**: `Raw` escape hatch is mandatory. The query builder should handle 80% of simple CRUD; complex queries remain raw SQL in repositories. The `whereRaw()`, `setRaw()` methods are not code smells — they're the pragmatic bridge.

### Insight 6: Type System is the Linchpin

**Supporting evidence**: schema-management §1 defines 20+ abstract types; multi-engine §1 shows each maps differently across 3 engines. The type system feeds into DDL generation, introspection comparison, and diff detection.

**Implication**: The `TypeRegistry` must be the single source of truth for type mapping. It's consumed by: (1) SchemaCompiler (validate YAML types), (2) DdlGenerator (render SQL types), (3) SchemaIntrospector (reverse-map DB types to abstract types), (4) SchemaDiffer (compare column types). Getting this wrong breaks the entire pipeline.

---

## 4. Relationships and Dependencies

### Component Dependency Graph

```
ConnectionConfig → Connection → TransactionManager
                             → AdvisoryLock
                             → QueryFactory → [Select|Insert|Update|Delete]Query → Compiler
                             → SchemaIntrospector (per-engine)

YAML file → SchemaCompiler → SchemaDefinition
                                    ↓
SchemaDefinition + Introspected → SchemaDiffer → DiffOperation[]
                                                       ↓
                                    DdlGenerator (per-engine) → SQL[]
                                                                  ↓
                                                  SchemaExecutor → DB

TypeRegistry ← used by SchemaCompiler, DdlGenerator, SchemaIntrospector

Plugin System → SchemaEngine (facade) → uses SchemaCompiler + SchemaDiffer + DdlGenerator + SchemaExecutor
Core Install  → same pipeline
Core Upgrade  → same pipeline
```

### Data Flow: Request Lifecycle

```
HTTP Request → Symfony DI resolves ConnectionInterface (lazy, no connect yet)
            → Repository::find() calls $this->db->prepare() → lazy connect triggers
            → PDO query executes
            → Entity hydrated
            → Service logic
            → Transaction commit
            → Events dispatched
            → Connection::disconnect() at request end (kernel listener)
```

### Data Flow: Plugin Install

```
Admin clicks "Install Plugin"
→ PluginManager::install()
  → SchemaEngine::apply(schema.yml path)
    → SchemaCompiler::compile(YAML) → SchemaDefinition
    → SchemaDiffer::diff(SchemaDefinition, empty) → [CreateTable, ...]
    → DdlGenerator::generate(op) → SQL[] (per operation)
    → SchemaExecutor::execute(SQL[]) → runs against DB
    → Store schema snapshot in phpbb_plugins.state
```

---

## 5. Gaps and Uncertainties

### Information Gaps

| Gap | Impact | Resolution Path |
|-----|--------|-----------------|
| **Column rename detection** in diff | Medium — false positive drop+add | Require explicit `renames:` map in YAML for cross-version changes |
| **SQLite table rebuild edge cases** | Medium — may miss triggers, views | Test thoroughly during implementation; may defer SQLite ALTER support |
| **JSON path abstraction in query builder** | Low — services use raw JSON SQL now | Provide engine-specific JSON expression helpers as separate utility, not in core QB |
| **Reconnection error codes** | Low — affects CLI only | Test with MySQL 8 / PG 14 during implementation |
| **Concurrent migration safety** | Low for phpBB (single admin) | Use advisory lock around schema operations; document "one admin at a time" |

### Unresolved Design Questions

1. **Should the query builder auto-prefix tables?** (Compiler knows prefix → could auto-prefix any table name matching pattern)
2. **Should ConnectionInterface extend PDO or wrap it?** (Wrapping is cleaner but breaks code that type-hints `\PDO`)
3. **Should `Raw::now()` compile per-engine or use Unix timestamp?** (phpBB convention is Unix timestamps, not DB-native datetime)
4. **How to handle SQLite DECIMAL precision loss?** (Store as INTEGER cents? TEXT? Document the limitation?)

---

## 6. Service Boundary Definition

### IN the Database Service (`phpbb\database\*`)

| Component | Responsibility |
|-----------|---------------|
| `Connection` | Lazy PDO wrapper, lifecycle management |
| `ConnectionConfig` | DSN building, driver options, init statements |
| `TransactionManager` | Begin/commit/rollback with savepoint nesting |
| `AdvisoryLock` | Named advisory locks (per-engine implementation) |
| `QueryFactory` + builders | Fluent query construction + compilation |
| `Compiler\*` | Per-engine SQL generation for queries |
| `Schema\SchemaCompiler` | YAML → SchemaDefinition model |
| `Schema\SchemaIntrospector` | DB → introspected model (per-engine) |
| `Schema\SchemaDiffer` | SchemaDefinition × introspected → DiffOperation[] |
| `Schema\DdlGenerator` | DiffOperation → SQL (per-engine) |
| `Schema\SchemaExecutor` | Execute DDL with safety, dry-run, progress |
| `Schema\TypeRegistry` | Abstract type ↔ engine-specific type mapping |
| `Migration\MigrationRunner` | Execute data migrations (PHP classes) |
| `Migration\MigrationTracker` | Track applied migrations in `phpbb_schema_migrations` |

### NOT in the Database Service (owned by individual services)

| Component | Owner |
|-----------|-------|
| Repository interfaces | Each domain service (`phpbb\threads\contract\*`) |
| Repository implementations | Each domain service (`phpbb\threads\repository\*`) |
| Entity classes | Each domain service |
| Hydration logic | Each repository |
| Domain-specific SQL | Each repository (visibility fragments, tree math, etc.) |
| Schema YAML files | Each service + plugin authors |
| Counter logic | Each service's CounterService |
| Cache invalidation | Each service's event listeners |

---

## 7. Conclusions

### Primary Conclusions

1. **The database service is a thin, layered infrastructure provider** — not an ORM, not a full abstraction. It provides: connection, transactions, locks, query building, and schema management.

2. **Schema management is the most complex and highest-value subsystem.** The YAML→DDL pipeline with multi-engine support and safe diffing is the core differentiator. It serves both core phpBB installation and the plugin system.

3. **The query builder is optional value-add.** Existing services work fine with raw SQL. The QB benefits new development, plugin authors, and common patterns (IN clauses, pagination, upserts). It should NOT be forced on existing service designs.

4. **Multi-engine support is achievable via Strategy pattern** at three levels: DdlGenerator, QueryCompiler, SchemaIntrospector. Each engine gets its own implementation class behind a shared interface.

5. **SQLite support should be scoped as dev/test only** for MVP. Full ALTER TABLE support on SQLite (table rebuild) adds significant complexity and can be deferred.

### Recommendations

1. **Phase implementation**: Connection+Transaction → Schema Pipeline → Query Builder
2. **Start with MySQL + PostgreSQL DDL generators**; add SQLite DdlGenerator when tests need it
3. **Type system first**: build and validate TypeRegistry before other schema components
4. **Keep the query builder minimal for MVP**: SELECT/INSERT/UPDATE/DELETE with basic WHERE, JOIN, ORDER, LIMIT. Defer window functions, CTEs, lateral joins.
5. **Test schema pipeline against real MySQL 8 and PostgreSQL 14** early — introspection edge cases are numerous
