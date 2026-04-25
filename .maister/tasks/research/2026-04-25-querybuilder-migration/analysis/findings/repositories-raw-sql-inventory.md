# Raw SQL Inventory — Dbal*Repository files under `src/phpbb/`

Research question: **How should we replace all hand-written SQL queries with Doctrine DBAL QueryBuilder across the `phpbb\` namespace?**

Scope: every `Dbal*Repository.php` file under `src/phpbb/`.  
Dialect functions noted: `HEX()`, `UNHEX()`, `GREATEST()`, `ON DUPLICATE KEY UPDATE`, and use of `LIMIT … OFFSET` with typed parameters.

---

## 1. `src/phpbb/user/Repository/DbalUserRepository.php`

### `findById`
```sql
SELECT * FROM phpbb_users WHERE user_id = :id LIMIT 1
```
**Type**: SELECT  
**Special functions**: none  
**Portable**: Y

---

### `findByIds`
```sql
SELECT * FROM phpbb_users WHERE user_id IN (?)
```
Bound with `ArrayParameterType::INTEGER`.  
**Type**: SELECT  
**Special functions**: none  
**Portable**: Y

---

### `findByUsername`
```sql
SELECT * FROM phpbb_users WHERE username_clean = :clean LIMIT 1
```
**Type**: SELECT  
**Special functions**: none  
**Portable**: Y

---

### `findByEmail`
```sql
SELECT * FROM phpbb_users WHERE user_email = :email LIMIT 1
```
**Type**: SELECT  
**Special functions**: none  
**Portable**: Y

---

### `create`
```sql
INSERT INTO phpbb_users
  (user_type, username, username_clean, user_email, user_password,
   user_colour, group_id, user_avatar, user_regdate, user_lastmark,
   user_posts, user_new, user_rank, user_ip, user_login_attempts,
   user_inactive_reason, user_form_salt, user_actkey)
VALUES
  (:type, :username, :usernameClean, :email, :passwordHash, …)
```
**Type**: INSERT  
**Special functions**: none  
**Portable**: Y

---

### `update`
Already uses `$this->connection->createQueryBuilder()->update()` with a dynamic `set()`/`setParameter()` loop.  
**Status**: ✅ Already QueryBuilder — skip.

---

### `delete`
```sql
DELETE FROM phpbb_users WHERE user_id = :id
```
**Type**: DELETE  
**Special functions**: none  
**Portable**: Y

---

### `search`
Already uses `$this->connection->createQueryBuilder()->select()` with `andWhere`, `setMaxResults`, `setFirstResult`.  
**Status**: ✅ Already QueryBuilder — skip.

---

### `findDisplayByIds`
```sql
SELECT user_id, username, user_colour, user_avatar
FROM phpbb_users WHERE user_id IN (?)
```
Bound with `ArrayParameterType::INTEGER`.  
**Type**: SELECT  
**Special functions**: none  
**Portable**: Y

---

### `incrementTokenGeneration`
```sql
UPDATE phpbb_users SET token_generation = token_generation + 1 WHERE user_id = :id
```
**Type**: UPDATE  
**Special functions**: arithmetic expression in SET  
**Portable**: Y

---

## 2. `src/phpbb/user/Repository/DbalGroupRepository.php`

### `findById`
```sql
SELECT * FROM phpbb_groups WHERE group_id = :id LIMIT 1
```
**Type**: SELECT · **Portable**: Y

---

### `findAll`
Two variants depending on `?GroupType $type`:
```sql
-- with type filter:
SELECT * FROM phpbb_groups WHERE group_type = :type ORDER BY group_name ASC
-- without:
SELECT * FROM phpbb_groups ORDER BY group_name ASC
```
**Type**: SELECT · **Portable**: Y

---

### `getMembershipsForUser`
```sql
SELECT * FROM phpbb_user_group WHERE user_id = :userId
```
**Type**: SELECT · **Portable**: Y

---

### `addMember`
Two branches:

**MySQL path** (`MySQLPlatform` instanceof check):
```sql
INSERT INTO phpbb_user_group (group_id, user_id, group_leader, user_pending)
VALUES (:groupId, :userId, :isLeader, 0)
ON DUPLICATE KEY UPDATE group_leader = :isLeader, user_pending = 0
```
**Special functions**: `ON DUPLICATE KEY UPDATE` — MySQL-only  
**Portable**: N (MySQL branch only)

**Non-MySQL (SQLite) path** – already portable fallback via `transactional(DELETE + INSERT)`:
```sql
DELETE FROM phpbb_user_group WHERE group_id = :groupId AND user_id = :userId
INSERT INTO phpbb_user_group (group_id, user_id, group_leader, user_pending)
  VALUES (:groupId, :userId, :isLeader, 0)
