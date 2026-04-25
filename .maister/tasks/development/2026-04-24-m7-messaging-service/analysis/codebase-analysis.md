# Phase 1: Codebase Analysis — M7 Messaging Service

**Scope**: Understand codebase structure, threads service architecture pattern, and integration points for implementing M7 messaging service (phpbb\messaging) following the M6 threads pattern.

**Analysis Date**: 2026-04-24
**Status**: Comprehensive Analysis Complete

---

## 1. Codebase Structure Overview

### 1.1 Project Architecture (Symfony 8.x + phpBB4)

```
src/phpbb/
├── api/                      # REST API controllers & infrastructure
├── auth/                      # Authentication service
├── cache/                     # Caching infrastructure
├── common/                    # Shared utilities, events, exceptions
├── config/                    # Configuration services
├── db/                        # Database infrastructure
├── hierarchy/                 # Forum hierarchy service (M5)
├── threads/                   # Threads service (M6) ← TEMPLATE FOR M7
├── user/                      # User service & DTOs
└── messaging/                 # TARGET: Messaging service (M7) — NOT YET CREATED
```

### 1.2 Backend Stack
- **Framework**: Symfony 8.x (DI container, service definitions in config/services.yaml)
- **Database**: Doctrine DBAL (prepared statements, PDO via direct Connection)
- **Testing**: PHPUnit 11.x (integration tests, unit tests, E2E via Playwright)
- **Code Standards**: .maister/docs/standards/backend/STANDARDS.md (namespacing, PHPDoc, Controller design)
- **API Standards**: .maister/docs/standards/backend/REST_API.md (JSON responses, pagination, HTTP status codes)

---

## 2. Threads Service Pattern (M6) — Template for M7

The `phpbb\threads` service (M6, completed) provides the exact architectural pattern M7 should replicate. Analysis of this pattern is critical for consistent architecture across Meridian.

### 2.1 Directory Structure & File Organization

```
src/phpbb/threads/
├── ThreadsService.php              # Main facade — implements ThreadsServiceInterface
├── Contract/
│   ├── ThreadsServiceInterface.php  # Service contract (methods: getTopic, listTopics, createTopic, etc.)
│   ├── TopicRepositoryInterface.php # Repository contract
│   └── PostRepositoryInterface.php  # Repository contract
├── Entity/
│   ├── Topic.php                    # Domain entity (value object-like, immutable after fetch)
│   └── Post.php                     # Domain entity
├── Repository/
│   ├── DbalTopicRepository.php      # Concrete DBAL implementation
│   └── DbalPostRepository.php       # Concrete DBAL implementation
├── DTO/
│   ├── TopicDTO.php                 # Data transfer object (API-safe serialization)
│   ├── PostDTO.php
│   ├── CreateTopicRequest.php       # Request DTO (immutable, validated at controller)
│   └── CreatePostRequest.php        # Request DTO
└── Event/
    ├── TopicCreatedEvent.php        # Domain event (TopicCreated → dispatched after insert)
    └── PostCreatedEvent.php         # Domain event
```

### 2.2 Key Architectural Patterns

#### A. Service Interface (Contract-Based Design)

**ThreadsServiceInterface.php** — Defines public contract:
```php
interface ThreadsServiceInterface {
    public function getTopic(int $topicId): TopicDTO;
    public function listTopics(int $forumId, PaginationContext $ctx): PaginatedResult;
    public function createTopic(CreateTopicRequest $request): DomainEventCollection;
    public function listPosts(int $topicId, PaginationContext $ctx): PaginatedResult;
    public function createPost(CreatePostRequest $request): DomainEventCollection;
}
```

**Key pattern**: 
- All methods return DTOs (API-safe, serializable)
- Create/write methods return `DomainEventCollection` (not entities)
- Repositories accessed only via interfaces (DI injection)
- No static methods or procedural code

#### B. Domain-Driven Design (DDD)

**Entities** (`Entity/Topic.php`, `Entity/Post.php`):
- Value objects (not directly exposed to API)
- Constructed from database rows
- Hold domain logic, validation
- Used internally only

**DTOs** (`DTO/TopicDTO.php`, `DTO/PostDTO.php`):
- API-safe transfer objects
- Implement JsonSerializable
- Include `fromEntity()` static factory for Entity→DTO conversion
- Include field filtering/sanitization for API responses

**Request DTOs** (`DTO/CreateTopicRequest.php`, etc.):
- Immutable request objects
- Validated at controller level
- Parameters extracted from JSON body
- Passed to service methods

