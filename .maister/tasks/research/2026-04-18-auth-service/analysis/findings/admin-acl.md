# Admin ACL — Permission Management in the ACP

## Overview

phpBB's ACP permission system is managed by two main modules and a backing `auth_admin` class:

| File | Role |
|------|------|
| `src/phpbb/common/acp/acp_permissions.php` | ACP module — UI for setting/viewing/deleting permissions for users/groups |
| `src/phpbb/common/acp/acp_permission_roles.php` | ACP module — CRUD for permission roles |
| `src/phpbb/common/acp/auth.php` | `auth_admin` class (extends `\phpbb\auth\auth`) — low-level ACL set/delete/role operations |
| `src/phpbb/common/functions_admin.php` | Helper functions: `copy_forum_permissions()`, `phpbb_cache_moderators()`, `add_permission_language()` |
| `src/phpbb/forums/auth/auth.php` | Base `auth` class — contains `acl_clear_prefetch()` |

---

## 1. Permission Scope: Global vs Local

**Determined by mode string** in `acp_permissions::main()` (line ~178):

```php
$permission_scope = (strpos($mode, '_global') !== false) ? 'global' : 'local';
```

**Mode → Permission type → Scope mapping:**

| Mode | Permission prefixes | Scope | Victim(s) |
|------|-------------------|-------|-----------|
| `setting_user_global` / `setting_group_global` | `u_`, `m_`, `a_` | global | user / group |
| `setting_user_local` / `setting_group_local` | `f_`, `m_` | local | user+forums / group+forums |
| `setting_admin_global` | `a_` | global | usergroup |
| `setting_mod_global` | `m_` | global | usergroup |
| `setting_mod_local` | `m_` | local | forums+usergroup |
| `setting_forum_local` | `f_` | local | forums+usergroup |

**Global** means `forum_id = 0` in the ACL tables.  
**Local** (forum-scoped) requires one or more `forum_id` values to be selected.

The `permission_scope` variable controls:
- Which ACL table columns are queried (global vs local)
- How `retrieve_defined_user_groups()` filters: `a.forum_id = 0` (global) vs `a.forum_id <> 0` (local)

---

## 2. Permission Setting Workflow

### 2.1 `set_permissions()` — Apply Single User/Group Permissions

**File:** `acp_permissions.php`, line ~702  
**Signature:** `function set_permissions($mode, $permission_type, $auth_admin, &$user_id, &$group_id)`

**Flow:**

1. **CSRF check** — `check_form_key($form_name)` validated before entry (line ~311)
2. **Authorization check** — Verifies caller has `a_{type}auth` AND `a_auth{users|groups}`:
   ```php
   if (!$auth->acl_get('a_' . str_replace('_', '', $permission_type) . 'auth') || !$auth->acl_get('a_auth' . $ug_type . 's'))
   ```
3. **Parse POST data** — Gets `psubmit[ug_id][forum_id]`, `setting[ug_id][forum_id][option]`, `role[ug_id][forum_id]`
4. **Inheritance** — If `inherit` POST array is set, copies settings to additional users/groups and forums
5. **Role validation** — Calls `check_assigned_role($assigned_role, $auth_settings)` to verify role matches actual settings; resets to 0 if mismatch
6. **Delegate to `auth_admin`** — `$auth_admin->acl_set($ug_type, $forum_id, $ug_id, $auth_settings, $assigned_role)`
7. **Post-actions:**
   - If `m_` type → `phpbb_cache_moderators()` rebuilds moderator cache
   - If `m_` or `a_` type → `phpbb_update_foes()` removes new mods/admins from foe lists
8. **Logging** — `$this->log_action($mode, 'add', ...)`

### 2.2 `set_all_permissions()` — Apply All Users/Groups at Once

**File:** `acp_permissions.php`, line ~773  
**Signature:** `function set_all_permissions($mode, $permission_type, $auth_admin, &$user_id, &$group_id)`

