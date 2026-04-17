# Specification: phpBB REST API — Phase 1

**Status**: Ready for implementation  
**Risk**: Medium  
**Date**: 2026-04-16

---

## Goal

Layer three independent REST API entry points (`/api/*`, `/adm/api/*`, `/install/api/*`) on top
of phpBB Vibed's existing Symfony HttpKernel infrastructure, returning hardcoded mock data in
Phase 1 to validate the architecture end-to-end without touching any existing behavior.

---

## User Stories

- As a REST client, I can call `GET /api/v1/health` and receive `{"status":"ok"}` immediately
  without authentication, to verify the Forum API is reachable.
- As an admin tool, I can call `GET /adm/api/v1/health` and receive `{"status":"ok"}` immediately,
  to verify the Admin API is reachable.
- As an install client, I can call `GET /install/api/v1/health` and receive `{"status":"ok"}`,
  to verify the Installer API is reachable without a running DB.
- As a REST client, I can call any other API endpoint and receive `{"status":501}` with a clear
  message, knowing authentication is not yet implemented.
- As an existing forum user, my `/viewtopic.php`, `/app.php`, `/feed`, and all current phpBB
  routes continue to work without any disruption.

---

## Core Requirements

1. `phpbb\core\Application` — shared ~40-line wrapper class; delegates to `HttpKernel`; exposes `run()`.
2. `web/api.php` entry point — ≤15 lines; no `session_begin()`, no `acl()`; includes `common.php`, runs `api.application`.
3. `web/adm/api.php` entry point — minimal; defines `ADMIN_START`, `NEED_SID`, `IN_ADMIN`; no session/ACL in entry script; runs `admin_api.application`.
4. `web/install/api.php` entry point — defines `IN_INSTALL`; requires `startup.php` (never `common.php`); runs `install_api.application`.
5. `phpbb\api\event\json_exception_subscriber` — priority 10 on `kernel.exception`; guards with path prefix check (`/api/`, `/adm/api/`, `/install/api/`) before acting; returns JSON; calls `stopPropagation()`.
6. `phpbb\api\event\auth_subscriber` — priority 0 on `kernel.request`; guards with path prefix check first; then returns `501` for all API requests except paths ending in `/health`.
7. Forum API mock controllers (no constructor dependencies): `health`, `forums`, `topics`, `users`.
8. Admin API mock controllers: `health`, `users`.
9. Installer API mock controllers: `health`, `status`.
10. `services_api.yml` — declares all forum+admin services; imported into main `services.yml`.
11. `services_install_api.yml` — declares installer services; imported into installer `services.yml`.
12. `api.yml` — 5 routes under `/api/v1/`; imported into `routing.yml`.
13. `admin_api.yml` — 2 routes under `/adm/api/v1/`; imported into `routing.yml`.
14. `install_api.yml` — 2 routes under `/install/api/v1/`; imported into installer `environment.yml`.
15. Nginx — 3 `location ^~` blocks added before the PHP regex block; CORS headers; OPTIONS 204.
16. `composer.json` — add `"phpbb\\api\\": "src/phpbb/api/"` to `autoload.psr-4`.
17. Route cache cleared after deployment (`rm -rf cache/production/`).

---

## Reusable Components

### Existing Code to Leverage

| Component | File | How Reused |
|-----------|------|------------|
| `web/app.php` | `web/app.php` | Pattern for `web/api.php` (define → include common.php → get service → run) |
| `web/adm/index.php` | `web/adm/index.php` | Pattern for `web/adm/api.php` (constants before common.php; `$phpbb_root_path = '../../'`) |
| `src/phpbb/install/app.php` | `src/phpbb/install/app.php` | Pattern for `web/install/api.php` (startup.php, `$phpbb_installer_container`) |
| `src/phpbb/install/startup.php` | `src/phpbb/install/startup.php` | Bootstraps installer DI container; required verbatim |
| `@http_kernel` service | `services_http.yml` | Reused as-is in all 3 APIs; no new HttpKernel needed |
| `@symfony_request` service | `services_http.yml` | Fetched from container in `Application::run()` |
| `@router`, `@router.listener` | `services_routing.yml` | Handles URL→controller mapping; no new routing infrastructure |
| `@dispatcher` | `services_event.yml` | Shared event bus; used unchanged |
| `phpbb\event\kernel_exception_subscriber` | `forums/event/kernel_exception_subscriber.php` | Structural template for `json_exception_subscriber` (subscriber interface, getSubscribedEvents pattern) |
| `routing.yml` import pattern | `config/default/routing/routing.yml` | Pattern for `resource: api.yml, prefix: /` |
| `environment.yml` pattern | `config/installer/routing/environment.yml` | Pattern for adding `install_api.yml` resource |

