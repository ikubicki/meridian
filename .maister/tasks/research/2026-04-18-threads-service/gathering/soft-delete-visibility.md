# Soft-Delete & Content Visibility System — Findings

## 1. Visibility States

Defined in `src/phpbb/common/constants.php:91-94`:

```php
define('ITEM_UNAPPROVED', 0); // has not yet been approved
define('ITEM_APPROVED',   1); // approved, not soft-deleted
define('ITEM_DELETED',    2); // soft deleted
define('ITEM_REAPPROVE',  3); // edited, needs re-approval
```

Both `post_visibility` (posts table) and `topic_visibility` (topics table) use these same integer values.

---

## 2. content_visibility Class — Public API

**File**: `src/phpbb/forums/content_visibility.php`  
**Namespace**: `phpbb\content_visibility`  
**Service ID**: `content.visibility`

### Constructor Dependencies
- `\phpbb\auth\auth`, `\phpbb\config\config`, `\phpbb\event\dispatcher_interface`, `\phpbb\db\driver\driver_interface`, `\phpbb\user`
- Table name strings: `$forums_table`, `$posts_table`, `$topics_table`, `$users_table`

### Public Methods

#### `can_soft_delete($forum_id, $poster_id, $post_locked): bool` (Lines 108-120)
Returns true if the current user can soft-delete:
- Moderators with `m_softdelete` permission → always true
- Users with `f_softdelete` permission → only own posts, and only if post is not locked

#### `get_count($mode, $data, $forum_id): int` (Lines 128-136)
Returns visible count based on permissions.
- `$mode`: one of `topic_posts`, `forum_posts`, `forum_topics`
- If user has `m_approve` → returns `approved + unapproved + softdeleted` (the "real" total)
- Else → returns only `approved` (the "displayed" count)

#### `is_visible($mode, $forum_id, $data): bool` (Lines 145-182)
Checks if a single topic/post is visible to the current user.
- Approved items → always visible
- Unapproved/Reapprove items → visible to the poster themselves (if `display_unapproved_posts` config is on and user is not anonymous)
- Users with `m_approve` → see everything
- Fires event `core.phpbb_content_visibility_is_visible`

#### `get_visibility_sql($mode, $forum_id, $table_alias): string` (Lines 191-248)
Generates WHERE clause for a **single forum**.
- Moderators (`m_approve`) → `1 = 1` (see all)
- Normal users → `{prefix}{mode}_visibility = 1` (ITEM_APPROVED only)
  - If `display_unapproved_posts` is on → also includes own UNAPPROVED/REAPPROVE posts
- Fires event `core.phpbb_content_visibility_get_visibility_sql_before`

**Example output** (normal user, no unapproved display):
```sql
(post_visibility = 1)
```

**Example output** (normal user with unapproved display):
```sql
((post_visibility = 1) OR ((post_visibility = 0 OR post_visibility = 3) AND poster_id = 42))
```

#### `get_forums_visibility_sql($mode, $forum_ids, $table_alias): string` (Lines 258-319)
Generates WHERE clause for **multiple forums**.
- Splits forums into: `$approve_forums` (where user has `m_approve`) and remaining
- Moderated forums → all visibilities shown
- Non-moderator forums → ITEM_APPROVED only

**Example output**:
```sql
((forum_id IN (5, 7)) OR (post_visibility = 1 AND forum_id IN (1, 2, 3)))
```

#### `get_global_visibility_sql($mode, $exclude_forum_ids, $table_alias): string` (Lines 328-383)
Generates WHERE clause for **all forums** minus excluded ones.
- Includes approved items in all non-excluded forums
- Adds all items from forums where user has `m_approve`

#### `set_post_visibility($visibility, $post_id, $topic_id, $forum_id, $user_id, $time, $reason, $is_starter, $is_latest, $limit_visibility, $limit_delete_time): array` (Lines 398-671)
**Main method for changing post visibility.** This is the core of soft-delete, approval, and restore.

Parameters:
- `$visibility` — target state: ITEM_APPROVED, ITEM_DELETED, or ITEM_REAPPROVE
- `$post_id` — single int, array of IDs, or empty (= all posts in topic)
- `$limit_visibility` / `$limit_delete_time` — when restoring a topic, only restore posts that were in a specific state at a specific time

**What it does** (step by step):
1. Validates `$visibility` is in `[ITEM_APPROVED, ITEM_DELETED, ITEM_REAPPROVE]`
2. Selects all target posts, tracks per-poster postcounts and old visibilities
3. **Updates posts table**: sets `post_visibility`, `post_delete_user`, `post_delete_time`, `post_delete_reason`
4. **Updates user postcounts**:
   - ITEM_DELETED / ITEM_REAPPROVE → decrement `user_posts` for affected authors
   - ITEM_APPROVED → increment `user_posts`
   - Also updates global `num_posts` config
