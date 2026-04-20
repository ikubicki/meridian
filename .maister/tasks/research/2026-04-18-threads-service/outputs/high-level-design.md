# High-Level Design: `phpbb\threads` Service

## Design Overview

**Business context**: The phpBB threads subsystem — topics, posts, drafts, visibility, and content rendering — is the forum's core write path, currently spread across ~8,000 LOC of procedural code (`posting.php`, `functions_posting.php`, `viewtopic.php`, `content_visibility.php`, `message_parser.php`). The `submit_post()` monolith alone is ~1,000 lines handling 6 modes. This code is untestable, tightly coupled to globals, and manages 20+ denormalized counters with manual increment/decrement across 3 table levels. The `phpbb\threads` service replaces this with a clean, plugin-extensible OOP layer.

**Chosen approach**: A **lean core decomposition** with `ThreadsService` facade orchestrating **3 repositories** (`TopicRepository`, `PostRepository`, `DraftRepository`), **3 domain services** (`VisibilityService`, `CounterService`, `TopicMetadataService`), **1 feature service** (`DraftService`), and a **`ContentPipeline`** for plugin-driven text formatting. Cross-cutting features — **Polls, ReadTracking, Subscriptions, Attachments** — are **plugins** that extend the core via **domain events and request/response decorators**. Content storage uses the **existing `post_text` column** — legacy posts contain s9e XML and remain unchanged; an `encoding_engine` column (`VARCHAR(16) DEFAULT 's9e'`) tells the ContentPipeline which renderer to use. New posts default to `s9e` encoding. The API is **event-driven** — service methods return `DomainEventCollection` objects; controllers dispatch events post-commit.

**Key decisions:**
- **s9e XML default + encoding_engine** — existing `post_text` column preserved; `encoding_engine VARCHAR(16) DEFAULT 's9e'` added; ContentPipeline dispatches to renderer based on engine value; future formats supported per-post (ADR-001 amended)
- **Hybrid content pipeline** — ordered `ContentPluginInterface` middleware chain for formatting + events for cross-cutting hooks like censoring (ADR-002)
- **Lean core + plugin extensions** — Polls, ReadTracking, Subscriptions, Attachments extend via events + decorators, NOT core services (ADR-003)
- **Event-driven counter propagation** — topic-level counters sync in own transaction; forum counters propagated via domain events to `phpbb\hierarchy` (ForumStatsSubscriber); user counters via events to `phpbb\user`; batch reconciliation as safety net (ADR-004 amended)
- **Dedicated VisibilityService** — single entry point for all visibility transitions, 4-state machine, counter integration, cascades, SQL generation (ADR-005)
- **Auth-unaware service** — no permission checks inside threads; API middleware enforces ACL; `f_noapprove` passed as parameter for initial visibility (ADR-006)
- **DraftService in core** — simple CRUD in threads namespace, event-based cleanup on post submit (ADR-007)

---

## Architecture

### System Context (C4 Level 1)

```
                              ┌───────────────────┐
                              │   End User (Web)   │
                              │   Mobile / SPA     │
                              │   API Consumer      │
                              └────────┬───────────┘
                                       │ HTTP
                                       ▼
                              ┌───────────────────┐
                              │   phpBB App Layer  │
                              │  Controllers/API   │
                              │  (Auth middleware)  │
                              └────────┬───────────┘
                                       │ PHP method calls
                                       ▼
┌──────────────┐     ┌───────────────────────────────────┐     ┌──────────────┐
│ phpbb\auth   │     │      phpbb\threads                │────►│ phpbb\user   │
│ (enforces    │     │      Service Layer                │     │ (user_posts  │
│  f_post,     │     │                                   │     │  via events) │
│  f_reply...) │     │  Events → phpbb\hierarchy counters │     └──────────────┘
└──────────────┘     │  Async: domain events → plugins   │
                     └───────────┬───────────────────────┘
                                 │                    ▲
                                 │ PDO                │ Event listeners
                                 ▼                    │
                     ┌───────────────────┐   ┌────────┴──────────────┐
                     │     MySQL / DB    │   │  Plugin Listeners      │
                     │  phpbb_topics     │   │  (Polls, ReadTracking, │
                     │  phpbb_posts      │   │   Subscriptions,       │
                     │  phpbb_drafts     │   │   Attachments, Search, │
                     └───────────────────┘   │   Notifications)       │
                                             └────────────────────────┘
```

**External systems**:
- **phpbb\auth** — Called by the API layer (NOT by threads) to enforce `f_post`, `f_reply`, `m_edit`, `m_delete`, etc.
- **phpbb\hierarchy** — Consumes domain events (`TopicCreatedEvent`, `PostCreatedEvent`, `VisibilityChangedEvent`, etc.) via `ForumStatsSubscriber` to update forum counters and last-post info. Threads is completely unaware of Hierarchy — zero imports, zero direct calls (event-driven, eventual consistency)
- **phpbb\user** — Consumes `PostCreatedEvent`, `PostVisibilityChangedEvent` etc. to update `user_posts` and `user_lastpost_time` (event-driven, post-commit)
- **Plugin listeners** — Polls, ReadTracking, Subscriptions, Attachments, Search, Notifications all consume domain events and extend via DTO decorators

