# Codebase Integration Findings: Notifications Service

## 1. Cache Service Integration

**Source**: `.maister/tasks/research/2026-04-19-cache-service/outputs/high-level-design.md`

### Key Interfaces

The cache service provides three relevant interfaces:

```php
// Base PSR-16
phpbb\cache\CacheInterface extends Psr\SimpleCache\CacheInterface

// Extended with tags + compute
phpbb\cache\TagAwareCacheInterface extends CacheInterface
{
    setTagged(string $key, mixed $value, ?int $ttl = null, array $tags = []): bool;
    invalidateTags(array $tags): bool;
    getOrCompute(string $key, callable $compute, ?int $ttl = null, array $tags = []): mixed;
}

// Factory for namespaced pools
phpbb\cache\CachePoolFactoryInterface
{
    getPool(string $namespace): TagAwareCacheInterface;
}
```

### Pool Pattern

Each service gets a namespaced pool via factory. Pools share a backend connection but have logically isolated key spaces.

```php
// In DI container (YAML):
phpbb.cache.pool.notifications:
    factory: ['@phpbb.cache.pool_factory', 'getPool']
    arguments: ['notifications']
```

Existing pool registrations follow the same pattern:
- `phpbb.cache.pool.threads` → `getPool('threads')`
- `phpbb.cache.pool.messaging` → `getPool('messaging')`
- `phpbb.cache.pool.hierarchy` → `getPool('hierarchy')`
- `phpbb.cache.pool.auth` → `getPool('auth')`

**Confidence**: High (100%) — Direct from cache service design document.

### getOrCompute Pattern

Lazy-loading with automatic cache storage and tag association:

```php
$count = $this->cache->getOrCompute(
    "unread_count:{$userId}",
    fn() => $this->repository->countUnread($userId),
    $ttl,
    ["user_notifications:{$userId}"]
);
```

Flow: cache miss → invoke callable → store result with tags → return. On hit, tag versions are checked — if any tag was invalidated, it's treated as a miss.

**Confidence**: High (100%) — Direct from cache service design, lines ~200-220.

### Tag Invalidation Pattern

Tags use version counters. `invalidateTags()` increments the version — stale entries fail version check on next read (no deletion needed).

```php
// When a notification is created/read/deleted for user 42:
$this->cache->invalidateTags(["user_notifications:42"]);
```

Each service provides its own `CacheInvalidationSubscriber` that listens to domain events:

```php
class CacheInvalidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly TagAwareCacheInterface $cache,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NotificationCreatedEvent::class => 'onNotificationChange',
            NotificationReadEvent::class => 'onNotificationChange',
        ];
    }

    public function onNotificationChange($event): void
    {
        $this->cache->invalidateTags([
            "user_notifications:{$event->userId}",
        ]);
    }
}
```

**Confidence**: High (100%) — Pattern documented in cache service design Section 9.

### Recommended Cache Pool & Key Design

Based on the established convention `{namespace}:{entity}:{identifier}:{variant}`:

| Consumer Key | Full Key | Tags | TTL |
|---|---|---|---|
| `unread_count:{user_id}` | `notifications:unread_count:42` | `["user_notifications:42"]` | 30s |
| `list:{user_id}:{page}` | `notifications:list:42:1` | `["user_notifications:42"]` | 30s |
| `subscription_types` | `notifications:subscription_types` | `["notification_types"]` | 3600s |

**TTL strategy**: Short TTL (30s) for user-specific counts/lists since they change frequently. Longer TTL (3600s) for global metadata like subscription types.

**Evidence**: Cache key convention from Section 7.2; TTL strategy from Section 9.3 hybrid TTL+events table showing "Conversation list: 30s" as analogous pattern.

---

## 2. Auth Service Integration

**Source**: `.maister/tasks/research/2026-04-18-auth-service/outputs/high-level-design.md`

### AuthorizationService.isGranted()

```php
namespace phpbb\auth\Contract;

interface AuthorizationServiceInterface
{
    // Check if user has a specific permission
    public function isGranted(User $user, string $permission, ?int $forumId = null): bool;

    // Check if user has ANY of the specified permissions (short-circuit OR)
    public function isGrantedAny(User $user, array $permissions, ?int $forumId = null): bool;

    // Get all forum IDs where user has the specified permission
    public function getGrantedForums(User $user, string $permission): array;
}
```

