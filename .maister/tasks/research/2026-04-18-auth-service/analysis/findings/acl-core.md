# Findings: ACL Core — `phpbb\auth\auth` Class

**Source category**: `acl-core`
**Source file**: `src/phpbb/forums/auth/auth.php` (1139 lines)
**Research question**: How does the legacy phpBB ACL system work internally? Extract all ACL methods, understand the bitfield permission format, and document the cache mechanism.

---

## 1. Class Overview

```php
namespace phpbb\auth;

class auth
{
    var $acl = array();           // Decoded permission bitstrings: [forum_id => bitstring]
    var $cache = array();         // Runtime result cache: [forum_id][option] => bool
    var $acl_options = array();   // Option registry: ['global'][name]=>idx, ['local'][name]=>idx, ['id'][name]=>id, ['option'][id]=>name
    var $acl_forum_ids = false;   // Cached list of all forum_ids (lazy-loaded for negation queries)

    protected $container;         // \phpbb\Container — DI container for $db, $cache, $dispatcher access
}
```

**Key design trait**: The class mixes permission-checking (read path) with permission-building (write/cache path), authentication (login flow), and raw DB querying (admin tools). All in one God-class.

---

## 2. ACL Constants

Source: `src/phpbb/common/constants.php`

| Constant | Value | Meaning |
|----------|-------|---------|
| `ACL_NEVER` | `0` | Permission explicitly denied — **cannot be overridden** by any YES grant |
| `ACL_YES` | `1` | Permission explicitly granted |
| `ACL_NO` | `-1` | Permission not set / neutral — can be overridden by YES from another source |

**Critical insight**: `ACL_NEVER` (0) is the **strongest** setting. Once any source sets `ACL_NEVER`, no group or role grant can override it. This is the "NEVER wins" principle. `ACL_NO` (-1) is the "not set" default that can be overridden by `ACL_YES` from group inheritance.

---

## 3. Permission Types — Global vs Local

Permissions are categorized by prefix:

| Prefix | Scope | Description | Example |
|--------|-------|-------------|---------|
| `a_` | Global only | Admin permissions | `a_board`, `a_user` |
| `m_` | Global + Local | Moderator permissions | `m_edit`, `m_delete` |
| `u_` | Global only | User-level permissions | `u_sendpm`, `u_attach` |
| `f_` | Local only | Forum-specific permissions | `f_read`, `f_post`, `f_reply` |

**Global**: Stored in `$this->acl[0]` — forum_id = 0.
**Local/Forum-scoped**: Stored in `$this->acl[$forum_id]` for each forum.

Each option has `is_global` and `is_local` flags in `phpbb_acl_options`. Some options (e.g. `m_`) can be BOTH global and local.

---

## 4. Bitfield Format — Encoding/Decoding

### 4.1 Storage Format (`user_permissions` column)

The `user_permissions` column in `phpbb_users` is a `mediumtext` field containing a custom-encoded multi-line string:

```
<global_encoded>\n<forum1_encoded>\n\n<forum3_encoded>...
```

**Key rules**:
- Line number = forum_id. Line 0 = global permissions (forum_id=0).
- Empty lines represent forums with no permissions set — the line index IS the forum_id.
- Each line contains base-36 encoded chunks representing the permission bitstring.

### 4.2 Encoding (`build_bitstring`)

Source: Lines 462-520

