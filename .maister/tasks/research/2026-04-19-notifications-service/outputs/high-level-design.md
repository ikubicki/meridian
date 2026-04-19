# High-Level Design: phpBB Notifications Service

## Design Overview

**Business context**: phpBB's forum needs real-time user notifications (Facebook-style bell badge + dropdown) delivered via REST API. The existing legacy notification system is server-side rendered with no API, no polling, and updates only on page reload. Users expect instant awareness of replies, mentions, and messages without refreshing.

**Chosen approach**: **Full rewrite** as a standalone `phpbb\notifications` service under PSR-4 namespace, bypassing the legacy `notification_manager` entirely. The service uses a **Repository → Service → Controller** layered architecture with an **extensible type/method registry** (tagged DI services), **tag-aware cache** (30s TTL aligned with polling), and **HTTP polling with `Last-Modified`/`304`** optimization. The frontend is a **React component** (`<NotificationBell>`) with a custom `useNotifications` hook managing polling via the Visibility API.

**Key decisions:**
- **Full rewrite over facade** — standalone service owns reads AND writes; no dependency on legacy `notification_manager` (ADR-004)
- **HTTP Polling 30s** with `Last-Modified`/`304 Not Modified` — zero infrastructure changes, proven GitHub API pattern (ADR-001)
- **Write-time responder aggregation** — leverages existing DB structure for "John and 3 others replied" grouping (ADR-002)
- **Tag-aware cache pool** with event-driven invalidation — 90%+ cache hit rate on polling endpoint (ADR-003)
- **React frontend** with `useNotifications` hook and Visibility API — pauses polling on inactive tabs (ADR-006)
- **Extensibility-first** — new notification types and delivery methods added via tagged DI services + interfaces (ADR-007)

---

## Architecture

### System Context (C4 Level 1)

```
┌─────────────────┐         ┌────────────────────────────────────┐
│                  │  HTTP   │                                    │
│   Forum User     │────────▶│        phpBB Forum System          │
│   (Browser/React)│◀────────│                                    │
│                  │  JSON   │  ┌──────────────────────────────┐  │
└─────────────────┘         │  │  Notifications Service        │  │
                            │  │  (phpbb\notifications)        │  │
                            │  └──────────────────────────────┘  │
                            │                                    │
                            │  ┌──────────┐  ┌──────────────┐   │
                            │  │  MySQL    │  │  Cache Pool   │   │
                            │  │  (DB)     │  │  (File/Redis) │   │
                            │  └──────────┘  └──────────────┘   │
                            │                                    │
                            │  ┌──────────────────────────────┐  │
                            │  │  Email / SMTP Server          │  │
                            │  └──────────────────────────────┘  │
                            └────────────────────────────────────┘

Interactions:
  Browser ──HTTP/JSON──▶ REST API (polling GET /count every 30s, on-demand list/mark)
  Notifications Service ──PDO──▶ MySQL (phpbb_notifications, phpbb_notification_types)
  Notifications Service ──PSR-16──▶ Cache Pool (tag-aware, 30s TTL)
  Notifications Service ──SMTP──▶ Email Server (via delivery methods)
  Forum Actions (post, PM, etc.) ──Symfony Events──▶ Notifications Service (create)
```

### Container Overview (C4 Level 2)

```
┌─────────────────────────────────────────────────────────────────────┐
│  Browser (React SPA / Hybrid)                                       │
│                                                                     │
│  ┌──────────────────────┐  ┌──────────────────────────────────┐    │
│  │ <NotificationBell>   │  │ <NotificationDropdown>           │    │
│  │  useNotifications()  │  │  <NotificationItem> × N          │    │
│  │  polls /count @30s   │  │  loads list on open              │    │
│  └──────────┬───────────┘  └──────────────┬───────────────────┘    │
└─────────────┼──────────────────────────────┼────────────────────────┘
              │ GET /count (If-Modified-Since)│ GET /notifications
              │ POST /read                   │ POST /{id}/read
              ▼                              ▼
┌─────────────────────────────────────────────────────────────────────┐
│  REST API Layer (Nginx + PHP-FPM)                                   │
│                                                                     │
│  ┌─────────────────┐  ┌───────────────────┐  ┌──────────────────┐  │
│  │ auth_subscriber  │  │ json_exception_sub│  │ CORS (Nginx)     │  │
│  │ JWT → _api_user  │  │ error formatting  │  │ OPTIONS → 204    │  │
│  │ (priority 8)     │  │ (priority 10)     │  │                  │  │
│  └────────┬─────────┘  └───────────────────┘  └──────────────────┘  │
│           ▼                                                         │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │  NotificationsController (phpbb\api\v1\controller)           │   │
│  │    count()  │  index()  │  markRead()  │  markAllRead()      │   │
│  └──────┬───────────────────────────────────────────────────────┘   │
└─────────┼───────────────────────────────────────────────────────────┘
          ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Notifications Service Layer (phpbb\notifications)                   │
│                                                                     │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │  NotificationService (facade)                                 │   │
│  │    getUnreadCount() │ getNotifications() │ createNotification()│  │
│  │    markRead() │ markAllRead() │ deleteNotifications()         │   │
│  └──┬──────────────┬──────────────────┬──────────────┬──────────┘   │
│     │              │                  │              │               │
│     ▼              ▼                  ▼              ▼               │
│  ┌──────────┐ ┌────────────────┐ ┌─────────────┐ ┌──────────────┐  │
│  │ Cache    │ │ Notification   │ │ TypeRegistry │ │ MethodManager│  │
│  │ Pool     │ │ Repository     │ │ (tagged DI)  │ │ (board,email)│  │
│  │ (tags,   │ │ (PDO, CRUD,   │ │              │ │              │  │
│  │  30s TTL)│ │  count, list)  │ │              │ │              │  │
│  └──────────┘ └───────┬────────┘ └──────────────┘ └──────────────┘  │
│                       │                                             │
│  ┌──────────────────────────────────────────────────────────────┐   │
│  │  Event System (Symfony EventDispatcher)                       │   │
│  │                                                               │   │
│  │  NotificationCreatedEvent ──▶ CacheInvalidationSubscriber    │   │
│  │  NotificationsMarkedReadEvent ──▶ invalidateTags()           │   │
│  │  NotificationsDeletedEvent                                    │   │
│  └──────────────────────────────────────────────────────────────┘   │
│                       │                                             │
└───────────────────────┼─────────────────────────────────────────────┘
                        ▼
┌─────────────────────────────────────────────────────────────────────┐
│  Data Layer                                                         │
│                                                                     │
│  ┌──────────────────────┐  ┌────────────────────────────────────┐  │
│  │  phpbb_notifications  │  │  phpbb_notification_types          │  │
│  │  (user notifications) │  │  (type_id ↔ type_name mapping)    │  │
│  └──────────────────────┘  └────────────────────────────────────┘  │
│                                                                     │
│  ┌──────────────────────┐  ┌────────────────────────────────────┐  │
│  │  phpbb_user_notifs    │  │  Cache Store (file / redis)        │  │
│  │  (subscription prefs) │  │  Pool: "notifications"             │  │
│  └──────────────────────┘  └────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Key Components

### Component Overview

| Component | Namespace | Purpose | Dependencies |
|-----------|-----------|---------|--------------|
| `NotificationService` | `phpbb\notifications\Service` | Main facade — orchestrates reads, writes, cache, events, type/method dispatch | Repository, Cache, TypeRegistry, MethodManager, EventDispatcher |
| `NotificationRepository` | `phpbb\notifications\Repository` | PDO data access — CRUD, count, list, mark-read, bulk operations | `\PDO` (database_connection) |
| `NotificationsController` | `phpbb\api\v1\controller` | REST API — 4 endpoints, HTTP headers, JSON responses | NotificationService, Request |
| `NotificationTypeRegistry` | `phpbb\notifications\Type` | Extensible type registration via tagged DI services, type lookup, validation | Tagged `notification.type` services |
| `NotificationMethodManager` | `phpbb\notifications\Method` | Delivery method orchestration — dispatches to board, email, etc. | Tagged `notification.method` services |
| `NotificationTypeInterface` | `phpbb\notifications\Type` | Contract for notification types — recipient finding, display, email | — |
| `NotificationMethodInterface` | `phpbb\notifications\Method` | Contract for delivery methods — notify, mark-read, preferences | — |
| `CacheInvalidationSubscriber` | `phpbb\notifications\Listener` | Symfony event subscriber — invalidates user cache tags on changes | TagAwareCacheInterface |
| Domain Events | `phpbb\notifications\Event` | `NotificationCreatedEvent`, `NotificationsMarkedReadEvent`, `NotificationsDeletedEvent` | — |
| `NotificationTransformer` | `phpbb\notifications\Transformer` | Transforms raw DB rows + type metadata into JSON-ready arrays | TypeRegistry |

---

### Component Details

### 1. NotificationService

**Class**: `phpbb\notifications\Service\NotificationService`
**Responsibility**: Main facade that orchestrates all notification operations — cached reads, writes through type/method system, event dispatching.

```php
namespace phpbb\notifications\Service;