Similar to `set_permissions()` but iterates over ALL ug_id/forum_id combinations in the POST data:

```php
foreach ($auth_settings as $ug_id => $forum_auth_row) {
    foreach ($forum_auth_row as $forum_id => $auth_options) {
        $auth_admin->acl_set($ug_type, $forum_id, $ug_id, $auth_options, $assigned_role, false);
    }
}
$auth_admin->acl_clear_prefetch();  // Single prefetch clear after all sets
```

Note: Passes `$clear_prefetch = false` to individual `acl_set()` calls, then does ONE bulk `acl_clear_prefetch()` at the end.

### 2.3 `remove_permissions()` — Delete Permission Entries

**File:** `acp_permissions.php`, line ~900  
**Signature:** `function remove_permissions($mode, $permission_type, $auth_admin, &$user_id, &$group_id, &$forum_id)`

**Flow:**

1. **Authorization check** — Same as `set_permissions()`
2. **Calls `auth_admin->acl_delete()`:**
   ```php
   $auth_admin->acl_delete($ug_type, $ug_ids, $forum_id ?: false, $permission_type);
   ```
3. **Post-actions** — recache moderators if `m_` type
4. **Logging** — `$this->log_action($mode, 'del', ...)`

The delete action requires `confirm_box()` confirmation (line ~280).

---

## 3. Low-Level ACL Operations (`auth_admin`)

### 3.1 `acl_set()` — Set a User or Group ACL Record

**File:** `acp/auth.php`, line 839  
**Signature:** `function acl_set($ug_type, $forum_id, $ug_id, $auth, $role_id = 0, $clear_prefetch = true)`

**Strategy: Delete-then-insert** (not update):

1. Extracts permission flag prefix (e.g., `f_`, `m_`, `a_`, `u_`)
2. Computes the "any-flag" option ID (the bare prefix like `f_`)
3. **Deletes** all existing rows for the given ug_id + forum_id + option_ids from `ACL_USERS_TABLE` or `ACL_GROUPS_TABLE`
4. **Deletes** any role-based assignments for same ug_id + forum_id
5. Sets the "any-flag" to `ACL_YES` if any option is YES
6. **Two assignment paths:**
   - **Role-based**: Inserts ONE row per ug_id+forum with `auth_option_id=0, auth_role_id=<role_id>`
   - **Direct**: Inserts one row per option where `setting != ACL_NO`, with `auth_option_id=<id>, auth_setting=<value>`
7. If `$clear_prefetch = true`, calls `$this->acl_clear_prefetch()`

**Key insight for role vs direct assignment in DB:**

| Assignment Type | `auth_option_id` | `auth_role_id` | `auth_setting` |
|----------------|------------------|----------------|----------------|
| Role-based | `0` | `<role_id>` | `0` |
| Direct (per-option) | `<option_id>` | `0` (implied) | `ACL_YES` or `ACL_NEVER` |

### 3.2 `acl_set_role()` — Set Role Permission Data

**File:** `acp/auth.php`, line 973  
**Signature:** `function acl_set_role($role_id, $auth)`

Writes to `ACL_ROLES_DATA_TABLE`:
1. Removes any-flag from settings array
2. Sets any-flag to YES if any sub-option is YES
3. **Deletes** all existing rows for `role_id` from `ACL_ROLES_DATA_TABLE`
4. **Inserts** one row per option where `setting != ACL_NO`
5. If no data, inserts the any-flag as `ACL_NEVER`
6. Calls `acl_clear_prefetch()`

### 3.3 `acl_delete()` — Remove Local/Global Permission

**File:** `acp/auth.php`, line 1037  
**Signature:** `function acl_delete($mode, $ug_id = false, $forum_id = false, $permission_type = false)`

**Handles role-to-direct conversion on delete:**

