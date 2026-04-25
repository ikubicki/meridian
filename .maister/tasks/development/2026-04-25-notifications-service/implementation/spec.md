# Specification: M8 Notifications Service (`phpbb\notifications`)

---

## 1. Overview

### Purpose
Implement a standalone read-and-mark notifications service for phpBB under the `phpbb\notifications` namespace. The service exposes 4 REST endpoints that authenticated clients poll every 30 seconds to display an unread badge count and a notification list.

### Scope
**In scope (M8)**:
- 4 REST endpoints (count, list, mark single, mark all)
- HTTP Polling 30s with `Last-Modified` / `304 Not Modified`
- Tag-aware cache (30s TTL) with event-driven invalidation
- `TypeRegistry` with 2 built-in types (`notification.type.post`, `notification.type.topic`)
- `MethodManager` with `board` (full impl) and `email` (no-op stub) methods
- DB migration: TEXT → JSON on `notification_data`, new composite index
- PHPUnit unit + integration tests, Playwright E2E tests

**Out of scope (M8)**: notification creation pipeline, React `<NotificationBell>` component, email delivery implementation, subscription preferences API (`phpbb_user_notifications`).

### Template
M7 Messaging (`src/phpbb/messaging/`) is the direct architectural template — entity, repository, service, controller, events, tests all follow identical patterns.

### Standards
STANDARDS.md (`final readonly class`, PSR-4, constructor DI), REST_API.md (thin controllers, `JsonResponse`), DOMAIN_EVENTS.md (`DomainEventCollection`), TESTING.md (PHPUnit 10+, SQLite integration, AAA pattern).

---

## 2. Directory Structure

All new files to create:

```
src/phpbb/
├── notifications/
│   ├── Contract/
│   │   ├── NotificationRepositoryInterface.php
│   │   └── NotificationServiceInterface.php
│   ├── Entity/
│   │   └── Notification.php
│   ├── DTO/
│   │   └── NotificationDTO.php
│   ├── Repository/
│   │   └── DbalNotificationRepository.php
│   ├── Service/
│   │   └── NotificationService.php
│   ├── Type/
│   │   ├── NotificationTypeInterface.php
│   │   ├── PostNotificationType.php
│   │   └── TopicNotificationType.php
│   ├── Method/
│   │   ├── NotificationMethodInterface.php
│   │   ├── BoardNotificationMethod.php
│   │   └── EmailNotificationMethod.php
│   ├── Event/
│   │   ├── NotificationReadEvent.php
│   │   ├── NotificationsReadAllEvent.php
│   │   ├── RegisterNotificationTypesEvent.php
│   │   └── RegisterDeliveryMethodsEvent.php
│   ├── Listener/
│   │   └── CacheInvalidationSubscriber.php
│   ├── TypeRegistry.php
│   └── MethodManager.php
└── api/
    └── Controller/
        └── NotificationsController.php          ← new file in existing directory

tests/phpbb/notifications/
├── Entity/
│   └── NotificationTest.php
├── DTO/
│   └── NotificationDTOTest.php
├── Repository/
│   └── DbalNotificationRepositoryTest.php
├── Service/
│   └── NotificationServiceTest.php
├── Listener/
│   └── CacheInvalidationSubscriberTest.php
└── TypeRegistry/
    └── TypeRegistryTest.php

tests/e2e/
└── api.spec.ts                                  ← additive: new test.describe('Notifications API') block
```

Modified files:
- `src/phpbb/config/services.yaml` — additive M8 block appended

---

## 3. DB Migration

Execute once against the live database. The `phpbb_notifications` table is **empty** in the current install, making both operations instantaneous.

```sql
-- Step 1: Migrate TEXT → JSON (enables DB-level JSON validation; table is empty)
ALTER TABLE phpbb_notifications
    MODIFY COLUMN notification_data JSON NOT NULL;

-- Step 2: Replace sparse (user_id, notification_read) index with a covering
--         composite index that also supports ORDER BY notification_time queries.
--         Covers: COUNT(*) WHERE user_id+notification_read, list ORDER BY time,
--         and getLastModified MAX(notification_time) WHERE user_id.
ALTER TABLE phpbb_notifications DROP INDEX `user`;
ALTER TABLE phpbb_notifications
    ADD INDEX `user_read_time` (`user_id`, `notification_read`, `notification_time`);

-- Step 3: Seed built-in notification types (required for JOIN in repository)
INSERT IGNORE INTO phpbb_notification_types
    (`notification_type_name`, `notification_type_enabled`)
VALUES
    ('notification.type.post',  1),
    ('notification.type.topic', 1);
```

The existing `item_ident` index (`notification_type_id`, `item_id`) is retained for the creation pipeline (M8.x).

---

## 4. Entity Spec — `Notification`

**File**: `src/phpbb/notifications/Entity/Notification.php`  
**Namespace**: `phpbb\notifications\Entity`  
**Pattern**: `final readonly class` with `fromRow()` static factory (see STANDARDS.md).

The entity is hydrated from a JOIN of `phpbb_notifications` ⋈ `phpbb_notification_types` so it carries both `typeId` (FK) and `typeName` (human-readable, used in DTO `type` field).

