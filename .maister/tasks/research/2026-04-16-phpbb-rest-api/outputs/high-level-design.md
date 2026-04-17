# phpBB REST API — High-Level Design

## Design Overview

phpBB already ships with `Symfony\Component\HttpKernel\HttpKernel` (without FrameworkBundle) and a Symfony DI container — the proposed REST API layer is a **configuration extension** of this existing infrastructure, not a re-architecture. Three independent HTTP entry points (`web/api.php`, `web/adm/api.php`, `web/install/api.php`) each bootstrap a DI container and delegate to a shared **`phpbb\core\Application`** wrapper class (~40 lines) that calls `HttpKernel::handle()` and sends a `JsonResponse`. Forum API and Admin API share the existing forum DI container (extended via new YAML imports); the Installer API uses phpBB's isolated installer container, which intentionally has no DB services and never includes `common.php`.

**Key decisions:**
- **Composition over inheritance** — `phpbb\core\Application` wraps `HttpKernel` privately; all three APIs use the same class with different DI service IDs
- **Session-based auth for Phase 1** — `session_begin()` + `acl()` reused from existing entry points; Phase 2 migrates to `Authorization: Bearer <token>` via a `kernel.request` subscriber
- **New YAML route files** — `api.yml`, `admin_api.yml`, `install_api.yml` imported into their container's `routing.yml`; zero new PHP routing infrastructure
- **Separate PSR-4 namespaces** — `phpbb\core\`, `phpbb\api\`, `phpbb\admin\` each own their subtree under `src/phpbb/`
- **Hardcoded mock data in Phase 1** — controllers return `new JsonResponse([...])` with static arrays; every mock is removable with a single grep
- **JSON exception subscriber at priority 10** — fires before the Twig-dependent forum subscriber (priority 0), returns JSON for all API exceptions

---

## Architecture

### System Context (C4 Level 1)

```
                        phpBB Forum System
