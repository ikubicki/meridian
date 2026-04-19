# Topic Display & Pagination Logic — Comprehensive Findings

## 1. viewtopic.php Flow (Step-by-Step)

**Source**: `web/viewtopic.php` (2425 lines)

### 1.1 URL Parameters & Initialization (Lines 1–50)

```php
$topic_id   = $request->variable('t', 0);       // topic ID
$post_id    = $request->variable('p', 0);        // direct post ID link
$voted_id   = $request->variable('vote_id', array('' => 0));
$start      = $request->variable('start', 0);    // pagination offset
$view       = $request->variable('view', '');     // 'unread', 'next', 'previous', 'print', 'viewpoll'
$hilit_words = $request->variable('hilit', '', true); // search highlight
```

Sort defaults come from user preferences:
```php
$default_sort_days = $user->data['user_post_show_days'] ?: 0;
$default_sort_key  = $user->data['user_post_sortby_type'] ?: 't';  // 't' = post_time
$default_sort_dir  = $user->data['user_post_sortby_dir'] ?: 'a';   // 'a' = ascending
$sort_days = $request->variable('st', $default_sort_days);
$sort_key  = $request->variable('sk', $default_sort_key);
$sort_dir  = $request->variable('sd', $default_sort_dir);
```

### 1.2 Special View Handling (Lines 65–175)

**`view=unread`**: Finds the first unread post in the topic:
1. Queries `TOPICS_TABLE` for `forum_id`
2. Gets topic tracking info via `get_complete_topic_tracking()`
3. Queries `POSTS_TABLE` for first post with `post_time > $topic_last_read` (ordered ASC)
4. Falls back to `topic_last_post_id` if no unread found
5. Sets `$post_id` to redirect to that post

**`view=next` / `view=previous`**: Finds adjacent topics:
1. Gets current topic's `topic_last_post_time`
2. Queries next/previous topic by `topic_last_post_time` comparison
3. Redirects to that topic

### 1.3 Topic Metadata Query (Lines 183–265)

The main topic query joins forums, topics, and optionally posts (if `$post_id` set):

```php
$sql_array = array(
    'SELECT' => 't.*, f.*',
    'FROM'   => array(FORUMS_TABLE => 'f'),
);
if ($post_id) {
    $sql_array['SELECT'] .= ', p.post_visibility, p.post_time, p.post_id';
    $sql_array['FROM'][POSTS_TABLE] = 'p';
}
$sql_array['FROM'][TOPICS_TABLE] = 't';
```

**LEFT JOINs for registered users**:
- `TOPICS_WATCH_TABLE` → `tw.notify_status` (subscription status)
- `BOOKMARKS_TABLE` → `bm.topic_id as bookmarked` (if bookmarks enabled)
- `TOPICS_TRACK_TABLE` → `tt.mark_time` (topic read tracking)
- `FORUMS_TRACK_TABLE` → `ft.mark_time as forum_mark_time` (forum read tracking)

**WHERE clause**:
- Without post_id: `t.topic_id = $topic_id`
- With post_id: `p.post_id = $post_id AND t.topic_id = p.topic_id`
- Always: `f.forum_id = t.forum_id`

Result stored in `$topic_data` — contains ALL columns from topics and forums tables merged.

### 1.4 Post Position Calculation for Direct Post Links (Lines 295–340)

When `$post_id` is given, calculates which page the post is on:

```php
// First/last post optimization:
if ($post_id == $topic_data['topic_first_post_id'] || $post_id == $topic_data['topic_last_post_id']) {
    // Direct calculation without query
} else {
    // COUNT(p.post_id) WHERE post_time < target post_time
    $sql = 'SELECT COUNT(p.post_id) AS prev_posts FROM ' . POSTS_TABLE . " p
        WHERE p.topic_id = {$topic_data['topic_id']}
        AND " . $phpbb_content_visibility->get_visibility_sql('post', $forum_id, 'p.');
}
```

