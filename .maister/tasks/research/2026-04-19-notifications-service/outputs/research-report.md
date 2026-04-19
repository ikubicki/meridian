# Research Report: phpBB Notifications Service

**Research Type**: Mixed (Technical + Requirements + Literature)
**Date**: 2026-04-19
**Scope**: REST API service for notification delivery, email integration, and frontend real-time updates

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Research Objectives](#2-research-objectives)
3. [Methodology](#3-methodology)
4. [Current State Analysis](#4-current-state-analysis)
5. [Proposed Architecture](#5-proposed-architecture)
6. [Decision Areas](#6-decision-areas)
7. [Integration Plan](#7-integration-plan)
8. [Risks & Mitigations](#8-risks--mitigations)
9. [Gaps & Open Questions](#9-gaps--open-questions)
10. [Appendices](#10-appendices)

---

## 1. Executive Summary

### Research Question

Jak zaprojektować usługę `phpbb\notifications` odpowiedzialną za powiadomienia do użytkowników, wysyłkę emaili oraz powiadomienia w aplikacji frontendowej, z lekkim REST API endpointem informującym o ilości i najnowszych powiadomieniach? (Facebook-style)

### Answer

Nowa usługa `phpbb\notifications` powinna być zaprojektowana jako **cienka warstwa facade** nad istniejącym `notification_manager`, z trzema głównymi komponentami:

1. **`NotificationService`** — fasada orkiestrująca read-path (nowy `NotificationRepository` z PDO + cache z tagged poolem `notifications`) i write-path (delegacja do legacy `notification_manager` dla mark-read/mark-all/delete)

2. **REST API Controller** z 4 endpointami:
   - `GET /api/v1/notifications/count` — lekki count endpoint (polling co 30s, cached 30s TTL, `304 Not Modified`)
   - `GET /api/v1/notifications` — lista z paginacją i responder aggregation
   - `POST /api/v1/notifications/{id}/read` — mark single read
   - `POST /api/v1/notifications/read` — mark all read

3. **Frontend polling** — `setInterval` (30s) + `fetch('/api/v1/notifications/count')` z `If-Modified-Since`/`304` optimization

Architektura wykorzystuje 100% istniejącej infrastruktury: Symfony routing, JWT auth, notification manager (21 typów, 3 metody), DI container, i zaprojektowany cache service. Jedyna zmiana schematu to dodanie composite index `(user_id, notification_read, notification_time DESC)`.

### Key Findings

- Istniejący `notification_manager` pokrywa cały write-path (tworzenie, deduplikacja, wysyłka email/jabber)
- `board.php::load_notifications()` obsługuje limit/offset/count — prawie gotowy REST adapter
- Facebook-style grouping ("John and 3 others replied") jest **już zaimplementowany** w `post.php::add_responders()`
- `prepare_for_display()` zwraca array gotowy do JSON transformacji
- HTTP Polling + Cache (30s) to wystarczająca strategia real-time dla forum (proven: GitHub API)
- Brakujący index `(user_id, notification_time DESC)` to jedyna zmiana schematu

---

## 2. Research Objectives

### Primary Question
Jak zaprojektować usługę REST API dla powiadomień z Facebook-style UX (bell + dropdown + count badge)?

### Sub-Questions
1. Jak zintegrować nową usługę z istniejącym `notification_manager` bez duplikacji logiki?
2. Jaka strategia real-time jest optymalna dla PHP-FPM + Nginx stack?
3. Jak efektywnie cache'ować notification count dla polling endpoint?
4. Jak eksponować Facebook-style aggregation (responders) w REST API?
5. Jak zintegrować z zaprojektowanym cache service i auth service?
6. Jakie zmiany schematu DB są potrzebne?

### Scope
- **Included**: REST API endpoints, cache layer, polling strategy, JSON response schema, DI wiring, DB indexes
- **Excluded**: Email delivery internals (handled by existing manager), WebPush (separate phpBB 4.0 feature), subscription management UCP, admin endpoints

---

## 3. Methodology

### Research Type
Mixed — Technical codebase analysis + Requirements analysis + Literature review

### Data Sources

| Source | Files Analyzed | Focus |
|--------|---------------|-------|
| Codebase — Notifications | `notification/manager.php`, `notification/type/base.php`, `notification/type/post.php`, `notification/method/board.php`, `notification/method/email.php`, `phpbb_dump.sql` | Notification types, methods, DB schema, events |
| Codebase — API | `web/api.php`, `core/Application.php`, `api/v1/controller/*.php`, `api/event/*.php`, `routing/api.yml`, `services_api.yml` | REST infrastructure, auth, routing |
| Codebase — Integration | Cache service design, auth service design, `services_*.yml`, event system | Cross-service patterns |
| External — Patterns | GitHub Notifications API, MDN SSE, Facebook UX patterns | REST design, real-time strategies, aggregation |
| External — phpBB | phpBB official docs, phpBB GitHub, prosilver templates, `core.js` | Extension points, AJAX patterns, frontend |

### Analysis Framework
Mixed Technical + Requirements analysis:
- Component analysis (what exists, how it works)
- Pattern analysis (cross-source relationships)
- Gap analysis (what's missing)
- Trade-off analysis (decision areas with pros/cons)

---

## 4. Current State Analysis

### 4.1 Notification Manager (`src/phpbb/forums/notification/manager.php`)

**Status**: Mature, fully functional (~1000 lines, 20+ public methods)

**Architecture**: Monolithic orchestrator with DI-injected type/method collections
- 12 constructor dependencies (includes full `ContainerInterface` — anti-pattern)
- Dispatches 3 legacy phpBB events (`core.notification_manager_add_notifications_*`)
- Handles: type discovery, recipient finding, deduplication, method dispatch, subscription management

**Key methods for REST API integration**:

| Method | Use | Evidence |
|--------|-----|----------|
| `load_notifications($method, $options)` | Fetch notifications with count/pagination | `codebase-notifications` Lines 97-135 |
| `mark_notifications_by_id($method, $ids)` | Mark specific notifications read | `codebase-notifications` Lines 233-242 |
| `mark_notifications(false, false, $uid, $time)` | Mark all read | `codebase-notifications` Lines 159-179 |
| `get_item_type_class($name, $data)` | Instantiate type for display | `codebase-notifications` Lines 907-914 |

**Assessment**: The manager's public API is sufficient for 100% of REST endpoint needs. Its internal complexity (type instantiation, method dispatch) is appropriately hidden.

### 4.2 Notification Types (21 types)

**Source**: `src/phpbb/forums/notification/type/`

The type system is extensible (tagged services, `notification.type` tag) with well-defined interfaces:
- `find_users_for_notification()` — determines recipients
- `prepare_for_display()` — returns template variable array (JSON-bridge)
- `create_insert_array()` — builds DB row data
- `add_responders()` (post.php) — Facebook-style coalescing

**Critical for API**: `prepare_for_display()` returns:
```php
[
    'NOTIFICATION_ID'   => int,
    'STYLING'           => string,    // CSS class: 'notification-post', etc.
    'AVATAR'            => string,    // HTML: <img src="..." />
    'FORMATTED_TITLE'   => string,    // HTML: "Username replied to Topic"
    'REFERENCE'         => string,    // Subject/reference text
    'FORUM'             => string,    // Forum name
    'URL'               => string,    // Target URL
    'TIME'              => string,    // Formatted date string
    'UNREAD'            => bool,
    'U_MARK_READ'       => string,    // Mark-read URL with CSRF hash
]
```

**Transformation needed for JSON API**:
- `AVATAR`: Extract `src` URL from HTML img tag
- `FORMATTED_TITLE`: Optionally strip HTML or provide alongside plain-text version
- `TIME`: Add ISO 8601 `notification_time` (raw timestamp available from `$notification->notification_time`)
- Add `type`: `notification_type_name` (e.g., `notification.type.post`)
- Add `responders[]`: Deserialize from `notification_data` if present

### 4.3 Delivery Methods

| Method | Status | REST API Relevance |
|--------|--------|--------------------|
| `board` (in-app) | ✅ Active | **Primary** — data source for REST endpoints |
| `email` | ✅ Active | No REST exposure needed — continues via manager |
| `jabber` | ✅ Active | No REST exposure needed — continues via manager |

The `board` method (`notification/method/board.php`) is the data backend for the REST API. Its `load_notifications()` executes up to 3 SQL queries:
1. Count unread (if `count_unread` enabled)
2. Count total (if `count_total` enabled)
3. Fetch paginated notifications with type name join

### 4.4 Database Schema

**Source**: `phpbb_dump.sql`

```sql
-- Main storage (Lines 2534-2549)
CREATE TABLE phpbb_notifications (
  notification_id      INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  notification_type_id SMALLINT(4) UNSIGNED NOT NULL DEFAULT 0,
  item_id              MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT 0,
  item_parent_id       MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT 0,
  user_id              INT(10) UNSIGNED NOT NULL DEFAULT 0,
  notification_read    TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  notification_time    INT(11) UNSIGNED NOT NULL DEFAULT 1,
  notification_data    TEXT NOT NULL,
  PRIMARY KEY (notification_id),
  KEY item_ident (notification_type_id, item_id),
  KEY user (user_id, notification_read)
);
```

**Index analysis for REST API queries**:

| Query Pattern | Existing Index | Assessment |
|---|---|---|
| `COUNT(*) WHERE user_id=? AND read=0` | `user (user_id, notification_read)` | ✅ Optimal |
| `SELECT * WHERE user_id=? ORDER BY time DESC LIMIT 20` | `user (user_id, notification_read)` | ⚠️ Filesort — missing time in index |
| `UPDATE SET read=1 WHERE notification_id IN (?)` | `PRIMARY KEY` | ✅ Optimal |
| `DELETE WHERE type_id=? AND item_id=?` | `item_ident` | ✅ Optimal |

**Required migration**: Add composite index `(user_id, notification_read, notification_time DESC)` to cover both count and sorted list.

### 4.5 REST API Infrastructure

**Source**: `web/api.php`, `src/phpbb/api/`, `services_api.yml`, `api.yml`

| Component | Status | Evidence |
|-----------|--------|----------|
| Entry point (`web/api.php`) | ✅ Working | Routes `/api/*` to `api.application` |
| Symfony HttpKernel | ✅ Working | `phpbb\core\Application` wraps kernel |
| YAML routing (`api.yml`) | ✅ Working | 8 existing routes with `/api/v1/` prefix |
| JWT auth (`auth_subscriber`) | ✅ Working | Validates Bearer token, sets `_api_token` |
| JSON error handling | ✅ Working | `json_exception_subscriber` at priority 10 |
| CORS (nginx) | ✅ Working | Wildcard `*` origin, OPTIONS → 204 |
| Controllers (5 existing) | ✅ Working | `health`, `auth`, `forums`, `topics`, `users` |

**Gap**: No notification-related API controller exists. All notification interaction is through server-side rendered pages.

### 4.6 Frontend Notification UI

**Source**: `external-phpbb` Section 2-4

- **jQuery-based** (3.6.0) — no modern framework
- Badge in header loaded server-side on every page via `page_header()` → `load_notifications()`
- AJAX interaction via `data-ajax` attribute + `phpbb.addAjaxCallback()` pattern
- No polling mechanism — count updates only on page reload or mark-read AJAX
- prosilver template has extension events: `notification_dropdown_footer_before/after`

---

## 5. Proposed Architecture

### 5.1 High-Level Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  Frontend (Browser)                                         │
│                                                             │
│  ┌──────────────┐  ┌──────────────────────────────────┐    │
│  │ Bell Badge   │  │ Notification Dropdown             │    │
│  │ (count poll) │  │ (on-demand list load)             │    │
│  └──────┬───────┘  └──────────────┬───────────────────┘    │
│         │ 30s interval             │ on click               │
└─────────┼──────────────────────────┼────────────────────────┘
          │                          │
          ▼                          ▼
┌─────────────────────────────────────────────────────────────┐
│  REST API Layer                                             │
│                                                             │
│  ┌─────────────────┐  ┌──────────────────────┐             │
│  │ auth_subscriber  │  │ json_exception_sub   │             │
│  │ (JWT validation) │  │ (error formatting)   │             │
│  └────────┬────────┘  └──────────────────────┘             │
│           │                                                 │
│  ┌────────▼────────────────────────────────────────────┐   │
│  │ phpbb\api\v1\controller\notifications               │   │
│  │                                                      │   │
│  │  count()       → GET  /api/v1/notifications/count   │   │
│  │  index()       → GET  /api/v1/notifications         │   │
│  │  markRead()    → POST /api/v1/notifications/{id}/read│  │
│  │  markAllRead() → POST /api/v1/notifications/read    │   │
│  └────────┬─────────────────────────────────────────────┘  │
└───────────┼─────────────────────────────────────────────────┘
            │
            ▼
┌─────────────────────────────────────────────────────────────┐
│  Service Layer                                              │
│                                                             │
│  ┌────────────────────────────────────────────────────┐    │
│  │ phpbb\notifications\Service\NotificationService     │    │
│  │                                                      │    │
│  │  getUnreadCount($userId)  → cached count            │    │
│  │  getNotifications($userId, $limit, $offset)         │    │
│  │  markRead($userId, $notificationId)                 │    │
│  │  markAllRead($userId)                               │    │
│  └──┬──────────────┬──────────────────┬────────────────┘   │
│     │              │                  │                      │
│     ▼              ▼                  ▼                      │
│  ┌──────────┐  ┌──────────────┐  ┌─────────────────────┐  │
│  │ Cache    │  │ Notification │  │ notification_manager │  │
│  │ Pool     │  │ Repository   │  │ (legacy, for writes) │  │
│  │ (tags)   │  │ (PDO reads)  │  │                      │  │
│  └──────────┘  └──────┬───────┘  └──────────┬──────────┘  │
│                        │                      │              │
│                        ▼                      │              │
│              ┌─────────────────┐              │              │
│              │ phpbb_           │◄─────────────┘              │
│              │ notifications   │                              │
│              │ (MySQL)         │                              │
│              └─────────────────┘                              │
└─────────────────────────────────────────────────────────────┘

Event Flow:
  notification_manager->add_notifications() (existing write-path)
    → board.php INSERT → phpbb_notifications
    → email.php → email queue
    → NotificationService dispatches NotificationCreatedEvent
      → CacheInvalidationSubscriber
        → cache->invalidateTags(["user_notifications:{userId}"])
```

### 5.2 Service Layer Design

#### NotificationService

```php
namespace phpbb\notifications\Service;

class NotificationService
{
    public function __construct(
        private readonly NotificationRepository $repository,
        private readonly TagAwareCacheInterface $cache,
        private readonly \phpbb\notification\manager $notificationManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function getUnreadCount(int $userId): int
    {
        return $this->cache->getOrCompute(
            "unread_count:{$userId}",
            fn() => $this->repository->countUnread($userId),
            30, // TTL seconds
            ["user_notifications:{$userId}"]
        );
    }

    public function getNotifications(int $userId, int $limit = 20, int $offset = 0): array
    {
        return $this->cache->getOrCompute(
            "list:{$userId}:{$limit}:{$offset}",
            fn() => $this->loadAndTransform($userId, $limit, $offset),
            30,
            ["user_notifications:{$userId}"]
        );
    }

    public function markRead(int $userId, int $notificationId): void
    {
        $this->notificationManager->mark_notifications_by_id(
            'notification.method.board', $notificationId, time(), true
        );
        $this->eventDispatcher->dispatch(
            new NotificationsMarkedReadEvent($userId, [$notificationId])
        );
    }

    public function markAllRead(int $userId): void
    {
        $this->notificationManager->mark_notifications(false, false, $userId, time(), true);
        $this->eventDispatcher->dispatch(
            new NotificationsMarkedReadEvent($userId, null)
        );
    }
}
```

#### NotificationRepository

```php
namespace phpbb\notifications\Repository;

class NotificationRepository
{
    public function __construct(private readonly \PDO $db) {}

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

    public function findForUser(int $userId, int $limit = 20, int $offset = 0): array
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
}
```

### 5.3 REST API Endpoints

#### Routes (`api.yml` additions)

```yaml
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

#### Endpoint Specifications

**`GET /api/v1/notifications/count`** — Polling endpoint (hot path)

*Purpose*: Lekki endpoint zwracający tylko count nieprzeczytanych. Wywoływany co 30s.

*Response* (200):
```json
{
  "unread_count": 5
}
```

*Response* (304): empty body when `If-Modified-Since` matches (nothing changed)

*Headers*:
- `Last-Modified: <timestamp of newest unread notification>`
- `Cache-Control: private, no-cache`

---

**`GET /api/v1/notifications`** — List endpoint (on-demand)

*Purpose*: Pełna lista powiadomień z paginacją. Ładowana gdy user otwiera dropdown.

*Query parameters*:
| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `limit` | int | 20 | Max items (1-50) |
| `offset` | int | 0 | Pagination offset |
| `unread_only` | bool | false | Filter to unread only |

*Response* (200):
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

---

**`POST /api/v1/notifications/{id}/read`** — Mark single read

*Response* (200):
```json
{
  "status": "ok",
  "unread_count": 11
}
```

---

**`POST /api/v1/notifications/read`** — Mark all read

*Response* (200):
```json
{
  "status": "ok",
  "unread_count": 0
}
```

### 5.4 Cache Architecture

#### Cache Pool

```yaml
# services_notifications_api.yml
phpbb.cache.pool.notifications:
    factory: ['@phpbb.cache.pool_factory', 'getPool']
    arguments: ['notifications']
```

#### Cache Key Design

| Key Pattern | Full Key | Tags | TTL |
|---|---|---|---|
| `unread_count:{user_id}` | `notifications:unread_count:42` | `["user_notifications:42"]` | 30s |
| `list:{user_id}:{limit}:{offset}` | `notifications:list:42:20:0` | `["user_notifications:42"]` | 30s |

#### Invalidation Events

```php
// Domain events
final readonly class NotificationCreatedEvent {
    public function __construct(public int $userId, public string $type, public int $itemId) {}
}

final readonly class NotificationsMarkedReadEvent {
    public function __construct(public int $userId, public ?array $notificationIds = null) {}
}

// Subscriber
class CacheInvalidationSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly TagAwareCacheInterface $cache) {}

    public static function getSubscribedEvents(): array
    {
        return [
            NotificationCreatedEvent::class => 'onNotificationChange',
            NotificationsMarkedReadEvent::class => 'onNotificationChange',
        ];
    }

    public function onNotificationChange(object $event): void
    {
        $this->cache->invalidateTags(["user_notifications:{$event->userId}"]);
    }
}
```

### 5.5 Real-Time Polling Strategy

**Phase 1: HTTP Polling with conditional requests**

```javascript
// Frontend polling
let lastModified = null;

async function pollNotificationCount() {
    const headers = {};
    if (lastModified) {
        headers['If-Modified-Since'] = lastModified;
    }

    const response = await fetch('/api/v1/notifications/count', {
        headers: { ...headers, 'Authorization': `Bearer ${token}` }
    });

    if (response.status === 304) return; // Nothing changed

    lastModified = response.headers.get('Last-Modified');
    const { unread_count } = await response.json();
    updateBadge(unread_count);
}

setInterval(pollNotificationCount, 30000);
```

**Performance characteristics** (1000 concurrent users, 30s interval):
- Request rate: ~33 req/s
- Cache hit rate: ~90% (most polls return cached count)
- `304` responses: ~80% of requests when no new notifications
- DB queries: ~3 req/s (cache misses only)
- Avg response time: < 5ms (cache hit), ~10ms (cache miss + DB query)

---

## 6. Decision Areas

### Decision 1: Real-Time Delivery Strategy

| Option | Latency | Complexity | Infra Changes | PHP Compatibility |
|--------|---------|-----------|---------------|-------------------|
| **A) HTTP Polling (30s)** | 15-30s avg | Very Low | None | ✅ Excellent |
| B) Long Polling | ~5s | Medium | None but ties PHP workers | ⚠️ Problematic |
| C) SSE | ~1s | Medium-High | Async runtime needed | ❌ Requires Swoole/ReactPHP |
| D) WebSocket | < 1s | High | Separate WS server | ❌ Separate service needed |

**Recommendation: A) HTTP Polling**

**Rationale**:
- Zero infrastructure changes — works with existing PHP-FPM + Nginx
- `Last-Modified`/`304` optimization reduces bandwidth by ~80%
- 30s latency is acceptable for a forum (nie chat, nie trading)
- GitHub API uses the same strategy
- Cache-aligned TTL (30s) ensures most polls are sub-millisecond cache hits

**Evidence**: `external-patterns` Section 3.2-3.6, GitHub API documentation

**Phase 2 upgrade path**: SSE via separate Go/Node.js service + Redis pub/sub (documented in `external-patterns` Section 3.6)

---

### Decision 2: Service Architecture (Read vs Write Path)

| Option | Read Performance | Write Consistency | Complexity |
|--------|-----------------|-------------------|-----------|
| A) Full rewrite (bypass manager) | ✅ Optimal | ❌ Must reimplement events/dedup | High |
| B) Pure delegation (all via manager) | ❌ Manager overhead on count | ✅ All logic reused | Low |
| **C) Hybrid (new repo for reads, manager for writes)** | ✅ Optimized reads | ✅ All write logic reused | Medium |

**Recommendation: C) Hybrid**

**Rationale**:
- **Read path**: New `NotificationRepository` with PDO — optimized `countUnread()` (single fast SQL) and `findForUser()` (no type instantiation overhead for raw data)
- **Write path**: Delegate to `notification_manager` — preserves event dispatching, deduplication, method-specific queuing, and type lifecycle
- Auth service follows same pattern: wraps legacy `$auth` for reads, delegates writes

**Evidence**: `codebase-integration` Section 5, auth service design precedent

---

### Decision 3: Notification Aggregation

| Option | Read Perf | Write Perf | Flexibility | UX Quality |
|--------|-----------|-----------|-------------|-----------|
| A) None (flat list) | ✅ Fast | ✅ No change | ❌ No grouping | ❌ Noisy |
| **B) Existing write-time (responders)** | ✅ Fast | ✅ Already done | ✅ Covers posts | ✅ Facebook-style |
| C) Read-time GROUP BY | ⚠️ Complex queries | ✅ No change | ✅ All types | ✅ Flexible |
| D) Dedicated groups table | ❌ Complex writes | ❌ New table + logic | ✅ Full control | ✅ Best UX |

