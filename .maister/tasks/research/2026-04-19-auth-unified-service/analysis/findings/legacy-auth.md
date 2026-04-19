# Legacy phpBB Authentication System — Detailed Findings

## 1. Authentication Providers

### 1.1 Available Providers

| Provider | File | Purpose |
|----------|------|---------|
| `db` | `src/phpbb/forums/auth/provider/db.php` | Database auth (default) — authenticates against `phpbb_users` table |
| `ldap` | `src/phpbb/forums/auth/provider/ldap.php` | LDAP/Active Directory authentication |
| `apache` | `src/phpbb/forums/auth/provider/apache.php` | Apache HTTP basic auth (delegates to `PHP_AUTH_USER`) |
| `base` | `src/phpbb/forums/auth/provider/base.php` | Abstract base class with no-op defaults |

### 1.2 Provider Interface

**File**: `src/phpbb/forums/auth/provider/provider_interface.php`  
**Namespace**: `phpbb\auth\provider`

Key methods:

```php
interface provider_interface
{
    public function init();                              // Check if provider is ready
    public function login($username, $password);        // Authenticate credentials → array
    public function autologin();                        // Auto-login from cookie key → user row or null
    public function logout($data, $new_session);       // Cleanup on logout
    public function validate_session($user);           // Validate ongoing session → bool|null
    public function acp();                             // ACP config fields
    public function get_acp_template($new_config);     // ACP template vars
    public function get_login_data();                  // Custom login form elements
    public function login_link_has_necessary_data(array $login_link_data);
    public function link_account(array $link_data);
    public function unlink_account(array $link_data);
}
```

**Login return format**:
```php
[
    'status'    => LOGIN_SUCCESS | LOGIN_ERROR_* | LOGIN_BREAK,
    'error_msg' => string|false,
    'user_row'  => array,  // Full user row from DB
    // Optional:
    'redirect_data' => array,  // Only for LOGIN_SUCCESS_LINK_PROFILE
]
```

### 1.3 Provider Selection & Collection

**File**: `src/phpbb/forums/auth/provider_collection.php`  
**Namespace**: `phpbb\auth`

- Extends `\phpbb\di\service_collection` — Symfony DI service collection
- Provider is selected based on `$config['auth_method']` (e.g. `'db'`, `'ldap'`)
- Service IDs follow pattern: `auth.provider.{name}`
- Falls back to `auth.provider.db` if configured method doesn't exist

```php
public function get_provider($provider_name = '')
{
    $provider_name = ($provider_name !== '') ? $provider_name : basename(trim($this->config['auth_method']));
    // Looks up 'auth.provider.' . $provider_name in container
    // Falls back to 'auth.provider.db'
}
```

### 1.4 Database Provider — Password Verification

**File**: `src/phpbb/forums/auth/provider/db.php`

Login flow within `db::login($username, $password)`:

1. Reject empty password/username
2. Clean username: `utf8_clean_string($username)`
3. Query `phpbb_users` by `username_clean`
4. Track login attempts in `phpbb_login_attempts` table (by IP or forwarded-for)
5. If too many attempts → show CAPTCHA (`captcha_factory->get_instance`)
6. Verify password: `$this->passwords_manager->check($password, $row['user_password'], $row)`
7. On success:
   - Auto-upgrade old password hashes (MD5 → modern)
   - Clear login attempts
   - Reset `user_login_attempts` to 0
   - Check `user_type` — reject `USER_INACTIVE` / `USER_IGNORE`
   - Return `LOGIN_SUCCESS` + full user row
8. On failure:
   - Increment `user_login_attempts` (capped at `LOGIN_ATTEMPTS_MAX = 100`)
   - Return `LOGIN_ERROR_PASSWORD` or `LOGIN_ERROR_ATTEMPTS`

**Dependencies injected**:
- `captcha_factory`, `config`, `db`, `passwords_manager`, `request`, `user`

---

## 2. Session Management

### 2.1 Session Class

**File**: `src/phpbb/forums/session.php`  
**Namespace**: `phpbb`  
**Class**: `session`

