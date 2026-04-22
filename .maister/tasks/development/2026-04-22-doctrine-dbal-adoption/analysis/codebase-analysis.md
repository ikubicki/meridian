# Codebase Analysis Report

**Date**: 2026-04-22
**Task**: Migrate raw PDO layer (PdoFactory + 4 repositories) to Doctrine DBAL 4.x (DBAL-only, no ORM) in `src/phpbb/`. Add SQLite-in-memory integration test harness. Implement platform-switched upsert for GroupRepository. Keep `*RepositoryInterface` contracts unchanged.
**Analyzer**: codebase-analyzer skill (4 Explore agents: File Discovery, Code Analysis, Context Discovery, Migration Target)

---

## Summary

- Doctrine DBAL is **not installed** — `composer.json` has no `doctrine/dbal` entry, `vendor/doctrine/` does not exist, and `DoctrineBundle` is absent from `bundles.php`. The entire dependency chain must be added from scratch.
- Five source files require rewrite (`PdoFactory` + 4 repositories); four interface contracts and all six consuming services are **frozen** and must not change.
- The highest-risk migration point is `PdoGroupRepository::addMember()` which uses MySQL-only `ON DUPLICATE KEY UPDATE` — a platform-switched upsert strategy is mandatory for SQLite tests to pass.
- Two repositories (`PdoBanRepository`, `PdoGroupRepository`) have **zero test coverage**; the unit test suite must be expanded alongside the migration.
- Env vars follow a non-standard `PHPBB_DB_*` naming scheme rather than `DATABASE_URL`, requiring a custom connection factory instead of DBAL's default autoconfiguration.

---

## Files Identified

### Primary Files — Direct Migration Targets

**`src/phpbb/db/PdoFactory.php`** (47 lines)
- Static factory producing one `\PDO` instance per request via `services.yaml` factory registration.
- Must be replaced by a Doctrine DBAL `Connection` factory (or `ConnectionFactory` service) that reads the same `PHPBB_DB_*` env vars and assembles a `DriverManager::getConnection()` call.

**`src/phpbb/auth/Repository/PdoRefreshTokenRepository.php`** (112 lines)
- Implements `RefreshTokenRepositoryInterface`. Six operations, all named-param `prepare() / execute()`. Timestamps stored and read as Unix integers (`INT` column, `DateTimeImmutable::createFromFormat('U', ...)`).
- DBAL migration: `prepare()` → `executeStatement()` / `executeQuery()`, `fetch()` → `fetchAssociative()`, `fetchAll()` → `fetchAllAssociative()`. Timestamp hydration unchanged.

**`src/phpbb/user/Repository/PdoUserRepository.php`** (330 lines)
- Implements `UserRepositoryInterface`. Ten operations including dynamic `SET` builder for `update()`, IN-list binding via positional `?`, and explicit `PDO::PARAM_INT` for `LIMIT`/`OFFSET` in `search()`. Uses `lastInsertId()` in `create()`.
- Most complex repository. IN-list requires DBAL `Connection::executeQuery()` with `ArrayParameterType::INTEGER`. `LIMIT`/`OFFSET` must use `ParameterType::INTEGER`. `lastInsertId()` → `Connection::lastInsertId()`.

**`src/phpbb/user/Repository/PdoBanRepository.php`** (166 lines)
- Implements `BanRepositoryInterface`. Eight operations. Uses `->query()` for no-param `findAll()`. Existence checks via `fetchColumn()` → cast to `bool`. `BanType` is inferred from which column (`ban_userid`/`ban_ip`/`ban_email`) is non-empty (no SQL change needed).
- DBAL migration: `fetchColumn()` → `fetchOne()`, `query()` → `executeQuery()`. Straightforward.

**`src/phpbb/user/Repository/PdoGroupRepository.php`** (104 lines)
- Implements `GroupRepositoryInterface`. Five operations. `addMember()` contains **MySQL-specific** `ON DUPLICATE KEY UPDATE` — the only platform-specific SQL in the entire codebase.
- DBAL migration: all ops are simple except `addMember()` which needs platform-detection or DBAL's `AbstractPlatform`-based upsert API.

