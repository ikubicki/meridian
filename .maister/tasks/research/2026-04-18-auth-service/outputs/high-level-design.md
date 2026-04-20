# High-Level Design: `phpbb\auth\` Authorization Service

## Design Overview

phpBB's legacy ACL system is a well-optimized but monolithically-implemented 1139-line God-class that mixes authentication orchestration, authorization checking, cache management, and raw SQL queries. The forum needs a modern REST API with per-route permission enforcement, but the current API has **zero ACL checking** — any valid JWT accesses any endpoint. Meanwhile, authentication is already fully handled by `phpbb\user\Service\AuthenticationService`.

The `phpbb\auth\` service is a **pure authorization engine** extracted from the legacy ACL subsystem into clean, type-safe PHP 8.2 services. It preserves the proven **three-value permission model** (YES/NEVER/NO), the deterministic **5-layer resolution algorithm**, and the **O(1) bitfield cache** read path — while decomposing the God-class into 4 focused services with proper DI, repository abstractions, and event-driven cache invalidation. A new **AuthorizationSubscriber** at Symfony event priority 8 bridges the service to the REST API layer, reading `_api_permission` route defaults to enforce ACL on every request.

**Key decisions:**
- Authorization-only scope — authentication stays in `phpbb\user`, no `login()`/`logout()` in this service (ADR-001)
- Preserve the base-36/31-bit bitfield cache format for backward compatibility with legacy ACP (ADR-002)
- Direct PDO access to `phpbb_users.user_permissions` column since it's excluded from the User entity (ADR-003)
- Route defaults (`_api_permission`, `_api_forum_param`) for permission configuration, matching existing Symfony patterns (ADR-004)
- Subscriber priority 8, between RouterListener(32) and default controllers(0) (ADR-005)
- Import `User` entity from `phpbb\user` — single source of truth for user identity (ADR-006)

---

## Architecture

### System Context (C4 Level 1)

```
                                   ┌─────────────┐
                                   │  Mobile /   │
                                   │  SPA Client │
                                   └──────┬──────┘
                                          │ HTTPS + Bearer JWT
                                          ▼
┌──────────────┐              ┌───────────────────────┐              ┌──────────────┐
│  phpbb\user  │──produces──→ │     phpBB REST API     │ ←─routes──  │  Route YAML  │
│  (AuthN)     │  User entity │  (Symfony HttpKernel)  │             │  (_api_perm) │
└──────────────┘              └───────────┬───────────┘              └──────────────┘
                                          │ isGranted()
                                          ▼
                              ┌───────────────────────┐
                              │    phpbb\auth\        │
                              │  (Authorization Svc)  │
                              └───────────┬───────────┘
                                          │ SQL (PDO)
                                          ▼
                              ┌───────────────────────┐
                              │    MySQL Database     │
                              │  5 ACL tables +       │
                              │  user_permissions col │
                              └───────────────────────┘
```

**Actors & Systems:**

| Element | Role |
|---------|------|
| Mobile / SPA Client | Sends HTTP requests with Bearer JWT |
| phpbb\user (AuthN) | Produces `User` entity from login/session; owns `phpbb_users` table structurally |
| phpBB REST API | Symfony HttpKernel routing + subscriber chain |
| Route YAML | Declares `_api_permission` and `_api_forum_param` per route |
| phpbb\auth (AuthZ) | Pure authorization — resolves, caches, checks permissions |
| MySQL Database | Stores 5 ACL tables + `user_permissions` bitfield cache column |

### Container Overview (C4 Level 2)

```
┌─ phpbb\auth\ ─────────────────────────────────────────────────────────────┐
│                                                                           │
│  ┌─────────────────────┐    ┌────────────────────┐                        │
│  │ AuthorizationService│───→│ PermissionResolver  │ ← cold path only     │
│  │  (facade)           │    │  (5-layer algorithm)│    (cache miss)       │
│  │  isGranted()        │    └────────┬───────────┘                        │
│  │  isGrantedAny()     │             │ reads groups                       │
│  │  getGrantedForums() │             ▼                                    │
│  └────────┬────────────┘    ┌─────────────────────┐                       │
│           │                 │  GroupRepository     │ ← from phpbb\user    │
│           │ decode/encode   │  (DI interface)      │                       │
│           ▼                 └─────────────────────┘                        │
│  ┌─────────────────────┐                                                  │
│  │  AclCacheService    │─────────────────────────────────────┐            │
│  │  buildBitstring()   │                                     │            │
│  │  decodeBitstring()  │                                     │            │
│  │  clearPrefetch()    │                                     │            │
│  └────────┬────────────┘                                     │            │
│           │ read/write user_permissions                       │            │
│           ▼                                                  ▼            │
│  ┌─────────────────────┐                        ┌─────────────────────┐   │
│  │ AclCacheRepository  │                        │   AclRepository     │   │
│  │ (user_permissions)  │                        │ (5 ACL tables)      │   │
│  └─────────────────────┘                        └─────────────────────┘   │
│                                                          ▲                │
│  ┌─────────────────────┐  ┌────────────────────┐         │                │
│  │ PermissionService   │  │    RoleService     │─────────┘                │
│  │  setUserPermissions │  │    createRole()    │   admin CRUD             │
│  │  setGroupPermissions│  │    deleteRole()    │                          │
│  └─────────────────────┘  └────────────────────┘                          │
│                                                                           │
└───────────────────────────────────────────────────────────────────────────┘

┌─ REST API Layer ──────────────────────────────────────────────────────────┐
│                                                                           │
│  RouterListener (priority 32)                                            │
│       │ resolves _api_permission, _api_forum_param from route defaults   │
│       ▼                                                                  │
│  AuthorizationSubscriber (priority 8)                                    │
│       │ JWT decode → User lookup → isGranted() → 403 or pass            │
│       ▼                                                                  │
│  Controllers (priority 0)                                                │
│       │ receive User entity from request attributes                      │
│                                                                           │
└───────────────────────────────────────────────────────────────────────────┘
```

---

## Component Breakdown

### Directory Structure

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
│   ├── Permission.php
│   ├── Role.php
│   ├── RolePermission.php
│   └── PermissionGrant.php
├── Enum/
│   ├── PermissionType.php
│   ├── PermissionScope.php
│   ├── PermissionSetting.php
│   └── GrantTarget.php
├── Service/
│   ├── AuthorizationService.php
│   ├── PermissionResolver.php
│   ├── AclCacheService.php
│   ├── PermissionService.php
│   └── RoleService.php
├── Repository/
│   ├── AclRepository.php
│   └── AclCacheRepository.php
├── Event/
│   ├── PermissionsClearedEvent.php
│   └── PermissionDeniedEvent.php
└── Exception/
    ├── AccessDeniedException.php
    └── PermissionNotFoundException.php
```

### Key Components

