# Research Sources

## Category 1: Internal Patterns

### Project HLDs (Database Usage Conventions)

| Source | Path | What to Extract |
|--------|------|-----------------|
| Threads Service HLD | `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md` | Repository interfaces, PDO injection, two-phase pagination, entity hydration, counter atomics, transaction handling |
| Hierarchy Service HLD | `.maister/tasks/research/2026-04-18-hierarchy-service/outputs/high-level-design.md` | ForumRepository pattern, nested set SQL, advisory locking, PDO prepare/execute |
| Users Service HLD | `.maister/tasks/research/2026-04-19-users-service/outputs/high-level-design.md` | JSON columns, schema changes (ALTER TABLE), profile fields extensibility |
| Plugin System HLD | `.maister/tasks/research/2026-04-19-plugin-system/outputs/high-level-design.md` | Schema System section (lines 549-850): SchemaCompiler, SchemaDiffer, DdlGenerator, SchemaExecutor |

### Key Patterns Already Established

- **Repository Pattern**: All services use `*RepositoryInterface` with PDO implementation
- **PDO Injection**: `private readonly \PDO $db` via constructor DI
- **Entity Hydration**: Repository methods return typed entity objects (readonly classes)
- **Two-Phase Pagination**: SELECT IDs first → SELECT full rows for those IDs
- **Events After Commit**: Domain events dispatched after transaction commits
- **Naming**: `phpbb_` table prefix, snake_case columns, `int unsigned` for IDs

---

## Category 2: Schema Management

### Plugin System Schema Section

| Source | Lines | Content |
|--------|-------|---------|
| Schema YAML format | Plugin HLD §Schema System | `schema.yml` structure with tables, columns, indexes |
| Column type mapping | Plugin HLD §Supported Column Types | uint/int/bigint/bool/varchar/text/decimal/datetime/json |
| SchemaCompiler | Plugin HLD §SchemaCompiler | Parses YAML → SchemaDefinition (TableDefinition, ColumnDefinition) |
| SchemaDiffer | Plugin HLD §SchemaDiffer | Produces DiffOperation objects (CreateTable, AddColumn, ModifyColumn, etc.) |
| DdlGenerator | Plugin HLD §DdlGenerator | Interface + MySQL/PostgreSQL implementations |
| SchemaExecutor | Plugin HLD §SchemaExecutor | Runs DDL, step-based for large ops |
| Data Migrations | Plugin HLD §Data Migrations | PHP migration classes for data transforms |

### External References — Schema Diffing

| Source | URL / Package | What to Extract |
|--------|--------------|-----------------|
| Doctrine DBAL Schema | `doctrine/dbal` (Schema\Comparator) | Schema comparison algorithm, AbstractSchemaManager, platform-specific type mapping |
| Doctrine Migrations | `doctrine/migrations` | Version tracking, up/down pattern, dependency resolution |
| Phinx | `robmorgan/phinx` | Table/Column abstraction, change tracking, adapter pattern per DB |
| Laravel Schema Builder | `illuminate/database` (Schema\Blueprint) | Fluent column definition API, migration runner |
| Cycle Database | `cycle/database` | Schema introspection, declarative schema comparison |

---

## Category 3: Multi-Engine

### Engine Documentation

| Engine | Reference | Key Topics |
|--------|-----------|------------|
| MySQL 8.0+ | dev.mysql.com/doc/refman/8.0 | CREATE TABLE syntax, data types, INFORMATION_SCHEMA tables, AUTO_INCREMENT, UNSIGNED, JSON type, generated columns |
| PostgreSQL 14+ | postgresql.org/docs/14 | CREATE TABLE syntax, data types, pg_catalog / information_schema, SERIAL/IDENTITY, CHECK constraints, JSONB |
| SQLite | sqlite.org/lang_createtable.html | Type affinity system, sqlite_master, AUTOINCREMENT, limitations (no ALTER DROP COLUMN pre-3.35) |

### Cross-Engine Differences to Document

- Integer types: UNSIGNED (MySQL only) vs CHECK constraint (PG) vs no enforcement (SQLite)
- Auto-increment: AUTO_INCREMENT vs GENERATED ALWAYS AS IDENTITY vs AUTOINCREMENT
- Boolean: TINYINT(1) vs BOOLEAN vs INTEGER
- Text: MEDIUMTEXT/LONGTEXT vs TEXT vs TEXT
- JSON: JSON vs JSONB vs TEXT (SQLite)
- Index syntax differences
- ALTER TABLE capabilities (SQLite limitations)
- Introspection queries (INFORMATION_SCHEMA vs pg_catalog vs sqlite_master/pragma)

### Existing Type Mapping (from Plugin HLD)

