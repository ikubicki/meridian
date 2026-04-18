# Auth Providers — Legacy phpBB Pattern

## Research Question
How does the legacy phpBB auth provider pattern work? What does the interface define? How does the db provider handle login?

---

## 1. File Inventory

All auth files under `src/phpbb/forums/auth/`:

| File | Purpose |
|------|---------|
| `auth.php` | ACL/permissions engine + `login()` orchestrator |
| `provider_collection.php` | DI service collection — resolves active provider |
| `provider/provider_interface.php` | Contract all auth providers must implement |
| `provider/base.php` | Abstract base — null-object defaults for all methods |
| `provider/db.php` | **Primary provider** — database username/password auth |
| `provider/ldap.php` | LDAP authentication |
| `provider/apache.php` | Apache HTTP auth |
| `provider/oauth/oauth.php` | OAuth 1/2 authentication (delegates to `db.php` internally) |
| `provider/oauth/token_storage.php` | OAuth token persistence |
| `provider/oauth/service/` | Per-service OAuth adapters (Facebook, Google, Twitter, Bitly) |

**Source**: `src/phpbb/forums/auth/` directory listing

---

## 2. Provider Interface — Full Signature

**Source**: `src/phpbb/forums/auth/provider/provider_interface.php` (Lines 1–208)

```php
namespace phpbb\auth\provider;

interface provider_interface
{
    // --- Core authentication ---
    public function init();                                       // Check if provider is usable; returns false|string|null
    public function login($username, $password);                  // Returns status array (see §4)
    public function autologin();                                  // Returns user_row array or null
    public function logout($data, $new_session);                  // Post-logout cleanup
    public function validate_session($user);                      // Returns bool|null — session still valid?

    // --- ACP configuration ---
    public function acp();                                        // Config fields for admin panel
    public function get_acp_template($new_config);                // ACP template data

    // --- Login form customization ---
    public function get_login_data();                             // Custom login form elements

    // --- Account linking (OAuth / external) ---
    public function login_link_has_necessary_data(array $login_link_data);  // Validate link data
    public function link_account(array $link_data);               // Link external → phpBB account
    public function unlink_account(array $link_data);             // Unlink
    public function get_auth_link_data($user_id = 0);             // UCP link management template data
}
```

### Method Contracts Summary

| Method | Return | When Called |
|--------|--------|-------------|
| `init()` | `false` (ok) / `string` (error) / `null` | ACP when switching auth method |
| `login($username, $password)` | `array{status, error_msg, user_row [, redirect_data]}` | `auth::login()` → via `login_box()` |
| `autologin()` | `array` (user row) / `null` | `session_create()` — cookie-less re-auth |
| `validate_session($user)` | `bool` / `null` | `session_begin()` — session check on every request |
| `logout($data, $new_session)` | `void` | `session_kill()` |
| `get_login_data()` | `array{TEMPLATE_FILE, ...}` / `null` | `login_box()` — custom login form fields |

---

## 3. Base Provider (Abstract Default)

**Source**: `src/phpbb/forums/auth/provider/base.php` (Lines 1–107)

`base` is an abstract class implementing `provider_interface` with **all methods returning `null`/void** — a null-object pattern. Concrete providers only need to override what they implement. The only method providers **must** override is `login()` (it has no default in `base`).

Key: `base` does NOT implement `login()` — subclasses are forced to provide it.

---

## 4. db.php — Login Flow Step-by-Step

**Source**: `src/phpbb/forums/auth/provider/db.php` (Lines 90–278)

### Constructor Dependencies

```php
public function __construct(
    factory $captcha_factory,
    config $config,
    driver_interface $db,
    manager $passwords_manager,
    request_interface $request,
    user $user,
    $phpbb_root_path,
    $php_ext
)
```

### `login($username, $password)` Flow

**Step 1: Input validation**
- Trim password (compatibility)
- Return `LOGIN_ERROR_PASSWORD` if empty password
- Return `LOGIN_ERROR_USERNAME` if empty username

