# Synthesis: phpBB REST API — Cross-Source Analysis

**Research Question**: How to build `phpbb\core` as a base for three independent Symfony applications
(`phpbb\api`, `phpbb\admin\api`, `phpbb\install\api`) that expose REST APIs.

**Sources Synthesized**: 5 findings files (entrypoints-bootstrap, di-kernel, installer, external-symfony-kernel, nginx)
**Date**: 2026-04-16

---

## 1. Executive Summary

The research confirms that phpBB already uses Symfony HttpKernel without FrameworkBundle — the
proposed architecture is a natural extension of existing patterns, not a re-architecture. Three
independent API entry points are achievable with a single shared `phpbb\core\Application` class
and per-API DI configuration. The largest divergence is the installer API, which cannot use
`common.php` and must mirror the `web/install.php` bootstrap exactly.

---

## 2. Cross-Source Validated Findings

### 2.1 Confirmed by Multiple Sources

| Finding | Sources | Confidence |
|---------|---------|-----------|
| phpBB uses raw `Symfony\HttpKernel` (no FrameworkBundle) | di-kernel §3, external-symfony-kernel §6 | **HIGH** |
| `RouterListener` is mandatory for any HttpKernel API | external-symfony-kernel §9, di-kernel §4 | **HIGH** |
| `kernel_exception_subscriber` (forums) has Twig deps → unusable for JSON API | di-kernel §7, external-symfony-kernel §8a | **HIGH** |
| `controller.resolver` works without template (`@template` is optional arg) | di-kernel §6, external-symfony-kernel §12 | **HIGH** |
| `common.php:30` redirects if `PHPBB_INSTALLED` not defined → blocks install API | installer §1, entrypoints §6 | **HIGH** |
| Installer uses a completely separate DI container (`with_environment('installer')`) | installer §2, installer §3 | **HIGH** |
| Nginx needs `^~` prefix blocks before the PHP regex location | nginx §4, nginx §6 | **HIGH** |
| API controllers can return `JsonResponse` directly; no `controller.helper` needed | di-kernel §8, di-kernel §11, external-symfony-kernel §14 | **HIGH** |
| `session_begin()` must precede `auth->acl()` | entrypoints §3, entrypoints §5 | **HIGH** |
| Composition (wrap HttpKernel) preferred over extension | external-symfony-kernel §17 | **HIGH** |

### 2.2 Contradictions / Resolved Conflicts

| Conflict | Resolution |
|----------|-----------|
| `web/adm/index.php` does NOT use Symfony HttpKernel (legacy ACP module system), but the new `web/adm/api.php` should. | Create `web/adm/api.php` as a NEW entry point that uses the HttpKernel after `common.php` bootstrap — do not modify `adm/index.php`. The two coexist because nginx routes them separately by URI prefix. |
| "All APIs share the same DI container" vs "installer uses separate container" | True split: forum API + admin API share the forum container (built from `common.php`). Installer API uses the installer container (built from `startup.php`). Three entry points, two distinct containers. |
| `$phpbb_root_path` convention differs (relative string) from `PHPBB_ROOT_PATH` (absolute constant used in installer) | Use `PHPBB_ROOT_PATH` (installer convention) only in `web/install/api.php`. Forum and admin API use `PHPBB_FILESYSTEM_ROOT` (absolute) + `$phpbb_root_path` (relative), matching `web/app.php`. |

---

## 3. Patterns Across All Findings

### Pattern 1: All Three APIs Need `RouterListener`

All kernel-based HTTP dispatch requires `RouterListener` tagged as `kernel.event_subscriber`.
It is the only mechanism that populates `_controller` on the request attributes. Without it,
every request raises `NotFoundHttpException` before the controller is resolved.

**Evidence**: external-symfony-kernel §9 (technical details); di-kernel §4 (already present in services_routing.yml)

**Implication**: All three APIs can reuse the existing `router.listener` service from the forum
container. The installer API gets its own `router.listener` from the installer container, which
already wires it.

---

### Pattern 2: All Three APIs Must Replace/Supplement the Exception Subscriber

The existing `kernel_exception_subscriber` in the forums container depends on `@template`,
`@language`, `@user` and returns HTML error pages. Every JSON API needs a custom subscriber
that returns `JsonResponse` for all exceptions.

**Evidence**: di-kernel §7 (dependency analysis); external-symfony-kernel §8a, §14 (code pattern)

**Implication**: A `phpbb\api\event\json_exception_subscriber` must be registered with greater
priority (or the forums subscriber must be disabled per-context) for each API context. The simplest
approach: register the JSON subscriber at priority `10` on `kernel.exception` while the forums
subscriber runs at `0` — the JSON subscriber fires first and stops propagation.