### Fields

| Property | Type | DB column |
|---|---|---|
| `notificationId` | `int` | `phpbb_notifications.notification_id` |
| `typeId` | `int` | `phpbb_notifications.notification_type_id` |
| `typeName` | `string` | `phpbb_notification_types.notification_type_name` (joined) |
| `itemId` | `int` | `phpbb_notifications.item_id` |
| `itemParentId` | `int` | `phpbb_notifications.item_parent_id` |
| `userId` | `int` | `phpbb_notifications.user_id` |
| `read` | `bool` | `phpbb_notifications.notification_read` |
| `createdAt` | `int` | `phpbb_notifications.notification_time` (Unix timestamp) |
| `data` | `array` | `phpbb_notifications.notification_data` (decoded JSON) |

### `fromRow()` factory

```
fromRow(array $row): self
```

Casts all scalar types explicitly (`(int)`, `(bool)`, `(string)`). Decodes `notification_data` using `json_decode($row['notification_data'], true, 512, JSON_THROW_ON_ERROR)`, defaulting to `[]` on empty string. Includes `typeName` from the joined column `notification_type_name`.

---

## 5. Entity Spec — `NotificationType`

**Used only in tests and seeding** — not exposed as a domain entity in M8 service layer. The type name is embedded directly in `Notification::typeName` via the repository JOIN.

If a standalone entity is needed (e.g., by TypeRegistry lazy-loading from DB), implement it as:

```
phpbb\notifications\Entity\NotificationType
  notificationTypeId: int
  name: string
  enabled: bool
  fromRow(array $row): self
```

Not required for M8 but documented for M8.x reference.

---

## 6. DTO Spec — `NotificationDTO`

**File**: `src/phpbb/notifications/DTO/NotificationDTO.php`  
**Namespace**: `phpbb\notifications\DTO`  
**Pattern**: `final readonly class` with `fromEntity()` and `toArray()`.

### JSON Shape

```json
{
    "id": 42,
    "type": "notification.type.post",
    "unread": true,
    "createdAt": 1745612345,
    "data": {
        "itemId": 100,
        "itemParentId": 5,
        "responders": [
            { "userId": 3, "username": "alice" }
        ],
        "responderCount": 1
    }
}
```

### Constructor Fields

| Property | Type | Source |
|---|---|---|
| `id` | `int` | `Notification::notificationId` |
| `type` | `string` | `Notification::typeName` |
| `unread` | `bool` | `!Notification::read` |
| `createdAt` | `int` | `Notification::createdAt` |
| `data` | `array` | See below |

### `data` Field Construction

```
'itemId'        => $notification->itemId,
'itemParentId'  => $notification->itemParentId,
'responders'    => $notification->data['responders'] ?? [],
'responderCount'=> count($notification->data['responders'] ?? []),
```

The `responders` value is stored at write-time in `notification_data` JSON by the creation pipeline (ADR-002). In M8 the read path surfaces it verbatim.

### `toArray()` Method

Returns the associative array matching the JSON shape above, used by the controller for `JsonResponse` serialization.

### `fromEntity()` Factory

```
public static function fromEntity(Notification $notification): self
```

Named arguments in `new self(...)` call (STANDARDS.md requirement).

---

## 7. Repository Interface

**File**: `src/phpbb/notifications/Contract/NotificationRepositoryInterface.php`  
**Namespace**: `phpbb\notifications\Contract`

```php
interface NotificationRepositoryInterface
{
    /**
     * Find a single notification by ID, scoped to userId for ownership check.
     * Returns null if not found or if user_id does not match.
     */
    public function findById(int $notificationId, int $userId): ?Notification;

    /**
     * Count unread notifications for a user.
     * Uses composite index (user_id, notification_read, notification_time).
     */
    public function countUnread(int $userId): int;

    /**
     * Return MAX(notification_time) for the user's notifications.
     * Returns null when user has no notifications.
     * Used as the Last-Modified timestamp for HTTP 304 logic.
     */
    public function getLastModified(int $userId): ?int;

    /**
     * Return paginated notification list, mapped to NotificationDTO.
     * JOINs phpbb_notification_types to populate typeName on entity.
     * Ordered by notification_time DESC.
     */
    public function listByUser(int $userId, PaginationContext $ctx): PaginatedResult;

    /**
     * Mark a single notification as read, scoped to userId.
     * Returns true if a row was updated (notification found and owned by user).
     * Returns false if notification not found or does not belong to user.
     */
    public function markRead(int $notificationId, int $userId): bool;

    /**
     * Mark all unread notifications as read for the given user.
     * Returns the number of rows updated (0 if all already read).
     */
    public function markAllRead(int $userId): int;
}
```

Imports (to be explicit in interface file):
- `phpbb\notifications\Entity\Notification`
- `phpbb\api\DTO\PaginationContext`
- `phpbb\user\DTO\PaginatedResult`

---

## 8. Repository Implementation

