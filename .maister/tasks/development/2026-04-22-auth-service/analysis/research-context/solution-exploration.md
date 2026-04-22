# Solution Exploration: Unified Auth Service Design

**Research Question**: How should `phpbb\auth` be designed as a unified service handling both AuthN and AuthZ, with stateless JWT tokens and user/group token elevation model?

**Critical Constraint**: Clean-break rewrite. Legacy phpBB serves as inspiration only — zero backward compatibility requirements.

---

## Problem Reframing

### Research Question
Design a unified authentication and authorization service for a modern PHP 8.2+ forum platform that provides stateless identity verification via JWT, efficient permission resolution via cached bitfields, and a privilege escalation model for administrative operations.

### How Might We Questions

1. **HMW carry enough authorization state in a JWT to avoid per-request DB hits while keeping tokens small?**
2. **HMW allow admin/moderator privilege escalation that's secure, time-bounded, and natural to use?**
3. **HMW revoke stateless tokens when security events demand it without rebuilding server-side sessions?**
4. **HMW manage JWT signing keys safely for a PHP monolith that may eventually become multi-service?**
5. **HMW expose auth capabilities to other services without coupling them to implementation details?**
6. **HMW keep 125 permissions across 4 scopes performant at O(1) while remaining extensible?**

---

## Decision Area 1: Token Architecture

### Why It Matters
The token structure defines the fundamental contract between client and server. It determines what can be verified statelessly, how large HTTP headers/cookies become, and how the system handles multiple token lifecycles (access, refresh, elevated).

### Alternative 1A: Monolithic Token Pair (Access + Refresh)

Two token types: a **single JWT access token** carrying all claims (identity, flags, metadata) and an **opaque refresh token** stored server-side.

**Description**: One JWT serves all non-elevated purposes. Claims include `sub`, `type`, `utype`, `gen`, `pv`, `flags`, `jti`, standard registered claims. Refresh is opaque (random hex), stored as SHA-256 hash in DB with family-based rotation.

**Strengths**:
- Simplest mental model — one token for all regular API access
- Single validation path in middleware
- Minimal cookie/header management on client
- Well-understood pattern (matches OAuth2 access+refresh)

**Weaknesses**:
- All claims travel with every request, even when not needed
- Token size is fixed at maximum (even for simple reads)
- No granularity — can't issue reduced-scope tokens

**Best when**: Application is a single deployment, token size is well within limits (~350-400 bytes), and simplicity outweighs flexibility.

**Evidence**: Research report §6.1 estimates ~350 bytes encoded. Cookie budget is 4096 bytes — using only ~10%. Synthesis §I2 confirms global permissions (93 bits) as flags fit trivially.

### Alternative 1B: Split Token Architecture (Identity + Authorization)

Separate the **identity token** (who you are) from the **authorization token** (what you can do). Identity token is small and long-lived; authorization token is short and carries permission-relevant claims.

**Description**: Identity token contains only `sub`, `gen`, `iss`, `aud`, `exp` (~200 bytes). Authorization token adds `flags`, `pv`, `utype` and has a shorter TTL. Client sends both on permission-sensitive requests, identity-only on public/read endpoints.

**Strengths**:
- Smaller identity token for high-frequency read requests
- Authorization token can be refreshed independently when permissions change
- Cleaner separation of concerns

**Weaknesses**:
- Client must manage two tokens — significantly more complexity
- Two validation paths in middleware
- Race conditions when authorization token expires but identity doesn't
- Over-engineered for a monolith where both tokens are validated in the same process

**Best when**: Microservices architecture where different services need different claim subsets, or when token size is a genuine constraint.

**Evidence**: Synthesis §I1 notes JWT is for identity while bitfield is for permissions — but this separation is already achieved by the hybrid model (JWT identity + server-side bitfield). Splitting the JWT itself adds complexity without proportional benefit.

### Alternative 1C: JWT Access + JWT Refresh (Fully Stateless)

Both access and refresh tokens are JWTs. No server-side refresh token storage.

**Description**: Access JWT (15 min TTL) plus Refresh JWT (7 day TTL, audience `phpbb-refresh`). Refresh token is validated by signature verification alone. No DB table for refresh tokens.

**Strengths**:
- Truly zero server state for token management
- No `phpbb_auth_refresh_tokens` table needed
- Simpler refresh flow — JWT verification only

**Weaknesses**:
- **No theft detection** — can't detect refresh token reuse (no family tracking)
- **No device management** — can't list or revoke individual sessions
- **Logout-all requires generation counter only** — no per-device revocation
- Refresh token becomes a long-lived bearer credential with no usage tracking
- Contradicts research finding that refresh tokens are "the only required server state" (for good reason)

**Best when**: Stateless purity is valued over security features. Not recommended for a forum platform where account security matters.

**Evidence**: Synthesis §I6 explicitly states "Refresh tokens are the ONLY required server state" and provides the rationale: theft detection via family-based rotation. Research report §4.3 details the rotation mechanism. Removing server-side refresh tokens eliminates the theft detection capability.

### Alternative 1D: Opaque Access Token + Server-Side Session (Hybrid Stateful)

Access token is an opaque random string that maps to a server-side session record. No JWT at all for access. Refresh token is also opaque.

**Description**: Login returns an opaque 64-hex access token. Server looks it up in a `phpbb_auth_sessions` table on every request. Session record contains user_id, flags, permissions reference, expiry. Essentially a modern, clean reimplementation of phpBB's session model.

**Strengths**:
- Instant revocation — delete the session row
- No token size concerns
- No signing key management
- Simpler security model (no algorithm confusion, no claim tampering)
- Permission changes take effect immediately (no perm_version staleness)

**Weaknesses**:
- **Contradicts user requirement** for stateless JWT
- Per-request DB/cache hit for session lookup (vs signature verification for JWT)
- Session table grows with active users, needs cleanup cron
- Doesn't enable future multi-service verification without shared session store

**Best when**: User explicitly chooses stateful session model. Not applicable here — user decision mandates JWT.

**Evidence**: Research report §4 explicitly notes prior REST API ADR-005 recommended DB tokens, but user reversed this decision in favor of stateless JWT (Synthesis §C1).

### Trade-Off Matrix: Token Architecture

