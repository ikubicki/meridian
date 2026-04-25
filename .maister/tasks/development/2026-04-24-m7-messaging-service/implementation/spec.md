# Phase 5: Specification — M7 Messaging Service

**Date**: 2026-04-24
**Status**: Approved for Implementation
**Based on**: Research phase (HLD, decision log, API spec) + Threads pattern (M6 reference)

---

## Executive Summary

**M7 Messaging Service** (`phpbb\messaging`) implements a modern, event-driven messaging system as the foundational inbox/conversation service for phpBB4 Meridian. It provides:

- **17 REST API endpoints** for conversation management, message operations, and participant control
- **Domain-driven service layer** with explicit transaction management and event sourcing
- **3 domain entities** (Conversation, Message, Participant) following the Threads (M6) architecture
- **Full test coverage** (60-80 test methods) across unit, integration, and E2E
- **Plugin-based extensibility** via domain events (notifications, rules, content formatting, attachments)

**Architecture**: Symfony 8.x DI + Doctrine DBAL + Event-driven + PSR-4 (`phpbb\messaging\*`)
**Database**: 5 tables (conversations, messages, participants, message_deletes, counters)
**Language**: PHP 8.2+
**Delivery Timeline**: Phase 7 planning + Phase 8 implementation (~24-30 hours)

---

## 1. Scope & Deliverables

### 1.1 In Scope (M7 Batch 1)

| Component | Scope | Delivery |
|-----------|-------|----------|
| **Database schema** | 5 tables + indexes + constraints | DDL statements |
| **Domain entities** | Conversation, Message, Participant | 3 Entity classes |
| **DTOs** | ConversationDTO, MessageDTO, ParticipantDTO + Request DTOs | 6 DTO classes |
| **Repositories** | ConversationRepository, MessageRepository, ParticipantRepository | 3 Repository classes + interfaces |
| **Services** | MessagingService facade + 3 helper services | 4 Service classes + ContractInterfaces |
| **REST Controllers** | ConversationsController, MessagesController, ParticipantsController | 3 controllers (17 endpoints) |
| **Domain Events** | ConversationCreated, MessageDelivered, MessageEdited, etc. | 7+ Event classes |
| **Tests** | Unit, integration, controller tests | ~60-80 test methods |
| **E2E Tests** | Browser workflows (Playwright) | ~5 full scenarios |
| **DI Registration** | services.yaml entries + route definitions | Configuration |

### 1.2 Out of Scope (M8+)

- Full-text search with relevance
- Advanced counter reconciliation (hot+cold tiering)
- Draft persistence + UI integration
- Message reporting/moderation UI
- Notification system (infrastructure ready)
- Permission system beyond ownership

---

## 2. Database Design

### 2.1 Entity Relationship Diagram

```
messaging_conversations (1) ──┬──── (N) messaging_messages
                              └──── (N) messaging_participants
                              
messaging_participants ────────────── users (via user_id FK)

messaging_messages ─────────────── users (via author_id FK)

messaging_message_deletes ────── messaging_messages (soft-delete per participant)
```

### 2.2 Table Definitions

#### Table: `messaging_conversations`

```sql
CREATE TABLE messaging_conversations (
    conversation_id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    participant_hash            CHAR(64) NOT NULL COMMENT 'SHA-256 of sorted participant IDs',
    title                       VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    created_by                  INT UNSIGNED NOT NULL,
    created_at                  INT UNSIGNED NOT NULL,
    last_message_id             BIGINT UNSIGNED DEFAULT NULL,
    last_message_at             INT UNSIGNED DEFAULT NULL,
    message_count               INT UNSIGNED NOT NULL DEFAULT 0,
    participant_count           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    
    PRIMARY KEY (conversation_id),
    UNIQUE KEY uidx_participant_hash (participant_hash),
    KEY idx_last_message_at (last_message_at DESC),
    KEY idx_created_by (created_by),
    
    CONSTRAINT fk_conversations_created_by FOREIGN KEY (created_by) 
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Conversations (thread-per-participant-set model)';
```