### Container Overview (C4 Level 2)

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        phpbb\threads                                    │
│                                                                         │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │                     ThreadsService (Facade)                       │  │
│  │                                                                   │  │
│  │  Request DTO ──► RequestDecoratorChain ──► Service Logic          │  │
│  │       ──► Domain Event ──► EventDispatcher ──► ResponseDecorator  │  │
│  │       ──► Response DTO / Event returned to caller                 │  │
│  └──┬───────┬──────────┬────────────┬──────────┬────────────────────┘  │
│     │       │          │            │          │                        │
│  ┌──▼──┐ ┌──▼───┐ ┌───▼────┐ ┌────▼────┐ ┌───▼────┐                  │
│  │Topic│ │Post  │ │Visibi- │ │Counter  │ │Topic   │                  │
│  │Repo │ │Repo  │ │lity    │ │Service  │ │Metadata│                  │
│  │     │ │      │ │Service │ │         │ │Service │                  │
│  │CRUD │ │CRUD  │ │4-state │ │incr/dec │ │first/  │                  │
│  │query│ │paged │ │machine │ │transfer │ │last    │                  │
│  │     │ │      │ │cascade │ │sync     │ │post    │                  │
│  └──┬──┘ └──┬───┘ └───┬────┘ └────┬────┘ └───┬────┘                  │
│     │       │         │           │           │                        │
│     └───────┴────┬────┴───────────┴───────────┘                        │
│                  │                                                      │
│            ┌─────▼──────┐                                               │
│            │    PDO     │                                               │
│            └────────────┘                                               │
│                                                                         │
│  ┌──────┐ ┌──────────────┐                                             │
│  │Draft │ │Content       │                                             │
│  │Svc   │ │Pipeline      │                                             │
│  │      │ │              │                                             │
│  │save  │ │parse/render  │                                             │
│  │load  │ │plugin chain  │                                             │
│  │delete│ │              │                                             │
│  └──┬───┘ └──────┬───────┘                                             │
│     │            │                                                      │
│  ┌──▼───┐  ┌────▼──────────────────────────────────┐                   │
│  │Draft │  │ ContentPluginInterface (middleware)    │                   │
│  │Repo  │  │ + ContentParse/RenderEvents (hooks)    │                   │
│  └──────┘  └────────────────────────────────────────┘                   │
│                                                                         │
│  ┌────────────────────────────────────────────────────────────────┐     │
│  │ Request/Response Decorator Pipeline                            │     │
│  │                                                                │     │
│  │  CreateTopicRequest ──► [PollDeco] ──► [AttachDeco] ──►       │     │
│  │  TopicViewResponse  ◄── [PollDeco] ◄── [ReadTrackDeco] ◄──   │     │
│  └────────────────────────────────────────────────────────────────┘     │
│                                                                         │
│  ┌────────────────────────────────────────────────────────────────┐     │
│  │ EventDispatcher                                                │     │
│  │                                                                │     │
│  │ TopicCreatedEvent ──► PollPlugin (create poll)                │     │
│  │ PostCreatedEvent  ──► AttachmentPlugin (adopt orphans)        │     │
│  │                   ──► SearchPlugin (index)                     │     │
│  │                   ──► NotificationPlugin (notify watchers)     │     │
│  │                   ──► phpbb\user (increment user_posts)       │     │
│  └────────────────────────────────────────────────────────────────┘     │
└─────────────────────────────────────────────────────────────────────────┘
```

**Container responsibilities**:

| Container | Tech | Responsibility |
|-----------|------|----------------|
| ThreadsService | PHP 8.2 class | Facade — orchestrates operations, runs decorator pipeline, dispatches events, returns domain events |
| TopicRepository | PHP 8.2 + PDO | Topic CRUD, forum listing queries, two-phase pagination, entity hydration |
| PostRepository | PHP 8.2 + PDO | Post CRUD, topic post listing, two-phase pagination, entity hydration |
| VisibilityService | PHP 8.2 | 4-state machine transitions, counter side effects via CounterService, cascade logic (topic→posts), SQL generation |
| CounterService | PHP 8.2 + PDO | All denormalized counter operations: increment, decrement, transfer, sync/reconciliation |
| TopicMetadataService | PHP 8.2 + PDO | Recalculate first/last post denormalized columns on topic when posts change |
| DraftService | PHP 8.2 | Draft CRUD, event-based cleanup on post submit |
| DraftRepository | PHP 8.2 + PDO | Draft table CRUD, user draft queries |
| ContentPipeline | PHP 8.2 | Ordered plugin chain for parse+render; dispatches content pipeline events |
| DecoratorPipeline | PHP 8.2 | Ordered chain of request/response decorators provided by plugins |
| EventDispatcher | Symfony EventDispatcher | Dispatches domain events to registered listeners |

---

## Key Components

### Directory Structure

```
src/phpbb/threads/
├── ThreadsService.php
├── ThreadsServiceInterface.php
│
├── contract/
│   ├── TopicRepositoryInterface.php
│   ├── PostRepositoryInterface.php
│   ├── DraftRepositoryInterface.php
│   ├── VisibilityServiceInterface.php
│   ├── CounterServiceInterface.php
│   ├── TopicMetadataServiceInterface.php
│   ├── DraftServiceInterface.php
│   ├── ContentPipelineInterface.php
│   └── ContentPluginInterface.php
│
├── dto/
│   ├── request/
│   │   ├── CreateTopicRequest.php
│   │   ├── CreateReplyRequest.php
│   │   ├── EditPostRequest.php
│   │   ├── SoftDeleteRequest.php
│   │   ├── RestoreRequest.php
│   │   ├── HardDeleteRequest.php
│   │   ├── BumpTopicRequest.php
│   │   ├── LockTopicRequest.php
│   │   ├── MoveTopicRequest.php
│   │   ├── ChangeTopicTypeRequest.php
│   │   ├── SaveDraftRequest.php
│   │   └── GetTopicPostsRequest.php
│   └── response/
│       ├── TopicViewResponse.php
│       ├── ForumTopicsResponse.php
│       ├── PostResponse.php
│       └── DraftResponse.php
│
├── entity/
│   ├── Topic.php
│   ├── Post.php
│   └── Draft.php
│
├── enum/
│   ├── Visibility.php
│   ├── TopicType.php
│   └── TopicStatus.php
│
├── event/
│   ├── TopicCreatedEvent.php
│   ├── TopicEditedEvent.php
│   ├── TopicLockedEvent.php
│   ├── TopicMovedEvent.php
│   ├── TopicDeletedEvent.php
│   ├── TopicTypeChangedEvent.php
│   ├── PostCreatedEvent.php
│   ├── PostEditedEvent.php
│   ├── PostSoftDeletedEvent.php
│   ├── PostRestoredEvent.php
│   ├── PostHardDeletedEvent.php
│   ├── VisibilityChangedEvent.php
│   ├── ForumCountersChangedEvent.php
│   ├── DraftSavedEvent.php
│   ├── DraftDeletedEvent.php
│   ├── ContentPreParseEvent.php
│   ├── ContentPostParseEvent.php
│   ├── ContentPreRenderEvent.php
│   └── ContentPostRenderEvent.php
│
├── exception/
│   ├── TopicNotFoundException.php              # extends phpbb\common\Exception\NotFoundException
│   ├── PostNotFoundException.php               # extends phpbb\common\Exception\NotFoundException
│   ├── DraftNotFoundException.php              # extends phpbb\common\Exception\NotFoundException
│   ├── InvalidVisibilityTransitionException.php # extends phpbb\common\Exception\ValidationException
│   └── TopicLockedException.php                # extends phpbb\common\Exception\ConflictException
│
├── pipeline/
│   ├── ContentPipeline.php
│   └── ContentContext.php
│
├── repository/
│   ├── TopicRepository.php
│   ├── PostRepository.php
│   └── DraftRepository.php
│
├── service/
│   ├── VisibilityService.php
│   ├── CounterService.php
│   ├── TopicMetadataService.php
│   └── DraftService.php
│
└── decorator/
    ├── RequestDecoratorInterface.php
    ├── ResponseDecoratorInterface.php
    └── DecoratorPipeline.php
```

---

## Domain Model

### Enums

```php
<?php declare(strict_types=1);

namespace phpbb\threads\enum;

enum Visibility: int
{
    case Unapproved = 0;
    case Approved   = 1;
    case Deleted    = 2;
    case Reapprove  = 3;

    /** Map visibility to the counter field suffix */
    public function counterField(): string
    {
        return match ($this) {
            self::Approved                  => 'approved',
            self::Unapproved, self::Reapprove => 'unapproved',
            self::Deleted                   => 'softdeleted',
        };
    }

    /** Whether this state counts toward public num_posts / num_topics */
    public function countsPublic(): bool
    {
        return $this === self::Approved;
    }
}

enum TopicType: int
{
    case Normal   = 0;
    case Sticky   = 1;
    case Announce = 2;
    case Global   = 3;
}

enum TopicStatus: int
{
    case Unlocked = 0;
    case Locked   = 1;
    case Moved    = 2;
}
```

### Topic Entity

```php
<?php declare(strict_types=1);

namespace phpbb\threads\entity;

use phpbb\threads\enum\TopicType;
use phpbb\threads\enum\TopicStatus;
use phpbb\threads\enum\Visibility;

final class Topic
{
    public function __construct(
        // Identity
        public readonly int $id,
        public readonly int $forumId,

        // Content
        public readonly string $title,
        public readonly int $iconId,

        // Type & State
        public readonly TopicType $type,
        public readonly TopicStatus $status,
        public readonly Visibility $visibility,

        // Author
        public readonly int $posterId,
        public readonly int $createdAt,

        // Denormalized first post info
        public readonly int $firstPostId,
        public readonly string $firstPosterName,
        public readonly string $firstPosterColour,

        // Denormalized last post info
        public readonly int $lastPostId,
        public readonly int $lastPosterId,
        public readonly string $lastPosterName,
        public readonly string $lastPosterColour,
        public readonly string $lastPostSubject,
        public readonly int $lastPostTime,

        // Counters
        public readonly int $postsApproved,
        public readonly int $postsUnapproved,
        public readonly int $postsSoftdeleted,

        // Display
        public readonly int $views,

        // Announce/Sticky expiry
        public readonly int $timeLimit,

        // Move tracking
        public readonly int $movedToId,

        // Bump
        public readonly bool $bumped,
        public readonly int $bumperId,

        // Soft-delete info (nullable)
        public readonly int $deleteTime,
        public readonly int $deleteUserId,
        public readonly string $deleteReason,

        // Flags
        public readonly bool $hasAttachments,
        public readonly bool $hasReports,
    ) {}

    public function totalPosts(): int
    {
        return $this->postsApproved + $this->postsUnapproved + $this->postsSoftdeleted;
    }

    public function displayReplies(): int
    {
        return max(0, $this->postsApproved - 1);
    }

    public function isMoved(): bool
    {
        return $this->status === TopicStatus::Moved;
    }
}
```

### Post Entity

```php
<?php declare(strict_types=1);

namespace phpbb\threads\entity;

use phpbb\threads\enum\Visibility;

final class Post
{
    public function __construct(
        // Identity
        public readonly int $id,
        public readonly int $topicId,
        public readonly int $forumId,

        // Author
        public readonly int $posterId,
        public readonly string $posterIp,
        public readonly string $posterUsername,
        public readonly int $postedAt,

        // Content — RAW TEXT ONLY
        public readonly string $postText,
        public readonly string $subject,
        public readonly int $iconId,

        // State
        public readonly Visibility $visibility,
        public readonly bool $countsTowardPostCount,

        // Edit tracking
        public readonly int $editTime,
        public readonly int $editUserId,
        public readonly int $editCount,
        public readonly string $editReason,
        public readonly bool $editLocked,

        // Soft-delete info
        public readonly int $deleteTime,
        public readonly int $deleteUserId,
        public readonly string $deleteReason,

        // Flags
        public readonly bool $hasAttachments,
        public readonly bool $hasReports,
    ) {}
}
```

### Draft Entity

```php
<?php declare(strict_types=1);

namespace phpbb\threads\entity;

final class Draft
{
    public function __construct(
        public readonly int $id,
        public readonly int $userId,
        public readonly int $forumId,
        public readonly int $topicId,     // 0 = new topic draft
        public readonly string $title,
        public readonly string $message,  // Stored text (s9e XML or per encoding_engine)
        public readonly int $savedAt,
    ) {}
}
```

---

## Service Architecture

### ThreadsServiceInterface (Facade)

```php
<?php declare(strict_types=1);

namespace phpbb\threads;

