# Plan: phpBB4 REST API Boilerplate

**Date**: 2026-04-21  
**Scope**: PHP boilerplate in `src/phpbb/` — Symfony 8.x HTTP fundament + JWT AuthSubscriber + mock kontrolery z kluczowych grup openapi.yaml  
**Target dir**: `src/phpbb/` (nowy katalog, niezależny od `src/phpbb3/`)  
**Framework**: Symfony 8.x + PHP 8.2+ + FrameworkBundle + PHP Attributes routing  
**Auth**: JWT Bearer tokeny zgodnie z `2026-04-19-auth-unified-service` research  
**Research sources**:
- `.maister/tasks/research/2026-04-16-phpbb-rest-api/` — architektura HTTP, Application wrapper
- `.maister/tasks/research/2026-04-19-auth-unified-service/` — JWT spec, AuthenticationSubscriber
- `.maister/tasks/research/2026-04-20-rest-api-spec/outputs/openapi.yaml` — kontrakt API

---

## Context

`src/phpbb3/` — istniejący kod phpBB3 (Symfony 3.4, PHP 7.2, legacy). Pozostaje jako **referencja**, nie jako zależność.  
`src/phpbb/` — nowy phpBB4 codebase. **Czysta karta**. Żaden import z `src/phpbb3/`.  
`composer.json` — zaktualizowany do Symfony 8.x + PHP 8.2+. phpBB3 przestaje być uruchamialne.

---

## Applicable Standards

### `standards/global/STANDARDS.md`
- `declare(strict_types=1)` na początku każdego pliku
- Nagłówek copyright phpBB w każdym pliku PHP
- Brak zamykającego `?>` tagu
- Allman braces (klamra na nowej linii)
- Indentacja tabulatorami

### `standards/backend/STANDARDS.md`
- PHP 8.2+: `readonly` constructor promotion, `match`, named args, nullsafe `?->`
- Constructor injection — wyłącznie; żadnych `global`
- PSR-4 pod `phpbb\` namespace mirroring `src/phpbb/`
- `PascalCase` klasy, `camelCase` metody
- Żadnych imports z `src/phpbb3/` ani `vendor/phpbb3/`

### `standards/backend/REST_API.md`
- Wszystkie trasy prefixem `/api/v1/`
- Nazwy routów: `api_v1_<resource>_<action>`
- Zawsze `JsonResponse` — żadnych `echo`/`header()`
- Success shape: `{ "data": ... }` z opcjonalnym `{ "meta": ... }`
- Error shape: `{ "error": "message", "status": N }`
- Validation error (422): `{ "errors": [{ "field": ..., "message": ... }] }`
- JWT claims: `sub`, `gen`, `pv`, `utype`, `flags`, `kid`, `jti` (wg auth-unified HLD)
- `_api_token` request attribute — źródło tożsamości w kontrolerach

### `standards/testing/STANDARDS.md`
- PHPUnit 10+: atrybuty PHP (`#[Test]`, `#[DataProvider]`)
- Izolowane unit testy, bez DB
- `PascalCase` klasy testowe, `camelCase` metody

---

## File Inventory

### Zmodyfikowane pliki (4)

