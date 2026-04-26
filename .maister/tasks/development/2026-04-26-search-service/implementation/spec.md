# Specification: M9 Search Service

## Goal

Implement a pluggable full-text search service (`phpbb\search`) for phpBB4 Meridian that exposes `GET /api/search` and selects a database-specific driver (`FullTextDriver`, `LikeDriver`, `ElasticsearchDriver`) based on the `search_driver` key stored in `phpbb_config`. A minimal `phpbb\config\ConfigRepository` is created as the cross-cutting phpBB admin config reader.

---

## User Stories

- As a logged-in user, I want to search forum posts by keyword so I can find relevant discussions.
- As a logged-in user, I want to optionally filter search results to a specific forum so I see only relevant content.
- As a logged-in user, I want paginated search results so I can browse large result sets.

---

## Core Requirements

1. `GET /api/search?q={term}` — searches `post_text`, `post_subject`, `topic_title` (z phpbb_topics), `forum_name` i `forum_desc` (z phpbb_forums); only posts with `post_visibility = 1` are returned.
2. Optional filters:
   - `?forum_id={int}` — narrows to a specific forum
   - `?topic_id={int}` — narrows to a specific topic
   - `?user_id={int}` — narrows to posts by a specific user (`poster_id`)
3. Pagination via `?page={int}&perPage={int}` (default: page=1, perPage=20, max perPage=50).
4. Endpoint requires JWT Bearer token — 401 if `_api_user` attribute is absent.
5. Driver selection reads `search_driver` key from `phpbb_config` via `ConfigRepository::get()` with default value `fulltext`.
6. `FullTextDriver` executes platform-specific full-text SQL:
   - MySQL/MariaDB: `MATCH(p.post_text, p.post_subject) AGAINST(:q IN BOOLEAN MODE)` for posts; plus LIKE fallback (`OR t.topic_title LIKE :qLike OR f.forum_name LIKE :qLike OR f.forum_desc LIKE :qLike`) for topic/forum fields (no FULLTEXT index on those tables). Two bind params: `:q` = raw term, `:qLike` = `'%term%'`.
   - PostgreSQL: `to_tsvector` on post fields + LIKE on topic/forum fields.
   - SQLite: LIKE fallback (delegates to LikeDriver).
7. `LikeDriver` uses LEFT JOINs to `phpbb_topics` and `phpbb_forums`, searches:
   `(p.post_text LIKE :q OR p.post_subject LIKE :q OR t.topic_title LIKE :q OR f.forum_name LIKE :q OR f.forum_desc LIKE :q)` where `:q = '%term%'`.
8. `ElasticsearchDriver` extends `LikeDriver`; overrides `search()` to emit a `LoggerInterface::warning()` before delegating to `parent::search()`.
9. `SearchService` resolves the driver from config and falls back to `LikeDriver` on any unrecognised driver value.
10. Response DTO fields: `postId`, `topicId`, `forumId`, `subject`, `excerpt` (first 200 chars of `post_text`), `postedAt`, `topicTitle` (from phpbb_topics.topic_title), `forumName` (from phpbb_forums.forum_name).
11. `ConfigRepository::get(string $key, string $default = ''): string` reads from `phpbb_config` table.

---

## Reusable Components

### Existing Code to Leverage