Key properties:
```php
var $cookie_data = array();   // ['u' => user_id, 'k' => autologin_key]
var $page = array();          // Current page info
var $data = array();          // Session + user data merged
var $session_id = '';         // 32-char MD5 hex string
var $ip = '';                 // Client IP
var $time_now = 0;            // Current timestamp
```

### 2.2 Session Begin — `session_begin($update_session_page = true)`

**Lines ~230-480 in session.php**

Flow:
1. Gather client info: browser UA, referer, forwarded-for, host, current page
2. Read cookies: `{cookie_name}_sid`, `{cookie_name}_u`, `{cookie_name}_k`
3. Determine session ID from cookie or `sid` GET parameter
4. If session ID exists → query DB:
   ```sql
   SELECT u.*, s.*
   FROM phpbb_sessions s, phpbb_users u
   WHERE s.session_id = '{escaped_sid}'
     AND u.user_id = s.session_user_id
   ```
5. Validate existing session against:
   - IP address (configurable prefix check via `$config['ip_check']`)
   - Browser string (if `$config['browser_check']`)
   - Forwarded-for header (if `$config['forwarded_for_check']`)
   - Referer (if `$config['referer_validation']`)
6. Call `$provider->validate_session($this->data)` — provider can invalidate
7. Check session expiry:
   - Non-autologin: `session_time < now - session_length` → expired
   - Autologin: `session_time < now - (86400 * max_autologin_time)` → expired
8. If valid → update last active time, return true
9. If invalid/missing → call `$this->session_create()`

### 2.3 Session Create — `session_create($user_id = false, $set_admin = false, $persist_login = false, $viewonline = true)`

**Lines ~480-810 in session.php**

Flow:
1. Run garbage collection (if due)
2. Disable autologin if `$config['allow_autologin']` is false
3. Bot detection (match user-agent + IP against `phpbb_bots` table)
4. Try `$provider->autologin()` — returns user row if auto-login is possible
5. If autologin cookie key (`k`) exists:
   ```sql
   SELECT u.*
   FROM phpbb_users u, phpbb_sessions_keys k
   WHERE u.user_id = {cookie_u}
     AND u.user_type IN (USER_NORMAL, USER_FOUNDER)
     AND k.user_id = u.user_id
     AND k.key_id = '{md5(cookie_k)}'
   ```
6. If explicit `$user_id` passed → load user from `phpbb_users`
7. If no data → load anonymous user
8. Ban check
9. **Generate new session ID**: `$this->session_id = md5(unique_id())`
10. Build session row:
    ```php
    $sql_ary = [
        'session_id'            => md5(unique_id()),
        'session_user_id'       => (int) $user_id,
        'session_start'         => time(),
        'session_last_visit'    => last_visit_time,
        'session_time'          => time(),
        'session_browser'       => substr($browser, 0, 149),
        'session_forwarded_for' => $forwarded_for,
        'session_ip'            => $ip,
        'session_autologin'     => 0|1,
        'session_admin'         => 0|1,
        'session_viewonline'    => 0|1,
        'session_page'          => substr($page, 0, 199),
        'session_forum_id'      => $forum_id,
    ];
    ```
11. `INSERT INTO phpbb_sessions`
12. If autologin → call `$this->set_login_key()`
13. Set cookies: `{cookie_name}_u`, `{cookie_name}_k`, `{cookie_name}_sid`
14. Cookie expiry: `max_autologin_time * 86400` or 1 year
15. Update/generate `user_form_salt` (CSRF protection)

### 2.4 Session Storage

| Table | Purpose | Key Columns |
|-------|---------|-------------|
| `phpbb_sessions` | Active sessions | `session_id` (char 32, PK), `session_user_id`, `session_time`, `session_ip`, `session_browser`, `session_autologin`, `session_admin` |
| `phpbb_sessions_keys` | Remember-me keys | `key_id` (char 32), `user_id` (composite PK), `last_ip`, `last_login` |

**Session ID**: 32-character hex string — `md5(unique_id())`  
**Autologin key**: Stored as `md5(key_id)` in DB, raw `key_id` in cookie

### 2.5 Cookies Used

| Cookie | Content | Purpose |
|--------|---------|---------|
| `{cookie_name}_sid` | Session ID (32 hex chars) | Identifies current session |
| `{cookie_name}_u` | User ID (int) | Identifies user for autologin |
| `{cookie_name}_k` | Autologin key (raw) | Persistent login key |

