# Specification: M4 — Adopt Doctrine DBAL 4 in phpbb namespace

**Date:** 2026-04-22
**Task path:** `.maister/tasks/development/2026-04-22-doctrine-dbal-adoption/`

---

## 1. Overview

### Goal

Replace the raw PDO layer (`PdoFactory` + four `Pdo*Repository` classes) in `src/phpbb/` with Doctrine DBAL 4.x (DBAL-only, no ORM). Add a SQLite-in-memory integration test harness. Delete the PDO layer after all tests are green.

### Context

- Five files are being rewritten; four `*RepositoryInterface` contracts and all six consuming services are **frozen**.
- DBAL is not yet installed (`doctrine/dbal` absent from `composer.json`).
- MariaDB 10.11 in production; SQLite in-memory for tests.
- `DoctrineBundle` is **not** adopted — DBAL is wired manually via a bespoke `DbalConnectionFactory`.
- The only MySQL-specific SQL in the codebase is `ON DUPLICATE KEY UPDATE` in `PdoGroupRepository::addMember()` — this must become platform-portable.

### Non-Goals (M4)

- DoctrineBundle, Doctrine ORM, `doctrine/migrations`
- Legacy `src/phpbb3/` layer
- UI or API contract changes
- Performance benchmarking
- Schema migration tooling

---

## 2. Architecture

### 2.1 Dependency

Add to `composer.json` `require` block:

```
"doctrine/dbal": "^4.0"
```

`doctrine/dbal` 4.x ships with `pdo_mysql` and `pdo_sqlite` drivers — no extra packages needed for SQLite tests.

### 2.2 `DbalConnectionFactory` — Production Wiring

`DbalConnectionFactory` is an **instance service** registered in `services.yaml`. Its `create()` method is called as a Symfony factory method. It assembles a DBAL configuration array from the same `PHPBB_DB_*` env vars used by the deleted `PdoFactory`.

**MariaDB DSN (production):**

```php
DriverManager::getConnection([
    'driver'        => 'pdo_mysql',
    'host'          => $host,
    'port'          => (int) $port ?: 3306,
    'dbname'        => $dbname,
    'user'          => $user,
    'password'      => $password,
    'charset'       => 'utf8mb4',
    'serverVersion' => 'mariadb-10.11.0',   // DBAL 4 requires this to resolve MariaDBPlatform
]);
```

`serverVersion` with the `mariadb-` prefix causes DBAL 4 to instantiate `MariaDBPlatform` (which extends `MySQLPlatform`), enabling both `instanceof MySQLPlatform` and `instanceof MariaDBPlatform` to return `true`.

**SQLite DSN (tests):**

```php
DriverManager::getConnection([
    'driver' => 'pdo_sqlite',
    'memory' => true,
]);
```

### 2.3 `Doctrine\DBAL\Connection` as a Symfony Service

`Doctrine\DBAL\Connection` is registered as a **non-public** service in `services.yaml` using `DbalConnectionFactory` as a factory. All four `Dbal*Repository` classes receive it via autowire (their constructors type-hint `Connection`).

No `bundles.php` change. No `config/packages/doctrine.yaml`.

---

## 3. Components

### 3.1 `phpbb\db\DbalConnectionFactory`

| Aspect | Detail |
|---|---|
| **Location** | `src/phpbb/db/DbalConnectionFactory.php` |
| **Namespace** | `phpbb\db` |
| **Replaces** | `phpbb\db\PdoFactory` (deleted after green tests) |

**Constructor:** No parameters (no injected dependencies; registered as `~ ` in services.yaml for autowire).

**Method: `create()`**

```
create(
    string $host,
    string $dbname,
    string $user,
    string $password,
    string $port = '',
): Doctrine\DBAL\Connection
```

- Builds the DBAL params array (see §2.2).
- Port: cast `(int) $port` → fallback to 3306 if resolves to 0.
- Calls `Doctrine\DBAL\DriverManager::getConnection(array $params)`.
- Returns the `Connection` instance directly.
- No exception handling — DBAL throws `Doctrine\DBAL\Exception` on connection failure; let it propagate (bootstrap failure is fatal).

---

### 3.2 `phpbb\auth\Repository\DbalRefreshTokenRepository`

| Aspect | Detail |
|---|---|
| **Location** | `src/phpbb/auth/Repository/DbalRefreshTokenRepository.php` |
| **Implements** | `phpbb\auth\Contract\RefreshTokenRepositoryInterface` |
| **Replaces** | `PdoRefreshTokenRepository` |
| **Complexity** | Low |

**Constructor:**

```
__construct(
    private readonly Doctrine\DBAL\Connection $connection,
)
```

**Method mapping (interface → DBAL API):**