Start offset: `$start = floor(($topic_data['prev_posts']) / $config['posts_per_page']) * $config['posts_per_page']);`

### 1.5 Read Tracking (Lines 420–440)

Two mechanisms depending on config:
1. **Database tracking** (`load_db_lastread` + registered user): Uses `get_topic_tracking()` with data already JOINed
2. **Cookie tracking** (`load_anon_lastread` or registered): Uses `get_complete_topic_tracking()` which reads from cookie or DB

### 1.6 Sort Options (Lines 442–470)

```php
$sort_by_text = array(
    'a' => 'AUTHOR',
    't' => 'POST_TIME',
    's' => 'SUBJECT'
);
$sort_by_sql = array(
    'a' => array('u.username_clean', 'p.post_id'),
    't' => array('p.post_time', 'p.post_id'),
    's' => array('p.post_subject', 'p.post_id')
);
$join_user_sql = array('a' => true, 't' => false, 's' => false);
```

If `$sort_days` is set, posts are filtered by `post_time >= time() - ($sort_days * 86400)`.

### 1.7 Highlight Handling (Lines 515–525)

```php
if ($hilit_words) {
    $highlight_match = phpbb_clean_search_string($hilit_words);
    $highlight = urlencode($highlight_match);
    $highlight_match = str_replace('\*', '\w+?', preg_quote($highlight_match, '#'));
    $highlight_match = str_replace(' ', '|', $highlight_match);
}
```

Applied later during post rendering (line ~1780):
```php
$message = preg_replace('#(?!<.*)(?<!\w)(' . $highlight_match . ')(?!\w|[^<>]*(?:</s(?:cript|tyle))?>)#is',
    '<span class="posthilit">\1</span>', $message);
```

### 1.8 Topic Actions (Quick Mod) (Lines 625–700)

Available quick moderation actions from topic view:
- `lock` / `unlock` — lock/unlock topic
- `delete_topic` — delete or soft-delete
- `restore_topic` — restore soft-deleted
- `move` — move to another forum
- `split` — split topic
- `merge` / `merge_topic` — merge posts/topics
- `fork` — fork (copy) topic
- `make_normal` / `make_sticky` / `make_announce` / `make_global` — change topic type
- `topic_logs` — view topic moderation logs

All actions POST to `mcp.php` with `quickmod=1`.

### 1.9 Template Variables for Topic (Lines 780–860)

Key template variables assigned:
```
FORUM_ID, FORUM_NAME, TOPIC_ID, TOPIC_TITLE, TOPIC_POSTER
TOPIC_AUTHOR_FULL, TOTAL_POSTS
S_IS_LOCKED, S_VIEWTOPIC, S_UNREAD_VIEW
U_VIEW_TOPIC, U_VIEW_FORUM, U_CANONICAL
U_VIEW_OLDER_TOPIC, U_VIEW_NEWER_TOPIC
U_PRINT_TOPIC, U_EMAIL_TOPIC
U_WATCH_TOPIC, U_BOOKMARK_TOPIC
U_POST_NEW_TOPIC, U_POST_REPLY_TOPIC, U_BUMP_TOPIC
S_DISPLAY_POST_INFO, S_DISPLAY_REPLY_INFO
```

---

## 2. Post Query — The Exact SQL

**Source**: `web/viewtopic.php` Lines 1216–1330

### 2.1 Step 1: Fetch Post IDs (paginated)

```php
$sql = 'SELECT p.post_id
    FROM ' . POSTS_TABLE . ' p' . (($join_user_sql[$sort_key]) ? ', ' . USERS_TABLE . ' u' : '') . "
    WHERE p.topic_id = $topic_id
        AND " . $phpbb_content_visibility->get_visibility_sql('post', $forum_id, 'p.') . "
        " . (($join_user_sql[$sort_key]) ? 'AND u.user_id = p.poster_id' : '') . "
        $limit_posts_time
    ORDER BY $sql_sort_order";
$result = $db->sql_query_limit($sql, $sql_limit, $sql_start);
```

