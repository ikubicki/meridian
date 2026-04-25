# Requirements: M8 Notifications Service

## Task Description
Implementacja `phpbb\notifications` — standalone service z layered architecture, 4 REST endpointami, HTTP polling 30s + 304, tag-aware cache, 2 wbudowanymi typami.

## Decisions Resolved
- notification_data column: ALTER TABLE → JSON type (DB migration wymagana)
- Built-in types: notification.type.post + notification.type.topic
- Creation pipeline: poza zakresem M8 (osobny milestone)
- React <NotificationBell>: poza zakresem M8 (M10)
- Email delivery: no-op stub zarejestrowany w MethodManager
- phpbb_user_notifications preferencje: poza zakresem M8

## Functional Requirements

### REST Endpoints (4)
1. GET /api/v1/notifications/count — unread count (auth required, cache 30s, Last-Modified/304)
2. GET /api/v1/notifications — paginated list with responders (auth required, cache 30s)
3. POST /api/v1/notifications/{id}/read — mark single notification read (auth, owner-check)
4. POST /api/v1/notifications/read — mark all read for authenticated user

### Polling Support
- GET /count responds with `Last-Modified` header (timestamp of latest notification)
- When client sends `If-Modified-Since` and nothing changed → 304 Not Modified (no body)
- `X-Poll-Interval: 30` header on every response (server-controlled backoff hint)

### Cache Strategy
- Tag-aware cache pool `'notifications'` via CachePoolFactory
- Key pattern: `user:{userId}:count`, `user:{userId}:notifications:{page}`
- Tags: `['user:{userId}']` — invalidated on mark-read mutations
- TTL: 30s (aligned with polling interval)

### TypeRegistry
- `notification.type.post` — reply to a post (entityId = post_id, itemParentId = topic_id)
- `notification.type.topic` — new topic in followed forum (entityId = topic_id, itemParentId = forum_id)
- Extensible via `RegisterNotificationTypesEvent` (Symfony event)

### MethodManager
- `board` method — in-app notifications (full impl using phpbb_notifications table)
- `email` method — no-op stub (registered, does nothing)
- Extensible via `RegisterDeliveryMethodsEvent`

### NotificationDTO JSON Shape
```json
{
  "id": 42,
  "type": "notification.type.post",
  "unread": true,
  "createdAt": 1745612345,
  "data": {
    "itemId": 100,
    "itemParentId": 5,
    "responders": [{"userId": 3, "username": "alice"}],
    "responderCount": 1
  }
}
```

### DB Migration
- ALTER TABLE phpbb_notifications MODIFY notification_data JSON
- ADD INDEX ON (user_id, notification_read, notification_time DESC) (composite)

## Non-Functional Requirements
- GET /count response time < 50ms (cache hit path)
- No legacy notification_manager dependency
- PHPUnit unit tests for all layers
- Playwright E2E tests for all endpoints

## Scope Boundaries
- **In**: 4 REST endpoints, HTTP polling, cache, TypeRegistry (2 types), MethodManager (no-op email), PHPUnit, E2E
- **Out**: React component, creation pipeline, email delivery impl, subscription prefs API

## Reuse
- M7 Messaging as template (entity, repo, service, controller, events, tests)
- Existing TagAwareCacheInterface, DomainEvent, PaginationContext, AuthenticationSubscriber
