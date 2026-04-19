# High-Level Design: Database Service

## Design Overview

The phpBB rebuild requires a **layered database infrastructure service** that provides connection management, declarative YAML→DDL schema management, schema versioning, and an optional query builder — all supporting MySQL 8+, PostgreSQL 14+, and SQLite (dev/test). Eight domain services use raw PDO with repository pattern and manual hydration; the DB service must preserve this pattern while adding schema lifecycle tooling for core install/upgrade and the plugin system.

The chosen approach is a **four-layer architecture** (Connection → Schema Management → Versioning → Query Builder) using **Strategy pattern** for all engine-specific components. The type system uses a **PHP 8.1 backed enum** (`AbstractType`) providing type-safe column type mapping with methods for SQL generation, reverse mapping, and validation. Schema management uses a **hybrid snapshot + live introspection** diff strategy. The query builder is a **full fluent mutable builder** with per-engine Compiler — optional, never replacing raw SQL.

**Key decisions:**
- **Thin ConnectionInterface** (5 methods + lazy PDO) — preserves raw SQL philosophy while adding testability and lazy initialization
- **Enum-based type system** — closed set of ~20 types with per-case behavior methods; plugin extension via DI-registered `TypeExtensionInterface`
- **Abstract YAML + engine overrides** — clean developer API with opt-in engine-specific tuning
- **Hybrid schema diff** — deterministic snapshot-based planning with live introspection for drift detection
- **Strategy pattern everywhere** — DdlGenerator, QueryCompiler, SchemaIntrospector each get one implementation per engine
- **Phase 4 query builder** — designed now for interface stability, built last

---

## Architecture

### System Context (C4 Level 1)

```
┌──────────────────────────────────────────────────────────────┐
│                        phpBB Application                      │
│                                                              │
│  ┌───────────────┐  ┌──────────────┐  ┌──────────────────┐  │
│  │ Domain        │  │ Plugin       │  │ CLI Tools        │  │
│  │ Services (8)  │  │ System       │  │ (install,upgrade)│  │
│  └───────┬───────┘  └──────┬───────┘  └────────┬─────────┘  │
│          │                 │                    │             │
│          └────────┬────────┴────────────────────┘             │
│                   ▼                                           │
│          ┌────────────────┐                                   │
│          │ Database       │                                   │
│          │ Service        │◄─── This design                   │
│          │ phpbb\database\│                                   │
│          └────────┬───────┘                                   │
│                   │                                           │
└───────────────────┼───────────────────────────────────────────┘
                    │ PDO
        ┌───────────┼───────────┐
        ▼           ▼           ▼
   ┌─────────┐ ┌─────────┐ ┌─────────┐
   │ MySQL 8+│ │ PG 14+  │ │ SQLite  │
   └─────────┘ └─────────┘ └─────────┘
```

**Interactions:**
- **Domain Services** → inject `ConnectionInterface`, call `prepare()`/`exec()` with raw SQL; optionally use QueryFactory
- **Plugin System** → uses SchemaEngine facade which orchestrates SchemaCompiler → SchemaDiffer → DdlGenerator → SchemaExecutor
- **CLI Tools** → invoke schema pipeline for install/upgrade, execute data migrations via MigrationRunner
- **Database Service → Engines** — PDO with engine-specific init statements; lazy connection per request

### Container Overview (C4 Level 2)

