# Synthesis: `phpbb\hierarchy` Service Design

**Research Question**: How to design `phpbb\hierarchy` service for category/forum management based on legacy nested set code, with plugin architecture, excluding ACL?

**Research Type**: Technical (codebase extraction + service design)

**Date**: 2026-04-18

---

## 1. Executive Summary

Six comprehensive findings files were analyzed covering: nested set algorithms (870 LOC, 17 methods), database schema (4 tables, 50 columns), ACP CRUD operations (2245 LOC), display pipeline (700-line monolith), tracking/subscription system (dual-path DB+cookies), and phpBB's plugin/extension architecture (service collections, event dispatcher, tagged DI).

The key architectural insight is that **the legacy codebase has two parallel nested-set implementations that don't talk to each other**: a clean OOP `nestedset_forum` class that nobody uses, and raw inline SQL in `acp_forums.php` that does all the actual work. The new `phpbb\hierarchy` service should unify these behind a single service facade, extract tracking and subscriptions into dedicated services, and use phpBB's proven `service_collection` + event dispatcher patterns for extensibility.

---

## 2. Cross-Source Analysis

### 2.1 Validated Findings (Confirmed by Multiple Sources)

| Finding | Sources | Confidence |
|---------|---------|------------|
| Nested set uses `left_id`, `right_id`, `parent_id` with `forum_parents` cache | nested-set-core, forum-schema, forum-display, forum-crud | **HIGH** |
| Three forum types: `FORUM_CAT(0)`, `FORUM_POST(1)`, `FORUM_LINK(2)` | forum-schema, forum-crud, forum-display | **HIGH** |
| `forum_parents` column stores serialized PHP parent chain as lazy cache | nested-set-core, forum-display, forum-crud | **HIGH** |
| `display_forums()` orders by `left_id` for DFS traversal | forum-display, nested-set-core | **HIGH** |
| All mutating tree operations require DB-level advisory locking | nested-set-core, forum-crud | **HIGH** |
| No foreign key constraints — all referential integrity in application code | forum-schema | **HIGH** |
| Cache invalidation clears `forum_parents` on any structural change | nested-set-core, forum-crud | **HIGH** |
| Dual tracking paths: DB (`forums_track`) for registered users, cookies for anonymous | forum-tracking, forum-display | **HIGH** |
| `notify_status` deduplication prevents notification spam | forum-tracking | **HIGH** |
| Extension services use `service_collection` with compiler pass auto-discovery | plugin-patterns | **HIGH** |
| Events use `compact()/extract()` pattern with `trigger_event()` | plugin-patterns, forum-crud, forum-display | **HIGH** |

### 2.2 Contradictions Identified and Resolved

#### Contradiction 1: OOP Nested Set vs. Raw SQL in ACP

**Evidence**:
- `nestedset_forum` class exists with full insert/delete/move/reparent support (nested-set-core)
- `acp_forums.php` does its own raw SQL nested-set math for ALL CRUD operations (forum-crud)
- The ACP never calls `nestedset_forum` methods

**Resolution**: This is legacy technical debt, not a design conflict. The `nestedset` class was introduced in phpBB 3.1 but the ACP was never refactored to use it. The new service MUST use the OOP class (or its successor) as the single source of truth for tree operations.

#### Contradiction 2: Insert Semantics Mismatch

**Evidence**:
- `nestedset::insert()` always inserts at root level, then requires `change_parent()` for placement (nested-set-core)
- ACP inserts directly at the correct position under a parent using raw SQL (forum-crud)

**Resolution**: The ACP approach (insert-at-position) is more efficient (1 operation vs. 2). The new `ForumRepository::create()` should combine both steps atomically: insert + position in a single transaction. This is a design improvement over both legacy approaches.

#### Contradiction 3: `remove_subset` Argument Count

**Evidence**: `move_children` and `change_parent` call `remove_subset` with 4 arguments, but the method only accepts 3 (nested-set-core).

**Resolution**: PHP silently ignores extra arguments. This is a bug in the legacy code (unused 4th arg). The new implementation should have correct method signatures.

### 2.3 Confidence Assessment

| Category | Confidence | Rationale |
|----------|------------|-----------|
| Database schema | **HIGH** | Direct DDL extraction from dump |
| Nested set algorithms | **HIGH** | Full code reading of all 3 files |
| CRUD operations | **HIGH** | Full reading of 2245-line ACP module |
| Display pipeline | **HIGH** | Full reading of `display_forums()` |
| Tracking system | **HIGH** | All 4 modes and both paths analyzed |
| Plugin architecture | **HIGH** | Core DI patterns + extension system documented |
| Entity design (proposed) | **MEDIUM** | Design inference from schema + usage patterns |
| Migration strategy (proposed) | **MEDIUM** | Requires runtime validation |

