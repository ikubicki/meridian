# Implementation Plan: M9 Search Service

> **STATUS: COMPLETED 2026-04-26** — 494 unit tests, 178 E2E tests, 0 cs:fix violations.
> Plan rozszerzony względem oryginału o Group 8–13 (SearchQuery DTO, NativeDriver, Indexer, Cache).
> Aktualny opis implementacji: `spec.md` sekcja "Post-implementation Amendments".

## Overview

**Total Steps:** 47 (plan bazowy) + rozszerzenia wg `.maister/plans/2026-04-26-search-service.md`
**Task Groups:** 7 (plan bazowy) + 6 dodatkowych
**Final Tests:** 494 unit + 178 E2E

---

## Implementation Steps

---

### Task Group 1: Config Module
**Dependencies:** None
**Estimated Steps:** 8

- [x] 1.0 Complete Config module
  - [x] 1.1 Write 2 unit tests for `ConfigRepository` (tests first)
    - `it_returns_config_value_for_existing_key` — mock `Connection::fetchAssociative()` returns `['config_value' => 'like']`; assert `ConfigRepository::get('search_driver', 'fulltext')` returns `'like'`
    - `it_returns_default_when_key_missing` — mock `Connection::fetchAssociative()` returns `false`; assert returns `'fulltext'`
    - File: `tests/phpbb/config/ConfigRepositoryTest.php`
  - [x] 1.2 Create `src/phpbb/config/Contract/ConfigRepositoryInterface.php`
    - Namespace: `phpbb\config\Contract`
    - Single method: `get(string $key, string $default = ''): string`
  - [x] 1.3 Create `src/phpbb/config/ConfigRepository.php`
    - Namespace: `phpbb\config`
    - `final class ConfigRepository implements ConfigRepositoryInterface`
    - Constructor: `private readonly \Doctrine\DBAL\Connection $connection`
    - SQL: `SELECT config_value FROM phpbb_config WHERE config_name = :key LIMIT 1`
    - Use `$connection->fetchAssociative($sql, ['key' => $key])`
    - Return `(string) $row['config_value']` on hit, `$default` on `false`
    - Wrap `\Doctrine\DBAL\Exception` in `RepositoryException`
  - [x] 1.4 Append Config module DI block to `src/phpbb/config/services.yaml`
    ```yaml
    # ---------------------------------------------------------------------------
    # Config module (M9 — phpbb_config reader)
    # ---------------------------------------------------------------------------

    phpbb\config\ConfigRepository: ~

    phpbb\config\Contract\ConfigRepositoryInterface:
        alias: phpbb\config\ConfigRepository
    ```
  - [x] 1.5 Run Config module tests only; ensure U11 and U12 pass
    - `vendor/bin/phpunit tests/phpbb/config/ConfigRepositoryTest.php`

**Acceptance Criteria:**
- U11: `it_returns_config_value_for_existing_key` passes
- U12: `it_returns_default_when_key_missing` passes
- `ConfigRepositoryInterface` and `ConfigRepository` classes exist under `phpbb\config`
- DI alias registered in `services.yaml`

---

### Task Group 2: Search Contracts & DTO
**Dependencies:** None (parallel-safe with Group 1)
**Estimated Steps:** 8