| Plik | Zmiana |
|------|--------|
| `composer.json` | PHP `^8.2`, Symfony `^8.0`, PHPUnit `^10.0`, PSR-4 `phpbb\` |
| `docker/nginx/nginx.conf` | Blok `location ^~ /api/` → `web/api.php` |
| `phpunit.xml` | PHPUnit 10+ config, test suite dla `tests/phpbb/` |
| `.env` | Werktor JWT secrets: `PHPBB_JWT_SECRET`, `PHPBB_APP_ENV` |

### Nowe pliki (22)

**HTTP Kernel:**
| Plik | Opis |
|------|------|
| `src/phpbb/Kernel.php` | Symfony 8.x Kernel, MicroKernelTrait, config z `src/phpbb/config/` |
| `web/api.php` | Symfony 8.x entry point — bootstrap Kernel, handle, send, terminate |

**Config:**
| Plik | Opis |
|------|------|
| `src/phpbb/config/packages/framework.yaml` | FrameworkBundle: session off, router UTF-8, error handling |
| `src/phpbb/config/routes.yaml` | Import kontrolerów z atrybutami, prefix `/api/v1` |
| `src/phpbb/config/services.yaml` | Defaults autowire+autoconfigure, tagi kontrolerów i subskrybentów |

**Event Subscribers:**
| Plik | Opis |
|------|------|
| `src/phpbb/api/EventSubscriber/ExceptionSubscriber.php` | Priority 10 na `kernel.exception` — JSON error response, path guard `/api/` |
| `src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php` | Priority 16 na `kernel.request` — JWT Bearer validate, set `_api_token`, public path whitelist |

**Controllers (mock data):**
| Plik | Opis |
|------|------|
| `src/phpbb/api/Controller/HealthController.php` | `GET /health` → `{"status":"ok"}` — public, no auth |
| `src/phpbb/api/Controller/AuthController.php` | `POST /auth/login`, `POST /auth/logout`, `POST /auth/refresh` |
| `src/phpbb/api/Controller/ForumsController.php` | `GET /forums`, `GET /forums/{forumId}` |
| `src/phpbb/api/Controller/TopicsController.php` | `GET /forums/{forumId}/topics`, `GET /topics/{topicId}` |
| `src/phpbb/api/Controller/UsersController.php` | `GET /users/{userId}`, `GET /me` |

**Unit Tests:**
| Plik | Opis |
|------|------|
| `tests/phpbb/api/EventSubscriber/ExceptionSubscriberTest.php` | Test JSON error przy `kernel.exception` |
| `tests/phpbb/api/EventSubscriber/AuthenticationSubscriberTest.php` | Test JWT validation paths (valid, expired, missing, public bypass) |
| `tests/phpbb/api/Controller/HealthControllerTest.php` | Test `200 {"status":"ok"}` |
| `tests/phpbb/api/Controller/AuthControllerTest.php` | Test shapes login/logout/refresh response |
| `tests/phpbb/api/Controller/ForumsControllerTest.php` | Test `data` envelope i status codes |
| `tests/phpbb/api/Controller/TopicsControllerTest.php` | Test `data` envelope |
| `tests/phpbb/api/Controller/UsersControllerTest.php` | Test `/me` i `/users/{id}` response shapes |
| `tests/phpbb/api/KernelTest.php` | Sanity smoke test — Kernel bootstrap, container has controllers |

---

## Implementation Groups

### Group 1: Dependency Update (`composer.json`)

**Dlaczego pierwsze**: Bez poprawnych paczek żadna klasa Symfony 8.x/PHP 8.2 się nie ładuje.

**Zmiany:**
- `"php": "^8.2"` (było `^7.2 || ^8.0.0`)
- `"config.platform.php": "8.2"`
- Symfony `~3.4` → `^8.0` dla wszystkich `symfony/*` paczek
- Dodaj `"symfony/framework-bundle": "^8.0"`
- Usuń `"symfony/debug": "~3.4"` (deprecated, usunięty w Symfony 7)
- Usuń `"symfony/proxy-manager-bridge": "~3.4"` (usunięty w Symfony 7)
- Usuń `"symfony/polyfill-php72": "^1.23"` (PHP 7.2 polyfill, zbędny)
- `"phpunit/phpunit": "^10.0"` (było `^7.0`)
- Usuń phpBB3 PSR-4 mappings (`phpbb3\\*`)
- Dodaj `"phpbb\\": "src/phpbb/"` do `autoload.psr-4`
- Dodaj `"tests/": "tests/"` do `autoload-dev.psr-4`

**Akceptacja:** `composer install` kończy się bez błędów. `php -r "require 'vendor/autoload.php'; echo (class_exists('Symfony\Bundle\FrameworkBundle\FrameworkBundle') ? 'OK' : 'FAIL');"` → `OK`

---

### Group 2: Symfony 8.x Kernel

**Dlaczego po Group 1**: Wymaga zainstalowanego FrameworkBundle.

**Tasks:**

**2.1 `src/phpbb/Kernel.php`**
```php
<?php
declare(strict_types=1);
/**
 * phpBB copyright header...
 */
namespace phpbb;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 2); // dwa poziomy wyżej od src/phpbb/
    }

    public function getConfigDir(): string
    {
        return __DIR__ . '/config'; // src/phpbb/config/
    }

    public function getCacheDir(): string
    {
        return $this->getProjectDir() . '/cache/phpbb4/' . $this->environment;
    }

    public function getLogDir(): string
    {
        return $this->getProjectDir() . '/var/log/phpbb4';
    }
}
```

**2.2 `src/phpbb/config/packages/framework.yaml`**
```yaml
framework:
    secret: '%env(PHPBB_APP_SECRET)%'
    http_method_override: false
    handle_all_throwables: true
    session:
        enabled: false
    router:
        utf8: true
    php_errors:
        log: true
