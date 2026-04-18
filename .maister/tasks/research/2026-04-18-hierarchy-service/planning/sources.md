# Research Sources

## 1. nested-set-core

### Key Files
- `src/phpbb/forums/tree/tree_interface.php` — Tree interface (insert, delete, move, move_children, change_parent, get_path_and_subtree_data, regenerate_left_right_ids)
- `src/phpbb/forums/tree/nestedset.php` (875 lines) — Abstract base: left_id/right_id management, locking, add/remove from nestedset, move with delta, path queries, subtree extraction, regeneration
- `src/phpbb/forums/tree/nestedset_forum.php` — Forum-specific: column mapping (forum_id, forum_parents, forum_name, forum_type), message prefix

### Methods to Extract
From `nestedset.php`:
- `insert()`, `add_item_to_nestedset()`, `remove_item_from_nestedset()`
- `delete()`, `move()`, `move_down()`, `move_up()`
- `move_children()`, `change_parent()`
- `get_path_and_subtree_data()`, `get_path_data()`, `get_subtree_data()`
- `get_set_of_nodes_data()`, `get_path_basic_data()`, `get_all_tree_data()`
- `remove_subset()`, `prepare_adding_subset()`
- `reset_nestedset_values()`, `regenerate_left_right_ids()`
- `acquire_lock()`, `get_sql_where()`

### Patterns to Identify
- Lock acquisition strategy (`\phpbb\lock\db`)
- Column abstraction layer (column_item_id, column_left_id, etc.)
- SQL_WHERE for multi-set-per-table support
- item_basic_data caching in parent column

---

## 2. forum-schema

### Database Tables

#### `phpbb_forums` (~48 columns)
Source: `phpbb_dump.sql` lines 1695-1750

Column groups to categorize:
- **Identity**: forum_id (PK), forum_name, forum_desc (+bitfield/options/uid)
- **Hierarchy**: parent_id, left_id, right_id, forum_parents (serialized path cache)
- **Type/Status**: forum_type, forum_status, forum_link
- **Display**: forum_image, forum_style, display_on_index, display_subforum_list, display_subforum_limit
- **Rules**: forum_rules (+bitfield/options/uid), forum_rules_link
- **Security**: forum_password
- **Counters**: forum_posts_approved/unapproved/softdeleted, forum_topics_approved/unapproved/softdeleted
- **Last post cache**: forum_last_post_id, forum_last_poster_id, forum_last_post_subject, forum_last_post_time, forum_last_poster_name, forum_last_poster_colour
- **Settings**: forum_topics_per_page, forum_flags, forum_options, enable_indexing, enable_icons
- **Pruning**: enable_prune, prune_next, prune_days, prune_viewed, prune_freq, enable_shadow_prune, prune_shadow_days, prune_shadow_freq, prune_shadow_next

Indexes:
- PK: `forum_id`
- `left_right_id` (left_id, right_id)
- `forum_lastpost_id` (forum_last_post_id)

#### `phpbb_forums_access`
Source: `phpbb_dump.sql` lines 1773-1779
- `forum_id` mediumint(8) unsigned
- `user_id` int(10) unsigned
- `session_id` char(32)
- PK: (forum_id, user_id, session_id)

#### `phpbb_forums_track`
Source: `phpbb_dump.sql` lines 1797-1803
- `user_id` int(10) unsigned
- `forum_id` mediumint(8) unsigned
- `mark_time` int(11) unsigned
- PK: (user_id, forum_id)

#### `phpbb_forums_watch`
Source: `phpbb_dump.sql` lines 1821-1829
- `forum_id` mediumint(8) unsigned
- `user_id` int(10) unsigned
- `notify_status` tinyint(1) unsigned
- Keys: forum_id, user_id, notify_stat

### Cross-Reference Sources
- `src/phpbb/common/acp/acp_forums.php` — form fields map to columns (which are user-editable)
- `src/phpbb/common/functions_display.php` — which columns are used in display
- `src/phpbb/forums/content_visibility.php` — approved/unapproved/softdeleted counter usage

---

## 3. forum-crud

### Key Files
- `src/phpbb/common/acp/acp_forums.php` (2245 lines) — primary CRUD controller

### Functions to Analyze
| Function | Line | Purpose |
|----------|------|---------|
| `main()` | 23 | Entry point: routes actions (add, edit, delete, move_up, move_down, sync) |
| `get_forum_info()` | 949 | Load single forum by ID |
| `update_forum_data()` | 972 | Create or update forum (INSERT or UPDATE, nested set ops) |
| `move_forum()` | 1430 | Move forum content from one to another |
| `move_forum_content()` | 1552 | Move topics/posts between forums |
| `delete_forum()` | 1637 | Delete forum with action options (delete/move posts, delete/move subforums) |
| `delete_forum_content()` | 1882 | Delete all content in a forum |
| `move_forum_by()` | 2116 | Reorder forum position (move_up/move_down by steps) |
| `copy_permission_page()` | 2222 | UI for copying permissions from another forum |

### Helper Functions (functions_admin.php)
| Function | Line | Purpose |
|----------|------|---------|
| `make_forum_select()` | 63 | Build forum select dropdown (tree-aware) |
| `get_forum_list()` | 225 | Get filtered forum list by ACL |
| `get_forum_branch()` | 301 | Get forum branch (ancestors or descendants) |
| `copy_forum_permissions()` | 354 | Copy ACL permissions between forums |
| `move_topics()` | 541 | Move topics to different forum |

