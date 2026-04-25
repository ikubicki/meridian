# Services – Raw SQL Inventory

**Research question**: How should we replace all hand-written SQL queries with Doctrine DBAL QueryBuilder across the `phpbb\` namespace?

**Scope**: All non-repository PHP classes under `src/phpbb/` that import `Doctrine\DBAL\Connection` or call `executeQuery` / `executeStatement` / `fetchOne` directly.

---

## Files Scanned

The following non-repository classes were identified via two grep passes:
1. `grep executeQuery|executeStatement src/phpbb/**/*.php`
2. `grep Doctrine\DBAL\Connection src/phpbb/**/*.php`

Then filtered to exclude `Repository/`, `db/migrations/`, and `db/DbalConnectionFactory.php`.

---

## Files WITH Raw SQL

### 1. `src/phpbb/hierarchy/Service/TrackingService.php`

**Class**: `phpbb\hierarchy\Service\TrackingService`

Tables used: `phpbb_forums_track`, `phpbb_topics_track`

#### Method: `markForumRead(int $forumId, int $userId): void`

```php
// Check for existing row
$existing = $this->connection->executeQuery(
    'SELECT user_id FROM phpbb_forums_track WHERE forum_id = :forumId AND user_id = :userId',
    ['forumId' => $forumId, 'userId' => $userId]
)->fetchAssociative();

// Branch: UPDATE if exists
$this->connection->executeStatement(
    'UPDATE phpbb_forums_track SET mark_time = :markTime WHERE forum_id = :forumId AND user_id = :userId',
    ['markTime' => $markTime, 'forumId' => $forumId, 'userId' => $userId]
);

// Branch: INSERT if not exists
$this->connection->executeStatement(
    'INSERT INTO phpbb_forums_track (user_id, forum_id, mark_time) VALUES (:userId, :forumId, :markTime)',
    ['userId' => $userId, 'forumId' => $forumId, 'markTime' => $markTime]
);
```

- SQL types: SELECT, UPDATE, INSERT (upsert pattern)
- Dialect-specific: none
- Portable: **Yes** (MySQL + SQLite)

#### Method: `hasUnread(int $forumId, int $userId): bool`

```php
// Check mark_time
$row = $this->connection->executeQuery(
    'SELECT mark_time FROM phpbb_forums_track WHERE forum_id = :forumId AND user_id = :userId',
    ['forumId' => $forumId, 'userId' => $userId]
)->fetchAssociative();

