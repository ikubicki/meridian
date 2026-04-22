# Gap Analysis: M3 — Unified Auth Service (`phpbb\auth`)

**Date**: 2026-04-22
**Analyzer**: gap-analyzer subagent

---

## Summary

- **Risk Level**: High
- **Estimated Effort**: High
- **Detected Characteristics**: modifies_existing_code, creates_new_entities, involves_data_operations

This task is a greenfield service build wired onto stable infrastructure. All external dependencies (firebase/php-jwt, PDO, BanService, UserRepository, CachePool) are confirmed wired and working. Three blocking scope questions must be resolved before spec: elevation endpoint inclusion, ACL bitfield depth, and the `_api_token` → `_api_user` attribute rename that is a breaking change for every existing protected controller.

---

## Task Characteristics

| Characteristic | Value | Evidence |
|----------------|-------|----------|
| has_reproducible_defect | false | New feature, no crash/error |
| modifies_existing_code | **true** | AuthController (3 TODO stubs), AuthenticationSubscriber (2 TODO markers), User entity (missing columns), PdoUserRepository (hydrate missing columns), services.yaml (needs auth wiring) |
| creates_new_entities | **true** | Entire `phpbb\auth\` namespace — 0 files currently exist |
| involves_data_operations | **true** | 2 new DB columns on `phpbb_users`, 1 new table `phpbb_auth_refresh_tokens`, RefreshTokenRepository CRUD |
| ui_heavy | false | Backend API only |

---

## Gaps Identified

### Missing: Entire `phpbb\auth` namespace (0/14+ files)

Verified via `file_search src/phpbb/auth/**` → no results. The entire module must be created from scratch:

| File | Status |
|------|--------|
| `phpbb\auth\Contract\AuthenticationServiceInterface` | ❌ Missing |
| `phpbb\auth\Contract\TokenServiceInterface` | ❌ Missing |
| `phpbb\auth\Contract\AuthorizationServiceInterface` | ❌ Missing |
| `phpbb\auth\Contract\RefreshTokenRepositoryInterface` | ❌ Missing |
| `phpbb\auth\Service\AuthenticationService` | ❌ Missing |
| `phpbb\auth\Service\TokenService` | ❌ Missing |
| `phpbb\auth\Service\AuthorizationService` | ❌ Missing |
| `phpbb\auth\Repository\PdoRefreshTokenRepository` | ❌ Missing |
| `phpbb\auth\Repository\AclCacheRepository` | ❌ Missing |
| `phpbb\auth\Entity\RefreshToken` | ❌ Missing |
| `phpbb\auth\Exception\InvalidCredentialsException` | ❌ Missing |
| `phpbb\auth\Exception\TokenRevokedException` | ❌ Missing |
| `phpbb\api\EventSubscriber\AuthorizationSubscriber` | ❌ Missing |
| SQL migration (ALTER + CREATE TABLE) | ❌ Missing |

### Missing: DB schema columns and table

```sql
-- Confirmed missing via phpbb_dump.sql scan (grep returned no results):
ALTER TABLE phpbb_users
    ADD COLUMN token_generation INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN perm_version     INT UNSIGNED NOT NULL DEFAULT 0;

-- New table (confirmed not in dump):
CREATE TABLE phpbb_auth_refresh_tokens (...);
```

### Incomplete Features (existing files need modification)

**`AuthController`** (3 stub methods):
- `login()` — hardcoded sub=2, no DB, no password verify → must inject AuthenticationService
- `logout()` — no-op 204 → must call AuthenticationService::logout()
- `refresh()` — mock response → must call AuthenticationService/TokenService::refresh()

**`AuthenticationSubscriber`** (2 TODO markers, lines 130–131):
- `// TODO: verify $claims->gen against phpbb_users.token_generation (requires DB)` → must inject UserRepository, read user row, check gen counter
- `// TODO: verify $claims->pv against phpbb_users.perm_version (requires DB)` → same, check pv counter
- HLD specifies subscriber should set `_api_user` (User entity) not `_api_token` (stdClass claims) — **BREAKING CHANGE** for all controllers

**`User` entity** — readonly, missing two fields:
- `tokenGeneration: int` — not in constructor
- `permVersion: int` — not in constructor

**`PdoUserRepository::hydrate()`** — confirmed: does not map `token_generation` or `perm_version` from DB row, and `create()` SQL does not include these columns. Also needs `update()` to allow incrementing these columns (e.g. `incrementTokenGeneration`, `incrementPermVersion` or via generic `update()`).

**`services.yaml`** — confirmed: no `phpbb\auth\*` entries. Needs manual wiring (or resource auto-discovery block).

### Missing Tests

| Test file | Status | Notes |
|-----------|--------|-------|
| `tests/phpbb/auth/Service/AuthenticationServiceTest.php` | ❌ Missing | login flow, wrong password, banned user |
| `tests/phpbb/auth/Service/TokenServiceTest.php` | ❌ Missing | encode/decode, expiry, gen counter |
| `tests/phpbb/auth/Service/AuthorizationServiceTest.php` | ❌ Missing | isGranted, founder override |
| `tests/phpbb/auth/Repository/PdoRefreshTokenRepositoryTest.php` | ❌ Missing | rotation, family revocation |
| `tests/phpbb/api/Controller/AuthControllerTest.php` | ⚠️ Must rewrite | Currently tests mock — 5 existing tests will break when service DI is added |
| `tests/phpbb/api/EventSubscriber/AuthenticationSubscriberTest.php` | ⚠️ Must extend | gen/pv validation test cases needed |

### Missing E2E Scenarios

Confirmed existing 18 tests pass (`tests/e2e/api.spec.ts`). Need 4 new scenarios:
- Refresh token flow (consume → new access token → new refresh token)
- Logout (token invalidated after logout)
- Expired/revoked token rejection
- Wrong password → 401

---

## Data Lifecycle Analysis

### Entity: RefreshToken

| Operation | Backend | UI (API endpoint) | User Access | Status |
|-----------|---------|-------------------|-------------|--------|
| CREATE | `PdoRefreshTokenRepository::store()` | `POST /auth/login` | Public endpoint | ❌ missing |
| READ | `findByHash()` | `POST /auth/refresh` | Public endpoint | ❌ missing |
| UPDATE | `rotate()` (revoke old, insert new) | `POST /auth/refresh` | Public endpoint | ❌ missing |
| DELETE/revoke | `revokeFamily()`, `revokeAllForUser()` | `POST /auth/logout` | Authenticated | ❌ missing |

**Completeness**: 0% — entire lifecycle missing. Blocking implementation.
**Orphaned operations**: None yet (all missing).

### Entity: User.token_generation + User.perm_version

| Operation | Backend | Access | Status |
|-----------|---------|--------|--------|
| READ | `UserRepository::findById()` after ALTER | Via gen/pv in JWT subscriber | ❌ columns not in schema yet |
| INCREMENT | `UserRepository::update([tokenGeneration => ...])` | Admin/password-change flow | ❌ `update()` allowedColumns doesn't include these yet |

**Critical gap**: `PdoUserRepository::update()` has a fixed `$allowedColumns` whitelist. `token_generation` and `perm_version` are NOT in it. The logout/ban/password-change flows that increment gen will need `update()` to support `tokenGeneration` and `permVersion` — or a dedicated `incrementTokenGeneration(int $id): void` method.

---

## User Journey Impact Assessment

Not applicable (backend API — no UI components). All endpoints are public or bearer-authenticated — no navigation path needed.

---

## Integration Points

### Consumers affected by this task

| Component | Affected How | Breaking? |
|-----------|-------------|-----------|
| All protected controllers (`ForumsController`, `TopicsController`, `UsersController`) | Currently read `$request->attributes->get('_api_token')->sub`. HLD demands rename to `_api_user` (User entity) | **YES** — attribute-name breaking change |
| `AuthenticationSubscriberTest` | Must add test cases for gen/pv rejection | No (additive) |
| `AuthControllerTest` | Must inject `AuthenticationService` mock — 5 tests become invalid | **YES** — must rewrite test class |
| `PdoUserRepository` | hydrate() must map 2 new columns; update() must allow incrementing them | Breaking if not hydrated (null ref errors) |

### Downstream modules

| Module | Dependency |
|--------|-----------|
| `phpbb\hierarchy` (M5) | `AuthorizationService::getGrantedForums()` will need hierarchy tree — blocked in M3 unless deferred |
| `phpbb\forums` | Will call `AuthorizationService::isGranted('f_read', $forumId)` — needs M3 done |
| All future controllers | Will use `_api_user` attribute after rename |

---

## ACL Bitfield Feasibility Check

**Confirmed**: `phpbb_acl_options` table has data in the dump (f_*, m_*, a_*, u_* permissions — 125 options confirmed from schema). The bitfield architecture is feasible:
- Global permissions (m_*, a_*, u_*): `is_global = 1` in `phpbb_acl_options` → 92 bits → 12 bytes raw → ~16 bytes base64
- Forum permissions (f_*): `is_global = 0` → 33 options, cannot go in JWT, need `AclCacheRepository`

**Risk**: Building the global bitfield for `flags` claim requires joining `phpbb_acl_users + phpbb_acl_groups + phpbb_acl_roles_data + phpbb_acl_options`. This is non-trivial SQL with role inheritance. Without existing ACL resolution code, the `AuthorizationService` implementation carries significant effort risk.

---

## Issues Requiring Decisions

### Critical (Must Decide Before Spec)

**1. POST /auth/elevate scope in M3?**

The orchestrator task description includes `POST /auth/elevate` in the endpoint list. The user's task brief says "optional in M3 or deferred." The HLD has a complete elevation flow (separate JWT, `aud: phpbb-admin`, 5-min TTL, `elv_jti` claim, `scope` claim, derived key).

- **Options**:
  - A) Include in M3 — adds `TokenService::issueElevatedToken()`, `AuthenticationService::elevate()`, `AuthorizationSubscriber` admin route guards, and elevated token validation path (~4 extra files)
  - B) Defer to M4/M5 — M3 delivers login/logout/refresh only; elevate is a documented TODO stub
- **Recommendation**: Defer (option B). Without forum Hierarchy, the ACP permission context is limited anyway. M3 already has ~14 new files + DB migration — adding elevation increases scope by ~25% with no ACP consumers yet.
- **Rationale**: Including `POST /auth/elevate` with no ACP controllers to protect is speculative scope with high complexity cost.

---

**2. AuthorizationService depth in M3**

`AuthorizationService` appears in HLD as a full ACL resolver (12 bytes bitfield from DB join across 4 ACL tables). However, no controllers currently check permissions beyond identity (no `_api_permission` route metadata anywhere). Two options exist:

- **Options**:
  - A) Full implementation — build global bitfield from `phpbb_acl_users/groups/roles_data/options` during login; `AuthorizationSubscriber` at priority 8 checks `_api_permission` route attribute; `AclCacheRepository` caches bitfield by `user_id:perm_version`
  - B) Minimal stub — `flags` claim always = 0 in JWT; `AuthorizationService::isGranted()` exists as interface-only implementation returning `false` (or founder always `true`); wire the subscriber but no routes use `_api_permission` yet
  - C) Defer entirely — no `AuthorizationSubscriber`, no `AuthorizationService`; gen/pv subscriber validation is the only revocation mechanism in M3
- **Recommendation**: Option B (minimal stub). The interface, service, and subscriber exist but `flags` = empty bitfield for now. This unblocks M5 (Hierarchy) which will populate ACL properly without requiring a full rewrite.
- **Rationale**: Full bitfield build requires joining 4 ACL tables with role inheritance — significant implementation risk. No consumers exist yet. Stub satisfies the "ACL architecture in place" requirement without overbuilding.

---

**3. `_api_token` → `_api_user` attribute rename**

Current `AuthenticationSubscriber` sets `_api_token` (stdClass JWT claims). HLD specifies `_api_user` (full User entity). All existing controllers read `$request->attributes->get('_api_token')->sub`.

This is a **breaking change** for `ForumsController`, `TopicsController`, `UsersController`, and any future controllers.

- **Options**:
  - A) Hard rename to `_api_user` — update all 3 controllers in same PR; subscriber hydrates User entity from `sub` claim after gen/pv validation
  - B) Set both — subscriber sets `_api_token` (stdClass, backward compat) AND `_api_user` (User entity); migrate controllers over time
  - C) Keep `_api_token` as stdClass — do not set `_api_user`; controllers access raw JWT claims only; hydration is controller's responsibility
- **Recommendation**: Option A (hard rename). Three controllers is a small surface. Backward-compat shims create confusion; the `User` entity provides meaningful type safety. Gen/pv validation requires a DB user lookup anyway — hydrate the entity once, share it.
- **Rationale**: Setting `_api_user` as the entity is architecturally correct and the subscriber already loads the user for gen/pv check. No extra DB cost.

---

### Important (Should Decide)

**4. Rate limiting in M3 scope?**

HLD login flow step 1: "Rate limit check (IP + user throttle)." No rate limiting infrastructure exists in the codebase (`grep -r "RateLimit\|throttle\|login_attempts" src/phpbb/` found only `user_login_attempts` column in `phpbb_users` which the legacy phpBB uses).

- **Options**:
  - A) Include basic rate limit — use `user_login_attempts` column + IP-based counter in cache; block after N failures
  - B) Defer — M3 delivery without rate limiting; add as M3.1 or M4 security hardening
- **Default**: Defer (option B). The `user_login_attempts` column exists but requires its own policy logic. Adding it to M3 scope increases complexity without being a core auth architecture requirement.

---

**5. DB migration mechanism**

No migration framework (Doctrine Migrations, Phinx) exists. Schema changes needed:
- `ALTER TABLE phpbb_users ADD COLUMN token_generation ...`
- `ALTER TABLE phpbb_users ADD COLUMN perm_version ...`
- `CREATE TABLE phpbb_auth_refresh_tokens (...)`

- **Options**:
  - A) SQL file at `src/phpbb/auth/Migration/001_auth_schema.sql` — must be run manually via `docker exec mysql < file.sql`
  - B) Update `phpbb_dump.sql` directly — the authoritative DB snapshot includes the new schema; re-init DB from dump
  - C) PHP migration class callable via `bin/phpbbcli.php`
- **Default**: Option A + B — both the migration SQL file (runnable incrementally) AND updating `phpbb_dump.sql` to keep it consistent with the expected schema.

---

**6. Refresh token TTL discrepancy**

Task description says "30-day TTL." HLD schema comment says "7 days default."

- **Options**:
  - A) 30 days — matches task brief
  - B) 7 days — matches HLD schema comment (more conservative, phpBB forum context)
- **Default**: 30 days (matches task brief, industry standard for "remember me" sessions). Make it configurable via `$refreshTokenTtl` constructor param defaulting to `30 * 86400`.

---

**7. PasswordService extracted vs inline**

HLD diagram shows `UserService / PasswordService` as a separate layer. No `PasswordService` exists anywhere in `src/phpbb/`.

- **Options**:
  - A) Inline Argon2id in `AuthenticationService` — `password_verify()` + `password_needs_rehash()` + `password_hash()`
  - B) Extract to `phpbb\user\Service\PasswordService` — shared with future registration flow; single responsibility
- **Default**: Option B. Registration will need it. A 3-method service is trivial and properly places password logic in the `user` module (not `auth`).

---

## Recommendations

1. **Resolve 3 critical decisions before spec** — elevate scope, ACL depth, and `_api_user` rename. These directly affect the number of files (±4 for elevate), test surface (±10 test cases), and require coordinated controller updates.

2. **Update `PdoUserRepository::update()` in same PR** as User entity changes — the `$allowedColumns` whitelist must include `tokenGeneration` and `permVersion` to enable gen increment at logout/password-change.

3. **PHP 8.2 native `password_verify()` for Argon2id** — no extra library needed; `password_hash($pass, PASSWORD_ARGON2ID)` is available natively. Confirm Docker PHP image has `--with-password-argon2` (standard in PHP 8.x official images).

4. **`AuthControllerTest` requires full rewrite** — once `AuthController` injects `AuthenticationService`, the current 5 tests that test mock behavior become invalid. Plan for test rewrite in same PR.

5. **Defer `AuthorizationSubscriber`'s `_api_permission` route guard** — create the subscriber class but leave route permission metadata wiring for M5 (when Hierarchy provides forum ACL context). This prevents 403s on existing endpoints during M3 rollout.

---

## Risk Assessment

| Risk Area | Level | Detail |
|-----------|-------|--------|
| **Security** | Critical | JWT/password logic is security-critical; incorrect Argon2id usage, weak RNG for refresh tokens, or timing attacks are OWASP Top 10 risks |
| **DB schema migration** | High | No migration framework; `ALTER TABLE` on `phpbb_users` in production requires care; wrong column type breaks counter logic |
| **Breaking change: `_api_token` rename** | Medium | 3 existing controllers must be updated; missing one causes 500 on protected routes |
| **ACL bitfield build complexity** | High (if full) / Low (if stub) | 4-table ACL join with role inheritance is complex; mitigated by deferring to stub strategy |
| **Refresh token family revocation** | Medium | Family rotation requires atomic `UPDATE + INSERT`; non-atomic implementation allows theft window |
| **Test isolation** | Medium | PHPUnit tests for auth service need DB mock or in-memory PDO; integration tests need real DB |
| **Complexity accumulation** | High | 14+ new files + 5 modified files + DB migration + test rewrites = high cognitive load; should be broken into sub-tasks in spec |
