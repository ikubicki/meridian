# High-Level Design: `phpbb\search` Service

## Design Overview

**Business context**: phpBB's search subsystem — pluggable backends, word indexing, result caching, query parsing — is currently spread across ~4,000 LOC of implicit-contract code with 4 backends sharing 15 undocumented methods, per-backend query parsing, monolithic tokenization, and fragmented caching that bakes permissions into cache keys. The search system powers the forum's primary content-discovery path. The `phpbb\search` service replaces this with a clean, testable orchestrator + pluggable backend architecture with complete isolation from legacy code.

**Chosen approach**: An **orchestrator + ISP-segregated backends** architecture where `SearchOrchestrator` owns all cross-cutting concerns (parsing, caching, permissions, rate limiting, shadow ban filtering) and delegates only index read/write to backends through **4 separate interfaces** (`SearcherInterface`, `IndexerInterface`, `IndexAdminInterface`, `BackendInfoInterface`). Query parsing uses a **full AST with recursive descent** producing typed nodes that backend-specific translators convert to native query formats. Indexing is **synchronous by default** with a strategy seam (`IndexingStrategyInterface`) for future async opt-in. Result caching uses **permission-group hashing** — users with identical forum access share cache entries. The native backend **preserves the legacy 3-table schema** (wordlist, wordmatch, results) without column additions, but the code architecture accommodates future additive evolution.

**Key decisions:**
- **Interface Segregation (ISP)** — 4 separate interfaces per capability; orchestrator uses `instanceof` checks before delegating (ADR-001)
- **Sync-Default + Async Opt-In** — `IndexingStrategyInterface` seam with `SynchronousStrategy` default and `AsynchronousStrategy` stub (ADR-002)
- **Full AST with Recursive Descent** — typed node tree (`TermNode`, `PhraseNode`, `BooleanNode`, `PrefixNode`, `NotNode`) with per-backend translators (ADR-003)
- **Permission-Group Hash caching** — `md5(sorted forum IDs)` as cache key segment; 3-5 groups cover 95%+ of users (ADR-004)
- **Hybrid permissions** — forum-level pre-filter in WHERE clause + post-filter for shadow bans and moderator visibility edge cases (ADR-005)
- **Preserve Legacy Schema** — no `frequency` or `forum_id` columns now; code structured for additive evolution later (ADR-006)
- **IDs Only result format** — `SearchResult` returns `int[]` IDs + metadata; hydration is the controller's responsibility (ADR-007)

---

## Architecture

### System Context (C4 Level 1)

```
                            ┌──────────────────┐
                            │   End User       │
                            │   (Web / API)    │
                            └────────┬─────────┘
                                     │ HTTP
                                     ▼
                            ┌──────────────────┐
                            │  phpBB App Layer │
                            │  Controllers/API │
                            │  (Auth middleware)│
                            └────────┬─────────┘
                                     │ PHP calls
                                     ▼
┌──────────────┐   ┌─────────────────────────────────┐   ┌──────────────┐
│ phpbb\auth   │──▸│       phpbb\search              │◂──│ phpbb\threads│
│ (forum ACL,  │   │       Service Layer             │   │ (8 domain    │
│  m_approve)  │   │                                 │   │  events)     │
└──────────────┘   └──────┬──────────────────────────┘   └──────────────┘
                          │           ▲
┌──────────────┐          │           │
│ phpbb\       │◂─────────┤           │
│ hierarchy    │          │    ┌──────┴──────────┐
│ (forum tree) │          │    │ phpbb\cache     │
└──────────────┘          │    │ (CachePool)     │
                          │    └─────────────────┘
┌──────────────┐          │
│ phpbb\user   │◂─────────┘
│ (shadow ban  │
│  service)    │
└──────────────┘
```

**Interactions:**
- **phpbb\threads → Search**: 8 domain events (`PostCreatedEvent`, `PostEditedEvent`, `PostSoftDeletedEvent`, `PostRestoredEvent`, `PostHardDeletedEvent`, `TopicDeletedEvent`, `VisibilityChangedEvent`, `TopicMovedEvent`) trigger indexing
- **Search → phpbb\auth**: `getGrantedForums($user, 'f_read')` for pre-filter; `isGranted($user, 'm_approve', $forumId)` for moderator visibility
- **Search → phpbb\hierarchy**: `getSubtree($forumId)` for forum-scoped searches resolving child forums
- **Search → phpbb\user**: `ShadowBanService::isShadowBanned($userId)` for post-filter
- **Search → phpbb\cache**: `TagAwareCacheInterface` pool `'search'` for result caching

### Container Overview (C4 Level 2)