This is a two-step query pattern:
1. First query: get just post IDs for the current page (with `sql_query_limit`)
2. Second query: fetch full post + user data for those IDs

### 2.2 Step 2: Fetch Full Post + User Data

```php
$sql_ary = array(
    'SELECT' => 'u.*, z.friend, z.foe, p.*',
    'FROM'   => array(
        USERS_TABLE => 'u',
        POSTS_TABLE => 'p',
    ),
    'LEFT_JOIN' => array(
        array(
            'FROM' => array(ZEBRA_TABLE => 'z'),
            'ON'   => 'z.user_id = ' . $user->data['user_id'] . ' AND z.zebra_id = p.poster_id',
        ),
    ),
    'WHERE' => $db->sql_in_set('p.post_id', $post_list) . ' AND u.user_id = p.poster_id',
);
```

**Tables involved**:
- `POSTS_TABLE` (p) — post content, metadata
- `USERS_TABLE` (u) — poster info (all user columns)
- `ZEBRA_TABLE` (z) — friend/foe relationship (LEFT JOIN)

### 2.3 Reverse Optimization (Lines 1195–1215)

If user is past the midpoint of total posts, queries are reversed for efficiency:
```php
if ($start > $total_posts / 2) {
    $store_reverse = true;
    $direction = ($sort_dir == 'd') ? 'ASC' : 'DESC';
    $sql_limit = $pagination->reverse_limit($start, $sql_limit, $total_posts);
    $sql_start = $pagination->reverse_start($start, $sql_limit, $total_posts);
}
```

---

## 3. Pagination System

**Source**: `src/phpbb/forums/pagination.php` (namespace `phpbb\pagination`)

### 3.1 Config Setting

`$config['posts_per_page']` — number of posts per page in topic view.
`$config['topics_per_page']` — number of topics per page in forum view.

Forums can override: `$forum_data['forum_topics_per_page']` (viewforum.php line ~173).

### 3.2 Pagination Service

Container service: `$phpbb_container->get('pagination')`

Key methods:
- `validate_start($start, $per_page, $total)` — clamps start to valid range
- `generate_template_pagination($base_url, $block_var_name, $start_name, $num_items, $per_page, $start)` — generates template pagination data
- `get_on_page($per_page, $start)` — returns current page number (1-based)
- `reverse_limit()` / `reverse_start()` — optimization for late-page queries

### 3.3 URL Format

Pagination uses `start` parameter:
- Page 1: `viewtopic.php?t=123`
- Page 2: `viewtopic.php?t=123&start=10` (with 10 posts/page)
- Page 3: `viewtopic.php?t=123&start=20`

Built URL: `append_sid("{$phpbb_root_path}viewtopic.php", "t=$topic_id" . (($start == 0) ? '' : "&start=$start"))`

### 3.4 Template Pagination Generation (pagination.php Lines 137–200)

Displays up to 5 page numbers centered on current page, with ellipsis for longer ranges:
```php
$start_page = ($total_pages > 5) ? min(max(1, $on_page - 2), $total_pages - 4) : 1;
$end_page = ($total_pages > 5) ? max(min($total_pages, $on_page + 2), 5) : $total_pages;
```

Template block vars per page item: `PAGE_NUMBER`, `PAGE_URL`, `S_IS_CURRENT`, `S_IS_PREV`, `S_IS_NEXT`, `S_IS_ELLIPSIS`.

---

## 4. Post Ordering

**Source**: `web/viewtopic.php` Lines 442–465

Default: **Chronological ascending** (`sort_key='t'`, `sort_dir='a'`).

Available sort options:
| Key | Label | SQL |
|-----|-------|-----|
| `a` | Author | `u.username_clean, p.post_id` |
| `t` | Post Time | `p.post_time, p.post_id` |
| `s` | Subject | `p.post_subject, p.post_id` |

Direction: `a` = ascending, `d` = descending.

