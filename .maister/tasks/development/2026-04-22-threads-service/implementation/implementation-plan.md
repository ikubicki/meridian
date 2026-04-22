# Implementation Plan: Threads Service Module (`phpbb\threads\`)

## Overview

Total Steps: 54
Task Groups: 7
Expected Tests: 38–48 (6 groups × 4–8 tests each + up to 10 in final review)

## Implementation Steps

---

### Task Group 1: Foundation — Entities, DTOs, Contracts
**Dependencies:** None
**Estimated Steps:** 9

- [x] 1.0 Complete foundation layer (entities, DTOs, contracts)
  - [x] 1.1 Write 4 unit tests for entity construction and DTO mapping
    - `Topic` constructor accepts all 15 typed constructor properties
    - `Post` constructor accepts all 10 typed constructor properties
    - `TopicDTO::fromEntity()` maps all 8 fields correctly (verify `authorId` ← `posterId`, `postCount` ← `postsApproved`, `createdAt` ← `time`)
    - `PostDTO::fromEntity()` maps all 5 fields correctly (verify `content` ← `text`, `authorId` ← `posterId`)
    - Run: `vendor/bin/phpunit --filter TopicEntityTest --filter PostEntityTest --filter TopicDTOTest --filter PostDTOTest`
  - [x] 1.2 Create `src/phpbb/threads/Entity/Topic.php`
    - `final readonly class Topic` with 15 constructor properties mirroring `phpbb_topics` columns
    - File header copyright block, `declare(strict_types=1)`, namespace `phpbb\threads\Entity`
    - Properties: `int $id`, `int $forumId`, `string $title`, `int $posterId`, `int $time`, `int $postsApproved`, `int $lastPostTime`, `string $lastPosterName`, `int $lastPosterId`, `string $lastPosterColour`, `int $firstPostId`, `int $lastPostId`, `int $visibility`, `string $firstPosterName`, `string $firstPosterColour`
  - [x] 1.3 Create `src/phpbb/threads/Entity/Post.php`
    - `final readonly class Post` with 10 constructor properties mirroring `phpbb_posts` columns
    - Properties: `int $id`, `int $topicId`, `int $forumId`, `int $posterId`, `int $time`, `string $text`, `string $subject`, `string $username`, `string $posterIp`, `int $visibility`
  - [x] 1.4 Create `src/phpbb/threads/DTO/TopicDTO.php`
    - `final readonly class TopicDTO` with 8 public properties
    - Static `fromEntity(Topic $topic): self` factory mapping fields as specified
    - Properties: `int $id`, `string $title`, `int $forumId`, `int $authorId`, `int $postCount`, `string $lastPosterName`, `int|string $lastPostTime`, `int|string $createdAt`
  - [x] 1.5 Create `src/phpbb/threads/DTO/PostDTO.php`
    - `final readonly class PostDTO` with 5 public properties
    - Static `fromEntity(Post $post): self` factory
    - Properties: `int $id`, `int $topicId`, `int $forumId`, `int $authorId`, `string $content`
  - [x] 1.6 Create `src/phpbb/threads/DTO/CreateTopicRequest.php`
    - `final readonly class CreateTopicRequest` with 7 constructor properties: `int $forumId`, `string $title`, `string $content`, `int $actorId`, `string $actorUsername`, `string $actorColour`, `string $posterIp`
  - [x] 1.7 Create `src/phpbb/threads/DTO/CreatePostRequest.php`
    - `final readonly class CreatePostRequest` with 6 constructor properties: `int $topicId`, `string $content`, `int $actorId`, `string $actorUsername`, `string $actorColour`, `string $posterIp`
  - [x] 1.8 Create `src/phpbb/threads/Contract/TopicRepositoryInterface.php`
    - Methods: `findById`, `findByForum`, `insert`, `updateFirstLastPost`, `updateLastPost`
    - `findByForum` return type: `PaginatedResult` (use `phpbb\user\DTO\PaginatedResult`)
    - `insert` signature: `insert(CreateTopicRequest $request, int $now): int`
    - All methods annotated to throw `RepositoryException` on DBAL failure
  - [x] 1.9 Create `src/phpbb/threads/Contract/PostRepositoryInterface.php` and `src/phpbb/threads/Contract/ThreadsServiceInterface.php`
    - `PostRepositoryInterface` methods: `findById`, `findByTopic`, `insert` (9-param signature returning `int`)
    - `ThreadsServiceInterface` methods: `getTopic`, `listTopics`, `createTopic`, `listPosts`, `createPost`
    - All return types as per spec (`DomainEventCollection` for mutations, `PaginatedResult`/`TopicDTO`/`PostDTO` for queries)
  - [x] 1.n Ensure foundation tests pass
    - Run ONLY the 4 tests written in step 1.1
    - Do NOT run entire test suite