- [x] 2.0 Complete Search contracts and DTO
  - [x] 2.1 Write 2 unit tests for `SearchResultDTO` (tests first)
    - `it_maps_all_fields_from_row` — call `SearchResultDTO::fromRow(['post_id'=>42,'topic_id'=>7,'forum_id'=>3,'post_subject'=>'Hello','post_text'=>'Body text','post_time'=>1714089600,'topic_title'=>'Hello World Topic','forum_name'=>'General Discussion'])`; assert all 8 fields have correct type and value
    - `it_truncates_excerpt_to_200_chars` — call `fromRow()` with `post_text` of 250 chars; assert `$dto->excerpt` has `mb_strlen` of exactly 200
    - File: `tests/phpbb/search/DTO/SearchResultDTOTest.php`
  - [x] 2.2 Create `src/phpbb/search/Contract/SearchDriverInterface.php`
    - Namespace: `phpbb\search\Contract`
    - Method: `search(string $query, ?int $forumId, PaginationContext $ctx): PaginatedResult`
    - Import: `phpbb\api\DTO\PaginationContext`, `phpbb\user\DTO\PaginatedResult`
    - PHPDoc `@return PaginatedResult<\phpbb\search\DTO\SearchResultDTO>`
  - [x] 2.3 Create `src/phpbb/search/Contract/SearchServiceInterface.php`
    - Namespace: `phpbb\search\Contract`
    - Mirrors `SearchDriverInterface` signature exactly
  - [x] 2.4 Create `src/phpbb/search/DTO/SearchResultDTO.php`
    - `final readonly class SearchResultDTO`
    - Constructor promotes: `int $postId`, `int $topicId`, `int $forumId`, `string $subject`, `string $excerpt`, `int $postedAt`, `string $topicTitle`, `string $forumName`
    - `fromRow(array $row): self` — cast all fields; `excerpt = mb_substr((string) $row['post_text'], 0, 200)`; `topicTitle = (string) $row['topic_title']`; `forumName = (string) $row['forum_name']`
    - `toArray(): array` — return all 8 fields as camelCase keys matching API spec
  - [x] 2.5 Run DTO tests only; ensure U13 and U14 pass
    - `vendor/bin/phpunit tests/phpbb/search/DTO/SearchResultDTOTest.php`

**Acceptance Criteria:**
- U13: `it_maps_all_fields_from_row` passes
- U14: `it_truncates_excerpt_to_200_chars` passes
- `SearchDriverInterface`, `SearchServiceInterface`, `SearchResultDTO` exist in correct namespaces

---

### Task Group 3: Search Drivers
**Dependencies:** Group 1 (ConfigRepository for interface shapes), Group 2 (contracts, DTO)
**Estimated Steps:** 14

