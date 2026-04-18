# Decision Log: `phpbb\auth\` Authorization Service

---

## ADR-001: Authorization-Only Scope (Exclude Authentication)

### Status
Accepted

### Context
The legacy `auth.php` class (1139 lines) is a God-class mixing four concerns: authentication orchestration (`login()` method delegating to auth providers), authorization checking (`acl_get()`, `acl_getf()`, etc.), cache management (`acl_cache()`, `acl_clear_prefetch()`), and raw DB queries for admin operations. The `phpbb\user` service has already been designed with a complete `AuthenticationService` handling login, logout, session validation, password hashing, ban checking, and auth provider delegation. The question is whether the new `phpbb\auth\` namespace should include authentication methods or focus purely on authorization.

### Decision Drivers
- The `phpbb\user\Service\AuthenticationService` already provides a complete 10-step login flow, session management, and auth provider integration
- Authentication ("who are you?") and authorization ("what can you do?") are fundamentally different concerns with different lifecycles
- The legacy `auth.php::login()` method simply delegates to auth providers — it contains zero ACL logic
- Mixing AuthN and AuthZ in one class was the root cause of the 1139-line God-class anti-pattern

### Considered Options
1. **Authorization only** — `phpbb\auth\` contains ACL permission checking, cache, and admin operations. No `login()`/`logout()`.
2. **Full auth** — `phpbb\auth\` wraps both authentication (delegating to `phpbb\user`) and authorization in a unified facade.
3. **Split namespace** — `phpbb\auth\authentication\` + `phpbb\auth\authorization\` sub-namespaces.

### Decision Outcome
Chosen option: **Option 1 (Authorization only)**, because authentication is already fully implemented in the `phpbb\user` service spec with proper separation. Adding authentication to `phpbb\auth\` would create either duplication or a thin wrapper that adds complexity without value. The clean boundary — user service produces a `User` entity, auth service consumes it for permission checks — matches the Single Responsibility Principle and makes both services independently testable.

### Consequences

#### Good
- Clean SRP: each service has one responsibility
- No code duplication between `phpbb\user` and `phpbb\auth`
- The `AuthorizationService::isGranted(User $user, ...)` signature makes the dependency direction explicit
- Both services can be tested independently with mock dependencies
- Clear namespace semantics: `phpbb\auth\` = authorization, `phpbb\user\` = authentication + identity

#### Bad
- Developers may expect `phpbb\auth\` to handle login (legacy naming convention)
- Controllers/middleware must interact with two services (user for identity, auth for permissions)
- The "auth" namespace name is slightly misleading — "authorization" would be more precise, but `phpbb\auth\` is the established convention

---

## ADR-002: Preserve Bitfield Cache Format

### Status
Accepted

### Context
The legacy ACL system caches resolved permissions as a base-36 encoded bitstring in `phpbb_users.user_permissions` (mediumtext column). The format uses 31-bit chunks converted to 6-character base-36 strings, separated by newlines where the line index equals the forum_id. This provides O(1) permission checks after initial decode. Alternative cache formats could be simpler to implement and debug.

### Decision Drivers
- The bitfield format is already proven in production across millions of phpBB installations
- Legacy ACP (Admin Control Panel) reads and writes this exact format via `auth_admin` class
- During migration, both legacy ACP and new REST API must coexist on the same `user_permissions` column
- The O(1) read performance is a critical performance property (every API request)
- The encoding/decoding logic is well-documented and deterministic

### Considered Options
1. **Preserve bitfield format** — port the exact encode/decode algorithm to the new service, maintaining binary compatibility.
2. **JSON format** — store `{"forum_id": {"option_name": true/false}}` in the column. Human-readable, slightly larger.
3. **Redis/external cache** — move permission cache out of DB into Redis or Memcached.
4. **Simplified binary** — raw binary blob (PHP `pack()`/`unpack()`) without base-36 encoding.

### Decision Outcome
Chosen option: **Option 1 (Preserve bitfield format)**, because backward compatibility with the legacy ACP is a hard requirement during the migration period. Both the legacy `auth.php` and the new `AuthorizationService` will read/write the same `user_permissions` column. Any format mismatch would cause permission resolution failures. Additionally, the format is compact (a typical admin user's bitstring is ~200 characters for 125 options across multiple forums), and the O(1) lookup after decode is optimal.

### Consequences

#### Good
- Zero-downtime migration: legacy ACP and new API can coexist
- No schema migration needed for `user_permissions` column
- Proven format — no risk of introducing encoding bugs in a new format
- Cache validation logic (bitstring length check) works unchanged
- Performance characteristics preserved exactly

#### Bad
- The base-36/31-bit encoding is non-trivial to implement and debug
- New developers will find the format opaque compared to JSON
- Unit testing requires building test bitstrings (fixtures from DB dump help)
- Future migration to a different format will require a separate ADR and data migration

---

## ADR-003: Direct PDO Access for `user_permissions` Column

### Status
Accepted

### Context
The `user_permissions` and `user_perm_from` columns live in the `phpbb_users` table, which is structurally owned by the `phpbb\user` service. However, the `User` entity deliberately excludes these columns (confirmed in the user service IMPLEMENTATION_SPEC.md — the `fromRow()` mapping skips them). The auth service needs to read this column on every request and write it on cache rebuild. The question is how to access it.

### Decision Drivers
- `user_permissions` is excluded from the `User` entity by design — it's semantically owned by the auth service
- The auth service needs direct read/write access without going through User entity methods
- The user service already manages `phpbb_users` for user CRUD — two services touching the same table creates coupling
- Parameterized queries (PDO) are required for SQL safety per phpBB standards
- Performance: this column is read on every API request (hot path)

### Considered Options
1. **Dedicated `AclCacheRepository`** — the auth service has its own repository that queries `phpbb_users.user_permissions` directly via PDO prepared statements.
2. **User service exposes methods** — add `getUserPermissions(int $userId): string` and `storeUserPermissions(...)` to `UserRepositoryInterface`.
3. **Shared column via events** — auth service dispatches an event, user service reads/writes the column.

### Decision Outcome
Chosen option: **Option 1 (Dedicated AclCacheRepository)**, because the `user_permissions` column is semantically owned by the auth service even though it resides in the users table. Adding permission-related methods to the User repository would violate the clean separation between authentication and authorization. The `AclCacheRepository` provides a focused interface (`getUserPermissions`, `storeUserPermissions`, `clearUserPermissions`) with exactly the SQL needed — no more, no less. All queries use PDO prepared statements for safety.

### Consequences

#### Good
- Clean separation: auth service has full control over its cache column
- No modifications needed to the user service interfaces
- `AclCacheRepository` can be optimized independently (e.g., SELECT only the needed column)
- Testable in isolation with a mock database connection

#### Bad
- Two services access `phpbb_users` table — potential for conflicting transactions during bulk operations
- Schema changes to `phpbb_users` could affect both services
- Marginally more complex than having a single service own all columns
- Must be documented clearly that auth service "leases" these columns from the users table

---

## ADR-004: Route Defaults for Permission Configuration

### Status
Accepted

### Context
The REST API needs a mechanism to declare which ACL permission is required for each endpoint. When the `AuthorizationSubscriber` fires on `kernel.request`, it needs to know: (a) which permission to check, (b) whether the check is forum-scoped, and (c) where to find the forum_id if applicable. Several approaches exist for configuring this mapping.

### Decision Drivers
- Must integrate with Symfony's existing routing infrastructure
- Configuration should be declarative and visible in route definitions
- Must support: global permissions, forum-scoped permissions, public endpoints, and authenticated-only endpoints
- Should not require code changes to add permission checks to new routes
- The existing API already uses YAML route definitions with `defaults:` section

### Considered Options
1. **Route defaults** — add `_api_permission`, `_api_forum_param`, and `_api_public` as route defaults in YAML. The subscriber reads these from `$request->attributes`.
2. **PHP 8 attributes** — use `#[RequiresPermission('f_read', forumParam: 'id')]` attributes on controller methods.
3. **Dedicated config file** — a separate `permissions.yml` mapping route names to permission requirements.
4. **Middleware stack per route** — configure a specific middleware per route group in service config.