| YAML Type | MySQL | PostgreSQL | SQLite (inferred) |
|-----------|-------|------------|-------------------|
| `uint` | `INT UNSIGNED` | `INTEGER CHECK (col >= 0)` | `INTEGER` |
| `int` | `INT` | `INTEGER` | `INTEGER` |
| `bigint` | `BIGINT` | `BIGINT` | `INTEGER` |
| `bool` | `TINYINT(1)` | `BOOLEAN` | `INTEGER` |
| `varchar` | `VARCHAR(length)` | `VARCHAR(length)` | `TEXT` |
| `text` | `MEDIUMTEXT` | `TEXT` | `TEXT` |
| `decimal` | `DECIMAL(p,s)` | `DECIMAL(p,s)` | `REAL` |
| `datetime` | `INT` (unix ts) | `INTEGER` | `INTEGER` |
| `json` | `JSON` | `JSONB` | `TEXT` |

---

## Category 4: Query Builder

### External References

| Source | Package | What to Extract |
|--------|---------|-----------------|
| Doctrine DBAL QueryBuilder | `doctrine/dbal` | Fluent SELECT/INSERT/UPDATE/DELETE, expression builder, parameter types, join API |
| Laravel Query Builder | `illuminate/database` | Fluent API, where clauses, raw expressions, chunking, aggregate methods |
| CakePHP Database | `cakephp/database` | Query objects, type casting, result decorators |
| Cycle DBAL | `cycle/database` | Query builder with fragment injection, compiler separation |
| Aura.SqlQuery | `aura/sqlquery` | Minimal query objects, no connection coupling |
| Atlas.Query | `atlas/query` | Composition-based query building, bind tracking |

### Design Constraints (from existing services)

- Services currently use raw PDO `prepare()` / `execute()` with string SQL
- Two-phase pagination pattern needs: subquery or separate query execution
- Counter operations need: `UPDATE ... SET col = col + 1` (expression in SET)
- Visibility filtering needs: dynamically injected WHERE clause fragments
- Must support raw SQL escape hatch for complex queries (nested sets, CTEs)

---

## Category 5: Versioning & Connection

### Schema Versioning References

| Source | Approach | What to Extract |
|--------|----------|-----------------|
| Doctrine Migrations | Migration files (up/down) | Version table structure, execution tracking, dependency ordering |
| Phinx | Migration files + rollback | Change detection, reversible migrations, breakpoints |
| Laravel Migrations | Migration files + batch | Batch tracking, rollback by batch, fresh/refresh commands |
| Flyway (concept) | Versioned SQL files | Naming convention, checksum validation, baseline concept |
| Plugin HLD (chosen approach) | Declarative diff (no migration files) | Snapshot comparison: declared YAML vs current DB state |

### Connection Management References

| Source | Pattern | What to Extract |
|--------|---------|-----------------|
| Doctrine DBAL Connection | Connection wrapper over PDO | Lazy connect, reconnection, nested transactions (savepoints) |
| Laravel DatabaseManager | Connection factory + resolver | Named connections, read/write split, sticky connections |
| Cycle Database | DatabaseManager + Driver | Driver abstraction, connection pool, profiling hooks |
| PDO native | Direct PDO | setAttribute options, error modes, persistent connections |

### Transaction Patterns

| Pattern | Used By | Notes |
|---------|---------|-------|
| Single transaction per request | Threads (event dispatch after commit) | Simple, fits phpBB's request model |
| Savepoints for nested transactions | Hierarchy (nested set operations) | Needed for complex multi-step operations |
| DDL auto-commit | Schema execution | MySQL auto-commits on DDL; need workaround for atomic multi-table changes |

---

## Cross-Cutting: Integration Points

### Plugin System → Database Service Dependency

The plugin system's `SchemaEngine` (SchemaCompiler, SchemaDiffer, DdlGenerator, SchemaExecutor) sits **above** the database service. The database service must provide:

1. **Connection** — PDO instance or wrapper for SchemaExecutor
2. **Introspection API** — For SchemaIntrospector to read current DB state
3. **DDL Generation** — Platform-specific DDL from abstract operations (DdlGenerator uses this)
4. **Type System** — Shared abstract type definitions (both schema YAML and query builder use them)
5. **Transaction API** — For SchemaExecutor to wrap DDL atomically

### Service Layer → Database Service Dependency

All repositories (TopicRepository, ForumRepository, UserRepository, etc.) need:

1. **Connection** — PDO instance (currently injected directly)
2. **Query Builder** (optional) — Typed query construction instead of raw strings
3. **Transaction API** — Begin/commit/rollback with savepoint support
4. **Table Prefix** — `phpbb_` prefix resolution

---

## File Inventory Summary

| Category | Internal Sources | External References |
|----------|-----------------|---------------------|
| Internal Patterns | 4 HLD documents | — |
| Schema Management | 1 HLD section | 5 packages/tools |
| Multi-Engine | — | 3 engine doc sets |
| Query Builder | 4 existing service patterns | 6 packages |
| Versioning & Connection | 1 HLD section | 4 packages + PDO docs |
| **Total** | **5 project documents** | **15+ external references** |