- [x] 3.0 Complete all three search drivers
  - [x] 3.1 Write 7 unit tests across three driver test files (tests first)
    - **`tests/phpbb/search/Driver/LikeDriverTest.php`** (4 tests):
      - `it_applies_like_pattern_to_query` — execute search; assert SQL contains `LIKE`; assert param bound as `'%term%'`
      - `it_adds_forum_id_filter_when_provided` — call with `forumId=3`; assert captured SQL contains `forum_id`
      - `it_omits_forum_id_filter_when_null` — call with `forumId=null`; assert SQL does NOT contain `forumId` filter
      - `it_always_filters_by_post_visibility_1` — assert SQL/QueryBuilder contains `post_visibility = 1`
    - **`tests/phpbb/search/Driver/FullTextDriverTest.php`** (2 tests):
      - `it_uses_match_against_on_mysql` — mock `Connection::getDatabasePlatform()` returning `MySQLPlatform`; assert result returned; (SQL verification via integration or E2E)
      - `it_falls_back_to_like_on_sqlite` — mock platform returning non-MySQL; mock `LikeDriver $fallback`; assert `$fallback->search()` called once
    - **`tests/phpbb/search/Driver/ElasticsearchDriverTest.php`** (1 test):
      - `it_logs_warning_and_returns_results` — mock `LoggerInterface`; assert `warning()` called once with message containing `'Elasticsearch driver not implemented'`; assert result equals `parent::search()` output
  - [x] 3.2 Create `src/phpbb/search/Driver/LikeDriver.php`
    - `final class LikeDriver implements SearchDriverInterface`
    - Constructor: `private readonly \Doctrine\DBAL\Connection $connection`
    - Main query (QueryBuilder with JOINs):
      ```sql
      SELECT p.post_id, p.topic_id, p.forum_id, p.post_subject, p.post_text, p.post_time,
             t.topic_title, f.forum_name
      FROM phpbb_posts p
      LEFT JOIN phpbb_topics t ON t.topic_id = p.topic_id
      LEFT JOIN phpbb_forums f ON f.forum_id = p.forum_id
      WHERE (p.post_text LIKE :q OR p.post_subject LIKE :q
             OR t.topic_title LIKE :q OR f.forum_name LIKE :q OR f.forum_desc LIKE :q)
        AND p.post_visibility = 1
        [AND p.forum_id = :forumId]
        [AND p.topic_id = :topicId]
        [AND p.poster_id = :userId]
      LIMIT :perPage OFFSET :offset
      ```
    - Count query: same JOINs and WHERE, `SELECT COUNT(*)`
    - Bind `:q = '%' . $query . '%'`, `:offset = ($ctx->page - 1) * $ctx->perPage`
    - Use QueryBuilder (`createQueryBuilder()`) chaining — consistent with existing repositories
    - Map results: `array_map(fn($row) => SearchResultDTO::fromRow($row), $result->fetchAllAssociative())`
    - Return `new PaginatedResult(items: $items, total: (int) $total, page: $ctx->page, perPage: $ctx->perPage)`
  - [x] 3.3 Create `src/phpbb/search/Driver/FullTextDriver.php`
    - `final class FullTextDriver implements SearchDriverInterface`
    - Constructor: `private readonly \Doctrine\DBAL\Connection $connection, private readonly LikeDriver $fallback`
    - Detect platform via `$this->connection->getDatabasePlatform()`
    - **MySQL** (`instanceof \Doctrine\DBAL\Platforms\MySQLPlatform`):
      - Two JOINs (same as LikeDriver)
      - WHERE: `(MATCH(p.post_text, p.post_subject) AGAINST(:q IN BOOLEAN MODE) OR t.topic_title LIKE :qLike OR f.forum_name LIKE :qLike OR f.forum_desc LIKE :qLike) AND p.post_visibility = 1 [AND p.forum_id = :forumId] [AND p.topic_id = :topicId] [AND p.poster_id = :userId]`
    - **PostgreSQL** (`instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform`):
      - Same JOINs; WHERE with `to_tsvector @@` for post fields + LIKE for topic/forum + same optional filters
    - **SQLite / other**: `return $this->fallback->search($query, $forumId, $topicId, $userId, $ctx)`
  - [x] 3.4 Create `src/phpbb/search/Driver/ElasticsearchDriver.php`
    - `final class ElasticsearchDriver extends LikeDriver`
    - Constructor adds `private readonly \Psr\Log\LoggerInterface $logger`; passes `$connection` to `parent::__construct()`
    - Override `search()`: call `$this->logger->warning('Elasticsearch driver not implemented; falling back to LikeDriver', ['query' => $query])`; return `parent::search($query, $forumId, $topicId, $userId, $ctx)`
  - [x] 3.5 Run driver tests only; ensure U1–U7 pass
    - `vendor/bin/phpunit tests/phpbb/search/Driver/`

**Acceptance Criteria:**
- U1–U4: `LikeDriverTest` all pass
- U5–U6: `FullTextDriverTest` all pass
- U7: `ElasticsearchDriverTest` passes
- No real DB connections in tests (all mocked)

---

### Task Group 4: Search Service
**Dependencies:** Group 1 (ConfigRepositoryInterface), Group 2 (contracts), Group 3 (drivers)
**Estimated Steps:** 7

