# JWT Patterns — Findings

## 1. firebase/php-jwt Library API (v6.x, PHP ^8.0)

### 1.1 Core API Surface

**Encoding (signing) a token:**
```php
use Firebase\JWT\JWT;

public static function encode(
    array $payload,                           // Claims array
    string|OpenSSLAsymmetricKey|OpenSSLCertificate $key,  // Secret or private key
    string $alg,                              // Algorithm identifier
    ?string $keyId = null,                    // Optional 'kid' header
    ?array $head = null                       // Additional header fields
): string;
```

**Decoding (verifying) a token:**
```php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

public static function decode(
    string $jwt,                              // The JWT string
    Key|ArrayAccess|array $keyOrKeyArray,      // Key or kid=>Key map
    ?stdClass &$headers = null                // OUT: decoded headers
): stdClass;                                  // Returns payload as object
```

**Key wrapper:**
```php
use Firebase\JWT\Key;

new Key(
    string|OpenSSLAsymmetricKey|OpenSSLCertificate $keyMaterial,
    string $algorithm   // Must match token's alg header exactly
);
```

Key point: The `Key` object **binds** an algorithm to key material. During decode, the library verifies `$key->getAlgorithm() === $header->alg`. This **prevents algorithm confusion attacks** by design.

### 1.2 Supported Algorithms

| Algorithm | Type | Method | Min Key Length |
|-----------|------|--------|---------------|
| HS256 | Symmetric | hash_hmac SHA256 | 256 bits (32 bytes) |
| HS384 | Symmetric | hash_hmac SHA384 | 384 bits (48 bytes) |
| HS512 | Symmetric | hash_hmac SHA512 | 512 bits (64 bytes) |
| RS256 | Asymmetric | openssl SHA256 | 2048 bits RSA key |
| RS384 | Asymmetric | openssl SHA384 | 2048 bits RSA key |
| RS512 | Asymmetric | openssl SHA512 | 2048 bits RSA key |
| ES256 | Asymmetric | openssl SHA256 | P-256 curve |
| ES384 | Asymmetric | openssl SHA384 | P-384 curve |
| ES256K | Asymmetric | openssl SHA256 | secp256k1 curve |
| EdDSA | Asymmetric | sodium Ed25519 | Ed25519 key pair |

### 1.3 Built-in Claims Validation

The library **automatically validates** these claims during `decode()`:

| Claim | Validation | Exception |
|-------|-----------|-----------|
| `exp` | `(timestamp - leeway) >= exp` → expired | `ExpiredException` |
| `nbf` | `floor(nbf) > (timestamp + leeway)` → too early | `BeforeValidException` |
| `iat` | Only checked if `nbf` is absent; `floor(iat) > (timestamp + leeway)` | `BeforeValidException` |

**NOT validated by library** (must be done in application code):
- `iss` (issuer)
- `aud` (audience)
- `sub` (subject)
- `jti` (JWT ID)
- Any custom claims

### 1.4 Exception Hierarchy

```
\UnexpectedValueException
├── Firebase\JWT\ExpiredException         (implements JWTExceptionWithPayloadInterface)
│   ├── getPayload(): object              — access expired token's payload
│   └── getTimestamp(): ?int              — when the check was performed
├── Firebase\JWT\BeforeValidException     (implements JWTExceptionWithPayloadInterface)
│   └── getPayload(): object              — access not-yet-valid token's payload
└── Firebase\JWT\SignatureInvalidException — signature verification failed

\DomainException                          — algorithm errors, key problems
\InvalidArgumentException                 — empty key, wrong key type
\TypeError                                — wrong key material type
```

Key insight: `ExpiredException` provides `getPayload()` — useful for extracting the user_id from expired tokens during refresh flow.

### 1.5 Clock Skew / Leeway

```php
JWT::$leeway = 60; // seconds — static, applies to all decode() calls
JWT::$timestamp = null; // Override current time (useful for testing)
```

### 1.6 Multiple Keys Support (Key Rotation)

