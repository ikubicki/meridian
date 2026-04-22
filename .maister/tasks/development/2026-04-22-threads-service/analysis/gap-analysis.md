# Gap Analysis: Threads Service Module

## Summary
- **Risk Level**: low-medium
- **Estimated Effort**: medium
- **Detected Characteristics**: modifies_existing_code, creates_new_entities, involves_data_operations

## Task Characteristics
- Has reproducible defect: no
- Modifies existing code: yes (TopicsController, PostsController, services.yaml)
- Creates new entities: yes (entire `src/phpbb/threads/` module)
- Involves data operations: yes (phpbb_topics and phpbb_posts CRUD)
- UI heavy: no (REST API only)

---

## Gaps Identified

### Missing Features (entire module absent)

The directory `src/phpbb/threads/` does not exist. Zero files from the desired state are present.

**Contracts (interfaces) — all missing:**
- `phpbb\threads\Contract\ThreadsServiceInterface`
- `phpbb\threads\Contract\TopicRepositoryInterface`
- `phpbb\threads\Contract\PostRepositoryInterface`

**Entities — all missing:**
- `phpbb\threads\Entity\Topic`
- `phpbb\threads\Entity\Post`

**DTOs — all missing:**
- `phpbb\threads\DTO\TopicDTO`
- `phpbb\threads\DTO\PostDTO`
- `phpbb\threads\DTO\CreateTopicRequest`
- `phpbb\threads\DTO\CreatePostRequest`

**Repositories — all missing:**
- `phpbb\threads\Repository\DbalTopicRepository`
- `phpbb\threads\Repository\DbalPostRepository`

**Service — missing:**
- `phpbb\threads\Service\ThreadsService`

**Domain events — all missing:**
- `phpbb\threads\Event\TopicCreatedEvent`
- `phpbb\threads\Event\PostCreatedEvent`

### Incomplete Features (exist but need change)

**TopicsController** — exists at `src/phpbb/api/Controller/TopicsController.php`:
- Injects `Connection` directly (raw SQL anti-pattern)
- Contains business logic inline (SQL queries, transaction management)
- Contains private `topicRowToArray()` mapping method that should move to DTO
- After refactor: must inject `ThreadsServiceInterface` + `EventDispatcherInterface`, delegate all data operations
- Constructor dependencies change from `(Connection, AuthorizationServiceInterface, UserRepositoryInterface)` to `(ThreadsServiceInterface, AuthorizationServiceInterface, UserRepositoryInterface, EventDispatcherInterface)`

**PostsController** — exists at `src/phpbb/api/Controller/PostsController.php`:
- Injects `Connection` directly (raw SQL anti-pattern)
- Contains inline topic lookup + post insertion + topic denormalization update
- After refactor: delegates to `ThreadsServiceInterface`
- Constructor dependencies change from `(Connection, AuthorizationServiceInterface)` to `(ThreadsServiceInterface, AuthorizationServiceInterface, EventDispatcherInterface)`

**services.yaml** — exists but contains no threads/topic/post service registrations:
- Needs new section for `phpbb\threads\` module (repositories, service, interface alias)

### Behavioral Changes Needed

The `create` method in `TopicsController` (POST /forums/{forumId}/topics) performs a multi-step transaction inline:
1. INSERT into `phpbb_topics`
2. INSERT into `phpbb_posts` (first post)
3. UPDATE `phpbb_topics` to set `topic_first_post_id` and `topic_last_post_id`

This three-step logic must move intact into `ThreadsService::createTopic()` with the same transaction boundary. The behavior is preserved; only location changes.

The `create` method in `PostsController` (POST /topics/{topicId}/posts) also updates topic denormalization fields (`topic_last_post_id`, `topic_last_poster_*`, `topic_last_post_time`) after inserting a post. This must also move to `ThreadsService::createPost()` with the same atomicity.

---

## Data Lifecycle Analysis

### Entity: Topic (phpbb_topics table)

| Operation | Backend | UI Component | User Access | Status |
|-----------|---------|--------------|-------------|--------|
| CREATE | TopicsController::create() — inline SQL | N/A (API) | POST /forums/{forumId}/topics | PARTIAL — no service layer |
| READ (list) | TopicsController::indexByForum() — inline SQL | N/A (API) | GET /forums/{forumId}/topics | PARTIAL — no service layer |
| READ (single) | TopicsController::show() — inline SQL | N/A (API) | GET /topics/{topicId} | PARTIAL — no service layer |
| UPDATE | Not implemented | N/A | No endpoint | NOT IN SCOPE |
| DELETE | Not implemented | N/A | No endpoint | NOT IN SCOPE |

**Completeness for in-scope operations**: 100% routes exist, 0% service layer exists
**Orphaned Operations**: None (all routes are functional, just architecturally wrong)

### Entity: Post (phpbb_posts table)

| Operation | Backend | UI Component | User Access | Status |
|-----------|---------|--------------|-------------|--------|
| CREATE (first post) | TopicsController::create() — inline SQL | N/A (API) | POST /forums/{forumId}/topics | PARTIAL — no service layer |
| CREATE (reply) | PostsController::create() — inline SQL | N/A (API) | POST /topics/{topicId}/posts | PARTIAL — no service layer |
| READ | Not implemented | N/A | No endpoint | NOT IN SCOPE |
| UPDATE | Not implemented | N/A | No endpoint | NOT IN SCOPE |
| DELETE | Not implemented | N/A | No endpoint | NOT IN SCOPE |

**Completeness for in-scope operations**: 100% routes exist, 0% service layer exists
**Orphaned Operations**: None — routes are live and returning correct HTTP responses

---

## Existing Tests

A `TopicsControllerTest` exists at `tests/phpbb/api/Controller/TopicsControllerTest.php` with 5 test cases covering the three `TopicsController` methods. These tests mock `Connection` directly.

**Impact**: After refactoring, these tests will need to be rewritten to mock `ThreadsServiceInterface` instead of `Connection`. The test file cannot be left as-is after the controller refactor.

No `PostsControllerTest` exists. This is a gap if tests are expected to match the hierarchy module pattern (which has `HierarchyServiceTest`, `DbalForumRepositoryTest`, entity tests, etc.).

**Hierarchy module test coverage to mirror:**
- `tests/phpbb/hierarchy/Service/HierarchyServiceTest.php` (integration test with SQLite)
- `tests/phpbb/hierarchy/Repository/DbalForumRepositoryTest.php` (integration test)
- `tests/phpbb/hierarchy/Entity/ForumTest.php` (unit test)
- `tests/phpbb/api/Controller/ForumsControllerTest.php` (unit test with mocks)

---

## Integration Points

### DI Registration Pattern (from hierarchy module)

The services.yaml pattern to follow for the new section:

```yaml
# Threads module
phpbb\threads\Repository\DbalTopicRepository:
    arguments:
        $connection: '@Doctrine\DBAL\Connection'