| Perspective | 1A: Monolithic Pair | 1B: Split Tokens | 1C: Fully Stateless | 1D: Opaque Sessions |
|------------|:---:|:---:|:---:|:---:|
| Technical Feasibility | High | High | High | High |
| User Impact | High (simple client) | Medium (complex client) | Medium (no device mgmt) | High (simple) |
| Simplicity | **High** | Low | Medium | Medium |
| Risk | Low | Medium (complexity) | **High** (no theft detect) | Low |
| Scalability | High | High | High | Medium (DB per request) |

### Recommendation: Alternative 1A — Monolithic Token Pair

**Confidence**: High (90%)

**Rationale**: The monolithic access JWT + opaque refresh token is the simplest architecture that satisfies all requirements. Token size (~350 bytes) is well within limits. The hybrid model (JWT for identity, server-side bitfield for permissions) already provides the separation that 1B tries to achieve at the JWT level. Family-based refresh rotation (excluded by 1C) is critical for theft detection.

**Key trade-offs accepted**: All claims travel with every request (negligible cost at 350 bytes). No partial token refresh when permissions change (mitigated by 15-min TTL and `perm_version` check).

**Key assumptions**: Token size stays under ~500 bytes (true unless claim set grows significantly). Single deployment target for foreseeable future (HS256 sufficient).

---

## Decision Area 2: Permission Embedding Strategy

### Why It Matters
The 125-permission ACL system with 4 scopes is the backbone of phpBB authorization. How permissions relate to the JWT determines the balance between stateless verification speed and permission freshness. This is the most architecturally significant decision.

### Alternative 2A: Flags-Only in JWT + Server-Side Bitfield (Hybrid Cache)

JWT carries 3 coarse permission **category flags** (`a`, `m`, `u`/`f`) as a small array. All fine-grained permission checks resolved server-side from the cached bitfield, loaded once per request.

**Description**: JWT `flags` claim is an array like `["u", "f"]` (5-15 bytes). Middleware uses flags for quick gate decisions (ACP gate = `a` in flags, MCP gate = `m` in flags). For fine-grained checks (e.g., `f_post` in forum 7), the `AuthorizationService` loads the user's pre-computed bitfield from cache (keyed by `user_id:perm_version`), decodes it into memory, and all subsequent `isGranted()` calls in the same request are O(1) lookups in the in-memory array.

**Strengths**:
- JWT stays minimal (~350 bytes)
- Bitfield is proven O(1) at all board sizes (100 bytes for 5 forums, 12 KB for 1000 forums)
- Quick routing decisions (ACP/MCP gates) need no cache hit
- `perm_version` in JWT enables stale detection without cache lookup
- Forum permissions scale naturally (not in JWT, in bitfield)

**Weaknesses**:
- First fine-grained permission check per request incurs one cache read
- If cache misses, falls through to DB read + bitfield rebuild (~50ms)
- Flags may become stale for up to 15 minutes (access token TTL)

**Best when**: Forum count is non-trivial (>5), permissions are checked frequently per request, and O(1) per-check performance matters more than zero-state purity.

**Evidence**: Synthesis §I1 ("Bitfield IS the stateless permission cache"), §I2 (global perms = 93 bits, forum perms scale unbounded), §T1 resolution. Research report §5.1, §5.5 (bitfield size analysis).

### Alternative 2B: Full Global Bitfield in JWT

Embed the full **global permission bitfield** (admin + moderator + user = ~93 bits = ~20 bytes base64) directly in the JWT. Forum permissions stay server-side.

**Description**: JWT carries a `perms` claim containing the base64-encoded global bitfield. This covers all 92 global permissions (`a_*`, `m_*`, `u_*`). The `AuthorizationService` can resolve any global permission check directly from the JWT payload without a cache hit. Forum-specific permissions (`f_*` + local `m_*`) still come from the server-side bitfield cache.

**Strengths**:
- **Zero cache hits for global permission checks** — fully stateless for 92/125 permissions
- Token size increase is trivial (~20 bytes → ~370 total)
- Admin, moderator, and user permission checks need no server-side state at all
- Forum-only checks still use the efficient bitfield cache

**Weaknesses**:
- Global permissions in JWT are stale until token refresh (max 15 min)
- Changing a user's admin permissions doesn't take effect until token rotates (mitigated by `perm_version` check on sensitive operations)
- Slightly more complex claim parsing (decode bitfield in middleware)
- If global permission count grows beyond ~200, the bitfield claim grows proportionally

**Best when**: Most API endpoints primarily check global permissions (admin actions, user-level operations like PM sending, profile editing). Forum-specific actions are a subset.

**Evidence**: Synthesis §I2 explicitly states "Global permissions fit in JWT" and calculates 93 bits → 18 base-36 chars. Research report Appendix B confirms 92 global permissions. The 20-byte cost is negligible against the 4096-byte cookie budget.

### Alternative 2C: Permission Hash + Server-Side Validation

JWT carries a **hash of the permission set** (not the permissions themselves). Server checks the hash against the cached bitfield's hash to detect staleness, then resolves permissions from cache.

**Description**: JWT `perm_hash` = SHA-256(bitfield)[0:8] (8-byte truncated hash). On each request, server loads bitfield, computes hash, compares. Match → bitfield is consistent with what was true at token issuance. Mismatch → permissions changed, trigger re-evaluation or token refresh.

**Strengths**:
- Very small JWT claim (8 bytes)
- Can detect ANY permission change, not just version increments
- No risk of stale permission data in the token itself

**Weaknesses**:
- **Still requires server-side bitfield load on every request** — the hash can't be used for actual permission checks
- Added computation (hash comparison) with no decision-making benefit over `perm_version` integer
- If bitfield isn't cached, you compute a hash of data you had to load anyway
- Strictly worse than `perm_version` (same freshness detection, more computation, less information)

**Best when**: Never — `perm_version` integer achieves the same freshness detection at lower cost.

**Evidence**: Synthesis §I3 describes the `perm_version` mechanism. A hash adds computation without additional capability. The version counter is monotonically increasing and directly signals "something changed" without needing the full bitfield to compare.

### Alternative 2D: Minimal Claims + Lazy Permission Load

JWT carries only identity (`sub`, `gen`). **All** permission checks are lazy-loaded on first use from cache/DB. No flags, no `perm_version`, no permission data in JWT at all.

**Description**: JWT is purely an identity token. The `AuthorizationService` is invoked only when needed (lazy). First `isGranted()` call loads the full bitfield from cache. If no permission check is needed (e.g., public endpoints), no cache hit occurs.