**Acceptance Criteria:**
- All 4 unit tests pass
- 13 files created under `src/phpbb/threads/` (Entity/, DTO/, Contract/ subdirectories)
- All classes use `declare(strict_types=1)`, copyright header, and PSR-4 namespace matching directory
- `final readonly` used on all entity and DTO classes

---

### Task Group 2: Repository Layer — `DbalTopicRepository`
**Dependencies:** Group 1
**Estimated Steps:** 8

- [x] 2.0 Complete DbalTopicRepository
  - [x] 2.1 Write 7 integration tests for `DbalTopicRepository` (extends `IntegrationTestCase`, SQLite in-memory)
    - `findById` returns `null` for unknown ID
    - `findById` returns hydrated `Topic` with all 15 properties for existing row
    - `insert` persists a row and returns auto-increment integer ID
    - `findByForum` returns `PaginatedResult` with correct `total` count
    - `findByForum` respects `topic_visibility = 1` filter (invisible topics excluded)
    - `updateFirstLastPost` writes correct `topic_first_post_id` and `topic_last_post_id` to row
    - `updateLastPost` writes all 5 denormalization columns correctly
    - Run: `vendor/bin/phpunit --filter DbalTopicRepositoryTest`
  - [x] 2.2 Create `tests/phpbb/threads/Repository/DbalTopicRepositoryTest.php`
    - Extend `IntegrationTestCase`, implement `setUpSchema()` creating minimal `phpbb_topics` table in SQLite
    - Helper `insertTopic(array $overrides = []): int` method for test data
    - Use `#[Test]` attribute on all test methods
    - AAA structure in all test methods
  - [x] 2.3 Create `src/phpbb/threads/Repository/DbalTopicRepository.php`
    - `class DbalTopicRepository implements TopicRepositoryInterface`
    - `private const TABLE = 'phpbb_topics'`
    - Constructor: `(private readonly \Doctrine\DBAL\Connection $connection)`
    - Implement `findById`: SELECT all 15 columns WHERE `topic_id = :id` LIMIT 1; wrap `\Doctrine\DBAL\Exception` in `RepositoryException`
    - Implement `findByForum`: COUNT query + paginated SELECT with `topic_visibility = 1`, ORDER BY `topic_last_post_time DESC`, LIMIT/OFFSET from `PaginationContext`; return `PaginatedResult` with `TopicDTO::fromEntity($this->hydrate($row))` items
    - Implement `insert`: INSERT with all columns per spec SQL; return `(int) $this->connection->lastInsertId()`
    - Implement `updateFirstLastPost`: UPDATE `topic_first_post_id`, `topic_last_post_id`
    - Implement `updateLastPost`: UPDATE 5 denormalization columns
    - Private `hydrate(array $row): Topic` — cast all values to typed properties
  - [x] 2.n Ensure DbalTopicRepository tests pass
    - Run ONLY the 7 tests written in step 2.1
    - Do NOT run entire test suite

