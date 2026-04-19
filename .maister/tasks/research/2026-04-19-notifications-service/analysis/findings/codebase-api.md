# Codebase Findings: REST API Infrastructure

**Source Category**: codebase-api
**Date**: 2026-04-19
**Confidence**: High (100%) — all findings from direct source code reading

---

## Table of Contents

1. [API Entry Point](#1-api-entry-point)
2. [Application Wrapper](#2-application-wrapper)
3. [Routing Configuration](#3-routing-configuration)
4. [DI Services](#4-di-services)
5. [API Controllers — Pattern & Examples](#5-api-controllers--pattern--examples)
6. [Authentication (JWT)](#6-authentication-jwt)
7. [Error Handling](#7-error-handling)
8. [Nginx / CORS Layer](#8-nginx--cors-layer)
9. [Response Format Conventions](#9-response-format-conventions)
10. [Template for Notifications Endpoint](#10-template-for-notifications-endpoint)
11. [Gaps & TODOs](#11-gaps--todos)

---

## 1. API Entry Point

**File**: `web/api.php` (28 lines)

```php
define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../');
$phpbb_root_path = './';
include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php');

/** @var \phpbb\core\Application $app */
$phpbb_container->get('api.application')->run();
```

**Bootstrap sequence**:
1. `common.php` loads config, builds DI container (`$phpbb_container`), sets up class loader
2. No `session_begin()` / `acl()` calls — authentication is fully delegated to `api.auth_subscriber`
3. `api.application` service is fetched from DI, its `run()` method handles the full Symfony request cycle

**Source**: `web/api.php:1-28`, `src/phpbb/common/common.php:1-100`

---

## 2. Application Wrapper

**File**: `src/phpbb/core/Application.php`
**Class**: `phpbb\core\Application`
**Pattern**: Composition over inheritance — wraps `Symfony\Component\HttpKernel\HttpKernel`

```php
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

    public function run()
    {
        $request  = $this->container->get('symfony_request');
        $response = $this->handle($request);
        $response->send();
        $this->terminate($request, $response);
    }
}
```

**Key facts**:
- Same class is registered as both `api.application` and `admin_api.application` (different DI IDs, same `@http_kernel`)
- The `symfony_request` service provides the Symfony Request object (wraps `$_SERVER`, `$_GET`, etc.)
- `http_kernel` dispatches through: RouterListener → ControllerResolver → controller method → Response

**Source**: `src/phpbb/core/Application.php:1-84`

---

## 3. Routing Configuration

### Main routing import

**File**: `src/phpbb/common/config/default/routing/routing.yml`

```yaml
phpbb_api_routing:
    resource: api.yml
    prefix:   /

phpbb_admin_api_routing:
    resource: admin_api.yml
    prefix:   /
```

The prefix is `/` — the full path (e.g. `/api/v1/...`) is specified in the route definition itself.

### Forum API routes

**File**: `src/phpbb/common/config/default/routing/api.yml`

```yaml
api_health:
    path:     /api/v1/health
    defaults:
        _controller: phpbb.api.v1.controller.health:index
    methods:  [GET]

api_forums:
    path:     /api/v1/forums
    defaults:
        _controller: phpbb.api.v1.controller.forums:index
    methods:  [GET]

api_forum_topics:
    path:     /api/v1/forums/{id}
    defaults:
        _controller: phpbb.api.v1.controller.forums:topics
    methods:  [GET]
    requirements:
        id: \d+

api_topics:
    path:     /api/v1/topics
    defaults:
        _controller: phpbb.api.v1.controller.topics:index
    methods:  [GET]

api_topic_show:
    path:     /api/v1/topics/{id}
    defaults:
        _controller: phpbb.api.v1.controller.topics:show
    methods:  [GET]
    requirements:
        id: \d+

api_users_me:
    path:     /api/v1/users/me
    defaults:
        _controller: phpbb.api.v1.controller.users:me
    methods:  [GET]

api_auth_login:
    path:     /api/v1/auth/login
    defaults:
        _controller: phpbb.api.v1.controller.auth:login
    methods:  [POST]

api_auth_signup:
    path:     /api/v1/auth/signup
    defaults:
        _controller: phpbb.api.v1.controller.auth:signup
    methods:  [POST]
```

### Route definition pattern

| Element | Convention | Example |
|---------|-----------|---------|
| Route name | `api_{resource}_{action}` | `api_topic_show` |
| Path | `/api/v1/{resource}[/{id}]` | `/api/v1/topics/{id}` |
| Controller | `phpbb.api.v1.controller.{resource}:{method}` | `phpbb.api.v1.controller.topics:show` |
| Methods | Explicit `[GET]`, `[POST]`, etc. | `methods: [GET]` |
| Requirements | Regex for route params | `id: \d+` |
| Versioning | Hardcoded `/v1/` in path | N/A |

### Future: `_api_permission` route defaults

Per auth service high-level design (`.maister/tasks/research/2026-04-18-auth-service/outputs/high-level-design.md`), routes will gain permission metadata:

```yaml
api_forums:
    path:     /api/v1/forums
    defaults:
        _controller: phpbb.api.v1.controller.forums:index
        _api_permission: f_list        # ACL option checked by AuthorizationSubscriber
        _api_forum_param: id           # Route param holding forum_id for forum-scoped perms
```

This is **not yet implemented** in current routes but is the planned pattern.

**Source**: `src/phpbb/common/config/default/routing/api.yml:1-49`, `src/phpbb/common/config/default/routing/routing.yml:1-37`

---

## 4. DI Services

**File**: `src/phpbb/common/config/default/container/services_api.yml`

```yaml
services:
    # ─── Application wrapper ────────────────────────────────
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

    # ─── Event subscribers ──────────────────────────────────
    api.json_exception_subscriber:
        class: phpbb\api\event\json_exception_subscriber
        arguments:
            - '%debug.exceptions%'
        tags:
            - { name: kernel.event_subscriber }

    api.auth_subscriber:
        class: phpbb\api\event\auth_subscriber
        tags:
            - { name: kernel.event_subscriber }

    # ─── Forum API controllers ──────────────────────────────
    phpbb.api.v1.controller.health:
        class: phpbb\api\v1\controller\health

    phpbb.api.v1.controller.forums:
        class: phpbb\api\v1\controller\forums

    phpbb.api.v1.controller.topics:
        class: phpbb\api\v1\controller\topics

    phpbb.api.v1.controller.users:
        class: phpbb\api\v1\controller\users

    phpbb.api.v1.controller.auth:
        class: phpbb\api\v1\controller\auth

    # ─── Admin API controllers ──────────────────────────────
    phpbb.admin.api.v1.controller.health:
        class: phpbb\admin\api\v1\controller\health

    phpbb.admin.api.v1.controller.users:
        class: phpbb\admin\api\v1\controller\users
```

### Service naming convention

| Pattern | Example |
|---------|---------|
| Controller service ID | `phpbb.api.v1.controller.{resource}` |
| Controller class | `phpbb\api\v1\controller\{resource}` |
| Event subscriber | `api.{name}_subscriber` |

### Key observation: No constructor injection in Phase 1 controllers

Current controllers have **zero constructor arguments** — they are mock/hardcoded implementations. Real controllers will need DI arguments for database, config, etc. Example of how that would look:

```yaml
phpbb.api.v1.controller.notifications:
    class: phpbb\api\v1\controller\notifications
    arguments:
        - '@phpbb.notifications.service'
        - '@user'
```

**Source**: `src/phpbb/common/config/default/container/services_api.yml:1-57`

---

## 5. API Controllers — Pattern & Examples

### Controller signature pattern

All controllers follow the same pattern:

```php
namespace phpbb\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class {resource}
{
    /**
     * {METHOD} /api/v1/{resource}
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function {action}(Request $request)
    {
        // Access JWT claims (set by auth_subscriber)
        $token = $request->attributes->get('_api_token');
        $user_id = $token->user_id ?? null;

        // Business logic...

        return new JsonResponse([
            'resource_key' => $data,
            'total'        => count($data),
        ]);
    }
}
```

### Existing controllers

| Controller | File | Methods | Auth Required |
|-----------|------|---------|--------------|
| `health` | `src/phpbb/api/v1/controller/health.php` | `index()` → GET /api/v1/health | No (public) |
| `auth` | `src/phpbb/api/v1/controller/auth.php` | `login()` → POST /api/v1/auth/login, `signup()` → POST /api/v1/auth/signup | No (public) |
| `forums` | `src/phpbb/api/v1/controller/forums.php` | `index()` → GET /api/v1/forums, `topics($id)` → GET /api/v1/forums/{id} | Yes (JWT) |
| `topics` | `src/phpbb/api/v1/controller/topics.php` | `index()` → GET /api/v1/topics, `show($id)` → GET /api/v1/topics/{id} | Yes (JWT) |
| `users` | `src/phpbb/api/v1/controller/users.php` | `me()` → GET /api/v1/users/me | Yes (JWT) |

### Route params in controller methods

Route parameters (`{id}`) are passed as method arguments:

```php
// Route: /api/v1/topics/{id} with requirements: id: \d+
public function show(Request $request, $id)
{
    $id = (int) $id;
    // ...
}
```

### Query params for filtering

```php
// GET /api/v1/topics?forum_id=3
$forum_id = $request->query->get('forum_id');
```

### Request body (JSON)

```php
// POST /api/v1/auth/login
$body = json_decode($request->getContent(), true);
$login    = isset($body['login'])    ? (string) $body['login']    : '';
$password = isset($body['password']) ? (string) $body['password'] : '';
```

**Source**: `src/phpbb/api/v1/controller/forums.php:1-98`, `src/phpbb/api/v1/controller/topics.php:1-120`, `src/phpbb/api/v1/controller/auth.php:1-168`

---

## 6. Authentication (JWT)

**File**: `src/phpbb/api/event/auth_subscriber.php`
**Class**: `phpbb\api\event\auth_subscriber`

### How it works

1. Registered as `kernel.event_subscriber` — fires on `KernelEvents::REQUEST`
2. Checks if request path starts with `/api/`, `/adm/api/`, or `/install/api/`
3. Skips non-API paths entirely (standard phpBB routes pass through)
4. **Public endpoints** (no auth required): paths ending with `/health`, `/auth/login`, `/auth/signup`
5. All other API paths require `Authorization: Bearer <jwt>` header

### Token validation flow

```
Request → auth_subscriber::on_kernel_request()
  ├─ Non-API path? → return (pass through)
  ├─ Public endpoint? → return (no auth)
  ├─ No/malformed Authorization header? → 401 JSON response
  └─ Has Bearer token:
       ├─ JWT::decode() success → store claims in $request->attributes->set('_api_token', $claims)
       ├─ ExpiredException → 401 "Token expired"
       ├─ SignatureInvalidException → 401 "Invalid token signature"
       └─ UnexpectedValueException → 401 "Invalid token"
```

### Token claims available to controllers

```php
$token = $request->attributes->get('_api_token');
// stdClass with properties:
// - $token->user_id   (int)
// - $token->username  (string)
// - $token->admin     (bool)
// - $token->iss       (string, "phpBB")
// - $token->iat       (int, issued-at timestamp)
// - $token->exp       (int, expiry timestamp)
```

### JWT secret

Currently **hardcoded**: `'phpbb-api-secret-change-in-production'` (both in `auth_subscriber.php` and `auth.php` controller). Marked as TODO for Phase 2 config parameter.

### Error response format (auth failures)

```json
{
    "error": "Missing or malformed Authorization header",
    "status": 401
}
```

**Source**: `src/phpbb/api/event/auth_subscriber.php:1-112`

---

## 7. Error Handling

**File**: `src/phpbb/api/event/json_exception_subscriber.php`
**Class**: `phpbb\api\event\json_exception_subscriber`

### How it works

1. Subscribes to `KernelEvents::EXCEPTION` at **priority 10** (fires before the default HTML subscriber at priority 0)
2. Guarded by path: only intercepts `/api/`, `/adm/api/`, `/install/api/` paths
3. Calls `$event->stopPropagation()` to prevent the HTML/Twig subscriber from overriding

### Error response format

```json
{
    "error": "Error message from exception",
    "status": 404
}
```

In debug mode (`%debug.exceptions%` = true), includes `trace`:

```json
{
    "error": "Something went wrong",
    "status": 500,
    "trace": "#0 /path/to/file.php(42): ..."
}
```

### HTTP status code resolution

- `HttpExceptionInterface` exceptions → use `$exception->getStatusCode()`
- All other exceptions → `500`
- Headers from `HttpExceptionInterface` are forwarded to the JSON response

### Controller-level errors

Controllers return error responses manually:

```php
return new JsonResponse(['error' => 'Topic not found', 'status' => 404], 404);
```

Validation errors use 422:

```php
return new JsonResponse(['errors' => $errors, 'status' => 422], 422);
```

**Source**: `src/phpbb/api/event/json_exception_subscriber.php:1-98`

---

## 8. Nginx / CORS Layer

**File**: `docker/nginx/default.conf`

```nginx
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
```

**Key facts**:
- `^~` prefix location intercepts all `/api/` requests before the generic PHP handler
- `SCRIPT_FILENAME` always points to `api.php` — all API URLs are handled by a single entry point
- CORS headers are set at the nginx level (wildcard origin `*`)
- OPTIONS preflight returns 204 directly from nginx
- No rate limiting configured

**Source**: `docker/nginx/default.conf:28-38`

---

## 9. Response Format Conventions

### Successful responses

**Collection** (GET list):
```json
{
    "forums": [ ... ],
    "total": 3
}
```

**Single resource** (GET by ID):
```json
{
    "topic": { "id": 1, "title": "...", "content": "..." }
}
```

**Auth token**:
```json
{
    "token": "<jwt_string>",
    "expires_in": 3600
}
```

**Created resource** (201):
```json
{
    "token": "<jwt>",
    "expires_in": 3600,
    "user": { "id": 100, "username": "...", "email": "...", "admin": false }
}
```

### Error responses

**Single error**:
```json
{
    "error": "Error message",
    "status": 401
}
```

**Validation errors (422)**:
```json
{
    "errors": ["username is required", "password must be at least 6 characters"],
    "status": 422
}
```

### Observations

- No pagination implemented yet (Phase 1 is mock data)
- No `meta` envelope (no `links`, `page`, `per_page` fields)
- Response keys use the resource name as top-level key (pluralized for collections, singular for show)
- `total` count provided for collections
- Status code duplicated in both HTTP status and response body `status` field
- No rate limiting

**Source**: All controllers in `src/phpbb/api/v1/controller/`

---

## 10. Template for Notifications Endpoint

Based on the patterns above, a notifications controller would follow this structure:

### 1. Route definition (`api.yml` additions)

```yaml
api_notifications:
    path:     /api/v1/notifications
    defaults:
        _controller: phpbb.api.v1.controller.notifications:index
    methods:  [GET]

api_notification_mark_read:
    path:     /api/v1/notifications/{id}/read
    defaults:
        _controller: phpbb.api.v1.controller.notifications:mark_read
    methods:  [POST]
    requirements:
        id: \d+

api_notifications_mark_all_read:
    path:     /api/v1/notifications/read
    defaults:
        _controller: phpbb.api.v1.controller.notifications:mark_all_read
    methods:  [POST]
```

### 2. Service definition (`services_api.yml` addition)

```yaml
phpbb.api.v1.controller.notifications:
    class: phpbb\api\v1\controller\notifications
    arguments:
        - '@phpbb.notifications.service'   # or whatever the notification service is named
```

### 3. Controller skeleton

```php
<?php
namespace phpbb\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class notifications
{
    public function index(Request $request)
    {
        $token = $request->attributes->get('_api_token');
        $user_id = $token->user_id;

        // Fetch notifications for user...

        return new JsonResponse([
            'notifications' => $notifications,
            'total'         => count($notifications),
            'unread_count'  => $unread_count,
        ]);
    }

    public function mark_read(Request $request, $id)
    {
        $token = $request->attributes->get('_api_token');
        // Mark notification $id as read...
        return new JsonResponse(['status' => 'ok']);
    }

    public function mark_all_read(Request $request)
    {
        $token = $request->attributes->get('_api_token');
        // Mark all as read...
        return new JsonResponse(['status' => 'ok']);
    }
}
```

---

## 11. Gaps & TODOs

| Gap | Notes |
|-----|-------|
| **No pagination** | No `page`/`per_page`/`offset` parameters implemented in any controller. Notifications will need pagination. |
| **No DI in controllers** | All Phase 1 controllers are zero-dependency mocks. Notifications controller will be the first to need actual service injection. |
| **JWT secret hardcoded** | `auth_subscriber.php:39` and `auth.php:37` both hardcode `'phpbb-api-secret-change-in-production'`. |
| **No `_api_permission` enforcement** | Route-level permission defaults are designed (auth service research) but not yet in routes. |
| **No rate limiting** | Neither nginx nor PHP level. |
| **Error format inconsistency** | Single errors use `"error"` key, validation uses `"errors"` (array). Could benefit from a standardized error envelope. |
| **No response caching headers** | No `Cache-Control`, `ETag`, or `Last-Modified` headers set. |
| **No PATCH/PUT/DELETE examples** | All current endpoints are GET or POST only. Notifications `mark_read` would be first PATCH candidate. |
| **Public endpoint detection by suffix** | `auth_subscriber` uses `substr()` suffix matching for `/health`, `/auth/login`, `/auth/signup`. Adding more public endpoints requires editing the subscriber or moving to a route-based `_api_public` default. |