**`src/phpbb/config/services.yaml`** (DI wiring)
- Current `PDO` service block (factory + env args) must be replaced with a `Doctrine\DBAL\Connection` service entry pointing to the new connection factory.
- All four repository service registrations need constructor arg type updated (no explicit arg needed — autowire handles it once `Connection` is a registered service).

**`src/phpbb/config/bundles.php`**
- Must add `Doctrine\Bundle\DoctrineBundle\DoctrineBundle` (or configure DBAL standalone without DoctrineBundle if ORM is explicitly excluded).

### Related Files — Config / Infrastructure

**`src/phpbb/config/packages/framework.yaml`** — No changes; model only for doctrine.yaml layout.
**`src/phpbb/config/packages/monolog.yaml`** — No changes; reference for config file convention.
**`phpunit.xml`** — Needs `PHPBB_DB_*` env vars added (or SQLite DSN env) for the integration test harness, plus a new `dbal-integration` test suite entry.
**`docker-compose.yml`** — Source of truth for env var names (`PHPBB_DB_HOST`, `PHPBB_DB_NAME`, `PHPBB_DB_USER`, `PHPBB_DB_PASSWD`).
**`composer.json`** — `require` block needs `doctrine/dbal: ^4.0`; `require-dev` needs `doctrine/dbal` SQLite driver support (bundled).

### Frozen Files — MUST NOT CHANGE

| Interface | Methods |
|-----------|---------|
| `src/phpbb/auth/Contract/RefreshTokenRepositoryInterface.php` | `save`, `findByHash`, `revokeByHash`, `revokeFamily`, `revokeAllForUser`, `deleteExpired` |
| `src/phpbb/user/Contract/UserRepositoryInterface.php` | `findById`, `findByIds`, `findByUsername`, `findByEmail`, `create`, `update`, `delete`, `search`, `findDisplayByIds`, `incrementTokenGeneration` |
| `src/phpbb/user/Contract/BanRepositoryInterface.php` | `isUserBanned`, `isIpBanned`, `isEmailBanned`, `findById`, `findAll`, `create`, `delete` |
| `src/phpbb/user/Contract/GroupRepositoryInterface.php` | `findById`, `findAll(?GroupType)`, `getMembershipsForUser`, `addMember`, `removeMember` |

---

## Current Functionality

### Data Flow (PDO path)

```
HTTP Request
  → Symfony DI Container
    → services.yaml: PDO { factory: PdoFactory::create($host, $dbname, $user, $password) }
      → MySQLi DSN: mysql:host=db;port=3306;dbname=phpbb;charset=utf8mb4
        → ATTR_ERRMODE=EXCEPTION, FETCH_ASSOC, EMULATE_PREPARES=false
    → Repository(PDO $pdo) — constructor-injected
      → pdo->prepare(SQL) → stmt->execute([...]) → fetch/fetchAll/fetchColumn
        → hydrate(): entity / DTO / bool
```

### Key Patterns Per Repository

| Repository | `prepare+execute` | `query()` | `fetchColumn` | `lastInsertId` | `PARAM_INT` | IN-list `?` | MySQL-specific |
|---|---|---|---|---|---|---|---|
| PdoRefreshTokenRepository | ✓ (all 6) | — | — | — | — | — | — |
| PdoUserRepository | ✓ (8/10) | — | ✓ (COUNT) | ✓ (create) | ✓ (LIMIT/OFFSET) | ✓ (findByIds, findDisplayByIds) | — |
| PdoBanRepository | ✓ (7/8) | ✓ (findAll) | ✓ (3 exists) | ✓ (create) | — | — | — |
| PdoGroupRepository | ✓ (4/5) | ✓ (findAll-noarg) | — | — | — | — | **`ON DUPLICATE KEY UPDATE`** |

### Timestamp Handling

All timestamps are stored as `INT` (Unix epoch). Hydration uses `DateTimeImmutable::createFromFormat('U', (string) $row['field'])`. This approach is database-agnostic and requires no change post-migration; DBAL returns `INT` columns as strings from MySQL and integers from SQLite — the `(string)` cast in `createFromFormat` handles both.

---

## Dependencies

### Imports (Current — What Repositories Depend On)

- `\PDO` — class-typed constructor arg in all 4 repositories
- `\PDOStatement` — used implicitly via `prepare()` return
- No external packages beyond PHP core in repository layer

### Consumers (What Depends on Repositories — via Interfaces Only)