**Step 2: Lookup user**
```sql
SELECT * FROM phpbb_users WHERE username_clean = '{escaped_clean_username}'
```

**Step 3: Record login attempt (IP brute-force tracking)**
- Query `LOGIN_ATTEMPT_TABLE` for attempts from this IP (or forwarded-for) within `ip_login_limit_time`
- Insert new attempt row regardless of outcome
- Skipped entirely if no IP available

**Step 4: CAPTCHA check**
- If user-level attempts (`user_login_attempts >= max_login_attempts`) OR IP-level attempts (`>= ip_login_limit_max`):
  - Instantiate CAPTCHA plugin
  - Show CAPTCHA = true

**Step 5: User not found**
- If IP limit exceeded → `LOGIN_ERROR_ATTEMPTS`
- Otherwise → `LOGIN_ERROR_USERNAME`

**Step 6: CAPTCHA validation** (if shown)
- `$captcha->init(CONFIRM_LOGIN)` → `$captcha->validate($row)`
- Failed validation → `LOGIN_ERROR_ATTEMPTS`
- Success → `$captcha->reset()`

**Step 7: Password verification**
```php
$this->passwords_manager->check($password, $row['user_password'], $row)
```

**Step 8: On password match — success path**
- If password hash is old format (convert_flag or 32-char hash): rehash with `$passwords_manager->hash()` and UPDATE
- DELETE all login attempts for this user
- Reset `user_login_attempts` to 0

**Step 9: Inactive user check**
- If `user_type == USER_INACTIVE || USER_IGNORE` → `LOGIN_ERROR_ACTIVE`

**Step 10: Return success**
```php
['status' => LOGIN_SUCCESS, 'error_msg' => false, 'user_row' => $row]
```

**Step 11: On password mismatch — failure path**
- INCREMENT `user_login_attempts` (capped at `LOGIN_ATTEMPTS_MAX` = 100)
- Return `LOGIN_ERROR_ATTEMPTS` (if CAPTCHA shown) or `LOGIN_ERROR_PASSWORD`

### Return Array Structure

```php
[
    'status'    => int,    // LOGIN_SUCCESS | LOGIN_ERROR_* | LOGIN_BREAK
    'error_msg' => string|false,  // Language key or false on success
    'user_row'  => array,  // Full user row from DB (or ['user_id' => ANONYMOUS])
    // Optional:
    'redirect_data' => array,  // Only for LOGIN_SUCCESS_LINK_PROFILE
    'cp_data' => array,        // Only for LOGIN_SUCCESS_CREATE_PROFILE
]
```

### Status Constants

**Source**: `src/phpbb/common/constants.php` (Lines 61–74)

| Constant | Value | Meaning |
|----------|-------|---------|
| `LOGIN_BREAK` | 2 | Hard stop — trigger_error |
| `LOGIN_SUCCESS` | 3 | Authentication succeeded |
| `LOGIN_SUCCESS_CREATE_PROFILE` | 20 | External auth — create phpBB profile |
| `LOGIN_SUCCESS_LINK_PROFILE` | 21 | External auth — link to existing profile |
| `LOGIN_ERROR_USERNAME` | 10 | User not found |
| `LOGIN_ERROR_PASSWORD` | 11 | Wrong password |
| `LOGIN_ERROR_ACTIVE` | 12 | Account inactive |
| `LOGIN_ERROR_ATTEMPTS` | 13 | Too many attempts |
| `LOGIN_ERROR_EXTERNAL_AUTH` | 14 | External auth failure |
| `LOGIN_ERROR_PASSWORD_CONVERT` | 15 | Password needs conversion |
| `LOGIN_ATTEMPTS_MAX` | 100 | Cap on login_attempts column |

---

## 5. Provider Collection — Selection Mechanism

**Source**: `src/phpbb/forums/auth/provider_collection.php` (Lines 1–73)

```php
class provider_collection extends \phpbb\di\service_collection
```

### `get_provider($provider_name = '')`

