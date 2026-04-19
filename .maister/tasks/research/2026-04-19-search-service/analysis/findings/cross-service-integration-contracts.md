# Cross-Service Integration Contracts: Search Service

## 1. Events Consumed (from `phpbb\threads`)

Search must subscribe to the following domain events dispatched **after** the database transaction commits.

### 1.1 `PostCreatedEvent` → Trigger Indexing

**Source**: `phpbb\threads\event\PostCreatedEvent`
**Dispatched by**: `ThreadsService::createTopic()`, `ThreadsService::createReply()`

| Payload Field | Type | Usage by Search |
|---------------|------|-----------------|
| `postId` | `int` | Primary index key |
| `topicId` | `int` | Group posts by topic for topic-level search |
| `forumId` | `int` | Forum scope filtering |
| `posterId` | `int` | Author filtering, shadow ban check |
| `visibility` | `Visibility` (enum) | Only index if `Visibility::Approved` |
| `isFirstPost` | `bool` | Index `title` field from topic subject |
| `request` | `CreateTopicRequest\|CreateReplyRequest` | Extract `message` (raw text) and `title` for indexing |

**Action**: Add post to search index if `visibility === Visibility::Approved`.

---

### 1.2 `PostEditedEvent` → Trigger Re-indexing

**Source**: `phpbb\threads\event\PostEditedEvent`
**Dispatched by**: `ThreadsService::editPost()`

| Payload Field | Type | Usage by Search |
|---------------|------|-----------------|
| `postId` | `int` | Locate existing index entry |
| `topicId` | `int` | Context for topic-level data |
| `forumId` | `int` | Forum scope |
| `editorId` | `int` | Not used by search directly |
| `oldText` | `string` | N/A (ignore) |
| `newText` | `string` | Re-index with new content |
| `editReason` | `string` | Not indexed |

**Action**: Update index entry for `postId` with new text content. If post visibility is not `Approved`, skip.

---

### 1.3 `PostSoftDeletedEvent` → Remove from Index

**Source**: `phpbb\threads\event\PostSoftDeletedEvent`
**Dispatched by**: `ThreadsService::softDeletePost()`

| Payload Field | Type | Usage by Search |
|---------------|------|-----------------|
| `postId` | `int` | Remove from index |
| `topicId` | `int` | Context |
| `forumId` | `int` | Context |
| `posterId` | `int` | Context |
| `actorId` | `int` | Not used |
| `reason` | `string` | Not used |

**Action**: Remove post from search index.

---

### 1.4 `PostRestoredEvent` → Re-add to Index

**Source**: `phpbb\threads\event\PostRestoredEvent`
**Dispatched by**: `ThreadsService::restorePost()`

| Payload Field | Type | Usage by Search |
|---------------|------|-----------------|
| `postId` | `int` | Fetch post data and re-index |
| `topicId` | `int` | Context |
| `forumId` | `int` | Forum scope |
| `posterId` | `int` | Author |
| `actorId` | `int` | Not used |

**Action**: Fetch post content from `PostRepository`, add back to index.

---

### 1.5 `PostHardDeletedEvent` → Permanent Removal from Index

**Source**: `phpbb\threads\event\PostHardDeletedEvent`
**Dispatched by**: `ThreadsService::hardDeletePost()`

| Payload Field | Type | Usage by Search |
|---------------|------|-----------------|
| `postId` | `int` | Remove from index permanently |
| `topicId` | `int` | Context |
| `forumId` | `int` | Context |
| `posterId` | `int` | Context |
| `wasFirstPost` | `bool` | If true, topic title no longer associated with this post |
| `wasLastPost` | `bool` | Not used by search |

**Action**: Remove post from search index permanently.

---

### 1.6 `TopicDeletedEvent` → Batch Remove All Posts in Topic

**Source**: `phpbb\threads\event\TopicDeletedEvent`
**Dispatched by**: `ThreadsService::hardDeleteTopic()`