**Algorithm**:
1. For each forum_id (or 0 for global), iterate all options in order (global or local set).
2. Build a binary string: each position = one permission option. Value is `ACL_YES(1)` or `ACL_NEVER(0)`.
3. **Auto-set category flags**: If any `a_foo` is YES, automatically set the `a_` flag to YES (unless it's NEVER).
4. Implode into a single binary string (e.g., `"1001010110..."`).
5. **Pad to 31-bit boundary**: chunks of 31 bits (padded right with 0s).
6. Convert each 31-bit chunk from base-2 to base-36, pad to 6 chars.
7. Concatenate all 6-char chunks for that forum line.
8. Separate forum lines with `\n`. Use extra `\n` for gaps (empty forum_ids).

```php
// Encoding: 31 bits → 6 chars base-36
for ($i = 0; $i < strlen($bitstring); $i += 31)
{
    $hold_str .= str_pad(base_convert(str_pad(substr($bitstring, $i, 31), 31, 0, STR_PAD_RIGHT), 2, 36), 6, 0, STR_PAD_LEFT);
}
```

**Why 31 bits?** PHP's `base_convert()` uses signed 32-bit integers internally. Using 31 bits avoids overflow/sign issues on 32-bit PHP builds.

**Why base-36?** 31 bits = max value 2,147,483,647. In base-36 this fits in at most 6 characters (`zzzzzz` = 2,176,782,335). This is a compact text-safe encoding.

### 4.3 Decoding (`_fill_acl`)

Source: Lines 139-174

**Algorithm**:
1. Split `user_permissions` by `\n` — each element is a forum line. Array index = forum_id.
2. For each non-empty line, process in 6-char chunks.
3. Convert each chunk: base-36 → base-2, pad left to 31 bits.
4. Concatenate all decoded chunks → full binary permission string.
5. Store in `$this->acl[$forum_id]` as a string where each char position maps to an option index.

```php
// Decoding: 6 chars base-36 → 31 bits binary
$converted = str_pad(base_convert($subseq, 36, 2), 31, 0, STR_PAD_LEFT);
```

**Optimization**: Uses `$seq_cache` array to memoize decoded 6-char chunks — same chunk pattern across forums only decoded once.

### 4.4 Bitstring Validation

After `_fill_acl()`, the `acl()` method validates bitstring lengths:

```php
$global_length = count($this->acl_options['global']);
// Pad to nearest 31-bit boundary
$global_length = ($global_length % 31) ? ($global_length - ($global_length % 31) + 31) : $global_length;
```

If any `$this->acl[$f]` bitstring length doesn't match the expected padded length, it triggers a full `acl_cache()` rebuild. This handles the case where new permission options were added after the cache was built.

---

## 5. Public Methods — Complete API

### 5.1 `acl(&$userdata)` — Initialize Permissions

**Signature**: `function acl(&$userdata): void`
**Source**: Lines 37-110

**Purpose**: Load and decode all permission data for a user into the in-memory `$this->acl` array.

**Flow**:
1. Reset all internal arrays (`$this->acl`, `$this->cache`, `$this->acl_options`).
2. Load `acl_options` from cache key `'_acl_options'`. If cache miss, query `ACL_OPTIONS_TABLE` and build:
   - `$acl_options['global'][$name] = sequential_index`
   - `$acl_options['local'][$name] = sequential_index`
   - `$acl_options['id'][$name] = auth_option_id`
   - `$acl_options['option'][$id] = name`
3. If `$userdata['user_permissions']` is empty → call `acl_cache($userdata)` to build it.
4. Call `_fill_acl($userdata['user_permissions'])` to decode the bitstring.
5. Validate bitstring lengths against option counts. If mismatch → rebuild via `acl_cache()` + re-fill.

**SQL**: `SELECT auth_option_id, auth_option, is_global, is_local FROM phpbb_acl_options ORDER BY auth_option_id`

---

### 5.2 `acl_get($opt, $f = 0)` — Check Single Permission

**Signature**: `function acl_get($opt, $f = 0): bool`
**Source**: Lines 178-218

**Purpose**: Check if user has a specific permission, optionally for a specific forum.

**Flow**:
1. Handle `!` prefix for negation (e.g., `!f_read` returns true if user does NOT have `f_read`).
2. Check runtime `$this->cache[$f][$opt]` — return cached result if available.
3. **Global check**: If option exists in `$this->acl_options['global']`, check `$this->acl[0][$global_index]`.
4. **Local check**: If `$f != 0` AND option exists in `$this->acl_options['local']`, also check `$this->acl[$f][$local_index]`.
5. **Combine with OR**: Global and local are OR'd together. If user has global permission, they have it in every forum.
6. **Founder override**: Comment says "Founder always has all global options set to true" — but the actual founder override happens in `acl_cache()` at cache-build time, not here.
7. Return negated result if `!` prefix was used.

**Key insight**: The result is a single bit from the pre-computed bitstring, not a DB query. This is O(1) for cached checks.

---

### 5.3 `acl_getf($opt, $clean = false)` — Get Forums With Permission

**Signature**: `function acl_getf($opt, $clean = false): array`
**Source**: Lines 227-305

**Purpose**: Get array of all forums where user has (or doesn't have) a specific permission.

**Flow**:
1. Handle `!` prefix for negation.
2. If negated, lazy-load `$this->acl_forum_ids` (all forum IDs not already in `$this->acl`) via `SELECT forum_id FROM phpbb_forums`.
3. Iterate all forum-keyed entries in `$this->acl` (skip `$f == 0` global).
4. For each forum, call `acl_get($opt, $f)` (which uses the O(1) bitstring lookup).
5. If `$clean == true`, only include forums where permission is actually set (omit false entries).
6. For negated queries, add remaining forum IDs not in `$this->acl`.

**Returns**: `array[$forum_id][$opt] => bool`

**SQL** (only for negated queries): `SELECT forum_id FROM phpbb_forums WHERE forum_id NOT IN (...)`

---

### 5.4 `acl_getf_global($opt)` — Check If Set In Any Forum

**Signature**: `function acl_getf_global($opt): bool`
**Source**: Lines 313-349

**Purpose**: Returns `true` if user has permission in at least one forum. Also handles arrays of options.

**Flow**:
1. If `$opt` is array → recursively call for each, return true on first match.
2. If option is local: iterate all forums in `$this->acl`, return true on first `acl_get()` hit.
3. If option is global only: just call `acl_get($opt)` (global check).

**Key insight**: Short-circuits on first true — efficient for "has moderator access to any forum" checks.

---

### 5.5 `acl_gets()` — Check Multiple Permissions

**Signature**: `function acl_gets(...$args): int`
**Source**: Lines 354-380

**Purpose**: Check if user has ANY of the specified permissions. Variadic.

**Flow**:
1. Last argument may be a forum_id (numeric). If not numeric, it's treated as another option name.
2. Supports alternate syntax: `acl_gets(array('m_', 'a_'), $forum_id)`.
3. OR all results: `$acl |= $this->acl_get($opt, $f)`.

**Returns**: `int` (0 or 1) — truthy if ANY permission is set.

---

### 5.6 `acl_get_list($user_id, $opts, $forum_id)` — Permission List

**Signature**: `function acl_get_list($user_id = false, $opts = false, $forum_id = false): array`
**Source**: Lines 393-419

**Purpose**: Get which users have which permissions in which forums. Used by admin tools.

**Flow**:
1. If only `$user_id` is given (no opts/forum): use optimized `acl_raw_data_single_user()`.
2. Otherwise: use `acl_raw_data()` for multi-user/multi-option/multi-forum query.
3. Restructure result: `[$forum_id][$auth_option][] = $user_id` (only where `$auth_setting` is truthy).

**Warning** (from code comment): This function may give incorrect results when mixing category flags (e.g., `a_`) with NEVER overrides. If a group grants `a_foo` but user has `a_foo = NEVER`, the user still appears in the `a_` list. Known bug: PHPBB3-10252.

---

### 5.7 `acl_cache(&$userdata)` — Build + Store Cache

**Signature**: `function acl_cache(&$userdata): void`
**Source**: Lines 424-455

**Purpose**: Build the complete permission bitstring for a user by querying all ACL sources, encode it, and store in `phpbb_users.user_permissions`.

**Flow**:
1. Clear `$userdata['user_permissions']`.
2. Call `acl_raw_data_single_user($userdata['user_id'])` — collects ALL permission data from all sources.
3. **Founder override**: If `$userdata['user_type'] == USER_FOUNDER` (constant = 3), force ALL `a_*` options to `ACL_YES`.
4. Call `build_bitstring($hold_ary)` to encode.
5. Write encoded string to `phpbb_users.user_permissions` via UPDATE.

**SQL**: `UPDATE phpbb_users SET user_permissions = '...', user_perm_from = 0 WHERE user_id = X`

**Key insight**: Founder users ALWAYS get all admin permissions, regardless of any NEVER grants.

---

### 5.8 `build_bitstring(&$hold_ary)` — Encode Permissions

**Signature**: `function build_bitstring(&$hold_ary): string`
**Source**: Lines 460-520

**Purpose**: Convert a `[forum_id][option_id] => setting` array into the packed base-36 encoded string.

**Flow** (detailed in Section 4.2 above):
1. Sort by forum_id (ksort).
2. For each forum, iterate ALL options in order.
3. Map settings to binary (YES=1, anything else=0). Note: only YES maps to 1 — NEVER(0) and NO(-1) both become 0 in the bitstring.
4. **Auto-set category flags**: If `a_foo = YES`, set `a_ = YES` too (unless `a_` is already NEVER).
5. Use `\n` padding for forum_id gaps.
6. Convert 31-bit chunks to base-36 (6-char padded).

**Critical insight**: The bitstring is a **boolean** representation — only YES(1) vs NOT-YES(0). The three-way ACL_NEVER/ACL_YES/ACL_NO distinction is resolved during the build phase and collapsed to binary.

---

### 5.9 `acl_clear_prefetch($user_id = false)` — Invalidate Cache

**Signature**: `function acl_clear_prefetch($user_id = false): void`
**Source**: Lines 525-580

**Purpose**: Clear cached permissions so they are rebuilt on next access.

**Flow**:
1. Destroy role cache: `$cache->destroy('_role_cache')`.
2. Rebuild role cache: query ALL from `phpbb_acl_roles_data`, serialize per role_id, store via `$cache->put('_role_cache', ...)`.
3. Clear `user_permissions` column: `UPDATE phpbb_users SET user_permissions = '', user_perm_from = 0 [WHERE user_id IN (...)]`.
4. Dispatch event `core.acl_clear_prefetch_after`.

**If `$user_id` is false**: Clears ALL users' cached permissions (global invalidation).
**If `$user_id` is provided**: Clears only specified user(s).

**Side effect**: Always rebuilds the role cache, even for single-user invalidation. This is somewhat expensive.

#### Call Sites (18 found across codebase):

| File | Context |
|------|---------|
| `acp_forums.php` (×3) | Forum creation, modification, deletion |
| `acp_permissions.php` (×2) | Permission set/remove operations |
| `acp_groups.php` | Group permission changes |
| `acp_users.php` | User permission changes |
| `acp_main.php` | Resynchronize/purge cache |
| `functions_user.php` (×5) | User group add/remove, user delete, user move |
| `acp/auth.php` (×4) | Legacy admin auth class — set/remove permissions |
| `acp_permission_roles.php` | Role modification |

---

### 5.10 `acl_role_data($user_type, $role_type, $ug_id, $forum_id)` — Get Assigned Roles

**Signature**: `function acl_role_data($user_type, $role_type, $ug_id = false, $forum_id = false): array`
**Source**: Lines 585-612

**Purpose**: Get roles assigned to users or groups, used by ACP permission management UI.

**SQL**: Joins `ACL_USERS_TABLE` (or `ACL_GROUPS_TABLE`) with `ACL_ROLES_TABLE` filtered by `role_type`.

**Returns**: `[$ug_id][$forum_id] => $role_id`

---

### 5.11 `acl_raw_data($user_id, $opts, $forum_id)` — Raw DB Query (Multi-User)

**Signature**: `function acl_raw_data($user_id = false, $opts = false, $forum_id = false): array`
**Source**: Lines 617-730

**Purpose**: Get raw permission data from DB for one or more users. Used by admin permission listing and by `acl_get_list()`.

**Flow** — Executes 4 separate SQL queries:
1. **User direct grants (non-role)**: `ACL_USERS_TABLE WHERE auth_role_id = 0`
2. **User role grants**: `ACL_USERS_TABLE JOIN ACL_ROLES_DATA_TABLE`
3. **Group direct grants (non-role)**: `ACL_GROUPS_TABLE JOIN USER_GROUP_TABLE JOIN GROUPS_TABLE WHERE auth_role_id = 0`
4. **Group role grants**: `ACL_GROUPS_TABLE JOIN USER_GROUP_TABLE JOIN GROUPS_TABLE JOIN ACL_ROLES_DATA_TABLE`

**NEVER override logic** (in group queries):
```php
if (!isset($hold_ary[...]) || $hold_ary[...] != ACL_NEVER)
{
    $hold_ary[...] = $setting;
    // If NEVER detected, unset the category flag
}
```
User-specific grants are processed first, then group grants. If user already has `ACL_NEVER` for an option, group grants CANNOT override it.

**Group filtering**: `ug.user_pending = 0 AND NOT (ug.group_leader = 1 AND g.group_skip_auth = 1)` — excludes pending members and group leaders with skip_auth.

**Returns**: `[$user_id][$forum_id][$option_name] => $auth_setting`

---

### 5.12 `acl_user_raw_data($user_id, $opts, $forum_id)` — User-Only Raw Data

**Signature**: `function acl_user_raw_data($user_id = false, $opts = false, $forum_id = false): array`
**Source**: Lines 735-780

**Purpose**: Same as `acl_raw_data()` but ONLY user-specific grants (no group inheritance). Used by ACP permission UI.

**Queries**: 2 SQL queries (non-role user grants + role user grants).

**Returns**: `[$user_id][$forum_id][$option_name] => $auth_setting`

---

### 5.13 `acl_group_raw_data($group_id, $opts, $forum_id)` — Group-Only Raw Data

**Signature**: `function acl_group_raw_data($group_id = false, $opts = false, $forum_id = false): array`
**Source**: Lines 785-838

**Purpose**: Get raw permission data for groups only (no user-level grants). Used by ACP group permission management.

**Extra filter**: `AND ao.is_local <> 0` when forum_id is specified — only returns local permissions.

**Returns**: `[$group_id][$forum_id][$option_name] => $auth_setting`

---

### 5.14 `acl_raw_data_single_user($user_id)` — All Permissions for One User (Optimized)

**Signature**: `function acl_raw_data_single_user($user_id): array`
**Source**: Lines 843-930

**Purpose**: Get complete permission data for a single user — this is the primary data source for `acl_cache()`. Optimized path using the cached role data.

**Flow**:
1. Load `_role_cache` from application cache (or rebuild from `ACL_ROLES_DATA_TABLE` if miss).
2. Query user-specific grants: `SELECT forum_id, auth_option_id, auth_role_id, auth_setting FROM ACL_USERS_TABLE WHERE user_id = X`.
   - If `auth_role_id` set → unserialize entire role's options from cache and merge.
   - If no role → set single option directly.
3. Query group grants: `SELECT FROM ACL_GROUPS_TABLE JOIN USER_GROUP_TABLE JOIN GROUPS_TABLE WHERE ug.user_id = X`.
   - For each grant, call `_set_group_hold_ary()` which enforces the NEVER-wins rule.

**Returns**: `[$forum_id][$option_id] => $auth_setting` (keyed by option_id, NOT option_name)

**Key difference from `acl_raw_data()`**: Returns option_id keys (not names), no user_id wrapper, and uses role cache.

---

### 5.15 `_set_group_hold_ary(&$hold_ary, $option_id, $setting)` — Merge Group Permission

**Signature**: `function _set_group_hold_ary(&$hold_ary, $option_id, $setting): void`
**Source**: Lines 935-960

**Purpose**: Merge a single group permission into the hold array, respecting the NEVER-wins rule.

**Algorithm**:
```
IF option not set yet OR option != ACL_NEVER:
    SET option = setting
    IF setting == ACL_NEVER:
        Find category flag (e.g., "a_" for "a_foo")
        IF flag == ACL_YES: UNSET flag
```

**Critical rule**: Once `ACL_NEVER` is set (from any source — user or group), it cannot be overridden:
- `!isset` → accept any setting
- `isset && != ACL_NEVER` → accept new setting (may override ACL_YES or ACL_NO)
- `isset && == ACL_NEVER` → reject (keeps NEVER)

**Category flag cleanup**: When an option gets NEVER, the parent category flag (e.g., `a_`) is unset if it was YES. The flag is recalculated correctly during `build_bitstring()`.

---

### 5.16 `obtain_user_data($user_id)` — Fetch User Row for ACL

**Signature**: `public function obtain_user_data($user_id): array`
**Source**: Lines 117-130

**Purpose**: Simple query to get `user_id, username, user_permissions, user_type` from `phpbb_users`.

**SQL**: `SELECT user_id, username, user_permissions, user_type FROM phpbb_users WHERE user_id = X`

**Note**: Uses `user_id` directly in SQL without parameterized query — relies on integer casting elsewhere. Potential security concern if `$user_id` is not validated.

---

### 5.17 `login($username, $password, $autologin, $viewonline, $admin)` — Authentication

**Signature**: `function login($username, $password, $autologin = false, $viewonline = 1, $admin = 0): array`
**Source**: Lines 965-1095

**Purpose**: Authenticate user via auth provider plugin system.

**Flow**:
1. Get configured auth provider from service container (`auth.provider_collection`).
2. Call `$provider->login($username, $password)`.
3. Handle special statuses: `LOGIN_SUCCESS_CREATE_PROFILE` (auto-create), `LOGIN_SUCCESS_LINK_PROFILE` (redirect to link flow).
4. Dispatch event `core.auth_login_session_create_before`.
5. On `LOGIN_SUCCESS`: Create session via `$user->session_create()`.
6. For admin login: Clear old cookies, delete old session row.

**Login constants** (from constants.php):

| Constant | Value | Meaning |
|----------|-------|---------|
| `LOGIN_BREAK` | 2 | Login halted (e.g., forced logout) |
| `LOGIN_SUCCESS` | 3 | Login successful |
| `LOGIN_SUCCESS_CREATE_PROFILE` | 20 | Success, but profile needs creation |
| `LOGIN_SUCCESS_LINK_PROFILE` | 21 | Success, but account linking needed |
| `LOGIN_ERROR_USERNAME` | 10 | Username not found |
| `LOGIN_ERROR_PASSWORD` | 11 | Wrong password |
| `LOGIN_ERROR_ACTIVE` | 12 | Account not active |
| `LOGIN_ERROR_ATTEMPTS` | 13 | Too many login attempts |
| `LOGIN_ERROR_EXTERNAL_AUTH` | 14 | External auth failure |
| `LOGIN_ERROR_PASSWORD_CONVERT` | 15 | Password needs conversion (migration) |

---

### 5.18 `build_auth_option_statement($key, $auth_options, &$sql_opts)` — SQL Builder Helper

**Signature**: `function build_auth_option_statement($key, $auth_options, &$sql_opts): void`
**Source**: Lines 1100-1139

**Purpose**: Build SQL WHERE clause fragments for filtering by auth option names. Supports exact match, LIKE with `%`, arrays, and mixed.

**Output**: Populates `$sql_opts` by reference with SQL fragment like `AND ao.auth_option = 'f_read'` or `AND ao.auth_option LIKE 'f_%'`.

---

## 6. Permission Resolution Algorithm — Complete Flow

### 6.1 Cache Build Phase (`acl_raw_data_single_user` → `acl_cache` → `build_bitstring`)

1. **Collect user direct grants** (from `phpbb_acl_users`):
   - Role-based: unserialize all options from cached role data
   - Direct: set individual `[forum_id][option_id] = setting`

2. **Collect group grants** (from `phpbb_acl_groups` JOIN `phpbb_user_group`):
   - For each group the user belongs to (non-pending, respecting `group_skip_auth`):
     - Role-based: unserialize role, merge each option via `_set_group_hold_ary()`
     - Direct: merge via `_set_group_hold_ary()`

3. **Merge algorithm** (`_set_group_hold_ary`):
   - **NEVER wins**: If option is already `ACL_NEVER`, no group grant can change it.
   - **Last-write-wins** (otherwise): Later group grants overwrite earlier ones (except NEVER).
   - **User grants processed first**: So user NEVER blocks all subsequent group grants.
   - **Category flag management**: When NEVER is set, clears parent `a_`/`m_`/`f_`/`u_` flag.

4. **Founder override**: If `user_type == USER_FOUNDER(3)`, force all `a_*` globals to `ACL_YES`. This happens AFTER the merge, overriding even NEVER for admin options.

5. **Encode to bitstring** (`build_bitstring`):
   - Map to binary: `ACL_YES → 1`, everything else → `0`.
   - Auto-compute category flags: if any `a_foo` is YES and `a_` isn't NEVER, set `a_ = 1`.
   - Pack via 31-bit chunks → base-36 → 6-char strings.
   - Join forums with `\n`.

6. **Store**: Write to `phpbb_users.user_permissions` column.

### 6.2 Read Phase (`_fill_acl` → `acl_get`)

1. **Decode** (`_fill_acl`): Split by `\n`, decode each line from base-36 → binary. Store as `$this->acl[$forum_id] = "10010110..."`.

2. **Lookup** (`acl_get`): Direct array index access — `$this->acl[$forum_id][$option_index]`. Returns `'1'` or `'0'` (string chars), treated as boolean.

3. **Global + Local OR**: For forum-specific checks, global and local results are OR'd. If user has global `m_edit`, they have it in every forum.

4. **Runtime cache**: Results cached in `$this->cache[$forum_id][$opt]` to avoid repeated string indexing.

### 6.3 Resolution Priority Summary

```
Priority (highest to lowest):
1. FOUNDER override (a_* always YES for founders)
2. ACL_NEVER from ANY source (user direct or group) — immutable
3. ACL_YES from user direct grant
4. ACL_YES from group/role grant
5. ACL_NO (-1) — treated as "not set", becomes 0 in bitstring
```

---

## 7. Cache Mechanism — Detailed Analysis

### 7.1 Two Cache Layers

| Cache | Storage | Key | Content | Invalidation |
|-------|---------|-----|---------|--------------|
| **Permission options** | phpBB file cache | `_acl_options` | Map of all option names ↔ IDs ↔ indices | Rarely — only when options added/removed |
| **Role data** | phpBB file cache | `_role_cache` | Serialized `[role_id][option_id] => setting` | On `acl_clear_prefetch()` |
| **User permissions** | DB column | `phpbb_users.user_permissions` | Base-36 encoded bitstring | On `acl_clear_prefetch($user_id)` |
| **Runtime lookups** | PHP memory | `$this->cache[$f][$opt]` | Boolean results | On `acl()` init (reset per request) |

### 7.2 Cache Build Trigger

`user_permissions` is built lazily: `acl()` checks if the column is empty, and if so calls `acl_cache()`. This means:
- After `acl_clear_prefetch()`, the next page load triggers a rebuild.
- The rebuild queries 4+ SQL queries (user grants, group grants, role cache).
- For a cleared ALL-users invalidation, every user's next request triggers their own cache rebuild.

### 7.3 `user_perm_from` Column

```php
$sql = "UPDATE phpbb_users SET user_permissions = '...', user_perm_from = 0 WHERE user_id = X";
```

The `user_perm_from` column tracks "permission copying" — when one user uses another's permission set. Always reset to 0 on cache rebuild/clear. This is a legacy admin feature for applying one user's permissions to another.

---

## 8. Edge Cases and Gotchas

### 8.1 NEVER Cannot Be Overridden
Once any source (user grant or ANY group) sets `ACL_NEVER(0)` for an option, no other source can change it. This is by design but can be confusing: an admin might grant a role with YES, but if a user has NEVER from another group, the YES is ignored.

### 8.2 Category Flag Auto-Calculation Can Be Wrong
The `acl_get_list()` method has a known bug (PHPBB3-10252): category flags (`a_`, `m_`, etc.) may show YES even when all specific options under that category are NEVER'd. This is because the flag is auto-set during `build_bitstring()` based on ANY sub-option being YES, but doesn't properly re-check after NEVER overrides.

### 8.3 Group Processing Order Matters
In `_set_group_hold_ary()`, the merge is "NEVER wins, otherwise last-write-wins." Since SQL doesn't guarantee group processing order, the effective permission from multiple groups (when none is NEVER) depends on DB query order. This is usually not a problem because most decisions are binary (YES vs nothing).

### 8.4 `obtain_user_data()` SQL Injection Risk
```php
$sql = 'SELECT ... FROM ' . USERS_TABLE . ' WHERE user_id = ' . $user_id;
```
The `$user_id` parameter is not cast to int here — callers must ensure it's safe. Though the method is `public`, it relies on trusted input.

### 8.5 Bitstring Length Validation
The `acl()` method actively detects stale bitstrings (wrong length) and triggers a rebuild. This safely handles the scenario where new permission options are added (e.g., by extensions) without manually clearing all caches.

### 8.6 `acl_clear_prefetch()` Always Rebuilds Role Cache
Even when clearing a single user's permissions, the entire role cache is rebuilt. This is unnecessary overhead for single-user operations but ensures consistency.

### 8.7 PHP 31-bit Limitation
The 31-bit chunk size is a workaround for PHP's `base_convert()` using signed 32-bit integers. On 64-bit PHP, larger chunks would be possible but this legacy encoding remains.

### 8.8 Forum ID Encoding via Line Position
Empty forum IDs (gaps) are encoded as empty lines (`\n`). For boards with sparse forum IDs (e.g., forum_id jumps from 5 to 500), this creates ~495 empty newlines in the `user_permissions` text. The field is `mediumtext` so this rarely hits limits, but it's wasteful.

---

## 9. Summary — Key Architecture Facts

| Aspect | Detail |
|--------|--------|
| **Class** | `phpbb\auth\auth` — God-class: ACL + auth + admin queries |
| **Read path** | O(1) bitstring index lookup after decode |
| **Write path** | 4+ SQL queries to build, one UPDATE to store |
| **Encoding** | Binary → 31-bit chunks → base-36 → 6-char strings, `\n`-separated per forum |
| **Resolution** | NEVER wins → Founder override (a_ only) → YES from any source → default NO |
| **Cache invalidation** | `acl_clear_prefetch()` clears DB column + rebuilds role cache |
| **Lazy rebuild** | Next `acl()` call after invalidation triggers full rebuild |
| **Global/Local OR** | Global permission in forum_id=0 applies to ALL forums |
| **Category flags** | Auto-computed: `a_` = YES if any `a_*` = YES (unless NEVER) |
| **Total methods** | 15 public + 2 private (complete listing above) |

---

## 10. Confidence Assessment

| Finding | Confidence |
|---------|------------|
| Bitfield format (31-bit, base-36, newline-separated) | **High (100%)** — directly from code |
| NEVER-wins resolution algorithm | **High (100%)** — confirmed in `_set_group_hold_ary()` and `acl_raw_data()` |
| Founder override for `a_*` | **High (100%)** — explicit in `acl_cache()` lines 440-446 |
| Cache invalidation mechanism | **High (100%)** — all call sites traced (18 locations) |
| Global/Local OR combining | **High (100%)** — explicit in `acl_get()` lines 195-212 |
| Category flag auto-set bug | **High (95%)** — confirmed by code comment referencing PHPBB3-10252 |
| Group processing order non-determinism | **Medium (80%)** — inferred from SQL without ORDER BY on group queries |
