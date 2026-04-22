# Decision Log: Unified Auth Service (`phpbb\auth`)

All decisions reference alternatives analyzed in [solution-exploration.md](solution-exploration.md).

---

## ADR-001: Token Architecture

### Status
Accepted

### Context
The auth service needs a token model for stateless API authentication. The token structure determines what can be verified without server-side state, how large HTTP headers/cookies become, and how the system handles multiple token lifecycles (access, refresh, elevated). The user requirement mandates stateless JWT — this supersedes the prior REST API ADR-005 which recommended opaque DB tokens.

### Decision Drivers
- User requirement: stateless JWT (non-negotiable)
- Refresh token theft detection requires server-side state (family tracking)
- Token size must stay well within 4096-byte cookie budget
- Single validation path in middleware for simplicity
- Device/session management via refresh token families

### Considered Options
1. **Monolithic Token Pair** — single JWT access + opaque refresh
2. **Split Token Architecture** — separate identity JWT + authorization JWT
3. **Fully Stateless Refresh** — both access and refresh are JWTs
4. **Opaque Sessions** — opaque access token + server-side session (no JWT)

### Decision Outcome
Chosen option: **1. Monolithic Token Pair**, because it is the simplest architecture satisfying all requirements. Token size (~350 bytes) uses ~10% of cookie budget. The hybrid model (JWT identity + server-side bitfield) already achieves the separation that option 2 tries at the JWT level. Family-based refresh rotation (excluded by option 3) is critical for theft detection.

### Consequences

#### Good
- Simplest mental model — one JWT type for all regular API access
- Single validation path in `AuthenticationSubscriber`
- Well-understood OAuth2 access+refresh pattern
- Refresh tokens provide session-level management (per-device, revocable)

#### Bad
- All claims travel with every request, even when not needed (~350 bytes, negligible)
- No partial token refresh when permissions change (mitigated by 15-min TTL + `perm_version`)
- Server state required for refresh tokens (`phpbb_auth_refresh_tokens` table)

### References
- solution-exploration.md §Decision Area 1
- research-report.md §4.1–4.3
- synthesis.md §C1 (JWT vs DB Tokens resolution)

---

## ADR-002: Permission Embedding Strategy

### Status
Accepted

### Context
phpBB has 125 permissions across 4 scopes (admin: 42, moderator: 15, user: 35, forum: 33). 92 are global-only (`a_*`, `m_*` global, `u_*`), 48 are forum-scoped. The design must balance stateless verification speed against permission freshness and token size. This is the most architecturally significant decision.

### Decision Drivers
- 92 global permissions = ~93 bits = ~12 bytes raw = ~20 bytes base64url — trivially small
- Forum permissions scale with forum count (unbounded) — cannot go in JWT
- Most API routing decisions need only global permission checks
- Zero cache hits for global checks is achievable at negligible token cost
- `perm_version` mechanism provides freshness detection

### Considered Options
1. **Flags-Only in JWT** — 3 category flags (`a`, `m`, `u`) + server-side bitfield for all fine-grained checks
2. **Full Global Bitfield in JWT** — all 92 global permissions encoded, forum permissions server-side
3. **Permission Hash** — SHA-256 truncated hash of bitfield for staleness detection
4. **Minimal Claims (Lazy)** — identity only, all permissions lazy-loaded
5. **Role-Based Claims** — coarse role string (`admin`, `moderator`, `user`)

### Decision Outcome
Chosen option: **2. Full Global Bitfield in JWT**, because embedding 93 bits (~20 bytes) achieves true statelessness for 92/125 permission checks at negligible token size cost. The flags-only approach (option 1) is a close second but wastes the opportunity to eliminate cache hits for global permission checks. Option 5 (roles) is fundamentally incompatible with phpBB's capability-based permission model.

### Consequences

#### Good
- Zero cache hits for all global permission checks (admin, moderator, user permissions)
- Token size increase is trivial (~20 bytes → ~370 total)
- Admin/moderator/user permission checks are fully stateless and O(1)
- Forum-only checks still use the efficient O(1) bitfield cache

#### Bad
- Global permissions in JWT are stale until token refresh (max 15 min)
- Slightly more complex claim parsing (decode base64url bitfield in middleware, ~0.1ms)
- If global permission count grows beyond ~200+, the bitfield claim grows proportionally

### References
- solution-exploration.md §Decision Area 2
- research-report.md §5.1, §5.5, Appendix B
- synthesis.md §I1 (Bitfield IS the stateless permission cache), §I2 (Global perms fit in JWT)

---

## ADR-003: Token Elevation Model

