# Phase 2: Gap Analysis & Scope Clarification — M7 Messaging Service

**Purpose**: Identify gaps between current codebase and desired M7 implementation, assess task characteristics, and confirm scope alignment.

**Analysis Date**: 2026-04-24
**Status**: Complete

---

## 1. Gap Analysis: Current vs Desired State

### 1.1 Current State (2026-04-24)

**What exists:**
- ✅ Symfony 8.x DI framework (established)
- ✅ Doctrine DBAL with prepared statements (established)
- ✅ Service/Repo/DTO pattern proven in Threads (M6)
- ✅ REST API controller patterns (ThreadsController reference)
- ✅ Domain event infrastructure (DomainEventCollection, event bus)
- ✅ Test infrastructure (PHPUnit, Playwright, E2E setup)
- ✅ Standards documentation (.maister/docs/standards/)

**What doesn't exist:**
- ❌ `phpbb\messaging` namespace (requires creation)
- ❌ Messaging database tables (5 tables required)
- ❌ Messaging DTOs/Entities/Repositories (3 entity types)
- ❌ MessagingService facade + 5 helper services
- ❌ Conversations/Messages REST controllers (2 controllers)
- ❌ 17 API endpoints (requires implementation)
- ❌ Messaging unit tests (integration + unit)
- ❌ Messaging E2E tests
- ❌ DI registration (config/services.yaml additions)
- ❌ Domain events (ConversationCreatedEvent, MessageCreatedEvent, etc.)

### 1.2 Desired State (M7 Complete)

**Delivery scope**:
- ✅ Full phpbb\messaging service with 17 REST API endpoints
- ✅ Conversation, Message, Participant entities + DTOs
- ✅ DbalConversationRepository, DbalMessageRepository (single-table repos)
- ✅ MessagingService facade + domain events
- ✅ ConversationsController, MessagesController (REST)
- ✅ Full PHPUnit test coverage (service, integration, controller unit tests)
- ✅ Playwright E2E tests (full workflows)
- ✅ OpenAPI spec verification

### 1.3 Gap Summary

| Scope Item | Gap | Effort | Complexity |
|-----------|-----|--------|-----------|
| Database schema (5 tables) | CREATE TABLE statements | Low | Low (straightforward DDL) |
| Entities + DTOs (3 types) | 6 new entity/DTO pairs | Medium | Low (from pattern) |
| Repositories (2 concrete) | 2 repository implementations | Medium | Medium (multi-entity queries) |
| Service layer | 6 service classes (1 facade + 5 helpers) | Medium | Medium (business logic) |
| REST controllers (2) | ConversationsController, MessagesController | Medium | Medium-Low (17 methods total) |
| API endpoints (17) | 8 conversations, 7 messages, 2 participants | High | Medium (CRUD + business logic) |
| Tests (unit + integration) | ~60-80 test methods across fixtures | High | Medium-High (full coverage) |
| E2E tests | Full workflows: create, read, edit, delete, list | High | Medium (Playwright scripts) |
| Domain events (7+) | Event classes + dispatching | Low-Medium | Low (boilerplate) |
| DI registration | services.yaml entries | Low | Low (configuration) |
| **TOTAL** | **M7 Implementation** | **HIGH** | **MEDIUM** |

---

## 2. Task Characteristics (Phase 2 Detection)

Based on codebase analysis and gap assessment:

### 2.1 Five Task Characteristic Fields

| Field | Value | Reasoning |
|-------|-------|-----------|
| **has_reproducible_defect** | `false` | New feature (M7), not a bug fix. No existing defect to reproduce. |
| **modifies_existing_code** | `true` | Must modify `config/services.yaml` (add DI). May touch `src/phpbb/api/` for controller registration. |
| **creates_new_entities** | `true` | Creates 3 domain entities (Conversation, Message, Participant) and supporting infrastructure. |
| **involves_data_operations** | `true` | Heavy database operations: complex inserts/updates/queries (read cursor, participant hash, etc.). |
| **ui_heavy** | `false` | Backend service only. REST API returns JSON. No UI mockups needed (API already designed in research). |

### 2.2 Risk Assessment

**Risk Level**: MEDIUM-LOW

**Risk Factors:**
- ✅ **Low**: Architecture proven (Threads pattern)
- ✅ **Low**: Database design finalized (HLD completed)
- ✅ **Low**: API spec completed (OpenAPI from research)
- ⚠️ **Medium**: Complexity in multi-entity operations (Conversation + Participant + Message interactions)
- ⚠️ **Medium**: Time-limited edit window validation (business logic edge cases)

