# Implementation Plan: M8 Notifications Service (`phpbb\notifications`)

## Overview

| Metric | Value |
|--------|-------|
| Task Groups | 8 |
| Total Steps | 52 |
| Expected PHPUnit Tests | 40–50 (unit + integration) |
| E2E Tests | ~10 Playwright cases |
| Template | M7 Messaging (`src/phpbb/messaging/`) |

### Audit Fixes Incorporated

| ID | Fix | Applied In |
|----|-----|-----------|
| H1 | 304 E2E test requires pre-seeded row in `beforeAll` fixture | Group 8 |
| H2 | Group C (11 tests) split into `ReadTest` + `WriteTest` (6+5) | Group 3 |
| M1 | `markRead`/`markAllRead` return `204 No Content`, not `{status:'read'}` | Groups 5, 6, 8 |
| M2 | `NotificationService` is `final class` (not `final readonly class`); cache property is `private readonly` | Group 5 |
| M4 | Document `Last-Modified` trade-off (no update on mark-read) as inline comment | Group 6 |
| L1 | `max(1, $result->totalPages())` in `index()` response | Group 6 |
| L2 | `@throws RepositoryException` in all interface PHPDoc blocks | Group 3 |
| L3 | Empty-string pre-check before `json_decode` in `fromRow()`; cover with dedicated test | Group 2 |

---

## Implementation Steps

---

### Task Group 1: Database Migration
**Dependencies:** None  
**Estimated Steps:** 5

- [x] 1.0 Complete database migration
  - [x] 1.1 Write 3 migration verification tests
    - File: `tests/phpbb/notifications/Migration/MigrationSchemaTest.php`
    - Test `phpbb_notifications.notification_data` column type is `json` (or `longtext` for MariaDB)
    - Test composite index `user_read_time` exists on `phpbb_notifications`
    - Test `phpbb_notification_types` rows `notification.type.post` and `notification.type.topic` exist
    - These are smoke-test queries: `SHOW COLUMNS`, `SHOW INDEX`, `SELECT COUNT(*)` — run against test DB
  - [x] 1.2 Create SQL migration file
    - File: `src/phpbb/migrations/m8_notifications_json.sql`
    - Step 1: `ALTER TABLE phpbb_notifications MODIFY COLUMN notification_data JSON NOT NULL`
    - Step 2: `ALTER TABLE phpbb_notifications DROP INDEX user; ADD INDEX user_read_time (user_id, notification_read, notification_time)`
    - Step 3: `INSERT IGNORE INTO phpbb_notification_types ...` for both built-in types
    - Keep old `item_ident` index (required by creation pipeline M8.x)
  - [x] 1.3 Apply migration against containerised MySQL
    - Run: `docker exec phpbb_app php bin/phpbbcli.php db:migrate` (or raw `mysql < migration.sql`)
    - Confirm zero affected rows on re-run (idempotent `ALTER IGNORE` / `INSERT IGNORE`)
  - [x] 1.4 Document rollback approach in migration file header comment
    - Rollback: `MODIFY COLUMN notification_data TEXT`, drop/recreate `user` index, `DELETE FROM phpbb_notification_types WHERE ...`
  - [x] 1.5 Ensure migration tests pass
    - Run only: `composer test -- --filter Migration`

**Acceptance Criteria:**
- All 3 migration smoke tests pass
- `notification_data` is JSON type in live DB
- `user_read_time` composite index present
- Both type rows seeded

---

### Task Group 2: Domain Layer (Entity, DTO, Events)
**Dependencies:** Group 1 (DB must exist for integration context)  
**Estimated Steps:** 7