use phpbb\threads\dto\request\CreateTopicRequest;
use phpbb\threads\dto\request\CreateReplyRequest;
use phpbb\threads\dto\request\EditPostRequest;
use phpbb\threads\dto\request\SoftDeleteRequest;
use phpbb\threads\dto\request\RestoreRequest;
use phpbb\threads\dto\request\HardDeleteRequest;
use phpbb\threads\dto\request\BumpTopicRequest;
use phpbb\threads\dto\request\LockTopicRequest;
use phpbb\threads\dto\request\MoveTopicRequest;
use phpbb\threads\dto\request\ChangeTopicTypeRequest;
use phpbb\threads\dto\request\GetTopicPostsRequest;
use phpbb\threads\dto\request\SaveDraftRequest;
use phpbb\threads\dto\response\TopicViewResponse;
use phpbb\threads\dto\response\ForumTopicsResponse;
use phpbb\threads\dto\response\PostResponse;
use phpbb\threads\dto\response\DraftResponse;
use phpbb\threads\event\TopicCreatedEvent;
use phpbb\threads\event\PostCreatedEvent;
use phpbb\threads\event\PostEditedEvent;
use phpbb\threads\event\VisibilityChangedEvent;
use phpbb\threads\event\PostHardDeletedEvent;
use phpbb\threads\event\TopicDeletedEvent;
use phpbb\threads\event\TopicLockedEvent;
use phpbb\threads\event\TopicMovedEvent;
use phpbb\threads\event\TopicTypeChangedEvent;
use phpbb\threads\event\DraftSavedEvent;
use phpbb\threads\event\DraftDeletedEvent;

interface ThreadsServiceInterface
{
    // ── Write Operations (return domain events) ──

    public function createTopic(CreateTopicRequest $request): TopicCreatedEvent;

    public function createReply(CreateReplyRequest $request): PostCreatedEvent;

    public function editPost(EditPostRequest $request): PostEditedEvent;

    public function softDeletePost(SoftDeleteRequest $request): VisibilityChangedEvent;

    public function restorePost(RestoreRequest $request): VisibilityChangedEvent;

    public function hardDeletePost(HardDeleteRequest $request): PostHardDeletedEvent;

    public function softDeleteTopic(SoftDeleteRequest $request): VisibilityChangedEvent;

    public function restoreTopic(RestoreRequest $request): VisibilityChangedEvent;

    public function hardDeleteTopic(HardDeleteRequest $request): TopicDeletedEvent;

    public function approvePost(int $postId, int $moderatorId): VisibilityChangedEvent;

    public function approveTopic(int $topicId, int $moderatorId): VisibilityChangedEvent;

    public function bumpTopic(BumpTopicRequest $request): TopicLockedEvent;

    public function lockTopic(LockTopicRequest $request): TopicLockedEvent;

    public function moveTopic(MoveTopicRequest $request): TopicMovedEvent;

    public function changeTopicType(ChangeTopicTypeRequest $request): TopicTypeChangedEvent;

    // ── Query Operations (return DTOs, no events — reads are side-effect-free) ──

    public function getTopic(int $topicId): ?TopicViewResponse;

    public function getPost(int $postId): ?PostResponse;

    public function getTopicWithPosts(GetTopicPostsRequest $request): TopicViewResponse;

    public function getForumTopics(int $forumId, int $page, int $perPage): ForumTopicsResponse;

    // ── Draft Operations ──

    public function saveDraft(SaveDraftRequest $request): DraftSavedEvent;

    public function loadDraft(int $draftId, int $userId): ?DraftResponse;

    /** @return DraftResponse[] */
    public function getUserDrafts(int $userId, ?int $forumId = null): array;

    public function deleteDraft(int $draftId, int $userId): DraftDeletedEvent;
}
```

### VisibilityServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\threads\contract;

use phpbb\threads\enum\Visibility;

interface VisibilityServiceInterface
{
    /**
     * Transition a post's visibility state.
     * Handles counter side effects via CounterService.
     *
     * @throws InvalidVisibilityTransitionException
     */
    public function setPostVisibility(
        int $postId,
        Visibility $newVisibility,
        int $topicId,
        int $forumId,
        int $actorId,
        string $reason = '',
    ): void;

    /**
     * Transition a topic's visibility state.
     * Cascades to posts: topic soft-delete → cascade APPROVED posts to DELETED.
     * Topic restore → restore posts with matching delete_time.
     *
     * @return int[] IDs of affected posts
     * @throws InvalidVisibilityTransitionException
     */
    public function setTopicVisibility(
        int $topicId,
        Visibility $newVisibility,
        int $forumId,
        int $actorId,
        string $reason = '',
    ): array;

    /**
     * Generate SQL WHERE clause for visibility filtering.
     * Used by repositories to filter topics/posts by visible states.
     *
     * @param Visibility[] $allowedStates States the current user may see
     * @param string $tableAlias SQL table alias (e.g., 'p' for posts)
     * @param string $column Column name (default: 'visibility')
     * @return string SQL fragment, e.g. "p.post_visibility IN (1)"
     */
    public function getVisibilitySql(
        array $allowedStates,
        string $tableAlias = '',
        string $column = 'visibility',
    ): string;
}
```

### CounterServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\threads\contract;

use phpbb\threads\enum\Visibility;

interface CounterServiceInterface
{
    /**
     * Increment counters for a new post.
     * Updates topic_posts_* within the threads-owned transaction.
     * Forum counters are NOT updated here — propagated via domain events
     * to phpbb\hierarchy's ForumStatsSubscriber (see COUNTER_PATTERN.md).
     * User counters are NOT updated here (event-driven via phpbb\user).
     */
    public function incrementPostCounters(
        int $topicId,
        int $forumId,
        Visibility $visibility,
    ): void;

    /**
     * Decrement counters for a removed post.
     */
    public function decrementPostCounters(
        int $topicId,
        int $forumId,
        Visibility $visibility,
    ): void;

    /**
     * Transfer counters when visibility changes.
     * Decrements old visibility bucket, increments new visibility bucket.
     */
    public function transferPostCounters(
        int $topicId,
        int $forumId,
        Visibility $fromVisibility,
        Visibility $toVisibility,
    ): void;

    /**
     * Increment topic counters on forum.
     */
    public function incrementTopicCounters(
        int $forumId,
        Visibility $visibility,
    ): void;

    /**
     * Decrement topic counters on forum.
     */
    public function decrementTopicCounters(
        int $forumId,
        Visibility $visibility,
    ): void;

    /**
     * Transfer topic counters when topic visibility changes.
     */
    public function transferTopicCounters(
        int $forumId,
        Visibility $fromVisibility,
        Visibility $toVisibility,
    ): void;

    /**
     * Full resync: recalculate topic counters from posts table.
     */
    public function syncTopicCounters(int $topicId): void;

    /**
     * Full resync: recalculate forum counters from topics/posts tables.
     */
    public function syncForumCounters(int $forumId): void;
}
```

### TopicMetadataServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\threads\contract;

interface TopicMetadataServiceInterface
{
    /**
     * Recalculate first post denormalized columns on topic.
     * Called when the first post changes (delete, visibility change).
     * Updates: first_post_id, first_poster_name, first_poster_colour
     */
    public function recalculateFirstPost(int $topicId): void;

    /**
     * Recalculate last post denormalized columns on topic.
     * Called when the last post changes (new reply, delete, visibility change).
     * Updates: last_post_id, last_poster_id, last_poster_name,
     *          last_poster_colour, last_post_subject, last_post_time
     */
    public function recalculateLastPost(int $topicId): void;

    /**
     * Full resync of all metadata on a topic (first + last post + counters).
     * Used for maintenance/repair.
     */
    public function fullResync(int $topicId): void;
}
```

### DraftServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\threads\contract;

use phpbb\threads\dto\request\SaveDraftRequest;
use phpbb\threads\dto\response\DraftResponse;
use phpbb\threads\event\DraftSavedEvent;
use phpbb\threads\event\DraftDeletedEvent;

interface DraftServiceInterface
{
    public function save(SaveDraftRequest $request): DraftSavedEvent;

    public function load(int $draftId, int $userId): ?DraftResponse;

    /** @return DraftResponse[] */
    public function loadForUser(int $userId, ?int $forumId = null): array;

    public function delete(int $draftId, int $userId): DraftDeletedEvent;
}
```

---

## Content Pipeline (Plugin Architecture)

### Core Principle

The core stores content in the **existing `post_text` column** with an **`encoding_engine`** column indicating the format (default: `'s9e'`). Legacy posts contain s9e XML and remain unchanged. The `ContentPipeline` consults `encoding_engine` to dispatch to the correct renderer:

```php
match ($post->encodingEngine) {
	's9e' => $this->s9eRenderer->render($post->postText),
	'raw' => $this->bbcodeParser->parse($post->postText),
	// Future formats added here
};
```

New posts are stored with `encoding_engine = 's9e'` by default. The `ContentPipeline` runs the appropriate plugin chain on **every render**. There is no stored cache, no `rendered_html` column, no `plugin_metadata`. Caching is handled by the centralized cache service (`TagAwareCacheInterface`, pool `cache.threads`) wrapping the pipeline externally.

### ContentPipelineInterface

```php
<?php declare(strict_types=1);

namespace phpbb\threads\contract;

use phpbb\threads\pipeline\ContentContext;

interface ContentPipelineInterface
{
    /**
     * Parse text at save time.
     * Runs ordered plugin chain (ContentPluginInterface::parse) then
     * dispatches ContentPreParseEvent / ContentPostParseEvent.
     *
     * Returns the (possibly transformed) text to store.
     * For s9e encoding, this produces s9e XML from user input.
     * For raw encoding, this is primarily validation/normalization.
     */
    public function parse(string $rawText, ContentContext $context): string;

