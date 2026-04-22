# Requirements: phpbb\hierarchy Service

**Date**: 2026-04-22  
**Task**: Implement complete phpbb\hierarchy service namespace

---

## Initial Description (from user)
Implement Hierarchy Service (phpbb\hierarchy) — forums, categories, subforums, nested set, jako następna pozycja z roadmap po ukończeniu doctrine/dbal migration (M4).

## Q&A from Codebase Analysis + Gap Analysis

**Q: Jaki zakres implementujemy?**  
A: Wszystkie 5 serwisów jednorazowo: HierarchyService, ForumRepository, TreeService, TrackingService, SubscriptionService, plus plugin events, plus REST API wiring.

**Q: Strategia lockowania?**  
A: SELECT FOR UPDATE w transakcji DBAL. SQLite (testy) obsługuje to przez DBAL bez rzucania wyjątku.

**Q: Co robimy z forum_parents?**  
A: Konwertujemy na JSON. Odczyt: try JSON first, fallback PHP serialize. Zapis: zawsze JSON.

**Q: Plugin system?**  
A: Events + Request/Response Decorators (ADR-004). Brak service_collection. Typed domain events.

**Q: Typ zwracany z mutacji?**  
A: Domain events zwracane + dispatchowane (ADR-005). Odczyty: DTO bezpośrednio.

**Q: Mapowanie typów forumów?**  
A: ForumType enum (CATEGORY=0, POST=1, LINK=2) + ForumTypeRegistry z ForumTypeBehaviorInterface (ADR-001).

**Q: Czy ACL w hierarchy?**  
A: Nie (ADR-006). Auth sprawdza controller/display layer, nie hierarchy.

**Q: Baza danych?**  
A: DBAL 4 (doctrine/dbal ^4.0 zainstalowane). MariaDB 10.11 w produkcji, SQLite in-memory w testach.

## Similar Features in Codebase (Reusability)

### Patterns do skopiowania:
- `DbalBanRepository` — wzorzec CRUD z exception wrapping
- `DbalGroupRepository` — platform-switched upsert, JOIN, enum mapping
- `DbalUserRepository` — QueryBuilder, ArrayParameterType::INTEGER, keyed arrays
- `IntegrationTestCase` — abstract, setUpSchema(), SQLite in-memory
- `RepositoryException` — `phpbb\db\Exception\RepositoryException`
- `services.yaml` — interface → alias wiring, auto-inject DBAL Connection

### Istniejące pliki do modyfikacji:
- `src/phpbb/api/Controller/ForumsController.php` — podpiąć pod HierarchyService
- `src/phpbb/config/services.yaml` — dodać hierarchy service definitions

## Functional Requirements Summary

### FR-01: Forum entity
- Klasa `phpbb\hierarchy\Entity\Forum` hydrated z phpbb_forums
- Pola: forumId, forumName, parentId, leftId, rightId, forumType (enum), forumDesc, forumLink, forumStatus, displayOnIndex, topicsApproved, postsApproved, lastPostTime, lastPostId, lastPosterName, lastPosterColour, forumParents (array<int,string>)
- ForumType enum: CATEGORY=0, POST=1, LINK=2

### FR-02: ForumRepository
- Interface: `phpbb\hierarchy\Contract\ForumRepositoryInterface`
- Implementation: `phpbb\hierarchy\Repository\DbalForumRepository`
- Methods: `findById(int): ?Forum`, `findAll(): array<int,Forum>`, `findChildren(int): array<int,Forum>`, `create(ForumData): Forum`, `update(int, ForumData): Forum`, `delete(int): void`
- Exception wrapping: `RepositoryException`
- Integer keyed returns

### FR-03: TreeService
- Interface: `phpbb\hierarchy\Contract\TreeServiceInterface`
- Implementation: `phpbb\hierarchy\Service\TreeService`
- Methods: `getSubtree(?int rootId): array<int,Forum>`, `getPath(int forumId): array<int,Forum>`, `positionNode(int nodeId, int parentId): void`, `removeNode(int nodeId): void`, `moveNode(int nodeId, int newParentId): void`, `rebuildTree(): void`
- Locking: `$conn->transactional()` + SELECT FOR UPDATE on tree root/ancestor
- Nested set math: port from phpBB3 `nestedset.php` to DBAL 4

