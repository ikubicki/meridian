# Decision Log

## ADR-001: Real-Time Delivery Strategy

### Status
Accepted

### Context
The notification system needs to deliver updates to the browser (unread count badge, new notifications in dropdown) without requiring full page reloads. The infrastructure is PHP-FPM + Nginx with no persistent connection support (no Redis, no message broker). The target is Facebook-style bell icon with live-updating badge. Forum use case tolerates moderate latency (not real-time chat).

### Decision Drivers
- Zero infrastructure changes — must work with existing PHP-FPM + Nginx stack
- Acceptable latency for forum context (15-30s is fine; sub-second is overkill)
- Minimized server load for inactive/background tabs
- Proven pattern with predictable scaling characteristics
- Cache-friendly for high hit rates

### Considered Options
1. **HTTP Polling (30s)** with `Last-Modified`/`304 Not Modified`
2. **Long Polling** — server holds connection until new data or timeout
3. **Server-Sent Events (SSE)** via separate Node.js/Go gateway + Redis pub/sub
4. **WebSocket** via Ratchet/Swoole

### Decision Outcome
Chosen option: **HTTP Polling (30s)** with conditional requests, because it requires zero infrastructure changes, aligns with cache TTL for natural hit rates, and is a proven pattern (GitHub Notifications API uses the same approach). 30s polling at 1000 concurrent users = ~33 req/s — trivial for Nginx with 90%+ cache hits.

### Consequences

#### Good
- Zero new infrastructure — works with existing PHP-FPM + Nginx deployment
- `304 Not Modified` eliminates ~80% of response payload bandwidth
- 30s cache TTL = natural alignment with polling interval
- Frontend can adjust interval via `X-Poll-Interval` header (server-controlled backoff)
- Predictable, linear scaling: load = users ÷ interval

#### Bad
- 15-30s average latency before user sees new notification (worst case 30s)
- ~33 req/s of "empty" polling requests per 1000 users (minimal cost but non-zero)
- Not suitable for real-time features (chat, typing indicators)

#### Neutral
- Upgrade path to SSE or WebSocket in v2 is straightforward (change transport, keep API contract)

---

## ADR-002: Notification Aggregation Approach

### Status
Accepted

### Context
Facebook-style notification UX requires grouped notifications: "John, Jane, and 3 others replied to your topic." The existing phpBB database stores `notification_data` as a serialized blob that can contain a `responders[]` array populated at write time by `post.php::add_responders()`. This mechanism coalesces up to 25 responders per (type, topic) combination for unread notifications.

### Decision Drivers
- Minimize new aggregation logic — leverage what already exists in the data model
- Cover the primary use case: post reply grouping (most frequent notification type on a forum)
- Avoid complex `GROUP BY` + `GROUP_CONCAT` SQL on serialized TEXT columns
- Keep API simple for frontend consumption
- Acceptable to skip grouping for 1:1 interaction types (PM, quote)

### Considered Options
1. **Write-time responder aggregation** (existing mechanism in `notification_data`)
2. **Read-time SQL GROUP BY** aggregation at query time
3. **Hybrid** — write-time for posts + read-time for other types

### Decision Outcome
Chosen option: **Write-time responder aggregation**, because the data for "John and N others replied" is already stored in `notification_data->responders[]` by the post notification type. The API deserializes this blob and returns `responders[]` + `responder_count`. No new grouping logic needed. For non-post types (PM, quote, mention) that are inherently 1:1, flat display is appropriate.

### Consequences

#### Good
- Zero new aggregation logic — data already exists in DB
- Simple API: flat list with embedded `responders[]` array
- Frontend gets full flexibility to format rollup text ("John and 3 others")
- Post reply grouping covers the most frequent notification type on forums
- No complex SQL joins or subqueries

#### Bad
- Non-post notification types (PM, quote) are not grouped — flat display only
- Write-time grouping is rigid — cannot change grouping rules without data migration
- If user reads a notification and new replies come, a new notification record is created (no continuation)
- Max 25 responders / 4000 chars serialized data — artificial limits