### New Components Required

| Component | Justification |
|-----------|---------------|
| `phpbb\core\Application` | No existing phpBB class wraps HttpKernel with a `run()` method suitable for all 3 APIs |
| `json_exception_subscriber` | Existing `kernel_exception_subscriber` depends on `@template` and `@language` (Twig); API must return JSON without those services |
| `auth_subscriber` | No existing stub/placeholder auth subscriber exists; needed per Phase 1 spec |
| All 9 mock controllers | New capability; no existing code to reuse |
| 3 new service YAML files | New services; no existing YAML covers API namespaces |
| 3 new routing YAML files | New URL prefixes with no existing routes |
| 3 new entry point PHP files | New request entry points |
| 1 new installer routing YAML | New routes in installer namespace |

---

## Technical Approach

### Container Strategy

Forum API and Admin API **share** the single forum DI container (both `web/api.php` and
`web/adm/api.php` include `common.php`). All forum+admin services live in `services_api.yml`,
which is imported into the main `services.yml`.

Installer API uses an **isolated** installer container (built by `startup.php` via
`with_environment('installer')->without_extensions()`). Installer services live in
`services_install_api.yml`, imported into the installer's `services.yml`.

### Request Routing

Nginx uses `location ^~ /api/`, `location ^~ /adm/api/`, `location ^~ /install/api/` blocks
(placed **before** the PHP regex block) to hardcode `SCRIPT_FILENAME` per entry point.
`REQUEST_URI` passes through unchanged so the Symfony Router inside PHP sees the full path
(`/api/v1/health`, etc.).

### Exception Handling

`json_exception_subscriber` at priority 10 fires before the Twig-dependent
`kernel_exception_subscriber` at priority 0. It calls `$event->stopPropagation()` after
`$event->setResponse()` to prevent the HTML subscriber from overriding the JSON response.
In the installer container, the same class is registered as `install_api.exception_listener`
at priority 10.

### Auth Gate (Phase 1 Stub)

`auth_subscriber` listens on `kernel.request` at default priority 0. It inspects
`$request->getPathInfo()` for a `/health` suffix — if matched, it does nothing (returns 200).
For all other paths, it sets a 501 `JsonResponse` on the event and logs "not yet implemented".
No DB table is created in Phase 1.

### Route Cache

After any route YAML change, delete `cache/production/` and let phpBB regenerate on next request.
**Document this in the deploy script** — route compilation is lazy (first request post-deploy).

---

## Implementation Guidance

### Testing Approach

Each implementation step group should include 2–8 focused tests. Suggested groups:

| Group | Tests |
|-------|-------|
| Nginx routing | 3: each `location ^~` block routes to correct entry point (can be curl integration tests) |
| `phpbb\core\Application` | 3: `run()` calls handle → send → terminate in order; constructor stores kernel; constructor stores container |
| `json_exception_subscriber` | 4: 404 returns JSON 404; 500 returns JSON 500; debug mode adds trace; stopPropagation is called |
| `auth_subscriber` | 4: `/api/v1/health` passes through; `/adm/api/v1/health` passes through; `/install/api/v1/health` passes through; `/api/v1/forums` returns 501 |
| Mock controllers | 2 per controller: response is 200; body matches expected JSON shape |
| Integration (acceptance) | 6: matches the 6 acceptance criteria exactly |

Run **only new tests** after each group, not the full suite (avoids noise from unrelated failures).

### Standards Compliance

