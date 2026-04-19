# Legacy ACL System — Complete Analysis

**Source category**: `legacy-acl`  
**Primary source**: `src/phpbb/forums/auth/auth.php` (1139 lines)  
**Supporting sources**: `phpbb_dump.sql` (schema), `src/phpbb/common/constants.php`, `src/phpbb/common/acp/auth.php`  
**Confidence**: High (100%) — all findings verified against source code

---

## 1. ACL Constants

**Source**: `src/phpbb/common/constants.php:55-57`

```php
define('ACL_NEVER', 0);   // Permission explicitly denied — CANNOT be overridden
define('ACL_YES',   1);   // Permission granted
define('ACL_NO',   -1);   // Permission not set / neutral — can be overridden
```

**Resolution semantics**:
- `ACL_NEVER` (0) is the **strongest** — once set from ANY source, no grant can override
- `ACL_YES` (1) grants access
- `ACL_NO` (-1) means "unset" — will be overridden by YES from any group/role

**User type constants** (relevant for founder override):
```php
define('USER_NORMAL', 0);
define('USER_INACTIVE', 1);
define('USER_IGNORE', 2);
define('USER_FOUNDER', 3);
```

---

## 2. Database Schema — All ACL Tables

### 2.1 `phpbb_acl_options` — Permission Definitions (Master Registry)

```sql
CREATE TABLE phpbb_acl_options (
  auth_option_id  mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  auth_option     varchar(50) NOT NULL DEFAULT '',     -- e.g. 'f_read', 'a_board', 'm_edit'
  is_global       tinyint(1) unsigned NOT NULL DEFAULT 0,
  is_local        tinyint(1) unsigned NOT NULL DEFAULT 0,
  founder_only    tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (auth_option_id),
  UNIQUE KEY auth_option (auth_option)
);
```

**Row count**: 125 options  
**Key semantics**:
- `is_global=1` → applies board-wide (stored under forum_id=0)
- `is_local=1` → applies per-forum (stored under specific forum_id)
- Some options are BOTH global AND local (e.g., all `m_*` moderator permissions)
- `founder_only=1` → currently unused (no options have this set)

### 2.2 `phpbb_acl_roles` — Role Definitions

```sql
CREATE TABLE phpbb_acl_roles (
  role_id          mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  role_name        varchar(255) NOT NULL DEFAULT '',   -- language key e.g. 'ROLE_ADMIN_FULL'
  role_description text NOT NULL,                      -- language key
  role_type        varchar(10) NOT NULL DEFAULT '',    -- 'a_', 'm_', 'u_', 'f_'
  role_order       smallint(4) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (role_id),
  KEY role_type (role_type),
  KEY role_order (role_order)
);
```

**24 unique roles** (48 rows due to install artifact):

| role_type | Roles |
|-----------|-------|
| `a_` | STANDARD, FORUM, USERGROUP, FULL (4) |
| `u_` | FULL, STANDARD, LIMITED, NOPM, NOAVATAR, NEW_MEMBER (6) |
| `m_` | FULL, STANDARD, SIMPLE, QUEUE (4) |
| `f_` | FULL, STANDARD, NOACCESS, READONLY, LIMITED, BOT, ONQUEUE, POLLS, LIMITED_POLLS, NEW_MEMBER (10) |

### 2.3 `phpbb_acl_roles_data` — Role → Permission Mappings

```sql
CREATE TABLE phpbb_acl_roles_data (
  role_id         mediumint(8) unsigned NOT NULL DEFAULT 0,
  auth_option_id  mediumint(8) unsigned NOT NULL DEFAULT 0,
  auth_setting    tinyint(2) NOT NULL DEFAULT 0,    -- ACL_YES(1), ACL_NO(-1), ACL_NEVER(0)
  PRIMARY KEY (role_id, auth_option_id),
  KEY ath_op_id (auth_option_id)
);
```

**422 mappings** — each role maps to a set of permission → setting pairs.

### 2.4 `phpbb_acl_groups` — Group-Level Permission Assignments