**Design Notes**:
- `participant_hash`: Enables O(1) find-or-create for participant sets
- `last_message_at`: Denormalized for efficient sorting
- Unique index on `participant_hash` prevents duplicate conversations

#### Table: `messaging_messages`

```sql
CREATE TABLE messaging_messages (
    message_id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id             BIGINT UNSIGNED NOT NULL,
    author_id                   INT UNSIGNED NOT NULL,
    message_text                MEDIUMTEXT NOT NULL COLLATE utf8mb4_unicode_ci,
    message_subject             VARCHAR(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    created_at                  INT UNSIGNED NOT NULL,
    edited_at                   INT UNSIGNED DEFAULT NULL,
    edit_count                  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    metadata                    JSON DEFAULT NULL COMMENT 'Extensible: attachments, etc.',
    
    PRIMARY KEY (message_id),
    KEY idx_conversation_time (conversation_id, created_at),
    KEY idx_conversation_id (conversation_id, message_id),
    KEY idx_author (author_id),
    
    CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) 
        REFERENCES messaging_conversations(conversation_id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_author FOREIGN KEY (author_id) 
        REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Messages (no BBCode/formatting here, plugins handle rendering)';
```

**Design Notes**:
- `message_text`: Raw content (plugins render via events)
- `metadata`: JSON column for plugin extensibility (attachments, custom fields)
- Indexes on (conversation_id, created_at) for efficient pagination

#### Table: `messaging_participants`

```sql
CREATE TABLE messaging_participants (
    conversation_id             BIGINT UNSIGNED NOT NULL,
    user_id                     INT UNSIGNED NOT NULL,
    role                        ENUM('owner', 'member', 'hidden') NOT NULL DEFAULT 'member',
    state                       ENUM('active', 'pinned', 'archived') NOT NULL DEFAULT 'active',
    joined_at                   INT UNSIGNED NOT NULL,
    left_at                     INT UNSIGNED DEFAULT NULL COMMENT 'NULL = still active',
    last_read_message_id        BIGINT UNSIGNED DEFAULT NULL,
    last_read_at                INT UNSIGNED DEFAULT NULL,
    is_muted                    TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    is_blocked                  TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
    
    PRIMARY KEY (conversation_id, user_id),
    KEY idx_user_state (user_id, state, left_at),
    KEY idx_user_active (user_id, left_at),
    
    CONSTRAINT fk_participants_conversation FOREIGN KEY (conversation_id) 
        REFERENCES messaging_conversations(conversation_id) ON DELETE CASCADE,
    CONSTRAINT fk_participants_user FOREIGN KEY (user_id) 
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-user conversation state (read cursors, roles, organization)';
```

**Design Notes**:
- Composite PK (conversation_id, user_id): One row per user per conversation
- `role`: 'hidden' for BCC-style participants (visible to owner only)
- `state`: Per-user organization (replaces folders) — active/pinned/archived
- `last_read_message_id`: Read cursor — all messages with id ≤ this are "read"
- Indexes enable efficient user conversation queries

#### Table: `messaging_message_deletes` (Soft Delete Tracking)

```sql
CREATE TABLE messaging_message_deletes (
    conversation_id             BIGINT UNSIGNED NOT NULL,
    message_id                  BIGINT UNSIGNED NOT NULL,
    user_id                     INT UNSIGNED NOT NULL,
    deleted_at                  INT UNSIGNED NOT NULL,
    
    PRIMARY KEY (conversation_id, message_id, user_id),
    
    CONSTRAINT fk_msg_deletes_conversation FOREIGN KEY (conversation_id) 
        REFERENCES messaging_conversations(conversation_id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_deletes_message FOREIGN KEY (message_id) 
        REFERENCES messaging_messages(message_id) ON DELETE CASCADE,
    CONSTRAINT fk_msg_deletes_user FOREIGN KEY (user_id) 
        REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Per-participant soft-delete tracking (message visible again if undeleted)';
```

