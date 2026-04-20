# Synthesis: phpbb\threads Service Design

## Research Question

How should the `phpbb\threads` service be designed to encapsulate all topic/post/poll/draft functionality from the legacy phpBB codebase, following the same architectural patterns established by `phpbb\hierarchy`, `phpbb\auth`, and `phpbb\user` — with a plugin-based content pipeline, event-driven API, and clean service decomposition?

---

## Executive Summary

The legacy threads subsystem is the most complex part of phpBB: ~8,000 LOC across `posting.php` (2123), `functions_posting.php` (3009), `viewtopic.php` (2425), plus `content_visibility.php`, `message_parser.php`, and poll/draft handling. It manages 9 database tables with heavy denormalization (20+ denormalized counters/columns), a 4-state visibility machine, a tightly-coupled content parsing pipeline (s9e TextFormatter), and intricate counter management that touches 4 tables per operation.

The core design challenge is **decomposing this monolith into a plugin-friendly, event-driven service** while preserving the battle-tested counter consistency and visibility semantics. The content pipeline is particularly critical: BBCode, markdown, smilies, attachments, and magic URLs must ALL become plugins, not core concerns — yet the current s9e XML format is deeply embedded.

Three validated patterns from sibling services provide the blueprint:
1. **Event-driven returns** (from hierarchy): Service methods return domain events as primary output
2. **Request/Response DTO decorators** (from hierarchy): Plugins extend via `withExtra()` on immutable DTOs
3. **Auth-unaware services** (from hierarchy + auth): The threads service trusts the caller; `phpbb\auth` middleware enforces permissions externally

---

## Cross-Source Analysis

### Validated Findings (confirmed by multiple sources)

**V1: Four-state visibility is universal and consistent**
- Confirmed across: posting-workflow (§2), soft-delete-visibility (§1-9), topic-post-schema (Constants)
- States: UNAPPROVED(0), APPROVED(1), DELETED(2), REAPPROVE(3)
- Both `post_visibility` and `topic_visibility` use identical semantics
- REAPPROVE maps to the same counter as UNAPPROVED (`posts_unapproved`)
- Confidence: **HIGH**

**V2: Three-counter system for every aggregate**
- Confirmed across: topic-post-schema (denormalized counters), soft-delete-visibility (§3), posting-workflow (§5.7)
- Pattern: `{entity}_{level}_approved`, `{entity}_{level}_unapproved`, `{entity}_{level}_softdeleted`
- Applied at: topic level (post counts), forum level (post + topic counts), global config (`num_posts`, `num_topics`)
- Confidence: **HIGH**

**V3: Two-phase query pattern for paginated display**
- Confirmed across: topic-display (§2.1, §2.2, §12.4)
- Step 1: Fetch IDs only with pagination (lightweight query)
- Step 2: Fetch full data for those IDs (rich JOIN query)
- Applied in both viewtopic (post IDs) and viewforum (topic IDs)
- Confidence: **HIGH**

**V4: submit_post() is a monolith that needs decomposition**
- Confirmed across: posting-workflow (§5, §14.1), content-format (§5), polls-drafts (§1.2-1.3)
- ~1000 lines, handles post/reply/edit with mode-based branching
- Has its own `@todo Split up` comment in source
- Touches 10+ tables in a single transaction
- Confidence: **HIGH**

**V5: Orphan attachment pattern is the canonical upload model**
- Confirmed across: attachment-patterns (§2, §7), posting-workflow (§5.11)
- Phase 1: Upload → create with `is_orphan=1`
- Phase 2: Post submit → adopt, set `is_orphan=0`
- Security: Always re-verify ownership from DB
- Confidence: **HIGH**

**V6: s9e XML is the canonical storage format but needs rethinking**
- Confirmed across: content-format (§1, §5, §7, §14)
- Stores parsed intermediate XML (`<r>...</r>` / `<t>...</t>`), not raw text
- Round-trips via `<s>`/`<e>` markers for edit → unparse
- Tightly coupled to PHP s9e library
- Not API-friendly (JSON AST would be better for SPA/REST)
- Confidence: **HIGH**

### Contradictions Resolved

