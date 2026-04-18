# Forum Display & Navigation — Findings

**Source Category**: forum-display  
**Research Question**: How is the forum hierarchy displayed? What's the rendering pipeline from DB to HTML?  
**Confidence**: High (100%) — direct code reading of all critical files

---

## 1. `display_forums()` Deep Dive

**Source**: [functions_display.php](src/phpbb/common/functions_display.php#L21-L738)

### 1.1 Signature & Parameters

```php
function display_forums($root_data = '', $display_moderators = true, $return_moderators = false)
```

- `$root_data` — Either `''` (root, forum_id=0) or a forum data array with `forum_id`, `left_id`, `right_id`
- `$display_moderators` — Whether to load and show moderators
- `$return_moderators` — If true, returns `[$active_forum_ary, $forum_moderators]`

### 1.2 SQL Query Construction

**Lines 100-156**: The main query selects `f.*` from `FORUMS_TABLE` with optional LEFT JOINs:

```php
$sql_ary = array(
    'SELECT'    => 'f.*',
    'FROM'      => array(FORUMS_TABLE => 'f'),
    'LEFT_JOIN' => [...],
    'WHERE'     => $sql_where,
    'ORDER_BY'  => 'f.left_id',   // <-- CRITICAL: nested set ordering
);
```

**WHERE clause logic**:
- If `$root_data` is empty/false → `$sql_where = ''` (all forums)
- If `$root_data` given → `'left_id > {left_id} AND left_id < {right_id}'` (subtree only)

**LEFT JOINs** (conditional):
- `FORUMS_TRACK_TABLE ft` — if `load_db_lastread && is_registered` → brings `ft.mark_time`
- `FORUMS_ACCESS_TABLE fa` — if `$show_active` → checks passworded forum access by session

**Event**: `core.display_forums_modify_sql` (since 3.1.0-a1) — allows extensions to modify `$sql_ary` before query

### 1.3 Tree Traversal (Nested Set: left_id/right_id)

The query orders by `f.left_id` which gives a depth-first pre-order traversal of the nested set tree. The code then processes rows sequentially:

**Skip logic** (Lines 195-220):
1. **Empty categories**: `forum_type == FORUM_CAT && (left_id + 1 == right_id)` → skip (no children)
2. **Permission-based branch skipping**: If `!auth->acl_get('f_list', forum_id)` → set `$right_id = row['right_id']` and skip all descendants until `left_id >= right_id`
3. **Subtree skip via `$right_id`**: Any row with `left_id < right_id` is skipped (child of a hidden forum)

### 1.4 Forum Type Handling (Categories vs Forums vs Links)

Three forum types distinguished by `forum_type` constant:

| Constant | Value | Behavior |
|----------|-------|----------|
| `FORUM_CAT` | Category | Gets `S_IS_CAT => true` template block, displays as category header |
| `FORUM_POST` | Regular forum | Full display with topics/posts counts, last post info |
| `FORUM_LINK` | External link | Gets `S_IS_LINK => true`, click tracking via `FORUM_FLAG_LINK_TRACK` |

**Direct children** (`parent_id == root_data['forum_id']` or `parent_id == branch_root_id`):
- Stored in `$forum_rows[$forum_id]`
- Categories set `$branch_root_id = forum_id`
- Non-categories added to `$forum_ids_moderator[]`

**Deeper descendants** (subforums):
- Stored in `$subforums[$parent_id][$forum_id]` with: `display`, `name`, `orig_forum_last_post_time`, `children[]`, `type`
- Their topic/post counts **aggregate upward** into `$forum_rows[$parent_id]`
- Last post info propagated upward if newer than parent's

### 1.5 Subforum Display Logic

**Lines 270-310**: Subforums bubble up stats to their direct parent:

```php
$subforums[$parent_id][$forum_id]['display'] = (
    $row['display_on_index'] && 
    (!$parent_subforum_limit || $parent_id == $row['parent_id'])
);
```

- `display_on_index` flag controls visibility
- `display_subforum_limit` on parent controls whether only direct children or all sub-levels show
- Post/topic counts from subforums **add to parent forum row**
- Last post info from subforums replaces parent if newer
- LINK forums' posts NOT counted in parent's post count

**Subforum rendering** (Lines 490-530):
- Subforums rendered as links inside parent row
- Unread state checked per subforum AND its children (transitive)
- Template data: `forumrow.subforum` sub-block with `U_SUBFORUM`, `SUBFORUM_NAME`, `S_UNREAD`, `IS_LINK`

### 1.6 Content Visibility Integration

**Source**: [content_visibility.php](src/phpbb/forums/content_visibility.php#L124-L137)

```php
$row['forum_posts']  = $phpbb_content_visibility->get_count('forum_posts', $row, $forum_id);
$row['forum_topics'] = $phpbb_content_visibility->get_count('forum_topics', $row, $forum_id);
```

`get_count()` logic:
- **Non-moderator**: returns `$data[$mode . '_approved']` only
- **Moderator** (`m_approve`): returns `approved + unapproved + softdeleted`

This means visible topic/post counts depend on moderator permission per forum.

### 1.7 Performance Characteristics

- **Single query** fetches all forums (no N+1)
- Moderator list has **1 hour SQL cache**: `$db->sql_query($sql, 3600)`
- **No lazy loading** — all forums fetched in one pass
- **No explicit forum data caching** — query runs each page load
- Forum tracking info loaded in same query via LEFT JOIN (no extra query)
- Cookie-based tracking for anonymous users (no DB query)

---

## 2. `index.php` Flow

**Source**: [index.php](web/index.php#L1-L256)

### Entry Point

```php
include('functions_display.php');
$user->session_begin();
$auth->acl($user->data);
$user->setup('viewforum');

// ... notification handling ...

display_forums('', $config['load_moderators']);
```

**Key points**:
- Called with `$root_data = ''` → queries ALL forums (WHERE is empty)
- `$display_moderators` = `$config['load_moderators']`
- `$return_moderators = false` (default)
- After `display_forums()`, assigns additional template vars: `TOTAL_POSTS`, `TOTAL_TOPICS`, `TOTAL_USERS`, `NEWEST_USER`, `LEGEND`, `BIRTHDAY_LIST`
- Template file: `index_body.html`

### page_header / page_footer

```php
page_header($page_title, true);
$template->set_filenames(array('body' => 'index_body.html'));
page_footer();
```

The `page_header()` function sets up common template vars (user info, breadcrumbs base, etc.), and `page_footer()` triggers the actual template rendering.

---

## 3. `viewforum.php` Flow

**Source**: [viewforum.php](web/viewforum.php#L1-L350)

### Forum Data Loading

```php
$forum_id = $request->variable('f', 0);
$sql_ary = ['SELECT' => 'f.*', 'FROM' => [FORUMS_TABLE => 'f'], 'WHERE' => 'f.forum_id = ' . $forum_id];
```

With optional LEFT JOINs for:
- `FORUMS_TRACK_TABLE ft` — read tracking
- `FORUMS_WATCH_TABLE fw` — watch/subscription status

**Event**: `core.viewforum_modify_sql` — allows modifying the forum select query.

### Subforum Detection & Display

```php
// Do we have subforums?
if ($forum_data['left_id'] != $forum_data['right_id'] - 1)
{
    list($active_forum_ary, $moderators) = display_forums($forum_data, $config['load_moderators'], $config['load_moderators']);
}
```

- This is a **leaf node check**: if `right_id - left_id == 1`, the forum has no children
- If subforums exist, calls `display_forums($forum_data, ...)` with the forum's own data as `$root_data`
- This generates the WHERE clause: `left_id > {forum.left_id} AND left_id < {forum.right_id}`
- Template: `viewforum_body.html`

### Navigation Generation

```php
generate_forum_nav($forum_data);
generate_forum_rules($forum_data);
```

Called **before** `display_forums()` to set up breadcrumbs and rules.

---

## 4. Template Variables

### 4.1 Category Row (`forumrow` block, `S_IS_CAT == true`)

| Variable | Description |
|----------|-------------|
| `S_IS_CAT` | `true` |
| `FORUM_ID` | Category ID |
| `FORUM_NAME` | Category name |
| `FORUM_DESC` | Parsed BBCode description |
| `FORUM_IMAGE` / `FORUM_IMAGE_SRC` | Optional category image |
| `U_VIEWFORUM` | Link to viewforum.php?f={id} |

### 4.2 Forum Row (`forumrow` block, `S_IS_CAT == false`)

| Variable | Description |
|----------|-------------|
| `S_IS_CAT` | `false` |
| `S_NO_CAT` | `true` if forum has no parent category and previous one did |
| `S_IS_LINK` | `true` for FORUM_LINK type |
| `S_UNREAD_FORUM` | Whether forum has unread content |
| `S_AUTH_READ` | User can read forum |
| `S_LOCKED_FORUM` | Forum is locked |
| `S_LIST_SUBFORUMS` | Whether to display subforum list |
| `S_SUBFORUMS` | Whether subforums exist |
| `S_DISPLAY_SUBJECT` | Whether to show last post subject |
| `S_FEED_ENABLED` | RSS feed available |
| `FORUM_ID` | Forum ID |
| `FORUM_NAME` | Forum name |
| `FORUM_DESC` | Parsed description |
| `TOPICS` | Topic count (visibility-adjusted) |
| `POSTS` / `CLICKS` | Post count (for forums) or click count (for links) |
| `FORUM_IMG_STYLE` | CSS class for folder image |
| `FORUM_FOLDER_IMG` | HTML img tag for folder |
| `FORUM_FOLDER_IMG_ALT` | Alt text |
| `FORUM_IMAGE` / `FORUM_IMAGE_SRC` | Custom forum image |
| `LAST_POST_SUBJECT` | Full last post subject |
| `LAST_POST_SUBJECT_TRUNCATED` | Truncated to 30 chars |
| `LAST_POST_TIME` | Formatted time |
| `LAST_POST_TIME_RFC3339` | RFC3339 timestamp |
| `LAST_POSTER` | Username only |
| `LAST_POSTER_COLOUR` | User colour |
| `LAST_POSTER_FULL` | Full username link with colour |
| `MODERATORS` | Comma-separated moderator list |
| `SUBFORUMS` | Comma-separated subforum links HTML |
| `L_SUBFORUM_STR` | "Subforum" or "Subforums" label |
| `L_MODERATOR_STR` | "Moderator" or "Moderators" label |
| `U_UNAPPROVED_TOPICS` | MCP link if unapproved topics exist |
| `U_UNAPPROVED_POSTS` | MCP link if unapproved posts exist |
| `U_VIEWFORUM` | Forum URL (or external link for FORUM_LINK) |
| `U_LAST_POSTER` | Profile link of last poster |
| `U_LAST_POST` | Direct link to last post |

### 4.3 Subforum Sub-block (`forumrow.subforum`)

| Variable | Description |
|----------|-------------|
| `U_SUBFORUM` | Subforum URL |
| `SUBFORUM_NAME` | Name |
| `S_UNREAD` | Has unread content |
| `IS_LINK` | Is FORUM_LINK type |

### 4.4 Global Template Variables (set by `display_forums`)

| Variable | Description |
|----------|-------------|
| `U_MARK_FORUMS` | URL to mark all forums read |
| `S_HAS_SUBFORUM` | Whether any visible forums exist |
| `L_SUBFORUM` | "Subforum"/"Subforums" based on count |
| `LAST_POST_IMG` | "View latest post" icon |
| `UNAPPROVED_IMG` | Unapproved topics icon |
| `UNAPPROVED_POST_IMG` | Unapproved posts icon |

### 4.5 Navigation Template Variables (`navlinks` block)

| Variable | Description |
|----------|-------------|
| `S_IS_CAT` | Parent is category |
| `S_IS_LINK` | Parent is link |
| `S_IS_POST` | Parent is forum |
| `BREADCRUMB_NAME` | Display name |
| `FORUM_ID` | Forum ID |
| `MICRODATA` | `data-forum-id="{id}"` attribute |
| `U_BREADCRUMB` | URL to viewforum.php |

---

## 5. Events Dispatched During Display

### In `display_forums()`

| Event | When | Key Vars | Since |
|-------|------|----------|-------|
| `core.display_forums_modify_sql` | Before main query | `sql_ary` | 3.1.0-a1 |
| `core.display_forums_modify_row` | Per forum row fetched | `branch_root_id`, `row` | 3.1.0-a1 |
| `core.display_forums_modify_forum_rows` | After subforum aggregation per row | `forum_rows`, `subforums`, `branch_root_id`, `parent_id`, `row` | 3.1.0-a1 |
| `core.display_forums_before` | Before template loop generation | `active_forum_ary`, `display_moderators`, `forum_moderators`, `forum_rows`, `return_moderators`, `root_data` | 3.1.4-RC1 |
| `core.display_forums_modify_category_template_vars` | Per category before template assign | `cat_row`, `last_catless`, `root_data`, `row` | 3.1.0-RC4 |
| `core.display_forums_modify_template_vars` | Per forum before template assign | `forum_row`, `row`, `subforums_row` | 3.1.0-a1 |
| `core.display_forums_add_template_data` | Per forum after template assign | `forum_row`, `row`, `subforums_list`, `subforums_row`, `catless` | 3.1.0-b5 |
| `core.display_forums_after` | After all template generation | `active_forum_ary`, `display_moderators`, `forum_moderators`, `forum_rows`, `return_moderators`, `root_data` | 3.1.0-RC5 |

### In `viewforum.php`

| Event | When | Key Vars |
|-------|------|----------|
| `core.viewforum_modify_sql` | Before forum data query | `sql_ary` |
| `core.viewforum_modify_page_title` | Before page render | `page_title`, `forum_data`, `forum_id`, `start` |
| `core.viewforum_modify_topic_ordering` | Before topic sort | `sort_by_text`, `sort_by_sql` |

### In `generate_forum_nav()`

| Event | When | Key Vars |
|-------|------|----------|
| `core.generate_forum_nav` | Before navlinks template assign | `forum_data`, `forum_template_data`, `microdata_attr`, `navlinks_parents`, `navlinks` |

### In `index.php`

| Event | When | Key Vars |
|-------|------|----------|
| `core.index_mark_notification_after` | After notification marked read | `mark_notification`, `notification` |
| `core.index_modify_birthdays_sql` | Before birthday query | `now`, `sql_ary`, `time` |
| `core.index_modify_birthdays_list` | After birthday list built | `birthdays`, `rows` |
| `core.index_modify_page_title` | Before page render | `page_title` |

---

## 6. Navigation & Breadcrumbs

**Source**: [functions_display.php](src/phpbb/common/functions_display.php#L766-L860)

### `generate_forum_nav(&$forum_data_ary)`

1. **Permission check**: `if (!auth->acl_get('f_list', forum_id)) return;`
2. **Get parents**: calls `get_forum_parents($forum_data_ary)`
3. **Build parent navlinks**: Each parent gets a `navlinks` template block entry with `S_IS_CAT/LINK/POST`, `BREADCRUMB_NAME`, `FORUM_ID`, `MICRODATA`, `U_BREADCRUMB`
4. **Add current forum**: appended as final `navlinks` block entry
5. **Assign forum template data**: `FORUM_ID`, `FORUM_NAME`, `FORUM_DESC`, `S_ENABLE_FEEDS_FORUM`

### `get_forum_parents(&$forum_data)`

**Source**: [functions_display.php](src/phpbb/common/functions_display.php#L864-L905)

Two code paths:
1. **If `forum_parents` column is empty**: Queries `FORUMS_TABLE` for all ancestors using nested set: `WHERE left_id < {forum.left_id} AND right_id > {forum.right_id} ORDER BY left_id ASC`. Then **caches result** by serializing into `forum_parents` column via UPDATE.
2. **If `forum_parents` column populated**: `unserialize()` the cached data.

Returns `array($forum_id => array($forum_name, $forum_type))` for each ancestor.

**Key insight**: Breadcrumb data is **cached in the database** (`forum_parents` column) as a serialized PHP array. The cache is populated on first access and reused thereafter. Invalidation happens when forum structure changes (not visible in this code but would be in admin/forum management).

---

## 7. Rendering Pipeline Summary (DB → HTML)

```
DB (forums table, nested set) 
  → display_forums() single SQL query (ORDER BY left_id)
    → Sequential row processing with branch-skip logic
      → ACL filtering (f_list permission)
      → Content visibility adjustment (topic/post counts)
      → Forum tracking info merge (read/unread state)
      → Parent-child aggregation (stats bubble up)
      → Subforum list construction
    → Template block assignments (forumrow, forumrow.subforum)
    → Global template vars (mark forums link, icons)
  → Template engine renders index_body.html / viewforum_body.html
    → HTML output
```

### Folder Image State Machine

```
forum_type + forum_status + has_subforums + unread_state → folder_image

FORUM_LINK                                → 'forum_link'
FORUM_POST + has_subforums + unread       → 'forum_unread_subforum'  
FORUM_POST + has_subforums + read         → 'forum_read_subforum'
FORUM_POST + no_subforums  + unread       → 'forum_unread'
FORUM_POST + no_subforums  + read         → 'forum_read'
Any        + ITEM_LOCKED   + unread       → 'forum_unread_locked'
Any        + ITEM_LOCKED   + read         → 'forum_read_locked'
```

---

## 8. Key Observations for Service Extraction

1. **`display_forums()` is a monolith** — 700+ lines mixing SQL, business logic, ACL, read-tracking, template assignment
2. **Nested set traversal is implicit** — uses `ORDER BY left_id` and sequential processing with skip-by-right_id
3. **No domain objects** — returns raw DB arrays, processes them inline
4. **Stat aggregation is coupled** — subforum topic/post counts roll up to parent during the same loop
5. **Template assignment is interleaved** — not separable from data processing without refactoring
6. **Events enable extension** but the core loop remains monolithic
7. **`generate_forum_nav()` has the breadcrumb caching pattern** — serializes parent chain into DB column
8. **Two call sites**: `index.php` (root, all forums) and `viewforum.php` (subtree of current forum)
