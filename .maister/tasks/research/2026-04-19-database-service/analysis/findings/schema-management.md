# Schema Management Patterns: YAML→DDL Compilation, Diffing & Generation

## 1. YAML Schema Format Specification

### Recommended Structure (from plugin-system design + industry patterns)

```yaml
tables:
    poll_options:                         # table name (auto-prefixed)
        columns:
            option_id:
                type: uint               # abstract type mapped per-platform
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
            metadata:
                type: json
                nullable: true
        primary_key: [option_id]
        indexes:
            idx_topic: [topic_id]
            idx_topic_votes: [topic_id, vote_count]
        unique_keys:
            uq_topic_text: [topic_id, option_text]
        foreign_keys:
            fk_topic:
                columns: [topic_id]
                references:
                    table: topics
                    columns: [topic_id]
                on_delete: CASCADE
                on_update: CASCADE
        options:
            engine: InnoDB              # MySQL only, ignored on PG
            charset: utf8mb4
            collation: utf8mb4_unicode_ci
```

### Column Properties

| Property | Type | Required | Description |
|----------|------|----------|-------------|
| `type` | string | Yes | Abstract type (see type map below) |
| `length` | int | Conditional | Required for `varchar`, optional for `decimal(p,s)` |
| `precision` | int | Conditional | For `decimal` type |
| `scale` | int | Conditional | For `decimal` type |
| `nullable` | bool | No | Default `false` (NOT NULL) |
| `default` | mixed | No | Default value; `null` if nullable, no default otherwise |
| `auto_increment` | bool | No | Default `false` |
| `unsigned` | bool | No | Default `false` (alias: `type: uint` implies unsigned) |
| `comment` | string | No | Column comment (stored in DDL) |

### Abstract Type System

| YAML Type | MySQL | PostgreSQL | SQLite |
|-----------|-------|------------|--------|
| `uint` | `INT UNSIGNED` | `INTEGER CHECK (col >= 0)` | `INTEGER` |
| `int` | `INT` | `INTEGER` | `INTEGER` |
| `smallint` | `SMALLINT` | `SMALLINT` | `INTEGER` |
| `bigint` | `BIGINT` | `BIGINT` | `INTEGER` |
| `bool` | `TINYINT(1)` | `BOOLEAN` | `INTEGER` |
| `varchar` | `VARCHAR(n)` | `VARCHAR(n)` | `TEXT` |
| `text` | `MEDIUMTEXT` | `TEXT` | `TEXT` |
| `longtext` | `LONGTEXT` | `TEXT` | `TEXT` |
| `decimal` | `DECIMAL(p,s)` | `NUMERIC(p,s)` | `REAL` |
| `float` | `FLOAT` | `REAL` | `REAL` |
| `datetime` | `INT` (unix ts) | `INTEGER` | `INTEGER` |
| `timestamp` | `TIMESTAMP` | `TIMESTAMPTZ` | `TEXT` |
| `json` | `JSON` | `JSONB` | `TEXT` |
| `blob` | `BLOB` | `BYTEA` | `BLOB` |

### Constraints Expression

```yaml
# NOT NULL: default behavior, explicit via nullable: false
# DEFAULT: explicit default property
# UNIQUE: via unique_keys section (composite) or column-level unique: true (single)
# CHECK: future enhancement (not MVP)
```

### Index Expression

```yaml
indexes:
    # Simple index
    idx_user: [user_id]
    # Composite index (column order matters for leftmost-prefix)
    idx_topic_created: [topic_id, created_at]
    # Partial length (MySQL):
    idx_title:
        columns: [title]
        length: { title: 100 }

unique_keys:
    uq_email: [email]
    uq_slug_parent: [slug, parent_id]
```

### Foreign Key Expression

```yaml
foreign_keys:
    fk_topic:
        columns: [topic_id]
        references:
            table: topics          # logical name (auto-prefixed)
            columns: [topic_id]
        on_delete: CASCADE         # CASCADE | SET NULL | RESTRICT | NO ACTION
        on_update: CASCADE
```

### Table Options

