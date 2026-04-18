# Decision Log: `phpbb\hierarchy` Service

---

## ADR-001: Single Entity + Typed Behavior Delegates

### Status
Accepted

### Context
The `phpbb_forums` table stores all three forum types (Category=0, Forum=1, Link=2) in a single table with 50 columns. Categories cannot have posts, links redirect to URLs, and forums have full content. The entity model must handle these behavioral differences while mapping to one physical table. The plugin system needs to support custom forum types (e.g., Wiki=3) without core code changes.

Four approaches were considered: (A) single entity with type enum only, (B) inheritance hierarchy with base + subtypes, (C) single entity + behavior delegates via registry, (D) anemic entity with all logic in services.

### Decision Drivers
- All three types share the same DB table and nested set tree — no JOIN overhead justified
- Plugin extensibility requires registering new forum types at runtime
- Type-specific validation and behavior must be enforced (categories can't have posts, links need URLs)
- Entity hydration must be simple — one `SELECT *` → one constructor
- Legacy anti-pattern of scattered `if (forum_type == X)` checks must not be recreated

### Considered Options
1. **Single Entity with ForumType enum** — One `Forum` class, type checking at service layer
2. **Base Node + Specialized Subtypes** — `Category extends Node`, `PostingForum extends Node`, `ForumLink extends Node`
3. **Single Entity + Typed Behavior Delegates** — One `Forum` entity + `ForumTypeBehaviorRegistry` mapping types to behavior objects
4. **Anemic Entity + Rich Service Layer** — Pure data class, all logic in services

### Decision Outcome
Chosen option: **3 — Single Entity + Typed Behavior Delegates**, because it combines the simplicity of 1:1 DB mapping (single entity, trivial hydration) with the extensibility of type-specific behavior via the plugin system. The `ForumTypeRegistry` maps `ForumType` → `ForumTypeBehaviorInterface`, allowing plugins to register custom types by providing a behavior delegate without modifying core code.

### Consequences

#### Good
- 1:1 mapping to `phpbb_forums` table — single hydration path, no factory switching
- Plugins register new forum types by implementing `ForumTypeBehaviorInterface` and registering via `RegisterForumTypesEvent`
- Validation rules are centralized per type (not scattered across services)
- `canHaveContent()`, `canHaveChildren()`, `getEditableFields()` provide a clean API for UI and logic decisions
- Composition over inheritance — no deep class hierarchies

#### Bad
- Two concepts to learn: entity (data) + behavior delegate (rules)
- Behavior interface must be designed carefully upfront — adding methods is a breaking change
- Properties like `link` and `password` exist on all entity instances but are semantically irrelevant for some types (no compile-time enforcement)
- ForumTypeRegistry lookup adds one level of indirection per type-dependent operation

---

## ADR-002: Port Legacy Nested Set to PDO

### Status
Accepted

### Context
The forum hierarchy uses a nested set model with `left_id`, `right_id`, and `parent_id` columns. Two parallel implementations exist: an OOP `nestedset_forum` class (870 LOC, 17 methods, never called by ACP) and raw inline SQL in `acp_forums.php` (2245 LOC). The new `TreeService` must unify these. The existing data in production databases uses tight-numbered `left_id`/`right_id` values. Any change to the tree storage model requires data migration.

### Decision Drivers
- Existing `left_id`/`right_id` data in production must work without migration
- The legacy nested set math is battle-tested over ~17 years
- Tree mutations are admin-only operations — infrequent, latency-tolerant
- The `left_id` composite index is already built and optimized
- `ORDER BY left_id` provides natural DFS pre-order traversal (the primary read pattern)
- The insert semantics mismatch (legacy class inserts at root, ACP inserts at position) must be resolved

### Considered Options
1. **Port legacy `nestedset.php` to PDO** — Same algorithms, PHP 8.2 types, PDO prepared statements, fix known bugs
2. **Fresh nested set implementation (clean room)** — New code with gap-based numbering for cheaper inserts
3. **Materialized path (path enumeration)** — Replace `left_id`/`right_id` with `/1/5/12/` path column
4. **Closure table** — New `forum_closure` table storing all ancestor-descendant pairs

### Decision Outcome
Chosen option: **1 — Port legacy `nestedset.php` to PDO**, because the nested set mathematics are proven correct, the existing data requires zero migration, and write frequency is very low (admin-only). The port resolves the dual-implementation problem by making the OOP `TreeService` the single source of truth. The insert semantics mismatch is fixed by combining insert + position atomically (insert as last child of parent in one step).

Key improvements over legacy:
- PDO parameterized queries replace `$db->sql_query()` with `sql_escape()`
- Fixed `remove_subset` argument count bug (legacy passes unused 4th arg)
- Insert-at-position replaces legacy two-step insert-at-root + `change_parent()`
- PHP 8.2 strict types, readonly properties, enums

### Consequences

#### Good
- Zero data migration — production databases work immediately
- Battle-tested algorithms reduce risk of subtle tree corruption bugs
- Known performance characteristics — same index patterns, same query costs
- Directly addresses the dual-implementation problem identified in analysis
- `forum_parents` cache continues to work (can convert PHP serialize → JSON as implementation detail)

#### Bad
- O(n) row shifts on every mutation — acceptable for infrequent admin operations
- Advisory locking creates a single-writer bottleneck — acceptable for admin-only ops
- Inherits algorithmic complexity of nested set — harder to debug if data gets corrupted
- `forum_parents` serialized cache is fragile (mitigated by planned JSON conversion)

---

## ADR-003: Five-Service Decomposition

### Status
Accepted

### Context
The legacy code is a monolith: `acp_forums.php` (2245 LOC) handles CRUD, tree operations, validation, cache invalidation, and event dispatch in one file. `display_forums()` (700 LOC) mixes SQL, ACL, tracking, and template rendering. `markread()` (230 LOC) handles read tracking. `watch_topic_or_forum()` (200 LOC) handles subscriptions. The new design must decompose this into testable, independent services.

Analysis confirmed that tracking (`phpbb_forums_track`) and subscriptions (`phpbb_forums_watch`) have completely different schemas, different update patterns, and different UI flows — they share only the `forum_id` foreign key.

### Decision Drivers
- Tracking and subscriptions are genuinely separate concerns (different schemas, different update patterns)
- Tree operations (nested set math) are independent of forum CRUD (inserting DB rows)
- A facade is needed to coordinate cross-cutting operations (event dispatch, decorator pipeline, transaction management)
- Each service should be independently testable with mock dependencies
- Simple operations (display forum index) should not require deep service composition

### Considered Options
1. **Five Services** — HierarchyService (facade), ForumRepository, TreeService, TrackingService, SubscriptionService
2. **Three Services (merged)** — HierarchyService (facade + tracking + subscriptions), ForumRepository, TreeService
3. **Six Services (+ read model)** — Five services + ForumTreeReadModel for denormalized display queries
4. **Four Services (no facade)** — ForumRepository, TreeService, TrackingService, SubscriptionService; controllers orchestrate

### Decision Outcome
Chosen option: **1 — Five Services**, because tracking and subscriptions are genuinely independent concerns that should evolve separately. The facade provides a natural coordination point for cross-cutting operations (event dispatch, decorator pipeline, transaction boundaries) without putting orchestration logic in controllers.

### Consequences

#### Good
- Clean separation: tree math, persistence, tracking, subscriptions are independently testable
- Facade provides single entry point while preserving internal modularity
- TrackingService and SubscriptionService can evolve independently (e.g., Redis-backed tracking, webhook subscriptions)
- Plugins can depend on specific sub-services (e.g., only `TreeServiceInterface`)
- Each interface is focused — no 20+ method god interface

#### Bad
- Five interfaces + five implementations + facade = significant initial surface area
- DI configuration has 5+ service definitions
- Simple operations require coordinating multiple services through the facade
- Risk of facade becoming a thin pass-through for simple queries

---

## ADR-004: Events + Request/Response Decorators for Plugins

### Status
Accepted

### Context
The legacy phpBB extension system uses `service_collection` with tagged DI services and compiler passes for plugin discovery. Extensions implement interfaces, register as tagged services, and are auto-discovered at container compilation time. This pattern is used for auth providers, notification types, and OAuth services.

**The user explicitly decided to drop the legacy extension system entirely for `phpbb\hierarchy`.** The rationale: the new architecture should use a modern event + decorator pattern instead of the phpBB-specific `service_collection` / tagged services / compiler pass machinery. This is a deliberate architectural break from phpBB convention.

The new plugin model must support:
- Custom forum types (e.g., Wiki forum)
- Side effects on CRUD operations (e.g., initialize wiki pages on create)
- Enriching request DTOs before processing (e.g., adding wiki-specific fields)
- Enriching response DTOs after processing (e.g., adding wiki page counts)

### Decision Drivers
- User explicitly stated: "legacy extensions system will be dropped completely"
- Plugins must extend via events (domain event listeners) and decorators (request/response wrapping)
- ForumType registration needs a mechanism that doesn't rely on service_collection
- The pattern must be understandable without deep phpBB DI knowledge
- Decorators must be composable — multiple plugins can decorate the same request/response
- Events must be typed (not the legacy `compact()/extract()` pattern)

### Considered Options
1. **Legacy `service_collection` + tagged services** — phpBB standard pattern: `HierarchyPluginInterface`, compiler pass auto-discovers tagged services into `ordered_service_collection`, lazy loading
2. **Events + Request/Response Decorators** — Plugins extend via: (a) Symfony `EventSubscriberInterface` for domain events, (b) `RequestDecoratorInterface` / `ResponseDecoratorInterface` for DTO enrichment, (c) `RegisterForumTypesEvent` for type registration
3. **PHP 8.2 Attributes for discovery** — Plugins annotate classes with `#[HierarchyPlugin(...)]`, custom compiler pass scans attributes
4. **Interface-only (no auto-discovery)** — Plugins implement `HierarchyPluginInterface`, explicitly register as constructor arguments

### Decision Outcome
Chosen option: **2 — Events + Request/Response Decorators**, because the user explicitly mandated dropping the legacy extension system. This approach provides:

- **Events**: Plugins implement `EventSubscriberInterface` and subscribe to typed domain events (`ForumCreatedEvent`, `ForumDeletedEvent`, etc.). No interface to implement — just listen to the events you care about.
- **Request decorators**: Plugins implement `RequestDecoratorInterface` with `supports()` and `decorateRequest()`. Decorators add extra data to request DTOs via `withExtra()` before the service processes them.
- **Response decorators**: Plugins implement `ResponseDecoratorInterface` with `supports()` and `decorateResponse()`. Decorators enrich response DTOs via `withExtra()` after the service produces them.
- **Type registration**: Plugins register custom `ForumTypeBehaviorInterface` implementations by listening to `RegisterForumTypesEvent` dispatched at boot time.

The `DecoratorPipeline` collects request and response decorators and applies them in priority order. Decorators register via `hierarchy.request_decorator` / `hierarchy.response_decorator` DI tags collected by a minimal compiler pass — this is NOT the legacy `service_collection` pattern but a targeted pipeline assembly.

### Consequences

#### Good
- No `service_collection`, no `ordered_service_collection`, no `HierarchyPluginInterface`
- Plugins are loosely coupled — listen to events, decorate DTOs, no shared interface contract
- Adding new extension points = adding new events (no interface BC break)
- Typed domain events replace legacy `compact()/extract()` arrays
- Request/response decoration is composable — multiple plugins decorate independently
- ForumType registration via event is clean: plugins react to `RegisterForumTypesEvent` at boot

#### Bad
- Breaking phpBB convention — existing extension developers must learn the new pattern
- No compile-time guarantee that a plugin handles required lifecycle events
- Event discovery requires documentation (which events exist, what payloads they carry)
- Decorator pipeline adds processing overhead per request (mitigated by `supports()` short-circuit)
- DI tags still needed for decorator collection — a minimal compiler pass is required
- No unified "plugin interface" to inspect — discovering what a plugin does requires reading its event subscriptions + decorators

---

## ADR-005: Event-Driven API

### Status
Accepted

### Context
The `HierarchyService` facade needs an API style for its public methods. The key question: what do service methods return? Options range from direct entities (`createForum() → Forum`) to command objects to domain events. The user selected event-driven returns where mutating methods return domain event objects.

14 domain events were identified during research. Events must serve dual purposes: (a) return value to the immediate caller, (b) dispatched to listeners for side effects.

### Decision Drivers
- Domain events are already planned for the plugin architecture (ADR-004)
- Events as returns provide a natural audit trail
- Side effects (cache invalidation, ACL cleanup, wiki page setup) must be driven by events
- The caller needs both the entity AND any plugin-enriched response data
- Read operations (getTree, getForum) should NOT return events — they are side-effect-free

### Considered Options
1. **Direct service methods** — `createForum() → Forum`, events dispatched internally as side effects only
2. **Command pattern (CQRS-lite)** — `CreateForumCommand` → handler → `CommandResult`, commands are serializable
3. **Event-driven (domain events as returns)** — `createForum() → ForumCreatedEvent`, events are both return values and dispatched to listeners
4. **Fluent builder** — `$hierarchy->deleteForum(42)->moveContentTo(10)->execute()`

### Decision Outcome
Chosen option: **3 — Event-driven**, because events already power the plugin architecture and making them the primary return type creates a unified model. The caller receives the event (which contains the entity + decorated response), and the same event is dispatched to listeners for side effects. This naturally provides an audit trail.

**Important constraint**: Read operations (`getForum`, `getTree`, `getPath`, `getSubtree`, `getChildIds`, `isForumUnread`, `getUnreadStatus`, `isSubscribed`, `getSubscribers`) return DTOs directly, NOT events. Events are only for mutations.

### Consequences

#### Good
- Unified model: events are both return values and listener triggers
- Natural audit trail: event stream records all hierarchy mutations
- Callers access entity via `$event->forum` and decorated response via `$event->response`
- Plugin-enriched data flows through the response decorator chain into the event
- Consistent pattern: every mutation = one event returned + dispatched

#### Bad
- Changed method signature convention: `createForum()` returns `ForumCreatedEvent` not `Forum`
- Higher cognitive load: tracing an operation requires understanding the event flow
- If a listener fails after dispatch, the mutation is committed but the side effect may not execute
- Debugging requires following the event chain through dispatcher → listeners
- Eventual consistency concerns if listeners have ordering dependencies

---

## ADR-006: No ACL Responsibility

### Status
Accepted

### Context
The legacy `display_forums()` function interleaves ACL checks (`$auth->acl_get('f_list', $forumId)`) with data fetching. The hierarchy service design must decide whether to include ACL filtering internally or delegate it to callers.

The research identified six permission touch points: `f_list`, `f_read`, `m_approve`, `a_forumadd`, `a_forumdel`, `a_fauth`. All are applied in the display/controller layer, not in the data layer. The `content_visibility` service that provides moderator-aware counts also calls auth internally and is consumer-side.

### Decision Drivers
- ACL filtering is viewer-specific — different users see different forum counts and trees
- The hierarchy service should return complete, unfiltered data for maximum reusability
- ACL is a cross-cutting concern — mixing it into hierarchy creates coupling
- Controllers and API layers are the natural place for permission enforcement
- The `phpbb\auth` service is a separate bounded context from `phpbb\hierarchy`

### Considered Options
1. **Include ACL in hierarchy** — `getTree($userId)` returns only forums the user can see
2. **Exclude ACL from hierarchy** — `getTree()` returns full tree; caller filters by permission
3. **Optional ACL decorator** — Provide a `AclFilteredHierarchyService` wrapper that callers can use

### Decision Outcome
Chosen option: **2 — Exclude ACL from hierarchy**, because ACL filtering is viewer-specific and belongs in the presentation/API layer. The hierarchy service provides complete data; callers compose hierarchy + auth:

```php
$tree = $hierarchy->getTree();
$visible = array_filter($tree->forums, fn(Forum $f) => $auth->acl_get('f_list', $f->id));
```

### Consequences

#### Good
- Clean separation of concerns: hierarchy owns structure, auth owns access control
- Service is reusable across contexts that don't need ACL (CLI tools, migrations, admin views)
- No dependency on `phpbb\auth` from `phpbb\hierarchy` — zero coupling
- Permission logic doesn't pollute tree operations

#### Bad
- Every consumer must remember to apply ACL filtering — no "safe by default"
- Performance: full tree fetched even when user can only see a subset (acceptable for typical < 200 forum trees)
- ACL cleanup on forum delete must be handled via event listener, not directly by hierarchy

---

## ADR-007: Dual-Path Tracking Preserved

### Status
Accepted

### Context
The legacy `markread()` function implements two tracking strategies: database-backed tracking for registered users (`phpbb_forums_track` table) and cookie-based tracking for anonymous users. The cookie path uses base36-encoded timestamps with `board_startdate` offsets, has a 10000-char overflow protection mechanism, and is load-bearing for anonymous user experience (showing "new posts" indicators without login).

The question is whether to preserve this dual-path approach or simplify to DB-only.

### Decision Drivers
- Anonymous users represent a significant portion of phpBB traffic
- Cookie tracking is the only way to show "new posts" indicators without requiring login
- The cookie format (base36 encoding, offset from board_startdate) is well-tested
- Removing cookie tracking would degrade anonymous UX with no benefit
- The two strategies have different read/write patterns and different storage concerns
- The strategy pattern allows future addition of new tracking backends (e.g., Redis, localStorage)

### Considered Options
1. **Dual-path preserved** — `DbTrackingStrategy` for registered users, `CookieTrackingStrategy` for anonymous, with `TrackingStrategyInterface` abstraction
2. **DB-only** — Remove cookie tracking; anonymous users see no "new post" indicators
3. **Cookie-only** — Use cookies for all users (registered and anonymous)
4. **Session-based for anonymous** — Use server-side sessions instead of cookies

### Decision Outcome
Chosen option: **1 — Dual-path preserved**, because both paths are load-bearing for their respective user populations. The `TrackingStrategyInterface` provides a clean abstraction, and `TrackingService` selects strategy based on `userId > 0`.

### Consequences

#### Good
- Anonymous user experience preserved — "new posts" indicators work without login
- Strategy pattern allows future backend additions (Redis, localStorage) without changing the service interface
- Cookie logic is encapsulated in `CookieTrackingStrategy` — isolated from DB path
- `TrackingService` delegates transparently based on user type

#### Bad
- Two code paths to test and maintain
- Cookie tracking inherits legacy complexity (base36 encoding, overflow pruning, board_startdate offsets)
- Cookie strategy requires access to HTTP request/response objects (injected dependency)
- Cookie size limits (10000 chars) impose practical limits on tracked forum count for anonymous users