#### Neutral
- Read-time grouping can be added later (v2) for additional types without breaking the API contract

---

## ADR-003: Cache Strategy

### Status
Accepted

### Context
The count endpoint (`GET /notifications/count`) is the hot path — called every 30 seconds per active user. Without caching, each poll triggers a `COUNT(*)` SQL query + type join. With 1000 users at 30s intervals = ~33 queries/second. The cache service design provides `TagAwareCacheInterface` with `getOrCompute()` and `invalidateTags()` — a proven pattern already designed for hierarchy, messaging, and auth services.

### Decision Drivers
- Count endpoint must respond in < 5ms (cache hit) to meet 50ms budget
- Consistent with cache service architecture already designed for other services
- Event-driven invalidation for near-instant freshness after changes
- No additional infrastructure beyond what cache service provides
- Graceful degradation if cache service is not yet implemented

### Considered Options
1. **Tag-aware pool** with `getOrCompute()` + event invalidation (30s TTL)
2. **Denormalized count** on `phpbb_users` table (`notification_unread_count` column)
3. **Simple file cache** with TTL-only invalidation (60s, no tags)
4. **Redis atomic counters** (INCR/DECR separate from DB)

### Decision Outcome
Chosen option: **Tag-aware pool with event invalidation**, because it aligns with the cache service architecture, provides near-instant freshness via `invalidateTags()` on domain events, and achieves ~90% cache hit rate with 30s TTL. Fallback to `NullTagAwareCache` (pass-through to repository) if cache service is not yet built.

### Consequences

#### Good
- ~90% cache hit rate — most polls are sub-1ms cache reads
- Consistent architecture with hierarchy, messaging, auth cache pools
- `invalidateTags()` provides near-instant freshness after mark-read/new notification
- `getOrCompute()` provides stampede protection (single DB query per cache miss)
- No schema migration needed (unlike denormalized count)

#### Bad
- Depends on cache service implementation (not yet built) — fallback needed
- Cache key explosion: per user × per page variant (mitigated by short 30s TTL = auto-eviction)
- First request per user after cache restart = cold start (DB query)

#### Neutral
- 30s TTL means absolute worst case is 30s stale data even without event invalidation
- Denormalized count is a valid v2 optimization if cache hit rate proves < 85%

---

## ADR-004: Service Architecture — Full Rewrite

### Status
Accepted

### Context
The existing `notification_manager` (~1000 lines) handles type discovery, recipient determination, deduplication, serialization, and method dispatch. The research synthesis recommended a "hybrid facade" (new repository for reads, manager for writes). However, the project direction is a **full forum rewrite** — legacy code should not be a dependency for new services. The new service should be a self-contained, extensible, testable component with clean interfaces.

### Decision Drivers
- Project direction: full forum rewrite from scratch — no legacy dependencies
- Extensibility: easy to add types, methods, integrations via tagged DI
- Testability: clean constructor injection, mockable interfaces, no container injection
- Clean separation of concerns: Repository (data), Service (orchestration), TypeRegistry (types), MethodManager (delivery)
- Performance: direct PDO for reads, no type instantiation overhead for count queries

### Considered Options
1. **Hybrid facade** — new repository for reads, delegate writes to legacy `notification_manager`
2. **Pure delegation** — all operations through legacy manager with JSON transform layer
3. **Decorator pattern** — wrap manager with cache/transform decorators
4. **Full rewrite** — standalone service, bypass legacy manager entirely

### Decision Outcome
Chosen option: **Full rewrite**, because the project is building a new forum from scratch and the new service should be a standalone component with its own type system, method management, repository, and event dispatching. This provides full control over query optimization, clean constructor injection (no `ContainerInterface` anti-pattern), and a testable architecture.

### Consequences

#### Good
- Clean, testable architecture with proper constructor injection
- Full control over query optimization (direct PDO, no type instantiation overhead)
- Extensible type/method system via tagged DI services
- No coupling to legacy manager's internal structure
- Self-contained — can be understood and modified independently