phpbb\threads\Contract\TopicRepositoryInterface:
    alias: phpbb\threads\Repository\DbalTopicRepository

phpbb\threads\Repository\DbalPostRepository:
    arguments:
        $connection: '@Doctrine\DBAL\Connection'

phpbb\threads\Contract\PostRepositoryInterface:
    alias: phpbb\threads\Repository\DbalPostRepository

phpbb\threads\Service\ThreadsService:
    arguments:
        $topicRepository: '@phpbb\threads\Contract\TopicRepositoryInterface'
        $postRepository:  '@phpbb\threads\Contract\PostRepositoryInterface'

phpbb\threads\Contract\ThreadsServiceInterface:
    alias: phpbb\threads\Service\ThreadsService
    public: true
```

The controllers under `phpbb\api\Controller\` are already auto-wired via the wildcard resource registration in services.yaml — no change needed there, only the constructor signature changes.

### Namespace Mapping

`composer.json` maps `phpbb\` to `src/phpbb/` via PSR-4 autoload. The new `phpbb\threads\` namespace will be automatically resolvable at `src/phpbb/threads/` with no composer.json changes required.

### Patterns to Follow

**Entity pattern**: `phpbb\hierarchy\Entity\Forum` — `final readonly` class with typed constructor properties, no setters, value-object sub-entities for groupings (ForumStats, ForumLastPost).

**DTO pattern**: `phpbb\hierarchy\DTO\ForumDTO` — `final readonly` class with static `fromEntity()` factory method.

**Request DTO pattern**: `phpbb\hierarchy\DTO\CreateForumRequest` — plain readonly class, constructor properties with defaults.

**Repository pattern**: `phpbb\hierarchy\Repository\DbalForumRepository` — wraps Doctrine DBAL, uses `executeQuery`/`executeStatement` (not `fetchAssociative` directly on Connection), throws `phpbb\db\Exception\RepositoryException` on DBAL exceptions, private `hydrate()` method.

**Service pattern**: `phpbb\hierarchy\Service\HierarchyService` — `final`, injected interface dependencies, returns `DomainEventCollection` from mutation methods, returns DTOs from query methods.

**Event pattern**: `phpbb\hierarchy\Event\ForumCreatedEvent` — `final readonly` class extending `phpbb\common\Event\DomainEvent`. The base class has `entityId`, `actorId`, `occurredAt` properties. No additional properties needed unless the event requires extra context.

**Controller pattern**: `phpbb\api\Controller\ForumsController` — injects service interface + `EventDispatcherInterface`, calls `$events->dispatch($this->dispatcher)` after mutations, uses `try/catch \InvalidArgumentException` for 404/400 responses.

---

## Specific Implementation Notes

### Asymmetry: Topic CREATE also creates the first Post

`TopicsController::create()` inserts both a `phpbb_topics` row and a `phpbb_posts` row atomically. `ThreadsService::createTopic()` must preserve this: it is a single transaction that creates both records and back-fills the post IDs onto the topic.

This means `DbalTopicRepository::insert()` alone is insufficient for topic creation — the service layer must orchestrate the cross-table operation. Options:

**Option A**: `ThreadsService::createTopic()` calls both `topicRepository->insert()` and `postRepository->insert()` with explicit transaction wrapping in the service method.

**Option B**: `DbalTopicRepository::insertWithFirstPost()` encapsulates the entire three-step transaction as a single repository method.

Option A aligns with the hierarchy pattern (service orchestrates, repository is single-table). Option B is more encapsulated but deviates from the pattern.

### topic_status check in PostsController

`PostsController::create()` fetches `topic_status` from `phpbb_topics` but currently does NOT enforce a locked-topic check — it only checks `f_reply` permission. This implicit gap in the current code should be preserved as-is during refactor (do not add locked topic enforcement unless explicitly requested).

### `post_subject` hardcoding

`PostsController::create()` sets `post_subject = 'Re: post'` (hardcoded string). This is a known quirk in the existing code. The refactor should preserve this behavior exactly.

---

## Issues Requiring Decisions

### Critical (Must Decide Before Proceeding)

1. **Transaction boundary for topic creation (cross-table)**
   - Issue: Creating a topic requires inserting into two tables (`phpbb_topics` and `phpbb_posts`) atomically. How should this be structured in the clean architecture?
   - Options:
     - A: Service orchestrates the transaction — `ThreadsService::createTopic()` wraps both `topicRepository->insert()` and `postRepository->insert()` in a try/catch with explicit `connection->beginTransaction()`/`commit()`/`rollBack()`. Requires `Connection` injection in the service (slight coupling, but honest).
     - B: Repository method encapsulates — `DbalTopicRepository::insertWithFirstPost()` takes both topic and post data, handles the full three-step transaction internally.
   - Recommendation: Option A
   - Rationale: Option A keeps repositories single-table (consistent with DbalForumRepository pattern) and makes the cross-entity transaction visible at the service layer where it belongs.

### Important (Should Decide)

2. **Test scope: should TopicsControllerTest be refactored as part of this task?**
   - Issue: The existing `TopicsControllerTest` mocks `Connection` directly. After the controller refactor, it will fail (wrong constructor). The test must be updated.
   - Options:
     - A: Include test refactoring in scope — update `TopicsControllerTest` to mock `ThreadsServiceInterface`, and add `PostsControllerTest`, `ThreadsServiceTest`, `DbalTopicRepositoryTest`, `DbalPostRepositoryTest`.
     - B: Update `TopicsControllerTest` only (minimum needed to keep CI green), defer new service/repository tests.
   - Default: Option A (full test parity with hierarchy module)
   - Rationale: The hierarchy module has full test coverage across all layers; this module should match. The existing test is already broken by the architecture change.

3. **Should `ThreadsServiceInterface` be a single interface covering both topics and posts, or split into `TopicServiceInterface` + `PostServiceInterface`?**
   - Issue: The clarifications say "ThreadsService covering both topics and posts operations" but do not specify if the interface is unified or split.
   - Options:
     - A: Single `ThreadsServiceInterface` with all topic and post methods (matches the name requested in clarifications)
     - B: Split into `TopicServiceInterface` and `PostServiceInterface`, injected separately into the two controllers
   - Default: Option A (single interface, as described in clarifications)
   - Rationale: Simpler DI registration, matches the module name `phpbb\threads\`, and both controllers are in the same bounded context.

---

## Recommendations

1. Use `DbalTopicRepository` and `DbalPostRepository` as single-table repositories, with transaction management in `ThreadsService`. Inject `Connection` into the service for the explicit transaction boundary (not into repositories separately — let autowiring handle it for repositories).

2. `TopicDTO::fromEntity(Topic $topic)` and `PostDTO::fromEntity(Post $post)` static factories should produce the same field shapes currently returned by `topicRowToArray()` and the inline post array in PostsController to ensure zero response shape change.

3. Define `TopicCreatedEvent` with only `entityId` (topicId) and `actorId` (userId) — the base `DomainEvent` class already provides these. Do the same for `PostCreatedEvent` with `entityId` = postId. Do not add extra event properties unless a listener will need them.

4. The `post_subject = 'Re: post'` hardcode should be preserved as-is in `CreatePostRequest` as a default. Do not change behavior during this refactor.

5. Update `TopicsControllerTest` as part of this task — the controller constructor signature changes make the existing test structurally invalid.

---

## Risk Assessment

- **Complexity Risk**: Low — this is a pure architectural extraction with no behavior changes. All logic is already working; it is being moved, not invented.
- **Integration Risk**: Low — routes are preserved exactly. The only integration surface that changes is the controller constructor, which is managed by Symfony DI autowiring. services.yaml update is straightforward.
- **Regression Risk**: Low-medium — the transaction logic for topic+post creation must be reproduced faithfully. The `TopicsControllerTest` will break if not updated. No DB migrations required (table schemas unchanged).
