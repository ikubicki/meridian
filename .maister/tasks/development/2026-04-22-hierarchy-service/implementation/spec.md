# Specification: `phpbb\hierarchy` Service

**Date**: 2026-04-22
**Task**: Implement complete phpbb\hierarchy PHP namespace — forums, categories, nested set, tracking, subscriptions, plugin events, REST API wiring.

---

## 1. Overview

### What Is Being Built

A clean-room reimplementation of phpBB3 forum hierarchy management under the `phpbb\hierarchy` namespace. Replaces ~4 000 LOC of untestable procedural code (`acp_forums.php`, `display_forums()`, `markread()`, `watch_topic_or_forum()`, `nestedset_forum`) with a modern, testable, dependency-injected service layer.

### Architecture Summary

Five services composed by a facade:

| Service | Role |
|---|---|
| `HierarchyService` (facade) | Orchestrates all operations, runs decorator pipeline, returns `DomainEventCollection` |
| `ForumRepository` | CRUD on `phpbb_forums`, entity hydration, exception wrapping |
| `TreeService` | Nested set operations (insert, move, remove, traversal) with `SELECT FOR UPDATE` locking |
| `TrackingService` | Per-user read status on `phpbb_forums_track` |
| `SubscriptionService` | Forum watch subscriptions on `phpbb_forums_watch` |

Plugin extensibility via:
- Typed Symfony domain events (`ForumCreatedEvent`, etc.) — controllers dispatch via `EventDispatcherInterface`
- `ForumTypeRegistry` — maps `ForumType` enum to `ForumTypeBehaviorInterface` delegates
- `RegisterForumTypesEvent` — fired at boot for plugin type registration
- Request/response decorator arrays (DI-injected, no compiler pass in phase 1)

### Domain Events Standard

This service follows `.maister/docs/standards/backend/DOMAIN_EVENTS.md`:
- **Mutation methods return `DomainEventCollection`** — controllers call `$events->dispatch($dispatcher)`
- **Services do NOT dispatch events internally**
- **Query methods return entities/DTOs directly**, never events

The `DomainEvent` base class and `DomainEventCollection` must be created as prerequisites in `src/phpbb/common/Event/`.

### Scope

**In scope**: All five services, plugin events, REST API wiring, PHPUnit tests (min 40).

**Out of scope**: Cookie tracking strategy (DB path only for phase 1), `DecoratorPipeline` compiler pass (DI-injected arrays), E2E Playwright tests, React frontend changes, search indexing, admin panel, user documentation.

---

## 2. Namespace Structure

### Files to Create

```
src/phpbb/common/Event/                         (PREREQUISITE)
    DomainEvent.php                             — abstract readonly base event
    DomainEventCollection.php                   — iterable event container

src/phpbb/hierarchy/
    Contract/
        ForumRepositoryInterface.php
        HierarchyServiceInterface.php
        TreeServiceInterface.php
        TrackingServiceInterface.php
        SubscriptionServiceInterface.php
    Entity/
        Forum.php                               — readonly domain entity
        ForumType.php                           — backed enum (int): CATEGORY=0, POST=1, LINK=2
        ForumStatus.php                         — backed enum (int): Unlocked=0, Locked=1
        ForumStats.php                          — readonly value object (post/topic counters)
        ForumLastPost.php                       — readonly value object
        ForumPruneSettings.php                  — readonly value object
    DTO/
        ForumDTO.php                            — serializable read model
        CreateForumRequest.php                  — mutation input with optional actorId + extra[]
        UpdateForumRequest.php                  — partial update input
    Event/
        ForumCreatedEvent.php
        ForumUpdatedEvent.php
        ForumDeletedEvent.php
        ForumMovedEvent.php
        RegisterForumTypesEvent.php             — dispatched at boot for type registration
    Plugin/
        ForumTypeRegistry.php
        ForumTypeBehaviorInterface.php
        CategoryBehavior.php                    — CATEGORY type delegate
        ForumBehavior.php                       — POST type delegate
        LinkBehavior.php                        — LINK type delegate
    Repository/
        DbalForumRepository.php
    Service/
        HierarchyService.php
        TreeService.php
        TrackingService.php
        SubscriptionService.php

tests/phpbb/hierarchy/
    Repository/
        DbalForumRepositoryTest.php             — IntegrationTestCase, SQLite
    Service/
        TreeServiceTest.php                     — IntegrationTestCase, SQLite
        TrackingServiceTest.php                 — IntegrationTestCase, SQLite
        SubscriptionServiceTest.php             — IntegrationTestCase, SQLite
        HierarchyServiceTest.php                — PHPUnit unit test, mocked deps
```

### Files to Modify

```
src/phpbb/api/Controller/ForumsController.php   — inject HierarchyServiceInterface, add 5 new routes
src/phpbb/config/services.yaml                  — add all hierarchy service definitions
```

---

## 3. Prerequisites: `phpbb\common\Event`

These must be created before any hierarchy code.

### `DomainEvent` (abstract readonly base)

**Namespace**: `phpbb\common\Event`
**File**: `src/phpbb/common/Event/DomainEvent.php`

Properties:
- `public readonly int $entityId` — ID of the affected entity
- `public readonly int $actorId` — ID of the user who triggered the action (0 = system)
- `public readonly \DateTimeImmutable $occurredAt` — defaults to `new \DateTimeImmutable()`

Constructor: `__construct(int $entityId, int $actorId, \DateTimeImmutable $occurredAt = new \DateTimeImmutable())`

### `DomainEventCollection`

**Namespace**: `phpbb\common\Event`
**File**: `src/phpbb/common/Event/DomainEventCollection.php`
**Implements**: `\IteratorAggregate`

Constructor: `__construct(private readonly array $events)` — `$events` is `DomainEvent[]`

Methods:
- `dispatch(EventDispatcherInterface $dispatcher): void` — calls `$dispatcher->dispatch($event)` for each event
- `getIterator(): \ArrayIterator` — returns `new \ArrayIterator($this->events)`
- `all(): array` — returns `$this->events`
- `first(): ?DomainEvent` — returns `$this->events[0] ?? null`

---

## 4. Entity Design

### `Forum` entity

**Namespace**: `phpbb\hierarchy\Entity`
**File**: `src/phpbb/hierarchy/Entity/Forum.php`

```
final readonly class Forum
```

Constructor properties (all `public readonly`):

| Property | Type | DB column |
|---|---|---|
| `$id` | `int` | `forum_id` |
| `$name` | `string` | `forum_name` |
| `$description` | `string` | `forum_desc` |
| `$descriptionBitfield` | `string` | `forum_desc_bitfield` |
| `$descriptionOptions` | `int` | `forum_desc_options` |
| `$descriptionUid` | `string` | `forum_desc_uid` |
| `$parentId` | `int` | `parent_id` |
| `$leftId` | `int` | `left_id` |
| `$rightId` | `int` | `right_id` |
| `$type` | `ForumType` | `forum_type` |
| `$status` | `ForumStatus` | `forum_status` |
| `$image` | `string` | `forum_image` |
| `$rules` | `string` | `forum_rules` |
| `$rulesLink` | `string` | `forum_rules_link` |
| `$rulesBitfield` | `string` | `forum_rules_bitfield` |
| `$rulesOptions` | `int` | `forum_rules_options` |
| `$rulesUid` | `string` | `forum_rules_uid` |
| `$link` | `string` | `forum_link` |
| `$password` | `string` | `forum_password` |
| `$style` | `int` | `forum_style` |
| `$topicsPerPage` | `int` | `forum_topics_per_page` |
| `$flags` | `int` | `forum_flags` |
| `$options` | `int` | `forum_options` |
| `$displayOnIndex` | `bool` | `display_on_index` |
| `$displaySubforumList` | `bool` | `display_subforum_list` |
| `$enableIndexing` | `bool` | `enable_indexing` |
| `$enableIcons` | `bool` | `enable_icons` |
| `$stats` | `ForumStats` | composite |
| `$lastPost` | `ForumLastPost` | composite |
| `$pruneSettings` | `ForumPruneSettings` | composite |
| `$parents` | `array` | `forum_parents` (decoded JSON/serialize) |

Methods (all derived, no DB access):
- `isLeaf(): bool` — returns `$this->rightId - $this->leftId === 1`
- `descendantCount(): int` — returns `(int)(($this->rightId - $this->leftId - 1) / 2)`
- `isCategory(): bool` — returns `$this->type === ForumType::Category`
- `isForum(): bool` — returns `$this->type === ForumType::Forum`
- `isLink(): bool` — returns `$this->type === ForumType::Link`

### `ForumType` enum

**Namespace**: `phpbb\hierarchy\Entity`
**File**: `src/phpbb/hierarchy/Entity/ForumType.php`

```
enum ForumType: int
{
    case Category = 0;
    case Forum    = 1;
    case Link     = 2;
}
```

### `ForumStatus` enum

**Namespace**: `phpbb\hierarchy\Entity`
**File**: `src/phpbb/hierarchy/Entity/ForumStatus.php`

```
enum ForumStatus: int
{
    case Unlocked = 0;
    case Locked   = 1;
}
```

### `ForumStats` value object

**Namespace**: `phpbb\hierarchy\Entity`
**File**: `src/phpbb/hierarchy/Entity/ForumStats.php`

```
final readonly class ForumStats
```

