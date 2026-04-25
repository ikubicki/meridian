# Spec Audit: M8 Notifications Service

**Date**: 2026-04-25  
**Spec**: `.maister/tasks/development/2026-04-25-notifications-service/implementation/spec.md`  
**Auditor mode**: spec-auditor  

---

## Overall Verdict

⚠️ **PASS WITH CONCERNS**

The spec is well-structured, covers all four endpoints, and follows the M7 template faithfully. Two high-severity defects require fixes before implementation begins; six additional issues of medium/low severity need resolution or acknowledgement.

| Severity | Count |
|----------|-------|
| Critical | 0 |
| High     | 2 |
| Medium   | 4 |
| Low      | 3 |

---

## Critical Issues

None.

---

## High Severity Issues

### H1 — E2E 304 test will always return 200 in empty-DB state

**Spec reference**: Section 21, test case "GET /notifications/count — 304 when If-Modified-Since is in the future"

**Evidence** (controller flow from Section 15, Endpoint 1):
1. Empty DB → `getLastModified()` returns `null`
2. `if ($lastModifiedTs !== null)` guard means `$response->setLastModified()` is **never called**
3. Symfony's `Request::isNotModified(Response $response)` only returns `true` when response has `Last-Modified` set **and** `If-Modified-Since >= Last-Modified`. When response has no `Last-Modified`, the condition short-circuits to `false`.
4. Result: `isNotModified()` → `false` → controller returns `200`, not `304`.

```
concrete flow (empty DB, future If-Modified-Since):
  getLastModified() → null
  setLastModified() → [skipped]
  isNotModified(response)  → false   ← Symfony source confirmed
  → 200 returned, test asserts 304 → TEST FAILS
```

**Category**: Incorrect (test scenario unfeasible as written)

**Recommendation**: The 304 test MUST either:
- Pre-seed one notification row in a `beforeAll` / `beforeEach` fixture so `Last-Modified` is set, then send `If-Modified-Since` ≥ that timestamp, OR
- Remove the 304 E2E test and cover 304 logic in a `NotificationsControllerTest` unit test using a mocked service.

---

### H2 — Group C repository test count (11) violates the stated 2–8 limit

**Spec reference**: Section 20 header — "2–4 tests per group (2–8 limit per step group)"

**Evidence**: Group C (`DbalNotificationRepositoryTest`) lists **11 test cases**:
`findByIdReturnsNullForMissing`, `findByIdReturnsScopedToUser`, `findByIdReturnsHydratedEntity`, `countUnreadReturnsZeroByDefault`, `countUnreadCountsOnlyUnread`, `getLastModifiedReturnsNullWhenEmpty`, `getLastModifiedReturnsMaxTime`, `listByUserReturnsPaginatedDTOs`, `markReadReturnsTrueForOwn`, `markReadReturnsFalseForOther`, `markAllReadUpdatesAllUnread`.

**Category**: Incorrect (internal inconsistency — spec violates a constraint it defines)

**Recommendation**: Either raise the documented limit to cover repositories (e.g. "Repository integration tests may contain up to 12 cases") or split Group C into two classes: `DbalNotificationRepositoryReadTest` and `DbalNotificationRepositoryWriteTest` (6+5 = 11, each within limit). The 11 tests themselves are all necessary — the limit text needs updating.

---

## Medium Severity Issues

### M1 — Mark-read endpoints return non-standard response body

**Spec reference**: Section 15, Endpoints 3 and 4 — both return `new JsonResponse(['status' => 'read'])` at HTTP 200.

**Evidence**: REST_API.md (authoritative): *"Always use `data` as the top-level key."* The M7 template (`MessagesController.php` lines 183 and 209) uses `JsonResponse(status: 204)` (no body) for similar mutations.

```
Spec returns:   { "status": "read" }          ← violates data-envelope rule
Standard says:  { "data": { ... } }           ← REST_API.md
M7 template:    HTTP 204, no body             ← MessagesController.php L183, L209
```

**Category**: Incorrect (violates REST_API.md and deviates from M7 template)

