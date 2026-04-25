# Test Suite SQL Patterns — QueryBuilder Migration Research

**Research question**: How should we replace all hand-written SQL queries with Doctrine DBAL QueryBuilder across the `phpbb\` namespace?

**Scope**: Test files under `tests/phpbb/` — repository tests only.

---

## 1. Mock vs Integration Test Breakdown

### 1.1 Infrastructure

`tests/phpbb/Integration/IntegrationTestCase.php` is the shared SQLite harness:

```php
$this->connection = DriverManager::getConnection([
    'driver' => 'pdo_sqlite',
    'memory' => true,
]);
$this->setUpSchema();   // implemented by each test class
```

Subclasses implement `setUpSchema(): void` to run `CREATE TABLE` DDL and instantiate the SUT.

Three messaging repository tests bypass `IntegrationTestCase` and call `DriverManager::getConnection` directly with the same SQLite-in-memory config, then call a private `setupDatabase()` method. Functionally identical to `IntegrationTestCase`.

---

### 1.2 Per-Repository Classification

| Repository | Test class | Strategy | SQLite or Mock |
|---|---|---|---|
| `DbalForumRepository` | `DbalForumRepositoryTest` | extends `IntegrationTestCase` | **SQLite in-memory** |
| `DbalUserRepository` | `DbalUserRepositoryTest` | extends `IntegrationTestCase` | **SQLite in-memory** |
| `DbalBanRepository` | `DbalBanRepositoryTest` | extends `IntegrationTestCase` | **SQLite in-memory** |
| `DbalGroupRepository` | `DbalGroupRepositoryTest` | extends `IntegrationTestCase` | **SQLite in-memory** |
| `DbalRefreshTokenRepository` | `DbalRefreshTokenRepositoryTest` | extends `IntegrationTestCase` | **SQLite in-memory** |
| `DbalTopicRepository` | `DbalTopicRepositoryTest` | extends `IntegrationTestCase` | **SQLite in-memory** |
| `DbalPostRepository` | `DbalPostRepositoryTest` | extends `IntegrationTestCase` | **SQLite in-memory** |
| `DbalConversationRepository` | `DbalConversationRepositoryTest` | `DriverManager::getConnection` (direct) | **SQLite in-memory** |
| `DbalMessageRepository` | `DbalMessageRepositoryTest` | `DriverManager::getConnection` (direct) | **SQLite in-memory** |
| `DbalParticipantRepository` | `DbalParticipantRepositoryTest` | `DriverManager::getConnection` (direct) | **SQLite in-memory** |
| `DbalStoredFileRepository` | `DbalStoredFileRepositoryTest` | `$this->createMock(Connection::class)` | **Mock** |
| `DbalStorageQuotaRepository` | `DbalStorageQuotaRepositoryTest` | `$this->createMock(Connection::class)` | **Mock** |

**Summary**: 10 of 12 repository tests already use real SQLite; only the two storage repos remain mock-based.

---

## 2. DDL Used in `setUpSchema()` Methods — SQLite Compatibility Audit

All DDL found in the test suite uses SQLite-native syntax. No MySQL-specific DDL constructs were detected.

| DDL construct | Expected MySQL-specific variant | Actual usage in tests | SQLite-safe? |
|---|---|---|---|
| Auto-increment PK | `INT AUTO_INCREMENT` | `INTEGER PRIMARY KEY AUTOINCREMENT` | ✅ Yes |
| String columns | `VARCHAR(n)` / `TINYTEXT` | `TEXT` | ✅ Yes |
| Integer columns | `INT UNSIGNED` / `TINYINT` / `BIGINT` | `INTEGER` | ✅ Yes |
| Table engine/charset | `ENGINE=InnoDB CHARSET=utf8mb4` | none | ✅ Yes |
| Unique constraint | `UNIQUE KEY` | `UNIQUE` or column-level | ✅ Yes |
| Composite PK | supported both | `PRIMARY KEY (col1, col2)` | ✅ Yes |

### DDL per test class

**`DbalForumRepositoryTest`** (`phpbb_forums`):  
`INTEGER PRIMARY KEY AUTOINCREMENT`, all `INTEGER`/`TEXT` with `DEFAULT`. No MySQL-specific constructs.

**`DbalUserRepositoryTest`** (`phpbb_users`):  
Plain `INTEGER`/`TEXT NOT NULL DEFAULT`. No MySQL-specific constructs.

**`DbalBanRepositoryTest`** (`phpbb_banlist`):  
Plain `INTEGER`/`TEXT NOT NULL DEFAULT`. No MySQL-specific constructs.

**`DbalGroupRepositoryTest`** (`phpbb_groups` + `phpbb_user_group`):  
Two tables, both use `INTEGER`/`TEXT NOT NULL DEFAULT` only. No MySQL-specific constructs.

**`DbalRefreshTokenRepositoryTest`** (`phpbb_auth_refresh_tokens`):  
`token_hash TEXT NOT NULL UNIQUE`, `INTEGER DEFAULT NULL` for nullable columns. No MySQL-specific constructs.

**`DbalTopicRepositoryTest`** (`phpbb_topics`):  
All `INTEGER`/`TEXT NOT NULL DEFAULT`. No MySQL-specific constructs.

**`DbalPostRepositoryTest`** (`phpbb_posts`):  
All `INTEGER`/`TEXT NOT NULL DEFAULT`. No MySQL-specific constructs.

**`DbalConversationRepositoryTest`** (`phpbb_messaging_conversations` + `phpbb_messaging_participants`):  
`IF NOT EXISTS` clause, `INTEGER`/`TEXT`, composite PK. No MySQL-specific constructs.

**`DbalMessageRepositoryTest`** (`phpbb_messaging_messages` + `phpbb_messaging_message_deletes`):  
`IF NOT EXISTS`, composite PK on message_deletes. No MySQL-specific constructs.

**`DbalParticipantRepositoryTest`** (`phpbb_messaging_participants`):  
Same schema as above. No MySQL-specific constructs.

**Conclusion**: No DDL changes are needed after QueryBuilder migration. The SQLite test infrastructure is already dialect-clean.

---

## 3. Brittle Tests — SQL String Assertions

Only the two mock-based storage tests assert specific SQL strings or SQL keywords via `$this->stringContains(...)` on the mock's `executeStatement` argument.

### 3.1 `DbalStoredFileRepositoryTest`

| Test method | SQL assertion | Severity |
|---|---|---|
| `delete_executes_delete_statement` | `->with($this->stringContains('DELETE'))` | Low — keyword-level, survives QueryBuilder |
| `mark_claimed_executes_update_statement` | `->with($this->stringContains('UPDATE'))` | Low — keyword-level, survives QueryBuilder |

**QueryBuilder note**: `QueryBuilder` generates `DELETE FROM …` and `UPDATE … SET …` for the same operations, so these keyword-level assertions would likely still pass even if the test kept its mock approach. However, QueryBuilder returns a compiled SQL string that still contains `DELETE` / `UPDATE`.

### 3.2 `DbalStorageQuotaRepositoryTest`

| Test method | SQL assertion | Severity |
|---|---|---|
| `decrement_usage_executes_update` | `->with($this->stringContains('UPDATE'))` | Low |
| `reconcile_executes_update_with_actual_bytes` | `->with($this->stringContains('SET used_bytes = :actual_bytes'))` | **High** — brittle. Asserts a specific parameter name embedded in a raw SQL fragment. Will break with QueryBuilder migration |

**Source**: `DbalStorageQuotaRepositoryTest.php:104`
```php
->with($this->stringContains('SET used_bytes = :actual_bytes'));
```

After QueryBuilder migration, the generated SQL for `reconcile()` will look like:
```sql
UPDATE phpbb_storage_quotas SET used_bytes = ?, updated_at = ? WHERE user_id = ? AND forum_id = ?
```
(positional `?` if QueryBuilder uses positional params, not named `:actual_bytes`). The `stringContains` assertion would **fail**.

---

## 4. MySQL-Specific Runtime SQL in Repositories (Impact on Tests)

Even though the DDL is dialect-clean, two repositories use MySQL-specific SQL at runtime that affects the test strategy:

### 4.1 `DbalGroupRepository::addMember` — `ON DUPLICATE KEY UPDATE`

**Source**: `src/phpbb/user/Repository/DbalGroupRepository.php:103`

The method already contains a platform branch:
- **MySQL path**: `INSERT … ON DUPLICATE KEY UPDATE group_leader = :isLeader, user_pending = 0`
- **Non-MySQL path**: `transactional(DELETE + INSERT)`

**Test coverage**: `DbalGroupRepositoryTest::testAddMember_insert_idempotency` runs against SQLite — exercising only the **non-MySQL** branch. The MySQL `ON DUPLICATE KEY` path is NOT covered by unit tests; it is only exercised in E2E tests against MariaDB.

**QueryBuilder impact**: QueryBuilder has no native upsert API. The existing platform-branch pattern must be preserved post-migration.

### 4.2 `DbalStorageQuotaRepository::decrementUsage` — `GREATEST()`

**Source**: `src/phpbb/storage/Repository/DbalStorageQuotaRepository.php:78`
```sql
SET used_bytes = GREATEST(0, used_bytes - :bytes), updated_at = :now
```

`GREATEST()` is a MySQL/MariaDB scalar function. **SQLite does not support `GREATEST()`.**

**Test coverage**: The current mock hides this — `$this->createMock(Connection::class)` never executes the SQL, so the mock test passes on SQLite even though the SQL would fail at runtime on SQLite.

**QueryBuilder impact**: QueryBuilder cannot express `GREATEST()` portably. If storage repo tests are converted to SQLite integration tests (which they should be — see §5), `decrementUsage` will need either:
- A platform branch: `GREATEST(0, …)` on MySQL, `MAX(0, …)` on SQLite (SQLite supports `MAX` as aggregate, **not** as two-argument scalar — so this is also non-trivial)
- A platform branch using `CASE WHEN used_bytes >= :bytes THEN used_bytes - :bytes ELSE 0 END` (portable)
- Keeping `executeStatement` with raw SQL for this one function (acceptable exception to QueryBuilder-everywhere rule)

---

## 5. Test Changes Needed for QueryBuilder Migration

### 5.1 Repositories with no test changes needed

All 10 integration test classes (SQLite) test behaviour entirely through the public repository API (insert, find, update, delete). Because QueryBuilder generates equivalent SQL for the same operations, switching the implementation from `executeStatement(raw SQL)` to `$qb->…->executeStatement()` will not break any assertions in these tests.

| Repository | Action needed |
|---|---|
| `DbalForumRepository` | None |
| `DbalUserRepository` | None |
| `DbalBanRepository` | None |
| `DbalGroupRepository` | None (MySQL `ON DUPLICATE KEY` branch must be kept; SQLite path tested OK) |
| `DbalRefreshTokenRepository` | None |
| `DbalTopicRepository` | None |
| `DbalPostRepository` | None |
| `DbalConversationRepository` | None |
| `DbalMessageRepository` | None |
| `DbalParticipantRepository` | None |

### 5.2 `DbalStoredFileRepository` — migrate mock to integration test

**Current state**: Mock-based. Tests use `createMock(Connection::class)`.  
**Problem**: Mock tests do not exercise real SQL execution; brittle keyword assertions remain.  
**Action**: Replace the mock-based test class with a SQLite in-memory integration test.

Required DDL for `setUpSchema()`:
```sql
CREATE TABLE phpbb_storage_files (
    id            TEXT    PRIMARY KEY,
    asset_type    TEXT    NOT NULL DEFAULT '',
    visibility    TEXT    NOT NULL DEFAULT 'public',
    original_name TEXT    NOT NULL DEFAULT '',
    physical_name TEXT    NOT NULL DEFAULT '',
    mime_type     TEXT    NOT NULL DEFAULT '',
    filesize      INTEGER NOT NULL DEFAULT 0,
    checksum      TEXT    NOT NULL DEFAULT '',
    is_orphan     INTEGER NOT NULL DEFAULT 1,
    parent_id     TEXT    DEFAULT NULL,
    variant_type  TEXT    DEFAULT NULL,
    uploader_id   INTEGER NOT NULL DEFAULT 0,
    forum_id      INTEGER NOT NULL DEFAULT 0,
    created_at    INTEGER NOT NULL DEFAULT 0,
    claimed_at    INTEGER DEFAULT NULL
)
```
(Verify actual column names against `DbalStoredFileRepository::save()` implementation before finalising schema.)

**Tests to rewrite**:
- `find_by_id_returns_null_when_not_found` → insert nothing, assert null
- `find_by_id_returns_stored_file_when_found` → insert a row, assert hydrated entity
- `save_executes_insert_statement` → `save()` row, then `findById()` to verify persistence
- `delete_executes_delete_statement` → `save()` then `delete()` then `findById()` returns null
- `find_orphans_before_returns_array` → insert orphan rows with `claimed_at = null`, assert found
- `mark_claimed_executes_update_statement` → `save()` then `markClaimed()`, verify `claimed_at` set
- `find_variants_returns_empty_array_when_none` → assert empty array without parent rows
- `save_wraps_dbal_exception_in_repository_exception` → insert duplicate PK to force `UniqueConstraintViolationException`, assert wrapped in `RepositoryException`

### 5.3 `DbalStorageQuotaRepository` — migrate mock to integration test (with caveat)

**Current state**: Mock-based.  
**Problem 1**: Brittle `stringContains('SET used_bytes = :actual_bytes')` assertion will break after QueryBuilder migration.  
**Problem 2**: `GREATEST()` in `decrementUsage()` is MySQL-only — converting to SQLite integration test will expose this platform incompatibility.

**Action — two-step**:

**Step A** (prerequisite, independent of QueryBuilder migration): Replace `GREATEST(0, used_bytes - :bytes)` with a portable expression before converting to SQLite tests:  
```sql
-- Portable alternative:
SET used_bytes = CASE WHEN used_bytes >= :bytes THEN used_bytes - :bytes ELSE 0 END
```
Or keep as raw SQL escape hatch if a platform branch is too complex.

**Step B** (after Step A): Convert mock test to SQLite integration test. Required DDL:
```sql
CREATE TABLE phpbb_storage_quotas (
    user_id     INTEGER NOT NULL,
    forum_id    INTEGER NOT NULL,
    used_bytes  INTEGER NOT NULL DEFAULT 0,
    max_bytes   INTEGER NOT NULL DEFAULT 9223372036854775807,
    updated_at  INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, forum_id)
)
```

**Tests to rewrite**:
- `find_by_user_and_forum_returns_null_when_not_found` → assert null on empty table
- `find_by_user_and_forum_returns_entity` → insert row, assert hydrated entity
- `increment_usage_returns_true_when_row_updated` → insert row, call `incrementUsage`, assert return true and updated value
- `increment_usage_returns_false_when_no_row_updated` → call on missing row, assert false
- `decrement_usage_executes_update` → insert row with `used_bytes=500`, call `decrementUsage(200)`, assert `used_bytes=300`
- `reconcile_executes_update_with_actual_bytes` → insert row, call `reconcile(750)`, assert `used_bytes=750`
- `find_all_user_forum_pairs_returns_array` → insert multiple rows, assert count and structure

**The `stringContains('SET used_bytes = :actual_bytes')` brittle assertion must be dropped entirely** — integration test replaces it with a real round-trip assertion.

### 5.4 `DbalGroupRepository::addMember` — no new tests, but test coverage gap acknowledged

The MySQL `ON DUPLICATE KEY UPDATE` branch has no unit test coverage (only SQLite path tested). After QueryBuilder migration, if a QueryBuilder-based upsert for MySQL is introduced, a separate integration test targeting MariaDB would be needed — or the existing E2E suite is accepted as the only coverage for that path.

---

## 6. Summary Table

| Repository test | Changes needed for QueryBuilder migration |
|---|---|
| `DbalForumRepositoryTest` | None |
| `DbalUserRepositoryTest` | None |
| `DbalBanRepositoryTest` | None |
| `DbalGroupRepositoryTest` | None (MySQL branch gap acknowledged) |
| `DbalRefreshTokenRepositoryTest` | None |
| `DbalTopicRepositoryTest` | None |
| `DbalPostRepositoryTest` | None |
| `DbalConversationRepositoryTest` | None |
| `DbalMessageRepositoryTest` | None |
| `DbalParticipantRepositoryTest` | None |
| `DbalStoredFileRepositoryTest` | **Rewrite as SQLite integration test** — drop mocks, add `setUpSchema()` |
| `DbalStorageQuotaRepositoryTest` | **Rewrite as SQLite integration test** after fixing `GREATEST()` in `decrementUsage`; drop brittle `stringContains('SET used_bytes = :actual_bytes')` |

**Critical blocker**: `GREATEST()` in `DbalStorageQuotaRepository::decrementUsage` must be replaced with a portable expression **before** the SQLite integration test for that repo can be written. This is independent of QueryBuilder migration but is a prerequisite for test conversion.