use phpbb\cache\TagAwareCacheInterface;
use phpbb\notifications\Repository\NotificationRepository;
use phpbb\notifications\Type\NotificationTypeRegistry;
use phpbb\notifications\Method\NotificationMethodManager;
use phpbb\notifications\Transformer\NotificationTransformer;
use phpbb\notifications\Event\NotificationCreatedEvent;
use phpbb\notifications\Event\NotificationsMarkedReadEvent;
use phpbb\notifications\Event\NotificationsDeletedEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $repository,
        private readonly TagAwareCacheInterface $cache,
        private readonly NotificationTypeRegistry $typeRegistry,
        private readonly NotificationMethodManager $methodManager,
        private readonly NotificationTransformer $transformer,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    /**
     * Get unread notification count for a user.
     * Cached with 30s TTL, tag-invalidated on changes.
     */
    public function getUnreadCount(int $userId): int;

    /**
     * Get the timestamp of the most recent notification for a user.
     * Used for Last-Modified / 304 Not Modified responses.
     */
    public function getLastModified(int $userId): ?int;

    /**
     * Get paginated notification list, transformed for JSON output.
     * Cached with 30s TTL per user+page combination.
     */
    public function getNotifications(int $userId, int $limit = 20, int $offset = 0): array;

    /**
     * Create notifications for a given type and data.
     * Determines recipients via type, dispatches to delivery methods, fires events.
     *
     * @param string $typeName   Service name, e.g. 'notification.type.post'
     * @param array  $data       Type-specific data (post_id, topic_id, etc.)
     * @param array  $options    Optional overrides (ignore_users, etc.)
     */
    public function createNotification(string $typeName, array $data, array $options = []): void;

    /**
     * Mark a single notification as read.
     * Returns updated unread count.
     */
    public function markRead(int $userId, int $notificationId): int;

    /**
     * Mark all notifications as read for a user.
     * Returns 0 (new unread count).
     */
    public function markAllRead(int $userId): int;

    /**
     * Delete notifications by type and item.
     * Called when the source item is deleted (e.g. post deleted).
     */
    public function deleteNotifications(string $typeName, int $itemId, ?int $parentId = null): void;
}
```

**Extension Points**:
- `createNotification()` resolves type via `TypeRegistry` → type determines recipients via `findUsersForNotification()`
- `MethodManager` dispatches to all configured delivery methods
- Events fired after every state change enable decoupled extensions

---

### 2. NotificationRepository

**Class**: `phpbb\notifications\Repository\NotificationRepository`
**Responsibility**: All database operations via PDO prepared statements. Owns the SQL, handles table prefixes, manages transactions.

```php
namespace phpbb\notifications\Repository;

class NotificationRepository
{
    public function __construct(
        private readonly \PDO $db,
        private readonly string $notificationsTable,
        private readonly string $notificationTypesTable,
    ) {}

    /**
     * Count unread notifications for a user.
     * Uses composite index (user_id, notification_read, notification_time).
     */
    public function countUnread(int $userId): int;

    /**
     * Get timestamp of most recent notification for a user.
     */
    public function getLatestTimestamp(int $userId): ?int;

    /**
     * Find paginated notifications for a user with type name join.
     * Returns raw associative arrays (not hydrated objects).
     */
    public function findForUser(int $userId, int $limit = 20, int $offset = 0): array;

    /**
     * Find a single notification by ID, scoped to user.
     */
    public function findOneByIdAndUser(int $notificationId, int $userId): ?array;

    /**
     * Count total notifications for a user (read + unread).
     */
    public function countTotal(int $userId): int;

    /**
     * Insert a batch of notification rows.
     * Used by createNotification flow.
     *
     * @param array[] $rows  Each row: [notification_type_id, item_id, item_parent_id, user_id, notification_time, notification_data]
     */
    public function insertBatch(array $rows): void;

    /**
     * Mark a specific notification as read.
     * Returns affected row count.
     */
    public function markRead(int $notificationId, int $userId, int $time): int;

    /**
     * Mark all notifications as read for a user.
     * Returns affected row count.
     */
    public function markAllRead(int $userId, int $time): int;