| Component | File | How to leverage |
|-----------|------|-----------------|
| `PaginationContext` | [src/phpbb/api/DTO/PaginationContext.php](../../../../../src/phpbb/api/DTO/PaginationContext.php) | Build via `PaginationContext::fromQuery($request->query)`; clamp `perPage` to 50 in controller before delegating |
| `PaginatedResult<T>` | [src/phpbb/user/DTO/PaginatedResult.php](../../../../../src/phpbb/user/DTO/PaginatedResult.php) | Return type from `search()` methods; holds `items[]`, `total`, `page`, `perPage` |
| `RepositoryException` | `src/phpbb/db/Exception/RepositoryException.php` | Wrap all `\Doctrine\DBAL\Exception` throws |
| `Doctrine\DBAL\Connection` | `src/phpbb/config/services.yaml` (registered) | Inject into `FullTextDriver`, `LikeDriver`, `ConfigRepository` |
| Module structure | [src/phpbb/notifications/](../../../../../src/phpbb/notifications/) | Mirror: `Contract/`, `DTO/`, `Driver/`, `Service/`; no `Entity/` needed (DTO only) |
| Controller pattern | [src/phpbb/api/Controller/NotificationsController.php](../../../../../src/phpbb/api/Controller/NotificationsController.php) | Auth guard, `PaginationContext::fromQuery`, inline meta array, `#[Route]` attribute |
| DI registration | [src/phpbb/config/services.yaml](../../../../../src/phpbb/config/services.yaml) | Append M9 block following existing module blocks |
| PHPUnit test pattern | `tests/phpbb/notifications/` | `#[Test]`, `#[DataProvider]`, `createMock(Interface::class)` |
| E2E helper | `tests/e2e/helpers/db.ts` | Seed/clear posts via mysql2; port 13306 |

### New Components Required

| Component | Justification |
|-----------|--------------|
| `phpbb\config\ConfigRepository` | No existing service reads from `phpbb_config` table; needed by `SearchService` for driver selection and by future modules |
| `phpbb\search\Contract\SearchDriverInterface` | New abstraction — pluggable driver pattern doesn't exist in codebase |
| `phpbb\search\Contract\SearchServiceInterface` | Public service contract for controller DI |
| `phpbb\search\Driver\FullTextDriver` | New — platform-aware full-text SQL |
| `phpbb\search\Driver\LikeDriver` | New — universal LIKE fallback |
| `phpbb\search\Driver\ElasticsearchDriver` | New — stub delegating to LikeDriver with warning log |
| `phpbb\search\Service\SearchService` | New — driver resolution + delegation |
| `phpbb\search\DTO\SearchResultDTO` | New domain DTO; no existing search result DTO exists |
| `phpbb\api\Controller\SearchController` | New — exposes `GET /api/search` |

---

## Technical Approach

### Driver Resolution Flow

```
SearchController
    → SearchService::search(query, forumId, topicId, userId, PaginationContext)
        → ConfigRepository::get('search_driver', 'fulltext')
        → switch driver value:
            'fulltext' → FullTextDriver
            'like'     → LikeDriver
            'elasticsearch' → ElasticsearchDriver (extends LikeDriver, logs warning)
            unknown    → LikeDriver (fallback)
        → driver->search(query, forumId, topicId, userId, pagination)
            → SELECT phpbb_posts + visibility filter + optional forum_id/topic_id/user_id filters
            → return PaginatedResult<SearchResultDTO>
    → SearchController builds JSON response
```

### Class Hierarchy

```
SearchDriverInterface
    ├── LikeDriver (implements SearchDriverInterface)
    │       └── ElasticsearchDriver extends LikeDriver
    └── FullTextDriver (implements SearchDriverInterface)
            └── injects: LikeDriver $fallback (for SQLite platform)

SearchServiceInterface
    └── SearchService (implements SearchServiceInterface)
            ├── injects: FullTextDriver, LikeDriver, ElasticsearchDriver
            └── injects: ConfigRepositoryInterface

ConfigRepositoryInterface  ← REQUIRED (for SearchService unit testability)
    └── ConfigRepository
            └── injects: Doctrine\DBAL\Connection
```

### SearchService Constructor Signature

```php
public function __construct(
    private readonly FullTextDriver               $fullTextDriver,
    private readonly LikeDriver                   $likeDriver,
    private readonly ElasticsearchDriver           $elasticsearchDriver,
    private readonly ConfigRepositoryInterface     $configRepository,
) {}
```

### Data Flow