- [x] 2.0 Complete domain layer
  - [x] 2.1 Write 5 entity tests (test-first)
    - File: `tests/phpbb/notifications/Entity/NotificationTest.php`
    - Namespace: `phpbb\Tests\notifications\Entity`
    - `fromRowMapsAllFields` — all 9 DB columns correctly cast (`(int)`, `(bool)`, `(string)`)
    - `fromRowDecodesJsonData` — valid JSON string `'{"responders":[]}'` → decoded array
    - `fromRowSetsReadBool` — `notification_read=0` → `read=false`; `=1` → `read=true`
    - `fromRowDefaultsDataToEmptyArray` — `notification_data='[]'` → `data=[]`
    - `fromRowHandlesEmptyStringData` — `notification_data=''` → `data=[]` (L3 fix: pre-check before `json_decode`)
    - Use `#[Test]` attribute (PHPUnit 10+), AAA pattern, no DB required (pure unit)
  - [x] 2.2 Write 4 DTO tests (test-first)
    - File: `tests/phpbb/notifications/DTO/NotificationDTOTest.php`
    - `fromEntityMapsId` — `dto->id === notification->notificationId`
    - `fromEntitySetsUnreadInvertsRead` — `read=false` → `unread=true`; `read=true` → `unread=false`
    - `fromEntityBuildsDataArray` — `responders`, `responderCount`, `itemId`, `itemParentId` correct
    - `toArrayMatchesJsonShape` — keys: `id`, `type`, `unread`, `createdAt`, `data`
    - Build test `Notification` via `Notification::fromRow()` (factory call with fixture array)
  - [x] 2.3 Implement `Notification` entity
    - File: `src/phpbb/notifications/Entity/Notification.php`
    - `final readonly class Notification`
    - All 9 properties typed as per spec Section 4
    - `public static function fromRow(array $row): self` — explicit casts + L3 fix:
      ```php
      $raw = $row['notification_data'] ?? '';
      $data = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
      ```
    - Named constructor arguments in `new self(...)` call (STANDARDS.md)
  - [x] 2.4 Implement `NotificationDTO`
    - File: `src/phpbb/notifications/DTO/NotificationDTO.php`
    - `final readonly class NotificationDTO`
    - `public static function fromEntity(Notification $notification): self` with named args
    - `public function toArray(): array` returning documented JSON shape
    - `data` field: `itemId`, `itemParentId`, `responders`, `responderCount`
  - [x] 2.5 Implement 4 Event classes
    - `src/phpbb/notifications/Event/NotificationReadEvent.php` — `final readonly class extends DomainEvent`, empty body
    - `src/phpbb/notifications/Event/NotificationsReadAllEvent.php` — `final readonly class extends DomainEvent`, empty body
    - `src/phpbb/notifications/Event/RegisterNotificationTypesEvent.php` — `final class` (NOT readonly, NOT DomainEvent), with `$types` array, `addType()`, `getTypes()`
    - `src/phpbb/notifications/Event/RegisterDeliveryMethodsEvent.php` — `final class`, with `$methods` array, `addMethod()`, `getMethods()`
    - Verify `DomainEvent` base class: `src/phpbb/common/Event/DomainEvent.php` (has `entityId`, `actorId` constructor params)
  - [x] 2.6 Verify entity + DTO tests pass
    - Run: `composer test -- --filter 'NotificationTest|NotificationDTOTest'`
  - [x] 2.7 Run cs:fix on new files
    - Run: `composer cs:fix`

**Acceptance Criteria:**
- All 9 tests (5 entity + 4 DTO) pass
- `Notification::fromRow('')` returns `data=[]` (empty-string path verified)
- All 4 event classes instantiate correctly
- No CS violations

---

### Task Group 3: Repository Layer (Interface + DBAL Implementation)
**Dependencies:** Group 2 (entity must exist)  
**Estimated Steps:** 7

