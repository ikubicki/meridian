# Research Report: phpbb\messaging Service

## Executive Summary

The legacy phpBB private messaging system (`functions_privmsgs.php` ~2400 LOC, 4 DB tables, 16+ functions) implements a message-centric architecture with per-user state tracking, folder organization, lazy rule evaluation, and denormalized counters. The system is functional but monolithic, with tight coupling between message lifecycle, folder management, rule processing, and notification dispatch.

The new `phpbb\messaging` service should adopt a **conversation-centric model** that preserves the successful dual-table pattern (content vs per-user state) while modernizing threading (explicit conversations), rule evaluation (delivery-time), and extensibility (event-driven plugins).

---

## 1. Legacy System Architecture

### 1.1 Database Structure

**4 core tables** + 3 shared tables:

| Table | Rows (typical) | Purpose |
|-------|----------------|---------|
| `phpbb_privmsgs` | 1 per message | Message content (subject, body, BBCode, timestamps, author) |
| `phpbb_privmsgs_to` | N per message | Per-recipient state (folder, read, starred, deleted) |
| `phpbb_privmsgs_folder` | 0-4 per user | Custom user folders (beyond system folders) |
| `phpbb_privmsgs_rules` | 0-5000 per user | Filtering/routing rules |

**Shared with posts**: `phpbb_attachments` (via `in_message` flag), `phpbb_reports` (via `pm_id`), `phpbb_drafts` (via `forum_id=0`)

### 1.2 Message Lifecycle

```
Compose → submit_pm() [transactional]
  ├── Validate recipients (expand groups, check permissions)
  ├── INSERT phpbb_privmsgs (content)
  ├── INSERT phpbb_privmsgs_to × N recipients (folder=NO_BOX, pm_new=1)
  ├── INSERT phpbb_privmsgs_to × 1 sender (folder=OUTBOX)
  ├── UPDATE user counters (user_new_privmsg++, user_unread_privmsg++)
  ├── Process attachments (claim orphans)
  ├── Delete draft (if from draft)
  └── Fire notifications

Recipient views folder → place_pm_into_folder() [lazy]
  ├── Load unplaced messages (folder=NO_BOX)
  ├── Evaluate rules (if user_message_rules=1)
  ├── Execute actions: delete / mark read / mark important / move to folder
  ├── Default: move to INBOX
  ├── Handle folder overflow (delete oldest / hold / redirect)
  └── Move sender's OUTBOX copy → SENTBOX

Read → update_unread_status()
  ├── SET pm_unread=0 on privmsgs_to row
  ├── Decrement user_unread_privmsg
  └── Mark notification as read

Delete → delete_pm()
  ├── Remove user's privmsgs_to row
  ├── If no rows remain → DELETE physical message from phpbb_privmsgs
  └── Clean up notifications, attachments
```

### 1.3 Threading Model

- **Flat**: `root_level` column on `phpbb_privmsgs` points to root message ID
- 0 = root message (new thread/conversation)
- Non-zero = reply in thread
- No parent_id, no tree depth, no ordering guarantees
- Subject prefix `Re:` / `Fwd:` is the only visible threading cue

### 1.4 Folder System

**5 system folders** (virtual, not stored in folder table):
| ID | Name | Purpose |
|----|------|---------|
| 0 | INBOX | Default destination for received messages |
| -1 | SENTBOX | Sent messages (after at least one recipient viewed) |
| -2 | OUTBOX | Sent but not yet delivered (editable) |
| -3 | NO_BOX | Staging area (temp before rule processing) |
| -4 | HOLD_BOX | Held messages (when destination full) |

**Custom folders**: max 4 per user (`pm_max_boxes`), denormalized `pm_count`.

**Quota**: 50 messages per folder (`pm_max_msgs`), enforced independently per folder.

### 1.5 Rules Engine

- **14 connection operators** × **5 check types** × **4 actions**
- **ALL-match semantics**: every rule is evaluated (not first-match-stops)
- **First folder-move wins**: multiple move actions → only first takes effect
- **Non-folder actions stack**: mark-read + mark-important can both fire
- **Admin/mod protection**: auto-delete rules cannot remove admin/mod messages
- **No editing**: delete and recreate only
- **Max 5000 rules** per user (hardcoded)
- **Evaluated lazily** at folder view time, NOT at delivery

### 1.6 Participant Model

- **TO + BCC** addressing (colon-delimited serialization: `u_2:u_5:g_3`)
- **Groups expanded at send time** into individual `privmsgs_to` rows
- **BCC is blind**: TO recipients cannot see BCC; author sees all
- **Reply-all excludes BCC** (only TO header rebuilt)
- **Sender gets their own `privmsgs_to` row** (OUTBOX/SENTBOX tracking)
- **Blocking via rules** (foe list), NOT at send time — no "blocked" error
- **Max recipients**: per-group override → global config fallback