**Recommendation**: Align with M7 template: return `204 No Content` (`new JsonResponse(status: 204)` for both mark-read endpoints, and update the status-code tables in the spec accordingly. If a body is desired for client debugging, wrap as `['data' => ['status' => 'read']]`.

---

### M2 — Self-contradictory `NotificationService` property description

**Spec reference**: Section 13, cache property declaration.

**Evidence** (verbatim from spec):
```
"Add a private non-readonly property:
    private readonly TagAwareCacheInterface $cache;"
```

"Non-readonly" and `readonly` are mutually exclusive. In PHP, a `readonly` property cannot be re-assigned after construction, which perfectly fits the use case (assigned once from `$cacheFactory->getPool()`). The code snippet is correct; the prose text is wrong.

**Category**: Ambiguous (contradictory instructions; implementor may declare it non-readonly and discover bugs in tests)

**Recommendation**: Remove "non-readonly" from the prose. Correct text: *"Add a private readonly property"*. Also explicitly state the class declaration as `final class NotificationService` (not `final readonly class`), consistent with the M7 `MessagingService` template (`final class MessagingService`).

---

### M3 — `NotificationService` class declaration not specified

**Spec reference**: Section 13 — shows constructor but no class-level declaration.

**Evidence**: The M7 analogue is `final class MessagingService implements MessagingServiceInterface` (verified in `src/phpbb/messaging/MessagingService.php` line 45). The spec omits the class declaration entirely. An implementor guided by "STANDARDS.md `final readonly class`" in the overview could incorrectly declare `final readonly class NotificationService`, which would conflict with the constructor-body assignment `$this->cache = ...` only if the property weren't `readonly`. With `private readonly`, a `readonly class` declaration IS valid PHP — but it deviates from the M7 pattern and may cause confusion.

**Category**: Incomplete

**Recommendation**: Add explicit class declaration to Section 13: `final class NotificationService implements NotificationServiceInterface`.

---

### M4 — 304 / cache coherence: mark-read does not update `Last-Modified`

**Spec reference**: Sections 13 (`getLastModified()` not cached, derived from `MAX(notification_time)`) and 15 (endpoint 1 uses `Last-Modified` for 304).

**Evidence**:
- `notification_time` is set at creation time; `markRead()` sets `notification_read = 1` but does NOT change `notification_time`.
- After mark-read: tag-based cache is invalidated ✓ (next poll gets fresh count).
- After mark-read: `MAX(notification_time)` is unchanged → same `Last-Modified` → client sending `If-Modified-Since: <prior Last-Modified>` gets `304` → client shows stale (pre-mark-read) unread count.

This creates a window where a client that polled successfully, marked a notification as read, then immediately polled again with `If-Modified-Since` could receive a stale cached response despite tag invalidation.

**Category**: Incomplete (design trade-off not acknowledged in spec)

**Recommendation**: Add a callout box in Section 15, Endpoint 1:
> **Known trade-off**: `Last-Modified` is derived from `MAX(notification_time)` and does not change on mark-read mutations. Clients using `If-Modified-Since` may receive a 304 with stale unread counts for up to 30 seconds after a mark-read. The tag-cache invalidation guarantees freshness only for clients that omit `If-Modified-Since`. This is an acceptable trade-off aligned with ADR-001 (polling, not push).

---

## Low Severity Issues

### L1 — Missing `max(1, ...)` floor on `lastPage` in paginated response

**Spec reference**: Section 15, Endpoint 2 — "Map items and Return `JsonResponse` with standard paginated shape".

**Evidence**: Every other controller in the codebase floors `totalPages()` at 1:
```php
// ConversationsController.php L58, MessagesController.php L57, TopicsController.php L63
'lastPage' => max(1, $result->totalPages()),
```
`PaginatedResult::totalPages()` returns `0` for empty result sets (`ceil(0/25) = 0`). The spec does not include `max(1, ...)`, meaning an empty notifications list would return `"lastPage": 0`.

**Category**: Incomplete

**Recommendation**: Update the Endpoint 2 response shape snippet to show `'lastPage' => max(1, $result->totalPages())`.

---

### L2 — `@throws RepositoryException` missing from interface PHPDoc

**Spec reference**: Section 7 — `NotificationRepositoryInterface` method comments.

**Evidence**: All M7 repository interfaces document `@throws RepositoryException` on every method (verified in `src/phpbb/messaging/Contract/ConversationRepositoryInterface.php`, `MessageRepositoryInterface.php`, `ParticipantRepositoryInterface.php`). The notifications interface spec includes PHPDoc blocks but omits the `@throws` annotation.

**Category**: Incomplete (minor template deviation)

**Recommendation**: Add `* @throws \phpbb\db\Exception\RepositoryException` to all six method PHPDoc blocks in the interface spec.

---

### L3 — `fromRow()` empty-string fallback is underspecified

**Spec reference**: Section 4, `fromRow()` factory — "Decodes `notification_data` using `json_decode($row['notification_data'], true, 512, JSON_THROW_ON_ERROR)`, defaulting to `[]` on empty string."

**Evidence**: `json_decode('', true, 512, JSON_THROW_ON_ERROR)` throws `\JsonException` — it does NOT return null or an empty array. The spec doesn't specify how to check for an empty string before calling `json_decode`. An implementor might write:

```php
// ❌ Wrong — throws on empty string
$data = json_decode($row['notification_data'], true, 512, JSON_THROW_ON_ERROR);

// ✅ Required by spec intent
$raw = $row['notification_data'] ?? '';
$data = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
```

The Group A test `fromRowDefaultsDataToEmptyArray` tests `'{}'` and `'[]'` (valid JSON) — it does NOT test an actual empty string, leaving the stated fallback behaviour untested.

**Category**: Ambiguous

**Recommendation**: Add explicit pre-check code to the `fromRow()` factory snippet. Update the test to add a `fromRowDefaultsDataToEmptyString` case that exercises the empty-string path specifically.

---

## Extra (Not in Requirements, Implementation Adds Value)

### X1 — `NotificationType` entity documented in Section 5

Section 5 documents a `NotificationType` entity marked as "Not required for M8 but documented for M8.x reference." This is useful forward-documentation, not a problem. Correctly scoped out.

---

## Clarification Needed

### C1 — Should `min(1, ...)` error be treated as HTTP 422 or silently clamped?

`PaginationContext::fromQuery()` — the spec does not state what happens when `?page=0` or `?perPage=0` is passed for `GET /notifications`. If `fromQuery()` clamps these values silently, the spec is silent on it. If it throws, the controller has no `try/catch` for pagination errors. Confirm: does `PaginationContext::fromQuery()` throw or clamp for invalid page values?

---

## Specification Completeness Summary

| Area | Status |
|------|--------|
| All 4 endpoints specced with status codes | ✅ |
| `Last-Modified` / `304` logic | ✅ (with design trade-off H1, M4) |
| Route ordering / `\d+` requirement | ✅ |
| DB migration — ALTER TABLE + index | ✅ |
| DB migration — seed INSERT IGNORE | ✅ |
| TypeRegistry lazy-init | ✅ |
| MethodManager lazy-init | ✅ |
| DomainEventCollection usage | ✅ |
| Cache invalidation subscriber | ✅ |
| Unit test plan Groups A-F | ✅ (with H2 count issue) |
| E2E test plan | ⚠️ (H1 — 304 test broken) |
| DI services.yaml block | ✅ |
| PSR-4 / namespace | ✅ |
| `final readonly class` for Entities/DTOs | ✅ |
| `RepositoryException` propagation | ✅ |
| Response envelope `data` key | ❌ (M1 — mark-read endpoints) |
| `@throws` in interface PHPDoc | ❌ (L2) |

---

## Required Actions Before Implementation Starts

1. **Fix H1**: Rewrite 304 E2E test to use pre-seeded notification data or move to unit test.  
2. **Fix H2**: Resolve Group C 11-test count against the stated 2-8 limit (split or update limit).  
3. **Fix M1**: Change mark-read and mark-all-read responses to `204 No Content` (matching M7 template) or wrap in `{ "data": {...} }` envelope.  
4. **Fix M2**: Remove contradictory "non-readonly" text; add explicit `final class NotificationService` declaration.  
5. **Acknowledge M4**: Add trade-off callout for 304 / mark-read coherence window.

Items L1, L2, L3 are recommended fixes but do not block implementation.

---

*Audit completed 2026-04-25. Evidence from actual codebase: `MessagesController.php`, `MessagingService.php`, `TagAwareCacheInterface.php`, `DomainEvent.php`, `PaginatedResult.php`, `AuthenticationSubscriber.php`.*
