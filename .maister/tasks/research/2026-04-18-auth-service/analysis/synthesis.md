# Synthesis: phpbb\auth Service Design Research

**Research Question**: How to design a modern `phpbb\auth\` service extracted from legacy phpBB ACL code — authentication integration with `phpbb\user`, ACL permission checking, role/option management — to be used by REST API middleware.

**Date**: 2026-04-18  
**Sources synthesized**: 6 finding categories (acl-core, acl-database, auth-providers, admin-acl, user-service-integration, rest-api-middleware)

---

## 1. Executive Summary

The legacy phpBB ACL system is a well-designed but monolithically-implemented authorization engine. The `auth` class (1139 lines) combines authentication orchestration, ACL permission checking, cache management, and admin DB queries into a single God-class. However, beneath this monolith lies a clean conceptual model: a three-value permission system (YES/NEVER/NO) with a deterministic resolution algorithm, efficient bitfield caching, and clear separation between global and forum-scoped permissions.

The new `phpbb\auth\` service can be cleanly extracted because:
1. **Authentication is already solved** — `phpbb\user\Service\AuthenticationService` handles login/logout/session fully.
2. **The ACL engine is self-contained** — it operates on 5 dedicated DB tables + one column in `phpbb_users`.
3. **The resolution algorithm is deterministic** — NEVER wins → Founder override → YES from any source → default NO.
4. **The REST API has a clear gap** — JWT validation exists, but zero ACL checking exists today.

---

## 2. Cross-Source Analysis

### 2.1 Validated Findings (Confirmed by Multiple Sources)

| Finding | Evidence Sources | Confidence |
|---------|-----------------|------------|
| Three-value ACL: YES(1), NEVER(0), NO(-1) | acl-core (constants), acl-database (schema), admin-acl (UI flow) | **100%** |
| 125 permission options: 42 `a_` + 33 `f_` + 15 `m_` + 35 `u_` | acl-database (query), acl-core (code scan) | **100%** |
| Bitfield encoding: 31-bit chunks → base-36 → 6-char → newline-separated | acl-core (build_bitstring), acl-database (user_permissions sample) | **100%** |
| NEVER-wins resolution rule | acl-core (_set_group_hold_ary), admin-acl (permission trace), acl-database (schema semantics) | **100%** |
| Founder override for all `a_*` permissions | acl-core (acl_cache line 440), user-service-integration (UserType enum) | **100%** |
| `user_permissions` excluded from User entity | user-service-integration (spec analysis), acl-core (separate read path) | **95%** |
| REST API has zero ACL checking | rest-api-middleware (auth_subscriber analysis), user-service-integration (gap analysis) | **100%** |
| Auth providers handle authentication, NOT authorization | auth-providers (interface analysis), user-service-integration (boundary definition) | **100%** |
| 19 cache invalidation call sites across codebase | acl-core (18 sites), admin-acl (1 additional from migration tool) | **95%** |
| Delete-then-insert pattern for all ACL writes | admin-acl (acl_set, acl_set_role), acl-core (acl_cache) | **100%** |

### 2.2 Contradictions Identified and Resolved

| Contradiction | Source A | Source B | Resolution |
|---------------|----------|----------|------------|
| ACL_NO value: 0 or -1? | acl-core says ACL_NEVER=0, ACL_NO=-1 | acl-database confirms same | **No contradiction** — `ACL_NEVER=0` (strongest deny), `ACL_NO=-1` (neutral/unset). The naming is confusing but consistent. |
| Role count: 24 or 48? | acl-database reports 48 rows | acl-database also says "24 unique × 2 duplicates" | **Resolved**: Installation artifact — 24 unique roles, each duplicated. Only unique matters for service design. |
| Founder can be blocked by NEVER? | acl-core says "NEVER cannot be overridden" (generic) | acl-core also says Founder override applies AFTER merge | **Resolved**: Founder override runs AFTER NEVER-wins merge but forcibly sets all `a_*` to YES. Founders bypass NEVER for admin permissions only. Non-admin NEVER still blocks founders for `f_`, `m_`, `u_` permissions. |
| `admin: true` in JWT vs actual ACL | rest-api-middleware: JWT has `admin: true` boolean | acl-core: admin access is `acl_get('a_')` | **Gap identified**: JWT flag is self-declared, not ACL-verified. Must be resolved by reading `a_` permission from ACL engine when signing JWT. |

### 2.3 Evidence Quality Assessment

| Source | Quality | Completeness | Notes |
|--------|---------|-------------|-------|
| acl-core | **High** | 100% — all 15+ methods documented | Direct code reading, line-by-line |
| acl-database | **High** | 100% — live DB inspection | All 5 tables + user_permissions column |
| auth-providers | **High** | 95% — full interface + db.php flow | OAuth internals not fully traced |
| admin-acl | **High** | 95% — complete CRUD + invalidation | Some edge cases in role deletion may exist |
| user-service-integration | **High** | 95% — based on spec, not yet implemented | Spec may change during implementation |
| rest-api-middleware | **High** | 90% — current code is Phase 1 mock | Production code will differ significantly |

---

## 3. Patterns and Themes

### 3.1 Architectural Patterns

| Pattern | Prevalence | Quality | Evidence |
|---------|-----------|---------|----------|
| **God-class anti-pattern** | Core — the `auth` class IS this | Low (to be fixed) | 1139 lines, 15+ methods mixing 4 concerns |
| **Bitfield caching** | Core — the performance backbone | High (well-optimized) | O(1) reads, lazy rebuild, memoized decode |
| **Three-value logic** | Core — the permission model | High (elegant) | YES/NEVER/NO with clear precedence |
| **Delete-then-insert** | All ACL writes | Medium (works, not ideal) | admin-acl: `acl_set()`, `acl_set_role()` |
| **Role indirection** | Group/user assignment | High (compact storage) | One row per role assignment vs N rows per option |
| **Lazy cache rebuild** | Permission lifecycle | High (efficient) | Clear → empty → next access rebuilds |
| **Event hook points** | Cache invalidation | Medium (exists, not consistent) | `core.acl_clear_prefetch_after` event exists |

### 3.2 Design Patterns

| Pattern | Where | Assessment |
|---------|-------|-----------|
| **Strategy pattern** | Auth providers (provider_interface) | Well-implemented — clean plugin architecture |
| **Null-object pattern** | provider/base.php | All methods return null — concrete overrides what's needed |
| **Repository pattern** | NOT used in legacy (raw SQL everywhere) | Must be introduced in new service |
| **Observer/Event** | Some events dispatched (acl_clear_prefetch_after) | Incomplete — needs expansion |
| **Value object** | NOT used (arrays everywhere) | Must be introduced (Permission, Role entities) |

### 3.3 Implementation Patterns

| Pattern | Assessment | Action |
|---------|-----------|--------|
| Raw SQL with string interpolation | **Security risk** in `obtain_user_data()` | Replace with PDO prepared statements |
| Global state (`var $acl`, `var $cache`) | Legacy PHP4 style | Replace with readonly properties + DI |
| Array-based returns | No type safety | Replace with DTOs/entities |
| Mixed scope (global + local in same method) | Complex but correct | Preserve logic, separate interface |

---

## 4. Key Insights

### Insight 1: The Auth/AuthZ Boundary Is Clean

**Supporting evidence**: auth-providers (authentication), user-service-integration (AuthenticationService), acl-core (authorization)

The legacy `auth` class mixes authentication and authorization, but the conceptual boundary is perfectly clear:
- **Authentication** = "Who are you?" → `phpbb\user\Service\AuthenticationService` (already designed)
- **Authorization** = "What can you do?" → `phpbb\auth\Service\AuthorizationService` (to be designed)

The `login()` method in legacy `auth.php` delegates to auth providers. The new system delegates to `AuthenticationService`. The ACL methods (`acl_get`, `acl_getf`, etc.) are purely authorization — they never touch credentials.

**Implication**: The new `phpbb\auth\` namespace should contain ONLY authorization logic. No `login()`, no `logout()`, no session management.

### Insight 2: The Bitfield Cache IS the Read Path

**Supporting evidence**: acl-core (O(1) bitstring lookup), acl-database (user_permissions column), admin-acl (19 invalidation sites)

The entire ACL read path depends on a single `mediumtext` column (`user_permissions`) in the users table. Permission checking never hits the 5 ACL tables — they're only used for:
1. Building the cache (lazy, on miss)
2. Admin UI operations (CRUD)

**Implication**: The new service must preserve this two-tier architecture:
- **Hot path** (every API request): Decode `user_permissions` → bitstring → O(1) lookup
- **Cold path** (admin operations + cache miss): Query ACL tables → resolve → encode → store

### Insight 3: `user_permissions` Is a Shared-Ownership Problem

**Supporting evidence**: user-service-integration (column excluded from User entity), acl-core (read/write in auth class), admin-acl (clear in auth_admin)

The `user_permissions` column lives in `phpbb_users` but is NOT part of the User entity. It's written by the ACL engine and cleared by admin operations. This creates a cross-service data dependency:
- `phpbb\user` owns the `phpbb_users` table structurally
- `phpbb\auth` owns the `user_permissions` column semantically

**Resolution**: The auth service should access `user_permissions` and `user_perm_from` via its own `AclCacheRepository` interface, querying `phpbb_users` directly. The user service sets it to `''` on registration and otherwise ignores it.

### Insight 4: The REST API Gap Is Precisely Defined

**Supporting evidence**: rest-api-middleware (complete subscriber analysis), user-service-integration (integration flow)

The current state is:
- JWT validation ✅ (exists, needs hardening)
- User identity resolution ✅ (JWT claims contain user_id)
- ACL permission checking ❌ (completely missing)
- Route→permission mapping ❌ (routes have no permission metadata)
- Token refresh/revocation ❌ (stateless JWT only)

What must be added:
1. Route defaults with `_api_permission` and `_api_scope` metadata
2. Auth subscriber refactored to call `AuthorizationService::isGranted()` after JWT decode
3. User entity resolution from JWT user_id claim
4. 403 response when permission denied (using existing `json_exception_subscriber`)

### Insight 5: Permission Resolution Has 5 Layers

**Supporting evidence**: acl-core (Resolution Priority Summary), acl-database (table structure), admin-acl (permission trace)

```
Layer 1: User direct grants       (phpbb_acl_users)
Layer 2: User role grants          (phpbb_acl_users → phpbb_acl_roles_data)
Layer 3: Group direct grants       (phpbb_acl_groups + phpbb_user_group)
Layer 4: Group role grants         (phpbb_acl_groups → phpbb_acl_roles_data)
Layer 5: NEVER override + Founder  (merge algorithm)
```

The merge algorithm:
1. User direct + role grants processed first → `hold_ary`
2. Group grants merged via `_set_group_hold_ary()` — NEVER wins
3. Founder override applied LAST for `a_*` only
4. Binary collapse: `YES → 1`, everything else → `0`
5. Encode to bitstring

**Implication**: The new `PermissionResolver` service must faithfully reproduce this 5-layer resolution. Shortcuts or simplifications risk permission escalation bugs.

### Insight 6: Global/Local OR Semantics Are Critical for API

**Supporting evidence**: acl-core (acl_get method), acl-database (is_global/is_local flags)

When checking `f_read` for forum_id=5:
- Check global index in `$acl[0]` (if `f_read` has `is_global=1`) — always false for `f_*`
- Check local index in `$acl[5]` (if `f_read` has `is_local=1`) — true for `f_*`
- OR them together

For `m_edit` for forum_id=5:
- Check global in `$acl[0]` — moderator has `is_global=1`
- Check local in `$acl[5]` — moderator has `is_local=1`
- OR: if user has global `m_edit`, they have it in ALL forums

**Implication**: The `isGranted()` method must distinguish between:
- `isGranted($user, 'f_read', forumId: 5)` — local check only
- `isGranted($user, 'a_board')` — global check only
- `isGranted($user, 'm_edit', forumId: 5)` — global OR local check

---

## 5. Relationships and Dependencies

### 5.1 Component Relationship Map

```
phpbb\user (Authentication)                    phpbb\auth (Authorization)
┌─────────────────────────┐                   ┌─────────────────────────────┐
│ AuthenticationService   │──produces──→      │ AuthorizationService        │
│   login() → Session     │   User entity     │   isGranted(User, perm, f)  │
│   validateSession()→User│                   │   isGrantedAny(...)         │
│                         │                   │   getGrantedForums(...)     │
│ UserRepository          │◄─reads────        │                             │
│   findById() → User     │                   │ PermissionResolver          │
│                         │                   │   resolve(userId) → bitfield│
│ GroupRepository         │◄─reads────        │                             │
│   getGroupsForUser()    │                   │ AclCacheService             │
│                         │                   │   getUserPermissions()      │
│ User entity             │                   │   clearPrefetch()           │
│   id, type, groupId     │                   │   buildBitstring()          │
│   isFounder()           │                   │                             │
│                         │                   │ PermissionService (admin)   │
│ GroupMembership entity  │                   │   setPermissions()          │
│   isPending, isLeader   │                   │   removePermissions()       │
│                         │                   │                             │
│ Group entity            │                   │ RoleService (admin)         │
│   skipAuth flag         │                   │   createRole()              │
└─────────────────────────┘                   │   updateRole()              │
                                              │   deleteRole()              │