1. If `$permission_type` is specified, fetches all `auth_option_id` values for that type
2. Finds users/groups that have **role-based** assignments for those options
3. **Converts role assignments to direct assignments** first (via `acl_set()` with `role_id=0`)
4. Then **deletes** the matching rows from ACL table
5. Calls `acl_clear_prefetch()`

This ensures that when you delete permissions of type `f_` for a user, role-based assignments are properly unwound.

---

## 4. Role CRUD Operations (`acp_permission_roles`)

**File:** `src/phpbb/common/acp/acp_permission_roles.php`

### 4.1 Role Types

```php
switch ($mode) {
    case 'admin_roles':  $permission_type = 'a_'; break;
    case 'user_roles':   $permission_type = 'u_'; break;
    case 'mod_roles':    $permission_type = 'm_'; break;
    case 'forum_roles':  $permission_type = 'f_'; break;
}
```

### 4.2 Create Role (`action = 'add'`)

**Validation:**
- CSRF: `check_form_key($form_name)` (line ~165)
- Name required, description max 4000 chars
- Name uniqueness within type: SELECT from `ACL_ROLES_TABLE` WHERE `role_type` + `role_name`

**Insert:**
```php
$sql_ary = ['role_name' => $role_name, 'role_description' => $description, 'role_type' => $permission_type];
$sql_ary['role_order'] = MAX(role_order) + 1;  // Appended at end
INSERT INTO ACL_ROLES_TABLE
```
Then: `$this->auth_admin->acl_set_role($role_id, $auth_settings)`

### 4.3 Edit Role (`action = 'edit'`)

Same validation as create. Updates `ACL_ROLES_TABLE` with new name/description, then calls `acl_set_role()` to replace all role data.

### 4.4 Delete Role (`action = 'remove'`)

**File:** `acp_permission_roles::remove_role()` (line ~555)

**Flow:**
1. Fetch all `auth_option` values for the permission type → build `ACL_NO` defaults
2. Fetch the role's actual settings from `ACL_ROLES_DATA_TABLE` → override defaults
3. Fetch all user/group assignments using this role via `get_role_mask($role_id)`
4. **Re-assign as direct permissions** — calls `acl_set('user'/group', ...)` for each assignment
5. **Cleanup:** DELETE from `ACL_USERS_TABLE` + `ACL_GROUPS_TABLE` WHERE `auth_role_id = $role_id`
6. **Cleanup:** DELETE from `ACL_ROLES_DATA_TABLE` WHERE `role_id`
7. **Cleanup:** DELETE from `ACL_ROLES_TABLE` WHERE `role_id`
8. Calls `acl_clear_prefetch()`

**Key behavior:** When a role is deleted, its permission settings are converted to direct per-option assignments for all users/groups that had the role.

### 4.5 Reorder Roles (`move_up` / `move_down`)

Uses `role_order` integer column. CSRF protected via `check_link_hash()`. Swaps order with adjacent role:
```php
SET role_order = (order_total) - role_order WHERE role_order IN (current, adjacent)
```

---

## 5. Cache Invalidation — `acl_clear_prefetch()`

**File:** `src/phpbb/forums/auth/auth.php`, line 524  
**Signature:** `function acl_clear_prefetch($user_id = false)`

**What it does:**
1. Destroys `_role_cache` from cache
2. Rebuilds role cache: Queries ALL rows from `ACL_ROLES_DATA_TABLE`, serializes per `role_id`, stores as `_role_cache`
3. Clears user computed permissions:
   - If `$user_id` specified: `UPDATE users SET user_permissions='', user_perm_from=0 WHERE user_id IN (...)`
   - If no user: Updates ALL users
4. Fires event `core.acl_clear_prefetch_after`

### 5.1 Complete List of `acl_clear_prefetch` Call Sites

