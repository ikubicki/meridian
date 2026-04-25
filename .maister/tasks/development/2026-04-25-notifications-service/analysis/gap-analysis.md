# Gap Analysis: M8 Notifications Service (pending decisions) (`phpbb\notifications`)

## Summary
- **Risk Level**: Medium
- **Estimated Effort**: Medium
- **Detected Characteristics**: creates_new_entities, modifies_existing_code, involves_data_operations

## Task Characteristics
- Has reproducible defect: no
- Modifies existing code: yes (services.yaml)
- Creates new entities: yes (Notification, NotificationDTO, NotificationType, TypeRegistry, MethodManager, NotificationRepository, NotificationService, NotificationsController)
- Involves data operations: yes (READ `phpbb_notifications`, `phpbb_notification_types`; WRITE `notification_read` flag)
- UI heavy: no (React component deferred to M10)

---

## Gaps Identified

### Missing Features (complete list — zero notification code exists in `src/phpbb/notifications/`)

| File / Component | Notes |
|---|---|
| `notifications/Entity/Notification.php` | Domain entity mapped from `phpbb_notifications` |
| `notifications/Entity/NotificationType.php` | Domain entity mapped from `phpbb_notification_types` |
| `notifications/DTO/NotificationDTO.php` | API response shape |
| `notifications/Contract/NotificationRepositoryInterface.php` | Read / mark-read contract |
| `notifications/Repository/DbalNotificationRepository.php` | DBAL impl — reads 3 existing tables |
| `notifications/Contract/NotificationServiceInterface.php` | Service contract |
| `notifications/Service/NotificationService.php` | Count, list, mark-single-read, mark-all-read; cache integration |
| `notifications/TypeRegistry.php` | Symfony-event-based extensible type map |
| `notifications/MethodManager.php` | Delivery method registry (board + email stubs) |
| `api/Controller/NotificationsController.php` | 4 endpoints with Last-Modified / 304 headers |
| `config/services.yaml` additions | New DI wiring block (M8 section) |
| `tests/phpbb/notifications/` | PHPUnit unit + integration tests |
| `tests/e2e/api.spec.ts` additions | M8 Playwright E2E block |

### Incomplete Features
- None — this is a pure greenfield addition.

### Behavioral Changes Needed
- `services.yaml`: additive only — new M8 block mirroring M7 pattern.
- `tests/e2e/api.spec.ts`: additive only — new `test.describe('Notifications API', ...)` block.

---

## User Journey Impact Assessment

| Dimension | Current | After | Assessment |
|---|---|---|---|
| Reachability | N/A | `GET /api/v1/notifications` | ✅ JWT-gated, same as messaging |
| Discoverability | N/A | 8/10 — standard REST idiom, polling every 30s | ✅ |
| Flow Integration | N/A | New feature, no existing flow broken | ✅ |
| Multi-Persona | N/A | All authenticated users; unauthenticated → 401 | ✅ |

---

## Data Lifecycle Analysis

### Entity: Notification (`phpbb_notifications`)

| Operation | Backend | UI Component | User Access | Status |
|---|---|---|---|---|
| CREATE | ❌ Not in M8 scope (written by future event listeners) | N/A | N/A | Out of scope |
| READ (list) | `GET /api/v1/notifications` | Future frontend component | JWT auth → endpoint | ✅ in scope |
| READ (count) | `GET /api/v1/notifications/count` | Future `<NotificationBell>` unread badge | JWT auth → endpoint | ✅ in scope |
| UPDATE (mark read) | `POST /api/v1/notifications/{id}/read` | Future dismiss button | JWT auth → endpoint | ✅ in scope |
| UPDATE (mark all read) | `POST /api/v1/notifications/read` | Future "clear all" | JWT auth → endpoint | ✅ in scope |
| DELETE | Not in design | N/A | N/A | Out of scope |

**Completeness for M8 scope**: 100% — all 4 in-scope operations covered across all 3 layers.

### Entity: NotificationType (`phpbb_notification_types`)

| Operation | Backend | UI | Access | Status |
|---|---|---|---|---|
| READ (by name) | TypeRegistry lookup | N/A | Internal only | ✅ in scope |
| WRITE | TypeRegistry event (`CollectNotificationTypesEvent`) | N/A | Extension point | ✅ via ADR-007 |

**Completeness**: 100% for M8 scope.

### Critical Schema Detail — `notification_data` TEXT column