### Decision Outcome
Chosen option: **Option 1 (Route defaults)**, because the existing phpBB REST API already uses YAML route definitions with `defaults:` for `_controller`. Adding `_api_permission` and `_api_forum_param` as additional defaults follows the same pattern, keeps permission requirements co-located with the routes they protect, and is a standard Symfony convention (similar to `_locale`, `_format`). The subscriber reads these via `$request->attributes->get()` with zero reflection overhead.

### Consequences

#### Good
- Zero reflection/annotation parsing overhead at runtime
- Permissions visible in route definitions — easy to audit
- Standard Symfony pattern (`_` prefix reserved for framework/internal attributes)
- Works with YAML, XML, or PHP route loaders
- Supports all scenarios: `_api_public: true`, no permission (auth-only), specific permission, forum-scoped permission

#### Bad
- No compile-time validation — a typo in `_api_permission: f_raed` only fails at runtime
- Slightly less discoverable than PHP attributes for developers used to annotation-based frameworks
- Route files can become verbose with many defaults
- Forum-scoped checks at subscriber level limited to route parameters — body params require controller-level checks

---

## ADR-005: Subscriber Priority 8 (Between Router and Controllers)

### Status
Accepted

### Context
The `AuthorizationSubscriber` listens on `kernel.request` and must fire after the route is resolved (so `_api_permission` is available from route defaults) but before the controller executes (so unauthorized requests are rejected early). Symfony's `RouterListener` runs at priority 32. The default controller resolution is at priority 0. The existing `auth_subscriber` (JWT-only) runs at priority 0.

