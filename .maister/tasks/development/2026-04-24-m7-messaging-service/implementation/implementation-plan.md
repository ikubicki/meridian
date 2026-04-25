# Phase 7: Implementation Plan — M7 Messaging Service

**Date**: 2026-04-24
**Status**: Ready for Phase 8 Execution
**Total Task Groups**: 8 major groups with 32 subtasks

---

## 1. Implementation Strategy

### 1.1 Execution Order (Critical Path)

```
Phase 7A: FOUNDATION
  ├── TG-1: Database Schema (DDL)
  └── TG-2: Domain Entities + DTOs

Phase 7B: DATA LAYER
  ├── TG-3: Repository Interfaces
  └── TG-4: Repository Implementations (DBAL)

Phase 7C: BUSINESS LOGIC
  ├── TG-5: Service Interfaces
  ├── TG-6: MessagingService Implementation
  └── TG-7: Domain Events

Phase 7D: API & CONFIGURATION
  ├── TG-8: REST Controllers
  └── TG-9: DI Registration + Routes

Phase 7E: TESTING (Parallel with 7C-7D)
  ├── TG-10: Repository Tests (Integration)
  ├── TG-11: Service Tests (Unit)
  ├── TG-12: Controller Tests (Unit)
  └── TG-13: E2E Tests (Playwright)

Phase 7F: QUALITY GATES
  ├── TG-14: Code Review + Standards Compliance
  └── TG-15: Final Verification
```

### 1.2 Dependencies & Critical Path

```
TG-1 (Schema) → TG-2 (Entities) → TG-3 (Repo Interfaces) → TG-4 (Repo Impl)
                                                              ↓
TG-5 (Service Interfaces) → TG-6 (Service Impl) → TG-7 (Events) → TG-8 (Controllers)
                                                                         ↓
                                                                   TG-9 (DI + Routes)
                                                                         ↓
(Parallel) TG-10 (Repo Tests) → TG-11 (Service Tests) → TG-12 (Controller Tests) → TG-13 (E2E)
```

---

## 2. Task Group Definitions

### **TG-1: Database Schema Creation**

**Scope**: Create 5 messaging tables with indexes and constraints

**Deliverables**:
- [ ] `messaging_conversations` table (DDL)
- [ ] `messaging_messages` table (DDL)
- [ ] `messaging_participants` table (DDL)
- [ ] `messaging_message_deletes` table (DDL)
- [ ] Verify all indexes created
- [ ] Verify all foreign key constraints

**Files to Create**: None (SQL only, manual or migration tool)
**Estimated Time**: 1 hour
**Dependencies**: None
**Subtasks**:
1. Write DDL for conversations table (PRIMARY KEY, UNIQUE index on participant_hash, FK to users)
2. Write DDL for messages table (composite indexes on (conversation_id, created_at))
3. Write DDL for participants table (composite PK, indexes on (user_id, state))
4. Write DDL for message_deletes table (soft-delete tracking)
5. Test table creation, verify schema

**Success Criteria**:
- ✅ All 5 tables exist in test database
- ✅ All foreign keys defined
- ✅ All indexes created

---

### **TG-2: Domain Entities & DTOs**

**Scope**: Create 3 entity classes and 6 DTO classes

**Deliverables**:
- [ ] Entity/Conversation.php (domain entity)
- [ ] Entity/Message.php (domain entity)
- [ ] Entity/Participant.php (domain entity)
- [ ] DTO/ConversationDTO.php (API response)
- [ ] DTO/MessageDTO.php (API response)
- [ ] DTO/ParticipantDTO.php (API response)
- [ ] DTO/Request/CreateConversationRequest.php
- [ ] DTO/Request/SendMessageRequest.php
- [ ] DTO/Request/UpdateParticipantRequest.php

**Files to Create**:
```
src/phpbb/messaging/
├── Entity/Conversation.php
├── Entity/Message.php
├── Entity/Participant.php
└── DTO/
    ├── ConversationDTO.php
    ├── MessageDTO.php
    ├── ParticipantDTO.php
    └── Request/
        ├── CreateConversationRequest.php
        ├── SendMessageRequest.php
        └── UpdateParticipantRequest.php
```