Cookie settings from config: `cookie_name`, `cookie_domain`, `cookie_path`, `cookie_secure`

### 2.6 Session Garbage Collection — `session_gc()`

**Lines ~990-1090 in session.php**

- Triggered when `time() > config['session_last_gc'] + config['session_gc']`
- Updates `user_lastvisit` from most recent expired session per user
- Deletes all sessions older than `config['session_length']`
- Deletes autologin keys older than `config['max_autologin_time']` days
- Cleans login attempts older than `config['ip_login_limit_time']`
- Runs CAPTCHA garbage collection

### 2.7 Remember-Me / Persistent Login — `set_login_key()`

**Lines ~1596-1660 in session.php**

- Generates new key: `unique_id(hexdec(substr($session_id, 0, 8)))`
- Stores `md5(key_id)` in `phpbb_sessions_keys`
- Updates existing key if one exists, otherwise inserts new
- On login: key in cookie is compared against `md5(cookie_k)` in DB
- `reset_login_keys($user_id)` — deletes all keys for user (called on password change)

---

## 3. Login Flow (End-to-End)

### 3.1 Entry Point

**File**: `web/ucp.php` → `case 'login':` (line 85)

```php
case 'login':
    if ($user->data['is_registered']) {
        redirect(append_sid("{$phpbb_root_path}index.php"));
    }
    login_box($request->variable('redirect', "index.php"));
break;
```

### 3.2 `login_box()` Function

**File**: `src/phpbb/common/functions.php:2346`  
**Signature**: `login_box($redirect = '', $l_explain = '', $l_success = '', $admin = false, $s_display = true)`

Flow:
1. Event: `core.login_box_before`
2. If `$admin` and user lacks `a_` permission → 403 + log
3. On POST (`login` submitted):
   - Admin re-auth uses `credential` hash for password field name (anti-CSRF)
   - Read: `username`, `password`, `autologin`, `viewonline`
   - Verify username matches session user (for admin re-auth)
   - **Check CSRF form key**: `check_form_key('login')`
   - Call: `$auth->login($username, $password, $autologin, $viewonline, $admin)`
4. If admin → log success/failure
5. On `LOGIN_SUCCESS`:
   - Event: `core.login_box_redirect`
   - Redirect to `$redirect` URL
6. On `LOGIN_ERROR_ATTEMPTS` → show CAPTCHA
7. Other errors → display error message

### 3.3 `auth::login()` Method

**File**: `src/phpbb/forums/auth/auth.php:953`  
**Signature**: `login($username, $password, $autologin = false, $viewonline = 1, $admin = 0)`

Flow:
1. Get provider from `auth.provider_collection`
2. Call `$provider->login($username, $password)` → `$login` array
3. Handle `LOGIN_SUCCESS_CREATE_PROFILE` → call `user_add()`, re-fetch user
4. Handle `LOGIN_SUCCESS_LINK_PROFILE` → redirect to `ucp.php?mode=login_link`
5. Event: `core.auth_login_session_create_before`
6. On `LOGIN_SUCCESS`:
   - For admin re-auth: clear old session cookies, reset `$_SID`
   - Call `$user->session_create($login['user_row']['user_id'], $admin, $autologin, $viewonline)`
   - Delete old admin session if admin re-auth
   - Return `LOGIN_SUCCESS`
7. On failure → return login result array as-is

### 3.4 Admin Re-Authentication

- Uses credential hash in password field name: `password_{credential}`
- The `credential` is a 32-char hex string validated with `strspn()`
- On admin login, old session is deleted, new session created with `session_admin = 1`
- Admin session flag checked via `session_admin` column in sessions table

### 3.5 Failed Login Handling

**Rate limiting (IP-based)**:
- Config: `ip_login_limit_max`, `ip_login_limit_time`, `ip_login_limit_use_forwarded`
- Each attempt logged in `phpbb_login_attempts` table
- When limit exceeded → CAPTCHA required