### Status
Accepted

### Context
Administrative and moderator operations need stronger authentication than regular API access. The legacy phpBB system required password re-entry for ACP access, creating a `session_admin=1` flag. The new JWT-based system needs an equivalent mechanism that preserves the UX pattern while providing shorter exposure windows.

### Decision Drivers
- Security: shorter TTL for privileged operations limits blast radius
- UX: familiar pattern (phpBB users already re-enter password for ACP)
- Clean separation: regular and privileged operations should use different token types
- Audit trail: link elevated actions to the originating access token

### Considered Options
1. **Separate Elevated Token** — independent JWT with `aud: phpbb-admin`, 5-min TTL, scope claims
2. **Token Exchange (Scope-Upgrade)** — replace access token with upgraded version including scope
3. **Grace Period** — always carry admin flags, check `last_auth` timestamp
4. **Per-Action Confirmation** — password/TOTP on every admin mutation

### Decision Outcome
Chosen option: **1. Separate Elevated Token**, because it preserves the familiar UX pattern (re-enter password → get admin access), provides the shortest exposure window (5-min TTL vs 15-min for option 2), and cleanly separates regular and privileged operations. The `elv_jti` claim provides audit trail linking.

### Consequences

#### Good
- Shortest exposure window (5-min TTL) for privileged operations
- Clean audience separation (`phpbb-api` vs `phpbb-admin`)
- Different derived signing key for elevated tokens (compartmentalized key compromise)
- Trivially revocable — don't refresh, let it expire
- Familiar UX pattern matching legacy phpBB admin access

#### Bad
- Client manages two active token types (three including refresh)
- Admin may need to re-elevate mid-task if 5-min TTL expires (mitigated: TTL is configurable)
- Additional endpoint (`POST /auth/elevate`) and validation path

### References
- solution-exploration.md §Decision Area 3
- research-report.md §6.2, §6.3
- synthesis.md §P5 (Password re-authentication for elevation), §T2 (Group token → elevated token)

---

## ADR-004: Revocation Strategy

### Status
Accepted

### Context
JWT's statelessness means tokens are valid until expiry unless the system can detect and reject revoked tokens. Security events (password change, ban, forced logout) require faster invalidation than natural expiry. The revocation strategy determines the trade-off between statelessness and security guarantees.

### Decision Drivers
- Password changes and bans must invalidate tokens faster than 15-minute natural expiry
- Generation counter piggybacks on user record load (already needed for permission checks) — zero additional cost
- JTI deny list is rare-case insurance (< 10 entries typically) with self-cleaning TTL
- Defense in depth: independent layers add resilience

### Considered Options
1. **Short TTL Only** — rely entirely on natural expiry (15 min window)
2. **Generation Counter Only** — per-user integer, invalidates all user tokens at once
3. **Three-Layer** — natural expiry + generation counter + optional JTI deny list
4. **Refresh Family Only** — revoke refresh tokens, let access tokens expire naturally

### Decision Outcome
Chosen option: **3. Three-Layer**, because Layers 1+2 cover 99%+ of real-world scenarios at effectively zero cost, and Layer 3 (JTI deny list) adds emergency single-token revocation at ~20 lines of code and ~0 runtime cost when unused. Layer 3 is optional and can be disabled by configuration.

### Consequences

#### Good
- Complete coverage: per-token (Layer 3), per-user (Layer 2), and natural (Layer 1)
- Generation counter costs zero additional DB queries (user record already loaded)
- JTI deny list is self-limiting (cache entries auto-expire with token TTL)
- Each layer independently adds value — defense in depth
- Layer 3 is optional (coded but can be disabled for deployments without shared cache)

#### Bad
- Three mechanisms to understand, implement, and test
- JTI deny list technically breaks pure statelessness (ephemeral cache state)
- Requires cache backend (Redis) for Layer 3; Layers 1+2 work without it

### References
- solution-exploration.md §Decision Area 4
- research-report.md §4.4 (all three layers detailed)
- synthesis.md §I4 (Generation counter satisfies revocation), §T3 (Revocation vs statelessness)

---

## ADR-005: Key Management

### Status
Accepted

### Context
The JWT signing key is the single secret that, if compromised, allows forging any token. The system is a PHP monolith with no near-term multi-service plans. Key management must be simple, secure, and support zero-downtime rotation.

### Decision Drivers
- HS256 is ~10x faster than RSA signature operations
- Single application — no need for public key distribution to external verifiers
- `firebase/php-jwt` Key class binds algorithm to key, preventing algorithm confusion
- Key derivation with purpose salts provides key separation without multiple secrets
- `kid` header in JWT enables zero-downtime rotation