| Location | File:Line | Context / Trigger |
|----------|-----------|-------------------|
| **Permission setting** | `acp/acp_permissions.php:833` | After `set_all_permissions()` bulk apply |
| **Forum permission copy** | `acp/acp_permissions.php:1261` | After `copy_forum_permissions()` |
| **auth_admin::acl_set()** | `acp/auth.php:966` | After every `acl_set()` call (when `$clear_prefetch=true`) |
| **auth_admin::acl_set_role()** | `acp/auth.php:1031` | After updating role data |
| **auth_admin::acl_delete()** | `acp/auth.php:1139` | After deleting permission entries |
| **Role removal** | `acp/acp_permission_roles.php:599` | After `remove_role()` |
| **Forum move** | `acp/acp_forums.php:103` | After moving forum |
| **Forum sync** | `acp/acp_forums.php:228` | After syncing forum permissions |
| **Forum delete** | `acp/acp_forums.php:798` | After deleting forum |
| **Group defaults** | `acp/acp_groups.php:632` | After setting group as default for users |
| **Admin cache purge** | `acp/acp_main.php:387` | ACP "Purge cache" button |
| **User group change** (ACP users) | `acp/acp_users.php:1019` | After changing user's default group |
| **User creation** | `functions_user.php:902` | `user_add()` — after inserting user ACL entries |
| **Group membership add** | `functions_user.php:2515` | `group_user_add()` |
| **Group membership del** | `functions_user.php:2889` | `group_user_del()` |
| **Group attribute set** | `functions_user.php:3075` | `group_user_attributes()` |
| **Group set default** | `functions_user.php:3364` | `group_set_user_default()` |
| **Migration tool** | `forums/db/migration/tool/permission.php:183` | Various migration permission adjustments |
| **CLI cache purge** | `forums/console/command/cache/purge.php:83` | CLI `cache:purge` command |

---

## 6. Permission Language Strings

### 6.1 Language Loading — `add_permission_language()`

**File:** `functions_admin.php`, line 3134

```php
function add_permission_language()
{
    $finder = $phpbb_extension_manager->get_finder();
    $lang_files = $finder
        ->prefix('permissions_')
        ->suffix(".php")
        ->core_path('src/phpbb/language/')
        ->extension_directory('/language')
        ->find();
    // Loads each file via $user->add_lang() or $user->add_lang_ext()
}
```

Finds all files named `permissions_*.php` in core language directory and extension language directories.

### 6.2 Permission Name Conventions

All permission options follow the pattern `{type}_{name}`:
- `f_read`, `f_post`, `f_reply` — forum permissions (33 options)
- `m_approve`, `m_edit`, `m_delete` — moderator permissions (12 local + 3 global-only: `m_ban`, `m_pm_report`, `m_warn`)
- `a_board`, `a_forum`, `a_user` — admin permissions (42 options)
- `u_attach`, `u_search`, `u_sendpm` — user permissions (35 options)

Translation through `\phpbb\permissions` service (`acl.permissions` in container):
- `get_permission_lang($permission)` — translates `f_read` to display string
- `get_type_lang($type, $scope)` — translates `f_` to "Forum permissions"
- `get_category_lang($cat)` — translates permission categories

---

## 7. `copy_forum_permissions()` — Forum Permission Cloning

**File:** `functions_admin.php`, line 354  
**Signature:** `copy_forum_permissions($src_forum_id, $dest_forum_ids, $clear_dest_perms = true, $add_log = true)`

**Flow:**
1. Validates source and destination forums exist
2. Queries `ACL_USERS_TABLE` for source forum → builds insert array for each dest forum
3. Queries `ACL_GROUPS_TABLE` for source forum → builds insert array for each dest forum
4. Within a DB transaction:
   - If `$clear_dest_perms=true`: DELETE existing dest forum entries from both tables
   - `sql_multi_insert()` the copied rows
5. Logs to admin log

Called from `acp_permissions::copy_forum_permissions()` (mode `setting_forum_copy`) which also triggers `phpbb_cache_moderators()` and `acl_clear_prefetch()`.

---