| Payload Field | Type | Usage by Search |
|---------------|------|-----------------|
| `topicId` | `int` | Remove ALL posts in this topic from index |
| `forumId` | `int` | Context |
| `allPostIds` | `int[]` | Explicit list of post IDs to deindex |
| `allPosterIds` | `int[]` | Not used |

**Action**: Batch-delete all `allPostIds` from the search index.

---

### 1.7 `VisibilityChangedEvent` → Conditional Add/Remove from Index

**Source**: `phpbb\threads\event\VisibilityChangedEvent`
**Dispatched by**: `ThreadsService::softDeleteTopic()`, `restoreTopic()`, `approvePost()`, `approveTopic()`

| Payload Field | Type | Usage by Search |
|---------------|------|-----------------|
| `entityType` | `string` (`'post'` or `'topic'`) | Determines scope |
| `entityId` | `int` | Primary entity being changed |
| `topicId` | `int` | Context |
| `forumId` | `int` | Context |
| `oldVisibility` | `Visibility` | Previous state |
| `newVisibility` | `Visibility` | New state |
| `affectedPostIds` | `int[]` | All posts affected (cascade from topic) |
| `actorId` | `int` | Not used |

**Action logic**:
- If `newVisibility === Visibility::Approved`: add all `affectedPostIds` to index (fetch content from DB)
- If `oldVisibility === Visibility::Approved` and `newVisibility !== Visibility::Approved`: remove all `affectedPostIds` from index
- Ignore transitions between non-Approved states (e.g., Unapproved → Deleted)

---

### 1.8 `TopicMovedEvent` → Update Forum ID in Index

**Source**: `phpbb\threads\event\TopicMovedEvent`
**Dispatched by**: `ThreadsService::moveTopic()`

| Payload Field | Type | Usage by Search |
|---------------|------|-----------------|
| `topicId` | `int` | All posts in this topic need forum_id update |
| `oldForumId` | `int` | Previous forum |
| `newForumId` | `int` | New forum — update index entries |
| `createShadow` | `bool` | Not used by search |

**Action**: Update `forum_id` for all indexed posts belonging to `topicId`.

---

## 2. Events Dispatched by Search

### 2.1 `SearchPerformedEvent`

**Purpose**: Analytics, rate limiting, audit logging.

```php
namespace phpbb\search\event;

final readonly class SearchPerformedEvent
{
    public function __construct(
        public int $userId,
        public string $query,
        public int $resultCount,
        public float $executionTimeMs,
        public string $searchType,      // 'posts' | 'topics'
        public ?int $forumId,           // null = global search
        public int $timestamp,
    ) {}
}
```

### 2.2 `IndexRebuiltEvent`

**Purpose**: Admin notification, logging.

```php
namespace phpbb\search\event;

final readonly class IndexRebuiltEvent
{
    public function __construct(
        public int $totalPostsIndexed,
        public float $durationSeconds,
        public int $triggeredByUserId,
        public int $timestamp,
    ) {}
}
```

---

## 3. Service Dependencies (What Search Calls)

### 3.1 `ShadowBanService::isShadowBanned(int $userId): bool`

**Source**: `phpbb\user\Service\ShadowBanService`
**Contract**: Quick boolean check — returns `true` if user is currently shadow-banned.

**Usage in Search**: Filter search results so that shadow-banned users' posts are invisible to other normal users. Posts by shadow-banned users are still visible to the author themselves (if searching) and to admins/moderators.

**Integration pattern**:
```php
// After retrieving post_ids from index, before returning results:
foreach ($results as $key => $result) {
    if ($result->posterId !== $currentUserId 
        && !$isModeratorOrAdmin
        && $this->shadowBanService->isShadowBanned($result->posterId)) {
        unset($results[$key]);
    }
}
```

**Performance note**: Should batch this check. Ideal future API: `ShadowBanService::areShadowBanned(int[] $userIds): array<int, bool>`

---

### 3.2 `AuthorizationService::getGrantedForums(User $user, string $permission): int[]`