- **PHP 7.2+**: No `declare(strict_types=1)`, no PHP 8 syntax (match, named args, nullsafe operator).
- **phpBB indentation**: Tabs throughout — no spaces for indentation.
- **No closing PHP tag** at end of any PHP file.
- **PSR-4**: New namespace `phpbb\api\` must be registered in `composer.json` before any class in that namespace is loaded.
- **SQL safety**: Phase 1 has zero DB queries — no SQL concerns. Phase 2 must use `$db->sql_escape()` or parameterized queries exclusively.
- **CSRF**: No state-changing action in Phase 1 — no `check_form_key()` needed. Phase 2 POST endpoints must add CSRF validation.
- **File headers**: All new PHP files carry the standard phpBB copyright/license header.
- **Symfony 3.4 event classes**: Use `GetResponseForExceptionEvent` (not `ExceptionEvent`), `GetResponseEvent` (not `RequestEvent`).

---

## Out of Scope

- `phpbb_api_tokens` DB table (Phase 2)
- Real token authentication (Phase 2)
- Real DB queries in controllers (Phase 2)
- OpenAPI / Swagger schema generation
- Rate limiting
- API versioning infrastructure beyond URL prefix (`/v1/`)
- Admin permission gate (`acl_get('a_')`) in the entry point — replaced by auth_subscriber in Phase 2

---

## Success Criteria

1. `curl -s http://localhost:8181/api/v1/health` → HTTP 200, body `{"status":"ok","api":"phpBB Forum API","version":"1.0.0-dev"}`
2. `curl -s http://localhost:8181/adm/api/v1/health` → HTTP 200, body `{"status":"ok","api":"phpBB Admin API"}`
3. `curl -s http://localhost:8181/install/api/v1/health` → HTTP 200, body `{"status":"ok","api":"phpBB Install API"}`
4. `curl -s http://localhost:8181/api/v1/forums` → HTTP 501, body `{"error":"API token authentication not yet implemented","status":501}`
5. `curl -s http://localhost:8181/api/v1/topics/1` → HTTP 501
6. Existing routes work: `curl -s http://localhost:8181/` returns the phpBB forum index (not a 500 or blank page), and `/feed` returns the RSS feed.

---

---

# Complete File Specifications

> All code below is exact and implementable. The implementer follows each section in order without making any architectural decisions.

---

## FILES TO MODIFY (6)

---

### MOD-1: `composer.json` — Add PSR-4 autoload entry

**Change**: Add one line to `autoload.psr-4` object.

```diff
     "autoload": {
         "psr-4": {
             "phpbb\\core\\": "src/phpbb/core/",
             "phpbb\\": "src/phpbb/forums/",
             "phpbb\\admin\\": "src/phpbb/admin/",
             "phpbb\\common\\": "src/phpbb/common/",
+            "phpbb\\api\\": "src/phpbb/api/",
             "phpbb\\install\\": "src/phpbb/install/"
         },
```

**After edit**, run `composer dump-autoload` inside the Docker container or host.

---

### MOD-2: `src/phpbb/common/config/default/container/services.yml` — Import services_api.yml

**Change**: Add one line at the end of the `imports:` block (after `services_ucp.yml`).

```diff
     - { resource: services_ucp.yml }
     - { resource: services_user.yml }
 
     - { resource: tables.yml }
     - { resource: parameters.yml }
+
+    - { resource: services_api.yml }
```

---

### MOD-3: `src/phpbb/common/config/default/routing/routing.yml` — Import api.yml and admin_api.yml

**Change**: Append two resource imports at the end of the file.

Current file ends with (approximate):
```yaml
phpbb_ucp_routing:
    resource: ucp.yml
    prefix: /user
```

**Add after the last existing entry**:
```yaml
phpbb_api_routing:
    resource: api.yml
    prefix:   /

phpbb_admin_api_routing:
    resource: admin_api.yml
    prefix:   /
```

---

### MOD-4: `src/phpbb/common/config/installer/container/services.yml` — Import services_install_api.yml

**Change**: Add one line at the end of the `imports:` block.

```diff
 imports:
     - { resource: services_installer.yml }
     - { resource: ../../default/container/services_event.yml }
     - { resource: ../../default/container/services_filesystem.yml }
     - { resource: ../../default/container/services_http.yml }
     - { resource: ../../default/container/services_language.yml }
     - { resource: ../../default/container/services_php.yml }
     - { resource: ../../default/container/services_routing.yml }
     - { resource: ../../default/container/services_twig.yml }
+
+    - { resource: services_install_api.yml }
```