┌───────────────────────────────────────────────────────────────┐
│                                                               │
│   [Browser / Forum User]  ──────►  web/index.php             │
│                                    web/viewtopic.php          │
│                                    (existing phpBB pages)     │
│                                                               │
│   [REST Client / SPA]   ─────────► web/api.php               │
│   (curl, mobile, JS)               ↓                         │
│                                    phpbb\api\*                │
│                                    (Forum REST API)           │
│                                                               │
│   [Admin Tool / SPA]    ─────────► web/adm/api.php           │
│                                    ↓                         │
│                                    phpbb\admin\api\*          │
│                                    (Admin REST API)           │
│                                                               │
│   [Installer Client]    ─────────► web/install/api.php        │
│                                    ↓                         │
│                                    phpbb\install\api\*        │
│                                    (Installer REST API)        │
│                                                               │
│          ↕ shared via phpBB DI container                     │
│   [MySQL / MariaDB]     ◄────────  forum + admin APIs        │
│   [Filesystem]          ◄────────  all three APIs            │
│                                                               │
└───────────────────────────────────────────────────────────────┘
```

All three APIs live inside the same PHP process / Docker container. Nginx routes by URI prefix to the correct entry point PHP file. External systems (DB, filesystem, cache) are accessed through existing phpBB services.

---

### Container Overview (C4 Level 2)

```
  ┌──────────────────────────────────────────────────────────────────────┐
  │  Docker: app (PHP-FPM, port 9000)                                    │
  │                                                                      │
  │  ┌─────────────────────────────────────────────────────────────┐     │
  │  │  Nginx (port 80/443)                                        │     │
  │  │                                                             │     │
  │  │  location ^~ /api/        → SCRIPT_FILENAME=web/api.php     │     │
  │  │  location ^~ /adm/api/    → SCRIPT_FILENAME=web/adm/api.php │     │
  │  │  location ^~ /install/api/→ SCRIPT_FILENAME=web/install/api │     │
  │  │  location ~ \.php         → app.php (existing forum)        │     │
  │  └──────────────────────────────────────────────────────────────┘     │
  │          │ FastCGI (SCRIPT_FILENAME hardcoded per API)               │
  │          ▼                                                           │
  │  ┌────────────────────┐  ┌────────────────────┐  ┌───────────────┐  │
  │  │  web/api.php       │  │  web/adm/api.php   │  │web/install/   │  │
  │  │                    │  │                    │  │api.php        │  │
  │  │  include           │  │  define ADMIN_START│  │               │  │
  │  │  common.php        │  │  include           │  │require        │  │
  │  │  session_begin()   │  │  common.php        │  │startup.php    │  │
  │  │  auth->acl()       │  │  session+acl       │  │(NO common.php)│  │
  │  │  user->setup('')   │  │  gate: a_=false→403│  │               │  │
  │  └────────┬───────────┘  └─────────┬──────────┘  └──────┬────────┘  │
  │           │                        │                     │          │
  │           ▼  (same container)      ▼ (same container)    ▼          │
  │  ┌────────────────────────────┐    │         ┌────────────────────┐  │
  │  │  Forum DI Container        │◄───┘         │Installer DI        │  │
  │  │  (built by common.php)     │              │Container           │  │
  │  │                            │              │(built by startup.  │  │
  │  │  api.application           │              │php, no DB)         │  │
  │  │    phpbb\core\Application  │              │                    │  │
  │  │    wraps @http_kernel      │              │install_api.app     │  │
  │  │                            │              │  phpbb\core\App    │  │
  │  │  admin_api.application     │              │  wraps @http_kernel│  │
  │  │    same class, same kernel │              │                    │  │
  │  │                            │              │install_api.except  │  │
  │  │  api.exception_listener    │              │_listener           │  │
  │  │    priority 10 on          │              │  json_exception_   │  │
  │  │    kernel.exception        │              │  subscriber        │  │
  │  │                            │              └────────────────────┘  │
  │  │  phpbb.api.v1.*controllers │                                      │
  │  │  phpbb.admin.api.v1.*ctrls │              [phpbb_api_tokens table]│
  │  │                            │              (Phase 2 only)          │
  │  │  @http_kernel (shared)     │                                      │
  │  │  @router.listener          │                                      │
  │  │  @dispatcher               │                                      │
  │  │  @dbal.conn, @config, etc. │                                      │
  │  └────────────────────────────┘                                      │
  │                │                                                     │
  │                ▼                                                     │
  │  ┌─────────────────────┐  ┌─────────────────────┐                   │
  │  │  MySQL / MariaDB    │  │  Filesystem cache   │                   │
  │  │  phpbb_*  tables    │  │  cache/production/  │                   │
  │  └─────────────────────┘  └─────────────────────┘                   │
  └──────────────────────────────────────────────────────────────────────┘