| Component | Purpose | Responsibilities | Key Interfaces | Dependencies |
|-----------|---------|-----------------|----------------|-------------- |
| **AuthorizationService** | Primary facade for permission checks | `isGranted()`, `isGrantedAny()`, `getGrantedForums()`, `isGrantedInAnyForum()`, `loadPermissions()` | `AuthorizationServiceInterface` | AclCacheService, PermissionResolver |
| **PermissionResolver** | 5-layer resolution algorithm | Collect user/group grants, apply NEVER-wins merge, founder override, produce `[forum_id][option_id => setting]` result | Internal (no public interface) | AclRepository, AclCacheService, GroupRepository (from phpbb\user) |
| **AclCacheService** | Bitfield encode/decode + cache lifecycle | Encode to bitstring, decode from bitstring, clear prefetch, manage option registry, manage role cache | `AclCacheServiceInterface` | AclCacheRepository, AclRepository, file cache, EventDispatcher |
| **PermissionService** | Admin permission CRUD | Set/remove user/group permissions (direct + role-based), copy forum permissions, get raw permission data | `PermissionServiceInterface` | AclRepository, AclCacheService |
| **RoleService** | Admin role CRUD | Create/update/delete/reorder roles, manage role → permission assignments | `RoleServiceInterface` | AclRepository, AclCacheService |
| **AclRepository** | Data access for 5 ACL tables | Query user grants, group grants, roles, role-permission mappings, permission options | `AclRepositoryInterface` | PDO (database connection) |
| **AclCacheRepository** | Data access for `user_permissions` column | Read/write `user_permissions` and `user_perm_from` in `phpbb_users` | `AclCacheRepositoryInterface` | PDO (database connection) |

---

## Detailed Interface Signatures

### Enum Types

```php
namespace phpbb\auth\Enum;

enum PermissionType: string
{
    case Admin = 'a_';
    case Forum = 'f_';
    case Moderator = 'm_';
    case User = 'u_';

    public static function fromPrefix(string $prefix): self;
    public static function fromOptionName(string $name): self; // extracts first 2 chars
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

enum GrantTarget: string
{
    case User = 'user';
    case Group = 'group';
}
```

### Entity / Value Object Classes

```php
namespace phpbb\auth\Entity;

final readonly class Permission
{
    public function __construct(
        public int $id,           // auth_option_id (PK in acl_options)
        public string $name,      // e.g., 'f_read', 'a_board', 'm_edit'
        public bool $isGlobal,    // is_global flag
        public bool $isLocal,     // is_local flag
        public bool $founderOnly, // founder_only flag (currently all 0)
    ) {}

    public function getType(): PermissionType;
    public function isDualScope(): bool;  // isGlobal && isLocal (m_* permissions)
}

final readonly class Role
{
    public function __construct(
        public int $id,            // role_id (PK in acl_roles)
        public string $name,       // Language key: 'ROLE_ADMIN_FULL'
        public string $description,// Language key
        public PermissionType $type,
        public int $order,         // Display ordering
    ) {}
}

final readonly class RolePermission
{
    public function __construct(
        public int $roleId,
        public int $optionId,      // auth_option_id
        public PermissionSetting $setting, // Yes or Never (No is absence)
    ) {}
}

final readonly class PermissionGrant
{
    public function __construct(
        public GrantTarget $target, // User or Group
        public int $targetId,       // user_id or group_id
        public int $forumId,        // 0 = global scope
        public ?int $roleId,        // non-null = role-based assignment
        public ?int $optionId,      // non-null = direct assignment
        public PermissionSetting $setting,
    ) {}

    public function isRoleBased(): bool;   // roleId !== null
    public function isDirect(): bool;      // optionId !== null
}
```

### AuthorizationServiceInterface

```php
namespace phpbb\auth\Contract;

use phpbb\user\Entity\User;

interface AuthorizationServiceInterface
{
    /**
     * Check if user has a specific permission.
     *
     * For global-only permissions (a_*, u_*): forumId is ignored.
     * For local-only permissions (f_*): forumId required, checks acl[$forumId].
     * For dual-scope permissions (m_*): checks acl[0] OR acl[$forumId].
     *
     * @param User        $user       User entity (provides id, type)
     * @param string      $permission Permission name, e.g. 'f_read', 'a_board'
     * @param int|null    $forumId    Forum ID for forum-scoped checks, null for global
     * @return bool
     */
    public function isGranted(User $user, string $permission, ?int $forumId = null): bool;

    /**
     * Check if user has ANY of the specified permissions (short-circuit OR).
     *
     * @param User     $user
     * @param string[] $permissions  Array of permission names
     * @param int|null $forumId
     * @return bool True on first match
     */
    public function isGrantedAny(User $user, array $permissions, ?int $forumId = null): bool;

    /**
     * Get all forum IDs where user has the specified permission.
     *
     * Only meaningful for local-scoped permissions (f_*, m_*).
     * For m_* with global grant, returns ALL forum IDs.
     *
     * @param User   $user
     * @param string $permission Permission name
     * @return int[] Array of forum IDs
     */
    public function getGrantedForums(User $user, string $permission): array;

    /**
     * Check if user has permission in at least one forum.
     * Equivalent to legacy acl_getf_global().
     *
     * @param User   $user
     * @param string $permission
     * @return bool
     */
    public function isGrantedInAnyForum(User $user, string $permission): bool;

    /**
     * Load permissions for a user. Called once per request.
     * Decodes user_permissions bitstring or triggers full resolution + cache rebuild.
     * Subsequent calls for the same user are no-ops (memoized).
     *
     * @param User $user
     */
    public function loadPermissions(User $user): void;
}
```

### AclCacheServiceInterface

```php
namespace phpbb\auth\Contract;

interface AclCacheServiceInterface
{
    /**
     * Get the cached permission bitstring for a user.
     * Returns '' if cache is empty (trigger resolution).
     *
     * @param int $userId
     * @return string Base-36 encoded bitstring or ''
     */
    public function getUserPermissions(int $userId): string;

    /**
     * Store a computed bitstring for a user in phpbb_users.user_permissions.
     *
     * @param int    $userId
     * @param string $bitstring Encoded bitstring from buildBitstring()
     */
    public function storeUserPermissions(int $userId, string $bitstring): void;

    /**
     * Clear cached permissions. Triggers lazy rebuild on next access.
     * Also rebuilds the role cache in file storage.
     * Dispatches PermissionsClearedEvent.
     *
     * @param int|null $userId Null = clear ALL users
     */
    public function clearPrefetch(?int $userId = null): void;

    /**
     * Rebuild role cache from acl_roles_data table.
     * Stored in file cache as '_role_cache'.
     */
    public function rebuildRoleCache(): void;

    /**
     * Get the role cache: roleId → [optionId → PermissionSetting].
     * Loads from file cache, rebuilds on miss.
     *
     * @return array<int, array<int, PermissionSetting>>
     */
    public function getRoleCache(): array;

    /**
     * Get the option registry (loaded from acl_options table, cached).
     *
     * Returns structure:
     *   'global'  => [name => sequential_index, ...]
     *   'local'   => [name => sequential_index, ...]
     *   'id'      => [name => auth_option_id, ...]
     *   'option'  => [auth_option_id => name, ...]
     *
     * @return array{global: array<string,int>, local: array<string,int>, id: array<string,int>, option: array<int,string>}
     */
    public function getOptionRegistry(): array;

    /**
     * Encode a resolved permission array to the base-36 bitstring format.
     *
     * @param array<int, array<int, PermissionSetting>> $permissions [forum_id => [option_id => setting]]
     * @param array                                     $optionRegistry From getOptionRegistry()
     * @return string Encoded multi-line bitstring
     */
    public function buildBitstring(array $permissions, array $optionRegistry): string;

    /**
     * Decode a base-36 bitstring to in-memory permission arrays.
     *
     * @param string $bitstring   Encoded multi-line bitstring
     * @param array  $optionRegistry
     * @return array{acl: array<int, string>, options: array} Decoded acl[forum_id] = binary string
     */
    public function decodeBitstring(string $bitstring, array $optionRegistry): array;
}
```