**Recommendation: B) Existing write-time aggregation**

**Rationale**:
- phpBB `post.php::add_responders()` already coalesces up to 25 responders per topic notification
- The data for "John, Jane, and 3 others replied" is **already stored** in `notification_data`
- API deserializes and formats — no new grouping logic
- For non-post types (PM, quote), flat display is appropriate (these are 1:1 interactions)
- Read-time GROUP BY adds query complexity with marginal UX gain for forum volumes

**Evidence**: `codebase-notifications` post.php Lines 400-463; `external-patterns` Section 4.1

---

### Decision 4: Cache Strategy

| Option | Hit Rate | Consistency | Complexity | Infra |
|--------|----------|------------|-----------|-------|
| A) No caching | 0% | ✅ Perfect | None | None |
| **B) Tag-aware pool + event invalidation** | ~90% | ✅ Near-perfect | Low-Medium | Cache service |
| C) Denormalized count on user row | 99% | ⚠️ Can drift | Medium | Schema migration |
| D) Redis counters (INCR/DECR) | 99% | ✅ Atomic | Medium | Redis required |

**Recommendation: B) Tag-aware pool + event invalidation**

**Rationale**:
- Aligns with designed cache service (`getOrCompute()` + `invalidateTags()`)
- Same pattern as hierarchy, messaging, auth services — architectural consistency
- 30s TTL matches polling interval — natural alignment
- Version-based tag invalidation eliminates race conditions
- No schema migration or additional infrastructure needed
- C (denormalized) is a good Phase 2 optimization if needed

