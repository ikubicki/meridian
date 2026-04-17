# Research Sources

## Category 1 — `codebase-entrypoints`
*Wzorzec bootstrapu istniejących entry pointów*

### Kluczowe pliki do odczytu

| Plik | Cel |
|------|-----|
| `web/app.php` | Główny wzorzec: session_begin → HttpKernel::handle |
| `web/index.php` | Klasyczny procedural entry point (bez HttpKernel) |
| `web/adm/index.php` | Admin entry point — inny bootstrap? |
| `web/install.php` | Install entry point |
| `web/viewforum.php` | Typowy page entry point — co inicjalizuje? |
| `src/phpbb/common/common.php` | Bootstrap‑serce: config → container_builder → $phpbb_container |
| `src/phpbb/common/startup.php` | Najwcześniejszy bootstrap (autoload, constants) |
| `src/phpbb/common/constants.php` | Stałe systemu |

### Wzorce do szukania (grep)
- `session_begin` — kto go woła, a kto nie?
- `http_kernel` / `HttpKernel` — gdzie jest używany
- `phpbb_container->get` — jakie services są pobierane w entry pointach
- `PHPBB_FILESYSTEM_ROOT` / `$phpbb_root_path` — ustawianie root path

---

## Category 2 — `codebase-di-kernel`
*DI, HttpKernel, routing, controller resolver*

### Kluczowe pliki do odczytu

| Plik | Cel |
|------|-----|
| `src/phpbb/common/config/default/container/services_http.yml` | Definicja HttpKernel, symfony_request, request_stack |
| `src/phpbb/common/config/default/container/services_routing.yml` | Definicja router, RouterListener, ControllerResolver |
| `src/phpbb/common/config/default/container/services.yml` | Główny services — controller.resolver, class_loader |
| `src/phpbb/common/config/default/container/parameters.yml` | Parametry kontenera |
| `src/phpbb/forums/controller/resolver.php` | phpbb\controller\resolver implements ControllerResolverInterface |
| `src/phpbb/forums/controller/helper.php` | Helper kontrolerów — JSON response? |
| `src/phpbb/forums/routing/router.php` | phpbb\routing\router implements RouterInterface |
| `src/phpbb/forums/routing/resources_locator/` | Jak router ładuje zasoby tras |
| `src/phpbb/forums/di/container_builder.php` | Jak budowany jest kontener (env, config, extensions) |
| `src/phpbb/forums/json_response.php` | Czy istnieje JsonResponse w phpBB? |

### Wzorce do szukania (grep)
- `kernel.event_subscriber` tag — które services słuchają zdarzeń kernela?
- `RouteCollection` / `route_collection` — jak trasy są agregowane
- `ControllerResolverInterface` — gdzie jest implementacja
- `ArgumentResolverInterface` — Symfony 3.4 wymaga go w HttpKernel

---

## Category 3 — `codebase-installer`
*Wzorzec izolowanego kontenera (installer vs default)*

### Kluczowe pliki do odczytu

| Plik | Cel |
|------|-----|
| `src/phpbb/common/config/installer/container/services.yml` | Installer DI — inny zestaw services |
| `src/phpbb/install/` (struktura) | Jak install używa własnego bootstrapu |
| `src/phpbb/forums/di/container_builder.php` | Metody `with_config`, `get_container` — czy obsługuje env? |
| `src/phpbb/common/config/` (lista katalogów) | Jakie środowiska DI istnieją poza `default` i `installer`? |

### Wzorce do szukania (grep)
- `container_builder` — gdzie jest instancjonowany poza `common.php`
- `with_config` — parametry budowania kontenera
- `PHPBB_ENVIRONMENT` — jak środowisko wpływa na kontener
- `extensions` w kontekście DI (`getContainerExtension`) — czy phpBB używa DI Extensions?

