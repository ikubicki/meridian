# High-Level Design: `phpbb\hierarchy` Service

## Design Overview

**Business context**: The phpBB forum hierarchy — categories, forums, and links — is managed by ~4000 LOC of legacy procedural code spread across `acp_forums.php` (2245 LOC), `display_forums()` (700 LOC), `markread()` (230 LOC), and an unused OOP `nestedset_forum` class. This code is untestable, un-reusable, and blocks the migration to a modern API-driven architecture. The `phpbb\hierarchy` service replaces this with a clean, extensible OOP layer.

**Chosen approach**: A **five-service decomposition** (`HierarchyService` facade, `ForumRepository`, `TreeService`, `TrackingService`, `SubscriptionService`) built on a **single `Forum` entity with typed behavior delegates** via `ForumTypeRegistry`. The nested set algorithm is **ported from legacy `nestedset.php`** to PDO (same math, zero data migration). The API is **event-driven** — service methods return **domain event objects** as primary output. Extensibility uses a **modern event + request/response decorator model**, completely replacing the legacy `service_collection`/tagged services/compiler pass pattern.

**Key decisions:**
- Single `Forum` entity with `ForumType` enum + `ForumTypeBehaviorInterface` delegates registered via `ForumTypeRegistry` (ADR-001)
- Port legacy nested set math to PDO — zero data migration, battle-tested algorithms (ADR-002)
- Five services with clear responsibility boundaries, composed by `HierarchyService` facade (ADR-003)
- **Legacy extension system dropped entirely** — plugins extend via domain events + request/response DTO decorators (ADR-004)
- Service methods return domain event objects; event listeners drive all side effects (ADR-005)
- Hierarchy is ACL-unaware; the display/API layer applies permission filters (ADR-006)
- Dual-path tracking preserved: DB for registered users, cookies for anonymous (ADR-007)

---

## Architecture

### System Context (C4 Level 1)

```
                              ┌───────────────────┐
                              │   Admin (ACP)      │
                              │   End User (Web)   │
                              │   API Consumer      │
                              └────────┬───────────┘
                                       │ HTTP
                                       ▼
                              ┌───────────────────┐
                              │   phpBB App Layer  │
                              │  Controllers/API   │
                              │  (applies ACL)     │
                              └────────┬───────────┘
                                       │ PHP method calls
                                       ▼
┌──────────────┐     ┌───────────────────────────────────┐     ┌──────────────┐
│ phpbb\auth   │◄────│      phpbb\hierarchy              │────►│ phpbb\user   │
│ (ACL filter) │     │      Service Layer                │     │ (user_id,    │
└──────────────┘     │                                   │     │  lastmark)   │
                     │  Events dispatched to listeners   │     └──────────────┘
                     └───────────┬───────────────────────┘
                                 │                    ▲
                                 │ PDO                │ Event listeners
                                 ▼                    │
                     ┌───────────────────┐   ┌────────┴───────┐
                     │     MySQL / DB    │   │  Plugin Event   │
                     │  phpbb_forums     │   │  Listeners      │
                     │  phpbb_forums_*   │   │  (extensions)   │
                     └───────────────────┘   └────────────────┘
```

**External systems**:
- **phpbb\auth** — Called by the display/API layer (NOT by hierarchy) to filter forums by `f_list`/`f_read`
- **phpbb\user** — Provides `user_id` and `user_lastmark` as context; hierarchy fires events that user-side listeners consume
- **phpbb\notification** — Consumes subscriber lists from `SubscriptionService` for email delivery
- **phpbb\cache** — Listens to hierarchy domain events to invalidate SQL caches

### Container Overview (C4 Level 2)

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        phpbb\hierarchy                                  │
│                                                                         │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │                     HierarchyService (Facade)                     │  │
│  │                                                                   │  │
│  │  Request DTO ──► RequestDecoratorChain ──► Service Logic          │  │
│  │       ──► Domain Event ──► EventDispatcher ──► ResponseDecorator  │  │
│  │       ──► Response DTO returned to caller                         │  │
│  └──┬─────────┬────────────┬──────────────┬──────────────────────────┘  │
│     │         │            │              │                             │
│  ┌──▼──┐  ┌──▼───┐   ┌───▼────┐   ┌────▼──────┐                      │
│  │Forum│  │Tree  │   │Tracking│   │Subscription│                      │
│  │Repo │  │Svc   │   │Svc     │   │Svc         │                      │
│  │     │  │      │   │        │   │            │                      │
│  │CRUD │  │nested│   │DB path │   │watch/      │                      │
│  │hydra│  │set   │   │cookie  │   │notify      │                      │
│  │tion │  │ops   │   │path    │   │            │                      │
│  └──┬──┘  └──┬───┘   └───┬────┘   └────┬──────┘                      │
│     │        │           │              │                              │
│     └────────┴─────┬─────┴──────────────┘                              │
│                    │                                                    │
│              ┌─────▼──────┐                                             │
│              │    PDO     │                                             │
│              └────────────┘                                             │
│                                                                         │
│  ┌─────────────────────┐  ┌────────────────────────────┐               │
│  │ ForumTypeRegistry   │  │ EventDispatcher             │               │
│  │                     │  │                             │               │
│  │ Category behavior   │  │ ForumCreatedEvent           │               │
│  │ Forum behavior      │  │ ForumMovedEvent             │               │
│  │ Link behavior       │  │ ... → plugin listeners      │               │
│  │ (plugin types)      │  │                             │               │
│  └─────────────────────┘  └────────────────────────────┘               │
│                                                                         │
│  ┌────────────────────────────────────────────────────────────────┐     │
│  │ Request/Response Decorator Pipeline                            │     │
│  │                                                                │     │
│  │  CreateForumRequest ──► [WikiDecorator] ──► [GalleryDeco] ──► │     │
│  │  CreateForumResponse ◄── [WikiDecorator] ◄── final response   │     │
│  └────────────────────────────────────────────────────────────────┘     │
└─────────────────────────────────────────────────────────────────────────┘
```

**Container responsibilities**:

| Container | Tech | Responsibility |
|-----------|------|----------------|
| HierarchyService | PHP 8.2 class | Facade — orchestrates operations, runs decorator pipeline, dispatches events, returns domain events |
| ForumRepository | PHP 8.2 + PDO | CRUD operations on `phpbb_forums`, entity hydration, data validation |
| TreeService | PHP 8.2 + PDO | Nested set operations: insert/remove/move/reorder nodes, tree traversal, advisory locking |
| TrackingService | PHP 8.2 + PDO + cookies | Per-user read status: mark read, check unread, dual DB/cookie strategy |
| SubscriptionService | PHP 8.2 + PDO | Forum watch: subscribe/unsubscribe, eligible subscriber queries, notify status |
| ForumTypeRegistry | PHP 8.2 | Maps `ForumType` → `ForumTypeBehaviorInterface`; plugins register custom types |
| EventDispatcher | Symfony EventDispatcher | Dispatches domain events to registered listeners |
| DecoratorPipeline | PHP 8.2 | Ordered chain of request/response decorators provided by plugins |

---

## Key Components

### Entity & Value Object Model

#### Forum Entity

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\entity;

final class Forum
{
    public function __construct(
        // Identity
        public readonly int $id,
        public readonly string $name,
        public readonly string $description,
        public readonly string $descriptionBitfield,
        public readonly int $descriptionOptions,
        public readonly string $descriptionUid,

        // Hierarchy (nested set)
        public readonly int $parentId,
        public readonly int $leftId,
        public readonly int $rightId,
        public readonly ForumType $type,
        public readonly ForumStatus $status,

        // Display
        public readonly string $image,
        public readonly string $rules,
        public readonly string $rulesLink,
        public readonly string $rulesBitfield,
        public readonly int $rulesOptions,
        public readonly string $rulesUid,
        public readonly bool $displayOnIndex,
        public readonly bool $displaySubforumList,
        public readonly bool $displaySubforumLimit,
        public readonly int $topicsPerPage,
        public readonly int $flags,
        public readonly int $options,

        // Type-specific
        public readonly string $link,           // ForumType::Link only
        public readonly string $password,       // ForumType::Forum only

        // Style
        public readonly int $style,

        // Indexing / icons
        public readonly bool $enableIndexing,
        public readonly bool $enableIcons,

        // Denormalized stats
        public readonly ForumStats $stats,
        public readonly ForumLastPost $lastPost,

        // Prune settings
        public readonly ForumPruneSettings $pruneSettings,

        /** @var array<int, array{name: string, type: int}> Decoded parent chain */
        public readonly array $parents,
    ) {}

    public function isLeaf(): bool
    {
        return $this->rightId - $this->leftId === 1;
    }

    public function descendantCount(): int
    {
        return (int) (($this->rightId - $this->leftId - 1) / 2);
    }

    public function isCategory(): bool
    {
        return $this->type === ForumType::Category;
    }

    public function isForum(): bool
    {
        return $this->type === ForumType::Forum;
    }

    public function isLink(): bool
    {
        return $this->type === ForumType::Link;
    }
}
```

