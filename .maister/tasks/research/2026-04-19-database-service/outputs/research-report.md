# Database Service — Research Report

**Research Type**: Mixed (Technical Architecture + Literature)
**Date**: 2026-04-19
**Scope**: MySQL 8+, PostgreSQL 14+, SQLite · PHP 8.2+ · PDO · No ORM

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Service Architecture Overview](#2-service-architecture-overview)
3. [Component Catalog](#3-component-catalog)
4. [YAML Schema Format Specification](#4-yaml-schema-format-specification)
5. [Multi-Engine Type System](#5-multi-engine-type-system)
6. [Schema Diff & DDL Generation Pipeline](#6-schema-diff--ddl-generation-pipeline)
7. [Schema Versioning Strategy](#7-schema-versioning-strategy)
8. [Query Builder API Design](#8-query-builder-api-design)
9. [Connection & Transaction Management](#9-connection--transaction-management)
10. [Plugin System Integration](#10-plugin-system-integration)
11. [Design Decisions Required](#11-design-decisions-required)
12. [Risk Analysis](#12-risk-analysis)
13. [Recommendations](#13-recommendations)
14. [MVP Scope](#14-mvp-scope)

---

## 1. Executive Summary

The database service is a **layered infrastructure foundation** for the phpBB rebuild, providing connection management, transaction control, an optional query builder, and a complete YAML→DDL schema management pipeline with multi-engine support. Cross-referencing 8 existing domain service designs reveals unanimous use of raw PDO with prepared statements, manual entity hydration, and repository pattern with `array $data` writes — the DB service must preserve this pattern while adding schema lifecycle tooling. The service decomposes into four layers: **Connection** (lazy PDO, transactions, advisory locks), **Query Builder** (optional fluent API with per-engine Compiler), **Schema Management** (YAML compilation, introspection, diffing, DDL generation), and **Versioning** (migration tracking, snapshot-based diff). The schema pipeline is the highest-complexity subsystem served by Strategy pattern — one DdlGenerator, SchemaIntrospector, and QueryCompiler per engine. Key design constraints: no ORM, PDO-based, Symfony DI, single shared connection per request, events after commit.

---

## 2. Service Architecture Overview

### Layered Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                        CONSUMERS                                     │
│  ┌──────────────┐  ┌──────────────┐  ┌───────────────┐             │
│  │  Domain       │  │  Plugin      │  │  CLI Tools    │             │
│  │  Repositories │  │  System      │  │  (install,    │             │
│  │  (8 services) │  │  SchemaEngine│  │   upgrade)    │             │
│  └──────┬───────┘  └──────┬───────┘  └───────┬───────┘             │
│         │                  │                   │                     │
├─────────┼──────────────────┼───────────────────┼─────────────────────┤
│         ▼                  ▼                   ▼                     │
│  Layer 4: SCHEMA MANAGEMENT (lifecycle-only, never at runtime)      │
│  ┌─────────────┐ ┌──────────────┐ ┌────────────┐ ┌──────────────┐  │
│  │ Schema      │ │ Schema       │ │ Schema     │ │ DDL          │  │
│  │ Compiler    │ │ Introspector │ │ Differ     │ │ Generator    │  │
│  │ (YAML→Model)│ │ (DB→Model)   │ │ (Δ detect) │ │ (per engine) │  │
│  └──────┬──────┘ └──────┬───────┘ └─────┬──────┘ └──────┬───────┘  │
│         │               │               │               │           │
│         └───────────────┴───────┬───────┴───────────────┘           │
│                                 ▼                                    │
│  ┌─────────────────┐  ┌─────────────────┐                          │
│  │ Type Registry   │  │ Schema Executor │                          │
│  │ (type mapping)  │  │ (DDL runner)    │                          │
│  └─────────────────┘  └────────┬────────┘                          │
│                                │                                    │
├────────────────────────────────┼────────────────────────────────────┤
│         ▼                      │                                    │
│  Layer 3: VERSIONING           │                                    │
│  ┌─────────────────┐  ┌───────┴─────────┐                         │
│  │ Migration       │  │ Migration       │                          │
│  │ Runner          │  │ Tracker         │                          │
│  │ (data migration)│  │ (state tracking)│                          │
│  └─────────────────┘  └─────────────────┘                          │
│                                                                     │
├─────────────────────────────────────────────────────────────────────┤
│  Layer 2: QUERY BUILDER (optional, services may bypass)             │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐             │
│  │ QueryFactory  │  │ Select/Insert│  │ Compiler     │             │
│  │              │  │ Update/Delete│  │ (per engine) │             │
│  │              │  │ Query        │  │              │             │
│  └──────────────┘  └──────────────┘  └──────────────┘             │
│                                                                     │
├─────────────────────────────────────────────────────────────────────┤
│  Layer 1: CONNECTION (foundation, used by everything)               │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐             │
│  │ Connection   │  │ Transaction  │  │ Advisory     │             │
│  │ (lazy PDO)   │  │ Manager      │  │ Lock         │             │
│  └──────────────┘  └──────────────┘  └──────────────┘             │
│  ┌──────────────┐                                                  │
│  │ Connection   │                                                  │
│  │ Config       │                                                  │
│  └──────────────┘                                                  │
└─────────────────────────────────────────────────────────────────────┘
```

### Namespace Structure

```
phpbb\database\
├── Connection.php                    # Lazy PDO wrapper
├── ConnectionConfig.php              # DSN + options value object
├── ConnectionConfigFactory.php       # Builds config from phpbb config.php
├── ConnectionInterface.php           # Read+write interface
├── ReadConnectionInterface.php       # Read-only interface
├── TransactionManager.php            # Begin/commit/rollback + savepoints
├── TransactionManagerInterface.php
├── AdvisoryLock.php                  # Named advisory locks
├── AdvisoryLockInterface.php
├── query/
│   ├── QueryFactory.php              # Entry point: select(), insert(), etc.
│   ├── SelectQuery.php
│   ├── InsertQuery.php
│   ├── UpdateQuery.php
│   ├── DeleteQuery.php
│   ├── Raw.php                       # Raw SQL fragment
│   ├── CompiledQuery.php             # SQL + bindings result
│   ├── expression/
│   │   ├── Expression.php
│   │   └── ExpressionBuilder.php
│   └── compiler/
│       ├── Compiler.php              # Abstract base
│       ├── MySQLCompiler.php
│       ├── PostgreSQLCompiler.php
│       └── SQLiteCompiler.php
├── schema/
│   ├── SchemaCompiler.php            # YAML → SchemaDefinition
│   ├── SchemaIntrospector.php        # Interface
│   ├── SchemaDiffer.php              # Model × Model → DiffOperation[]
│   ├── SchemaExecutor.php            # Execute DDL safely
│   ├── TypeRegistry.php              # Abstract ↔ engine type mapping
│   ├── model/
│   │   ├── SchemaDefinition.php      # Immutable schema model
│   │   ├── TableDefinition.php
│   │   ├── ColumnDefinition.php
│   │   ├── IndexDefinition.php
│   │   └── ForeignKeyDefinition.php
│   ├── diff/
│   │   ├── DiffOperation.php         # Base class
│   │   ├── CreateTable.php
│   │   ├── DropTable.php
│   │   ├── AddColumn.php
│   │   ├── DropColumn.php
│   │   ├── ModifyColumn.php
│   │   ├── AddIndex.php
│   │   ├── DropIndex.php
│   │   ├── AddForeignKey.php
│   │   └── DropForeignKey.php
│   ├── ddl/
│   │   ├── DdlGeneratorInterface.php
│   │   ├── MySQLDdlGenerator.php
│   │   └── PostgreSQLDdlGenerator.php
│   └── introspector/
│       ├── SchemaIntrospectorInterface.php
│       ├── MySQLIntrospector.php
│       └── PostgreSQLIntrospector.php
└── migration/
    ├── MigrationRunner.php           # Execute PHP migration classes
    ├── MigrationTracker.php          # Track applied migrations
    └── AbstractMigration.php         # Base class for data migrations
```

---

## 3. Component Catalog

### Layer 1: Connection

| Component | Responsibility | Interface | Dependencies | MVP? |
|-----------|---------------|-----------|--------------|------|
| `ConnectionConfig` | Build DSN, options, init statements per engine | Value object (readonly) | None | ✅ |
| `ConnectionConfigFactory` | Create config from `config.php` | Static factory | `ConnectionConfig` | ✅ |
| `Connection` | Lazy PDO creation, lifecycle control | `ConnectionInterface`, `ReadConnectionInterface` | `ConnectionConfig` | ✅ |
| `TransactionManager` | Begin/commit/rollback with savepoint nesting | `TransactionManagerInterface` | `Connection` | ✅ |
| `AdvisoryLock` | Named application-level locks | `AdvisoryLockInterface` | `Connection` | ✅ |

#### Key Interface: ConnectionInterface

```php
namespace phpbb\database;

interface ReadConnectionInterface
{
    public function prepare(string $sql): \PDOStatement;
    public function query(string $sql): \PDOStatement;
    public function getTablePrefix(): string;
    public function getDriver(): string; // 'mysql', 'pgsql', 'sqlite'
}

interface ConnectionInterface extends ReadConnectionInterface
{
    public function exec(string $sql): int;
    public function lastInsertId(?string $name = null): string|false;
    public function getPdo(): \PDO; // escape hatch for advanced use
}
```

#### Key Interface: TransactionManagerInterface

```php
namespace phpbb\database;

interface TransactionManagerInterface
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;

    /** @template T @param callable(): T $callback @return T */
    public function transactional(callable $callback): mixed;
    public function getNestingLevel(): int;
}
```

#### Key Interface: AdvisoryLockInterface

```php
namespace phpbb\database;

interface AdvisoryLockInterface
{
    public function acquire(string $lockName, int $timeoutSeconds = 10): bool;
    public function release(string $lockName): void;
}
```

### Layer 2: Query Builder

| Component | Responsibility | Interface | Dependencies | MVP? |
|-----------|---------------|-----------|--------------|------|
| `QueryFactory` | Create query builders | `QueryFactoryInterface` | `Connection`, `Compiler` | ⚡ Nice-to-have |
| `SelectQuery` | Fluent SELECT construction | `SelectQueryInterface` | `Compiler` | ⚡ |
| `InsertQuery` | Fluent INSERT construction | `InsertQueryInterface` | `Compiler` | ⚡ |
| `UpdateQuery` | Fluent UPDATE construction | `UpdateQueryInterface` | `Compiler` | ⚡ |
| `DeleteQuery` | Fluent DELETE construction | `DeleteQueryInterface` | `Compiler` | ⚡ |
| `Compiler` (abstract) | Base SQL generation | Abstract class | — | ⚡ |
| `MySQLCompiler` | MySQL-specific SQL | Extends `Compiler` | — | ⚡ |
| `PostgreSQLCompiler` | PostgreSQL-specific SQL | Extends `Compiler` | — | ⚡ |
| `SQLiteCompiler` | SQLite-specific SQL | Extends `Compiler` | — | 🔜 Deferred |
| `ExpressionBuilder` | Complex WHERE conditions | `ExpressionBuilderInterface` | — | ⚡ |
| `Raw` | SQL escape hatch with bindings | Value object | — | ⚡ |

### Layer 3: Schema Management

| Component | Responsibility | Interface | Dependencies | MVP? |
|-----------|---------------|-----------|--------------|------|
| `TypeRegistry` | Map abstract types ↔ engine types | Class (singleton) | — | ✅ |
| `SchemaCompiler` | Parse YAML → `SchemaDefinition` | Class | `TypeRegistry`, Symfony YAML | ✅ |
| `SchemaDiffer` | Compute diff between two schemas | Class | — | ✅ |
| `SchemaExecutor` | Run DDL with safety/dry-run | Class | `Connection`, `DdlGenerator` | ✅ |
| `DdlGeneratorInterface` | Convert DiffOperation → SQL | Interface | `TypeRegistry` | ✅ |
| `MySQLDdlGenerator` | MySQL DDL generation | Implements interface | `TypeRegistry` | ✅ |
| `PostgreSQLDdlGenerator` | PostgreSQL DDL generation | Implements interface | `TypeRegistry` | ✅ |
| `SQLiteDdlGenerator` | SQLite DDL generation | Implements interface | `TypeRegistry` | 🔜 Deferred |
| `SchemaIntrospectorInterface` | Read current DB schema | Interface | `Connection` | ✅ |
| `MySQLIntrospector` | MySQL schema reading | Implements interface | `Connection` | ✅ |
| `PostgreSQLIntrospector` | PostgreSQL schema reading | Implements interface | `Connection` | ✅ |
| `SchemaDefinition` | Immutable in-memory schema model | Value object | — | ✅ |
| `DiffOperation` (set) | Diff operation value objects | Value objects | — | ✅ |

### Layer 4: Versioning

| Component | Responsibility | Interface | Dependencies | MVP? |
|-----------|---------------|-----------|--------------|------|
| `MigrationRunner` | Execute PHP migration classes in order | Class | `Connection`, `MigrationTracker` | ✅ |
| `MigrationTracker` | Track applied migrations in `phpbb_schema_migrations` | Class | `Connection` | ✅ |
| `AbstractMigration` | Base class for imperative data migrations | Abstract class | `Connection` | ✅ |

---

## 4. YAML Schema Format Specification

### Full Format

```yaml
tables:
    poll_options:                          # table name (auto-prefixed with phpbb_)
        columns:
            option_id:
                type: uint                # abstract type (see §5)
                auto_increment: true
            topic_id:
                type: uint
                nullable: false
                default: 0
            option_text:
                type: varchar
                length: 255
                default: ""
            vote_count:
                type: uint
                default: 0
            created_at:
                type: timestamp           # unix timestamp as INT
            metadata:
                type: json
                nullable: true

        primary_key: [option_id]

        indexes:
            idx_topic: [topic_id]
            idx_topic_votes: [topic_id, vote_count]

        unique_keys:
            uq_topic_text: [topic_id, option_text]

        foreign_keys:                      # optional; loose coupling preferred
            fk_topic:
                columns: [topic_id]
                references:
                    table: topics          # logical name (auto-prefixed)
                    columns: [topic_id]
                on_delete: CASCADE
                on_update: CASCADE

        options:                           # engine-specific, optional
            engine: InnoDB
            charset: utf8mb4
            collation: utf8mb4_unicode_ci
```

### Column Properties Reference

| Property | Type | Default | Notes |
|----------|------|---------|-------|
| `type` | string | **required** | Abstract type name (see §5) |
| `length` | int | varies | Required for `varchar`; optional for `char` |
| `precision` | int | 10 | For `decimal` type |
| `scale` | int | 0 | For `decimal` type |
| `nullable` | bool | `false` | Columns are NOT NULL by default |
| `default` | mixed | none | Default value; `null` allowed if nullable |
| `auto_increment` | bool | `false` | Only one per table |
| `unsigned` | bool | `false` | Implied by `uint`/`ubigint` types |
| `comment` | string | none | Column comment (stored in DDL where supported) |

### Index Properties

```yaml
indexes:
    # Simple: array of columns
    idx_user: [user_id]

    # Composite: column order matters for leftmost-prefix optimization
    idx_topic_time: [topic_id, created_at]

    # With prefix length (MySQL only, ignored elsewhere):
    idx_title:
        columns: [title]
        length: { title: 100 }
```

### Conventions

- **Table names**: `snake_case`, plural (`topics`, `users`, `forums`)
- **Column names**: `snake_case` with entity prefix for PKs (`topic_id`, `user_id`)
- **FK columns**: Same name as referenced PK
- **Booleans**: `tinyint(1)` semantics, named descriptively (`is_active`, `topic_reported`)
- **Timestamps**: Unix timestamps as `timestamp` type (maps to INT), not DATETIME
- **Counters**: `uint`, `DEFAULT 0`
- **Index names**: `idx_` prefix + descriptive (`idx_forum_visibility`)
- **Unique key names**: `uq_` prefix
- **FK names**: `fk_` prefix

---

## 5. Multi-Engine Type System

### Abstract Type Mapping

| YAML Type | MySQL 8+ | PostgreSQL 14+ | SQLite | PHP Read Type |
|-----------|----------|----------------|--------|---------------|
| `smallint` | `SMALLINT` | `SMALLINT` | `INTEGER` | `int` |
| `int` | `INT` | `INTEGER` | `INTEGER` | `int` |
| `bigint` | `BIGINT` | `BIGINT` | `INTEGER` | `int\|string` |
| `uint` | `INT UNSIGNED` | `INTEGER CHECK(≥0)` | `INTEGER` | `int` |
| `ubigint` | `BIGINT UNSIGNED` | `BIGINT CHECK(≥0)` | `INTEGER` | `int\|string` |
| `serial` | `BIGINT UNSIGNED AUTO_INCREMENT` | `BIGINT GENERATED ALWAYS AS IDENTITY` | `INTEGER PRIMARY KEY` | `int` |
| `bool` | `TINYINT(1)` | `BOOLEAN` | `INTEGER` | `bool` |
| `varchar` | `VARCHAR(N)` | `VARCHAR(N)` | `TEXT` | `string` |
| `char` | `CHAR(N)` | `CHAR(N)` | `TEXT` | `string` |
| `text` | `MEDIUMTEXT` | `TEXT` | `TEXT` | `string` |
| `longtext` | `LONGTEXT` | `TEXT` | `TEXT` | `string` |
| `decimal` | `DECIMAL(P,S)` | `NUMERIC(P,S)` | `REAL` ⚠️ | `string` |
| `float` | `FLOAT` | `REAL` | `REAL` | `float` |
| `double` | `DOUBLE` | `DOUBLE PRECISION` | `REAL` | `float` |
| `timestamp` | `INT UNSIGNED` | `INTEGER` | `INTEGER` | `int` |
| `json` | `JSON` | `JSONB` | `TEXT` | `array` |
| `blob` | `LONGBLOB` | `BYTEA` | `BLOB` | `string` |
| `binary` | `BINARY(N)` | `BYTEA` | `BLOB` | `string` |
| `uuid` | `CHAR(36)` | `UUID` | `TEXT` | `string` |

⚠️ SQLite `REAL` for `decimal` is lossy — no exact decimal arithmetic. Document this limitation.

### TypeRegistry Interface

```php
namespace phpbb\database\schema;

final class TypeRegistry
{
    /**
     * Get the engine-specific SQL type for an abstract type.
     */
    public function getSqlType(string $abstractType, string $driver): string;

    /**
     * Reverse-map an engine-specific type to abstract type.
     * Used by SchemaIntrospector to normalize introspected columns.
     */
    public function getAbstractType(string $sqlType, string $driver): string;

    /**
     * Check if an abstract type name is valid.
     */
    public function isValidType(string $abstractType): bool;

    /**
     * Get the full column SQL declaration with modifiers.
     */
    public function getColumnDeclaration(
        ColumnDefinition $column,
        string $driver,
    ): string;
}
```

### Engine-Specific Considerations

| Feature | MySQL | PostgreSQL | SQLite | Abstraction Strategy |
|---------|-------|------------|--------|---------------------|
| `UNSIGNED` | Native keyword | CHECK constraint | Not enforced | DdlGenerator adds CHECK for PG |
| `AUTO_INCREMENT` | `AUTO_INCREMENT` | `GENERATED AS IDENTITY` | `INTEGER PRIMARY KEY` | `serial` abstract type |
| `BOOLEAN` | `TINYINT(1)` | Native `BOOLEAN` | `INTEGER` | Compiler normalizes TRUE/FALSE |
| `JSON` validated | Yes | Yes (JSONB) | No | App-level validation for SQLite |
| `VARCHAR` enforcement | Yes | Yes | No (TEXT affinity) | App-level for SQLite |
| `DECIMAL` exact | Yes | Yes | No (REAL) | Document limitation |

---

## 6. Schema Diff & DDL Generation Pipeline

### Pipeline Flow

```
                    ┌────────────────┐
                    │   schema.yml   │
                    └───────┬────────┘
                            │ parse
                            ▼
                    ┌────────────────┐
                    │ SchemaCompiler │ → validates types, resolves aliases
                    └───────┬────────┘
                            │
                            ▼
┌────────────────┐  ┌────────────────┐
│  Live Database │  │ SchemaDefinition│ (immutable in-memory model)
└───────┬────────┘  └───────┬────────┘
        │ introspect        │
        ▼                   │
┌────────────────┐          │
│SchemaIntrospector│         │
│(per-engine)    │          │
└───────┬────────┘          │
        │                   │
        ▼                   ▼
┌─────────────────────────────────┐
│         SchemaDiffer            │ → produces DiffOperation[]
│ diff(declared, current)         │
└───────────────┬─────────────────┘
                │
                ▼
┌─────────────────────────────────┐
│     DdlGenerator (per-engine)   │ → produces SQL string[]
│     generate(DiffOperation)     │
└───────────────┬─────────────────┘
                │
                ▼
┌─────────────────────────────────┐
│       SchemaExecutor            │ → executes with safety/dry-run
│       execute(SQL[])            │
└─────────────────────────────────┘
```

### DiffOperation Types

| Operation | Trigger | Destructive? | Auto-apply? |
|-----------|---------|-------------|-------------|
| `CreateTable` | Table in YAML, not in DB | No | ✅ Yes |
| `DropTable` | Table in DB, removed from YAML | **Yes** | ❌ Requires confirmation |
| `AddColumn` | Column in YAML, not in DB table | No | ✅ Yes |
| `DropColumn` | Column in DB, removed from YAML | **Yes** | ❌ Requires confirmation |
| `ModifyColumn` | Column in both, properties differ | Maybe | ⚠️ Widening = safe; narrowing = destructive |
| `AddIndex` | Index in YAML, not in DB | No | ✅ Yes |
| `DropIndex` | Index in DB, removed from YAML | No | ✅ Yes |
| `AddForeignKey` | FK in YAML, not in DB | Maybe | ⚠️ Pre-flight check for orphans |
| `DropForeignKey` | FK in DB, removed from YAML | No | ✅ Yes |
| `AddUniqueKey` | Unique key in YAML, not in DB | Maybe | ⚠️ Pre-flight check for duplicates |

### Operation Ordering

DDL must be ordered to respect dependencies:

```
Phase 1: Drop FKs           (unblock column/table drops)
Phase 2: Drop indexes        (unblock column drops)
Phase 3: Drop columns
Phase 4: Create tables       (topological sort by FK references)
Phase 5: Add columns
Phase 6: Modify columns
Phase 7: Add indexes
Phase 8: Add unique keys     (after data checks)
Phase 9: Add FKs             (after all tables/columns exist)
Phase 10: Drop tables        (reverse FK order, after FKs removed)
```

### DdlGenerator Interface

```php
namespace phpbb\database\schema\ddl;

interface DdlGeneratorInterface
{
    /** @return string[] SQL statements for this operation */
    public function generate(DiffOperation $operation): array;

    /** Get column type SQL fragment for a column definition */
    public function getColumnTypeSQL(ColumnDefinition $column): string;

    /** Get full column declaration (type + modifiers + constraints) */
    public function getColumnDeclarationSQL(ColumnDefinition $column): string;
}
```

### SchemaExecutor Safety

```php
namespace phpbb\database\schema;

final class SchemaExecutor
{
    /** Preview operations without executing */
    public function dryRun(SchemaDefinition $declared): DryRunResult;

    /** Execute operations. Destructive ops require $confirmed = true. */
    public function execute(
        SchemaDefinition $declared,
        bool $confirmed = false,
    ): ExecutionResult;
}

final readonly class DryRunResult
{
    public function __construct(
        /** @var DiffOperation[] */
        public array $operations,
        /** @var string[] Generated SQL */
        public array $sql,
        /** @var DiffOperation[] Destructive operations requiring confirmation */
        public array $destructiveOperations,
        public bool $requiresConfirmation,
    ) {}
}
```

### Pre-Flight Checks

Before potentially-failing operations:

```sql
-- Before AddUniqueKey: check for duplicates
SELECT col1, col2, COUNT(*) FROM table GROUP BY col1, col2 HAVING COUNT(*) > 1;

-- Before SET NOT NULL: check for existing NULLs
SELECT COUNT(*) FROM table WHERE col IS NULL;

-- Before AddForeignKey: check for orphan rows
SELECT COUNT(*) FROM child WHERE fk_col NOT IN (SELECT pk FROM parent);
```

---

## 7. Schema Versioning Strategy

### Core phpBB Versioning

| Aspect | Approach |
|--------|----------|
| **Version tracking** | Semantic version in `phpbb_config` (`schema_version` = `5.0.0`) |
| **Schema source** | Declarative YAML in codebase per version |
| **Diff computation** | Old version YAML vs new version YAML (deterministic, offline) |
| **Data migrations** | Imperative PHP classes for backfills, transforms, seeds |
| **Rollback** | Forward-only in production; down migrations for dev only |

### Plugin Versioning

| Aspect | Approach |
|--------|----------|
| **Version tracking** | Per-plugin in `phpbb_plugins.plugin_version` |
| **Schema snapshot** | Stored in `phpbb_plugins.state` JSON (already in Plugin HLD) |
| **Diff computation** | Old snapshot vs new `schema.yml` (hybrid snapshot + live fallback) |
| **Install** | Full `CreateTable` operations |
| **Update** | Diff old snapshot → new YAML → incremental DDL |
| **Uninstall** | `DropTable` (with confirmation) |

### Migration Tracking Table

```sql
CREATE TABLE phpbb_schema_migrations (
    migration_id    VARCHAR(255)    NOT NULL,   -- FQCN (e.g. phpbb\migration\v500\CreateForumsTable)
    version         VARCHAR(50)     NOT NULL,   -- semver group (5.0.0)
    applied_at      INT UNSIGNED    NOT NULL,   -- unix timestamp
    execution_ms    INT UNSIGNED    NOT NULL DEFAULT 0,
    checksum        VARCHAR(64)     DEFAULT NULL, -- SHA-256 of file (tamper detection)
    batch           INT UNSIGNED    NOT NULL DEFAULT 1,
    PRIMARY KEY (migration_id),
    INDEX idx_version (version),
    INDEX idx_batch (batch)
);
```

### Diff Strategy: Hybrid Snapshot + Live Introspection

| Scenario | Source | Reason |
|----------|--------|--------|
| Plugin update | Old snapshot from `state` JSON vs new `schema.yml` | Accurate without live DB at plan time |
| Fresh install | New YAML → all `CreateTable` | No old state |
| Uninstall | Snapshot → empty = all `DropTable` | Snapshot tells us what to remove |
| Drift detection | New YAML vs live introspection | Catches manual DB changes |
| Core upgrade | Old version YAML vs new version YAML | Deterministic, reproducible |

### Data Migration Base Class

```php
namespace phpbb\database\migration;

abstract class AbstractMigration
{
    public function __construct(
        protected readonly ConnectionInterface $db,
    ) {}

    /** Unique identifier (FQCN used by default) */
    public function getId(): string
    {
        return static::class;
    }

    /** Execute the migration */
    abstract public function up(): void;

    /** Reverse the migration (optional, dev only) */
    public function down(): void
    {
        throw new \LogicException('Down migration not implemented for ' . static::class);
    }
}
```

---

## 8. Query Builder API Design

### Design Principles

1. **Separate builder per query type** (SelectQuery, InsertQuery, etc.) — type safety
2. **Compiler per engine** (MySQLCompiler, PostgreSQLCompiler) — cleanest multi-engine approach
3. **Mutable builders** (industry standard) with `clone` support for branching
4. **Automatic parameter binding** — never interpolate values
5. **`Raw` escape hatch** — for SQL the builder can't express
6. **Table prefix handled by Compiler** — transparent to callers

### QueryFactory (Entry Point)

```php
namespace phpbb\database\query;

interface QueryFactoryInterface
{
    public function select(string|Raw ...$columns): SelectQuery;
    public function insert(string $table): InsertQuery;
    public function update(string $table, ?string $alias = null): UpdateQuery;
    public function delete(string $table): DeleteQuery;
    public function raw(string $sql, array $bindings = []): Raw;
    public function expr(): ExpressionBuilder;
}
```

### SelectQuery (Key Methods)

```php
interface SelectQuery
{
    // Core
    public function from(string|SelectQuery $table, ?string $alias = null): static;
    public function where(string|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static;
    public function orWhere(string|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static;
    public function whereIn(string $column, array|SelectQuery $values): static;
    public function whereNull(string $column): static;
    public function whereRaw(string $sql, array $bindings = []): static;

    // Joins
    public function join(string $table, string $alias, string|\Closure $condition): static;
    public function leftJoin(string $table, string $alias, string|\Closure $condition): static;

    // Ordering & pagination
    public function orderBy(string $column, string $direction = 'ASC'): static;
    public function limit(int $limit): static;
    public function offset(int $offset): static;

    // Aggregates (terminal)
    public function count(string $column = '*'): int;
    public function sum(string $column): int|float;

    // Results (terminal)
    public function fetchAll(): array;
    public function fetchOne(): ?array;
    /** @return \Generator<array> */
    public function cursor(): \Generator;

    // Debug
    public function toSQL(): string;
    public function getBindings(): array;
}
```

### UpdateQuery with Increment Support

```php
interface UpdateQuery
{
    public function set(string $column, mixed $value): static;
    public function setRaw(string $expression, array $bindings = []): static;
    public function increment(string $column, int|float $amount = 1): static;
    public function decrement(string $column, int|float $amount = 1): static;
    public function where(string|\Closure $column, mixed $operatorOrValue = null, mixed $value = null): static;
    public function execute(): int; // affected rows
}
```

### InsertQuery with Upsert

```php
interface InsertQuery
{
    public function row(array $data): static;
    public function rows(array $rows): static;
    public function onConflict(array $columns): static;
    public function doUpdate(array $columns): static;
    public function doNothing(): static;
    public function execute(): int; // last insert ID or affected rows
}
```

### Compiler Contract

```php
namespace phpbb\database\query\compiler;

abstract class Compiler
{
    public function __construct(
        protected readonly string $tablePrefix,
    ) {}

    abstract protected function quoteIdentifier(string $name): string;
    abstract protected function compileLimit(int $limit, int $offset): string;
    abstract protected function compileBooleanValue(bool $value): string;
    abstract protected function compileUpsert(InsertQuery $query): CompiledQuery;

    public function compileSelect(SelectQuery $query): CompiledQuery { /* ... */ }
    public function compileInsert(InsertQuery $query): CompiledQuery { /* ... */ }
    public function compileUpdate(UpdateQuery $query): CompiledQuery { /* ... */ }
    public function compileDelete(DeleteQuery $query): CompiledQuery { /* ... */ }
}
```

**Engine-specific Compilation Examples:**

| Feature | MySQL | PostgreSQL | SQLite |
|---------|-------|------------|--------|
| Identifier quoting | `` `name` `` | `"name"` | `"name"` |
| Boolean | `1` / `0` | `TRUE` / `FALSE` | `1` / `0` |
| UPSERT | `ON DUPLICATE KEY UPDATE` | `ON CONFLICT DO UPDATE` | `ON CONFLICT DO UPDATE` |
| CONCAT | `CONCAT(a, b)` | `a \|\| b` | `a \|\| b` |
| LIMIT | `LIMIT N OFFSET M` | `LIMIT N OFFSET M` | `LIMIT N OFFSET M` |

### Usage by Repository

```php
// Repositories can use EITHER raw SQL or query builder — both work

// Raw SQL (existing pattern, unchanged):
$stmt = $this->db->prepare(
    "SELECT * FROM {$this->db->getTablePrefix()}topics WHERE topic_id = ?"
);
$stmt->execute([$topicId]);

// Query builder (new option):
$row = $this->qf->select('*')
    ->from('topics')  // prefix applied by Compiler
    ->where('topic_id', $topicId)
    ->fetchOne();
```

---

## 9. Connection & Transaction Management

### Connection Lifecycle

```
Request Start → DI resolves Connection (no connect yet — lazy)
             → First $db->prepare() → PDO created, init statements run
             → All repositories share same PDO instance
             → Request End → $db->disconnect() via kernel listener
```

### Connection Implementation

```php
namespace phpbb\database;

final class Connection implements ConnectionInterface
{
    private ?\PDO $pdo = null;

    public function __construct(
        private readonly ConnectionConfig $config,
    ) {}

    public function getPdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new \PDO(
                $this->config->getDsn(),
                $this->config->getUsername(),
                $this->config->getPassword(),
                $this->config->getOptions(),
            );
            foreach ($this->config->getInitStatements() as $stmt) {
                $this->pdo->exec($stmt);
            }
        }
        return $this->pdo;
    }

    public function prepare(string $sql): \PDOStatement
    {
        return $this->getPdo()->prepare($sql);
    }

    public function exec(string $sql): int
    {
        return $this->getPdo()->exec($sql);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->getPdo()->lastInsertId($name);
    }

    public function getTablePrefix(): string
    {
        return $this->config->getTablePrefix();
    }

    public function getDriver(): string
    {
        return $this->config->getDriver();
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }

    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }
}
```

### Transaction Manager with Savepoints

```php
namespace phpbb\database;

final class TransactionManager implements TransactionManagerInterface
{
    private int $nestingLevel = 0;

    public function __construct(
        private readonly Connection $connection,
    ) {}

    public function begin(): void
    {
        $pdo = $this->connection->getPdo();
        if ($this->nestingLevel === 0) {
            $pdo->beginTransaction();
        } else {
            $pdo->exec('SAVEPOINT sp_' . $this->nestingLevel);
        }
        $this->nestingLevel++;
    }

    public function commit(): void
    {
        if ($this->nestingLevel === 0) {
            throw new \LogicException('No active transaction to commit.');
        }
        $this->nestingLevel--;
        $pdo = $this->connection->getPdo();
        if ($this->nestingLevel === 0) {
            $pdo->commit();
        } else {
            $pdo->exec('RELEASE SAVEPOINT sp_' . $this->nestingLevel);
        }
    }

    public function rollback(): void
    {
        if ($this->nestingLevel === 0) {
            throw new \LogicException('No active transaction to rollback.');
        }
        $this->nestingLevel--;
        $pdo = $this->connection->getPdo();
        if ($this->nestingLevel === 0) {
            $pdo->rollBack();
        } else {
            $pdo->exec('ROLLBACK TO SAVEPOINT sp_' . $this->nestingLevel);
        }
    }

    public function transactional(callable $callback): mixed
    {
        $this->begin();
        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function getNestingLevel(): int
    {
        return $this->nestingLevel;
    }
}
```

### Engine Init Statements

| Engine | Init Statements | Purpose |
|--------|----------------|---------|
| MySQL | `SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci'` | Charset |
| MySQL | `SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,...'` | Strict mode |
| PostgreSQL | `SET client_encoding = 'UTF8'` | Charset |
| PostgreSQL | `SET timezone = 'UTC'` | Timezone |
| SQLite | `PRAGMA journal_mode = WAL` | Concurrent readers |
| SQLite | `PRAGMA foreign_keys = ON` | FK enforcement |
| SQLite | `PRAGMA busy_timeout = 5000` | Lock wait |

### DI Wiring (services.yml)

```yaml
services:
    phpbb\database\ConnectionConfig:
        factory: ['phpbb\database\ConnectionConfigFactory', 'fromPhpbbConfig']
        arguments: ['%phpbb.db_config%']

    phpbb\database\Connection:
        arguments: ['@phpbb\database\ConnectionConfig']

    phpbb\database\ConnectionInterface:
        alias: phpbb\database\Connection

    phpbb\database\ReadConnectionInterface:
        alias: phpbb\database\Connection

    phpbb\database\TransactionManager:
        arguments: ['@phpbb\database\Connection']

    phpbb\database\TransactionManagerInterface:
        alias: phpbb\database\TransactionManager

    phpbb\database\AdvisoryLock:
        arguments: ['@phpbb\database\Connection']

    phpbb\database\AdvisoryLockInterface:
        alias: phpbb\database\AdvisoryLock
```

---

## 10. Plugin System Integration

### How Plugin System Consumes DB Service

The plugin system's `SchemaEngine` is a **facade** that orchestrates DB service components:

```php
namespace phpbb\plugin;

final class SchemaEngine
{
    public function __construct(
        private readonly \phpbb\database\schema\SchemaCompiler $compiler,
        private readonly \phpbb\database\schema\SchemaIntrospectorInterface $introspector,
        private readonly \phpbb\database\schema\SchemaDiffer $differ,
        private readonly \phpbb\database\schema\ddl\DdlGeneratorInterface $ddlGenerator,
        private readonly \phpbb\database\schema\SchemaExecutor $executor,
    ) {}

    public function install(string $schemaYamlPath): ExecutionResult
    {
        $declared = $this->compiler->compile($schemaYamlPath);
        $operations = $this->differ->diff($declared, SchemaDefinition::empty());
        return $this->executor->execute($declared);
    }

    public function update(string $schemaYamlPath, SchemaDefinition $oldSnapshot): ExecutionResult
    {
        $declared = $this->compiler->compile($schemaYamlPath);
        $operations = $this->differ->diff($declared, $oldSnapshot);
        // ... generate DDL, execute
    }

    public function uninstall(SchemaDefinition $snapshot): ExecutionResult
    {
        $operations = $this->differ->diff(SchemaDefinition::empty(), $snapshot);
        // ... generate DropTable operations, execute with confirmation
    }

    public function dryRun(string $schemaYamlPath): DryRunResult
    {
        $declared = $this->compiler->compile($schemaYamlPath);
        return $this->executor->dryRun($declared);
    }
}
```

### MetadataAccessor (Plugin JSON Operations)

The `MetadataAccessor` in the plugin system directly uses `ConnectionInterface` for JSON operations — these are too engine-specific for the query builder:

```php
// MySQL
"UPDATE {$prefix}topics SET metadata = JSON_SET(COALESCE(metadata, '{}'), ?, CAST(? AS JSON)) WHERE topic_id = ?"

// PostgreSQL
"UPDATE {$prefix}topics SET metadata = jsonb_set(COALESCE(metadata, '{}'), ?::text[], ?::jsonb) WHERE topic_id = ?"
```

This is handled by engine-specific SQL in the `MetadataAccessor`, not by the DB service's query builder. The DB service provides `getDriver()` for engine detection.

### DI Auto-Wiring of Engine-Specific Implementations

```yaml
# Factory resolves engine-specific implementation based on driver
services:
    phpbb\database\schema\ddl\DdlGeneratorInterface:
        factory: ['phpbb\database\schema\ddl\DdlGeneratorFactory', 'create']
        arguments: ['@phpbb\database\Connection']

    phpbb\database\schema\SchemaIntrospectorInterface:
        factory: ['phpbb\database\schema\introspector\IntrospectorFactory', 'create']
        arguments: ['@phpbb\database\Connection']

    phpbb\database\query\compiler\Compiler:
        factory: ['phpbb\database\query\compiler\CompilerFactory', 'create']
        arguments: ['@phpbb\database\Connection']
```

```php
// Factory pattern for engine selection
final class DdlGeneratorFactory
{
    public static function create(ConnectionInterface $conn): DdlGeneratorInterface
    {
        return match ($conn->getDriver()) {
            'mysql' => new MySQLDdlGenerator($conn->getTablePrefix()),
            'pgsql' => new PostgreSQLDdlGenerator($conn->getTablePrefix()),
            'sqlite' => new SQLiteDdlGenerator($conn->getTablePrefix()),
            default => throw new UnsupportedPlatformException($conn->getDriver()),
        };
    }
}
```

---

## 11. Design Decisions Required

| # | Decision | Options | Trade-offs | Recommendation |
|---|----------|---------|------------|----------------|
| 1 | **ConnectionInterface vs raw \PDO** | A) Wrap PDO in interface B) Extend PDO C) Pass-through both | A: Clean abstraction, testable, lazy; adds indirection. B: Breaks DI. C: Dual APIs = confusion. | **A: Wrap in interface** — testability + lazy connection justify the thin wrapper |
| 2 | **Query builder: immutable vs mutable** | A) Immutable (each method returns new instance) B) Mutable (`$this` return) | A: Safe but verbose + GC pressure. B: Industry standard, ergonomic, risk of accidental mutation. | **B: Mutable + clone support** — matches all major frameworks |
| 3 | **Table prefix in query builder** | A) Compiler auto-prefixes all table names B) Caller includes prefix C) Compiler prefixes only unprefixed names | A: Clean, may break if table name collides. B: Verbose. C: Complex detection logic. | **A: Compiler auto-prefixes** — query builder always works with logical names |
| 4 | **YAML parser** | A) Symfony YAML component B) Custom parser C) PHP array config | A: Standard, already a dependency. B: Unnecessary. C: Loses declarative benefits. | **A: Symfony YAML** — already in vendor |
| 5 | **`timestamp` type mapping** | A) Unix INT (project convention) B) Native DATETIME/TIMESTAMPTZ C) Both as separate types | A: Portable, consistent with existing services. B: Better DB tooling support. C: Flexibility. | **A: Unix INT** — matches all existing service designs |
| 6 | **Schema diff approach** | A) Snapshot-only B) Live introspection only C) Hybrid snapshot + live fallback | A: Offline but may drift. B: Always accurate but requires connection. C: Best of both. | **C: Hybrid** — snapshot for normal ops, introspection for drift detection |
| 7 | **SQLite ALTER limitations** | A) Full table rebuild support B) Limit SQLite to CREATE only C) Defer SQLite ALTER to post-MVP | A: Complex but complete. B: Limits dev testing. C: Pragmatic. | **C: Defer** — MVP supports CREATE TABLE on SQLite; ALTER via rebuild added later |
| 8 | **Foreign keys in YAML** | A) Full FK support B) Loose coupling only (no FK constraints) C) Optional FK section | A: Referential integrity. B: Simpler, matches plugin convention. C: Flexibility. | **C: Optional** — FKs declared in YAML for core tables; plugins use loose coupling (events for cascade) |

