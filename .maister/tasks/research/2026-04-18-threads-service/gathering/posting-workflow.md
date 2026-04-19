# Posting Workflow — Comprehensive Analysis

## Source Files

| File | Lines | Purpose |
|------|-------|---------|
| `web/posting.php` | 2123 | Entry point, mode routing, form handling, validation, submit orchestration |
| `src/phpbb/common/functions_posting.php` | 3009 | `submit_post()`, `delete_post()`, `update_post_information()`, `phpbb_handle_post_delete()`, `phpbb_bump_topic()` |
| `src/phpbb/common/message_parser.php` | — | BBCode/message parsing (`parse_message` class) |
| `src/phpbb/common/constants.php:91-94` | — | Visibility constants |

---

## 1. Posting Modes

Modes are set via `$request->variable('mode', '')` in `web/posting.php:42`.

| Mode | URL param | Required ID | Purpose |
|------|-----------|-------------|---------|
| `post` | `mode=post&f=X` | `forum_id` | Create new topic |
| `reply` | `mode=reply&t=X` | `topic_id` | Reply to topic |
| `quote` | `mode=quote&p=X` | `post_id` | Reply with quoted post |
| `edit` | `mode=edit&p=X` | `post_id` | Edit existing post |
| `delete` | `mode=delete&p=X` | `post_id` | Hard-delete post (may fallback to soft_delete) |
| `soft_delete` | `mode=soft_delete&p=X` | `post_id` | Soft-delete post |
| `bump` | `mode=bump&t=X` | `topic_id` | Bump topic to top |
| `smilies` | `mode=smilies&f=X` | `forum_id` | Smiley popup window |
| `popup` | `mode=popup&f=X` | `forum_id` | Upload progress popup |

**Delete-mode fallback** (`web/posting.php:113-116`):
```php
if ($mode == 'delete' && (($confirm && !$request->is_set_post('delete_permanent')) 
    || !$auth->acl_gets('f_delete', 'm_delete', $forum_id)))
{
    $mode = 'soft_delete';
}
```

---

## 2. Visibility Constants

Defined in `src/phpbb/common/constants.php:91-94`:

| Constant | Value | Meaning |
|----------|-------|---------|
| `ITEM_UNAPPROVED` | 0 | Pending moderation |
| `ITEM_APPROVED` | 1 | Visible to all |
| `ITEM_DELETED` | 2 | Soft-deleted |
| `ITEM_REAPPROVE` | 3 | Edited post needing re-approval |

---

## 3. Entry Point Flow (`web/posting.php`)

### 3.1 Parameter Collection (lines 1–42)
```
session_begin() → acl() → parse request variables (draft_id, preview, save, load, confirm, cancel, refresh, submit, mode)
```

### 3.2 ID Resolution (lines 44–112)
- **post mode**: `forum_id` from `f` param
- **reply/bump**: `topic_id` from `t` param → query `TOPICS_TABLE` for `forum_id`
- **edit/delete/quote/soft_delete**: `post_id` from `p` param → join `TOPICS_TABLE` + `POSTS_TABLE` for `topic_id`, `forum_id`

### 3.3 Event: `core.modify_posting_parameters` (line 177)
Allows extensions to alter `post_id`, `topic_id`, `forum_id`, `draft_id`, `submit`, `preview`, `save`, `load`, `cancel`, `refresh`, `mode`, `error`.

### 3.4 Data Loading (lines 190–260)
Based on mode, queries different data:
- **post**: `SELECT * FROM FORUMS_TABLE`
- **reply/bump**: `SELECT f.*, t.* FROM TOPICS_TABLE t, FORUMS_TABLE f` (with visibility check)
- **edit/delete/quote/soft_delete**: `SELECT f.*, t.*, p.*, u.* FROM POSTS, TOPICS, FORUMS, USERS` (with visibility check)

### 3.5 Authorization Checks (lines 303–400)

Permission checks per mode (`web/posting.php:330–378`):