#### ForumType Enum

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\entity;

enum ForumType: int
{
    case Category = 0;
    case Forum    = 1;
    case Link     = 2;
}
```

#### ForumStatus Enum

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\entity;

enum ForumStatus: int
{
    case Unlocked = 0;
    case Locked   = 1;
}
```

#### Value Objects

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\entity;

final readonly class ForumStats
{
    public function __construct(
        public int $postsApproved,
        public int $postsUnapproved,
        public int $postsSoftdeleted,
        public int $topicsApproved,
        public int $topicsUnapproved,
        public int $topicsSoftdeleted,
    ) {}

    public function totalPosts(): int
    {
        return $this->postsApproved + $this->postsUnapproved + $this->postsSoftdeleted;
    }

    public function totalTopics(): int
    {
        return $this->topicsApproved + $this->topicsUnapproved + $this->topicsSoftdeleted;
    }
}

final readonly class ForumLastPost
{
    public function __construct(
        public int $postId,
        public int $posterId,
        public string $subject,
        public int $time,
        public string $posterName,
        public string $posterColour,
    ) {}
}

final readonly class ForumPruneSettings
{
    public function __construct(
        public bool $enabled,
        public int $days,
        public int $viewed,
        public int $frequency,
        public int $next,
        public bool $shadowEnabled,
        public int $shadowDays,
        public int $shadowFrequency,
        public int $shadowNext,
    ) {}
}
```

---

### Request/Response DTOs

These are the **decoration targets** for the plugin decorator pipeline. Plugins wrap these DTOs to add custom data, transform payloads, or inject additional behavior.

#### Request DTOs

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\dto\request;

use phpbb\hierarchy\entity\ForumType;

class CreateForumRequest
{
    /** @var array<string, mixed> Plugin-injected extra data */
    private array $extra = [];

    public function __construct(
        public readonly string $name,
        public readonly ForumType $type,
        public readonly int $parentId = 0,
        public readonly string $description = '',
        public readonly string $link = '',
        public readonly string $image = '',
        public readonly string $rules = '',
        public readonly string $rulesLink = '',
        public readonly string $password = '',
        public readonly int $style = 0,
        public readonly int $topicsPerPage = 0,
        public readonly int $flags = 32,
        public readonly bool $displayOnIndex = true,
        public readonly bool $displaySubforumList = true,
        public readonly bool $enableIndexing = true,
        public readonly bool $enableIcons = true,
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

class UpdateForumRequest
{
    private array $extra = [];

    public function __construct(
        public readonly int $forumId,
        public readonly ?string $name = null,
        public readonly ?ForumType $type = null,
        public readonly ?int $parentId = null,
        public readonly ?string $description = null,
        public readonly ?string $link = null,
        public readonly ?string $image = null,
        public readonly ?string $rules = null,
        public readonly ?string $rulesLink = null,
        public readonly ?string $password = null,
        public readonly ?bool $clearPassword = null,
        public readonly ?int $style = null,
        public readonly ?int $topicsPerPage = null,
        public readonly ?int $flags = null,
        public readonly ?bool $displayOnIndex = null,
        public readonly ?bool $displaySubforumList = null,
        public readonly ?bool $enableIndexing = null,
        public readonly ?bool $enableIcons = null,
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

class MoveForumRequest
{
    private array $extra = [];

    public function __construct(
        public readonly int $forumId,
        public readonly int $newParentId,
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

class DeleteForumRequest
{
    private array $extra = [];

    public function __construct(
        public readonly int $forumId,
        /** @var 'move'|'delete' What to do with posts/topics */
        public readonly string $contentAction,
        public readonly ?int $moveContentTo = null,
        /** @var 'move'|'delete'|null What to do with subforums */
        public readonly ?string $subforumAction = null,
        public readonly ?int $moveSubforumsTo = null,
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

class ReorderForumRequest
{
    private array $extra = [];

    public function __construct(
        public readonly int $forumId,
        public readonly int $delta,
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

#### Response DTOs

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\dto\response;

use phpbb\hierarchy\entity\Forum;

class ForumResponse
{
    /** @var array<string, mixed> Plugin-injected extra data */
    private array $extra = [];

    public function __construct(
        public readonly Forum $forum,
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

class DeleteForumResponse
{
    private array $extra = [];

    public function __construct(
        public readonly int $forumId,
        /** @var int[] IDs of all deleted forums (including subforums) */
        public readonly array $deletedIds,
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

class MoveForumResponse
{
    private array $extra = [];

    public function __construct(
        public readonly Forum $forum,
        public readonly int $oldParentId,
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

class ReorderForumResponse
{
    private array $extra = [];

    public function __construct(
        public readonly Forum $forum,
        public readonly bool $moved,
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

class TreeResponse
{
    private array $extra = [];

    /** @param Forum[] $forums Ordered by left_id (DFS pre-order) */
    public function __construct(
        public readonly array $forums,
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

### Complete Directory Structure

```
src/phpbb/hierarchy/
├── HierarchyService.php
├── HierarchyServiceInterface.php
│
├── dto/
│   ├── request/
│   │   ├── CreateForumRequest.php
│   │   ├── UpdateForumRequest.php
│   │   ├── MoveForumRequest.php
│   │   ├── DeleteForumRequest.php
│   │   └── ReorderForumRequest.php
│   └── response/
│       ├── ForumResponse.php
│       ├── DeleteForumResponse.php
│       ├── MoveForumResponse.php
│       ├── ReorderForumResponse.php
│       └── TreeResponse.php
│
├── entity/
│   ├── Forum.php
│   ├── ForumType.php
│   ├── ForumStatus.php
│   ├── ForumStats.php
│   ├── ForumLastPost.php
│   └── ForumPruneSettings.php
│
├── repository/
│   ├── ForumRepositoryInterface.php
│   └── ForumRepository.php
│
├── tree/
│   ├── TreeServiceInterface.php
│   └── TreeService.php
│
├── tracking/
│   ├── TrackingServiceInterface.php
│   ├── TrackingService.php
│   ├── TrackingStrategyInterface.php
│   ├── DbTrackingStrategy.php
│   └── CookieTrackingStrategy.php
│
├── subscription/
│   ├── SubscriptionServiceInterface.php
│   └── SubscriptionService.php
│
├── type/
│   ├── ForumTypeBehaviorInterface.php
│   ├── ForumTypeRegistry.php
│   ├── CategoryBehavior.php
│   ├── PostingForumBehavior.php
│   └── LinkBehavior.php
│
├── event/
│   ├── ForumCreatedEvent.php
│   ├── ForumUpdatedEvent.php
│   ├── ForumDeletedEvent.php
│   ├── ForumMovedEvent.php
│   ├── ForumReorderedEvent.php
│   ├── ForumMarkedReadEvent.php
│   ├── AllForumsMarkedReadEvent.php
│   ├── ForumSubscribedEvent.php
│   ├── ForumUnsubscribedEvent.php
│   └── RegisterForumTypesEvent.php
│
├── decorator/
│   ├── RequestDecoratorInterface.php
│   ├── ResponseDecoratorInterface.php
│   └── DecoratorPipeline.php
│
└── exception/
    ├── ForumNotFoundException.php
    ├── InvalidForumTypeException.php
    ├── TreeLockException.php
    └── InvalidMoveException.php
