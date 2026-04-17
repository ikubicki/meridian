# Spec Audit: phpBB REST API Phase 1

**Audited file**: `.maister/tasks/development/2026-04-16-phpbb-rest-api/spec.md`  
**Date**: 2026-04-16  
**Auditor**: spec-auditor (independent — no implementation assumptions trusted)

---

## Audit Methodology

Every finding below is based on direct code inspection of the files listed in the brief.
No claims from prior analysis documents were trusted without verification.

---

## BLOCKER Findings

---

### BLOCKER-1 — `auth_subscriber` has no API path prefix guard — will 501 all existing HttpKernel routes

**Spec Reference**: NEW-3 (`src/phpbb/api/event/auth_subscriber.php`); Core Req 6; Success Criterion 6  
**Implementation Reference**: `src/phpbb/common/config/default/container/services.yml`, `src/phpbb/common/config/default/container/services_api.yml` (NEW-12)

**Evidence**:

`services_api.yml` (NEW-12) registers `api.auth_subscriber` inside the **main shared forum container**:
```yaml
api.auth_subscriber:
    class: phpbb\api\event\auth_subscriber
    tags:
        - { name: kernel.event_subscriber }
```

The main `services.yml` imports `services_api.yml` (MOD-2):
```yaml
- { resource: services_api.yml }
```

This means `api.auth_subscriber` is added to the **single shared `@dispatcher`** which is used by the **same `@http_kernel`** that `web/app.php` invokes too:

```php
// web/app.php (existing, unchanged)
$http_kernel = $phpbb_container->get('http_kernel');      // same kernel
$symfony_request = $phpbb_container->get('symfony_request');
$response = $http_kernel->handle($symfony_request);       // same dispatcher fires
```

The `auth_subscriber.on_kernel_request()` (NEW-3) logic:
```php
$path = $event->getRequest()->getPathInfo();
if (substr($path, -strlen('/health')) === '/health') { return; }
$event->setResponse(new JsonResponse(['error' => '...', 'status' => 501], 501));
```

For a request to `/feed` (a real phpBB route):
- `$path` = `/feed`
- does not end with `/health` → subscriber **returns 501**

For `/app.php?controller=...` → `$path` = `/app.php` → **returns 501**.

**Impact**: Every Symfony HttpKernel-routed phpBB URL (`/feed`, `/help/*`, `/user/*`, controller-based routes) returns HTTP 501 the moment `services_api.yml` is imported. Success Criterion 6 ("Existing routes work") is **structurally broken**.

**Recommendation**: Add a path prefix guard at the top of `on_kernel_request()`:

```php
$path = $event->getRequest()->getPathInfo();

// Only guard API paths; leave existing forum routes untouched
if (strpos($path, '/api/v') !== 0
    && strpos($path, '/adm/api/v') !== 0
    && strpos($path, '/install/api/v') !== 0)
{
    return;
}

if (substr($path, -strlen('/health')) === '/health')
{
    return;
}

$event->setResponse(new JsonResponse([
    'error'  => 'API token authentication not yet implemented',
    'status' => 501,
], 501));
```

---

### BLOCKER-2 — `json_exception_subscriber` has no API path prefix guard — converts all forum exceptions to JSON

**Spec Reference**: NEW-2 (`src/phpbb/api/event/json_exception_subscriber.php`); Core Req 5; Section "Exception Handling"  
**Implementation Reference**: `src/phpbb/common/config/default/container/services_api.yml` (NEW-12), `src/phpbb/forums/event/kernel_exception_subscriber.php`

**Evidence**:

`services_api.yml` (NEW-12) registers `api.exception_listener` (priority 10) into the shared forum container's dispatcher:
```yaml
api.exception_listener:
    class: phpbb\api\event\json_exception_subscriber
    arguments:
        - '%debug.exceptions%'
    tags:
        - { name: kernel.event_subscriber }
```

The existing HTML subscriber in `services_event.yml`:
```yaml
kernel_exception_subscriber:
    class: phpbb\event\kernel_exception_subscriber
    # ...
    tags:
        - { name: kernel.event_subscriber }
```

