# Schema Versioning & Connection Management Findings

## 1. Schema Versioning Strategy Comparison

### Versioning Naming Approaches

| Strategy | Example | Pros | Cons | Used By |
|----------|---------|------|------|---------|
| **Timestamp-based** | `20260419120000_create_users` | No conflicts in teams, natural ordering | Long filenames, not human-friendly | Phinx, Laravel, dbmate |
| **Sequential numbering** | `001_create_users.sql` | Simple, readable | Merge conflicts in teams | Flyway (V1__, V2__), Rails |
| **Semantic versioning** | `1.0.0`, `1.1.0` | Aligns with release versions | Doesn't scale for many small changes | Doctrine Migrations (optional), Plugin HLD |
| **Hash-based** | `a3f2b1c4` (content hash) | Detects tampered migrations | Not human-orderable, fragile | Flyway checksums (validation) |

### Tracking Mechanisms Comparison

| System | Table Name | Columns | Ordering | Unique Feature |
|--------|-----------|---------|----------|----------------|
| **Phinx** | `phinxlog` | version, migration_name, start_time, end_time, breakpoint | Timestamp (14-char YYYYMMDDHHMMSS) | Breakpoints for partial rollback |
| **Laravel** | `migrations` | id, migration, batch | Batch number + order within batch | Batch grouping for multi-file rollback |
| **Doctrine Migrations** | `doctrine_migration_versions` | version, executed_at, execution_time | Namespace-qualified class name | Dependency graph resolution |
| **Flyway** | `flyway_schema_history` | installed_rank, version, description, type, script, checksum, installed_by, installed_on, execution_time, success | Version string sort | Checksums for integrity, repeatable migrations |
| **dbmate** | `schema_migrations` | version | Simple timestamp string | Schema dump file (`schema.sql`) for fresh deploys |
| **Liquibase** | `DATABASECHANGELOG` | id, author, filename, dateexecuted, orderexecuted, exectype, md5sum, description, comments, tag, liquibase, contexts, labels, deployment_id | Execution order | Contexts/labels for conditional application |

### Migration Execution Patterns

| Pattern | Description | Reversibility | Used By |
|---------|-------------|---------------|---------|
| **Up/Down** | Explicit `up()` and `down()` methods | Manual (developer writes reverse) | Phinx, Laravel, Doctrine, Rails |
| **Change** (auto-reversible) | Single `change()` method; framework infers reverse | Automatic for supported ops | Phinx, Rails |
| **Forward-only** | No down migration; fixes deployed as new migrations | N/A — never rolls back | Flyway (recommended mode), production best practice |
| **Declarative diff** | Compare desired state YAML vs live DB; generate DDL | Implicit (revert = apply old YAML) | Our plugin system (SchemaCompiler+SchemaDiffer) |

### Diff Computation Approaches

| Approach | Description | Pros | Cons |
|----------|-------------|------|------|
| **Snapshot-based** | Store full schema at each version; diff two snapshots | Reproducible, offline-capable | Storage overhead, stale if snapshots diverge |
| **Changelog-based** | Track each change operation explicitly | Audit trail, deterministic replay | Cannot detect drift from manual changes |
| **Live diff** | Compare YAML/model definition against current DB state via introspection | Always accurate, detects manual drift | Requires DB connection at diff time, introspection overhead |
| **Hybrid (our approach)** | Store schema snapshot in `phpbb_plugins.state` JSON + live introspection fallback | Best of both — accurate + offline-capable for plugin diffs | Slightly more complex state management |

**Evidence** (Plugin System HLD, §Schema Versioning):
> "Storing a schema snapshot ensures that even if the plugin's `schema.yml` is updated on disk before the update process runs, the differ can produce an accurate diff."

### Rollback Strategies

| Strategy | Description | When to Use | Risk |
|----------|-------------|-------------|------|
| **Down migrations** | Each migration has explicit undo logic | Dev/staging environments | Data loss if down drops columns with data |
| **Forward-only** | Never roll back; deploy fixes as new migrations | Production | Safest for data integrity |
| **Point-in-time** | Restore DB backup to specific timestamp | Disaster recovery | Loses all changes since backup |
| **Conditional rollback** | Only revert structure (not data-destructive ops) | Production rollback of feature flags | Complex to implement correctly |
| **Schema snapshot restore** | Apply previous schema YAML (our declarative approach) | Plugin downgrade | SchemaDiffer handles automatically |

