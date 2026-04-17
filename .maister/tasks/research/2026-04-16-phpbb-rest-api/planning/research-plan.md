# Research Plan — phpBB REST API

**Data**: 2026-04-16
**Typ**: Mixed (Technical + Architecture)
**Status**: Draft

---

## 1. Cele badania (Research Objectives)

### RO-1: Wzorzec bootstrapowania — jak `web/app.php` inicjalizuje HttpKernel
Zrozumieć sekwencję: `common.php` → `container_builder` → `http_kernel` service → `handle()`.
Ustalić, które kroki są obowiązkowe, a które można pominąć/zastąpić w nowych entry pointach API.

### RO-2: Jak phpBB łączy routing z HttpKernel
Zrozumieć relację między `phpbb\routing\router`, `RouterListener`, `controller.resolver`
a cyklem żądania HttpKernel. Ustalić, w jaki sposób nowe API może załadować własne zasoby tras.

### RO-3: Izolacja kontenera DI dla wielu aplikacji
Zbadać, czy `phpbb\di\container_builder` pozwala na nadpisanie/rozszerzenie zestawu services.yml,
jak w przypadku `config/installer/container/` vs `config/default/container/`.
Odpowiedzieć: jeden wspólny kontener dla trzech API czy oddzielne?

### RO-4: Wzorzec mini-kernel w Symfony 3.4
Znaleźć dokumentację / przykłady Symfony 3.4 MicroKernel lub prostego HttpKernel bez
pełnego AppKernel — żeby zbudować `phpbb\core` z minimalnym zestawem services.

### RO-5: Konfiguracja Nginx dla wielu entry pointów REST API
Ustalić zmiany w `docker/nginx/default.conf` potrzebne do routowania
`/api/*`, `/adm/api/*`, `/install/api/*` do nowych plików PHP.

---

## 2. Metodologia

| Cel | Metoda | Źródła |
|-----|--------|--------|
| RO-1 | Czytanie kodu — trace wywołań od `web/app.php` przez `common.php` do `container_builder` | Kod źródłowy |
| RO-2 | Analiza konfiguracji DI + klasy routingu | services_routing.yml, router.php, resolver.php |
| RO-3 | Porównanie `config/default` vs `config/installer` | services.yml, container_builder |
| RO-4 | Przeszukanie zewnętrznej dokumentacji Symfony 3.4 | docs.symfony.com, cookbook |
| RO-5 | Analiza `docker/nginx/default.conf` + wzorce FastCGI | Istniejący nginx config |

---

## 3. Gathering Strategy

### Instances: 5

| # | Category ID | Focus Area | Narzędzia | Output Prefix |
|---|------------|------------|-----------|---------------|
| 1 | `codebase-entrypoints` | Istniejące `web/*.php` — wzorzec bootstrapu, sesja, HttpKernel | Grep, Read | `codebase-entrypoints` |
| 2 | `codebase-di-kernel` | DI container_builder, services_http.yml, controller resolver, routing | Read, Grep | `codebase-di-kernel` |
| 3 | `codebase-installer` | `config/installer/` vs `config/default/` — wielokontenerowy wzorzec | Read, List | `codebase-installer` |
| 4 | `external-symfony-kernel` | Symfony 3.4 HttpKernel, MicroKernel, mini-kernel bez FrameworkBundle | Fetch | `external-symfony-kernel` |
| 5 | `codebase-nginx` | `docker/nginx/default.conf` — routing FastCGI, bloki location, PATH_INFO | Read | `codebase-nginx` |

### Rationale
- **5 instancji** odpowiada 5 niezależnym wymiarom pytania badawczego.
- Kategorie 1–3 i 5 to praca na kodzie lokalnym — można uruchamiać równolegle.
- Kategoria 4 (zewnętrzna) wymaga dostępu do sieci — oddzielny gatherer.
- Kategoria 3 (installer) jest krytyczna, bo daje wzorzec wielu izolowanych kontenerów.

---

## 4. Fazy badania

