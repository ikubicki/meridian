# Specification: M3 — Unified Auth Service (`phpbb\auth`)

## Goal

Replace mock authentication in `AuthController` with a real `phpbb\auth` service layer: Argon2id password verification, JWT issuance with generation-counter revocation, opaque refresh token family rotation, and an elevated short-lived token for admin operations.

---

## User Stories

- As an SPA user, I want to log in with username and password and receive a JWT access token + opaque refresh token so that I can authenticate subsequent API calls.
- As an SPA user, I want to silently refresh my access token before it expires so that my session persists without re-login.
- As an admin user, I want to prove my identity again with a password re-check so that sensitive admin routes can be protected by a short-lived elevated JWT.
- As a logged-in user, I want to log out so that all my tokens are invalidated server-side immediately.
- As a banned user/IP, I want to be refused login with a clear 403 so that the ban system is enforced at the auth boundary.

---

## Core Requirements

1. **Login endpoint** — verify Argon2id hash, ban check, issue access + refresh tokens.
2. **Logout endpoint** — increment `token_generation`; revoke entire refresh token family for current user.
3. **Refresh endpoint** — verify opaque token hash from DB; rotate family (revoke old, issue new); issue fresh access token with current gen/pv.
4. **Elevate endpoint** — re-verify password; issue 5-min elevated JWT (`aud: phpbb-admin`).
5. **AuthenticationSubscriber upgrade** — inject `UserRepositoryInterface`; after JWT decode, fetch User by `sub`, enforce `gen` counter, set `_api_token_stale` flag on stale `pv`; set `_api_user` (User entity) replacing `_api_token` (stdClass).
6. **PasswordService** — `hashPassword`, `verifyPassword`, `needsRehash` via PHP `PASSWORD_ARGON2ID`.
7. **AuthorizationService stub** — `isGranted()` returns `false`; `AuthorizationSubscriber` registered at priority 8 but no routes define `_api_permission` in M3 (no-op in practice).
8. **DB schema migrations** — new columns on `phpbb_users`, new `phpbb_auth_refresh_tokens` table.
9. **`_api_token` → `_api_user` rename** — hard rename in `ForumsController`, `TopicsController`, `UsersController`.
10. **`User` entity extension** — add `tokenGeneration` and `permVersion` readonly constructor fields.

---

## Visual Design

None — backend API only.

---

## Reusable Components

### Existing Code to Leverage

| Component | Path | What It Provides |
|-----------|------|-----------------|
| `BanService` | [src/phpbb/user/Service/BanService.php](../../../../src/phpbb/user/Service/BanService.php) | `assertNotBanned(userId, ip, email)` — inject directly into `AuthenticationService`; no new ban logic needed |
| `UserRepositoryInterface` / `PdoUserRepository` | [src/phpbb/user/Contract/UserRepositoryInterface.php](../../../../src/phpbb/user/Contract/UserRepositoryInterface.php) | `findByUsername()` for login lookup; `findById()` for subscriber user hydration; `update()` for `token_generation` increment |
| `PdoUserRepository::hydrate()` pattern | [src/phpbb/user/Repository/PdoUserRepository.php](../../../../src/phpbb/user/Repository/PdoUserRepository.php) | Exact template for `PdoRefreshTokenRepository::hydrate()` — array-to-entity mapping with typed casting |
| `AuthenticationSubscriber` | [src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php](../../../../src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php) | Extend in-place: already has JWT decode, public suffix list, optional-auth prefix list; add constructor DI for `UserRepositoryInterface` |
| `AuthController` | [src/phpbb/api/Controller/AuthController.php](../../../../src/phpbb/api/Controller/AuthController.php) | Replace all 3 stub methods; add `elevate()`; inject `AuthenticationServiceInterface` |
| `BannedException` | [src/phpbb/user/Exception/BannedException.php](../../../../src/phpbb/user/Exception/BannedException.php) | Catch in `AuthController::login()` → 403 response |
| `firebase/php-jwt` | `vendor/firebase/php-jwt` | Already installed — `JWT::encode()`, `JWT::decode()`, `Key`; used by existing subscriber |
| `EventSubscriberInterface` pattern | `AuthenticationSubscriber` | Template for `AuthorizationSubscriber` — same `getSubscribedEvents()` pattern |
| File header template | Any existing PHP file | `/** \n * This file is part of the phpBB4 "Meridian" package.\n * @copyright (c) Irek Kubicki...\n */` |
| `services.yaml` DI wiring | [src/phpbb/config/services.yaml](../../../../src/phpbb/config/services.yaml) | Append `phpbb\auth\*` service definitions following the `phpbb\user\*` block pattern |

### New Components Required

All new components live under `src/phpbb/auth/` because no existing `phpbb\auth` namespace exists (confirmed via file search — 0 results).

