# Research Brief — phpBB REST API

**Data**: 2026-04-16  
**Typ badania**: Mixed (Technical + Architecture)  
**Pytanie badawcze**: Jak zbudować `phpbb\core` jako bazę dla trzech samodzielnych aplikacji Symfony
(`phpbb\api`, `phpbb\admin\api`, `phpbb\install\api`) wystawiających REST API,
które docelowo zastąpią wszystkie istniejące web entry pointy phpBB?

---

## Kontekst

Projekt phpBB Vibed jest zmodernizowanym forkiem phpBB 3.3.x.

### Znana struktura PSR-4 (composer.json)
| Namespace | Ścieżka |
|-----------|---------|
| `phpbb\core\` | `src/phpbb/core/` (puste — do stworzenia) |
| `phpbb\` | `src/phpbb/forums/` |
| `phpbb\admin\` | `src/phpbb/admin/` (puste) |
| `phpbb\common\` | `src/phpbb/common/` |
| `phpbb\install\` | `src/phpbb/install/` |

### Istniejące entry pointy (web/)
- `app.php`, `index.php`, `viewforum.php`, `viewtopic.php`, etc.
- `adm/index.php` (panel admina)
- Brak: `api.php`, `adm/api.php`, `install/api.php`

### Symfony w projekcie
- Wersja 3.4 (HttpKernel, HttpFoundation, Routing, DI, EventDispatcher)
- Istnieje `phpbb\Container` (fasada DI, zmigrowana w poprzedniej sesji)
- Istnieje `$phpbb_container` (Symfony ContainerInterface)

---

## Cele projektu

1. **`phpbb\core`** — bazowa klasa/framework dla wszystkich trzech API
   - Inicjalizacja Symfony HttpKernel
   - Wspólna obsługa routingu, middleware, autentykacji
   - Integracja z istniejącym DI kontenerem phpBB

2. **`phpbb\api`** → `web/api.php`
   - Publiczne REST API forum (tematy, posty, użytkownicy, kategorie)
   - Autentykacja via sesja phpBB lub token

3. **`phpbb\admin\api`** → `web/adm/api.php`
   - Admin REST API (zarządzanie, konfiguracja, użytkownicy)
   - Tylko dla moderatorów/adminów

4. **`phpbb\install\api`** → `web/install/api.php`
   - Instalacja/update API
   - Podąża za istniejącą logiką install/

---

## Kryteria sukcesu

- [ ] `phpbb\core` enkapsuluje inicjalizację Symfony HttpKernel
- [ ] Każde API dziedziczy z `phpbb\core` i dodaje własny routing
- [ ] Entry pointy (`web/api.php` etc.) są minimalne (≤20 linii)
- [ ] Mockowane endpointy zwracają JSON z HTTP 200
- [ ] Działa równolegle z istniejącymi entry pointami
- [ ] Nginx jest skonfigurowany (lub może być) dla nowych endpointów

---

## Zakres badania

**Włączone**:
- Symfony HttpKernel/HttpFoundation/Routing jako base
- Istniejące wzorce w projekcie (common.php, Container, DI)
- Wzorce REST API z autentykacją phpBB
- Nginx routing dla nowych endpointów
- Sposób na mockowanie danych

**Wykluczone**:
- Pełna implementacja wszystkich endpointów
- Migracja bazy danych
- Zmiany w szablonach Twig
