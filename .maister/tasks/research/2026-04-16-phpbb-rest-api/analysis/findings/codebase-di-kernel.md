# Codebase Findings: Symfony HttpKernel & DI Integration

**Source category**: `codebase-di-kernel`
**Gathered**: 2026-04-16

---

## 1. Container Bootstrap (`src/phpbb/common/common.php`)

The DI container is built in `common.php` and shared via globals:

```php
// common.php:63
$phpbb_container_builder = new \phpbb\di\container_builder($phpbb_root_path, $phpbb_filesystem_root);
$phpbb_container = $phpbb_container_builder->with_config($phpbb_config_php_file)->get_container();

// common.php:106
$phpbb_app_container = new \phpbb\Container($phpbb_container);
```

**Key facts**:
- `$phpbb_container` = the actual Symfony `ContainerInterface` (or cached `phpbb_cache_container`)
- `$phpbb_app_container` = thin `phpbb\Container` wrapper created **after** build to avoid circular DI issues
- The `phpbb_app_container` service (registered in `services.yml`) wraps `@service_container`

**Source**: `src/phpbb/common/common.php:63-106`

---

## 2. Entry Point: `web/app.php`

```php
// app.php:23
include(PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/common.php');

// app.php:26-30 — phpBB session started BEFORE Symfony kernel
$user->session_begin();
$auth->acl($user->data);
$user->setup('app');

// app.php:33-37 — Symfony kernel dispatches the request
$http_kernel = $phpbb_container->get('http_kernel');
$symfony_request = $phpbb_container->get('symfony_request');
$response = $http_kernel->handle($symfony_request);
$response->send();
$http_kernel->terminate($symfony_request, $response);
```

**Implication for REST API**: The existing `app.php` always starts a full phpBB session.
A dedicated `api.php` entrypoint that skips `$user->session_begin()` / `$user->setup()` can reuse
the same container and kernel without sessions.

**Source**: `web/app.php:23-37`

---

## 3. HTTP Services (`services_http.yml`)

**File**: `src/phpbb/common/config/default/container/services_http.yml`

| Service ID | Class | Notes |
|---|---|---|
| `http_kernel` | `Symfony\Component\HttpKernel\HttpKernel` | Args: `@dispatcher`, `@controller.resolver`, `@request_stack` |
| `symfony_request` | `phpbb\symfony_request` | Wraps phpBB `@request` into Symfony Request |
| `request_stack` | `Symfony\Component\HttpFoundation\RequestStack` | Standard Symfony |
| `request` | `phpbb\request\request` | phpBB input wrapper with super-global disabling |

**`phpbb\symfony_request`** (`src/phpbb/forums/symfony_request.php:23-44`):
- Extends `Symfony\Component\HttpFoundation\Request`
- Constructor reads `GET`, `POST`, `SERVER`, `FILES`, `COOKIE` from phpBB's request object
- Warning comment: does NOT escape input — raw values passthrough

**Source**: `src/phpbb/common/config/default/container/services_http.yml:1-23`

---

## 4. Routing Services (`services_routing.yml`)

**File**: `src/phpbb/common/config/default/container/services_routing.yml`

| Service ID | Class | Notes |
|---|---|---|
| `router` | `phpbb\routing\router` | Custom `RouterInterface`, reads YAML route files |
| `router.listener` | `Symfony\Component\HttpKernel\EventListener\RouterListener` | Tagged `kernel.event_subscriber` |
| `routing.helper` | `phpbb\routing\helper` | URL generation (used in `controller.helper`) |
| `routing.delegated_loader` | `Symfony\Component\Config\Loader\DelegatingLoader` | Delegates to YAML loader |
| `routing.resolver` | `phpbb\routing\loader_resolver` | Resolves loader from collection |
| `routing.loader.collection` | `phpbb\di\service_collection` | Tagged `routing.loader` |
| `routing.loader.yaml` | `Symfony\Component\Routing\Loader\YamlFileLoader` | Tagged `routing.loader` |
| `routing.chained_resources_locator` | `phpbb\routing\resources_locator\chained_resources_locator` | Chains locators |
| `routing.resources_locator.collection` | `phpbb\di\service_collection` | Tagged `routing.resources_locator` |
| `routing.resources_locator.default` | `phpbb\routing\resources_locator\default_resources_locator` | Reads routing YAML files, supports extensions |

**`phpbb\routing\router`** (`src/phpbb/forums/routing/router.php`):
- Implements `Symfony\Component\Routing\RouterInterface`
- Caches matcher/generator to PHP files in `cache_dir`
- Constructor: `($container, $resources_locator, $loader, $php_ext, $cache_dir)`

**Source**: `src/phpbb/common/config/default/container/services_routing.yml:1-75`

---

## 5. Route Definitions

**Master router file**: `src/phpbb/common/config/default/routing/routing.yml`
Resources are loaded relative to the environment via `src/phpbb/common/config/production/routing/environment.yml`:

```yaml
# environment.yml
core.default:
    resource: ../../default/routing/routing.yml
```