---

## 3. Patterns and Themes

### 3.1 Architectural Patterns

| Pattern | Prevalence | Quality | Evidence |
|---------|-----------|---------|----------|
| **Nested Set Model** | Core (entire hierarchy) | Mature, well-tested | `nestedset.php` 870 LOC with locking, transactions |
| **Denormalized Counters** | `phpbb_forums` (6 counter cols) | Necessary for performance | Post/topic counts split by approval state |
| **Denormalized Last Post** | `phpbb_forums` (6 cols) | Performance optimization | Avoids JOIN with posts table on every page load |
| **Serialized Cache Column** | `forum_parents` | Fragile but effective | PHP `serialize()` in DB column, invalidated on structure change |
| **Dual-Path Strategy** | Read tracking | Mature | DB path for registered, cookie for anonymous |
| **Tagged Service Collection** | Plugin discovery | phpBB standard | Compiler pass auto-discovers tagged services |
| **Event Hook (compact/extract)** | All CRUD + display | phpBB standard but awkward | 13+ events in ACP, 7+ in display |
| **Advisory DB Locking** | Tree mutations | Critical for concurrency | `\phpbb\lock\db` wraps `GET_LOCK()` |

### 3.2 Design Patterns

| Pattern | Where | Assessment |
|---------|-------|------------|
| **Template Method** | `nestedset` (abstract) → `nestedset_forum` | Clean, appropriate |
| **Facade** (proposed) | `HierarchyService` wrapping tree + tracking + subscriptions | Needed to unify scattered logic |
| **Repository** (proposed) | `ForumRepository` for CRUD | Extracts DB access from ACP monolith |
| **Strategy** (proposed) | `TreeOperationInterface` for nested set variants | Future extensibility |
| **Observer** | Event dispatcher | Already proven in phpBB |
| **Service Locator** | `service_collection` lazy loading | phpBB pattern, acceptable for plugin discovery |

### 3.3 Anti-Patterns Found

| Anti-Pattern | Where | Impact |
|-------------|-------|--------|
| **God Function** | `display_forums()` — 700+ LOC mixing SQL, ACL, tracking, template | Cannot test, cannot extend, cannot reuse |
| **Parallel Implementations** | ACP raw SQL vs. `nestedset_forum` class | Maintenance burden, divergence risk |
| **Serialized PHP in DB** | `forum_parents` column | Not queryable, fragile, PHP-version-dependent |
| **Missing Domain Objects** | Raw arrays everywhere | No type safety, no validation layer |
| **Inline Validation** | `update_forum_data()` mixes validation + persistence | Not reusable, not testable |

---

## 4. Key Insights

### Insight 1: The Tree Service Is the Core — Everything Else Wraps It

**Supporting evidence**: Every operation (CRUD, display, tracking, subscriptions) depends on the tree structure. The `left_id`/`right_id` ordering drives display, the `parent_id` drives navigation, the tree structure drives tracking rollup.

**Implication**: `TreeService` should be a standalone, thoroughly tested service. Other services compose it. Never bypass it.

### Insight 2: Tracking and Subscriptions Are Separate Concerns

**Supporting evidence**: `forums_track` (read status) and `forums_watch` (email notifications) have completely different schemas, different update patterns, different UI flows. They share only the `forum_id` foreign key.

**Implication**: `TrackingService` and `SubscriptionService` should be separate classes, not merged into one "user interaction" service.

### Insight 3: Cookie Tracking MUST Be Preserved for Anonymous Users

**Supporting evidence**: `markread()` has complete dual-path logic. The cookie path uses base36 encoding with board_startdate offsets. This is load-bearing for anonymous user experience.

**Implication**: `TrackingService` needs a strategy pattern: `DbTrackingStrategy` for registered users, `CookieTrackingStrategy` for anonymous. The interface must abstract this.

### Insight 4: Content Visibility Is an Auth Concern, Not Hierarchy

**Supporting evidence**: `display_forums()` calls `$phpbb_content_visibility->get_count()` which internally checks `m_approve` permission. Topic/post counts vary per viewer.

**Implication**: `HierarchyService` returns raw counts. The display layer (or a decorator) applies visibility rules by calling `phpbb\auth`. This aligns with the constraint that hierarchy excludes ACL.

### Insight 5: The `service_collection` Pattern Is the Right Plugin Architecture

