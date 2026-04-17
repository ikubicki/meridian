# Implementation Plan: phpBB REST API — Phase 1

**Spec**: `implementation/spec.md`  
**Date**: 2026-04-16  
**Risk**: Medium  
**Testing approach**: No unit tests (phpBB standard). Functional validation via `curl` integration tests only.

---

## Overview

| Metric | Value |
|--------|-------|
| Task Groups | 7 |
| New files | 19 |
| Modified files | 6 |
| Total checklist items | ~55 |
| Acceptance tests | 6 curl commands |

---

## Execution Order

1. **Group 1** — Foundation (PSR-4 + Application class) — no dependencies
2. **Group 2** — Event Subscribers — depends on Group 1
3. **Group 3** — Forum Mock Controllers — no dependencies (can run in parallel with Group 2)
4. **Group 4** — Admin + Install Mock Controllers — no dependencies (can run in parallel with Groups 2–3)
5. **Group 5** — DI Services YAML — depends on Groups 1–4
6. **Group 6** — Routing YAML — depends on Group 5
7. **Group 7** — Entry Points + Nginx — depends on Groups 5–6

---

## Group 1: Foundation

**Dependencies**: None  
**Files**: 1 new, 1 modified  

### Why first
`phpbb\api\` namespace is not registered in `composer.json` — PHP cannot load any `phpbb\api\*` class until this is fixed. `phpbb\core\Application` is also required before DI services can declare it.

### Tasks

- [ ] 1.1 **MOD `composer.json`** — add `"phpbb\\api\\": "src/phpbb/api/"` to `autoload.psr-4`
  - Insert **before** `"phpbb\\install\\"` line to respect PHP PSR-4 specificity ordering
  - Exact diff from spec MOD-1
- [ ] 1.2 **Run `composer dump-autoload`** inside the Docker container
  - `docker compose exec app composer dump-autoload`
  - Verify: no errors, `vendor/composer/autoload_psr4.php` now contains `phpbb\\api\\`
- [ ] 1.3 **CREATE `src/phpbb/core/Application.php`** — ~40-line wrapper class
  - Namespace: `phpbb\core`
  - Implements `HttpKernelInterface`, `TerminableInterface`
  - Constructor: `(HttpKernel $kernel, ContainerInterface $container)`
  - Method `run()`: fetches `Request` from container via `symfony_request`, calls `handle()` → `send()` → `terminate()`
  - Composition, not inheritance
  - No closing PHP tag, tabs indentation, phpBB copyright header
  - Exact code from spec NEW-1

### Acceptance Criteria

- `composer dump-autoload` exits 0 with no warnings
- `vendor/composer/autoload_psr4.php` contains entry for `phpbb\\api\\`
- `src/phpbb/core/Application.php` is parseable: `php -l src/phpbb/core/Application.php` returns no errors
- Class exists lint check: `php -r "require 'vendor/autoload.php'; new phpbb\core\Application(null, null);"` — no "class not found" fatal

---

## Group 2: Event Subscribers

**Dependencies**: Group 1 (namespace must be registered)  
**Files**: 2 new  

### Why after Group 1
Both classes live in `phpbb\api\event\` — namespace must be registered before files are parseable.

### Tasks

- [ ] 2.1 **CREATE `src/phpbb/api/event/json_exception_subscriber.php`**
  - Namespace: `phpbb\api\event`
  - Implements `EventSubscriberInterface`
  - `getSubscribedEvents()`: listens on `KernelEvents::EXCEPTION` at priority **10**
  - Guard: check `$request->getPathInfo()` starts with `/api/`, `/adm/api/`, or `/install/api/` before acting
  - If not an API path: return immediately (let existing HTML subscriber handle it)
  - If API path: build `JsonResponse(['error' => $exception->getMessage(), 'status' => $statusCode])`, call `$event->setResponse()` then `$event->stopPropagation()`
  - Symfony 3.4 event class: `GetResponseForExceptionEvent` (NOT `ExceptionEvent`)
  - Template: mirror structure of `src/phpbb/forums/event/kernel_exception_subscriber.php`
  - No `@template`, no `@language` dependencies — standalone class
  - Debug mode: add `'trace' => $exception->getTraceAsString()` to response body

- [ ] 2.2 **CREATE `src/phpbb/api/event/auth_subscriber.php`**
  - Namespace: `phpbb\api\event`
  - Implements `EventSubscriberInterface`
  - `getSubscribedEvents()`: listens on `KernelEvents::REQUEST` at priority **0**
  - Guard: check path starts with `/api/`, `/adm/api/`, or `/install/api/` — return immediately if not API
  - Health bypass: if path ends with `/health`, do nothing (allow request to pass through)
  - For all other API paths: set `JsonResponse(['error' => 'API token authentication not yet implemented', 'status' => 501], 501)` on the event; do NOT call `stopPropagation()`
  - Symfony 3.4 event class: `GetResponseEvent` (NOT `RequestEvent`)
  - No phpBB service dependencies in constructor

- [ ] 2.3 **Syntax check both files**
  - `php -l src/phpbb/api/event/json_exception_subscriber.php`
  - `php -l src/phpbb/api/event/auth_subscriber.php`

### Acceptance Criteria

- Both files pass `php -l` with no errors
- `json_exception_subscriber` registers on `kernel.exception` at priority 10
- `auth_subscriber` registers on `kernel.request` at priority 0
- Neither class has a constructor parameter that is a phpBB-specific service

---

## Group 3: Forum Mock Controllers

**Dependencies**: None (can be created before Group 1 if desired, but autoload must be in place before they are loaded)  
**Files**: 4 new  

### Tasks

- [ ] 3.1 **CREATE `src/phpbb/api/v1/controller/health.php`**
  - Namespace: `phpbb\api\v1\controller`
  - No constructor
  - `index()` → `new JsonResponse(['status' => 'ok', 'api' => 'phpBB Forum API', 'version' => '1.0.0-dev'])`

- [ ] 3.2 **CREATE `src/phpbb/api/v1/controller/forums.php`**
  - Namespace: `phpbb\api\v1\controller`
  - No constructor
  - `index()` → `JsonResponse` with hardcoded array: `['forums' => [['id' => 1, 'name' => 'General Discussion', 'description' => 'General discussion topics', 'topic_count' => 0, 'post_count' => 0]]]`

- [ ] 3.3 **CREATE `src/phpbb/api/v1/controller/topics.php`**
  - Namespace: `phpbb\api\v1\controller`
  - No constructor
  - `index()` → `JsonResponse` with hardcoded `['topics' => [['id' => 1, 'title' => 'Welcome to phpBB', 'forum_id' => 1, 'post_count' => 1]]]`
  - `show(int $id)` → `JsonResponse` with `['topic' => ['id' => $id, 'title' => 'Welcome to phpBB', 'forum_id' => 1, 'post_count' => 1]]`

- [ ] 3.4 **CREATE `src/phpbb/api/v1/controller/users.php`**
  - Namespace: `phpbb\api\v1\controller`
  - No constructor
  - `me()` → `JsonResponse` with `['user' => ['id' => 0, 'username' => 'guest', 'email' => '', 'group_id' => 1]]`

- [ ] 3.5 **Syntax check all 4 files**
  - `php -l` on each controller

### Acceptance Criteria

- All 4 files pass `php -l`
- No constructor with phpBB service parameters in any controller
- Each method returns `JsonResponse` with the exact payload shape from spec

---

## Group 4: Admin + Install Mock Controllers

**Dependencies**: None  
**Files**: 4 new  

### Tasks

- [ ] 4.1 **CREATE `src/phpbb/admin/api/v1/controller/health.php`**
  - Namespace: `phpbb\admin\api\v1\controller`
  - No constructor
  - `index()` → `JsonResponse(['status' => 'ok', 'api' => 'phpBB Admin API'])`

- [ ] 4.2 **CREATE `src/phpbb/admin/api/v1/controller/users.php`**
  - Namespace: `phpbb\admin\api\v1\controller`
  - No constructor
  - `index()` → `JsonResponse(['users' => [['id' => 2, 'username' => 'admin', 'email' => 'admin@example.com', 'group_id' => 5]]])`

- [ ] 4.3 **CREATE `src/phpbb/install/api/v1/controller/health.php`**
  - Namespace: `phpbb\install\api\v1\controller`
  - No constructor
  - `index()` → `JsonResponse(['status' => 'ok', 'api' => 'phpBB Install API'])`

- [ ] 4.4 **CREATE `src/phpbb/install/api/v1/controller/status.php`**
  - Namespace: `phpbb\install\api\v1\controller`
  - No constructor
  - `index()` → `JsonResponse(['installed' => false, 'version' => '3.3.x-dev'])`

- [ ] 4.5 **Syntax check all 4 files**
  - `php -l` on each controller

### Acceptance Criteria

- All 4 files pass `php -l`
- No constructor with phpBB service parameters in any controller
- Correct namespace per file (admin vs install differ)

---

## Group 5: DI Configuration

**Dependencies**: Groups 1, 2, 3, 4 (all service classes must exist before YAML is loaded)  
**Files**: 2 new, 2 modified  

### Tasks

- [ ] 5.1 **CREATE `src/phpbb/common/config/default/container/services_api.yml`**
  - Declare services for **both** Forum API and Admin API (they share the forum DI container)
  - Service list:
    - `phpbb.core.Application` (class parameter)
    - `api.application`: class `phpbb\core\Application`, arguments `['@http_kernel', '@service_container']`
    - `admin_api.application`: class `phpbb\core\Application`, arguments `['@http_kernel', '@service_container']`
    - `api.exception_listener`: class `phpbb\api\event\json_exception_subscriber`, tags `[{name: kernel.event_subscriber}]`
    - `api.auth_subscriber`: class `phpbb\api\event\auth_subscriber`, tags `[{name: kernel.event_subscriber}]`
    - `phpbb.api.v1.controller.health`: class `phpbb\api\v1\controller\health`
    - `phpbb.api.v1.controller.forums`: class `phpbb\api\v1\controller\forums`
    - `phpbb.api.v1.controller.topics`: class `phpbb\api\v1\controller\topics`
    - `phpbb.api.v1.controller.users`: class `phpbb\api\v1\controller\users`
    - `phpbb.admin.api.v1.controller.health`: class `phpbb\admin\api\v1\controller\health`
    - `phpbb.admin.api.v1.controller.users`: class `phpbb\admin\api\v1\controller\users`
  - Use Symfony 3.4 YAML DI syntax (no `autowire: true`, explicit `arguments:`)

- [ ] 5.2 **CREATE `src/phpbb/common/config/installer/container/services_install_api.yml`**
  - Installer container is **isolated** from forum container
  - Service list:
    - `install_api.application`: class `phpbb\core\Application`, arguments `['@http_kernel', '@service_container']`
    - `install_api.exception_listener`: class `phpbb\api\event\json_exception_subscriber`, tags `[{name: kernel.event_subscriber}]`
    - `install_api.auth_subscriber`: class `phpbb\api\event\auth_subscriber`, tags `[{name: kernel.event_subscriber}]`
    - `phpbb.install.api.v1.controller.health`: class `phpbb\install\api\v1\controller\health`
    - `phpbb.install.api.v1.controller.status`: class `phpbb\install\api\v1\controller\status`

- [ ] 5.3 **MOD `src/phpbb/common/config/default/container/services.yml`**
  - Add `- { resource: services_api.yml }` at the end of the `imports:` block (after `services_ucp.yml` or last existing import)
  - Exact diff from spec MOD-2

- [ ] 5.4 **MOD `src/phpbb/common/config/installer/container/services.yml`**
  - Add `- { resource: services_install_api.yml }` at the end of the `imports:` block
  - Exact diff from spec MOD-4

- [ ] 5.5 **Clear route/DI cache**
  - `rm -rf cache/production/`
  - Verify forum still boots: `curl -s http://localhost:8181/` must return HTML (not 500)