**Design Notes**:
- Soft delete: Messages not deleted in DB, tracked in separate table
- Query filter: WHERE (conversation_id, message_id, user_id) NOT IN (SELECT ...)
- Enables "undelete" in future (restore from this table)

---

## 3. Service Architecture

### 3.1 Service Layer Interfaces

#### MessagingServiceInterface (Facade)

```php
namespace phpbb\messaging\Contract;

use phpbb\api\DTO\PaginationContext;
use phpbb\common\Event\DomainEventCollection;
use phpbb\messaging\DTO\{ConversationDTO, MessageDTO, ParticipantDTO};
use phpbb\messaging\DTO\Request\{CreateConversationRequest, SendMessageRequest};
use phpbb\user\DTO\PaginatedResult;

interface MessagingServiceInterface
{
    // Conversations
    public function listConversations(int $userId, ?string $state, PaginationContext $ctx): PaginatedResult; // List with state filter
    public function getConversation(int $conversationId, int $userId): ConversationDTO;
    public function createConversation(CreateConversationRequest $request, int $userId): DomainEventCollection;
    public function archiveConversation(int $conversationId, int $userId): DomainEventCollection;
    public function pinConversation(int $conversationId, int $userId): DomainEventCollection;
    public function unpinConversation(int $conversationId, int $userId): DomainEventCollection;
    public function deleteConversation(int $conversationId, int $userId): DomainEventCollection; // Owner only
    
    // Messages
    public function listMessages(int $conversationId, int $userId, PaginationContext $ctx): PaginatedResult;
    public function getMessage(int $messageId, int $userId): MessageDTO;
    public function sendMessage(int $conversationId, SendMessageRequest $request, int $userId): DomainEventCollection;
    public function editMessage(int $messageId, string $newText, int $userId): DomainEventCollection;
    public function deleteMessage(int $messageId, int $userId): DomainEventCollection;
    public function markMessageRead(int $messageId, int $conversationId, int $userId): DomainEventCollection;
    public function searchMessages(int $conversationId, string $query, PaginationContext $ctx): PaginatedResult;
    
    // Participants
    public function listParticipants(int $conversationId, int $userId): array; // Returns Array<ParticipantDTO>
    public function addParticipant(int $conversationId, int $newUserId, int $userId): DomainEventCollection; // Owner only
    public function removeParticipant(int $conversationId, int $targetUserId, int $userId): DomainEventCollection;
    public function updateParticipantRole(int $conversationId, int $targetUserId, string $role, int $userId): DomainEventCollection;
}
```

### 3.2 Repository Layer Interfaces

#### ConversationRepositoryInterface

```php
namespace phpbb\messaging\Contract;

use phpbb\api\DTO\PaginationContext;
use phpbb\messaging\DTO\Request\CreateConversationRequest;
use phpbb\messaging\Entity\Conversation;
use phpbb\user\DTO\PaginatedResult;

interface ConversationRepositoryInterface
{
    public function findById(int $conversationId): ?Conversation;
    public function findByParticipantHash(string $hash): ?Conversation;
    public function listByUser(int $userId, ?string $state, PaginationContext $ctx): PaginatedResult;
    public function insert(CreateConversationRequest $request, int $createdBy): int; // Returns conversation_id
    public function update(int $conversationId, array $fields): void;
    public function delete(int $conversationId): void;
}
```

#### MessageRepositoryInterface

```php
namespace phpbb\messaging\Contract;

use phpbb\api\DTO\PaginationContext;
use phpbb\messaging\DTO\Request\SendMessageRequest;
use phpbb\messaging\Entity\Message;
use phpbb\user\DTO\PaginatedResult;

interface MessageRepositoryInterface
{
    public function findById(int $messageId): ?Message;
    public function listByConversation(int $conversationId, PaginationContext $ctx): PaginatedResult;
    public function search(int $conversationId, string $query, PaginationContext $ctx): PaginatedResult;
    public function insert(int $conversationId, SendMessageRequest $request, int $authorId): int; // Returns message_id
    public function update(int $messageId, array $fields): void;
    public function deletePerUser(int $messageId, int $userId): void;  // Soft delete per participant
    public function isDeletedForUser(int $messageId, int $userId): bool;
}
```