Constructor properties:
- `public int $postsApproved`
- `public int $postsUnapproved`
- `public int $postsSoftdeleted`
- `public int $topicsApproved`
- `public int $topicsUnapproved`
- `public int $topicsSoftdeleted`

Methods:
- `totalPosts(): int` — sum of three post counters
- `totalTopics(): int` — sum of three topic counters

### `ForumLastPost` value object

**Namespace**: `phpbb\hierarchy\Entity`
**File**: `src/phpbb/hierarchy/Entity/ForumLastPost.php`

```
final readonly class ForumLastPost
```

Constructor properties:
- `public int $postId` — `forum_last_post_id`
- `public int $posterId` — `forum_last_poster_id`
- `public string $subject` — `forum_last_post_subject`
- `public int $time` — `forum_last_post_time`
- `public string $posterName` — `forum_last_poster_name`
- `public string $posterColour` — `forum_last_poster_colour`

### `ForumPruneSettings` value object

**Namespace**: `phpbb\hierarchy\Entity`
**File**: `src/phpbb/hierarchy/Entity/ForumPruneSettings.php`

```
final readonly class ForumPruneSettings
```

Constructor properties:
- `public bool $enabled` — `enable_prune`
- `public int $days` — `prune_days`
- `public int $viewed` — `prune_viewed`
- `public int $frequency` — `prune_freq`
- `public int $next` — `prune_next`

---

## 5. DTO Design

### `ForumDTO`

**Namespace**: `phpbb\hierarchy\DTO`
**File**: `src/phpbb/hierarchy/DTO/ForumDTO.php`

```
final readonly class ForumDTO
```

Constructor properties:
- `public int $id`
- `public string $name`
- `public string $description`
- `public int $parentId`
- `public int $type` — `ForumType->value`
- `public int $status` — `ForumStatus->value`
- `public int $leftId`
- `public int $rightId`
- `public bool $displayOnIndex`
- `public int $topicsApproved`
- `public int $postsApproved`
- `public int $lastPostId`
- `public int $lastPostTime`
- `public string $lastPosterName`
- `public string $link`
- `public array $parents` — decoded parent chain

Named constructor:
- `static fromEntity(Forum $forum): self` — maps all properties from entity

### `CreateForumRequest`

**Namespace**: `phpbb\hierarchy\DTO`
**File**: `src/phpbb/hierarchy/DTO/CreateForumRequest.php`

Class (not readonly — needs `$extra` mutation via `withExtra()`):

Constructor properties (`public readonly`):
- `string $name`
- `ForumType $type`
- `int $parentId = 0`
- `int $actorId = 0`
- `string $description = ''`
- `string $link = ''`
- `string $image = ''`
- `string $rules = ''`
- `string $rulesLink = ''`
- `string $password = ''`
- `int $style = 0`
- `int $topicsPerPage = 0`
- `int $flags = 32`
- `bool $displayOnIndex = true`
- `bool $displaySubforumList = true`
- `bool $enableIndexing = true`
- `bool $enableIcons = false`

Private state:
- `private array $extra = []`

Methods:
- `withExtra(string $key, mixed $value): static` — clone + set extra key
- `getExtra(string $key, mixed $default = null): mixed`
- `getAllExtra(): array`

### `UpdateForumRequest`

**Namespace**: `phpbb\hierarchy\DTO`
**File**: `src/phpbb/hierarchy/DTO/UpdateForumRequest.php`

Constructor properties (all `?Type = null` except $forumId and $actorId):
- `public readonly int $forumId`
- `public readonly int $actorId = 0`
- `public readonly ?string $name = null`
- `public readonly ?ForumType $type = null`
- `public readonly ?int $parentId = null`
- `public readonly ?string $description = null`
- `public readonly ?string $link = null`
- `public readonly ?string $image = null`
- `public readonly ?string $rules = null`
- `public readonly ?string $rulesLink = null`
- `public readonly ?string $password = null`
- `public readonly ?bool $clearPassword = null`
- `public readonly ?int $style = null`
- `public readonly ?int $topicsPerPage = null`
- `public readonly ?int $flags = null`
- `public readonly ?bool $displayOnIndex = null`
- `public readonly ?bool $displaySubforumList = null`
- `public readonly ?bool $enableIndexing = null`
- `public readonly ?bool $enableIcons = null`

Same `$extra` + `withExtra()`/`getExtra()`/`getAllExtra()` pattern as `CreateForumRequest`.

---

## 6. Interface Contracts

### `ForumRepositoryInterface`

**Namespace**: `phpbb\hierarchy\Contract`
**File**: `src/phpbb/hierarchy/Contract/ForumRepositoryInterface.php`

```
interface ForumRepositoryInterface
```

Methods:

```
findById(int $id): ?Forum
```
Returns hydrated `Forum` or `null`.
Throws: `RepositoryException`

```
findAll(): array
```
Returns `array<int, Forum>` keyed by `forum_id`, ordered by `left_id ASC`.
Throws: `RepositoryException`

```
findChildren(int $parentId): array
```
Returns `array<int, Forum>` of direct children, keyed by `forum_id`, ordered by `left_id ASC`.
Throws: `RepositoryException`

```
insertRaw(CreateForumRequest $request): int
```
Inserts a forum row with `left_id = 0`, `right_id = 0` (tree position set by `TreeService`).
Returns the new `forum_id`.
Throws: `RepositoryException`

```
update(UpdateForumRequest $request): Forum
```
Applies non-null fields from the request. Returns the updated entity.
Throws: `RepositoryException`, `\InvalidArgumentException` (if forum not found)

```
delete(int $forumId): void
```
Deletes the forum row. Caller (TreeService) must have already removed the tree entry.
Throws: `RepositoryException`

```
updateTreePosition(int $forumId, int $leftId, int $rightId, int $parentId): void
```
Updates `left_id`, `right_id`, `parent_id` atomically. Used by `TreeService`.
Throws: `RepositoryException`

```
shiftLeftIds(int $threshold, int $delta): void
```
`UPDATE phpbb_forums SET left_id = left_id + :delta WHERE left_id >= :threshold`
Throws: `RepositoryException`

```
shiftRightIds(int $threshold, int $delta): void
```
`UPDATE phpbb_forums SET right_id = right_id + :delta WHERE right_id >= :threshold`
Throws: `RepositoryException`

### `TreeServiceInterface`

**Namespace**: `phpbb\hierarchy\Contract`
**File**: `src/phpbb/hierarchy/Contract/TreeServiceInterface.php`

```
interface TreeServiceInterface
```

Methods:

```
getSubtree(?int $rootId): array
```
If `$rootId` is `null`: all forums ordered by `left_id ASC`.
If `$rootId` given: all forums where `left_id >= root.left_id AND right_id <= root.right_id`, ordered by `left_id ASC`.
Returns `array<int, Forum>` keyed by `forum_id`.
Throws: `RepositoryException`

```
getPath(int $forumId): array
```
Returns ancestors from root to (and including) the node: `WHERE left_id <= node.left_id AND right_id >= node.right_id ORDER BY left_id ASC`.
Returns `array<int, Forum>` keyed by `forum_id`.
Throws: `RepositoryException`

```
insertAtPosition(int $forumId, int $parentId): void
```
Positions a newly inserted (left_id=0, right_id=0) node as the last child of `$parentId`.
Runs inside `transactional()` with SELECT FOR UPDATE on the parent.
Throws: `RepositoryException`, `\InvalidArgumentException` (parent not found)

```
removeNode(int $forumId): void
```
Removes the node and its entire subtree from the nested set.
Updates left_id and right_id of remaining nodes.
Does NOT delete rows from DB (caller decides on cascade vs reassign).
Throws: `RepositoryException`

```
moveNode(int $forumId, int $newParentId): void
```
Moves the node subtree under `$newParentId`.
Runs inside `transactional()` with SELECT FOR UPDATE.
Throws: `RepositoryException`, `\InvalidArgumentException` (parent not found or would create cycle)

```
rebuildTree(): void
```
Full O(3n) rebuild of all `left_id`, `right_id`, `parent_id` values from scratch.
Used for data repair only.
Throws: `RepositoryException`

### `HierarchyServiceInterface`

**Namespace**: `phpbb\hierarchy\Contract`
**File**: `src/phpbb/hierarchy/Contract/HierarchyServiceInterface.php`

```
interface HierarchyServiceInterface
```

Read methods (return DTOs):

```
listForums(?int $parentId = null): array
```
Returns `array<int, ForumDTO>` — direct children of `$parentId` (or all root forums if null).

```
getForum(int $id): ?ForumDTO
```
Returns `ForumDTO` or `null`.

```
getTree(?int $rootId = null): array
```
Returns all forums in DFS order (full subtree from root), `array<int, ForumDTO>`.

```
getPath(int $id): array
```
Returns `array<int, ForumDTO>` from root to forum (breadcrumb).

Mutation methods (return `DomainEventCollection`):

```
createForum(CreateForumRequest $request): DomainEventCollection
```
Creates forum row + positions in tree (single transaction). Returns collection containing `ForumCreatedEvent`.
Throws: `RepositoryException`, `\InvalidArgumentException`

```
updateForum(UpdateForumRequest $request): DomainEventCollection
```
Updates non-null fields. Returns collection containing `ForumUpdatedEvent`.
Throws: `RepositoryException`, `\InvalidArgumentException`