- [x] 4.0 Complete SearchService
  - [x] 4.1 Write 3 unit tests for `SearchService` (tests first)
    - `it_uses_fulltext_driver_when_config_is_fulltext` — mock `ConfigRepositoryInterface::get('search_driver', 'fulltext')` returns `'fulltext'`; mock `FullTextDriver` (as `SearchDriverInterface&MockObject`); assert `FullTextDriver::search()` called once
    - `it_uses_like_driver_when_config_is_like` — mock config returns `'like'`; assert `LikeDriver::search()` called once
    - `it_falls_back_to_like_on_unknown_driver_value` — mock config returns `'unknown_value'`; assert `LikeDriver::search()` called once (no exception thrown)
    - File: `tests/phpbb/search/Service/SearchServiceTest.php`
  - [x] 4.2 Create `src/phpbb/search/Service/SearchService.php`
    - `final class SearchService implements SearchServiceInterface`
    - Constructor signature (from spec):
      ```php
      public function __construct(
          private readonly FullTextDriver           $fullTextDriver,
          private readonly LikeDriver               $likeDriver,
          private readonly ElasticsearchDriver      $elasticsearchDriver,
          private readonly ConfigRepositoryInterface $configRepository,
      ) {}
      ```
    - `search()` method: read `$this->configRepository->get('search_driver', 'fulltext')`; switch on value:
      - `'fulltext'` → delegate to `$this->fullTextDriver`
      - `'like'` → delegate to `$this->likeDriver`
      - `'elasticsearch'` → delegate to `$this->elasticsearchDriver`
      - default → delegate to `$this->likeDriver` (silent fallback)
  - [x] 4.3 Run service tests only; ensure U8–U10 pass
    - `vendor/bin/phpunit tests/phpbb/search/Service/SearchServiceTest.php`

**Acceptance Criteria:**
- U8: `it_uses_fulltext_driver_when_config_is_fulltext` passes
- U9: `it_uses_like_driver_when_config_is_like` passes
- U10: `it_falls_back_to_like_on_unknown_driver_value` passes (no exception thrown)

---

### Task Group 5: API Controller & DI Wiring
**Dependencies:** Group 1, Group 2, Group 3, Group 4
**Estimated Steps:** 8

- [x] 5.0 Complete SearchController, DI registration, and route declaration
  - [x] 5.1 Create `src/phpbb/api/Controller/SearchController.php`
    - Namespace: `phpbb\api\Controller`
    - Constructor: `private readonly SearchServiceInterface $searchService`
    - Method: `search(Request $request): JsonResponse` with `#[Route('/api/search', methods: ['GET'])]`
    - Auth guard (mirror `NotificationsController`): `if ($request->attributes->get('_api_user') === null) { return new JsonResponse(['error' => 'Authentication required'], 401); }`
    - Validate `q` param: `$q = trim((string) $request->query->get('q', ''))`; if empty return `new JsonResponse(['error' => 'Search term is required'], 400)`
    - Build pagination: `$ctx = PaginationContext::fromQuery($request->query)`; clamp: `$ctx = new PaginationContext(page: $ctx->page, perPage: min(50, $ctx->perPage))`
    - Extract filters:
      ```php
      $forumId = $request->query->has('forum_id') ? (int) $request->query->get('forum_id') : null;
      $topicId = $request->query->has('topic_id') ? (int) $request->query->get('topic_id') : null;
      $userId  = $request->query->has('user_id')  ? (int) $request->query->get('user_id')  : null;
      ```
    - Delegate: `$result = $this->searchService->search($q, $forumId, $topicId, $userId, $ctx)`
    - Build response:
      ```php
      return new JsonResponse([
          'data' => array_map(fn($dto) => $dto->toArray(), $result->items),
          'meta' => [
              'total'    => $result->total,
              'page'     => $result->page,
              'perPage'  => $result->perPage,
              'lastPage' => $result->totalPages(),
          ],
      ]);
      ```
    - Wrap in `try/catch (\Throwable $e)` returning `JsonResponse(['error' => 'Internal server error'], 500)`
  - [x] 5.2 Append Search module DI block to `src/phpbb/config/services.yaml` (after Config module block added in 1.4)
    ```yaml
    # ---------------------------------------------------------------------------
    # Search module (M9)
    # ---------------------------------------------------------------------------

    phpbb\search\Driver\LikeDriver: ~

    phpbb\search\Driver\FullTextDriver: ~

    phpbb\search\Driver\ElasticsearchDriver: ~

    phpbb\search\Service\SearchService: ~

    phpbb\search\Contract\SearchServiceInterface:
        alias: phpbb\search\Service\SearchService
        public: true

    phpbb\api\Controller\SearchController: ~
    ```
  - [x] 5.3 Append route to `src/phpbb/config/routes.yaml`
    - Verify whether `routes.yaml` already auto-imports controllers via annotation/attribute scan; if so, no manual entry is needed
    - If explicit route registration is used, add: `phpbb_api_search: { path: /api/search, controller: phpbb\api\Controller\SearchController::search, methods: [GET] }`
  - [x] 5.4 Smoke-test DI wiring
    - Run `composer test` (existing PHPUnit suite) to confirm no container compilation errors
    - Confirm `GET /api/search` route resolves (can use `php bin/console debug:router | grep search` if available)