**Estimated Time**: 2 hours
**Dependencies**: TG-1 (schema known)
**Subtasks**:
1. Create Conversation entity with properties (conversationId, participantHash, title, createdBy, etc.)
   - Include fromRow() factory method
   - Include toDTO() method
2. Create Message entity with properties (messageId, conversationId, authorId, text, etc.)
   - Include fromRow() factory method
   - Include toDTO() method
3. Create Participant entity with properties (conversationId, userId, role, state, etc.)
   - Include fromRow() factory method
   - Include toDTO() method
4. Create ConversationDTO with JsonSerializable + factory methods
5. Create MessageDTO with JsonSerializable + factory methods
6. Create ParticipantDTO with JsonSerializable + factory methods
7. Create request DTOs (CreateConversationRequest, SendMessageRequest, UpdateParticipantRequest)
   - Immutable properties
   - Optional validation (type checking)

**Success Criteria**:
- ✅ All 9 files created with complete PHPDoc
- ✅ fromRow() and fromEntity() methods functional
- ✅ DTOs implement JsonSerializable
- ✅ All properties typed (PHP 8.2 strict types)

---

### **TG-3: Repository Interfaces**

**Scope**: Define contracts for data layer

**Deliverables**:
- [ ] Contract/ConversationRepositoryInterface.php
- [ ] Contract/MessageRepositoryInterface.php
- [ ] Contract/ParticipantRepositoryInterface.php

**Files to Create**:
```
src/phpbb/messaging/Contract/
├── ConversationRepositoryInterface.php
├── MessageRepositoryInterface.php
├── ParticipantRepositoryInterface.php
```

**Estimated Time**: 0.5 hours
**Dependencies**: TG-2 (Entity/DTO types needed)
**Subtasks**:
1. Define ConversationRepositoryInterface with methods: findById, findByParticipantHash, listByUser, insert, update, delete
2. Define MessageRepositoryInterface with methods: findById, listByConversation, search, insert, update, deletePerUser, isDeletedForUser
3. Define ParticipantRepositoryInterface with methods: findByConversation, findByUser, insert, update, delete, findByConversationAndUser

**Success Criteria**:
- ✅ All interfaces defined with proper method signatures
- ✅ Return types correct (Entity types, PaginatedResult, etc.)
- ✅ PHPDoc complete

---

### **TG-4: Repository Implementations (DBAL)**

**Scope**: Implement 3 repositories with Doctrine DBAL

**Deliverables**:
- [ ] Repository/DbalConversationRepository.php (~200 LOC)
- [ ] Repository/DbalMessageRepository.php (~250 LOC)
- [ ] Repository/DbalParticipantRepository.php (~150 LOC)

**Files to Create**:
```
src/phpbb/messaging/Repository/
├── DbalConversationRepository.php
├── DbalMessageRepository.php
└── DbalParticipantRepository.php
```

**Estimated Time**: 3 hours
**Dependencies**: TG-1, TG-2, TG-3
**Subtasks**:
1. Implement DbalConversationRepository:
   - findById(): Query with prepared statement, return Conversation entity
   - findByParticipantHash(): Query with unique index lookup
   - listByUser(): Join to participants table, filter by user_id + state + left_at IS NULL
   - insert(): Insert new row, return generated ID
   - update(): Update specific fields by conversation_id
   - delete(): Delete cascade (FK handles)
2. Implement DbalMessageRepository:
   - findById(): Simple find by message_id
   - listByConversation(): Query with pagination, exclude deleted (soft-delete check)
   - search(): LIKE query on message_text
   - insert(): Insert message, update conversation denormalized fields
   - update(): Update message content/edit_count/edited_at
   - deletePerUser(): Insert into message_deletes table (soft-delete per participant)
   - isDeletedForUser(): Check message_deletes table
3. Implement DbalParticipantRepository:
   - findByConversation(): Query all participants in conversation
   - findByUser(): Query all participant records for user
   - insert(): Insert participant record with default role/state
   - update(): Update role/state/read_cursor for specific participant
   - delete(): Soft-delete or hard-delete per requirements
   - findByConversationAndUser(): Single participant lookup