```
**Portable**: Y

> **Note**: This is the only file that already contains an explicit MySQL vs. SQLite branch.

---

### `removeMember`
```sql
DELETE FROM phpbb_user_group WHERE group_id = :groupId AND user_id = :userId
```
**Type**: DELETE · **Portable**: Y

---

## 3. `src/phpbb/user/Repository/DbalBanRepository.php`

### `isUserBanned`
```sql
SELECT 1 FROM phpbb_banlist
WHERE ban_userid = :userId AND ban_exclude = 0
  AND (ban_end = 0 OR ban_end > :now) LIMIT 1
```
**Type**: SELECT · **Portable**: Y

---

### `isIpBanned`
```sql
SELECT 1 FROM phpbb_banlist
WHERE ban_ip = :ip AND ban_exclude = 0
  AND (ban_end = 0 OR ban_end > :now) LIMIT 1
```
**Type**: SELECT · **Portable**: Y

---

### `isEmailBanned`
```sql
SELECT 1 FROM phpbb_banlist
WHERE ban_email = :email AND ban_exclude = 0
  AND (ban_end = 0 OR ban_end > :now) LIMIT 1
```
**Type**: SELECT · **Portable**: Y

---

### `findById`
```sql
SELECT * FROM phpbb_banlist WHERE ban_id = :id LIMIT 1
```
**Type**: SELECT · **Portable**: Y

---

### `findAll`
```sql
SELECT * FROM phpbb_banlist ORDER BY ban_id ASC
```
**Type**: SELECT · **Portable**: Y

---

### `create`
```sql
INSERT INTO phpbb_banlist
  (ban_userid, ban_ip, ban_email, ban_start, ban_end,
   ban_exclude, ban_reason, ban_give_reason)
VALUES (:userId, :ip, :email, :start, :end, :exclude, :reason, :displayReason)
```
**Type**: INSERT · **Portable**: Y

---

### `delete`
```sql
DELETE FROM phpbb_banlist WHERE ban_id = :id
```
**Type**: DELETE · **Portable**: Y

---

## 4. `src/phpbb/auth/Repository/DbalRefreshTokenRepository.php`

### `save`
```sql
INSERT INTO phpbb_auth_refresh_tokens
  (user_id, family_id, token_hash, issued_at, expires_at, revoked_at)