Users can set their default in UCP preferences (`user_post_sortby_type`, `user_post_sortby_dir`).

**Time filter**: Posts can be filtered to show only last N days (0=all, 1, 7, 14, 30, 90, 180, 365).

---

## 5. Template Variables Per Post

**Source**: `web/viewtopic.php` Lines 2030–2100

Each post gets these template variables in the `postrow` block:

### Author info:
```
POST_AUTHOR_FULL, POST_AUTHOR_COLOUR, POST_AUTHOR, U_POST_AUTHOR
RANK_TITLE, RANK_IMG, RANK_IMG_SRC
POSTER_JOINED, POSTER_POSTS, POSTER_AVATAR, POSTER_WARNINGS, POSTER_AGE
CONTACT_USER
```

### Post content:
```
POST_DATE, POST_DATE_RFC3339, POST_SUBJECT, MESSAGE, SIGNATURE
EDITED_MESSAGE     — "Last edited by X on Y, edited Z times total"
EDIT_REASON        — edit reason text
DELETED_MESSAGE    — soft-delete info
DELETE_REASON
BUMPED_MESSAGE
```

### Post metadata:
```
POST_ID, POST_NUMBER (sequential: $i + $start + 1), POSTER_ID
MINI_POST_IMG      — unread/read indicator per-post
POST_ICON_IMG, POST_ICON_IMG_WIDTH, POST_ICON_IMG_HEIGHT, POST_ICON_IMG_ALT
ONLINE_IMG, S_ONLINE
```

### Action URLs:
```
U_EDIT     — posting.php?mode=edit&p={post_id}
U_QUOTE    — posting.php?mode=quote&p={post_id}
U_INFO     — mcp.php (post details)
U_DELETE   — posting.php?mode=soft_delete|delete&p={post_id}
U_REPORT   — phpbb_report_post_controller route
U_APPROVE_ACTION — mcp.php queue
U_MCP_REPORT, U_MCP_APPROVE, U_MCP_RESTORE
U_MINI_POST — viewtopic.php?p={post_id}#p{post_id}
U_SEARCH, U_PM, U_EMAIL, U_JABBER
U_NOTES (mod notes), U_WARN (warn user)
```

### State flags:
```
S_HAS_ATTACHMENTS, S_MULTIPLE_ATTACHMENTS
S_POST_UNAPPROVED, S_CAN_APPROVE, S_POST_DELETED, S_POST_REPORTED
S_DISPLAY_NOTICE
S_FRIEND, S_UNREAD_POST, S_FIRST_UNREAD
S_CUSTOM_FIELDS, S_TOPIC_POSTER, S_FIRST_POST
S_IGNORE_POST (foe), S_POST_HIDDEN
S_DELETE_PERMANENT
```

### User cache (built once per unique poster):
```
user_type, joined, posts, warnings
sig, sig_bbcode_uid, sig_bbcode_bitfield
online, avatar, age
rank_title, rank_image, rank_image_src
username, user_colour
author_full, author_colour, author_username, author_profile
contact_user, jabber, search, email, pm
```

---

## 6. Read Tracking

**Source**: `src/phpbb/common/functions.php` Lines 953–1100

### 6.1 Two Tracking Mechanisms

**Database tracking** (`config['load_db_lastread']` + registered user):
- `phpbb_topics_track` table: per-topic mark_time per user
- `phpbb_forums_track` table: per-forum mark_time per user
- `users.user_lastmark`: global fallback timestamp
- Function: `get_topic_tracking()` — uses data already JOINed in the main query

**Cookie tracking** (anonymous or `config['load_anon_lastread']`):
- Cookie: `{cookie_name}_track` — serialized base-36 encoded data
- Structure: `{'t' => {topic_id36 => time36}, 'f' => {forum_id => time36}, 'l' => global_time36}`
- Function: `get_complete_topic_tracking()` — reads cookie, queries DB if registered

### 6.2 Unread Detection

