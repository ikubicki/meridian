# Raport badawczy: Niezaimplementowane funkcjonalności phpBB4 Meridian

**Typ badania**: Analiza luk (gap analysis)
**Data**: 2026-04-25
**Projekt**: phpBB4 „Meridian" — przebudowa phpBB3 na Symfony 8.x + React SPA

---

## Spis treści

1. [Zaplanowane, ale jeszcze niezaimplementowane](#1-zaplanowane-ale-jeszcze-niezaimplementowane)
2. [Luki funkcjonalne — poza jakimkolwiek planem](#2-luki-funkcjonalne--poza-jakimkolwiek-planem)
3. [Rekomendowane uzupełnienia planu kamieni milowych](#3-rekomendowane-uzupełnienia-planu-kamieni-milowych)
4. [Podsumowanie](#4-podsumowanie)

---

## Streszczenie wykonawcze

Ukończono M0–M8 (cache, użytkownicy, auth/JWT, REST API, fora, przechowywanie plików, wątki/posty, wiadomości, powiadomienia). To solidna warstwa backendowa — 436 testów PHPUnit, 168 E2E.

**Jednak plan ma dwie kategorie luk:**

- **W planie, niezrealizowane**: ~67 zadań ⏳ rozłożonych po M9–M14 i blokach bezpieczeństwa (S.1, S.2, load testy). Część z nich to zaległości po kamienach M0–M8.
- **Poza planem**: 27 obszarów funkcjonalnych z phpBB3 nieobecnych w żadnym milestone'ie M0–M14, w tym kilka **krytycznych dla MVP** (rejestracja, email, CAPTCHA, cron scheduler).

Wniosek: forum nie może działać publicznie bez uzupełnienia luk poza planem.

---

## 1. Zaplanowane, ale jeszcze niezaimplementowane

### 1.1 Zaległości w ukończonych kamieniach milowych (M0–M8)

Następujące zadania mają status ⏳ mimo że ich kamień milowy jest oznaczony jako ukończony:

| Zadanie | Opis | Milestone |
|---|---|---|
| **0.10** | GitHub Actions CI pipeline (lint + testy + `composer audit`) | M0 / X.5 |
| **1.9** | Redis backend (Phase 2, opcjonalny) | M1 |
| **6.6** | Tiered Counter Pattern — flush przez cron | M6 / X.2 |
| **8.11** | React frontend — komponent pollingu powiadomień | M8 → odłożone do M10 |
| **X.3** | Common exceptions (`phpbb\common\Exception\*`) | Cross-cutting |
| **X.4** | Skrypty migracji DB (PM + załączniki) | Cross-cutting |

> **Uwaga**: 0.10 i X.5 to to samo zadanie (CI pipeline). Łącznie 6 zaległych zadań ⏳ w „ukończonych" kamieniach.

---

### 1.2 Planowane kamienie milowe (M9–M14)

#### M9 — Search Service (`phpbb\search`)

**Status**: Research ✅ — implementacja nie rozpoczęta

| Zadanie | Opis |
|---|---|
| 9.2 | Plan implementacji |
| 9.3 | Architektura ISP + backendy (MySQL FT / Sphinx / pluggable) |
| 9.4 | Testy PHPUnit |
| 9.5 | Testy Playwright E2E (`/api/v1/search/`) |

**Odpowiednik phpBB3**: `search/` — `fulltext_native`, `fulltext_sphinx`, `fulltext_postgres`

---

#### M10 — React SPA Frontend

**Status**: Statyczny mock (`mocks/forum-index/`) — żadna prawdziwa SPA nie konsumuje `/api/v1/`

| Zadanie | Opis |
|---|---|
| 10.2 | Design system / bazowe komponenty |
| 10.3 | Auth flow (login, logout, trasy chronione) |
| 10.4 | Forum index + hierarchia |
| 10.5 | Lista tematów + widok postu |
| 10.6 | Komponent powiadomień (polling) |
| 10.7 | UI wiadomości |

**Odpowiednik phpBB3**: Cały `styles/prosilver/` + renderowanie Twig/TPL

---

#### M11 — Content Formatting Plugins

**Status**: Wszystkie zadania ⏳ (szacunkowo ~7 zadań)

Zakres: pluggable pipeline przez s9e text-formatter; pluginy BBCode, Markdown, Smilies; kolumna `encoding_engine` na tabeli postów.

**Odpowiednik phpBB3**: `includes/bbcode.php`, `phpbb\textformatter\`

---

#### M12 — Moderation Service (`phpbb\moderation`)

**Status**: Wszystkie zadania ⏳ (12.1–12.8, 8 zadań)

Zakres: raport + repozytorium, kolejka moderacyjna, akcje moderatora (lock/delete/move), REST API `/api/v1/moderation/`, testy PHPUnit + E2E.

**Odpowiednik phpBB3**: `includes/mcp.php`, `phpbb\report\`, `phpbb\content_visibility\`

> **Uwaga**: Ostrzeżenia (warnings), notatki moderatora i szczegółowe logi MCP **nie są** opisane w M12 — patrz Sekcja 2.

---

#### M13 — Configuration Service (`phpbb\config`)

**Status**: Wszystkie zadania ⏳ (13.1–13.6, 6 zadań)

Zakres: ConfigRepository (DBAL, tabela `phpbb_config`), ConfigService (get/set/delete), REST API `/api/v1/config/` (admin only).

**Odpowiednik phpBB3**: `phpbb\config\config` + globalny `$config`

---

#### M14 — Admin Panel (`phpbb\admin`)

**Status**: Wszystkie zadania ⏳ (14.1–14.6, 6 zadań)

Zakres: kontrolery REST ACP + React SPA admin UI. Zastępuje katalog `adm/`. Testy PHPUnit + E2E.

**Odpowiednik phpBB3**: Cały katalog `adm/` — użytkownicy, fora, konfiguracja, bany, rozszerzenia, style.

---

### 1.3 Przeglądy bezpieczeństwa i load testy

#### S.1 — Przegląd bezpieczeństwa: warstwa Auth (trigger: po M3 — zaległy)

**7 zadań ⏳** (S1.1–S1.7):

| Zadanie | Opis |
|---|---|
| S1.1 | JWT attack surface: alg:none, słaby sekret, bypass expiry |
| S1.2 | Brute-force / rate-limiting na `POST /api/v1/auth/login` |
| S1.3 | ACL bypass: poziomy escalacja uprawnień (user → user) |
| S1.4 | ACL bypass: pionowy escalacja uprawnień (user → admin) |
| S1.5 | Session fixation / ponowne użycie tokenu po wylogowaniu |
| S1.6 | Audyt konfiguracji Argon2id (pamięć, iteracje, równoległość) |
| S1.7 | Dokumentacja wyników + remediacja |

---

#### M6.x — Load testy (trigger: po M6 — zaległe)

**6 zadań ⏳** (L.1–L.6):

| Zadanie | Opis | Cel |
|---|---|---|
| L.1 | k6 scaffolding (`tests/load/`) + profil docker-compose | — |
| L.2 | `GET /api/v1/forums` | p95 < 100ms @ 50 VU |
| L.3 | `GET /api/v1/forums/{id}/topics` | p95 < 150ms @ 50 VU |
| L.4 | `GET /api/v1/topics/{id}/posts` | p95 < 200ms @ 50 VU |
| L.5 | Baseline cache hit/miss ratio | Decyzja o Redis |
| L.6 | Skrypt `composer test:load` + brama CI | — |

---

#### S.2 — Przegląd bezpieczeństwa: pełny API surface (trigger: po M6.x — zaległy)

**9 zadań ⏳** (S2.1–S2.9):

| Zadanie | Opis |
|---|---|
| S2.1 | OWASP ZAP automated scan: pełny API surface |
| S2.2 | IDOR na endpointach zasobów (fora, tematy, posty, pliki) |
| S2.3 | XSS przez pipeline treści (output s9e) |
| S2.4 | Insecure file upload (typ, rozmiar, ścieżka) |
| S2.5 | Smoke test SQL injection (audyt prepared statements) |
| S2.6 | Mass assignment / over-posting na POST/PATCH |
| S2.7 | Wrażliwe dane w odpowiedziach błędów |
| S2.8 | Audyt nagłówków bezpieczeństwa (CORS, CSP, X-Frame-Options) |
| S2.9 | Dokumentacja wyników + remediacja |

---

## 2. Luki funkcjonalne — poza jakimkolwiek planem

Poniższe funkcje phpBB3 **nie mają żadnego zadania w M0–M14**.

### 2.1 Krytyczne (blokują użyteczność forum jako produktu publicznego)

#### Przepływ rejestracji (CAPTCHA, weryfikacja email, COPPA)
- **phpBB3**: `ucp/ucp_register.php`, `acp/acp_captcha.php`
- **Problem**: M3 pokrywa tylko logowanie JWT. Brak endpointu rejestracji, CAPTCHA service, pipeline aktywacji emailem, opcji zatwierdzenia przez admina, COPPA gate.
- **Dlaczego krytyczne**: Forum bez rejestracji nie może nabywać użytkowników.

#### Wychodzący system emaili (messenger + kolejka)
- **phpBB3**: `functions_messenger.php`, klasa `messenger`, kolejka w DB + cron flush
- **Problem**: M8 (powiadomienia) to polling HTTP. Brak zaplanowanego pipeline'u emailowego.
- **Dlaczego krytyczne**: Reset hasła, aktywacja konta, subskrypcje tematów — wszystkie wymagają emaila. Nawet minimalne forum potrzebuje resetu hasła.

#### Cron / framework zaplanowanych zadań
- **phpBB3**: `web/cron.php`, `src/phpbb3/forums/cron/` (manager + tasks)
- **Problem**: Brak jakiegokolwiek milestone'a dla cron infrastructure. M6.6 (counter flush) i M1.9 (Redis) są od tego zależne.
- **Dlaczego krytyczne**: Kolejka emaili nigdy się nie opróżni, liczniki nigdy nie zostaną zsynchronizowane, stare sesje nigdy nie zostaną wyczyszczone.

#### Śledzenie przeczytanych postów (znaczniki „nowych postów")
- **phpBB3**: `TOPICS_TRACK_TABLE`, `FORUMS_TRACK_TABLE`, tracking cookie dla gości
- **Problem**: M6 buduje dane wątków/postów, ale brak warstwy read-state. Nie ma ikon „nowy", nie ma widoku „nowe posty".
- **Dlaczego krytyczne**: Jedna z najczęściej używanych funkcji UX; decyduje o codziennym use case każdego zalogowanego użytkownika.

#### System awatarów
- **phpBB3**: `src/phpbb3/forums/avatar/` (driver + manager + helper), upload/remote/Gravatar
- **Problem**: M5b daje upload pliku, ale brak user-facing flow awatarów (wybór drivera, resize, powiązanie z kontem).
- **Dlaczego krytyczne**: Wizualna tożsamość użytkownika w każdym poście — oczekiwana przez użytkowników.

#### CAPTCHA / system antyspamowy
- **phpBB3**: `src/phpbb3/forums/captcha/` (Q&A, GD, reCAPTCHA), `acp_captcha.php`
- **Problem**: Brak w żadnym milestone'ie — ani rejestracja, ani guest posting nie mają antyspamu.
- **Dlaczego krytyczne**: Bez bramki antyspamowej boty rejestracyjne to natychmiastowy problem produkcyjny.

#### Watchowanie tematów/forów — subskrypcje email
- **phpBB3**: `TOPICS_WATCH_TABLE`, `FORUMS_WATCH_TABLE`, integracja z funkcjami postowania
- **Problem**: M8 pokrywa powiadomienia HTTP polling. Email-based watch to odrębna, nieplanowana funkcja.
- **Dlaczego krytyczne**: Główny sposób, w jaki użytkownicy phpBB śledzą tematy bez codziennego odwiedzania forum.

---

### 2.2 Ważne (znaczące luki w feature parity)

#### Katalog użytkowników i profile publiczne
- **phpBB3**: `web/memberlist.php` (lista, profil, lista grupy, strona team, live search, formularz kontaktu z adminem)
- **Problem**: M2 buduje wewnętrzny serwis użytkowników, ale brak publicznego API katalogu / profilu.
- **Wpływ**: Fundamentalna funkcja społecznościowa każdego forum.

#### Preferencje użytkownika (język, strefa czasowa, format daty, styl)
- **phpBB3**: `ucp/ucp_prefs.php` (tryby: personal, post, display)
- **Problem**: M2 przechowuje kolumny preferencji w modelu, ale brak API UCP preferencji ani UI.
- **Wpływ**: Wymagane dla internacjonalizacji i wielojęzycznych społeczności.

#### RSS / Atom feeds
- **phpBB3**: `web/feed.php`, `src/phpbb3/forums/feed/` (feeds dla całego boardu, forum, tematu)
- **Wpływ**: Standardowa syndykacja treści; oczekiwana przez użytkowników i indeksery.

#### Extension / plugin system
- **Status**: ADR wybrał model (macrokernel: events + decorators) — brak milestone'a implementacji
- **Wpływ**: Ekosystem phpBB zależy od rozszerzeń. Bez frameworku phpBB4 jest systemem zamkniętym.

#### Podpisy użytkownika (signatures)
- **phpBB3**: `ucp/ucp_profile.php` (tryb sig), kolumna `user_sig` w `phpbb_users`
- **Problem**: M11 pokryje rendering BBCode, ale nie mechanizm podpisów.
- **Wpływ**: Kulturowy element społeczności forumowych.

#### Członkostwo w grupach (UCP)
- **phpBB3**: `ucp/ucp_groups.php` (join/leave, prośby, zmiana domyślnej grupy, zarządzanie przez liderów)
- **Problem**: M2 ma model danych grup, brak API interakcji UCP.
- **Wpływ**: Organizacja społeczności przez grupy to core phpBB.

#### Zarządzanie załącznikami (UCP)
- **phpBB3**: `ucp/ucp_attachments.php` (lista + usuwanie własnych załączników, quota)
- **Problem**: M5b daje serwis storage, brak user-facing „moje załączniki".
- **Wpływ**: Prywatność i zarządzanie przestrzenią przez użytkownika.

---

### 2.3 Drobne (quality-of-life)

| Funkcja | phpBB3 lokacja | Dlaczego ważne |
|---|---|---|
| Who's Online | `web/viewonline.php` | Przejrzystość społeczna; widoczne na głównej stronie |
| Rangi użytkowników | `acp/acp_ranks.php`, `RANKS_TABLE` | Gamifikacja; tytuły oparte na liczbie postów |
| Znajomi / Wrogowie (Zebra) | `ucp/ucp_zebra.php`, `ZEBRA_TABLE` | Bezpieczeństwo; ukrywanie treści od natrętów |
| Szkice postów | `DRAFTS_TABLE`, `web/posting.php` | Zapobiega utracie długich wpisów |
| System ostrzeżeń | `mcp/mcp_warn.php` | Strukturalna disciplina moderacyjna z punktami |
| Notatki moderatora na kontach | `mcp/mcp_notes.php` | Pamięć moderacyjna między rotacjami staffu |
| Cenzura słów | `acp/acp_words.php` (regex rules) | Wymóg prawny/polityki community |
| Zarządzanie pakietami językowymi | `acp/acp_language.php` | Niezbędne dla społeczności nieanglojęzycznych |
| FAQ / pomoc BBCode | `web/faq.php`, `src/phpbb3/forums/help/` | Zmniejsza koszt wsparcia nowych użytkowników |
| Masowe emaile (ACP) | `acp/acp_email.php` | Komunikacja adminów z bazą użytkowników |
| Formularz kontaktu z adminem | `web/memberlist.php?mode=contactadmin` | Dostępność dla niezarejestrowanych |
| Boty / zarządzanie pająkami | `acp/acp_bots.php` | SEO i statystyki |
| Pruning forum i użytkowników | `acp/acp_prune.php` | Housekeeping adminów |

---

## 3. Rekomendowane uzupełnienia planu kamieni milowych

### M15 — Rejestracja, Email & CAPTCHA *(Prerequisit do pełnego MVP)*

**Uzasadnienie**: Blokuje nabywanie użytkowników. Email jest wymagany przez reset hasła, aktywację, subskrypcje.

**Proponowany zakres**:
- `phpbb\registration` — endpoint `POST /api/v1/users/register`, terms acceptance, COPPA gate
- `phpbb\captcha` — pluggable CAPTCHA service (Q&A, reCAPTCHA v3)
- `phpbb\mailer` — serwis emaili (outbound queue + DB flush job), szablony per-język
- Integracja z M3 (Auth) — aktywacja konta przez email, resend activation
- Testy PHPUnit + E2E

---

### M16 — Cron / Background Task Framework *(Prerequisit dla M15 emaili i M6.6)*

**Uzasadnienie**: Kolejka emaili, flush liczników (M6.6), pruning sesji, reindeksacja wyszukiwania — wszystkie zależą od crona.

**Proponowany zakres**:
- `phpbb\cron` — CronManager + interfejs CronTask (pluggable)
- Wbudowane taski: flush email queue, prune sessions, prune read-tracking, garbage-collect drafts
- Endpoint `GET /api/v1/cron/trigger` (lub CLI command) z opcją real cron job
- Integracja z Symfony Scheduler lub własnym runner

---

### M17 — Read Tracking & Subskrypcje *(Core UX — codzienne użycie forum)*

**Uzasadnienie**: Znaczniki „nowych postów" i email watching to dwie najczęściej używane funkcje codziennie.

**Proponowany zakres**:
- `phpbb\tracking` — `TOPICS_TRACK_TABLE`, `FORUMS_TRACK_TABLE`, cookie fallback dla gości
- REST API: `POST /api/v1/topics/{id}/read`, `GET /api/v1/users/me/unread`
- `phpbb\subscription` — watch topic/forum, `TOPICS_WATCH_TABLE`, `FORUMS_WATCH_TABLE`
- Integracja z M15 (mailer) do wysyłki powiadomień email
- Testy PHPUnit + E2E

---

### M18 — User Profile & Community Features *(Feature parity — profil publiczny)*

**Uzasadnienie**: Katalog, profil publiczny, awatary, preferencje — brakujące puzzle po M2.

**Proponowany zakres**:
- `phpbb\profile` — API profilu publicznego `GET /api/v1/users/{id}/profile`
- `phpbb\avatar` — driver-based avatar system (upload, remote URL, Gravatar); resize via M5b Storage
- `phpbb\memberlist` — katalog użytkowników z paginacją, filtrami, stroną team/moderatorów
- UCP Preferences API — `PATCH /api/v1/users/me/preferences` (strefa czasowa, język, styl, powiadomienia)
- Podpisy użytkownika (integracja z M11 BBCode)
- Testy PHPUnit + E2E

---

### M19 — RSS Feeds & Discovery *(Syndykacja treści)*

**Uzasadnienie**: Standardowa funkcja webowa; wspomaga SEO i external integrations.

**Proponowany zakres**:
- `phpbb\feed` — Atom/RSS control dla boardu, forum, tematu
- Endpoints: `GET /api/v1/feed/board`, `/feed/forums/{id}`, `/feed/topics/{id}`
- RFC 4287 compliance
- Testy PHPUnit + E2E (content negotiation)

---

### M20 — Extension System Framework *(Ekosystem phpBB4)*

**Uzasadnienie**: ADR zdecydował (macrokernel: events + decorators), ale implementacja nie istnieje. Bez frameworku phpBB4 jest systemem zamkniętym.

**Proponowany zakres**:
- `phpbb\extension` — ExtensionManager, interfejs Extension, lifecycle (enable/disable/migrate)
- Event integration (Symfony EventDispatcher + wewnętrzne DomainEvents)
- Decorator pattern dla serwisów
- ACP extension manager (w ramach M14 lub osobny)
- Minimal example extension jako test integracyjny

---

### Zadania dodatkowe do istniejących milestones

| Milestone | Rekomendowane dodanie |
|---|---|
| **M12** (Moderation) | Warning system z punktami (`mcp/mcp_warn.php`), notatki moderatora (`mcp_notes.php`) |
| **M10** (React SPA) | Podzielić na M10a (design system + auth) i M10b (forum views + notifications UI) |
| **M14** (Admin) | Word censorship (`acp_words.php`), language pack management (`acp_language.php`) |
| **M13** (Config) | Dodać task E2E (brak w obecnym planie) |

---

## 4. Podsumowanie

### Liczby

| Kategoria | Liczba |
|---|---|
| ⏳ zadań w ukończonych kamieniach (M0–M8) | **6** |
| ⏳ zadań w M9–M14 | **~37** |
| ⏳ zadań bezpieczeństwa i load testów | **22** |
| ⏳ zadań cross-cutting (X.2, X.3, X.4, X.5) | **4** |
| **Łączna liczba zadań ⏳ w planie** | **~69** |
| Obszarów funkcjonalnych całkowicie poza planem | **27** |
| — z czego HIGH impact | **10** |
| — z czego MEDIUM impact | **12** |
| — z czego LOW impact | **5** |

---

### Top 5 priorytetów dla następnego cyklu planowania

1. **M15 — Rejestracja + Email + CAPTCHA**
   Forum nie może nabywać użytkowników bez przepływu rejestracji. Reset hasła to hard requirement.

2. **M16 — Cron / Background Task Framework**
   Kolejka emaili (M15), flush liczników (M6.6), Redis (M1.9), read-tracking pruning — wszystkie blokowane bez crona.

3. **S.1 + S.2 — Zaległe przeglądy bezpieczeństwa**
   22 zadania dotyczące JWT, IDOR, XSS, upload, SQL injection. Ryzyko narasta z każdym nowym endpointem.

4. **M17 — Read Tracking & Subskrypcje** (równolegle z M9 lub po)
   Znaczniki „nowych postów" to najczęściej używana funkcja UX; bez nich forum wygląda jak beta.

5. **M18 — Profil użytkownika & Awatary** (równolegle z M10)
   Wizualna tożsamość i katalog użytkowników kończą picture po M2. Wymagane do sensownego M10 (React SPA).

---

### Mapa zależności (priorytety)

```
M15 (Email + Rejestracja + CAPTCHA)
  └── wymaga → M16 (Cron)
  └── wpływa na → M17 (Subskrypcje email)
  └── wpływa na → M18 (Awatary — email weryfikacja)

M16 (Cron)
  └── odblokuje → M6.6 (counter flush)
  └── odblokuje → M1.9 (Redis — opcjonalny)
  └── odblokuje → M9 search reindex (przyszłość)

S.1 + S.2 (Security)
  └── powinny poprzedzać → M9, M10 (nowe powierzchnie ataku)
```

---

*Raport wygenerowany na podstawie: `MILESTONES.md` (stan na 2026-04-25), analiza kodu `web/`, `src/phpbb3/common/`, `src/phpbb3/forums/`.*