**Strengths**:
- Smallest possible JWT (~250 bytes)
- No stale permission data in token — always fresh from cache
- Clean separation: JWT = identity, cache = permissions (no overlap)

**Weaknesses**:
- **ACP/MCP gate checks require a cache hit** — can't do quick routing from JWT alone
- Every non-public request incurs at least one cache read (same as 2A, but without the flag shortcut)
- Loses the `perm_version` freshness signal — can't detect staleness without loading the full bitfield
- Middleware can't reject unauthorized requests early for admin routes without hitting cache

**Best when**: Permission checks are rare (most routes are public), or cache latency is near-zero (in-process memory cache).

**Evidence**: Synthesis §P1 describes the 4-layer security gate cascade. Layer 2 (category gate) uses `a_`/`m_` flags for quick routing. Without them, gate checks must load the full bitfield, removing the optimization that flags provide.

### Alternative 2E: Role-Based JWT Claims + Server-Side Fine-Grained

JWT carries a **role string** (`admin`, `moderator`, `user`, `founder`) instead of permission flags. Roles are coarse groupings. Fine-grained checks are server-side.

**Description**: JWT `role` claim = `admin | global_mod | user | founder`. Derived from the highest-privilege permission category the user has. ACP gate checks `role in [admin, founder]`. MCP gate checks `role in [admin, founder, global_mod]`. Fine-grained permissions from bitfield.

**Strengths**:
- Simple mental model — everyone understands roles
- Very small claim (1 string)
- Easy to explain in API documentation

**Weaknesses**:
- **phpBB permissions are NOT role-based** — a user can have `a_forum` without `a_user`. A single "admin" role loses this granularity
- Role derivation is lossy — two users with different admin sub-permissions get the same "admin" role
- A "global_mod" role doesn't distinguish between a user who has only `m_edit` globally vs one who has all `m_*`
- Introduces a false abstraction that conflicts with the bitfield model

**Best when**: The permission system is genuinely role-based (RBAC). phpBB's system is capability-based with NEVER-wins, which doesn't map cleanly to roles.

**Evidence**: Synthesis §1.1 ("Permissions are 100% bitfield-based, NOT group-based"), §C2 resolution. Group membership ≠ permissions. Role labels would introduce a lie into the token.

### Trade-Off Matrix: Permission Embedding Strategy

| Perspective | 2A: Flags + Bitfield | 2B: Full Global in JWT | 2C: Perm Hash | 2D: Minimal (Lazy) | 2E: Roles |
|------------|:---:|:---:|:---:|:---:|:---:|
| Technical Feasibility | High | High | High | High | Medium |
| User Impact | High (fast gates) | **High** (fastest) | Low (adds nothing) | Medium (slower gates) | Medium |
| Simplicity | **High** | Medium | Low | High | High (but wrong) |
| Risk | Low | Low | Low | Low | **High** (abstraction mismatch) |
| Scalability | High | High | Medium | High | Medium |

### Recommendation: Alternative 2B — Full Global Bitfield in JWT

**Confidence**: High (85%)

**Rationale**: Embedding 93 bits (~20 bytes) of global permissions in the JWT achieves true statelessness for 92 out of 125 permission checks at negligible token size cost. The flags-only approach (2A) is a close second — simpler, but wastes the opportunity to eliminate cache hits for global permission checks. Since we're doing a clean-break rewrite, the small added complexity of bitfield decoding in the JWT claim is a one-time implementation cost that pays off on every request.

**Key trade-offs accepted**: Global permissions in JWT are stale up to 15 minutes. Mitigated by `perm_version` check on sensitive (write) operations — for reads, eventual consistency within the TTL window is acceptable. Bitfield decoding adds ~0.1ms to middleware (negligible).

**Key assumptions**: Global permission count stays under ~200 (currently 92). Forum permission count remains unbounded, confirming they can't go in JWT.

**Why not 2A (Flags Only)?** Close runner-up. The difference is that 2A still requires a cache hit for specific global permission checks (e.g., `a_forum`), while 2B doesn't. The 20-byte cost for carrying full global perms is negligible. For teams preferring maximum simplicity, 2A is entirely viable.

---

## Decision Area 3: Token Elevation Model

### Why It Matters
Administrative and moderator actions need stronger authentication guarantees than regular API access. The elevation model determines how users prove their right to perform privileged operations, how long that proof lasts, and what happens when it expires.

### Alternative 3A: Separate Elevated Token (Step-Up Authentication)

User presents current access token + password → server issues a **separate elevated JWT** with shorter TTL, different audience, and scope claims.

**Description**: `POST /auth/elevate` with Bearer access token + password body. Server re-verifies password, checks `a_` or `m_` permission, issues an elevated JWT with `type: elevated`, `aud: phpbb-admin`, `scope: [acp|mcp]`, TTL 5 minutes. Client sends the elevated token (not the access token) for admin requests. Access token remains valid independently.

