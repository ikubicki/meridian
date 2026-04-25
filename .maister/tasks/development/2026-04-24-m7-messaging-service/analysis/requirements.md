# Phase 5: Requirements — M7 Messaging Service

**Source**: Research phase (M7.1) outputs: HLD, decision log, solution exploration, API spec
**Date Gathered**: 2026-04-24
**Status**: Complete (from research artifacts)

---

## 1. Executive Summary

M7 implements a modern conversation-based messaging service as a REST API, following the proven Threads (M6) architectural pattern. The service provides:

- **Data Model**: Conversations (participant sets), Messages (content), Participants (roles & state)
- **API Coverage**: 17 endpoints for CRUD operations + bulk actions
- **Technical Stack**: Symfony 8.x DI, Doctrine DBAL, domain-driven design, event-driven architecture
- **Target Users**: React SPA frontend, mobile clients (REST API consumers)

---

## 2. Functional Requirements (from HLD)

### 2.1 Conversation Management

### FR-1: Create Conversation
- **Actor**: Authenticated user
- **Input**: Array of participant user_ids, optional title
- **Logic**:
  - Calculate participant_hash = SHA-256(sorted unique user_ids)
  - Check if conversation already exists with this hash
  - If exists: return existing conversation (idempotent)
  - If not: create new conversation, add participants, emit ConversationCreatedEvent
  - Owner role = creator; participants default to member role
- **Output**: ConversationDTO + DomainEventCollection

### FR-2: List User's Conversations
- **Actor**: Authenticated user
- **Input**: State filter (active/pinned/archived), pagination context
- **Logic**:
  - Query conversations where user is active participant (left_at IS NULL)
  - Filter by user's state preference
  - Sort by last_message_at DESC (recent first)
- **Output**: PaginatedResult<ConversationDTO>

### FR-3: Get Conversation Details
- **Actor**: Authenticated user (must be participant)
- **Input**: conversation_id
- **Logic**:
  - Verify user is participant (active or left_at)
  - Return full conversation + participant list
- **Output**: ConversationDTO + participants array

### FR-4: Pin Conversation
- **Actor**: Participant
- **Input**: conversation_id
- **Logic**: Update participant.state = 'pinned' (per-user operation)
- **Output**: DomainEventCollection

### FR-5: Archive Conversation
- **Actor**: Participant
- **Input**: conversation_id
- **Logic**: Update participant.state = 'archived'
- **Output**: DomainEventCollection

### FR-6: Delete Conversation (Hard Delete)
- **Actor**: Owner only
- **Input**: conversation_id
- **Logic**: Delete conversation + all messages + participants (cascade)
- **Validation**: Only owner (role='owner') can delete
- **Output**: DomainEventCollection (ConversationDeletedEvent)

### FR-7: Add Participant to Conversation
- **Actor**: Owner
- **Input**: conversation_id, new_user_id
- **Logic**:
  - Add participant with role='member'
  - Update participant_count
  - Emit ParticipantAddedEvent
- **Validation**: User not already participant
- **Output**: DomainEventCollection

### FR-8: Update Participant Role/State
- **Actor**: Owner (role changes) or self (state changes)
- **Input**: conversation_id, user_id, role (enum), state (enum)
- **Logic**: Update participant.role and/or participant.state
- **Output**: DomainEventCollection

### 2.2 Message Management

### FR-9: Send Message
- **Actor**: Active participant
- **Input**: conversation_id, message_text, optional message_subject, optional attachments_metadata
- **Logic**:
  - Insert message (author_id, created_at, content)
  - Update conversation.last_message_at, last_message_id, message_count
  - Reset participant.last_read_message_id = NULL (for others, not sender)
  - Emit MessageDeliveredEvent (listeners handle notifications)
- **Output**: MessageDTO + DomainEventCollection

### FR-10: List Messages in Conversation
- **Actor**: Participant
- **Input**: conversation_id, pagination context (limit, offset)
- **Logic**:
  - Verify user is participant
  - Return messages ordered by created_at ASC (oldest first)
  - Include edit history if available
- **Output**: PaginatedResult<MessageDTO>

### FR-11: Get Single Message
- **Actor**: Participant
- **Input**: message_id
- **Logic**: Return message + edit history
- **Output**: MessageDTO

### FR-12: Edit Message
- **Actor**: Message author only
- **Input**: message_id, new_text
- **Logic**:
  - Validate edit window: (now - created_at) <= edit_window (default 5 min)
  - If within window: UPDATE message, set edited_at=now, increment edit_count
  - If outside window: throw EditWindowExpiredError
  - Emit MessageEditedEvent