**Source**: `phpbb\auth\Contract\AuthorizationServiceInterface`
**Contract**: Returns all forum IDs where the user has the given permission.

**Permission used**: `'f_read'` — forum-level read access.

**Usage in Search**: Before executing a search query, resolve the list of forums the user can read. Constrain the search index query to only return posts from those forums.

**Integration pattern**:
```php
$readableForumIds = $this->authService->getGrantedForums($user, 'f_read');
// Pass $readableForumIds as a filter to the search backend
$results = $this->searchBackend->query($queryString, forumIds: $readableForumIds);
```

**Caching note**: The auth service loads permissions once per request (memoized internally via `loadPermissions()`). The resulting forum list can also be cached in the search cache pool with tag `user_permissions:{userId}`.

---

### 3.3 `AuthorizationService::isGranted(User $user, string $permission, ?int $forumId): bool`

**Source**: `phpbb\auth\Contract\AuthorizationServiceInterface`
**Contract**: Check specific permission for a specific forum.

**Permissions relevant to Search**:
- `'f_read'` — can user see posts in this forum?
- `'m_approve'` — can user see unapproved posts? (moderator queue search)
- `'a_search'` — can admin access search admin panel?

---

### 3.4 `HierarchyService::getSubtree(int $forumId, bool $includeRoot = true): TreeResponse`

**Source**: `phpbb\hierarchy\HierarchyServiceInterface`
**Contract**: Returns all descendant forums (DFS pre-order) of a given forum. `TreeResponse` contains `Forum[]` array.

**Usage in Search**: When a user searches within a specific forum, resolve all sub-forums to include in the scope.

**Integration pattern**:
```php
// User searches in forum 5 → include forum 5 + all children
$subtree = $this->hierarchyService->getSubtree($forumId, includeRoot: true);
$forumIds = array_map(fn(Forum $f) => $f->id, $subtree->forums);
// Intersect with user's readable forums
$searchableForums = array_intersect($forumIds, $readableForumIds);
```

---

### 3.5 `HierarchyService::getChildIds(int $parentId): int[]`

**Source**: `phpbb\hierarchy\HierarchyServiceInterface`
**Contract**: Returns IDs of direct children of a parent forum.

**Usage in Search**: For shallow forum-scoped search (search in forum but not sub-forums).

---

### 3.6 `CachePoolFactory::getPool(string $namespace): TagAwareCacheInterface`

**Source**: `phpbb\cache\CachePoolFactoryInterface`
**Contract**: Returns a namespaced, tag-aware cache pool.

**Usage in Search**:
```php
$cache = $this->cachePoolFactory->getPool('search');
```

**Cache keys and tags**:

| Key Pattern | Content | TTL | Tags |
|-------------|---------|-----|------|
| `results:{queryHash}:{forumScope}:{page}` | Paginated post IDs | 300s (5min) | `['search_results', 'forum:{forumId}']` |
| `user_forums:{userId}` | Array of readable forum IDs | 600s (10min) | `['user_permissions:{userId}']` |

**Tag invalidation triggers**:
- `PostCreatedEvent` → invalidate `search_results` tag
- `VisibilityChangedEvent` → invalidate `search_results` tag
- `PermissionsClearedEvent` (from Auth) → invalidate `user_permissions:{userId}` tag

---

### 3.7 `UserDisplayService::findDisplayByIds(int[] $ids): UserDisplayDTO[]`

**Source**: `phpbb\user\Service\UserDisplayService`
**Contract**: Batch-loads lightweight display DTOs for search result enrichment.

**`UserDisplayDTO` structure**:
```php
final readonly class UserDisplayDTO
{
    public int $id;
    public string $username;
    public string $colour;
    public string $avatarUrl;
}
```

**Usage in Search**: After retrieving post IDs from the index, hydrate results with poster display info for the frontend.

---

## 4. Data Requirements (What Data Search Needs Access To)

### 4.1 Post Data for Indexing

Obtained from `PostCreatedEvent` payloads or fetched via `PostRepository` for re-indexing/rebuilds.