5. **Syncs first/last post info**:
   - If `$is_latest` but not starter → calls `update_post_information('topic', ...)` and `update_post_information('forum', ...)`
   - If `$is_starter` → calls `sync('topic', ...)` which recursively resyncs the forum
6. **Updates topic and forum counter columns** — uses a field alias map:
   ```php
   ITEM_APPROVED   => 'posts_approved'
   ITEM_UNAPPROVED => 'posts_unapproved'
   ITEM_DELETED    => 'posts_softdeleted'
   ITEM_REAPPROVE  => 'posts_unapproved'
   ```
   Decrements old state counters, increments new state counter for both `topic_posts_*` and `forum_posts_*`
7. Fires events: `core.set_post_visibility_before_sql`, `core.set_post_visibility_after`

#### `set_topic_visibility($visibility, $topic_id, $forum_id, $user_id, $time, $reason, $force_update_all): array` (Lines 688-809)
**Wraps set_post_visibility for whole-topic operations.**

Updates the **topics table**:
- Sets `topic_visibility`, `topic_delete_user`, `topic_delete_time`, `topic_delete_reason`

Then cascades to posts:
- **Restoring** (`ITEM_DELETED → ITEM_APPROVED`): Only restores posts that were soft-deleted at the same `topic_delete_time` with `ITEM_DELETED` visibility (preserving individually-soft-deleted posts)
- **Soft-deleting** (`ITEM_APPROVED → ITEM_DELETED`): Only marks currently-approved posts as ITEM_DELETED (preserving individually-unapproved posts)
- **Force mode**: Updates all posts regardless of current state

Fires events: `core.set_topic_visibility_before_sql`, `core.set_topic_visibility_after`

#### `add_post_to_statistic($data, &$sql_data)` (Lines 817-830)
Increments `topic_posts_approved + 1`, `forum_posts_approved + 1`, optionally `user_posts + 1`, and global `num_posts + 1`.

#### `remove_post_from_statistic($data, &$sql_data)` (Lines 838-879)
Decrements the correct counter based on the post's **current** visibility:
- ITEM_APPROVED → decrements `*_approved`, `user_posts`, `num_posts`
- ITEM_UNAPPROVED / ITEM_REAPPROVE → decrements `*_unapproved`
- ITEM_DELETED → decrements `*_softdeleted`

#### `remove_topic_from_statistic($data, &$sql_data)` (Lines 887-908)
Same as above but for topic deletion — decrements both `forum_posts_*` and `forum_topics_*` based on current visibility.

---

## 3. Counter Pairs (Displayed vs Real)

The system uses **three separate counters** instead of a "real vs displayed" pair pattern. The "real total" is the sum of all three.

### Topic-level counters (topics table)
| Column | Description |
|--------|-------------|
| `topic_posts_approved` | Visible post count (displayed to normal users) |
| `topic_posts_unapproved` | Posts pending approval (ITEM_UNAPPROVED + ITEM_REAPPROVE) |
| `topic_posts_softdeleted` | Soft-deleted posts |

**"topic_replies"** for display = `topic_posts_approved - 1` (subtract the first post).  
**"topic_replies_real"** = `topic_posts_approved + topic_posts_unapproved + topic_posts_softdeleted - 1`.

The `get_count('topic_posts', $data, $forum_id)` method returns either `approved` or `approved + unapproved + softdeleted` based on `m_approve` permission.

### Forum-level counters (forums table)
| Column | Description |
|--------|-------------|
| `forum_posts_approved` | Approved posts |
| `forum_posts_unapproved` | Unapproved posts |
| `forum_posts_softdeleted` | Soft-deleted posts |
| `forum_topics_approved` | Approved topics |
| `forum_topics_unapproved` | Unapproved topics |
| `forum_topics_softdeleted` | Soft-deleted topics |

### Global counters (config table)
| Key | Description |
|-----|-------------|
| `num_posts` | Total approved posts site-wide |
| `num_topics` | Total approved topics site-wide |

---

## 4. Soft-Delete Flow

### Soft-deleting a single post (not first/last of topic)

**Entry point**: `delete_post()` in `functions_posting.php:1373` with `$is_soft = true`

1. Determines `$post_mode = 'delete'` (middle post)
2. Calls `$phpbb_content_visibility->set_post_visibility(ITEM_DELETED, $post_id, $topic_id, $forum_id, ...)` (line 1434)
3. Inside `set_post_visibility`:
   - Updates `posts` table: `post_visibility = 2, post_delete_user, post_delete_time, post_delete_reason`
   - Decrements `user_posts` for the author
   - Decrements `topic_posts_approved`, `forum_posts_approved`
   - Increments `topic_posts_softdeleted`, `forum_posts_softdeleted`
   - Decrements global `num_posts`