**File**: `src/phpbb/notifications/Repository/DbalNotificationRepository.php`  
**Namespace**: `phpbb\notifications\Repository`  
**Implements**: `NotificationRepositoryInterface`  
**Pattern**: Doctrine DBAL QueryBuilder only, `setParameter()` for all user-supplied values, private `hydrate()` method.

### Constants

```php
private const NOTIFICATIONS_TABLE = 'phpbb_notifications';
private const TYPES_TABLE         = 'phpbb_notification_types';
```

### Constructor

```php
public function __construct(
    private readonly \Doctrine\DBAL\Connection $connection,
)
```

### Method Implementations

#### `findById(int $notificationId, int $userId): ?Notification`

```
SELECT n.*, t.notification_type_name
FROM phpbb_notifications n
LEFT JOIN phpbb_notification_types t ON t.notification_type_id = n.notification_type_id
WHERE n.notification_id = :id AND n.user_id = :userId
LIMIT 1
```

Returns `$this->hydrate($row)` or `null` if `fetchAssociative()` returns `false`.

#### `countUnread(int $userId): int`

```
SELECT COUNT(*)
FROM phpbb_notifications
WHERE user_id = :userId AND notification_read = 0
```

Returns `(int) fetchOne()`.

#### `getLastModified(int $userId): ?int`

```
SELECT MAX(notification_time)
FROM phpbb_notifications
WHERE user_id = :userId
```

Returns `(int) fetchOne()` when non-null, `null` otherwise. The `MAX()` returns DB NULL when no rows exist — handle via `$result !== false && $result !== null ? (int) $result : null`.

#### `listByUser(int $userId, PaginationContext $ctx): PaginatedResult`

```
-- count query (clone of base QB):
SELECT COUNT(*) FROM phpbb_notifications WHERE user_id = :userId

-- data query:
SELECT n.*, t.notification_type_name
FROM phpbb_notifications n
LEFT JOIN phpbb_notification_types t ON t.notification_type_id = n.notification_type_id
WHERE n.user_id = :userId
ORDER BY n.notification_time DESC
LIMIT :perPage OFFSET :offset
```

`$offset = ($ctx->page - 1) * $ctx->perPage`

Returns `PaginatedResult` with `items` as `array<NotificationDTO>` (items are mapped with `NotificationDTO::fromEntity($this->hydrate($row))`).

#### `markRead(int $notificationId, int $userId): bool`

```
UPDATE phpbb_notifications
SET notification_read = 1
WHERE notification_id = :id AND user_id = :userId AND notification_read = 0
```

Returns `$this->connection->executeStatement(...) > 0`.

#### `markAllRead(int $userId): int`

```
UPDATE phpbb_notifications
SET notification_read = 1
WHERE user_id = :userId AND notification_read = 0
```

Returns `(int) $this->connection->executeStatement(...)`.

### Private `hydrate(array $row): Notification`

Delegates to `Notification::fromRow($row)`. Centralizes the hydration call so `findById` and `listByUser` both go through a single point.

### Exception Handling

Every public method wraps its logic in `try/catch (\Doctrine\DBAL\Exception $e)` and re-throws as `\phpbb\db\Exception\RepositoryException('...', previous: $e)` — mirrors the `DbalMessageRepository` pattern.

---

## 9. `NotificationTypeInterface`

**File**: `src/phpbb/notifications/Type/NotificationTypeInterface.php`  
**Namespace**: `phpbb\notifications\Type`

```php
interface NotificationTypeInterface
{
    /**
     * Unique type name, e.g. 'notification.type.post'.
     * Must match the value stored in phpbb_notification_types.notification_type_name.
     */
    public function getTypeName(): string;
}
```

> NOTE: Methods for recipient determination, display rendering, and email subject
> (used by the creation pipeline in M8.x) are intentionally omitted from M8 scope.
> This interface is minimal — only `getTypeName()` is needed by TypeRegistry for M8.

---

## 10. `NotificationMethodInterface`

**File**: `src/phpbb/notifications/Method/NotificationMethodInterface.php`  
**Namespace**: `phpbb\notifications\Method`

```php
interface NotificationMethodInterface
{
    /**
     * Unique method name, e.g. 'board' or 'email'.
     */
    public function getMethodName(): string;
}
```

> NOTE: Dispatch methods (`notify()`, `markRead()`, `getPreferences()`) used by the
> creation pipeline are out of M8 scope. The interface is minimal for registry purposes.

---

## 11. `TypeRegistry`

**File**: `src/phpbb/notifications/TypeRegistry.php`  
**Namespace**: `phpbb\notifications`

### Responsibility
Lazy-initialize a map of `name → NotificationTypeInterface` by dispatching `RegisterNotificationTypesEvent` on first access. Caches the map in a private property to avoid repeated dispatches.

### Constructor

```php
public function __construct(
    private readonly EventDispatcherInterface $dispatcher,
)
```

### Private `$types` Property

```php
/** @var array<string, NotificationTypeInterface>|null */
private ?array $types = null;
```

### `initialize()` (private)

```php
if ($this->types !== null) {
    return;
}
$event = new RegisterNotificationTypesEvent();
$this->dispatcher->dispatch($event);
$this->types = $event->getTypes();
```