#### C. Repository Pattern (DBAL-Based)

**DbalTopicRepository.php** — Concrete implementation:

```php
class DbalTopicRepository implements TopicRepositoryInterface {
    private const TABLE = 'phpbb_topics';
    
    public function __construct(private readonly Connection $connection) {}
    
    public function findById(int $id): ?Topic {
        // Prepared statement, always parameterized
        $row = $this->connection->executeQuery(
            'SELECT ... FROM ' . self::TABLE . ' WHERE topic_id = :id',
            ['id' => $id]
        )->fetchAssociative();
        
        return $row ? Topic::fromRow($row) : null;
    }
    
    public function insert(CreateTopicRequest $req, int $now): int {
        // Transactional insert, returns generated ID
        $this->connection->insert(self::TABLE, [
            'forum_id' => $req->forumId,
            'topic_title' => $req->title,
            // ...
        ]);
        return (int) $this->connection->lastInsertId();
    }
}
```

**Key patterns**:
- Always use prepared statements (never interpolate user input)
- All queries parameterized (`:id`, `:title`, etc.)
- Single table per repository (Topic → phpbb_topics, Post → phpbb_posts)
- Return entities from queries
- Pagination via `findByForum(..., PaginationContext)` returning `PaginatedResult`

#### D. Domain Events (Event-Driven)

**Event/TopicCreatedEvent.php**:
```php
class TopicCreatedEvent implements DomainEventInterface {
    public function __construct(
        public readonly int $topicId,
        public readonly int $forumId,
        public readonly int $createdBy,
        public readonly int $createdAt,
    ) {}
}
```

**Service integration** (ThreadsService.createTopic):
```php
public function createTopic(CreateTopicRequest $request): DomainEventCollection {
    // ... insert logic ...
    $events = new DomainEventCollection();
    $events->add(new TopicCreatedEvent(...));
    return $events;  // ← Caller dispatches these events
}
```

**Key pattern**: Service returns events (not dispatches them). Controller later dispatches to event bus.

#### E. Dependency Injection (Symfony Services)

**config/services.yaml** (hypothetical for threads):
```yaml
services:
  phpbb\threads\ThreadsService:
    arguments:
      $topicRepository: '@phpbb\threads\Repository\DbalTopicRepository'
      $postRepository: '@phpbb\threads\Repository\DbalPostRepository'
      $connection: '@doctrine.dbal.default_connection'
  
  phpbb\threads\Repository\DbalTopicRepository:
    arguments:
      $connection: '@doctrine.dbal.default_connection'
  
  phpbb\threads\Repository\DbalPostRepository:
    arguments:
      $connection: '@doctrine.dbal.default_connection'
```

**Key pattern**: All dependencies injected via constructor, no `new` keyword in business logic.

#### F. Transaction Management

**ThreadsService.createTopic** (full lifecycle):
```php
public function createTopic(CreateTopicRequest $request): DomainEventCollection {
    $now = time();
    
    $this->connection->beginTransaction();  // ← Explicit transaction
    
    try {
        $topicId = $this->topicRepository->insert($request, $now);
        $postId = $this->postRepository->insert(...);
        
        $this->connection->commit();  // ← Explicit commit on success
        
        $events = new DomainEventCollection();
        $events->add(new TopicCreatedEvent(...));
        return $events;
    } catch (\Throwable $e) {
        $this->connection->rollBack();
        throw RepositoryException::insertFailed(...);
    }
}
```

**Key pattern**: 
- Explicit transaction boundaries
- Commit only on successful logic completion
- Rollback on any exception
- Events created AFTER transaction commits (atomicity guarantee)

---

## 3. Messaging Service Requirements (from Research)

### 3.1 Architecture (from HLD)

The research phase (2026-04-19) established these key design decisions:

| Concern | Decision | Relevance to M7 Implementation |
|---------|----------|-------------------------------|
| Conversation Model | Thread-per-participant-set | Shapes ConversationService + repository |
| Read Tracking | Hybrid cursor + sparse unread | Participant entity with read_cursor field |
| Message Mutability | Time-limited edit (5min window) | MessageService needs edit window validation |
| Participant Roles | Owner / Member / Hidden | Role field on Participant entity |
| Organization | Pinned + Archive (no folders) | State field on Participant (active/pinned/archived) |
| Counters | Tiered hot+cold (event-driven) | Counter updates via event listeners |
| Content Formatting | Via ContentPipeline plugin (events) | Message storage is raw, rendering delegated |
| Attachments | Via phpbb\storage plugin (events) | Metadata JSON column for plugin data |