```
┌──────────────────────────────────────────────────────────────────────────┐
│                           phpbb\search                                   │
│                                                                          │
│  ┌────────────────────────────────────────────────────────────────────┐  │
│  │                    SearchOrchestrator                               │  │
│  │                                                                    │  │
│  │  SearchQuery ──► RateLimiter ──► LoadChecker                       │  │
│  │       ──► QueryParser (→ AST) ──► PermissionResolver               │  │
│  │       ──► CacheLayer (check) ──► Backend::search()                 │  │
│  │       ──► PostFilter (shadow bans) ──► CacheLayer (store)          │  │
│  │       ──► SearchResult returned                                    │  │
│  └──┬──────────┬──────────┬──────────┬──────────┬────────────────────┘  │
│     │          │          │          │          │                        │
│  ┌──▼────┐ ┌──▼──────┐ ┌▼────────┐ ┌▼───────┐ ┌▼──────────────────┐   │
│  │Query  │ │Cache    │ │Indexing │ │Permis- │ │Event Listeners    │   │
│  │Parser │ │Layer    │ │Pipeline │ │sion    │ │(from Threads)     │   │
│  │(AST)  │ │(perm-   │ │         │ │Resolver│ │                   │   │
│  │       │ │group    │ │Strategy │ │(hybrid)│ │PostCreatedListener│   │
│  │       │ │hash)    │ │+ Tokeniz│ │        │ │PostEditedListener │   │
│  └───────┘ └─────────┘ └─────────┘ └────────┘ │TopicDeletedList.. │   │
│                                                 └───────────────────┘   │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │                   Backend Layer (ISP Interfaces)                  │   │
│  │                                                                   │   │
│  │  SearcherInterface ──── search methods                            │   │
│  │  IndexerInterface ───── index/remove methods                      │   │
│  │  IndexAdminInterface ── create/delete/rebuild index                │   │
│  │  BackendInfoInterface ─ name, availability, features              │   │
│  │                                                                   │   │
│  │  ┌──────────┐ ┌──────────┐ ┌────────────┐                        │   │
│  │  │ Native   │ │ MySQL    │ │ PostgreSQL │                        │   │
│  │  │ Backend  │ │ Backend  │ │ Backend    │                        │   │
│  │  │          │ │          │ │            │                        │   │
│  │  │ implements│ │implements│ │ implements │                        │   │
│  │  │ ALL 4    │ │Searcher  │ │ Searcher   │                        │   │
│  │  │ interfaces│ │+Info     │ │ +Info      │                        │   │
│  │  └──────────┘ └──────────┘ └────────────┘                        │   │
│  └──────────────────────────────────────────────────────────────────┘   │
│                                                                          │
│  ┌──────────────────────────────────────────────────────────────────┐   │
│  │                     Query Translators                             │   │
│  │  NativeQueryTranslator  │ MysqlQueryTranslator                    │   │
│  │  PostgresQueryTranslator                                          │   │
│  └──────────────────────────────────────────────────────────────────┘   │
└──────────────────────────────────────────────────────────────────────────┘
```

**Key container notes:**
- **Native Backend** implements all 4 interfaces (it has its own word index to manage)
- **MySQL/PostgreSQL Backends** implement `SearcherInterface` + `BackendInfoInterface` only (DB engine maintains FULLTEXT/GIN indexes — no `IndexerInterface` needed)
- **QueryParser** is shared across all backends; per-backend `QueryTranslatorInterface` converts AST to native format
- **IndexingPipeline** only invoked for backends that implement `IndexerInterface`

---

## Service Decomposition

```
src/phpbb/search/
├── SearchOrchestrator.php              # Central coordinator
├── SearchServiceInterface.php          # Public contract (searchPosts, searchTopics)
├── SearchAdminServiceInterface.php     # Admin contract (rebuild, stats, delete)
│
├── Backend/
│   ├── SearcherInterface.php           # search(CompiledQuery, SearchOptions): BackendSearchResult
│   ├── IndexerInterface.php            # indexPost(), removePost(), updateForumId()
│   ├── IndexAdminInterface.php         # createIndex(), deleteIndex(), rebuildIndex()
│   ├── BackendInfoInterface.php        # getName(), isAvailable(), getFeatures()
│   │
│   ├── Native/
│   │   ├── NativeBackend.php           # Implements all 4 interfaces
│   │   └── NativeQueryTranslator.php   # AST → SQL with word ID lookups
│   ├── Mysql/
│   │   ├── MysqlBackend.php            # Implements SearcherInterface + BackendInfoInterface
│   │   └── MysqlQueryTranslator.php    # AST → MATCH...AGAINST
│   └── Postgres/
│       ├── PostgresBackend.php         # Implements SearcherInterface + BackendInfoInterface
│       └── PostgresQueryTranslator.php # AST → to_tsquery
│
├── Query/
│   ├── QueryParser.php                 # Recursive descent parser
│   ├── QueryTranslatorInterface.php    # AST → backend-specific CompiledQuery
│   ├── Node/
│   │   ├── QueryNode.php              # Abstract base
│   │   ├── TermNode.php               # Single word (modifier: MUST/MUST_NOT/SHOULD)
│   │   ├── PhraseNode.php             # "quoted phrase"
│   │   ├── BooleanNode.php            # AND/OR grouping
│   │   ├── PrefixNode.php             # word* wildcard
│   │   └── NotNode.php                # Negation wrapper
│   └── CompiledQuery.php              # Backend-specific compiled query DTO
│
├── Indexing/
│   ├── IndexingStrategyInterface.php   # Strategy seam (sync/async)
│   ├── SynchronousIndexingStrategy.php # Default: direct call
│   ├── AsynchronousIndexingStrategy.php# Stub: queues for future
│   ├── SearchAnalyzer.php              # CharFilter → Tokenizer → TokenFilter pipeline
│   ├── CharFilter/
│   │   ├── CharacterFilterInterface.php
│   │   ├── BbcodeStripFilter.php
│   │   ├── HtmlEntityDecodeFilter.php
│   │   └── NfcNormalizerFilter.php
│   ├── Tokenizer/
│   │   ├── TokenizerInterface.php
│   │   └── UnicodeWordTokenizer.php
│   └── TokenFilter/
│       ├── TokenFilterInterface.php
│       ├── LowercaseFilter.php
│       ├── MinLengthFilter.php
│       ├── MaxLengthFilter.php
│       ├── CjkBigramFilter.php
│       └── StopWordFilter.php
│
├── Cache/
│   ├── SearchCacheLayer.php            # Cache check/store with perm-group hash
│   └── PermissionGroupHasher.php       # Computes md5(sorted forum IDs)
│
├── Permission/
│   ├── PermissionResolver.php          # Pre-filter: readable forums
│   └── PostFilterPipeline.php          # Post-filter: shadow bans, mod visibility
│
├── Dto/
│   ├── SearchQuery.php                 # User-facing query input
│   ├── SearchOptions.php               # Sort, pagination, type, filters
│   ├── SearchResult.php                # ids[], totalCount, page, perPage, executionTimeMs
│   ├── BackendSearchResult.php         # Backend raw result (ids[], totalCount)
│   ├── IndexableDocument.php           # Post data for indexing
│   ├── IndexStats.php                  # Index statistics for ACP
│   └── BackendFeatures.php            # Feature flags (phraseSearch, wildcard, etc.)
│
├── Event/
│   ├── PreSearchEvent.php              # Before search execution
│   ├── PostSearchEvent.php             # After search execution
│   ├── PreIndexEvent.php               # Before document indexing
│   ├── PostIndexEvent.php              # After document indexing
│   ├── SearchPerformedEvent.php        # Analytics/audit dispatch
│   └── IndexRebuiltEvent.php           # Admin notification dispatch
│
└── Listener/
    ├── PostCreatedListener.php         # Threads event → index
    ├── PostEditedListener.php          # Threads event → re-index
    ├── PostSoftDeletedListener.php     # Threads event → remove
    ├── PostRestoredListener.php        # Threads event → re-add
    ├── PostHardDeletedListener.php     # Threads event → remove permanent
    ├── TopicDeletedListener.php        # Threads event → batch remove
    ├── VisibilityChangedListener.php   # Threads event → add/remove
    └── TopicMovedListener.php          # Threads event → update forum_id
```