```yaml
options:
    engine: InnoDB          # MySQL: InnoDB (default), MyISAM
    charset: utf8mb4        # MySQL charset
    collation: utf8mb4_unicode_ci
    # PostgreSQL: tablespace, fillfactor (rare, usually omitted)
```

---

## 2. Compilation Pipeline Design

### Architecture Overview

```
schema.yml → SchemaCompiler → SchemaDefinition (in-memory model)
                                       ↓
DB → SchemaIntrospector → TableDefinition (current state)
                                       ↓
SchemaDefinition + Current State → SchemaDiffer → DiffOperation[]
                                       ↓
DiffOperation[] → DdlGenerator (platform-specific) → SQL string[]
                                       ↓
SQL[] → SchemaExecutor → executes against DB
```

### Component Data Flow

#### SchemaCompiler (YAML → Model)

**Responsibilities:**
1. Parse YAML (Symfony YAML component)
2. Validate structure (column types exist, PKs reference valid columns, indexes reference valid columns)
3. Resolve type aliases (`uint` → `{type: int, unsigned: true}`)
4. Produce immutable `SchemaDefinition` value object

**Doctrine parallel:** `Doctrine\DBAL\Schema\Schema` is built programmatically:
```php
$schema = new Schema();
$table = $schema->createTable('users');
$table->addColumn('id', 'integer', ['autoincrement' => true, 'unsigned' => true]);
$table->addColumn('name', 'string', ['length' => 255]);
$table->setPrimaryKey(['id']);
```

**Laravel parallel:** `Blueprint` accumulates column definitions:
```php
Schema::create('users', function (Blueprint $table) {
    $table->increments('id');
    $table->string('name', 255);
});
```

Both accumulate an in-memory representation before generating SQL.

#### SchemaIntrospector (DB → Model)

**Doctrine approach:** `Doctrine\DBAL\Schema\AbstractSchemaManager::introspectSchema()`
- Queries `INFORMATION_SCHEMA.TABLES`, `INFORMATION_SCHEMA.COLUMNS`, `INFORMATION_SCHEMA.STATISTICS` (indexes), `INFORMATION_SCHEMA.KEY_COLUMN_USAGE` (FKs)
- Returns a `Schema` object representing current DB state
- Platform-specific managers: `MySQLSchemaManager`, `PostgreSQLSchemaManager`

**Key queries (MySQL):**
```sql
-- Columns
SELECT * FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table;

-- Indexes
SELECT * FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table;

-- Foreign keys
SELECT * FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = :db AND REFERENCED_TABLE_NAME IS NOT NULL;
```

**PostgreSQL equivalent:** Uses `pg_catalog.pg_class`, `pg_catalog.pg_attribute`, `pg_catalog.pg_index`.

#### Type Resolution Chain

```
Abstract Type (YAML) → TypeRegistry.resolve(name)
    → AbstractType instance (e.g., IntegerType, StringType)
        → Platform.getColumnDeclarationSQL(column, type)
            → Platform-specific SQL fragment ("INT UNSIGNED", "INTEGER", etc.)
```

**Doctrine's pattern:**
- `Type` base class with `getSQLDeclaration(array $column, AbstractPlatform $platform)`
- Each type delegates to the platform for final SQL rendering
- Platform decides modifiers (e.g., MySQL adds `UNSIGNED`, PG uses CHECK constraint)

**Visitor vs Strategy pattern:**
- Doctrine uses **Strategy pattern**: each `AbstractPlatform` subclass overrides SQL generation methods
- NOT Visitor pattern — operations don't "visit" the platform; platform methods are called directly
- Laravel also uses Strategy: each `Grammar` subclass (`MySqlGrammar`, `PostgresGrammar`) compiles Blueprint to SQL

### Platform Abstraction Pattern (Strategy)

```php
interface DdlGeneratorInterface
{
    /** @return string[] SQL statements for this operation */
    public function generate(DiffOperation $operation): array;
}

class MysqlDdlGenerator implements DdlGeneratorInterface
{
    public function generate(DiffOperation $op): array
    {
        return match (true) {
            $op instanceof CreateTable => $this->generateCreateTable($op),
            $op instanceof AddColumn => $this->generateAddColumn($op),
            // ...
        };
    }
}

class PostgresDdlGenerator implements DdlGeneratorInterface { /* ... */ }
```