Per-topic (viewforum): `$unread_topic = $row['topic_last_post_time'] > $topic_tracking_info[$topic_id]`

Per-post (viewtopic): `$post_unread = $row['post_time'] > $topic_tracking_info[$topic_id]`

First unread: tracked via `$first_unread` flag, sets `S_FIRST_UNREAD` on the first unread post.

### 6.3 Mark-Read Logic (viewtopic.php Lines 2310–2350)

After displaying posts:
```php
if ($topic_data['topic_last_post_time'] > $topic_tracking_info[$topic_id]
    && $max_post_time > $topic_tracking_info[$topic_id]) {
    markread('topic', $forum_id, $topic_id, $max_post_time);
    $all_marked_read = update_forum_tracking_info(...);
}
```

---

## 7. Quick Reply

**Source**: `web/viewtopic.php` Lines 2360–2420

### Conditions for quick reply:
```php
$s_quick_reply = $user->data['is_registered']
    && $config['allow_quick_reply']
    && ($topic_data['forum_flags'] & FORUM_FLAG_QUICK_REPLY)
    && $auth->acl_get('f_reply', $forum_id)
    && (($topic_data['forum_status'] == ITEM_UNLOCKED
         && $topic_data['topic_status'] == ITEM_UNLOCKED)
        || $auth->acl_get('m_edit', $forum_id));
```

Quick reply POSTs to: `posting.php?mode=reply&t=$topic_id`

Hidden fields:
```php
$qr_hidden_fields = array(
    'topic_cur_post_id' => (int) $topic_data['topic_last_post_id'],
    'topic_id'          => (int) $topic_data['topic_id'],
    'forum_id'          => (int) $forum_id,
);
```

Additional flags: `disable_bbcode`, `disable_smilies`, `disable_magic_url`, `attach_sig`, `notify`, `lock_topic` — set based on user preferences and permissions.

Template vars: `S_QUICK_REPLY`, `U_QR_ACTION`, `QR_HIDDEN_FIELDS`, `SUBJECT` (prefixed with "Re: ").

CSRF: `add_form_key('posting')` is called when quick reply or poll voting is active.

---

## 8. Post Edit History

**Source**: `web/viewtopic.php` Lines 1795–1855

### Tracking

Posts track edits via columns in `POSTS_TABLE`:
- `post_edit_count` — number of times edited
- `post_edit_time` — timestamp of last edit
- `post_edit_reason` — reason text for last edit
- `post_edit_user` — user_id who last edited
- `post_edit_locked` — whether post is edit-locked (by moderator)

### Display

Controlled by `$config['display_last_edited']`:
```php
if (($row['post_edit_count'] && $config['display_last_edited']) || $row['post_edit_reason']) {
    $l_edited_by = $user->lang('EDITED_TIMES_TOTAL', (int) $row['post_edit_count'],
        $display_username, $user->format_date($row['post_edit_time'], false, true));
}
```

Template var: `EDITED_MESSAGE` — e.g. "Last edited by User on Jan 1, 2024, edited 3 times in total."
Template var: `EDIT_REASON` — the edit reason text.

**No full edit history** — only the last edit is tracked. No revision diffs.

---

## 9. Post Actions Available from Topic View

**Source**: `web/viewtopic.php` Lines 1960–2050

Per-post actions (permission-dependent):

| Action | URL | Permission |
|--------|-----|------------|
| Edit | `posting.php?mode=edit&p={id}` | `f_edit` or `m_edit` + time/lock checks |
| Quote | `posting.php?mode=quote&p={id}` | `f_reply` or `m_edit`, post must be approved |
| Delete | `posting.php?mode=soft_delete\|delete&p={id}` | `f_delete/f_softdelete` or `m_delete/m_softdelete` |
| Report | `phpbb_report_post_controller` route | `f_report` |
| Info | `mcp.php?i=main&mode=post_details&p={id}` | `m_info` |
| PM | `ucp.php?i=pm&mode=compose&action=quotepost&p={id}` | `u_sendpm` + recipient can receive PM |
| Warn | `mcp.php?i=warn&mode=warn_post&u={id}` | `m_warn` |
| Approve | `mcp.php?i=queue&p={id}` | `m_approve` |