All six consumers inject the **interface**, never the concrete `Pdo*` class. Zero PDO-specific calls leak into consumers.

| Consumer Service | Injects |
|---|---|
| `phpbb\auth\Service\AuthService` (or similar JWT handler) | `RefreshTokenRepositoryInterface` |
| `phpbb\user\Service\UserSearchService` | `UserRepositoryInterface` |
| `phpbb\user\Service\UserDisplayService` | `UserRepositoryInterface` |
| `phpbb\api\Controller\*` (auth endpoints) | `RefreshTokenRepositoryInterface`, `UserRepositoryInterface` |
| `phpbb\api\Controller\*` (user endpoints) | `BanRepositoryInterface`, `GroupRepositoryInterface` |
| (consumers total: ~6 services) | all via interface alias |

**Consumer Impact Scope**: None — no source changes required in any consumer.

---

## Migration Complexity Matrix (Per Repository)

| Repository | LOC | SQL Ops | Special SQL | DBAL API Touches | Test Coverage Now | Complexity |
|---|---|---|---|---|---|---|
| `PdoRefreshTokenRepository` | 112 | 6 (all named-param) | — | `executeStatement`, `executeQuery`, `fetchAssociative`, `fetchOne` | 4 unit tests (PDO mock) | **Low** |
| `PdoBanRepository` | 166 | 8 | `query()` no-param, `fetchColumn` bool | `executeQuery`, `fetchOne`, `executeStatement` | **NONE** | **Low-Medium** |
| `PdoGroupRepository` | 104 | 5 | **MySQL upsert**, `query()` conditional | `executeQuery`, `fetchAllAssociative`, platform upsert | **NONE** | **Medium-High** |
| `PdoUserRepository` | 330 | 10 | `IN (?,?,?)` positional, `PARAM_INT` bind, dynamic SET builder, `lastInsertId` | `executeQuery`+`ArrayParameterType`, `executeStatement`, `fetchOne`, `fetchAllAssociative` | 4 unit tests (PDO mock) | **High** |
| `PdoFactory` | 47 | n/a | MySQL DSN only | `DriverManager::getConnection` | — | **Low** |

**Overall migration complexity: Moderate** (no ORM mapping, no schema migration, SQL is already correct for MySQL; only SQLite divergence is the upsert).

---

## DI Wiring Analysis (Current → Target)

### Current (`services.yaml`)

```yaml
PDO:
    class: PDO
    factory: ['phpbb\db\PdoFactory', 'create']
    arguments:
        $host:     '%env(PHPBB_DB_HOST)%'
        $dbname:   '%env(PHPBB_DB_NAME)%'
        $user:     '%env(PHPBB_DB_USER)%'
        $password: '%env(PHPBB_DB_PASSWD)%'
    public: false

phpbb\user\Repository\PdoUserRepository: ~          # autowires PDO
phpbb\user\Contract\UserRepositoryInterface:
    alias: phpbb\user\Repository\PdoUserRepository
    public: true
# (× 3 more repository blocks)
```

### Target (`services.yaml` — proposed)

```yaml
# ── Connection factory ────────────────────────────────────────────────────
phpbb\db\DbalConnectionFactory: ~

Doctrine\DBAL\Connection:
    factory: ['@phpbb\db\DbalConnectionFactory', 'create']
    arguments:
        $host:     '%env(PHPBB_DB_HOST)%'
        $dbname:   '%env(PHPBB_DB_NAME)%'
        $user:     '%env(PHPBB_DB_USER)%'
        $password: '%env(PHPBB_DB_PASSWD)%'
    public: false

# ── Repositories (autowire picks up Connection) ───────────────────────────
phpbb\auth\Repository\DbalRefreshTokenRepository: ~
phpbb\auth\Contract\RefreshTokenRepositoryInterface:
    alias: phpbb\auth\Repository\DbalRefreshTokenRepository

phpbb\user\Repository\DbalUserRepository: ~
phpbb\user\Contract\UserRepositoryInterface:
    alias: phpbb\user\Repository\DbalUserRepository
    public: true

phpbb\user\Repository\DbalBanRepository: ~
phpbb\user\Contract\BanRepositoryInterface:
    alias: phpbb\user\Repository\DbalBanRepository
    public: true

phpbb\user\Repository\DbalGroupRepository: ~
phpbb\user\Contract\GroupRepositoryInterface:
    alias: phpbb\user\Repository\DbalGroupRepository
    public: true
```