**Supporting evidence**: Auth providers, notification types, OAuth services all use this pattern. It's battle-tested, understood by phpBB developers, and IDE-friendly.

**Implication**: Use `hierarchy.plugin` tag for plugin discovery, with a `HierarchyPluginInterface` contract. Plugins register custom forum types, attributes, or behaviors.

### Insight 6: Forum Type Determines Available Behavior

**Supporting evidence**: `FORUM_CAT` has no posts. `FORUM_LINK` has no content. `FORUM_POST` has full functionality. Type changes require content migration (move or delete). ACP has explicit type-change matrices.

**Implication**: Forum type should be an enum, with type-specific behavior delegated via the plugin system. The `HierarchyPluginInterface` can define `supportsForumType()`.

---

## 5. Relationships and Dependencies

### 5.1 Component Dependency Map

```
phpbb\hierarchy\HierarchyService  ←  Facade / Entry Point
   ├── phpbb\hierarchy\repository\ForumRepository  ←  CRUD + DB access
   │      └── PDO (direct, no legacy $db driver)
   ├── phpbb\hierarchy\tree\TreeService  ←  Nested set operations
   │      └── PDO
   ├── phpbb\hierarchy\tracking\TrackingService  ←  Read status
   │      ├── PDO (registered users)
   │      └── Cookie adapter (anonymous users)
   ├── phpbb\hierarchy\subscription\SubscriptionService  ←  Watch/notify
   │      └── PDO
   ├── phpbb\hierarchy\plugin\PluginCollection  ←  service_collection
   │      └── phpbb\hierarchy\plugin\HierarchyPluginInterface[]
   └── phpbb\event\dispatcher_interface  ←  Event hooks
```

### 5.2 Integration with External Services

| External Service | Integration Point | Direction | Notes |
|-----------------|-------------------|-----------|-------|
| `phpbb\auth` | Permission checks (`f_list`, `f_read`, `m_approve`) | Called BY display layer, NOT by hierarchy | Hierarchy provides data, auth filters it |
| `phpbb\user` | `user_id` for tracking/subscriptions, `user_lastmark` baseline | Read user context | No write to users table except `user_lastmark` on "mark all read" |
| `phpbb\notification` | `forums_watch` feeds notification recipients | Hierarchy provides subscriber list | Notification system owns delivery |
| `phpbb\cache` | `$cache->destroy('sql', FORUMS_TABLE)` on mutations | Called after mutations | New service should fire events; cache listener handles invalidation |
| `phpbb\content_visibility` | Moderator-aware topic/post counts | Called BY display layer | NOT part of hierarchy — it's an auth concern |

### 5.3 Data Flow: Create Forum

```
API/ACP Controller
  → HierarchyService::createForum(CreateForumDTO)
    → dispatch('hierarchy.forum.pre_create', $dto)
    → ForumRepository::insert($data)
      → INSERT INTO phpbb_forums
    → TreeService::positionNode($forumId, $parentId)
      → UPDATE left_id/right_id (with advisory lock)
    → dispatch('hierarchy.forum.post_create', $forum)
    → return Forum entity
```

### 5.4 Data Flow: Display Forum Tree

```
Controller/index.php
  → HierarchyService::getTree(?int $rootId)
    → TreeService::getSubtree($rootId) — returns ordered nodes
    → ForumRepository::hydrateNodes($nodeIds) — full forum data
    → return ForumNode[] (ordered, with depth)
  → Auth layer filters by f_list permission
  → TrackingService::getTrackingInfo($userId, $forumIds) — read status
  → Display layer merges + renders
```

---

## 6. Gaps and Uncertainties

### 6.1 Information Gaps

| Gap | Impact | Mitigation |
|-----|--------|------------|
| **Exact SQL performance with large trees** | Unknown query cost for trees with 500+ forums | Benchmark during implementation; the nested set index `(left_id, right_id)` exists |
| **Cookie tracking size limits** | `markread()` has 10000 char overflow protection; unclear how this interacts with many forums | Preserve existing logic; consider per-forum cookie entries |
| **`forum_options` bitmask usage** | Default is 0, not exposed in ACP UI; unclear if any extension uses it | Keep the column; expose via plugin interface |
| **Concurrent tree mutation** | Advisory locks work for single-server; unclear behavior with connection pooling | PDO advisory locks via `GET_LOCK()`; document single-writer assumption |

### 6.2 Unresolved Design Questions

