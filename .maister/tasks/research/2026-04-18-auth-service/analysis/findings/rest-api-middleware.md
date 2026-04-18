# REST API Middleware — Research Findings

**Category**: `rest-api-middleware`
**Date**: 2026-04-18
**Confidence**: High (90%+) — all claims based on direct code reading

---

## 1. Current REST API Auth State

### Architecture Overview

The REST API was implemented as **Phase 1** (mock data, real infrastructure). Three entry points exist:

| Entry Point | Service ID | Container |
|-------------|-----------|-----------|
| `web/api.php` | `api.application` | Forum (shared) |
| `web/adm/api.php` | `admin_api.application` | Forum (shared) |
| `web/install/api.php` | `install_api.application` | Installer (isolated) |

All three use `phpbb\core\Application` which wraps Symfony `HttpKernel`.

**Source**: `web/api.php` (lines 1–27), `src/phpbb/common/config/default/container/services_api.yml`

### Auth Mechanism: JWT (firebase/php-jwt)

The API uses **JWT with HMAC-SHA256** for authentication. Key details:

| Aspect | Current State |
|--------|--------------|
| **Library** | `Firebase\JWT\JWT` (imported but NOT in `composer.json` `require`) |
| **Algorithm** | HS256 |
| **Secret** | Hardcoded: `'phpbb-api-secret-change-in-production'` |
| **TTL** | 3600 seconds (1 hour) |
| **Token transport** | `Authorization: Bearer <token>` header |
| **Token storage** | Stateless — JWT is self-contained, no DB table |

**Critical finding**: `firebase/jwt` is NOT listed in `composer.json` `require` section. It seems to be pulled in as a transitive dependency or was manually added to `vendor/`.

**Source**: `src/phpbb/api/event/auth_subscriber.php` (line 17–19), `src/phpbb/api/v1/controller/auth.php` (line 17–18)

### JWT Payload Structure

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

**Source**: `src/phpbb/api/v1/controller/auth.php` (lines 70–77)

---

## 2. Existing Auth Event Subscriber Analysis

### `phpbb\api\event\auth_subscriber` — Full Analysis

**File**: `src/phpbb/api/event/auth_subscriber.php` (108 lines)
**Service ID**: `api.auth_subscriber` (tagged `kernel.event_subscriber`)
**Priority**: Default (0) on `KernelEvents::REQUEST`

#### What It Does

1. **Guards only API paths**: Checks `getPathInfo()` for `/api/`, `/adm/api/`, `/install/api/` prefixes. Non-API routes pass through untouched.

2. **Exempts public endpoints**: Paths ending with `/health`, `/auth/login`, `/auth/signup` skip authentication.

3. **Extracts Bearer token**: Reads `Authorization` header, expects `Bearer <token>` format.

4. **Decodes JWT**: Uses `Firebase\JWT\JWT::decode()` with hardcoded secret and HS256 algorithm.

5. **Stores claims on request**: `$request->attributes->set('_api_token', $claims)` — controllers access via `$request->attributes->get('_api_token')`.

6. **Error responses**: Returns 401 JSON for:
   - Missing/malformed Authorization header
   - Expired token (`ExpiredException`)
   - Invalid signature (`SignatureInvalidException`)
   - Any other JWT error (`UnexpectedValueException`)

#### What It Does NOT Do

| Missing Capability | Impact |
|-------------------|--------|
| **No ACL/permission checking** | Any valid JWT grants access to all endpoints |
| **No user existence validation** | Token for deleted user still works until expiry |
| **No DB lookup** | No `phpbb_api_tokens` table, no token revocation |
| **No rate limiting** | Unlimited requests per token |
| **No scope/role-based access** | `admin: true` in JWT is not enforced on any route |
| **No token refresh** | User must re-login after 1 hour |
| **No CSRF check** | Not needed for stateless JWT, but POST endpoints lack it |
| **No configurable secret** | Hardcoded string shared between subscriber and auth controller |

**Source**: `src/phpbb/api/event/auth_subscriber.php` (lines 35–108)

#### Controller Token Usage