VALUES (:userId, :familyId, :tokenHash, :issuedAt, :expiresAt, :revokedAt)
```
**Type**: INSERT · **Portable**: Y

---

### `findByHash`
```sql
SELECT * FROM phpbb_auth_refresh_tokens WHERE token_hash = :hash LIMIT 1
```
**Type**: SELECT · **Portable**: Y

---

### `revokeByHash`
```sql
UPDATE phpbb_auth_refresh_tokens
SET revoked_at = :now
WHERE token_hash = :hash AND revoked_at IS NULL
```
**Type**: UPDATE · **Portable**: Y

---

### `revokeFamily`
```sql
UPDATE phpbb_auth_refresh_tokens
SET revoked_at = :now
WHERE family_id = :familyId AND revoked_at IS NULL
```
**Type**: UPDATE · **Portable**: Y

---

### `revokeAllForUser`
```sql
UPDATE phpbb_auth_refresh_tokens
SET revoked_at = :now
WHERE user_id = :userId AND revoked_at IS NULL
```
**Type**: UPDATE · **Portable**: Y

---

### `deleteExpired`
```sql
DELETE FROM phpbb_auth_refresh_tokens
WHERE expires_at < :now AND revoked_at IS NOT NULL
```
**Type**: DELETE · **Portable**: Y

---

## 5. `src/phpbb/hierarchy/Repository/DbalForumRepository.php`

### `findById`
```sql
SELECT * FROM phpbb_forums WHERE forum_id = :id LIMIT 1
```
**Type**: SELECT · **Portable**: Y

---

### `findAll`
```sql
SELECT * FROM phpbb_forums ORDER BY left_id ASC
```
**Type**: SELECT · **Portable**: Y

---

### `findChildren`
```sql
SELECT * FROM phpbb_forums WHERE parent_id = :parentId ORDER BY left_id ASC
```
**Type**: SELECT · **Portable**: Y

---

### `insertRaw`
```sql
INSERT INTO phpbb_forums
  (forum_name, forum_type, forum_desc, forum_link, forum_status, parent_id,
   display_on_index, display_subforum_list, enable_indexing, enable_icons,
   forum_style, forum_image, forum_rules, forum_rules_link, forum_password,
   forum_topics_per_page, forum_flags, forum_parents, left_id, right_id,
   forum_posts_approved, …)
VALUES
  (:forumName, :forumType, …, '[]', 0, 0, 0, 0, 0, …)
```
**Type**: INSERT · **Special functions**: none · **Portable**: Y

---

### `update`
Dynamically assembled raw SQL string:
```sql
UPDATE phpbb_forums SET <dynamic SET clauses> WHERE forum_id = :forumId
```
All field names are hard-coded in the PHP conditionals (not injected from user input).  
**Type**: UPDATE · **Portable**: Y  
> ⚠️ Candidate for QueryBuilder `->set()` loop.

---

### `delete`
```sql
DELETE FROM phpbb_forums WHERE forum_id = :forumId
```
**Type**: DELETE · **Portable**: Y

---

### `updateTreePosition`
```sql
UPDATE phpbb_forums
SET left_id = :leftId, right_id = :rightId, parent_id = :parentId
WHERE forum_id = :forumId
```
**Type**: UPDATE · **Portable**: Y

---

### `shiftLeftIds`
```sql
UPDATE phpbb_forums SET left_id = left_id + :delta WHERE left_id >= :threshold
```
**Type**: UPDATE · arithmetic expression in SET · **Portable**: Y

---

### `shiftRightIds`
```sql
UPDATE phpbb_forums SET right_id = right_id + :delta WHERE right_id >= :threshold
```
**Type**: UPDATE · arithmetic expression in SET · **Portable**: Y

---

### `updateParentId`
```sql
UPDATE phpbb_forums SET parent_id = :parentId WHERE forum_id = :forumId
```
**Type**: UPDATE · **Portable**: Y

---

### `clearParentsCache`
```sql
UPDATE phpbb_forums SET forum_parents = '[]' WHERE forum_id = :forumId
```
**Type**: UPDATE · **Portable**: Y

---

## 6. `src/phpbb/threads/Repository/DbalTopicRepository.php`

### `findById`
```sql
SELECT topic_id, forum_id, topic_title, topic_poster, topic_time, …
FROM phpbb_topics WHERE topic_id = :id LIMIT 1
```
**Type**: SELECT · **Portable**: Y

---

### `findByForum`
Two queries:
```sql
-- count:
SELECT COUNT(*) FROM phpbb_topics
WHERE forum_id = :forumId AND topic_visibility = 1