---

## 2. Recommended Versioning Approach (phpBB Context)

### Core Schema (phpBB itself)

**Strategy: Hybrid Declarative + Imperative**

1. **Primary**: Declarative YAML defines the *desired state* of all core tables
2. **Versioning**: Semantic version tied to phpBB release (`5.0.0`, `5.1.0`)
3. **Diff**: `SchemaDiffer` computes DDL from old→new schema YAML
4. **Data Migrations**: Imperative PHP classes for data transforms (backfills, renames, seeds)
5. **Tracking**: Single version number in `phpbb_config` table (key: `schema_version`)

**Rationale**:
- phpBB releases as a whole — not granular per-table migrations
- Declarative YAML is already established in the plugin system HLD
- Forward-only in production; down migrations only for dev convenience

### Plugin Schema (per-plugin)

**Strategy: Declarative + Per-Plugin Version**

1. Each plugin declares `schema.yml` (desired state)
2. Version tracked in `phpbb_plugins.plugin_version` column
3. Schema snapshot stored in `phpbb_plugins.state` JSON (for accurate diffs during updates)
4. On install: full `CreateTable` operations
5. On update: diff old snapshot → new YAML → incremental DDL
6. On uninstall: `DropTable` (if `keepData=false`)

**Already established** — Plugin System HLD §Schema Lifecycle Integration.

### Version Tracking Table Design

```sql
-- Core schema version tracking
-- (For the phpBB core itself — simple key in phpbb_config)
-- phpbb_config: config_name='schema_version', config_value='5.0.0'

-- Data migration tracking (for imperative PHP migrations)
CREATE TABLE phpbb_schema_migrations (
    migration_id    VARCHAR(255)    NOT NULL,   -- fully-qualified class name
    version         VARCHAR(50)     NOT NULL,   -- semver this migration belongs to
    applied_at      INT UNSIGNED    NOT NULL,   -- unix timestamp
    execution_ms    INT UNSIGNED    NOT NULL DEFAULT 0,
    checksum        VARCHAR(64)     DEFAULT NULL, -- SHA-256 of migration file (tamper detection)
    batch           INT UNSIGNED    NOT NULL DEFAULT 1,  -- for grouped rollback
    PRIMARY KEY (migration_id),
    INDEX idx_version (version),
    INDEX idx_batch (batch)
);
```

**Design decisions**:
- `migration_id` = FQCN (e.g. `phpbb\migration\v500\CreateForumsTable`) — deterministic, unique
- `version` = semver group — allows "rollback all migrations for version X"
- `batch` = Laravel-style grouping — rollback last applied batch as a unit
- `checksum` = Flyway-style integrity check — detect if migration file changed after apply
- `execution_ms` = diagnostic — identify slow migrations

---

## 3. Version Tracking Table Design (Extended)

### Multi-Environment Awareness

For environments that need to track deployment state:

```sql
-- Optional: only needed if environment-aware deployment tracking is required
CREATE TABLE phpbb_schema_deployments (
    deployment_id   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    environment     VARCHAR(50)     NOT NULL,   -- 'production', 'staging', 'dev'
    schema_version  VARCHAR(50)     NOT NULL,
    deployed_at     INT UNSIGNED    NOT NULL,
    deployed_by     VARCHAR(100)    DEFAULT NULL,
    PRIMARY KEY (deployment_id),
    INDEX idx_env_version (environment, schema_version)
);
```

**Recommendation**: Skip this for v1. phpBB is typically single-environment per installation. If needed later, it's a simple additive schema change.

### Plugin Version Tracking (already designed)

From Plugin System HLD — the `phpbb_plugins` table handles per-plugin versioning:

```sql
-- Already defined in Plugin System HLD
CREATE TABLE phpbb_plugins (
    plugin_id       INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    plugin_name     VARCHAR(100)    NOT NULL,   -- 'acme/polls'
    plugin_version  VARCHAR(50)     NOT NULL,   -- '1.2.3'
    state           JSON            NOT NULL,   -- includes schema snapshot
    enabled         TINYINT(1)      NOT NULL DEFAULT 1,
    installed_at    INT UNSIGNED    NOT NULL,
    updated_at      INT UNSIGNED    NOT NULL,
    PRIMARY KEY (plugin_id),
    UNIQUE KEY uq_name (plugin_name)
);
```

The `state` JSON field stores the last-applied schema YAML (parsed to JSON) for accurate diffing.

---

## 4. Diff Computation Strategy

### Recommended: Hybrid Snapshot + Live Introspection

```
┌─────────────────────────────────────────────────────────────┐
│                    Schema Diff Pipeline                       │
│                                                              │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐  │
│  │ New schema   │    │ Old schema   │    │ Live DB      │  │
│  │ (YAML file)  │    │ (snapshot    │    │ (introspect  │  │
│  │              │    │  from state) │    │  if needed)  │  │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘  │
│         │                   │                   │           │
│         ▼                   ▼                   ▼           │
│  ┌──────────────────────────────────────────────────────┐   │
│  │                  SchemaDiffer                         │   │
│  │                                                      │   │
│  │  Primary path: diff(new YAML, old snapshot)          │   │
│  │  Fallback: diff(new YAML, live introspection)        │   │
│  │                                                      │   │
│  │  Output: DiffOperation[]                             │   │
│  └──────────────────────────┬───────────────────────────┘   │
│                             │                               │
│                             ▼                               │
│  ┌──────────────────────────────────────────────────────┐   │
│  │              DdlGenerator (per-engine)                │   │
│  │              MySQL / PostgreSQL / SQLite              │   │
│  └──────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

**When to use each path**:

| Scenario | Diff Source | Reason |
|----------|-------------|--------|
| Plugin update (`1.0.0` → `1.1.0`) | Old snapshot from `state` JSON vs new `schema.yml` | Accurate, doesn't require live DB access at diff-plan time |
| Fresh install | New YAML only → all `CreateTable` ops | No old state exists |
| Uninstall | Current snapshot → empty = all `DropTable` ops | Snapshot tells us what to remove |
| Drift detection / repair | New YAML vs live introspection | Catches manual DB changes |
| Core upgrade | Old version YAML vs new version YAML (both from codebase) | Deterministic, reproducible |

### Diff Algorithm (from Plugin System HLD)

Already established rules:
1. Table in YAML but not in DB → `CreateTable`
2. Table in DB but not in YAML (on uninstall) → `DropTable`
3. Column in YAML but not in DB table → `AddColumn`
4. Column in DB but dropped from YAML → `DropColumn` (destructive — requires confirmation)
5. Column type/length/default changed → `ModifyColumn`
6. Index differences → `AddIndex` / `DropIndex`

**Safety**: Destructive operations (`DropColumn`, `DropTable`) flagged and require admin confirmation before execution.

---

## 5. Connection Lifecycle Management

### Connection Lifecycle Patterns

| Pattern | Description | When to Use |
|---------|-------------|-------------|
| **Eager connection** | Connect at construction time | Simple CLI scripts |
| **Lazy connection** | Connect on first query/prepare | Web requests (may not need DB) |
| **Persistent connection** | Reuse connection across requests (PDO `ATTR_PERSISTENT`) | High-traffic, short-lived requests |
| **Per-request** | New connection per HTTP request, close at end | Standard web (our default) |
| **Long-running** | Single connection for CLI worker lifetime | Queue consumers, cron |
| **Reconnect-on-error** | Detect "gone away" and reconnect transparently | Long-running CLI processes |

### Recommended: Lazy Connection with Per-Request Lifecycle

```php
namespace phpbb\database;

final class Connection
{
    private ?\PDO $pdo = null;

    public function __construct(
        private readonly ConnectionConfig $config,
    ) {}

    /**
     * Get or create the underlying PDO instance.
     * Lazy: does not connect until first use.
     */
    public function getPdo(): \PDO
    {
        if ($this->pdo === null) {
            $this->pdo = $this->createPdo();
        }
        return $this->pdo;
    }