- [x] 3.0 Complete repository layer
  - [x] 3.1 Write 6 integration READ tests (test-first, SQLite)
    - File: `tests/phpbb/notifications/Repository/DbalNotificationRepositoryReadTest.php`
    - Extends `phpbb\Tests\Integration\IntegrationTestCase`
    - `setUpSchema()` creates both tables (TEXT for SQLite compat on `notification_data`)
    - `findByIdReturnsNullForMissing` — query ID 999 → null
    - `findByIdReturnsScopedToUser` — existing row, wrong `userId` → null
    - `findByIdReturnsHydratedEntity` — correct ID + userId → `Notification` with `typeName` from JOIN
    - `countUnreadReturnsZeroByDefault` — empty table → 0
    - `countUnreadCountsOnlyUnread` — 3 rows: 2 unread + 1 read → 2
    - `getLastModifiedReturnsNullWhenEmpty` — no rows → null
    - `getLastModifiedReturnsMaxTime` — 2 rows (times 100, 200) → 200
    - Study: `tests/phpbb/messaging/Repository/` for `setUpSchema()`, fixture helpers
  - [x] 3.2 Write 5 integration WRITE tests (test-first, SQLite)
    - File: `tests/phpbb/notifications/Repository/DbalNotificationRepositoryWriteTest.php`
    - Same `IntegrationTestCase` + same schema (can share a trait or duplicate schema)
    - `listByUserReturnsPaginatedDTOs` — 3 rows → `PaginatedResult` total=3, items are `NotificationDTO`
    - `markReadReturnsTrueForOwn` — unread row + correct userId → `true`; row has `notification_read=1`
    - `markReadReturnsFalseForOther` — unread row + wrong userId → `false`; row unchanged
    - `markReadReturnsFalseIfAlreadyRead` — already-read row → `false`
    - `markAllReadUpdatesAllUnread` — 3 unread → 3 updated; `countUnread()` returns 0
  - [x] 3.3 Implement `NotificationRepositoryInterface`
    - File: `src/phpbb/notifications/Contract/NotificationRepositoryInterface.php`
    - All 6 method signatures from spec Section 7
    - L2 fix: Add `@throws \phpbb\db\Exception\RepositoryException` to every method's PHPDoc block
    - Imports: `Notification`, `PaginationContext`, `PaginatedResult`
  - [x] 3.4 Implement `DbalNotificationRepository`
    - File: `src/phpbb/notifications/Repository/DbalNotificationRepository.php`
    - `final class DbalNotificationRepository implements NotificationRepositoryInterface`
    - Constants: `NOTIFICATIONS_TABLE = 'phpbb_notifications'`, `TYPES_TABLE = 'phpbb_notification_types'`
    - Constructor: `private readonly \Doctrine\DBAL\Connection $connection`
    - Use QueryBuilder (`$this->connection->createQueryBuilder()`) for all queries — no raw SQL strings
    - `findById()`: LEFT JOIN types table, `setParameter()` for `:id` and `:userId`, hydrate or null
    - `countUnread()`: COUNT with `notification_read = 0`, return `(int) fetchOne()`
    - `getLastModified()`: `MAX(notification_time)`, null-guard: `$result !== false && $result !== null ? (int)$result : null`
    - `listByUser()`: count query + data query with ORDER BY `notification_time DESC`, LIMIT/OFFSET from `$ctx`; map items to `NotificationDTO::fromEntity()`;  return `PaginatedResult`
    - `markRead()`: UPDATE with `notification_id`, `user_id`, `notification_read=0` WHERE; `executeStatement() > 0`
    - `markAllRead()`: UPDATE with `user_id`, `notification_read=0` WHERE; return `(int) executeStatement()`
    - Private `hydrate(array $row): Notification` delegating to `Notification::fromRow($row)`
    - Every public method wrapped in `try/catch(\Doctrine\DBAL\Exception $e)` → `RepositoryException`
    - Study: `src/phpbb/messaging/Repository/DbalMessageRepository.php` for pattern reference
  - [x] 3.5 Verify read tests pass
    - Run: `composer test -- --filter DbalNotificationRepositoryReadTest`
  - [x] 3.6 Verify write tests pass
    - Run: `composer test -- --filter DbalNotificationRepositoryWriteTest`
  - [x] 3.7 Run cs:fix
    - Run: `composer cs:fix`

**Acceptance Criteria:**
- All 11 repository tests pass (6 read + 5 write)
- `DbalNotificationRepository` uses QueryBuilder exclusively (no raw SQL string interpolation)
- `@throws RepositoryException` present in all 6 interface method PHPDocs
- Exception wrapping tested implicitly (integration test with bad connection would throw `RepositoryException`)

---

### Task Group 4: Extensibility Layer (TypeRegistry, MethodManager, Built-in Types/Methods)
**Dependencies:** Group 2 (events must exist)  
**Estimated Steps:** 6

