# Specification: Threads Service Module (`phpbb\threads\`)

## Goal

Extract all topic and post business logic from `TopicsController` and `PostsController` into a new `phpbb\threads\` module following the `phpbb\hierarchy\` architecture exactly, adding a proper service layer, repositories, entities, DTOs, and domain events while preserving all existing route URLs and response shapes.

## User Stories

- As a forum reader, I want to list topics in a forum with pagination so that I can browse threads without loading everything at once.
- As a forum reader, I want to view a single topic's details so that I can decide whether to read its posts.
- As a forum reader, I want to list posts in a topic with pagination so that I can read the full discussion.
- As a forum poster, I want to create a new topic so that I can start a discussion; I expect the full topic data back in the response.
- As a forum poster, I want to reply to an existing topic so that I can contribute to an ongoing discussion.

## Core Requirements

1. Create `src/phpbb/threads/` module with contracts, entities, DTOs, repository, service, and event classes.
2. `DbalTopicRepository` covers `phpbb_topics`: `findById`, `findByForum` (paginated), `insert`, `updateFirstLastPost`, `updateLastPost`.
3. `DbalPostRepository` covers `phpbb_posts`: `findById`, `findByTopic` (paginated), `insert`.
4. `ThreadsService` injects `TopicRepositoryInterface`, `PostRepositoryInterface`, and `Connection`; owns all cross-table transactions via `beginTransaction` / `commit` / `rollBack`.
5. `ThreadsServiceInterface` is a single interface covering all topic and post methods.
6. `Topic` and `Post` entities are `final readonly` with typed constructor properties mirroring `phpbb_topics` and `phpbb_posts` columns exactly.
7. `TopicDTO` and `PostDTO` are `final readonly` with static `fromEntity()` factories; response field shapes must be identical to what the controllers currently return.
8. `TopicCreatedEvent` and `PostCreatedEvent` extend `phpbb\common\Event\DomainEvent`.
9. Mutation methods in `ThreadsService` return `DomainEventCollection`; query methods return DTOs or `PaginatedResult<TopicDTO|PostDTO>`.
10. `TopicsController` is refactored to inject `ThreadsServiceInterface`, `EventDispatcherInterface`, and drop `Connection`; all SQL moves to the service/repository layer.
11. `PostsController` is refactored identically: inject `ThreadsServiceInterface` and `EventDispatcherInterface`, drop `Connection`.
12. `GET /topics/{topicId}/posts` is a new endpoint implemented in `PostsController`.
13. `POST /forums/{forumId}/topics` must return full `TopicDTO` in the `data` field (current behavior: partial inline array).
14. `post_subject` for reply posts must be `'Re: ' . topic_title` (fixes the hardcoded `'Re: post'` in current code).
15. DI registration for all new classes in `services.yaml`.
16. Full test coverage: `DbalTopicRepositoryTest`, `DbalPostRepositoryTest`, `ThreadsServiceTest`, refactored `TopicsControllerTest`, new `PostsControllerTest`.

## Visual Design

No visual assets. This is a pure backend service module with no UI changes.

## Reusable Components

### Existing Code to Leverage

| Component | File | How to leverage |
|-----------|------|----------------|
| `DomainEvent` base class | `src/phpbb/common/Event/DomainEvent.php` | Extend for `TopicCreatedEvent`, `PostCreatedEvent` |
| `DomainEventCollection` | `src/phpbb/common/Event/DomainEventCollection.php` | Return from all mutation methods in `ThreadsService` |
| `PaginationContext` | `src/phpbb/api/DTO/PaginationContext.php` | Pass from controllers to service for all list methods; use `PaginationContext::fromQuery($request->query)` |
| `PaginatedResult<T>` | `src/phpbb/user/DTO/PaginatedResult.php` | Return from `findByForum` and `findByTopic` repository methods; wrap in service list methods |
| `RepositoryException` | `src/phpbb/db/Exception/RepositoryException.php` | Wrap all `\Doctrine\DBAL\Exception` in both repositories |
| `IntegrationTestCase` | `tests/phpbb/Integration/IntegrationTestCase.php` | Extend for `DbalTopicRepositoryTest`, `DbalPostRepositoryTest`, `ThreadsServiceTest` (SQLite in-memory) |
| Hierarchy entity pattern | `src/phpbb/hierarchy/Entity/Forum.php` | `final readonly` with typed constructor properties; copy structure for `Topic`, `Post` |
| Hierarchy DTO pattern | `src/phpbb/hierarchy/DTO/ForumDTO.php` | `final readonly` with `fromEntity()` factory; copy structure for `TopicDTO`, `PostDTO` |
| Hierarchy repository pattern | `src/phpbb/hierarchy/Repository/DbalForumRepository.php` | `private const TABLE`, `executeQuery`/`executeStatement`, `hydrate()`, `RepositoryException` wrapping |
| Hierarchy service pattern | `src/phpbb/hierarchy/Service/HierarchyService.php` | `final`, injected interfaces, `DomainEventCollection` returns, `InvalidArgumentException` for not-found |
| Hierarchy event pattern | `src/phpbb/hierarchy/Event/ForumCreatedEvent.php` | `final readonly` empty class extending `DomainEvent`; copy for `TopicCreatedEvent`, `PostCreatedEvent` |
| services.yaml DI pattern | `src/phpbb/config/services.yaml` (hierarchy section, lines 105–143) | Repository + alias + service + alias pattern |
| `HierarchyServiceTest` pattern | `tests/phpbb/hierarchy/Service/HierarchyServiceTest.php` | In-memory SQLite schema setup in `setUpSchema()`, helper `insert*()` methods, `#[Test]` attribute |
| `TopicsControllerTest` (existing) | `tests/phpbb/api/Controller/TopicsControllerTest.php` | Rewrite to mock `ThreadsServiceInterface` instead of `Connection` |