```

---

## Key Components

| Component | Class / File | Responsibility |
|-----------|-------------|----------------|
| **Core Application** | `phpbb\core\Application` · `src/phpbb/core/Application.php` | Wraps `HttpKernel`; implements `run()` — fetches request from container, handles it, sends response, terminates. ~40 lines. Same class used for all 3 APIs via different DI service IDs. |
| **JSON Exception Subscriber** | `phpbb\api\event\json_exception_subscriber` · `src/phpbb/api/event/json_exception_subscriber.php` | Listens on `kernel.exception` at priority 10. Converts any exception to JSON `{error, status}`. Shared by forum and admin containers; duplicated for installer container. |
| **Forum API Entry Point** | `web/api.php` | Includes `common.php`; calls `session_begin()` + `acl()`; retrieves `api.application` from container and calls `run()`. Max 20 lines. |
| **Admin API Entry Point** | `web/adm/api.php` | Same as forum entry point + defines `ADMIN_START`, `NEED_SID`, `IN_ADMIN`; enforces `acl_get('a_')` gate (JSON 403). Sets `$phpbb_root_path = '../../'`. |
| **Installer API Entry Point** | `web/install/api.php` | Defines `IN_INSTALL`; requires `startup.php` (never `common.php`); retrieves `install_api.application` from `$phpbb_installer_container`. |
| **Forum API Controllers** | `phpbb\api\v1\controller\*` · `src/phpbb/api/v1/controller/` | Return `JsonResponse` with hardcoded mock data. One class per resource (health, forums, topics, users). |
| **Admin API Controllers** | `phpbb\admin\api\v1\controller\*` · `src/phpbb/admin/api/v1/controller/` | Same pattern as forum controllers; require admin session. |
| **Installer API Controllers** | `phpbb\install\api\v1\controller\*` · `src/phpbb/install/api/v1/controller/` | Return installer status mocks; run in DB-free installer container. |
| **Forum API DI Config** | `services_api.yml` · `src/phpbb/common/config/default/container/` | Declares `api.application`, `admin_api.application`, `api.exception_listener`, and all controller services. Imported into `services.yml`. |
| **Installer API DI Config** | `services_install_api.yml` · `src/phpbb/common/config/installer/container/` | Declares `install_api.application`, `install_api.exception_listener`, installer controllers. |
| **Forum API Routes** | `api.yml` · `src/phpbb/common/config/default/routing/` | 5 Phase-1 routes under `/api/v1/`. Imported into `routing.yml`. |
| **Admin API Routes** | `admin_api.yml` · same dir | 3+ routes under `/adm/api/v1/`. Imported into `routing.yml`. |
| **Installer API Routes** | `install_api.yml` · `src/phpbb/common/config/installer/routing/` | 3 routes under `/install/api/v1/`. Imported into `environment.yml`. |
| **Nginx Config** | `docker/nginx/default.conf` | 3 new `location ^~` blocks (one per API), placed before the PHP regex block. Hardcode `SCRIPT_FILENAME` per entry point. |

---

## Request Flow

### Forum API — Typical Request

```
1.  Client sends:   GET /api/v1/forums
                    Authorization: Bearer <token>   (Phase 2)
                    Cookie: phpbb3_sid=...           (Phase 1)

2.  Nginx matches:  location ^~ /api/
                    SCRIPT_FILENAME = $document_root/api.php
                    REQUEST_URI = /api/v1/forums

3.  PHP-FPM runs:   web/api.php
    a.  define PHPBB_FILESYSTEM_ROOT, $phpbb_root_path = './'
    b.  include common.php  → builds forum DI container
    c.  $user->session_begin()  → loads session from DB
    d.  $auth->acl($user->data) → loads ACL
    e.  $user->setup('')
    f.  $phpbb_container->get('api.application')->run()

4.  Application::run()
    a.  $request = $container->get('symfony_request')
        (wraps $_GET, $_POST, $_SERVER)
    b.  $response = $this->handle($request)
        → delegates to HttpKernel::handle()

5.  HttpKernel dispatches kernel.request
    a.  RouterListener::onKernelRequest()
        → matches /api/v1/forums against api.yml
        → sets $request->attributes['_controller']
           = 'phpbb.api.v1.controller.forums:index'

6.  HttpKernel resolves controller
    → controller.resolver fetches 'phpbb.api.v1.controller.forums'
      from DI container, returns [object, 'index']

7.  HttpKernel calls:  forums::index(Request $request)
    → returns new JsonResponse(['data' => [...], 'total' => 0], 200)

8.  HttpKernel dispatches kernel.response
    → ResponseListener::onKernelResponse() prepares headers

9.  Application::run() calls $response->send()
    → writes HTTP headers + JSON body to stdout (PHP-FPM)

10. Application::run() calls $this->terminate($request, $response)
    → kernel_terminate_subscriber fires (GC, exit handlers)

11. Nginx forwards response to client.
```

### Admin API — Auth Gate

```
3c. $auth->acl($user->data)
3d. if (!$auth->acl_get('a_'))
      header('Content-Type: application/json', true, 403)
      echo json_encode(['error' => 'Forbidden', 'status' => 403])
      exit;          ← request ends here for non-admins
