# Search Service — Patterns & Literature Review

## 1. Tokenization Pipeline Patterns

### 1.1 The Elasticsearch/Lucene Analyzer Model

The industry-standard architecture (Elasticsearch, Lucene, Solr) decomposes text analysis into three sequential stages:

```
Input Text → [Character Filters] → [Tokenizer] → [Token Filters] → Tokens
```

| Stage | Responsibility | Examples |
|-------|---------------|----------|
| **Character Filters** (0..N) | Transform raw character stream before tokenization | HTML stripping, character normalization (e.g., Hindu-Arabic → Latin numerals), pattern replacement |
| **Tokenizer** (exactly 1) | Break character stream into individual tokens | Whitespace, Unicode word boundary (UAX#29), N-gram, pattern-based |
| **Token Filters** (0..N) | Post-process token stream | Lowercase, stop word removal, stemming (Snowball/Porter), min/max length, synonym expansion |

**Key design principle**: The same pipeline must be applied at both index time and query time to ensure matching. Elasticsearch explicitly separates `index_analyzer` from `search_analyzer` when asymmetric processing is needed (e.g., synonym expansion only at index time).

### 1.2 phpBB Native Approach (Current Implementation)

The existing `fulltext_native::cleanup()` method implements a single monolithic function that:
1. Converts encoding to UTF-8
2. Decodes HTML entities and NCR
3. Normalizes to NFC via `\Normalizer::normalize()`
4. Converts ASCII uppercase to lowercase via `strtr()`
5. Removes ASCII non-alpha characters
6. Processes multi-byte UTF-8 characters using lookup tables (`search_indexer_*.php` files)
7. Handles CJK/Hangul ranges with special min-length exemptions

`split_message()` then calls `cleanup()` and tokenizes on whitespace via `strtok()`.

**Limitations**: No stemming, no synonym support, no configurable pipeline, hardcoded character handling.

### 1.3 CJK/Unicode Word Splitting

CJK languages lack whitespace word boundaries. Two main approaches:

| Approach | How it works | Used by |
|----------|-------------|---------|
| **N-gram** | Split CJK text into overlapping character sequences (bigrams/trigrams) | MySQL `ngram_token_size`, phpBB native (single-char tokens for CJK range) |
| **Dictionary-based (MeCab/Jieba)** | Use language dictionaries to segment words | MySQL MeCab plugin, Elasticsearch ICU/kuromoji |
| **Character-level** | Each CJK character is an individual token | phpBB native current behavior |

**Recommendation for PHP 8.2+**: Use PHP `IntlBreakIterator` with `WORD_BOUNDARY` rules for Unicode-aware tokenization. For CJK, bigram tokenization is the pragmatic choice without external dependencies.

### 1.4 Recommended Pipeline Design for This Project

```php
interface TokenFilterInterface {
    /** @return string[] Filtered tokens (may add/remove/modify) */
    public function filter(array $tokens): array;
}

interface TokenizerInterface {
    /** @return string[] Raw tokens from text */
    public function tokenize(string $text): array;
}

interface AnalyzerInterface {
    /** @return string[] Final processed tokens */
    public function analyze(string $text): array;
}
```

**Pipeline composition** (configurable via config/DI):
```
HtmlStripFilter → NfcNormalizer → UnicodeTokenizer → [LowercaseFilter, MinLengthFilter, MaxLengthFilter, StopWordFilter, ?SnowballStemFilter]
```

**Key filters for this project**:
- `LowercaseFilter` — `mb_strtolower()`
- `MinLengthFilter` — configurable min (default: 3 for Latin, 1 for CJK)
- `MaxLengthFilter` — configurable max (default: 84, matching phpBB column size)
- `StopWordFilter` — language-specific stop word lists
- `CommonWordFilter` — frequency-based threshold (phpBB's `fulltext_native_common_thres`)
- `CjkBigramFilter` — split CJK sequences into bigrams

---

## 2. Inverted Index Design

### 2.1 Core Data Structure

An inverted index maps terms to documents:

```
Term → [(doc_id, field, frequency, positions?), ...]
```

**phpBB's native implementation uses two tables**:

| Table | Schema | Purpose |
|-------|--------|---------|
| `search_wordlist` | `word_id`, `word_text` (VARCHAR 255), `word_common` (BOOL), `word_count` (MEDIUMINT) | Dictionary — unique word → ID mapping |
| `search_wordmatch` | `post_id`, `word_id`, `title_match` (BOOL) | Posting list — word → document links with field distinction |

### 2.2 Field-Level Indexing

phpBB distinguishes title vs body via `title_match` boolean flag in `search_wordmatch`. This allows:
- Title-only search (`titleonly`)
- Body-only search (`msgonly`)
- First-post only search (`firstpost`)
- All fields search (`all`)

**Modern approach**: Store field information as a separate column or use separate posting lists per field. The boolean flag approach is simple and effective for 2 fields.

**Recommendation**: Keep the 2-field model (title + body) with a `field` ENUM column for extensibility:
```sql
CREATE TABLE search_index (
    post_id    INT UNSIGNED NOT NULL,
    word_id    INT UNSIGNED NOT NULL,
    field      TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 0=body, 1=title
    frequency  SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    PRIMARY KEY (word_id, post_id, field),
    INDEX idx_post (post_id)
);
```

### 2.3 Relevance Scoring

| Algorithm | Formula | Characteristics |
|-----------|---------|-----------------|
| **Simple frequency** | `word_count` in document | phpBB native uses this implicitly via SQL ordering |
| **TF-IDF** | `TF * log(N/df)` | Classic approach; InnoDB FULLTEXT uses `TF * IDF * IDF` |
| **BM25** | `TF * IDF * (k1 + 1) / (TF + k1 * (1 - b + b * dl/avgdl))` | Gold standard; accounts for document length normalization |

**MySQL InnoDB FULLTEXT** uses TF-IDF variant: `rank = TF * IDF * IDF` where `IDF = log10(total_records / matching_records)`.

**Recommendation for native backend**: Implement simple TF-IDF. Store `word_count` (document frequency) in wordlist table and `frequency` (term frequency) in match table. Calculate at query time:
```php
$score = $tf * log10($total_posts / max($df, 1));
```

For the native PHP backend, exact BM25 is overkill — the MySQL/PG backends provide better ranking natively. The native backend should aim for "good enough" ranking with simple TF-IDF.

### 2.4 Common Word Handling

Two strategies:

| Strategy | How | Trade-off |
|----------|-----|-----------|
| **Static stopword list** | Predefined list per language (the, a, is, ...) | Simple; misses domain-specific common words |
| **Frequency threshold** | Flag word as common if `word_count > total_posts * threshold` | Adaptive; phpBB uses `fulltext_native_common_thres` (default ~1%) |

**phpBB's approach**: The `tidy()` method periodically scans for words exceeding the threshold, flags them as `word_common = 1`, and deletes their match entries to save space. This is a background maintenance task run via cron.

**Recommendation**: Combine both — apply static stopword list at index time (never index), plus frequency threshold as maintenance task. The static list prevents obvious common words from ever entering the index.

### 2.5 Wildcard Support

| Approach | Implementation | Performance |
|----------|---------------|-------------|
| **Suffix wildcard (prefix matching)** | `word_text LIKE 'term%'` | Good with B-tree index on word_text |
| **Prefix wildcard (suffix matching)** | Store reversed words, search `LIKE 'reversed_prefix%'` | Requires additional reversed index |
| **Infix wildcard** | N-gram index or `LIKE '%term%'` | Expensive; N-gram approach preferred |

**phpBB native**: Supports only trailing wildcard (`word*`). Converts `*` to `%` and uses `LIKE` against `word_text`. Only one wildcard per query allowed to limit DB load.

**Recommendation**: Support prefix wildcard only (term*). It covers 90%+ of wildcard use cases. Use B-tree index on `word_text` column. Limit to one wildcard term per query.

---

## 3. Query Parsing Approaches

### 3.1 Boolean Operator Models

| System | AND | OR | NOT | Phrase | Wildcard | Grouping |
|--------|-----|----|----|--------|----------|----------|
| **phpBB native** | `+word` or default | `(word1\|word2)` | `-word` | Not supported | `word*` (trailing) | `(...)` |
| **MySQL BOOLEAN** | `+word` | default (no op) | `-word` | `"phrase"` | `word*` | `(...)` |
| **PostgreSQL** | `&` | `\|` | `!` | `<->` (adjacent) | `:*` (prefix) | `(...)` |
| **Sphinx** | implicit (space) | `\|` | `-word` or `!word` | `"phrase"` | `word*` | `(...)` |

### 3.2 AST-Based Query Parser

A proper query parser produces an Abstract Syntax Tree:

```
Input: "+php -java (mysql|postgres) framework*"

AST:
  AND
  ├── MUST("php")
  ├── MUST_NOT("java")
  ├── OR
  │   ├── TERM("mysql")
  │   └── TERM("postgres")
  └── PREFIX("framework")
```

**Implementation pattern** (recursive descent parser):
```php
class QueryParser {
    public function parse(string $input): QueryNode { ... }
}

abstract class QueryNode {}
class TermNode extends QueryNode { public string $term; public bool $wildcard; }
class PhraseNode extends QueryNode { public string $phrase; }
class BooleanNode extends QueryNode {
    public string $operator; // AND, OR
    public array $children;
}
class NotNode extends QueryNode { public QueryNode $child; }
```

### 3.3 phpBB Native Parser Analysis

The existing `split_keywords()` method (lines 195-395 of fulltext_native.php) performs:
1. Token extraction with operators: `+`, `-`, `|`, `(`, `)`, `*`, space
2. Character-by-character state machine for bracket handling
3. Regex cleanup passes for normalization
4. Word lookup in `search_wordlist` table to get `word_id`s
5. Classification into three arrays: `must_contain_ids`, `must_not_contain_ids`, `must_exclude_one_ids`

**Weakness**: No phrase search support in native backend. No proper AST — directly produces SQL building blocks.

### 3.4 Graceful Degradation

When complex parsing fails:
1. Catch parse exception
2. Fall back to treating entire input as space-separated OR terms
3. Strip all operators and search for individual words
4. Log degradation for monitoring

```php
try {
    $ast = $parser->parse($query);
} catch (QueryParseException $e) {
    // Fallback: treat as simple OR of all words
    $words = preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', '', $query));
    $ast = new OrNode(array_map(fn($w) => new TermNode($w), $words));
}
```

### 3.5 Recommendation

Implement a proper recursive descent parser producing an AST. The AST is then transformed into backend-specific queries:
- **Native**: Converted to `must_contain` / `must_not_contain` / `or_groups` arrays
- **MySQL**: Converted to `IN BOOLEAN MODE` query string
- **PostgreSQL**: Converted to `tsquery` expression

This separates parsing (shared) from execution (backend-specific).

---

## 4. Caching Strategies

### 4.1 Cache Key Composition

**phpBB's approach** (from `fulltext_native::keyword_search()`):
```php
$search_key = md5(implode('#', [
    serialize($must_contain_ids),
    serialize($must_not_contain_ids),
    serialize($must_exclude_one_ids),
    $type,           // posts|topics
    $fields,         // titleonly|msgonly|firstpost|all
    $terms,          // all|any
    $sort_days,
    $sort_key,
    $topic_id,
    implode(',', $ex_fid_ary),   // excluded forum IDs (permissions!)
    $post_visibility,
    implode(',', $author_ary),
    $author_name,
]));
```

**Critical insight**: The `ex_fid_ary` (excluded forums based on permissions) is part of the cache key. This means cache entries are per-permission-set, significantly reducing cache hit rate.

### 4.2 Cache Storage Model

phpBB uses a hybrid approach:
- **ACM cache**: Stores result ID arrays keyed by `_search_results_{$search_key}` with configurable TTL (`search_store_results`, default 1800s)
- **Database table**: `search_results` stores metadata (search_key, time, keywords, authors) for cache management

The cached data structure:
```php
$store = [
    -1 => $result_count,    // total results
    -2 => $sort_dir,        // sort direction
    0  => $first_id,        // result IDs by position
    1  => $second_id,
    // ... 
];
```

### 4.3 Cache Invalidation Approaches

| Strategy | Mechanism | Trade-off |
|----------|-----------|-----------|
| **TTL-based** | Results expire after N seconds | Simple; stale results possible within TTL window |
| **Event-based** | Invalidate on post create/edit/delete | Accurate; more complex; must identify affected searches |
| **Keyword-match** | Delete cached searches containing modified words | phpBB's approach via `destroy_cache($words)` — LIKE-matches keywords |
| **Full flush** | Clear all search cache on any content change | Simple; poor cache utilization |

**phpBB's `destroy_cache()` method**: When a post is created/edited/deleted, it:
1. Identifies the words affected
2. Queries `search_results` table for searches containing those words (`LIKE '%word%'`)
3. Deletes matching ACM cache entries

### 4.4 Recommended Caching Strategy

```
┌─────────────────────────────────────────────────┐
│  Cache Layer 1: Normalized Query Cache          │
│  Key: hash(normalized_query + sort + page)      │
│  Value: [result_ids]                            │
│  TTL: 300s (short, permission-independent)      │
│  Invalidation: event-based                      │
└─────────────────────────────────────────────────┘
         ↓ miss
┌─────────────────────────────────────────────────┐
│  Cache Layer 2: Permission-Filtered Results     │
│  Key: hash(query_key + permission_hash)         │
│  Value: [filtered_result_ids]                   │
│  TTL: 60s (very short due to permission combo)  │
│  Invalidation: on permission change event       │
└─────────────────────────────────────────────────┘
```

**Better approach for this project**: Separate content search from permission filtering.

1. **Cache raw search results** (permission-independent): `query_hash → [all matching post_ids]`
2. **Filter permissions at retrieval time**: Cheaper than storing per-user cached results
3. **Invalidate on content change**: Listen to `PostCreated`, `PostEdited`, `PostDeleted` events
4. **Use short TTL as safety net**: Even with event-based invalidation, cap at ~5 minutes

This dramatically improves cache hit rates because the permission-independent result set is shared across all users.

### 4.5 Cache Warming

For high-traffic forums:
- Pre-warm cache for empty-query "latest posts" type searches
- Re-warm popular queries after bulk invalidation (index rebuild)
- Don't warm user-specific queries (long tail, low hit rate)

---

## 5. Plugin Architecture Patterns

### 5.1 Strategy Pattern for Backends

**Current phpBB approach**: No formal interface — backends are selected by class name string in config (`search_type`), instantiated via `new $class_name(...)`. Duck-typing based on method presence (`keyword_search`, `author_search`, `index`, etc.).

**Recommended modern approach**:

```php
interface SearchBackendInterface {
    public function getName(): string;
    public function isAvailable(): bool;
    public function getCapabilities(): BackendCapabilities;
    
    // Indexing
    public function index(IndexableDocument $document): void;
    public function removeFromIndex(int $postId): void;
    public function rebuildIndex(iterable $documents, ?callable $progress = null): void;
    
    // Searching
    public function search(SearchQuery $query, SearchOptions $options): SearchResult;
    
    // Maintenance
    public function optimize(): void;
    public function getStats(): IndexStats;
}
```

### 5.2 Feature Capability Declarations

Backends should declare what they support:

```php
class BackendCapabilities {
    public bool $phraseSearch = false;
    public bool $wildcardSearch = false;
    public bool $booleanOperators = false;
    public bool $relevanceRanking = false;
    public bool $stemming = false;
    public bool $highlightSnippets = false;
    public bool $realtimeIndexing = true;   // vs batch-only like Sphinx delta
    public bool $fieldWeighting = false;
}
```

The search orchestrator uses capabilities to:
- Skip unsupported query features gracefully
- Show/hide UI options based on active backend
- Choose fallback behavior when feature is unsupported

### 5.3 Interface Segregation

Split the monolithic interface for backends that only support partial operations:

```php
interface SearcherInterface {
    public function search(SearchQuery $query, SearchOptions $options): SearchResult;
}

interface IndexerInterface {
    public function index(IndexableDocument $document): void;
    public function removeFromIndex(int $postId): void;
}

interface IndexAdminInterface {
    public function createIndex(): void;
    public function deleteIndex(): void;
    public function rebuildIndex(iterable $documents, ?callable $progress = null): void;
    public function getStats(): IndexStats;
}
```

A backend implements all or a subset. The orchestrator checks via `instanceof`.

### 5.4 Orchestrator Pattern

The search service orchestrator handles cross-cutting concerns:

```
SearchService (orchestrator)
├── Parses query (shared logic)
├── Checks permissions (shared logic)  
├── Checks cache (shared logic)
├── Delegates to backend (strategy)
├── Filters results by permissions (shared logic)
├── Stores in cache (shared logic)
└── Returns formatted results (shared logic)
```

Only the actual search/index execution is delegated to the backend. Everything else stays in the orchestrator.

### 5.5 Graceful Degradation

When a backend is unavailable:
```php
public function search(SearchQuery $query, SearchOptions $options): SearchResult {
    if (!$this->backend->isAvailable()) {
        if ($this->fallbackBackend !== null) {
            return $this->fallbackBackend->search($query, $options);
        }
        throw new SearchUnavailableException('Search backend is currently unavailable');
    }
    // ...
}
```

---

## 6. Event-Driven Indexing

### 6.1 Synchronous vs Asynchronous Indexing

| Mode | When to use | Trade-offs |
|------|-------------|------------|
| **Synchronous** | Post save → immediate index update | Simple; adds latency to post creation; guaranteed consistency |
| **Asynchronous (queue)** | Post save → queue event → worker indexes | No latency on post save; eventual consistency; requires queue infrastructure |
| **Delta/Batch** | Periodic re-index of changed documents | Sphinx model; simple; higher latency for new content appearing in search |

**phpBB native current behavior**: Synchronous. The `index()` method is called directly from `submit_post()` in `functions_posting.php`. The indexing (word extraction + DB insert) happens within the same request.

**Sphinx current behavior**: Uses delta indexing. A `max_doc_id` counter table tracks the last indexed post. New posts go into the delta index, which is periodically merged with the main index (every 3600s via cron `search_gc`).

### 6.2 Recommended Approach for This Project

**Primary: Synchronous indexing with event dispatch**

```php
// In Threads service when post is created:
$eventDispatcher->dispatch(new PostCreatedEvent($postId, $forumId, $subject, $body));

// Search service listener:
class SearchIndexListener {
    public function onPostCreated(PostCreatedEvent $event): void {
        $this->searchService->indexPost($event->postId, $event->subject, $event->body);
    }
    
    public function onPostEdited(PostEditedEvent $event): void {
        $this->searchService->reindexPost($event->postId, $event->newSubject, $event->newBody);
    }
    
    public function onPostDeleted(PostDeletedEvent $event): void {
        $this->searchService->removePost($event->postId);
    }
}
```

**Why synchronous for phpBB**: No queue infrastructure available in typical phpBB deployments. Synchronous indexing adds minimal latency (a few ms for word extraction + INSERT). The trade-off is acceptable.

**Batch re-indexing** (for index rebuild or backend switch):
```php
public function rebuildIndex(?callable $progress = null): void {
    $this->backend->deleteIndex();
    $this->backend->createIndex();
    
    $batch_size = (int) $this->config['search_block_size'];
    $offset = 0;
    
    while ($posts = $this->postRepository->getBatch($offset, $batch_size)) {
        foreach ($posts as $post) {
            $this->backend->index(new IndexableDocument($post));
        }
        $offset += $batch_size;
        $progress?.($offset);
    }
}
```

### 6.3 Index Consistency

Ensure consistency via:
1. **Transaction wrapping**: Index updates within the same DB transaction as post changes
2. **Idempotent operations**: Re-indexing a post should produce the same result regardless of current state
3. **Reconciliation**: Periodic validation that index matches actual content (maintenance task)

---

## 7. Permission Filtering Models

### 7.1 Pre-Filter vs Post-Filter

| Approach | How | Pros | Cons |
|----------|-----|------|------|
| **Pre-filter** | Only search content the user can access | Accurate counts; no wasted work | Must know all accessible forums upfront; cache per-user |
| **Post-filter** | Search everything, then remove unauthorized results | Better cache sharing; simpler search query | Inaccurate counts; may return fewer results than `per_page`; wastes search work |
| **Hybrid** | Pre-filter by forum access, post-filter for fine-grained visibility | Best of both; accurate forum-level; handles soft-delete etc. | More complex |

### 7.2 phpBB Current Approach: Pre-Filter (Forum-Level)

The existing search system uses **pre-filtering** at forum level:

```php
// web/search.php — Build excluded forum array
$ex_fid_ary = array_unique(array_merge(
    array_keys($auth->acl_getf('!f_read', true)),    // Can't read
    array_keys($auth->acl_getf('!f_search', true))   // Can't search
));

// Also exclude password-protected forums user hasn't unlocked
$not_in_fid = "WHERE f.forum_id NOT IN (...) OR (f.forum_password <> '' AND fa.user_id <> $user_id)";
```

This `ex_fid_ary` is then passed to the backend's `keyword_search()` method which adds:
```sql
AND p.forum_id NOT IN (1, 5, 12, ...)  -- excluded forums
AND {$post_visibility}                   -- visibility conditions per-forum
```

The `$post_visibility` string is generated by `content_visibility` service and handles:
- Approved/unapproved/soft-deleted post states
- Per-forum moderator visibility permissions

### 7.3 Recommended Architecture

**Hybrid approach with separated concerns**:

```
┌──────────────────┐     ┌──────────────────┐     ┌──────────────────┐
│  Search Backend  │────▸│  Permission      │────▸│  Final Results   │
│  (content match) │     │  Filter          │     │  (paginated)     │
└──────────────────┘     └──────────────────┘     └──────────────────┘
   Returns all              Applies:                Returns page of
   matching IDs             - Forum ACL              authorized results
                            - Visibility
                            - Shadow ban
```

**Implementation**:
```php
class PermissionFilteredSearch {
    public function search(SearchQuery $query, int $userId): PaginatedResult {
        // 1. Get accessible forum IDs for this user
        $accessibleForums = $this->authService->getAccessibleForums($userId, ['f_read', 'f_search']);
        
        // 2. Add forum constraint to search query (pre-filter)
        $query = $query->withForumScope($accessibleForums);
        
        // 3. Execute search (cached, permission-aware via forum scope)
        $rawResults = $this->searchBackend->search($query);
        
        // 4. Post-filter for visibility (soft-delete, unapproved)
        $visibleResults = $this->visibilityFilter->filter($rawResults, $userId);
        
        // 5. Post-filter for shadow bans
        $finalResults = $this->shadowBanFilter->filter($visibleResults);
        
        return new PaginatedResult($finalResults, $query->getOffset(), $query->getLimit());
    }
}
```

### 7.4 Performance Implications

| Forum count | Pre-filter cost | Cache impact |
|-------------|----------------|--------------|
| < 50 forums | Negligible (small IN clause) | Moderate fragmentation |
| 50-500 forums | Small (index scan on forum_id) | High fragmentation — many permission combos |
| 500+ forums | Consider bitmap/bitset approach | Use permission group hashing |

**Optimization**: Hash the sorted accessible forum list to create a "permission group" key. Users with identical forum access share the same cache entries:
```php
$permissionHash = md5(implode(',', sort($accessibleForumIds)));
$cacheKey = $queryHash . '#' . $permissionHash;
```

This significantly reduces cache fragmentation — most users with the same role/group have identical forum access.

---

## 8. Recommendations Summary

### Architecture Decisions

| Decision | Recommendation | Rationale |
|----------|---------------|-----------|
| **Tokenization** | Pipeline pattern with configurable filters | Extensible; testable; matches ES model |
| **Index design** | Wordlist + posting list tables (evolved from phpBB) | Proven at scale; simple SQL; familiar schema |
| **Relevance** | TF-IDF for native; delegate to DB for MySQL/PG | Native doesn't need BM25 complexity |
| **Query parser** | Recursive descent → AST → backend-specific translation | Clean separation of concerns |
| **Caching** | Two-layer: permission-independent results + permission-filtered page | Maximizes hit rate |
| **Invalidation** | Event-based (PostCreated/Edited/Deleted) + TTL safety net | Accurate invalidation without over-engineering |
| **Backend pattern** | Strategy with capability declarations | Clean plugin architecture |
| **Indexing mode** | Synchronous with event-driven architecture | No queue dependency; acceptable latency |
| **Permissions** | Hybrid pre-filter (forum ACL) + post-filter (visibility, shadow ban) | Performance + accuracy balance |

### Key Patterns to Implement

1. **AnalyzerInterface** — Configurable tokenization pipeline
2. **SearchBackendInterface** — Strategy pattern for pluggable backends
3. **BackendCapabilities** — Feature declaration for graceful degradation
4. **QueryParser** → AST → Backend-specific translator
5. **SearchOrchestrator** — Coordinates cache, permissions, backend delegation
6. **Event listeners** — `PostCreated`/`Edited`/`Deleted` → index update + cache invalidation
7. **PermissionFilter** — Separates content matching from access control

### What to Avoid

- **Don't reimplement BM25 in PHP** — Let MySQL/PG handle ranking for those backends
- **Don't cache per-user** — Cache per-permission-group for better hit rates
- **Don't do phrase search in native backend** — Document as limitation, let MySQL/PG/Sphinx handle it
- **Don't implement async queuing** — phpBB deployments rarely have queue infrastructure
- **Don't support infix wildcards** — Prefix-only covers 90%+ of use cases without performance issues
- **Don't stem by default** — Make it opt-in via config; many phpBB installations are multilingual

### Priority Order for Implementation

1. Backend interface + native backend (core functionality)
2. Tokenization pipeline (reusable across backends)
3. Query parser (shared component)
4. Cache layer (performance)
5. Event-driven indexing (integration)
6. MySQL/PostgreSQL backends (leverage DB capabilities)
7. Permission filtering (cross-service integration)
