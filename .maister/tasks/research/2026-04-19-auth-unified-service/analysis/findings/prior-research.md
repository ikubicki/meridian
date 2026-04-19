# Prior Research Synthesis: Unified Auth Service

**Source**: All relevant prior research outputs from `.maister/tasks/research/`
**Purpose**: Extract key decisions, interfaces, contradictions, and assumptions that inform the design of the unified auth service.

---

## 1. The Authentication Gap (Context)

A circular deferral exists across three documents:

| Document | Claims |
|----------|--------|
| Auth Service ADR-001 | "Authentication stays in `phpbb\user`, no `login()`/`logout()` in this service" |
| User Service ADR-001 | "Auth owns all authentication — sessions, tokens, login/logout" |
| Auth Service HLD | "Token generation/refresh strategy — Token service will be designed independently" |

**Result**: Nobody has designed:
- Session management (create, validate, destroy, remember-me)
- Token service (API token CRUD, validation, hashing)
- Login flow orchestration (credential check → session/token creation)
- Logout flow (session/token invalidation)

The unified auth service is the resolution of this gap.

---

## 2. Decisions to Preserve

### From Auth Service (ACL/AuthZ)

| Decision | ID | Rationale | Interface Dependency |
|----------|----|-----------|---------------------|
| **Authorization-only scope for ACL service** | ADR-001 | AuthN and AuthZ are separate concerns with different lifecycles. `isGranted(User $user, ...)` makes dependency explicit. | All controllers call `isGranted()` via `AuthorizationServiceInterface` |
| **Preserve bitfield cache format** | ADR-002 | Legacy ACP backward compatibility during migration. Both old and new code read/write `user_permissions` column. O(1) read performance. | `AclCacheServiceInterface` encode/decode methods |
| **Direct PDO for `user_permissions`** | ADR-003 | Column is semantically owned by auth even though it's in `phpbb_users`. User entity deliberately excludes it. | `AclCacheRepository` (SELECT/UPDATE `user_permissions`) |
| **Route defaults for permission config** | ADR-004 | `_api_permission`, `_api_forum_param`, `_api_public` in route YAML. Standard Symfony `$request->attributes`. | `AuthorizationSubscriber` reads these at priority 8 |
| **AuthorizationSubscriber at priority 8** | ADR-005 | Between RouterListener(32) and controllers(0). Room for rate limiting(16) and request transforms(4). | Fixed priority contract — cannot change without updating all docs |
| **Three-value permission model** | — | YES(1)/NEVER(0)/NO(-1) with NEVER-wins merge, founder override, 5-layer resolution | Core of `PermissionResolver` |

### From Users Service

| Decision | ID | Rationale | Interface Dependency |
|----------|----|-----------|---------------------|
| **User = data, Auth = access control** | ADR-001 | Keeps User focused (~8 services). Auth is single authority for "who is logged in" + "what can they do". Clear direction: Auth → User (never reverse). | Auth imports `User` entity, `GroupRepositoryInterface`, calls `PasswordService::verifyPassword()` |
| **PasswordService in User** | — | Hash owned by User (data). Auth verifies it during login flow. | `verifyPassword(string $plain, string $hash): bool`, `needsRehash()`, `hashPassword()` |
| **User entity fields relevant to Auth** | — | `loginAttempts`, `formSalt`, `type` (UserType::Founder for admin override) | Auth needs `User::$type`, `User::$loginAttempts`, `User::$id` |

### From REST API

| Decision | ID | Rationale | Interface Dependency |
|----------|----|-----------|---------------------|
| **DB tokens (opaque, SHA-256 hashed)** | ADR-005 | Immediately revocable, no key management, `phpbb_api_tokens` table with `label`, `last_used`, `is_active`. | Token auth subscriber SELECT by hash, UPDATE `last_used` |
| **`token_auth_subscriber` at priority 16** | ADR-002 (Phase 2) | Authentication BEFORE authorization (8). User hydration at this level. | Fixed priority — other subscribers rely on user being available after 16 |
| **Session-based for Phase 1, tokens for Phase 2** | ADR-002 | Incremental migration path. Entry point calls `session_begin()` → replaced by subscriber. | Controllers must work with BOTH auth mechanisms during transition |