### Acceptance Criteria

- `services_api.yml` and `services_install_api.yml` are valid YAML (parseable)
- Forum index `curl -s http://localhost:8181/` returns HTTP 200 HTML after cache clear
- No 500 errors on forum routes (DI container compiles cleanly)

---

## Group 6: Routing

**Dependencies**: Group 5 (services must be declared before routes reference them)  
**Files**: 3 new, 2 modified  

### Tasks

- [ ] 6.1 **CREATE `src/phpbb/common/config/default/routing/api.yml`**
  - 5 routes under `/api/v1/` prefix:
    ```yaml
    phpbb_api_v1_health:
        path:       /api/v1/health
        defaults:   { _controller: phpbb.api.v1.controller.health:index }
        methods:    [GET]

    phpbb_api_v1_forums:
        path:       /api/v1/forums
        defaults:   { _controller: phpbb.api.v1.controller.forums:index }
        methods:    [GET]

    phpbb_api_v1_topics:
        path:       /api/v1/topics
        defaults:   { _controller: phpbb.api.v1.controller.topics:index }
        methods:    [GET]

    phpbb_api_v1_topics_show:
        path:       /api/v1/topics/{id}
        defaults:   { _controller: phpbb.api.v1.controller.topics:show }
        methods:    [GET]
        requirements:
            id:     \d+

    phpbb_api_v1_users_me:
        path:       /api/v1/users/me
        defaults:   { _controller: phpbb.api.v1.controller.users:me }
        methods:    [GET]
    ```