### New Components Required

The entire `src/phpbb/threads/` module is new — no existing code covers topics/posts at the service layer. The justification is the central purpose of this task: extract from controller into a properly layered module.

The `GET /topics/{topicId}/posts` endpoint does not exist in any controller today; `PostsController` must grow a new `index` action method.

`CreateTopicRequest` and `CreatePostRequest` DTOs are new; no equivalent request DTO exists in the codebase for topics or posts (the current code unpacks `json_decode` inline in the controller).

`ThreadsService` requires `Connection` injection (in addition to the two repository interfaces) to own the cross-table transaction for `createTopic`. This slightly deviates from `HierarchyService` (which has no `Connection`), but is justified by the mandatory atomicity of inserting both a `phpbb_topics` row and a `phpbb_posts` row in a single transaction.

## Technical Approach

### Module Directory Structure

```
src/phpbb/threads/
  Contract/
    ThreadsServiceInterface.php
    TopicRepositoryInterface.php
    PostRepositoryInterface.php
  DTO/
    TopicDTO.php
    PostDTO.php
    CreateTopicRequest.php
    CreatePostRequest.php
  Entity/
    Topic.php
    Post.php
  Event/
    TopicCreatedEvent.php
    PostCreatedEvent.php
  Repository/
    DbalTopicRepository.php
    DbalPostRepository.php
  Service/
    ThreadsService.php

tests/phpbb/threads/
  Repository/
    DbalTopicRepositoryTest.php
    DbalPostRepositoryTest.php
  Service/
    ThreadsServiceTest.php
```

Refactored (no new files created, existing files modified):
- `src/phpbb/api/Controller/TopicsController.php`
- `src/phpbb/api/Controller/PostsController.php`
- `src/phpbb/config/services.yaml`
- `tests/phpbb/api/Controller/TopicsControllerTest.php`

New test file:
- `tests/phpbb/api/Controller/PostsControllerTest.php`

### Data Flow

**Read path**: Controller builds `PaginationContext` from request → calls `ThreadsServiceInterface` method → service calls repository → repository executes SQL → returns `PaginatedResult<TopicDTO>` or single DTO → controller wraps in `JsonResponse`.

**Write path**: Controller parses and validates request body → builds `CreateTopicRequest` or `CreatePostRequest` → calls mutation method → service wraps in transaction, calls repositories, returns `DomainEventCollection` → controller calls `$events->dispatch($this->dispatcher)` → returns `JsonResponse` with full DTO.

**ACL**: All authorization checks (`isGranted`) remain in the controller layer and are not delegated to the service.

**Anonymous fallback**: Controllers that allow anonymous access continue to use `$userRepository->findById(1)` when `_api_user` is null, same as today.

### Transaction Design

`ThreadsService::createTopic()` executes:
1. `$this->connection->beginTransaction()`
2. `$this->topicRepository->insert($request)` — returns `$topicId`
3. `$this->postRepository->insert($postRequest)` — returns `$postId`
4. `$this->topicRepository->updateFirstLastPost($topicId, $postId)` — sets `topic_first_post_id` and `topic_last_post_id`
5. `$this->connection->commit()`
6. On any `\Throwable`: `$this->connection->rollBack()`, re-throw as `\RuntimeException`

`ThreadsService::createPost()` executes:
1. `$this->connection->beginTransaction()`
2. `$this->topicRepository->findById($topicId)` — returns `Topic` or throws `\InvalidArgumentException`
3. `$this->postRepository->insert($request)` — returns `$postId`
4. `$this->topicRepository->updateLastPost($topicId, $postId, $userId, $username, $userColour, $now)` — denormalization
5. `$this->connection->commit()`
6. On any `\Throwable`: `$this->connection->rollBack()`, re-throw