| Mode | Permission | Extra conditions |
|------|------------|------------------|
| `post` | `f_post` | — |
| `bump` | `f_bump` | — |
| `reply`/`quote` | `f_reply` | — |
| `edit` | `f_edit` or `m_edit` | Must be registered |
| `delete` | `m_delete` or (`f_delete` + own post) | Must be registered |
| `soft_delete` | via `content_visibility->can_soft_delete()` | Fallback from delete |

**Event: `core.modify_posting_auth`** (line 416) — allows extensions to override auth.

Additional checks (`web/posting.php:455–510`):
- **Forum type**: Must be `FORUM_POST` for post/reply/quote/bump
- **Forum/Topic locked**: Checked against `ITEM_LOCKED`, moderators can bypass via `m_lock`/`m_edit`
- **Edit time limit**: `$config['edit_time']` enforced for non-moderators
- **Edit lock**: `post_edit_locked` flag respected
- **Event: `core.posting_modify_cannot_edit_conditions`** allows overriding edit restrictions

### 3.6 Delete & Bump Handling (lines 520–555)
- **Delete/Soft-delete**: Delegates to `phpbb_handle_post_delete()` and returns early
- **Bump**: Validates `bump_topic_allowed()`, checks link hash, calls `phpbb_bump_topic()`, returns early

### 3.7 Form Processing on Submit/Preview/Refresh (lines 750+)
1. Collects form fields: subject, message, username, topic_type, bbcode flags, poll data, notify, lock flags
2. **Post review check** (reply/quote): If `topic_cur_post_id != topic_last_post_id`, shows new posts since user started composing
3. **Attachment parsing**: `$message_parser->parse_attachments()`
4. **Event: `core.posting_modify_message_text`**
5. **MD5 checksum**: Detects if message changed during edit
6. **Message parsing**: `$message_parser->parse()` — BBCode, smilies, URLs
7. **Flood check**: `$config['flood_interval']` for non-exempt users
8. **CAPTCHA**: For guests when `enable_post_confirm` is on
9. **CSRF check**: `check_form_key('posting')` on submit/preview
10. **Subject validation**: Empty subject check for new topics
11. **Poll parsing**: `$message_parser->parse_poll()`
12. **Topic type auth**: Validates sticky/announce/global permissions
13. **DNSBL check**: `$user->check_dnsbl('post')`
14. **Event: `core.posting_modify_submission_errors`**

### 3.8 Submit Execution (lines 1435–1620)

Guarded by **posting lock** (`$phpbb_container->get('posting.lock')`) to prevent double-submits:

1. **Lock/Unlock topic**: If user has `m_lock` or `f_user_lock` permission
2. **Lock/Unlock post edit**: If editing with `m_edit` permission
3. **Build `$data` array** — the primary data structure passed to `submit_post()`
4. **Event: `core.posting_modify_submit_post_before`**
5. **Call `submit_post()`** — returns redirect URL
6. **Event: `core.posting_modify_submit_post_after`**
7. **Handle inline delete**: If delete/delete_permanent is also posted during edit
8. **Moderation queue message**: If post needs approval
9. **Redirect** to topic/post

---

## 4. The `$data` Array Passed to `submit_post()`

Built in `web/posting.php:1491–1537`:

```php
$data = array(
    'topic_title'           => string,      // topic title (from post_subject if empty)
    'topic_first_post_id'   => int,
    'topic_last_post_id'    => int,
    'topic_time_limit'      => int,         // sticky/announce time limit
    'topic_attachment'      => int,         // 0 or 1
    'post_id'               => int,
    'topic_id'              => int,
    'forum_id'              => int,
    'icon_id'               => int,
    'poster_id'             => int,
    'enable_sig'            => bool,
    'enable_bbcode'         => bool,
    'enable_smilies'        => bool,
    'enable_urls'           => bool,
    'enable_indexing'        => bool,
    'message_md5'           => string,
    'post_checksum'         => string,
    'post_edit_reason'      => string,
    'post_edit_user'        => int,
    'forum_parents'         => mixed,
    'forum_name'            => string,
    'notify'                => bool,
    'notify_set'            => int,
    'poster_ip'             => string,
    'post_edit_locked'      => int,
    'bbcode_bitfield'       => string,
    'bbcode_uid'            => string,
    'message'               => string,      // parsed message text
    'attachment_data'       => array,
    'filename_data'         => array,
    'topic_status'          => int,
    'topic_visibility'      => int|false,
    'post_visibility'       => int|false,
    // Only for edit mode:
    'topic_posts_approved'      => int,
    'topic_posts_unapproved'    => int,
    'topic_posts_softdeleted'   => int,
);
```