REST API Layer                                │                             │
┌─────────────────────────┐                   │ AclRepository               │
│ auth_subscriber         │──calls──→         │   (5 ACL tables)            │
│   JWT → user_id         │                   │                             │
│   route → permission    │                   │ AclCacheRepository          │
│   isGranted() check     │                   │   (user_permissions col)    │
│                         │                   └─────────────────────────────┘
│ Route config (YAML)     │
│   _api_permission       │                   DB Tables (owned by auth)
│   _api_scope            │                   ┌─────────────────────────┐
│   _api_forum_param      │                   │ phpbb_acl_options (125) │
│                         │                   │ phpbb_acl_roles (24)    │
│ Controllers             │                   │ phpbb_acl_roles_data    │
│   receive User entity   │                   │ phpbb_acl_users         │
│   receive permissions   │                   │ phpbb_acl_groups        │
└─────────────────────────┘                   │ phpbb_users.user_perms  │
                                              └─────────────────────────┘
```

### 5.2 Data Flow: API Request → Permission Check

```
1. HTTP Request with Bearer JWT
2. RouterListener resolves route → adds _route, _api_permission, _api_scope, _api_forum_param
3. auth_subscriber (priority 8):
   a. Extract + decode JWT → user_id
   b. UserRepository::findById(user_id) → User entity
   c. Check User.isActive() — reject inactive/banned
   d. AuthorizationService::isGranted(User, _api_permission, forum_param) → bool
   e. If denied → throw AccessDeniedException → 403 JSON
   f. If granted → set _api_user attribute on request