**Acceptance Criteria:**
- All 7 integration tests pass against SQLite in-memory
- `DbalTopicRepository` contains zero raw column names outside SQL strings
- `hydrate()` casts all 15 columns to correct PHP types
- Every public method wraps DBAL exceptions in `RepositoryException`

---

### Task Group 3: Repository Layer — `DbalPostRepository`
**Dependencies:** Group 1
**Estimated Steps:** 7

- [x] 3.0 Complete DbalPostRepository
  - [x] 3.1 Write 4 integration tests for `DbalPostRepository` (extends `IntegrationTestCase`, SQLite in-memory)
    - `findById` returns `null` for unknown ID
    - `insert` persists a row and returns auto-increment integer ID
    - `findByTopic` returns `PaginatedResult` filtered by `post_visibility = 1` (invisible posts excluded)
    - `findByTopic` orders results by `post_time ASC`
    - Run: `vendor/bin/phpunit --filter DbalPostRepositoryTest`
  - [x] 3.2 Create `tests/phpbb/threads/Repository/DbalPostRepositoryTest.php`
    - Extend `IntegrationTestCase`, implement `setUpSchema()` creating minimal `phpbb_posts` table in SQLite
    - Helper `insertPost(array $overrides = []): int` method for test data
    - Use `#[Test]` attribute, AAA structure
  - [x] 3.3 Create `src/phpbb/threads/Repository/DbalPostRepository.php`
    - `class DbalPostRepository implements PostRepositoryInterface`
    - `private const TABLE = 'phpbb_posts'`
    - Constructor: `(private readonly \Doctrine\DBAL\Connection $connection)`
    - Implement `findById`: SELECT all 10 columns WHERE `post_id = :id` LIMIT 1
    - Implement `findByTopic`: COUNT query + paginated SELECT with `post_visibility = 1`, ORDER BY `post_time ASC`; return `PaginatedResult` with `PostDTO::fromEntity($this->hydrate($row))` items
    - Implement `insert`: INSERT with all 9 columns per spec SQL; return `(int) $this->connection->lastInsertId()`
    - Private `hydrate(array $row): Post` — cast all values to typed properties
  - [x] 3.n Ensure DbalPostRepository tests pass
    - Run ONLY the 4 tests written in step 3.1
    - Do NOT run entire test suite

**Acceptance Criteria:**
- All 4 integration tests pass against SQLite in-memory
- `findByTopic` orders by `post_time ASC` (not DESC)
- Every public method wraps DBAL exceptions in `RepositoryException`

---

### Task Group 4: Service Layer — `ThreadsService`
**Dependencies:** Groups 1, 2, 3
**Estimated Steps:** 9