1. Controller parses `Request` → builds `PaginationContext` (via `PaginationContext::fromQuery()`; controller clamps `perPage` to `min(50, $ctx->perPage)`), extracts `q`, `forumId`, `topicId`, `userId`.
2. `SearchService` reads `search_driver` config once per request via `$this->configRepository->get('search_driver', 'fulltext')`.
3. Selected driver executes **raw SQL via `$connection->executeQuery($sql, $params)`** and `$connection->fetchOne($countSql, $params)` — consistent with all other repositories in this codebase. **Do NOT use `createQueryBuilder()`** — it is incompatible with the mocked-Connection unit test strategy.
4. Separate `SELECT COUNT(*)` query for total (same WHERE conditions, no LIMIT/OFFSET). For FullTextDriver MySQL: COUNT query also includes `MATCH(post_text, post_subject) AGAINST(:q IN BOOLEAN MODE)` in WHERE.
5. Driver maps each row to `SearchResultDTO` via `SearchResultDTO::fromRow()`.
6. Controller serialises `SearchResultDTO[]` via `->toArray()` + inline meta. `meta.lastPage` = `$result->totalPages()`.

### ConfigRepository

- Reads single row: `SELECT config_value FROM phpbb_config WHERE config_name = :key LIMIT 1`
- Returns `(string) $row['config_value']` or `$default` when no row found
- No caching in M9 scope (DB round-trip per request is acceptable)
- Wraps `\Doctrine\DBAL\Exception` in `RepositoryException`

---

## File Structure

### New files — `src/phpbb/config/`

| File | Namespace | Responsibility |
|------|-----------|---------------|
| `Contract/ConfigRepositoryInterface.php` | `phpbb\config\Contract` | Interface: `get(string $key, string $default = ''): string` — required for testability |
| `ConfigRepository.php` | `phpbb\config` | Reads `phpbb_config` table; implements `ConfigRepositoryInterface` |

### Database Migration (required before FullTextDriver MySQL path works)

Add FULLTEXT index on `phpbb_posts` (phpbb_dump.sql currently has only B-TREE indexes):
```sql
ALTER TABLE phpbb_posts ADD FULLTEXT ft_posts (post_text, post_subject);
```
Deliver as seed in E2E setup (`tests/e2e/helpers/db.ts` `setupSearch()`) and document in migration notes. Without this, MySQL throws `Can't find FULLTEXT index matching the column list`.

### New files — `src/phpbb/search/`

| File | Namespace | Responsibility |
|------|-----------|---------------|
| `Contract/SearchDriverInterface.php` | `phpbb\search\Contract` | Driver contract: `search(string $query, ?int $forumId, ?int $topicId, ?int $userId, PaginationContext $ctx): PaginatedResult` |
| `Contract/SearchServiceInterface.php` | `phpbb\search\Contract` | Service contract: mirrors driver signature |
| `Driver/LikeDriver.php` | `phpbb\search\Driver` | LIKE-based fallback; implements `SearchDriverInterface` |
| `Driver/FullTextDriver.php` | `phpbb\search\Driver` | Platform-aware FTS; implements `SearchDriverInterface`; detects MySQL/PG/SQLite |
| `Driver/ElasticsearchDriver.php` | `phpbb\search\Driver` | Extends `LikeDriver`; logs warning before delegating to `parent::search()` |
| `Service/SearchService.php` | `phpbb\search\Service` | Resolves driver from config; implements `SearchServiceInterface` |
| `DTO/SearchResultDTO.php` | `phpbb\search\DTO` | Read-only result DTO; `fromRow(array $row): self`, `toArray(): array` |

### New files — `src/phpbb/api/Controller/`

| File | Namespace | Responsibility |
|------|-----------|---------------|
| `SearchController.php` | `phpbb\api\Controller` | `GET /api/search` — auth, pagination, search, response |

### Modified files

| File | Change |
|------|--------|
| `src/phpbb/config/services.yaml` | Append M9 DI block (ConfigRepository + search module services) |

### New files — `tests/phpbb/search/`