    /**
     * Delete notifications by type and item.
     */
    public function deleteByTypeAndItem(int $typeId, int $itemId, ?int $parentId = null): void;

    /**
     * Get users already notified for a type+item combination (deduplication).
     */
    public function getNotifiedUsers(int $typeId, int $itemId): array;

    /**
     * Resolve type name → numeric ID.
     * Auto-creates new types if not found.
     */
    public function resolveTypeId(string $typeName): int;

    /**
     * Prune old notifications.
     */
    public function prune(int $beforeTimestamp, bool $onlyRead = true): int;
}
```

---

### 3. NotificationsController

**Class**: `phpbb\api\v1\controller\notifications`
**Responsibility**: REST API endpoints. Extracts auth context from request, delegates to `NotificationService`, returns `JsonResponse` with appropriate headers.

```php
namespace phpbb\api\v1\controller;

use phpbb\notifications\Service\NotificationService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class notifications
{
    public function __construct(
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * GET /api/v1/notifications/count
     *
     * Polling endpoint. Supports If-Modified-Since / 304 Not Modified.
     * Returns: { "unread_count": N }
     * Headers: Last-Modified, Cache-Control, X-Poll-Interval
     */
    public function count(Request $request): Response;

    /**
     * GET /api/v1/notifications
     *
     * Paginated notification list. Query params: limit (1-50), offset, unread_only.
     * Returns: { "notifications": [...], "unread_count": N, "total": N }
     */
    public function index(Request $request): JsonResponse;

    /**
     * POST /api/v1/notifications/{id}/read
     *
     * Mark single notification as read.
     * Returns: { "status": "ok", "unread_count": N }
     */
    public function markRead(Request $request, int $id): JsonResponse;

    /**
     * POST /api/v1/notifications/read
     *
     * Mark all notifications as read.
     * Returns: { "status": "ok", "unread_count": 0 }
     */
    public function markAllRead(Request $request): JsonResponse;
}
```

---

### 4. NotificationTypeRegistry

**Class**: `phpbb\notifications\Type\NotificationTypeRegistry`
**Responsibility**: Manages notification type registration via tagged DI services. Provides type lookup, validation, and enumeration.

```php
namespace phpbb\notifications\Type;

class NotificationTypeRegistry
{
    /** @var array<string, NotificationTypeInterface> */
    private array $types = [];

    /**
     * @param iterable<NotificationTypeInterface> $taggedTypes  Injected via DI tagged services
     */
    public function __construct(iterable $taggedTypes)
    {
        foreach ($taggedTypes as $type) {
            $this->types[$type->getTypeName()] = $type;
        }
    }

    /** Get a type instance by service name. */
    public function getType(string $typeName): NotificationTypeInterface;

    /** Check if a type is registered. */
    public function hasType(string $typeName): bool;

    /** Get all registered type names. */
    public function getTypeNames(): array;

    /** Get types grouped by category for subscription UI. */
    public function getGroupedTypes(): array;

    /** Check if a type is currently enabled. */
    public function isEnabled(string $typeName): bool;
}
```

**Extension Point**: Register new notification type by creating a class implementing `NotificationTypeInterface` and tagging it `notification.type` in DI YAML. No code changes to `NotificationTypeRegistry` needed.

---

### 5. NotificationMethodManager

**Class**: `phpbb\notifications\Method\NotificationMethodManager`
**Responsibility**: Orchestrates delivery methods. Each method (board, email, jabber) handles its delivery channel. Manager dispatches to all methods the user has enabled.

```php
namespace phpbb\notifications\Method;

class NotificationMethodManager
{
    /** @var array<string, NotificationMethodInterface> */
    private array $methods = [];

    /**
     * @param iterable<NotificationMethodInterface> $taggedMethods  Injected via DI tagged services
     */
    public function __construct(iterable $taggedMethods)
    {
        foreach ($taggedMethods as $method) {
            $this->methods[$method->getMethodName()] = $method;
        }
    }

    /**
     * Dispatch notification to all methods enabled by each user.
     *
     * @param NotificationTypeInterface $type
     * @param array                     $data       Type-specific data
     * @param array<int, string[]>      $userPrefs  Map of user_id → enabled method names
     */
    public function dispatch(
        NotificationTypeInterface $type,
        array $data,
        array $userPrefs,
    ): void;

    /** Get all registered methods. */
    public function getMethods(): array;

    /** Get methods available to users (for subscription settings). */
    public function getAvailableMethods(): array;

    /** Get default methods for new subscriptions. */
    public function getDefaultMethods(): array;
}
```

**Extension Point**: Register new delivery method by implementing `NotificationMethodInterface` and tagging `notification.method`.

---

### 6. NotificationTypeInterface

**Interface**: `phpbb\notifications\Type\NotificationTypeInterface`
**Responsibility**: Contract that all notification types must implement. Defines how types find recipients, build display data, and handle email.

```php
namespace phpbb\notifications\Type;

interface NotificationTypeInterface
{
    /** Unique service name, e.g. 'notification.type.post'. */
    public function getTypeName(): string;

    /** Human-readable group for subscription UI, e.g. 'NOTIFICATION_GROUP_POSTING'. */
    public function getGroup(): string;

    /** Whether this type is currently available (config-dependent). */
    public function isAvailable(): bool;

    /**
     * Determine which users should receive this notification.
     * Returns map of user_id → array of enabled method names.
     *
     * @param array $data    Type-specific data (e.g. post data, PM data)
     * @param array $options Options (ignore_users, etc.)
     * @return array<int, string[]>  user_id => ['notification.method.board', 'notification.method.email']
     */
    public function findUsersForNotification(array $data, array $options = []): array;

    /**
     * Extract the item ID from type data.
     */
    public function getItemId(array $data): int;

    /**
     * Extract the parent item ID (e.g. topic_id for a post notification).
     */
    public function getItemParentId(array $data): int;

    /**
     * Build the serializable notification_data blob for storage.
     */
    public function buildNotificationData(array $data): array;

    /**
     * Transform a stored notification row into a display-ready array.
     *
     * @param array $row  Raw DB row including deserialized notification_data
     * @return array       JSON-ready display data: title, url, avatar_url, style_class, responders, etc.
     */
    public function transformForDisplay(array $row): array;

    /**
     * Handle responder coalescence — merge a new actor into an existing notification.
     * Returns null if coalescence is not supported for this type.
     *
     * @param array $existingData  Current notification_data from DB
     * @param array $newData       New responder data
     * @return array|null          Updated notification_data or null if no coalescence
     */
    public function coalesceResponder(array $existingData, array $newData): ?array;

    /** Email template name, or null if no email for this type. */
    public function getEmailTemplate(): ?string;

    /** Variables for email template rendering. */
    public function getEmailTemplateVariables(array $data, array $notificationData): array;
}
```

---

### 7. NotificationMethodInterface

**Interface**: `phpbb\notifications\Method\NotificationMethodInterface`
**Responsibility**: Contract for delivery methods. Each method knows how to deliver notifications through its channel.

```php
namespace phpbb\notifications\Method;

use phpbb\notifications\Type\NotificationTypeInterface;

interface NotificationMethodInterface
{
    /** Unique method name, e.g. 'notification.method.board'. */
    public function getMethodName(): string;

    /** Human-readable label for subscription settings UI. */
    public function getDisplayName(): string;

    /** Whether this method is globally available. */
    public function isAvailable(): bool;

    /** Whether this method is enabled by default for new users. */
    public function isEnabledByDefault(): bool;

    /**
     * Queue a notification for delivery through this method.
     *
     * @param NotificationTypeInterface $type
     * @param array                     $data       Type-specific data
     * @param int[]                     $userIds    Users to notify via this method
     */
    public function notify(NotificationTypeInterface $type, array $data, array $userIds): void;

    /**
     * Flush any queued notifications (batch send).
     */
    public function flush(): void;
}
```

---

### 8. CacheInvalidationSubscriber

**Class**: `phpbb\notifications\Listener\CacheInvalidationSubscriber`
**Responsibility**: Listens to domain events and invalidates the relevant user's cache tags. Ensures polling endpoint returns fresh data after any change.

```php
namespace phpbb\notifications\Listener;

use phpbb\cache\TagAwareCacheInterface;
use phpbb\notifications\Event\NotificationCreatedEvent;
use phpbb\notifications\Event\NotificationsMarkedReadEvent;
use phpbb\notifications\Event\NotificationsDeletedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class CacheInvalidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TagAwareCacheInterface $cache,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NotificationCreatedEvent::class => 'onNotificationChange',
            NotificationsMarkedReadEvent::class => 'onNotificationChange',
            NotificationsDeletedEvent::class => 'onNotificationChange',
        ];
    }

    public function onNotificationChange(object $event): void
    {
        // Each event exposes getUserIds() returning int[]
        foreach ($event->getUserIds() as $userId) {
            $this->cache->invalidateTags(["user_notifications:{$userId}"]);
        }
    }
}
```

---

### 9. Domain Events

**Namespace**: `phpbb\notifications\Event`

```php
// NotificationCreatedEvent — fired after new notifications inserted
final readonly class NotificationCreatedEvent
{
    public function __construct(
        private string $typeName,
        private int $itemId,
        private array $userIds,     // all recipient user IDs
    ) {}

    public function getTypeName(): string;
    public function getItemId(): int;
    public function getUserIds(): array;
}