**C1: Poll data lives on topics table vs. separate entity**
- Schema shows poll columns directly on `phpbb_topics` (6 columns)
- Polls also use 2 separate tables (`poll_options`, `poll_votes`)
- Resolution: Poll is a **value object** owned by Topic, NOT an independent entity. The `poll_options` and `poll_votes` tables are implementation details of the Poll aggregate. In the new design, PollData is embedded in Topic but has its own repository for option/vote CRUD.

**C2: Draft message storage format**
- Drafts store "parsed BBCode" (`$message_parser->message`) per polls-drafts §2.2
- But drafts are loaded back into the message parser for editing
- Resolution: In the new design, drafts should store **raw source text** (pre-parse), since the content pipeline is plugin-based and parsing is not idempotent across plugin configurations. Storing parsed output locks the draft to the parse-time plugin config.

**C3: Counter updates are sometimes in transactions, sometimes not**
- `submit_post()` wraps core SQL in a transaction (§5.4, §5.14)
- But search indexing, notifications, read tracking are post-commit (§5.15-5.16)
- `delete_post()` uses two separate transactions (§11)
- Resolution: The new design should use a single transaction for data + counters, with post-commit event dispatch for side effects (search, notifications, tracking). This matches the hierarchy service pattern.

### Confidence Assessment

| Finding | Confidence | Sources |
|---------|------------|---------|
| Visibility state machine | HIGH | 3 sources, direct code evidence |
| Counter management pattern | HIGH | 3 sources, schema + code evidence |
| Two-phase query pattern | HIGH | 2 independent implementations |
| Content pipeline architecture | HIGH | 2 sources, detailed code analysis |
| Poll lifecycle | MEDIUM | 2 sources, but edge cases unclear |
| Draft lifecycle | MEDIUM | 1 primary source, limited coverage |
| Orphan upload pattern | HIGH | 2 sources, security analysis included |
| First-post special handling | HIGH | 3 sources, complex cascade logic |

---

## Patterns and Themes

### P1: Denormalization-Heavy Schema (Prevalence: Universal, Quality: Established)

The legacy schema aggressively denormalizes for read performance:
- **Topic table**: 12+ denormalized columns (first/last poster names, colours, post times)
- **Post table**: `forum_id` denormalized from topic for query performance
- **Forum table**: 6 counter pairs for posts/topics by visibility state
- **Attachment flags**: `post_attachment`, `topic_attachment` boolean flags avoid JOINs

**Implication**: The new service MUST maintain these denormalized counters/columns or explicitly redesign the read path. Counter consistency is the #1 correctness concern.

### P2: Mode-Branching Monolith (Prevalence: Core posting, Quality: Poor/Legacy)

Both `submit_post()` and `delete_post()` use mode-string branching (`post`, `reply`, `edit_first_post`, `edit_last_post`, `edit_topic`, `edit`, `delete_topic`, `delete_first_post`, `delete_last_post`, `delete`).

**Implication**: Decompose into separate command handlers: `CreateTopicHandler`, `CreateReplyHandler`, `EditPostHandler`, `DeletePostHandler`, `BumpTopicHandler`. Each handler encapsulates its own counter logic and event emission.

### P3: Visibility as Primary State (Prevalence: Universal, Quality: Good)

Visibility (`ITEM_APPROVED`, `ITEM_UNAPPROVED`, `ITEM_DELETED`, `ITEM_REAPPROVE`) is not a secondary flag — it IS the primary state machine. There's no separate `status` + `deleted` pattern. The `content_visibility` class encapsulates all state transitions and counter adjustments.

**Implication**: Model as a `Visibility` enum with a `VisibilityService` that handles all transitions and counter side effects. This is the single most critical service component.

### P4: Event-Heavy Extension Points (Prevalence: High — 30+ events, Quality: Mixed)

Legacy phpBB fires 30+ events across posting, display, and delete flows. Many are "modify data before SQL" patterns rather than true domain events.

**Implication**: Map legacy "modify" events to request decorators and legacy "after" events to domain events. The new event catalog should be strictly domain-oriented: `TopicCreated`, `PostCreated`, `PostEdited`, `PostVisibilityChanged`, `TopicSoftDeleted`, etc.