    /**
     * Explicitly close the connection.
     * Called at end of request lifecycle by kernel listener.
     */
    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /**
     * Check if currently connected.
     */
    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    private function createPdo(): \PDO
    {
        $pdo = new \PDO(
            $this->config->getDsn(),
            $this->config->getUsername(),
            $this->config->getPassword(),
            $this->config->getOptions(),
        );

        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
        $pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        // Engine-specific initialization
        foreach ($this->config->getInitStatements() as $stmt) {
            $pdo->exec($stmt);
        }

        return $pdo;
    }
}
```

**Key decisions**:
- **Lazy** — avoids connection cost if request doesn't touch DB (static pages, cache hits)
- **Per-request** — connection created once, shared across all repositories, closed at request end
- **No persistent connections** in default mode — they cause issues with transactions left open on error
- **ERRMODE_EXCEPTION** — all DB errors throw, consistent with project error handling
- **EMULATE_PREPARES=false** — real prepared statements for security + correct type handling

### Reconnection for CLI

```php
namespace phpbb\database;

final class ResilientConnection extends Connection
{
    private int $maxRetries = 3;
    private int $retryDelayMs = 100;

    /**
     * Execute a callback with automatic reconnection on "gone away" errors.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function withReconnect(callable $callback): mixed
    {
        $attempts = 0;
        while (true) {
            try {
                return $callback();
            } catch (\PDOException $e) {
                if (!$this->isRetryable($e) || ++$attempts >= $this->maxRetries) {
                    throw $e;
                }
                $this->disconnect();
                usleep($this->retryDelayMs * 1000 * $attempts); // linear backoff
            }
        }
    }

    private function isRetryable(\PDOException $e): bool
    {
        // MySQL: 2006 = server has gone away, 2013 = lost connection
        // PostgreSQL: connection reset by peer
        $retryableCodes = ['HY000', '08S01', '08006'];
        $retryableMessages = ['server has gone away', 'lost connection', 'connection reset'];

        foreach ($retryableMessages as $msg) {
            if (stripos($e->getMessage(), $msg) !== false) {
                return true;
            }
        }
        return in_array($e->getCode(), $retryableCodes, true);
    }
}
```

**Note**: Only for CLI/queue workers. Web requests should fail fast and return 503.

---

## 6. Transaction Management Patterns

### Transaction API

```php
namespace phpbb\database;

interface TransactionManagerInterface
{
    /**
     * Begin a transaction. Supports nesting via savepoints.
     */
    public function begin(): void;

    /**
     * Commit the current transaction (or release savepoint if nested).
     */
    public function commit(): void;

    /**
     * Rollback the current transaction (or rollback to savepoint if nested).
     */
    public function rollback(): void;

    /**
     * Execute a callable within a transaction.
     * Commits on success, rolls back on exception.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transactional(callable $callback): mixed;

    /**
     * Get the current nesting level (0 = no active transaction).
     */
    public function getNestingLevel(): int;
}
```

### Transaction Implementation with Savepoint Nesting

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

**Source**: Pattern derived from Doctrine DBAL `Connection::beginTransaction()` with savepoint emulation. See [Doctrine Transactions documentation](https://www.doctrine-project.org/projects/doctrine-dbal/en/4.2/reference/transactions.html):
> "Calling beginTransaction() while already in a transaction will not result in an actual transaction inside a transaction [...] Instead, transaction nesting is emulated by resorting to SQL savepoints."

### Isolation Levels

```php
namespace phpbb\database\enum;

enum IsolationLevel: string
{
    case ReadUncommitted = 'READ UNCOMMITTED';
    case ReadCommitted = 'READ COMMITTED';     // Default for PostgreSQL
    case RepeatableRead = 'REPEATABLE READ';   // Default for MySQL InnoDB
    case Serializable = 'SERIALIZABLE';
}
```

**Per-engine defaults**:
- MySQL InnoDB: `REPEATABLE READ`
- PostgreSQL: `READ COMMITTED`
- SQLite: `SERIALIZABLE` (implied — single-writer)

**Recommendation**: Use engine defaults (don't override globally). Allow per-transaction override when needed (e.g., counter reconciliation may use `SERIALIZABLE`).

### Advisory Locking

Already established pattern from Hierarchy Service HLD:

```php
namespace phpbb\database;