---

### MOD-5: `src/phpbb/common/config/installer/routing/environment.yml` — Import install_api.yml

**Change**: Add one entry to the file (currently only has `core.default`).

Current content:
```yaml
core.default:
    resource: installer.yml
```

**New content**:
```yaml
core.default:
    resource: installer.yml

core.install_api:
    resource: install_api.yml
```

---

### MOD-6: `docker/nginx/default.conf` — Add 3 API location blocks

**Change**: Insert the three `location ^~` blocks **immediately before** the existing
`location ~ ^(.+\.php)(/.*)? {` block.

Locate this line in the file:
```nginx
    # PHP files — also handles PATH_INFO (e.g. /install.php/support)
    location ~ ^(.+\.php)(/.*)? {
```

**Insert above it**:
```nginx
    # ─── Forum REST API ─────────────────────────────────────────────────────────
    location ^~ /api/ {
        fastcgi_pass  app:9000;
        fastcgi_param SCRIPT_FILENAME $document_root/api.php;
        fastcgi_param PHPBB_ROOT_PATH /var/www/phpbb/;
        include fastcgi_params;

        add_header 'Access-Control-Allow-Origin'  '*'                                              always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, PATCH, DELETE, OPTIONS'        always;
        add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With' always;
        if ($request_method = OPTIONS) { return 204; }
    }

    # ─── Admin REST API ──────────────────────────────────────────────────────────
    location ^~ /adm/api/ {
        fastcgi_pass  app:9000;
        fastcgi_param SCRIPT_FILENAME $document_root/adm/api.php;
        fastcgi_param PHPBB_ROOT_PATH /var/www/phpbb/;
        include fastcgi_params;

        add_header 'Access-Control-Allow-Origin'  '*'                                              always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, PATCH, DELETE, OPTIONS'        always;
        add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With' always;
        if ($request_method = OPTIONS) { return 204; }
    }

    # ─── Installer REST API ──────────────────────────────────────────────────────
    location ^~ /install/api/ {
        fastcgi_pass  app:9000;
        fastcgi_param SCRIPT_FILENAME $document_root/install/api.php;
        fastcgi_param PHPBB_ROOT_PATH /var/www/phpbb/;
        include fastcgi_params;

        add_header 'Access-Control-Allow-Origin'  '*'                                              always;
        add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, PATCH, DELETE, OPTIONS'        always;
        add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With' always;
        if ($request_method = OPTIONS) { return 204; }
    }

```

**Why this ordering works**: Nginx `^~` uses longest-prefix matching. `/adm/api/` cannot match
the `/api/` block (the URI starts with `/adm/`, not `/api/`). The `^~` modifier stops regex
evaluation for the matched location. The generic PHP regex block (`~ ^(.+\.php)`) never fires
for API URIs because they don't end in `.php`.

**After edit**: Reload nginx — `docker compose exec nginx nginx -s reload` (or restart the container).

---

---

## FILES TO CREATE (19)

---

### NEW-1: `src/phpbb/core/Application.php`

```php
<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\core;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * Shared HTTP application wrapper for all phpBB REST API entry points.
 *
 * Composes Symfony HttpKernel via delegation (not inheritance) so that
 * the same class can be registered as multiple DI services with different IDs.
 */
class Application implements HttpKernelInterface, TerminableInterface
{
	/** @var HttpKernel */
	private $kernel;

	/** @var ContainerInterface */
	private $container;

	/**
	 * @param HttpKernel         $kernel    The shared forum/installer HttpKernel service
	 * @param ContainerInterface $container The DI container (used to fetch symfony_request)
	 */
	public function __construct(HttpKernel $kernel, ContainerInterface $container)
	{
		$this->kernel    = $kernel;
		$this->container = $container;
	}

	/**
	 * {@inheritdoc}
	 */
	public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true)
	{
		return $this->kernel->handle($request, $type, $catch);
	}

	/**
	 * {@inheritdoc}
	 */
	public function terminate(Request $request, Response $response)
	{
		$this->kernel->terminate($request, $response);
	}

	/**
	 * Fetch the current request from the DI container, dispatch it through
	 * the kernel, send the response, then run terminate subscribers.
	 *
	 * @return void
	 */
	public function run()
	{
		/** @var Request $request */
		$request  = $this->container->get('symfony_request');
		$response = $this->handle($request);
		$response->send();
		$this->terminate($request, $response);
	}
}
```

