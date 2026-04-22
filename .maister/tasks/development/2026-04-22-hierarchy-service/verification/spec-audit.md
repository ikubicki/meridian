# Specification Audit: `phpbb\hierarchy` Service

**Audit date**: 2026-04-22
**Auditor**: Spec-Auditor (independent)
**Spec file**: `.maister/tasks/development/2026-04-22-hierarchy-service/implementation/spec.md`
**Compliance status**: ❌ **Non-Compliant** — 4 critical issues, 15 important issues, 8 minor issues

---

## Audit Scope

| File read | Purpose |
|---|---|
| `implementation/spec.md` | Primary specification — read in full (all 20 sections) |
| `analysis/gap-analysis.md` | Requirements gap analysis baseline |
| `analysis/requirements.md` | Functional requirements (FR-01..FR-09) |
| `research/2026-04-18-hierarchy-service/outputs/decision-log.md` | ADR-001..ADR-007 |
| `.maister/docs/standards/backend/STANDARDS.md` | Backend coding standards |
| `.maister/docs/standards/backend/DOMAIN_EVENTS.md` | Authoritative event standard |
| `.maister/docs/standards/testing/STANDARDS.md` | Test standards |
| `src/phpbb/user/Repository/DbalGroupRepository.php` | DBAL pattern reference |
| `src/phpbb/api/Controller/ForumsController.php` | Existing controller (confirms mock) |
| `src/phpbb/config/services.yaml` | DI registration baseline |

---

## 1. Critical Issues

> Blocking — must fix before implementation begins.

---

### C-01 — Forum delete does not cascade to children (data integrity failure)

**Spec reference**: Section 11 `deleteForum()` implementation; Section 10 `removeNode()` algorithm.

**Evidence**:
- Section 10 `removeNode()` Step 3 zeros out the entire subtree:
  ```sql
  UPDATE phpbb_forums SET left_id = 0, right_id = 0
  WHERE left_id >= :leftId AND right_id <= :rightId
  ```
  Spec note: *"marks rows for deletion without deleting them (repository.delete() handles that)"*
- Section 11 `deleteForum()` then calls:
  ```php
  $this->treeService->removeNode($forumId);
  $this->repository->delete($forumId);  // deletes ONE row only
  ```
  `repository->delete()` spec: `DELETE FROM phpbb_forums WHERE forum_id = :forumId`

**Gap**: When `deleteForum(10)` is called on a forum with 3 child forums (IDs 11, 12, 13), `removeNode()` zeroes out their `left_id`/`right_id` but they remain as DB rows. `delete(10)` removes only forum 10. Forums 11, 12, 13 become permanent orphans with `left_id=0, right_id=0, parent_id=1` — invisible to traversal but still consuming `forum_id` values and polluting the table.

**Category**: Missing — orphan handling not specified.

