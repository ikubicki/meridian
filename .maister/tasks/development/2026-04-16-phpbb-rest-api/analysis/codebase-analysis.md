# Codebase Analysis Report

**Date**: 2026-04-16
**Task**: Build phpBB Vibed REST API
**Description**: Build phpbb\core\Application REST API for phpBB Vibed — creating web/api.php, web/adm/api.php, web/install/api.php entry points plus phpbb\core\Application base class, phpbb\api\* controllers, phpbb\admin\api\* controllers, JSON exception subscriber, API token auth subscriber, DI services YAML, routing YAML files, and nginx config changes.
**Analyzer**: codebase-analyzer skill (3 Explore agents: File Discovery, Code Analysis, Pattern Mining)

---

## Summary

The phpBB Vibed project uses a Symfony HttpKernel–based dispatch loop with PSR-4 namespaced services wired via YAML DI containers. Three distinct bootstrap paths exist (forums, admin, installer), each sharing a common container-builder pattern that compiles to `cache/production/`. A REST API layer can be cleanly layered on top by introducing new entry points (`web/api.php`, `web/adm/api.php`, `web/install/api.php`), a `phpbb\core\Application` base class, new service/routing YAMLs, two event subscribers (auth + exception), and an nginx location block — without modifying any existing files.

---

## Files Identified

### Primary Files

**web/app.php** (40 lines)
- Main forum entry point; boots `common.php` → starts session → calls `http_kernel->handle()`
- Canonical reference for the new `web/api.php` entry point pattern

**src/phpbb/common/common.php**
- Boots the DI container via `container_builder`; sets `$user`, `$auth`, `$db`, `$config` globals
- Must be included by `web/api.php` (same as `app.php`)

**src/phpbb/install/app.php** (~50 lines)
- Installer bootstrap — uses `container_builder->with_environment('installer')->without_extensions()`
- Reference for `web/install/api.php` bootstrap variant

**web/adm/index.php**
- Admin bootstrap — defines `ADMIN_START`, `NEED_SID` before `common.php`
- Reference for `web/adm/api.php` bootstrap variant

**src/phpbb/common/config/default/container/services.yml**
- Master import file for all DI service definitions; new `services_api.yml` must be imported here

**src/phpbb/common/config/default/container/services_http.yml**
- Defines `http_kernel`, `symfony_request`, `request_stack`; consumed unchanged by API

**src/phpbb/common/config/default/container/services_event.yml**
- Defines `kernel_exception_subscriber` (Twig-dependent!), `dispatcher`, `symfony_response_listener`
- New JSON exception subscriber must NOT depend on `@template`

**src/phpbb/common/config/default/container/services_routing.yml**
- Defines `router`, `router.listener`, `routing.helper`; new API routing loader drops in here

**src/phpbb/common/config/default/container/services_auth.yml**
- Defines `auth`, `auth.provider_collection`; API token subscriber uses `@auth`

**src/phpbb/common/config/default/routing/routing.yml**
- Master routing include file; new `api.yml` must be added here

**docker/nginx/default.conf**
- Nginx vhost config; needs new `/api` location block pointing to `web/api.php`

### Related Files

**src/phpbb/forums/install/controller/timeout_check.php**
- Simplest existing controller returning `JsonResponse`; direct template for API controllers

**src/phpbb/forums/feed/controller/feed.php**
- More complex controller using auth + config injection; template for auth-aware API controllers

**src/phpbb/forums/event/kernel_exception_subscriber.php** (inferred from services_event.yml)
- Existing exception subscriber (Twig-based); JSON variant must mirror its structure without `@template`