- [x] 4.0 Complete extensibility layer
  - [x] 4.1 Write 4 TypeRegistry tests (test-first)
    - File: `tests/phpbb/notifications/TypeRegistry/TypeRegistryTest.php`
    - Mock: `EventDispatcherInterface&MockObject $dispatcher`
    - `getByNameDispatchesEventLazily` — first `all()` call dispatches `RegisterNotificationTypesEvent` exactly once
    - `getByNameDeduplicatesDispatch` — two `all()` calls → `dispatch()` called only once (lazy init guard)
    - `getByNameThrowsForUnknownType` — `getByName('nonexistent')` → `\InvalidArgumentException`
    - `allReturnsRegisteredMap` — mock dispatcher adds a type via `addType()` in event handler; `all()` returns that type
    - Use PHPUnit mock that calls `$event->addType(new PostNotificationType())` inside `dispatch()` callback
  - [x] 4.2 Implement `NotificationTypeInterface` and `NotificationMethodInterface`
    - File: `src/phpbb/notifications/Type/NotificationTypeInterface.php` — `getTypeName(): string` only (per spec Section 9)
    - File: `src/phpbb/notifications/Method/NotificationMethodInterface.php` — `getMethodName(): string` only (per spec Section 10)
  - [x] 4.3 Implement `TypeRegistry`
    - File: `src/phpbb/notifications/TypeRegistry.php`
    - `final class TypeRegistry` (NOT readonly — `$types` property is mutable)
    - Constructor: `private readonly EventDispatcherInterface $dispatcher`
    - Private: `private ?array $types = null` (nullable, starts uninitialized)
    - Private `initialize()`: guard `if ($this->types !== null) return;` then dispatch `RegisterNotificationTypesEvent`
    - `getByName(string $typeName)`: calls `initialize()`, throws `\InvalidArgumentException` if not found
    - `all()`: calls `initialize()`, returns `$this->types`
  - [x] 4.4 Implement `MethodManager`
    - File: `src/phpbb/notifications/MethodManager.php`
    - Identical lazy-dispatch pattern to `TypeRegistry`, using `RegisterDeliveryMethodsEvent`
    - `getByName()` + `all()` — same guard and throw pattern
  - [x] 4.5 Implement 4 built-in types/methods
    - `src/phpbb/notifications/Type/PostNotificationType.php` — `final class`, `getTypeName()='notification.type.post'`, `register(RegisterNotificationTypesEvent $event): void`
    - `src/phpbb/notifications/Type/TopicNotificationType.php` — `final class`, `getTypeName()='notification.type.topic'`, `register()`
    - `src/phpbb/notifications/Method/BoardNotificationMethod.php` — `final class`, `getMethodName()='board'`, `register(RegisterDeliveryMethodsEvent $event): void`
    - `src/phpbb/notifications/Method/EmailNotificationMethod.php` — `final class`, `getMethodName()='email'`, `register()`
  - [x] 4.6 Verify TypeRegistry tests pass
    - Run: `composer test -- --filter TypeRegistryTest`

**Acceptance Criteria:**
- All 4 `TypeRegistryTest` tests pass
- `TypeRegistry::initialize()` dispatches exactly once (memoized)
- `getByName()` throws `\InvalidArgumentException` for unknown names
- All 4 built-in types/methods implement their interfaces

---

### Task Group 5: Service Layer (NotificationService, CacheInvalidationSubscriber)
**Dependencies:** Groups 3, 4 (repository + registry must exist)  
**Estimated Steps:** 7