## 8. `phpbb_cache_moderators()` — Moderator Cache Rebuild

**File:** `functions_admin.php`, line 2532

Called whenever `m_` permissions change. Truncates and rebuilds `MODERATOR_CACHE_TABLE`:
1. Queries all users with `m_%` permissions via `acl_user_raw_data()`
2. Removes users who have group DENY overrides on those options
3. Does the same for group-based moderators
4. Inserts combined results into moderator cache table

---

## 9. Permission Trace

**File:** `acp_permissions.php`, `permission_trace()` (line ~1035)

Diagnostic tool (mode `trace`) that shows how a specific permission resolves for a user:
1. Default = `ACL_NO`
2. For each group the user belongs to: shows group-level setting, accumulates total
3. Shows user-specific direct setting
4. For local permissions: checks if the global version overrides
5. Founder override: founders always get `ACL_YES` for `a_` permissions

Logic: `ACL_NEVER` beats `ACL_YES`, `ACL_YES` beats `ACL_NO`, `ACL_NO` falls through.

---

## 10. `auth_admin` Constructor — ACL Options Cache

**File:** `acp/auth.php`, line ~22

The constructor builds/loads the `_acl_options` cache from `ACL_OPTIONS_TABLE`:

```php
$this->acl_options = [
    'global' => ['m_' => 0, 'm_approve' => 1, ...],  // Global scope options with bit indices
    'local'  => ['f_' => 0, 'f_announce' => 1, ...],  // Local scope options with bit indices
    'id'     => ['f_' => 1, 'f_announce' => 2, ...],  // Option name → auth_option_id
    'option' => [1 => 'f_', 2 => 'f_announce', ...],  // auth_option_id → option name
];
```

Cached as `_acl_options`. Total 125 options in this installation.

---

## 11. Validation Summary

Before any permission modification:
1. **CSRF protection** — `check_form_key('acp_permissions')` / `confirm_box()` for deletes
2. **Permission checks** — Requires:
   - `a_{type}auth` (e.g., `a_fauth` for forum perms, `a_mauth` for mod perms, `a_aauth` for admin perms)
   - `a_authusers` (to modify user perms) or `a_authgroups` (to modify group perms)
3. **Existence validation** — `check_existence()` verifies user/group/forum IDs exist in DB
4. **Role verification** — `check_assigned_role()` ensures the role's stored settings match what was submitted
5. **Name uniqueness** — Role names must be unique within their type

---

## 12. Database Tables Involved

| Table | Purpose |
|-------|---------|
| `phpbb_acl_options` | Master list of all permission options (125 rows) |
| `phpbb_acl_roles` | Role definitions (name, description, type, order) |
| `phpbb_acl_roles_data` | Role → option settings (role_id, auth_option_id, auth_setting) |
| `phpbb_acl_users` | Direct user permission grants (user_id, forum_id, auth_option_id/auth_role_id, auth_setting) |
| `phpbb_acl_groups` | Direct group permission grants (group_id, forum_id, auth_option_id/auth_role_id, auth_setting) |
| `phpbb_moderator_cache` | Cached moderator list (rebuilt by `phpbb_cache_moderators()`) |

---

## 13. Key Architectural Patterns

1. **Delete-then-insert** for all ACL writes — never UPDATE individual rows
2. **Role indirection** — when a role is assigned, only ONE row with `auth_option_id=0, auth_role_id=N` is stored; actual options live in `acl_roles_data`
3. **Role deletion converts to direct** — deleting a role re-materializes its settings as direct per-option entries
4. **Batch operations** use `$clear_prefetch=false` then manual `acl_clear_prefetch()` at end
5. **ACL_NO (0) is never stored** — only ACL_YES (1) and ACL_NEVER (-1) get rows; ACL_NO means "absent"
6. **Three-value logic** — ACL_YES (allow), ACL_NEVER (deny, can't be overridden), ACL_NO (not set, inherits)