**composer.json**
- PSR-4 map; `phpbb\core\` → `src/phpbb/core/` namespace already registered (confirmed by Code Analysis agent)

**src/phpbb/common/config/installer/container/services.yml**
- Installer-specific DI; `web/install/api.php` needs its own slim service stack

---

## Current Functionality

The forum dispatch loop is fully operational:
1. `web/app.php` includes `common.php` which compiles/loads the DI container.
2. `$user->session_begin()` + `$auth->acl()` establish session context.
3. `http_kernel->handle($symfony_request)` routes via Symfony `Router` → resolves `service:method` controller → returns `Response`.
4. `$response->send()` + `http_kernel->terminate()` complete the cycle.

Admin (`web/adm/index.php`) follows the same pattern with an `ADMIN_START` constant guard.
Installer (`web/install.php`) → `src/phpbb/install/app.php` → `startup.php` uses an isolated container with `without_extensions()`.

**No REST API layer exists today.** There are no `/api` routes, no Bearer-token auth subscriber, and no JSON-only exception handler.

### Key Components / Functions

- **`container_builder`**: Builds & caches the Symfony DI container; entry point for all bootstrap variants
- **`http_kernel->handle()`**: Core dispatch; dispatches `kernel.request`, `kernel.controller`, `kernel.response`, `kernel.exception`
- **Controller resolver**: Parses `service:method` from `_controller` attribute; fetches service from container
- **`kernel_exception_subscriber`**: Catches unhandled exceptions and renders an HTML error page (Twig-dependent — must be replaced for API routes)
- **`router.listener`**: `RouterListener` — matches incoming URL to a route definition; populates `_controller`

### Data Flow

```
HTTP Request
  → web/api.php
    → common.php (DI container boot)
    → [NO session_begin() — stateless]
    → http_kernel->handle($request)
      → kernel.request: ApiTokenAuthSubscriber (validate Bearer token)
      → RouterListener (match /api/v1/... route)
      → ControllerResolver (resolve phpbb.api.v1.controller.NAME:action)
      → Controller::action() → JsonResponse
      → kernel.exception: JsonExceptionSubscriber (on error → JSON error body)
    → $response->send()
    → http_kernel->terminate()
```

---

## Dependencies

### Imports (What the API Stack Depends On)

| Dependency | Purpose |
|---|---|
| `Symfony\Component\HttpKernel\HttpKernel` | Core dispatch engine |
| `Symfony\Component\HttpFoundation\JsonResponse` | API response type |
| `Symfony\Component\EventDispatcher\EventSubscriberInterface` | Subscriber contracts |
| `Symfony\Component\HttpKernel\Event\RequestEvent` | Auth subscriber hook point |
| `Symfony\Component\HttpKernel\Event\ExceptionEvent` | Exception subscriber hook point |
| `@dispatcher` (DI service) | Event bus wiring |
| `@request_stack` (DI service) | Request context |
| `@auth` (DI service) | phpBB ACL/auth object |
| `@config` (DI service) | phpBB config values (token validation) |
| `@language` (DI service) | Error message translation |

### Consumers (What Depends on What We're Building)

| File | Relationship |
|---|---|
| `web/api.php` | Consumes `phpbb\core\Application` |
| `web/adm/api.php` | Consumes `phpbb\core\Application` with admin flags |
| `web/install/api.php` | Consumes `phpbb\core\Application` with installer container |
| Nginx `default.conf` | Routes `/api` URI to `web/api.php` |
| `services.yml` | Imports new `services_api.yml` |
| `routing.yml` | Imports new `api.yml` routes |

**Consumer Count**: 0 existing files broken by new additions (purely additive)
**Impact Scope**: Low — no existing code is modified except two YAML imports and one nginx block

---

## Test Coverage

### Existing Test Files

No tests found for web entry points or the kernel event subscribers in the explored paths. The `phpunit` binary is available at `vendor/bin/phpunit`.

### Coverage Assessment

- **Test count for affected code**: 0 (no existing API code to test)
- **Framework**: PHPUnit (via vendor)
- **Gaps**: All new code (entry points, Application class, controllers, subscribers) will need tests written
- **Test path convention**: Mirror `src/phpbb/` under a `tests/` directory (standard phpBB layout)

---

## Coding Patterns

### Naming Conventions

| Element | Pattern | Example |
|---|---|---|
| Namespace | `phpbb\DOMAIN\sub` (PSR-4, snake_case segments) | `phpbb\api\controller` |
| Class | `PascalCase` | `ApiTokenAuthSubscriber` |
| Service ID | `phpbb.domain.sub.name` (dot-separated) | `phpbb.api.v1.controller.users` |
| Route ID | `phpbb_domain_resource_action` (underscore) | `phpbb_api_v1_users_list` |
| File | `snake_case.php` | `api_token_auth_subscriber.php` |
| Controller tag | `phpbb.DOMAIN.controller` | `phpbb.api.v1.controller` |

### Architecture Patterns

- **Style**: OOP, Symfony-idiomatic; constructor injection only
- **Controller**: Plain PHP class with typed constructor args; each public method returns a `Response`
- **Subscriber**: Implements `EventSubscriberInterface`; static `getSubscribedEvents()` returns priority-ordered map
- **DI**: Pure YAML service definitions — no annotations, no `#[Autowire]`
- **Routing**: YAML-only route files included via master `routing.yml`
- **No `global` in OOP code** — all dependencies via constructor DI
- **No closing PHP tag** (`?>`)
- **Indentation**: Tabs (phpBB standard)