### Naming Convention Decision

Rename concrete classes from `Pdo*` → `Dbal*` to reflect the new driver. Interface aliases and consumers are unaffected.

### Bundle Registration

`bundles.php` needs `Doctrine\Bundle\DoctrineBundle\DoctrineBundle` **only if** DoctrineBundle's DBAL layer is used for connection management. Alternatively, wire DBAL standalone via `DbalConnectionFactory` without the bundle. Either works; the standalone approach avoids the Doctrine config ceremony and suits "DBAL-only, no ORM" intent.

**Recommendation**: Go bundle-less. Add only `doctrine/dbal ^4.0` to `composer.json`. Wire `Connection` manually in `services.yaml`. Skip `doctrine.yaml` entirely. This matches the project's "no ORM" constraint exactly and avoids unneeded bundle overhead.

---

## Test Strategy Analysis

### Current Test State

| Test File | Approach | Tests | What It Covers |
|---|---|---|---|
| `PdoRefreshTokenRepositoryTest` | `createMock(\PDO::class)` + `createMock(\PDOStatement::class)` | 4 | SQL structure, param names, hydration |
| `PdoUserRepositoryTest` | Same PDO mock pattern + `makeRepository($pdo)` helper | 4 | hydration, `create()` INSERT shape |
| BanRepository | **NONE** | 0 | — |
| GroupRepository | **NONE** | 0 | — |

**Problem**: Mocking `\PDO` and `\PDOStatement` tests parameter wiring but not the actual SQL execution path. Migrating to DBAL will break all existing mocks (wrong type); they must be replaced rather than adapted.

### Target: SQLite-in-Memory Integration Tests

All four `Dbal*Repository` classes will receive **integration tests** that:

1. Boot a `Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])` connection.
2. Apply a schema via DBAL's `SchemaManager` or inline `CREATE TABLE` DDL (subset of columns needed per test).
3. Seed minimal fixture rows.
4. Execute the repository method under test.
5. Assert result entities or query the DB directly for side-effects.

**SQLite test env vars** — add to `phpunit.xml`:

```xml
<env name="DBAL_DRIVER" value="pdo_sqlite"/>
<env name="DBAL_URL"    value="sqlite:///:memory:"/>
```

Or inject the `Connection` directly in the test (not via container) to keep tests fast and isolated.

### Existing PDO Mock Tests — Migration Path

| Old Test File | Action |
|---|---|
| `PdoRefreshTokenRepositoryTest` | Delete; replace with `DbalRefreshTokenRepositoryTest` (SQLite integration) |
| `PdoUserRepositoryTest` | Delete; replace with `DbalUserRepositoryTest` (SQLite integration) |
| `BanRepositoryTest` | Create new `DbalBanRepositoryTest` (SQLite integration) |
| `GroupRepositoryTest` | Create new `DbalGroupRepositoryTest` (SQLite integration, upsert behaviour) |

### Test Harness Helper

A `tests/phpbb/Support/InMemoryConnectionFactory.php` trait or base class should expose:

```php
protected function createConnection(): Connection
{
    return DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
}

protected function applySchema(Connection $conn, string $sql): void
{
    foreach (explode(';', $sql) as $ddl) {
        $trimmed = trim($ddl);
        if ($trimmed !== '') { $conn->executeStatement($trimmed); }
    }
}
```

---

## Critical Migration Decisions

### 1. Platform-Switched Upsert (GroupRepository)

**Problem**: `addMember()` uses `ON DUPLICATE KEY UPDATE` — MySQL-only, fails on SQLite.

**Solution — Option A** (preferred): Use DBAL 4.x `Connection::upsert()` (available since DBAL 3.3, stable in 4.x):

```php
$this->connection->upsert(
    self::TABLE_PIVOT,
    ['group_id' => $groupId, 'user_id' => $userId, 'group_leader' => (int) $isLeader, 'user_pending' => 0],
    ['group_id', 'user_id'],          // unique key columns
);
```

`Connection::upsert()` uses `AbstractPlatform::getUpsertSQL()` which emits `INSERT OR REPLACE` on SQLite and `INSERT ... ON DUPLICATE KEY UPDATE` on MySQL.

