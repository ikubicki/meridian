# Implementation Plan: M4 — Adopt Doctrine DBAL 4 in phpbb namespace

**Date:** 2026-04-22
**Spec:** `implementation/spec.md`
**Task path:** `.maister/tasks/development/2026-04-22-doctrine-dbal-adoption/`

---

## Overview

| Metric | Value |
|--------|-------|
| Task groups | 6 (A–F) |
| Total steps | 36 |
| Expected tests | 31–39 (8 + 8 + 7 + 8 + up to 10 review) |
| Has testing group | Yes (Group F includes verification + final E2E) |
| Repositories migrated | 4 (`RefreshToken`, `Ban`, `Group`, `User`) |
| Files deleted | 7 (5 source + 2 test) |
| Files created | 13 (5 source + 1 base test + 4 repo tests + 2 infra) |

---

## Implementation Steps

---

### Task Group A — Foundation & Infrastructure
**Dependencies:** None  
**Estimated Steps:** 6

- [x] A.0 Complete foundation infrastructure
  - [x] A.1 Write infrastructure smoke tests (3 tests)
    - `tests/phpbb/db/DbalConnectionFactoryTest.php` — verify `create()` returns a `Doctrine\DBAL\Connection` instance using SQLite in-memory params
    - `tests/phpbb/Integration/IntegrationTestCaseTest.php` — verify `setUp()` creates a live Connection and `setUpSchema()` is called (use a concrete anonymous subclass with trivial DDL)
    - Verify `RepositoryException` is a `\RuntimeException` subclass and preserves `$previous`
  - [x] A.2 `composer require doctrine/dbal:^4.0` — add DBAL to project
    - Run: `composer require doctrine/dbal:^4.0`
    - Verify `composer.json` `require` block contains `"doctrine/dbal": "^4.0"`
    - Verify `vendor/doctrine/dbal/` directory exists
  - [x] A.3 Create `src/phpbb/db/Exception/RepositoryException.php`
    - Namespace: `phpbb\db\Exception`
    - `final class RepositoryException extends \RuntimeException` — no extra methods/properties
    - File header: GPL-2.0, `declare(strict_types=1)`, no closing PHP tag, tabs for indent
  - [x] A.4 Create `src/phpbb/db/DbalConnectionFactory.php`
    - Namespace: `phpbb\db`
    - No constructor parameters (registered as `~` in services.yaml)
    - Method `create(string $host, string $dbname, string $user, string $password, string $port = ''): \Doctrine\DBAL\Connection`
    - Assembles params array: `driver=pdo_mysql`, `charset=utf8mb4`, `serverVersion=mariadb-10.11.0`, port cast to `(int) $port ?: 3306`
    - Calls `\Doctrine\DBAL\DriverManager::getConnection($params)` and returns result
    - No exception handling in factory — bootstrap failure is fatal; let DBAL propagate
  - [x] A.5 Create `tests/phpbb/Integration/IntegrationTestCase.php`
    - Namespace: `phpbb\Tests\Integration`
    - `abstract class IntegrationTestCase extends \PHPUnit\Framework\TestCase`
    - Property: `protected \Doctrine\DBAL\Connection $connection`
    - `setUp()`: calls `parent::setUp()`, creates SQLite in-memory connection via `DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])`, then calls `$this->setUpSchema()`
    - `abstract protected function setUpSchema(): void`
    - No Symfony kernel; no fixtures; pure DBAL
  - [x] A.6 Update `src/phpbb/config/services.yaml` — add DBAL Connection block (PDO kept until Groups B-E complete)
    - Remove the entire `PDO:` service block (class, factory, arguments)
    - Add comment `# Database connection (Doctrine DBAL 4 — no ORM, no DoctrineBundle)`
    - Add `phpbb\db\DbalConnectionFactory: ~`
    - Add `Doctrine\DBAL\Connection:` service with `factory: ['@phpbb\db\DbalConnectionFactory', 'create']` and same four env var arguments (`$host`, `$dbname`, `$user`, `$password`)
    - Keep `public: false` on the Connection service
    - Run: `php bin/phpbbcli.php debug:container 'Doctrine\DBAL\Connection'` — must exit 0 and show the Connection service
  - [x] A.7 Ensure infrastructure tests pass
    - Run ONLY the 3 tests written in A.1
    - All 3 must be green before proceeding to Group B