---

## Interface Definitions

### SearcherInterface

```php
namespace phpbb\search\Backend;

use phpbb\search\Dto\BackendSearchResult;
use phpbb\search\Dto\SearchOptions;
use phpbb\search\Query\CompiledQuery;

interface SearcherInterface
{
    /**
     * Execute a compiled search query for posts.
     *
     * @param CompiledQuery $query Backend-specific compiled query
     * @param SearchOptions $options Sort, pagination, forum filter, author filter
     * @return BackendSearchResult IDs and total count
     */
    public function searchPosts(CompiledQuery $query, SearchOptions $options): BackendSearchResult;

    /**
     * Execute a compiled search query for topics (first-post matching).
     */
    public function searchTopics(CompiledQuery $query, SearchOptions $options): BackendSearchResult;
}
```

### IndexerInterface

```php
namespace phpbb\search\Backend;

use phpbb\search\Dto\IndexableDocument;

interface IndexerInterface
{
    /**
     * Index a single post. Called synchronously on post create/edit.
     */
    public function indexPost(IndexableDocument $document): void;

    /**
     * Remove a single post from the index.
     */
    public function removePost(int $postId): void;

    /**
     * Remove multiple posts (batch). Called on topic delete.
     *
     * @param int[] $postIds
     */
    public function removePosts(array $postIds): void;

    /**
     * Update forum_id for all posts in a topic. Called on topic move.
     */
    public function updateForumId(int $topicId, int $newForumId): void;

    /**
     * Periodic maintenance: recalculate common-word flags, clean orphans.
     */
    public function optimize(): void;
}
```

### IndexAdminInterface

```php
namespace phpbb\search\Backend;

use phpbb\search\Dto\IndexStats;

interface IndexAdminInterface
{
    /**
     * Create the backend's index structures (tables, FULLTEXT indexes, etc.).
     */
    public function createIndex(): void;

    /**
     * Drop the backend's index structures.
     */
    public function deleteIndex(): void;

    /**
     * Check if index structures exist and are ready.
     */
    public function indexExists(): bool;

    /**
     * Rebuild entire index from provided document stream.
     *
     * @param iterable<IndexableDocument> $documents Stream of all indexable posts
     * @param callable|null $progress Progress callback: fn(int $processed, int $total): void
     */
    public function rebuildIndex(iterable $documents, ?callable $progress = null): void;

    /**
     * Return index statistics for ACP display.
     */
    public function getStats(): IndexStats;
}
```

### BackendInfoInterface

```php
namespace phpbb\search\Backend;

use phpbb\search\Dto\BackendFeatures;

interface BackendInfoInterface
{
    /**
     * Unique identifier for config/registration (e.g. 'native', 'mysql', 'postgres').
     */
    public function getName(): string;

    /**
     * Check prerequisites: DB engine support, extensions, connectivity.
     */
    public function isAvailable(): bool;

    /**
     * Declare supported features for orchestrator and UI adaptation.
     */
    public function getFeatures(): BackendFeatures;
}
```

### BackendFeatures DTO