```

---

## Service Interfaces with Full PHP 8.2 Signatures

### HierarchyServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy;

use phpbb\hierarchy\dto\request\CreateForumRequest;
use phpbb\hierarchy\dto\request\UpdateForumRequest;
use phpbb\hierarchy\dto\request\MoveForumRequest;
use phpbb\hierarchy\dto\request\DeleteForumRequest;
use phpbb\hierarchy\dto\request\ReorderForumRequest;
use phpbb\hierarchy\dto\response\ForumResponse;
use phpbb\hierarchy\dto\response\DeleteForumResponse;
use phpbb\hierarchy\dto\response\MoveForumResponse;
use phpbb\hierarchy\dto\response\ReorderForumResponse;
use phpbb\hierarchy\dto\response\TreeResponse;
use phpbb\hierarchy\event\ForumCreatedEvent;
use phpbb\hierarchy\event\ForumUpdatedEvent;
use phpbb\hierarchy\event\ForumDeletedEvent;
use phpbb\hierarchy\event\ForumMovedEvent;
use phpbb\hierarchy\event\ForumReorderedEvent;
use phpbb\hierarchy\event\ForumMarkedReadEvent;
use phpbb\hierarchy\event\AllForumsMarkedReadEvent;
use phpbb\hierarchy\event\ForumSubscribedEvent;
use phpbb\hierarchy\event\ForumUnsubscribedEvent;

interface HierarchyServiceInterface
{
    // ── CRUD (return domain events) ──

    /**
     * Create a forum. Runs request decorator chain, inserts forum,
     * positions in tree, dispatches ForumCreatedEvent, runs response decorators.
     *
     * @return ForumCreatedEvent Contains the created forum and decorated response
     */
    public function createForum(CreateForumRequest $request): ForumCreatedEvent;

    /**
     * Update a forum. Runs request decorators, persists changes,
     * dispatches ForumUpdatedEvent, runs response decorators.
     *
     * @return ForumUpdatedEvent Contains old and new forum state
     */
    public function updateForum(UpdateForumRequest $request): ForumUpdatedEvent;

    /**
     * Delete a forum. Runs request decorators, handles content/subforum
     * migration, removes from tree, dispatches ForumDeletedEvent.
     *
     * @return ForumDeletedEvent Contains deleted forum ID and all removed IDs
     */
    public function deleteForum(DeleteForumRequest $request): ForumDeletedEvent;

    // ── Tree Operations (return domain events) ──

    /**
     * Move forum to a new parent. Runs request decorators, reparents in tree,
     * dispatches ForumMovedEvent, runs response decorators.
     *
     * @return ForumMovedEvent Contains forum, old parent, new parent
     */
    public function moveForum(MoveForumRequest $request): ForumMovedEvent;

    /**
     * Reorder forum among siblings.
     *
     * @return ForumReorderedEvent Contains forum and whether it actually moved
     */
    public function reorderForum(ReorderForumRequest $request): ForumReorderedEvent;

    // ── Query (return DTOs, not events — reads are side-effect-free) ──

    public function getForum(int $forumId): ?ForumResponse;

    /** @return TreeResponse Forums ordered by left_id (DFS pre-order) */
    public function getTree(?int $rootId = null): TreeResponse;

    /** @return ForumResponse[] Ancestors from root to item */
    public function getPath(int $forumId): array;

    /** @return TreeResponse Descendants of item */
    public function getSubtree(int $forumId, bool $includeRoot = true): TreeResponse;

    /** @return int[] IDs of direct children */
    public function getChildIds(int $parentId): array;

    // ── Tracking (return domain events) ──

    /**
     * @return ForumMarkedReadEvent
     */
    public function markForumRead(int $userId, int $forumId, int $markTime): ForumMarkedReadEvent;

    /**
     * @return AllForumsMarkedReadEvent
     */
    public function markAllRead(int $userId, int $markTime): AllForumsMarkedReadEvent;

    /**
     * Check if forum is unread. Pure query — no event returned.
     */
    public function isForumUnread(int $userId, int $forumId): bool;

    /**
     * @param int[] $forumIds
     * @return array<int, bool> forumId => unread
     */
    public function getUnreadStatus(int $userId, array $forumIds): array;

    // ── Subscriptions (return domain events) ──

    public function subscribe(int $userId, int $forumId): ForumSubscribedEvent;

    public function unsubscribe(int $userId, int $forumId): ForumUnsubscribedEvent;

    /**
     * Pure query — no event.
     */
    public function isSubscribed(int $userId, int $forumId): bool;

    /**
     * @return int[] User IDs subscribed with notify_status = NOTIFY_YES
     */
    public function getSubscribers(int $forumId, ?int $excludeUserId = null): array;
}
```

### ForumRepositoryInterface

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\repository;

use phpbb\hierarchy\entity\Forum;

interface ForumRepositoryInterface
{
    public function findById(int $forumId): ?Forum;

    /** @return array<int, Forum> keyed by forum_id */
    public function findByIds(array $forumIds): array;

    /** @return Forum[] ordered by left_id */
    public function findAll(): array;

    /** @return Forum[] children of given parent, ordered by left_id */
    public function findByParent(int $parentId): array;

    /**
     * Insert forum row (without nested set positioning).
     * @param array<string, mixed> $data Column => value
     * @return int New forum_id
     */
    public function insert(array $data): int;

    /** @param array<string, mixed> $data Columns to update */
    public function update(int $forumId, array $data): void;

    public function delete(int $forumId): void;

    /** @param int[] $forumIds */
    public function deleteMultiple(array $forumIds): void;

    /** Reset all 6 stats counters to zero */
    public function resetStats(int $forumId): void;

    /**
     * Invalidate forum_parents cache.
     * @param int[]|null $forumIds Null = all forums
     */
    public function invalidateParentCache(?array $forumIds = null): void;

    /**
     * Hydrate Forum entity from raw DB row.
     * @param array<string, mixed> $row
     */
    public function hydrate(array $row): Forum;
}
```

### TreeServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\tree;

interface TreeServiceInterface
{
    /**
     * Position a new node under a parent. Sets left_id, right_id, parent_id.
     * Acquires advisory lock internally.
     */
    public function insertNode(int $nodeId, int $parentId): void;

    /**
     * Remove node + entire subtree from nested set.
     * @return int[] IDs of all removed nodes (including the node itself)
     */
    public function removeNode(int $nodeId): array;

    /**
     * Move a node (+ subtree) to a new parent.
     */
    public function changeParent(int $nodeId, int $newParentId): void;

    /**
     * Move all children of one parent to a new parent.
     */
    public function moveChildren(int $fromParentId, int $toParentId): void;

    /**
     * Reorder a node among siblings.
     * @param int $delta Positive = move toward first, negative = move toward last
     * @return bool False if already at boundary
     */
    public function reorder(int $nodeId, int $delta): bool;

    // ── Traversal ──

    /**
     * Get ancestors from root to node (inclusive).
     * @return array<int, array{forum_id: int, parent_id: int, left_id: int, right_id: int}>
     */
    public function getPath(int $nodeId): array;

    /**
     * Get descendants of node ordered by left_id (DFS pre-order).
     * @return array<int, array{forum_id: int, parent_id: int, left_id: int, right_id: int}>
     */
    public function getSubtree(int $nodeId, bool $includeRoot = true): array;

    /**
     * Full tree ordered by left_id.
     * @return array<int, array{forum_id: int, parent_id: int, left_id: int, right_id: int}>
     */
    public function getFullTree(): array;

    /**
     * Get direct children of a node.
     * @return int[] Child node IDs ordered by left_id
     */
    public function getChildIds(int $parentId): array;

    /**
     * Rebuild left_id/right_id from parent_id relationships.
     * Expensive repair operation — O(3n) queries.
     */
    public function regenerate(): void;

    /**
     * Invalidate the forum_parents cached parent chains.
     * @param int[]|null $nodeIds Null = all nodes
     */
    public function invalidateParentCache(?array $nodeIds = null): void;
}
```

### TrackingServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\tracking;

interface TrackingServiceInterface
{
    /**
     * Mark specific forum(s) as read.
     * Deletes topic-level tracking rows older than markTime.
     * @param int|int[] $forumId
     */
    public function markForumsRead(int $userId, int|array $forumId, int $markTime): void;

    /**
     * Mark all forums as read (global reset).
     * Deletes all forums_track + topics_track rows for user.
     */
    public function markAllRead(int $userId, int $markTime): void;

    /**
     * Get mark times for given forums.
     * @param int[] $forumIds
     * @return array<int, int> forumId => markTime (0 if never marked)
     */
    public function getMarkTimes(int $userId, array $forumIds): array;

    /**
     * Check if forum is unread (forum_last_post_time > mark_time).
     */
    public function isUnread(int $userId, int $forumId, int $forumLastPostTime): bool;

    /**
     * Auto-mark forum read if all topics in it are read.
     * @return bool True if forum was auto-marked
     */
    public function autoMarkIfComplete(int $userId, int $forumId, int $forumLastPostTime): bool;
}

interface TrackingStrategyInterface
{
    public function markRead(int $userId, int $forumId, int $markTime): void;
    public function getMarkTime(int $userId, int $forumId): int;
}
```

### SubscriptionServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\subscription;

interface SubscriptionServiceInterface
{
    public function subscribe(int $userId, int $forumId): void;

    public function unsubscribe(int $userId, int $forumId): void;

    /** @param int[] $forumIds Batch unsubscribe */
    public function unsubscribeMultiple(int $userId, array $forumIds): void;

    public function isSubscribed(int $userId, int $forumId): bool;

    /** @return int[] Forum IDs the user is subscribed to */
    public function getUserSubscriptions(int $userId): array;

    /**
     * Get subscribers eligible for notification.
     * @return int[] User IDs with notify_status = NOTIFY_YES
     */
    public function getEligibleSubscribers(int $forumId, ?int $excludeUserId = null): array;

    /**
     * Reset notify_status to NOTIFY_YES on user revisit.
     */
    public function resetNotifyStatus(int $userId, int $forumId): void;

    /**
     * Remove all subscriptions for a deleted forum.
     */
    public function removeForumSubscriptions(int $forumId): void;
}
```

### ForumTypeBehaviorInterface

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\type;