### 1.7 Notifications & Reporting

**3 notification types**:
1. `notification.type.pm` — New PM → each recipient
2. `notification.type.report_pm` — PM reported → moderators with `m_pm_report`
3. `notification.type.report_pm_closed` — Report closed → original reporter

**3 delivery methods**: board (popup), email (`privmsg_notify` template), jabber

**Report flow**: User reports → `phpbb_reports` row + PM text snapshot + ANONYMOUS `privmsgs_to` row → moderators review in MCP → close/delete

**Privacy**: Moderators CAN read reported PM content (stored in report). Reported user is NEVER notified.

---

## 2. Design Questions Answered

### Q1: What is the optimal data model for conversations?

**Finding**: The legacy system already has a proto-conversation model via `root_level`. A full conversation model (`messaging_conversations` table) with explicit `conversation_id` FK on messages is the natural evolution. The dual-table pattern (content + per-user state) should be preserved as it's architecturally sound.

### Q2: How should the folder/organization system work?

**Finding**: Legacy folders are exclusive (one folder per message per user). The system supports 5 system folders + 4 custom. With conversations as the organizing unit (not individual messages), folders could organize **conversations** rather than messages. Labels/tags are an alternative but break backward mental model.

### Q3: How should the rules engine be designed?

**Finding**: Legacy evaluates lazily (on view) with ALL-match semantics. Modern approach: evaluate at delivery time via event listener on `message.created`. Keep ALL-match for non-folder actions (mark-read + mark-important stack), first-match for folder placement. Add admin/global rules capability.

### Q4: How should participants and visibility work?

**Finding**: Legacy has TO/BCC with group expansion. In a conversation model: participants have roles (owner, member, hidden). "Hidden" replaces BCC semantics. Group expansion should remain at-add-time (not dynamic membership).

### Q5: How should read tracking work?

**Finding**: Legacy uses per-message `pm_unread` flag. For conversations: **cursor-based** (`last_read_message_id` per user per conversation) is more efficient — one row per participant instead of one flag per message per user.

### Q6: How should drafts work?

**Finding**: Legacy drafts lack recipient storage. New drafts should include: conversation_id (for reply drafts), recipient list (for new conversation drafts), subject, body, metadata.

### Q7: How should reporting/moderation work?

**Finding**: Legacy copies full message text to report table + grants ANONYMOUS access. New system: dedicated `messaging_reports` table. Report should reference conversation + message by ID. Moderator access via report context (not general PM access).

### Q8: How should counters/counts work?

**Finding**: Legacy has scattered increment/decrement across 15+ code paths. New system: dedicated counter service with event-driven maintenance. Two counters per user: unread_conversations, total_conversations. Counter updates via event listeners on message.created / message.read / conversation.archived.

---

## 3. Architectural Constraints (from prior decisions)

| Constraint | Source |
|---|---|
| Attachments via `phpbb\storage` plugin | User decision (threads research) |
| Content formatting via ContentPipeline plugin | User decision (threads research) |
| Auth via external middleware (service trusts caller) | User decision (all services) |
| Event-driven API (domain events as return values) | User decision (all services) |
| Request/Response decorators for plugins | User decision (all services) |
| NO legacy extension system | User decision |
| PSR-4 namespace: `phpbb\messaging` | Convention |
| Dependency injection via Symfony container | Convention |
| Parameterized SQL queries only | Security requirement |

---

## 4. Key Metrics from Legacy System

| Metric | Value |
|---|---|
| Source files analyzed | 15+ |
| Total LOC investigated | ~8,000 |
| DB tables | 4 core + 3 shared |
| phpBB events in PM flow | 13 |
| Permission keys | 14 (13 user + 1 moderator) |
| Config keys | 9 |
| Notification types | 3 |
| Rule check types | 5 |
| Rule operators | 14 |
| Rule actions | 4 |
| System folders | 5 |
| Max custom folders | 4 |
| Max messages per folder | 50 (default) |
| Max rules per user | 5000 |

---

## 5. Recommendations for Brainstorming Phase

Based on the research, the following **8 design decision areas** should be explored:

1. **Conversation model**: Single-participant conversations vs multi-participant vs hybrid
2. **Folder/organization**: Exclusive folders (legacy) vs labels/tags vs both
3. **Read tracking**: Per-message flags vs cursor-based vs hybrid
4. **Rule evaluation timing**: Delivery-time (sync) vs async (queue) vs lazy (legacy)
5. **Participant roles**: Simple (sender/recipient) vs rich (owner/admin/member/hidden/muted)
6. **Message mutability**: Immutable after send vs time-limited edit vs unlimited edit
7. **Counter strategy**: Inline updates vs event-driven vs periodic reconciliation
8. **Reporting model**: Shared with forums vs dedicated messaging reports
