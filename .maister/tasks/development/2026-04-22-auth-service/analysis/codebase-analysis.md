# Codebase Analysis Report

**Date**: 2026-04-22
**Task**: M3 — Auth Unified Service (`phpbb\auth`)
**Description**: Implement phpbb\auth unified Auth Service replacing mock authentication with real JWT login/logout/refresh, Argon2id password verification, generation counter validation in AuthSubscriber, ACL bitfield authorization, RefreshToken DB storage.
**Analyzer**: codebase-analyzer skill (3 Explore agents: File Discovery, Code Analysis, Context Discovery)

---

## Summary

The phpBB REST API ships a functional but entirely mocked auth layer: `AuthController` returns a hard-coded JWT (sub=2, gen=1, pv=0) without touching the database, `AuthenticationSubscriber` decodes and validates tokens but explicitly skips `gen`/`pv` counter checks via two TODO comments, and no `phpbb\auth` namespace exists at all. The two required schema columns (`token_generation`, `perm_version`) are absent from `phpbb_users`, and the `phpbb_auth_refresh_tokens` table does not exist. All dependencies needed by the real implementation (Firebase JWT, PDO, BanService, UserRepository) are already wired and working — making this primarily a **greenfield service build** on top of stable infrastructure.

---

## Files Identified

### Primary Files (modify or replace)

| File | Lines | Role |
|------|-------|------|
| [src/phpbb/api/Controller/AuthController.php](../../../../src/phpbb/api/Controller/AuthController.php) | ~90 | Login/logout/refresh endpoints — all three methods are TODO stubs |
| [src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php](../../../../src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php) | ~155 | JWT middleware (priority 16) — must grow gen/pv DB validation |
| [src/phpbb/user/Entity/User.php](../../../../src/phpbb/user/Entity/User.php) | ~55 | User aggregate root — missing `tokenGeneration` + `permVersion` fields |
| [src/phpbb/user/Repository/PdoUserRepository.php](../../../../src/phpbb/user/Repository/PdoUserRepository.php) | ~130+ | Hydrate method must map the two new columns |
| [src/phpbb/config/services.yaml](../../../../src/phpbb/config/services.yaml) | ~70 | DI wiring — must register `phpbb\auth\*` services and aliases |

### Files to Create (new)

| Path | Purpose |
|------|---------|
| `src/phpbb/auth/Contract/AuthenticationServiceInterface.php` | login / logout / logoutAll / elevate |
| `src/phpbb/auth/Contract/TokenServiceInterface.php` | issueAccessToken / issueElevatedToken / verify / refresh |
| `src/phpbb/auth/Contract/AuthorizationServiceInterface.php` | isGranted / isGrantedAny / getGrantedForums |
| `src/phpbb/auth/Contract/RefreshTokenRepositoryInterface.php` | CRUD + family rotation |
| `src/phpbb/auth/Service/AuthenticationService.php` | Argon2id verify, BanService, gen counter, token issuance |
| `src/phpbb/auth/Service/TokenService.php` | Firebase JWT encode/decode, key derivation, rotation |
| `src/phpbb/auth/Service/AuthorizationService.php` | Bitfield-based ACL, founder override |
| `src/phpbb/auth/Repository/PdoRefreshTokenRepository.php` | `phpbb_auth_refresh_tokens` CRUD |
| `src/phpbb/auth/Repository/AclCacheRepository.php` | Cache-keyed permission bitfield read/write |
| `src/phpbb/auth/Entity/RefreshToken.php` | Value object for persisted refresh token |
| `src/phpbb/auth/Exception/InvalidCredentialsException.php` | Bad username/password |
| `src/phpbb/auth/Exception/TokenRevokedException.php` | Revoked gen/family |
| `src/phpbb/api/EventSubscriber/AuthorizationSubscriber.php` | New — route-level ACL check (priority 8) |
| SQL migration script | ALTER TABLE + CREATE TABLE for two new columns and new table |

### Related Files (read or test impact)