---

## 5. `submit_post()` Flow

Located in `src/phpbb/common/functions_posting.php:1668–2710`.

**Signature**:
```php
function submit_post($mode, $subject, $username, $topic_type, &$poll_ary, &$data_ary, 
                     $update_message = true, $update_search_index = true)
```

### 5.1 Pre-processing (lines 1668–1730)

1. **Event: `core.modify_submit_post_data`** — modify all input data before processing
2. Returns `false` if `$mode == 'delete'` (delete is handled separately)
3. Sets `$current_time` from `$data_ary['post_time']` or `time()`

### 5.2 Post Mode Determination (lines 1732–1745)

Maps user-facing modes to internal `$post_mode`:

| User mode | Internal `$post_mode` |
|-----------|-----------------------|
| `post` | `post` |
| `reply`, `quote` | `reply` |
| `edit` (only post in topic) | `edit_topic` |
| `edit` (first post) | `edit_first_post` |
| `edit` (last post) | `edit_last_post` |
| `edit` (middle post) | `edit` |

### 5.3 Visibility Determination (lines 1770–1800)

```
Default: ITEM_APPROVED
If NOT f_noapprove → ITEM_UNAPPROVED (new posts) or ITEM_REAPPROVE (edits)
Overridable by: data_ary['force_approved_state'] or data_ary['force_visibility']
```

### 5.4 DB Transaction Begin (line 1802)
```php
$db->sql_transaction('begin');
```

### 5.5 SQL Data Collection — Posts Table (lines 1805–1900)

**For post/reply** — builds INSERT array:
```php
$sql_data[POSTS_TABLE]['sql'] = array(
    'forum_id', 'poster_id', 'icon_id', 'poster_ip', 'post_time',
    'post_visibility', 'enable_bbcode', 'enable_smilies', 'enable_magic_url',
    'enable_sig', 'post_username', 'post_subject', 'post_text',
    'post_checksum', 'post_attachment', 'bbcode_bitfield', 'bbcode_uid',
    'post_postcount', 'post_edit_locked'
);
```

**For edit modes** — builds UPDATE array:
- Conditionally updates `post_edit_time`, `post_edit_reason`, `post_edit_user`, increments `post_edit_count`
- Edit info displayed if: reason given OR (not moderator AND not last post)
- Moderator edits of other users' posts are logged via `$phpbb_log->add('mod', ...)`
- Always updates: `forum_id`, `poster_id`, `icon_id`, bbcode flags, subject, checksum, attachment flag, bbcode fields
- Conditionally updates `post_text` if `$update_message` is true

### 5.6 SQL Data Collection — Topics Table (lines 1907–2040)

**For `post` mode** — builds INSERT array for new topic:
```php
$sql_data[TOPICS_TABLE]['sql'] = array(
    'topic_poster', 'topic_time', 'topic_last_view_time', 'forum_id',
    'icon_id', 'topic_posts_approved/unapproved/softdeleted',
    'topic_visibility', 'topic_delete_user', 'topic_title',
    'topic_first_poster_name', 'topic_first_poster_colour',
    'topic_type', 'topic_time_limit', 'topic_attachment', 'topic_status'
);
```
Plus poll fields if poll options present.

**For `reply` mode** — stat updates (no SQL array, only stat[] increments)

**For `edit_topic`/`edit_first_post`** — updates topic metadata, poll fields

