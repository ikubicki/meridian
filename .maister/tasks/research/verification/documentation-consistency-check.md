# Documentation Consistency Audit

**Date**: 2026-04-20
**Scope**: All research HLDs, decision logs, standards, cross-cutting documents, architecture docs
**Status**: ⚠️ 14 Findings — 2 Critical, 5 High, 5 Medium, 2 Low

---

## Finding 1: Threads HLD — Raw Text Storage Contradicts D7 (s9e XML Default)

- **Severity**: Critical
- **File(s)**: `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md`
- **What it says**: Lines 7, 10, 406, 752, 1818 — "Content storage is **raw text only** — a single `post_text` column with no cached HTML or metadata" / "Raw text only storage — single `post_text` column, no `plugin_metadata`, no `rendered_html`; full parse+render on every display (ADR-001)"
- **What it should say**: Per cross-cutting decision D7 (`cross-cutting-decisions-plan.md`): "Keep s9e XML as the default storage format. Add `encoding_engine VARCHAR(16) NOT NULL DEFAULT 's9e'` column to `phpbb_posts`." ContentPipeline reads `encoding_engine` to determine processing.
- **Action needed**: Amend Threads HLD ADR-001. Replace "raw text only" with s9e XML default + encoding_engine field. Update ContentPipeline section to show format-aware rendering via `match ($post->encodingEngine)`. The migration notes at line 2045 mention `S9eCompatPlugin` — this is now the PRIMARY approach, not a migration shim.

---

## Finding 2: Threads HLD — Synchronous `updateForumStats()` Contradicts D8 (Event-Driven)

- **Severity**: Critical
- **File(s)**: `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md` (lines 60, 1685, 1719-1720, 1895-1897); `.maister/tasks/research/2026-04-18-threads-service/outputs/decision-log.md` (line 153); `.maister/tasks/research/2026-04-18-threads-service/outputs/research-report.md` (lines 1089-1113, 1205-1206, 1363)
- **What it says**: "phpbb\hierarchy — Threads calls `updateForumStats()` and `updateForumLastPost()` synchronously within the same transaction" / CounterService code shows `$this->hierarchyService->updateForumStats(...)` / Dependency table lists these as "Synchronous (in-transaction)"
- **What it should say**: Per cross-cutting decision D8: "Threads emits events. Hierarchy consumes them. Threads is completely unaware of Hierarchy." Threads MUST NOT import or call any Hierarchy method. Event-driven, eventual consistency.
- **Action needed**: Remove `$this->hierarchyService` dependency from Threads entirely. Remove `updateForumStats()` / `updateForumLastPost()` calls from CounterService. Remove Hierarchy from the system context diagram's sync dependency. Ensure `TopicCreatedEvent`, `PostCreatedEvent`, etc. carry sufficient data for Hierarchy's `ForumStatsSubscriber` to update counters independently.

---

## Finding 3: Hierarchy HLD — Missing `ForumStatsSubscriber` (D8 Not Propagated)

- **Severity**: High
- **File(s)**: `.maister/tasks/research/2026-04-18-hierarchy-service/outputs/high-level-design.md`
- **What it says**: No `ForumStatsSubscriber` defined. No listener for `TopicCreatedEvent`, `PostCreatedEvent`, `TopicDeletedEvent`, or `PostDeletedEvent`. The `ForumStats` value object exists (line 251), but there is no mechanism to update it from Threads events.
- **What it should say**: Per D8: "Hierarchy registers event listeners for `TopicCreatedEvent`, `PostCreatedEvent`, `TopicDeletedEvent`, `PostDeletedEvent`" + contains `recalculateForumStats()` cron job.
- **Action needed**: Add `ForumStatsSubscriber` to Hierarchy HLD listening for Threads events. Add `recalculateForumStats()` method. Document counter update logic and the COUNTER_PATTERN.md reference.

---

## Finding 4: Notifications HLD — Tagged DI Contradicts Macrokernel Decision (§7.1)