-- paginated list:
SELECT topic_id, forum_id, … FROM phpbb_topics
WHERE forum_id = :forumId AND topic_visibility = 1
ORDER BY topic_last_post_time DESC
LIMIT :limit OFFSET :offset
```
`limit` and `offset` bound with `ParameterType::INTEGER`.  
**Type**: SELECT (COUNT + paginated) · **Special functions**: `LIMIT … OFFSET` · **Portable**: Y (DBAL handles dialect)

---

### `insert`
```sql
INSERT INTO phpbb_topics
  (forum_id, topic_title, topic_poster, topic_time,
   topic_first_poster_name, topic_first_poster_colour,
   topic_last_poster_id, topic_last_poster_name, topic_last_poster_colour,
   topic_last_post_subject, topic_last_post_time, topic_visibility)
VALUES (:forumId, :title, :posterId, :now, …, 1)
```
**Type**: INSERT · **Portable**: Y

---

### `updateFirstLastPost`
```sql
UPDATE phpbb_topics
SET topic_first_post_id = :postId, topic_last_post_id = :postId
WHERE topic_id = :topicId
```
**Type**: UPDATE · **Portable**: Y

---

### `updateLastPost`
```sql
UPDATE phpbb_topics
SET topic_last_post_id       = :postId,
    topic_last_poster_id     = :posterId,
    topic_last_poster_name   = :posterName,
    topic_last_poster_colour = :posterColour,
    topic_last_post_time     = :now
WHERE topic_id = :topicId
```
**Type**: UPDATE · **Portable**: Y

---

## 7. `src/phpbb/threads/Repository/DbalPostRepository.php`

### `findById`
```sql
SELECT post_id, topic_id, forum_id, poster_id, post_time,
       post_text, post_subject, post_username, poster_ip, post_visibility
FROM phpbb_posts WHERE post_id = :id LIMIT 1
```
**Type**: SELECT · **Portable**: Y

---

### `findByTopic`
Two queries:
```sql
-- count:
SELECT COUNT(*) FROM phpbb_posts
WHERE topic_id = :topicId AND post_visibility = 1

-- paginated list:
SELECT post_id, topic_id, … FROM phpbb_posts
WHERE topic_id = :topicId AND post_visibility = 1
ORDER BY post_time ASC
LIMIT :limit OFFSET :offset
```
`limit` and `offset` bound with `ParameterType::INTEGER`.  
**Type**: SELECT (COUNT + paginated) · **Portable**: Y

---

### `insert`
```sql
INSERT INTO phpbb_posts
  (topic_id, forum_id, poster_id, post_time,
   post_text, post_subject, post_username, poster_ip, post_visibility)
VALUES (:topicId, :forumId, :posterId, :now, :content, :subject, :posterUsername, :posterIp, :visibility)
```
**Type**: INSERT · **Portable**: Y

---

## 8. `src/phpbb/messaging/Repository/DbalConversationRepository.php`

### `findById`
```sql
SELECT conversation_id, participant_hash, title, created_by, created_at,
       last_message_id, last_message_at, message_count, participant_count
FROM phpbb_messaging_conversations WHERE conversation_id = :id LIMIT 1
```
**Type**: SELECT · **Portable**: Y

---

### `findByParticipantHash`
```sql
SELECT … FROM phpbb_messaging_conversations WHERE participant_hash = :hash LIMIT 1
```
**Type**: SELECT · **Portable**: Y

---

### `listByUser`
Dynamically builds two queries (optional `state` filter):
```sql
-- count:
SELECT COUNT(*) FROM phpbb_messaging_conversations c
INNER JOIN phpbb_messaging_participants p ON c.conversation_id = p.conversation_id
WHERE p.user_id = :userId AND p.left_at IS NULL [AND p.state = :state]

-- paginated list:
SELECT c.conversation_id, … FROM phpbb_messaging_conversations c
INNER JOIN phpbb_messaging_participants p ON c.conversation_id = p.conversation_id
WHERE p.user_id = :userId AND p.left_at IS NULL [AND p.state = :state]
ORDER BY c.last_message_at DESC, c.created_at DESC
LIMIT :limit OFFSET :offset
```
`limit` and `offset` bound with `ParameterType::INTEGER`.  
**Type**: SELECT (JOIN + COUNT + paginated) · **Portable**: Y

---

### `insert`
```sql
INSERT INTO phpbb_messaging_conversations
  (participant_hash, title, created_by, created_at, message_count, participant_count)