// Count unread topics
$result = $this->connection->executeQuery(
    'SELECT COUNT(*) FROM phpbb_topics_track WHERE forum_id = :forumId AND topic_last_post_time > :markTime',
    ['forumId' => $forumId, 'markTime' => $markTime]
)->fetchOne();
```

- SQL types: SELECT, SELECT COUNT(*)
- Dialect-specific: none
- Portable: **Yes**

---

### 2. `src/phpbb/hierarchy/Service/SubscriptionService.php`

**Class**: `phpbb\hierarchy\Service\SubscriptionService`

Table used: `phpbb_forums_watch`

#### Method: `subscribe(int $forumId, int $userId): void`

```php
$this->connection->executeStatement(
    'INSERT INTO phpbb_forums_watch (forum_id, user_id, notify_status) VALUES (:forumId, :userId, :notifyStatus)',
    ['forumId' => $forumId, 'userId' => $userId, 'notifyStatus' => 0]
);
```

- SQL types: INSERT
- Dialect-specific: none
- Portable: **Yes**

#### Method: `unsubscribe(int $forumId, int $userId): void`

```php
$this->connection->executeStatement(
    'DELETE FROM phpbb_forums_watch WHERE forum_id = :forumId AND user_id = :userId',
    ['forumId' => $forumId, 'userId' => $userId]
);
```

- SQL types: DELETE
- Dialect-specific: none
- Portable: **Yes**

#### Method: `isSubscribed(int $forumId, int $userId): bool`

```php
$result = $this->connection->executeQuery(
    'SELECT user_id FROM phpbb_forums_watch WHERE forum_id = :forumId AND user_id = :userId',
    ['forumId' => $forumId, 'userId' => $userId]
)->fetchAssociative();
```

- SQL types: SELECT (existence check)
- Dialect-specific: none
- Portable: **Yes**

---

### 3. `src/phpbb/storage/Quota/QuotaService.php`

**Class**: `phpbb\storage\Quota\QuotaService`

Table used: `phpbb_stored_files`

#### Method: `reconcileAll(): DomainEventCollection`

```php
$actual = (int) $this->connection->executeQuery(
    'SELECT COALESCE(SUM(filesize), 0) FROM phpbb_stored_files WHERE uploader_id = :uid AND forum_id = :fid',
    ['uid' => $userId, 'fid' => $forumId],
)->fetchOne();
```

- SQL types: SELECT with aggregate subquery
- Dialect-specific: **`COALESCE(SUM(...), 0)`** — COALESCE prevents NULL when no rows match; SUM is an aggregate
- Portable: **Yes** — both COALESCE and SUM are standard SQL supported by MySQL and SQLite

> **Migration note**: QueryBuilder does not have a native `COALESCE` wrapper. Use `$qb->select('COALESCE(SUM(filesize), 0)')` as a raw expression string, or create a `new Expression('COALESCE(SUM(filesize), 0)')`.

---

### 4. `src/phpbb/auth/Service/AuthorizationService.php`

**Class**: `phpbb\auth\Service\AuthorizationService`

Tables used: `phpbb_user_group`, `phpbb_acl_groups`, `phpbb_acl_options`, `phpbb_acl_roles_data`, `phpbb_acl_users`

All queries use `$this->connection->fetchOne($sql, $params)` (raw SQL shorthand, not executeQuery).

#### Method: `resolveGroupPermission(int $userId, string $permission, array $forumScope): ?int`

**Query 1 – direct option grant via group**:
```sql
SELECT ag.auth_setting
FROM phpbb_user_group ug
JOIN phpbb_acl_groups ag ON ag.group_id = ug.group_id
JOIN phpbb_acl_options ao ON ao.auth_option_id = ag.auth_option_id
WHERE ug.user_id = ?
  AND ug.user_pending = 0
  AND ao.auth_option = ?
  AND ag.forum_id IN ($placeholders)
ORDER BY ag.auth_setting DESC
```

**Query 2 – role-based grant via group**:
```sql
SELECT rd.auth_setting
FROM phpbb_user_group ug
JOIN phpbb_acl_groups ag ON ag.group_id = ug.group_id
JOIN phpbb_acl_roles_data rd ON rd.role_id = ag.auth_role_id
JOIN phpbb_acl_options ao ON ao.auth_option_id = rd.auth_option_id
WHERE ug.user_id = ?
  AND ug.user_pending = 0
  AND ag.auth_role_id > 0
  AND ao.auth_option = ?
  AND ag.forum_id IN ($placeholders)
ORDER BY rd.auth_setting DESC
```

- Params: positional `?` with dynamic `IN ($placeholders)` list built via `array_fill`
- SQL types: multi-table JOIN SELECT, ORDER BY, dynamic IN list
- Dialect-specific: none — standard SQL JOINs, ORDER BY
- Portable: **Yes**

> **Migration note**: Dynamic IN list must be replaced with `$qb->createNamedParameter()` or `Connection::PARAM_INT_ARRAY` type via `executeQuery(..., [...], [ArrayParameterType::INTEGER])`.

#### Method: `resolveUserPermission(int $userId, string $permission, array $forumScope): ?int`

**Query 3 – direct option override on user**:
```sql
SELECT au.auth_setting
FROM phpbb_acl_users au
JOIN phpbb_acl_options ao ON ao.auth_option_id = au.auth_option_id
WHERE au.user_id = ?
  AND ao.auth_option = ?
  AND au.forum_id IN ($placeholders)