---

## 12. Risk Analysis

| Risk | Probability | Impact | Mitigation |
|------|------------|--------|------------|
| Schema introspection returns different results across engine versions | Medium | High — diff produces wrong operations | Pin minimum versions (MySQL 8.0+, PG 14+); test introspection against real engines in CI |
| SQLite table rebuild drops triggers/views | Medium | Medium — dev/test data loss | Document limitation; skip trigger recreation in SQLite rebuild |
| Type mapping edge cases cause silent data loss | Low | High — decimal precision, varchar truncation | Comprehensive type mapping tests; SQLite DECIMAL limitation documented prominently |
| Query builder performance overhead vs raw SQL | Low | Low — builder adds microseconds | Profile; builder compiles once, PDO caches statements |
| Column rename misdetected as drop+add in diff | Medium | High — data loss | Require explicit `renames:` section in YAML; never auto-detect renames |
| Concurrent schema migrations corrupt state | Low (phpBB is single-admin) | High — inconsistent schema | Advisory lock around all schema operations |
| MySQL DDL auto-commits breaking transaction expectations | Medium | Medium — partial schema application | Document MySQL DDL limitation; use per-operation tracking with rollback info |
| Plugin schema conflicts with core schema | Low | Medium — table/column collision | Namespace plugin tables (`phpbb_plugin_vendorname_*`); schema compiler validates no collision |