### Decision Drivers
- Must fire AFTER `RouterListener` (32) so route attributes are available
- Must fire BEFORE controllers (0) to prevent unauthorized code execution
- Should leave room for future subscribers between router and auth (e.g., rate limiting at priority 16)
- Should leave room for future subscribers between auth and controllers (e.g., request transformation at priority 4)
- The priority value should be a conventional round-ish number that's easy to reason about

### Considered Options
1. **Priority 8** — between router (32) and default (0), leaves room for rate limiting (16) and request transforms (4).
2. **Priority 16** — halfway between router and default. Less room for future middlewares above auth.
3. **Priority 1** — just before controllers. Very tight, no room for post-auth pre-controller logic.
4. **Priority -1** — after default. Would execute after most middleware, defeating the purpose of early rejection.

### Decision Outcome
Chosen option: **Option 1 (Priority 8)**, because it provides the best balance of early rejection (failing fast before controller logic) while leaving the priority space cleanly partitioned: 32 (routing) → 16 (available for rate limiting) → 8 (authorization) → 4 (available for request transforms) → 0 (controllers). Priority 8 is a power-of-two that follows a halving pattern, making the priority chain easy to reason about.

### Consequences

#### Good
- Clean priority partitioning with room for growth
- Unauthorized requests rejected before any controller or business logic executes
- Route attributes (`_api_permission`, `_api_forum_param`) guaranteed to be available
- Consistent with the Symfony community convention of using single-digit/low-teen priorities for security subscribers

#### Bad
- If a future subscriber at priority 16 modifies the route (rare), auth checks could be based on stale data
- Developers must remember the priority when adding new subscribers — no enforcement mechanism
- Priority 0 subscribers (if any remain) would fire after auth, potentially with stale assumptions

---

## ADR-006: Import User Entity from `phpbb\user`

### Status
Accepted

### Context
The `AuthorizationService::isGranted()` method needs user identity information — specifically `user_id`, `user_type` (for Founder detection), and `group_id`. This data could come from the `phpbb\user\Entity\User` entity (which already exists in the user service spec), from a local auth-specific user representation, or from raw scalars.

### Decision Drivers
- The `User` entity already contains `id`, `type` (with `UserType::Founder`), `groupId`, `isFounder()`, `isActive()` — everything the auth service needs
- Duplicating user representation creates a synchronization problem
- The DI container makes cross-service entity dependencies natural
- The auth service needs `GroupMembership` and `Group` entities from the user service anyway (for `isPending`, `skipAuth`)
- Using the same `User` entity in both services and in controllers provides a consistent API