### Namespace and Autoloading

`phpbb\threads\` maps to `src/phpbb/threads/` via the existing PSR-4 `phpbb\` → `src/phpbb/` autoload rule in `composer.json`. No changes to `composer.json` are required.

---

## Class Specifications

### `phpbb\threads\Entity\Topic`

`final readonly` class. Maps `phpbb_topics` columns needed by the API. All properties typed.

Constructor properties:
- `int $id` — `topic_id`
- `int $forumId` — `forum_id`
- `string $title` — `topic_title`
- `int $posterId` — `topic_poster`
- `int $time` — `topic_time` (Unix timestamp)
- `int $postsApproved` — `topic_posts_approved`
- `int $lastPostTime` — `topic_last_post_time`
- `string $lastPosterName` — `topic_last_poster_name`
- `int $lastPosterId` — `topic_last_poster_id`
- `string $lastPosterColour` — `topic_last_poster_colour`
- `int $firstPostId` — `topic_first_post_id`
- `int $lastPostId` — `topic_last_post_id`
- `int $visibility` — `topic_visibility`
- `string $firstPosterName` — `topic_first_poster_name`
- `string $firstPosterColour` — `topic_first_poster_colour`

No methods beyond constructor.

---

### `phpbb\threads\Entity\Post`

`final readonly` class. Maps `phpbb_posts` columns.

Constructor properties:
- `int $id` — `post_id`
- `int $topicId` — `topic_id`
- `int $forumId` — `forum_id`
- `int $posterId` — `poster_id`
- `int $time` — `post_time` (Unix timestamp)
- `string $text` — `post_text`
- `string $subject` — `post_subject`
- `string $username` — `post_username`
- `string $posterIp` — `poster_ip`
- `int $visibility` — `post_visibility`

No methods beyond constructor.

---

### `phpbb\threads\DTO\TopicDTO`

`final readonly` class with static `fromEntity(Topic $topic): self` factory.

Public properties (API response fields):
- `int $id`
- `string $title`
- `int $forumId`
- `int $authorId`
- `int $postCount`
- `string $lastPosterName`
- `int|string $lastPostTime` — preserved as-is from `topic_last_post_time`
- `int|string $createdAt` — preserved as-is from `topic_time`

`fromEntity` maps: `id` ← `$topic->id`, `title` ← `$topic->title`, `forumId` ← `$topic->forumId`, `authorId` ← `$topic->posterId`, `postCount` ← `$topic->postsApproved`, `lastPosterName` ← `$topic->lastPosterName`, `lastPostTime` ← `$topic->lastPostTime`, `createdAt` ← `$topic->time`.

This exactly preserves the field names and values produced by the existing `topicRowToArray()` private method in `TopicsController`.

---

### `phpbb\threads\DTO\PostDTO`

`final readonly` class with static `fromEntity(Post $post): self` factory.

Public properties:
- `int $id`
- `int $topicId`
- `int $forumId`
- `int $authorId`
- `string $content`

`fromEntity` maps: `id` ← `$post->id`, `topicId` ← `$post->topicId`, `forumId` ← `$post->forumId`, `authorId` ← `$post->posterId`, `content` ← `$post->text`.

---

### `phpbb\threads\DTO\CreateTopicRequest`

`final readonly` class. Carries validated data from controller to service for topic creation.

Constructor properties:
- `int $forumId`
- `string $title`
- `string $content`
- `int $actorId`
- `string $actorUsername`
- `string $actorColour`
- `string $posterIp`

---

### `phpbb\threads\DTO\CreatePostRequest`

`final readonly` class. Carries validated data from controller to service for reply post creation.

Constructor properties:
- `int $topicId`
- `string $content`
- `int $actorId`
- `string $actorUsername`
- `string $actorColour`
- `string $posterIp`

---

### `phpbb\threads\Contract\TopicRepositoryInterface`

```php
public function findById(int $topicId): ?Topic;

/** @return PaginatedResult<TopicDTO> */
public function findByForum(int $forumId, PaginationContext $ctx): PaginatedResult;

public function insert(CreateTopicRequest $request, int $now): int; // returns topic_id

public function updateFirstLastPost(int $topicId, int $postId): void;

public function updateLastPost(
    int $topicId,
    int $postId,
    int $posterId,
    string $posterName,
    string $posterColour,
    int $now,
): void;
```

All methods throw `RepositoryException` on DBAL failure.

---

### `phpbb\threads\Contract\PostRepositoryInterface`

```php
public function findById(int $postId): ?Post;

/** @return PaginatedResult<PostDTO> */
public function findByTopic(int $topicId, PaginationContext $ctx): PaginatedResult;

