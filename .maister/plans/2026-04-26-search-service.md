# Plan: phpBB4 Search Service — M9 (`phpbb\search`)

**Date**: 2026-04-26
**Scope**: M9 — rozbudowa istniejącego search o NativeDriver (własny indeks słów), SearchIndexerService, rozszerzone filtry i cache
**Reference phpBB3**: `src/phpbb3/forums/search/` (fulltext_native, fulltext_mysql, fulltext_postgres, fulltext_sphinx)
**Target dir**: `src/phpbb/search/`, `src/phpbb/db/migrations/`

---

## Stan wyjściowy (już zaimplementowane)

| Element | Plik | Status |
|---------|------|--------|
| `SearchDriverInterface` | `Contract/SearchDriverInterface.php` | ✅ (do rozszerzenia) |
| `SearchServiceInterface` + `SearchService` | `Service/` | ✅ |
| `LikeDriver` | `Driver/LikeDriver.php` | ✅ |
| `FullTextDriver` | `Driver/FullTextDriver.php` | ✅ (MySQL MATCH/AGAINST + PG tsvector) |
| `ElasticsearchDriver` | `Driver/ElasticsearchDriver.php` | ✅ (stub) |
| `SearchResultDTO` | `DTO/SearchResultDTO.php` | ✅ |
| `SearchController` | `api/Controller/SearchController.php` | ✅ (do rozszerzenia) |
| PHPUnit (podstawowe) | `tests/phpbb/search/` | ✅ |

---

## Applicable Standards

- `declare(strict_types=1)`, zakładka = tab, bez closing PHP tag
- `readonly` constructor promotion — wszystkie DTO i value objects
- PSR-4: `phpbb\search\` → `src/phpbb/search/`
- Doctrine DBAL + prepared statements — zero raw SQL z user input
- PHPUnit 10+: `#[Test]`, `#[DataProvider]`, bez adnotacji
- File header: `phpBB4 "Meridian"`, `@copyright (c) Irek Kubicki`

---

## Cel

1. **SearchQuery DTO** — enkapsulacja parametrów wyszukiwania (zastępuje długą sygnaturę metody)
2. **NativeDriver** — własny indeks słów wzorowany na `fulltext_native` phpBB3 (`phpbb_search_wordlist` + `phpbb_search_wordmatch`)
3. **SearchIndexerService** — indeksowanie postów przy create/edit/delete
4. **Rozszerzone filtry** — `sort_by`, `search_in`, `date_from`, `date_to`
5. **Cache wyników** — integracja z `CachePool` (M1)
6. **Pełne testy PHPUnit + E2E**

---

## Group 1: SearchQuery DTO + aktualizacja interfejsu

**Cel**: zastąpić 5 parametrów `search()` jednym DTO; dodać wsparcie dla sort / search_in / date range.

### `src/phpbb/search/DTO/SearchQuery.php`

```php
readonly class SearchQuery
{
    public function __construct(
        public string  $keywords,
        public ?int    $forumId   = null,
        public ?int    $topicId   = null,
        public ?int    $userId    = null,
        public string  $sortBy    = 'date_desc',   // date_desc | date_asc | relevance
        public string  $searchIn  = 'both',         // both | posts | titles | first_post
        public ?int    $dateFrom  = null,           // Unix timestamp
        public ?int    $dateTo    = null,           // Unix timestamp
    ) {}
}
```

Walidacja `sortBy` i `searchIn` — enum-style: rzuć `\InvalidArgumentException` przy nieznanej wartości.

### Aktualizacja `SearchDriverInterface`

Zmień sygnaturę:

```php
public function search(SearchQuery $query, PaginationContext $ctx): PaginatedResult;
```

### Aktualizacja istniejącychDriverów

- `LikeDriver::search()` — dostosuj do nowej sygnatury; zastosuj `search_in` (filtr kolumn) i `sort_by` (ORDER BY `post_time`)
- `FullTextDriver::search()` — j.w.; MySQL `MATCH/AGAINST` tylko gdy `search_in !== 'titles'`
- `ElasticsearchDriver::search()` — j.w. (nadal deleguje do LikeDriver)

### Aktualizacja `SearchService`

Dostosuj sygnaturę metody `search()` do `SearchQuery`.

### Aktualizacja `SearchController`

Nowe query params:
- `sort_by` (default: `date_desc`) — values: `date_desc`, `date_asc`, `relevance`
- `search_in` (default: `both`) — values: `both`, `posts`, `titles`, `first_post`
- `date_from` (int, Unix timestamp)
- `date_to` (int, Unix timestamp)

