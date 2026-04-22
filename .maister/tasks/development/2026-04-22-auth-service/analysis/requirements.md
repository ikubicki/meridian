# Requirements — M3 Auth Unified Service

## Initial Description
Implement `phpbb\auth` — unified auth service replacing current mock. Real Argon2id password verification, JWT issuance from DB, generation counter validation, ACL bitfield authorization, refresh token rotation. Endpoints: POST /auth/login, POST /auth/logout, POST /auth/refresh, POST /auth/elevate.

## Q&A

**Q: Scope elevation — include /auth/elevate?**
A: Yes, include in M3. Full elevation flow — password re-verification, elevated JWT (aud: phpbb-admin, 5-min TTL).

**Q: AuthorizationService depth?**
A: Minimal stub — interfaces exist, AuthorizationSubscriber registered, flags=0 (no route guards active). Full ACL deferred to M5 (Hierarchy).

**Q: _api_token → _api_user rename?**
A: Hard rename — update all 3 existing controllers + AuthenticationSubscriber in same PR. Subscriber hydrates User entity after gen/pv validation.

**Q: Rate limiting?**
A: Deferred from M3. Track user_login_attempts in column but throttling not enforced in M3.

**Q: PasswordService extraction?**
A: Extract to `phpbb\user\Service\PasswordService` — reusable for future registration flow.

**Q: Migration approach?**  
A: SQL file `src/phpbb/auth/Migration/001_auth_schema.sql` + update phpbb_dump.sql.

**Q: Refresh token TTL?**
A: 30 days, configurable via constructor parameter (defaultRefreshTtlDays).

**Q: User journey?**
A: SPA → POST /auth/login → accessToken + refreshToken → Bearer header on every API call → POST /auth/refresh before expiry → POST /auth/elevate for admin ops (password re-verify) → POST /auth/logout to revoke.

**Q: Similar existing code?**
A: M2 pattern: BanService (DI + interface), PdoUserRepository (PDO prepared statements, hydrate()), AuthenticationSubscriber (subscriber pattern, priority). AuthController (existing mock to replace).

**Q: Visual assets?**
A: None — backend API only.

## Functional Requirements

### FR-1: Login
- Accept POST /auth/login with `{username, password}` JSON
- Find user by username_clean (lowercase)
- Verify password via Argon2id (password_verify)
- Check ban via BanService::assertNotBanned
- Issue JWT access token (15-min) + opaque refresh token (30-day)
- Return `{ data: { accessToken, refreshToken, expiresIn: 900 } }`
- Errors: 422 missing fields, 401 invalid credentials, 403 banned

### FR-2: Logout
- Accept POST /auth/logout (requires auth)
- Revoke current refresh token family (by user from JWT sub)
- Increment token_generation in phpbb_users (invalidates all existing JWTs)
- Return 204

### FR-3: Refresh
- Accept POST /auth/refresh with `{refreshToken}`
- Verify refresh token hash exists and not revoked/expired
- Family-based rotation: revoke old token, issue new refresh token in same family
- Issue new access token with fresh gen/pv claims  
- Return `{ data: { accessToken, refreshToken, expiresIn: 900 } }`
- Errors: 422 missing, 401 invalid/expired refresh token

### FR-4: Elevate (Admin Token)
- Accept POST /auth/elevate with `{password}` (requires regular auth)
- Re-verify password (current user from JWT sub)
- Issue elevated JWT (5-min TTL, aud: phpbb-admin)
- Return `{ data: { elevatedToken, expiresIn: 300 } }`
- AuthenticationSubscriber validates aud claim on admin routes (future use)

### FR-5: Token Generation Validation (AuthenticationSubscriber)
- After JWT::decode(), fetch User by sub from UserRepository
- If jwt.gen < user.token_generation → 401 "Token revoked"
- If jwt.pv !== user.perm_version → set `_api_token_stale = true` attribute
- Set `_api_user` (User entity) on request attributes (replaces `_api_token`)
- Cache user lookup with short TTL to avoid N+1 on hot paths

### FR-6: PasswordService
- `phpbb\user\Service\PasswordService`
- `hashPassword(string $plaintext): string` — password_hash with PASSWORD_ARGON2ID
- `verifyPassword(string $plaintext, string $hash): bool` — password_verify
- `needsRehash(string $hash): bool` — password_needs_rehash

### FR-7: AuthorizationService (stub)
- `AuthorizationServiceInterface::isGranted(User $user, string $permission): bool`
- Stub implementation: return false (no permissions until M5)
- `AuthorizationSubscriber` registered at priority 8, reads route `_api_permission` attribute
- No routes have `_api_permission` in M3, subscriber fires but no-ops

### FR-8: DB Schema
- ALTER phpbb_users: ADD token_generation INT UNSIGNED DEFAULT 0, ADD perm_version INT UNSIGNED DEFAULT 0
- CREATE TABLE phpbb_auth_refresh_tokens: id, user_id, family_id(CHAR36), token_hash(CHAR64), issued_at, expires_at, revoked_at

## Non-Functional Requirements
- All passwords: Argon2id only (no legacy MD5 verification in M3)
- JWT: HS256, secret from PHPBB_JWT_SECRET env
- Refresh tokens: SHA-256 hashed in DB, raw token only returned to client
- No raw user input in SQL (PDO prepared statements)
- PHPUnit test coverage for all auth service methods
- E2E: login→refresh→logout flow, wrong password, banned user scenarios

## Scope Boundaries
**Included**: login, logout, refresh, elevate, PasswordService, TokenService, AuthenticationService, RefreshTokenRepository, AuthorizationService stub, DB schema migration SQL, hard _api_token→_api_user rename, PHPUnit tests, E2E additions.

**Excluded**: Rate limiting enforcement, LDAP/OAuth providers, full ACL bitfield from 4 tables, ACP admin controllers (no consumers), email verification on login, CSRF tokens (API is stateless).

## Reusability Opportunities
- `phpbb\user\Repository\PdoUserRepository::findByUsername()` — exists, returns full User with passwordHash
- `phpbb\user\Service\BanService::assertNotBanned()` — inject into AuthenticationService
- `phpbb\user\Contract\UserRepositoryInterface` — inject into AuthenticationService + AuthSubscriber
- `vendor/firebase/php-jwt` — JWT::encode + JWT::decode already available
- `AuthenticationSubscriber` — extend, don't replace (add gen/pv validation, add _api_user)

## Technical Considerations
- `phpbb\auth\Service\TokenService`: inject jwtSecret as constructor string (from services.yaml `%env(PHPBB_JWT_SECRET)%`)
- `phpbb\auth\Repository\PdoRefreshTokenRepository`: inject PDO (same factory as user module)
- `phpbb\auth\Service\AuthenticationService`: inject UserRepositoryInterface, BanService, TokenService, RefreshTokenRepository, PasswordService
- User entity needs 2 new fields: add to constructor + PdoUserRepository::hydrate()
- PdoUserRepository::update() allowedColumns whitelist must include `token_generation`
- AuthController must be fully DI'd (no hardcoded values after M3)