**Factory selection:**
```php
$generator = match ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) {
    'mysql' => new MysqlDdlGenerator($tablePrefix),
    'pgsql' => new PostgresDdlGenerator($tablePrefix),
    default => throw new UnsupportedPlatformException(),
};
```

---

## 3. Schema Diffing Algorithm

### How Doctrine Comparator Works

**Source:** `Doctrine\DBAL\Schema\Comparator::compareSchemas(Schema $fromSchema, Schema $toSchema)`

**Algorithm:**
1. **Tables added:** Tables in `$toSchema` not in `$fromSchema` → `CreateTable`
2. **Tables removed:** Tables in `$fromSchema` not in `$toSchema` → `DropTable`
3. **Tables modified:** Tables in both → `diffTable()`:
   - **Columns added:** In new, not in old
   - **Columns removed:** In old, not in new
   - **Columns changed:** In both, but properties differ (call `diffColumn()`)
   - **Indexes added/removed/changed:** Compare index sets
   - **Foreign keys added/removed/changed:** Compare FK sets

#### Column Diff Detection

```php
// Doctrine Comparator::diffColumn()
private function columnsEqual(Column $column1, Column $column2): bool
{
    // Compare: type, length, precision, scale, unsigned,
    //          fixed, notnull, default, autoincrement,
    //          platformOptions, comment
    return $this->columnTypeEqual($column1, $column2)
        && $column1->getLength() === $column2->getLength()
        && $column1->getPrecision() === $column2->getPrecision()
        && $column1->getScale() === $column2->getScale()
        && $column1->getUnsigned() === $column2->getUnsigned()
        && $column1->getNotnull() === $column2->getNotnull()
        && $this->defaultValueEqual($column1, $column2)
        && $column1->getAutoincrement() === $column2->getAutoincrement();
}
```

#### Index Diff Detection

Indexes are compared by:
1. Column list (order matters)
2. Uniqueness flag
3. Index type (btree, hash, fulltext)
4. Index flags/options

An index is "changed" if same name but different columns/options → generates `DropIndex` + `AddIndex`.

### Full Operation Set

| Operation | Trigger | Destructive? |
|-----------|---------|-------------|
| `CreateTable` | Table in declared, not in DB | No |
| `DropTable` | Table in DB, not in declared (uninstall) | **Yes** |
| `AddColumn` | Column in declared, not in DB table | No |
| `DropColumn` | Column in DB, not in declared | **Yes** |
| `ModifyColumn` | Column exists in both, properties differ | **Maybe** (narrowing = destructive) |
| `RenameColumn` | Heuristic or explicit mapping | **Maybe** |
| `AddIndex` | Index in declared, not in DB | No |
| `DropIndex` | Index in DB, not in declared | No (data-safe) |
| `AddUniqueKey` | Unique key in declared, not in DB | **Maybe** (fails if duplicates exist) |
| `DropUniqueKey` | Unique key in DB, not in declared | No |
| `AddForeignKey` | FK in declared, not in DB | **Maybe** (fails if orphans exist) |
| `DropForeignKey` | FK in DB, not in declared | No |
| `ChangeDefault` | Default value differs | No |
| `ChangeNullability` | nullable flag differs | **Maybe** (NOT NULL if NULLs exist) |

### Compatible vs Breaking Changes

**Safe (auto-apply):**
- `AddColumn` (with DEFAULT or nullable)
- `AddIndex`, `DropIndex`
- `DropForeignKey`
- Widening type (INT → BIGINT, VARCHAR(100) → VARCHAR(255))
- Adding DEFAULT
- NOT NULL → nullable

**Potentially destructive (require confirmation):**
- `DropColumn` — data loss
- `DropTable` — data loss
- Narrowing type (BIGINT → INT, VARCHAR(255) → VARCHAR(100)) — truncation
- nullable → NOT NULL — fails if NULLs exist
- `AddUniqueKey` — fails if duplicates exist
- `AddForeignKey` — fails if orphans exist