- **Severity**: High
- **File(s)**: `.maister/tasks/research/2026-04-19-notifications-service/outputs/high-level-design.md` (lines 7, 15, 93, 134-135, 377, 388-392, 432-436, 1070-1091)
- **What it says**: "extensible type/method registry (tagged DI services)" / `TypeRegistry` uses `iterable $taggedTypes` injected via DI / YAML config uses `phpbb\di\service_collection` with `tag: notification.type` and `tag: notification.method`
- **What it should say**: Per cross-cutting assessment §7.1 (resolved): "Tagged DI is **dropped entirely**. All services adopt a unified macrokernel architecture: domain service core + extending plugins." Notification types and delivery methods must use event-based registration (same as `RegisterForumTypesEvent` in Hierarchy).
- **Action needed**: Replace `NotificationTypeRegistry` tagged DI injection with a `RegisterNotificationTypesEvent` dispatched at boot. Replace `NotificationMethodManager` tagged DI with `RegisterDeliveryMethodsEvent`. Remove all `service_collection` and tag references from YAML config.

---

## Finding 5: Auth Service (Original) — Per-Service Exceptions Not Extending `phpbb\common\Exception\*`

- **Severity**: High
- **File(s)**: `.maister/tasks/research/2026-04-18-auth-service/outputs/high-level-design.md` (lines 147-149, 1235, 1240)
- **What it says**: `AccessDeniedException extends \RuntimeException` / `PermissionNotFoundException extends \InvalidArgumentException` — both under `phpbb\auth\Exception\`
- **What it should say**: Per D4 (`cross-cutting-decisions-plan.md`): All services use `phpbb\common\Exception\*` base classes. `AccessDeniedException` should extend `phpbb\common\Exception\AccessDeniedException` (maps to 403). `PermissionNotFoundException` should extend `phpbb\common\Exception\PhpbbException` or be an `\InvalidArgumentException` (programming error, not user-facing).
- **Action needed**: Change inheritance to `phpbb\common\Exception\AccessDeniedException` (which provides the HTTP 403 mapping centrally). Remove per-service exception hierarchy that duplicates the shared package.

---

## Finding 6: Threads HLD — Per-Service Exceptions Not Aligned with D4

- **Severity**: High
- **File(s)**: `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md` (lines 215-219, 1703-1705)
- **What it says**: Defines `phpbb\threads\exception\TopicNotFoundException`, `PostNotFoundException`, `DraftNotFoundException`, `InvalidVisibilityTransitionException`, `TopicLockedException` — no mention of extending `phpbb\common\Exception\*`
- **What it should say**: Per D4: `TopicNotFoundException` / `PostNotFoundException` / `DraftNotFoundException` should extend `phpbb\common\Exception\NotFoundException` (HTTP 404). `TopicLockedException` should extend `phpbb\common\Exception\ConflictException` (HTTP 409). `InvalidVisibilityTransitionException` should extend `phpbb\common\Exception\ValidationException` (HTTP 422).
- **Action needed**: Update exception hierarchy to extend shared base classes from `phpbb\common\Exception\`.

---

## Finding 7: Messaging HLD — Per-Service Exceptions Not Aligned with D4

- **Severity**: High
- **File(s)**: `.maister/tasks/research/2026-04-19-messaging-service/outputs/hld.md` (lines 1029-1033)
- **What it says**: Defines `ConversationNotFoundException`, `MessageNotFoundException`, `EditWindowExpiredException`, `NotParticipantException`, `MaxRecipientsExceededException` under `phpbb\messaging\exception\`
- **What it should say**: Per D4: `ConversationNotFoundException` / `MessageNotFoundException` → extend `phpbb\common\Exception\NotFoundException`. `EditWindowExpiredException` → extend `phpbb\common\Exception\ConflictException`. `NotParticipantException` → extend `phpbb\common\Exception\AccessDeniedException`. `MaxRecipientsExceededException` → extend `phpbb\common\Exception\ValidationException`.
- **Action needed**: Update exception hierarchy to extend shared base classes.

---

## Finding 8: HLDs Do Not Use `DomainEventCollection` Return Type

- **Severity**: Medium
- **File(s)**:
  - Threads: `.../2026-04-18-threads-service/outputs/high-level-design.md` (line 498-544) — returns individual event objects: `createTopic(): TopicCreatedEvent`, `createReply(): PostCreatedEvent`, etc.
  - Hierarchy: `.../2026-04-18-hierarchy-service/outputs/high-level-design.md` (lines 757-813) — returns individual events: `createForum(): ForumCreatedEvent`, etc.
  - Users: `.../2026-04-19-users-service/outputs/high-level-design.md` (lines 203-250) — returns `void` or entities, dispatches events internally
  - Messaging: `.../2026-04-19-messaging-service/outputs/hld.md` (lines 309-335) — returns `MessageSentResult {events[]}`
  - Notifications: `.../2026-04-19-notifications-service/outputs/high-level-design.md` (line 201) — `createNotification(): void`

- **What it says**: Threads/Hierarchy return individual typed event objects. Users dispatches events internally (returns void). Messaging uses a custom `MessageSentResult` wrapper with `events[]`. Notifications returns void.
- **What it should say**: Per DOMAIN_EVENTS.md standard: "All service mutations MUST return `DomainEventCollection`" — not individual events, not void, not custom wrappers. Controllers dispatch, services return.
- **Action needed**: 
  - Threads: Change return types from `TopicCreatedEvent` to `DomainEventCollection`
  - Hierarchy: Change return types from `ForumCreatedEvent` to `DomainEventCollection`  
  - Users: Change `void`-returning mutation methods to return `DomainEventCollection`; stop dispatching internally
  - Messaging: Replace `MessageSentResult {events[]}` with `DomainEventCollection`
  - Notifications: Change `createNotification(): void` to return `DomainEventCollection`

---

## Finding 9: Storage HLD — No `TagAwareCacheInterface` Usage (D1 Violation)

- **Severity**: Medium
- **File(s)**: `.maister/tasks/research/2026-04-19-storage-service/outputs/high-level-design.md`
- **What it says**: No reference to `TagAwareCacheInterface`, `cache.storage` pool, or any cache service integration. Only `Cache-Control` HTTP headers mentioned.
- **What it should say**: Per D1: "All services MUST use the new `phpbb\cache` service via `TagAwareCacheInterface`. No per-service custom caching. No exceptions." The decisions plan explicitly lists: "Storage HLD: Add cache pool `cache.storage` for quota lookups."
- **Action needed**: Add `TagAwareCacheInterface` dependency to `StorageService`. Create `cache.storage` pool for caching quota lookups and file metadata. Document cache invalidation strategy for quota changes.

---

## Finding 10: Messaging HLD — No `TagAwareCacheInterface` Usage (D1 Violation)

- **Severity**: Medium
- **File(s)**: `.maister/tasks/research/2026-04-19-messaging-service/outputs/hld.md`
- **What it says**: No reference to `TagAwareCacheInterface` or cache pool. Counter pattern mentions "tiered hot+cold" (line 22: "Counters: Tiered hot+cold") but does not reference the standard `TagAwareCacheInterface` pool.
- **What it should say**: Per D1: Add cache pool `cache.messaging` for conversation metadata caching. Counter hot layer should use the centralized `TagAwareCacheInterface`.
- **Action needed**: Add `TagAwareCacheInterface` dependency. Create `cache.messaging` pool. Reference COUNTER_PATTERN.md for cache key convention (`counter.messaging.{id}.{field}`).

---

## Finding 11: Services-Architecture.md — Stale Information (DB Opaque Tokens, User Service ⚠️)

- **Severity**: Medium
- **File(s)**: `.maister/docs/project/services-architecture.md` (lines 31, 55, 88-98)
- **What it says**: 
  - Line 31: REST API "Key Decision" = "DB opaque tokens"
  - Line 55: "User Service ⚠️" with warning icon (implies not researched)
  - Lines 88-98: Lists "Critical Items" including "User Service not researched", "Extension model contradiction", "JWT vs DB token"
- **What it should say**: 
  - REST API key decision should be "JWT tokens" (per unified auth service research)
  - User Service HAS been researched at `2026-04-19-users-service/` — remove ⚠️
  - Critical items 1-3 are ALL RESOLVED — should be marked ✅ or removed
- **Action needed**: Update services-architecture.md to reflect current resolved state: JWT tokens, User Service researched, extension model unified as macrokernel.

---

## Finding 12: Tech-Stack.md — References Legacy Stack Without Modern Targets

- **Severity**: Medium
- **File(s)**: `.maister/docs/project/tech-stack.md`
- **What it says**: PHPUnit version `^7.0`, Symfony `~3.4`, PHP `^7.2 || ^8.0`, "Custom Multi-Driver DBAL", no mention of PDO replacement, `check_form_key()` described as current security practice
- **What it should say**: The new architecture uses PHPUnit `^10.0`, targets Symfony 6.x/7.x, PHP 8.2+/8.3, PDO (not legacy DBAL), JWT (not form keys). The tech-stack doc describes the LEGACY state, not the TARGET state.
- **Action needed**: Either: (a) rename to `tech-stack-legacy.md` and create a `tech-stack-target.md` with new decisions, or (b) add a clear "Target Architecture" section documenting the new stack per standards.

---

## Finding 13: Storage HLD — Per-Service Exception (`FileNotFoundException`) Not Aligned with D4

- **Severity**: Low
- **File(s)**: `.maister/tasks/research/2026-04-19-storage-service/outputs/high-level-design.md` (lines 188-189, 423-449, 492, 604-666)
- **What it says**: Defines `phpbb\storage\exception\FileNotFoundException` — throws it directly with inline error messages
- **What it should say**: Per D4: `FileNotFoundException` should extend `phpbb\common\Exception\NotFoundException` (HTTP 404 mapping) from the shared package.
- **Action needed**: Update exception to extend `phpbb\common\Exception\NotFoundException`.

---

## Finding 14: Hierarchy HLD — Per-Service Exception Not Aligned with D4

- **Severity**: Low
- **File(s)**: `.maister/tasks/research/2026-04-18-hierarchy-service/outputs/high-level-design.md` (lines 709-710)
- **What it says**: Defines `ForumNotFoundException.php`, `InvalidForumTypeException.php`, `TreeLockException.php`, `InvalidMoveException.php` — no mention of extending `phpbb\common\Exception\*`
- **What it should say**: Per D4: `ForumNotFoundException` → extend `phpbb\common\Exception\NotFoundException`. `TreeLockException` → extend `phpbb\common\Exception\ConflictException`. `InvalidMoveException` / `InvalidForumTypeException` → extend `phpbb\common\Exception\ValidationException`.
- **Action needed**: Update exception hierarchy to extend shared base classes.

---

## Summary Table

| Research Task | Alignment Score | Critical Issues |
|---|---|---|
| Composer Autoload (2026-04-15) | ✅ 100% | None — infrastructure, no service patterns |
| Root Path Elimination (2026-04-15) | ✅ 100% | None — infrastructure |
| REST API (2026-04-16) | ⚠️ 70% | DB opaque tokens superseded by JWT (not updated in services-architecture) |
| Auth Service (2026-04-18) | ⚠️ 65% | Exceptions extend SPL directly (not `phpbb\common\Exception\*`); superseded by unified auth |
| Hierarchy Service (2026-04-18) | ⚠️ 55% | Missing ForumStatsSubscriber (D8); individual event returns (not DomainEventCollection); per-service exceptions |
| **Threads Service (2026-04-18)** | ❌ 35% | **Raw text contradicts D7; sync hierarchy calls contradicts D8**; individual event returns; per-service exceptions |
| Auth Unified (2026-04-19) | ✅ 95% | Minor: ensure exceptions extend common package |
| Cache Service (2026-04-19) | ✅ 100% | Reference standard, fully aligned |
| Database Service (2026-04-19) | ✅ 95% | Clean design, no conflicts |
| Messaging Service (2026-04-19) | ⚠️ 50% | No TagAwareCacheInterface; custom result wrappers (not DomainEventCollection); per-service exceptions |
| Notifications Service (2026-04-19) | ⚠️ 45% | Tagged DI (contradicts macrokernel); void returns (not DomainEventCollection); dispatches internally |
| Storage Service (2026-04-19) | ⚠️ 60% | No TagAwareCacheInterface; per-service exceptions; no DomainEventCollection returns |
| Users Service (2026-04-19) | ⚠️ 55% | Void returns with internal dispatch (not DomainEventCollection); per-service exceptions; no TagAwareCacheInterface |
| Plugin System (2026-04-19) | ✅ 90% | Aligned; references macrokernel pattern correctly |
| Search Service (2026-04-19) | ✅ 90% | Uses TagAwareCacheInterface; clean ISP design |

### Architecture Docs

| Document | Status | Issues |
|---|---|---|
| `services-architecture.md` | ⚠️ Stale | Still says "DB opaque tokens", "User Service ⚠️ not researched", lists resolved items as critical |
| `tech-stack.md` | ⚠️ Stale | Describes legacy stack only, no mention of target architecture (PHP 8.3, PDO, JWT, PHPUnit 10) |
| `architecture.md` | — | Not checked (`.gitkeep` stub) |
| `roadmap.md` | — | Not checked for this audit |
| `vision.md` | — | Not checked for this audit |

---

## Overall Assessment

### ❌ NOT READY — Documentation requires alignment pass before implementation

**Summary**: Cross-cutting decisions D1-D8 were made on 2026-04-20 but have NOT been propagated back to the individual HLDs. The decisions plan explicitly lists "Changes Required" tables for each decision — none of these changes have been applied. The HLDs still contain the old patterns.

**Impact**: If developers implement from the current HLDs without reading the cross-cutting decisions plan, they will build:
- Threads with raw text storage (wrong — should be s9e XML)
- Threads with synchronous Hierarchy coupling (wrong — should be event-driven)
- Services with per-service exception hierarchies (wrong — should extend common)
- Notifications with tagged DI (wrong — should use event registration)
- Services returning void or individual events (wrong — should return DomainEventCollection)
- Storage and Messaging without cache integration (wrong — must use TagAwareCacheInterface)

### Priority Action Plan

| Priority | Action | Affected Files | Effort |
|---|---|---|---|
| **1 — Critical** | Amend Threads HLD: s9e XML default + encoding_engine (D7) | threads/high-level-design.md, decision-log.md | Medium |
| **2 — Critical** | Amend Threads HLD: Remove hierarchy coupling, emit events only (D8) | threads/high-level-design.md, research-report.md | Medium |
| **3 — High** | Add ForumStatsSubscriber to Hierarchy HLD (D8) | hierarchy/high-level-design.md | Small |
| **4 — High** | Replace tagged DI in Notifications HLD (macrokernel) | notifications/high-level-design.md | Medium |
| **5 — High** | Update all exception hierarchies to extend `phpbb\common\Exception\*` (D4) | auth, threads, hierarchy, messaging, storage, users HLDs | Small per file |
| **6 — Medium** | Update return types to `DomainEventCollection` across all 6 service HLDs (D6) | All service HLDs | Medium |
| **7 — Medium** | Add `TagAwareCacheInterface` to Storage + Messaging HLDs (D1) | storage, messaging HLDs | Small |
| **8 — Medium** | Update services-architecture.md to reflect resolved state | services-architecture.md | Small |
| **9 — Low** | Update tech-stack.md with target architecture | tech-stack.md | Small |

---

*Reality assessment: The cross-cutting decisions are sound and well-reasoned. The gap is purely documentation propagation — decisions were centralized but not distributed to the individual HLDs. This is a documentation debt, not an architectural problem.*