---

## 13. Recommendations

| Priority | Recommendation | Rationale |
|----------|---------------|-----------|
| 🔴 Critical | Build `TypeRegistry` first and test exhaustively | It's the linchpin for DDL generation, introspection, and diffing |
| 🔴 Critical | Implement `ConnectionInterface` wrapping PDO with lazy init | Foundation for everything; blocks all other work |
| 🟠 High | Build schema pipeline in order: Compiler → Introspector → Differ → DdlGenerator → Executor | Each depends on the previous; test each in isolation |
| 🟠 High | Test DDL generation against real MySQL 8 and PostgreSQL 14 from day one | Introspection and DDL edge cases are numerous |
| 🟡 Medium | Keep query builder minimal for MVP | Select/Insert/Update/Delete with basic WHERE/JOIN/ORDER/LIMIT covers 80% of use cases |
| 🟡 Medium | Use factory pattern for engine-specific implementations | Clean DI, no conditionals in business logic |
| 🟢 Low | Add SQLite DdlGenerator with table rebuild | Defer until test suite requires ALTER operations |
| 🟢 Low | Add query logging / EXPLAIN integration | Dev convenience, not blocking |

---

## 14. MVP Scope

### Phase 1: Connection Foundation (Required for all domain services)

- [x] `ConnectionConfig` + `ConnectionConfigFactory`
- [x] `Connection` (lazy PDO, lifecycle)
- [x] `ConnectionInterface`, `ReadConnectionInterface`
- [x] `TransactionManager` with savepoint nesting
- [x] `TransactionManagerInterface`
- [x] `AdvisoryLock` (MySQL + PostgreSQL)
- [x] `AdvisoryLockInterface`
- [x] DI service definitions