### AclRepositoryInterface

```php
namespace phpbb\auth\Contract;

use phpbb\auth\Entity\PermissionGrant;
use phpbb\auth\Entity\Permission;
use phpbb\auth\Entity\Role;
use phpbb\auth\Entity\RolePermission;
use phpbb\auth\Enum\PermissionType;
use phpbb\auth\Enum\GrantTarget;

interface AclRepositoryInterface
{
    // --- Permission Options (acl_options table) ---

    /**
     * Load all permission options.
     * @return Permission[]
     */
    public function findAllOptions(): array;

    /**
     * Find a single option by name.
     */
    public function findOptionByName(string $name): ?Permission;

    // --- User Grants (acl_users table) ---

    /**
     * Get all grants (direct + role-based) for a user.
     * @return PermissionGrant[]
     */
    public function getUserGrants(int $userId, ?int $forumId = null): array;

    /**
     * Get user grants filtered by permission type prefix.
     * @return PermissionGrant[]
     */
    public function getUserGrantsByType(int $userId, PermissionType $type, ?int $forumId = null): array;

    /**
     * Insert user grants (delete-then-insert pattern).
     * @param PermissionGrant[] $grants
     */
    public function setUserGrants(int $userId, int $forumId, array $grants): void;

    /**
     * Remove user grants.
     * @param int[]       $userIds
     * @param int|null    $forumId       Null = all forums
     * @param PermissionType|null $type  Null = all types
     */
    public function removeUserGrants(array $userIds, ?int $forumId = null, ?PermissionType $type = null): void;

    // --- Group Grants (acl_groups table) ---

    /**
     * Get all grants for a group.
     * @return PermissionGrant[]
     */
    public function getGroupGrants(int $groupId, ?int $forumId = null): array;

    /**
     * Insert group grants (delete-then-insert pattern).
     * @param PermissionGrant[] $grants
     */
    public function setGroupGrants(int $groupId, int $forumId, array $grants): void;

    /**
     * Remove group grants.
     * @param int[]       $groupIds
     * @param int|null    $forumId
     * @param PermissionType|null $type
     */
    public function removeGroupGrants(array $groupIds, ?int $forumId = null, ?PermissionType $type = null): void;

    // --- Roles (acl_roles + acl_roles_data tables) ---

    /**
     * Find a role by ID.
     */
    public function findRoleById(int $roleId): ?Role;

    /**
     * Get all roles of a given type.
     * @return Role[]
     */
    public function findRolesByType(PermissionType $type): array;

    /**
     * Insert a new role. Returns the new role_id.
     */
    public function insertRole(string $name, string $description, PermissionType $type, int $order): int;

    /** Update role metadata. */
    public function updateRole(int $roleId, string $name, string $description): void;

    /**
     * Delete a role. Callers must handle materialization of role settings
     * to direct grants BEFORE calling this.
     */
    public function deleteRole(int $roleId): void;

    /** Update role order. */
    public function updateRoleOrder(int $roleId, int $newOrder): void;

    // --- Role Permission Mappings (acl_roles_data table) ---

    /**
     * Get all permission mappings for a role.
     * @return RolePermission[]
     */
    public function getRolePermissions(int $roleId): array;

    /**
     * Replace all permission mappings for a role (delete + insert).
     * @param RolePermission[] $permissions
     */
    public function setRolePermissions(int $roleId, array $permissions): void;

    /**
     * Load full role cache data: roleId → [optionId → setting].
     * Used by AclCacheService for role cache build.
     * @return array<int, array<int, int>>
     */
    public function loadAllRoleData(): array;

    // --- Bulk / Copy Operations ---

    /**
     * Copy all grants (user + group) from one forum to target forums.
     */
    public function copyForumGrants(int $sourceForumId, array $targetForumIds): void;

    /**
     * Get all forum IDs that have any ACL grants.
     * @return int[]
     */
    public function getForumIdsWithGrants(): array;
}
```

### AclCacheRepositoryInterface

```php
namespace phpbb\auth\Contract;

interface AclCacheRepositoryInterface
{
    /**
     * Read user_permissions bitstring from phpbb_users.
     *
     * @param int $userId
     * @return string The encoded bitstring, or '' if cache is empty
     */
    public function getUserPermissions(int $userId): string;

    /**
     * Read user_perm_from from phpbb_users.
     *
     * @param int $userId
     * @return int Source user ID (0 = own permissions)
     */
    public function getUserPermFrom(int $userId): int;

    /**
     * Store computed bitstring in phpbb_users.user_permissions.
     * Also resets user_perm_from to 0.
     *
     * @param int    $userId
     * @param string $bitstring
     */
    public function storeUserPermissions(int $userId, string $bitstring): void;

    /**
     * Clear cached permissions by setting user_permissions = ''.
     * Also resets user_perm_from to 0.
     *
     * @param int[]|null $userIds Null = clear ALL users
     */
    public function clearUserPermissions(?array $userIds = null): void;
}
```

### PermissionServiceInterface

```php
namespace phpbb\auth\Contract;

use phpbb\auth\Enum\PermissionType;
use phpbb\auth\Enum\PermissionSetting;
use phpbb\auth\Entity\PermissionGrant;

interface PermissionServiceInterface
{
    /**
     * Set permissions for a user on a forum (or global if forumId=0).
     * Uses delete-then-insert pattern.
     * Triggers cache invalidation for the affected user.
     *
     * @param int                              $userId
     * @param int                              $forumId   0 = global
     * @param array<string, PermissionSetting> $settings  [option_name => setting]
     * @param int|null                         $roleId    Non-null = role-based assignment
     */
    public function setUserPermissions(
        int $userId,
        int $forumId,
        array $settings,
        ?int $roleId = null,
    ): void;

    /**
     * Set permissions for a group on a forum (or global if forumId=0).
     * Uses delete-then-insert pattern.
     * Triggers cache invalidation for ALL users in the group.
     *
     * @param int                              $groupId
     * @param int                              $forumId   0 = global
     * @param array<string, PermissionSetting> $settings  [option_name => setting]
     * @param int|null                         $roleId
     */
    public function setGroupPermissions(
        int $groupId,
        int $forumId,
        array $settings,
        ?int $roleId = null,
    ): void;

    /**
     * Remove all permissions for users on forums.
     * Triggers cache invalidation.
     *
     * @param int[]              $userIds
     * @param int|null           $forumId  Null = all forums
     * @param PermissionType|null $type    Null = all types
     */
    public function removeUserPermissions(
        array $userIds,
        ?int $forumId = null,
        ?PermissionType $type = null,
    ): void;

    /**
     * Remove all permissions for groups on forums.
     * Triggers cache invalidation for ALL affected users.
     *
     * @param int[]              $groupIds
     * @param int|null           $forumId
     * @param PermissionType|null $type
     */
    public function removeGroupPermissions(
        array $groupIds,
        ?int $forumId = null,
        ?PermissionType $type = null,
    ): void;

    /**
     * Copy all user and group permissions from one forum to targets.
     * Triggers cache invalidation for ALL users.
     *
     * @param int   $sourceForumId
     * @param int[] $targetForumIds
     */
    public function copyForumPermissions(int $sourceForumId, array $targetForumIds): void;

    /**
     * Get raw permission data for a user (admin diagnostic tool).
     * Returns grants from acl_users table (NOT resolved, NOT cached).
     *
     * @param int                  $userId
     * @param PermissionType|null  $type    Filter by prefix
     * @return PermissionGrant[]
     */
    public function getRawUserPermissions(int $userId, ?PermissionType $type = null): array;

    /**
     * Get raw permission data for a group (admin diagnostic tool).
     *
     * @param int                 $groupId
     * @param PermissionType|null $type
     * @return PermissionGrant[]
     */
    public function getRawGroupPermissions(int $groupId, ?PermissionType $type = null): array;
}
```