Edit time limit: `$config['edit_time']` (minutes, 0 = unlimited).
Delete time limit: `$config['delete_time']` (minutes, 0 = unlimited).
Locked posts (`post_edit_locked`) cannot be edited/deleted by non-moderators.

---

## 10. Poll Support

**Source**: `web/viewtopic.php` Lines 870–1190

Polls are loaded when `$topic_data['poll_start']` is non-zero:

```php
$sql = 'SELECT o.*, p.bbcode_bitfield, p.bbcode_uid
    FROM ' . POLL_OPTIONS_TABLE . ' o, ' . POSTS_TABLE . " p
    WHERE o.topic_id = $topic_id
        AND p.post_id = {$topic_data['topic_first_post_id']}
    ORDER BY o.poll_option_id";
```

User votes loaded from `POLL_VOTES_TABLE` (registered) or cookie (anonymous).

Template data per poll option:
```
POLL_OPTION_ID, POLL_OPTION_CAPTION, POLL_OPTION_RESULT
POLL_OPTION_PERCENT, POLL_OPTION_PERCENT_REL
POLL_OPTION_PCT, POLL_OPTION_WIDTH, POLL_OPTION_VOTED, POLL_OPTION_MOST_VOTES
```

Vote submission validates CSRF with `check_form_key('posting')`.

---

## 11. Attachments

**Source**: `web/viewtopic.php` Lines 1635–1720

Attachments loaded separately after posts, for posts with `post_attachment=1`:
```php
$sql = 'SELECT * FROM ' . ATTACHMENTS_TABLE . '
    WHERE ' . $db->sql_in_set('post_msg_id', $attach_list) . '
        AND in_message = 0
    ORDER BY attach_id DESC, post_msg_id ASC';
```

Requires permissions: `u_download` + `f_download` for the forum.

---

## 12. Viewforum: Topic Listing

**Source**: `web/viewforum.php` (1101 lines)

### 12.1 Forum Data Query (Lines 45–75)

```php
$sql_ary = [
    'SELECT' => 'f.*',
    'FROM'   => [FORUMS_TABLE => 'f'],
    'WHERE'  => 'f.forum_id = ' . $forum_id,
];
// LEFT JOINs for registered users:
// - FORUMS_TRACK_TABLE (ft) → ft.mark_time
// - FORUMS_WATCH_TABLE (fw) → fw.notify_status
```

### 12.2 Topics Per Page

```php
if ($forum_data['forum_topics_per_page']) {
    $config['topics_per_page'] = $forum_data['forum_topics_per_page'];
}
$topics_count = $phpbb_content_visibility->get_count('forum_topics', $forum_data, $forum_id);
```

### 12.3 Topic Sort Options (Lines 300–320)

```php
$sort_by_text = array(
    'a' => 'AUTHOR',              // t.topic_first_poster_name
    't' => 'POST_TIME',           // t.topic_last_post_time, t.topic_last_post_id
    'r' => 'REPLIES',             // t.topic_posts_approved (+ unapproved+softdeleted for mods)
    's' => 'SUBJECT',             // LOWER(t.topic_title)
    'v' => 'VIEWS'                // t.topic_views
);
```

Default sort: by last post time descending (`sort_key='t'`, `sort_dir='d'`).

### 12.4 Topic IDs Query (Lines 680–720)

Two-step query pattern (same as viewtopic):

**Step 1 — Get topic IDs** (paginated):
```php
$sql_ary = array(
    'SELECT'   => 't.topic_id',
    'FROM'     => array(TOPICS_TABLE => 't'),
    'WHERE'    => "$sql_where
        AND t.topic_type IN (" . POST_NORMAL . ', ' . POST_STICKY . ")
        $sql_approved $sql_limit_time",
    'ORDER_BY' => 't.topic_type ' . ((!$store_reverse) ? 'DESC' : 'ASC') . ', ' . $sql_sort_order,
);
$result = $db->sql_query_limit($sql, $sql_limit, $sql_start);
```