// NotificationsMarkedReadEvent — fired after mark-read (single or all)
final readonly class NotificationsMarkedReadEvent
{
    public function __construct(
        private int $userId,
        private ?array $notificationIds,  // null = mark all
    ) {}

    public function getUserIds(): array { return [$this->userId]; }
    public function getNotificationIds(): ?array;
    public function isMarkAll(): bool;
}

// NotificationsDeletedEvent — fired after notifications deleted
final readonly class NotificationsDeletedEvent
{
    public function __construct(
        private string $typeName,
        private int $itemId,
        private array $userIds,
    ) {}

    public function getUserIds(): array;
}
```

---

### 10. NotificationTransformer

**Class**: `phpbb\notifications\Transformer\NotificationTransformer`
**Responsibility**: Transforms raw DB rows into JSON-ready arrays by delegating to the type's `transformForDisplay()` and adding common fields.

```php
namespace phpbb\notifications\Transformer;

use phpbb\notifications\Type\NotificationTypeRegistry;

class NotificationTransformer
{
    public function __construct(
        private readonly NotificationTypeRegistry $typeRegistry,
    ) {}

    /**
     * Transform a batch of raw notification rows to JSON-ready arrays.
     *
     * @param array[] $rows  Raw DB rows with notification_data already unserialized
     * @return array[]       JSON-ready notification arrays
     */
    public function transformBatch(array $rows): array;

    /**
     * Transform a single row.
     * Delegates to type's transformForDisplay(), then adds common fields:
     *   id, type, read, time (unix), time_iso (ISO 8601), responders, responder_count
     */
    public function transform(array $row): array;
}
```

---

## REST API Specification

### Routes

```yaml
# api.yml additions

api_notification_count:
    path:     /api/v1/notifications/count
    defaults:
        _controller: phpbb.api.v1.controller.notifications:count
    methods:  [GET]

api_notifications:
    path:     /api/v1/notifications
    defaults:
        _controller: phpbb.api.v1.controller.notifications:index
    methods:  [GET]

api_notification_mark_read:
    path:     /api/v1/notifications/{id}/read
    defaults:
        _controller: phpbb.api.v1.controller.notifications:markRead
    methods:  [POST]
    requirements:
        id: \d+

api_notifications_mark_all_read:
    path:     /api/v1/notifications/read
    defaults:
        _controller: phpbb.api.v1.controller.notifications:markAllRead
    methods:  [POST]
```

### Endpoint: GET /api/v1/notifications/count

**Purpose**: Lightweight polling endpoint. Called every 30 seconds. This is the hot path — must respond in < 5ms (cache hit).

**Auth**: JWT Bearer token required.

**Request Headers**:
| Header | Required | Purpose |
|--------|----------|---------|
| `Authorization` | Yes | `Bearer <jwt_token>` |
| `If-Modified-Since` | No | HTTP date of last known state; enables 304 |

**Response 200 OK**:
```json
{
    "unread_count": 5
}
```

**Response 304 Not Modified**: Empty body (no new notifications since `If-Modified-Since`).

**Response Headers**:
| Header | Value | Purpose |
|--------|-------|---------|
| `Last-Modified` | RFC 7231 date of newest notification | Conditional request support |
| `Cache-Control` | `private, no-cache` | No shared cache; browser must revalidate |
| `X-Poll-Interval` | `30` | Server-recommended polling interval (seconds) |

**Error Responses**:
| Status | Body | Condition |
|--------|------|-----------|
| 401 | `{ "error": "unauthorized", "message": "Valid authentication required" }` | Missing/invalid JWT |

---

### Endpoint: GET /api/v1/notifications

**Purpose**: Full notification list with pagination. Loaded when user opens the dropdown.

**Auth**: JWT Bearer token required.

**Query Parameters**:
| Param | Type | Default | Range | Description |
|-------|------|---------|-------|-------------|
| `limit` | int | 20 | 1–50 | Max items per page |
| `offset` | int | 0 | ≥ 0 | Pagination offset |
| `unread_only` | bool | false | — | Filter to unread notifications only |

**Response 200 OK**:
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
                { "user_id": 42, "username": "john_doe", "avatar_url": "/images/avatars/upload/avatar_42.jpg" },
                { "user_id": 55, "username": "jane_smith", "avatar_url": "/images/avatars/upload/avatar_55.jpg" }
            ],
            "responder_count": 5
        }
    ],
    "unread_count": 12,
    "total": 150
}
```