---

## Complexity Assessment

| Factor | Value | Level |
|---|---|---|
| New files to create | ~15–18 files | High |
| Dependencies per controller | 2–5 services | Low–Medium |
| Consumers of new code | 0 existing files broken | Low |
| Test coverage (new) | 0% (must be written) | High risk |
| Integration surface | 2 YAML imports + 1 nginx block | Low |

### Overall: **Moderate**

The dispatch mechanics are already proven and reusable. The complexity comes from the number of files to create and wiring them correctly — not from algorithmic difficulty. The biggest risk is the exception subscriber Twig dependency: the existing `kernel_exception_subscriber` must NOT be replaced globally; the JSON variant must run only for API routes (check path prefix or content-type negotiation).

---

## Key Findings

### Strengths

- Symfony HttpKernel is already the dispatch engine — no framework change needed
- Controller resolver already supports `service:method` format — controllers are just DI services
- Three distinct bootstrap paths are well-separated — API variants slot in cleanly
- `phpbb\core\` namespace is already in `composer.json` PSR-4 map — ready to use
- DI container is compiled to cache (`cache/production/`) — production performance unaffected

### Concerns

- **Twig coupling in `kernel_exception_subscriber`**: existing subscriber will fire for API routes and return HTML. A JSON exception subscriber with higher priority (or path-aware guard) is essential.
- **Session bootstrap for admin API**: `web/adm/api.php` must decide whether to call `session_begin()` or use pure token auth — mixing both is risky.
- **Installer container is isolated**: `web/install/api.php` must use `startup.php`'s container; services like `@user` and `@auth` may not be available there.
- **`phpbb\core\Application` base class**: must be thin — avoid duplicating common.php logic; wrap it, don't replace it.
- **Cache invalidation**: Adding new service/routing YAMLs requires cache clear (`cache/production/` purge) after deployment.

### Opportunities

- A `phpbb\core\Application` base class can eliminate copy-paste across the three entry points
- JSON exception subscriber can double as structured API error formatter (RFC 7807 Problem Details)
- API token storage can reuse the existing `$config` service without a new DB table initially

---

## Impact Assessment

- **Primary changes** (new files only):
  - `web/api.php`
  - `web/adm/api.php`
  - `web/install/api.php`
  - `src/phpbb/core/Application.php`
  - `src/phpbb/api/controller/*.php` (v1 resource controllers)
  - `src/phpbb/admin/api/controller/*.php` (admin resource controllers)
  - `src/phpbb/api/event/json_exception_subscriber.php`
  - `src/phpbb/api/event/api_token_auth_subscriber.php`
  - `src/phpbb/common/config/default/container/services_api.yml`
  - `src/phpbb/common/config/default/routing/api.yml`
  - `src/phpbb/common/config/default/routing/api_admin.yml`

- **Related changes** (minimal edits to existing files):
  - `src/phpbb/common/config/default/container/services.yml` — add import for `services_api.yml`
  - `src/phpbb/common/config/default/routing/routing.yml` — add import for `api.yml`
  - `docker/nginx/default.conf` — add `/api` and `/adm/api` location blocks

- **Test updates**:
  - New test suite needed under `tests/api/` covering subscribers and controllers with PHPUnit

### Risk Level: **Low-Medium**

Additive-only implementation with two small YAML edits and one nginx edit. Main risk is the exception subscriber priority conflict with the existing Twig-based handler.

---

## Recommendations

### Architecture

1. **`phpbb\core\Application`** should be a thin wrapper:
   ```php
   namespace phpbb\core;
   class Application {
       public function __construct(private string $rootPath, private array $env = []) {}
       public function run(): void { /* include common.php, handle, send, terminate */ }
   }
   ```
   Entry points call `(new Application(__DIR__ . '/../'))->run()` — no duplication.

2. **JSON Exception Subscriber** must run at higher priority than the Twig subscriber. Check `$event->getRequest()->getPathInfo()` prefix `/api` before acting, so it does not intercept forum HTML errors:
   ```php
   public static function getSubscribedEvents(): array {
       return [KernelEvents::EXCEPTION => ['onKernelException', 10]]; // higher than default 0
   }
   ```

3. **API Token Auth Subscriber** hooks `kernel.request` at priority `> RouterListener` (default 32), so it runs after routing but before the controller. It should:
   - Extract `Authorization: Bearer <token>` header
   - Validate against a stored hash in `$config['api_token_hash']` (or a DB table)
   - Return `401 JsonResponse` on failure immediately via `$event->setResponse()`

4. **Do NOT call `$user->session_begin()`** in `web/api.php` — API is stateless. Admin API (`web/adm/api.php`) can call it only if mixing session + token is an explicit requirement.

5. **Route structure**:
   ```yaml
   # api.yml
   phpbb_api_v1_users_list:
       path: /api/v1/users
       defaults: { _controller: phpbb.api.v1.controller.users:list }
       methods: [GET]
   ```

6. **Service naming**:
   ```yaml
   # services_api.yml
   phpbb.api.v1.controller.users:
       class: phpbb\api\controller\users
       arguments: ['@config', '@user', '@auth', '@db']
   phpbb.api.event.json_exception_subscriber:
       class: phpbb\api\event\json_exception_subscriber
       arguments: ['%debug.exceptions%']
       tags: [{ name: kernel.event_subscriber }]
   phpbb.api.event.api_token_auth_subscriber:
       class: phpbb\api\event\api_token_auth_subscriber
       arguments: ['@config', '@request']
       tags: [{ name: kernel.event_subscriber }]
   ```

### Implementation Order

1. Create `services_api.yml` and `api.yml` routing stubs (empty but valid YAML)
2. Wire imports in `services.yml` and `routing.yml`
3. Implement `phpbb\core\Application` base class
4. Implement `web/api.php` using `Application`
5. Implement `json_exception_subscriber` (path-guarded)
6. Implement `api_token_auth_subscriber`
7. Implement first controller (`users` or `health`)
8. Add nginx location block
9. Test end-to-end with `curl`
10. Add `web/adm/api.php` and `web/install/api.php` variants
11. Write PHPUnit tests

### Anti-Patterns to Avoid

- Do NOT use `global $db` in controllers — inject `@db` via constructor
- Do NOT return strings from controller actions — always return `Response` or `JsonResponse`
- Do NOT reuse the existing `kernel_exception_subscriber` — it has a hard `@template` (Twig) dependency
- Do NOT omit the closing PHP tag omission — phpBB standard forbids `?>`
- Do NOT use PHP short tags

---

## Next Steps

Proceed to gap analysis phase: identify which files from the implementation list above have no existing counterpart, confirm the exact `phpbb\core\Application` public interface, and produce a detailed implementation plan with file-by-file task breakdown.

---

*Generated by codebase-analysis-reporter from 3 Explore agent outputs (File Discovery, Code Analysis, Pattern Mining).*