Sticky topics always sort first (by `topic_type DESC`).

**Step 2 — Bulk fetch full topic data**:
```php
$sql_array = array(
    'SELECT'    => $sql_array['SELECT'],  // t.* + LEFT JOIN fields
    'FROM'      => array(TOPICS_TABLE => 't'),
    'LEFT_JOIN' => [...],
    'WHERE'     => $db->sql_in_set('t.topic_id', $topic_list),
);
```

LEFT JOINs for registered users:
- `TOPICS_POSTED_TABLE` (tp) → `tp.topic_posted` (has user posted here?)
- `TOPICS_TRACK_TABLE` (tt) → `tt.mark_time` (topic read tracking)
- `FORUMS_TRACK_TABLE` (ft) → `ft.mark_time` (forum read tracking, for global announces)

### 12.5 Announcements Query (Lines 540–600)

Global and forum announcements fetched separately:
```php
$sql_ary = array(
    'WHERE' => '(t.forum_id = ' . $forum_id . ' AND t.topic_type = ' . POST_ANNOUNCE . ')
        OR (' . $db->sql_in_set('t.forum_id', $g_forum_ary) . ' AND t.topic_type = ' . POST_GLOBAL . ')',
    'ORDER_BY' => 't.topic_time DESC',
);
```

Announcements are prepended to the topic list.

### 12.6 Shadow Topics (Moved Topics) (Lines 740–810)

Shadow topics (ITEM_MOVED status) get their data replaced from the actual moved-to topic:
```php
$sql_array = array(
    'SELECT' => 't.*',
    'FROM'   => array(TOPICS_TABLE => 't'),
    'WHERE'  => $db->sql_in_set('t.topic_id', array_keys($shadow_topic_list)),
);
```

### 12.7 Template Variables Per Topic (Lines 920–1020)

Each topic row in the `topicrow` template block:

```
FORUM_ID, TOPIC_ID
TOPIC_AUTHOR, TOPIC_AUTHOR_COLOUR, TOPIC_AUTHOR_FULL
FIRST_POST_TIME, FIRST_POST_TIME_RFC3339
LAST_POST_SUBJECT, LAST_POST_TIME, LAST_POST_TIME_RFC3339
LAST_VIEW_TIME, LAST_VIEW_TIME_RFC3339
LAST_POST_AUTHOR, LAST_POST_AUTHOR_COLOUR, LAST_POST_AUTHOR_FULL
REPLIES, VIEWS, TOPIC_TITLE, TOPIC_TYPE, FORUM_NAME
TOPIC_IMG_STYLE, TOPIC_FOLDER_IMG, TOPIC_FOLDER_IMG_ALT
TOPIC_ICON_IMG, ATTACH_ICON_IMG, UNAPPROVED_IMG
S_TOPIC_TYPE (numeric), S_USER_POSTED, S_UNREAD_TOPIC
S_TOPIC_REPORTED, S_TOPIC_UNAPPROVED, S_POSTS_UNAPPROVED
S_TOPIC_DELETED, S_HAS_POLL
S_POST_ANNOUNCE, S_POST_GLOBAL, S_POST_STICKY
S_TOPIC_LOCKED, S_TOPIC_MOVED
U_NEWEST_POST, U_LAST_POST, U_LAST_POST_AUTHOR
U_TOPIC_AUTHOR, U_VIEW_TOPIC, U_VIEW_FORUM
U_MCP_REPORT, U_MCP_QUEUE
S_TOPIC_TYPE_SWITCH (for template section separation)
```

Per-topic pagination (mini-pagination in topic row):
```php
$pagination->generate_template_pagination(
    $topic_row['U_VIEW_TOPIC'], 'topicrow.pagination', 'start',
    (int) $topic_row['REPLIES'] + 1, $config['posts_per_page'], 1, true, true
);
```