---

### NEW-2: `src/phpbb/api/event/json_exception_subscriber.php`

```php
<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\api\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\GetResponseForExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Converts all kernel exceptions to JSON responses for the REST API.
 *
 * Registered at priority 10 so it fires before the HTML-rendering
 * kernel_exception_subscriber (priority 0). Calls stopPropagation()
 * to prevent the HTML subscriber from overriding the JSON response.
 */
class json_exception_subscriber implements EventSubscriberInterface
{
	/** @var bool */
	private $debug;

	/**
	 * @param bool $debug When true, includes the exception trace in the response body
	 */
	public function __construct($debug = false)
	{
		$this->debug = (bool) $debug;
	}

	/**
	 * Transform any kernel exception into a JSON response.
	 *
	 * Only intercepts requests to API paths; non-API routes fall through to
	 * the Twig-based kernel_exception_subscriber as normal.
	 *
	 * @param GetResponseForExceptionEvent $event
	 * @return void
	 */
	public function on_kernel_exception(GetResponseForExceptionEvent $event)
	{
		$path = $event->getRequest()->getPathInfo();

		// Only handle API paths — let HTML subscriber handle forum routes
		if (strpos($path, '/api/') !== 0 && strpos($path, '/adm/api/') !== 0 && strpos($path, '/install/api/') !== 0)
		{
			return;
		}

		$exception = $event->getException();

		$status = ($exception instanceof HttpExceptionInterface)
			? $exception->getStatusCode()
			: 500;

		$data = [
			'error'  => $exception->getMessage(),
			'status' => $status,
		];

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
		$event->stopPropagation();
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents()
	{
		return [
			KernelEvents::EXCEPTION => ['on_kernel_exception', 10],
		];
	}
}
```

---

### NEW-3: `src/phpbb/api/event/auth_subscriber.php`

```php
<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\api\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Phase 1 authentication stub.
 *
 * Blocks all API requests with HTTP 501 except health-check endpoints
 * (any path whose last segment is "health").
 *
 * Phase 2 will replace this stub with real token validation against
 * the phpbb_api_tokens table, with no changes required to controllers
 * or routing.
 */
class auth_subscriber implements EventSubscriberInterface
{
	/**
	 * Intercept kernel.request and block non-health endpoints.
	 *
	 * @param GetResponseEvent $event
	 * @return void
	 */
	public function on_kernel_request(GetResponseEvent $event)
	{
		if (!$event->isMasterRequest())
		{
			return;
		}

		$path = $event->getRequest()->getPathInfo();

		// Only intercept API paths — let standard phpBB routes pass through untouched
		if (strpos($path, '/api/') !== 0 && strpos($path, '/adm/api/') !== 0 && strpos($path, '/install/api/') !== 0)
		{
			return;
		}

		// Allow all health-check endpoints without authentication
		if (substr($path, -strlen('/health')) === '/health')
		{
			return;
		}

		$event->setResponse(new JsonResponse([
			'error'  => 'API token authentication not yet implemented',
			'status' => 501,
		], 501));
	}

	/**
	 * {@inheritdoc}
	 */
	public static function getSubscribedEvents()
	{
		return [
			KernelEvents::REQUEST => 'on_kernel_request',
		];
	}
}
```

---

### NEW-4: `src/phpbb/api/v1/controller/health.php`

```php
<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Forum API health-check controller (Phase 1 — no phpBB dependencies).
 */
class health
{
	/**
	 * GET /api/v1/health
	 *
	 * @return JsonResponse
	 */
	public function index()
	{
		return new JsonResponse([
			'status'  => 'ok',
			'api'     => 'phpBB Forum API',
			'version' => '1.0.0-dev',
		]);
	}
}
```

---

### NEW-5: `src/phpbb/api/v1/controller/forums.php`