    /**
     * Render stored text for display.
     * Consults encoding_engine to determine rendering strategy,
     * then runs ordered plugin chain (ContentPluginInterface::render).
     *
     * Executes on EVERY display — cache.threads pool wraps this externally.
     */
    public function render(string $storedText, ContentContext $context): string;

    /**
     * Register a content plugin into the pipeline.
     * Plugins are sorted by priority (lower = earlier in chain).
     */
    public function registerPlugin(ContentPluginInterface $plugin): void;
}
```

### ContentPluginInterface

```php
<?php declare(strict_types=1);

namespace phpbb\threads\contract;

use phpbb\threads\pipeline\ContentContext;

interface ContentPluginInterface
{
    /** Unique plugin identifier (e.g., 'bbcode', 'markdown', 'smilies') */
    public function getName(): string;

    /** Lower priority = earlier in chain. Typical: BBCode=100, Smilies=200, AutoLink=300, Censor=900 */
    public function getPriority(): int;

    /**
     * Parse phase (at save time): validate/normalize text.
     * For s9e engine: produces s9e XML. For raw engine: normalizes input.
     * Returns the (possibly modified) text.
     */
    public function parse(string $text, ContentContext $context): string;

    /**
     * Render phase (at display time): transform stored text into HTML.
     * For s9e engine: delegates to s9e renderer. For raw engine: runs BBCode parser.
     * Returns the (possibly modified) text/HTML.
     */
    public function render(string $text, ContentContext $context): string;
}
```

### ContentContext

```php
<?php declare(strict_types=1);

namespace phpbb\threads\pipeline;

final readonly class ContentContext
{
    public function __construct(
        /** Forum ID for context-specific config (allowed BBCodes, etc.) */
        public int $forumId,
        /** Author's user ID */
        public int $userId,
        /** Content mode: 'post', 'signature', 'pm' */
        public string $mode,
        /** Viewing user's ID (for render-time per-user preferences) */
        public int $viewingUserId = 0,
        /** Per-user: display smilies as images? */
        public bool $viewSmilies = true,
        /** Per-user: display images inline? */
        public bool $viewImages = true,
        /** Per-user: apply word censoring? */
        public bool $viewCensored = true,
        /** Search highlight words (render-time only) */
        public ?string $highlightWords = null,
        /** Forum-specific config overrides */
        public array $config = [],
    ) {}
}
```

### Content Pipeline Events

These fire **during** parse/render operations (synchronous, in-process) for cross-cutting hooks:

| Event | Phase | Purpose | Example Use |
|-------|-------|---------|-------------|
| `ContentPreParseEvent` | Before plugin parse chain | Validate/transform raw input before plugins | Spam filter, content policy check |
| `ContentPostParseEvent` | After plugin parse chain | Modify result after all plugins parsed | Final validation, empty-check |
| `ContentPreRenderEvent` | Before plugin render chain | Inject context data for rendering | Quote author resolution, attachment data injection |
| `ContentPostRenderEvent` | After plugin render chain | Post-process final HTML | Search highlighting, lazy-load image rewriting |

### Example Content Plugins (NOT in core — shown for reference)

| Plugin | Priority | Parse | Render |
|--------|----------|-------|--------|
| BBCodePlugin | 100 | Validate BBCode syntax, normalize tags | Convert `[b]text[/b]` → `<strong>text</strong>` |
| MarkdownPlugin | 100 | Validate markdown syntax | Convert markdown → HTML |
| SmiliesPlugin | 200 | No-op | Replace smiley codes → `<img>` tags (if viewSmilies) |
| AutolinkPlugin | 300 | No-op | Detect URLs/emails → `<a>` tags |
| CensorPlugin | 900 | No-op | Replace censored words (if viewCensored) |
| S9eRendererPlugin | 50 | Produce s9e XML from input | Render s9e XML via s9e library (default engine) |

---

## Plugin Extension Points

External plugins (Polls, ReadTracking, Subscriptions, Attachments) extend the core threads service through three mechanisms:

### 1. Request Decorators

Plugins add data to request DTOs before the operation executes.

```php
<?php declare(strict_types=1);

namespace phpbb\threads\decorator;

interface RequestDecoratorInterface
{
    /** Return the modified request with extra data added */
    public function decorate(object $request): object;

    /** Plugin priority (lower = earlier) */
    public function getPriority(): int;
}
```

**Example — PollPlugin adds poll data to CreateTopicRequest**:

```php
class PollRequestDecorator implements RequestDecoratorInterface
{
    public function decorate(object $request): object
    {
        if (!$request instanceof CreateTopicRequest) {
            return $request;
        }

        // Poll data came from the HTTP request, injected by the controller
        $pollData = $this->extractPollConfig($request);
        if ($pollData !== null) {
            return $request->withExtra('poll_config', $pollData);
        }

        return $request;
    }

    public function getPriority(): int { return 100; }
}
```

### 2. Response Decorators

Plugins enrich response DTOs with additional data for display.

```php
<?php declare(strict_types=1);

namespace phpbb\threads\decorator;

interface ResponseDecoratorInterface
{
    /** Return the modified response with extra data added */
    public function decorate(object $response): object;

    /** Plugin priority (lower = earlier) */
    public function getPriority(): int;
}
```

**Example — PollPlugin adds poll results to TopicViewResponse**:

```php
class PollResponseDecorator implements ResponseDecoratorInterface
{
    public function decorate(object $response): object
    {
        if (!$response instanceof TopicViewResponse) {
            return $response;
        }

        $pollResults = $this->pollService->getResults($response->topic->id);
        if ($pollResults !== null) {
            return $response->withExtra('poll', $pollResults);
        }

        return $response;
    }

    public function getPriority(): int { return 100; }
}
```

**Example — ReadTrackingPlugin adds unread markers**:

```php
class ReadTrackingResponseDecorator implements ResponseDecoratorInterface
{
    public function decorate(object $response): object
    {
        if (!$response instanceof ForumTopicsResponse) {
            return $response;
        }

        $topicIds = array_map(fn($t) => $t->id, $response->topics);
        $unreadMap = $this->trackingService->getUnreadStatus($this->userId, $topicIds);

        return $response->withExtra('unread_map', $unreadMap);
    }

    public function getPriority(): int { return 200; }
}
```

### 3. Event Subscribers

Plugins react to domain events dispatched after transaction commit.

**Example — PollPlugin creates poll on topic creation**:

```php
class PollEventSubscriber
{
    public function onTopicCreated(TopicCreatedEvent $event): void
    {
        $pollConfig = $event->request->getExtra('poll_config');
        if ($pollConfig === null) {
            return;
        }

        $this->pollService->createPoll(
            topicId: $event->topicId,
            config: $pollConfig,
        );
    }
}
```

**Example — AttachmentPlugin adopts orphans on post creation**:

```php
class AttachmentEventSubscriber
{
    public function onPostCreated(PostCreatedEvent $event): void
    {
        $attachmentRefs = $event->request->getExtra('attachment_refs');
        if (empty($attachmentRefs)) {
            return;
        }

        $this->attachmentService->adoptOrphans(
            postId: $event->postId,
            topicId: $event->topicId,
            refs: $attachmentRefs,
        );
    }
}
```

### Plugin Registration

Plugins register themselves via the Symfony DI container using interface tagging:

```yaml
# In plugin's services.yaml
services:
    poll.request_decorator:
        class: phpbb\poll\PollRequestDecorator
        tags: ['phpbb.threads.request_decorator']

    poll.response_decorator:
        class: phpbb\poll\PollResponseDecorator
        tags: ['phpbb.threads.response_decorator']

    poll.event_subscriber:
        class: phpbb\poll\PollEventSubscriber
        tags: ['kernel.event_subscriber']