**Notification Object Schema**:
| Field | Type | Description |
|-------|------|-------------|
| `id` | int | Notification ID |
| `type` | string | Type service name (`notification.type.post`, `.pm`, `.quote`, etc.) |
| `read` | bool | Whether the notification has been read |
| `time` | int | Unix timestamp |
| `time_iso` | string | ISO 8601 formatted timestamp |
| `title` | string | Plain text notification title |
| `url` | string | Target URL |
| `avatar_url` | string | Primary actor's avatar URL |
| `style_class` | string | CSS class for icon/color styling |
| `reference` | string | Subject/reference text |
| `forum` | string | Forum name (if applicable) |
| `responders` | array | List of responder actors (may be empty) |
| `responder_count` | int | Total responder count (may exceed array length due to cap) |

**Error Responses**:
| Status | Body | Condition |
|--------|------|-----------|
| 401 | `{ "error": "unauthorized" }` | Missing/invalid JWT |
| 422 | `{ "error": "validation_error", "message": "limit must be between 1 and 50" }` | Invalid query params |

---

### Endpoint: POST /api/v1/notifications/{id}/read

**Purpose**: Mark a single notification as read. Returns updated count for immediate badge update.

**Auth**: JWT Bearer token required.

**Path Parameters**: `id` — notification ID (int, required).

**Response 200 OK**:
```json
{
    "status": "ok",
    "unread_count": 11
}
```

**Error Responses**:
| Status | Body | Condition |
|--------|------|-----------|
| 401 | `{ "error": "unauthorized" }` | Missing/invalid JWT |
| 404 | `{ "error": "not_found", "message": "Notification not found" }` | ID doesn't exist or belongs to another user |

---

### Endpoint: POST /api/v1/notifications/read

**Purpose**: Mark all notifications as read. Returns zero count.

**Auth**: JWT Bearer token required.

**Response 200 OK**:
```json
{
    "status": "ok",
    "unread_count": 0
}
```

**Error Responses**:
| Status | Body | Condition |
|--------|------|-----------|
| 401 | `{ "error": "unauthorized" }` | Missing/invalid JWT |

---

## Database Design

### Existing Tables (Reused)

#### phpbb_notifications (primary storage)

```sql
CREATE TABLE phpbb_notifications (
    notification_id        INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    notification_type_id   SMALLINT(4) UNSIGNED NOT NULL DEFAULT 0,
    item_id                MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT 0,
    item_parent_id         MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT 0,
    user_id                INT(10) UNSIGNED NOT NULL DEFAULT 0,
    notification_read      TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    notification_time      INT(11) UNSIGNED NOT NULL DEFAULT 1,
    notification_data      TEXT NOT NULL,
    PRIMARY KEY (notification_id),
    KEY item_ident (notification_type_id, item_id),
    KEY user (user_id, notification_read)
);
```

#### phpbb_notification_types (type name ↔ ID mapping)

```sql
CREATE TABLE phpbb_notification_types (
    notification_type_id      SMALLINT(4) UNSIGNED NOT NULL DEFAULT 0,
    notification_type_name    VARCHAR(255) NOT NULL DEFAULT '',
    notification_type_enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (notification_type_id),
    UNIQUE KEY type (notification_type_name)
);
```

#### phpbb_user_notifications (subscription preferences)

```sql
CREATE TABLE phpbb_user_notifications (
    item_type    VARCHAR(165) NOT NULL DEFAULT '',
    item_id      MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT 0,
    user_id      INT(10) UNSIGNED NOT NULL DEFAULT 0,
    method       VARCHAR(165) NOT NULL DEFAULT '',
    notify       TINYINT(1) UNSIGNED NOT NULL DEFAULT 1
);
```

### Migration — New Composite Index

```sql
-- Covers both count query (WHERE user_id=? AND notification_read=0)
-- and sorted list query (WHERE user_id=? ORDER BY notification_time DESC)
-- Eliminates filesort on list queries.
ALTER TABLE phpbb_notifications
    ADD INDEX idx_user_read_time (user_id, notification_read, notification_time DESC);

-- The old index is now superseded but can be kept for backward compatibility
-- with any code still using (user_id, notification_read) patterns.
-- Optional: DROP INDEX user ON phpbb_notifications;
```

### Query Patterns

| Query | SQL | Index Used |
|-------|-----|-----------|
| Count unread | `SELECT COUNT(*) FROM phpbb_notifications n JOIN phpbb_notification_types nt ON nt.notification_type_id = n.notification_type_id WHERE n.user_id = ? AND n.notification_read = 0 AND nt.notification_type_enabled = 1` | `idx_user_read_time` |
| Latest timestamp | `SELECT MAX(notification_time) FROM phpbb_notifications WHERE user_id = ?` | `idx_user_read_time` |
| Paginated list | `SELECT n.*, nt.notification_type_name FROM phpbb_notifications n JOIN phpbb_notification_types nt ON nt.notification_type_id = n.notification_type_id WHERE n.user_id = ? AND nt.notification_type_enabled = 1 ORDER BY n.notification_time DESC LIMIT ? OFFSET ?` | `idx_user_read_time` |
| Mark read | `UPDATE phpbb_notifications SET notification_read = 1, notification_time = ? WHERE notification_id = ? AND user_id = ?` | `PRIMARY KEY` |
| Mark all read | `UPDATE phpbb_notifications SET notification_read = 1 WHERE user_id = ? AND notification_read = 0` | `idx_user_read_time` |
| Dedup check | `SELECT user_id FROM phpbb_notifications WHERE notification_type_id = ? AND item_id = ? AND notification_read = 0` | `item_ident` |

---

## Cache Design

### Cache Pool

Isolated `notifications` namespace pool via `CachePoolFactoryInterface::getPool('notifications')`.

### Cache Keys and Tags