### 5.7 Counter Updates (stat arrays)

**Post mode:**
- `USERS_TABLE`: `user_lastpost_time`, conditionally `user_posts + 1` (if `f_postcount` and approved)
- `FORUMS_TABLE`: `forum_topics_approved/unapproved/softdeleted + 1`, `forum_posts_approved/unapproved/softdeleted + 1`

**Reply mode:**
- `TOPICS_TABLE`: `topic_last_view_time`, reset bump, `topic_posts_approved/unapproved/softdeleted + 1`, `topic_attachment`
- `USERS_TABLE`: `user_lastpost_time`, conditionally `user_posts + 1`
- `FORUMS_TABLE`: `forum_posts_approved/unapproved/softdeleted + 1`

### 5.8 Event: `core.submit_post_modify_sql_data` (line 2060)
Extensions can modify `$sql_data` before execution.

### 5.9 DB Execution Order (lines 2075–2160)

1. **INSERT TOPICS_TABLE** (post mode only) → get `topic_id` via `$db->sql_nextid()`
2. **INSERT POSTS_TABLE** (post/reply) → get `post_id` via `$db->sql_nextid()`
3. Set `topic_last_post_*` fields on Topics table
4. For post mode: set `topic_first_post_id`
5. If approved: increment `$config['num_topics']` (post), `$config['num_posts']`, update `FORUMS_TABLE` last post info
6. **UPDATE TOPICS_TABLE** (if sql set)
7. **UPDATE POSTS_TABLE** (if sql set, for edits)

### 5.10 Poll Handling (lines 2165–2230)

- For edits: loads existing `POLL_OPTIONS_TABLE`, compares with new options
- Inserts new options, updates changed options, deletes excess options
- If option count changed during edit: resets all votes (`DELETE FROM POLL_VOTES_TABLE`, reset `poll_option_total`)

### 5.11 Attachment Processing (lines 2235–2310)

- Validates orphan attachments (owned by current user, `is_orphan = 1`)
- Updates existing attachments (comment only)
- Converts orphan attachments: sets `post_msg_id`, `topic_id`, `is_orphan = 0`
- Updates `$config['upload_dir_size']` and `$config['num_files']`

### 5.12 Visibility Fix for Edits (lines 2315–2385)

- If post visibility changed (e.g., edited approved post by non-moderator → ITEM_REAPPROVE), calls `$phpbb_content_visibility->set_post_visibility()`
- Updates forum last post subject if this is the latest forum post

### 5.13 Stat Updates Execution (lines 2390–2400)

Iterates `$sql_data`, executes UPDATE statements for the `stat[]` arrays:
```php
foreach ($sql_data as $table => $update_ary) {
    if (isset($update_ary['stat']) && implode('', $update_ary['stat'])) {
        $sql = "UPDATE $table SET " . implode(', ', $update_ary['stat']) . ' WHERE ' . $where_sql[$table];
    }
}
```

Where clauses:
- `POSTS_TABLE`: `post_id = X`
- `TOPICS_TABLE`: `topic_id = X`
- `FORUMS_TABLE`: `forum_id = X`
- `USERS_TABLE`: `user_id = X`

### 5.14 Transaction Commit (line 2410)
```php
$db->sql_transaction('commit');
```

### 5.15 Post-Commit Operations (lines 2412–2510)

1. **Delete loaded draft**: If `draft_loaded` was set
2. **Search index**: Calls `$search->index()` if `$update_search_index` and `enable_indexing`
3. **Topic watch**: Insert/delete `TOPICS_WATCH_TABLE` based on notify preference
4. **Mark as read**: `markread('post', ...)` and `markread('topic', ...)`
5. **Forum tracking**: Update `FORUMS_TRACK_TABLE`

### 5.16 Notifications (lines 2520–2640)

**Approved posts:**
- `post` mode: `notification.type.quote`, `notification.type.topic`
- `reply`/`quote` mode: `notification.type.quote`, `notification.type.bookmark`, `notification.type.post`, `notification.type.forum`
- Edit modes (own post): update `notification.type.quote`; always update `bookmark`, `topic`, `post`, `forum`