- **Output**: MessageDTO + DomainEventCollection

### FR-13: Delete Message (Per-Participant)
- **Actor**: Author or owner
- **Input**: message_id, user_id (who deletes it)
- **Logic**:
  - Message not deleted in DB (soft delete per participant)
  - Insert into messaging_message_deletes (user_id, message_id, deleted_at)
  - On read, filter out deleted messages
- **Output**: DomainEventCollection (MessageDeletedEvent)

### FR-14: Mark Message as Read
- **Actor**: Participant
- **Input**: conversation_id, message_id
- **Logic**:
  - Update participant.last_read_message_id = message_id
  - Update participant.last_read_at = now
  - Emit MessageReadEvent (for counters, notifications)
- **Output**: DomainEventCollection

### FR-15: Search Messages in Conversation
- **Actor**: Participant
- **Input**: conversation_id, search_query, pagination
- **Logic**: Simple LIKE search in message_text (full-text reserved for M8+)
- **Output**: PaginatedResult<MessageDTO>

### 2.3 Participant Management

### FR-16: List Participants in Conversation
- **Actor**: Any participant (or owner can see hidden)
- **Input**: conversation_id
- **Logic**:
  - List all participants with role and state
  - Filter hidden participants (visible to owner only)
- **Output**: Array<ParticipantDTO>

### FR-17: Remove Participant from Conversation
- **Actor**: Owner or self
- **Input**: conversation_id, user_id
- **Logic**:
  - Set participant.left_at = now (soft delete)
  - Do NOT delete row (history preserved)
- **Output**: DomainEventCollection (ParticipantRemovedEvent)

---

## 3. Non-Functional Requirements

### NFR-1: Transactions
- Multi-entity operations must be atomic (message + conversation update, participant + role change)
- Use explicit `BEGIN TRANSACTION`, `COMMIT`, `ROLLBACK` (Doctrine DBAL)

### NFR-2: Concurrency
- Optimistic locking if multiple users edit simultaneously (not required Phase 1)
- Cursor-based pagination to handle concurrent inserts

### NFR-3: Performance
- Conversation lookup by participant_hash = O(1) (unique index)
- Message listing by conversation_id + pagination = O(n) where n=page_size (indexed)
- Participant filters via state (active/archived/pinned) = O(1) with index

### NFR-4: Security
- Authorization at controller level (user must be participant)
- Owner-only operations checked before execution
- SQL injection prevention via prepared statements (mandatory)
- No SQL string interpolation (code review gate)

### NFR-5: Event-Driven
- Service methods return DomainEventCollection (never dispatch internally)
- Controllers dispatch events to event bus after transaction commit
- Event listeners implement plugins (notifications, counting, rules, etc.)

---

## 4. Data Model (Entities & DTOs)

### 4.1 Domain Entities (Internal)

#### Entity: Conversation
```php
class Conversation {
    public int $conversationId;
    public string $participantHash;      // SHA-256 of sorted participant IDs
    public ?string $title;               // NULL = auto-generated
    public int $createdBy;               // user_id of creator
    public int $createdAt;               // Unix timestamp
    public ?int $lastMessageId;          // Denormalized
    public ?int $lastMessageAt;          // Denormalized
    public int $messageCount;            // Denormalized
    public int $participantCount;        // Denormalized
}
```

#### Entity: Message
```php
class Message {
    public int $messageId;
    public int $conversationId;
    public int $authorId;
    public string $messageText;          // Raw content (plugins render)
    public ?string $messageSubject;      // Optional
    public int $createdAt;
    public ?int $editedAt;               // NULL = never edited
    public int $editCount;
    public ?array $metadata;             // JSON: {attachments: [...], ...}
}
```

#### Entity: Participant
```php
class Participant {
    public int $conversationId;
    public int $userId;
    public string $role;                 // 'owner' | 'member' | 'hidden'
    public string $state;                // 'active' | 'pinned' | 'archived'
    public int $joinedAt;
    public ?int $leftAt;                 // NULL = still active
    public ?int $lastReadMessageId;
    public ?int $lastReadAt;
    public bool $isMuted;
    public bool $isBlocked;
}
```

### 4.2 Data Transfer Objects (API)

#### DTO: ConversationDTO
```php
class ConversationDTO implements JsonSerializable {
    public int $id;
    public string $title;
    public int $createdAt;
    public int $lastMessageAt;
    public int $messageCount;
    public int $participantCount;
    public array $participants;          // Array<ParticipantDTO>
    public string $userState;            // Current user's state (active/pinned/archived)
}
```