public function insert(
    int $topicId,
    int $forumId,
    int $posterId,
    string $posterUsername,
    string $posterIp,
    string $content,
    string $subject,
    int $now,
    int $visibility,
): int; // returns post_id
```

All methods throw `RepositoryException` on DBAL failure.

---

### `phpbb\threads\Contract\ThreadsServiceInterface`

```php
public function getTopic(int $topicId): TopicDTO; // throws \InvalidArgumentException if not found or not visible

/** @return PaginatedResult<TopicDTO> */
public function listTopics(int $forumId, PaginationContext $ctx): PaginatedResult;

public function createTopic(CreateTopicRequest $request): DomainEventCollection;

/** @return PaginatedResult<PostDTO> */
public function listPosts(int $topicId, PaginationContext $ctx): PaginatedResult;

public function createPost(CreatePostRequest $request): DomainEventCollection;
```

---

### `phpbb\threads\Repository\DbalTopicRepository`

Implements `TopicRepositoryInterface`. `private const TABLE = 'phpbb_topics'`.

Constructor: `(private readonly \Doctrine\DBAL\Connection $connection)`.

**`findById(int $topicId): ?Topic`**
```sql
SELECT topic_id, forum_id, topic_title, topic_poster, topic_time,
       topic_posts_approved, topic_last_post_time, topic_last_poster_name,
       topic_last_poster_id, topic_last_poster_colour,
       topic_first_post_id, topic_last_post_id, topic_visibility,
       topic_first_poster_name, topic_first_poster_colour
FROM phpbb_topics
WHERE topic_id = :id
LIMIT 1
```
Returns `null` when no row. Wraps `\Doctrine\DBAL\Exception` in `RepositoryException`.

**`findByForum(int $forumId, PaginationContext $ctx): PaginatedResult`**

Two queries:
1. `SELECT COUNT(*) FROM phpbb_topics WHERE forum_id = :forumId AND topic_visibility = 1` — total count
2. `SELECT [columns] FROM phpbb_topics WHERE forum_id = :forumId AND topic_visibility = 1 ORDER BY topic_last_post_time DESC LIMIT :limit OFFSET :offset`

Columns selected are identical to `findById` — all columns including `topic_first_poster_colour` must be present because the shared `hydrate()` method requires every entity property. `LIMIT` = `$ctx->perPage`, `OFFSET` = `($ctx->page - 1) * $ctx->perPage`. Returns `new PaginatedResult(items: $dtos, total: $count, page: $ctx->page, perPage: $ctx->perPage)` where each item is `TopicDTO::fromEntity($this->hydrate($row))`.

**`insert(CreateTopicRequest $request, int $now): int`**
```sql
INSERT INTO phpbb_topics
    (forum_id, topic_title, topic_poster, topic_time,
     topic_first_poster_name, topic_first_poster_colour,
     topic_last_poster_id, topic_last_poster_name, topic_last_poster_colour,
     topic_last_post_subject, topic_last_post_time, topic_visibility)
VALUES
    (:forumId, :title, :posterId, :now,
     :firstPosterName, :firstPosterColour,
     :posterId, :posterName, :posterColour,
     :title, :now, 1)
```
Returns `(int) $this->connection->lastInsertId()`.

**`updateFirstLastPost(int $topicId, int $postId): void`**
```sql
UPDATE phpbb_topics
SET topic_first_post_id = :postId, topic_last_post_id = :postId
WHERE topic_id = :topicId
```

**`updateLastPost(int $topicId, int $postId, int $posterId, string $posterName, string $posterColour, int $now): void`**
```sql
UPDATE phpbb_topics
SET topic_last_post_id      = :postId,
    topic_last_poster_id    = :posterId,
    topic_last_poster_name  = :posterName,
    topic_last_poster_colour = :posterColour,
    topic_last_post_time    = :now
WHERE topic_id = :topicId
```

**`hydrate(array $row): Topic`** — private method; casts all values to correct types.

---

### `phpbb\threads\Repository\DbalPostRepository`

Implements `PostRepositoryInterface`. `private const TABLE = 'phpbb_posts'`.

Constructor: `(private readonly \Doctrine\DBAL\Connection $connection)`.

**`findById(int $postId): ?Post`**
```sql
SELECT post_id, topic_id, forum_id, poster_id, post_time,
       post_text, post_subject, post_username, poster_ip, post_visibility
FROM phpbb_posts
WHERE post_id = :id
LIMIT 1
```

**`findByTopic(int $topicId, PaginationContext $ctx): PaginatedResult`**

Two queries:
1. `SELECT COUNT(*) FROM phpbb_posts WHERE topic_id = :topicId AND post_visibility = 1`
2. `SELECT [columns] FROM phpbb_posts WHERE topic_id = :topicId AND post_visibility = 1 ORDER BY post_time ASC LIMIT :limit OFFSET :offset`

Returns `PaginatedResult` with items as `PostDTO::fromEntity($this->hydrate($row))`.

**`insert(...): int`**
```sql
INSERT INTO phpbb_posts
    (topic_id, forum_id, poster_id, post_time, post_text, post_subject,
     post_username, poster_ip, post_visibility)