```php
$keys = [
    'key-2026-04' => new Key($currentKey, 'HS256'),
    'key-2026-03' => new Key($previousKey, 'HS256'),  // Accept old tokens during rotation
];

// During encode, specify kid:
$jwt = JWT::encode($payload, $currentKey, 'HS256', 'key-2026-04');

// During decode, library matches kid from header to key array:
$decoded = JWT::decode($jwt, $keys);
```

---

## 2. JWT Standard Claims (RFC 7519) & Forum Application Design

### 2.1 Standard Registered Claims

| Claim | Full Name | Usage for phpBB |
|-------|-----------|-----------------|
| `iss` | Issuer | `"phpbb"` — identifies this system |
| `sub` | Subject | User ID (numeric string): `"42"` |
| `aud` | Audience | `"phpbb-api"` or `["phpbb-api", "phpbb-admin"]` |
| `exp` | Expiration | Unix timestamp when token becomes invalid |
| `nbf` | Not Before | Unix timestamp when token becomes valid |
| `iat` | Issued At | Unix timestamp of token creation |
| `jti` | JWT ID | Unique token identifier (UUID v4) for revocation tracking |

### 2.2 Custom Claims for phpBB Forum

**User Token (standard access):**
```php
$payload = [
    // Standard claims
    'iss' => 'phpbb',
    'sub' => (string) $user_id,
    'aud' => 'phpbb-api',
    'iat' => time(),
    'exp' => time() + 900,          // 15 minutes
    'nbf' => time(),
    'jti' => $this->generateJti(),  // UUID v4

    // Custom claims
    'type' => 'access',             // Token type discriminator
    'username' => $username,        // For display (non-authoritative)
    'group_id' => $default_group,   // User's default group
    'groups' => [5, 7, 12],         // All group memberships (IDs)
    'role' => 'user',               // Role level: guest|user|mod|admin
];
```

**Group/Elevated Token (admin/mod access):**
```php
$payload = [
    'iss' => 'phpbb',
    'sub' => (string) $user_id,
    'aud' => 'phpbb-admin',         // Different audience!
    'iat' => time(),
    'exp' => time() + 300,          // 5 minutes — shorter for elevated
    'nbf' => time(),
    'jti' => $this->generateJti(),

    // Custom claims
    'type' => 'elevated',           // Token type discriminator
    'username' => $username,
    'group_id' => $elevated_group,  // The group granting elevated access
    'role' => 'admin',              // 'mod' or 'admin'
    'scope' => ['acp', 'mcp'],      // Granted scopes
    'elevation_jti' => $parent_jti, // Links to original access token
];
```

### 2.3 Token Lifetime Recommendations

| Token Type | Lifetime | Rationale |
|-----------|----------|-----------|
| Access token (user) | 15 minutes | Short-lived, limits exposure window |
| Access token (elevated) | 5 minutes | High-privilege, minimize attack surface |
| Refresh token | 7 days | Stored server-side (DB), enables re-auth |
| Remember-me token | 30 days | Persistent login, stored as secure cookie |

---

## 3. Access + Refresh Token Pattern

### 3.1 Standard Flow

```
┌─────────┐                    ┌─────────────┐                ┌──────┐
│ Client  │                    │ Auth Service │                │  DB  │
└────┬────┘                    └──────┬───────┘                └──┬───┘
     │ POST /auth/login               │                           │
     │ {username, password}            │                           │
     ├────────────────────────────────►│                           │
     │                                 │ Verify credentials        │
     │                                 ├──────────────────────────►│
     │                                 │◄─────── user record ──────┤
     │                                 │                           │
     │                                 │ Generate access + refresh │
     │                                 │ Store refresh in DB       │
     │                                 ├──────────────────────────►│
     │◄───── {access_token,            │                           │
     │        refresh_token,           │                           │
     │        expires_in: 900}         │                           │
     │                                 │                           │
     │ GET /api/forums (Bearer token)  │                           │
     ├────────────────────────────────►│                           │
     │                                 │ Verify JWT signature+exp  │
     │◄───── 200 OK ──────────────────┤ (no DB hit needed)        │
     │                                 │                           │
     │ POST /auth/refresh              │                           │
     │ {refresh_token}                 │                           │
     ├────────────────────────────────►│                           │
     │                                 │ Validate refresh in DB    │
     │                                 ├──────────────────────────►│
     │                                 │ Rotate: invalidate old,   │
     │                                 │         issue new pair    │
     │                                 ├──────────────────────────►│
     │◄───── {new_access_token,        │                           │
     │        new_refresh_token}       │                           │
```

