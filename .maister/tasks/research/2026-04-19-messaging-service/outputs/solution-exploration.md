# Solution Exploration: phpbb\messaging Service

## Problem Reframing

### Research Question

How should the `phpbb\messaging` service be architected to replace the legacy phpBB private messaging system, preserving essential functionality while modernizing for event-driven extensibility, conversation-centric UX, and plugin-based composition?

### How Might We Questions

1. **HMW group messages into coherent conversations** without losing the simplicity of one-off direct messages?
2. **HMW let users organize messages** in a way that's intuitive to both legacy phpBB users (folders) and modern messaging users (labels/search)?
3. **HMW track read state efficiently** at scale without O(messages × participants) storage?
4. **HMW evaluate filtering rules** at delivery time without blocking the send path or losing the stack-semantics of the legacy engine?
5. **HMW model BCC/hidden participants** in a conversation model where membership is ongoing, not per-message?
6. **HMW handle message mutability** in a multi-participant context without creating inconsistent views?
7. **HMW maintain accurate counters** without the scattered 15+ code-path increment/decrement problem of legacy?
8. **HMW enable moderator access to reported messages** without granting broad PM read permissions?

---

## Decision Area 1: Conversation Model

**Question**: How should messages be grouped and threaded?

**Legacy evidence**: `root_level` column is a proto-conversation-ID (0 = root, non-zero = reply chain). Synthesis confirms this is the natural migration path: "A conversation model with explicit conversation_id FK is the natural evolution."

### Alternative A: Message-Centric (Legacy Preserved)

Keep messages as top-level entities. `root_level` provides loose grouping. A "conversation" is a virtual concept derived from querying messages with the same root.

- **Strengths**: Zero schema change from legacy. Migration is trivial (rename tables). No concept mapping needed for existing data.
- **Weaknesses**: No explicit conversation entity means no place to hang conversation-level metadata (title, type, last_activity). Queries for "all messages in conversation" require self-join or subquery on root_level. Cannot model conversation-level operations (archive, mute) without contorting the data model.
- **Best when**: The system will never evolve beyond legacy feature parity and migration cost is the primary concern.
- **Evidence**: Legacy `root_level` approach analyzed in [gathering/pm-schema.md](../gathering/pm-schema.md). Research report §1.3 confirms flat threading limitations.

### Alternative B: Conversation-Centric (Strict)

All messages require a `conversation_id` FK. A `messaging_conversations` table holds metadata (title, created_at, type, last_message_at). Cannot send a standalone message — always within a conversation.

- **Strengths**: Clean relational model. conversation_id is a single efficient FK lookup. Conversation-level operations (archive, mute, add/remove participant) have a natural home. Indexes on `(conversation_id, created_at)` give fast pagination.
- **Weaknesses**: Slightly heavier write path (INSERT conversation + INSERT message for new threads). Migration must synthesize conversations from legacy `root_level` chains — non-trivial for orphaned messages with `root_level=0` that have no replies.
- **Best when**: Building for modern conversation UX (threaded views, participant management, conversation search).
- **Evidence**: Synthesis §5 ("Flat Threading via root_level"): "A conversation model with explicit conversation_id FK is the natural evolution." Research report §2 Q1 confirms dual-table pattern maps cleanly.

### Alternative C: Hybrid (Auto-Create)

Conversations exist but are auto-created transparently. `MessageService::send()` checks if a conversation exists for the given root/participants; if not, creates one. Single-message "direct" is a 1-message conversation. No user-visible "create conversation" step.

- **Strengths**: Same schema benefits as B, but the API feels like sending messages (not managing conversations). Legacy migration maps cleanly: each unique `root_level` value → one conversation; standalone messages → 1-message conversations. Forward-compatible (conversations can gain features later).
- **Weaknesses**: Implicit creation may cause surprising behavior (e.g., sending to same participants twice creates two conversations unless participant-matching is in place). Slightly more complex send path (lookup or create).
- **Best when**: Wanting the structured data model of conversations without forcing callers to think in terms of conversations.
- **Evidence**: Legacy `submit_pm()` already does implicit grouping via `root_level` — the new service would just formalize it. Research report §1.2 lifecycle analysis shows message creation already involves implicit threading decisions.

### Alternative D: Thread-Per-Recipient-Set (WhatsApp Model)

Unique conversation per unique set of participants. Sending to {Alice, Bob} always routes to the same conversation. Sending to {Alice, Bob, Carol} creates/finds a different conversation. Participant set is the conversation identity.

- **Strengths**: Intuitive grouping — "my conversation with Alice" is always one place. No duplicate conversations. Natural for 1:1 and stable groups.
- **Weaknesses**: Adding a participant to an existing conversation changes its identity (is it still the same conversation?). Legacy data doesn't map well — same root_level thread may have varying recipient sets across replies. BCC/hidden participants complicate set-matching. Group conversations where members come and go break the model.
- **Best when**: The system is primarily 1:1 messaging with occasional small groups, and participant sets are stable.
- **Evidence**: Legacy `to_address`/`bcc_address` varies per message within a thread (different reply-to sets). Synthesis §8 notes groups are expanded at send time, meaning participant sets are not stable. This makes D a poor fit for phpBB's existing usage patterns.

### Trade-Off Matrix

| Criterion | A: Message-Centric | B: Strict Conversation | C: Hybrid Auto-Create | D: Thread-Per-Set |
|---|---|---|---|---|
| **Complexity** | Low | Medium | Medium | High |
| **Query Performance** | Poor (self-join) | Excellent (FK index) | Excellent (FK index) | Good (hash lookup) |
| **Migration Difficulty** | Trivial | Medium (synthesize convos) | Medium (same as B) | Hard (set-matching) |
| **Feature Richness** | Low | High | High | Medium |
| **Event-Driven Fit** | Poor (no entity for conv events) | Excellent | Excellent | Good |
| **Plugin Extensibility** | Poor | Excellent | Excellent | Good |

### Scope Guardrails

- **In scope**: conversation creation, message→conversation FK, conversation-level metadata.
- **Out of scope**: real-time typing indicators, message delivery status beyond read/unread, federation.

### Convergence Recommendation: **C — Hybrid Auto-Create**

**Rationale**: Provides the same schema strength as B (explicit conversation entity, FK indexes, event hookpoints) while keeping the API feel close to legacy "send a message" mental model. Migration maps cleanly: each `root_level` group → one conversation, standalone messages → 1-message conversations. The auto-create pattern avoids forcing callers to manage conversation lifecycle explicitly while still giving the system a first-class conversation entity for events, plugins, and future features.

