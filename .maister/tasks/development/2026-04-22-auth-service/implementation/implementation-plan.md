# Implementation Plan: M3 — Unified Auth Service (`phpbb\auth`)

## Overview

Total Steps: 68
Task Groups: 7
Expected Tests: 26–44 (6–8 per group + max 10 in review group)

---

## Implementation Steps

### Task Group 1: DB Schema + Infrastructure
**Dependencies:** None
**Estimated Steps:** 8

- [x] 1.0 Complete database schema and User entity infrastructure
  - [x] 1.1 Write 4 focused tests for User entity with new fields
  - [x] 1.2 Create `src/phpbb/auth/Migration/001_auth_schema.sql`
  - [x] 1.3 Update `phpbb_dump.sql` to reflect schema changes
  - [x] 1.4 Modify `src/phpbb/user/Entity/User.php` — add two new readonly constructor fields
  - [x] 1.5 Add `incrementTokenGeneration(int $userId): void` to `src/phpbb/user/Contract/UserRepositoryInterface.php`
  - [x] 1.6 Extend `src/phpbb/user/Repository/PdoUserRepository.php`
  - [x] 1.7 Write 4 focused tests for PdoUserRepository changes
  - [x] 1.8 Ensure Group 1 tests pass

**Acceptance Criteria:**
- All 8 User entity + repository tests pass
- SQL migration file is valid (can be applied to a fresh MySQL 8 instance)
- `User` entity has `tokenGeneration` and `permVersion` fields with correct default 0
- `PdoUserRepository::incrementTokenGeneration()` uses single atomic UPDATE prepared statement

---

### Task Group 2: Auth Contracts + Entities
**Dependencies:** Group 1
**Estimated Steps:** 10

- [x] 2.0 Complete phpbb\auth contracts and value objects
  - [x] 2.1 Write 6 focused tests for RefreshToken entity and TokenPayload
    - `itIsRevokedWhenRevokedAtIsSet` — `isRevoked()` returns true when `revokedAt` not null
    - `itIsNotRevokedWhenRevokedAtIsNull` — `isRevoked()` returns false
    - `itIsExpiredWhenExpiresAtIsInPast` — `isExpired()` true for past datetime
    - `itIsNotExpiredWhenExpiresAtIsInFuture` — `isExpired()` false for future datetime
    - `itIsValidWhenNotRevokedAndNotExpired` — `isValid()` true only when both conditions met
    - `itBuildsTokenPayloadFromStdClass` — `TokenPayload::fromStdClass()` maps all fields
    - Test file: `tests/phpbb/auth/Entity/RefreshTokenTest.php` (new)
    - Test file: `tests/phpbb/auth/Entity/TokenPayloadTest.php` (new)
  - [x] 2.2 Create `src/phpbb/auth/Exception/AuthenticationFailedException.php`
    - Extends `\RuntimeException`
    - File header, declare(strict_types=1), namespace `phpbb\auth\Exception`
    - No additional methods or properties
  - [x] 2.3 Create `src/phpbb/auth/Exception/InvalidRefreshTokenException.php`
    - Extends `\RuntimeException`
    - Same structure as AuthenticationFailedException
  - [x] 2.4 Create `src/phpbb/auth/Entity/RefreshToken.php`
    - Final readonly class, namespace `phpbb\auth\Entity`
    - Constructor fields: id (int), userId (int), familyId (string), tokenHash (string), issuedAt (\DateTimeImmutable), expiresAt (\DateTimeImmutable), revokedAt (?\DateTimeImmutable)
    - Methods: `isRevoked(): bool`, `isExpired(): bool`, `isValid(): bool`
  - [x] 2.5 Create `src/phpbb/auth/Entity/TokenPayload.php`
    - Final readonly class, namespace `phpbb\auth\Entity`
    - Constructor fields: iss (string), sub (int), aud (string), iat (int), exp (int), jti (string), gen (int), pv (int), utype (int), flags (string)
    - Static factory: `fromStdClass(\stdClass $claims): self` — casts each claim to correct PHP type
  - [x] 2.6 Create `src/phpbb/auth/Contract/AuthenticationServiceInterface.php`
    - Methods: `login(string $username, string $password, string $ip): array`, `logout(int $userId): void`, `refresh(string $rawRefreshToken): array`, `elevate(int $userId, string $password): array`
    - PHPDoc: `@throws AuthenticationFailedException`, `@throws BannedException`, `@throws InvalidRefreshTokenException`
  - [x] 2.7 Create `src/phpbb/auth/Contract/TokenServiceInterface.php`
    - Methods: `issueAccessToken(User $user): string`, `issueElevatedToken(User $user): string`, `decodeToken(string $rawToken, string $expectedAud): TokenPayload`
    - Import `phpbb\user\Entity\User` and `phpbb\auth\Entity\TokenPayload`
  - [x] 2.8 Create `src/phpbb/auth/Contract/AuthorizationServiceInterface.php`
    - Single method: `isGranted(User $user, string $permission): bool`
  - [x] 2.9 Create `src/phpbb/auth/Contract/RefreshTokenRepositoryInterface.php`
    - Methods: `save(RefreshToken $token): void`, `findByHash(string $hash): ?RefreshToken`, `revokeByHash(string $hash): void`, `revokeFamily(string $familyId): void`, `revokeAllForUser(int $userId): void`, `deleteExpired(): void`
    - All hash parameters are SHA-256 hex strings (CHAR64)
  - [x] 2.10 Ensure Group 2 tests pass
    - Run: `vendor/bin/phpunit tests/phpbb/auth/Entity/`
    - All 6 written tests must pass