#### DTO: MessageDTO
```php
class MessageDTO implements JsonSerializable {
    public int $id;
    public int $conversationId;
    public int $authorId;
    public string $authorUsername;
    public string $text;                 // Rendered content (if formatting applied)
    public ?string $subject;
    public int $createdAt;
    public ?int $editedAt;
    public int $editCount;
    public bool $isDeleted;              // True if current user deleted it
}
```

#### DTO: ParticipantDTO
```php
class ParticipantDTO implements JsonSerializable {
    public int $userId;
    public string $username;
    public string $role;                 // 'owner' | 'member' | 'hidden'
    public string $state;                // 'active' | 'pinned' | 'archived'
    public int $joinedAt;
    public ?int $leftAt;
    public int $unreadCount;             // Calculated from last_read_message_id
}
```

### 4.3 Request DTOs

#### CreateConversationRequest
```php
class CreateConversationRequest {
    public array $participantIds;        // Array<int>
    public ?string $title;
}
```

#### SendMessageRequest
```php
class SendMessageRequest {
    public string $text;
    public ?string $subject;
    public ?array $attachments;
}
```

#### UpdateParticipantRequest
```php
class UpdateParticipantRequest {
    public ?string $role;                // 'owner' | 'member'
    public ?string $state;               // 'active' | 'pinned' | 'archived'
}
```

---

## 5. REST API Endpoints (17 Total)

### 5.1 Conversations (8 endpoints)

| Method | Endpoint | Handler | Requires Auth | Returns |
|--------|----------|---------|---------------|---------|
| `GET` | `/api/conversations` | listConversations | ✅ | PaginatedResult<ConversationDTO> |
| `GET` | `/api/conversations/{id}` | getConversation | ✅ | ConversationDTO |
| `POST` | `/api/conversations` | createConversation | ✅ | ConversationDTO |
| `PATCH` | `/api/conversations/{id}/archive` | archiveConversation | ✅ | ConversationDTO |
| `PATCH` | `/api/conversations/{id}/pin` | pinConversation | ✅ | ConversationDTO |
| `PATCH` | `/api/conversations/{id}/unpin` | unpinConversation | ✅ | ConversationDTO |
| `DELETE` | `/api/conversations/{id}` | deleteConversation | ✅ (owner) | 204 No Content |
| `PATCH` | `/api/conversations/{id}/participants` | updateParticipants | ✅ (owner) | ConversationDTO |

### 5.2 Messages (7 endpoints)

| Method | Endpoint | Handler | Requires Auth | Returns |
|--------|----------|---------|---------------|---------|
| `GET` | `/api/conversations/{id}/messages` | listMessages | ✅ | PaginatedResult<MessageDTO> |
| `POST` | `/api/conversations/{id}/messages` | sendMessage | ✅ | MessageDTO |
| `GET` | `/api/messages/{id}` | getMessage | ✅ | MessageDTO |
| `PATCH` | `/api/messages/{id}` | editMessage | ✅ (author) | MessageDTO |
| `DELETE` | `/api/messages/{id}` | deleteMessage | ✅ | 204 No Content |
| `POST` | `/api/messages/{id}/read` | markMessageRead | ✅ | DomainEventCollection |
| `GET` | `/api/conversations/{id}/messages/search` | searchMessages | ✅ | PaginatedResult<MessageDTO> |

### 5.3 Participants (2 endpoints)

| Method | Endpoint | Handler | Requires Auth | Returns |
|--------|----------|---------|---------------|---------|
| `GET` | `/api/conversations/{id}/participants` | listParticipants | ✅ | Array<ParticipantDTO> |
| `PATCH` | `/api/conversations/{id}/participants/{userId}` | updateParticipant | ✅ (owner) | ParticipantDTO |

---

## 6. Service Architecture

### 6.1 Service Layer

**MessagingService (Facade)**
- Single entry point for all messaging operations
- Orchestrates sub-services
- Injects: ConversationService, MessageService, ParticipantService
- Returns: DTOs + DomainEventCollection

**ConversationService**
- Create, list, archive, pin, delete conversations
- Lookup by participant_hash
- Injects: ConversationRepository

**MessageService**
- Send, list, edit, delete, search messages
- Edit window validation
- Injects: MessageRepository, ConversationRepository

**ParticipantService**
- Add, list, remove participants
- Role/state updates
- Injects: ParticipantRepository

**Supporting Services** (deferred Phase 2 of M7):
- RuleService (auto-replies, filters)
- CounterService (unread counts)
- DraftService (auto-save drafts)

### 6.2 Repository Layer

