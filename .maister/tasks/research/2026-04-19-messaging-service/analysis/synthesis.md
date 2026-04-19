# Messaging Service Research — Synthesis

## Cross-Cutting Patterns Identified

### 1. Dual-Table Architecture (Content vs Per-User State)

The legacy system splits message storage into:
- **`phpbb_privmsgs`** — Single row per message (immutable content after send)
- **`phpbb_privmsgs_to`** — One row per participant (mutable per-user state)

This is effectively a **conversation participant pattern** already. Each user has independent:
- Folder placement
- Read/unread tracking
- Star/important flag
- Delete state
- Reply/forward tracking

**Implication for new design**: This pattern maps well to a `conversation_participants` or `message_participants` table. The separation of content from per-user state is architecturally sound and should be preserved.

### 2. Lazy Rule Evaluation (NOT Real-Time)

Rules execute **on folder view**, not on delivery. Messages sit in `NO_BOX` until the user visits PM. This is a significant architectural choice:
- **Pro**: No performance penalty on send (send is O(recipients), not O(recipients × rules))
- **Pro**: Rule changes affect new unplaced messages immediately
- **Con**: Delayed user experience — message may sit unprocessed for hours
- **Con**: Notifications fire before rules (user gets "new PM" notification, but PM hasn't been categorized yet)

**Implication for new design**: A modern system should evaluate rules at delivery time (event-driven). The lazy evaluation was a pragmatic choice for a pre-queue era, but with event listeners it's trivial to process rules synchronously on message creation.

### 3. Folder Quotas as Hard Limits (Per-Folder)

The quota model is:
- `pm_max_msgs` → per-folder limit (not total per user)
- Overflow handling: delete oldest / hold / redirect to another folder
- Sentbox has auto-cleanup (`clean_sentbox()`)

**Implication**: A modern conversation-based system might not need per-folder quotas. Instead, consider:
- Total message quota per user (across all conversations)
- Archive/cleanup policies per conversation
- Or: Remove quotas entirely (storage is cheap)

### 4. OUTBOX → SENTBOX Transition (Delivery Receipt Semantics)

The OUTBOX/SENTBOX distinction is a **delivery receipt** mechanism:
- OUTBOX = "sent but no recipient has opened their folder yet"
- SENTBOX = "at least one recipient has seen their folder"

This enables "edit while in outbox" (unsent feeling). Once any recipient sees it → locked.

**Implication**: Modern systems typically use:
- "Sent" state (immediate after send)
- "Delivered" state (server confirmed delivery)
- "Read" state (recipient opened)
- The edit-while-unread feature is uncommon in modern messaging but is a phpBB-specific UX that may be worth preserving as an option.

### 5. Flat Threading via `root_level`

Threading is minimal:
- `root_level` points to the root message's `msg_id`
- No parent_id chain, no tree structure
- Reply subjects get `Re:` prefix

**Implication for new design**: A **conversation model** (all messages in a conversation share a `conversation_id`) is the natural evolution. The `root_level` is essentially a proto-conversation-ID.

### 6. Shared Tables (Attachments, Reports, Drafts)

Legacy shares tables with posts:
- `phpbb_attachments` — `in_message` flag discriminates PM vs post
- `phpbb_reports` — `pm_id` column discriminates PM vs post reports
- `phpbb_drafts` — `forum_id = 0 AND topic_id = 0` means PM draft

**Implication for new design**: 
- Attachments → plugin via `phpbb\storage` (already decided)
- Reports → separate `messaging_reports` table (dedicated bounded context)
- Drafts → dedicated `messaging_drafts` or inline in conversation state

### 7. BCC as Privacy Feature (Not Email-Style)

BCC in phpBB means:
- BCC recipients CAN see the message
- BCC recipients are NOT visible to TO recipients
- Author can see all (TO + BCC)
- Reply-all excludes BCC recipients

This is more like a "hidden recipient" than traditional email BCC. In a conversation model, this could map to "silent participant" or "hidden member".

### 8. Group Expansion at Send Time

Groups stored in `to_address` as `g_N` but expanded into individual `privmsgs_to` rows. The group reference is just a historical record of addressing intent.

**Implication**: In a conversation model, consider:
- Option A: Only individual participants (groups are just a convenience for adding multiple people)
- Option B: Group participants that stay dynamic (new group members see conversation)
- Option A matches legacy behavior. Option B is a new feature decision.

---

## Key Design Tensions Identified

### Tension 1: Conversations vs Individual Messages

Legacy is **message-centric** (each message is independent, threaded loosely via `root_level`). Modern messaging is **conversation-centric** (messages live inside conversations).

Decision needed: Full conversation model? Or keep message-centric with better threading?

### Tension 2: Folders vs Labels/Tags

Legacy uses **folders** (exclusive — a message is in exactly one folder). Modern email uses **labels** (non-exclusive — a message can have multiple labels).

Decision needed: Keep exclusive folders? Switch to labels? Support both?

### Tension 3: Delivery-Time vs View-Time Rule Processing

Legacy defers rule processing. Modern systems process immediately.

Decision needed: Synchronous rule evaluation on delivery? Or keep lazy? (Strongly suggests synchronous for new system.)

### Tension 4: Per-Folder Quotas vs Global Quotas

Legacy: 50 messages per folder. Modern: typically unlimited or global storage quota.

Decision needed: Drop per-folder quotas? Global message count limit? No limits?

### Tension 5: Edit-After-Send vs Immutable Messages

Legacy allows edit while in outbox (before any recipient views folder). Most modern messaging treats sent messages as immutable.

Decision needed: Support message editing? If so, what window? (Common in Slack/Discord, uncommon in email-style.)

---

## Functional Decomposition for New Service

Based on analysis, the messaging service naturally decomposes into:

### Core Bounded Contexts

1. **ConversationService** (primary facade)
   - Create conversation, add/remove participants, archive
   - Maps to: threads → conversations

2. **MessageService** (message CRUD within conversations)
   - Send message, edit message, delete message
   - Maps to: submit_pm, edit PM, delete PM

3. **ParticipantService** (per-user state management)
   - Read state, folder placement, starring
   - Maps to: privmsgs_to operations

4. **FolderService** (organizational system)
   - CRUD folders, move messages between folders
   - Maps to: get_folder, move_pm, folder options

5. **RuleService** (filtering/routing engine)
   - CRUD rules, evaluate rules on new messages
   - Maps to: check_rule, place_pm_into_folder rules logic

### Plugin Bounded Contexts (via events/decorators)

6. **Attachments** → `phpbb\storage` plugin (file handling)
7. **Notifications** → Event listener on message.created / message.read
8. **Reporting** → Event listener exposing report flow
9. **Content Formatting** → ContentPipeline plugin (BBCode/markdown)

---

## Data Model Evolution

### Legacy → Modern Mapping

| Legacy Table | Legacy Role | Modern Equivalent |
|---|---|---|
| `phpbb_privmsgs` | Message content | `messaging_messages` (+ `conversation_id` FK) |
| `phpbb_privmsgs_to` | Per-user state | `messaging_participants` (conversation-level) + `messaging_read_state` (message-level) |
| `phpbb_privmsgs_folder` | Custom folders | `messaging_folders` (unchanged concept) |
| `phpbb_privmsgs_rules` | Filter rules | `messaging_rules` (fired at delivery, not view) |
| `root_level` column | Threading | `conversation_id` FK (explicit) |
| `to_address`/`bcc_address` | Recipient record | `messaging_participants.role` (owner/member/hidden) |
| `pm_unread` in privmsgs_to | Read tracking | `messaging_read_state` or cursor-based (last_read_message_id) |

### New Tables (Likely)

1. `messaging_conversations` — Conversation metadata (title, created_at, type)
2. `messaging_messages` — Message content (conversation_id FK, author_id, content, timestamps)
3. `messaging_participants` — Who's in each conversation (role, joined_at, left_at)
4. `messaging_read_cursors` — Per-user read position per conversation (cursor-based, not per-message flag)
5. `messaging_folders` — User's folder structure
6. `messaging_folder_items` — Which conversations are in which folders
7. `messaging_rules` — Filtering rules per user
8. `messaging_drafts` — Draft messages (with recipient info unlike legacy)

---

## Counter/Denormalization Strategy

Legacy maintains denormalized counters scattered across 8+ locations:
- `user_new_privmsg`, `user_unread_privmsg` on users table
- `pm_count` on folders table
- Manual increment/decrement in ~15 code paths

**Recommendation**: Use the same hybrid tiered counter pattern as threads service:
- Hot counters (unread count) in a dedicated counter table with atomic operations
- Cold counters (total messages) derived from counts periodically
- Event listeners maintain counters on message.created / message.read / message.deleted

---

## Integration Points

| Integration | Direction | Mechanism |
|---|---|---|
| `phpbb\user` | Inbound | User identity for participants |
| `phpbb\auth` | Inbound | Permission checks (middleware) |
| `phpbb\storage` | Outbound (plugin) | Attachment file storage |
| `phpbb\threads` | None | Separate bounded context (quotepost crosses boundary) |
| Notifications | Outbound (event) | `message.created` → notification listener |
| Zebra (friend/foe) | Inbound | Blocking/permissions via user service |

---

## Risk Areas

1. **Migration complexity**: 4 tables with complex cross-references. Need careful migration plan.
2. **Performance at scale**: Rules evaluated per-message per-user can be expensive for users with many rules and many messages. Need batching strategy.
3. **Conversation model transition**: Legacy messages may not cleanly group into conversations (some are standalone, some are threaded via root_level).
4. **BCC → Hidden participant**: Need clear semantics for visibility rules when conversations can evolve (participants added later).
5. **Edit semantics in conversations**: If messages are edited, do all participants see the edit? What about read receipts?
