# Research Report: Designing a Modern `phpbb\auth\` Service

**Research type**: Technical (codebase extraction + architecture design)  
**Date**: 2026-04-18  
**Methodology**: Static codebase analysis + DB schema mapping + existing service integration review  
**Overall confidence**: **High (95%)**

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Research Objectives](#2-research-objectives)
3. [Methodology](#3-methodology)
4. [Legacy ACL System Architecture](#4-legacy-acl-system-architecture)
5. [Permission Types & Categories](#5-permission-types--categories)
6. [Key Findings Per Area](#6-key-findings-per-area)
7. [Proposed `phpbb\auth\` Service Architecture](#7-proposed-phpbbauth-service-architecture)
8. [REST API Middleware Concept](#8-rest-api-middleware-concept)
9. [Risks & Open Questions](#9-risks--open-questions)
10. [Recommended Next Steps](#10-recommended-next-steps)
11. [Appendices](#11-appendices)

---

## 1. Executive Summary

### What Was Researched

A complete analysis of the legacy phpBB ACL (Access Control List) system to design a modern, type-safe `phpbb\auth\` service for authorization checking, permission management, and REST API middleware integration.

### How It Was Researched

- **6 source categories** analyzed: core ACL engine (1139-line class), 5 DB tables (748 rows), auth provider system (12-method interface), admin CRUD operations (19 cache invalidation sites), user service integration boundary, and REST API middleware gap
- **Direct code reading** of all relevant source files
- **Live database inspection** against running Docker instance
- **Cross-referencing** with existing `phpbb\user` implementation spec

### Key Findings

1. The legacy `auth` class is a **God-class** mixing 4 concerns (authentication, authorization, cache, admin) — but the authorization engine is conceptually clean and extractable.
2. The ACL uses a **three-value permission model** (YES/NEVER/NO) with a deterministic 5-layer resolution algorithm and efficient base-36 bitfield caching.
3. **Authentication is already handled** by `phpbb\user\Service\AuthenticationService` — the new auth service is **authorization only**.
4. The REST API currently has **zero ACL checking** — any valid JWT accesses any endpoint.
5. A clean extraction path exists: 4 services, 5 repository interfaces, 6 entities, operating on 5 existing DB tables.

### Main Conclusions

The `phpbb\auth\` service should be a focused authorization engine with:
- `AuthorizationService` as the primary facade (permission checking)
- `PermissionResolver` for the 5-layer resolution algorithm
- `AclCacheService` for bitfield encode/decode/invalidate
- `PermissionService` + `RoleService` for admin operations
- Integration via Symfony `kernel.request` subscriber at priority 8

---

## 2. Research Objectives

### Primary Research Question

How to design a modern `phpbb\auth\` service extracted from legacy phpBB ACL code — authentication integration with `phpbb\user`, ACL permission checking, role/option management — to be used by REST API middleware.

### Sub-Questions

1. How does the legacy bitfield permission format work end-to-end?
2. What is the complete resolution algorithm (user → group → role → NEVER → Founder)?
3. Where is the boundary between authentication (`phpbb\user`) and authorization (`phpbb\auth`)?
4. What entities, interfaces, and services are needed?
5. How should the REST API middleware check permissions per route?
6. What are the backward-compatibility constraints (shared DB tables)?

### Scope

- **Included**: ACL engine, 5 ACL tables, bitfield cache, auth provider pattern, admin CRUD, REST API middleware, `phpbb\user` integration
- **Excluded**: OAuth/social login, CAPTCHA/anti-spam, PM permissions, user CRUD, session management

---

## 3. Methodology

### Data Sources

| Source | Files Analyzed | Method |
|--------|---------------|--------|
| ACL Core | `auth.php` (1139 lines) | Complete line-by-line code reading |
| ACL Database | 5 tables, 748 rows | Live MySQL queries via Docker |
| Auth Providers | 8 files (interface, base, db, collection, session hooks) | Direct source reading |
| Admin ACL | 4 files (acp_permissions, acp_permission_roles, auth_admin, functions_admin) | Source reading + flow tracing |
| User Service | `IMPLEMENTATION_SPEC.md`, `spec.md` | Specification analysis |
| REST API | 6 files (subscriber, controllers, routes, services YAML) | Source reading + architecture analysis |

### Analysis Framework

Technical research framework: Component analysis (what exists, how it works) → Flow analysis (data paths, execution) → Pattern identification → Architecture proposal.

---

## 4. Legacy ACL System Architecture

### 4.1 Database Tables (5 tables)

```
phpbb_acl_options          ← Permission definitions (125 rows)
  │                           Columns: auth_option_id, auth_option, is_global, is_local, founder_only
  │
  ├─→ phpbb_acl_roles_data ← Role→Permission mappings (422 rows)
  │     │                      Columns: role_id, auth_option_id, auth_setting
  │     │
  │     └─ phpbb_acl_roles  ← Role definitions (24 unique)
  │                            Columns: role_id, role_name, role_type, role_order
  │
  ├─→ phpbb_acl_users      ← User-level grants (2 rows in default install)
  │                            Columns: user_id, forum_id, auth_option_id, auth_role_id, auth_setting
  │
  └─→ phpbb_acl_groups     ← Group-level grants (154 rows)
                               Columns: group_id, forum_id, auth_option_id, auth_role_id, auth_setting

phpbb_users.user_permissions ← Cached computed bitstring (mediumtext)
phpbb_users.user_perm_from   ← Permission copy source (mediumint, 0=own)
```

**Dual assignment mode**: Both `acl_users` and `acl_groups` support role-based (one row per role assignment) and direct (one row per permission option) assignments in the same table.

### 4.2 Three-Value Permission Model

| Constant | Value | Semantics |
|----------|-------|-----------|
| `ACL_YES` | `1` | Permission granted |
| `ACL_NEVER` | `0` | Permission permanently denied — **cannot be overridden by YES from any source** |
| `ACL_NO` | `-1` | Not set / neutral — can be overridden by YES from group/role |

**NEVER-wins principle**: Once any source (user direct, any group, any role) sets `ACL_NEVER` for an option, no other source can change it. This is the core security invariant.

### 4.3 Bitfield Format

The `user_permissions` column stores a custom-encoded multi-line string:

```
<line_0_global>\n<line_1_forum1>\n<line_2_forum2>\n...
```

- **Line index = forum_id** (line 0 = global/forum_id=0, line N = forum_id N)
- **Empty lines** represent forums with no permissions (forum_id gaps)
- **Per-line encoding**: binary string → split into 31-bit chunks → each chunk `base_convert(2→36)` → zero-pad to 6 chars → concatenate

**Why 31 bits?** PHP's `base_convert()` uses signed 32-bit integers. 31 bits avoids overflow.
**Why base-36?** 31 bits (max 2,147,483,647) fits in 6 base-36 characters (`zzzzzz` = 2,176,782,335). Compact text-safe encoding.

**Example**: Admin user global line: `zik0zjzik0zjzik0zi` (near-max = most permissions set)
**Example**: Anonymous global line: `00000000000g13ydmo` (mostly zeros)

### 4.4 Permission Resolution Algorithm

**Cache Build Phase** (`acl_raw_data_single_user` → `acl_cache` → `build_bitstring`):

```
Step 1: Collect user direct grants (phpbb_acl_users, non-role)
Step 2: Collect user role grants (phpbb_acl_users → role cache expansion)
Step 3: For each group user belongs to (non-pending, respecting group_skip_auth):
        a. Collect group direct grants
        b. Collect group role grants
        c. Merge via _set_group_hold_ary():
           - If option not set yet → accept any setting
           - If option == ACL_NEVER → reject (NEVER is immutable)
           - If option != ACL_NEVER → accept new setting (last-write-wins)
           - If new is NEVER → clear parent category flag (e.g., a_ for a_foo)
Step 4: Founder override: if user_type == USER_FOUNDER(3):
        Force ALL a_* globals to ACL_YES (overrides even NEVER for admin perms)
Step 5: Collapse to binary: ACL_YES → 1, everything else → 0
Step 6: Auto-set category flags: if any a_foo=1 and a_≠NEVER, set a_=1
Step 7: Encode to bitstring (31-bit base-36)
Step 8: Store in phpbb_users.user_permissions
```

**Resolution Priority (highest to lowest)**:
1. **Founder override** — `a_*` always YES for founders
2. **ACL_NEVER** — from any source (user or group), immutable
3. **ACL_YES from user direct** — processed first
4. **ACL_YES from group/role** — merged with NEVER-wins
5. **ACL_NO** — treated as "not set", becomes 0 in bitstring

**Read Phase** (`_fill_acl` → `acl_get`):
1. Decode `user_permissions`: split by `\n`, decode each line (6-char chunks → base-36→binary → concatenate)
2. Store as `$acl[$forum_id] = "10010110..."` (binary string, position = option index)
3. Lookup: `$acl[$forum_id][$option_index]` — **O(1)**, single character comparison
4. Global+Local OR: for forum-scoped checks, `acl[0][global_idx] || acl[$f][local_idx]`

### 4.5 Cache Mechanism

| Layer | Storage | Invalidation |
|-------|---------|-------------|
| `_acl_options` | File cache | When options added/removed (extensions) |
| `_role_cache` | File cache | On every `acl_clear_prefetch()` call (rebuilt) |
| `user_permissions` | DB column | On `acl_clear_prefetch(?userId)` — set to `''` |
| `$this->acl[]` | PHP memory | Per-request (reset on `acl()` init) |
| `$this->cache[]` | PHP memory | Per-request (reset on `acl()` init) |

**Lazy rebuild**: When `user_permissions` is empty, next `acl()` call triggers full rebuild from ACL tables.

**19 invalidation trigger sites** across codebase: forum CRUD, permission set/remove, role modification, group membership changes, ACP cache purge, CLI cache purge, migration tool.

---

## 5. Permission Types & Categories

### 5.1 Four Permission Prefixes

| Prefix | Count | Scope | Global (`is_global`) | Local (`is_local`) | Description |
|--------|-------|-------|---------------------|-------------------|-------------|
| `a_` | 42 | Global only | ✅ | ❌ | Admin panel permissions |
| `u_` | 35 | Global only | ✅ | ❌ | User-level capabilities |
| `f_` | 33 | Local only | ❌ | ✅ | Forum-specific permissions |
| `m_` | 15 | Both | ✅ | ✅ | Moderator permissions |

### 5.2 Global vs Local Semantics

- **Global** (forum_id=0): Permission applies board-wide. Stored in bitstring line 0.
- **Local** (forum_id=N): Permission applies to specific forum. Stored in bitstring line N.
- **Both** (`m_*`): Can be assigned globally (moderator of all forums) OR per-forum. On check, global and local are **OR'd** — global grants imply access to all forums.

### 5.3 Category Flags

Each prefix type has a "base" option (`a_`, `m_`, `f_`, `u_`) that acts as a category flag:
- `a_` = "Can access admin panel" (required for any `a_*` access)
- `m_` = "Is moderator" (required for any `m_*` access)
- `f_` = "Can see forum" (required for any `f_*` access)
- `u_` = "Has user permissions" (required for any `u_*` access)

Auto-calculated during `build_bitstring()`: if any `a_foo=YES`, set `a_=YES` (unless `a_=NEVER`).

**Known bug** (PHPBB3-10252): Category flags may be incorrect when NEVER overrides exist. The new service should NOT use category flags for authorization decisions — always check the specific option.

### 5.4 Role Types (24 unique roles)

| Type | Roles | Key Examples |
|------|-------|-------------|
| Admin (`a_`) | 4 | FULL (all 42), STANDARD (30), FORUM (forum mgmt), USERGROUP (user/group mgmt) |
| Forum (`f_`) | 10 | FULL (all 33), STANDARD (26), LIMITED (21), READONLY (8), NOACCESS (0), BOT (7) |
| Moderator (`m_`) | 4 | FULL (all 15), STANDARD (most), SIMPLE (edit+delete+lock), QUEUE (approve only) |
| User (`u_`) | 6 | FULL (all 35), STANDARD (29), LIMITED (subset), NOPM, NOAVATAR, NEW_MEMBER |

---

## 6. Key Findings Per Area

### 6.1 ACL Core (`auth.php`)

- **God-class**: 1139 lines, 15+ public methods mixing authentication orchestration, authorization checking, cache management, and raw DB queries
- **Read path is O(1)**: After bitstring decode, permission checks are single array index lookups
- **Write path is expensive**: 4+ SQL queries to collect, merge, encode, then UPDATE `user_permissions`
- **`login()` method delegates to auth providers** — not actual ACL logic, should NOT be in auth service
- **`obtain_user_data()` has SQL injection risk**: `$user_id` not cast to int in raw SQL
- **Bitstring validation**: `acl()` detects stale bitstrings (wrong length) and auto-rebuilds — handles extension-added options gracefully

### 6.2 ACL Database

- **125 permission options** across 4 types (42 admin + 33 forum + 15 moderator + 35 user)
- **No foreign keys**: All relationships enforced by application code
- **No primary key on `acl_users`/`acl_groups`**: Index-only tables, allowing multiple assignments
- **Dual assignment mode**: Role-based (`auth_role_id > 0`) or direct (`auth_option_id > 0`) in same table
- **Default install**: 7 groups with role-based assignments, only 2 user-specific grant rows (admin)
- **`founder_only` flag**: Available in `acl_options` but currently unused (all 0)

### 6.3 Auth Providers

- **12-method interface**: `login`, `autologin`, `logout`, `validate_session`, `init`, `acp`, `get_acp_template`, `get_login_data`, `login_link_has_necessary_data`, `link_account`, `unlink_account`, `get_auth_link_data`
- **db.php is primary provider**: Username/password auth with brute-force protection (IP-based) and CAPTCHA integration
- **Not needed in auth service**: Authentication is handled by `phpbb\user\AuthenticationService` — providers are authentication concern
- **Session hooks exist**: `validate_session()`, `autologin()`, `logout()` called by session lifecycle — handled by `SessionService`

### 6.4 Admin ACL Operations

- **Delete-then-insert pattern**: All permission writes delete existing rows then insert new ones — never UPDATE
- **Role deletion converts to direct**: Deleting a role materializes its settings as per-option rows for all affected users/groups
- **`acl_clear_prefetch()` always rebuilds role cache**: Even for single-user invalidation — somewhat expensive but ensures consistency
- **Batch optimization exists**: `set_all_permissions()` passes `$clear_prefetch=false` to individual operations, then does ONE bulk invalidation
- **Strong security checks**: CSRF via `check_form_key()`, permission checks (requires `a_*auth` + `a_auth{users|groups}`), confirmation dialogs for deletes

### 6.5 User Service Integration

- **Authentication = DONE**: `phpbb\user\AuthenticationService` handles complete login flow (10 steps)
- **User entity EXCLUDES `user_permissions`**: The auth service needs **direct DB access** to this column
- **UserType enum exists**: `Normal(0)`, `Inactive(1)`, `Ignore(2)`, `Founder(3)` — Founder detection via `$user->isFounder()`
- **Group entity has `skipAuth` flag**: Must be respected in group permission resolution
- **GroupMembership tracks pending/leader**: Pending members excluded from permission resolution
- **Clear boundary**: User service produces `User` entity → Auth service consumes it for authorization

### 6.6 REST API Middleware

- **JWT (HS256) via firebase/php-jwt**: Working authentication but hardcoded secret
- **`auth_subscriber`**: Validates JWT, stores claims in `_api_token` request attribute, priority 0
- **ZERO ACL checking**: Any valid JWT accesses any endpoint — the primary gap
- **No route permission metadata**: Routes carry no `_api_permission`/`_api_scope` information
- **Mock auth controller**: Hardcoded admin/admin credentials, `admin: true` flag in JWT is self-declared
- **Proposed fix**: Route defaults in YAML + refactored subscriber at priority 8 + `AuthorizationService::isGranted()`

---

## 7. Proposed `phpbb\auth\` Service Architecture

### 7.1 Scope Definition

**What it does**:
- Authorization checking (permission queries)
- Permission cache management (bitfield encode/decode/invalidate)
- Permission administration (set/remove grants, role CRUD)
- Route-level access control for REST API

**What it does NOT do**:
- Authentication (login/logout/session → `phpbb\user`)
- User management (CRUD → `phpbb\user`)
- Token generation/validation (JWT → dedicated token service)
- Ban checking (→ `phpbb\user\BanService`)

### 7.2 Directory Structure

```
src/phpbb/auth/
├── Contract/
│   ├── AuthorizationServiceInterface.php
│   ├── PermissionServiceInterface.php
│   ├── RoleServiceInterface.php
│   ├── AclCacheServiceInterface.php
│   ├── AclRepositoryInterface.php
│   └── AclCacheRepositoryInterface.php
├── Entity/
│   ├── Permission.php            # Represents an acl_option (id, name, isGlobal, isLocal)
│   ├── Role.php                  # Represents an acl_role (id, name, description, type, order)
│   ├── RolePermission.php        # Role→Permission mapping with setting value
│   └── PermissionGrant.php       # User/Group→Permission assignment (role-based or direct)
├── Enum/
│   ├── PermissionType.php        # Admin, Forum, Moderator, User
│   ├── PermissionScope.php       # Global, Local
│   ├── PermissionSetting.php     # Yes(1), Never(0), No(-1)
│   └── GrantTarget.php           # User, Group
├── Service/
│   ├── AuthorizationService.php  # Primary facade: isGranted(), isGrantedAny(), getGrantedForums()
│   ├── PermissionResolver.php    # 5-layer resolution algorithm
│   ├── AclCacheService.php       # Bitfield encode/decode/invalidate
│   ├── PermissionService.php     # Admin: set/remove user/group permissions
│   └── RoleService.php           # Admin: role CRUD
├── Repository/
│   ├── AclRepository.php         # Queries 5 ACL tables
│   └── AclCacheRepository.php    # Reads/writes user_permissions column
├── Event/
│   ├── PermissionsClearedEvent.php
│   └── PermissionDeniedEvent.php
└── Exception/
    ├── AccessDeniedException.php
    └── PermissionNotFoundException.php
```

### 7.3 Core Interfaces

#### AuthorizationServiceInterface (Primary Facade)

```php
namespace phpbb\auth\Contract;

use phpbb\user\Entity\User;

interface AuthorizationServiceInterface
{
    /**
     * Check if user has a specific permission.
     * For forum-scoped permissions (f_*, some m_*), pass forumId.
     * Global + local results are OR'd for dual-scope permissions (m_*).
     */
    public function isGranted(User $user, string $permission, ?int $forumId = null): bool;

    /**
     * Check if user has ANY of the specified permissions.
     * Returns true on first match (short-circuit).
     */
    public function isGrantedAny(User $user, array $permissions, ?int $forumId = null): bool;

    /**
     * Get all forum IDs where user has the specified permission.
     * Only meaningful for local-scoped permissions (f_*, m_*).
     */
    public function getGrantedForums(User $user, string $permission): array;

    /**
     * Check if user has permission in at least one forum.
     * Equivalent to legacy acl_getf_global().
     */
    public function isGrantedInAnyForum(User $user, string $permission): bool;

    /**
     * Load permissions for a user (call once per request, results cached).
     * Internally decodes user_permissions bitstring or triggers rebuild.
     */
    public function loadPermissions(User $user): void;
}
```

#### AclCacheServiceInterface

```php
namespace phpbb\auth\Contract;

interface AclCacheServiceInterface
{
    /** Get the cached permission bitstring for a user, or '' if not cached. */
    public function getUserPermissions(int $userId): string;

    /** Store a computed bitstring for a user. */
    public function storeUserPermissions(int $userId, string $bitstring): void;

    /** Clear cached permissions. If userId=null, clears ALL users. */
    public function clearPrefetch(?int $userId = null): void;

    /** Rebuild the role cache from acl_roles_data table. */
    public function rebuildRoleCache(): void;

    /** Get the role cache (role_id → [option_id → setting]). */
    public function getRoleCache(): array;

    /** Get option registry (name→index, id→name mappings). */
    public function getOptionRegistry(): array;

    /** Encode permissions array to bitstring. */
    public function buildBitstring(array $permissions, array $optionRegistry): string;

    /** Decode bitstring to permissions array. */
    public function decodeBitstring(string $bitstring, array $optionRegistry): array;
}
```

#### PermissionServiceInterface (Admin Operations)

```php
namespace phpbb\auth\Contract;

interface PermissionServiceInterface
{
    /** Set permissions for a user on a forum (or global if forumId=0). */
    public function setUserPermissions(
        int $userId,
        int $forumId,
        array $settings,
        ?int $roleId = null
    ): void;

    /** Set permissions for a group on a forum (or global if forumId=0). */
    public function setGroupPermissions(
        int $groupId,
        int $forumId,
        array $settings,
        ?int $roleId = null
    ): void;

    /** Remove all permissions of a type for user(s) on forum(s). */
    public function removeUserPermissions(array $userIds, ?int $forumId = null, ?string $permissionType = null): void;

    /** Remove all permissions of a type for group(s) on forum(s). */
    public function removeGroupPermissions(array $groupIds, ?int $forumId = null, ?string $permissionType = null): void;

    /** Copy permissions from one forum to another. */
    public function copyForumPermissions(int $sourceForumId, array $targetForumIds): void;

    /** Get raw permission data for users (admin diagnostic). */
    public function getRawUserPermissions(int $userId, ?string $permissionType = null): array;

    /** Get raw permission data for groups (admin diagnostic). */
    public function getRawGroupPermissions(int $groupId, ?string $permissionType = null): array;
}
```

#### RoleServiceInterface (Admin Operations)

```php
namespace phpbb\auth\Contract;

interface RoleServiceInterface
{
    public function createRole(string $name, string $description, string $type, array $settings): int;
    public function updateRole(int $roleId, string $name, string $description, array $settings): void;
    public function deleteRole(int $roleId): void;
    public function getRole(int $roleId): ?Role;
    public function getRolesByType(string $type): array;
    public function reorderRole(int $roleId, string $direction): void;
}
```

### 7.4 Core Entities

#### Permission (Value Object)

```php
namespace phpbb\auth\Entity;

final readonly class Permission
{
    public function __construct(
        public int $id,
        public string $name,          // e.g., 'f_read', 'a_board'
        public bool $isGlobal,
        public bool $isLocal,
        public bool $founderOnly,
    ) {}

    public function getType(): PermissionType
    {
        return PermissionType::fromPrefix(substr($this->name, 0, 2));
    }
}
```

#### Role (Entity)

```php
namespace phpbb\auth\Entity;

final readonly class Role
{
    public function __construct(
        public int $id,
        public string $name,          // Language key: 'ROLE_ADMIN_FULL'
        public string $description,   // Language key
        public string $type,          // 'a_', 'f_', 'm_', 'u_'
        public int $order,
    ) {}
}
```

#### PermissionGrant (Value Object)

```php
namespace phpbb\auth\Entity;

final readonly class PermissionGrant
{
    public function __construct(
        public GrantTarget $target,   // User or Group
        public int $targetId,         // user_id or group_id
        public int $forumId,          // 0 = global
        public ?int $roleId,          // null = direct assignment
        public ?int $optionId,        // null = role-based assignment
        public PermissionSetting $setting, // YES, NEVER, or NO
    ) {}
}
```

### 7.5 Enums

```php
namespace phpbb\auth\Enum;

enum PermissionType: string
{
    case Admin = 'a_';
    case Forum = 'f_';
    case Moderator = 'm_';
    case User = 'u_';

    public static function fromPrefix(string $prefix): self
    {
        return self::from($prefix);
    }
}

enum PermissionSetting: int
{
    case Yes = 1;
    case Never = 0;
    case No = -1;
}

enum PermissionScope: string
{
    case Global = 'global';
    case Local = 'local';
}
```

### 7.6 DB Tables Operated On

| Table | Operations by Auth Service |
|-------|--------------------------|
| `phpbb_acl_options` | READ (load option registry, cache as `_acl_options`) |
| `phpbb_acl_roles` | READ/WRITE (role CRUD via RoleService) |
| `phpbb_acl_roles_data` | READ/WRITE (role permission mappings via RoleService, role cache via AclCacheService) |
| `phpbb_acl_users` | READ/WRITE (user grants via PermissionService, raw data for cache build) |
| `phpbb_acl_groups` | READ/WRITE (group grants via PermissionService, raw data for cache build) |
| `phpbb_users.user_permissions` | READ/WRITE (cache bitstring via AclCacheRepository) |
| `phpbb_users.user_perm_from` | READ/WRITE (permission switch tracking) |

**Tables NOT owned but READ from**:
| Table | Why |
|-------|-----|
| `phpbb_user_group` | Group membership lookup during resolution |
| `phpbb_groups` | Group `skip_auth` flag check during resolution |
| `phpbb_forums` | Forum ID list for negated permission queries |

### 7.7 Integration with `phpbb\user`

**Dependencies on user service** (injected via DI):

| Dependency | Interface | Usage |
|-----------|-----------|-------|
| User entity | `phpbb\user\Entity\User` | Input to `isGranted()` — provides `id`, `type`, `groupId` |
| UserType enum | `phpbb\user\Enum\UserType` | Founder detection (`Founder = 3`) |
| Group entity | `phpbb\user\Entity\Group` | `skipAuth` flag check |
| GroupMembership | `phpbb\user\Entity\GroupMembership` | `isPending` exclusion |
| UserRepository | `phpbb\user\Contract\UserRepositoryInterface` | Fallback user lookup by ID (if only user_id available) |
| GroupRepository | `phpbb\user\Contract\GroupRepositoryInterface` | Group membership queries for permission resolution |

**Flow**:
```
phpbb\user produces → User entity
phpbb\auth consumes → User entity for authorization checks
phpbb\auth reads → user_permissions column directly (not via User entity)
```

### 7.8 PermissionResolver — The Core Algorithm

The `PermissionResolver` encapsulates the 5-layer resolution algorithm as a stateless service:

```php
namespace phpbb\auth\Service;

final class PermissionResolver
{
    public function __construct(
        private readonly AclRepositoryInterface $aclRepo,
        private readonly AclCacheServiceInterface $cacheService,
        private readonly \phpbb\user\Contract\GroupRepositoryInterface $groupRepo,
    ) {}

    /**
     * Resolve all permissions for a user into a permission array.
     * This is the expensive path — called only on cache miss.
     *
     * @return array<int, array<int, PermissionSetting>> [forum_id => [option_id => setting]]
     */
    public function resolve(int $userId, UserType $userType): array
    {
        $hold = [];

        // Layer 1-2: User direct + role grants
        $userGrants = $this->aclRepo->getUserGrants($userId);
        $roleCache = $this->cacheService->getRoleCache();
        foreach ($userGrants as $grant) {
            if ($grant->roleId !== null) {
                // Expand role from cache
                $this->mergeRoleGrants($hold, $grant->forumId, $roleCache[$grant->roleId] ?? []);
            } else {
                $hold[$grant->forumId][$grant->optionId] = $grant->setting;
            }
        }

        // Layer 3-4: Group direct + role grants (NEVER-wins merge)
        $memberships = $this->groupRepo->getMembershipsForUser($userId);
        foreach ($memberships as $membership) {
            if ($membership->isPending) continue;
            $group = $this->groupRepo->findById($membership->groupId);
            if ($group->skipAuth && $membership->isLeader) continue;

            $groupGrants = $this->aclRepo->getGroupGrants($membership->groupId);
            foreach ($groupGrants as $grant) {
                if ($grant->roleId !== null) {
                    foreach (($roleCache[$grant->roleId] ?? []) as $optId => $setting) {
                        $this->mergeGroupPermission($hold, $grant->forumId, $optId, $setting);
                    }
                } else {
                    $this->mergeGroupPermission($hold, $grant->forumId, $grant->optionId, $grant->setting);
                }
            }
        }

        // Layer 5: Founder override
        if ($userType === UserType::Founder) {
            $options = $this->cacheService->getOptionRegistry();
            foreach ($options['global'] as $name => $index) {
                if (str_starts_with($name, 'a_')) {
                    $hold[0][$options['id'][$name]] = PermissionSetting::Yes;
                }
            }
        }

        return $hold;
    }

    /**
     * NEVER-wins merge for group permissions.
     * Once NEVER is set, no source can override it.
     */
    private function mergeGroupPermission(array &$hold, int $forumId, int $optionId, PermissionSetting $setting): void
    {
        if (!isset($hold[$forumId][$optionId]) || $hold[$forumId][$optionId] !== PermissionSetting::Never) {
            $hold[$forumId][$optionId] = $setting;
        }
    }
}
```

---

## 8. REST API Middleware Concept

### 8.1 Route Permission Configuration (YAML)

```yaml
# src/phpbb/common/config/default/routing/api.yml

# Public — no auth required
api_health:
    path:     /api/v1/health
    defaults: { _controller: 'phpbb.api.v1.controller.health:index', _api_public: true }
    methods:  [GET]

api_auth_login:
    path:     /api/v1/auth/login
    defaults: { _controller: 'phpbb.api.v1.controller.auth:login', _api_public: true }
    methods:  [POST]

api_auth_signup:
    path:     /api/v1/auth/signup
    defaults: { _controller: 'phpbb.api.v1.controller.auth:signup', _api_public: true }
    methods:  [POST]

# Authenticated — user-level permissions
api_forums:
    path:     /api/v1/forums
    defaults:
        _controller: 'phpbb.api.v1.controller.forums:index'
        _api_permission: f_list
    methods:  [GET]

api_forum_topics:
    path:     /api/v1/forums/{id}
    defaults:
        _controller: 'phpbb.api.v1.controller.forums:topics'
        _api_permission: f_read
        _api_forum_param: id          # Route param that is the forum_id
    methods:  [GET]

api_topics:
    path:     /api/v1/topics
    defaults:
        _controller: 'phpbb.api.v1.controller.topics:index'
        _api_permission: f_read
    methods:  [GET]

api_topic_show:
    path:     /api/v1/topics/{id}
    defaults:
        _controller: 'phpbb.api.v1.controller.topics:show'
        _api_permission: f_read
        # forum_id resolved by controller from topic data
    methods:  [GET]

api_users_me:
    path:     /api/v1/users/me
    defaults:
        _controller: 'phpbb.api.v1.controller.users:me'
        # No specific permission — any authenticated user
    methods:  [GET]
```

### 8.2 AuthorizationMiddleware (Refactored auth_subscriber)

```php
namespace phpbb\api\event;

use phpbb\auth\Contract\AuthorizationServiceInterface;
use phpbb\auth\Exception\AccessDeniedException;
use phpbb\user\Contract\UserRepositoryInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class AuthorizationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly AuthorizationServiceInterface $authService,
        private readonly UserRepositoryInterface $userRepo,
        private readonly TokenServiceInterface $tokenService,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 8]]; // Priority 8: after router (32), before default (0)
    }

    public function onKernelRequest(GetResponseEvent $event): void
    {
        $request = $event->getRequest();

        // Only handle API paths
        if (!$this->isApiPath($request->getPathInfo())) {
            return;
        }

        // Skip public endpoints
        if ($request->attributes->get('_api_public', false)) {
            return;
        }

        // Step 1: Extract and validate JWT
        $token = $this->tokenService->extractFromRequest($request);
        // Throws 401 if missing/invalid/expired

        // Step 2: Resolve user from token claims
        $user = $this->userRepo->findById($token->userId);
        if ($user === null || !$user->isActive()) {
            // 401: User not found or inactive
            throw new UnauthorizedException('Invalid user');
        }

        // Step 3: Check route-level ACL permission
        $requiredPermission = $request->attributes->get('_api_permission');
        if ($requiredPermission !== null) {
            // Determine forum_id from route params if applicable
            $forumParam = $request->attributes->get('_api_forum_param');
            $forumId = $forumParam ? (int) $request->attributes->get($forumParam) : null;

            if (!$this->authService->isGranted($user, $requiredPermission, $forumId)) {
                throw new AccessDeniedException(
                    "Permission '$requiredPermission' denied"
                );
            }
        }

        // Step 4: Store resolved user on request for controllers
        $request->attributes->set('_api_user', $user);
    }
}
```

### 8.3 Endpoint → phpBB ACL Permission Mapping

| API Endpoint | Method | ACL Permission | Scope | Forum Source |
|-------------|--------|---------------|-------|-------------|
| `/api/v1/health` | GET | — (public) | public | — |
| `/api/v1/auth/login` | POST | — (public) | public | — |
| `/api/v1/auth/signup` | POST | — (public) | public | — |
| `/api/v1/auth/refresh` | POST | — (public) | public | — |
| `/api/v1/forums` | GET | `f_list` | any forum | List only forums where granted |
| `/api/v1/forums/{id}` | GET | `f_read` | specific forum | Route param `id` |
| `/api/v1/forums/{id}/topics` | GET | `f_read` | specific forum | Route param `id` |
| `/api/v1/topics` | GET | `f_read` | any forum | Filter by granted forums |
| `/api/v1/topics/{id}` | GET | `f_read` | topic's forum | Resolved in controller |
| `/api/v1/topics` | POST | `f_post` | specific forum | Request body `forum_id` |
| `/api/v1/topics/{id}/posts` | POST | `f_reply` | topic's forum | Resolved in controller |
| `/api/v1/users/me` | GET | — | authenticated | — (any valid user) |
| `/api/v1/admin/*` | * | `a_` (base) | global | — |

**Pattern for forum-scoped endpoints**:
- If forum_id is a route parameter → check in subscriber (`_api_forum_param`)
- If forum_id comes from request body or requires entity lookup → check in controller
- For listing endpoints (forums, topics) → use `getGrantedForums()` to filter results

### 8.4 Error Response Format

```json
// 401 Unauthorized — missing/invalid/expired JWT
{
    "error": {
        "code": 401,
        "message": "Authentication required",
        "type": "unauthorized"
    }
}

// 403 Forbidden — valid JWT but insufficient permissions
{
    "error": {
        "code": 403,
        "message": "Permission 'f_read' denied for forum 5",
        "type": "access_denied"
    }
}
```

Handled by existing `json_exception_subscriber` (priority 10 on `kernel.exception`).

---

## 9. Risks & Open Questions

### 9.1 High-Priority Risks

| Risk | Probability | Impact | Mitigation |
|------|------------|--------|------------|
| **Permission semantics regression** — new service resolves differently than legacy | Medium | 🔴 Critical | Extensive testing with real ACL data; build test fixtures from live DB dump |
| **Performance regression** — losing O(1) read path | Low | 🔴 Critical | Preserve bitfield cache architecture; benchmark against legacy |
| **JWT secret hardcoded** — remains un-addressed | High | 🔴 Critical | Must be moved to DI parameter / env var before any production use |
| **`firebase/php-jwt` not in composer.json** | High | 🟡 Medium | Add to `require` section immediately |

### 9.2 Medium-Priority Risks

| Risk | Probability | Impact | Mitigation |
|------|------------|--------|------------|
| **User entity spec changes** — `phpbb\user` not yet implemented | Medium | 🟡 Medium | Code against interfaces, not concrete classes |
| **Extension-added permissions** — options beyond 125 core | Medium | 🟡 Medium | Dynamic option loading from `acl_options` table, cache invalidation on change |
| **Stale JWT claims** — permissions change after token issued | Medium | 🟡 Medium | Short-lived tokens (15 min), re-check ACL on every request for critical operations |
| **Backward compatibility** — legacy ACP must still work | Medium | 🟡 Medium | Same DB tables, same semantics, coexistence period |

### 9.3 Open Questions

| # | Question | Impact | Recommendation |
|---|----------|--------|----------------|
| 1 | Should the auth service support the `user_perm_from` (switch permissions) feature? | Low | Exclude from v1, add later if admin tools need it |
| 2 | Should JWT embed ACL permissions or always check DB? | Medium | Hybrid: embed scope/type in JWT, check specific permissions from DB |
| 3 | How to handle topics endpoint where forum_id requires entity lookup? | Medium | Controller-level check using `$authService->isGranted()` directly |
| 4 | Should `acl_clear_prefetch()` emit a Symfony event for cache warming? | Low | Yes — add `PermissionsClearedEvent` for observability |
| 5 | How to test the 5-layer resolution algorithm comprehensively? | High | Build test fixtures from DB dump (all 7 default groups), parameterized tests per layer |
| 6 | Should the bitfield format be preserved or replaced with a simpler cache? | Medium | Preserve for backward compatibility with legacy ACP; abstract behind interface for future replacement |
| 7 | How to handle `m_*` permissions that are both global and local? | Medium | Already solved: `isGranted()` checks both `acl[0]` and `acl[$f]`, OR'd together — same as legacy |
| 8 | Token refresh strategy — JWT+refresh or API keys? | Medium | JWT+refresh tokens: short-lived access (15 min), refresh in `phpbb_api_tokens` table |

---

## 10. Recommended Next Steps

### Phase 1: Core Authorization Service (P0)

| Step | Deliverable | Complexity |
|------|------------|------------|
| 1.1 | Create `phpbb\auth\Enum\` — PermissionType, PermissionSetting, PermissionScope | Low |
| 1.2 | Create `phpbb\auth\Entity\` — Permission, Role, PermissionGrant | Low |
| 1.3 | Create `phpbb\auth\Contract\` — AuthorizationServiceInterface, AclCacheServiceInterface | Low |
| 1.4 | Implement `AclCacheRepository` — read/write `user_permissions` column via PDO | Medium |
| 1.5 | Implement `AclCacheService` — bitfield encode/decode, option registry, role cache | High |
| 1.6 | Implement `PermissionResolver` — 5-layer resolution algorithm | High |
| 1.7 | Implement `AuthorizationService` — `isGranted()`, `isGrantedAny()`, `getGrantedForums()` | Medium |
| 1.8 | Write unit tests with fixtures from DB dump | High |

### Phase 2: REST API Integration (P0)

| Step | Deliverable | Complexity |
|------|------------|------------|
| 2.1 | Add `_api_permission`, `_api_scope`, `_api_forum_param` to route YAML | Low |
| 2.2 | Refactor `auth_subscriber` → `AuthorizationSubscriber` (priority 8) | Medium |
| 2.3 | Move JWT secret to DI parameter / environment variable | Low |
| 2.4 | Add `firebase/php-jwt` to `composer.json` require | Low |
| 2.5 | Replace mock auth controller with `AuthenticationService::login()` delegation | Medium |

### Phase 3: Admin Operations (P1)

| Step | Deliverable | Complexity |
|------|------------|------------|
| 3.1 | Implement `AclRepository` — queries for 5 ACL tables | Medium |
| 3.2 | Implement `PermissionService` — set/remove user/group permissions | High |
| 3.3 | Implement `RoleService` — role CRUD | Medium |
| 3.4 | Implement `acl_clear_prefetch()` equivalent with event dispatch | Medium |

### Phase 4: Token Strategy (P1)

| Step | Deliverable | Complexity |
|------|------------|------------|
| 4.1 | Design `phpbb_api_tokens` migration | Low |
| 4.2 | Implement `TokenService` — JWT + refresh token management | Medium |
| 4.3 | Add `/api/v1/auth/refresh` and `/api/v1/auth/logout` endpoints | Medium |

---

## 11. Appendices

### A. Complete Source List

| # | File | Lines | Purpose |
|---|------|-------|---------|
| 1 | `src/phpbb/forums/auth/auth.php` | 1139 | Legacy ACL class (God-class) |
| 2 | `src/phpbb/forums/auth/provider/provider_interface.php` | 208 | Auth provider contract |
| 3 | `src/phpbb/forums/auth/provider/base.php` | 107 | Abstract null-object base |
| 4 | `src/phpbb/forums/auth/provider/db.php` | 278 | Database auth provider |
| 5 | `src/phpbb/forums/auth/provider_collection.php` | 73 | DI provider resolution |
| 6 | `src/phpbb/forums/session.php` | 930+ | Session lifecycle hooks |
| 7 | `src/phpbb/common/acp/acp_permissions.php` | 1200+ | ACP permission UI module |
| 8 | `src/phpbb/common/acp/acp_permission_roles.php` | 600+ | ACP role CRUD module |
| 9 | `src/phpbb/common/acp/auth.php` | 1140+ | `auth_admin` class |
| 10 | `src/phpbb/common/functions_admin.php` | 3134+ | copy_forum_permissions, cache_moderators |
| 11 | `src/phpbb/common/constants.php` | 240+ | ACL constants, USER_FOUNDER |
| 12 | `src/phpbb/api/event/auth_subscriber.php` | 108 | JWT validation subscriber |
| 13 | `src/phpbb/api/v1/controller/auth.php` | 165 | Mock auth controller |
| 14 | `src/phpbb/common/config/default/routing/api.yml` | ~50 | API route definitions |
| 15 | `src/phpbb/common/config/default/container/services_api.yml` | ~50 | API service config |
| 16 | `src/phpbb/common/config/default/container/services_auth.yml` | ~100 | Auth service config |
| 17 | `src/phpbb/user/IMPLEMENTATION_SPEC.md` | 1670+ | User service spec |

### B. Database Row Counts

| Table | Rows | Notes |
|-------|------|-------|
| `phpbb_acl_options` | 125 | 42 admin + 33 forum + 15 moderator + 35 user |
| `phpbb_acl_roles` | 48 | 24 unique roles (duplicated in install) |
| `phpbb_acl_roles_data` | 422 | Role → permission mappings |
| `phpbb_acl_users` | 2 | Admin user direct grants only |
| `phpbb_acl_groups` | 154 | 7 groups × ~22 assignments each |

### C. Permission Resolution Test Scenarios

| Scenario | Expected Result | Why |
|----------|----------------|-----|
| Registered user, `f_read`, forum 1 | YES | REGISTERED group has ROLE_FORUM_STANDARD which includes f_read |
| Anonymous user, `f_post`, forum 1 | NO | GUESTS group has ROLE_FORUM_READONLY which excludes f_post |
| Admin user, `a_board`, global | YES | ADMINISTRATORS group has ROLE_ADMIN_STANDARD which includes a_board |
| Founder user, `a_backup`, global | YES | Founder override: ALL a_* forced to YES regardless of role |
| User with NEVER on `f_read`, group grants YES | NO (NEVER) | NEVER-wins rule: NEVER from user blocks group YES |
| User in BOTS group, `f_list`, forum 1 | YES | BOTS group has ROLE_FORUM_BOT which includes f_list |
| User in NEW_MEMBER group, `f_announce`, forum 1 | NO | ROLE_FORUM_NEW_MEMBER excludes f_announce |
| Global moderator, `m_edit`, forum 5 | YES | GLOBAL_MODERATORS has ROLE_MOD_FULL globally → OR with any forum |
| User pending in group, group grants YES | NO | Pending members excluded from permission resolution |
| User leader in skip_auth group | Skipped | Group's permissions not applied for this user |

### D. JWT Payload Evolution

**Current (Phase 1 mock)**:
```json
{
    "iss": "phpBB",
    "iat": 1700000000,
    "exp": 1700003600,
    "user_id": 2,
    "username": "admin",
    "admin": true
}
```

**Proposed (Phase 2)**:
```json
{
    "iss": "phpBB",
    "iat": 1700000000,
    "exp": 1700000900,
    "sub": 2,
    "username": "admin",
    "user_type": 3,
    "scopes": ["a_", "m_", "u_"],
    "jti": "unique-token-id"
}
```

Changes:
- `user_id` → `sub` (JWT standard claim)
- `admin: true` → `scopes: ["a_", ...]` (ACL-derived)
- `user_type` added (for Founder detection without DB hit)
- `jti` added (for token revocation via blacklist)
- TTL reduced: 3600s → 900s (15 minutes)

---

*Report generated by static codebase analysis. All findings based on direct source code reading and live database inspection. No runtime testing performed.*