```
┌─────────────────────────────────────────────────────────────────────┐
│                    Database Service (phpbb\database\)                │
│                                                                     │
│  Layer 4: QUERY BUILDER (optional, Phase 4)                         │
│  ┌──────────────┐  ┌──────────────────────────┐  ┌──────────────┐  │
│  │ QueryFactory  │  │ SelectQuery, InsertQuery, │  │ Compiler     │  │
│  │              │  │ UpdateQuery, DeleteQuery  │  │ (per-engine) │  │
│  └──────┬───────┘  └────────────┬─────────────┘  └──────┬───────┘  │
│         │                       │                        │          │
│         └───────────────────────┴────────────────────────┘          │
│                                 │                                   │
├─────────────────────────────────┼───────────────────────────────────┤
│  Layer 3: VERSIONING            │                                   │
│  ┌─────────────────┐  ┌────────┴────────┐  ┌───────────────────┐   │
│  │ MigrationRunner  │  │ MigrationTracker │  │ SnapshotManager  │   │
│  └─────────────────┘  └─────────────────┘  └───────────────────┘   │
│                                                                     │
├─────────────────────────────────────────────────────────────────────┤
│  Layer 2: SCHEMA MANAGEMENT (lifecycle-only, never at runtime)      │
│  ┌─────────────┐ ┌──────────────┐ ┌────────────┐ ┌──────────────┐  │
│  │ Schema      │ │ Schema       │ │ Schema     │ │ DDL          │  │
│  │ Compiler    │ │ Introspector │ │ Differ     │ │ Generator    │  │
│  │ (YAML→Model)│ │ (DB→Model)   │ │ (Δ detect) │ │ (per-engine) │  │
│  └──────┬──────┘ └──────┬───────┘ └─────┬──────┘ └──────┬───────┘  │
│         └───────────────┴───────┬───────┴───────────────┘           │
│                                 ▼                                    │
│  ┌─────────────────┐  ┌─────────────────┐                          │
│  │ AbstractType    │  │ Schema Executor │                          │
│  │ (enum + ext.)   │  │ (DDL runner)    │                          │
│  └─────────────────┘  └─────────────────┘                          │
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

**Container responsibilities:**
- **Connection** (Layer 1): Lazy PDO wrapper, table prefix, driver detection, transaction nesting, advisory locks
- **Schema Management** (Layer 2): YAML → SchemaDefinition → SchemaDiffer → DiffOperation[] → DdlGenerator → SQL[] → SchemaExecutor — invoked only during install/upgrade/plugin lifecycle
- **Versioning** (Layer 3): Track applied migrations, store/load schema snapshots, execute data migrations
- **Query Builder** (Layer 4): Optional fluent API with per-engine Compiler; repositories can use raw SQL or builder interchangeably

---

## Key Components

### Layer 1: Connection

| Component | Purpose | Responsibilities | Key Interfaces | Dependencies |
|-----------|---------|-----------------|----------------|--------------|
| `phpbb\database\ConnectionConfig` | Immutable DSN + options holder | Build DSN string, provide driver name, hold init statements, store table prefix | Value object (readonly) | None |
| `phpbb\database\ConnectionConfigFactory` | Create config from phpBB settings | Parse config.php, resolve driver, set engine-specific init statements | Static factory | `ConnectionConfig` |
| `phpbb\database\Connection` | Lazy PDO wrapper with lifecycle | Create PDO on first use, delegate prepare/exec/lastInsertId, expose driver/prefix, disconnect | `ConnectionInterface` | `ConnectionConfig` |
| `phpbb\database\TransactionManager` | Nested transaction support | Begin/commit/rollback, savepoint-based nesting, `transactional()` closure API | `TransactionManagerInterface` | `Connection` |
| `phpbb\database\AdvisoryLock` | Named application-level locks | Acquire/release named locks using engine-specific SQL (GET_LOCK, pg_advisory_lock) | `AdvisoryLockInterface` | `Connection` |

### Layer 2: Schema Management

| Component | Purpose | Responsibilities | Key Interfaces | Dependencies |
|-----------|---------|-----------------|----------------|--------------|
| `phpbb\database\schema\type\AbstractType` | Type-safe column type mapping | Map abstract→SQL per engine, reverse-map SQL→abstract, validate column defs | Backed enum | None |
| `phpbb\database\schema\type\TypeRegistry` | Unified type lookups + extensions | Wrap enum for reverse lookups, register plugin type extensions, delegate to enum for core types | Class | `AbstractType`, `TypeExtensionInterface` |
| `phpbb\database\schema\SchemaCompiler` | Parse YAML into model | Load YAML, validate types, resolve overrides, produce SchemaDefinition | Class | `TypeRegistry`, Symfony YAML |
| `phpbb\database\schema\model\SchemaDefinition` | Immutable in-memory schema | Hold tables, provide lookup by name, compare equality, serialize to snapshot | Value object | `TableDefinition` |
| `phpbb\database\schema\model\TableDefinition` | Immutable table model | Hold columns, indexes, FKs, options | Value object | `ColumnDefinition`, `IndexDefinition`, `ForeignKeyDefinition` |
| `phpbb\database\schema\model\ColumnDefinition` | Immutable column model | Hold type, length, precision, scale, nullable, default, auto_increment | Value object | `AbstractType` |
| `phpbb\database\schema\model\IndexDefinition` | Immutable index model | Hold name, columns, uniqueness, prefix lengths | Value object | None |
| `phpbb\database\schema\model\ForeignKeyDefinition` | Immutable FK model | Hold name, columns, referenced table/columns, cascade rules | Value object | None |
| `phpbb\database\schema\SchemaIntrospectorInterface` | Read live DB schema | Introspect tables, columns, indexes, FKs from INFORMATION_SCHEMA / PRAGMA | Interface | `ConnectionInterface` |
| `phpbb\database\schema\introspector\MySQLIntrospector` | MySQL schema reader | Query INFORMATION_SCHEMA for MySQL-specific column/index/FK metadata | Implements interface | `ConnectionInterface`, `TypeRegistry` |
| `phpbb\database\schema\introspector\PostgreSQLIntrospector` | PostgreSQL schema reader | Query information_schema + pg_indexes for PG-specific metadata | Implements interface | `ConnectionInterface`, `TypeRegistry` |
| `phpbb\database\schema\SchemaDiffer` | Compare two schemas | Detect added/removed/modified tables, columns, indexes, FKs; produce DiffOperation[] | Class | None (pure model comparison) |
| `phpbb\database\schema\diff\DiffOperation` | Base for diff operations | Common interface for all schema change types | Abstract class | None |
| `phpbb\database\schema\diff\CreateTable` | Table creation | Hold full TableDefinition for new table | Extends `DiffOperation` | `TableDefinition` |
| `phpbb\database\schema\diff\DropTable` | Table removal | Hold table name (destructive, requires confirmation) | Extends `DiffOperation` | None |
| `phpbb\database\schema\diff\AddColumn` | Column addition | Hold table name + ColumnDefinition | Extends `DiffOperation` | `ColumnDefinition` |
| `phpbb\database\schema\diff\DropColumn` | Column removal | Hold table name + column name (destructive) | Extends `DiffOperation` | None |
| `phpbb\database\schema\diff\ModifyColumn` | Column modification | Hold table name + old/new ColumnDefinition | Extends `DiffOperation` | `ColumnDefinition` |
| `phpbb\database\schema\diff\AddIndex` | Index creation | Hold table name + IndexDefinition | Extends `DiffOperation` | `IndexDefinition` |
| `phpbb\database\schema\diff\DropIndex` | Index removal | Hold table name + index name | Extends `DiffOperation` | None |
| `phpbb\database\schema\diff\AddForeignKey` | FK creation | Hold table name + ForeignKeyDefinition | Extends `DiffOperation` | `ForeignKeyDefinition` |
| `phpbb\database\schema\diff\DropForeignKey` | FK removal | Hold table name + FK name | Extends `DiffOperation` | None |
| `phpbb\database\schema\ddl\DdlGeneratorInterface` | Convert DiffOperation → SQL | Generate engine-specific DDL for any diff operation | Interface | `TypeRegistry` |
| `phpbb\database\schema\ddl\AbstractDdlGenerator` | Shared DDL logic | Operation dispatch, column declaration ordering, default rendering | Abstract class | `TypeRegistry` |
| `phpbb\database\schema\ddl\MySQLDdlGenerator` | MySQL DDL | AUTO_INCREMENT, UNSIGNED, ENGINE/CHARSET options, identifier quoting with backticks | Extends abstract | `TypeRegistry` |
| `phpbb\database\schema\ddl\PostgreSQLDdlGenerator` | PostgreSQL DDL | GENERATED AS IDENTITY, CHECK constraints for unsigned, double-quote identifiers | Extends abstract | `TypeRegistry` |
| `phpbb\database\schema\ddl\SQLiteDdlGenerator` | SQLite DDL | CREATE TABLE only for MVP; table rebuild for ALTER deferred to post-MVP | Extends abstract | `TypeRegistry` |
| `phpbb\database\schema\SchemaExecutor` | Execute DDL safely | Dry-run preview, execute with confirmation for destructive ops, store snapshot after success | Class | `ConnectionInterface`, `DdlGeneratorInterface`, `SchemaDiffer`, `SnapshotManager` |

### Layer 3: Versioning

| Component | Purpose | Responsibilities | Key Interfaces | Dependencies |
|-----------|---------|-----------------|----------------|--------------|
| `phpbb\database\migration\SnapshotManager` | Store/load schema snapshots | Serialize SchemaDefinition to JSON, store in `phpbb_schema_snapshots` or plugin state, load for diff | Class | `ConnectionInterface` |
| `phpbb\database\migration\MigrationTracker` | Track applied migrations | Read/write `phpbb_schema_migrations` table, check if migration applied, record execution | Class | `ConnectionInterface` |
| `phpbb\database\migration\MigrationRunner` | Execute data migrations | Discover migration classes, sort by dependency, execute in order, track state | Class | `ConnectionInterface`, `MigrationTracker` |
| `phpbb\database\migration\AbstractMigration` | Base for data migrations | Provide connection access, unique ID (FQCN), abstract up(), optional down() | Abstract class | `ConnectionInterface` |

### Layer 4: Query Builder (Phase 4)

| Component | Purpose | Responsibilities | Key Interfaces | Dependencies |
|-----------|---------|-----------------|----------------|--------------|
| `phpbb\database\query\QueryFactory` | Entry point for query building | Create SelectQuery, InsertQuery, UpdateQuery, DeleteQuery; hold compiler + connection | `QueryFactoryInterface` | `ConnectionInterface`, `QueryCompilerInterface` |
| `phpbb\database\query\SelectQuery` | Fluent SELECT construction | from, where, join, orderBy, limit, offset; terminal methods: fetchAll, fetchOne, cursor, count | Class | `QueryCompilerInterface`, `ConnectionInterface` |
| `phpbb\database\query\InsertQuery` | Fluent INSERT construction | row, rows, onConflict/doUpdate/doNothing (upsert); terminal: execute | Class | `QueryCompilerInterface`, `ConnectionInterface` |
| `phpbb\database\query\UpdateQuery` | Fluent UPDATE construction | set, setRaw, increment, decrement, where; terminal: execute | Class | `QueryCompilerInterface`, `ConnectionInterface` |
| `phpbb\database\query\DeleteQuery` | Fluent DELETE construction | where; terminal: execute | Class | `QueryCompilerInterface`, `ConnectionInterface` |
| `phpbb\database\query\expression\ExpressionBuilder` | Complex WHERE conditions | and/or grouping, nested conditions, raw expressions | Class | None |
| `phpbb\database\query\Raw` | SQL escape hatch | Hold raw SQL fragment + bindings; usable in where, set, from positions | Value object | None |
| `phpbb\database\query\CompiledQuery` | Compilation output | Hold final SQL string + ordered bindings array | Value object | None |
| `phpbb\database\query\compiler\QueryCompilerInterface` | Compile query objects → SQL | Compile select/insert/update/delete, apply table prefix, quote identifiers | Interface | None |
| `phpbb\database\query\compiler\MySQLCompiler` | MySQL query compilation | Backtick quoting, ON DUPLICATE KEY UPDATE, TINYINT(1) booleans | Implements interface | None |
| `phpbb\database\query\compiler\PostgreSQLCompiler` | PostgreSQL query compilation | Double-quote identifiers, ON CONFLICT DO UPDATE, native BOOLEAN | Implements interface | None |
| `phpbb\database\query\compiler\SQLiteCompiler` | SQLite query compilation | Double-quote identifiers, ON CONFLICT DO UPDATE, INTEGER booleans | Implements interface | None |

---

## Service Contracts (Interfaces)

### ConnectionInterface

```php
namespace phpbb\database;