**Trade-offs accepted**: Slightly more complex send path than B (lookup-or-create). Need a clear rule for when auto-create fires vs. reusing existing conversation.

**Key assumptions**: Most messages are replies within existing threads (confirmed by legacy `root_level` pattern). 1-message conversations will be common but won't cause performance issues (they're just conversations with count=1).

---

## Decision Area 2: Folder/Organization System

**Question**: How do users organize their messages/conversations?

**Legacy evidence**: 5 system folders (INBOX=0, SENTBOX=-1, OUTBOX=-2, NO_BOX=-3, HOLD_BOX=-4) + max 4 custom folders. Per-folder quota of 50 messages. Folders are exclusive (one folder per message per user). Denormalized `pm_count` on folder table. Research report §1.4 has full breakdown.

### Alternative A: Exclusive Folders (Legacy Model)

Each conversation appears in exactly one folder per user. System folders (Inbox, Sent, Archive) + N custom folders. Moving a conversation to folder X removes it from the previous folder.

- **Strengths**: Direct mental model migration for legacy users. Simple implementation (single `folder_id` column on participant table). Folder counts are trivial (one increment, one decrement). Quota enforcement per-folder is straightforward.
- **Weaknesses**: Cannot cross-file (a conversation about "Project X" can be in "Projects" OR "Important" but not both). Rigid. Legacy's max-4-custom-folders limit felt artificial in research.
- **Best when**: User base is accustomed to email-like folder model and migration fidelity matters most.
- **Evidence**: Legacy schema uses `folder_id` on `privmsgs_to` — single-valued. Research report §1.4 for full folder system analysis.

### Alternative B: Labels/Tags (Gmail Model)

Non-exclusive tagging. A conversation can have multiple labels. System labels (Inbox, Sent) plus unlimited user labels. "Moving" = removing one label and adding another (or just adding).

- **Strengths**: Flexible organization. A conversation can be in both "Work" and "Important". No artificial limits. Modern UX. Search + labels replaces browsing folders.
- **Weaknesses**: More complex queries (many-to-many join via `messaging_labels` pivot table). Counter maintenance harder (a conversation in 3 labels must update 3 counters). Migration requires converting folder_id to label rows. Users accustomed to phpBB folders may find labels unfamiliar.
- **Best when**: Building for power users who want flexible organization and the system will have robust search.
- **Evidence**: Synthesis §Key Design Tensions #2 identifies folders vs labels as a key decision point. Legacy research shows users operate with few folders (max 4 custom), suggesting heavy label usage may not match phpBB user behavior.

### Alternative C: Pinned + Archive (Minimal)

Three states per conversation per user: **Active** (in inbox), **Pinned** (sticky at top), **Archived** (hidden from default view). No folders, no labels. Search is the primary organization tool.

- **Strengths**: Extremely simple model (single `state` enum on participant row). Zero configuration for users. No folder/label CRUD. Matches modern chat apps (WhatsApp, Signal). Trivial migration (everything starts as Active).
- **Weaknesses**: No user organization beyond pin/archive. Power users lose ability to categorize. Legacy folder-based rules (move-to-folder action) have no target. Users with many conversations may struggle without search.
- **Best when**: Messaging volume per user is low-to-moderate and simplicity is paramount.
- **Evidence**: Legacy `pm_max_msgs=50` quota suggests moderate volume per user. But legacy rules engine has ACTION_PLACE_INTO_FOLDER as a core action (research report §1.5), which this model eliminates entirely.

### Alternative D: Folders + Optional Labels (Hybrid)

Each conversation has one primary folder (like legacy) AND zero or more optional labels/tags. Folders handle the physical location; labels add metadata layers. System folders required; custom folders enabled; labels are optional addon.

- **Strengths**: Backward-compatible folder model for basic users. Power users can add labels for cross-referencing. Rules engine can target folders (preserved from legacy). Labels provide the flexibility folders lack without replacing them.
- **Weaknesses**: Two organization systems is cognitively complex ("Is this in a folder or labeled or both?"). Implementation must maintain both folder_id and labels pivot table. Counter logic spans two systems. Over-engineered for users who only use folders.
- **Best when**: Gradual migration path where folders are v1 and labels are introduced later as optional.
- **Evidence**: Synthesis identified this tension explicitly. The dual approach tries to satisfy both camps but risks satisfying neither fully.

### Trade-Off Matrix

| Criterion | A: Exclusive Folders | B: Labels/Tags | C: Pinned+Archive | D: Folders+Labels |
|---|---|---|---|---|
| **Complexity** | Low | Medium | Very Low | High |
| **Query Performance** | Excellent (single FK) | Medium (join) | Excellent (enum) | Medium (dual system) |
| **Migration Difficulty** | Trivial | Medium | Trivial | Medium |
| **Feature Richness** | Medium | High | Low | Very High |
| **Rules Engine Fit** | Excellent | Good | Poor (no targets) | Excellent |
| **UX Familiarity** | phpBB users: High | Gmail users: High | Chat users: High | All: Medium |

### Scope Guardrails

- **In scope**: folder/label CRUD, assignment of conversations to organizational units, system default folders.
- **Out of scope**: folder sharing between users, smart/dynamic folders (auto-populated by rules), folder nesting.

### Convergence Recommendation: **A — Exclusive Folders**

**Rationale**: Matches legacy mental model perfectly, keeps implementation simple (single `folder_id` on participant row), and preserves rules engine compatibility (ACTION_PLACE_INTO_FOLDER works unchanged). The legacy system's low custom folder limit (4) and moderate message volume (50/folder quota) suggest phpBB users don't need label flexibility. If labels become needed later, they can be added as a plugin via events (conversation.moved, conversation.labeled) without changing the core model. Simplicity wins for v1.

**Trade-offs accepted**: No cross-filing. Users wanting a conversation in two "folders" must choose one.

**Key assumptions**: phpBB users primarily use system folders and 0-2 custom folders. Search will supplement folder browsing for finding specific conversations. The max custom folder limit can be raised from 4.

---

## Decision Area 3: Read Tracking

**Question**: How is per-user read state tracked?

**Legacy evidence**: `pm_unread` boolean per message per user on `privmsgs_to`. Also `pm_new` for "never seen at all". Denormalized `user_unread_privmsg` on users table. Research report §1.2 shows read status updates happen in `update_unread_status()`.