### 3.2 Refresh Token Storage (Server-Side)

Refresh tokens are **opaque** (not JWT) or JWT stored in DB:

```sql
CREATE TABLE phpbb_auth_refresh_tokens (
    token_id        VARCHAR(64) PRIMARY KEY,  -- SHA256 of token value
    user_id         INT UNSIGNED NOT NULL,
    family_id       VARCHAR(36) NOT NULL,     -- UUID, groups rotation chain
    issued_at       INT UNSIGNED NOT NULL,
    expires_at      INT UNSIGNED NOT NULL,
    revoked         TINYINT(1) DEFAULT 0,
    replaced_by     VARCHAR(64) NULL,         -- Points to successor token
    user_agent      VARCHAR(255),
    ip_address      VARCHAR(45),

    INDEX idx_user_id (user_id),
    INDEX idx_family_id (family_id),
    INDEX idx_expires (expires_at)
);
```

### 3.3 Refresh Token Rotation (One-Time Use)

```php
public function refresh(string $refreshToken): TokenPair
{
    $tokenHash = hash('sha256', $refreshToken);
    $stored = $this->refreshTokenRepository->findByHash($tokenHash);

    if (!$stored || $stored->revoked || $stored->expires_at < time()) {
        if ($stored && !$stored->revoked) {
            // Token reuse detected! Revoke entire family
            $this->revokeFamily($stored->family_id);
        }
        throw new AuthenticationException('Invalid refresh token');
    }

    // Rotate: mark old as used, issue new pair
    $this->refreshTokenRepository->revoke($tokenHash);

    $newRefreshToken = bin2hex(random_bytes(32));
    $this->refreshTokenRepository->store([
        'token_id'   => hash('sha256', $newRefreshToken),
        'user_id'    => $stored->user_id,
        'family_id'  => $stored->family_id,  // Same family!
        'issued_at'  => time(),
        'expires_at' => time() + 604800,     // 7 days
        'revoked'    => 0,
    ]);

    $accessToken = $this->issueAccessToken($stored->user_id);

    return new TokenPair($accessToken, $newRefreshToken);
}
```

### 3.4 Family-Based Revocation

If a refresh token is reused (detected theft), revoke ALL tokens in the family:

```php
public function revokeFamily(string $familyId): void
{
    $this->db->sql_query(
        'UPDATE phpbb_auth_refresh_tokens
         SET revoked = 1
         WHERE family_id = ' . $this->db->sql_escape($familyId)
    );
}
```

This limits damage: attacker's stolen token becomes useless, user must re-authenticate.

---

## 4. Token Revocation Without Full Server State

### 4.1 Strategy: Short-Lived Access + Server-Side Refresh

The primary approach — no access token blacklist needed:

| Mechanism | Token Type | State Required |
|-----------|-----------|---------------|
| Short expiry (15 min) | Access token | **None** — purely stateless |
| DB lookup | Refresh token | 1 row per active session |
| Family revocation | Refresh chain | Family ID in refresh table |

**Key insight**: Access tokens are never revoked individually. Worst case, a stolen access token works for ≤15 minutes. For forced immediate revocation (e.g., password change), increment a `token_generation` counter on the user record and embed it in JWT — stale generation = rejected.

### 4.2 JTI-Based Deny List (Optional Enhancement)

For scenarios requiring immediate access token revocation:

```php
// Minimal deny list — stores only JTI + expiry
// Can use Redis with TTL = remaining token lifetime
$this->cache->set(
    'jwt_deny:' . $jti,
    true,
    $remainingTtl  // Auto-expires when token would have anyway
);

// Check during validation (middleware):
if ($this->cache->has('jwt_deny:' . $payload->jti)) {
    throw new TokenRevokedException();
}
```

Size consideration: With 15-min tokens and 10k active users, max deny list entries = ~100 (only for forced revocations). Negligible overhead.

### 4.3 Generation Counter Pattern