**Success Criteria**:
- ✅ All queries use prepared statements (no interpolation)
- ✅ All methods return correct entity types
- ✅ Pagination works (PaginatedResult with total/limit/offset)
- ✅ Relationship queries correct (JOINs if needed)
- ✅ All methods have PHPDoc

---

### **TG-5: Service Interfaces**

**Scope**: Define service contracts for business logic

**Deliverables**:
- [ ] Contract/MessagingServiceInterface.php
- [ ] Contract/ConversationServiceInterface.php (optional, may inline)
- [ ] Contract/MessageServiceInterface.php (optional, may inline)

**Files to Create**:
```
src/phpbb/messaging/Contract/
└── MessagingServiceInterface.php  # Main facade interface
```

**Estimated Time**: 1 hour
**Dependencies**: TG-3 (Repository interfaces), TG-2 (DTOs)
**Subtasks**:
1. Define MessagingServiceInterface with all 17 public methods:
   - listConversations, getConversation, createConversation, archiveConversation, pinConversation, unpinConversation, deleteConversation
   - listMessages, getMessage, sendMessage, editMessage, deleteMessage, markMessageRead, searchMessages
   - listParticipants, addParticipant, removeParticipant, updateParticipantRole

**Success Criteria**:
- ✅ All 17 methods with correct signatures
- ✅ Return types correct (DTOs, DomainEventCollection, etc.)
- ✅ PHPDoc complete

---

### **TG-6: MessagingService Implementation**

**Scope**: Implement main service facade with transactional logic

**Deliverables**:
- [ ] MessagingService.php (~400 LOC)
- [ ] Helper service classes (optional inline or separate):
  - ConversationService.php (find-or-create logic)
  - MessageService.php (edit window validation)
  - ParticipantService.php (role/state management)

**Files to Create**:
```
src/phpbb/messaging/MessagingService.php
```

**Estimated Time**: 4 hours
**Dependencies**: TG-4 (Repositories), TG-5 (Interface), TG-7 (Events - created in parallel)
**Subtasks**:
1. Create MessagingService class with DI:
   ```php
   public function __construct(
       private readonly ConversationRepositoryInterface $conversationRepo,
       private readonly MessageRepositoryInterface $messageRepo,
       private readonly ParticipantRepositoryInterface $participantRepo,
       private readonly Connection $connection,
   ) {}
   ```
2. Implement createConversation():
   - Calculate participant_hash from request participant IDs
   - Find-or-create conversation (return early if exists)
   - Insert participants with roles (owner for creator, member for others)
   - Emit ConversationCreatedEvent
   - Transaction wrapper (BEGIN, COMMIT, ROLLBACK)
3. Implement listConversations(): Query conversationRepo with state filter + pagination
4. Implement getConversation(): Find + verify user is participant + return DTO with participants array
5. Implement archiveConversation(), pinConversation(), unpinConversation(): Update participant state
6. Implement deleteConversation(): Verify owner, delete conversation (cascade handles messages/participants)
7. Implement sendMessage():
   - Insert message to messageRepo
   - Update conversation denormalized fields (last_message_id, message_count)
   - Reset read cursors for other participants
   - Emit MessageDeliveredEvent
   - Transaction wrapper
8. Implement editMessage():
   - Verify edit window: (now - message.created_at) <= EDIT_WINDOW_SECONDS (default 300)
   - Update message + edit_count
   - Emit MessageEditedEvent
9. Implement other message methods (listMessages, getMessage, deleteMessage, markMessageRead, searchMessages)
10. Implement participant methods (listParticipants, addParticipant, removeParticipant, updateParticipantRole)
11. Helper: hashParticipants(array): return SHA-256 of sorted participant IDs

**Success Criteria**:
- ✅ All methods implemented with correct logic
- ✅ Transactions atomic (explicit BEGIN/COMMIT/ROLLBACK)
- ✅ Events returned (not dispatched)
- ✅ All error paths caught and wrapped
- ✅ PHPDoc complete for all methods

---

### **TG-7: Domain Events**

**Scope**: Create event classes for integration hooks