#### ParticipantRepositoryInterface

```php
namespace phpbb\messaging\Contract;

use phpbb\messaging\Entity\Participant;

interface ParticipantRepositoryInterface
{
    public function findByConversation(int $conversationId): array; // Returns Array<Participant>
    public function findByUser(int $userId): array; // All conversations for user
    public function insert(int $conversationId, int $userId, string $role = 'member'): void;
    public function update(int $conversationId, int $userId, array $fields): void;
    public function delete(int $conversationId, int $userId): void;
    public function findByConversationAndUser(int $conversationId, int $userId): ?Participant;
}
```

### 3.3 Service Implementation Strategy

**MessagingService**:
```php
class MessagingService implements MessagingServiceInterface
{
    public function __construct(
        private readonly ConversationRepositoryInterface $conversationRepo,
        private readonly MessageRepositoryInterface $messageRepo,
        private readonly ParticipantRepositoryInterface $participantRepo,
        private readonly Connection $connection,
    ) {}
    
    public function createConversation(CreateConversationRequest $request, int $userId): DomainEventCollection
    {
        $events = new DomainEventCollection();
        $this->connection->beginTransaction();
        
        try {
            $hash = $this->hashParticipants($request->participantIds);
            
            // Find-or-create
            $existing = $this->conversationRepo->findByParticipantHash($hash);
            if ($existing !== null) {
                $this->connection->commit();
                return $events; // Idempotent
            }
            
            // Create conversation
            $conversationId = $this->conversationRepo->insert($request, $userId);
            
            // Add participants
            foreach ($request->participantIds as $participantId) {
                $role = ($participantId === $userId) ? 'owner' : 'member';
                $this->participantRepo->insert($conversationId, $participantId, $role);
            }
            
            $this->connection->commit();
            
            // Events AFTER commit
            $events->add(new ConversationCreatedEvent(
                conversationId: $conversationId,
                createdBy: $userId,
                participantIds: $request->participantIds,
                createdAt: time(),
            ));
            
            return $events;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw RepositoryException::insertFailed("Failed to create conversation: {$e->getMessage()}");
        }
    }
    
    // ... other methods following same pattern ...
}
```

**Key patterns**:
- Explicit transactions: `beginTransaction()` → `commit()` on success, `rollBack()` on error
- Factory methods delegate to repositories
- Events created AFTER transaction commits
- All exceptions wrapped in repository exceptions

---

## 4. REST API Specification

### 4.1 Base Configuration

**Base Path**: `/api`
**Authentication**: Bearer token (JWT via middleware)
**Content-Type**: `application/json`
**Response Format**:
- Success: `{"data": {...}, "meta": {pagination, ...}}`
- Error: `{"error": "...", "code": "...", "details": {...}}`

### 4.2 HTTP Status Codes

| Code | Meaning | Example |
|------|---------|---------|
| 200 | OK | GET conversation succeeded |
| 201 | Created | POST conversation created |
| 204 | No Content | DELETE conversation (no body) |
| 400 | Bad Request | Invalid JSON, missing required fields |
| 401 | Unauthorized | No auth token |
| 403 | Forbidden | Not participant, owner-only operation |
| 404 | Not Found | Conversation doesn't exist |
| 409 | Conflict | Edit window expired, duplicate conversation |
| 422 | Unprocessable | Validation failed (e.g., invalid role) |
| 500 | Internal Server Error | Database error, transaction failed |

### 4.3 Endpoint Specifications (17)