use phpbb\hierarchy\entity\Forum;
use phpbb\hierarchy\entity\ForumType;

interface ForumTypeBehaviorInterface
{
    /** Which ForumType this behavior applies to */
    public function getType(): ForumType;

    /** Human-readable label for this type */
    public function getLabel(): string;

    /** Can this type have child forums? */
    public function canHaveChildren(): bool;

    /** Can this type contain topics/posts? */
    public function canHaveContent(): bool;

    /** Can this type be a redirect link? */
    public function isLink(): bool;

    /**
     * Get the list of editable fields for this type in ACP.
     * @return string[] Field names
     */
    public function getEditableFields(): array;

    /**
     * Validate forum data for this type.
     * @param array<string, mixed> $data
     * @return string[] Error message keys (empty = valid)
     */
    public function validate(array $data): array;

    /**
     * Called when a forum of this type is created.
     * Allows type-specific initialization.
     */
    public function onCreated(Forum $forum): void;

    /**
     * Called before a forum of this type is deleted.
     * Allows type-specific cleanup.
     */
    public function onDeleting(int $forumId): void;
}
```

### ForumTypeRegistry

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\type;

use phpbb\hierarchy\entity\ForumType;
use phpbb\hierarchy\exception\InvalidForumTypeException;

final class ForumTypeRegistry
{
    /** @var array<int, ForumTypeBehaviorInterface> ForumType::value => behavior */
    private array $behaviors = [];

    /**
     * Register a behavior for a forum type.
     * Plugins call this to register custom types.
     */
    public function register(ForumTypeBehaviorInterface $behavior): void
    {
        $this->behaviors[$behavior->getType()->value] = $behavior;
    }

    public function get(ForumType $type): ForumTypeBehaviorInterface
    {
        return $this->behaviors[$type->value]
            ?? throw new InvalidForumTypeException("No behavior registered for type: {$type->name}");
    }

    public function has(ForumType $type): bool
    {
        return isset($this->behaviors[$type->value]);
    }

    /** @return ForumTypeBehaviorInterface[] */
    public function all(): array
    {
        return array_values($this->behaviors);
    }
}
```

---

## Domain Event Model

All mutating `HierarchyService` methods return domain event objects. Events are also dispatched to the `EventDispatcher` so listeners can react.

### Event Classes

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\event;

use phpbb\hierarchy\entity\Forum;
use phpbb\hierarchy\dto\response\ForumResponse;
use phpbb\hierarchy\dto\response\DeleteForumResponse;
use phpbb\hierarchy\dto\response\MoveForumResponse;
use phpbb\hierarchy\dto\response\ReorderForumResponse;

final readonly class ForumCreatedEvent
{
    public function __construct(
        public Forum $forum,
        public ForumResponse $response,
    ) {}
}

final readonly class ForumUpdatedEvent
{
    public function __construct(
        public Forum $oldForum,
        public Forum $newForum,
        public ForumResponse $response,
    ) {}
}

final readonly class ForumDeletedEvent
{
    public function __construct(
        public int $forumId,
        /** @var int[] IDs of all deleted forums */
        public array $deletedIds,
        public DeleteForumResponse $response,
    ) {}
}

final readonly class ForumMovedEvent
{
    public function __construct(
        public Forum $forum,
        public int $oldParentId,
        public int $newParentId,
        public MoveForumResponse $response,
    ) {}
}

final readonly class ForumReorderedEvent
{
    public function __construct(
        public Forum $forum,
        public int $delta,
        public bool $moved,
        public ReorderForumResponse $response,
    ) {}
}

final readonly class ForumMarkedReadEvent
{
    public function __construct(
        public int $userId,
        /** @var int[] */
        public array $forumIds,
        public int $markTime,
    ) {}
}

final readonly class AllForumsMarkedReadEvent
{
    /** Listeners should update user_lastmark on this event */
    public function __construct(
        public int $userId,
        public int $markTime,
    ) {}
}

final readonly class ForumSubscribedEvent
{
    public function __construct(
        public int $userId,
        public int $forumId,
    ) {}
}

final readonly class ForumUnsubscribedEvent
{
    public function __construct(
        public int $userId,
        public int $forumId,
    ) {}
}
```

### RegisterForumTypesEvent (boot-time event for plugin type registration)

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\event;

use phpbb\hierarchy\type\ForumTypeBehaviorInterface;
use phpbb\hierarchy\type\ForumTypeRegistry;

final class RegisterForumTypesEvent
{
    public function __construct(
        private readonly ForumTypeRegistry $registry,
    ) {}

    public function register(ForumTypeBehaviorInterface $behavior): void
    {
        $this->registry->register($behavior);
    }

    public function getRegistry(): ForumTypeRegistry
    {
        return $this->registry;
    }
}
```

### How Service Methods Return Events and How Listeners Process Them

**Service returns event to caller:**
```php
// In controller:
$event = $hierarchy->createForum(new CreateForumRequest(
    name: 'Help & Support',
    type: ForumType::Forum,
    parentId: 1,
));

// Caller receives the event — can access the forum entity and decorated response
$forum = $event->forum;
$response = $event->response;
$wikiPageCount = $response->getExtra('wiki_page_count'); // from plugin decorator
```

**Listeners process dispatched events:**
```php
// Cache invalidation listener (core)
class CacheInvalidationListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ForumCreatedEvent::class   => 'onForumChanged',
            ForumUpdatedEvent::class   => 'onForumChanged',
            ForumDeletedEvent::class   => 'onForumChanged',
            ForumMovedEvent::class     => 'onForumChanged',
            ForumReorderedEvent::class => 'onForumChanged',
        ];
    }

    public function onForumChanged(): void
    {
        $this->cache->destroy('sql', FORUMS_TABLE);
    }
}

// User lastmark listener (core)
class UserLastmarkListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [AllForumsMarkedReadEvent::class => 'onAllMarkedRead'];
    }

    public function onAllMarkedRead(AllForumsMarkedReadEvent $event): void
    {
        // UPDATE phpbb_users SET user_lastmark = :time WHERE user_id = :uid
        $this->pdo->prepare('UPDATE phpbb_users SET user_lastmark = ? WHERE user_id = ?')
            ->execute([$event->markTime, $event->userId]);
    }
}
```

---

## Plugin Architecture: Events + Request/Response Decorators

> **CRITICAL**: The legacy phpBB extension system (`service_collection`, tagged services, compiler passes) is **completely dropped**. The new plugin model is based on:
> 1. **Domain events** — plugins subscribe as event listeners
> 2. **Request/response decorators** — plugins wrap DTOs to add custom data
> 3. **ForumType registration** — plugins register types via `RegisterForumTypesEvent`

### Event-Based Extension

Plugins register as standard Symfony `EventSubscriberInterface` listeners. They react to domain events for side effects, validation enrichment, or cross-cutting concerns.

```php
<?php declare(strict_types=1);

namespace acme\wiki_forum\listener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use phpbb\hierarchy\event\ForumCreatedEvent;
use phpbb\hierarchy\event\ForumDeletedEvent;
use phpbb\hierarchy\event\RegisterForumTypesEvent;

final class WikiForumListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            RegisterForumTypesEvent::class => 'onRegisterTypes',
            ForumCreatedEvent::class       => 'onForumCreated',
            ForumDeletedEvent::class       => 'onForumDeleted',
        ];
    }

    public function onRegisterTypes(RegisterForumTypesEvent $event): void
    {
        $event->register(new WikiForumBehavior());
    }

    public function onForumCreated(ForumCreatedEvent $event): void
    {
        if ($event->forum->type !== WikiForumType::Wiki) {
            return;
        }
        $this->initializeWikiStructure($event->forum->id);
    }

    public function onForumDeleted(ForumDeletedEvent $event): void
    {
        foreach ($event->deletedIds as $forumId) {
            $this->cleanupWikiData($forumId);
        }
    }
}
```

### Request Decorator Pattern

Plugins implement `RequestDecoratorInterface` to wrap/transform request DTOs **before** they reach the service logic. Decorators are chained in priority order and can add extra data, validate, or transform.

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\decorator;

interface RequestDecoratorInterface
{
    /**
     * Whether this decorator applies to the given request.
     */
    public function supports(object $request): bool;

    /**
     * Decorate the request. Return the (potentially modified) request.
     * Use $request->withExtra() to add plugin-specific data.
     */
    public function decorateRequest(object $request): object;

    /**
     * Execution priority. Lower = earlier in chain.
     */
    public function getPriority(): int;
}
```

**Example: Wiki Forum request decorator adds wiki-specific fields:**

```php
<?php declare(strict_types=1);

namespace acme\wiki_forum\decorator;

use phpbb\hierarchy\decorator\RequestDecoratorInterface;
use phpbb\hierarchy\dto\request\CreateForumRequest;

final class WikiForumRequestDecorator implements RequestDecoratorInterface
{
    public function supports(object $request): bool
    {
        return $request instanceof CreateForumRequest
            && $request->type === WikiForumType::Wiki;
    }

