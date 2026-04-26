# Write-Path Findings: Post Content — HTTP Request → Database INSERT

**Source category**: `write-path`
**Date**: 2026-04-26
**Researcher**: information-gatherer agent

---

## 1. Complete Write-Path Sequence

### 1.1 CREATE POST — full call chain

```
HTTP POST /api/v1/topics/{topicId}/posts
│
├─ [1] PostsController::create()
│       src/phpbb/api/Controller/PostsController.php : 76–126
│
│   a) JWT auth already resolved by AuthenticationSubscriber (KernelEvents::REQUEST, prio 30)
│   b) JSON body decoded — raw string extracted:
│        $body    = json_decode($request->getContent(), true) ?? [];
│        $content = trim((string) ($body['content'] ?? ''));
│      ► Only validation: empty-string guard. NO sanitization, NO bbcode/HTML processing.
│
│   c) DomainEventCollection returned; extract PostCreatedEvent::entityId for the new post id
│
├─ [2] ThreadsServiceInterface::createPost(CreatePostRequest $request)
│       src/phpbb/threads/ThreadsService.php : 136–185
│
│   a) Looks up topic; opens DB transaction
│   b) Calls postRepository->insert(…, content: $request->content, …)
│      ► $request->content is the SAME string that came from the HTTP body (trimmed only)
│   c) Commits transaction
│   d) Calls searchIndexer->indexPost(…, $request->content, …)  ← also receives raw content
│   e) Returns DomainEventCollection([PostCreatedEvent])
│
└─ [3] DbalPostRepository::insert()
        src/phpbb/threads/Repository/DbalPostRepository.php : 114–158
│
│   QueryBuilder INSERT into phpbb_posts:
│     'post_text' => ':content'    ← raw, unprocessed string written to DB
│
│   No hooks, no filter, no transformation between service and DBAL layer.
```

**Evidence – controller body extraction (PostsController.php:96–99)**:
```php
$body    = json_decode($request->getContent(), true) ?? [];
$content = trim((string) ($body['content'] ?? ''));

if ($content === '') {
    return new JsonResponse(['error' => 'Content is required', 'status' => 400], 400);
}
```

**Evidence – service passes content straight to repository (ThreadsService.php:149–158)**:
```php
$postId = $this->postRepository->insert(
    topicId:        $request->topicId,
    forumId:        $topic->forumId,
    posterId:       $request->actorId,
    posterUsername: $request->actorUsername,
    posterIp:       $request->posterIp,
    content:        $request->content,   // ← unchanged
    subject:        'Re: ' . $topic->title,
    now:            $now,
    visibility:     1,
);
```

**Evidence – DBAL insert maps content to post_text (DbalPostRepository.php:128–145)**:
```php
$qb->insert(self::TABLE)
    ->values([
        'topic_id'        => ':topicId',
        'forum_id'        => ':forumId',
        'poster_id'       => ':posterId',
        'post_time'       => ':now',
        'post_text'       => ':content',   // ← raw string
        'post_subject'    => ':subject',
        ...
    ])
    ->setParameter('content', $content)
    ...
    ->executeStatement();
```

---

### 1.2 UPDATE POST — full call chain

```
HTTP PATCH /api/v1/topics/{topicId}/posts/{postId}
│
├─ [1] PostsController::update()
│       src/phpbb/api/Controller/PostsController.php : 128–163
│       Same raw extraction + trim as create. No additional processing.
│
├─ [2] ThreadsService::updatePost(UpdatePostRequest)
│       src/phpbb/threads/ThreadsService.php : ~395–420
│       Ownership check → trim → calls postRepository->updateContent()
│
└─ [3] DbalPostRepository::updateContent()
        src/phpbb/threads/Repository/DbalPostRepository.php : 160–175
│
│   UPDATE phpbb_posts SET post_text = :content WHERE post_id = :postId
│   ► Same pattern: raw string written to DB, no transformation.
```

---

## 2. DTO Chain — Data Containers