```php
<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Forum list controller (Phase 1 — hardcoded mock data).
 */
class forums
{
	/**
	 * GET /api/v1/forums
	 *
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function index(Request $request)
	{
		return new JsonResponse([
			'forums' => [
				[
					'id'          => 1,
					'name'        => 'General Discussion',
					'description' => 'Talk about anything',
					'topics'      => 0,
					'posts'       => 0,
				],
				[
					'id'          => 2,
					'name'        => 'Announcements',
					'description' => 'Official announcements',
					'topics'      => 0,
					'posts'       => 0,
				],
			],
			'total' => 2,
		]);
	}
}
```

---

### NEW-6: `src/phpbb/api/v1/controller/topics.php`

```php
<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Topic list and detail controller (Phase 1 — hardcoded mock data).
 */
class topics
{
	/**
	 * GET /api/v1/topics
	 *
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function index(Request $request)
	{
		return new JsonResponse([
			'topics' => [
				[
					'id'       => 1,
					'title'    => 'Welcome to the API',
					'forum_id' => 1,
					'posts'    => 1,
				],
			],
			'total' => 1,
		]);
	}

	/**
	 * GET /api/v1/topics/{id}
	 *
	 * @param Request $request
	 * @param int     $id
	 * @return JsonResponse
	 */
	public function show(Request $request, $id)
	{
		return new JsonResponse([
			'topic' => [
				'id'       => (int) $id,
				'title'    => 'Mock Topic #' . $id,
				'forum_id' => 1,
				'posts'    => 0,
			],
		]);
	}
}
```

---

### NEW-7: `src/phpbb/api/v1/controller/users.php`

```php
<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * User controller (Phase 1 — hardcoded mock data).
 */
class users
{
	/**
	 * GET /api/v1/users/me
	 *
	 * @return JsonResponse
	 */
	public function me()
	{
		return new JsonResponse([
			'user' => [
				'id'       => 0,
				'username' => 'guest',
				'email'    => 'guest@example.com',
			],
		]);
	}
}
```

---

### NEW-8: `src/phpbb/admin/api/v1/controller/health.php`

```php
<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\admin\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Admin API health-check controller (Phase 1 — no phpBB dependencies).
 */
class health
{
	/**
	 * GET /adm/api/v1/health
	 *
	 * @return JsonResponse
	 */
	public function index()
	{
		return new JsonResponse([
			'status' => 'ok',
			'api'    => 'phpBB Admin API',
		]);
	}
}
```

---

### NEW-9: `src/phpbb/admin/api/v1/controller/users.php`

```php
<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\admin\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin user management controller (Phase 1 — hardcoded mock data).
 */
class users
{
	/**
	 * GET /adm/api/v1/users
	 *
	 * @param Request $request
	 * @return JsonResponse
	 */
	public function index(Request $request)
	{
		return new JsonResponse([
			'users' => [
				[
					'id'       => 1,
					'username' => 'Admin',
					'email'    => 'admin@example.com',
					'role'     => 'administrator',
				],
			],
			'total' => 1,
		]);
	}
}
```

---

### NEW-10: `src/phpbb/install/api/v1/controller/health.php`

```php
<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\install\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Installer API health-check controller (Phase 1 — no phpBB dependencies).
 * Runs in the isolated installer container; no DB services available.
 */
class health
{
	/**
	 * GET /install/api/v1/health
	 *
	 * @return JsonResponse
	 */
	public function index()
	{
		return new JsonResponse([
			'status' => 'ok',
			'api'    => 'phpBB Install API',
		]);
	}
}
```

---

### NEW-11: `src/phpbb/install/api/v1/controller/status.php`

```php
<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

namespace phpbb\install\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Installer status controller (Phase 1 — hardcoded mock data).
 */
class status
{
	/**
	 * GET /install/api/v1/status
	 *
	 * @return JsonResponse
	 */
	public function index()
	{
		return new JsonResponse([
			'installed' => false,
			'version'   => '3.3.x-dev',
		]);
	}
}
```

---

### NEW-12: `src/phpbb/common/config/default/container/services_api.yml`