**Cannot auto-detect (require explicit mapping):**
- Column rename (looks like drop + add)
- Table rename

### Diff Algorithm Pseudocode

```
function diff(declared: SchemaDefinition, current: Introspected): DiffOperation[]
    ops = []

    for table in declared.tables:
        currentTable = current.getTable(prefix + table.name)
        if currentTable is null:
            ops.push(CreateTable(table))
            continue

        // Column diff
        for col in table.columns:
            currentCol = currentTable.getColumn(col.name)
            if currentCol is null:
                ops.push(AddColumn(table.name, col))
            else if !columnsEqual(col, currentCol):
                ops.push(ModifyColumn(table.name, currentCol, col))

        for col in currentTable.columns:
            if col.name not in table.columns:
                ops.push(DropColumn(table.name, col.name))  // flag destructive

        // Index diff
        for idx in table.indexes:
            if idx not in currentTable.indexes:
                ops.push(AddIndex(table.name, idx))
        for idx in currentTable.indexes:
            if idx not in table.indexes:
                ops.push(DropIndex(table.name, idx.name))

        // FK diff (similar)
        // Unique key diff (similar)

    return orderOperations(ops)  // topological sort for FK dependencies
```

---

## 4. DDL Generation Strategy Pattern

### Platform Strategy Selection

```php
interface DdlGeneratorInterface
{
    /** Generate SQL for a single diff operation */
    public function generate(DiffOperation $operation): array;

    /** Get column type SQL fragment */
    public function getColumnTypeSQL(ColumnDefinition $col): string;

    /** Get full column declaration (type + modifiers) */
    public function getColumnDeclarationSQL(ColumnDefinition $col): string;
}
```

### MySQL-Specific Generation

```php
final class MysqlDdlGenerator implements DdlGeneratorInterface
{
    public function generateCreateTable(CreateTable $op): array
    {
        $table = $op->table;
        $sql = "CREATE TABLE {$this->prefix}{$table->name} (\n";

        // Columns
        foreach ($table->columns as $col) {
            $sql .= "    {$col->name} {$this->getColumnDeclarationSQL($col)},\n";
        }

        // Primary key
        $sql .= "    PRIMARY KEY (" . implode(', ', $table->primaryKey) . ")";

        // Indexes inline
        foreach ($table->indexes as $name => $columns) {
            $sql .= ",\n    INDEX {$name} (" . implode(', ', $columns) . ")";
        }

        // Unique keys inline
        foreach ($table->uniqueKeys as $name => $columns) {
            $sql .= ",\n    UNIQUE KEY {$name} (" . implode(', ', $columns) . ")";
        }

        $sql .= "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        return [$sql];
    }

    public function getColumnTypeSQL(ColumnDefinition $col): string
    {
        return match ($col->type) {
            'uint'     => 'INT UNSIGNED',
            'int'      => 'INT',
            'bigint'   => 'BIGINT',
            'smallint' => 'SMALLINT',
            'bool'     => 'TINYINT(1)',
            'varchar'  => "VARCHAR({$col->length})",
            'text'     => 'MEDIUMTEXT',
            'longtext' => 'LONGTEXT',
            'decimal'  => "DECIMAL({$col->precision},{$col->scale})",
            'json'     => 'JSON',
            'datetime' => 'INT UNSIGNED',  // unix timestamp
            'blob'     => 'BLOB',
        };
    }

    public function getColumnDeclarationSQL(ColumnDefinition $col): string
    {
        $sql = $this->getColumnTypeSQL($col);

        if (!$col->nullable) {
            $sql .= ' NOT NULL';
        }

        if ($col->default !== null) {
            $sql .= ' DEFAULT ' . $this->quoteDefault($col->default, $col->type);
        } elseif ($col->nullable) {
            $sql .= ' DEFAULT NULL';
        }

        if ($col->autoIncrement) {
            $sql .= ' AUTO_INCREMENT';
        }

        return $sql;
    }
}
```