| Interface method | DBAL calls | Notes |
|---|---|---|
| `save(RefreshToken $token): void` | `executeStatement(SQL, [named params])` | Named params unchanged; timestamps as `->getTimestamp()` |
| `findByHash(string $hash): ?RefreshToken` | `executeQuery(SQL, [':hash' => $hash])->fetchAssociative()` | Null if result is `false` |
| `revokeByHash(string $hash): void` | `executeStatement(SQL, [':now' => time(), ':hash' => $hash])` | |
| `revokeFamily(string $familyId): void` | `executeStatement(SQL, [':now' => time(), ':familyId' => $familyId])` | |
| `revokeAllForUser(int $userId): void` | `executeStatement(SQL, [':now' => time(), ':userId' => $userId])` | |
| `deleteExpired(): void` | `executeStatement(SQL, [':now' => time()])` | |

**Hydration:** Copied verbatim from `PdoRefreshTokenRepository::hydrate()`. `DateTimeImmutable::createFromFormat('U', (string) $row['field'])` handles both MySQL string and SQLite int returns.

**Exception handling:** Each public method wraps its body in `try/catch(Doctrine\DBAL\Exception $e)` and rethrows as `phpbb\db\Exception\RepositoryException` (see §9).

---

### 3.3 `phpbb\user\Repository\DbalBanRepository`

| Aspect | Detail |
|---|---|
| **Location** | `src/phpbb/user/Repository/DbalBanRepository.php` |
| **Implements** | `phpbb\user\Contract\BanRepositoryInterface` |
| **Replaces** | `PdoBanRepository` |
| **Complexity** | Low-Medium |

**Constructor:**

```
__construct(
    private readonly Doctrine\DBAL\Connection $connection,
)
```

**Method mapping:**

| Interface method | DBAL calls | Notes |
|---|---|---|
| `isUserBanned(int $userId): bool` | `executeQuery(SQL, [':userId' => $userId, ':now' => time()])->fetchOne()` | `!== false` cast to bool |
| `isIpBanned(string $ip): bool` | same pattern | |
| `isEmailBanned(string $email): bool` | same pattern; apply `mb_strtolower($email)` | |
| `findById(int $id): ?Ban` | `executeQuery(SQL, [':id' => $id])->fetchAssociative()` | null if `false` |
| `findAll(): array` | `executeQuery('SELECT * FROM phpbb_banlist ORDER BY ban_id ASC')->fetchAllAssociative()` | No params — replaces `pdo->query()` |
| `create(array $data): Ban` | `executeStatement(SQL, [named params])` + `(int) $this->connection->lastInsertId()` | |
| `delete(int $id): void` | `executeStatement(SQL, [':id' => $id])` | |

**Hydration:** Copied verbatim from `PdoBanRepository::hydrate()` — `BanType` detection via non-empty column logic is DB-agnostic.

**Exception handling:** Try/catch `Doctrine\DBAL\Exception` → `RepositoryException`.

---

### 3.4 `phpbb\user\Repository\DbalGroupRepository`

| Aspect | Detail |
|---|---|
| **Location** | `src/phpbb/user/Repository/DbalGroupRepository.php` |
| **Implements** | `phpbb\user\Contract\GroupRepositoryInterface` |
| **Replaces** | `PdoGroupRepository` |
| **Complexity** | Medium-High (platform-switched upsert) |

**Constructor:**

```
__construct(
    private readonly Doctrine\DBAL\Connection $connection,
)
```

**Method mapping:**

| Interface method | DBAL calls | Notes |
|---|---|---|
| `findById(int $id): ?Group` | `executeQuery()->fetchAssociative()` | |
| `findAll(?GroupType $type = null): array` | `executeQuery(SQL, $params)->fetchAllAssociative()` where `$params = $type ? [':type' => $type->value] : []` | Unifies the two PDO branches into one call |
| `getMembershipsForUser(int $userId): array` | `executeQuery()->fetchAllAssociative()` | Inline `GroupMembership` hydration unchanged |
| `addMember(int $groupId, int $userId, bool $isLeader = false): void` | Platform-switched upsert — see §5 | |
| `removeMember(int $groupId, int $userId): void` | `executeStatement(SQL, [':groupId' => $groupId, ':userId' => $userId])` | |

**Group hydration:** Copied verbatim from `PdoGroupRepository::hydrate()`.

**Exception handling:** Try/catch `Doctrine\DBAL\Exception` → `RepositoryException`.

---

### 3.5 `phpbb\user\Repository\DbalUserRepository`

| Aspect | Detail |
|---|---|
| **Location** | `src/phpbb/user/Repository/DbalUserRepository.php` |
| **Implements** | `phpbb\user\Contract\UserRepositoryInterface` |
| **Replaces** | `PdoUserRepository` |
| **Complexity** | High |