```php
namespace phpbb\search\Dto;

final readonly class BackendFeatures
{
    public function __construct(
        public bool $phraseSearch = false,
        public bool $wildcardSearch = false,
        public bool $booleanOperators = true,
        public bool $relevanceRanking = false,
        public bool $realtimeIndexing = true,
        public int $minWordLength = 3,
        public int $maxWordLength = 84,
    ) {}
}
```

---

## SearchOrchestrator

The central service coordinating all search operations. Implements `SearchServiceInterface` and `SearchAdminServiceInterface`.

### Search Flow

```
SearchOrchestrator::searchPosts(SearchQuery $query, User $user): SearchResult
│
├── 1. Rate Limit Check
│   └── Check user_last_search against search_interval config
│       └── Reject with SearchFloodException if too soon
│
├── 2. Load Check
│   └── Check system load against limit_search_load config
│       └── Reject with SearchUnavailableException if overloaded
│
├── 3. Dispatch PreSearchEvent
│   └── Extensions can modify query before execution
│
├── 4. Parse Query
│   └── QueryParser::parse($query->keywords) → QueryNode AST
│       └── Validate: max keywords, non-empty after filtering, not negation-only
│
├── 5. Resolve Permissions (Pre-Filter)
│   └── PermissionResolver::resolve($user, $query->forumId)
│       ├── AuthorizationService::getGrantedForums($user, 'f_read') → int[]
│       ├── If forum-scoped: intersect with HierarchyService::getSubtree($forumId)
│       └── Return readable forum IDs
│
├── 6. Compute Cache Key
│   └── PermissionGroupHasher::hash($readableForumIds)
│       └── key = "search:{queryHash}:{permGroupHash}:{sort}:{page}"
│
├── 7. Check Cache
│   └── CacheLayer::get($key) → BackendSearchResult | null
│       └── HIT → skip to step 10
│
├── 8. Translate & Execute
│   ├── Resolve QueryTranslator for active backend
│   ├── translator->translate(AST, backend->getFeatures()) → CompiledQuery
│   │   └── Graceful degradation: PhraseNode → AND on native, PrefixNode → strip on PG
│   └── backend->searchPosts(compiledQuery, searchOptions) → BackendSearchResult
│
├── 9. Store in Cache
│   └── CacheLayer::set($key, $result, TTL, tags: ['search_results'])
│
├── 10. Post-Filter
│   ├── ShadowBanService::isShadowBanned() batch check on poster IDs
│   │   └── Remove shadow-banned posts (unless viewer is poster or mod/admin)
│   └── Moderator visibility expansion for unapproved/soft-deleted
│
├── 11. Dispatch PostSearchEvent
│   └── Extensions can modify/augment results
│
├── 12. Dispatch SearchPerformedEvent (analytics)
│   └── userId, query, resultCount, executionTimeMs
│
└── 13. Return SearchResult
    └── SearchResult { ids: int[], totalCount, page, perPage, executionTimeMs }
```

### Backend Delegation Pattern (instanceof)

```php
// Indexing — only if backend supports it
if ($this->backend instanceof IndexerInterface) {
    $this->indexingStrategy->index($this->backend, $document);
}

// Admin operations — only if backend supports it
if ($this->backend instanceof IndexAdminInterface) {
    $this->backend->rebuildIndex($documents, $progress);
}

// Search — all backends support this (it's the primary operation)
// $this->backend is always SearcherInterface (type-hinted in constructor)
$result = $this->backend->searchPosts($compiledQuery, $options);

// Feature-dependent behavior
$features = $this->backend->getFeatures(); // BackendInfoInterface
if (!$features->phraseSearch) {
    // Degrade PhraseNode to BooleanNode(AND) in translator
}
```

**Trade-off acknowledged**: ISP means `instanceof` checks at every delegation point. This is ~4 checks in the orchestrator (index, remove, admin, optimize). The benefit is compile-time clarity: MySQL/PG backends never implement `indexPost()` — there is no semantic dishonesty with no-op methods.

---

## Query Parser AST

### Node Types

| Node | Fields | Represents | Example |
|------|--------|-----------|---------|
| `TermNode` | `term: string`, `modifier: Modifier` | Single word | `+php`, `-java`, `framework` |
| `PhraseNode` | `phrase: string` | Quoted phrase | `"dependency injection"` |
| `BooleanNode` | `operator: AND\|OR`, `children: QueryNode[]` | Group | `(mysql\|postgres)` |
| `PrefixNode` | `prefix: string` | Trailing wildcard | `frame*` |
| `NotNode` | `child: QueryNode` | Negation wrapper | `-(a\|b)` |

`Modifier` enum: `MUST` (+), `MUST_NOT` (-), `SHOULD` (default).

### Parser Flow

```
Input string: "+php -java (mysql|postgres) framework*"
     │
     ▼
Lexer (tokenize into operator tokens + word tokens)
     │
     ▼
Recursive Descent Parser
     │
     ▼
AST:
  BooleanNode(AND)
  ├── TermNode("php", MUST)
  ├── TermNode("java", MUST_NOT)
  ├── BooleanNode(OR)
  │   ├── TermNode("mysql", SHOULD)
  │   └── TermNode("postgres", SHOULD)
  └── PrefixNode("framework")
```

### Query Normalization (for cache key)

Before hashing the AST for cache-key generation, the parser normalizes:
1. Sort children of AND/OR nodes alphabetically by term
2. Deduplicate identical terms within the same group
3. Flatten trivial nesting (single-child BooleanNode → its child)
4. Lowercase all terms

### Graceful Degradation in Translators