**Unapproved posts:**
- `post`: `notification.type.topic_in_queue`
- `reply`/`quote`: `notification.type.post_in_queue`

**Reapprove:**
- Edit first/topic: `notification.type.topic_in_queue` + delete `approve_post` notification
- Edit/last: `notification.type.post_in_queue` + delete `approve_post` notification

### 5.17 Event: `core.submit_post_end` (line 2695)
Final event, allows modifying `$url` (return URL).

### 5.18 Return URL Construction (lines 2645–2690)

Returns URL to the new/edited post or topic, considering visibility:
- If visible (or moderator can see): `viewtopic.php?p=X#pX`
- If new topic: `viewtopic.php?t=X`
- Otherwise: `viewforum.php?f=X`

---

## 6. Tables Touched by `submit_post()`

| Table | Operation | When |
|-------|-----------|------|
| `TOPICS_TABLE` | INSERT | `post` mode (new topic) |
| `TOPICS_TABLE` | UPDATE | Always (last post info, stats, poll) |
| `POSTS_TABLE` | INSERT | `post`/`reply` modes |
| `POSTS_TABLE` | UPDATE | `edit` modes |
| `FORUMS_TABLE` | UPDATE | Always (post counts, last post info) |
| `USERS_TABLE` | UPDATE | Always (lastpost_time, post count) |
| `POLL_OPTIONS_TABLE` | INSERT/UPDATE/DELETE | If poll options present |
| `POLL_VOTES_TABLE` | DELETE | If poll options changed during edit |
| `ATTACHMENTS_TABLE` | UPDATE | If attachments present |
| `DRAFTS_TABLE` | DELETE | If draft was loaded |
| `TOPICS_WATCH_TABLE` | INSERT/DELETE | Based on notify preference |
| `FORUMS_TRACK_TABLE` | SELECT | For tracking info update |
| Config table | UPDATE | `num_topics`, `num_posts`, `upload_dir_size`, `num_files` |
| Search index | custom | Via search backend `->index()` |

---

## 7. `delete_post()` Flow

Located in `src/phpbb/common/functions_posting.php:1423–1660`.

**Signature**:
```php
function delete_post($forum_id, $topic_id, $post_id, &$data, $is_soft = false, $softdelete_reason = '')
```

### 7.1 Post Mode Determination (lines 1440–1450)

| Condition | `$post_mode` |
|-----------|--------------|
| Only post in topic | `delete_topic` |
| First post | `delete_first_post` |
| Last post | `delete_last_post` |
| Middle post | `delete` |

### 7.2 Transaction & Core Operations (lines 1455–1490)

```php
$db->sql_transaction('begin');
```

**Soft delete** (non-topic):
- `$phpbb_content_visibility->set_post_visibility(ITEM_DELETED, ...)`

**Hard delete** (non-soft):
- `delete_posts('post_id', [$post_id], false, false, false)` — from `functions_admin.php`

```php
$db->sql_transaction('commit');
```

### 7.3 Post-Delete Updates by Mode

**`delete_topic`:**
- Update shadow topic forum counters
- Soft: `set_topic_visibility(ITEM_DELETED, ...)`
- Hard: `delete_topics()`, `remove_topic_from_statistic()`, decrement `num_posts`, `update_post_information('forum', ...)`

**`delete_first_post`:**
- Queries next approved post (or any post) to become new first post
- Updates `topic_poster`, `topic_first_post_id`, `topic_first_poster_*`, `topic_time`

**`delete_last_post`:**
- Hard delete: `update_post_information('forum', ...)` and `update_post_information('topic', ...)`
- Resets topic bump fields

**`delete` (middle):**
- Finds next post after deleted post's time

### 7.4 Counter Updates for Non-Topic Deletes
- Hard delete: `remove_post_from_statistic()` updates forum/topic counters
- Topic attachment flag reset if no more attachments
- User posted-in tracking: If user has no more posts in topic, delete from `TOPICS_POSTED_TABLE`
- Sync reported flag: `sync('topic_reported', ...)`