VALUES
    (:topicId, :forumId, :posterId, :now, :content, :subject,
     :username, :posterIp, :visibility)
```
Returns `(int) $this->connection->lastInsertId()`.

**`hydrate(array $row): Post`** — private.

---

### `phpbb\threads\Service\ThreadsService`

`final class ThreadsService implements ThreadsServiceInterface`.

Constructor:
```
(
    private readonly TopicRepositoryInterface $topicRepository,
    private readonly PostRepositoryInterface $postRepository,
    private readonly \Doctrine\DBAL\Connection $connection,
)
```

**`getTopic(int $topicId): TopicDTO`**
- Calls `$this->topicRepository->findById($topicId)`
- If null or `$topic->visibility !== 1`: throw `new \InvalidArgumentException("Topic {$topicId} not found")`
- Returns `TopicDTO::fromEntity($topic)`

**`listTopics(int $forumId, PaginationContext $ctx): PaginatedResult`**
- Delegates to `$this->topicRepository->findByForum($forumId, $ctx)`
- Returns the `PaginatedResult` directly (repository already wraps in DTOs)

**`createTopic(CreateTopicRequest $request): DomainEventCollection`**
- `$now = time()`
- `$this->connection->beginTransaction()`
- try:
  - `$topicId = $this->topicRepository->insert($request, $now)`
  - `$postId = $this->postRepository->insert(topicId: $topicId, forumId: $request->forumId, posterId: $request->actorId, posterUsername: $request->actorUsername, posterIp: $request->posterIp, content: $request->content, subject: $request->title, now: $now, visibility: 1)`
  - `$this->topicRepository->updateFirstLastPost($topicId, $postId)`
  - `$this->connection->commit()`
- catch `\Throwable $e`:
  - `$this->connection->rollBack()`
  - throw `new \RuntimeException('Failed to create topic', previous: $e)`
- Returns `new DomainEventCollection([new TopicCreatedEvent(entityId: $topicId, actorId: $request->actorId)])` — always contains exactly one event; `first()` is guaranteed non-null.

**`listPosts(int $topicId, PaginationContext $ctx): PaginatedResult`**
- Verifies topic exists via `$this->topicRepository->findById($topicId)`; if null throw `\InvalidArgumentException`
- Does NOT re-check `topic_visibility`; the caller (`PostsController::index`) is responsible for calling `getTopic()` first to enforce the visibility gate.
- Delegates to `$this->postRepository->findByTopic($topicId, $ctx)`

**`createPost(CreatePostRequest $request): DomainEventCollection`**
- `$now = time()`
- `$topic = $this->topicRepository->findById($request->topicId)`; if null throw `\InvalidArgumentException("Topic {$request->topicId} not found")`
- `$subject = 'Re: ' . $topic->title`
- `$this->connection->beginTransaction()`
- try:
  - `$postId = $this->postRepository->insert(topicId: $request->topicId, forumId: $topic->forumId, posterId: $request->actorId, posterUsername: $request->actorUsername, posterIp: $request->posterIp, content: $request->content, subject: $subject, now: $now, visibility: 1)`
  - `$this->topicRepository->updateLastPost($request->topicId, $postId, $request->actorId, $request->actorUsername, $request->actorColour, $now)`
  - `$this->connection->commit()`
- catch `\Throwable $e`:
  - `$this->connection->rollBack()`
  - throw `new \RuntimeException('Failed to create post', previous: $e)`
- Returns `new DomainEventCollection([new PostCreatedEvent(entityId: $postId, actorId: $request->actorId)])` — always contains exactly one event; `first()` is guaranteed non-null.

---

### `phpbb\threads\Event\TopicCreatedEvent`

`final readonly class TopicCreatedEvent extends DomainEvent {}` — no additional properties. Uses base `entityId` (topicId) and `actorId`.

---

### `phpbb\threads\Event\PostCreatedEvent`

`final readonly class PostCreatedEvent extends DomainEvent {}` — no additional properties. Uses base `entityId` (postId) and `actorId`.

---

### Refactored `phpbb\api\Controller\TopicsController`

New constructor signature:
```
(
    private readonly ThreadsServiceInterface $threadsService,
    private readonly AuthorizationServiceInterface $authorizationService,
    private readonly UserRepositoryInterface $userRepository,
    private readonly EventDispatcherInterface $dispatcher,
)
```

Route attributes and names are unchanged.

**`indexByForum(int $forumId, Request $request): JsonResponse`**
1. Resolve `$checker` (user or anonymous fallback)
2. ACL check `f_read` on `$forumId` → 403 if denied
3. `$ctx = PaginationContext::fromQuery($request->query)`
4. `$result = $this->threadsService->listTopics($forumId, $ctx)` — `PaginatedResult<TopicDTO>`
5. Return `JsonResponse` with shape:
   ```json
   {
     "data": [ ...topics as associative arrays... ],
     "meta": { "total": N, "page": P, "perPage": PP, "lastPage": L }
   }
   ```
   `lastPage` = `max(1, $result->totalPages())` — preserves existing behavior where empty forums return `lastPage: 1`. `data` items are spread as associative arrays from `TopicDTO` properties.

**`show(int $topicId, Request $request): JsonResponse`**
1. try `$dto = $this->threadsService->getTopic($topicId)` → catch `\InvalidArgumentException` → 404
2. ACL check `f_read` on `$dto->forumId` → 403 if denied
3. Return `JsonResponse(['data' => [...$dto properties...]])`

**`create(int $forumId, Request $request): JsonResponse`**
1. Auth check: 401 if no `_api_user`
2. ACL check `f_post` on `$forumId` → 403 if denied
3. Parse body; validate `$title !== ''` → 400 if empty
4. Build `$createRequest = new CreateTopicRequest(...)` from validated data and `$user` properties
5. try `$events = $this->threadsService->createTopic($createRequest)` → catch `\RuntimeException` → 500
6. `$events->dispatch($this->dispatcher)`
7. `$topicId = $events->first()->entityId`
8. `$dto = $this->threadsService->getTopic($topicId)`
9. Return `JsonResponse(['data' => [...$dto properties...]], 201)`

The `topicRowToArray()` private method is deleted; serialization uses `TopicDTO` properties.

---

### Refactored `phpbb\api\Controller\PostsController`

New constructor signature:
```
(
    private readonly ThreadsServiceInterface $threadsService,
    private readonly AuthorizationServiceInterface $authorizationService,
    private readonly EventDispatcherInterface $dispatcher,
)
```

**New: `index(int $topicId, Request $request): JsonResponse`** (GET /topics/{topicId}/posts)

Route: `#[Route('/topics/{topicId}/posts', name: 'api_v1_topics_posts_index', methods: ['GET'], defaults: ['_allow_anonymous' => true])]`