interface ConnectionInterface
{
    /**
     * Prepare a SQL statement for execution.
     */
    public function prepare(string $sql): \PDOStatement;

    /**
     * Execute a SQL statement and return the number of affected rows.
     */
    public function exec(string $sql): int;

    /**
     * Return the ID of the last inserted row.
     */
    public function lastInsertId(?string $name = null): string|false;

    /**
     * Get the table prefix for this installation (e.g. 'phpbb_').
     */
    public function getTablePrefix(): string;

    /**
     * Get the driver name: 'mysql', 'pgsql', or 'sqlite'.
     */
    public function getDriver(): string;
}
```

### DdlGeneratorInterface

```php
namespace phpbb\database\schema\ddl;

use phpbb\database\schema\diff\DiffOperation;
use phpbb\database\schema\model\ColumnDefinition;

interface DdlGeneratorInterface
{
    /**
     * Generate SQL statements for a diff operation.
     *
     * @return string[] One or more SQL statements
     */
    public function generate(DiffOperation $operation): array;

    /**
     * Get the full column declaration SQL (type + modifiers + constraints).
     * Used internally by generate() and exposed for testing/debugging.
     */
    public function getColumnDeclarationSQL(ColumnDefinition $column): string;
}
```

### SchemaIntrospectorInterface

```php
namespace phpbb\database\schema;

use phpbb\database\schema\model\SchemaDefinition;
use phpbb\database\schema\model\TableDefinition;

interface SchemaIntrospectorInterface
{
    /**
     * Introspect all tables matching the configured prefix.
     */
    public function introspect(): SchemaDefinition;

    /**
     * Introspect a single table by logical name (without prefix).
     */
    public function introspectTable(string $tableName): ?TableDefinition;

    /**
     * Check if a table exists in the database.
     */
    public function tableExists(string $tableName): bool;
}
```

### QueryCompilerInterface

```php
namespace phpbb\database\query\compiler;

use phpbb\database\query\CompiledQuery;
use phpbb\database\query\SelectQuery;
use phpbb\database\query\InsertQuery;
use phpbb\database\query\UpdateQuery;
use phpbb\database\query\DeleteQuery;

interface QueryCompilerInterface
{
    public function compileSelect(SelectQuery $query): CompiledQuery;
    public function compileInsert(InsertQuery $query): CompiledQuery;
    public function compileUpdate(UpdateQuery $query): CompiledQuery;
    public function compileDelete(DeleteQuery $query): CompiledQuery;

    /**
     * Quote a database identifier (table name, column name).
     */
    public function quoteIdentifier(string $identifier): string;

    /**
     * Apply the table prefix to a logical table name.
     */
    public function prefixTable(string $table): string;
}
```

### TransactionManagerInterface

```php
namespace phpbb\database;

interface TransactionManagerInterface
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;

    /**
     * Execute callback within a transaction.
     * Commits on success, rolls back on exception.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed;

    public function getNestingLevel(): int;
}
```

### TypeExtensionInterface (Plugin custom types)

```php
namespace phpbb\database\schema\type;

use phpbb\database\schema\model\ColumnDefinition;

interface TypeExtensionInterface
{
    /**
     * Abstract type name (e.g. 'point', 'geometry').
     */
    public function getName(): string;

    /**
     * Get engine-specific SQL type.
     */
    public function toSql(string $driver): string;

    /**
     * Reverse-map an engine SQL type to this abstract type.
     * Return null if the SQL type is not owned by this extension.
     */
    public function fromSql(string $sqlType, string $driver): ?string;

    /**
     * Validate that a column definition is valid for this type.
     */
    public function validate(ColumnDefinition $column): void;
}
```

---

## AbstractType Enum Design

### Full Enum Definition

```php
namespace phpbb\database\schema\type;

use phpbb\database\schema\model\ColumnDefinition;

enum AbstractType: string
{
    case Smallint  = 'smallint';
    case Int       = 'int';
    case Bigint    = 'bigint';
    case Uint      = 'uint';
    case Ubigint   = 'ubigint';
    case Serial    = 'serial';
    case Bool      = 'bool';
    case Varchar   = 'varchar';
    case Char      = 'char';
    case Text      = 'text';
    case Longtext  = 'longtext';
    case Decimal   = 'decimal';
    case Float     = 'float';
    case Double    = 'double';
    case Timestamp = 'timestamp';
    case Json      = 'json';
    case Blob      = 'blob';
    case Binary    = 'binary';
    case Uuid      = 'uuid';