| File | Type |
|------|------|
| `Service/SearchServiceTest.php` | Unit — driver selection, fallback, unknown driver |
| `Driver/FullTextDriverTest.php` | Unit — mocked Connection, asserts SQL contains MATCH keyword |
| `Driver/LikeDriverTest.php` | Unit — mocked Connection, asserts LIKE pattern |
| `Driver/ElasticsearchDriverTest.php` | Unit — asserts warning logged + result delegated to parent |
| `DTO/SearchResultDTOTest.php` | Unit — `fromRow()` mapping, `toArray()` fields |

### New files — `tests/phpbb/config/`

| File | Type |
|------|------|
| `ConfigRepositoryTest.php` | Unit — `get()` returns value, returns default on missing key |

### New E2E files

| File | Scenarios |
|------|-----------|
| `tests/e2e/search.spec.ts` | UC-S1 through UC-S5 (see Acceptance Criteria) |

---

## Interface Contracts

```php
namespace phpbb\search\Contract;

use phpbb\api\DTO\PaginationContext;
use phpbb\user\DTO\PaginatedResult;

interface SearchDriverInterface
{
    /**
     * @return PaginatedResult<\phpbb\search\DTO\SearchResultDTO>
     */
    public function search(string $query, ?int $forumId, PaginationContext $ctx): PaginatedResult;
}
```

```php
namespace phpbb\search\Contract;

use phpbb\api\DTO\PaginationContext;
use phpbb\user\DTO\PaginatedResult;

interface SearchServiceInterface
{
    /**
     * @return PaginatedResult<\phpbb\search\DTO\SearchResultDTO>
     */
    public function search(string $query, ?int $forumId, PaginationContext $ctx): PaginatedResult;
}
```

---

## API Specification

### Endpoint

`GET /api/search`

### Request Parameters

| Parameter | Type | Required | Default | Constraints | Notes |
|-----------|------|----------|---------|-------------|---------|
| `q` | string | Yes | — | min 1 char | URL-decoded search term |
| `forum_id` | int | No | null | positive int | Narrows results to one forum |
| `topic_id` | int | No | null | positive int | Narrows results to one topic |
| `user_id` | int | No | null | positive int | Narrows results to posts by a specific user (`poster_id`) |
| `page` | int | No | 1 | ≥ 1 | Passed to `PaginationContext` |
| `perPage` | int | No | 25 | 1–50 | `PaginationContext::fromQuery()` default=25; controller clamps to `min(50, $ctx->perPage)` |

> **Note**: `PaginationContext::fromQuery()` defaults to `perPage=25` (source: `PaginationContext.php#L50`). SearchController overrides the cap to 50 via `new PaginationContext(page: $ctx->page, perPage: min(50, $ctx->perPage))`.

### Success Response — 200 OK

```json
{
  "data": [
    {
      "postId":   42,
      "topicId":  7,
      "forumId":  3,
      "subject":  "Re: phpBB4 development",
      "excerpt":  "phpBB is the most popular open-source forum software...",
      "postedAt": 1714089600
    }
  ],
  "meta": {
    "total":    128,
    "page":     1,
    "perPage":  20,
    "lastPage": 7
  }
}
```

### Error Responses

| HTTP Status | Condition | Body |
|-------------|-----------|------|
| 400 | `q` param missing or empty | `{"error": "Search term is required"}` |
| 401 | JWT Bearer token missing / invalid | `{"error": "Authentication required"}` |
| 500 | Unexpected exception | `{"error": "Internal server error"}` |

### Auth

Requires `Authorization: Bearer {jwt}` header. Middleware populates `$request->attributes->get('_api_user')`. Response 401 if attribute is `null`.

---

## Driver Specifications

### LikeDriver