```php
// In JWT payload:
'gen' => $user->token_generation,  // Integer, starts at 0

// On password change / forced logout:
UPDATE phpbb_users SET token_generation = token_generation + 1 WHERE user_id = ?;

// In auth middleware (cheap check, loaded with user on first request):
if ($payload->gen < $user->token_generation) {
    throw new TokenRevokedException('Token invalidated by security event');
}
```

Trade-off: Requires one DB check per request to verify generation, but the user record is typically already loaded for permission checks.

---

## 5. Two Token Types: User Token vs Elevated Token

### 5.1 Elevation Model

```
┌──────────────┐     POST /auth/elevate     ┌──────────────────┐
│              │    {group_id, password}      │                  │
│  User Token  ├────────────────────────────►│  Elevated Token  │
│  (role:user) │                             │  (role:admin)    │
│  aud:api     │◄────────────────────────────┤  aud:admin       │
│  exp:15min   │     Falls back to user      │  exp:5min        │
└──────────────┘     when elevated expires   └──────────────────┘
```

### 5.2 Elevation Request Flow

```php
public function elevate(string $accessToken, int $groupId, string $password): string
{
    // 1. Verify current access token
    $payload = $this->verifyAccessToken($accessToken);
    $userId = (int) $payload->sub;

    // 2. Re-verify password (step-up authentication)
    if (!$this->verifyPassword($userId, $password)) {
        throw new AuthenticationException('Invalid credentials for elevation');
    }

    // 3. Verify user belongs to the requested group
    if (!in_array($groupId, $payload->groups)) {
        throw new AuthorizationException('User not member of requested group');
    }

    // 4. Determine elevated role from group type
    $role = $this->resolveGroupRole($groupId); // 'admin' or 'mod'
    $scopes = $this->resolveGroupScopes($groupId); // ['acp'] or ['mcp']

    // 5. Issue elevated token
    return JWT::encode([
        'iss' => 'phpbb',
        'sub' => (string) $userId,
        'aud' => 'phpbb-admin',
        'iat' => time(),
        'exp' => time() + 300,  // 5 minutes
        'jti' => $this->generateJti(),
        'type' => 'elevated',
        'role' => $role,
        'group_id' => $groupId,
        'scope' => $scopes,
        'elevation_jti' => $payload->jti,  // Link to parent
    ], $this->signingKey, 'HS256');
}
```

### 5.3 Scope-Based Access Control

```php
// Middleware checks aud + scope claims:
class ElevatedAccessMiddleware
{
    public function handle(Request $request, string $requiredScope): void
    {
        $payload = $request->getAttribute('jwt_payload');

        // Must be elevated token
        if (($payload->type ?? '') !== 'elevated') {
            throw new ForbiddenException('Elevated access required');
        }

        // Check audience
        if ($payload->aud !== 'phpbb-admin') {
            throw new ForbiddenException('Invalid token audience');
        }

        // Check scope
        if (!in_array($requiredScope, $payload->scope ?? [])) {
            throw new ForbiddenException("Scope '$requiredScope' not granted");
        }
    }
}
```

### 5.4 Claims Design for Group Permissions

**Option A: Group IDs only (recommended for start)**
```php
'groups' => [5, 7, 12]  // Compact, permission resolved server-side
```
Pros: Small token, permissions always fresh from cache
Cons: Requires permission cache lookup per request

**Option B: Permission digest (optimization)**
```php
'perm_hash' => 'a3f2b1...'  // SHA256 of serialized permission set
```
Used to detect stale permissions: if hash doesn't match current cached permission set, force re-auth.

**Option C: Inline permission bitfield (maximum performance)**
```php
'acl' => 'base64-encoded-bitfield'  // Full ACL bitfield in token
```
Pros: Zero DB/cache hit for permission checks
Cons: Token size explosion (phpBB has 60+ permission options × N forums), exceeds cookie 4KB limit easily

