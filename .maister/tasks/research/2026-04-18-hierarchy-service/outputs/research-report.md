# Research Report: `phpbb\hierarchy` Service Design

**Research Type**: Technical (codebase extraction + service design)
**Date**: 2026-04-18
**Confidence Level**: HIGH
**Sources Analyzed**: 6 findings files covering ~4000+ lines of legacy code

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Legacy Architecture Analysis](#2-legacy-architecture-analysis)
3. [Entity Model](#3-entity-model)
4. [Service Architecture](#4-service-architecture)
5. [Interface Signatures](#5-interface-signatures)
6. [Plugin Architecture](#6-plugin-architecture)
7. [Database Access Patterns](#7-database-access-patterns)
8. [Event Model](#8-event-model)
9. [Integration with phpbb\auth](#9-integration-with-phpbbauth)
10. [Integration with phpbb\user](#10-integration-with-phpbbuser)
11. [Migration Strategy](#11-migration-strategy)
12. [Risks & Open Questions](#12-risks--open-questions)

---

## 1. Executive Summary

The `phpbb\hierarchy` service provides a clean OOP interface for managing the forum/category tree, replacing the legacy combination of a 2245-line ACP module (`acp_forums.php`), a 700-line display function (`display_forums()`), and under-utilized `nestedset_forum` class. The service is decomposed into five components: `HierarchyService` (facade), `ForumRepository` (CRUD persistence), `TreeService` (nested set operations), `TrackingService` (per-user read status with dual DB/cookie paths), and `SubscriptionService` (forum watch/notification integration). All use direct PDO with parameterized queries, PHP 8.2 strict types, and PSR-4 namespacing. The plugin architecture leverages phpBB's proven `service_collection` pattern with tagged DI services, a `HierarchyPluginInterface` contract, and coarse-grained events dispatched at each CRUD boundary for extension hooks.

---

## 2. Legacy Architecture Analysis

### 2.1 Current Component Map

| Component | Location | LOC | Role |
|-----------|----------|-----|------|
| `nestedset` (abstract) | `src/phpbb/forums/tree/nestedset.php` | ~870 | Generic nested set: insert, delete, move, reparent, traverse |
| `nestedset_forum` | `src/phpbb/forums/tree/nestedset_forum.php` | ~50 | Forum-specific column mapping (forum_id, forum_parents) |
| `acp_forums` | `src/phpbb/common/acp/acp_forums.php` | 2245 | ACP CRUD — does its own raw SQL nested set, ignores `nestedset_forum` |
| `display_forums()` | `src/phpbb/common/functions_display.php` | ~700 | Monolithic display: SQL → ACL filter → tree walk → stat aggregation → template |
| `markread()` | `src/phpbb/common/functions.php` | ~230 | 4-mode read tracking with dual DB/cookie paths |
| `watch_topic_or_forum()` | `src/phpbb/common/functions_display.php` | ~200 | Subscribe/unsubscribe with hash validation |
| `generate_forum_nav()` | `src/phpbb/common/functions_display.php` | ~100 | Breadcrumb generation from `forum_parents` cache |

### 2.2 Key Problems in Legacy Architecture

1. **Dual nested-set implementations**: ACP does raw SQL; `nestedset_forum` class exists but is unused by ACP. Divergence risk.
2. **No domain objects**: Everything is `array`. No type safety, no validation boundaries.
3. **Global state dependency**: `global $db`, `global $user`, `global $auth`, `global $cache` throughout.
4. **700-line monolith**: `display_forums()` mixes SQL, ACL, tracking, template — untestable, un-reusable.
5. **No separation of concerns**: Validation, persistence, tree operations, cache invalidation, logging all in `update_forum_data()` (400 LOC).
6. **Serialized PHP in DB**: `forum_parents` uses `serialize()`/`unserialize()` — fragile, not queryable.

### 2.3 What Works Well (Preserve)

- Nested set algorithm is correct and battle-tested (~17 years)
- Advisory DB locking for concurrent mutations
- Dual-path tracking (DB for registered, cookies for anonymous) — load-bearing for UX
- `service_collection` DI pattern for plugin discovery
- Event dispatcher for extension hooks
- Denormalized counters and last-post data for display performance

---

## 3. Entity Model

### 3.1 Design Decision: Single Forum Entity

All three forum types (Category, Forum, Link) share the same database table, the same nested-set position columns, and the same parent-child relationships. They differ only in behavior (categories hold no posts, links redirect). A single entity with a `ForumType` enum captures this cleanly.

### 3.2 Forum Entity

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\entity;

enum ForumType: int
{
    case Category = 0;
    case Forum = 1;
    case Link = 2;
}

enum ForumStatus: int
{
    case Unlocked = 0;
    case Locked = 1;
}

final class Forum
{
    public function __construct(
        public readonly int $id,
        public readonly int $parentId,
        public readonly int $leftId,
        public readonly int $rightId,
        public readonly ForumType $type,
        public readonly ForumStatus $status,
        public readonly string $name,
        public readonly string $description,
        public readonly string $link,
        public readonly string $image,
        public readonly string $rules,
        public readonly string $rulesLink,
        public readonly string $password,
        public readonly int $style,
        public readonly int $topicsPerPage,
        public readonly int $flags,
        public readonly int $options,
        public readonly bool $displayOnIndex,
        public readonly bool $displaySubforumList,
        public readonly bool $displaySubforumLimit,
        public readonly bool $enableIndexing,
        public readonly bool $enableIcons,
        public readonly ForumStats $stats,
        public readonly ForumLastPost $lastPost,
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
        return ($this->rightId - $this->leftId - 1) / 2;
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

### 3.3 Value Objects

```php
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

### 3.4 DTOs for Create/Update

```php
final readonly class CreateForumData
{
    public function __construct(
        public string $name,
        public ForumType $type,
        public int $parentId = 0,
        public string $description = '',
        public string $link = '',
        public string $image = '',
        public string $rules = '',
        public string $rulesLink = '',
        public string $password = '',
        public int $style = 0,
        public int $topicsPerPage = 0,
        public int $flags = 32, // POST_REVIEW default
        public bool $displayOnIndex = true,
        public bool $displaySubforumList = true,
        public bool $enableIndexing = true,
        public bool $enableIcons = true,
    ) {}
}

final readonly class UpdateForumData
{
    public function __construct(
        public int $forumId,
        public ?string $name = null,
        public ?ForumType $type = null,
        public ?int $parentId = null,
        public ?string $description = null,
        public ?string $link = null,
        public ?string $image = null,
        public ?string $rules = null,
        public ?string $rulesLink = null,
        public ?string $password = null,
        public ?bool $clearPassword = null,
        public ?int $style = null,
        public ?int $topicsPerPage = null,
        public ?int $flags = null,
        public ?bool $displayOnIndex = null,
        public ?bool $displaySubforumList = null,
        public ?bool $enableIndexing = null,
        public ?bool $enableIcons = null,
    ) {}
}
```

---

## 4. Service Architecture

### 4.1 Component Diagram

```
┌─────────────────────────────────────────────────────────┐
│                   HierarchyService                      │
│                     (Facade)                            │
│  createForum() editForum() deleteForum() moveForum()    │
│  getTree() getForum() getPath() getSubtree()            │
│  markRead() isUnread() subscribe() unsubscribe()        │
├─────────┬───────────┬────────────┬──────────────────────┤
│         │           │            │                      │
│  ForumRepository  TreeService  TrackingService  SubscriptionService
│  (CRUD + query)   (nested set)  (read status)   (watch/notify)
│         │           │            │                      │
│        PDO         PDO       PDO + Cookies            PDO
└─────────┴───────────┴────────────┴──────────────────────┘
         ↕                    ↕
   EventDispatcher      PluginCollection
```

### 4.2 HierarchyService — Facade

**Responsibility**: Single entry point for all hierarchy operations. Coordinates between repository, tree, tracking, and subscription services. Dispatches events. Delegates to plugins.

**Does NOT**: Check ACL permissions. Render templates. Own the user table.

### 4.3 ForumRepository — CRUD + Query

**Responsibility**: 
- INSERT/UPDATE/DELETE forum rows in `phpbb_forums`
- Hydrate `Forum` entities from DB rows
- Validate forum data (name length, password match, etc.)
- Manage denormalized fields (BBCode parsing, flag bitmask construction)

**Does NOT**: Modify `left_id`/`right_id` (that's TreeService). Handle tracking or subscriptions.

### 4.4 TreeService — Nested Set Operations

**Responsibility**:
- Position new nodes (set `left_id`, `right_id`, `parent_id`)
- Reparent nodes (change parent with full subtree)
- Reorder siblings (move up/down within same parent)
- Traverse: get path (ancestors), get subtree (descendants), get full tree
- Rebuild tree from `parent_id` relationships (repair tool)
- Manage advisory locks for concurrent mutations
- Maintain `forum_parents` JSON cache

**Does NOT**: Know about forum-specific fields. Handle CRUD of forum attributes.

### 4.5 TrackingService — Read Status

**Responsibility**:
- Mark forum(s) as read for a user (`forums_track` INSERT/UPDATE)
- Mark all forums as read (DELETE `forums_track` + event for `user_lastmark`)
- Check if a forum is unread for a user (compare `forum_last_post_time` vs. `mark_time`)
- Dual-path: DB strategy for registered users, cookie strategy for anonymous
- Auto-mark forum read when all topics read (`update_forum_tracking_info` logic)

**Does NOT**: Own `topics_track` (that's topic-level). Modify `user_lastmark` directly (fires event).

### 4.6 SubscriptionService — Watch/Notify

**Responsibility**:
- Subscribe user to forum (`forums_watch` INSERT)
- Unsubscribe user from forum (`forums_watch` DELETE)
- List user's subscriptions
- Query subscribers for a forum (for notification system)
- Reset `notify_status` on revisit

**Does NOT**: Send notifications (that's `phpbb\notification`). Check permissions for subscription eligibility.

---

## 5. Interface Signatures

### 5.1 HierarchyServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy;

use phpbb\hierarchy\entity\Forum;
use phpbb\hierarchy\entity\ForumType;
use phpbb\hierarchy\dto\CreateForumData;
use phpbb\hierarchy\dto\UpdateForumData;

interface HierarchyServiceInterface
{
    // ── CRUD ──

    public function createForum(CreateForumData $data): Forum;

    public function updateForum(UpdateForumData $data): Forum;

    /**
     * @param 'move'|'delete' $contentAction What to do with posts/topics
     * @param 'move'|'delete'|null $subforumAction What to do with subforums
     */
    public function deleteForum(
        int $forumId,
        string $contentAction,
        ?int $moveContentTo = null,
        ?string $subforumAction = null,
        ?int $moveSubforumsTo = null,
    ): void;

    // ── Tree Operations ──

    public function moveForum(int $forumId, int $newParentId): void;

    public function reorderForum(int $forumId, int $delta): bool;

    // ── Query ──

    public function getForum(int $forumId): ?Forum;

    /** @return Forum[] Ordered by left_id (DFS pre-order) */
    public function getTree(?int $rootId = null): array;

    /** @return Forum[] Ancestors from root to item */
    public function getPath(int $forumId): array;

    /** @return Forum[] Descendants of item */
    public function getSubtree(int $forumId, bool $includeRoot = true): array;

    /** @return int[] IDs of direct children */
    public function getChildIds(int $parentId): array;

    // ── Tracking ──

    public function markForumRead(int $userId, int $forumId, int $markTime): void;

    public function markAllRead(int $userId, int $markTime): void;

    public function isForumUnread(int $userId, int $forumId): bool;

    /**
     * @param int[] $forumIds
     * @return array<int, bool> forumId => unread
     */
    public function getUnreadStatus(int $userId, array $forumIds): array;

    // ── Subscriptions ──

    public function subscribe(int $userId, int $forumId): void;

    public function unsubscribe(int $userId, int $forumId): void;

    public function isSubscribed(int $userId, int $forumId): bool;

    /**
     * @return int[] User IDs subscribed to this forum with notify_status = NOTIFY_YES
     */
    public function getSubscribers(int $forumId, ?int $excludeUserId = null): array;
}
```

### 5.2 ForumRepositoryInterface

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\repository;

use phpbb\hierarchy\entity\Forum;

interface ForumRepositoryInterface
{
    public function findById(int $forumId): ?Forum;

    /** @return Forum[] keyed by forum_id */
    public function findByIds(array $forumIds): array;

    /** @return Forum[] ordered by left_id */
    public function findAll(): array;

    /**
     * @return Forum[] children of given parent, ordered by left_id
     */
    public function findByParent(int $parentId): array;

    /**
     * Insert forum row (without nested set positioning).
     * Returns new forum_id.
     */
    public function insert(array $data): int;

    public function update(int $forumId, array $data): void;

    public function delete(int $forumId): void;

    /** @param int[] $forumIds */
    public function deleteMultiple(array $forumIds): void;

    /**
     * Reset stats counters to zero.
     */
    public function resetStats(int $forumId): void;

    /**
     * Invalidate forum_parents cache for given forum IDs.
     * @param int[]|null $forumIds Null = all forums
     */
    public function invalidateParentCache(?array $forumIds = null): void;
}
```

### 5.3 TreeServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\tree;

interface TreeServiceInterface
{
    /**
     * Position a new node under a parent. Sets left_id, right_id, parent_id.
     * Must be called within advisory lock.
     */
    public function insertNode(int $nodeId, int $parentId): void;

    /**
     * Remove node + entire subtree from nested set.
     * @return int[] IDs of all removed nodes
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
     * Reorder a node among siblings. Positive = up, negative = down.
     * @return bool False if already at boundary
     */
    public function reorder(int $nodeId, int $delta): bool;

    // ── Traversal ──

    /**
     * @return array<int, array{forum_id: int, parent_id: int, left_id: int, right_id: int}>
     * Ancestors from root to node (inclusive).
     */
    public function getPath(int $nodeId): array;

    /**
     * @return array<int, array{...}> Descendants ordered by left_id.
     */
    public function getSubtree(int $nodeId, bool $includeRoot = true): array;

    /**
     * @return array<int, array{...}> Full tree ordered by left_id.
     */
    public function getFullTree(): array;

    /**
     * Rebuild left_id/right_id from parent_id relationships.
     * Expensive repair operation.
     */
    public function regenerate(): void;
}
```

### 5.4 TrackingServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\tracking;

interface TrackingServiceInterface
{
    /**
     * Mark specific forum(s) as read.
     * @param int|int[] $forumId
     */
    public function markForumsRead(int $userId, int|array $forumId, int $markTime): void;

    /**
     * Mark all forums as read (global reset).
     * Deletes forums_track rows, fires event for user_lastmark update.
     */
    public function markAllRead(int $userId, int $markTime): void;

    /**
     * Get mark times for given forums.
     * @param int[] $forumIds
     * @return array<int, int> forumId => markTime (0 if never marked)
     */
    public function getMarkTimes(int $userId, array $forumIds): array;

    /**
     * Check if forum is unread based on forum_last_post_time vs. mark_time.
     */
    public function isUnread(int $userId, int $forumId, int $forumLastPostTime): bool;

    /**
     * Auto-mark forum read if all topics are now read.
     * Called after a topic is read, checks for remaining unread topics.
     * @return bool True if forum was auto-marked
     */
    public function autoMarkIfComplete(int $userId, int $forumId, int $forumLastPostTime): bool;
}
```

### 5.5 SubscriptionServiceInterface

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

    /**
     * @return int[] Forum IDs the user is subscribed to
     */
    public function getUserSubscriptions(int $userId): array;

    /**
     * Get subscribers eligible for notification.
     * @return int[] User IDs with notify_status = NOTIFY_YES
     */
    public function getEligibleSubscribers(int $forumId, ?int $excludeUserId = null): array;

    /**
     * Reset notify_status to NOTIFY_YES for a user's subscription.
     * Called when user revisits the forum.
     */
    public function resetNotifyStatus(int $userId, int $forumId): void;

    /**
     * Remove all subscriptions for a deleted forum.
     */
    public function removeForumSubscriptions(int $forumId): void;
}
```

---

## 6. Plugin Architecture

### 6.1 Plugin Contract Interface

```php
<?php declare(strict_types=1);

namespace phpbb\hierarchy\plugin;

use phpbb\hierarchy\entity\Forum;
use phpbb\hierarchy\entity\ForumType;

interface HierarchyPluginInterface
{
    /**
     * Unique identifier for this plugin.
     */
    public function getName(): string;

    /**
     * Which forum types this plugin operates on.
     * @return ForumType[]
     */
    public function getSupportedTypes(): array;

    /**
     * Called after a forum is created. Plugin may initialize custom data.
     */
    public function onForumCreated(Forum $forum): void;

    /**
     * Called before a forum is deleted. Plugin should clean up custom data.
     */
    public function onForumDeleting(int $forumId): void;

    /**
     * Provide custom attributes for the forum data array.
     * @return array<string, mixed> Extra key-value pairs merged into forum display data
     */
    public function getCustomAttributes(Forum $forum): array;

    /**
     * Validate forum data before create/update.
     * @return string[] Error message keys (empty = valid)
     */
    public function validate(array $forumData): array;
}
```

### 6.2 Service Tag Discovery (DI Configuration)

```yaml
# config/services_hierarchy.yml

# Plugin collection — auto-discovers all tagged hierarchy plugins
hierarchy.plugin_collection:
    class: phpbb\di\ordered_service_collection
    arguments: ['@service_container']
    tags:
        - { name: service_collection, tag: hierarchy.plugin }

# Core hierarchy services
hierarchy.service:
    class: phpbb\hierarchy\HierarchyService
    arguments:
        - '@hierarchy.forum_repository'
        - '@hierarchy.tree_service'
        - '@hierarchy.tracking_service'
        - '@hierarchy.subscription_service'
        - '@hierarchy.plugin_collection'
        - '@dispatcher'

hierarchy.forum_repository:
    class: phpbb\hierarchy\repository\ForumRepository
    arguments: ['@dbal.conn.pdo']

hierarchy.tree_service:
    class: phpbb\hierarchy\tree\TreeService
    arguments: ['@dbal.conn.pdo']

hierarchy.tracking_service:
    class: phpbb\hierarchy\tracking\TrackingService
    arguments: ['@dbal.conn.pdo', '@request']

hierarchy.subscription_service:
    class: phpbb\hierarchy\subscription\SubscriptionService
    arguments: ['@dbal.conn.pdo']
```

### 6.3 Extension Plugin Registration

An extension registers a hierarchy plugin by tagging its service:

```yaml
# ext/acme/custom_forums/config/services.yml
acme.custom_forums.hierarchy_plugin:
    class: acme\custom_forums\hierarchy\CustomForumPlugin
    arguments: ['@dbal.conn.pdo']
    tags:
        - { name: hierarchy.plugin, order: 100 }
```

### 6.4 Plugin Discovery Flow

```
Container compilation
  → collection_pass processes 'service_collection' tags
  → Finds hierarchy.plugin_collection wants tag 'hierarchy.plugin'
  → Finds all services tagged 'hierarchy.plugin'
  → Registers them into ordered_service_collection
  → At runtime: collection lazily loads plugins from container
```

### 6.5 Event Hooks for CRUD Operations

Plugins can also hook via events without implementing the full interface:

```php
// Extension listener
class listener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'hierarchy.forum.post_create' => 'onForumCreated',
            'hierarchy.forum.pre_delete'  => 'onForumDeleting',
            'hierarchy.tree.post_move'    => 'onForumMoved',
        ];
    }
}
```

### 6.6 Extension Points Summary

| Extension Point | Mechanism | Use Case |
|----------------|-----------|----------|
| Custom forum types | `HierarchyPluginInterface::getSupportedTypes()` | Add "Wiki" or "Gallery" forum types |
| Custom attributes | `HierarchyPluginInterface::getCustomAttributes()` | Add metadata to forum display |
| Custom validation | `HierarchyPluginInterface::validate()` | Enforce per-extension rules |
| CRUD hooks | Event dispatcher (`hierarchy.forum.*`) | Side effects on create/update/delete |
| Tree hooks | Event dispatcher (`hierarchy.tree.*`) | React to structure changes |
| Display hooks | Event dispatcher (`hierarchy.display.*`) | Modify rendered tree data |
| Custom tracking | Tagged `hierarchy.tracking_strategy` service | Alternative tracking backends |

---

## 7. Database Access Patterns

### 7.1 ForumRepository SQL

| Method | SQL | Notes |
|--------|-----|-------|
| `findById($id)` | `SELECT * FROM phpbb_forums WHERE forum_id = :id` | Single parameterized query |
| `findByIds($ids)` | `SELECT * FROM phpbb_forums WHERE forum_id IN (:ids) ORDER BY left_id` | `IN` clause with bound params |
| `findAll()` | `SELECT * FROM phpbb_forums ORDER BY left_id` | Full tree, single query |
| `findByParent($pid)` | `SELECT * FROM phpbb_forums WHERE parent_id = :pid ORDER BY left_id` | Direct children only |
| `insert($data)` | `INSERT INTO phpbb_forums (...) VALUES (...)` | Returns `lastInsertId()` |
| `update($id, $data)` | `UPDATE phpbb_forums SET ... WHERE forum_id = :id` | Dynamic SET clause |
| `delete($id)` | `DELETE FROM phpbb_forums WHERE forum_id = :id` | Single row delete |
| `resetStats($id)` | `UPDATE phpbb_forums SET forum_posts_approved=0, ... WHERE forum_id = :id` | 6 counter columns |
| `invalidateParentCache($ids)` | `UPDATE phpbb_forums SET forum_parents = '' WHERE forum_id IN (:ids)` | Or `WHERE 1=1` for all |

### 7.2 TreeService SQL

| Method | SQL | Notes |
|--------|-----|-------|
| `insertNode($id, $pid)` | `SELECT right_id FROM phpbb_forums WHERE forum_id = :pid` → `UPDATE ... SET left_id = left_id + 2 WHERE left_id > :right` → `UPDATE ... SET right_id = right_id + 2 WHERE :left BETWEEN left_id AND right_id` → `UPDATE phpbb_forums SET left_id = :new_left, right_id = :new_right, parent_id = :pid WHERE forum_id = :id` | 4 queries in advisory lock |
| `removeNode($id)` | `SELECT ... WHERE left_id BETWEEN :left AND :right ORDER BY left_id` → close gap UPDATE → DELETE | 3 queries in lock |
| `changeParent($id, $pid)` | Remove subset (keep values) → open gap at new parent → shift moved items | 5 queries in lock + transaction |
| `reorder($id, $delta)` | `SELECT` item → `SELECT` siblings → CASE-based swap UPDATE | 3 queries in lock |
| `getPath($id)` | `SELECT i2.* FROM phpbb_forums i1 JOIN phpbb_forums i2 ON i1.left_id BETWEEN i2.left_id AND i2.right_id WHERE i1.forum_id = :id ORDER BY i2.left_id` | Self-join, uses composite index |
| `getSubtree($id)` | `SELECT i2.* FROM phpbb_forums i1 JOIN phpbb_forums i2 ON i2.left_id BETWEEN i1.left_id AND i1.right_id WHERE i1.forum_id = :id ORDER BY i2.left_id` | Self-join |
| `getFullTree()` | `SELECT * FROM phpbb_forums ORDER BY left_id` | Single scan |
| `regenerate()` | Recursive: `SELECT` children per parent → UPDATE left → recurse → UPDATE right | O(3n) queries — repair only |

### 7.3 TrackingService SQL

| Method | SQL | Notes |
|--------|-----|-------|
| `markForumsRead($uid, $fids, $time)` | `DELETE FROM phpbb_topics_track WHERE user_id = :uid AND mark_time < :time AND forum_id IN (:fids)` → `INSERT ... ON DUPLICATE KEY UPDATE mark_time = :time` into `phpbb_forums_track` | UPSERT pattern |
| `markAllRead($uid, $time)` | `DELETE FROM phpbb_forums_track WHERE user_id = :uid AND mark_time < :time` → `DELETE FROM phpbb_topics_track WHERE user_id = :uid AND mark_time < :time` | Fires event for `user_lastmark` |
| `getMarkTimes($uid, $fids)` | `SELECT forum_id, mark_time FROM phpbb_forums_track WHERE user_id = :uid AND forum_id IN (:fids)` | Returns map |
| `autoMarkIfComplete()` | `SELECT 1 FROM phpbb_topics t LEFT JOIN phpbb_topics_track tt ON ... WHERE t.forum_id = :fid AND t.topic_last_post_time > :mark_time AND (tt.topic_id IS NULL OR tt.mark_time < t.topic_last_post_time) LIMIT 1` | If no rows → forum is fully read |

### 7.4 SubscriptionService SQL

| Method | SQL | Notes |
|--------|-----|-------|
| `subscribe($uid, $fid)` | `INSERT INTO phpbb_forums_watch (forum_id, user_id, notify_status) VALUES (:fid, :uid, 0)` | NOTIFY_YES = 0 |
| `unsubscribe($uid, $fid)` | `DELETE FROM phpbb_forums_watch WHERE forum_id = :fid AND user_id = :uid` | |
| `isSubscribed($uid, $fid)` | `SELECT 1 FROM phpbb_forums_watch WHERE forum_id = :fid AND user_id = :uid LIMIT 1` | |
| `getEligibleSubscribers($fid)` | `SELECT user_id FROM phpbb_forums_watch WHERE forum_id = :fid AND notify_status = 0 AND user_id != :exclude` | Feeds notification system |
| `resetNotifyStatus($uid, $fid)` | `UPDATE phpbb_forums_watch SET notify_status = 0 WHERE forum_id = :fid AND user_id = :uid AND notify_status = 1` | On revisit |

---

## 8. Event Model

### 8.1 Events Dispatched by HierarchyService

| Event Name | When | Payload | Mutable Keys |
|------------|------|---------|--------------|
| `hierarchy.forum.pre_create` | Before forum INSERT | `{data: CreateForumData}` | `data` |
| `hierarchy.forum.post_create` | After forum created + positioned | `{forum: Forum}` | — |
| `hierarchy.forum.pre_update` | Before forum UPDATE | `{forum_id: int, data: UpdateForumData, old_forum: Forum}` | `data` |
| `hierarchy.forum.post_update` | After forum updated | `{forum: Forum, old_forum: Forum}` | — |
| `hierarchy.forum.pre_delete` | Before forum deletion starts | `{forum_id: int, content_action: string, subforum_action: ?string}` | — (can throw to abort) |
| `hierarchy.forum.post_delete` | After forum fully deleted | `{forum_id: int, deleted_ids: int[]}` | — |
| `hierarchy.tree.pre_move` | Before reparent/reorder | `{forum_id: int, new_parent_id: int}` | `new_parent_id` |
| `hierarchy.tree.post_move` | After reparent/reorder | `{forum_id: int, old_parent_id: int, new_parent_id: int}` | — |
| `hierarchy.tree.post_reorder` | After sibling reorder | `{forum_id: int, delta: int}` | — |
| `hierarchy.tree.post_regenerate` | After full tree rebuild | `{}` | — |
| `hierarchy.tracking.post_mark` | After forum(s) marked read | `{user_id: int, forum_ids: int[], mark_time: int}` | — |
| `hierarchy.tracking.post_mark_all` | After all forums marked read | `{user_id: int, mark_time: int}` | — |
| `hierarchy.subscription.post_subscribe` | After user subscribes | `{user_id: int, forum_id: int}` | — |
| `hierarchy.subscription.post_unsubscribe` | After user unsubscribes | `{user_id: int, forum_id: int}` | — |

### 8.2 Event Dispatch Implementation

```php
// In HierarchyService::createForum()
$eventData = $this->dispatcher->trigger_event(
    'hierarchy.forum.pre_create',
    ['data' => $data]
);
$data = $eventData['data'];

$forumId = $this->repository->insert($this->prepareInsertData($data));
$this->tree->insertNode($forumId, $data->parentId);

foreach ($this->plugins as $plugin) {
    if (in_array($data->type, $plugin->getSupportedTypes(), true)) {
        $plugin->onForumCreated($forum);
    }
}

$this->dispatcher->trigger_event(
    'hierarchy.forum.post_create',
    ['forum' => $forum]
);
```

### 8.3 Comparison with Legacy Events

| Legacy (13 ACP events) | New (14 events total) | Change |
|------------------------|----------------------|--------|
| `core.acp_manage_forums_request_data` | `hierarchy.forum.pre_create/pre_update` | Merged and simplified |
| `core.acp_manage_forums_validate_data` | `HierarchyPluginInterface::validate()` | Plugin pattern, not event |
| `core.acp_manage_forums_update_data_before` | `hierarchy.forum.pre_create/pre_update` | Merged |
| `core.acp_manage_forums_update_data_after` | `hierarchy.forum.post_create/post_update` | Merged |
| `core.acp_manage_forums_move_children` | `hierarchy.tree.post_move` | Generalized |
| `core.acp_manage_forums_move_content` | `hierarchy.forum.pre_delete` (content_action) | Part of delete flow |
| `core.delete_forum_content_before_query` | `hierarchy.forum.pre_delete` | Simplified |
| 7 display events | Remain in display layer | Not hierarchy's concern |

---

## 9. Integration with phpbb\auth

### 9.1 Principle: Hierarchy Provides Data, Auth Filters It

The `phpbb\hierarchy` service is deliberately ACL-unaware. It returns the full tree to callers. The display/API layer is responsible for applying permission filters:

```php
// Controller / display layer
$tree = $hierarchy->getTree();
$visibleForums = array_filter($tree, fn(Forum $f) => $auth->acl_get('f_list', $f->id));
```

### 9.2 Permission Touch Points

| Permission | Used Where | Called By |
|-----------|-----------|----------|
| `f_list` | Filter visible forums in tree display | Display layer, NOT hierarchy |
| `f_read` | Control forum access | Controller routing, NOT hierarchy |
| `m_approve` | Determine visible post/topic counts (`content_visibility`) | Display layer via `content_visibility` |
| `a_forumadd` | Gate forum creation in ACP | ACP controller, NOT hierarchy |
| `a_forumdel` | Gate forum deletion in ACP | ACP controller, NOT hierarchy |
| `a_fauth` | Gate permission management after create/edit | ACP controller, NOT hierarchy |

### 9.3 Cascading ACL Cleanup on Delete

When a forum is deleted, its ACL entries in `phpbb_acl_groups` and `phpbb_acl_users` must be removed. The hierarchy service fires `hierarchy.forum.pre_delete`; an auth listener handles cleanup:

```php
// In phpbb\auth's event listener:
public function onForumDeleting(array $event): void
{
    $forumId = $event['forum_id'];
    // DELETE FROM phpbb_acl_groups WHERE forum_id = :fid
    // DELETE FROM phpbb_acl_users WHERE forum_id = :fid
    $this->aclClearPrefetch();
}
```

---

## 10. Integration with phpbb\user

### 10.1 User Data Read Points

| Data | Used By | Access Pattern |
|------|---------|---------------|
| `user_id` | TrackingService, SubscriptionService | Passed as parameter from session |
| `user_lastmark` | TrackingService — global read baseline | SELECT from `phpbb_users` via context |
| `user_lastmark` UPDATE | `markAllRead()` — global reset | Hierarchy fires event; user service listener updates |

### 10.2 Tracking Integration

```php
// TrackingService::getMarkTimes() implementation:
public function getMarkTimes(int $userId, array $forumIds): array
{
    $stmt = $this->pdo->prepare(
        'SELECT forum_id, mark_time FROM phpbb_forums_track
         WHERE user_id = :uid AND forum_id IN (' . $this->placeholders($forumIds) . ')'
    );
    // ...
    // For forums without rows, fallback to user_lastmark (passed from caller)
}
```

The `user_lastmark` fallback is provided by the caller (controller/display layer), keeping hierarchy independent of the users table:

```php
// Controller:
$markTimes = $tracking->getMarkTimes($userId, $forumIds);
$userLastmark = $user->data['user_lastmark'];

foreach ($forums as $forum) {
    $markTime = $markTimes[$forum->id] ?? $userLastmark;
    $unread = $forum->lastPost->time > $markTime;
}
```

### 10.3 Subscription + Notification Flow

```
User subscribes → SubscriptionService::subscribe()
  → INSERT phpbb_forums_watch

New post created → Notification system calls:
  → SubscriptionService::getEligibleSubscribers($forumId)
  → Returns user_ids with notify_status = NOTIFY_YES
  → Notification system sends emails, marks notify_status = NOTIFY_NO

User revisits forum → Controller calls:
  → SubscriptionService::resetNotifyStatus($userId, $forumId)
  → User eligible for next notification
```

---

## 11. Migration Strategy

### 11.1 Phase 1: Core Services (No Breaking Changes)

1. Create `phpbb\hierarchy\tree\TreeService` wrapping existing `nestedset_forum` logic with PDO
2. Create `phpbb\hierarchy\repository\ForumRepository` for forum CRUD
3. Create `phpbb\hierarchy\entity\Forum` and value objects
4. Create `phpbb\hierarchy\HierarchyService` facade
5. Register all services in DI container
6. Write comprehensive tests against real database

**Compatibility**: Legacy code continues to work unchanged. New service operates on the same tables.

### 11.2 Phase 2: Tracking & Subscriptions

1. Create `TrackingService` extracting logic from `markread()`
2. Create `SubscriptionService` extracting logic from `watch_topic_or_forum()`
3. Legacy `markread()` delegates to `TrackingService` internally (backward compatibility)
4. Legacy `watch_topic_or_forum()` delegates to `SubscriptionService`

### 11.3 Phase 3: ACP Migration

1. Refactor `acp_forums.php` to call `HierarchyService` instead of raw SQL
2. Remove duplicate nested-set SQL from ACP
3. Keep ACP template and routing unchanged
4. Validation moves to `ForumRepository` (reusable for API)

### 11.4 Phase 4: Display Migration

1. Extract data-fetching from `display_forums()` into `HierarchyService::getTree()`
2. Move ACL filtering to controller/middleware
3. Move stat aggregation (subforum rollup) to a `TreeAggregator` utility
4. Keep template variable assignment in display layer
5. Gradually replace procedural display with composition

### 11.5 Database Migration

```php
// Migration: Convert forum_parents from serialized PHP to JSON
class convert_forum_parents_to_json extends migration
{
    public function update_data(): array
    {
        return [['custom', [[$this, 'convert_parents']]]];
    }

    public function convert_parents(): void
    {
        $sql = 'SELECT forum_id, forum_parents FROM phpbb_forums WHERE forum_parents != ""';
        // For each row: unserialize → json_encode → UPDATE
    }
}
```

---

## 12. Risks & Open Questions

### 12.1 Risks

| Risk | Severity | Mitigation |
|------|----------|------------|
| **Dual-write during migration** | MEDIUM | Phase 1 operates on same tables. No separate data store. |
| **Advisory lock contention** | LOW | Same locking as legacy. Only affects concurrent admin operations. |
| **Cookie tracking complexity** | MEDIUM | Port existing base36 logic exactly. Test with real cookie data. |
| **`forum_parents` PHP→JSON migration** | LOW | One-time migration. Test with production-scale data. |
| **Plugin interface stability** | MEDIUM | Mark `HierarchyPluginInterface` as `@internal` for first release. Stabilize in v2. |
| **Performance regression in display** | MEDIUM | Benchmark new service tree fetch vs. legacy single-query approach. Both do `ORDER BY left_id`. |

### 12.2 Open Questions

| Question | Status | Notes |
|----------|--------|-------|
| Should `TreeService` support multiple trees in one table (via `sql_where`)? | **Deferred** | Legacy supports it. phpBB forums use single tree. Keep parameter but don't optimize for it. |
| Should stat aggregation (subforum rollup) live in hierarchy or display? | **Recommend: hierarchy** | It's a tree operation (sum children into parent). But only needed for display. Create optional `TreeAggregator`. |
| How to handle `forums_access` (password-protected forums)? | **Deferred** | It's session-scoped, password-based. Separate from ACL. Could be a `ForumAccessService` or part of hierarchy. |
| Should the plugin system support custom DB columns? | **No for v1** | Plugins use `forum_options` bitmask or separate tables. Custom columns require schema migration per plugin. |
| How does the new service interact with `$cache->destroy('sql', FORUMS_TABLE)`? | **Via events** | `hierarchy.forum.post_create/update/delete` events. Cache listener subscribes and invalidates. |
| Thread safety of `forum_parents` JSON cache writes? | **Same as legacy** | Advisory lock covers mutations. Cache writes are idempotent (lazy populate). |

---

## Appendices

### A. Source Files Analyzed

| File | Lines Read | Confidence |
|------|-----------|------------|
| `src/phpbb/forums/tree/tree_interface.php` | Full | HIGH |
| `src/phpbb/forums/tree/nestedset.php` | Full (~870) | HIGH |
| `src/phpbb/forums/tree/nestedset_forum.php` | Full (~50) | HIGH |
| `src/phpbb/common/acp/acp_forums.php` | Full (2245) | HIGH |
| `src/phpbb/common/functions_display.php` | 1-905 | HIGH |
| `src/phpbb/common/functions.php` | 553-1380 | HIGH |
| `src/phpbb/common/constants.php` | Selected | HIGH |
| `src/phpbb/forums/event/dispatcher.php` | Full | HIGH |
| `src/phpbb/forums/di/pass/collection_pass.php` | Full | HIGH |
| `src/phpbb/forums/extension/base.php` | Full | HIGH |
| `src/phpbb/forums/notification/type/forum.php` | Full | HIGH |
| `phpbb_dump.sql` (schema extracts) | Selected | HIGH |

### B. Namespace Structure

```
phpbb\hierarchy\
├── HierarchyService.php              ← Facade
├── HierarchyServiceInterface.php
├── dto\
│   ├── CreateForumData.php
│   └── UpdateForumData.php
├── entity\
│   ├── Forum.php
│   ├── ForumType.php                  ← enum
│   ├── ForumStatus.php                ← enum
│   ├── ForumStats.php                 ← value object
│   ├── ForumLastPost.php              ← value object
│   └── ForumPruneSettings.php         ← value object
├── repository\
│   ├── ForumRepository.php
│   └── ForumRepositoryInterface.php
├── tree\
│   ├── TreeService.php
│   └── TreeServiceInterface.php
├── tracking\
│   ├── TrackingService.php
│   ├── TrackingServiceInterface.php
│   ├── DbTrackingStrategy.php
│   └── CookieTrackingStrategy.php
├── subscription\
│   ├── SubscriptionService.php
│   └── SubscriptionServiceInterface.php
├── plugin\
│   ├── HierarchyPluginInterface.php
│   └── PluginCollection.php           ← extends ordered_service_collection
└── event\
    └── HierarchyEvents.php            ← Event name constants
```