- [x] 5.0 Complete service layer
  - [x] 5.1 Write 7 `NotificationService` tests (test-first)
    - File: `tests/phpbb/notifications/Service/NotificationServiceTest.php`
    - Mocks: `NotificationRepositoryInterface&MockObject`, `CachePoolFactoryInterface&MockObject`, `TagAwareCacheInterface&MockObject`
    - `setUp()`: `$cacheFactory->method('getPool')->with('notifications')->willReturn($cache)`
    - `getUnreadCountDelegatesToCache` — `$cache->getOrCompute()` called with key `user:42:count`, TTL=30, tag `user:42`
    - `getUnreadCountInvokesFallback` — cache compute callable invokes `$repo->countUnread($userId)`
    - `getLastModifiedDelegatesToRepository` — `$repo->getLastModified()` called directly (no cache method called)
    - `getNotificationsUsesPagedKey` — key contains `$ctx->page`, TTL=30, user tag
    - `markReadThrowsForNotFound` — `$repo->markRead()` returns `false` → `\InvalidArgumentException` thrown
    - `markReadReturnsDomainEventCollection` — `$repo->markRead()` returns `true` → `DomainEventCollection` containing `NotificationReadEvent` with `entityId=$notificationId`
    - `markAllReadReturnsDomainEventCollection` — returns `DomainEventCollection` with `NotificationsReadAllEvent`, `entityId=$userId`
  - [x] 5.2 Write 3 `CacheInvalidationSubscriber` tests (test-first)
    - File: `tests/phpbb/notifications/Listener/CacheInvalidationSubscriberTest.php`
    - Mock: `CachePoolFactoryInterface&MockObject`, `TagAwareCacheInterface&MockObject`
    - `subscribesToBothEvents` — `getSubscribedEvents()` keys include `NotificationReadEvent::class` and `NotificationsReadAllEvent::class`
    - `onNotificationReadInvalidatesUserTag` — creates `NotificationReadEvent(entityId:7, actorId:42)`, calls subscriber; asserts `$cache->invalidateTags(["user:42"])` called
    - `onNotificationsReadAllInvalidatesUserTag` — same tag pattern with `NotificationsReadAllEvent`
  - [x] 5.3 Implement `NotificationServiceInterface`
    - File: `src/phpbb/notifications/Contract/NotificationServiceInterface.php`
    - 5 method signatures: `getUnreadCount`, `getLastModified`, `getNotifications`, `markRead`, `markAllRead`
    - Return types: `int`, `?int`, `PaginatedResult`, `DomainEventCollection`, `DomainEventCollection`
  - [x] 5.4 Implement `NotificationService` (M2 + M3 fix)
    - File: `src/phpbb/notifications/Service/NotificationService.php`
    - **M2/M3 fix**: `final class NotificationService implements NotificationServiceInterface` (NOT `final readonly class`)
    - Constructor: two params (`NotificationRepositoryInterface $repository`, `CachePoolFactoryInterface $cacheFactory`) with `readonly` on `$repository`
    - **M2 fix**: `private readonly TagAwareCacheInterface $cache;` declared as class property (readonly, not constructor-promoted)
    - Constructor body: `$this->cache = $cacheFactory->getPool('notifications');`
    - Cache keys: `"user:{$userId}:count"`, `"user:{$userId}:notifications:{$ctx->page}"`
    - Tags: `["user:{$userId}"]`; TTL: 30 seconds
    - `markRead()`: throws `\InvalidArgumentException` when `$repo->markRead()` returns `false`; returns `DomainEventCollection([new NotificationReadEvent(entityId: $notificationId, actorId: $userId)])`
    - `markAllRead()`: discards row count (idempotent); returns `DomainEventCollection([new NotificationsReadAllEvent(entityId: $userId, actorId: $userId)])`
    - Study: `src/phpbb/messaging/MessagingService.php` for exact cache pattern
  - [x] 5.5 Implement `CacheInvalidationSubscriber`
    - File: `src/phpbb/notifications/Listener/CacheInvalidationSubscriber.php`
    - `final class CacheInvalidationSubscriber implements EventSubscriberInterface`
    - Constructor: `CachePoolFactoryInterface $cacheFactory`; body assigns `$this->cache = $cacheFactory->getPool('notifications')`
    - `private readonly TagAwareCacheInterface $cache;`
    - `getSubscribedEvents()`: maps both event classes to their handler methods
    - `onNotificationRead()`: `$this->cache->invalidateTags(["user:{$event->actorId}"])`
    - `onNotificationsReadAll()`: same tag invalidation
  - [x] 5.6 Verify service tests pass
    - Run: `composer test -- --filter 'NotificationServiceTest|CacheInvalidationSubscriberTest'`
  - [x] 5.7 Run cs:fix
    - Run: `composer cs:fix`

**Acceptance Criteria:**
- All 10 tests (7 service + 3 subscriber) pass
- `NotificationService` is declared `final class` (not `final readonly class`)
- `$cache` is `private readonly TagAwareCacheInterface` assigned in constructor body
- `markRead()` throws on not-found; `markAllRead()` is idempotent (never throws)

---

### Task Group 6: API Layer (NotificationsController, DI Config, Routing)
**Dependencies:** Group 5 (service interface must exist)  
**Estimated Steps:** 8