**`routing.yml`** imports sub-files with prefixes:

| Resource | Prefix | Example path |
|---|---|---|
| `cron.yml` | `/cron` | `/cron/{cron_type}` → `cron.controller:handle` |
| `feed.yml` | `/feed` | `/feed` → `phpbb.feed.controller:overall` |
| `help.yml` | `/help` | |
| `report.yml` | *(none)* | `/post/{id}/report`, `/pm/{id}/report` |
| `ucp.yml` | `/user` | `/user/delete_cookies`, `/user/reset_password` |

**Route pattern**: `service_id:method` string in `_controller` attribute.

**Source**: `src/phpbb/common/config/default/routing/routing.yml`, `src/phpbb/common/config/production/routing/environment.yml`

---

## 6. Controller Resolver (`src/phpbb/forums/controller/resolver.php`)

```php
class resolver implements ControllerResolverInterface
{
    public function __construct(ContainerInterface $container, $phpbb_root_path,
                                \phpbb\template\template $template = null)
```

- Parses `service:method` format from `_controller` attribute
- Fetches the service from the DI container by ID
- Template is **optional** (only needed for extension style-path auto-configuration)
- For extension controllers, auto-sets template style paths via `$controller_dir`

**Key insight**: The resolver itself does not require Twig — it's the controllers that call `controller.helper::render()` that
force Twig dependency. A JSON controller registered as a service can be resolved without any template dependency.

**Source**: `src/phpbb/forums/controller/resolver.php:23-100`

---

## 7. Event / Dispatcher Services (`services_event.yml`)

**File**: `src/phpbb/common/config/default/container/services_event.yml`

| Service ID | Class | Notes |
|---|---|---|
| `dispatcher` | `phpbb\event\dispatcher` | phpBB event dispatcher (wraps Symfony dispatcher) |
| `kernel_exception_subscriber` | `phpbb\event\kernel_exception_subscriber` | **Twig-dependent** (requires `@template`, `@language`, `@user`) |
| `kernel_terminate_subscriber` | `phpbb\event\kernel_terminate_subscriber` | Tagged `kernel.event_subscriber` |
| `symfony_response_listener` | `Symfony\Component\HttpKernel\EventListener\ResponseListener` | Sets charset on responses; tagged `kernel.event_subscriber` |

**`kernel_exception_subscriber`** (`src/phpbb/forums/event/kernel_exception_subscriber.php:24-90`):
- Listens on `KernelEvents::EXCEPTION`
- Can return `JsonResponse` (already imports it: line 18) for JSON requests
- BUT constructor requires `@template`, `@language`, `@user` — heavy Twig/session dependencies
- For API use, a new lightweight exception subscriber that always returns JSON would be needed

**Source**: `src/phpbb/common/config/default/container/services_event.yml:1-25`

---

## 8. Controller Helper (`src/phpbb/forums/controller/helper.php`)

```php
class helper
{
    public function __construct(auth $auth, cache_interface $cache, config $config, manager $cron_manager,
                                driver_interface $db, dispatcher $dispatcher, language $language,
                                request_interface $request, routing_helper $routing_helper,
                                symfony_request $symfony_request, template $template, user $user,
                                $root_path, $admin_path, $php_ext, $sql_explain = false)
```

- The `render()` method: calls `page_header()`, `page_footer()`, renders Twig template → `Response`
- Already imports `JsonResponse` (line 28) but no `json()` helper method exists yet
- **Tightly coupled to Twig and phpBB session** via `$template` and `$user` dependencies
- API controllers should NOT depend on `controller.helper`; they can return `JsonResponse` directly

**Source**: `src/phpbb/forums/controller/helper.php:1-200`

---

## 9. DI Container Builder (`src/phpbb/forums/di/container_builder.php`)

**Key methods**:

```php
// Container build (simplified)
public function get_container()  // line 135
{
    // 1. Try cache first (phpbb_cache_container PHP class)
    // 2. If stale: load extensions from DB, create ContainerBuilder, load YAML
    // 3. Add CompilerPasses: collection_pass, RegisterListenersPass (x2)
    // 4. Load {environment}/config.yml (→ services.yml → all sub-files)
    // 5. Compile & dump to cache
}
```

**Fluent builder API**:
- `->with_config($config_php_file)` — inject DB credentials
- `->with_environment('production')` — selects config directory
- `->with_extensions()` / `->without_extensions()` — include/exclude phpBB extensions
- `->with_cache()` / `->without_cache()` — container caching
- `->with_config_path($path)` — custom config directory
- `->with_custom_parameters([...])` — inject extra container parameters

**Core parameters injected**:
```php
// container_builder.php:599-612
'core.root_path'            => $this->phpbb_root_path,
'core.filesystem_root_path' => $this->filesystem_root_path,
'core.php_ext'              => $this->php_ext,
'core.environment'          => $this->get_environment(),
'core.debug'                => defined('DEBUG') ? DEBUG : false,
'core.cache_dir'            => $this->get_cache_dir(),
```