---

## 3. Decisions to Supersede

| Original Decision | Source | Why It Must Be Superseded | New Decision Needed |
|-------------------|--------|---------------------------|---------------------|
| **"Bearer JWT" references in Auth HLD** | Auth HLD (20+ JWT references, `firebase/php-jwt`) | REST API explicitly designed DB tokens. JWT requires key management, refresh tokens, cannot be instantly revoked. Cross-cutting assessment recommends DB tokens. | Unified service uses **opaque DB tokens only**. All JWT references become "Bearer token". |
| **Auth says "AuthenticationService already exists in phpbb\user"** | Auth ADR-001 context | This service was never actually designed. The User service research explicitly defers AuthN to Auth. | Unified service IS the AuthenticationService. |
| **`$user->session_begin()` + `$auth->acl()` in entry points** | REST API Phase 1 | Legacy pattern — the subscriber chain replaces this entirely. | Unified service's `token_auth_subscriber` handles all user hydration. Entry points become thin bootstrap-only. |
| **`auth_subscriber JWT → _api_user (priority 8)` in Notifications** | Notifications HLD | Wrong priority (collides with AuthZ) and wrong token type. | Authentication at priority 16, authorization at priority 8. Notifications must consume user from request attributes set at priority 16. |

---

## 4. Interface Contracts That Downstream Services Depend On

### AuthorizationServiceInterface (consumed by all API controllers)

```php
interface AuthorizationServiceInterface {
    public function isGranted(User $user, string $permission, ?int $forumId = null): bool;
    public function isGrantedAny(User $user, array $permissions, ?int $forumId = null): bool;
    public function getGrantedForums(User $user, string $permission): array;
    public function isGrantedInAnyForum(User $user, string $permission): bool;
    public function loadPermissions(User $user): void;
}
```

### Token Auth Subscriber Contract (consumed by REST API framework)

- Listens on `kernel.request` at **priority 16**
- Extracts `Authorization: Bearer <raw_token>` header
- Computes `hash('sha256', $raw_token)` → SELECT from `phpbb_api_tokens`
- No row or `is_active = 0` → `JsonResponse 401`, `stopPropagation()`
- Valid → loads User from DB, sets `$request->attributes->set('_api_user', $user)`, calls `$auth->acl()`
- Updates `last_used` timestamp

### AuthorizationSubscriber Contract (consumed by route definitions)

- Listens on `kernel.request` at **priority 8**
- Reads `$request->attributes->get('_api_permission')` from route defaults
- If `_api_public: true` → pass through
- If no `_api_permission` → require authentication only (user exists)
- If `_api_permission` set → call `isGranted($user, $permission, $forumId)`
- Denied → `JsonResponse 403`

### PasswordService Contract (consumed by Auth login flow)

```php
// In phpbb\user — Auth calls these during authentication
public function verifyPassword(string $plain, string $hash): bool;
public function needsRehash(string $hash): bool;
public function hashPassword(string $password): string;
```

### User Entity Fields (consumed by Auth)

- `$user->id` — for permission lookups
- `$user->type` — `UserType::Founder` triggers admin override in ACL
- `$user->loginAttempts` — for throttling
- `$user->passwordHash` — for credential verification

### GroupRepositoryInterface (consumed by PermissionResolver)

```php
public function getMembershipsForUser(int $userId): GroupMembership[];
```

### Events That Auth Must Handle

| Event | Source | Auth's Response |
|-------|--------|----------------|
| `UserDeletedEvent` | User Service | Clear ACL cache (`clearPrefetch($userId)`) |
| `PasswordChangedEvent` | User Service | Invalidate all sessions/tokens except current |
| `UserBannedEvent` | User Service | Kill all active sessions/tokens for user |