---

## 3. Scope Decisions & Clarifications

### 3.1 Scope Boundaries — CONFIRMED FROM RESEARCH

The research phase (M7.1) established these scope boundaries:

| Decision | Status | Rationale |
|----------|--------|-----------|
| 17 API endpoints (conversations, messages, participants) | ✅ CONFIRMED | OpenAPI spec analyzed, endpoints extracted |
| 3 service layers (Conversation, Message, Participant) | ✅ CONFIRMED | HLD specifies this decomposition |
| Single-table repositories (not ORM) | ✅ CONFIRMED | DBAL pattern from Threads established |
| Domain events in service returns | ✅ CONFIRMED | DomainEventCollection pattern from Threads |
| No UI/frontend in M7 scope | ✅ CONFIRMED | API-only implementation (React SPA consumes docs later) |
| Event-driven architecture (plugins via listeners) | ✅ CONFIRMED | HLD specifies plugin model (notifications, reporting, etc.) |
| Time-limited message edit (5-min default) | ✅ CONFIRMED | HLD specifies configurable window |
| Participant roles (owner/member/hidden) | ✅ CONFIRMED | HLD specifies 3-role enum |

**Scope Expansion Needed?** ❌ NO

All major decisions already made by research phase. M7 implementation is straightforward application of HLD + Threads pattern.

### 3.2 Clarifications RESOLVED (from Research)

| Question (from Research Phase) | Answer (Decision Log) | Impact on M7 |
|--------------------------------|----------------------|-------------|
| Thread model vs folders? | Thread-per-participant-set (WhatsApp-style) | Shapes ConversationService lookup by participant_hash |
| Edit window for messages? | Time-limited (default 5 min, configurable) | MessageService.editMessage() validates edit_count/edit_at |
| Read tracking approach? | Hybrid cursor + sparse overrides | Participant.last_read_message_id field |
| Participant visibility? | 3-role enum (owner/member/hidden) | Participant.role field, access control logic |
| Conversation organization? | Pinned + Archive (no folders) | Participant.state field (active/pinned/archived) |
| Content formatting? | Via plugin (ContentPipeline) | Message stored as raw, plugin renders |
| Counters (unread, etc.)? | Tiered hot+cold (denormalized) | Later phase (defer advanced caching) |
| Attachments? | Via plugin (phpbb\storage) | metadata JSON field for plugin data |

**All clarifications resolved.** No blockers for Phase 3.

### 3.3 Implementation Scope — DEFERRED (Future Phases)

For M7 scope clarity, these are OUT OF SCOPE (Phase 8 may include simplified versions):

| Feature | Scope | Reason | Expected Phase |
|---------|-------|--------|-----------------|
| Advanced counter reconciliation | ❌ DEFERRED | Cron-based sync can be added later | M8+ |
| Draft messages | ❌ DEFERRED | Basic CRUD works without draft storage | M8+ |
| Message search/full-text | ❌ DEFERRED | Can use simple LIKE queries initially | M8+ |
| Message reporting/moderation | ❌ DEFERRED | Via event listener (infrastructure ready) | M8+ |
| Notifications | ❌ DEFERRED | Via event listener (infrastructure ready) | M8+ |
| Permission checks | ⚠️ PARTIAL | Basic ownership checks in Phase 8, advanced roles later | M8+ |

**M7 Core Scope**: ✅ CRUD for Conversations + Messages + Participants + basic access control

---

## 4. Task Type Detection

### 4.1 Classification

**Primary Type**: **NEW FEATURE** (complex, multi-entity service)

**Sub-types**:
- Self-contained service (new namespace phpbb\messaging)
- Pattern-based (replicates Threads from M6)
- Database-heavy (5 tables, complex queries)
- Event-driven (returns DomainEventCollection)
- Integration (adds to config/services.yaml, API routes)

### 4.2 Implementation Complexity