### Considered Options
1. **HS256 with Derived Key** from master secret in config.php, kid header for rotation
2. **RS256 Key Pair** — private/public PEM files, JWKS endpoint
3. **EdDSA (Ed25519)** — modern asymmetric, smaller keys
4. **HS256 with Environment Variable** — same as 1 but secret from `$_ENV`

### Decision Outcome
Chosen option: **1. HS256 with Derived Key**, because it is the simplest and fastest approach for a monolith. Key derivation (`hash_hmac('sha256', 'jwt-{type}-v{version}', $masterSecret)`) provides per-token-type key separation from a single 64-hex master secret. The secret source (config.php vs env var) is a deployment configuration concern, not an architectural one — both will be supported.

### Consequences

#### Good
- Fastest signing/verification (~0.01ms HMAC vs ~1ms RSA)
- Single master secret to manage
- Derived keys per token type (access vs elevated) compartmentalize compromise
- `kid` header enables zero-downtime rotation
- Algorithm confusion mitigated by library design (Key class binds algo)

#### Bad
- No public key distribution — external services cannot verify tokens independently
- Single point of compromise if master secret is leaked
- Migration to RS256/EdDSA required if multi-service verification is needed (straightforward: issue new tokens with new algo, old expire within 15 min)

### References
- solution-exploration.md §Decision Area 5
- research-report.md §R2, §A6
- synthesis.md §6 (secondary conclusion: HS256 for PHP monolith)

---

## ADR-006: Service Interface Design

### Status
Accepted

### Context
The auth service is consumed by every other service in the system. Its interface determines coupling, testability, and how auth concerns are distributed. Most consumers only check permissions; a few handle login/logout; token internals are infrastructure-level.

### Decision Drivers
- Interface Segregation Principle: consumers should depend only on what they use
- Most controllers only need `isGranted()` — shouldn't depend on `login()` signatures
- Symfony DI autowiring works naturally with typed interfaces
- Clean separation enables independent testing and mocking
- Three concerns map to three natural interfaces: AuthN flows, AuthZ checks, Token infrastructure

### Considered Options
1. **Three-Interface Facade** — `AuthenticationServiceInterface`, `AuthorizationServiceInterface`, `TokenServiceInterface`
2. **Single Unified Interface** — one `AuthServiceInterface` with all methods
3. **Middleware-Only** — no injected service, auth via request attributes only
4. **Event-Driven** — dispatch `CheckPermissionEvent` for every permission check

### Decision Outcome
Chosen option: **1. Three-Interface Facade**, because it matches the natural responsibility boundaries. Controllers inject only `AuthorizationServiceInterface`. Login/logout endpoints inject `AuthenticationServiceInterface`. Subscribers use `TokenServiceInterface` internally. Mocking in tests is clean — mock only the interface under test.

### Consequences

#### Good
- ISP compliance — controllers don't depend on auth flow methods
- Each interface is independently testable and mockable
- Clean ownership: most services only know `AuthorizationServiceInterface`
- Token internals hidden from business logic consumers
- Symfony DI autowiring maps interfaces to implementations naturally

#### Bad
- Three interfaces to maintain and document (stable abstractions, unlikely to change)
- Cross-interface operations (login needs both AuthN + Token) handled internally by implementation
- Slightly more DI configuration (negligible with autowiring)

### References
- solution-exploration.md §Decision Area 6
- research-report.md §R1
- synthesis.md §C3 (unified ownership), §P1 (layered gate cascade shows distinct concerns)

---

## ADR-007: Bitfield Encoding Format

### Status
Accepted

### Context
The JWT `flags` claim carries 92 global permission bits. The encoding format affects token size, decode complexity, and readability. Research noted two formats: base-36 string (`a1b2c3...`, ~18 chars) and base64url binary (`AQAAAQ...`, ~16 chars).

### Decision Drivers
- Minimize encoded size in JWT payload
- Fast decode in PHP middleware (< 0.1ms)
- Standard encoding — no custom alphabet
- Consistent with JWT conventions (base64url is the standard encoding in JWTs)

### Considered Options
1. **Base64url-encoded binary** — pack 92 bits into 12 bytes, base64url encode → 16 chars
2. **Base-36 string** — treat bitfield as large integer, encode in base 36 → 18 chars
3. **JSON array of permission strings** — `["a_forum", "a_user", ...]` per active permission
4. **Hex string** — 12 bytes → 24 hex chars