| Node | Native | MySQL | PostgreSQL |
|------|--------|-------|-----------|
| `PhraseNode` | Degrade → `BooleanNode(AND, [terms...])` | `"exact phrase"` | Degrade → `BooleanNode(AND, [terms...])` |
| `PrefixNode` | `LIKE 'word%'` on wordlist | `word*` | Strip wildcard, exact term |
| Nested `BooleanNode` | Flatten to 2-level max | Full support | Full support |

The translator checks `BackendFeatures` and transforms unsupported nodes before generating native query syntax. Degradation is logged for monitoring.

---

## Indexing Pipeline

### Event-Driven Architecture

```
Threads Domain Events                    Search Indexing Listeners
─────────────────────                    ────────────────────────
PostCreatedEvent        ──────────────►  PostCreatedListener
  { postId, topicId, forumId,              │
    posterId, subject, body,               ▼
    postTime, isFirstPost,             IndexingStrategy::index(backend, document)
    visibility }                           │
                                           ▼ (sync default)
PostEditedEvent         ──────────────►  SynchronousIndexingStrategy
  { postId, newSubject, newBody }          │
                                           ▼
PostSoftDeletedEvent    ──────────────►  SearchAnalyzer::analyze(text) → terms[]
  { postId }                               │
                                           ▼
PostRestoredEvent       ──────────────►  IndexerInterface::indexPost(document)
  { postId }                           (only if backend implements IndexerInterface)

PostHardDeletedEvent    ──────────────►  IndexerInterface::removePost(postId)
  { postId }

TopicDeletedEvent       ──────────────►  IndexerInterface::removePosts(postIds[])
  { topicId, postIds[] }

VisibilityChangedEvent  ──────────────►  index or remove based on new visibility
  { postIds[], newVisibility }

TopicMovedEvent         ──────────────►  IndexerInterface::updateForumId(topicId, newForumId)
  { topicId, newForumId }
```

### IndexingStrategyInterface

```php
namespace phpbb\search\Indexing;

use phpbb\search\Backend\IndexerInterface;
use phpbb\search\Dto\IndexableDocument;

interface IndexingStrategyInterface
{
    public function index(IndexerInterface $backend, IndexableDocument $document): void;
    public function remove(IndexerInterface $backend, int $postId): void;
    public function removeBatch(IndexerInterface $backend, array $postIds): void;
}
```

**SynchronousIndexingStrategy** (default): directly calls the backend methods.

**AsynchronousIndexingStrategy** (future stub): wraps the call in a queue message for deferred processing. Not implemented in initial release — the class exists as a hook/seam with a `throw new \LogicException('Async indexing not yet implemented')` body. The DI config toggle (`search_indexing_async: false`) determines which strategy is injected.

### Tokenization Pipeline

```
Raw Text → [CharacterFilters] → [Tokenizer] → [TokenFilters] → Terms[]

Default pipeline:
  CharFilter 1: BbcodeStripFilter     — remove [code]...[/code], BBCode tags, HTML
  CharFilter 2: HtmlEntityDecodeFilter — &amp; → &
  CharFilter 3: NfcNormalizerFilter    — Unicode NFC normalization

  Tokenizer:    UnicodeWordTokenizer   — split on UAX#29 word boundaries (IntlBreakIterator)

  TokenFilter 1: LowercaseFilter       — mb_strtolower()
  TokenFilter 2: MinLengthFilter       — drop tokens < config min (default 3, 1 for CJK)
  TokenFilter 3: MaxLengthFilter       — drop tokens > config max (default 84)
  TokenFilter 4: CjkBigramFilter       — split CJK sequences into bigrams
  TokenFilter 5: StopWordFilter        — remove language-specific stop words (optional)
```

The same `SearchAnalyzer` instance processes both indexing input and query terms — ensuring consistency.

### Native Backend Indexing (Differential)

On post create:
1. `analyzer.analyze(subject)` → subject terms
2. `analyzer.analyze(body)` → body terms
3. Look up existing word IDs in `search_wordlist`
4. INSERT new words (if not exists)
5. INSERT word↔post links in `search_wordmatch` (with `title_match` flag)
6. UPDATE `word_count` (+1) in `search_wordlist`

On post edit (differential):
1. Compute new terms from edited content
2. Query existing word associations for the post
3. Diff: `new_words = new_terms - existing`; `removed_words = existing - new_terms`
4. INSERT new associations, DELETE removed associations
5. UPDATE `word_count` accordingly

On MySQL/PG: listeners check `$backend instanceof IndexerInterface` and skip if false. Cache invalidation still happens via cache layer tag flush.

---

## Caching Layer

### Cache Key Structure

```
search:{queryHash}:{permGroupHash}:{pageKey}
```

- `queryHash` = `md5(serialize(normalizedAst) . '#' . sortKey . '#' . sortDir . '#' . sortDays)`
- `permGroupHash` = `md5(implode(',', sort($readableForumIds)))`
- `pageKey` = `{offset}:{limit}`

### Permission-Group Hash

```php
$readableForumIds = $this->authService->getGrantedForums($user, 'f_read');
sort($readableForumIds);
$permGroupHash = md5(implode(',', $readableForumIds));
```

Users with identical forum access share cache entries. In typical phpBB installations, 3-5 effective permission groups (guest, registered, moderator, admin, custom) cover 95%+ of users.

### Invalidation Strategy