**Severity**: **Critical** — production data corruption on every non-leaf delete. The tree invariant (acceptance criterion #2) would fail after the first deletion of a parent forum.

**Recommendation**: Spec must choose one of: (a) throw `\InvalidArgumentException` if forum has children (`isLeaf() === false`), (b) cascade-delete all subtree rows inside the transaction, (c) reassign children to the deleted forum's parent. Document the chosen strategy explicitly.

---

### C-02 — `moveForum()` has no REST endpoint

**Spec reference**: Section 6 `HierarchyServiceInterface`; Section 11 `moveForum()` implementation; Section 14 REST API routes.

**Evidence**:
- Section 6 defines `moveForum(int $forumId, int $newParentId, int $actorId = 0): DomainEventCollection`
- Section 11 specifies the full implementation of `moveForum()` calling `treeService->moveNode()`
- Section 14 route table:

  | Method | Path | Handler |
  |---|---|---|
  | PATCH | `/forums/{forumId}` | `update()` → calls `updateForum()` |

- Section 11 `updateForum()` calls `$this->repository->update($request)` only — it does NOT call `treeService->moveNode()`
- `UpdateForumRequest` has `?int $parentId = null`, but `updateForum()` never passes this to the tree service

**Gap**: Moving a forum between parents requires `moveForum()`, but no HTTP route invokes it. The PATCH endpoint invokes `updateForum()` which only updates column values, not tree position. A PATCH request with a new `parentId` would update the `parent_id` column via `update()` but leave `left_id`/`right_id` unchanged — producing a corrupt tree.

**Category**: Missing — moveForum is an unreachable dead-letter endpoint.

**Severity**: **Critical** — forum hierarchy reorganization (the core mutation of a hierarchy service) is completely inaccessible via the API.

**Recommendation**: Add a dedicated route, e.g. `PATCH /forums/{forumId}/move` with body `{"newParentId": N}` wired to `moveForum()`. Alternatively, detect `parentId` change in the PATCH handler and route to `moveForum()` automatically, but document this logic explicitly.

---

### C-03 — `listForums(null)` returns all forums, not root forums (internal spec contradiction)

**Spec reference**: Section 6 `HierarchyServiceInterface`; Section 11 `listForums()` implementation.

**Evidence**:
- Section 6 docstring: *"Returns `array<int, ForumDTO>` — direct children of `$parentId` (or **all root forums** if null)."*
- Section 11 implementation: `$parentId === null ? $this->repository->findAll() : $this->repository->findChildren($parentId)`
- `findAll()` spec (section 9): `SELECT * FROM phpbb_forums ORDER BY left_id ASC` — returns **all** forums
- Root forums have `parent_id = 0`. `findAll()` returns categories, subforums, and all nested levels

**Gap**: The interface contract says "root forums" (direct children of the virtual root, i.e. `parent_id = 0`). The implementation calls `findAll()` which returns every forum in the database. A `GET /forums` call would return a flat list of 200 forums instead of 5 top-level categories.

**Category**: Incorrect — interface contract and implementation are inconsistent.

**Severity**: **Critical** — the primary API endpoint (`GET /forums`) would return wrong data for any non-trivial forum tree.

**Recommendation**: Fix implementation to `$parentId === null ? $this->repository->findChildren(0) : $this->repository->findChildren($parentId)`. Alternatively, rewrite the docstring to say "all forums (flat list)" and add a separate `getRootForums()` method.

---

### C-04 — Auth check never returns 401 (contradicts acceptance criteria)

**Spec reference**: Section 14 `create()` handler; Section 19 acceptance criteria #5.

**Evidence**:
- Acceptance criteria #5: *"POST /forums without JWT returns 401; with JWT missing acp flag returns 403"*
- Section 14 implementation:
  ```php
  $token = $request->attributes->get('_api_token');
  $actorId = $token?->userId ?? 0;
  if (!in_array('acp', $token?->flags ?? [], true)) return 403;
  ```
- When `$token === null` (no JWT): `$token?->flags` → `null`, `?? []` → `[]`, `in_array('acp', [])` → `false`, returns **403**.
- The code never evaluates `$token === null` separately.

**Gap**: No-JWT requests receive HTTP 403 instead of 401. HTTP semantics: 401 = not authenticated, 403 = authenticated but not authorized. The wrong status code is returned, and acceptance criteria #5 is definitionally unachievable with the specified code.

**Category**: Incorrect — implementation contradicts stated acceptance criteria.

**Severity**: **Critical** — auth error handling is wrong by definition; any automated API client or security audit would flag this.

**Recommendation**: Add explicit null check before flags check:
```php
if ($token === null) {
    return new JsonResponse(['error' => 'Authentication required'], 401);
}
if (!in_array('acp', $token->flags ?? [], true)) {
    return new JsonResponse(['error' => 'Forbidden'], 403);
}
```
Apply the same fix to `update()` and `delete()` handlers (section 14 doesn't show their full body, so assumed same pattern).

---

## 2. Important Issues

> Should fix — affects correctness, maintainability, or standards compliance.

---

### I-01 — `DomainEventCollection` spec contradicts authoritative `DOMAIN_EVENTS.md` standard

**Spec reference**: Section 3 (DomainEventCollection definition); `.maister/docs/standards/backend/DOMAIN_EVENTS.md`.

**Evidence**:
- Spec section 3 defines DomainEventCollection with methods: `dispatch()`, `getIterator()`, `all()`, `first()`
- `DOMAIN_EVENTS.md` authoritative standard defines: `add()`, `merge()`, `getIterator()`, `count()`, `isEmpty()`, `dispatch()` (implied via `$events->dispatch()` in examples)
- Spec has `first()` — **absent from standard**
- Standard has `add()`, `merge()`, `count()`, `isEmpty()` — **absent from spec**
- Section 14 controller code relies on `$events->first()->forum->id` — requires `first()`

**Gap**: An implementation following the standard's `DomainEventCollection` would lack `first()`, breaking the controller code in section 14. An implementation following the spec would lack `add()`, `merge()`, `count()` — breaking any future consumer following the standard.

**Category**: Incorrect — spec conflicts with the declared authoritative standard.

**Severity**: High — implementations following different source documents will be incompatible.

**Recommendation**: Reconcile the spec's DomainEventCollection with the standard. Either: (a) add `first()`, `all()` to the standard and update `DOMAIN_EVENTS.md`, or (b) remove them from the spec and refactor the controller code (e.g. use `foreach` or `iterator_to_array()` to get the first element). Choose one canonical definition.

---

### I-02 — `requirements.md` FR-04 contradicts spec's `HierarchyServiceInterface` return type

**Spec reference**: `analysis/requirements.md` FR-04; Section 6 `HierarchyServiceInterface`; `decision-log.md` ADR-005.

**Evidence**:
- `requirements.md` FR-04: *"Mutation methods (return domain events): `createForum(CreateForumRequest): ForumCreatedEvent`"*
- `decision-log.md` ADR-005 chosen outcome: *"createForum() returns ForumCreatedEvent not Forum"*
- `spec.md` section 6: `createForum(CreateForumRequest $request): DomainEventCollection`
- `DOMAIN_EVENTS.md`: *"Mutation Methods Return DomainEventCollection"*

**Gap**: Three documents ‒ `requirements.md`, ADR-005, and `spec.md` ‒ are mutually inconsistent on the return type of mutation methods. The spec aligns with `DOMAIN_EVENTS.md` (correct). The ADR and requirements are stale.

**Category**: Ambiguous — implementers reading ADR-005 or requirements.md could implement the wrong signature.

**Severity**: High — ADR-005 text is authoritative architecture documentation; if followed literally, implementations would be wrong.

**Recommendation**: Update `requirements.md` FR-04 and `decision-log.md` ADR-005 "Consequences" section to reflect `DomainEventCollection` return type. Add a note to ADR-005 that it was superseded by `DOMAIN_EVENTS.md` standard.

---

### I-03 — `phpbb_forums_watch` test schema missing UNIQUE constraint

**Spec reference**: Section 16 `SubscriptionServiceTest` DDL.

**Evidence**:
- Section 16 DDL:
  ```sql
  CREATE TABLE phpbb_forums_watch (
      forum_id      INTEGER NOT NULL,
      user_id       INTEGER NOT NULL,
      notify_status INTEGER NOT NULL DEFAULT 0
  )
  ```
  No `PRIMARY KEY` or `UNIQUE (forum_id, user_id)` constraint.
- Section 13 `subscribe()` spec: *"idempotent upsert, notify_status=1"*
- Test `testSubscribe_idempotent_noError` would never detect duplicate row creation since there's no uniqueness enforcement.
- The platform-switched DELETE+INSERT upsert only ensures atomicity, not uniqueness without a constraint.

**Gap**: The subscribe idempotency test is meaningless without a unique constraint. Two calls to `subscribe(1, 1)` would insert two rows, `isSubscribed()` would return true (row found), hiding the bug.

**Category**: Incorrect — test schema is missing a constraint needed to validate the stated behavior.

**Severity**: High — test would pass vacuously while hiding a real data integrity bug in production.

**Recommendation**: Add `PRIMARY KEY (forum_id, user_id)` or `UNIQUE (forum_id, user_id)` to the test DDL.

---

### I-04 — `insertRaw()` stores `forum_parents` as `''`, not JSON `'[]'`

**Spec reference**: Section 9 `insertRaw()` SQL; Section 19 acceptance criteria #12.

**Evidence**:
- Section 9 INSERT SQL values list: `forum_parents` hardcoded as `''` (empty string literal in SQL)
- Acceptance criteria #12: *"forum_parents written as JSON on every insertRaw() and update()"*
- Section 9 `decodeParents('')`: *"if ($raw === '') return []"* — special-cases empty string to return `[]`

**Gap**: The spec requires JSON on write (criterion #12) but the INSERT writes `''` which is not valid JSON. The decode handles it gracefully, but the invariant is violated. Any external tool reading the column after insert would find an empty string, not `[]`.

**Category**: Incorrect — stated acceptance criterion is not met by the specified SQL.

**Severity**: High — contradiction between acceptance criteria and implementation spec.

**Recommendation**: Change the hardcoded `''` in the VALUES to `'[]'` (or pass it as a param with value `json_encode([])`).

---

### I-05 — `moveNode()` Step F underspecified (`<adjusted>` / `...` placeholders)

**Spec reference**: Section 10 Algorithm `moveNode()` Step F.

**Evidence**:
- Spec text: *"Step F — Update parent_id of the moved root: `updateTreePosition(forumId: $forumId, leftId: $insertPos + <adjusted>, rightId: ..., parentId: $newParentId)` (After step E, re-query the node to get its new left/right, then call updateTreePosition for parent_id only.)"*

**Gap**: `<adjusted>` and `...` are unexplained placeholders. The actual computation of the moved node's final `leftId`/`rightId` after Step E is not given. An implementer following the spec literally cannot determine these values without reverse-engineering the algorithm from Step E logic.

**Category**: Incomplete — algorithm is underspecified at the critical final step.

**Severity**: High — implementer must guess or derive the formula, risking subtle tree corruption bugs.

**Recommendation**: Replace placeholders with the explicit formula. After Step E, the moved node's new `left_id = (-1 * original_left_id) + offset` where `offset = $insertPos - abs($node['left_id'])`. The full computation is:
```
$newLeftId  = $insertPos;
$newRightId = $insertPos + $size - 1;
updateTreePosition($forumId, $newLeftId, $newRightId, $newParentId);
```
Or simply: re-query the node after Step E (`SELECT left_id, right_id FROM phpbb_forums WHERE forum_id = :forumId`) to get its new position, then call `updateTreePosition` with those values. Either approach should be explicit.

---

### I-06 — `updateForum()` `changedFields` computation for `ForumUpdatedEvent` is unspecified

**Spec reference**: Section 11 `updateForum()` implementation; Section 7 `ForumUpdatedEvent`.

**Evidence**:
- Section 7: `ForumUpdatedEvent` has `public array $changedFields` — *"keys of fields that were changed"*
- Section 11: `return new DomainEventCollection([new ForumUpdatedEvent($forum, [...changed fields...], $request->actorId)])`
- `[...changed fields...]` is a placeholder — no algorithm specified

**Gap**: Implementer must invent how to populate `$changedFields`. The `update()` repository method knows which fields were non-null in the request, but doesn't return this information. There's no defined method to compare old vs new entity state.

**Category**: Incomplete — critical detail missing from a core return value.

**Severity**: Medium-High — the event payload is incomplete by design, reducing its utility for audit trails and subscribers.

**Recommendation**: Specify one of: (a) collect non-null field names from `UpdateForumRequest` before calling `update()`, (b) fetch old entity before update, then diff against new entity, or (c) explicitly mark `$changedFields` as implementation-defined and not guaranteed to be exhaustive.

---

### I-07 — `forum_parents` cache not updated after `moveForum()`

**Spec reference**: Section 11 `moveForum()` implementation; Forum entity `$parents: array` field.

**Evidence**:
- Section 11 `moveForum()` steps: fetch old forum → call `treeService->moveNode()` → reload forum → return event
- No step updates `forum_parents` on the moved node or its descendants
- Forum entity section 4: `$parents: array` — *"decoded JSON/serialize"* ancestor chain

**Gap**: After moving forum 5 (previously under forum 3) to under forum 7, forum 5's `forum_parents` column still contains the old ancestor chain `[..., {3}]`. All of forum 5's subtree descendants also have stale `forum_parents`. Any code relying on `$forum->parents` for breadcrumbs will show incorrect data.

**Category**: Missing — cache invalidation/update not specified.

**Severity**: Medium-High — denormalized cache silently incorrect after tree mutations; breadcrumb navigation would be wrong.

**Recommendation**: Spec must either: (a) add a `updateParentsCache(int $forumId): void` step to `moveForum()` that re-computes `forum_parents` for the moved subtree using `getPath()`, or (b) explicitly document that `forum_parents` is a maintenance-deferred denormalized field, unreliable after moves, and callers should use `getPath()` instead.

---

### I-08 — `RequestDecoratorInterface` and `ResponseDecoratorInterface` not defined in spec

**Spec reference**: Section 11 `HierarchyService` constructor; ADR-004; Section 2 file list.

**Evidence**:
- Section 11 constructor: `private readonly array $requestDecorators = []` (typed as `RequestDecoratorInterface[]` in comment)
- Section 11 constructor: `private readonly array $responseDecorators = []` (typed as `ResponseDecoratorInterface[]`)
- Section 2 file list: no `RequestDecoratorInterface.php` or `ResponseDecoratorInterface.php`
- ADR-004 mentions these interfaces but gives no method signatures

**Gap**: Two interfaces required by the HierarchyService constructor are referenced but never specified. `supports()` and `decorateRequest()` are mentioned in section 11 but not defined in any interface contract.

**Category**: Missing — two interfaces exist in the implementation but have no specification.

**Severity**: Medium-High — implementer must invent the interface contracts from context clues.

**Recommendation**: Add to section 2 file list and specify both interfaces, even if minimally:
```
src/phpbb/hierarchy/Plugin/
    RequestDecoratorInterface.php   — supports(CreateForumRequest|UpdateForumRequest): bool; decorateRequest(request): request
    ResponseDecoratorInterface.php  — supports(ForumDTO): bool; decorateResponse(ForumDTO): ForumDTO
```

---

### I-09 — Response decorators injected but never applied in any mutation

**Spec reference**: Section 11 `createForum()`, `updateForum()`, `deleteForum()`, `moveForum()` implementations.

**Evidence**:
- Section 11 `createForum()` step 1: applies `$this->requestDecorators` ✓
- Section 11 `createForum()` after step 4: no response decorator step
- `$responseDecorators` is constructor-injected but used in zero methods
- Section 11 only shows request decorators in `createForum()`. `updateForum()` shows "Apply decorators" (request only). `deleteForum()` and `moveForum()`: no decorator steps at all.

**Gap**: The response decorator pipeline is wired via DI but dead — it never runs. ADR-004 specifically requires response decorators to enrich `ForumDTO` after mutations.

**Category**: Incomplete — declared feature is unimplemented in the spec.

**Severity**: Medium — the decorator pipeline is central to the plugin architecture (ADR-004) but the spec doesn't specify where/how response decorators are applied.

**Recommendation**: Add a response decoration step to each mutation method that returns ForumDTO data. Example for `createForum()`:
```php
$dto = $this->hierarchyService->getForum($forumId);
foreach ($this->responseDecorators as $dec) {
    if ($dec->supports($dto)) $dto = $dec->decorateResponse($dto);
}
```
Then the events should carry the decorated DTO.

---

### I-10 — `services.yaml` registers `DomainEventCollection` as a service (incorrect)

**Spec reference**: Section 15 services.yaml block.

**Evidence**:
- Section 15: `phpbb\common\Event\DomainEventCollection: ~`
- DomainEventCollection is a value object with `__construct(private array $events = [])` — it holds event data per operation
- Registering it as a service creates a singleton container-managed instance with an empty `$events` array
- This singleton would be injected wherever DomainEventCollection is type-hinted, overwriting any event payload

**Gap**: Value objects must not be registered as DI services. `DomainEventCollection` is instantiated with `new DomainEventCollection([...])` in each service method — it must not be a singleton.

**Category**: Incorrect — architecturally wrong DI registration.

**Severity**: Medium-High — if Symfony autowires the registered service instead of `new DomainEventCollection(...)`, all mutation methods would return empty event collections.

**Recommendation**: Remove `phpbb\common\Event\DomainEventCollection: ~` from services.yaml. The comment `# Common domain events (prerequisites)` is misleading — only the event classes themselves need to be autoloadable via Composer's PSR-4, not registered in the container.

---

### I-11 — Nested transaction double-wrapping may corrupt state under SQLite

**Spec reference**: Section 11 `createForum()` and `deleteForum()` implementations; Section 10 algorithm `insertAtPosition()` and `removeNode()`.

**Evidence**:
- Section 11 `createForum()`:
  ```php
  $this->connection->transactional(function() use (...) {
      $this->treeService->insertAtPosition(...); // also wraps transactional()
  });
  ```
- Section 10 `insertAtPosition()`: *"Steps inside `$this->connection->transactional()`"*
- Section 11 `deleteForum()`: same pattern — outer `transactional()` + inner `removeNode()` transactional

**Gap**: DBAL 4 handles MySQL nested transactions via savepoints. SQLite uses WAL-mode but savepoints behave differently — a ROLLBACK TO SAVEPOINT in SQLite releases the savepoint but does not roll back the outer transaction. The spec does not address this edge case or guarantee correct behavior for nested transactions.

**Category**: Incomplete — cross-DB nested transaction behavior undefined.

**Severity**: Medium — may cause unexpected behavior in test environments (SQLite) when the inner transaction fails.

**Recommendation**: Either: (a) remove the outer `transactional()` wrappers from `createForum()` and `deleteForum()` (the tree service already provides transactional guarantees), or (b) explicitly note that the tree service methods are designed to be called from within an existing transaction and do not nest their own transaction when a connection already has one active (use `$connection->isTransactionActive()` check).

---

### I-12 — `subscribe()` method listed twice in `SubscriptionServiceInterface` (copy-paste error)

**Spec reference**: Section 6 `SubscriptionServiceInterface`.

**Evidence**:
- Section 6, under SubscriptionServiceInterface:
  ```
  subscribe(int $userId, int $forumId): void
  subscribe(int $userId, int $forumId): void    // idempotent upsert, notify_status=1
  ```
  The method appears twice with identical signature.

**Category**: Incorrect — obvious copy-paste error.

**Severity**: Medium — confusing for implementers; could cause parse errors if naively copied into PHP interface definition.

**Recommendation**: Remove the duplicate `subscribe()` line.

---

### I-13 — `getTree()` has no REST endpoint

**Spec reference**: Section 6 `HierarchyServiceInterface::getTree()`; Section 14 route table.

**Evidence**:
- Section 6: `getTree(?int $rootId = null): array` — returns all forums in DFS order
- Section 14 routes: 7 routes defined; none calls `getTree()`
- `index()` calls `listForums()` which (per section 11 corrected) should return root forums, not full tree

**Gap**: `getTree()` is a specified interface method with a full implementation spec but is unreachable via HTTP. Callers needing a full nested tree (e.g., breadcrumbs, admin tree view) have no API endpoint.

**Category**: Missing — public interface method with no corresponding HTTP surface.

**Severity**: Medium — reduces API completeness; getTree() is likely the most commonly needed operation for forum display.

**Recommendation**: Add `GET /forums/tree` route (or `GET /forums?depth=full`) wired to `getTree()`.

---

### I-14 — Anonymous user (userId=0) semantics undefined in `TrackingServiceInterface`

**Spec reference**: Section 6 `TrackingServiceInterface`; Section 12 `TrackingService`; ADR-007.

**Evidence**:
- ADR-007 explicitly preserves dual-path tracking (DB for registered, cookie for anonymous)
- Section 12 defers cookie tracking to Phase 2
- `isUnread(int $userId, int $forumId): bool` — for `userId=0`, no DB row exists, always returns `true`
- `markRead(0, $forumId)` would insert a row with `user_id=0` into `phpbb_forums_track`

**Gap**: The interface makes no distinction between registered and anonymous users. Phase 1 TrackingService silently inserts DB rows for userId=0 and misrepresents read status for all anonymous users (always "unread"). This is not documented as a known limitation.

**Category**: Incomplete — phase 1 limitation not documented in interface contract.

**Severity**: Medium — could mislead consumers of TrackingService into building on broken anonymous-user semantics.

**Recommendation**: Add a note to `TrackingServiceInterface` docblocks (or section 20 limitations): "Phase 1: userId=0 (anonymous users) not supported. `isUnread()` always returns true for userId=0. Cookie-based tracking is deferred to Phase 2."

---

### I-15 — ADR-005 text states `ForumCreatedEvent` return type, contradicting `DomainEventCollection`

**Spec reference**: `decision-log.md` ADR-005; Section 6 `HierarchyServiceInterface`.

**Evidence**:
- ADR-005 "Decision Outcome": *"Chosen option: 3 — Event-driven... createForum() → ForumCreatedEvent"*
- ADR-005 "Consequences (Bad)": *"Changed method signature convention: `createForum()` returns `ForumCreatedEvent` not `Forum`"*
- Spec section 6: `createForum(): DomainEventCollection`
- `DOMAIN_EVENTS.md`: *"Mutation Methods Return `DomainEventCollection`"*

**Gap**: ADR-005 documents the chosen architecture as returning the specific event type directly. The spec and DOMAIN_EVENTS.md standard supersede this with `DomainEventCollection`. But ADR-005 was never updated to reflect this evolution.

**Category**: Ambiguous — stale decision record conflicts with current spec.

**Severity**: Medium — architectural documentation is misleading; developers reading ADRs before spec will have wrong mental model.

**Recommendation**: Update ADR-005 status to "Superseded by DOMAIN_EVENTS.md standard" and note that the return type was revised from `ForumCreatedEvent` to `DomainEventCollection`.

---

## 3. Minor Issues

> Low severity — nice to have; does not block correct implementation.

---

### M-01 — `forum_options` column not settable via `CreateForumRequest`

**Evidence**: Section 4 `Forum.$options: int` maps to `forum_options`. Section 5 `CreateForumRequest` has no `$options` field. INSERT SQL in section 9 omits `forum_options` — relies on DB default (0).

**Gap**: This property is silently initialized to 0 from the DB default. If phpBB3 uses non-zero defaults for `forum_options` in production DDL, newly created forums may behave incorrectly.

**Severity**: Low — acceptable if DB schema default is correct.

**Recommendation**: Document explicitly: "forum_options defaults to 0 on create; not settable via API in Phase 1."

---

### M-02 — `ForumCreatedEvent::$parentId` declared `?int` but `Forum::$parentId` is `int`

**Evidence**: Section 7 `ForumCreatedEvent`: `public ?int $parentId`. Section 4 `Forum::$parentId: int`. Root forums have `parentId=0`, not `null`.

**Gap**: The nullable type is inconsistent with the entity's non-nullable type. `$this->parentId = $forum->parentId` always sets an `int`, never `null`.

**Severity**: Low — PHP type widening; won't cause errors but is semantically imprecise.

**Recommendation**: Change to `public int $parentId` to match `Forum::$parentId`.

---

### M-03 — SQLite `FOR UPDATE` compatibility note only for `insertAtPosition`, not `removeNode`/`moveNode`

**Evidence**: Section 10 `insertAtPosition()` has explicit note: *"SQLite compatibility: SQLite does not throw on SELECT ... FOR UPDATE — DBAL silently omits."* `removeNode()` and `moveNode()` also use `FOR UPDATE` but have no equivalent note.

**Severity**: Low — the note is redundant since DBAL behavior is consistent, but its absence from other algorithms could confuse readers.

**Recommendation**: Move the SQLite note to a single location at the top of Section 10 applying to all algorithms.

---

### M-04 — `rebuildTree()` has no test specified in `TreeServiceTest`

**Evidence**: Section 16 `TreeServiceTest` test table lists 8 methods; `rebuildTree()` is in `TreeServiceInterface` (section 6) and has an implementation spec (section 10) but zero corresponding tests.

**Severity**: Low — spec says minimum 6 TreeService tests, but a repair utility with no verification is risky.

**Recommendation**: Add `testRebuildTree_fixesCorruptedLeftRightIds` to TreeServiceTest.

---

### M-05 — `ForumDTO::fromEntity()` mapping is unspecified

**Evidence**: Section 5: *"`static fromEntity(Forum $forum): self` — maps all properties from entity."* ForumDTO has ~16 properties; Forum has ~30. The subset selection is not specified.

**Severity**: Low — implementer can infer from property names, but explicit mapping prevents mistakes with fields like `topicsApproved` vs `stats->topicsApproved`.

**Recommendation**: Add a mapping table to section 5 showing `ForumDTO.field ← Forum.source` for each property.

---

### M-06 — `ForumTypeRegistry` boot strategy ambiguity

**Evidence**: Section 8: *"`RegisterForumTypesEvent` — fired at boot for plugin type registration."* Section 20 deferred items: *"`RegisterForumTypesEvent` dispatched at container boot (currently lazy on first registry use)"*. The boot-time claim is in the main spec; the lazy-initialization is in deferred items.

**Severity**: Low — contradiction in same spec between section 8 ("at boot") and section 20 ("lazy, deferred"). Confirmed lazy by section 8's `private ?array $behaviors = null` pattern.

**Recommendation**: Clarify in section 8 that Phase 1 uses lazy initialization on first `getBehavior()` call, not container boot.

---

### M-07 — Test class `setUpSchema()` for `DbalForumRepositoryTest` missing `forum_options` column

**Evidence**: Section 16 test DDL lists 45 columns but omits `forum_options`. The `hydrate()` method in section 9 reads `(int) $row['forum_options']` — this would throw an `undefined array key` error on SQLite if the column doesn't exist.

**Severity**: Low-Medium — test suite would fail immediately on first `findById()` call.

**Recommendation**: Add `forum_options INTEGER NOT NULL DEFAULT 0` to the test DDL.

---

### M-08 — `listForums()` parameter documentation inconsistency (`?int $parentId` meaning)

**Evidence**: HierarchyServiceInterface: `listForums(?int $parentId = null)` — docstring says *"all root forums if null"*. The `children()` controller method calls `listForums(forumId)`. But `listForums(0)` would call `findChildren(0)` which returns forums with `parent_id=0` (root forums). Same result as `listForums(null)` per the recommended fix in C-03.

**Gap**: `listForums(null)` and `listForums(0)` would behave identically after the C-03 fix, making `?int $parentId` semantically equivalent to `int $parentId`. Minor but confusing.

**Severity**: Low — no functional impact post-fix, but the nullable parameter adds unnecessary ambiguity.

**Recommendation**: Optionally simplify to `listForums(int $parentId = 0): array` where 0 means root.

---

## 4. Standards Compliance Verification

| Standard | Status | Notes |
|---|---|---|
| `declare(strict_types=1)` | ✅ Specified | Section 17 header template includes it |
| GPL-2.0 file header | ✅ Specified | Section 17 shows exact header |
| No closing PHP tag | ✅ Specified | Section 17 explicitly states this |
| Tab indentation | ✅ Specified | Section 17 confirms; section 18 references standard |
| No closing PHP tag | ✅ | |
| `private const TABLE` | ✅ Specified | Sections 9, 12, 13 all show `private const TABLE` |
| Named params without `:` prefix | ✅ Specified | Sections 9 and 19 (acceptance criterion #10) |
| `executeQuery` for reads / `executeStatement` for writes | ✅ Implied | Section 9 shows `executeQuery` for SELECT |
| `RepositoryException` wrapping | ✅ Specified | Section 9 exception wrapping |
| `transactional()` for mutations | ✅ Specified | Sections 10, 11 |
| PHPDoc only where native types insufficient | ✅ Aligned | Section 18 references global standard |
| Readonly constructor props | ✅ Specified | Sections 4, 5, 7 use `final readonly class` |
| PascalCase classes | ✅ All files named correctly | |
| `#[Test]` attribute (no annotations) | ✅ Specified | Section 16 header |
| `IntegrationTestCase` for DB tests | ✅ Specified | Section 16 all DB test classes |
| `assertSame()` over `assertEquals()` | ✅ Referenced | Section 18 references testing standard |
| JWT via `_api_token` attribute | ✅ Aligned | Section 14 uses `$request->attributes->get('_api_token')` |
| `data` top-level JSON key | ✅ Aligned | Section 14 all responses use `data` key |
| HTTP 201 for create | ✅ Aligned | Section 14: `new JsonResponse([...], 201)` |
| HTTP 204 for delete | ✅ Aligned | Section 14 delete returns 204 |
| HTTP 422 for validation errors | ✅ Aligned | Section 14 create validation returns 422 |

---

## 5. ADR Compliance Verification

| ADR | Status | Notes |
|---|---|---|
| ADR-001: Single Forum entity + ForumTypeRegistry | ✅ Compliant | Sections 4, 8 correctly implement |
| ADR-002: Nested set with SELECT FOR UPDATE | ✅ Compliant | Section 10 algorithms |
| ADR-003: Exactly 5 services | ✅ Compliant | HierarchyService, ForumRepository, TreeService, TrackingService, SubscriptionService |
| ADR-004: Events + decorators, NO service_collection | ⚠️ Partial | Request decorators specified; Response decorators injected but not applied (I-09); Interfaces not defined (I-08) |
| ADR-005: DomainEventCollection return | ✅ Spec correct, ADR stale | Spec correctly uses DomainEventCollection; ADR-005 text is outdated (I-15) |
| ADR-006: No ACL in hierarchy | ✅ Compliant | No auth checks in service layer |
| ADR-007: Dual-path tracking | ⚠️ Partial | DB path specified; cookie path recognized as deferred; but anonymous user semantics undefined (I-14) |

---

## 6. Test Coverage Verification

| Test file | Min required | Spec lists | Status |
|---|---|---|---|
| `DbalForumRepositoryTest` | 8 | 12 methods in table | ✅ Exceeds minimum |
| `TreeServiceTest` | 6 | 8 methods in table | ✅ Exceeds minimum |
| `TrackingServiceTest` | 5 | 6 methods in table | ✅ Meets/exceeds |
| `SubscriptionServiceTest` | 4 | 6 methods in table | ✅ Exceeds |
| `HierarchyServiceTest` | 5 | 8 methods in table | ✅ Exceeds |
| **Total** | 40 | **~40 in tables** | ⚠️ Meets minimum only if all specified tests are implemented; entity tests (`ForumTest`, `ForumTypeTest`) not in tables but listed in section 2 |
| `rebuildTree()` | 0 specified | 0 | ❌ Gap (M-04) |
| `SubscriptionServiceTest` UNIQUE constraint | Required | Missing | ❌ Schema gap (I-03) |

---

## 7. Edge Case Coverage

| Edge case | Covered in spec | Notes |
|---|---|---|
| Delete forum with children | ❌ **Not handled** | Critical gap C-01 — orphan rows |
| Move forum into its own subtree | ✅ Handled | Section 10 moveNode() step 2: cycle guard |
| `forum_parents` on create | ❌ **Bug** | Stored as `''` not `'[]'` (I-04) |
| `forum_parents` on move | ❌ **Not handled** | Cache not updated (I-07) |
| `forum_parents` on delete | ❌ **Not specified** | Orphan rows have stale parents |
| TreeService finds inconsistent left/right | ⚠️ Partial | `rebuildTree()` specified as repair tool but not triggered automatically |
| Empty tree (no forums) | ✅ Handled | `getSubtree(null)` returns `[]` gracefully |
| Root-level insert (parentId=0) | ⚠️ Ambiguous | `insertAtPosition(forumId, 0)` — spec doesn't show what happens when parentId=0 (no parent row exists) |
| Concurrent tree mutations | ✅ Addressed | SELECT FOR UPDATE locking; single-writer bottleneck acceptable for admin ops |

---

## 8. Recommendations Summary

1. **C-01** — Define and implement orphan/child handling strategy in `deleteForum()` before writing any code.
2. **C-02** — Add `POST /forums/{forumId}/move` or `PATCH /forums/{forumId}/move` endpoint wired to `moveForum()`.
3. **C-03** — Fix `listForums(null)` to call `findChildren(0)`, not `findAll()`.
4. **C-04** — Add explicit 401 check before 403 check in all write endpoints.
5. **I-01** — Reconcile `DomainEventCollection` with `DOMAIN_EVENTS.md` — add `first()`, `all()` to the standard or refactor controller to not use `first()`.
6. **I-03** — Add `PRIMARY KEY (forum_id, user_id)` to `phpbb_forums_watch` test DDL.
7. **I-04** — Change hardcoded `''` in `insertRaw()` SQL for `forum_parents` to `'[]'`.
8. **I-05** — Replace `<adjusted>` placeholder in `moveNode()` Step F with explicit formula or re-query pattern.
9. **I-08+I-09** — Define `RequestDecoratorInterface` and `ResponseDecoratorInterface` contracts; add response decorator application step to mutation methods.
10. **I-10** — Remove `DomainEventCollection` from `services.yaml`.
11. **M-07** — Add `forum_options` column to test DDL.

---

## Compliance Status

❌ **Non-Compliant**

Four critical issues make the spec unsafe to implement as written:
1. Data integrity failure on forum deletion (orphan children)
2. Core mutation (`moveForum`) is unreachable via HTTP
3. Incorrect auth status codes contradicting stated acceptance criteria
4. `listForums(null)` returns wrong data set

The spec is otherwise comprehensive and well-structured. The DBAL patterns, nested set algorithms, plugin system design, and test specifications are thorough. With the 4 critical fixes applied, the spec would be promotable to implementation-ready after resolving the 15 important issues.