**Deliverables**:
- [ ] Event/ConversationCreatedEvent.php
- [ ] Event/MessageDeliveredEvent.php
- [ ] Event/MessageEditedEvent.php
- [ ] Event/MessageDeletedEvent.php
- [ ] Event/ParticipantAddedEvent.php
- [ ] Event/ParticipantRemovedEvent.php
- [ ] Event/ConversationDeletedEvent.php

**Files to Create**:
```
src/phpbb/messaging/Event/
├── ConversationCreatedEvent.php
├── MessageDeliveredEvent.php
├── MessageEditedEvent.php
├── MessageDeletedEvent.php
├── ParticipantAddedEvent.php
├── ParticipantRemovedEvent.php
└── ConversationDeletedEvent.php
```

**Estimated Time**: 0.5 hours
**Dependencies**: TG-2 (Entity types for context)
**Subtasks**:
1. Create ConversationCreatedEvent with properties: conversationId, createdBy, participantIds, createdAt
2. Create MessageDeliveredEvent with properties: messageId, conversationId, authorId, deliveredAt
3. Create MessageEditedEvent with properties: messageId, oldText, newText, editCount
4. Create MessageDeletedEvent with properties: messageId, userId
5. Create ParticipantAddedEvent, ParticipantRemovedEvent, ConversationDeletedEvent
6. All events implement DomainEventInterface

**Success Criteria**:
- ✅ All event classes defined
- ✅ Properties typed (PHP 8.2)
- ✅ Implement DomainEventInterface

---

### **TG-8: REST Controllers**

**Scope**: Implement 3 controllers with 17 endpoints total

**Deliverables**:
- [ ] API/Controller/ConversationsController.php (8 endpoints)
- [ ] API/Controller/MessagesController.php (7 endpoints)
- [ ] API/Controller/ParticipantsController.php (2 endpoints)

**Files to Create**:
```
src/phpbb/api/Controller/
├── ConversationsController.php
├── MessagesController.php
└── ParticipantsController.php
```

**Estimated Time**: 2.5 hours
**Dependencies**: TG-6 (MessagingService), TG-2 (DTOs/Requests)
**Subtasks**:
1. Create ConversationsController:
   - listConversations(): GET /api/conversations → call service.listConversations() → return PaginatedResult
   - getConversation(): GET /api/conversations/{id} → verify participant → return ConversationDTO
   - createConversation(): POST /api/conversations → validate request → call service → return 201
   - archiveConversation(): PATCH /api/conversations/{id}/archive → update state
   - pinConversation(): PATCH /api/conversations/{id}/pin → update state
   - unpinConversation(): PATCH /api/conversations/{id}/unpin → update state
   - deleteConversation(): DELETE /api/conversations/{id} → verify owner → return 204
   - updateParticipants(): PATCH /api/conversations/{id}/participants → add/remove participants
2. Create MessagesController:
   - listMessages(): GET /api/conversations/{id}/messages → return paginated messages
   - sendMessage(): POST /api/conversations/{id}/messages → validate request → return 201
   - getMessage(): GET /api/messages/{id} → return MessageDTO
   - editMessage(): PATCH /api/messages/{id} → verify author → return MessageDTO
   - deleteMessage(): DELETE /api/messages/{id} → verify author → return 204
   - markMessageRead(): POST /api/messages/{id}/read → update cursor → return DomainEventCollection
   - searchMessages(): GET /api/conversations/{id}/messages/search?query=X → return paginated results
3. Create ParticipantsController:
   - listParticipants(): GET /api/conversations/{id}/participants → return Array<ParticipantDTO>
   - updateParticipant(): PATCH /api/conversations/{id}/participants/{userId} → verify owner → update role/state
4. All controllers:
   - Inject MessagingService via DI
   - Extract current user_id from authentication context (middleware provides)
   - Translate service exceptions to HTTP status codes (400, 403, 404, 409, 422, 500)
   - Return JSON with proper Content-Type headers
   - Dispatch DomainEventCollection to event bus (if returned by service)