#### **C1. List Conversations**
```
GET /api/conversations?state=active&limit=20&offset=0

Query Parameters:
  - state: 'active' | 'pinned' | 'archived' (optional, default 'active')
  - limit: Int (1-100, default 20)
  - offset: Int (default 0)

Response (200):
{
  "data": [
    {
      "id": 1,
      "title": "Project Discussion",
      "createdAt": 1713960000,
      "lastMessageAt": 1713962000,
      "messageCount": 42,
      "participantCount": 3,
      "userState": "active"
    },
    ...
  ],
  "meta": {
    "pagination": {
      "total": 150,
      "limit": 20,
      "offset": 0,
      "pages": 8
    }
  }
}

Error (401):
{
  "error": "Unauthorized",
  "code": "AUTH_REQUIRED"
}
```

#### **C2. Get Conversation**
```
GET /api/conversations/:conversationId

Response (200):
{
  "data": {
    "id": 1,
    "title": "Project Discussion",
    "createdAt": 1713960000,
    "lastMessageAt": 1713962000,
    "messageCount": 42,
    "participantCount": 3,
    "userState": "active",
    "participants": [
      {
        "userId": 1,
        "username": "john",
        "role": "owner",
        "state": "active",
        "joinedAt": 1713960000,
        "unreadCount": 5
      },
      {
        "userId": 2,
        "username": "jane",
        "role": "member",
        "state": "active",
        "joinedAt": 1713960010,
        "unreadCount": 0
      },
      {
        "userId": 3,
        "username": "bob",
        "role": "hidden",
        "state": "active",
        "joinedAt": 1713960020,
        "unreadCount": 0
      }
    ]
  }
}
```

#### **C3. Create Conversation**
```
POST /api/conversations

Body:
{
  "participantIds": [1, 2, 3],
  "title": "Project Discussion" // optional
}

Response (201):
{
  "data": {
    "id": 1,
    "title": "Project Discussion",
    "createdAt": 1713960000,
    "participantCount": 3,
    "participants": [...]
  }
}

Error (422):
{
  "error": "At least one participant required",
  "code": "INVALID_PARTICIPANTS"
}
```

#### **C4. Archive Conversation**
```
PATCH /api/conversations/:conversationId/archive

Response (200):
{
  "data": {
    ...,
    "userState": "archived"
  }
}
```

#### **C5. Pin Conversation**
```
PATCH /api/conversations/:conversationId/pin

Response (200):
{
  "data": {
    ...,
    "userState": "pinned"
  }
}
```

#### **C6. Unpin Conversation**
```
PATCH /api/conversations/:conversationId/unpin

Response (200):
{
  "data": {
    ...,
    "userState": "active"
  }
}
```

#### **C7. Delete Conversation** (Owner only)
```
DELETE /api/conversations/:conversationId

Response (204): No content

Error (403):
{
  "error": "Only owner can delete",
  "code": "FORBIDDEN_OWNER_ONLY"
}
```

#### **C8. Add Participant** (Owner only)
```
PATCH /api/conversations/:conversationId/participants

Body:
{
  "userId": 4,
  "action": "add"
}

Response (200):
{
  "data": {
    "participants": [...]  // updated list
  }
}
```

#### **M1. List Messages**
```
GET /api/conversations/:conversationId/messages?limit=20&offset=0

Response (200):
{
  "data": [
    {
      "id": 1,
      "conversationId": 1,
      "authorId": 1,
      "authorUsername": "john",
      "text": "Hey everyone!",
      "subject": null,
      "createdAt": 1713960000,
      "editedAt": null,
      "editCount": 0,
      "isDeleted": false
    },
    {
      "id": 2,
      "conversationId": 1,
      "authorId": 2,
      "authorUsername": "jane",
      "text": "Hi John!",
      "subject": null,
      "createdAt": 1713960010,
      "editedAt": null,
      "editCount": 0,
      "isDeleted": false
    }
  ],
  "meta": {
    "pagination": {
      "total": 42,
      "limit": 20,
      "offset": 0
    }
  }
}
```