### Decision Outcome
Chosen option: **1. Base64url-encoded binary**, because base64url is the native encoding of JWT payloads (header and payload are already base64url), it produces the smallest representation (16 chars), and PHP has built-in `base64_decode()` / `base64_encode()`. Decoding to binary string → bit checking via `ord()` and bitwise ops is trivial and fast.

### Consequences

#### Good
- Smallest encoding (16 chars for 92 bits)
- Native JWT encoding convention — no custom serialization
- Fast decode: `base64_decode()` → binary string → `ord($byte) >> $offset & 1`
- No dependency on arbitrary alphabet or big-integer libraries

#### Bad
- Not human-readable in JWT debugger tools (opaque base64 string)
- Bit position mapping must be maintained as a separate index (`permission_name → bit_position`)

---

## ADR-008: CSRF Strategy

### Status
Accepted

### Context
When JWT is transported via HttpOnly cookies (web SPA), the browser auto-includes it on every request to the origin. This makes the system vulnerable to CSRF — a malicious site can trigger state-changing requests that carry the victim's cookie. API clients using Bearer headers are not affected.

### Decision Drivers
- Must work with HttpOnly cookie transport (no JS access to the token)
- Must not require server-side CSRF state (stateless design goal)
- Should be compatible with SPA architecture (custom headers from JS)
- Legacy phpBB used `form_salt` / `check_form_key()` — server-side state, not applicable to JWT

### Considered Options
1. **Double-submit cookie** — `csrf` claim in JWT + non-HttpOnly cookie + `X-CSRF-Token` header
2. **Synchronizer token pattern** — server-side CSRF token in session/DB
3. **SameSite=Strict only** — rely entirely on cookie SameSite attribute
4. **Custom header requirement** — require `X-Requested-With` on all state-changing requests

### Decision Outcome
Chosen option: **1. Double-submit cookie**, because it is fully stateless (CSRF value embedded in JWT at issuance), works with HttpOnly cookies, and is compatible with SPA architecture. The non-HttpOnly `phpbb_csrf` cookie is readable by same-origin JS, which sends its value in the `X-CSRF-Token` header. Cross-origin attackers cannot read same-origin cookies, so they cannot forge the header.

### Consequences

#### Good
- Fully stateless — CSRF value generated at token issuance, stored in JWT, no server state
- SPA-compatible: JS reads non-HttpOnly cookie, sends as header
- Server validation is trivial: `hash_equals($jwt->csrf, $request->headers->get('X-CSRF-Token'))`
- `SameSite=Strict` as defense-in-depth alongside double-submit

#### Bad
- Requires two cookies: HttpOnly JWT cookie + non-HttpOnly CSRF cookie
- Client must implement CSRF header sending for all state-changing requests
- CSRF token rotates with JWT — client must re-read cookie after token refresh

---

## ADR-009: Refresh Token Rotation Strategy

### Status
Accepted

### Context
Refresh tokens are the only server-side state in the auth system. Their rotation model determines theft detection capability, session management features, and database growth patterns.

### Decision Drivers
- Theft detection: if an attacker uses a stolen refresh token, the system must detect it
- One-time-use rotation: each refresh token is valid for exactly one exchange
- Family grouping: all tokens in a rotation chain share a `family_id` → one device = one family
- Device management: listing/revoking individual devices maps to listing/revoking families
- DB cleanup: expired+revoked tokens garbage-collected by cron

### Considered Options
1. **One-time-use with family rotation** — each refresh creates successor in same family; reuse of old token = theft
2. **Sliding window** — refresh tokens valid for N uses within a time window
3. **No rotation** — long-lived refresh token reused until expiry
4. **Refresh token versioning** — counter-based rotation without family concept

### Decision Outcome
Chosen option: **1. One-time-use with family rotation**, because it provides the strongest theft detection. When a revoked token is reused, the entire family is immediately revoked — both the attacker and the legitimate user lose their session, but the attacker cannot maintain persistent access. The `replaced_by` column creates an audit chain.

### Consequences

#### Good
- Strongest theft detection: reuse of revoked token → immediate family revocation
- Each device/session = one family → maps naturally to "manage your sessions" UI
- `replaced_by` column creates complete rotation audit chain
- Expired + revoked tokens self-clean via periodic garbage collection cron

#### Bad
- One DB write per refresh (INSERT new + UPDATE old) — acceptable for 15-min refresh interval
- Network failure during rotation can orphan a token (client got new pair but old not marked revoked — mitigated by checking `replaced_by` chain)
- Family table grows until GC runs (kept small by 7-day TTL + periodic cleanup)

### References
- research-report.md §4.3 (refresh token rotation flow)
- synthesis.md §I6 (Refresh tokens are the only required server state)