- [x] 4.0 Complete ThreadsService
  - [x] 4.1 Write 8 integration tests for `ThreadsService` (extends `IntegrationTestCase`, real repositories, SQLite in-memory)
    - `getTopic` throws `\InvalidArgumentException` for unknown topic ID
    - `getTopic` throws `\InvalidArgumentException` for topic with `visibility != 1`
    - `listTopics` returns `PaginatedResult` with correct item count
    - `createTopic` inserts topic and first post atomically; returns `DomainEventCollection` containing `TopicCreatedEvent`
    - `createTopic` returns event with correct `actorId`
    - `createPost` throws `\InvalidArgumentException` for unknown topic
    - `createPost` inserts post with `post_subject = 'Re: ' . topic_title`
    - `createPost` returns `DomainEventCollection` containing `PostCreatedEvent`
    - Run: `vendor/bin/phpunit --filter ThreadsServiceTest`
  - [x] 4.2 Create `tests/phpbb/threads/Service/ThreadsServiceTest.php`
    - Extend `IntegrationTestCase`, implement `setUpSchema()` creating both `phpbb_topics` and `phpbb_posts` tables
    - Instantiate real `DbalTopicRepository`, `DbalPostRepository`, and `ThreadsService` (no mocks — integration test)
    - Helper factory `makeCreateTopicRequest(array $overrides = []): CreateTopicRequest`
    - Helper factory `makeCreatePostRequest(array $overrides = []): CreatePostRequest`
    - Use `#[Test]` attribute, AAA structure
  - [x] 4.3 Create `src/phpbb/threads/Service/ThreadsService.php`
    - `final class ThreadsService implements ThreadsServiceInterface`
    - Constructor injects `TopicRepositoryInterface`, `PostRepositoryInterface`, `\Doctrine\DBAL\Connection`
    - `getTopic`: calls `findById`; throws `\InvalidArgumentException` if null or `visibility !== 1`; returns `TopicDTO::fromEntity($topic)`
    - `listTopics`: delegates directly to `$this->topicRepository->findByForum($forumId, $ctx)`
    - `createTopic`: `beginTransaction` → insert topic → insert post → `updateFirstLastPost` → `commit`; on `\Throwable` `rollBack` and re-throw as `\RuntimeException`; return `new DomainEventCollection([new TopicCreatedEvent(...)])`
    - `listPosts`: verifies topic exists via `findById` (throws `\InvalidArgumentException` if null); delegates to `$this->postRepository->findByTopic($topicId, $ctx)`
    - `createPost`: fetches topic via `findById` (throws if null); computes `$subject = 'Re: ' . $topic->title`; `beginTransaction` → insert post → `updateLastPost` → `commit`; return `new DomainEventCollection([new PostCreatedEvent(...)])`
  - [x] 4.4 Create `src/phpbb/threads/Event/TopicCreatedEvent.php` and `src/phpbb/threads/Event/PostCreatedEvent.php`
    - Both: `final readonly class XxxCreatedEvent extends DomainEvent {}`
    - Extend `phpbb\common\Event\DomainEvent`; no additional properties
    - Reuse: `src/phpbb/hierarchy/Event/ForumCreatedEvent.php` as structural template
  - [x] 4.n Ensure ThreadsService tests pass
    - Run ONLY the 8 tests written in step 4.1
    - Do NOT run entire test suite

**Acceptance Criteria:**
- All 8 integration tests pass
- `ThreadsService` has zero raw SQL
- Transaction rollback path covered by tests
- `post_subject` for replies is `'Re: ' . topic_title` (verified by test in 4.1)
- `DomainEventCollection::first()` is non-null after both `createTopic` and `createPost`

---

### Task Group 5: Controller Refactor — `TopicsController` + `PostsController`
**Dependencies:** Groups 1, 4
**Estimated Steps:** 10