| Trigger | Action | Scope |
|---------|--------|-------|
| Post created/edited/deleted | Flush tag `search_results` | All cached search results |
| Post visibility changed | Flush tag `search_results` | All cached search results |
| Topic moved | Flush tag `search_results` | All cached search results |
| Permissions changed | Flush tag `search_results` | All (permission hash may have changed) |
| TTL expiry | Auto-eviction | Per-entry |
| Admin: clear search cache | Flush pool namespace `search` | Everything |

**Trade-off accepted**: Flushing all `search_results` on any content change is aggressive but simple. Under heavy posting (10+ posts/minute), cache hit rate will be low. Word-level tag granularity (linking cache entries to specific terms) can be added as a future optimization without changing the public API.

### Cache Configuration

| Config Key | Default | Purpose |
|-----------|---------|---------|
| `search_cache_ttl` | `300` | Result cache TTL in seconds |
| `search_perm_cache_ttl` | `600` | Permission-group hash cache TTL |

---

## Event Catalog

### Events Consumed (from phpbb\threads)

| Event | Listener | Action |
|-------|----------|--------|
| `PostCreatedEvent` | `PostCreatedListener` | Index if visibility = Approved |
| `PostEditedEvent` | `PostEditedListener` | Re-index (differential for native) |
| `PostSoftDeletedEvent` | `PostSoftDeletedListener` | Remove from index |
| `PostRestoredEvent` | `PostRestoredListener` | Re-add to index (fetch content from repo) |
| `PostHardDeletedEvent` | `PostHardDeletedListener` | Permanently remove from index |
| `TopicDeletedEvent` | `TopicDeletedListener` | Batch remove all post IDs |
| `VisibilityChangedEvent` | `VisibilityChangedListener` | Add or remove based on new visibility |
| `TopicMovedEvent` | `TopicMovedListener` | Update forum_id in index entries |

### Events Dispatched

| Event | Payload | Consumers |
|-------|---------|-----------|
| `PreSearchEvent` | `query`, `user`, mutable `options` | Extensions (modify query, add filters) |
| `PostSearchEvent` | `query`, `user`, mutable `result` | Extensions (modify results, add metrics) |
| `PreIndexEvent` | mutable `document` | Extensions (modify content, skip indexing) |
| `PostIndexEvent` | `document`, `backend` | Extensions (trigger side effects) |
| `SearchPerformedEvent` | `userId`, `query`, `resultCount`, `executionTimeMs`, `searchType` | Analytics, audit |
| `IndexRebuiltEvent` | `totalPostsIndexed`, `durationSeconds`, `triggeredByUserId` | Admin notification |

---

## REST API Endpoints

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| `GET` | `/api/search` | Search posts or topics | Required (or guest if enabled) |
| `GET` | `/api/search/author/{username}` | Search by author name | Required |
| `POST` | `/api/admin/search/rebuild` | Trigger index rebuild | Admin only |
| `GET` | `/api/admin/search/stats` | Get index statistics | Admin only |
| `DELETE` | `/api/admin/search/index` | Delete search index | Admin only |
| `GET` | `/api/admin/search/rebuild/status` | Check rebuild progress | Admin only |

### Search Query Parameters

| Param | Type | Default | Description |
|-------|------|---------|-------------|
| `keywords` | string | required | Search query string |
| `type` | string | `posts` | `posts` or `topics` |
| `forum_id` | int | null | Restrict to forum (+ children) |
| `author` | string | null | Filter by author name |
| `author_id` | int | null | Filter by author ID |
| `sort_by` | string | `time` | `time`, `author`, `forum`, `subject` |
| `sort_dir` | string | `desc` | `asc` or `desc` |
| `days` | int | null | Restrict to last N days |
| `page` | int | `1` | Page number |
| `per_page` | int | `25` | Results per page (max 50) |

### Response Format

```json
{
  "data": {
    "ids": [1042, 1038, 1035, 1029],
    "total_count": 127,
    "page": 1,
    "per_page": 25,
    "execution_time_ms": 14.2
  }
}
```

The controller hydrates IDs via `ThreadsService` and `UserDisplayService` for the final rendered response.

---

## Extension Points

### Decorated Operations

The `SearchOrchestrator` supports the project's `DecoratorPipeline` pattern for:
- **Pre/Post Search** — `PreSearchEvent` / `PostSearchEvent` allow extensions to modify query or results
- **Pre/Post Index** — `PreIndexEvent` / `PostIndexEvent` allow extensions to modify documents or trigger side effects

### Event-Only Operations

- `SearchPerformedEvent` — analytics/audit hooks (read-only consumption)
- `IndexRebuiltEvent` — admin notification hooks (read-only consumption)

### Backend Registration

Backends are registered as tagged Symfony services:

```yaml
services:
    phpbb.search.backend.native:
        class: phpbb\search\Backend\Native\NativeBackend
        tags: ['phpbb.search.backend']

    phpbb.search.backend.mysql:
        class: phpbb\search\Backend\Mysql\MysqlBackend
        tags: ['phpbb.search.backend']
```

The `SearchOrchestrator` receives the active backend via DI based on config `search_backend: native`.

---

## Cross-Service Contracts

### What Search Imports

