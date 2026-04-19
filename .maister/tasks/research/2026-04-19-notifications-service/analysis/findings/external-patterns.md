# External Patterns: Notification System Best Practices

**Source Category**: external-patterns
**Date**: 2026-04-19
**Confidence**: High (80-95%) — based on documented APIs and established patterns

---

## 1. Facebook-Style Notification Patterns

### 1.1 Notification Grouping / Aggregation

Facebook and social media platforms group notifications to reduce noise. The canonical pattern is **"N actors did X on your Y"**:

- "John, Jane, and 3 others liked your post"
- "5 people commented on your photo"
- "Sarah and 2 others replied to your comment"

**Grouping dimensions** (all must match for aggregation):
1. **Notification type** — same action (like, comment, follow)
2. **Target object** — same entity being acted upon (post #123, topic #456)
3. **Time window** — within a configurable window (e.g., 1 hour, 24 hours)

**Grouping algorithm** (conceptual):
```
GROUP KEY = (notification_type, target_entity_type, target_entity_id)
```
Within each group, sort by time DESC. The group's display time = most recent notification in group.

**Actor rollup strategy**:
- 1 actor: "John commented on your post"
- 2 actors: "John and Jane commented on your post"
- 3+ actors: "John, Jane, and N others commented on your post"
- Show the most recent 1-2 actors by name, count the rest

### 1.2 Read/Unread State Management

**Per-notification read state**:
- Each notification row has a `read` boolean/timestamp
- A grouped notification is "unread" if ANY notification in the group is unread
- Clicking a grouped notification marks ALL items in the group as read

**Badge count**: 
- Typically counts **distinct unread groups**, not individual notifications
- Or simpler: count of unread notification rows (phpBB's current approach)
- Some platforms use "unseen" (never appeared in dropdown) vs "unread" (appeared but not clicked)

**Two-tier state** (Facebook model):
1. **Unseen**: notification exists but user hasn't opened the bell dropdown → increments badge counter
2. **Unread**: user opened dropdown (saw the notification) but hasn't clicked it → notification appears bold/highlighted
3. **Read**: user clicked through to the target content → normal appearance

For a forum like phpBB, a simpler **single boolean `read`** flag is sufficient. The badge shows count of `read = false` notifications.

### 1.3 Bell + Dropdown UX Pattern

Standard pattern across all major platforms:
1. **Bell icon** with numeric badge (count of unread)
2. Click bell → **dropdown panel** with recent notifications (10-20 items)
3. Each item shows: actor avatar, action text, target, relative time, unread indicator
4. "Mark all as read" button at top
5. "See all notifications" link to full page at bottom
6. Opening dropdown marks notifications as "seen" (badge count resets)

**Confidence**: High (95%) — this is the universal standard

---

## 2. REST API Design for Notifications

### 2.1 GitHub Notifications API — Endpoint Design

**Source**: https://docs.github.com/en/rest/activity/notifications

GitHub's API is the gold standard for REST notification API design. Key design decisions:

**Endpoints**:
| Method | Path | Purpose |
|--------|------|---------|
| `GET` | `/notifications` | List notifications for authenticated user |
| `PUT` | `/notifications` | Mark all notifications as read |
| `GET` | `/notifications/threads/{thread_id}` | Get a single notification thread |
| `PATCH` | `/notifications/threads/{thread_id}` | Mark a thread as read |
| `DELETE` | `/notifications/threads/{thread_id}` | Mark a thread as done |
| `GET` | `/notifications/threads/{thread_id}/subscription` | Get thread subscription |
| `PUT` | `/notifications/threads/{thread_id}/subscription` | Subscribe/unsubscribe to thread |
| `DELETE` | `/notifications/threads/{thread_id}/subscription` | Delete thread subscription |
| `GET` | `/repos/{owner}/{repo}/notifications` | List repo notifications |
| `PUT` | `/repos/{owner}/{repo}/notifications` | Mark all repo notifications read |

**Key design patterns from GitHub**:
- **Thread-based model**: Notifications are grouped into "threads" — a thread is a conversation (issue, PR, commit). This is their aggregation mechanism.
- **Reason field**: Each notification has a `reason` (subscribed, mention, author, assign, comment, etc.)
- **Subject nesting**: Thread has a `subject` object with `title`, `url`, `latest_comment_url`, `type`
- **Scope filtering**: Can filter by repo (`/repos/{owner}/{repo}/notifications`)

### 2.2 GitHub Response Schema

```json
{
  "id": "1",
  "unread": true,
  "reason": "subscribed",
  "updated_at": "2014-11-07T22:01:45Z",
  "last_read_at": "2014-11-07T22:01:45Z",
  "subject": {
    "title": "Greetings",
    "url": "https://api.github.com/repos/octokit/octokit.rb/issues/123",
    "latest_comment_url": "https://api.github.com/repos/octokit/octokit.rb/issues/comments/123",
    "type": "Issue"
  },
  "repository": {
    "id": 1296269,
    "name": "Hello-World",
    "full_name": "octocat/Hello-World"
  },
  "url": "https://api.github.com/notifications/threads/1",
  "subscription_url": "https://api.github.com/notifications/threads/1/subscription"
}
```

### 2.3 Query Parameters (GitHub)

| Parameter | Type | Description |
|-----------|------|-------------|
| `all` | boolean | If true, show read notifications too. Default: false (unread only) |
| `participating` | boolean | Only notifications where user is directly involved |
| `since` | ISO 8601 | Only updated after this time |
| `before` | ISO 8601 | Only updated before this time |
| `page` | integer | Page number (default: 1) |
| `per_page` | integer | Results per page (max 50, default 50) |

### 2.4 Mark-as-Read Patterns

GitHub offers multiple granularity levels:

1. **Mark single thread read**: `PATCH /notifications/threads/{id}` → 205 Reset Content
2. **Mark all read**: `PUT /notifications` with optional `last_read_at` timestamp → 202 Accepted
3. **Mark repo read**: `PUT /repos/{owner}/{repo}/notifications` with optional `last_read_at` → 202 Accepted
4. **Mark as done** (dismiss permanently): `DELETE /notifications/threads/{id}` → 204 No Content

**Async processing**: When marking all as read, if too many notifications exist, GitHub returns `202 Accepted` and processes in the background. This is a scalability pattern worth adopting.

### 2.5 Recommended REST API Design for phpBB

Based on GitHub's patterns and forum requirements:

```
GET    /api/v1/notifications                    — list (with filters)
GET    /api/v1/notifications/count              — unread count only (lightweight)
GET    /api/v1/notifications/{id}               — single notification
PATCH  /api/v1/notifications/{id}               — mark single as read
PUT    /api/v1/notifications/mark-read          — mark all/bulk as read
DELETE /api/v1/notifications/{id}               — delete/dismiss notification
```

**Query parameters for list**:
- `unread_only` (boolean, default: true)
- `type` (filter by notification type: post, topic, pm, quote, etc.)
- `since` (ISO 8601 timestamp)
- `limit` (max per page, default 20, max 50)
- `cursor` (cursor-based pagination — see below)

### 2.6 Pagination: Cursor-Based vs Offset

**Offset pagination** (`?page=2&per_page=20`):
- Pros: Simple, GitHub uses it, familiar
- Cons: Inconsistent when new notifications arrive between pages (items shift)

**Cursor-based pagination** (`?cursor=abc123&limit=20`):
- Pros: Stable under inserts, better for real-time data, more efficient for large datasets
- Cons: Can't jump to arbitrary page, slightly more complex

**Recommendation for phpBB**: Start with **offset pagination** (simpler, matches existing phpBB patterns). The notification volume per user in a forum is manageable. Cursor-based is overkill unless scaling to millions of notifications per user.

**Confidence**: High (90%) — GitHub's approach is well-documented and proven

---

## 3. Real-Time Delivery Strategies

### 3.1 Comparison Table

| Strategy | Latency | Complexity | Server Resources | PHP Compatibility |
|----------|---------|-----------|-----------------|-------------------|
| **HTTP Polling** | High (interval) | Very Low | Low per-request, high bandwidth | Excellent |
| **Long Polling** | Medium | Low-Medium | Medium (held connections) | Possible but ties up PHP workers |
| **Server-Sent Events (SSE)** | Low | Medium | High (persistent connections) | Problematic with traditional PHP |
| **WebSockets** | Very Low | High | High (persistent connections) | Requires separate server (Ratchet/Swoole) |

### 3.2 HTTP Polling (Recommended for phpBB)

**How it works**: Client sends `GET /api/v1/notifications/count` every N seconds.

**Implementation**:
```javascript
// Client-side
setInterval(async () => {
    const resp = await fetch('/api/v1/notifications/count');
    const { count } = await resp.json();
    updateBadge(count);
}, 30000); // Every 30 seconds
```

**GitHub's polling optimization** (from their docs):
- Return `Last-Modified` header on notification responses
- Client sends `If-Modified-Since` header on next poll
- Server returns `304 Not Modified` if nothing changed (no body, saves bandwidth)
- `X-Poll-Interval` header tells client how often to poll (server controls rate)

```
GET /notifications
→ 200 OK
→ Last-Modified: Thu, 25 Oct 2012 15:16:27 GMT
→ X-Poll-Interval: 60

GET /notifications
If-Modified-Since: Thu, 25 Oct 2012 15:16:27 GMT
→ 304 Not Modified
→ X-Poll-Interval: 60
```

**Pros**:
- Zero infrastructure changes — works with standard PHP-FPM + Nginx
- Trivially simple to implement
- Each request is stateless, scales normally
- Existing phpBB caching layer works perfectly

**Cons**:
- 30-60 second delay before user sees new notification
- Wasted requests when nothing changed (mitigated by `304` responses)
- For 1000 concurrent users at 30s interval = ~33 requests/second (very manageable)

**Bandwidth optimization pattern**: 
- Lightweight count endpoint returns just `{ "count": 5 }`
- Full notification list only fetched when user opens dropdown
- `304 Not Modified` for unchanged state (requires `ETag` or `Last-Modified`)

**Confidence**: High (95%) — this is the pragmatic choice for a PHP forum

### 3.3 Long Polling

**How it works**: Client sends request, server holds it open until new data is available (up to a timeout), then responds.

**Problems with PHP**:
- Each long-poll holds a PHP-FPM worker for 30+ seconds
- With limited PHP-FPM pool size (e.g., 50 workers), only 50 concurrent users could long-poll
- Apache/mod_php has the same problem — one thread per connection

**Verdict**: **Not recommended for PHP** without async runtime. Use regular polling instead.

### 3.4 Server-Sent Events (SSE)

**Source**: https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events

**How it works**: Server maintains an open HTTP connection, pushes `text/event-stream` data as events occur.

**PHP SSE implementation** (from MDN docs):
```php
header("Content-Type: text/event-stream");
header("Cache-Control: no-cache");
header("X-Accel-Buffering: no");

while (true) {
    // Check for new notifications
    $count = get_unread_count($user_id);
    echo "event: notification_count\n";
    echo "data: {\"count\": $count}\n\n";
    
    if (ob_get_contents()) {
        ob_end_flush();
    }
    flush();
    
    if (connection_aborted()) break;
    sleep(5);
}
```

**Client side** (universal browser support):
```javascript
const source = new EventSource('/api/v1/notifications/stream');

source.addEventListener('notification_count', (e) => {
    const { count } = JSON.parse(e.data);
    updateBadge(count);
});

source.addEventListener('new_notification', (e) => {
    const notification = JSON.parse(e.data);
    showToast(notification);
});
```

**SSE features relevant to notifications**:
- Auto-reconnect built into browser (configurable via `retry:` field)
- Event IDs enable resumption after disconnect (`Last-Event-ID` header)
- Named events (e.g., `notification_count`, `new_notification`)
- One-way (server → client) — perfect for notifications

**PHP limitations with SSE**:
- Same problem as long polling: ties up a PHP-FPM worker per connection
- Traditional PHP (`while(true) sleep(5)`) is just fancy polling on server side
- For true push, need an event-driven runtime:
  - **ReactPHP** (event loop in PHP)
  - **Swoole** (async PHP extension, coroutine-based)
  - **Separate Node.js/Go service** that acts as SSE gateway

**Browser limitations**:
- HTTP/1.1: Max 6 SSE connections per domain per browser (all tabs share!)
- HTTP/2: Limit is 100 concurrent streams (much better)
- Solution: Use shared worker or service worker to multiplex

**Verdict for phpBB**: SSE is architecturally appealing but **requires infrastructure changes** (async runtime or separate service). Not suitable for v1 with traditional PHP-FPM. Consider as v2 enhancement.

### 3.5 WebSockets

**How it works**: Full-duplex persistent TCP connection after HTTP upgrade handshake.

**PHP options**:
- **Ratchet** (ReactPHP-based): WebSocket server library for PHP
- **Swoole**: PHP extension with built-in WebSocket server
- **Separate service**: Node.js/Go WebSocket server reads from shared queue (Redis pub/sub)

**When WebSockets make sense**:
- Chat applications (need bidirectional)
- Collaborative editing
- Gaming

**When NOT to use for notifications**:
- Notifications are one-way (server → client)
- SSE provides the same push capability with less complexity
- WebSockets don't work through many corporate proxies
- PHP isn't designed for persistent connections

**Verdict for phpBB**: **Overkill** for a notification system. SSE covers the use case. Polling is simpler.

### 3.6 Recommended Strategy for phpBB (Phased)

**Phase 1 (v1)**: HTTP Polling with conditional requests
- `GET /api/v1/notifications/count` every 30s
- `Last-Modified` / `304 Not Modified` optimization
- Zero infrastructure changes
- Already gives good UX for a forum

**Phase 2 (future)**: SSE via separate lightweight service
- Small Node.js or Go service as SSE gateway
- PHP writes to Redis pub/sub when notification created
- SSE service subscribes to Redis and pushes to clients
- Sub-second notification delivery

**Confidence**: High (90%) — architecture proven by GitHub (polling) and many real-time apps (SSE)

---

## 4. Notification Aggregation Algorithms

### 4.1 Time-Window Based Grouping

**Algorithm**: Group notifications with same type + target within a time window.

```
Time window: 1 hour (configurable)

Notification A: user1 liked post#1 at 10:00
Notification B: user2 liked post#1 at 10:15
Notification C: user3 liked post#1 at 10:45
→ GROUPED: "user1, user2, and user3 liked your post" (3 items, displayed at 10:45)

Notification D: user4 liked post#1 at 12:00 (> 1hr gap from last)
→ NEW GROUP: "user4 liked your post"
```

**Implementation approaches**:

#### Approach A: Read-Time Aggregation (Recommended for phpBB)
- Store each notification as an individual row
- Aggregate at query time using SQL GROUP BY or application logic
- `GROUP BY notification_type, item_type, item_id`
- Select most recent N actors per group

```sql
-- Get grouped notifications for a user
SELECT item_type, item_id, notification_type_id,
       COUNT(*) as actor_count,
       MAX(notification_time) as latest_time,
       MIN(notification_read) as all_read,
       GROUP_CONCAT(notification_data ORDER BY notification_time DESC) as actors_json
FROM phpbb_notifications
WHERE user_id = ?
GROUP BY notification_type_id, item_type, item_id
ORDER BY latest_time DESC
LIMIT 20
```

**Pros**: Simple, no extra tables, flexible grouping rules, individual notifications still accessible
**Cons**: GROUP BY can be slow on large tables; need proper indexes

#### Approach B: Write-Time Aggregation
- Maintain a `notification_groups` table
- When new notification arrives, check if matching group exists within time window
- If yes: increment counter, update actor list, bump timestamp
- If no: create new group

```sql
-- notification_groups table
CREATE TABLE phpbb_notification_groups (
    group_id        INT AUTO_INCREMENT PRIMARY KEY,
    user_id         INT NOT NULL,
    notification_type VARCHAR(255) NOT NULL,
    item_type       VARCHAR(255) NOT NULL,
    item_id         INT NOT NULL,
    actor_count     INT DEFAULT 1,
    latest_actor_ids TEXT,          -- JSON: [user_id, user_id, ...]
    latest_time     INT NOT NULL,
    is_read         TINYINT DEFAULT 0,
    created_at      INT NOT NULL
);
```

**Pros**: Fast reads, pre-computed display data
**Cons**: Complex write logic, harder to "unexpand" a group, data duplication

#### Recommendation for phpBB
**Use Approach A (read-time aggregation)**:
- phpBB already stores individual notifications in `phpbb_notifications`
- Forum notification volumes are moderate (not Facebook-scale)
- Add proper composite indexes: `(user_id, notification_type_id, item_type, item_id, notification_time)`
- Aggregate in PHP service layer, not in SQL (more flexible grouping rules)

### 4.2 Type + Target Grouping Matrix

For a forum, the natural grouping:

| Notification Type | Group By | Display |
|-------------------|----------|---------|
| New reply to topic | topic_id | "3 new replies in Topic X" |
| Quote | topic_id + post_id | "User quoted you in Topic X" (usually 1:1, no grouping) |
| Like/reaction | post_id | "John and 2 others liked your post in Topic X" |
| PM | pm_id | Not grouped (each PM is distinct) |
| Topic subscription | forum_id | "5 new topics in Forum X" |
| Mention | post_id | "User mentioned you in Topic X" (usually 1:1) |

### 4.3 Actor Rollup Display Logic

```php
function format_actor_text(array $actors): string
{
    $count = count($actors);
    
    if ($count === 1) {
        return $actors[0]['username'];
    }
    
    if ($count === 2) {
        return $actors[0]['username'] . ' and ' . $actors[1]['username'];
    }
    
    // 3+: "John, Jane, and N others"
    $others = $count - 2;
    return sprintf(
        '%s, %s, and %d %s',
        $actors[0]['username'],
        $actors[1]['username'],
        $others,
        $others === 1 ? 'other' : 'others'
    );
}
```

### 4.4 When to Break Aggregation

- **Different target**: new topic → new group (even same type)
- **Time gap too large**: e.g., > 24 hours since last notification in group → new group
- **User already read the group**: after reading, new activity starts a new group (unread indicator resets)
- **Max actors per group**: cap at reasonable number (e.g., 50) to prevent unbounded groups

**Confidence**: High (85%) — based on established social media UX patterns

---

## 5. Performance Patterns

### 5.1 Caching Notification Counts

**Redis counter pattern** (ideal for real-time count):
```
Key: notification:count:{user_id}
Value: integer (unread count)

# On new notification:
INCR notification:count:42

# On mark-as-read (single):
DECR notification:count:42

# On mark-all-read:
SET notification:count:42 0

# On read:
GET notification:count:42
```

**Without Redis** (MySQL + application cache):
```php
// Cache unread count in user's session or phpBB cache
$cache_key = 'notification_count_' . $user_id;
$count = $cache->get($cache_key);

if ($count === false) {
    $count = $db->count('phpbb_notifications', [
        'user_id' => $user_id,
        'notification_read' => 0
    ]);
    $cache->set($cache_key, $count, 60); // TTL 60 seconds
}
```

**phpBB-specific approach**: Use phpBB's existing cache driver (`\phpbb\cache\driver\*`):
- File-based cache (default) or Redis/Memcached if configured
- Cache key per user: `_notification_count_{user_id}`
- Invalidate on: new notification created, notification marked read
- TTL: 30-60 seconds (matches polling interval)

### 5.2 Denormalized Count on User Record

Store unread count directly on user row:
```sql
ALTER TABLE phpbb_users ADD COLUMN notification_unread_count INT DEFAULT 0;
```

**Pros**: Fastest possible read (single column on already-loaded user row)
**Cons**: Must keep in sync (increment on create, decrement on read, reset on mark-all)
**Risk**: Count can drift out of sync; need periodic reconciliation job

**phpBB already does this partially**: `user_notifications` count exists. The pattern is proven in the codebase.

### 5.3 Read-Through Cache for Recent Notifications

For the dropdown showing latest 10-20 notifications:

```php
$cache_key = "notifications_recent_{$user_id}";
$notifications = $cache->get($cache_key);

if ($notifications === false) {
    $notifications = $notification_service->get_recent($user_id, 20);
    $cache->set($cache_key, $notifications, 30); // Short TTL
}
```

**Invalidation triggers**:
1. New notification for this user → delete cache key
2. User marks notification(s) as read → delete cache key
3. TTL expiry (safety net)

### 5.4 Cache Invalidation Strategy

**Event-driven invalidation** (phpBB has event dispatcher):

| Event | Invalidation Action |
|-------|---------------------|
| Notification created | Increment count cache, invalidate recent list cache |
| Notification read (single) | Decrement count cache, invalidate recent list cache |
| Mark all read | Set count to 0, invalidate recent list cache |
| Notification deleted | Decrement count (if was unread), invalidate recent list |

**Implementation with phpBB events**:
```php
// In notification service, after creating notification:
$this->cache->delete('_notification_count_' . $user_id);
$this->cache->delete('_notifications_recent_' . $user_id);

// Or better: use increment/decrement for count to avoid race conditions
```

### 5.5 Database Indexing for Notification Queries

Critical indexes for notification queries:

```sql
-- Primary query: user's unread notifications, sorted by time
CREATE INDEX idx_notif_user_unread ON phpbb_notifications 
    (user_id, notification_read, notification_time DESC);

-- Grouping query: for aggregation
CREATE INDEX idx_notif_grouping ON phpbb_notifications 
    (user_id, notification_type_id, item_type, item_id, notification_time);

-- Count query: fast unread count
CREATE INDEX idx_notif_count ON phpbb_notifications 
    (user_id, notification_read);
```

**Confidence**: High (90%) — standard database and caching patterns

---

## 6. Reference Response Schemas

### 6.1 GitHub Notification Thread Response

```json
{
  "id": "1",
  "unread": true,
  "reason": "subscribed",
  "updated_at": "2014-11-07T22:01:45Z",
  "last_read_at": "2014-11-07T22:01:45Z",
  "subject": {
    "title": "Greetings",
    "url": "https://api.github.com/repos/octokit/octokit.rb/issues/123",
    "latest_comment_url": "https://api.github.com/repos/octokit/octokit.rb/issues/comments/123",
    "type": "Issue"
  },
  "repository": { "id": 1296269, "name": "Hello-World", "full_name": "octocat/Hello-World" },
  "url": "https://api.github.com/notifications/threads/1",
  "subscription_url": "https://api.github.com/notifications/threads/1/subscription"
}
```

**Key GitHub design decisions**:
- `reason` field explains WHY user got this notification
- `subject` is a linked resource (URL to the actual issue/PR/commit)
- `subscription_url` for managing notification preferences per-thread
- `last_read_at` tracks when user last viewed this thread

### 6.2 Proposed phpBB Notification Response Schema

**Individual notification** (flat, no aggregation):
```json
{
  "id": 1234,
  "type": "post",
  "read": false,
  "time": "2026-04-19T10:30:00+00:00",
  "actor": {
    "user_id": 42,
    "username": "john_doe",
    "avatar_url": "/images/avatars/42.jpg"
  },
  "target": {
    "type": "topic",
    "id": 789,
    "title": "Discussion about new features",
    "url": "/viewtopic.php?t=789&p=1234#p1234"
  },
  "text": "john_doe replied to your topic \"Discussion about new features\"",
  "url": "/viewtopic.php?t=789&p=1234#p1234"
}
```

**Aggregated notification** (grouped):
```json
{
  "id": "group_post_topic_789",
  "type": "post",
  "read": false,
  "time": "2026-04-19T10:30:00+00:00",
  "actors": [
    { "user_id": 42, "username": "john_doe", "avatar_url": "/images/avatars/42.jpg" },
    { "user_id": 55, "username": "jane_smith", "avatar_url": "/images/avatars/55.jpg" }
  ],
  "actor_count": 5,
  "target": {
    "type": "topic",
    "id": 789,
    "title": "Discussion about new features",
    "url": "/viewtopic.php?t=789"
  },
  "text": "john_doe, jane_smith, and 3 others replied to your topic",
  "notification_ids": [1234, 1235, 1236, 1237, 1238],
  "url": "/viewtopic.php?t=789"
}
```

**List response with metadata**:
```json
{
  "notifications": [ /* array of notification objects */ ],
  "unread_count": 12,
  "total_count": 150,
  "pagination": {
    "page": 1,
    "per_page": 20,
    "total_pages": 8,
    "has_next": true
  }
}
```

**Count-only endpoint** (lightweight, for polling):
```json
{
  "unread_count": 12
}
```

### 6.3 GitHub Thread Subscription Response

```json
{
  "subscribed": true,
  "ignored": false,
  "reason": null,
  "created_at": "2012-10-06T21:34:12Z",
  "url": "https://api.github.com/notifications/threads/1/subscription",
  "thread_url": "https://api.github.com/notifications/threads/1"
}
```

Relevant for phpBB's user notification preferences (which types to receive via which methods).

---

## 7. Implementation Complexity Summary

| Pattern | Complexity | Infrastructure Changes | Recommendation |
|---------|-----------|----------------------|----------------|
| REST endpoints (list, count, mark-read) | Low | None | **v1 — must have** |
| HTTP polling with `304` optimization | Low | None | **v1 — must have** |
| Read-time aggregation (GROUP BY) | Medium | None (SQL + PHP) | **v1 — should have** |
| Per-user count cache | Low | None (use existing cache) | **v1 — must have** |
| Denormalized count on user record | Low | Schema migration | **v1 — nice to have** |
| Database indexes for notifications | Low | Schema migration | **v1 — must have** |
| Actor rollup display ("N others") | Low | None (PHP formatting) | **v1 — should have** |
| Write-time aggregation groups | High | New table, complex logic | **v2 — if needed** |
| SSE real-time push | High | Async runtime or separate service | **v2 — future** |
| WebSockets | Very High | Separate WS server | **Not recommended** |
| Redis pub/sub for real-time | Medium | Redis infrastructure | **v2 — future** |

---

## Sources

1. **GitHub Notifications REST API**: https://docs.github.com/en/rest/activity/notifications
   - Endpoint design, response schemas, polling optimization, mark-as-read patterns
   
2. **MDN — Using Server-Sent Events**: https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events
   - SSE protocol format, PHP implementation, browser compatibility, limitations (6 connections per domain on HTTP/1.1)

3. **web.dev — EventSource Basics**: https://web.dev/articles/eventsource-basics
   - SSE vs WebSocket comparison, PHP server example, event stream format, reconnection handling

4. **Facebook notification UX patterns**: Industry-standard, observed across Facebook, LinkedIn, Twitter, Reddit
   - Aggregation, actor rollup, bell+dropdown, read/unread/seen states

5. **GitHub polling optimization**: Documented in their API docs
   - `Last-Modified` / `If-Modified-Since` / `304 Not Modified` / `X-Poll-Interval` pattern