**Strengths**:
- Clean separation — elevated token can have different audience, different validation rules
- Short TTL (5 min) limits exposure window for privileged operations
- Revocation is simple — don't refresh the elevated token
- `elv_jti` claim links elevated to original access token (audit trail)
- Client can hold user token + elevated token simultaneously
- Familiar pattern (matches legacy phpBB's password re-entry for ACP)

**Weaknesses**:
- Client manages two active tokens (three including refresh)
- Elevated token expires while admin is mid-task — needs UX for re-elevation
- Password entry required even if admin just authenticated 30 seconds ago
- Single 5-min window may be too short for complex admin operations

**Best when**: Security outweighs convenience. Admin operations are infrequent enough that re-authentication is acceptable. This is the standard pattern for privilege escalation.

**Evidence**: Synthesis §P5 (legacy pattern: `session_admin=1` after password re-entry), §T2 resolution (permission-gated elevation, not group-gated). Research report §6.2, §6.3 (full elevation flow diagrams).

### Alternative 3B: Scope-Upgrade on Existing Token (Token Exchange)

Instead of a separate token, the server issues a **new access token** with additional `scope` and `elevated: true` claims, replacing the old one.

**Description**: `POST /auth/elevate` with password → server issues a new access JWT that includes all original claims plus `scope: [acp]`, `elevated: true`, with a refreshed `exp` (15 min from now, but configurable). Old access token is implicitly superseded. Client always uses the latest token.

**Strengths**:
- Client manages only one access token at a time — simpler state management
- No "which token do I send?" confusion
- Elevation doesn't require separate token management
- Token naturally downgrades when it expires (user logs in again, gets regular token)

**Weaknesses**:
- Elevated token has the same TTL as regular access token (15 min) — longer exposure
- Can't revoke elevation without revoking the entire access token
- No clean "de-elevate" flow — wait for expiry or issue new non-elevated token
- Mixing purposes (regular access + elevated) in one token complicates validation logic
- If the token is stored in an HttpOnly cookie, replacing it requires a Set-Cookie response

**Best when**: Client simplicity is paramount and the admin UX involves frequent switching between admin and regular operations.

**Evidence**: Research report §6.2 designed a separate elevated token specifically to have shorter TTL. Merging them loses this security property.

### Alternative 3C: Claim-Based Elevation with Grace Period

Access token always carries the user's permission flags. **No separate elevation** — admin routes simply require the `a_` flag in the existing JWT plus a **recent password verification timestamp**.

**Description**: Login flow always sets `flags: ["a", "m", "u", "f"]` based on the user's actual permissions. Admin endpoints check two conditions: (1) `a` in `flags`, and (2) `last_auth` claim ≤ 5 minutes ago (or a server-side `last_auth_at` timestamp check). If the `last_auth` is stale, client is redirected to re-verify password. Password re-verification updates `last_auth_at` on the user record (server-side check) rather than re-issuing a token.

**Strengths**:
- Only one token type ever exists
- No token issuance on elevation — just a password verification endpoint that updates a timestamp
- Grace period is configurable per-deployment
- Admin who just logged in can access ACP immediately (within grace period)

**Weaknesses**:
- **last_auth check requires server-side state** — either a claim that can't be updated without new token, or a DB check on every admin request
- If using a JWT claim, the 5-min grace from login means a user can access ACP for 5 min without re-entering password — weaker than explicit elevation
- Blurs the line between "authenticated" and "elevated" — every user token carries admin flags if the user has them
- Token theft gives immediate admin access if within grace period

**Best when**: Tight integration between login and admin access is desired, and the grace period tradeoff is acceptable.

**Evidence**: This approach lacks support in the research findings. Synthesis §P5 explicitly preserves the "password re-authentication for elevation" pattern. Carrying admin flags on every token increases the blast radius of token theft.

### Alternative 3D: Per-Action Confirmation (No Persistent Elevation)

No elevated token at all. Each admin action requires a **confirmation payload** (password or TOTP code) sent with the request.

**Description**: Admin endpoints accept an `X-Confirm-Password` header (or equivalent body field) on every state-changing request. Server verifies password/TOTP per-request. Read-only admin endpoints require only the `a_` flag in the JWT (no confirmation).

**Strengths**:
- Maximum security — every mutation is explicitly confirmed
- No elevated token to steal or manage
- No time window where elevation is "active"
- Matches banking/financial UX patterns

**Weaknesses**:
- **Terrible admin UX** — entering password on every action is unacceptable for bulk admin work
- Password sent with every admin request increases exposure to logging/interception
- Per-request password verification is computationally expensive (bcrypt/argon on every admin POST)
- Not viable for admin operations that involve multiple sequential requests

**Best when**: Extreme security environments where admin operations are rare single actions. Not suitable for a forum admin panel with frequent operations.

**Evidence**: No support in research findings for this pattern. Legacy phpBB elevates once per ACP session, not per action.

### Trade-Off Matrix: Token Elevation Model

| Perspective | 3A: Separate Elevated | 3B: Token Exchange | 3C: Grace Period | 3D: Per-Action |
|------------|:---:|:---:|:---:|:---:|
| Technical Feasibility | High | High | Medium | High |
| User Impact | High (familiar UX) | Medium | Medium (grace) | **Low** (terrible UX) |
| Simplicity | Medium (2 tokens) | **High** (1 token) | Medium | Low (per-request) |
| Risk | **Low** (short TTL) | Medium (longer exposure) | High (token theft) | Very Low |
| Scalability | High | High | Medium (DB check) | Low (bcrypt per req) |

### Recommendation: Alternative 3A — Separate Elevated Token

**Confidence**: High (90%)

**Rationale**: The separate elevated token preserves the UX pattern that phpBB users already understand (re-enter password to access admin), provides the shortest exposure window (5-min TTL), and cleanly separates regular and privileged operations. The added client complexity (managing two tokens) is minimal — client sends the elevated token for `/admin/*` routes and the regular token for everything else.

**Key trade-offs accepted**: Client manages multiple token types. Admin may need to re-elevate mid-task if 5-min TTL expires. Mitigated by making TTL configurable and client-side token refresh prompts.

**Key assumptions**: Admin operations occur in distinct "admin sessions" rather than constantly mixed with regular browsing. 5-min TTL is tunable per deployment.

---

## Decision Area 4: Revocation Strategy

### Why It Matters
JWT's statelessness is its strength and weakness. Once issued, a JWT is valid until expiry unless the system can detect and reject revoked tokens. The revocation strategy determines the trade-off between statelessness, security guarantees, and implementation complexity.

### Alternative 4A: Short TTL Only (Pure Stateless)

Rely entirely on short token lifetimes. No revocation mechanism beyond natural expiry.

**Description**: Access tokens live 15 minutes, elevated tokens 5 minutes. When a security event occurs (password change, ban), the affected tokens remain valid until they expire. Refresh tokens (server-side) can be revoked immediately.

**Strengths**:
- Zero per-request state checking overhead
- Simplest possible implementation
- No generation counter, no deny lists, no additional DB columns
- Refresh token revocation handles session-level logout

**Weaknesses**:
- **15-minute window** where a compromised/stale token remains valid
- Banned user can continue accessing the API for up to 15 minutes
- Password change doesn't invalidate existing access tokens
- Unacceptable for any security-sensitive deployment

**Best when**: Token TTL is very short (< 2 minutes) AND the system can tolerate the exposure window. Not advisable for a forum platform.

**Evidence**: Synthesis §T3 explicitly addresses this tension and concludes that natural expiry alone is insufficient — "password changes, bans, etc. need immediate invalidation."

### Alternative 4B: Generation Counter (Minimal State)

Each user has a `token_generation` integer. Incremented on security events. JWT carries `gen` claim. Middleware compares `jwt.gen < user.token_generation` → reject.

**Description**: Add `token_generation INT UNSIGNED DEFAULT 0` to user record. Increment on: password change, ban, forced logout, security event. Auth middleware loads the user record (already needed for most requests) and compares the generation value. Stale token → rejected.

**Strengths**:
- Per-request cost is zero additional queries (user record already loaded)
- Invalidates ALL tokens for a user in one atomic operation
- Minimal storage (1 integer per user)
- Simple to understand and implement
- Covers 99% of real-world revocation needs (password change, ban, force-logout)

**Weaknesses**:
- Invalidates ALL tokens — can't selectively revoke one device/session
- Requires loading user record on every authenticated request (but this is already needed for permission checks)
- Latency is "next request" not "immediate" (token is valid until presented)
- No per-token granularity

**Best when**: Per-user revocation granularity is sufficient (most common case). Combined with refresh token family revocation for per-device granularity.

**Evidence**: Synthesis §I4 ("Generation counter satisfies revocation requirements"). Research report §4.4 Layer 2. The key insight is that loading the user record for generation check is free — it's already loaded for permission resolution.

### Alternative 4C: Three-Layer Revocation (Natural Expiry + Generation + JTI Deny List)

Combines short TTL, generation counter, and a **cache-based JTI deny list** for immediate single-token revocation.

**Description**: Three layers work independently:
1. **Natural expiry** (15 min / 5 min) — zero state
2. **Generation counter** — per-user, piggybacks on user record load (~0 cost)
3. **JTI deny list** — cache entry `jwt_deny:{jti}` with TTL = remaining token lifetime. `O(1)` cache check. Self-cleaning (entries expire when token would have expired). Typically < 10 entries at any time.

**Strengths**:
- Complete coverage: per-token, per-user, and natural expiry
- JTI deny list is self-limiting (auto-TTL cleanup)
- Each layer independently adds value — defense in depth
- JTI layer is optional (can be enabled only when needed)
- Cache check is O(1) — adds ~0.05ms per request if using Redis

**Weaknesses**:
- JTI deny list requires a shared cache (Redis) or in-process cache
- Three mechanisms to understand, implement, and test
- JTI deny list is technically breaking "stateless" — albeit with minimal, ephemeral state
- Over-engineered if generation counter covers all real scenarios

**Best when**: The system needs different revocation speeds for different scenarios. Generation counter for routine events (password change), JTI deny list for emergency revocation (stolen device report).

**Evidence**: Synthesis §T3 proposes exactly this three-layer model. Research report §4.4 details all three layers with evidence for each. Research report §4.4 Layer 3 notes it's "rare scenario" — typically < 10 entries.

### Alternative 4D: Refresh Token Family Tracking Only

No access token revocation at all. Rely on refresh token family revocation + short access TTL.

**Description**: When a security event occurs, revoke all refresh token families for the user. Existing access tokens remain valid until expiry (max 15 min). The user can't get new access tokens without re-authenticating. No generation counter, no deny list.

**Strengths**:
- Simpler than generation counter (no JWT claim, no per-request check)
- Refresh family revocation provides per-device granularity
- Forced logout = revoke all families → user must re-login within 15 min

**Weaknesses**:
- 15-minute window where banned/compromised user still has API access
- No way to speed up invalidation for urgent security events
- Less safe than generation counter for essentially zero additional cost

**Best when**: The 15-minute exposure window is tolerable. Given that generation counter costs nothing extra (user record already loaded), this is strictly worse than 4B.

**Evidence**: Research report §4.4 Layer 1 is essentially this approach. The report explicitly layers additional mechanisms on top because natural expiry alone is insufficient.

### Trade-Off Matrix: Revocation Strategy

| Perspective | 4A: TTL Only | 4B: Generation Counter | 4C: Three-Layer | 4D: Refresh Family |
|------------|:---:|:---:|:---:|:---:|
| Technical Feasibility | High | **High** | High | High |
| User Impact | Low (15-min gap) | High | **High** | Low (15-min gap) |
| Simplicity | **High** | High | Medium | High |
| Risk | **High** (gap) | Low | **Very Low** | High (gap) |
| Scalability | High | High | High | High |

### Recommendation: Alternative 4C — Three-Layer Revocation (with Layer 3 Optional)

**Confidence**: High (85%)

**Rationale**: Layers 1 and 2 cover 99%+ of real-world scenarios at effectively zero cost. Layer 3 (JTI deny list) is a low-cost addition for the rare emergency case. Implementing all three layers during the initial build is cheaper than retrofitting Layer 3 later if it's needed. The JTI deny list can be coded but disabled by default — a configuration flag enables it.

**Key trade-offs accepted**: Three mechanisms to maintain. JTI deny list breaks pure statelessness. Mitigated by the fact that Layer 3 is optional and self-limiting.

**Key assumptions**: Redis (or equivalent shared cache) is available for JTI deny list. If no shared cache exists, Layers 1+2 are sufficient.

**Why not 4B only?** 4B is the pragmatic minimum and perfectly viable. 4C adds Layer 3 as insurance — the implementation is ~20 lines of code and ~0 runtime cost when unused.

---

## Decision Area 5: Key Management

### Why It Matters
The JWT signing key is the single secret that, if compromised, allows forging any token. Key management determines the security ceiling of the entire authentication system. It also affects whether the system can rotate keys without downtime and whether future multi-service architectures are supported.

### Alternative 5A: HS256 with Derived Key (Symmetric, Single Key)

Single HMAC key derived from a master secret in `config.php`. HS256 algorithm. Key ID (`kid`) in JWT header for rotation support.

**Description**: Master secret = 64-char hex string in `config.php` (generated at install). Signing key = `hash_hmac('sha256', 'jwt-access-v1', $masterSecret)`. Key derivation with purpose salt prevents key reuse across different token types. `kid` header enables rotation: new key derived with `jwt-access-v2` suffix, old key accepted for verification for a transition period.

**Strengths**:
- **Fastest signing/verification** — HMAC is ~10x faster than RSA
- Single secret to manage — stored in `config.php`
- Key derivation from master secret means token-type-specific keys without separate secrets
- `kid` header enables zero-downtime key rotation
- `firebase/php-jwt` prevents algorithm confusion by design

**Weaknesses**:
- Verifier must know the secret — can't distribute a public key to other services
- Single point of compromise — master secret leak = forge any token
- Key is in a PHP file — environment variable would be more secure for production
- No crypto-separation between signer and verifier

**Best when**: Single application deployment (PHP monolith). No need for external services to verify tokens. This is the current and foreseeable architecture.

**Evidence**: Synthesis §6 (secondary conclusion: "HS256 is the right algorithm choice for a PHP monolith"). Research report §R2 recommends HS256 for Phase 1. `firebase/php-jwt` Key class binds algorithm — mitigates algorithm confusion.

### Alternative 5B: RS256 with Key Pair (Asymmetric)

RSA-2048 key pair. Private key signs tokens (server only). Public key verifies tokens (can be distributed).

**Description**: Generate RSA-2048 key pair. Private key stored in `config/jwt-private.pem` (chmod 600). Public key in `config/jwt-public.pem` (can be published). JWT `kid` header for rotation. JWKS endpoint (`/.well-known/jwks.json`) publishes public keys.

**Strengths**:
- **Verification without the secret** — other services only need the public key
- Key compromise of public key is harmless
- Supports future JWKS endpoint for multi-service architecture
- Industry standard for OAuth2/OIDC providers

**Weaknesses**:
- Slower: RSA-2048 signing ~1ms, verification ~0.1ms (vs ~0.01ms for HMAC)
- Two files to manage and protect (private + public key)
- Key rotation is more complex (generate new pair, publish new public key, wait for propagation)
- Over-engineered for a PHP monolith where signer = verifier
- PEM file management adds operational complexity

**Best when**: Multiple services need to verify tokens independently, or the system will act as an OAuth2 provider for external applications.

**Evidence**: Research report §R2 mentions RS256 as a future option: "Switch to RS256 only if multi-service verification becomes necessary." Current architecture is monolithic — RS256's distribution benefit isn't needed.

### Alternative 5C: EdDSA with Ed25519 (Modern Asymmetric)

Ed25519 key pair. Faster than RSA with smaller keys.

**Description**: Generate Ed25519 key pair (32-byte private, 32-byte public). Same distribution model as 5B but with faster operations and smaller signatures. Requires `sodium` PHP extension (available since PHP 7.2).

**Strengths**:
- Fastest asymmetric algorithm available
- Smallest key sizes (32 bytes vs 256+ bytes for RSA)
- Resistant to timing attacks by design
- Modern and future-proof

**Weaknesses**:
- `firebase/php-jwt` supports EdDSA but it's less commonly used
- Requires `sodium` extension (available but may not be in all PHP builds)
- Same operational complexity as RSA (key pair management)
- Still unnecessary for a monolith — symmetric is simpler
- Less community familiarity — debugging JWT issues is harder

**Best when**: Multi-service architecture with performance sensitivity. A future "Phase 3" option if the system evolves to distributed services.

**Evidence**: No direct support in research findings — EdDSA wasn't analyzed. Included for completeness as it's the modern alternative to RSA.

### Alternative 5D: HS256 with Environment Variable Key (Symmetric, External Secret)

Same as 5A but the master secret comes from an **environment variable** instead of `config.php`.

**Description**: `JWT_SECRET` environment variable (64-hex string). PHP reads `$_ENV['JWT_SECRET']` or `getenv('JWT_SECRET')`. No secret in the codebase or config files. Key derivation same as 5A.

**Strengths**:
- Secret never touches the filesystem (can't be leaked via file read vulnerability)
- Compatible with Docker/K8s secret management
- Standard 12-factor app practice
- Easy rotation via deployment restart

**Weaknesses**:
- Requires environment configuration on every deployment
- `phpinfo()` or debug pages can leak environment variables
- More complex local development setup
- phpBB historically uses `config.php` for secrets — different convention

**Best when**: Containerized deployments, CI/CD pipelines, or environments with file-read vulnerability concerns.

**Evidence**: Research report §A6 states the key is "stored securely in config.php (not in DB, not in version control)." Environment variables are an alternative secure storage mechanism. Since this is a clean-break rewrite, convention isn't binding.

### Trade-Off Matrix: Key Management

| Perspective | 5A: HS256 config.php | 5B: RS256 Key Pair | 5C: EdDSA | 5D: HS256 env var |
|------------|:---:|:---:|:---:|:---:|
| Technical Feasibility | **High** | High | Medium | High |
| User Impact | Neutral | Neutral | Neutral | Neutral |
| Simplicity | **High** | Medium | Medium | High |
| Risk | Low | Low | Medium (less tested) | Low |
| Scalability | Medium (no distribution) | **High** (public key) | **High** | Medium |

### Recommendation: Alternative 5A — HS256 with Derived Key (with 5D as Production Variant)

**Confidence**: High (90%)

**Rationale**: HS256 is the right choice for a PHP monolith. It's fastest, simplest, and `firebase/php-jwt` mitigates algorithm confusion by design. Key derivation with purpose salts provides key separation without managing multiple secrets. The `kid` header enables rotation.

For production deployments, the secret source should be configurable: `config.php` for simple setups, environment variable for containerized deployments. This is a configuration concern, not an architectural one.

**Key trade-offs accepted**: No public key distribution to external services. Mitigated by the fact that the current architecture is monolithic. If multi-service verification is needed later, migration to RS256 or EdDSA is straightforward (issue new tokens with new algorithm, old tokens expire within 15 minutes).

**Key assumptions**: Architecture remains monolithic for the near term. If multi-service is planned within 6 months, start with RS256 (5B) instead.

---

## Decision Area 6: Auth Service Interface Design

### Why It Matters
The auth service is consumed by every other service in the system. Its public interface determines coupling, testability, and how authentication/authorization concerns are distributed across the codebase. A well-designed interface makes auth invisible to most developers; a poorly designed one makes every controller aware of token internals.

### Alternative 6A: Three-Interface Facade (AuthN + AuthZ + Token)

Three separate interfaces — `AuthenticationServiceInterface`, `AuthorizationServiceInterface`, `TokenServiceInterface` — each with a focused responsibility. A thin facade (`AuthService`) optionally wraps them for convenience.

**Description**:
- `AuthenticationServiceInterface`: `login()`, `logout()`, `logoutAll()`, `elevate()`, `refresh()`
- `AuthorizationServiceInterface`: `isGranted()`, `isGrantedAny()`, `getGrantedForums()`, `isGrantedInAnyForum()`, `loadPermissions()`
- `TokenServiceInterface`: `issueAccessToken()`, `issueElevatedToken()`, `verify()`, `decode()` (internal, not typically used by other services)
- Symfony DI injects the specific interface each consumer needs
- Subscribers (`AuthenticationSubscriber`, `AuthorizationSubscriber`) use the internal service interfaces

**Strengths**:
- **Interface Segregation Principle** — consumers depend only on what they use
- `AuthorizationService` is independently testable and mockable
- Token internals hidden from most consumers (they interact via AuthZ)
- Clear ownership boundaries
- Symfony DI autowiring works naturally with typed interfaces

**Weaknesses**:
- Three interfaces to maintain and document
- Some consumers need both AuthN and AuthZ (rare — usually just the subscribers)
- Slightly more Symfony DI configuration

**Best when**: The system has many services with diverse auth needs. Controllers typically only need `AuthorizationServiceInterface`. Token management is infrastructure-level.

**Evidence**: Research report §R1 proposes exactly this structure. Synthesis §C3 confirms the unified service owns both AuthN and AuthZ, but §P1 (layered security gates) shows they are distinct concerns within that unified ownership.

### Alternative 6B: Single Unified Interface

One `AuthServiceInterface` exposing all auth operations. Single injection point for all consumers.

**Description**: `AuthServiceInterface` with methods: `login()`, `logout()`, `elevate()`, `refresh()`, `isGranted()`, `isGrantedAny()`, `getGrantedForums()`, `loadPermissions()`. All consumers inject one interface. Token operations are internal (not on the public interface).

**Strengths**:
- One interface to learn, inject, and mock
- Simple DI configuration
- Easy to discover available auth operations
- Less code overall

**Weaknesses**:
- **Violates Interface Segregation** — a controller that only checks permissions depends on an interface with login/logout methods
- Large interface surface (10+ methods) — harder to mock in tests
- Changes to authentication flow affect the interface that authorization consumers depend on
- No compile-time guarantee that a consumer only uses what it should

**Best when**: Small team, few services, and simplicity outweighs separation of concerns.

**Evidence**: The user requirement says "unified service" — this could be interpreted as unified ownership (one team/package) rather than unified interface. Research report §R1 chose to split interfaces despite unified ownership.

### Alternative 6C: Middleware-Only (No Injected Service)

Authentication and authorization happen entirely in HTTP middleware (subscribers). Controllers receive the authenticated user and granted permissions as **request attributes**. No auth service is injected into controllers.

**Description**: `AuthenticationSubscriber` (priority 16) sets `_api_user`. `AuthorizationSubscriber` (priority 8) checks route-level permissions. Controllers read `$request->attributes->get('_api_user')` and assume they're authorized. For fine-grained checks, controllers use a lightweight `PermissionChecker` that reads from the already-loaded in-memory bitfield.

**Strengths**:
- Controllers have zero dependency on auth services
- Auth is truly cross-cutting — lives entirely in middleware pipeline
- Simpler controller constructors (no injected auth service)
- Route-level permission declarations (`_api_permission`) are declarative and auditable

**Weaknesses**:
- **Fine-grained permission checks inside controllers become awkward** — need some way to call `isGranted()` from a controller
- Route-level permissions can't express dynamic conditions (e.g., "can edit this post" depends on post ownership)
- Loses the ability to do conditional authorization flows in business logic
- `PermissionChecker` becomes a shadow auth service anyway

**Best when**: All authorization can be expressed at the route level. True for simple CRUD, but forum systems need dynamic permission checks (e.g., "can edit own post within edit window").

**Evidence**: Research report §7.3 uses the subscriber model for route-level checks but explicitly provides `AuthorizationService::isGranted()` for "fine-grained checks via AuthorizationService" at the controller level (priority 0). Pure middleware can't replace this.

### Alternative 6D: Event-Driven Auth (Authority via Events)

Auth operations are triggered via **events** dispatched through Symfony EventDispatcher. Other services dispatch `CheckPermissionEvent`, `AuthenticateEvent`, etc. Auth service listens and responds.

**Description**: `CheckPermissionEvent` dispatched with user, permission, forumId. Auth subscriber checks and sets `$event->setGranted(true/false)`. Controllers dispatch events instead of calling services. Authentication also event-based.

**Strengths**:
- Fully decoupled — services don't import auth interfaces
- Extensible — other listeners can modify auth decisions
- Consistent with Symfony's event-driven architecture

**Weaknesses**:
- **Performance overhead** — event dispatch for every permission check is slower than direct method call
- Debugging is harder — "why was this denied?" requires tracing event listeners
- Return values via event mutation is awkward and error-prone
- High-frequency operations (permission checks, 5-10x per request) shouldn't use event dispatch
- Over-abstraction for a direct yes/no question

**Best when**: Auth decisions need to be modified by plugins/extensions at runtime. Not suitable as the primary authorization mechanism for high-frequency checks.

**Evidence**: The user specifies Symfony EventDispatcher, but for auth *events* (login success, token revoked), not for auth *checks*. Research report §7.3 uses direct `isGranted()` calls for permission checks and event subscribers only for request lifecycle hooks.

### Trade-Off Matrix: Auth Service Interface Design

| Perspective | 6A: Three Interfaces | 6B: Single Interface | 6C: Middleware-Only | 6D: Event-Driven |
|------------|:---:|:---:|:---:|:---:|
| Technical Feasibility | **High** | **High** | Medium | High |
| User Impact | High (clean APIs) | High (simple) | Medium | Medium |
| Simplicity | Medium | **High** | Medium | Low |
| Risk | Low | Low | Medium (shadow svc) | Medium (perf) |
| Scalability | **High** | Medium | Medium | Low (event overhead) |

### Recommendation: Alternative 6A — Three-Interface Facade

**Confidence**: High (85%)

**Rationale**: The three-interface design matches the natural responsibility boundaries: most consumers only need `AuthorizationServiceInterface` (controllers doing permission checks), a few need `AuthenticationServiceInterface` (login/logout endpoints), and `TokenServiceInterface` is internal infrastructure. Symfony DI autowiring makes this zero-cost to configure. Mocking in tests is cleaner — mock only the interface you're testing against.

**Key trade-offs accepted**: Three interfaces to maintain. Mitigated by the fact that they're stable abstractions — the methods are well-defined and unlikely to change frequently.

**Key assumptions**: Symfony DI autowiring is available. Most services interact only with `AuthorizationServiceInterface`.

**Why not 6B?** Single interface is simpler but creates unnecessary coupling. A thread controller that only calls `isGranted()` shouldn't depend on `login()` and `elevate()` signatures. For a clean-break rewrite, starting with clean interfaces is cheap.

---

## Consolidated Trade-Off Analysis

### 5-Perspective Summary Across All Recommendations

| Decision Area | Recommendation | Tech Feasibility | User Impact | Simplicity | Risk | Scalability |
|--------------|----------------|:-:|:-:|:-:|:-:|:-:|
| Token Architecture | Monolithic Token Pair | High | High | High | Low | High |
| Permission Embedding | Full Global Bitfield in JWT | High | High | Medium | Low | High |
| Token Elevation | Separate Elevated Token | High | High | Medium | Low | High |
| Revocation Strategy | Three-Layer (Gen Counter + optional JTI) | High | High | Medium | Very Low | High |
| Key Management | HS256 Derived Key | High | Neutral | High | Low | Medium |
| Service Interface | Three-Interface Facade | High | High | Medium | Low | High |

### Cross-Cutting Observations

1. **Simplicity vs Separation**: Alternatives 1A/5A/6B favor maximum simplicity. Alternatives 2B/3A/4C/6A favor clean separation. The recommendations lean toward separation where the cost is low (6A) and simplicity where complexity doesn't pay off (1A, 5A).

2. **Stateless vs Practical**: Pure statelessness (1C, 4A) sacrifices security properties for theoretical purity. The recommended hybrid approach (JWT for identity, server-side bitfield for permissions, generation counter for revocation) is pragmatically stateless — the server state that exists (user record, bitfield cache, refresh tokens) would be loaded anyway for non-auth purposes.

3. **Current vs Future**: Every recommendation is optimized for the current monolithic architecture but includes an upgrade path. HS256→RS256, bitfield→distributed cache, three interfaces→microservice boundaries. No premature optimization for multi-service.

---

## User Preferences

From accumulated context and user decisions:

1. **Stateless JWT** — non-negotiable. firebase/php-jwt library.
2. **Two token types** — user token + elevated token (originally "group token", reinterpreted).
3. **Unified auth service** — single package owns both AuthN and AuthZ.
4. **PHP 8.2+, PSR-4, Symfony DI + EventDispatcher** — modern PHP stack.
5. **Clean break from legacy** — no backward compatibility constraints.
6. **Bitfield concept preserved** — proven O(1) performance retained, clean reimplementation.

All recommendations align with these preferences.

---

## Recommended Approach (Summary)

**Architecture**: Monolithic JWT access token (15-min TTL) with full global permission bitfield embedded (~20 bytes, 92 permissions). Opaque refresh tokens with family-based rotation (server-side). Separate elevated JWT (5-min TTL) for admin/moderator operations issued after password re-verification. HS256 signing with derived key from config secret. Three-layer revocation (natural expiry + generation counter + optional JTI deny list). Three-interface service design (AuthN, AuthZ, Token).

**Key innovation over legacy**: The bitfield concept is preserved and reused, but identity verification is stateless (JWT signature vs session DB lookup). Permission data travels via server-side cache, not in the token — the JWT carries just enough to enable routing decisions (flags/bitfield for global perms) and freshness checks (`perm_version`).

**Confidence**: High (85% overall). Highest confidence on token architecture and elevation model. Slightly lower on permission embedding (2B vs 2A is a close call) and key management (depends on deployment model).

---

## Why Not Others

| Rejected Alternative | Reason |
|---------------------|--------|
| Split Token Architecture (1B) | Over-engineered for a monolith. The JWT + bitfield split already achieves identity/authorization separation. |
| Fully Stateless Refresh (1C) | Eliminates theft detection (family tracking). Security sacrifice not justified. |
| Opaque Sessions (1D) | Contradicts user requirement for stateless JWT. |
| Flags-Only in JWT (2A) | Close runner-up. Viable but misses the chance to eliminate cache hits for 92 global permissions at ~20 bytes cost. |
| Permission Hash (2C) | Strictly worse than `perm_version` — same detection, more computation. |
| Minimal Claims (2D) | Loses quick-gate routing optimization. No practical benefit over 2A/2B. |
| Role-Based Claims (2E) | phpBB permissions are capability-based, not role-based. Roles would be a lossy abstraction. |
| Token Exchange (3B) | Elevated access at regular token TTL (15 min) is too long. Loses the short-exposure benefit. |
| Grace Period (3C) | Weakens security — token theft within grace period gives immediate admin access. |
| Per-Action Confirmation (3D) | Unusable admin UX. Bcrypt per admin request is wasteful. |
| TTL-Only Revocation (4A) | 15-minute revocation gap is unacceptable for bans and password changes. |
| Refresh Family Only (4D) | Strictly worse than 4B — generation counter costs nothing extra. |
| RS256 / EdDSA (5B/5C) | Unnecessary for monolith. Public key distribution benefit isn't needed yet. |
| Single Interface (6B) | Violates ISP. Controllers shouldn't depend on login/logout signatures. |
| Middleware-Only (6C) | Can't express dynamic permission checks (post ownership, edit windows). |
| Event-Driven Auth (6D) | Event dispatch overhead on high-frequency permission checks. Over-abstraction. |

---

## Deferred Ideas

| Idea | Scope | Rationale for Deferral |
|------|-------|----------------------|
| OAuth2/OIDC provider mode | Out-of-scope | phpBB as identity provider for external apps — Phase 3+ feature |
| Scoped API tokens (non-JWT) | Stretch | Limited-permission tokens for integrations — not needed for primary auth |
| WebAuthn / Passkeys | Out-of-scope | Passwordless auth provider — Phase 2 extensibility via `AuthProviderInterface` |
| Session binding (TLS/device fingerprint) | Stretch | Additional theft resistance — evaluate after core auth is stable |
| Adaptive / risk-based authentication | Out-of-scope | Step-up based on IP/device anomaly — requires ML/heuristic infrastructure |
| LDAP / OAuth2 consumer providers | Out-of-scope (Phase 2) | Enterprise auth sources — architected via `AuthProviderInterface` extensibility |
| Admin token management UI | Stretch | Allow admins to view/revoke user sessions — depends on refresh token families being implemented |
| Remember-me with long-lived refresh | Stretch | 30-day refresh token — same mechanism, just config change to TTL |
| Rate limiting as separate service | Out-of-scope | Currently login-attempts owned by Auth — could be extracted if rate limiting applies more broadly |