- Tables: `phpbb_posts p LEFT JOIN phpbb_topics t ON t.topic_id = p.topic_id LEFT JOIN phpbb_forums f ON f.forum_id = p.forum_id`
- WHERE clause:
  ```sql
  (p.post_text LIKE :q OR p.post_subject LIKE :q
   OR t.topic_title LIKE :q
   OR f.forum_name LIKE :q
   OR f.forum_desc LIKE :q)
  AND p.post_visibility = 1
  ```
- Parameter binding: `:q = '%' . $query . '%'`
- Optional: `AND p.forum_id = :forumId`; `AND p.topic_id = :topicId`; `AND p.poster_id = :userId`
- Selected columns: `p.post_id, p.topic_id, p.forum_id, p.post_subject, p.post_text, p.post_time, t.topic_title, f.forum_name`
- Pagination: `LIMIT :perPage OFFSET :offset` where `offset = ($page - 1) * $perPage`
- Count query: same JOINs and WHERE without LIMIT/OFFSET, `SELECT COUNT(*)`
- Row mapping: `SearchResultDTO::fromRow($row)`

### FullTextDriver

Platform detected via `$connection->getDatabasePlatform()`:

**MySQL / MariaDB** (`instanceof MySQLPlatform`):
```sql
SELECT p.post_id, p.topic_id, p.forum_id, p.post_subject, p.post_text, p.post_time,
       t.topic_title, f.forum_name
FROM phpbb_posts p
LEFT JOIN phpbb_topics t ON t.topic_id = p.topic_id
LEFT JOIN phpbb_forums f ON f.forum_id = p.forum_id
WHERE (
    MATCH(p.post_text, p.post_subject) AGAINST(:q IN BOOLEAN MODE)
    OR t.topic_title LIKE :qLike
    OR f.forum_name LIKE :qLike
    OR f.forum_desc LIKE :qLike
)
  AND p.post_visibility = 1
  [AND p.forum_id = :forumId]
  [AND p.topic_id = :topicId]
  [AND p.poster_id = :userId]
LIMIT :perPage OFFSET :offset
```
> Two bind params: `:q` = raw query term (for MATCH), `:qLike` = `'%term%'` (for LIKE on topic/forum). No FULLTEXT index exists on phpbb_topics or phpbb_forums.

**PostgreSQL** (`instanceof PostgreSQLPlatform`):
```sql
SELECT p.post_id, p.topic_id, p.forum_id, p.post_subject, p.post_text, p.post_time,
       t.topic_title, f.forum_name
FROM phpbb_posts p
LEFT JOIN phpbb_topics t ON t.topic_id = p.topic_id
LEFT JOIN phpbb_forums f ON f.forum_id = p.forum_id
WHERE (
    to_tsvector('english', p.post_text || ' ' || p.post_subject)
        @@ plainto_tsquery('english', :q)
    OR t.topic_title LIKE :qLike
    OR f.forum_name LIKE :qLike
    OR f.forum_desc LIKE :qLike
)
  AND p.post_visibility = 1
  [AND p.forum_id = :forumId]
  [AND p.topic_id = :topicId]
  [AND p.poster_id = :userId]
LIMIT :perPage OFFSET :offset
```

**SQLite** (all other platforms including `SqlitePlatform`):
- Falls back to `LikeDriver` by delegating: `return $this->fallback->search($query, $forumId, $topicId, $userId, $ctx)`
- `FullTextDriver` injects `LikeDriver $fallback` in constructor — autowired automatically.
- Rationale: FTS5 virtual table provisioning is out of scope; SQLite is not a production target.

### ElasticsearchDriver

```
extends LikeDriver

override search():
    1. $logger->warning('Elasticsearch driver not implemented; falling back to LikeDriver', ['query' => $query])
    2. return parent::search($query, $forumId, $topicId, $userId, $ctx)
```

Constructor injects `Psr\Log\LoggerInterface` in addition to `Doctrine\DBAL\Connection`.

---

## ConfigRepository

### Schema

