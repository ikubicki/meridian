# Forum Tracking & Subscription (Watch) — Findings

## Research Question
How does forum read tracking and subscription (watch) work? What's the data model and logic?

---

## 1. Read Tracking — `markread()` Function

**Source**: [src/phpbb/common/functions.php](src/phpbb/common/functions.php#L553-L780)

### Signature
```php
function markread($mode, $forum_id = false, $topic_id = false, $post_time = 0, $user_id = 0)
```

### Modes

#### `$mode == 'all'` — Mark All Forums Read (Index Page)
- **Triggered by**: `display_forums()` when `$mark_read == 'all'` (from `index.php?mark=forums`)
- **Notification clearing**: Marks ALL notification types read: `topic`, `quote`, `bookmark`, `post`, `approve_topic`, `approve_post`, `forum`
- **DB tracking** (`load_db_lastread` + registered user):
  - **DELETEs** all rows from `phpbb_topics_track` and `phpbb_forums_track` WHERE `user_id = X AND mark_time < $post_time`
  - **UPDATEs** `phpbb_users.user_lastmark = $post_time` — this becomes the global baseline
- **Cookie tracking** (anonymous / when db tracking off):
  - Clears tracked topics (`tf`, `t`, `f` keys) from cookie
  - Sets `l` (lastmark) key in cookie to base36-encoded offset from `board_startdate`
  - Updates `user_lastmark` in DB if registered

#### `$mode == 'topics'` — Mark All Topics in Specific Forum(s) Read
- **Triggered by**: `viewforum.php?mark=topics` and `display_forums()` when `$mark_read == 'forums'`
- **Input**: `$forum_id` can be an array of forum IDs
- **Notification clearing**: Marks `topic`/`approve_topic` by parent (forum_id), then fetches all topic_ids in those forums and marks `quote`/`bookmark`/`post`/`approve_post`/`forum` notifications by parent (topic_id)
- **DB tracking**:
  - **DELETEs** from `phpbb_topics_track` WHERE `user_id AND mark_time < $post_time AND forum_id IN (...)`
  - **SELECTs** existing `phpbb_forums_track` rows for those forums
  - **UPDATEs** existing rows: `SET mark_time = $post_time`
  - **INSERTs** new rows for forums not yet tracked: `(user_id, forum_id, mark_time)`
- **Cookie tracking**: Clears per-topic entries for the forum from cookie, sets `f[$forum_id]` to the mark time

#### `$mode == 'topic'` — Mark Single Topic Read
- **Triggered by**: `viewtopic.php`, `submit_post()` after posting
- **DB tracking**: UPDATE or INSERT into `phpbb_topics_track` with `(user_id, topic_id, forum_id, mark_time)`
- **Cookie tracking**: Sets `t[$topic_id36]` to base36 time offset; adds `tf[$forum_id][$topic_id36]` mapping; has overflow protection at 10000 chars

#### `$mode == 'post'` — Mark Topic as Posted-To
- **Triggered by**: `submit_post()` after posting
- Inserts into `phpbb_topics_posted` table (tracks which topics a user has posted in — for bold-topic display)

### Events
- `core.markread_before` — can prevent marking by setting `$should_markread = false`
- `core.markread_after` — post-marking hook

---

## 2. `update_forum_tracking_info()` — Auto-Mark Forum Read

**Source**: [src/phpbb/common/functions.php](src/phpbb/common/functions.php#L1268-L1380)

### Signature
```php
function update_forum_tracking_info($forum_id, $forum_last_post_time, $f_mark_time = false, $mark_time_forum = false)
```

### Purpose
After a topic is read/posted, this function checks if ALL topics in the forum are now read. If yes, it auto-marks the entire forum as read via `markread('topics', $forum_id)`.

### Logic
1. **Determine `$mark_time_forum`** — the user's current forum mark time:
   - DB: from `phpbb_forums_track.mark_time` or fallback to `user_lastmark`
   - Cookie: from `tracking_topics['f'][$forum_id]` or `user_lastmark`

2. **Early exit**: If `$mark_time_forum >= $forum_last_post_time` → forum already marked read, return

3. **Check for unread topics**:
   - DB: `SELECT t.forum_id FROM topics LEFT JOIN topics_track WHERE topic_last_post_time > $mark_time_forum AND (tt.topic_id IS NULL OR tt.mark_time < t.topic_last_post_time)` — limited to 1 row
   - Cookie: iterates topics checking if tracked in `tf[$forum_id]`

4. **Auto-mark**: If no unread topics found (`!$row`), calls `markread('topics', $forum_id)` and returns `true`

### Callers
- `submit_post()` in [src/phpbb/common/functions_posting.php](src/phpbb/common/functions_posting.php#L2480) — after creating/editing a post
- `bump_topic()` in [src/phpbb/common/functions_posting.php](src/phpbb/common/functions_posting.php#L2795) — after bumping a topic

---

## 3. `phpbb_forums_track` Table — Data Model

**Source**: [phpbb_dump.sql](phpbb_dump.sql#L1797-L1804)

### Schema
```sql
CREATE TABLE phpbb_forums_track (
  user_id    int(10) unsigned NOT NULL DEFAULT 0,
  forum_id   mediumint(8) unsigned NOT NULL DEFAULT 0,
  mark_time  int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (user_id, forum_id)
);
```

### Semantics
- **One row per (user, forum)** — composite PK
- `mark_time` = UNIX timestamp of when user last marked this forum as read
- **No row** means the user has never explicitly marked this forum; falls back to `users.user_lastmark`

### Operations

| Operation | When | SQL |
|-----------|------|-----|
| **INSERT** | `markread('topics', ...)` for forums not yet in table | `INSERT INTO forums_track (user_id, forum_id, mark_time)` |
| **UPDATE** | `markread('topics', ...)` for forums already in table | `UPDATE forums_track SET mark_time = X WHERE user_id AND forum_id` |
| **DELETE** | `markread('all', ...)` — global mark read | `DELETE FROM forums_track WHERE user_id = X AND mark_time < Y` |
| **DELETE** | User deletion (`user_delete()`) | Deletes all rows for user |
| **DELETE** | Forum deletion (ACP) | Deletes rows for forum |
| **SELECT** | `display_forums()` — LEFT JOIN to get `ft.mark_time` | Joined per forum per user |
| **SELECT** | `get_complete_topic_tracking()` — fallback for topics without individual marks | `SELECT mark_time FROM forums_track WHERE user_id AND forum_id` |
| **SELECT** | `update_forum_tracking_info()` — check current mark | `SELECT mark_time FROM forums_track WHERE ...` |
| **SELECT** | `submit_post()` / `bump_topic()` — read current mark before `update_forum_tracking_info()` | `SELECT mark_time FROM forums_track ...` |

### Global "Mark All Read"
When `markread('all')` is called:
1. **DELETE** all `forums_track` rows for user where `mark_time < $post_time`
2. **DELETE** all `topics_track` rows for user where `mark_time < $post_time`  
3. **UPDATE** `users.user_lastmark = $post_time` — this becomes the new baseline; anything before this time is "read"

---

## 4. `phpbb_forums_watch` Table — Subscription Data Model

**Source**: [phpbb_dump.sql](phpbb_dump.sql#L1821-L1830)

### Schema
```sql
CREATE TABLE phpbb_forums_watch (
  forum_id      mediumint(8) unsigned NOT NULL DEFAULT 0,
  user_id       int(10) unsigned NOT NULL DEFAULT 0,
  notify_status tinyint(1) unsigned NOT NULL DEFAULT 0,
  KEY forum_id (forum_id),
  KEY user_id (user_id),
  KEY notify_stat (notify_status)
);
```

### Semantics
- **No composite PK** — indexed by `forum_id`, `user_id`, and `notify_status` separately
- `notify_status`: `NOTIFY_YES = 0` (ready to receive notifications), `NOTIFY_NO = 1` (notification already sent, waiting for user to read)

### Constants
- `NOTIFY_YES = 0` — [src/phpbb/common/constants.php](src/phpbb/common/constants.php#L130)
- `NOTIFY_NO = 1` — [src/phpbb/common/constants.php](src/phpbb/common/constants.php#L131)

### Operations

| Operation | Where | Details |
|-----------|-------|---------|
| **INSERT** | `watch_topic_or_forum()` — via `?watch=forum` GET param | `INSERT INTO forums_watch (user_id, forum_id, notify_status) VALUES (X, Y, NOTIFY_YES)` |
| **DELETE** | `watch_topic_or_forum()` — via `?unwatch=forum` GET param | `DELETE FROM forums_watch WHERE forum_id = X AND user_id = Y` |
| **DELETE** | UCP subscribed page — batch unwatch | `DELETE FROM forums_watch WHERE forum_id IN (...) AND user_id = X` |
| **UPDATE** | `watch_topic_or_forum()` — re-enable notification | `UPDATE forums_watch SET notify_status = NOTIFY_YES WHERE ...` — when user revisits and status was NOTIFY_NO |
| **SELECT** | Notification types — find recipients | `SELECT user_id FROM forums_watch WHERE forum_id = X AND notify_status = NOTIFY_YES AND user_id <> poster_id` |
| **DELETE** | User deletion | Deletes all rows for user |
| **DELETE** | Forum deletion (ACP) | Deletes rows for forum |

---

## 5. Watch/Subscribe UI Flow

**Source**: [src/phpbb/common/functions_display.php](src/phpbb/common/functions_display.php#L1335-L1530) — function at line 1335 (unnamed, part of display logic, called `watch_topic_or_forum` section)

### Subscribe Flow
1. User clicks "Watch Forum" link → `viewforum.php?uid=X&f=Y&watch=forum&hash=Z`
2. `watch_topic_or_forum()` function is called (part of `functions_display.php`)
3. Hash validation via `check_link_hash()` or `confirm_box()`
4. INSERT into `phpbb_forums_watch` with `notify_status = NOTIFY_YES`

### Unsubscribe Flow
1. User clicks "Stop Watching" → `viewforum.php?uid=X&f=Y&unwatch=forum&hash=Z`
2. Hash + UID validation
3. DELETE from `phpbb_forums_watch` for that user+forum

### Re-enable Notification
When user revisits and `notify_status == NOTIFY_NO`:
- Auto-UPDATE to `NOTIFY_YES` (makes them eligible for next notification)

### UCP Manage Subscriptions
**Source**: [src/phpbb/common/ucp/ucp_main.php](src/phpbb/common/ucp/ucp_main.php#L280-L400)
- Lists all watched forums from `forums_watch JOIN forums` with optional `forums_track` LEFT JOIN for unread detection
- Batch unwatch via POST with CSRF (`check_form_key('ucp_front_subscribed')`)

---

## 6. Notification Integration

### `notification.type.forum` — Post in Watched Forum
**Source**: [src/phpbb/forums/notification/type/forum.php](src/phpbb/forums/notification/type/forum.php)

- Extends `\phpbb\notification\type\post`
- `find_users_for_notification($post)`:
  - Queries `phpbb_forums_watch` WHERE `forum_id = X AND notify_status = NOTIFY_YES AND user_id <> poster_id`
  - Filters through `get_authorised_recipients()` (permission check)
  - Checks for already-notified-but-unread users — updates their existing notification (adds responder) instead of creating new one
- Email template: `forum_notify`
- Includes `U_STOP_WATCHING_FORUM` link in email

### `notification.type.topic` — New Topic in Watched Forum
**Source**: [src/phpbb/forums/notification/type/topic.php](src/phpbb/forums/notification/type/topic.php#L100-L140)

- `find_users_for_notification($topic)`:
  - Same query pattern: `SELECT user_id FROM forums_watch WHERE forum_id = X AND notify_status = NOTIFY_YES AND user_id <> poster_id`
  - Filters through `get_authorised_recipients()`

### Notification Lifecycle
1. User subscribes → `forums_watch.notify_status = NOTIFY_YES (0)`
2. New post/topic created → notification system queries `forums_watch` for eligible users
3. Notification sent → (presumably `notify_status` set to `NOTIFY_NO` to prevent spam — though this is handled by the notification manager's deduplication, not direct UPDATE in these files)
4. User reads the topic → notifications marked read
5. User revisits forum → `watch_topic_or_forum()` resets `notify_status = NOTIFY_YES`

---

## 7. Unread Detection Logic in `display_forums()`

**Source**: [src/phpbb/common/functions_display.php](src/phpbb/common/functions_display.php#L100-L530)

### Building `$forum_tracking_info`

During forum query:
1. **DB tracking** (registered users with `load_db_lastread`):
   - LEFT JOIN `phpbb_forums_track ft ON ft.user_id = X AND ft.forum_id = f.forum_id`
   - `$forum_tracking_info[$forum_id] = $row['mark_time'] ?: $user->data['user_lastmark']`

2. **Cookie tracking** (anonymous or when db tracking off):
   - Read cookie → `tracking_unserialize()`
   - `$forum_tracking_info[$forum_id] = tracking_topics['f'][$forum_id]` (base36-decoded + board_startdate) or `user_lastmark`

### Unread Check (line ~470)
```php
$forum_unread = (isset($forum_tracking_info[$forum_id]) 
    && $row['orig_forum_last_post_time'] > $forum_tracking_info[$forum_id]) 
    ? true : false;
```

**Logic**: A forum is **unread** if `forum_last_post_time > user's mark_time for that forum`.

### Subforum Propagation (line ~480-490)
```php
$subforum_unread = (isset($forum_tracking_info[$subforum_id]) 
    && $subforum_row['orig_forum_last_post_time'] > $forum_tracking_info[$subforum_id]);
```
- Checks each subforum individually
- Also checks children of subforums
- If ANY subforum or child is unread → parent forum is marked unread too (`$forum_unread = true`)

### Folder Image Selection
- `forum_unread` / `forum_read` — basic forum icons
- `forum_unread_subforum` / `forum_read_subforum` — when forum has subforums

---

## 8. "Mark Forum Read" User Action

### From viewforum.php
**Source**: [web/viewforum.php](web/viewforum.php#L226-L245)

```php
if ($mark_read == 'topics') {
    $token = $request->variable('hash', '');
    if (check_link_hash($token, 'global')) {
        markread('topics', array($forum_id), false, $request->variable('mark_time', 0));
    }
}
```
URL: `viewforum.php?f=X&mark=topics&hash=Z&mark_time=T`

### From index.php / display_forums()
**Source**: [src/phpbb/common/functions_display.php](src/phpbb/common/functions_display.php#L62-L95)

Two modes:
1. **`mark_read == 'all'`** (at index root) → calls `markread('all', ...)` 
2. **`mark_read == 'forums'`** (inside a category/parent forum) → calls `markread('topics', $forum_ids)` for all child forums collected during the loop

Both require hash validation (`check_link_hash($token, 'global')`).

---

## 9. Tracking Helper Functions

### `get_topic_tracking($forum_id, $topic_ids, &$rowset, $forum_mark_time)`
**Source**: [src/phpbb/common/functions.php](src/phpbb/common/functions.php) ~line 990+
- Uses already-fetched data from `$rowset` (topics with their `mark_time` from topics_track JOIN)
- For topics without individual marks, falls back to `$forum_mark_time[$forum_id]` then `user_lastmark`
- Returns `$last_read[topic_id] => timestamp`

### `get_complete_topic_tracking($forum_id, $topic_ids)`
**Source**: [src/phpbb/common/functions.php](src/phpbb/common/functions.php) ~line 1020+
- Full DB/cookie lookup (no pre-fetched data)
- DB: queries `topics_track` then falls back to `forums_track` then `user_lastmark`
- Cookie: reads tracking cookie, decodes base36 timestamps

---

## 10. Two-Tier Tracking System Summary

phpBB maintains a **three-level fallback** for read tracking:

```
Per-Topic Mark (topics_track / cookie 't')
    ↓ fallback if no topic-level mark
Per-Forum Mark (forums_track / cookie 'f')
    ↓ fallback if no forum-level mark
User Global Mark (users.user_lastmark / cookie 'l')
```

**Registered users**: DB tables (`topics_track`, `forums_track`, `users.user_lastmark`)
**Anonymous users**: Cookie-based tracking with base36-encoded timestamps + overflow protection at 10000 chars

### Key Design Points
- Forum mark time acts as a "floor" — anything posted before this time is read
- `update_forum_tracking_info()` auto-promotes: if all topics in a forum are individually read, the forum itself gets marked read (cleaning up topic-level entries)
- `markread('all')` is destructive: deletes ALL tracking rows and sets `user_lastmark` as new floor
- Cookie tracking has a 10,000 char limit; when exceeded, oldest entries are pruned and their timestamps promote to forum-level marks

---

## 11. Usage Points Across Codebase

| File | Usage |
|------|-------|
| `functions.php` | `markread()`, `get_topic_tracking()`, `get_complete_topic_tracking()`, `update_forum_tracking_info()` |
| `functions_display.php` | `display_forums()` — LEFT JOIN, unread detection, mark-read actions; `watch_topic_or_forum()` — subscribe/unsubscribe |
| `functions_posting.php` | `submit_post()` and `bump_topic()` — markread + update_forum_tracking_info after posting |
| `functions_user.php` | `user_delete()` — cleanup of both tables |
| `functions_mcp.php` | Moderator views — LEFT JOIN for unread detection |
| `ucp/ucp_main.php` | UCP subscribed page — manage watches, display unread watched forums/topics |
| `acp/acp_forums.php` | Forum move/delete — cleanup of both tables |
| `web/viewforum.php` | Mark topics read action |
| `forums/notification/type/forum.php` | Post notification — queries `forums_watch` |
| `forums/notification/type/topic.php` | Topic notification — queries `forums_watch` |
| `forums/db/migration/data/v30x/release_3_0_0.php` | Table creation migration |