- [x] 6.0 Complete API layer
  - [x] 6.1 Write 4 controller tests (test-first)
    - File: `tests/phpbb/api/Controller/NotificationsControllerTest.php`
    - Mock `NotificationServiceInterface&MockObject $service`, `EventDispatcherInterface&MockObject $dispatcher`
    - `countReturns401WhenNoUser` — `_api_user=null` in request attributes → response status 401
    - `countReturns200WithUnreadCount` — authenticated user, service returns count=3 → `body.data.unread=3`, header `X-Poll-Interval=30`
    - `markReadReturns204OnSuccess` — (M1 fix) service succeeds → HTTP 204, no body
    - `markReadReturns404WhenNotFound` — service throws `\InvalidArgumentException` → HTTP 404
    - Study: `tests/phpbb/api/Controller/MessagesControllerTest.php` for auth attribute pattern
  - [x] 6.2 Implement `NotificationsController`
    - File: `src/phpbb/api/Controller/NotificationsController.php`
    - `final class NotificationsController` in `phpbb\api\Controller` namespace
    - Symfony `#[Route]` attributes on each method (no separate `routes.yaml` entry needed — auto-discovered)
    - Auth helper at start of each method: `$user = $request->attributes->get('_api_user'); if ($user === null) return new JsonResponse(['error' => 'Authentication required'], 401)`
    - **Endpoint 1 — `count()`**: `GET /api/v1/notifications/count`
      - Get `$lastModifiedTs` from service (not cached)
      - Build `$response = new JsonResponse()`, set `X-Poll-Interval: 30`
      - If `$lastModifiedTs !== null`: `$response->setLastModified(new \DateTimeImmutable('@'.$lastModifiedTs))`
      - If `$request->isNotModified($response)`: return `$response` (Symfony auto-sets 304)
      - Fetch count, set `$response->setData(['data' => ['unread' => $count]])`
      - **M4 comment**: Add inline comment above `getLastModified()` call:
        ```php
        // Known trade-off: Last-Modified is derived from MAX(notification_time).
        // It does NOT update on markRead — clients using If-Modified-Since may
        // receive 304 with stale count for up to 30s after a mark-read.
        // Tag-cache invalidation ensures freshness for clients omitting If-Modified-Since.
        // See ADR-001 (polling) and spec Section 15 Endpoint 1.
        ```
    - **Endpoint 2 — `index()`**: `GET /api/v1/notifications`
      - Parse `PaginationContext::fromQuery($request->query)`
      - Map DTOs: `array_map(fn(NotificationDTO $dto) => $dto->toArray(), $result->items)`
      - **L1 fix**: `'lastPage' => max(1, $result->totalPages())`
      - Return `JsonResponse(['data' => $items, 'meta' => ['total' => ..., 'page' => ..., 'perPage' => ..., 'lastPage' => ...]])`
    - **Endpoint 3 — `markRead(int $id, Request $request)`**: `POST /api/v1/notifications/{id}/read`
      - Requirements: `['id' => '\d+']`
      - Try: call service, dispatch events
      - **M1 fix**: Return `new JsonResponse(status: 204)` on success (no body)
      - Catch `\InvalidArgumentException`: return `new JsonResponse(['error' => 'Notification not found'], 404)`
    - **Endpoint 4 — `markAllRead(Request $request)`**: `POST /api/v1/notifications/read`
      - Declare this route method BEFORE `markRead` in the file to avoid routing ambiguity (or rely on `\d+` constraint)
      - **M1 fix**: Return `new JsonResponse(status: 204)` on success (idempotent, always 204)
    - Wrap all public methods in `try/catch(\Throwable $e)` → `new JsonResponse(['error' => 'Internal server error'], 500)`
    - Study: `src/phpbb/api/Controller/MessagesController.php` for exact construction pattern
  - [x] 6.3 Update `services.yaml`
    - File: `src/phpbb/config/services.yaml`
    - Append M8 block after existing M7 messaging block (additive only — no existing entries modified)
    - Include all entries from spec Section 19:
      - `DbalNotificationRepository` with `$connection`
      - `NotificationRepositoryInterface` alias
      - `TypeRegistry` with `$dispatcher`
      - `MethodManager` with `$dispatcher`
      - `PostNotificationType` with `kernel.event_listener` tag for `RegisterNotificationTypesEvent`
      - `TopicNotificationType` same
      - `BoardNotificationMethod` with tag for `RegisterDeliveryMethodsEvent`
      - `EmailNotificationMethod` same
      - `NotificationService` with `$repository` + `$cacheFactory`
      - `NotificationServiceInterface` alias (`public: true`)
      - `CacheInvalidationSubscriber` with `$cacheFactory` + `kernel.event_subscriber` tag
    - `NotificationsController` is auto-discovered by existing `phpbb\api\Controller\:` resource block — no explicit entry
  - [x] 6.4 Verify routes are registered
    - Run: `docker exec phpbb_app php bin/phpbbcli.php debug:router | grep notification`
    - Confirm all 4 route names: `api_v1_notifications_count`, `api_v1_notifications_index`, `api_v1_notifications_mark_read`, `api_v1_notifications_mark_all_read`
  - [x] 6.5 Clear DI container cache
    - Run: `docker exec phpbb_app rm -rf /var/www/html/cache/phpbb4/production && docker restart phpbb_app` (required after `services.yaml` changes)
    - See debugging.md: stale compiled container causes 500 after DI changes
  - [x] 6.6 Verify controller tests pass
    - Run: `composer test -- --filter NotificationsControllerTest`
  - [x] 6.7 Run full PHPUnit suite
    - Run: `composer test`
    - Must pass (0 failures, 0 errors)
  - [x] 6.8 Run cs:fix
    - Run: `composer cs:fix`