---

## 5. Subscriber Priority Chain (Canonical)

| Priority | Subscriber | Service | Responsibility |
|----------|-----------|---------|----------------|
| 32 | `RouterListener` | Symfony | Resolves route, populates `_controller`, `_api_permission`, `_api_forum_param` |
| 16 | `token_auth_subscriber` | **Unified Auth** | AuthN: token → user hydration, sets `_api_user` |
| 10 | `json_exception_subscriber` | REST API | Catches exceptions, returns JSON error |
| 8 | `AuthorizationSubscriber` | **Unified Auth** | AuthZ: ACL check using route defaults |
| 0 | Controllers | Service-specific | Business logic |

---

## 6. DB Token Schema (from REST API ADR-005)

```sql
CREATE TABLE phpbb_api_tokens (
    token_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT(10) UNSIGNED NOT NULL,
    token      CHAR(64) NOT NULL COMMENT 'SHA-256 hex of raw token',
    label      VARCHAR(255) NOT NULL DEFAULT '',
    created    DATETIME NOT NULL,
    last_used  DATETIME DEFAULT NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (token_id),
    UNIQUE KEY uidx_token (token),
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Token lifecycle**: 32 random bytes → hex → `hash('sha256', $raw)` stored in DB → raw returned to client once. Verification: `hash('sha256', $incoming)` → SELECT.

---

## 7. Assumptions Made by Other Services About Auth

| Service | Assumption | Evidence |
|---------|-----------|----------|
| **Threads** | Auth enforces `f_post`, `f_reply`, `m_edit`, `m_delete` per forum | Threads ADR-006: "auth-unaware — ACL enforced externally by API layer" |
| **Hierarchy** | Auth enforces `f_list`, `f_read` before tree data is returned | Hierarchy ADR-006: same pattern, trusts caller |
| **Messaging** | Auth has already authenticated user before service methods are called | "Trusts caller" — no internal auth checks |
| **Notifications** | User object available at `$request->attributes->get('_api_user')` after auth subscriber | References "auth_subscriber → _api_user" |
| **All services** | `$user` passed to service methods is already authenticated and their session/token is valid | Facade pattern: `ServiceFacade::method(User $user, ...)` |
| **Search** | Permission-group caching respects ACL; `getGrantedForums()` used to filter results | Search uses `AuthorizationService::getGrantedForums()` to restrict result set |
| **Admin API** | `acl_get('a_')` gate enforced before any admin endpoint code runs | REST API: admin entry point checks admin permission immediately |

---

## 8. Contradictions That Must Be Resolved

### 8.1 JWT vs DB Tokens (CRITICAL)

| Source | Position |
|--------|----------|
| Auth HLD | "HTTPS + Bearer JWT", references `firebase/php-jwt`, JWT claims, signature validation |
| REST API ADR-005 | DB opaque tokens, SHA-256 hashed, `phpbb_api_tokens` table, DB lookup per request |
| Cross-cutting assessment | Recommends DB tokens — simpler, immediately revocable, no key management |
| Reality check | Still unresolved as of 2026-04-19 |

**Resolution for unified service**: Adopt DB tokens. The REST API design is more detailed, concrete, and appropriate for phpBB (no key rotation, no token refresh complexity, immediate revocation).

### 8.2 Who Owns Session Management?

Currently nobody. The unified service must own:
- Session creation (login flow → create session or token)
- Session validation (subscriber validates on each request)
- Session destruction (logout, ban, password change)
- Remember-me / long-lived sessions

### 8.3 Login Flow Orchestration

The credential verification path crosses services:
1. User submits credentials
2. **Auth** receives request
3. **Auth** calls `UserRepository::findByUsername()` (from User service)
4. **Auth** calls `PasswordService::verifyPassword()` (from User service)
5. **Auth** checks `BanService::isUserBanned()` (from User service)
6. **Auth** creates session/token (own responsibility)
7. **Auth** resets `loginAttempts` (calls User service)

This cross-service flow must be explicit in the unified design.

---

## 9. Open Questions for Unified Service Design

1. **Session table or token-only?** — Does the unified service maintain `phpbb_sessions` (legacy) or go token-only? Or both during migration?

2. **Admin panel authentication** — Admin uses same tokens? Separate admin tokens? Session + SID (legacy)?

3. **CSRF protection** — Legacy uses `form_salt` + `check_form_key()`. REST APIs typically don't need CSRF (token-based). How do state-changing requests authenticate during the session-based Phase 1?

4. **Auth provider extensibility** — Legacy supports multiple auth providers (db, ldap, apache). Does the unified service support pluggable auth providers?

5. **Token scoping** — Are tokens always full-access? Or can they have limited scope (read-only, specific endpoints)?

6. **Rate limiting / brute-force** — `user_login_attempts` exists. Who increments/resets it? What's the lockout threshold?

7. **Password rehash on login** — `needsRehash()` triggers rehash transparently. Where does this happen in the flow?

8. **Remember-me tokens** — Separate from API tokens? Long-lived session cookies?

9. **Multi-device session management** — Can users see/revoke their active sessions?

10. **Token expiry policy** — Tokens currently have no expiry column. Add `expires_at`? Or rely on `is_active` + admin revocation only?

---

## 10. Key Interfaces the Unified Service Must Define

Based on the gap analysis, the unified service must provide AT MINIMUM:

```php
// Authentication (NEW — currently undesigned)
interface AuthenticationServiceInterface {
    public function authenticateByCredentials(string $username, string $password): AuthResult;
    public function authenticateByToken(string $rawToken): AuthResult;
    public function logout(int $userId, ?string $tokenId = null): void;
    public function logoutAll(int $userId): void;
}