### Considered Options
1. **Import `User` entity from `phpbb\user`** — `AuthorizationService::isGranted(User $user, ...)` type-hints the user service's entity directly.
2. **Auth-specific user DTO** — create `phpbb\auth\Dto\AuthSubject` with only the auth-relevant fields (id, type, groupId).
3. **Scalar parameters** — `isGranted(int $userId, UserType $userType, ...)` — no entity dependency.

### Decision Outcome
Chosen option: **Option 1 (Import User entity)**, because the `User` entity is the canonical representation of a user in the system. The auth service already depends on `phpbb\user\Contract\GroupRepositoryInterface` for group membership data — adding a dependency on the `User` entity is zero additional coupling. A local DTO would require mapping logic at every call site. Scalar parameters would lose type safety and make the API error-prone. The `isGranted(User $user, ...)` signature makes the call site expressive and self-documenting.

### Consequences

#### Good
- Single source of truth for user identity across the entire application
- Expressive, type-safe API: `$authService->isGranted($user, 'f_read', forumId: 5)`
- Controllers that already have a `User` entity (from session resolution) can pass it directly to auth checks
- No mapping/conversion overhead
- `$user->isFounder()` available directly in PermissionResolver

#### Bad
- Compile-time coupling: `phpbb\auth` depends on `phpbb\user\Entity\User` — changes to User entity could require auth service updates
- If the user service is not yet implemented, auth service tests must mock the User entity
- The User entity carries ~30 properties but auth only uses 3 (id, type, groupId) — slight violation of Interface Segregation Principle
- Circular dependency risk if user service ever needs auth service (unlikely given the clean boundary)

---

## ADR-007: Exclude `user_perm_from` from v1

### Status
Accepted

### Context
The `phpbb_users` table has a `user_perm_from` column (mediumint, default 0) that supports a "switch permissions" admin feature. When `user_perm_from != 0`, the user inherits permissions from another user — the `acl_cache()` method reads the source user's data instead. This is used by administrators to test how the forum looks for another user. The question is whether v1 of the auth service should support this feature.

### Decision Drivers
- The `user_perm_from` feature is an admin diagnostic tool, not a core authorization requirement
- Supporting it adds complexity to the cache read path (must check another user's permissions)
- The REST API middleware use case doesn't need admin impersonation in v1
- The column still exists in the DB and must be reset to 0 on cache clear (to avoid stale impersonation)
- Implementation is relatively simple (read permissions from source user instead of target) and can be added later

### Considered Options
1. **Exclude from v1** — reset `user_perm_from` to 0 on `clearPrefetch()` but don't implement the switch logic. Always use the user's own permissions.
2. **Include in v1** — implement the full switch logic in `AclCacheService::getUserPermissions()`.
3. **Remove entirely** — drop support for the feature and document the removal.

### Decision Outcome
Chosen option: **Option 1 (Exclude from v1)**, because the permission-switch feature is an admin diagnostic tool that does not affect the core authorization path or the REST API middleware. The column is safely handled by resetting to 0 on every cache clear, which means the v1 service is correct (users always get their own permissions). When admin tools are modernized (future), support can be added by modifying `AclCacheService::getUserPermissions()` to check `user_perm_from` and load the source user's bitstring if non-zero.

### Consequences

#### Good
- Simpler v1 implementation — fewer code paths, fewer tests, fewer edge cases
- No risk of permission escalation through impersonation bugs
- Cache read path is straightforward: read user_permissions for the requested user_id
- Feature can be added in v2 without breaking changes (additive change to AclCacheService)

#### Bad
- Admins cannot use "switch permissions" via the new service/API until v2
- Legacy ACP may set `user_perm_from != 0`, which the new service would ignore (potential confusion)
- The column is still reset to 0 on cache clear, which could disrupt an in-progress impersonation session if both legacy ACP and new service are active simultaneously