1. Default: reads `$config['auth_method']` (e.g. `'db'`, `'ldap'`, `'oauth'`)
2. Looks up `'auth.provider.' . $provider_name` in the DI container
3. Fallback: always tries `'auth.provider.db'`
4. Throws `\RuntimeException` if neither exists

The collection is tagged via `services_auth.yml`:
```yaml
auth.provider_collection:
    tags: [{ name: service_collection, tag: auth.provider }]
```

All providers tagged `auth.provider` are auto-collected.

---

## 6. Integration with Session Management

### session_begin() — Session Validation

**Source**: `src/phpbb/forums/session.php` (Lines 416–432)

When an existing session is found:
1. Validate IP, browser, forwarded_for, referer
2. Get active auth provider: `$provider_collection->get_provider()`
3. Call `$provider->validate_session($this->data)`
   - `null` return → session OK (db provider returns null by default)
   - `false` return → invalidate session
4. Check session time expiry (regular or autologin timeframe)

### session_create() — Autologin

**Source**: `src/phpbb/forums/session.php` (Lines 574–584)

When no session exists:
1. Get active auth provider
2. Call `$provider->autologin()` — returns user row or empty
3. If user data returned, use it (skip cookie validation)
4. If empty, fall back to cookie-based autologin (`sessions_keys` table)

### session_kill() — Logout

**Source**: `src/phpbb/forums/session.php` (Lines 878–930)

1. Delete session from `SESSIONS_TABLE`
2. Dispatch `core.session_kill_after` event
3. Call `$provider->logout($this->data, $new_session)` — allows external cleanup (e.g. OAuth token revocation)
4. Update `user_lastvisit`, destroy cookies

### auth::login() — Login Orchestrator

**Source**: `src/phpbb/forums/auth/auth.php` (Lines 955–1090)

1. Get provider from collection
2. Call `$provider->login($username, $password)`
3. Handle special statuses:
   - `LOGIN_SUCCESS_CREATE_PROFILE` → call `user_add()`, re-query user
   - `LOGIN_SUCCESS_LINK_PROFILE` → redirect to UCP link page
4. Dispatch `core.auth_login_session_create_before` event
5. On `LOGIN_SUCCESS` → call `$user->session_create($user_id, $admin, $autologin, $viewonline)`
6. Return final status array

### login_box() — UI Controller

**Source**: `src/phpbb/common/functions.php` (Lines 2346–2640)

1. Handles POST form submission (username/password/CSRF check)
2. Calls `$auth->login()` (which delegates to provider as above)
3. On success → redirect
4. On failure → display error + CAPTCHA if needed
5. Gets `$auth_provider->get_login_data()` for custom login form fields (OAuth buttons etc.)

---

## 7. Service Configuration (DI)

**Source**: `src/phpbb/common/config/default/container/services_auth.yml` (Lines 1–100)

| Service ID | Class | Dependencies |
|-----------|-------|-------------|
| `auth` | `phpbb\auth\auth` | `@phpbb_app_container` |
| `auth.provider_collection` | `phpbb\auth\provider_collection` | `@service_container`, `@config` |
| `auth.provider.db` | `phpbb\auth\provider\db` | captcha.factory, config, dbal.conn, passwords.manager, request, user, root_path, php_ext |
| `auth.provider.apache` | `phpbb\auth\provider\apache` | config, dbal.conn, language, request, user, root_path, php_ext |
| `auth.provider.ldap` | `phpbb\auth\provider\ldap` | config, dbal.conn, language, user |
| `auth.provider.oauth` | `phpbb\auth\provider\oauth\oauth` | config, dbal.conn, **auth.provider.db**, dispatcher, language, request, oauth.service_collection, user, oauth tables, root_path, php_ext |

Note: OAuth provider takes `auth.provider.db` as a dependency — it delegates DB-login as fallback.

---

## 8. Overlap with Designed `phpbb\user\Service\AuthenticationService`

**Source**: `src/phpbb/user/IMPLEMENTATION_SPEC.md` (Lines 1089–1160)

### What AuthenticationService Already Covers