| Field | Source | Index Purpose |
|-------|--------|---------------|
| `post_id` | `Post::$id` | Primary key in index |
| `topic_id` | `Post::$topicId` | Topic grouping, topic-level search |
| `forum_id` | `Post::$forumId` | Permission-based filtering |
| `poster_id` | `Post::$posterId` | Author search, shadow ban filter |
| `post_subject` | `Post::$subject` | Searchable text (title/subject) |
| `post_text` | `Post::$postText` | Searchable text (body content) — raw text |
| `post_time` | `Post::$postedAt` | Sort by relevance/date, time-range filtering |
| `post_visibility` | `Post::$visibility` | Only index `Visibility::Approved` (value=1) |

### 4.2 Topic Data for Topic-Level Search

| Field | Source | Index Purpose |
|-------|--------|---------------|
| `topic_id` | `Topic::$id` | Topic search result key |
| `forum_id` | `Topic::$forumId` | Permission filtering |
| `topic_title` | `Topic::$title` | Text search on title |
| `topic_poster` | `Topic::$posterId` | Author filter |
| `topic_time` | `Topic::$createdAt` | Sort/filter by creation date |
| `topic_last_post_time` | `Topic::$lastPostTime` | Sort by activity |
| `topic_visibility` | `Topic::$visibility` | Only index Approved topics |
| `topic_posts_approved` | `Topic::$postsApproved` | Display in results |

### 4.3 Data NOT Owned by Search (Retrieved at Result Time)

| Data | Source Service | Method |
|------|---------------|--------|
| Poster display info | `phpbb\user` | `UserDisplayService::findDisplayByIds()` |
| Forum name/path | `phpbb\hierarchy` | `HierarchyService::getForum()` or `getPath()` |
| Full post content (for snippets) | `phpbb\threads` | `ThreadsService::getPost()` or direct `PostRepository` |

---

## 5. Consumer Expectations (What Other Services Expect from Search)

### 5.1 API Controllers Expect: Post/Topic ID Lists

Search returns **ID arrays** — the Threads service handles hydration to full response DTOs.

```php
interface SearchServiceInterface
{
    /**
     * Search posts. Returns paginated post IDs matching query.
     * Caller (API controller) fetches full post data from Threads.
     */
    public function searchPosts(SearchQuery $query, User $user): SearchResult;

    /**
     * Search topics. Returns paginated topic IDs matching query.
     */
    public function searchTopics(SearchQuery $query, User $user): SearchResult;
}

final readonly class SearchResult
{
    public function __construct(
        /** @var int[] Post or topic IDs */
        public array $ids,
        public int $totalCount,
        public int $page,
        public int $perPage,
        public float $executionTimeMs,
    ) {}
}
```

### 5.2 Admin Expects: Reindex Capability

```php
interface SearchAdminInterface
{
    /**
     * Trigger a full search index rebuild.
     * Iterates all approved posts and re-indexes.
     * Should be async/batched for large forums.
     */
    public function rebuildIndex(int $triggeredByUserId): IndexRebuiltEvent;

    /**
     * Get current index statistics.
     */
    public function getIndexStats(): IndexStats;

    /**
     * Delete entire search index.
     */
    public function deleteIndex(): void;
}
```

### 5.3 Threads Calls Search: None

The Threads service does NOT call Search directly. Integration is **purely event-driven** — Threads dispatches events, Search subscribes. This is the plugin/listener pattern documented in the Threads architecture.

---

## 6. Permission Filtering Model

### Design: Pre-filter by Authorized Forums

Search applies **forum-level read permissions as a pre-filter**, NOT a post-filter.

**Flow**:
```
1. User initiates search
2. Search calls AuthorizationService::getGrantedForums($user, 'f_read')
   → Returns int[] of forum IDs user can access
3. Search query is constrained: WHERE forum_id IN ($readableForumIds)
4. Results returned are guaranteed to be in readable forums
```