#### Bad
- Must reimplement recipient finding, deduplication, and method dispatch logic
- More initial code than a facade/wrapper approach
- Legacy code that calls `notification_manager` directly won't trigger new events (requires migration)
- Higher upfront development effort

#### Neutral
- The old `notification_manager` can remain operational during transition; migration is gradual
- This is consistent with the full rewrite approach for auth and other services

---

## ADR-005: API Response Format

### Status
Accepted

### Context
The API needs to return notification data in a format that React components can easily consume. Options range from raw DB rows, to server-rendered formatted text, to structured nested groups. The existing `prepare_for_display()` returns HTML-heavy arrays designed for server-side templates. The frontend (React) needs structured data for flexible rendering.

### Decision Drivers
- Frontend flexibility: React components need structured data, not pre-rendered HTML
- Simple consumption: flat iteration (`notifications.map(...)`) preferred over nested structures
- Responder data available: frontend can format "John and N others" text with full flexibility
- Consistent with existing API conventions: resource-keyed flat JSON
- Bandwidth efficiency: minimal payload for polling endpoint

### Considered Options
1. **Flat list with embedded responders** — each notification has `responders[]` array
2. **Server-rendered formatted text** — server provides display-ready strings
3. **Grouped nested response** — notifications grouped by type+target with nested items

### Decision Outcome
Chosen option: **Flat list with embedded responders**, because it gives React components full flexibility to format notification text, provides simple flat iteration, and includes structured responder data for actor rollup. Type-specific `transformForDisplay()` ensures each notification type provides clean structured data (no HTML).

### Consequences

#### Good
- Simple flat list for frontend: `notifications.map(n => <NotificationItem {...n} />)`
- Structured `responders[]` array enables flexible client-side formatting
- Plain text in `title`, `reference`, `forum` — no HTML parsing needed
- Consistent with existing API patterns (resource-keyed JSON)
- Easy to cache: flat structure = simple serialization

#### Bad
- Frontend must implement actor rollup text formatting ("John and 3 others")
- Notifications without responders have empty array — minor inconsistency in display logic
- No server-side i18n for rollup text — frontend handles localization

#### Neutral
- Grouped response (option 3) can be layered on top in v2 without breaking existing flat endpoint

---

## ADR-006: Frontend Framework — React

### Status
Accepted

### Context
The existing phpBB frontend is jQuery 3.6.0 with server-side rendered templates. The notification UI requires dynamic updates (polling badge, dropdown toggle, mark-read interactions). The project direction is modernizing the frontend with React.

### Decision Drivers
- Project direction: frontend modernization with React
- Component encapsulation: bell + badge + dropdown as a self-contained React component tree
- Custom hooks: `useNotifications()` cleanly encapsulates polling, visibility, abort, state
- Visibility API integration: React effect lifecycle naturally handles visible/hidden transitions
- Optimistic UI updates: React state management enables instant mark-read feedback

### Considered Options
1. **jQuery** `setInterval` + `$.ajax` — consistent with existing phpBB
2. **Vanilla JS** Fetch API + Visibility API — zero dependencies
3. **React** component with custom hooks — modern, encapsulated

### Decision Outcome
Chosen option: **React** with `useNotifications` custom hook, because the project is moving to React for the frontend. The hook encapsulates all polling logic (interval, visibility, abort, conditional requests), component tree provides clean separation (Bell, Dropdown, Item), and optimistic updates via state management provide instant UI feedback.

### Consequences

#### Good
- Clean component encapsulation: `<NotificationBell>` is self-contained
- `useNotifications()` hook encapsulates all polling complexity
- Visibility API integration via `useEffect` cleanup
- Optimistic mark-read: state updates instantly, reverts on error
- Consistent with project's frontend modernization direction

#### Bad
- Breaks from phpBB's jQuery ecosystem — notification component is React island
- Requires React runtime loaded on every page with notifications (bundle size)
- Team must know React (learning curve if jQuery-only background)