**Acceptance Criteria:**
- All 3 infrastructure tests pass
- `vendor/doctrine/dbal/` exists; `composer.json` has `"doctrine/dbal": "^4.0"` in `require`
- `src/phpbb/db/Exception/RepositoryException.php` exists as final class extending `\RuntimeException`
- `src/phpbb/db/DbalConnectionFactory.php` exists; `create()` returns `Doctrine\DBAL\Connection`
- `tests/phpbb/Integration/IntegrationTestCase.php` exists as abstract base class
- `services.yaml` contains no `PDO:` service entry; contains `Doctrine\DBAL\Connection:` service
- `bin/phpbbcli.php debug:container 'Doctrine\DBAL\Connection'` exits 0

---

### Task Group B — RefreshToken Repository (Pilot — Simplest, No MySQL-specific SQL)
**Dependencies:** A  
**Estimated Steps:** 5

- [x] B.0 Complete RefreshToken repository migration
  - [x] B.1 Write 8 tests for `DbalRefreshTokenRepository` (SQLite in-memory)
    - File: `tests/phpbb/auth/Repository/DbalRefreshTokenRepositoryTest.php`
    - Extends `IntegrationTestCase`
    - `setUpSchema()`: inline DDL for `phpbb_auth_refresh_tokens` (7 columns: `id`, `user_id`, `family_id`, `token_hash UNIQUE`, `issued_at`, `expires_at`, `revoked_at DEFAULT NULL`) — INTEGER PRIMARY KEY AUTOINCREMENT on SQLite
    - Tests:
      1. `testSaveAndFindByHash_returnsToken` — save a token, findByHash by same hash → assert entity not null, fields match
      2. `testFindByHash_notFound_returnsNull` — findByHash with unknown hash → assert null
      3. `testRevokeByHash_setsRevokedAt` — save token, revokeByHash → findByHash → assert `revokedAt` is non-null
      4. `testRevokeFamily_revokesAllInFamily` — save 2 tokens with same familyId, revokeFamily → both show non-null `revokedAt`
      5. `testRevokeAllForUser_revokesAllUserTokens` — save tokens for userId 1 and userId 2, revokeAllForUser(1) → only userId 1 tokens revoked
      6. `testDeleteExpired_removesTokensPastExpiry` — save expired token (expires_at in the past), save active token, deleteExpired → active token still findable, expired token not found
      7. `testSave_updatesExistingToken` — save token, modify and save again (same hash) → expect upsert/update behaviour (depends on ON DUPLICATE KEY; on SQLite this will be an INSERT or REPLACE — verify spec behaviour)
      8. `testRevokedAt_nullable_onFreshToken` — save token without revokedAt → findByHash → assert `revokedAt` is null on entity
  - [x] B.2 Create `src/phpbb/auth/Repository/DbalRefreshTokenRepository.php`
    - Namespace: `phpbb\auth\Repository`
    - Implements `phpbb\auth\Contract\RefreshTokenRepositoryInterface`
    - Constructor: `__construct(private readonly \Doctrine\DBAL\Connection $connection)`
    - Method mapping (all 6 interface methods, all using named params):
      - `save()` → `executeStatement(SQL, [named params])` with timestamps as `->getTimestamp()`
      - `findByHash()` → `executeQuery(SQL, [':hash' => $hash])->fetchAssociative()` → null if false
      - `revokeByHash()` → `executeStatement(SQL, [':now' => time(), ':hash' => $hash])`
      - `revokeFamily()` → `executeStatement(SQL, [':now' => time(), ':familyId' => $familyId])`
      - `revokeAllForUser()` → `executeStatement(SQL, [':now' => time(), ':userId' => $userId])`
      - `deleteExpired()` → `executeStatement(SQL, [':now' => time()])`
    - Copy `hydrate()` verbatim from `PdoRefreshTokenRepository` (timestamp handling via `DateTimeImmutable::createFromFormat('U', (string) $row[...])`)
    - Wrap each public method body in `try { ... } catch (\Doctrine\DBAL\Exception $e) { throw new \phpbb\db\Exception\RepositoryException('...', previous: $e); }`
  - [x] B.3 Update `src/phpbb/config/services.yaml` — swap RefreshToken alias
    - Remove: `phpbb\auth\Repository\PdoRefreshTokenRepository: ~` and its alias entry
    - Add: `phpbb\auth\Repository\DbalRefreshTokenRepository: ~`
    - Change alias: `phpbb\auth\Contract\RefreshTokenRepositoryInterface → DbalRefreshTokenRepository`
    - Verify container still compiles: `php bin/phpbbcli.php debug:container 'phpbb\auth\Contract\RefreshTokenRepositoryInterface'`
  - [x] B.4 Run PHPUnit — 8 RefreshToken tests must pass
    - Run ONLY `DbalRefreshTokenRepositoryTest`
    - All 8 must be green
  - [x] B.5 Delete pilot PDO implementations
    - Delete `src/phpbb/auth/Repository/PdoRefreshTokenRepository.php`
    - Delete `tests/phpbb/auth/Repository/PdoRefreshTokenRepositoryTest.php`
    - Run full PHPUnit suite to confirm no regressions (pre-existing tests unrelated to RefreshToken must still pass)