### P5: Two-Phase Display Optimization (Prevalence: Both viewtopic + viewforum, Quality: Good)

The "fetch IDs then fetch data" pattern with reverse optimization (query from end for late pages) is well-proven.

**Implication**: Preserve this pattern in `PostRepository::findPaginated()` and `TopicRepository::findByForum()`. The repository returns domain entities, not raw arrays.

### P6: Timer-Based Edit/Delete Restrictions (Prevalence: Posting, Quality: Established)

Config-based time limits (`edit_time`, `delete_time`) plus moderator overrides. Post edit locking via `post_edit_locked` flag.

**Implication**: These business rules belong in the API/middleware layer, NOT in the threads service. The service trusts the caller (constraint from design brief). The API layer checks time limits and edit locks before calling the service.

---

## Key Insights

### I1: Content Pipeline Must Be Fully Decoupled (Confidence: HIGH)

**Evidence**: content-format §14 identifies 9 distinct transformations currently in the pipeline. Attachment-patterns §10 maps the full coupling surface. The design constraint explicitly requires "BBCode, markdown, smilies, attachments, magic URLs = ALL plugins, NOT core."

**Implication**: Core stores TWO text representations:
1. **`raw_text`**: Exactly what the user typed (for editing)
2. **`rendered_metadata`**: Plugin-produced output metadata (JSON, for efficient rendering)

The core provides a `ContentPipelineInterface` that plugins implement. The pipeline runs at save-time (parse) and display-time (render). Plugins register ordered handlers. This replaces s9e completely in the new service.

### I2: Counter Management Needs a Dedicated Service (Confidence: HIGH)

**Evidence**: Counter updates are scattered across `submit_post()`, `delete_post()`, `content_visibility`, and `update_post_information()`. There are 20+ counter columns across 3 tables. Manual counter management is the #1 source of bugs in legacy phpBB.

**Implication**: Create a `CounterService` that encapsulates ALL counter operations:
- `incrementPostCounters(topicId, forumId, visibility)` 
- `decrementPostCounters(topicId, forumId, visibility)`
- `transferPostCounters(topicId, forumId, fromVisibility, toVisibility)`
- `syncTopicCounters(topicId)` — recalculate from posts table
- `syncForumCounters(forumId)` — recalculate from topics/posts tables

The counter service operates within the same transaction as the calling operation.

### I3: First-Post / Last-Post Denormalization is a Major Complexity Driver (Confidence: HIGH)

**Evidence**: posting-workflow §5.6, §5.9, soft-delete-visibility §8, topic-display §12.6 all show extensive first/last post metadata management. When the first post changes (delete, visibility change), 7+ topic columns must be recalculated. When the last post changes, 6+ topic columns AND 6+ forum columns must be recalculated.

**Implication**: Create a `TopicMetadataService` that handles first/last post recalculation. It's called by the main write operations and by the visibility service. This replaces the `update_post_information()` and `sync()` functions.

### I4: Poll is a Topic Aggregate, Not a Standalone Entity (Confidence: HIGH)

**Evidence**: Poll metadata lives on `phpbb_topics` (6 columns). Poll options and votes are child tables of topic. Polls can only be created/edited via the first post of a topic. Poll BBCode rendering uses the first post's `bbcode_uid`.

**Implication**: Model `Poll` as a value object within Topic, with `PollOption` and `PollVote` as child entities. `PollService` handles vote CRUD but lives under the threads namespace. Poll creation/deletion is a Topic operation.

### I5: Draft is a Separate Bounded Context with Minimal Coupling (Confidence: HIGH)

**Evidence**: Drafts have a simple 7-column table, no poll data, no attachments, manual save only, no auto-purge. The only integration point is "delete draft after post submit."

**Implication**: `DraftService` is a lightweight standalone service within `phpbb\threads`. It stores raw text (not parsed). Draft cleanup on post submit is handled via event listener (`PostCreated` → delete matching draft).

### I6: Shadow Topics (Moved) Need Special Handling (Confidence: MEDIUM)

**Evidence**: topic-display §12.6 shows shadow topics (`ITEM_MOVED` status, `topic_moved_id` points to real topic). viewforum does a secondary query to resolve shadows.