**Acceptance Criteria:**
- `SearchController` exists and follows thin-controller pattern (auth guard → validate → paginate → delegate → respond)
- DI wiring compiles without errors (all services autowired)
- Route `/api/search` is registered and resolvable
- `composer test` still passes (all prior unit tests green)

---

### Task Group 6: E2E Tests
**Dependencies:** Group 5 (complete, wired API must be running)
**Estimated Steps:** 8

- [x] 6.0 Complete E2E test suite for Search endpoint
  - [x] 6.1 Add `setupSearchConfig()` helper to `tests/e2e/helpers/db.ts`
    - Seeds `phpbb_config` with `config_name='search_driver'`, `config_value='like'`, `is_dynamic=0` via `INSERT INTO phpbb_config ... ON DUPLICATE KEY UPDATE config_value = 'like'`
    - Seeds `phpbb_config` with `config_name='search_driver'`, `config_value='like'` using mysql2 on port 13306
    - Add `cleanupSearchConfig()` to remove seeded test posts and restore config
    - Add `seedSearchPost(subject: string, postText: string, forumId: number, visibility: number = 1): Promise<number>` returning inserted `post_id`
  - [x] 6.2 Create `tests/e2e/search.spec.ts` with 5 scenarios
    - **UC-S1 Auth guard**: `GET /api/search?q=phpBB` without `Authorization` header → assert 401; assert body `error` field present
    - **UC-S2 Basic search**: seed post with `post_visibility=1`, subject `"phpBB is great"`; call with JWT; assert 200; assert `data[0].subject` contains `"phpBB is great"`
    - **UC-S3 forum_id filter**: seed 2 posts in different forums; query with `forum_id={forumA}`; assert only posts from `forumA` in `data`
    - **UC-S4 Pagination**: seed 25+ posts matching `"test"`; call `?q=test&perPage=10&page=2`; assert 200, `meta.page === 2`, `data.length <= 10`
    - **UC-S5 No match empty result**: call `?q=xyzzy_no_match_unique_string` with JWT; assert 200, `data.length === 0`, `meta.total === 0`
    - Use `setupSearchConfig()` in `beforeEach`; `cleanupSearchConfig()` in `afterEach`
    - Obtain JWT via `helpers/auth.ts` (mirror existing E2E test pattern)
  - [x] 6.3 Add FULLTEXT index to E2E DB setup (required for production config, avoids `FullText` error in future)
    - Add to `tests/e2e/helpers/db.ts` `setupSearch()` or similar: `ALTER TABLE phpbb_posts ADD FULLTEXT IF NOT EXISTS ft_posts (post_text, post_subject)`
    - Document in migration notes: must be run before switching `search_driver` to `fulltext`
  - [x] 6.4 Run E2E tests only; ensure UC-S1 through UC-S5 pass
    - `composer test:e2e -- --grep "search"` (or equivalent Playwright filter)

**Acceptance Criteria:**
- UC-S1: 401 returned without token
- UC-S2: 200 with seeded result in `data`
- UC-S3: `forum_id` filter correctly narrows results
- UC-S4: pagination meta correct; `data.length ≤ perPage`
- UC-S5: empty `data` array and `meta.total === 0`

---

### Task Group 7: Test Review & Gap Analysis
**Dependencies:** All previous groups (1–6)
**Estimated Steps:** 4