interface AdvisoryLockInterface
{
    /**
     * Acquire a named advisory lock (application-level, not row-level).
     * Blocks until lock is acquired or timeout.
     *
     * @param string $lockName Logical lock identifier (e.g., 'hierarchy_tree')
     * @param int $timeoutSeconds Maximum wait time (0 = non-blocking)
     * @return bool True if lock acquired, false if timeout
     */
    public function acquire(string $lockName, int $timeoutSeconds = 10): bool;

    /**
     * Release a previously acquired advisory lock.
     */
    public function release(string $lockName): void;
}
```

**Engine-specific SQL**:
- MySQL: `SELECT GET_LOCK(:name, :timeout)` / `SELECT RELEASE_LOCK(:name)`
- PostgreSQL: `SELECT pg_advisory_lock(:hash)` / `SELECT pg_advisory_unlock(:hash)`
- SQLite: Not supported natively — use file-based locking or skip (single-writer anyway)

**Evidence** (Hierarchy Service HLD, nested set operations):
```
acquire_advisory_lock('hierarchy_tree')
try:
    // ... nested set operations ...
finally:
    release_advisory_lock('hierarchy_tree')
```

### Transaction + Events Pattern (Project Convention)

Established across all services:

```
1. Begin transaction
2. Perform writes (INSERT/UPDATE/DELETE)
3. Update counters (in-transaction for consistency)
4. Commit transaction
5. Dispatch domain events AFTER commit
6. Event listeners perform side effects (cache invalidation, notifications, etc.)
```

**Evidence** (Threads Service HLD):
> "All events are dispatched **after** the database transaction commits. Each event carries enough data for listeners to act without additional queries."

This prevents listeners from seeing uncommitted data or causing deadlocks.

---

## 7. Configuration and DSN

### ConnectionConfig Value Object

```php
namespace phpbb\database;

final readonly class ConnectionConfig
{
    /**
     * @param array<int, mixed> $options PDO constructor options
     * @param string[] $initStatements SQL to execute after connect (SET NAMES, etc.)
     */
    public function __construct(
        private string $driver,         // 'mysql', 'pgsql', 'sqlite'
        private string $host = 'localhost',
        private int $port = 3306,
        private string $database = '',
        private string $username = '',
        private string $password = '',
        private string $charset = 'utf8mb4',
        private string $collation = 'utf8mb4_unicode_ci',
        private ?string $unixSocket = null,
        private ?string $sslCa = null,
        private ?string $sslCert = null,
        private ?string $sslKey = null,
        private array $options = [],
        private array $initStatements = [],
        private string $tablePrefix = 'phpbb_',
    ) {}

    public function getDsn(): string
    {
        return match ($this->driver) {
            'mysql' => $this->buildMysqlDsn(),
            'pgsql' => $this->buildPgsqlDsn(),
            'sqlite' => $this->buildSqliteDsn(),
            default => throw new \InvalidArgumentException("Unsupported driver: {$this->driver}"),
        };
    }

    private function buildMysqlDsn(): string
    {
        $dsn = "mysql:dbname={$this->database};charset={$this->charset}";
        if ($this->unixSocket !== null) {
            $dsn .= ";unix_socket={$this->unixSocket}";
        } else {
            $dsn .= ";host={$this->host};port={$this->port}";
        }
        return $dsn;
    }

    private function buildPgsqlDsn(): string
    {
        return "pgsql:host={$this->host};port={$this->port};dbname={$this->database}";
    }

    private function buildSqliteDsn(): string
    {
        if ($this->database === ':memory:') {
            return 'sqlite::memory:';
        }
        return "sqlite:{$this->database}";
    }

    public function getUsername(): string { return $this->username; }
    public function getPassword(): string { return $this->password; }
    public function getTablePrefix(): string { return $this->tablePrefix; }
    public function getDriver(): string { return $this->driver; }

    /**
     * @return array<int, mixed>
     */
    public function getOptions(): array
    {
        $defaults = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ];

        // SSL options for MySQL
        if ($this->driver === 'mysql' && $this->sslCa !== null) {
            $defaults[\PDO::MYSQL_ATTR_SSL_CA] = $this->sslCa;
            if ($this->sslCert !== null) {
                $defaults[\PDO::MYSQL_ATTR_SSL_CERT] = $this->sslCert;
            }
            if ($this->sslKey !== null) {
                $defaults[\PDO::MYSQL_ATTR_SSL_KEY] = $this->sslKey;
            }
        }

        return $this->options + $defaults;
    }

    /**
     * @return string[]
     */
    public function getInitStatements(): array
    {
        $stmts = $this->initStatements;

        // Engine-specific initialization
        if ($this->driver === 'mysql') {
            $stmts[] = "SET NAMES '{$this->charset}' COLLATE '{$this->collation}'";
            $stmts[] = "SET sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'";
        } elseif ($this->driver === 'pgsql') {
            $stmts[] = "SET client_encoding = 'UTF8'";
            $stmts[] = "SET timezone = 'UTC'";
        } elseif ($this->driver === 'sqlite') {
            $stmts[] = 'PRAGMA journal_mode = WAL';
            $stmts[] = 'PRAGMA foreign_keys = ON';
            $stmts[] = 'PRAGMA busy_timeout = 5000';
        }

        return $stmts;
    }
}
```

### Configuration from phpBB config.php

```php
// Factory: builds ConnectionConfig from phpBB's config.php values
namespace phpbb\database;

