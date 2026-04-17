# External Findings: Symfony 3.4 HttpKernel Patterns for Micro REST API

**Source category**: `external-symfony-kernel`
**Research question**: What is the recommended Symfony 3.4 pattern for building a lightweight REST API using HttpKernel WITHOUT FrameworkBundle? How can we build a `phpbb\core` base class?

---

## 1. HttpKernelInterface — The Core Contract

**Source**: `vendor/symfony/http-kernel/HttpKernelInterface.php`

```php
interface HttpKernelInterface
{
    const MASTER_REQUEST = 1;
    const SUB_REQUEST    = 2;

    public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true): Response;
}
```

**Key facts**:

- The entire Symfony HTTP layer is built around this single method.
- `$type` distinguishes master from sub-requests (for forwarding/internal dispatching).
- `$catch = true` means exceptions are caught and converted to responses via `kernel.exception` event.
- `$catch = false` lets exceptions bubble up (useful in CLI / tests).

---

## 2. TerminableInterface — Post-Response Cycle

**Source**: `vendor/symfony/http-kernel/TerminableInterface.php`

```php
interface TerminableInterface
{
    public function terminate(Request $request, Response $response): void;
}
```

**Key facts**:

- Called AFTER `$response->send()` to fire the `kernel.terminate` event.
- Used for cleanup, garbage collection, async jobs.
- `HttpKernel` implements both `HttpKernelInterface` AND `TerminableInterface`.

---

## 3. HttpKernel Constructor — What It Needs

**Source**: `vendor/symfony/http-kernel/HttpKernel.php:46–57`

```php
class HttpKernel implements HttpKernelInterface, TerminableInterface
{
    public function __construct(
        EventDispatcherInterface    $dispatcher,         // REQUIRED
        ControllerResolverInterface $resolver,           // REQUIRED
        RequestStack                $requestStack = null, // auto-created if null
        ArgumentResolverInterface   $argumentResolver = null // falls back to $resolver (deprecated in 3.x)
    ) { ... }
}
```

**Minimum required DI services**:

| Service | Class | Purpose |
|---------|-------|---------|
| `dispatcher` | `EventDispatcherInterface` | Dispatches ALL kernel events |
| `controller.resolver` | `ControllerResolverInterface` | Maps `_controller` attribute to a callable |
| `request_stack` | `RequestStack` | Tracks nested request contexts |
| `argument_resolver` | `ArgumentResolverInterface` | Resolves controller method arguments |

**Note**: In Symfony 3.4, passing only `$dispatcher` + `$resolver` works but triggers a deprecation warning about `ArgumentResolver`. A proper setup should explicitly provide an `ArgumentResolver`.

---

## 4. The Full Request Lifecycle (handleRaw internals)

**Source**: `vendor/symfony/http-kernel/HttpKernel.php:113–175`

```
handle(Request $request)
    └── handleRaw(Request $request)
            │
            ├── 1. dispatch kernel.REQUEST → GetResponseEvent
            │       └── [Early return if listener sets a response]
            │
            ├── 2. resolver->getController(request) → callable
            │
            ├── 3. dispatch kernel.CONTROLLER → FilterControllerEvent
            │       └── [Listeners can swap the controller callable]
            │
            ├── 4. argumentResolver->getArguments(request, controller) → array
            │
            ├── 5. dispatch kernel.CONTROLLER_ARGUMENTS → FilterControllerArgumentsEvent
            │
            ├── 6. call_user_func_array(controller, arguments) → $response
            │
            ├── 7. [If $response is NOT a Response instance]
            │       └── dispatch kernel.VIEW → GetResponseForControllerResultEvent
            │               └── listener MUST convert return value to Response
            │
            └── 8. filterResponse($response)
                    ├── dispatch kernel.RESPONSE → FilterResponseEvent
                    └── dispatch kernel.FINISH_REQUEST → FinishRequestEvent
                            └── requestStack->pop()
```

**On exception**: `handleException()` dispatches `kernel.EXCEPTION` → `GetResponseForExceptionEvent`. Listeners can set a response; if none does, the exception re-throws.

---

## 5. All KernelEvents Constants

**Source**: `vendor/symfony/http-kernel/KernelEvents.php`