| Key Pattern | Example | Tags | TTL | Content |
|-------------|---------|------|-----|---------|
| `unread_count:{user_id}` | `notifications:unread_count:42` | `["user_notifications:42"]` | 30s | int (count) |
| `last_modified:{user_id}` | `notifications:last_modified:42` | `["user_notifications:42"]` | 30s | int (unix timestamp) |
| `list:{user_id}:{limit}:{offset}` | `notifications:list:42:20:0` | `["user_notifications:42"]` | 30s | array (transformed notification list) |

### TTL Strategy

- **30s TTL** aligns with polling interval — natural cache lifecycle
- Cache miss on first poll after TTL → DB query (~2ms) → cache populated
- Subsequent polls within window → cache hit (< 1ms)
- **Expected hit rate**: ~90% (most 30s windows have no notification changes)

### Invalidation Flow

```
  Forum action (new post, PM, etc.)
    │
    ▼
  NotificationService::createNotification()
    │
    ├── Repository::insertBatch() → DB INSERT
    │
    ├── EventDispatcher::dispatch(NotificationCreatedEvent)
    │     │
    │     ▼
    │   CacheInvalidationSubscriber::onNotificationChange()
    │     │
    │     ▼
    │   cache->invalidateTags(["user_notifications:42", "user_notifications:55", ...])
    │
    └── MethodManager::dispatch() → email, etc.
```

**Mark-read also triggers invalidation** — same flow via `NotificationsMarkedReadEvent`.

### Cold Start Behavior

- First request for a user → cache miss → DB query → cache populated
- After cache service restart → all users experience one cache miss cycle
- `getOrCompute()` ensures only one concurrent DB query per cache key (stampede protection)

### Fallback (Cache Service Not Implemented)

`NullTagAwareCache` adapter that delegates directly to repository. Every call is a DB query. Still functional, just slower (~2ms instead of < 1ms). Performance acceptable for low traffic.

---

## Event System

### Domain Events Dispatched

| Event | Fired When | Data | Subscribers |
|-------|-----------|------|-------------|
| `NotificationCreatedEvent` | After new notifications inserted in DB | typeName, itemId, userIds[] | CacheInvalidationSubscriber |
| `NotificationsMarkedReadEvent` | After mark-read (single or all) | userId, notificationIds (null = all) | CacheInvalidationSubscriber |
| `NotificationsDeletedEvent` | After notifications deleted | typeName, itemId, userIds[] | CacheInvalidationSubscriber |

### Integration Points for Extensions

Extensions can subscribe to these events to:
- **Push notifications** (Web Push, mobile API) — listen to `NotificationCreatedEvent`
- **Analytics** — track notification interaction patterns
- **External integrations** — forward notifications to Slack, Discord, etc.
- **Custom cache strategies** — additional invalidation logic

### Creating Notifications from Forum Actions

Forum code (post creation, PM sending, etc.) calls `NotificationService::createNotification()`:

```php
// Example: After a new post is created
$this->notificationService->createNotification(
    'notification.type.post',
    [
        'post_id'      => $post_id,
        'topic_id'     => $topic_id,
        'poster_id'    => $poster_id,
        'post_subject' => $subject,
        'topic_title'  => $topic_title,
        'forum_id'     => $forum_id,
        'forum_name'   => $forum_name,
    ]
);
```

The `NotificationService` then:
1. Resolves the type via `TypeRegistry`
2. Calls `type->findUsersForNotification()` to determine recipients
3. Checks for existing unread notifications (dedup via `coalesceResponder()`)
4. Inserts new rows via `Repository::insertBatch()`
5. Dispatches to delivery methods via `MethodManager::dispatch()`
6. Fires `NotificationCreatedEvent`

---

## DI Configuration

```yaml
# src/phpbb/common/config/default/container/services_notifications.yml

services:

    # ─── Type Registry (collects tagged types) ────────────────────────────

    phpbb.notifications.type_collection:
        class: phpbb\di\service_collection
        arguments:
            - '@service_container'
        tags:
            - { name: service_collection, tag: notification.type }

    phpbb.notifications.type_registry:
        class: phpbb\notifications\Type\NotificationTypeRegistry
        arguments:
            - '@phpbb.notifications.type_collection'

    # ─── Method Manager (collects tagged methods) ────────────────────────

    phpbb.notifications.method_collection:
        class: phpbb\di\service_collection
        arguments:
            - '@service_container'
        tags:
            - { name: service_collection, tag: notification.method }

    phpbb.notifications.method_manager:
        class: phpbb\notifications\Method\NotificationMethodManager
        arguments:
            - '@phpbb.notifications.method_collection'

    # ─── Repository ──────────────────────────────────────────────────────

    phpbb.notifications.repository:
        class: phpbb\notifications\Repository\NotificationRepository
        arguments:
            - '@database_connection'
            - '%tables.notifications%'
            - '%tables.notification_types%'

    # ─── Cache Pool ──────────────────────────────────────────────────────

    phpbb.cache.pool.notifications:
        factory: ['@phpbb.cache.pool_factory', 'getPool']
        arguments: ['notifications']

    # ─── Transformer ─────────────────────────────────────────────────────

    phpbb.notifications.transformer:
        class: phpbb\notifications\Transformer\NotificationTransformer
        arguments:
            - '@phpbb.notifications.type_registry'

    # ─── Core Service ────────────────────────────────────────────────────

    phpbb.notifications.service:
        class: phpbb\notifications\Service\NotificationService
        arguments:
            - '@phpbb.notifications.repository'
            - '@phpbb.cache.pool.notifications'
            - '@phpbb.notifications.type_registry'
            - '@phpbb.notifications.method_manager'
            - '@phpbb.notifications.transformer'
            - '@event_dispatcher'

    # ─── Event Subscriber ────────────────────────────────────────────────

    phpbb.notifications.listener.cache_invalidation:
        class: phpbb\notifications\Listener\CacheInvalidationSubscriber
        arguments:
            - '@phpbb.cache.pool.notifications'
        tags:
            - { name: kernel.event_subscriber }

    # ─── API Controller ──────────────────────────────────────────────────

    phpbb.api.v1.controller.notifications:
        class: phpbb\api\v1\controller\notifications
        arguments:
            - '@phpbb.notifications.service'

    # ─── Built-in Notification Types ─────────────────────────────────────

    # Each type is registered as a prototype (shared: false) and tagged.
    # Types inherit from AbstractNotificationType for common boilerplate.

    notification.type.post:
        class: phpbb\notifications\Type\PostNotification
        shared: false
        arguments:
            - '@database_connection'
            - '@phpbb.auth.service.authorization'
        tags:
            - { name: notification.type }

    notification.type.topic:
        class: phpbb\notifications\Type\TopicNotification
        shared: false
        arguments:
            - '@database_connection'
            - '@phpbb.auth.service.authorization'
        tags:
            - { name: notification.type }

    notification.type.pm:
        class: phpbb\notifications\Type\PrivateMessageNotification
        shared: false
        tags:
            - { name: notification.type }

    notification.type.quote:
        class: phpbb\notifications\Type\QuoteNotification
        shared: false
        arguments:
            - '@database_connection'
            - '@phpbb.auth.service.authorization'
        tags:
            - { name: notification.type }

    notification.type.bookmark:
        class: phpbb\notifications\Type\BookmarkNotification
        shared: false
        arguments:
            - '@database_connection'
            - '@phpbb.auth.service.authorization'
        tags:
            - { name: notification.type }

    # ─── Built-in Delivery Methods ───────────────────────────────────────

    notification.method.board:
        class: phpbb\notifications\Method\BoardMethod
        tags:
            - { name: notification.method }

    notification.method.email:
        class: phpbb\notifications\Method\EmailMethod
        arguments:
            - '@phpbb.messenger'
        tags:
            - { name: notification.method }
```

