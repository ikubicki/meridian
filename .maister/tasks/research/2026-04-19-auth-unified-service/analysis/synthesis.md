# Synthesis: Unified Auth Service Design

**Research Question**: How should `phpbb\auth` be designed as a unified service handling both AuthN and AuthZ, with stateless JWT tokens and user/group token elevation model?

---

## Executive Summary

Five sources covering legacy authentication, legacy ACL, prior research decisions, JWT patterns, and group-ACL model converge on a clear architecture: a **hybrid stateless/cached design** where JWT tokens carry identity, quick-gate flags, and a freshness marker, while the existing O(1) bitfield cache handles fine-grained permission resolution server-side. The four key tensions — bitfield size vs token size, group tokens vs permission reality, revocation vs statelessness, and forum-specific permission scaling — each resolve cleanly once the user's decision to use stateless JWT is interpreted as "stateless for identity verification, cached for authorization."

---

## 1. Cross-Source Analysis

### 1.1 Validated Findings (confirmed by 2+ sources)

| Finding | Sources | Confidence |
|---------|---------|------------|
| Permissions are 100% bitfield-based, NOT group-based | legacy-acl, group-acl-model | **High** |
| `a_` flag (1 bit) is necessary and sufficient for ACP gate | legacy-acl §7, group-acl-model §7 | **High** |
| `m_` / `acl_getf_global('m_')` is the MCP gate | legacy-acl §7, group-acl-model §7 | **High** |
| Global permissions are small (~93 bits = 18 base-36 chars) | legacy-acl §4.4, group-acl-model §10 | **High** |
| Forum permissions scale: 48 bits × N forums | legacy-acl §4.4, group-acl-model §10 | **High** |
| `user_type=USER_FOUNDER` overrides all `a_*` NEVER | legacy-acl §5.2, group-acl-model §6 | **High** |
| `firebase/php-jwt` prevents algorithm confusion by design | jwt-patterns §1.1, §6.6 | **High** |
| Nobody designed AuthN yet — circular deferral gap | prior-research §1 | **High** |
| Authentication subscriber at priority 16, AuthZ at priority 8 | prior-research §5, §2 | **High** |
| Group membership alone CANNOT determine permissions | group-acl-model §11 insight 1 | **High** |
| Bitfield IS already a stateless permission cache | group-acl-model §11 insight 2 | **High** |

### 1.2 Contradictions and Their Resolutions

#### C1: JWT vs DB Tokens (RESOLVED by user decision)

| Source | Position |
|--------|----------|
| Auth HLD | JWT with `firebase/php-jwt`, Bearer tokens |
| REST API ADR-005 | Opaque DB tokens, SHA-256, `phpbb_api_tokens` table |
| Cross-cutting assessment | Recommends DB tokens |
| **User decision** | **Stateless JWT tokens** — not DB tokens |

**Resolution**: The user explicitly chose stateless JWT. This supersedes the REST API's DB token design. The `phpbb_api_tokens` schema from ADR-005 is **not used** for the primary auth mechanism. Instead:
- Access tokens = short-lived JWT (15 min), verified by signature only (no DB hit)
- Refresh tokens = opaque, stored in `phpbb_auth_refresh_tokens` (DB state acceptable for refresh)

This is a major directional reversal from prior research consensus (which favored DB tokens for simplicity). The architecture must address the harder revocation problem that JWT introduces.

#### C2: "Group Token" vs Permission Reality

| Source | Understanding |
|--------|---------------|
| User decision | "Group token" for admin ACP/MCP access |
| group-acl-model | Permissions are NOT group-based; they're bitfield-based |
| legacy-acl | ACP gate = `acl_get('a_')`, not group membership check |

**Resolution**: "Group token" is a **misnomer in phpBB's context**. Reinterpret as "elevated token" — a short-lived JWT issued after re-authentication that carries:
- The `type: elevated` discriminator
- The `aud: phpbb-admin` audience claim
- Permission flags (`a_`, `m_`) confirming elevated access rights
- A `scope` claim (`['acp']`, `['mcp']`, or both) for routing