### Phase 2: Schema Pipeline (Required for install + plugin system)

- [ ] `TypeRegistry` (abstract ↔ engine type mapping)
- [ ] `SchemaDefinition` model (Table, Column, Index, ForeignKey value objects)
- [ ] `SchemaCompiler` (YAML → SchemaDefinition)
- [ ] `MySQLIntrospector` + `PostgreSQLIntrospector`
- [ ] `SchemaDiffer` (two schemas → DiffOperation[])
- [ ] `DiffOperation` types (CreateTable, DropTable, AddColumn, DropColumn, ModifyColumn, AddIndex, DropIndex, AddFK, DropFK)
- [ ] `MySQLDdlGenerator` + `PostgreSQLDdlGenerator`
- [ ] `SchemaExecutor` (dry-run + execute with safety)
- [ ] Factory classes for engine-specific resolution

### Phase 3: Versioning (Required for core upgrade)

- [ ] `phpbb_schema_migrations` table + migration tracker
- [ ] `AbstractMigration` base class
- [ ] `MigrationRunner` (discover, order, execute, track)

### Phase 4: Query Builder (Nice-to-have for dev ergonomics)

- [ ] `QueryFactory` + `SelectQuery` + `InsertQuery` + `UpdateQuery` + `DeleteQuery`
- [ ] `ExpressionBuilder`
- [ ] `Raw` escape hatch
- [ ] `MySQLCompiler` + `PostgreSQLCompiler`
- [ ] `CompiledQuery` value object