- [ ] 6.2 **CREATE `src/phpbb/common/config/default/routing/admin_api.yml`**
  - 2 routes under `/adm/api/v1/`:
    ```yaml
    phpbb_admin_api_v1_health:
        path:       /adm/api/v1/health
        defaults:   { _controller: phpbb.admin.api.v1.controller.health:index }
        methods:    [GET]

    phpbb_admin_api_v1_users:
        path:       /adm/api/v1/users
        defaults:   { _controller: phpbb.admin.api.v1.controller.users:index }
        methods:    [GET]
    ```

- [ ] 6.3 **CREATE `src/phpbb/common/config/installer/routing/install_api.yml`**
  - 2 routes under `/install/api/v1/`:
    ```yaml
    phpbb_install_api_v1_health:
        path:       /install/api/v1/health
        defaults:   { _controller: phpbb.install.api.v1.controller.health:index }
        methods:    [GET]

    phpbb_install_api_v1_status:
        path:       /install/api/v1/status
        defaults:   { _controller: phpbb.install.api.v1.controller.status:index }
        methods:    [GET]
    ```

- [ ] 6.4 **MOD `src/phpbb/common/config/default/routing/routing.yml`**
  - Append two resource imports at the end of the file (exact diff from spec MOD-3):
    ```yaml
    phpbb_api_routing:
        resource: api.yml
        prefix:   /

    phpbb_admin_api_routing:
        resource: admin_api.yml
        prefix:   /
    ```
  - Do NOT add a prefix that conflicts with existing routes (`/cron`, `/feed`, `/user`, `/help`)