```sql
`notification_data` text NOT NULL  -- phpbb_notifications
```

phpBB3 stored PHP-serialized arrays here (`a:3:{s:...}`). The table is **empty** in the current DB dump (fresh install). The new implementation (ADR-002: write-time aggregation) needs to store pre-rendered display data. Two options exist:

- **Option A**: Store JSON string in existing TEXT column (zero migration cost, slightly wasteful)
- **Option B**: Migrate column to MySQL `JSON` type (type safety, query-ability, trivial ALTER TABLE since table is empty)

→ **Decision required** — see `decisions_needed.critical`.

### Entity: UserNotificationPreference (`phpbb_user_notifications`)

```sql
-- subscription preferences: which item_type+item_id combinations a user wants notifications for
(`item_type`, `item_id`, `user_id`, `method`, `notify`)
```

This table governs whether a notification is *delivered* (creation-side logic). The 4 REST endpoints in M8 scope only read/update *existing delivered notifications* from `phpbb_notifications`. The preference table is **not** required by any of the 4 endpoints — it belongs to the notification creation pipeline (future event listeners), not the read/polling service.

→ **Decision required** — see `decisions_needed.important` (scope confirmation).

---

## New Capability Analysis

### Integration Points

| Point | Mechanism | Evidence |
|---|---|---|
| DI container | `services.yaml` M8 block | M7 block at line ~200 of services.yaml |
| Route registration | `#[Route]` attributes on `NotificationsController` | routes.yaml auto-discovers `../api/Controller/` |
| Cache pool | `CachePoolFactory::getPool('notifications')` | `CachePoolFactory::getPool(string)` confirmed |
| Domain events (cache invalidation) | `EventDispatcherInterface::dispatch()` → `NotificationsReadEvent` | Pattern confirmed in StorageService, QuotaService |
| TypeRegistry extensibility | `Symfony\Component\EventDispatcher\EventDispatcherInterface` | Confirmed pattern throughout codebase |

### Patterns to Follow (M7 as exact template)

| M7 file | M8 equivalent |
|---|---|
| `messaging/Entity/Message.php` | `notifications/Entity/Notification.php` |
| `messaging/Repository/DbalMessageRepository.php` | `notifications/Repository/DbalNotificationRepository.php` |
| `messaging/Contract/MessageRepositoryInterface.php` | `notifications/Contract/NotificationRepositoryInterface.php` |
| `messaging/MessagingService.php` | `notifications/Service/NotificationService.php` (simpler — single entity) |
| `api/Controller/MessagesController.php` | `api/Controller/NotificationsController.php` |
| `config/services.yaml` M7 block | M8 block |
| `tests/phpbb/messaging/` | `tests/phpbb/notifications/` |

### Architectural Impact