| Service | Interface/Method | Purpose | Call Pattern |
|---------|-----------------|---------|-------------|
| `phpbb\auth` | `AuthorizationService::getGrantedForums($user, string $permission)` | Readable forum IDs for pre-filter | Every search query |
| `phpbb\auth` | `AuthorizationService::isGranted($user, 'm_approve', int $forumId)` | Moderator visibility check | When visibility post-filter needed |
| `phpbb\hierarchy` | `HierarchyService::getSubtree(int $forumId)` | Resolve child forums for scoped search | Forum-scoped searches |
| `phpbb\user` | `ShadowBanService::isShadowBanned(int $userId)` | Shadow ban check for post-filter | Every search query (batch) |
| `phpbb\cache` | `TagAwareCacheInterface` (pool: `'search'`) | Result caching with tag invalidation | Every search query |
| `phpbb\threads` | Domain events (8 event types) | Indexing triggers | On every content mutation |

### What Search Exposes

```php
// Public contract for controllers and other services
interface SearchServiceInterface
{
    public function searchPosts(SearchQuery $query, User $user): SearchResult;
    public function searchTopics(SearchQuery $query, User $user): SearchResult;
}

// Admin contract for ACP and admin API
interface SearchAdminServiceInterface
{
    public function rebuildIndex(?callable $progress = null): IndexRebuiltEvent;
    public function getIndexStats(): IndexStats;
    public function deleteIndex(): void;
    public function getActiveBackendName(): string;
}
```

---

## Database Schema

### Tables Owned by Search (Legacy Schema Preserved)

```sql
-- Word vocabulary (native backend only)
CREATE TABLE phpbb_search_wordlist (
    word_id     INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    word_text   VARCHAR(255)    NOT NULL DEFAULT '',
    word_common TINYINT(1)      UNSIGNED NOT NULL DEFAULT 0,
    word_count  MEDIUMINT(8)    UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (word_id),
    UNIQUE KEY (word_text)
);

-- Word↔Post associations (native backend only)
CREATE TABLE phpbb_search_wordmatch (
    post_id     INT UNSIGNED    NOT NULL DEFAULT 0,
    word_id     INT UNSIGNED    NOT NULL DEFAULT 0,
    title_match TINYINT(1)      UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY unq_mtch (word_id, post_id, title_match),
    KEY post_id (post_id)
);

-- Legacy cached results (replaced by cache pool, table retained for migration)
CREATE TABLE phpbb_search_results (
    search_key      VARCHAR(32)     NOT NULL DEFAULT '',
    search_time     INT UNSIGNED    NOT NULL DEFAULT 0,
    search_keywords MEDIUMTEXT      NOT NULL,
    search_authors  MEDIUMTEXT      NOT NULL,
    PRIMARY KEY (search_key)
);
```

**Schema notes:**
- `search_wordlist` and `search_wordmatch` are only used by the native backend. MySQL/PG backends use DB engine-managed FULLTEXT/GIN indexes.
- `search_results` table is superseded by the `phpbb\cache` pool. The table is retained during migration but no longer written to. Can be dropped after migration period.
- No `frequency` or `forum_id` columns are added now. The code architecture (interface segregation, DTO-based indexing) does not preclude adding these columns later as additive ALTER TABLE changes without interface-breaking modifications.

---

## Concrete Examples

### Example 1: Search Flow (Cache Miss)

**Given** a registered user "alice" with read access to forums [1, 2, 3, 5] (same as all registered users), searching for `+php -java framework`

**When** `SearchOrchestrator::searchPosts()` is called:

1. Rate limiter checks `alice.user_last_search` — OK (last search was 5s ago, interval is 0)
2. Load checker verifies system load — OK (load = 0.3, limit = 0)
3. `PreSearchEvent` dispatched — no listeners modify query
4. `QueryParser::parse("+php -java framework")` produces AST:
   ```
   BooleanNode(AND)
   ├── TermNode("php", MUST)
   ├── TermNode("java", MUST_NOT)
   └── TermNode("framework", SHOULD)
   ```
5. `PermissionResolver` gets `[1, 2, 3, 5]` from auth service
6. `permGroupHash = md5("1,2,3,5")` = `"a3f2..."`
7. Cache lookup: `search:b7c1...:a3f2...:0:25` → MISS
8. `NativeQueryTranslator::translate(AST)` → SQL with word_id lookups + INNER JOIN
9. `NativeBackend::searchPosts()` → `BackendSearchResult { ids: [1042, 1038, ...], totalCount: 127 }`
10. Cache store with TTL=300s, tag=`search_results`
11. Post-filter: batch check poster IDs for shadow bans — none removed
12. `PostSearchEvent` dispatched
13. `SearchPerformedEvent` dispatched (analytics)

**Then** return `SearchResult { ids: [1042, 1038, ...], totalCount: 127, page: 1, perPage: 25, executionTimeMs: 18.4 }`

### Example 2: Indexing Flow (Post Created)

**Given** user "bob" creates a new post in forum 2, topic 50

**When** `PostCreatedEvent` is dispatched by Threads service:

1. `PostCreatedListener` receives event with `{ postId: 2001, topicId: 50, forumId: 2, posterId: 42, subject: "PHP 8.2 features", body: "Readonly classes are amazing...", visibility: APPROVED }`
2. Listener checks visibility = APPROVED → proceed
3. Builds `IndexableDocument` from event payload
4. Dispatches `PreIndexEvent` — no listeners modify document
5. Listener calls `IndexingStrategy::index(backend, document)`
6. `SynchronousIndexingStrategy` calls `backend->indexPost(document)` directly (backend implements `IndexerInterface`)
7. `NativeBackend::indexPost()`:
   - `analyzer.analyze("PHP 8.2 features")` → `["php", "features"]` (8.2 filtered by length)
   - `analyzer.analyze("Readonly classes are amazing...")` → `["readonly", "classes", "amazing", ...]`
   - Look up word IDs, INSERT new words, INSERT wordmatch rows