The permission model uses three values:
- `PermissionSetting::Yes` (1) — granted
- `PermissionSetting::Never` (0) — permanently denied, overrides any YES
- `PermissionSetting::No` (-1) — not granted (default)

**Confidence**: High (100%) — Direct from auth service interface signatures.

### Route-Level Permission via `_api_permission`

Routes declare required permissions in YAML defaults. `AuthorizationSubscriber` (priority 8) reads them automatically:

```yaml
# Example for notifications endpoint:
api_notifications:
    path:     /api/v1/notifications
    defaults:
        _controller: phpbb.api.v1.controller.notifications:index
        # No _api_permission needed — only requires authenticated user.
        # The subscriber verifies valid JWT + active user when no permission is set.
    methods:  [GET]

api_notification_mark_read:
    path:     /api/v1/notifications/{id}/read
    defaults:
        _controller: phpbb.api.v1.controller.notifications:markRead
        # No specific permission — all authenticated users can read their own notifications
    methods:  [POST]
    requirements:
        id: \d+
```

**Notification permission recommendation**: Notifications are per-user personal data. The existing `u_readnotif` permission is not needed at route level — all authenticated users should access their own notifications. Authorization is implicit: the controller reads `user_id` from the JWT-authenticated user and only returns that user's notifications. No `_api_permission` needed.

For admin endpoints (e.g., purge all notifications), use `_api_permission: a_board`.

**Confidence**: High (90%) — Based on auth service design patterns and existing route configs.

### User Context in Controllers

After the `AuthorizationSubscriber` runs (priority 8), the User entity is available:

```php
// In controller:
$user = $request->attributes->get('_api_user');
$userId = $user->id;
```

This is the standard pattern for all API controllers. The subscriber:
1. Extracts Bearer JWT from Authorization header
2. Validates JWT
3. Resolves User entity via `UserRepositoryInterface::findById()`
4. Sets `_api_user` on request attributes
5. Optionally checks `_api_permission` if configured

**Evidence**: Auth service design, REST API Middleware Architecture section, lines ~988-1010.

---

## 3. DI Container Patterns

**Source**: `src/phpbb/common/config/default/container/services*.yml`

### Service Definition Conventions

Services follow the `phpbb.{domain}.{layer}.{name}` naming pattern for new services:

```yaml
# From auth service design:
phpbb.auth.repository.acl:
    class: phpbb\auth\Repository\AclRepository
    arguments: ['@database_connection']

phpbb.auth.service.authorization:
    class: phpbb\auth\Service\AuthorizationService
    arguments:
        - '@phpbb.auth.service.acl_cache'
        - '@phpbb.auth.service.permission_resolver'
```

Legacy services use shorter flat names:

```yaml
notification_manager:
    class: phpbb\notification\manager
    arguments:
        - '@notification.type_collection'
        - '@notification.method_collection'
        - '@service_container'
        # ... 8 more arguments
```

**Confidence**: High (100%) — Observed directly in YAML files.

### Service Tags

Tagged services are collected via `phpbb\di\service_collection`:

```yaml
# Collection definition:
notification.type_collection:
    class: phpbb\di\service_collection
    arguments:
        - '@service_container'
    tags:
        - { name: service_collection, tag: notification.type }

# Tagged service:
notification.type.post:
    class: phpbb\notification\type\post
    shared: false
    parent: notification.type.base
    tags:
        - { name: notification.type }
```

Event subscribers use the `kernel.event_subscriber` tag:

```yaml
api.auth_subscriber:
    class: phpbb\api\event\auth_subscriber
    tags:
        - { name: kernel.event_subscriber }
```

### Controller Registration

API controllers are registered as simple class services:

```yaml
phpbb.api.v1.controller.forums:
    class: phpbb\api\v1\controller\forums

phpbb.api.v1.controller.topics:
    class: phpbb\api\v1\controller\topics
```

Routes reference them in the `_controller` default:

```yaml
api_forums:
    path:     /api/v1/forums
    defaults:
        _controller: phpbb.api.v1.controller.forums:index
    methods:  [GET]
```