### RoleServiceInterface

```php
namespace phpbb\auth\Contract;

use phpbb\auth\Entity\Role;
use phpbb\auth\Entity\RolePermission;
use phpbb\auth\Enum\PermissionType;
use phpbb\auth\Enum\PermissionSetting;

interface RoleServiceInterface
{
    /**
     * Create a new role. Returns the new role ID.
     * Triggers role cache rebuild.
     *
     * @param string                           $name        Language key
     * @param string                           $description Language key
     * @param PermissionType                   $type
     * @param array<string, PermissionSetting> $settings    [option_name => setting]
     * @return int The new role_id
     */
    public function createRole(
        string $name,
        string $description,
        PermissionType $type,
        array $settings,
    ): int;

    /**
     * Update role name, description, and permission settings.
     * Triggers role cache rebuild + cache invalidation for all users with this role.
     *
     * @param int                              $roleId
     * @param string                           $name
     * @param string                           $description
     * @param array<string, PermissionSetting> $settings
     */
    public function updateRole(
        int $roleId,
        string $name,
        string $description,
        array $settings,
    ): void;

    /**
     * Delete a role. Materializes the role's settings as direct per-option grants
     * for all users/groups that have this role assigned, then deletes the role.
     * Triggers full cache invalidation.
     *
     * @param int $roleId
     */
    public function deleteRole(int $roleId): void;

    /**
     * Get a single role by ID.
     */
    public function getRole(int $roleId): ?Role;

    /**
     * Get all roles of a given type, ordered by role_order.
     * @return Role[]
     */
    public function getRolesByType(PermissionType $type): array;

    /**
     * Move a role up or down in the display order.
     *
     * @param int    $roleId
     * @param string $direction 'up' or 'down'
     */
    public function reorderRole(int $roleId, string $direction): void;

    /**
     * Get all permission settings for a role.
     * @return RolePermission[]
     */
    public function getRolePermissions(int $roleId): array;
}
```

---

## Permission Resolution Algorithm

### Overview

Permission resolution transforms raw ACL data from 5 database tables into a single binary bitstring per user. This is the most security-critical piece of the system. The algorithm has 5 layers, processed in order, with NEVER-wins as the core safety invariant.

### Complete Algorithm (PermissionResolver::resolve)

```
INPUT:  userId (int), userType (UserType enum)
OUTPUT: array<int, array<int, PermissionSetting>>  [forumId => [optionId => setting]]

STEP 1 — Load role cache
    roleCache = AclCacheService.getRoleCache()
    // roleCache: roleId → [optionId → PermissionSetting]
    // Loaded from file cache '_role_cache', rebuilt from acl_roles_data on miss.

STEP 2 — Collect user direct + role grants (Layer 1-2)
    hold = []
    userGrants = AclRepository.getUserGrants(userId)
    // SQL: SELECT forum_id, auth_option_id, auth_role_id, auth_setting
    //      FROM phpbb_acl_users WHERE user_id = :userId

    FOR EACH grant IN userGrants:
        IF grant.roleId IS NOT NULL:
            // Role-based: expand all options from role cache
            FOR EACH (optId, setting) IN roleCache[grant.roleId]:
                hold[grant.forumId][optId] = setting
        ELSE:
            // Direct: set single option
            hold[grant.forumId][grant.optionId] = grant.setting

STEP 3 — Collect group grant + role grants with NEVER-wins (Layer 3-4)
    memberships = GroupRepository.getMembershipsForUser(userId)
    // SQL: SELECT ug.group_id, ug.user_pending, ug.group_leader,
    //             g.group_skip_auth
    //      FROM phpbb_user_group ug
    //      JOIN phpbb_groups g ON ug.group_id = g.group_id
    //      WHERE ug.user_id = :userId

    FOR EACH membership IN memberships:
        IF membership.isPending:
            CONTINUE  // Pending members get no permissions

        group = GroupRepository.findById(membership.groupId)
        IF group.skipAuth AND membership.isLeader:
            CONTINUE  // Leaders of skip-auth groups get no group permissions

        groupGrants = AclRepository.getGroupGrants(membership.groupId)
        // SQL: SELECT forum_id, auth_option_id, auth_role_id, auth_setting
        //      FROM phpbb_acl_groups WHERE group_id = :groupId

        FOR EACH grant IN groupGrants:
            IF grant.roleId IS NOT NULL:
                FOR EACH (optId, setting) IN roleCache[grant.roleId]:
                    mergeGroupPermission(hold, grant.forumId, optId, setting)
            ELSE:
                mergeGroupPermission(hold, grant.forumId, grant.optionId, grant.setting)

STEP 4 — Founder override (Layer 5)
    IF userType == UserType::Founder:
        optionRegistry = AclCacheService.getOptionRegistry()
        FOR EACH (name, index) IN optionRegistry['global']:
            IF name starts with 'a_':
                hold[0][optionRegistry['id'][name]] = PermissionSetting::Yes
        // Founders get ALL admin permissions, overriding even NEVER

STEP 5 — Return resolved permissions
    RETURN hold

---

FUNCTION mergeGroupPermission(hold, forumId, optionId, setting):
    IF hold[forumId][optionId] NOT SET:
        hold[forumId][optionId] = setting       // First source wins
    ELSE IF hold[forumId][optionId] != PermissionSetting::Never:
        hold[forumId][optionId] = setting       // Override non-NEVER
        // If new setting is NEVER, it sticks permanently
    // ELSE: already NEVER → immutable, skip
```

### Resolution Priority (highest to lowest)

| Priority | Source | Behavior |
|----------|--------|----------|
| 1 | **Founder override** | Forcibly sets ALL `a_*` globals to YES (runs last, overrides NEVER for admin perms) |
| 2 | **ACL_NEVER from any source** | Immutable once set — no subsequent YES can override |
| 3 | **User direct grants** | Processed first, sets initial hold values |
| 4 | **User role grants** | Expanded from role cache, merged into hold |
| 5 | **Group direct grants** | Merged with NEVER-wins rule |
| 6 | **Group role grants** | Merged with NEVER-wins rule |
| 7 | **ACL_NO (absent)** | Default — becomes 0 in bitstring |