**Constructor:**

```
__construct(
    private readonly Doctrine\DBAL\Connection $connection,
)
```

**Method mapping:**

| Interface method | DBAL calls | Notes |
|---|---|---|
| `findById(int $id): ?User` | `executeQuery(SQL, [':id' => $id])->fetchAssociative()` | |
| `findByIds(array $ids): array` | `executeQuery(SQL, [$ids], [ArrayParameterType::INTEGER])->fetchAllAssociative()` | Replaces `array_fill()` `?` expansion |
| `findByUsername(string $username): ?User` | `executeQuery()->fetchAssociative()` | |
| `findByEmail(string $email): ?User` | `executeQuery()->fetchAssociative()` | |
| `create(array $data): User` | `executeStatement(SQL, [named params])` + `(int) $this->connection->lastInsertId()` | 18-col INSERT unchanged |
| `update(int $id, array $data): void` | QueryBuilder — see below | |
| `delete(int $id): void` | `executeStatement(SQL, [':id' => $id])` | |
| `search(UserSearchCriteria $criteria): PaginatedResult` | QueryBuilder — see below | |
| `findDisplayByIds(array $ids): array` | `executeQuery(SQL, [$ids], [ArrayParameterType::INTEGER])->fetchAllAssociative()` | Same pattern as findByIds |
| `incrementTokenGeneration(int $userId): void` | `executeStatement(SQL, [':id' => $userId])` | |

**`update()` — QueryBuilder approach (FR-4):**

1. Guard empty `$data` early-return (unchanged).
2. Build `$qb = $this->connection->createQueryBuilder()->update(self::TABLE)->where('user_id = :id')->setParameter('id', $id)`.
3. Iterate `$allowedColumns` whitelist (exact same map as current) — `$qb->set($column, ':' . $field)->setParameter($field, $resolvedValue)`.
4. Guard empty `$setClauses` early-return (unchanged).
5. `$qb->executeStatement()`.

`UserType` / `InactiveReason` enum → `->value` resolution retained identically.

**`search()` — QueryBuilder approach (FR-4):**

1. `$qb = $this->connection->createQueryBuilder()->select('*')->from(self::TABLE)`.
2. Conditional `andWhere()` + `setParameter()` for `query`, `type`, `groupId` criteria — same logic as current.
3. COUNT: clone `$qb`, `->select('COUNT(*)')`, `->setMaxResults(null)->setFirstResult(0)` → `executeQuery()->fetchOne()` cast to `int`.
4. Sort validation: same `$allowedSorts` whitelist + `strtoupper` direction check.
5. `$qb->orderBy($sortColumn, $sortDirection)->setMaxResults($criteria->perPage)->setFirstResult(($criteria->page - 1) * $criteria->perPage)`.
6. `$qb->executeQuery()->fetchAllAssociative()` → hydrate each row.

**Hydration:** Copied verbatim from `PdoUserRepository::hydrate()`.

**Exception handling:** Try/catch `Doctrine\DBAL\Exception` → `RepositoryException`.

---

## 4. Integration Test Harness

### 4.1 `IntegrationTestCase` Base Class

| Aspect | Detail |
|---|---|
| **Location** | `tests/phpbb/Integration/IntegrationTestCase.php` |
| **Namespace** | `phpbb\Tests\Integration` |
| **Extends** | `PHPUnit\Framework\TestCase` |

**Design:**

```
abstract class IntegrationTestCase extends TestCase
{
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = DriverManager::getConnection([
            'driver' => 'pdo_sqlite',
            'memory' => true,
        ]);
        $this->setUpSchema();
    }

    abstract protected function setUpSchema(): void;
}
```

No Symfony kernel. No fixture loading. Pure DBAL — fast and side-effect-free.

### 4.2 DDL Patterns (Inline in Each Test's `setUpSchema()`)

**`DbalRefreshTokenRepositoryTest`:**

```sql
CREATE TABLE phpbb_auth_refresh_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    family_id  TEXT    NOT NULL,
    token_hash TEXT    NOT NULL UNIQUE,
    issued_at  INTEGER NOT NULL,
    expires_at INTEGER NOT NULL,
    revoked_at INTEGER DEFAULT NULL
)
```

**`DbalBanRepositoryTest`:**

```sql
CREATE TABLE phpbb_banlist (
    ban_id          INTEGER PRIMARY KEY AUTOINCREMENT,
    ban_userid      INTEGER NOT NULL DEFAULT 0,
    ban_ip          TEXT    NOT NULL DEFAULT '',
    ban_email       TEXT    NOT NULL DEFAULT '',
    ban_start       INTEGER NOT NULL DEFAULT 0,
    ban_end         INTEGER NOT NULL DEFAULT 0,
    ban_exclude     INTEGER NOT NULL DEFAULT 0,
    ban_reason      TEXT    NOT NULL DEFAULT '',
    ban_give_reason TEXT    NOT NULL DEFAULT ''
)
```

