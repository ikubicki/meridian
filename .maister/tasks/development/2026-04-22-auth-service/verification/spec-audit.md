# Specification Audit — M3 Unified Auth Service (`phpbb\auth`)

**Auditor**: spec-auditor (independent)
**Date**: 2026-04-22
**Spec**: `.maister/tasks/development/2026-04-22-auth-service/implementation/spec.md`
**Status**: ❌ Non-Compliant — 3 critical issues block safe implementation

---

## Summary

The spec is well-structured and covers the majority of the implementation surface at a level appropriate for a security-critical feature. Flows, data models, JWT claims, and Argon2id usage are correctly described. However, three critical defects will cause runtime failures or broken security guarantees if implemented as written, and several important gaps will create ambiguity or missing functionality. The spec must be patched before implementation begins.

| Severity | Count |
|----------|-------|
| Critical (must fix before impl) | 3 |
| Important (should fix) | 5 |
| Low (minor observations) | 5 |

---

## CRITICAL Issues

### C1 — `AuthenticationSubscriber` will reject ALL tokens after M3 (key derivation mismatch)

**Spec References**:
- `TokenService::issueAccessToken()` — signs with `hash_hmac('sha256', 'jwt-access-v1', $jwtSecret, true)` (derived key).
- `AuthenticationSubscriber upgrade` — "After existing `JWT::decode()` call" — adds gen/pv steps but does **not** change the decode call.
- Existing subscriber: `JWT::decode($rawToken, new Key($this->resolveSecret(), 'HS256'))` where `resolveSecret()` returns the **raw** `PHPBB_JWT_SECRET`.