---

## Frontend Integration (React)

### Component Hierarchy

```
<NotificationBell>                    // Top-level, renders bell icon + badge
  ├── <Badge count={unreadCount} />   // Red circle with number
  └── <NotificationDropdown>          // Shown on click
       ├── <DropdownHeader>           // "Notifications" title + "Mark all read" button
       ├── <NotificationList>         // Scrollable list
       │    └── <NotificationItem>    // Individual notification
       │         ├── <Avatar>
       │         ├── <NotificationText>
       │         └── <TimeAgo>
       └── <DropdownFooter>           // "View all notifications" link
```

### useNotifications Hook

```typescript
interface UseNotificationsReturn {
    unreadCount: number;
    notifications: Notification[];
    isLoading: boolean;
    error: Error | null;
    markRead: (id: number) => Promise<void>;
    markAllRead: () => Promise<void>;
    loadNotifications: (offset?: number) => Promise<void>;
    total: number;
}

interface Notification {
    id: number;
    type: string;
    read: boolean;
    time: number;
    time_iso: string;
    title: string;
    url: string;
    avatar_url: string;
    style_class: string;
    reference: string;
    forum: string;
    responders: Responder[];
    responder_count: number;
}

interface Responder {
    user_id: number;
    username: string;
    avatar_url: string;
}
```

### Hook Implementation Outline

```typescript
function useNotifications(pollInterval: number = 30000): UseNotificationsReturn {
    // State: unreadCount, notifications, isLoading, error, total
    // Ref: lastModified (string), abortController

    // pollCount():
    //   - Skip if document.hidden (Visibility API)
    //   - AbortController for request cancellation
    //   - Set If-Modified-Since header if lastModified exists
    //   - On 304: do nothing (no change)
    //   - On 200: update unreadCount, store Last-Modified header

    // useEffect: set up interval + visibilitychange listener
    //   - On visible: immediate poll + resume interval
    //   - On hidden: pause interval
    //   - Cleanup: clear interval, abort pending request

    // markRead(id): POST /notifications/{id}/read → update counts + mark in local state
    // markAllRead(): POST /notifications/read → set count=0, mark all in local state
    // loadNotifications(offset): GET /notifications?limit=20&offset=N → append to state
}
```

### State Management

- **Polling state** managed inside `useNotifications` hook (no external store needed)
- **Optimistic updates** on mark-read: immediately update local state, revert on error
- **Immediate count update** from POST response `unread_count` field — no wait for next poll
- **Notification list** lazy-loaded on dropdown open, not on every poll

### NotificationBell Component

```tsx
function NotificationBell() {
    const {
        unreadCount, notifications, isLoading,
        markRead, markAllRead, loadNotifications, total
    } = useNotifications(30000);

    const [isOpen, setIsOpen] = useState(false);

    const handleOpen = () => {
        setIsOpen(true);
        if (notifications.length === 0) {
            loadNotifications();
        }
    };

    const handleClickNotification = async (notification: Notification) => {
        if (!notification.read) {
            await markRead(notification.id);
        }
        window.location.href = notification.url;
    };

    // Render: bell icon → badge → dropdown panel
}
```

---

## Extensibility Design

### Adding a New Notification Type

**Steps**:

1. **Create type class** implementing `NotificationTypeInterface` (or extending `AbstractNotificationType`):

```php
// src/phpbb/notifications/Type/MentionNotification.php
namespace phpbb\notifications\Type;

class MentionNotification extends AbstractNotificationType
{
    public function getTypeName(): string
    {
        return 'notification.type.mention';
    }

    public function getGroup(): string
    {
        return 'NOTIFICATION_GROUP_POSTING';
    }

    public function findUsersForNotification(array $data, array $options = []): array
    {
        // Parse mentioned usernames from post content
        // Return user_id => [method names] map
    }

    public function transformForDisplay(array $row): array
    {
        return [
            'title' => "{$row['notification_data']['poster_username']} mentioned you in \"{$row['notification_data']['topic_title']}\"",
            'url' => "/viewtopic.php?p={$row['item_id']}#p{$row['item_id']}",
            'avatar_url' => $this->resolveAvatarUrl($row['notification_data']['poster_id']),
            'style_class' => 'notification-mention',
            'reference' => $row['notification_data']['post_subject'] ?? '',
            'forum' => $row['notification_data']['forum_name'] ?? '',
        ];
    }

    public function coalesceResponder(array $existingData, array $newData): ?array
    {
        return null; // Mentions are 1:1 — no coalescence
    }
}
```

2. **Register in DI** (YAML):

```yaml
notification.type.mention:
    class: phpbb\notifications\Type\MentionNotification
    shared: false
    arguments:
        - '@database_connection'
        - '@phpbb.auth.service.authorization'
    tags:
        - { name: notification.type }
```

3. **Trigger from forum code**:

```php
$this->notificationService->createNotification('notification.type.mention', [
    'post_id' => $postId,
    'topic_id' => $topicId,
    'poster_id' => $posterId,
    'poster_username' => $posterUsername,
    'mentioned_users' => $mentionedUserIds,
]);
```

**No changes needed** to NotificationService, TypeRegistry, Controller, or any other component.

### Adding a New Delivery Method

1. **Create method class** implementing `NotificationMethodInterface`:

```php
namespace phpbb\notifications\Method;

class WebPushMethod implements NotificationMethodInterface
{
    public function getMethodName(): string { return 'notification.method.webpush'; }
    public function isAvailable(): bool { return true; }
    public function isEnabledByDefault(): bool { return false; }

    public function notify(NotificationTypeInterface $type, array $data, array $userIds): void
    {
        // Queue Web Push notifications for each user
    }

    public function flush(): void
    {
        // Batch send queued push messages
    }
}
```