```yaml
# Forum + Admin REST API services.
# Imported into services.yml — runs inside the shared forum DI container.
# Both web/api.php and web/adm/api.php share this container.

services:

    # ─── JSON Exception Subscriber (priority 10 > HTML subscriber at 0) ─────────
    api.exception_listener:
        class: phpbb\api\event\json_exception_subscriber
        arguments:
            - '%debug.exceptions%'
        tags:
            - { name: kernel.event_subscriber }

    # ─── Auth Stub Subscriber (Phase 1: always 501 except /health) ──────────────
    api.auth_subscriber:
        class: phpbb\api\event\auth_subscriber
        tags:
            - { name: kernel.event_subscriber }

    # ─── Application Instances ──────────────────────────────────────────────────
    api.application:
        class: phpbb\core\Application
        arguments:
            - '@http_kernel'
            - '@service_container'

    admin_api.application:
        class: phpbb\core\Application
        arguments:
            - '@http_kernel'
            - '@service_container'

    # ─── Forum API Controllers ───────────────────────────────────────────────────
    phpbb.api.v1.controller.health:
        class: phpbb\api\v1\controller\health

    phpbb.api.v1.controller.forums:
        class: phpbb\api\v1\controller\forums

    phpbb.api.v1.controller.topics:
        class: phpbb\api\v1\controller\topics

    phpbb.api.v1.controller.users:
        class: phpbb\api\v1\controller\users

    # ─── Admin API Controllers ───────────────────────────────────────────────────
    phpbb.admin.api.v1.controller.health:
        class: phpbb\admin\api\v1\controller\health

    phpbb.admin.api.v1.controller.users:
        class: phpbb\admin\api\v1\controller\users
```

---

### NEW-13: `src/phpbb/common/config/installer/container/services_install_api.yml`

```yaml
# Installer REST API services.
# Imported into installer/container/services.yml — runs inside the isolated
# installer DI container (no DB services, no session).

services:

    # ─── JSON Exception Subscriber ──────────────────────────────────────────────
    install_api.exception_listener:
        class: phpbb\api\event\json_exception_subscriber
        arguments:
            - '%debug.exceptions%'
        tags:
            - { name: kernel.event_subscriber }

    # ─── Auth Stub Subscriber ───────────────────────────────────────────────────
    install_api.auth_subscriber:
        class: phpbb\api\event\auth_subscriber
        tags:
            - { name: kernel.event_subscriber }

    # ─── Application Instance ───────────────────────────────────────────────────
    install_api.application:
        class: phpbb\core\Application
        arguments:
            - '@http_kernel'
            - '@service_container'

    # ─── Installer API Controllers ───────────────────────────────────────────────
    phpbb.install.api.v1.controller.health:
        class: phpbb\install\api\v1\controller\health

    phpbb.install.api.v1.controller.status:
        class: phpbb\install\api\v1\controller\status
```

**Note**: `phpbb\api\event\json_exception_subscriber` and `phpbb\api\event\auth_subscriber` are
in the `phpbb\api\` namespace (autoloaded from `src/phpbb/api/`) and are shared between the forum
container and installer container.

---

### NEW-14: `src/phpbb/common/config/default/routing/api.yml`

```yaml
# Forum REST API routes — Phase 1
# Imported into routing.yml with prefix /

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
    requirements:
        id: \d+

phpbb_api_v1_user_me:
    path:     /api/v1/users/me
    methods:  [GET]
    defaults: { _controller: phpbb.api.v1.controller.users:me }
```

---

### NEW-15: `src/phpbb/common/config/default/routing/admin_api.yml`

```yaml
# Admin REST API routes — Phase 1
# Imported into routing.yml with prefix /

phpbb_admin_api_v1_health:
    path:     /adm/api/v1/health
    methods:  [GET]
    defaults: { _controller: phpbb.admin.api.v1.controller.health:index }

phpbb_admin_api_v1_users_list:
    path:     /adm/api/v1/users
    methods:  [GET]
    defaults: { _controller: phpbb.admin.api.v1.controller.users:index }
```

---

### NEW-16: `src/phpbb/common/config/installer/routing/install_api.yml`

```yaml
# Installer REST API routes — Phase 1
# Imported into environment.yml

