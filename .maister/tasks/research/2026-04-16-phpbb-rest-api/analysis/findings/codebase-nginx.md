# Nginx Configuration Analysis — REST API Routing

**Source category**: `codebase-nginx`
**Research question**: How to configure nginx to route `/api/`, `/adm/api/`, and `/install/api/` paths to new PHP entry points while keeping existing phpBB routing intact?

---

## 1. Current Nginx Configuration

**Source**: `docker/nginx/default.conf` (full file, 77 lines)

### Document Root & Server

```nginx
server {
    listen 80;
    server_name localhost;
    root /var/www/phpbb/web;
    index index.php;
    charset utf-8;
```

- **Document root**: `/var/www/phpbb/web` (maps to `web/` in the repository)
- **PHP-FPM upstream**: `app:9000` (Docker service)

### Main Location (Symfony catch-all)

```nginx
location / {
    try_files $uri $uri/ /app.php?$query_string;
}
```

All unresolved URIs fall back to `web/app.php` with the original query string preserved.  
This is the standard Symfony/phpBB front-controller pattern.

### PHP-FPM Location Block

```nginx
location ~ ^(.+\.php)(/.*)? {
    fastcgi_pass app:9000;
    fastcgi_index index.php;
    fastcgi_split_path_info ^(.+\.php)(/.*)$;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param PATH_INFO $fastcgi_path_info;
    fastcgi_param PHPBB_ROOT_PATH /var/www/phpbb/;
    include fastcgi_params;
}
```

Key observations:
- Regex `~ ^(.+\.php)(/.*)?` matches **any** `.php` file with optional PATH_INFO suffix.
- `SCRIPT_FILENAME` is set dynamically from `$document_root$fastcgi_script_name` — it resolves to the actual `.php` file in the URI.
- `PHPBB_ROOT_PATH` is injected as a FastCGI param (used by phpBB bootstrap).
- This is a **regex location** (lower priority than `^~` prefix locations).

### Static Asset Locations

```nginx
location /assets/  { alias /var/www/phpbb/web/assets/; ... }
location /images/  { alias /var/www/phpbb/web/images/; ... }
location /adm/images/ { alias /var/www/phpbb/web/adm/images/; ... }
location /src/phpbb/styles/ { alias /var/www/phpbb/src/phpbb/styles/; ... }
location /files/   { alias /var/www/phpbb/files/; }
```

### Security Denials

```nginx
location ~ ^/(src|bin|cache|store|config)/ { deny all; return 404; }
location ~ /\. { deny all; return 404; }
```

### Existing API-like Routing

**None present.** There are no `/api/`, `/adm/api/`, or `/install/api/` location blocks.

---

## 2. Existing PHP Entry Points

### Forum entry point — `web/app.php`

**Source**: `web/app.php` lines 1–30

```php
define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../');
$phpbb_root_path = './';
include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php');
$user->session_begin();
$auth->acl($user->data);
$user->setup('app');
$http_kernel = $phpbb_container->get('http_kernel');
```

Pattern: bootstraps common and uses Symfony `http_kernel` to dispatch requests via the Symfony Router.

### Install entry point — `web/install.php`

**Source**: `web/install.php`

```php
define('PHPBB_ROOT_PATH', __DIR__ . '/../');
require('../src/phpbb/install/app.php');
```

Minimal wrapper delegating to `src/phpbb/install/app.php`.

### Admin entry point — `web/adm/index.php`

**Source**: `web/adm/index.php` lines 1–30

```php
define('ADMIN_START', true);
define('NEED_SID', true);
$phpbb_root_path = '../../';
require($phpbb_root_path . 'src/phpbb/common/common.php');
// legacy ACP bootstrap — no Symfony http_kernel
```

Legacy style entry (no Symfony HTTP kernel). Future `web/adm/api.php` should probably follow this bootstrap style but respond with JSON.

---

## 3. Symfony Routing Configuration

### Default (forum) routing

**Source**: `src/phpbb/common/config/default/routing/routing.yml`

```yaml
phpbb_cron_routing:
    resource: cron.yml
    prefix: /cron

phpbb_feed_routing:
    resource: feed.yml
    prefix: /feed

phpbb_help_routing:
    resource: help.yml
    prefix: /help

phpbb_report_routing:
    resource: report.yml   # no prefix → routes from report.yml are at /

phpbb_ucp_routing:
    resource: ucp.yml
    prefix: /user
```

**Key finding**: None of the existing forum routes use an `/api/` prefix. A new `api.yml` file included with `prefix: /api` would be cleanly isolated.

### Installer routing

**Source**: `src/phpbb/common/config/installer/routing/installer.yml` + `environment.yml`

Routes: `/`, `/license`, `/support`, `/install`, `/update`, `/convert/*`, `/download/*`  
No prefixes; all installer routes live at root of the install prefix (which nginx maps via PATH_INFO on `install.php`).

---

## 4. Nginx Location Block Strategy for API Entry Points

### Priority Mechanics

Nginx location matching order relevant here:

| Priority | Modifier | Match type |
|----------|----------|------------|
| 1 | `=` | Exact match |
| 2 | `^~` | Longest prefix (stops regex check) |
| 3 | `~` / `~*` | Regex (longest wins if tied) |
| 4 | none | Longest prefix (allows regex after) |

The existing PHP regex `~ ^(.+\.php)(/.*)?` is **lower priority** than any `^~` prefix block.  
Adding `^~` blocks for `/api/`, `/adm/api/`, `/install/api/` ensures those paths are never passed to the regex or the `try_files` fallback.

### Proposed Location Blocks

Place these **before** the general PHP regex location block:

```nginx
# ─── Forum REST API ────────────────────────────────────────────────────────
location ^~ /api/ {
    fastcgi_pass  app:9000;
    fastcgi_param SCRIPT_FILENAME $document_root/api.php;
    fastcgi_param REQUEST_URI     $request_uri;
    fastcgi_param PHPBB_ROOT_PATH /var/www/phpbb/;
    include fastcgi_params;

    # CORS headers for REST clients
    add_header 'Access-Control-Allow-Origin'  '*' always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, PATCH, DELETE, OPTIONS' always;
    add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With' always;
    if ($request_method = OPTIONS) {
        return 204;
    }
}

# ─── Admin REST API ────────────────────────────────────────────────────────
location ^~ /adm/api/ {
    fastcgi_pass  app:9000;
    fastcgi_param SCRIPT_FILENAME $document_root/adm/api.php;
    fastcgi_param REQUEST_URI     $request_uri;
    fastcgi_param PHPBB_ROOT_PATH /var/www/phpbb/;
    include fastcgi_params;

    add_header 'Access-Control-Allow-Origin'  '*' always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, PATCH, DELETE, OPTIONS' always;
    add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With' always;
    if ($request_method = OPTIONS) {
        return 204;
    }
}

# ─── Install REST API ──────────────────────────────────────────────────────
location ^~ /install/api/ {
    fastcgi_pass  app:9000;
    fastcgi_param SCRIPT_FILENAME $document_root/install/api.php;
    fastcgi_param REQUEST_URI     $request_uri;
    fastcgi_param PHPBB_ROOT_PATH /var/www/phpbb/;
    include fastcgi_params;

    add_header 'Access-Control-Allow-Origin'  '*' always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, PATCH, DELETE, OPTIONS' always;
    add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With' always;
    if ($request_method = OPTIONS) {
        return 204;
    }
}
```

### Why direct `SCRIPT_FILENAME` instead of `try_files`

`try_files` attempts to find a real file/directory before falling back. For API entry points this is wrong — `GET /api/users` does not correspond to a file. Instead we hardcode `SCRIPT_FILENAME` to the specific entry point file and let `REQUEST_URI` pass through untouched so the PHP router can parse the path.

This is the same technique phpBB's installer uses when nginx routes `/install.php/support` — `fastcgi_split_path_info` sets `PATH_INFO` from the URI remainder.

### `REQUEST_URI` availability in PHP

`fastcgi_params` (included via `include fastcgi_params;`) already defines `REQUEST_URI` from `$request_uri`. The explicit `fastcgi_param REQUEST_URI $request_uri;` line is a reinforcement but may be omitted if the system `fastcgi_params` already includes it. The PHP entry point can read `$_SERVER['REQUEST_URI']` to get the full `/api/...` path for routing.

---

## 5. Entry Point File Paths

| URI prefix | Nginx `SCRIPT_FILENAME` | File to create |
|------------|------------------------|----------------|
| `/api/*` | `$document_root/api.php` | `web/api.php` |
| `/adm/api/*` | `$document_root/adm/api.php` | `web/adm/api.php` |
| `/install/api/*` | `$document_root/install/api.php` | `web/install/api.php` |

Document root in Docker container is `/var/www/phpbb/web`, which maps to the repository `web/` directory.

---

## 6. Impact on Existing phpBB Routes

| Route pattern | Current handling | After change |
|---------------|-----------------|--------------|
| `/` | `try_files → app.php` | **Unchanged** |
| `/viewtopic.php` | PHP regex location | **Unchanged** |
| `/feed`, `/help`, `/user/*` | `try_files → app.php` → Symfony router | **Unchanged** |
| `/adm/index.php` | PHP regex location | **Unchanged** |
| `/install.php/*` | PHP regex location (PATH_INFO) | **Unchanged** |
| `/api/*` | was: `try_files → app.php` (would 404) | **New: → `web/api.php`** |
| `/adm/api/*` | was: `try_files → app.php` (would 404) | **New: → `web/adm/api.php`** |
| `/install/api/*` | was: `try_files → app.php` (would 404) | **New: → `web/install/api.php`** |

No existing phpBB routes conflict. The `/api/`, `/adm/api/`, `/install/api/` paths are currently unreachable (they would fall into `app.php` which has no routes at those prefixes and would return 404).

---

## 7. Gaps & Uncertainties

- **HTTPS/TLS**: Current config is HTTP-only. For production REST API, TLS termination should be configured. Confidence: known gap, out of scope for this research.
- **Rate limiting**: No `limit_req` in current config. For public REST API endpoints, rate limiting at nginx level is recommended. Not blocking implementation.
- **CORS `Allow-Origin: *`**: Wildcard origin is acceptable for a forum API but production installs may want to restrict to known origins. Implementation can start with `*` and tighten later.
- **`adm/api.php` bootstrap**: The admin ACP does not use Symfony's `http_kernel`; a new `web/adm/api.php` would need to decide whether to use Symfony routing or a simpler custom router. Nginx routing is the same either way.

---

## Summary

**Confidence**: High (95%) — based on direct reading of nginx config and all relevant entry points.

The safest, cleanest strategy for routing API paths:
1. Add three `location ^~ /…/api/` blocks in `docker/nginx/default.conf`, placed before the PHP regex location.
2. Each block hardcodes `SCRIPT_FILENAME` to the dedicated PHP entry point file.
3. `REQUEST_URI` is passed through automatically (via `fastcgi_params`), so the PHP layer sees the full original URI.
4. CORS preflight handled at nginx level with `if ($request_method = OPTIONS) { return 204; }`.
5. No existing phpBB routes are affected.