- [x] 5.0 Complete controller refactor
  - [x] 5.1 Write 8 unit tests for refactored `TopicsController` (mock `ThreadsServiceInterface`)
    - `indexByForum` returns 200 with `data` array and `meta` envelope
    - `indexByForum` returns 403 when ACL denied
    - `show` returns 200 with `data` envelope containing all `TopicDTO` fields
    - `show` returns 404 when `ThreadsService` throws `\InvalidArgumentException`
    - `show` returns 403 when ACL denied
    - `create` returns 201 with full `TopicDTO` in `data` (not partial inline array)
    - `create` returns 401 without authenticated `_api_user`
    - `create` returns 400 with empty title
    - Run: `vendor/bin/phpunit --filter TopicsControllerTest`
  - [x] 5.2 Write 6 unit tests for new/refactored `PostsController` (mock `ThreadsServiceInterface`)
    - `index` returns 200 with `data` array and `meta` pagination envelope
    - `index` returns 404 when topic not found (`\InvalidArgumentException` from service)
    - `create` returns 201 with post data envelope
    - `create` returns 401 without authenticated user
    - `create` returns 400 with empty content
    - `create` returns 404 for unknown topic
    - Run: `vendor/bin/phpunit --filter PostsControllerTest`
  - [x] 5.3 Rewrite `tests/phpbb/api/Controller/TopicsControllerTest.php`
    - Replace `Connection` mock with `ThreadsServiceInterface` mock
    - Verify `create` test asserts `data` contains `TopicDTO` fields (`id`, `title`, `forumId`, `authorId`, `postCount`, `lastPosterName`, `lastPostTime`, `createdAt`) rather than partial `firstPost` shape
    - Keep `makeUser()` helper; adapt all 8 test methods per new contract
    - Use `#[Test]` attribute, AAA structure
  - [x] 5.4 Create `tests/phpbb/api/Controller/PostsControllerTest.php`
    - Mock `ThreadsServiceInterface`, `AuthorizationServiceInterface`, `UserRepositoryInterface`, `EventDispatcherInterface`
    - 6 tests covering both `index` (GET) and `create` (POST) actions
    - Use `#[Test]` attribute, AAA structure
  - [x] 5.5 Refactor `src/phpbb/api/Controller/TopicsController.php`
    - Replace constructor: inject `ThreadsServiceInterface`, `AuthorizationServiceInterface`, `UserRepositoryInterface`, `EventDispatcherInterface`; remove `Connection`
    - `indexByForum`: build `PaginationContext::fromQuery($request->query)`; call `$this->threadsService->listTopics($forumId, $ctx)`; return paginated JSON with `lastPage = max(1, $result->totalPages())`; serialize `TopicDTO` properties as associative array
    - `show`: call `$this->threadsService->getTopic($topicId)` wrapped in try/catch `\InvalidArgumentException` → 404; ACL check on `$dto->forumId`; return `JsonResponse(['data' => [...$dto]])` serialized from DTO properties
    - `create`: validate; build `CreateTopicRequest`; call `$this->threadsService->createTopic()`; call `$events->dispatch($this->dispatcher)`; fetch topic via `$this->threadsService->getTopic($events->first()->entityId)`; return 201 with full `TopicDTO`
    - Delete private `topicRowToArray()` method
  - [x] 5.6 Refactor `src/phpbb/api/Controller/PostsController.php`
    - Replace constructor: inject `ThreadsServiceInterface`, `AuthorizationServiceInterface`, `UserRepositoryInterface`, `EventDispatcherInterface`; remove `Connection`
    - Add new `index` action: `#[Route('/topics/{topicId}/posts', name: 'api_v1_topics_posts_index', methods: ['GET'], defaults: ['_allow_anonymous' => true])]`; fetch topic via `getTopic` (catch `\InvalidArgumentException` → 404); anonymous ACL fallback via `$userRepository->findById(1)`; ACL check `f_read`; `listPosts($topicId, $ctx)`; return paginated JSON
    - Refactor `create` action: replace inline SQL with `CreatePostRequest` → `$this->threadsService->createPost()`; fix `post_subject` (now handled by service as `'Re: ' . topic_title`); dispatch events; return 201 response
  - [x] 5.n Ensure all controller tests pass
    - Run ONLY the 14 tests written in steps 5.1 and 5.2
    - Do NOT run entire test suite

**Acceptance Criteria:**
- All 14 controller tests pass (8 topics + 6 posts)
- `TopicsController` and `PostsController` contain zero direct SQL, zero `Connection` injection, zero `beginTransaction`/`commit`/`rollBack` calls
- `POST /forums/{forumId}/topics` response `data` contains full `TopicDTO` fields (not partial `{id, title, forumId, authorId, firstPost}`)
- `GET /topics/{topicId}/posts` route is registered and returns 200 paginated response
- Anonymous fallback pattern (`$userRepository->findById(1)`) preserved for `indexByForum`, `show`, and `index` in `PostsController`

---

### Task Group 6: DI Registration — `services.yaml`
**Dependencies:** Groups 2, 3, 4
**Estimated Steps:** 4