| Class | File | Role |
|-------|------|------|
| `CreatePostRequest` | `src/phpbb/threads/DTO/CreatePostRequest.php` | Carries `content: string` from controller to service — plain readonly DTO, no processing |
| `UpdatePostRequest` | `src/phpbb/threads/DTO/UpdatePostRequest.php` | `postId, content, actorId` — same pattern |
| `Post` (entity) | `src/phpbb/threads/Entity/Post.php` | Hydrated from DB row; `text: string` is stored/retrieved as-is |
| `PostDTO` | `src/phpbb/threads/DTO/PostDTO.php` | Read DTO; `content` maps from `Post::$text` directly — no output transformation |

---

## 3. Content is Stored RAW

**Confirmed**: Content goes through this exact path without any transformation:

```
HTTP JSON string
  → trim()
    → CreatePostRequest::$content (string)
      → ThreadsService passes unchanged
        → DbalPostRepository INSERT phpbb_posts.post_text
```

There is **no** bbcode parsing, markdown conversion, `htmlspecialchars`, `strip_tags`, or any other filter applied anywhere on the write path.

The same raw string is also sent verbatim to the search indexer:
```php
// ThreadsService.php:181
$this->searchIndexer->indexPost($postId, $request->content, 'Re: ' . $topic->title, $topic->forumId);
```

---

## 4. Existing Hooks/Events on the Write Path

### 4.1 Domain events (post-persist, NOT pre-persist)

After the DB INSERT commits, a `DomainEventCollection` is returned and dispatched in the controller:

```php
// PostsController.php:112–115
$events = $this->threadsService->createPost(new CreatePostRequest(…));
$events->dispatch($this->dispatcher);   // fires PostCreatedEvent
```

`PostCreatedEvent` (and `PostUpdatedEvent`) are dispatched via `EventDispatcherInterface` **after** the write.  
Listeners registered for these events receive only `entityId` and `actorId` — **not** the content string.

**Evidence – DomainEvent base class (src/phpbb/common/Event/DomainEvent.php:21–26)**:
```php
abstract readonly class DomainEvent
{
    public function __construct(
        public readonly string|int $entityId,
        public readonly int $actorId,
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {
    }
}
```

### 4.2 KernelEvents subscribers (request/response layer)

Three `EventSubscriberInterface` implementations exist:

| Subscriber | File | KernelEvents subscribed |
|-----------|------|------------------------|
| `AuthenticationSubscriber` | `src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php` | `REQUEST` |
| `AuthorizationSubscriber`  | `src/phpbb/api/EventSubscriber/AuthorizationSubscriber.php` | `REQUEST` (or CONTROLLER) |
| `ExceptionSubscriber`      | `src/phpbb/api/EventSubscriber/ExceptionSubscriber.php` | `EXCEPTION` |

None of these touch post content. There is **no** `KernelEvents::VIEW` or `KernelEvents::RESPONSE` subscriber that could intercept content on output.

### 4.3 ForumBehaviorRegistry — existing plugin registry pattern

`src/phpbb/hierarchy/Plugin/ForumBehaviorRegistry.php` implements a **tagged-service registry** pattern for forum-type behaviors:

```php
// ForumBehaviorRegistry.php
final class ForumBehaviorRegistry
{
    private array $behaviors = [];

    public function register(ForumBehaviorInterface $behavior): void
    {
        $this->behaviors[] = $behavior;
    }
    ...
    public function getForType(string $forumType): array { … }
}
```

`ForumBehaviorInterface` extends **both**:
- `RequestDecoratorInterface` — `decorateCreate(CreateForumRequest): CreateForumRequest`
- `ResponseDecoratorInterface` — `decorateResponse(Forum): Forum`

This is the closest existing precedent for a content-plugin injection pattern. However, it operates on **Forum** entities, not post content.

---

## 5. Where Content Transformation COULD Be Injected

### Option A — Pre-save, inside ThreadsService (RECOMMENDED for storage-side processing)

**Injection point**: `ThreadsService::createPost()` / `updatePost()`, immediately before `$this->postRepository->insert(…)`

```php
// Current:
$postId = $this->postRepository->insert(…, content: $request->content, …);

// With injected processor:
$processed = $this->contentPipeline->process($request->content);
$postId = $this->postRepository->insert(…, content: $processed, …);
```