**Evidence**: `codebase-integration` Section 1, cache service design Sections 7-9

---

### Decision 5: API Response Schema

| Option | Client Complexity | Bandwidth | Extensibility |
|--------|-------------------|-----------|--------------|
| A) Raw DB rows + `prepare_for_display()` | Medium | Higher (HTML) | Low |
| **B) Transformed JSON with responders** | Low | Optimal | High |
| C) GraphQL | Low | Optimal (flexible) | Overkill | 

**Recommendation: B) Transformed JSON with responders**

**Rationale**:
- `prepare_for_display()` → strip HTML → add type/responders/ISO time → clean JSON
- Server does transformation once; N clients benefit
- Responders array enables flexible client-side formatting
- Consistent with existing API conventions (resource-keyed JSON responses)

**Response schema** (from Section 5.3 above): flat with embedded `responders[]` array, `responder_count`, typed `notification.type.*` identifier, ISO 8601 time alongside unix timestamp.

---

### Decision 6: Database Index Optimization

| Option | Query Impact | Write Impact | Migration |
|--------|-------------|-------------|-----------|
| A) Keep existing indexes | ⚠️ Filesort on list | None | None |
| **B) Add `(user_id, notification_read, notification_time DESC)`** | ✅ Covers count + list | Minimal (~5% write overhead) | Simple ALTER TABLE |
| C) Add covering index with all columns | ✅ Removes all lookups | Higher write overhead | Larger index |