VALUES (:hash, :title, :createdBy, :now, 0, :participantCount)
```
**Type**: INSERT · **Portable**: Y

---

### `update`
Dynamically assembled, with explicit **whitelist** of allowed field names:
```php
$allowed = ['title', 'last_message_id', 'last_message_at', 'message_count',
            'participant_count', 'participant_hash'];
```
```sql
UPDATE phpbb_messaging_conversations
SET <whitelisted fields> = :params
WHERE conversation_id = :conversationId
```
**Type**: UPDATE · **Portable**: Y · ✅ Whitelist present — safe

---

### `delete`
```sql
DELETE FROM phpbb_messaging_conversations WHERE conversation_id = :id
```
**Type**: DELETE · **Portable**: Y

---

## 9. `src/phpbb/messaging/Repository/DbalMessageRepository.php`

### `findById`
```sql
SELECT message_id, conversation_id, author_id, message_text, message_subject,
       created_at, edited_at, edit_count, metadata
FROM phpbb_messaging_messages WHERE message_id = :id LIMIT 1
```
**Type**: SELECT · **Portable**: Y

---

### `listByConversation`
```sql
-- count:
SELECT COUNT(*) FROM phpbb_messaging_messages WHERE conversation_id = :conversationId

-- paginated list:
SELECT … FROM phpbb_messaging_messages WHERE conversation_id = :conversationId
ORDER BY created_at ASC LIMIT :limit OFFSET :offset
```
**Type**: SELECT (COUNT + paginated) · **Portable**: Y

---

### `search`
```sql
-- count:
SELECT COUNT(*) FROM phpbb_messaging_messages
WHERE conversation_id = :conversationId
  AND (message_text LIKE :query OR message_subject LIKE :query)

-- paginated list:
SELECT … FROM phpbb_messaging_messages
WHERE conversation_id = :conversationId
  AND (message_text LIKE :query OR message_subject LIKE :query)
ORDER BY created_at ASC LIMIT :limit OFFSET :offset
```
`$query` token escaped via `addcslashes($query, '%_')` before binding.  
**Type**: SELECT (LIKE search + COUNT + paginated) · **Portable**: Y

---

### `insert`
```sql
INSERT INTO phpbb_messaging_messages
  (conversation_id, author_id, message_text, message_subject, created_at, edit_count, metadata)
VALUES (:conversationId, :authorId, :messageText, :messageSubject, :now, 0, :metadata)
```
**Type**: INSERT · **Portable**: Y

---

### `update`
Dynamically assembled — **no field-name whitelist**:
```php
foreach ($fields as $field => $value) {       // ← field keys flow into SQL unguarded
    $set[] = $field . ' = :' . $field;
    $params[$field] = $value;
}
```
```sql
UPDATE phpbb_messaging_messages SET <dynamic fields> WHERE message_id = :messageId
```
**Type**: UPDATE · **Portable**: Y (values parameterized) · ⚠️ **Security note**: field names are not whitelisted — SQL injection risk if `$fields` keys originate from untrusted input.

---

### `deletePerUser` (soft-delete)
Three queries inside a single method:
```sql
-- 1. resolve conversation:
SELECT conversation_id FROM phpbb_messaging_messages WHERE message_id = :id

-- 2a. check existing soft-delete record:
SELECT 1 FROM phpbb_messaging_message_deletes
WHERE conversation_id = :conversationId AND message_id = :messageId AND user_id = :userId LIMIT 1

-- 2b-update: if exists:
UPDATE phpbb_messaging_message_deletes
SET deleted_at = :now
WHERE conversation_id = :conversationId AND message_id = :messageId AND user_id = :userId

-- 2b-insert: if new:
INSERT INTO phpbb_messaging_message_deletes
  (conversation_id, message_id, user_id, deleted_at)