**Acceptance Criteria:**
- All 8 `DbalRefreshTokenRepositoryTest` tests pass on SQLite
- `PdoRefreshTokenRepository.php` and `PdoRefreshTokenRepositoryTest.php` deleted
- `services.yaml` alias points to `DbalRefreshTokenRepository`
- Container resolves `RefreshTokenRepositoryInterface` → `DbalRefreshTokenRepository`
- Full PHPUnit suite exits 0 (no regressions)

---

### Task Group C — Ban Repository
**Dependencies:** A, B  
**Estimated Steps:** 5

- [x] C.0 Complete Ban repository migration
  - [x] C.1 Write 8 tests for `DbalBanRepository` (SQLite in-memory, no prior PDO tests existed)
    - File: `tests/phpbb/user/Repository/DbalBanRepositoryTest.php`
    - Extends `IntegrationTestCase`
    - `setUpSchema()`: inline DDL for `phpbb_banlist` (9 columns per spec §4.2: `ban_id`, `ban_userid`, `ban_ip`, `ban_email`, `ban_start`, `ban_end`, `ban_exclude`, `ban_reason`, `ban_give_reason`)
    - Tests:
      1. `testIsUserBanned_returnsTrueForActiveBan` — insert ban row with ban_userid=5, ban_end=far future → assert isUserBanned(5) === true
      2. `testIsUserBanned_returnsFalseForExpiredBan` — insert ban with ban_end in the past → assert false
      3. `testIsIpBanned_returnsTrueForMatchingIp` — insert ban row with ban_ip='1.2.3.4' → assert isIpBanned('1.2.3.4') === true
      4. `testIsEmailBanned_caseInsensitive` — insert ban_email='User@Example.COM' → assert isEmailBanned('user@example.com') === true (mb_strtolower applied)
      5. `testFindById_found_returnsHydratedBan` — insert row, findById → assert Ban entity fields match
      6. `testFindById_notFound_returnsNull` — findById(99999) → assert null
      7. `testFindAll_returnsAllBansOrderedByBanId` — insert 3 rows, findAll() → assert count=3, ordered ASC by ban_id
      8. `testCreate_returnsHydratedBanWithId` — call create($data) → assert returned Ban has non-zero id and fields match; also assert id matches lastInsertId re-fetch (CRIT-1 fix verified)
      9. `testDelete_removesRow` — insert row, delete(id), findById(id) → null (bonus test if 8 already covers the rest)
  - [x] C.2 Create `src/phpbb/user/Repository/DbalBanRepository.php`
    - Namespace: `phpbb\user\Repository`
    - Implements `phpbb\user\Contract\BanRepositoryInterface`
    - Constructor: `__construct(private readonly \Doctrine\DBAL\Connection $connection)`
    - `private const TABLE = 'phpbb_banlist'`
    - Method mapping:
      - `isUserBanned()` / `isIpBanned()` / `isEmailBanned()` → `executeQuery(SQL, [params])->fetchOne()` → result `!== false` cast to bool; `isEmailBanned` applies `mb_strtolower($email)` to param before binding
      - `findById()` → `executeQuery(SQL, [':id' => $id])->fetchAssociative()` → null if false
      - `findAll()` → `executeQuery('SELECT * FROM phpbb_banlist ORDER BY ban_id ASC')->fetchAllAssociative()` (no params; replaces `pdo->query()`)
      - `create()` → `executeStatement(SQL, [named params])` + `(int) $this->connection->lastInsertId()` + `$this->findById($newId) ?? throw new \RuntimeException('Ban not found after INSERT')` → return entity (CRIT-1)
      - `delete()` → `executeStatement('DELETE FROM phpbb_banlist WHERE ban_id = :id', [':id' => $id])`
    - Copy `hydrate()` verbatim from `PdoBanRepository` (BanType detection via non-empty column logic, DB-agnostic)
    - Try/catch `\Doctrine\DBAL\Exception` → `RepositoryException` on all public methods
  - [x] C.3 Update `src/phpbb/config/services.yaml` — swap Ban alias
    - Remove: `phpbb\user\Repository\PdoBanRepository: ~` and its alias entry
    - Add: `phpbb\user\Repository\DbalBanRepository: ~`
    - Change alias: `phpbb\user\Contract\BanRepositoryInterface → DbalBanRepository` (keep `public: true`)
    - Verify: `php bin/phpbbcli.php debug:container 'phpbb\user\Contract\BanRepositoryInterface'`
  - [x] C.4 Run PHPUnit — 8+ Ban tests must pass
    - Run ONLY `DbalBanRepositoryTest`
    - All 8+ must be green
  - [x] C.5 Delete PDO Ban implementation
    - Delete `src/phpbb/user/Repository/PdoBanRepository.php`
    - Run full PHPUnit suite to confirm no regressions