    public function decorateRequest(object $request): object
    {
        return $request
            ->withExtra('wiki_default_page', 'Main Page')
            ->withExtra('wiki_allow_anonymous_edits', false)
            ->withExtra('wiki_revision_limit', 100);
    }

    public function getPriority(): int
    {
        return 100;
    }
}
```

### Response Decorator Pattern

Plugins implement `ResponseDecoratorInterface` to enrich response DTOs **after** the service produces them.

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\decorator;

interface ResponseDecoratorInterface
{
    public function supports(object $response): bool;

    /**
     * Decorate the response. Return the (potentially enriched) response.
     * Use $response->withExtra() to add plugin-specific data.
     */
    public function decorateResponse(object $response, object $request): object;

    public function getPriority(): int;
}
```

**Example: Wiki Forum response decorator enriches output with wiki metadata:**

```php
<?php declare(strict_types=1);

namespace acme\wiki_forum\decorator;

use phpbb\hierarchy\decorator\ResponseDecoratorInterface;
use phpbb\hierarchy\dto\response\ForumResponse;

final class WikiForumResponseDecorator implements ResponseDecoratorInterface
{
    public function __construct(
        private readonly WikiPageRepository $wikiRepository,
    ) {}

    public function supports(object $response): bool
    {
        return $response instanceof ForumResponse
            && $response->forum->type === WikiForumType::Wiki;
    }

    public function decorateResponse(object $response, object $request): object
    {
        $wikiPageCount = $this->wikiRepository->getPageCount($response->forum->id);
        $lastEdit = $this->wikiRepository->getLastEdit($response->forum->id);

        return $response
            ->withExtra('wiki_page_count', $wikiPageCount)
            ->withExtra('wiki_last_edit', $lastEdit);
    }

    public function getPriority(): int
    {
        return 100;
    }
}
```

### Decorator Pipeline

The `DecoratorPipeline` collects and executes decorators in priority order:

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\decorator;

final class DecoratorPipeline
{
    /** @var RequestDecoratorInterface[] sorted by priority */
    private array $requestDecorators = [];

    /** @var ResponseDecoratorInterface[] sorted by priority */
    private array $responseDecorators = [];

    public function addRequestDecorator(RequestDecoratorInterface $decorator): void
    {
        $this->requestDecorators[] = $decorator;
        usort($this->requestDecorators, fn($a, $b) => $a->getPriority() <=> $b->getPriority());
    }

    public function addResponseDecorator(ResponseDecoratorInterface $decorator): void
    {
        $this->responseDecorators[] = $decorator;
        usort($this->responseDecorators, fn($a, $b) => $a->getPriority() <=> $b->getPriority());
    }

    public function decorateRequest(object $request): object
    {
        foreach ($this->requestDecorators as $decorator) {
            if ($decorator->supports($request)) {
                $request = $decorator->decorateRequest($request);
            }
        }
        return $request;
    }

    public function decorateResponse(object $response, object $request): object
    {
        foreach ($this->responseDecorators as $decorator) {
            if ($decorator->supports($response)) {
                $response = $decorator->decorateResponse($response, $request);
            }
        }
        return $response;
    }
}
```

### ForumType Registration via Event

Plugins register custom types during boot by listening to `RegisterForumTypesEvent`:

```php
// During HierarchyService construction or first use:
public function boot(): void
{
    // Register core types
    $this->typeRegistry->register(new CategoryBehavior());
    $this->typeRegistry->register(new PostingForumBehavior());
    $this->typeRegistry->register(new LinkBehavior());

    // Dispatch event for plugin registration
    $event = new RegisterForumTypesEvent($this->typeRegistry);
    $this->dispatcher->dispatch($event);
}
```

A plugin registers its custom type:

```php
// acme\wiki_forum\listener\WikiForumListener
public function onRegisterTypes(RegisterForumTypesEvent $event): void
{
    $event->register(new WikiForumBehavior());
}
```

Where `WikiForumBehavior` implements `ForumTypeBehaviorInterface`:

```php
<?php declare(strict_types=1);

namespace acme\wiki_forum\type;

use phpbb\hierarchy\type\ForumTypeBehaviorInterface;
use phpbb\hierarchy\entity\Forum;
use phpbb\hierarchy\entity\ForumType;

final class WikiForumBehavior implements ForumTypeBehaviorInterface
{
    private const WIKI_TYPE_VALUE = 3;

    public function getType(): ForumType
    {
        return ForumType::from(self::WIKI_TYPE_VALUE);
    }

    public function getLabel(): string { return 'Wiki Forum'; }
    public function canHaveChildren(): bool { return false; }
    public function canHaveContent(): bool { return true; }
    public function isLink(): bool { return false; }

    public function getEditableFields(): array
    {
        return ['name', 'description', 'wiki_default_page', 'wiki_revision_limit'];
    }

    public function validate(array $data): array
    {
        $errors = [];
        if (empty($data['name'])) {
            $errors[] = 'WIKI_FORUM_NAME_REQUIRED';
        }
        return $errors;
    }

    public function onCreated(Forum $forum): void
    {
        // Initialize wiki structure in custom tables
    }

    public function onDeleting(int $forumId): void
    {
        // Clean up wiki pages, revisions, etc.
    }
}
```

### Complete Wiki Forum Plugin Example — File Structure

```
ext/acme/wiki_forum/
├── config/
│   └── services.yml              # Register listener + decorators as services
├── listener/
│   └── WikiForumListener.php     # EventSubscriberInterface: register type, react to events
├── type/
│   └── WikiForumBehavior.php     # ForumTypeBehaviorInterface implementation
├── decorator/
│   ├── WikiForumRequestDecorator.php   # RequestDecoratorInterface: add wiki fields
│   └── WikiForumResponseDecorator.php  # ResponseDecoratorInterface: enrich with wiki data
└── repository/
    └── WikiPageRepository.php    # Plugin's own data access
```

**Plugin `services.yml`:**
```yaml
services:
    acme.wiki_forum.listener:
        class: acme\wiki_forum\listener\WikiForumListener
        tags:
            - { name: kernel.event_subscriber }

    acme.wiki_forum.request_decorator:
        class: acme\wiki_forum\decorator\WikiForumRequestDecorator
        tags:
            - { name: hierarchy.request_decorator }

    acme.wiki_forum.response_decorator:
        class: acme\wiki_forum\decorator\WikiForumResponseDecorator
        arguments:
            - '@acme.wiki_forum.wiki_repository'
        tags:
            - { name: hierarchy.response_decorator }
```

> **Note on DI tags**: The `hierarchy.request_decorator` and `hierarchy.response_decorator` tags use a simple compiler pass to collect decorators into the `DecoratorPipeline`. This is NOT the legacy `service_collection` pattern — it's a minimal, targeted DI integration for the decorator chain only. The primary extension mechanism remains events + direct type registration.

---

## Event-Driven Flow

### createForum() — Full Sequence

```
Caller: $event = $hierarchy->createForum(new CreateForumRequest(...))
    │
    ├── 1. REQUEST DECORATION
    │   DecoratorPipeline::decorateRequest($request)
    │     ├── WikiForumRequestDecorator::decorateRequest() → adds wiki extras
    │     └── GalleryRequestDecorator::decorateRequest() → adds gallery extras
    │   Result: $request with extra fields populated
    │
    ├── 2. TYPE VALIDATION
    │   ForumTypeRegistry::get($request->type)
    │     └── $behavior->validate($requestData)
    │         └── Throws ValidationException if errors
    │
    ├── 3. PERSISTENCE (in transaction)
    │   ├── ForumRepository::insert($data) → $forumId
    │   └── TreeService::insertNode($forumId, $parentId)
    │       └── Advisory lock → shift left_id/right_id → set node position
    │
    ├── 4. TYPE CALLBACK
    │   $behavior->onCreated($forum)
    │
    ├── 5. BUILD RESPONSE
    │   ForumRepository::findById($forumId) → $forum (hydrated entity)
    │   new ForumResponse($forum)
    │
    ├── 6. RESPONSE DECORATION
    │   DecoratorPipeline::decorateResponse($response, $request)
    │     ├── WikiForumResponseDecorator → adds wiki_page_count, wiki_last_edit
    │     └── Other decorators → add their extras
    │
    ├── 7. CREATE + DISPATCH EVENT
    │   $event = new ForumCreatedEvent($forum, $response)
    │   EventDispatcher::dispatch($event)
    │     ├── CacheInvalidationListener → clears forum cache
    │     ├── WikiForumListener::onForumCreated() → initializes wiki pages
    │     └── AuditLogListener → logs the creation
    │
    └── 8. RETURN $event to caller