### 7.5 Event: `core.delete_post_after` (line 1645)

---

## 8. Soft Delete vs Hard Delete

| Aspect | Soft Delete | Hard Delete |
|--------|-------------|-------------|
| **Post data** | Kept, visibility → `ITEM_DELETED` | Row removed from `POSTS_TABLE` |
| **Topic** (if only post) | `topic_visibility` → `ITEM_DELETED` | Topic row deleted |
| **Counters** | Shifted: approved−1, softdeleted+1 | Decremented absolutely |
| **Attachments** | Kept | Removed by `delete_posts()` |
| **Search index** | Kept | Removed |
| **Permissions** | `f_softdelete` / `m_softdelete` | `f_delete` / `m_delete` |
| **Reversible?** | Yes (restore to approved) | No |

Handled by `phpbb_handle_post_delete()` in `functions_posting.php:2870–3009`:
- Shows confirmation dialog with permanent delete checkbox
- Checks: moderator or (own post + last post + not edit-locked + within delete_time)
- Calls `delete_post()` with `$is_soft` flag
- Logs: `LOG_SOFTDELETE_TOPIC/POST` or `LOG_DELETE_TOPIC/POST`

---

## 9. `phpbb_bump_topic()` Flow

Located in `src/phpbb/common/functions_posting.php:2730–2830`.

Inside a transaction:
1. UPDATE `POSTS_TABLE`: set `post_time = $bump_time` on last post
2. UPDATE `TOPICS_TABLE`: set `topic_last_post_time`, `topic_bumped = 1`, `topic_bumper = user_id`
3. UPDATE `FORUMS_TABLE`: update all `forum_last_post_*` fields
4. UPDATE `USERS_TABLE`: set `user_lastpost_time` (flood prevention)
5. COMMIT
6. Mark as read + update forum tracking
7. Log: `LOG_BUMP_TOPIC`

---

## 10. Event Hooks (phpBB Events)

### In `web/posting.php`:

| Event | Line | Phase |
|-------|------|-------|
| `core.modify_posting_parameters` | 177 | After param parsing, before any logic |
| `core.posting_modify_row_data` | 275 | After loading post/topic/forum data |
| `core.modify_posting_auth` | 416 | After permission checks |
| `core.posting_modify_cannot_edit_conditions` | 500 | Edit restriction override |
| `core.posting_modify_post_data` | 648 | Before message parsing setup |
| `core.posting_modify_default_variables` | 695 | Default variable override |
| `core.posting_modify_bbcode_status` | 780 | BBCode feature flags |
| `core.posting_modify_message_text` | 1060 | Before message MD5/parse |
| `core.posting_modify_submission_errors` | 1415 | Before submit execution |
| `core.posting_modify_submit_post_before` | 1570 | Just before `submit_post()` call |
| `core.posting_modify_submit_post_after` | 1600 | After `submit_post()` returns |
| `core.posting_modify_quote_attributes` | 1785 | Quote formatting |
| `core.posting_modify_post_subject` | 1810 | Reply subject |
| `core.posting_modify_template_vars` | 2060 | Template variables |

### In `submit_post()`:

| Event | ~Line | Phase |
|-------|-------|-------|
| `core.modify_submit_post_data` | 1695 | Input data modification |
| `core.submit_post_modify_sql_data` | 2060 | SQL data before execution |
| `core.modify_submit_notification_data` | 2530 | Notification data |
| `core.submit_post_end` | 2695 | After everything, URL modification |

### In `delete_post()`:

| Event | ~Line | Phase |
|-------|-------|-------|
| `core.delete_post_after` | 1645 | After delete completes |

### In `phpbb_handle_post_delete()`:

| Event | ~Line | Phase |
|-------|-------|-------|
| `core.handle_post_delete_conditions` | 2900 | Override delete permission checks |

---

## 11. Transaction Handling