**Implication**: `TopicStatus::Moved` in the enum, with `movedToId` on the Topic entity. The `TopicRepository` resolves shadows transparently or provides a `resolveMovedTopics()` method.

---

## Relationships and Dependencies

### Component Dependency Graph

```
phpbb\threads\ThreadsService (Facade)
├── TopicService
│   ├── TopicRepository
│   ├── VisibilityService
│   ├── CounterService
│   └── TopicMetadataService
├── PostService
│   ├── PostRepository
│   ├── VisibilityService
│   ├── CounterService
│   └── ContentPipeline (plugin interface)
├── PollService
│   └── PollRepository
├── DraftService
│   └── DraftRepository
├── ReadTrackingService
│   ├── DbTrackingStrategy
│   └── CookieTrackingStrategy
└── SubscriptionService (topic watches)
    └── SubscriptionRepository
```

### External Integration Points

```
phpbb\threads ──uses──► phpbb\hierarchy (ForumRepository for forum context, stats updates)
phpbb\threads ──uses──► phpbb\user (User entity for author context)
phpbb\threads ◄──used by── phpbb\auth middleware (enforces permissions before calling threads)
phpbb\threads ──emits──► EventDispatcher ──► Search plugin (index updates)
phpbb\threads ──emits──► EventDispatcher ──► Notification plugin (email/push)
phpbb\threads ──emits──► EventDispatcher ──► Attachment plugin (orphan adoption, cascade delete)
phpbb\threads ──emits──► EventDispatcher ──► BBCode plugin (content parsing)
phpbb\threads ──emits──► EventDispatcher ──► Cache invalidation listener
```

### Data Flow: Create Reply

```
API Controller
  │ (auth middleware already validated f_reply + f_noapprove)
  ▼
CreateReplyRequest DTO
  │ ──► RequestDecoratorChain (attachment plugin adds file refs, BBCode plugin validates)
  ▼
ThreadsService.createReply()
  │
  ├── ContentPipeline.parse(rawText) → Parse events to plugins → rendered metadata
  ├── PostRepository.insert(post)
  ├── CounterService.incrementPostCounters(topicId, forumId, APPROVED)
  ├── TopicMetadataService.updateLastPost(topicId, newPost)
  │     └── Also updates Forum last post via hierarchy event
  ├── DB Transaction COMMIT
  │
  ├── Dispatch PostCreatedEvent
  │     ├── AttachmentPlugin: adopt orphans for this post
  │     ├── SearchPlugin: index new post
  │     ├── NotificationPlugin: notify watchers
  │     ├── DraftPlugin: delete loaded draft
  │     └── ReadTrackingPlugin: mark as read for author
  │
  └── ResponseDecoratorChain → PostCreatedEvent returned to caller
```

---

## Gaps and Uncertainties

### Information Gaps

**G1: Search Index Integration**
- Legacy uses `$search->index()` after commit — backend-agnostic interface
- No research on current search backend implementation
- Impact: Need to define `SearchIndexEvent` for plugins but don't know the exact payload

**G2: Notification System Architecture**
- Legacy fires many notification types (`topic`, `post`, `quote`, `bookmark`, `forum`, `*_in_queue`)
- No research on notification service design
- Impact: Domain events must carry enough data for notification routing

**G3: Moderator Logging System**
- Legacy uses `$phpbb_log->add('mod', ...)` extensively
- No research on how this integrates
- Impact: Mod log should be an event listener, but data requirements unclear

**G4: Private Messages**
- PM uses similar tables/patterns but wasn't investigated
- Shares content pipeline (same BBCode/s9e format)
- Impact: ContentPipeline design should be reusable for PMs

### Unverified Claims

**U1: Cookie-based read tracking is still necessary**
- Legacy supports both DB and cookie tracking for anonymous users
- Question: Does the new API-first approach need cookie tracking, or is DB-only sufficient?

**U2: Global announcements display in all forums**
- `topic_type = POST_GLOBAL` shown everywhere per viewforum findings
- Need to verify: Does the new hierarchy service handle global announcement resolution?

### Unresolved Inconsistencies

