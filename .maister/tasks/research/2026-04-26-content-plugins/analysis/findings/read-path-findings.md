# Read-Path Findings: Post Content from DB to JSON Response

**Source category**: `read-path`
**Date**: 2026-04-26
**Confidence**: High (100%) — all findings based on direct file reads with evidence

---

## 1. Exact Read Path Sequence

```
phpbb_posts.post_text (raw string, no processing)
    ↓  DbalPostRepository::hydrate()
Post::$text  (Entity, readonly class)
    ↓  PostDTO::fromEntity()
PostDTO::$content  (DTO, readonly class)
    ↓  ThreadsService::getPost() / listPosts()
PostDTO (returned to controller)
    ↓  PostsController::postToArray()
array ['content' => $dto->content]
    ↓  new JsonResponse([...])
JSON: {"data": {"content": "<raw_post_text>"}}
```

**No transformation occurs at any step.** Every layer passes the raw string through unchanged.

---

## 2. Layer-by-Layer Evidence

### Layer 1: Database Repository — `DbalPostRepository`

**File**: `src/phpbb/threads/Repository/DbalPostRepository.php`

`findById()` SELECT (lines 42–53):
```php
$qb->select(
    'post_id',
    'topic_id',
    'forum_id',
    'poster_id',
    'post_time',
    'post_text',      // ← raw bbcode stored here
    'post_subject',
    'post_username',
    'poster_ip',
    'post_visibility'
)
```

`findByTopic()` SELECT (lines 82–93): identical column list.

`hydrate()` method (lines 157–168):
```php
private function hydrate(array $row): Post
{
    return new Post(
        id:         (int) $row['post_id'],
        topicId:    (int) $row['topic_id'],
        forumId:    (int) $row['forum_id'],
        posterId:   (int) $row['poster_id'],
        time:       (int) $row['post_time'],
        text:       (string) $row['post_text'],  // ← DB column → Post::$text
        subject:    (string) $row['post_subject'],
        username:   (string) $row['post_username'],
        posterIp:   (string) $row['poster_ip'],
        visibility: (int) $row['post_visibility'],
    );
}
```

**Finding**: `post_text` is cast to `string` only. Zero processing.

---

### Layer 2: Entity — `Post`

**File**: `src/phpbb/threads/Entity/Post.php`

```php
final readonly class Post
{
    public function __construct(
        public int $id,
        public int $topicId,
        public int $forumId,
        public int $posterId,
        public int $time,
        public string $text,     // ← holds raw post_text
        public string $subject,
        public string $username,
        public string $posterIp,
        public int $visibility,
    ) {}
}
```

**Finding**: Plain value object, no methods, no transformation.

---

### Layer 3: DTO — `PostDTO::fromEntity()`

**File**: `src/phpbb/threads/DTO/PostDTO.php`

```php
final readonly class PostDTO
{
    public function __construct(
        public int $id,
        public int $topicId,
        public int $forumId,
        public int $authorId,
        public string $authorUsername,
        public string $content,    // ← renamed from "text" to "content"
        public int $createdAt,
    ) {}

    public static function fromEntity(Post $post): self
    {
        return new self(
            id:             $post->id,
            topicId:        $post->topicId,
            forumId:        $post->forumId,
            authorId:       $post->posterId,
            authorUsername: $post->username,
            content:        $post->text,   // ← Post::$text → PostDTO::$content
            createdAt:      $post->time,
        );
    }
}
```

**Finding**: Only rename happens here (`text` → `content`). No processing.

---

### Layer 4: Service — `ThreadsService`

**File**: `src/phpbb/threads/ThreadsService.php`

`getPost()` (lines 65–73):
```php
public function getPost(int $postId): PostDTO
{
    $post = $this->postRepository->findById($postId);

    if ($post === null || $post->visibility !== 1) {
        throw new \InvalidArgumentException("Post {$postId} not found");
    }

    return PostDTO::fromEntity($post);  // ← calls fromEntity, returns DTO
}
```

`listPosts()` (lines 123+) delegates directly to `$this->postRepository->findByTopic()` which itself maps rows → DTO inside the repository.

**Finding**: Pure delegation, no transformation.

---

### Layer 5: Controller — `PostsController::postToArray()`

**File**: `src/phpbb/api/Controller/PostsController.php`, lines 195–204

```php
private function postToArray(PostDTO $dto): array
{
    return [
        'id'             => $dto->id,
        'topicId'        => $dto->topicId,
        'forumId'        => $dto->forumId,
        'authorId'       => $dto->authorId,
        'authorUsername' => $dto->authorUsername,
        'content'        => $dto->content,   // ← raw string, key = "content"
        'createdAt'      => $dto->createdAt,
    ];
}
```

Called from `index()` (line 65):
```php
return new JsonResponse([
    'data' => array_map([$this, 'postToArray'], $result->items),
    ...
]);
```

Also from `create()` (line 129):
```php
return new JsonResponse([
    'data' => $this->postToArray($dto),
], 201);
```

And from `update()` (line 172):
```php
return new JsonResponse(['data' => $this->postToArray($dto)]);
```

**Finding**: `postToArray()` is the single place where `PostDTO` is converted to an array before it hits `JsonResponse`. Called from all three read-output paths (list, create-response, update-response).

---

## 3. `post_text` Field in JSON Response