Its `getSubscribedEvents()` (verified at `src/phpbb/forums/event/kernel_exception_subscriber.php:148`):
```php
return array(
    KernelEvents::EXCEPTION => 'on_kernel_exception',   // default priority = 0
);
```

The API's `json_exception_subscriber.getSubscribedEvents()` (NEW-2 spec):
```php
return [
    KernelEvents::EXCEPTION => ['on_kernel_exception', 10],   // priority 10 > 0
];
```

`json_exception_subscriber.on_kernel_exception()` (NEW-2 spec) immediately:
1. Calls `$event->setResponse(new JsonResponse(...))` 
2. Calls `$event->stopPropagation()`  — **prevents HTML subscriber from running**

For a normal phpBB 404 from `web/app.php` (valid scenario today):
- `json_exception_subscriber` fires at priority 10 → sets JSON 404 → stops propagation
- `kernel_exception_subscriber` (HTML) **never runs**
- User sees `{"error":"...","status":404}` instead of phpBB's HTML 404 page

**Impact**: Any unhandled exception or HTTP error on existing phpBB forum routes returns JSON instead of the phpBB-styled HTML page. This breaks the forum's user-facing error handling immediately upon `services_api.yml` import.

**Recommendation**: Add a path prefix check at the top of `on_kernel_exception()` in NEW-2:

```php
public function on_kernel_exception(GetResponseForExceptionEvent $event)
{
    $path = $event->getRequest()->getPathInfo();

    // Only handle exceptions for API routes; let HTML subscriber handle forum errors
    if (strpos($path, '/api/v') !== 0
        && strpos($path, '/adm/api/v') !== 0
        && strpos($path, '/install/api/v') !== 0)
    {
        return;
    }

    $exception = $event->getException();
    // ... rest of existing logic
}
```

---

### BLOCKER-3 — `web/install/api.php` missing `$phpbb_root_path` assignment — installer startup.php will crash

**Spec Reference**: NEW-19 (`web/install/api.php`); Section "Reusable Components" (install/app.php pattern)  
**Implementation Reference**: `src/phpbb/install/app.php:19`, `src/phpbb/install/startup.php:246–282`

**Evidence**:

The existing `src/phpbb/install/app.php` (the stated pattern):
```php
// Line 19 — sets $phpbb_root_path BEFORE requiring startup.php
$phpbb_root_path = defined('PHPBB_ROOT_PATH') ? PHPBB_ROOT_PATH : '../../../';
// ...
require($startup_path);   // startup.php uses $phpbb_root_path freely
```

`src/phpbb/install/startup.php` uses `$phpbb_root_path` as a free variable in global scope:
```php
// Line 246
phpbb_require_updated('src/phpbb/common/startup.php', $phpbb_root_path);
phpbb_require_updated('src/phpbb/forums/class_loader.php', $phpbb_root_path);
installer_class_loader($phpbb_root_path);
// ...
$phpbb_installer_container_builder = new \phpbb\di\container_builder($phpbb_root_path);  // Line 277
$other_config_path = $phpbb_root_path . 'install/update/new/config';                     // Line 280
$config_path = (file_exists(...)) ? $other_config_path : $phpbb_root_path . 'src/phpbb/common/config';
```

The spec's NEW-19:
```php
define('IN_INSTALL', true);
define('PHPBB_ROOT_PATH', __DIR__ . '/../../');

require PHPBB_ROOT_PATH . 'src/phpbb/install/startup.php';
// ← $phpbb_root_path is NEVER set
```

When startup.php executes, `$phpbb_root_path` is `null`/`''`. All `phpbb_require_updated()` calls fail silently (file paths become `"install/update/new/src/phpbb/common/startup.php"` with an empty root). The container builder receives empty string as root path → incorrect cache/config paths → **either PHP fatal error or misconfigured container that cannot find any service**.

**Recommendation**: Add one line in NEW-19, matching the install/app.php pattern exactly:

```php
define('IN_INSTALL', true);
define('PHPBB_ROOT_PATH', __DIR__ . '/../../');
$phpbb_root_path = PHPBB_ROOT_PATH;             // ← ADD THIS LINE

require PHPBB_ROOT_PATH . 'src/phpbb/install/startup.php';
$phpbb_installer_container->get('install_api.application')->run();
```

---

## WARNING Findings

---

### WARNING-1 — `%debug.exceptions%` parameter not confirmed in installer container

**Spec Reference**: NEW-13 (`services_install_api.yml`)  
**Implementation Reference**: `src/phpbb/common/config/installer/container/services.yml`

**Evidence**:

`services_install_api.yml` (NEW-13):
```yaml
install_api.exception_listener:
    class: phpbb\api\event\json_exception_subscriber
    arguments:
        - '%debug.exceptions%'
```

The installer container imports `../../default/container/services_event.yml` which also references `%debug.exceptions%` (in the `kernel_exception_subscriber` definition present in that file). However, the installer's local `services.yml` immediately overrides `kernel_exception_subscriber` with a different class — meaning the default entry may never be used, and the parameter may still be defined through a separate `parameters.yml`.

The parameter is used in the forum container (confirmed via `services_event.yml`). Whether it's defined in the installer container's parameter chain requires checking `src/phpbb/common/config/installer/` for a `parameters.yml` or equivalent.

**Risk**: If `%debug.exceptions%` is not defined in the installer container's parameter bag, the container build will throw `ParameterNotFoundException` and `web/install/api.php` will fail to bootstrap.

**Recommendation**: Before implementation, run the installer container build and confirm the parameter exists:
```bash
docker compose exec app php -r "
  define('IN_INSTALL', true);
  \$phpbb_root_path = '/var/www/phpbb/';
  require '/var/www/phpbb/src/phpbb/install/startup.php';
  var_dump(\$phpbb_installer_container->getParameter('debug.exceptions'));
"
```
If it throws, add `parameters: { debug.exceptions: false }` to `services_install_api.yml`.

---

### WARNING-2 — `environment.yml` multi-key routing not verified against phpBB's routing loader

**Spec Reference**: MOD-5 (`src/phpbb/common/config/installer/routing/environment.yml`)  
**Implementation Reference**: `src/phpbb/common/config/installer/routing/environment.yml`

**Evidence**:

Current `environment.yml` (verified):
```yaml
core.default:
    resource: installer.yml
```

Spec MOD-5 proposes:
```yaml
core.default:
    resource: installer.yml

core.install_api:
    resource: install_api.yml
```

The key names (`core.default`, `core.install_api`) follow a phpBB-specific naming pattern. Whether phpBB's routing file locator/loader iterates over **all** keys in `environment.yml` or only the `core.default` key is not documented in the spec and was not verified against the loader source code during this audit.

**Risk**: If the routing loader has special handling for `core.default` and ignores other keys, `/install/api/v1/health` will return 404 regardless of the controller setup.

**Recommendation**: Before implementation, verify that phpBB's routing infrastructure in the installer context loads all keys from `environment.yml`. Search the routing loader:
```bash
grep -r "environment.yml\|core\.default" src/phpbb/common/ --include="*.php" -l
```
If the loader only processes `core.default`, a simpler approach is to add the install_api routes directly to `installer.yml` rather than a separate file, or use `_import:` syntax if supported.

---

### WARNING-3 — `auth_subscriber` suffix check risks future false positives

**Spec Reference**: NEW-3 (`auth_subscriber`); Section "Auth Gate"  
**Evidence** (code from spec):

```php
if (substr($path, -strlen('/health')) === '/health') { return; }
```

If Phase 2 adds a route like `/api/v1/app-health` or `/api/v1/topics/{id}/health`, those would bypass the auth gate. The check is correct for Phase 1's exact routes (`/api/v1/health`, `/adm/api/v1/health`, `/install/api/v1/health`) but has no segment-boundary assertion.

**Risk**: Low for Phase 1. Becomes a real exposure in Phase 2 when more routes are added.