- [ ] 6.5 **MOD `src/phpbb/common/config/installer/routing/environment.yml`**
  - Add `core.install_api` entry (exact diff from spec MOD-5):
    ```yaml
    core.install_api:
        resource: install_api.yml
    ```

- [ ] 6.6 **Clear cache and verify routing loads**
  - `rm -rf cache/production/`
  - `curl -s http://localhost:8181/` — forum still returns HTTP 200

### Acceptance Criteria

- All 3 routing YAML files are valid YAML
- `routing.yml` and `environment.yml` modifications are syntactically correct
- Forum index still returns HTTP 200 after cache clear (router compiles without errors)

---

## Group 7: Entry Points + Nginx

**Dependencies**: Groups 5, 6 (services and routes must exist before entry scripts run them)  
**Files**: 3 new, 1 modified  

### Tasks

- [ ] 7.1 **CREATE `web/api.php`** — Forum REST API entry point (≤15 lines)
  - Pattern from `web/app.php`
  - Define constants if needed; include `common.php`
  - Get `api.application` from container; call `run()`
  - NO `session_begin()`, NO `$auth->acl()`, NO `$user->setup()`
  - Use `$phpbb_root_path = '../'` and `$phpEx = 'php'`

- [ ] 7.2 **CREATE `web/adm/api.php`** — Admin REST API entry point
  - Pattern from `web/adm/index.php`
  - Define `ADMIN_START`, `NEED_SID`, `IN_ADMIN` before including `common.php`
  - `$phpbb_root_path = '../../'`
  - Get `admin_api.application` from container; call `run()`
  - NO session/ACL in entry script

- [ ] 7.3 **CREATE `web/install/api.php`** — Installer REST API entry point
  - Create directory `web/install/` first (does not exist)
  - Pattern from `src/phpbb/install/app.php`
  - Define `IN_INSTALL`
  - Set `$phpbb_root_path = PHPBB_ROOT_PATH;` (or `'../../'`) **before** requiring `startup.php`
  - Require `startup.php` (never `common.php` — installer has isolated container)
  - Get `install_api.application` from `$phpbb_installer_container`; call `run()`

- [ ] 7.4 **MOD `docker/nginx/default.conf`** — Add 3 API location blocks
  - Insert the three `location ^~` blocks **immediately before** the `location ~ ^(.+\.php)(/.*)? {` block
  - Exact block content from spec MOD-6:
    - `/api/` → `SCRIPT_FILENAME $document_root/api.php`
    - `/adm/api/` → `SCRIPT_FILENAME $document_root/adm/api.php`
    - `/install/api/` → `SCRIPT_FILENAME $document_root/install/api.php`
  - Each block includes CORS headers + `if ($request_method = OPTIONS) { return 204; }`
  - Note: `/adm/api/` block must come before `/api/` in the config, OR nginx longest-prefix matching handles it (both `^~` prefixes are unambiguous)

