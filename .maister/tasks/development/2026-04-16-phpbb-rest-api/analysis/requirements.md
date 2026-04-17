# Requirements — phpBB REST API (Phase 1)

**Data**: 2026-04-16

## Opis zadania
Zbudować trzy REST API entry pointy dla phpBB Vibed na bazie istniejącej infrastruktury Symfony HttpKernel. Faza 1 zwraca wyłącznie mocki. Docelowo zastępuje wszystkie entry pointy phpBB.

## Q&A z requirements gathering

**Q: Które endpointy implementujemy w Fazie 1?**  
A: Lista z research raportu jest OK:
- Forum: `GET /api/v1/health`, `GET /api/v1/forums`, `GET /api/v1/topics`, `GET /api/v1/topics/{id}`, `GET /api/v1/users/me`
- Admin: `GET /adm/api/v1/health`, `GET /adm/api/v1/users`
- Install: `GET /install/api/v1/health`, `GET /install/api/v1/status`

**Q: Status auth w Fazie 1?**  
A: Stub — auth_subscriber zwraca 501 Not Implemented

**Q: Sesja phpBB w entry pointcie?**  
A: Nie — entry pointy są minimalne (bez session_begin/acl)

**Q: Powiązania z phpBB codebase w kontrolerach?**  
A: **BRAK** — kontrolery Fazy 1 mają zero zależności od phpBB services.
Nie wolno wstrzykiwać `@config`, `@dbal.conn`, `@user`, `@auth` ani żadnego phpBB serwisu.
Kontrolery to pure PHP klasy bez konstruktora lub z pustym konstruktorem.
Hardcoded mock arrays bezpośrednio w metodach.

**Q: CORS?**  
A: Tak — nginx dodaje `Access-Control-Allow-Origin: *` + metody + nagłówki + obsługa OPTIONS

## Wymagania funkcjonalne

### F1: phpbb\core\Application
- Klasa ~40 linii, namespace `phpbb\core`
- Implementuje `HttpKernelInterface`, `TerminableInterface`
- Kompozycja nad `Symfony\Component\HttpKernel\HttpKernel`
- Metoda `run()`: pobiera request z kontenera, wywołuje handle(), send(), terminate()
- Konstruktor: `(HttpKernel $kernel, ContainerInterface $container)`

### F2: Entry pointy
- `web/api.php` — ≤15 linii, define constants → include common.php → get api.application → run()
- `web/adm/api.php` — define ADMIN_START, NEED_SID, IN_ADMIN → include common.php → check acl('a_') → run()  
- `web/install/api.php` + katalog `web/install/` — define IN_INSTALL → require startup.php → run()
- BEZ session_begin(), BEZ auth->acl() w entry pointach

### F3: JSON Exception Subscriber
- `phpbb\api\event\json_exception_subscriber`
- Priority: 10 (wyższy niż HTML subscriber = 0)
- Obsługuje wszystkie wyjątki → JsonResponse z `{'error': ..., 'status': ...}`
- W trybie debug: dodaje trace

### F4: Auth Subscriber (stub)
- `phpbb\api\event\auth_subscriber`
- kernel.request event
- Zwraca `JsonResponse({"error": "API token authentication not yet implemented"}, 501)`
- **Faza 1: zawsze 501** (stub — bez sprawdzania DB)
- **WYJĄTEK**: nie blokuje `/api/v1/health` (lub wszystkie GETs? → tylko /health nie wymaga auth)

### F5: Mock Controllers (bez zależności phpBB)
Forum API (`phpbb\api\v1\controller\*`):
- `health::index()` → `{"status": "ok", "api": "phpBB Forum API", "version": "1.0.0-dev"}`
- `forums::index()` → `{"forums": [{"id": 1, "name": "General Discussion", ...}]}`
- `topics::index()` → `{"topics": [{...}]}`
- `topics::show(int $id)` → `{"topic": {"id": $id, ...}}`
- `users::me()` → `{"user": {"id": 0, "username": "guest", ...}}`

Admin API (`phpbb\admin\api\v1\controller\*`):
- `health::index()` → `{"status": "ok", "api": "phpBB Admin API"}`
- `users::index()` → `{"users": [{...}]}`

Install API (`phpbb\install\api\v1\controller\*`):
- `health::index()` → `{"status": "ok", "api": "phpBB Install API"}`
- `status::index()` → `{"installed": false, "version": "3.3.x-dev"}`

**KRYTYCZNE**: Żadna z tych klas nie ma konstruktora z zależnościami phpBB.

### F6: DI Services
- Nowy plik `services_api.yml` importowany do `services.yml`
- Serwis `api.application`, `admin_api.application` → klasa `phpbb\core\Application`
- Serwis `api.exception_listener` (json_exception_subscriber, priority 10)
- Serwis `api.auth_subscriber` (stub)
- Kontrolery jako serwisy: `phpbb.api.v1.controller.health`, etc.
- Analogicznie dla installer: `services_install_api.yml` (osobny plik dla installer kontenera)

### F7: Routing YAML
- `api.yml` — 5 tras z prefiksem `/api/v1/`
- `admin_api.yml` — 2 trasy z prefiksem `/adm/api/v1/`
- `install_api.yml` — 2 trasy (dla installer kontenera)
- Importowane do odpowiednich `routing.yml`

### F8: Nginx
- 3 bloki `location ^~` PRZED blokiem `~ ^(.+\.php)`
- `/api/` → `SCRIPT_FILENAME=web/api.php`
- `/adm/api/` → `SCRIPT_FILENAME=web/adm/api.php`
- `/install/api/` → `SCRIPT_FILENAME=web/install/api.php`
- CORS headers + OPTIONS 204

### F9: composer.json
- Dodać `"phpbb\\api\\": "src/phpbb/api/"` do autoload.psr-4

## Kryteria akceptacji
1. `curl http://localhost:8181/api/v1/health` → HTTP 200, `{"status":"ok",...}`
2. `curl http://localhost:8181/adm/api/v1/health` → HTTP 200 (brak auth gate na /health)
3. `curl http://localhost:8181/install/api/v1/health` → HTTP 200
4. `curl http://localhost:8181/api/v1/forums` → HTTP 501 (auth stub blokuje)
5. `curl http://localhost:8181/api/v1/topics/1` → HTTP 501 (auth stub)
6. Istniejące trasy forum (/viewtopic.php etc.) nadal działają

## Ograniczenia
- PHP 7.2+, Symfony 3.4
- Brak tabeli phpbb_api_tokens w Fazie 1
- Brak `declare(strict_types=1)` (phpBB 3.3 standard)
- Indentation: taby (phpBB standard)
- Brak closing PHP tag