### Deferred (Post-MVP)

- SQLite DdlGenerator with table rebuild
- SQLite QueryCompiler
- SQLite SchemaIntrospector
- Window functions, CTEs, lateral joins in query builder
- Query logging / EXPLAIN integration
- Reconnection logic for CLI workers
- ResilientConnection (retry on "gone away")
- Multi-environment deployment tracking

---

## Appendices

### A. Source Files Analyzed

| File | Category | Content |
|------|----------|---------|
| `analysis/findings/internal-patterns.md` | Internal | DB usage across 8 domain services |
| `analysis/findings/schema-management.md` | Schema | YAML format, diff algorithms, DDL generation |
| `analysis/findings/multi-engine.md` | Multi-engine | Type mapping, DDL syntax, introspection queries |
| `analysis/findings/query-builder.md` | Query Builder | Doctrine, Laravel, CakePHP, Cycle API comparison |
| `analysis/findings/versioning-connection.md` | Versioning+Connection | Version tracking, connection lifecycle, transactions |
| `planning/research-brief.md` | Context | Research scope and constraints |
| `planning/research-plan.md` | Context | Research methodology and objectives |

### B. Introspection Query Reference

#### MySQL: Get Columns
```sql
SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
       COLUMN_KEY, EXTRA, CHARACTER_MAXIMUM_LENGTH, NUMERIC_PRECISION, NUMERIC_SCALE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
ORDER BY ORDINAL_POSITION;
```

