# Decision Log: phpbb\threads Service

## Summary

| ADR | Decision | Status |
|-----|----------|--------|
| ADR-001 | Raw text only storage — no metadata bag, no cached HTML | Accepted |
| ADR-002 | Hybrid content pipeline — middleware chain + events | Accepted |
| ADR-003 | Lean core + plugin extensions — Polls, ReadTracking, Subscriptions, Attachments as plugins | Accepted |
| ADR-004 | Hybrid tiered counter management — sync for forum, events for user, reconciliation for safety | Accepted |
| ADR-005 | Dedicated VisibilityService — centralized 4-state machine | Accepted |
| ADR-006 | Auth-unaware service — API middleware enforces ACL externally | Accepted |
| ADR-007 | DraftService in core threads namespace | Accepted |
| 5 | Poll Architecture | **C: Bounded context** | As plugin extending threads (not core) |
| 6 | Draft Architecture | **A: DraftService in threads namespace** | Core feature |
| 7 | Visibility/State Machine | **C: Dedicated VisibilityService** | Centralized transitions + counter integration |

---

## Decision 1: Content Storage — Raw Text Only

**Choice**: A — Store only `raw_text`. Plugins parse/render on every request.

**User rationale**: Simplest storage model. Performance will be addressed with a caching layer in the future (render cache, not stored cache).

**Implications**:
- Single `post_text` column stores the user's original input
- No `plugin_metadata`, no `rendered_html` in DB
- Content pipeline runs full parse+render on every display
- Future: render cache (Redis/file) eliminates repeated rendering
- Migration: legacy s9e XML content needs a compatibility plugin to render old format

---

## Decision 2: Content Pipeline — Hybrid (Middleware + Events)

**Choice**: D — Structured middleware chain for formatting transformations + events for cross-cutting hooks.

**Architecture**:
- `ContentPluginInterface` with `parse()`, `render()`, `getPriority()` — middleware chain
- `ContentParseEvent` / `ContentRenderEvent` — cross-cutting hooks (validation, censoring)
- Plugin ordering explicit via priority numbers

---

## Decision 3: Service Decomposition — Full with Plugin Extensions

**Choice**: D — Full decomposition, BUT with reduced core scope.

**Core threads service** (in `phpbb\threads\`):
- `ThreadsService` — facade
- `TopicRepository`, `PostRepository`, `DraftRepository` — data access
- `VisibilityService` — state machine + cascades
- `CounterService` — denormalized counter management
- `TopicMetadataService` — first/last post recalculation
- `DraftService` — draft CRUD
- `ContentPipeline` — plugin orchestration

**Plugin extensions** (separate bounded contexts, extend threads via events + decorators):
- `PollPlugin` — poll creation, voting, results
- `ReadTrackingPlugin` — unread/read state per user per topic
- `SubscriptionPlugin` — topic watch/notification subscriptions
- `AttachmentPlugin` — file attachments on posts

Each plugin hooks into threads via domain events (`TopicCreatedEvent`, `PostCreatedEvent`, etc.) and request/response decorators.

---

## Decision 4: Counter Management — Hybrid Tiered

**Choice**: D — Three-tier approach.

- **Tier 1 (synchronous)**: topic_posts_approved/unapproved/softdeleted, forum_posts/topics — same transaction
- **Tier 2 (event-driven)**: user_posts, user_lastpost_time — via domain events consumed by `phpbb\user`
- **Tier 3 (reconciliation)**: batch safety net, periodic full recount

---

## Decision 5: Poll Architecture — Plugin Bounded Context

**Choice**: C variant — Bounded context but as a PLUGIN extending threads.

- `PollConfig` data passed via request decorators during topic creation
- `PollService` subscribes to `TopicCreatedEvent`, `PostEditedEvent` (first post)
- Voting is an independent operation on `PollService`
- Poll display data injected via response decorators on topic view
- NOT in core threads — completely separate plugin package

---

## Decision 6: Draft Architecture — Core DraftService

**Choice**: A — DraftService in `phpbb\threads\` namespace.

- Core feature (not a plugin)
- Simple CRUD: save, load, delete
- Event-based cleanup: `PostCreatedEvent` → delete matching draft
- Stores raw text only

---

## Decision 7: Visibility — Dedicated VisibilityService

**Choice**: C — Dedicated service with explicit transition rules and guards.

- Single entry point for ALL visibility changes
- 4 states: Unapproved(0), Approved(1), Deleted(2), Reapprove(3)
- Counter side effects per transition via `CounterService`
- Cascade: topic soft-delete → cascade to approved posts
- SQL generation: `getVisibilitySql()` for filtered queries
- Guard conditions enforced (can't restore what wasn't deleted, etc.)