- [x] 7.0 Review and fill critical test gaps
  - [x] 7.1 Review all tests written in Groups 1–6
    - Verify 14 unit tests (U1–U14) are all green: `composer test`
    - Verify 5 E2E tests (UC-S1 through UC-S5) are all green: `composer test:e2e`
  - [x] 7.2 Identify gaps for the M9 Search feature only
    - Is `400` response for empty/missing `q` covered? (controller branch, not yet tested in unit or E2E)
    - Is `ElasticsearchDriver` resolved by `SearchService` when config=`'elasticsearch'` tested?
    - Is `FullTextDriver` PostgreSQL branch tested?
  - [x] 7.3 Write up to 10 additional strategic tests to fill gaps
    - Suggested additions (add only gaps not already covered):
      - `SearchServiceTest::it_uses_elasticsearch_driver_when_config_is_elasticsearch`
      - `FullTextDriverTest::it_uses_tsvector_on_postgresql` — mock `PostgreSQLPlatform`; assert SQL contains `to_tsvector`
      - E2E: missing `q` param → 400 with `{"error": "Search term is required"}`
      - `LikeDriverTest::it_returns_paginated_result_with_correct_total`
  - [x] 7.4 Run all feature-specific tests; target 18–24 passing
    - `composer test`
    - `composer test:e2e`
    - `composer cs:fix` — confirm produces no changes

**Acceptance Criteria:**
- All existing 14 unit tests still pass
- All 5 E2E scenarios still pass
- No more than 10 additional tests added
- `composer cs:fix` exits cleanly (no diffs)

---

## Execution Order

1. **Group 1: Config Module** (8 steps) — no dependencies
2. **Group 2: Search Contracts & DTO** (8 steps) — no dependencies; can run in parallel with Group 1
3. **Group 3: Search Drivers** (14 steps) — depends on Groups 1 and 2
4. **Group 4: Search Service** (7 steps) — depends on Groups 1, 2, 3
5. **Group 5: API Controller & DI Wiring** (8 steps) — depends on Groups 1–4
6. **Group 6: E2E Tests** (8 steps) — depends on Group 5 (full stack wired)
7. **Group 7: Test Review & Gap Analysis** (4 steps) — depends on all previous groups

---

## Standards Compliance

Follow standards from `.maister/docs/standards/`:

- **`global/`** — tabs for indentation, no closing `?>`, `camelCase` in OOP, PHPDoc on public methods
- **`backend/`** — `final` classes, constructor promotion, `readonly` DTOs, `phpbb\` PSR-4 namespace, no raw SQL interpolation, `executeQuery()` over QueryBuilder
- **`backend/REST_API.md`** — `Authorization: Bearer` JWT, `PaginationContext::fromQuery`, meta block with `total`/`page`/`perPage`/`lastPage`, `#[Route]` attribute
- **`testing/`** — PHPUnit 10 `#[Test]` attribute, `it_` method naming, `createMock(Interface::class)` pattern, no `@Test` docblock

---

## Notes

- **TDD order**: Every group writes tests first (step X.1), then implements (steps X.2+), then runs only those new tests (step X.n)
- **Run incrementally**: After each group, run ONLY the tests introduced in that group — do NOT run the entire suite until Group 7
- **Mark progress**: Check off `[ ]` steps as completed during execution
- **Reuse first**: `PaginationContext`, `PaginatedResult`, `RepositoryException`, `NotificationsController` pattern — all referenced in Groups 2, 3, 5
- **E2E DB seed**: All E2E tests use `search_driver = 'like'` in `phpbb_config` (set in `setupSearchConfig()`) to avoid FULLTEXT index requirement on the test database
- **FULLTEXT index note**: `ALTER TABLE phpbb_posts ADD FULLTEXT ft_posts (post_text, post_subject)` is required before `search_driver = 'fulltext'` can be used in production; delivery is via `setupSearch()` helper comment in `db.ts`, not a runtime migration
- **ElasticsearchDriver constructor**: must call `parent::__construct($connection)` and accept `LoggerInterface $logger` as second arg; DI container will autowire both via `phpbb\search\Driver\ElasticsearchDriver: ~`