### Public Methods

```php
/**
 * Look up a type by name. Throws InvalidArgumentException for unknown names.
 */
public function getByName(string $typeName): NotificationTypeInterface

/**
 * Return the full map of all registered types.
 * @return array<string, NotificationTypeInterface>
 */
public function all(): array
```

Both call `$this->initialize()` before accessing `$this->types`.

---

## 12. `MethodManager`

**File**: `src/phpbb/notifications/MethodManager.php`  
**Namespace**: `phpbb\notifications`

Identical lazy-dispatch pattern to `TypeRegistry`, but uses `RegisterDeliveryMethodsEvent` and `NotificationMethodInterface`.

### Constructor

```php
public function __construct(
    private readonly EventDispatcherInterface $dispatcher,
)
```

### Private `$methods` Property

```php
/** @var array<string, NotificationMethodInterface>|null */
private ?array $methods = null;
```

### `initialize()` (private)

```php
if ($this->methods !== null) {
    return;
}
$event = new RegisterDeliveryMethodsEvent();
$this->dispatcher->dispatch($event);
$this->methods = $event->getMethods();
```

### Public Methods

```php
public function getByName(string $name): NotificationMethodInterface   // throws InvalidArgumentException
public function all(): array                                            // array<string, NotificationMethodInterface>
```

---

## 13. `NotificationService`

**File**: `src/phpbb/notifications/Service/NotificationService.php`  
**Namespace**: `phpbb\notifications\Service`  
**Implements**: `NotificationServiceInterface`

### Constructor

```php
public function __construct(
    private readonly NotificationRepositoryInterface $repository,
    CachePoolFactoryInterface $cacheFactory,
)
```

Inside the constructor body (not promotion), create the cache pool:

```php
$this->cache = $cacheFactory->getPool('notifications');
```

Add a private non-readonly property:

```php
private readonly TagAwareCacheInterface $cache;
```

`CachePoolFactory::getPool()` is lightweight (no I/O), safe to call in constructor.

### Cache Keys & Tags

| Key pattern | Tags | TTL |
|---|---|---|
| `user:{userId}:count` | `["user:{userId}"]` | 30s |
| `user:{userId}:notifications:{page}` | `["user:{userId}"]` | 30s |

### Public Methods

#### `getUnreadCount(int $userId): int`

```php
return (int) $this->cache->getOrCompute(
    key: "user:{$userId}:count",
    compute: fn () => $this->repository->countUnread($userId),
    ttl: 30,
    tags: ["user:{$userId}"],
);
```

#### `getLastModified(int $userId): ?int`

```php
return $this->repository->getLastModified($userId);
```

Not cached. The indexed `MAX()` query is fast (< 5ms). The timestamp only changes when new notifications are created — not on mark-read — so stale cache would cause 304 false positives. Direct DB read is correct here.

#### `getNotifications(int $userId, PaginationContext $ctx): PaginatedResult`

```php
return $this->cache->getOrCompute(
    key: "user:{$userId}:notifications:{$ctx->page}",
    compute: fn () => $this->repository->listByUser($userId, $ctx),
    ttl: 30,
    tags: ["user:{$userId}"],
);
```

#### `markRead(int $notificationId, int $userId): DomainEventCollection`

```php
$updated = $this->repository->markRead($notificationId, $userId);
if (!$updated) {
    throw new \InvalidArgumentException(
        "Notification {$notificationId} not found or not owned by user {$userId}"
    );
}
return new DomainEventCollection([
    new NotificationReadEvent(entityId: $notificationId, actorId: $userId),
]);
```

#### `markAllRead(int $userId): DomainEventCollection`

```php
$this->repository->markAllRead($userId);  // result count is discarded (idempotent)
return new DomainEventCollection([
    new NotificationsReadAllEvent(entityId: $userId, actorId: $userId),
]);
```

`entityId = $userId` is used as a sentinel (no single notification entity in "mark all" context).

---

## 14. `NotificationServiceInterface`

**File**: `src/phpbb/notifications/Contract/NotificationServiceInterface.php`  
**Namespace**: `phpbb\notifications\Contract`

```php
interface NotificationServiceInterface
{
    public function getUnreadCount(int $userId): int;
    public function getLastModified(int $userId): ?int;
    public function getNotifications(int $userId, PaginationContext $ctx): PaginatedResult;
    public function markRead(int $notificationId, int $userId): DomainEventCollection;
    public function markAllRead(int $userId): DomainEventCollection;
}
```

---

## 15. `NotificationsController`

**File**: `src/phpbb/api/Controller/NotificationsController.php`  
**Namespace**: `phpbb\api\Controller`  
**Pattern**: Thin routing layer — no business logic (REST_API.md).

### Constructor

```php
public function __construct(
    private readonly NotificationServiceInterface $notificationService,
    private readonly EventDispatcherInterface $dispatcher,
)
```

### Auth Helper

All 4 methods begin with the same pattern (mirrors `MessagesController`):

```php
/** @var \phpbb\user\Entity\User|null $user */
$user = $request->attributes->get('_api_user');
if ($user === null) {
    return new JsonResponse(['error' => 'Authentication required'], 401);
}
```

