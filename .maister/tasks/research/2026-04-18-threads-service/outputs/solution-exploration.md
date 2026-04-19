# Solution Exploration: phpbb\threads Service Design

## Problem Reframing

### Research Question

How should `phpbb\threads` be architected to encapsulate all topic/post/poll/draft functionality with a plugin-based content pipeline, event-driven API, and clean integration with `phpbb\hierarchy`, `phpbb\auth`, and `phpbb\user` services?

### How Might We Questions

1. **HMW store post content** so that all formatting (BBCode, markdown, smilies) is 100% plugin-driven while keeping edits round-trippable and rendering efficient?
2. **HMW design the content pipeline** so plugins can transform text at parse/render time without the core knowing about any specific format?
3. **HMW decompose the 8,000 LOC monolith** into focused services that remain cohesive while keeping the facade simple for callers?
4. **HMW manage 20+ denormalized counters** reliably across 3 table levels without reintroducing the scattered update bugs of the legacy system?
5. **HMW position polls** relative to topics so they remain tightly coupled for creation but independently operable for voting?
6. **HMW handle drafts** so they stay lightweight and decoupled from the full posting pipeline?
7. **HMW model visibility states** so the 4-state machine with cascade behavior is enforced consistently and extensibly?

---

## Decision Area 1: Content Storage Strategy

### Context and Problem Statement

The legacy system stores s9e XML — an intermediate parsed format tightly coupled to the s9e PHP library. The new design mandates that ALL content formatting (BBCode, markdown, smilies, attachments, magic URLs) be plugins, not core concerns. The core must store content in a format that:
- Preserves the original user input for editing (round-trip)
- Enables efficient rendering without re-parsing every display
- Does not assume any specific formatting plugin exists
- Supports migration from legacy s9e XML

### Alternative A: Raw Text Only — Plugins Parse/Render On-The-Fly

**Description**: Core stores only the user's original typed text (`raw_text`). Every display request runs the full plugin pipeline: parse → render. No intermediate state is persisted.

**Strengths**:
- Simplest storage model — single column of plain text
- Plugin changes take effect immediately on existing content (no stale cache)
- Zero migration complexity for storage format changes
- Smallest storage footprint

**Weaknesses**:
- **Performance**: Every page view re-parses every post. For a 20-post page, that's 20 parse + render operations per request
- BBCode/markdown parsing can be CPU-intensive (regex, nesting validation)
- No ability to detect "what changed" between edits without re-parsing
- Read latency directly coupled to plugin count and complexity

**Best when**: Content is short, plugins are trivial, traffic is low — none of which apply to a production forum

**Evidence**: Research report §6.1 shows the legacy system already does dual-phase (parse at save, render at display) specifically because on-the-fly parsing is too expensive. Synthesis §I1 confirms rendering is CPU-bound.

### Alternative B: Raw Text + Cached Rendered Output (Dual Storage)

**Description**: Core stores `raw_text` (original input) and `rendered_html` (cached plugin output). Parse + render runs at save time; display reads `rendered_html` directly. Cache invalidated on edit or plugin configuration change.

**Strengths**:
- **Read performance**: Display is a simple column read, zero parse/render cost
- Simple mental model: what you save is what gets displayed
- Easy to reason about cache invalidation (edit = re-render)
- Closest to what legacy does (s9e XML → HTML at display was already fast)

**Weaknesses**:
- **Per-user rendering impossible**: Rendered HTML is fixed — can't support per-user preferences (view smilies, censoring, search highlight) without variants
- Plugin config changes require bulk re-render of all affected posts (reparser)
- Storage doubles (raw_text + rendered_html for every post)
- Tight coupling between save-time plugin state and display output

**Best when**: All users see identical content; no per-user rendering preferences exist

**Evidence**: Research report §D6 explicitly identifies per-user preferences (viewimg, viewsmilies, viewflash, censoring) as rendering-time concerns. Synthesis §I1 recommends against pre-rendering because of this.

### Alternative C: Raw Text + Plugin-Agnostic Intermediate AST

**Description**: Core stores `raw_text` + a structured JSON AST representing the parsed document tree (paragraphs, inline elements, etc.) in a format-agnostic schema. Plugins produce AST nodes at parse time; renderers consume them at display time.

**Strengths**:
- Format-agnostic: the AST schema is plugin-independent
- Supports multiple output formats (HTML, JSON for SPAs, plain text for notifications)
- Partial re-rendering possible (modify subtree, not whole doc)
- Rich edit diffs possible (structural comparison)

**Weaknesses**:
- **High complexity**: Defining a universal AST that covers BBCode, markdown, attachments, and future plugins is a design project in itself
- Over-engineered for the current use case (forum posts are not complex documents)
- All plugins must agree on AST node types — tight implicit coupling
- JSON AST likely larger than raw text + metadata
- No existing phpBB tooling for AST; pure greenfield risk

**Best when**: Multiple output formats are required and document structure is complex (like a CMS or rich-text editor backend)

**Evidence**: Research report §14 mentions the idea briefly but recommends against it. The existing content is relatively flat (paragraphs with inline formatting) — not deeply nested structures. Synthesis §V6 notes that the s9e XML was already an intermediate format, and it caused coupling problems.

### Alternative D: Raw Text + Plugin Metadata Bag (Recommended)

**Description**: Core stores `raw_text` (original input) + `plugin_metadata` (JSON object keyed by plugin name) + `content_flags` (bitmask of active plugins at parse time). Parse runs at save-time; render runs at display-time using stored metadata. Each plugin owns its portion of the metadata bag.

**Strengths**:
- **Plugin isolation**: Each plugin reads/writes only its own metadata key — no shared schema
- **Per-user rendering**: Render phase can apply per-user preferences (censoring, smiley display)
- Parse at save-time is efficient; render at display-time with metadata is fast (no re-parsing)
- Migration-friendly: legacy content gets `{"s9e_compat": true}` metadata, s9e compatibility plugin renders it
- Extensible: new plugins add new keys without schema changes
- Moderate storage overhead (raw text + relatively small JSON bag)

**Weaknesses**:
- Render still runs per-request (though much cheaper than full parse)
- Plugin metadata format isn't standardized — potential for inconsistency
- Requires render-side caching for high-traffic topics
- Slightly more complex than Alternative B

**Best when**: Multiple formatting plugins exist with independent lifecycles, per-user rendering preferences matter, and the system needs to evolve over time

**Evidence**: Research report §6.2 proposes exactly this model. Synthesis §I1 supports it with HIGH confidence. Report §D6 recommends "parse at save-time, render lazily" which aligns with this approach. The hierarchy service pattern (DTO `withExtra()`) validates the metadata-bag concept.

### Trade-Off Matrix