| Question | Options | Recommendation |
|----------|---------|----------------|
| Unified `Node` entity vs. separate `Forum`, `Category`, `ForumLink`? | A: Single entity with type discriminator. B: Base + subtypes. | **A**: Single `Forum` entity with `ForumType` enum. All share the same table and tree. Type affects behavior, not structure. |
| Store `forum_parents` as JSON or keep serialized PHP? | JSON is queryable, PHP serialize is legacy-compatible. | **JSON**: New service on PHP 8.2 should use JSON. Write a migration to convert existing data. |
| Should `HierarchyService` own the "mark forum read" action? | A: Yes (it's forum-centric). B: No (it's user-centric). | **A**: `TrackingService` lives within hierarchy namespace. The action modifies `forums_track`. |
| How to handle `user_lastmark` update (global mark-all-read)? | A: In TrackingService. B: In a separate UserService. | **B**: `TrackingService` fires an event; a listener on the user side updates `user_lastmark`. Hierarchy doesn't own the users table. |

---

## 7. Synthesis by Framework: Technical Research

### 7.1 Component Analysis

**What exists**:
- `nestedset_forum` — clean OOP class, unused by ACP (17 public methods)
- `acp_forums.php` — monolithic ACP module (2245 LOC, does everything)
- `display_forums()` — monolithic display function (700 LOC)
- `markread()` — multi-mode tracking function (4 modes, dual-path)
- `watch_topic_or_forum()` — subscribe/unsubscribe handler

**How it's structured**: Procedural spaghetti. Functions call functions, global state, raw arrays. No domain model. `$phpbb_root_path`, `global $db`, `global $user` everywhere.

**How it works**: See Data Flow diagrams in section 5.3/5.4.

**How it integrates**: Via global variables, function calls, and the event dispatcher. No formal interfaces between subsystems.

### 7.2 Pattern Analysis

- **Nested set**: Algorithmically sound, well-implemented in `nestedset.php`. The problem is adoption, not design.
- **Event system**: Good for extension points. The `compact()/extract()` API is awkward but functional.
- **Service collections**: Excellent plugin discovery. Lazy loading prevents performance penalty.
- **Tracking**: Two-path design is necessary and well-executed. The cookie format (base36 offsets) is clever but cryptic.

### 7.3 Quality Assessment

| Aspect | Strength | Weakness |
|--------|----------|----------|
| **Algorithms** | Nested set is correct and battle-tested | Two parallel implementations cause confusion |
| **Extensibility** | 13+ events in ACP, 7+ in display | Events are too fine-grained in ACP, too coarse in display |
| **Testability** | — | Zero testability: globals, no DI, no domain objects |
| **Performance** | Single-query tree fetch, denormalized counters | `regenerate_left_right_ids` is O(3n) queries; `forum_parents` cache is fragile |
| **Security** | CSRF protection, ACL checks | No prepared statements in ACP SQL (uses `sql_escape` not parameterized) |

---

## 8. Conclusions

### Primary Conclusions

1. **The nested set algorithm is sound** — keep the mathematical model, discard the parallel implementation in ACP. (HIGH confidence)

2. **Five services are needed** — `HierarchyService` (facade), `ForumRepository` (CRUD), `TreeService` (nested set), `TrackingService` (read status), `SubscriptionService` (watch). (HIGH confidence)

3. **Plugin architecture should use `service_collection` pattern** — proven in phpBB, supports lazy loading, tagged discovery, and ordering. (HIGH confidence)

4. **ACL must be excluded from hierarchy** — The display layer applies `f_list`/`f_read` filtering. `TrackingService` doesn't check permissions. `content_visibility` is called externally. (HIGH confidence)

5. **A single `Forum` entity with `ForumType` enum** covers all three types (CAT, POST, LINK) without subclassing. Forum type affects available operations, not data structure. (MEDIUM-HIGH confidence)

### Secondary Conclusions

6. **Replace serialized PHP parent cache with JSON** — More portable, queryable, and safe.

7. **Cookie tracking strategy must be preserved** — Anonymous user experience depends on it.

8. **Events should be coarser than legacy** — One pre/post event pair per CRUD operation, not 13 micro-events.

9. **Direct PDO replaces legacy `$db` driver** — Parameterized queries, proper prepared statements, no `sql_escape()`.

### Recommendations

1. Start with `TreeService` + `ForumRepository` — they're the foundation everything else depends on.
2. Write the `HierarchyPluginInterface` early — it shapes all extension points.
3. Build `TrackingService` and `SubscriptionService` as independent modules that compose with hierarchy.
4. Define events upfront — they're the public API for extensions.
5. Defer migration of `display_forums()` — the display layer is the consumer, not the service.