phpbb_install_api_v1_health:
    path:     /install/api/v1/health
    methods:  [GET]
    defaults: { _controller: phpbb.install.api.v1.controller.health:index }

phpbb_install_api_v1_status:
    path:     /install/api/v1/status
    methods:  [GET]
    defaults: { _controller: phpbb.install.api.v1.controller.status:index }
```

---

### NEW-17: `web/api.php`

```php
<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

/**
 * Forum REST API entry point.
 *
 * Minimal bootstrap — no session_begin(), no acl().
 * Authentication is handled by phpbb\api\event\auth_subscriber.
 */

define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../');
$phpbb_root_path = './';

include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php');

/** @var \phpbb\core\Application $app */
$phpbb_container->get('api.application')->run();
```

---

### NEW-18: `web/adm/api.php`

```php
<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

/**
 * Admin REST API entry point.
 *
 * Defines ADMIN_START and NEED_SID before common.php (required by bootstrap).
 * No session_begin(), no acl() — authentication handled by auth_subscriber.
 */

define('ADMIN_START', true);
define('NEED_SID', true);
define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../../');
$phpbb_root_path = '../../';

include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php');

define('IN_ADMIN', true);

/** @var \phpbb\core\Application $app */
$phpbb_container->get('admin_api.application')->run();
```

---

### NEW-19: `web/install/api.php`

```php
<?php
/**
 *
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

/**
 * Installer REST API entry point.
 *
 * Uses the isolated installer DI container — common.php is NEVER included
 * (it would trigger checkInstallation() and redirect if phpBB is not installed).
 */

define('IN_INSTALL', true);
define('PHPBB_ROOT_PATH', __DIR__ . '/../../');

// startup.php uses $phpbb_root_path as a variable (not the constant)
$phpbb_root_path = PHPBB_ROOT_PATH;

require PHPBB_ROOT_PATH . 'src/phpbb/install/startup.php';

// $phpbb_installer_container is defined by startup.php
/** @var \phpbb\core\Application $app */
$phpbb_installer_container->get('install_api.application')->run();
```

**Note**: `PHPBB_ROOT_PATH` (not `PHPBB_FILESYSTEM_ROOT`) is the constant startup.php expects.
`__DIR__` here is `/var/www/phpbb/web/install/`, so `../../` resolves to `/var/www/phpbb/`.

---

---

## Post-Implementation Checklist

After all files are created/modified, perform these steps in order:

1. **Composer dump-autoload** (register `phpbb\api\` namespace):
   ```
   docker compose exec app composer dump-autoload
   ```

2. **Clear route cache** (Symfony router must discover new YAML routes):
   ```
   docker compose exec app rm -rf /var/www/phpbb/cache/production/
   ```

3. **Reload nginx** (activate new `location ^~` blocks):
   ```
   docker compose exec nginx nginx -s reload
   ```

4. **Run acceptance tests** (6 curl commands from Success Criteria above).

---

## Architecture Consistency Notes

- `phpbb\admin\api\v1\controller\*` lives at `src/phpbb/admin/api/v1/controller/` which falls under
  the existing `phpbb\admin\` PSR-4 mapping (`"phpbb\\admin\\": "src/phpbb/admin/"`) — no new
  autoload entry needed for admin controllers.

- `phpbb\install\api\v1\controller\*` lives at `src/phpbb/install/api/v1/controller/` which falls
  under the existing `phpbb\install\` PSR-4 mapping — no new autoload entry needed.

- Only `phpbb\api\` requires the new autoload entry because it has no parent mapping
  (`phpbb\` maps to `src/phpbb/forums/`, not `src/phpbb/`).

- Both `api.application` and `admin_api.application` wrap the **same** `@http_kernel` with the
  **same** `@service_container`. The difference is only the DI service ID used in the entry point.
  The router inside the kernel sees both `/api/*` and `/adm/api/*` routes (all registered in the
  shared forum routing), and dispatches to the correct controller based solely on the URL.

- The `install_api.exception_listener` and `install_api.auth_subscriber` in the installer container
  use the **same PHP classes** as `api.exception_listener` and `api.auth_subscriber` in the forum
  container. The class files are loaded from `src/phpbb/api/event/` via the `phpbb\api\` autoload
  mapping, regardless of which container is active.