    /**
     * Get the engine-specific SQL base type for this abstract type.
     * Does NOT include length, precision, or modifiers — those are
     * assembled by the DdlGenerator using ColumnDefinition properties.
     */
    public function toSql(string $driver): string
    {
        return match ($this) {
            self::Smallint  => match ($driver) {
                'mysql', 'pgsql' => 'SMALLINT',
                'sqlite' => 'INTEGER',
            },
            self::Int => match ($driver) {
                'mysql'  => 'INT',
                'pgsql'  => 'INTEGER',
                'sqlite' => 'INTEGER',
            },
            self::Bigint => match ($driver) {
                'mysql', 'pgsql' => 'BIGINT',
                'sqlite' => 'INTEGER',
            },
            self::Uint => match ($driver) {
                'mysql'  => 'INT UNSIGNED',
                'pgsql'  => 'INTEGER',     // CHECK added by DdlGenerator
                'sqlite' => 'INTEGER',
            },
            self::Ubigint => match ($driver) {
                'mysql'  => 'BIGINT UNSIGNED',
                'pgsql'  => 'BIGINT',      // CHECK added by DdlGenerator
                'sqlite' => 'INTEGER',
            },
            self::Serial => match ($driver) {
                'mysql'  => 'BIGINT UNSIGNED',  // AUTO_INCREMENT added by DdlGenerator
                'pgsql'  => 'BIGINT',           // GENERATED ALWAYS AS IDENTITY by DdlGenerator
                'sqlite' => 'INTEGER',           // PRIMARY KEY implies autoincrement
            },
            self::Bool => match ($driver) {
                'mysql'  => 'TINYINT(1)',
                'pgsql'  => 'BOOLEAN',
                'sqlite' => 'INTEGER',
            },
            self::Varchar => match ($driver) {
                'mysql', 'pgsql' => 'VARCHAR',  // (N) added by DdlGenerator from length
                'sqlite' => 'TEXT',
            },
            self::Char => match ($driver) {
                'mysql', 'pgsql' => 'CHAR',     // (N) added by DdlGenerator from length
                'sqlite' => 'TEXT',
            },
            self::Text => match ($driver) {
                'mysql'  => 'MEDIUMTEXT',
                'pgsql'  => 'TEXT',
                'sqlite' => 'TEXT',
            },
            self::Longtext => match ($driver) {
                'mysql'  => 'LONGTEXT',
                'pgsql'  => 'TEXT',
                'sqlite' => 'TEXT',
            },
            self::Decimal => match ($driver) {
                'mysql'  => 'DECIMAL',    // (P,S) from precision/scale
                'pgsql'  => 'NUMERIC',    // (P,S) from precision/scale
                'sqlite' => 'REAL',       // ⚠️ lossy
            },
            self::Float => match ($driver) {
                'mysql'  => 'FLOAT',
                'pgsql'  => 'REAL',
                'sqlite' => 'REAL',
            },
            self::Double => match ($driver) {
                'mysql'  => 'DOUBLE',
                'pgsql'  => 'DOUBLE PRECISION',
                'sqlite' => 'REAL',
            },
            self::Timestamp => match ($driver) {
                'mysql'  => 'INT UNSIGNED',
                'pgsql'  => 'INTEGER',
                'sqlite' => 'INTEGER',
            },
            self::Json => match ($driver) {
                'mysql'  => 'JSON',
                'pgsql'  => 'JSONB',
                'sqlite' => 'TEXT',
            },
            self::Blob => match ($driver) {
                'mysql'  => 'LONGBLOB',
                'pgsql'  => 'BYTEA',
                'sqlite' => 'BLOB',
            },
            self::Binary => match ($driver) {
                'mysql'  => 'BINARY',     // (N) from length
                'pgsql'  => 'BYTEA',
                'sqlite' => 'BLOB',
            },
            self::Uuid => match ($driver) {
                'mysql'  => 'CHAR(36)',
                'pgsql'  => 'UUID',
                'sqlite' => 'TEXT',
            },
        };
    }

    /**
     * Reverse-map an engine SQL type string to an AbstractType.
     * Returns null if no match found (delegated to TypeExtensions).
     */
    public static function fromSql(string $sqlType, string $driver): ?self
    {
        $normalized = strtoupper(trim($sqlType));

        // Engine-specific reverse map (ordered most-specific first)
        return match ($driver) {
            'mysql' => self::fromMysql($normalized),
            'pgsql' => self::fromPgsql($normalized),
            'sqlite' => self::fromSqlite($normalized),
            default => null,
        };
    }

    private static function fromMysql(string $type): ?self
    {
        // Strip display width: INT(11) → INT
        $base = preg_replace('/\(\d+(?:,\d+)?\)/', '', $type);

        return match (true) {
            $base === 'BIGINT UNSIGNED'  => self::Ubigint,
            $base === 'INT UNSIGNED'     => self::Uint,
            $base === 'TINYINT'          => self::Bool,
            $base === 'SMALLINT'         => self::Smallint,
            $base === 'INT'              => self::Int,
            $base === 'BIGINT'           => self::Bigint,
            str_starts_with($type, 'VARCHAR')  => self::Varchar,
            str_starts_with($type, 'CHAR')     => self::Char,
            $base === 'MEDIUMTEXT'       => self::Text,
            $base === 'LONGTEXT'         => self::Longtext,
            str_starts_with($type, 'DECIMAL')  => self::Decimal,
            $base === 'FLOAT'            => self::Float,
            $base === 'DOUBLE'           => self::Double,
            $base === 'JSON'             => self::Json,
            $base === 'LONGBLOB'         => self::Blob,
            str_starts_with($type, 'BINARY')   => self::Binary,
            $base === 'BYTEA'            => self::Blob,
            $base === 'UUID'             => self::Uuid,
            default => null,
        };
    }

    private static function fromPgsql(string $type): ?self
    {
        return match (true) {
            $type === 'SMALLINT'         => self::Smallint,
            $type === 'INTEGER'          => self::Int,
            $type === 'BIGINT'           => self::Bigint,
            $type === 'BOOLEAN'          => self::Bool,
            str_starts_with($type, 'CHARACTER VARYING') => self::Varchar,
            str_starts_with($type, 'VARCHAR') => self::Varchar,
            str_starts_with($type, 'CHAR')    => self::Char,
            $type === 'TEXT'             => self::Text,
            str_starts_with($type, 'NUMERIC') => self::Decimal,
            $type === 'REAL'             => self::Float,
            $type === 'DOUBLE PRECISION' => self::Double,
            $type === 'JSONB'            => self::Json,
            $type === 'JSON'             => self::Json,
            $type === 'BYTEA'            => self::Blob,
            $type === 'UUID'             => self::Uuid,
            default => null,
        };
    }

    private static function fromSqlite(string $type): ?self
    {
        return match (true) {
            $type === 'INTEGER' => self::Int,
            $type === 'TEXT'    => self::Text,
            $type === 'REAL'    => self::Float,
            $type === 'BLOB'    => self::Blob,
            default => null,
        };
    }

    /**
     * Validate that the ColumnDefinition is compatible with this type.
     * Throws InvalidArgumentException on validation failure.
     */
    public function validate(ColumnDefinition $column): void
    {
        match ($this) {
            self::Varchar, self::Char, self::Binary => $column->length > 0
                ?: throw new \InvalidArgumentException(
                    "Type {$this->value} requires a positive length, got {$column->length}"
                ),
            self::Decimal => ($column->precision > 0 && $column->scale >= 0)
                ?: throw new \InvalidArgumentException(
                    "Type decimal requires precision > 0 and scale >= 0"
                ),
            default => null,
        };
    }

    /**
     * Whether this type requires an unsigned CHECK constraint on PostgreSQL.
     */
    public function requiresUnsignedCheck(): bool
    {
        return match ($this) {
            self::Uint, self::Ubigint, self::Timestamp => true,
            default => false,
        };
    }

    /**
     * Whether this type implies auto_increment behavior.
     */
    public function isAutoIncrement(): bool
    {
        return $this === self::Serial;
    }

    /**
     * Whether the column type requires a length parameter.
     */
    public function requiresLength(): bool
    {
        return match ($this) {
            self::Varchar, self::Char, self::Binary => true,
            default => false,
        };
    }

    /**
     * Whether the column type supports precision/scale.
     */
    public function supportsPrecision(): bool
    {
        return $this === self::Decimal;
    }
}
```

### TypeRegistry (Wraps Enum + Extensions)

```php
namespace phpbb\database\schema\type;

use phpbb\database\schema\model\ColumnDefinition;