| Component | Complexity | Est. LOC | Affected Files |
|-----------|-----------|---------|-----------------|
| Entities (3) | Low | 300 | Entity/*.php |
| DTOs + Requests (6) | Low | 400 | DTO/*.php |
| Repositories (2) | Medium | 600 | Repository/*.php |
| Services (6) | Medium-High | 1000 | *Service.php (6 files) |
| REST Controllers (2) | Medium | 500 | api/*Controller.php |
| Domain Events (7+) | Low | 150 | Event/*.php |
| Tests (60-80 methods) | Medium-High | 2000+ | tests/phpbb/messaging/ |
| E2E Tests | Medium | 1500+ | tests/e2e/*.spec.ts |
| **TOTAL** | **MEDIUM** | **6500+** | **30+ files** |

---

## 5. Phase Activation Matrix

Based on task characteristics:

| Phase | Condition | Activate? | Reason |
|-------|-----------|-----------|--------|
| **Phase 3** (TDD Red) | `has_reproducible_defect = true` | ❌ NO | New feature, no defect to reproduce |
| **Phase 4** (UI Mockups) | `ui_heavy = true` | ❌ NO | Backend service only, REST API |
| **Phase 5** (Spec + Requirements) | Always | ✅ YES | Create full service specification |
| **Phase 6** (Spec Audit) | Recommended | ✅ YES | Audit spec for completeness |
| **Phase 7** (Implementation Plan) | Always | ✅ YES | Break into task groups |
| **Phase 8** (Implementation) | Always | ✅ YES | Code all components |
| **Phase 9** (TDD Green) | Phase 3 executed | ❌ NO | Phase 3 not executed |
| **Phase 10** (Verification Options) | Always | ✅ YES | Determine verification strategy |
| **Phase 11** (Verification) | Always | ✅ YES | Code review, pragmatic, reality checks |
| **Phase 12** (E2E) | `e2e_enabled = true` (auto-set) | ✅ YES | Browser workflows for 17 endpoints |
| **Phase 13** (User Docs) | `user_docs_enabled = true` (auto-set) | ✅ YES | API guide for frontend team |
| **Phase 14** (Finalization) | Always | ✅ YES | Summary + next steps |

**Auto-Set Flags** (from characteristics):
- `e2e_enabled: true` (feature heavyweight) → Phase 12 enabled
- `user_docs_enabled: true` (new entities) → Phase 13 enabled

---

## 6. Key Findings

### 6.1 Architecture

✅ **Architecture is proven and well-documented**
- Threads pattern directly applicable
- No novel architectural patterns needed
- Clear precedent for all design decisions

### 6.2 Requirements

✅ **Requirements are complete and unambiguous**
- Research phase delivered HLD, design decisions, API spec
- No ambiguity in data model
- No conflicting design goals

### 6.3 Risk

✅ **Risk is manageable**
- Complexity is in scope (multi-entity ops) not unknowns
- Business logic is straightforward (validations, state checks)
- Testing strategy clear (unit + integration + E2E all feasible from patterns)

### 6.4 Estimates

**Estimated effort** (for orchestrator planning):
- Specification + Plan: 2-4 hours
- Implementation: 12-16 hours (service, repos, controllers, tests)
- E2E tests: 4-6 hours
- Verification + fixes: 2-4 hours
- **Total**: ~24-30 hours (full-time equivalent: 3-4 days)

---

## 7. Scope Decisions & Confirmation

### 7.1 No Critical Decisions Needed

The research phase has already resolved all major technical decisions. The gap analysis confirms:

1. ✅ Scope is clear and bounded
2. ✅ Architecture is proven (Threads pattern)
3. ✅ Requirements are finalized (HLD + OpenAPI)
4. ✅ No blocking uncertainties

### 7.2 Implementation Strategy — CONFIRMED

**Recommended approach**:
1. Proceed directly to Phase 5 (Specification Creation)
2. Phase 7 will break into parallel task groups (entities, repos, services, controllers, tests)
3. Phase 8 implements in task group order

**No scope expansion needed.** M7 is fully scoped by research phase.

---

## 8. Conclusion

**Gap Analysis Status**: ✅ COMPLETE

**Key Outcomes**:
- Current state: No messaging service exists
- Desired state: 17 endpoints, 3 entities, full test coverage (clearly defined)
- Gap: Medium effort, but low complexity (proven pattern)
- Task characteristics: 5 fields detected, no red flags
- Phases to activate: 5-14 (skip TDD Red, UI Mockups, skip TDD Green)
- Auto-set options: e2e_enabled=true, user_docs_enabled=true

**Ready to proceed to Phase 5 (Specification Creation).**

✅ All prerequisites met. No scope clarifications blocking Phase 5.