**Success Criteria**:
- ✅ All 17 endpoints implemented
- ✅ Correct HTTP methods and status codes
- ✅ Authorization checks present (participant member, owner-only operations)
- ✅ Error responses follow format: {"error": "...", "code": "...", "details": {...}}
- ✅ Success responses follow format: {"data": {...}, "meta": {...}}
- ✅ PHPDoc complete

---

### **TG-9: DI Registration & Route Configuration**

**Scope**: Register services in Symfony container and define API routes

**Deliverables**:
- [ ] config/services.yaml (additions to register messag services)
- [ ] config/routes/messaging_api.yaml (API routes for 17 endpoints)

**Files to Modify**:
```
config/services.yaml        # Add phpbb\messaging services
config/routes/              # Add messaging_api.yaml
```

**Estimated Time**: 1 hour
**Dependencies**: TG-6 (Services exist), TG-8 (Controllers exist)
**Subtasks**:
1. Register MessagingService in services.yaml:
   - Service: phpbb\messaging\MessagingService
   - Inject: ConversationRepository, MessageRepository, ParticipantRepository, Connection
2. Register repositories:
   - DbalConversationRepository
   - DbalMessageRepository
   - DbalParticipantRepository
3. Define routes:
   - GET /api/conversations → ConversationsController::listConversations
   - GET /api/conversations/{id} → ConversationsController::getConversation
   - POST /api/conversations → ConversationsController::createConversation
   - ... (all 17 endpoints)
4. Configure route prefixes/defaults

**Success Criteria**:
- ✅ All services registered and auto-wired
- ✅ All 17 routes defined with correct HTTP methods
- ✅ No DI container errors (test with: symfony debug:container)
- ✅ Routes accessible in debug router

---

### **TG-10: Repository Tests (Integration)**

**Scope**: Integration tests for repository layer with real database

**Deliverables**:
- [ ] tests/phpbb/messaging/Repository/ConversationRepositoryTest.php (~100 LOC)
- [ ] tests/phpbb/messaging/Repository/MessageRepositoryTest.php (~120 LOC)
- [ ] tests/phpbb/messaging/Repository/ParticipantRepositoryTest.php (~80 LOC)

**Estimated Time**: 3 hours
**Dependencies**: TG-4 (Repositories), TG-1 (Schema)
**Test Coverage**: ~30-40 test methods

**Test Cases**:
1. ConversationRepositoryTest:
   - testFindById_Exists() → returns Conversation object
   - testFindById_NotExists() → returns NULL
   - testFindByParticipantHash_Exists() → returns existing conversation
   - testFindByParticipantHash_NotExists() → returns NULL
   - testListByUser_WithActiveState() → filters by state, returns PaginatedResult
   - testListByUser_WithPinnedState() → correct state filter
   - testListByUser_ExcludesLeftParticipants() → left_at IS NULL check
   - testInsert_CreatesConversation() → returns conversation_id
   - testInsert_GeneratedIdReused() → subsequent.insert() with same params returns same ID
   - testUpdate_ChangesTitle() → update title field
   - testDelete_CascadesToMessages() → messages also deleted
   - testDelete_CascadesToParticipants() → participants also deleted

2. MessageRepositoryTest:
   - testFindById_Exists() → returns Message
   - testFindById_NotExists() → returns NULL
   - testListByConversation_Paginated() → returns correct page
   - testListByConversation_ExcludesDeleted() → soft-delete filtering
   - testSearch_FindsByText() → LIKE query works
   - testSearch_Empty() → returns empty result for no matches
   - testInsert_CreatesMessage() → returns message_id
   - testInsert_UpdatesConversationDenormalized() → last_message_id, timestamp updated
   - testUpdate_SetsEditedAt() → edit timestamp set
   - testUpdate_IncrementsEditCount() → edit counter incremented
   - testDeletePerUser_SoftDeletes() → inserts into message_deletes
   - testIsDeletedForUser_DeletedMessage() → returns true
   - testIsDeletedForUser_NotDeleted() → returns false