**`DbalGroupRepositoryTest`:**

```sql
CREATE TABLE phpbb_groups (
    group_id           INTEGER PRIMARY KEY AUTOINCREMENT,
    group_type         INTEGER NOT NULL DEFAULT 0,
    group_name         TEXT    NOT NULL DEFAULT '',
    group_desc         TEXT    NOT NULL DEFAULT '',
    group_display      INTEGER NOT NULL DEFAULT 0,
    group_legend       INTEGER NOT NULL DEFAULT 0,
    group_colour       TEXT    NOT NULL DEFAULT '',
    group_rank         INTEGER NOT NULL DEFAULT 0,
    group_avatar       TEXT    NOT NULL DEFAULT '',
    group_receive_pm   INTEGER NOT NULL DEFAULT 0,
    group_message_limit   INTEGER NOT NULL DEFAULT 0,
    group_max_recipients  INTEGER NOT NULL DEFAULT 0,
    group_founder_manage  INTEGER NOT NULL DEFAULT 0,
    group_skip_auth       INTEGER NOT NULL DEFAULT 0,
    group_teampage        INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE phpbb_user_group (
    group_id     INTEGER NOT NULL,
    user_id      INTEGER NOT NULL,
    group_leader INTEGER NOT NULL DEFAULT 0,
    user_pending INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (group_id, user_id)
)
```

**`DbalUserRepositoryTest`:**

```sql
CREATE TABLE phpbb_users (
    user_id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_type            INTEGER NOT NULL DEFAULT 0,
    username             TEXT    NOT NULL DEFAULT '',
    username_clean       TEXT    NOT NULL DEFAULT '',
    user_email           TEXT    NOT NULL DEFAULT '',
    user_password        TEXT    NOT NULL DEFAULT '',
    user_colour          TEXT    NOT NULL DEFAULT '',
    group_id             INTEGER NOT NULL DEFAULT 0,
    user_avatar          TEXT    NOT NULL DEFAULT '',
    user_regdate         INTEGER NOT NULL DEFAULT 0,
    user_lastmark        INTEGER NOT NULL DEFAULT 0,
    user_posts           INTEGER NOT NULL DEFAULT 0,
    user_lastpost_time   INTEGER NOT NULL DEFAULT 0,
    user_new             INTEGER NOT NULL DEFAULT 1,
    user_rank            INTEGER NOT NULL DEFAULT 0,
    user_ip              TEXT    NOT NULL DEFAULT '',
    user_login_attempts  INTEGER NOT NULL DEFAULT 0,
    user_inactive_reason INTEGER NOT NULL DEFAULT 0,
    user_form_salt       TEXT    NOT NULL DEFAULT '',
    user_actkey          TEXT    NOT NULL DEFAULT '',
    token_generation     INTEGER NOT NULL DEFAULT 0,
    perm_version         INTEGER NOT NULL DEFAULT 0
)
```

### 4.3 Test File Locations

| Test class | Location |
|---|---|
| `DbalRefreshTokenRepositoryTest` | `tests/phpbb/auth/Repository/DbalRefreshTokenRepositoryTest.php` |
| `DbalBanRepositoryTest` | `tests/phpbb/user/Repository/DbalBanRepositoryTest.php` |
| `DbalGroupRepositoryTest` | `tests/phpbb/user/Repository/DbalGroupRepositoryTest.php` |
| `DbalUserRepositoryTest` | `tests/phpbb/user/Repository/DbalUserRepositoryTest.php` |

### 4.4 Test Case Coverage per Repository

Each test class covers 2-8 focused assertions per interface method:

| Repository | Methods | Minimum tests |
|---|---|---|
| DbalRefreshTokenRepository | 6 | 8 (save, findByHash-found, findByHash-notFound, revokeByHash, revokeFamily, revokeAllForUser, deleteExpired, revoked-at-nullable) |
| DbalBanRepository | 7 | 8 (isUserBanned-true/false, isIpBanned, isEmailBanned, findById-found/notFound, findAll, create, delete) |
| DbalGroupRepository | 5 | 7 (findById, findAll-all/byType, getMembershipsForUser, addMember-insert, addMember-upsert-idempotent, removeMember) |
| DbalUserRepository | 10 | 8+ (findById, findByIds, findByUsername, findByEmail, create, update-partial, delete, search-pagination, findDisplayByIds, incrementTokenGeneration) |

### 4.5 `phpunit.xml` Change

Add PHPBB DB env vars for SQLite (no extra test suite needed — the integration tests live under `tests/phpbb/` along with unit tests):