VALUES (:conversationId, :messageId, :userId, :now)
```
**Type**: SELECT + UPDATE/INSERT (upsert pattern via check-then-write) · **Portable**: Y  
> ⚠️ Could be simplified with QueryBuilder or a proper UPSERT.

---

### `isDeletedForUser`
```sql
SELECT 1 FROM phpbb_messaging_message_deletes
WHERE message_id = :messageId AND user_id = :userId LIMIT 1
```
**Type**: SELECT · **Portable**: Y

---

## 10. `src/phpbb/messaging/Repository/DbalParticipantRepository.php`

### `findByConversation`
```sql
SELECT conversation_id, user_id, role, state, joined_at, left_at,
       last_read_message_id, last_read_at, is_muted, is_blocked
FROM phpbb_messaging_participants WHERE conversation_id = :conversationId
```
**Type**: SELECT · **Portable**: Y

---

### `findByUser`
```sql
SELECT … FROM phpbb_messaging_participants WHERE user_id = :userId
```
**Type**: SELECT · **Portable**: Y

---

### `findByConversationAndUser`
```sql
SELECT … FROM phpbb_messaging_participants
WHERE conversation_id = :conversationId AND user_id = :userId LIMIT 1
```
**Type**: SELECT · **Portable**: Y

---

### `insert`
```sql
INSERT INTO phpbb_messaging_participants
  (conversation_id, user_id, role, state, joined_at, is_muted, is_blocked)
VALUES (:conversationId, :userId, :role, "active", :now, 0, 0)
```
**Type**: INSERT · **Portable**: Y (note: `"active"` double-quoted string literal — MySQL/SQLite accept this; prefer `'active'` in standard SQL)

---

### `update`
Dynamically assembled — **no field-name whitelist**:
```php
foreach ($fields as $field => $value) {       // ← same unguarded pattern
    $set[] = $field . ' = :' . $field;
    $params[$field] = $value;
}
```
```sql
UPDATE phpbb_messaging_participants SET <dynamic fields>
WHERE conversation_id = :conversationId AND user_id = :userId
```
**Type**: UPDATE · **Portable**: Y · ⚠️ **Security note**: same unguarded field-name pattern as `DbalMessageRepository::update`.

---

### `delete`
```sql
DELETE FROM phpbb_messaging_participants
WHERE conversation_id = :conversationId AND user_id = :userId
```
**Type**: DELETE · **Portable**: Y

---

## 11. `src/phpbb/storage/Repository/DbalStoredFileRepository.php`

All queries in this file use `HEX()` and `UNHEX()` MySQL functions because the `id` and `parent_id` columns are stored as binary (`VARBINARY` / `BINARY(16)`) values, with UUIDs represented as hex strings in PHP.

### `findById`
```sql
SELECT HEX(id) AS id, asset_type, visibility, original_name, physical_name,
       mime_type, filesize, checksum, is_orphan, HEX(parent_id) AS parent_id,
       variant_type, uploader_id, forum_id, created_at, claimed_at
FROM phpbb_stored_files WHERE id = UNHEX(:id) LIMIT 1
```
**Type**: SELECT · **Special functions**: `HEX()`, `UNHEX()` · **Portable**: **N** (MySQL-only)

---

### `save`
```sql
INSERT INTO phpbb_stored_files
  (id, asset_type, visibility, …, parent_id, …)
VALUES
  (UNHEX(:id), :asset_type, :visibility, …, UNHEX(:parent_id), …)