### Additional Files
- `src/phpbb/common/acp/info/acp_forums.php` — ACP module info registration
- `src/phpbb/common/functions_admin.php` — forum admin helper functions

---

## 4. forum-display

### Key Files
- `src/phpbb/common/functions_display.php` (1781 lines) — `display_forums()` is the primary function

### Functions to Analyze
| Function | Line | Purpose |
|----------|------|---------|
| `display_forums()` | 21 | Main display: builds forum hierarchy, subforum lists, template vars |
| `get_forum_parents()` | 864 | Retrieve parent chain for breadcrumb (uses forum_parents cache column) |

### Event Hooks in display_forums()
| Event | Line | Data |
|-------|------|------|
| `core.display_forums_modify_sql` | 153 | SQL query modification |
| `core.display_forums_modify_row` | 177 | Per-row data modification |
| `core.display_forums_modify_forum_rows` | 344 | All rows modification |
| `core.display_forums_before` | 414 | Before template rendering |
| `core.display_forums_modify_category_template_vars` | 460 | Category template vars |
| `core.display_forums_modify_template_vars` | 664 | Forum template vars |
| `core.display_forums_add_template_data` | 693 | Additional template data |
| `core.display_forums_after` | 727 | After rendering |

### Entry Points
- `web/index.php` — Forum listing (calls display_forums with no root)
- `web/viewforum.php` — Single forum view (shows subforums)

### Related
- `src/phpbb/forums/content_visibility.php` — Content visibility filtering for counters

---

## 5. forum-tracking

### Key Files — Read Tracking
- `src/phpbb/common/functions.php` line 553 — `markread($mode, $forum_id, $topic_id, $post_time, $user_id)` — marks forums/topics as read
- `src/phpbb/common/functions.php` line 1268 — `update_forum_tracking_info($forum_id, $forum_last_post_time, $f_mark_time, $mark_time_forum)` — updates tracking display state

### Key Files — Subscriptions
- DB table `phpbb_forums_watch` — user_id, forum_id, notify_status
- `src/phpbb/forums/notification/type/forum.php` — Forum subscription notification type
- `src/phpbb/forums/notification/type/topic.php` — Topic notification (references forum)

### Key Files — Access Control
- DB table `phpbb_forums_access` — password-protected forum session tracking

### Patterns to Trace
1. **Mark as read flow**: markread('forums') → phpbb_forums_track INSERT/UPDATE → cookie fallback for guests
2. **Unread detection**: display_forums() → compare mark_time with forum_last_post_time
3. **Watch/subscribe**: UCP subscription management → phpbb_forums_watch → notification dispatch
4. **Password access**: Forum password entry → phpbb_forums_access session record

### Related Functions
- `display_forums()` lines ~200-340 — tracking/unread status calculation during display

---

## 6. plugin-patterns

### Extension System
- `src/phpbb/forums/extension/extension_interface.php` — Lifecycle: is_enableable(), enable_step(), disable_step(), purge_step()
- `src/phpbb/forums/extension/base.php` — Default implementation
- `src/phpbb/forums/extension/manager.php` — Extension management (enable/disable/load from DB)
- `src/phpbb/forums/extension/metadata_manager.php` — Extension metadata (composer.json parsing)
- `src/phpbb/forums/extension/provider.php` — Extension service provider

### Event/Dispatcher System
- `src/phpbb/forums/event/dispatcher_interface.php` — `trigger_event($eventName, $data)` interface
- `src/phpbb/forums/event/dispatcher.php` — Implementation (extends Symfony EventDispatcher)
- `src/phpbb/forums/event/data.php` — Event data container

### DI/Container Patterns
- `src/phpbb/forums/di/extension/core.php` — Core DI extension
- `src/phpbb/forums/di/extension/tables.php` — Table name injection
- `src/phpbb/forums/di/extension/config.php` — Config injection
- `src/phpbb/forums/di/extension/container_configuration.php` — Container configuration

### Real Extension Example: viglink
- `src/phpbb/ext/phpbb/viglink/ext.php` — Extension class
- `src/phpbb/ext/phpbb/viglink/event/listener.php` — Event listener (subscriber pattern)
- `src/phpbb/ext/phpbb/viglink/event/acp_listener.php` — ACP event listener
- `src/phpbb/ext/phpbb/viglink/config/` — DI service definitions
- `src/phpbb/ext/phpbb/viglink/migrations/` — DB migration files
- `src/phpbb/ext/phpbb/viglink/composer.json` — Extension metadata

### Notification System (for subscription integration)
- `src/phpbb/forums/notification/type/type_interface.php` — Notification type interface
- `src/phpbb/forums/notification/type/base.php` — Base notification implementation
- `src/phpbb/forums/notification/type/forum.php` — Forum-specific notification

### Patterns to Extract
1. **Event dispatch**: `$phpbb_dispatcher->trigger_event('core.xxx', compact($vars))` + `extract()`
2. **Extension lifecycle**: enable_step/disable_step with resumable state
3. **DI service registration**: Symfony container YAML definitions
4. **Listener registration**: Event subscriber with `getSubscribedEvents()`
5. **Migration pattern**: Schema and data migrations for extensions