final class ConnectionConfigFactory
{
    public static function fromPhpbbConfig(array $config): ConnectionConfig
    {
        return new ConnectionConfig(
            driver: $config['dbms'],           // 'mysql', 'pgsql', 'sqlite'
            host: $config['dbhost'] ?? 'localhost',
            port: (int) ($config['dbport'] ?? self::defaultPort($config['dbms'])),
            database: $config['dbname'],
            username: $config['dbuser'] ?? '',
            password: $config['dbpasswd'] ?? '',
            tablePrefix: $config['table_prefix'] ?? 'phpbb_',
        );
    }

    private static function defaultPort(string $driver): int
    {
        return match ($driver) {
            'mysql' => 3306,
            'pgsql' => 5432,
            'sqlite' => 0,
            default => 3306,
        };
    }
}
```

---

## 8. Proposed Connection API (PHP Interface Sketch)

### Interface Hierarchy

```php
namespace phpbb\database;

/**
 * Read-only database operations.
 * Injected into query-only services that should not write.
 */
interface ReadConnectionInterface
{
    public function prepare(string $sql): \PDOStatement;
    public function query(string $sql): \PDOStatement;
    public function getTablePrefix(): string;
    public function getDriver(): string;
}

/**
 * Full database connection with write capabilities.
 * Injected into repositories that perform mutations.
 */
interface ConnectionInterface extends ReadConnectionInterface
{
    public function exec(string $sql): int;
    public function lastInsertId(?string $name = null): string|false;
}

/**
 * Transaction management (separate concern from connection).
 */
interface TransactionManagerInterface
{
    public function begin(): void;
    public function commit(): void;
    public function rollback(): void;
    public function transactional(callable $callback): mixed;
    public function getNestingLevel(): int;
}

/**
 * Advisory lock management (separate concern).
 */
interface AdvisoryLockInterface
{
    public function acquire(string $lockName, int $timeoutSeconds = 10): bool;
    public function release(string $lockName): void;
}

/**
 * Combined service container registration (convenience interface).
 * Most repositories inject this.
 */