**Acceptance Criteria:**
- All 4 controller tests pass
- All 4 routes visible in `debug:router` output
- `markRead` and `markAllRead` return HTTP 204 (M1 fix confirmed)
- M4 trade-off comment present in `count()` method
- L1 `max(1, ...)` present in `index()` response
- `composer test` passes with 0 failures

---

### Task Group 7: Unit Test Review & Gap Analysis
**Dependencies:** All Groups 2–6  
**Estimated Steps:** 4

- [x] 7.0 Review and fill critical PHPUnit gaps
  - [x] 7.1 Review all existing tests from Groups 2–6
    - Count current tests per file (target: ~32–40 total unit + integration)
    - Verify all audit fixes covered by at least one test:
      - H2: Two separate repository test classes exist ✓
      - M1: Controller test asserts 204 ✓
      - M2: Inspect `NotificationService` class declaration in service test `setUp` (or add assertion)
      - L3: `fromRowHandlesEmptyStringData` exists in `NotificationTest` ✓
  - [x] 7.2 Identify gaps (feature-specific only — not global suite coverage)
    - Edge cases not covered yet: `markRead` when already read, `listByUser` pagination with `perPage`, event dispatch from `DomainEventCollection`
    - Missing: `MethodManager` tests (identical to `TypeRegistry` — add 2-3 mirror tests)
    - Missing: `CacheInvalidationSubscriber::getSubscribedEvents()` assertion for correct handler names
  - [x] 7.3 Write up to 10 additional targeted tests
    - File: add to existing test files (do not create new files unless necessary)
    - Suggested additions (max 10):
      1. `MethodManagerTest::getByNameThrowsForUnknownMethod` — mirrors TypeRegistry
      2. `MethodManagerTest::allReturnsRegisteredMap` — mirrors TypeRegistry
      3. `NotificationTest::fromRowIncludesTypeName` — verify `typeName` mapped from `notification_type_name`
      4. `DbalNotificationRepositoryReadTest::listByUserReturnsEmptyForUnknownUser`
      5. `NotificationServiceTest::markAllReadInvokesRepository` — verify `$repo->markAllRead($userId)` called
      6. `CacheInvalidationSubscriberTest::getSubscribedEventsHasCorrectHandlerNames` — keys map to `onNotificationRead` / `onNotificationsReadAll`
      7. `NotificationsControllerTest::indexReturns401WhenNoUser`
      8. `NotificationsControllerTest::indexReturnsEmptyDataAndMeta`
      9. `DbalNotificationRepositoryWriteTest::markAllReadWhenAllAlreadyReadReturnsZero`
      10. `NotificationDTOTest::fromEntityCountsRespondersCorrectly` — `responderCount = count(responders)`
  - [x] 7.4 Run all notification-scope tests
    - Run: `composer test -- --filter 'notifications|Notifications'`
    - Expect: ~40–50 total, all passing

**Acceptance Criteria:**
- ~40–50 PHPUnit tests pass (unit + integration combined)
- No more than 10 additional tests added in this group
- All audit-fix scenarios have test coverage
- No regressions in pre-existing test suite (`composer test` 0 failures)

---

### Task Group 8: E2E Tests (Playwright)
**Dependencies:** Group 6 (container running, routes registered)  
**Estimated Steps:** 5