```sql
-- phpbb_config table
config_name  VARCHAR(255)  NOT NULL  PRIMARY KEY
config_value VARCHAR(255)  NOT NULL  DEFAULT ''
is_dynamic   TINYINT(1)    NOT NULL  DEFAULT 0
```

### Interface Contract

```php
namespace phpbb\config\Contract;

interface ConfigRepositoryInterface
{
    public function get(string $key, string $default = ''): string;
}
```

### Method Signature

```php
namespace phpbb\config;

use phpbb\config\Contract\ConfigRepositoryInterface;
use phpbb\db\Exception\RepositoryException;

final class ConfigRepository implements ConfigRepositoryInterface
{
    public function __construct(
        private readonly \Doctrine\DBAL\Connection $connection,
    ) {}

    /**
     * @throws RepositoryException
     */
    public function get(string $key, string $default = ''): string;
}
```

### Behaviour

- Executes: `SELECT config_value FROM phpbb_config WHERE config_name = :key LIMIT 1`
- Returns `(string) $row['config_value']` on hit.
- Returns `$default` if no row found (`fetchAssociative()` returns `false`).
- Wraps `\Doctrine\DBAL\Exception` in `RepositoryException`.
- No in-memory caching in M9 scope.

### DI Registration (appended to services.yaml)

```yaml
# ---------------------------------------------------------------------------
# Config module (M9 — phpbb_config reader)
# ---------------------------------------------------------------------------

phpbb\config\ConfigRepository: ~

phpbb\config\Contract\ConfigRepositoryInterface:
    alias: phpbb\config\ConfigRepository

# ---------------------------------------------------------------------------
# Search module (M9)
# ---------------------------------------------------------------------------

phpbb\search\Driver\LikeDriver: ~

phpbb\search\Driver\FullTextDriver: ~     # autowires LikeDriver $fallback

phpbb\search\Driver\ElasticsearchDriver: ~

phpbb\search\Service\SearchService: ~

phpbb\search\Contract\SearchServiceInterface:
    alias: phpbb\search\Service\SearchService
    public: true

phpbb\api\Controller\SearchController: ~
```

---

### SearchResultDTO

### Field Types

| Field | PHP type | Source column | Transformation |
|-------|----------|---------------|----------------|
| `postId` | `int` | `phpbb_posts.post_id` | `(int)` cast |
| `topicId` | `int` | `phpbb_posts.topic_id` | `(int)` cast |
| `forumId` | `int` | `phpbb_posts.forum_id` | `(int)` cast |
| `subject` | `string` | `phpbb_posts.post_subject` | `(string)` cast |
| `excerpt` | `string` | `phpbb_posts.post_text` | `mb_substr((string) $row['post_text'], 0, 200)` |
| `postedAt` | `int` | `phpbb_posts.post_time` | `(int)` cast (Unix timestamp) |
| `topicTitle` | `string` | `phpbb_topics.topic_title` | `(string)` cast |
| `forumName` | `string` | `phpbb_forums.forum_name` | `(string)` cast |

### Class Shape

```php
final readonly class SearchResultDTO
{
    public function __construct(
        public int    $postId,
        public int    $topicId,
        public int    $forumId,
        public string $subject,
        public string $excerpt,
        public int    $postedAt,
        public string $topicTitle,
        public string $forumName,
    ) {}

    public static function fromRow(array $row): self;
    public function toArray(): array;
}
```

---

## Implementation Guidance

### Testing Approach

- **2–6 unit tests per driver**: test SQL keyword presence via mock `Statement`/`Result`, test `forum_id` filter applied/omitted, test `post_visibility` filter.
- **2–3 unit tests for SearchService**: driver selection per config value, fallback on unknown value, `ConfigRepository::get` called with `('search_driver', 'fulltext')`.
- **2 unit tests for ConfigRepository**: found key returns value, missing key returns default.
- **2 unit tests for SearchResultDTO**: `fromRow()` maps all fields, `excerpt` truncated at 200 chars.
- **5 E2E scenarios** (UC-S1 through UC-S5): run against real MySQL on port 13306.
- Unit tests mock `Doctrine\DBAL\Connection` — no real SQL executed in PHPUnit suite.