- [x] 6.0 Complete DI wiring
  - [x] 6.1 Write 2 smoke tests verifying DI wiring is complete
    - Confirm `ThreadsServiceInterface` alias resolves to `ThreadsService` (check class existence and interface implementation)
    - Confirm both repository interfaces alias to their DBAL implementations
    - These can be simple PHP `instanceof` assertions in a minimal bootstrap test or verified by Symfony container dump
    - Run: `php bin/console debug:container phpbb\\threads --no-interaction` or equivalent
  - [x] 6.2 Add threads module section to `src/phpbb/config/services.yaml`
    - Add after the `# Hierarchy module` section (after line 143)
    - Register `DbalTopicRepository` with `$connection: '@Doctrine\DBAL\Connection'`
    - Register `TopicRepositoryInterface` as alias for `DbalTopicRepository`
    - Register `DbalPostRepository` with `$connection: '@Doctrine\DBAL\Connection'`
    - Register `PostRepositoryInterface` as alias for `DbalPostRepository`
    - Register `ThreadsService` with named arguments `$topicRepository`, `$postRepository`, `$connection`
    - Register `ThreadsServiceInterface` as alias for `ThreadsService` with `public: true`
    - Reuse: hierarchy section pattern at lines 105–143 of `services.yaml` as structural template
  - [x] 6.3 Verify container compiles without errors
    - Run: `php bin/console cache:clear --no-interaction` (or equivalent container validation)
    - Confirm no "cannot autowire" or "no service found" errors for threads module classes
  - [x] 6.n Ensure DI smoke tests pass
    - Run ONLY the 2 tests written in step 6.1

**Acceptance Criteria:**
- `services.yaml` compiles without errors
- `ThreadsServiceInterface` is `public: true` and resolvable by container
- Controllers auto-wire `ThreadsServiceInterface` via Symfony autowiring (no explicit controller entries needed)
- No controller registrations added — controllers already covered by wildcard resource at lines 7–9

---

### Task Group 7: Test Review and Gap Analysis
**Dependencies:** All previous groups (1–6)
**Estimated Steps:** 5

- [x] 7.0 Review and fill critical test gaps
  - [x] 7.1 Review all tests from previous groups
    - Count: 4 (group 1) + 7 (group 2) + 4 (group 3) + 8 (group 4) + 14 (group 5) + 2 (group 6) = 39 tests
    - Run full threads-module test suite: `vendor/bin/phpunit --filter "DbalTopicRepositoryTest|DbalPostRepositoryTest|ThreadsServiceTest|TopicsControllerTest|PostsControllerTest"`
  - [x] 7.2 Analyze gaps for the threads module only
    - Check: transaction rollback path tested in `ThreadsServiceTest`?
    - Check: `listPosts` topic-not-found path tested in `ThreadsServiceTest`?
    - Check: `createPost` with `actorColour` propagation tested?
    - Check: `findByForum` pagination OFFSET calculation tested (page > 1)?
    - Check: `updateLastPost` all 5 columns verified in `DbalTopicRepositoryTest`?
  - [x] 7.3 Write up to 8 additional strategic tests for identified gaps
    - Priority: rollback on DB failure, pagination offset correctness, `createPost` `actorColour` propagation to `updateLastPost`
    - Add to existing test files (no new files)
    - Use `#[Test]` attribute, AAA structure
  - [x] 7.4 Run complete threads module test suite including new tests
    - Expected total: ~39 existing + up to 8 new = 39–47 tests
    - Run: `vendor/bin/phpunit --filter "DbalTopicRepositoryTest|DbalPostRepositoryTest|ThreadsServiceTest|TopicsControllerTest|PostsControllerTest"`
  - [x] 7.n Verify success criteria from spec are all met
    - All 5 API routes respond correctly (3 existing + 1 changed + 1 new)
    - `POST /forums/{forumId}/topics` returns full `TopicDTO` (not partial shape)
    - `GET /topics/{topicId}/posts` returns 200 with `data` + `meta`
    - `post_subject` for reply is `'Re: ' . topic_title`
    - Both controllers contain zero direct SQL calls
    - `TopicsControllerTest` passes without mocking `Connection`
    - `src/phpbb/threads/` mirrors `src/phpbb/hierarchy/` directory structure exactly

