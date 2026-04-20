# Decision Log: phpbb\threads Service

## Summary

| ADR | Decision | Status |
|-----|----------|--------|
| ADR-001 | s9e XML default + encoding_engine column — preserve existing format, format-aware pipeline | Amended |
| ADR-002 | Hybrid content pipeline — middleware chain + events | Accepted |
| ADR-003 | Lean core + plugin extensions — Polls, ReadTracking, Subscriptions, Attachments as plugins | Accepted |
| ADR-004 | Event-driven counter propagation — topic-level sync, forum counters via events to Hierarchy, reconciliation for safety | Amended |
| ADR-005 | Dedicated VisibilityService — centralized 4-state machine | Accepted |
| ADR-006 | Auth-unaware service — API middleware enforces ACL externally | Accepted |
| ADR-007 | DraftService in core threads namespace | Accepted |

---

## ADR-001: s9e XML Default + Encoding Engine

### Status
Amended (2026-04-20) — supersedes original "Raw text only storage" decision. See cross-cutting-decisions-plan.md D7.

### Context
The legacy system stores s9e XML — an intermediate parsed format tightly coupled to the s9e PHP library. The new design mandates that ALL content formatting (BBCode, markdown, smilies, attachments, magic URLs) be plugins, not core concerns. The core must store content in a format that preserves the original user input for editing, doesn't assume any specific formatting plugin exists, and supports migration from legacy s9e XML.

Four alternatives were evaluated: raw text only, raw text + cached HTML (dual storage), raw text + plugin-agnostic AST, and raw text + plugin metadata bag (JSON).

**Amendment rationale**: The cross-cutting assessment (D7) determined that bulk migration of existing s9e XML posts is unnecessary and risky. Instead, an `encoding_engine` column enables format-aware rendering without data migration.

### Decision Drivers
- Simplicity of storage model — minimize columns and schema complexity
- Plugin changes should take effect immediately on existing content
- **No bulk data migration** — existing s9e XML posts must remain valid without conversion
- Maximum backward compatibility with phpBB3 schema (per backend STANDARDS.md)
- Per-user rendering preferences (viewSmilies, viewCensored) require render-time variation anyway

### Considered Options
1. **Raw text only** — single `post_text` column, full parse+render on every display
2. **Raw text + cached HTML** — dual columns, pre-rendered at save time
3. **Raw text + plugin-agnostic AST** — structured JSON document tree
4. **Raw text + plugin metadata bag** — raw text + JSON metadata per plugin
5. **s9e XML default + encoding_engine** — preserve existing format, add column for future format support

### Decision Outcome
Chosen option: **s9e XML default + encoding_engine** (option 5, amended from original option 1). The existing `post_text` column is preserved unchanged. A new `encoding_engine VARCHAR(16) NOT NULL DEFAULT 's9e'` column is added to `phpbb_posts`. The `ContentPipeline` uses `encoding_engine` to dispatch to the correct renderer:

```php
match ($post->encodingEngine) {
    's9e' => $this->s9eRenderer->render($post->postText),
    'raw' => $this->bbcodeParser->parse($post->postText),
    // Future formats added here
};
```

### Consequences

#### Good
- Zero migration — all existing s9e XML posts work immediately
- s9e rendering is battle-tested; no new parser bugs
- Future format support (raw, Markdown) is per-post via `encoding_engine`
- Schema change is additive (`ADD COLUMN`) — backward compatible
- Plugin configuration changes still take effect on re-render

#### Bad
- s9e library remains a runtime dependency (was already in vendor)
- Two+ rendering paths in ContentPipeline increase complexity
- Per-user rendering preferences still require render-time variation (unchanged from original)
- A caching layer (`cache.threads` via `TagAwareCacheInterface`) will be essential for production traffic

---

## ADR-002: Hybrid Content Pipeline (Middleware + Events)

### Status
Accepted

### Context
All formatting is plugin-based. The content pipeline transforms raw text → HTML at display time. Plugins must execute in a defined order (BBCode before smilies, smilies before autolink), some plugins operate at boundaries (validation, censoring), and the system needs extensibility hooks for future plugins. Four architectures were evaluated: event-only, middleware chain, decorator stack, and hybrid.

### Decision Drivers
- Formatting plugins need explicit, debuggable ordering
- Cross-cutting concerns (validation, censoring, highlighting) need boundary hooks
- Consistency with hierarchy service's dual pattern (decorators + events)
- Plugin authors need clear guidance on which mechanism to use

### Considered Options
1. **Event-based only** — all plugins subscribe to parse/render events
2. **Middleware chain only** — `ContentPluginInterface` with ordered `parse()`/`render()`
3. **Decorator stack** — nested `ContentProcessor` wrappers
4. **Hybrid** — middleware for formatting + events for cross-cutting hooks

### Decision Outcome
Chosen option: **Hybrid (middleware + events)**, because the content pipeline has two distinct extension needs: (1) ordered formatting transformations (BBCode, markdown, smilies) and (2) cross-cutting hooks (validation, censoring, caching). Middleware gives explicit ordering for the former; events give clean observation points for the latter. This mirrors the dual-mechanism pattern in `phpbb\hierarchy` (decorator pipeline + domain events).