interface DatabaseInterface extends ConnectionInterface, TransactionManagerInterface
{
}
```

### DI Wiring

```yaml
# config/services/database.yml
services:
    phpbb\database\ConnectionConfig:
        factory: ['phpbb\database\ConnectionConfigFactory', 'fromPhpbbConfig']
        arguments: ['%phpbb.db_config%']

    phpbb\database\Connection:
        class: phpbb\database\Connection
        arguments: ['@phpbb\database\ConnectionConfig']

    phpbb\database\TransactionManager:
        class: phpbb\database\TransactionManager
        arguments: ['@phpbb\database\Connection']

    phpbb\database\AdvisoryLock:
        class: phpbb\database\AdvisoryLock
        arguments: ['@phpbb\database\Connection']

    # Interface aliases
    phpbb\database\ConnectionInterface:
        alias: phpbb\database\Connection
    phpbb\database\ReadConnectionInterface:
        alias: phpbb\database\Connection
    phpbb\database\TransactionManagerInterface:
        alias: phpbb\database\TransactionManager
    phpbb\database\AdvisoryLockInterface:
        alias: phpbb\database\AdvisoryLock
```

### Repository Usage Pattern (from internal conventions)

```php
namespace phpbb\threads;

final class TopicRepository implements TopicRepositoryInterface
{
    public function __construct(
        private readonly \phpbb\database\ConnectionInterface $db,
    ) {}

    public function find(int $topicId): ?Topic
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM ' . $this->db->getTablePrefix() . 'topics WHERE topic_id = ?'
        );
        $stmt->execute([$topicId]);
        $row = $stmt->fetch();

        return $row ? Topic::fromRow($row) : null;
    }

    public function create(array $data): int
    {
        // ... INSERT logic ...
        return (int) $this->db->lastInsertId();
    }
}
```

### Service Transactional Usage

```php
namespace phpbb\threads;

final class ThreadsService implements ThreadsServiceInterface
{
    public function __construct(
        private readonly TopicRepositoryInterface $topicRepo,
        private readonly PostRepositoryInterface $postRepo,
        private readonly CounterServiceInterface $counterService,
        private readonly TransactionManagerInterface $tx,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function createTopic(CreateTopicRequest $request): TopicCreatedEvent
    {
        // Transaction wraps all writes
        $event = $this->tx->transactional(function () use ($request): TopicCreatedEvent {
            $topicId = $this->topicRepo->create([...]);
            $postId = $this->postRepo->create([...]);
            $this->counterService->incrementForumTopics($request->forumId);

            return new TopicCreatedEvent($topicId, $postId, ...);
        });

        // Events dispatched AFTER commit (convention from all service HLDs)
        $this->eventDispatcher->dispatch($event);

        return $event;
    }
}
```

---

## Summary

### Key Recommendations

| Concern | Recommendation | Rationale |
|---------|---------------|-----------|
| **Core versioning** | Semantic version in `phpbb_config` + migration tracking table | Simple, matches phpBB release cycle |
| **Plugin versioning** | Per-plugin version in `phpbb_plugins` + schema snapshot in JSON state | Already designed in Plugin HLD |
| **Diff strategy** | Hybrid snapshot + live introspection fallback | Accurate without requiring DB at plan time |
| **Connection** | Lazy, per-request, PDO-based | Established pattern, simple, testable |
| **Transactions** | Savepoint-based nesting, Doctrine-style | Supports nested service calls safely |
| **Events** | Always dispatch AFTER commit | Prevents inconsistency, established convention |
| **Advisory locks** | Engine-specific GET_LOCK / pg_advisory_lock | Already used in Hierarchy Service |
| **Rollback** | Forward-only in production; down migrations for dev | Industry best practice, data safety |

### Confidence Assessment

| Finding | Confidence | Source |
|---------|-----------|--------|
| Declarative YAML + SchemaDiffer approach | **High (95%)** | Plugin System HLD §Schema System (established) |
| Transaction savepoint nesting | **High (95%)** | Doctrine DBAL documentation + project convention |
| Advisory locking pattern | **High (90%)** | Hierarchy Service HLD (already implemented) |
| Events after commit pattern | **High (95%)** | Threads + Hierarchy + Plugin HLDs (universal) |
| Lazy connection pattern | **High (90%)** | Doctrine DBAL + standard PHP practice |
| Version tracking table design | **Medium (75%)** | Synthesis of Phinx/Laravel/Flyway — needs validation during implementation |
| Reconnection for CLI | **Medium (70%)** | Industry pattern — needs testing with actual PDO error codes |