Only `forums` controller reads the token:

```php
// src/phpbb/api/v1/controller/forums.php:32
$token = $request->attributes->get('_api_token');
```

It extracts `user_id` and `username` for the `requester` field in the response. No permission check is performed.

The `users/me` endpoint does NOT read the token — it returns hardcoded guest data.

**Source**: `src/phpbb/api/v1/controller/forums.php` (line 32), `src/phpbb/api/v1/controller/users.php`

---

## 3. Auth Controller Analysis

### `phpbb\api\v1\controller\auth` — Login & Signup

**File**: `src/phpbb/api/v1/controller/auth.php` (165 lines)
**Service ID**: `phpbb.api.v1.controller.auth`

#### `POST /api/v1/auth/login`

- **Phase 1 mock**: Only accepts `admin`/`admin` credentials
- Signs JWT with hardcoded secret
- Returns `{"token": "<jwt>", "expires_in": 3600}`
- No DB query — credentials are hardcoded
- **Phase 2 intention**: Will query `phpbb_users` and `phpbb_api_tokens`

**Source**: `src/phpbb/api/v1/controller/auth.php` (lines 44–82)

#### `POST /api/v1/auth/signup`

- **Phase 1 mock**: Validates input (username 3-20 chars, email, password min 6)
- Conflict check: `admin` username and `admin@example.com` email are "taken"
- Returns JWT + user data on success (201)
- New user gets `"admin": false` in JWT
- **Phase 2 intention**: Will INSERT into `phpbb_users`

**Source**: `src/phpbb/api/v1/controller/auth.php` (lines 84–165)

#### Security Issues in Current Code

1. **Hardcoded JWT secret** shared by value (not by reference) between `auth_subscriber` and `auth` controller — easy to get out of sync
2. **No password hashing** — mock comparison is plaintext
3. **JWT secret in source code** — must move to config/env
4. **`admin` flag in JWT** is self-declared, not verified against DB roles

---

## 4. API Routing Configuration

### Routes Defined

**File**: `src/phpbb/common/config/default/routing/api.yml`

| Route Name | Path | Method | Controller | Auth Required |
|-----------|------|--------|------------|---------------|
| `api_health` | `/api/v1/health` | GET | `health:index` | No |
| `api_forums` | `/api/v1/forums` | GET | `forums:index` | **Yes** |
| `api_forum_topics` | `/api/v1/forums/{id}` | GET | `forums:topics` | **Yes** |
| `api_topics` | `/api/v1/topics` | GET | `topics:index` | **Yes** |
| `api_topic_show` | `/api/v1/topics/{id}` | GET | `topics:show` | **Yes** |
| `api_users_me` | `/api/v1/users/me` | GET | `users:me` | **Yes** |
| `api_auth_login` | `/api/v1/auth/login` | POST | `auth:login` | No |
| `api_auth_signup` | `/api/v1/auth/signup` | POST | `auth:signup` | No |

**Key observation**: Routes carry NO metadata about required permissions. The subscriber uses path-suffix matching for public/protected classification — there's no per-route permission declaration.

**Source**: `src/phpbb/common/config/default/routing/api.yml`

### DI Service Configuration

**File**: `src/phpbb/common/config/default/container/services_api.yml`

- `auth_subscriber` has zero constructor arguments — the JWT secret is hardcoded in the class
- Controllers have zero constructor arguments — pure mock, no service dependencies
- No auth service, no ACL service injected anywhere

**Source**: `src/phpbb/common/config/default/container/services_api.yml`

---

## 5. JSON Exception Subscriber

**File**: `src/phpbb/api/event/json_exception_subscriber.php` (97 lines)
**Priority**: 10 on `KernelEvents::EXCEPTION` (fires before HTML subscriber at 0)
**Behavior**: Converts exceptions to JSON for API paths, calls `stopPropagation()` to prevent HTML fallback

This subscriber is well-implemented and does NOT need changes for auth integration. It correctly handles HTTP exception codes and debug mode.

**Source**: `src/phpbb/api/event/json_exception_subscriber.php` (lines 36–97)