3. ParticipantRepositoryTest:
   - testFindByConversation_ReturnsAll() → all participants in conversation
   - testFindByUser_ReturnsAll() → all conversations for user
   - testFindByUser_ExcludesLeftParticipants() → left_at filters
   - testInsert_CreatesParticipant() → role defaults to 'member'
   - testUpdate_ChangesRole() → role field updated
   - testUpdate_ChangesState() → state field updated (active/pinned/archived)
   - testUpdate_ChangesReadCursor() → last_read_message_id updated
   - testDelete_SoftOrHardDelete() → behavior per implementation
   - testFindByConversationAndUser_Exists() → returns single participant
   - testFindByConversationAndUser_NotExists() → returns NULL

**Success Criteria**:
- ✅ All 30+ tests pass
- ✅ Database state verified after each operation
- ✅ Pagination offsets tested
- ✅ Edge cases covered (empty results, boundaries)

---

### **TG-11: Service Tests (Unit)**

**Scope**: Unit tests for MessagingService with mocked repositories

**Deliverables**:
- [ ] tests/phpbb/messaging/MessagingServiceTest.php (~200 LOC)

**Estimated Time**: 2 hours
**Dependencies**: TG-6 (MessagingService), TG-7 (Events)
**Test Coverage**: ~20-25 test methods

**Test Cases**:
1. testCreateConversation_NewSet() → new conversation inserted, event emitted
2. testCreateConversation_ExistingSet() → returns existing conversation (idempotent)
3. testCreateConversation_TransactionRollsBack() → on repo error, transaction rolled back
4. testListConversations_WithStateFilter() → active/pinned/archived state respected
5. testGetConversation_NotParticipant() → throws exception for non-member
6. testArchiveConversation_ChangesUserState() → state set to 'archived'
7. testSendMessage_CreatesMessage() → message inserted, event emitted
8. testSendMessage_UpdatesConversationTimestamp() → denormalized fields updated
9. testSendMessage_ResetsOtherReadCursors() → other participants' cursors cleared
10. testEditMessage_WithinWindow() → message updated, edit_count incremented
11. testEditMessage_OutsideWindow() → throws EditWindowExpiration exception
12. testDeleteMessage_SoftDelete() → message marked as deleted
13. testMarkMessageRead_UpdatesCursor() → read cursor advanced
14. testAddParticipant_OwnerOnly() → non-owner throws exception
15. testAddParticipant_DuplicatePrevention() → duplicate returns error
16. testRemoveParticipant_SoftDelete() → participant left_at set

**Mocking Strategy**:
- Mock ConversationRepository
- Mock MessageRepository
- Mock ParticipantRepository
- Mock Doctrine DBAL Connection (for transaction simulation)

**Success Criteria**:
- ✅ All 20+ tests pass
- ✅ 100% of service methods tested
- ✅ Event emission verified
- ✅ Transaction flow verified

---

### **TG-12: Controller Tests (Unit)**

**Scope**: Unit tests for REST controllers

**Deliverables**:
- [ ] tests/phpbb/messaging/API/ConversationsControllerTest.php (~100 LOC)
- [ ] tests/phpbb/messaging/API/MessagesControllerTest.php (~80 LOC)
- [ ] tests/phpbb/messaging/API/ParticipantsControllerTest.php (~40 LOC)

**Estimated Time**: 2 hours
**Dependencies**: TG-8 (Controllers), TG-11 (Service tests establish patterns)
**Test Coverage**: ~17 test methods (one per endpoint)

**Test Cases**:
1. ConversationsControllerTest:
   - testListConversations_Returns200() → status 200, JSON response
   - testGetConversation_Returns200() → returns ConversationDTO + participants
   - testGetConversation_NotParticipant_Returns403() → forbidden
   - testCreateConversation_Returns201() → created status, location header
   - testArchiveConversation_Returns200() → state changed
   - testDeleteConversation_OwnerOnly_Returns403() → non-owner forbidden
   - testDeleteConversation_Owner_Returns204() → no content
   - testUpdateParticipants_OwnerOnly() → authorization check

2. MessagesControllerTest:
   - testListMessages_Returns200()
   - testSendMessage_Returns201()
   - testGetMessage_Returns200()
   - testEditMessage_Returns200()
   - testEditMessage_OutsideWindow_Returns409()
   - testDeleteMessage_Returns204()
   - testMarkMessageRead_Returns200()
   - testSearchMessages_Returns200()