**Acceptance Criteria:**
- All 8+ `DbalBanRepositoryTest` tests pass on SQLite
- `create()` re-fetches via `findById` after `lastInsertId` (CRIT-1 verified by test)
- `findAll()` uses no-param `executeQuery` (not `->query()`)
- `isEmailBanned` applies `mb_strtolower` (verified by case-insensitive test)
- `PdoBanRepository.php` deleted
- `services.yaml` alias points to `DbalBanRepository`
- Full PHPUnit suite exits 0

---

### Task Group D — Group Repository (Platform-Switched Upsert)
**Dependencies:** A, B, C  
**Estimated Steps:** 5

- [x] D.0 Complete Group repository migration
  - [x] D.1 Write 7+ tests for `DbalGroupRepository` (SQLite in-memory, no prior PDO tests existed)
    - File: `tests/phpbb/user/Repository/DbalGroupRepositoryTest.php`
    - Extends `IntegrationTestCase`
    - `setUpSchema()`: inline DDL for both `phpbb_groups` (16 columns per spec §4.2) and `phpbb_user_group` (4 columns, composite PK `(group_id, user_id)`)
    - Tests:
      1. `testFindById_found_returnsHydratedGroup` — insert group row, findById → assert Group entity fields match
      2. `testFindById_notFound_returnsNull` — findById(99999) → null
      3. `testFindAll_noFilter_returnsAllGroups` — insert 3 groups with different types, findAll(null) → count=3
      4. `testFindAll_withTypeFilter_returnsOnlyMatchingGroups` — insert 2 groups type=0 and 1 type=1, findAll(GroupType::OPEN) → count=1 (HIGH-1 fix: separate SQL strings for conditional WHERE)
      5. `testGetMembershipsForUser_returnsCorrectMemberships` — insert 2 group-user rows for userId=1, getMembershipsForUser(1) → 2 memberships
      6. `testAddMember_insert_idempotency` — call `addMember(1, 1, false)` twice → assert exactly 1 row in `phpbb_user_group` with `group_leader=0` (SQLite else-branch: DELETE+INSERT in transaction)
      7. `testAddMember_leaderPromotion` — call `addMember(1, 1, false)` then `addMember(1, 1, true)` → assert `group_leader=1` in DB (upsert updates existing row)
      8. `testRemoveMember_deletesMembershipRow` — addMember, removeMember → assert 0 rows in `phpbb_user_group`
  - [x] D.2 Create `src/phpbb/user/Repository/DbalGroupRepository.php`
    - Namespace: `phpbb\user\Repository`
    - Implements `phpbb\user\Contract\GroupRepositoryInterface`
    - Constructor: `__construct(private readonly \Doctrine\DBAL\Connection $connection)`
    - `private const TABLE = 'phpbb_groups'`, `private const TABLE_PIVOT = 'phpbb_user_group'`
    - Method mapping:
      - `findById()` → `executeQuery(SQL, [':id' => $id])->fetchAssociative()` → null if false
      - `findAll(?GroupType $type = null)` → conditional SQL strings (HIGH-1 fix): if `$type !== null` use `WHERE group_type = :type` SQL + params `[':type' => $type->value]`; else plain `SELECT *` SQL + empty params. Call `array_map([$this, 'hydrate'], $result->fetchAllAssociative())`
      - `getMembershipsForUser()` → `executeQuery(SELECT join SQL, [':userId' => $userId])->fetchAllAssociative()` → inline `GroupMembership` hydration (copy verbatim from PdoGroupRepository)
      - `addMember()` → platform-switched upsert (see spec §5):
        - `$platform = $this->connection->getDatabasePlatform()`
        - If `$platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform` → `executeStatement('INSERT INTO ... ON DUPLICATE KEY UPDATE group_leader = :isLeader, user_pending = 0', [...])`
        - Else (SQLite / others) → `$this->connection->transactional(function(\Doctrine\DBAL\Connection $conn) use (...) { DELETE WHERE (groupId, userId); INSERT INTO ... })`
      - `removeMember()` → `executeStatement('DELETE FROM phpbb_user_group WHERE group_id = :groupId AND user_id = :userId', [...])`
    - Copy `hydrate()` verbatim from `PdoGroupRepository`
    - Try/catch `\Doctrine\DBAL\Exception` → `RepositoryException`
  - [x] D.3 Update `src/phpbb/config/services.yaml` — swap Group alias
    - Remove: `phpbb\user\Repository\PdoGroupRepository: ~` and its alias entry
    - Add: `phpbb\user\Repository\DbalGroupRepository: ~`
    - Change alias: `phpbb\user\Contract\GroupRepositoryInterface → DbalGroupRepository` (keep `public: true`)
    - Verify: `php bin/phpbbcli.php debug:container 'phpbb\user\Contract\GroupRepositoryInterface'`
  - [x] D.4 Run PHPUnit — 7+ Group tests must pass
    - Run ONLY `DbalGroupRepositoryTest`
    - All 7+ must be green, including both idempotency and leader promotion tests (D.1 tests 6 and 7)
  - [x] D.5 Delete PDO Group implementation
    - Delete `src/phpbb/user/Repository/PdoGroupRepository.php`
    - Run full PHPUnit suite to confirm no regressions