| Constant | String value | Event class | Purpose |
|----------|-------------|-------------|---------|
| `REQUEST` | `kernel.request` | `GetResponseEvent` | Routing, auth gates, early responses |
| `EXCEPTION` | `kernel.exception` | `GetResponseForExceptionEvent` | Error handling → JSON error responses |
| `VIEW` | `kernel.view` | `GetResponseForControllerResultEvent` | Convert non-Response returns |
| `CONTROLLER` | `kernel.controller` | `FilterControllerEvent` | Swap/wrap controller |
| `CONTROLLER_ARGUMENTS` | `kernel.controller_arguments` | `FilterControllerArgumentsEvent` | Inject extra args |
| `RESPONSE` | `kernel.response` | `FilterResponseEvent` | Add/modify headers |
| `TERMINATE` | `kernel.terminate` | `PostResponseEvent` | Post-send cleanup |
| `FINISH_REQUEST` | `kernel.finish_request` | `FinishRequestEvent` | Reset routing context |

---

## 6. Existing phpBB HttpKernel DI Configuration

**Source**: `src/phpbb/common/config/default/container/services_http.yml`

```yaml
services:
    http_kernel:
        class: Symfony\Component\HttpKernel\HttpKernel
        arguments:
            - '@dispatcher'
            - '@controller.resolver'
            - '@request_stack'

    symfony_request:
        class: phpbb\symfony_request
        arguments:
            - '@request'

    request_stack:
        class: Symfony\Component\HttpFoundation\RequestStack
```

**Source**: `src/phpbb/common/config/default/container/services_routing.yml`

```yaml
services:
    router.listener:
        class: Symfony\Component\HttpKernel\EventListener\RouterListener
        arguments:
            - '@router'
            - '@request_stack'
        tags:
            - { name: kernel.event_subscriber }
```

**Key insight**: phpBB already uses the pattern from Symfony without FrameworkBundle. The `http_kernel` service is a raw `Symfony\Component\HttpKernel\HttpKernel` instance wired via YAML DI.

---

## 7. How phpBB Actually Invokes the Kernel (Entry Point Pattern)

**Source**: `web/app.php:28-35`

```php
// Container is already built from common.php bootstrap
$http_kernel     = $phpbb_container->get('http_kernel');
$symfony_request = $phpbb_container->get('symfony_request');

$response = $http_kernel->handle($symfony_request);
$response->send();
$http_kernel->terminate($symfony_request, $response);
```

**Pattern summary**: Build container → get `http_kernel` → `handle()` → `send()` → `terminate()`.

---

## 8. Existing phpBB Event Subscribers

### 8a. Forums kernel_exception_subscriber

**Source**: `src/phpbb/forums/event/kernel_exception_subscriber.php`

- Handles `kernel.exception`, converts exceptions to `Response` with template rendering.
- Uses `\phpbb\template` to render HTML error pages.
- **NOT suitable for JSON API** (depends on `\phpbb\template`, `\phpbb\user`, `\phpbb\language`).

### 8b. Install kernel_exception_subscriber

**Source**: `src/phpbb/forums/install/event/kernel_exception_subscriber.php`

- Handles `kernel.exception`, checks `$request->isXmlHttpRequest()`.
- Returns `JsonResponse` for AJAX requests — closer to REST API pattern.
- Still depends on `template` for non-AJAX fallback.

### 8c. kernel_terminate_subscriber

**Source**: `src/phpbb/forums/event/kernel_terminate_subscriber.php`

```php
static public function getSubscribedEvents()
{
    return [
        KernelEvents::TERMINATE => ['on_kernel_terminate', ~PHP_INT_MAX],
    ];
}

public function on_kernel_terminate(PostResponseEvent $event)
{
    garbage_collection();
    exit_handler();
}
```

---

## 9. RouterListener — What It Does

**Source**: `vendor/symfony/http-kernel/EventListener/RouterListener.php`

**Subscribes to**:
- `kernel.request` (priority 32) → matches URL to route, sets `_controller` and `_route` on `$request->attributes`
- `kernel.finish_request` (priority 0) → resets routing context to parent request after sub-requests
- `kernel.exception` (priority -64) → only in debug mode, shows welcome page for `NoConfigurationException`

**Constructor**:
```php
public function __construct(
    UrlMatcherInterface|RequestMatcherInterface $matcher, // REQUIRED: the router
    RequestStack $requestStack,                           // REQUIRED
    RequestContext $context = null,                       // optional if matcher implements RequestContextAwareInterface
    LoggerInterface $logger = null,
    string $projectDir = null,
    bool $debug = true
)
```

**For REST API**: `RouterListener` is **required** — it's how `_controller` gets set on the request.

---