#### **M2. Send Message**
```
POST /api/conversations/:conversationId/messages

Body:
{
  "text": "Thanks for the update!",
  "subject": null,  // optional, for first message
  "attachments": null  // optional metadata
}

Response (201):
{
  "data": {
    "id": 43,
    "conversationId": 1,
    "authorId": 1,
    "authorUsername": "john",
    "text": "Thanks for the update!",
    "createdAt": 1713960100,
    "editCount": 0,
    "isDeleted": false
  }
}
```

#### **M3. Get Message**
```
GET /api/messages/:messageId

Response (200):
{
  "data": {
    "id": 1,
    "conversationId": 1,
    ...,
    "editHistory": [
      {
        "editedAt": 1713960050,
        "oldText": "Hey everyone! (original)",
        "editCount": 1
      }
    ]
  }
}
```

#### **M4. Edit Message** (Author only)
```
PATCH /api/messages/:messageId

Body:
{
  "text": "Hey everyone! (updated)"
}

Response (200):
{
  "data": {
    "id": 1,
    ...,
    "text": "Hey everyone! (updated)",
    "editedAt": 1713960050,
    "editCount": 1
  }
}

Error (409):
{
  "error": "Edit window expired",
  "code": "EDIT_WINDOW_EXPIRED",
  "details": {
    "createdAt": 1713960000,
    "editWindow": 300,  // seconds
    "secondsElapsed": 150
  }
}
```

#### **M5. Delete Message**
```
DELETE /api/messages/:messageId

Response (204): No content
```

#### **M6. Mark Message Read**
```
POST /api/messages/:messageId/read

Response (200):
{
  "data": {
    "messageId": 1,
    "conversationId": 1,
    "lastReadMessageId": 1,
    "unreadCount": 0
  }
}
```

#### **M7. Search Messages**
```
GET /api/conversations/:conversationId/messages/search?query=budget&limit=20

Query Parameters:
  - query: String (search term)
  - limit: Int (1-50)
  - offset: Int

Response (200):
{
  "data": [
    {
      "id": 5,
      "text": "The budget for Q2 is approved",
      ...
    }
  ],
  "meta": {
    "pagination": {...}
  }
}
```

#### **P1. List Participants**
```
GET /api/conversations/:conversationId/participants

Response (200):
{
  "data": [
    {
      "userId": 1,
      "username": "john",
      "role": "owner",
      "state": "active",
      "joinedAt": 1713960000,
      "leftAt": null,
      "unreadCount": 0
    },
    {
      "userId": 2,
      "username": "jane",
      "role": "member",
      "state": "active",
      "joinedAt": 1713960010,
      "leftAt": null,
      "unreadCount": 2
    }
  ]
}

Note: Current user sees hidden participants if they're the owner.
```

#### **P2. Update Participant**
```
PATCH /api/conversations/:conversationId/participants/:userId

Body:
{
  "role": "member",  // 'owner' or 'member'
  "state": "active"  // 'active', 'pinned', or 'archived'
}

Response (200):
{
  "data": {
    "userId": 2,
    "role": "member",
    "state": "active",
    ...
  }
}
```

---

## 5. Domain Events

### ConversationCreatedEvent
```php
class ConversationCreatedEvent implements DomainEventInterface
{
    public function __construct(
        public readonly int $conversationId,
        public readonly int $createdBy,
        public readonly array $participantIds,
        public readonly int $createdAt,
    ) {}
}
```

### MessageDeliveredEvent
```php
class MessageDeliveredEvent implements DomainEventInterface
{
    public function __construct(
        public readonly int $messageId,
        public readonly int $conversationId,
        public readonly int $authorId,
        public readonly int $deliveredAt,
    ) {}
}
```

### MessageEditedEvent, MessageDeletedEvent, ParticipantAddedEvent, etc.
Similar structure (omitted for brevity)

---

## 6. Implementation Constraints

### C1: Transaction Management
- All multi-step operations wrapped in explicit transactions
- Rollback on any exception
- Events created AFTER commit