---

### Pattern 3: Bootstrap Splits at the Installer Boundary

Forum API and Admin API: `common.php` → forum container  
Installer API: `startup.php` → installer container (completely separate)

This split is fundamental, not optional. It arises from:
- `common.php:30` calling `checkInstallation()` which `exit`s if `PHPBB_INSTALLED` is absent
- Installer container intentionally excludes DB services (DB may not exist)
- phpBB's own installer already establishes this pattern (`web/install.php`)

**Evidence**: installer §§1–6 (detailed analysis including option evaluation)

---

### Pattern 4: Controller Resolution Pattern is Universal

All three APIs can use phpBB's `phpbb\controller\resolver` (or a standard Symfony one) with
the `service_id:method` string format in `_controller` route attribute. The `@template`
constructor argument in `phpbb\controller\resolver` is optional; JSON controllers do not need it.

**Evidence**: di-kernel §6; external-symfony-kernel §12 (class design references resolver)

---

### Pattern 5: Every Entry Point Sets Two Path Variables

All phpBB entry points (without exception) set:
- `PHPBB_FILESYSTEM_ROOT` — absolute path to repository root + trailing `/`
- `$phpbb_root_path` — relative path from entry point to the same root

The values differ by depth (e.g., `web/api.php` uses `'./'`; `web/adm/api.php` uses `'../../'`).
This pair is consumed throughout `common.php` and the core function files.

**Evidence**: entrypoints §2 (define pattern), entrypoints §5 (admin path differences), nginx §2

---

### Pattern 6: Nginx Hardcoded SCRIPT_FILENAME for API Entry Points