2. **Register in DI**:

```yaml
notification.method.webpush:
    class: phpbb\notifications\Method\WebPushMethod
    arguments:
        - '@phpbb.webpush.service'
    tags:
        - { name: notification.method }
```

### Customizing Notification Display

- Override `transformForDisplay()` in the type class to change JSON output
- Frontend `<NotificationItem>` can switch rendering based on `type` field
- Style classes (`notification-post`, `notification-pm`, etc.) map to CSS rules

### Extension Points Summary

| Extension Point | Mechanism | Registration |
|----------------|-----------|-------------|
| New notification type | Implement `NotificationTypeInterface` | Tag: `notification.type` |
| New delivery method | Implement `NotificationMethodInterface` | Tag: `notification.method` |
| React to notification events | Implement `EventSubscriberInterface` | Tag: `kernel.event_subscriber` |
| Custom display transform | Override `transformForDisplay()` | In type class |
| Custom recipient logic | Override `findUsersForNotification()` | In type class |
| Custom cache strategy | Replace cache pool factory | DI override |

---

## Design Decisions

| ID | Decision | Option Chosen | See ADR |
|----|----------|--------------|---------|
| ADR-001 | Real-Time Delivery Strategy | HTTP Polling 30s + Last-Modified/304 | [decision-log.md](decision-log.md#adr-001) |
| ADR-002 | Notification Aggregation | Write-Time Responders (existing mechanism) | [decision-log.md](decision-log.md#adr-002) |
| ADR-003 | Cache Strategy | Tag-Aware Pool + Event Invalidation | [decision-log.md](decision-log.md#adr-003) |
| ADR-004 | Service Architecture | Full Rewrite (standalone, bypass legacy manager) | [decision-log.md](decision-log.md#adr-004) |
| ADR-005 | API Response Format | Flat List + Embedded Responders JSON | [decision-log.md](decision-log.md#adr-005) |
| ADR-006 | Frontend Framework | React + useNotifications Hook | [decision-log.md](decision-log.md#adr-006) |
| ADR-007 | Type/Method Extensibility | Tagged DI Services + Interface Contracts | [decision-log.md](decision-log.md#adr-007) |
| ADR-008 | Notification Data Serialization | JSON in notification_data column | [decision-log.md](decision-log.md#adr-008) |

---

## Concrete Examples

### Example 1: Polling Cycle — No New Notifications

**Given**: User 42 has 3 unread notifications, last checked 10 seconds ago.
**When**: React `useNotifications` hook fires 30-second interval poll: `GET /api/v1/notifications/count` with `If-Modified-Since: Sat, 19 Apr 2026 10:00:00 GMT`.
**Then**:
- Controller calls `NotificationService::getLastModified(42)` → cache hit → returns `1713520200` (same as client's)
- Controller responds `304 Not Modified` (empty body, < 1ms, zero bandwidth)
- React hook: no state change, badge stays at 3

### Example 2: New Post Reply → Notification Created → Next Poll Updates Badge

**Given**: User 42 has 3 unread notifications. User 55 replies to User 42's topic.
**When**: Post creation code calls `NotificationService::createNotification('notification.type.post', [...])`.
**Then**:
1. `PostNotification::findUsersForNotification()` returns `[42 => ['notification.method.board', 'notification.method.email']]`
2. `PostNotification::coalesceResponder()` checks for existing unread topic notification → finds one → appends User 55 to responders
3. `Repository::insertBatch()` or update — data stored in `phpbb_notifications`
4. `MethodManager::dispatch()` → BoardMethod stores, EmailMethod queues email
5. `NotificationCreatedEvent` dispatched → `CacheInvalidationSubscriber` → `cache->invalidateTags(["user_notifications:42"])`
6. Next poll from User 42 (within 30s) → cache miss → DB query returns count=4 → `200 OK { "unread_count": 4 }`
7. React badge updates from 3 → 4

### Example 3: Mark All Read → Immediate Badge Reset

**Given**: User 42 has 12 unread notifications, dropdown is open.
**When**: User clicks "Mark all read" → React calls `markAllRead()` → `POST /api/v1/notifications/read`.
**Then**:
1. Controller calls `NotificationService::markAllRead(42)`
2. `Repository::markAllRead(42, time())` → `UPDATE phpbb_notifications SET notification_read = 1 WHERE user_id = 42 AND notification_read = 0`
3. `NotificationsMarkedReadEvent(42, null)` dispatched → cache invalidated
4. Service returns `0` (new unread count)
5. Controller responds `{ "status": "ok", "unread_count": 0 }`
6. React hook: optimistically updates badge to 0, marks all notifications as read in local state

---

## Out of Scope

| Area | Why Excluded | When to Address |
|------|-------------|-----------------|
| **SSE / WebSocket real-time** | Zero infrastructure changes mandate; 30s polling is sufficient for forum | v2 — when user base > 5000 concurrent |
| **Subscription management API** | Existing UCP page works; read-only first | v2 — `GET/PUT /api/v1/notifications/settings` |
| **Read-time aggregation (GROUP BY)** | Write-time responders cover primary use case (post replies) | v2 — if non-post grouping needed |
| **Push notifications (Web Push)** | Requires VAPID keys, service worker, permission UX | v3 — separate feature |
| **Admin purge/prune endpoints** | Keep in admin panel for v1 | v2 — `DELETE /admin/api/v1/notifications/prune` |
| **Notification deletion by user** | phpBB convention is mark-read, not delete | Add if frontend UX demands it |
| **Email digest aggregation** | Existing email method handles immediate delivery | v2 — daily/weekly digest option |
| **Legacy `notification_manager` migration** | Full rewrite approach — legacy code will be gradually replaced | During overall forum rewrite |
| **Deferred ideas from exploration** | SharedWorker tab dedup, denormalized count, bulk operations API, analytics | See solution-exploration.md Deferred Ideas |

---

## Success Criteria

| Criterion | Target | Measurement |
|-----------|--------|-------------|
| Count endpoint response time (cache hit) | < 5ms | Server-side timing header |
| Count endpoint response time (cache miss) | < 50ms | Server-side timing header |
| List endpoint response time (20 items) | < 50ms | Server-side timing header |
| Cache hit rate on polling | > 85% | Cache hit/miss counter |
| Polling bandwidth (304 response) | < 200 bytes | Network inspector |
| Time to first badge update (new notification) | < 30s average | Polling interval |
| Adding a new notification type | No changes to core components | Code review checklist |
| Adding a new delivery method | No changes to core components | Code review checklist |