### 3.2 Core Entities (from HLD)

M7 implementation must support these domain entities:

1. **Conversation**
   - conversation_id (primary key)
   - participant_hash (SHA-256 of sorted participant IDs)
   - title (nullable, auto-generated if null)
   - created_by, created_at
   - last_message_id, last_message_at (denormalized)
   - message_count, participant_count (denormalized)

2. **Message**
   - message_id (primary key)
   - conversation_id (FK)
   - author_id (FK → users)
   - message_text (MEDIUMTEXT)
   - message_subject (optional, first message may have)
   - created_at, edited_at, edit_count
   - metadata (JSON for plugins)

3. **Participant**
   - (conversation_id, user_id) composite primary key
   - role (ENUM: owner, member, hidden)
   - state (ENUM: active, pinned, archived) ← per-user organization
   - joined_at, left_at (soft-leave)
   - last_read_message_id (read cursor)
   - last_read_at (timestamp)
   - is_muted, is_blocked

### 3.3 Service Methods (from HLD)

The research identified 7 service layers. M7 should implement (Phase 8 implementation):

**MessagingService (facade)**:
- listConversations(userId, state, pagination)
- getConversation(conversationId, userId)
- createConversation(participants, title)
- archiveConversation(conversationId, userId)
- pinConversation(conversationId, userId)
- sendMessage(conversationId, authorId, content)
- editMessage(messageId, authorId, newContent)
- deleteMessage(messageId, authorId)
- addParticipant(conversationId, userId)
- removeParticipant(conversationId, userId)

**Supporting services** (Phase 8 may inline or defer):
- ConversationService, MessageService, ParticipantService
- RuleService, CounterService, DraftService

### 3.4 REST API Endpoints (17 total, from OpenAPI spec)

The research phase analyzed the OpenAPI spec and identified these endpoints:

**Conversations (8 endpoints)**:
- GET /api/conversations — list user's conversations (with state filter)
- GET /api/conversations/{id} — get single conversation
- POST /api/conversations — create conversation
- PATCH /api/conversations/{id}/archive — archive
- PATCH /api/conversations/{id}/pin — pin
- PATCH /api/conversations/{id}/unpin — unpin
- DELETE /api/conversations/{id} — hard delete (owner only)
- PATCH /api/conversations/{id}/participants — add/remove participants

**Messages (7 endpoints)**:
- GET /api/conversations/{id}/messages — list messages in conversation
- POST /api/conversations/{id}/messages — send message
- GET /api/messages/{id} — get single message
- PATCH /api/messages/{id} — edit message
- DELETE /api/messages/{id} — delete message (per-participant)
- POST /api/messages/{id}/read — mark read
- POST /api/conversations/{id}/messages/search — search messages

**Participants (2 endpoints)**:
- GET /api/conversations/{id}/participants — list participants
- PATCH /api/conversations/{id}/participants/{userId} — update participant role/state

---

## 4. Key Files to Reference

### 4.1 Threads Service (Pattern Template)

| File | Purpose | Lines | Key Pattern |
|------|---------|-------|------------|
| `src/phpbb/threads/ThreadsService.php` | Main facade | ~150 | Service contract, transaction management |
| `src/phpbb/threads/Contract/ThreadsServiceInterface.php` | Service interface | ~30 | Method signatures, DTO returns, DomainEventCollection |
| `src/phpbb/threads/Repository/DbalTopicRepository.php` | Repository | ~100 | DBAL patterns, prepared statements, Entity hydration |
| `src/phpbb/threads/Entity/Topic.php` | Domain entity | ~50 | fromRow() factory, immutability |
| `src/phpbb/threads/DTO/TopicDTO.php` | Response DTO | ~40 | fromEntity() factory, JsonSerializable |
| `src/phpbb/threads/DTO/CreateTopicRequest.php` | Request DTO | ~20 | Immutable request object |
| `src/phpbb/threads/Event/TopicCreatedEvent.php` | Domain event | ~15 | Event data structure |

### 4.2 Standards & Documentation