Zbuduj `SearchQuery` z params i przekaż do `searchService->search()`.

**Testy**: zaktualizuj `SearchServiceTest`, `LikeDriverTest`, `FullTextDriverTest`.

---

## Group 2: DB Migration — tabele NativeDriver

**Plik**: `src/phpbb/db/migrations/Version20260426SearchNativeSchema.php`

### Tabela `phpbb_search_wordlist`

| Kolumna | Typ | Opis |
|---------|-----|------|
| `word_id` | `INT UNSIGNED AUTO_INCREMENT PK` | — |
| `word_text` | `VARCHAR(255) NOT NULL` | słowo (lowercase) |
| `word_count` | `MEDIUMINT UNSIGNED NOT NULL DEFAULT 0` | ile postów zawiera słowo |

Indeks UNIQUE na `word_text`.

### Tabela `phpbb_search_wordmatch`

| Kolumna | Typ | Opis |
|---------|-----|------|
| `post_id` | `INT UNSIGNED NOT NULL` | FK → phpbb_posts.post_id |
| `word_id` | `INT UNSIGNED NOT NULL` | FK → phpbb_search_wordlist.word_id |
| `title_match` | `TINYINT(1) NOT NULL DEFAULT 0` | 1 = słowo pochodzi z subject/title |

PK złożony: `(post_id, word_id, title_match)`.  
Indeksy: `(word_id)`, `(post_id)`.

**Test migracji**: sprawdź że tabele istnieją i mają poprawne kolumny (SQLite-based integration test, jak w pozostałych migracjach).

---

## Group 3: NativeDriver

**Cel**: własny silnik wyszukiwania oparty na tabelach `phpbb_search_wordlist` + `phpbb_search_wordmatch`, wzorowany na `fulltext_native` phpBB3.

### `src/phpbb/search/Tokenizer/NativeTokenizer.php`

```php
final class NativeTokenizer
{
    public function __construct(
        private readonly int $minLength = 3,
        private readonly int $maxLength = 14,
    ) {}

    /** @return array{must: string[], mustNot: string[], should: string[]} */
    public function tokenize(string $keywords): array
```

- Rozpoznaje operatory: `+word` (must), `-word` (mustNot), `|word` (should / OR), brak prefiksu → must
- Filtruje słowa poniżej `minLength` i powyżej `maxLength`
- Lowercase + `mb_strtolower`; znaki diakrytyczne przepuszcza
- Usuwa znaki specjalne: `+-|()* ` (tylko jako separatory, nie w treści)
- CJK bigrams: dla znaków Hangul/CJK tworzy n-gramy o długości 2

### `src/phpbb/search/Driver/NativeDriver.php`

```php
final class NativeDriver implements SearchDriverInterface
{
    public function __construct(
        private readonly \Doctrine\DBAL\Connection $connection,
        private readonly NativeTokenizer $tokenizer,
        private readonly LikeDriver $fallback,
    ) {}

    public function search(SearchQuery $query, PaginationContext $ctx): PaginatedResult
```

**Algorytm `search()`**:
1. Tokenize `$query->keywords` → `{must, mustNot, should}`
2. Jeśli brak tokenów → fallback do `LikeDriver`
3. Pobierz `word_id` dla tokenów z `phpbb_search_wordlist`
4. Nierozpoznane słowa → fallback do `LikeDriver` (graceful degradation)
5. `must`: `INTERSECT` post_id z `phpbb_search_wordmatch` dla każdego word_id
6. `mustNot`: `EXCEPT` / `NOT IN (subquery)` 
7. `should`: co najmniej jeden musi pasować (`UNION`)
8. Zastosuj `search_in`: `title_match = 1` (titles), `title_match = 0` (posts), brak filtru (both)
9. JOIN z `phpbb_posts`, `phpbb_topics`, `phpbb_forums`
10. Filtruj `post_visibility = 1`, opcjonalnie `forum_id`, `topic_id`, `user_id`, `date_from/to`
11. `sort_by`: `date_asc/desc` → `ORDER BY p.post_time`, `relevance` → `ORDER BY word_match_count DESC`
12. Paginacja

**Fallback**: jeśli zapytanie nie zwraca żadnych treści z indeksu (puste tabele = nie zindeksowano) → log warning, deleguj do `LikeDriver`.

---

## Group 4: SearchIndexerService

**Cel**: indeksowanie treści postów w tabelach `search_wordlist` / `search_wordmatch` przy create/edit/delete.

