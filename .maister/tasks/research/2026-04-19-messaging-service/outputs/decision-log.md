# Decision Log — phpbb\messaging Service

## ADR-1: Conversation Model → Thread-per-Participant-Set (D)

**Decision**: Unique conversation per unique set of participants (WhatsApp model). Sending a message to the same set of participants auto-routes to the existing conversation.

**Rationale**: Most natural model for 1:1 messaging (the dominant use case). Prevents conversation fragmentation. New messages to same person always land in same thread. Multi-party conversations are unique per participant set.

**Implications**:
- Need canonical participant-set hashing for lookup
- Adding/removing participants creates a NEW conversation (different set = different thread)
- Legacy migration: group by root_level + participant set → conversation

---

## ADR-2: Organization System → Pinned + Archive (C)

**Decision**: Minimal organization — conversations are either active, pinned, or archived. No folders, no labels.

**Rationale**: Simplifies UX dramatically. Modern messaging apps (WhatsApp, Signal, Telegram) don't use folders. Pinned conversations surface frequently-used threads. Archive hides old ones without deletion.

**Implications**:
- `conversation_state` enum: active / pinned / archived (per participant)
- Rules engine: folder-placement actions replaced with archive/pin actions
- Migration: all legacy folder contents → active; starred → pinned
- No folder CRUD needed, no folder quotas

---

## ADR-3: Read Tracking → Hybrid Cursor + Sparse Flags (D)

**Decision**: Cursor-based (`last_read_message_id`) for bulk read tracking, plus sparse overrides for "mark as unread" on specific messages/conversations.

**Rationale**: Cursor is O(1) storage and handles 99% of cases. Sparse flags allow "mark unread" feature (common UX: user marks conversation as unread to revisit later) without reverting the cursor.

**Implications**:
- `messaging_participants.last_read_message_id` — primary cursor
- `messaging_unread_overrides` table — sparse (conversation_id, user_id, marked_unread_at)
- Unread = messages with id > cursor OR conversation has override flag
- Override cleared on next read

---

## ADR-4: Rule Evaluation → Event Listener Sync (C)

**Decision**: `MessageDelivered` event fires synchronously, RuleService listens and applies rules immediately within the same request.

**Rationale**: Decoupled (RuleService is a listener, not in send pipeline), but immediate (user gets correctly categorized message instantly). No queue infrastructure needed. Rules are simple enough to be fast sync.

**Implications**:
- `MessageDelivered` domain event emitted after message insert
- RuleService listener evaluates all user rules against message
- Actions applied: archive, pin, mark-read, delete, block
- No folder-move action (ADR-2 eliminated folders)
- Bounded: rules per user is finite, evaluation is O(rules × 1 message)

---

## ADR-5: Participant Roles → Owner/Member/Hidden (B)

**Decision**: Three-role enum — owner (conversation creator), member (visible participant), hidden (BCC-equivalent, can read/write but invisible to other participants).

**Rationale**: Minimal but sufficient. Owner has "manage" permissions (title, participants). Hidden preserves legacy BCC semantics. No over-engineering with full RBAC for a messaging context.

**Implications**:
- `messaging_participants.role` enum: owner, member, hidden
- Participant list queries filter hidden from non-owner viewers
- Hidden participants can still send messages (visible as "from" but not in participant list)
- Owner can add/remove participants (except themselves)

---

## ADR-6: Message Mutability → Time-Limited Edit (B)

**Decision**: Messages are editable within a configurable time window (default 5 minutes). After the window, messages are immutable. Edit history tracked.

**Rationale**: Matches user expectation from legacy (edit while in outbox). Modern apps (Slack, Discord, Telegram) offer similar. Time window prevents abuse while allowing typo fixes.

**Implications**:
- Config: `messaging_edit_window` (seconds, default 300, 0 = disabled)
- `messaging_messages.edited_at` timestamp (null = never edited)
- `messaging_messages.edit_count` counter
- `messaging_message_edits` table for history (message_id, old_text, edited_at)
- Domain event: `MessageEdited` (for notification plugins)

---

## ADR-7: Counter Strategy → Tiered Hot+Cold (D)

**Decision**: Hot counters (unread conversations, unread messages) in dedicated table with atomic operations via event listeners. Cold stats (total conversations, total messages) computed periodically by cron.

**Rationale**: Proven pattern from threads service. Unread counts must be real-time (user expects instant badge update). Totals can lag. Avoids scattered increment/decrement like legacy.

**Implications**:
- `messaging_counters` table: (user_id, counter_type, value)
- Counter types: unread_conversations, unread_messages
- Event listeners on: MessageDelivered (+1), MessageRead (-1), ConversationArchived (adjust)
- Cron job: reconcile hot counters weekly, compute cold stats

---

## ADR-8: Reporting Model → Plugin via Events (C)

**Decision**: Reporting is a plugin that listens to `MessageReported` event. Core messaging emits the event with message content snapshot. Plugin handles report storage, moderator notification, and resolution flow.

**Rationale**: Keeps core messaging lean. Reporting is a moderation concern, not a messaging concern. Plugin architecture matches the established pattern (events + decorators). Can be independently developed/deployed.

**Implications**:
- Core emits: `MessageReported` event with {conversation_id, message_id, reporter_id, reason, content_snapshot}
- Plugin: `phpbb\messaging\plugin\reporting` (or `phpbb\moderation\messaging_reports`)
- Plugin manages its own table(s) and MCP integration
- Core provides: `MessagingService::getMessageForReport(message_id)` — returns content snapshot
- Moderator access to conversation: via report snapshot, NOT direct conversation access
