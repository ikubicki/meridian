# Findings: User Service Integration Points for phpbb\auth

**Source**: `src/phpbb/user/IMPLEMENTATION_SPEC.md`, `.maister/tasks/development/2026-04-18-user-service/spec.md`, `src/phpbb/forums/auth/auth.php`, `src/phpbb/api/event/auth_subscriber.php`, `src/phpbb/api/v1/controller/auth.php`
**Category**: `user-service-integration`
**Confidence**: High (95%) — based on complete implementation specs

---

## 1. What phpbb\user Provides

### 1.1 AuthenticationService (login, logout, validateSession)

**Source**: `src/phpbb/user/IMPLEMENTATION_SPEC.md` lines 1100-1210

The `phpbb\user\Service\AuthenticationService` handles the **entire authentication flow**:

| Method | What it does | Returns |
|--------|-------------|---------|
| `login(LoginDTO)` | Credential verification, ban check, attempt limiting, password rehash, session creation, event dispatch | `Session` entity |
| `logout(string $sessionId)` | Session destruction + event dispatch | `void` |
| `validateSession(string $sessionId)` | Finds session → returns user | `?User` entity |

**Login flow** (10 steps):
1. Find user by `username_clean`
2. Check ban status via `BanService::assertNotBanned($userId, $ip, $email)`
3. Check login attempts (max 5)
4. Check inactive status
5. Verify password via `PasswordHasherInterface::verify()`
6. Reset login attempts on success
7. Rehash password if needed
8. Update `user_lastvisit`, `user_last_active`, `user_ip`
9. Create session via `SessionService::create()`
10. Dispatch `UserLoggedInEvent`

**Key conclusion**: Authentication is **fully handled** by the user service. The `phpbb\auth` service does NOT need to implement login/logout/session validation.

### 1.2 SessionService

**Source**: `src/phpbb/user/IMPLEMENTATION_SPEC.md` lines 1609-1670

| Method | Purpose |
|--------|---------|
| `create(userId, ip, browser, forwardedFor, persist, viewOnline)` | Creates session + optional persistent key |
| `findById(sessionId)` | Lookup session |
| `destroy(sessionId)` | Delete session + keys |
| `destroyAllForUser(userId)` | Kill all sessions |
| `touch(sessionId, page)` | Update last activity |
| `gc(maxLifetimeSeconds)` | Garbage collect expired sessions |

Session IDs are `md5(random_bytes(16) . $ip . $browser . time())` — 32 char hex strings.

### 1.3 Other Services (not directly relevant to auth)

| Service | Relevance to auth |
|---------|-------------------|
| `RegistrationService` | Sets `user_permissions = ''` on new users — auth service will need to populate on first access |
| `PasswordService` | Handles password changes/resets — dispatches events auth service could listen to for cache invalidation |
| `BanService` | Handles ban checking — auth service may need to check bans for authorization too |
| `GroupService` | Group membership management — **critical** since ACL resolution depends on group membership |
| `UserSearchService` | User lookups by ID/username — auth service uses this to get user data |

### 1.4 Deliberate Exclusions in User Service Spec

**Source**: `spec.md` "Deliberate Exclusions" section