### Security Invariants

1. **NEVER-wins**: Once any source sets `PermissionSetting::Never` for an option, only Founder override for `a_*` can overrule it. All other permissions with NEVER are immutable.
2. **Founder applies LAST**: The Founder override runs after all merges, ensuring Founder always has admin access.
3. **Pending exclusion**: Users with `user_pending = 1` in a group get ZERO permissions from that group.
4. **Skip-auth exclusion**: Group leaders in `group_skip_auth = 1` groups get no permissions from that group.

---

## Bitfield Encode/Decode

### Encode Algorithm (`buildBitstring`)

```
INPUT:  permissions: array<int, array<int, PermissionSetting>>  [forumId => [optionId => setting]]
        optionRegistry: from getOptionRegistry()
OUTPUT: string — multi-line base-36 encoded bitstring

1. Determine maxForumId from permissions keys.
2. For forumId = 0 to maxForumId:
     IF forumId NOT in permissions:
         Append empty line ("\n")
         CONTINUE

     options = optionRegistry['global'] if forumId == 0, else optionRegistry['local']

     // Build binary string: one bit per option in sequential order
     binaryStr = ""
     FOR EACH (optionName, sequentialIndex) IN options (sorted by index):
         optionId = optionRegistry['id'][optionName]
         IF permissions[forumId][optionId] == PermissionSetting::Yes:
             binaryStr += "1"
         ELSE:
             binaryStr += "0"

     // Auto-set category flags: if any a_foo=1 and a_≠NEVER, set a_=1
     // (Reproduce legacy behavior, but do NOT rely on category flags for auth checks)

     // Pad to 31-bit boundary
     padLength = ceil(len(binaryStr) / 31) * 31
     binaryStr = str_pad(binaryStr, padLength, "0", PAD_RIGHT)

     // Convert 31-bit chunks to 6-char base-36
     encoded = ""
     FOR i = 0 TO len(binaryStr) STEP 31:
         chunk = binaryStr[i : i+31]
         base36 = base_convert(chunk, 2, 36)
         encoded += str_pad(base36, 6, "0", PAD_LEFT)

     Append encoded + "\n"

3. Trim trailing newline.
4. RETURN result
```

### Decode Algorithm (`decodeBitstring`)

```
INPUT:  bitstring: string — multi-line base-36 encoded
        optionRegistry: from getOptionRegistry()
OUTPUT: acl: array<int, string>  [forumId => binary permission string]

1. Split bitstring by "\n" — each element is a forum line.
   Line index = forumId (line 0 = global, line N = forum N).

2. chunkCache = []  // Memoize decoded 6-char chunks

3. FOR EACH (forumId, line) IN lines:
     IF line is empty: CONTINUE

     binary = ""
     FOR i = 0 TO len(line) STEP 6:
         chunk = line[i : i+6]
         IF chunk IN chunkCache:
             binary += chunkCache[chunk]
         ELSE:
             decoded = str_pad(base_convert(chunk, 36, 2), 31, "0", PAD_LEFT)
             chunkCache[chunk] = decoded
             binary += decoded

     acl[forumId] = binary

4. Validate lengths:
     expectedGlobal = ceil(count(optionRegistry['global']) / 31) * 31
     expectedLocal  = ceil(count(optionRegistry['local']) / 31) * 31
     IF acl[0] length != expectedGlobal OR any acl[f] length != expectedLocal:
         RETURN null  // Triggers full cache rebuild

5. RETURN acl
```

### Read-Time Lookup (O(1))

```
FUNCTION isGranted(acl, optionRegistry, permission, forumId):
    result = false

    // Global check
    IF permission IN optionRegistry['global']:
        globalIdx = optionRegistry['global'][permission]
        result = result OR (acl[0][globalIdx] == '1')

    // Local check (only if forumId given)
    IF forumId != null AND permission IN optionRegistry['local']:
        localIdx = optionRegistry['local'][permission]
        IF forumId IN acl:
            result = result OR (acl[forumId][localIdx] == '1')

    RETURN result
```

**Key property**: After decode, permission checking is a single character comparison at a known index — true O(1).

---

## REST API Middleware Architecture

### Subscriber Chain (Priority Ordering)

```
Priority 32 — RouterListener (Symfony built-in)
    │  Resolves route → sets _controller, _route, and route defaults
    │  including _api_permission, _api_forum_param, _api_public
    ▼
Priority 8 — AuthorizationSubscriber (new)
    │  1. Skip if _api_public = true
    │  2. Extract Bearer JWT from Authorization header
    │  3. Validate JWT (signature, expiration, claims)
    │  4. Resolve User entity from JWT sub claim via UserRepository
    │  5. Check user.isActive() → 401 if inactive
    │  6. Read _api_permission from request attributes
    │  7. If _api_permission set:
    │     a. Read _api_forum_param → extract forumId from route params
    │     b. Call AuthorizationService::isGranted(user, permission, forumId)
    │     c. If denied → throw AccessDeniedException (→ 403)
    │  8. Set _api_user = User entity on request (for controllers)
    ▼
Priority 0 — Controllers
    │  Access User via: $request->attributes->get('_api_user')
    │  May do additional fine-grained permission checks in controller logic
    ▼
Priority -10 — json_exception_subscriber (existing)
       Catches AccessDeniedException → 403 JSON
       Catches UnauthorizedException → 401 JSON
```

### Route Configuration Pattern

```yaml
# Route with forum-scoped permission check at subscriber level
api_forum_topics:
    path:     /api/v1/forums/{id}/topics
    defaults:
        _controller: 'phpbb.api.v1.controller.forums:topics'
        _api_permission: f_read       # ACL option name to check
        _api_forum_param: id          # Route param containing forum_id
    methods:  [GET]

# Route with global permission (no forum param)
api_admin_settings:
    path:     /api/v1/admin/settings
    defaults:
        _controller: 'phpbb.api.v1.controller.admin:settings'
        _api_permission: a_board      # Global admin permission
    methods:  [GET]

# Public route (no auth required)
api_health:
    path:     /api/v1/health
    defaults:
        _controller: 'phpbb.api.v1.controller.health:index'
        _api_public: true
    methods:  [GET]

# Authenticated-only route (no specific permission)
api_users_me:
    path:     /api/v1/users/me
    defaults:
        _controller: 'phpbb.api.v1.controller.users:me'
        # No _api_permission → subscriber only verifies valid JWT + active user
    methods:  [GET]
```

### Permission Check Strategies

| Strategy | When | Where Checked |
|----------|------|---------------|
| **Route-level** (`_api_permission`) | Permission maps 1:1 to route | AuthorizationSubscriber |
| **Controller-level** | Forum ID needs entity lookup (e.g., topic→forum) | Controller calls `isGranted()` directly |
| **Filter-level** | List endpoints filter by permission (e.g., visible forums) | Controller calls `getGrantedForums()` |

### Endpoint → Permission Mapping