```
deleteForum(int $forumId, int $actorId = 0): DomainEventCollection
```
Removes from tree + deletes row. Returns collection containing `ForumDeletedEvent`.
Throws: `RepositoryException`, `\InvalidArgumentException`

```
moveForum(int $forumId, int $newParentId, int $actorId = 0): DomainEventCollection
```
Moves under new parent. Returns collection containing `ForumMovedEvent`.
Throws: `RepositoryException`, `\InvalidArgumentException`

### `TrackingServiceInterface`

**Namespace**: `phpbb\hierarchy\Contract`

```
interface TrackingServiceInterface
```

Methods:
```
markRead(int $userId, int $forumId): void
```
Upserts `phpbb_forums_track` row with current timestamp.

```
markAllRead(int $userId): void
```
Upserts all forums as read for user (or deletes all track rows — implementation may delete).

```
isUnread(int $userId, int $forumId): bool
```
Returns `true` if no track row exists (or `mark_time` is stale).

```
getUnreadStatus(int $userId, array $forumIds): array
```
Returns `array<int, bool>` keyed by `forum_id` — `true` = unread.

### `SubscriptionServiceInterface`

**Namespace**: `phpbb\hierarchy\Contract`

```
interface SubscriptionServiceInterface
```

Methods:
```
subscribe(int $userId, int $forumId): void
subscribe(int $userId, int $forumId): void    // idempotent upsert, notify_status=1

unsubscribe(int $userId, int $forumId): void  // deletes row or returns silently if absent

isSubscribed(int $userId, int $forumId): bool

getSubscribers(int $forumId): array           // returns array<int> of user_ids where notify_status=1
```

---

## 7. Domain Events

All events extend `phpbb\common\Event\DomainEvent`.

### `ForumCreatedEvent`

**Namespace**: `phpbb\hierarchy\Event`

```
final readonly class ForumCreatedEvent extends DomainEvent
```

Additional constructor properties (in addition to `entityId`, `actorId`, `occurredAt`):
- `public Forum $forum` — the created entity
- `public ?int $parentId` — the parent id (0 = root)

Constructor: `__construct(Forum $forum, int $actorId = 0)` — calls `parent::__construct($forum->id, $actorId)`, sets `$this->forum = $forum`, `$this->parentId = $forum->parentId`.

### `ForumUpdatedEvent`

Additional properties:
- `public Forum $forum` — the updated entity
- `public array $changedFields` — keys of fields that were changed

Constructor: `__construct(Forum $forum, array $changedFields, int $actorId = 0)`

### `ForumDeletedEvent`