> ACL/Permissions → `phpbb\authorization\`

The user service **explicitly** excludes all ACL/permission logic. This is the domain of the auth service.

---

## 2. User Entity — Fields Critical for Authorization

**Source**: `src/phpbb/user/IMPLEMENTATION_SPEC.md` lines 290-400

### 2.1 User Entity Properties (auth-relevant)

```php
final class User {
    public readonly int $id;
    public readonly string $username;
    public readonly UserType $type;      // Normal=0, Inactive=1, Ignore=2, Founder=3
    public readonly int $groupId;        // Default group FK
    public readonly int $options;        // Bitfield (int, default 230271)
    // ... 25+ other properties
}
```

### 2.2 Fields NOT in User Entity but in phpbb_users Table

**Critical finding**: The User entity does **NOT** include these ACL-related columns:

| Column | Type | Purpose | In User Entity? |
|--------|------|---------|-----------------|
| `user_permissions` | mediumtext | Cached ACL bitstring | **NO** |
| `user_perm_from` | mediumint(8) | Permission copy source user | **NO** |

**Evidence**: The `fromRow()` mapping in the spec (lines 405-440) maps ~32 columns to entity properties, but `user_permissions` and `user_perm_from` are absent. The only reference to `user_permissions` in the spec is in the `RegistrationService` where it's set to empty string on user creation (line 1266).

**Implication**: The auth service will need its **own repository method** to read `user_permissions` and `user_perm_from` from `phpbb_users`. It cannot get these from the `User` entity.

### 2.3 UserType Enum (Critical for Founder Logic)

```php
enum UserType: int {
    case Normal = 0;
    case Inactive = 1;
    case Ignore = 2;
    case Founder = 3;
}
```

**Founder rule in legacy auth.php** (line ~460):
```php
if ($userdata['user_type'] == USER_FOUNDER) {
    foreach ($this->acl_options['global'] as $opt => $id) {
        if (strpos($opt, 'a_') === 0) {
            $hold_ary[0][$this->acl_options['id'][$opt]] = ACL_YES;
        }
    }
}
```

Founders automatically receive ALL `a_*` (admin) global permissions. The auth service needs access to `UserType` to implement this.

### 2.4 User Entity Helper Methods

```php
$user->isFounder()   // type === UserType::Founder
$user->isActive()    // type === Normal || Founder
$user->isInactive()  // type === Inactive
```

The auth service can use these directly for quick authorization checks.

### 2.5 Group Entity and GroupMembership

```php
final class Group {
    public readonly int $id;
    public readonly string $name;
    public readonly GroupType $type;
    public readonly bool $skipAuth;       // Important: groups can skip auth
    public readonly bool $founderManage;  // Founder-only management
}

final class GroupMembership {
    public readonly int $groupId;
    public readonly int $userId;
    public readonly bool $isLeader;
    public readonly bool $isPending;
}
```

**`group_skip_auth`**: If set, this group's permissions are skipped during ACL resolution. The auth service needs to know about this.

---

## 3. Clear Boundary: Authentication vs Authorization

### 3.1 Boundary Definition

| Concern | Owner | What it does |
|---------|-------|-------------|
| **Authentication** (AuthN) | `phpbb\user\Service\AuthenticationService` | "Who are you?" — Credential check, session management, ban check, login/logout |
| **Authorization** (AuthZ) | `phpbb\auth\` (new service) | "What can you do?" — ACL checking, permission resolution, role management |

The boundary is clean:
- **User service**: Takes credentials → produces a `User` entity and `Session` entity
- **Auth service**: Takes a `User` entity (or user_id) → resolves what permissions that user has

### 3.2 Flow

```
Request → JWT middleware (auth_subscriber) → decode token → get user_id
       → phpbb\user\AuthenticationService::validateSession() → User entity
       → phpbb\auth\AuthorizationService::checkPermission(User, 'f_read', forumId: 5) → bool
```

### 3.3 What the Auth Service Needs from User Service

| What | How | Interface |
|------|-----|-----------|
| User entity (for user_id, type, groupId) | `UserSearchService::findById(int)` or `AuthenticationService::validateSession(string)` | `User` entity |
| User's group memberships | `GroupService::getGroupsForUser(int)` | `Group[]` |
| Group membership details (pending, leader) | `GroupService::getMemberships(int)` | `GroupMembership[]` |
| Is user a Founder? | `$user->isFounder()` | `bool` |
| user_permissions (cached ACL) | **NOT available via user service** — needs own DB access | Direct PDO query |
| user_perm_from | **NOT available via user service** — needs own DB access | Direct PDO query |

---

## 4. Integration Architecture Options

### 4.1 Option A: Auth Service Depends on User Service Interfaces (Recommended)

```php
namespace phpbb\auth\Service;