```

**2.3 `src/phpbb/config/services.yaml`**
```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    phpbb\api\Controller\:
        resource: '../api/Controller/'
        tags: ['controller.service_arguments']

    phpbb\api\EventSubscriber\:
        resource: '../api/EventSubscriber/'
```

**2.4 `src/phpbb/config/routes.yaml`**
```yaml
api_v1:
    resource: '../api/Controller/'
    type: attribute
    prefix: /api/v1
```

**2.5 `web/api.php`**
```php
<?php
declare(strict_types=1);
/**
 * phpBB copyright header...
 */
use phpbb\Kernel;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../vendor/autoload.php';

$kernel   = new Kernel($_ENV['PHPBB_APP_ENV'] ?? 'production', (bool) ($_ENV['PHPBB_APP_DEBUG'] ?? false));
$request  = Request::createFromGlobals();
$response = $kernel->handle($request);
$response->send();
$kernel->terminate($request, $response);
```

**Akceptacja:** `php -l src/phpbb/Kernel.php` → no syntax errors. Kernel bootstrap smoke test przechodzi (Group 8 testy).

---

### Group 3: Event Subscribers

**Dlaczego po Group 2**: Klasy muszą być loadowalne przez Kernel.

**3.1 `src/phpbb/api/EventSubscriber/ExceptionSubscriber.php`**

- `implements EventSubscriberInterface`
- Nasłuchuje `kernel.exception` priorytet **10** (przed Twig subscriber)
- Guard: działa tylko jeśli `$request->getPathInfo()` zaczyna się od `/api/`
- Zwraca `JsonResponse(['error' => $exception->getMessage(), 'status' => $code], $code)`
- Dla `HttpExceptionInterface` — używa `getStatusCode()` i `getHeaders()`
- Dla wszystkich innych — 500, bezpieczna generyczna wiadomość (nie leak stack trace w produkcji)
- Wywołuje `$event->stopPropagation()`

**3.2 `src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php`**

- `implements EventSubscriberInterface`
- Nasłuchuje `kernel.request` priorytet **16** (przed routing resolution)
- Guard: tylko ścieżki `/api/` (skip remains — pozostałe entry pointy mogą działać)
- **Whitelist (bez auth)**:
  - `POST /api/v1/auth/login`
  - `POST /api/v1/auth/signup`
  - `GET /api/v1/health`
- Ekstrakcja tokenu: `Authorization: Bearer <token>` header
- Jeśli brak/malformed → `401 {"error":"Missing or malformed Authorization header","status":401}`
- Dekodowanie JWT: `Firebase\JWT\JWT::decode()` z kluczem HS256 z `$_ENV['PHPBB_JWT_SECRET']`
- Obsługiwane wyjątki JWT:
  - `ExpiredException` → `401 {"error":"Token expired","status":401}`
  - `SignatureInvalidException` → `401 {"error":"Invalid token signature","status":401}`
  - `UnexpectedValueException` → `401 {"error":"Invalid token","status":401}`
- Na sukces: `$request->attributes->set('_api_token', $claims)` (obiekt stdClass z firebase/php-jwt)
- JWT claims wg auth-unified HLD: `sub` (int), `aud`, `gen`, `pv`, `utype`, `jti`, `flags`, `kid`

**Ważne (security)**: Subscriber **nie weryfikuje** `gen`/`pv` counter w fazie boilerplate (wymaga DB). Weryfikacja jest zaznaczona jako `// TODO: verify token_generation from DB` — aby było widoczne co jest brakującym zabezpieczeniem.

---

### Group 4: Mock Controllers

**Dlaczego po Group 3**: Subscribers muszą istnieć przed kontrolerami (dependency chain w DI).

Wszystkie kontrolery:
- `declare(strict_types=1)`, nagłówek phpBB
- Namespace `phpbb\api\Controller`
- PHP 8 `#[Route]` attribute (bez `/api/v1` prefix — dodaje routes.yaml)
- Return type `JsonResponse`
- Mock data inline jako hardcoded tablice
- Konstruktor bez parametrów (faza boilerplate — real services potem)
- `#[AsController]` attribute (autoconfigure)