| Endpoint | Method | Permission | Scope | Forum Source |
|----------|--------|-----------|-------|-------------|
| `/api/v1/health` | GET | — (public) | — | — |
| `/api/v1/auth/login` | POST | — (public) | — | — |
| `/api/v1/auth/signup` | POST | — (public) | — | — |
| `/api/v1/auth/refresh` | POST | — (public) | — | — |
| `/api/v1/forums` | GET | `f_list` | any forum | filter by `getGrantedForums()` |
| `/api/v1/forums/{id}` | GET | `f_read` | specific | route param `id` |
| `/api/v1/forums/{id}/topics` | GET | `f_read` | specific | route param `id` |
| `/api/v1/topics` | GET | `f_read` | any forum | filter by `getGrantedForums()` |
| `/api/v1/topics/{id}` | GET | `f_read` | topic's forum | resolved in controller |
| `/api/v1/topics` | POST | `f_post` | specific | request body `forum_id` |
| `/api/v1/topics/{id}/posts` | POST | `f_reply` | topic's forum | resolved in controller |
| `/api/v1/users/me` | GET | — | authenticated | — |
| `/api/v1/admin/*` | * | `a_` (base) | global | — |

---

## Integration Points

### What `phpbb\auth` imports from `phpbb\user`

| Import | Full Path | Usage in Auth Service |
|--------|-----------|----------------------|
| `User` entity | `phpbb\user\Entity\User` | Input to `isGranted()`. Provides `$user->id`, `$user->type`, `$user->groupId` |
| `UserType` enum | `phpbb\user\Enum\UserType` | Founder detection: `UserType::Founder` in PermissionResolver |
| `Group` entity | `phpbb\user\Entity\Group` | `$group->skipAuth` flag check during group permission resolution |
| `GroupMembership` entity | `phpbb\user\Entity\GroupMembership` | `$membership->isPending` and `$membership->isLeader` checks |
| `UserRepositoryInterface` | `phpbb\user\Contract\UserRepositoryInterface` | `findById(int)` — resolve user from JWT user_id claim in AuthorizationSubscriber |
| `GroupRepositoryInterface` | `phpbb\user\Contract\GroupRepositoryInterface` | `getMembershipsForUser(int)` — group membership lookup in PermissionResolver |

### What `phpbb\auth` accesses directly (shared DB)

| Column | Table | Direction | Why Direct Access |
|--------|-------|-----------|-------------------|
| `user_permissions` | `phpbb_users` | READ/WRITE | Excluded from User entity by design. Auth owns this column semantically. |
| `user_perm_from` | `phpbb_users` | READ/WRITE | Permission-switch tracking. Excluded from v1 (ADR-007) but column still reset to 0 on cache clear. |

### DI Service Registration

```yaml
# services_auth.yml

services:
    phpbb.auth.repository.acl:
        class: phpbb\auth\Repository\AclRepository
        arguments: ['@database_connection']

    phpbb.auth.repository.acl_cache:
        class: phpbb\auth\Repository\AclCacheRepository
        arguments: ['@database_connection']

    phpbb.auth.service.acl_cache:
        class: phpbb\auth\Service\AclCacheService
        arguments:
            - '@phpbb.auth.repository.acl_cache'
            - '@phpbb.auth.repository.acl'
            - '@cache.driver'
            - '@event_dispatcher'

    phpbb.auth.service.permission_resolver:
        class: phpbb\auth\Service\PermissionResolver
        arguments:
            - '@phpbb.auth.repository.acl'
            - '@phpbb.auth.service.acl_cache'
            - '@phpbb.user.repository.group'  # from phpbb\user

    phpbb.auth.service.authorization:
        class: phpbb\auth\Service\AuthorizationService
        arguments:
            - '@phpbb.auth.service.acl_cache'
            - '@phpbb.auth.service.permission_resolver'

    phpbb.auth.service.permission:
        class: phpbb\auth\Service\PermissionService
        arguments:
            - '@phpbb.auth.repository.acl'
            - '@phpbb.auth.service.acl_cache'

    phpbb.auth.service.role:
        class: phpbb\auth\Service\RoleService
        arguments:
            - '@phpbb.auth.repository.acl'
            - '@phpbb.auth.service.acl_cache'

    phpbb.api.event.authorization_subscriber:
        class: phpbb\api\event\AuthorizationSubscriber
        arguments:
            - '@phpbb.auth.service.authorization'
            - '@phpbb.user.repository.user'
            - '@phpbb.api.service.token'
        tags:
            - { name: kernel.event_subscriber }
```

---

## Database Access Patterns

### AclRepository Queries

| Method | Table(s) | SQL Pattern | When Called |
|--------|----------|-------------|------------|
| `findAllOptions()` | `phpbb_acl_options` | `SELECT auth_option_id, auth_option, is_global, is_local, founder_only FROM phpbb_acl_options ORDER BY auth_option_id` | Option registry build (cached in file cache as `_acl_options`) |
| `getUserGrants(userId)` | `phpbb_acl_users` | `SELECT forum_id, auth_option_id, auth_role_id, auth_setting FROM phpbb_acl_users WHERE user_id = :userId` | Cache rebuild (PermissionResolver) |
| `getGroupGrants(groupId)` | `phpbb_acl_groups` | `SELECT forum_id, auth_option_id, auth_role_id, auth_setting FROM phpbb_acl_groups WHERE group_id = :groupId` | Cache rebuild (PermissionResolver) |
| `setUserGrants(userId, forumId, grants)` | `phpbb_acl_users` | `DELETE FROM phpbb_acl_users WHERE user_id = :userId AND forum_id = :forumId [AND auth_option_id IN (:types)]` then `INSERT INTO phpbb_acl_users (user_id, forum_id, auth_option_id, auth_role_id, auth_setting) VALUES (...)` | Admin: PermissionService |
| `setGroupGrants(...)` | `phpbb_acl_groups` | Same delete-then-insert pattern | Admin: PermissionService |
| `loadAllRoleData()` | `phpbb_acl_roles_data` | `SELECT role_id, auth_option_id, auth_setting FROM phpbb_acl_roles_data` | Role cache build (AclCacheService) |
| `findRolesByType(type)` | `phpbb_acl_roles` | `SELECT * FROM phpbb_acl_roles WHERE role_type = :type ORDER BY role_order` | Admin: RoleService |
| `insertRole(...)` | `phpbb_acl_roles` | `INSERT INTO phpbb_acl_roles (role_name, role_description, role_type, role_order) VALUES (...)` | Admin: RoleService |
| `setRolePermissions(roleId, perms)` | `phpbb_acl_roles_data` | Delete + insert pattern | Admin: RoleService |
| `copyForumGrants(src, targets)` | `phpbb_acl_users` + `phpbb_acl_groups` | `INSERT INTO phpbb_acl_users SELECT ... FROM phpbb_acl_users WHERE forum_id = :src` (for each target) | Admin: PermissionService |

### AclCacheRepository Queries

| Method | Table | SQL Pattern |
|--------|-------|-------------|
| `getUserPermissions(userId)` | `phpbb_users` | `SELECT user_permissions FROM phpbb_users WHERE user_id = :userId` |
| `getUserPermFrom(userId)` | `phpbb_users` | `SELECT user_perm_from FROM phpbb_users WHERE user_id = :userId` |
| `storeUserPermissions(userId, bitstring)` | `phpbb_users` | `UPDATE phpbb_users SET user_permissions = :bitstring, user_perm_from = 0 WHERE user_id = :userId` |
| `clearUserPermissions(userIds)` | `phpbb_users` | `UPDATE phpbb_users SET user_permissions = '', user_perm_from = 0 WHERE user_id IN (:ids)` or `UPDATE phpbb_users SET user_permissions = '', user_perm_from = 0` (if null = all) |

