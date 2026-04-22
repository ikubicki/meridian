# Work Log — M3 Auth Unified Service

## Started: 2026-04-22 — Implementation Phase

**Total Steps**: 68
**Task Groups**: 7
**Expected Tests**: 26-44

## Standards Reading Log

### Loaded Per Group
(Entries added as groups execute)

---

## 2026-04-22 — Group 1 Complete: DB Schema + Infrastructure

**Steps**: 1.1 through 1.8 completed
**Standards Applied**: global/STANDARDS.md (tabs, file headers), backend/STANDARDS.md (readonly, PDO, PSR-4), testing/STANDARDS.md (#[Test], createMock)
**Tests**: 13 passed (9 entity + 4 repository)
**Files Modified**: UserTest.php (extended), 001_auth_schema.sql (created), phpbb_dump.sql (updated), User.php (+2 fields), UserRepositoryInterface.php (+1 method), PdoUserRepository.php (+hydrate+method), PdoUserRepositoryTest.php (created)
**Notes**: New User constructor params have default=0 — backward compatible. Atomic incrementTokenGeneration with single UPDATE.

---

## 2026-04-22 — Group 2 Complete: Auth Contracts + Entities

**Steps**: 2.1 through 2.10 completed
**Standards Applied**: global/STANDARDS.md, backend/STANDARDS.md, testing/STANDARDS.md
**Tests**: 6 passed (5 RefreshToken + 1 TokenPayload), 20 assertions
**Files Created**: AuthenticationFailedException.php, InvalidRefreshTokenException.php, RefreshToken.php, TokenPayload.php, AuthenticationServiceInterface.php, TokenServiceInterface.php, AuthorizationServiceInterface.php, RefreshTokenRepositoryInterface.php, RefreshTokenTest.php, TokenPayloadTest.php
**Notes**: findByHash() returns revoked entities for theft detection. isExpired() uses DateTimeImmutable comparison. fromStdClass() casts sub/gen/pv/utype to int.

---

## 2026-04-22 — Group 3 Complete: Auth Services Core

**Steps**: 3.1 through 3.6 completed
**Standards Applied**: global/STANDARDS.md, backend/STANDARDS.md, testing/STANDARDS.md
**Tests**: 10 new passed (26 total), 42 assertions
**Files Created**: PasswordService.php (user\Service), TokenService.php (auth\Service), RefreshTokenService.php (auth\Service), PasswordServiceTest.php, TokenServiceTest.php, RefreshTokenServiceTest.php
**Notes**: TokenService uses hash_hmac derived key (NEVER raw secret). uuid4() duplicated in TokenService+RefreshTokenService (intentional, no coupling). RefreshTokenService stores SHA-256 hash of raw token. decodeToken() uses match for key selection, throws UnexpectedValueException for unknown aud.

---

## 2026-04-22 — Group 4 Complete: AuthenticationService + PdoRefreshTokenRepository

**Steps**: 4.1 through 4.6 completed
**Standards Applied**: global/STANDARDS.md, backend/STANDARDS.md, testing/STANDARDS.md
**Tests**: 12 new passed (24 total in phpbb\auth\), 55 assertions
**Files Created**: PdoRefreshTokenRepository.php, AuthenticationService.php, PdoRefreshTokenRepositoryTest.php, AuthenticationServiceTest.php
**Extra Files Created**: PasswordServiceInterface.php (user\Contract), RefreshTokenServiceInterface.php (auth\Contract) — needed because final classes cannot be mocked directly
**Files Modified**: PasswordService.php (implements PasswordServiceInterface), RefreshTokenService.php (implements RefreshTokenServiceInterface)
**Notes**: findByHash() returns revoked entities; theft detection flow: isRevoked() → revokeFamily() then throw. logout() calls both revokeAllForUser() + incrementTokenGeneration() (atomic, no race condition).

---

## 2026-04-22 — Group 5 Complete: API Layer

**Steps**: 5.1 through 5.7 completed
**Standards Applied**: global/STANDARDS.md, backend/STANDARDS.md, testing/STANDARDS.md
**Tests**: 20 new passed (48 assertions)
**Files Created**: AuthorizationService.php, AuthorizationSubscriber.php
**Files Rewritten**: AuthController.php (injects AuthenticationServiceInterface, adds elevate()), AuthControllerTest.php (6 tests with mocked service)
**Files Modified**: AuthenticationSubscriber.php (TokenService+UserRepository DI, decodeToken(), _api_user attr), AuthenticationSubscriberTest.php (new constructor, 5 new tests)
**Notes**: AuthenticationSubscriber no longer uses raw JWT::decode or resolveSecret(). Attribute is _api_user (User entity), not _api_token (stdClass). AuthorizationSubscriber exits early in M3 (no routes define _api_permission).

---

## 2026-04-22 — Group 6 Complete: Controller Rename + Services Wiring

**Steps**: 6.1 through 6.7 completed
**Standards Applied**: global/STANDARDS.md, backend/STANDARDS.md
**Tests**: 142/142 full suite passed (0 regressions)
**Files Modified**: UsersController.php (me() uses _api_user directly), TopicsController.php (_api_token→_api_user), UsersControllerTest.php (updated 2 tests), services.yaml (Auth M3 block appended)
**Notes**: ForumsController had no _api_token usage. me() no longer calls findById (entity already hydrated by subscriber). PasswordServiceInterface and RefreshTokenServiceInterface also registered.

---

## 2026-04-22 — Group 7 Complete: Test Review + E2E

**Steps**: 7.1 through 7.6 completed
**Standards Applied**: testing/STANDARDS.md
**Tests**: 145 total passed (288 assertions)
**Bugs Fixed**: phpbb_dump.sql INSERT rows updated from 69 to 71 values (token_generation + perm_version); alice user added (user_id=200, argon2id hash for 'testpass')
**Files Created**: AuthorizationSubscriberTest.php (2 tests)
**Files Modified**: TokenServiceTest.php (+1 test), api.spec.ts (+let refreshToken, +3 E2E scenarios: wrong-password 401, refresh, elevate), phpbb_dump.sql (all 57 user rows fixed + alice added)
**Notes**: itRefreshRevokesEntireFamilyOnTokenReuseDetection already existed. itUsesDistinctKeyForAccessVsElevatedToken verifies that access token cannot be decoded with phpbb-admin aud.

---

## Phase 8 Implementation COMPLETE

All 7 groups implemented. Total: 145 PHPUnit tests, 3 new E2E scenarios.