### Projected DI Registration for Notifications Service

```yaml
# services_notifications_api.yml

services:
    # Repository
    phpbb.notifications.repository:
        class: phpbb\notifications\Repository\NotificationRepository
        arguments: ['@database_connection']

    # Cache pool
    phpbb.cache.pool.notifications:
        factory: ['@phpbb.cache.pool_factory', 'getPool']
        arguments: ['notifications']

    # Core service
    phpbb.notifications.service:
        class: phpbb\notifications\Service\NotificationService
        arguments:
            - '@phpbb.notifications.repository'
            - '@phpbb.cache.pool.notifications'
            - '@event_dispatcher'

    # Cache invalidation subscriber
    phpbb.notifications.listener.cache_invalidation:
        class: phpbb\notifications\Listener\CacheInvalidationSubscriber
        arguments:
            - '@phpbb.cache.pool.notifications'
        tags:
            - { name: kernel.event_subscriber }

    # API Controller
    phpbb.api.v1.controller.notifications:
        class: phpbb\api\v1\controller\notifications

    # Event subscriber for API (if separate)
    # Or use the same subscriber pattern as auth
```

**Confidence**: High (90%) — Extrapolated from observed patterns across auth, cache, and existing services.

---

## 4. Event System

**Source**: `src/phpbb/common/config/default/container/services_event.yml`, notification manager source.

### Symfony EventDispatcher Integration

phpBB wraps the Symfony EventDispatcher:

```yaml
# services_event.yml
services:
    dispatcher:
        class: phpbb\event\dispatcher
        arguments:
            - '@service_container'
```

`phpbb\event\dispatcher` implements `phpbb\event\dispatcher_interface` and extends Symfony's EventDispatcher. The custom `trigger_event()` method is the legacy approach using extract/compact.

### Event Subscriber Registration

Subscribers implement `Symfony\Component\EventDispatcher\EventSubscriberInterface` and are tagged:

```yaml
api.json_exception_subscriber:
    class: phpbb\api\event\json_exception_subscriber
    arguments:
        - '%debug.exceptions%'
    tags:
        - { name: kernel.event_subscriber }
```

**Existing subscriber classes** in the project:
- `phpbb\api\event\auth_subscriber` — JWT validation
- `phpbb\api\event\json_exception_subscriber` — Error formatting
- `phpbb\event\kernel_exception_subscriber` — Template error handling
- `phpbb\event\kernel_terminate_subscriber` — Cleanup

### Legacy Notification Events

The existing `notification\manager` dispatches these phpBB events via `trigger_event()`:

| Event Name | When | Key Variables |
|---|---|---|
| `core.notification_manager_add_notifications_before` | Before finding users to notify | `notification_type_name`, `data`, `notified_users`, `options` |
| `core.notification_manager_add_notifications` | After finding users, before sending | `notification_type_name`, `data`, `notify_users`, `options` |
| `core.notification_manager_add_notifications_for_users_modify_data` | After dedup, before creating DB rows | `notification_type_name`, `data`, `notify_users` |

**Evidence**: `src/phpbb/forums/notification/manager.php`, lines 264-387.

### New Service Event Pattern

For the new notifications service, use proper Symfony-style events (not legacy `trigger_event()`):

```php
namespace phpbb\notifications\Event;

final readonly class NotificationCreatedEvent
{
    public function __construct(
        public int $userId,
        public string $notificationType,
        public int $itemId,
    ) {}
}

final readonly class NotificationsMarkedReadEvent
{
    public function __construct(
        public int $userId,
        public ?array $notificationIds = null, // null = all
    ) {}
}
```

Subscriber pattern:

```php
namespace phpbb\notifications\Listener;

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
            NotificationDeletedEvent::class => 'onNotificationChange',
        ];
    }

    public function onNotificationChange(object $event): void
    {
        $this->cache->invalidateTags([
            "user_notifications:{$event->userId}",
        ]);
    }
}
```

**Confidence**: High (95%) — Pattern established in cache service design Section 9.1 and auth service events.

---

## 5. Existing Service Patterns

### Legacy Notification Manager Architecture

**Source**: `src/phpbb/forums/notification/manager.php`

The legacy manager is a monolithic God-class with 10+ constructor dependencies:

```php
public function __construct(
    $notification_types,          // Service collection
    $notification_methods,        // Service collection
    ContainerInterface $container,// Full container (anti-pattern)
    \phpbb\user_loader $user_loader,
    \phpbb\event\dispatcher_interface $phpbb_dispatcher,
    \phpbb\db\driver\driver_interface $db,
    \phpbb\cache\service $cache,
    \phpbb\language\language $language,
    \phpbb\user $user,
    $notification_types_table,    // Table name string
    $user_notifications_table,    // Table name string
)
```

Key issues for the new service to avoid:
1. **Container injection** — the manager takes the full DI container to lazy-load types. New service should use proper DI.
2. **Mixed concerns** — CRUD, querying, subscriptions, type management all in one class. New service should separate these.
3. **Table names as strings** — passed via DI parameters. New service should use repository pattern.

### Notification Methods Architecture

Legacy has a strategy pattern for delivery methods:
- `notification.method.board` — In-database board notifications (default)
- `notification.method.email` — Email delivery
- `notification.method.jabber` — XMPP/Jabber delivery

Each implements `method_interface` with:
- `load_notifications(array $options)` — Query and return notifications
- `add_to_queue(\phpbb\notification\type\type_interface $notification)` — Enqueue for batch insert
- `notify()` — Flush queue (batch INSERT into DB)
- `mark_notifications(...)` — Mark read/unread
- `get_notified_users(...)` — Check for duplicates

The `board` method interacts directly with `phpbb_notifications` table via DBAL.

**Confidence**: High (100%) — Direct from source code.

### Other Designed Services (Not Yet Implemented)

No `outputs/` directories found for hierarchy-service or messaging-service — these are still in research/planning phase.

---

## 6. Database Access Patterns

**Source**: `src/phpbb/forums/notification/manager.php`, `src/phpbb/forums/notification/method/board.php`

### Legacy DBAL Pattern

phpBB uses `\phpbb\db\driver\driver_interface` (custom DBAL, not Doctrine):

```php
// Query execution:
$sql = 'SELECT COUNT(n.notification_id) AS unread_count
    FROM ' . $this->notifications_table . ' n, ' . $this->notification_types_table . ' nt
    WHERE n.user_id = ' . (int) $options['user_id'] . '
        AND n.notification_read = 0
        AND nt.notification_type_id = n.notification_type_id
        AND nt.notification_type_enabled = 1';
$result = $this->db->sql_query($sql);
$unread_count = (int) $this->db->sql_fetchfield('unread_count');
$this->db->sql_freeresult($result);
```

Key methods:
- `$this->db->sql_query($sql)` — Execute query
- `$this->db->sql_query($sql, $cache_ttl)` — Execute with result caching
- `$this->db->sql_query_limit($sql, $limit, $offset)` — Paginated query
- `$this->db->sql_fetchrow($result)` — Fetch row
- `$this->db->sql_fetchfield($field)` — Fetch single field
- `$this->db->sql_freeresult($result)` — Free result
- `$this->db->sql_build_array('INSERT', $data)` — Build INSERT VALUES
- `$this->db->sql_in_set($col, $ids)` — Build IN clause
- `$this->db->sql_escape($value)` — Escape string value
- `$this->db->sql_nextid()` — Get last insert ID

### Safe Parameterization

Legacy phpBB uses `(int)` casting for integer params and `sql_escape()` for strings:

```php
// Integer params — cast directly:
'WHERE user_id = ' . (int) $user_id

// String params — escape:
"WHERE item_type = '" . $this->db->sql_escape($item_type) . "'"

// Build arrays (INSERT/UPDATE):
$this->db->sql_build_array('INSERT', array(
    'item_type'   => $item_type, // auto-escaped
    'user_id'     => (int) $user_id,
    'notify'      => 1,
));
```

### New Service DB Pattern (Based on Auth Service Design)

The auth service design uses direct PDO (not legacy DBAL):

```yaml
phpbb.auth.repository.acl:
    class: phpbb\auth\Repository\AclRepository
    arguments: ['@database_connection']
```

With prepared statements:

```php
// From auth service design SQL patterns:
// SELECT user_permissions FROM phpbb_users WHERE user_id = :userId
// UPDATE phpbb_users SET user_permissions = :bitstring WHERE user_id = :userId
```