```

### moveForum() — Full Sequence

```
Caller: $event = $hierarchy->moveForum(new MoveForumRequest(forumId: 5, newParentId: 2))
    │
    ├── 1. REQUEST DECORATION
    │   DecoratorPipeline::decorateRequest($request)
    │
    ├── 2. LOAD CURRENT STATE
    │   $forum = ForumRepository::findById(5) → old state
    │   $oldParentId = $forum->parentId
    │
    ├── 3. TREE OPERATION (in advisory lock + transaction)
    │   TreeService::changeParent(5, 2)
    │     ├── Remove subtree from current position (keep IDs)
    │     ├── Close gap at old position
    │     ├── Open gap at new parent's right_id
    │     ├── Shift subtree to new position
    │     └── Update parent_id
    │
    ├── 4. INVALIDATE PARENT CACHE
    │   TreeService::invalidateParentCache(null) → clear all forum_parents
    │
    ├── 5. BUILD RESPONSE
    │   $forum = ForumRepository::findById(5) → new state
    │   $response = new MoveForumResponse($forum, $oldParentId)
    │
    ├── 6. RESPONSE DECORATION
    │   DecoratorPipeline::decorateResponse($response, $request)
    │
    ├── 7. CREATE + DISPATCH EVENT
    │   $event = new ForumMovedEvent($forum, $oldParentId, 2, $response)
    │   EventDispatcher::dispatch($event)
    │     ├── CacheInvalidationListener → clears SQL cache, forum_parents
    │     ├── AclCleanupListener → adjusts permission inheritance if needed
    │     └── Plugin listeners → react to move
    │
    └── 8. RETURN $event
```

### deleteForum() — Full Sequence

```
Caller: $event = $hierarchy->deleteForum(new DeleteForumRequest(
    forumId: 42, contentAction: 'move', moveContentTo: 10,
    subforumAction: 'move', moveSubforumsTo: 5
))
    │
    ├── 1. REQUEST DECORATION
    │
    ├── 2. LOAD CURRENT STATE
    │   $forum = ForumRepository::findById(42)
    │
    ├── 3. TYPE CALLBACK (pre-delete)
    │   $behavior->onDeleting(42)
    │
    ├── 4. HANDLE CONTENT (in transaction)
    │   if contentAction == 'move':
    │     UPDATE phpbb_topics SET forum_id = 10 WHERE forum_id = 42
    │     UPDATE phpbb_posts SET forum_id = 10 WHERE forum_id = 42
    │     Resync stats on target forum
    │   elif contentAction == 'delete':
    │     DELETE topics + posts for forum 42
    │
    ├── 5. HANDLE SUBFORUMS
    │   if subforumAction == 'move':
    │     TreeService::moveChildren(42, 5) → reparent children under 5
    │   elif subforumAction == 'delete':
    │     Recursive delete for all descendants
    │
    ├── 6. REMOVE FROM TREE
    │   $deletedIds = TreeService::removeNode(42)
    │
    ├── 7. DELETE DB ROWS
    │   ForumRepository::deleteMultiple($deletedIds)
    │
    ├── 8. CLEANUP
    │   SubscriptionService::removeForumSubscriptions(42)
    │   TrackingService cleanup for deleted forums
    │
    ├── 9. BUILD RESPONSE
    │   $response = new DeleteForumResponse(42, $deletedIds)
    │
    ├── 10. RESPONSE DECORATION
    │
    ├── 11. CREATE + DISPATCH EVENT
    │   $event = new ForumDeletedEvent(42, $deletedIds, $response)
    │   EventDispatcher::dispatch($event)
    │     ├── AclCleanupListener → DELETE acl_groups/acl_users WHERE forum_id IN ($deletedIds)
    │     ├── CacheInvalidationListener → full cache clear
    │     └── Plugin listeners → cleanup custom data
    │
    └── 12. RETURN $event
```

---

## Nested Set Algorithm

Ported from legacy `nestedset.php` (870 LOC). Same math, PDO parameterized queries, PHP 8.2 strict types.

### insertNode — Pseudocode

```
function insertNode(int $nodeId, int $parentId): void
    acquire_advisory_lock('hierarchy_tree')
    try:
        if parentId == 0:
            // Insert at root: right-most position
            $rightMost = SELECT MAX(right_id) FROM phpbb_forums
            $newLeft = $rightMost + 1
            $newRight = $rightMost + 2
        else:
            // Insert as last child of parent
            $parent = SELECT right_id FROM phpbb_forums WHERE forum_id = $parentId
            $newLeft = $parent['right_id']
            $newRight = $newLeft + 1

            // Shift existing nodes to make room
            UPDATE phpbb_forums SET left_id = left_id + 2
                WHERE left_id >= $newLeft
            UPDATE phpbb_forums SET right_id = right_id + 2
                WHERE right_id >= $newLeft

        // Position the new node
        UPDATE phpbb_forums
            SET left_id = $newLeft,
                right_id = $newRight,
                parent_id = $parentId
            WHERE forum_id = $nodeId

        invalidateParentCache(null)
    finally:
        release_advisory_lock('hierarchy_tree')
```

### removeNode — Pseudocode

```
function removeNode(int $nodeId): int[]
    acquire_advisory_lock('hierarchy_tree')
    try:
        $node = SELECT left_id, right_id FROM phpbb_forums WHERE forum_id = $nodeId
        $width = $node['right_id'] - $node['left_id'] + 1

        // Collect all nodes in subtree
        $removedIds = SELECT forum_id FROM phpbb_forums
            WHERE left_id BETWEEN $node['left_id'] AND $node['right_id']

        // Close the gap
        UPDATE phpbb_forums SET left_id = left_id - $width
            WHERE left_id > $node['right_id']
        UPDATE phpbb_forums SET right_id = right_id - $width
            WHERE right_id > $node['right_id']

        return $removedIds
    finally:
        release_advisory_lock('hierarchy_tree')
```

### changeParent — Pseudocode

```
function changeParent(int $nodeId, int $newParentId): void
    acquire_advisory_lock('hierarchy_tree')
    begin_transaction()
    try:
        $node = SELECT * FROM phpbb_forums WHERE forum_id = $nodeId
        $subtreeWidth = $node['right_id'] - $node['left_id'] + 1

        // 1. Temporarily "lift" the subtree out (negative values)
        UPDATE phpbb_forums
            SET left_id = left_id * -1, right_id = right_id * -1
            WHERE left_id BETWEEN $node['left_id'] AND $node['right_id']

        // 2. Close gap at old position
        UPDATE phpbb_forums SET left_id = left_id - $subtreeWidth
            WHERE left_id > $node['right_id'] AND left_id > 0
        UPDATE phpbb_forums SET right_id = right_id - $subtreeWidth
            WHERE right_id > $node['right_id'] AND right_id > 0

        // 3. Open gap at new parent
        $newParent = SELECT right_id FROM phpbb_forums WHERE forum_id = $newParentId
        $insertAt = $newParent['right_id']

        UPDATE phpbb_forums SET left_id = left_id + $subtreeWidth
            WHERE left_id >= $insertAt AND left_id > 0
        UPDATE phpbb_forums SET right_id = right_id + $subtreeWidth
            WHERE right_id >= $insertAt AND right_id > 0

        // 4. Calculate offset and move subtree to new position
        $offset = $insertAt - $node['left_id']
        UPDATE phpbb_forums
            SET left_id = (left_id * -1) + $offset,
                right_id = (right_id * -1) + $offset
            WHERE left_id < 0

        // 5. Update parent_id
        UPDATE phpbb_forums SET parent_id = $newParentId WHERE forum_id = $nodeId

        invalidateParentCache(null)
        commit_transaction()
    catch:
        rollback_transaction()
        throw
    finally:
        release_advisory_lock('hierarchy_tree')
```

### reorder — Pseudocode

```
function reorder(int $nodeId, int $delta): bool
    acquire_advisory_lock('hierarchy_tree')
    try:
        $node = SELECT * FROM phpbb_forums WHERE forum_id = $nodeId

        // Find sibling to swap with
        if $delta > 0:  // move up (toward lower left_id)
            $sibling = SELECT * FROM phpbb_forums
                WHERE parent_id = $node['parent_id']
                AND right_id = $node['left_id'] - 1
        else:  // move down (toward higher left_id)
            $sibling = SELECT * FROM phpbb_forums
                WHERE parent_id = $node['parent_id']
                AND left_id = $node['right_id'] + 1

        if $sibling is null:
            return false  // already at boundary

        // Swap using CASE-based UPDATE
        $nodeWidth = $node['right_id'] - $node['left_id'] + 1
        $sibWidth = $sibling['right_id'] - $sibling['left_id'] + 1

        UPDATE phpbb_forums SET
            left_id = CASE
                WHEN left_id BETWEEN $node['left_id'] AND $node['right_id']
                    THEN left_id + (offset toward sibling position)
                WHEN left_id BETWEEN $sibling['left_id'] AND $sibling['right_id']
                    THEN left_id - (offset toward node position)
                ELSE left_id
            END,
            right_id = CASE ... (analogous) ... END
        WHERE left_id BETWEEN MIN($node['left_id'], $sibling['left_id'])
            AND MAX($node['right_id'], $sibling['right_id'])

        invalidateParentCache(null)
        return true
    finally:
        release_advisory_lock('hierarchy_tree')
