# Scope Clarifications: phpbb\hierarchy Service

**Date**: 2026-04-22  
**Phase**: 2 → 5 Gate

---

## Decisions Made

### D1: Implementacja zakresu — Wszystkie 5 serwisów (opcja C)
- HierarchyService (facade)
- ForumRepository (CRUD + DBAL 4)
- TreeService (nested set + locking)
- TrackingService (phpbb_forums_track)
- SubscriptionService (phpbb_forums_watch)
- Plugin events (ForumCreatedEvent, ForumDeletedEvent, ForumUpdatedEvent, ForumMovedEvent)
- REST API wiring: ForumsController → HierarchyService
- Pełne testy PHPUnit dla wszystkich komponentów

### D2: Locking dla TreeService — SELECT FOR UPDATE w transakcji DBAL
- `$this->connection->transactional(function() { ... SELECT FOR UPDATE ... })` 
- Produkcja: MySQL/MariaDB obsługuje natywnie
- Testy (SQLite): IntegrationTestCase będzie zawierał workaround (SQLite ignoruje SELECT FOR UPDATE — nie rzuca błędu)

### D3: forum_parents — Konwersja na JSON
- Odczyt: akceptuj istniejący PHP serialize LUB JSON (try JSON first, fallback serialize)
- Zapis: zawsze JSON
- Brak osobnej migracji — konwersja lazy na pierwszej aktualizacji

### D4 (ADR-004): Plugin system — Events + Request/Response Decorators
- Zdefiniowane w decision-log ADR-004 — brak service_collection
- Domain events: ForumCreatedEvent, ForumDeletedEvent, ForumUpdatedEvent, ForumMovedEvent
- RegisterForumTypesEvent przy boottrasowaniu

### D5 (ADR-005): Typ zwracany
- Mutacje: zwracają domain event (ForumCreatedEvent zawiera entity Forum)
- Odczyty: zwracają ForumDTO lub tablice DTO bezpośrednio

### D6 (ADR-002): TreeService — DBAL 4 zamiast PDO
- Decision log mówi "Port to PDO" — aktualizujemy do DBAL 4 (naturalna konsekwencja M4)
- Identyczne algorytmy nested set, tylko warstwa zapytań przez DBAL Connection

---

## Out of Scope
- Cookie tracking (anonymous users) — TODO na przyszłość
- CompilerPass dla decorator pipeline — może być uproszczone do DI injection w tej fazie
- User docs, E2E — wykluczone