| File | Relationship |
|------|-------------|
| [tests/phpbb/api/Controller/AuthControllerTest.php](../../../../tests/phpbb/api/Controller/AuthControllerTest.php) | Must be rewritten — currently tests mock behaviour |
| [tests/phpbb/api/EventSubscriber/AuthenticationSubscriberTest.php](../../../../tests/phpbb/api/EventSubscriber/AuthenticationSubscriberTest.php) | Must grow gen/pv test cases |
| [tests/e2e/api.spec.ts](../../../../tests/e2e/api.spec.ts) | 18 existing tests pass — 4 new scenarios needed |
| [src/phpbb/user/Service/BanService.php](../../../../src/phpbb/user/Service/BanService.php) | Consumed by `AuthenticationService` |
| [docker-compose.yml](../../../../docker-compose.yml) | `PHPBB_JWT_SECRET` already set |
| `.maister/tasks/development/2026-04-22-auth-service/analysis/research-context/high-level-design.md` | Authoritative architecture design |

---

## Current Functionality

### Login Flow (as-is)

```
POST /auth/login
  → AuthController::login()
      → validates username/password presence (422 if missing)
      → encodes JWT with hard-coded sub=2, gen=1, pv=0, flags=0
      → returns accessToken + "mock-refresh-token"
      (NO DB lookup, NO password check, ALWAYS succeeds with valid credentials shape)
```

### Token Validation (as-is)

```
GET|POST /api/* (protected)
  → AuthenticationSubscriber::onKernelRequest() [priority 16]
      → bypasses PUBLIC_SUFFIXES (/health, /auth/login, /auth/signup, /auth/refresh)
      → extracts Bearer token
      → Firebase JWT::decode() — validates signature + expiry
      → // TODO: verify gen against phpbb_users.token_generation
      → // TODO: verify pv against phpbb_users.perm_version
      → stores raw stdClass claims as request attribute '_api_token'
```

### Logout / Refresh (as-is)

- `logout()` — returns 204, does nothing
- `refresh()` — returns a mock `accessToken`, ignores the provided `refreshToken` value entirely

### Key Missing Behaviours

| Capability | Status |
|-----------|--------|
| Argon2id `password_verify()` | ❌ Not implemented |
| Real DB user lookup on login | ❌ Not implemented |
| `token_generation` counter validation | ❌ TODO in subscriber |
| `perm_version` validation | ❌ TODO in subscriber |
| Refresh token DB storage | ❌ Not implemented |
| Refresh token family rotation | ❌ Not implemented |
| Logout revocation | ❌ No-op |
| ACL bitfield resolution | ❌ Not implemented |
| `AuthorizationSubscriber` | ❌ Not wired |
| Admin elevation endpoint | ❌ Not implemented |

---

## Dependencies

### What the Auth Service Depends On

| Dependency | Purpose | Status |
|-----------|---------|--------|
| `firebase/php-jwt` | JWT encode/decode | ✅ In vendor, used in subscriber |
| `\PDO` (via `PdoFactory`) | DB access for user + token lookup | ✅ Wired in services.yaml |
| `phpbb\user\Contract\UserRepositoryInterface` | User lookup by username/ID | ✅ Wired |
| `phpbb\user\Service\BanService` | `assertNotBanned()` during login | ✅ Wired |
| `phpbb\cache\CachePoolFactoryInterface` | ACL bitfield cache | ✅ Wired |
| `PHPBB_JWT_SECRET` env var | JWT signing key | ✅ Set in docker-compose.yml |

### What Depends on Auth (consumers)

| File | How it uses auth |
|------|----------------|
| All protected API controllers | Read `_api_token` attribute set by subscriber |
| `AuthenticationSubscriber` | Decodes + validates JWT |
| `AuthorizationSubscriber` (planned) | Checks ACL bitfield from JWT claims |

**Consumer count**: All controllers — changes to JWT claim structure are a **breaking interface change**.