| Criterion | A: Raw Only | B: Dual Storage | C: AST | D: Metadata Bag |
|-----------|:-----------:|:----------------:|:------:|:----------------:|
| **Complexity** | Low | Low | Very High | Medium |
| **Read Performance** | Poor | Excellent | Good | Good (with cache) |
| **Write Performance** | Excellent | Good | Good | Good |
| **Per-User Rendering** | Yes (expensive) | No | Yes | Yes |
| **Plugin Independence** | Full | Full | Coupled (AST schema) | Full |
| **Storage Overhead** | Minimal | High | High | Moderate |
| **Migration Ease** | Easy | Medium | Hard | Medium |
| **Consistency w/ sibling services** | N/A | N/A | N/A | High (metadata bag = withExtra pattern) |

### Recommendation: Alternative D — Raw Text + Plugin Metadata Bag

**Rationale**: Alternative D is the natural fit for a plugin-driven architecture. It preserves the parse-at-save / render-at-display lifecycle proven by the legacy system, while decoupling the storage format from any specific plugin. The `plugin_metadata` JSON bag mirrors the `withExtra()` pattern used in hierarchy's DTO decorators, ensuring architectural consistency.

**Key trade-offs accepted**: Render runs per-request (not pre-cached), so high-traffic topics need a render cache layer. This is acceptable because per-user preferences (censoring, smiley visibility) REQUIRE render-time variation.

**Key assumptions**: Plugin metadata is compact (< 2KB per post for typical BBCode content). Render with metadata is 10-50x cheaper than full parse (smiley lookup, simple string replacements vs. regex parsing).

**Risk**: MEDIUM — The plugin metadata format is schema-free, so plugins could store excessive data. Mitigate with size limits per plugin and a metadata validation step at parse time.

---

## Decision Area 2: Content Pipeline Architecture

### Context and Problem Statement

Given that all formatting is plugin-based (Decision Area 1), HOW should the plugin content pipeline execute? The pipeline transforms raw text → stored metadata (parse phase) and stored metadata → HTML (render phase). Plugins must execute in a defined order, some plugins depend on others' output, and the system needs extensibility hooks for future plugins.

### Alternative A: Event-Based Pipeline

**Description**: The pipeline fires events at each phase (before-parse, parse, after-parse, before-render, render, after-render). Plugins subscribe to events they care about. Each plugin modifies shared state on the event object.

**Strengths**:
- Maximum decoupling — plugins don't know about each other
- Consistent with the domain event pattern used elsewhere (PostCreatedEvent, etc.)
- Easy to add new plugins (just subscribe to events)
- Event dispatcher infrastructure already exists (Symfony EventDispatcher)