**Evidence**:
- [src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php](../../../../src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php#L128) — decodes with raw secret.
- [spec.md (TokenService)](../../../../.maister/tasks/development/2026-04-22-auth-service/implementation/spec.md#L413) — issues tokens with derived key.

**Gap**: `TokenService` signs tokens with a 32-byte HMAC-derived binary key. `AuthenticationSubscriber` decodes with the raw ASCII string. These are cryptographically different values. Every real token issued after M3 will throw `SignatureInvalidException` on every authenticated request — complete auth failure in production.

**Fix Required**: The `AuthenticationSubscriber` update section must specify:
1. Add `private readonly TokenServiceInterface $tokenService` to the constructor.
2. Replace the existing `JWT::decode()` call + `_api_token` set with `$payload = $this->tokenService->decodeToken($rawToken, 'phpbb-api')`.
3. Remove the `resolveSecret()` method from the subscriber (no longer needed).

Alternatively, specify that `TokenService` is injected and `decodeToken()` is called there. Without this fix, the M3 implementation cannot function.

---

### C2 — Test case `findByHashReturnsNullWhenRevoked` directly contradicts the repository spec

**Spec References**:
- `PdoRefreshTokenRepository::findByHash()` spec: "**Do NOT filter by `revoked_at IS NULL`** — the service layer must receive the revoked entity to detect token theft (reuse of a previously rotated token) and revoke the entire family."
- `PdoRefreshTokenRepositoryTest` test case #4: `findByHashReturnsNullWhenRevoked`.

**Evidence**:
- [spec.md (findByHash)](../../../../.maister/tasks/development/2026-04-22-auth-service/implementation/spec.md#L375) — returns revoked tokens.
- [spec.md (PdoRefreshTokenRepositoryTest)](../../../../.maister/tasks/development/2026-04-22-auth-service/implementation/spec.md#L737) — test #4 asserts null for revoked token.

**Gap**: These two requirements directly contradict each other. If test #4 is implemented as written, it will enforce the wrong behavior: `findByHash()` would be coded to return `null` for revoked tokens, silently breaking the theft detection flow in `AuthenticationService::refresh()`. A stolen-and-revoked token would be treated as "not found" → `InvalidRefreshTokenException` instead of triggering `revokeFamily()`. Theft detection fails silently.

**Fix Required**: Delete test #4 from `PdoRefreshTokenRepositoryTest`. Replace with:
- `findByHashReturnsRevokedEntityWhenTokenIsRevoked` — asserts that `findByHash()` returns the entity even when `revoked_at` is set (non-null).
- Retain tests for `findByHashReturnsNullWhenNotFound` (hash not in table at all).

---

### C3 — Logout flow contradicts `incrementTokenGeneration()` in three places

**Spec References**:
- "Logout Flow" step 4: "Increment `phpbb_users.token_generation` via `UserRepositoryInterface::update(id, ['tokenGeneration' => current + 1])`."
- `PdoUserRepository` component spec: "Add `incrementTokenGeneration(int $userId): void`... avoids a read-modify-write race condition compared to using `update()`."
- `AuthenticationService` component spec: "calls `revokeAllForUser()` then `incrementTokenGeneration()` on the user repository."

**Evidence**:
- [spec.md (Logout Flow)](../../../../.maister/tasks/development/2026-04-22-auth-service/implementation/spec.md#L125) — uses `update()`.
- [spec.md (PdoUserRepository)](../../../../.maister/tasks/development/2026-04-22-auth-service/implementation/spec.md#L248) — defines `incrementTokenGeneration()`.
- [spec.md (AuthenticationService)](../../../../.maister/tasks/development/2026-04-22-auth-service/implementation/spec.md#L457) — calls `incrementTokenGeneration()`.

**Gap**: The "Logout Flow" high-level description (`update()` with read-modify-write) contradicts the component spec (`incrementTokenGeneration()` with atomic SQL). An implementer who follows the Logout Flow section will introduce a race condition where two concurrent logouts could both read the same `token_generation`, both compute `current + 1`, and write the same value — leaving one generation increment ineffective.

**Fix Required**: Update the Logout Flow step 4 to read: "Call `UserRepositoryInterface::incrementTokenGeneration($userId)` — single atomic `UPDATE ... SET token_generation = token_generation + 1`."

---

## IMPORTANT Issues

### I1 — `services.yaml` auth section missing (no concrete YAML provided)

**Evidence**: [src/phpbb/config/services.yaml](../../../../src/phpbb/config/services.yaml) ends at `phpbb\user\Service\BanService: ~`. No `phpbb\auth\*` block exists.

**Gap**: Gotcha #9 mentions `TokenService` needs `$jwtSecret: '%env(PHPBB_JWT_SECRET)%'` but gives no complete `services.yaml` snippet. Interface aliases that cannot be autowired and must be explicit:
- `phpbb\auth\Contract\RefreshTokenRepositoryInterface` → `PdoRefreshTokenRepository`
- `phpbb\auth\Contract\AuthenticationServiceInterface` → `AuthenticationService`
- `phpbb\auth\Contract\TokenServiceInterface` → `TokenService`
- `phpbb\auth\Contract\AuthorizationServiceInterface` → `AuthorizationService`

Without the `AuthorizationServiceInterface` alias, `AuthorizationSubscriber` (which has `AuthorizationServiceInterface` in its constructor) cannot be wired by Symfony DI. The `AuthenticationServiceInterface` alias is required for the controller mock to work in tests.

**Fix Required**: Add a complete `phpbb\auth\*` services.yaml block to the spec (mirror of the `phpbb\user\*` section), including all four interface → concrete aliases and the `TokenService` scalar argument.

---

### I2 — `RefreshToken` entity missing `isRevoked()` and `isExpired()` methods

**Spec Reference**: `AuthenticationService::refresh()` flow — "If `isRevoked()` → ... If `isExpired()` → ..."

**Evidence**: [spec.md (RefreshToken entity)](../../../../.maister/tasks/development/2026-04-22-auth-service/implementation/spec.md#L260) — defines only `isValid(): bool`.

**Gap**: `AuthenticationService::refresh()` calls `isRevoked()` and `isExpired()` as if they are entity methods, but only `isValid()` is defined. An implementer must guess the implementation (`$token->revokedAt !== null`, `$token->expiresAt < new \DateTimeImmutable()`). The theft detection path (`isRevoked()` → `revokeFamily()`) is the security-critical branch; it must be unambiguous.

**Fix Required**: Add to `RefreshToken` entity spec:
```
isRevoked(): bool  — returns $this->revokedAt !== null
isExpired(): bool  — returns $this->expiresAt <= new \DateTimeImmutable()
```
Update `isValid()` to use these: `return !$this->isRevoked() && !$this->isExpired()`.

---

### I3 — `ForumsController` in Core Requirements list is incorrect

**Spec Reference**: Core Requirement #9 — "hard rename in `ForumsController`, `TopicsController`, `UsersController`". Scope-clarifications.md D3 — "ForumsController, TopicsController, UsersController — zmiana `_api_token` na `_api_user`."

**Evidence**: `grep _api_token src/phpbb/api/Controller/ForumsController.php` — **zero results**. [src/phpbb/api/Controller/ForumsController.php](../../../../src/phpbb/api/Controller/ForumsController.php) returns mock data with no auth attribute access.

**Gap**: `ForumsController` does not currently use `_api_token` at all. Core Requirement 9 and scope-clarifications.md both list it for rename, but the detailed component spec correctly says "Currently no `_api_token` read — no change needed." The inconsistency will cause the implementer to waste time looking for a rename that doesn't exist, or add an unnecessary change.

**Fix Required**: Remove `ForumsController` from Core Requirement 9 and from scope-clarifications.md D3's list. Only `TopicsController` (line 98) and `UsersController` (line 35) have actual `_api_token` reads.

---

### I4 — No E2E test scenarios specified for new auth endpoints

**Evidence**: [tests/e2e/api.spec.ts](../../../../tests/e2e/api.spec.ts) contains 16 tests, none covering `/auth/refresh`, `/auth/logout`, `/auth/elevate`, or error paths. Scope-clarifications.md promises "rozszerzone E2E" (extended E2E).

**Gap**: The spec defines detailed unit tests for all auth services but specifies zero new E2E scenarios. Acceptance criteria 1–5 directly require POST `/auth/login`, `/auth/refresh`, `/auth/logout`, `/auth/elevate` to work end-to-end. Without E2E tests, these criteria cannot be mechanically verified.

**Fix Required**: Add an E2E test section to the spec with at minimum:
1. `POST /auth/login` valid credentials → 200 + JWT structure check.
2. `POST /auth/refresh` with issued token → 200 + new tokens, old token revoked in DB.
3. `POST /auth/logout` → 204 + subsequent Bearer rejected with 401 "Token revoked".
4. `POST /auth/elevate` with correct password → 200 + `aud: "phpbb-admin"`.
5. `POST /auth/login` wrong password → 401.
6. `GET /me` with stale token (after logout) → 401.

---

### I5 — `AuthenticationSubscriber` audience validation uses `===` on `aud` claim that may be an array

**Spec Reference**: `AuthenticationSubscriber upgrade` — "Validate `$claims->aud === 'phpbb-api'`."

**Gap**: RFC 7519 allows `aud` to be either a string or an array of strings. `firebase/php-jwt` v6+ returns `aud` as-is from the JWT payload — it can be an array if the token was issued with an array. The spec guarantees `TokenService` issues tokens with a string `aud`, but `===` comparison against an array silently returns `false` rather than throwing. The subscriber would then issue a 401 "Token revoked" with a misleading error message. Defensive code should handle both forms.

**Fix Required**: Add a note that `TokenService` MUST issue `aud` as a string (not array) and that the subscriber validates with a strict string comparison. OR specify: `$aud = is_array($claims->aud) ? $claims->aud[0] : $claims->aud; if ($aud !== 'phpbb-api') { ... }`.

---

## LOW Issues

### L1 — Logout Flow step 2 mentions `jti` but it is never used

**Reference**: "Logout Flow step 2: Extract `sub` (user ID) and `jti` (token ID)."

`jti` is not passed to `logout(int $userId)` and JTI deny-list is explicitly out of scope. Extracting `jti` is vestigial from an earlier design and will confuse the implementer into thinking it is needed.

**Fix**: Remove `jti` extraction from Logout Flow. The controller reads `_api_user->id` and that's all that's needed.

---

### L2 — `deleteExpired()` cron query leaves naturally-expired (non-revoked) rows forever

**Reference**: `PdoRefreshTokenRepository::deleteExpired()` — `DELETE WHERE expires_at < UNIX_TIMESTAMP() AND revoked_at IS NOT NULL`.

Tokens that expired without ever being explicitly revoked (e.g., user simply never refreshed) are not pruned because `revoked_at` remains `NULL`. These rows accumulate indefinitely. This may be intentional (token audit trail) but is not stated as such.

**Fix**: If intentional, add an implementation note explaining the design choice. If not, change condition to `OR` or remove the `revoked_at IS NOT NULL` filter.

---

### L3 — `iss` claim is specified but never validated on decode

**Reference**: JWT Token Specification — `iss: "phpbb"`.

Neither `TokenService::decodeToken()` nor `AuthenticationSubscriber` spec mentions validating `iss`. This is low risk for M3 (all tokens are self-issued) but is a departure from JWT best practices.

**Fix**: Either add explicit `iss` validation to `decodeToken()` spec or add a note explaining why it is deliberately skipped in M3.

---

### L4 — `User` entity `final readonly` gotcha provides no resolution path

**Reference**: Implementation Gotcha #1 — "verify no positional-argument instantiation exists in tests or fixtures before adding `tokenGeneration` and `permVersion`."

The gotcha correctly identifies the risk but stops at "verify." It gives no directive for what to do if positional instantiation IS found (update all call sites to named arguments, or use a static factory, etc.).

**Fix**: Add: "If positional instantiation is found in `tests/phpbb/` or elsewhere, convert those call sites to named arguments before adding the new fields. Named arguments are already PHP 8.0+ and align with the project's PHP 8.4+ requirement."

---

### L5 — `AuthorizationSubscriber` priority note is ambiguous

**Reference**: "Registered at priority 8 but no routes define `_api_permission`... `AuthorizationSubscriber` priority 8 — fires after `AuthenticationSubscriber` (priority 16, higher = earlier)."

The parenthetical "(higher = earlier)" correctly explains Symfony's priority model but the phrasing "fires after... (higher = earlier)" can be parsed as self-contradictory by a reader unfamiliar with Symfony.

**Fix**: Reword as: "Priority 8 fires *after* priority 16 — in Symfony, higher priority number = earlier execution. `AuthorizationSubscriber` (8) therefore always sees `_api_user` already set by `AuthenticationSubscriber` (16)."

---

## Compliance Status

❌ **Non-Compliant** — Three critical issues (C1, C2, C3) will cause:
- C1: Complete authentication failure (all token validation → 500/401).
- C2: Silent bypass of refresh token theft detection.
- C3: Race condition on concurrent logout undermining the generation counter revocation guarantee.

---

## Recommended Fix Priority

| # | Fix | Effort |
|---|-----|--------|
| 1 | C1 — Inject `TokenService` into subscriber, replace decode call | ~15 min spec edit |
| 2 | C2 — Replace test #4 with correct assertion | ~5 min spec edit |
| 3 | C3 — Align Logout Flow with `incrementTokenGeneration()` | ~2 min spec edit |
| 4 | I1 — Add complete `services.yaml` `phpbb\auth\*` YAML block | ~15 min spec edit |
| 5 | I2 — Add `isRevoked()`, `isExpired()` to `RefreshToken` entity spec | ~5 min spec edit |
| 6 | I3 — Remove `ForumsController` from rename list | ~2 min spec edit |
| 7 | I4 — Add E2E test scenarios section | ~30 min spec edit |
| 8 | I5 — Clarify `aud` string assertion | ~5 min spec edit |

Total estimated spec corrections: ~1.5 hours before implementation can safely begin.