**Impact scope**: High — the `_api_token` attribute is the identity contract for every controller downstream.

---

## Schema Analysis

### `phpbb_users` (existing, needs ALTER)

| Column | Status | Notes |
|--------|--------|-------|
| `user_id` | ✅ Present | PK |
| `user_password` | ✅ Present | `varchar(255)`, Argon2id hash stored here |
| `username_clean` | ✅ Present | Used by `findByUsername()` |
| `user_type` | ✅ Present | UserType enum (0=normal, 1=inactive, 2=ignore, 3=founder) |
| `token_generation` | ❌ **MISSING** | INT UNSIGNED NOT NULL DEFAULT 0 — needed for revocation |
| `perm_version` | ❌ **MISSING** | INT UNSIGNED NOT NULL DEFAULT 0 — needed for ACL cache key |

### `phpbb_auth_refresh_tokens` (new table required)

```sql
CREATE TABLE phpbb_auth_refresh_tokens (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    token_hash    CHAR(64)        NOT NULL,          -- SHA-256 hex of opaque token
    user_id       INT UNSIGNED    NOT NULL,
    family_id     CHAR(16)        NOT NULL,          -- hex(random_bytes(8))
    issued_at     INT UNSIGNED    NOT NULL,
    expires_at    INT UNSIGNED    NOT NULL,
    revoked       TINYINT(1)      NOT NULL DEFAULT 0,
    INDEX idx_token_hash  (token_hash),
    INDEX idx_user_family (user_id, family_id),
    INDEX idx_expires     (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### `phpbb_sessions` — NOT used

Traditional session table (13 columns, `session_admin` flag). JWT approach does not touch this table.

---

## Architecture Overview

The planned architecture (from `high-level-design.md`) uses a **three-interface facade** under `phpbb\auth`:

```
HTTP Request
    │
    ▼  priority 16
AuthenticationSubscriber  ──► TokenService::verify()
    │                         UserRepository::findById()
    │                         (gen counter + pv check)
    │  sets _api_user on request
    ▼  priority 8
AuthorizationSubscriber   ──► AuthorizationService::isGranted()
    │                         (bitfield from JWT claims / AclCacheRepository)
    ▼
Controller
    │
    └──► (on login) AuthenticationService::login()
              ├─ UserRepository::findByUsername()
              ├─ password_verify() [Argon2id]
              ├─ BanService::assertNotBanned()
              ├─ TokenService::issueAccessToken()
              └─ RefreshTokenRepository::store()