---

## 6. Proposed Middleware Integration Pattern

### Current Flow

```
Request → Nginx → api.php → HttpKernel::handle()
    → kernel.request event
        → RouterListener (matches route)
        → auth_subscriber (checks JWT, sets _api_token)
    → Controller (reads _api_token, returns mock data)
    → kernel.response event
    → Response
```

### Proposed Flow (with new `phpbb\auth` service)

```
Request → Nginx → api.php → HttpKernel::handle()
    → kernel.request event
        → RouterListener (matches route, adds _route metadata)
        → auth_subscriber (refactored):
            1. Extract Bearer token from Authorization header
            2. Call phpbb\auth\Service\TokenService::validateToken($raw_token)
               → Returns TokenClaims DTO (user_id, username, roles, scopes)
               → Throws InvalidTokenException / TokenExpiredException
            3. Call phpbb\auth\Service\AuthorizationService::checkRouteAccess(
                   $token_claims, $route_name, $route_params
               )
               → Maps route to required ACL permissions
               → Checks user has permission via ACL engine
               → Throws AccessDeniedException (→ 403)
            4. Store resolved user + permissions on request attributes:
               $request->attributes->set('_api_user', $user_entity)
               $request->attributes->set('_api_permissions', $resolved_permissions)
    → Controller (reads _api_user, _api_permissions)
    → kernel.response event
    → Response
```

### Subscriber Priority Considerations

| Subscriber | Event | Priority | Purpose |
|-----------|-------|----------|---------|
| `RouterListener` | `kernel.request` | 32 | URL → route matching (Symfony default) |
| **`auth_subscriber`** | `kernel.request` | **8** (recommended) | JWT validation + ACL check |
| Other subscribers | `kernel.request` | 0 | Any post-auth processing |
| `json_exception_subscriber` | `kernel.exception` | 10 | JSON error responses |

**Rationale**: Priority 8 runs after routing resolution (32) so `_route` attribute is available, but before default priority (0) handlers. Currently `auth_subscriber` uses default priority (0) — it should be raised to ensure it runs before any controller-adjacent logic.

**Source**: Analysis of Symfony HttpKernel event priority system, comparing with existing `json_exception_subscriber` at priority 10

---

## 7. Route → Permission Mapping Approach

### Option A: Route Defaults in YAML (Recommended)

Add permission metadata directly to route definitions:

```yaml
api_forums:
    path:     /api/v1/forums
    defaults:
        _controller: phpbb.api.v1.controller.forums:index
        _api_permission: f_list    # phpBB ACL option
        _api_scope: public         # public|user|moderator|admin
    methods:  [GET]

api_forum_topics:
    path:     /api/v1/forums/{id}
    defaults:
        _controller: phpbb.api.v1.controller.forums:topics
        _api_permission: f_read
        _api_scope: user
        _api_forum_param: id       # which route param is the forum_id
    methods:  [GET]
```

**Pros**:
- Permission requirements visible in routing config
- `auth_subscriber` reads `$request->attributes->get('_api_permission')` after RouterListener resolves the route
- No code changes needed to add new permissions — just edit YAML
- Forum-scoped permissions can reference route parameters (`_api_forum_param`)

**Cons**:
- Complex permissions (multiple required) need array syntax
- Custom permission logic (e.g., "own resource only") still needs controller code

### Option B: Static Map in Auth Subscriber

```php
private const ROUTE_PERMISSIONS = [
    'api_forums'       => ['scope' => 'public', 'acl' => 'f_list'],
    'api_forum_topics' => ['scope' => 'user',   'acl' => 'f_read', 'forum_param' => 'id'],
    'api_topic_show'   => ['scope' => 'user',   'acl' => 'f_read'],
    'api_users_me'     => ['scope' => 'user',   'acl' => null],
];
```

**Pros**: All mapping in one place, easy to test.
**Cons**: Config changes require code changes, harder to maintain.

### Option C: Controller Annotations/Attributes (PHP 8.1+)

```php
#[ApiPermission(scope: 'user', acl: 'f_read', forumParam: 'id')]
public function topics(Request $request, int $id): JsonResponse
```