| New Component | Justification |
|---------------|---------------|
| `Contract\AuthenticationServiceInterface` | New capability; no existing auth interface exists |
| `Contract\TokenServiceInterface` | JWT lifecycle management only for this module |
| `Contract\AuthorizationServiceInterface` | ACL interface (stub impl in M3; full in M5) |
| `Contract\RefreshTokenRepositoryInterface` | DB-persisted refresh tokens — new `phpbb_auth_refresh_tokens` table |
| `Entity\RefreshToken` | Maps `phpbb_auth_refresh_tokens` rows; no existing entity applies |
| `Entity\TokenPayload` | Typed DTO for JWT claims (replaces untyped `stdClass` from `JWT::decode()`) |
| `Exception\AuthenticationFailedException` | Distinct from `BannedException`; signals wrong credentials |
| `Exception\InvalidRefreshTokenException` | Signals revoked/expired/unknown refresh token |
| `Migration\001_auth_schema.sql` | New DB schema; no migration system exists yet |
| `Repository\PdoRefreshTokenRepository` | PDO implementation for `RefreshTokenRepositoryInterface` |
| `Service\AuthenticationService` | Orchestrates login/logout/refresh/elevate flows |
| `Service\TokenService` | JWT issuance and decoding; key derivation with HMAC |
| `Service\AuthorizationService` | Stub ACL service; no existing implementation |
| `Service\RefreshTokenService` | Family rotation logic extracted from `AuthenticationService` for testability |
| `phpbb\user\Service\PasswordService` | Extracted Argon2id helper; reusable for future registration — lives under `user\Service` not `auth\` because registration will also use it |
| `phpbb\api\EventSubscriber\AuthorizationSubscriber` | New subscriber at priority 8 for route-level ACL (no-op in M3) |

---

## Technical Approach

### Key Derivation

`TokenService` derives two signing keys from `PHPBB_JWT_SECRET` via HMAC-SHA256:

```
access_key   = hash_hmac('sha256', 'jwt-access-v1',   $masterSecret, raw_output=true)
elevated_key = hash_hmac('sha256', 'jwt-elevated-v1', $masterSecret, raw_output=true)
```

This separates the key space for regular vs. elevated tokens and enables zero-downtime rotation via `kid` header (`"access-v1"`, `"elevated-v1"`).

### Refresh Token Flow

1. Client sends opaque UUID v4 string in `{refreshToken}` body.
2. `AuthenticationService::refresh()` SHA-256 hashes the raw token.
3. `PdoRefreshTokenRepository::findByHash()` looks up the hash.
4. If not found / revoked / expired → `InvalidRefreshTokenException` → 401.
5. `RefreshTokenService::rotateFamily()`:
   - Calls `revokeFamily(familyId)` to mark all tokens in this family as revoked.
   - Generates new UUID v4 raw token, same `family_id`.
   - Saves new `phpbb_auth_refresh_tokens` row with SHA-256 hash, new `issued_at`, `expires_at = now + 30 days`.
6. Fetches fresh User to read current `token_generation` and `perm_version`.
7. Issues new access token with updated `gen` and `pv` claims via `TokenService`.
8. Returns `{accessToken, refreshToken (raw), expiresIn: 900}`.

### Login Flow

1. Validate request fields (422 on missing).
2. `UserRepositoryInterface::findByUsername()` — 401 if not found.
3. `PasswordService::verifyPassword()` — 401 if mismatch.
4. `BanService::assertNotBanned(userId, ip, email)` — 403 (`BannedException`) if banned.
5. Generate new `family_id` (UUID v4), new raw refresh token (UUID v4).
6. Save `phpbb_auth_refresh_tokens` row (SHA-256 hash stored).
7. Issue access token (gen = `user.tokenGeneration`, pv = `user.permVersion`).
8. Return 200 with `{accessToken, refreshToken, expiresIn: 900}`.

### Logout Flow

1. Read JWT from `_api_user` request attribute (subscriber already validated).
2. Extract `sub` (user ID) and `jti` (token ID).
3. `PdoRefreshTokenRepository::revokeAllForUser(userId)` — marks all refresh token rows as revoked.
4. Call `UserRepositoryInterface::incrementTokenGeneration($userId)` — atomic `UPDATE SET token_generation = token_generation + 1` avoids read-modify-write race condition.
5. Return 204 — all existing JWTs become stale on next request (gen counter check).

### Elevate Flow

1. Validate `password` field (422 on missing).
2. Read current user from `_api_user` attribute.
3. `UserRepositoryInterface::findById(userId)` for fresh hash.
4. `PasswordService::verifyPassword()` — 401 on mismatch.
5. `TokenService::issueElevatedToken(user)` — 5-min JWT, `aud: phpbb-admin`, `kid: elevated-v1`.
6. Return `{elevatedToken, expiresIn: 300}`.

### AuthenticationSubscriber Upgrade

After existing `JWT::decode()` call:

1. Wrap `stdClass` claims in `TokenPayload` typed DTO.
2. Inject and call `UserRepositoryInterface::findById((int) $claims->sub)`.
3. If user not found → 401.
4. If `$claims->gen < $user->tokenGeneration` → 401 "Token revoked".
5. If `$claims->pv !== $user->permVersion` → set `_api_token_stale = true` attribute.
6. Set `_api_user` attribute with `User` entity.
7. Remove (or do not set) `_api_token` attribute.

**Audience validation**: In M3, `AuthenticationSubscriber` validates `aud === "phpbb-api"`. Elevated tokens (`aud: phpbb-admin`) are only accepted by a separate guard in `AuthorizationSubscriber` (future M5 admin routes).

### Data Flow

```
SPA → POST /auth/login
      → AuthController::login()
        → AuthenticationService::login(username, password, ip)
          → UserRepository::findByUsername()
          → PasswordService::verifyPassword()
          → BanService::assertNotBanned()
          → RefreshTokenService::issueFamily()
          → TokenService::issueAccessToken()
        ← {accessToken, refreshToken, expiresIn}
      ← 200 JSON