---

### Endpoint 1 — `count()`

```
GET /api/v1/notifications/count
Route name: api_v1_notifications_count
Auth: required
```

**Logic**:

1. Auth check → 401 if missing.
2. Get `?int $lastModifiedTs = $this->notificationService->getLastModified($user->id)`.
3. Build `$response = new JsonResponse()` (empty for now).
4. Set `$response->headers->set('X-Poll-Interval', '30')`.
5. If `$lastModifiedTs !== null`: call `$response->setLastModified(new \DateTimeImmutable('@' . $lastModifiedTs))`.
6. If `$request->isNotModified($response)`: return `$response` immediately (Symfony auto-sets 304, removes body).
7. Get count: `$count = $this->notificationService->getUnreadCount($user->id)`.
8. Set data: `$response->setData(['data' => ['unread' => $count]])`.
9. Return `$response` (200).

**Response headers** (all responses):
- `Last-Modified: <RFC 7231 date>` (if user has any notifications)
- `X-Poll-Interval: 30`

**Status codes**:
- `200` — count returned
- `304` — client's cache is still valid
- `401` — no token
- `500` — unexpected error (try/catch \Throwable)

---

### Endpoint 2 — `index()`

```
GET /api/v1/notifications
Route name: api_v1_notifications_index
Auth: required
```

**Logic**:

1. Auth check → 401.
2. Parse `$ctx = PaginationContext::fromQuery($request->query)`.
3. `$result = $this->notificationService->getNotifications($user->id, $ctx)`.
4. Map items: `array_map(fn (NotificationDTO $dto) => $dto->toArray(), $result->items)`.
5. Return `JsonResponse` with standard paginated shape.

**Response shape** (200):

```json
{
    "data": [ { /* NotificationDTO */ }, ... ],
    "meta": {
        "total": 42,
        "page": 1,
        "perPage": 25,
        "lastPage": 2
    }
}
```

**Status codes**: `200`, `401`, `500`.

---

### Endpoint 3 — `markRead(int $id, Request $request)`

```
POST /api/v1/notifications/{id}/read
Route name: api_v1_notifications_mark_read
Requirements: id=\d+
Auth: required
```

**Why `requirements: ['id' => '\d+']`**: Symfony must not match the string `"read"` from endpoint 4's path as an `$id`. The `\d+` constraint ensures only numeric segments match this route.

**Logic**:

1. Auth check → 401.
2. Try `$events = $this->notificationService->markRead($id, $user->id)`.
3. `$events->dispatch($this->dispatcher)`.
4. Return `new JsonResponse(['status' => 'read'])` (200).
5. On `\InvalidArgumentException` → `new JsonResponse(['error' => 'Notification not found'], 404)`.
6. On `\Throwable` → 500.

**Status codes**: `200`, `401`, `404` (not found or wrong owner), `500`.

---

### Endpoint 4 — `markAllRead(Request $request)`

```
POST /api/v1/notifications/read
Route name: api_v1_notifications_mark_all_read
Auth: required
```

**Logic**:

1. Auth check → 401.
2. `$events = $this->notificationService->markAllRead($user->id)`.
3. `$events->dispatch($this->dispatcher)`.
4. Return `new JsonResponse(['status' => 'read'])` (200).
5. On `\Throwable` → 500.

**Status codes**: `200` (always, even if 0 notifications updated — idempotent), `401`, `500`.

---

### Route Ordering Note

In `#[Route]` attribute configuration, both POST routes must be declared in the correct order in the PHP file — Symfony resolves route conflicts based on specificity. The fixed-path route `POST /notifications/read` must be declared **before** `POST /notifications/{id}/read`, or the `\d+` requirement on `{id}` makes the order irrelevant (preferred approach).

---

## 16. Event Classes

### 16a. `NotificationReadEvent`

**File**: `src/phpbb/notifications/Event/NotificationReadEvent.php`  
**Namespace**: `phpbb\notifications\Event`  
**Extends**: `phpbb\common\Event\DomainEvent`  
**Pattern**: `final readonly class`, empty body (no extra fields needed).

```php
final readonly class NotificationReadEvent extends DomainEvent
{
    // entityId = notificationId, actorId = userId (owner)
    // No extra fields for M8 scope.
}
```

### 16b. `NotificationsReadAllEvent`

**File**: `src/phpbb/notifications/Event/NotificationsReadAllEvent.php`  
**Pattern**: `final readonly class`, empty body.

```php
final readonly class NotificationsReadAllEvent extends DomainEvent
{
    // entityId = userId (used as sentinel — no single entity in "mark all" context)
    // actorId = userId
}
```

### 16c. `RegisterNotificationTypesEvent`

**File**: `src/phpbb/notifications/Event/RegisterNotificationTypesEvent.php`  
**NOT a `DomainEvent`** — this is a Symfony synchronous event for registry initialization.