final class AuthorizationService
{
    public function __construct(
        private readonly \phpbb\user\Contract\UserRepositoryInterface $userRepo,
        private readonly \phpbb\user\Contract\GroupRepositoryInterface $groupRepo,
        private readonly AclRepositoryInterface $aclRepo, // own repo for ACL tables
        private readonly \PDO $pdo,                        // for user_permissions column
    ) {}
}
```

**Pros**: Clean dependency, reuses user service contracts
**Cons**: Cross-namespace dependency (phpbb\auth depends on phpbb\user)

### 4.2 Option B: Shared Contracts in a Common Namespace

Put shared types in `phpbb\common\` or `phpbb\shared\`:
- Move `UserType` enum to shared namespace
- Create `AuthorizableUser` interface with `getId()`, `getType()`, `getGroupId()`, `isFounder()`
- Both services depend on shared contracts

**Pros**: No circular dependency, clean separation
**Cons**: More files, indirection, the User entity already exists in user service

### 4.3 Option C: Auth Service Receives Primitives Only

```php
$authService->checkPermission(userId: 42, permission: 'f_read', forumId: 5);
```

Auth service looks up everything internally (user data, groups, ACL).

**Pros**: No dependency on user service at all
**Cons**: Duplicated queries (loading user data that's already loaded), cannot reuse user enums

### 4.4 Recommendation: Option A with Narrow Interface

The auth service should:
1. **Import** `phpbb\user\Entity\User` and `phpbb\user\Enum\UserType` directly (read-only dependencies)
2. **Accept** User entity as input to permission checks
3. **Own** its ACL tables and `user_permissions` column access
4. **Use** `GroupRepositoryInterface` from user service for group lookups

This is the cleanest approach because:
- The User entity is a readonly value object — safe to depend on
- The auth service owns all ACL logic (5 ACL tables + user_permissions column)
- Group membership data already exists in the user service — no need to duplicate

---

## 5. `user_permissions` Column — Shared Ownership Problem

### 5.1 Current State

The `user_permissions` column in `phpbb_users` is:
- **Written by**: Legacy `auth::acl_cache()` (builds bitstring from ACL tables)
- **Read by**: Legacy `auth::acl()` → `_fill_acl()` (decodes bitstring into runtime array)
- **Cleared by**: `auth::acl_clear_prefetch()` (sets to empty string on invalidation)
- **Set to ''**: `RegistrationService::register()` (new users start with empty permissions)

### 5.2 Who Should Own user_permissions?

The **auth service** should own read/write of `user_permissions`:
- **Read**: Auth service decodes the cached bitstring for permission checks
- **Write**: Auth service builds the bitstring when cache is empty (on first access or after invalidation)
- **Clear**: Auth service clears the cache when permissions change

The **user service** should:
- Set `user_permissions = ''` on new user creation (already in spec)
- NOT read or interpret the field
- NOT include it in the User entity (already excluded)

### 5.3 Cache Strategy

| Approach | Description | Recommendation |
|----------|-------------|----------------|
| DB cache (`user_permissions`) | Current approach — bitstring in user row | Keep for backward compatibility |
| Per-request in-memory cache | Decode once per request, store in service property | Add on top of DB cache |
| External cache (Redis/Memcached) | Replace DB column with cache layer | Future optimization, not for v1 |

**Recommendation**: Auth service reads `user_permissions` from DB, decodes once per request into an in-memory array (same as legacy `$this->acl` array), and uses that for all permission checks in the request lifecycle.

---

## 6. Events for Cross-Service Communication

### 6.1 Events Auth Service Should Listen To

| Event | Source | Auth Service Action |
|-------|--------|---------------------|
| `UserCreatedEvent` | `RegistrationService` | No action needed (permissions empty until assigned) |
| `PasswordChangedEvent` | `PasswordService` | Consider clearing sessions (optional security measure) |

### 6.2 Events Auth Service Should Dispatch

| Event | When | Consumers |
|-------|------|-----------|
| `PermissionsCacheCleared` | After `acl_clear_prefetch()` equivalent | Cache warming, logging |
| `PermissionsChanged` | After role/grant modification in ACP | Audit logging |
| `PermissionDenied` | When authorization check fails | Security logging, rate limiting |

### 6.3 Integration via EventDispatcherInterface

Both services use the same `EventDispatcherInterface` contract. The auth service should define its own event classes in `phpbb\auth\Event\`.

---

## 7. REST API Integration Gap

### 7.1 Current State

**Source**: `src/phpbb/api/event/auth_subscriber.php`, `src/phpbb/api/v1/controller/auth.php`

The current API middleware:
- Decodes JWT tokens (Bearer header)
- Stores claims in `_api_token` request attribute
- **Does NOT check ACL permissions** — any authenticated user can access any endpoint
- Auth controller is Phase 1 mock (hardcoded admin/admin)

JWT payload:
```php
[
    'iss'      => 'phpBB',
    'iat'      => $now,
    'exp'      => $now + 3600,
    'user_id'  => 2,
    'username' => 'admin',
    'admin'    => true,  // boolean flag, not ACL-based
]
```

### 7.2 What's Missing

1. **Real authentication**: Login should use `phpbb\user\AuthenticationService::login()` instead of hardcoded check
2. **ACL middleware**: After JWT decode, need `phpbb\auth` to check if user has permission for the requested resource
3. **Permission claims in JWT**: Could embed basic permissions in token to avoid DB lookup on every request
4. **Admin flag from ACL**: The `admin: true` flag should come from `acl_get('a_')`, not hardcoded

### 7.3 Proposed Integration Flow

```
1. POST /api/v1/auth/login
   → phpbb\user\AuthenticationService::login(LoginDTO)
   → Get User entity
   → phpbb\auth\AuthorizationService::getQuickPermissions(User)
   → Embed basic permissions in JWT claims
   → Return JWT