### Standards Compliance

- **Naming & PHPDoc**: [.maister/docs/standards/global/STANDARDS.md](.maister/docs/standards/global/STANDARDS.md) — tabs for indentation, no closing `?>`, camelCase in OOP
- **PHP / Backend**: [.maister/docs/standards/backend/STANDARDS.md](.maister/docs/standards/backend/STANDARDS.md) — PSR-4 under `phpbb\`, constructor promotion, `final readonly` DTOs, no raw SQL interpolation
- **REST API**: [.maister/docs/standards/backend/REST_API.md](.maister/docs/standards/backend/REST_API.md) — `Authorization: Bearer` JWT, `PaginationContext::fromQuery`, meta block with `total`/`page`/`perPage`/`lastPage`
- **Testing**: [.maister/docs/standards/testing/STANDARDS.md](.maister/docs/standards/testing/STANDARDS.md) — PHPUnit 10 `#[Test]` attribute, `it_` naming, no `@Test` docblock

---

## Acceptance Criteria

### PHPUnit Unit Tests

| ID | Test | Expected |
|----|------|----------|
| U1 | `LikeDriverTest::it_applies_like_pattern_to_query` | Generated SQL contains `LIKE :q`; `:q` bound as `%term%` |
| U2 | `LikeDriverTest::it_adds_forum_id_filter_when_provided` | SQL contains `forum_id = :forumId` |
| U3 | `LikeDriverTest::it_omits_forum_id_filter_when_null` | SQL does not contain `forum_id` |
| U4 | `LikeDriverTest::it_always_filters_by_post_visibility_1` | SQL contains `post_visibility = 1` |
| U5 | `FullTextDriverTest::it_uses_match_against_on_mysql` | SQL contains `MATCH` and `AGAINST` |
| U6 | `FullTextDriverTest::it_falls_back_to_like_on_sqlite` | SQL contains `LIKE :q` (SQLite fallback) |
| U7 | `ElasticsearchDriverTest::it_logs_warning_and_returns_results` | `LoggerInterface::warning` called once; result matches `parent::search()` |
| U8 | `SearchServiceTest::it_uses_fulltext_driver_when_config_is_fulltext` | `FullTextDriver::search()` called |
| U9 | `SearchServiceTest::it_uses_like_driver_when_config_is_like` | `LikeDriver::search()` called |
| U10 | `SearchServiceTest::it_falls_back_to_like_on_unknown_driver_value` | `LikeDriver::search()` called |
| U11 | `ConfigRepositoryTest::it_returns_config_value_for_existing_key` | Returns string value from DB row |
| U12 | `ConfigRepositoryTest::it_returns_default_when_key_missing` | Returns `$default` argument |
| U13 | `SearchResultDTOTest::it_maps_all_fields_from_row` | All 6 fields mapped with correct types |
| U14 | `SearchResultDTOTest::it_truncates_excerpt_to_200_chars` | `excerpt` is ≤ 200 chars |

### Playwright E2E Tests

| ID | Scenario | Setup | Expected |
|----|----------|-------|----------|
| UC-S1 | Auth guard | No token | `GET /api/search?q=phpBB` → 401 |
| UC-S2 | Basic search returns results | Seed post with `post_visibility=1`, subject "phpBB is great"; seed `phpbb_config ('search_driver', 'like')` | `GET /api/search?q=phpBB` with token → 200, `data[0].subject` contains "phpBB is great" |
| UC-S3 | forum_id filter | Seed posts (`post_visibility=1`) in two forums | `GET /api/search?q=hello&forum_id={id}` → only posts from that forum returned |
| UC-S4 | Pagination | Seed 25+ matching posts (`post_visibility=1`) | `GET /api/search?q=test&perPage=10&page=2` → 200, `meta.page = 2`, `data.length ≤ 10` |
| UC-S5 | No match returns empty | No matching posts seeded | `GET /api/search?q=xyzzy_no_match` → 200, `data = []`, `meta.total = 0` |