```php
final class RegisterNotificationTypesEvent
{
    /** @var array<string, NotificationTypeInterface> */
    private array $types = [];

    public function addType(NotificationTypeInterface $type): void
    {
        $this->types[$type->getTypeName()] = $type;
    }

    /** @return array<string, NotificationTypeInterface> */
    public function getTypes(): array
    {
        return $this->types;
    }
}
```

### 16d. `RegisterDeliveryMethodsEvent`

**File**: `src/phpbb/notifications/Event/RegisterDeliveryMethodsEvent.php`  
**NOT a `DomainEvent`** — Symfony event for method registry initialization.

```php
final class RegisterDeliveryMethodsEvent
{
    /** @var array<string, NotificationMethodInterface> */
    private array $methods = [];

    public function addMethod(NotificationMethodInterface $method): void
    {
        $this->methods[$method->getMethodName()] = $method;
    }

    /** @return array<string, NotificationMethodInterface> */
    public function getMethods(): array
    {
        return $this->methods;
    }
}
```

---

## 17. Built-in Types and Methods

### `PostNotificationType`

**File**: `src/phpbb/notifications/Type/PostNotificationType.php`

```php
final class PostNotificationType implements NotificationTypeInterface
{
    public function getTypeName(): string
    {
        return 'notification.type.post';
    }

    /**
     * Event listener: registers this type into the TypeRegistry.
     * Bound in services.yaml via kernel.event_listener tag.
     */
    public function register(RegisterNotificationTypesEvent $event): void
    {
        $event->addType($this);
    }
}
```

### `TopicNotificationType`

Identical structure, `getTypeName()` returns `'notification.type.topic'`.

### `BoardNotificationMethod`

**File**: `src/phpbb/notifications/Method/BoardNotificationMethod.php`

```php
final class BoardNotificationMethod implements NotificationMethodInterface
{
    public function getMethodName(): string
    {
        return 'board';
    }

    public function register(RegisterDeliveryMethodsEvent $event): void
    {
        $event->addMethod($this);
    }
}
```

> Board method write operations (notify, mark-read via method layer) are part of the creation
> pipeline (M8.x). For M8, the board method is registered but its dispatch methods are not called.

### `EmailNotificationMethod`

Identical structure, `getMethodName()` returns `'email'`. No-op stub.

```php
final class EmailNotificationMethod implements NotificationMethodInterface
{
    public function getMethodName(): string
    {
        return 'email';
    }

    public function register(RegisterDeliveryMethodsEvent $event): void
    {
        $event->addMethod($this);
    }
}
```

---

## 18. `CacheInvalidationSubscriber`

**File**: `src/phpbb/notifications/Listener/CacheInvalidationSubscriber.php`  
**Namespace**: `phpbb\notifications\Listener`  
**Implements**: `Symfony\Component\EventDispatcher\EventSubscriberInterface`

### Responsibility
Listen to `NotificationReadEvent` and `NotificationsReadAllEvent`; invalidate the `user:{userId}` tag in the notifications cache pool. This clears all cached entries for the affected user (`count` + all `notifications:{page}` entries).

### Constructor

```php
public function __construct(
    CachePoolFactoryInterface $cacheFactory,
)
```

Inside constructor body:

```php
$this->cache = $cacheFactory->getPool('notifications');
```

Private property:

```php
private readonly TagAwareCacheInterface $cache;
```

### `getSubscribedEvents()`

```php
return [
    NotificationReadEvent::class    => 'onNotificationRead',
    NotificationsReadAllEvent::class => 'onNotificationsReadAll',
];
```

### Handlers

```php
public function onNotificationRead(NotificationReadEvent $event): void
{
    $this->cache->invalidateTags(["user:{$event->actorId}"]);
}

public function onNotificationsReadAll(NotificationsReadAllEvent $event): void
{
    $this->cache->invalidateTags(["user:{$event->actorId}"]);
}
```

`$event->actorId` is the userId in both cases (the user who performed the action).

---

## 19. DI Configuration

Append the following block to `src/phpbb/config/services.yaml` **after the existing M7 messaging block**:

```yaml
    # ---------------------------------------------------------------------------
    # Notifications module (M8)
    # ---------------------------------------------------------------------------

    phpbb\notifications\Repository\DbalNotificationRepository:
        arguments:
            $connection: '@Doctrine\DBAL\Connection'

    phpbb\notifications\Contract\NotificationRepositoryInterface:
        alias: phpbb\notifications\Repository\DbalNotificationRepository

    phpbb\notifications\TypeRegistry:
        arguments:
            $dispatcher: '@Symfony\Component\EventDispatcher\EventDispatcherInterface'

    phpbb\notifications\MethodManager:
        arguments:
            $dispatcher: '@Symfony\Component\EventDispatcher\EventDispatcherInterface'

    # Built-in types — each registers itself via kernel.event_listener
    phpbb\notifications\Type\PostNotificationType:
        tags:
            - name: kernel.event_listener
              event: phpbb\notifications\Event\RegisterNotificationTypesEvent
              method: register

    phpbb\notifications\Type\TopicNotificationType:
        tags:
            - name: kernel.event_listener
              event: phpbb\notifications\Event\RegisterNotificationTypesEvent
              method: register

    # Built-in delivery methods
    phpbb\notifications\Method\BoardNotificationMethod:
        tags:
            - name: kernel.event_listener
              event: phpbb\notifications\Event\RegisterDeliveryMethodsEvent
              method: register

    phpbb\notifications\Method\EmailNotificationMethod:
        tags:
            - name: kernel.event_listener
              event: phpbb\notifications\Event\RegisterDeliveryMethodsEvent
              method: register

    phpbb\notifications\Service\NotificationService:
        arguments:
            $repository:    '@phpbb\notifications\Contract\NotificationRepositoryInterface'
            $cacheFactory:  '@phpbb\cache\CachePoolFactoryInterface'

    phpbb\notifications\Contract\NotificationServiceInterface:
        alias: phpbb\notifications\Service\NotificationService
        public: true

    phpbb\notifications\Listener\CacheInvalidationSubscriber:
        arguments:
            $cacheFactory: '@phpbb\cache\CachePoolFactoryInterface'
        tags:
            - { name: kernel.event_subscriber }
```