The designed `AuthenticationService` handles the **core login workflow**:
1. Find user by clean username → via `UserRepositoryInterface`
2. Ban check → via `BanService`
3. Login attempt limit check (hardcoded MAX=5)
4. Inactive check → `$user->isInactive()`
5. Password verification → via `PasswordHasherInterface`
6. Reset login attempts
7. Rehash if needed
8. Update last visit
9. Create session → via `SessionService`
10. Dispatch events → via `EventDispatcherInterface`

### What Auth Providers Add (NOT in AuthenticationService)

| Feature | Legacy Provider | AuthenticationService |
|---------|----------------|----------------------|
| Multiple auth backends (LDAP, OAuth, Apache) | Yes — pluggable via DI tags | No — single DB path |
| IP-based brute force (per-IP rate limiting) | Yes — `LOGIN_ATTEMPT_TABLE` | No |
| CAPTCHA integration | Yes — via `captcha_factory` | No |
| Custom login form fields | Yes — `get_login_data()` | No |
| Session validation callback | Yes — `validate_session()` | No (SessionService handles) |
| Autologin provider hook | Yes — `autologin()` | No (SessionService handles) |
| Logout provider hook | Yes — `logout()` | No |
| Account linking (OAuth) | Yes — `link_account()`, `unlink_account()` | No |
| ACP configuration | Yes — `acp()`, `get_acp_template()` | No |
| Auto-create profile on external auth | Yes — `LOGIN_SUCCESS_CREATE_PROFILE` | No |
| Password hash auto-upgrade (old 32-char) | Yes — in db.php | Yes — via `needsRehash()` |

### Key Gaps to Address

1. **Provider pluggability**: AuthenticationService is a single-strategy service. If the modernized system needs LDAP/OAuth, a provider abstraction or adapter is needed.
2. **IP brute-force & CAPTCHA**: These are security features in `db.php` that AuthenticationService doesn't implement. Could be extracted as middleware/decorators.
3. **Session lifecycle hooks**: `validate_session()`, `autologin()`, and `logout()` are called by `session.php` at specific lifecycle points. The new SessionService must account for these or they get lost.
4. **OAuth account linking**: Entirely separate concern — not part of AuthenticationService.

---

## 9. Confidence Assessment

| Finding | Confidence | Evidence Quality |
|---------|------------|-----------------|
| Interface signature (all 12 methods) | **High (100%)** | Direct source read |
| db.php login flow | **High (100%)** | Complete source read, all branches traced |
| Status constants and values | **High (100%)** | Source: constants.php |
| Session integration (3 hook points) | **High (100%)** | Source: session.php confirmed |
| Provider selection via config | **High (100%)** | Source: provider_collection.php + services_auth.yml |
| AuthenticationService overlap | **High (95%)** | Source: IMPLEMENTATION_SPEC.md — spec not yet implemented |
| OAuth delegation to db.php | **High (90%)** | services_auth.yml shows `@auth.provider.db` dependency |

---

## 10. Sources

| File | Lines Read | Purpose |
|------|-----------|---------|
| `src/phpbb/forums/auth/provider/provider_interface.php` | 1–208 | Full interface |
| `src/phpbb/forums/auth/provider/base.php` | 1–107 | Abstract defaults |
| `src/phpbb/forums/auth/provider/db.php` | 1–278 | Primary login implementation |
| `src/phpbb/forums/auth/provider_collection.php` | 1–73 | Provider resolution |
| `src/phpbb/forums/auth/auth.php` | 940–1090 | Login orchestrator |
| `src/phpbb/forums/session.php` | 400–600, 860–930 | Session lifecycle hooks |
| `src/phpbb/common/functions.php` | 2346–2640 | login_box() UI controller |
| `src/phpbb/common/constants.php` | 61–74 | LOGIN_* constants |
| `src/phpbb/common/config/default/container/services_auth.yml` | 1–100 | DI service definitions |
| `src/phpbb/user/IMPLEMENTATION_SPEC.md` | 1089–1160 | AuthenticationService design |