```xml
<php>
    <env name="PHPBB_JWT_SECRET" value="test-secret-minimum-32-chars-ok!"/>
    <env name="PHPBB_APP_ENV"    value="test"/>
    <env name="PHPBB_APP_DEBUG"  value="0"/>
    <!-- DBAL integration tests use SQLite in-memory — no external DB env needed -->
</php>
```

No additional env vars are required because `IntegrationTestCase` hardcodes the SQLite params array directly — no env var lookups.

---

## 5. Platform-Switched Upsert

### `DbalGroupRepository::addMember()` — Pseudocode

```
function addMember(int $groupId, int $userId, bool $isLeader = false): void

    try:
        platform = $this->connection->getDatabasePlatform()

        if platform instanceof Doctrine\DBAL\Platforms\MySQLPlatform:
            // Covers both MySQL and MariaDB (MariaDBPlatform extends MySQLPlatform)
            $this->connection->executeStatement(
                'INSERT INTO phpbb_user_group
                    (group_id, user_id, group_leader, user_pending)
                 VALUES
                    (:groupId, :userId, :isLeader, 0)
                 ON DUPLICATE KEY UPDATE
                    group_leader = :isLeader,
                    user_pending = 0',
                [
                    ':groupId'  => $groupId,
                    ':userId'   => $userId,
                    ':isLeader' => (int) $isLeader,
                ]
            )
        else:
            // SQLite (tests) and any future platform
            // Two-step: DELETE then INSERT — atomic within a transaction
            $this->connection->transactional(
                function (Connection $conn) use ($groupId, $userId, $isLeader): void {
                    $conn->executeStatement(
                        'DELETE FROM phpbb_user_group
                          WHERE group_id = :groupId AND user_id = :userId',
                        [':groupId' => $groupId, ':userId' => $userId]
                    )
                    $conn->executeStatement(
                        'INSERT INTO phpbb_user_group
                            (group_id, user_id, group_leader, user_pending)
                         VALUES
                            (:groupId, :userId, :isLeader, 0)',
                        [
                            ':groupId'  => $groupId,
                            ':userId'   => $userId,
                            ':isLeader' => (int) $isLeader,
                        ]
                    )
                }
            )

    catch Doctrine\DBAL\Exception as $e:
        throw new phpbb\db\Exception\RepositoryException(
            'Failed to add group member: ' . $e->getMessage(),
            previous: $e
        )
```

**Test assertion for idempotency (SQLite path):**
Call `addMember(1, 1, false)` twice → assert exactly 1 row exists + `group_leader = 0`. Then call with `isLeader = true` → assert `group_leader = 1`.

---

## 6. DI Changes

### 6.1 `services.yaml` — Database Block (Before → After)

**BEFORE:**

```yaml
# ---------------------------------------------------------------------------
# Database connection (native PDO — no Doctrine DBAL)
# ---------------------------------------------------------------------------

PDO:
    class: PDO
    factory: ['phpbb\db\PdoFactory', 'create']
    arguments:
        $host:     '%env(PHPBB_DB_HOST)%'
        $dbname:   '%env(PHPBB_DB_NAME)%'
        $user:     '%env(PHPBB_DB_USER)%'
        $password: '%env(PHPBB_DB_PASSWD)%'
    public: false
```

**AFTER:**

```yaml
# ---------------------------------------------------------------------------
# Database connection (Doctrine DBAL 4 — no ORM, no DoctrineBundle)
# ---------------------------------------------------------------------------

phpbb\db\DbalConnectionFactory: ~

Doctrine\DBAL\Connection:
    factory: ['@phpbb\db\DbalConnectionFactory', 'create']
    arguments:
        $host:     '%env(PHPBB_DB_HOST)%'
        $dbname:   '%env(PHPBB_DB_NAME)%'
        $user:     '%env(PHPBB_DB_USER)%'
        $password: '%env(PHPBB_DB_PASSWD)%'
    public: false
```

### 6.2 `services.yaml` — Repository Aliases (Before → After)

**User module:**

```yaml
# BEFORE
phpbb\user\Repository\PdoUserRepository: ~
phpbb\user\Contract\UserRepositoryInterface:
    alias: phpbb\user\Repository\PdoUserRepository
    public: true

phpbb\user\Repository\PdoGroupRepository: ~
phpbb\user\Contract\GroupRepositoryInterface:
    alias: phpbb\user\Repository\PdoGroupRepository
    public: true

phpbb\user\Repository\PdoBanRepository: ~
phpbb\user\Contract\BanRepositoryInterface:
    alias: phpbb\user\Repository\PdoBanRepository
    public: true

# AFTER
phpbb\user\Repository\DbalUserRepository: ~
phpbb\user\Contract\UserRepositoryInterface:
    alias: phpbb\user\Repository\DbalUserRepository
    public: true

phpbb\user\Repository\DbalGroupRepository: ~
phpbb\user\Contract\GroupRepositoryInterface:
    alias: phpbb\user\Repository\DbalGroupRepository
    public: true

phpbb\user\Repository\DbalBanRepository: ~
phpbb\user\Contract\BanRepositoryInterface:
    alias: phpbb\user\Repository\DbalBanRepository
    public: true
```

