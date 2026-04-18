# Forum CRUD Operations — Legacy ACP Analysis

**Source**: `src/phpbb/common/acp/acp_forums.php` (2245 lines), `src/phpbb/common/functions_admin.php`, `src/phpbb/forums/tree/nestedset_forum.php`
**Confidence**: High (100%) — direct code reading

---

## 1. Mode Routing (`main()` method)

**Source**: [acp_forums.php](src/phpbb/common/acp/acp_forums.php#L24-L48)

The `main($id, $mode)` method uses an `$action` request variable to route:

| Action | Description | Permission Required |
|--------|-------------|-------------------|
| `add` | Create new forum | `a_forumadd` |
| `edit` | Modify existing forum | (implicit admin) |
| `delete` | Remove forum | `a_forumdel` |
| `move_up` | Reorder up | (implicit admin) |
| `move_down` | Reorder down | (implicit admin) |
| `sync` | Resync topic counts | (implicit admin) |
| `sync_forum` | Resync forum counters | (implicit admin) |
| `copy_perm` | Copy permissions from another forum | (implicit admin) |
| `progress_bar` | AJAX progress display | (implicit admin) |

**Security**:
- CSRF via `add_form_key('acp_forums')` + `check_form_key($form_key)` on all POST updates
- ACL checked for `add` (`a_forumadd`) and `delete` (`a_forumdel`) before processing

**Two-phase routing**:
1. **Phase 1 (POST update)**: If `$update` is true, processes `add`, `edit`, or `delete` mutations
2. **Phase 2 (display)**: Renders the appropriate form (`add`/`edit`/`delete` form, or default listing)

---

## 2. Entity Model — All Forum Fields

**Source**: [acp_forums.php](src/phpbb/common/acp/acp_forums.php#L112-L173)

### Fields collected from POST on add/edit:

| Field | Type | Default (add) | Description |
|-------|------|---------------|-------------|
| `parent_id` | int | `$this->parent_id` | Parent forum ID (0 = root) |
| `forum_type` | int | `FORUM_POST` | Type: `FORUM_CAT`, `FORUM_POST`, `FORUM_LINK` |
| `type_action` | string | `''` | When changing type: `move` or `delete` (for content) |
| `forum_status` | int | `ITEM_UNLOCKED` | Lock status: `ITEM_UNLOCKED` or `ITEM_LOCKED` |
| `forum_parents` | string | `''` | Serialized parent chain (auto-generated) |
| `forum_name` | string (utf8) | `''` | Forum display name |
| `forum_link` | string | `''` | External URL (for FORUM_LINK type) |
| `forum_link_track` | bool | `false` | Track link clicks |
| `forum_desc` | string (utf8) | `''` | Forum description (BBCode supported) |
| `forum_desc_uid` | string | `''` | BBCode UID for desc |
| `forum_desc_options` | int | `7` | BBCode options bitmask for desc |
| `forum_desc_bitfield` | string | `''` | BBCode bitfield for desc |
| `forum_rules` | string (utf8) | `''` | Forum rules text (BBCode supported) |
| `forum_rules_uid` | string | `''` | BBCode UID for rules |
| `forum_rules_options` | int | `7` | BBCode options bitmask for rules |
| `forum_rules_bitfield` | string | `''` | BBCode bitfield for rules |
| `forum_rules_link` | string | `''` | External URL for rules |
| `forum_image` | string | `''` | Path to forum icon image |
| `forum_style` | int | `0` | Style override (0 = board default) |
| `display_subforum_list` | bool | `true` | Show list of subforums |
| `display_subforum_limit` | bool | `false` | Limit subforum display |
| `display_on_index` | bool | `true` | Show on board index |
| `forum_topics_per_page` | int | `0` | Override topics per page (0 = board default) |
| `enable_indexing` | bool | `true` | Enable search indexing |
| `enable_icons` | bool | `true` | Enable topic icons |
| `enable_prune` | bool | `false` | Enable auto-pruning |
| `enable_post_review` | bool | `true` | Show post review before submit |
| `enable_quick_reply` | bool | `false` | Enable quick reply |
| `enable_shadow_prune` | bool | `false` | Prune shadow (moved) topics |
| `prune_days` | int | `7` | Prune topics older than N days |
| `prune_viewed` | int | `7` | Prune topics not viewed in N days |
| `prune_freq` | int | `1` | Prune check frequency (days) |
| `prune_old_polls` | bool | `false` | Include polls in pruning |
| `prune_announce` | bool | `false` | Include announcements in pruning |
| `prune_sticky` | bool | `false` | Include stickies in pruning |
| `prune_shadow_days` | int | `7` | Shadow topic prune age |
| `prune_shadow_freq` | int | `1` | Shadow prune check frequency |
| `forum_password` | string (utf8) | `''` | Forum access password |
| `forum_password_confirm` | string (utf8) | `''` | Password confirmation (not stored) |
| `forum_password_unset` | bool | `false` | Clear existing password |

### Computed fields (not from direct form input):

| Field | Source | Description |
|-------|--------|-------------|
| `forum_options` | Set to `0` on add | Bitmask (not exposed in UI) |
| `show_active` | Conditional on `forum_type` | `display_recent` for POST, `display_active` for CAT |
| `forum_flags` | Computed from booleans | Bitmask combining flags (see below) |
| `left_id` / `right_id` | Nested set | Auto-calculated on insert |

### Forum Flags Bitmask:

| Bit | Constant | Description |
|-----|----------|-------------|
| 1 | `FORUM_FLAG_LINK_TRACK` | Track link clicks |
| 2 | `FORUM_FLAG_PRUNE_POLL` | Prune old polls |
| 4 | `FORUM_FLAG_PRUNE_ANNOUNCE` | Prune announcements |
| 8 | `FORUM_FLAG_PRUNE_STICKY` | Prune stickies |
| 16 | `FORUM_FLAG_ACTIVE_TOPICS` | Show active topics |
| 32 | `FORUM_FLAG_POST_REVIEW` | Enable post review |
| 64 | `FORUM_FLAG_QUICK_REPLY` | Enable quick reply |

---

## 3. Create Forum (action=add)

**Source**: [acp_forums.php](src/phpbb/common/acp/acp_forums.php#L107-L243)

### Flow:
1. Collect all form fields from POST (same as edit, but no `forum_id`)
2. Set `forum_options = 0` for new forums
3. Parse BBCode for `forum_rules` and `forum_desc` via `generate_text_for_storage()`
4. Call `$this->update_forum_data($forum_data)` which:
   - Validates data (name not empty, desc/rules < 4000 chars, password match, prune values ≥ 0, image exists)
   - Hashes password if set
   - **Nested set insertion**:
     - If `parent_id > 0`: Finds parent's `right_id`, shifts all nodes right by 2, inserts with `left_id = parent.right_id`, `right_id = parent.right_id + 1`
     - If `parent_id == 0` (root): Gets `MAX(right_id)`, inserts with `left_id = max+1`, `right_id = max+2`
   - `INSERT INTO FORUMS_TABLE`
   - Sets `forum_data['forum_id'] = $db->sql_nextid()`
   - Logs `LOG_FORUM_ADD`
5. After successful creation:
   - Optionally copies permissions from `forum_perm_from` via `copy_forum_permissions()`
   - Clears ACL prefetch
   - Invalidates forums cache
   - If no permissions copied and user has `a_fauth`, redirects to permissions page

### Nested Set Insert (in `update_forum_data()`):

```php
// With parent
$sql = 'UPDATE FORUMS_TABLE SET left_id = left_id + 2, right_id = right_id + 2 WHERE left_id > ' . $row['right_id'];
$sql = 'UPDATE FORUMS_TABLE SET right_id = right_id + 2 WHERE ' . $row['left_id'] . ' BETWEEN left_id AND right_id';
$forum_data_sql['left_id'] = $row['right_id'];
$forum_data_sql['right_id'] = $row['right_id'] + 1;

// Without parent (root)
$forum_data_sql['left_id'] = $max_right_id + 1;
$forum_data_sql['right_id'] = $max_right_id + 2;
```

**Note**: The ACP does NOT use `nestedset_forum` class for insertion. It does raw SQL nested-set math inline.

---

## 4. Edit Forum (action=edit)

**Source**: [acp_forums.php](src/phpbb/common/acp/acp_forums.php#L107-L243), [update_forum_data()](src/phpbb/common/acp/acp_forums.php#L1007-L1425)

### Flow:
1. Sets `forum_data['forum_id'] = $forum_id` (falls through from `case 'edit':` to `case 'add':`)
2. Collects same fields as create
3. Calls `$this->update_forum_data($forum_data)` which for existing forums:
   - Loads existing row via `get_forum_info()`
   - **Type change handling**:
     - POST → CAT/LINK: Requires `type_action` = `move` (move content to another forum) or `delete` (delete content). Resets all stats.
     - CAT → LINK with subforums: Requires `action_subforums` = `move` or `delete`. Adjusts nested set.
     - CAT → POST: Resets stats counters to 0
     - POST → LINK with subforums: Blocked with error `FORUM_WITH_SUBFORUMS_NOT_TO_LINK`
   - **Parent change**: If `parent_id` changed, calls `$this->move_forum()` to reposition in nested set
   - **Name change**: Clears ALL `forum_parents` columns in the table (invalidates cached parent chains)
   - `UPDATE FORUMS_TABLE SET ... WHERE forum_id = $forum_id`
   - Logs `LOG_FORUM_EDIT`
4. After update:
   - Optionally copies permissions (only if user has `a_fauth`, `a_authusers`, `a_authgroups`, `a_mauth`)
   - Clears ACL prefetch + forums cache

### Key Type-Change Side Effects:

| From | To | Action Required | Side Effects |
|------|----|-----------------|--------------|
| POST | CAT | move or delete content | Stats reset to 0 |
| POST | LINK | move or delete content | Stats reset to 0, blocked if has subforums |
| CAT | LINK | move or delete subforums | Nested set adjusted |
| CAT | POST | none | Stats set to 0 |
| LINK | anything | none | (no content to handle) |

---

## 5. Delete Forum (action=delete)

**Source**: [delete_forum()](src/phpbb/common/acp/acp_forums.php#L1633-L1876), [delete_forum_content()](src/phpbb/common/acp/acp_forums.php#L1907-L2133)

### UI Options (presented in delete confirmation form):
- **Posts**: `action_posts` = `delete` or `move` (to `posts_to_id`)
- **Subforums**: `action_subforums` = `delete` or `move` (to `subforums_to_id`)

### `delete_forum()` Flow:

1. Load forum data via `get_forum_info()`
2. **Handle posts**:
   - `delete`: Calls `delete_forum_content($forum_id)`
   - `move`: Calls `move_forum_content($forum_id, $posts_to_id)`
3. **Handle subforums**:
   - `delete`: Iterates children via `get_forum_branch()`, calls `delete_forum_content()` for each, then deletes from `FORUMS_TABLE`, `ACL_GROUPS_TABLE`, `ACL_USERS_TABLE`
   - `move`: Uses `move_forum()` for each direct child, then updates `parent_id`, then deletes only the target forum + its ACLs
   - neither: Just deletes the single forum + its ACLs
4. **Resync nested set tree**: Adjusts `left_id`/`right_id` by subtracting `$diff` (2 × number of deleted forums)
5. **Clean extension groups**: Removes deleted forum IDs from `EXTENSION_GROUPS_TABLE.allowed_forums`
6. **Logging**: Dispatches one of 9 different log entries based on the combination of post/subforum actions

### `delete_forum_content()` Flow:

1. Delete attachments for all topics in forum
2. Delete shadow topics
3. Count posts per user (for post count adjustment)
4. **Cascade delete from tables** (multi-table delete on MySQL, batched on others):
   - `SEARCH_WORDMATCH_TABLE` (post_id)
   - `REPORTS_TABLE` (post_id)
   - `WARNINGS_TABLE` (post_id)
   - `BOOKMARKS_TABLE` (topic_id)
   - `TOPICS_WATCH_TABLE` (topic_id)
   - `TOPICS_POSTED_TABLE` (topic_id)
   - `POLL_OPTIONS_TABLE` (topic_id)
   - `POLL_VOTES_TABLE` (topic_id)
5. **Delete forum-specific rows**:
   - `FORUMS_ACCESS_TABLE`, `FORUMS_TRACK_TABLE`, `FORUMS_WATCH_TABLE`, `LOG_TABLE`, `MODERATOR_CACHE_TABLE`, `POSTS_TABLE`, `TOPICS_TABLE`, `TOPICS_TRACK_TABLE`
6. Update `DRAFTS_TABLE` to set `forum_id = 0`
7. Adjust user post counts
8. Recount global stats (`num_posts`, `num_topics`, `num_files`, `upload_dir_size`)

---

## 6. Move Forum (Reparenting)

**Source**: [move_forum()](src/phpbb/common/acp/acp_forums.php#L1428-L1570)

Called when a forum's `parent_id` changes during edit.

### Flow:
1. Validate target parent is not FORUM_LINK type
2. `$db->sql_transaction('begin')`
3. Get all children via `get_forum_branch($from_id, 'children', 'descending')`
4. Calculate `$diff = count(children) * 2`
5. **Remove from old position**: Subtract `$diff` from all nodes to the right
6. **Insert into new position**: Add `$diff` at new parent's `right_id`
7. **Reposition moved branch**: Calculate and apply offset
8. Clear `forum_parents` for all affected nodes
9. `$db->sql_transaction('commit')`

**Important**: This is a raw SQL nested-set operation, NOT using the `nestedset_forum` class.

---

## 7. Move Forum Content

**Source**: [move_forum_content()](src/phpbb/common/acp/acp_forums.php#L1578-L1632)

Moves posts/topics from one forum to another.

### Tables updated:
- `LOG_TABLE`, `POSTS_TABLE`, `TOPICS_TABLE`, `DRAFTS_TABLE`, `TOPICS_TRACK_TABLE` → `SET forum_id = $to_id WHERE forum_id = $from_id`
- `FORUMS_ACCESS_TABLE`, `FORUMS_TRACK_TABLE`, `FORUMS_WATCH_TABLE`, `MODERATOR_CACHE_TABLE` → `DELETE WHERE forum_id = $from_id`

### Post-move:
- `sync('topic_moved')` — deletes ghost topics that link back to same forum
- `sync('forum', 'forum_id', $to_id)` — resyncs target forum counters

---

## 8. Copy Permissions

**Source**: [functions_admin.php](src/phpbb/common/functions_admin.php#L354-L535)  `copy_forum_permissions()`

### Flow:
1. Validate source and destination forums exist
2. Query all entries from `ACL_USERS_TABLE` and `ACL_GROUPS_TABLE` for `$src_forum_id`
3. If `$clear_dest_perms`: DELETE existing permissions from destination forums
4. `sql_multi_insert` copied rows with destination `forum_id`
5. Log `LOG_FORUM_COPIED_PERMISSIONS`

### When called:
- After **create**: If `forum_perm_from` is specified in form
- After **edit**: Only if user has `a_fauth`, `a_authusers`, `a_authgroups`, `a_mauth`
- Via **copy_perm** action: Standalone permission copy

**Note**: There is NO "copy forum" operation (deep copy). Only permission copying exists.

---

## 9. Reorder (move_up / move_down)

**Source**: [move_forum_by()](src/phpbb/common/acp/acp_forums.php#L2140-L2221)

### Flow:
1. Find sibling(s) in the same direction among forums with same `parent_id`
2. Calculate swap ranges using `left_id`/`right_id` boundaries
3. Single UPDATE query using CASE expression to swap positions:
```sql
UPDATE FORUMS_TABLE
SET left_id = left_id + CASE
    WHEN left_id BETWEEN {move_up_left} AND {move_up_right} THEN -{diff_up}
    ELSE {diff_down}
END,
right_id = right_id + CASE ...
END,
forum_parents = ''
WHERE left_id BETWEEN {left_id} AND {right_id}
  AND right_id BETWEEN {left_id} AND {right_id}
```
4. Returns target forum name (for logging) or `false` if already at boundary
5. Supports AJAX response via `\phpbb\json_response`

---

## 10. Validation Rules (in `update_forum_data()`)

**Source**: [acp_forums.php](src/phpbb/common/acp/acp_forums.php#L1007-L1100)

| Rule | Error |
|------|-------|
| `forum_name` is empty | `FORUM_NAME_EMPTY` |
| `forum_name` contains 4-byte UTF-8 emoji | `FORUM_NAME_EMOJI` (after UCR encoding attempt) |
| `forum_desc` > 4000 chars (utf8) | `FORUM_DESC_TOO_LONG` |
| `forum_rules` > 4000 chars (utf8) | `FORUM_RULES_TOO_LONG` |
| `forum_password` ≠ `forum_password_confirm` | `FORUM_PASSWORD_MISMATCH` |
| `prune_days`, `prune_viewed`, or `prune_freq` < 0 | `FORUM_DATA_NEGATIVE` |
| `forum_topics_per_page` outside USINT range | `validate_range()` |
| `forum_image` path doesn't exist on disk | `FORUM_IMAGE_NO_EXIST` |
| `forum_password` is exactly 32 chars (old MD5 hash) | `FORUM_PASSWORD_OLD` |
| Parent is FORUM_LINK type | `PARENT_IS_LINK_FORUM` |
| POST with subforums changing to LINK | `FORUM_WITH_SUBFORUMS_NOT_TO_LINK` |

### Additional validation via event:
- `core.acp_manage_forums_validate_data` — extensions can add custom errors

### Password handling:
- `forum_password_unset` = true → clears password to `''`
- Empty password → field not updated (preserves existing)
- Non-empty password → hashed via `$passwords_manager->hash()`

---

## 11. Cache Invalidation

| Operation | Cache Calls |
|-----------|-------------|
| Create (add) | `$cache->destroy('sql', FORUMS_TABLE)`, `$auth->acl_clear_prefetch()` |
| Edit | `$cache->destroy('sql', FORUMS_TABLE)`, `$auth->acl_clear_prefetch()` |
| Delete | `$cache->destroy('sql', FORUMS_TABLE)`, `$auth->acl_clear_prefetch()`, `$cache->destroy('_extensions')` |
| Move up/down | `$cache->destroy('sql', FORUMS_TABLE)` |
| Sync | `$cache->destroy('sql', FORUMS_TABLE)` |
| Copy permissions | `$cache->destroy('sql', FORUMS_TABLE)`, `$auth->acl_clear_prefetch()`, `phpbb_cache_moderators()` |

### Additional invalidation:
- Forum name change during edit → clears ALL forums' `forum_parents` column (`SET forum_parents = ''`)
- Move (reparent) → clears `forum_parents` for affected nodes

---

## 12. Events / Hooks Dispatched

| Event | When | Variables |
|-------|------|-----------|
| `core.acp_manage_forums_request_data` | After collecting POST data (add/edit) | `action`, `forum_data` |
| `core.acp_manage_forums_initialise_data` | Before rendering add/edit form | `action`, `update`, `forum_id`, `row`, `forum_data`, `parents_list` |
| `core.acp_manage_forums_display_form` | Before template assign (add/edit form) | `action`, `update`, `forum_id`, `row`, `forum_data`, `parents_list`, `errors`, `template_data` |
| `core.acp_manage_forums_validate_data` | During validation in `update_forum_data()` | `forum_data`, `errors` |
| `core.acp_manage_forums_update_data_before` | Before SQL insert/update | `forum_data`, `forum_data_sql` |
| `core.acp_manage_forums_update_data_after` | After SQL insert/update | `forum_data`, `forum_data_sql`, `is_new_forum`, `errors` |
| `core.acp_manage_forums_move_children` | When reparenting children | `from_id`, `to_id`, `errors` |
| `core.acp_manage_forums_move_content` | When moving posts/topics | `from_id`, `to_id`, `sync`, `errors` |
| `core.acp_manage_forums_move_content_sql_before` | Before move content SQL | `table_ary` |
| `core.acp_manage_forums_move_content_after` | After move content SQL | `from_id`, `to_id`, `sync` |
| `core.delete_forum_content_before_query` | Before delete content SQL | `table_ary`, `forum_id`, `topic_ids`, `post_counts` |
| `core.acp_manage_forums_modify_forum_list` | Before rendering forum list | `rowset` |
| `core.make_forum_select_modify_forum_list` | In `make_forum_select()` helper | `rowset` |

---

## 13. Nested Set Implementation Notes

**Key finding**: The ACP module does its own nested-set operations with raw SQL, NOT using the `\phpbb\tree\nestedset_forum` class.

The `nestedset_forum` class exists ([nestedset_forum.php](src/phpbb/forums/tree/nestedset_forum.php)) but is only a thin wrapper that configures the base `\phpbb\tree\nestedset` with forum-specific column mappings:
- `item_id` → `forum_id`
- `item_parents` → `forum_parents`
- Basic data: `forum_id`, `forum_name`, `forum_type`

The base `nestedset` class provides methods: `insert()`, `delete()`, `move()`, `move_up()`, `move_down()`, `move_children()`, `change_parent()`, `get_path_data()`, `get_subtree_data()`, `regenerate_left_right_ids()`.

**Implication for new service**: The new hierarchy service should use the `nestedset_forum` class (or its successor) rather than reimplementing nested-set math. The ACP's inline approach is legacy and error-prone.

---

## 14. Helper Functions (functions_admin.php)

| Function | Purpose |
|----------|---------|
| `make_forum_select()` | Build HTML `<option>` list of forums with ACL filtering |
| `get_forum_list()` | Get flat array of forum IDs with optional ACL filtering |
| `get_forum_branch()` | Get parents, children, or full branch of a forum |
| `copy_forum_permissions()` | Copy ACL entries between forums |
| `move_topics()` | Move topics between forums (updates `forum_id`) |
| `move_posts()` | Move posts between topics |
| `delete_topics()` | Delete topics with cascade |
| `delete_posts()` | Delete posts with cascade |
| `delete_topic_shadows()` | Remove shadow/moved topic pointers |
| `sync()` | Resync counters (topics, posts, forums) |
| `phpbb_cache_moderators()` | Rebuild moderator cache table |

---

## 15. Database Tables Involved

### Primary:
- `FORUMS_TABLE` — forum entity storage

### Permissions (affected by CRUD):
- `ACL_USERS_TABLE` — per-user forum permissions
- `ACL_GROUPS_TABLE` — per-group forum permissions
- `MODERATOR_CACHE_TABLE` — cached moderator list

### Content (affected by delete/move):
- `POSTS_TABLE`, `TOPICS_TABLE`, `ATTACHMENTS_TABLE`
- `SEARCH_WORDMATCH_TABLE`, `REPORTS_TABLE`, `WARNINGS_TABLE`
- `BOOKMARKS_TABLE`, `TOPICS_WATCH_TABLE`, `TOPICS_POSTED_TABLE`
- `POLL_OPTIONS_TABLE`, `POLL_VOTES_TABLE`
- `DRAFTS_TABLE`, `LOG_TABLE`

### Tracking (affected by delete/move):
- `FORUMS_ACCESS_TABLE`, `FORUMS_TRACK_TABLE`, `FORUMS_WATCH_TABLE`, `TOPICS_TRACK_TABLE`

### Other:
- `EXTENSION_GROUPS_TABLE` — allowed_forums list cleaned on delete

---

## 16. Summary of Key Patterns

1. **No "Copy Forum" operation** — Only permissions can be copied, not the forum structure itself
2. **Type changes are expensive** — Changing POST → CAT/LINK requires deciding what to do with content
3. **Nested set is managed manually** — Raw SQL, not via the OOP nestedset class
4. **Permissions are decoupled** — Forum CRUD triggers permission operations but doesn't own them
5. **Forum name change has global impact** — Clears ALL forums' `forum_parents` cache column
6. **Delete is complex** — 9 different log entry variants depending on post/subforum disposition
7. **Extensive event system** — 13 events allow extensions to hook into every phase of CRUD
8. **Password uses modern hashing** — via `passwords.manager` service, with legacy MD5 detection