```

---

## Database Access Patterns

### ForumRepository SQL

| Method | SQL | Notes |
|--------|-----|-------|
| `findById($id)` | `SELECT * FROM phpbb_forums WHERE forum_id = :id` | Single parameterized query |
| `findByIds($ids)` | `SELECT * FROM phpbb_forums WHERE forum_id IN (:ids) ORDER BY left_id` | `IN` clause with bound params |
| `findAll()` | `SELECT * FROM phpbb_forums ORDER BY left_id` | Full tree, single query |
| `findByParent($pid)` | `SELECT * FROM phpbb_forums WHERE parent_id = :pid ORDER BY left_id` | Direct children only |
| `insert($data)` | `INSERT INTO phpbb_forums (...) VALUES (...)` | Returns `lastInsertId()` |
| `update($id, $data)` | `UPDATE phpbb_forums SET ... WHERE forum_id = :id` | Dynamic SET clause from non-null fields |
| `delete($id)` | `DELETE FROM phpbb_forums WHERE forum_id = :id` | Single row only |
| `deleteMultiple($ids)` | `DELETE FROM phpbb_forums WHERE forum_id IN (:ids)` | Batch delete |
| `resetStats($id)` | `UPDATE phpbb_forums SET forum_posts_approved=0, forum_posts_unapproved=0, forum_posts_softdeleted=0, forum_topics_approved=0, forum_topics_unapproved=0, forum_topics_softdeleted=0 WHERE forum_id = :id` | 6 counter columns |
| `invalidateParentCache($ids)` | `UPDATE phpbb_forums SET forum_parents = '' WHERE forum_id IN (:ids)` | Or `WHERE 1=1` for all |

### TreeService SQL

| Method | SQL | Notes |
|--------|-----|-------|
| `insertNode` | 3-4 queries: SELECT parent → UPDATE left_id shift → UPDATE right_id shift → UPDATE node position | All within advisory lock |
| `removeNode` | 3 queries: SELECT subtree IDs → UPDATE close left gap → UPDATE close right gap | Within lock |
| `changeParent` | 5 queries: negate subtree → close old gap → open new gap → shift subtree → update parent_id | Transaction + lock |
| `reorder` | 3 queries: SELECT node → SELECT sibling → CASE-based swap UPDATE | Within lock |
| `getPath($id)` | `SELECT i2.* FROM phpbb_forums i1 JOIN phpbb_forums i2 ON i1.left_id BETWEEN i2.left_id AND i2.right_id WHERE i1.forum_id = :id ORDER BY i2.left_id` | Self-join on nested set |
| `getSubtree($id)` | `SELECT i2.* FROM phpbb_forums i1 JOIN phpbb_forums i2 ON i2.left_id BETWEEN i1.left_id AND i1.right_id WHERE i1.forum_id = :id ORDER BY i2.left_id` | Self-join |
| `getFullTree()` | `SELECT * FROM phpbb_forums ORDER BY left_id` | Single scan |
| `regenerate()` | Recursive: per-parent SELECT children → UPDATE left → recurse → UPDATE right | O(3n) queries — repair only |

### TrackingService SQL

| Method | SQL |
|--------|-----|
| `markForumsRead` | `DELETE FROM phpbb_topics_track WHERE user_id=:uid AND forum_id IN (:fids) AND mark_time < :time` then `INSERT INTO phpbb_forums_track ... ON DUPLICATE KEY UPDATE mark_time=:time` |
| `markAllRead` | `DELETE FROM phpbb_forums_track WHERE user_id=:uid` then `DELETE FROM phpbb_topics_track WHERE user_id=:uid` |
| `getMarkTimes` | `SELECT forum_id, mark_time FROM phpbb_forums_track WHERE user_id=:uid AND forum_id IN (:fids)` |
| `autoMarkIfComplete` | `SELECT 1 FROM phpbb_topics t LEFT JOIN phpbb_topics_track tt ON ... WHERE t.forum_id=:fid AND t.topic_last_post_time > :mark AND (tt.topic_id IS NULL OR tt.mark_time < t.topic_last_post_time) LIMIT 1` |

### SubscriptionService SQL

| Method | SQL |
|--------|-----|
| `subscribe` | `INSERT INTO phpbb_forums_watch (forum_id, user_id, notify_status) VALUES (:fid, :uid, 0)` |
| `unsubscribe` | `DELETE FROM phpbb_forums_watch WHERE forum_id=:fid AND user_id=:uid` |
| `isSubscribed` | `SELECT 1 FROM phpbb_forums_watch WHERE forum_id=:fid AND user_id=:uid LIMIT 1` |
| `getEligibleSubscribers` | `SELECT user_id FROM phpbb_forums_watch WHERE forum_id=:fid AND notify_status=0 AND user_id!=:exclude` |
| `resetNotifyStatus` | `UPDATE phpbb_forums_watch SET notify_status=0 WHERE forum_id=:fid AND user_id=:uid AND notify_status=1` |
| `removeForumSubscriptions` | `DELETE FROM phpbb_forums_watch WHERE forum_id=:fid` |

---

## Integration Points

### Imports from `phpbb\auth`

Hierarchy does **NOT** call auth directly. The display/API layer is responsible:

```php
// Controller / display layer — NOT in phpbb\hierarchy
$tree = $hierarchy->getTree();
$visibleForums = array_filter(
    $tree->forums,
    fn(Forum $f) => $auth->acl_get('f_list', $f->id)
);
```

**Permission touch points** (all outside hierarchy):

| Permission | Where Applied | By Whom |
|------------|--------------|---------|
| `f_list` | Filter visible forums in tree display | Display/API controller |
| `f_read` | Control forum page access | Forum controller |
| `m_approve` | Determine visible post/topic counts | Display layer via `content_visibility` |
| `a_forumadd/del` | Gate ACP operations | ACP controller |

### Imports from `phpbb\user`

| Data | Used By | Pattern |
|------|---------|---------|
| `user_id` | TrackingService, SubscriptionService | Passed as method parameter from session |
| `user_lastmark` | Tracking baseline time | Caller provides; hierarchy doesn't read users table |
| `user_lastmark` UPDATE | `markAllRead()` | `AllForumsMarkedReadEvent` dispatched → user-side listener updates |

### ACL Cleanup on Forum Delete

The auth subsystem registers an event listener for `ForumDeletedEvent`:

```php
// phpbb\auth\listener\AclCleanupListener
class AclCleanupListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [ForumDeletedEvent::class => 'onForumDeleted'];
    }

    public function onForumDeleted(ForumDeletedEvent $event): void
    {
        $ids = $event->deletedIds;
        // DELETE FROM phpbb_acl_groups WHERE forum_id IN (:ids)
        // DELETE FROM phpbb_acl_users WHERE forum_id IN (:ids)
        $this->aclClearPrefetch();
    }
}
```

---

## Tracking & Subscription Detail

### Tracking: Dual-Path Strategy

**Registered users** → `DbTrackingStrategy`:
- `phpbb_forums_track` table: `(user_id, forum_id, mark_time)` with PK on `(user_id, forum_id)`
- Uses UPSERT pattern (`INSERT ... ON DUPLICATE KEY UPDATE`)
- `markForumsRead`: deletes topic_track rows below mark_time, upserts forum_track
- `markAllRead`: deletes all forum_track + topic_track for user, fires `AllForumsMarkedReadEvent` (listener updates `user_lastmark`)

**Anonymous users** → `CookieTrackingStrategy`:
- Cookie-based tracking using base36-encoded timestamps
- Cookie key: `_track` keyed by `f` (forum) prefix
- Format: `f{forum_id_base36}={mark_time_offset_base36}`
- Offset calculated from `board_startdate` configuration
- Cookie overflow protection at 10000 chars (oldest entries pruned)
- Read by decoding base36 back to forum_id + timestamp

**TrackingService selects strategy based on user_id**:
```php
public function markForumsRead(int $userId, int|array $forumId, int $markTime): void
{
    $strategy = $userId > 0
        ? $this->dbStrategy
        : $this->cookieStrategy;

    foreach ((array) $forumId as $fid) {
        $strategy->markRead($userId, $fid, $markTime);
    }
}
```

### Tracking: Markread 4 Modes

1. **Mark single forum** — `markForumsRead($uid, $forumId, time())`
2. **Mark multiple forums** — `markForumsRead($uid, [$id1, $id2, ...], time())`
3. **Mark all forums** — `markAllRead($uid, time())`
4. **Auto-mark forum** — `autoMarkIfComplete($uid, $forumId, $lastPostTime)` — checks if all topics read

### Subscriptions: Watch/Notify

- `phpbb_forums_watch` table: `(forum_id, user_id, notify_status)`
- `notify_status`: 0 = eligible for notification (`NOTIFY_YES`), 1 = already notified (`NOTIFY_NO`)
- Subscribe: INSERT with `notify_status=0`
- On new post in forum: notification system calls `getEligibleSubscribers($forumId)` → gets user_ids with `notify_status=0` → sends notifications → sets `notify_status=1`
- On user revisit: `resetNotifyStatus($uid, $fid)` → sets back to 0
- On forum delete: `removeForumSubscriptions($fid)` → deletes all watch rows

---

## Design Decisions

| # | Decision | Outcome | ADR |
|---|----------|---------|-----|
| 1 | Entity model | Single Entity + Typed Behavior Delegates | [ADR-001](decision-log.md#adr-001-single-entity--typed-behavior-delegates) |
| 2 | Nested set implementation | Port legacy to PDO | [ADR-002](decision-log.md#adr-002-port-legacy-nested-set-to-pdo) |
| 3 | Service decomposition | Five services | [ADR-003](decision-log.md#adr-003-five-service-decomposition) |
| 4 | Plugin architecture | Events + Request/Response Decorators | [ADR-004](decision-log.md#adr-004-events--requestresponse-decorators-for-plugins) |
| 5 | API style | Event-driven (domain events as returns) | [ADR-005](decision-log.md#adr-005-event-driven-api) |
| 6 | ACL responsibility | Excluded from hierarchy | [ADR-006](decision-log.md#adr-006-no-acl-responsibility) |
| 7 | Tracking strategy | Dual-path preserved (DB + cookies) | [ADR-007](decision-log.md#adr-007-dual-path-tracking-preserved) |

---

## Concrete Examples

### Example 1: Admin creates a "Help & Support" forum under "General" category

**Given**: A category "General" (id=1, type=Category) exists at root level.
**When**: Admin calls `createForum(new CreateForumRequest(name: 'Help & Support', type: ForumType::Forum, parentId: 1))`.
**Then**:
- Request decorator chain runs (no wiki decorators apply — type is Forum)
- `PostingForumBehavior::validate()` passes (name is not empty)
- Row inserted into `phpbb_forums` with new `forum_id=5`
- `TreeService::insertNode(5, 1)` positions node inside "General" (left_id/right_id shifted)
- `PostingForumBehavior::onCreated()` runs (no-op for standard forum)
- `ForumCreatedEvent` dispatched → cache listener invalidates SQL cache
- Event returned to caller containing the hydrated `Forum` entity and `ForumResponse`

### Example 2: Plugin adds a Wiki forum type

**Given**: The `acme/wiki_forum` extension is installed and its listener registered.
**When**: `HierarchyService` boots and dispatches `RegisterForumTypesEvent`.
**Then**:
- `WikiForumListener::onRegisterTypes()` calls `$event->register(new WikiForumBehavior())`
- `ForumTypeRegistry` now contains 4 types: Category(0), Forum(1), Link(2), Wiki(3)
- When admin creates a Wiki forum: `CreateForumRequest` with type=Wiki(3)
  - `WikiForumRequestDecorator` adds `wiki_default_page`, `wiki_allow_anonymous_edits`
  - `WikiForumBehavior::validate()` enforces wiki-specific rules
  - `WikiForumBehavior::onCreated()` initializes wiki page structure
  - `WikiForumResponseDecorator` enriches response with `wiki_page_count`
  - `WikiForumListener::onForumCreated()` runs additional wiki setup

### Example 3: Admin moves a subforum to a different parent

**Given**: Forum "PHP Help" (id=10, parent=5) needs to move under "Development" (id=3).
**When**: Admin calls `moveForum(new MoveForumRequest(forumId: 10, newParentId: 3))`.
**Then**:
- Old parent_id=5 captured before move
- Advisory lock acquired
- Subtree (10 + any children) "lifted" via negative left_id/right_id
- Gap closed at old position under parent 5
- Gap opened at new position under parent 3
- Subtree shifted to new position
- `parent_id` updated to 3
- All `forum_parents` caches cleared
- Lock released
- `ForumMovedEvent(forum, oldParentId=5, newParentId=3, response)` dispatched
- ACL listener may adjust permission inheritance
- Cache listener clears SQL query cache

---

## Out of Scope

| Item | Reason | Future Consideration |
|------|--------|---------------------|
| ACL/permissions management | Explicitly excluded — hierarchy provides data, auth filters it | If tight ACL integration needed, create `HierarchyAclBridge` |
| Topic/post management | Separate `phpbb\content` service responsibility | Hierarchy handles forum-level only |
| Display/template rendering | Belongs to presentation layer consuming hierarchy service | Display layer composes hierarchy + auth |
| `forum_access` (password protection) | Session-scoped concern separate from hierarchy structure | Could become `ForumAccessService` |
| Custom DB columns per plugin | Plugins use `forum_options` bitmask or separate tables for v1 | Schema migration per plugin in v2 |
| Read model / CQRS split | No evidence of read performance problems on typical trees | Revisit at 500+ forums |
| Forum archival / soft-delete trees | Expands scope beyond CRUD + tree operations | Product feature request |
| Async tree mutations | Current operations are synchronous and fast enough | High-concurrency admin scenarios |
| `forum_parents` PHP→JSON migration | Implementation detail, not architecture decision | During TreeService implementation |

---

## Success Criteria

1. **All tree operations maintain nested set invariants** — no orphaned nodes, no overlapping left_id/right_id ranges after any mutation sequence
2. **Zero data migration required** — new service operates on existing `phpbb_forums` / `phpbb_forums_track` / `phpbb_forums_watch` tables unchanged
3. **Each service independently testable** — ForumRepository, TreeService, TrackingService, SubscriptionService can be unit-tested with mock PDO
4. **Plugin can register a custom forum type** — via `RegisterForumTypesEvent` without modifying any core service code
5. **Plugin can decorate request/response** — via decorator interfaces without modifying core DTOs
6. **Domain events dispatched for all mutations** — every create/update/delete/move/reorder operation returns an event and dispatches to listeners
7. **Dual-path tracking functional** — registered users tracked via DB, anonymous via cookies, with identical unread semantics

---

## Implementation Phases

### Phase 1: Foundation (no external dependencies)
**Deliverables**: Entity model, value objects, enums, DTOs, exception classes
**Dependencies**: None
**Effort**: Smallest phase — pure data structures

### Phase 2: TreeService
**Deliverables**: `TreeServiceInterface`, `TreeService` with full nested set operations, advisory locking
**Dependencies**: Phase 1 (entities)
**Effort**: Most complex phase — ported from legacy 870 LOC
**Testing**: Integration tests against real MySQL with nested set invariant assertions

### Phase 3: ForumRepository
**Deliverables**: `ForumRepositoryInterface`, `ForumRepository` with CRUD, hydration, parent cache
**Dependencies**: Phase 1 (entities), Phase 2 (tree — for insert coordination)
**Testing**: Integration tests against real MySQL

### Phase 4: Plugin Infrastructure
**Deliverables**: `ForumTypeBehaviorInterface`, `ForumTypeRegistry`, 3 core behaviors (Category, PostingForum, Link), `RegisterForumTypesEvent`, `DecoratorPipeline`, decorator interfaces
**Dependencies**: Phase 1 (entities, DTOs)
**Testing**: Unit tests with mock behaviors

### Phase 5: HierarchyService Facade
**Deliverables**: `HierarchyServiceInterface`, `HierarchyService` orchestrating all sub-services, decorator pipeline execution, event dispatch
**Dependencies**: Phase 2 + 3 + 4
**Testing**: Integration tests covering full flows (create → event → decorator)

### Phase 6: TrackingService
**Deliverables**: `TrackingServiceInterface`, `TrackingService`, `DbTrackingStrategy`, `CookieTrackingStrategy`
**Dependencies**: Phase 1, PDO
**Testing**: Integration tests with both strategies, 4-mode markread coverage

### Phase 7: SubscriptionService
**Deliverables**: `SubscriptionServiceInterface`, `SubscriptionService`
**Dependencies**: Phase 1, PDO
**Testing**: Integration tests for subscribe/unsubscribe/notify cycle

### Phase 8: Domain Events Integration
**Deliverables**: All event classes wired into HierarchyService, event dispatch at each mutation point, documented event contracts
**Dependencies**: Phase 5 (facade)
**Testing**: Verify all mutation methods dispatch correct events with correct payloads

### Phase 9: DI Configuration & Boot
**Deliverables**: Service definitions (YAML/PHP), `RegisterForumTypesEvent` dispatch on boot, decorator pipeline wiring via compiler pass for `hierarchy.request_decorator`/`hierarchy.response_decorator` tags
**Dependencies**: All prior phases
**Testing**: End-to-end smoke tests with full DI container