**UI1: `post_postcount` column vs user_posts counter**
- Posts have a `post_postcount` flag determining if they increment user post count
- But the visibility service always increments/decrements `user_posts` on state changes
- Question: Should the threads service own `user_posts` counter updates, or delegate to `phpbb\user`?
- Recommendation: Emit `UserPostCountChanged` event, let `phpbb\user` handle its own counter

---

## Synthesis by Domain

### 1. Domain Model

**Entities**:
- `Topic` — The thread aggregate root. Owns poll data, status, type, visibility
- `Post` — Individual message within a topic. Owns content, visibility, edit history
- `PollOption` — Individual poll choice (child of Topic's poll)
- `PollVote` — Individual vote record (child of Topic's poll)
- `Draft` — Unsaved composition (independent lightweight entity)

**Value Objects**:
- `TopicType` (enum: Normal, Sticky, Announce, Global)
- `TopicStatus` (enum: Unlocked, Locked, Moved)
- `Visibility` (enum: Unapproved, Approved, Deleted, Reapprove)
- `PostContent` (raw_text + plugin_metadata)
- `EditInfo` (edit_time, edit_user, edit_count, edit_reason, edit_locked)
- `DeleteInfo` (delete_time, delete_user, delete_reason)
- `PollConfig` (title, max_options, length, vote_change_allowed, start_time)
- `TopicStats` (posts_approved, posts_unapproved, posts_softdeleted, views)
- `LastPostInfo` (post_id, poster_id, poster_name, poster_colour, subject, time)
- `FirstPostInfo` (post_id, poster_id, poster_name, poster_colour, time)

### 2. State Machines

**Post Visibility State Machine**:
```
                    ┌─────────────────────────────────┐
                    │                                 │
                    ▼                                 │
[New Post] ──► UNAPPROVED ──approve──► APPROVED ──soft_delete──► DELETED
                    │                     │    ▲          │
                    │                     │    │          │
                    │               edit (no   │     restore
                    │              f_noapprove) │          │
                    │                     │    │          │
                    │                     ▼    │          │
                    │               REAPPROVE ─┘          │
                    │                     │               │
                    │                approve              │
                    │                     │               │
                    └──────── disapprove ─┘    ◄──────────┘
                         (hard delete)            restore

[Any State] ──hard_delete──► REMOVED (row deleted)
```

**Topic Visibility State Machine**:
- Follows first post visibility in most cases
- Topic soft-delete cascades to all APPROVED posts (changes them to DELETED)
- Topic restore only restores posts whose `post_delete_time` matches `topic_delete_time`
- Individually soft-deleted posts survive topic restore

### 3. Service Decomposition

| Service | Responsibility | Key Methods |
|---------|---------------|-------------|
| `ThreadsService` | Facade — orchestrates all operations | `createTopic()`, `createReply()`, `editPost()`, `deletePost()`, `softDeletePost()`, `restorePost()`, `bumpTopic()`, `lockTopic()` |
| `TopicRepository` | Topic CRUD + queries | `findById()`, `findByForum()`, `findByIds()`, `insert()`, `update()` |
| `PostRepository` | Post CRUD + paginated queries | `findById()`, `findByTopic()`, `findPaginated()`, `insert()`, `update()` |
| `VisibilityService` | State transitions + counter cascades | `setPostVisibility()`, `setTopicVisibility()`, `getVisibilitySql()` |
| `CounterService` | Denormalized counter management | `increment()`, `decrement()`, `transfer()`, `sync()` |
| `TopicMetadataService` | First/last post denormalization | `updateLastPost()`, `updateFirstPost()`, `fullResync()` |
| `PollService` | Poll CRUD + voting | `createPoll()`, `updatePoll()`, `deletePoll()`, `vote()`, `getResults()` |
| `DraftService` | Draft CRUD | `save()`, `load()`, `delete()`, `findByUser()` |
| `ReadTrackingService` | Per-user read state | `markTopicRead()`, `markForumRead()`, `getUnreadTopics()` |
| `TopicSubscriptionService` | Topic watches | `subscribe()`, `unsubscribe()`, `getSubscribers()` |

### 4. Content Pipeline Design

The content pipeline is the primary extension point for plugins:

```php
interface ContentPipelineInterface
{
    /** Parse raw text → store-ready content */
    public function parse(string $rawText, ContentContext $context): ParsedContent;
    
    /** Render stored content → display HTML */
    public function render(ParsedContent $content, RenderContext $context): string;
    
    /** Convert stored content back to raw text for editing */
    public function unparse(ParsedContent $content): string;
}
```

`ParsedContent` contains:
- `rawText`: The original user input (always preserved)
- `metadata`: JSON object with plugin-specific parse results
- `flags`: Which plugins were active at parse time

Plugin registration:
```php
interface ContentPluginInterface
{
    public function getPriority(): int; // Controls execution order
    
    public function parse(string $text, ContentContext $ctx): ContentPluginResult;
    public function render(string $text, array $metadata, RenderContext $ctx): string;
    public function unparse(string $text, array $metadata): string;
}
```

### 5. Plugin Hook Inventory

**Domain Events (post-commit, for side effects)**:

| Event | Trigger | Key Data |
|-------|---------|----------|
| `TopicCreatedEvent` | New topic created | topic, first post, forum_id |
| `PostCreatedEvent` | Reply/quote posted | post, topic_id, forum_id, is_first_post |
| `PostEditedEvent` | Post edited | post (old + new), changed_fields |
| `PostVisibilityChangedEvent` | Approve/soft-delete/restore | post_id, old_visibility, new_visibility |
| `TopicVisibilityChangedEvent` | Topic approve/soft-delete/restore | topic_id, old, new, affected_post_ids |
| `PostHardDeletedEvent` | Permanent delete | post_id, topic_id, forum_id, was_first, was_last |
| `TopicHardDeletedEvent` | Topic permanent delete | topic_id, forum_id, post_ids |
| `TopicBumpedEvent` | Topic bumped | topic_id, bumper_id |
| `TopicLockedEvent` | Topic locked/unlocked | topic_id, new_status |
| `TopicMovedEvent` | Topic moved to another forum | topic_id, old_forum, new_forum |
| `TopicTypeChangedEvent` | Normal↔Sticky↔Announce↔Global | topic_id, old_type, new_type |
| `PollCreatedEvent` | Poll added to topic | topic_id, options |
| `PollVoteCastEvent` | User voted | topic_id, user_id, option_ids |
| `PollDeletedEvent` | Poll removed | topic_id |
| `DraftSavedEvent` | Draft saved | draft_id, user_id |
| `DraftDeletedEvent` | Draft deleted | draft_id |

**Content Pipeline Events (during parse/render)**:

| Event | Phase | Purpose |
|-------|-------|---------|
| `ContentParseBeforeEvent` | Pre-parse | Plugins validate/transform raw input |
| `ContentParseAfterEvent` | Post-parse | Plugins can modify parsed result |
| `ContentRenderBeforeEvent` | Pre-render | Plugins inject metadata (e.g., quote author links) |
| `ContentRenderAfterEvent` | Post-render | Plugins post-process HTML |

**Request Decorator Hooks (synchronous, before operation)**:

| When | Request DTO | What plugins do |
|------|------------|-----------------|
| Creating topic | `CreateTopicRequest` | Add attachment refs, validate poll, inject metadata |
| Creating reply | `CreateReplyRequest` | Add attachment refs, process quotes |
| Editing post | `EditPostRequest` | Handle attachment changes, re-validate |
| Deleting post | `DeletePostRequest` | Cascade attachment cleanup |

---

## Key Design Decisions to Make (Brainstorming Required)

### D1: Storage Format — Raw Text + JSON Metadata vs. s9e XML
- **Option A**: Store raw text + JSON metadata (plugin-produced). API-friendly, format-agnostic.
- **Option B**: Continue storing s9e XML for backward compatibility, add raw text column.
- **Option C**: Store only raw text, render on-the-fly with caching.
- **Recommendation**: Option A. Store both `raw_text` and `rendered_html` (cached). Plugins store metadata in a JSON column.

### D2: Counter Updates — Synchronous in-Transaction vs. Eventual Consistency
- **Option A**: All counter updates in the same transaction (current legacy behavior).
- **Option B**: Emit events, apply counters asynchronously for better throughput.
- **Recommendation**: Option A for correctness. Forum counter displays are critical UX. Async would require complex reconciliation.

### D3: Forum Counter Updates — Threads Service vs. Hierarchy Service
- Threads service creates posts/topics IN forums. Forum stats (`forum_posts_*`, `forum_topics_*`) live on the forums table owned by `phpbb\hierarchy`.
- **Option A**: Threads service directly updates forum counters (cross-service DB write).
- **Option B**: Threads service emits events, hierarchy service listens and updates its own counters.
- **Option C**: Threads service calls a `phpbb\hierarchy` method to update counters.
- **Recommendation**: ~~Option C~~ **Superseded by D8**: Event-driven counter updates. Threads emits `TopicCreatedEvent`, `PostCreatedEvent`, `VisibilityChangedEvent` etc. Hierarchy's `ForumStatsSubscriber` listens and updates forum counters. Threads has no direct dependency on Hierarchy.

### D4: User Post Count — Threads Service vs. User Service
- Legacy `submit_post()` directly updates `user_posts` and `user_lastpost_time`.
- **Option A**: Threads service directly updates user table.
- **Option B**: Emit `UserPostCountChanged` event, let `phpbb\user` handle it.
- **Recommendation**: Option B. User entity is owned by `phpbb\user`. Event-driven decoupling is cleaner.

### D5: Read Tracking — Database vs. Cookie vs. Both
- Legacy supports both DB (registered) and cookie (anonymous) tracking.
- API consumers are always authenticated → cookie tracking unnecessary for API.
- **Option A**: DB-only tracking (simpler).
- **Option B**: Dual DB+cookie (backward compatible with templates).
- **Recommendation**: Option A for the service layer. Cookie tracking, if needed, is an API/template concern.

### D6: Content Pipeline Execution — Eager vs. Lazy Rendering
- **Option A**: Parse and render at save-time, store rendered HTML.
- **Option B**: Store raw text + parse metadata, render on display (with cache).
- **Option C**: Parse at save-time, render lazily with persistent cache.
- **Recommendation**: Option C. Parse at save-time (captures plugin state), render lazily (supports per-user preferences like censoring, smiley display).

### D7: Poll Model — Embedded in Topic vs. Separate Aggregate
- Poll data is tightly coupled to Topic (metadata on same row).
- But poll voting is an independent operation with its own business rules.
- **Recommendation**: Embedded model for creation/metadata, separate `PollService` for voting. Poll cannot exist without a Topic.

---

## Conclusions

### Primary Conclusions

1. **The threads service should follow the 10-service decomposition** outlined above, with `ThreadsService` as the facade coordinating `TopicRepository`, `PostRepository`, `VisibilityService`, `CounterService`, `TopicMetadataService`, `PollService`, `DraftService`, `ReadTrackingService`, and `TopicSubscriptionService`.

2. **Content pipeline must be fully plugin-based** with a `ContentPipelineInterface` that receives ordered plugin handlers. Core stores raw text + plugin metadata. This is the biggest architectural departure from legacy.

3. **Counter management is the hardest correctness problem** and requires a dedicated `CounterService` with transactional guarantees. The three-counter pattern (approved/unapproved/softdeleted) must be preserved.

4. **Visibility is a first-class state machine**, not a flag. The `VisibilityService` is the ONLY way to change visibility, and it handles all counter side effects.

5. **The service is auth-unaware** — following hierarchy's pattern, the API layer enforces permissions before calling service methods.

### Secondary Conclusions

6. Integration with `phpbb\hierarchy` for forum counter/last-post updates should use direct method calls within the transaction, not async events.

7. Integration with `phpbb\user` for post counts should use domain events (async-safe, as post count display is not latency-critical).

8. Draft service is simple enough to implement first as a confidence-builder.

9. The legacy 30+ phpBB events map cleanly to ~16 domain events + request/response decorators.

10. Schema migration will require adding a `raw_text` column to `phpbb_posts` and potentially a `plugin_metadata` JSON column, while keeping `post_text` for backward compatibility during transition.