**Acceptance Criteria:**
- All 6 entity tests pass
- All contract files exist with correct method signatures
- `RefreshToken::isValid()` returns false when either isRevoked() or isExpired() is true
- `TokenPayload::fromStdClass()` correctly casts `sub`, `gen`, `pv`, `utype` to int

---

### Task Group 3: Auth Services Core
**Dependencies:** Group 2
**Estimated Steps:** 11

- [x] 3.0 Complete PasswordService, TokenService, and RefreshTokenService
  - [x] 3.1 Write 6 focused tests for PasswordService and TokenService
    - `itHashesPasswordWithArgon2id` — `hashPassword()` result starts with `$argon2id$`
    - `itVerifiesValidPassword` — `verifyPassword()` returns true for correct password
    - `itRejectsInvalidPassword` — `verifyPassword()` returns false for wrong password
    - `itDetectsNeedsRehash` — `needsRehash()` returns false for a freshly hashed password
    - `itIssuesAccessTokenWithCorrectAud` — decode issued JWT, check aud="phpbb-api"
    - `itDecodesAccessTokenReturnsTokenPayload` — `decodeToken()` returns `TokenPayload` instance
    - Test files: `tests/phpbb/user/Service/PasswordServiceTest.php` (new), `tests/phpbb/auth/Service/TokenServiceTest.php` (new)
  - [x] 3.2 Create `src/phpbb/user/Service/PasswordService.php`
    - Namespace `phpbb\user\Service`
    - No constructor dependencies
    - `hashPassword(string $plaintext): string` — `password_hash($plaintext, PASSWORD_ARGON2ID)`
    - `verifyPassword(string $plaintext, string $hash): bool` — `password_verify($plaintext, $hash)`
    - `needsRehash(string $hash): bool` — `password_needs_rehash($hash, PASSWORD_ARGON2ID)`
  - [x] 3.3 Create `src/phpbb/auth/Service/TokenService.php`
    - Constructor: `string $jwtSecret`, `int $accessTtl = 900`, `int $elevatedTtl = 300`
    - Private `deriveKey(string $context): string` — `hash_hmac('sha256', $context, $this->jwtSecret, true)`
    - `issueAccessToken(User $user): string` — build claims with iss, sub=$user->id, aud="phpbb-api", iat, exp=now+900, jti=uuid4, gen=$user->tokenGeneration, pv=$user->permVersion, utype, flags, kid="access-v1"; `JWT::encode($claims, $derivedKey, 'HS256')`
    - `issueElevatedToken(User $user): string` — aud="phpbb-admin", exp=now+300, kid="elevated-v1", scope=["acp","mcp"]
    - `decodeToken(string $rawToken, string $expectedAud): TokenPayload` — select key by aud; `JWT::decode()`; validate aud; `TokenPayload::fromStdClass()`; throws `\UnexpectedValueException` on failure
    - Private `uuid4(): string` helper using `random_bytes()`
  - [x] 3.4 Write 4 focused tests for RefreshTokenService
    - `itIssuesFamilyReturnsRawTokenAndFamilyId` — `issueFamily()` returns both keys
    - `itSavesHashedTokenNotRawToken` — verify repository `save()` called with SHA-256 hash (not raw)
    - `itRotatesFamilyRevokesOldTokenAndSavesNew` — mock repo, verify `revokeByHash()` called then new entity saved
    - `itRotatesFamilyPreservesFamilyId` — new token shares same familyId as old
    - Test file: `tests/phpbb/auth/Service/RefreshTokenServiceTest.php` (new)
  - [x] 3.5 Create `src/phpbb/auth/Service/RefreshTokenService.php`
    - Constructor: `RefreshTokenRepositoryInterface $refreshTokenRepository`, `int $refreshTtlDays = 30`
    - `issueFamily(int $userId): array` — generate family_id (uuid4), raw token (uuid4), hash = `hash('sha256', $raw)`, create `RefreshToken` entity, call `$this->refreshTokenRepository->save()`, return `['rawToken' => $raw, 'familyId' => $familyId]`
    - `rotateFamily(RefreshToken $existingToken): array` — call `revokeByHash($existingToken->tokenHash)`, generate new raw uuid4 in same `family_id`, save new entity, return `['rawToken' => $newRaw]`
    - Private `uuid4(): string` — same implementation as in TokenService (do not share — avoid coupling)
    - All timestamps as `\DateTimeImmutable`; expires_at = now + refreshTtlDays days
  - [x] 3.6 Ensure Group 3 tests pass
    - Run: `vendor/bin/phpunit tests/phpbb/user/Service/ tests/phpbb/auth/Service/TokenServiceTest.php tests/phpbb/auth/Service/RefreshTokenServiceTest.php`
    - All 10 written tests must pass