3. ParticipantsControllerTest:
   - testListParticipants_Returns200()
   - testUpdateParticipant_Returns200()

**Mocking Strategy**:
- Mock MessagingService
- Use Symfony test utilities for HTTP requests

**Success Criteria**:
- ✅ All 17 tests pass
- ✅ HTTP status codes correct
- ✅ JSON serialization correct
- ✅ Error responses formatted correctly

---

### **TG-13: E2E Tests (Playwright)**

**Scope**: Browser-based workflows testing full application stack

**Deliverables**:
- [ ] tests/e2e/messaging.spec.ts (~300 LOC)

**Estimated Time**: 4-5 hours
**Dependencies**: All previous (TG-1 through TG-12)
**Test Coverage**: 5 full workflows

**E2E Scenarios**:

1. **Scenario 1: Create Conversation & Send Message**
   - Navigate to create conversation form
   - Select participants (User A, User B)
   - Enter title
   - Submit (POST /api/conversations)
   - Verify conversation created
   - Send message
   - Verify message appears in list
   - Mark as read

2. **Scenario 2: Edit Message (Success)**
   - Create conversation with message
   - Edit message within 5-minute window
   - Verify edit successful
   - Verify edit_count = 1
   - Search for edited text

3. **Scenario 3: Edit Message (Failure)**
   - Create conversation with old message (created >5 min ago via test fixture)
   - Attempt to edit
   - Verify 409 Conflict error
   - Verify message not changed

4. **Scenario 4: Delete and Restore Workflow**
   - Create conversation
   - Send message
   - Delete message
   - Verify message hidden (soft-delete)
   - Admin: restore message (future)
   - Verify message restored

5. **Scenario 5: Archive & Pin Organization**
   - Create 3 conversations
   - Pin one
   - Archive one
   - List with state filters
   - Verify correct conversations returned per state

**Test Implementation** (Playwright):
```typescript
test('Create conversation and send message', async ({ page }) => {
  await page.goto('/conversations/new');
  await page.fill('[data-testid=participant-select]', 'user2');
  await page.fill('[data-testid=title]', 'Test Conversation');
  await page.click('button[type=submit]');
  
  // Verify creation
  await expect(page).toHaveURL(/\/conversations\/\d+/);
  
  // Send message
  await page.fill('[data-testid=message-input]', 'Hello!');
  await page.click('button:has-text("Send")');
  
  // Verify message appears
  await expect(page.locator('text=Hello!')).toBeVisible();
});
```

**Success Criteria**:
- ✅ All 5 scenarios pass
- ✅ Screenshots captured for documentation
- ✅ Network requests verified (API calls)
- ✅ Error states tested

---

### **TG-14: Code Review & Standards Compliance**

**Scope**: Quality gates and standards verification

**Deliverables**:
- [ ] All files reviewed for STANDARDS.md compliance
- [ ] All SQL statements reviewed for injection safety
- [ ] All PHPDoc complete
- [ ] cs:fix passes

**Estimated Time**: 1-2 hours
**Dependencies**: All code files (TG-1 through TG-13)

**Checklist**:
- [ ] File headers present (copyright, license)
- [ ] PHPDoc on all classes/methods/properties
- [ ] Prepared statements only (no interpolation)
- [ ] No static methods in OOP code
- [ ] DI via constructor (no global)
- [ ] Naming conventions (phpbb\, PascalCase, camelCase)
- [ ] Error handling (no silent failures)
- [ ] Transactions atomic (explicit commit/rollback)
- [ ] cs:fix clean (composer cs:fix passes)

**Success Criteria**:
- ✅ All standards met
- ✅ No security issues (SQL injection, auth bypass)
- ✅ Code style consistent

---

### **TG-15: Final Verification & Documentation**

**Scope**: Integration testing and documentation generation

**Deliverables**:
- [ ] All tests passing (unit, integration, E2E)
- [ ] OpenAPI spec synchronized
- [ ] Test results documented
- [ ] Known issues (if any) documented

**Estimated Time**: 1 hour
**Dependencies**: All code + tests