**Solution — Option B** (fallback if DBAL upsert proves insufficient): Detect platform via `$this->connection->getDatabasePlatform()` and branch between MySQL raw SQL and a `DELETE + INSERT` pair for SQLite.

**Recommendation**: Option A. Verify DBAL 4.x API surface during implementation.

### 2. PARAM_INT for LIMIT / OFFSET (UserRepository)

**Problem**: PDO requires explicit `PDO::PARAM_INT` for `LIMIT`/`OFFSET` when `EMULATE_PREPARES=false`. DBAL handles this differently.

**Solution**: Pass `LIMIT`/`OFFSET` as `ParameterType::INTEGER` via the types array argument of `executeQuery()`:

```php
$this->connection->executeQuery(
    $sql,
    $params + [':limit' => $limit, ':offset' => $offset],
    $types + [':limit' => ParameterType::INTEGER, ':offset' => ParameterType::INTEGER],
);
```

Alternatively, embed LIMIT/OFFSET directly in SQL as literals (safe — values are cast `int` before use).

### 3. IN-List Binding (UserRepository `findByIds`, `findDisplayByIds`)

**Problem**: Current code builds `implode(',', array_fill(0, count($ids), '?'))` and passes positional params. DBAL 4.x has a cleaner API.

**Solution**: Use DBAL's `ArrayParameterType::INTEGER` expansion:

```php
use Doctrine\DBAL\ArrayParameterType;

$this->connection->executeQuery(
    'SELECT * FROM ' . self::TABLE . ' WHERE user_id IN (?)',
    [$ids],
    [ArrayParameterType::INTEGER],
);
```

DBAL expands the single `?` into the correct `(?,?,?)` list automatically. This replaces both the `array_fill` placeholder construction and `execute($ids)`.

### 4. Timestamp Handling (RefreshTokenRepository)

**Current**: Unix `INT` stored, `\DateTimeImmutable::createFromFormat('U', (string) $row['field'])` on read.

**DBAL behaviour**: SQLite returns integers as PHP `int`, MySQL may return them as strings. The `(string)` cast in `createFromFormat` handles both — **no change required**.

### 5. `fetchColumn()` / `fetchOne()` Mapping

| PDO method | DBAL 4.x equivalent |
|---|---|
| `$stmt->fetchColumn()` | `$result->fetchOne()` |
| `$stmt->fetch()` | `$result->fetchAssociative()` |
| `$stmt->fetchAll()` | `$result->fetchAllAssociative()` |
| `$pdo->query($sql)` | `$this->connection->executeQuery($sql)` |
| `$pdo->prepare($sql)->execute($params)` (write) | `$this->connection->executeStatement($sql, $params)` |
| `$pdo->lastInsertId()` | `$this->connection->lastInsertId()` |

### 6. `DbalConnectionFactory` — Custom DSN Assembly

DBAL 4.x `DriverManager::getConnection()` accepts a params array:

```php
DriverManager::getConnection([
    'driver'    => 'pdo_mysql',
    'host'      => $host,
    'dbname'    => $dbname,
    'user'      => $user,
    'password'  => $password,
    'charset'   => 'utf8mb4',
    'driverOptions' => [
        PDO::ATTR_EMULATE_PREPARES => false,
    ],
]);
```

This preserves the existing MySQL connection semantics (charset, emulate prepares) without relying on `DATABASE_URL`.

---

## Risk Matrix

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| `ON DUPLICATE KEY UPDATE` fails on SQLite | Certain | High (blocks integration tests) | Use DBAL `upsert()` or branch by platform |
| `ArrayParameterType` API changed in DBAL 4.x | Low | Medium | Verify during `composer require`; DBAL changelog confirms stable in 4.x |
| `LIMIT`/`OFFSET` as strings cause MySQL driver errors | Low | Medium | Explicit `ParameterType::INTEGER` in types array |
| E2E tests (21 Playwright specs) break due to runtime regression | Low-Medium | High | Run full E2E suite after migration before merge |
| `lastInsertId()` returns `'0'` on SQLite for some drivers | Medium | Medium | Assert in integration test; use `RETURNING id` if needed (SQLite 3.35+) |
| Timestamp `createFromFormat('U', ...)` returns `false` on null `revoked_at` | Existing risk | Medium | Current code already guards with `$row['revoked_at'] !== null` checks |
| Ban/Group repos have no tests — silent regressions | High probability | Medium | Mandatory: write integration tests for all 4 repos before completing migration |
| DoctrineBundle version conflicts with Symfony 8.x | Low | Medium | `doctrine/doctrine-bundle: ^2.12` supports Symfony 8; or go bundle-less |