**Rate limiting (user-based)**:
- `user_login_attempts` column in `phpbb_users`
- Config: `max_login_attempts`
- Incremented on failure (capped at `LOGIN_ATTEMPTS_MAX = 100`)
- Reset to 0 on success

---

## 4. Password Management

### 4.1 Password Manager

**File**: `src/phpbb/forums/passwords/manager.php`  
**Namespace**: `phpbb\passwords`

Key methods:
```php
public function hash($password, $type = '');           // Hash password with default or specified algorithm
public function check($password, $hash, $user_row = []); // Verify password against hash
public function detect_algorithm($hash);               // Detect hash type from prefix
```

- Rejects passwords > 4096 characters
- Detects algorithm by hash prefix (e.g. `$2a$`, `$H$`, `$argon2id$`)
- Supports combined/chained hashes (`$H\2a$` = phpass + bcrypt)
- Sets `convert_flag = true` if hash needs upgrading to newer algorithm
- On `convert_flag`, the `db` provider re-hashes and updates `user_password`

### 4.2 Password Drivers

**Directory**: `src/phpbb/forums/passwords/driver/`

| Driver | Prefix | Purpose |
|--------|--------|---------|
| `argon2i.php` | `$argon2i$` | Argon2i (modern, preferred) |
| `argon2id.php` | `$argon2id$` | Argon2id (modern, preferred) |
| `bcrypt.php` | `$2a$` | Bcrypt (cost 10+) |
| `bcrypt_2y.php` | `$2y$` | Bcrypt 2y variant |
| `phpass.php` | `$H$` | Portable PHP password hashing (legacy) |
| `salted_md5.php` | — | Legacy salted MD5 |
| `md5_phpbb2.php` | — | phpBB2 legacy MD5 |
| `sha1.php` | — | SHA1 (legacy) |
| Various `*_wcf*`, `*_smf*`, etc. | — | Migration drivers from other forum software |

**Driver interface** (`driver_interface.php`):
```php
interface driver_interface {
    public function is_supported();
    public function is_legacy();
    public function get_prefix();
    public function hash($password);
    public function check($password, $hash, $user_row = []);
}
```

Default algorithm selection: configured via DI container `$defaults` array, first supported type wins.

### 4.3 Password Change Flow

In `reset_password` controller (`src/phpbb/forums/ucp/controller/reset_password.php`):

1. Validate new password (min length from `$config['min_pass_chars']`, complexity from `$config['pass_complex']`)
2. Hash: `$this->passwords_manager->hash($data['new_password'])`
3. Update DB:
   ```sql
   UPDATE phpbb_users SET
     user_password = '{new_hash}',
     user_passchg = {time()},
     user_login_attempts = 0,
     reset_token = '',
     reset_token_expiration = 0
   WHERE user_id = {id}
   ```
4. Call `$this->user->reset_login_keys($user_id)` — invalidates all sessions + persistent keys

### 4.4 Password Reset Flow

**Controller**: `src/phpbb/forums/ucp/controller/reset_password.php`

**Request phase** (`request()` method):
1. User submits email (+ optional username if multiple accounts share email)
2. CSRF check: `check_form_key('ucp_reset_password')`
3. Look up user by email/username
4. Skip if user is inactive/ignored or already has valid token
5. Generate token: `strtolower(gen_rand_string(32))`
6. Store `reset_token` + `reset_token_expiration` in `phpbb_users`
7. Send email with reset link containing `u={user_id}&token={reset_token}`

**Reset phase** (`reset()` method):
1. Validate token with `hash_equals()` (timing-safe)
2. Check expiration
3. Validate new password
4. Hash & update, clear token, reset login keys

---

## 5. Logout Flow

### 5.1 Logout Entry Point

**File**: `web/ucp.php` lines 103-117

```php
case 'logout':
    if ($user->data['user_id'] != ANONYMOUS
        && $request->is_set('sid')
        && $request->variable('sid', '') === $user->session_id)
    {
        $user->session_kill();
    }
    redirect(append_sid("{$phpbb_root_path}index.php"));
break;
```

**Security**: Logout requires valid `sid` parameter matching current session (prevents CSRF logout).

### 5.2 `session_kill($new_session = true)`

**File**: `src/phpbb/forums/session.php:884`