```

**Token lifecycle:**
- Access token: HS256 JWT, 15 min TTL, claims: `sub`, `gen`, `pv`, `utype`, `flags` (ACL bitfield), `iat`, `exp`, `jti`
- Refresh token: opaque 32-byte random, SHA-256-hashed for storage, 30-day TTL, family rotation on use, full family revocation on theft detection

---

## Integration Points

| # | Integration Point | Change Type | Risk |
|---|------------------|-------------|------|
| 1 | `phpbb_users` schema | ALTER TABLE (add 2 cols) | Medium — existing data unaffected (DEFAULT 0) |
| 2 | `phpbb_auth_refresh_tokens` | CREATE TABLE | Low — new table |
| 3 | `User` entity + hydrate | Add 2 readonly fields | Low — additive |
| 4 | `PdoUserRepository::hydrate()` | Map 2 new columns | Low |
| 5 | `AuthController` | Replace 3 mock methods | High — contract change |
| 6 | `AuthenticationSubscriber` | Add gen/pv DB validation | High — all auth paths affected |
| 7 | `services.yaml` | Register `phpbb\auth\*` | Low — additive |
| 8 | New `AuthorizationSubscriber` | Wire at priority 8 | Medium — new middleware |
| 9 | `AuthControllerTest` | Rewrite (inject real services via mocks) | Medium |
| 10 | `AuthenticationSubscriberTest` | Add gen/pv test cases | Low |
| 11 | E2E `api.spec.ts` | Add 4+ new scenarios | Low |

---

## Test Coverage Assessment

### Existing Tests

| Test File | Tests | Coverage |
|-----------|-------|---------|
| `AuthControllerTest.php` | 5 tests | Mock-only: login 200/422, logout 204, refresh 200/422 |
| `AuthenticationSubscriberTest.php` | 8+ tests | Signature/expiry/missing header — no gen/pv cases |
| `tests/e2e/api.spec.ts` | 18 E2E tests | General API flows — auth login/logout paths present |

### Coverage Gaps

- No test: Argon2id credential verification (wrong password → 401)
- No test: `token_generation` mismatch → 401
- No test: `perm_version` mismatch → cache invalidation
- No test: refresh token rotation (use once, then reuse → family revocation)
- No test: logout revokes all tokens (`logoutAll`)
- No test: admin elevation (`POST /auth/elevate`)
- No test: banned user login attempt → 403
- No E2E: expired access token → 401 with specific error body
- No E2E: refresh flow end-to-end

### Coverage Level: **Low (<30%)** for the real implementation surface

---

## Coding Patterns

### Naming Conventions

- **Classes/Interfaces**: PascalCase — `AuthenticationService`, `TokenServiceInterface`
- **Methods/Properties**: camelCase — `issueAccessToken()`, `tokenGeneration`
- **DB columns**: snake_case — `token_generation`, `perm_version`, `user_id`
- **Interface suffix**: `*Interface` (e.g., `UserRepositoryInterface`)
- **Exception suffix**: `Exception` extending `RuntimeException`

### Architecture Patterns

- **Style**: Constructor injection, `readonly` properties, `final` entity classes
- **Contracts**: Interface + alias in `services.yaml` (ISP pattern)
- **DB access**: Native PDO prepared statements — no Doctrine DBAL
- **No global variables in OOP code** — env vars injected via DI or constructor param
- **PSR-4 autoloading** under `phpbb\` namespace

### File Header

All files must include the project copyright/license docblock before `declare(strict_types=1)`.

---

## Complexity Assessment

| Factor | Value | Level |
|--------|-------|-------|
| Files to create | ~14 new files | High |
| Files to modify | 5 existing files | Medium |
| DB schema changes | 2 columns + 1 table | Medium |
| External dependencies | 0 new (all in vendor) | Low |
| Downstream consumers | All protected controllers | High |
| Test coverage | <30% of real surface | High |
| Security sensitivity | Highest (auth core) | High |

### Overall: **Complex**

This is a greenfield service build (no `phpbb\auth` namespace exists) that also modifies the live auth middleware and the User entity — both of which underpin every API endpoint. Security correctness is paramount: Argon2id timing, refresh token storage, family rotation, and revocation must all be implemented correctly before the first commit.

---

## Key Findings

### Strengths

- All required external dependencies are already installed and wired (`firebase/php-jwt`, PDO, BanService, UserRepository)
- `PHPBB_JWT_SECRET` is correctly configured in docker-compose
- `AuthenticationSubscriber` has the right structure and explicit TODO markers at exactly the right integration points
- `AuthController` has exact TODO comments matching the three needed replacements
- Existing unit tests provide a regression baseline; they will need to be adapted but the test scaffold is solid
- `BanService::assertNotBanned()` is ready-to-use, no changes needed

### Concerns

- **Schema migration has no tooling** — project has no migration framework; raw `ALTER TABLE` statements must be applied manually or via a bootstrap script; risk of environment drift
- **`User` entity is `final readonly`** — adding fields is safe (additive) but the hydrate method in `PdoUserRepository` must be updated atomically with the schema change
- **JWT claim structure change is breaking** — existing mock tokens (sub=2, gen=1, pv=0) will fail gen/pv validation once the subscriber checks are enabled; dev environment will need a re-login
- **No JTI deny-list** — the high-level design mentions optional JTI deny-list; logout revocation relies solely on generation counter increment, which is a deliberate trade-off but must be documented
- **`AuthenticationSubscriber` constructor** — currently takes `jwtSecret` as a plain `string` param wired via env; injecting DB-dependent services requires careful DI wiring (circular dependency risk if UserRepository is also needed by auth services)

### Opportunities

- `AuthorizationSubscriber` can be introduced as a pure add — no existing code needs to change for the first pass (just `services.yaml`)
- Argon2id is available via PHP's built-in `password_verify()` — no new library needed
- ACL bitfield in JWT means `AuthorizationSubscriber` can be zero-DB for global permission checks (only forum-scoped checks hit cache/DB)

---

## Impact Assessment

- **Primary changes**: `AuthController`, `AuthenticationSubscriber`, `User` entity, `PdoUserRepository` hydrator, `services.yaml`
- **New module**: entire `src/phpbb/auth/` namespace (~14 files)
- **Schema**: `phpbb_users` ALTER + `phpbb_auth_refresh_tokens` CREATE
- **Test updates**: `AuthControllerTest` rewrite, `AuthenticationSubscriberTest` extension, 4+ new E2E scenarios

### Risk Level: **High**

Authentication is the trust boundary of the entire API. Bugs in credential verification, token generation, or revocation logic are critical security vulnerabilities. The lack of a migration framework means schema changes are irreversible without manual intervention. The `_api_token` attribute on the request object is consumed by every downstream controller — changes to its type (from `stdClass` to a typed `User` entity) constitute a breaking internal API change.

---

## Recommendations

### Implementation Order (risk-mitigating sequence)

1. **Schema migration first** — `ALTER TABLE phpbb_users ADD token_generation`, `ADD perm_version`; `CREATE TABLE phpbb_auth_refresh_tokens`. Apply to all environments before any code changes go live.

2. **Extend `User` entity + hydrator** — add `tokenGeneration` and `permVersion` readonly fields; update `PdoUserRepository::hydrate()`. Run existing tests to confirm no regression.

3. **Build `phpbb\auth` contracts** — define the three interfaces (`AuthenticationServiceInterface`, `TokenServiceInterface`, `AuthorizationServiceInterface`) and `RefreshTokenRepositoryInterface`. No implementation yet — just contracts the rest of the code will code against.

4. **Implement `TokenService`** — pure JWT encode/decode logic wrapping `firebase/php-jwt`; unit-testable without DB. Covers `issueAccessToken()`, `verify()`, `refresh()`.

5. **Implement `PdoRefreshTokenRepository`** — CRUD on `phpbb_auth_refresh_tokens`; SHA-256 hashing, family rotation. Integration-testable.

6. **Implement `AuthenticationService`** — login (findByUsername → password_verify → assertNotBanned → issueAccessToken + storeRefreshToken), logout (increment `token_generation`), refresh (rotate token family). This is the highest-risk component; write unit tests before implementation (TDD).

7. **Update `AuthController`** — replace three TODO stubs with `AuthenticationService` calls. Update `AuthControllerTest` to inject mocked service.

8. **Update `AuthenticationSubscriber`** — replace two TODO comments with `UserRepository::findById()` lookups for gen/pv validation; extend `AuthenticationSubscriberTest`.

9. **Wire `services.yaml`** — register all `phpbb\auth\*` services, alias interfaces, inject `jwtSecret` for `TokenService`.

10. **Add `AuthorizationSubscriber`** — wire at priority 8; read `_api_permission` route attribute, delegate to `AuthorizationService`. Add to services.yaml.

11. **E2E test expansion** — add: expired token → 401, refresh flow, logout, admin elevation.

12. **Security review** — verify Argon2id timing (no early return on user-not-found path), refresh token entropy (≥32 bytes), family revocation on reuse, no token material logged.

---

## Next Steps

Proceed to **gap analysis**: compare this current-state report against the high-level design (`research-context/high-level-design.md`) to produce a detailed specification of every interface, method signature, DB column type, and error code. Then generate an implementation plan with checkboxed task groups.
