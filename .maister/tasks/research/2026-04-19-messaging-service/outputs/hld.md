# High-Level Design: phpbb\messaging Service

## 1. Overview

`phpbb\messaging` is a modern conversation-based messaging service replacing the legacy phpBB privmsgs system. It implements a thread-per-participant-set model (WhatsApp-style) with event-driven architecture, plugin-based extensibility, and clean separation of concerns.

### Design Decisions Summary

| # | Area | Decision |
|---|------|----------|
| 1 | Conversation Model | Thread-per-participant-set (unique conversation per participant set) |
| 2 | Organization | Pinned + Archive (no folders — active/pinned/archived) |
| 3 | Read Tracking | Hybrid cursor + sparse unread overrides |
| 4 | Rule Evaluation | Event listener sync (MessageDelivered → RuleService) |
| 5 | Participant Roles | Owner / Member / Hidden (3-role enum) |
| 6 | Message Mutability | Time-limited edit (configurable window, default 5min) |
| 7 | Counters | Tiered hot+cold (event-driven hot, cron-reconciled cold) |
| 8 | Reporting | Plugin via events (MessageReported event → reporting plugin) |

### Architectural Constraints

- Auth via external middleware (service trusts caller)
- Attachments via `phpbb\storage` plugin (events)
- Content formatting via ContentPipeline plugin (events)
- Event-driven API (domain events as method returns)
- Request/Response decorators for extensibility
- NO legacy extension system
- PSR-4: `phpbb\messaging\*`
- PHP 8.2+, MySQL/MariaDB

---

## 2. Service Architecture

### 2.1 Component Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                         phpbb\messaging                              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌──────────────────────┐    ┌──────────────────────┐              │
│  │   MessagingService   │◄───│   ConversationRepo   │              │
│  │      (Facade)        │    └──────────────────────┘              │
│  └──────┬───────────────┘                                           │
│         │                                                            │
│  ┌──────┼──────────────────────────────────────────────┐           │
│  │      │           Core Services                       │           │
│  │      ▼                                               │           │
│  │  ┌────────────┐  ┌────────────┐  ┌──────────────┐  │           │
│  │  │Conversation│  │  Message   │  │ Participant  │  │           │
│  │  │  Service   │  │  Service   │  │   Service    │  │           │
│  │  └────────────┘  └────────────┘  └──────────────┘  │           │
│  │                                                      │           │
│  │  ┌────────────┐  ┌────────────┐  ┌──────────────┐  │           │
│  │  │   Rule     │  │  Counter   │  │   Draft      │  │           │
│  │  │  Service   │  │  Service   │  │   Service    │  │           │
│  │  └────────────┘  └────────────┘  └──────────────┘  │           │
│  └──────────────────────────────────────────────────────┘           │
│                                                                      │
│  ┌──────────────────────────────────────────────────────┐           │
│  │               Domain Events (Bus)                     │           │
│  │  MessageDelivered │ MessageRead │ MessageEdited       │           │
│  │  ConversationCreated │ ParticipantAdded │ ...         │           │
│  └──────────────────────────────────────────────────────┘           │
│                          ▲                                           │
├──────────────────────────┼──────────────────────────────────────────┤
│  Plugins (Event Listeners / Decorators)                             │
│                          │                                           │
│  ┌───────────┐  ┌───────┴────┐  ┌────────────┐  ┌──────────────┐ │
│  │Attachments│  │Notifications│  │  Reporting │  │  Content     │ │
│  │  Plugin   │  │   Plugin   │  │   Plugin   │  │  Pipeline    │ │
│  └───────────┘  └────────────┘  └────────────┘  └──────────────┘ │
└─────────────────────────────────────────────────────────────────────┘

External Dependencies:
  ← phpbb\user (identity)
  ← phpbb\auth (permissions via middleware)
  ← phpbb\storage (file storage for attachments plugin)
```

### 2.2 Service Responsibilities

| Service | Responsibility |
|---------|---------------|
| `MessagingService` | Public facade. Orchestrates all operations. Single entry point. Depends on TagAwareCacheInterface (`cache.messaging`) for conversation/counter caching. |
| `ConversationService` | Conversation lifecycle: find-or-create, archive, pin, participant set lookup |
| `MessageService` | Message CRUD: send, edit (within window), delete (per-participant) |
| `ParticipantService` | Participant management: add/remove, roles, visibility, blocking |
| `RuleService` | Rule CRUD + evaluation. Listens to `MessageDelivered` event. |
| `CounterService` | Hot counter management. Listens to delivery/read/archive events. |
| `DraftService` | Draft CRUD per user per conversation |

---

## 3. Data Model

### 3.1 Entity Relationship Diagram

```
messaging_conversations
  ├── 1:N messaging_messages
  ├── 1:N messaging_participants (includes read cursor)
  └── M:N users (via participants)