**Acceptance Criteria:**
- All feature-specific tests pass (39–47 total)
- No more than 8 additional tests added in this group
- All 9 success criteria from spec verified

---

## Execution Order

1. Group 1: Foundation — Entities, DTOs, Contracts (9 steps, no dependencies)
2. Group 2: DbalTopicRepository (8 steps, depends on 1)
3. Group 3: DbalPostRepository (7 steps, depends on 1)
4. Group 4: ThreadsService + Events (9 steps, depends on 1+2+3)
5. Group 5: Controller Refactor (10 steps, depends on 1+4)
6. Group 6: DI Registration (4 steps, depends on 2+3+4)
7. Group 7: Test Review and Gap Analysis (5 steps, depends on all)

Groups 2 and 3 can be executed in parallel (both depend only on Group 1).

---

## Standards Compliance

Follow standards from `.maister/docs/standards/`:

- `global/STANDARDS.md` — `declare(strict_types=1)` on every file; `PascalCase` classes; `camelCase` methods; single-quote strings unless interpolation needed
- `backend/STANDARDS.md` — `final readonly` for all entities and DTOs; constructor property promotion with `readonly`; PSR-4 namespace mirrors directory; `private const TABLE` (not injected); `RepositoryException` wrapping all `\Doctrine\DBAL\Exception`; controllers contain no SQL, no business logic, no direct `Connection`
- `backend/REST_API.md` — `PaginationContext::fromQuery()` in every list action; standard JSON envelope shapes (`data`, `meta`, `error`, `status`)
- `backend/DOMAIN_EVENTS.md` — mutation methods return `DomainEventCollection`; controllers call `$events->dispatch($this->dispatcher)`
- `testing/STANDARDS.md` — `#[Test]` attribute (not `@test` docblock); `#[DataProvider]` for parameterized cases; AAA (Arrange, Act, Assert) structure in all test methods

File header (copyright/license block) required on every new PHP file — copy from any existing module file (e.g., `src/phpbb/hierarchy/Service/HierarchyService.php` lines 1–13).

---

## Notes