### PostgreSQL-Specific Generation

```php
final class PostgresDdlGenerator implements DdlGeneratorInterface
{
    public function getColumnTypeSQL(ColumnDefinition $col): string
    {
        return match ($col->type) {
            'uint'     => 'INTEGER',  // no unsigned, CHECK added separately
            'int'      => 'INTEGER',
            'bigint'   => 'BIGINT',
            'smallint' => 'SMALLINT',
            'bool'     => 'BOOLEAN',
            'varchar'  => "VARCHAR({$col->length})",
            'text'     => 'TEXT',
            'longtext' => 'TEXT',
            'decimal'  => "NUMERIC({$col->precision},{$col->scale})",
            'json'     => 'JSONB',
            'datetime' => 'INTEGER',
            'blob'     => 'BYTEA',
        };
    }

    public function getColumnDeclarationSQL(ColumnDefinition $col): string
    {
        if ($col->autoIncrement) {
            // Use SERIAL/BIGSERIAL for auto-increment
            return match ($col->type) {
                'bigint' => 'BIGSERIAL',
                default  => 'SERIAL',
            };
        }

        $sql = $this->getColumnTypeSQL($col);

        // Add unsigned CHECK constraint for uint
        if ($col->type === 'uint') {
            $sql .= " CHECK ({$col->name} >= 0)";
        }

        if (!$col->nullable) {
            $sql .= ' NOT NULL';
        }

        if ($col->default !== null) {
            $sql .= ' DEFAULT ' . $this->quoteDefault($col->default, $col->type);
        } elseif ($col->nullable) {
            $sql .= ' DEFAULT NULL';
        }

        return $sql;
    }
}
```

### Engine-Specific Differences

| Feature | MySQL | PostgreSQL | SQLite |
|---------|-------|------------|--------|
| Unsigned integers | `INT UNSIGNED` | `CHECK (col >= 0)` | N/A (no type enforcement) |
| Auto-increment | `AUTO_INCREMENT` | `SERIAL` / `GENERATED ALWAYS AS IDENTITY` | `INTEGER PRIMARY KEY` (implicit ROWID) |
| Boolean | `TINYINT(1)` | `BOOLEAN` | `INTEGER` |
| JSON | `JSON` (validated) | `JSONB` (binary, indexable) | `TEXT` (no validation) |
| Text sizes | `TEXT` / `MEDIUMTEXT` / `LONGTEXT` | `TEXT` (all same) | `TEXT` |
| ALTER COLUMN | `ALTER TABLE .. MODIFY COLUMN` | `ALTER TABLE .. ALTER COLUMN .. TYPE` | Requires table rebuild |
| Multiple ALTERs | Single `ALTER TABLE` multi-clause | Separate statements | Table rebuild |
| IF NOT EXISTS | `CREATE TABLE IF NOT EXISTS` | `CREATE TABLE IF NOT EXISTS` | `CREATE TABLE IF NOT EXISTS` |
| Index type | `USING BTREE` / `USING HASH` | `USING btree` / `USING gin` / `USING gist` | B-tree only |

### Statement Ordering (FK Dependency Resolution)

Foreign keys require tables to exist before referencing them. The DDL generator must:

1. **Topological sort** `CreateTable` operations by FK dependencies
2. **Separate FK creation** from table creation if circular references exist:
   ```sql
   CREATE TABLE orders (...);                    -- first
   CREATE TABLE order_items (...);               -- depends on orders
   ALTER TABLE orders ADD CONSTRAINT fk_...; -- add FKs after all tables exist
   ```
3. **Drop FKs before dropping tables** they reference
4. **Drop indexes before dropping columns** they reference

**Algorithm:**
```
function orderOperations(ops: DiffOperation[]): DiffOperation[]
    // Phase 1: Drop FKs
    // Phase 2: Drop indexes
    // Phase 3: Drop columns
    // Phase 4: Create tables (topologically sorted by FK refs)
    // Phase 5: Add columns
    // Phase 6: Modify columns
    // Phase 7: Add indexes
    // Phase 8: Add FKs (after all tables/columns exist)
    // Phase 9: Drop tables (reverse FK order)
```