**Acceptance Criteria:**
- All 10 PasswordService + TokenService + RefreshTokenService tests pass
- `PasswordService::hashPassword()` produces Argon2id hashes — verified via `str_starts_with($hash, '$argon2id$')`
- Raw refresh token is never stored — only SHA-256 hex stored in `RefreshToken::$tokenHash`
- `TokenService::decodeToken()` throws `\UnexpectedValueException` for wrong audience

---

### Task Group 4: AuthenticationService + RefreshToken Repository
**Dependencies:** Groups 1, 2, 3
**Estimated Steps:** 12

- [x] 4.0 Complete AuthenticationService and PdoRefreshTokenRepository
  - [x] 4.1 Write 4 focused tests for PdoRefreshTokenRepository
    - `itSavesTokenWithPreparedStatement` — mock PDO+PDOStatement, verify INSERT called with correct params
    - `itFindsByHashReturnsRefreshToken` — mock SELECT returns row, verify entity hydrated correctly
    - `itFindsByHashReturnsNullWhenNotFound` — mock SELECT returns empty, verify null returned
    - `itRevokesAllForUserUpdatesWithPreparedStatement` — mock PDO, verify UPDATE called with userId
    - Test file: `tests/phpbb/auth/Repository/PdoRefreshTokenRepositoryTest.php` (new)
  - [x] 4.2 Create `src/phpbb/auth/Repository/PdoRefreshTokenRepository.php`
    - Constructor: `private readonly \PDO $pdo`
    - Private const TABLE = `'phpbb_auth_refresh_tokens'`
    - `save(RefreshToken $token): void` — INSERT prepared statement; issued_at/expires_at/revoked_at stored as Unix timestamps (INT)
    - `findByHash(string $hash): ?RefreshToken` — `SELECT * WHERE token_hash = :hash LIMIT 1`; do NOT filter by revoked_at (needed for theft detection); returns null if not found
    - Private `hydrate(array $row): RefreshToken` — cast all fields; `revokedAt` = null when $row['revoked_at'] IS NULL, else `DateTimeImmutable::createFromFormat('U', $row['revoked_at'])`
    - `revokeByHash(string $hash): void` — `UPDATE ... SET revoked_at = UNIX_TIMESTAMP() WHERE token_hash = :hash AND revoked_at IS NULL`
    - `revokeFamily(string $familyId): void` — `UPDATE ... SET revoked_at = UNIX_TIMESTAMP() WHERE family_id = :familyId AND revoked_at IS NULL`
    - `revokeAllForUser(int $userId): void` — `UPDATE ... SET revoked_at = UNIX_TIMESTAMP() WHERE user_id = :userId AND revoked_at IS NULL`
    - `deleteExpired(): void` — `DELETE WHERE expires_at < UNIX_TIMESTAMP() AND revoked_at IS NOT NULL`
  - [x] 4.3 Write 4 focused tests for AuthenticationService
    - `itLoginReturnsTokensOnValidCredentials` — mock all deps, happy path returns accessToken+refreshToken+expiresIn
    - `itLoginThrowsAuthenticationFailedOnWrongPassword` — PasswordService returns false → `AuthenticationFailedException`
    - `itLoginThrowsBannedExceptionWhenBanServiceThrows` — BanService throws BannedException → rethrown
    - `itLogoutRevokesTokensAndIncrementsGeneration` — verify both `revokeAllForUser()` and `incrementTokenGeneration()` called
    - Test file: `tests/phpbb/auth/Service/AuthenticationServiceTest.php` (new) — use `$this->createMock()` only
  - [x] 4.4 Create `src/phpbb/auth/Service/AuthenticationService.php`
    - Constructor: UserRepositoryInterface, PasswordService, BanService, TokenService, RefreshTokenService, RefreshTokenRepositoryInterface
    - `login()`: `findByUsername()` → null throw AuthenticationFailedException; `verifyPassword()` → false throw; `assertNotBanned()` → BannedException rethrows as-is; `issueFamily(userId)`; `issueAccessToken(user)`; return array
    - `logout()`: `revokeAllForUser(userId)`; `incrementTokenGeneration(userId)`; return void
    - `refresh()`: `hash('sha256', $rawToken)` → `findByHash()` → null throw; `isRevoked()` → `revokeFamily(familyId)`+throw; `isExpired()` → throw; `rotateFamily($token)` → `findById($token->userId)` → `issueAccessToken($user)`; return array
    - `elevate()`: `findById(userId)` → null throw; `verifyPassword()` → false throw; `issueElevatedToken(user)` → return `['elevatedToken' => ..., 'expiresIn' => 300]`
  - [x] 4.5 Write additional AuthenticationService refresh + elevate tests
    - `itRefreshThrowsInvalidRefreshTokenExceptionWhenNotFound` — findByHash returns null
    - `itRefreshRevokesEntireFamilyOnTokenReuseDetection` — re-use of revoked token calls revokeFamily
    - `itElevateThrowsAuthenticationFailedOnWrongPassword` — wrong password
    - `itRefreshIssuesNewTokensOnSuccess` — happy path returns all three keys
  - [x] 4.6 Ensure Group 4 tests pass
    - Run: `vendor/bin/phpunit tests/phpbb/auth/`
    - All Group 4 tests (8 repository + 8 service) must pass