- **Test-Driven**: Each group starts with 2–8 focused tests before implementation
- **Run Incrementally**: Use `--filter ClassName` to run only new tests after each group; never run the full suite mid-implementation
- **Mark Progress**: Check off each `[ ]` step as completed; the checked state is the resume point if execution is interrupted
- **Reuse First**: `DomainEvent`, `DomainEventCollection`, `PaginationContext`, `PaginatedResult`, `RepositoryException`, `IntegrationTestCase` — all imported from existing modules; no reimplementation
- **Transaction Ownership**: `ThreadsService` owns all `beginTransaction`/`commit`/`rollBack` — repositories must NOT start transactions internally
- **No `composer.json` changes**: `phpbb\threads\` maps to `src/phpbb/threads/` via the existing PSR-4 `phpbb\` → `src/phpbb/` rule
- **Controller auto-wiring**: Controllers under `phpbb\api\Controller\` are already covered by the wildcard resource in `services.yaml` lines 7–9; no explicit controller entries needed in Group 6
- **Anonymous user fallback**: The pattern `$user ?? $this->userRepository->findById(self::ANONYMOUS_USER_ID)` must be preserved in all three list/show actions across both controllers

---

## OpenAPI Gap Milestones (Categorized Backlog)

Source comparison: OpenAPI spec vs implemented controller routes (snapshot from `outputs/openapi-missing-endpoints.txt`).

### Milestone A: Conversations and Messaging (18)

- [ ] Implement conversations and unread messaging API surface
Endpoints:
`delete /conversations/{conversationId}`
`delete /conversations/{conversationId}/archive`
`delete /conversations/{conversationId}/messages/{messageId}`
`delete /conversations/{conversationId}/mute`
`delete /conversations/{conversationId}/participants/{userId}`
`delete /conversations/{conversationId}/pin`
`get /conversations`
`get /conversations/{conversationId}`
`get /conversations/{conversationId}/messages`
`get /messaging/unread`
`patch /conversations/{conversationId}/messages/{messageId}`
`post /conversations`
`post /conversations/{conversationId}/archive`
`post /conversations/{conversationId}/messages`
`post /conversations/{conversationId}/mute`
`post /conversations/{conversationId}/participants`
`post /conversations/{conversationId}/pin`
`post /conversations/{conversationId}/read`

### Milestone B: User Management and Moderation (14)

- [ ] Implement user lifecycle, typing, and user-ban endpoints
Endpoints:
`delete /users/{userId}/bans/{banId}`
`get /users/check-email`
`get /users/check-username`
`get /users/{userId}/bans`
`get /users/{userId}/type`
`post /users`
`post /users/{userId}/bans`
`post /users/{userId}/delete`
`post /users/{userId}/type`
`delete /bans/{banId}`
`get /bans`
`get /bans/{banId}`
`patch /bans/{banId}`
`post /bans`

### Milestone C: Groups and Permissions (13)

- [ ] Implement groups CRUD and forum permission assignment endpoints
Endpoints:
`delete /groups/{groupId}`
`delete /groups/{groupId}/members/{userId}`
`get /groups`
`get /groups/{groupId}`
`get /groups/{groupId}/members`
`patch /groups/{groupId}`
`post /groups`
`post /groups/{groupId}/members`
`delete /forums/{forumId}/permissions/{groupId}`
`get /forums/{forumId}/permissions/{groupId}`
`post /forums/{forumId}/permissions/{groupId}`
`patch /forums/{forumId}`
`post /forums/{forumId}/move`

### Milestone D: Topic/Post Advanced Moderation (13)

- [ ] Implement advanced topic/post moderation and lifecycle endpoints
Endpoints:
`delete /topics/{topicId}`
`patch /topics/{topicId}`
`post /topics/{topicId}/approve`
`post /topics/{topicId}/merge`
`post /topics/{topicId}/move`
`post /topics/{topicId}/restore`
`post /topics/{topicId}/split`
`delete /posts/{postId}`
`get /posts/{postId}`
`patch /posts/{postId}`
`post /posts/{postId}/approve`
`post /posts/{postId}/report`
`post /posts/{postId}/restore`

### Milestone E: Drafts, Files, and Notifications (13)

- [ ] Implement drafts, storage, and notifications endpoints
Endpoints:
`delete /drafts/{draftId}`
`get /drafts`
`get /drafts/{draftId}`
`patch /drafts/{draftId}`
`post /drafts`
`delete /files/{fileId}`
`get /files/{fileId}`
`get /files/{fileId}/download`
`post /files`
`get /notifications`
`get /notifications/count`
`post /notifications/read-all`
`post /notifications/{notificationId}/read`

### Milestone F: Auth, Profile, Config, Search (11)

- [ ] Implement remaining auth/profile/system endpoints
Endpoints:
`get /auth/sso/{provider}/authorize`
`post /auth/logout-all`
`post /auth/sso/{provider}/callback`
`patch /me`
`put /me/password`
`get /config`
`post /config`
`post /password-reset`
`post /password-reset/confirm`
`get /search`
`post /search/rebuild`

### Milestone G: Delivery and Verification Gate

- [ ] Deliver each milestone with service/repository/controller split, unit+integration coverage, and OpenAPI conformance checks
Acceptance criteria:
`composer test` passes
`composer test:e2e` passes
`composer cs:fix` passes
No route mismatch against OpenAPI for delivered category