1. try `$topic = $this->threadsService->getTopic($topicId)` → catch `\InvalidArgumentException` → 404
2. ACL check `f_read` on `$topic->forumId` → 403 if denied (requires `UserRepositoryInterface` injection for anonymous fallback — add to constructor)
3. `$ctx = PaginationContext::fromQuery($request->query)`
4. `$result = $this->threadsService->listPosts($topicId, $ctx)`
5. Return paginated `JsonResponse` with `data` array of post fields and `meta` block.

Note: `PostsController` must also inject `UserRepositoryInterface` for the anonymous fallback on the new `index` method.

Updated constructor:
```
(
    private readonly ThreadsServiceInterface $threadsService,
    private readonly AuthorizationServiceInterface $authorizationService,
    private readonly UserRepositoryInterface $userRepository,
    private readonly EventDispatcherInterface $dispatcher,
)
```

**Refactored: `create(int $topicId, Request $request): JsonResponse`** (POST /topics/{topicId}/posts)

Route name and URL unchanged: `api_v1_topics_posts_create`.

1. `$user = $request->attributes->get('_api_user')` — Auth check: 401 if `$user === null`
2. try `$topic = $this->threadsService->getTopic($topicId)` → catch `\InvalidArgumentException` → 404
3. ACL check `f_reply` on `$topic->forumId` → 403 if denied
4. Parse body; `$content = trim(...)` from decoded JSON; validate `$content !== ''` → 400 if empty
5. Build `$postRequest = new CreatePostRequest(...)` from `$topic`, `$user`, `$content`, `$request->server->get('REMOTE_ADDR', '')`
6. try `$events = $this->threadsService->createPost($postRequest)` → catch `\RuntimeException` → 500
7. `$events->dispatch($this->dispatcher)`
8. `$postId = $events->first()->entityId`
9. Return `JsonResponse(['data' => ['id' => $postId, 'topicId' => $topicId, 'forumId' => $topic->forumId, 'authorId' => $user->id, 'content' => $content]], 201)`

Response shape for `create` is identical to current `PostsController::create` output.

---

## API Endpoint Specifications

### GET /forums/{forumId}/topics

- Route name: `api_v1_forums_topics_index` (unchanged)
- Auth: optional (anonymous allowed via `_allow_anonymous`)
- ACL: `f_read` on `forumId`
- Query params: `page` (int, default 1), `perPage` (int, default 25, max 100)
- Response 200:
  ```json
  {
    "data": [
      { "id": 1, "title": "...", "forumId": 5, "authorId": 12,
        "postCount": 3, "lastPosterName": "alice",
        "lastPostTime": 1700001000, "createdAt": 1700000000 }
    ],
    "meta": { "total": 42, "page": 1, "perPage": 25, "lastPage": 2 }
  }
  ```