---

## 5. Safety Mechanisms

### Destructive Change Handling

#### Classification

Every `DiffOperation` is classified:

```php
enum OperationRisk: string
{
    case SAFE = 'safe';               // AddColumn, AddIndex
    case REQUIRES_CHECK = 'check';    // AddUniqueKey (check for duplicates first)
    case DESTRUCTIVE = 'destructive'; // DropColumn, DropTable
    case IRREVERSIBLE = 'irreversible'; // DropTable with no backup
}
```

#### Dry-Run Mode

Before executing, generate DDL and present to admin:

```php
interface SchemaEngineInterface
{
    /** Preview operations without executing */
    public function dryRun(string $schemaYamlPath): DryRunResult;
}

final readonly class DryRunResult
{
    public function __construct(
        /** @var DiffOperation[] */
        public array $operations,
        /** @var string[] Generated SQL */
        public array $sql,
        /** @var DiffOperation[] Operations classified as destructive */
        public array $destructiveOperations,
        /** @var bool Whether confirmation is needed */
        public bool $requiresConfirmation,
    ) {}
}
```

#### Confirmation Flow

```
1. Plugin update detected
2. SchemaDiffer produces operations
3. If any operation.risk == DESTRUCTIVE:
   a. Present dry-run output to admin
   b. List data that will be lost
   c. Require explicit confirmation (e.g., checkbox "I understand data will be lost")
   d. Log confirmation audit trail
4. Execute only after confirmation
```

#### Pre-Flight Checks

Before executing potentially failing operations:

```php
// Before AddUniqueKey:  check for duplicates
SELECT col1, col2, COUNT(*) FROM table GROUP BY col1, col2 HAVING COUNT(*) > 1;

// Before NOT NULL:  check for existing NULLs
SELECT COUNT(*) FROM table WHERE col IS NULL;

// Before AddForeignKey:  check for orphan rows
SELECT COUNT(*) FROM child WHERE fk_col NOT IN (SELECT pk FROM parent);
```

If pre-flight check fails → abort with actionable error message rather than letting DDL fail cryptically.

#### Backup Recommendation

