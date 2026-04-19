# Group-ACL Model Findings

## Research Focus

What group information is needed in JWT tokens to enable stateless permission checks? How does phpBB's group system map to permissions, and what's the minimum claim set for user/group token design?

---

## 1. Group Schema (`phpbb_groups`)

**Source**: `phpbb_dump.sql:1847-1870`

```sql
CREATE TABLE `phpbb_groups` (
  `group_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `group_type` tinyint(4) NOT NULL DEFAULT 1,
  `group_founder_manage` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `group_skip_auth` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `group_name` varchar(255) NOT NULL DEFAULT '',
  `group_desc` text NOT NULL,
  -- ... display/avatar/rank/colour fields omitted ...
  PRIMARY KEY (`group_id`)
);
```

### Group Type Constants

**Source**: `src/phpbb/common/constants.php:77-81`

| Constant | Value | Description |
|----------|-------|-------------|
| `GROUP_OPEN` | 0 | Users can freely join |
| `GROUP_CLOSED` | 1 | Admins assign membership |
| `GROUP_HIDDEN` | 2 | Hidden from non-members |
| `GROUP_SPECIAL` | 3 | System groups — cannot be deleted, always exist |
| `GROUP_FREE` | 4 | Free to join without approval |

### Key Fields for Token Design

| Field | Relevance |
|-------|-----------|
| `group_id` | Primary identifier — needed in JWT group claims |
| `group_type` | GROUP_SPECIAL (3) identifies system groups |
| `group_skip_auth` | If 1, group leaders don't inherit permissions from this group |
| `group_founder_manage` | If 1, only founders can manage this group (ADMINISTRATORS has this) |

---

## 2. Special Groups (Built-in)

**Source**: `phpbb_dump.sql:1880-1894`

| group_id | group_name | group_type | Notes |
|----------|-----------|------------|-------|
| 1 | GUESTS | 3 (SPECIAL) | Anonymous users |
| 2 | REGISTERED | 3 (SPECIAL) | All registered users |
| 3 | REGISTERED_COPPA | 3 (SPECIAL) | Under-13 users (COPPA) |
| 4 | GLOBAL_MODERATORS | 3 (SPECIAL) | Site-wide moderators |
| 5 | ADMINISTRATORS | 3 (SPECIAL) | Admins (founder_manage=1) |
| 6 | BOTS | 3 (SPECIAL) | Search engine bots |
| 7 | NEWLY_REGISTERED | 3 (SPECIAL) | New users with restricted perms |

**Identification**: Special groups are identified by `group_type = 3` (GROUP_SPECIAL) and their canonical `group_name` (uppercase key used in language system). The `group_id` values are installation-specific (auto-increment), so code should identify them by name, not by ID.

**Evidence** (from dump): All 7 system groups have `group_type = 3`.

---

## 3. User-Group Membership (`phpbb_user_group`)

**Source**: `phpbb_dump.sql:3669-3678`

```sql
CREATE TABLE `phpbb_user_group` (
  `group_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `group_leader` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `user_pending` tinyint(1) unsigned NOT NULL DEFAULT 1,
  KEY `group_id` (`group_id`),
  KEY `user_id` (`user_id`),
  KEY `group_leader` (`group_leader`)
);
```

### Key Fields

| Field | Token Relevance |
|-------|-----------------|
| `group_id` | Which group the user belongs to |
| `user_id` | Which user |
| `group_leader` | If 1, user is group leader (affects `group_skip_auth`) |
| `user_pending` | If 1, user hasn't been approved — gets ZERO permissions from this group |

### Sample Memberships (dump)

```
(1,1,0,0)  — user_id=1 (Anonymous) in group_id=1 (GUESTS)
(2,2,0,0)  — user_id=2 (admin) in group_id=2 (REGISTERED)
(4,2,0,0)  — user_id=2 (admin) in group_id=4 (GLOBAL_MODERATORS)
(5,2,1,0)  — user_id=2 (admin) in group_id=5 (ADMINISTRATORS), is group_leader
(6,3,0,0)  — user_id=3 (bot) in group_id=6 (BOTS)
```

Admin user belongs to 3 groups: REGISTERED, GLOBAL_MODERATORS, ADMINISTRATORS.

---

## 4. User's Default Group (`phpbb_users.group_id`)

**Source**: `phpbb_dump.sql:3965`

```sql
`group_id` mediumint(8) unsigned NOT NULL DEFAULT 3,
```

The `group_id` column on `phpbb_users` is the user's **default (display) group** — determines:
- Avatar colour display
- `user_colour` inheritance
- Rank display
- Group name shown in posts

**It does NOT affect permissions.** Permissions are always calculated from ALL groups the user belongs to (via `phpbb_user_group`).

**Evidence**: The ACL resolution code (`acl_raw_data_single_user`) queries `phpbb_user_group` for ALL memberships, not `phpbb_users.group_id`. The default group is purely cosmetic.

From dump:
- Anonymous (user_id=1) has group_id=1 (GUESTS)
- Admin (user_id=2) has group_id=5 (ADMINISTRATORS)
- Bots have group_id=6 (BOTS)

---

## 5. Group → Permission Mapping (`phpbb_acl_groups`)

**Source**: `phpbb_dump.sql:26-37`

```sql
CREATE TABLE `phpbb_acl_groups` (
  `group_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `auth_option_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `auth_role_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `auth_setting` tinyint(2) NOT NULL DEFAULT 0,
);
```

### Two Assignment Modes

1. **Direct option assignment**: `auth_option_id > 0`, `auth_role_id = 0`
   - Sets a single permission option directly
   - `auth_setting` = ACL_YES (1) or ACL_NEVER (0)

2. **Role-based assignment**: `auth_role_id > 0`, `auth_option_id = 0`
   - Assigns an entire role (bundle of permissions)
   - The role's individual options come from `phpbb_acl_roles_data`
   - `auth_setting` is typically 0 (ignored, role data defines settings)

### Scope Context

- `forum_id = 0` → **Global** permission (applies everywhere)
- `forum_id > 0` → **Local/Forum-specific** permission (applies only to that forum)

### Sample Assignments (from dump)

```
(1,0,91,0,1)   — GUESTS: global u_ (option 91) = YES
(1,0,100,0,1)  — GUESTS: global u_download (option 100) = YES  
(1,0,119,0,1)  — GUESTS: global u_search (option 119) = YES
(5,0,0,5,0)    — ADMINISTRATORS: global role_id=5 (ROLE_USER_FULL)
(5,0,0,1,0)    — ADMINISTRATORS: global role_id=1 (ROLE_ADMIN_STANDARD)
(2,0,0,6,0)    — REGISTERED: global role_id=6 (ROLE_USER_STANDARD)
(4,0,0,5,0)    — GLOBAL_MODERATORS: global role_id=5 (ROLE_USER_FULL)
(4,0,0,10,0)   — GLOBAL_MODERATORS: global role_id=10 (ROLE_MOD_FULL)
(5,2,0,14,0)   — ADMINISTRATORS: forum 2 role_id=14 (ROLE_FORUM_FULL)
(5,2,0,10,0)   — ADMINISTRATORS: forum 2 role_id=10 (ROLE_MOD_FULL)
```

---

## 6. Permission Aggregation Algorithm

**Source**: `src/phpbb/forums/auth/auth.php:870-950` (`acl_raw_data_single_user`)

### Resolution Order

1. **User direct + role grants** (from `phpbb_acl_users`) — processed first
2. **Group direct + role grants** (from `phpbb_acl_groups`) — merged with NEVER-wins

### NEVER-Wins Rule

**Source**: `auth.php:930-950` (`_set_group_hold_ary`)

```php
function _set_group_hold_ary(&$hold_ary, $option_id, $setting)
{
    if (!isset($hold_ary[$option_id]) || (isset($hold_ary[$option_id]) && $hold_ary[$option_id] != ACL_NEVER))
    {
        $hold_ary[$option_id] = $setting;
        // If we detect ACL_NEVER, we will unset the flag option
        if ($setting == ACL_NEVER) { ... }
    }
}
```

**Logic**:
- If option not yet set → set it (first source wins)
- If option already set to something other than NEVER → overwrite with new value
- If option already set to **NEVER** → **immutable**, cannot be overridden
- Once ANY source sets NEVER, it's permanent (except Founder override for `a_*`)

### Multi-Group Behavior

When a user belongs to **multiple groups** (e.g., REGISTERED + GLOBAL_MODERATORS + ADMINISTRATORS):
- Permissions from ALL groups are merged
- NEVER from any group overrides YES from all other groups
- The "most permissive" wins **except** when NEVER is involved
- In practice: YES from any group grants access, NEVER from any group denies access permanently

### ACL Constants

**Source**: `src/phpbb/common/constants.php:55-57`

| Constant | Value | Meaning |
|----------|-------|---------|
| `ACL_NEVER` | 0 | Explicitly denied — cannot be overridden |
| `ACL_YES` | 1 | Explicitly granted |
| `ACL_NO` | -1 | Not set / default deny (can be overridden) |

### Founder Override

**Source**: `auth.php:440-448` (`acl_cache`)

```php
if ($userdata['user_type'] == USER_FOUNDER)
{
    foreach ($this->acl_options['global'] as $opt => $id)
    {
        if (strpos($opt, 'a_') === 0)
        {
            $hold_ary[0][$this->acl_options['id'][$opt]] = ACL_YES;
        }
    }
}
```

**Founders always get ALL `a_*` admin permissions**, even if NEVER is set. This runs AFTER all merges.

---

## 7. Admin/Moderator Access Determination

### ACP Access Gate

**Source**: `web/adm/index.php:42-47`

```php
if (!$auth->acl_get('a_'))
{
    send_status_line(403, 'Forbidden');
    trigger_error('NO_ADMIN');
}
```

ACP access requires the **`a_` master permission** (option_id 49). This is a "flag" permission — it's automatically set to YES if the user has ANY `a_*` specific permission granted.

Additionally, ACP requires **session re-authentication** (`session_admin` flag).

### MCP Access

**Source**: `web/mcp.php:40-49`

MCP only requires user to be registered. Specific moderation actions check `m_` permissions per-forum:
- `$auth->acl_get('m_')` — global moderation flag
- `$auth->acl_getf_global('m_')` — has m_ in ANY forum

### Permission Determination Is PURELY Permission-Based

**Critical Finding**: phpBB does NOT check group membership for admin/moderator status. It's **100% permission-based**.

**Evidence**: `web/adm/index.php:42` checks `$auth->acl_get('a_')` — this reads from the user's permission bitfield cache, which was computed from all their groups.

A user in the ADMINISTRATORS group gets admin access because that group has `a_*` roles assigned, not because of the group name itself. If you removed all admin roles from the ADMINISTRATORS group (hypothetically), members would lose ACP access.

### Common Pattern: "Is user admin or moderator?"

**Source**: `src/phpbb/forums/user.php:379,391`

```php
if ($auth->acl_gets('a_', 'm_') || $auth->acl_getf_global('m_'))
```

This checks:
1. `a_` — has any admin permission (global)
2. `m_` — has global moderator permission
3. `acl_getf_global('m_')` — has moderator permission in any specific forum

---

## 8. Permission Options Taxonomy

**Source**: `phpbb_dump.sql:116-242` (`phpbb_acl_options`)

### Permission Prefixes

| Prefix | Scope | Count | Purpose |
|--------|-------|-------|---------|
| `f_` | Local only | 33 | Forum permissions (read, post, reply, etc.) |
| `m_` | Both | 15 | Moderator permissions (approve, delete, move, etc.) |
| `a_` | Global only | 42 | Admin permissions (manage forums, users, settings) |
| `u_` | Global only | 35 | User-level permissions (PM, avatar, search, etc.) |

### Key Distinction: Global vs Local

- **Global** (`is_global=1`): Stored at `forum_id=0`. Checked without forum context.
- **Local** (`is_local=1`): Stored per-forum. Checked with specific forum_id.
- **Both** (`is_global=1, is_local=1`): `m_` permissions — can be global OR per-forum.

### Flag Permissions

Each prefix has a "flag" option (e.g., `a_`, `m_`, `f_`, `u_`):
- Set to YES if ANY specific sub-permission is YES
- Used for quick "does user have ANY X-type permission?" checks
- Auto-computed during bitstring build

---

## 9. Role System

**Source**: `phpbb_dump.sql:252-319` (`phpbb_acl_roles`)

### Role Types

| Type | Example Roles | Purpose |
|------|---------------|---------|
| `a_` | ROLE_ADMIN_FULL, ROLE_ADMIN_STANDARD | Admin role bundles |
| `m_` | ROLE_MOD_FULL, ROLE_MOD_STANDARD | Moderator role bundles |
| `f_` | ROLE_FORUM_FULL, ROLE_FORUM_READONLY | Forum access role bundles |
| `u_` | ROLE_USER_FULL, ROLE_USER_STANDARD | User capability role bundles |

Roles are just convenient bundles. The system always expands roles to individual `auth_option_id` settings via `phpbb_acl_roles_data`.

---

## 10. Implications for JWT Token Design

### The Core Problem

phpBB's permission system computes a **bitfield per user** that encodes all permissions for all forums. This bitfield is stored in `phpbb_users.user_permissions` and is the basis for all `acl_get()` calls.

**Size**: ~125 permission options × multiple forums. For a board with 50 forums, the bitfield is roughly 125 × 50 = 6,250 bits ≈ 800 bytes. Encoded in base-36, it's the compact bitstring in `user_permissions` column.

### Why Group IDs Alone Are NOT Enough for Stateless Checks

Group membership tells you **which groups** a user belongs to, but NOT what permissions those groups have. The mapping from groups → permissions requires DB lookups to:
1. `phpbb_acl_groups` — what roles/options are assigned to each group
2. `phpbb_acl_roles_data` — what options are in each role

**You cannot resolve permissions from group_ids alone without a DB query.**

### Viable Approaches for JWT Claims

#### Approach A: Embed Pre-Computed Permission Bitfield

Embed the full permission bitfield (or a hash/version of it) in the JWT:

```json
{
  "user_id": 2,
  "user_type": 3,
  "perm_hash": "sha256-of-user_permissions",
  "user_permissions": "<base64-encoded-bitfield>"
}
```

**Pros**: True stateless — `acl_get()` reads from JWT claims directly  
**Cons**: Large payload (800+ bytes for active boards). JWT size bloat. Must invalidate on ANY permission change.

#### Approach B: Permission Digest (Flag Permissions Only)

Embed only the 4 flag permissions in JWT for quick routing:

```json
{
  "user_id": 2,
  "user_type": 3,
  "flags": {
    "a": true,   // has admin access
    "m": true,   // has global moderator access
    "u": true,   // has user-level permissions
    "f": true    // has forum access
  },
  "groups": [2, 4, 5],
  "perm_version": 1713456789
}
```

**Pros**: Compact. Enables fast ACP/MCP gate checks. `perm_version` allows cache validation.  
**Cons**: Still need DB for fine-grained forum-specific checks. Not truly stateless for forum operations.

#### Approach C: Hybrid — Tiered Token Claims

**User Token** (lightweight, for standard operations):
```json
{
  "sub": 2,
  "type": "user",
  "user_type": 0,
  "groups": [2, 7],
  "flags": {"u": true, "f": true},
  "perm_v": 42
}
```

**Group Token** (elevated, for admin/mod operations):
```json
{
  "sub": 2,
  "type": "group",
  "user_type": 3,
  "group_id": 5,
  "groups": [2, 4, 5],
  "flags": {"a": true, "m": true, "u": true, "f": true},
  "a_perms": "base36-encoded-admin-bitfield",
  "perm_v": 42
}
```

**Pros**: Separation of concerns. Admin bitfield is small (42 `a_*` options = ~42 bits). Forum operations still need server-side cache. Clear elevation boundary.  
**Cons**: Forum-level permissions still require server-side lookup.

### Recommendation: What MUST Be in the Token

#### Minimum Claims for User Token

| Claim | Purpose | Required? |
|-------|---------|-----------|
| `sub` (user_id) | Identify user | YES |
| `user_type` | Founder override detection | YES |
| `groups` (group_ids) | Quick group membership check, cache key for session-local perm cache | YES |
| `perm_v` (version/timestamp) | Validate cached permissions are still fresh | YES |
| `flags.u` | User has u_ permissions | NICE-TO-HAVE |
| `flags.a` | User can access ACP (quick gate) | NICE-TO-HAVE |
| `flags.m` | User can access MCP (quick gate) | NICE-TO-HAVE |

#### Minimum Claims for Group Token (Elevated)

| Claim | Purpose | Required? |
|-------|---------|-----------|
| `sub` (user_id) | Identify user | YES |
| `type: "group"` | Token type discrimination | YES |
| `group_id` | Which group context is elevated | YES |
| `user_type` | Founder override | YES |
| `groups` (all group_ids) | Full membership for resolution | YES |
| `flags.a` | Confirms admin permission | YES |
| `a_perms` or `m_perms` | Encoded admin/mod bitfield subset | OPTIONAL |
| `perm_v` | Version for freshness | YES |

---

## 11. Key Design Insights

### 1. Permissions Are NOT Derivable from Group IDs Alone

Group membership is necessary but not sufficient. The mapping from group_id → actual permissions requires the `phpbb_acl_groups` + `phpbb_acl_roles_data` data. This is why the permission bitfield exists as a pre-computed cache.

### 2. The Bitfield IS the Stateless Permission Cache

phpBB already solved "stateless permission checks" — the `user_permissions` bitfield is a pre-computed, self-contained permission map. The question is whether to put it in JWT or keep it server-side.

### 3. Permission Version Is Critical

Any approach needs a `perm_version` or `perm_hash` to detect stale permissions. Permissions change when:
- Admin modifies group permissions
- User is added/removed from groups
- Roles are edited
- `acl_clear_prefetch` is called

The existing system stores `user_permissions = ''` to force recalculation. For JWT, a version counter or timestamp enables similar invalidation.

### 4. Group Token Concept Maps Well to phpBB's Design

The "elevation" concept (user token → group token for admin access) maps naturally to phpBB's design:
- Standard operations need only `f_` and `u_` permissions (forum/user level)
- Admin operations need `a_` permissions (always global, ~42 options)
- Moderator operations need `m_` permissions (can be global or per-forum)

The group token can carry the admin/moderator bitfield subset (small: 42 bits for admin, 15 bits for mod) without bloating the token.

### 5. Forum-Specific Permissions Are the Scalability Challenge

The true challenge isn't admin/mod (those are small global bitfields) — it's the **forum-level permissions** (33 `f_` options × N forums). For a board with 100 forums, that's 3,300 bits just for forum permissions.

**Pragmatic solution**: Forum-level permissions should be resolved server-side using a short-lived in-memory cache (loaded once per request from the pre-computed bitfield), not embedded in the JWT.

### 6. `user_type` Is Essential for Founder Bypass

`USER_FOUNDER` (3) overrides all `a_*` NEVER restrictions. This MUST be in the token so the AuthZ layer can apply the founder override without DB access.

### 7. group_skip_auth Only Matters During Resolution

The `group_skip_auth` flag only affects permission computation (excludes group leaders from inheriting that group's permissions). Once the bitfield is computed, it's irrelevant. It does NOT need to be in the JWT.

---

## 12. Summary: Minimal JWT Claim Design

### For Standard Forum Operations (User Token)

```json
{
  "iss": "phpBB",
  "sub": 42,
  "iat": 1713456000,
  "exp": 1713459600,
  "type": "user",
  "utype": 0,
  "grp": [2, 7],
  "pv": 1713456000
}
```

Server-side loads the cached bitfield (keyed by user_id + perm_version) for forum-level ACL checks. No per-request DB query needed if bitfield is cached in Redis/memcache.

### For Admin/Moderator Access (Group Token)

```json
{
  "iss": "phpBB",
  "sub": 42,
  "iat": 1713456000,
  "exp": 1713457800,
  "type": "group",
  "utype": 3,
  "gid": 5,
  "grp": [2, 4, 5],
  "flags": ["a", "m"],
  "pv": 1713456000
}
```

The `flags` claim enables immediate ACP/MCP gate checks without any lookup. Fine-grained admin sub-permission checks (`a_forum`, `a_user`, etc.) still use server-side cached bitfield.

### Why Not Fully Stateless?

True statelessness (all permissions in JWT) would require embedding ~800+ bytes of bitfield data in every token. This is impractical because:
1. JWT in Authorization header has practical size limits (~8KB for most proxy/CDN)
2. Forum permissions change rarely but forum count grows
3. The existing bitfield cache mechanism already provides O(1) lookups
4. A Redis/memcache permission cache gives identical performance without token bloat

The optimal design: **JWT carries identity + quick gates (flags) + freshness marker; server-side cache provides detailed permission bitfield.**

---

## Sources

| Source | Lines | Content |
|--------|-------|---------|
| `phpbb_dump.sql` | 26-89 | `phpbb_acl_groups` schema + data |
| `phpbb_dump.sql` | 93-242 | `phpbb_acl_options` schema + data |
| `phpbb_dump.sql` | 246-319 | `phpbb_acl_roles` schema + data |
| `phpbb_dump.sql` | 323-400 | `phpbb_acl_roles_data` schema + partial data |
| `phpbb_dump.sql` | 771-798 | `phpbb_acl_users` schema + data |
| `phpbb_dump.sql` | 1847-1895 | `phpbb_groups` schema + data |
| `phpbb_dump.sql` | 3669-3730 | `phpbb_user_group` schema + data |
| `phpbb_dump.sql` | 3963-4040 | `phpbb_users` schema (user_permissions, group_id) |
| `src/phpbb/forums/auth/auth.php` | 1-950 | ACL class — resolution algorithm |
| `src/phpbb/common/constants.php` | 44-81 | Group/ACL/User type constants |
| `web/adm/index.php` | 34-49 | ACP access gate |
| `web/mcp.php` | 40-49 | MCP access check |
| `src/phpbb/forums/user.php` | 379-410 | Admin/mod combined check pattern |
| `.maister/tasks/research/2026-04-18-auth-service/outputs/high-level-design.md` | 780-890 | Prior ACL resolution algorithm spec |

**Confidence**: High (90-100%) — All findings from direct source inspection of code and schema.