SPA → GET /api/v1/me (Bearer token)
      → AuthenticationSubscriber::onKernelRequest()
        → JWT::decode()
        → UserRepository::findById(sub)
        → gen/pv checks
        → sets _api_user = User entity
      → UsersController::me()
        → $request->attributes->get('_api_user')  ← User entity (not stdClass)
```

---

## Implementation Guidance

### Testing Approach

- **2–8 focused tests per implementation step group**.
- New `phpbb\auth` service tests use `$this->createMock()` — no DB, no filesystem.
- `PdoRefreshTokenRepository` tests mock `PDO` and `PDOStatement`.
- Updated `AuthControllerTest` mocks `AuthenticationServiceInterface` (not the concrete service).
- `AuthenticationSubscriberTest` extended with: gen counter rejection, pv stale flag, `_api_user` set.
- `PasswordServiceTest` uses `password_verify()` directly for assertion — no mocks needed.
- Run only the auth-related test namespaces between implementation steps, not the full suite.

### Standards Compliance

- [.maister/docs/standards/backend/STANDARDS.md](../../../../.maister/docs/standards/backend/STANDARDS.md) — PHP 8.4+, `declare(strict_types=1)`, readonly constructor promotion, Allman braces, tabs, no closing PHP tag, file headers.
- [.maister/docs/standards/backend/REST_API.md](../../../../.maister/docs/standards/backend/REST_API.md) — JSON response shape `{data: {...}}`, error shape `{errors: [{field, message}]}`, correct HTTP codes.
- [.maister/docs/standards/testing/STANDARDS.md](../../../../.maister/docs/standards/testing/STANDARDS.md) — `#[Test]` attribute, `TestCase` extends, descriptive method names (`itReturns401WhenTokenGenerationStale`).
- PDO prepared statements only — no raw SQL interpolation.
- PSR-4 under `phpbb\` namespace — all paths under `src/phpbb/`.

### Implementation Ordering

The following ordering avoids broken states:

1. **DB schema** — run `001_auth_schema.sql`, update `phpbb_dump.sql`.
2. **`User` entity + `PdoUserRepository`** — add `tokenGeneration`, `permVersion` fields and hydration.
3. **`PasswordService`** — standalone, no dependencies.
4. **`phpbb\auth` contracts** — interfaces only; no logic.
5. **`RefreshToken` entity + `PdoRefreshTokenRepository`** — DB layer.
6. **`TokenService`** — JWT issuance and decoding.
7. **`RefreshTokenService`** — family rotation logic (depends on repository + token service).
8. **`AuthenticationService`** — orchestrator (depends on all of above + BanService + UserRepository).
9. **`AuthorizationService` stub + `AuthorizationSubscriber`** — no-op, safe to add.
10. **`AuthController` replacement** — inject `AuthenticationService`; add `elevate()`.
11. **`AuthenticationSubscriber` upgrade** — inject `UserRepositoryInterface`; add gen/pv; rename attribute.
12. **Controller `_api_token` → `_api_user` rename** — `ForumsController`, `TopicsController`, `UsersController`.
13. **`services.yaml` wiring** — register all new services.
14. **Tests** — unit then update E2E.

---

## Component Specifications

### `phpbb\user\Service\PasswordService`

**Purpose**: Argon2id password hashing helper, extracted for reuse in future registration flow.

**Constructor dependencies**: none.

**Methods**:
- `hashPassword(string $plaintext): string` — returns `password_hash($plaintext, PASSWORD_ARGON2ID)`.
- `verifyPassword(string $plaintext, string $hash): bool` — returns `password_verify($plaintext, $hash)`.
- `needsRehash(string $hash): bool` — returns `password_needs_rehash($hash, PASSWORD_ARGON2ID)`.

---

### `phpbb\user\Entity\User` (modified)

Add two new readonly constructor fields (end of parameter list to avoid breaking existing call sites that use named arguments):

- `tokenGeneration: int` — maps to `phpbb_users.token_generation`.
- `permVersion: int` — maps to `phpbb_users.perm_version`.

**Default value in `PdoUserRepository::hydrate()`**: `(int) ($row['token_generation'] ?? 0)`.

---

### `phpbb\user\Repository\PdoUserRepository` (modified)

- Add `token_generation` and `perm_version` to `hydrate()`.
- Add `'token_generation'` → `'token_generation'` and `'perm_version'` → `'perm_version'` to `update()` `$allowedColumns` map.
- Add `incrementTokenGeneration(int $userId): void` as a dedicated method (single-statement `UPDATE ... SET token_generation = token_generation + 1 WHERE user_id = :id`) — avoids a read-modify-write race condition compared to using `update()`.

**`incrementTokenGeneration` signature**:
```
public function incrementTokenGeneration(int $userId): void
```
This must be added to `UserRepositoryInterface` as well.

---

### `phpbb\auth\Entity\RefreshToken`

**Purpose**: Immutable value object mapping a `phpbb_auth_refresh_tokens` row.

**Constructor fields**:
- `id: int`
- `userId: int`
- `familyId: string` (UUID v4, CHAR36)
- `tokenHash: string` (SHA-256 hex, CHAR64)
- `issuedAt: \DateTimeImmutable`
- `expiresAt: \DateTimeImmutable`
- `revokedAt: ?\DateTimeImmutable`

**Computed methods**:
- `isRevoked(): bool` — returns `$this->revokedAt !== null`.
- `isExpired(): bool` — returns `$this->expiresAt <= new \DateTimeImmutable()`.
- `isValid(): bool` — returns `!$this->isRevoked() && !$this->isExpired()`.

---

### `phpbb\auth\Entity\TokenPayload`

**Purpose**: Typed DTO wrapping the `stdClass` claims from `JWT::decode()`. Prevents accidental access to undefined properties.

**Constructor fields**:
- `iss: string`
- `sub: int`
- `aud: string`
- `iat: int`
- `exp: int`
- `jti: string`
- `gen: int`
- `pv: int`
- `utype: int`
- `flags: string`

**Static factory**: `fromStdClass(\stdClass $claims): self`.

---

### `phpbb\auth\Exception\AuthenticationFailedException`

Extends `\RuntimeException`. No additional methods. Used when username not found or password mismatch. Controller maps to 401.

---

### `phpbb\auth\Exception\InvalidRefreshTokenException`

Extends `\RuntimeException`. No additional methods. Used when refresh token hash not in DB, or revoked, or expired. Controller maps to 401.

---

### `phpbb\auth\Contract\AuthenticationServiceInterface`

```
login(string $username, string $password, string $ip): array
    // Returns: ['accessToken' => string, 'refreshToken' => string, 'expiresIn' => int]
    // Throws: AuthenticationFailedException (401), BannedException (403)