### Alternative A: Per-Message Flag (Legacy Model)

Each participant has a boolean `is_read` per message in the conversation. Stored as a column on the participant-message junction or similar.

- **Strengths**: Exact per-message granularity. Can mark specific messages unread independently. Matches legacy behavior identically.
- **Weaknesses**: Storage grows as O(messages × participants). For a conversation with 100 messages and 5 participants = 500 rows/flags. Marking "all read" requires updating N rows. Inefficient for long-running conversations.
- **Best when**: Conversations are short (1-5 messages) and exact per-message control is needed.
- **Evidence**: Legacy `privmsgs_to` has one row per participant per message. Synthesis §1 notes this pattern. At legacy scale (50 msgs/folder) it works, but won't scale for longer conversations.

### Alternative B: Cursor-Based

Store `last_read_message_id` per participant per conversation. All messages with `id <= cursor` are "read". Single row per participant per conversation.

- **Strengths**: O(1) storage per participant per conversation regardless of message count. "Mark all read" = update one value. Extremely efficient queries: `WHERE m.id > p.last_read_message_id`. Natural for chronologically-ordered conversations.
- **Weaknesses**: Cannot mark a specific earlier message as "unread" (cursor is monotonic). If messages are reordered or edited, cursor semantics may not match user expectation. Requires message IDs to be monotonically increasing within a conversation (auto-increment guarantees this).
- **Best when**: Messages are chronologically ordered (which they are in conversations) and "mark specific message unread" is not a required feature.
- **Evidence**: Synthesis §Data Model Evolution explicitly recommends "cursor-based (last_read_message_id)" as more efficient. Research report §2 Q5 confirms the recommendation.

### Alternative C: Timestamp-Based

Store `last_read_at` timestamp per participant per conversation. Messages with `created_at <= last_read_at` are read.

- **Strengths**: Same O(1) efficiency as cursor. Works even if message IDs aren't sequential within a conversation. Human-readable ("user last read at 2024-01-15 14:30").
- **Weaknesses**: Timestamp precision issues (two messages in same millisecond). Clock skew between application servers. Less precise than ID-based comparisons. Requires index on `(conversation_id, created_at)` instead of PK-based lookup.
- **Best when**: Message IDs are not guaranteed monotonic within a conversation (e.g., distributed systems). Less relevant for single-node MySQL.
- **Evidence**: Legacy system uses IDs (auto_increment `msg_id`), not timestamps, for ordering. ID-based cursor is more natural for this codebase.

### Alternative D: Hybrid Cursor + Sparse Flags

Cursor for bulk read tracking (same as B), plus a sparse `messaging_read_overrides` table for exceptions: individual messages explicitly marked unread by the user.

- **Strengths**: Gets 99% efficiency of cursor model while supporting "mark as unread" for specific messages. Override table stays small (only user-initiated unmarks are stored). Handles the "I want to come back to this message" UX pattern.
- **Weaknesses**: More complex read-state resolution: `is_read = (msg.id <= cursor) AND NOT EXISTS(override for this msg)`. Two tables to maintain. Edge case: user marks message unread, then reads a newer message (cursor advances past it) — override must be preserved.
- **Best when**: Cursor efficiency is desired but "mark as unread" is a required feature.
- **Evidence**: Legacy has `pm_unread` AND `pm_marked` (important) flags. The "mark as important" feature partially overlaps with "mark unread" use case. A star/pin feature might eliminate the need for unread overrides entirely.

### Trade-Off Matrix

| Criterion | A: Per-Message Flag | B: Cursor | C: Timestamp | D: Cursor+Overrides |
|---|---|---|---|---|
| **Complexity** | Low | Very Low | Low | Medium |
| **Storage Efficiency** | Poor (O(n×m)) | Excellent (O(1)) | Excellent (O(1)) | Excellent (O(1) + sparse) |
| **Query Performance** | Poor (scan flags) | Excellent (ID compare) | Good (timestamp compare) | Good (ID compare + NOT EXISTS) |
| **Migration Difficulty** | Trivial | Easy (MAX(read msg_id)) | Easy (MAX(read timestamp)) | Easy (cursor + migrate marked) |
| **Feature Richness** | High (per-msg control) | Medium | Medium | High |
| **Counter Maintenance** | Complex | Simple | Simple | Medium |

### Scope Guardrails

- **In scope**: read/unread tracking per user per conversation, unread count derivation.
- **Out of scope**: delivery receipts ("delivered to device"), typing indicators, "seen by" lists for group conversations.

### Convergence Recommendation: **B — Cursor-Based**

**Rationale**: O(1) storage per participant, trivially simple queries, and perfect fit for chronologically-ordered conversations. The "mark as unread" use case from Alternative D is real but rare — it can be handled by a separate "starred/pinned messages" feature (which is already planned via `pm_marked` legacy migration) rather than complicating the read-tracking model. Migration is clean: for each user×thread combination, set cursor to MAX(msg_id) of read messages.

**Trade-offs accepted**: No per-message unread control. Users cannot mark individual messages as unread.

**Key assumptions**: Messages within a conversation are always chronologically ordered by auto-increment ID. The "star/pin" feature satisfies the "come back to this" use case.

---

## Decision Area 4: Rule Evaluation Timing

**Question**: When are filtering/routing rules evaluated?

**Legacy evidence**: Rules fire lazily on `place_pm_into_folder()` when user views their PM folder. Messages sit in NO_BOX (-3) until then. Research report §1.5 documents the full rule engine: 14 operators × 5 check types × 4 actions, ALL-match semantics.

### Alternative A: Delivery-Time Synchronous

Rules fire synchronously inside the `MessageService::send()` pipeline. After message INSERT, rules for each recipient are loaded and evaluated. Actions execute before send() returns.