> `phpbb\api\Controller\NotificationsController` is auto-discovered by the existing
> `phpbb\api\Controller\:` resource block (line 7 of services.yaml) — no explicit entry needed.

---

## 20. Unit Test Plan

All tests live under `tests/phpbb/notifications/`. Namespace: `phpbb\Tests\notifications\`. PHPUnit 10+ — use `#[Test]` attribute and AAA structure (TESTING.md).

### 2–4 tests per group (2–8 limit per step group)

---

### Group A — `NotificationTest` (Entity)

**File**: `tests/phpbb/notifications/Entity/NotificationTest.php`

| Test | Scenario |
|---|---|
| `fromRowMapsAllFields` | All DB row values correctly cast to typed properties |
| `fromRowDecodesJsonData` | `notification_data` JSON string decoded to `array` |
| `fromRowDefaultsDataToEmptyArray` | Empty JSON `'{}'` or `'[]'` results in `[]` for `data` |
| `fromRowSetsReadBool` | `notification_read = 0` → `read = false`, `= 1` → `read = true` |

---

### Group B — `NotificationDTOTest`

**File**: `tests/phpbb/notifications/DTO/NotificationDTOTest.php`

| Test | Scenario |
|---|---|
| `fromEntityMapsId` | DTO `id` equals entity `notificationId` |
| `fromEntitySetsUnread` | `read=false` → `unread=true`, `read=true` → `unread=false` |
| `fromEntityBuildsDataArray` | `responders`, `responderCount`, `itemId`, `itemParentId` correct |
| `toArrayMatchesJsonShape` | `toArray()` keys match documented JSON shape |

---

### Group C — `DbalNotificationRepositoryTest` (Integration — SQLite)

**File**: `tests/phpbb/notifications/Repository/DbalNotificationRepositoryTest.php`  
**Extends**: `phpbb\Tests\Integration\IntegrationTestCase`  
**`setUpSchema()`** creates both `phpbb_notifications` (JSON column → use TEXT for SQLite compat) and `phpbb_notification_types`.

| Test | Scenario |
|---|---|
| `findByIdReturnsNullForMissing` | ID 999 → null |
| `findByIdReturnsScopedToUser` | Existing ID with wrong userId → null |
| `findByIdReturnsHydratedEntity` | Correct ID + userId → Notification with typeName |
| `countUnreadReturnsZeroByDefault` | No rows → 0 |
| `countUnreadCountsOnlyUnread` | 3 rows: 2 unread, 1 read → 2 |
| `getLastModifiedReturnsNullWhenEmpty` | No rows → null |
| `getLastModifiedReturnsMaxTime` | 2 rows with different times → higher time |
| `listByUserReturnsPaginatedDTOs` | 3 rows → PaginatedResult with correct total, items as NotificationDTO |
| `markReadReturnsTrueForOwn` | Unread notification + correct userId → true, row updated |
| `markReadReturnsFalseForOther` | Unread notification + wrong userId → false |
| `markAllReadUpdatesAllUnread` | 3 unread → 3 updated, `countUnread()` returns 0 after |

---

### Group D — `NotificationServiceTest` (Unit — mocks)

**File**: `tests/phpbb/notifications/Service/NotificationServiceTest.php`  

Mock intersection types:
- `NotificationRepositoryInterface&MockObject $repo`
- `CachePoolFactoryInterface&MockObject $cacheFactory`
- `TagAwareCacheInterface&MockObject $cache`

`$cacheFactory->method('getPool')->with('notifications')->willReturn($cache)` in `setUp()`.

| Test | Scenario |
|---|---|
| `getUnreadCountDelegatesToCache` | `getOrCompute` called with correct key, TTL=30, tag |
| `getUnreadCountInvokesFallback` | Cache compute callable calls `$repo->countUnread()` |
| `getLastModifiedDelegatesToRepository` | `$repo->getLastModified()` called directly (no cache) |
| `getNotificationsUsesPerPageCache` | Key includes `$ctx->page`, TTL=30, user tag |
| `markReadThrowsForNotFound` | `$repo->markRead()` returns false → `\InvalidArgumentException` |
| `markReadReturnsDomainEventCollection` | `$repo->markRead()` returns true → correct event class + entityId |
| `markAllReadReturnsDomainEventCollection` | Returns `NotificationsReadAllEvent` with entityId=userId |