logout(int $userId): void

refresh(string $rawRefreshToken): array
    // Returns: ['accessToken' => string, 'refreshToken' => string, 'expiresIn' => int]
    // Throws: InvalidRefreshTokenException (401)

elevate(int $userId, string $password): array
    // Returns: ['elevatedToken' => string, 'expiresIn' => int]
    // Throws: AuthenticationFailedException (401)
```

---

### `phpbb\auth\Contract\TokenServiceInterface`

```
issueAccessToken(User $user): string
    // Returns signed HS256 JWT, aud="phpbb-api", TTL=900s

issueElevatedToken(User $user): string
    // Returns signed HS256 JWT, aud="phpbb-admin", TTL=300s

decodeToken(string $rawToken, string $expectedAud): TokenPayload
    // Wraps JWT::decode(); validates aud; throws UnexpectedValueException on failure
```

---

### `phpbb\auth\Contract\AuthorizationServiceInterface`

```
isGranted(User $user, string $permission): bool
    // M3: always returns false
```

---

### `phpbb\auth\Contract\RefreshTokenRepositoryInterface`

```
save(RefreshToken $token): void
findByHash(string $hash): ?RefreshToken          // hash = SHA-256 hex; returns revoked tokens too (required for theft detection)
revokeByHash(string $hash): void                 // revokes a single token by its hash (used during normal rotation)
revokeFamily(string $familyId): void             // sets revoked_at = NOW() for all in family (used on theft detection)
revokeAllForUser(int $userId): void              // sets revoked_at = NOW() for all user tokens
deleteExpired(): void                            // prune rows where expires_at < NOW() (for cron)
```

---

### `phpbb\auth\Repository\PdoRefreshTokenRepository`

**Constructor**: `__construct(private readonly \PDO $pdo)`.

**Table constant**: `private const TABLE = 'phpbb_auth_refresh_tokens'`.

**`save()`**: INSERT prepared statement with all token fields; `issued_at` and `expires_at` stored as Unix timestamps (INT).

**`findByHash()`**: `SELECT * WHERE token_hash = :hash LIMIT 1`; hydrate with private `hydrate(array $row): RefreshToken`. **Do NOT filter by `revoked_at IS NULL`** — the service layer must receive the revoked entity to detect token theft (reuse of a previously rotated token) and revoke the entire family.

**`revokeFamily()`**: `UPDATE ... SET revoked_at = UNIX_TIMESTAMP() WHERE family_id = :familyId AND revoked_at IS NULL`.

**`revokeByHash()`**: `UPDATE ... SET revoked_at = UNIX_TIMESTAMP() WHERE token_hash = :hash AND revoked_at IS NULL`.

**`revokeAllForUser()`**: `UPDATE ... SET revoked_at = UNIX_TIMESTAMP() WHERE user_id = :userId AND revoked_at IS NULL`.

**`deleteExpired()`**: `DELETE WHERE expires_at < UNIX_TIMESTAMP() AND revoked_at IS NOT NULL`.

---

### `phpbb\auth\Service\TokenService`

**Constructor**:
```
__construct(
    private readonly string $jwtSecret,
    private readonly int $accessTtl = 900,
    private readonly int $elevatedTtl = 300,
)
```

**Key derivation** (private method `deriveKey(string $context): string`):
```
hash_hmac('sha256', $context, $this->jwtSecret, true)
```
Used with contexts `'jwt-access-v1'` and `'jwt-elevated-v1'`.

**`issueAccessToken(User $user): string`**: Build claims array with all required claims (see JWT spec below); call `JWT::encode($claims, new Key($key, 'HS256'))` with the derived key. Set `kid: "access-v1"` in payload (firebase/php-jwt does not support header-only kid, so include in claims for M3).

**`issueElevatedToken(User $user): string`**: Same as above, `aud: "phpbb-admin"`, `exp: now + 300`, `kid: "elevated-v1"`, additional `scope: ["acp", "mcp"]` claim.

**`decodeToken(string $rawToken, string $expectedAud): TokenPayload`**: Call `JWT::decode($rawToken, new Key($key, 'HS256'))`; validate `$claims->aud === $expectedAud`; return `TokenPayload::fromStdClass($claims)`. Key selection based on `$expectedAud`.

---

### `phpbb\auth\Service\RefreshTokenService`

**Purpose**: Family rotation logic extracted for testability.

**Constructor**:
```
__construct(
    private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
    private readonly int $refreshTtlDays = 30,
)
```

**`issueFamily(int $userId): array`**: Generate `family_id = uuid_v4()`, `raw = uuid_v4()`, hash = `hash('sha256', $raw)`. Save `RefreshToken` entity. Return `['rawToken' => $raw, 'familyId' => $familyId]`.

**`rotateFamily(RefreshToken $existingToken): array`**: Mark the old token as revoked via a single `UPDATE ... SET revoked_at = now() WHERE token_hash = :hash` (dedicated `revokeByHash(string $hash): void` on the repository — add to `RefreshTokenRepositoryInterface`). Generate new raw UUID v4 token in the same `family_id`. Save new entity. Return `['rawToken' => $newRaw]`.

**UUID v4 generation**: Private helper `uuid4(): string` using `sprintf('%s-%s-4%s-%s%s-%s', ...)` with `random_bytes()`.

---

### `phpbb\auth\Service\AuthenticationService`

**Constructor**:
```
__construct(
    private readonly UserRepositoryInterface $userRepository,
    private readonly PasswordService $passwordService,
    private readonly BanService $banService,
    private readonly TokenService $tokenService,
    private readonly RefreshTokenService $refreshTokenService,
    private readonly RefreshTokenRepositoryInterface $refreshTokenRepository,
)
```

**`login()`**: Flow described in Technical Approach. Catches `BannedException` — rethrows as-is (controller catches it separately).

**`logout()`**: Flow described in Technical Approach; calls `revokeAllForUser()` then `incrementTokenGeneration()` on the user repository.

**`refresh()`**: Hash raw token; `findByHash()`:
- null → throw `InvalidRefreshTokenException` (unknown token)
- `isRevoked()` → `revokeFamily($token->familyId)` (theft: caller re-used a rotated token) → throw `InvalidRefreshTokenException`
- `isExpired()` → throw `InvalidRefreshTokenException`
- valid → `rotateFamily($token)` → fetch user → `issueAccessToken()`.

**`elevate()`**: `findById(userId)`; `verifyPassword()`; `issueElevatedToken()`.

---

### `phpbb\auth\Service\AuthorizationService`

**Constructor**: `__construct()` — no dependencies for M3 stub.

**`isGranted(User $user, string $permission): bool`**: Returns `false` unconditionally.

---

### `phpbb\api\EventSubscriber\AuthorizationSubscriber`

**Constructor**:
```
__construct(
    private readonly AuthorizationServiceInterface $authorizationService,
)
```

**`getSubscribedEvents()`**: `[KernelEvents::REQUEST => ['onKernelRequest', 8]]`.

**`onKernelRequest()`**: Read `_api_permission` from current route defaults; if absent → return (no-op). If present: read `_api_user` from request attributes; call `isGranted()`; if false → 403 JsonResponse. In M3, no routes define `_api_permission`, so this always exits early.

---

### `phpbb\api\EventSubscriber\AuthenticationSubscriber` (modified)

**New constructor parameters**:
- `private readonly UserRepositoryInterface $userRepository`
- `private readonly TokenServiceInterface $tokenService`

**IMPORTANT — Key derivation fix (C1)**: Replace existing `JWT::decode($rawToken, new Key($secret, 'HS256'))` call with `$this->tokenService->decodeToken($rawToken, 'phpbb-api')`. This ensures the derived HMAC key (not the raw secret) is used for verification, matching `TokenService::issueAccessToken()` behavior. Do NOT use raw `JWT::decode()` in the subscriber — token signatures will always fail.

**`onKernelRequest()` additions** (replace old `JWT::decode()` + `_api_token` set):
1. Call `$payload = $this->tokenService->decodeToken($rawToken, 'phpbb-api')` — validates signature, expiry, audience, and wraps claims into `TokenPayload`.
2. `$user = $this->userRepository->findById($payload->sub)` — if null → 401.
3. `if ($payload->gen < $user->tokenGeneration)` → 401 "Token revoked".
4. `if ($payload->pv !== $user->permVersion)` → `$request->attributes->set('_api_token_stale', true)`.
5. `$request->attributes->set('_api_user', $user)` — replaces old `_api_token` set.
6. Remove (do NOT set) `_api_token` attribute — hard rename complete.

---

### `phpbb\api\Controller\AuthController` (replaced)

**Constructor**:
```
__construct(
    private readonly AuthenticationServiceInterface $authService,
)
```

**`login(Request $request): JsonResponse`**:
- Validate `username` and `password` fields → 422 if missing.
- Call `$this->authService->login($body['username'], $body['password'], $request->getClientIp() ?? '')`.
- Catch `AuthenticationFailedException` → 401.
- Catch `BannedException` → 403.
- Return 200 `{data: {accessToken, refreshToken, expiresIn}}`.

**`logout(Request $request): Response`**:
- Read `_api_user` attribute (User entity).
- Call `$this->authService->logout($user->id)`.
- Return 204.

**`refresh(Request $request): JsonResponse`**:
- Validate `refreshToken` field → 422 if missing.
- Call `$this->authService->refresh($body['refreshToken'])`.
- Catch `InvalidRefreshTokenException` → 401.
- Return 200 `{data: {accessToken, refreshToken, expiresIn}}`.

**`elevate(Request $request): JsonResponse`** (NEW route):
- Route: `POST /auth/elevate`, name `api_v1_auth_elevate`.
- Validate `password` field → 422 if missing.
- Read `_api_user` attribute.
- Call `$this->authService->elevate($user->id, $body['password'])`.
- Catch `AuthenticationFailedException` → 401.
- Return 200 `{data: {elevatedToken, expiresIn}}`.

**Route attributes remain on methods** (not controller class).

---

### Controllers: `_api_token` → `_api_user` rename

**`UsersController::me()`** (already reads `_api_token`):
- Replace `$request->attributes->get('_api_token')` with `$request->attributes->get('_api_user')`.
- Instead of `$token?->sub`, use `$user?->id` directly (User entity, not stdClass).
- Remove manual null check on `$userId <= 0`; check `$user === null` instead.
- Remove `$this->userSearchService->findById($userId)` — user is already the hydrated entity; read directly from `$user`.

**`ForumsController`**: Currently no `_api_token` read — no change needed (uses no auth attributes).

**`TopicsController`**: Search for `_api_token` usage — update any occurrence to `_api_user` with entity access pattern.

---

## DB Schema Changes

### `001_auth_schema.sql`

```sql
-- File: src/phpbb/auth/Migration/001_auth_schema.sql
-- M3: Add token_generation and perm_version columns to phpbb_users.
-- M3: Create phpbb_auth_refresh_tokens table.