| Step | Property name | Notes |
|------|--------------|-------|
| DB column | `post_text` | Raw bbcode/markdown/plain text |
| Entity | `Post::$text` | Cast `(string)`, no change |
| DTO | `PostDTO::$content` | Renamed from `text` |
| JSON key | `"content"` | Exactly `$dto->content`, no transformation |

**Final JSON shape**:
```json
{
  "data": {
    "id": 42,
    "topicId": 7,
    "forumId": 3,
    "authorId": 5,
    "authorUsername": "foobar",
    "content": "[b]Hello[/b] world",
    "createdAt": 1745683200
  }
}
```

---

## 4. Best Injection Point for Pre-Output Processing

### Primary recommendation: `PostsController::postToArray()`

This method is the single serialization chokepoint for all post read operations. Injecting a `ContentRenderingPipelineInterface` into `PostsController` and calling it here will cover all three endpoints (GET list, POST create response, PATCH update response) without touching any other layer.

**Proposed change** (lines 195–204):
```php
// Before:
'content' => $dto->content,

// After:
'content' => $this->contentPipeline->render($dto->content),
```

**Implementation**:
```php
public function __construct(
    private readonly ThreadsServiceInterface $threadsService,
    private readonly AuthorizationServiceInterface $authorizationService,
    private readonly UserRepositoryInterface $userRepository,
    private readonly EventDispatcherInterface $dispatcher,
    private readonly ContentRenderingPipelineInterface $contentPipeline,  // ← inject here
) {}
```

**Pros**:
- Only affects JSON output; internal DTO stays as raw text (good for edit-forms that need original markup)
- No change to repository, entity, DTO, or service
- Single file change
- Aligns with "render at output time" (phpBB3 `generate_text_for_display` pattern)

**Cons**:
- `PostsController` grows one dependency

### Alternative: inside `PostDTO::fromEntity()`

Transform `$post->text` before storing in `PostDTO::$content`. Rejected because:
- DTO becomes rendering-aware (breaks separation of concerns)
- Edit APIs that need raw markup would also receive rendered HTML

### Alternative: dedicated `KernelEvents::RESPONSE` subscriber

Not viable — all responses are `JsonResponse` with pre-encoded data; a response subscriber would need to decode JSON, mutate, and re-encode, which is fragile.

---

## 5. Symfony Serializer — Not Used

**Evidence**:
- Grep for `SerializerInterface` in `src/phpbb/**/*.php` → **0 matches**
- Grep for `kernel.view|ViewEvent|onKernelView|ResponseEvent|onKernelResponse` → **0 matches**
- No `KernelEvents::VIEW` listeners exist
- All responses are manually constructed via `new JsonResponse([...])`

**Conclusion**: The Symfony Serializer/Normalizer chain is not part of this codebase. A normalizer-based injection point does **not** exist and cannot be added without a significant refactor. The `postToArray()` approach is the correct injection point.

---

## 6. Existing Plugin Patterns (for design reference)

### `ForumBehaviorRegistry` (manual push)

**File**: `src/phpbb/hierarchy/Plugin/ForumBehaviorRegistry.php`

```php
final class ForumBehaviorRegistry
{
    private array $behaviors = [];

    public function register(ForumBehaviorInterface $behavior): void
    {
        $this->behaviors[] = $behavior;
    }

    public function getForType(string $forumType): array { ... }
}
```

Pattern: explicit `register()` calls, no tag automation.

### `TypeRegistry` (event-dispatch lazy init)

**File**: `src/phpbb/notifications/TypeRegistry.php`

```php
private function initialize(): void
{
    $event = new RegisterNotificationTypesEvent();
    $this->dispatcher->dispatch($event);
    // listeners add types to $event->getTypes()
}
```

Pattern: `EventDispatcher` used to collect plugin instances at first use.

### `services.yaml` global defaults

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false
```

**Conclusion**: `tagged_iterator` / `#[AutoconfigureTag]` are ready to use without any additional compiler passes. Both `autoconfigure: true` and `autowire: true` are global defaults. A `ContentProcessorInterface` with `#[AutoconfigureTag('phpbb.content_processor')]` will be auto-wired into a pipeline service via `tagged_iterator`.

---

## 7. Existing Event Subscribers (for context)

All subscribers in `src/phpbb/api/EventSubscriber/`:

| Class | Event | Purpose |
|-------|-------|---------|
| `ExceptionSubscriber` | `KernelEvents::EXCEPTION` (priority 10) | Convert unhandled exceptions to JSON on `/api/*` paths |
| `AuthenticationSubscriber` | `KernelEvents::REQUEST` | JWT verification, attaches `_api_user` to request |
| `AuthorizationSubscriber` | `KernelEvents::REQUEST` | Role/permission checks on protected routes |

No `KernelEvents::VIEW` or `KernelEvents::RESPONSE` subscribers exist.

---

## Summary

| Question | Answer |
|----------|--------|
| Where does `post_text` enter phpbb4? | `DbalPostRepository::hydrate()` |
| What is the JSON key? | `"content"` |
| Any transformation in the read path? | None — raw string all the way |
| Symfony Serializer used? | No |
| kernel.view / response subscriber used? | No |
| Best injection point | `PostsController::postToArray()` — line 200 |
| Best injection mechanism | Inject `ContentRenderingPipelineInterface` into `PostsController`, call in `postToArray()` |
| Existing plugin pattern to follow | `ForumBehaviorRegistry` (or tagged_iterator, both viable) |