### Consequences

#### Good
- Explicit plugin ordering via `getPriority()` — debuggable and predictable
- Clean separation: transformation plugins use `ContentPluginInterface`; cross-cutting use events
- Events allow observation without modifying the pipeline (logging, metrics)

#### Bad
- Two extension mechanisms to learn and document
- Plugin authors must choose the right mechanism (clear docs needed)
- Slightly more infrastructure code than a pure approach

---

## ADR-003: Lean Core with Plugin Extensions

### Status
Accepted

### Context
The legacy threads subsystem includes topics, posts, polls, drafts, read tracking, subscriptions, bookmarks, and attachments. The research identified 15+ potential components. The question is which features belong in the core `phpbb\threads` package and which should be external plugins.

### Decision Drivers
- Core should be minimal — only features that cannot function without tight coupling to topic/post lifecycle
- Polls, read tracking, subscriptions, and attachments have independent lifecycles
- Plugin architecture must be demonstrated as viable — these features serve as proof-of-concept
- Smaller core = easier to test, review, and maintain

### Considered Options
1. **Monolithic** — all features in one `ThreadsService` class
2. **Full in-namespace decomposition** — all 15 components in `phpbb\threads\`
3. **Lean core + plugin extensions** — core: ThreadsService, TopicRepo, PostRepo, DraftRepo, VisibilityService, CounterService, TopicMetadataService, DraftService, ContentPipeline. Plugins: Polls, ReadTracking, Subscriptions, Attachments

### Decision Outcome
Chosen option: **Lean core + plugin extensions**, because it keeps the core focused on the three hardest problems (counters, visibility, metadata) while demonstrating the plugin extension model with real features. Polls, read tracking, subscriptions, and attachments have independent lifecycles and can hook into the core via domain events and request/response decorators without any core code changes.

### Consequences

#### Good
- Core surface area is ~9 components instead of 15+ — manageable review and testing
- Plugin architecture validated by 4 real-world features
- Each plugin can be developed, deployed, and tested independently
- Core changes don't risk breaking poll/tracking/subscription logic

#### Bad
- Plugin features require event+decorator coordination — more indirection than direct method calls
- Poll creation is transactionally separate from topic creation (PollPlugin reacts to TopicCreatedEvent post-commit)
- Developers must understand both core service API and plugin extension points

---

## ADR-004: Event-Driven Counter Propagation

### Status
Amended (2026-04-20) — supersedes original "Hybrid tiered counter management" decision. See cross-cutting-decisions-plan.md D8 and COUNTER_PATTERN.md.

### Context
The legacy system maintains 20+ denormalized counters across 3 table levels (topic, forum, global) plus per-user post counts. Counter bugs are immediately visible to all users. The counters span two service boundaries: topic/forum counters are owned-data, but user_posts is owned by `phpbb\user`.

**Amendment rationale**: The cross-cutting assessment (D8) determined that Threads must be completely unaware of Hierarchy. Forum counters are now propagated via domain events, not synchronous calls. This eliminates the coupling between Threads and Hierarchy.

### Decision Drivers
- **Threads must have zero imports from `phpbb\hierarchy`** — event-driven only (cross-cutting D8)
- Forum-visible counters should reflect changes promptly (eventual consistency accepted)
- User post counts are owned by `phpbb\user` — cross-service transaction is undesirable
- Safety net needed for any bugs in counter logic
- Follows the Tiered Counter Pattern standard (COUNTER_PATTERN.md)

### Considered Options
1. **All synchronous** — all counters in one transaction (including user table and forum table)
2. **All event-driven** — eventual consistency for everything
3. **Sync + reconciliation** — synchronous counters + batch resync jobs
4. **Hybrid tiered** — sync for own data, events for cross-service, reconciliation for safety

### Decision Outcome
Chosen option: **Hybrid tiered** (option 4, amended scope). Topic-level counters (`topic_posts_approved`, `topic_posts_unapproved`, `topic_posts_softdeleted`) are updated synchronously within the threads-owned transaction. Forum-level counters (`forum_posts_*`, `forum_topics_*`, `num_posts`, `num_topics`) are propagated via `ForumCountersChangedEvent` consumed by Hierarchy's `ForumStatsSubscriber`. User counters are updated via `PostCreatedEvent` consumed by `phpbb\user`. Periodic reconciliation catches any drift.

**Key change from original**: Forum counters are NO LONGER updated in-transaction by Threads. Threads emits events; Hierarchy consumes them independently.

### Consequences

#### Good
- **Zero coupling** — Threads has no import of `phpbb\hierarchy`
- Topic-level counters are always accurate (ACID guarantees, same transaction)
- No cross-service transaction — neither user table nor forum table locked during post operations
- Clean service boundaries: each service owns its own counters
- Self-healing via reconciliation batch job (COUNTER_PATTERN.md)

#### Bad
- Forum counters may be stale for one request cycle (eventual consistency)
- Three mechanisms to maintain (sync + events + reconciliation)
- User post count may lag by milliseconds (event dispatch latency)
- Reconciliation is expensive (COUNT queries on full tables) — run infrequently

---

## ADR-005: Dedicated VisibilityService

### Status
Accepted

### Context
Post and topic visibility follows a 4-state machine: Unapproved(0), Approved(1), Deleted(2), Reapprove(3). Transitions trigger counter changes across multiple tables and cascade operations (topic soft-delete cascades to all approved posts). The legacy system has a dedicated `content_visibility.php` class (~900 LOC) because visibility logic was too complex to scatter across services.

### Decision Drivers
- Visibility has 8+ transitions, each with multi-table counter side effects
- Cascade behavior (topic → posts) crosses Post/Topic boundaries
- SQL generation for visibility-filtered queries must be consistent
- Counter bugs from scattered visibility updates are the #1 correctness risk

### Considered Options
1. **Visibility enum with transition methods** — logic on the value object
2. **Simple enum + inline service logic** — spread across ThreadsService methods
3. **Dedicated VisibilityService** — single entry point for all transitions
4. **Generic cross-cutting VisibilityService** — shared across bounded contexts

### Decision Outcome
Chosen option: **Dedicated VisibilityService**, because it centralizes the most complex business logic (state machine + counter effects + cascades) into a single testable service. It is the ONLY entry point for visibility changes, preventing the scattered-update bugs that plague the legacy system. It integrates with CounterService for correct counter management and provides consistent SQL generation via `getVisibilitySql()`.

### Consequences

#### Good
- Single source of truth for all visibility transitions — eliminates scattered updates
- Counter matrix encoded once and tested once
- Cascade behavior (topic → posts with matching delete_time) centralized
- SQL generation consistent across all repositories
- Highly testable: each transition is a unit-testable method

#### Bad
- All write operations must route through VisibilityService — coordination overhead
- One more service dependency for ThreadsService facade
- Slightly more complex than inline logic for the simplest cases

---

## ADR-006: Auth-Unaware Service

### Status
Accepted

### Context
The legacy system interleaves permission checks (`acl_get()`) within posting operations. The new architecture has a dedicated `phpbb\auth` service. The question is whether `phpbb\threads` should check permissions internally or trust the caller.

### Decision Drivers
- Consistency with `phpbb\hierarchy` which is also auth-unaware — API layer applies ACL
- Separating authorization from business logic improves testability
- API middleware pattern (AuthorizationSubscriber at priority 8) already established
- One permission — `f_noapprove` — determines initial visibility and must be communicated somehow

### Considered Options
1. **Auth-aware** — threads imports and calls phpbb\auth internally
2. **Auth-unaware** — API middleware enforces ACL; service trusts the caller

### Decision Outcome
Chosen option: **Auth-unaware**, because it follows the established pattern from `phpbb\hierarchy` and `phpbb\auth`. The API layer's `AuthorizationSubscriber` enforces permissions before the controller calls ThreadsService. The `f_noapprove` result is passed as a `noapprove` boolean field on request DTOs, avoiding any auth import in the threads namespace.

### Consequences

#### Good
- No dependency on phpbb\auth — threads can be tested in isolation
- Consistent with hierarchy service pattern
- Permission logic centralized in API middleware, not duplicated in services
- Simpler service methods — no permission branching

#### Bad
- Callers MUST enforce permissions before calling the service — no safety net
- f_noapprove passed as a boolean creates a trust boundary (caller must not lie)
- Internal callers (cron jobs, CLI) must simulate the permission check

---

## ADR-007: DraftService in Core Threads Namespace

### Status
Accepted

### Context
Drafts are save points for in-progress posts. The legacy implementation is simple: 7-column table, manual save only, no auto-purge, no attachments, no polls. Drafts could live as a plugin, embedded in PostService, as a generic infrastructure service, or as a standalone service in the threads namespace.

### Decision Drivers
- Drafts are contextually thread-related (save while composing a reply/topic)
- Simple enough to implement first as a confidence-builder for the architecture
- Event pattern integration: PostCreatedEvent → delete matching draft
- Only thread drafts exist currently — YAGNI for generic draft infrastructure

### Considered Options
1. **DraftService in threads namespace** — core feature with DraftRepository
2. **Draft as plugin** — external, event-driven
3. **Draft methods in PostService** — no separate service
4. **Generic phpbb\draft infrastructure** — reusable for PMs, admin forms

### Decision Outcome
Chosen option: **DraftService in threads namespace**, because it is the simplest correct solution for a thread-specific, lightweight feature. Event-based cleanup (PostCreatedEvent → delete matching draft) integrates cleanly with the domain event architecture. If PM drafts are needed later, a generic service can be extracted at that time.

### Consequences

#### Good
- Simplest implementation (~100-150 LOC for service + repository)
- Good first-implementation candidate — validates patterns with low risk
- Event-based cleanup is clean and decoupled
- In-namespace — discoverable, testable, no external dependency

#### Bad
- Not reusable for PM drafts (extraction needed if PMs require drafts)
- Two more classes in the threads namespace (DraftService + DraftRepository)