- [x] 8.0 Complete E2E test suite
  - [x] 8.1 Pre-seeded fixture setup (H1 fix)
    - In the new `test.describe('Notifications API (M8)')` block, add a `beforeAll` fixture:
      ```typescript
      test.beforeAll(async () => {
          // Seed one notification row directly via DB so Last-Modified is non-null.
          // Required for 304 conditional test (H1 audit fix).
          await db.execute(
              `INSERT IGNORE INTO phpbb_notifications
               (notification_type_id, item_id, item_parent_id, user_id,
                notification_read, notification_time, notification_data)
               VALUES (1, 1, 1, :userId, 0, :time, '{}')`,
              { userId: TEST_USER_ID, time: Math.floor(Date.now() / 1000) - 60 }
          );
      });
      ```
    - Study `tests/e2e/api.spec.ts` for existing `beforeAll` patterns and `db` helper usage
    - `afterAll`: clean up seeded row to avoid polluting other tests
  - [x] 8.2 Add Notifications API describe block
    - File: `tests/e2e/api.spec.ts`
    - Append new `test.describe('Notifications API (M8)', () => { ... })` block after existing M7 describe block
    - Reuse existing auth token setup from outer describe context
  - [x] 8.3 Implement auth guard E2E tests
    - `GET /notifications/count without auth — 401`
    - `GET /notifications without auth — 401`
    - `POST /notifications/read without auth — 401`
    - `POST /notifications/999/read without auth — 401`
  - [x] 8.4 Implement happy-path E2E tests
    - `GET /notifications/count — 200 with unread and correct headers`:
      - Assert `res.status() === 200`
      - Assert `body.data.unread >= 0` (integer)
      - Assert `res.headers()['x-poll-interval'] === '30'`
    - `GET /notifications/count — 304 when If-Modified-Since matches last modified` (H1 fix):
      - Pre-condition: DB has a seeded row (from `beforeAll`)
      - First: `GET /notifications/count` → extract `Last-Modified` header
      - Second: `GET /notifications/count` with `If-Modified-Since: <Last-Modified value>` → assert 304
      - Do NOT use future date trick — use the actual `Last-Modified` from first response
    - `GET /notifications — 200 with data array and meta`:
      - Assert `Array.isArray(body.data)`
      - Assert `body.meta` has keys: `total`, `page`, `perPage`, `lastPage`
      - Assert `body.meta.lastPage >= 1` (L1 fix verified end-to-end)
    - `POST /notifications/read — 204 even when no unread` (M1 fix):
      - Assert `res.status() === 204` (NOT 200, NOT `{status:'read'}`)
    - `POST /notifications/99999/read — 404 for non-existent notification`:
      - Assert `res.status() === 404`
  - [x] 8.5 Run Playwright E2E suite
    - Run: `composer test:e2e`
    - All 10 notification E2E cases must pass
    - Zero failures in pre-existing E2E tests

**Acceptance Criteria:**
- All ~10 E2E tests pass
- 304 test uses actual `Last-Modified` from prior response (not future date)
- `POST /notifications/read` returns 204, not 200 (M1 confirmed in E2E)
- `body.meta.lastPage >= 1` (L1 confirmed in E2E)
- `beforeAll` seeds row; `afterAll` cleans it up

---

## Execution Order

1. **Group 1 — Database Migration** (0 dependencies, run first)
2. **Group 2 — Domain Layer** (depends on 1: DB exists for integration context)
3. **Group 3 — Repository Layer** (depends on 2: entity needed)
4. **Group 4 — Extensibility Layer** (depends on 2: events needed)
5. **Group 5 — Service Layer** (depends on 3+4: repo + events)
6. **Group 6 — API Layer** (depends on 5: service interface)
7. **Group 7 — Unit Test Review** (depends on 2–6: all layers implemented)
8. **Group 8 — E2E Tests** (depends on 6: container running + routes registered)

---

## Standards Compliance

Follow standards from `.maister/docs/standards/`:

| Standard | Applicable To |
|----------|--------------|
| `global/STANDARDS.md` | All files — `final readonly class`, camelCase, PHPDoc, tabs |
| `backend/STANDARDS.md` | Entity, Repository, Service — QB-only SQL, DomainEventCollection, DI |
| `backend/REST_API.md` | Controller — thin layer, `JsonResponse`, status codes, `data` envelope |
| `testing/STANDARDS.md` | All tests — PHPUnit 10+ `#[Test]` attribute, AAA pattern, SQLite integration |

Key constraints:
- **Never interpolate user input into SQL** — always `setParameter()`
- **`final readonly class`** for entities, DTOs, events extending `DomainEvent`
- **`final class`** (not readonly) for service, subscriber, registry (mutable `$cache` / `$types` properties)
- **Named constructor arguments** in `new self(...)` calls (entities, DTOs)
- **`DomainEventCollection`** returned from all mutating service methods
- **PHPUnit `#[Test]` attribute** — not `/** @test */` annotation

---

## Notes

- **Test-Driven**: Each group (1–6) starts with 2-8 tests before implementation begins
- **Run Incrementally**: Only run the new group's tests after each group (not full suite until Group 7)
- **Mark Progress**: Check off steps as completed; use this file as the resume source of truth
- **Reuse First**: `DbalMessageRepository`, `MessagingService`, `MessagesController` are the authoritative M7 templates — copy structure, adapt names
- **Cache Clear**: After any `services.yaml` change, clear `cache/phpbb4/production` and restart container (see debugging.md)
- **Audit Fixes**: H1, H2, M1, M2, M4 are high-priority — verify each in its group's acceptance criteria before moving to the next group