- Clean: processor is injected via constructor DI
- No controller changes needed
- SearchIndexer gets processed content (or could receive original — decision point)

### Option B — Pre-save, inside the controller (NOT recommended)

`PostsController::create()` at line ~102, between `trim()` and `new CreatePostRequest(…)`.  
Couples business logic to the HTTP layer — violates separation of concerns.

### Option C — Pre-output, in `PostDTO::fromEntity()` or `postToArray()` (for read-side transformation)

`PostsController::postToArray()` (line ~207) is the only place where content flows to the JSON response. A transformer could be applied here for read-side rendering (e.g., bbcode → HTML for display).

```php
private function postToArray(PostDTO $dto): array
{
    return [
        …
        'content' => $this->contentRenderer->render($dto->content),
        …
    ];
}
```

### Option D — KernelEvents::VIEW / RESPONSE subscriber

A `KernelEvents::RESPONSE` subscriber could intercept JSON responses on `/api/*/posts` paths and transform content fields in the JSON body. This is **invasive** (requires JSON parse/re-encode) and risks breaking structured data. Not recommended.

---

## 6. No Existing Content Processor Interface

**Confirmed**: running `grep -r "ContentProcessor\|TextProcessor\|PostProcessor\|BodyProcessor" src/ --include="*.php"` returns **zero results**. There is no existing content processing interface, pipeline, or plugin system for post bodies.

---

## 7. Summary Table

| Question | Answer |
|----------|--------|
| Is content stored raw? | **YES** — `post_text` = verbatim trimmed HTTP body string |
| Any pre-save transformation? | **NO** — none exists |
| Any pre-output transformation? | **NO** — `postToArray()` returns `$dto->content` directly |
| Any hooks on write path? | Post-persist only (`PostCreatedEvent` / `PostUpdatedEvent`), no content in payload |
| Existing plugin pattern? | `ForumBehaviorRegistry` (tagged services, decorator pattern) — for forums, not posts |
| Existing content processor interface? | **NONE** |
| Best injection point (pre-save)? | `ThreadsService::createPost()` / `updatePost()` before repository call |
| Best injection point (pre-output)? | `PostsController::postToArray()` |

---

## 8. Files Investigated

| File | Lines Read | Purpose |
|------|-----------|---------|
| `src/phpbb/api/Controller/PostsController.php` | 1–220 | Full create/update/delete/index actions |
| `src/phpbb/threads/ThreadsService.php` | 1–500 | Full service — createPost, updatePost |
| `src/phpbb/threads/Repository/DbalPostRepository.php` | 1–200 | insert(), updateContent(), hydrate() |
| `src/phpbb/threads/DTO/CreatePostRequest.php` | full | DTO structure |
| `src/phpbb/threads/DTO/UpdatePostRequest.php` | full | DTO structure |
| `src/phpbb/threads/DTO/PostDTO.php` | full | Read DTO, fromEntity() |
| `src/phpbb/threads/Entity/Post.php` | full | Entity hydration |
| `src/phpbb/threads/Event/PostCreatedEvent.php` | full | Domain event payload |
| `src/phpbb/common/Event/DomainEvent.php` | full | Base event — entityId + actorId only |
| `src/phpbb/common/Event/DomainEventCollection.php` | full | dispatch() mechanism |
| `src/phpbb/api/EventSubscriber/ExceptionSubscriber.php` | full | KernelEvents::EXCEPTION only |
| `src/phpbb/hierarchy/Plugin/ForumBehaviorRegistry.php` | full | Existing plugin registry pattern |
| `src/phpbb/hierarchy/Plugin/ForumBehaviorInterface.php` | full | RequestDecorator + ResponseDecorator |
| `src/phpbb/hierarchy/Contract/RequestDecoratorInterface.php` | full | decorateCreate/decorateUpdate |
| `src/phpbb/hierarchy/Contract/ResponseDecoratorInterface.php` | full | decorateResponse |
| `src/phpbb/config/services.yaml` | 1–400 | Full DI config — threads, hierarchy sections |
| grep across src/phpbb/ | — | bbcode/markdown/transform/filter/ContentProcessor/EventSubscriber pattern search |