3e. $phpbb_container->get('admin_api.application')->run()
    → otherwise identical to forum API flow
```

### Exception Handling

```
7.  Controller throws any exception (e.g., NotFoundHttpException)
8.  HttpKernel dispatches kernel.exception
    a.  api.exception_listener::on_kernel_exception() [priority 10]
        → $event->setResponse(JsonResponse(['error'=>..., 'status'=>...]))
        → $event->stopPropagation()
    b.  kernel_exception_subscriber [priority 0] — SKIPPED (propagation stopped)
9.  Client receives JSON, not HTML error page.
```

---

## Database Changes

### Phase 1 — No Changes

Session-based auth uses existing `phpbb_sessions` table. No schema changes needed.

### Phase 2 — `phpbb_api_tokens` Table

```sql
CREATE TABLE phpbb_api_tokens (
    token_id   BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    user_id    INT(10) UNSIGNED  NOT NULL,
    token      CHAR(64)          NOT NULL COMMENT 'SHA-256 hex of the raw token',
    label      VARCHAR(255)      NOT NULL DEFAULT '',
    created    DATETIME          NOT NULL,
    last_used  DATETIME          DEFAULT NULL,
    is_active  TINYINT(1)        NOT NULL DEFAULT 1,
    PRIMARY KEY (token_id),
    UNIQUE KEY uidx_token (token),
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

**Column notes**:
- `token` — SHA-256 hex of the raw 32-byte random token issued to the client. Never stored in plaintext.
- `last_used` — updated on each successful authenticated request (DB write per request).
- `is_active` — soft-delete / revocation flag; active = 1.
- `user_id` — foreign key to `phpbb_users.user_id` (enforced at application level, not with FK constraint per phpBB convention).

---

## API Token Authentication Flow (Phase 2)

```
Class: phpbb\api\event\token_auth_subscriber
File:  src/phpbb/api/event/token_auth_subscriber.php
Event: kernel.request  (priority 16, before RouterListener at priority 8)
```

```
Client ──► Authorization: Bearer <raw_token>
              │
              ▼
        token_auth_subscriber::onKernelRequest()
              │
              ├── Extract raw_token from header
              │   (no header → reject with 401)
              │
              ├── $hash = hash('sha256', $raw_token)
              │
              ├── DB query:
              │   SELECT * FROM phpbb_api_tokens
              │   WHERE token = '$hash' AND is_active = 1
              │   (parameterized via $db->sql_build_query)
              │
              ├── No row found → JsonResponse 401, stopPropagation
              │
              ├── UPDATE phpbb_api_tokens
              │   SET last_used = NOW()
              │   WHERE token_id = <id>
              │
              ├── Load user:
              │   SELECT * FROM phpbb_users WHERE user_id = <user_id>
              │
              ├── Hydrate $user->data = $user_row
              │   $auth->acl($user->data)
              │
              └── Request continues to RouterListener → Controller
```

**Entry point change for Phase 2** (`web/api.php`):
- Remove: `$user->session_begin()` and `$auth->acl($user->data)`
- The subscriber handles auth entirely at the kernel level

---

## File Creation Map

Ordered list — each step is independently testable.

### Infrastructure

| # | Action | File | Purpose |
|---|--------|------|---------|
| 1 | MODIFY | `docker/nginx/default.conf` | Add 3 `location ^~` blocks |

### Core Library

| # | Action | File | Class / Purpose |
|---|--------|------|-----------------|
| 2 | CREATE | `src/phpbb/core/Application.php` | `phpbb\core\Application` — shared kernel wrapper |

### Forum API

| # | Action | File | Class / Purpose |
|---|--------|------|-----------------|
| 3 | CREATE | `src/phpbb/api/event/json_exception_subscriber.php` | `phpbb\api\event\json_exception_subscriber` |
| 4 | CREATE | `src/phpbb/common/config/default/container/services_api.yml` | DI: api.application, admin_api.application, exception listener, controllers |
| 5 | MODIFY | `src/phpbb/common/config/default/container/services.yml` | Add import of services_api.yml |
| 6 | CREATE | `src/phpbb/common/config/default/routing/api.yml` | 5 Phase-1 routes under /api/v1/ |
| 7 | MODIFY | `src/phpbb/common/config/default/routing/routing.yml` | Import api.yml and admin_api.yml |
| 8 | CREATE | `web/api.php` | Forum API entry point |
| 9 | CREATE | `src/phpbb/api/v1/controller/health.php` | `phpbb\api\v1\controller\health` |
| 10 | CREATE | `src/phpbb/api/v1/controller/users.php` | `phpbb\api\v1\controller\users` |
| 11 | CREATE | `src/phpbb/api/v1/controller/forums.php` | `phpbb\api\v1\controller\forums` |
| 12 | CREATE | `src/phpbb/api/v1/controller/topics.php` | `phpbb\api\v1\controller\topics` |

### Admin API

| # | Action | File | Class / Purpose |
|---|--------|------|-----------------|
| 13 | CREATE | `src/phpbb/common/config/default/routing/admin_api.yml` | Routes under /adm/api/v1/ |
| 14 | CREATE | `web/adm/api.php` | Admin API entry point |
| 15 | CREATE | `src/phpbb/admin/api/v1/controller/health.php` | `phpbb\admin\api\v1\controller\health` |
| 16 | CREATE | `src/phpbb/admin/api/v1/controller/users.php` | `phpbb\admin\api\v1\controller\users` |

### Installer API

| # | Action | File | Class / Purpose |
|---|--------|------|-----------------|
| 17 | CREATE | `src/phpbb/common/config/installer/container/services_install_api.yml` | DI: install_api.application, exception listener, controllers |
| 18 | MODIFY | `src/phpbb/common/config/installer/container/services.yml` | Add import of services_install_api.yml |
| 19 | CREATE | `src/phpbb/common/config/installer/routing/install_api.yml` | 3 routes under /install/api/v1/ |
| 20 | MODIFY | `src/phpbb/common/config/installer/routing/environment.yml` | Import install_api.yml |
| 21 | CREATE | `web/install/api.php` | Installer API entry point |
| 22 | CREATE | `src/phpbb/install/api/v1/controller/install.php` | `phpbb\install\api\v1\controller\install` |

### composer.json

| # | Action | File | Purpose |
|---|--------|------|---------|
| 23 | MODIFY | `composer.json` | Add `"phpbb\\api\\": "src/phpbb/api/"` to autoload.psr-4 |

---

## DI Services YAML

### `services_api.yml` (complete — forum + admin)

```yaml
# src/phpbb/common/config/default/container/services_api.yml

services:

    # ─── Shared Exception Subscriber ──────────────────────────────────
    api.exception_listener:
        class: phpbb\api\event\json_exception_subscriber
        arguments:
            - '%core.debug%'
        tags:
            - { name: kernel.event_subscriber }

    # ─── Application Instances ────────────────────────────────────────
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

    # ─── Forum API Controllers ────────────────────────────────────────
    phpbb.api.v1.controller.health:
        class: phpbb\api\v1\controller\health

    phpbb.api.v1.controller.forums:
        class: phpbb\api\v1\controller\forums

    phpbb.api.v1.controller.topics:
        class: phpbb\api\v1\controller\topics

    phpbb.api.v1.controller.users:
        class: phpbb\api\v1\controller\users

    # ─── Admin API Controllers ────────────────────────────────────────
    phpbb.admin.api.v1.controller.health:
        class: phpbb\admin\api\v1\controller\health

    phpbb.admin.api.v1.controller.users:
        class: phpbb\admin\api\v1\controller\users
```

### `services_install_api.yml` (complete — installer)

```yaml
# src/phpbb/common/config/installer/container/services_install_api.yml

services:

    install_api.exception_listener:
        class: phpbb\api\event\json_exception_subscriber
        arguments:
            - '%core.debug%'
        tags:
            - { name: kernel.event_subscriber }

    install_api.application:
        class: phpbb\core\Application
        arguments:
            - '@http_kernel'
            - '@service_container'

    phpbb.install.api.v1.controller.install:
        class: phpbb\install\api\v1\controller\install
```

---

## Nginx Changes

Add these three blocks to `docker/nginx/default.conf` **immediately before** the existing PHP regex location block (`location ~ ^(.+\.php)(/.*)? { ... }`).

```nginx
# ─── Forum REST API ──────────────────────────────────────────────────────────
location ^~ /api/ {
    fastcgi_pass  app:9000;
    fastcgi_param SCRIPT_FILENAME $document_root/api.php;
    fastcgi_param PHPBB_ROOT_PATH /var/www/phpbb/;
    include fastcgi_params;

    add_header 'Access-Control-Allow-Origin'  '*'                                             always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, PATCH, DELETE, OPTIONS'       always;
    add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With' always;
    if ($request_method = OPTIONS) { return 204; }
}

# ─── Admin REST API ───────────────────────────────────────────────────────────
location ^~ /adm/api/ {
    fastcgi_pass  app:9000;
    fastcgi_param SCRIPT_FILENAME $document_root/adm/api.php;
    fastcgi_param PHPBB_ROOT_PATH /var/www/phpbb/;
    include fastcgi_params;

    add_header 'Access-Control-Allow-Origin'  '*'                                             always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, PATCH, DELETE, OPTIONS'       always;
    add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With' always;
    if ($request_method = OPTIONS) { return 204; }
}

# ─── Installer REST API ───────────────────────────────────────────────────────
location ^~ /install/api/ {
    fastcgi_pass  app:9000;
    fastcgi_param SCRIPT_FILENAME $document_root/install/api.php;
    fastcgi_param PHPBB_ROOT_PATH /var/www/phpbb/;
    include fastcgi_params;

    add_header 'Access-Control-Allow-Origin'  '*'                                             always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, PATCH, DELETE, OPTIONS'       always;
    add_header 'Access-Control-Allow-Headers' 'Content-Type, Authorization, X-Requested-With' always;
    if ($request_method = OPTIONS) { return 204; }
}
```

**Why `^~`**: The prefix-and-stop modifier intercepts these URIs before the generic `~ \.php` regex runs. `SCRIPT_FILENAME` is hardcoded because `/api/v1/forums` is not a file on disk.

**What is unaffected**: Existing forum routes (`/viewtopic.php`, `/app.php`, `/adm/index.php`) are not matched by any of these blocks.

---

## Routing YAML

### `api.yml` (5 Phase-1 routes)

```yaml
# src/phpbb/common/config/default/routing/api.yml

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

**Register** by adding to `routing.yml`:
```yaml
phpbb_api_routing:
    resource: api.yml
    prefix:   /
```

### `admin_api.yml` (3 Phase-1 routes)

```yaml
# src/phpbb/common/config/default/routing/admin_api.yml

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
    requirements:
        id: \d+
```

**Register** by adding to `routing.yml`:
```yaml
phpbb_admin_api_routing:
    resource: admin_api.yml
    prefix:   /
```

### `install_api.yml` (3 Phase-1 routes)

```yaml
# src/phpbb/common/config/installer/routing/install_api.yml

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

**Register** by adding to `environment.yml`:
```yaml
core.install_api:
    resource: install_api.yml
```

---

## Mock Controller Examples

### `phpbb\api\v1\controller\health` — Forum Health

```php
<?php
/**
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbb\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

class health
{
	public function index(): JsonResponse
	{
		return new JsonResponse([
			'status'  => 'ok',
			'version' => '3.3.x',
		]);
	}
}
```

### `phpbb\api\v1\controller\forums` — Forum List (mock)

```php
<?php
/**
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbb\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class forums
{
	public function index(Request $request): JsonResponse
	{
		return new JsonResponse([
			'data'  => [
				['id' => 1, 'name' => 'General Discussion', 'description' => 'Mock forum'],
				['id' => 2, 'name' => 'Announcements',      'description' => 'Mock forum'],
			],
			'total' => 2,
		]);
	}
}
```

### `phpbb\api\v1\controller\users` — Current User (mock)

```php
<?php
/**
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbb\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

class users
{
	public function me(): JsonResponse
	{
		return new JsonResponse([
			'id'       => 1,
			'username' => 'Admin',
			'email'    => 'admin@example.com',
		]);
	}
}
```

### `phpbb\admin\api\v1\controller\health` — Admin Health

```php
<?php
/**
 * This file is part of the phpBB Forum Software package.
 *
 * @copyright (c) phpBB Limited <https://www.phpbb.com>
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace phpbb\admin\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

class health
{
	public function index(): JsonResponse
	{
		return new JsonResponse([
			'status' => 'ok',
			'admin'  => true,
		]);
	}
}
```

---

## Concrete Examples

### Example 1 — Forum API Health Check

**Given**: nginx is configured, `web/api.php` exists, DI container built with `services_api.yml`  
**When**: `curl http://localhost/api/v1/health`  
**Then**: HTTP 200, `Content-Type: application/json`, body `{"status":"ok","version":"3.3.x"}`

### Example 2 — Admin API Unauthorized Access

**Given**: A client with no phpBB session cookie  
**When**: `curl http://localhost/adm/api/v1/health`  
**Then**: HTTP 403, JSON body `{"error":"Forbidden","status":403}` — returned by the entry point auth gate before the kernel runs

### Example 3 — Installer API Before phpBB Is Installed

**Given**: phpBB database not yet created; `web/install/api.php` exists  
**When**: `curl http://localhost/install/api/v1/status`  
**Then**: HTTP 200, JSON body `{"installed":false,"step":null}` — installer container bootstraps without DB access

### Example 4 — Exception Handling (Route Not Found)

**Given**: Forum API is running  
**When**: `curl http://localhost/api/v1/nonexistent`  
**Then**: HTTP 404, JSON body `{"error":"No route found for \"GET /api/v1/nonexistent\"","status":404}` — `json_exception_subscriber` converts `NotFoundHttpException` to JSON, NOT an HTML page

---

## Out of Scope

The following are **not addressed** in this design:

- **OAuth 2.0 / JWT** — Phase 2 concern; token auth uses DB table only
- **OpenAPI / Swagger spec** — no spec generation in Phase 1
- **Rate limiting** — not part of this design
- **API versioning strategy beyond v1** — v2 is a new sub-namespace, no framework changes needed now
- **Real database queries in controllers** — all Phase 1 controllers return hardcoded mock data
- **phpBB extension packaging** — APIs live in core, not in the extension system
- **HTTPS / TLS termination** — handled by existing nginx/Docker setup
- **phpBB event hooks (extension points) in API controllers** — Phase 3 concern

---

## Success Criteria

1. `curl http://localhost/api/v1/health` returns HTTP 200 JSON without touching any existing phpBB page
2. `curl http://localhost/adm/api/v1/health` returns HTTP 403 with no valid admin session; HTTP 200 with one
3. `curl http://localhost/install/api/v1/status` returns HTTP 200 JSON even before phpBB installation
4. Any unmatched API route returns HTTP 404 as JSON, never as an HTML page
5. All existing phpBB forum pages (`/viewtopic.php`, `/app.php`, etc.) are unaffected
6. Adding a new route requires only YAML changes + cache clear; no PHP class changes are needed
7. `src/phpbb/core/Application.php` is ≤ 50 lines and has no phpBB-specific dependencies beyond the constructor types
