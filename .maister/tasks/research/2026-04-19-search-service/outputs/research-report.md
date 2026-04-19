# Search Service — Research Report

| Field | Value |
|-------|-------|
| **Research type** | Mixed (technical + requirements + literature) |
| **Date** | 2026-04-19 |
| **Sources analyzed** | 5 finding documents, 4 legacy backend implementations, 3 DB tables, 30 config keys, 8 domain events |

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Service Architecture](#2-service-architecture)
3. [Backend Contract](#3-backend-contract)
4. [Indexing Pipeline](#4-indexing-pipeline)
5. [Query Processing](#5-query-processing)
6. [Result Caching](#6-result-caching)
7. [Cross-Service Contracts](#7-cross-service-contracts)
8. [Permission Filtering](#8-permission-filtering)
9. [Admin / Configuration](#9-admin--configuration)
10. [Decision Areas](#10-decision-areas)
11. [Confidence Assessment](#11-confidence-assessment)
12. [Risk Analysis](#12-risk-analysis)

---

## 1. Executive Summary

### What Was Researched

The complete search subsystem of phpBB was analyzed to inform a ground-up redesign of the `phpbb\search` service. This includes 4 legacy backend implementations (native inverted index, MySQL FULLTEXT, PostgreSQL tsvector, Sphinx), their database schemas, cross-service integration contracts with the new Threads/Auth/Hierarchy services, industry best practices for search engine internals, and the admin configuration/management surface.

### How It Was Researched

Five parallel information-gathering probes examined: (1) legacy backend source code to extract the implicit contract and per-backend behavior, (2) database DDL and SQL query patterns, (3) event contracts and service dependencies with adjacent services, (4) academic and industry literature on tokenization, inverted indexes, query parsing, caching, and plugin architecture, (5) ACP code for configuration, index rebuild, rate limiting, and load management.

### Key Findings

- **Implicit contract of 15 methods** shared across 4 backends — formalizable into a ~10-method `SearchBackendInterface`
- **Only the native backend maintains its own inverted index** (wordlist + wordmatch tables); MySQL/PG delegate to DB engine FULLTEXT; Sphinx delegates to external daemon
- **Orchestrator pattern already partially exists** in `base.php` (caching, result retrieval) but is broken by Sphinx which doesn't extend it
- **8 domain events** from Threads service provide complete coverage for index lifecycle
- **Permission filtering uses pre-filter at forum level** (excluded forum IDs in WHERE clause) — validated as correct approach
- **Query parsing is duplicated** across 4 backends with inconsistent operator support — should be unified via AST
- **Caching is fragmented** by permissions baked into cache keys — permission-group hashing can improve hit rate significantly
- **30 config keys** govern the system; ~15 must carry forward, ~15 can be redesigned

### Main Conclusions

The new search service should follow an **orchestrator + pluggable backend** architecture where the orchestrator owns parsing, caching, permissions, rate limiting, and result formatting — delegating only index read/write to backends. A formal `SearchBackendInterface` with capability declarations enables graceful degradation across backends with different feature sets. Event-driven synchronous indexing, AST-based query parsing, and hybrid permission filtering (pre-filter forums, post-filter visibility/shadow bans) are the recommended approaches.

---

## 2. Service Architecture

### Recommended Structure: Orchestrator + Pluggable Backends

```
┌──────────────────────────────────────────────────────────────┐
│                        API Layer                              │
│   SearchController::search(), SearchController::author()      │
└──────────────────────────┬───────────────────────────────────┘
                           │
┌──────────────────────────▼───────────────────────────────────┐
│                   SearchService (Orchestrator)                 │
│                                                               │
│  ┌─────────────┐  ┌──────────────┐  ┌───────────────────┐   │
│  │ RateLimiter  │  │ LoadChecker  │  │ PermissionResolver│   │
│  └─────────────┘  └──────────────┘  └───────────────────┘   │
│                                                               │
│  ┌─────────────┐  ┌──────────────┐  ┌───────────────────┐   │
│  │ QueryParser  │  │  CacheLayer  │  │ ShadowBanFilter   │   │
│  │  (→ AST)     │  │ (tag-aware)  │  │ (post-filter)     │   │
│  └─────────────┘  └──────────────┘  └───────────────────┘   │
│                                                               │
│  ┌──────────────────────────────────────────────────────┐    │
│  │             SearchBackendInterface                    │    │
│  │  search() | index() | remove() | rebuild() | stats() │    │
│  └────┬──────────┬───────────┬───────────┬──────────────┘    │
│       │          │           │           │                    │
└───────┼──────────┼───────────┼───────────┼────────────────────┘
        ▼          ▼           ▼           ▼
   ┌─────────┐ ┌────────┐ ┌──────────┐ ┌────────┐
   │ Native  │ │ MySQL  │ │PostgreSQL│ │ Sphinx │
   │ Backend │ │Backend │ │ Backend  │ │Backend │
   └─────────┘ └────────┘ └──────────┘ └────────┘
```

### Orchestrator Responsibilities (NOT delegated to backends)

| Concern | Implementation |
|---------|---------------|
| Rate limiting | Check `search_interval` / `search_anonymous_interval` against `user_last_search` |
| Load protection | Check system load against `limit_search_load` |
| Query parsing | `QueryParser::parse()` → AST, shared across all backends |
| Tokenization | `AnalyzerInterface::analyze()` — same pipeline for index and query |
| Permission resolution | `AuthorizationService::getGrantedForums()` → forum ID whitelist |
| Cache check/store | Tag-aware cache pool with permission-group-hash keys |
| Shadow ban post-filter | `ShadowBanService::isShadowBanned()` batch check |
| Result formatting | `SearchResult` DTO with IDs, total count, pagination, timing |
| Event dispatching | `SearchPerformedEvent`, `IndexRebuiltEvent` |

### Backend Responsibilities (delegated)

| Concern | Implementation |
|---------|---------------|
| Index storage | Backend-specific: word tables (native), FULLTEXT (MySQL), GIN (PG), external (Sphinx) |
| Query execution | Translate AST to backend-specific query (SQL, tsquery, SphinxQL) |
| Index creation/deletion | Backend-specific DDL or API calls |
| Statistics | Backend-specific: word count, index size, etc. |
| Availability check | Can this backend operate? (DB engine check, daemon connectivity) |

---

## 3. Backend Contract

### Proposed `SearchBackendInterface`

Based on the extracted 15-method implicit contract across 4 backends, rationalized to a clean interface:

```php
namespace phpbb\search\Contract;

interface SearchBackendInterface
{
    /** Unique identifier for config/registration */
    public function getId(): string;

    /** Human-readable name for ACP display */
    public function getName(): string;

    /** Check prerequisites (DB engine, extensions, daemon connectivity) */
    public function isAvailable(): bool;

    /** Declare supported features */
    public function getCapabilities(): BackendCapabilities;

    /** Execute a search query. AST already parsed by orchestrator. */
    public function search(CompiledQuery $query, SearchOptions $options): BackendSearchResult;

    /** Index a single document (post). Called synchronously on post create/edit. */
    public function indexDocument(IndexableDocument $document): void;

    /** Remove a document from index. Called on post delete. */
    public function removeDocument(int $postId): void;

    /** Remove multiple documents. Called on topic delete. */
    public function removeDocuments(array $postIds): void;

    /** Update forum_id for posts in a topic. Called on topic move. */
    public function updateForumId(int $topicId, int $newForumId): void;

    /** Periodic maintenance (common word recalculation, orphan cleanup). */
    public function optimize(): void;

    /** Return index statistics for ACP. */
    public function getStats(): IndexStats;
}
```

### Separate Admin Interface

```php
interface SearchBackendAdminInterface
{
    /** Create the backend's index structures (tables, FULLTEXT indexes, etc.) */
    public function createIndex(): void;

    /** Drop the backend's index structures */
    public function deleteIndex(): void;

    /** Check if index structures exist */
    public function indexExists(): bool;

    /** Rebuild entire index. Accepts iterable of documents + progress callback. */
    public function rebuildIndex(iterable $documents, ?callable $progress = null): void;

    /** Return ACP-specific configuration fields */
    public function getAdminConfig(): array;
}
```

### Capability Declaration

```php
final readonly class BackendCapabilities
{
    public function __construct(
        public bool $phraseSearch = false,
        public bool $wildcardSearch = false,
        public bool $booleanOperators = true,
        public bool $relevanceRanking = false,
        public bool $stemming = false,
        public bool $realtimeIndexing = true,
        public bool $fieldWeighting = false,
        public WordLengthRange $wordLength = new WordLengthRange(3, 14),
    ) {}
}
```

### Mapping Legacy Methods → New Interface

| Legacy Method | New Method | Notes |
|---------------|-----------|-------|
| `get_name()` | `getName()` | Direct mapping |
| `init()` | `isAvailable()` | Returns bool, not error string |
| `split_keywords()` | Moved to orchestrator `QueryParser` | No longer backend-specific |
| `keyword_search()` | `search()` | Receives pre-parsed `CompiledQuery` |
| `author_search()` | `search()` | Author filter is part of `SearchOptions` |
| `index()` | `indexDocument()` | Receives `IndexableDocument` DTO |
| `index_remove()` | `removeDocument()` / `removeDocuments()` | Separated single/batch |
| `tidy()` | `optimize()` | Renamed for clarity |
| `create_index()` | `SearchBackendAdminInterface::createIndex()` | Separated admin interface |
| `delete_index()` | `SearchBackendAdminInterface::deleteIndex()` | Separated admin interface |
| `index_created()` | `SearchBackendAdminInterface::indexExists()` | Renamed |
| `index_stats()` | `getStats()` | Returns `IndexStats` DTO |
| `acp()` | `SearchBackendAdminInterface::getAdminConfig()` | Returns structured config |
| `get_search_query()` | Orchestrator responsibility | Not in backend interface |
| `get_common_words()` | Orchestrator responsibility | TokenFilter returns filtered words |
| `get_word_length()` | `getCapabilities()->wordLength` | Part of capabilities |
| `obtain_ids()` / `save_ids()` | Orchestrator `CacheLayer` | Not in backend interface |
| `destroy_cache()` | Orchestrator `CacheLayer` | Tag-based invalidation |

### Key DTOs

```php
final readonly class IndexableDocument
{
    public function __construct(
        public int $postId,
        public int $topicId,
        public int $forumId,
        public int $posterId,
        public string $subject,
        public string $body,       // raw text, pre-BBCode-stripping
        public int $postTime,
        public bool $isFirstPost,
    ) {}
}

final readonly class SearchOptions
{
    public function __construct(
        public string $type,           // 'posts' | 'topics'
        public array $forumIds,        // allowed forum IDs (pre-filtered by permissions)
        public ?int $topicId,          // restrict to topic
        public array $authorIds,       // filter by author(s)
        public ?string $authorName,    // author name (for wildcard)
        public ?int $sortDays,         // restrict to last N days
        public string $sortKey,        // 'relevance' | 'time' | 'author' | 'forum' | 'subject'
        public string $sortDir,        // 'asc' | 'desc'
        public int $offset,
        public int $limit,
    ) {}
}

final readonly class BackendSearchResult
{
    public function __construct(
        /** @var int[] Post or topic IDs */
        public array $ids,
        public int $totalCount,
    ) {}
}
```

---

## 4. Indexing Pipeline

### Tokenization Architecture

The legacy `cleanup()` method is a monolithic function handling encoding, normalization, case folding, and CJK processing in one pass. The new service should use the Elasticsearch-standard pipeline pattern:

```
Raw Text → [CharacterFilters] → [Tokenizer] → [TokenFilters] → Terms
```

### Proposed Pipeline

```php
interface AnalyzerInterface {
    /** @return string[] Processed terms ready for indexing or querying */
    public function analyze(string $text): array;
}

class SearchAnalyzer implements AnalyzerInterface {
    public function __construct(
        private array $charFilters,     // CharacterFilterInterface[]
        private TokenizerInterface $tokenizer,
        private array $tokenFilters,    // TokenFilterInterface[]
    ) {}

    public function analyze(string $text): array {
        foreach ($this->charFilters as $filter) {
            $text = $filter->filter($text);
        }
        $tokens = $this->tokenizer->tokenize($text);
        foreach ($this->tokenFilters as $filter) {
            $tokens = $filter->filter($tokens);
        }
        return $tokens;
    }
}
```

### Default Pipeline Configuration

| Stage | Component | Purpose |
|-------|----------|---------|
| CharFilter 1 | `BbcodeStripFilter` | Remove `[code]...[/code]`, BBCode tags, HTML |
| CharFilter 2 | `HtmlEntityDecodeFilter` | Convert `&amp;` etc. to characters |
| CharFilter 3 | `NfcNormalizerFilter` | Unicode NFC normalization |
| Tokenizer | `UnicodeWordTokenizer` | Split on Unicode word boundaries (UAX#29 via `IntlBreakIterator`) |
| TokenFilter 1 | `LowercaseFilter` | `mb_strtolower()` |
| TokenFilter 2 | `MinLengthFilter` | Drop tokens shorter than config min (default 3, 1 for CJK) |
| TokenFilter 3 | `MaxLengthFilter` | Drop tokens longer than config max (default 84) |
| TokenFilter 4 | `CjkBigramFilter` | Split CJK character sequences into bigrams |
| TokenFilter 5 | `StopWordFilter` (optional) | Remove language-specific stop words |

### Indexing Flow (Native Backend)

```
PostCreatedEvent
    │
    ▼
SearchIndexListener::onPostCreated()
    │
    ▼
SearchService::indexPost(postId, subject, body)
    │
    ├── analyzer.analyze(subject) → subject_terms[]
    ├── analyzer.analyze(body) → body_terms[]
    │
    ▼
NativeBackend::indexDocument(IndexableDocument)
    │
    ├── Look up existing word IDs in search_wordlist
    ├── INSERT new words (if not exists)
    ├── INSERT word↔post links in search_wordmatch (with field flag)
    ├── UPDATE word_count (+1) in search_wordlist
    └── Invalidate affected cache entries (tag-based)
```

### Indexing Flow (MySQL/PostgreSQL Backends)

```
PostCreatedEvent
    │
    ▼
SearchIndexListener::onPostCreated()
    │
    ▼
SearchService::indexPost(postId, subject, body)
    │
    ▼
MysqlBackend::indexDocument(IndexableDocument)
    │
    └── No-op (DB engine auto-maintains FULLTEXT index on INSERT/UPDATE)
        └── Only action: invalidate affected cache entries
```

### Edit Mode (Differential Indexing — Native Only)

On post edit, the native backend:
1. Queries existing word associations for the post
2. Computes diff: new_words = new_terms - existing_terms; removed_words = existing_terms - new_terms
3. INSERTs new associations, DELETEs removed associations
4. Updates word_count (+1 for additions, -1 for removals)

This differential approach avoids full re-indexing on every edit and is validated by legacy code analysis.

---

## 5. Query Processing

### Parser Specification

The query parser transforms user input into a backend-agnostic AST:

```
Input: "+php -java (mysql|postgres) framework*"

AST:
  BooleanNode(AND)
  ├── TermNode("php", modifier=MUST)
  ├── TermNode("java", modifier=MUST_NOT)
  ├── BooleanNode(OR)
  │   ├── TermNode("mysql")
  │   └── TermNode("postgres")
  └── PrefixNode("framework")
```

### AST Node Types

| Node | Represents | Legacy Equivalent |
|------|-----------|------------------|
| `TermNode(term, modifier)` | Single word; modifier = MUST, MUST_NOT, SHOULD | `+word`, `-word`, `word` |
| `PhraseNode(phrase)` | Quoted phrase `"exact match"` | MySQL/Sphinx `"phrase"` |
| `BooleanNode(op, children)` | AND/OR group | Implicit AND, `(a|b)` for OR |
| `PrefixNode(prefix)` | Wildcard `term*` | `word*` trailing wildcard |
| `NotNode(child)` | Negation | `-word`, `-(a|b)` |

### Operator Support Matrix

| Operator | Syntax | Native | MySQL | PostgreSQL | Sphinx |
|----------|--------|--------|-------|-----------|--------|
| AND (implicit) | `word1 word2` | `INNER JOIN per word` | `+word1 +word2` | `word1 & word2` | `word1 word2` |
| OR | `(word1\|word2)` | `LEFT JOIN + IS NULL OR` | `word1 word2` (no +) | `word1 \| word2` | `word1 \| word2` |
| NOT | `-word` | `LEFT JOIN + IS NULL` | `-word` | `& !word` | `-word` |
| Phrase | `"exact phrase"` | Degrade to AND | `"exact phrase"` | Degrade to AND | `"exact phrase"` |
| Prefix | `word*` | `LIKE 'word%'` on wordlist | `word*` | Not supported → strip | `word*` |

### Backend Translators

Each backend implements a `QueryTranslator` that converts the AST:

```php
interface QueryTranslatorInterface {
    public function translate(QueryNode $ast, BackendCapabilities $caps): CompiledQuery;
}
```

- **NativeQueryTranslator**: AST → `must_contain_ids[]`, `must_not_contain_ids[]`, `or_group_ids[]` + SQL fragments
- **MysqlQueryTranslator**: AST → `MATCH(...) AGAINST('...' IN BOOLEAN MODE)` string
- **PostgresQueryTranslator**: AST → `to_tsquery('config', '...')` string
- **SphinxQueryTranslator**: AST → Sphinx extended query string

### Graceful Degradation

When a backend doesn't support an operator:
1. `PhraseNode` → degrade to `BooleanNode(AND, [TermNode, TermNode, ...])` on native/PG
2. `PrefixNode` → strip wildcard, search exact term on PG
3. Complex nesting → flatten to top-level AND

The orchestrator logs degradation for monitoring.

### Query Validation Rules (carried from legacy)

| Rule | Source | Behavior |
|------|--------|----------|
| Max keywords: `max_num_search_keywords` | Admin-config | Reject with error if exceeded |
| Empty query after filtering | Legacy-backends | Return false / empty result |
| Negation-only query | Native/PG backends | Reject (no positive terms) |
| Word length outside [min, max] | All backends | Silently drop, add to common_words display |
| Single wildcard limit | Native backend | Allow max 1 wildcard term per query |

---

## 6. Result Caching

### Legacy Architecture (Two-Tier)

```
┌──────────────────────────────┐     ┌──────────────────────────┐
│ phpbb_search_results (DB)    │     │ ACM File Cache           │
│ search_key (MD5), PK         │     │ _search_results_{key}    │
│ search_time                  │◄───►│ Array: [-1=>count,       │
│ search_keywords (MEDIUMTEXT) │     │  -2=>sort_dir, 0=>id,    │
│ search_authors (MEDIUMTEXT)  │     │  1=>id, ...]             │
└──────────────────────────────┘     └──────────────────────────┘
```

**Problems identified**:
1. `LIKE '%word%'` invalidation on `search_keywords` — O(n) table scan per word
2. Permission data (ex_fid_ary) baked into cache key — high fragmentation
3. Sphinx bypasses caching entirely — no consistent layer
4. `MEDIUMTEXT` columns for simple word/author lists — schema overkill
5. No index on `search_time` — TTL cleanup is slow at scale

### Recommended Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  Tag-Aware Cache Pool (Symfony Cache / phpBB CachePool)      │
│                                                               │
│  Key: search:{queryHash}:{permGroupHash}:{sort}:{page}       │
│  Value: BackendSearchResult (ids[], totalCount)               │
│  TTL: 300s (configurable)                                     │
│  Tags: ['search_results', 'forum:{fid}', ...]               │
│                                                               │
│  Invalidation:                                                │
│    PostCreatedEvent  → invalidate tag 'search_results'        │
│    PostEditedEvent   → invalidate tag 'search_results'        │
│    PostDeletedEvent  → invalidate tag 'search_results'        │
│    PermCleared       → invalidate tag 'user_perms:{userId}'   │
└─────────────────────────────────────────────────────────────┘
```

### Cache Key Design

```php
$queryHash = md5(serialize($normalizedAst) . '#' . $options->sortKey . '#' . $options->sortDir . '#' . $options->sortDays);
$permGroupHash = md5(implode(',', $sortedForumIds));
$pageKey = $options->offset . ':' . $options->limit;

$cacheKey = "search:{$queryHash}:{$permGroupHash}:{$pageKey}";
```

**Key improvement**: Users with identical forum access (same role/group) share the same `permGroupHash`, dramatically improving hit rates compared to the per-`ex_fid_ary` legacy approach.

### Invalidation Strategy

| Trigger | Action | Scope |
|---------|--------|-------|
| Post created/edited/deleted | Flush tag `search_results` | All cached search results |
| Post visibility changed | Flush tag `search_results` | All cached search results |
| Permissions changed for user | Flush tag `user_perms:{userId}` | That user's permission cache |
| TTL expiry | Auto-eviction | Per-entry |
| Admin: clear cache | Flush namespace `search` | All search cache |

**Trade-off accepted**: Flushing all `search_results` on any content change is aggressive but simple. For high-traffic forums, this could be refined to word-specific tags (similar to legacy LIKE approach but using proper cache tags instead of SQL LIKE). This optimization can be added later.

### Cache Bypass

Sphinx backend (if supported) bypasses the cache layer since it has its own internal caching. The orchestrator checks `backend.getCapabilities().realtimeIndexing` — if false (delta-based), caching at the orchestrator level adds no value.

---

## 7. Cross-Service Contracts

### Events Consumed (from `phpbb\threads`)

| Event | Action | Priority |
|-------|--------|----------|
| `PostCreatedEvent` | Index post (if visibility=Approved) | Synchronous |
| `PostEditedEvent` | Re-index post (differential) | Synchronous |
| `PostSoftDeletedEvent` | Remove from index | Synchronous |
| `PostRestoredEvent` | Re-add to index (fetch content from repo) | Synchronous |
| `PostHardDeletedEvent` | Permanently remove from index | Synchronous |
| `TopicDeletedEvent` | Batch remove all post IDs | Synchronous |
| `VisibilityChangedEvent` | Add or remove based on new visibility | Synchronous |
| `TopicMovedEvent` | Update forum_id in index entries | Synchronous |

### Events Dispatched

| Event | Payload | Consumers |
|-------|---------|-----------|
| `SearchPerformedEvent` | userId, query, resultCount, executionTimeMs, searchType, forumId | Analytics, audit |
| `IndexRebuiltEvent` | totalPostsIndexed, durationSeconds, triggeredByUserId | Admin notification |

### Service Dependencies

| Service | Method | Purpose | Call Frequency |
|---------|--------|---------|---------------|
| `AuthorizationService` | `getGrantedForums($user, 'f_read')` | Resolve readable forums | Every search query |
| `AuthorizationService` | `isGranted($user, 'm_approve', $forumId)` | Check moderator visibility | When visibility filter needed |
| `HierarchyService` | `getSubtree($forumId)` | Resolve sub-forums for scoped search | Forum-scoped searches |
| `ShadowBanService` | `isShadowBanned($userId)` | Filter shadow-banned users' posts | Every search query (post-filter) |
| `CachePoolFactory` | `getPool('search')` | Get tag-aware cache pool | Service initialization |
| `UserDisplayService` | `findDisplayByIds($ids)` | Enrich results with poster info | Result enrichment (optional) |

### Service Contract (What Search Exposes)

```php
interface SearchServiceInterface
{
    public function searchPosts(SearchQuery $query, User $user): SearchResult;
    public function searchTopics(SearchQuery $query, User $user): SearchResult;
}

interface SearchAdminInterface
{
    public function rebuildIndex(int $triggeredByUserId): IndexRebuiltEvent;
    public function getIndexStats(): IndexStats;
    public function deleteIndex(): void;
}
```

The service returns **ID arrays** — hydration to full DTOs is the controller's responsibility (via Threads service).

---

## 8. Permission Filtering

### Recommended: Hybrid Pre-Filter + Post-Filter

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│  Forum Pre-Filter│────▸│ Backend Search   │────▸│ Post-Filter     │
│  (WHERE IN)      │     │ (content match)  │     │ (shadow bans,   │
│                  │     │                  │     │  edge visibility)│
└─────────────────┘     └─────────────────┘     └─────────────────┘
```

**Pre-filter (forum-level ACL)**:
1. `AuthorizationService::getGrantedForums($user, 'f_read')` → readable forum IDs
2. If user searches in specific forum: intersect with `HierarchyService::getSubtree($forumId)`
3. Pass resulting forum IDs as constraint to backend (`WHERE forum_id IN (...)`)

**Post-filter (user-context-dependent)**:
1. **Shadow ban filter**: For each poster_id in results, check `ShadowBanService::isShadowBanned()`. Remove shadow-banned posts unless viewer is the poster or an admin/moderator.
2. **Visibility edge cases**: Moderators may see unapproved/soft-deleted posts in their forums — handled by expanding visibility parameter per-forum based on `m_approve` permission.

**Why this hybrid**:
- Pre-filter eliminates unauthorized results early (no wasted I/O)
- Pagination stays accurate for the forum-level filter
- Shadow ban filtering is rare (< 0.1% of users) — post-filter overhead is negligible
- Moderator visibility varies per-forum — too complex to pre-filter efficiently

### Permission-Group Hashing

```php
$readableForumIds = $this->authService->getGrantedForums($user, 'f_read');
sort($readableForumIds);
$permGroupHash = md5(implode(',', $readableForumIds));
```

Users with identical forum access share cache entries. In typical phpBB installations, 3-5 permission groups cover 95%+ of users (guests, registered, moderators, admins, custom groups).

---

## 9. Admin / Configuration

### Configuration Keys to Carry Forward

| Config Key | Default | Purpose | New Service Equivalent |
|-----------|---------|---------|----------------------|
| `load_search` | `1` | Global search kill switch | Keep as-is |
| `search_interval` | `0` | Flood protection (registered users) | Keep as-is |
| `search_anonymous_interval` | `0` | Flood protection (guests) | Keep as-is |
| `max_num_search_keywords` | `10` | Query complexity limit | Keep as-is |
| `min_search_author_chars` | `3` | Author search validation | Keep as-is |
| `default_search_return_chars` | `300` | Result snippet length | Keep as-is |
| `limit_search_load` | `0` | CPU load threshold | Keep (adapt to new metrics) |

### Configuration Keys to Redesign

| Legacy Key | Issue | New Approach |
|-----------|-------|-------------|
| `search_type` (class FQCN) | Tight coupling to class name | Service tag ID: `'native'`, `'mysql'`, `'postgres'` |
| `search_store_results` (1800s) | Single TTL for all backends | Per-cache-pool TTL, configurable |
| `search_block_size` (250) | Internal pagination detail | Move to backend config, not global |
| `search_gc` / `search_last_gc` | Cron-style GC | Replace with cache TTL + optimize() schedule |
| `search_indexing_state` | Comma-separated state in config | Dedicated rebuild state table or cache entry |
| `fulltext_native_*` | Backend-specific in global config | Nested under backend config namespace |

### New Configuration to Add

| Config | Purpose |
|--------|---------|
| `search_cache_ttl` | Result cache TTL (default: 300s) |
| `search_permission_cache_ttl` | Permission group cache TTL (default: 600s) |
| `search_rebuild_batch_size` | Configurable batch size for index rebuild (default: 200) |
| `search_relevance_enabled` | Enable/disable TF-IDF scoring in native backend |
| `search_max_wildcard_terms` | Max wildcard terms per query (default: 1) |

### Index Rebuild Process

The legacy rebuild is a resumable state machine with `meta_refresh(1)` for long-running operations. The new service should preserve this pattern:

1. **State storage**: Dedicated cache entry or DB row (not config table)
2. **Batch size**: Configurable (default 200, up from legacy's 100)
3. **Progress API**: Return `{processed, total, percentage, rate}` for frontend
4. **Resumability**: Store `lastProcessedPostId` for crash recovery
5. **Cancellation**: Admin can cancel via flag check between batches
6. **Memory management**: `gc_collect_cycles()` between batches

---

## 10. Decision Areas

### Decision 1: Backend Interface Granularity

| Alternative | Description | Pros | Cons |
|-------------|-------------|------|------|
| **A. Fat interface** | Single `SearchBackendInterface` with all methods | Simple discovery; one type to check | Backends must implement no-op stubs for unsupported operations |
| **B. Capability-based (ISP)** | Separate `SearcherInterface`, `IndexerInterface`, `IndexAdminInterface` | Clean separation; backends only implement what they support | Orchestrator must check `instanceof` for each operation |
| **C. Fat + Capabilities DTO** | Single interface + `BackendCapabilities` declarations | One interface; capability-aware orchestrator; degradation via capability check | Slight redundancy (method exists but capability says false) |

**Recommendation**: **C** — pragmatic balance. The capabilities DTO (Literature §5.2) handles UI adaptation and graceful degradation, while the single interface keeps backend registration simple.

### Decision 2: Indexing Strategy

| Alternative | Description | Pros | Cons |
|-------------|-------------|------|------|
| **A. Synchronous** | Index in same request as post save | Immediate consistency; no infrastructure | Adds latency to post creation |
| **B. Async (queue)** | Queue event, worker indexes | Zero latency on post save | Requires queue infrastructure; eventual consistency |
| **C. Hybrid** | Sync for single-post, async for bulk operations | Best of both for typical usage | Two code paths to maintain |
| **D. Delta batch** | Periodic re-index of new/changed posts | Simple; used by Sphinx | High latency for new content in search |

**Recommendation**: **A** (synchronous) for phpBB's deployment context. The latency (5–50ms for native word extraction + INSERT) is acceptable. Async can be added as opt-in later for high-traffic installations. Bulk rebuild uses batch processing (already designed).

### Decision 3: Query Parser Complexity

| Alternative | Description | Pros | Cons |
|-------------|-------------|------|------|
| **A. Legacy-compatible only** | Support `+`, `-`, `|`, `()`, `*` — same as today | Zero learning curve; proven | No phrase search; limited |
| **B. Extended syntax** | Add `"phrase"`, field specifiers (`title:word`), proximity | More powerful; matches user expectations from Google | More complex parser; potential confusion |
| **C. Two modes** | Simple mode (legacy) + advanced mode (extended) | Backward compatible + powerful | UI complexity; two parsers |

**Recommendation**: **A initially, evolve to B**. Start with legacy-compatible operators to ensure no regression. Phrase search (`"..."`) is the most requested extension and simple to add. Field specifiers and proximity can come later.

### Decision 4: Result Caching Model

| Alternative | Description | Pros | Cons |
|-------------|-------------|------|------|
| **A. Per-permission-set (legacy)** | ex_fid_ary baked into cache key | Exact results; no post-filter | Low hit rate; high memory |
| **B. Shared + post-filter** | Cache raw results; filter per-request | Maximum hit rate; low memory | Over-fetch needed; re-filter on every hit |
| **C. Permission-group hash** | Group users by identical permissions; shared within group | Good hit rate; accurate pagination | Must compute group hash; invalidate on perm change |
| **D. Two-layer** | Layer 1: raw results (short TTL); Layer 2: per-group filtered (longer TTL) | Best hit rate for repeated queries | Complexity of two cache layers |

**Recommendation**: **C** — permission-group hash offers the best pragmatic balance. Most phpBB installations have 3-5 effective permission levels, meaning cache entries are shared across large user segments without requiring post-filter overhead.

### Decision 5: Permission Check Timing

| Alternative | Description | Pros | Cons |
|-------------|-------------|------|------|
| **A. Pure pre-filter** | WHERE forum_id IN (...) only | Pagination accurate; simple | Can't handle per-post visibility elegantly |
| **B. Pure post-filter** | Search all, filter after | Best cache sharing | Inaccurate pagination; wasted I/O |
| **C. Hybrid** | Forum-level pre-filter + visibility/shadow post-filter | Accurate for major access control; handles edge cases | Slightly more complex |
| **D. Materialized permissions** | Store per-post "readable_by" in index | Fastest queries; exact results | Index bloat; complex invalidation on perm change |

**Recommendation**: **C** (hybrid). Forum-level pre-filter handles 95%+ of access control efficiently. Post-filter for shadow bans and moderator visibility handles the remaining edge cases without significant overhead.

### Decision 6: Native Backend Schema Evolution

| Alternative | Description | Pros | Cons |
|-------------|-------------|------|------|
| **A. Keep 3-table design** | wordlist + wordmatch + results (same schema) | Proven; minimal risk | Inherits scalability issues (N-way JOIN) |
| **B. Evolve schema** | Add `frequency` column, `forum_id` to wordmatch, index on search_time | Better ranking (TF-IDF); faster forum scoping | Migration needed from legacy |
| **C. Redesign completely** | New schema (e.g., JSONB posting lists, partitioned tables) | Potential for major improvements | High risk; unproven; may not outperform B |

**Recommendation**: **B** (evolve). Adding `frequency` (term frequency per doc) enables TF-IDF ranking. Adding `forum_id` to wordmatch enables forum-scoped queries without JOINing posts table. These are low-risk, high-impact improvements. Complete redesign is unnecessary complexity.

### Decision 7: Search Result Format

| Alternative | Description | Pros | Cons |
|-------------|-------------|------|------|
| **A. IDs only** | Return `int[]` of post/topic IDs | Minimal coupling; controller handles hydration | Extra round-trip to fetch display data |
| **B. Enriched DTOs** | Return DTOs with title, snippet, author, date | Single call for display | Search service needs post content access; coupling |
| **C. IDs + metadata** | IDs + score + matched field info | Enough for ranking/highlighting; lightweight | Slightly more complex than pure IDs |

**Recommendation**: **A** (IDs only) as the primary contract. The search service should remain focused on finding matching content, not rendering it. Enrichment (titles, snippets, author display) is the controller's concern, using `ThreadsService` and `UserDisplayService`. The `SearchResult` DTO includes `ids[]`, `totalCount`, `page`, `perPage`, and `executionTimeMs` — sufficient for the consumer.

---

## 11. Confidence Assessment

| Area | Confidence | Rationale |
|------|-----------|-----------|
| Backend contract design | **High** (90%) | 4 backends analyzed exhaustively; common surface is clear; well-established Strategy pattern |
| Tokenization pipeline | **High** (85%) | Industry-standard pattern (Elasticsearch model); CJK handling verified against legacy |
| Query parser (AST) | **High** (85%) | Operator set well-defined from 4 backends; recursive descent is textbook approach |
| Event-driven indexing | **High** (90%) | 8 events fully specified with payloads; synchronous dispatch matches deployment context |
| Result caching strategy | **Medium-High** (75%) | Permission-group hashing is sound in theory; hit rate improvement unverified without real data |
| Permission filtering model | **High** (85%) | Pre-filter at forum level validated across all legacy backends; hybrid approach is well-established |
| Native schema evolution | **Medium** (70%) | Adding frequency/forum_id is low-risk but TF-IDF ranking effectiveness untested for phpBB content |
| Sphinx support strategy | **Medium-Low** (50%) | Architecturally distinct; unclear if investment is justified; no data on Sphinx adoption rate |
| Relevance ranking | **Medium** (65%) | TF-IDF is sound for native backend but phpBB has never had ranking — user expectations unknown |
| Admin rebuild/management | **High** (90%) | Legacy state machine fully analyzed; resumable batch pattern is proven |

---

## 12. Risk Analysis

| Risk | Probability | Impact | Mitigation |
|------|------------|--------|-----------|
| **Native backend performance degradation at scale** | Medium | High | Benchmark early with 100K+ posts; set performance budget; index on (word_id, forum_id) as escape hatch |
| **Cache invalidation too aggressive** (flush all on any change) | Medium | Medium | Start with full-flush; add word-level tag granularity if cache hit rate is unacceptable |
| **Query parser edge cases** (nested OR+NOT, Unicode operators) | Medium | Low | Comprehensive test suite covering all legacy operator combinations; graceful degradation fallback |
| **Permission-group hash invalidation lag** | Low | Medium | Short TTL on permission cache (60-600s); honor `PermissionsClearedEvent` |
| **CJK tokenization regression** | Low | High (for CJK forums) | Port legacy CJK character ranges into `CjkBigramFilter`; test against known CJK content |
| **Sphinx backend incompatibility** | Medium | Low | Defer Sphinx support; document as optional extension; keep interface compatible |
| **Index rebuild time for large forums** | Medium | Medium | Configurable batch size; progress API; resumable state; memory management with gc_collect_cycles() |
| **Breaking change in search syntax** | Low | Medium | Start with legacy-compatible operators (Decision 3A); extend gradually |
| **Over-engineering the interface** | Medium | Low | Start with fat interface + capabilities (Decision 1C); refactor to ISP only if needed |
| **Synchronous indexing latency spike** | Low | Low | Monitor indexing time per post; add circuit breaker if > 100ms; queue as future opt-in |

---

## Appendices

### A. Complete Source List

| Source | File | Key Content |
|--------|------|-------------|
| Legacy backends | `analysis/findings/legacy-backends-analysis.md` | 4 backend implementations, 15-method contract, operator spec, 23 event hooks |
| Database schema | `analysis/findings/database-schema-analysis.md` | 3 table DDL, backend-table matrix, SQL patterns, cache schema |
| Cross-service contracts | `analysis/findings/cross-service-integration-contracts.md` | 8 consumed events, 2 dispatched events, 5 service deps, permission/shadow ban models |
| Literature review | `analysis/findings/patterns-literature-review.md` | Tokenization pipeline, inverted index, query parsing, caching, plugin patterns |
| Admin configuration | `analysis/findings/admin-configuration-analysis.md` | 30 config keys, ACP operations, rebuild state machine, flood protection |

### B. Gaps and Uncertainties

1. No performance benchmarks exist for the native backend at scale (>100K posts with TF-IDF)
2. Real-world permission-group distribution unknown (affects cache hit rate projections)
3. Sphinx adoption rate among phpBB installations unknown (affects priority of Sphinx support)
4. CJK bigram tokenization edge cases not exhaustively tested
5. Multi-database compatibility for native backend schema changes (MySQL vs PostgreSQL DDL)
6. Event payload inconsistency: some events carry text, others require repository fetch

### C. Config Key Full Inventory

30 configuration keys identified across global settings (14), native backend (4), MySQL backend (2), PostgreSQL backend (3), Sphinx backend (5), related non-search (2). See Admin Configuration findings for complete details.

### D. Legacy Extension Events

23 `core.search_*` events identified across 4 backends. These will NOT be replicated 1:1. Instead, 4-6 orchestrator-level events will provide extension points:
- `PreSearchEvent` — before search execution (modify query, add filters)
- `PostSearchEvent` — after search execution (modify results, add metrics)
- `PreIndexEvent` — before document indexing (modify content, skip indexing)
- `PostIndexEvent` — after document indexing (trigger side effects)
- `PreRebuildEvent` — before full rebuild (prepare resources)
- `PostRebuildEvent` — after full rebuild (cleanup, notification)