---

### Group E — `CacheInvalidationSubscriberTest`

**File**: `tests/phpbb/notifications/Listener/CacheInvalidationSubscriberTest.php`

| Test | Scenario |
|---|---|
| `subscribesToBothEvents` | `getSubscribedEvents()` keys = `[NotificationReadEvent::class, NotificationsReadAllEvent::class]` |
| `onNotificationReadInvalidatesUserTag` | `$cache->invalidateTags(["user:42"])` called with actorId from event |
| `onNotificationsReadAllInvalidatesUserTag` | Same tag pattern with actorId |

---

### Group F — `TypeRegistryTest`

**File**: `tests/phpbb/notifications/TypeRegistry/TypeRegistryTest.php`

| Test | Scenario |
|---|---|
| `getByNameDispatchesEventLazily` | `$dispatcher->dispatch()` called once per `initialize()` |
| `getByNameDeduplicatesDispatch` | Two `getByName()` calls → `dispatch()` called only once |
| `getByNameThrowsForUnknownType` | Unknown name → `\InvalidArgumentException` |
| `allReturnsRegisteredMap` | Event populates types → `all()` returns same map |

---

## 21. E2E Test Plan

Append a new `test.describe('Notifications API (M8)')` block to `tests/e2e/api.spec.ts`, after the existing M7 Messaging block. All tests run inside the existing test file's auth context (tokens from earlier `login` test).

### Test Cases

```typescript
test.describe('Notifications API (M8)', () => {

    // Auth guards
    test('GET /notifications/count without auth — 401')
    test('GET /notifications without auth — 401')
    test('POST /notifications/read without auth — 401')
    test('POST /notifications/999/read without auth — 401')

    // GET /notifications/count — happy path (empty state)
    test('GET /notifications/count — 200 with unread=0 and correct headers')
    // Asserts:
    //   res.status() === 200
    //   body.data.unread === 0 (or >= 0 — integer)
    //   res.headers()['x-poll-interval'] === '30'

    // GET /notifications/count — 304 conditional
    test('GET /notifications/count — 304 when If-Modified-Since is in the future')
    // Sends: If-Modified-Since: <date 1 year from now>
    // Asserts: res.status() === 304

    // GET /notifications — happy path (empty state)
    test('GET /notifications — 200 with empty data array and meta')
    // Asserts:
    //   res.status() === 200
    //   Array.isArray(body.data) === true
    //   body.meta matches { total: expect.any(Number), page: 1, perPage: ..., lastPage: ... }

    // POST /notifications/read — mark all (empty — idempotent)
    test('POST /notifications/read — 200 even when no unread notifications')
    // Asserts: res.status() === 200, body.status === 'read'

    // POST /notifications/{id}/read — non-existent ID
    test('POST /notifications/99999/read — 404 for non-existent notification')
    // Asserts: res.status() === 404

})
```

---

## 22. Scope Exclusions

The following are **explicitly out of scope for M8**:

| Excluded | Rationale |
|---|---|
| Notification creation pipeline (`createNotification()`) | Separate milestone (M8.x); requires event listeners on post/topic creation |
| React `<NotificationBell>` component and `useNotifications` hook | Deferred to M10 |
| Email delivery implementation in `EmailNotificationMethod` | No-op stub only; SMTP configuration out of scope |
| Subscription preferences API (`phpbb_user_notifications` table) | Creation-side concern; not needed by read endpoints |
| `TypeRegistry::getByName()` called during M8 request cycle | Types are registered but never resolved during read/mark-read operations |
| `MethodManager` dispatch calls during M8 request cycle | Methods are registered but never dispatched in M8 |
| WebSocket / SSE / long polling | ADR-001 decided HTTP polling 30s |
| Notification deletion endpoint | Not in requirements |
| Admin notification management endpoints | Not in requirements |
| `phpbb_notification_emails` table access | Legacy table; not used in M8 |

---

## Verification Checklist

- [ ] All 6 requirements from `analysis/requirements.md` addressed
- [ ] 4 REST endpoints fully specced with HTTP status codes and headers
- [ ] `Last-Modified` / `304` logic correct (based on `MAX(notification_time)`)
- [ ] Cache invalidation fires on both `markRead` and `markAllRead`
- [ ] TypeRegistry lazily initializes (first-access dispatch, no repeated calls)
- [ ] No over-engineering: `TypeRegistry.getByName()` and `MethodManager.getByName()` are defined but not called within M8 service flow
- [ ] `notification_data` JSON schema aligns with ADR-002 responder aggregation format
- [ ] All test groups have 2–8 tests per group (Groups A–F: 4, 4, 11, 7, 3, 4)
- [ ] `services.yaml` additions are additive only (no existing entries modified)
- [ ] DB migration is safe: table is empty, ALTER is instantaneous

---

*Specification generated 2026-04-25. Template: M7 Messaging. Research: ADR-001–ADR-007.*