**4.1 `HealthController.php`**
```php
#[Route('/health', name: 'api_v1_health', methods: ['GET'])]
public function health(): JsonResponse
{
    return new JsonResponse(['status' => 'ok']);
}
```

**4.2 `AuthController.php`** — 3 akcje
```php
#[Route('/auth/login', name: 'api_v1_auth_login', methods: ['POST'])]
// → {"data":{"accessToken":"mock.jwt.token","refreshToken":"mock-refresh","expiresIn":900}}

#[Route('/auth/logout', name: 'api_v1_auth_logout', methods: ['POST'])]
// → 204 No Content

#[Route('/auth/refresh', name: 'api_v1_auth_refresh', methods: ['POST'])]
// → {"data":{"accessToken":"mock.jwt.token","expiresIn":900}}
```

**4.3 `ForumsController.php`** — 2 akcje
```php
#[Route('/forums', name: 'api_v1_forums_index', methods: ['GET'])]
// → {"data":[{"id":1,"title":"General Discussion","description":"...","topicCount":42}],"meta":{"total":1}}

#[Route('/forums/{forumId}', name: 'api_v1_forums_show', methods: ['GET'])]
// → {"data":{"id":1,"title":"General Discussion",...}} lub 404
```

**4.4 `TopicsController.php`** — 2 akcje
```php
#[Route('/forums/{forumId}/topics', name: 'api_v1_forums_topics_index', methods: ['GET'])]
// → {"data":[...],"meta":{"total":5,"page":1,"perPage":25,"lastPage":1}}

#[Route('/topics/{topicId}', name: 'api_v1_topics_show', methods: ['GET'])]
// → {"data":{"id":1,"title":"Hello World","forumId":1,...}}
```

**4.5 `UsersController.php`** — 2 akcje
```php
#[Route('/me', name: 'api_v1_me_show', methods: ['GET'])]
// → {"data":{"id":2,"username":"alice","email":"alice@example.com",...}}
// (pobiera z $request->attributes->get('_api_token')->sub)

#[Route('/users/{userId}', name: 'api_v1_users_show', methods: ['GET'])]
// → {"data":{"id":1,"username":"alice",...}}
```

---

### Group 5: Unit Tests

**Dlaczego po Group 4**: Trzeba testować rzeczywiście istniejące klasy.

**phpunit.xml** — PHPUnit 10+ format:
```xml
<phpunit bootstrap="vendor/autoload.php" colors="true">
    <testsuites>
        <testsuite name="phpbb4">
            <directory>tests/phpbb</directory>
        </testsuite>
    </testsuites>
    <php>
        <env name="PHPBB_JWT_SECRET" value="test-secret-32-chars-minimum-len"/>
        <env name="PHPBB_APP_ENV" value="test"/>
    </php>
</phpunit>
```

**Testy kontrolerów** (5 plików): Mock HttpKernel request, assert JsonResponse status + JSON shape.

**`AuthenticationSubscriberTest.php`** — kluczowe scenariusze:
1. Ścieżka nie-API: subscriber nie działa
2. Public path (`/health`): brak tokenu → brak 401
3. Brakujący `Authorization` header → 401
4. Ważny JWT → `_api_token` ustawiony na request
5. Wygasły JWT → 401 `"Token expired"`
6. Nieprawidłowa sygnatura → 401 `"Invalid token signature"`

**`ExceptionSubscriberTest.php`**:
1. `HttpException(404)` na ścieżce `/api/` → JSON 404
2. Exception na ścieżce `/notapi/` → subscriber pomija (nie stopuje)
3. Generic exception → 500 z bezpieczną wiadomością (nie stack trace)

**Akceptacja**: `vendor/bin/phpunit --testsuite phpbb4` → wszystkie testy zielone.

---

### Group 6: Nginx Update

**Dlaczego ostatnie**: Entry point i Kernel muszą istnieć przed modyfikacją nginx.

**Zmiana w `docker/nginx/nginx.conf`:**
Dodaj przed blokiem `location ~ \.php$`:
```nginx
# phpBB4 REST API
location ^~ /api/ {
    try_files $uri /api.php$is_args$args;
    fastcgi_pass php:9000;
    fastcgi_param SCRIPT_FILENAME $document_root/api.php;
    include fastcgi_params;
    
    # CORS headers
    add_header 'Access-Control-Allow-Origin' '*' always;
    add_header 'Access-Control-Allow-Methods' 'GET, POST, PUT, PATCH, DELETE, OPTIONS' always;
    add_header 'Access-Control-Allow-Headers' 'Authorization, Content-Type' always;
    
    # Preflight
    if ($request_method = 'OPTIONS') {
        return 204;
    }
}
```