ORDER BY au.auth_setting DESC
```

**Query 4 – role-based override on user**:
```sql
SELECT rd.auth_setting
FROM phpbb_acl_users au
JOIN phpbb_acl_roles_data rd ON rd.role_id = au.auth_role_id
JOIN phpbb_acl_options ao ON ao.auth_option_id = rd.auth_option_id
WHERE au.user_id = ?
  AND au.auth_role_id > 0
  AND ao.auth_option = ?
  AND au.forum_id IN ($placeholders)
ORDER BY rd.auth_setting DESC
```

- SQL types: multi-table JOIN SELECT, ORDER BY, dynamic IN list
- Dialect-specific: none
- Portable: **Yes**

> **Migration note**: Same dynamic IN list concern as above. All 4 queries share the same structure; a private helper method could share the QB construction.

---

## Files WITH Connection But No Raw SQL (Transaction-only)

These files import `Doctrine\DBAL\Connection` solely to manage transactions (`beginTransaction` / `commit` / `rollBack`). They contain **no raw SQL** and do not need QueryBuilder migration.

| File | Class | Connection usage |
|------|-------|-----------------|
| `src/phpbb/storage/Service/OrphanService.php` | `OrphanService` | beginTransaction, commit, rollBack only |
| `src/phpbb/storage/StorageService.php` | `StorageService` | beginTransaction, commit, rollBack only |
| `src/phpbb/messaging/ParticipantService.php` | `ParticipantService` | beginTransaction, commit, rollBack only |
| `src/phpbb/messaging/MessageService.php` | `MessageService` | beginTransaction, commit, rollBack only |
| `src/phpbb/messaging/ConversationService.php` | `ConversationService` | beginTransaction, commit, rollBack only |
| `src/phpbb/messaging/MessagingService.php` | `MessagingService` | Injected/unused for SQL; delegates to sub-services |
| `src/phpbb/threads/ThreadsService.php` | `ThreadsService` | beginTransaction, commit, rollBack only |

---

## Job Files – No Raw SQL

| File | Class | Notes |
|------|-------|-------|
| `src/phpbb/storage/Quota/QuotaReconciliationJob.php` | `QuotaReconciliationJob` | Delegates to `QuotaService::reconcileAll()` — no direct DB access |
| `src/phpbb/storage/Orphan/OrphanCleanupJob.php` | `OrphanCleanupJob` | Delegates to `OrphanService::cleanupExpired()` — no direct DB access |

---

## Summary Table

| File (under src/phpbb/) | Class | Methods with raw SQL | SQL operations | Dialect-specific | Portable |
|-------------------------|-------|---------------------|----------------|-----------------|---------|
| `hierarchy/Service/TrackingService.php` | `TrackingService` | `markForumRead`, `hasUnread` | SELECT, UPDATE, INSERT, SELECT COUNT | None | Yes |
| `hierarchy/Service/SubscriptionService.php` | `SubscriptionService` | `subscribe`, `unsubscribe`, `isSubscribed` | INSERT, DELETE, SELECT | None | Yes |
| `storage/Quota/QuotaService.php` | `QuotaService` | `reconcileAll` | SELECT + COALESCE(SUM) | `COALESCE`, `SUM` (both standard) | Yes |
| `auth/Service/AuthorizationService.php` | `AuthorizationService` | `resolveGroupPermission`, `resolveUserPermission` | 4× multi-JOIN SELECT with dynamic IN | Dynamic IN list (positional `?`) | Yes |

**Total: 4 service classes with raw SQL across 10 distinct query sites.**

---

## Migration Priority Notes

1. **`AuthorizationService`** — highest complexity; 4 multi-JOIN queries with dynamic IN lists. The dynamic-IN pattern requires `ArrayParameterType::INTEGER` or manual placeholder generation with QB. Consider extracting a private `buildAclQuery(qb, ...)` helper.

2. **`QuotaService::reconcileAll`** — single query but uses `COALESCE(SUM(...), 0)`; must be expressed as a raw QB expression since QB has no `coalesce()` method.

3. **`TrackingService`** — upsert pattern (SELECT then INSERT or UPDATE); consider replacing with a proper upsert or keeping SELECT + conditional INSERT/UPDATE on the QB.

4. **`SubscriptionService`** — simplest; straightforward INSERT/DELETE/SELECT with no complexity.
