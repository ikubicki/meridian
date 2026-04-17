# phpBB REST API — Research Report

**Type**: Mixed (Technical + Architecture)
**Date**: 2026-04-16
**Researcher**: Research Synthesis Agent

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Research Objectives](#2-research-objectives)
3. [Methodology](#3-methodology)
4. [Architecture Decision](#4-architecture-decision)
   - 4.1 [phpbb\core\Application](#41-phpbbcoreapplication)
   - 4.2 [phpbb\api\Application](#42-phpbbapplication)
   - 4.3 [phpbb\admin\api\Application](#43-phpbbadminapiapplication)
   - 4.4 [phpbb\install\api\Application](#44-phpbbinstallapiapplication)
5. [Entry Points](#5-entry-points)
6. [Nginx Configuration](#6-nginx-configuration)
7. [DI Services Required](#7-di-services-required)
8. [Routing Design](#8-routing-design)
9. [Mock Endpoints (Phase 1)](#9-mock-endpoints-phase-1)
10. [Implementation Roadmap](#10-implementation-roadmap)
11. [Risks and Open Questions](#11-risks-and-open-questions)
12. [Appendices](#12-appendices)

---

## 1. Executive Summary

phpBB already uses `Symfony\Component\HttpKernel\HttpKernel` without FrameworkBundle; the proposed
three-API architecture is a configuration extension of this existing pattern, not a re-architecture.
A shared `phpbb\core\Application` class (~40 lines) wraps the Symfony HttpKernel and provides a
`run()` method. Forum and Admin APIs share the existing forum DI container (extended via new YAML
imports); the Installer API uses phpBB's isolated installer container unchanged. The critical split:
`web/api.php` and `web/adm/api.php` include `common.php` as all other entry points do; `web/install/api.php`
must mirror `web/install.php` exactly and never touch `common.php`.

---

## 2. Research Objectives

**Primary question**: How to build `phpbb\core` as a base for three independent Symfony applications
(`phpbb\api`, `phpbb\admin\api`, `phpbb\install\api`) that expose REST APIs — eventually replacing
all existing phpBB web entry points?

**Sub-questions addressed**:
1. What does the existing bootstrap sequence look like, and what can be reused?
2. Which Symfony services need to be created vs. reused?
3. How does the installer context differ and what are the constraints?
4. What nginx changes are required?
5. What does the `phpbb\core\Application` class need to look like?
6. What are the minimum services required for a working JSON REST API on top of phpBB's HttpKernel?

**Scope**: Architecture design + implementation specification. Does not cover OAuth/JWT implementation,
database schema changes, or OpenAPI spec generation.

---

## 3. Methodology

- **Codebase analysis**: 5 information-gathering agents examined source files directly
- **Files analyzed**: ~25 PHP source files, 8 YAML config files, 1 nginx config, vendor Symfony source
- **Framework used**: Mixed (Technical + Architecture) research framework
- **Evidence quality**: All findings based on direct source code reading, not documentation

---

## 4. Architecture Decision

### 4.1 `phpbb\core\Application`

**Location**: `src/phpbb/core/Application.php`
**Namespace**: `phpbb\core`

**Design**: Composition over inheritance. Wraps `Symfony\Component\HttpKernel\HttpKernel`
and holds a reference to the DI container. Does NOT extend `HttpKernel` (avoids coupling to
Symfony internals that may be closed in future versions).

**Class definition**:

```php
<?php
/**
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbb\core;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

class Application implements HttpKernelInterface, TerminableInterface
{
	/** @var HttpKernel */
	private $kernel;

	/** @var ContainerInterface */
	private $container;

	public function __construct(HttpKernel $kernel, ContainerInterface $container)
	{
		$this->kernel    = $kernel;
		$this->container = $container;
	}

	public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true): Response
	{
		return $this->kernel->handle($request, $type, $catch);
	}

	public function terminate(Request $request, Response $response): void
	{
		$this->kernel->terminate($request, $response);
	}

	public function run(): void
	{
		/** @var Request $request */
		$request  = $this->container->get('symfony_request');
		$response = $this->handle($request);
		$response->send();
		$this->terminate($request, $response);
	}

	public function getContainer(): ContainerInterface
	{
		return $this->container;
	}
}
```

**Dependencies** (constructor injected via DI):
- `@http_kernel` — existing forum service (reused as-is)
- `@service_container` — the DI container itself

**DI service definition** (in `services_api.yml`):

```yaml
api.application:
    class: phpbb\core\Application
    arguments:
        - '@http_kernel'
        - '@service_container'
```

---

### 4.2 `phpbb\api\Application`

**No separate PHP class is required.** The Forum REST API reuses `phpbb\core\Application` with
a dedicated DI service (`api.application`) that references the same `@http_kernel`. The distinction
from the existing `web/app.php` flow lies in:

1. A new JSON exception subscriber replacing (outprioritizing) the Twig-dependent forum subscriber
2. A new API routing YAML with `/api/v1/` prefix
3. Session auth still used in Phase 1 (simplified); later phases replace with token auth

**Key namespace**: `phpbb\api\` → `src/phpbb/api/`
**Controllers**: `phpbb\api\v1\controller\*` → registered as DI services

**PSR-4 mapping** (add to `composer.json`):
```json
"phpbb\\api\\": "src/phpbb/api/"
```
_(Or use the existing `phpbb\\` psr-4 mapping if files live under `src/phpbb/forums/api/`)_

---

### 4.3 `phpbb\admin\api\Application`

**No separate PHP class.** Same `phpbb\core\Application` reused as DI service `admin_api.application`.

**Differences from Forum API**:
- Entry point (`web/adm/api.php`) sets `ADMIN_START=true`, `NEED_SID=true`, `IN_ADMIN=true` before `common.php`
- Entry point explicitly enforces `$auth->acl_get('a_')` and returns JSON 403 if check fails
- `$phpbb_root_path = '../../'` (two levels deep from `web/adm/`)
- Separate routing YAML with `/adm/api/v1/` prefix (matched by nginx; PHP router sees full URI)
- Admin-namespace controllers: `phpbb\admin\api\v1\controller\*`

**PSR-4 mapping** (add to `composer.json`):
```json
"phpbb\\admin\\": "src/phpbb/admin/"
```

---

### 4.4 `phpbb\install\api\Application`

**Isolated bootstrap** — completely separate from the forum container.

**Key architectural facts** (confirmed by research):
- `common.php:30` calls `checkInstallation()` → if `PHPBB_INSTALLED` is not defined → 302 redirect, `exit()`
- Installer container built with `->with_environment('installer')->without_extensions()` — NO DB services
- phpBB's own `web/install.php` already demonstrates the only viable pattern: include `startup.php`, never `common.php`

**PHP class**: Reuse `phpbb\core\Application` — same class, different container and different DI service ID.

**DI service** (in `services_install_api.yml`, imported into `installer/container/services.yml`):
```yaml
install_api.application:
    class: phpbb\core\Application
    arguments:
        - '@http_kernel'
        - '@service_container'
```

**Container**: `$phpbb_installer_container` (built by `startup.php`)

**Request handling**: Same `symfony_request` + `http_kernel` pattern, but the container is the
installer container which has its own `router`, `router.listener`, `dispatcher`, `http_kernel`.

---

## 5. Entry Points

### `web/api.php`

Maximum 20 lines. Mirrors `web/app.php` but skips template/language setup.

```php
<?php
/**
 * Forum REST API entry point.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../');
$phpbb_root_path = './';

include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php');

$user->session_begin();
$auth->acl($user->data);
$user->setup('');

/** @var \phpbb\core\Application $app */
$phpbb_container->get('api.application')->run();
```

**Notes**:
- `$user->setup('')` loads no language pack (API returns JSON, not translated strings for the UI)
- `session_begin()` + `acl()` are kept for Phase 1 (session-based auth); Phase 2 replaces with JWT listener
- 17 lines — within the 20-line limit

---

### `web/adm/api.php`

```php
<?php
/**
 * Admin REST API entry point.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

define('ADMIN_START', true);
define('NEED_SID', true);
define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../../');
$phpbb_root_path = '../../';

include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php');

define('IN_ADMIN', true);

$user->session_begin();
$auth->acl($user->data);
$user->setup('');

if (!$auth->acl_get('a_'))
{
	header('Content-Type: application/json; charset=UTF-8', true, 403);
	echo json_encode(['error' => 'Forbidden', 'status' => 403]);
	exit;
}

/** @var \phpbb\core\Application $app */
$phpbb_container->get('admin_api.application')->run();
```

**Notes**:
- `ADMIN_START` and `NEED_SID` must be defined BEFORE `common.php` (consumed by bootstrap internals)
- `IN_ADMIN` is defined AFTER `common.php` per the existing `adm/index.php` pattern
- Admin auth gate returns JSON 403 instead of `login_box()` / `trigger_error()`
- `$phpbb_root_path = '../../'` — two directory levels from `web/adm/` to root

---

### `web/install/api.php`

```php
<?php
/**
 * Installer REST API entry point.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

define('IN_INSTALL', true);
define('PHPBB_ROOT_PATH', __DIR__ . '/../../');

require PHPBB_ROOT_PATH . 'src/phpbb/install/startup.php';

// $phpbb_installer_container is available after startup.php
/** @var \phpbb\core\Application $app */
$phpbb_installer_container->get('install_api.application')->run();
```

**Notes**:
- `IN_INSTALL=true` is required — `startup.php` has an `exit` guard that checks for this constant
- `PHPBB_ROOT_PATH` matches the installer's convention (not `PHPBB_FILESYSTEM_ROOT`)
- `common.php` is NEVER included — would trigger `checkInstallation()` redirect
- File lives at `web/install/api.php` (directory `web/install/` must be created)
- This entry point works both before and during phpBB installation

---

## 6. Nginx Configuration

Add these three `location` blocks to `docker/nginx/default.conf`, placed **before** the
`location ~ ^(.+\.php)(/.*)? { ... }` block.

```nginx
# ─── Forum REST API ──────────────────────────────────────────────────────────
location ^~ /api/ {
    fastcgi_pass  app:9000;
    fastcgi_param SCRIPT_FILENAME $document_root/api.php;
    fastcgi_param PHPBB_ROOT_PATH /var/www/phpbb/;
    include fastcgi_params;

    add_header 'Access-Control-Allow-Origin'  '*'                                          always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, PATCH, DELETE, OPTIONS'    always;
    add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With' always;
    if ($request_method = OPTIONS) { return 204; }
}

# ─── Admin REST API ───────────────────────────────────────────────────────────
location ^~ /adm/api/ {
    fastcgi_pass  app:9000;
    fastcgi_param SCRIPT_FILENAME $document_root/adm/api.php;
    fastcgi_param PHPBB_ROOT_PATH /var/www/phpbb/;
    include fastcgi_params;

    add_header 'Access-Control-Allow-Origin'  '*'                                          always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, PATCH, DELETE, OPTIONS'    always;
    add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With' always;
    if ($request_method = OPTIONS) { return 204; }
}

# ─── Installer REST API ───────────────────────────────────────────────────────
location ^~ /install/api/ {
    fastcgi_pass  app:9000;
    fastcgi_param SCRIPT_FILENAME $document_root/install/api.php;
    fastcgi_param PHPBB_ROOT_PATH /var/www/phpbb/;
    include fastcgi_params;

    add_header 'Access-Control-Allow-Origin'  '*'                                          always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, PATCH, DELETE, OPTIONS'    always;
    add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With' always;
    if ($request_method = OPTIONS) { return 204; }
}
```

**Why `^~` (prefix, stop-regex)?**
- `^~` has higher priority than the `~` PHP regex location block
- Requests to `/api/`, `/adm/api/`, `/install/api/` are intercepted BEFORE the generic PHP handler
- `SCRIPT_FILENAME` is hardcoded to the specific API entry point file (not derived from URI, because `/api/users` is not a file)
- `REQUEST_URI` passes through unchanged via `fastcgi_params` include — the PHP router sees the full `/api/v1/users` URI

**No existing routes are affected.** The `/api/*`, `/adm/api/*`, `/install/api/*` paths currently
fall through to `app.php` and return 404 (no routes registered at those paths). After this change
they route to dedicated entry points.

---

## 7. DI Services Required

### 7.1 New Services — Forum Container

**File**: `src/phpbb/common/config/default/container/services_api.yml` (new file, imported into `services.yml`)

```yaml
services:
    # JSON error responses for the forum REST API
    api.exception_listener:
        class: phpbb\api\event\json_exception_subscriber
        arguments:
            - '%core.debug%'
        tags:
            - { name: kernel.event_subscriber }

    # phpbb\core\Application instance for the forum REST API
    api.application:
        class: phpbb\core\Application
        arguments:
            - '@http_kernel'
            - '@service_container'

    # phpbb\core\Application instance for the admin REST API
    admin_api.application:
        class: phpbb\core\Application
        arguments:
            - '@http_kernel'
            - '@service_container'
```

**Import** into `src/phpbb/common/config/default/container/services.yml`:
```yaml
imports:
    # ... existing imports ...
    - { resource: services_api.yml }
```

### 7.2 New Services — Installer Container

**File**: `src/phpbb/common/config/installer/container/services_install_api.yml` (new file)

```yaml
services:
    # JSON error responses for the installer REST API
    install_api.exception_listener:
        class: phpbb\api\event\json_exception_subscriber
        arguments:
            - '%core.debug%'
        tags:
            - { name: kernel.event_subscriber }

    # phpbb\core\Application instance for the installer REST API
    install_api.application:
        class: phpbb\core\Application
        arguments:
            - '@http_kernel'
            - '@service_container'
```

**Import** into `src/phpbb/common/config/installer/container/services.yml`:
```yaml
imports:
    # ... existing imports ...
    - { resource: services_install_api.yml }
```

### 7.3 Existing Services Reused (No Changes Needed)

| Service ID | Class | Reused by |
|-----------|-------|-----------|
| `http_kernel` | `Symfony\Component\HttpKernel\HttpKernel` | All 3 APIs |
| `symfony_request` | `phpbb\symfony_request` | All 3 APIs |
| `request_stack` | `Symfony\Component\HttpFoundation\RequestStack` | All 3 APIs |
| `router` | `phpbb\routing\router` | Forum + Admin API |
| `router.listener` | `Symfony\Component\HttpKernel\EventListener\RouterListener` | All 3 APIs |
| `dispatcher` | `phpbb\event\dispatcher` | All 3 APIs |
| `symfony_response_listener` | `Symfony\Component\HttpKernel\EventListener\ResponseListener` | All 3 APIs |
| `kernel_terminate_subscriber` | `phpbb\event\kernel_terminate_subscriber` | Forum + Admin API |
| `controller.resolver` | `phpbb\controller\resolver` | Forum + Admin API |
| `dbal.conn` | `phpbb\db\driver\factory` | Forum + Admin API |
| `config` | `phpbb\config\db` | Forum + Admin API |
| `cache` / `cache.driver` | varies | Forum + Admin API |

### 7.4 New Service: `phpbb\api\event\json_exception_subscriber`

**File**: `src/phpbb/api/event/json_exception_subscriber.php`

```php
<?php
/**
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbb\api\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;

class json_exception_subscriber implements EventSubscriberInterface
{
	/** @var bool */
	private $debug;

	public function __construct(bool $debug = false)
	{
		$this->debug = $debug;
	}

	public function on_kernel_exception(GetResponseForExceptionEvent $event): void
	{
		$exception  = $event->getException();
		$status     = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
		$data       = ['error' => $exception->getMessage(), 'status' => $status];

		if ($this->debug)
		{
			$data['trace'] = $exception->getTraceAsString();
		}

		$response = new JsonResponse($data, $status);

		if ($exception instanceof HttpExceptionInterface)
		{
			$response->headers->add($exception->getHeaders());
		}

		$event->setResponse($response);
	}

	public static function getSubscribedEvents(): array
	{
		return [
			KernelEvents::EXCEPTION => ['on_kernel_exception', 10],
		];
	}
}
```

**Priority 10** ensures it fires before the Twig-based `kernel_exception_subscriber` (priority 0),
which prevents HTML error pages from reaching JSON API consumers.

---

## 8. Routing Design

### 8.1 Forum API Routes

**New file**: `src/phpbb/common/config/default/routing/api.yml`

```yaml
phpbb_api_v1_health:
    path:     /api/v1/health
    methods:  [GET]
    defaults: { _controller: phpbb.api.v1.controller.health:index }

phpbb_api_v1_forums_list:
    path:     /api/v1/forums
    methods:  [GET]
    defaults: { _controller: phpbb.api.v1.controller.forums:index }

phpbb_api_v1_topics_list:
    path:     /api/v1/topics
    methods:  [GET]
    defaults: { _controller: phpbb.api.v1.controller.topics:index }

phpbb_api_v1_topic_show:
    path:     /api/v1/topics/{id}
    methods:  [GET]
    defaults: { _controller: phpbb.api.v1.controller.topics:show }
    requirements: { id: \d+ }

phpbb_api_v1_user_me:
    path:     /api/v1/users/me
    methods:  [GET]
    defaults: { _controller: phpbb.api.v1.controller.users:me }
```

**Register** in `src/phpbb/common/config/default/routing/routing.yml`:

```yaml
phpbb_api_routing:
    resource: api.yml
    prefix:   /
```

### 8.2 Admin API Routes

**New file**: `src/phpbb/common/config/default/routing/admin_api.yml`

```yaml
phpbb_admin_api_v1_health:
    path:     /adm/api/v1/health
    methods:  [GET]
    defaults: { _controller: phpbb.admin.api.v1.controller.health:index }

phpbb_admin_api_v1_users_list:
    path:     /adm/api/v1/users
    methods:  [GET]
    defaults: { _controller: phpbb.admin.api.v1.controller.users:index }

phpbb_admin_api_v1_user_ban:
    path:     /adm/api/v1/users/{id}/ban
    methods:  [POST]
    defaults: { _controller: phpbb.admin.api.v1.controller.users:ban }
    requirements: { id: \d+ }
```

**Register** in `src/phpbb/common/config/default/routing/routing.yml`:

```yaml
phpbb_admin_api_routing:
    resource: admin_api.yml
    prefix:   /
```

### 8.3 Installer API Routes

**New file**: `src/phpbb/common/config/installer/routing/install_api.yml`

```yaml
phpbb_install_api_v1_status:
    path:     /install/api/v1/status
    methods:  [GET]
    defaults: { _controller: phpbb.install.api.v1.controller.install:status }

phpbb_install_api_v1_requirements:
    path:     /install/api/v1/requirements
    methods:  [GET]
    defaults: { _controller: phpbb.install.api.v1.controller.install:requirements }

phpbb_install_api_v1_install:
    path:     /install/api/v1/install
    methods:  [POST]
    defaults: { _controller: phpbb.install.api.v1.controller.install:install }
```

**Register** in `src/phpbb/common/config/installer/routing/environment.yml` (add alongside existing resource):

```yaml
core.install_api:
    resource: install_api.yml
```

### 8.4 Controller Registration Pattern

All controllers are DI services with `service_id:method` format in `_controller`.

**Forum API controller example** (in `services_api.yml`):

```yaml
phpbb.api.v1.controller.health:
    class: phpbb\api\v1\controller\health
    # No arguments needed for health endpoint mock
```

**Controller class example** (`src/phpbb/api/v1/controller/health.php`):

```php
<?php
namespace phpbb\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

class health
{
	public function index(): JsonResponse
	{
		return new JsonResponse(['status' => 'ok', 'version' => '3.3.x']);
	}
}
```

---

## 9. Mock Endpoints (Phase 1)

Implement these as mock controllers that return hardcoded JSON (no real DB queries). Phase 1
validates the plumbing end-to-end before connecting to real data.

### `phpbb\api` — Forum REST API

| Method | Path | Mock Response | Notes |
|--------|------|--------------|-------|
| GET | `/api/v1/health` | `{"status":"ok","version":"3.3.x"}` | No auth required |
| GET | `/api/v1/forums` | `{"data":[],"total":0}` | Paginated stub |
| GET | `/api/v1/topics` | `{"data":[],"total":0}` | Paginated stub |
| GET | `/api/v1/topics/{id}` | `{"error":"Not Found","status":404}` | Tests 404 handling |
| GET | `/api/v1/users/me` | `{"id":1,"username":"Admin"}` | Session auth test |

**Priority order**: heath → users/me → forums → topics → topics/{id}

### `phpbb\admin\api` — Admin REST API

| Method | Path | Mock Response | Notes |
|--------|------|--------------|-------|
| GET | `/adm/api/v1/health` | `{"status":"ok","admin":true}` | Validates admin gate |
| GET | `/adm/api/v1/users` | `{"data":[],"total":0}` | Admin auth required |
| POST | `/adm/api/v1/users/{id}/ban` | `{"success":true}` | Tests POST + admin gate |

### `phpbb\install\api` — Installer REST API

| Method | Path | Mock Response | Notes |
|--------|------|--------------|-------|
| GET | `/install/api/v1/status` | `{"installed":false,"step":null}` | No auth required |
| GET | `/install/api/v1/requirements` | `{"php":{"ok":true},"db":{"ok":false}}` | PHP env check stub |
| POST | `/install/api/v1/install` | `{"started":true}` | Validates installer bootstrap |

---

## 10. Implementation Roadmap

Ordered list of files to create/modify. Each step is independently testable.

### Step 1 — Nginx (infrastructure gate)

```
MODIFY  docker/nginx/default.conf
        + location ^~ /api/
        + location ^~ /adm/api/
        + location ^~ /install/api/
```

**Test**: `curl -I http://localhost/api/v1/health` → should reach PHP (500 or 404 acceptable at this stage)

---

### Step 2 — Core Application Class

```
CREATE  src/phpbb/core/Application.php           (phpbb\core\Application)
```

**Test**: Class loads via autoloader without errors

---

### Step 3 — JSON Exception Subscriber

```
CREATE  src/phpbb/api/event/json_exception_subscriber.php   (phpbb\api\event\json_exception_subscriber)
```

---

### Step 4 — Forum API DI Configuration

```
CREATE  src/phpbb/common/config/default/container/services_api.yml
MODIFY  src/phpbb/common/config/default/container/services.yml   (add import)
```

---

### Step 5 — Forum API Routing

```
CREATE  src/phpbb/common/config/default/routing/api.yml
MODIFY  src/phpbb/common/config/default/routing/routing.yml       (add resource)
```

---

### Step 6 — Forum API Entry Point

```
CREATE  web/api.php
```

**Clear cache**: `rm -rf cache/production/` (DI container and route cache must be rebuilt)

---

### Step 7 — Forum API Health Controller

```
CREATE  src/phpbb/api/v1/controller/health.php   (phpbb\api\v1\controller\health)
ADD     service phpbb.api.v1.controller.health  to services_api.yml
ADD     route phpbb_api_v1_health               to api.yml
```

**Test**: `curl http://localhost/api/v1/health` → `{"status":"ok","version":"3.3.x"}`

---

### Step 8 — Forum API users/me Controller

```
CREATE  src/phpbb/api/v1/controller/users.php
ADD     service + route
```

**Test**: `curl -b 'phpbb3_xxx_sid=...' http://localhost/api/v1/users/me`

---

### Step 9 — Admin API DI + Routing

```
CREATE  src/phpbb/common/config/default/routing/admin_api.yml
MODIFY  src/phpbb/common/config/default/routing/routing.yml   (add resource)
ADD     admin_api.application service to services_api.yml
```

---

### Step 10 — Admin API Entry Point + Health Controller

```
CREATE  web/adm/api.php
CREATE  src/phpbb/admin/api/v1/controller/health.php
ADD     service phpbb.admin.api.v1.controller.health to services_api.yml
```

**Test**: `curl http://localhost/adm/api/v1/health` → 403 (no valid session), or 200 with valid admin session

---

### Step 11 — Installer API DI Configuration

```
CREATE  src/phpbb/common/config/installer/container/services_install_api.yml
MODIFY  src/phpbb/common/config/installer/container/services.yml   (add import)
```

---

### Step 12 — Installer API Routing

```
CREATE  src/phpbb/common/config/installer/routing/install_api.yml
MODIFY  src/phpbb/common/config/installer/routing/environment.yml   (add resource)
```

---

### Step 13 — Installer API Entry Point + Status Controller

```
CREATE  web/install/           (directory)
CREATE  web/install/api.php
CREATE  src/phpbb/install/api/v1/controller/install.php
ADD     service phpbb.install.api.v1.controller.install to services_install_api.yml
```

**Test** (before phpBB install): `curl http://localhost/install/api/v1/status` → `{"installed":false,...}`

---

### Step 14 — Remaining Mock Controllers

Complete the remaining Phase 1 mock endpoints (forums, topics, admin users, install requirements).

---

## 11. Risks and Open Questions

### Risk 1 — Exception Subscriber Priority Conflict (HIGH priority to resolve)

**Issue**: The existing `kernel_exception_subscriber` in the forum container catches ALL exceptions
and may return HTML before the new `api.exception_listener` runs.

**Detail**: Both subscribers listen on `kernel.exception`. Priority 10 (new) > 0 (existing).
Symfony event dispatcher stops propagation when `$event->setResponse()` is called AND
`$event->isPropagationStopped()` returns true. The new subscriber calls `$event->setResponse()`
but does NOT explicitly call `$event->stopPropagation()`. The forums subscriber will also fire
unless explicitly stopped.

**Resolution**: Call `$event->stopPropagation()` in `json_exception_subscriber::on_kernel_exception()`
after setting the response.

---

### Risk 2 — Session overhead on stateless API calls (MEDIUM)

**Issue**: `$user->session_begin()` performs PHP session start + DB query on every request.
For high-frequency API calls this is significant overhead.

**Resolution for Phase 1**: Accept the overhead; use session auth.
**Resolution for Phase 2**: Create a `phpbb\api\event\token_auth_listener` that runs on
`kernel.request` at priority 16, validates a JWT/API token, populates `$auth` without
touching sessions, and skips `session_begin()`. This requires restructuring `web/api.php`.

---

### Risk 3 — Admin API double auth confusion (LOW)

**Issue**: `web/adm/api.php` does an explicit auth check before the kernel runs. The kernel
may ALSO have an auth listener registered. This can lead to checking auth twice or inconsistency.

**Resolution**: Keep the explicit gate in `web/adm/api.php` for now. In Phase 2, move auth
entirely into an event listener and remove the gate from the entry point.

---

### Risk 4 — DI container cache invalidation (LOW — operational)

**Issue**: After adding new YAML files and routes, the DI container cache
(`cache/production/*.php`) and route cache files will be stale.

**Resolution**: Document and automate `rm -rf cache/production/` as part of the deploy process.
Consider adding a Makefile target.

---

### Risk 5 — Installer routing integration (MEDIUM)

**Issue**: The installer's `resources_locator` (which discovers route YAML files) and how it
picks up a new `install_api.yml` file is less documented than the forum equivalent.

**Detail**: The installer uses `routing.resources_locator.default` service configured in its own
YAML. Whether adding a resource to `environment.yml` is sufficient is confirmed by the pattern
analysis but not yet tested.

**Resolution**: Test by adding the health endpoint first. If routing fails, inspect the installer's
`routing.chained_resources_locator` service to understand how it chains locators.

---

### Risk 6 — `argument_resolver` not wired in `services_http.yml` (LOW)

**Issue**: Symfony 3.4 `HttpKernel` shows a deprecation when `ArgumentResolver` is not explicitly
provided. The current `services_http.yml` does not include it.

**Resolution**: Add to `services_http.yml`:
```yaml
argument_resolver:
    class: Symfony\Component\HttpKernel\Controller\ArgumentResolver

http_kernel:
    class: Symfony\Component\HttpKernel\HttpKernel
    arguments:
        - '@dispatcher'
        - '@controller.resolver'
        - '@request_stack'
        - '@argument_resolver'      # ← add this
```
This is a minor fix with no functional impact.

---

### Risk 7 — Install directory path conflict (LOW)

**Issue**: `web/install/api.php` lives in `web/install/`. The `location ^~ /install/api/` in nginx
is a path prefix — ensure there is no existing `web/install/` directory or nginx static files
that would match first.

**Research finding**: The directory `web/` was inspected; there is no `web/install/` directory.
The nginx block will need to be added alongside the `^~ /install/api/` rule to prevent the
PHP regex location from absorbing `/install/api.php` instead of the entry point.

---

## 12. Appendices

### Appendix A — Complete Source Index

| Finding File | Key Facts Used |
|-------------|---------------|
| `codebase-entrypoints-bootstrap.md` | Bootstrap sequence, `$phpbb_root_path` values, `ADMIN_START`/`NEED_SID`, session/auth ordering, what to skip, `json_response` class |
| `codebase-di-kernel.md` | Reusable services list, `controller.resolver` template optionality, `kernel_exception_subscriber` deps, `container_builder` API |
| `codebase-installer.md` | `checkInstallation()` mechanics, installer container isolation, `IN_INSTALL` guard, `startup.php` pattern, Option A recommendation |
| `external-symfony-kernel.md` | `HttpKernel` constructor deps, event lifecycle, `RouterListener` requirement, composition design, `json_exception_subscriber` code pattern |
| `codebase-nginx.md` | `^~` priority mechanics, proposed location blocks, `SCRIPT_FILENAME` hardcoding rationale, no conflict with existing routes |

### Appendix B — PSR-4 Namespace Assignments

| Namespace | Path | Status |
|-----------|------|--------|
| `phpbb\core\` | `src/phpbb/core/` | NEW — must add to composer.json |
| `phpbb\api\` | `src/phpbb/api/` | NEW — must add to composer.json |
| `phpbb\admin\api\` | `src/phpbb/admin/api/` | Covered by existing `phpbb\admin\` → `src/phpbb/admin/` |
| `phpbb\install\api\` | `src/phpbb/install/api/` | Covered by existing composer PSR-4 (installer namespace) |

**Add to `composer.json` `autoload.psr-4`**:
```json
"phpbb\\core\\": "src/phpbb/core/",
"phpbb\\api\\": "src/phpbb/api/"
```
Then run `composer dump-autoload`.

### Appendix C — Key phpBB Constants Reference

| Constant | Where Set | Value | Purpose |
|----------|----------|-------|---------|
| `PHPBB_FILESYSTEM_ROOT` | Entry point | `__DIR__ . '/<n>/'` | Absolute repo root (with trailing `/`) |
| `PHPBB_ROOT_PATH` | Installer entry point | `__DIR__ . '/<n>/'` | Installer-specific absolute root |
| `ADMIN_START` | `web/adm/*.php` | `true` | Signals admin context to bootstrap |
| `NEED_SID` | `web/adm/*.php` | `true` | Forces SID validation in session |
| `IN_ADMIN` | Set AFTER common.php | `true` | Signals admin context to hooks/extensions |
| `IN_INSTALL` | `web/install/*.php` | `true` | Guard for installer-only code |
| `PHPBB_INSTALLED` | `config.php` (generated) | `true` | Bypasses installer redirect in `common.php` |

### Appendix D — Overall Confidence Assessment

| Area | Confidence | Basis |
|------|-----------|-------|
| Bootstrap sequence (forum + admin API) | HIGH (95%) | Direct source reading of all relevant files |
| Installer API bootstrap | HIGH (90%) | Installer source fully analyzed; option analysis thorough |
| `phpbb\core\Application` design | HIGH (95%) | Symfony vendor source + existing phpBB patterns both confirmed |
| Nginx configuration | HIGH (95%) | nginx config file directly analyzed; priority mechanics confirmed |
| Routing integration | MEDIUM (80%) | Pattern is clear; installer routing locator not verified by testing |
| Session/auth for API | MEDIUM (75%) | `session_begin()` call confirmed; JWT path is future work not yet designed |
| DI container cache invalidation | HIGH (90%) | Cache path confirmed; invalidation requirement is standard phpBB practice |