4. Controller receives User entity from request attributes
5. Response
```

### 5.3 Data Flow: Permission Cache Build

```
1. acl_clear_prefetch() called (any of 19 trigger sites)
2. user_permissions set to '' in phpbb_users
3. _role_cache rebuilt in file cache
4. Next request for this user:
   a. AuthorizationService::isGranted() called
   b. AclCacheService::getUserPermissions() returns ''
   c. PermissionResolver::resolve(userId):
      i.   Load role cache from file cache
      ii.  Query phpbb_acl_users (user direct + role grants)
      iii. Query phpbb_acl_groups JOIN phpbb_user_group (group grants)
      iv.  Merge: user first, then groups via NEVER-wins algorithm
      v.   Founder override for a_*
      vi.  build_bitstring() → base-36 encoded string
   d. AclCacheService::storeUserPermissions(userId, bitstring)
   e. Decode bitstring → in-memory array
   f. O(1) permission lookup
```

---

## 6. Gaps and Uncertainties

### 6.1 Information Gaps

| Gap | Impact | How to resolve |
|-----|--------|---------------|
| Extension permissions (beyond 125 core) | Extensions can add new `auth_option` rows. The service must handle dynamic option sets. | Check `acl_options` cache invalidation when extensions enabled/disabled |
| Group processing order in SQL | Multiple groups may have conflicting YES settings. Order affects which YES "wins" for non-NEVER cases. | Usually irrelevant (YES=YES regardless of source), but edge cases with category flags could surface |
| `user_perm_from` feature scope | The "switch permissions" admin feature is documented but unclear if the REST API should support it | Probably not — exclude from v1, add later if needed |
| OAuth token interaction with ACL | If OAuth logins are supported, tokens may need different ACL treatment | Excluded from scope per research brief — future work |

### 6.2 Unverified Claims

| Claim | Source | Why unverified |
|-------|--------|---------------|
| `phpbb\user\Service\AuthenticationService` spec is final | user-service-integration | Spec is not yet implemented — may change |
| `firebase/php-jwt` is available at runtime | rest-api-middleware | It's in vendor/ but NOT in composer.json require |
| 24 unique roles (not 48) | acl-database | Duplication appears to be install artifact but not 100% confirmed |

### 6.3 Unresolved Inconsistencies

| Inconsistency | Details | Recommendation |
|---------------|---------|----------------|
| **ACL_NO vs "not stored"** | admin-acl says "ACL_NO is never stored — only YES and NEVER get rows." But ACL_NO is formally `-1`. In `acl_roles_data`, `auth_setting` can be 0 (NEVER) or 1 (YES). Absence = NO. | Treat NO as "absent" in repository, use explicit enum in domain |
| **Category flag bug** (PHPBB3-10252) | `acl_get_list()` can report incorrect category flags when NEVER overrides exist | Accept as known limitation, document, fix in new service |
| **JWT `admin` boolean vs ACL `a_`** | REST API sets `admin: true` in JWT as a boolean, but ACL has granular `a_*` permissions | Replace with ACL-derived claims; embed permission scope in JWT |

---

## 7. Synthesis by Technical Framework

### 7.1 Component Analysis

**What exists** (to be extracted):
- Permission checking engine (15 methods in `auth.php`)
- 5 ACL database tables with 125 options, 24 roles, 422 mappings
- Bitfield cache mechanism (encode/decode/invalidate)
- Admin CRUD operations (in `auth_admin` + ACP modules)
- Auth provider plugin system (interface + 4 implementations)

**What needs to be built** (new):
- Clean service interfaces (`AuthorizationServiceInterface`, `PermissionServiceInterface`, `RoleServiceInterface`)
- Repository layer for 5 ACL tables (`AclRepository`, `AclCacheRepository`)
- Proper entities/value objects (`Permission`, `Role`, `PermissionGrant`, `AclOptions`)
- REST API middleware integration (`AuthorizationMiddleware` or refactored `auth_subscriber`)
- Route→permission mapping configuration

**What's already solved** (by `phpbb\user`):
- Authentication (login/logout/session)
- User entity with `UserType` (including Founder detection)
- Group entity with `skipAuth` flag
- GroupMembership with `isPending`/`isLeader`

### 7.2 Flow Analysis

**Read path** (hot, every request):
```
JWT decode → user_id → load user_permissions (DB or cached) → decode bitstring → O(1) index lookup
```
- **Bottleneck**: DB read for `user_permissions` on first request per user per cache cycle
- **Optimization**: Already optimal at O(1) after decode. Consider Redis for `user_permissions` in future.

**Write path** (cold, admin operations only):
```
ACP form → CSRF check → auth check → acl_set() [delete+insert] → acl_clear_prefetch()
```
- **Bottleneck**: `acl_clear_prefetch()` rebuilds entire role cache even for single-user changes
- **Optimization**: Selective role cache invalidation (only if affected role changed)

**Cache lifecycle**:
```
Fresh → In use (bitstring cached in DB) → Invalidated (set to '') → Stale (first access rebuilds) → Fresh
```

### 7.3 Quality Assessment (SWOT)

| Strengths | Weaknesses |
|-----------|-----------|
| O(1) permission checks | God-class architecture |
| Efficient bitfield encoding | No type safety (arrays everywhere) |
| NEVER-wins security model | Raw SQL with interpolation risks |
| Lazy cache rebuild | Full role cache rebuild on any change |
| Well-defined 3-value logic | Category flag auto-calc bug |

| Opportunities | Threats |
|--------------|---------|
| Clean extraction to modern OOP | Breaking existing permission semantics |
| Type-safe entities/DTOs | Performance regression (losing O(1)) |
| REST API integration | Backward incompatibility with ACP |
| Event-driven cache invalidation | Extension compatibility |

---

## 8. What the New Service Handles vs What's Already Solved

### Already Solved (DO NOT REIMPLEMENT)

| Capability | Owner | Interface |
|-----------|-------|-----------|
| User login (credential verification) | `phpbb\user\AuthenticationService` | `login(LoginDTO): Session` |
| Session management (create/validate/destroy) | `phpbb\user\SessionService` | `create()`, `findById()`, `destroy()` |
| Password hashing/verification | `phpbb\user\PasswordHasherInterface` | `verify()`, `hash()`, `needsRehash()` |
| User entity (id, type, group) | `phpbb\user\Entity\User` | `isFounder()`, `isActive()` |
| Group entity + membership | `phpbb\user\Entity\Group/GroupMembership` | `skipAuth`, `isPending` |
| Ban checking | `phpbb\user\BanService` | `assertNotBanned()` |

### New Auth Service Must Handle

| Capability | Priority | Complexity |
|-----------|----------|------------|
| `isGranted(User, permission, ?forumId)` — core permission check | **P0** | Medium (port resolution logic) |
| `isGrantedAny(User, permissions[], ?forumId)` — multi-permission OR | **P0** | Low (wraps isGranted) |
| `getGrantedForums(User, permission)` — forums with permission | **P1** | Medium (iterate all forums) |
| Bitfield decode (`_fill_acl` equivalent) | **P0** | Medium (port encoding) |
| Bitfield build (`acl_cache` + `build_bitstring`) | **P0** | High (5-layer resolution) |
| Cache invalidation (`acl_clear_prefetch`) | **P0** | Medium (DB update + file cache) |
| Permission option registry (load `_acl_options` cache) | **P0** | Low (simple query + cache) |
| Role cache management | **P1** | Medium (serialize/deserialize) |
| Admin: set permissions (users/groups) | **P1** | High (delete+insert, role verification) |
| Admin: manage roles (CRUD) | **P1** | Medium (standard CRUD) |
| Admin: permission trace/debug | **P2** | Medium (diagnostic tool) |
| REST API: route→permission mapping | **P0** | Low (YAML config + subscriber) |
| REST API: auth subscriber refactoring | **P0** | Medium (priority, 403 handling) |

---

## 9. Conclusions

### Primary Conclusions

1. **The auth service is AUTHORIZATION ONLY.** Authentication is fully handled by `phpbb\user`. The new namespace should reflect this: `phpbb\auth\Service\AuthorizationService` as the primary entry point.

2. **The 5-layer resolution algorithm must be preserved exactly.** Any deviation risks permission escalation (users gaining unintended access) or permission blocking (users losing intended access). The NEVER-wins rule and Founder override are security-critical.

3. **The bitfield cache is the performance backbone.** The O(1) read path must be preserved. The new service should decode the `user_permissions` bitstring once per request and cache in-memory, exactly as legacy does.

4. **The REST API gap is well-defined and solvable.** Route defaults in YAML for permission metadata + refactored auth_subscriber at priority 8 = minimal architecture change for full ACL integration.

5. **`user_permissions` column requires shared DB access.** The auth service needs its own repository to read/write this column in `phpbb_users`. This is the ONLY cross-table access between user and auth domains.

### Secondary Conclusions

6. The legacy `auth_admin` class (extending `auth`) should be split into separate `PermissionService` and `RoleService` — admin operations are clearly distinct from permission checking.

7. The 19 cache invalidation sites suggest the auth service should expose a clean `clearCache(?int $userId = null): void` method and potentially event-based triggers.

8. The JWT strategy should evolve: short-lived access tokens (15 min) with ACL-derived claims, plus refresh tokens in `phpbb_api_tokens` table for revocation.

9. `group_skip_auth` is a subtle but important flag that must be respected in group permission resolution.

10. The category flag auto-calculation bug (PHPBB3-10252) should be fixed in the new service by not using category flags for authorization decisions — only specific permission options.