**Acceptance Criteria:**
- All 16 tests pass (4 repository + 8 AuthenticationService)
- `findByHash()` returns revoked tokens (not filtered) — required for theft detection
- `refresh()` detects token reuse by calling `revokeFamily()` when a revoked token is re-submitted
- `logout()` calls both `revokeAllForUser()` and `incrementTokenGeneration()` — enforces stateless revocation

---

### Task Group 5: API Layer
**Dependencies:** Groups 3, 4
**Estimated Steps:** 12

- [x] 5.0 Complete AuthController, AuthenticationSubscriber upgrade, and AuthorizationService+Subscriber
  - [x] 5.1 Write 6 focused tests for AuthController (complete rewrite)
    - `itReturns200WithTokensOnSuccessfulLogin` — mock AuthenticationService, verify response shape `{data: {accessToken, refreshToken, expiresIn}}`
    - `itReturns422WhenUsernameIsMissing` — missing field validation
    - `itReturns401OnAuthenticationFailedException` — catch maps to 401
    - `itReturns403OnBannedException` — catch maps to 403
    - `itReturns204OnLogout` — verify no body, status 204
    - `itReturns200WithElevatedTokenOnElevate` — verify response `{data: {elevatedToken, expiresIn}}`
    - Test file: `tests/phpbb/api/Controller/AuthControllerTest.php` (complete rewrite — file already exists)
  - [x] 5.2 Replace `src/phpbb/api/Controller/AuthController.php`
    - Constructor: `private readonly AuthenticationServiceInterface $authService`
    - Remove all old mock logic; remove `JwtService` if previously injected
    - `login(Request $request): JsonResponse` — decode JSON body; validate username+password (422); call `$this->authService->login()`; catch `AuthenticationFailedException` → 401; catch `BannedException` → 403; return `JsonResponse(['data' => [...]], 200)`
    - `logout(Request $request): Response` — read `_api_user` attribute; call `$this->authService->logout($user->id)`; return `new Response('', 204)`
    - `refresh(Request $request): JsonResponse` — validate refreshToken field (422); call `$this->authService->refresh()`; catch `InvalidRefreshTokenException` → 401; return 200
    - `elevate(Request $request): JsonResponse` (NEW) — route `POST /auth/elevate`, name `api_v1_auth_elevate`; validate password field; read `_api_user`; call `$this->authService->elevate($user->id, $body['password'])`; catch → 401; return 200
  - [x] 5.3 Create `src/phpbb/auth/Service/AuthorizationService.php`
    - Constructor: no dependencies
    - Implements `AuthorizationServiceInterface`
    - `isGranted(User $user, string $permission): bool` — returns false unconditionally
  - [x] 5.4 Create `src/phpbb/api/EventSubscriber/AuthorizationSubscriber.php`
    - Constructor: `AuthorizationServiceInterface $authorizationService`
    - Implements `EventSubscriberInterface`
    - `getSubscribedEvents()`: `[KernelEvents::REQUEST => ['onKernelRequest', 8]]`
    - `onKernelRequest()`: read `_api_permission` from route defaults via `$event->getRequest()->attributes->get('_route_params')`; if absent → return early; read `_api_user`; call `isGranted()`; false → `JsonResponse(['error' => 'Forbidden', 'status' => 403], 403)`
    - In M3 this always exits early (no routes define `_api_permission`)
  - [x] 5.5 Modify `src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php`
    - Add to constructor: `private readonly UserRepositoryInterface $userRepository`, `private readonly TokenServiceInterface $tokenService`
    - Replace `JWT::decode($rawToken, new Key($secret, 'HS256'))` with `$payload = $this->tokenService->decodeToken($rawToken, 'phpbb-api')`
    - After decode: `$user = $this->userRepository->findById($payload->sub)` → null → 401
    - `if ($payload->gen < $user->tokenGeneration)` → 401 'Token revoked'
    - `if ($payload->pv !== $user->permVersion)` → `$request->attributes->set('_api_token_stale', true)`
    - `$request->attributes->set('_api_user', $user)` — replace old `_api_token` set
    - Do NOT set `_api_token` — hard rename complete in subscriber
  - [x] 5.6 Write 5 focused tests extending AuthenticationSubscriberTest
    - `itSets_api_userAttributeFromUserRepository` — after valid token, _api_user is User entity
    - `itReturns401WhenUserNotFound` — findById returns null
    - `itReturns401WhenTokenGenerationStale` — gen < user->tokenGeneration
    - `itSets_api_token_staleWhenPermVersionMismatch` — pv !== user->permVersion sets flag
    - `itDoesNotSet_api_tokenAttribute` — verify old attribute not set
    - Test file: `tests/phpbb/api/EventSubscriber/AuthenticationSubscriberTest.php` (extend, append 5 new test methods)
  - [x] 5.7 Ensure Group 5 tests pass
    - Run: `vendor/bin/phpunit tests/phpbb/api/Controller/AuthControllerTest.php tests/phpbb/api/EventSubscriber/AuthenticationSubscriberTest.php`
    - All 11 written tests must pass

