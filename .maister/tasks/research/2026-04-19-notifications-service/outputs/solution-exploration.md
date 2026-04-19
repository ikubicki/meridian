# Solution Exploration: phpBB Notifications Service

**Research Question**: Jak zaprojektować usługę `phpbb\notifications` odpowiedzialną za powiadomienia do użytkowników, wysyłkę emaili oraz powiadomienia w aplikacji frontendowej, z lekkim REST API endpointem informującym o ilości i najnowszych powiadomieniach? (Facebook-style)

**Date**: 2026-04-19
**Research Type**: Mixed (Technical + Requirements + Literature)
**Research Confidence**: High

---

## Problem Reframing

### Research Question

phpBB posiada dojrzały `notification_manager` (21 typów, 3 metody dostarczania, 4 tabele DB) obsługujący cały write-path — ale zero REST API oraz zero frontend polling. Użytkownik widzi powiadomienia dopiero po przeładowaniu strony. Celem jest zaprojektowanie lekkiej warstwy usługowej, która eksponuje istniejące dane powiadomień przez REST API z Facebook-style badge & dropdown UX (bell icon → count → dropdown z listą).

### How Might We Questions

1. **HMW dostarczać aktualizacje powiadomień do frontendu** bez wymagania przeładowania strony, przy zachowaniu PHP-FPM + Nginx stack?
2. **HMW grupować powiadomienia Facebook-style** ("John, Jane, and 3 others replied") bez przepisywania istniejącej logiki coalescence w `notification_manager`?
3. **HMW cache'ować dane powiadomień** żeby polling endpoint (wywoływany co 30s na aktywnego usera) nie obciążał bazy danych?
4. **HMW zintegrować nową usługę** z istniejącym `notification_manager` minimalizując duplikację logiki (events, deduplication, method dispatch)?
5. **HMW zaprojektować odpowiedź JSON API** żeby frontend mógł łatwo renderować bell badge, dropdown z listą i responder rollup text?
6. **HMW zintegrować frontend polling** z istniejącą jQuery-based aplikacją phpBB, zapewniając niską latencję przy minimalnym obciążeniu sieciowym?

---

## Explored Alternatives

### Decision Area 1: Real-Time Delivery Strategy

**HMW**: Jak dostarczać aktualizacje powiadomień do frontendu bez przeładowania strony?

#### Alternative 1A: HTTP Polling (30s) z `Last-Modified`/`304` Optimization

Klient wykonuje `GET /api/v1/notifications/count` co 30 sekund z nagłówkiem `If-Modified-Since`. Serwer porównuje timestamp ostatniego powiadomienia użytkownika i zwraca `304 Not Modified` gdy nic się nie zmieniło — zero body, minimalne bandwidth. Przy zmianie: pełny `200 OK` z nowym count i `Last-Modified`.

**Strengths**:
- Zero zmian infrastrukturalnych — działa z istniejącym PHP-FPM + Nginx
- Każdy request jest stateless i bezstanowy — skaluje się liniowo
- `304` eliminuje ~80% transferu danych (proven: GitHub API)
- 30s cache TTL = naturalny alignment z polling interval
- ~33 req/s dla 1000 aktywnych userów @ 30s — trywialne dla Nginx

**Weaknesses**:
- 15-30s latencja (średnio) zanim user zobaczy nowe powiadomienie
- Nadal ~33 req/s "pustych" requestów per 1000 userów (choć minimalny koszt)
- Brak natychmiastowego push — nie nadaje się do scenariuszy real-time chat

**Best when**: Aplikacja forumowa z umiarkowanym ruchem; akceptowalna latencja 15-30s; brak budżetu na dodatkową infrastrukturę; istniejący PHP-FPM stack.

**Evidence**: external-patterns Section 3.2 (GitHub polling), synthesis Section 2.1 (proven pattern), codebase-integration (cache alignment).

---

#### Alternative 1B: Long Polling

Klient wysyła `GET /api/v1/notifications/count` — serwer trzyma połączenie otwarte do 30s lub do pojawienia się nowego powiadomienia, wtedy natychmiast odpowiada. Klient po otrzymaniu odpowiedzi natychmiast wysyła kolejny request. Daje quasi-real-time dostarczanie (~1-5s latencja).

**Strengths**:
- Niższa latencja niż polling (~1-5s vs 15-30s)
- Brak dodatkowej infrastruktury (nadal HTTP)
- Natychmiastowy push gdy nowe powiadomienie pojawi się w trakcie oczekiwania
- Mniejszy ruch niż polling (request wysyłany tylko po odpowiedzi)

**Weaknesses**:
- Każde otwarte połączenie blokuje PHP-FPM worker — przy 50 workerach i 50 aktywnych userach brak workerów dla normalnego ruchu
- Wymaga mechanizmu sygnalizacji (DB polling w pętli, shared memory, Redis pub/sub) po stronie serwera
- PHP nie ma natywnego async — `sleep()` + `SELECT COUNT(*)` w pętli jest marnotrawstwem zasobów
- Problematyczne z load balancerami i proxy timeout (Nginx default 60s)

**Best when**: Niski ruch (< 20 concurrent users); potrzebna niższa latencja; gotowość na tuning PHP-FPM pool size.

**Evidence**: external-patterns Section 3.3 (PHP limitations), synthesis Section 2.3 (PHP-FPM constraint).

---

#### Alternative 1C: Server-Sent Events (SSE) via Separate Service

Osobny lekki serwis (Node.js/Go) utrzymuje persistent SSE connections z klientami. PHP przy tworzeniu powiadomienia publikuje event do Redis pub/sub. SSE gateway subskrybuje Redis i pushuje `text/event-stream` do odpowiednich klientów. Sub-sekundowa latencja bez obciążania PHP workerów.

**Strengths**:
- Sub-sekundowa latencja — prawdziwy push, nie polling
- SSE ma wbudowany auto-reconnect w przeglądarce
- Named events (`notification_count`, `new_notification`) — bogate API
- PHP workerzy nie są blokowane — gateway jest osobnym procesem
- Event IDs umożliwiają resumption po disconnect