### Cross-Service Reads (from `phpbb\user` tables, via interfaces)

| Data Needed | Interface Method | Table(s) |
|-------------|-----------------|----------|
| Group memberships for user | `GroupRepositoryInterface::getMembershipsForUser(int)` | `phpbb_user_group` |
| Group entity (skipAuth flag) | `GroupRepositoryInterface::findById(int)` | `phpbb_groups` |
| User entity from JWT claim | `UserRepositoryInterface::findById(int)` | `phpbb_users` |
| All forum IDs (for negated queries) | `ForumRepositoryInterface::getAllForumIds()` | `phpbb_forums` |

---

## Event Flow

### Events Dispatched

| Event | Dispatched By | When | Payload |
|-------|---------------|------|---------|
| `PermissionsClearedEvent` | `AclCacheService::clearPrefetch()` | After user_permissions set to '' and role cache rebuilt | `?int $userId` (null = all), `bool $rolesCacheRebuilt` |
| `PermissionDeniedEvent` | `AuthorizationSubscriber` | When `isGranted()` returns false for a route-level check | `User $user`, `string $permission`, `?int $forumId`, `string $routeName` |

### Events Consumed (Subscribed To)

| Event | Listener | Purpose |
|-------|----------|---------|
| `core.acl_clear_prefetch_after` (legacy) | Compatibility bridge (if legacy ACP still running) | Trigger `AclCacheService::clearPrefetch()` when legacy code clears cache |
| Group membership changed (from phpbb\user) | `AclCacheService` | Clear affected user's permission cache when they join/leave a group |

### Cache Invalidation Trigger Map

The following operations call `clearPrefetch()`:

| Operation | Scope | clearPrefetch Argument |
|-----------|-------|----------------------|
| Set user permissions | Single user | `userId` |
| Remove user permissions | Multiple users | `userId` per user |
| Set group permissions | All group members | `null` (all users) or user IDs from group |
| Remove group permissions | All group members | `null` or user IDs from group |
| Create/update/delete role | All users with role | `null` (all users) |
| Copy forum permissions | All users | `null` (all users) |
| Forum create/modify/delete | All users | `null` (all users) |
| User joins/leaves group | Single user | `userId` |

---

## Error Handling

### Exception Hierarchy

```
phpbb\auth\Exception\
├── AccessDeniedException          extends phpbb\common\Exception\AccessDeniedException
│   Properties: permission (string), forumId (?int), userId (int)
│   Thrown by: AuthorizationSubscriber (route-level), controllers (fine-grained)
│   HTTP mapping: 403 Forbidden
│
└── PermissionNotFoundException    extends phpbb\common\Exception\NotFoundException
    Properties: permissionName (string)
    Thrown by: AuthorizationService (unknown option name), PermissionService
    HTTP mapping: 404 (unknown permission option)
```

### When Each Is Thrown

| Exception | Trigger | Example |
|-----------|---------|---------|
| `AccessDeniedException` | `isGranted()` returns false for route permission | User without `f_read` accessing `/api/v1/forums/5` |
| `AccessDeniedException` | Controller calls `isGranted()` and gets false | User without `f_post` trying to create topic |
| `PermissionNotFoundException` | Permission name not in option registry | Typo in route config: `_api_permission: f_raed` |

### Error Response Format (from existing json_exception_subscriber)

```json
{
    "error": {
        "code": 403,
        "message": "Permission 'f_read' denied for forum 5",
        "type": "access_denied"
    }
}
```

---

## Cache Strategy

### Three Cache Layers

| Layer | Storage | Key/Location | Lifetime | Invalidation |
|-------|---------|-------------|----------|-------------|
| **File cache** — Option registry | File system (phpBB cache driver) | `_acl_options` | Until extension enable/disable | Cleared when options added/removed |
| **File cache** — Role cache | File system (phpBB cache driver) | `_role_cache` | Until any `clearPrefetch()` call | Rebuilt on every `clearPrefetch()` |
| **DB cache** — User bitstring | `phpbb_users.user_permissions` column | Per-user row | Until user's permissions change | Set to `''` by `clearPrefetch(?userId)` |
| **Memory cache** — Decoded ACL | PHP in-memory array in AuthorizationService | Per-request | Single request lifetime | Reset on new request (stateless) |

### Cache Lifecycle

```
FRESH STATE:
  user_permissions = '<encoded bitstring>'
  In-memory: not loaded yet

FIRST REQUEST:
  1. AuthorizationService::loadPermissions(user) called
  2. AclCacheService::getUserPermissions(userId) → returns bitstring
  3. AclCacheService::decodeBitstring() → in-memory acl[] array
  4. acl[] memoized in AuthorizationService for request lifetime
  5. isGranted() → O(1) lookup in acl[]

INVALIDATION (admin changes permissions):
  1. PermissionService::setUserPermissions() (or group/role change)
  2. AclCacheService::clearPrefetch(userId)
     a. AclCacheRepository::clearUserPermissions([userId])  → user_permissions = ''
     b. AclCacheService::rebuildRoleCache()                 → file cache updated
     c. Dispatch PermissionsClearedEvent
  3. user_permissions is now ''

NEXT REQUEST (cache miss):
  1. AuthorizationService::loadPermissions(user) called
  2. AclCacheService::getUserPermissions(userId) → returns ''
  3. PermissionResolver::resolve(userId, userType)
     a. Queries acl_users, acl_groups, user_group, groups tables
     b. Applies 5-layer resolution algorithm
     c. Returns [forumId => [optionId => setting]]
  4. AclCacheService::buildBitstring() → encode to base-36
  5. AclCacheService::storeUserPermissions(userId, bitstring) → UPDATE phpbb_users
  6. AclCacheService::decodeBitstring() → in-memory acl[]
  7. Normal O(1) lookups resume
```

### Performance Characteristics

| Operation | Cost | Frequency |
|-----------|------|-----------|
| `isGranted()` (hot path) | O(1) — single array index lookup | Every API request |
| `decodeBitstring()` | O(n) where n = option count × forum count | Once per request (memoized) |
| `resolve()` (full rebuild) | 2+ SQL queries + merge algorithm | Only on cache miss |
| `buildBitstring()` | O(n) encoding | Only on cache rebuild |
| `clearPrefetch()` | 1 UPDATE + 1 SELECT + file write | Admin operations only |

---

## Concrete Examples

### Example 1: API request with forum-scoped permission

**Given**: Registered user (id=5, type=Normal) requests `GET /api/v1/forums/3/topics`. Route has `_api_permission: f_read`, `_api_forum_param: id`.

**When**: AuthorizationSubscriber fires at priority 8.

**Then**:
1. JWT decoded → userId=5
2. User entity loaded via `UserRepository::findById(5)`
3. `AuthorizationService::isGranted(user, 'f_read', forumId: 3)` called
4. Permissions loaded: `user_permissions` decoded → `acl[3]` contains binary string
5. `optionRegistry['local']['f_read']` = index 7 (example)
6. `acl[3][7]` = '1' → **access granted**
7. User entity set as `_api_user` on request
8. Controller receives User and renders topics

