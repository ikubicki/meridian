# Gap Analysis: `phpbb\hierarchy` Service

## Summary
- **Risk Level**: Medium
- **Estimated Effort**: High
- **Detected Characteristics**: creates_new_entities, modifies_existing_code, involves_data_operations

---

## Task Characteristics
- Has reproducible defect: **no** — no failing tests; `ForumsController` returns mock data intentionally
- Modifies existing code: **yes** — `ForumsController` and `services.yaml` must be updated
- Creates new entities: **yes** — entire `phpbb\hierarchy\` namespace is greenfield
- Involves data operations: **yes** — CRUD on `phpbb_forums`, `phpbb_forums_track`, `phpbb_forums_watch`
- UI heavy: **no** — purely backend + REST API; React frontend is out of scope

---

## 1. Current vs. Desired State

### Current State

| Item | Status |
|------|--------|
| `src/phpbb/hierarchy/` directory | **Does not exist** |
| `phpbb\hierarchy\entity\Forum` | Missing |
| `phpbb\hierarchy\entity\ForumType` enum | Missing |
| `phpbb\hierarchy\entity\ForumStatus` enum | Missing |
| `phpbb\hierarchy\entity\ForumStats` value object | Missing |
| `phpbb\hierarchy\entity\ForumLastPost` value object | Missing |
| `phpbb\hierarchy\entity\ForumPruneSettings` value object | Missing |
| `phpbb\hierarchy\entity\CreateForumData` DTO | Missing |
| `phpbb\hierarchy\entity\UpdateForumData` DTO | Missing |
| `phpbb\hierarchy\Contract\ForumRepositoryInterface` | Missing |
| `phpbb\hierarchy\Contract\HierarchyServiceInterface` | Missing |
| `phpbb\hierarchy\Contract\TreeServiceInterface` | Missing |
| `phpbb\hierarchy\Contract\TrackingServiceInterface` | Missing |
| `phpbb\hierarchy\Contract\SubscriptionServiceInterface` | Missing |
| `phpbb\hierarchy\Repository\DbalForumRepository` | Missing |
| `phpbb\hierarchy\Tree\TreeService` | Missing |
| `phpbb\hierarchy\Service\HierarchyService` | Missing |
| `phpbb\hierarchy\Tracking\TrackingService` | Missing |
| `phpbb\hierarchy\Subscription\SubscriptionService` | Missing |
| `phpbb\hierarchy\Plugin\ForumTypeRegistry` | Missing |
| `phpbb\hierarchy\Plugin\ForumTypeBehaviorInterface` | Missing |
| `phpbb\hierarchy\Event\ForumCreatedEvent` (+ siblings) | Missing |
| DI registration in `services.yaml` | Missing (no hierarchy block) |
| `ForumsController` wired to `HierarchyService` | Pending — uses hardcoded `MOCK_FORUMS` |
| Unit tests under `tests/phpbb/hierarchy/` | Missing |
| Integration tests under `tests/phpbb/Integration/` | Missing |

### Desired State

Complete `phpbb\hierarchy` module with:
- Domain layer: `Forum` entity + enums + value objects + DTOs
- Repository layer: `DbalForumRepository` implementing `ForumRepositoryInterface`
- Tree layer: `TreeService` (ported nested set from legacy `nestedset.php`) implementing `TreeServiceInterface`
- Service layer: `HierarchyService` facade composing repository + tree
- Tracking layer: `TrackingService` (dual DB/cookie paths) implementing `TrackingServiceInterface`
- Subscription layer: `SubscriptionService` implementing `SubscriptionServiceInterface`
- Plugin layer: `ForumTypeRegistry` + `ForumTypeBehaviorInterface`
- Event model: domain events per CRUD boundary (`ForumCreatedEvent`, `ForumMovedEvent`, `ForumDeletedEvent`)
- DI registration for all of the above in `services.yaml`
- `ForumsController` injecting `HierarchyServiceInterface`, replacing mock with real data
- Unit tests for all services/entities
- Integration tests for `DbalForumRepository` extending `IntegrationTestCase`

---

## 2. Identified Gaps

### 2.1 Missing Files (Greenfield)

All files below require creation from scratch under `src/phpbb/hierarchy/`:

**Domain entities and value objects** (`Entity/`):
- `Forum.php` — main entity (readonly constructor-promoted, ~30 properties)
- `ForumType.php` — backed enum (`Category=0`, `Forum=1`, `Link=2`)
- `ForumStatus.php` — backed enum (`Unlocked=0`, `Locked=1`)
- `ForumStats.php` — readonly value object (6 counter fields)
- `ForumLastPost.php` — readonly value object (6 last-post fields)
- `ForumPruneSettings.php` — readonly value object (9 prune fields)
- `CreateForumData.php` — DTO for create operations
- `UpdateForumData.php` — DTO for update operations (nullable fields)

**Contracts** (`Contract/`):
- `ForumRepositoryInterface.php` — CRUD contract
- `HierarchyServiceInterface.php` — facade contract (`listForums`, `getForum`, `createForum`, `updateForum`, `deleteForum`, `moveForum`)
- `TreeServiceInterface.php` — tree operations contract (`getSubtree`, `positionNode`, `removeNode`, `moveNode`)
- `TrackingServiceInterface.php`
- `SubscriptionServiceInterface.php`

**Repository** (`Repository/`):
- `DbalForumRepository.php` — DBAL 4 implementation: `private const TABLE = 'phpbb_forums'`, named params without `:` prefix, `RepositoryException` wrapping, full hydration of `Forum` entity from row

**Tree** (`Tree/`):
- `TreeService.php` — ported nested set: insert-at-position (atomic insert+position), `getSubtree`, `removeNode`, `moveNode`, advisory DB locking wrapper

**Service** (`Service/`):
- `HierarchyService.php` — facade: composes `ForumRepository` + `TreeService`, dispatches domain events

**Tracking** (`Tracking/`):
- `TrackingService.php` — dual-path (DB for registered users via `phpbb_forums_track`, cookie for anonymous)

**Subscription** (`Subscription/`):
- `SubscriptionService.php` — `phpbb_forums_watch` CRUD

**Plugin** (`Plugin/`):
- `ForumTypeRegistry.php` — maps `ForumType` → `ForumTypeBehaviorInterface`
- `ForumTypeBehaviorInterface.php` — contract: `supportsForumType()`, `canHaveContent()`, `canHaveChildren()`, `getEditableFields()`
- Built-in behavior classes: `CategoryBehavior.php`, `ForumBehavior.php`, `LinkBehavior.php`

**Events** (`Event/`):
- `ForumCreatedEvent.php`
- `ForumUpdatedEvent.php`
- `ForumDeletedEvent.php`
- `ForumMovedEvent.php`

### 2.2 Existing Files Requiring Changes

**`src/phpbb/api/Controller/ForumsController.php`**:
- Remove `private const MOCK_FORUMS` array
- Inject `HierarchyServiceInterface` via constructor
- Replace `index()` body with `$this->hierarchy->listForums()`
- Replace `show()` body with `$this->hierarchy->getForum($forumId)`
- Add proper 404 exception handling (not array-based error response)

**`src/phpbb/config/services.yaml`**:
- Add full hierarchy block: `DbalForumRepository`, `TreeService`, `HierarchyService`, `TrackingService`, `SubscriptionService`, `ForumTypeRegistry`
- Register interface aliases for each implementation
- Register built-in `ForumTypeBehavior` instances with `ForumTypeRegistry`

### 2.3 Missing Tests

**Unit tests** (`tests/phpbb/hierarchy/`):
- `Entity/ForumTest.php` — `isLeaf()`, `descendantCount()`, type helpers
- `Entity/ForumTypeTest.php` — enum backing value correctness
- `Service/HierarchyServiceTest.php` — mock repository + tree, verify delegation + event dispatch
- `Tree/TreeServiceTest.php` — nested set math: insert, remove, move, subtree queries
- `Tracking/TrackingServiceTest.php` — DB path + cookie path separation
- `Subscription/SubscriptionServiceTest.php`
- `Plugin/ForumTypeRegistryTest.php` — registration + lookup

**Integration tests** (`tests/phpbb/Integration/` or `tests/phpbb/hierarchy/Repository/`):
- `DbalForumRepositoryTest.php` — extends `IntegrationTestCase`, SQLite in-memory schema with `phpbb_forums` DDL, full CRUD: `findById`, `findAll`, `insert`, `update`, `delete`

---

## 3. User Journey Impact Assessment

| Dimension | Current | After | Assessment |
|-----------|---------|-------|------------|
| API `/forums` (GET) | Returns 2 hardcoded entries | Returns live data from `phpbb_forums` | ✅ Improvement |
| API `/forums/{id}` (GET) | Mock linear search | Real DB lookup with 404 on miss | ✅ Improvement |
| API write operations (POST/PUT/DELETE) | Non-existent | New routes via `HierarchyService` | N/A — Phase 1 may be read-only |
| Discoverability (dev) | 4/10 — TODOs hidden in controller | 9/10 — standard REST pattern | +5 |

---

## 4. Data Lifecycle Analysis

### Entity: Forum (`phpbb_forums`)

| Operation | Backend | UI Component | User Access | Status |
|-----------|---------|--------------|-------------|--------|
| CREATE | Not yet (`DbalForumRepository::insert` missing) | No ACP endpoint yet | No route | ❌ |
| READ | Not yet (mock only) | `GET /forums`, `GET /forums/{id}` exist (mock) | API accessible | ⚠️ Partial |
| UPDATE | Not yet | No PUT endpoint | No route | ❌ |
| DELETE | Not yet | No DELETE endpoint | No route | ❌ |

**Completeness**: 10% (read path exists as mock only; no real DB access at any layer)  
**Orphaned Operations**: READ is partially orphaned — UI/API exists but no backend implementation  

### Entity: Forum Track (`phpbb_forums_track`)

| Operation | Backend | UI | Access | Status |
|-----------|---------|-----|--------|--------|
| CREATE/UPDATE (mark read) | `TrackingService` missing | No endpoint | No route | ❌ |
| READ (unread check) | `TrackingService` missing | No endpoint | No route | ❌ |

**Completeness**: 0%

### Entity: Forum Watch (`phpbb_forums_watch`)

| Operation | Backend | UI | Access | Status |
|-----------|---------|-----|--------|--------|
| CREATE (subscribe) | `SubscriptionService` missing | No endpoint | No route | ❌ |
| READ (subscriber list) | `SubscriptionService` missing | No endpoint | No route | ❌ |
| DELETE (unsubscribe) | `SubscriptionService` missing | No endpoint | No route | ❌ |

**Completeness**: 0%

---

## 5. Issues Requiring Decisions

### Critical (Must Decide Before Proceeding)

**D1 — Phase 1 scope**
- **Issue**: The full desired state requires 5 services + plugin layer + events + 3 table integrations. This is high effort. What is the minimum viable Phase 1?
- **Options**:
  - A: Core only — `Forum` entity + `DbalForumRepository` (read-only: `findById`, `findAll`) + `HierarchyService::listForums()` + `HierarchyService::getForum()` + wire `ForumsController`. No tree mutations, no tracking, no subscriptions. Targets replacing the mock immediately.
  - B: Core + mutations — same as A plus `TreeService` and CRUD (`createForum`, `updateForum`, `deleteForum`, `moveForum`).
  - C: Full — all 5 services in one phase.
- **Recommendation**: **A** — delivers the only currently failing user-observable thing (mock data) with minimal complexity. Tree/tracking/subscriptions are genuinely independent and can be Phase 2.
- **Impact**: Defines which files get created in this task. The entity model and contracts must still be designed for the full surface area even if Phase 1 only implements a subset.

**D2 — Advisory locking for tree mutations**
- **Issue**: `TreeService` requires a locking strategy for concurrent mutations (O(n) row shifts). Three options have been evaluated.
- **Options**:
  - A: MySQL `GET_LOCK()` / `RELEASE_LOCK()` via `executeQuery` — matches legacy `phpbb\lock\db` exactly, single-server, no extra dependencies
  - B: Symfony Lock component (`lock.factory`) with database store — portable, framework-native, supports distributed setups
  - C: Rely on DB transaction isolation alone — simplest, no explicit lock; risks concurrent corruption on high-concurrency admin panels
- **Recommendation**: **A** — tree mutations are admin-only (low frequency), MySQL `GET_LOCK` is battle-tested in phpBB, and Symfony Lock adds a compile-time dependency for a marginal benefit. If Phase 1 is read-only, this is deferred to Phase 2.
- **Impact**: Determines `TreeService` constructor signature and DI wiring.

**D3 — Plugin architecture contradiction**
- **Issue**: The research `synthesis.md` recommends `service_collection` + tagged DI (ADR conclusion: "HIGH confidence"). The `decision-log.md` ADR-004 explicitly rejects this in favour of Symfony domain events + request/response decorator pipelines. These two outputs contradict each other.
- **Options**:
  - A: Follow ADR-004 — domain events + `ForumTypeRegistry` with `RegisterForumTypesEvent`; no tagged DI for plugin discovery
  - B: Follow synthesis recommendation — `service_collection` pattern with compiler pass; matches all other phpBB4 modules
  - C: Hybrid — `ForumTypeRegistry` uses constructor injection (no tagged DI) for built-in types; external extension support deferred
- **Recommendation**: **C** for Phase 1 — `ForumTypeRegistry` injected with the 3 built-in behavior instances. Avoids the contradiction entirely and unblocks implementation. Plugin extensibility can be resolved architecturally in a separate ADR before Phase 2.
- **Impact**: Determines whether a compiler pass / DI extension is needed and whether `ForumTypeRegistry` is part of Phase 1 at all (it may not be needed if Phase 1 is read-only).

### Important (Should Decide)

**D4 — `forum_parents` cache format**
- **Issue**: The column currently stores PHP-serialized data (`a:2:{i:1;a:2:{s:10:"forum_name";s:...`). The research recommends migrating to JSON. This migration touches production data.
- **Options**:
  - A: Keep PHP `serialize` format — zero migration risk, match legacy exactly, read with `unserialize()`
  - B: Write JSON, provide a one-time migration command — cleaner long-term, queryable
  - C: Ignore the column entirely for Phase 1 (don't return `parents` in the API response) — defer the decision
- **Recommendation**: **C** for Phase 1 — the `parents` field is not required for `GET /forums` or `GET /forums/{id}` list responses. Defer format choice until the breadcrumb/navigation feature is needed.
- **Impact**: Determines whether `ForumPruneSettings`, `ForumLastPost`, and `parents` are populated in `Forum::$parents` in Phase 1 hydration. Can use an empty array initially.

**D5 — `HierarchyService` return type contract**
- **Issue**: ADR-005 states "service methods return domain event objects as primary output". This deviates from the established pattern in this codebase (`AuthenticationService`, `UserSearchService` etc.) which return entities/DTOs directly.
- **Options**:
  - A: Return domain event objects (per ADR-005) — caller gets an event wrapping the entity
  - B: Return entities/DTOs directly (matches existing codebase convention) — simpler, consistent
  - C: Return both — entity as primary return, event dispatched as side effect via the injected `EventDispatcherInterface`
- **Recommendation**: **C** — aligns with Symfony conventions (dispatch events as side effects, return entity to caller). The controller receives a `Forum` or `Forum[]` and doesn't need to unwrap an event object. Side-effect events still flow to listeners.
- **Impact**: Determines method signatures on `HierarchyServiceInterface` and controller code.

---

## 6. Scope Recommendations

### Phase 1 (This Task — Recommended)

| Component | Included | Notes |
|-----------|----------|-------|
| `Forum` entity + enums + value objects | ✅ | Full entity needed to type the return value correctly |
| `CreateForumData` / `UpdateForumData` DTOs | Partial — read DTOs only | Skip `UpdateForumData` if read-only |
| `ForumRepositoryInterface` + `DbalForumRepository` | ✅ | `findById`, `findAll` (ORDER BY left_id) |
| `HierarchyServiceInterface` + `HierarchyService` | ✅ | `listForums()`, `getForum(int $id)` only |
| `ForumsController` wired to service | ✅ | Removes mock; the explicit goal |
| DI registration | ✅ | Repository + service block in `services.yaml` |
| Unit tests (entity + service) | ✅ | Required by project standards |
| Integration test (`DbalForumRepositoryTest`) | ✅ | Required by project standards |
| `TreeService` | ❌ | Defer — no tree mutations in Phase 1 |
| `TrackingService` | ❌ | Defer — separate concern, no user context in Phase 1 |
| `SubscriptionService` | ❌ | Defer — no notification wiring yet |
| `ForumTypeRegistry` / `ForumTypeBehaviorInterface` | ❌ | Defer — not needed for read-only facade |
| Domain events | ❌ | Defer — no mutations = no events needed |

### Phase 2 (Deferred)

- `TreeService` with nested set port + advisory locking
- Full CRUD in `HierarchyService` and `ForumRepository`
- `TrackingService` (DB + cookie strategy)
- `SubscriptionService`
- `ForumTypeRegistry` + behavior delegates
- Domain events (`ForumCreatedEvent` etc.)
- ACP REST endpoints for forum management

---

## 7. Recommendations

1. **Resolve D1 (phase scope) and D3 (plugin arch contradiction) before writing specs** — these gate which files get created and what interfaces look like.
2. **Use D5 option C for service return types** — matches existing codebase conventions, avoids a confusing event-unwrapping API.
3. **Start `DbalForumRepository::findAll()` with `ORDER BY left_id`** — gives correct DFS traversal order for free; controllers/API return pre-ordered trees without sorting.
4. **Design `Forum` entity constructor with all 30+ fields even in Phase 1** — changing the entity signature later will cascade across tests. Use `null` or empty defaults for deferred fields (`$parents = []`).
5. **Mirror the `auth` module structure exactly**: `Contract/`, `Entity/`, `Repository/`, `Service/` directories — maintains project consistency and IDE discoverability.
6. **SQLite DDL for `DbalForumRepositoryTest`** must include at minimum: `forum_id`, `forum_name`, `forum_desc`, `left_id`, `right_id`, `parent_id`, `forum_type`, `forum_status` — the rest can use defaults/NULLs.

---

## 8. Risk Assessment

- **Risk Level**: **Medium**
- **Complexity Risk**: High — `Forum` entity has 30+ fields; hydration is verbose but mechanical. Nested set math (Phase 2) carries correctness risk.
- **Integration Risk**: Low for Phase 1 (read-only, replaces mock). Medium for Phase 2 (tree mutations require locking + transaction handling).
- **Regression Risk**: Low — `ForumsController` changes are additive (replaces mock, same HTTP interface). `services.yaml` additions don't touch existing registrations.
- **Scope Creep Risk**: Medium — the research artifacts are comprehensive and may tempt over-engineering Phase 1. Strict scope boundary (read-only + wire controller) mitigates this.

**Rationale for Medium overall**: The domain is well-researched and ADRs are in place for the core decisions. The main risk vectors are (a) the contradiction between synthesis.md and ADR-004 on plugin architecture (D3) and (b) the large entity surface area. Both are manageable with the decisions above.

---

## 9. Phase Summary

The `phpbb\hierarchy` namespace is entirely absent (0 PHP files under `src/phpbb/hierarchy/`); the only touchpoint is `ForumsController` returning hardcoded mock data with a TODO comment. Phase 1 can deliver meaningful value by implementing read-only `DbalForumRepository` + `HierarchyService` + wiring the controller, deferring `TreeService`, `TrackingService`, `SubscriptionService`, and the plugin layer to Phase 2.