- Response 403: `{"error": "Forbidden", "status": 403}`

### GET /topics/{topicId}

- Route name: `api_v1_topics_show` (unchanged)
- Auth: optional
- ACL: `f_read` on the topic's forum
- Response 200: `{"data": { ...topic fields... }}`
- Response 404: `{"error": "Topic not found", "status": 404}`
- Response 403: `{"error": "Forbidden", "status": 403}`

### POST /forums/{forumId}/topics

- Route name: `api_v1_forums_topics_create` (unchanged)
- Auth: required
- ACL: `f_post` on `forumId`
- Request body: `{"title": "...", "content": "..."}`
- Response 201: `{"data": { ...full TopicDTO fields... }}` — **changed from partial inline array to full TopicDTO**
- Response 400: `{"error": "Title is required", "status": 400}`
- Response 401: `{"error": "Authentication required", "status": 401}`
- Response 403: `{"error": "Forbidden", "status": 403}`
- Response 500: `{"error": "Internal server error", "status": 500}`

### GET /topics/{topicId}/posts (new)

- Route name: `api_v1_topics_posts_index` (new)
- Auth: optional
- ACL: `f_read` on the topic's forum
- Query params: `page`, `perPage`
- Response 200:
  ```json
  {
    "data": [
      { "id": 7, "topicId": 3, "forumId": 5, "authorId": 12, "content": "..." }
    ],
    "meta": { "total": 10, "page": 1, "perPage": 25, "lastPage": 1 }
  }
  ```
- Response 404: `{"error": "Topic not found", "status": 404}`
- Response 403: `{"error": "Forbidden", "status": 403}`

### POST /topics/{topicId}/posts

- Route name: `api_v1_topics_posts_create` (unchanged)
- Auth: required
- ACL: `f_reply` on the topic's forum
- Request body: `{"content": "..."}`
- Response 201: `{"data": {"id": 8, "topicId": 3, "forumId": 5, "authorId": 12, "content": "..."}}`
- Response 400: `{"error": "Content is required", "status": 400}`
- Response 401: `{"error": "Authentication required", "status": 401}`
- Response 403: `{"error": "Forbidden", "status": 403}`
- Response 404: `{"error": "Topic not found", "status": 404}`
- Response 500: `{"error": "Internal server error", "status": 500}`

---

## DI Registration Requirements

Add to `src/phpbb/config/services.yaml` after the hierarchy module section:

```yaml
# ---------------------------------------------------------------------------
# Threads module
# ---------------------------------------------------------------------------

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
        $connection:      '@Doctrine\DBAL\Connection'

phpbb\threads\Contract\ThreadsServiceInterface:
    alias: phpbb\threads\Service\ThreadsService
    public: true
```

