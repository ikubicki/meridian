# Research Brief: phpbb\messaging Service

## Research Question

How to design `phpbb\messaging` service that:
1. Replaces the legacy privmsgs system with a modern conversation-based architecture
2. Supports folders, filtering rules, multi-recipient (TO/BCC), read tracking
3. Integrates with `phpbb\storage` for attachments (as plugin)
4. Provides notification hooks, reporting, and quota management
5. Follows the established event-driven, plugin-friendly architecture

## Research Type

**Technical** — codebase extraction + service design

## Context

### Prior Services Designed
- `phpbb\user` — user management (IMPLEMENTATION_SPEC.md)
- `phpbb\auth` — authorization (HLD complete)
- `phpbb\hierarchy` — forum tree/nested set (HLD complete)
- `phpbb\threads` — topics/posts with plugin content pipeline (HLD complete)
- `phpbb\storage` — file assets, quotas, Flysystem (HLD complete)

### Architecture Decisions (binding)
- **Event-driven API**: Methods return domain events
- **Auth via middleware**: Service trusts the caller
- **Request/Response Decorators**: Plugin extensibility
- **NO legacy extension system**: No service_collection, no tagged services
- **PSR-4**: `phpbb\messaging\` → `src/phpbb/messaging/`
- **Attachments**: Via `phpbb\storage` plugin (not built into messaging core)

### Legacy System Overview
- **4 DB tables**: phpbb_privmsgs, phpbb_privmsgs_to, phpbb_privmsgs_folder, phpbb_privmsgs_rules
- **Core function**: `submit_pm()` in `functions_privmsgs.php` (~200 LOC)
- **Folder constants**: INBOX(0), SENTBOX(-1), OUTBOX(-2), NO_BOX(-3), HOLD_BOX(-4), custom(1+)
- **Threading**: `root_level` column for conversation chains
- **Soft-delete**: `pm_deleted` flag per-recipient (each user sees own copy)
- **Rules engine**: 5 check types × 15 connection types × 4 actions
- **Recipient model**: TO + BCC, serialized in `to_address`/`bcc_address`
- **Limits**: pm_max_msgs(50), pm_max_boxes(4), pm_max_recipients(0/unlimited)
- **Notifications**: Integrates with phpBB notification system (notification.type.pm)

## Scope

### Included
- Conversation/message model (replacing flat PM list)
- Folder system (system folders + custom user folders)
- Filtering/routing rules (automatic folder placement)
- Multi-participant conversations (TO/BCC or group convos)
- Read/unread tracking per participant
- Message editing, forwarding, quoting
- Draft management (PM drafts)
- Quota management (message count limits, storage when attachments involved)
- Notification events (for notification system to consume)
- Reporting mechanism (flag message to moderators)
- User blocking/ignore integration
- Conversation archiving

### Excluded
- File attachment storage (→ `phpbb\storage` via plugin)
- Email delivery (→ notification infrastructure)
- Permission checks (→ `phpbb\auth` middleware)
- User profiles/presence (→ `phpbb\user`)
- Real-time/WebSocket delivery (future)
- Full-text search (future)

## Key Design Questions

1. **Conversation model**: Flat messages (legacy) vs threaded conversations vs chat-like model?
2. **Participant model**: TO/BCC (email-like) vs conversation participants (chat-like)?
3. **Storage**: Per-user copies (legacy: each recipient has own) vs shared message + per-user metadata?
4. **Folders vs Labels**: Traditional folders (one location) vs labels/tags (multiple)?
5. **Rules engine**: Keep legacy 5×15×4 matrix or simplify?
6. **Content pipeline**: Use same `phpbb\threads` ContentPipeline for PM text, or separate?
7. **Group messaging**: How to handle sending to groups (expand members)?
8. **Soft-delete semantics**: When all participants delete, hard-delete? Reference counting?

## Success Criteria

1. Clean conversation model supporting 1:1 and group messaging
2. Folder system with custom folders and message limits
3. Rules engine for automatic routing/marking
4. Event catalog enabling notifications, attachments, search plugins
5. Integration contracts with storage, user, auth services
6. Backward-compatible migration path from legacy privmsgs
7. Quota system for message count and storage
8. Reporting workflow for moderation