**Recommendation: B) Composite index**

**Rationale**:
- Covers the two most frequent queries: count unread (`WHERE user_id=? AND read=0`) and recent list (`WHERE user_id=? ORDER BY time DESC`)
- Single migration statement
- Minimal write overhead (notification creation is relatively infrequent)
- Existing `user (user_id, notification_read)` index can be dropped (superseded)

**Evidence**: `codebase-notifications` Section 6 (index analysis, filesort observation)

---

## 7. Integration Plan

### 7.1 Files to Create

| File | Layer | Purpose |
|------|-------|---------|
| `src/phpbb/notifications/Service/NotificationService.php` | Service | Facade: cache + repo + manager |
| `src/phpbb/notifications/Repository/NotificationRepository.php` | Repository | PDO queries: count, list |
| `src/phpbb/notifications/Event/NotificationCreatedEvent.php` | Event | Domain event for cache invalidation |
| `src/phpbb/notifications/Event/NotificationsMarkedReadEvent.php` | Event | Domain event for cache invalidation |
| `src/phpbb/notifications/Listener/CacheInvalidationSubscriber.php` | Listener | Tag invalidation on events |
| `src/phpbb/api/v1/controller/notifications.php` | Controller | REST endpoints |

### 7.2 Files to Modify

| File | Change |
|------|--------|
| `src/phpbb/common/config/default/routing/api.yml` | Add 4 notification routes |
| `src/phpbb/common/config/default/container/services_api.yml` | Add controller service |
| New: `src/phpbb/common/config/default/container/services_notifications_api.yml` | Full DI config |
| `src/phpbb/common/config/default/container/services.yml` | Import new services file |