### `src/phpbb/search/Contract/SearchIndexerInterface.php`

```php
interface SearchIndexerInterface
{
    public function indexPost(int $postId, string $text, string $subject, int $forumId): void;
    public function deindexPost(int $postId): void;
    public function reindexAll(): void;   // admin use: rebuild full index
}
```

### `src/phpbb/search/Service/SearchIndexerService.php`

```php
final class SearchIndexerService implements SearchIndexerInterface
{
    public function __construct(
        private readonly \Doctrine\DBAL\Connection $connection,
        private readonly NativeTokenizer $tokenizer,
        private readonly \Psr\Log\LoggerInterface $logger,
    ) {}
```

**`indexPost()`**:
1. `deindexPost($postId)` — najpierw usuń stary indeks
2. Tokenize `$text` → słowa treści (`title_match = 0`)
3. Tokenize `$subject` → słowa tytułu (`title_match = 1`)
4. Dla każdego słowa: `INSERT INTO phpbb_search_wordlist (word_text, word_count) VALUES (...) ON DUPLICATE KEY UPDATE word_count = word_count + 1`
5. `INSERT INTO phpbb_search_wordmatch (post_id, word_id, title_match) ...` (batch insert)

**`deindexPost()`**:
1. Pobierz `word_id` z `phpbb_search_wordmatch WHERE post_id = ?`
2. `DELETE FROM phpbb_search_wordmatch WHERE post_id = ?`
3. Dla każdego `word_id`: `UPDATE phpbb_search_wordlist SET word_count = word_count - 1 WHERE word_id = ?`
4. `DELETE FROM phpbb_search_wordlist WHERE word_count <= 0`

**`reindexAll()`**:
1. `DELETE FROM phpbb_search_wordmatch` — wyczyść
2. `DELETE FROM phpbb_search_wordlist`
3. Iteruj wszystkie posty (`SELECT post_id, post_text, post_subject FROM phpbb_posts WHERE post_visibility = 1`)
4. `indexPost()` dla każdego — batch po 1000 postów

### `src/phpbb/search/Service/NullSearchIndexer.php`

Implementacja `SearchIndexerInterface` — wszystkie metody są no-op. Używana gdy `search_driver !== 'native'`.

### Integracja z ThreadsService

W `src/phpbb/threads/Service/ThreadsService.php` (lub w `PostsService`):
- Po `createPost()` → `$indexer->indexPost(...)`
- Po `editPost()` → `$indexer->indexPost(...)` (reindeksuje)
- Po `deletePost()` → `$indexer->deindexPost(...)`

DI: wstrzyknij `SearchIndexerInterface` konstruktorem; konfiguracja wybiera `SearchIndexerService` lub `NullSearchIndexer` w zależności od `search_driver` config.

---

## Group 5: Cache wyników wyszukiwania

**Cel**: cache'owanie wyników w `SearchService` za pomocą `CachePool` (M1).

### Aktualizacja `SearchService`

```php
final class SearchService implements SearchServiceInterface
{
    public function __construct(
        // ... wszyskie drivery jak dotąd ...
        private readonly \phpbb\cache\TagAwareCacheInterface $cache,
        private readonly \phpbb\config\Contract\ConfigRepositoryInterface $configRepository,
    ) {}

    public function search(SearchQuery $query, PaginationContext $ctx): PaginatedResult
    {
        $ttl = (int) $this->configRepository->get('search_cache_ttl', '300'); // 5 min default
        if ($ttl <= 0) {
            return $this->getDriver()->search($query, $ctx);
        }

        $cacheKey = 'search.' . md5(json_encode([$query, $ctx]));

        return $this->cache->getOrCompute(
            $cacheKey,
            fn () => $this->getDriver()->search($query, $ctx),
            $ttl,
            ['search'],
        );
    }
```

Cache jest invalidowany tagiem `'search'` — wywołaj `$cache->invalidateTags(['search'])`:
- po `deindexPost()` (edit/delete)
- po `reindexAll()`

---

## Group 6: PHPUnit Tests

### `tests/phpbb/search/Tokenizer/NativeTokenizerTest.php`

- `tokenize_basic_words_returns_must_array`
- `plus_prefix_adds_to_must`
- `minus_prefix_adds_to_must_not`
- `pipe_prefix_adds_to_should`
- `words_below_min_length_are_ignored`
- `words_above_max_length_are_ignored`
- `tokenize_cjk_returns_bigrams`

### `tests/phpbb/search/Driver/NativeDriverTest.php`