### FR-04: HierarchyService (facade)
- Interface: `phpbb\hierarchy\Contract\HierarchyServiceInterface`  
- Implementation: `phpbb\hierarchy\Service\HierarchyService`
- Read methods (return DTO): `listForums(?int parentId): array<int,ForumDTO>`, `getForum(int id): ?ForumDTO`, `getTree(): array<int,ForumDTO>`, `getPath(int id): array<int,ForumDTO>`
- Mutation methods (return domain events): `createForum(CreateForumRequest): ForumCreatedEvent`, `updateForum(int, UpdateForumRequest): ForumUpdatedEvent`, `deleteForum(int): ForumDeletedEvent`, `moveForum(int, int newParentId): ForumMovedEvent`
- Dispatches events via Symfony EventDispatcherInterface
- Passes through DecoratorPipeline for request/response decoration

### FR-05: TrackingService
- Interface: `phpbb\hierarchy\Contract\TrackingServiceInterface`
- Implementation: `phpbb\hierarchy\Service\TrackingService`
- Methods: `markRead(int userId, int forumId): void`, `markAllRead(int userId): void`, `isUnread(int userId, int forumId): bool`, `getUnreadStatus(int userId, array<int> forumIds): array<int,bool>`
- Table: phpbb_forums_track (user_id, forum_id, mark_time)

### FR-06: SubscriptionService
- Interface: `phpbb\hierarchy\Contract\SubscriptionServiceInterface`
- Implementation: `phpbb\hierarchy\Service\SubscriptionService`
- Methods: `subscribe(int userId, int forumId): void`, `unsubscribe(int userId, int forumId): void`, `isSubscribed(int userId, int forumId): bool`, `getSubscribers(int forumId): array<int>`
- Table: phpbb_forums_watch (forum_id, user_id, notify_status)

### FR-07: Plugin Event System
- Domain events: `ForumCreatedEvent`, `ForumUpdatedEvent`, `ForumDeletedEvent`, `ForumMovedEvent` (zawierają Forum entity + response DTO)
- Event dispatching via Symfony EventDispatcherInterface
- `RegisterForumTypesEvent` — fires at boot for plugin type registration
- `ForumTypeRegistry` — maps ForumType enum → ForumTypeBehaviorInterface

### FR-08: REST API wiring
- `ForumsController` dostaje `HierarchyServiceInterface` (już istnieje jako stub)
- Endpointy:
  - `GET /forums` → `listForums()`
  - `GET /forums/{id}` → `getForum()`
  - `POST /forums` → `createForum()` (wymaga auth)
  - `PATCH /forums/{id}` → `updateForum()` (wymaga auth)
  - `DELETE /forums/{id}` → `deleteForum()` (wymaga auth)
  - `GET /forums/{id}/children` → `listForums(parentId)`
  - `GET /forums/{id}/path` → `getPath()`

### FR-09: Tests
- PHPUnit unit tests dla każdego serwisu
- IntegrationTestCase dla DbalForumRepository (SQLite schema)
- Minimum: 8 tests ForumRepository, 6 tests TreeService, 5 tests TrackingService, 4 tests SubscriptionService, 5 tests HierarchyService
- Minimum 40 testów łącznie

## Reusability Opportunities
- Copy `hydrate()` pattern from DbalBanRepository/DbalGroupRepository — array → entity mapping
- Copy `setUpSchema()` pattern from DbalGroupRepositoryTest for SQLite schema
- Remove mock data from ForumsController, inject HierarchyServiceInterface via DI

## Scope Boundaries

### In Scope:
- Wszystkie 5 serwisów + plugin events
- REST API PUT/PATCH/DELETE wiring (z auth requirement)
- forum_parents → JSON (lazy conversion)
- ForumType enum + ForumTypeRegistry skeleton
- Tests (min 40)

### Out of Scope:
- Cookie tracking dla anonymous użytkowników
- CompilerPass dla decorator pipeline — inject dekoratory przez DI
- E2E Playwright tests (wymaga Docker)
- User documentation
- Admin panel / React frontend changes
- Search indexing based on hierarchy

## Technical Considerations
- DBAL 4: named params bez `:` prefix, `executeQuery`/`executeStatement`/`fetchAllAssociative`
- SQLite nie ma LIMIT w UPDATE — TreeService query musi być cross-db compatible
- PHP 8.2: readonly properties, enums, named arguments, match expressions
- `private const TABLE = 'phpbb_forums'` — brak dynamicznego prefixu
- Symfony 8.x: attribute routing, EventDispatcher, DI autowiring