- **Strengths**: Immediate effect — message lands in correct folder instantly. No staging area (NO_BOX eliminated). Notifications fire after rules (so blocked messages don't notify). Simple mental model for users: "my rules apply immediately."
- **Weaknesses**: Send latency increases proportionally to recipient count × rules count. For a message to 20 recipients with 50 rules each = 1000 rule evaluations in one request. Blocks the sender's request.
- **Best when**: Rule counts are low and recipient counts are moderate (typical phpBB scenario: <10 recipients, <20 rules per user).
- **Evidence**: Synthesis §2 ("Lazy Rule Evaluation"): "A modern system should evaluate rules at delivery time (event-driven). The lazy evaluation was a pragmatic choice for a pre-queue era." Research report §2 Q3 confirms delivery-time is recommended.

### Alternative B: Delivery-Time Async (Queue)

Message delivered to inbox immediately (default folder). A queued job evaluates rules in background and moves messages to correct folders.

- **Strengths**: Non-blocking send. Works for arbitrarily complex rules. Scales independently (queue workers can be scaled). MessageService::send() stays fast.
- **Weaknesses**: Requires queue infrastructure (phpBB doesn't have a job queue — only cron). Messages may briefly appear in wrong folder (inbox) before rules move them. Race condition: user opens inbox, sees message, rules move it — confusing UX. More infrastructure complexity.
- **Best when**: System has a proper job queue and rule evaluation is computationally expensive.
- **Evidence**: phpBB has only `cron.php` for background tasks — no real-time queue. Adding queue infrastructure for rules alone is over-engineering. Legacy rule volume (max 5000) is bounded, making sync viable.

### Alternative C: Event Listener (Decoupled Sync)

`message.delivered` domain event is emitted. A `RuleService` listener handles it. Execution is still synchronous in the same HTTP request, but the coupling is through events, not direct calls.

- **Strengths**: Decoupled — MessageService doesn't know about rules. RuleService can be disabled/replaced without touching send path. Other listeners can also react to the same event (notifications, counters). Matches the event-driven architecture constraint.
- **Weaknesses**: Still synchronous — same latency as A but with event dispatch overhead. Error in RuleService listener could affect send reliability (need error isolation). Ordering of listeners matters (rules before notifications).
- **Best when**: Event-driven architecture is a hard requirement (it is) and rule evaluation latency is acceptable in-request.
- **Evidence**: The architectural constraint explicitly requires "Event-driven API (domain events as return values)". This is the natural fit for the system's design philosophy.

### Alternative D: Hybrid (Critical Sync + Non-Critical Deferred)

Critical rules (block/delete) evaluated synchronously via event listener (same as C). Non-critical rules (move-to-folder, mark-as-read, mark-important) deferred to next cron run or lazy on folder view.

- **Strengths**: Blocks abusive messages immediately. Non-critical actions don't add to send latency. Graceful degradation: if cron is slow, messages still land in inbox correctly.
- **Weaknesses**: Two evaluation paths to maintain. User confusion: "Why hasn't my move-to-folder rule fired yet?" Partial consistency — some rules are immediate, others delayed. More complex implementation.
- **Best when**: Critical rules (blocking, auto-delete) MUST be immediate and the system has meaningful cron-based background processing.
- **Evidence**: Legacy uses ACTION_DELETE_MESSAGE (critical) and ACTION_PLACE_INTO_FOLDER (non-critical). Split makes semantic sense but adds implementation complexity. Research synthesis suggests the phpBB rule load is light enough that full-sync is viable.

### Trade-Off Matrix

| Criterion | A: Sync Direct | B: Async Queue | C: Event Listener | D: Hybrid |
|---|---|---|---|---|
| **Complexity** | Low | High (queue infra) | Medium | High (two paths) |
| **Send Latency** | Medium (rules in-request) | Low (deferred) | Medium (rules in-request) | Low-Medium |
| **Migration Difficulty** | Low | High | Low | Medium |
| **Architecture Fit** | Poor (direct coupling) | Good (decoupled) | Excellent (event-driven) | Good |
| **Consistency** | Full immediate | Eventually consistent | Full immediate | Split |
| **Infrastructure** | None extra | Queue system needed | None extra | Cron |

### Scope Guardrails

- **In scope**: Rule evaluation trigger mechanism, event dispatch, action execution.
- **Out of scope**: Rule CRUD UI, rule syntax/DSL, admin-level global rules (future plugin).

### Convergence Recommendation: **C — Event Listener (Decoupled Sync)**

**Rationale**: Perfectly matches the architectural constraint of event-driven APIs. `MessageService::send()` returns domain events including `MessageDelivered`; the `RuleService` subscribes and evaluates rules synchronously in the same request. This gives immediate consistency (messages land in correct folder) while maintaining decoupling (MessageService has zero knowledge of rules). The phpBB rule load is bounded (max 5000 rules, typically <50 active per user, recipient counts <10) — synchronous evaluation in-request is viable without performance concerns.

**Trade-offs accepted**: Send latency includes rule evaluation time. If a future use case requires very heavy rules (AI-based filtering), this may need to evolve to async.

**Key assumptions**: Rule evaluation for a typical user (<50 rules) completes in <10ms. PHP 8.2 performance is sufficient for in-request evaluation.

---

## Decision Area 5: Participant Roles & Visibility

**Question**: What roles can participants have in a conversation?

**Legacy evidence**: TO/BCC addressing. BCC recipients can read/write but are invisible to TO recipients. Author sees all. Reply-all excludes BCC. Sender gets own `privmsgs_to` row. Research report §1.6 documents the full participant model.

### Alternative A: Simple Binary (Owner + Members)

Owner (creator) has full control. Members can read and send messages. No role differentiation beyond that. No hidden participants.

- **Strengths**: Minimal complexity. One `role` enum: `owner`/`member`. No visibility edge cases. Easy to reason about.
- **Weaknesses**: BCC functionality lost entirely. No way to have invisible participants. Owner has no special privileges beyond creation (or unclear what "control" means for messaging).
- **Best when**: BCC/hidden recipients are not needed and conversations are symmetric (all participants are equal).
- **Evidence**: Legacy BCC is actively used (to_address/bcc_address stored per message, group expansion includes BCC recipients). Dropping it loses functionality. Research report §1.6 and Synthesis §7 document BCC semantics.

### Alternative B: Owner/Member/Hidden

Owner manages conversation (can add/remove participants). Members are standard visible participants. Hidden replaces BCC — can read and write but not visible to non-hidden participants. Owner always sees all.

- **Strengths**: Maps directly to legacy TO/BCC semantics. Three-value enum is simple. Covers the primary use case (hidden = BCC). Owner role enables moderation within conversation.
- **Weaknesses**: "Hidden" participants in a conversation model are tricky: if Hidden user sends a message, do visible members see it? (Yes in legacy BCC). If someone is added as Hidden later, do they see history? What about "Reply all" — does it include Hidden?
- **Best when**: BCC compatibility is important and the system needs a clear ownership model.
- **Evidence**: Synthesis §7: "BCC in phpBB means hidden recipient, not email-style BCC." This maps directly to a `hidden` role. Research report §1.6 confirms reply-all excludes BCC.

### Alternative C: Full RBAC

Owner/Admin/Member/ReadOnly/Hidden/Muted. Fine-grained per-conversation permissions. Admins can manage participants but not delete conversation. ReadOnly can view but not send. Muted receives messages but gets no notifications.

- **Strengths**: Maximum flexibility. Covers every conceivable use case. ReadOnly enables announcement channels. Muted handles "quiet follow" pattern.
- **Weaknesses**: Massive over-engineering for a private messaging system. phpBB PM conversations are typically 2-5 people. RBAC per conversation adds complexity to every query (permission checks on every read/write). Migration has no source data for most roles.
- **Best when**: Building a Slack/Discord-style channel system, not a private messaging replacement.
- **Evidence**: Legacy has exactly two visibility levels: TO and BCC. No evidence in codebase or research for need of ReadOnly, Muted, or Admin roles within PM conversations.

### Alternative D: Flat + Visibility Flag

All participants are equal (no owner role). Each has a `visible` boolean. `visible=true` = TO equivalent. `visible=false` = BCC equivalent. No special owner privileges.

- **Strengths**: Simplest possible model: every participant is a row with one boolean flag. No role hierarchy to manage. Matches legacy equality (phpBB PMs don't have a persistent "owner" — the author just sent it).
- **Weaknesses**: No participant management (who can add/remove people if everyone is equal?). Creator has no discoverable special status. "visible" is a negative definition — harder to reason about than "hidden."
- **Best when**: Conversations are truly peer-to-peer with no management needs, and BCC is the only variance.
- **Evidence**: Legacy has no "owner" concept for PMs — the author is just another participant with an OUTBOX/SENTBOX row. This aligns with flat equality. But the new conversation model may need management operations (add participant) which require some authority.

### Trade-Off Matrix

| Criterion | A: Simple Binary | B: Owner/Member/Hidden | C: Full RBAC | D: Flat+Visibility |
|---|---|---|---|---|
| **Complexity** | Very Low | Low | High | Very Low |
| **Legacy Compatibility** | Poor (no BCC) | Excellent | Excellent | Good |
| **Migration Difficulty** | Easy | Easy | Hard (no source data) | Easy |
| **Feature Richness** | Low | Medium | Very High | Low |
| **Query Overhead** | None | Minimal | Significant | None |
| **Future Extensibility** | Low | Good (add roles later) | Already extended | Low |

### Scope Guardrails

- **In scope**: participant roles, visibility rules, who-sees-whom logic.
- **Out of scope**: per-message addressing (TO/BCC is per-conversation, not per-message), dynamic group participants (groups expanded at add-time per legacy).

### Convergence Recommendation: **B — Owner/Member/Hidden**

**Rationale**: Maps directly to legacy BCC semantics (synthesis §7 confirms "hidden recipient"), provides clear ownership for conversation management, and is simple enough (3-value enum) to not burden queries. The Owner role gives a natural authority for "add participant" operations that the flat model (D) lacks. Full RBAC (C) is massive over-engineering for PM conversations of 2-5 people. The 3 roles can be extended later to 4-5 if needed without schema change (enum column).

**Trade-offs accepted**: Hidden participant semantics need clear definition: hidden user's messages ARE visible to all (content is shared), but hidden user's NAME is not in the participant list for non-hidden members.

**Key assumptions**: Conversations need a single authority for management operations. Group expansion at add-time (not dynamic) per legacy behavior. Most conversations have 2-3 participants.

---

## Decision Area 6: Message Mutability

**Question**: Can sent messages be edited or deleted?

**Legacy evidence**: Edit only while in OUTBOX (before any recipient views their folder). Once moved to SENTBOX → immutable. `message_edit_time` and `message_edit_count` columns exist on `phpbb_privmsgs`. Research report §1.2 lifecycle analysis documents the OUTBOX→SENTBOX transition.

### Alternative A: Immutable After Send

Messages cannot be edited once sent. Delete = soft delete per participant (remove from user's view). If all participants delete → hard delete (orphan cleanup).

- **Strengths**: Simplest model. No edit history to track. No "edited" indicators. No confusion about what recipients saw. Classical email semantics. Matches the principle of least surprise for a forum community.
- **Weaknesses**: Typos are permanent. Users accustomed to legacy edit-while-in-outbox lose that ability. No "unsend" capability.
- **Best when**: The system values message integrity over convenience and wants to minimize complexity.
- **Evidence**: Legacy is nearly immutable — edit only in the narrow OUTBOX window before any recipient views. Synthesis §4 notes this is uncommon in modern messaging.

### Alternative B: Time-Limited Edit Window

Configurable edit window (e.g., 5 minutes after send). Within window: full edit allowed. After window: immutable. Edit history tracked in `message_edits` table. "Edited" indicator shown to recipients.

- **Strengths**: Handles typo fixes (the most common edit reason). Clear boundary — no ambiguity about when editing is allowed. Audit trail preserves original content. Familiar from Slack/Discord.
- **Weaknesses**: Adds edit history table and "edited" rendering logic. Time window is arbitrary — 5 minutes may be too short or too long. Still doesn't help with edit-after-read (recipient may have already seen the typo).
- **Best when**: Quick typo correction is valued but long-term message integrity is also important.
- **Evidence**: Legacy `message_edit_time` and `message_edit_count` columns already track edit metadata. The concept of tracked edits exists in the schema; the new system just changes the rules (time-based instead of outbox-state-based).

### Alternative C: Full Edit + Audit Trail

Edit anytime, all edits recorded in `messaging_message_edits` table (original_text, edited_at). Recipients see "(edited)" indicator and can optionally view history. Author can edit their own messages; others cannot.

- **Strengths**: Maximum flexibility. Users can fix errors anytime. Full audit trail for moderation/dispute resolution. Wikipedia-style transparency.
- **Weaknesses**: History table grows with every edit. Complexity for rendering (show current or history?). Security concern: user sends offensive content, moderator screenshots it, user edits to hide it — audit trail mitigates but adds complexity to moderation workflow.
- **Best when**: Building a collaborative/persistent messaging system where conversations are long-lived reference documents.
- **Evidence**: Legacy schema has edit tracking columns, suggesting phpBB already values edit transparency. But legacy restricts editing to outbox-only, suggesting the team was cautious about post-send editing.

### Alternative D: Delete-For-Everyone

Sender can delete a message for all participants within a configurable time window. No editing — delete + resend is the correction mechanism. After window, delete becomes per-participant only.

- **Strengths**: Simple correction mechanism (delete wrong message, send corrected one). No edit history complexity. Clear semantics — deleted messages show "This message was deleted" placeholder. Matches WhatsApp model.
- **Weaknesses**: Disruptive to conversation flow (gap where message was). Recipients may have already read the original. Reply chains reference deleted messages → confusing context. Two different delete behaviors (for-everyone vs for-me) based on timing.
- **Best when**: "Unsend" is the primary use case rather than "edit in place."
- **Evidence**: No legacy equivalent — phpBB PM delete is always per-participant. Introducing delete-for-everyone is a new concept without migration precedent.

### Trade-Off Matrix

| Criterion | A: Immutable | B: Time-Limited Edit | C: Full Edit+Audit | D: Delete-For-Everyone |
|---|---|---|---|---|
| **Complexity** | Very Low | Medium | High | Medium |
| **Storage** | Minimal | Medium (edit history) | High (full history) | Low |
| **Migration Difficulty** | Trivial | Easy | Easy | Easy |
| **Feature Richness** | Low | Medium | High | Medium |
| **Message Integrity** | Highest | High | Medium (audit mitigates) | Medium |
| **Moderation Fit** | Excellent | Good | Good (history helps) | Risky (evidence deleted) |

### Scope Guardrails

- **In scope**: edit/delete policies, edit history storage, "edited" indicator.
- **Out of scope**: real-time edit propagation (push to other clients), collaborative editing, edit permissions beyond author.

### Convergence Recommendation: **B — Time-Limited Edit Window**

**Rationale**: Covers the primary use case (typo correction) without the complexity of full edit history. The configurable window (admin setting, defaulting to 5 minutes) gives board administrators control. Edit history table is small (most messages are never edited). Legacy already has `message_edit_time`/`message_edit_count` columns, making migration natural. The time-based window is a clean evolution from legacy's outbox-state-based window — more intuitive for users.

**Trade-offs accepted**: Users cannot edit messages after the window. No "unsend" capability (but soft-delete-for-self is always available).

**Key assumptions**: Most edits happen within seconds of sending (typos). 5-minute default is sufficient. Edit history is kept for moderation but not exposed as a browsable UI feature in v1.

---

## Decision Area 7: Counter Strategy

**Question**: How are unread counts and totals maintained?

**Legacy evidence**: Denormalized counters scattered across 15+ code paths. `user_new_privmsg` and `user_unread_privmsg` on users table. `pm_count` on folders table. Manual increment/decrement everywhere. The synthesis explicitly calls this out as a key problem area.

### Alternative A: Event-Driven Atomic Counters

Dedicated `messaging_counters` table. Event listeners increment/decrement atomically on each domain event (`message.delivered` → unread++, `message.read` → unread--`, `conversation.archived` → total--`).

- **Strengths**: Centralized counter logic in one service/listener — not scattered across 15+ code paths. Atomic MySQL operations (`UPDATE SET count = count + 1`) prevent races. Counters are always current (no stale data). Cleanly decoupled via events.
- **Weaknesses**: Eventual consistency if event listener fails (message delivered but counter not updated). No self-healing — if counter drifts, it stays wrong until manual fix. Every new event type requires a counter update handler.
- **Best when**: Event infrastructure is reliable and counter accuracy is important.
- **Evidence**: Synthesis §Counter/Denormalization Strategy: "Use the same hybrid tiered counter pattern as threads service." Research report documents 15+ scattered update paths as a key problem.

### Alternative B: Computed on Read

No stored counters. Calculate unread count from read cursors on each request: `SELECT COUNT(*) FROM messages m JOIN participants p ON ... WHERE m.id > p.last_read_message_id`.

- **Strengths**: Always perfectly accurate. Zero counter maintenance code. No drift possible. No additional table needed. Eliminate an entire class of bugs (counter mismatch).
- **Weaknesses**: Query cost on every page load. For a user in 100 conversations, computing unread = 100 subqueries or a complex aggregate join. Not scalable for users with many conversations. The "unread badge" in the header fires on every request — this becomes expensive.
- **Best when**: User conversation count is very low (<20) and query performance is not a concern.
- **Evidence**: Legacy explicitly chose denormalized counters (despite the maintenance burden) because the header badge fires on every page load. Computing on-read was presumably considered and rejected for performance.

### Alternative C: Periodic Reconciliation

Counters maintained by event listeners (like A), but a cron job periodically recalculates true counts and corrects drift. Dual-write: events for real-time, cron for truth.

- **Strengths**: Self-healing — drift from failed events or bugs is automatically corrected. Confidence that counters are eventually accurate even if event handling has bugs. Cron frequency tunable (every 5 min, hourly, daily).
- **Weaknesses**: Counter can be wrong between reconciliation runs. Cron adds load (full table scan to recount). Two systems to maintain (events + cron). Reconciliation for many users can be expensive.
- **Best when**: Event handling is not fully trusted yet (early rollout) and counter accuracy is critical.
- **Evidence**: Legacy PM system has a known bug (PHPBB3-10605: "Orphaned privmsgs left with no ties in privmsgs_to"). Counter drift is a real risk. Self-healing has proven value.

### Alternative D: Tiered (Hot + Cold)

Hot counters (unread conversations, new messages) in a dedicated `messaging_counters` table with atomic operations updated via events. Cold stats (total conversations, total messages sent all-time) computed periodically by cron. Matches the threads service pattern.

- **Strengths**: Hot path (unread badge) is fast and event-driven. Cold stats (profile page totals) don't burden the hot path. Clear separation of concerns. Proven pattern already in use by threads service.
- **Weaknesses**: Two counter strategies to implement. Need clear classification of "hot" vs "cold" for each metric. Cold counters may show stale numbers (acceptable for totals, not for unread).
- **Best when**: The system has both frequently-accessed counters (unread) and rarely-accessed stats (totals) — which is exactly the phpBB use case.
- **Evidence**: Synthesis explicitly recommends "Use the same hybrid tiered counter pattern as threads service: Hot counters (unread count) in dedicated counter table with atomic operations. Cold counters (total messages) derived from counts periodically."

### Trade-Off Matrix

| Criterion | A: Event Atomic | B: Computed | C: Reconciliation | D: Tiered Hot+Cold |
|---|---|---|---|---|
| **Complexity** | Medium | Low | High | Medium-High |
| **Query Performance** | Excellent (read counter) | Poor (aggregate) | Excellent (read counter) | Excellent (hot), Moderate (cold) |
| **Accuracy** | High (no self-heal) | Perfect | High (self-healing) | High (hot), Acceptable (cold) |
| **Migration Difficulty** | Easy | Trivial | Medium | Medium |
| **Consistency with Codebase** | Good | N/A | Good | Excellent (matches threads) |
| **Infrastructure** | Events only | None | Events + Cron | Events + Cron |

### Scope Guardrails

- **In scope**: unread conversation count, unread message count per folder, total conversations per user.
- **Out of scope**: real-time counter push (WebSocket), per-conversation message count (derived from data), system-wide statistics (admin panel).

### Convergence Recommendation: **D — Tiered Hot+Cold**

**Rationale**: Matches the proven pattern already established by the threads service in this codebase. Hot counters (unread conversations, unread per folder) are event-driven with atomic MySQL operations — fast reads, no aggregation queries. Cold counters (total messages, total conversations) computed by cron — acceptable staleness for non-critical stats. This also implicitly includes reconciliation for hot counters (cron verifies and corrects drift), giving the self-healing benefit of C without making it the primary mechanism.

**Trade-offs accepted**: Two counter update mechanisms. Cold stats can be stale by up to one cron cycle.

**Key assumptions**: Unread count is accessed on every page load (header badge) — must be O(1) read. Total message count is accessed rarely (profile page). The threads service tiered pattern is already proven reliable.

---

## Decision Area 8: Reporting Model

**Question**: How are conversations/messages reported to moderators?

**Legacy evidence**: Reports use shared `phpbb_reports` table with `pm_id` column to discriminate from post reports. Report stores full message text as snapshot. Moderators access via MCP with `m_pm_report` permission. Three notification types for PM reports. Research report §1.7 documents the full flow.

### Alternative A: Dedicated Messaging Reports

Separate `messaging_reports` table. Completely decoupled from forum post reports. Own report lifecycle (open → reviewed → resolved). Own moderation interface.

- **Strengths**: Clean bounded context — messaging service owns its report data. No coupling to forum report schema. Can evolve independently (different report fields, workflows). Aligns with service boundaries.
- **Weaknesses**: Duplicates report infrastructure (queue, moderation UI). Moderators must check two report queues. Cannot see unified "all pending reports" view without aggregation layer.
- **Best when**: Services are truly independent and a unified moderation dashboard is future work.
- **Evidence**: Synthesis §6: "Reports → separate messaging_reports table (dedicated bounded context)." Research report architecture constraints require service independence.

### Alternative B: Shared Report Infrastructure

Unified report system across all services. Single `reports` table with `reportable_type` (message, post, profile) and `reportable_id`. One moderation queue for all.

- **Strengths**: Single queue for moderators — no context-switching. Shared report management code (assign, close, escalate). Unified analytics. Consistent UX for reporting anything.
- **Weaknesses**: Cross-service coupling — messaging depends on a shared reports service. Schema must accommodate all reportable types (messages have different context than posts). Changes to report schema affect all services. Violates bounded context principle.
- **Best when**: A shared moderation framework already exists or is planned as a platform service.
- **Evidence**: Legacy already uses shared `phpbb_reports` table. But the modernization explicitly rejects shared tables between bounded contexts. The legacy approach was a pragmatic compromise for a monolith.

### Alternative C: Plugin via Events

Reporting is a plugin that listens to `message.reported` event. The messaging core emits the event with message content; a reporting plugin handles storage, moderation flow, notifications. Core has zero knowledge of report persistence.

- **Strengths**: Maximum decoupling. Messaging core stays clean — no report-related code at all. Reporting can be implemented by any plugin (custom moderation tools, third-party integrations). Aligns perfectly with event-driven architecture constraint.
- **Weaknesses**: No report storage without the plugin — the feature doesn't exist in core. Plugin must be reliable (if it fails to handle the event, report is lost). Testing the full flow requires the plugin.
- **Best when**: Event-driven extensibility is the primary architectural principle and reporting has clear plugin boundaries.
- **Evidence**: Architecture constraint: "Request/Response decorators for extensibility." Events are the extension mechanism. But reporting is a core moderation need — relegating it to a plugin may make it feel like an afterthought.

### Alternative D: Snapshot + Reference

Report stores a snapshot of message content (frozen copy) plus a reference to conversation_id and message_id. Moderator reviews the snapshot without directly accessing the live conversation. If the original message is edited/deleted, the report snapshot preserves evidence.

- **Strengths**: Evidence preservation — edits/deletions don't affect the report. Privacy-focused — moderator sees only the reported content, not the entire conversation. Forensically sound. Works regardless of whether report storage is dedicated or shared.
- **Weaknesses**: Storage duplication (full message content copied). Snapshot becomes stale if message is edited (but this is a feature, not a bug, for evidence). Not a complete answer — still needs a storage decision (dedicated table or shared).
- **Best when**: Evidence integrity is paramount and message mutability exists (it does, per Decision Area 6).
- **Evidence**: Legacy already snapshots content into reports: "report stores message content snapshot" (research report §1.7). The pattern is proven and solves a real problem.

### Trade-Off Matrix

| Criterion | A: Dedicated Table | B: Shared Infrastructure | C: Plugin via Events | D: Snapshot+Reference |
|---|---|---|---|---|
| **Complexity** | Medium | Medium | Low (core), Medium (plugin) | Medium (additive to A or B) |
| **Service Independence** | Excellent | Poor | Excellent | Excellent |
| **Migration Difficulty** | Medium | Easy (extend existing) | Medium | Medium |
| **Feature Richness** | High | High | Depends on plugin | High |
| **Architecture Fit** | Good | Poor (shared table) | Excellent | Good (orthogonal) |
| **Moderator UX** | Dedicated queue | Unified queue | Depends on plugin | N/A (data strategy) |

### Scope Guardrails

- **In scope**: report creation on message, report storage, moderator access to reported content.
- **Out of scope**: moderator conversation access (reading unreported PMs), automated moderation (AI-based), appeal workflow.

### Convergence Recommendation: **A + D — Dedicated Table with Snapshots**

**Rationale**: Dedicated `messaging_reports` table maintains service independence (clean bounded context). Snapshot strategy preserves evidence regardless of message edits (Decision Area 6 allows time-limited edits). The combination gives: (1) messaging owns its report data, (2) moderators see frozen evidence, (3) original message reference enables "view in context" if permissions allow. Event emission (`message.reported`) remains available for plugins to extend the flow (notification to mods, escalation rules) without the core reporting being plugin-dependent.

**Trade-offs accepted**: Moderators must check a separate report queue for messaging (no unified view with post reports in v1). Content duplication in snapshots adds storage.

**Key assumptions**: A unified moderation dashboard can be built later as an aggregation layer over service-specific report tables. Snapshot size is small (message text only, no attachments — attachments are plugin-managed separately).

---

## User Preferences (from Task Constraints)

The following constraints were stated in the task brief and research context:

| Preference | Impact |
|---|---|
| Event-driven API with domain events as return values | Strongly favors C for rule timing, C for reporting extensibility |
| Plugins via events/decorators, NOT legacy extension system | Rules, reporting, attachments are all plugin-adjacent |
| Attachments and formatting are plugins (not in core) | Core messaging stays lean; reporting snapshots exclude attachments |
| Auth is external middleware | Participant roles don't need to duplicate permission checks |
| Integration with existing threads service | Counter pattern should match (tiered hot+cold) |
| MySQL/MariaDB database | Cursor-based read tracking leverages auto_increment IDs well |
| PHP 8.2+ | Enums for participant roles, typed properties throughout |

---

## Recommended Approach Summary

| Decision Area | Recommendation | Confidence |
|---|---|---|
| 1. Conversation Model | **C: Hybrid Auto-Create** | High |
| 2. Folder/Organization | **A: Exclusive Folders** | High |
| 3. Read Tracking | **B: Cursor-Based** | High |
| 4. Rule Evaluation | **C: Event Listener (Sync)** | High |
| 5. Participant Roles | **B: Owner/Member/Hidden** | High |
| 6. Message Mutability | **B: Time-Limited Edit** | Medium |
| 7. Counter Strategy | **D: Tiered Hot+Cold** | High |
| 8. Reporting Model | **A+D: Dedicated + Snapshots** | High |

### Cross-Cutting Coherence Check

The recommended alternatives form a coherent system:

- **Conversation-centric** (C1) gives entities for folder placement (C2), cursor tracking (C3), participant roles (C5), and counter aggregation (C7).
- **Event-driven** rule evaluation (C4) and reporting events (C8) align with the architectural constraint. Events flow: `MessageDelivered` → RuleService (folders), CounterService (unread++), NotificationPlugin.
- **Cursor-based** read tracking (C3) feeds directly into **tiered counters** (C7): unread = conversations where `last_message_id > last_read_cursor`.
- **Exclusive folders** (C2) keep the rules engine simple: `ACTION_PLACE_INTO_FOLDER` maps to exactly one folder_id update.
- **Owner/Member/Hidden** (C5) maps cleanly to legacy TO/BCC with minimal schema.
- **Time-limited edit** (C6) justifies **snapshot reports** (C8) — edited messages need frozen evidence.

---

## Why Not Others

| Rejected Alternative | Rationale |
|---|---|
| 1A: Message-Centric | No conversation entity blocks events, folder placement, participant management. Legacy debt preserved. |
| 1B: Strict Conversation | Forces explicit conversation creation — heavier API for simple "send a message" use case. |
| 1D: Thread-Per-Set | Legacy data doesn't map well (varying recipient sets per message in thread). BCC complicates set-matching. |
| 2B: Labels/Tags | Over-complex for legacy phpBB users with low folder counts. Many-to-many join hurts query performance. |
| 2C: Pinned+Archive | Eliminates folder-based rule actions entirely. Too minimal for users with organizational needs. |
| 2D: Folders+Labels | Over-engineered dual system. Cognitive complexity for marginal benefit. |
| 3A: Per-Message Flag | O(messages × participants) storage doesn't scale for longer conversations. |
| 3C: Timestamp-Based | ID-based cursor is more precise and natural for auto_increment MySQL. |
| 3D: Cursor+Overrides | Adds complexity for "mark unread" feature that can be handled by star/pin instead. |
| 4A: Sync Direct | Direct coupling violates event-driven architecture constraint. |
| 4B: Async Queue | phpBB lacks queue infrastructure. Over-engineering for bounded rule load. |
| 4D: Hybrid Timing | Two evaluation paths are complex and confusing for users ("why didn't my rule fire?"). |
| 5A: Simple Binary | Drops BCC functionality — a regression from legacy. |
| 5C: Full RBAC | Massive over-engineering for 2-5 person PM conversations. |
| 5D: Flat+Visibility | No ownership authority for participant management operations. |
| 6A: Immutable | Drops the typo-fix use case. Legacy already tracked edits. |
| 6C: Full Edit+Audit | Over-complex for v1. Moderation complications with unrestricted editing. |
| 6D: Delete-For-Everyone | New concept without legacy precedent. Disrupts conversation flow. |
| 7A: Event Atomic Only | No self-healing for counter drift. Proven to be a real problem (PHPBB3-10605). |
| 7B: Computed on Read | Too expensive for header badge query on every page load. |
| 7C: Reconciliation Only | Events still needed for real-time accuracy. Cron-only means stale badges. |
| 8B: Shared Infrastructure | Violates bounded context principle. Cross-service coupling. |
| 8C: Plugin Only | Core moderation need shouldn't be plugin-dependent. |

---

## Deferred Ideas

1. **Dynamic group participants** — When a user is added via group, new group members could auto-join the conversation. Interesting but complex and breaks legacy semantics (groups expanded at add-time). Evaluate in v2.
2. **Smart/dynamic folders** — Folders auto-populated by saved searches or rules (e.g., "All conversations with Alice"). Gmail-like experience. Defer to plugin.
3. **Read receipts / "seen by"** — Show who has read a message in group conversations. Not in legacy. Introduce as optional plugin via `message.read` events.
4. **Conversation search** — Full-text search across all conversations. Important but orthogonal to data model decisions. Defer to search service integration.
5. **Unified moderation dashboard** — Aggregate report queues from messaging + forums + profiles. v2 platform feature.
6. **Message reactions/emoji** — Lightweight response mechanism. Not in legacy scope. Future plugin via events.
7. **Conversation pinning per-forum** — Pin a PM to a forum topic context. Cross-bounded-context feature. Defer.