Integration test z SQLite (jak `LikeDriverTest`):
- Setup: tworzy tabele `phpbb_search_wordlist`, `phpbb_search_wordmatch`, `phpbb_posts`, `phpbb_topics`, `phpbb_forums`; seeduje dane + indeks
- `search_finds_post_by_indexed_word`
- `search_with_must_not_excludes_post`
- `search_with_title_match_filters_correctly`
- `search_with_date_from_filters_by_post_time`
- `search_returns_empty_on_unknown_word_delegates_to_fallback`
- `search_paginates_correctly`

### `tests/phpbb/search/Service/SearchIndexerServiceTest.php`

Integration test z SQLite:
- `index_post_creates_wordlist_entries`
- `index_post_creates_wordmatch_entries`
- `deindex_post_removes_wordmatch_entries`
- `deindex_post_decrements_word_count`
- `deindex_post_deletes_words_with_zero_count`
- `reindex_all_rebuilds_index_from_scratch`

### `tests/phpbb/search/DTO/SearchQueryTest.php`

- `default_sort_by_is_date_desc`
- `invalid_sort_by_throws_exception`
- `invalid_search_in_throws_exception`

### `tests/phpbb/search/Service/SearchServiceCacheTest.php`

Unit test z mockami:
- `search_returns_cached_result_on_second_call`
- `search_bypasses_cache_when_ttl_zero`

### Aktualizacja istniejących testów

- `LikeDriverTest` — dostosuj do `SearchQuery` DTO
- `FullTextDriverTest` — j.w.
- `SearchServiceTest` — dostosuj do nowej sygnatury

---

## Group 7: Playwright E2E Tests

Plik: `tests/e2e/search.spec.ts`

### Use Cases

| UC | Opis | Endpoint |
|----|------|----------|
| UC-SR1 | Search returns posts matching keyword | `GET /search?q=hello` |
| UC-SR2 | Search with forum_id filter | `GET /search?q=hello&forum_id=1` |
| UC-SR3 | Search with user_id filter | `GET /search?q=hello&user_id=2` |
| UC-SR4 | Search with sort_by=date_asc | `GET /search?q=hello&sort_by=date_asc` |
| UC-SR5 | Search with search_in=titles | `GET /search?q=topicword&search_in=titles` |
| UC-SR6 | Search with date_from filter | `GET /search?q=hello&date_from=1700000000` |
| UC-SR7 | Empty query returns 400 | `GET /search` |
| UC-SR8 | Unauthenticated returns 401 | `GET /search?q=hello` (no JWT) |
| UC-SR9 | Pagination: page=2 returns next page | `GET /search?q=hello&page=2&perPage=5` |
| UC-SR10 | NativeDriver indexed search (jeśli config `search_driver=native`) | `GET /search?q=indexedword` |

**DB seeding** (via `helpers/db.ts`): seed 20 postów z różnymi tytułami i treściami + seed `phpbb_search_wordlist` / `phpbb_search_wordmatch` dla UC-SR10.

---

## MILESTONES.md — aktualizacja M9

Po wdrożeniu zaktualizuj MILESTONES.md:

```markdown
## M9 — Search Service (`phpbb\search`)

| # | Task | Status | Plan / Commit |
|---|------|--------|---------------|
| 9.1 | Research search | ✅ | `tasks/research/` |
| 9.2 | Implementation plan | ✅ | `plans/2026-04-26-search-service.md` |
| 9.3 | SearchQuery DTO + interface update + driver adaptation | ⏳ | Group 1 |
| 9.4 | DB Migration: search_wordlist + search_wordmatch | ⏳ | Group 2 |
| 9.5 | NativeDriver (NativeTokenizer + NativeDriver) | ⏳ | Group 3 |
| 9.6 | SearchIndexerService + NullSearchIndexer + ThreadsService wiring | ⏳ | Group 4 |
| 9.7 | Cache wyników (SearchService + CachePool) | ⏳ | Group 5 |
| 9.8 | PHPUnit tests (Groups 1–5) | ⏳ | Group 6 |
| 9.9 | Playwright E2E tests (`/api/v1/search/`) | ⏳ | Group 7 |
```

---

## Zależności

| Group | Wymaga |
|-------|--------|
| 1 | — |
| 2 | — |
| 3 | 1, 2 |
| 4 | 2, 3 |
| 5 | 1 (SearchService + CachePool z M1) |
| 6 | 1–5 |
| 7 | 1–6 (działający Docker stack) |