**Recommendation** (can defer to Phase 2): Replace suffix check with an exact set or use a path-ends-with-full-segment pattern:
```php
// Exact match is safer for Phase 1 — no performance cost with 3 routes
$health_paths = ['/api/v1/health', '/adm/api/v1/health', '/install/api/v1/health'];
if (in_array($path, $health_paths, true)) { return; }
```

---

## INFO Findings

---

### INFO-1 — `web/install/` directory does not exist and must be created

**Spec Reference**: NEW-19  
**Evidence**: `file_search` for `web/install/` returns no matches. Workspace listing confirms `web/` has no `install/` subdirectory.

The nginx block in MOD-6 hardcodes `SCRIPT_FILENAME $document_root/install/api.php`. PHP-FPM will fail with "No input file specified" unless the directory and file both exist. Creating `web/install/api.php` (NEW-19) implicitly requires `mkdir web/install/` first.

**Recommendation**: Add an explicit step before NEW-19: `mkdir -p web/install/` (or note this in the implementation checklist).

---

### INFO-2 — `IN_ADMIN` defined after `common.php` in `web/adm/api.php` — needs pattern verification

**Spec Reference**: NEW-18 (`web/adm/api.php`)  
**Evidence** (from spec):

```php
define('ADMIN_START', true);
define('NEED_SID', true);
define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../../');
$phpbb_root_path = '../../';

include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php');

define('IN_ADMIN', true);   // ← after common.php
```

The spec states this follows the `web/adm/index.php` pattern, but `web/adm/index.php` was not listed in the audit-brief files. Since Phase 1 has no session/ACL calls and `IN_ADMIN` is only checked by `page_header()` / `page_footer()` functions (neither called by API controllers), this is likely benign. But if any service or subscriber loaded by `common.php` reads `IN_ADMIN`, it would see `false`.

**Recommendation**: Verify against `web/adm/index.php` before implementation. If `IN_ADMIN` appears before `common.php` there, move it in NEW-18 to maintain the same bootstrap order (i.e., all three admin constants defined before the include).

---

### INFO-3 — nginx CORS headers on OPTIONS 204 — `add_header` + `if` interaction

**Spec Reference**: MOD-6 (nginx blocks)  
**Evidence**: In the spec's nginx block:
```nginx
add_header 'Access-Control-Allow-Origin'  '*'  always;
add_header 'Access-Control-Allow-Methods' '...' always;
add_header 'Access-Control-Allow-Headers' '...' always;
if ($request_method = OPTIONS) { return 204; }
```

Nginx `if` blocks inherit `add_header` directives from the enclosing `location` when no `add_header` is placed inside the `if` block itself. Combined with `always`, CORS headers should be sent on the 204 response. This is a well-known pattern. No config syntax errors found.

**Recommendation**: No change needed. Validate with `curl -I -X OPTIONS http://localhost:8181/api/v1/health` post-deploy and confirm `Access-Control-Allow-Origin: *` is present in the response headers.

---

## Verdict

> **❌ BLOCKED — Do not implement from this spec without the required changes below.**

---

## Required Changes Before Implementation

| # | File | Change Required |
|---|------|-----------------|
| 1 | `src/phpbb/api/event/auth_subscriber.php` (NEW-3) | Add API path prefix guard at top of `on_kernel_request()` — check path starts with `/api/v`, `/adm/api/v`, or `/install/api/v`; return immediately if not |
| 2 | `src/phpbb/api/event/json_exception_subscriber.php` (NEW-2) | Add API path prefix guard at top of `on_kernel_exception()` — same prefix check; return immediately if not an API path |
| 3 | `web/install/api.php` (NEW-19) | Add `$phpbb_root_path = PHPBB_ROOT_PATH;` on the line after `define('PHPBB_ROOT_PATH', ...)` and before the `require` |
| 4 | `src/phpbb/common/config/installer/container/services_install_api.yml` (NEW-13) | Verify `%debug.exceptions%` is available in installer container; add inline `parameters: { debug.exceptions: false }` if not |
| 5 | Implementation notes | Add explicit `mkdir -p web/install/` step before creating `web/install/api.php` |

Changes 1, 2, and 3 are **required before any code is written**. Changes 4 and 5 can be resolved during implementation if validated early.