### Example 2: Founder accessing admin endpoint

**Given**: Founder user (id=2, type=Founder) requests `GET /api/v1/admin/settings`. Route has `_api_permission: a_board`.

**When**: On first request, `user_permissions` is empty (fresh install after cache clear).

**Then**:
1. JWT decoded → userId=2
2. `AuthorizationService::loadPermissions(user)` → getUserPermissions returns ''
3. `PermissionResolver::resolve(2, UserType::Founder)` executes:
   - Layers 1-4: Collects grants from ADMINISTRATORS group (ROLE_ADMIN_STANDARD)
   - Layer 5: **Founder override** — forces ALL `a_*` options to YES
4. `buildBitstring()` → encodes (nearly all 1s for global admin line)
5. `storeUserPermissions(2, bitstring)` → saved to DB for next request
6. `isGranted(user, 'a_board', null)` → checks `acl[0][index_of_a_board]` = '1' → **granted**

### Example 3: Permission denied with NEVER override

**Given**: User (id=10, type=Normal) is in group REGISTERED (has ROLE_FORUM_STANDARD granting `f_post` = YES) BUT also has a direct user grant of `f_post` = NEVER for forum 7.

**When**: User requests `POST /api/v1/forums/7/topics` (requires `f_post`).

**Then**:
1. Permission resolution (on cache miss):
   - Layer 1: User direct grant → `hold[7][f_post_id]` = NEVER
   - Layer 3-4: Group REGISTERED role grants YES for `f_post`
   - `mergeGroupPermission()`: `hold[7][f_post_id]` is already NEVER → **skip** (NEVER immutable)
2. `buildBitstring()` → forum 7 line has `f_post` bit = 0
3. `isGranted(user, 'f_post', forumId: 7)` → `acl[7][f_post_idx]` = '0' → **denied**
4. AuthorizationSubscriber throws `AccessDeniedException` → 403 response

---

## Design Decisions

| # | Decision | See |
|---|----------|-----|
| ADR-001 | Authorization-only scope (exclude authentication) | [decision-log.md](decision-log.md#adr-001) |
| ADR-002 | Preserve bitfield cache format | [decision-log.md](decision-log.md#adr-002) |
| ADR-003 | Direct PDO for user_permissions column | [decision-log.md](decision-log.md#adr-003) |
| ADR-004 | Route defaults for permission configuration | [decision-log.md](decision-log.md#adr-004) |
| ADR-005 | Subscriber priority 8 | [decision-log.md](decision-log.md#adr-005) |
| ADR-006 | Import User entity from phpbb\user | [decision-log.md](decision-log.md#adr-006) |
| ADR-007 | Exclude user_perm_from from v1 | [decision-log.md](decision-log.md#adr-007) |

---

## Out of Scope

| Exclusion | Rationale | Future Plan |
|-----------|-----------|-------------|
| Authentication (login/logout/session) | Fully handled by `phpbb\user\Service\AuthenticationService` | No plan — boundary is permanent |
| OAuth/social login | Not part of ACL authorization | Separate service if needed |
| CAPTCHA/anti-spam | Handled by authentication layer | N/A |
| PM permissions | Separate subsystem from forum ACL | Future evaluation |
| User CRUD | Owned by `phpbb\user` | N/A |
| Ban checking | Owned by `phpbb\user\BanService` | Auth subscriber may call `assertNotBanned()` separately |
| Token generation/refresh strategy | Separate concern from authorization | Token service will be designed independently |
| `user_perm_from` (permission switch) | Low-priority admin feature (ADR-007) | v2 if admin tools need it |
| Extension permissions management UI | ACP module concern, not service | Build when ACP is modernized |
| Category flag auto-calculation bug fix | Known bug PHPBB3-10252 | New service ignores category flags for auth checks; only uses specific options |

---

## Success Criteria

| # | Criterion | Measurement |
|---|-----------|-------------|
| 1 | Permission semantics match legacy exactly | Parameterized tests using DB fixtures from all 7 default groups produce identical results to legacy `auth::acl_get()` |
| 2 | O(1) permission check on hot path | After `loadPermissions()`, `isGranted()` does zero SQL queries — pure array index lookup |
| 3 | NEVER-wins invariant enforced | No combination of user/group/role grants can override a NEVER setting (except Founder for `a_*`) |
| 4 | REST API routes enforce ACL | All non-public endpoints return 403 when user lacks the required permission |
| 5 | Cache invalidation is correct | After `clearPrefetch()`, next `isGranted()` call triggers full rebuild and returns updated permissions |
| 6 | Backward compatibility with legacy ACP | Same 5 DB tables, same semantics — legacy ACP can coexist with new service during migration |

---

## Implementation Phases

### Phase 1: Core Authorization Service (P0 — No Dependencies)

| Step | Deliverable | Depends On |
|------|------------|------------|
| 1.1 | `phpbb\auth\Enum\` — PermissionType, PermissionSetting, PermissionScope, GrantTarget | — |
| 1.2 | `phpbb\auth\Entity\` — Permission, Role, RolePermission, PermissionGrant | 1.1 |
| 1.3 | `phpbb\auth\Contract\` — All 6 interfaces | 1.1, 1.2 |
| 1.4 | `phpbb\auth\Exception\` — AccessDeniedException, PermissionNotFoundException | — |
| 1.5 | `phpbb\auth\Repository\AclCacheRepository` — user_permissions read/write | 1.3 |
| 1.6 | `phpbb\auth\Repository\AclRepository` — 5 ACL table queries | 1.2, 1.3 |
| 1.7 | `phpbb\auth\Service\AclCacheService` — bitfield encode/decode, option registry, role cache | 1.3, 1.5, 1.6 |
| 1.8 | `phpbb\auth\Service\PermissionResolver` — 5-layer resolution algorithm | 1.3, 1.6, 1.7 |
| 1.9 | `phpbb\auth\Service\AuthorizationService` — facade | 1.3, 1.7, 1.8 |
| 1.10 | Unit tests with fixtures from DB dump | 1.9 |

### Phase 2: REST API Integration (P0 — Depends on Phase 1)

| Step | Deliverable | Depends On |
|------|------------|------------|
| 2.1 | Add `_api_permission`, `_api_forum_param` to route YAML | — |
| 2.2 | `phpbb\api\event\AuthorizationSubscriber` at priority 8 | 1.9 |
| 2.3 | `phpbb\auth\Event\` — PermissionsClearedEvent, PermissionDeniedEvent | — |
| 2.4 | DI service registration (`services_auth.yml`) | 1.* |
| 2.5 | Integration tests (full request → 403 / 200) | 2.2, 2.4 |

### Phase 3: Admin Operations (P1 — Depends on Phase 1)

| Step | Deliverable | Depends On |
|------|------------|------------|
| 3.1 | `phpbb\auth\Service\PermissionService` — set/remove user/group permissions | 1.6, 1.7 |
| 3.2 | `phpbb\auth\Service\RoleService` — role CRUD with materialization on delete | 1.6, 1.7 |
| 3.3 | Unit tests for admin operations | 3.1, 3.2 |