#### PostgreSQL: Get Columns
```sql
SELECT column_name, data_type, udt_name, is_nullable, column_default,
       character_maximum_length, numeric_precision, numeric_scale, identity_generation
FROM information_schema.columns
WHERE table_schema = 'public' AND table_name = ?
ORDER BY ordinal_position;
```

#### MySQL: Get Indexes
```sql
SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX, INDEX_TYPE
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
ORDER BY INDEX_NAME, SEQ_IN_INDEX;
```

#### PostgreSQL: Get Indexes
```sql
SELECT indexname, indexdef FROM pg_indexes WHERE tablename = ?;
```

#### MySQL: Get Foreign Keys
```sql
SELECT rc.CONSTRAINT_NAME, kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME,
       kcu.REFERENCED_COLUMN_NAME, rc.UPDATE_RULE, rc.DELETE_RULE
FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
  ON rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.TABLE_NAME = ?;
```

#### SQLite: Get Table Info
```sql
PRAGMA table_info('tablename');       -- columns
PRAGMA index_list('tablename');       -- indexes
PRAGMA foreign_key_list('tablename'); -- foreign keys
```

### C. JSON Operator Cross-Reference

| Operation | MySQL 8+ | PostgreSQL 14+ | SQLite 3.38+ |
|-----------|----------|----------------|--------------|
| Extract text | `col->>'$.key'` | `col->>'key'` | `col->>'$.key'` |
| Set value | `JSON_SET(col, '$.key', val)` | `jsonb_set(col, '{key}', val)` | `json_set(col, '$.key', val)` |
| Remove key | `JSON_REMOVE(col, '$.key')` | `col - 'key'` | `json_remove(col, '$.key')` |
| Key exists | `JSON_CONTAINS_PATH(col, 'one', '$.key')` | `col ? 'key'` | `json_type(col,'$.key') IS NOT NULL` |
| Contains | `JSON_CONTAINS(col, '"v"', '$.k')` | `col @> '{"k":"v"}'` | No operator |