### Soft-deleting a whole topic (first == last post, or explicitly the topic)

**Entry point**: `delete_post()` with `$post_mode = 'delete_topic'` (line 1388)

1. For shadow topics → decrements `forum_topics_approved` in forums with shadows
2. Calls `$phpbb_content_visibility->set_topic_visibility(ITEM_DELETED, ...)` (line 1470)
3. Inside `set_topic_visibility`:
   - Updates `topics` table: `topic_visibility = 2, topic_delete_user, topic_delete_time, topic_delete_reason`
   - Calls `set_post_visibility(ITEM_DELETED, false, $topic_id, ...)` for all **approved** posts in the topic (`$limit_visibility = ITEM_APPROVED`)
   - This preserves already-unapproved posts in their current state

### Soft-deleting first post (but topic has more posts)

`$post_mode = 'delete_first_post'` → This is treated as a **single post soft-delete** via `set_post_visibility`, with `$is_starter = true` flag. The `sync('topic', ...)` function is called to recalculate topic metadata (first poster name, time, etc).

---

## 5. Restore Flow

Restoring is accomplished by calling `set_post_visibility(ITEM_APPROVED, ...)` or `set_topic_visibility(ITEM_APPROVED, ...)`.

### Restoring a topic (`set_topic_visibility` with `ITEM_APPROVED`)

From `content_visibility.php:795-801`:

```php
if (!$force_update_all && $original_topic_data['topic_delete_time'] 
    && $original_topic_data['topic_visibility'] == ITEM_DELETED 
    && $visibility == ITEM_APPROVED)
{
    // Only restore posts that were soft-deleted at the same time as the topic
    $this->set_post_visibility($visibility, false, $topic_id, $forum_id, 
        $user_id, $time, '', true, true, 
        $original_topic_data['topic_visibility'],  // limit_visibility = ITEM_DELETED
        $original_topic_data['topic_delete_time']); // limit_delete_time
}
```

This ensures:
- Posts that were individually soft-deleted **before** the topic was soft-deleted remain soft-deleted
- Only posts whose `post_delete_time` matches the topic's `topic_delete_time` get restored

### Restoring a post

Via `set_post_visibility(ITEM_APPROVED, $post_id, ...)`:
- Changes `post_visibility` to 1
- Increments `user_posts`, `topic_posts_approved`, `forum_posts_approved`, `num_posts`
- Decrements `topic_posts_softdeleted`, `forum_posts_softdeleted`

---

## 6. Hard Delete Flow

**Entry point**: `delete_post()` with `$is_soft = false`

### Hard delete of a single post

1. Calls `delete_posts('post_id', array($post_id), ...)` — physically removes the row from the posts table
2. Calls `$phpbb_content_visibility->remove_post_from_statistic($data, $sql_data)` to decrement the appropriate counter based on the post's **current** visibility state
3. Updates `forum_last_post_*` info via `update_post_information()`
4. Checks TOPICS_POSTED tracking, cleans up if poster has no more posts in topic

### Hard delete of entire topic

1. Calls `delete_topics('topic_id', array($topic_id), false)` — removes topic and all its posts
2. Calls `$phpbb_content_visibility->remove_topic_from_statistic()` — decrements `forum_posts_*` and `forum_topics_*`
3. Decrements global `num_posts` by 1
4. For shadow topics: decrements `forum_topics_approved` in forums that held the shadow
5. `delete_topics()` / `delete_posts()` from `functions_admin.php` handles cascade: attachments, bookmarks, reports, notifications, search index, etc.

---

## 7. Approval Workflow

### When does a post need approval?

Determined in `submit_post()` (`functions_posting.php:1768-1798`):

```php
$post_visibility = ITEM_APPROVED;

// If user lacks f_noapprove permission
if (!$auth->acl_get('f_noapprove', $data_ary['forum_id']))
{
    $post_visibility = ITEM_UNAPPROVED;  // New post
    
    // Editing an existing post → ITEM_REAPPROVE
    switch ($post_mode) {
        case 'edit_first_post':
        case 'edit':
        case 'edit_last_post':
        case 'edit_topic':
            $post_visibility = ITEM_REAPPROVE;
            break;
    }
}
```

Key permission: **`f_noapprove`** — if user HAS this, posts go directly to ITEM_APPROVED.

ITEM_REAPPROVE (value 3) is specifically for **edited** posts that need re-approval. It's treated identically to ITEM_UNAPPROVED in visibility queries and counter columns (`posts_unapproved` counts both).

### Approval process (moderator)

**File**: `src/phpbb/common/mcp/mcp_queue.php`