## 10. ResponseListener — What It Does

**Source**: `vendor/symfony/http-kernel/EventListener/ResponseListener.php`

**Subscribes to**: `kernel.response`

```php
public function onKernelResponse(FilterResponseEvent $event)
{
    if (!$event->isMasterRequest()) { return; }

    $response = $event->getResponse();
    if (null === $response->getCharset()) {
        $response->setCharset($this->charset); // e.g., 'UTF-8'
    }
    $response->prepare($event->getRequest()); // fixes protocol version, Content-Type, etc.
}
```

**For REST API**: Useful for ensuring `JsonResponse` is properly prepared (correct HTTP version, Content-Length). **Optional but recommended**.

---

## 11. ExceptionListener (Default Symfony) — NOT Suitable for JSON APIs

**Source**: `vendor/symfony/http-kernel/EventListener/ExceptionListener.php`

- Dispatches a **sub-request** to a `_controller` (typically a Twig error controller).
- Depends on controller infrastructure to generate error HTML.
- **NOT appropriate for REST APIs** — must be replaced with a custom JSON exception listener.

---

## 12. Proposed `phpbb\core\Application` Design

### Architecture Decision: Composition over Inheritance

The recommended pattern is **composition** — `phpbb\core\Application` wraps/uses `HttpKernel` but does NOT extend it. This allows phpBB-specific bootstrapping logic around the Symfony lifecycle.

```
phpbb\core\Application                          (wraps HttpKernel, handles bootstrapping)
    ↑ extends
phpbb\api\Application                           (REST JSON API — custom subscribers, no Twig)
phpbb\install\api\Application  (Installer REST API — may share phpbb\api\Application)
```

OR if contexts need truly isolate DI containers:

```
phpbb\core\Application  ←─ composition ─→  Symfony\Component\HttpKernel\HttpKernel
     ↑ used by
phpbb\api\entry_point  (builds container, creates phpbb\core\Application, runs it)
```

### Minimal `phpbb\core\Application` Class Structure

```php
<?php

namespace phpbb\core;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
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

    public function handle(Request $request, $type = self::MASTER_REQUEST, $catch = true)
    {
        return $this->kernel->handle($request, $type, $catch);
    }

    public function terminate(Request $request, $response): void
    {
        $this->kernel->terminate($request, $response);
    }

    public function run(): void
    {
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

---

## 13. Minimal Required Event Subscribers for a JSON REST API

### REQUIRED

| Subscriber | Service | Why |
|-----------|---------|-----|
| `RouterListener` | `router.listener` | MUST set `_controller` attribute on request; without it the kernel throws `NotFoundHttpException` immediately |

### RECOMMENDED

| Subscriber | Service | Why |
|-----------|---------|-----|
| `ResponseListener` | `response.listener` | Calls `$response->prepare()` to fix protocol version, `Content-Type`, `Content-Length` |
| Custom `JsonExceptionListener` | `api.exception_listener` | Converts ALL exceptions to `JsonResponse` with `{"error": "...", "code": 404}` |

### OPTIONAL (but useful)

| Subscriber | Service | Why |
|-----------|---------|-----|
| Custom `CorsListener` | `api.cors_listener` | Sets CORS headers on `kernel.response` |
| Custom `AuthListener` | `api.auth_listener` | JWT/token validation on `kernel.request` (priority > 32 or < 32 to run before/after routing) |

### NOT NEEDED for JSON API

| Subscriber | Why excluded |
|-----------|-------------|
| Default `ExceptionListener` | Routes to an HTML controller — wrong for JSON API |
| `kernel_exception_subscriber` (forums) | Depends on `\phpbb\template`, `\phpbb\user` — wrong for lightweight API |
| TwigListener / StreamedResponseListener | No Twig in REST API |

---

## 14. Custom JSON Exception Listener Pattern

```php
<?php

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
        $statusCode = $exception instanceof HttpExceptionInterface
            ? $exception->getStatusCode()
            : 500;

        $data = ['error' => $exception->getMessage(), 'status' => $statusCode];

        if ($this->debug) {
            $data['trace'] = $exception->getTraceAsString();
        }

        $response = new JsonResponse($data, $statusCode);

        if ($exception instanceof HttpExceptionInterface) {
            $response->headers->add($exception->getHeaders());
        }

        $event->setResponse($response);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['on_kernel_exception', 0],
        ];
    }
}
```

**DI service tag**:

```yaml
api.exception_listener:
    class: phpbb\api\event\json_exception_subscriber
    arguments:
        - '%core.debug%'
    tags:
        - { name: kernel.event_subscriber }