### C2: SQL Safety
- 100% prepared statements
- No string interpolation in queries
- All user input parameterized

### C3: DTO Serialization
- All DTOs implement JsonSerializable
- Include factory methods (fromEntity, fromRow)
- Exclude sensitive data (passwords, IPs)

### C4: Authorization
- All endpoints check authentication (middleware)
- Service methods receive user_id (trusted)
- Controller layer validates participant membership
- Owner-only operations checked before execution

### C5: Naming Conventions
- Namespaces: `phpbb\messaging\{Service,Repository,Entity,DTO,Event,Contract}`
- Classes: PascalCase (MessagingService, DbalConversationRepository)
- Methods: camelCase (createConversation, listMessages)
- Constants: UPPER_SNAKE_CASE

### C6: Error Handling
- Repository exceptions wrap database errors
- Controllers translate to HTTP status codes
- Error responses include error code + details

---

## 7. Testing Strategy

### Unit Tests (Service Layer)
- **Target**: MessagingService, helper services
- **Coverage**: ~20 test methods
- **Mocking**: Mock repositories, Connection
- **Focus**: Service logic, transaction handling, event emission

### Integration Tests (Repository Layer)
- **Target**: DbalConversationRepository, DbalMessageRepository, DbalParticipantRepository
- **Coverage**: ~30 test methods
- **Database**: Test database, real queries
- **Focus**: Query correctness, pagination, multi-entity operations

### Controller Unit Tests
- **Target**: ConversationsController, MessagesController, ParticipantsController
- **Coverage**: ~17 test methods (one per endpoint)
- **Mocking**: Mock MessagingService
- **Focus**: HTTP status codes, DTO serialization, authorization

### E2E Tests (Playwright)
- **Scenarios**: 
  1. Create conversation with 2 participants
  2. Send/receive messages
  3. Edit message within window (success + failure)
  4. Delete conversation
  5. Full workflow: create, message, archive, list

- **Coverage**: ~5 scenarios
- **Browser**: Chrome (Playwright)

---

## 8. Success Acceptance Criteria

✅ **Functional**:
- All 17 endpoints responding correctly
- CRUD operations work (Create, Read, Update, Delete patterns)
- Transactions atomic (no partial states)
- Edit window validated (5-min default enforced)

✅ **Technical**:
- 100% prepared statements (no SQL injection)
- All configured services registered (DI)
- Domain events returned from service methods
- Repositories implement interfaces

✅ **Quality**:
- Unit tests: 100% pass
- Integration tests: 100% pass
- Controller tests: 100% pass
- E2E tests: All 5 scenarios pass
- Code style: cs:fix clean

✅ **Standards Compliance**:
- PHPDoc complete (all methods documented)
- Naming follows STANDARDS.md
- No legacy code patterns
- File headers with copyright notice

---

## 9. Timeline & Effort

| Phase | Task | Hours | Owner |
|-------|------|-------|-------|
| Phase 7 | Implementation plan + task decomposition | 2-3 | Orchestrator |
| Phase 8 | **Implementation** | | |
| | Database schema creation | 1 | Dev |
| | Entity + DTO classes | 2 | Dev |
| | Repositories implementation | 3 | Dev |
| | Service layer | 3 | Dev |
| | REST controllers | 2 | Dev |
| | Domain events | 1 | Dev |
| | DI registration + routes | 1 | Dev |
| | Unit tests | 2 | QA |
| | Integration tests | 3 | QA |
| | Controller tests | 2 | QA |
| | E2E tests | 4 | QA |
| | Code review + fixes | 2-3 | Dev/QA |
| **TOTAL** | **M7 Complete** | **~28-32 hours** | |

---

## 10. Conclusion

This specification provides all technical detail needed for Phase 8 implementation. The service architecture replicates the proven Threads (M6) pattern, ensuring consistency and reducing architectural risk.

**Next Phase**: Phase 6 (Specification Audit) — review for completeness and clarity.

**Status**: ✅ Specification complete and ready for implementation.