**Acceptance Criteria:**
- All 11 API layer tests pass (6 AuthController + 5 AuthenticationSubscriber)
- `AuthController` has no mock logic — all behavior delegated to `AuthenticationService`
- `AuthenticationSubscriber` uses `tokenService->decodeToken()` — NEVER raw `JWT::decode()`
- `elevate()` route registered as `api_v1_auth_elevate` on `POST /auth/elevate`
- `_api_token` attribute NOT set anywhere in `AuthenticationSubscriber`

---

### Task Group 6: Controller Rename + Services Wiring
**Dependencies:** Groups 4, 5
**Estimated Steps:** 8

- [x] 6.0 Complete _api_token → _api_user rename and services.yaml wiring
  - [x] 6.1 Inspect `src/phpbb/api/Controller/ForumsController.php` for `_api_token` usage
    - If found: replace with `_api_user` and entity access pattern
    - Spec notes no changes expected here — confirm before skipping
  - [x] 6.2 Inspect and modify `src/phpbb/api/Controller/TopicsController.php`
    - Replace any `$request->attributes->get('_api_token')` with `$request->attributes->get('_api_user')`
    - Update property access: `$token?->sub` → `$user?->id`; `$token?->username` → `$user?->username` etc.
  - [x] 6.3 Modify `src/phpbb/api/Controller/UsersController.php`
    - Replace `$request->attributes->get('_api_token')` with `$request->attributes->get('_api_user')`
    - Replace `$token?->sub` → `$user?->id` (User entity has public `id` property)
    - Replace `$this->userSearchService->findById($userId)` with `$user` directly — entity already hydrated
    - Remove null check on `$userId <= 0`; use `$user === null` check instead
  - [x] 6.4 Add all new service registrations to `src/phpbb/config/services.yaml`
    - `phpbb\user\Service\PasswordService:` — no arguments
    - `phpbb\auth\Service\TokenService:` — arguments: `$jwtSecret: '%env(PHPBB_JWT_SECRET)%'`
    - `phpbb\auth\Service\RefreshTokenService:` — autowire; no explicit args needed
    - `phpbb\auth\Repository\PdoRefreshTokenRepository:` — autowire PDO injection
    - `phpbb\auth\Service\AuthenticationService:` — autowire all deps
    - `phpbb\auth\Service\AuthorizationService:` — no args
    - `phpbb\api\EventSubscriber\AuthorizationSubscriber:` — tag `kernel.event_subscriber`
    - Interface-to-implementation bindings: `phpbb\auth\Contract\AuthenticationServiceInterface: '@phpbb\auth\Service\AuthenticationService'` etc. for TokenServiceInterface, AuthorizationServiceInterface, RefreshTokenRepositoryInterface
    - Ensure `AuthenticationSubscriber` definition updated with new TokenService + UserRepository constructor args
  - [x] 6.5 Write 4 tests covering the rename and wiring (integration-light)
    - `itUsersControllerMeReturnsUserIdFromEntity` — mock request with `_api_user` = User entity, verify response contains correct userId
    - `itUsersControllerMeReturns401WhenNoUser` — `_api_user` null → 401
    - `itTopicsControllerUsesApiUserNotApiToken` — grep/assert `_api_token` not present in TopicsController after change
    - `itForumsControllerHasNoApiTokenUsage` — verify ForumsController clean
    - Test file: `tests/phpbb/api/Controller/UsersControllerTest.php` (extend existing or create)
  - [x] 6.6 Validate services.yaml syntax
    - Run: `php bin/phpbbcli.php debug:container --env=dev 2>&1 | head -20` or equivalent Symfony console command
    - No autowiring errors, no missing service/binding errors
  - [x] 6.7 Ensure Group 6 tests pass
    - Run: `vendor/bin/phpunit tests/phpbb/api/Controller/UsersControllerTest.php`
    - All 4 written tests must pass
  - [ ] 6.8 Run smoke check across all auth-related tests
    - Run: `vendor/bin/phpunit tests/phpbb/`
    - No regressions in previously passing tests