ALTER TABLE phpbb_users
    ADD COLUMN token_generation INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN perm_version     INT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE phpbb_auth_refresh_tokens
(
    id         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED    NOT NULL,
    family_id  CHAR(36)        NOT NULL,
    token_hash CHAR(64)        NOT NULL,
    issued_at  INT UNSIGNED    NOT NULL,
    expires_at INT UNSIGNED    NOT NULL,
    revoked_at INT UNSIGNED    NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_token_hash (token_hash),
    KEY idx_family (family_id),
    KEY idx_user (user_id),
    KEY idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

`phpbb_dump.sql` must be updated to reflect these changes so fresh installs include the schema.

---

## API Contract

### POST `/auth/login`

**Auth**: none (public suffix).

**Request**:
```json
{ "username": "alice", "password": "s3cr3t" }
```

**Success 200**:
```json
{
  "data": {
    "accessToken":  "<signed JWT>",
    "refreshToken": "<opaque UUID v4>",
    "expiresIn":    900
  }
}
```

**Errors**:

| HTTP | Condition | Body |
|------|-----------|------|
| 422 | Missing `username` or `password` | `{errors: [{field, message}]}` |
| 401 | Unknown username or wrong password | `{error: "Invalid credentials", status: 401}` |
| 403 | User, IP, or email banned | `{error: "Account is banned", status: 403}` |

---

### POST `/auth/logout`

**Auth**: Bearer access token (required, standard route).

**Request**: empty body.

**Success 204**: no body.

**Errors**:

| HTTP | Condition |
|------|-----------|
| 401 | Missing or invalid token (handled by subscriber) |

---

### POST `/auth/refresh`

**Auth**: none (public suffix — `/auth/refresh` in `PUBLIC_SUFFIXES`).

**Request**:
```json
{ "refreshToken": "<opaque UUID v4>" }
```

**Success 200**:
```json
{
  "data": {
    "accessToken":  "<new signed JWT>",
    "refreshToken": "<new opaque UUID v4>",
    "expiresIn":    900
  }
}
```

**Errors**:

| HTTP | Condition |
|------|-----------|
| 422 | Missing `refreshToken` field |
| 401 | Token not found, revoked, or expired |

---

### POST `/auth/elevate`

**Auth**: Bearer access token (required, standard route).

**Request**:
```json
{ "password": "s3cr3t" }
```

**Success 200**:
```json
{
  "data": {
    "elevatedToken": "<signed JWT, aud=phpbb-admin>",
    "expiresIn":     300
  }
}
```

**Errors**:

| HTTP | Condition |
|------|-----------|
| 401 | Missing or invalid Bearer token (subscriber) |
| 401 | Password re-verification failed |
| 422 | Missing `password` field |

---

## Error Handling

| Exception | Thrown By | HTTP Code | Response |
|-----------|-----------|-----------|----------|
| `AuthenticationFailedException` | `AuthenticationService::login/elevate` | 401 | `{error: "Invalid credentials", status: 401}` |
| `InvalidRefreshTokenException` | `AuthenticationService::refresh` | 401 | `{error: "Invalid or expired refresh token", status: 401}` |
| `BannedException` | `BanService::assertNotBanned` (via login) | 403 | `{error: "Account is banned", status: 403}` |
| `ExpiredException` (firebase) | `AuthenticationSubscriber` | 401 | `{error: "Token expired", status: 401}` |
| `SignatureInvalidException` | `AuthenticationSubscriber` | 401 | `{error: "Invalid token signature", status: 401}` |
| `UnexpectedValueException` | `AuthenticationSubscriber` | 401 | `{error: "Invalid token", status: 401}` |
| gen counter mismatch | `AuthenticationSubscriber` | 401 | `{error: "Token revoked", status: 401}` |

All exceptions are caught in the controller (for `AuthController` methods) or in the subscriber (for middleware checks). No exceptions reach the Symfony kernel error handler from the auth layer.

---

## JWT Token Specification

### Access Token Claims

| Claim | Value | Notes |
|-------|-------|-------|
| `iss` | `"phpbb"` | Validated on decode |
| `sub` | `int` | User ID |
| `aud` | `"phpbb-api"` | Validated in subscriber |
| `iat` | `time()` | Unix timestamp |
| `exp` | `time() + 900` | Auto-validated by firebase/php-jwt |
| `jti` | UUID v4 | Unique token ID |
| `gen` | `user->tokenGeneration` | Revocation counter |
| `pv` | `user->permVersion` | Permission freshness |
| `utype` | `user->type->value` | UserType enum int |
| `flags` | `"AAAAAAA="` | Base64 zero-bitfield (M3 stub; full in M5) |
| `kid` | `"access-v1"` | Key identifier |

### Elevated Token Claims

Same as access, plus:

| Claim | Value |
|-------|-------|
| `aud` | `"phpbb-admin"` |
| `exp` | `time() + 300` |
| `kid` | `"elevated-v1"` |
| `scope` | `["acp", "mcp"]` |

---

## Testing Requirements

### Unit Tests

#### `PasswordServiceTest` (new)
1. `hashReturnsValidArgon2idHash` — `password_get_info()` algorithm === `PASSWORD_ARGON2ID`.
2. `verifyReturnsTrueForCorrectPassword`.
3. `verifyReturnsFalseForWrongPassword`.
4. `needsRehashReturnsFalseForFreshHash`.

#### `TokenServiceTest` (new)
1. `issueAccessTokenReturnsThreeSegmentString`.
2. `issueAccessTokenSubClaimMatchesUserId`.
3. `issueAccessTokenAudIsPhpbbApi`.
4. `issueElevatedTokenAudIsPhpbbAdmin`.
5. `issueElevatedTokenExpiresInFiveMinutes`.
6. `decodeTokenReturnsTokenPayloadWithCorrectSub`.
7. `decodeTokenThrowsOnWrongAud`.

#### `RefreshTokenServiceTest` (new)
1. `issueFamilyReturnsDifferentRawTokenEachCall`.
2. `issueFamilySavesHashedTokenToRepository`.
3. `rotateFamilyRevokesOldFamilyBeforeIssuingNew`.
4. `rotateFamilyReturnsNewRawToken`.

#### `AuthenticationServiceTest` (new)
1. `loginReturnsTokensOnValidCredentials`.
2. `loginThrowsAuthenticationFailedExceptionOnUnknownUser`.
3. `loginThrowsAuthenticationFailedExceptionOnWrongPassword`.
4. `loginThrowsBannedExceptionWhenBanCheckFails`.
5. `logoutRevokesAllUserTokensAndIncrementsGeneration`.
6. `refreshThrowsInvalidRefreshTokenExceptionWhenHashNotFound`.
7. `refreshThrowsInvalidRefreshTokenExceptionWhenRevoked`.
8. `refreshReturnsNewTokens`.
9. `elevateThrowsAuthenticationFailedExceptionOnWrongPassword`.
10. `elevateReturnsElevatedToken`.

#### `PdoRefreshTokenRepositoryTest` (new)
1. `savePersistsTokenRowToDatabase`.
2. `findByHashReturnsNullWhenNotFound`.
3. `findByHashReturnsRefreshTokenEntityWhenFound`.
4. `findByHashReturnsRevokedEntityWhenTokenIsRevoked` — revoked token must be returned (NOT null) so theft detection can call `revokeFamily()`. Verifying `$token->isRevoked() === true`.
5. `revokeFamilySetsRevokedAtOnAllFamilyTokens`.
6. `revokeAllForUserSetsRevokedAtOnAllUserTokens`.

#### `AuthControllerTest` (rewrite)
1. `loginReturns200WithValidTokensFromService`.
2. `loginReturns422WhenFieldsMissing`.
3. `loginReturns401WhenAuthenticationFails`.
4. `loginReturns403WhenBanned`.
5. `logoutReturns204`.
6. `refreshReturns200WithNewTokens`.
7. `refreshReturns422WhenRefreshTokenMissing`.
8. `refreshReturns401WhenInvalidRefreshToken`.
9. `elevateReturns200WithElevatedToken`.
10. `elevateReturns401WhenPasswordWrong`.
11. `elevateReturns422WhenPasswordMissing`.

#### `AuthenticationSubscriberTest` (extended — add to existing file)
1. `itSets_api_userAttributeAfterSuccessfulValidation`.
2. `itReturns401WhenTokenGenerationIsStale`.
3. `itSetsTokenStaleWhenPermVersionMismatch`.
4. `itReturns401WhenSubUserNotFoundInDatabase`.
5. `itRejects401WhenAudIsPhpbbAdmin` (elevated token on standard route).

---

## Acceptance Criteria

1. `POST /auth/login` with valid phpBB credentials returns a 3-segment JWT access token and a UUID-format opaque refresh token.
2. The access token decodes to claims containing correct `sub` (user ID), `gen`, `pv`, `aud: "phpbb-api"`, `exp = iat + 900`.
3. `POST /auth/refresh` with the returned refresh token issues a new access token and a new refresh token; the old refresh token is revoked in `phpbb_auth_refresh_tokens`.
4. `POST /auth/logout` sets `phpbb_users.token_generation` to current + 1; subsequent requests with the old access token are rejected with 401 "Token revoked".
5. `POST /auth/elevate` with correct password returns a JWT with `aud: "phpbb-admin"` and TTL ≤ 300 seconds.
6. `GET /api/v1/me` with a valid Bearer token returns the authenticated user's profile — `request->attributes->get('_api_user')` is a `User` entity, not a `stdClass`.
7. `POST /auth/login` for a banned user/IP returns 403.
8. `POST /auth/login` with wrong password returns 401.
9. All new `phpbb\auth\*` unit tests pass; all existing tests in `tests/phpbb/` continue to pass.
10. `phpbb_auth_refresh_tokens` table and new `phpbb_users` columns exist in `phpbb_dump.sql`.

---

## Out of Scope

- Legacy MD5 password migration (Argon2id-only in M3).
- Rate limiting on login attempts (tracked via `user_login_attempts` column but not enforced).
- JTI deny list (generation counter is the primary revocation mechanism; deny list deferred).
- Forum-scoped ACL resolution (`getGrantedForums()`, `isGrantedAny()`) — M5.
- Full `flags` bitfield computation from `phpbb_acl_*` tables — M5.
- `AclCacheRepository` — M5.
- `POST /auth/signup` endpoint — separate milestone.
- HttpOnly cookie transport for tokens — SPA uses Bearer header only in M3.
- Multi-device session management UI.

---

## Implementation Notes

### Gotchas

1. **`User` is `final readonly`** — adding constructor parameters is backward-compatible only if using named arguments everywhere; verify no positional-argument instantiation exists in tests or fixtures before adding `tokenGeneration` and `permVersion`.
2. **`_api_token` → `_api_user` is a breaking change** — the subscriber and all three controllers must be updated atomically. If `_api_token` is removed by the subscriber but a controller still reads it, a `null` entity will silently pass auth checks. Update all files in one implementation step.
3. **`UserRepositoryInterface::incrementTokenGeneration()`** — this method must be added to the interface AND the `PdoUserRepository` implementation. `AuthenticationService` depends on the interface, not the concrete class.
4. **SHA-256 hex is 64 chars** — `hash('sha256', $raw)` produces a 64-character hex string. `CHAR(64)` in MySQL is appropriate; do not use `BINARY` to avoid collation issues with `findByHash()` prepared statements.
5. **`family_id` is CHAR(36)** — UUID v4 in canonical `xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx` format, 36 chars with hyphens.
6. **`revoked_at` is Unix timestamp (INT)** — consistent with the existing phpBB convention of storing timestamps as integers, not `DATETIME` columns; matches `issued_at` and `expires_at`.
7. **`$request->getClientIp()`** — returns `null` if the request has no IP (CLI context); `login()` must pass `'' ` as fallback to avoid type errors.
8. **`AuthorizationSubscriber` priority 8** — fires after `AuthenticationSubscriber` (priority 16, higher = earlier). `_api_user` will be set by the time `AuthorizationSubscriber` runs.
9. **services.yaml `$jwtSecret` parameter** — `TokenService` must be explicitly wired in `services.yaml` with `arguments: $jwtSecret: '%env(PHPBB_JWT_SECRET)%'` since autowire cannot inject string scalars.
10. **`flags` claim in M3** — use a fixed base64-encoded zero string (`base64_encode(str_repeat("\0", 12))`) as placeholder. Do not attempt to compute real bitfields in M3.

---

## services.yaml Wiring (I1 fix)

Append the following block to `src/phpbb/config/services.yaml` after the `phpbb\user\*` section:

```yaml
# --- Auth module ---
phpbb\auth\Service\TokenService:
    arguments:
        $jwtSecret: '%env(PHPBB_JWT_SECRET)%'

phpbb\auth\Service\RefreshTokenService: ~

phpbb\auth\Service\AuthenticationService: ~

phpbb\auth\Service\AuthorizationService: ~

phpbb\auth\Contract\AuthenticationServiceInterface:
    alias: phpbb\auth\Service\AuthenticationService
    public: true

phpbb\auth\Contract\AuthorizationServiceInterface:
    alias: phpbb\auth\Service\AuthorizationService
    public: true

phpbb\auth\Contract\TokenServiceInterface:
    alias: phpbb\auth\Service\TokenService
    public: true

phpbb\auth\Repository\PdoRefreshTokenRepository: ~

phpbb\auth\Contract\RefreshTokenRepositoryInterface:
    alias: phpbb\auth\Repository\PdoRefreshTokenRepository
    public: true

# PasswordService lives in user module (reusable for registration)
phpbb\user\Service\PasswordService: ~
```

---

## E2E Test Additions (I4 fix)

Append the following scenarios to `tests/e2e/api.spec.ts`:

1. **`POST /auth/login — wrong password returns 401`** — login with valid username but wrong password; expect 401, no token in response.
2. **`POST /auth/refresh — returns new access token`** — after initial login, POST /auth/refresh with the refresh token from login; expect 200, new accessToken (different from original), new refreshToken. Store new accessToken for subsequent tests.
3. **`POST /auth/logout — invalidates token`** — POST /auth/logout with Bearer; expect 204. Then GET /me with old token; expect 401 (token revoked via gen counter increment).
4. **`POST /auth/elevate — wrong password returns 401`** — POST /auth/elevate with Bearer but wrong password body; expect 401.
5. **`POST /auth/elevate — returns elevated JWT`** — POST /auth/elevate with correct password; expect 200, elevatedToken with 3 segments, expiresIn=300.