Flow:
1. Delete session from DB: `DELETE FROM phpbb_sessions WHERE session_id = '{sid}' AND session_user_id = {uid}`
2. Event: `core.session_kill_after`
3. Call `$provider->logout($this->data, $new_session)` — provider cleanup hook
4. If registered user:
   - Update `user_lastvisit` from session time
   - Delete autologin key: `DELETE FROM phpbb_sessions_keys WHERE user_id = {uid} AND key_id = '{md5(cookie_k)}'`
   - Reset data to anonymous user
5. Expire all cookies (set to timestamp in past):
   - `{cookie_name}_u = ''`
   - `{cookie_name}_k = ''`
   - `{cookie_name}_sid = ''`
6. If `$new_session = true` → create anonymous session

---

## 6. Important Constants

**File**: `src/phpbb/common/constants.php`

```php
// Login status constants
define('LOGIN_BREAK', 2);
define('LOGIN_SUCCESS', 3);
define('LOGIN_SUCCESS_CREATE_PROFILE', 20);
define('LOGIN_SUCCESS_LINK_PROFILE', 21);
define('LOGIN_ERROR_USERNAME', 10);
define('LOGIN_ERROR_PASSWORD', 11);
define('LOGIN_ERROR_ACTIVE', 12);
define('LOGIN_ERROR_ATTEMPTS', 13);
define('LOGIN_ERROR_EXTERNAL_AUTH', 14);
define('LOGIN_ERROR_PASSWORD_CONVERT', 15);

// User types
define('USER_NORMAL', 0);
define('USER_INACTIVE', 1);
define('USER_FOUNDER', 3);
define('ANONYMOUS', 1);

// Limits
define('LOGIN_ATTEMPTS_MAX', 100);

// Tables
define('SESSIONS_TABLE', $table_prefix . 'sessions');
define('SESSIONS_KEYS_TABLE', $table_prefix . 'sessions_keys');
define('LOGIN_ATTEMPT_TABLE', $table_prefix . 'login_attempts');
define('USERS_TABLE', $table_prefix . 'users');
```

---

## 7. Configuration Values (from `phpbb_config`)

| Key | Purpose | Typical Value |
|-----|---------|---------------|
| `auth_method` | Active auth provider | `'db'` |
| `session_length` | Session TTL (seconds) | `3600` |
| `session_gc` | GC interval (seconds) | `3600` |
| `allow_autologin` | Enable remember-me | `1` |
| `max_autologin_time` | Remember-me TTL (days, 0=forever) | `0` |
| `ip_check` | IP octets to validate (0-4) | `3` |
| `browser_check` | Validate browser string | `1` |
| `forwarded_for_check` | Validate X-Forwarded-For | `0` |
| `referer_validation` | Referer check level | `1` |
| `max_login_attempts` | Per-user attempt limit | `3` |
| `ip_login_limit_max` | Per-IP attempt limit | `50` |
| `ip_login_limit_time` | IP limit window (seconds) | `21600` |
| `cookie_name` | Cookie prefix | `'phpbb3'` |
| `cookie_domain` | Cookie domain | `''` |
| `cookie_path` | Cookie path | `'/'` |
| `cookie_secure` | HTTPS-only cookies | `0` |
| `min_pass_chars` | Min password length | `6` |
| `pass_complex` | Password complexity rule | `'PASS_TYPE_ANY'` |
| `allow_password_reset` | Enable reset flow | `1` |
| `active_sessions` | Max active sessions (0=unlimited) | `0` |
| `form_token_lifetime` | CSRF token TTL (seconds) | `7200` |
| `captcha_plugin` | CAPTCHA implementation | `'core.captcha.plugins.qa'` |

---

## 8. Database Schema