Additional properties:
- `public int $forumId` — could not reference Forum entity (it's deleted)
- `public int $parentId`

Constructor: `__construct(int $forumId, int $parentId, int $actorId = 0)` — calls `parent::__construct($forumId, $actorId)`.

### `ForumMovedEvent`

Additional properties:
- `public Forum $forum` — the moved entity (with new parentId)
- `public int $oldParentId`

Constructor: `__construct(Forum $forum, int $oldParentId, int $actorId = 0)`

### `RegisterForumTypesEvent`

**Note**: Not a `DomainEvent` — this is a boot-time event. Does NOT extend `DomainEvent`.

**Purpose**: Fired during container boot (or on first ForumTypeRegistry use) to let plugins register custom `ForumType` values via `ForumTypeBehaviorInterface`.

Class: `final class RegisterForumTypesEvent`
Method: `register(ForumType|int $type, ForumTypeBehaviorInterface $behavior): void`
Method: `getRegistrations(): array` — returns `array<int, ForumTypeBehaviorInterface>`

---

## 8. Plugin System

### `ForumTypeBehaviorInterface`

**Namespace**: `phpbb\hierarchy\Plugin`

```
interface ForumTypeBehaviorInterface
```

Methods:
- `canHaveContent(): bool` — true for `ForumType::Forum` only
- `canHaveChildren(): bool` — true for `ForumType::Category` and `ForumType::Forum`
- `requiresLink(): bool` — true for `ForumType::Link` only
- `getEditableFields(): array` — returns `string[]` of field names editable for this type
- `validate(CreateForumRequest|UpdateForumRequest $request): array` — returns `string[]` of validation errors

### `ForumTypeRegistry`

**Namespace**: `phpbb\hierarchy\Plugin`

Constructor: `__construct(private readonly EventDispatcherInterface $dispatcher)`

Private state: `private ?array $behaviors = null` (lazy-loaded)

Methods:
- `getBehavior(ForumType $type): ForumTypeBehaviorInterface` — lazy-initializes, dispatches `RegisterForumTypesEvent`, returns exact behavior; throws `\InvalidArgumentException` if type not registered
- `private initialize(): void` — dispatches `RegisterForumTypesEvent`, registers built-in behaviors (Category, Forum, Link), merges plugin registrations

### `CategoryBehavior`, `ForumBehavior`, `LinkBehavior`

Each implements `ForumTypeBehaviorInterface` with appropriate boolean returns.

| Method | Category | Forum | Link |
|---|---|---|---|
| `canHaveContent()` | `false` | `true` | `false` |
| `canHaveChildren()` | `true` | `true` | `false` |
| `requiresLink()` | `false` | `false` | `true` |

---

## 9. DBAL 4 Repository Specification

### `DbalForumRepository`

**Namespace**: `phpbb\hierarchy\Repository`
**File**: `src/phpbb/hierarchy/Repository/DbalForumRepository.php`
**Implements**: `ForumRepositoryInterface`

```
private const TABLE = 'phpbb_forums';
```

Constructor:
```
__construct(private readonly \Doctrine\DBAL\Connection $connection)
```

#### Method: `findById(int $id): ?Forum`

```sql
SELECT * FROM phpbb_forums WHERE forum_id = :id LIMIT 1
```
Params: `['id' => $id]`
Returns: `$row !== false ? $this->hydrate($row) : null`

#### Method: `findAll(): array`

```sql
SELECT * FROM phpbb_forums ORDER BY left_id ASC
```
Returns keyed array: `$result[$entity->id] = $entity`

#### Method: `findChildren(int $parentId): array`

```sql
SELECT * FROM phpbb_forums WHERE parent_id = :parentId ORDER BY left_id ASC
```
Params: `['parentId' => $parentId]`
Returns keyed array by `forum_id`.

#### Method: `insertRaw(CreateForumRequest $request): int`

```sql
INSERT INTO phpbb_forums
    (forum_name, forum_type, forum_desc, forum_link, forum_status, parent_id,
     display_on_index, display_subforum_list, enable_indexing, enable_icons,
     forum_style, forum_image, forum_rules, forum_rules_link, forum_password,
     forum_topics_per_page, forum_flags, forum_parents, left_id, right_id,
     forum_posts_approved, forum_posts_unapproved, forum_posts_softdeleted,
     forum_topics_approved, forum_topics_unapproved, forum_topics_softdeleted,
     forum_last_post_id, forum_last_poster_id, forum_last_post_subject,
     forum_last_post_time, forum_last_poster_name, forum_last_poster_colour,
     prune_next, prune_days, prune_viewed, prune_freq, enable_prune)
VALUES
    (:forumName, :forumType, :forumDesc, :forumLink, :forumStatus, :parentId,
     :displayOnIndex, :displaySubforumList, :enableIndexing, :enableIcons,
     :forumStyle, :forumImage, :forumRules, :forumRulesLink, :forumPassword,
     :topicsPerPage, :forumFlags, '', 0, 0,
     0, 0, 0, 0, 0, 0,
     0, 0, '', 0, '', '',
     0, 0, 0, 0, 0)
```

Param array:
```
'forumName'          => $request->name,
'forumType'          => $request->type->value,
'forumDesc'          => $request->description,
'forumLink'          => $request->link,
'forumStatus'        => ForumStatus::Unlocked->value,
'parentId'           => $request->parentId,
'displayOnIndex'     => (int) $request->displayOnIndex,
'displaySubforumList'=> (int) $request->displaySubforumList,
'enableIndexing'     => (int) $request->enableIndexing,
'enableIcons'        => (int) $request->enableIcons,
'forumStyle'         => $request->style,
'forumImage'         => $request->image,
'forumRules'         => $request->rules,
'forumRulesLink'     => $request->rulesLink,
'forumPassword'      => $request->password,
'topicsPerPage'      => $request->topicsPerPage,
'forumFlags'         => $request->flags,
```

Returns: `(int) $this->connection->lastInsertId()`

#### Method: `update(UpdateForumRequest $request): Forum`

Build dynamic SET clause from non-null fields. Use a `$sets = []` / `$params = []` pattern:

For each field in `UpdateForumRequest` (except `$forumId` and `$actorId`):
- If the property is not null, add `"forum_column = :param"` to `$sets`, add value to `$params`

Special cases:
- `$request->type !== null` → `forum_type = :forumType`, param value `$request->type->value`
- `$request->clearPassword === true` → `forum_password = :forumPassword`, param value `''`
- `$request->password !== null && $request->clearPassword !== true` → `forum_password = :forumPassword`

SQL after building sets:
```sql
UPDATE phpbb_forums SET {implode(',', $sets)} WHERE forum_id = :forumId
```

After update, reload: `return $this->findById($request->forumId) ?? throw new \InvalidArgumentException(...)`

#### Method: `delete(int $forumId): void`

```sql
DELETE FROM phpbb_forums WHERE forum_id = :forumId
```
Params: `['forumId' => $forumId]`

#### Method: `updateTreePosition(int $forumId, int $leftId, int $rightId, int $parentId): void`

```sql
UPDATE phpbb_forums
SET left_id = :leftId, right_id = :rightId, parent_id = :parentId
WHERE forum_id = :forumId
```
Params: `['forumId' => $forumId, 'leftId' => $leftId, 'rightId' => $rightId, 'parentId' => $parentId]`

#### Method: `shiftLeftIds(int $threshold, int $delta): void`

```sql
UPDATE phpbb_forums SET left_id = left_id + :delta WHERE left_id >= :threshold
```
Params: `['delta' => $delta, 'threshold' => $threshold]`

#### Method: `shiftRightIds(int $threshold, int $delta): void`

```sql
UPDATE phpbb_forums SET right_id = right_id + :delta WHERE right_id >= :threshold
```
Params: `['delta' => $delta, 'threshold' => $threshold]`

#### Method: `private hydrate(array $row): Forum`

Constructs `Forum` using named arguments:

```
id:                  (int) $row['forum_id'],
name:                $row['forum_name'],
description:         $row['forum_desc'],
descriptionBitfield: $row['forum_desc_bitfield'],
descriptionOptions:  (int) $row['forum_desc_options'],
descriptionUid:      $row['forum_desc_uid'],
parentId:            (int) $row['parent_id'],
leftId:              (int) $row['left_id'],
rightId:             (int) $row['right_id'],
type:                ForumType::from((int) $row['forum_type']),
status:              ForumStatus::from((int) $row['forum_status']),
image:               $row['forum_image'],
rules:               $row['forum_rules'],
rulesLink:           $row['forum_rules_link'],
rulesBitfield:       $row['forum_rules_bitfield'],
rulesOptions:        (int) $row['forum_rules_options'],
rulesUid:            $row['forum_rules_uid'],
link:                $row['forum_link'],
password:            $row['forum_password'],
style:               (int) $row['forum_style'],
topicsPerPage:       (int) $row['forum_topics_per_page'],
flags:               (int) $row['forum_flags'],
options:             (int) $row['forum_options'],
displayOnIndex:      (bool) $row['display_on_index'],
displaySubforumList: (bool) $row['display_subforum_list'],
enableIndexing:      (bool) $row['enable_indexing'],
enableIcons:         (bool) $row['enable_icons'],
stats:               new ForumStats(
    postsApproved:     (int) $row['forum_posts_approved'],
    postsUnapproved:   (int) $row['forum_posts_unapproved'],
    postsSoftdeleted:  (int) $row['forum_posts_softdeleted'],
    topicsApproved:    (int) $row['forum_topics_approved'],
    topicsUnapproved:  (int) $row['forum_topics_unapproved'],
    topicsSoftdeleted: (int) $row['forum_topics_softdeleted'],
),
lastPost:            new ForumLastPost(
    postId:       (int) $row['forum_last_post_id'],
    posterId:     (int) $row['forum_last_poster_id'],
    subject:      $row['forum_last_post_subject'],
    time:         (int) $row['forum_last_post_time'],
    posterName:   $row['forum_last_poster_name'],
    posterColour: $row['forum_last_poster_colour'],
),
pruneSettings:       new ForumPruneSettings(
    enabled:   (bool) $row['enable_prune'],
    days:      (int) $row['prune_days'],
    viewed:    (int) $row['prune_viewed'],
    frequency: (int) $row['prune_freq'],
    next:      (int) $row['prune_next'],
),
parents:             $this->decodeParents($row['forum_parents']),
```

#### Method: `private decodeParents(string $raw): array`

```
if ($raw === '') return [];
$decoded = json_decode($raw, true);
if (is_array($decoded)) return $decoded;
// fallback: try PHP unserialize for legacy data
$unserialized = @unserialize($raw);
return is_array($unserialized) ? $unserialized : [];
```

#### Exception Wrapping

Every public method body wrapped in:
```
try {
    // ...DBAL calls...
} catch (\Doctrine\DBAL\Exception $e) {
    throw new RepositoryException('...', previous: $e);
}
```

`RepositoryException` is `phpbb\db\Exception\RepositoryException`.

---

## 10. Nested Set Algorithms (`TreeService`)

**Namespace**: `phpbb\hierarchy\Service`
**Implements**: `TreeServiceInterface`

Constructor:
```
__construct(private readonly ForumRepositoryInterface $repository)
```

Note: `ForumRepositoryInterface` provides all SQL through `shiftLeftIds()`, `shiftRightIds()`, `updateTreePosition()`, and `findById()`. `TreeService` itself does NOT execute direct SQL — it delegates to the repository. This keeps tree logic pure and testable.

Exception: `getSubtree()` and `getPath()` require SQL not covered by the repository interface. These two methods inject `\Doctrine\DBAL\Connection` directly. Add `Connection` as second constructor argument.

Constructor:
```
__construct(
    private readonly ForumRepositoryInterface $repository,
    private readonly \Doctrine\DBAL\Connection $connection,
)
```

### Algorithm: `insertAtPosition(int $forumId, int $parentId)`

Atomically positions a newly inserted node (with left_id=0, right_id=0) as the last child of `$parentId`.

Steps inside `$this->connection->transactional(function() use (...))`:

1. **Lock & fetch parent**:
   ```sql
   SELECT forum_id, left_id, right_id FROM phpbb_forums WHERE forum_id = :parentId FOR UPDATE
   ```
   Params: `['parentId' => $parentId]`
   If no row: throw `\InvalidArgumentException("Parent forum {$parentId} not found")`

2. **Determine insert position**: `$insertPos = $parent['right_id']`

3. **Shift existing nodes**: The new node will occupy positions `$insertPos` and `$insertPos + 1`.
   ```
   shiftLeftIds(threshold: $insertPos, delta: 2)
   shiftRightIds(threshold: $insertPos, delta: 2)
   ```
   (These shift all nodes with left_id >= insertPos and right_id >= insertPos by +2)

4. **Set new node position**:
   ```
   updateTreePosition(
       forumId: $forumId,
       leftId:  $insertPos,
       rightId: $insertPos + 1,
       parentId: $parentId,
   )
   ```

**SQLite compatibility**: SQLite does not throw on `SELECT ... FOR UPDATE` — DBAL silently omits the `FOR UPDATE` clause, and SQLite's WAL-mode transaction provides adequate serialization for the testing environment. No workaround needed.

### Algorithm: `removeNode(int $forumId)`

Removes node and its subtree from the tree (does NOT delete rows — caller decides).

Steps inside `$this->connection->transactional(function() use (...))`:

1. **Fetch node**:
   ```sql
   SELECT left_id, right_id FROM phpbb_forums WHERE forum_id = :forumId FOR UPDATE
   ```
   If not found: throw `\InvalidArgumentException`

2. **Calculate subtree width**: `$width = $node['right_id'] - $node['left_id'] + 1`

3. **Close gap**:
   ```
   shiftLeftIds(threshold: $node['right_id'] + 1, delta: -$width)
   shiftRightIds(threshold: $node['right_id'] + 1, delta: -$width)
   ```

   Also zero-out the deleted node's left/right to prevent dangling references:
   ```sql
   UPDATE phpbb_forums SET left_id = 0, right_id = 0
   WHERE left_id >= :leftId AND right_id <= :rightId
   ```
   Params: `['leftId' => $node['left_id'], 'rightId' => $node['right_id']]`
   This marks rows for deletion without deleting them (repository.delete() handles that).

### Algorithm: `moveNode(int $forumId, int $newParentId)`

Steps inside `$this->connection->transactional(function() use (...))`:

1. Fetch node (SELECT FOR UPDATE), fetch new parent (SELECT FOR UPDATE)
2. Guard against cycle: if `$newParentId` is within the node's subtree, throw `\InvalidArgumentException`
3. Calculate subtree size: `$size = $node['right_id'] - $node['left_id'] + 1`
4. **Step A — Extract subtree**: mark subtree node left/right as negative to exclude from shift steps:
   ```sql
   UPDATE phpbb_forums SET left_id = left_id * -1, right_id = right_id * -1
   WHERE left_id >= :leftId AND right_id <= :rightId
   ```
5. **Step B — Close gap at old position**:
   ```
   shiftLeftIds(threshold: $node['right_id'] + 1, delta: -$size)
   shiftRightIds(threshold: $node['right_id'] + 1, delta: -$size)
   ```
   Note: shiftLeftIds/shiftRightIds must use `WHERE left_id >= :threshold` — since subtree rows have negative left_id, they are not affected.

6. **Step C — Re-fetch new parent** (its position may have shifted):
   ```sql
   SELECT left_id, right_id FROM phpbb_forums WHERE forum_id = :newParentId
   ```

7. **Step D — Open gap at new position** (`$insertPos = $newParent['right_id']`):
   ```
   shiftLeftIds(threshold: $insertPos, delta: $size)
   shiftRightIds(threshold: $insertPos, delta: $size)
   ```

8. **Step E — Place subtree at new position**: offset = `$insertPos - $node['left_id']`
   ```sql
   UPDATE phpbb_forums
   SET left_id  = (left_id  * -1) + :offset,
       right_id = (right_id * -1) + :offset
   WHERE left_id < 0
   ```
   Params: `['offset' => $offset]`

9. **Step F — Update parent_id** of the moved root:
   ```
   updateTreePosition(forumId: $forumId, leftId: $insertPos + <adjusted>, rightId: ..., parentId: $newParentId)
   ```
   (After step E, re-query the node to get its new left/right, then call updateTreePosition for parent_id only.)

### Algorithm: `getSubtree(?int $rootId)`

```sql
-- If $rootId is null:
SELECT * FROM phpbb_forums ORDER BY left_id ASC

-- If $rootId is given (need root's left/right first):
SELECT left_id, right_id FROM phpbb_forums WHERE forum_id = :rootId
-- then:
SELECT * FROM phpbb_forums
WHERE left_id >= :leftId AND right_id <= :rightId
ORDER BY left_id ASC
```

Returns keyed array `Forum[]` — keyed by `forum_id`.

### Algorithm: `getPath(int $forumId)`

```sql
-- Step 1: get node's left/right
SELECT left_id, right_id FROM phpbb_forums WHERE forum_id = :forumId

-- Step 2: get all ancestors (including the node itself)
SELECT * FROM phpbb_forums
WHERE left_id <= :nodeLeftId AND right_id >= :nodeRightId
ORDER BY left_id ASC
```

Returns keyed `Forum[]` representing the path from root to node.

### Algorithm: `rebuildTree()`

This is a repair utility. Implementation fetches all forums ordered by `parent_id, forum_id`, then recursively assigns `left_id`/`right_id` values starting from 1, updating each row. Implementation complexity: O(3n) DB writes. Spec for phase 1: implement as a simple recursive PHP function that rebuilds the entire tree in-memory then issues UPDATE statements. Full batching can be a future optimization.

---

## 11. `HierarchyService` (Facade)

**Namespace**: `phpbb\hierarchy\Service`
**Implements**: `HierarchyServiceInterface`

Constructor:
```
__construct(
    private readonly ForumRepositoryInterface $repository,
    private readonly TreeServiceInterface $treeService,
    private readonly \Doctrine\DBAL\Connection $connection,
    private readonly ForumTypeRegistry $typeRegistry,
    private readonly array $requestDecorators = [],    // RequestDecoratorInterface[]
    private readonly array $responseDecorators = [],   // ResponseDecoratorInterface[]
)
```

Note: `EventDispatcherInterface` is NOT injected here — per DOMAIN_EVENTS.md, dispatching is the controller's responsibility.

### Read methods

`listForums(?int $parentId = null): array` — calls `$parentId === null ? $this->repository->findAll() : $this->repository->findChildren($parentId)`, maps to `ForumDTO::fromEntity()`.

`getForum(int $id): ?ForumDTO` — calls `findById($id)`, maps to DTO or null.

`getTree(?int $rootId = null): array` — delegates to `$this->treeService->getSubtree($rootId)`, maps each `Forum` to `ForumDTO`.

`getPath(int $id): array` — delegates to `$this->treeService->getPath($id)`, maps to `ForumDTO[]`.

### Mutation: `createForum(CreateForumRequest $request): DomainEventCollection`

1. Apply request decorators: `foreach ($this->requestDecorators as $dec) { if ($dec->supports($request)) { $request = $dec->decorateRequest($request); } }`
2. Validate via type registry: `$this->typeRegistry->getBehavior($request->type)->validate($request)` — if errors, throw `\InvalidArgumentException`
3. Wrap in transaction:
   ```
   $this->connection->transactional(function() use ($request, &$forum) {
       $forumId = $this->repository->insertRaw($request);
       $this->treeService->insertAtPosition($forumId, $request->parentId);
       $forum = $this->repository->findById($forumId);
   });
   ```
4. Return `new DomainEventCollection([new ForumCreatedEvent($forum, $request->actorId)])`

### Mutation: `updateForum(UpdateForumRequest $request): DomainEventCollection`

1. Apply decorators
2. Call `$forum = $this->repository->update($request)`
3. Return `new DomainEventCollection([new ForumUpdatedEvent($forum, [...changed fields...], $request->actorId)])`

### Mutation: `deleteForum(int $forumId, int $actorId = 0): DomainEventCollection`

1. Fetch forum first (to get parentId for event): `$forum = $this->repository->findById($forumId)` — throw if null
2. `$this->connection->transactional(function() use ($forumId) { $this->treeService->removeNode($forumId); $this->repository->delete($forumId); })`
3. Return `new DomainEventCollection([new ForumDeletedEvent($forumId, $forum->parentId, $actorId)])`

### Mutation: `moveForum(int $forumId, int $newParentId, int $actorId = 0): DomainEventCollection`

1. Get old parentId: `$oldForum = $this->repository->findById($forumId)` — throw if null
2. `$this->treeService->moveNode($forumId, $newParentId)` (internally transactional)
3. Reload: `$forum = $this->repository->findById($forumId)`
4. Return `new DomainEventCollection([new ForumMovedEvent($forum, $oldForum->parentId, $actorId)])`

---

## 12. `TrackingService`

**Namespace**: `phpbb\hierarchy\Service`
**Implements**: `TrackingServiceInterface`

```
private const TABLE = 'phpbb_forums_track';
```

Constructor: `__construct(private readonly \Doctrine\DBAL\Connection $connection)`

### `markRead(int $userId, int $forumId): void`

Platform-switched upsert (same pattern as `DbalGroupRepository::addMember`):

MySQLPlatform:
```sql
INSERT INTO phpbb_forums_track (user_id, forum_id, mark_time)
VALUES (:userId, :forumId, :markTime)
ON DUPLICATE KEY UPDATE mark_time = :markTime
```

Other (SQLite): DELETE then INSERT in `transactional()`.

`mark_time` = `time()` (current Unix timestamp).

### `markAllRead(int $userId): void`

```sql
DELETE FROM phpbb_forums_track WHERE user_id = :userId
```
(Removes all tracking rows — equivalent to "mark all read" since absence of a row means "no tracked read time", but since isUnread() checks for row existence, deleting all means nothing is tracked as read. Correction: the correct semantic for "mark all read" is to upsert all existing forum IDs. However, for phase 1 simplification: delete all existing rows and insert one row per forum with current timestamp. This is done by fetching all forum IDs then bulk inserting.)

Simpler phase-1 implementation: Delete all rows for user (effectively resetting tracking). The display layer can use a "last global read time" stored elsewhere. Spec says: delete all `phpbb_forums_track` rows for `$userId`. Note the limitation in section 16.

### `isUnread(int $userId, int $forumId): bool`

```sql
SELECT mark_time FROM phpbb_forums_track
WHERE user_id = :userId AND forum_id = :forumId
LIMIT 1
```
Returns `true` if no row found (`$row === false`).

### `getUnreadStatus(int $userId, array $forumIds): array`

```sql
SELECT forum_id FROM phpbb_forums_track
WHERE user_id = :userId AND forum_id IN (?)
```
Uses `ArrayParameterType::INTEGER` for the IN list.
Build complete result: all forums not in result set are unread (`true`), all in the set are read (`false`).

---

## 13. `SubscriptionService`

**Namespace**: `phpbb\hierarchy\Service`
**Implements**: `SubscriptionServiceInterface`

```
private const TABLE = 'phpbb_forums_watch';
```

Constructor: `__construct(private readonly \Doctrine\DBAL\Connection $connection)`

### `subscribe(int $userId, int $forumId): void`

Platform-switched upsert — sets `notify_status = 1`.

MySQLPlatform:
```sql
INSERT INTO phpbb_forums_watch (forum_id, user_id, notify_status)
VALUES (:forumId, :userId, 1)
ON DUPLICATE KEY UPDATE notify_status = 1
```

SQLite: DELETE then INSERT in `transactional()`.

### `unsubscribe(int $userId, int $forumId): void`

```sql
DELETE FROM phpbb_forums_watch
WHERE forum_id = :forumId AND user_id = :userId
```
Silently successful if no row exists.

### `isSubscribed(int $userId, int $forumId): bool`

```sql
SELECT notify_status FROM phpbb_forums_watch
WHERE forum_id = :forumId AND user_id = :userId
LIMIT 1
```
Returns `true` if row exists.

### `getSubscribers(int $forumId): array`

```sql
SELECT user_id FROM phpbb_forums_watch
WHERE forum_id = :forumId AND notify_status = 1
```
Returns `array<int>` of user IDs (plain integers, not keyed).

---

## 14. REST API Changes: `ForumsController`

**File**: `src/phpbb/api/Controller/ForumsController.php`

### Changes

1. Remove `MOCK_FORUMS` constant and all mock data.
2. Add constructor:
   ```
   __construct(
       private readonly HierarchyServiceInterface $hierarchyService,
       private readonly EventDispatcherInterface $dispatcher,
   )
   ```
3. Add all routes.

### Route Definitions

| Method | Path | Route name | Auth required |
|---|---|---|---|
| GET | `/forums` | `api_v1_forums_index` | No |
| GET | `/forums/{forumId}` | `api_v1_forums_show` | No |
| POST | `/forums` | `api_v1_forums_create` | Yes (JWT claims, `acp` flag) |
| PATCH | `/forums/{forumId}` | `api_v1_forums_update` | Yes (JWT claims, `acp` flag) |
| DELETE | `/forums/{forumId}` | `api_v1_forums_delete` | Yes (JWT claims, `acp` flag) |
| GET | `/forums/{forumId}/children` | `api_v1_forums_children` | No |
| GET | `/forums/{forumId}/path` | `api_v1_forums_path` | No |

### Method Signatures

```
#[Route('/forums', name: 'api_v1_forums_index', methods: ['GET'])]
public function index(): JsonResponse

#[Route('/forums/{forumId}', name: 'api_v1_forums_show', methods: ['GET'])]
public function show(int $forumId): JsonResponse

#[Route('/forums', name: 'api_v1_forums_create', methods: ['POST'])]
public function create(Request $request): JsonResponse

#[Route('/forums/{forumId}', name: 'api_v1_forums_update', methods: ['PATCH'])]
public function update(int $forumId, Request $request): JsonResponse

#[Route('/forums/{forumId}', name: 'api_v1_forums_delete', methods: ['DELETE'])]
public function delete(int $forumId, Request $request): JsonResponse

#[Route('/forums/{forumId}/children', name: 'api_v1_forums_children', methods: ['GET'])]
public function children(int $forumId): JsonResponse

#[Route('/forums/{forumId}/path', name: 'api_v1_forums_path', methods: ['GET'])]
public function path(int $forumId): JsonResponse
```

### Response Shapes

**`index()`**:
```json
{ "data": [...ForumDTO objects...], "meta": { "total": N } }
```

**`show(int $forumId)`**:
- 200: `{ "data": { ...ForumDTO } }`
- 404: `{ "error": "Forum not found", "status": 404 }`

**`create(Request $request)`**:
- Parse body: `$body = json_decode($request->getContent(), true) ?? []`
- Validate: `name` required (string), `type` required (0, 1, or 2). On missing: 422 with `errors` array.
- Extract actorId from JWT: `$token = $request->attributes->get('_api_token'); $actorId = $token?->userId ?? 0`
- Check ACP flag: `if (!in_array('acp', $token?->flags ?? [], true)) return 403`
- Build `CreateForumRequest`, call `createForum()`, dispatch events:
  ```
  $events = $this->hierarchyService->createForum($req);
  $events->dispatch($this->dispatcher);
  $forumId = $events->first()->forum->id;
  $dto = $this->hierarchyService->getForum($forumId);
  return new JsonResponse(['data' => $dto], 201);
  ```

**`update(int $forumId, Request $request)`**:
- Build `UpdateForumRequest` from body (all optional fields)
- Call `updateForum()`, dispatch events.
- 200: `{ "data": { ...updated ForumDTO } }`
- 404 if forum not found (catch `\InvalidArgumentException`)

**`delete(int $forumId, Request $request)`**:
- Auth check (acp flag)
- Call `deleteForum($forumId, $actorId)`, dispatch events.
- 204: empty response

**`children(int $forumId)`**:
```json
{ "data": [...], "meta": { "total": N } }
```

**`path(int $forumId)`**:
```json
{ "data": [...ForumDTO in order from root to target...], "meta": { "total": N } }
```

---

## 15. `services.yaml` Changes

Add to `src/phpbb/config/services.yaml` after the auth module section:

```yaml
    # ---------------------------------------------------------------------------
    # Hierarchy module (M5)
    # ---------------------------------------------------------------------------

    # Common domain events (prerequisites)
    phpbb\common\Event\DomainEventCollection: ~

    # Hierarchy repository
    phpbb\hierarchy\Repository\DbalForumRepository: ~

    phpbb\hierarchy\Contract\ForumRepositoryInterface:
        alias: phpbb\hierarchy\Repository\DbalForumRepository

    # Hierarchy services
    phpbb\hierarchy\Service\TreeService: ~

    phpbb\hierarchy\Contract\TreeServiceInterface:
        alias: phpbb\hierarchy\Service\TreeService

    phpbb\hierarchy\Service\TrackingService: ~

    phpbb\hierarchy\Contract\TrackingServiceInterface:
        alias: phpbb\hierarchy\Service\TrackingService

    phpbb\hierarchy\Service\SubscriptionService: ~

    phpbb\hierarchy\Contract\SubscriptionServiceInterface:
        alias: phpbb\hierarchy\Service\SubscriptionService

    phpbb\hierarchy\Plugin\ForumTypeRegistry: ~

    phpbb\hierarchy\Service\HierarchyService:
        arguments:
            $requestDecorators: []
            $responseDecorators: []

    phpbb\hierarchy\Contract\HierarchyServiceInterface:
        alias: phpbb\hierarchy\Service\HierarchyService
        public: true
```

Note: `Doctrine\DBAL\Connection` is already defined and auto-injected via type hint. `EventDispatcherInterface` is auto-wired by Symfony.

---

## 16. Test Specifications

All test files must extend either `IntegrationTestCase` (for DB tests) or `PHPUnit\Framework\TestCase` (for unit tests). All use `#[Test]` attribute (no `@test` annotation). Tab indentation. GPL-2.0 file header.

### `DbalForumRepositoryTest`

**File**: `tests/phpbb/hierarchy/Repository/DbalForumRepositoryTest.php`
**Class**: `phpbb\Tests\hierarchy\Repository\DbalForumRepositoryTest`
**Extends**: `phpbb\Tests\Integration\IntegrationTestCase`

**`setUpSchema()`** must create:

```sql
CREATE TABLE phpbb_forums (
    forum_id               INTEGER PRIMARY KEY AUTOINCREMENT,
    parent_id              INTEGER NOT NULL DEFAULT 0,
    left_id                INTEGER NOT NULL DEFAULT 0,
    right_id               INTEGER NOT NULL DEFAULT 0,
    forum_parents          TEXT    NOT NULL DEFAULT '',
    forum_name             TEXT    NOT NULL DEFAULT '',
    forum_desc             TEXT    NOT NULL DEFAULT '',
    forum_desc_bitfield    TEXT    NOT NULL DEFAULT '',
    forum_desc_options     INTEGER NOT NULL DEFAULT 7,
    forum_desc_uid         TEXT    NOT NULL DEFAULT '',
    forum_link             TEXT    NOT NULL DEFAULT '',
    forum_password         TEXT    NOT NULL DEFAULT '',
    forum_style            INTEGER NOT NULL DEFAULT 0,
    forum_image            TEXT    NOT NULL DEFAULT '',
    forum_rules            TEXT    NOT NULL DEFAULT '',
    forum_rules_link       TEXT    NOT NULL DEFAULT '',
    forum_rules_bitfield   TEXT    NOT NULL DEFAULT '',
    forum_rules_options    INTEGER NOT NULL DEFAULT 7,
    forum_rules_uid        TEXT    NOT NULL DEFAULT '',
    forum_topics_per_page  INTEGER NOT NULL DEFAULT 0,
    forum_type             INTEGER NOT NULL DEFAULT 1,
    forum_status           INTEGER NOT NULL DEFAULT 0,
    forum_posts_approved     INTEGER NOT NULL DEFAULT 0,
    forum_posts_unapproved   INTEGER NOT NULL DEFAULT 0,
    forum_posts_softdeleted  INTEGER NOT NULL DEFAULT 0,
    forum_topics_approved    INTEGER NOT NULL DEFAULT 0,
    forum_topics_unapproved  INTEGER NOT NULL DEFAULT 0,
    forum_topics_softdeleted INTEGER NOT NULL DEFAULT 0,
    forum_last_post_id       INTEGER NOT NULL DEFAULT 0,
    forum_last_poster_id     INTEGER NOT NULL DEFAULT 0,
    forum_last_post_subject  TEXT    NOT NULL DEFAULT '',
    forum_last_post_time     INTEGER NOT NULL DEFAULT 0,
    forum_last_poster_name   TEXT    NOT NULL DEFAULT '',
    forum_last_poster_colour TEXT    NOT NULL DEFAULT '',
    forum_flags              INTEGER NOT NULL DEFAULT 32,
    forum_options            INTEGER NOT NULL DEFAULT 0,
    display_on_index         INTEGER NOT NULL DEFAULT 1,
    display_subforum_list    INTEGER NOT NULL DEFAULT 1,
    enable_indexing          INTEGER NOT NULL DEFAULT 1,
    enable_icons             INTEGER NOT NULL DEFAULT 0,
    prune_next               INTEGER NOT NULL DEFAULT 0,
    prune_days               INTEGER NOT NULL DEFAULT 0,
    prune_viewed             INTEGER NOT NULL DEFAULT 0,
    prune_freq               INTEGER NOT NULL DEFAULT 0,
    enable_prune             INTEGER NOT NULL DEFAULT 0
)
```

After schema, instantiate: `$this->repository = new DbalForumRepository($this->connection)`

**Helper method**: `insertForum(array $overrides = []): int` — merges defaults with overrides, inserts via `$this->connection->executeStatement(...)`, returns `lastInsertId()`.

**Test methods** (minimum 8, target 10):

| Method | What it tests |
|---|---|
| `testFindById_found_returnsHydratedForum` | findById returns correct entity with all mapped fields |
| `testFindById_notFound_returnsNull` | findById returns null for missing ID |
| `testFindAll_returnsAllForumsOrderedByLeftId` | findAll returns all rows, keyed by forum_id, left_id order |
| `testFindChildren_returnsDirectChildrenOnly` | findChildren with parentId=1 returns only direct children |
| `testFindChildren_emptyResult_returnsEmptyArray` | findChildren for childless forum returns [] |
| `testInsertRaw_persistsAllFields` | insertRaw returns a valid ID; findById confirms all fields stored |
| `testInsertRaw_setsTreePositionToZero` | insertRaw sets left_id=0 and right_id=0 (tree service positions later) |
| `testUpdate_changesOnlyNonNullFields` | update with partial request only modifies specified fields |
| `testDelete_removesRow` | delete makes findById return null |
| `testShiftLeftIds_shiftsCorrectRows` | shiftLeftIds only shifts rows above threshold; others unaffected |
| `testDecodeParents_jsonFormat_parsesCorrectly` | forum_parents with JSON string is decoded to array |
| `testDecodeParents_serializeFormat_parsesCorrectly` | legacy PHP serialize string decoded via fallback |

### `TreeServiceTest`

**File**: `tests/phpbb/hierarchy/Service/TreeServiceTest.php`
**Class**: `phpbb\Tests\hierarchy\Service\TreeServiceTest`
**Extends**: `phpbb\Tests\Integration\IntegrationTestCase`

`setUpSchema()`: Create same `phpbb_forums` schema as above.

After schema:
```
$this->repository = new DbalForumRepository($this->connection);
$this->treeService = new TreeService($this->repository, $this->connection);
```

**Test methods** (minimum 6):

| Method | What it tests |
|---|---|
| `testInsertAtPosition_rootForum_getsLeftId1RightId2` | single forum at root gets left_id=1, right_id=2 |
| `testInsertAtPosition_childForum_parentRightIdExpands` | inserting child shifts parent right_id to +2 |
| `testInsertAtPosition_siblingOrdering_correctLeftRightIds` | two siblings get consecutive left/right values |
| `testGetSubtree_returnsAllDescendantsInDfsOrder` | subtree of root returns all nested children ordered by left_id |
| `testGetPath_returnsAncestorChainFromRootToNode` | getPath returns [root, parent, node] in order |
| `testRemoveNode_closesGapInTree` | after removeNode, remaining nodes have correct left/right |
| `testMoveNode_reordersTreeCorrectly` | node moved under different parent reflects in left/right |
| `testInsertAtPosition_parentNotFound_throwsInvalidArgumentException` | non-existent parentId throws exception |

### `TrackingServiceTest`

**File**: `tests/phpbb/hierarchy/Service/TrackingServiceTest.php`
**Class**: `phpbb\Tests\hierarchy\Service\TrackingServiceTest`
**Extends**: `phpbb\Tests\Integration\IntegrationTestCase`

`setUpSchema()`:
```sql
CREATE TABLE phpbb_forums_track (
    user_id   INTEGER NOT NULL,
    forum_id  INTEGER NOT NULL,
    mark_time INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, forum_id)
)
```

After schema: `$this->service = new TrackingService($this->connection)`

**Test methods** (minimum 5):

| Method | What it tests |
|---|---|
| `testMarkRead_insertsTrackingRow` | markRead inserts row with current time |
| `testMarkRead_idempotent_updatesExistingRow` | calling markRead twice updates mark_time, no duplicate rows |
| `testIsUnread_noRow_returnsTrue` | no tracking row means forum is unread |
| `testIsUnread_afterMarkRead_returnsFalse` | after markRead, isUnread returns false |
| `testGetUnreadStatus_mixedResult_correctBooleanMap` | getUnreadStatus returns correct true/false per forum |
| `testMarkAllRead_deletesAllUserRows` | markAllRead removes all rows for given userId |

### `SubscriptionServiceTest`

**File**: `tests/phpbb/hierarchy/Service/SubscriptionServiceTest.php`
**Class**: `phpbb\Tests\hierarchy\Service\SubscriptionServiceTest`
**Extends**: `phpbb\Tests\Integration\IntegrationTestCase`

`setUpSchema()`:
```sql
CREATE TABLE phpbb_forums_watch (
    forum_id      INTEGER NOT NULL,
    user_id       INTEGER NOT NULL,
    notify_status INTEGER NOT NULL DEFAULT 0
)
```

**Test methods** (minimum 4):

| Method | What it tests |
|---|---|
| `testSubscribe_insertsWatchRow` | subscribe creates row with notify_status=1 |
| `testSubscribe_idempotent_noError` | calling subscribe twice causes no error or duplicate |
| `testUnsubscribe_removesRow` | unsubscribe deletes row; isSubscribed returns false |
| `testUnsubscribe_nonExistent_silentSuccess` | unsubscribe on non-existent row does not throw |
| `testIsSubscribed_afterSubscribe_returnsTrue` | isSubscribed returns true after subscribe |
| `testGetSubscribers_returnsOnlyNotifyStatusOne` | getSubscribers filters correctly on notify_status |

### `HierarchyServiceTest`

**File**: `tests/phpbb/hierarchy/Service/HierarchyServiceTest.php`
**Class**: `phpbb\Tests\hierarchy\Service\HierarchyServiceTest`
**Extends**: `PHPUnit\Framework\TestCase`

Dependencies: all mocked. Uses `createMock(ForumRepositoryInterface::class)`, `createMock(TreeServiceInterface::class)`, `createMock(\Doctrine\DBAL\Connection::class)`, `createMock(ForumTypeRegistry::class)`.

Use helper `makeService()` to instantiate `HierarchyService` with mocks.

**Test methods** (minimum 5):

| Method | What it tests |
|---|---|
| `testGetForum_found_returnsMappedDto` | getForum delegates to repository, maps to DTO |
| `testGetForum_notFound_returnsNull` | getForum returns null when repository returns null |
| `testCreateForum_returnsCollectionWithCreatedEvent` | createForum returns DomainEventCollection containing ForumCreatedEvent |
| `testCreateForum_callsInsertRawThenInsertAtPosition` | verifies both repo.insertRaw and treeService.insertAtPosition called |
| `testDeleteForum_callsRemoveNodeAndDelete` | verifies treeService.removeNode then repository.delete called |
| `testUpdateForum_returnsCollectionWithUpdatedEvent` | updateForum returns DomainEventCollection containing ForumUpdatedEvent |
| `testMoveForum_returnsCollectionWithMovedEvent` | moveForum returns DomainEventCollection containing ForumMovedEvent |
| `testListForums_noParentId_delegatesToFindAll` | listForums(null) calls repository->findAll() and maps all results to DTOs |

Note for HierarchyServiceTest: `$connection->transactional()` must be configured to execute its callback. Do this with:
```php
$this->connection->method('transactional')->willReturnCallback(fn($cb) => $cb($this->connection));
```

---

## 17. File Header Template

All new PHP files must start with:

```php
<?php

/**
 *
 * This file is part of the phpBB4 "Meridian" package.
 *
 * @copyright (c) Irek Kubicki <phpbb@codebuilders.pl>
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 * For full copyright and license information, please see
 * the docs/CREDITS.txt file.
 *
 */

declare(strict_types=1);

namespace phpbb\hierarchy\...;
```

No closing PHP tag. Tab indentation throughout.

---

## 18. Standards Compliance

| Standard | Applicable requirement |
|---|---|
| `.maister/docs/standards/global/STANDARDS.md` | PascalCase classes, PHPDoc only where native types insufficient, file headers, tab indent |
| `.maister/docs/standards/backend/STANDARDS.md` | `declare(strict_types=1)`, readonly constructor props, PHP 8.2 enums, match expressions, named args, constructor-only DI, no `global`, single-quote strings |
| `.maister/docs/standards/backend/REST_API.md` | `JsonResponse`, `data` top-level key, HTTP status codes, thin controllers, JWT Bearer auth via `_api_token` attribute, 422 validation shape |
| `.maister/docs/standards/backend/DOMAIN_EVENTS.md` | Mutations return `DomainEventCollection`; controllers dispatch; `DomainEvent` base class required; naming `{Entity}{Action}Event` |
| `.maister/docs/standards/testing/STANDARDS.md` | `#[Test]`, `#[DataProvider]` attributes (no annotations), `IntegrationTestCase` for DB tests, `createMock()` only, AAA structure, `assertSame()` over `assertEquals()` |

---

## 19. Acceptance Criteria

The implementation is complete when all of the following are true:

1. **`composer test` passes** — all PHPUnit tests green, minimum 40 tests total.

2. **Tree invariant**: After any insert/delete/move, the tree satisfies:
   - `SELECT MAX(right_id) = COUNT(*) * 2 FROM phpbb_forums` (tight numbering, no gaps)

3. **`GET /forums`** returns JSON with `data` array and `meta.total`. No mock data.

4. **`GET /forums/{id}`** returns 200 with forum entity or 404 with `error` key.

5. **`POST /forums`** without JWT returns 401; with JWT missing `acp` flag returns 403; valid request returns 201 with `data`.

6. **`PATCH /forums/{id}`** updates only supplied fields.

7. **`DELETE /forums/{id}`** returns 204 and removes the row from DB.

8. **`GET /forums/{id}/children`** returns direct children only.

9. **`GET /forums/{id}/path`** returns breadcrumb from root to target.

10. **All DBAL calls use named parameters with array keys WITHOUT `:` prefix** (e.g., `['id' => 1]` not `[':id' => 1]`).

11. **`RepositoryException` wraps all `\Doctrine\DBAL\Exception`** in all repository methods.

12. **`forum_parents` written as JSON** on every `insertRaw()` and `update()`. Legacy PHP serialize accepted on read.

13. **`ForumType::from()` and `ForumStatus::from()`** used for enum hydration — no raw integer comparisons.

14. **`DomainEventCollection`** returned by all four HierarchyService mutation methods.

15. **`services.yaml`** has all hierarchy service definitions; `HierarchyServiceInterface` alias is `public: true`.

16. **`composer cs:fix`** produces no changes (PSR-12 compliant, tab indent applied).

---

## 20. Known Limitations & Deferred Items

| Item | Deferred to |
|---|---|
| Cookie-based tracking for anonymous users (`CookieTrackingStrategy`) | Phase 2 |
| `DecoratorPipeline` with compiler pass auto-discovery of DI-tagged decorators | Phase 2 |
| `RegisterForumTypesEvent` dispatched at container boot (currently lazy on first registry use) | Phase 2 |
| `rebuildTree()` batch optimization (currently O(3n) individual UPDATEs) | Phase 2 |
| `markAllRead()` — precise implementation (upsert per forum, not DELETE-all) | Phase 2 |
| Counter update logic (post/topic counts on post create/delete) — see `COUNTER_PATTERN.md` | Threads service |
| Redis-backed tracking strategy | Phase 3 |
| E2E Playwright tests for hierarchy endpoints | Requires Docker |
| Admin panel / React frontend changes for hierarchy management | Frontend milestone |
| Search index integration with hierarchy events | Search service |
| ACL integration (`f_list`, `f_read`) — applied in display/controller layer by consumers | Not hierarchy responsibility |

---

## 21. Spec Corrections (Audit 2026-04-22)

This section OVERRIDES earlier sections where contradictions were found. Implementation MUST follow corrections over original spec text.

### C-01 FIX: deleteForum() must reject non-leaf forums

**Override for section 11 (`HierarchyService::deleteForum`)**:

Before removing from tree, check for children:
```php
$children = $this->repository->findChildren($forumId);
if (!empty($children)) {
    throw new \InvalidArgumentException(
        "Cannot delete forum {$forumId}: it has " . count($children) . " direct child forum(s). Move or delete children first."
    );
}
```

Controller (`delete()` method) catches `\InvalidArgumentException` and returns HTTP 400:
```php
return new JsonResponse(['error' => $e->getMessage(), 'status' => 400], 400);
```

**Test addition to HierarchyServiceTest**:
```
testDeleteForum_withChildren_throwsInvalidArgumentException
```

### C-02 FIX: Add moveForum REST endpoint

**Override for section 14 (Route Definitions table)**:

Add the following route:

| Method | Path | Route name | Auth required |
|---|---|---|---|
| PATCH | `/forums/{forumId}/move` | `api_v1_forums_move` | Yes (JWT claims, `acp` flag) |

**Method signature**:
```php
#[Route('/forums/{forumId}/move', name: 'api_v1_forums_move', methods: ['PATCH'])]
public function move(int $forumId, Request $request): JsonResponse
```

**Body**: `{ "new_parent_id": 5 }`

**Implementation**:
```php
$body = json_decode($request->getContent(), true) ?? [];
$newParentId = (int) ($body['new_parent_id'] ?? 0);
// auth check (acp flag)
$events = $this->hierarchyService->moveForum($forumId, $newParentId, $actorId);
$events->dispatch($this->dispatcher);
$dto = $this->hierarchyService->getForum($forumId);
return new JsonResponse(['data' => $dto], 200);
```

Catch `\InvalidArgumentException` → 404 or 422 depending on message (forum not found vs. same-parent or cycle).

### C-03 FIX: listForums(null) returns root forums only

**Override for section 11 (`HierarchyService::listForums`)**:

```
listForums(?int $parentId = null): array
```
Implementation:
```php
$forums = $this->repository->findChildren($parentId ?? 0);
return array_map(fn(Forum $f) => ForumDTO::fromEntity($f), $forums);
```

`findChildren(0)` returns all forums with `parent_id = 0` (root forums). This is NOT the same as `findAll()`.

`getTree()` continues to call `$this->treeService->getSubtree(null)` to get all forums.

**Test change in HierarchyServiceTest**:
```
testListForums_noParentId_delegatesToFindChildrenZero  // not findAll()
```

### C-04 FIX: Correct auth codes (401 vs 403)

**Override for section 14 (auth pattern in `create`, `update`, `delete`, `move` methods)**:

```php
$token = $request->attributes->get('_api_token');

// No token at all → 401 Unauthorized
if ($token === null) {
    return new JsonResponse(['error' => 'Authentication required', 'status' => 401], 401);
}

// Token present but missing acp flag → 403 Forbidden
if (!in_array('acp', $token->flags ?? [], true)) {
    return new JsonResponse(['error' => 'Insufficient permissions', 'status' => 403], 403);
}
```

This order is mandatory: check token existence BEFORE checking permissions.

### I-04 FIX: forum_parents initial value must be JSON

**Override for section 9 (`insertRaw` SQL)**:

Change `forum_parents` initial value from `''` (empty string) to `'[]'` (JSON empty array):

```sql
-- In the VALUES clause, change '' to '[]' for forum_parents position
```

The SQL literal in `insertRaw` inserts `'[]'` not `''` for the `forum_parents` column.

### I-05 FIX: moveNode() Step F — exact SQL for parent_id update

**Override for section 10, Algorithm `moveNode`, Step F**:

After Step E places the subtree at new position, update `parent_id` of only the moved root node:
```sql
UPDATE phpbb_forums
SET parent_id = :newParentId
WHERE forum_id = :forumId
```
Params: `['newParentId' => $newParentId, 'forumId' => $forumId]`

Do NOT call `updateTreePosition()` here — it would require re-fetching left/right which are already correct after Step E. Instead call `repository->updateParentId(int $forumId, int $parentId): void` — a new method on the repository:

```
updateParentId(int $forumId, int $parentId): void
```
```sql
UPDATE phpbb_forums SET parent_id = :parentId WHERE forum_id = :forumId
```

Add `updateParentId` to `ForumRepositoryInterface`.

### I-07 FIX: Invalidate forum_parents after moveForum

**Override for section 11 (`HierarchyService::moveForum`)**:

After `$this->treeService->moveNode($forumId, $newParentId)`, update `forum_parents` for all moved nodes:

For phase 1, simplified approach:
- Reset `forum_parents = '[]'` for all nodes in the moved subtree (they are stale):
  ```
  $subtree = $this->treeService->getSubtree($forumId);
  foreach (array_keys($subtree) as $id) {
      $this->repository->clearParentsCache($id);
  }
  ```

Add `clearParentsCache(int $forumId): void` to `ForumRepositoryInterface`:
```sql
UPDATE phpbb_forums SET forum_parents = '[]' WHERE forum_id = :forumId
```

(Proper breadcrumb cache rebuilding is deferred to Phase 2.)

### I-08 FIX: Define RequestDecoratorInterface and ResponseDecoratorInterface

Add to namespace structure (section 2) and create two files:

**File**: `src/phpbb/hierarchy/Plugin/RequestDecoratorInterface.php`
```php
interface RequestDecoratorInterface
{
    public function supports(CreateForumRequest|UpdateForumRequest $request): bool;
    public function decorateRequest(CreateForumRequest|UpdateForumRequest $request): CreateForumRequest|UpdateForumRequest;
}
```

**File**: `src/phpbb/hierarchy/Plugin/ResponseDecoratorInterface.php`
```php
interface ResponseDecoratorInterface
{
    public function supports(ForumDTO $dto): bool;
    public function decorateResponse(ForumDTO $dto): ForumDTO;
}
```

### I-09 FIX: Apply response decorators in read methods

**Override for section 11 (`HierarchyService` read methods)**:

`getForum()` applies response decorators:
```php
if ($dto === null) return null;
foreach ($this->responseDecorators as $dec) {
    if ($dec->supports($dto)) {
        $dto = $dec->decorateResponse($dto);
    }
}
return $dto;
```

Same pattern in `listForums()`, `getTree()`, `getPath()` for each DTO in the result array.

### I-10 FIX: Remove DomainEventCollection from services.yaml

**Override for section 15 (services.yaml)**:

Remove this line:
```yaml
phpbb\common\Event\DomainEventCollection: ~
```

`DomainEventCollection` is a value object instantiated with `new`, not a DI service.

### I-12 FIX: SubscriptionServiceInterface has duplicate subscribe()

**Override for section 6 (`SubscriptionServiceInterface`)**:

The interface has exactly these methods (one `subscribe`, one `unsubscribe`):
```
subscribe(int $userId, int $forumId): void    // idempotent upsert, notify_status=1
unsubscribe(int $userId, int $forumId): void  // deletes row; silent if absent
isSubscribed(int $userId, int $forumId): bool
getSubscribers(int $forumId): array           // returns int[] of user_ids where notify_status=1
```

The duplicate `subscribe()` line from the original spec is removed.

### I-03 FIX: Add UNIQUE constraint to phpbb_forums_watch test DDL

**Override for section 16 (`SubscriptionServiceTest setUpSchema`)**:

```sql
CREATE TABLE phpbb_forums_watch (
    forum_id      INTEGER NOT NULL,
    user_id       INTEGER NOT NULL,
    notify_status INTEGER NOT NULL DEFAULT 0,
    UNIQUE(forum_id, user_id)
)
```

### M-07 FIX: Add forum_options and forum_desc_bitfield to test DDL

The `phpbb_forums` DDL in `DbalForumRepositoryTest::setUpSchema()` (section 16) already contains `forum_options` and `forum_desc_bitfield`. Verify the CREATE TABLE in the hydrate() mapping matches all columns. The DDL in section 16 is authoritative and complete — use it exactly.

---

*End of corrections. All other sections remain as originally written.*