---

## Acceptance Criteria

Boilerplate jest ukończony gdy:

1. `composer install` — exit code 0, brak błędów
2. `php -l src/phpbb/Kernel.php` + wszystkie nowe pliki PHP — brak syntax errors
3. `vendor/bin/phpunit --testsuite phpbb4` — ≥ 20 testów, wszystkie zielone
4. `curl http://localhost/api/v1/health` → `{"status":"ok"}` HTTP 200
5. `curl http://localhost/api/v1/forums` bez tokenu → `{"error":"...","status":401}` HTTP 401
6. `curl -H "Authorization: Bearer <valid_mock_jwt>" http://localhost/api/v1/forums` → `{"data":[...],"meta":{...}}` HTTP 200
7. Istniejące `src/phpbb3/` pliki — **niezmienione** (nie importowane, nie usuwane)

---

## Implementation Order

```
Group 1 (composer.json)
    └── Group 2 (Kernel + config)
            └── Group 3 (Subscribers)
                    └── Group 4 (Controllers)
                            └── Group 5 (Tests)
                                    └── Group 6 (Nginx)
```

---

## Standards Compliance Checklist

- [ ] **`declare(strict_types=1)`** na początku każdego nowego pliku PHP (`standards/global/STANDARDS.md`)
- [ ] **Nagłówek copyright phpBB** w każdym pliku PHP (`standards/global/STANDARDS.md`)
- [ ] **Brak `?>`** zamykającego tagu we wszystkich plikach (`standards/global/STANDARDS.md`)
- [ ] **Allman braces** — klamra otwierająca na nowej linii (`standards/global/STANDARDS.md`)
- [ ] **Indentacja tabulatorami** — żadnych spacji na wcięciach (`standards/global/STANDARDS.md`)
- [ ] **`readonly` constructor promotion** dla wstrzykiwanych zależności (`standards/backend/STANDARDS.md`)
- [ ] **Żadnych `global`** — wyłącznie constructor DI (`standards/backend/STANDARDS.md`)
- [ ] **Namespace `phpbb\`** mirroring `src/phpbb/` (`standards/backend/STANDARDS.md`)
- [ ] **`PascalCase`** klasy, `camelCase` metody (`standards/backend/STANDARDS.md`)
- [ ] **Zero importów z `src/phpbb3/`** lub legacy `includes/` (`standards/backend/STANDARDS.md`)
- [ ] **Route prefix `/api/v1/`** na wszystkich trasach (`standards/backend/REST_API.md`)
- [ ] **Route naming `api_v1_<resource>_<action>`** (`standards/backend/REST_API.md`)
- [ ] **Zawsze `JsonResponse`** — żadnych `echo`/`header()` w kontrolerach (`standards/backend/REST_API.md`)
- [ ] **Success shape `{ "data": ... }`** z opcjonalnym `{ "meta": ... }` na kolekcjach (`standards/backend/REST_API.md`)
- [ ] **Error shape `{ "error": "msg", "status": N }`** (`standards/backend/REST_API.md`)
- [ ] **Validation errors `{ "errors": [{field, message}] }`** status 422 (`standards/backend/REST_API.md`)
- [ ] **JWT claims** zgodne ze specyfikacją auth-unified: `sub`, `gen`, `pv`, `utype`, `flags`, `kid`, `jti` (`standards/backend/REST_API.md`)
- [ ] **`_api_token` attribute** — jedyne źródło tożsamości w kontrolerach; controllers nie walidują JWT bezpośrednio (`standards/backend/REST_API.md`)
- [ ] **PHPUnit 10+ attributes** `#[Test]`, `#[DataProvider]` — żadnych `@annotation` (`standards/testing/STANDARDS.md`)
- [ ] **Testy izolowane** — żadnego DB, żadnych side effects (`standards/testing/STANDARDS.md`)
- [ ] **`PascalCase` klasy testowe**, `camelCase` metody testowe (`standards/testing/STANDARDS.md`)
- [ ] **`TODO: verify token_generation from DB`** widoczne w AuthenticationSubscriber — brakujące zabezpieczenie oznaczone wprost