---

## Implementation Order Recommendation

The following order minimises risk by establishing infrastructure first, then migrating simple repos, and saving the most complex + platform-specific repo for last.

```
Phase 1 — Infrastructure (no behaviour change yet)
  1.1  composer require doctrine/dbal:^4.0
  1.2  Create DbalConnectionFactory (mirrors PdoFactory semantics)
  1.3  Register Doctrine\DBAL\Connection in services.yaml; keep PDO registration
  1.4  Smoke-test: boot container, verify Connection resolves — no repo changes yet

Phase 2 — Simplest repository: PdoRefreshTokenRepository → DbalRefreshTokenRepository
  2.1  Create DbalRefreshTokenRepository (named-param only, all DBAL)
  2.2  Wire alias in services.yaml; remove PDO alias
  2.3  Write DbalRefreshTokenRepositoryTest (SQLite in-memory, 4+ tests)
  2.4  Run phpunit + e2e — green gate

Phase 3 — PdoBanRepository → DbalBanRepository
  3.1  Create DbalBanRepository (fetchOne, executeQuery)
  3.2  Wire alias; write DbalBanRepositoryTest (must create new — zero existing)
  3.3  Run phpunit + e2e — green gate

Phase 4 — PdoGroupRepository → DbalGroupRepository (platform-switched upsert)
  4.1  Create DbalGroupRepository with DBAL upsert for addMember()
  4.2  Wire alias; write DbalGroupRepositoryTest including addMember upsert + idempotency
  4.3  Run phpunit + e2e — green gate

Phase 5 — Most complex: PdoUserRepository → DbalUserRepository
  5.1  Create DbalUserRepository (IN-list, PARAM_INT, dynamic SET, lastInsertId)
  5.2  Wire alias; expand DbalUserRepositoryTest (schema: 22 columns needed)
  5.3  Run phpunit + e2e — green gate

Phase 6 — Cleanup
  6.1  Remove PDO service block from services.yaml
  6.2  Delete PdoFactory.php + all 4 Pdo*Repository.php files
  6.3  Delete old PdoRefreshTokenRepositoryTest.php + PdoUserRepositoryTest.php
  6.4  Final full test run (unit + e2e)
```

**Estimated file count at completion**:
- 5 deleted (`PdoFactory` + 4 repos)
- 2 deleted test files
- 5 created (`DbalConnectionFactory` + 4 `Dbal*Repository`)
- 4 created test files (`Dbal*RepositoryTest`)
- 3 modified (`services.yaml`, `composer.json`, `phpunit.xml`)
- 1 optional new (`config/packages/doctrine.yaml` — only if DoctrineBundle is used)

---

## Impact Assessment

- **Primary changes**: 5 source files, `services.yaml`, `composer.json`, `phpunit.xml`
- **Related changes**: potentially `bundles.php` (only if DoctrineBundle route taken)
- **Test updates**: 2 old test files deleted, 4 new integration test files created
- **Consumer impact**: zero — all 6 consumers inject via interface

### Risk Level: **Low-Medium**

The SQL layer is correct and unchanged at the MySQL level. The primary risk is the upsert divergence (addressable with DBAL's built-in API) and the absence of tests for two repositories. With a phase-by-phase approach and a green-gate after each phase, regressions are caught immediately.

---

## Next Steps

The gap-analyzer / planning phase should focus on:
1. Confirming `doctrine/dbal ^4.0` compatibility with `symfony/framework-bundle ^8.0` (check `doctrine/dbal` release notes for Symfony 8 support).
2. Deciding bundle-less vs DoctrineBundle approach (recommendation: bundle-less per analysis above).
3. Specifying the SQLite DDL schema snippets needed by each integration test (column lists per table, types that SQLite accepts).
4. Confirming `Connection::upsert()` signature in DBAL 4.x before coding Phase 4.