final class TypeRegistry
{
    /** @var array<string, TypeExtensionInterface> */
    private array $extensions = [];

    /**
     * @param iterable<TypeExtensionInterface> $extensions DI-injected tagged services
     */
    public function __construct(iterable $extensions = [])
    {
        foreach ($extensions as $ext) {
            $this->extensions[$ext->getName()] = $ext;
        }
    }

    public function getSqlType(AbstractType|string $type, string $driver): string
    {
        if (is_string($type)) {
            // Check enum first, then extensions
            $enumType = AbstractType::tryFrom($type);
            if ($enumType !== null) {
                return $enumType->toSql($driver);
            }
            if (isset($this->extensions[$type])) {
                return $this->extensions[$type]->toSql($driver);
            }
            throw new \InvalidArgumentException("Unknown abstract type: {$type}");
        }
        return $type->toSql($driver);
    }

    public function getAbstractType(string $sqlType, string $driver): string
    {
        // Try enum first
        $enumType = AbstractType::fromSql($sqlType, $driver);
        if ($enumType !== null) {
            return $enumType->value;
        }
        // Try extensions
        foreach ($this->extensions as $ext) {
            $result = $ext->fromSql($sqlType, $driver);
            if ($result !== null) {
                return $result;
            }
        }
        throw new \InvalidArgumentException(
            "Cannot reverse-map SQL type '{$sqlType}' for driver '{$driver}'"
        );
    }

    public function isValidType(string $abstractType): bool
    {
        return AbstractType::tryFrom($abstractType) !== null
            || isset($this->extensions[$abstractType]);
    }

    public function validate(ColumnDefinition $column): void
    {
        $enumType = AbstractType::tryFrom($column->type);
        if ($enumType !== null) {
            $enumType->validate($column);
            return;
        }
        if (isset($this->extensions[$column->type])) {
            $this->extensions[$column->type]->validate($column);
            return;
        }
        throw new \InvalidArgumentException("Unknown type: {$column->type}");
    }
}
```

### Plugin Custom Type Extension

Plugins needing types outside the core 19 (e.g., `point`, `geometry`) implement `TypeExtensionInterface` and register it as a DI tagged service:

```yaml
# Plugin services.yml
services:
    acme\maps\type\PointType:
        tags: ['phpbb.database.type_extension']
```

The `TypeRegistry` constructor receives all tagged services via DI. This provides extensibility without modifying the closed enum.

**Trade-off**: Plugin types are second-class — they don't get IDE autocomplete from the enum, and they miss compile-time exhaustiveness checks in `match()` expressions. This is acceptable because custom types are rare (estimated <5% of plugin schemas), and the enum covers the 19 types that represent 95%+ of real-world column definitions.

---

## YAML Schema Format Specification (3C)

### Full Example

```yaml
tables:
    topics:
        columns:
            topic_id:
                type: uint
                auto_increment: true
            forum_id:
                type: uint
                default: 0
            user_id:
                type: uint
                default: 0
            title:
                type: varchar
                length: 255
            body:
                type: text
            status:
                type: smallint
                default: 0
            is_pinned:
                type: bool
                default: false
            is_locked:
                type: bool
                default: false
            view_count:
                type: uint
                default: 0
            reply_count:
                type: uint
                default: 0
            last_post_time:
                type: timestamp
                default: 0
            last_post_user_id:
                type: uint
                default: 0
            metadata:
                type: json
                nullable: true
            created_at:
                type: timestamp
                default: 0
            updated_at:
                type: timestamp
                default: 0

        primary_key: [topic_id]

        indexes:
            idx_forum_pinned_time:
                columns: [forum_id, is_pinned, last_post_time]
            idx_user: [user_id]
            idx_last_post: [last_post_time]

        unique_keys:
            # (none for this table)

        foreign_keys:
            fk_forum:
                columns: [forum_id]
                references:
                    table: forums
                    columns: [forum_id]
                on_delete: CASCADE
            fk_user:
                columns: [user_id]
                references:
                    table: users
                    columns: [user_id]
                on_delete: SET DEFAULT

        overrides:
            mysql:
                engine: InnoDB
                charset: utf8mb4
                collation: utf8mb4_unicode_ci
```

### Column Properties Reference

| Property | Type | Default | Required | Notes |
|----------|------|---------|----------|-------|
| `type` | string | — | **Yes** | Must be a valid AbstractType case or registered extension |
| `length` | int | — | For varchar, char, binary | Maximum character/byte length |
| `precision` | int | 10 | For decimal | Total digits |
| `scale` | int | 0 | For decimal | Digits after decimal point |
| `nullable` | bool | `false` | No | NOT NULL is default |
| `default` | mixed | none | No | `null` allowed only if nullable |
| `auto_increment` | bool | `false` | No | One per table max |
| `comment` | string | none | No | Stored in DDL where engine supports it |

### Index Format

```yaml
indexes:
    # Short form: array of columns
    idx_forum: [forum_id]

    # Long form: with prefix length (MySQL only, ignored elsewhere)
    idx_title:
        columns: [title]
        length: { title: 100 }
```

### Foreign Key Format

```yaml
foreign_keys:
    fk_forum:
        columns: [forum_id]
        references:
            table: forums              # Logical name, auto-prefixed
            columns: [forum_id]
        on_delete: CASCADE             # CASCADE | SET NULL | SET DEFAULT | RESTRICT | NO ACTION
        on_update: NO ACTION           # Same options, default: NO ACTION
```

### Engine Overrides

The `overrides:` section is **opt-in** and only affects table-level DDL options. It cannot override column types or add columns — those belong in the abstract `columns:` section.

```yaml
overrides:
    mysql:
        engine: InnoDB          # Default anyway, explicit for clarity
        charset: utf8mb4
        collation: utf8mb4_unicode_ci
    pgsql:
        # No PG-specific table options needed for most tables
```

### Plugin Schema Example

```yaml
# File: plugins/acme-polls/schema.yml
tables:
    plugin_acme_poll_options:
        columns:
            option_id:
                type: uint
                auto_increment: true
            poll_id:
                type: uint
            option_text:
                type: varchar
                length: 255
            vote_count:
                type: uint
                default: 0
        primary_key: [option_id]
        indexes:
            idx_poll: [poll_id]
```

### Conventions

| Element | Convention | Example |
|---------|-----------|---------|
| Table names | snake_case, plural | `topics`, `poll_options` |
| Plugin tables | `plugin_{vendor}_{name}` prefix | `plugin_acme_poll_options` |
| Column names | snake_case | `topic_id`, `last_post_time` |
| PK columns | `{entity}_id` | `topic_id`, `user_id` |
| FK columns | Same name as referenced PK | `forum_id` |
| Booleans | `is_*` or `has_*` prefix | `is_pinned`, `has_attachments` |
| Timestamps | Unix int, `*_at` suffix | `created_at`, `updated_at` |
| Counters | `*_count` suffix, default 0 | `view_count`, `reply_count` |
| Index names | `idx_` prefix | `idx_forum_pinned_time` |
| Unique key names | `uq_` prefix | `uq_email` |
| FK names | `fk_` prefix | `fk_forum`, `fk_user` |

---

## Data Flow

### Schema Install Flow

```
                    schema.yml
                        │
                        ▼
              ┌──────────────────┐
              │  SchemaCompiler  │  parse YAML, validate types via TypeRegistry
              └────────┬─────────┘
                       │
                       ▼
              ┌──────────────────┐
              │ SchemaDefinition │  immutable in-memory model
              └────────┬─────────┘
                       │
                       ▼
              ┌──────────────────┐
              │  SchemaDiffer    │  diff(declared, empty) → all CreateTable ops
              └────────┬─────────┘
                       │
                       ▼
              ┌──────────────────┐
              │  DdlGenerator    │  generate(CreateTable) → CREATE TABLE SQL
              │  (engine-specific)│
              └────────┬─────────┘
                       │
                       ▼
              ┌──────────────────┐
              │  SchemaExecutor  │  execute SQL via ConnectionInterface
              └────────┬─────────┘
                       │
                       ▼
              ┌──────────────────┐
              │ SnapshotManager  │  store SchemaDefinition as JSON snapshot
              └──────────────────┘