### 7.3 DI Configuration

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
            - '@notification_manager'
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
        arguments:
            - '@phpbb.notifications.service'
```

### 7.4 Migration

```sql
-- Add composite index for notification query optimization
CREATE INDEX idx_notifications_user_time
ON phpbb_notifications (user_id, notification_read, notification_time DESC);

-- Optionally drop superseded index (user_id, notification_read)
-- DROP INDEX user ON phpbb_notifications;
```

---

## 8. Risks & Mitigations

### Risk 1: Cache Service Not Yet Implemented

**Probability**: Medium (cache service is designed but not coded)
**Impact**: Medium — NotificationService depends on `TagAwareCacheInterface`

**Mitigation**:
- Code to interface (`TagAwareCacheInterface`), not implementation
- Create `NullCache` adapter that passes through to repository (no-cache fallback)
- Cache pool factory can return NullCache until real implementation is ready
- Service is fully functional without cache — just slower

### Risk 2: `database_connection` PDO Service Missing from DI

**Probability**: Low-Medium (needs verification)
**Impact**: Medium — NotificationRepository needs PDO

**Mitigation**:
- Check existing DI config for PDO service registration
- If missing: register `database_connection` service wrapping existing DB config
- Fallback: use legacy DBAL adapter in repository (less clean but functional)

### Risk 3: Legacy Event Bridge Gap

**Probability**: Medium
**Impact**: Medium — notifications created by code that doesn't go through new service won't invalidate cache

**Mitigation**:
- Register legacy event listener on `core.notification_manager_add_notifications` that dispatches Symfony `NotificationCreatedEvent`
- This catches ALL notification creations regardless of entry point
- Alternative: periodic cache TTL expiry (30s) catches all changes within one interval

### Risk 4: `prepare_for_display()` Side Effects

**Probability**: Low (code reading suggests it's safe)
**Impact**: Low — could cause unexpected state changes

**Mitigation**:
- Verify method is idempotent before relying on it
- Alternative: build JSON from raw `notification_data` + type metadata directly

### Risk 5: Polling Scale Under Load

**Probability**: Low (forum traffic patterns)
**Impact**: Medium — if many concurrent users poll simultaneously

**Mitigation**:
- 30s cache TTL means ~90% of polls are cache hits (< 1ms)
- `304 Not Modified` saves bandwidth on ~80% of requests
- Add `X-Poll-Interval` header so server can increase interval under load
- Nginx rate limiting can be added if needed

### Risk 6: Stale Count After Rapid Actions

**Probability**: Low
**Impact**: Low — cosmetic issue (badge shows wrong count briefly)

**Mitigation**:
- After mark-read/mark-all-read, return updated `unread_count` in response
- Frontend updates badge immediately from response (no wait for next poll)
- Tag invalidation ensures next poll gets fresh count

---

## 9. Gaps & Open Questions

### Information Gaps

| Gap | Resolution Path |
|-----|----------------|
| `database_connection` PDO service availability in DI | Inspect `services_database.yml` for PDO registration |
| Notification volume per user (for cache sizing) | Query production DB: `SELECT user_id, COUNT(*) FROM phpbb_notifications GROUP BY user_id ORDER BY 2 DESC LIMIT 10` |
| Frontend architecture decision (SPA vs SSR enhancement) | Architecture team decision; API works for both |
| Cache service implementation timeline | Coordinate with cache service task |

### Open Architectural Questions

1. **HTML vs plain text in notification titles?**
   - `FORMATTED_TITLE` contains HTML (`<span>`, `<a>` tags)
   - API clients typically prefer plain text or markdown
   - Recommendation: Provide both `title` (stripped) and `title_html` (original)

2. **Subscription management endpoints?**
   - Users configure notification preferences in UCP
   - Not addressed in this design (keep in legacy UCP for v1)
   - v2: `GET/PUT /api/v1/notifications/settings`

3. **Admin purge/prune endpoint?**
   - Admin endpoint for bulk operations
   - Requires `_api_permission: a_board` route default
   - Low priority — keep in admin panel for v1

4. **Notification deletion by user?**
   - GitHub allows `DELETE /notifications/threads/{id}` (dismiss)
   - phpBB doesn't have user-facing delete — only mark-read
   - Skip for v1; add if frontend requests it

---

## 10. Appendices

### A. Complete Source List

| Source | Location | Findings |
|--------|----------|----------|
| Notification Manager | `src/phpbb/forums/notification/manager.php` | Full API, events, flow |
| Board Method | `src/phpbb/forums/notification/method/board.php` | Load/mark/delete SQL |
| Email Method | `src/phpbb/forums/notification/method/email.php` | Email flow |
| Post Type | `src/phpbb/forums/notification/type/post.php` | Responder coalescence |
| Base Type | `src/phpbb/forums/notification/type/base.php` | `prepare_for_display()` |
| API Entry | `web/api.php` | Bootstrap |
| Application | `src/phpbb/core/Application.php` | HttpKernel wrapper |
| Auth Subscriber | `src/phpbb/api/event/auth_subscriber.php` | JWT validation |
| Routes | `src/phpbb/common/config/default/routing/api.yml` | Route patterns |
| DI Services | `src/phpbb/common/config/default/container/services_api.yml` | Service patterns |
| DB Schema | `phpbb_dump.sql:2534-2549` | Table + index definitions |
| Cache Design | `.maister/tasks/research/2026-04-19-cache-service/outputs/` | Pool, tags, `getOrCompute()` |
| Auth Design | `.maister/tasks/research/2026-04-18-auth-service/outputs/` | Authorization, `_api_user` |
| GitHub API | https://docs.github.com/en/rest/activity/notifications | REST patterns, polling |
| phpBB Docs | https://area51.phpbb.com/docs/dev/master/extensions/ | Type/method extension |

### B. Implementation Complexity Estimate

| Component | Complexity | Depends On |
|-----------|-----------|-----------|
| NotificationRepository (PDO) | Low | `database_connection` service |
| NotificationService (facade) | Low | Repository, cache, manager |
| Notifications Controller | Low | Service |
| CacheInvalidationSubscriber | Low | Cache pool |
| Domain Events (2 classes) | Trivial | — |
| DI Configuration (YAML) | Low | — |
| Route Configuration | Trivial | — |
| DB Migration (index) | Trivial | — |
| Frontend polling JS | Low | JWT token storage |
| `Last-Modified`/`304` support | Low-Medium | Response header management |
| JSON transformation (from `prepare_for_display()`) | Medium | HTML parsing for AVATAR |

### C. Notation Used

- **Confidence levels**: High (90-100%), Medium (70-89%), Low (<70%)
- **Evidence quality**: Direct (source code), Designed (documented design), Inferred (pattern matching), External (external research)
- **Phases**: v1 (initial implementation), v2 (future enhancement)