- [ ] 7.5 **Reload nginx**
  - `docker compose exec nginx nginx -s reload`
  - Verify: nginx reload exits 0 (no config errors)

- [ ] 7.6 **Clear route cache**
  - `rm -rf cache/production/`

### Acceptance Criteria

- `php -l web/api.php`, `php -l web/adm/api.php`, `php -l web/install/api.php` all pass
- `nginx -t` (config test) returns no errors
- `curl -v http://localhost:8181/api/v1/health` returns HTTP 200 (first end-to-end test)

---

## Final Integration Tests (Acceptance Criteria)

Run all 6 curl tests after completing Group 7. All must pass.

```bash
# Test 1 — Forum API health (no auth, expect 200)
curl -s -o /dev/null -w "%{http_code}" http://localhost:8181/api/v1/health
# Expected: 200
curl -s http://localhost:8181/api/v1/health
# Expected body: {"status":"ok","api":"phpBB Forum API","version":"1.0.0-dev"}

# Test 2 — Admin API health (no auth, expect 200)
curl -s -o /dev/null -w "%{http_code}" http://localhost:8181/adm/api/v1/health
# Expected: 200
curl -s http://localhost:8181/adm/api/v1/health
# Expected body: {"status":"ok","api":"phpBB Admin API"}

# Test 3 — Installer API health (no auth, expect 200)
curl -s -o /dev/null -w "%{http_code}" http://localhost:8181/install/api/v1/health
# Expected: 200
curl -s http://localhost:8181/install/api/v1/health
# Expected body: {"status":"ok","api":"phpBB Install API"}

# Test 4 — Auth stub (forums list, expect 501)
curl -s -o /dev/null -w "%{http_code}" http://localhost:8181/api/v1/forums
# Expected: 501
curl -s http://localhost:8181/api/v1/forums
# Expected body: {"error":"API token authentication not yet implemented","status":501}

# Test 5 — Auth stub (topic by ID, expect 501)
curl -s -o /dev/null -w "%{http_code}" http://localhost:8181/api/v1/topics/1
# Expected: 501

# Test 6 — Existing routes not broken
curl -s -o /dev/null -w "%{http_code}" http://localhost:8181/
# Expected: 200 (forum HTML index page)
curl -s -o /dev/null -w "%{http_code}" http://localhost:8181/feed.php
# Expected: 200 (RSS feed XML)
```

---

## Standards Compliance

All new PHP files must follow:

| Rule | Detail |
|------|--------|
| Indentation | Tabs (not spaces) |
| PHP version | 7.2+ syntax only — no `match`, no named args, no nullsafe `?->`, no `declare(strict_types=1)` |
| Closing tag | No `?>` at end of any PHP file |
| Symfony version | 3.4 event class names: `GetResponseForExceptionEvent`, `GetResponseEvent` |
| Namespace | PSR-4; `phpbb\api\` → `src/phpbb/api/` |
| File header | Standard phpBB copyright/license block in all new PHP files |
| SQL | No DB queries in Phase 1 — not applicable |
| CSRF | No state-changing POST in Phase 1 — not applicable |
| Controller dependencies | Zero phpBB service dependencies in any mock controller |

Standards reference: `.maister/docs/standards/`

---

## Cache Management

> **Critical operational note**: Any change to routing YAML or DI service YAML requires clearing the compiled cache.

```bash
rm -rf cache/production/
```

Run this:
- After Group 5 (DI services added)
- After Group 6 (routing YAML added)
- After Group 7 (entry points created — entry point changes don't need cache clear but routes do)

The cache is regenerated lazily on the first HTTP request after deletion.

---

## Rollback Plan

All changes are additive (new files + append-only edits to YAML imports). To rollback:

1. Remove the 3 `import` lines from `services.yml` and installer `services.yml`
2. Remove the 2 route import lines from `routing.yml` and `environment.yml`
3. Remove the single `phpbb\\api\\` line from `composer.json` + run `composer dump-autoload`
4. Remove the 3 nginx location blocks + reload nginx
5. Delete new files (`web/api.php`, `web/adm/api.php`, `web/install/api.php`, `web/install/` dir)
6. `rm -rf cache/production/`

No existing code is altered in a destructive way — existing `services.yml`, `routing.yml`, `composer.json` only have lines appended.