```
**Type**: INSERT · **Special functions**: `UNHEX()` · **Portable**: **N**

---

### `delete`
```sql
DELETE FROM phpbb_stored_files WHERE id = UNHEX(:id)
```
**Type**: DELETE · **Special functions**: `UNHEX()` · **Portable**: **N**

---

### `findOrphansBefore`
```sql
SELECT HEX(id) AS id, …, HEX(parent_id) AS parent_id, …
FROM phpbb_stored_files WHERE is_orphan = 1 AND created_at < :ts
```
**Type**: SELECT · **Special functions**: `HEX()` · **Portable**: **N**

---

### `markClaimed`
```sql
UPDATE phpbb_stored_files
SET is_orphan = 0, claimed_at = :claimed_at WHERE id = UNHEX(:id)
```
**Type**: UPDATE · **Special functions**: `UNHEX()` · **Portable**: **N**

---

### `findVariants`
```sql
SELECT HEX(id) AS id, …, HEX(parent_id) AS parent_id, …
FROM phpbb_stored_files WHERE parent_id = UNHEX(:parent_id)
```
**Type**: SELECT · **Special functions**: `HEX()`, `UNHEX()` · **Portable**: **N**

---

## 12. `src/phpbb/storage/Repository/DbalStorageQuotaRepository.php`

### `findByUserAndForum`
```sql
SELECT user_id, forum_id, used_bytes, max_bytes, updated_at
FROM phpbb_storage_quotas WHERE user_id = :user_id AND forum_id = :forum_id LIMIT 1
```
**Type**: SELECT · **Portable**: Y

---

### `incrementUsage`
```sql
UPDATE phpbb_storage_quotas
SET used_bytes = used_bytes + :bytes, updated_at = :now
WHERE user_id = :user_id AND forum_id = :forum_id
  AND used_bytes + :bytes <= max_bytes
```
**Type**: UPDATE · arithmetic expression in SET + WHERE · **Portable**: Y

---

### `decrementUsage`
```sql
UPDATE phpbb_storage_quotas
SET used_bytes = GREATEST(0, used_bytes - :bytes), updated_at = :now
WHERE user_id = :user_id AND forum_id = :forum_id
```
**Type**: UPDATE · **Special functions**: `GREATEST()` (MySQL-only) · **Portable**: **N**

---

### `reconcile`
```sql
UPDATE phpbb_storage_quotas
SET used_bytes = :actual_bytes, updated_at = :now
WHERE user_id = :user_id AND forum_id = :forum_id
```
**Type**: UPDATE · **Portable**: Y

---

### `findAllUserForumPairs`
```sql
SELECT user_id, forum_id FROM phpbb_storage_quotas
```
**Type**: SELECT · **Portable**: Y

---

### `initDefault`
```sql
INSERT INTO phpbb_storage_quotas
  (user_id, forum_id, used_bytes, max_bytes, updated_at)