**ConversationRepository**
- findById(conversationId): ?Conversation
- findByParticipantHash(hash): ?Conversation
- listByUser(userId, state, pagination): PaginatedResult
- insert(request): conversationId
- update(conversationId, fields): void
- delete(conversationId): void

**MessageRepository**
- findById(messageId): ?Message
- listByConversation(conversationId, pagination): PaginatedResult
- search(conversationId, query, pagination): PaginatedResult
- insert(message): messageId
- update(messageId, fields): void

**ParticipantRepository**
- findByConversation(conversationId): Array<Participant>
- findByUser(userId): Array<Participant>
- insert(participant): void
- update(conversationId, userId, fields): void
- delete(conversationId, userId): void

---

## 7. Domain Events

**ConversationCreatedEvent**: Emitted when conversation is created
- Carries: conversationId, createdBy, participantIds

**MessageDeliveredEvent**: Emitted when message is sent
- Carries: messageId, conversationId, authorId
- Listeners: Notifications, counting, rule evaluation

**MessageEditedEvent**: Emitted when message is edited
- Carries: messageId, oldText, newText, editCount

**MessageDeletedEvent**: Emitted when message is deleted
- Carries: messageId, userId

**ParticipantAddedEvent**, **ParticipantRemovedEvent**, **ConversationDeletedEvent**: As needed

---

## 8. Specification Acceptance Criteria

### SC-1: Database Schema
- ✅ 5 tables created (conversations, messages, participants, message_deletes, optionally: counters, rules, drafts)
- ✅ Proper indexes on foreign keys and filter columns
- ✅ Constraints enforced (NOT NULL, FK, unique participant_hash)

### SC-2: Service Layer
- ✅ MessagingService interface defined
- ✅ All 17 API operations have corresponding service methods
- ✅ Transactions atomic per operation
- ✅ Events returned (not dispatched)

### SC-3: DTOs & Entities
- ✅ 3 entities (Conversation, Message, Participant) defined
- ✅ 6 DTOs (3 response + 3 request) defined
- ✅ Factory methods (fromEntity, fromRow) implemented

### SC-4: REST API
- ✅ 17 endpoints implemented (8+7+2)
- ✅ Proper HTTP methods and status codes
- ✅ Authorization checks present
- ✅ JSON serialization correct

### SC-5: Tests
- ✅ Unit tests for service methods (20+ tests)
- ✅ Integration tests for repositories (30+ tests)
- ✅ Controller unit tests (17 endpoint tests)
- ✅ E2E tests for full workflows (5+ full scenarios)

### SC-6: Code Quality
- ✅ All prepared statements (no SQL injection)
- ✅ PHPDoc complete
- ✅ Naming conventions followed (phpbb\, camelCase, STANDARDS.md compliance)
- ✅ cs:fix passes (composer cs:fix)

---

## 9. Assumptions & Constraints

### A1: Authentication
- User authentication handled by middleware (external to messaging service)
- Service assumes caller is authenticated (receives user_id in context)

### A2: Content Formatting
- Message storage is raw text (plugins handle formatting)
- Rendering delegated to ContentPipeline plugin

### A3: Attachments
- Metadata stored as JSON in message.metadata column
- File storage handled by phpbb\storage plugin via event listener

### A4: Permissions
- Basic ownership checks (owner role, authorship)
- Advanced permissions (role-based) reserved for M8+

### A5: Edit Window
- Configurable globally (default 5 min)
- Enforced at message level (no editing after window)

### A6: Participant Hash
- SHA-256 of sorted, deduplicated user_id list
- Example: users [2, 1, 3] → sorted [1, 2, 3] → hash(1:2:3)
- Enables O(1) lookup for existing conversations

---

## 10. Out of Scope (M7)

- ❌ Full-text search with relevance scoring (use simple LIKE)
- ❌ Advanced counter reconciliation (cron-based deferred to M8+)
- ❌ Draft message persistence (CRUD structure ready but not UI integrated)
- ❌ Message reporting/moderation (event infrastructure ready)
- ❌ Notification system (event listener ready, notification logic deferred)
- ❌ Mobile push notifications (depends on M8 notification service)
- ❌ Message reactions, threaded replies (future enhancements)

---

## 11. Success Criteria (Phase Completion)

✅ **Functional**: All 17 endpoints implemented and tested
✅ **Integration**: DI registration complete, routes working
✅ **Quality**: Tests passing (unit, integration, E2E)
✅ **Standards**: Code follows STANDARDS.md, cs:fix clean
✅ **Documentation**: OpenAPI spec verified, user guide generated

---

**Status**: Requirements complete, ready for Phase 6 (Specification Audit).