### Faza 1 — Broad Discovery (zakres, struktura)
- Skan `web/*.php` → z jakim wzorcem startuje każdy entry point?
- Skan `src/phpbb/common/config/` → jakie konfiguracje kontenera istnieją?
- Skan `src/phpbb/forums/routing/` i `controller/` → jakie klasy tworzą warstwę routingu?
- Skan `src/phpbb/install/` → czy install ma własny kernel/kontener?

### Faza 2 — Targeted Reading (odczyt kluczowych plików)
- `web/app.php` → sekwencja bootstrapu
- `src/phpbb/common/common.php` → inicjalizacja kontenera i globale
- `src/phpbb/common/config/default/container/services_http.yml` → definicja HttpKernel
- `src/phpbb/common/config/default/container/services_routing.yml` → definicja routera
- `src/phpbb/common/config/installer/container/services.yml` → installer DI
- `src/phpbb/forums/di/container_builder.php` → jak się buduje kontener
- `docker/nginx/default.conf` → aktualny routing

### Faza 3 — Deep Dive (analiza integracyjna)
- Trace flow: Request → HttpKernel → RouterListener → ControllerResolver → Response
- Zidentyfikować, które services są "core" (wymagane przez HttpKernel) a które "forum-specific"
- Zbadać, czy `phpbb\routing\router` może załadować YAML z dowolnej ścieżki
- Zweryfikować, jak `common.php` można pominąć lub zastąpić dla nowych API

### Faza 4 — Verification (walidacja)
- Sprawdzić, czy dependencies Symfony ≥ 3.4 zawierają `MicroKernelTrait`
- Potwierdzić, że nowe entry pointy mogą działać obok `web/index.php` (bez konfliktu sesji/ciasteczek)
- Zaproponować minimalny `web/api.php` (≤20 linii) na podstawie znalezionych wzorców

---

## 5. Ramy analizy (Analysis Framework)

### Wymiar 1: Minimalizm bootstrapu
Pytanie: Ile kroków z `common.php` jest niezbędnych dla czystego REST API (bez sesji phpBB)?
Metryka: liczba linii w nowym entry pointcie vs `web/app.php`.

### Wymiar 2: Izolacja kontenera
Pytanie: Jednoserwisowy kontener dla wszystkich trzech API czy trzy oddzielne?
Kryteria: zużycie pamięci, nakładanie się services, możliwość testowania.

### Wymiar 3: Kompatybilność wsteczna
Pytanie: Czy nowe API nie psuje istniejących entry pointów?
Kryteria: brak globali, brak konfliktu sesji, osobna przestrzeń tras.

### Wymiar 4: Rozszerzalność
Pytanie: Jak dodawać kolejne endpointy (np. `/api/v2/topics`) bez zmian w `phpbb\core`?
Kryteria: nowy plik YAML z trasami, nowy kontroler — bez zmiany kernela.

---

## 6. Kryteria sukcesu

- [ ] Sekwencja bootstrapu `web/app.php` → HttpKernel udokumentowana krok po kroku
- [ ] Zidentyfikowany minimalny zestaw services DI wymagany przez HttpKernel
- [ ] Wzorzec wielu kontenerów (installer vs default) opisany jako wzorzec do zastosowania w API
- [ ] Znaleziony wzorzec Symfony 3.4 dla lekkiego HttpKernel (bez AppKernel / FrameworkBundle)
- [ ] Szkic minimalnego `web/api.php` (≤20 linii) gotowy do implementacji
- [ ] Zmiany Nginx dla `/api/*` zaproponowane

---

## 7. Oczekiwane wyniki (Expected Outputs)

| Plik | Zawartość |
|------|-----------|
| `outputs/architecture-recommendation.md` | Proponowana architektura `phpbb\core` + 3 API apps |
| `outputs/bootstrap-sequence.md` | Krok po kroku: co `web/api.php` musi wywołać |
| `outputs/di-services-minimal.md` | Lista services DI niezbędnych dla HttpKernel REST |
| `outputs/nginx-config-diff.md` | Proponowane zmiany do `docker/nginx/default.conf` |
| `outputs/entry-point-scaffold.md` | Szkielet `web/api.php`, `web/adm/api.php`, `web/install/api.php` |