> **E2E setup requirement**: All E2E tests must seed `phpbb_config` with `search_driver = 'like'` (avoids dependency on FULLTEXT index). Add `setupSearchConfig()` to `tests/e2e/helpers/db.ts`.

---

## Out of Scope

- Admin UI to view or change `search_driver` config value
- Write operations on `phpbb_config` (insert / update / delete)
- Search history or saved queries
- Per-forum ACL checks (visibility=1 filter is the only access control)
- Username JOIN (author info not in response DTO)
- Elasticsearch production integration (driver is a stub delegating to LIKE)
- SQLite FTS5 virtual table provisioning
- HTML stripping from `post_text` in excerpt
- Search result highlighting / snippet markup
- Index management (wordlist/wordmatch tables are read-only for phpBB3 compat; M9 does not write to them)
- Caching of `ConfigRepository::get()` results
- `phpbb_config` migration to insert `search_driver = fulltext` row (default fallback in code is sufficient)

---

## Success Criteria

- All 14 PHPUnit unit tests pass (`composer test`)
- All 5 E2E scenarios pass (`composer test:e2e`)
- `composer cs:fix` produces no changes
- `GET /api/search?q=phpBB` against a real MySQL instance returns correct paginated JSON with proper `meta` block
- Unknown `search_driver` config value silently falls back to `LikeDriver` (no exception thrown)

---

## Post-implementation Amendments (2026-04-26)

Spec rozszerzona podczas implementacji wg `.maister/plans/2026-04-26-search-service.md`:

### SearchQuery DTO (zastąpił 5-par. sygnaturę)

```php
readonly class SearchQuery {
    public string  $keywords,
    public ?int    $forumId   = null,
    public ?int    $topicId   = null,
    public ?int    $userId    = null,
    public string  $sortBy    = 'date_desc',   // date_desc | date_asc | relevance
    public string  $searchIn  = 'both',         // both | posts | titles | first_post
    public ?int    $dateFrom  = null,
    public ?int    $dateTo    = null,
}
```

Walidacja `sortBy` i `searchIn` w konstruktorze — rzuca `\InvalidArgumentException`.

### Nowe query params kontrolera

| Param | Typ | Domyślnie |
|-------|-----|-----------|
| `sort_by` | string | `date_desc` |
| `search_in` | string | `both` |
| `date_from` | int | — |
| `date_to` | int | — |

### DB Schema (migracja `Version20260426SearchNativeSchema`)

- `phpbb_search_wordlist` (word_id, word_text UNIQUE, word_count)
- `phpbb_search_wordmatch` (post_id, word_id, title_match) PK złożony

### Nowe klasy

| Klasa | Opis |
|-------|------|
| `search\Tokenizer\NativeTokenizer` | Tokenizacja: must/mustNot/should, CJK bigrams, min/max length |
| `search\Driver\NativeDriver` | Wyszukiwanie przez phpbb_search_wordlist/wordmatch; fallback → LikeDriver |
| `search\Contract\SearchIndexerInterface` | indexPost / deindexPost / reindexAll |
| `search\Service\SearchIndexerService` | Implementacja indeksera; wpisuje do wordlist/wordmatch |
| `search\Service\NullSearchIndexer` | No-op; używany gdy driver != native |

### Cache wyników

`SearchService::search()` używa `TagAwareCacheInterface::getOrCompute()` z tagiem `'search'` i TTL z `phpbb_config.search_cache_ttl` (domyślnie 300 s). TTL = 0 omija cache. Invalidacja po `deindexPost()` i `reindexAll()`.

### Finalne wyniki testów

- **PHPUnit**: 494 testów, 0 błędów
- **E2E**: 178 testów (10 search), 0 błędów
- **cs:fix**: 0 naruszeń
