# Codebase Analysis: phpbb\hierarchy Service

**Date**: 2026-04-22  
**Thoroughness**: Thorough

---

## 1. Existing Hierarchy Code

**Status: Stub only in new `src/phpbb/` structure**

- ✅ `src/phpbb/api/Controller/ForumsController.php` EXISTS — serving **hardcoded mock data** with TODO comments referencing `HierarchyService::listForums()` and `HierarchyService::getForum()`
- ❌ No Repository layer for forums
- ❌ No Service layer for hierarchy logic
- ❌ No Entity classes for forums
- ❌ No Contract (interface) definitions
- ❌ No TrackingService, SubscriptionService, or TreeService

Legacy reference: `src/phpbb3/` has `acp_forums.php` (monolith, 2245 LOC) and `nestedset_forum.php` class.

---

## 2. Key DBAL 4 Patterns to Follow

Based on 5 existing repositories (`DbalRefreshTokenRepository`, `DbalBanRepository`, `DbalUserRepository`, `DbalGroupRepository`):

### TABLE constant
```php
private const TABLE = 'phpbb_auth_refresh_tokens';
```

### Constructor
```php
public function __construct(
    private readonly \Doctrine\DBAL\Connection $connection,
) {
}
```

### SELECT queries
```php
$row = $this->connection->executeQuery(
    'SELECT * FROM ' . self::TABLE . ' WHERE user_id = :id LIMIT 1',
    ['id' => $id],  // Named params WITHOUT ':' prefix
)->fetchAssociative();
```

### Write queries
```php
$this->connection->executeStatement(
    'INSERT INTO ' . self::TABLE . ' (col1, col2) VALUES (:col1, :col2)',
    ['col1' => $value1, 'col2' => $value2],
);
```

### IN-list params
```php
use Doctrine\DBAL\ArrayParameterType;
$rows = $this->connection->executeQuery(
    'SELECT * FROM ' . self::TABLE . ' WHERE user_id IN (?)',
    [$ids],
    [ArrayParameterType::INTEGER],
)->fetchAllAssociative();
```

### Keyed return arrays
```php
$result = [];
foreach ($rows as $row) {
    $entity = $this->hydrate($row);
    $result[$entity->id] = $entity; // keyed by ID
}
return $result;
```

### Exception wrapping
```php
try {
    // ...
} catch (\Doctrine\DBAL\Exception $e) {
    throw new RepositoryException('msg', previous: $e);
}
```

### Integration tests
```php
class DbalForumRepositoryTest extends IntegrationTestCase
{
    protected function setUpSchema(): void
    {
        $this->connection->executeStatement('CREATE TABLE phpbb_forums (...)');
    }
}
```

---

## 3. Database Schema Summary

### `phpbb_forums`
- PK: `forum_id` (mediumint unsigned, auto-increment)
- Tree: `left_id`, `right_id`, `parent_id`, `forum_parents` (serialized PHP cache)
- Meta: `forum_name`, `forum_desc`, `forum_rules`, `forum_link`, `forum_password`, `forum_image`, `forum_style`
- Display: `forum_type` (0=cat, 1=post, 2=link), `forum_status`, `display_on_index`, `enable_indexing`, `display_subforum_list`
- Counters: `forum_topics_approved`, `forum_topics_unapproved`, `forum_topics_softdeleted`, `forum_posts_approved`, `forum_posts_unapproved`, `forum_posts_softdeleted`
- Last post: `forum_last_post_id`, `forum_last_poster_id`, `forum_last_post_subject`, `forum_last_post_time`, `forum_last_poster_name`, `forum_last_poster_colour`
- Pruning: `prune_next`, `prune_days`, `prune_viewed`, `prune_freq`, `enable_prune`, etc.
- Misc: `forum_topics_per_page`, `forum_flags`, `forum_options`
- Indexes: PK, `left_right_id(left_id, right_id)`, `forum_lastpost_id`

### `phpbb_forums_track`
- PK: (user_id, forum_id)
- Columns: `user_id`, `forum_id`, `mark_time` (unix timestamp)

### `phpbb_forums_watch`
- Columns: `forum_id`, `user_id`, `notify_status`
- Indexes: forum_id, user_id, notify_status

### `phpbb_forums_access`
- PK: (forum_id, user_id, session_id)
- Purpose: Optional permission cache (likely unused in new ACL)

---

## 4. API Layer Patterns

```php
namespace phpbb\api\Controller;

class ForumsController
{
    public function __construct(
        private readonly HierarchyServiceInterface $hierarchyService,
    ) {}

    #[Route('/forums', name: 'api_v1_forums_index', methods: ['GET'])]
    public function index(): JsonResponse {}

    #[Route('/forums/{forumId}', name: 'api_v1_forums_show', methods: ['GET'])]
    public function show(int $forumId): JsonResponse {}
}
```

Response format:
```php
// Collection
return new JsonResponse(['data' => $forums, 'meta' => ['total' => N]], 200);
// Single
return new JsonResponse(['data' => $forum], 200);
// Error
return new JsonResponse(['error' => 'Not found', 'status' => 404], 404);
// No content
return new Response(null, 204);
```

Auth: Bearer token via `Authorization` header. Public endpoints include `/health`, `/auth/*`.

---

## 5. Services.yaml Conventions

```yaml
# Implementation
phpbb\hierarchy\Repository\DbalForumRepository: ~

# Interface binding
phpbb\hierarchy\Contract\ForumRepositoryInterface:
    alias: phpbb\hierarchy\Repository\DbalForumRepository

# Service facade (public for controllers)
phpbb\hierarchy\Service\HierarchyService: ~
phpbb\hierarchy\Contract\HierarchyServiceInterface:
    alias: phpbb\hierarchy\Service\HierarchyService
    public: true

# DBAL Connection already defined, auto-injected via type hint
```

---

## 6. Integration Points

- `\Doctrine\DBAL\Connection` — auto-injected
- `phpbb\cache\CachePoolFactoryInterface` — for forum_parents cache (public service)
- `phpbb\auth\Contract\AuthorizationServiceInterface` — permissions (display layer, NOT hierarchy itself)
- `phpbb\db\Exception\RepositoryException` — standard exception wrapping
- Symfony EventDispatcher — for plugin hooks (pre/post CRUD events)

---

## 7. Clarifications Needed

1. **Locking strategy for tree mutations**: MySQL advisory lock (`GET_LOCK()` via DBAL), Symfony lock component, or DB transactions with `SELECT FOR UPDATE`?
2. **Event system scope**: Replicate phpBB3 events or design new Symfony events (incompatible but cleaner)?
3. **forum_parents cache**: Keep serialized PHP column pattern or move to Redis/cache service?
4. **Forum entity type**: Repository returns `Forum` domain entity or `ForumDTO` directly?
5. **Hierarchy Service scope (Phase 1)**: Implement all 5 services (HierarchyService + ForumRepository + TreeService + TrackingService + SubscriptionService) or just the core (ForumRepository + HierarchyService) with REST API wiring?