**Extension loading** (line 441-495):
- Builds a temporary container without cache/extensions to query `ext.manager`
- Loads `\{ext_namespace}\di\extension` (or `extension_base`) for each enabled extension
- Writes autoloader file to cache

**Container caching** (line 500-520):
- Dumps to `phpbb_cache_container` PHP class via `PhpDumper`
- Uses `ProxyDumper` (lazy proxy support)
- Cache key: MD5 of `phpbb_root_path + use_extensions + config_path`

**Source**: `src/phpbb/forums/di/container_builder.php:1-660`

---

## 10. Main Services in `services.yml`

**File**: `src/phpbb/common/config/default/container/services.yml`

Key services for REST API context:

| Service ID | Class | Notes |
|---|---|---|
| `phpbb_app_container` | `phpbb\Container` | Wraps `@service_container`; used in legacy globals |
| `controller.resolver` | `phpbb\controller\resolver` | Resolves `service:method` controllers |
| `controller.helper` | `phpbb\controller\helper` | **Twig + session dependent** |
| `config` | `phpbb\config\db` | phpBB config from DB |
| `cache` | `phpbb\cache\service` | Cache service |
| `cache.driver` | varies | File cache driver |
| `ext.manager` | `phpbb\extension\manager` | Extension loader |
| `file_locator` | `phpbb\routing\file_locator` | Used by YAML route loader |
| `dbal.conn` | `phpbb\db\driver\factory` | DB connection factory |
| `dbal.conn.driver` | synthetic | Raw DBAL driver injected after build |

**Source**: `src/phpbb/common/config/default/container/services.yml:1-150`

---

## 11. Analysis: What Can Be Reused for a Lightweight JSON API Kernel

### ✅ Fully Reusable (no changes needed)

| Service | Why |
|---|---|
| `http_kernel` | Standard Symfony kernel — works for any HTTP use case |
| `symfony_request` | Bridges phpBB input to Symfony Request |
| `request_stack` | Standard Symfony |
| `request` | phpBB request wrapper |
| `router` | Custom but standard `RouterInterface` — reads YAML routes |
| `router.listener` | Standard subscriber — already registered |
| `routing.loader.yaml` | Loads YAML route files |
| `routing.resources_locator.default` | Discovers route files |
| `dispatcher` | phpBB event dispatcher — used by kernel |
| `symfony_response_listener` | Already sets charset on responses |
| `dbal.conn` / `dbal.conn.driver` | DB access for API endpoints |
| `config` | phpBB config |
| `cache` / `cache.driver` | Caching |
| `controller.resolver` | Resolves `service:method` — template arg is optional |

### ⚠️ Reusable with Caution

| Service | Issue |
|---|---|
| `symfony_request` | Does NOT escape input — API must validate all input manually |
| `kernel_exception_subscriber` | Has Twig + session deps; will work but returns HTML error pages |

### ❌ Not Reusable / Must Replace

| Service | Why |
|---|---|
| `controller.helper` | Tightly coupled to Twig template render pipeline |
| `kernel_exception_subscriber` | Should be replaced with a JSON-only subscriber for API |

### 🆕 New Services Needed for REST API

1. **`api.kernel_exception_subscriber`** — listens `KernelEvents::EXCEPTION`, always returns `JsonResponse`
   (no `@template`, `@language`, `@user` deps)

2. **API controllers** — registered as DI services, return `JsonResponse` directly; no `controller.helper`

3. **New route resource** — `api_routing.yml` added to `routing.resources_locator.default` or registered
   via a new `routing.resources_locator` tagged service (for clean separation)

4. **Optional: `api.php` entrypoint** — skips phpBB session (`$user->session_begin()`) for stateless requests,
   or performs lightweight token-based auth instead

### Minimal Route Registration Pattern

```yaml
# src/phpbb/common/config/default/routing/api.yml
phpbb_api_v1_topics_list:
    path: /api/v1/topics
    methods: [GET]
    defaults: { _controller: phpbb.api.controller.topics:index }
```

Referenced from `routing.yml`:
```yaml
phpbb_api_routing:
    resource: api.yml
    prefix: /
```

---

## Summary

- phpBB uses **Symfony HttpKernel** with a custom event dispatcher (`phpbb\event\dispatcher`)
- The **DI container** is built via `phpbb\di\container_builder` which wraps Symfony's `ContainerBuilder`,
  supports YAML config, extension loading from DB, and PHP class caching
- **Controllers** are DI services resolved by `phpbb\controller\resolver` using `service_id:method` notation
- **Routes** are YAML files loaded by `routing.resources_locator.default`, discovered per-environment
- The existing `http_kernel`, `router`, `dispatcher`, `symfony_request` services are all
  **fully reusable for a REST API** — the only mandatory new component is a JSON-aware exception subscriber
- The httpBB session (`$user->session_begin()`) is started in `web/app.php` **before** `http_kernel->handle()`;
  a new `api.php` entrypoint can skip or replace this step for stateless token auth