**Acceptance Criteria:**
- All 7+ `DbalGroupRepositoryTest` tests pass on SQLite
- `addMember` idempotency test: call twice → exactly 1 row in DB (SQLite else-branch)
- `addMember` leader promotion test: isLeader=false then true → `group_leader=1`
- `findAll(?GroupType)` uses separate SQL strings for conditional WHERE (HIGH-1 fix)
- `PdoGroupRepository.php` deleted
- `services.yaml` alias points to `DbalGroupRepository`
- Full PHPUnit suite exits 0

---

### Task Group E — User Repository (Largest — QueryBuilder for Dynamic Queries)
**Dependencies:** A, B, C, D  
**Estimated Steps:** 5

- [x] E.0 Complete User repository migration
  - [x] E.1 Write 8+ tests for `DbalUserRepository` (SQLite in-memory)
    - File: `tests/phpbb/user/Repository/DbalUserRepositoryTest.php`
    - Extends `IntegrationTestCase`
    - `setUpSchema()`: inline DDL for `phpbb_users` (22 columns per spec §4.2: `user_id`, `user_type`, `username`, `username_clean`, `user_email`, `user_password`, `user_colour`, `group_id`, `user_avatar`, `user_regdate`, `user_lastmark`, `user_posts`, `user_lastpost_time`, `user_new`, `user_rank`, `user_ip`, `user_login_attempts`, `user_inactive_reason`, `user_form_salt`, `user_actkey`, `token_generation`, `perm_version`)
    - Helper: `private function insertUser(array $overrides = []): int` — inserts a row with defaults and returns inserted id (direct `$this->connection->insert()` + `lastInsertId`)
    - Tests:
      1. `testFindById_found_returnsHydratedUser` — insertUser, findById → entity fields match
      2. `testFindById_notFound_returnsNull` — findById(99999) → null
      3. `testFindByIds_returnsKeyedArray` — insert 2 users, findByIds([id1, id2]) → assert count=2, array keyed by user_id (HIGH-2)
      4. `testFindByIds_emptyArray_returnsEmpty` — findByIds([]) → [] (WARN-1 guard)
      5. `testFindDisplayByIds_returnsKeyedDtoArray` — insert user, findDisplayByIds([id]) → assert count=1, DTO keyed by id (HIGH-2)
      6. `testCreate_returnsHydratedUserWithId` — call create($data) → assert returned User has non-zero id; re-fetch confirms row exists (CRIT-1)
      7. `testUpdate_partialDataUpdatesOnlyAllowedColumns` — insertUser, update(id, ['username' => 'new_name']) → findById → assert username changed
      8. `testDelete_removesRow` — insertUser, delete(id), findById(id) → null
      9. `testSearch_pagination_returnsCorrectPage` — insert 5 users, search with perPage=2, page=2 → assert result count=2, total=5, currentPage=2
      10. `testSearch_noResults_returnsEmptyPaginatedResult` — search with username criteria matching nothing → assert items=[], total=0
      11. `testIncrementTokenGeneration_incrementsField` — insertUser with token_generation=0, incrementTokenGeneration(id) → findById → assert token_generation=1
  - [x] E.2 Create `src/phpbb/user/Repository/DbalUserRepository.php`
    - Namespace: `phpbb\user\Repository`
    - Implements `phpbb\user\Contract\UserRepositoryInterface`
    - Constructor: `__construct(private readonly \Doctrine\DBAL\Connection $connection)`
    - `private const TABLE = 'phpbb_users'`
    - Method mapping:
      - `findById()` → `executeQuery(SQL, [':id' => $id])->fetchAssociative()` → null if false
      - `findByIds(array $ids)` → guard empty → `executeQuery(SQL, [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER])->fetchAllAssociative()` → key result by `user_id` (HIGH-2)
      - `findByUsername()` / `findByEmail()` → `executeQuery(SQL, [param])->fetchAssociative()` → null if false
      - `findDisplayByIds(array $ids)` → guard empty → `executeQuery(SQL, [$ids], [ArrayParameterType::INTEGER])->fetchAllAssociative()` → inline `UserDisplayDTO` construction, keyed by `user_id` (HIGH-2; does NOT use `hydrate()`)
      - `create(array $data)` → 18-col named-param INSERT → `lastInsertId()` → `findById($newId) ?? throw new \RuntimeException('User not found after INSERT')` → return User (CRIT-1)
      - `update(int $id, array $data)` → guard empty `$data` → QueryBuilder: `createQueryBuilder()->update(TABLE)->where('user_id = :id')->setParameter('id', $id)` → iterate `$allowedColumns` whitelist (copy verbatim from PdoUserRepository) → guard empty `$setClauses` → `$qb->executeStatement()`
      - `delete()` → `executeStatement('DELETE FROM phpbb_users WHERE user_id = :id', [':id' => $id])`
      - `search(UserSearchCriteria $criteria)` → QueryBuilder: `createQueryBuilder()->select('*')->from(TABLE)` → conditional `andWhere/setParameter` for `query`, `type`, `groupId` → clone for COUNT query (`->select('COUNT(*)')->setMaxResults(null)->setFirstResult(0)`) → sort validation (copy `$allowedSorts` whitelist) → `->orderBy()->setMaxResults()->setFirstResult()` → `fetchAllAssociative()` → hydrate each row → return `PaginatedResult`
      - `incrementTokenGeneration()` → `executeStatement('UPDATE phpbb_users SET token_generation = token_generation + 1 WHERE user_id = :id', [':id' => $userId])`
    - Copy `hydrate()` verbatim from `PdoUserRepository`
    - Copy `$allowedColumns` whitelist verbatim for `update()`
    - Copy `$allowedSorts` whitelist vertbatim for `search()`
    - Try/catch `\Doctrine\DBAL\Exception` → `RepositoryException` on all public methods
  - [x] E.3 Update `src/phpbb/config/services.yaml` — swap User alias
    - Remove: `phpbb\user\Repository\PdoUserRepository: ~` and its alias entry
    - Add: `phpbb\user\Repository\DbalUserRepository: ~`
    - Change alias: `phpbb\user\Contract\UserRepositoryInterface → DbalUserRepository` (keep `public: true`)
    - Verify: `php bin/phpbbcli.php debug:container 'phpbb\user\Contract\UserRepositoryInterface'`
  - [x] E.4 Run PHPUnit — 8+ User tests must pass
    - Run ONLY `DbalUserRepositoryTest`
    - All 8+ must be green (including search pagination test and findByIds IN-list test)
  - [x] E.5 Delete PDO User implementation and its mock test
    - Delete `src/phpbb/user/Repository/PdoUserRepository.php`
    - Delete `tests/phpbb/user/Repository/PdoUserRepositoryTest.php`
    - Run full PHPUnit suite to confirm no regressions