**Weaknesses**:
- **Ordering is implicit** — determined by listener priority, hard to debug
- **Shared mutable state** on event objects (text modified in-place by multiple listeners) — error-prone
- No guaranteed contract between pipeline stages
- Hard to short-circuit the pipeline (e.g., skip render if content hasn't changed)
- Performance overhead of multiple dispatch cycles per post

**Best when**: Pipeline steps are truly independent and don't need to coordinate

**Evidence**: Research report §12.2 identifies 6 content pipeline events. However, synthesis §P4 notes that legacy "modify data" events caused confusion precisely because of shared mutable state. The hierarchy design uses events for post-commit side effects, NOT for within-operation transformation.

### Alternative B: Middleware Chain (Ordered Pipeline Processors)

**Description**: Plugins implement a `ContentMiddlewareInterface` with `process(ContentContext $ctx, callable $next): ContentContext`. Middleware are chained in explicit priority order. Each invokes `$next()` to continue the chain, can modify input before and output after.

**Strengths**:
- **Explicit ordering** — pipeline sequence is visible and debuggable
- Each middleware sees clean input and controls what it passes forward
- Can abort the chain early (validation failure)
- Familiar pattern (PSR-15 HTTP middleware, Laravel pipeline)
- Supports both synchronous parse and render phases

**Weaknesses**:
- **Single responsibility blur** — each middleware handles both parse and render, or needs separate interfaces
- Stack depth grows with plugins (not a real problem with < 20 plugins)
- Less discoverable than events (pipeline must be configured, not just subscribed)
- Middleware can't easily operate on the final result of other middleware

**Best when**: Pipeline stages are sequential, ordered, and each stage needs robust control over the flow

**Evidence**: Research report §6.3 models `ContentPluginInterface` with `parse()` and `render()` methods — essentially middleware operations. The hierarchy design's `DecoratorPipeline` (§Container Overview) uses ordered chains for request/response decoration, validating this pattern.

### Alternative C: Decorator Stack on ContentProcessor Interface

**Description**: A base `ContentProcessor` interface with `parse()` and `render()` methods. Plugins wrap this interface using the decorator pattern: `BBCodeProcessor(SmiliesProcessor(BaseProcessor()))`. Each decorator adds its own behavior before/after delegating to the wrapped processor.

**Strengths**:
- Classic OOP pattern, highly composable
- Each decorator is a standalone class with single responsibility
- Natural fit for "add behavior" use case
- Type-safe: each decorator implements the same interface

**Weaknesses**:
- **Fixed composition at build time** — decorator stack is assembled during DI container compilation, not at runtime
- Hard to conditionally skip decorators (per-request variation requires factory logic)
- Deep nesting makes debugging stack traces painful
- Adding/removing plugins requires rebuilding the decorator chain
- Plugin ordering is implicit in construction order

**Best when**: Behavior composition is fixed per-application and plugins are few

**Evidence**: The hierarchy service uses decorators for request/response DTOs but NOT for service-level processing. The content pipeline needs more flexibility than static decoration provides — per-user rendering preferences mean the render pipeline varies per request.

### Alternative D: Hybrid — Events for Hooks + Middleware for Pipeline Stages (Recommended)

**Description**: Two distinct mechanisms:
1. **Structured middleware chain** for the core parse/render pipeline (ordered, explicit, each plugin is a `ContentPluginInterface` with `parse()`, `render()`, `unparse()`)
2. **Events** for hook points around the pipeline (before/after parse, before/after render) where listeners can validate, modify, or observe

The middleware chain handles formatting transformation (BBCode, markdown, smilies, autolink). Events handle cross-cutting concerns (validation, censoring, caching, logging).

**Strengths**:
- **Separation of concerns**: Transformation plugins use the structured pipeline; cross-cutting concerns use events
- Explicit ordering for pipeline (plugin `getPriority()` determines chain position)
- Events for the "observe/modify at boundaries" pattern that phpBB plugins actually need
- Matches the dual pattern already used in hierarchy: decorators for DTOs + events for side effects
- Easy to reason about: "BBCode runs at priority 100, smilies at 200, autolink at 300" — then `ContentPostRenderEvent` fires for censoring/highlight

**Weaknesses**:
- Two extension mechanisms to learn and maintain
- Plugin authors must choose the right mechanism (documentation needed)
- Slightly more infrastructure code than a pure approach

**Best when**: The pipeline has both sequential transformation concerns (formatting) and cross-cutting concerns (validation, censoring) — which is exactly the threads content model

**Evidence**: Research report §6.2-6.3 models plugins as ordered pipeline processors (middleware aspect) while §12.2 defines 6 events around the pipeline (event aspect). Synthesis §P4 maps legacy "modify" events to request decorators and "after" events to domain events — the same dual-mechanism principle. The hierarchy design already validates this hybrid: decorators for DTOs + events for side effects.

### Trade-Off Matrix

| Criterion | A: Events Only | B: Middleware | C: Decorators | D: Hybrid |
|-----------|:--------------:|:------------:|:-------------:|:---------:|
| **Complexity** | Low | Medium | Medium | Medium-High |
| **Ordering Control** | Implicit (priority) | Explicit (chain) | Implicit (construction) | Explicit + Implicit |
| **Plugin Isolation** | High | Medium | High | High |
| **Per-Request Variation** | Easy | Possible | Hard | Easy |
| **Debugging** | Hard | Good | Hard | Good |
| **Consistency w/ sibling services** | Partial (events only) | Partial | Partial (decorators only) | High (both patterns) |
| **Extensibility** | High | Medium | Low | High |

### Recommendation: Alternative D — Hybrid (Events + Middleware)

**Rationale**: The content pipeline has two distinct extension needs: (1) ordered formatting transformations (BBCode → metadata, markdown → metadata) and (2) cross-cutting hooks (validation, censoring, caching). Using middleware for the former and events for the latter produces clean separation and mirrors the dual-mechanism pattern already established by the hierarchy service (decorator pipeline + domain events).

**Key trade-offs accepted**: Two mechanisms increases learning curve for plugin authors. Mitigate with clear documentation: "Implement `ContentPluginInterface` for formatting plugins; subscribe to `ContentParse*Event` / `ContentRender*Event` for cross-cutting hooks."

**Key assumptions**: Total plugin count for content pipeline stays under 20. Pipeline performance with metadata-based rendering is acceptable (< 5ms per post render with caching).

**Risk**: LOW — Both events and ordered pipelines are well-understood patterns with Symfony infrastructure support. The hierarchy service validates the dual-mechanism approach.

---

## Decision Area 3: Service Decomposition

### Context and Problem Statement

The legacy `submit_post()` (~1000 LOC) handles 6 modes with complex branching, touching 10+ tables. The new design needs to decompose this into focused services while providing a clean facade for callers. The question is: how granular should the decomposition be?

### Alternative A: Monolithic ThreadsService with Internal Methods

**Description**: A single `ThreadsService` class with all methods (`createTopic()`, `createReply()`, `editPost()`, `deletePost()`, etc.) and internal private methods for counter management, visibility, metadata updates. No sub-services.

**Strengths**:
- Simplest architecture — one class, one file, one transaction boundary
- No inter-service coordination overhead
- Easy to understand call flow (no delegation chains)
- Fastest to implement initially

**Weaknesses**:
- **Recreates the monolith** — a 2000+ LOC class is the same problem as `submit_post()` + `functions_posting.php`
- Untestable: can't unit-test counter logic without testing the whole service
- Single class becomes the bottleneck for all changes (merge conflicts, review complexity)
- Violates Single Responsibility Principle
- Counter and visibility logic duplicated across multiple methods

**Best when**: The problem domain is small (< 500 LOC total) — which it is NOT

**Evidence**: Research report §4.2 explicitly rates legacy cohesion as LOW and notes `submit_post()` "has its own `@todo Split up` comment." Synthesis §P2 identifies mode-branching monolith as the pattern to escape, not replicate.

### Alternative B: Facade + 3 Services (TopicService, PostService, ContentService)

**Description**: `ThreadsService` facade delegates to 3 domain services: `TopicService` (topic CRUD, types, status), `PostService` (post CRUD, visibility, editing), `ContentService` (pipeline orchestration). Counter management, metadata updates embedded in Topic/Post services.

**Strengths**:
- Clear domain boundaries: topics vs posts vs content
- Manageable service count (3 + facade = 4 classes)
- Each service is meaningfully sized (300-600 LOC)
- Lower coordination overhead than finer decomposition

**Weaknesses**:
- **Counter logic split across TopicService and PostService** — both touch same counters during visibility changes
- **Visibility spans both Topic and Post** — topic soft-delete cascades to posts, creating cross-service calls
- Topic metadata (first/last post) updates are driven by post operations but owned by topic
- Doesn't address poll and draft complexity — folded into Topic/Post services where they don't belong

**Best when**: The domain has clean aggregates without cross-cutting concerns — which the counter and visibility systems violate

**Evidence**: Synthesis §I2 emphasizes counter management is the #1 correctness concern and needs a DEDICATED service. Synthesis §I3 shows first/last post denormalization crosses Topic/Post boundaries. Research report §7.1-7.2 shows visibility cascades between topic and post levels.

### Alternative C: Facade + 5 Services (Topic, Post, Content, Poll, Draft)

**Description**: `ThreadsService` facade delegates to: `TopicService`, `PostService`, `ContentPipeline`, `PollService`, `DraftService`. Counter management and visibility logic embedded in TopicService/PostService or handled by shared utility classes.

**Strengths**:
- Polls and drafts have clear boundaries and independent lifecycles
- 5 focused services, each testable in isolation
- Draft service can be implemented first as confidence-builder (synthesis conclusion §8)
- Poll service can be developed independently

**Weaknesses**:
- **Still doesn't address the counter/visibility cross-cutting problem** — these span Topic and Post services
- `TopicService` would still be complex: CRUD + counters + metadata + visibility cascades
- Visibility cascade (topic → posts) requires PostService called from TopicService
- Internal coordination between services adds complexity without dedicated abstractions

**Best when**: Counters and visibility are simple enough to embed in CRUD services — which they are NOT (20+ counters, 4-state machine with cascades)

**Evidence**: Synthesis §I2 explicitly recommends a DEDICATED CounterService. Synthesis §P3 identifies visibility as a "primary state" needing its own service, not a flag on CRUD services.

### Alternative D: Facade + Domain Services per Aggregate + Supporting Services (Recommended)

**Description**: `ThreadsService` facade orchestrates:
- **Repositories** (data layer): `TopicRepository`, `PostRepository`, `PollRepository`, `DraftRepository`, `ReadTrackingRepository`, `SubscriptionRepository`
- **Domain services** (business logic): `VisibilityService` (state machine + cascades), `CounterService` (denormalized counters), `TopicMetadataService` (first/last post recalculation)
- **Feature services**: `PollService`, `DraftService`, `ReadTrackingService`, `TopicSubscriptionService`
- **Content pipeline**: `ContentPipeline` (plugin orchestration)

Total: 1 facade + 6 repositories + 3 domain services + 4 feature services + 1 pipeline = 15 components.

**Strengths**:
- **Counter logic centralized** — one CounterService called by all operations that affect counters
- **Visibility logic centralized** — one VisibilityService handles state transitions, cascade logic, and counter integration
- **Metadata recalculation centralized** — one TopicMetadataService for first/last post updates
- Each component has a clear single responsibility
- Repositories are pure data access — fully testable with DB mocks
- Domain services encapsulate the three hardest problems (counters, visibility, metadata)
- Matches hierarchy pattern: facade + sub-services with clear responsibility boundaries

**Weaknesses**:
- Most complex architecture — 15 components to wire and maintain
- Facade orchestration code could become complex (coordination between services)
- More difficult for new developers to navigate
- Risk of excessive abstraction for simpler operations

**Best when**: The domain has cross-cutting concerns (counters, visibility) that span multiple aggregates AND complex state machines — exactly the threads subsystem

**Evidence**: Research report §10.1-10.2 proposes exactly this decomposition. Synthesis concludes with a 10-service recommendation. Hierarchy design validates the facade + sub-services pattern with 5 composable services. All three research-identified hard problems (counters, visibility, metadata) get dedicated services.

### Trade-Off Matrix

| Criterion | A: Monolith | B: 3 Services | C: 5 Services | D: Full Decomp |
|-----------|:-----------:|:-------------:|:-------------:|:--------------:|
| **Complexity** | Low | Medium | Medium | High |
| **Counter Correctness** | Low (scattered) | Low (split) | Low (embedded) | High (centralized) |
| **Visibility Correctness** | Medium | Medium | Medium | High (dedicated) |
| **Testability** | Low | Medium | Good | Excellent |
| **Single Responsibility** | No | Partial | Good | Excellent |
| **Consistency w/ hierarchy** | Low | Medium | Medium | High |
| **Implementation Effort** | Low | Medium | Medium | High |
| **Maintainability** | Poor | Good | Good | Excellent |

### Recommendation: Alternative D — Full Decomposition

**Rationale**: The research overwhelmingly identifies counter management, visibility cascades, and metadata recalculation as the three hardest correctness problems. Each requires centralized logic that spans Topic/Post boundaries. Dedicating services to these cross-cutting concerns eliminates the duplication and coordination bugs that plague alternatives A-C. The pattern matches hierarchy's proven facade + sub-services architecture.

**Key trade-offs accepted**: 15 components is a lot. Mitigate with clear directory structure and interface-first design so navigation is straightforward.

**Key assumptions**: The facade's orchestration code stays manageable (< 50 LOC per operation method). Counter and visibility services cover all edge cases identified in research.

**Risk**: LOW — This decomposition directly follows from the research findings and mirrors the validated hierarchy pattern. The main risk is over-coordination in the facade, mitigated by keeping the facade thin (delegate, don't implement).

---

## Decision Area 4: Counter Management

### Context and Problem Statement

The legacy system maintains 20+ denormalized counters across 3 table levels (topic, forum, global) plus per-user post counts. Every write operation must correctly adjust the right counters. Counter bugs are immediately visible to all users. The question is HOW to manage these updates.

### Alternative A: Synchronous Counter Updates in Same Transaction

**Description**: All counter updates (topic, forum, global, user) run in the same database transaction as the primary write operation. Every `INSERT`/`UPDATE`/`DELETE` to posts/topics is immediately followed by counter `UPDATE` statements within the transaction boundary.

**Strengths**:
- **Perfect consistency** — counters always match reality (ACID guarantees)
- Simple mental model: operation + counters = one atomic unit
- Legacy system uses this pattern (proven correct when done right)
- No eventual consistency window — display is always accurate

**Weaknesses**:
- **Longer transactions** — each write holds locks on topic, forum, and config rows
- Lock contention on hot forums (many concurrent replies updating the same forum counter row)
- Cross-service transaction (forum counters live in hierarchy's tables) creates coupling
- User post count update locks the user row (acceptable for single-user operations)

**Best when**: Consistency is more important than throughput — true for a forum where counter accuracy IS the UX

**Evidence**: Research report §9.1 calls counter management the "#1 correctness concern." Report §D2 recommends synchronous for correctness. Synthesis §C3 resolves the legacy inconsistency by recommending "single transaction for data + counters, with post-commit event dispatch for side effects."

### Alternative B: Event-Driven Counter Updates (Separate Listener)

**Description**: Primary operations (insert post, change visibility) commit immediately. Domain events are dispatched post-commit. A `CounterUpdateListener` subscribes and applies counter adjustments in separate transactions.

**Strengths**:
- Shorter primary transactions — less lock contention
- Decoupled: counter logic lives entirely in the listener
- Easy to add new counter dimensions without modifying write path
- Resilient: failed counter update doesn't block the primary operation

**Weaknesses**:
- **Eventual consistency** — counters may lag behind reality for seconds to minutes
- If listener fails, counters become permanently wrong until manual reconciliation
- Order of event processing matters: concurrent events for the same topic could cause race conditions
- Users see stale counts — confusing for "just posted, but reply count didn't increment"
- Must build reconciliation/repair tooling from day one

**Best when**: Counter accuracy is non-critical or eventual consistency is acceptable — NOT the case for a forum

**Evidence**: Research report §D2 explicitly recommends AGAINST async counters: "Forum counter displays are critical UX." Synthesis §P1 identifies denormalization as the core schema pattern — async counters undermine this.

### Alternative C: Counter Service with Batch Reconciliation

**Description**: A dedicated `CounterService` provides increment/decrement/transfer methods called synchronously. Additionally, a periodic batch job (`syncTopicCounters()`, `syncForumCounters()`) recalculates all counters from source data. The batch job catches and fixes any drift.

**Strengths**:
- **Best of both**: synchronous for correctness + reconciliation for safety net
- Self-healing: bugs in counter logic are automatically corrected on next reconciliation
- Sync operations can be optimized (batch multiple counter updates in one SQL)
- Matches legacy pattern: `sync()` functions already exist
- Provides maintenance/repair tooling out of the box

**Weaknesses**:
- Reconciliation adds background job complexity (cron, admin UI)
- Sync operations AND reconciliation must be maintained — two codepaths for the same data
- Reconciliation can be expensive (full table scans for COUNT queries)
- First codepath (sync updates) must still be correct — reconciliation is safety net, not primary mechanism

**Best when**: Counter correctness is critical AND the system needs self-healing capability — exactly this scenario

**Evidence**: Research report §9.3 proposes exactly a `CounterService` with `syncTopicCounters()` and `syncForumCounters()`. Legacy system has `update_post_information()` and manual sync functions. Synthesis §I2 recommends dedicated service with explicit methods per operation type + full resync capability.

### Alternative D: Hybrid — Critical Counters Sync, Non-Critical Async (Recommended)

**Description**: Tier the counters by criticality:
- **Tier 1 (synchronous)**: topic post counts, forum post/topic counts, global num_posts/num_topics — updated in the same transaction via `CounterService`
- **Tier 2 (event-driven)**: user_posts, user_lastpost_time — updated via event listener in `phpbb\user` after commit
- **Tier 3 (reconciliation)**: All counters periodically verified and corrected via batch reconciliation

**Strengths**:
- Forum-visible counters are always accurate (sync)
- User-level counters use the established event pattern for cross-service updates
- Batch reconciliation provides self-healing for both tiers
- Reduced lock contention: user table not locked during post transactions
- Clean service boundary: `phpbb\user` owns `user_posts` via event consumption

**Weaknesses**:
- Three mechanisms to maintain (sync + events + reconciliation)
- User post count may lag by milliseconds (event dispatch latency)
- Must define the tier boundary clearly: what's critical vs. non-critical

**Best when**: Counter types have different consistency needs AND cross-service boundaries exist — exactly this architecture

**Evidence**: Research report §D4 recommends `UserPostCountChanged` event for user counters ("Event-driven decoupling is cleaner"). Report §D2 recommends synchronous for forum counters. Synthesis conclusion §6 states forum counter updates should use "direct method calls within the transaction, not async events" while conclusion §7 states user post counts should use "domain events." This hybrid is exactly what both documents recommend.

### Trade-Off Matrix

| Criterion | A: All Sync | B: All Async | C: Sync + Reconciliation | D: Hybrid |
|-----------|:-----------:|:------------:|:------------------------:|:---------:|
| **Counter Accuracy** | Perfect | Poor | Good + Self-healing | Very Good + Self-healing |
| **Transaction Duration** | Long | Short | Long | Medium |
| **Lock Contention** | High | None | High | Medium |
| **Cross-Service Coupling** | High (user table) | Low | High | Low (events for user) |
| **Complexity** | Low | Medium | Medium | Medium-High |
| **Self-Healing** | No | No (needs manual) | Yes | Yes |
| **Consistency w/ architecture** | Partial | Matched (events only) | Partial | High (sync for own, events for cross-service) |

### Recommendation: Alternative D — Hybrid Tiered Counters

**Rationale**: This is literally what both the research report AND synthesis independently recommend. Forum-visible counters are synchronous (critical UX), user counters use cross-service events (clean boundaries), and reconciliation provides a safety net. The tier boundary follows the service boundary: threads owns topic/forum counters (sync), user service owns user counters (events).

**Key trade-offs accepted**: Three mechanisms to maintain. Mitigate by keeping the event-driven path simple (single `PostCreatedEvent` → `incrementUserPosts`) and triggering reconciliation infrequently (daily or on-demand).

**Key assumptions**: User post count latency of < 1 second is acceptable. Reconciliation runs fast enough for daily execution.

**Risk**: LOW — Each mechanism is well-understood. The tiering directly maps to service boundaries. Legacy system already uses the sync + reconciliation pattern.

---

## Decision Area 5: Poll Architecture

### Context and Problem Statement

Polls in phpBB are attached to topics: poll metadata lives on the `phpbb_topics` row (6 columns), options in a separate table, and votes in another. Polls can only be created/edited via the first post. Voting is an independent operation. The question is where polls "live" architecturally.

### Alternative A: Poll as Sub-Entity of Topic (Managed by ThreadsService)

**Description**: Poll functionality embedded in `ThreadsService`. `PollConfig` is a value object on the `Topic` entity. Poll options and votes use the same repositories/services as topic CRUD. No separate `PollService`.

**Strengths**:
- Simple: poll creation is part of `createTopic()` / `editPost()` (first post)
- No separate service to coordinate
- Atomic: poll + topic created in one transaction naturally
- Matches the data model (poll columns live on topics table)

**Weaknesses**:
- **Voting logic pollutes ThreadsService** — vote casting has nothing to do with posting
- Vote business rules (max options, duration, change allowed) are non-trivial
- ThreadsService grows larger with poll methods
- Hard to test voting logic independently of topic CRUD
- Poll display/results are a distinct query pattern from topic/post display

**Best when**: Poll logic is trivial (< 50 LOC) — it's not (vote casting, option management, expiry, change-vote rules)

**Evidence**: Research report §5.1 shows `PollConfig` embedded in Topic entity (correct for data) but §13 Finding 4 recommends "Separate `PollService` for voting CRUD." Synthesis §I4 confirms: "embedded model for creation/metadata, separate `PollService` for voting."

### Alternative B: Poll as Separate Plugin (Like Attachments)

**Description**: Polls are completely external to the threads service. A poll plugin subscribes to `TopicCreatedEvent` and `PostEditedEvent` to detect poll data in request decorators, manages its own tables, and provides its own API endpoints for voting.

**Strengths**:
- Maximum decoupling — threads core doesn't know about polls at all
- Poll plugin can evolve independently
- Clean plugin architecture demonstration
- Could be disabled entirely without touching threads code

**Weaknesses**:
- **Poll data on topics table**: The existing 6 poll columns on `phpbb_topics` would be orphaned or need migration
- **Atomic creation impossible**: Poll creation can't be in the same transaction as topic creation
- Over-engineering: Polls are a CORE forum feature, not an optional add-on
- Request/response decorators would need to carry significant poll data
- Inconsistent with the entity model (Topic has poll columns — denying this creates friction)

**Best when**: Polls are truly optional and rarely used — not the case in phpBB where polls are a core feature

**Evidence**: The database schema (report §8.1) shows poll columns directly on `phpbb_topics`. Synthesis §C1 resolves: "Poll is a value object owned by Topic, NOT an independent entity." Treating it as a plugin contradicts the data model.

### Alternative C: Poll as Separate Bounded Context with Own Service (Recommended)

**Description**: `PollService` within the `phpbb\threads` namespace handles poll CRUD (create, update, delete), voting, and results. `PollConfig` remains a value object on `Topic` (metadata). `PollService` has its own `PollRepository` for options/votes tables. Poll creation/deletion is triggered by `ThreadsService` during topic creation/edit. Voting is called directly on `PollService`.

**Strengths**:
- **Clear responsibility boundary**: PollService owns voting logic, ThreadsService owns topic creation
- Voting is independently testable and independently callable
- Data model respected: PollConfig embedded in Topic, options/votes in PollRepository
- Transaction boundary clean: poll option creation in same transaction as topic creation
- Matches the research recommendation exactly

**Weaknesses**:
- Coordination needed: `ThreadsService.createTopic()` must call `PollService.createPoll()` within the transaction
- Two code paths that modify poll data (ThreadsService for create/edit/delete, PollService for voting)
- Slightly more complex than Alternative A

**Best when**: The entity has embedded metadata (poll config on topic) but independent operations (voting) — exactly this scenario

**Evidence**: Research report §13, Finding 4 recommends exactly this. Synthesis §I4: "Embedded model for creation/metadata, separate PollService for voting." Report §10.4 service responsibility matrix shows PollService with its own full CRUD.

### Alternative D: Poll as Value Object Embedded in Topic Entity

**Description**: Poll is purely a value object (`PollConfig`, `PollOption`, `PollVote`) with all operations handled through the Topic entity. `TopicRepository` handles poll option/vote persistence. No separate PollService or PollRepository.

**Strengths**:
- Simplest object model — everything through Topic
- No coordination between services
- Matches DDD aggregate pattern (Poll is owned by Topic aggregate)

**Weaknesses**:
- **Repository bloat**: TopicRepository handles topics + poll options + poll votes — too many concerns
- Voting logic doesn't belong in TopicRepository (repository = data access, not business rules)
- Topic aggregate becomes too large — violates "small aggregate" principle
- Hard to query vote data independently (e.g., "has user voted in any poll?")

**Best when**: Poll operations are purely CRUD with no business logic — vote casting HAS business logic (max options, expiry, change-vote rules)

**Evidence**: Synthesis §I4 explicitly separates metadata ownership (Topic entity) from operational ownership (PollService). The voting business rules (max_options, is_expired, allow_vote_change) warrant their own service.

### Trade-Off Matrix

| Criterion | A: Sub-Entity | B: Plugin | C: Bounded Context | D: Value Object |
|-----------|:-------------:|:---------:|:-------------------:|:---------------:|
| **Service Cohesion** | Low (voting in ThreadsService) | High | High | Low (in TopicRepo) |
| **Testability** | Low | High | High | Medium |
| **Data Model Alignment** | Good | Poor (orphaned columns) | Excellent | Good |
| **Transaction Integrity** | Excellent | Poor | Good | Excellent |
| **Complexity** | Low | High | Medium | Low |
| **Consistency w/ sibling services** | Low | Medium | High | Low |

### Recommendation: Alternative C — Separate Bounded Context

**Rationale**: Polls have embedded metadata (lives on Topic) but independent operations (voting). This dual nature maps perfectly to "PollConfig value object on Topic + PollService for operations." ThreadsService delegates poll create/delete within the transaction; PollService handles voting independently. This matches both the research recommendation and the entity-service separation pattern.

**Key trade-offs accepted**: ThreadsService must coordinate with PollService during topic creation. Mitigate by keeping PollService dependency injection clean and the create/delete delegation thin.

**Key assumptions**: Poll operations (voting, results) are called directly on PollService via the API, not through the ThreadsService facade.

**Risk**: LOW — This is the most natural decomposition based on the data and operation analysis.

---

## Decision Area 6: Draft Architecture

### Context and Problem Statement

Drafts are save points for in-progress posts. Legacy drafts are simple: 7-column table, manual save only, no auto-purge, no attachments, no polls. The question is where drafts live architecturally.

### Alternative A: DraftService in Threads Namespace (Recommended)

**Description**: `DraftService` + `DraftRepository` in `phpbb\threads\service\` and `phpbb\threads\repository\`. Stores raw text (not parsed). Draft cleanup on post submit via `PostCreatedEvent` listener. Emits `DraftSavedEvent` / `DraftDeletedEvent`.

**Strengths**:
- Drafts are contextually thread-related (save while composing a reply/topic)
- Uses event pattern: `PostCreatedEvent` → delete matching draft
- Services are lightweight (~100-150 LOC total)
- Good "first implementation" candidate — builds confidence in the architecture
- Follows the research recommendation exactly

**Weaknesses**:
- Adds 2 more classes to the threads namespace (service + repository)
- Draft CRUD is entirely independent of thread logic — could live anywhere

**Best when**: Drafts are exclusively used for forum posts/topics — true in current phpBB

**Evidence**: Research report §13, Finding 5: "Lightweight DraftService with raw text storage. Good candidate for first implementation." Synthesis §I5: "DraftService is a lightweight standalone service within phpbb\threads." Report conclusion §8: "Draft service is simple enough to implement first as a confidence-builder."

### Alternative B: Draft as Plugin (Outside Core Threads)

**Description**: Draft functionality is a plugin that subscribes to thread events. Draft service lives in its own namespace (`phpbb\drafts\`) or as a plugin package.

**Strengths**:
- Maximum decoupling — threads service is unaware of drafts
- Could be disabled without touching threads code
- Plugin model demonstration

**Weaknesses**:
- Over-engineering for a 7-column table with 4 CRUD operations
- Drafts are a core forum feature (every user uses them)
- No benefit from plugin isolation — drafts have no dependencies beyond user_id and forum_id
- Adds deployment complexity for minimal architectural benefit

**Best when**: Draft functionality is optional or has complex plugin requirements — neither is true

**Evidence**: Synthesis §I5 places DraftService "within phpbb\threads" — not as external plugin. The draft's only integration point is "delete draft after post submit" — trivially handled by an event listener.

### Alternative C: Draft Entity in PostService

**Description**: Draft CRUD methods embedded in `PostService`. No separate DraftService or DraftRepository.

**Strengths**:
- Fewer classes
- Drafts are conceptually "pre-posts" — grouping with PostService makes semantic sense

**Weaknesses**:
- PostService grows with unrelated draft logic
- Draft storage (raw text) differs from post storage (raw text + metadata)
- Draft has no content pipeline processing — different lifecycle than posts
- Harder to test draft operations independently

**Best when**: Draft operations interact heavily with post logic — they don't

**Evidence**: Drafts store raw text, NOT parsed content (Synthesis §C2). Drafts have no visibility, no counters, no metadata. They're a completely different lifecycle from posts.

### Alternative D: Draft as Generic Infrastructure Service

**Description**: A generic `phpbb\draft\DraftService` that handles drafts for any context (posts, PMs, other future features). Thread-agnostic draft storage with `context_type` + `context_id` fields.

**Strengths**:
- Reusable for private messages, admin forms, etc.
- Generic infrastructure avoids duplication if multiple features need drafts
- Clean separation from thread-specific code

**Weaknesses**:
- Over-engineers the current requirement (only thread drafts exist)
- Generic contexts add complexity: `context_type` field, type-specific cleanup logic
- PM system is out of scope — building for theoretical reuse
- Violates YAGNI: no current need for non-thread drafts

**Best when**: Multiple systems need draft functionality NOW — only threads does currently

**Evidence**: Research report scope explicitly excludes private messages. Synthesis scope doesn't mention non-thread drafts. Building generic infrastructure for one use case is premature abstraction.

### Trade-Off Matrix

| Criterion | A: Threads Namespace | B: Plugin | C: In PostService | D: Generic Infra |
|-----------|:--------------------:|:---------:|:------------------:|:-----------------:|
| **Simplicity** | High | Medium | High | Medium |
| **Cohesion** | Good | Over-decoupled | Poor (mixed) | Over-abstracted |
| **Implementation Effort** | Low | Medium | Low | Medium |
| **Reusability** | Thread-specific | High | None | High |
| **Consistency w/ architecture** | High (event pattern) | Medium | Low | Medium |

### Recommendation: Alternative A — DraftService in Threads Namespace

**Rationale**: Drafts are a thread-specific, lightweight feature with a simple lifecycle. Placing DraftService in the threads namespace is the simplest correct solution. Event-based cleanup (PostCreatedEvent → delete draft) integrates cleanly with the architecture. This is the ideal "first implementation" to validate the patterns.

**Key trade-offs accepted**: Not reusable for PMs. If PM drafts are needed later, a generic service can be extracted at that time (simple refactor).

**Key assumptions**: No non-thread draft use cases emerge in the near term.

**Risk**: VERY LOW — Simplest decision area. All research sources agree.

---

## Decision Area 7: Visibility / State Machine

### Context and Problem Statement

Post and topic visibility follows a 4-state machine: Unapproved(0) → Approved(1), Approved → Deleted(2), Approved → Reapprove(3) (when edited without f_noapprove). Transitions trigger counter changes and cascades (topic soft-delete cascades to all approved posts). The question is how to model and enforce this state machine.

### Alternative A: State Pattern with Visibility Value Object and Transition Methods

**Description**: `Visibility` is a value object (PHP enum) with methods like `canTransitionTo(Visibility $target): bool` and `transitionTo(Visibility $target): Visibility`. The `Visibility` enum encodes allowed transitions. Business logic lives on the enum itself.

**Strengths**:
- Self-documenting: the Visibility enum shows all states and transitions
- Compile-time safety: invalid transitions caught by method logic
- Simple: no separate state machine infrastructure
- Value object is immutable and easily testable

**Weaknesses**:
- **Side effects not handled**: Transitions need counter updates, cascade logic — the enum can't do this
- Transition validation is simple validation, not the real complexity (which is counter management)
- Doesn't address cascade behavior (topic → posts)
- Business rules become bloated on the enum: edit-without-noapprove → Reapprove requires context the enum doesn't have

**Best when**: Transitions are simple and have no side effects — NOT the case here

**Evidence**: Research report §7.4 shows every transition has counter changes and events. The transition itself is trivial (change an int); the CONSEQUENCES are complex. A value object can't orchestrate multi-table counter updates.

### Alternative B: Simple Enum + Validation in Service Layer

**Description**: `Visibility` is a plain PHP enum (4 values). All transition logic, validation, counter updates, and cascades live in `ThreadsService` or a thin helper class. No dedicated visibility service.

**Strengths**:
- Minimal abstraction — visibility is just a field, services handle the logic
- No additional service to wire and maintain
- Simplest for simple cases (approve a post = change field + update counters)

**Weaknesses**:
- **Visibility logic scattered**: Every method in ThreadsService that changes visibility must duplicate/coordinate counter updates
- Topic cascade logic (soft-delete topic → cascade to posts) becomes complex embedded code
- Multiple code paths modifying visibility with inconsistent counter handling
- This is exactly the problem the legacy system has (visibility logic scattered in submit_post, delete_post, content_visibility)

**Best when**: There are few transitions with simple side effects — the threads system has 8+ transitions with multi-table consequences

**Evidence**: Research report §4.1 notes `content_visibility.php` (~900 LOC) exists precisely because visibility logic was too complex to scatter. Synthesis §P3: "Visibility is not a secondary flag — it IS the primary state machine." The legacy system already proved that embedding visibility in services causes bugs.

### Alternative C: State Machine with Explicit Transition Rules and Guards (Recommended)

**Description**: A dedicated `VisibilityService` encapsulates ALL visibility transitions. It defines:
- Allowed transitions (Unapproved→Approved, Approved→Deleted, etc.)
- Guard conditions (e.g., can't restore a post that was never soft-deleted)
- Counter side effects per transition (via `CounterService`)
- Cascade operations: `setTopicVisibility()` cascades to posts
- SQL generation: `getVisibilitySql()` for query WHERE clauses

The service is the ONLY entry point for visibility changes — no direct column updates.

**Strengths**:
- **Single source of truth** for all visibility logic — eliminates scattered updates
- Counter updates are guaranteed correct per transition (counter matrix encoded once)
- Cascade behavior centralized (topic → posts with matching delete_time)
- SQL generation (visibility WHERE clauses) is consistent across all queries
- Matches the legacy `content_visibility.php` pattern but with proper OOP design
- Highly testable: each transition is a unit-testable method

**Weaknesses**:
- Additional service to maintain (but replaces logic that would exist anyway)
- All write operations must route through VisibilityService — adds a coordination step
- Slightly more complex than inline logic for simple cases (approve a single post)

**Best when**: Visibility has complex transitions with side effects, cascades, and cross-table impacts — exactly this system

**Evidence**: Research report §7.1-7.2 maps all transitions with counter changes. Synthesis §P3 identifies visibility as "primary state." Report §10.2 lists VisibilityService as a core service. Synthesis conclusion §4: "VisibilityService is the ONLY way to change visibility, and it handles all counter side effects."

### Alternative D: Visibility as Separate Cross-Cutting Service (VisibilityService Shared Across Domains)

**Description**: A generic `phpbb\visibility\VisibilityService` that manages visibility for ANY entity type (posts, topics, future entities). It takes an entity type + entity ID and handles state transitions generically.

**Strengths**:
- Reusable if other entities need visibility state machines
- Single visibility service for the entire application
- Generic infrastructure

**Weaknesses**:
- **Over-abstraction**: Only posts and topics use this visibility model
- Generic visibility can't encode topic-specific cascades (topic → posts)
- Counter integration requires type-specific logic anyway
- The 4-state model is threads-specific, not universal
- Violates YAGNI: no other bounded context uses this state machine

**Best when**: Multiple bounded contexts share identical visibility semantics — they don't

**Evidence**: The Visibility enum and its semantics (Unapproved/Approved/Deleted/Reapprove) are threads-specific. The hierarchy service has `ForumStatus` which is a completely different model. Auth has no visibility concept. Making it generic adds complexity without benefit.

### Trade-Off Matrix

| Criterion | A: Value Object | B: Enum + Service | C: Dedicated Service | D: Generic Cross-Cut |
|-----------|:---------------:|:-----------------:|:--------------------:|:--------------------:|
| **Correctness** | Low (no side effects) | Medium (scattered) | Excellent (centralized) | Good |
| **Complexity** | Very Low | Low | Medium | High |
| **Counter Integration** | None | Manual per call-site | Integrated | Type-dispatch overhead |
| **Cascade Support** | None | Ad-hoc | Built-in | Type-specific branches |
| **Testability** | Excellent (but trivial) | Low (embedded) | Excellent | Good |
| **Consistency w/ architecture** | Low | Low | High (dedicated service) | Low (shared service) |

### Recommendation: Alternative C — Dedicated VisibilityService

**Rationale**: Visibility is identified by ALL research sources as the core state machine of the threads domain. It has 8+ transitions, each with multi-table counter side effects, and cascade behavior (topic → posts). A dedicated VisibilityService centralizes this complexity, integrates with CounterService for correct counter management, and provides consistent SQL generation for visibility-filtered queries. This directly mirrors the legacy `content_visibility.php` approach but with proper OOP design and testability.

**Key trade-offs accepted**: All visibility changes route through one service, adding a coordination step. This is a feature, not a bug — preventing direct visibility column updates eliminates the #1 source of counter bugs.

**Key assumptions**: VisibilityService has access to both TopicRepository and PostRepository for cascade operations. CounterService is called within the same transaction.

**Risk**: LOW — The dedicated visibility service is the most strongly supported recommendation across all research sources. The legacy system already proved this approach works (content_visibility class).

---

## Trade-Off Analysis (Cross-Cutting)

### 5-Perspective Comparison Matrix

| Decision Area | Technical Feasibility | User Impact | Simplicity | Risk | Scalability |
|:---|:---:|:---:|:---:|:---:|:---:|
| **1. Content Storage: Metadata Bag (D)** | High (JSON column, simple schema) | High (per-user rendering) | Medium (two-phase lifecycle) | Low (proven pattern) | Good (cache renderers) |
| **2. Content Pipeline: Hybrid (D)** | High (Symfony infra exists) | Medium (transparent to users) | Medium (dual mechanism) | Low (well-understood patterns) | Good (priority-ordered) |
| **3. Decomposition: Full (D)** | High (standard PHP DI) | N/A (internal) | Low (15 components) | Low (mirrors hierarchy) | Excellent (independent scaling) |
| **4. Counters: Hybrid Tiered (D)** | High (PDO transactions + events) | High (accurate counts) | Medium (3 mechanisms) | Low (self-healing) | Good (batch reconciliation) |
| **5. Polls: Bounded Context (C)** | High (natural data split) | Medium (voting independent) | High (clear boundaries) | Low (simple delegation) | Good |
| **6. Drafts: Threads Namespace (A)** | High (7-column CRUD) | Medium (basic save/load) | Very High (simplest option) | Very Low | Good |
| **7. Visibility: Dedicated Service (C)** | High (state machine pattern) | High (correct moderation) | Medium (centralized rules) | Low (proven by legacy) | Good |

### Key Cross-Cutting Observations

1. **Consistency theme**: Every recommendation follows the facade + sub-services + events pattern established by `phpbb\hierarchy`. This architectural consistency reduces cognitive load across the codebase.

2. **Complexity budget**: The full decomposition (15 components) is the most complex decision. However, it centralizes the three hardest problems (counters, visibility, metadata) into dedicated services, reducing per-component complexity.

3. **Performance sweet spot**: The metadata-bag content storage with lazy rendering + tiered counter management balances read performance (minimal display-time work) with write correctness (synchronous counters for critical paths).

---

## User Preferences

The following hard constraints from the task brief shaped all alternatives:

| Constraint | Impact on Decisions |
|:---|:---|
| Event-driven API (methods return domain events) | All recommendations use event return pattern |
| Content pipeline = plugins | Storage Strategy D and Pipeline D designed around this |
| Auth via external middleware | No auth checks in any service recommendation |
| Request/Response Decorators | Pipeline D's event hooks complement decorators |
| NO legacy extension system | All alternatives use modern patterns |
| PSR-4 namespace `phpbb\threads\` | All components in threads namespace (except poll voting API) |
| Integration with hierarchy/auth/user | Counter tiering follows service boundaries |

---

## Recommended Approach (Combined)

| Decision Area | Recommendation | Confidence |
|:---|:---|:---:|
| Content Storage | **D**: Raw text + plugin metadata bag (JSON) | HIGH |
| Content Pipeline | **D**: Hybrid — middleware for formatting + events for hooks | HIGH |
| Service Decomposition | **D**: Full decomposition (facade + 6 repos + 3 domain services + 4 feature services + pipeline) | HIGH |
| Counter Management | **D**: Hybrid tiered (sync for forum counters, events for user counters, reconciliation for safety) | HIGH |
| Poll Architecture | **C**: Bounded context (PollConfig on Topic + PollService for voting) | HIGH |
| Draft Architecture | **A**: DraftService in threads namespace | HIGH |
| Visibility/State Machine | **C**: Dedicated VisibilityService with counter integration and cascades | HIGH |

### Primary Rationale

Every recommendation emerges from the same principle: **centralize cross-cutting concerns, delegate independent operations**. The threads domain's three hardest problems (counter management, visibility cascades, first/last post metadata) each get a dedicated service. Feature-level concerns (polls, drafts, tracking, subscriptions) get independent services with clear boundaries. The content pipeline uses the hybrid approach validated by the hierarchy service's dual extension model.

### Key Trade-Offs Accepted

1. **15 components** is complex — but each is focused, testable, and maintainable
2. **Three counter mechanisms** (sync + events + reconciliation) — but maps to service boundaries and provides self-healing
3. **Dual pipeline extension** (middleware + events) — but separates transformation from observation cleanly

### Key Assumptions

1. Facade orchestration stays manageable (< 50 LOC per method)
2. Plugin metadata is compact (< 2KB/post for typical BBCode)
3. Render with metadata is 10-50x cheaper than full parse
4. User post count latency of < 1 second is acceptable
5. Total content plugins stay under 20

---

## Why Not Others

| Rejected Alternative | Decision Area | Rejection Rationale |
|:---|:---|:---|
| A: Raw text only | Storage | Unacceptable read performance for production forum (20 parse ops per page view) |
| B: Dual storage | Storage | Can't support per-user rendering preferences (censoring, smilies, image display) |
| C: AST intermediate | Storage | Over-engineered for forum posts; universal AST is a design project in itself |
| A: Events only | Pipeline | Implicit ordering + shared mutable state caused bugs in legacy (synthesis §P4) |
| B: Middleware only | Pipeline | Can't handle cross-cutting concerns (validation, censoring) cleanly |
| C: Decorators only | Pipeline | Static composition can't vary per-request; poor debugging with deep nesting |
| A: Monolithic | Decomposition | Recreates the exact monolith problem — 2000+ LOC class, untestable |
| B: 3 services | Decomposition | Counter logic split across services; visibility cascades not addressed |
| C: 5 services | Decomposition | Still doesn't centralize counter/visibility cross-cutting concerns |
| A: All sync counters | Counters | Locks user table during post transaction — cross-service coupling |
| B: All async counters | Counters | "Forum counter displays are critical UX" — eventual consistency unacceptable |
| A: Sub-entity | Polls | Voting logic pollutes ThreadsService; untestable |
| B: Plugin | Polls | Breaks transaction integrity for poll creation; orphans schema columns |
| D: Value object only | Polls | Repository bloat; voting business rules don't belong in TopicRepository |
| B: Plugin | Drafts | Over-engineering for 7-column CRUD table; core feature |
| D: Generic infra | Drafts | YAGNI — only threads uses drafts currently |
| A: Value object transitions | Visibility | Can't handle side effects (counters, cascades) |
| B: Inline in services | Visibility | Scattered logic = counter bugs (legacy proof) |
| D: Generic visibility | Visibility | Over-abstraction — only threads uses this 4-state model |

---

## Deferred Ideas

| Idea | Category | Rationale for Deferral |
|:---|:---|:---|
| Generic draft infrastructure for PMs | Stretch | Only threads use drafts now; extract to generic service when PM service is designed |
| Content AST for multi-format output | Out-of-scope | Would enable JSON/plain-text/HTML outputs; consider when SPA frontend needs are clearer |
| Real-time poll results via WebSocket | Out-of-scope | Current architecture is request/response; real-time push is a separate infrastructure concern |
| Content version history | Stretch | Storing diffs between edits would enable richer edit history UI; not in current requirements |
| Optimistic concurrency for post edits | Stretch | Research report §Q3 identifies this as medium priority; defer to implementation spec phase |
| Global announcement resolution | Out-of-scope | Research report §Q6 — needs hierarchy service coordination; defer to API layer design |
| Shadow topic creation on move | Stretch | Research report §Q7 — could use HTTP redirects instead; decide during implementation |
| Read tracking for API consumers | Out-of-scope | Research report §Q5 — mobile apps may manage their own read state; defer to API design |
