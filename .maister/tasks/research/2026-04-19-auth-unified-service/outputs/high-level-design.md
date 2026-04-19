# High-Level Design: Unified Auth Service (`phpbb\auth`)

## Design Overview

**Business context**: The phpBB REST API lacks a single owner for authentication and authorization — three prior research documents deferred AuthN to each other, creating a circular gap. Every API endpoint needs identity verification and permission checking, making this the most critical infrastructure service in the platform.

**Chosen approach**: A **hybrid stateless/cached architecture** where JWT access tokens carry identity and global permissions statelessly, while a server-side **O(1) bitfield cache** resolves forum-specific permissions. Three service interfaces (**AuthenticationServiceInterface**, **AuthorizationServiceInterface**, **TokenServiceInterface**) provide clean separation of concerns under unified `phpbb\auth` ownership. Token elevation via a **separate short-lived JWT** replaces the legacy `session_admin` flag. **HS256** symmetric signing with derived keys keeps key management simple for the monolith.

**Key decisions:**
- **Monolithic JWT access token** (15-min TTL) + opaque SHA-256-hashed refresh token with family-based rotation for theft detection
- **Full global permission bitfield embedded in JWT** (~20 bytes for 92 permissions) — zero cache hits for global permission checks
- **Three-layer revocation**: natural expiry → generation counter → optional JTI deny list
- **Separate elevated JWT** (5-min TTL, `aud: phpbb-admin`) for admin/moderator operations, issued after password re-verification
- **HS256 with derived key** from master secret in `config.php`; `kid` header for zero-downtime rotation
- **Three-interface facade** — consumers inject only the interface they need (ISP)

---

## Architecture

### System Context (C4 Level 1)

```
                          ┌──────────────────────────────────────────────────────────────┐
                          │                       phpBB Platform                         │
                          │                                                              │
┌──────────┐   HTTP/S     │  ┌──────────────┐    ┌──────────────────────────────────┐    │
│          │─────────────►│  │   REST API    │───►│         phpbb\auth               │    │
│  Client  │◄─────────────│  │  (Symfony)    │◄───│  Unified Auth Service            │    │
│ (SPA/API)│   JSON+JWT   │  └──────┬───────┘    └────────────┬─────────────────────┘    │
└──────────┘              │         │                          │                          │
                          │         ▼                          ▼                          │
                          │  ┌──────────────┐    ┌──────────────────┐  ┌──────────────┐  │
                          │  │  Domain       │    │    Database      │  │  Cache        │  │
                          │  │  Services     │    │  (MySQL/Pg)     │  │  (Redis/File) │  │
                          │  │ (Users,Forums │    │                  │  │               │  │
                          │  │  Threads...)  │    └──────────────────┘  └──────────────┘  │
                          │  └──────────────┘                                             │
                          └──────────────────────────────────────────────────────────────┘
```

**Actors:**
- **Client (SPA/API)**: Browser SPA using HttpOnly cookies, or programmatic API client using Bearer tokens
- **REST API (Symfony)**: HTTP kernel with subscriber pipeline; routes requests through AuthN → AuthZ → Controller
- **phpbb\auth**: Unified service owning authentication (JWT lifecycle) and authorization (ACL resolution)
- **Domain Services**: User, Hierarchy, Threads, etc. — consume `AuthorizationServiceInterface` for permission checks
- **Database**: Stores refresh tokens, user records (with `token_generation`, `perm_version`), ACL tables
- **Cache**: Stores pre-computed permission bitfields keyed by `user_id:perm_version`

### Container Overview (C4 Level 2)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              phpbb\auth                                     │
│                                                                             │
│  ┌─────────────────────────────────────────────────────────────┐            │
│  │                  HTTP Middleware Layer                        │            │
│  │                                                               │            │
│  │  ┌──────────────────────────┐  ┌───────────────────────────┐ │            │
│  │  │ AuthenticationSubscriber │  │ AuthorizationSubscriber    │ │            │
│  │  │ (priority 16)            │  │ (priority 8)               │ │            │
│  │  │                          │  │                             │ │            │
│  │  │ JWT extraction           │  │ Route-level perm check     │ │            │
│  │  │ Signature validation     │  │ Elevation requirement check│ │            │
│  │  │ Gen counter check        │  │ Bitfield-based ACL         │ │            │
│  │  │ User hydration           │  │ Founder override           │ │            │
│  │  └──────────┬───────────────┘  └──────────┬────────────────┘ │            │
│  └─────────────┼─────────────────────────────┼──────────────────┘            │
│                │ uses                         │ uses                          │
│                ▼                              ▼                               │
│  ┌──────────────────────┐  ┌──────────────────────┐  ┌────────────────────┐  │
│  │ AuthenticationService│  │ AuthorizationService  │  │ TokenService       │  │
│  │                      │  │                       │  │                    │  │
│  │ login()              │  │ isGranted()           │  │ issueAccessToken() │  │
│  │ logout()             │  │ isGrantedAny()        │  │ issueElevatedToken()│ │
│  │ logoutAll()          │  │ getGrantedForums()    │  │ verify()           │  │
│  │ elevate()            │  │ loadPermissions()     │  │ refresh()          │  │
│  │ refresh()            │  │                       │  │                    │  │
│  └──────────┬───────────┘  └──────────┬───────────┘  └────────┬───────────┘  │
│             │                         │                        │              │
│             ▼                         ▼                        ▼              │
│  ┌────────────────────────────────────────────────────────────────────────┐   │
│  │                        Infrastructure Layer                            │   │
│  │                                                                        │   │
│  │  ┌─────────────────────┐  ┌──────────────────┐  ┌──────────────────┐  │   │
│  │  │ RefreshTokenRepo    │  │ AclCacheRepo     │  │ firebase/php-jwt │  │   │
│  │  │ (DB: refresh tokens)│  │ (Cache: bitfield)│  │ (JWT encode/     │  │   │
│  │  │                     │  │                   │  │  decode/verify)  │  │   │
│  │  └─────────────────────┘  └──────────────────┘  └──────────────────┘  │   │
│  └────────────────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────────────┘