**Acceptance Criteria:**
- All 4 rename tests pass
- No occurrence of `_api_token` remains in ForumsController, TopicsController, UsersController
- `services.yaml` container builds without errors
- All interface-to-implementation bindings registered in services.yaml
- `PHPBB_JWT_SECRET` injected via `%env(PHPBB_JWT_SECRET)%` — never hardcoded

---

### Task Group 7: Test Review & Gap Analysis
**Dependencies:** All previous groups (1–6)
**Estimated Steps:** 6

- [x] 7.0 Review, consolidate, and fill critical test gaps
  - [x] 7.1 Review all tests written in Groups 1–6 (target: ~26–38 existing tests)
    - Verify each test is isolated (no real DB, no real filesystem, no real JWT secret)
    - Ensure test method names match `it[BehaviorDescription]` convention per STANDARDS.md
  - [x] 7.2 Analyze gaps for THIS feature only — not full suite
    - Check: login ban path (BanService.assertNotBanned propagation)
    - Check: refresh token theft detection (rotateFamily on revoked token reuse)
    - Check: TokenService key derivation (access vs elevated use different keys)
    - Check: AuthorizationSubscriber early-return (no _api_permission route attribute)
  - [x] 7.3 Write up to 8 additional strategic tests for critical gaps found
    - Add E2E tests — append to `tests/e2e/api.spec.ts`:
      1. `POST /auth/login` with valid credentials returns 200 + tokens
      2. `POST /auth/login` with wrong password returns 401
      3. `GET /api/v1/me` with valid Bearer token returns user data
      4. `POST /auth/refresh` with valid refresh token returns new tokens
      5. `POST /auth/elevate` with correct password returns elevatedToken
    - PHPUnit gap tests (up to 3):
      - `itTokenServiceUsesDistinctKeyForAccessVsElevated` — decoded access token cannot be decoded with elevated key
      - `itAuthorizationSubscriberExitsEarlyWhenNoPermissionRoute` — no 403 when `_api_permission` not defined
      - `itAuthenticationServiceRefreshDetectsTokenFamilyTheft` — revoked token reuse triggers family revocation
  - [x] 7.4 Run full feature-specific test suite
    - Run: `vendor/bin/phpunit tests/phpbb/auth/ tests/phpbb/user/Service/ tests/phpbb/api/Controller/AuthControllerTest.php tests/phpbb/api/EventSubscriber/AuthenticationSubscriberTest.php`
    - Expected: all ~26–44 tests pass
  - [x] 7.5 Run E2E tests (requires running Docker environment)
    - Run: `cd tests/e2e && npx playwright test --grep "auth"` (or equivalent)
    - All 5 new E2E scenarios pass
  - [x] 7.6 Run full PHPUnit suite — verify no regressions
    - Run: `composer test`
    - Exit code 0 — all previously passing tests still pass