```

The `DecoratorPipeline` collects tagged decorators at container compilation time, sorts by priority, and executes in order.

---

## Domain Events Catalog

All events are dispatched **after** the database transaction commits. Each event carries enough data for listeners to act without additional queries.

### Topic Lifecycle Events

| Event | Key Payload | Typical Consumers |
|-------|-------------|-------------------|
| `TopicCreatedEvent` | topicId, forumId, posterId, title, firstPostId, visibility, request (with extras) | PollPlugin, SearchPlugin, NotificationPlugin |
| `TopicEditedEvent` | topicId, changedFields (title, type, etc.) | SearchPlugin, CacheInvalidation |
| `TopicLockedEvent` | topicId, newStatus (Locked/Unlocked), actorId | Logging |
| `TopicMovedEvent` | topicId, oldForumId, newForumId, createShadow | SearchPlugin, CacheInvalidation |
| `TopicDeletedEvent` | topicId, forumId, allPostIds[], allPosterIds[] | AttachmentPlugin (cascade), SearchPlugin (deindex), BookmarkCleanup, WatchCleanup |
| `TopicTypeChangedEvent` | topicId, oldType, newType | CacheInvalidation |

### Post Lifecycle Events

| Event | Key Payload | Typical Consumers |
|-------|-------------|-------------------|
| `PostCreatedEvent` | postId, topicId, forumId, posterId, visibility, isFirstPost, request (with extras) | AttachmentPlugin (adopt orphans), SearchPlugin (index), NotificationPlugin (watchers/quotes), DraftService (delete matching), phpbb\user (increment user_posts) |
| `PostEditedEvent` | postId, topicId, forumId, editorId, oldText, newText, editReason | SearchPlugin (reindex), AttachmentPlugin (sync), NotificationPlugin |
| `PostSoftDeletedEvent` | postId, topicId, forumId, posterId, actorId, reason | phpbb\user (decrement user_posts), SearchPlugin |
| `PostRestoredEvent` | postId, topicId, forumId, posterId, actorId | phpbb\user (increment user_posts), SearchPlugin |
| `PostHardDeletedEvent` | postId, topicId, forumId, posterId, wasFirstPost, wasLastPost | AttachmentPlugin (cascade delete), SearchPlugin (deindex), ReportCleanup |

### Cross-Cutting Events

| Event | Key Payload | Typical Consumers |
|-------|-------------|-------------------|
| `VisibilityChangedEvent` | entityType ('post'/'topic'), entityId, topicId, forumId, oldVisibility, newVisibility, affectedPostIds[], actorId | phpbb\user (post counts), SearchPlugin, NotificationPlugin (approval queue) |
| `ForumCountersChangedEvent` | forumId, delta (postsApproved±, topicsApproved±, etc.) | phpbb\hierarchy ForumStatsSubscriber (event-driven, eventual consistency) |

### Draft Events

| Event | Key Payload | Typical Consumers |
|-------|-------------|-------------------|
| `DraftSavedEvent` | draftId, userId, forumId, topicId | (none currently) |
| `DraftDeletedEvent` | draftId, userId | (none currently) |

---

## Request/Response DTOs

### Request DTOs

All request DTOs support plugin extension via `withExtra()`/`getExtra()`, following the hierarchy service pattern.

```php
<?php declare(strict_types=1);

namespace phpbb\threads\dto\request;

use phpbb\threads\enum\TopicType;

class CreateTopicRequest
{
    /** @var array<string, mixed> Plugin-injected extra data */
    private array $extra = [];

    public function __construct(
        public readonly int $forumId,
        public readonly int $posterId,
        public readonly string $title,
        public readonly string $message,        // User input text
        public readonly TopicType $type = TopicType::Normal,
        public readonly int $iconId = 0,
        public readonly bool $noapprove = false, // f_noapprove result from auth
    ) {}

    public function withExtra(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->extra[$key] = $value;
        return $clone;
    }

    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function getAllExtra(): array
    {
        return $this->extra;
    }
}

class CreateReplyRequest
{
    private array $extra = [];

    public function __construct(
        public readonly int $topicId,
        public readonly int $forumId,
        public readonly int $posterId,
        public readonly string $message,          // User input text
        public readonly int $iconId = 0,
        public readonly bool $noapprove = false,
    ) {}

    public function withExtra(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->extra[$key] = $value;
        return $clone;
    }

    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    public function getAllExtra(): array
    {
        return $this->extra;
    }
}

class EditPostRequest
{
    private array $extra = [];

    public function __construct(
        public readonly int $postId,
        public readonly int $editorId,
        public readonly string $message,          // User input text
        public readonly string $editReason = '',
        public readonly bool $noapprove = false,   // If false and post was approved → REAPPROVE
    ) {}

    public function withExtra(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->extra[$key] = $value;
        return $clone;
    }

    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    public function getAllExtra(): array
    {
        return $this->extra;
    }
}

class SoftDeleteRequest
{
    private array $extra = [];

    public function __construct(
        public readonly int $entityId,        // post_id or topic_id
        public readonly int $actorId,
        public readonly string $reason = '',
    ) {}

    public function withExtra(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->extra[$key] = $value;
        return $clone;
    }

    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    public function getAllExtra(): array
    {
        return $this->extra;
    }
}

class RestoreRequest
{
    private array $extra = [];

    public function __construct(
        public readonly int $entityId,        // post_id or topic_id
        public readonly int $actorId,
    ) {}

    public function withExtra(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->extra[$key] = $value;
        return $clone;
    }

    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    public function getAllExtra(): array
    {
        return $this->extra;
    }
}

class HardDeleteRequest
{
    private array $extra = [];

    public function __construct(
        public readonly int $entityId,        // post_id or topic_id
        public readonly int $actorId,
    ) {}

    public function withExtra(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->extra[$key] = $value;
        return $clone;
    }

    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    public function getAllExtra(): array
    {
        return $this->extra;
    }
}

class MoveTopicRequest
{
    private array $extra = [];

    public function __construct(
        public readonly int $topicId,
        public readonly int $newForumId,
        public readonly int $actorId,
        public readonly bool $createShadow = true,
    ) {}

    public function withExtra(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->extra[$key] = $value;
        return $clone;
    }

    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    public function getAllExtra(): array
    {
        return $this->extra;
    }
}

class SaveDraftRequest
{
    public function __construct(
        public readonly int $userId,
        public readonly int $forumId,
        public readonly int $topicId,         // 0 = new topic
        public readonly string $title,
        public readonly string $message,      // User input text
    ) {}
}
```

### Response DTOs

```php
<?php declare(strict_types=1);

namespace phpbb\threads\dto\response;

use phpbb\threads\entity\Topic;
use phpbb\threads\entity\Post;

class TopicViewResponse
{
    /** @var array<string, mixed> Plugin-injected extra data */
    private array $extra = [];

    /**
     * @param Post[] $posts
     */
    public function __construct(
        public readonly Topic $topic,
        public readonly array $posts,
        public readonly int $totalPosts,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    public function withExtra(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->extra[$key] = $value;
        return $clone;
    }

    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    public function getAllExtra(): array
    {
        return $this->extra;
    }
}

class ForumTopicsResponse
{
    private array $extra = [];

    /**
     * @param Topic[] $topics
     */
    public function __construct(
        public readonly array $topics,
        public readonly int $totalTopics,
        public readonly int $page,
        public readonly int $perPage,
    ) {}

    public function withExtra(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->extra[$key] = $value;
        return $clone;
    }

    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    public function getAllExtra(): array
    {
        return $this->extra;
    }
}
```

---

## Repository Contracts

### TopicRepositoryInterface

```php
<?php declare(strict_types=1);

namespace phpbb\threads\contract;

use phpbb\threads\entity\Topic;

interface TopicRepositoryInterface
{
    public function find(int $topicId): ?Topic;

    /** @return array<int, Topic> keyed by topic_id */
    public function findByIds(array $topicIds): array;

    /**
     * Two-phase query: fetch topic IDs first (with pagination), then hydrate.
     * @return array{topics: Topic[], total: int}
     */
    public function findByForum(
        int $forumId,
        string $visibilitySql,
        int $offset,
        int $limit,
        string $sortBy = 'last_post_time',
        string $sortDir = 'DESC',
    ): array;

    /**
     * @param array<string, mixed> $data Column => value
     * @return int New topic_id
     */
    public function create(array $data): int;

    /** @param array<string, mixed> $data Columns to update */
    public function update(int $topicId, array $data): void;

    /** Hard delete topic row */
    public function delete(int $topicId): void;

    /** Find global announcements (shown in all forums) */
    /** @return Topic[] */
    public function findGlobalAnnouncements(string $visibilitySql): array;
}
```

### PostRepositoryInterface

```php
<?php declare(strict_types=1);

namespace phpbb\threads\contract;

use phpbb\threads\entity\Post;

interface PostRepositoryInterface
{
    public function find(int $postId): ?Post;

    /** @return array<int, Post> keyed by post_id */
    public function findByIds(array $postIds): array;

    /**
     * Two-phase paginated query within a topic.
     * Phase 1: SELECT post_id with visibility filter + pagination
     * Phase 2: SELECT full rows for those IDs
     * @return array{posts: Post[], total: int}
     */
    public function findByTopic(
        int $topicId,
        string $visibilitySql,
        int $offset,
        int $limit,
        string $sortDir = 'ASC',
    ): array;

    /**
     * @param array<string, mixed> $data Column => value
     * @return int New post_id
     */
    public function create(array $data): int;

    /** @param array<string, mixed> $data Columns to update */
    public function update(int $postId, array $data): void;

    /** Hard delete post row */
    public function delete(int $postId): void;

    /**
     * Get all post IDs in a topic (for cascade operations).
     * @return int[]
     */
    public function findPostIds(int $topicId, ?string $visibilitySql = null): array;

    /**
     * Batch update visibility for multiple posts (cascade).
     * @param int[] $postIds
     */
    public function batchUpdateVisibility(
        array $postIds,
        int $newVisibility,
        int $deleteTime = 0,
        int $deleteUserId = 0,
        string $deleteReason = '',
    ): void;
}
```

### DraftRepositoryInterface

```php
<?php declare(strict_types=1);

namespace phpbb\threads\contract;

use phpbb\threads\entity\Draft;

interface DraftRepositoryInterface
{
    public function find(int $draftId): ?Draft;

    /** @return Draft[] */
    public function findByUser(int $userId, ?int $forumId = null): array;

    /**
     * @param array<string, mixed> $data
     * @return int New draft_id
     */
    public function save(array $data): int;

    public function delete(int $draftId): void;