External dependencies:
  ── phpbb\user (UserRepository, PasswordService, BanService)
  ── phpbb\hierarchy (forum tree context for permission scoping)
  ── Cache backend (Redis / file cache)
  ── Database (MySQL/PostgreSQL)
```

**Responsibilities:**

| Container | Responsibility |
|-----------|---------------|
| `AuthenticationSubscriber` | Extract JWT from request (header/cookie), validate signature + expiry + generation, hydrate User entity, set `_api_user` attribute |
| `AuthorizationSubscriber` | Read route `_api_permission`, check elevation requirements, delegate to `AuthorizationService::isGranted()`, reject 403 |
| `AuthenticationService` | Orchestrate login/logout/elevation/refresh flows, coordinate with User service for credential verification |
| `AuthorizationService` | Load permission bitfield from cache, decode into memory, resolve `isGranted()` at O(1), handle founder override |
| `TokenService` | JWT encode/decode via firebase/php-jwt, key derivation, access/elevated token issuance, refresh token rotation |
| `RefreshTokenRepository` | CRUD for `phpbb_auth_refresh_tokens`, family-based operations (rotate, revoke family, revoke all) |
| `AclCacheRepository` | Read/write permission bitfields in cache, keyed by `user_id:perm_version` |

---

## JWT Token Specifications

### Access Token (User Token)

**Purpose**: Standard API operations — reading forums, posting, profile operations, messaging.

```json
{
  "iss": "phpbb",
  "sub": 42,
  "aud": "phpbb-api",
  "iat": 1713564000,
  "exp": 1713564900,
  "jti": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "gen": 3,
  "pv": 17,
  "utype": 0,
  "flags": "AQAAAQAAAAAAAAAAAAAAAA==",
  "kid": "access-v1"
}
```

| Claim | Type | Description | Size |
|-------|------|-------------|------|
| `iss` | string | Issuer — always `"phpbb"`, validated on decode | 5 B |
| `sub` | int | User ID — primary identity claim | 1-10 B |
| `aud` | string | Audience — `"phpbb-api"` for regular, `"phpbb-admin"` for elevated | 9-12 B |
| `iat` | int | Issued-at Unix timestamp | 10 B |
| `exp` | int | Expiration — `iat + 900` (15 min). Auto-validated by firebase/php-jwt | 10 B |
| `jti` | string | UUID v4 — unique token ID for optional deny list and audit trail | 36 B |
| `gen` | int | Token generation counter — matches `phpbb_users.token_generation` at issuance. If `jwt.gen < user.token_generation` → reject | 1-4 B |
| `pv` | int | Permission version — matches `phpbb_users.perm_version` at issuance. Stale `pv` signals permission change | 1-10 B |
| `utype` | int | User type enum: `0`=NORMAL, `1`=INACTIVE, `2`=IGNORE, `3`=FOUNDER. Enables founder override detection without DB | 1 B |
| `flags` | string | Base64-encoded global permission bitfield (~92 bits = 12 bytes raw → 16 bytes base64). Covers all `a_*`, `m_*`, `u_*` permissions | ~20 B |
| `kid` | string | Key identifier — in JWT header, not payload. Maps to derived signing key. Enables zero-downtime rotation | 10 B |

**Estimated encoded size**: ~350–400 bytes. Cookie budget is 4096 bytes — uses ~10%.

**`flags` Bitfield Layout:**

```
Bits  0-41:  a_* permissions (42 admin permissions)
Bits 42-56:  m_* permissions (15 moderator permissions, global scope)
Bits 57-91:  u_* permissions (35 user permissions)
─────────────────────────────────────────────────────
Total: 92 bits → 12 bytes raw → 16 bytes base64url
```

The bitfield is pre-computed during permission cache build. Each bit position maps to a permission option ID via a fixed index (stored in `phpbb_acl_options.auth_option_id`). Reading a permission = check bit at known offset → O(1).

### Elevated Token (Admin/Moderator Token)

**Purpose**: Admin Control Panel and Moderator Control Panel operations. Issued after password re-verification.

```json
{
  "iss": "phpbb",
  "sub": 42,
  "aud": "phpbb-admin",
  "iat": 1713564000,
  "exp": 1713564300,
  "jti": "f1e2d3c4-b5a6-4789-0123-456789abcdef",
  "gen": 3,
  "pv": 17,
  "utype": 3,
  "flags": "//////////8AAAAAAAAAAAAA==",
  "scope": ["acp", "mcp"],
  "elv_jti": "a1b2c3d4-e5f6-4a7b-8c9d-0e1f2a3b4c5d",
  "kid": "elevated-v1"
}
```

| Claim | Difference from Access Token |
|-------|------------------------------|
| `aud` | `"phpbb-admin"` — different audience, routed to admin validation path |
| `exp` | `iat + 300` (5 min) — shorter exposure window |
| `scope` | `string[]` — granted elevation scopes: `"acp"`, `"mcp"`, or both |
| `elv_jti` | `string` — JTI of the parent access token. Audit trail linking elevated to source token |
| `kid` | `"elevated-v1"` — different derived key than access tokens |

**Key derivation separation**: Elevated tokens use a different derived key:
```
access_key    = hash_hmac('sha256', 'jwt-access-v1',   $masterSecret)
elevated_key  = hash_hmac('sha256', 'jwt-elevated-v1', $masterSecret)
```

This ensures a key compromise in one token type doesn't affect the other.

### Refresh Token (Opaque)

**Format**: 64-character hex string (32 bytes of `random_bytes()`, hex-encoded). NOT a JWT.

**Storage**: SHA-256 hash stored in DB. Raw token never stored server-side.

```
Raw token:     a3f8b2d1e4c5...64 hex chars...9f0e1d2c3b4a
Stored hash:   SHA-256(raw) = 7e2a...64 hex chars...f1b9
```

**Database schema** (`phpbb_auth_refresh_tokens`):

```sql
CREATE TABLE phpbb_auth_refresh_tokens (
    token_id     VARCHAR(64)  NOT NULL,       -- SHA-256 of raw token
    user_id      INT UNSIGNED NOT NULL,       -- Owner user
    family_id    VARCHAR(36)  NOT NULL,       -- UUID v4, groups rotation chain
    issued_at    INT UNSIGNED NOT NULL,       -- Unix timestamp
    expires_at   INT UNSIGNED NOT NULL,       -- Unix timestamp (iat + 7 days default)
    revoked      TINYINT(1)   NOT NULL DEFAULT 0,
    replaced_by  VARCHAR(64)  NULL,           -- Points to successor token hash
    user_agent   VARCHAR(255) NULL,           -- Device tracking
    ip_address   VARCHAR(45)  NULL,           -- IPv4/IPv6

    PRIMARY KEY (token_id),
    INDEX idx_user_id (user_id),
    INDEX idx_family_id (family_id),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Family-based rotation**: Each login creates a new `family_id`. Each refresh rotates within the family. Reuse of a revoked token → entire family revoked (theft detection).

---

## Authentication Flows

### 1. Login Flow

```
Client                    AuthenticationService         UserService / PasswordService       TokenService          DB
  │                              │                              │                              │                  │
  │ POST /auth/login             │                              │                              │                  │
  │ {username, password}         │                              │                              │                  │
  ├─────────────────────────────►│                              │                              │                  │
  │                              │                              │                              │                  │
  │                              │ 1. Rate limit check          │                              │                  │
  │                              │    (IP + user throttle)      │                              │                  │
  │                              │                              │                              │                  │
  │                              │ 2. findByUsername()          │                              │                  │
  │                              ├─────────────────────────────►│                              │                  │
  │                              │◄── User entity ──────────────┤                              │                  │
  │                              │                              │                              │                  │
  │                              │ 3. isBanned()?               │                              │                  │
  │                              ├─────────────────────────────►│                              │                  │
  │                              │◄── bool ─────────────────────┤                              │                  │
  │                              │                              │                              │                  │
  │                              │ 4. verifyPassword()          │                              │                  │
  │                              ├─────────────────────────────►│                              │                  │
  │                              │◄── bool ─────────────────────┤                              │                  │
  │                              │                              │                              │                  │
  │                              │ 5. needsRehash() → rehash    │                              │                  │
  │                              ├─────────────────────────────►│                              │                  │
  │                              │                              │                              │                  │
  │                              │ 6. Reset login attempts      │                              │                  │
  │                              │ 7. Build global bitfield     │                              │                  │
  │                              │                              │                              │                  │
  │                              │ 8. Issue access JWT ─────────────────────────────────────────►│                  │
  │                              │◄── signed JWT ──────────────────────────────────────────────┤                  │
  │                              │                              │                              │                  │
  │                              │ 9. Generate refresh token ──────────────────────────────────────────────────────►│
  │                              │    (new family_id, store hash)                              │                  │
  │                              │◄────────────────────────────────────────────────────────────────────────────────┤
  │                              │                              │                              │                  │
  │◄── 200 {access_token,       │                              │                              │                  │
  │    refresh_token,            │                              │                              │                  │
  │    token_type: "Bearer",    │                              │                              │                  │
  │    expires_in: 900}          │                              │                              │                  │
```

**Error responses:**

| Condition | HTTP Status | Side Effect |
|-----------|-------------|-------------|
| Empty username/password | 400 | None |
| User not found | 401 | Increment IP login attempts |
| User banned | 403 | None |
| User inactive (`USER_INACTIVE`) | 403 | None |
| Wrong password | 401 | Increment user + IP attempts |
| Rate limit exceeded | 429 | None |

### 2. Token Refresh Flow

```
Client                    TokenService                    DB
  │                              │                         │
  │ POST /auth/refresh           │                         │
  │ {refresh_token}              │                         │
  ├─────────────────────────────►│                         │
  │                              │                         │
  │                              │ hash(refresh_token)     │
  │                              │ SELECT WHERE token_id   │
  │                              ├────────────────────────►│
  │                              │◄── stored record ───────┤
  │                              │                         │
  │                              │ Validate:               │
  │                              │  - exists?              │
  │                              │  - revoked = 0?         │
  │                              │  - expires_at > now?    │
  │                              │                         │
  │                              │ THEFT CHECK:            │
  │                              │ If revoked = 1:         │
  │                              │   → Revoke entire       │
  │                              │     family (theft!)     │
  │                              │   → Return 401          │
  │                              │                         │
  │                              │ Rotate:                 │
  │                              │  - Mark old as revoked  │
  │                              │  - Set replaced_by      │
  │                              │  - Generate new refresh │
  │                              │  - INSERT new token     │
  │                              │    (same family_id)     │
  │                              ├────────────────────────►│
  │                              │                         │
  │                              │ Reload user for fresh   │
  │                              │ gen, pv, flags          │
  │                              │ Issue new access JWT    │
  │                              │                         │
  │◄── 200 {access_token,       │                         │
  │    refresh_token,            │                         │
  │    expires_in: 900}          │                         │
```

**Key invariant**: Each refresh token is used exactly once. The new refresh token inherits the same `family_id`. Reuse of a consumed token indicates theft → the entire family is revoked, forcing re-authentication.

### 3. Elevation Flow

```
Client                    AuthenticationService         PasswordService        TokenService
  │                              │                          │                        │
  │ POST /auth/elevate           │                          │                        │
  │ Bearer: <access_token>       │                          │                        │
  │ {password, scope: "acp"}     │                          │                        │
  ├─────────────────────────────►│                          │                        │
  │                              │                          │                        │
  │                              │ 1. Verify access token   │                        │
  │                              │    (signature, exp, gen) │                        │
  │                              │                          │                        │
  │                              │ 2. Re-verify password    │                        │
  │                              ├─────────────────────────►│                        │
  │                              │◄── bool ─────────────────┤                        │
  │                              │                          │                        │
  │                              │ 3. Check permission:     │                        │
  │                              │    scope "acp" → a_ flag │                        │
  │                              │    scope "mcp" → m_ flag │                        │
  │                              │    (from JWT bitfield    │                        │
  │                              │     or fresh ACL check)  │                        │
  │                              │                          │                        │
  │                              │ 4. Issue elevated JWT ───────────────────────────►│
  │                              │    aud=phpbb-admin       │                        │
  │                              │    scope=[acp]           │                        │
  │                              │    exp=5min              │                        │
  │                              │    elv_jti=access.jti    │                        │
  │                              │◄── elevated JWT ─────────────────────────────────┤
  │                              │                          │                        │
  │◄── 200 {elevated_token,     │                          │                        │
  │    expires_in: 300}          │                          │                        │
```

**Elevation rules:**

| Requested Scope | Required Permission | Verification |
|----------------|--------------------|----|
| `acp` | `a_` bit is set in global bitfield | Check bit 0 (or: founder override if `utype=3`) |
| `mcp` | `m_` bit is set globally, or `m_` in any forum | Global `m_` bit check OR `acl_getf_global('m_')` |
| `acp,mcp` | Both conditions | Both checks pass |

**Founder override**: If `utype == 3` (FOUNDER), the `a_` permission is always YES regardless of bitfield. Founders can always elevate to `acp`.

### 4. Logout Flow

```
Client                    AuthenticationService          DB / Cache
  │                              │                           │
  │ POST /auth/logout            │                           │
  │ Bearer: <access_token>       │                           │
  │ {refresh_token}              │                           │
  ├─────────────────────────────►│                           │
  │                              │                           │
  │                              │ 1. Hash refresh token     │
  │                              │ 2. Find by hash           │
  │                              │ 3. Revoke entire family   │
  │                              ├──────────────────────────►│
  │                              │                           │
  │                              │ 4. Optional: add current  │
  │                              │    access JTI to deny     │
  │                              │    list (cache, TTL=      │
  │                              │    remaining token life)  │
  │                              ├──────────────────────────►│
  │                              │                           │
  │◄── 204 No Content           │                           │
  │    + Clear-Cookie headers    │                           │
```

### 5. Logout-All Flow

```
Client                    AuthenticationService          DB
  │                              │                        │
  │ POST /auth/logout-all        │                        │
  │ Bearer: <access_token>       │                        │
  ├─────────────────────────────►│                        │
  │                              │                        │
  │                              │ 1. Revoke ALL refresh  │
  │                              │    families for user   │
  │                              ├───────────────────────►│
  │                              │                        │
  │                              │ 2. Increment           │
  │                              │    token_generation    │
  │                              │    (invalidates ALL    │
  │                              │     access JWTs)       │
  │                              ├───────────────────────►│
  │                              │                        │
  │◄── 204 No Content           │                        │
```

---

## Authorization Architecture

### Global Permission Check (From JWT — No Cache Hit)

For the 92 global permissions (`a_*`, `m_*`, `u_*`), the check is fully stateless:

```
isGranted($user, 'a_forum', forumId: null)
  │
  ├── 1. Get JWT payload from request attributes
  │
  ├── 2. Decode `flags` claim (base64url → 12-byte bitfield)
  │
  ├── 3. Look up bit index for 'a_forum' (from permission option index map)
  │      e.g. a_forum → bit position 5
  │
  ├── 4. Read bit: ($flags[$byteIndex] >> $bitOffset) & 1
  │      → 1 = granted, 0 = denied
  │
  ├── 5. Founder override: if utype == 3 AND permission starts with 'a_'
  │      → always granted (regardless of bitfield)
  │
  └── 6. Return bool
```

**Cost**: O(1). No cache read. No DB query. Pure computation from JWT payload.

### Forum Permission Check (From Server-Side Bitfield Cache)

For forum-scoped permissions (`f_*` + local `m_*`):

```
isGranted($user, 'f_post', forumId: 7)
  │
  ├── 1. Is bitfield loaded in memory for this request?
  │      YES → skip to step 4
  │      NO  → continue
  │
  ├── 2. Check cache: key "acl:{user_id}:{perm_version}"
  │      HIT  → decode bitfield into memory, go to step 4
  │      MISS → continue
  │
  ├── 3. Load from DB (user_permissions column) → decode → cache it
  │      If user_permissions empty → rebuild via acl_cache()
  │
  ├── 4. Read permission from in-memory decoded bitfield:
  │      $this->acl[$forumId]['f_post'] → 1 or 0
  │
  ├── 5. Dual-scope OR for m_* permissions:
  │      isGranted('m_edit', forum: 7) =
  │        global_m_edit (from JWT flags) OR local_m_edit (from bitfield[7])
  │
  └── 6. Return bool
```

**Cost**: O(1) per check after first load. First check incurs one cache read (~0.1ms Redis). All subsequent checks in the same request are in-memory O(1).

### Permission Freshness: `perm_version` Check Flow

```
Request arrives with JWT (pv=17)
  │
  ├── Load user record (for gen check — already needed)
  │   user.perm_version = 19  ← permissions changed since token issued!
  │
  ├── For READ operations (forum listing, viewing topics):
  │   → Allow with stale pv. Cached bitfield still valid.
  │   → Permissions refresh at next token rotation (max 15 min)
  │
  └── For WRITE/SENSITIVE operations (admin actions, permission changes):
      → Require pv match OR force token refresh
      → Respond 401 with header: X-Token-Stale: permissions
      → Client refreshes token → gets fresh pv and flags
```

### `isGranted()` Complete Decision Tree

```
isGranted(User $user, string $permission, ?int $forumId = null): bool
  │
  ├── Is user anonymous?
  │   YES → return false (anonymous has no permissions)
  │
  ├── Is this a global permission (a_*, m_* global, u_*)?
  │   YES ┬── Is user a FOUNDER and permission starts with 'a_'?
  │       │   YES → return true (founder override — NEVER-wins bypassed)
  │       │   NO  → decode flags bitfield from JWT → check bit → return result
  │       │
  │   NO  ┬── Is forumId provided?
  │       │   NO  → throw InvalidArgumentException (forum perm needs forum context)
  │       │   YES → continue
  │       │
  │       ├── Is bitfield loaded in memory?
  │       │   NO → load from cache (key: acl:{userId}:{pv})
  │       │   MISS → load from DB → cache → decode
  │       │
  │       ├── Is permission dual-scope (m_* with is_local=1)?
  │       │   YES → return global_check(JWT flags) OR local_check(bitfield[forumId])
  │       │   NO  → return bitfield[forumId][permission]
  │       │
  │       └── Bit not found? → return false (unknown permission = denied)
  │
  └── END
```

### Admin Gate (`a_` flag)

Used by `AuthorizationSubscriber` for routes with `_api_elevated: true, _api_scope: acp`:

1. Check token `type === 'elevated'`
2. Check `'acp' in token.scope`
3. Check `a_` bit in token `flags` (bit 0 — the root admin permission)
4. If all pass → allow through to controller
5. Controller may do fine-grained checks: `isGranted($user, 'a_forum')`, `isGranted($user, 'a_user')` — all from JWT bitfield, no cache hit

### Moderator Gate (`m_` flag)

For MCP routes (`_api_scope: mcp`):
- Elevated token required for MCP panel access
- Check `'mcp' in token.scope` and `m_` global bits in flags

For per-forum moderation (e.g., editing a post):
- Regular access token sufficient
- `isGranted($user, 'm_edit', forumId: 7)` checks global `m_edit` OR local `m_edit` for forum 7
- No elevation needed — moderation actions in forum context are permission-gated, not elevation-gated

---

## Middleware Pipeline

```
Priority 32: RouterListener (Symfony)
         │   Resolves route → _controller, _api_permission, _api_forum_param,
         │   _api_public, _api_elevated, _api_scope
         │
Priority 16: AuthenticationSubscriber (phpbb\auth)
         │   ┌─ Extract token from Authorization header or HttpOnly cookie
         │   ├─ No token? → set anonymous user on _api_user, continue
         │   ├─ Decode JWT via TokenService::verify() (signature + exp + iss + aud)
         │   ├─ Load user by sub claim (UserRepository::findById)
         │   ├─ Check: jwt.gen < user.token_generation → 401 (revoked)
         │   ├─ Optional: check JTI deny list in cache → 401 (revoked)
         │   ├─ Set _api_user = User entity on request attributes
         │   └─ Set _jwt_payload = decoded claims on request attributes
         │
Priority 10: JsonExceptionSubscriber (REST framework)
         │   Catches exceptions → JSON error response formatting
         │
Priority 8:  AuthorizationSubscriber (phpbb\auth)
         │   ┌─ _api_public: true? → pass through
         │   ├─ _api_user is anonymous? → 401
         │   ├─ _api_elevated: true?
         │   │   ├─ token.type !== 'elevated'? → 403 "Elevation required"
         │   │   └─ _api_scope not in token.scope? → 403 "Insufficient scope"
         │   ├─ _api_permission set?
         │   │   └─ isGranted(user, permission, forumId) → 403 if denied
         │   └─ All checks pass → request continues to controller
         │
Priority 0:  Controller
             Fine-grained isGranted() calls for business logic
             (post ownership, edit windows, dynamic conditions)
```

**Interaction between subscribers and services:**

- `AuthenticationSubscriber` uses `TokenServiceInterface::verify()` and `UserRepository::findById()`
- `AuthorizationSubscriber` uses `AuthorizationServiceInterface::isGranted()`
- Both subscribers read/write `Request::$attributes` — the communication channel between middleware layers
- Controllers inject `AuthorizationServiceInterface` for dynamic permission checks

---

## Interface Definitions (PHP 8.2+)

### AuthenticationServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\auth;

use phpbb\auth\model\TokenPair;
use phpbb\auth\model\ElevatedToken;

interface AuthenticationServiceInterface
{
    /**
     * Authenticate user with credentials and issue token pair.
     *
     * @throws \phpbb\auth\exception\InvalidCredentialsException
     * @throws \phpbb\auth\exception\UserBannedException
     * @throws \phpbb\auth\exception\UserInactiveException
     * @throws \phpbb\auth\exception\RateLimitExceededException
     */
    public function login(string $username, string $password, string $ipAddress, string $userAgent): TokenPair;

    /**
     * Revoke the refresh token family and optionally deny-list the access token JTI.
     */
    public function logout(string $refreshToken, ?string $accessJti = null): void;

    /**
     * Revoke all refresh families and increment token_generation for the user.
     */
    public function logoutAll(int $userId): void;

    /**
     * Issue an elevated token after password re-verification.
     *
     * @param string[] $scopes Requested scopes: 'acp', 'mcp'
     * @throws \phpbb\auth\exception\InvalidCredentialsException
     * @throws \phpbb\auth\exception\InsufficientPermissionException
     */
    public function elevate(int $userId, string $password, array $scopes, string $parentJti): ElevatedToken;

    /**
     * Exchange a valid refresh token for a new token pair.
     *
     * @throws \phpbb\auth\exception\InvalidRefreshTokenException
     * @throws \phpbb\auth\exception\RefreshTokenReusedException  Theft detected — family revoked
     */
    public function refresh(string $refreshToken, string $ipAddress, string $userAgent): TokenPair;
}
```

### AuthorizationServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\auth;

use phpbb\user\model\User;

interface AuthorizationServiceInterface
{
    /**
     * Check if user has a specific permission, optionally in a forum context.
     * Global permissions (a_*, m_* global, u_*) resolved from JWT bitfield — O(1), no cache.
     * Forum permissions (f_*, m_* local) resolved from server-side bitfield cache.
     */
    public function isGranted(User $user, string $permission, ?int $forumId = null): bool;

    /**
     * Check if user has ANY of the listed permissions.
     */
    public function isGrantedAny(User $user, array $permissions, ?int $forumId = null): bool;

    /**
     * Get all forum IDs where user has the given permission.
     * Always uses server-side bitfield (loads full forum permission map).
     */
    public function getGrantedForums(User $user, string $permission): array;

    /**
     * Check if user has the permission in at least one forum.
     */
    public function isGrantedInAnyForum(User $user, string $permission): bool;

    /**
     * Pre-load and cache the complete permission set for a user.
     * Called lazily on first forum-scoped isGranted() — or explicitly for batch checks.
     */
    public function loadPermissions(User $user): void;
}
```

### TokenServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\auth;

use phpbb\auth\model\AuthToken;
use phpbb\auth\model\RefreshToken;
use phpbb\auth\enum\TokenType;
use phpbb\user\model\User;

interface TokenServiceInterface
{
    /**
     * Issue an access JWT for the given user.
     * Encodes identity, generation, permission version, and global bitfield in claims.
     */
    public function issueAccessToken(User $user, string $globalBitfield): AuthToken;

    /**
     * Issue an elevated JWT with restricted audience, scope, and shorter TTL.
     *
     * @param string[] $scopes
     */
    public function issueElevatedToken(User $user, string $globalBitfield, array $scopes, string $parentJti): AuthToken;

    /**
     * Verify a JWT string. Validates signature, exp, iss, aud.
     * Returns decoded payload on success.
     *
     * @throws \phpbb\auth\exception\TokenExpiredException
     * @throws \phpbb\auth\exception\TokenInvalidException
     */
    public function verify(string $jwt, TokenType $expectedType = TokenType::Access): object;

    /**
     * Generate an opaque refresh token and persist its hash.
     * Returns the raw token (sent to client) — never stored server-side.
     */
    public function issueRefreshToken(int $userId, string $familyId, string $ipAddress, string $userAgent): RefreshToken;

    /**
     * Add a JTI to the cache-based deny list.
     * TTL = remaining token lifetime (self-cleaning).
     */
    public function denyJti(string $jti, int $remainingTtl): void;

    /**
     * Check if a JTI is in the deny list.
     */
    public function isJtiDenied(string $jti): bool;
}
```

### Key Value Objects

```php
<?php declare(strict_types=1);

namespace phpbb\auth\model;

/** Represents an issued JWT (access or elevated). */
final readonly class AuthToken
{
    public function __construct(
        public string $jwt,         // Encoded JWT string
        public string $jti,         // Token's unique ID
        public int    $expiresAt,   // Unix timestamp
        public int    $expiresIn,   // Seconds until expiry
    ) {}
}

/** Access token + refresh token pair returned from login/refresh. */
final readonly class TokenPair
{
    public function __construct(
        public AuthToken    $accessToken,
        public RefreshToken $refreshToken,
    ) {}
}

/** Opaque refresh token (raw value for client, hash for storage). */
final readonly class RefreshToken
{
    public function __construct(
        public string $rawToken,    // 64-hex sent to client
        public string $familyId,    // UUID v4 grouping rotation chain
        public int    $expiresAt,   // Unix timestamp
    ) {}
}

/** Elevated token result. */
final readonly class ElevatedToken
{
    public function __construct(
        public AuthToken $token,
        public array     $scopes,   // ['acp'], ['mcp'], or ['acp','mcp']
    ) {}
}

/** Decoded and validated global permission bitfield. */
final readonly class PermissionBitfield
{
    public function __construct(
        private string $raw,                    // Binary string (12 bytes)
        private array  $optionIndexMap,          // permission_name → bit_position
    ) {}

    public function has(string $permission): bool
    {
        $pos = $this->optionIndexMap[$permission] ?? null;
        if ($pos === null) {
            return false;
        }
        $byteIndex = intdiv($pos, 8);
        $bitOffset = $pos % 8;
        return (ord($this->raw[$byteIndex]) >> (7 - $bitOffset) & 1) === 1;
    }
}
```

### Key Enums

```php
<?php declare(strict_types=1);

namespace phpbb\auth\enum;

enum TokenType: string
{
    case Access   = 'access';
    case Elevated = 'elevated';
}

enum PermissionScope: string
{
    case Admin     = 'a_';
    case Moderator = 'm_';
    case User      = 'u_';
    case Forum     = 'f_';
}

enum ElevationScope: string
{
    case Acp = 'acp';
    case Mcp = 'mcp';
}
```

---

## Database Schema

### New Table: `phpbb_auth_refresh_tokens`

```sql
CREATE TABLE phpbb_auth_refresh_tokens (
    token_id     VARCHAR(64)  NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    family_id    VARCHAR(36)  NOT NULL,
    issued_at    INT UNSIGNED NOT NULL,
    expires_at   INT UNSIGNED NOT NULL,
    revoked      TINYINT(1)   NOT NULL DEFAULT 0,
    replaced_by  VARCHAR(64)  NULL,
    user_agent   VARCHAR(255) NULL,
    ip_address   VARCHAR(45)  NULL,

    PRIMARY KEY (token_id),
    INDEX idx_refresh_user_id (user_id),
    INDEX idx_refresh_family_id (family_id),
    INDEX idx_refresh_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### New Columns on `phpbb_users`

```sql
ALTER TABLE phpbb_users
    ADD COLUMN token_generation INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN perm_version     INT UNSIGNED NOT NULL DEFAULT 0;
```

| Column | Type | Purpose | Increment Triggers |
|--------|------|---------|-------------------|
| `token_generation` | INT UNSIGNED | Revocation marker. JWT `gen` < this → reject token | Password change, ban, forced logout, security event |
| `perm_version` | INT UNSIGNED | Permission freshness. JWT `pv` ≠ this → permissions changed | Group perm change, role edit, user added/removed from group, forum create/delete |

### Existing ACL Tables (Unchanged)

The ACL resolution system uses the existing `phpbb_acl_*` tables. These are NOT modified:
- `phpbb_acl_options` — permission definitions (125 rows)
- `phpbb_acl_groups` — group→permission grants
- `phpbb_acl_users` — user→permission grants (overrides)
- `phpbb_acl_roles` — named permission bundles
- `phpbb_acl_roles_data` — role→permission mappings

### Refresh Token Garbage Collection

```sql
-- Cron job: clean expired AND revoked tokens older than 30 days
DELETE FROM phpbb_auth_refresh_tokens
WHERE expires_at < UNIX_TIMESTAMP() - 2592000
  AND revoked = 1;
```

---

## Security Considerations

### CSRF Protection with Stateless JWT

**Web clients** (cookie transport): Double-submit cookie pattern.
- JWT contains a `csrf` claim (random 32-hex value, generated at token issuance)
- Same value set in a **non-HttpOnly** cookie (`phpbb_csrf`)
- Client reads the non-HttpOnly cookie via JS, sends value in `X-CSRF-Token` header on state-changing requests
- Server validates: `jwt_payload.csrf === request.header['X-CSRF-Token']`
- An attacker on a different origin cannot read the cookie (same-origin policy) and cannot forge the header

**API clients** (Bearer header): CSRF protection not needed — the token is not auto-included by the browser.

### XSS Mitigation (Token Storage)

- Access JWT in **HttpOnly, Secure, SameSite=Strict** cookie → JS cannot read it
- Refresh token in **HttpOnly, Secure, SameSite=Strict** cookie with `Path=/auth/refresh` → only sent to refresh endpoint
- Use `__Host-` cookie prefix where supported (forces Secure, no Domain, Path=/)
- NEVER store tokens in localStorage or sessionStorage
- CSP headers: strict `script-src` policy to reduce XSS surface

### Timing Attacks on Token Comparison

- Refresh token lookup: stored as SHA-256 hash → `hash_equals()` for comparison (constant-time)
- JTI deny list: exact string match in cache (key lookup, not comparison)
- Password verification: delegated to `password_verify()` (constant-time by design)
- CSRF token: `hash_equals()` for header vs claim comparison

### Algorithm Confusion Prevention

- `firebase/php-jwt` `Key` class **binds the algorithm to the key** — `new Key($secret, 'HS256')`
- Passing a token with `alg: none` or `alg: RS256` is rejected because the Key only accepts HS256
- No application-level mitigation needed — the library handles it by design
- Key derivation with purpose salt (`jwt-access-v1`, `jwt-elevated-v1`) prevents cross-token-type key reuse

### Key Rotation Procedure

1. Generate new master secret (or derive new key version: `jwt-access-v2`)
2. Add new `kid` → key mapping to key registry (both old and new active)
3. New tokens issued with new `kid`
4. Old tokens validated with old key (matched by `kid` in JWT header)
5. After max TTL window (15 min for access, 7 days for refresh), remove old key
6. Zero downtime — both keys active during transition period

---

## Integration Points

### With User Service

**Direction**: Auth → User (Auth depends on User, never reverse)

| Auth Calls On User | Method | When |
|---------------------|--------|------|
| Find user by username | `UserRepository::findByUsername()` | Login |
| Find user by ID | `UserRepository::findById()` | Token validation (gen check), refresh |
| Verify password | `PasswordService::verifyPassword()` | Login, elevation |
| Check rehash need | `PasswordService::needsRehash()` | Login (transparent upgrade) |
| Check ban | `BanService::isUserBanned()` | Login |
| Increment token gen | `UserRepository::incrementTokenGeneration()` | Logout-all, security events |
| Increment perm version | `UserRepository::incrementPermVersion()` | Permission changes |

**Events Auth listens to (from User Service):**

| Event | Auth Response |
|-------|--------------|
| `PasswordChangedEvent` | Increment `token_generation` (invalidate all JWTs), revoke refresh tokens except current session |
| `UserBannedEvent` | Increment `token_generation`, revoke ALL refresh tokens |
| `UserDeletedEvent` | Revoke all refresh tokens, clear ACL cache |

### With Hierarchy Service

**Direction**: Auth reads forum tree context.

| Integration | Detail |
|-------------|--------|
| Forum permission scoping | `isGranted($user, 'f_post', forumId: 7)` — forumId from route param resolved by Hierarchy |
| `getGrantedForums()` | Auth resolves which forums user can access; Hierarchy uses result to filter visible tree |
| Cache invalidation | Forum create/delete → `perm_version` increment for all users → bitfield rebuild on next access |

### With REST API Framework

**Subscriber chain**: AuthN at priority 16, AuthZ at priority 8. Both subscribe to `kernel.request`.

**Route configuration contract:**

```yaml
# Route defaults that Auth reads:
_api_permission: "f_read"       # Required permission (optional)
_api_forum_param: "forum_id"    # Route param containing forum ID (optional)
_api_public: true               # Skip auth entirely (optional)
_api_elevated: true             # Require elevated token (optional)
_api_scope: "acp"               # Required elevation scope (optional)
```

### With All Services

Any service needing permission checks injects `AuthorizationServiceInterface`:

```php
public function __construct(
    private readonly AuthorizationServiceInterface $authz,
) {}

// In controller or service method:
if (!$this->authz->isGranted($user, 'f_post', $forumId)) {
    throw new ForbiddenException();
}
```

---

## Design Decisions

| ADR | Decision | See |
|-----|----------|-----|
| ADR-001 | Monolithic JWT access + opaque refresh token | [decision-log.md#ADR-001](decision-log.md#adr-001-token-architecture) |
| ADR-002 | Full global bitfield in JWT (~20 bytes, 92 permissions) | [decision-log.md#ADR-002](decision-log.md#adr-002-permission-embedding-strategy) |
| ADR-003 | Separate elevated JWT (5-min TTL) for admin/moderator | [decision-log.md#ADR-003](decision-log.md#adr-003-token-elevation-model) |
| ADR-004 | Three-layer revocation (expiry + gen counter + JTI deny list) | [decision-log.md#ADR-004](decision-log.md#adr-004-revocation-strategy) |
| ADR-005 | HS256 with derived key from master secret | [decision-log.md#ADR-005](decision-log.md#adr-005-key-management) |
| ADR-006 | Three-interface facade (AuthN + AuthZ + Token) | [decision-log.md#ADR-006](decision-log.md#adr-006-service-interface-design) |
| ADR-007 | Base64url-encoded binary bitfield for JWT flags claim | [decision-log.md#ADR-007](decision-log.md#adr-007-bitfield-encoding-format) |
| ADR-008 | Double-submit cookie for CSRF protection | [decision-log.md#ADR-008](decision-log.md#adr-008-csrf-strategy) |
| ADR-009 | One-time-use refresh rotation with family-based theft detection | [decision-log.md#ADR-009](decision-log.md#adr-009-refresh-token-rotation-strategy) |

---

## Concrete Examples

### Example 1: Regular User Reads a Forum Topic

**Given**: User 42 (normal user) has an access token with `flags` containing `u_*` and `f_*` permissions set. Forum 7 has `f_read` granted.

**When**: `GET /api/forums/7/topics/123`

**Then**:
1. `AuthenticationSubscriber` (priority 16): extracts JWT from cookie, verifies signature+exp, loads user. `jwt.gen(3) == user.token_generation(3)` → valid. Sets `_api_user`.
2. `AuthorizationSubscriber` (priority 8): route has `_api_permission: f_read, _api_forum_param: forum_id`. Calls `isGranted(user, 'f_read', 7)`. Forum permission → loads bitfield from cache (`acl:42:17` → hit). Checks `bitfield[7]['f_read'] = 1` → allowed.
3. Controller executes. No further permission checks needed for read.

### Example 2: Admin Edits Forum Permissions via ACP

**Given**: User 1 (founder, `utype=3`) has an access token AND an elevated token with `scope: ['acp']`, `aud: 'phpbb-admin'`.

**When**: `PUT /api/admin/forums/7/permissions` with elevated token, `X-CSRF-Token` header matching JWT `csrf` claim.

**Then**:
1. `AuthenticationSubscriber`: extracts elevated JWT, verifies signature+exp+gen. Sets `_api_user` and `_jwt_payload`.
2. `AuthorizationSubscriber`: route has `_api_elevated: true, _api_scope: acp, _api_permission: a_forum`. Checks `type === 'elevated'` ✓, `'acp' in scope` ✓. Calls `isGranted(user, 'a_forum')`. Global permission → checks JWT flags bitfield bit for `a_forum`. Founder override: `utype=3` + `a_*` prefix → always YES.
3. CSRF check: `jwt.csrf === X-CSRF-Token header` → valid.
4. Controller processes permission update. Increments `perm_version` for affected users.

### Example 3: Token Theft Detection via Refresh Reuse

**Given**: User 42 logged in on Device A. Attacker stole the refresh token. Attacker used it first → got new token pair (rotated). User 42 tries to refresh with the old (now-revoked) token.

**When**: `POST /auth/refresh` with the old refresh token.

**Then**:
1. `TokenService` hashes the token, looks up in DB. Found — but `revoked = 1`.
2. **Theft detection triggered**: a revoked token was reused. This means either the user or the attacker has a stale token.
3. Entire family revoked: `UPDATE phpbb_auth_refresh_tokens SET revoked = 1 WHERE family_id = :familyId`.
4. Both the attacker's new token and any remaining tokens in this family are now invalid.
5. User 42 must re-authenticate (login). Security event logged.

---

## Out of Scope

| Area | Reason | Future Phase |
|------|--------|-------------|
| OAuth2/OIDC provider mode | phpBB as identity provider for external apps — requires protocol implementation | Phase 3+ |
| LDAP/OAuth2 consumer auth providers | Enterprise auth sources — Phase 1 is DB-only. `AuthProviderInterface` extensibility point exists | Phase 2 |
| WebAuthn / Passkeys | Passwordless authentication — separate auth provider | Phase 2 |
| Scoped API tokens (non-JWT) | Limited-permission tokens for integrations | Future |
| Session binding (TLS/device fingerprint) | Additional theft resistance layer | Future |
| Adaptive / risk-based authentication | Step-up based on IP/device anomaly | Future |
| Admin token management UI | View/revoke user sessions via ACP API | Future |
| Remember-me with long-lived refresh | 30-day refresh token — same mechanism, config change to TTL | Stretch |
| Legacy session coexistence | Parallel `phpbb_sessions` for legacy web frontend during migration | Migration phase |
| Bitfield computation / ACL cache build logic | Owned by the ACL domain — Auth consumes the pre-computed result | Separate service concern |

---

## Success Criteria

1. **Stateless identity verification**: Access token validated by signature alone — zero DB queries for authentication on 100% of requests
2. **O(1) permission checks**: Global permissions from JWT bitfield (no cache), forum permissions from in-memory decoded bitfield (one cache read per request)
3. **Sub-second revocation**: Generation counter invalidates all user tokens on next request; JTI deny list provides immediate single-token revocation when cache is available
4. **Elevation isolation**: Elevated token expires independently (5 min), has different audience/scope, uses different derived key — compromise of access token does not grant admin access
5. **Theft detection**: Refresh token reuse triggers family revocation — attacker and user both forced to re-authenticate
6. **Zero backward compatibility burden**: All interfaces, value objects, and enums are PHP 8.2+ modern code with no legacy phpBB dependencies