messaging_participants
  ├── has last_read_message_id (cursor)
  └── has state (active/pinned/archived) + role (owner/member/hidden)

messaging_unread_overrides (sparse)
  └── (user_id, conversation_id) → marked_unread

messaging_rules
  └── per user

messaging_counters
  └── per user × counter_type

messaging_drafts
  └── per user per conversation (or null for new conversation)

messaging_message_edits
  └── per message (audit trail)
```

### 3.2 Table Definitions

#### `messaging_conversations`

```sql
CREATE TABLE messaging_conversations (
    conversation_id     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    participant_hash    CHAR(64) NOT NULL,           -- SHA-256 of sorted participant IDs
    title               VARCHAR(255) DEFAULT NULL,   -- NULL = auto-generated from participants
    created_by          INT UNSIGNED NOT NULL,       -- FK → users.user_id
    created_at          INT UNSIGNED NOT NULL,       -- Unix timestamp
    last_message_id     BIGINT UNSIGNED DEFAULT NULL,-- denormalized for sort
    last_message_at     INT UNSIGNED DEFAULT NULL,   -- denormalized for sort
    message_count       INT UNSIGNED NOT NULL DEFAULT 0, -- denormalized counter
    participant_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (conversation_id),
    UNIQUE KEY idx_participant_hash (participant_hash),
    KEY idx_last_message_at (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Key design**: `participant_hash` is a SHA-256 of sorted, deduplicated participant user_ids. This enables O(1) lookup to find existing conversation for a given participant set. Format: `sha256(sort([user_id_1, user_id_2, ...]).join(':'))`.

#### `messaging_messages`

```sql
CREATE TABLE messaging_messages (
    message_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id     BIGINT UNSIGNED NOT NULL,    -- FK → messaging_conversations
    author_id           INT UNSIGNED NOT NULL,       -- FK → users.user_id
    message_text        MEDIUMTEXT NOT NULL,         -- raw content (plugins format)
    message_subject     VARCHAR(255) DEFAULT NULL,   -- optional, first message may have subject
    created_at          INT UNSIGNED NOT NULL,
    edited_at           INT UNSIGNED DEFAULT NULL,   -- NULL = never edited
    edit_count          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    metadata            JSON DEFAULT NULL,           -- extensible (plugins can store data)
    PRIMARY KEY (message_id),
    KEY idx_conversation_time (conversation_id, created_at),
    KEY idx_conversation_id (conversation_id, message_id),
    KEY idx_author (author_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Notes**:
- No BBCode/formatting columns — content pipeline plugin handles rendering
- `metadata` JSON column for plugin-specific data (attachments list, etc.)
- No `to_address`/`bcc_address` — participants are on conversation level

#### `messaging_participants`

```sql
CREATE TABLE messaging_participants (
    conversation_id     BIGINT UNSIGNED NOT NULL,
    user_id             INT UNSIGNED NOT NULL,
    role                ENUM('owner', 'member', 'hidden') NOT NULL DEFAULT 'member',
    state               ENUM('active', 'pinned', 'archived') NOT NULL DEFAULT 'active',
    joined_at           INT UNSIGNED NOT NULL,
    left_at             INT UNSIGNED DEFAULT NULL,   -- NULL = still active
    last_read_message_id BIGINT UNSIGNED DEFAULT NULL, -- read cursor
    last_read_at        INT UNSIGNED DEFAULT NULL,
    is_muted            TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    is_blocked          TINYINT(1) UNSIGNED NOT NULL DEFAULT 0, -- user blocked this convo
    PRIMARY KEY (conversation_id, user_id),
    KEY idx_user_state (user_id, state, left_at),
    KEY idx_user_active (user_id, left_at, last_read_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Key design**:
- `role`: owner manages conversation, hidden = invisible to non-owners (BCC)
- `state`: per-user organization (replaces folders) — active/pinned/archived
- `last_read_message_id`: read cursor — all messages with id <= this are "read"
- `left_at`: soft-leave — participant history preserved, excluded from active queries
- `is_muted`: suppresses notifications (plugin checks this)
- `is_blocked`: user has blocked/left this conversation permanently

#### `messaging_unread_overrides`

```sql
CREATE TABLE messaging_unread_overrides (
    user_id             INT UNSIGNED NOT NULL,
    conversation_id     BIGINT UNSIGNED NOT NULL,
    marked_unread_at    INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Purpose**: Sparse table for "mark as unread" feature. When a user marks a conversation as unread, a row is inserted here. On next read, the row is deleted and cursor advances.

#### `messaging_rules`

```sql
CREATE TABLE messaging_rules (
    rule_id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             INT UNSIGNED NOT NULL,
    rule_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    rule_check          ENUM('sender', 'subject', 'content', 'participant_count', 'sender_group') NOT NULL,
    rule_operator       ENUM('contains', 'not_contains', 'equals', 'not_equals', 'starts_with', 'ends_with', 'is_friend', 'is_foe', 'is_user', 'is_group', 'gt', 'lt') NOT NULL,
    rule_value          VARCHAR(255) NOT NULL DEFAULT '',
    rule_user_id        INT UNSIGNED DEFAULT NULL,
    rule_group_id       INT UNSIGNED DEFAULT NULL,
    rule_action         ENUM('archive', 'pin', 'mark_read', 'mute', 'block', 'delete') NOT NULL,
    is_active           TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    created_at          INT UNSIGNED NOT NULL,
    PRIMARY KEY (rule_id),
    KEY idx_user_active (user_id, is_active, rule_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Changes from legacy**:
- `rule_order` enables manual ordering (legacy had none)
- Actions adapted: no folder-move (no folders), added `archive`, `pin`, `mute`, `block`
- `is_active` flag enables disable-without-delete
- ALL-match semantics preserved (all matching rules fire)
- First state-change wins for conflicting actions (archive vs pin)

#### `messaging_counters`

```sql
CREATE TABLE messaging_counters (
    user_id             INT UNSIGNED NOT NULL,
    counter_type        ENUM('unread_conversations', 'unread_messages') NOT NULL,
    counter_value       INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at          INT UNSIGNED NOT NULL,
    PRIMARY KEY (user_id, counter_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Hot counters**: Updated atomically via event listeners. Reconciled by cron.

#### `messaging_drafts`

```sql
CREATE TABLE messaging_drafts (
    draft_id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id             INT UNSIGNED NOT NULL,
    conversation_id     BIGINT UNSIGNED DEFAULT NULL, -- NULL = new conversation draft
    recipient_ids       JSON DEFAULT NULL,             -- for new conversation: [user_id, ...]
    subject             VARCHAR(255) DEFAULT NULL,
    message_text        MEDIUMTEXT NOT NULL,
    metadata            JSON DEFAULT NULL,             -- plugin data (attachment refs, etc.)
    saved_at            INT UNSIGNED NOT NULL,
    PRIMARY KEY (draft_id),
    KEY idx_user (user_id),
    KEY idx_user_conversation (user_id, conversation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Improvement over legacy**: Stores recipient list for new conversation drafts.

#### `messaging_message_edits`

```sql
CREATE TABLE messaging_message_edits (
    edit_id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id          BIGINT UNSIGNED NOT NULL,
    editor_id           INT UNSIGNED NOT NULL,
    old_text            MEDIUMTEXT NOT NULL,
    edited_at           INT UNSIGNED NOT NULL,
    PRIMARY KEY (edit_id),
    KEY idx_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Purpose**: Audit trail for edits within the time window.

---

## 4. Core Service Interfaces

### 4.1 MessagingService (Facade)

```php
namespace phpbb\messaging;

class MessagingService
{
    // === Conversation Operations ===

    /**
     * Send a message. Auto-creates or finds existing conversation
     * for the given participant set.
     *
     * @return DomainEventCollection Contains MessageSentEvent, ConversationCreatedEvent (if new)
     */
    public function sendMessage(
        int $senderId,
        array $recipientIds,
        string $text,
        ?string $subject = null,
        array $metadata = [],
        array $hiddenRecipientIds = []
    ): DomainEventCollection;

    /**
     * Reply to existing conversation.
     *
     * @return DomainEventCollection Contains MessageSentEvent
     */
    public function replyToConversation(
        int $conversationId,
        int $senderId,
        string $text,
        array $metadata = []
    ): DomainEventCollection;

    /**
     * Edit a message (within edit window).
     *
     * @return DomainEventCollection Contains MessageEditedEvent
     * @throws EditWindowExpiredException
     */
    public function editMessage(
        int $messageId,
        int $editorId,
        string $newText
    ): DomainEventCollection;

    /**
     * Delete message for the calling user (soft delete from their view).
     *
     * @return DomainEventCollection Contains MessageDeletedEvent
     */
    public function deleteMessageForUser(
        int $messageId,
        int $userId
    ): DomainEventCollection;

    // === Conversation Management ===

    /**
     * Get user's conversation list, filtered by state.
     *
     * @return ConversationList {conversations[], total, has_more}
     */
    public function getConversations(
        int $userId,
        string $state = 'active',  // active|pinned|archived
        int $limit = 25,
        ?int $beforeId = null
    ): ConversationList;

    /**
     * Get messages in a conversation (paginated, newest first).
     *
     * @return MessageList {messages[], has_more}
     */
    public function getMessages(
        int $conversationId,
        int $userId,
        int $limit = 50,
        ?int $beforeId = null
    ): MessageList;

    /**
     * Mark conversation as read (advance cursor).
     *
     * @return DomainEventCollection Contains ConversationReadEvent
     */
    public function markAsRead(
        int $conversationId,
        int $userId,
        ?int $upToMessageId = null
    ): DomainEventCollection;

    /**
     * Mark conversation as unread (sparse override).
     *
     * @return DomainEventCollection Contains ConversationUnreadEvent
     */
    public function markAsUnread(
        int $conversationId,
        int $userId
    ): DomainEventCollection;

    // === Organization ===

    public function pinConversation(int $conversationId, int $userId): DomainEventCollection;
    public function unpinConversation(int $conversationId, int $userId): DomainEventCollection;
    public function archiveConversation(int $conversationId, int $userId): DomainEventCollection;
    public function unarchiveConversation(int $conversationId, int $userId): DomainEventCollection;

    // === Participants ===

    public function addParticipant(int $conversationId, int $actorId, int $userId, string $role = 'member'): DomainEventCollection;
    public function removeParticipant(int $conversationId, int $actorId, int $userId): DomainEventCollection;
    public function leaveConversation(int $conversationId, int $userId): DomainEventCollection;
    public function muteConversation(int $conversationId, int $userId): DomainEventCollection;
    public function unmuteConversation(int $conversationId, int $userId): DomainEventCollection;

    // === Drafts ===

    public function saveDraft(int $userId, ?int $conversationId, string $text, ?string $subject = null, ?array $recipientIds = null, array $metadata = []): DomainEventCollection;
    public function getDrafts(int $userId): DraftList;
    public function deleteDraft(int $draftId, int $userId): DomainEventCollection;

    // === Rules ===

    public function getRules(int $userId): RuleList;
    public function addRule(int $userId, array $ruleData): DomainEventCollection;
    public function updateRule(int $ruleId, int $userId, array $ruleData): DomainEventCollection;
    public function deleteRule(int $ruleId, int $userId): DomainEventCollection;
    public function reorderRules(int $userId, array $ruleIds): DomainEventCollection;

    // === Counters ===

    public function getUnreadCounts(int $userId): UnreadCounts;
}
```

### 4.2 DomainEventCollection (replaces Result Objects)

All mutation methods return `DomainEventCollection` per [DOMAIN_EVENTS.md](../../../../docs/standards/backend/DOMAIN_EVENTS.md). Controllers dispatch events after success. Data needed for the HTTP response (e.g., `conversationId`, `messageId`) is carried on the event payloads.

```php
// Controller example:
$events = $this->messagingService->sendMessage($senderId, $recipientIds, $text);
$this->dispatcher->dispatch($events);

// Extract response data from event payload:
$sent = $events->firstOfType(MessageSentEvent::class);
return new JsonResponse(['conversation_id' => $sent->conversationId, 'message_id' => $sent->entityId], 201);
```

### 4.3 Domain Events

All events extend `phpbb\common\Event\DomainEvent` (base fields: `entityId`, `actorId`, `occurredAt`).

```php
namespace phpbb\messaging\Event;

use phpbb\common\Event\DomainEvent;

final readonly class ConversationCreatedEvent extends DomainEvent
{
    public function __construct(
        int $entityId,              // conversation_id
        int $actorId,               // creator_id
        public array $participantIds,
    ) {
        parent::__construct($entityId, $actorId);
    }
}

final readonly class MessageSentEvent extends DomainEvent
{
    public function __construct(
        int $entityId,              // message_id
        int $actorId,               // sender_id
        public int $conversationId,
        public array $recipientIds,
        public ?string $subject,
    ) {
        parent::__construct($entityId, $actorId);
    }
}

final readonly class MessageReadEvent extends DomainEvent
{
    public function __construct(
        int $entityId,              // conversation_id
        int $actorId,               // reader user_id
        public int $lastReadMessageId,
        public int $messagesRead,
    ) {
        parent::__construct($entityId, $actorId);
    }
}

final readonly class MessageEditedEvent extends DomainEvent
{
    public function __construct(
        int $entityId,              // message_id
        int $actorId,               // editor_id
        public int $conversationId,
        public string $oldText,
        public string $newText,
    ) {
        parent::__construct($entityId, $actorId);
    }
}

final readonly class MessageReportedEvent extends DomainEvent
{
    public function __construct(
        int $entityId,              // message_id
        int $actorId,               // reporter_id
        public int $conversationId,
        public int $authorId,
        public string $reason,
        public string $messageSnapshot,
    ) {
        parent::__construct($entityId, $actorId);
    }
}

final readonly class ParticipantAddedEvent extends DomainEvent
{
    public function __construct(
        int $entityId,              // conversation_id
        int $actorId,               // added_by
        public int $userId,
        public string $role,
    ) {
        parent::__construct($entityId, $actorId);
    }
}

final readonly class ParticipantRemovedEvent extends DomainEvent
{
    public function __construct(
        int $entityId,              // conversation_id
        int $actorId,               // removed_by
        public int $userId,
    ) {
        parent::__construct($entityId, $actorId);
    }
}

final readonly class ConversationStateChangedEvent extends DomainEvent
{
    public function __construct(
        int $entityId,              // conversation_id
        int $actorId,               // user_id
        public string $oldState,
        public string $newState,
    ) {
        parent::__construct($entityId, $actorId);
    }
}
```

---

## 5. Core Flows

### 5.1 Send Message (New Conversation or Existing)

```
MessagingService::sendMessage(senderId, recipientIds, text, ...)
│
├── 1. Build participant set = sort(deduplicate([senderId] + recipientIds + hiddenRecipientIds))
├── 2. Compute participant_hash = sha256(participantIds.join(':'))
├── 3. Lookup conversation by participant_hash
│       SELECT conversation_id FROM messaging_conversations
│       WHERE participant_hash = :hash
│
├── [IF NOT FOUND] Create conversation:
│   ├── BEGIN TRANSACTION
│   ├── INSERT messaging_conversations (participant_hash, created_by, ...)
│   ├── INSERT messaging_participants × N (roles assigned)
│   ├── Emit ConversationCreated event
│   └── COMMIT
│
├── 4. Validate sender is active participant
├── 5. Insert message:
│   ├── INSERT messaging_messages (conversation_id, author_id, text, ...)
│   └── UPDATE messaging_conversations SET last_message_id, last_message_at, message_count+1
│
├── 6. Emit MessageDelivered event × (N-1) recipients
│   └── Listeners:
│       ├── RuleService → evaluate rules per recipient
│       ├── CounterService → increment unread_conversations/unread_messages
│       └── [Plugin] NotificationPlugin → send notifications
│
├── 7. Advance sender's read cursor to new message
│       UPDATE messaging_participants SET last_read_message_id = :msgId WHERE user_id = :sender
│
└── 8. Return DomainEventCollection [ConversationCreatedEvent?, MessageSentEvent]
```

### 5.2 Reply to Conversation

```
MessagingService::replyToConversation(conversationId, senderId, text, ...)
│
├── 1. Verify sender is active participant (left_at IS NULL, is_blocked = 0)
├── 2. INSERT message + UPDATE conversation metadata
├── 3. Emit MessageDelivered × active participants (excluding sender)
├── 4. Advance sender's cursor
├── 5. If any participant has state='archived' → auto-unarchive (set state='active')
└── 6. Return DomainEventCollection [MessageSentEvent]
```

### 5.3 Get Conversations (Inbox)

```
MessagingService::getConversations(userId, state='active', limit=25, beforeId=null)
│
├── 1. Query:
│       SELECT c.*, p.state, p.last_read_message_id, p.role, p.is_muted
│       FROM messaging_conversations c
│       JOIN messaging_participants p ON p.conversation_id = c.conversation_id
│       WHERE p.user_id = :userId
│         AND p.state = :state
│         AND p.left_at IS NULL
│         AND (:beforeId IS NULL OR c.last_message_at < (SELECT last_message_at FROM ... WHERE conversation_id = :beforeId))
│       ORDER BY c.last_message_at DESC
│       LIMIT :limit + 1
│
├── 2. For each conversation, compute unread:
│       unread_count = c.last_message_id > p.last_read_message_id
│       OR EXISTS in messaging_unread_overrides
│
├── 3. Load participant preview (first 3-5 participants per conversation)
│
├── 4. Load last message preview text (first 100 chars)
│
└── 5. Return ConversationList
```

### 5.4 Mark as Read

```
MessagingService::markAsRead(conversationId, userId, upToMessageId=null)
│
├── 1. Get current cursor: last_read_message_id
├── 2. Determine target: upToMessageId ?? conversation.last_message_id
├── 3. If target <= current cursor → no-op
├── 4. Count messages read = messages WHERE id > old_cursor AND id <= new_cursor
├── 5. UPDATE messaging_participants SET last_read_message_id = :target, last_read_at = NOW()
├── 6. DELETE FROM messaging_unread_overrides WHERE user_id AND conversation_id
├── 7. Emit MessageRead event (with messagesRead count)
│       └── CounterService → decrement unread counters
└── 8. Return MarkReadResult
```

### 5.5 Rule Evaluation (Event Listener)

```
RuleService::onMessageDelivered(MessageDelivered $event)
│
├── 1. Load active rules for recipient: ORDER BY rule_order
├── 2. Build check context from event:
│       {sender_id, sender_username, subject, content, participant_count, sender_groups}
├── 3. For each rule (ALL-match):
│       ├── Evaluate condition (rule_check + rule_operator + rule_value vs context)
│       └── If match → collect action
│
├── 4. Execute collected actions (order: block > delete > archive > pin > mark_read > mute):
│       ├── block → set is_blocked=1, leave conversation
│       ├── delete → deleteMessageForUser (soft delete)
│       ├── archive → set state='archived'
│       ├── pin → set state='pinned'
│       ├── mark_read → advance cursor
│       └── mute → set is_muted=1
│
└── 5. First state-change wins (archive vs pin → first matching rule's action)
```

### 5.6 Edit Message

```
MessagingService::editMessage(messageId, editorId, newText)
│
├── 1. Load message
├── 2. Verify editor == author
├── 3. Check edit window: (NOW - created_at) <= config['messaging_edit_window']
│       If expired → throw EditWindowExpiredException
├── 4. Store old text in messaging_message_edits
├── 5. UPDATE message: message_text = newText, edited_at = NOW(), edit_count++
├── 6. Emit MessageEdited event
└── 7. Return MessageEditedResult
```

---

## 6. Participant Hash & Conversation Lookup

### 6.1 Hash Generation

The participant hash enables O(1) conversation lookup for a given participant set:

```php
class ConversationService
{
    public function computeParticipantHash(array $userIds): string
    {
        $ids = array_unique(array_map('intval', $userIds));
        sort($ids, SORT_NUMERIC);
        return hash('sha256', implode(':', $ids));
    }
}
```

**Rules**:
- IDs are sorted numerically and deduplicated before hashing
- Hidden participants ARE included in the hash (they're part of the unique set)
- Adding/removing a participant changes the hash → creates a new conversation

### 6.2 Participant Set Mutation

When participants are added or removed, the conversation's `participant_hash` does NOT change. The hash represents the **initial** participant set for lookup purposes. After creation, participants can drift.

**Alternative considered**: Rehash on every participant change → rejected because it would break the "find existing conversation" lookup for the original set.

**Implication**: `participant_hash` is used for the initial "find-or-create" only. Once a conversation exists, adding participants makes it unreachable via hash for the new set. This is by design — the conversation already exists and can be found via `messaging_participants` index.

### 6.3 Edge Cases

- **1:1 conversation**: hash("2:5") → always find same conversation between users 2 and 5
- **Self-message**: hash("2") → conversation with only yourself (notes/saved messages)
- **Adding participant to 1:1**: The conversation evolves into 3-person. Hash stays as original hash("2:5"). New participant finds it via `messaging_participants` join.
- **Leaving a conversation**: `left_at` is set. Hash unchanged. Other participants keep seeing the conversation.

---

## 7. Plugin Integration Points

### 7.1 Event Listeners (Notification Plugin Example)

```php
namespace phpbb\messaging\plugin\notifications;

class NotificationListener
{
    public function onMessageDelivered(MessageDelivered $event): void
    {
        // Check participant's mute/notification preferences
        $participant = $this->participantRepo->get($event->conversationId, $event->recipientId);
        if ($participant->isMuted()) {
            return;
        }

        $this->notificationManager->add('messaging.new_message', [
            'conversation_id' => $event->conversationId,
            'message_id' => $event->messageId,
            'author_id' => $event->authorId,
            'recipient_id' => $event->recipientId,
            'subject' => $event->subject,
        ]);
    }

    public function onMessageRead(MessageRead $event): void
    {
        $this->notificationManager->markRead('messaging.new_message', [
            'conversation_id' => $event->conversationId,
            'user_id' => $event->userId,
        ]);
    }
}
```

### 7.2 Request/Response Decorators (Attachment Plugin Example)

```php
namespace phpbb\messaging\plugin\attachments;

class AttachmentDecorator implements MessageSendDecorator
{
    /**
     * Decorates the send request to process attachment references.
     */
    public function beforeSend(SendMessageRequest $request): SendMessageRequest
    {
        // Validate attachment IDs in metadata
        $attachmentIds = $request->metadata['attachment_ids'] ?? [];
        foreach ($attachmentIds as $id) {
            $this->storageService->claimOrphan($id, $request->senderId);
        }
        return $request;
    }

    /**
     * Decorates the send response to confirm attachment linking.
     */
    public function afterSend(SendMessageRequest $request, DomainEventCollection $events): DomainEventCollection
    {
        $sent = $events->firstOfType(MessageSentEvent::class);
        $attachmentIds = $request->metadata['attachment_ids'] ?? [];
        foreach ($attachmentIds as $id) {
            $this->storageService->linkToEntity($id, 'message', $sent->entityId);
        }
        return $events;
    }
}
```

### 7.3 Reporting Plugin

```php
namespace phpbb\messaging\plugin\reporting;

class ReportingListener
{
    public function onMessageReported(MessageReported $event): void
    {
        // Store report in plugin's own table
        $reportId = $this->reportRepo->create([
            'conversation_id' => $event->conversationId,
            'message_id' => $event->messageId,
            'reporter_id' => $event->reporterId,
            'author_id' => $event->authorId,
            'reason' => $event->reason,
            'message_snapshot' => $event->messageSnapshot,
            'metadata' => $event->metadata,
            'created_at' => $event->timestamp,
        ]);

        // Notify moderators
        $this->notificationManager->add('messaging.report', [
            'report_id' => $reportId,
            'reporter_id' => $event->reporterId,
            'message_author_id' => $event->authorId,
        ]);
    }
}
```

---

## 8. Configuration

```php
// Default configuration values
$messaging_config = [
    'messaging_enabled'         => true,
    'messaging_edit_window'     => 300,      // seconds (0 = disabled)
    'messaging_max_recipients'  => 20,       // max participants per conversation
    'messaging_max_rules'       => 100,      // max rules per user
    'messaging_max_drafts'      => 50,       // max drafts per user
    'messaging_max_message_length' => 10000, // characters
    'messaging_max_subject_length' => 255,
    'messaging_allow_hidden'    => true,     // allow BCC/hidden participants
    'messaging_flood_interval'  => 15,       // seconds between messages
    'messaging_counter_reconcile_interval' => 86400, // daily reconciliation
];
```

---

## 9. Permissions (via phpbb\auth middleware)

| Permission Key | Type | Description |
|---|---|---|
| `u_sendmsg` | user | Can send messages |
| `u_readmsg` | user | Can read messages |
| `u_editmsg` | user | Can edit own messages (within window) |
| `u_deletemsg` | user | Can delete messages from own view |
| `u_massmsg` | user | Can send to multiple recipients |
| `u_massmsg_group` | user | Can send to groups |
| `m_msg_report` | moderator | Can view/handle message reports |
| `a_msg_settings` | admin | Can configure messaging settings |

**Enforcement**: Auth middleware checks permissions before passing request to MessagingService. The service trusts the caller is pre-authorized.

---

## 10. Migration Strategy (Legacy → New)

### 10.1 Data Migration Plan

| Legacy Table | → | New Table | Strategy |
|---|---|---|---|
| `phpbb_privmsgs` | → | `messaging_messages` + `messaging_conversations` | Group by root_level + participant set → conversation |
| `phpbb_privmsgs_to` | → | `messaging_participants` | One row per unique (conversation, user) |
| `phpbb_privmsgs_folder` | → | _(dropped)_ | Custom folders not migrated (no folder concept) |
| `phpbb_privmsgs_rules` | → | `messaging_rules` | Adapt actions: folder-move → archive; delete stays |
| User counters | → | `messaging_counters` | Recompute from cursors |

### 10.2 Conversation Grouping Algorithm

```
1. For each unique root_level value (or single msg_id if root_level=0):
   a. Collect all messages with that root_level
   b. Collect ALL unique user_ids from privmsgs_to for those messages
   c. Compute participant_hash from the full user set
   d. Create conversation with that hash
   e. Insert all messages ordered by message_time
   f. Set participant cursors to their last-read message

2. Handle edge case: Different sub-threads within same root_level
   that have different participant sets → still same conversation
   (matches WhatsApp model: adding people to group doesn't split it)
```

### 10.3 State Migration

| Legacy State | → | New State |
|---|---|---|
| INBOX messages | → | participant.state = 'active' |
| SENTBOX/OUTBOX | → | _(sender is just a participant, always active)_ |
| Custom folder messages | → | participant.state = 'active' |
| Starred (pm_marked=1) | → | participant.state = 'pinned' |
| HOLD_BOX messages | → | participant.state = 'active' (just process them) |
| pm_unread=1 | → | last_read_message_id set to message before this one |
| pm_deleted=1 | → | left_at = migration_timestamp |

---

## 11. Performance Considerations

### 11.1 Hot Paths

| Operation | Expected Frequency | Target Latency |
|---|---|---|
| Get conversations list | Very high | < 10ms |
| Get messages in conversation | High | < 15ms |
| Send message | Medium | < 50ms |
| Mark as read | High | < 5ms |

### 11.2 Index Strategy

- **Conversation list**: `idx_user_state` on participants → covers WHERE user_id + state + left_at
- **Messages in conversation**: `idx_conversation_id` on messages → covers conversation_id + message_id for cursor pagination
- **Participant hash lookup**: UNIQUE index → exact match on conversation creation
- **Counter updates**: PRIMARY KEY on (user_id, counter_type) → direct row update

### 11.3 Denormalization

| Denormalized Field | Source of Truth | Update Trigger |
|---|---|---|
| `conversations.last_message_id` | MAX(messages.message_id) | On message insert |
| `conversations.last_message_at` | MAX(messages.created_at) | On message insert |
| `conversations.message_count` | COUNT(messages) | On message insert/delete |
| `conversations.participant_count` | COUNT(active participants) | On participant add/remove |
| `counters.unread_conversations` | Computed from cursors | Event-driven atomic ops |

### 11.4 Query Optimization

**Conversation list with unread indicator** (single query):
```sql
SELECT c.*, p.state, p.last_read_message_id, p.role, p.is_muted,
       (c.last_message_id > p.last_read_message_id) AS has_unread,
       EXISTS(SELECT 1 FROM messaging_unread_overrides o
              WHERE o.user_id = p.user_id AND o.conversation_id = c.conversation_id) AS force_unread
FROM messaging_conversations c
JOIN messaging_participants p ON p.conversation_id = c.conversation_id
WHERE p.user_id = :userId
  AND p.state = :state
  AND p.left_at IS NULL
ORDER BY c.last_message_at DESC
LIMIT 26;
```

---

## 12. Security Considerations

| Concern | Mitigation |
|---|---|
| SQL injection | All queries parameterized (no interpolation) |
| Unauthorized access | Auth middleware verifies permissions before service call |
| Participant privacy | Hidden participants filtered from response unless viewer is owner |
| Message content in reports | Snapshot copied at report time (moderators don't get live access) |
| Edit abuse | Time window enforced server-side, audit trail preserved |
| Flood protection | Rate limiting via `messaging_flood_interval` config |
| Mass message abuse | Max recipients config + `u_massmsg` permission gate |
| CSRF | Form key validation in controller layer (before service) |
| XSS in message content | Content pipeline plugin handles sanitization on render |

---

## 13. Directory Structure

```
src/phpbb/messaging/
├── MessagingService.php              # Public facade
├── config/
│   └── services.yml                  # DI container configuration
├── service/
│   ├── ConversationService.php       # Conversation lifecycle
│   ├── MessageService.php            # Message CRUD
│   ├── ParticipantService.php        # Participant management
│   ├── RuleService.php               # Rule CRUD + evaluation
│   ├── CounterService.php            # Hot counter operations
│   └── DraftService.php              # Draft CRUD
├── repository/
│   ├── ConversationRepository.php    # Conversation queries
│   ├── MessageRepository.php         # Message queries
│   ├── ParticipantRepository.php     # Participant queries
│   ├── RuleRepository.php            # Rule queries
│   ├── CounterRepository.php         # Counter read/write
│   └── DraftRepository.php           # Draft queries
├── Event/
│   ├── ConversationCreatedEvent.php      # extends DomainEvent
│   ├── MessageSentEvent.php              # extends DomainEvent
│   ├── MessageReadEvent.php              # extends DomainEvent
│   ├── MessageEditedEvent.php            # extends DomainEvent
│   ├── MessageReportedEvent.php          # extends DomainEvent
│   ├── ParticipantAddedEvent.php         # extends DomainEvent
│   ├── ParticipantRemovedEvent.php       # extends DomainEvent
│   └── ConversationStateChangedEvent.php # extends DomainEvent
├── dto/
│   ├── ConversationList.php
│   ├── MessageList.php
│   ├── DraftList.php
│   ├── RuleList.php
│   └── UnreadCounts.php
├── decorator/
│   ├── MessageSendDecorator.php      # Interface
│   └── DecoratorPipeline.php         # Decorator chain runner
├── listener/
│   ├── RuleEvaluationListener.php    # RuleService on MessageDelivered
│   ├── CounterUpdateListener.php     # CounterService on various events
│   └── ConversationActivationListener.php  # Unarchive on new message
├── exception/
│   ├── EditWindowExpiredException.php       # extends phpbb\common\Exception\ConflictException
│   ├── NotParticipantException.php          # extends phpbb\common\Exception\AccessDeniedException
│   ├── ConversationNotFoundException.php    # extends phpbb\common\Exception\NotFoundException
│   ├── MessageNotFoundException.php         # extends phpbb\common\Exception\NotFoundException
│   └── MaxRecipientsExceededException.php   # extends phpbb\common\Exception\ValidationException
└── migration/
    └── LegacyMigrationService.php    # privmsgs → messaging migration
```

---

## 14. Testing Strategy

| Layer | What to Test | Approach |
|---|---|---|
| Unit | Service logic (ConversationService, RuleService) | Mocked repositories |
| Unit | Participant hash generation | Edge cases (ordering, dedup) |
| Unit | Edit window enforcement | Time-based assertions |
| Integration | Full send → deliver → read flow | Real DB, no mocks |
| Integration | Rule evaluation with real data | Real DB |
| Integration | Counter consistency | Verify counts match reality |
| Integration | Migration script | Legacy fixtures → new schema verification |

---

## 15. Open Questions / Future Considerations

1. **Typing indicators**: Real-time "user is typing" — needs WebSocket infrastructure (out of scope for v1)
2. **Read receipts**: Show who has read a message — data exists (cursors), UI decision deferred
3. **Message reactions**: Emoji reactions — can be added as plugin via `metadata` JSON
4. **Search**: Full-text search across messages — may need search index (Elasticsearch/Meilisearch)
5. **Message scheduling**: Send later — could be a queue-based plugin
6. **Conversation titles**: Auto-generated from participants vs user-set — start with auto, allow override for 3+ participants