**Weaknesses**:
- Wymaga dodatkowej infrastruktury: Redis + SSE gateway service (Node.js/Go)
- Deployment complexity — Docker compose z 3 usługami zamiast 1
- HTTP/1.1: max 6 SSE connections per domain per browser (wszystkie taby!)
- Wymaga shared worker lub service worker do multipleksowania
- Utrzymanie dwóch runtime'ów (PHP + Node.js/Go) zwiększa maintenance burden

**Best when**: Wymaganie real-time (< 1s latencja); gotowość na dodatkową infrastrukturę; duże forum z wieloma aktywnymi userami.

**Evidence**: external-patterns Section 3.4 (SSE), MDN EventSource API, synthesis Section 2.3 (resolved contradiction: SSE needs async runtime).

---

#### Alternative 1D: WebSocket via Ratchet/Swoole

Dedykowany WebSocket server (Ratchet na ReactPHP lub Swoole) utrzymuje persistent full-duplex connections. PHP aplikacja komunikuje się z WS serverem przez Redis lub wewnętrzny HTTP endpoint. Najniższa możliwa latencja, bidirectional communication.

**Strengths**:
- Najniższa latencja (< 100ms)
- Bidirectional — umożliwia typing indicators, presence, push acknowledgements
- Jeden persistent connection per tab (vs 6 SSE connections limit)
- Naturalny upgrade path do chat/messaging

**Weaknesses**:
- **Overkill** — powiadomienia to one-way (server → client), bidirectional niepotrzebny
- Wymaga osobnego WS server process (Ratchet/Swoole)
- Problemy z corporate proxies, firewallami (nie przechodzą przez wiele proxy)
- PHP + WebSocket = niszowy stack, mniejsze community, trudniejsze debugging
- Znaczna złożoność: connection management, heartbeat, reconnect logic, state sync

**Best when**: Planowane rozszerzenie o real-time chat; istniejące doświadczenie z Swoole/ReactPHP; pełna kontrola nad infrastrukturą.

**Evidence**: external-patterns Section 3.5 (WebSocket verdict: overkill for notifications), synthesis Section 2.1 (PHP compatibility assessment).

---

### Decision Area 2: Notification Aggregation

**HMW**: Jak grupować powiadomienia Facebook-style bez przepisywania istniejącej logiki?

#### Alternative 2A: Leverage Existing Write-Time Aggregation (Responders)

Wykorzystanie istniejącego mechanizmu `post.php::add_responders()`, który koalesce'uje do 25 responderów per topic notification w `notification_data`. API deserializuje te dane i formatuje "John, Jane, and 3 others replied". Dla typów bez responderów (PM, quote) — flat display bez grupowania.

**Strengths**:
- Zero nowej logiki agregacji — dane już istnieją w DB w polu `notification_data`
- Sprawdzony mechanizm (phpBB codebase, lines 400-463 post.php)
- 4000 chars guard + 25 responder cap zapobiegają unbounded growth
- Pokrywa główny use case (post reply to topic) — najczęstszy typ notyfikacji na forum
- API potrzebuje tylko `unserialize()` + format — prosta transformacja

**Weaknesses**:
- Ograniczone do typu `post` — inne typy (topic, quote, PM) nie mają responder coalescence
- Write-time grouping jest sztywne — nie można zmienić reguł grupowania bez migracji
- Jeśli user przeczyta powiadomienie, nowe odpowiedzi tworzą nowy rekord → nie kontynuują starego grupy
- Max 25 responderów, 4000 chars serialized — artificialne ograniczenia

**Best when**: Forum z dominacją topic reply notifications; akceptowalny brak grupowania dla PM/quote; v1 scope z minimal effort.

**Evidence**: codebase-notifications post.php Lines 400-463 (`add_responders()`), synthesis Insight 5 (write-time aggregation already solves).

---

#### Alternative 2B: Read-Time SQL GROUP BY Aggregation

Zapytanie SQL z `GROUP BY notification_type_id, item_parent_id` (topic_id) przy odczycie. Grupuje wszystkie nieprzeczytane powiadomienia tego samego typu na ten sam temat, niezależnie od tego czy były write-time coalesced. Zlicza aktorów i zwraca najnowsze 2 nazwy per grupa.

**Strengths**:
- Grupuje WSZYSTKIE typy notyfikacji, nie tylko post replies
- Elastyczne — reguły grupowania w SQL/PHP, zmieniane bez migracji
- Działa na istniejących danych bez modyfikacji write path
- Możliwość grupowania po dowolnej wymiarze (topic, forum, type)
- Nie ograniczone do 25 responderów — prawdziwy count z bazy

**Weaknesses**:
- `GROUP BY` + `GROUP_CONCAT` na tabeli `phpbb_notifications` wymaga composite index z `notification_type_id` + `item_parent_id`
- Dodatkowa złożoność SQL: subqueries dla top-N aktorów per grupa
- Niekompatybilne z prostym offset-based pagination (grupy ≠ rows)
- Cache invalidation trudniejsza — zmiana jednego powiadomienia invalide'uje wiele grup
- `notification_data` is `serialize()`d → nie można GROUP_CONCAT na structured data bez PHP post-processing

**Best when**: Potrzeba grupowania poza post replies (topic notifications per forum, reactions per post); gotowość na złożoność SQL; v2+ z pełnym Facebook-style grouping.