All three API nginx blocks must hardcode `SCRIPT_FILENAME` to their entry PHP file. The `try_files`
mechanism used for forum routes is wrong for APIs (URIs don't map to files). The `^~` location
modifier ensures these blocks intercept their prefix BEFORE the generic PHP regex location runs.

**Evidence**: nginx §4 (strategy analysis), nginx §5 (file path table)

---

## 4. Dependency Map

```
web/api.php
    └─ include common.php
        ├─ startup.php (autoload, PHP env)
        ├─ config_php_file (reads config.php → DB creds)
        ├─ checkInstallation() [requires PHPBB_INSTALLED ✅ after install]
        ├─ container_builder → $phpbb_container (forum DI container)
        └─ register_compatibility_globals() → $user, $auth, $db, etc.
    ├─ $user->session_begin()
    ├─ $auth->acl($user->data)
    ├─ $user->setup('api')
    └─ $phpbb_container->get('api.application')->run()
            ├─ http_kernel (wraps dispatcher + controller.resolver + request_stack)
            │       ├─ router.listener (kernel.event_subscriber, sets _controller)
            │       ├─ api.exception_listener (kernel.event_subscriber, JSON errors)
            │       ├─ symfony_response_listener (kernel.event_subscriber, prepare())
            │       └─ kernel_terminate_subscriber (garbage_collection, exit_handler)
            └─ symfony_request (wraps phpBB request with GET/POST/SERVER data)

web/adm/api.php
    └─ [same as above + ADMIN_START, NEED_SID, IN_ADMIN constants]
    └─ auth gate: !$auth->acl_get('a_') → JsonResponse 403
    └─ $phpbb_container->get('admin_api.application')->run()

web/install/api.php
    └─ define IN_INSTALL + PHPBB_ROOT_PATH
    └─ require startup.php (installer bootstrap, NO common.php)
        ├─ container_builder with_environment('installer')->without_extensions()
        └─ $phpbb_installer_container (no DB, no session, no user services)
    └─ $phpbb_installer_container->get('install_api.application')->run()
```

---

## 5. Critical Relationships

| Component A | Relationship | Component B |
|-------------|-------------|-------------|
| `phpbb\core\Application` | wraps | `Symfony\HttpKernel` |
| `phpbb\api\Application` | reuses same class as | `phpbb\core\Application` (different DI config) |
| Forum API container | is SAME as | existing forum DI container (extended via new YAML) |
| Installer API container | is DIFFERENT from | forum container (built independently) |
| `api.exception_listener` | replaces | `kernel_exception_subscriber` for JSON responses |
| `RouterListener` | depends on | `phpbb\routing\router` (already wired in services_routing.yml) |
| `web/adm/api.php` | coexists with | `web/adm/index.php` (different URI → different nginx route) |

---

## 6. Key Insights

### Insight 1: The Architecture Requires NO New Framework
phpBB already has all the components: HttpKernel, Router, DI container, event dispatcher. The
work is configuration (YAML) and wiring, not framework-level code. The `phpbb\core\Application`
class is ~40 lines.

### Insight 2: Forum and Admin APIs Share ONE Container Build
`common.php` builds a single forum container. Both `web/api.php` and `web/adm/api.php` include
`common.php` and share this container. The separation is purely in routes and auth checks,
not in container instances. This means new services for both APIs can be added to the same
YAML files.

### Insight 3: Install API Is Architecturally Isolated
`web/install/api.php` is in a completely different world. It shares no container, no services,
and no bootstrap path with the forum APIs. This is an architectural feature, not a bug: the
installer must work before the database exists.

### Insight 4: Session Handling Is the Main Design Decision for Forum API
`session_begin()` is called in the `web/app.php` entry point BEFORE the kernel runs. For a
stateless REST API, this is wasteful. However, skipping it means `$auth->acl()` cannot work
(it needs `$user->data`). The decision is: stateful session-based auth (keep `session_begin()`)
or stateless token auth (replace `session_begin()` + `acl()` with a custom auth listener).
Phase 1 mocks should use session auth for simplicity.

### Insight 5: JSON Exception Subscriber Priority Requires Care
If both `kernel_exception_subscriber` (forum, priority 0) and `api.exception_listener` (priority 10)
are active in the same container, the API subscriber fires first and sets a response, stopping
propagation. This is the correct behavior. BUT the api.exception_listener must call
`$event->stopPropagation()` or Symfony will continue to the next subscriber.

---

## 7. Gaps and Uncertainties

| Gap | Impact | Mitigation |
|-----|--------|------------|
| `session_begin()` cost for stateless JSON API | Performance: session file I/O on every request | Phase 2: implement JWT auth listener that skips `session_begin()` |
| How to add API routes to installer's `resources_locator` | Installer routing is less documented | Follow the `routing.resources_locator.default` pattern; inspect `installer/routing/` YAML pattern |
| `phpbb\routing\router` caches to `cache/production/` — invalidation on new routes | Route cache won't update until cleared | Document "clear cache" as required step after adding routes |
| `argument_resolver` not explicitly wired in `services_http.yml` (Symfony 3.4 deprecation warning) | Non-fatal deprecation in logs | Add explicit `@argument_resolver` to `http_kernel` service args |
| Admin API path: `web/adm/api.php` — does `PHPBB_FILESYSTEM_ROOT` need to be `__DIR__ . '/../../'`? | Bootstrap failure if wrong | Confirmed by entrypoints §5: admin path is two levels deep from `web/` root |
| CORS `Allow-Origin: *` in nginx — acceptable for development only | Security gap in production | Add config variable or document "must restrict for production" |
| Whether `kernel_terminate_subscriber` (calls `garbage_collection`) fires correctly after API requests | Potential resource leaks if not | Confirmed it's tagged `kernel.event_subscriber` on `kernel.terminate` — it will fire |

---

## 8. Architecture Implications

### Must Build (new code)
1. `src/phpbb/core/Application.php` — 40-line wrapper implementing `HttpKernelInterface + TerminableInterface`
2. `src/phpbb/api/event/json_exception_subscriber.php` — JSON error responses
3. `web/api.php`, `web/adm/api.php`, `web/install/api.php` — entry points
4. Routing YAML files per API
5. DI service YAML files per API

### Must Configure (YAML changes)
1. `docker/nginx/default.conf` — 3 new `location ^~` blocks
2. `src/phpbb/common/config/default/container/services.yml` — import new `services_api.yml`
3. `src/phpbb/common/config/default/routing/routing.yml` — import new `api.yml`
4. `src/phpbb/common/config/installer/container/services.yml` — import `services_install_api.yml`

### Must NOT Change
- `src/phpbb/common/common.php` — the shared bootstrap is correct; do not modify it
- `web/app.php`, `web/adm/index.php`, `web/install.php` — no modifications to existing entry points
- `src/phpbb/common/config/default/container/services_http.yml` — reuse as-is
- `src/phpbb/common/config/default/container/services_routing.yml` — reuse as-is

---

## 9. Conclusions

**Primary**: phpBB's architecture already supports the proposed design. The forum and admin REST
APIs require only new DI configuration YAML + new PHP controllers + new entry points. The installer
API requires mirroring the existing `web/install.php` bootstrap exactly.

**Secondary**: The `phpbb\core\Application` class is the only genuinely new PHP class needed at
the infrastructure level. Everything else is either YAML configuration or controller code.

**Confidence**: HIGH across all findings. The only medium-confidence area is installer API
routing integration (less documented, but the pattern is clear from installer source code).