| File | Purpose | Key Content |
|------|---------|-------------|
| `.maister/docs/standards/backend/STANDARDS.md` | Backend conventions | Namespacing (phpbb\*), PHPDoc, DI, SQL safety |
| `.maister/docs/standards/backend/REST_API.md` | REST conventions | Controllers, pagination, status codes, JWT auth |
| `.maister/docs/standards/testing/STANDARDS.md` | Testing standards | PHPUnit structure, test naming, mocking |
| `.github/copilot-instructions.md` | Project meta-standards | Post-PHP edit: run composer test, test:e2e, cs:fix |

### 4.3 Configuration & DI

| File | Purpose | Relevance to M7 |
|------|---------|-----------------|
| `config/services.yaml` | Service registration | Must register MessagingService, repos, controllers |
| `phpunit.xml` | Test configuration | Test suite setup for M7 tests |
| `composer.json` | Dependencies | Test dependencies (PHPUnit, Playwright already present) |

---

## 5. Integration Points

### 5.1 Where M7 Should Integrate

1. **DI Container** (`config/services.yaml`)
   - Register MessagingService, repositories, controllers
   - Inject Connection (Doctrine DBAL), user service

2. **REST API** (`src/phpbb/api/`)
   - Create ConversationsController
   - Create MessagesController
   - Follow ThreadsController pattern (if exists)

3. **Domain Events** (`src/phpbb/common/Event/`)
   - MessagingService returns DomainEventCollection
   - Controllers dispatch to event bus
   - Listeners for notifications, counting, etc.

4. **Migrations** (database schema setup)
   - Create messaging_conversations table
   - Create messaging_messages table
   - Create messaging_participants table
   - Create messaging_unread_overrides table (optional optimization)
   - Create messaging_rules, messaging_counters, messaging_drafts tables (future)

5. **Authentication** (middleware)
   - Controllers receive authenticated user context
   - Participant lookup/filtering based on current user
   - Permission checks (owner-only operations, etc.)

---

## 6. Key Findings & Complexity Assessment

### 6.1 Architectural Alignment

✅ **STRONG**: Threads pattern perfectly applicable to Messaging
- Same service/repo/entity/DTO structure
- Same transaction management approach
- Same event-driven returns
- Same DI container usage

### 6.2 Complexity Factors

- **Entity interaction**: Messaging has 3 entities (Conversation, Message, Participant) vs Threads' 2 (Topic, Post)
  - Complexity: +20% (multi-entity operations, participant set management)
- **Business logic**: Time-limited edit window, participant roles, read tracking
  - Complexity: moderate (validation layer needed)
- **API endpoints**: 17 endpoints (threads = ~6-8 estimated)
  - Complexity: +100% (more controller methods)

### 6.3 Risk Assessment

| Risk | Impact | Mitigation |
|------|--------|-----------|
| Participant set hashing (participant_hash) | High | Validate hash generation in tests, document algorithm |
| Read tracking via cursor | Medium | Clear documentation, test cursor movement |
| Time-limited edit window | Medium | Validate edit_window config, test edge cases |
| Multi-entity transactions | Medium | Mirror Threads' transaction pattern exactly |

**Overall Risk Level**: **MEDIUM** (complexity in business logic, not architecture)

---

## 7. Recommended Approach

### Phase 1-2 Findings (This Phase)

✅ **Clear pattern available**: Use Threads (M6) as exact template
✅ **Research complete**: HLD + API spec ready
✅ **No architectural unknowns**: DI, repos, events, DTOs all established
✅ **Complexity quantified**: 3 entities, 17 endpoints, moderate business logic

### Next Phases (2-7)

1. **Phase 2 (Gap Analysis)**: Compare requirements vs pattern → confirm scope
2. **Phase 5 (Specification)**: Detail 17 endpoints, service methods, entity relationships
3. **Phase 7 (Planning)**: Break into task groups (entities, repos, services, controllers, tests)

### Implementation Strategy (Phase 8)

**Recommended order**:
1. Create domain entities + DTOs (Entity/, DTO/)
2. Create repository interfaces + implementations (Repository/, Contract/)
3. Create MessagingService facade (MessagingService.php)
4. Create REST controllers (api/)
5. Create tests (tests/phpbb/messaging/)
6. Create domain events (Event/)
7. Register services (config/services.yaml)
8. E2E tests (tests/e2e/)

---

## 8. Conclusion

The Threads service (M6) provides a proven, tested pattern that M7 should replicate exactly. No architectural innovation needed — focus is on correct implementation of domain logic and comprehensive testing.

**Status**: Ready to proceed to Phase 2 (Gap Analysis).