**Auth module:**

```yaml
# BEFORE
phpbb\auth\Repository\PdoRefreshTokenRepository: ~
phpbb\auth\Contract\RefreshTokenRepositoryInterface:
    alias: phpbb\auth\Repository\PdoRefreshTokenRepository

# AFTER
phpbb\auth\Repository\DbalRefreshTokenRepository: ~
phpbb\auth\Contract\RefreshTokenRepositoryInterface:
    alias: phpbb\auth\Repository\DbalRefreshTokenRepository
```

### 6.3 `bundles.php`

No change. DoctrineBundle is NOT adopted.

### 6.4 `composer.json`

Add to `require`:

```json
"doctrine/dbal": "^4.0"
```

No `require-dev` entry needed — SQLite PDO driver support is bundled with `pdo_sqlite`.

---

## 7. Migration-Boundary Rules

### What Changes (M4 scope)

| File | Change type |
|---|---|
| `composer.json` | Add `doctrine/dbal: ^4.0` |
| `src/phpbb/db/DbalConnectionFactory.php` | **Create new** |
| `src/phpbb/auth/Repository/DbalRefreshTokenRepository.php` | **Create new** |
| `src/phpbb/user/Repository/DbalBanRepository.php` | **Create new** |
| `src/phpbb/user/Repository/DbalGroupRepository.php` | **Create new** |
| `src/phpbb/user/Repository/DbalUserRepository.php` | **Create new** |
| `src/phpbb/db/Exception/RepositoryException.php` | **Create new** |
| `src/phpbb/config/services.yaml` | Replace PDO block + 4 repository aliases |
| `tests/phpbb/Integration/IntegrationTestCase.php` | **Create new** |
| `tests/phpbb/auth/Repository/DbalRefreshTokenRepositoryTest.php` | **Create new** |
| `tests/phpbb/user/Repository/DbalBanRepositoryTest.php` | **Create new** |
| `tests/phpbb/user/Repository/DbalGroupRepositoryTest.php` | **Create new** |
| `tests/phpbb/user/Repository/DbalUserRepositoryTest.php` | **Create new** |

### What Is Deleted After Green Tests

| File | Reason |
|---|---|
| `src/phpbb/db/PdoFactory.php` | Replaced by `DbalConnectionFactory` |
| `src/phpbb/auth/Repository/PdoRefreshTokenRepository.php` | Replaced by `DbalRefreshTokenRepository` |
| `src/phpbb/user/Repository/PdoBanRepository.php` | Replaced by `DbalBanRepository` |
| `src/phpbb/user/Repository/PdoGroupRepository.php` | Replaced by `DbalGroupRepository` |
| `src/phpbb/user/Repository/PdoUserRepository.php` | Replaced by `DbalUserRepository` |
| `tests/phpbb/auth/Repository/PdoRefreshTokenRepositoryTest.php` | Replaced by DBAL integration test |
| `tests/phpbb/user/Repository/PdoUserRepositoryTest.php` | Replaced by DBAL integration test |

### What Stays Frozen (No Source Changes Allowed)

| Asset | Reason |
|---|---|
| `src/phpbb/auth/Contract/RefreshTokenRepositoryInterface.php` | Contract freeze |
| `src/phpbb/user/Contract/UserRepositoryInterface.php` | Contract freeze |
| `src/phpbb/user/Contract/BanRepositoryInterface.php` | Contract freeze |
| `src/phpbb/user/Contract/GroupRepositoryInterface.php` | Contract freeze |
| All entity classes (`RefreshToken`, `User`, `Ban`, `Group`, `GroupMembership`) | Domain objects unchanged |
| All DTO classes (`UserDisplayDTO`, `PaginatedResult`, `UserSearchCriteria`) | Unchanged |
| All enum classes (`BanType`, `GroupType`, `UserType`, `InactiveReason`) | Unchanged |
| All 6 consuming service classes | Inject via interface; zero touch |
| `src/phpbb/config/services_test.yaml` | Environment overrides; not affected |
| Hydration logic in each `private function hydrate()` | Copy verbatim; no logic change |
| `tests/e2e/` (Playwright suite) | Must stay green but no changes needed |

---

## 8. Acceptance Criteria

The following conditions must all be true before M4 is declared complete:

| # | Criterion | Verification |
|---|---|---|
| AC-1 | All PHPUnit tests pass (145+ green, 0 red) | `vendor/bin/phpunit` exits 0 |
| AC-2 | All 21 Playwright E2E tests pass | `npx playwright test` exits 0 |
| AC-3 | Zero references to `\PDO` or `PDO::` in `src/phpbb/` | `grep -r 'PDO' src/phpbb/ --include='*.php'` returns empty |
| AC-4 | Zero references to `PdoFactory`, `PdoRefreshTokenRepository`, `PdoBanRepository`, `PdoGroupRepository`, `PdoUserRepository` anywhere in the codebase | grep returns empty |
| AC-5 | `Doctrine\DBAL\Connection` resolves as a Symfony service (container compiles) | `bin/phpbbcli.php debug:container Doctrine\\DBAL\\Connection` exits 0 |
| AC-6 | `DbalRefreshTokenRepositoryTest` covers all 6 interface methods with real SQL on SQLite | PHPUnit output shows ≥8 assertions for that class |
| AC-7 | `DbalBanRepositoryTest` covers all 7 interface methods with real SQL on SQLite | PHPUnit output shows ≥8 assertions for that class |
| AC-8 | `DbalGroupRepositoryTest` covers `addMember()` idempotency on the SQLite (else-branch) path | Explicit idempotency assertion passes |
| AC-9 | `DbalUserRepositoryTest` covers `search()` pagination and `findByIds()` IN-list with real SQL | Test assertions for paginated results + multi-ID lookup pass |
| AC-10 | `services.yaml` contains no `PDO` service entry | Manual review of yaml |
| AC-11 | `composer.json` contains `doctrine/dbal: ^4.0` in `require` | Manual review |
| AC-12 | `bundles.php` does NOT register DoctrineBundle | Manual review |

---

## 9. Domain Exception Design

### Exception Class

**Location:** `src/phpbb/db/Exception/RepositoryException.php`

**Namespace:** `phpbb\db\Exception`

**Design:**

```php
final class RepositoryException extends \RuntimeException
{
    // No extra methods or properties — acts as a typed wrapper.
    // DBAL original exception is preserved via $previous parameter.
}
```

### Usage Pattern (All Repositories)

```php
try {
    // ... DBAL call ...
} catch (\Doctrine\DBAL\Exception $e) {
    throw new \phpbb\db\Exception\RepositoryException(
        'Descriptive message: ' . $e->getMessage(),
        previous: $e,
    );
}
```

### Rationale

- Single exception class is sufficient: no consumer currently catches by repository exception type.
- The `$e->getPrevious()` chain preserves the full DBAL stack trace for debugging.
- If a future milestone requires distinguishing `UniqueConstraintViolationException` (e.g. duplicate email on create), a `DuplicateEntityException extends RepositoryException` sub-class can be added without breaking existing catch blocks.
- The class lives in `phpbb\db\Exception\` (not per-module) because it is shared by all four repositories across `auth` and `user` modules.

### What Is NOT Caught

- `\LogicException` subclasses from DBAL (programming errors like invalid column types) — let these propagate as fatal errors.
- `\RuntimeException` from `findById()` / `findAll()` post-insert re-fetch failures (existing `throw new \RuntimeException(...)` pattern in `create()`) — these are internal consistency failures, not DBAL transport errors. They remain as-is.

---

## 10. Spec Amendments (from audit — post Phase 6)

### CRIT-1 Fix: `create()` methods must re-fetch and return the entity

Both `DbalBanRepository::create()` and `DbalUserRepository::create()` must:
1. Execute the INSERT.
2. Retrieve the new ID via `(int) $this->connection->lastInsertId()`.
3. Call `$this->findById($newId)` to re-fetch the hydrated entity.
4. If the entity is null after re-fetch (shouldn't happen but guard against it), throw `new \RuntimeException('Failed to re-fetch entity after INSERT')`.
5. Return the entity.

**Explicit pseudocode for `DbalBanRepository::create()`:**
```php
$this->connection->executeStatement(SQL, [named params]);
$id  = (int) $this->connection->lastInsertId();
$ban = $this->findById($id) ?? throw new \RuntimeException('Ban not found after INSERT');
return $ban;
```

**Explicit pseudocode for `DbalUserRepository::create()`:**
```php
$this->connection->executeStatement(SQL, [named params]);
$id   = (int) $this->connection->lastInsertId();
$user = $this->findById($id) ?? throw new \RuntimeException('User not found after INSERT');
return $user;
```

### HIGH-1 Fix: `DbalGroupRepository::findAll()` — conditional SQL

The SQL itself is also conditional (not just `$params`):

```php
if ($type !== null) {
    $sql    = 'SELECT * FROM ' . self::TABLE . ' WHERE group_type = :type ORDER BY group_name ASC';
    $params = [':type' => $type->value];
} else {
    $sql    = 'SELECT * FROM ' . self::TABLE . ' ORDER BY group_name ASC';
    $params = [];
}