2. ANY /api/v1/* (protected endpoint)
   → auth_subscriber decodes JWT
   → Extract user_id from claims
   → Quick check from JWT claims (is_admin, basic perms)
   → For fine-grained checks: phpbb\auth\AuthorizationService::checkPermission(userId, perm, forumId)
```

---

## 8. Shared Types Summary

### Types Auth Service Should Import from User Service

| Type | Namespace | Usage in Auth |
|------|-----------|---------------|
| `User` entity | `phpbb\user\Entity\User` | Input to permission checks |
| `UserType` enum | `phpbb\user\Enum\UserType` | Founder detection |
| `Group` entity | `phpbb\user\Entity\Group` | Group-based permission resolution |
| `GroupMembership` entity | `phpbb\user\Entity\GroupMembership` | Pending member exclusion |
| `Session` entity | `phpbb\user\Entity\Session` | Session validation result |
| `UserRepositoryInterface` | `phpbb\user\Contract` | User data lookups (optional — could use own query) |
| `GroupRepositoryInterface` | `phpbb\user\Contract` | Group membership lookups |

### Types Auth Service Owns (new)

| Type | Purpose |
|------|---------|
| ACL option entity | Permission definition (e.g., `f_read`, `a_ban`) |
| ACL role entity | Named permission role |
| Permission grant value object | User/group → permission mapping |
| Permission result DTO | Result of permission check |
| Own repository interfaces | For 5 ACL tables |

---

## 9. Recommendations

### 9.1 Authentication is DONE — Auth Service = Authorization Only

The `phpbb\user\Service\AuthenticationService` provides complete authentication. The new `phpbb\auth` service should be named `phpbb\auth\Service\AuthorizationService` (or just `phpbb\auth`) and focus exclusively on:
- ACL permission checking (`acl_get`, `acl_getf`, `acl_gets` equivalents)
- Permission cache management (`acl_cache`, `acl_clear_prefetch` equivalents)
- Role and permission grant management (ACP operations)
- Bitfield encoding/decoding

### 9.2 Auth Service Should Read user_permissions Directly

Since `user_permissions` is excluded from the User entity, the auth service needs direct PDO access to read/write this column. Options:
- **Own method**: `AclCacheRepository::getUserPermissions(int $userId): string`
- **Extend user repo**: Not recommended — violates separation

### 9.3 Accept User Entity, Not user_id

Primary API should accept `User` entity:
```php
$authService->can(User $user, string $permission, int $forumId = 0): bool
```
This avoids redundant DB lookups since the User entity is already loaded by the authentication layer. Convencience overload with `int $userId` can load the entity internally.

### 9.4 group_skip_auth Must Be Respected

The `Group::$skipAuth` property from the user service indicates groups whose permissions should be skipped during ACL resolution. The auth service must check this when resolving group-inherited permissions.

### 9.5 Cache Invalidation Triggers

The auth service should clear `user_permissions` when:
- Permissions are modified in ACP (role assignment, direct grant)
- User is added/removed from a group (listen to user service events or provide explicit method)
- Roles are modified (affects all users with that role)

This matches legacy `acl_clear_prefetch()` behavior.