#### `approve_posts()` (line 698)
1. Groups posts by topic
2. Determines if first/last post of topic is affected
3. Calls `$phpbb_content_visibility->set_post_visibility(ITEM_APPROVED, $topic_data['posts'], $topic_id, ...)` per topic
4. Logs the action
5. Optionally notifies the poster
6. Sends notifications (quote, post notifications)

#### `approve_topics()` (line 961)
1. Calls `$phpbb_content_visibility->set_topic_visibility(ITEM_APPROVED, $topic_id, ...)` for each topic
2. This cascades to all posts in the topic
3. Logs and notifies

#### `disapprove_posts()` (line 1167)
Disapproval = **hard delete** of unapproved posts:
1. If all posts in a topic are unapproved → deletes the entire topic
2. Calls `delete_posts('post_id', ...)` to physically remove
3. Optionally notifies poster with disapproval reason
4. Cleans up notifications (`post_in_queue`, `topic_in_queue`)

**Important**: Disapproval deletes the actual content. It is NOT a visibility change — it's destruction.

---

## 8. First Post Special Handling

The first post of a topic gets special treatment throughout:

### In `delete_post()` (functions_posting.php)

When `$post_mode = 'delete_first_post'` (line 1393):
- After removing/soft-deleting the post, queries for the **next approved** post to become the new first post
- If no approved post found, takes the earliest post regardless of visibility
- Updates topic metadata: `topic_poster`, `topic_first_post_id`, `topic_first_poster_colour`, `topic_first_poster_name`, `topic_time`

### In `set_post_visibility()` (content_visibility.php)

When `$is_starter = true` (line 534):
- Calls `sync('topic', 'topic_id', $topic_id, true)` which does a full topic resync
- This recalculates **all** topic metadata and forum counters recursively
- Sets `$update_topic_postcount = false` since sync handles it

When `$is_starter = false` and `$is_latest = true` (line 524):
- Only calls `update_post_information('topic', ...)` and `update_post_information('forum', ...)`

### In `submit_post()` edit flow

When editing the first post and its visibility changes (line 2312-2321):
- Sets `$is_starter = true`, `$is_latest` varies
- Calls `set_post_visibility()` which triggers the full sync

### Topic visibility follows first post

When the only post in a topic changes visibility (or when the first post determines the topic's visibility), `set_topic_visibility()` is called to match. For example:
- `submit_post()` line 2306-2308: `$first_post_has_topic_info` logic checks if this is a sole post or first post whose visibility completely determines the topic state

---

## 9. Key Design Patterns Summary

1. **Visibility is column-based**: `post_visibility` / `topic_visibility` integers in their respective tables
2. **Three-counter system**: `*_approved`, `*_unapproved`, `*_softdeleted` on both topics and forums tables
3. **Soft-delete preserves data**: Row stays, only `post_visibility` changes to 2
4. **Timestamp-based restore**: `post_delete_time` enables restoring only the posts that were soft-deleted as part of a topic soft-delete
5. **Permission-gated SQL**: `get_visibility_sql()` transparently shows/hides content based on `m_approve`
6. **ITEM_REAPPROVE shares unapproved counter**: Both ITEM_UNAPPROVED (0) and ITEM_REAPPROVE (3) map to `posts_unapproved` column
7. **Disapproval = hard delete**: Unapproved content that is rejected is physically removed, not soft-deleted

---

## 10. DB Columns Reference

### posts table
| Column | Type | Description |
|--------|------|-------------|
| `post_visibility` | int | 0=unapproved, 1=approved, 2=deleted, 3=reapprove |
| `post_delete_time` | int | Unix timestamp of soft-delete |
| `post_delete_reason` | varchar(255) | Soft-delete reason |
| `post_delete_user` | int | User ID who performed the delete |
| `post_postcount` | int | Whether this post counts toward user_posts |

### topics table
| Column | Type | Description |
|--------|------|-------------|
| `topic_visibility` | int | Same constants as post_visibility |
| `topic_delete_time` | int | Unix timestamp of topic soft-delete |
| `topic_delete_reason` | varchar(255) | Soft-delete reason |
| `topic_delete_user` | int | User ID who performed the delete |
| `topic_posts_approved` | int | Count of approved posts |
| `topic_posts_unapproved` | int | Count of unapproved + reapprove posts |
| `topic_posts_softdeleted` | int | Count of soft-deleted posts |

### forums table
| Column | Type | Description |
|--------|------|-------------|
| `forum_posts_approved` | int | Approved posts count |
| `forum_posts_unapproved` | int | Unapproved posts count |
| `forum_posts_softdeleted` | int | Soft-deleted posts count |
| `forum_topics_approved` | int | Approved topics count |
| `forum_topics_unapproved` | int | Unapproved topics count |
| `forum_topics_softdeleted` | int | Soft-deleted topics count |