**Recommendation for notifications**: Follow the repository pattern from auth service, using PDO prepared statements instead of legacy DBAL:

```php
namespace phpbb\notifications\Repository;

class NotificationRepository
{
    public function __construct(
        private readonly \PDO $db,
    ) {}

    public function countUnread(int $userId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM phpbb_notifications n
             JOIN phpbb_notification_types nt ON nt.notification_type_id = n.notification_type_id
             WHERE n.user_id = :userId
               AND n.notification_read = 0
               AND nt.notification_type_enabled = 1'
        );
        $stmt->execute(['userId' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function findForUser(int $userId, int $limit, int $offset): array
    {
        $stmt = $this->db->prepare(
            'SELECT n.*, nt.notification_type_name
             FROM phpbb_notifications n
             JOIN phpbb_notification_types nt ON nt.notification_type_id = n.notification_type_id
             WHERE n.user_id = :userId
               AND nt.notification_type_enabled = 1
             ORDER BY n.notification_time DESC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('userId', $userId, \PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function markRead(int $userId, array $notificationIds): int
    {
        $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
        $stmt = $this->db->prepare(
            "UPDATE phpbb_notifications
             SET notification_read = 1
             WHERE user_id = ?
               AND notification_id IN ({$placeholders})"
        );
        $params = array_merge([$userId], $notificationIds);
        $stmt->execute($params);
        return $stmt->rowCount();
    }
}
```

**Confidence**: High (90%) — Repository pattern from auth service design + PDO prepared statements for security.

### Key Database Tables (Existing)

| Table | Purpose | Key Columns |
|---|---|---|
| `phpbb_notifications` | Notification records | `notification_id`, `notification_type_id`, `user_id`, `item_id`, `item_parent_id`, `notification_read`, `notification_time`, `notification_data` |
| `phpbb_notification_types` | Type registry | `notification_type_id`, `notification_type_name`, `notification_type_enabled` |
| `phpbb_user_notifications` | User subscription preferences | `item_type`, `item_id`, `user_id`, `method`, `notify` |
| `phpbb_notification_emails` | Email dedup tracking | (prevents duplicate emails) |

**Evidence**: `services_notification.yml` table parameters + direct SQL queries in `manager.php` and `board.php`.

---

## 7. Summary — Integration Wiring Checklist

### Service Dependencies for Notifications Service

```
phpbb\notifications\Service\NotificationService
├── phpbb\notifications\Repository\NotificationRepository (@phpbb.notifications.repository)
├── phpbb\cache\TagAwareCacheInterface (@phpbb.cache.pool.notifications)
└── Symfony\Component\EventDispatcher\EventDispatcherInterface (@event_dispatcher)

phpbb\notifications\Listener\CacheInvalidationSubscriber
└── phpbb\cache\TagAwareCacheInterface (@phpbb.cache.pool.notifications)

phpbb\api\v1\controller\notifications
└── phpbb\notifications\Service\NotificationService (@phpbb.notifications.service)
```

### Files to Create/Modify

| File | Action | Purpose |
|---|---|---|
| `config/default/container/services_notifications_api.yml` | Create | DI registration for service, repository, cache pool, subscribers |
| `config/default/routing/api.yml` | Modify | Add notification API routes |
| `config/default/container/services.yml` | Modify | Import `services_notifications_api.yml` |

### Route Definitions

```yaml
api_notifications:
    path:     /api/v1/notifications
    defaults:
        _controller: phpbb.api.v1.controller.notifications:index
    methods:  [GET]

api_notification_count:
    path:     /api/v1/notifications/count
    defaults:
        _controller: phpbb.api.v1.controller.notifications:count
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

No `_api_permission` needed — all endpoints are authenticated-only (JWT required) and scoped to the authenticated user's own notifications.

### Cache Invalidation Tags

| Event | Tags to Invalidate |
|---|---|
| Notification created for user X | `["user_notifications:X"]` |
| Notification(s) marked read for user X | `["user_notifications:X"]` |
| Notification deleted for user X | `["user_notifications:X"]` |
| All notifications pruned | All affected user tags |
| Notification type enabled/disabled | `["notification_types"]` |