Controllers under `phpbb\api\Controller\` are already auto-wired via the wildcard resource at line 7–9 of `services.yaml`. No explicit controller registrations are needed; Symfony autowiring resolves the new constructor signatures automatically once the interface aliases above are registered.

`EventDispatcherInterface` is provided by the Symfony framework and is auto-wired by default.

---

## Implementation Guidance

### Testing Approach

Each test group should have 2–8 focused tests. Do not run the full test suite between steps; run only newly added tests with `vendor/bin/phpunit --filter <ClassName>`.

**Group 1 — Entities and DTOs** (unit tests, no DB):
- `TopicDTO::fromEntity` maps all fields correctly
- `PostDTO::fromEntity` maps all fields correctly
- `Topic` constructor accepts expected column values
- `Post` constructor accepts expected column values

**Group 2 — `DbalTopicRepositoryTest`** (integration, SQLite in-memory, extends `IntegrationTestCase`):
- `findById` returns `null` for unknown ID
- `findById` returns hydrated `Topic` for existing row
- `insert` persists row and returns auto-increment ID
- `findByForum` returns paginated result with correct `total` count
- `findByForum` respects `topic_visibility = 1` filter
- `updateFirstLastPost` writes correct post IDs to topic row
- `updateLastPost` writes correct denormalization columns

**Group 3 — `DbalPostRepositoryTest`** (integration, SQLite in-memory):
- `findById` returns `null` for unknown ID
- `insert` persists row and returns auto-increment ID
- `findByTopic` returns paginated result filtered by `post_visibility = 1`
- `findByTopic` orders by `post_time ASC`

**Group 4 — `ThreadsServiceTest`** (integration, SQLite in-memory, exercises real repositories):
- `getTopic` throws `\InvalidArgumentException` for unknown topic ID
- `getTopic` throws `\InvalidArgumentException` for topic with `visibility != 1`
- `listTopics` returns `PaginatedResult` with correct item count
- `createTopic` inserts topic and first post atomically; returns `DomainEventCollection` with `TopicCreatedEvent`
- `createTopic` returns event with correct `actorId`
- `createPost` throws `\InvalidArgumentException` for unknown topic
- `createPost` inserts post with `subject = 'Re: ' . topic_title`
- `createPost` returns `DomainEventCollection` with `PostCreatedEvent`

**Group 5 — Refactored `TopicsControllerTest`** (unit test, mocks `ThreadsServiceInterface`):
- `indexByForum` returns 200 with `data` + `meta` envelope
- `indexByForum` returns 403 when ACL denied
- `show` returns 200 with `data` envelope
- `show` returns 404 for unknown topic
- `show` returns 403 when ACL denied
- `create` returns 201 with full topic DTO
- `create` returns 401 without authenticated user
- `create` returns 400 with empty title

**Group 6 — New `PostsControllerTest`** (unit test, mocks `ThreadsServiceInterface`):
- `index` returns 200 with `data` + `meta` envelope
- `index` returns 404 for unknown topic
- `create` returns 201 with post data envelope
- `create` returns 401 without authenticated user
- `create` returns 400 with empty content
- `create` returns 404 for unknown topic

### Standards Compliance

- **`declare(strict_types=1)`** on every file — `standards/global/STANDARDS.md`
- **`final readonly`** for all entity and DTO classes — `standards/backend/STANDARDS.md` (Readonly Properties section)
- **Constructor property promotion** with `readonly` for injected services — `standards/backend/STANDARDS.md`
- **`PascalCase`** class names, **`camelCase`** methods — `standards/global/STANDARDS.md`
- **PSR-4 namespace** mirrors directory exactly — `standards/backend/STANDARDS.md`
- **Single-quote strings** unless interpolation needed — `standards/backend/STANDARDS.md`
- **Controller design**: no SQL, no business logic, no direct `Connection` — `standards/backend/STANDARDS.md` (Controller Design section) and `standards/backend/REST_API.md`
- **`PaginationContext::fromQuery()`** in every list action — `standards/backend/REST_API.md` (Pagination Context section)
- **Domain events**: mutation methods return `DomainEventCollection`; controllers dispatch — `standards/backend/DOMAIN_EVENTS.md`
- **`#[Test]` attribute** (not `/** @test */`) — `standards/testing/STANDARDS.md`
- **`#[DataProvider]`** for parameterized cases — `standards/testing/STANDARDS.md`
- **AAA structure** in all test methods — `standards/testing/STANDARDS.md`
- **`RepositoryException` wrapping** all `\Doctrine\DBAL\Exception` — `standards/backend/STANDARDS.md` (Error Handling section)
- **Table names as `private const TABLE`**, not injected via DI — `standards/backend/STANDARDS.md` (Schema Compatibility section, fixed table prefix convention)
- **File header comment** (copyright/license block) on every new PHP file — as present in all existing module files

---

## Out of Scope

- Thread locking, pinning, subscriptions, read tracking
- `PATCH /topics/{topicId}` — update topic title
- `DELETE /topics/{topicId}` or `DELETE /posts/{postId}`
- Moderation operations (soft-delete, unapprove)
- Forum denormalization updates (`forum_posts_approved`, `forum_last_post_*` columns in `phpbb_forums`) — not in current controller code, not added here
- Counter Pattern (tiered hot/cold counter flush) — out of scope for this extraction
- Frontend or UI changes
- `composer.json` changes

---

## Success Criteria

1. All five API routes respond identically to today's behavior (verified by running existing passing test cases and new tests).
2. `POST /forums/{forumId}/topics` response body contains full `TopicDTO` fields — not the old partial `{id, title, forumId, authorId, firstPost}` shape.
3. `GET /topics/{topicId}/posts` returns 200 with `data` array and `meta` pagination block for a valid topic.
4. `post_subject` for reply posts is `'Re: ' . topic_title` (not `'Re: post'`).
5. `TopicsController` and `PostsController` contain zero direct SQL (`executeQuery`, `executeStatement`, `fetchAssociative`, `fetchAllAssociative`, `fetchOne`, `insert`, `update`, `beginTransaction`) — all delegated to `ThreadsServiceInterface`.
6. All new test classes pass: `DbalTopicRepositoryTest`, `DbalPostRepositoryTest`, `ThreadsServiceTest`, `TopicsControllerTest` (refactored), `PostsControllerTest`.
7. `TopicsControllerTest` passes without mocking `Connection` (now mocks `ThreadsServiceInterface`).
8. `services.yaml` DI registration allows Symfony container to fully wire the new module with no manual service definitions for controllers.
9. `src/phpbb/threads/` module follows the `phpbb\hierarchy\` directory structure exactly (Contract/, DTO/, Entity/, Event/, Repository/, Service/).