**Evidence**: external-patterns Section 4.1 (read-time vs write-time comparison), synthesis Section 2.3 (resolved contradiction: forum volumes don't warrant dual approach).

---

#### Alternative 2C: Hybrid — Write-Time Responders + Read-Time Type Grouping

Połączenie obu podejść: write-time `add_responders()` dla post replies (istniejący mechanizm) plus lekkie read-time grupowanie w service layer (PHP) dla typów bez responderów. Service sortuje powiadomienia, grupuje sąsiednie tego samego typu+target, i zwraca zagnieżdżoną strukturę.

**Strengths**:
- Najlepsze z obu światów — write-time dla hot path (posts), read-time dla reszty
- Brak zmian w write path — istniejący `add_responders()` działa dalej
- Read-time logika w PHP (nie SQL) — łatwa do zmiany i testowania
- Pozwala na progressive enhancement: v1 z write-time only, v2 dodaje read-time
- Flexible grouping dimensions w PHP code

**Weaknesses**:
- Dwa systemy agregacji do utrzymania (write-time w legacy, read-time w nowym service)
- Read-time w PHP zwiększa processing time per request (choć cached)
- Trudniejsze testowanie — kombinacja istniejącej i nowej logiki
- Cache key design komplikowany (zmiana jednego typu wpływa na grupę)

**Best when**: Iteracyjne podejście — start z write-time, rozszerzenie when needed; potrzeba grupowania niektórych dodatkowych typów.

**Evidence**: synthesis Section 2.3 (contradiction resolved: hybrid approach), external-patterns Section 4.1 (Approach A: read-time recommendation for phpBB).

---

### Decision Area 3: Cache Strategy

**HMW**: Jak cache'ować dane powiadomień żeby polling endpoint nie obciążał bazy?

#### Alternative 3A: Tag-Aware Pool z `getOrCompute()` + Event Invalidation

Dedykowany cache pool `notifications` z `TagAwareCacheInterface`. Count i lista cache'owane z 30s TTL. Tag per user (`user_notifications:{userId}`). Na zapis/odczyt — `invalidateTags()` przez `CacheInvalidationSubscriber` odsłuchujący Symfony events. Polling trafia w cache 90% czasu.

**Strengths**:
- 90%+ cache hit rate — większość requestów to czysty cache read (< 1ms)
- Spójne z zaprojektowanym cache service (pool factory, `getOrCompute()`, tags)
- Auto-invalidacja: event dispatched po markRead/markAll/new notification
- 30s TTL = natural alignment z polling interval — stale entries auto-expire
- Version-based tag invalidation eliminuje race conditions
- Łatwa do dodania — DI wiring + jeden subscriber

**Weaknesses**:
- Cache service jeszcze nie zaimplementowany — API może się różnić od designu
- 30s worst-case latencja na invalidation (poll + TTL miss window)
- Cache miss na pierwszym request per user — cold start
- Cache key explosion: per user × per page (20 limit × offset combinations)
- Tag invalidation może być kosztowna jeśli user ma wiele cache entries

**Best when**: Cache service będzie zaimplementowany; potrzeba consistent architectural pattern; umiarkowany traffic.

**Evidence**: synthesis Section 2.2 (Cache Service → Notification Caching mapping), codebase-integration Section 1 (getOrCompute design), research-report Section 5.4 (cache architecture).

---

#### Alternative 3B: Denormalized Count na User Record

Przechowywanie `notification_unread_count` bezpośrednio w wierszu `phpbb_users`. Inkrementacja atomowa (`UPDATE users SET count = count + 1 WHERE user_id = ?`) przy tworzeniu, dekrementacja przy markRead, reset przy markAllRead. Count endpoint czyta z jednej kolumny.

**Strengths**:
- Najszybszy możliwy read — jedna kolumna z już załadowanego user record
- 99%+ "cache hit" (count jest in-memory po załadowaniu usera)
- Brak oddzielnego cache systemu — mniej moving parts
- Atomowe operacje `count + 1` / `count - 1` — thread-safe
- phpBB już robi to w niektórych miejscach (user_notifications count pattern)

**Weaknesses**:
- **Count drift** — przy race conditions, failed transactions, edge cases count może się rozjechać z rzeczywistością
- Wymaga periodic reconciliation job (`UPDATE users SET count = (SELECT COUNT(*) FROM notifications WHERE ...)`)
- Schema migration (ALTER TABLE phpbb_users)
- Każdy write do notifications = dodatkowy write do users — zwiększa lock contention na users table
- Nie cache'uje listy (count only) — list endpoint nadal wymaga query/cache

**Best when**: Ekstremalnie wysoki load na count endpoint; gotowość na reconciliation cron; v2 optimizacja po walidacji traffic patterns.

**Evidence**: external-patterns Section 5.2 (denormalized count), synthesis Insight 2 (count endpoint is critical path).

---

#### Alternative 3C: Simple In-Memory Array Cache per Request + File Cache (60s TTL)

Użycie istniejącego `\phpbb\cache\driver\*` (file/memcached/redis) z prostym kluczem per user. Brak tagged pools, brak event invalidation — proste `$cache->get()` / `$cache->put()` z 60s TTL. Invalidation tylko przez TTL expiry.

**Strengths**:
- Działa z istniejącym cache driver — zero nowych dependencies
- Ekstremalnie prosty do implementacji (~10 linii kodu)
- Nie wymaga cache service, event subscriber, tag infrastructure
- 60s TTL = wystarczająco fresh dla forum use case
- Fallback strategy jeśli cache service nie jest gotowy

**Weaknesses**:
- Brak explicit invalidation — user czeka do 60s na aktualizację po markRead
- Brak tagged pools — nie ma sposobu na selective invalidation
- File-based cache driver może mieć filesystem overhead przy wielu userach
- Niespójne z architektonicznym wzorcem cache service (tag-aware pools)
- Stale count po markAllRead — user klika "mark all" i nadal widzi count = 5

**Best when**: Prototyp/MVP; cache service nie istnieje; potrzeba szybkiego wdrożenia z minimalnymi dependencies.

**Evidence**: external-patterns Section 5.1 (phpBB cache driver), synthesis Section 3.2 (anti-pattern: DB query on every page load).

---

#### Alternative 3D: Redis Atomic Counters (INCR/DECR)

Dedykowane Redis klucze per user (`notification:count:42`). `INCR` przy nowym powiadomieniu, `DECR` przy markRead, `SET 0` przy markAllRead. Count endpoint = `GET notification:count:42` (~0.1ms). Lista cache'owana jako serialized array z 30s TTL.

**Strengths**:
- Atomowe operacje — zero race conditions na count
- ~0.1ms read — najszybszy możliwy cache
- Nie wymaga tag invalidation — counter jest źródłem prawdy (nie cache derived value)
- Natural fit dla count + list separation
- Skalowalny do dowolnej liczby userów

**Weaknesses**:
- **Wymaga Redis** — dodatkowa infrastruktura nie obecna w standardowym phpBB deploy
- Count może driftować jeśli INCR/DECR nie jest atomic z DB write (two-phase operation)
- Dwa źródła prawdy: DB `notification_read` + Redis counter
- Redis failure = brak count display (fallback potrzebny)
- Niespójne z zaprojektowanym cache pattern (TagAwareCacheInterface)

**Best when**: Redis już dostępny w infra; ekstremalnie wysoki load; potrzeba sub-millisecond count reads.

**Evidence**: external-patterns Section 5.1 (Redis counter pattern), synthesis Section 2.4 (75% confidence on polling performance).

---

### Decision Area 4: Service Architecture

**HMW**: Jak zintegrować nową usługę z `notification_manager` minimalizując duplikację?

#### Alternative 4A: Hybrid Facade — New Repository for Reads, Manager for Writes

Nowy `NotificationService` jako fasada z dwoma ścieżkami: READ path przez nowy `NotificationRepository` (PDO, optimized queries, cached), WRITE path delegowany do legacy `notification_manager` (mark-read, mark-all, zachowując events/dedup). Service orkiestruje cache + repo + manager.

**Strengths**:
- Optimized READ path — `countUnread()` to single SQL query (vs manager's type instantiation)
- Write logic nie jest duplikowana — manager handles events, deduplication, method dispatch
- Spójne z auth service pattern (wraps legacy for reads, delegates writes)
- Jasny podział odpowiedzialności (Repository reads, Manager writes, Service orchestrates)
- Cache layer naturalnie wchodzi między Service a Repository

**Weaknesses**:
- Dwa "kanały" dostępu do tych samych danych — potencjalna niespójność
- Repository queries muszą replikować manager's filtering (enabled types, user availability)
- Legacy event bridge: jak notyfikować service o nowym powiadomieniu stworzonym przez manager bezpośrednio?
- Repository wymaga utrzymania osobnych SQL queries (drift risk vs manager's queries)

**Best when**: Standardowy przypadek — potrzeba optimized reads bez przepisywania write logic; istniejące service patterns w codebase.

**Evidence**: synthesis Insight 1 (Manager is a Feature), research-report Section 5.2 (hybrid design), auth service precedent.

---

#### Alternative 4B: Pure Delegation — All Through Manager

Nowy `NotificationService` deleguje WSZYSTKO do `notification_manager`. Count = `load_notifications('board', ['count_unread' => true, 'limit' => 0])`. List = `load_notifications('board', ['limit' => 20, 'start' => 0])`. Mark = `mark_notifications_by_id()`. Zero nowego SQL.

**Strengths**:
- Zero duplikacji — jedna ścieżka dostępu do danych
- Manager obsługuje type availability checking, enabled filtering automatycznie
- Każda zmiana w manager automatycznie propaguje się do API
- Najprostszy do implementacji — thin wrapper + JSON transformation
- Niski risk — korzysta z przetestowanego kodu

**Weaknesses**:
- **Performance**: `load_notifications()` z `count_unread: true` instantiuje type classes i sprawdza `is_available()` — 10-100x wolniejsze niż raw `SELECT COUNT(*)`
- Manager injektuje pełny `ContainerInterface` — antipattern propagowany do nowej warstwy
- Count endpoint (hot path, 33 req/s) byłby zbyt wolny nawet z cache (long cold-start)
- Manager API nie obsługuje `Last-Modified` concept — trudna integration z `304` optimization
- Tight coupling z legacy code — utrudnia przyszły refactoring

**Best when**: Ekstremalnie krótki deadline; count endpoint nie jest hot path; akceptowalna latencja > 100ms na cache miss.

**Evidence**: codebase-notifications Section 2 (`load_notifications()` flow — 3 SQL queries + type instantiation), synthesis Section 2.3 (contradiction: manager overhead on count).

---

#### Alternative 4C: Decorator Pattern — Wrap Manager with Cache/Transform Layer

Implementacja `NotificationServiceDecorator` implementującego ten sam interface co manager lub nowy `NotificationServiceInterface`. Dekorator cachuje wyniki manager'a, transformuje output `prepare_for_display()` → JSON, i dodaje `Last-Modified` metadata. Manager jest inner service.

**Strengths**:
- Czysta separacja warstw — decorator is transparent
- Manager API unchanged — dekorator dodaje cross-cutting concerns (cache, transform)
- Testable — mock inner service, test decorator logic separately
- Łatwe dodawanie nowych decoratorów (logging, metrics, rate limiting)
- Spójne z Symfony patterns (HttpKernel events are decorator-like)

**Weaknesses**:
- Manager API nie jest zaprojektowane dla decoration — `load_notifications()` zwraca complex array, nie typed objects
- Dekorator musi rozumieć wewnętrzną strukturę manager's output (tight coupling mimo loose interface)
- Nadal ma performance problem manager API na count (dekorator cache'uje wynik, ale cold start jest wolny)
- Over-engineering dla prostego use case — dodaje abstrakcyjną warstwę bez realnej potrzeby extension
- phpBB codebase nie używa decorator pattern — niespójne z existing patterns

**Best when**: Potrzeba łatwego extension point (logging, metrics); duży zespół z formal architecture standards.

**Evidence**: synthesis Section 3.1 (established patterns: facade over legacy, NOT decorator).

---

#### Alternative 4D: Full Rewrite — Bypass Manager Entirely

Nowy `NotificationService` operuje bezpośrednio na DB (PDO), implementuje własną logikę mark-read, count, list. `notification_manager` pozostawiany wyłącznie dla write-path (tworzenie nowych powiadomień). Reads go 100% through new service.

**Strengths**:
- Pełna kontrola nad query optimization — zero overhead z type instantiation
- Clean code — nowy service w 100% PSR-4 namespace, typed, testable
- Brak zależności od legacy manager API dla reads
- Performance optymalny — raw PDO z prepared statements

**Weaknesses**:
- Duplikacja filtering logic: `notification_type_enabled` check, user availability, etc.
- Brak event dispatching na marks — manager's events (`core.notification_manager_*`) pominięte
- Przyszłe zmiany w manager's logic (np. nowy type) wymagają synchronizacji w obu miejscach
- Zwiększone test coverage — testować logikę w dwóch miejscach
- Ryzyko niespójności: manager shows different results than service

**Best when**: Manager API jest fundamentalnie niewystarczające; planowany pełny replacement manager'a; greenfield rewrite.

**Evidence**: synthesis Insight 1 (Manager is a Feature — "should wrap, not replace"), research-report Section 6 Decision 2 (full rewrite = High complexity).

---

### Decision Area 5: API Response Design

**HMW**: Jak zaprojektować odpowiedź JSON API żeby frontend mógł renderować bell badge i dropdown?

#### Alternative 5A: Transformed JSON z Embedded Responders (Flat List)

Płaska lista powiadomień, każde z embedded `responders[]` array i `responder_count`. `prepare_for_display()` → strip HTML → add type/time/responders → czysty JSON. Frontend sam buduje "John, Jane, and 3 others" text z responders array.

```json
{
  "notifications": [
    {
      "id": 1234,
      "type": "notification.type.post",
      "read": false,
      "time": 1713520200,
      "time_iso": "2026-04-19T10:30:00+00:00",
      "title": "john_doe replied to your topic \"New Features\"",
      "url": "/viewtopic.php?t=789&p=1234#p1234",
      "avatar_url": "/images/avatars/upload/avatar_42.jpg",
      "style_class": "notification-post",
      "reference": "Re: New Features",
      "forum": "General Discussion",
      "responders": [
        { "user_id": 42, "username": "john_doe" },
        { "user_id": 55, "username": "jane_smith" }
      ],
      "responder_count": 5
    }
  ],
  "unread_count": 12,
  "total": 150
}
```

**Strengths**:
- Proste — płaska lista, łatwa do renderowania (`notifications.forEach(...)`)
- Responders array daje frontendowi pełną elastyczność formatowania
- `prepare_for_display()` jest naturalnym bridge (array → JSON transform)
- Spójne z existing API patterns (resource-keyed flat JSON)
- Oddzielny `unread_count` + `total` meta — frontend ma kompletne info

**Weaknesses**:
- Frontend musi implementować actor rollup formatting ("X and N others")
- Notyfikacje bez responderów mają pusty array — inconsistent structure
- `title` zawiera HTML lub stripped HTML — frontend musi obsłużyć oba
- Brak HATEOAS links (mark-read URL, next page) — coupling z URL knowledge

**Best when**: Frontend jest prosty (jQuery); potrzeba elastyczności w rendering; v1 scope.

**Evidence**: research-report Section 5.3 (endpoint specification), synthesis Insight 3 (`prepare_for_display()` is JSON bridge).

---

#### Alternative 5B: Server-Rendered Formatted Text z Actor Rollup

Serwer robi pełne formatowanie na backendzie: `"John, Jane, and 3 others replied to your topic"` jako gotowy string. Frontend wyświetla `formatted_text` bez żadnej logiki. Responders nadal dostępne jako opcjonalny nested data.

```json
{
  "notifications": [
    {
      "id": 1234,
      "type": "notification.type.post",
      "read": false,
      "time_iso": "2026-04-19T10:30:00+00:00",
      "formatted_text": "john_doe, jane_smith, and 3 others replied to \"New Features\"",
      "url": "/viewtopic.php?t=789&p=1234#p1234",
      "avatar_url": "/images/avatars/upload/avatar_42.jpg",
      "style_class": "notification-post"
    }
  ],
  "unread_count": 12,
  "total": 150,
  "links": {
    "next": "/api/v1/notifications?offset=20&limit=20"
  }
}
```

**Strengths**:
- Zero logiki renderowania na frontendzie — wyświetl `formatted_text` i gotowe
- Lokalizacja (phpBB language system) on server — brak i18n complexity na frontend
- Mniejszy payload — brak nested responders array
- HATEOAS `links` — frontend nie musi znać URL structure
- Spójne z SSR heritage phpBB (server renders everything)

**Weaknesses**:
- **Mniej elastyczne** — frontend nie może zmienić formatu (np. "Jane and 3 more" vs "Jane, John, and 3 others")
- formatted_text nie podlega łatwej manipulacji (np. bold first username)
- Trudne do cache'owania per-locale — `formatted_text` zależy od user's language
- phpBB language system wymaga załadowania lang files — overhead per locale

**Best when**: Single-language forum; frontend bez JS framework (pure DOM manipulation); SSR-first approach.

**Evidence**: codebase-notifications base.php `prepare_for_display()` already returns formatted HTML, external-patterns Section 2.2 (GitHub returns structured data, not formatted text).

---

#### Alternative 5C: Grouped Response z Nested Structure

Powiadomienia zgrupowane po type + target, z nested `items[]` per grupa i group-level metadata (count, latest_time, is_read). Frontend renderuje grupy, nie flat list.

```json
{
  "groups": [
    {
      "group_key": "post:topic:789",
      "type": "notification.type.post",
      "target": { "topic_id": 789, "topic_title": "New Features" },
      "unread": true,
      "latest_time_iso": "2026-04-19T10:30:00+00:00",
      "actor_count": 5,
      "recent_actors": [
        { "user_id": 42, "username": "john_doe", "avatar_url": "..." },
        { "user_id": 55, "username": "jane_smith", "avatar_url": "..." }
      ],
      "items": [
        { "id": 1234, "time_iso": "..." },
        { "id": 1230, "time_iso": "..." }
      ]
    }
  ],
  "unread_count": 12,
  "total_groups": 45
}
```

**Strengths**:
- Natywny Facebook-style UX — grupy odpowiadają bezpośrednio dropdown items
- Frontend nie musi grupować — server dostarczył gotową strukturę
- `actor_count` + `recent_actors` → natychmiastowy rollup rendering
- Pagination po grupach (nie po items) — bardziej intuicyjne UX
- Extensible — łatwo dodać `"group_key"` based mark-all-read per topic

**Weaknesses**:
- Wymaga read-time grouping logic na backendzie (decision area 2)
- Złożona struktura JSON — frontend potrzebuje nested iteration
- Pagination jest per-group, nie per-item — offset-based nie działa (cursor needed)
- Cache invalidation trudniejsza — jedno powiadomienie zmienia cały group
- Over-engineering for v1 — phpBB's write-time grouping nie mapuje clean na group response

**Best when**: Pełne Facebook-style UX; read-time aggregation (Alternative 2B/2C) zaimplementowana; v2+ scope.

**Evidence**: external-patterns Section 2.1 (GitHub thread-based model), external-patterns Section 4.1 (grouping algorithm).

---

### Decision Area 6: Frontend Integration

**HMW**: Jak zintegrować notification badge z istniejącą jQuery-based phpBB frontend?

#### Alternative 6A: jQuery `setInterval` + `$.ajax` Polling

Prosty jQuery polling: `setInterval(() => $.ajax('/api/v1/notifications/count'), 30000)`. Badge aktualizowany na sukces. Dropdown ładowany on-click via `$.ajax('/api/v1/notifications')`. Integracja z istniejącym phpBB AJAX pattern (`data-ajax` attribute system).

**Strengths**:
- Spójne z istniejącą jQuery codebase (phpBB 3.x, core.js)
- Natywna integracja z `phpbb.addAjaxCallback()` — mark-read przez data-ajax pattern
- Trywialna implementacja — ~30 linii jQuery
- CSRF token management through existing phpBB AJAX infrastructure
- Developers znają jQuery — zero learning curve

**Weaknesses**:
- jQuery jest legacy — nowe podejście blokuje migrację do nowoczesnego frontend
- `setInterval` nie respektuje visibility API — polluje nawet gdy tab jest nieaktywny
- Brak request cancellation — pending requests akumulują się przy szybkiej nawigacji
- jQuery `$.ajax` nie obsługuje abort natywnie (wymaga `jqXHR.abort()`)
- Tight coupling z jQuery ecosystem — trudne do użycia w przyszłym SPA/framework

**Best when**: Brak planów na frontend modernizację; quick win z minimal effort; team zna jQuery.

**Evidence**: codebase-notifications Section 5 (existing `data-ajax` patterns), external-phpbb (jQuery 3.6.0 in prosilver).

---

#### Alternative 6B: Vanilla Fetch API + Page Visibility API

Nowoczesny polling z `fetch()` API, `AbortController` dla request cancellation, i `document.visibilitychange` event do pauzowania polling gdy tab nieaktywny. Osobny moduł JS (ES module lub IIFE), niezależny od jQuery.

```javascript
// notifications-poll.js (IIFE pattern for phpBB compatibility)
(function(document, window) {
    let controller = null;
    let lastModified = null;

    async function poll() {
        if (document.hidden) return;
        controller = new AbortController();
        const headers = { 'Authorization': 'Bearer ' + token };
        if (lastModified) headers['If-Modified-Since'] = lastModified;

        const resp = await fetch('/api/v1/notifications/count', {
            signal: controller.signal, headers
        });
        if (resp.status === 304) return;
        lastModified = resp.headers.get('Last-Modified');
        const { unread_count } = await resp.json();
        document.querySelector('.notification-badge').textContent = unread_count;
    }

    setInterval(poll, 30000);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) poll();
    });
})(document, window);
```

**Strengths**:
- **Zero dependencies** — żadna biblioteka, native browser APIs
- Page Visibility API — zero wasted requests gdy tab nieaktywny
- `AbortController` — clean request cancellation
- `If-Modified-Since` / `304` natywnie obsługiwane przez fetch
- Framework-agnostic — działa z jQuery, React, Vue, lub bare metal
- Przyszłościowe — łatwa migracja do web worker lub shared worker

**Weaknesses**:
- Nie wykorzystuje phpBB `data-ajax` infrastructure — osobny moduł
- Wymaga ręcznego JWT token management (lub cookie-based auth)
- Brak integracji z phpBB CSRF system (form_token)
- IE11 incompatible (ale phpBB 4 dropuje IE support)
- Team musi znać modern APIs (fetch, AbortController, Visibility API)

**Best when**: Planowana modernizacja frontend; zero-dependency policy; potrzeba visibility-aware polling.

**Evidence**: external-patterns Section 3.2 (fetch + polling pattern), research-report Section 5.5 (polling strategy code example).

---

#### Alternative 6C: Shared Worker for Tab Deduplication

Jeden `SharedWorker` zarządza pollingiem dla WSZYSTKICH otwartych tabów tego samego forum. Worker polluje co 30s, rozsyła wynik do wszystkich connected ports (tabów). Eliminuje N × polling dla N tabów.

```javascript
// notification-worker.js (SharedWorker)
const ports = new Set();
let lastModified = null;

onconnect = (e) => {
    const port = e.ports[0];
    ports.add(port);
    port.onmessage = (msg) => { /* handle commands */ };
    port.start();
};

setInterval(async () => {
    const resp = await fetch('/api/v1/notifications/count', { /* ... */ });
    // Broadcast to all tabs
    for (const port of ports) port.postMessage({ unread_count });
}, 30000);
```

**Strengths**:
- **1 request per 30s** niezależnie od liczby otwartych tabów (vs N requests)
- Centralny state management — wszystkie taby widzą identyczny count
- Eliminuje race conditions między tabami (np. mark-all-read w jednym tabie)
- Odporny na tab crash — worker żyje dopóki jeden tab jest otwarty
- Bandwidth savings: 5 tabów = 80% mniej traffic

**Weaknesses**:
- SharedWorker nie obsługiwany na iOS Safari / old browsers — wymaga fallback
- Zwiększa complexity — worker lifecycle, port management, message protocol
- Debugging workers jest trudniejsze niż inline JS
- phpBB template system nie jest przygotowany na worker registration
- JWT token sharing through worker — security considerations

**Best when**: Wielu userów trzyma 3+ tabów forum; bandwidth/load to measurable concern; v2+ optimization.

**Evidence**: external-patterns Section 3.4 (shared worker for SSE multiplexing — analogous pattern).

---

---

## Trade-Off Analysis

### 5-Perspective Comparison Matrix

#### Decision Area 1: Real-Time Delivery

| Alternative | Technical Feasibility | User Impact | Simplicity | Risk | Scalability |
|---|---|---|---|---|---|
| **1A: HTTP Polling 30s** | **High** — zero infra changes, standard PHP | **Medium** — 15-30s delay | **High** — trivial implementation | **Low** — proven pattern | **High** — 33 req/s @ 1K users, linear scaling |
| 1B: Long Polling | Medium — works but blocks PHP workers | High — ~5s latency | Medium — needs server-side wait loop | **High** — PHP-FPM worker exhaustion | **Low** — workers = concurrent user limit |
| 1C: SSE via Separate Service | Medium — needs separate service | **High** — sub-second push | Low — Redis + Node.js/Go + SSE | Medium — new infra component | **High** — gateway designed for persistent connections |
| 1D: WebSocket | Low — requires Ratchet/Swoole | **High** — real-time bidirectional | **Low** — connection mgmt, heartbeat | **High** — niche PHP ecosystem | High — but overkill |

#### Decision Area 2: Notification Aggregation

| Alternative | Technical Feasibility | User Impact | Simplicity | Risk | Scalability |
|---|---|---|---|---|---|
| **2A: Write-Time Responders** | **High** — already implemented | **High** — Facebook-style for posts | **High** — zero new logic | **Low** — proven code | High — O(1) per notification |
| 2B: Read-Time GROUP BY | Medium — complex SQL + indexing | **High** — all types grouped | Low — GROUP BY + subqueries | Medium — query performance | Medium — GROUP BY cost grows |
| 2C: Hybrid | Medium — two aggregation systems | **High** — best UX overall | Medium — dual logic | Medium — complexity | High — write-time for hot path |

#### Decision Area 3: Cache Strategy

| Alternative | Technical Feasibility | User Impact | Simplicity | Risk | Scalability |
|---|---|---|---|---|---|
| **3A: Tag-Aware Pool** | Medium — depends on cache service | High — < 5ms polling | Medium — pool + subscriber | **Low** — pattern validated | **High** — per-user pools scale |
| 3B: Denormalized Count | High — simple ALTER TABLE | **High** — instant count | Medium — reconciliation needed | Medium — count drift | High — single column read |
| 3C: Simple File Cache | **High** — existing driver | Medium — 60s staleness | **High** — 10 LOC | Low — proven | Medium — file I/O per user |
| 3D: Redis Counters | Medium — requires Redis infra | **High** — 0.1ms reads | Medium — Redis operational mgmt | Medium — two truth sources | **High** — Redis designed for this |

#### Decision Area 4: Service Architecture

| Alternative | Technical Feasibility | User Impact | Simplicity | Risk | Scalability |
|---|---|---|---|---|---|
| **4A: Hybrid Facade** | **High** — auth service precedent | High — optimized reads | Medium — repo + manager | **Low** — proven pattern | **High** — PDO optimized |
| 4B: Pure Delegation | **High** — thin wrapper | Medium — slow count | **High** — minimal code | Low — no new logic | **Low** — manager overhead |
| 4C: Decorator | Medium — manager API not decorator-friendly | High — transparent | Low — abstract layer | Medium — over-engineering | High — extensible |
| 4D: Full Rewrite | Medium — duplicates filtering | High — fully optimized | Low — reimplements logic | **High** — event/dedup gaps | High — clean slate |

#### Decision Area 5: API Response Design

| Alternative | Technical Feasibility | User Impact | Simplicity | Risk | Scalability |
|---|---|---|---|---|---|
| **5A: Flat + Embedded Responders** | **High** — `prepare_for_display()` bridge | **High** — flexible rendering | **High** — flat iteration | **Low** — proven REST pattern | High — linear payload growth |
| 5B: Server-Rendered Text | High — simple string output | Medium — no client flexibility | **High** — display-ready | Low — server handles i18n | Medium — locale-specific cache |
| 5C: Grouped Response | Medium — requires read-time grouping | **High** — native Facebook UX | Low — nested iteration + cursor | Medium — complex cache | Medium — group computation |

#### Decision Area 6: Frontend Integration

| Alternative | Technical Feasibility | User Impact | Simplicity | Risk | Scalability |
|---|---|---|---|---|---|
| 6A: jQuery Polling | **High** — existing ecosystem | Medium — always polls | **High** — 30 LOC jQuery | **Low** — known patterns | Medium — polls inactive tabs |
| **6B: Fetch + Visibility API** | **High** — native browser APIs | **High** — visibility-aware | **High** — 40 LOC vanilla JS | **Low** — standard APIs | **High** — no wasted requests |
| 6C: SharedWorker | Medium — no iOS Safari | **High** — tab deduplication | Low — worker lifecycle | Medium — browser compat | **High** — 1 request per 30s total |

---

## User Preferences

Z kontekstu orkiestratora i research question:
- **PHP-FPM + Nginx stack** — brak appetite na dodatkową infrastrukturę
- **Facebook-style UX** — bell icon + count badge + dropdown
- **Lekki REST API** — emphasis na lightweight
- **Existing infrastructure reuse** — "z lekkim REST API endpointem" sugeruje minimal footprint
- **phpBB codebase conventions** — spójność z istniejącymi patterns (DI, Symfony, PSR-4)

---

## Recommended Approach

### Recommended Combination

| Decision Area | Recommended | Alternative |
|---|---|---|
| 1. Real-Time Delivery | **1A: HTTP Polling 30s** z `Last-Modified`/`304` | — |
| 2. Aggregation | **2A: Write-Time Responders** (existing) | — |
| 3. Cache | **3A: Tag-Aware Pool** z fallback do **3C: File Cache** | — |
| 4. Architecture | **4A: Hybrid Facade** (repo reads + manager writes) | — |
| 5. API Response | **5A: Flat + Embedded Responders** | — |
| 6. Frontend | **6B: Fetch + Visibility API** | — |

### Primary Rationale

Rekomendacja to **pragmatyczna, spójna architektura** budowana na 100% istniejącej infrastruktury:

1. **Zero nowej infrastruktury** — HTTP Polling, istniejące cache, existing manager → brak nowych zależności deployment
2. **Proven patterns** — każdy element rekomendacji ma precedens w codebase (auth service = facade pattern, GitHub API = polling + 304, write-time responders = existing code)
3. **Optimized hot path** — count endpoint: cache hit → < 1ms; cache miss → single PDO COUNT → 2ms; 90%+ hit rate → DB queries only ~3/s for 1000 users
4. **Minimal code** — szacunkowo ~500 LOC nowego kodu: Service (~100), Repository (~80), Controller (~120), Events (~40), Subscriber (~40), Frontend (~40), DI config (~80)

### Key Trade-offs Accepted

- **15-30s notification latency** — akceptowalne dla forum (nie chat/trading). Upgrade path → SSE w v2
- **Brak grupowania non-post typów** — PM, quote bez responder rollup. Akceptowalne bo to 1:1 interactions
- **Cache dependency on unrealized service** — cache service zaprojektowany ale nie zaimplementowany. Fallback: 3C (file cache)
- **Frontend nie-jQuery** — odejście od phpBB jQuery ecosystem. Trade-off: future-proof vs ecosystem consistency

### Key Assumptions

1. **Cache service zostanie zaimplementowany** przed lub równolegle z notification service (lub fallback 3C jest wystarczający)
2. **`database_connection` PDO service jest zarejestrowany** w DI container (albo trzeba go dodać — precedens z auth service)
3. **`prepare_for_display()` jest idempotent i side-effect-free** — nie mutuje internal state
4. **Forum traffic nie przekracza** ~1000 concurrent active users (→ 33 polling req/s)
5. **Frontend team akceptuje** vanilla JS over jQuery pattern

### Confidence Level

**High (88%)** — Wszystkie elementy mają silne evidence w codebase lub external patterns. Najniższa pewność: cache service readiness (90%) i polling performance at scale (75%).

---

## Why Not Others

### 1B: Long Polling
PHP-FPM worker exhaustion jest fundamentalnym blokerem. Przy 50 workerach i 50+ concurrent userach, normalen ruch forumowy byłby zablokowany. Brak async runtime w PHP czyni to podejście niewykonalnym bez znacznych zmian infrastrukturalnych.

### 1C: SSE via Separate Service
Dodaje Redis + Node.js/Go service do deployment → zwiększa operational complexity 3x. Latencja 15-30s jest akceptowalna dla forum. Benefit (sub-second push) nie justifikuje kosztu. Zarezerowowane jako v2 upgrade path.

### 1D: WebSocket
Overkill — powiadomienia to one-way communication. WebSocket dodaje bidirectional capability niepotrzebnie. Problemy z corporate proxies, debug complexity, niszowy PHP ecosystem (Ratchet/Swoole) — wszystko to nieproporcjonalne do value add.

### 2B: Read-Time GROUP BY
Forum notification volumes nie uzasadniają złożoności. phpBB's write-time aggregation pokrywa główny use case (post replies). GROUP BY + GROUP_CONCAT na TEXT column z `serialize()` data jest niepraktyczne. Reserved for v2 if needed.

### 2C: Hybrid Aggregation
Dwa systemy agregacji to maintenance burden. Write-time (Alternative 2A) pokrywa ~80% wartości (post reply grouping). Dodatkowa read-time logika jest premature optimization.

### 3B: Denormalized Count
Count drift risk wymaga reconciliation cron job. Schema migration (ALTER TABLE phpbb_users) jest bardziej inwazyjne niż cache layer. Warta rozważenia jako v2 optimization jeśli cache hit ratio < 85%.

### 3D: Redis Counters
Wymaga Redis infrastructure nie obecnej w standardowym phpBB deploy. Dwa źródła prawdy (DB + Redis) komplikują reliability reasoning. Over-engineering for forum scale.

### 4B: Pure Delegation
`load_notifications()` z `count_unread: true` instantiuje type classes — 10-100x overhead vs raw COUNT(). Nieakceptowalne dla polling endpoint (33 req/s). Count cold start > 50ms vs < 2ms z PDO repository.

### 4C: Decorator
Manager API nie jest zaprojektowane pod decoration. `load_notifications()` zwraca untyped array — decorator musi znać internal structure. Dodaje abstrakcyjną warstwę bez clear extension need. Niespójne z codebase patterns (facade, not decorator).

### 4D: Full Rewrite
Duplikuje manager's filtering logic (enabled types, user availability, dedup). Pomija manager events — extension developers tracą hook points. High risk of divergence. Manager is Feature, not Bug.

### 5B: Server-Rendered Text
Ogranicza frontend flexibility. Locale-specific caching zwiększa complexity. GitHub i modern APIs zwracają structured data, nie pre-rendered text.

### 5C: Grouped Response
Wymaga read-time aggregation (decision area 2) — dodaje dependency. Nested iteration + cursor-based pagination to over-engineering for v1. Reserved for v2 if grouped UX is required.

### 6A: jQuery Polling
Polluje inactive tabs — waste bandwidth. Brak `AbortController` equivalent. jQuery dependency blokuje frontend modernization. `setInterval` + `$.ajax` nie respektuje visibility API.

### 6C: SharedWorker
iOS Safari nie obsługuje SharedWorker — wymaga fallback logic podwajającą frontend code. Worker lifecycle management i port protocol dodają significant complexity. Opłacalne dopiero przy measurable multi-tab bandwidth problem (3+ tabów per user routinely).

---

## Deferred Ideas

1. **SSE Real-Time Upgrade (v2)** — Osobny Go/Node.js SSE gateway + Redis pub/sub. Daje sub-second push. Wymaga Docker compose rozszerzenie. Rozważyć gdy user base > 5000 active concurrent.

2. **Read-Time Aggregation (v2)** — PHP service-layer grouping dla non-post notification types (topic subscriptions per forum). Rozważyć po user feedback — czy brakuje grupowania poza post replies?

3. **Push Notifications (v3)** — Web Push API (Service Worker + VAPID keys) dla desktop/mobile push gdy browser zamknięty. Osobny feature scope — wymaga subscription management, permission UX, push server.

4. **Notification Preferences API** — `GET/PUT /api/v1/notifications/settings` do zarządzania subskrypcjami per type/method. Obecne UCP page działa; API exposure gdy frontend SPA.

5. **Denormalized Count Optimization** — `phpbb_users.notification_unread_count` column z atomic INCR/DECR. Rozważyć jeśli cache hit rate < 85% po wdrożeniu.

6. **Notification Analytics** — Track CTR per type, average read time, dismissal rate. Wartościowe dla product decisions ale osobny feature scope.

7. **Bulk Operations API** — `DELETE /api/v1/notifications` z body `{ "ids": [1,2,3] }`. Fan-requested feature ale poza v1 scope.

8. **Email Digest Preferences** — API endpoint dla konfiguracji email digest (immediate, daily, weekly). Obecny UCP flow wystarczający; API gdy SPA frontend.