### Ścieżki do przeszukania
- `src/phpbb/common/config/` — lista wszystkich podkatalogów
- `src/phpbb/install/**/*.php` — bootstrapowanie install

---

## Category 4 — `external-symfony-kernel`
*Symfony 3.4 HttpKernel bez FrameworkBundle — lekki wzorzec*

### Zewnętrzne źródła dokumentacji

| URL | Temat |
|-----|-------|
| `https://symfony.com/doc/3.4/create_framework/index.html` | "Create your own framework on top of Symfony" — kanoniczna seria |
| `https://symfony.com/doc/3.4/create_framework/http_kernel_httpkernelinterface.html` | HttpKernel HttpKernelInterface — minimalna implementacja |
| `https://symfony.com/doc/3.4/http_kernel.html` | Symfony HttpKernel component — cykl żądania |
| `https://symfony.com/doc/3.4/create_framework/routing.html` | Routing w lekkim frameworku |
| `https://symfony.com/doc/3.4/micro_kernel_trait.html` | MicroKernelTrait — czy dostępny w 3.4? |
| `https://symfony.com/doc/3.4/configuration/micro_kernel_trait.html` | MicroKernelTrait cookbook |
| `https://github.com/symfony/symfony/blob/3.4/src/Symfony/Component/HttpKernel/HttpKernel.php` | Kod źródłowy HttpKernel — wymagane argumenty |

### Pytania do odpowiedzi ze źródeł zewnętrznych
1. Jak zbudować minimalny Symfony 3.4 HttpKernel BEZ FrameworkBundle?
2. Czy `MicroKernelTrait` jest dostępny w Symfony 3.4?
3. Jakie `EventListener`-y są minimalne dla obsługi routingu? (RouterListener, ResponseListener, ExceptionListener)
4. Jak w Symfony 3.4 wstrzyknąć istniejący kontener DI do nowego HttpKernel?
5. Jak obsługiwać JSON-only response bez Twig?

---

## Category 5 — `codebase-nginx`
*Nginx — routing do nowych entry pointów API*

### Kluczowe pliki do odczytu

| Plik | Cel |
|------|-----|
| `docker/nginx/default.conf` | Aktualny config — `root`, `location /`, FastCGI params |
| `docker/php/php.ini` | Limity PHP, opcje — dla kontekstu |
| `docker-compose.yml` | Mapowanie portów, nazwy serwisów (app:9000) |

### Wzorce do przeanalizowania w nginx config
- Blok `location ~ ^(.+\.php)` — jak PHP jest obsługiwane
- `fastcgi_param SCRIPT_FILENAME` — jak entry point jest identyfikowany
- `try_files $uri $uri/ /app.php` — domyślny fallback
- Blok `location ~ ^/(src|bin|cache)` — co jest zablokowane

### Pytania do odpowiedzi
1. Jak dodać `location /api/` → `web/api.php` bez konfliktu z `try_files`?
2. Jak przekazać PREFIX_PATH do PHP przez FastCGI dla `/api/topics`?
3. Czy potrzebny `location /adm/api/` → `web/adm/api.php`?
4. Jak obsłużyć `PATH_INFO` dla REST routes (np. `/api/topics/123`)?

---

## Pliki konfiguracyjne — przekrój

| Plik | Kategoria | Znaczenie |
|------|-----------|-----------|
| `composer.json` | Ogólne | Wersje Symfony, autoload PSR-4, dostępne pakiety |
| `config.php` | Ogólne | Połączenie DB, $table_prefix |
| `src/phpbb/common/config/default/container/services.yml` | DI | Import wszystkich service-files |
| `src/phpbb/common/config/default/container/parameters.yml` | DI | `core.disable_super_globals`, `datetime.class` |
| `src/phpbb/common/config/installer/container/services.yml` | DI | Izolowany installer DI |
| `docker/nginx/default.conf` | Infrastruktura | FastCGI routing |
| `docker-compose.yml` | Infrastruktura | Sieć kontenerów |
