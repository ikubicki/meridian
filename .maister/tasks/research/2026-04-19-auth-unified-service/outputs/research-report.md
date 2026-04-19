# Research Report: Unified Auth Service Design

**Research type**: Mixed (technical + architecture)  
**Date**: 2026-04-19  
**Scope**: Design of `phpbb\auth` as unified AuthN + AuthZ service with stateless JWT tokens and token elevation model

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Research Objectives](#2-research-objectives)
3. [Methodology](#3-methodology)
4. [Authentication Architecture](#4-authentication-architecture)
5. [Authorization Architecture](#5-authorization-architecture)
6. [Token Model](#6-token-model)
7. [Integration Points](#7-integration-points)
8. [Security Analysis](#8-security-analysis)
9. [Recommendations](#9-recommendations)
10. [Open Questions / Future Work](#10-open-questions--future-work)
11. [Appendices](#11-appendices)

---

## 1. Executive Summary

This report defines the architecture for `phpbb\auth` as a unified service handling both authentication (AuthN) and authorization (AuthZ) for the phpBB REST API. The design resolves a critical gap identified across three prior research documents where no service owned session management, token issuance, or login flow orchestration.

**Key architectural decisions:**

- **Stateless JWT access tokens** (15-minute TTL) verified by HMAC signature — no per-request DB lookup for authentication.
- **Server-side refresh tokens** with family-based rotation for session persistence and theft detection.
- **Elevated tokens** (5-minute TTL) for admin/moderator operations, issued after password re-verification — replacing the legacy `session_admin` flag.
- **Existing O(1) ACL bitfield** preserved as the permission resolution engine — loaded from cache once per request, never embedded in JWT.
- **Three-layer revocation**: natural expiry → generation counter → optional JTI deny list.
- **Three sub-components**: `TokenService`, `AuthenticationService`, `AuthorizationService` (last preserved from prior design).

The design achieves a clean separation: JWT handles "who are you?" (statelessly), the bitfield cache handles "what can you do?" (at O(1) cost), and the refresh token table provides the minimal server state needed for session management.

---

## 2. Research Objectives

### Primary Question

How should `phpbb\auth` be designed as a unified service handling both AuthN and AuthZ, with stateless JWT tokens and user/group token elevation model?

### Sub-Questions

1. How do JWT tokens coexist with phpBB's complex ACL system (125 permissions, 4 scopes, NEVER-wins resolution)?
2. What belongs in the JWT vs what stays server-side?
3. How does the "group token" concept map to phpBB's permission model where permissions are NOT group-based?
4. How are tokens revoked when JWT is stateless?
5. What's the elevation flow from user token to admin/moderator token?
6. How does the auth service integrate with User, Hierarchy, and other services?

### Scope

**Included**: Authentication flows, JWT token structure, ACL integration, elevation flow, revocation strategy, security analysis, service interfaces.  
**Excluded**: Implementation code, migration strategy from legacy sessions, admin UI for token management, LDAP/OAuth2 provider support (Phase 2).

---

## 3. Methodology

### Sources Analyzed

| Source | Type | Files | Purpose |
|--------|------|-------|---------|
| Legacy auth providers | Code analysis | `src/phpbb/forums/auth/provider/*.php` | Understand current AuthN |
| Legacy session management | Code analysis | `src/phpbb/forums/session.php` | Understand current session model |
| Legacy ACL system | Code analysis | `src/phpbb/forums/auth/auth.php` (1139 lines) | Understand permission resolution |
| Database schema | Schema analysis | `phpbb_dump.sql` | ACL tables, group tables, session tables |
| Prior research (auth, users, REST API) | Prior output | 6 HLD/decision documents | Extract contracts and contradictions |
| firebase/php-jwt library | Code analysis | `vendor/firebase/php-jwt/src/*.php` | Verify API surface and security |
| Group system | Schema + code | Groups, user_group, acl_groups | Map groups to permissions |

### Analytical Framework

Mixed Technical + Architecture framework:
- **Component analysis**: What exists, how it works, how it integrates
- **Pattern analysis**: Design patterns, consistency, maturity
- **Gap analysis**: What's missing, what conflicts, what's ambiguous
- **Trade-off analysis**: JWT vs DB tokens, stateless vs cached, token size vs lookup cost

---

## 4. Authentication Architecture

### 4.1 Login Flow

```
┌─────────┐                     ┌──────────────────┐              ┌─────────────┐
│ Client  │                     │ phpbb\auth       │              │phpbb\user   │
│         │                     │ AuthenticationSvc │              │PasswordSvc  │
└────┬────┘                     └────────┬─────────┘              └──────┬──────┘
     │                                   │                               │
     │ POST /auth/login                  │                               │
     │ {username, password}              │                               │
     ├──────────────────────────────────►│                               │
     │                                   │                               │
     │                     ┌─────────────┤ 1. Rate limit check           │
     │                     │ IP + user   │    (login_attempts table)     │
     │                     │ throttling  │                               │
     │                     └─────────────┤                               │
     │                                   │                               │
     │                                   │ 2. findByUsername($username)  │
     │                                   ├─────────────────────────────►│
     │                                   │◄────── User entity ──────────┤
     │                                   │                               │
     │                                   │ 3. isBanned($userId)?        │
     │                                   ├─────────────────────────────►│
     │                                   │◄────── bool ─────────────────┤
     │                                   │                               │
     │                                   │ 4. verifyPassword($pw, $hash)│
     │                                   ├─────────────────────────────►│
     │                                   │◄────── bool ─────────────────┤
     │                                   │                               │
     │                                   │ 5. needsRehash($hash)?       │
     │                                   │    → hashPassword($pw)       │
     │                                   ├─────────────────────────────►│
     │                                   │                               │
     │                                   │ 6. Reset login attempts      │
     │                                   │ 7. Determine role + flags    │
     │                                   │ 8. Issue access JWT          │
     │                                   │ 9. Issue refresh token (DB)  │
     │                                   │                               │
     │◄── 200 {access_token,             │                               │
     │     refresh_token, expires_in}    │                               │
```

**Step details:**

1. **Rate limiting**: Check `phpbb_login_attempts` by IP. If over threshold → require CAPTCHA or reject.
2. **User lookup**: `UserRepository::findByUsername()` — returns User entity or null.
3. **Ban check**: `BanService::isUserBanned()` — checks bans by user_id, IP, email.
4. **Password verification**: `PasswordService::verifyPassword($plain, $hash)` — supports legacy MD5 + modern bcrypt/argon.
5. **Rehash**: Transparent password hash upgrade if `needsRehash()` returns true.
6. **Attempt reset**: Clear `login_attempts` in DB, reset `User::$loginAttempts` to 0.
7. **Role determination**: Check permission flags (`a_`, `m_`) to set `role` claim.
8. **JWT issuance**: `TokenService::issueAccessToken()` — HS256-signed, 15-minute TTL.
9. **Refresh token**: Generate opaque token, store SHA-256 hash in `phpbb_auth_refresh_tokens` with family_id.

**Return value:**
```json
{
  "access_token": "<jwt>",
  "refresh_token": "<opaque_64_hex>",
  "token_type": "Bearer",
  "expires_in": 900
}
```

**Error cases:**

| Condition | Response | Side Effect |
|-----------|----------|-------------|
| Empty username/password | 400 Bad Request | None |
| User not found | 401 Unauthorized | Increment IP login attempts |
| User banned | 403 Forbidden | None |
| User inactive (`USER_INACTIVE`) | 403 Forbidden (with reason) | None |
| Wrong password | 401 Unauthorized | Increment user + IP attempts |
| Rate limit exceeded | 429 Too Many Requests | None |

### 4.2 JWT Issuance

```php
// TokenService::issueAccessToken()
$payload = [
    'iss'   => 'phpbb',
    'sub'   => (string) $userId,
    'aud'   => 'phpbb-api',
    'iat'   => time(),
    'exp'   => time() + 900,        // 15 minutes
    'jti'   => self::generateJti(), // UUID v4
    'type'  => 'access',
    'utype' => $user->type,         // 0=NORMAL, 3=FOUNDER
    'gen'   => $user->tokenGeneration,
    'pv'    => $user->permVersion,
    'flags' => $this->computeFlags($userId), // ['u','a','m'] subset
];

return JWT::encode($payload, $this->signingKey, 'HS256', $this->keyId);
```

**Claim rationale:**

| Claim | Type | Purpose | Size |
|-------|------|---------|------|
| `iss` | string | Issuer identity; validated on decode | 5 bytes |
| `sub` | string | User ID — primary identity claim | 1-10 bytes |
| `aud` | string | `phpbb-api` or `phpbb-admin` — audience routing | 9-12 bytes |
| `iat` | int | Issued-at timestamp | 10 bytes |
| `exp` | int | Expiration — auto-validated by library | 10 bytes |
| `jti` | string | Unique token ID — for revocation/deny list | 36 bytes |
| `type` | string | `access` or `elevated` — token type discriminator | 6-8 bytes |
| `utype` | int | User type (0-3) — enables founder override detection | 1 byte |
| `gen` | int | Token generation counter — revocation marker | 1-4 bytes |
| `pv` | int | Permission version — cache freshness check | 1-10 bytes |
| `flags` | array | Permission category flags — quick gate checks | 5-15 bytes |

**Estimated token size**: ~350-400 bytes encoded (well within cookie/header limits).

### 4.3 Token Refresh Flow

```
┌─────────┐                     ┌──────────────────┐              ┌──────┐
│ Client  │                     │ phpbb\auth       │              │  DB  │
│         │                     │ TokenService      │              │      │
└────┬────┘                     └────────┬─────────┘              └──┬───┘
     │                                   │                           │
     │ POST /auth/refresh                │                           │
     │ {refresh_token}                   │                           │
     ├──────────────────────────────────►│                           │
     │                                   │                           │
     │                                   │ hash(refresh_token)       │
     │                                   │ SELECT from refresh_tokens│
     │                                   ├──────────────────────────►│
     │                                   │◄──── stored record ───────┤
     │                                   │                           │
     │                            ┌──────┤ Validate:                 │
     │                            │      │  - exists?                │
     │                            │      │  - revoked = 0?           │
     │                            │      │  - expires_at > now?      │
     │                            └──────┤                           │
     │                                   │                           │
     │                                   │ Rotate:                   │
     │                                   │  - Mark old as revoked    │
     │                                   │  - Generate new refresh   │
     │                                   │  - Insert new (same family)│
     │                                   ├──────────────────────────►│
     │                                   │                           │
     │                                   │ Issue new access JWT      │
     │                                   │ (reload user for gen/pv)  │
     │                                   │                           │
     │◄── 200 {access_token,             │                           │
     │     refresh_token, expires_in}    │                           │
```

**Refresh token rotation** (one-time use):
- Each refresh token can be used exactly once.
- On use: old token marked revoked, new token created with same `family_id`.
- **Theft detection**: If a revoked token is presented again → the entire family is revoked. Legitimate user must re-authenticate.

**Refresh token schema:**

```sql
CREATE TABLE phpbb_auth_refresh_tokens (
    token_id    VARCHAR(64) PRIMARY KEY,    -- SHA-256 of raw token
    user_id     INT UNSIGNED NOT NULL,
    family_id   VARCHAR(36) NOT NULL,       -- UUID, groups rotation chain
    issued_at   INT UNSIGNED NOT NULL,
    expires_at  INT UNSIGNED NOT NULL,
    revoked     TINYINT(1) NOT NULL DEFAULT 0,
    replaced_by VARCHAR(64) NULL,           -- Points to successor
    user_agent  VARCHAR(255) NULL,
    ip_address  VARCHAR(45) NULL,

    INDEX idx_user_id (user_id),
    INDEX idx_family_id (family_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4.4 Revocation Strategy

Three layers, ordered by increasing state requirements:

#### Layer 1: Natural Expiry (Zero State)

Access tokens expire after 15 minutes. Elevated tokens expire after 5 minutes. No action needed — expired tokens are rejected by `firebase/php-jwt` automatically via `exp` claim validation.

**Coverage**: All tokens eventually expire. Max exposure window = TTL.

#### Layer 2: Generation Counter (Minimal State)

```sql
-- Add to phpbb_users:
ALTER TABLE phpbb_users ADD COLUMN token_generation INT UNSIGNED NOT NULL DEFAULT 0;
```

JWT `gen` claim = value at issuance time. Auth middleware checks:

```php
if ($payload->gen < $user->tokenGeneration) {
    throw new TokenRevokedException('Token invalidated by security event');
}
```

**Triggers for increment:**
- Password change
- Account ban
- Forced logout (admin action)
- Security event (suspicious activity detected)

**Cost**: Zero additional DB queries — `user.token_generation` loaded with the user record (already needed for permission checks in most requests).

**Coverage**: All tokens for a user invalidated within one request cycle. Stale tokens rejected on next use.

#### Layer 3: JTI Deny List (Optional, for Immediate Single-Token Revocation)

```php
// Store in cache with TTL = remaining token lifetime
$this->cache->set('jwt_deny:' . $jti, true, $remainingTtl);

// Check in auth middleware
if ($this->cache->has('jwt_deny:' . $payload->jti)) {
    throw new TokenRevokedException();
}
```

**When needed**: Single compromised token that must be killed immediately (e.g., user reports stolen device). Rare scenario.

**Size**: Self-limiting — entries auto-expire when the token would have expired anyway. Max entries = number of forced revocations in a 15-minute window. Typically < 10.

### 4.5 Logout Flow

```php
// POST /auth/logout
public function logout(string $refreshToken): void
{
    // 1. Revoke refresh token family
    $hash = hash('sha256', $refreshToken);
    $stored = $this->refreshTokenRepo->findByHash($hash);
    if ($stored) {
        $this->refreshTokenRepo->revokeFamily($stored->familyId);
    }

    // 2. Optionally: add current access token JTI to deny list
    //    (only if immediate revocation is required)

    // 3. Client deletes access token cookie/storage
}

// POST /auth/logout-all
public function logoutAll(int $userId): void
{
    // 1. Revoke ALL refresh token families for user
    $this->refreshTokenRepo->revokeAllForUser($userId);

    // 2. Increment generation counter (invalidates all access tokens)
    $this->userRepo->incrementTokenGeneration($userId);
}
```

---

## 5. Authorization Architecture

### 5.1 Design Principle: Preserve the Bitfield

phpBB's ACL system is mature, performant (O(1) reads), and complex (125 permissions, 4 scopes, NEVER-wins resolution, founder override). The new auth service **preserves the bitfield engine entirely** and wraps it with a clean PHP interface.

The bitfield is NOT embedded in JWT. It is:
1. Pre-computed during `acl_cache()` (triggered on permission change)
2. Stored in `phpbb_users.user_permissions` column
3. Cached in application cache (Redis/file) keyed by `user_id:perm_version`
4. Decoded into memory per-request as `$acl[$forum_id] = "10010110..."`
5. Read at O(1) cost per permission check

### 5.2 Permission Resolution Flow (Per Request)

```
Request arrives with JWT
  │
  ▼
Auth middleware (priority 16):
  1. Verify JWT signature + exp
  2. Check gen < user.token_generation → reject if stale
  3. Set _api_user on request attributes
  │
  ▼
AuthZ subscriber (priority 8):
  1. Read _api_permission from route defaults
  2. If _api_public: true → pass through
  3. Load permission bitfield:
     a. Check cache: "acl:{user_id}:{perm_v}" → hit? use it
     b. Miss? Load from user_permissions column → decode → cache
     c. If user_permissions empty → rebuild via acl_cache()
  4. Check permission: $acl[$forum_id][$option_index] → '1' or '0'
  5. Founder override: if utype=3 and a_* permission → always YES
  6. Denied → 403 JSON response
  │
  ▼
Controller (priority 0):
  Fine-grained checks via AuthorizationService::isGranted()
```

### 5.3 AuthorizationService Interface (Preserved)

```php
namespace phpbb\auth;

interface AuthorizationServiceInterface
{
    /**
     * Check if user has a specific permission, optionally in a forum context.
     */
    public function isGranted(User $user, string $permission, ?int $forumId = null): bool;

    /**
     * Check if user has ANY of the listed permissions.
     */
    public function isGrantedAny(User $user, array $permissions, ?int $forumId = null): bool;

    /**
     * Get all forum IDs where user has the given permission.
     */
    public function getGrantedForums(User $user, string $permission): array;

    /**
     * Check if user has permission in at least one forum.
     */
    public function isGrantedInAnyForum(User $user, string $permission): bool;

    /**
     * Load and cache the complete permission set for a user.
     * Called once per request, subsequent isGranted() calls are O(1).
     */
    public function loadPermissions(User $user): void;
}
```

### 5.4 Permission Version and Cache Freshness

```sql
-- Add to phpbb_users:
ALTER TABLE phpbb_users ADD COLUMN perm_version INT UNSIGNED NOT NULL DEFAULT 0;
```

**Increment triggers** (in existing `acl_clear_prefetch()`):
- Forum create/modify/delete → all users
- Group permission change → all users
- User added/removed from group → affected users
- Role modification → all users
- Admin permission edit → all users

**JWT carries `pv` claim**. The AuthZ subscriber compares:
- `jwt.pv === user.perm_version` → cached bitfield is fresh, use it
- `jwt.pv < user.perm_version` → permissions changed since token issued

For non-sensitive reads (forum listing, viewing topics): allow stale `pv` — bitfield still in cache, rebuilt lazily. Max staleness = 15 minutes (access token TTL).

For sensitive writes (admin actions, permission modifications): require `pv` match or force token refresh.

### 5.5 Bitfield Size Analysis

| Scenario | Forum Count | Bitfield Size | Cache Load Cost |
|----------|-------------|---------------|-----------------|
| Small board | 5 forums | ~100 bytes | Negligible |
| Medium board | 50 forums | ~650 bytes | Negligible |
| Large board | 200 forums | ~2.5 KB | Acceptable |
| Mega board | 1000 forums | ~12 KB | Monitor |

All scenarios are well within single cache entry limits. Redis `GET` of 12 KB takes ~0.1ms.

---

## 6. Token Model

### 6.1 User Token (Standard Access)

**Purpose**: Standard API operations — reading forums, posting, user profile operations.

```json
{
  "iss": "phpbb",
  "sub": "42",
  "aud": "phpbb-api",
  "iat": 1713456000,
  "exp": 1713456900,
  "jti": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "type": "access",
  "utype": 0,
  "gen": 0,
  "pv": 1713400000,
  "flags": ["u", "f"]
}
```

**Estimated encoded size**: ~350 bytes

**What this enables without DB:**
- Identity verification (signature + exp)
- User type detection (normal vs founder)
- Quick gate checks ("is this a regular user?" → `flags` has `u` and `f`)
- Permission freshness check (`pv` vs cached version)
- Revocation detection (`gen` vs user record)

**What this does NOT enable without cache:**
- Fine-grained permission checks (e.g., "can post in forum 7?")
- Admin/moderator sub-permission checks

### 6.2 Elevated Token (Admin/Moderator Access)

**Purpose**: Admin Control Panel operations, Moderator Control Panel operations.

```json
{
  "iss": "phpbb",
  "sub": "42",
  "aud": "phpbb-admin",
  "iat": 1713456000,
  "exp": 1713456300,
  "jti": "f1e2d3c4-b5a6-4789-0123-456789abcdef",
  "type": "elevated",
  "utype": 3,
  "gen": 0,
  "pv": 1713400000,
  "flags": ["a", "m", "u", "f"],
  "scope": ["acp", "mcp"],
  "elv_jti": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d"
}
```

**Estimated encoded size**: ~420 bytes

**Additional claims:**
- `scope`: Array of granted scopes — `acp` (Admin Control Panel), `mcp` (Moderator Control Panel)
- `elv_jti`: Links to the parent access token's JTI — audit trail

**Key differences from user token:**
- `aud: phpbb-admin` — different audience, routed differently
- `type: elevated` — discriminator for middleware
- `exp`: 5 minutes (vs 15 for user token) — shorter exposure window
- `scope`: Explicit list of what the elevation grants

### 6.3 Elevation Flow

```
┌─────────┐                     ┌──────────────────┐              ┌─────────────┐
│ Client  │                     │ phpbb\auth       │              │ AuthZ Cache │
│         │                     │ AuthenticationSvc │              │             │
└────┬────┘                     └────────┬─────────┘              └──────┬──────┘
     │                                   │                               │
     │ POST /auth/elevate                │                               │
     │ Bearer: <access_token>            │                               │
     │ {password, scope: "acp"}          │                               │
     ├──────────────────────────────────►│                               │
     │                                   │                               │
     │                     ┌─────────────┤ 1. Verify access token        │
     │                     │Valid access │    (signature, exp, gen)      │
     │                     │token        │                               │
     │                     └─────────────┤                               │
     │                                   │                               │
     │                                   │ 2. Re-verify password         │
     │                                   │    (step-up authentication)   │
     │                                   │                               │
     │                                   │ 3. Check permission flags     │
     │                                   ├──────────────────────────────►│
     │                                   │    "acp" → needs a_ flag     │
     │                                   │    "mcp" → needs m_ flag     │
     │                                   │◄──── acl_get('a_') = true ───┤
     │                                   │                               │
     │                                   │ 4. Issue elevated token       │
     │                                   │    (aud=phpbb-admin,          │
     │                                   │     type=elevated,            │
     │                                   │     scope=[acp], exp=5min)   │
     │                                   │                               │
     │◄── 200 {elevated_token,           │                               │
     │     expires_in: 300}              │                               │
```

**Elevation rules:**

| Requested Scope | Required Permission | Check Method |
|----------------|--------------------|----|
| `acp` | `a_` flag is YES | `acl_get('a_')` — single global bit |
| `mcp` | `m_` flag is YES, or `m_` in any forum | `acl_get('m_')` OR `acl_getf_global('m_')` |
| `acp,mcp` | Both of the above | Both checks must pass |

**Founder special case**: If `user_type == USER_FOUNDER (3)`, the `a_` flag is always YES (founder override). Founders can always elevate to `acp`.

**Elevation is NOT persistent**: Client must re-elevate when the elevated token expires. The original access token remains valid independently.

### 6.4 Token Lifecycle Summary

```
Login ──► access_token (15 min) + refresh_token (7 days)
              │
              ├──► API requests (Bearer: access_token)
              │
              ├──► Token refresh: refresh_token ──► new access + refresh pair
              │
              ├──► Elevation: access_token + password ──► elevated_token (5 min)
              │         │
              │         └──► ACP/MCP requests (Bearer: elevated_token)
              │
              ├──► Logout: revoke refresh family, discard access token
              │
              └──► Logout-all: revoke all families + increment gen counter
```

### 6.5 Token Transport

| Context | Transport | Cookie Settings |
|---------|-----------|----------------|
| Web (SPA) | HttpOnly Secure cookie | `SameSite=Strict; Secure; HttpOnly; Path=/; Max-Age=900` |
| API client | `Authorization: Bearer <token>` header | N/A |
| Refresh token | HttpOnly Secure cookie (separate) | `SameSite=Strict; Secure; HttpOnly; Path=/auth/refresh; Max-Age=604800` |
| Elevated token | `Authorization: Bearer <token>` header or cookie | Short-lived, not persisted |

**Dual transport**: Auth middleware extracts token from `Authorization` header first, falls back to cookie. Enables both SPA and programmatic API access with the same validation logic.

---

## 7. Integration Points

### 7.1 With User Service

**Direction**: Auth → User (never reverse)

| Auth Calls | User Provides | When |
|-----------|--------------|------|
| `UserRepository::findByUsername($username)` | User entity | Login flow |
| `UserRepository::findById($userId)` | User entity | Token validation (gen check) |
| `PasswordService::verifyPassword($plain, $hash)` | bool | Login + elevation |
| `PasswordService::needsRehash($hash)` | bool | Login (transparent rehash) |
| `PasswordService::hashPassword($password)` | string | Rehash on login |
| `BanService::isUserBanned($userId)` | bool | Login flow |
| `GroupRepository::getMembershipsForUser($userId)` | GroupMembership[] | Permission resolution |

**Events Auth listens to:**

| Event | Source | Auth Response |
|-------|--------|--------------|
| `UserDeletedEvent` | User Service | `acl_clear_prefetch($userId)`, revoke all tokens |
| `PasswordChangedEvent` | User Service | Increment `token_generation`, revoke refresh tokens (except current) |
| `UserBannedEvent` | User Service | Increment `token_generation`, revoke all refresh tokens |

**User entity fields Auth needs:**

| Field | Claim | Purpose |
|-------|-------|---------|
| `$user->id` | `sub` | Primary identity |
| `$user->type` (UserType enum) | `utype` | Founder override detection |
| `$user->tokenGeneration` | `gen` | Revocation check |
| `$user->permVersion` | `pv` | Permission freshness |
| `$user->loginAttempts` | — | Rate limiting |
| `$user->passwordHash` | — | Credential verification |

### 7.2 With Hierarchy Service

**Direction**: Auth reads forum tree for permission scoping.

| Integration | Detail |
|-------------|--------|
| `getGrantedForums(User, 'f_read')` | Auth resolves which forums user can access; Hierarchy uses result to filter tree |
| Forum-specific permissions | `isGranted($user, 'f_post', $forumId)` — forum ID from Hierarchy's route param |
| Cache invalidation | Forum create/delete triggers `acl_clear_prefetch()` for all users |

**Key contract**: Hierarchy never checks permissions itself. It trusts the API layer (AuthZ subscriber at priority 8) to enforce access before controller logic runs.

### 7.3 With REST API Framework

**Subscriber chain:**

| Priority | Subscriber | Owner | Responsibility |
|----------|-----------|-------|----------------|
| 32 | `RouterListener` | Symfony | Resolves route → `_controller`, `_api_permission`, `_api_forum_param`, `_api_public` |
| 16 | `AuthenticationSubscriber` | **Auth Service** | JWT → user hydration, sets `_api_user` on request |
| 8 | `AuthorizationSubscriber` | **Auth Service** | ACL check against route `_api_permission` |
| 0 | Controllers | Service-specific | Business logic |

**AuthenticationSubscriber (priority 16):**

```php
public function onKernelRequest(RequestEvent $event): void
{
    $token = $this->extractToken($event->getRequest()); // header or cookie

    if ($token === null) {
        // Set anonymous user (for _api_public routes)
        $event->getRequest()->attributes->set('_api_user', $this->anonymousUser);
        return;
    }

    try {
        $payload = $this->tokenService->verify($token);
        $this->validateClaims($payload); // iss, aud, gen
        $user = $this->userRepository->findById((int) $payload->sub);

        if ($user === null || $payload->gen < $user->tokenGeneration) {
            throw new AuthenticationException('Token revoked');
        }

        $event->getRequest()->attributes->set('_api_user', $user);
        $event->getRequest()->attributes->set('_jwt_payload', $payload);

    } catch (\Exception $e) {
        $event->setResponse(new JsonResponse(['error' => 'Unauthorized'], 401));
        $event->stopPropagation();
    }
}
```

**AuthorizationSubscriber (priority 8):**

```php
public function onKernelRequest(RequestEvent $event): void
{
    $request = $event->getRequest();

    if ($request->attributes->get('_api_public', false)) {
        return; // Public route — no auth required
    }

    $user = $request->attributes->get('_api_user');
    if ($user === null || $user->isAnonymous()) {
        $event->setResponse(new JsonResponse(['error' => 'Authentication required'], 401));
        $event->stopPropagation();
        return;
    }

    $permission = $request->attributes->get('_api_permission');
    if ($permission === null) {
        return; // Route requires authentication only, no specific permission
    }

    $forumParam = $request->attributes->get('_api_forum_param', 'forum_id');
    $forumId = (int) $request->attributes->get($forumParam, 0);

    // Elevated endpoint check
    $requiresElevation = $request->attributes->get('_api_elevated', false);
    if ($requiresElevation) {
        $payload = $request->attributes->get('_jwt_payload');
        if (($payload->type ?? '') !== 'elevated') {
            $event->setResponse(new JsonResponse(['error' => 'Elevation required'], 403));
            $event->stopPropagation();
            return;
        }
        $requiredScope = $request->attributes->get('_api_scope', 'acp');
        if (!in_array($requiredScope, $payload->scope ?? [])) {
            $event->setResponse(new JsonResponse(['error' => 'Insufficient scope'], 403));
            $event->stopPropagation();
            return;
        }
    }

    if (!$this->authorizationService->isGranted($user, $permission, $forumId ?: null)) {
        $event->setResponse(new JsonResponse(['error' => 'Forbidden'], 403));
        $event->stopPropagation();
    }
}
```

**Route definition example:**

```yaml
# Regular forum endpoint
api_forum_topics:
    path: /api/forums/{forum_id}/topics
    defaults:
        _controller: phpbb\threads\controller\TopicController::list
        _api_permission: f_read
        _api_forum_param: forum_id

# Admin endpoint (requires elevation)
api_admin_forums:
    path: /api/admin/forums
    defaults:
        _controller: phpbb\hierarchy\controller\AdminForumController::list
        _api_permission: a_forum
        _api_elevated: true
        _api_scope: acp

# Public endpoint
api_forum_list:
    path: /api/forums
    defaults:
        _controller: phpbb\hierarchy\controller\ForumController::list
        _api_public: true
```

### 7.4 With Other Services

| Service | Integration | Direction |
|---------|-------------|-----------|
| **Threads** | `isGranted($user, 'f_post', $forumId)` before creating topic | Auth ← Threads (via AuthZ subscriber) |
| **Messaging** | `isGranted($user, 'u_sendpm')` before sending PM | Auth ← Messaging (via subscriber) |
| **Search** | `getGrantedForums($user, 'f_search')` to filter results | Auth → Search |
| **Notifications** | Reads `_api_user` from request attributes | Auth → Notifications (via request) |

---

## 8. Security Analysis

### 8.1 Attack Vectors and Mitigations

#### A1: Token Theft (XSS)

| Vector | Mitigation |
|--------|-----------|
| XSS reads `localStorage` token | Don't use localStorage — use HttpOnly cookies |
| XSS reads cookie | HttpOnly flag prevents JS access |
| XSS sends requests with auto-included cookie | SameSite=Strict prevents cross-origin cookie inclusion |

**Residual risk**: If XSS exists on the same origin, it can make API calls that include the HttpOnly cookie automatically. Mitigation: strong CSP headers + input sanitization.

#### A2: CSRF

| Vector | Mitigation |
|--------|-----------|
| Cross-origin form POST | `SameSite=Strict` on cookies blocks cross-origin cookie sending |
| Subdomain attack | Use `__Host-` cookie prefix (requires Secure, no Domain, Path=/) |

**Additional defense**: Double-submit cookie pattern for state-changing operations:
- JWT contains `csrf` claim (random value)
- Same value set in non-HttpOnly cookie
- Client sends value in `X-CSRF-Token` header
- Server validates: `jwt.csrf === header.X-CSRF-Token`

#### A3: Token Replay

| Vector | Mitigation |
|--------|-----------|
| Stolen access token reused | 15-minute TTL limits window; generation counter for forced revocation |
| Stolen refresh token reused | One-time use + family rotation: reuse triggers family revocation |
| Man-in-the-middle | HTTPS mandatory (Secure cookie flag); HSTS header |

#### A4: Algorithm Confusion

| Vector | Mitigation |
|--------|-----------|
| Attacker changes JWT `alg` header to `none` | `firebase/php-jwt` Key class binds algorithm — rejects mismatched alg |
| Attacker uses RS256 public key as HMAC secret | Key class enforces algorithm — only HS256 accepted for HMAC key |

**Status**: Mitigated by library design — no application code needed.

#### A5: Brute Force / Credential Stuffing

| Vector | Mitigation |
|--------|-----------|
| Rapid login attempts | IP-based rate limiting (`phpbb_login_attempts` table) |
| Distributed attack | Per-user attempt tracking (`User::$loginAttempts`) |
| Extreme volume | CAPTCHA challenge after threshold (existing `captcha_factory`) |

**Thresholds** (from legacy config):
- `ip_login_limit_max`: Max attempts per IP within time window
- `ip_login_limit_time`: Time window (seconds)
- `max_login_attempts`: Per-user maximum before CAPTCHA required

#### A6: Token Forgery

| Vector | Mitigation |
|--------|-----------|
| Fabricate JWT with guessed secret | HS256 with 256-bit key (32 random bytes) — computationally infeasible |
| Key leakage | Key derived from config secret + purpose salt; not stored in code |
| Kid manipulation | Key ID validated against known key map; unknown kid → rejection |

**Key derivation:**
```php
$signingKey = hash_hmac('sha256', 'jwt-access-v1', $config['auth_secret']);
```

`auth_secret` is a 64-char hex string generated during installation. Must be stored securely in `config.php` (not in DB, not in version control).

#### A7: Privilege Escalation

| Vector | Mitigation |
|--------|-----------|
| Modify JWT claims (e.g., `utype=3`) | Signature verification — modified payload = invalid signature |
| Obtain elevated token without admin perms | Elevation requires: valid token + password + `a_`/`m_` permission check |
| Use user token on admin endpoints | `_api_elevated: true` route default → middleware rejects non-elevated tokens |

#### A8: Stale Permissions

| Vector | Mitigation |
|--------|-----------|
| User loses permissions but JWT still has old flags | `perm_version` check — stale version triggers re-check from fresh bitfield |
| Admin demotes user, user keeps elevated token | Elevated tokens are 5 minutes — natural expiry. For immediate: generation counter |

### 8.2 Security Properties Summary

| Property | Achieved? | Mechanism |
|----------|-----------|-----------|
| **Authentication** | Yes | JWT signature verification (HS256) |
| **Authorization** | Yes | Bitfield-based ACL with O(1) checks |
| **Confidentiality** | Yes | HTTPS + HttpOnly cookies |
| **Integrity** | Yes | JWT signature prevents tampering |
| **Non-repudiation** | Partial | `jti` tracks token identity; audit trail via `elv_jti` |
| **Revocation** | Yes | 3-layer: natural expiry + generation + deny list |
| **Freshness** | Yes | `perm_version` detects stale permissions |
| **Least privilege** | Yes | User token for regular ops; elevated token required for admin/mod |
| **Defense in depth** | Yes | Multiple independent checks at layers 16, 8, controller |

---

## 9. Recommendations

### R1: Service Structure (Priority: Critical)

Design `phpbb\auth` with three sub-components:

```
phpbb\auth\
├── AuthenticationService        ← Login, logout, credential verification
│   implements AuthenticationServiceInterface
├── TokenService                 ← JWT issuance, validation, refresh
│   implements TokenServiceInterface
├── AuthorizationService         ← ACL resolution, isGranted()
│   implements AuthorizationServiceInterface
├── Subscriber/
│   ├── AuthenticationSubscriber ← kernel.request priority 16
│   └── AuthorizationSubscriber  ← kernel.request priority 8
├── Repository/
│   ├── RefreshTokenRepository   ← phpbb_auth_refresh_tokens CRUD
│   └── AclCacheRepository       ← user_permissions SELECT/UPDATE
└── Model/
    ├── TokenPair                ← access + refresh token DTO
    ├── AuthResult               ← login result DTO
    └── JwtClaims                ← claim constants/builder
```

### R2: JWT Algorithm and Key Management (Priority: Critical)

- **HS256** for Phase 1 (single application deployment)
- 256-bit key derived from `config.php` secret via HMAC
- Support key rotation via `kid` header and key map (2 active keys during rotation)
- Add `auth_secret` to `config.php` if not present (generate 64 hex chars on install)

### R3: Token TTLs (Priority: High)

| Token | TTL | Configurable? |
|-------|-----|--------------|
| Access token | 15 minutes | Yes — `auth_access_ttl` in config |
| Elevated token | 5 minutes | Yes — `auth_elevated_ttl` in config |
| Refresh token | 7 days | Yes — `auth_refresh_ttl` in config |
| JWT leeway | 60 seconds | Yes — `auth_jwt_leeway` in config |

### R4: Schema Changes (Priority: High)

```sql
-- New table
CREATE TABLE phpbb_auth_refresh_tokens (
    token_id    VARCHAR(64) NOT NULL,
    user_id     INT UNSIGNED NOT NULL,
    family_id   VARCHAR(36) NOT NULL,
    issued_at   INT UNSIGNED NOT NULL,
    expires_at  INT UNSIGNED NOT NULL,
    revoked     TINYINT(1) NOT NULL DEFAULT 0,
    replaced_by VARCHAR(64) NULL,
    user_agent  VARCHAR(255) NULL,
    ip_address  VARCHAR(45) NULL,
    PRIMARY KEY (token_id),
    INDEX idx_user_id (user_id),
    INDEX idx_family_id (family_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- New columns on phpbb_users
ALTER TABLE phpbb_users
    ADD COLUMN token_generation INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN perm_version INT UNSIGNED NOT NULL DEFAULT 0;
```

### R5: CSRF Strategy (Priority: High)

For web clients using cookie-based JWT:
- Embed `csrf` random value in JWT claims
- Set matching value in non-HttpOnly cookie (`phpbb_csrf`)
- Require `X-CSRF-Token` header on state-changing requests (POST/PUT/DELETE)
- Validate: `jwt.csrf === header['X-CSRF-Token']`

For API clients using Bearer header: CSRF not needed (token not auto-included by browser).

### R6: Auth Provider Extensibility (Priority: Low — Phase 2)

Phase 1: `db` provider only (password in `phpbb_users`).
Phase 2: `AuthProviderInterface` supporting:
- `db` (default)
- `ldap` (enterprise)
- `oauth2` (social login)

The provider returns a `User` entity on success; the rest of the flow (token issuance, ACL) is provider-agnostic.

### R7: Monitoring and Observability (Priority: Medium)

Log these events for security monitoring:
- Login success/failure (user, IP, user_agent)
- Token refresh (user, family_id)
- Elevation attempt (user, scope, success/failure)
- Generation counter increment (user, reason)
- Family revocation (user, family_id, reason: theft-detected / logout / admin)
- JTI deny list addition (user, jti, reason)

---

## 10. Open Questions / Future Work

### Resolved in This Report

| Question | Resolution |
|----------|-----------|
| JWT or DB tokens? | JWT (user decision) |
| What goes in JWT? | Identity + flags + freshness. NOT permissions. |
| How to handle ACL complexity? | Preserve bitfield engine, server-side cache |
| Group token concept? | Reinterpreted as "elevated token", permission-gated |
| Revocation without state? | 3-layer: natural expiry + gen counter + optional deny list |

### Open for HLD Phase

| Question | Impact | Suggested Direction |
|----------|--------|---------------------|
| **Exactly which cache backend?** | Performance | Redis for API layer; file cache as fallback. Bitfield cache keyed by `user_id:perm_version`. |
| **Remember-me flow** | UX | Long-lived refresh token (30 days) with separate cookie. Similar to current `phpbb_sessions_keys` but using refresh token families. |
| **Concurrent elevation** | UX | Allow multiple elevated tokens simultaneously (each is independent JWT with own scope). Client manages which to send per request. |
| **Admin API token management** | Feature | Allow admins to manage user refresh token families (view, revoke) via ACP API. |
| **Moderator global vs per-forum elevation** | AuthZ | Global `m_` elevation covers all forums. Per-forum `m_` checked via bitfield even with user token. Elevation only needed for MCP panel access, not per-forum moderation actions. |
| **Legacy session coexistence during migration** | Migration | Keep `phpbb_sessions` table operational for legacy web frontend. API uses JWT. Eventually sunset sessions. |
| **Token in response body vs Set-Cookie** | API Design | Return both: `Set-Cookie` for web, JSON body for API clients. Client chooses transport. |
| **Anonymous user token** | Public access | No token = anonymous. Auth middleware sets `_api_user` to anonymous user entity. Routes with `_api_public: true` allow this. |
| **Refresh token garbage collection** | Operations | Cron job to `DELETE FROM phpbb_auth_refresh_tokens WHERE expires_at < NOW() AND revoked = 1`. |
| **Bitfield format evolution** | Long-term | Current base-36 format works. If permission options are added (extensions), bitfield auto-detects length mismatch and rebuilds. JWT `pv` ensures clients get fresh data. |

### Future Work (Beyond Initial Release)

1. **OAuth2 / OpenID Connect provider** — phpBB as an identity provider for external applications
2. **Scoped API tokens** — non-JWT tokens with limited permission sets (e.g., read-only, specific endpoints)
3. **WebAuthn / Passkey support** — passwordless authentication as an auth provider
4. **Session binding** — bind JWT to TLS session or device fingerprint for theft resistance
5. **Adaptive authentication** — risk-based step-up auth (unusual IP, new device triggers 2FA)

---

## 11. Appendices

### A. Complete Source List

| Source | Location | Lines Analyzed |
|--------|----------|---------------|
| Auth providers | `src/phpbb/forums/auth/provider/*.php` | ~600 |
| Auth system (ACL) | `src/phpbb/forums/auth/auth.php` | 1139 |
| Session management | `src/phpbb/forums/session.php` | ~1600 |
| Constants | `src/phpbb/common/constants.php` | 55-81 |
| Database schema | `phpbb_dump.sql` | All ACL/group/session tables |
| firebase/php-jwt | `vendor/firebase/php-jwt/src/JWT.php` | ~500 |
| firebase/php-jwt Key | `vendor/firebase/php-jwt/src/Key.php` | ~80 |
| Prior Auth HLD | `.maister/tasks/research/2026-04-18-auth-service/outputs/` | 2 documents |
| Prior Users HLD | `.maister/tasks/research/2026-04-19-users-service/outputs/` | 2 documents |
| Prior REST API HLD | `.maister/tasks/research/2026-04-16-phpbb-rest-api/outputs/` | 2 documents |
| Cross-cutting assessment | Various | §6, §7 |

### B. Permission Count Summary

| Scope | Count | Global | Local | In JWT? |
|-------|-------|--------|-------|---------|
| `a_*` (admin) | 42 | 42 | 0 | Flags only (`a` in flags array) |
| `m_*` (moderator) | 15 | 15 | 15 (dual-scope) | Flags only (`m` in flags array) |
| `u_*` (user) | 35 | 35 | 0 | Flags only (`u` in flags array) |
| `f_*` (forum) | 33 | 0 | 33 | Never — server-side only |
| **Total** | **125** | **92** | **48** | **Flags = 3 chars** |

### C. JWT Size Budget

| Component | Bytes (approx) |
|-----------|---------------|
| Header (alg, typ, kid) | 50 |
| Payload (all claims) | 180-250 |
| Signature (HS256) | 43 |
| Base64 encoding overhead | 33% |
| **Total encoded** | **350-420** |

Cookie budget: 4096 bytes. JWT uses ~10% of available space. No size concerns.

### D. Subscriber Priority Map

```
Priority 32: RouterListener (Symfony)
         │   Resolves route defaults: _controller, _api_permission, _api_forum_param, _api_public, _api_elevated, _api_scope
         │
Priority 16: AuthenticationSubscriber (phpbb\auth)
         │   Extracts JWT → validates signature/exp/gen → loads User → sets _api_user
         │   Anonymous fallback for _api_public routes
         │
Priority 10: JsonExceptionSubscriber (REST API framework)
         │   Catches exceptions → JSON error responses
         │
Priority 8:  AuthorizationSubscriber (phpbb\auth)
         │   Reads _api_permission → checks ACL bitfield → 403 if denied
         │   Checks _api_elevated + _api_scope for admin endpoints
         │
Priority 0:  Controller
             Fine-grained isGranted() calls for business logic
```

### E. Key Decisions Register

| ID | Decision | Rationale | Supersedes |
|----|----------|-----------|-----------|
| AD-001 | Stateless JWT access tokens | User requirement — enables stateless API auth | REST API ADR-005 (DB tokens) |
| AD-002 | Server-side refresh tokens with family rotation | Revocation + theft detection; only server state needed | N/A (new) |
| AD-003 | Elevated tokens replace session_admin flag | JWT-native privilege escalation; shorter TTL, scoped | Legacy `session_admin` mechanism |
| AD-004 | Bitfield cache preserved, not embedded in JWT | O(1) performance retained; JWT stays small | N/A (preserves legacy ACL ADR-002) |
| AD-005 | Generation counter for forced revocation | Minimal state, piggybacks on user record load | N/A (new) |
| AD-006 | HS256 algorithm | Single-app, simple key management, fast | N/A (new, but RS256 was considered) |
| AD-007 | Permission version for cache freshness | Enables eventual consistency; stale perms corrected within 15min | Legacy `user_permissions = ''` clearing |
| AD-008 | CSRF via double-submit cookie | Works with JWT-in-cookie; compatible with SPA | Legacy `form_salt` / `check_form_key()` |
| AD-009 | "Group token" reinterpreted as "elevated token" | Permissions are bitfield-based, not group-based in phpBB | Original "group token" concept |
| AD-010 | Auth subscriber at priority 16, AuthZ at priority 8 | Matches established convention, AuthN before AuthZ | Notifications HLD (wrong priority 8 for auth) |