    /** Delete all drafts for a user in a specific topic (cleanup on post submit) */
    public function deleteByUserAndTopic(int $userId, int $topicId): void;
}
```

---

## Visibility State Machine

### State Diagram

```
                        NEW POST
                           │
                   ┌───────┴───────┐
                   │ noapprove?    │
                   └───┬───────┬───┘
                       │YES    │NO
                       ▼       ▼
                 ┌──────────┐  ┌──────────────┐
                 │ APPROVED │  │ UNAPPROVED   │
                 │   (1)    │  │   (0)        │
                 └────┬─────┘  └──────┬───────┘
                      │               │
        ┌─────────────┤               │
        │             │    approve    │
        │   edit(no   │───────────────┤
        │   noapprove)│               │
        │             ▼               │    disapprove
        │    ┌───────────┐            │    (hard delete)
        │    │ REAPPROVE │────────────┼──────────────────┐
        │    │   (3)     │  approve   │                  │
        │    └───────────┘            │                  │
        │                             │                  │
        │   soft_delete               │                  │
        │        │                    │                  │
        │        ▼                    │                  │
        │   ┌──────────┐             │                  │
        │   │ DELETED  │ ◄───────────┘                  │
        │   │   (2)    │    soft_delete                  │
        │   └────┬─────┘                                 │
        │        │                                       │
        │    restore                                     │
        │        │                                       │
        │        ▼                                       │
        │   ┌──────────┐                                 │
        │   │ APPROVED │                                 │
        │   └──────────┘                                 │
        │                                                │
        │   hard_delete (ANY STATE)                      │
        │        │                                       │
        │        ▼                                       │
        │   ┌──────────────┐                             │
        └──►│ ROW REMOVED  │ ◄───────────────────────────┘
            └──────────────┘
```

### Allowed Transitions

| From | To | Trigger | Guard |
|------|----|---------|-------|
| — | Approved | New post/topic | `noapprove = true` |
| — | Unapproved | New post/topic | `noapprove = false` |
| Unapproved | Approved | Moderator approve | — |
| Unapproved | *(row deleted)* | Moderator disapprove | Hard delete |
| Approved | Deleted | Soft-delete | — |
| Approved | Reapprove | Edit without noapprove | Only if previously approved |
| Deleted | Approved | Restore | Was soft-deleted |
| Reapprove | Approved | Re-approve | — |
| *(any)* | *(row deleted)* | Hard delete | — |

### Counter Effects per Transition

| Transition | topic_posts change | forum_posts change | num_posts | user_posts |
|------------|-------------------|-------------------|-----------|------------|
| → Approved (new) | approved +1 | approved +1 | +1 | +1 (event) |
| → Unapproved (new) | unapproved +1 | unapproved +1 | — | — |
| Unapproved → Approved | unapproved -1, approved +1 | same | +1 | +1 (event) |
| Approved → Deleted | approved -1, softdeleted +1 | same | -1 | -1 (event) |
| Approved → Reapprove | approved -1, unapproved +1 | same | -1 | -1 (event) |
| Deleted → Approved | softdeleted -1, approved +1 | same | +1 | +1 (event) |
| Reapprove → Approved | unapproved -1, approved +1 | same | +1 | +1 (event) |
| Hard delete (was Approved) | approved -1 | approved -1 | -1 | -1 (event) |
| Hard delete (was Unapproved) | unapproved -1 | unapproved -1 | — | — |
| Hard delete (was Deleted) | softdeleted -1 | softdeleted -1 | — | — |

Note: "user_posts" changes are dispatched as events consumed by `phpbb\user`, not updated in-transaction by the threads service.

### Cascade Rules

**Topic soft-delete** (Approved → Deleted):
1. Set `topic_visibility = 2` (Deleted)
2. Find all posts WHERE `post_visibility = 1` (Approved) in the topic
3. Batch-update those posts: `post_visibility = 2`, `post_delete_time = topic_delete_time`
4. Adjust counters for each batch post: approved → softdeleted
5. Leave individually-unapproved and individually-deleted posts untouched

**Topic restore** (Deleted → Approved):
1. Set `topic_visibility = 1` (Approved)
2. Find all posts WHERE `post_delete_time = topic_delete_time` AND `post_visibility = 2`
3. Restore only those posts (cascade-deleted ones, not individually-deleted)
4. Adjust counters: softdeleted → approved

---

## Counter Management

### Counter Tiers

| Tier | Counters | Timing | Reason |
|------|----------|--------|--------|
| **Sync (in-transaction)** | topic_posts_approved/unapproved/softdeleted | Same DB transaction (threads-owned tables only) | Critical UX — topic detail shows these |
| **Event-driven (post-commit)** | forum_posts_*, forum_topics_*, num_posts, num_topics | Via domain events → phpbb\hierarchy ForumStatsSubscriber | Threads is unaware of Hierarchy; eventual consistency; see COUNTER_PATTERN.md |
| **Event-driven (post-commit)** | user_posts, user_lastpost_time | Via domain events → phpbb\user listener | Clean service boundary — user entity owned by phpbb\user |
| **Reconciliation (batch)** | All of the above | Periodic cron / admin action | Safety net — catches any drift from bugs |

### Counter Matrix: Which Operation Affects Which Counters

| Operation | topic_posts | forum_posts | forum_topics | num_posts | num_topics |
|-----------|-------------|-------------|--------------|-----------|------------|
| createTopic (approved) | approved+1 | approved+1 | approved+1 | +1 | +1 |
| createTopic (unapproved) | unapproved+1 | unapproved+1 | unapproved+1 | — | — |
| createReply (approved) | approved+1 | approved+1 | — | +1 | — |
| createReply (unapproved) | unapproved+1 | unapproved+1 | — | — | — |
| approve post | transfer: unapproved→approved | same | — | +1 | — |
| approve topic | transfer: unapproved→approved | + cascade posts | transfer | +1 | +1 |
| softDelete post | transfer: approved→softdeleted | same | — | -1 | — |
| softDelete topic | cascade posts | cascade + transfer: approved→softdeleted | — | -(count) | -1 |
| restore post | transfer: softdeleted→approved | same | — | +1 | — |
| restore topic | cascade posts | cascade + transfer: softdeleted→approved | — | +(count) | +1 |
| hardDelete post | -(current vis) | same | — | if approved: -1 | — |
| hardDelete topic | -(all posts) | same | -(current vis) | -(approved) | -1 if approved |
| moveTopic | — | old -N, new +N | old -1, new +1 | — | — |

### ForumCountersChangedEvent

Forum counter updates are communicated to `phpbb\hierarchy` via domain events. Threads emits `ForumCountersChangedEvent` as part of its `DomainEventCollection` return — Hierarchy's `ForumStatsSubscriber` consumes it. **Threads has zero awareness of Hierarchy** (no imports, no direct calls).

```php
// Inside CounterService — builds event data, does NOT call hierarchy
$events->add(new ForumCountersChangedEvent(
    entityId: $forumId,
    actorId: $actorId,
    delta: new ForumStatsDelta(
        postsApproved: $approvedDelta,
        postsUnapproved: $unapprovedDelta,
        postsSoftdeleted: $softdeletedDelta,
        topicsApproved: $topicApprovedDelta,
        topicsUnapproved: $topicUnapprovedDelta,
        topicsSoftdeleted: $topicSoftdeletedDelta,
    ),
));
```

Hierarchy's `ForumStatsSubscriber` listens for this event and updates `phpbb_forums` counter columns. See `COUNTER_PATTERN.md` for the tiered counter standard and `cross-cutting-decisions-plan.md` D8.
```

---

## Error Handling

### Domain Exceptions

| Exception | When thrown | HTTP mapping |
|-----------|-----------|-------------|
| `TopicNotFoundException` | Topic ID doesn't exist in DB | 404 |
| `PostNotFoundException` | Post ID doesn't exist in DB | 404 |
| `DraftNotFoundException` | Draft ID doesn't exist or doesn't belong to user | 404 |
| `InvalidVisibilityTransitionException` | Attempted transition not in allowed transitions | 409 Conflict |
| `TopicLockedException` | Attempted reply/edit on locked topic | 423 Locked |

No auth exceptions — those are handled externally by `phpbb\auth` middleware in the API layer.

---

## Integration Contracts

### With phpbb\hierarchy

Threads is **completely unaware** of Hierarchy — zero imports, zero direct calls. Communication is exclusively via domain events:

| Event | Direction | Timing | Consumer |
|-------|-----------|--------|----------|
| `TopicCreatedEvent` | threads → hierarchy | Post-commit (event-driven) | `ForumStatsSubscriber`: increment forum topic/post counters, update last post |
| `PostCreatedEvent` | threads → hierarchy | Post-commit (event-driven) | `ForumStatsSubscriber`: increment forum post counter, update last post |
| `VisibilityChangedEvent` | threads → hierarchy | Post-commit (event-driven) | `ForumStatsSubscriber`: transfer between visibility buckets |
| `ForumCountersChangedEvent` | threads → hierarchy | Post-commit (event-driven) | `ForumStatsSubscriber`: apply counter delta |
| `TopicDeletedEvent` | threads → hierarchy | Post-commit (event-driven) | `ForumStatsSubscriber`: decrement counters, recalculate last post |
| `TopicMovedEvent` | threads → hierarchy | Post-commit (event-driven) | `ForumStatsSubscriber`: transfer counters between forums |

Eventual consistency is accepted — forum counters may be stale for one request cycle. Self-healing via `recalculateForumStats()` cron job (see COUNTER_PATTERN.md).

### With phpbb\auth

| Check | Provider | Consumer | Pattern |
|-------|----------|----------|---------|
| f_post, f_reply, m_edit, m_delete, etc. | phpbb\auth | API middleware | External — before calling ThreadsService |
| f_noapprove | phpbb\auth | API controller | Passed as `noapprove` boolean in request DTO |

The threads service receives the noapprove result as a DTO field, NOT by calling auth internally. This keeps the service fully auth-unaware.

### With phpbb\user

| Integration | Direction | Timing | Mechanism |
|-------------|-----------|--------|-----------|
| user_posts increment | threads → user | Post-commit event | `PostCreatedEvent` → user listener |
| user_posts decrement | threads → user | Post-commit event | `PostSoftDeletedEvent` → user listener |
| user_lastpost_time | threads → user | Post-commit event | `PostCreatedEvent` → user listener |
| poster_id, poster_name | user → threads | Data reference | Value copied into Post/Topic at creation |

---

## Database Schema (Target)

### phpbb_topics (simplified, key columns)

```sql
CREATE TABLE phpbb_topics (
    topic_id            int unsigned AUTO_INCREMENT PRIMARY KEY,
    forum_id            int unsigned NOT NULL DEFAULT 0,
    topic_title         varchar(255) NOT NULL DEFAULT '',
    topic_poster        int unsigned NOT NULL DEFAULT 0,
    topic_time          int unsigned NOT NULL DEFAULT 0,
    topic_type          tinyint NOT NULL DEFAULT 0,       -- TopicType enum
    topic_status        tinyint NOT NULL DEFAULT 0,       -- TopicStatus enum
    topic_visibility    tinyint NOT NULL DEFAULT 0,       -- Visibility enum
    icon_id             int unsigned NOT NULL DEFAULT 0,

    -- Denormalized first post
    topic_first_post_id         int unsigned NOT NULL DEFAULT 0,
    topic_first_poster_name     varchar(255) NOT NULL DEFAULT '',
    topic_first_poster_colour   varchar(6) NOT NULL DEFAULT '',

    -- Denormalized last post
    topic_last_post_id          int unsigned NOT NULL DEFAULT 0,
    topic_last_poster_id        int unsigned NOT NULL DEFAULT 0,
    topic_last_poster_name      varchar(255) NOT NULL DEFAULT '',
    topic_last_poster_colour    varchar(6) NOT NULL DEFAULT '',
    topic_last_post_subject     varchar(255) NOT NULL DEFAULT '',
    topic_last_post_time        int unsigned NOT NULL DEFAULT 0,

    -- Counters
    topic_posts_approved      int unsigned NOT NULL DEFAULT 0,
    topic_posts_unapproved    int unsigned NOT NULL DEFAULT 0,
    topic_posts_softdeleted   int unsigned NOT NULL DEFAULT 0,
    topic_views               int unsigned NOT NULL DEFAULT 0,

    -- Announce/Sticky
    topic_time_limit          int unsigned NOT NULL DEFAULT 0,

    -- Move
    topic_moved_id            int unsigned NOT NULL DEFAULT 0,

    -- Bump
    topic_bumped              tinyint(1) NOT NULL DEFAULT 0,
    topic_bumper              int unsigned NOT NULL DEFAULT 0,

    -- Soft-delete info
    topic_delete_time         int unsigned NOT NULL DEFAULT 0,
    topic_delete_user         int unsigned NOT NULL DEFAULT 0,
    topic_delete_reason       varchar(255) NOT NULL DEFAULT '',

    -- Flags
    topic_attachment           tinyint(1) NOT NULL DEFAULT 0,
    topic_reported             tinyint(1) NOT NULL DEFAULT 0,

    INDEX fid_time_moved (forum_id, topic_last_post_time, topic_moved_id),
    INDEX forum_vis_last (forum_id, topic_visibility, topic_last_post_id),
    INDEX latest_topics (forum_id, topic_last_post_time, topic_last_post_id, topic_moved_id)
);
```

### phpbb_posts (simplified, key columns)

```sql
CREATE TABLE phpbb_posts (
    post_id             int unsigned AUTO_INCREMENT PRIMARY KEY,
    topic_id            int unsigned NOT NULL DEFAULT 0,
    forum_id            int unsigned NOT NULL DEFAULT 0,     -- Denormalized from topic

    -- Author
    poster_id           int unsigned NOT NULL DEFAULT 0,
    poster_ip           varchar(40) NOT NULL DEFAULT '',
    post_username       varchar(255) NOT NULL DEFAULT '',     -- Guest name
    post_time           int unsigned NOT NULL DEFAULT 0,

    -- Content — RAW TEXT ONLY
    post_text           mediumtext NOT NULL,                   -- User's original text
    post_subject        varchar(255) NOT NULL DEFAULT '',
    icon_id             int unsigned NOT NULL DEFAULT 0,

    -- State
    post_visibility     tinyint NOT NULL DEFAULT 0,           -- Visibility enum
    post_postcount      tinyint(1) unsigned NOT NULL DEFAULT 1,

    -- Edit tracking
    post_edit_time      int unsigned NOT NULL DEFAULT 0,
    post_edit_user      int unsigned NOT NULL DEFAULT 0,
    post_edit_count     smallint unsigned NOT NULL DEFAULT 0,
    post_edit_reason    varchar(255) NOT NULL DEFAULT '',
    post_edit_locked    tinyint(1) NOT NULL DEFAULT 0,

    -- Soft-delete info
    post_delete_time    int unsigned NOT NULL DEFAULT 0,
    post_delete_user    int unsigned NOT NULL DEFAULT 0,
    post_delete_reason  varchar(255) NOT NULL DEFAULT '',

    -- Flags
    post_attachment      tinyint(1) NOT NULL DEFAULT 0,
    post_reported        tinyint(1) NOT NULL DEFAULT 0,

    INDEX tid_post_time (topic_id, post_time),
    INDEX poster_id (poster_id),
    INDEX post_visibility (post_visibility),
    INDEX tid_vis_time (topic_id, post_visibility, post_time)
);
```

### phpbb_drafts

```sql
CREATE TABLE phpbb_drafts (
    draft_id            int unsigned AUTO_INCREMENT PRIMARY KEY,
    user_id             int unsigned NOT NULL DEFAULT 0,
    topic_id            int unsigned NOT NULL DEFAULT 0,     -- 0 = new topic
    forum_id            int unsigned NOT NULL DEFAULT 0,
    save_time           int unsigned NOT NULL DEFAULT 0,
    draft_subject       varchar(255) NOT NULL DEFAULT '',
    draft_message       mediumtext NOT NULL,                  -- User input text

    INDEX user_id (user_id)
);
```

**Note**: Poll tables (`phpbb_poll_options`, `phpbb_poll_votes`) are owned by the PollPlugin. Read tracking tables (`phpbb_topics_track`, `phpbb_topics_posted`) are owned by the ReadTrackingPlugin. Subscription tables (`phpbb_topics_watch`, `phpbb_bookmarks`) are owned by the SubscriptionPlugin. Attachment tables are owned by the AttachmentPlugin.

---

## Data Flow

### Create Reply — Full Flow

```
API Controller
  │ Auth middleware validated: f_reply, f_noapprove → noapprove=true
  ▼
CreateReplyRequest { topicId, forumId, posterId, message (raw), noapprove }
  │
  ├──► RequestDecoratorChain
  │      ├── AttachmentDecorator: adds extra['attachment_refs']
  │      └── (other plugin decorators)
  ▼
ThreadsService.createReply(request)
  │
  ├── ContentPipeline.parse(rawText, context)  ← validates/normalizes text
  │     fires ContentPreParseEvent, ContentPostParseEvent
  │
  ├── Determine visibility: noapprove ? Approved : Unapproved
  │
  ├── BEGIN TRANSACTION
  │     ├── PostRepository.create(postData)         → post_id
  │     ├── CounterService.incrementPostCounters(topicId, forumId, visibility)
  │     │     └── UPDATE topics SET topic_posts_{vis}+1
  │     ├── TopicMetadataService.recalculateLastPost(topicId)
  │     └── COMMIT
  │
  ├── Build DomainEventCollection:
  │     ├── PostCreatedEvent (for search, notifications, user, etc.)
  │     └── ForumCountersChangedEvent (for hierarchy ForumStatsSubscriber)
  │
  ├── Controller dispatches events post-commit
  │     ├── ForumStatsSubscriber (hierarchy): update forum counters + last post
  │     ├── AttachmentPlugin: adopt orphans (attachment_refs from extra)
  │     ├── SearchPlugin: index new post
  │     ├── NotificationPlugin: notify watchers/quoted users
  │     ├── DraftService listener: delete matching draft
  │     ├── ReadTrackingPlugin: mark as read for author
  │     └── phpbb\user listener: increment user_posts, update lastpost_time
  │
  └── DomainEventCollection returned to caller
```

### Display Topic — Full Flow

```
API Controller
  │ Auth middleware: f_read validated
  │ Auth provides: allowed visibility states for this user
  ▼
GetTopicPostsRequest { topicId, page, perPage }
  │
  ▼
ThreadsService.getTopicWithPosts(request)
  │
  ├── TopicRepository.find(topicId)                  → Topic entity
  │
  ├── VisibilityService.getVisibilitySql([Approved, ...])  → SQL fragment
  │
  ├── PostRepository.findByTopic(topicId, visSQL, offset, limit)
  │     Phase 1: SELECT post_id WHERE topic_id=? AND {visSQL} ORDER BY post_time
  │     Phase 2: SELECT * FROM posts WHERE post_id IN (...)
  │     → Post[] entities
  │
  ├── ContentPipeline.render(post.postText, context) FOR EACH post
  │     fires ContentPreRenderEvent, ContentPostRenderEvent
  │     BBCode/Markdown/Smilies plugins transform raw text → HTML
  │     Censor plugin applies word filtering
  │     → rendered HTML (not persisted, returned in response)
  │
  ├── Build TopicViewResponse { topic, posts, pagination }
  │
  ├── ResponseDecoratorChain
  │     ├── PollDecorator: adds extra['poll'] = poll results
  │     ├── ReadTrackingDecorator: adds extra['unread_posts']
  │     └── AttachmentDecorator: adds extra['attachments'] per post
  │
  └── Return decorated TopicViewResponse
```

---

## Concrete Examples

### Example 1: User creates a new topic with a poll

**Given**: User has `f_post` and `f_noapprove` permissions in forum #5. User submits topic with title "Best PHP framework?" and message "Vote below:" and a poll with 3 options.

**When**: API controller creates `CreateTopicRequest(forumId: 5, posterId: 42, title: "Best PHP framework?", message: "Vote below:", noapprove: true)`. PollRequestDecorator adds `extra['poll_config'] = PollConfig(title: "Which?", options: [...], maxOptions: 1)`.

**Then**:
1. ThreadsService creates topic (visibility=Approved) and first post with s9e-parsed text "Vote below:"
2. CounterService increments topic_posts_approved+1 (topic-level, in-transaction)
3. DomainEventCollection returned with TopicCreatedEvent + ForumCountersChangedEvent
4. Controller dispatches events: ForumStatsSubscriber updates forum counters; PollPlugin creates poll_options rows
5. Response includes DomainEventCollection with topicId, postId

### Example 2: Moderator soft-deletes a topic with 15 posts

**Given**: Topic #100 in forum #5 has 15 posts: 12 approved, 2 unapproved, 1 already soft-deleted.

**When**: Moderator calls `softDeleteTopic(SoftDeleteRequest(entityId: 100, actorId: 99, reason: "Spam"))`.

**Then**:
1. VisibilityService sets topic_visibility = Deleted
2. Cascade: 12 approved posts → Deleted (post_delete_time = topic_delete_time). 2 unapproved and 1 already-deleted untouched.
3. CounterService: topic approved→softdeleted; topic-level counters adjusted in-transaction. ForumCountersChangedEvent emitted: posts_approved -12, posts_softdeleted +12, topics_approved -1, topics_softdeleted +1; num_posts -12, num_topics -1
4. TopicMetadataService: recalculates last post (now null/empty for approved posts)
5. VisibilityChangedEvent dispatched: affectedPostIds = [12 post IDs]
6. phpbb\user listener: decrements user_posts for each of the 12 posters (event-driven)

### Example 3: Displaying a topic page with per-user rendering

**Given**: Topic #50 has 30 posts. User #10 has viewSmilies=false. Page 2 requested (posts 16-30).

**When**: Controller calls `getTopicWithPosts(GetTopicPostsRequest(topicId: 50, page: 2, perPage: 15))`.

**Then**:
1. PostRepository fetches post IDs 16-30 (two-phase: IDs first, then full rows)
2. ContentPipeline.render() called for each of the 15 posts — consults `encoding_engine` (s9e for legacy, future formats via engine column) — with `ContentContext(viewSmilies: false)`
3. BBCodePlugin renders `[b]bold[/b]` → `<strong>bold</strong>` but SmiliesPlugin skips smiley→image conversion because viewSmilies=false
4. CensorPlugin applies word filtering
5. PollResponseDecorator adds poll results to TopicViewResponse extras
6. ReadTrackingResponseDecorator marks which posts are unread for user #10
7. Final TopicViewResponse returned with rendered HTML per post (not persisted)

---

## Design Decisions

| # | Decision | ADR | Rationale |
|---|----------|-----|-----------|
| 1 | s9e XML default + encoding_engine | ADR-001 (amended) | Preserve existing s9e XML; encoding_engine column for format-aware pipeline; no bulk migration needed |
| 2 | Hybrid content pipeline | ADR-002 | Middleware for formatting order + events for cross-cutting hooks |
| 3 | Lean core + plugin extensions | ADR-003 | Polls, ReadTracking, Subscriptions, Attachments out of core → smaller surface, cleaner boundaries |
| 4 | Event-driven counter propagation | ADR-004 (amended) | Topic counters sync in own tx; forum counters via events to Hierarchy; user counters via events; reconciliation for safety |
| 5 | Dedicated VisibilityService | ADR-005 | Centralizes 4-state machine, counter effects, cascades, SQL generation |
| 6 | Auth-unaware service | ADR-006 | Follows hierarchy pattern; API middleware enforces ACL |
| 7 | DraftService in core | ADR-007 | Simple CRUD, event-based cleanup, good first-implementation candidate |

See [decision-log.md](decision-log.md) for full MADR-format ADRs.

---

## Out of Scope

- **Private messages**: Similar content pipeline but separate bounded context (future `phpbb\pm` service)
- **Search backend**: Consumes `PostCreatedEvent` etc. but search indexing is a separate plugin/service
- **Notification delivery**: Consumes domain events but notification routing/email is external
- **Moderator control panel UI**: UI layer consumes the service API
- **Admin control panel**: Forum configuration lives in `phpbb\hierarchy`
- **Render caching**: Centralized `cache.threads` pool (`TagAwareCacheInterface`) wraps `ContentPipeline.render()` — NOT a threads core concern
- **Attachment storage**: AttachmentPlugin owns file storage, orphan management, MIME validation
- **Poll CRUD**: PollPlugin owns poll creation, voting, results, option management
- **Read tracking**: ReadTrackingPlugin owns per-user read state (DB or cookie strategy)
- **Topic subscriptions/bookmarks**: SubscriptionPlugin owns watch/notify lifecycle
- **Rate limiting / flood control**: API middleware concern, not service-level

---

## Success Criteria

1. **Counter accuracy**: All denormalized counters match `COUNT(*)` reality after any operation sequence (verified by reconciliation)
2. **Visibility correctness**: All transitions follow the state machine; cascade operations produce deterministic post-visibility states
3. **Plugin isolation**: Core threads service functions correctly with ZERO content plugins registered (stored text returned as-is for the active encoding engine)
4. **Event completeness**: Every state-changing operation returns a `DomainEventCollection` with sufficient data for all known consumers (Hierarchy ForumStatsSubscriber, Search, Notifications, Attachments, User)
5. **Auth-unaware guarantee**: No import of `phpbb\auth` anywhere in the `phpbb\threads` namespace
6. **Hierarchy-unaware guarantee**: No import of `phpbb\hierarchy` anywhere in the `phpbb\threads` namespace — all communication via domain events
6. **Render-time flexibility**: Two users viewing the same post with different preferences (viewSmilies, viewCensored) get different HTML output

---

## Migration Notes

### Legacy s9e Content

Existing posts store s9e XML in `post_text`. The s9e format is **preserved as the default** — no migration needed:

1. **`encoding_engine` column** (schema change): `ALTER TABLE phpbb_posts ADD COLUMN encoding_engine VARCHAR(16) NOT NULL DEFAULT 's9e';` — all existing posts automatically tagged as s9e.
2. **S9eRendererPlugin** (priority 50): The primary content plugin that renders s9e XML via the s9e PHP library. This is the **default rendering path**, not a compatibility shim.
3. **Future format migration**: To migrate individual posts to a new format (e.g., raw BBCode, Markdown), update `encoding_engine` and rewrite `post_text` per-post. No bulk migration required.

See `cross-cutting-decisions-plan.md` D7 for the full decision rationale.

### Counter Recalculation

On migration, run `CounterService::syncForumCounters()` and `CounterService::syncTopicCounters()` for all entities to ensure counters match reality. This handles any drift from legacy bugs.

### Visibility Values

The visibility integer values are **unchanged** from legacy:
- 0 = Unapproved (was `ITEM_UNAPPROVED`)
- 1 = Approved (was `ITEM_APPROVED`)
- 2 = Deleted (was `ITEM_DELETED`)
- 3 = Reapprove (was `ITEM_REAPPROVE`)

No data migration needed for visibility columns.

### New Index

Add composite index for the common "approved posts in topic sorted by time" query:

```sql
CREATE INDEX tid_vis_time ON phpbb_posts (topic_id, post_visibility, post_time);
```