return array_map(
    [$this, 'hydrate'],
    $this->connection->executeQuery($sql, array_values($params))->fetchAllAssociative(),
);
```

Note: Using separate SQL strings prevents DBAL from complaining about an unbound `:type` parameter.

### HIGH-2 Fix: `findByIds()` and `findDisplayByIds()` return keyed arrays

**`findByIds(array $ids): array<int, User>`:**
```php
if ($ids === []) {
    return [];
}
$rows   = $this->connection->executeQuery(SQL, [$ids], [ArrayParameterType::INTEGER])->fetchAllAssociative();
$result = [];
foreach ($rows as $row) {
    $user          = $this->hydrate($row);
    $result[$user->id] = $user;
}
return $result;
```

**`findDisplayByIds(array $ids): array<int, UserDisplayDTO>`:**
- Does NOT use `hydrate()`. Uses inline `UserDisplayDTO` construction from 4 columns only.
- Returns keyed by `user_id`.

```php
if ($ids === []) {
    return [];
}
$rows   = $this->connection->executeQuery(SQL, [$ids], [ArrayParameterType::INTEGER])->fetchAllAssociative();
$result = [];
foreach ($rows as $row) {
    $dto          = new UserDisplayDTO(
        id:       (int) $row['user_id'],
        username: $row['username'],
        colour:   $row['user_colour'],
        avatar:   $row['user_avatar'],
    );
    $result[$dto->id] = $dto;
}
return $result;
```

### WARN-1: Empty `$ids` guard (confirmed in HIGH-2 fix above)

Both `findByIds([])` and `findDisplayByIds([])` must return `[]` immediately before attempting `executeQuery`. DBAL 4 converts empty `ArrayParameterType::INTEGER` to `IN (NULL)` safely, but the guard avoids unnecessary DB round-trips and makes intent explicit.


## Reusable Components

### Existing Code Leveraged

| Component | Source | Reuse |
|---|---|---|
| Hydration logic (`private function hydrate()`) | All 4 PDO repos | Copy verbatim — no change needed |
| `private const TABLE` / `TABLE_PIVOT` | All 4 PDO repos | Copy verbatim |
| Allowed-column whitelist in `update()` | `PdoUserRepository` | Copy verbatim |
| Allowed-sorts whitelist in `search()` | `PdoUserRepository` | Copy verbatim |
| SQL strings (SELECT/INSERT/UPDATE/DELETE) | All 4 PDO repos | Reused without change for named-param ops |
| `BanType` hydration logic (non-empty column check) | `PdoBanRepository` | Copy verbatim |
| `GroupMembership` inline hydration | `PdoGroupRepository` | Copy verbatim |
| `services.yaml` factory pattern | `PdoFactory` block | Template for `DbalConnectionFactory` block |

### New Code Required

| Component | Justification |
|---|---|
| `DbalConnectionFactory` | `PdoFactory` is static and MySQL-only. DBAL `DriverManager` call with `serverVersion` param cannot be adapted from PDO factory. |
| `RepositoryException` | No existing domain exception in `phpbb\db`. Required by FR-6. |
| `IntegrationTestCase` | No existing SQLite test helper. Required by FR-7. |
| QueryBuilder usage in `update()` / `search()` | Required by FR-4; current PDO string concatenation is equivalent but DBAL offers native QueryBuilder for type-safe parameter binding. |

---

## Standards Compliance

- **Backend standards** ([.maister/docs/standards/backend/STANDARDS.md](.maister/docs/standards/backend/STANDARDS.md)): PDO prepared statements → DBAL parameterized API; namespacing under `phpbb\`; DI via Symfony container; no `global` in OOP code.
- **Testing standards** ([.maister/docs/standards/testing/STANDARDS.md](.maister/docs/standards/testing/STANDARDS.md)): PHPUnit; 2-8 focused tests per step group; isolation via SQLite in-memory.
- **Global conventions** ([.maister/docs/standards/global/STANDARDS.md](.maister/docs/standards/global/STANDARDS.md)): Tabs for indentation; no closing PHP tag; `declare(strict_types=1)`; GPL-2.0 file header.

---

## Out of Scope

- DoctrineBundle, Doctrine ORM, `doctrine/migrations`
- Schema migration or DDL management for production database
- Legacy `src/phpbb3/` database layer
- API contract changes or UI changes
- Performance benchmarks comparing PDO vs DBAL
- PostgreSQL or other platform support

---

*End of specification.*