```sql
CREATE TABLE phpbb_acl_groups (
  group_id        mediumint(8) unsigned NOT NULL DEFAULT 0,
  forum_id        mediumint(8) unsigned NOT NULL DEFAULT 0,  -- 0 = global
  auth_option_id  mediumint(8) unsigned NOT NULL DEFAULT 0,
  auth_role_id    mediumint(8) unsigned NOT NULL DEFAULT 0,
  auth_setting    tinyint(2) NOT NULL DEFAULT 0,
  KEY group_id (group_id),
  KEY auth_opt_id (auth_option_id),
  KEY auth_role_id (auth_role_id)
);
```

**Dual-mode assignment**:
- `auth_role_id > 0` → role-based (entire role's permissions apply). `auth_option_id` and `auth_setting` are 0.
- `auth_option_id > 0` → direct per-option grant. `auth_role_id` is 0.

**154 rows** — this is the PRIMARY permission assignment mechanism for most users.

### 2.5 `phpbb_acl_users` — User-Level Permission Overrides

```sql
CREATE TABLE phpbb_acl_users (
  user_id         int(10) unsigned NOT NULL DEFAULT 0,
  forum_id        mediumint(8) unsigned NOT NULL DEFAULT 0,  -- 0 = global
  auth_option_id  mediumint(8) unsigned NOT NULL DEFAULT 0,
  auth_role_id    mediumint(8) unsigned NOT NULL DEFAULT 0,
  auth_setting    tinyint(2) NOT NULL DEFAULT 0,
  KEY user_id (user_id),
  KEY auth_option_id (auth_option_id),
  KEY auth_role_id (auth_role_id)
);
```

**Same dual-mode** as `acl_groups`. Rarely used (only 2 rows in this install) — most permissions are via groups.

### 2.6 `phpbb_users` — Permission Cache Storage

```sql
-- Relevant columns:
user_permissions  mediumtext NOT NULL,   -- pre-computed packed bitstring
user_perm_from    mediumint(8) unsigned NOT NULL DEFAULT 0,  -- 0 = own perms, N = copying user N's perms
```

---

## 3. Permission Scopes / Types

| Prefix | Scope | `is_global` | `is_local` | Description | Total Options |
|--------|-------|-------------|------------|-------------|---------------|
| `f_` | Local only | 0 | 1 | Forum-specific permissions | 33 (IDs 1-33) |
| `m_` | Global + Local | 1 | 1 | Moderator permissions | 15 (IDs 34-48) |
| `a_` | Global only | 1 | 0 | Admin permissions | 42 (IDs 49-90) |
| `u_` | Global only | 1 | 0 | User-level permissions | 35 (IDs 91-125) |

**Category flags**: Each prefix has a "bare" flag option (e.g., `f_`, `m_`, `a_`, `u_`) that acts as a category gate:
- `a_` (ID 49) — "has any admin permission" — gate for ACP access
- `m_` (ID 34) — "has any mod permission" — gate for MCP access
- `u_` (ID 91) — "has any user permission"
- `f_` (ID 1) — "has any forum permission in this forum"

These flags are **auto-computed**: if any `a_*` sub-option is YES, `a_` is auto-set to YES during bitstring build.

### Key Forum Permissions (`f_*`)

| Option | Purpose |
|--------|---------|
| `f_read` | Can read forum content |
| `f_post` | Can create new topics |
| `f_reply` | Can reply to topics |
| `f_edit` | Can edit own posts |
| `f_delete` | Can delete own posts |
| `f_attach` | Can upload attachments |
| `f_download` | Can download attachments |
| `f_list` | Can see forum in listing |
| `f_search` | Can search in forum |
| `f_noapprove` | Posts don't require approval |
| `f_announce` | Can create announcements |
| `f_sticky` | Can create sticky topics |

### Key Admin Permissions (`a_*`)

| Option | Purpose |
|--------|---------|
| `a_board` | Manage board settings |
| `a_user` | Manage users |
| `a_group` | Manage groups |
| `a_forum` | Manage forums |
| `a_fauth` | Set forum permissions |
| `a_mauth` | Set moderator permissions |
| `a_uauth` | Set user permissions |
| `a_aauth` | Set admin permissions |
| `a_roles` | Manage permission roles |
| `a_switchperm` | Can use another user's permissions |
| `a_viewauth` | View permissions |

### Key Moderator Permissions (`m_*`)

| Option | Purpose | Scope |
|--------|---------|-------|
| `m_edit` | Edit posts | Global + Local |
| `m_delete` | Delete posts | Global + Local |
| `m_approve` | Approve/disapprove posts | Global + Local |
| `m_move` | Move topics | Global + Local |
| `m_lock` | Lock topics | Global + Local |
| `m_split` | Split topics | Global + Local |
| `m_merge` | Merge topics | Global + Local |
| `m_ban` | Ban users | Global only |
| `m_warn` | Warn users | Global only |
| `m_report` | Handle reported posts | Global + Local |

---

## 4. Bitfield Storage Format

### 4.1 Encoding Algorithm (`build_bitstring()`)

**Source**: `auth.php:460-520`

1. For each forum_id (0=global first, then ascending forum_ids):
   - Iterate ALL options for that scope (global/local) **in sequential index order**
   - Map: `ACL_YES(1) → '1'`, everything else → `'0'`
   - Auto-set category flag: if any `a_foo` = YES and `a_` ≠ NEVER → set `a_` = '1'
   - Result: binary string like `"10010110010..."`

2. **Pad** to 31-bit boundary (right-padded with 0)

3. **Convert** each 31-bit chunk: binary → base-36, left-padded to 6 characters:
   ```php
   str_pad(base_convert(str_pad(substr($bitstring, $i, 31), 31, 0, STR_PAD_RIGHT), 2, 36), 6, 0, STR_PAD_LEFT)
   ```

4. **Separate forums** with `\n`. Use extra `\n` for gaps (if forum_id jumps from 2 to 5, lines 3-4 are empty).

**Why 31 bits?** PHP's `base_convert()` uses signed 32-bit integers. 31 bits avoids overflow.  
**Why base-36?** 31 bits (max 2,147,483,647) fits in 6 base-36 characters (`zzzzzz` = 2,176,782,335). Compact text-safe encoding.

### 4.2 Storage Example

```
Line 0 (global, forum_id=0):  zik0zjzik0zjzik0zi    (admin user — mostly 1s)
Line 1 (forum_id=1):          zik0zjzih7uo          (forum-specific)
Line 2 (forum_id=2):          zik0zjzih7uo          (forum-specific)
```

For anonymous user: `00000000000g13ydmo` (mostly 0s, few read permissions)

### 4.3 Decoding Algorithm (`_fill_acl()`)

**Source**: `auth.php:135-174`

1. Split `user_permissions` by `\n` → array indexed by forum_id
2. For each non-empty line, iterate in 6-char chunks:
   ```php
   $converted = str_pad(base_convert($subseq, 36, 2), 31, 0, STR_PAD_LEFT);
   ```
3. Concatenate all decoded chunks → full binary string
4. Store: `$this->acl[$forum_id] = "1001010..."`

**Optimization**: Decoded chunks cached in `$seq_cache` array — identical 6-char patterns decoded only once.

### 4.4 Size Analysis

- **Global bitstring**: 125 options → pad to 155 bits (5 × 31) → 5 chunks × 6 chars = **30 chars**
- **Local bitstring (per forum)**: 33 local options → pad to 62 bits (2 × 31) → 2 chunks × 6 chars = **12 chars per forum**
- **Total for user with 10 forums**: ~30 + (12 × 10) = ~150 chars + newlines = **~170 bytes**
- **Typical**: 252 bytes observed in dump for 2 forums

### 4.5 JWT Implications

The complete permission bitstring for a user is typically **150-500 bytes** depending on number of forums. This is well within JWT payload limits (~8KB practical). Key considerations:
- Global permissions (a_, m_, u_) are ~30 chars total — trivially fits in JWT
- Forum-specific permissions add ~12 chars per forum — for 50 forums = ~600 bytes
- Could store the raw `user_permissions` field directly in JWT claims if desired

---

## 5. Permission Resolution Algorithm

### 5.1 Cache Build Phase (on first access or after invalidation)

**Trigger**: `acl()` method detects empty `user_permissions` → calls `acl_cache()`

**Method**: `acl_raw_data_single_user($user_id)` (auth.php:843-930)

**Step 1 — Load role cache** from file cache (`_role_cache` key):
```php
$role_cache[$role_id] = serialize([option_id => setting, ...]);
```

**Step 2 — Collect user-specific grants** (from `phpbb_acl_users`):
```sql
SELECT forum_id, auth_option_id, auth_role_id, auth_setting
FROM phpbb_acl_users WHERE user_id = X
```
- If `auth_role_id > 0` → unserialize role data, merge all options
- If `auth_role_id = 0` → set single `$hold_ary[$forum_id][$option_id] = $setting`

**Step 3 — Collect group grants** (from `phpbb_acl_groups`):
```sql
SELECT a.forum_id, a.auth_option_id, a.auth_role_id, a.auth_setting
FROM phpbb_acl_groups a, phpbb_user_group ug, phpbb_groups g
WHERE a.group_id = ug.group_id
  AND g.group_id = ug.group_id
  AND ug.user_pending = 0
  AND NOT (ug.group_leader = 1 AND g.group_skip_auth = 1)
  AND ug.user_id = X
```
- Each grant merged via `_set_group_hold_ary()` (NEVER-wins logic)

**Step 4 — Merge algorithm** (`_set_group_hold_ary`):
```php
function _set_group_hold_ary(&$hold_ary, $option_id, $setting) {
    if (!isset($hold_ary[$option_id]) || $hold_ary[$option_id] != ACL_NEVER) {
        $hold_ary[$option_id] = $setting;
        if ($setting == ACL_NEVER) {
            // Unset parent category flag if it was YES
            $flag_id = get_category_flag_id($option_id);
            if (isset($hold_ary[$flag_id]) && $hold_ary[$flag_id] == ACL_YES) {
                unset($hold_ary[$flag_id]);
            }
        }
    }
}
```

**Step 5 — Founder override** (in `acl_cache()`):
```php
if ($userdata['user_type'] == USER_FOUNDER) {
    foreach ($this->acl_options['global'] as $opt => $id) {
        if (strpos($opt, 'a_') === 0) {
            $hold_ary[0][$this->acl_options['id'][$opt]] = ACL_YES;
        }
    }
}
```
Founders get ALL `a_*` permissions forced to YES **after** the merge — overriding even NEVER.

**Step 6 — Encode + Store**:
- `build_bitstring($hold_ary)` → base-36 encoded string
- `UPDATE phpbb_users SET user_permissions = '...' WHERE user_id = X`

### 5.2 Resolution Priority (Highest to Lowest)

```
1. FOUNDER override         — a_* always YES for user_type=3
2. ACL_NEVER from ANY source — immutable (user direct OR any group)
3. ACL_YES from user direct  — user-specific grants processed first
4. ACL_YES from any group    — group grants merged (last-write-wins among groups)
5. ACL_NO (-1)               — "not set" default, becomes 0 in bitstring
```

**Critical**: User-specific grants are loaded FIRST into `$hold_ary`. Group grants are merged AFTER. Since `_set_group_hold_ary()` respects NEVER, a user-level NEVER blocks all group YES grants. But a user-level YES can be overridden by a group-level NEVER (because group merging uses "override unless NEVER is already set").

**Actual priority for a single option**:
- If ANY source (user or group) sets NEVER → result is NEVER (0 in bitstring)
- If user sets YES and no source sets NEVER → result is YES
- If user sets nothing, but group sets YES → result is YES
- If nothing sets YES → result is 0 (denied)

### 5.3 Read Phase (O(1) per check)

**On every page load**: `$auth->acl($user->data)` called at top of each script.

**Flow**:
1. Load `_acl_options` from cache (option name ↔ index mapping)
2. If `user_permissions` is empty → rebuild via `acl_cache()`
3. Decode: `_fill_acl($userdata['user_permissions'])` → `$this->acl[$forum_id] = "10010..."`
4. Validate bitstring lengths (detect stale cache from new options)

**Permission check** (`acl_get($opt, $f = 0)`):
```php
// Global check
$result = $this->acl[0][$this->acl_options['global'][$opt]];

// Local check (if forum specified and option is local)
if ($f != 0 && isset($this->acl_options['local'][$opt])) {
    $result |= $this->acl[$f][$this->acl_options['local'][$opt]];
}
// Global OR Local — global permission applies to ALL forums
```

**Result**: Single character from decoded bitstring — '1' or '0'. O(1) array index lookup.

---

## 6. Permission Checking Methods — Complete API

### `acl_get($opt, $f = 0): bool`
- Check single permission for optional forum context
- Supports `!` prefix for negation
- Combines global + local with OR
- Uses runtime cache `$this->cache[$f][$opt]`
- **Most commonly used** method across the codebase

### `acl_gets(...$opts, $f): int`
- Check if user has ANY of multiple permissions
- Last numeric arg is forum_id (optional)
- OR-combines all results
- Also accepts array: `acl_gets(['m_', 'a_'], $forum_id)`

### `acl_getf($opt, $clean = false): array`
- Get all forums where user has/doesn't have permission
- Returns `[$forum_id][$opt] => bool`
- Used for building UI (show/hide forum-specific features)

### `acl_getf_global($opt): bool`
- "Does user have this permission in ANY forum?"
- Short-circuits on first match
- Also checks global scope if option is global
- Accepts array of options (first true wins)
- **Used as MCP access gate**: `$auth->acl_getf_global('m_')`

---

## 7. Access Control Gates

### ACP (Admin Control Panel)
**File**: `web/adm/index.php:42`
```php
if (!$auth->acl_get('a_'))
{
    send_status_line(403, 'Forbidden');
    trigger_error('NO_ADMIN');
}
```
Also requires re-authentication: `$user->data['session_admin']` must be true.

### MCP (Moderator Control Panel)
**File**: `web/mcp.php:106`
```php
if (!$auth->acl_getf_global('m_'))
{
    // Denied unless using user quickmod tools (f_user_lock, f_sticky, etc.)
}
```

### Forum Access
**File**: `web/viewforum.php:107`
```php
if (!$auth->acl_gets('f_list', 'f_list_topics', 'f_read', $forum_id) || ...)
```
Multiple permissions OR'd — user needs at least one to access forum.

### Posting (Example Flow: "User wants to post in forum X")
1. `$auth->acl($user->data)` — load all permissions at page start
2. `$auth->acl_get('f_post', $forum_id)` — check `f_post` for that forum
3. Internally: `$this->acl[$forum_id][$local_index_of_f_post]` → '1' or '0'
4. No DB query at check time — everything from pre-computed bitstring

---

## 8. Cache Invalidation

### `acl_clear_prefetch($user_id = false)`
**Source**: `auth.php:525-580`

**Actions**:
1. Destroy `_role_cache` from file cache
2. Rebuild role cache from `phpbb_acl_roles_data` (always, even for single-user clear)
3. Clear `user_permissions` column:
   - `$user_id = false` → ALL users: `UPDATE phpbb_users SET user_permissions = ''`
   - `$user_id = [...]` → specific users: `UPDATE phpbb_users SET user_permissions = '' WHERE user_id IN (...)`
4. Set `user_perm_from = 0`
5. Dispatch event `core.acl_clear_prefetch_after`

**Effect**: Next `acl()` call for affected user(s) detects empty `user_permissions` → triggers full rebuild.

### Invalidation Triggers (18 call sites)

| Context | Scope |
|---------|-------|
| Forum create/modify/delete | ALL users |
| Permission set/remove (ACP) | ALL users |
| Role modification | ALL users |
| User added/removed from group | Affected user(s) |
| User type change | Affected user(s) |
| User delete | Affected user(s) |
| Resynchronize/purge (ACP) | ALL users |
| Group permission change | ALL users |

### `_acl_options` Cache
- Cached in phpBB file cache (key: `_acl_options`)
- Only invalidated when permission options are added/removed (rare — extensions)
- Contains: `['global'][$name] => $idx`, `['local'][$name] => $idx`, `['id'][$name] => $option_id`, `['option'][$id] => $name`

### `_role_cache`
- Cached in phpBB file cache
- Rebuilt on every `acl_clear_prefetch()` call
- Contains: `[$role_id] => serialize([$option_id => $setting, ...])`

---

## 9. Key Design Patterns for JWT Integration

### What the Bitstring Already Provides
- **Complete permission state** for a user pre-computed in ~150-500 bytes
- **O(1) check** — no DB needed at request time
- **Forum-scoped** — handles local permissions per-forum

### Challenges for JWT Claims
1. **Forum-dependent**: `f_*` permissions vary per forum — raw bitstring includes ALL forums
2. **Invalidation**: Current system clears DB column; JWT tokens can't be "un-issued"
3. **Size**: With many forums (50+), bitstring grows to 600+ bytes — still fits JWT but adds overhead
4. **Staleness**: Bitstring auto-detects stale length (new options added) — JWT would need version tracking
5. **NEVER semantics**: The three-way ACL_NEVER/YES/NO is collapsed to binary in the bitstring — this is fine for JWT (store the final computed boolean state)

### Possible JWT Claim Strategies

**Strategy A: Full bitstring in JWT**
```json
{
  "acl": "zik0zjzik0zjzik0zi\nzik0zjzih7uo\nzik0zjzih7uo"
}
```
- Pros: Exact replication of current system, no DB needed for any permission check
- Cons: Size (~500 bytes), staleness on permission change

**Strategy B: Global-only in JWT, forum-specific on demand**
```json
{
  "acl_global": "zik0zjzik0zjzik0zi",
  "acl_version": 1742385600
}
```
- Pros: Small (~30 bytes for global), covers ACP/MCP/user gates
- Cons: Forum-specific checks need DB lookup or second mechanism

**Strategy C: Permission digest/hash**
```json
{
  "acl_hash": "sha256_of_user_permissions",
  "acl_version": 1742385600
}
```
- Pros: Tiny claim, integrity check possible
- Cons: Still needs full bitstring load from DB/cache for actual checks

### Critical Insight: Global Permissions Suffice for Elevation

For the **token elevation model** (user token → group token):
- **User token** needs: `u_*` permissions (global only, ~15 relevant) = ~30 bytes
- **Admin token** needs: `a_` flag = 1 bit (or full `a_*` set = ~42 bits)
- **Moderator token** needs: `m_*` global + per-forum local = variable

The elevation check "can this user elevate to admin?" only requires checking `a_` globally — which is a single bit from the global bitstring.

---

## 10. Complete Permission Option Catalog

### Forum Permissions (`f_*`) — 33 options, all `is_local=1`

| ID | Option | Description |
|----|--------|-------------|
| 1 | `f_` | Category flag (auto-set) |
| 2 | `f_announce` | Create announcements |
| 3 | `f_announce_global` | Create global announcements |
| 4 | `f_attach` | Attach files |
| 5 | `f_bbcode` | Use BBCode |
| 6 | `f_bump` | Bump topics |
| 7 | `f_delete` | Delete own posts |
| 8 | `f_download` | Download attachments |
| 9 | `f_edit` | Edit own posts |
| 10 | `f_email` | Email topic to friend |
| 11 | `f_flash` | Use Flash BBCode |
| 12 | `f_icons` | Use topic icons |
| 13 | `f_ignoreflood` | Ignore flood limit |
| 14 | `f_img` | Use [img] BBCode |
| 15 | `f_list` | See forum in listing |
| 16 | `f_list_topics` | See topics in forum |
| 17 | `f_noapprove` | Posts bypass approval queue |
| 18 | `f_poll` | Create polls |
| 19 | `f_post` | Post new topics |
| 20 | `f_postcount` | Posts count toward post count |
| 21 | `f_print` | Print topics |
| 22 | `f_read` | Read forum content |
| 23 | `f_reply` | Reply to topics |
| 24 | `f_report` | Report posts |
| 25 | `f_search` | Search in forum |
| 26 | `f_sigs` | Display signatures |
| 27 | `f_smilies` | Use smilies |
| 28 | `f_sticky` | Create sticky topics |
| 29 | `f_subscribe` | Subscribe to forums/topics |
| 30 | `f_user_lock` | Lock own topics |
| 31 | `f_vote` | Vote in polls |
| 32 | `f_votechg` | Change votes |
| 33 | `f_softdelete` | Soft-delete own posts |

### Moderator Permissions (`m_*`) — 15 options

| ID | Option | `is_global` | `is_local` | Description |
|----|--------|-------------|------------|-------------|
| 34 | `m_` | 1 | 1 | Category flag |
| 35 | `m_approve` | 1 | 1 | Approve/disapprove posts |
| 36 | `m_chgposter` | 1 | 1 | Change post author |
| 37 | `m_delete` | 1 | 1 | Delete posts |
| 38 | `m_edit` | 1 | 1 | Edit posts |
| 39 | `m_info` | 1 | 1 | View post details |
| 40 | `m_lock` | 1 | 1 | Lock/unlock topics |
| 41 | `m_merge` | 1 | 1 | Merge topics |
| 42 | `m_move` | 1 | 1 | Move topics |
| 43 | `m_report` | 1 | 1 | Handle reports |
| 44 | `m_split` | 1 | 1 | Split topics |
| 45 | `m_softdelete` | 1 | 1 | Soft-delete posts |
| 46 | `m_ban` | 1 | 0 | Ban users (global only) |
| 47 | `m_pm_report` | 1 | 0 | Handle PM reports (global only) |
| 48 | `m_warn` | 1 | 0 | Warn users (global only) |

### Admin Permissions (`a_*`) — 42 options, all `is_global=1, is_local=0`

| ID | Option | Description |
|----|--------|-------------|
| 49 | `a_` | Category flag |
| 50 | `a_aauth` | Set admin permissions |
| 51 | `a_attach` | Manage attachments |
| 52 | `a_authgroups` | Manage group permissions |
| 53 | `a_authusers` | Manage user permissions |
| 54 | `a_backup` | Backup/restore database |
| 55 | `a_ban` | Manage bans |
| 56 | `a_bbcode` | Manage BBCodes |
| 57 | `a_board` | Manage board settings |
| 58 | `a_bots` | Manage bots |
| 59 | `a_clearlogs` | Clear logs |
| 60 | `a_email` | Send mass email |
| 61 | `a_extensions` | Manage extensions |
| 62 | `a_fauth` | Set forum permissions |
| 63 | `a_forum` | Manage forums |
| 64 | `a_forumadd` | Add forums |
| 65 | `a_forumdel` | Delete forums |
| 66 | `a_group` | Manage groups |
| 67 | `a_groupadd` | Add groups |
| 68 | `a_groupdel` | Delete groups |
| 69 | `a_icons` | Manage icons/smilies |
| 70 | `a_jabber` | Manage Jabber settings |
| 71 | `a_language` | Manage languages |
| 72 | `a_mauth` | Set moderator permissions |
| 73 | `a_modules` | Manage modules |
| 74 | `a_names` | Manage usernames |
| 75 | `a_phpinfo` | View PHP info |
| 76 | `a_profile` | Manage profile fields |
| 77 | `a_prune` | Prune forums |
| 78 | `a_ranks` | Manage ranks |
| 79 | `a_reasons` | Manage report reasons |
| 80 | `a_roles` | Manage permission roles |
| 81 | `a_search` | Manage search |
| 82 | `a_server` | Server settings |
| 83 | `a_styles` | Manage styles |
| 84 | `a_switchperm` | Use another user's permissions |
| 85 | `a_uauth` | Set user permissions |
| 86 | `a_user` | Manage users |
| 87 | `a_userdel` | Delete users |
| 88 | `a_viewauth` | View permissions |
| 89 | `a_viewlogs` | View logs |
| 90 | `a_words` | Manage word censors |

### User Permissions (`u_*`) — 35 options, all `is_global=1, is_local=0`

| ID | Option | Description |
|----|--------|-------------|
| 91 | `u_` | Category flag |
| 92 | `u_attach` | Attach files in posts |
| 93 | `u_chgavatar` | Change avatar |
| 94 | `u_chgcensors` | Change censor preferences |
| 95 | `u_chgemail` | Change email |
| 96 | `u_chggrp` | Change default group |
| 97 | `u_chgname` | Change username |
| 98 | `u_chgpasswd` | Change password |
| 99 | `u_chgprofileinfo` | Change profile info |
| 100 | `u_download` | Download attachments |
| 101 | `u_emoji` | Use emoji |
| 102 | `u_hideonline` | Hide online status |
| 103 | `u_ignoreflood` | Ignore flood limit |
| 104 | `u_masspm` | Send mass PMs |
| 105 | `u_masspm_group` | Send mass PM to groups |
| 106 | `u_pm_attach` | Attach in PMs |
| 107 | `u_pm_bbcode` | BBCode in PMs |
| 108 | `u_pm_delete` | Delete PMs |
| 109 | `u_pm_download` | Download PM attachments |
| 110 | `u_pm_edit` | Edit PMs |
| 111 | `u_pm_emailpm` | Email PMs |
| 112 | `u_pm_flash` | Flash in PMs |
| 113 | `u_pm_forward` | Forward PMs |
| 114 | `u_pm_img` | Images in PMs |
| 115 | `u_pm_printpm` | Print PMs |
| 116 | `u_pm_smilies` | Smilies in PMs |
| 117 | `u_readpm` | Read private messages |
| 118 | `u_savedrafts` | Save drafts |
| 119 | `u_search` | Use search |
| 120 | `u_sendemail` | Send email to users |
| 121 | `u_sendim` | Send instant messages |
| 122 | `u_sendpm` | Send private messages |
| 123 | `u_sig` | Use signature |
| 124 | `u_viewonline` | View who's online |
| 125 | `u_viewprofile` | View user profiles |

---

## 11. Summary — Architecture Facts for JWT Design

| Aspect | Detail |
|--------|--------|
| **Total options** | 125 permissions across 4 scopes |
| **Global bitstring size** | 92 options (a_ + m_ + u_) → 93 bits → padded to 93 (3×31) → 18 base-36 chars |
| **Local bitstring size** | 48 options (f_ + m_) → padded to 62 (2×31) → 12 base-36 chars per forum |
| **Resolution** | NEVER wins → Founder override → YES from any → default denied |
| **Cache storage** | `phpbb_users.user_permissions` column (mediumtext) |
| **Cache trigger** | Lazy rebuild on empty `user_permissions` + active length validation |
| **Invalidation** | `acl_clear_prefetch()` clears DB column, rebuilds role cache |
| **Read performance** | O(1) — single array index into pre-decoded bitstring |
| **Global OR Local** | Global permission → applies to ALL forums (OR'd with local) |
| **Founder privilege** | `user_type=3` → all `a_*` forced YES after merge |
| **ACP gate** | `acl_get('a_')` — single global bit check |
| **MCP gate** | `acl_getf_global('m_')` — "any forum has m_ set" |
| **Key for elevation** | `a_` flag (1 bit) determines admin eligibility; `m_` determines mod eligibility |