#### Neutral
- React component can coexist with jQuery pages via `ReactDOM.createRoot()` on a mount point
- Progressive migration: notifications first, then other interactive components

---

## ADR-007: Type/Method Extensibility via Tagged DI

### Status
Accepted

### Context
The notification system must be easily extensible — adding new notification types (mentions, reactions, approvals) and delivery methods (Web Push, Slack, Discord) should not require modifying core components. phpBB's DI container supports tagged services collected via `phpbb\di\service_collection`. This pattern is well-established in the existing codebase.

### Decision Drivers
- Open/Closed Principle: extending without modifying core
- Established pattern: phpBB already uses tagged service collections
- Plugin ecosystem: third-party extensions should be able to add notification types
- Minimal friction: implementing one interface + one YAML tag = done
- Runtime discovery: `TypeRegistry` and `MethodManager` enumerate available types/methods

### Considered Options
1. **Tagged DI services** with interface contracts
2. **Configuration-based registration** (type names in a config array, factory instantiation)
3. **Annotation/attribute-based auto-discovery** (PHP 8 attributes)

### Decision Outcome
Chosen option: **Tagged DI services with interface contracts**, because this is the established phpBB pattern for extensible collections (type tags for `notification.type`, `notification.method`). `NotificationTypeRegistry` and `NotificationMethodManager` receive tagged iterables via constructor injection. New types/methods require only: (1) implement the interface, (2) add a YAML service definition with the tag.

### Consequences

#### Good
- Follows established phpBB DI pattern — familiar to extension developers
- Clean interfaces: `NotificationTypeInterface`, `NotificationMethodInterface`
- Zero core code changes to add types/methods
- Runtime enumerable: UI can list available types for subscription settings
- Testable: mock the interface for unit tests

#### Bad
- YAML-based registration requires knowing the tag name and interface
- No compile-time validation that tagged services implement the correct interface
- Service collection resolution happens at container build time — not lazy

#### Neutral
- PHP 8 attribute-based discovery is a potential future enhancement but not needed now

---

## ADR-008: Notification Data Serialization Format — JSON

### Status
Accepted

### Context
The legacy `notification_data` column stores PHP `serialize()`d data. The new service is a full rewrite with no legacy dependency. The column is `TEXT` type. The stored data includes type-specific fields (topic_title, poster_id, responders array, etc.) that the API ultimately delivers as JSON.

### Decision Drivers
- Clean break: full rewrite should not perpetuate legacy serialization format
- API output is JSON: storing as JSON eliminates serialize/deserialize asymmetry
- Debugging: JSON is human-readable in DB queries
- Cross-language compatibility: JSON is language-agnostic (vs PHP serialize)
- Security: `unserialize()` has known object injection risks; `json_decode()` does not

### Considered Options
1. **Continue PHP `serialize()`** — backward compatible with existing data
2. **JSON** (`json_encode()`/`json_decode()`) — modern, cross-language, safe
3. **Separate columns** — denormalize notification_data into typed columns

### Decision Outcome
Chosen option: **JSON serialization** for `notification_data` in new notifications. New rows use `json_encode()`. Read path handles both JSON and legacy serialized data (auto-detect by checking first character: `{` or `[` = JSON, else `unserialize()`). This provides a gradual migration — old data still readable, new data is JSON.

### Consequences

#### Good
- Human-readable in DB queries and debugging
- No PHP object injection risk (unlike `unserialize()`)
- Direct pass-through to API responses (JSON in DB → JSON in response)
- Cross-language compatible if other services need to read notification data
- Auto-detection enables gradual migration (no big-bang data migration needed)

#### Bad
- Two serialization formats coexist during migration period
- Auto-detection adds minor overhead (check first character per row)
- JSON doesn't support PHP objects/classes (not needed for notification data)

#### Neutral
- Old notifications naturally age out via pruning; JSON becomes sole format over time