VALUES (:user_id, :forum_id, 0, :max_bytes, :now)
```
`UniqueConstraintViolationException` is caught and silently ignored (no-op upsert).  
**Type**: INSERT (idempotent via catch) · **Portable**: Y

---

## SQL Pattern Summary Table

| Pattern | Portable (Y/N) | Files affected | QueryBuilder feasibility |
|---|---|---|---|
| `SELECT … WHERE col = :p LIMIT 1` | Y | All 12 files | ✅ Easy: `->select()->from()->where()->setMaxResults(1)` |
| `SELECT * WHERE id IN (?)` with `ArrayParameterType` | Y | DbalUserRepository | ✅ Easy: `->where($qb->expr()->in('col', ':ids'))->setParameter('ids', $ids, ArrayParameterType::INTEGER)` |
| `SELECT COUNT(*)` | Y | DbalTopicRepository, DbalPostRepository, DbalConversationRepository, DbalMessageRepository | ✅ Easy: `->select('COUNT(*)')->from()->where()` |
| `SELECT … LIMIT :limit OFFSET :offset` with `ParameterType::INTEGER` | Y | DbalTopicRepository, DbalPostRepository, DbalConversationRepository, DbalMessageRepository | ✅ Easy: `->setMaxResults($ctx->perPage)->setFirstResult($offset)` |
| `SELECT … INNER JOIN … WHERE … LIMIT/OFFSET` (dynamic optional filter) | Y | DbalConversationRepository | ✅ Medium: `->join(); ->andWhere()` loop |
| `SELECT … LIKE :query` | Y | DbalMessageRepository | ✅ Easy: `->andWhere('col LIKE :q')->setParameter('q', $term)` |
| `INSERT INTO … VALUES (…)` | Y | All files | ✅ Easy: `->insert()->setValue()` or `$connection->insert()` |
| `INSERT … ON DUPLICATE KEY UPDATE` | N (MySQL-only) | DbalGroupRepository (MySQL branch) | ⚠️ Keep platform branch: MySQL → raw SQL; non-MySQL → existing DELETE+INSERT fallback |
| `INSERT` + catch `UniqueConstraintViolationException` (idempotent) | Y | DbalStorageQuotaRepository | ✅ Keep as-is; idiomatic DBAL pattern |
| `UPDATE … SET col = :p WHERE …` (fixed columns) | Y | Most files | ✅ Easy: `->update()->set()->where()` |
| `UPDATE … SET col = col + :delta` (arithmetic in SET) | Y | DbalUserRepository, DbalForumRepository, DbalStorageQuotaRepository | ✅ Easy: `->set('col', 'col + :delta')` |
| `UPDATE … SET <dynamic columns>` (whitelist-guarded loop) | Y | DbalConversationRepository, DbalForumRepository | ✅ Medium: `->set($col, ':'.$col)` loop inside QueryBuilder |
| `UPDATE … SET <dynamic columns>` (**no whitelist**) | Y (values safe) | DbalMessageRepository, DbalParticipantRepository | ⚠️ **Must add whitelist** before converting — security fix required |
| `UPDATE … SET used_bytes = GREATEST(0, …)` | N (MySQL-only) | DbalStorageQuotaRepository | ⚠️ Replace with `CASE WHEN (used_bytes - :bytes) < 0 THEN 0 ELSE (used_bytes - :bytes) END` via `->set('used_bytes', '…')` raw expression, or use `ExpressionBuilder` |
| `DELETE FROM … WHERE …` | Y | All files | ✅ Easy: `->delete()->where()` |
| `SELECT HEX(id), HEX(parent_id) / WHERE id = UNHEX(:id)` | N (MySQL-only) | DbalStoredFileRepository (all 6 methods) | ⚠️ Hard: no native DBAL QB expression for HEX/UNHEX; must use `->expr()->customFunction()` or keep raw SQL with `$qb->getConnection()->getDatabasePlatform()` branch; alternatively store IDs as CHAR(36) UUID strings |
| Check-then-write upsert (SELECT 1 → UPDATE/INSERT) | Y | DbalMessageRepository (`deletePerUser`) | ⚠️ Keep pattern or consolidate with `INSERT … ON CONFLICT` (SQLite) / `ON DUPLICATE KEY` (MySQL) platform branch |
| String literal in double quotes (`"active"`) | Quasi-Y | DbalParticipantRepository (`insert`) | ✅ Fix to single quotes `'active'` regardless of QB migration |

---

## Key Observations

1. **Majority is portable**: 10 of 12 files use only standard ANSI SQL patterns that map cleanly to DBAL QueryBuilder.

2. **Three non-portable constructs** require attention:
   - `HEX()` / `UNHEX()` in `DbalStoredFileRepository` — affects 6 methods; root cause is binary UUID column storage.
   - `GREATEST()` in `DbalStorageQuotaRepository::decrementUsage` — affects 1 method.
   - `ON DUPLICATE KEY UPDATE` in `DbalGroupRepository::addMember` — already has a portable SQLite fallback.

3. **Two security risks** (unguarded dynamic field names):
   - `DbalMessageRepository::update`
   - `DbalParticipantRepository::update`
   Both must receive an explicit field-name allowlist **before** converting to QueryBuilder.

4. **Already uses QueryBuilder**:
   - `DbalUserRepository::update` — `createQueryBuilder()->update()` with `set()`/`setParameter()` loop
   - `DbalUserRepository::search` — `createQueryBuilder()->select()` with pagination

5. **`DbalStoredFileRepository` is the highest-effort migration**: all 6 methods depend on MySQL-only `HEX`/`UNHEX`. The cleanest long-term fix is to change the column type to `CHAR(36)` UUID strings (requires a schema migration), which would make all queries portable and eliminate the special functions entirely.