**Checks**:
- [ ] Run full test suite: `composer test`
- [ ] Run E2E tests: `playwright test`
- [ ] Run cs:fix: `composer cs:fix`
- [ ] Verify routes registered: `symfony debug:router | grep messaging`
- [ ] Verify DI container: `symfony debug:container | grep messaging`

**Success Criteria**:
- ✅ 100% test pass rate
- ✅ No code style violations
- ✅ All routes registered
- ✅ DI container clean

---

## 3. Timeline & Resource Allocation

### 3.1 Estimated Hours per Task Group

| TG | Task | Est. Hours | Notes |
|----|------|-----------|-------|
| TG-1 | Database Schema | 1 | DDL only |
| TG-2 | Entities + DTOs | 2 | 9 files |
| TG-3 | Repository Interfaces | 0.5 | Interfaces only |
| TG-4 | Repository Implementations | 3 | DBAL queries |
| TG-5 | Service Interfaces | 1 | 17 methods |
| TG-6 | Service Implementation | 4 | Complex logic |
| TG-7 | Domain Events | 0.5 | Boilerplate |
| TG-8 | REST Controllers | 2.5 | 17 endpoints |
| TG-9 | DI + Routes | 1 | Configuration |
| TG-10 | Repository Tests | 3 | 30+ tests |
| TG-11 | Service Tests | 2 | 20+ tests |
| TG-12 | Controller Tests | 2 | 17 tests |
| TG-13 | E2E Tests | 4 | 5 workflows |
| TG-14 | Code Review | 1.5 | Standards gate |
| TG-15 | Final Verification | 1 | Integration check |
| **TOTAL** | **M7 Complete** | **~28-30 hours** | |

### 3.2 Execution Parallelization

**Phases Can Run in Parallel**:
- TG-10 (Repo Tests) while TG-6-7 (Services) are being implemented
- TG-11 (Service Tests) while TG-8-9 (Controllers) are being implemented
- TG-12-13 (Controller + E2E Tests) after TG-8

**Critical Path**: TG-1 → TG-2 → TG-3 → TG-4 → {TG-5, TG-6, TG-7} → TG-8 → TG-9 → {TG-10-13} → TG-14-15

**Recommended Execution Strategy**:
1. Developer 1: TG-1 through TG-9 (Infrastructure + implementation)
2. QA 1: TG-10 through TG-13 (Testing, can start after TG-4 completes)
3. Final review: TG-14-15 (Both)

---

## 4. Success Criteria (Phase Completion)

✅ **All Task Groups Complete**:
- [ ] TG-1: Database schema exists
- [ ] TG-2: All entities and DTOs defined
- [ ] TG-3: Repository interfaces defined
- [ ] TG-4: All repository methods implemented
- [ ] TG-5: Service interface defined
- [ ] TG-6: MessagingService fully implemented
- [ ] TG-7: All domain events defined
- [ ] TG-8: All 17 endpoints implemented
- [ ] TG-9: DI registration complete, routes functional
- [ ] TG-10: Repository tests passing (30+ tests)
- [ ] TG-11: Service tests passing (20+ tests)
- [ ] TG-12: Controller tests passing (17 tests)
- [ ] TG-13: E2E tests passing (5 workflows)
- [ ] TG-14: Standards compliance verified
- [ ] TG-15: Final verification complete

**Test Results**:
- ✅ composer test: 100% pass
- ✅ playwright test: 100% pass
- ✅ composer cs:fix: Clean
- ✅ All routes accessible

**Code Quality**:
- ✅ 100% prepared statements
- ✅ All PHPDoc complete
- ✅ No static methods in OOP code
- ✅ DI container registered

---

## 5. Dependencies & Blocking Issues

**No External Dependencies**: M7 can proceed immediately (Threads pattern proven, all design decisions finalized).

**Pre-Requisites**:
- ✅ Database access (test environment)
- ✅ Doctrine DBAL connection working
- ✅ Symfony 8.x DI container functioning
- ✅ PHPUnit 11.x installed
- ✅ Playwright configured for E2E

---

**Implementation Plan Ready for Phase 8 Execution!** ✅

**Status**: Approved for immediate implementation.
**Next Step**: Phase 8 — Execute all task groups in planned order.