```

### Schema Upgrade Flow

```
  old snapshot (JSON)          new schema.yml
        │                           │
        ▼                           ▼
  SnapshotManager            SchemaCompiler
        │                           │
        ▼                           ▼
  SchemaDefinition(old)      SchemaDefinition(new)
        │                           │
        └───────────┬───────────────┘
                    ▼
            ┌──────────────┐
            │ SchemaDiffer │  diff(new, old) → DiffOperation[]
            └──────┬───────┘
                   │
          ┌────────┴────────────────────────────────────┐
          │ [AddColumn, ModifyColumn, AddIndex, ...]    │
          └────────┬────────────────────────────────────┘
                   ▼
            ┌──────────────┐
            │ DdlGenerator │  generate(op) per operation → SQL[]
            └──────┬───────┘
                   ▼
            ┌──────────────┐
            │SchemaExecutor│  dry-run preview → confirm destructive → execute
            └──────┬───────┘
                   ▼
            ┌──────────────┐
            │SnapshotManager│  update snapshot
            └──────────────┘
```

### Query Builder Flow

```
  Repository code
        │
        ▼
  QueryFactory::select('*')
        │
        ▼
  SelectQuery::from('topics')->where('forum_id', $id)->limit(10)
        │ (method chaining builds internal state)
        ▼
  SelectQuery::fetchAll()  [terminal method]
        │
        ▼
  QueryCompilerInterface::compileSelect(query)
        │
        ├─ Apply table prefix: 'topics' → 'phpbb_topics'
        ├─ Quote identifiers: per engine rules
        ├─ Build WHERE clause with parameter placeholders
        ├─ Engine-specific LIMIT syntax
        │
        ▼
  CompiledQuery { sql: "SELECT * FROM `phpbb_topics` WHERE ...", bindings: [...] }
        │
        ▼
  ConnectionInterface::prepare(sql)->execute(bindings)
        │
        ▼
  PDOStatement::fetchAll()
```

### Drift Detection Flow

```
  schema.yml (declared truth)          Live Database
        │                                    │
        ▼                                    ▼
  SchemaCompiler                     SchemaIntrospectorInterface
        │                                    │
        ▼                                    ▼
  SchemaDefinition(declared)         SchemaDefinition(live)
        │                                    │
        └────────────────┬───────────────────┘
                         ▼
                  ┌──────────────┐
                  │ SchemaDiffer │  diff(declared, live)
                  └──────┬───────┘
                         │
                         ▼
              ┌──────────────────────────────┐
              │ DiffOperation[] (if any)     │
              │ = drift detected             │
              │ Report to admin/CI pipeline  │
              └──────────────────────────────┘