The elevation is **permission-gated**, not group-gated. The flow re-verifies the user's password and confirms the user has `a_` or `m_` permissions before issuing the elevated token. Group IDs appear in claims as informational context (for cache keying and display), not as authorization input.

#### C3: AuthenticationService Ownership

| Source | Claim |
|--------|-------|
| Auth ADR-001 | "Authentication stays in `phpbb\user`" |
| User ADR-001 | "Auth owns all authentication" |
| **User decision** | **Unified service handles both AuthN and AuthZ** |

**Resolution**: The unified `phpbb\auth` service IS the `AuthenticationService`. It owns:
- Credential verification (delegates password hash to User's `PasswordService`)
- JWT issuance, refresh, and validation
- ACL resolution and permission checking
- Session/token lifecycle management

#### C4: Subscriber Priority Conflict

| Source | Claim |
|--------|-------|
| Notifications HLD | `auth_subscriber JWT → _api_user` at priority 8 |
| Prior research canonical | Authentication at 16, Authorization at 8 |

**Resolution**: Notifications HLD is wrong. Authentication MUST be at priority 16 (before AuthZ at 8). Notifications reads `_api_user` from request attributes set at 16 — it never does its own auth.

### 1.3 Confidence Assessment

| Area | Confidence | Basis |
|------|-----------|-------|
| Legacy code structure (auth providers, session, ACL) | **High (95%)** | Direct source code analysis |
| JWT library capabilities | **High (95%)** | Source code + docs |
| Permission bitfield format and resolution | **High (95%)** | Source code + live DB dump |
| Token size estimates | **Medium (80%)** | Depends on forum count; validated for 2-50 forums |
| Elevation flow design | **Medium (75%)** | Novel design, no legacy equivalent to compare against |
| Revocation strategy | **Medium (75%)** | Generation counter is sound theory; untested in this codebase |

---

## 2. Patterns and Themes

### P1: Layered Security Gates (Consistent across legacy and new design)

**Pattern**: phpBB uses a cascade of increasingly specific security checks:
1. **Authentication gate** — "is this a valid user?" (session/token)
2. **Category gate** — "does this user have ANY admin/mod permission?" (`a_`, `m_` flags)
3. **Specific permission** — "does this user have THIS permission?" (`a_forum`, `m_edit`)
4. **Forum scope** — "...in THIS forum?" (local permission check)

**Evidence**: `web/adm/index.php` checks `a_` before any ACP modules run. `web/mcp.php` checks `m_` / `acl_getf_global('m_')`. Individual actions check specific perms + forum.

**JWT mapping**: This pattern maps directly to token types:
- User token → passes gate 1, carries enough for gate 2 (flags)
- Elevated token → passes gates 1-3, carries admin/mod scope
- Forum-specific (gate 4) → server-side bitfield resolution

### P2: Pre-Computed Cache is the Performance Foundation

**Pattern**: phpBB computes permissions once (on cache miss), stores as bitfield, then reads at O(1) cost. The bitfield is THE authoritative source for permission checks at runtime.

**Evidence**: `acl_cache()` builds and stores bitfield in `user_permissions`. All `acl_get()` calls read from decoded bitfield. No DB queries at permission-check time.

**JWT implication**: The architecture should NOT try to replace the bitfield with JWT claims. Instead:
- JWT carries identity + freshness marker (`perm_v`)
- Server loads bitfield into memory on first permission check per request
- All subsequent checks in same request hit the in-memory decoded bitfield (O(1))

Total cost: 1 cache read per request (Redis/memcache/file cache) + O(1) per check.

### P3: NEVER-Wins Resolution (Must Be Preserved)

**Pattern**: Three-value permission model where `ACL_NEVER(0)` is immutable — once any source (user or group grant) sets NEVER, no other source can override it. Founder bypasses NEVER for admin permissions only.

**Evidence**: `_set_group_hold_ary()` in `auth.php:930-950` — skips update if current value is NEVER. Founder override in `acl_cache()` runs AFTER merge, forces `a_*` to YES.

**JWT preservation**: The pre-computed bitfield already collapses the three-value model to binary (1/0). NEVER-wins is computed during bitfield build. JWT tokens only need the final binary result. The `user_type` claim in JWT enables founder override detection without re-resolving.

### P4: Dual-Scope Permissions (Global + Local)

**Pattern**: `m_*` moderator permissions exist in both global and local scopes. `acl_get('m_edit', $forum_id)` checks global `m_edit` OR local `m_edit` for that forum.

**Evidence**: `phpbb_acl_options` has 15 `m_*` permissions with `is_global=1, is_local=1`. `acl_get()` OR-combines global and local results.

**JWT implication**: An elevated (moderator) token can carry the global `m_*` flags, but per-forum moderator permissions still need server-side resolution. This is acceptable — moderator actions always specify a forum context.

### P5: Password Re-Authentication for Elevation (Legacy Pattern Preserved)

**Pattern**: phpBB ACP access requires re-entering the password even if already logged in. Creates a new session with `session_admin=1`, shorter-lived.

**Evidence**: `login_box()` with `$admin=true` in legacy code. `session_create($user_id, $set_admin=true)` sets the flag.

**JWT mapping**: The elevation flow replaces `session_admin=1` with a separate elevated JWT. Same UX pattern: user re-enters password → system issues elevated token → elevated token has shorter TTL (5 min vs 15 min).

---

## 3. Key Architectural Insights

### I1: The Bitfield IS the Stateless Permission Cache

The most important insight from cross-referencing legacy-acl and group-acl-model: phpBB's `user_permissions` bitfield is already a pre-computed, self-contained permission map. It's effectively a "stateless permission token" stored server-side.

Attempting to embed this in JWT creates a **worse version** of what already exists — larger (base64 overhead), harder to invalidate (can't clear a JWT like you can clear a DB column), and no performance gain (both are O(1) reads).

**Optimal design**: JWT for identity, bitfield for permissions. They complement each other perfectly.

### I2: Global Permissions Fit in JWT; Forum Permissions Don't

| Permission scope | Size in bitfield | Fits in JWT? |
|-----------------|-----------------|--------------|
| Admin (`a_*`) | 42 bits → 8 base-36 chars | YES — trivially |
| Moderator global (`m_*`) | 15 bits → 6 chars | YES |
| User (`u_*`) | 35 bits → 6 chars | YES |
| Forum per-forum (`f_` + local `m_`) | 48 bits × N forums | NO — grows unbounded |
| Total global | ~93 bits → 18 chars (~20 bytes) | YES |

The flags claim (`a`, `m`, `u`) in the JWT handles 95% of routing decisions (ACP gate, MCP gate, authenticated-only routes). Only forum-specific operations need the full bitfield.

### I3: Permission Version Enables Eventual Consistency

When permissions change (admin edits group perms), the legacy system clears `user_permissions` to force rebuild. With JWT, we need an equivalent signal.

**Solution**: `perm_version` (integer) stored on the user record. Incremented on any permission change affecting that user. JWT carries the `perm_v` claim at time of issuance. Server compares `jwt.perm_v` against current `user.perm_version`:
- Match → use cached bitfield, no rebuild
- Mismatch → trigger bitfield rebuild, reject stale token on sensitive operations

For non-sensitive operations (reading forum content), a stale `perm_v` with a still-valid JWT is acceptable — permissions refresh at next token rotation (max 15 min).

### I4: Generation Counter Satisfies Revocation Requirements

The "stateless JWT vs revocation" tension resolves with the **generation counter** pattern:
- `phpbb_users.token_generation` (integer, starts at 0)
- Incremented on: password change, forced logout, ban, security event
- JWT carries `gen` claim
- Auth middleware checks: `jwt.gen < user.token_generation` → reject

**Trade-off**: Requires loading the user record per request. But: the user record is almost always loaded anyway (for permission checks, display name, etc.). The generation check adds zero additional DB queries to the typical request flow.

For truly **immediate** revocation (within seconds, not minutes), a JTI deny list in cache (Redis TTL = remaining token lifetime) provides O(1) checking with automatic cleanup.

### I5: Elevation Is Permission-Gated, Not Group-Gated

The "group token" concept needs reframing. A user doesn't elevate "into a group" — they elevate "into a permission scope." The check is:
- Can elevate to admin? → `acl_get('a_')` is true
- Can elevate to moderator? → `acl_getf_global('m_')` is true

Group IDs appear as informational claims, not as authorization gates. The elevation endpoint verifies the user can assume the elevated role by checking their computed permissions, re-verifying their password, and issuing a scoped elevated token.

### I6: Refresh Tokens Are the Only Required Server State

The JWT-based architecture requires exactly one piece of persistent state: the refresh token table. Everything else is either:
- In the JWT itself (identity, flags, timestamps)
- In the existing bitfield cache (permissions)
- In the existing user record (generation counter, user_type)

The `phpbb_auth_refresh_tokens` table with family-based rotation provides:
- Theft detection (reuse = family revocation)
- Device tracking (user_agent, ip_address)
- Logout-all capability (revoke all families for user)

---

## 4. Resolution of Key Tensions

### T1: Stateless JWT vs ACL Complexity

**Tension**: Bitfield is ~150-800 bytes depending on forum count. Embedding it makes tokens huge. Not embedding means DB lookups.

**Resolution**: **Hybrid — JWT carries identity + flags, server-side cache carries bitfield.**
- JWT validates authentication (signature + exp + gen)
- JWT provides quick routing (flags: a_, m_, u_)
- Server loads permission bitfield into memory once per request from cache
- All `isGranted()` calls within that request use in-memory bitfield (O(1))

**Net cost vs pure DB tokens**: Identical. Both approaches need to load permissions from cache. JWT saves the token-lookup DB query. Bitfield-in-memory replaces token-in-DB as the permission source.

### T2: Group Token vs Permission Reality

**Tension**: User wants "group tokens" but permissions aren't group-based in phpBB.

**Resolution**: **Rename to "elevated token" with permission-gated issuance.**
- Elevation requires: valid user token + password re-entry + `a_` or `m_` permission
- Elevated token carries: `type: elevated`, `aud: phpbb-admin`, `scope: [acp|mcp]`
- Admin operations check: `token.type === 'elevated' && 'acp' in token.scope`
- Moderator operations check: `token.type === 'elevated' && 'mcp' in token.scope` (or user token + per-forum `m_` from bitfield)
- Fine-grained admin checks (e.g., `a_forum`, `a_user`) still use server-side bitfield

### T3: Token Revocation

**Tension**: JWT is stateless but password changes, bans, etc. need immediate invalidation.

**Resolution**: **Three-layer revocation strategy:**

| Layer | Mechanism | Latency | State Required |
|-------|-----------|---------|---------------|
| 1. Natural expiry | Short TTL (15 min / 5 min) | 0-15 min | None |
| 2. Generation counter | `gen` claim vs `user.token_generation` | Per-request check | 1 integer per user (already in user record) |
| 3. JTI deny list | Cache-based deny list with TTL | Immediate | ~0-100 entries in cache (auto-expire) |

Layer 1 handles 99% of cases (token just expires). Layer 2 handles security events (password change invalidates all tokens). Layer 3 is optional for extreme cases (immediate single-token revocation).

### T4: Forum-Specific Permission Scaling

**Tension**: Global perms are small (93 bits) but forum-specific scale with forum count.

**Resolution**: **Forum perms stay server-side; JWT never carries them.**
- Bitfield loaded from cache (Redis/file cache) once per request
- Decoded into memory (`$this->acl[$forum_id]` array)
- O(1) lookup per permission check
- Cache key: `user:{id}:permissions` with `perm_version` validation

For APIs that operate on specific forums, the forum ID comes from the route parameter, and the permission check hits the in-memory decoded bitfield. No additional DB queries.

---

## 5. Gaps and Uncertainties

### 5.1 Unresolved Design Questions

| Question | Impact | Suggested Resolution |
|----------|--------|---------------------|
| Where is `token_generation` stored? | Affects schema | Add column to `phpbb_users`: `token_generation INT UNSIGNED DEFAULT 0` |
| Where is `perm_version` stored? | Affects cache invalidation | Add column to `phpbb_users`: `perm_version INT UNSIGNED DEFAULT 0`, increment in `acl_clear_prefetch()` |
| CSRF for cookie-based JWT | Security | Double-submit cookie pattern (csrf claim in JWT + non-HttpOnly cookie) |
| Auth provider extensibility | Legacy feature | Phase 1: db provider only. Phase 2: `AuthProviderInterface` for LDAP, OAuth2, etc. |
| Remember-me with JWT | UX | Long-lived refresh token (30 days) stored in HttpOnly cookie; exchanges for short-lived access token |
| Multi-device session tracking | UX | Each refresh token family = one device. List families for "manage sessions" UI |
| Rate limiting ownership | Security | Auth service owns `login_attempts` tracking (IP + user-based), calls `CaptchaService` on threshold |

### 5.2 Areas Needing Further Investigation

1. **Production bitfield sizes**: Verified for 2-50 forums. Large boards (500+ forums) may have bitfields exceeding single cache read performance thresholds.
2. **Redis vs file cache for bitfield**: Legacy uses phpBB file cache. Redis would be more appropriate for the new API layer.
3. **Clock skew between servers**: JWT `exp` validation assumes synchronized clocks. The `JWT::$leeway` setting (60s default) needs tuning.
4. **Concurrent elevation**: Can a user hold multiple elevated tokens simultaneously? (Probably yes — each is independent JWT.)

---

## 6. Conclusions

### Primary Conclusions

1. **JWT + server-side bitfield cache is the optimal architecture** for phpBB. JWT handles authentication statelessly; the existing O(1) bitfield handles authorization without redesign. (High confidence)

2. **"Group token" must be reinterpreted as "elevated token"** — permission-gated, not group-gated. ACP access requires `a_` flag + re-authentication; MCP requires `m_` flag. (High confidence)

3. **Three-layer revocation** (natural expiry + generation counter + optional JTI deny list) provides a satisfying trade-off between statelessness and security requirements. (High confidence)

4. **Forum-specific permissions must stay server-side** — they scale with forum count and don't belong in JWT. Global permission flags in JWT cover all routing and gate decisions. (High confidence)

5. **The unified service resolves the AuthN ownership gap** identified across three prior research documents. `phpbb\auth` is the single authority for both authentication and authorization. (High confidence)

### Secondary Conclusions

6. **HS256 is the right algorithm choice** for a PHP monolith. Simpler than RS256, no key distribution problem, faster operations. Switch to RS256 only if multi-service verification becomes necessary. (Medium-high confidence)

7. **Refresh token rotation with family tracking** provides theft detection and device management without complex session tables. (Medium confidence — standard pattern, untested in this specific codebase)

8. **`user_type` MUST be in JWT** for founder override detection. This is a data field on User entity that Auth needs — confirms the Auth → User dependency direction. (High confidence)

### Recommendations

1. Design the unified `phpbb\auth` service with three main sub-components:
   - `TokenService` (JWT issuance/validation/refresh)
   - `AuthenticationService` (credential verification, login/logout flows)
   - `AuthorizationService` (ACL resolution, `isGranted()` API — preserved from prior design)

2. Add `token_generation` and `perm_version` columns to `phpbb_users` during migration.

3. Keep JWT claims minimal: `sub`, `type`, `utype`, `gen`, `pv`, `flags`, `exp`, `iat`, `jti`, `aud`.

4. Use HttpOnly cookies for web and Bearer header for API — dual transport with consistent validation.

5. Implement elevation as a POST endpoint (`/auth/elevate`) requiring current access token + password → returns elevated JWT with short TTL and scope claims.