### 12.8 Pagination for Topic List

```php
$base_url = append_sid("{$phpbb_root_path}viewforum.php", "f=$forum_id" . ((strlen($u_sort_param)) ? "&$u_sort_param" : ''));
$pagination->generate_template_pagination($base_url, 'pagination', 'start', $total_topic_count, $config['topics_per_page'], $start);
```

URL format: `viewforum.php?f=2&start=25`

### 12.9 Reverse Optimization (viewforum.php Lines 650–680)

Same pattern as viewtopic — if past midpoint, query from the end:
```php
if ($start > $topics_count / 2) {
    $store_reverse = true;
    $direction = ($sort_dir == 'd') ? 'ASC' : 'DESC';
    $sql_limit = $pagination->reverse_limit($start, $sql_limit, $topics_count - count($announcement_list));
    $sql_start = $pagination->reverse_start($start, $sql_limit, $topics_count - count($announcement_list));
}
```

---

## 13. Topic Status Icons (functions_display.php)

**Source**: `src/phpbb/common/functions_display.php` Lines 1030–1103

`topic_status()` function determines folder image based on:
- `topic_status` (ITEM_MOVED → `topic_moved`)
- `topic_type` (POST_GLOBAL → `global_*`, POST_ANNOUNCE → `announce_*`, POST_STICKY → `sticky_*`, default → `topic_*`)
- Hot threshold: `$config['hot_threshold']` — if replies+1 >= threshold, adds `_hot`
- Locked: adds `_locked`
- Unread: uses `_unread` variant
- User posted: adds `_mine`
- Poll: overrides type text to `VIEW_TOPIC_POLL`

---

## 14. View Counter

**Source**: `web/viewtopic.php` Lines 2290–2305

Topic views are incremented on first page view per session:
```php
if (!$user->data['is_bot'] && (strpos($user->data['session_page'], '&t=' . $topic_id) === false
    || isset($user->data['session_created']))) {
    $sql = 'UPDATE ' . TOPICS_TABLE . '
        SET topic_views = topic_views + 1, topic_last_view_time = ' . time() . "
        WHERE topic_id = $topic_id";
}
```

---

## 15. Content Visibility

**Source**: Throughout viewtopic.php and viewforum.php

`\phpbb\content_visibility` service handles approved/unapproved/soft-deleted content:
- `get_visibility_sql('post', $forum_id)` — SQL WHERE clause for visible posts
- `get_visibility_sql('topic', $forum_id, 't.')` — SQL WHERE clause for visible topics
- `get_count('topic_posts', $topic_data, $forum_id)` — gets visible post count (mods see all)
- `is_visible('topic', $forum_id, $row)` — checks if specific topic is visible

Moderators with `m_approve` see unapproved + soft-deleted content.

---

## 16. Event Hooks Summary

Both files are heavily event-driven. Key extension points:

**viewtopic.php**:
- `core.viewtopic_modify_forum_id` — modify forum_id after topic load
- `core.viewtopic_before_f_read_check` — override read permission
- `core.viewtopic_gen_sort_selects_before` — add sort options
- `core.viewtopic_get_post_data` — modify post query
- `core.viewtopic_post_rowset_data` — modify per-post data
- `core.viewtopic_cache_user_data` / `core.viewtopic_cache_guest_data` — modify user cache
- `core.viewtopic_modify_post_row` — modify post template data
- `core.viewtopic_modify_post_action_conditions` — override edit/delete permissions
- `core.viewtopic_modify_quick_reply_template_vars` — modify quick reply

**viewforum.php**:
- `core.viewforum_modify_sql` — modify forum query
- `core.viewforum_get_topic_data` — modify topic listing query
- `core.viewforum_modify_topicrow` — modify topic row template data
- `core.viewforum_get_topic_ids_data` — modify topic IDs query
