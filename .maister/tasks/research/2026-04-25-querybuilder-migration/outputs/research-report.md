# Research Report — Doctrine DBAL QueryBuilder Migration

**Research type**: Technical  
**Date**: 2026-04-25  
**Confidence level**: HIGH — all findings derived from direct code inspection  
**Researcher**: GitHub Copilot (research-synthesizer mode)

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Scope](#2-scope)
3. [What CAN Be Migrated to QueryBuilder](#3-what-can-be-migrated-to-querybuilder)
4. [What CANNOT Be Migrated — Dialect-Specific Patterns](#4-what-cannot-be-migrated)
5. [Recommended Migration Strategy](#5-recommended-migration-strategy)
6. [Security Fixes in Scope](#6-security-fixes-in-scope)
7. [Test Changes Required](#7-test-changes-required)
8. [Proposed Implementation Order](#8-proposed-implementation-order)
9. [Appendices](#9-appendices)

---

## 1. Executive Summary

Every `Dbal*Repository` class and every non-repository service class under `src/phpbb/` was audited for raw SQL usage. The verdict:

- **~85 % of all query sites** (approx. 70 out of ~82 individual SQL strings) can be replaced directly with Doctrine DBAL 4 QueryBuilder using standard API calls.
- **3 exception categories** exist — `ON DUPLICATE KEY UPDATE`, `HEX()`/`UNHEX()` binary functions, and `GREATEST()` — that either cannot be expressed in QB or require dialect-specific raw strings regardless. These cover ~15 % of query sites.
- **2 security defects** (SQL column-name injection risks) must be patched before the migration, regardless of QueryBuilder adoption.
- **No schema changes** are required to proceed with migration except for `DbalStoredFileRepository`, where replacing binary UUID columns with `CHAR(36)` strings would eliminate the HEX/UNHEX dependency entirely.
- The test suite is structurally healthy; 10 of 12 repository tests already run against real SQLite. Only two mock-based test classes need rewriting.

**Answer to the research question**: Migrate file-by-file, starting from the simplest repositories. Apply two security fixes first. Defer `DbalStoredFileRepository`. Handle `AuthorizationService` last because of its multi-JOIN complexity. For the three MySQL-specific patterns, keep raw `executeStatement` calls as documented exceptions — do not attempt to force them through QueryBuilder.

---

## 2. Scope

### 2.1 Files Affected

| Category | Count | Files |
|---|---|---|
| `Dbal*Repository` classes with raw SQL to migrate | 12 | DbalUserRepository, DbalGroupRepository, DbalBanRepository, DbalRefreshTokenRepository, DbalForumRepository, DbalTopicRepository, DbalPostRepository, DbalConversationRepository, DbalMessageRepository, DbalParticipantRepository, DbalStoredFileRepository, DbalStorageQuotaRepository |
| Service classes with raw SQL to migrate | 4 | TrackingService, SubscriptionService, QuotaService, AuthorizationService |
| Already fully on QueryBuilder (skip) | 2 methods | `DbalUserRepository::update`, `DbalUserRepository::search` |
| Connection-for-transactions only (no SQL) | 7 | OrphanService, StorageService, ParticipantService, MessageService, ConversationService, MessagingService, ThreadsService |

**Total files requiring migration work**: 16

### 2.2 Query Site Count (approximate)

| Classification | Count |
|---|---|
| Portable ANSI SQL — direct QB replacement | ~70 |
| MySQL-only — keep as raw SQL or fix schema | ~10 |
| Already QB | 2 |
| **Total** | **~82** |

---

## 3. What CAN Be Migrated to QueryBuilder

All patterns below use DBAL 4 API that generates portable SQL for both MySQL and SQLite.

### 3.1 Simple SELECT with WHERE

```php
// Before
$this->connection->executeQuery(
    'SELECT * FROM phpbb_users WHERE user_id = :id LIMIT 1',
    ['id' => $id]
)->fetchAssociative();

// After
$this->connection->createQueryBuilder()
    ->select('*')
    ->from('phpbb_users')
    ->where($qb->expr()->eq('user_id', ':id'))
    ->setMaxResults(1)
    ->setParameter('id', $id)
    ->fetchAssociative();
```

**Applies to**: `findById`, `findByUsername`, `findByEmail`, `findBanById`, `findByHash`, etc. — present in almost every repository.

---

### 3.2 SELECT with `IN` (array of IDs)

```php
// Before
$this->connection->executeQuery(
    'SELECT * FROM phpbb_users WHERE user_id IN (?)',
    [$ids],
    [ArrayParameterType::INTEGER]
);

// After — identical behaviour, tighter API
$qb = $this->connection->createQueryBuilder();
$qb->select('*')
   ->from('phpbb_users')
   ->where($qb->expr()->in('user_id', ':ids'))
   ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
   ->fetchAllAssociative();
```

**Applies to**: `DbalUserRepository::findByIds`, `DbalUserRepository::findDisplayByIds`, `AuthorizationService` (4 queries — switch from positional `?` to named parameter with `ArrayParameterType::INTEGER`).

---

### 3.3 Pagination — COUNT + paginated SELECT

```php
// Before
$count = (int) $this->connection->executeQuery(
    'SELECT COUNT(*) FROM phpbb_topics WHERE forum_id = :forumId AND topic_visibility = 1',
    ['forumId' => $forumId]
)->fetchOne();

$rows = $this->connection->executeQuery(
    'SELECT … FROM phpbb_topics WHERE forum_id = :forumId AND topic_visibility = 1
     ORDER BY topic_last_post_time DESC LIMIT :limit OFFSET :offset',
    ['forumId' => $forumId, 'limit' => $limit, 'offset' => $offset],
    [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER]
)->fetchAllAssociative();

// After — both queries share the base builder
$base = $this->connection->createQueryBuilder()
    ->from('phpbb_topics')
    ->where($qb->expr()->eq('forum_id', ':forumId'))
    ->andWhere($qb->expr()->eq('topic_visibility', '1'))
    ->setParameter('forumId', $forumId);

$count = (int) (clone $base)->select('COUNT(*)')->fetchOne();

$rows = (clone $base)
    ->select('topic_id, forum_id, …')
    ->orderBy('topic_last_post_time', 'DESC')
    ->setMaxResults($limit)
    ->setFirstResult($offset)
    ->fetchAllAssociative();
```

**Applies to**: `DbalTopicRepository::findByForum`, `DbalPostRepository::findByTopic`, `DbalConversationRepository::listByUser`, `DbalMessageRepository::listByConversation`, `DbalMessageRepository::search`.

> **Note**: `setMaxResults` / `setFirstResult` are translated by DBAL to the correct dialect — no manual `LIMIT`/`OFFSET` strings needed.

---

### 3.4 INSERT (fixed columns)

```php
// Before
$this->connection->executeStatement(
    'INSERT INTO phpbb_banlist (ban_userid, …) VALUES (:userId, …)',
    ['userId' => $userId, …]
);

// After
$this->connection->createQueryBuilder()
    ->insert('phpbb_banlist')
    ->values(['ban_userid' => ':userId', …])
    ->setParameter('userId', $userId)
    ->executeStatement();
```

**Applies to**: all repository `create`/`save`/`insert` methods that do not use `ON DUPLICATE KEY UPDATE` or `UNHEX()`.

---

### 3.5 UPDATE with fixed columns

```php
// Before
$this->connection->executeStatement(
    'UPDATE phpbb_auth_refresh_tokens SET revoked_at = :now WHERE token_hash = :hash AND revoked_at IS NULL',
    ['now' => $now, 'hash' => $hash]
);

// After
$qb = $this->connection->createQueryBuilder();
$qb->update('phpbb_auth_refresh_tokens')
   ->set('revoked_at', ':now')
   ->where($qb->expr()->eq('token_hash', ':hash'))
   ->andWhere($qb->expr()->isNull('revoked_at'))
   ->setParameter('now', $now)
   ->setParameter('hash', $hash)
   ->executeStatement();
```

**Applies to**: all `revokeByHash`, `revokeFamily`, `revokeAllForUser`, `updateLastPost`, `updateFirstLastPost`, `reconcile`, `incrementUsage`, `updateTreePosition`, `shiftLeftIds`, `shiftRightIds`, `updateParentId`, `clearParentsCache`, etc.

---

### 3.6 UPDATE with dynamic whitelisted columns

```php
// After (whitelist-guarded dynamic SET)
$allowed = ['title', 'last_message_id', 'last_message_at', 'message_count', 'participant_count', 'participant_hash'];
$qb = $this->connection->createQueryBuilder()->update('phpbb_messaging_conversations');
foreach (array_intersect_key($fields, array_flip($allowed)) as $col => $value) {
    $qb->set($col, ':' . $col)->setParameter($col, $value);
}
$qb->where($qb->expr()->eq('conversation_id', ':id'))
   ->setParameter('id', $id)
   ->executeStatement();
```

**Applies to**: `DbalConversationRepository::update`, `DbalForumRepository::update`. (`DbalMessageRepository::update` and `DbalParticipantRepository::update` require security fix first — see §6.)

---

### 3.7 DELETE

```php
// After
$qb = $this->connection->createQueryBuilder();
$qb->delete('phpbb_banlist')
   ->where($qb->expr()->eq('ban_id', ':id'))
   ->setParameter('id', $id)
   ->executeStatement();
```

**Applies to**: every repository `delete` method.

---

### 3.8 SELECT with aggregate — `COALESCE(SUM(...))`

```php
// After — raw expression string in select()
$actual = (int) $this->connection->createQueryBuilder()
    ->select('COALESCE(SUM(filesize), 0)')
    ->from('phpbb_stored_files')
    ->where($qb->expr()->eq('uploader_id', ':uid'))
    ->andWhere($qb->expr()->eq('forum_id', ':fid'))
    ->setParameter('uid', $userId)
    ->setParameter('fid', $forumId)
    ->fetchOne();
```

**Applies to**: `QuotaService::reconcileAll`. `COALESCE` and `SUM` are standard SQL; pass as string literal to `select()`.

---

### 3.9 Multi-JOIN SELECT (`AuthorizationService`)

```php
// After — private helper for repeated join structure
private function buildAclQuery(string $authOption, array $forumIds): QueryBuilder
{
    $qb = $this->connection->createQueryBuilder();
    return $qb
        ->from('phpbb_user_group', 'ug')
        ->innerJoin('ug', 'phpbb_acl_groups', 'ag', 'ag.group_id = ug.group_id')
        ->innerJoin('ag', 'phpbb_acl_options', 'ao', 'ao.auth_option_id = ag.auth_option_id')
        ->where($qb->expr()->eq('ug.user_id', ':userId'))
        ->andWhere($qb->expr()->eq('ug.user_pending', '0'))
        ->andWhere($qb->expr()->eq('ao.auth_option', ':opt'))
        ->andWhere($qb->expr()->in('ag.forum_id', ':fids'))
        ->orderBy('ag.auth_setting', 'DESC')
        ->setParameter('opt', $authOption)
        ->setParameter('fids', $forumIds, ArrayParameterType::INTEGER);
}
```

**Applies to**: `AuthorizationService::resolveGroupPermission` (queries 1+2), `AuthorizationService::resolveUserPermission` (queries 3+4). Each query differs only in which tables are joined for role vs. direct lookups.

---

## 4. What CANNOT Be Migrated

These patterns have no QB equivalent or the QB expression is identical to raw SQL (MySQL-locked regardless).

### 4.1 `INSERT … ON DUPLICATE KEY UPDATE` — MySQL-only

**File**: `src/phpbb/user/Repository/DbalGroupRepository.php` (MySQL branch of `addMember`)

DBAL 4 `QueryBuilder` has no `onDuplicateKeyUpdate()` or `onConflict()` method. The existing code already has the correct pattern: a platform branch that uses raw `executeStatement` on MySQL and a `transactional(DELETE + INSERT)` fallback for all other drivers.

**Recommended action**: **Keep as-is**. Document the raw SQL call with an inline comment:

```php
// QB cannot express ON DUPLICATE KEY UPDATE (MySQL-only). Keep raw SQL.
$this->connection->executeStatement(
    'INSERT INTO phpbb_user_group (group_id, user_id, group_leader, user_pending)
     VALUES (:groupId, :userId, :isLeader, 0)
     ON DUPLICATE KEY UPDATE group_leader = :isLeader, user_pending = 0',
    ['groupId' => $groupId, 'userId' => $userId, 'isLeader' => (int) $isLeader]
);
```

---

### 4.2 `HEX()` / `UNHEX()` — MySQL binary UUID columns

**File**: `src/phpbb/storage/Repository/DbalStoredFileRepository.php` — all 6 methods

The `id` and `parent_id` columns store binary UUIDs (`VARBINARY(16)` / `BINARY(16)`). All SELECT, INSERT, UPDATE, and DELETE queries use `HEX(id)` in projections and `UNHEX(:id)` in WHERE clauses.

DBAL 4 has no binary column abstraction. These functions can be inlined as raw strings in QB expression positions, but the SQL remains MySQL-only regardless.

**Two options**:

| Option | Effort | Benefit |
|---|---|---|
| A — inline as QB raw strings (`->where('id = UNHEX(:id)')`) | Low | Minor; keeps QB style, SQL still MySQL-locked |
| B — schema migration: change columns to `CHAR(36)` UUID strings | High | Eliminates HEX/UNHEX entirely; makes queries fully portable |

**Recommended action**: **Defer this file**. Do not migrate `DbalStoredFileRepository` as part of the QueryBuilder sprint. Plan a separate schema migration task (`phpbb_stored_files.id → CHAR(36)`) that will eliminate the problem at the root.

---

### 4.3 `GREATEST(0, col - :n)` — MySQL scalar function

**File**: `src/phpbb/storage/Repository/DbalStorageQuotaRepository.php::decrementUsage`

`GREATEST()` as a two-argument scalar function is MySQL/MariaDB only. SQLite supports `MAX()` only as an aggregate function; it does not accept `MAX(a, b)` as a scalar comparator.

**Recommended action**: Replace with a portable `CASE WHEN` expression before the QB migration of this file:

```php
// Portable replacement for GREATEST(0, used_bytes - :bytes)
$qb->update('phpbb_storage_quotas')
   ->set('used_bytes', 'CASE WHEN used_bytes >= :bytes THEN used_bytes - :bytes ELSE 0 END')
   ->set('updated_at', ':now')
   ->where($qb->expr()->eq('user_id', ':uid'))
   ->andWhere($qb->expr()->eq('forum_id', ':fid'))
   ->setParameter('bytes', $bytes)
   ->setParameter('now', $now)
   ->setParameter('uid', $userId)
   ->setParameter('fid', $forumId)
   ->executeStatement();
```

This is QB-expressible (raw string in `set()`) and fully portable.

---

## 5. Recommended Migration Strategy

### Principles

1. **One file per PR**. Each repository migration is an isolated, testable change.
2. **Security fixes first**. Patch the unguarded `update()` methods before any QB work.
3. **Run `composer test && composer test:e2e && composer cs:fix` after every file**.
4. **Don't migrate what's already done**. `DbalUserRepository::update` and `::search` are already on QB.
5. **Defer `DbalStoredFileRepository`** until the column type migration is planned.

### Phases

**Phase 0 — Security Patches (prerequisite, no QB yet)**
- Add field-name whitelist to `DbalMessageRepository::update`
- Add field-name whitelist to `DbalParticipantRepository::update`

**Phase 1 — Portability Fix (prerequisite for test conversion)**
- Replace `GREATEST()` with `CASE WHEN` in `DbalStorageQuotaRepository::decrementUsage`

**Phase 2 — Simple Repository Migration (5 files, low risk)**  
Order: `DbalBanRepository` → `DbalRefreshTokenRepository` → `DbalGroupRepository` → `DbalForumRepository` → `DbalUserRepository` (remaining methods)

**Phase 3 — Pagination Repositories (4 files, medium effort)**  
`DbalTopicRepository` → `DbalPostRepository` → `DbalMessageRepository` → `DbalConversationRepository`

**Phase 4 — Participant + Quota Repositories (2 files)**  
`DbalParticipantRepository` → `DbalStorageQuotaRepository`

**Phase 5 — Service Classes (4 files)**  
`SubscriptionService` → `TrackingService` → `QuotaService` → `AuthorizationService`

**Phase 6 — Test Conversion**  
Convert `DbalStoredFileRepositoryTest` and `DbalStorageQuotaRepositoryTest` from mock-based to SQLite integration tests.

**Phase 7 — (Separate sprint) Schema Migration**  
`phpbb_stored_files.id` → `CHAR(36)`, then migrate `DbalStoredFileRepository`.

---

## 6. Security Fixes in Scope

### 6.1 `DbalMessageRepository::update` — field-name injection risk

**File**: `src/phpbb/messaging/Repository/DbalMessageRepository.php`

**Current code** (simplified):
```php
public function update(int $messageId, array $fields): void
{
    $set = [];
    $params = ['messageId' => $messageId];
    foreach ($fields as $field => $value) {   // ← $field is not validated
        $set[] = $field . ' = :' . $field;
        $params[$field] = $value;
    }
    $sql = 'UPDATE phpbb_messaging_messages SET ' . implode(', ', $set) . ' WHERE message_id = :messageId';
    $this->connection->executeStatement($sql, $params);
}
```

**Risk**: If `$fields` keys ever contain attacker-controlled strings (e.g., a crafted API payload that bypasses controller validation), the field names are interpolated directly into SQL. Values are parameterised correctly, but column names are not.

**Fix**: Add an explicit whitelist before the loop:

```php
private const UPDATABLE_FIELDS = ['message_text', 'message_subject', 'edited_at', 'edit_count', 'metadata'];

public function update(int $messageId, array $fields): void
{
    $fields = array_intersect_key($fields, array_flip(self::UPDATABLE_FIELDS));
    if (empty($fields)) {
        return;
    }
    // then build QB SET loop ...
}
```

---

### 6.2 `DbalParticipantRepository::update` — field-name injection risk

**File**: `src/phpbb/messaging/Repository/DbalParticipantRepository.php`

**Identical pattern** to `DbalMessageRepository::update`. Field names from the `$fields` array are interpolated into SQL without validation.

**Fix**: Same approach — define `UPDATABLE_FIELDS` const with the explicit set of columns allowed to be updated:

```php
private const UPDATABLE_FIELDS = ['role', 'state', 'left_at', 'last_read_message_id', 'last_read_at', 'is_muted', 'is_blocked'];
```

---

## 7. Test Changes Required

### 7.1 No changes needed — 10 test classes

All integration test classes using SQLite (`IntegrationTestCase` subclasses + the three messaging test classes using direct `DriverManager::getConnection`) will continue to pass after the QueryBuilder migration. The QB-generated SQL for standard operations (`SELECT`, `INSERT`, `UPDATE`, `DELETE`) is functionally identical to the current raw SQL. No DDL changes are needed — the test schema DDL uses SQLite-portable syntax throughout.

### 7.2 `DbalStoredFileRepositoryTest` — rewrite as SQLite integration test

**Current state**: `$this->createMock(Connection::class)` — does not execute real SQL.

**Action**: Rewrite as `extends IntegrationTestCase`. Add `setUpSchema()` implementing the `phpbb_storage_files` DDL. Replace all mock-based assertions with real round-trip assertions (save → find → assert value, save → delete → findById returns null, etc.).

Drop the keyword-level `stringContains('DELETE')` and `stringContains('UPDATE')` assertions — they test implementation details irrelevant to repository contracts.

Note: `DbalStoredFileRepository` is deferred from QB migration (§4.2), but the test class can still be converted to SQLite integration style independently. The existing raw SQL will run against SQLite correctly as long as `HEX()`/`UNHEX()` usage is kept in the MySQL branch and the SQLite test DDL uses `TEXT` for UUID columns.

### 7.3 `DbalStorageQuotaRepositoryTest` — rewrite as SQLite integration test (blocked until Phase 1)

**Blocker**: `GREATEST()` in `decrementUsage` must be replaced first (Phase 1). Once replaced with `CASE WHEN`, the repository runs correctly against SQLite.

**Action (after Phase 1)**: Rewrite as `extends IntegrationTestCase`. Add `phpbb_storage_quotas` DDL to `setUpSchema()`. Drop the brittle `stringContains('SET used_bytes = :actual_bytes')` assertion — this asserts a specific parameter name in a raw SQL string, which will not survive QB migration:

```php
// BEFORE (brittle — must remove)
->with($this->stringContains('SET used_bytes = :actual_bytes'));

// AFTER (correct round-trip assertion)
$this->repo->reconcile($userId, $forumId, 750);
$quota = $this->repo->findByUserAndForum($userId, $forumId);
$this->assertSame(750, $quota->usedBytes);
```

### 7.4 `DbalGroupRepository` — MySQL branch gap acknowledged

The `addMember` MySQL branch (`ON DUPLICATE KEY UPDATE`) has no unit test coverage — SQLite integration tests only exercise the fallback branch. This gap is pre-existing. The QB migration does not change this; only E2E tests (running against MariaDB) cover the MySQL path. This is acceptable as-is.

---

## 8. Proposed Implementation Order

| # | File | Complexity | Blocker | Recommended action |
|---|---|---|---|---|
| 0a | `DbalMessageRepository` (update method only) | Low | None | **Security fix first**: add `UPDATABLE_FIELDS` whitelist |
| 0b | `DbalParticipantRepository` (update method only) | Low | None | **Security fix first**: add `UPDATABLE_FIELDS` whitelist |
| 1 | `DbalStorageQuotaRepository::decrementUsage` | Low | None | **Portability fix**: replace `GREATEST()` with `CASE WHEN` |
| 2 | `DbalBanRepository` | Low | None | Migrate all 5 methods to QB |
| 3 | `DbalRefreshTokenRepository` | Low | None | Migrate all 6 methods to QB |
| 4 | `DbalGroupRepository` | Low | None | Migrate portable methods; keep MySQL branch as raw SQL |
| 5 | `DbalUserRepository` | Low | None | Migrate remaining raw SQL methods (update/search already QB) |
| 6 | `DbalForumRepository` | Medium | None | Migrate 9 methods; `update` → QB `set()` loop with whitelist |
| 7 | `DbalTopicRepository` | Medium | None | Migrate with pagination clone pattern |
| 8 | `DbalPostRepository` | Medium | None | Migrate with pagination clone pattern |
| 9 | `DbalConversationRepository` | Medium | None | Migrate; `listByUser` uses optional `andWhere` |
| 10 | `DbalMessageRepository` (all other methods) | Medium | Step 0a done | Migrate remaining methods post-security-fix |
| 11 | `DbalParticipantRepository` (all other methods) | Medium | Step 0b done | Migrate remaining methods post-security-fix |
| 12 | `DbalStorageQuotaRepository` (all other methods) | Medium | Step 1 done | Migrate remaining methods; convert test to SQLite |
| 13 | `SubscriptionService` | Low | None | Migrate 3 simple queries |
| 14 | `TrackingService` | Medium | None | Migrate 5 queries (upsert pattern stays as SELECT + branch) |
| 15 | `QuotaService` | Low | None | Migrate 1 query (`COALESCE(SUM(...))` as raw select string) |
| 16 | `AuthorizationService` | High | None | Migrate 4 multi-JOIN queries; extract private QB builder helper |
| 17 | `DbalStoredFileRepositoryTest` | Medium | None (independent) | Convert mock test to SQLite integration test |
| 18 | `DbalStorageQuotaRepositoryTest` | Medium | Step 1+12 done | Convert mock test to SQLite integration test |
| 19 | `DbalStoredFileRepository` | High | Schema migration planned | **Defer**: requires separate `phpbb_stored_files.id → CHAR(36)` migration |

---

## 9. Appendices

### A. Complete Source List

| Source file | Queries/sites analysed |
|---|---|
| `analysis/findings/repositories-raw-sql-inventory.md` | 12 repository files, ~65 query sites |
| `analysis/findings/services-raw-sql-inventory.md` | 4 service files, ~10 query sites |
| `analysis/findings/dbal-capabilities-assessment.md` | DBAL 4.4.3 QB API, 13 pattern categories |
| `analysis/findings/tests-sql-patterns.md` | 12 test classes, DDL audit, mock/integration breakdown |

### B. DBAL 4 QB Methods Reference

| Operation | Method chain |
|---|---|
| Simple SELECT | `->select('…')->from('table')->where(…)->setParameter(…)` |
| SELECT LIMIT 1 | `->…->setMaxResults(1)` |
| SELECT IN array | `->where($qb->expr()->in('col', ':ids'))->setParameter('ids', $arr, ArrayParameterType::INTEGER)` |
| Pagination | `->setMaxResults($n)->setFirstResult($offset)` |
| JOIN | `->innerJoin('alias', 'table', 'a', 'a.col = alias.col')` |
| INSERT | `->insert('table')->values(['col' => ':p'])->setParameter('p', $v)` |
| UPDATE fixed | `->update('table')->set('col', ':p')->where(…)->setParameter('p', $v)` |
| UPDATE dynamic | `foreach ($fields as $c => $v) { $qb->set($c, ':'.$c)->setParameter($c, $v); }` |
| DELETE | `->delete('table')->where(…)` |
| COUNT aggregate | `->select('COUNT(*)')->from(…)->where(…)->fetchOne()` |
| Raw expression | Pass plain string to `select()`, `set()`, `where()`, `having()` |
| Execute | `->executeStatement()` (writes), chained fetch methods (reads) |

### C. Patterns That Must Stay as Raw SQL

| Pattern | File | Reason |
|---|---|---|
| `ON DUPLICATE KEY UPDATE` | `DbalGroupRepository::addMember` (MySQL branch) | No QB equivalent; keep platform branch |
| `HEX(id)` / `UNHEX(:id)` | `DbalStoredFileRepository` (all 6 methods) | Root cause: binary UUID columns; defer until schema migration |
| `GREATEST(0, x - :n)` | `DbalStorageQuotaRepository::decrementUsage` | Replace with `CASE WHEN` (portable) before QB migration |

### D. Confidence Assessment

| Finding | Confidence | Basis |
|---|---|---|
| ~85 % of queries are QB-migratable | High | Direct code inspection of 12 repo files |
| `ON DUPLICATE KEY UPDATE` has no QB equivalent | High | DBAL 4 source code review |
| `HEX()`/`UNHEX()` are MySQL-only | High | SQL standard; SQLite docs |
| `GREATEST()` non-portable; `CASE WHEN` alternative portable | High | SQL standard; SQLite docs |
| Security risk in `DbalMessageRepository` / `DbalParticipantRepository` | High | Direct code inspection |
| Test DDL is SQLite-clean; 10/12 tests need no changes | High | Direct test file inspection |
| `CASE WHEN` resolves `GREATEST()` for SQLite tests | High | SQLite SQL documentation |