**Acceptance Criteria:**
- All 8+ `DbalUserRepositoryTest` tests pass on SQLite
- `findByIds([])` returns `[]` immediately (WARN-1)
- `findByIds` / `findDisplayByIds` return arrays keyed by `user_id` (HIGH-2)
- `create()` re-fetches via `findById` after `lastInsertId` (CRIT-1)
- `search()` pagination test passes: correct page, total count
- `PdoUserRepository.php` and `PdoUserRepositoryTest.php` deleted
- `services.yaml` alias points to `DbalUserRepository`
- Full PHPUnit suite exits 0

---

### Task Group F — Cleanup & Full Verification
**Dependencies:** A, B, C, D, E  
**Estimated Steps:** 8

- [x] F.0 Complete cleanup and end-to-end verification
  - [x] F.1 Review tests from all previous groups (31–39 existing tests)
    - Review `DbalRefreshTokenRepositoryTest` (8 tests), `DbalBanRepositoryTest` (8+ tests), `DbalGroupRepositoryTest` (7+ tests), `DbalUserRepositoryTest` (8+ tests)
    - Identify gaps for THIS feature's critical paths:
      - `RepositoryException` propagation (catch DBAL exception → rethrow)
      - `DbalConnectionFactory::create()` covers port fallback to 3306
      - `findAll()` ordering/sorting correctness
  - [x] F.2 Write up to 10 additional strategic tests (if gaps found in F.1)
    - Priority: (a) RepositoryException wrapping (b) `DbalConnectionFactory` port default (c) `findByUsername`/`findByEmail` exact match
    - Do NOT add more than 10 tests
    - Run any new tests to confirm green
  - [x] F.3 Delete `src/phpbb/db/PdoFactory.php`
    - Delete the file
    - Verify: `grep -r 'PdoFactory' src/phpbb/ --include='*.php'` returns empty
  - [x] F.4 Verify zero PDO references in `src/phpbb/`
    - Run: `grep -r 'PDO' src/phpbb/ --include='*.php'`
    - Must return empty (no `\PDO`, `PDO::`, `PDOStatement`)
    - Run: `grep -r 'PdoFactory\|PdoRefreshToken\|PdoBan\|PdoGroup\|PdoUser' . --include='*.php'`
    - Must return empty everywhere in the project
  - [x] F.5 Verify `services.yaml` final state
    - Assert no `PDO:` entry exists
    - Assert `phpbb\db\DbalConnectionFactory: ~` exists
    - Assert `Doctrine\DBAL\Connection:` service block present with correct factory
    - Assert all 4 interface aliases point to `Dbal*` classes
    - Assert `bundles.php` does NOT contain `DoctrineBundle` (AC-12)
  - [x] F.6 Run full PHPUnit suite — all 145+ tests must pass
    - Run: `vendor/bin/phpunit`
    - Exit code must be 0
    - Zero failures, zero errors
    - Verify the `DbalRefreshTokenRepositoryTest` class shows ≥8 assertions in PHPUnit output (AC-6)
    - Verify `DbalBanRepositoryTest` shows ≥8 assertions (AC-7)
    - Verify `DbalGroupRepositoryTest` addMember idempotency assertion passes (AC-8)
    - Verify `DbalUserRepositoryTest` search pagination + findByIds assertions pass (AC-9)
  - [ ] F.7 Run Playwright E2E suite — all 21 tests must pass (requires Docker)
    - Prerequisite: Docker containers running (`docker compose up -d`)
    - Run: `cd tests/e2e && npx playwright test`
    - Exit code must be 0, all 21 green
    - These tests run against live Docker (MariaDB 10.11 via `DbalConnectionFactory` + `MariaDBPlatform`); verify ON DUPLICATE KEY UPDATE path is exercised if group membership E2E scenario exists
  - [ ] F.8 Final verification checklist
    - `composer.json` contains `"doctrine/dbal": "^4.0"` in `require` (AC-11)
    - `bin/phpbbcli.php debug:container 'Doctrine\DBAL\Connection'` exits 0 (AC-5)
    - AC-3: `grep -r 'PDO' src/phpbb/ --include='*.php'` → empty
    - AC-4: `grep -r 'PdoFactory\|PdoRefreshToken\|PdoBan\|PdoGroup\|PdoUser'` → empty
    - AC-10: `services.yaml` has no `PDO:` entry
    - AC-12: `bundles.php` has no DoctrineBundle