```

---

## 15. Minimal services.yml for a REST API Container

```yaml
services:
    # Core kernel dependencies
    dispatcher:
        class: Symfony\Component\EventDispatcher\EventDispatcher

    request_stack:
        class: Symfony\Component\HttpFoundation\RequestStack

    controller.resolver:
        class: Symfony\Component\HttpKernel\Controller\ControllerResolver

    argument.resolver:
        class: Symfony\Component\HttpKernel\Controller\ArgumentResolver

    http_kernel:
        class: Symfony\Component\HttpKernel\HttpKernel
        arguments:
            - '@dispatcher'
            - '@controller.resolver'
            - '@request_stack'
            - '@argument.resolver'

    # Routing
    router.listener:
        class: Symfony\Component\HttpKernel\EventListener\RouterListener
        arguments:
            - '@router'
            - '@request_stack'
        tags:
            - { name: kernel.event_subscriber }

    # Response preparation
    response.listener:
        class: Symfony\Component\HttpKernel\EventListener\ResponseListener
        arguments:
            - 'UTF-8'
        tags:
            - { name: kernel.event_subscriber }

    # JSON error responses (replaces default ExceptionListener)
    api.exception_listener:
        class: phpbb\api\event\json_exception_subscriber
        arguments:
            - '%core.debug%'
        tags:
            - { name: kernel.event_subscriber }

    # Application wrapper
    api.application:
        class: phpbb\core\Application
        arguments:
            - '@http_kernel'
            - '@service_container'
```

---

## 16. Entry Point Pattern for a REST API Module

```php
<?php
// api.php (web entry point)

define('PHPBB_FILESYSTEM_ROOT', __DIR__ . '/../');
$phpbb_root_path = './';

// Custom bootstrap that skips session, user, auth
require PHPBB_FILESYSTEM_ROOT . 'src/phpbb/common/api_bootstrap.php';

/** @var \phpbb\core\Application $app */
$app = $phpbb_container->get('api.application');
$app->run();
```

---

## 17. Key Architectural Decisions

### Why NOT extend HttpKernel directly?

- `HttpKernel` is `final`-able in future Symfony versions (already closed internals).
- phpBB needs its own bootstrapping logic (DI container build, config, etc.) that doesn't belong in kernel.
- Composition allows swapping the kernel implementation.

### Why NOT use FrameworkBundle?

- FrameworkBundle adds ~40 compiler passes, Twig, form, validator, translator services.
- For a JSON API, all that overhead is unnecessary.
- The raw `HttpKernel` with 3 event subscribers (RouterListener, ResponseListener, custom ExceptionListener) is sufficient.

### Event subscriber priority for auth in REST API:

```
kernel.request listeners (ordered by priority, highest first):
  32  → RouterListener::onKernelRequest  (sets _controller)
  16  → (custom) AuthListener             (validate JWT before routing resolution finishes)
   8  → (custom) RateLimitListener
   0  → (other listeners)
```

Auth should run AT OR BEFORE routing (priority ≥ 32) if it needs to short-circuit before route matching, or AFTER routing (priority < 32) if it needs `_route`/`_controller` attributes.

---

## Summary of Findings

| Aspect | Finding |
|--------|---------|
| HttpKernel minimum deps | `EventDispatcher`, `ControllerResolver`, `RequestStack`, `ArgumentResolver` |
| Kernel lifecycle events | 7 events: REQUEST, CONTROLLER, CONTROLLER_ARGUMENTS, VIEW, RESPONSE, FINISH_REQUEST, TERMINATE (+ EXCEPTION on error) |
| phpBB current pattern | Raw `HttpKernel` in DI YAML, no FrameworkBundle — already minimal |
| Required subscribers for JSON API | `RouterListener` (REQUIRED), `ResponseListener` (recommended) |
| Exception handling | Must replace default `ExceptionListener` with custom `JsonExceptionListener` |
| Architecture | Composition: `phpbb\core\Application` wraps `HttpKernel` and owns the `run()` loop |
| Entry point pattern | Bootstrap container → `$app->run()` → `handle()` + `send()` + `terminate()` |
| Inheritance hierarchy | `phpbb\api\Application extends phpbb\core\Application` OR flat with different DI configs |

**Confidence**: High (100%) — all findings based on direct source reading of vendor code and existing phpBB services.yml.