**Why pre-filter (not post-filter)**:
- Avoids fetching results the user can't see (wasted I/O)
- Pagination is accurate (no gaps from filtered results)
- Index can use forum_id as a partition/filter dimension
- Consistent with legacy phpBB search which uses `ex_fid_ary` (excluded forum IDs)

**Edge cases**:
- **Global moderators** (`m_*` with global grant): see all forums → no forum filter applied
- **Admins** (`a_*`): see all forums → no forum filter applied
- **Guests**: only forums with `f_read` granted to GUESTS group
- **Forum-specific search** (user picks a forum): intersect subtree IDs with readable IDs

**Permission caching**:
- Auth service memoizes per-request internally
- Search may additionally cache `user_forums:{userId}` in its cache pool (TTL 10min)
- Invalidated when `PermissionsClearedEvent` fires for that user

---

## 7. Shadow Ban Filtering Model

### Design: Post-filter on Results

Shadow ban filtering is applied **after** retrieving results from the index, because:
1. Shadow-banned users' posts ARE in the index (visible to themselves and admins)
2. The filter is user-context-dependent (the banned user sees their own posts)
3. Shadow bans are relatively rare — post-filtering is acceptable

**Flow**:
```
1. Search retrieves post IDs from index
2. For each unique poster_id in results:
   a. Skip if poster_id === current user (always see own posts)
   b. Skip if current user is admin/moderator (sees everything)
   c. Call ShadowBanService::isShadowBanned(poster_id)
   d. If banned → remove their posts from results
3. Return filtered results
```

**Optimization — Batch Check**:
```php
// Collect unique poster IDs that need checking
$posterIds = array_unique(array_map(fn($r) => $r->posterId, $results));
$posterIds = array_diff($posterIds, [$currentUserId]); // exclude self

// Batch check (proposed method or iterate)
$bannedUsers = [];
foreach ($posterIds as $uid) {
    if ($this->shadowBanService->isShadowBanned($uid)) {
        $bannedUsers[] = $uid;
    }
}

// Filter
$results = array_filter($results, fn($r) => 
    $r->posterId === $currentUserId || !in_array($r->posterId, $bannedUsers)
);
```

**Impact on pagination**:
- Shadow ban filtering MAY reduce result count below `perPage`
- Options: (a) over-fetch by a margin (e.g., fetch perPage + 20%), (b) accept occasional short pages
- Recommended: over-fetch strategy since shadow bans are rare

**Cache interaction**:
- Shadow ban status can be cached in the `search` pool: key `shadow_ban:{userId}`, TTL 60s, tag `shadow_bans`
- Invalidated when `ShadowBanAppliedEvent` or `ShadowBanRemovedEvent` fires (from `phpbb\user`)

---

## Summary of Integration Points

| Integration | Direction | Mechanism | Frequency |
|-------------|-----------|-----------|-----------|
| Threads → Search | Event | `PostCreatedEvent` | Every new post |
| Threads → Search | Event | `PostEditedEvent` | Every edit |
| Threads → Search | Event | `PostSoftDeletedEvent` | Moderator action |
| Threads → Search | Event | `PostRestoredEvent` | Moderator action |
| Threads → Search | Event | `PostHardDeletedEvent` | Moderator action |
| Threads → Search | Event | `TopicDeletedEvent` | Moderator action |
| Threads → Search | Event | `VisibilityChangedEvent` | Approval/deletion |
| Threads → Search | Event | `TopicMovedEvent` | Moderator action |
| Search → Auth | Sync call | `getGrantedForums('f_read')` | Every search query |
| Search → Hierarchy | Sync call | `getSubtree()` | Forum-scoped searches |
| Search → User (Shadow) | Sync call | `isShadowBanned()` | Every search query (post-filter) |
| Search → User (Display) | Sync call | `findDisplayByIds()` | Result enrichment |
| Search → Cache | Sync call | `getPool('search')` | Every search (read/write) |
| Auth → Search | Event (indirect) | `PermissionsClearedEvent` | Permission changes |
| Search → Controllers | Return value | `SearchResult` (ID arrays) | Every search query |