- **New namespace**: `phpbb\notifications\` in `src/phpbb/notifications/`
- **New API controller**: `src/phpbb/api/Controller/NotificationsController.php`
- **Two novel components** with no direct template: `TypeRegistry` + `MethodManager`
- **New HTTP pattern**: `Last-Modified` / `If-Modified-Since` / `304 Not Modified` — no existing controller uses this; Symfony has native support via `Response::setLastModified()` + `$request->isNotModified($response)` — low implementation risk
- **No DB migration needed** — all 3 tables already exist

---

## Issues Requiring Decisions

### Critical (Must Decide Before Proceeding)

1. **`notification_data` column format**
   - **Issue**: TEXT column in `phpbb_notifications` was used by phpBB3 for PHP-serialized data (`a:3:{...}`). The ADR-002 write-time aggregation design stores pre-rendered display data in this column. The table is empty in current DB.
   - **Options**:
     - **A — JSON in TEXT as-is**: Store JSON-encoded string in TEXT column. Zero migration cost, backward-compat. Slight waste (no DB-level type validation). Recommended if data querying by JSON key is never needed.
     - **B — Migrate TEXT → JSON column**: `ALTER TABLE phpbb_notifications MODIFY notification_data JSON NOT NULL DEFAULT (JSON_OBJECT())`. Table is empty so trivial. Enables `JSON_EXTRACT()` queries, enforces valid JSON at DB level.
   - **Recommendation**: Option B — table is empty, migration is free, JSON type is strictly better
   - **Rationale**: With write-time aggregation, `notification_data` becomes a structured store queried at read time. JSON column enables typed access and future querying without performance cost.

2. **TypeRegistry first-milestone built-in types**
   - **Issue**: The extensible TypeRegistry must ship with at least 1-2 concrete types to be testable. `phpbb_user_notifications` data has three: `notification.type.post`, `notification.type.topic`, `notification.type.forum`.
   - **Options**:
     - **A — `notification.type.post` only** (post reply notification): Simplest, proven demand (highest row count in phpbb_user_notifications seed data)
     - **B — `notification.type.post` + `notification.type.topic`** (post reply + topic-watch): Two types prove the registry is extensible without over-engineering
     - **C — No built-in types** (pure architecture, empty registry by default): Technically valid but untestable without a fixture type
   - **Recommendation**: Option B — two types prove extensibility and cover the most common user workflows
   - **Rationale**: Unit tests require at least one type; E2E tests require at least one type that can be inserted as a fixture; two is enough to validate the registry dispatch pattern.

### Important (Should Decide)

1. **`phpbb_user_notifications` preference table — in scope for M8?**
   - **Issue**: This table stores per-user notification subscription preferences (item_type + method). It's written by phpBB3 event handlers on subscription actions (forum subscription, topic subscription). The 4 REST endpoints don't need it to function. Should M8 expose a preference-management API (`GET/PUT /api/v1/notifications/preferences`) or defer entirely to M9/later?
   - **Options**:
     - **A — Read-only**: M8 reads phpbb_user_notifications internally to decide board/email delivery; no REST API for it
     - **B — Out of scope**: M8 ignores phpbb_user_notifications entirely (notification creation is future work)
     - **C — Expand scope**: Add preference endpoints (`GET/PATCH /api/v1/notifications/preferences`)
   - **Default**: Option B — phpbb_user_notifications is creation-side logic; M8 is the read/polling service
   - **Rationale**: The 4 defined endpoints only require phpbb_notifications + phpbb_notification_types. Preference management belongs with the notification creation pipeline.

2. **Email delivery method — stub depth**
   - **Issue**: `phpbb_user_notifications` includes `notification.method.email`. MethodManager must register this method. How much to implement?
   - **Options**:
     - **A — No-op stub** (registered but does nothing): Satisfies TypeRegistry/MethodManager architecture without SMTP scope creep
     - **B — Throw `\LogicException('not implemented')`**: Explicit, forces tests to mock it
     - **C — Skip method registration entirely**: No email method in M8
   - **Default**: Option A — no-op stub registered as `notification.method.email`
   - **Rationale**: MethodManager must be testable with multiple methods. Board method (in-app) fully implemented; email as no-op preserves architecture without adding SMTP scope.

3. **React `<NotificationBell>` — confirmed out of M8 scope?**
   - **Issue**: Task description says "low priority — backend first." MILESTONES.md M10.6 explicitly lists notifications component under React SPA.
   - **Options**:
     - **A — Out of M8 scope**: Backend only; frontend in M10
     - **B — Include minimal stub component**: Prove polling works end-to-end in M8
   - **Default**: Option A — confirmed by task description and MILESTONES structure
   - **Rationale**: M8 = backend service. Frontend in M10. E2E tests via Playwright API tests are sufficient to validate polling behaviour.

---

## Recommendations

1. **Scope M8 tightly**: 4 REST endpoints + TypeRegistry (2 built-in types) + MethodManager (board=full, email=no-op) + cache (per-user tag, 30s TTL) + HTTP polling headers. No frontend, no preference API, no email delivery.
2. **Resolve `notification_data` format before implementation starts** — this decision affects the entity hydration and repository write path.
3. **TypeRegistry is the highest architectural risk** — it's the only genuinely new pattern in this codebase. Budget extra test coverage here.
4. **No DB migration needed** — all 3 tables exist and are empty; if B is chosen for `notification_data`, that's a single `ALTER TABLE` as part of bootstrap/migration class.
5. **Reuse M7 test structure exactly** — `tests/phpbb/notifications/{Entity,DTO,Repository,Service}/` + `tests/e2e/api.spec.ts` M8 block.

---

## Risk Assessment
- **Complexity Risk**: Medium — TypeRegistry + MethodManager via Symfony events is the only untested architectural pattern; Last-Modified/304 is new but Symfony-native
- **Integration Risk**: Low — routes auto-discovered, DI additive, tables exist, cache pool proven
- **Regression Risk**: Low — additive only; no existing code paths modified
- **Data Risk**: Medium — `notification_data` column format decision must happen before first write test