8. Dispatches `PostIndexEvent`
9. Cache tag `search_results` flushed

**Then** the post is immediately searchable for `php features`.

### Example 3: Cache Hit Flow

**Given** user "charlie" has the same forum permissions as "alice" (both registered users, forums [1, 2, 3, 5]) and searches for the same query `+php -java framework` 30 seconds after alice

**When** `SearchOrchestrator::searchPosts()` is called:

1. Rate limiter, load checker — OK
2. AST parsed and normalized identically (same query)
3. `permGroupHash = md5("1,2,3,5")` = `"a3f2..."` — same hash as alice
4. Cache lookup: `search:b7c1...:a3f2...:0:25` → **HIT** (stored 30s ago, TTL=300s)
5. Post-filter: shadow ban check on cached result IDs — none removed
6. `SearchPerformedEvent` dispatched

**Then** return cached `SearchResult` — no backend query executed. Cache sharing works because alice and charlie have identical `permGroupHash`.

---

## Out of Scope

| Item | Rationale |
|------|-----------|
| **Elasticsearch/Meilisearch backend** | External search engine backends follow the same ISP interface pattern. Once the 3 DB backends prove the interface, adding external engines is straightforward. Not needed initially. |
| **Sphinx backend** | Architecturally distinct (external daemon, delta indexing, no caching). Should be a separate adapter package, not a first-class backend in initial design. The ISP interfaces accommodate Sphinx (`SearcherInterface` + `BackendInfoInterface` only). |
| **Relevance ranking / TF-IDF** | Requires `frequency` column in `search_wordmatch` (Decision 6: preserve legacy schema). The IDs-only result format can evolve to IDs+scores when schema evolves. |
| **Per-forum shadow ban awareness** | Current `ShadowBanService::isShadowBanned()` is global. Per-forum shadow bans would require a different post-filter contract. Out of scope. |
| **Faceted search** | Filter-by-forum, filter-by-author as facets with counts. Useful but extends the UI significantly. The backend interface can support it later (add facet aggregation to `BackendSearchResult`). |
| **Auto-suggestions / search-as-you-type** | Different query path (prefix-heavy, sub-50ms latency). Can be built as a separate endpoint using same backend interface. |
| **Custom field indexing** | Extending `IndexableDocument` with profile fields or custom BBCode attributes. Pipeline supports it but scope creep for initial design. |
| **Word-level cache tag invalidation** | Linking cache entries to specific search terms for surgical invalidation instead of flush-all. A future optimization if cache hit rate is unacceptable under heavy posting. |
| **Async indexing implementation** | The `AsynchronousIndexingStrategy` stub exists as a seam. Actual queue/worker implementation deferred until a deployment target with queue infrastructure is validated. |

---

## Success Criteria

| # | Criterion | Measurement |
|---|-----------|-------------|
| 1 | All 3 backends (native, MySQL, PG) pass the same search test suite | Automated tests with backend-parameterized data providers |
| 2 | Native backend indexes a post in < 50ms (100-word post) | Performance benchmark test |
| 3 | Cache hit rate > 60% for typical forum traffic patterns (5 permission groups) | Metric from `SearchPerformedEvent` tracking cache hit/miss |
| 4 | Query parser handles all legacy operators (`+`, `-`, `|`, `()`, `*`) plus `"phrase"` | Parser test suite covering all operator combinations |
| 5 | Shadow ban post-filter correctly hides shadow-banned users' posts from non-admin searchers | Integration test with shadow-banned user fixture |
| 6 | Index rebuild completes for 100K posts within 10 minutes with < 128MB memory | Performance benchmark with batch size tuning |
| 7 | Zero regression in search syntax — existing queries produce same results as legacy | Legacy query compatibility test suite |
| 8 | Backend swap via config change requires zero code changes | Integration test: swap `search_backend` config, run same test suite |

---

## Design Decisions Summary

| ADR | Decision | Link |
|-----|----------|------|
| ADR-001 | Interface Segregation (ISP) for backend interfaces | [decision-log.md#ADR-001](decision-log.md#adr-001-backend-interface-granularity) |
| ADR-002 | Sync-Default + Async Opt-In indexing strategy | [decision-log.md#ADR-002](decision-log.md#adr-002-indexing-strategy) |
| ADR-003 | Full AST with Recursive Descent query parser | [decision-log.md#ADR-003](decision-log.md#adr-003-query-parser-complexity) |
| ADR-004 | Permission-Group Hash caching model | [decision-log.md#ADR-004](decision-log.md#adr-004-result-caching-model) |
| ADR-005 | Hybrid permission timing (forum pre-filter + edge post-filter) | [decision-log.md#ADR-005](decision-log.md#adr-005-permission-check-timing) |
| ADR-006 | Preserve Legacy Schema for native backend | [decision-log.md#ADR-006](decision-log.md#adr-006-native-backend-schema-evolution) |
| ADR-007 | IDs Only result format | [decision-log.md#ADR-007](decision-log.md#adr-007-search-result-format) |