**Recommendation**: Option A with permission hash. Keep tokens small, use server-side permission cache (already exists in phpBB's `acl_cache`). The hash detects staleness without embedding full permissions.

---

## 6. Security Considerations

### 6.1 Algorithm Selection: HS256 vs RS256

| Factor | HS256 (Symmetric) | RS256 (Asymmetric) |
|--------|-------------------|---------------------|
| Key management | One shared secret | Separate sign/verify keys |
| Performance | ~10x faster | Slower (RSA operations) |
| Key distribution | Secret must stay on auth server | Public key can be distributed |
| Microservice fit | All services need secret | Services only need public key |
| Key rotation | Must update everywhere | Only replace private key |

**Recommendation for phpBB**: **HS256** for MVP. Single application, no microservice distribution needed. The signing key stays within the auth service. Simpler key management. If multi-service validation is needed later, switch to RS256 with `kid` header for rotation.

### 6.2 Key Management

```php
// Key derivation — use config.php secret + purpose-specific salt
$signingKey = hash_hmac('sha256', 'jwt-access-token-v1', $config['auth_secret']);

// Key requirements for HS256: minimum 32 bytes (256 bits)
// Generate with: bin2hex(random_bytes(32)) → 64 hex chars

// Key rotation support:
$keys = [
    'v2' => new Key(hash_hmac('sha256', 'jwt-v2', $secret), 'HS256'),
    'v1' => new Key(hash_hmac('sha256', 'jwt-v1', $secret), 'HS256'),
];
// Encode with 'v2', decode accepts both 'v1' and 'v2'
```

### 6.3 Token Storage & Transport

**Recommended: HttpOnly cookie for web + Authorization header for API**

```
Set-Cookie: phpbb_access=<jwt>; HttpOnly; Secure; SameSite=Strict; Path=/; Max-Age=900
```

| Attribute | Purpose |
|-----------|---------|
| HttpOnly | Prevents XSS from reading token |
| Secure | HTTPS only |
| SameSite=Strict | CSRF protection (blocks cross-origin requests) |
| Path=/ | Available to all routes |
| Max-Age=900 | 15 min, matches token exp |

**For API clients (mobile, SPA with CORS)**:
```
Authorization: Bearer <jwt>
```

### 6.4 CSRF Protection with JWT

When using cookies (SameSite=Strict helps but isn't sufficient for all browsers):

```php
// Double-submit cookie pattern:
// 1. Include a random value in JWT claims:
'csrf' => bin2hex(random_bytes(16)),

// 2. Also set it as a readable (non-HttpOnly) cookie:
Set-Cookie: phpbb_csrf=<same-value>; Secure; SameSite=Strict; Path=/

// 3. Client sends csrf value in X-CSRF-Token header
// 4. Server validates: jwt.csrf === header.X-CSRF-Token
```

Alternative: Since phpBB already has `form_key` / `check_form_key()`, the CSRF token can be embedded in JWT and validated against a separate non-HttpOnly cookie or form field.

### 6.5 Token Size Considerations

Typical JWT sizes with phpBB claims:

| Token Configuration | Approximate Size |
|--------------------|-----------------:|
| Minimal (sub, exp, iat, type) | ~200 bytes |
| User token (+ groups, role, username) | ~350 bytes |
| Elevated token (+ scope, elevation_jti) | ~400 bytes |
| With 50 group IDs | ~600 bytes |
| With inline ACL bitfield (60 options × 100 forums) | ~2-4 KB ⚠️ |

**Cookie limit**: 4096 bytes total (name + value + attributes). Keep JWT under ~3.5 KB for cookie transport.

**Recommendation**: Do NOT embed permission bitfields in JWT. Keep groups array (IDs only) and resolve permissions server-side from cache.

### 6.6 Algorithm Confusion Attack Protection

The firebase/php-jwt library handles this by design:

```php
// Key object binds algorithm to key material:
$key = new Key($secret, 'HS256');

// During decode, library checks:
// $key->getAlgorithm() === $header->alg
// If attacker changes header to 'none' or 'RS256', decode throws:
// UnexpectedValueException: 'Incorrect key for this algorithm'
```

No additional protection needed — the library's `Key` class prevents this class of attacks.

---

## 7. Practical Implementation Skeleton

### 7.1 Token Issuer Service

```php
namespace phpbb\auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;

class TokenService
{
    private string $signingKey;
    private string $keyId;
    private int $accessTtl = 900;      // 15 min
    private int $elevatedTtl = 300;    // 5 min
    private int $refreshTtl = 604800;  // 7 days

    public function __construct(string $secret, string $keyId = 'v1')
    {
        $this->signingKey = hash_hmac('sha256', 'jwt-' . $keyId, $secret);
        $this->keyId = $keyId;
    }

    public function issueAccessToken(int $userId, string $username, array $groups, string $role): string
    {
        return JWT::encode([
            'iss'      => 'phpbb',
            'sub'      => (string) $userId,
            'aud'      => 'phpbb-api',
            'iat'      => time(),
            'exp'      => time() + $this->accessTtl,
            'jti'      => $this->generateJti(),
            'type'     => 'access',
            'username' => $username,
            'groups'   => $groups,
            'role'     => $role,
        ], $this->signingKey, 'HS256', $this->keyId);
    }

    public function issueElevatedToken(int $userId, string $username, int $groupId, string $role, array $scopes, string $parentJti): string
    {
        return JWT::encode([
            'iss'            => 'phpbb',
            'sub'            => (string) $userId,
            'aud'            => 'phpbb-admin',
            'iat'            => time(),
            'exp'            => time() + $this->elevatedTtl,
            'jti'            => $this->generateJti(),
            'type'           => 'elevated',
            'username'       => $username,
            'group_id'       => $groupId,
            'role'           => $role,
            'scope'          => $scopes,
            'elevation_jti'  => $parentJti,
        ], $this->signingKey, 'HS256', $this->keyId);
    }

    public function verify(string $token): \stdClass
    {
        return JWT::decode($token, new Key($this->signingKey, 'HS256'));
    }

    public function verifyExpired(string $token): ?\stdClass
    {
        try {
            return $this->verify($token);
        } catch (ExpiredException $e) {
            return $e->getPayload();  // Access payload even after expiry (for refresh)
        }
    }

    private function generateJti(): string
    {
        // UUID v4
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
```

### 7.2 Token Validation Middleware

```php
namespace phpbb\auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\BeforeValidException;

class TokenValidator
{
    public function validate(string $token, string $expectedAudience = 'phpbb-api'): \stdClass
    {
        $payload = JWT::decode($token, new Key($this->signingKey, 'HS256'));

        // Application-level claim validation (not done by library):
        if (($payload->iss ?? '') !== 'phpbb') {
            throw new \UnexpectedValueException('Invalid issuer');
        }

        if (($payload->aud ?? '') !== $expectedAudience) {
            throw new \UnexpectedValueException('Invalid audience');
        }

        return $payload;
    }
}
```

---

## 8. Summary & Recommendations

### Key Decisions

| Decision | Recommendation | Rationale |
|----------|---------------|-----------|
| Algorithm | HS256 | Single-app, fast, simple key mgmt |
| Access token lifetime | 15 minutes | Balance UX vs security |
| Elevated token lifetime | 5 minutes | High-privilege, minimize exposure |
| Refresh token | Opaque, DB-stored | Enables revocation, rotation |
| Permission in JWT | Group IDs + perm_hash | Small token, server-side resolution |
| Token transport (web) | HttpOnly Secure cookie | XSS protection |
| Token transport (API) | Bearer header | Standard, CORS-friendly |
| CSRF protection | Double-submit pattern | Works with cookie-based JWT |
| Revocation | Short expiry + generation counter | Minimal state, eventual consistency |
| Key rotation | kid header + key map | Zero-downtime rotation |

### Implementation Priority

1. **TokenService** — encode/decode with HS256
2. **Refresh token rotation** — DB table, family tracking
3. **Middleware validation** — iss, aud, type checks
4. **Elevation flow** — password re-verify + scoped token
5. **Key rotation** — kid-based multi-key support
6. **Generation counter** — forced revocation on security events

### Confidence

- **High (95%)**: firebase/php-jwt API — directly read from source code
- **High (90%)**: JWT best practices — well-established standards (RFC 7519)
- **High (90%)**: Access/refresh pattern — industry standard (OAuth2)
- **Medium (80%)**: Token size estimates — depends on actual group counts
- **Medium (75%)**: phpBB specific claims design — needs validation against actual ACL bitfield sizes