**Pros**: Permission requirements live next to the code.
**Cons**: Requires attribute reader in subscriber, reflection overhead, not the phpBB way.

### Recommendation: Option A (Route Defaults)

Best fit for Symfony 3.4 routing infrastructure already in use. The `auth_subscriber` reads `_api_*` defaults from the matched route without any framework extensions.

### Permission Scope Levels

| Scope | Meaning | JWT Requirement |
|-------|---------|-----------------|
| `public` | No auth needed | None |
| `user` | Any authenticated user | Valid JWT |
| `moderator` | User with `m_` permissions | JWT + `acl_get('m_*')` |
| `admin` | User with `a_` permissions | JWT + `acl_get('a_*')` |
| `forum` | Forum-scoped permission | JWT + `acl_f_get($perm, $forum_id)` |

---

## 8. Token Strategy Recommendation

### Current: JWT Only (Phase 1 Mock)

Adequate for mock phase but insufficient for production because:
- No token revocation
- No refresh mechanism
- Claims (`admin: true`) are self-signed, not DB-verified
- Hardcoded secret

### Recommended: JWT + Database Token Registry (Hybrid)

| Component | Purpose |
|-----------|---------|
| **JWT** | Short-lived access token (15-30 min TTL) |
| **Refresh token** | Long-lived, stored in `phpbb_api_tokens` table, used to obtain new JWT |
| **`phpbb_api_tokens` table** | Token registry for revocation, audit, multi-device management |

#### Proposed `phpbb_api_tokens` Schema

```sql
CREATE TABLE phpbb_api_tokens (
    token_id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    token_hash      VARCHAR(64) NOT NULL,      -- SHA-256 of refresh token
    token_type      TINYINT NOT NULL DEFAULT 0, -- 0=refresh, 1=api_key
    scopes          VARCHAR(255) DEFAULT '*',   -- comma-separated ACL scopes
    created_at      INT UNSIGNED NOT NULL,
    expires_at      INT UNSIGNED NOT NULL,
    last_used_at    INT UNSIGNED DEFAULT 0,
    revoked         TINYINT(1) DEFAULT 0,
    ip_address      VARCHAR(40),
    user_agent      VARCHAR(255),
    INDEX idx_user_id (user_id),
    INDEX idx_token_hash (token_hash),
    FOREIGN KEY (user_id) REFERENCES phpbb_users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### Token Flow

```
1. POST /api/v1/auth/login
   → Validates credentials via phpbb\user\Service\AuthenticationService
   → Creates refresh token in phpbb_api_tokens
   → Signs JWT with user_id, username, roles from DB
   → Returns: { access_token: <jwt>, refresh_token: <opaque>, expires_in: 900 }

2. GET /api/v1/forums (protected)
   → auth_subscriber validates JWT
   → If expired: client calls POST /api/v1/auth/refresh
   → auth_subscriber resolves user from JWT claims
   → auth_subscriber checks ACL permission for route

3. POST /api/v1/auth/refresh
   → Validates refresh_token against phpbb_api_tokens
   → Issues new JWT (short-lived)
   → Rotates refresh token (old one invalidated)

4. POST /api/v1/auth/logout
   → Revokes refresh token in DB
   → JWT remains valid until expiry (stateless by design)