**Acceptance Criteria:**
- All feature tests pass (~31–41 total: 31–39 from groups B–E + up to 10 additional)
- `PdoFactory.php` deleted; zero PDO references remain in `src/phpbb/`
- Zero references to `Pdo*` class names anywhere in the project
- `vendor/bin/phpunit` exits 0 with 145+ tests passing
- Playwright E2E exits 0 with 21 tests passing against live Docker
- All 12 acceptance criteria (AC-1 through AC-12) from spec §8 satisfied

---

## Execution Order

1. **Group A** — Foundation & Infrastructure (3 steps + 3 infra tests) | No dependencies
2. **Group B** — RefreshToken Repository (5 steps + 8 tests) | Depends on A
3. **Group C** — Ban Repository (5 steps + 8+ tests) | Depends on A, B
4. **Group D** — Group Repository (5 steps + 7+ tests) | Depends on A, B, C
5. **Group E** — User Repository (5 steps + 8+ tests) | Depends on A, B, C, D
6. **Group F** — Cleanup & Full Verification (8 steps + up to 10 tests) | Depends on all

> B → C → D order is not strictly required by technical dependencies (all depend only on A), but sequential ordering reduces cognitive overhead and allows early validation of the DBAL API patterns in the simplest repository before tackling platform-specific logic.