// Token Management (NEW — schema exists, service doesn't)
interface TokenServiceInterface {
    public function createToken(int $userId, string $label): TokenResult; // returns raw token once
    public function revokeToken(int $tokenId): void;
    public function revokeAllForUser(int $userId): void;
    public function listTokens(int $userId): ApiToken[];
}

// Authorization (EXISTING — preserve exactly)
interface AuthorizationServiceInterface {
    public function isGranted(User $user, string $permission, ?int $forumId = null): bool;
    public function isGrantedAny(User $user, array $permissions, ?int $forumId = null): bool;
    public function getGrantedForums(User $user, string $permission): array;
    public function isGrantedInAnyForum(User $user, string $permission): bool;
    public function loadPermissions(User $user): void;
}
```

---

## 11. Source Citations

| Source | Location | Key Extractions |
|--------|----------|----------------|
| Auth Service HLD | `2026-04-18-auth-service/outputs/high-level-design.md` | Interfaces, enums, architecture diagrams, subscriber priorities |
| Auth Service Decisions | `2026-04-18-auth-service/outputs/decision-log.md` | ADR-001 through ADR-005 |
| Users Service HLD | `2026-04-19-users-service/outputs/high-level-design.md` | User entity, PasswordService, GroupRepository, events catalog |
| Users Service Decisions | `2026-04-19-users-service/outputs/decision-log.md` | ADR-001 (scope boundary) |
| REST API HLD | `2026-04-16-phpbb-rest-api/outputs/high-level-design.md` | token_auth_subscriber flow, priority 16, entry point patterns |
| REST API Decisions | `2026-04-16-phpbb-rest-api/outputs/decision-log.md` | ADR-002 (session→token), ADR-005 (phpbb_api_tokens schema) |
| Cross-cutting Assessment | `cross-cutting-assessment.md` | §7.2 JWT vs DB token, §7.3 priority conflicts, §6.1 User gap |
| Reality Check | `verification/reality-check.md` | NEW-1 (AuthN gap), GAP-3 (JWT vs DB still open), GAP-6 (priority conflicts) |