**Acceptance Criteria:**
- Total feature tests: 26–44 pass (including up to 8 new tests from this group)
- 5 E2E scenarios cover login, invalid login, authenticated access, refresh, and elevate
- `composer test` exits 0 with no regressions
- No test uses a real database, real filesystem, or hardcoded JWT secret

---

## Execution Order

1. **Group 1: DB Schema + Infrastructure** (8 steps) — foundational schema + User entity
2. **Group 2: Auth Contracts + Entities** (10 steps, depends on 1) — interfaces + value objects
3. **Group 3: Auth Services Core** (11 steps, depends on 2) — PasswordService + TokenService + RefreshTokenService
4. **Group 4: AuthenticationService + RefreshToken Repository** (12 steps, depends on 1, 2, 3) — repository + orchestrator
5. **Group 5: API Layer** (12 steps, depends on 3, 4) — AuthController + subscribers
6. **Group 6: Controller Rename + Services Wiring** (8 steps, depends on 4, 5) — rename + DI config
7. **Group 7: Test Review & Gap Analysis** (6 steps, depends on all) — QA + E2E

---

## Standards Compliance

Follow standards from `.maister/docs/standards/`:
- `global/STANDARDS.md` — File headers, PHPDoc, naming, tabs indentation, Allman braces
- `backend/STANDARDS.md` — PHP 8.2+, `declare(strict_types=1)`, readonly constructor promotion, PSR-4 under `phpbb\`, PDO prepared statements only, no SQL interpolation, no global
- `backend/REST_API.md` — `{data: {...}}` success shape, `{errors: [{field, message}]}` validation errors, `{error: string, status: int}` exception errors
- `testing/STANDARDS.md` — `#[Test]` attribute, descriptive method names `itDescribesBehavior`, `$this->createMock()` only — no DB or filesystem in unit tests

---

## Notes

- **Test-Driven**: Each group writes failing tests FIRST, then implements to make them pass
- **Run Incrementally**: Only run the group's own tests after each group — not the full `composer test`
- **Mark Progress**: Check off steps as completed by replacing `- [ ]` with `- [x]`
- **Reuse First**: `BanService`, `UserRepositoryInterface`, `PdoUserRepository`, `firebase/php-jwt`, `EventSubscriberInterface` pattern — all reused from existing code
- **Security Non-Negotiables**:
  - PDO prepared statements only — no string interpolation into SQL
  - `password_hash(PASSWORD_ARGON2ID)` — no bcrypt, no md5
  - SHA-256 hash stored for refresh tokens — never raw UUID
  - `tokenService->decodeToken()` in subscriber — never raw `JWT::decode()` with master secret
- **Key Derivation**: `TokenService` must use `hash_hmac('sha256', $context, $secret, true)` to derive separate access + elevated keys — the master `PHPBB_JWT_SECRET` is NEVER used directly in `JWT::encode()`/`JWT::decode()` calls
- **Theft Detection**: `findByHash()` must return revoked tokens so `AuthenticationService::refresh()` can detect token reuse and revoke the entire family