---

## Files Created / Modified / Deleted

### Created (new files)

| File | Group |
|------|-------|
| `src/phpbb/db/Exception/RepositoryException.php` | A |
| `src/phpbb/db/DbalConnectionFactory.php` | A |
| `tests/phpbb/Integration/IntegrationTestCase.php` | A |
| `src/phpbb/auth/Repository/DbalRefreshTokenRepository.php` | B |
| `tests/phpbb/auth/Repository/DbalRefreshTokenRepositoryTest.php` | B |
| `src/phpbb/user/Repository/DbalBanRepository.php` | C |
| `tests/phpbb/user/Repository/DbalBanRepositoryTest.php` | C |
| `src/phpbb/user/Repository/DbalGroupRepository.php` | D |
| `tests/phpbb/user/Repository/DbalGroupRepositoryTest.php` | D |
| `src/phpbb/user/Repository/DbalUserRepository.php` | E |
| `tests/phpbb/user/Repository/DbalUserRepositoryTest.php` | E |

### Modified (existing files)

| File | Change | Group |
|------|--------|-------|
| `composer.json` | Add `doctrine/dbal: ^4.0` to `require` | A |
| `src/phpbb/config/services.yaml` | Replace PDO block; swap 4 repository aliases | A + B + C + D + E |

### Deleted (after green tests)

| File | Group |
|------|-------|
| `src/phpbb/auth/Repository/PdoRefreshTokenRepository.php` | B |
| `tests/phpbb/auth/Repository/PdoRefreshTokenRepositoryTest.php` | B |
| `src/phpbb/user/Repository/PdoBanRepository.php` | C |
| `src/phpbb/user/Repository/PdoGroupRepository.php` | D |
| `src/phpbb/user/Repository/PdoUserRepository.php` | E |
| `tests/phpbb/user/Repository/PdoUserRepositoryTest.php` | E |
| `src/phpbb/db/PdoFactory.php` | F |

---

## Standards Compliance

Follow standards from `.maister/docs/standards/`:

- `global/` — Always applicable: tabs for indentation, no closing PHP tag, `declare(strict_types=1)`, GPL-2.0 file header
- `backend/STANDARDS.md` — No raw PDO; parameterized DBAL API only; `phpbb\` namespace; DI via Symfony container; no `global` in OOP code
- `backend/REST_API.md` — Not directly applicable (no new endpoints); verify existing endpoints still work via E2E
- `testing/STANDARDS.md` — PHPUnit; 2-8 focused tests per group; SQLite in-memory for isolation; no kernel bootstrap in integration tests

---

## Notes

- **Test-Driven:** Each group starts with 2-8 tests written before implementation
- **Run Incrementally:** After each group, run ONLY the new tests for that group; full suite only after deletion step
- **Mark Progress:** Check off steps as completed; keep plan as source of truth for resume
- **Reuse First:** Copy `hydrate()`, SQL strings, column whitelists, and sort whitelists verbatim from PDO sources
- **Pilot Pattern:** Group B (RefreshToken) is the simplest — establishes the DBAL API pattern for all subsequent groups; validate fully before proceeding
- **Spec Amendments:** CRIT-1 (`create()` re-fetch), HIGH-1 (`findAll()` conditional SQL), HIGH-2 (`findByIds` + `findDisplayByIds` keyed arrays), WARN-1 (empty `$ids` guard) — all must be implemented; each has an explicit test
- **Commit:** After Group F completes, commit with: `feat(db): M4 — Adopt Doctrine DBAL 4; replace PDO layer with Dbal*Repository + SQLite integration tests`