For destructive operations, recommend (but don't enforce):
- `mysqldump --single-transaction --tables table_name` before `DropTable`
- `SELECT * INTO backup_table FROM original_table` before major modifications

#### Transaction Handling

```php
// Wrap each independent operation in a transaction (MySQL DDL is not transactional by default)
// DDL is auto-committed in MySQL — use savepoints where possible
// PostgreSQL: DDL IS transactional — wrap entire batch in BEGIN/COMMIT

// Strategy:
if ($platform === 'pgsql') {
    // Single transaction for all operations
    $db->beginTransaction();
    foreach ($operations as $op) { execute($op); }
    $db->commit();
} else {
    // MySQL: per-operation execution, manual rollback tracking
    foreach ($operations as $op) {
        $this->recordRollbackInfo($op);
        execute($op);
    }
}
```

#### Rollback Tracking

Store reverse operations alongside execution:

| Forward Operation | Automatic Reverse |
|-------------------|-------------------|
| `CreateTable(t)` | `DropTable(t)` |
| `AddColumn(t,c)` | `DropColumn(t,c.name)` |
| `AddIndex(t,i)` | `DropIndex(t,i.name)` |
| `DropColumn(t,c)` | ⚠️ Cannot recover data |
| `ModifyColumn(t,old,new)` | `ModifyColumn(t,new,old)` |
| `DropTable(t)` | ⚠️ Cannot recover data |

---

## 6. Prior Art Comparison Table

| Aspect | Doctrine DBAL | Laravel Schema Builder | Phinx |
|--------|--------------|----------------------|-------|
| **Schema Definition** | Programmatic PHP (`Schema` + `Table` + `Column` objects) | `Blueprint` class in closures | PHP `change()` method with `$table->addColumn()` |
| **Declarative vs Imperative** | Supports both (Schema for introspect/diff, migrations for imperative) | Imperative (migrations with `up()`/`down()`) | Imperative (but supports `change()` for auto-reversible) |
| **Diff Capability** | Yes — `Comparator::compareSchemas()` produces `SchemaDiff` | No built-in diffing (Laravel has `doctrine/dbal` as optional dep for column changes) | No diffing — manual migrations only |
| **Platform Support** | MySQL, PostgreSQL, SQLite, Oracle, SQL Server | MySQL, PostgreSQL, SQLite, SQL Server | MySQL, PostgreSQL, SQLite, SQL Server |
| **Type System** | Rich abstract types (`Types\IntegerType`, `Types\StringType`, etc.) | Method-based (`$table->integer()`, `$table->string()`) | Method-based with type constants |
| **DDL Generation** | `AbstractPlatform::getCreateTableSQL()` — per-platform implementations | `Grammar` subclasses (`MySqlGrammar`, `PostgresGrammar`) | Adapter pattern (`MysqlAdapter`, `PostgresAdapter`) |
| **Introspection** | Yes — `AbstractSchemaManager::introspectSchema()` | Limited (via optional doctrine/dbal package) | Yes — for `change()` method reverse-engineering |
| **Rollback** | No automatic rollback (SchemaDiff is one-directional) | `down()` method per migration (manual) | `change()` auto-reversible or explicit `down()` |
| **Foreign Keys** | `$table->addForeignKeyConstraint()` | `$table->foreign()->references()->on()` | `$table->addForeignKey()` |
| **Index Support** | Full (unique, composite, partial, fulltext) | Full (unique, composite, fulltext) | Full |
| **JSON Column** | Yes (maps to platform JSON/JSONB) | `$table->json()` | `$table->addColumn('data', 'json')` |
| **Transactions** | Manual | Automatic per migration on PG, not MySQL | Manual |

### Key Patterns to Adopt

**From Doctrine:**
- `Comparator` diffing algorithm — most mature, battle-tested
- Platform strategy pattern for DDL generation
- INFORMATION_SCHEMA introspection
- Type abstraction layer (abstract → platform mapping)
- `SchemaDiff` → ordered operations

**From Laravel:**
- Clean, readable column modifier API (inspiration for YAML syntax)
- Grammar compilation pattern (Blueprint → SQL)
- Fluent foreign key declaration syntax

**From Phinx:**
- `change()` method concept (auto-reversible operations)
- Operation classification (reversible vs non-reversible)
- Step-based execution with progress tracking

**From dbmate/golang-migrate (conceptual):**
- File-based schema declarations (YAML / SQL)
- Schema state tracking in a dedicated DB table
- Up/down migration ordering by version
- Dry-run output before execution

### Recommended Approach for phpBB Plugin System

1. **Declarative YAML** (like Doctrine Schema but file-based, like dbmate)
2. **State diffing** (Doctrine Comparator algorithm)
3. **Platform generators** (Doctrine/Laravel Strategy pattern)
4. **Complementary PHP data migrations** (Phinx's step concept)
5. **Dry-run + confirmation** (safety-first for destructive ops)
6. **Schema snapshots** (stored in `phpbb_plugins.state` for accurate diffing on update)

---

## Sources

### Internal
- `.maister/tasks/research/2026-04-19-plugin-system/outputs/high-level-design.md` — Schema System section (lines 596–850+): YAML format, SchemaCompiler, SchemaIntrospector, SchemaDiffer, DdlGenerator, SchemaExecutor, DataMigrationRunner, operation types, diff rules, lifecycle integration

### External (Knowledge-Based)
- Doctrine DBAL: `Doctrine\DBAL\Schema\Schema`, `Comparator`, `AbstractPlatform`, `AbstractSchemaManager`
- Laravel: `Illuminate\Database\Schema\Blueprint`, `Grammars\MySqlGrammar`, `Grammars\PostgresGrammar`
- Phinx: `Phinx\Db\Table`, `Phinx\Db\Adapter\MysqlAdapter`, `change()` method
- dbmate: SQL file-based migrations, schema dump approach