**`submit_post()`**: Single transaction wrapping the core data operations:
- `$db->sql_transaction('begin')` at line 1802
- `$db->sql_transaction('commit')` at line 2410
- Post-commit: search indexing, notifications, watch updates (non-transactional)

**`delete_post()`**: Two transactions:
1. First transaction: soft/hard delete the post itself (lines 1455–1490)
2. Second transaction: update counters, topic metadata, posted tracking (later in function)

**`phpbb_bump_topic()`**: Single transaction wrapping all 4 UPDATE statements.

**Posting lock** (`web/posting.php:1438`): `$phpbb_container->get('posting.lock')` using `creation_time` + `form_token` to prevent double-submit race conditions.

---

## 12. State Transitions

```
[User composes] → [Submit]
                      │
                      ├─ f_noapprove? ──YES──→ ITEM_APPROVED (visible)
                      │                           │
                      └─ NO ──→ ITEM_UNAPPROVED (in queue)
                                    │
                                    ├─ Moderator approves → ITEM_APPROVED
                                    └─ Moderator rejects → deleted
                      
[ITEM_APPROVED] ──edit by non-mod without f_noapprove──→ ITEM_REAPPROVE
                ──edit by mod/with f_noapprove──→ stays ITEM_APPROVED

[Any state] ──soft_delete──→ ITEM_DELETED (reversible)
            ──hard_delete──→ row removed (irreversible)

[ITEM_DELETED] ──restore (m_approve)──→ ITEM_APPROVED
```

Draft flow:
```
[Save Draft] → DRAFTS_TABLE (INSERT)
[Load Draft] → Read from DRAFTS_TABLE → populate form
[Submit with draft_loaded] → DRAFTS_TABLE (DELETE) after successful submit
```

---

## 13. `update_post_information()` Function

Located at `src/phpbb/common/functions_posting.php:260–380`.

**Purpose**: Efficiently update last-post metadata for forums or topics without full `sync()`.

**Signature**:
```php
function update_post_information($type, $ids, $return_update_sql = false)
// $type = 'forum' | 'topic'
```

**Flow**:
1. Query `POSTS_TABLE` for `MAX(post_id)` where `post_visibility = ITEM_APPROVED`
2. For forums: also join `TOPICS_TABLE` where `topic_visibility = ITEM_APPROVED`
3. Handle empty forums (zero out all last_post fields)
4. For non-empty: query post+user data for last post IDs
5. Build UPDATE SQL: `{type}_last_post_id`, `{type}_last_post_subject`, `{type}_last_post_time`, `{type}_last_poster_id`, `{type}_last_poster_name`, `{type}_last_poster_colour`
6. Execute or return SQL arrays

**Events**:
- `core.update_post_info_modify_posts_sql` — modify query for last post data
- `core.update_post_info_modify_sql` — modify update SQL arrays

---

## 14. Key Observations for API Design

1. **`submit_post()` is monolithic** (~1000 lines) — handles post/reply/edit with mode-based branching. The function itself has a `@todo Split up` comment.

2. **Counter management is manual** — every mode carefully increments/decrements `forum_posts_*`, `topic_posts_*`, `user_posts`. No abstraction layer; relies on `$phpbb_content_visibility` for some operations.

3. **Two-phase pattern**: Transaction covers core data; post-commit handles search index, notifications, read tracking. This means search/notifications can fail without rolling back the post.

4. **Visibility is the primary state field** — not a separate status column. The same `post_visibility` / `topic_visibility` columns control approval workflow, soft-delete, and normal visibility.

5. **No ORM** — all SQL is hand-crafted using `$db->sql_build_array()` and string concatenation. The `$sql_data` associative array pattern groups INSERTs (`sql` key) and counter UPDATEs (`stat` key) by table.

6. **Posting lock** prevents double-submits using a time+token hash, separate from CSRF (`check_form_key`).

7. **Content visibility service** (`\phpbb\content_visibility`) encapsulates approval/soft-delete counter logic but is called separately from `submit_post()` for edits that change visibility.
