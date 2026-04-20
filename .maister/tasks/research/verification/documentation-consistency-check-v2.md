# Documentation Consistency Audit — V2

**Date**: 2026-04-20
**Scope**: All research HLDs, decision logs, standards, cross-cutting documents, architecture docs
**Auditor**: Reality Assessor (post-F1–F14 consistency fix round)
**Status**: ⚠️ 19 Findings — 0 Critical, 4 High, 10 Medium, 5 Low

---

## F1-F14 Verification: What Was Actually Fixed?

Before new findings, let's verify the original 14 findings from V1:

| V1 Finding | Status | Evidence |
|---|---|---|
| **F1** (Threads — raw text contradicts D7) | ⚠️ **Partially Fixed** | Design Overview (line 7) now says "s9e XML default + encoding_engine". ContentPipeline section (line ~752) correctly uses `match ($post->encodingEngine)`. **BUT** Post entity comment at line 406 still says `// Content — RAW TEXT ONLY` and SQL schema at line 1845 still says `-- Content — RAW TEXT ONLY`. See new F1 below. |
| **F2** (Threads — sync `updateForumStats()` contradicts D8) | ✅ **Fixed** | HLD lines 60, 622-641 now explicitly say "Forum counters are NOT updated here — propagated via domain events to phpbb\hierarchy's ForumStatsSubscriber". System context diagram says "zero imports, zero direct calls (event-driven, eventual consistency)". No direct Hierarchy calls remain in HLD. However, `synthesis.md` (line 474) still has the old recommendation — see F4. |
| **F3** (Hierarchy — missing ForumStatsSubscriber) | ✅ **Fixed** | Hierarchy HLD lines 1345-1396 now contain `ForumStatsSubscriber` listening for `TopicCreatedEvent`, `PostCreatedEvent`, `VisibilityChangedEvent`. Includes `recalculateForumStats()` cron. Uses `TagAwareCacheInterface`. |
| **F4** (Notifications — tagged DI) | ⚠️ **Partially Fixed** | TypeRegistry (line 377+) and MethodManager (line 448+) constructors now use `RegisterNotificationTypesEvent` / `RegisterDeliveryMethodsEvent`. **BUT** component table (line 134) still says "Tagged `notification.type` services". Container diagram (line 93) still says "(tagged DI)". YAML config (lines 1148-1175) still uses `tags: [ { name: notification.type } ]`. Extension point note (line 474) still says "tagging `notification.method`". See new F2. |
| **F5** (Auth — exceptions not extending shared) | ✅ **Fixed** | Auth HLD line 1235 now reads `AccessDeniedException extends phpbb\common\Exception\AccessDeniedException`, line 1240 reads `PermissionNotFoundException extends phpbb\common\Exception\NotFoundException`. |
| **F6** (Threads — exceptions not aligned) | ✅ **Fixed** | Threads HLD directory listing (lines 209-213) now shows all exceptions extending `phpbb\common\Exception\*`. |
| **F7** (Messaging — exceptions not aligned) | ✅ **Fixed** | Messaging HLD lines 1032-1036 now show all exceptions extending `phpbb\common\Exception\*`. |
| **F8** (HLDs don't use DomainEventCollection) | ⚠️ **Partially Fixed** | Hierarchy ✅ fixed (returns `DomainEventCollection`), Messaging ✅ fixed (top-level `sendMessage`/`replyToConversation`/`editMessage`/`deleteMessageForUser` return `DomainEventCollection`), Notifications ✅ fixed (`createNotification` returns `DomainEventCollection`). **BUT** Threads ❌ still returns individual events. Hierarchy tracking/subscription also returns individual events. Messaging secondary methods use custom result types. Users has mixed returns. Storage uses response DTOs, not DomainEventCollection. See F3, F5, F6, F7. |
| **F9** (Storage — no TagAwareCacheInterface) | ✅ **Fixed** | Storage HLD component table now lists `TagAwareCacheInterface ('cache.storage')` in StorageService dependencies. |
| **F10** (Messaging — no TagAwareCacheInterface) | ✅ **Fixed** | Messaging HLD line 86 now says "Depends on TagAwareCacheInterface (`cache.messaging`) for conversation/counter caching". |
| **F11** (services-architecture.md — stale) | ✅ **Fixed** | services-architecture.md now says "JWT bearer tokens" (line 14), User Service listed without ⚠️ (line 37), critical items marked as resolved (lines 93-98). |
| **F12** (tech-stack.md — legacy only) | ✅ **Fixed** | tech-stack.md now includes "Target Architecture (New Services)" section with PHP 8.2+, PDO, JWT, PHPUnit 10, TagAwareCacheInterface, Symfony 7.x. |
| **F13** (Storage — FileNotFoundException not extending shared) | ✅ **Fixed** | Storage HLD exception directory listing shows `FileNotFoundException # extends phpbb\common\Exception\NotFoundException` etc. |
| **F14** (Hierarchy — exceptions not extending shared) | ✅ **Fixed** | Hierarchy HLD directory listing (lines 707-710) shows all exceptions extending `phpbb\common\Exception\*`. |

### V1 Fix Summary: 10/14 fully fixed, 4/14 partially fixed (residual issues captured below).

---

## New Findings

### F1: Threads HLD — Residual "RAW TEXT ONLY" Comments in Post Entity and SQL Schema

- **Severity**: Medium
- **File(s)**: `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md`
- **Issue**: While the Design Overview and ContentPipeline sections were correctly updated to s9e XML default + encoding_engine, two inline comments were missed:
  - Line 406: `// Content — RAW TEXT ONLY` (in Post entity class)
  - Line 1845: `-- Content — RAW TEXT ONLY` (in SQL schema CREATE TABLE)
- **Expected**: Comments should say `// Content — s9e XML default (see encoding_engine)` or similar. The Post entity should ideally also include an `encodingEngine` property reflecting the new column.
- **Line(s)**: 406, 1845

---

### F2: Notifications HLD — Residual Tagged DI References in Diagrams, Component Table, YAML Config, and Extension Point Notes

- **Severity**: High
- **File(s)**: `.maister/tasks/research/2026-04-19-notifications-service/outputs/high-level-design.md`
- **Issue**: The TypeRegistry and MethodManager constructors were updated to event-based registration (lines 377+, 448+), but four locations still reference the old tagged DI pattern:
  1. **Line 93**: Container diagram label says `│ (tagged DI)  │` for TypeRegistry
  2. **Line 134**: Component overview table says "Extensible type registration via tagged DI services, type lookup, validation | Tagged `notification.type` services"
  3. **Line 135**: Component overview table says `Tagged `notification.method` services` for MethodManager
  4. **Line 474**: Extension point note says "Register new delivery method by implementing `NotificationMethodInterface` and tagging `notification.method`."
  5. **Lines 1148-1175**: YAML DI config still uses `tags: [ { name: notification.type } ]` and `tags: [ { name: notification.method } ]` for built-in notification types and methods
- **Expected**: All references to tagged DI must be replaced with event-based registration (`RegisterNotificationTypesEvent`, `RegisterDeliveryMethodsEvent`). The YAML config should NOT tag types/methods with `notification.type` / `notification.method`; instead, types should subscribe to `RegisterNotificationTypesEvent` and register themselves.
- **Line(s)**: 93, 134, 135, 474, 1148-1175

---

### F3: Threads HLD — Facade Interface Returns Individual Typed Events, Not DomainEventCollection

- **Severity**: High
- **File(s)**: `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md`
- **Issue**: The Design Overview (line 7) correctly states "service methods return `DomainEventCollection` objects", but the `ThreadsServiceInterface` (lines 498-544) defines return types as individual events:
  - `createTopic(…): TopicCreatedEvent` (line 498)
  - `createReply(…): PostCreatedEvent` (line 500)
  - `editPost(…): PostEditedEvent` (line 502)
  - `softDeletePost(…): VisibilityChangedEvent` (line 504)
  - `restorePost(…): VisibilityChangedEvent` (line 506)
  - `hardDeletePost(…): PostHardDeletedEvent` (line 508)
  - And all other mutation methods through line 522
  - Also `DraftServiceInterface` (lines 741-747): `save(…): DraftSavedEvent`, `delete(…): DraftDeletedEvent`
- **Expected**: Per DOMAIN_EVENTS.md: "All service mutations MUST return `DomainEventCollection`". Every mutation method should return `DomainEventCollection`, not individually typed events.
- **Line(s)**: 498-522, 741-747

---

### F4: Threads Synthesis Document — Stale Recommendation for Synchronous Hierarchy Calls

- **Severity**: Low
- **File(s)**: `.maister/tasks/research/2026-04-18-threads-service/analysis/synthesis.md`
- **Issue**: Line 474 still recommends: "Option C. `HierarchyService` exposes `updateForumStats()` and `updateForumLastPost()`. Threads calls these within its transaction." This was superseded by D8 (event-driven) in the cross-cutting decisions plan.
- **Expected**: Should be annotated as `[SUPERSEDED by D8 — event-driven]` or updated to reference the event-based pattern.
- **Line(s)**: 474

---

### F5: Hierarchy HLD — Tracking & Subscription Methods Return Individual Events, Not DomainEventCollection

- **Severity**: High
- **File(s)**: `.maister/tasks/research/2026-04-18-hierarchy-service/outputs/high-level-design.md`
- **Issue**: The CRUD/tree operations correctly return `DomainEventCollection` (lines 749-782), but tracking and subscription methods return individual events:
  - `markForumRead(…): ForumMarkedReadEvent` (line 805)
  - `markAllRead(…): AllForumsMarkedReadEvent` (line 810)
  - `subscribe(…): ForumSubscribedEvent` (line 825)
  - `unsubscribe(…): ForumUnsubscribedEvent` (line 827)
- **Expected**: Per DOMAIN_EVENTS.md, all mutations (including tracking and subscription operations that have side effects) should return `DomainEventCollection`.
- **Line(s)**: 805, 810, 825, 827

---

### F6: Messaging HLD — Secondary Mutation Methods Return Custom Result Types Instead of DomainEventCollection

- **Severity**: High
- **File(s)**: `.maister/tasks/research/2026-04-19-messaging-service/outputs/hld.md`
- **Issue**: The top 4 message CRUD methods correctly return `DomainEventCollection` (lines 309-352), but all remaining mutation methods return custom result types:
  - `markAsRead(…): MarkReadResult` (line 387)
  - `markAsUnread(…): MarkUnreadResult` (line 395)
  - `pinConversation(…): StateChangedResult` (line 399)
  - `unpinConversation(…): StateChangedResult` (line 400)
  - `archiveConversation(…): StateChangedResult` (line 401)
  - `unarchiveConversation(…): StateChangedResult` (line 402)
  - `addParticipant(…): ParticipantAddedResult` (line 406)
  - `removeParticipant(…): ParticipantRemovedResult` (line 407)
  - `leaveConversation(…): ParticipantLeftResult` (line 408)
  - `muteConversation(…): MuteResult` (line 409)
  - `unmuteConversation(…): MuteResult` (line 410)
  - `saveDraft(…): DraftSavedResult` (line 414)
  - `deleteDraft(…): DraftDeletedResult` (line 416)
  - All rule methods (lines 420-424)
  
  Additionally, §4.2 "Result Objects" (line 433) still defines `MessageSentResult` with `public readonly array $events` — a custom wrapper pattern, not `DomainEventCollection`.
- **Expected**: All mutation methods should return `DomainEventCollection`. The custom result types (`MarkReadResult`, `StateChangedResult`, `ParticipantAddedResult`, etc.) should be either eliminated (data carried in events) or the pattern should be documented as an intentional exception to the standard with justification.
- **Line(s)**: 387-424, 433-441

---

### F7: Users HLD — Mixed Return Patterns: Some Methods Return DomainEventCollection, Some Return void

- **Severity**: Medium
- **File(s)**: `.maister/tasks/research/2026-04-19-users-service/outputs/high-level-design.md`
- **Issue**: Per the service method table (lines 203-250), most mutation methods correctly return `DomainEventCollection`, but several state-changing methods return `void`:
  - `BanService::unban(int $banId): void` (line 231)
  - `BanService::assertNotBanned(…): void` (line 235) — this one is actually a check, not a mutation; acceptable
  - `ShadowBanService::remove(int $shadowBanId): void` (line 238)
  - `AdminUserService::delete(DeleteUserDTO): void` (line 248)
  - `AdminUserService::deactivate(int $userId, InactiveReason): void` (line 249)
  - `AdminUserService::activate(int $userId): void` (line 250)
  - `AdminUserService::changeType(int $userId, UserType): void` (line 251)
- **Expected**: All mutation methods (unban, remove shadow ban, delete/deactivate/activate/changeType) should return `DomainEventCollection` since they all produce domain events (`UserUnbannedEvent`, `UserShadowBanRemovedEvent`, `UserDeletedEvent`, `UserDeactivatedEvent`, `UserActivatedEvent`, `UserTypeChangedEvent`).
- **Line(s)**: 231, 238, 248-251

---

### F8: Users HLD — No TagAwareCacheInterface / cache.user Pool Referenced

- **Severity**: Medium
- **File(s)**: `.maister/tasks/research/2026-04-19-users-service/outputs/high-level-design.md`
- **Issue**: No mention of `TagAwareCacheInterface` or a `cache.user` pool anywhere in the HLD. The cross-cutting decisions plan (D1) states: "All services MUST use the new `phpbb\cache` service via `TagAwareCacheInterface`. No per-service custom caching. No exceptions." The Users service is a high-read-volume service (batch `findDisplayByIds()` calls from every other service), making cache integration critical.
- **Expected**: Users service should declare dependency on `TagAwareCacheInterface` via `cache.user` pool, at minimum for `UserDisplayService::findDisplayByIds()` (hot path), `BanService::isUserBanned()`, and `ShadowBanService::isShadowBanned()`.
- **Line(s)**: N/A (missing entirely)

---

### F9: Users HLD — Exception Hierarchy Not Documented

- **Severity**: Medium
- **File(s)**: `.maister/tasks/research/2026-04-19-users-service/outputs/high-level-design.md`
- **Issue**: The directory listing (line 193) only says `├── Exception\  # 14+ typed exceptions with HTTP codes` — there is no listing of which exceptions exist, what they extend, or how they map to `phpbb\common\Exception\*`. All other HLDs (Hierarchy, Threads, Messaging, Storage, Auth) explicitly list their exception classes with inheritance documented.
- **Expected**: An explicit listing of all 14+ exceptions with their parent classes from `phpbb\common\Exception\*`, similar to what Threads (lines 209-213) and Storage do.
- **Line(s)**: 193

---

### F10: Storage HLD — Facade Returns Response DTOs, Not DomainEventCollection for Mutations

- **Severity**: Medium
- **File(s)**: `.maister/tasks/research/2026-04-19-storage-service/outputs/high-level-design.md`
- **Issue**: `StorageServiceInterface` (lines 406-452) returns custom response DTOs for mutation methods:
  - `store(…): FileStoredResponse`
  - `delete(…): FileDeletedResponse`
  - `claim(…): FileClaimedResponse`
  
  These are response DTOs, not `DomainEventCollection`. The events (`FileStoredEvent`, `FileClaimedEvent`, `FileDeletedEvent`) exist but appear to be dispatched internally by the service implementation (line 488+), contradicting the standard "services return events, controllers dispatch" pattern.
- **Expected**: Mutation methods should return `DomainEventCollection`. If the response DTO pattern exists to carry return data (e.g., generated UUID), the events in the collection can carry this data and the controller can extract it.
- **Line(s)**: 406-452

---

### F11: Notifications HLD — `deleteNotifications()` and `markRead()`/`markAllRead()` Return Wrong Types

- **Severity**: Medium
- **File(s)**: `.maister/tasks/research/2026-04-19-notifications-service/outputs/high-level-design.md`
- **Issue**: While `createNotification()` was correctly updated to return `DomainEventCollection` (line 201), other mutation methods still have non-compliant return types:
  - `markRead(…): int` (line 207) — returns unread count instead of DomainEventCollection
  - `markAllRead(…): int` (line 213) — returns 0 instead of DomainEventCollection
  - `deleteNotifications(…): void` (line 219) — returns void
- **Expected**: All mutation methods should return `DomainEventCollection`. The unread count can be retrieved separately or carried as event payload.
- **Line(s)**: 207, 213, 219

---

### F12: Threads HLD — CounterServiceInterface Contains Forum-Level Counter Methods (D8 Violation Residue)

- **Severity**: Medium
- **File(s)**: `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md`
- **Issue**: `CounterServiceInterface` (lines 657-687) includes methods for forum-level counter operations:
  - `incrementTopicCounters(int $forumId, …): void` (line 657)
  - `decrementTopicCounters(int $forumId, …): void` (line 665)
  - `transferTopicCounters(int $forumId, …): void` (line 673)
  - `syncForumCounters(int $forumId): void` (line 687)
  
  Per D8: "Threads is completely unaware of Hierarchy." Forum counters are Hierarchy's responsibility, updated via `ForumStatsSubscriber`. The CounterService docstrings for post-level methods (lines 622-641) correctly state "Forum counters are NOT updated here", but the forum-level methods still exist in the interface.
- **Expected**: Remove `incrementTopicCounters`, `decrementTopicCounters`, `transferTopicCounters`, and `syncForumCounters` from Threads' `CounterServiceInterface`. These belong in Hierarchy. Threads CounterService should only manage topic-level counters (topic_posts_approved, etc.) and emit events for Hierarchy to handle forum-level changes.
- **Line(s)**: 657-687

---

### F13: Cross-Cutting Assessment — Stale Mermaid Diagram, "NOT RESEARCHED" Labels, and TODO References

- **Severity**: Medium
- **File(s)**: `.maister/tasks/research/cross-cutting-assessment.md`
- **Issue**: Multiple stale references that should have been updated after decisions were finalized:
  1. **Line 78**: Mermaid diagram node `USER["⚠️ User Service<br/>(NOT RESEARCHED)"]` with `style USER fill:#ff6666` — User Service WAS researched at `2026-04-19-users-service/`
  2. **Line 148**: §6.1 heading still reads `❌ CRITICAL: User Service / User Management — NOT RESEARCHED` — should be marked ✅ RESOLVED
  3. **Line 309**: "Define Hierarchy's Counter Update API" still marked 🔜 Deferred with reference to `TODO-forum-counter-contract.md` — this was RESOLVED by D8
  4. **Line 311**: "Address post_text format migration" still marked 🔜 Deferred with reference to `TODO-content-storage-migration.md` — this was RESOLVED by D7
  5. **Lines 340-341**: "Remaining open items" still list content storage migration and forum counter contract as TODO — both RESOLVED
  6. **Line 344**: Bottom line text still references "Two medium-priority items are tracked as research TODOs" — these are all resolved
- **Expected**: Mermaid diagram should show User Service as green/resolved. §6.1 should be ✅ RESOLVED. §9 items 5 and 7 should be ✅ Done. Bottom line should reflect that all 5 originally-blocking items are resolved.
- **Line(s)**: 78, 148, 309, 311, 340-341, 344

---

### F14: Services-Architecture.md — "raw text storage" in Threads Row

- **Severity**: Low
- **File(s)**: `.maister/docs/project/services-architecture.md`
- **Issue**: Line 40, Threads Service row still says "Lean core + plugins, raw text storage, hybrid counters" — should reference s9e XML default + encoding_engine.
- **Expected**: "Lean core + plugins, s9e XML default + encoding_engine, event-driven counters"
- **Line(s)**: 40

---

### F15: Cross-Cutting Assessment — Notation 9 (Threads → Hierarchy dependency as "Synchronous, clean")

- **Severity**: Low
- **File(s)**: `.maister/tasks/research/cross-cutting-assessment.md`
- **Issue**: §4 "Dependency Analysis" section states: "Threads → Hierarchy (sync calls to `updateForumStats`, `updateForumLastPost`) — clean". This describes the OLD synchronous pattern that was superseded by D8 (event-driven). After D8, the dependency is event-based (dashed arrow in Mermaid), not sync.
- **Expected**: Should read "Threads → Hierarchy (via domain events: TopicCreatedEvent, PostCreatedEvent, etc.) — eventual consistency, clean"
- **Line(s)**: ~line 99 in §4 (One-way dependencies section)

---

### F16: Messaging HLD — Domain Events Don't Extend DomainEvent Base Class

- **Severity**: Medium
- **File(s)**: `.maister/tasks/research/2026-04-19-messaging-service/outputs/hld.md`
- **Issue**: Domain event classes defined in §4.3 (lines 466-547) use bare constructors with service-specific payloads, but do NOT extend `phpbb\common\Event\DomainEvent`. All events per DOMAIN_EVENTS.md MUST contain `entityId`, `actorId`, `occurredAt` and extend the base class.
  - `ConversationCreated` — has `conversationId`, `creatorId` but no `occurredAt` via base class, no `extends DomainEvent`
  - `MessageDelivered` — has `authorId`, `recipientId` but no standardized `entityId`/`actorId`, no `extends DomainEvent`
  - `MessageRead` — similar: custom structure, no base class
  - All other events follow same non-standard pattern
- **Expected**: All events should declare `extends DomainEvent` (or `extends phpbb\common\Event\DomainEvent`). Payloads should map to the standard base fields plus additional custom fields.
- **Line(s)**: 466-547

---

### F17: Hierarchy HLD — Missing cache.hierarchy Pool Reference in Constructor

- **Severity**: Low
- **File(s)**: `.maister/tasks/research/2026-04-18-hierarchy-service/outputs/high-level-design.md`
- **Issue**: Per D1, all services must use `TagAwareCacheInterface` via pool `cache.hierarchy`. The `ForumStatsSubscriber` at line 1349 does inject `TagAwareCacheInterface`, but the HierarchyService facade constructor and the ForumRepository / TreeService constructors don't show `TagAwareCacheInterface` as a dependency. The cross-cutting decisions plan explicitly says "Hierarchy HLD: Add cache pool `cache.hierarchy` for nested-set tree". The cache is only visible in the stats subscriber, not in the main service for tree caching.
- **Expected**: `HierarchyService` facade or `TreeService`/`ForumRepository` should have `TagAwareCacheInterface` in their constructor signatures with pool `cache.hierarchy` for caching the nested-set tree, forum list, and parent chain data.
- **Line(s)**: ~738 (HierarchyServiceInterface), ~850 (ForumRepository)

---

### F18: Notifications HLD — `createNotification()` Has Wrong Dispatch Pattern

- **Severity**: Medium
- **File(s)**: `.maister/tasks/research/2026-04-19-notifications-service/outputs/high-level-design.md`
- **Issue**: Line 1048 shows: `$this->notificationService->createNotification(…)` called from "Forum code (post creation, PM sending, etc.)" directly. Per DOMAIN_EVENTS.md, services should NOT be called directly for side-effects from other services' flows. Instead, Threads should emit `PostCreatedEvent` and a Notifications subscriber should react to it. The described pattern creates direct coupling between services.
- **Expected**: The integration example should show a `NotificationSubscriber` listening to `PostCreatedEvent` / `MessageSentEvent` / etc., NOT direct calls from "forum code" to `notificationService->createNotification()`. This is consistent with the macrokernel event-driven architecture.
- **Line(s)**: 1044-1060, 1389, 1484

---

### F19: Threads HLD — Post Entity Missing `encodingEngine` Property

- **Severity**: Low
- **File(s)**: `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md`
- **Issue**: The Post entity (lines 395-430) doesn't include an `encodingEngine` property despite the D7 decision adding `encoding_engine VARCHAR(16) DEFAULT 's9e'` to `phpbb_posts`. The ContentPipeline section (line 752+) references `$post->encodingEngine` in the `match` expression, but the property doesn't exist on the entity.
- **Expected**: Post entity should include `public readonly string $encodingEngine` with a default of `'s9e'`.
- **Line(s)**: 395-430

---

## Cross-Service Contract Verification

### Event Producer → Consumer Alignment

| Event | Producer (Service) | Registered Consumer(s) | Status |
|---|---|---|---|
| `TopicCreatedEvent` | Threads | Hierarchy (ForumStatsSubscriber), Notifications (subscriber) | ✅ Aligned |
| `PostCreatedEvent` | Threads | Hierarchy (ForumStatsSubscriber), Users (UserCounterService), Storage (AttachmentPlugin), Notifications | ✅ Aligned |
| `VisibilityChangedEvent` | Threads | Hierarchy (ForumStatsSubscriber), Users (UserCounterService) | ✅ Aligned |
| `PostSoftDeletedEvent` | Threads | Users (UserCounterService) | ✅ Aligned |
| `PostRestoredEvent` | Threads | Users (UserCounterService) | ✅ Aligned |
| `PostHardDeletedEvent` | Threads | Storage (AttachmentPlugin), Users | ✅ Aligned |
| `TopicDeletedEvent` | Threads | Hierarchy (ForumStatsSubscriber), Users | ✅ Aligned |
| `MessageDelivered` | Messaging | Notifications (subscriber) | ✅ Aligned |
| `UserCreatedEvent` | Users | Notifications (welcome?) | ✅ Aligned |
| `UserDeletedEvent` | Users | Auth, Threads, Messaging, Notifications, Hierarchy (cascade) | ✅ Aligned |
| `FileStoredEvent` | Storage | ThumbnailListener | ✅ Internal |
| `ForumCreatedEvent` | Hierarchy | Cache invalidation, plugins | ✅ Internal |
| `RegisterForumTypesEvent` | Hierarchy (boot) | Forum type plugins | ✅ Aligned |
| `RegisterNotificationTypesEvent` | Notifications (boot) | Type plugins | ✅ Aligned |
| `RegisterDeliveryMethodsEvent` | Notifications (boot) | Method plugins | ✅ Aligned |

**No producer-consumer mismatches detected.** All advertised events have at least one documented consumer.

### Inter-HLD Contradictions

| Area | HLD A Says | HLD B Says | Status |
|---|---|---|---|
| Hierarchy ↔ Threads coupling | Threads: "zero imports, zero direct calls" | Hierarchy: ForumStatsSubscriber listens to Threads events | ✅ Consistent |
| Auth → User dependency | Auth: imports User entity + UserRepo | Users: exports these interfaces | ✅ Consistent |
| Content format | Threads: "s9e XML default + encoding_engine" | services-architecture: "s9e XML default, encoding_engine column" | ✅ Consistent (except services-arch line 40, see F14) |
| Cache approach | All HLDs reference TagAwareCacheInterface | STANDARDS.md mandates it | ⚠️ Users HLD missing explicit reference (F8) |

---

## Internal Consistency Checks (Within Each HLD)

| HLD | Section A | Section B | Consistent? |
|---|---|---|---|
| **Threads** | Design Overview: "return `DomainEventCollection` objects" | Interface: returns individual events | ❌ **Inconsistent** (F3) |
| **Threads** | ContentPipeline: `$post->encodingEngine` | Post Entity: no `encodingEngine` property | ❌ **Inconsistent** (F19) |
| **Threads** | ADR-004: "Forum counters propagated via domain events" | CounterServiceInterface: has `incrementTopicCounters(forumId)` | ❌ **Inconsistent** (F12) |
| **Hierarchy** | CRUD returns `DomainEventCollection` | Tracking/subscription returns individual events | ⚠️ **Inconsistent** (F5) |
| **Notifications** | TypeRegistry uses `RegisterNotificationTypesEvent` | YAML config tags types with `notification.type` | ❌ **Inconsistent** (F2) |
| **Notifications** | Design: event-based registration | Component table: "tagged DI" | ❌ **Inconsistent** (F2) |
| **Messaging** | Top-4 methods return `DomainEventCollection` | Secondary methods return custom Result types | ⚠️ **Inconsistent** (F6) |
| **Messaging** | §4.2 defines `MessageSentResult {events[]}` | §4.1 sendMessage returns `DomainEventCollection` | ❌ **Inconsistent** (F6) |
| **Storage** | Events dispatched internally by service | Standard: controllers dispatch, services return | ⚠️ **Inconsistent** (F10) |
| **Users** | Most mutations return DomainEventCollection | BanService, ShadowBan, Admin methods return void | ⚠️ **Inconsistent** (F7) |

---

## Summary Table

| HLD / Document | V1 Score | V2 Score | Remaining Issues |
|---|---|---|---|
| Threads Service (2026-04-18) | ❌ 35% | ⚠️ 70% | F1 (raw text comments), F3 (individual event returns), F12 (forum counters in CounterService), F19 (missing encodingEngine) |
| Hierarchy Service (2026-04-18) | ⚠️ 55% | ⚠️ 85% | F5 (tracking/sub return individual events), F17 (cache.hierarchy missing from facade) |
| Auth Service (2026-04-18) | ⚠️ 65% | ✅ 95% | Minor — fully aligned on all standards |
| Notifications Service (2026-04-19) | ⚠️ 45% | ⚠️ 65% | F2 (tagged DI residue in 5 locations), F11 (void/int returns), F18 (wrong dispatch pattern) |
| Messaging Service (2026-04-19) | ⚠️ 50% | ⚠️ 75% | F6 (custom result types), F16 (events don't extend DomainEvent) |
| Storage Service (2026-04-19) | ⚠️ 60% | ⚠️ 80% | F10 (DTO returns, not DomainEventCollection) |
| Users Service (2026-04-19) | ⚠️ 55% | ⚠️ 75% | F7 (void returns), F8 (no cache reference), F9 (exceptions undocumented) |
| Cache Service (2026-04-19) | ✅ 100% | ✅ 100% | None |
| services-architecture.md | ⚠️ Stale | ⚠️ 92% | F14 (raw text in Threads row) |
| tech-stack.md | ⚠️ Stale | ✅ 95% | Minor — Target Architecture section added |
| cross-cutting-assessment.md | — | ⚠️ 70% | F13 (stale User Service labeling, TODO refs), F15 (stale sync dependency) |
| cross-cutting-decisions-plan.md | — | ✅ 100% | Source of truth — fully consistent |
| DOMAIN_EVENTS.md | — | ✅ 100% | Standard is clear and correct |
| COUNTER_PATTERN.md | — | ✅ 100% | Standard is clear and correct |
| STANDARDS.md | — | ✅ 100% | Correctly updated with all standards |

---

## Severity Distribution

| Severity | Count | Findings |
|---|---|---|
| Critical | 0 | — |
| High | 4 | F2, F3, F5, F6 |
| Medium | 10 | F1, F7, F8, F9, F10, F11, F12, F13, F16, F18 |
| Low | 5 | F4, F14, F15, F17, F19 |

---

## Deployment Decision

### ⚠️ CONDITIONALLY READY — Fix High-Severity Items Before Implementation

**Improvement over V1**: The F1-F14 round resolved all Critical issues and most High/Medium ones. Documentation is significantly more consistent. The core architectural decisions are now properly propagated to the key decision points in each HLD.

**Remaining risk**: The 4 High-severity issues all relate to **DomainEventCollection return types** and **Notifications tagged DI residue**. These are not blocking for initial implementation but MUST be resolved before the affected services' implementation begins:

1. **F2** (Notifications tagged DI residue) — Must fix before Notifications implementation. YAML config will be copy-pasted into actual service files.
2. **F3** (Threads individual event returns) — Must fix before Threads implementation. The interface is the contract developers code against.
3. **F5** (Hierarchy tracking/subscription individual events) — Must fix before Hierarchy implementation.
4. **F6** (Messaging custom result types) — Must fix before Messaging implementation.

**Recommendation**: 
- **Phase 0-1** (Infrastructure, Cache) can proceed immediately — no documentation inconsistencies.
- **Phase 2** (Users) — Fix **F7, F8, F9** first (void returns, cache missing, exceptions undocumented).
- **Phase 3** (Auth) — Ready to proceed, auth HLD is 95% clean.
- **Phase 4** (REST API) — Ready to proceed.
- **Phase 5a** (Hierarchy) — Fix **F5, F17** first.
- **Phase 5b** (Storage) — Fix **F10** first.
- **Phase 6** (Threads) — Fix **F1, F3, F12, F19** first.
- **Phase 7** (Messaging) — Fix **F6, F16** first.
- **Phase 8** (Notifications) — Fix **F2, F11, F18** first.

**GO for Phases 0-4.** Fix HLD issues for each remaining phase before that phase begins.

---

*Audit completed 2026-04-20. 19 findings across all research outputs. No Critical issues remaining. Standards propagation from cross-cutting decisions is 80%+ complete but requires a targeted pass on return types and stale references.*