### `phpbb_sessions`
```sql
CREATE TABLE phpbb_sessions (
  session_id char(32) NOT NULL DEFAULT '',
  session_user_id int(10) unsigned NOT NULL DEFAULT 0,
  session_last_visit int(11) unsigned NOT NULL DEFAULT 0,
  session_start int(11) unsigned NOT NULL DEFAULT 0,
  session_time int(11) unsigned NOT NULL DEFAULT 0,
  session_ip varchar(40) NOT NULL DEFAULT '',
  session_browser varchar(150) NOT NULL DEFAULT '',
  session_forwarded_for varchar(255) NOT NULL DEFAULT '',
  session_page varchar(255) NOT NULL DEFAULT '',
  session_viewonline tinyint(1) unsigned NOT NULL DEFAULT 1,
  session_autologin tinyint(1) unsigned NOT NULL DEFAULT 0,
  session_admin tinyint(1) unsigned NOT NULL DEFAULT 0,
  session_forum_id mediumint(8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (session_id),
  KEY session_time (session_time),
  KEY session_user_id (session_user_id),
  KEY session_fid (session_forum_id)
);
```

### `phpbb_sessions_keys`
```sql
CREATE TABLE phpbb_sessions_keys (
  key_id char(32) NOT NULL DEFAULT '',
  user_id int(10) unsigned NOT NULL DEFAULT 0,
  last_ip varchar(40) NOT NULL DEFAULT '',
  last_login int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (key_id, user_id),
  KEY last_login (last_login)
);
```

### `phpbb_login_attempts`
```sql
CREATE TABLE phpbb_login_attempts (
  attempt_ip varchar(40) NOT NULL DEFAULT '',
  attempt_browser varchar(150) NOT NULL DEFAULT '',
  attempt_forwarded_for varchar(255) NOT NULL DEFAULT '',
  attempt_time int(11) unsigned NOT NULL DEFAULT 0,
  user_id int(10) unsigned NOT NULL DEFAULT 0,
  username varchar(255) NOT NULL DEFAULT '0',
  username_clean varchar(255) NOT NULL DEFAULT '0',
  KEY att_ip (attempt_ip, attempt_time),
  KEY att_for (attempt_forwarded_for, attempt_time),
  KEY att_time (attempt_time),
  KEY user_id (user_id)
);
```

### Relevant `phpbb_users` columns
- `user_password` — hashed password
- `user_passchg` — last password change timestamp
- `user_login_attempts` — failed login counter
- `user_lastvisit` — last session close timestamp
- `user_form_salt` — per-user CSRF salt
- `user_permissions` — ACL bitstring (newline-delimited)
- `user_type` — USER_NORMAL(0), USER_INACTIVE(1), USER_FOUNDER(3)
- `reset_token` — password reset token
- `reset_token_expiration` — token expiry timestamp

---

## 9. Patterns Worth Noting for JWT Migration

### Patterns to Preserve
1. **Provider abstraction** — The `provider_interface` pattern is clean; new JWT service should support pluggable auth backends
2. **Rate limiting** — IP-based + user-based attempt tracking is solid, needs equivalent in JWT service
3. **Password hash auto-upgrade** — `convert_flag` pattern elegantly upgrades legacy hashes on login
4. **CSRF protection** — `user_form_salt` + `check_form_key()` for state-changing requests
5. **Timing-safe comparison** — `hash_equals()` for reset tokens

### Patterns to Replace
1. **Session ID as MD5** — `md5(unique_id())` is predictable; JWT tokens are cryptographically signed
2. **DB-stored sessions** — Every request queries `phpbb_sessions` JOIN `phpbb_users`; JWT is stateless
3. **Cookie-based SID** — SID passed in cookies AND URL params; JWT should be header-only (Bearer token)
4. **IP validation per-request** — Breaks mobile/proxy users; JWT payload can include claims without per-request IP binding
5. **Global state** — Heavy reliance on `global $phpbb_app_container`; new service should be properly DI'd
6. **Session GC via cron** — Stateless JWT doesn't need garbage collection
7. **Browser fingerprinting** — Storing/comparing exact browser strings; not suitable for API clients

### Key Considerations for JWT Design
- **Autologin** maps to refresh tokens (long-lived)
- **session_admin** maps to elevated JWT claims or separate admin token
- **Ban checks** currently happen on every session_begin; need equivalent middleware
- **`validate_session()`** provider hook — JWT equivalent would be token validation middleware
- **Multiple simultaneous sessions** — JWT naturally supports this without DB rows
- **Form salt / CSRF** — JWT Bearer auth is inherently CSRF-immune for API calls; web forms still need CSRF tokens