```

#### JWT vs API Keys vs Session-Based Comparison

| Approach | Stateless | Revocable | Multi-client | phpBB ACL Compatible | Complexity |
|----------|-----------|-----------|--------------|---------------------|------------|
| **JWT only** | ✅ | ❌ | ✅ | Medium (claims stale) | Low |
| **JWT + refresh** | ✅ (access) | ✅ (refresh) | ✅ | ✅ | Medium |
| **API keys** | ❌ (DB hit) | ✅ | ✅ | ✅ | Low |
| **Session-based** | ❌ (DB hit) | ✅ | ❌ (single device) | ✅ (existing infra) | Low |

**Recommendation**: **JWT + refresh tokens**. Short-lived JWTs (15 min) give stateless performance for reads, while refresh tokens in `phpbb_api_tokens` provide revocation and audit trail. This aligns with the already-implemented JWT infrastructure and the `phpbb\user\Service\AuthenticationService` which returns `Session` objects.

---

## 9. Integration with `phpbb\user` Service

The `phpbb\user\Service\AuthenticationService` (from the implementation spec) provides:

| Method | Returns | Relevance to API Auth |
|--------|---------|----------------------|
| `login(LoginDTO)` | `Session` | Phase 2: auth controller delegates to this instead of mock |
| `logout(string $sessionId)` | `void` | Token revocation trigger |
| `validateSession(string $sessionId)` | `?User` | Could validate session-based API access |

### Integration Contract

The new `phpbb\auth` service should:

1. **Accept `User` entity** from `phpbb\user` service to load ACL permissions
2. **Not duplicate login logic** — delegate to `AuthenticationService::login()`
3. **Map JWT `user_id` claim** to user lookup via `UserRepositoryInterface::findById()`
4. **Load ACL from `user_permissions`** field on User entity (cached bitstring)
5. **Provide `checkPermission(int $userId, string $permission, ?int $forumId = null): bool`** as the primary API for middleware

### Proposed Auth Service Interface

```php
namespace phpbb\auth\Contract;

interface AuthorizationServiceInterface
{
    /** Check single global or forum-scoped permission */
    public function isGranted(int $userId, string $permission, ?int $forumId = null): bool;

    /** Check multiple permissions (any must match) */
    public function isGrantedAny(int $userId, array $permissions, ?int $forumId = null): bool;

    /** Get all forums where permission is granted */
    public function getGrantedForums(int $userId, string $permission): array;

    /** Check route-level access (maps route name → required permissions) */
    public function checkRouteAccess(int $userId, string $routeName, array $routeParams = []): bool;
}
```

---

## 10. Gaps & Risks

| Gap | Severity | Recommendation |
|-----|----------|----------------|
| JWT secret hardcoded | 🔴 High | Move to DI parameter / env variable in Phase 2 |
| `firebase/jwt` not in composer.json | 🟡 Medium | Add `"firebase/php-jwt": "^6.0"` to `require` |
| No ACL checking in subscriber | 🔴 High | Primary gap this research addresses — new auth service fills this |
| No token refresh mechanism | 🟡 Medium | Add `/auth/refresh` endpoint + `phpbb_api_tokens` table |
| No token revocation | 🟡 Medium | Add `phpbb_api_tokens` table for refresh token tracking |
| auth controller duplicates secret | 🟡 Medium | Inject secret via DI constructor parameter |
| `users/me` ignores `_api_token` | 🟢 Low | Phase 2: resolve user from token claims |
| No CORS per-origin validation | 🟢 Low | Currently `Access-Control-Allow-Origin: *` in Nginx |
| No rate limiting | 🟢 Low | Phase 3+ concern |

---

## 11. Summary

### Current State
- REST API uses **JWT (HS256)** via `firebase/php-jwt` for authentication
- `auth_subscriber` validates JWT and stores decoded claims on request as `_api_token`
- **No ACL permission checking** exists — any valid JWT can access any endpoint
- Auth controller is **mock only** (hardcoded admin/admin)
- JWT secret is **hardcoded** in two places (subscriber + controller)
- Routes carry **no permission metadata**

### Recommended Integration Path
1. **Route defaults** (`_api_permission`, `_api_scope`) for per-route permission metadata
2. **Refactored `auth_subscriber`** at priority 8 that:
   - Validates JWT (existing logic)
   - Resolves user from `user_id` claim via `phpbb\user` repository
   - Loads ACL permissions via new `phpbb\auth\Service\AuthorizationService`
   - Checks route permission from matched route defaults
   - Returns 403 if insufficient permissions
3. **New `phpbb\auth` service** provides `isGranted()` API wrapping legacy ACL bitfield logic
4. **JWT + refresh token** strategy for production with `phpbb_api_tokens` table
5. **DI-injected JWT secret** to eliminate hardcoded values