```

---

## Integration Points

### Connections to Existing Systems

| System | Integration | Direction | Mechanism |
|--------|------------|-----------|-----------|
| **8 Domain Services** | Inject `ConnectionInterface` for all DB access | Inbound | Symfony DI constructor injection |
| **Plugin System** | SchemaEngine facade orchestrates schema pipeline | Inbound | Symfony DI; SchemaEngine owns plugin_state JSON with snapshots |
| **CLI Tools** | Install/upgrade commands invoke schema pipeline + migration runner | Inbound | Symfony Console commands with DI |
| **Symfony DI** | Factory-based engine resolution for all Strategy implementations | Internal | Tagged services, factory methods |
| **YAML files** | Schema declarations read by SchemaCompiler | Inbound | Symfony YAML component (filesystem) |
| **Database engines** | PDO connections to MySQL/PG/SQLite | Outbound | PDO with engine-specific init statements |

### Database Interactions

| Component | DB Access | When |
|-----------|----------|------|
| `Connection` | All PDO operations | Every request (lazy) |
| `TransactionManager` | BEGIN/COMMIT/ROLLBACK/SAVEPOINT | Within service operations |
| `AdvisoryLock` | GET_LOCK / pg_advisory_lock | Tree mutations, schema operations |
| `SchemaIntrospector` | INFORMATION_SCHEMA / PRAGMA queries | Drift detection, first install |
| `SchemaExecutor` | DDL statements (CREATE, ALTER, DROP) | Install, upgrade, plugin lifecycle |
| `MigrationTracker` | SELECT/INSERT on `phpbb_schema_migrations` | Migration execution |
| `SnapshotManager` | SELECT/INSERT/UPDATE on snapshot storage | After schema operations |

---

## Design Decisions

| ID | Decision | Chosen Option | Key Rationale | ADR Link |
|----|----------|--------------|---------------|----------|
| DB-1 | Connection abstraction depth | Thin ConnectionInterface (1B) | Preserves raw SQL; adds lazy init + testability | [ADR-DB-1](decision-log.md#adr-db-1-thin-connection-interface) |
| DB-2 | Type system architecture | Enum-Based Type Objects (2B) | Type safety; IDE support; per-case validation | [ADR-DB-2](decision-log.md#adr-db-2-enum-based-type-system) |
| DB-3 | YAML schema format | Abstract + Engine Overrides (3C) | Portable defaults with opt-in engine specifics | [ADR-DB-3](decision-log.md#adr-db-3-abstract-yaml-with-engine-overrides) |
| DB-4 | Schema diff strategy | Hybrid Snapshot + Live (4D) | Deterministic planning with drift safety net | [ADR-DB-4](decision-log.md#adr-db-4-hybrid-schema-diff-strategy) |
| DB-5 | Query builder style | Full Fluent Mutable Builder (5C) | Industry standard; handles engine divergences | [ADR-DB-5](decision-log.md#adr-db-5-full-fluent-mutable-query-builder) |
| DB-6 | DDL generator architecture | Strategy Pattern per Engine (6B) | Engine isolation; mirrors Compiler/Introspector pattern | [ADR-DB-6](decision-log.md#adr-db-6-strategy-pattern-ddl-generator) |

---

## Concrete Examples

### Example 1: Plugin Installation — Forum Polls Plugin

**Given** a plugin `acme-polls` with `schema.yml` declaring a `plugin_acme_poll_options` table with columns `option_id` (uint, auto_increment), `poll_id` (uint), `option_text` (varchar 255), `vote_count` (uint, default 0)

**When** the admin installs the plugin and SchemaEngine::install() is called

**Then**:
1. SchemaCompiler parses YAML → SchemaDefinition with one table
2. SchemaDiffer diffs against empty → one `CreateTable` operation
3. DdlGenerator (MySQL) produces:
   ```sql
   CREATE TABLE `phpbb_plugin_acme_poll_options` (
       `option_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
       `poll_id` INT UNSIGNED NOT NULL DEFAULT 0,
       `option_text` VARCHAR(255) NOT NULL DEFAULT '',
       `vote_count` INT UNSIGNED NOT NULL DEFAULT 0,
       PRIMARY KEY (`option_id`),
       INDEX `idx_poll` (`poll_id`)
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
   ```
4. SchemaExecutor runs the DDL via ConnectionInterface
5. SnapshotManager stores the SchemaDefinition as JSON in plugin state

### Example 2: Core Upgrade — Adding a Column

**Given** core version 5.0.0 has a `topics` table, and version 5.1.0 adds a `featured_until` (timestamp, nullable) column

**When** upgrade CLI runs and loads the old 5.0.0 snapshot + new 5.1.0 schema YAML

**Then**:
1. SnapshotManager loads 5.0.0 SchemaDefinition
2. SchemaCompiler compiles 5.1.0 YAML → new SchemaDefinition
3. SchemaDiffer produces: `[AddColumn(table: 'topics', column: featured_until)]`
4. DdlGenerator (PostgreSQL) produces:
   ```sql
   ALTER TABLE "phpbb_topics" ADD COLUMN "featured_until" INTEGER NULL DEFAULT NULL
   ```
5. SchemaExecutor runs DDL, then MigrationRunner executes any PHP data migrations
6. SnapshotManager stores 5.1.0 snapshot

### Example 3: Drift Detection in CI

**Given** production MySQL database where a DBA manually added an index `idx_emergency` on `topics`

**When** drift detection CLI command is run

**Then**:
1. SchemaCompiler compiles current YAML → declared SchemaDefinition
2. MySQLIntrospector queries INFORMATION_SCHEMA → live SchemaDefinition
3. SchemaDiffer diffs declared vs live → `[DropIndex(table: 'topics', index: 'idx_emergency')]`
4. Report shows: "Drift detected: undeclared index `idx_emergency` on `topics`"
5. Admin decides: either add index to YAML (making it official) or drop it

---

## DI Wiring

### Symfony DI Configuration (services.yml)

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    # === Layer 1: Connection ===

    phpbb\database\ConnectionConfig:
        factory: ['phpbb\database\ConnectionConfigFactory', 'fromPhpbbConfig']
        arguments: ['%phpbb.db_driver%', '%phpbb.db_host%', '%phpbb.db_port%',
                     '%phpbb.db_name%', '%phpbb.db_user%', '%phpbb.db_password%',
                     '%phpbb.table_prefix%']

    phpbb\database\Connection:
        arguments: ['@phpbb\database\ConnectionConfig']

    phpbb\database\ConnectionInterface:
        alias: phpbb\database\Connection

    phpbb\database\TransactionManager:
        arguments: ['@phpbb\database\Connection']

    phpbb\database\TransactionManagerInterface:
        alias: phpbb\database\TransactionManager

    phpbb\database\AdvisoryLock:
        arguments: ['@phpbb\database\Connection']

    phpbb\database\AdvisoryLockInterface:
        alias: phpbb\database\AdvisoryLock

    # === Layer 2: Schema Management ===

    # Type extensions registered via tag
    phpbb\database\schema\type\TypeRegistry:
        arguments:
            $extensions: !tagged_iterator phpbb.database.type_extension

    phpbb\database\schema\SchemaCompiler:
        arguments:
            - '@phpbb\database\schema\type\TypeRegistry'

    # Engine-specific: DdlGenerator
    phpbb\database\schema\ddl\DdlGeneratorInterface:
        factory: ['phpbb\database\schema\ddl\DdlGeneratorFactory', 'create']
        arguments: ['@phpbb\database\ConnectionInterface', '@phpbb\database\schema\type\TypeRegistry']

    # Engine-specific: SchemaIntrospector
    phpbb\database\schema\SchemaIntrospectorInterface:
        factory: ['phpbb\database\schema\introspector\IntrospectorFactory', 'create']
        arguments: ['@phpbb\database\ConnectionInterface', '@phpbb\database\schema\type\TypeRegistry']

    phpbb\database\schema\SchemaDiffer: ~

    phpbb\database\schema\SchemaExecutor:
        arguments:
            - '@phpbb\database\ConnectionInterface'
            - '@phpbb\database\schema\ddl\DdlGeneratorInterface'
            - '@phpbb\database\schema\SchemaDiffer'
            - '@phpbb\database\migration\SnapshotManager'

    # === Layer 3: Versioning ===

    phpbb\database\migration\SnapshotManager:
        arguments: ['@phpbb\database\ConnectionInterface']

    phpbb\database\migration\MigrationTracker:
        arguments: ['@phpbb\database\ConnectionInterface']

    phpbb\database\migration\MigrationRunner:
        arguments:
            - '@phpbb\database\ConnectionInterface'
            - '@phpbb\database\migration\MigrationTracker'
            - '@phpbb\database\TransactionManagerInterface'

    # === Layer 4: Query Builder ===

    phpbb\database\query\compiler\QueryCompilerInterface:
        factory: ['phpbb\database\query\compiler\CompilerFactory', 'create']
        arguments: ['@phpbb\database\ConnectionInterface']

    phpbb\database\query\QueryFactory:
        arguments:
            - '@phpbb\database\ConnectionInterface'
            - '@phpbb\database\query\compiler\QueryCompilerInterface'

    phpbb\database\query\QueryFactoryInterface:
        alias: phpbb\database\query\QueryFactory
```

### Factory Pattern for Engine Resolution

```php
namespace phpbb\database\schema\ddl;

final class DdlGeneratorFactory
{
    public static function create(
        ConnectionInterface $connection,
        TypeRegistry $typeRegistry,
    ): DdlGeneratorInterface {
        return match ($connection->getDriver()) {
            'mysql'  => new MySQLDdlGenerator($connection->getTablePrefix(), $typeRegistry),
            'pgsql'  => new PostgreSQLDdlGenerator($connection->getTablePrefix(), $typeRegistry),
            'sqlite' => new SQLiteDdlGenerator($connection->getTablePrefix(), $typeRegistry),
            default  => throw new UnsupportedDriverException($connection->getDriver()),
        };
    }
}
```

The same factory pattern applies to `IntrospectorFactory` and `CompilerFactory`. All three resolve based on `ConnectionInterface::getDriver()`.

### Tagged Services for Type Extensions

```yaml
# Plugin registers custom types via tag
services:
    _instanceof:
        phpbb\database\schema\type\TypeExtensionInterface:
            tags: ['phpbb.database.type_extension']
```

The `TypeRegistry` receives all tagged `TypeExtensionInterface` implementations via `!tagged_iterator`, providing plugin extensibility without modifying the core `AbstractType` enum.

---

## Implementation Phases

### Phase 1: Connection + Type System (Foundation)

**Goal**: Unblock all 8 domain services and establish the type foundation for schema pipeline.

**Components**:
- `ConnectionConfig`, `ConnectionConfigFactory`
- `Connection` implementing `ConnectionInterface` (lazy PDO)
- `TransactionManager` with savepoint nesting
- `AdvisoryLock` (MySQL + PostgreSQL)
- `AbstractType` enum with all 19 cases + `toSql()`, `fromSql()`, `validate()`
- `TypeRegistry` with extension support
- DI service definitions for Layer 1

**Exit criteria**: Domain services can inject `ConnectionInterface` and execute queries. TypeRegistry passes unit tests for all 19 types × 3 engines.

### Phase 2: Schema Pipeline (Core of schema management)

**Goal**: Enable YAML → DDL generation for install and plugin lifecycle.

**Components**:
- `SchemaDefinition`, `TableDefinition`, `ColumnDefinition`, `IndexDefinition`, `ForeignKeyDefinition` (value objects)
- `SchemaCompiler` (YAML → SchemaDefinition)
- `SchemaDiffer` (two schemas → DiffOperation[])
- All `DiffOperation` types (CreateTable, DropTable, AddColumn, DropColumn, ModifyColumn, AddIndex, DropIndex, AddForeignKey, DropForeignKey)
- `AbstractDdlGenerator`, `MySQLDdlGenerator`, `PostgreSQLDdlGenerator`
- `SqliteDdlGenerator` (CREATE TABLE only for MVP)
- `MySQLIntrospector`, `PostgreSQLIntrospector`
- `SchemaExecutor` with dry-run support
- Factory classes for engine resolution

**Exit criteria**: Full round-trip test — YAML → compile → diff (against empty) → generate DDL → execute on MySQL + PostgreSQL → introspect → compare matches declared schema.

### Phase 3: Versioning (Snapshot + Migration tracking)

**Goal**: Enable core upgrades and plugin updates with tracking.

**Components**:
- `SnapshotManager` (serialize/deserialize SchemaDefinition)
- `MigrationTracker` (phpbb_schema_migrations table)
- `MigrationRunner` (discover, order, execute PHP data migrations)
- `AbstractMigration` base class
- Drift detection CLI integration

**Exit criteria**: Simulated upgrade test — old snapshot + new YAML → correct diff → correct DDL. Migration runner executes ordered PHP migrations.

### Phase 4: Query Builder (Optional, post-MVP)

**Goal**: Provide fluent query API for plugin developers and common patterns.

**Components**:
- `QueryFactory`, `SelectQuery`, `InsertQuery`, `UpdateQuery`, `DeleteQuery`
- `ExpressionBuilder`, `Raw`, `CompiledQuery`
- `MySQLCompiler`, `PostgreSQLCompiler`, `SQLiteCompiler`
- All implement `QueryCompilerInterface`

**Exit criteria**: Query builder produces correct SQL for all 3 engines across basic CRUD + joins + upsert + pagination. `Raw` escape hatch works in all positions.

### Deferred (Post-MVP)

- SQLite DdlGenerator ALTER support (table rebuild pattern)
- SQLite SchemaIntrospector
- Window functions, CTEs in query builder
- Query logging / EXPLAIN integration
- ResilientConnection (reconnection logic for CLI workers)
- Database event hooks (onQuery, onCommit)

---

## Testing Strategy

### Unit Testing

| Component | Mock Strategy | Key Assertions |
|-----------|--------------|----------------|
| `AbstractType` enum | No mocks needed — pure functions | `toSql()` returns correct SQL for all 19 types × 3 drivers; `fromSql()` round-trips; `validate()` rejects invalid column defs |
| `TypeRegistry` | Mock `TypeExtensionInterface` for extension tests | Core types delegate to enum; extensions are looked up; unknown types throw |
| `SchemaCompiler` | Mock `TypeRegistry` for type validation | Valid YAML produces correct SchemaDefinition; invalid types/formats throw meaningful errors |
| `SchemaDiffer` | No mocks — operates on value objects | Given two SchemaDefinitions, produces correct DiffOperation set; empty vs schema = all CreateTable; identical = empty diff |
| `DdlGenerator` (each engine) | Mock `TypeRegistry` for column SQL | Each DiffOperation type produces correct DDL SQL for the target engine |
| `SelectQuery` / other builders | Mock `QueryCompilerInterface` | Method chaining builds correct internal state; terminal methods call compiler |
| `QueryCompiler` (each engine) | No mocks — pure string generation | Correct identifier quoting, table prefix, LIMIT syntax, boolean rendering per engine |

### Integration Testing

| Scope | Approach | Environment |
|-------|----------|-------------|
| **Schema round-trip** | YAML → compile → DDL → execute → introspect → compare | SQLite (fast, in-process) for dev; MySQL + PostgreSQL in CI via Docker |
| **Migration execution** | Create tables → run migration → verify data | SQLite for dev; MySQL + PostgreSQL in CI |
| **Query builder SQL** | Build query → compile → execute → verify results | SQLite for dev (fast); MySQL + PostgreSQL for engine-specific features |
| **Drift detection** | Apply schema → manually alter DB → run drift → verify diff | Per-engine in CI |

### Testing Principles

1. **SQLite for fast local tests** — all CREATE TABLE tests, basic query builder tests, schema model tests run against in-memory SQLite
2. **MySQL + PostgreSQL in CI** — DDL generation, introspection, type mapping edge cases tested against real engines via Docker
3. **No database for pure unit tests** — SchemaDiffer, SchemaCompiler (with mocked TypeRegistry), AbstractType enum, DiffOperation classes are all tested without any DB
4. **Each engine tested in isolation** — MySQLDdlGenerator tested separately from PostgreSQLDdlGenerator; no cross-engine pollution
5. **Snapshot round-trip** — serialize SchemaDefinition → deserialize → compare equality

---

## Out of Scope

| Item | Rationale | Future Consideration |
|------|-----------|---------------------|
| **ORM / Entity mapping** | Explicitly rejected per project philosophy; services do manual hydration | Not planned |
| **Read/write splitting** | Single-server reality; ConnectionInterface is decorator-extensible if needed | Cloud hosting or large forums |
| **Connection pooling** | PHP request lifecycle doesn't benefit; PDO persistent connections handle this | Unlikely needed |
| **SQLite ALTER TABLE (table rebuild)** | Significant complexity; SQLite is dev/test only; CREATE TABLE sufficient for MVP | Post-MVP when test suite needs ALTER |
| **Generated columns in YAML** | Useful for search/denormalization but not in initial scope | When fulltext search requirements emerge |
| **Partial indexes** | PostgreSQL-specific optimization; not needed for initial launch | Performance optimization phase |
| **Query builder CTEs / window functions** | Advanced SQL features not needed by initial 8 services | When analytics or recursive queries appear |
| **Database event hooks** | onQuery/onCommit callbacks for audit/profiling | When cross-cutting observation is needed |
| **Column rename detection in diff** | Heuristic-based, error-prone; require explicit `renames:` in YAML instead | When schema evolution patterns stabilize |
| **Multi-tenant / sharding** | phpBB is single-database; no multi-tenant requirements | Extremely unlikely |

---

## Success Criteria

1. **All 19 AbstractType cases produce correct SQL** for MySQL, PostgreSQL, and SQLite — verified by unit tests with full type × engine matrix
2. **Schema round-trip is lossless** — YAML → compile → DDL → execute → introspect → compare matches declared schema (MySQL + PostgreSQL)
3. **Plugin install/update/uninstall** works via SchemaEngine facade without plugin authors touching DDL
4. **Schema diff is deterministic** — same YAML versions always produce identical DiffOperation[] from snapshots
5. **Drift detection identifies undeclared DB changes** via live introspection path
6. **Query builder produces correct, parameterized SQL** for all 3 engines — no SQL injection possible through builder API
