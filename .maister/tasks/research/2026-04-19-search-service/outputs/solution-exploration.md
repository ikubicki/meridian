# Solution Exploration: Search Service Design

## Problem Reframing

### Research Question

How should the new `phpbb\search` service be designed to implement legacy search mechanisms (pluggable backends, word indexing, result caching, query parsing) from scratch with complete isolation from the old solution?

### How Might We Questions

1. **HMW define a backend contract** that accommodates fundamentally different backend types (native inverted index, DB engine FULLTEXT, external daemon) without forcing backends to stub out unsupported operations?
2. **HMW keep the search index fresh** without adding latency to the post-save path or requiring infrastructure (queues, workers) that shared hosting deployments lack?
3. **HMW parse search queries** into a backend-agnostic representation that preserves legacy operator compatibility while enabling future extensions (phrases, field scoping)?
4. **HMW cache search results** effectively when forum permissions create a combinatorial explosion of cache keys that fragment hit rates?
5. **HMW enforce forum permissions** on search results without wasteful I/O on unauthorized content while also handling rare edge cases (shadow bans, moderator visibility)?
6. **HMW evolve the native backend's inverted index** to support relevance ranking and forum-scoped queries without a risky full schema redesign?
7. **HMW define the search result contract** so the search service stays focused (finding IDs) without coupling it to post content rendering?

---

## Decision Area 1: Backend Interface Granularity

### Context

The legacy system has 4 backends (native, MySQL, PostgreSQL, Sphinx) sharing an implicit 15-method contract with no formal interface. Backends differ fundamentally: native maintains its own inverted index, MySQL/PG delegate to DB engine FULLTEXT, Sphinx talks to an external daemon. The interface must accommodate passthrough backends where `index()` is a no-op as well as active-indexing backends where `index()` performs word extraction and storage. Sphinx breaks the legacy base class pattern by not extending `base.php`, bypassing caching entirely.

### Alternative A: Fat Unified Interface

Single `SearchBackendInterface` with all 12-15 methods. Every backend implements every method — passthrough backends return no-ops for indexing.

**Pros:**
- Simple registration: one tagged service type, one `instanceof` check
- Easy discoverability — all backend capability is on one type
- Lowest orchestrator complexity: call any method without checking types
- Familiar pattern to phpBB contributors accustomed to the legacy contract

**Cons:**
- MySQL/PG must implement `indexDocument()` as no-op (semantic dishonesty)
- Sphinx must stub `optimize()`, `updateForumId()`, and other native-specific operations
- Violates Interface Segregation Principle — client depends on methods it doesn't use
- No compile-time signal of which methods actually do work for a given backend

### Alternative B: Interface Segregation (ISP)

Separate interfaces: `SearcherInterface` (search), `IndexerInterface` (index/remove), `IndexAdminInterface` (create/delete/rebuild), `BackendMetaInterface` (name/availability/capabilities). Backends implement only what they support.

**Pros:**
- Clean separation — MySQL/PG skip `IndexerInterface` entirely
- Compile-time clarity about what each backend supports
- Extensible: new capability = new interface, no changes to existing backends
- Encourages single-responsibility per backend method group

**Cons:**
- Orchestrator must do `instanceof` checks before delegating each operation
- Backend registration/discovery is more complex (must collect multiple interfaces)
- Harder to reason about "what does this backend do?" at a glance
- Over-engineers a system with only 3-4 backends that rarely change

### Alternative C: Fat Interface + Capabilities DTO

Single `SearchBackendInterface` with all methods, plus a `getCapabilities(): BackendCapabilities` that returns a DTO declaring supported features (phrase search, wildcard, real-time indexing, relevance ranking). Orchestrator queries capabilities to decide behavior.

**Pros:**
- One interface for registration, discovery, and delegation
- Capabilities DTO enables UI adaptation (disable phrase search toggle for native)
- Graceful degradation logic is centralized: orchestrator translates AST based on capabilities
- No `instanceof` dance — all backends share the same type
- Capability declarations are runtime-inspectable (admin panel, diagnostics)

**Cons:**
- Some methods are semantically meaningless for passthrough backends (`indexDocument()` as no-op)
- Slight redundancy: method exists, but capability says `false`
- If capabilities grow, the DTO becomes a feature-flag bag
- No compile-time enforcement that a backend with `phraseSearch=true` actually handles phrases correctly

### Alternative D: Abstract Base + Optional Mixins (Trait-Based)

Abstract base class provides shared boilerplate. Backends selectively `use` traits: `IndexerTrait`, `FulltextBuilderTrait`, `DaemonConnectorTrait`. Interface remains minimal, traits provide optional implementations.

**Pros:**
- Code reuse for shared logic (stats formatting, config loading)
- Backends compose only the behavior they need
- Familiar pattern from phpBB's existing trait-based classes
- Reduces duplication between similar backends (MySQL/PG share FULLTEXT patterns)

**Cons:**
- Traits introduce hidden state and inheritance coupling — phpBB has been burned by this historically (Sphinx breaking `base.php`)
- Harder to test (traits can't be mocked independently)
- Orchestrator still needs runtime checks to determine what a backend supports
- Mixing OOP inheritance with composition creates a confusing hierarchy

### Trade-Off Matrix

| Perspective | A: Fat Interface | B: ISP | C: Fat + Capabilities | D: Traits |
|------------|-----------------|--------|----------------------|-----------|
| Technical Feasibility | High | High | High | Medium |
| User Impact (dev experience) | Good — simple | Medium — complex | Good — simple + adaptive UI | Medium — learning curve |
| Simplicity | High | Low | Medium-High | Medium |
| Risk | Low | Medium (over-engineering) | Low | Medium (trait coupling) |
| Scalability | Medium (grows awkwardly) | High (new interfaces) | High (new capability flags) | Low (trait proliferation) |

### Recommendation

**Alternative C: Fat Interface + Capabilities DTO**. The capabilities DTO solves the core tension: a uniform interface for simple registration/delegation, combined with runtime feature declarations that enable graceful degradation and UI adaptation. The 3-4 backends don't justify ISP's complexity, and traits repeat the coupling mistake that caused Sphinx to break `base.php` in legacy.

---

## Decision Area 2: Indexing Strategy

### Context

Content indexing keeps the search index up to date with forum posts. Legacy uses synchronous indexing for native/MySQL/PG backends (index in the same HTTP request as post save), while Sphinx uses asynchronous delta indexing. phpBB typically runs on shared hosting without queue infrastructure. The 8 domain events from Threads provide complete lifecycle coverage (create, edit, soft-delete, restore, hard-delete, topic-delete, visibility-change, topic-move).

### Alternative A: Fully Synchronous

Index documents in the same request that creates/edits them. Event listeners call `SearchService::indexPost()` directly in the Symfony event dispatcher flow.

**Pros:**
- Immediate consistency — new posts are searchable instantly
- Zero infrastructure requirements (no queues, no workers, no cron for indexing)
- Simplest to implement, debug, and reason about
- Matches 3 of 4 legacy backends' behavior (proven pattern)

**Cons:**
- Adds 5-50ms latency to every post create/edit request (native backend word extraction + INSERT)
- Under heavy posting load, indexing time compounds per-request
- No backpressure mechanism — if indexing fails, the post still exists but isn't indexed (data inconsistency)
- No parallelism — single-threaded PHP means indexing happens sequentially

### Alternative B: Fully Asynchronous (Queue-Based)

Events are dispatched to a message queue (e.g., Symfony Messenger with database transport). A background worker processes indexing jobs.

**Pros:**
- Zero latency impact on post save — user sees instant response
- Worker can batch index operations for efficiency
- Natural backpressure — queue depth signals overload
- Retryable: failed indexing jobs stay in queue for retry

**Cons:**
- Requires queue infrastructure not available on shared hosting (phpBB's primary deployment target)
- Eventual consistency — new posts may take seconds/minutes to appear in search
- Operational complexity: monitoring workers, handling dead letters, restart on failure
- Must handle "search too fast after posting" edge case (user posts, immediately searches, doesn't find it)

### Alternative C: Sync-Default with Async Opt-In

Synchronous by default (zero infrastructure). A config flag enables async mode for installations that have queue infrastructure. The event listener checks config and either dispatches synchronously or queues.

**Pros:**
- Works on shared hosting out of the box (sync)
- High-traffic installations can opt into async for performance
- Single indexing code path with a dispatch strategy wrapper
- Graceful upgrade path — start sync, switch to async when ready

**Cons:**
- Two execution modes to test and maintain
- Async mode requires documentation and setup instructions
- Edge cases differ between modes (error handling, consistency guarantees)
- Config flag adds complexity to the admin UX

### Alternative D: Coalesced Synchronous

Synchronous, but defers indexing to a shutdown handler or `register_shutdown_function()`. Coalesces multiple index operations in a single request (e.g., multi-quote post that triggers several events).

**Pros:**
- Reduces perceived latency — response is sent before indexing completes
- Coalescing avoids redundant index operations (edit + visibility change = one index op)
- No infrastructure requirements
- Keeps consistency guarantee (same request lifecycle)

**Cons:**
- `register_shutdown_function` is unreliable at scale (request timeout can kill it, fastcgi_finish_request is SAPI-dependent)
- Error in shutdown handler is silently swallowed — hard to debug
- Not all SAPIs support post-response execution (Apache vs nginx/FPM differs)
- phpBB has no existing pattern for shutdown-deferred work

### Trade-Off Matrix

| Perspective | A: Sync | B: Async Queue | C: Sync + Async Opt-In | D: Coalesced |
|------------|---------|----------------|----------------------|-------------|
| Technical Feasibility | High | Medium (needs queue) | High | Medium (SAPI issues) |
| User Impact | Good (instant search) | Poor (eventual) | Good/Excellent | Good |
| Simplicity | High | Medium | Medium | Low |
| Risk | Low | Medium (infra) | Low | Medium (reliability) |
| Scalability | Medium | High | High (when async) | Medium |

### Recommendation

**Alternative A: Fully Synchronous** as the initial strategy. phpBB's deployment context (shared hosting, no workers) makes sync the only universally viable option. The 5-50ms indexing overhead is acceptable for typical forum posting rates. Alternative C (async opt-in) is the natural evolution path once the core service is stable. The architecture should be designed to permit this later: event listeners receive an `IndexingStrategyInterface` that can be swapped via DI config.

---

## Decision Area 3: Query Parser Complexity

### Context

Legacy phpBB duplicates query parsing across 4 backends, each handling operators differently. The supported operator set is: `+` (MUST), `-` (MUST_NOT), `|` (OR within parentheses), `()` (grouping), `*` (trailing wildcard). Phrase search (`"..."`) is only supported by MySQL and Sphinx. The research recommends a shared AST-based parser that produces backend-agnostic query nodes, with backend-specific translators converting the AST to native query formats.

### Alternative A: Regex-Based Legacy-Compatible Parser

A straightforward regex/state-machine parser that recognizes exactly the legacy operator set (`+`, `-`, `|`, `()`, `*`). No AST — produces a flat structure of `must_terms`, `must_not_terms`, `or_groups`, `wildcard_terms`.

**Pros:**
- Fastest to implement — no recursive descent, no tree traversal
- Zero learning curve for phpBB users — identical syntax to today
- Proven operators — all 4 legacy backends handle these
- Simpler debugging — flat structure is easy to log and inspect

**Cons:**
- No extensibility — adding phrase search or field scoping requires rewriting the parser
- No graceful degradation framework — backend differences are handled ad-hoc
- Flat structure can't represent nested boolean logic (e.g., `(a | b) -c`)
- Duplicates the legacy problem: each backend re-interprets the flat structure differently

### Alternative B: Full AST with Recursive Descent

A recursive descent parser producing typed AST nodes (`TermNode`, `BooleanNode`, `PhraseNode`, `PrefixNode`, `NotNode`). Supports legacy operators plus `"phrase"` syntax. Backend translators walk the AST to generate backend-specific queries.

**Pros:**
- Extensible: new operators (field specifiers, proximity) are new node types, no parser rewrite
- Centralized parsing: each backend receives the same structured AST — no re-interpretation
- Graceful degradation is systematic: translator checks `capabilities` and downgrades unsupported nodes
- Enables query normalization (dedup terms, sort for cache-key consistency)

**Cons:**
- More complex to implement (recursive descent, node visitor pattern)
- Slight overhead: parse → AST → translate vs direct regex → SQL
- Risk of over-engineering if phpBB never actually extends beyond legacy operators
- Requires comprehensive test coverage for parser edge cases (unbalanced parens, empty groups)

### Alternative C: Two-Mode Parser (Simple + Advanced)

Two separate parsers: a simple mode (legacy-compatible flat structure) and an advanced mode (full AST with phrases, field specifiers). Users select mode in ACP or via query prefix (`!adv`).

**Pros:**
- Legacy users see zero change — simple mode is identical to today
- Power users get advanced features without affecting others
- Each parser optimized for its use case — simple mode is fast, advanced mode is complete
- Can ship simple mode first, add advanced mode later

**Cons:**
- Two parsers to maintain, test, and document
- UX confusion: "which mode am I in? why doesn't phrase search work?"
- Backends still need translators for both output formats (or a shared intermediate format — which is an AST)
- Mode switching adds admin configuration complexity

### Alternative D: PEG/Expression Grammar Parser

Use a formal grammar definition (PEG) to generate the parser. Define search syntax as a grammar file, generate PHP parser code.

**Pros:**
- Grammar is self-documenting — the syntax specification IS the parser
- Modification = edit grammar file, regenerate — no manual AST changes
- Battle-tested approach in language tooling
- Naturally produces AST

**Cons:**
- Requires a PEG parsing library dependency (e.g., `hafriedlander/php-peg`)
- Generated parser code is opaque — hard to debug when edge cases arise
- Overkill for the 5-6 operators phpBB search needs
- phpBB contributors may not be familiar with PEG grammars
- Adds external dependency to a project that minimizes them

### Trade-Off Matrix

| Perspective | A: Regex Legacy | B: Full AST | C: Two-Mode | D: PEG Grammar |
|------------|----------------|-------------|-------------|----------------|
| Technical Feasibility | High | High | Medium | Medium |
| User Impact | Neutral (same as today) | Good (phrases!) | Good (opt-in power) | Good (phrases) |
| Simplicity | High | Medium | Low | Low |
| Risk | Low | Low-Medium | Medium | Medium |
| Scalability | Low (can't extend) | High | Medium | High |

### Recommendation

**Alternative B: Full AST with recursive descent**. The parser is a ~200-line class — the implementation complexity difference between regex and recursive descent is modest, while the extensibility difference is enormous. Starting with legacy operators + phrase search covers 95% of user needs. The AST also provides the free benefit of query normalization for cache-key consistency. PEG and two-mode parsers add complexity without proportional value for phpBB's operator set.

---

## Decision Area 4: Result Caching Model

### Context

Legacy phpBB uses a two-tier cache: DB table (`search_results`) stores metadata with `LIKE '%word%'` invalidation (O(n) scan), and ACM file cache stores result ID arrays. Permissions are baked into the cache key via `ex_fid_ary` (excluded forum ID array), creating a combinatorial explosion of keys — each unique permission set generates a separate cache entry for the same query. Sphinx bypasses caching entirely. Research identifies permission-group hashing as a key improvement.

### Alternative A: Per-Permission-Set (Legacy Model)

Cache key includes the full set of excluded/included forum IDs. Each unique permission set gets its own cache entries.

**Pros:**
- Proven pattern — legacy phpBB has used this for 15+ years
- Pre-filtered results: no post-filter needed on cache hit
- Pagination is accurate — results already reflect user's permissions
- Simple invalidation model: delete by search_key

**Cons:**
- Severe cache fragmentation — forums with 10 permission groups × 100 popular queries = 1000 cache entries
- Low hit rate: users with slightly different permissions never share cache
- Memory waste: identical result sets stored multiple times for users with same effective access
- Legacy `LIKE` invalidation is O(n) — fundamentally unscalable

### Alternative B: Shared Cache + Post-Filter

Cache raw search results without any permission context. On cache hit, apply permission filtering before returning to user.

**Pros:**
- Maximum cache hit rate — one cache entry per query regardless of who searches
- Minimal memory usage — no duplication
- Tag-based invalidation replaces LIKE scan — O(1) per tag
- Works identically for all backends (including Sphinx)

**Cons:**
- Must over-fetch to guarantee `perPage` results after filtering (if filter removes items, you don't have enough)
- Re-filtering on every cache hit adds CPU cost
- Pagination becomes approximate — total count is pre-filter, actual results may differ
- Complex: need to store enough results to survive worst-case permission filtering

### Alternative C: Permission-Group Hash

Group users by identical effective forum permissions. Cache key includes a hash of the sorted list of readable forum IDs. Users with the same access share cache entries.

**Pros:**
- Dramatically reduces fragmentation — typical forum has 3-5 effective permission levels
- Pre-filtered results: pagination is accurate, no post-filter needed for forums
- Better hit rate than per-user while maintaining pre-filter benefits
- Hash computation is cheap: sort forum IDs, md5
- Compatible with tag-based invalidation

**Cons:**
- Must recompute permission-group hash on every request (or cache it with short TTL)
- Permission changes invalidate affected group's cache entries
- In forums with many custom per-user permissions, degrades toward per-user caching
- Doesn't help with visibility edge cases (shadow bans still need post-filter)

### Alternative D: Two-Layer Cache

Layer 1: short-TTL raw results (permission-independent, 60s). Layer 2: longer-TTL per-permission-group filtered results (300s). Cache hit checks L2 first, falls back to L1 + filter, falls back to backend.

**Pros:**
- Best theoretical hit rate: popular queries stay in L1 even during permission churn
- L2 provides pre-filtered fast path for repeated queries from same permission group
- L1 absorbs invalidation churn — only needs short TTL
- Decouples content freshness (L1 TTL) from permission freshness (L2 TTL)

**Cons:**
- Significantly more complex: two cache pools, two key schemes, two invalidation strategies
- Cache coherency bugs between layers are subtle and hard to diagnose
- Higher total memory usage (two copies of results)
- Marginal benefit over single-layer permission-group hash for typical phpBB traffic

### Trade-Off Matrix

| Perspective | A: Per-Permission | B: Shared + Filter | C: Perm-Group Hash | D: Two-Layer |
|------------|-------------------|--------------------|--------------------|-------------|
| Technical Feasibility | High | Medium | High | Medium |
| User Impact | Accurate pagination | Approximate pagination | Accurate pagination | Accurate pagination |
| Simplicity | Medium | Medium | Medium-High | Low |
| Risk | Low (proven) | Medium (over-fetch) | Low | Medium (coherency) |
| Scalability | Low (fragmentation) | High (dedup) | High | Highest |

### Recommendation

**Alternative C: Permission-Group Hash**. This provides the best pragmatic balance: in typical phpBB installations, 3-5 permission groups (guest, registered, moderator, admin, custom) cover 95%+ of users. Cache entries are shared across large user segments, improving hit rates by an order of magnitude over legacy, while preserving accurate pre-filtered pagination. Two-layer caching adds complexity that is only justified for very high-traffic installations — and can be introduced later as an optimization.

---

## Decision Area 5: Permission Check Timing

### Context

Forum read permissions determine which search results a user can see. Legacy phpBB builds an "excluded forum IDs" array before every search query and includes it in the SQL WHERE clause. This pre-filter approach works well for forum-level ACL but doesn't handle per-post visibility (unapproved posts, soft-deleted, shadow bans). Moderators may see unapproved posts in their moderated forums but not in other forums — this per-forum-per-visibility logic is complex.

### Alternative A: Pure Pre-Filter

Compute all readable forum IDs + per-forum visibility levels before the search query. Pass everything as WHERE constraints: `forum_id IN (...)` and `post_visibility IN (...)` per forum.

**Pros:**
- Backend returns only authorized results — no wasted I/O
- Pagination is perfectly accurate — total count reflects what user can see
- No post-processing step — results go directly to the response
- Simple mental model: "the query already accounts for permissions"

**Cons:**
- Per-forum visibility expansion creates complex SQL: `(forum_id = 1 AND visibility IN (1,2)) OR (forum_id = 2 AND visibility = 1) OR ...`
- Shadow bans can't be pre-filtered (require checking poster_id, not post attributes)
- Complex WHERE clauses may degrade query optimizer performance on backends
- Cache key must encode the full per-forum visibility map — re-fragmenting the cache

### Alternative B: Pure Post-Filter

Search without permission constraints. Filter the result set in PHP after retrieval, checking each result against user permissions.

**Pros:**
- Simplest backend query — no permission-related WHERE clauses
- Maximum cache sharing — everyone gets the same raw results
- All permission logic is centralized in one post-filter component
- Handles all edge cases (shadow bans, per-forum visibility, future rules)

**Cons:**
- Wastes I/O: may fetch hundreds of unauthorized results for restricted users
- Pagination is inaccurate: request page 1 of 25, filter removes 10, user sees 15
- Must over-fetch (request 100, show 25) — factor is unpredictable
- Guests on private forums would get almost zero results from large result sets

### Alternative C: Hybrid (Forum Pre-Filter + Edge-Case Post-Filter)

Pre-filter at forum level (`WHERE forum_id IN (readable_forum_ids)`). Post-filter for per-post edge cases: shadow bans, moderator visibility for unapproved/soft-deleted posts.

**Pros:**
- Eliminates ~95% of unauthorized results via cheap forum-level WHERE clause
- Pagination is accurate for the dominant access-control dimension (forum permissions)
- Shadow ban filtering is rare (<0.1% of posters) — negligible post-filter overhead
- Moderator visibility is resolved per-request without complicating the cache key
- Clean separation: backend handles spatial (forum) filtering, orchestrator handles contextual filtering

**Cons:**
- Two permission check points — slight complexity increase
- Post-filter may occasionally reduce result count below `perPage` (shadow ban case)
- Need to define clear boundary: what's pre-filtered vs post-filtered?
- Moderator visibility might cause minor pagination inaccuracy (rare)

### Alternative D: Materialized Permissions in Index

Store per-post "readable_by_group" flags in the search index. Query includes `WHERE readable_by_group IN (user's groups)`.

**Pros:**
- Fastest possible query — permissions are part of the index
- Perfect pagination accuracy
- Works equally well for all backends
- No post-filter needed — ever

**Cons:**
- Index bloat: every post stores permission-group associations (N groups × M posts)
- Permission changes require mass index updates (group permission change → re-index thousands of posts)
- Tight coupling between permission system and search index
- Only the native backend controls its index schema — MySQL/PG FULLTEXT can't add custom columns

### Trade-Off Matrix

| Perspective | A: Pure Pre-Filter | B: Pure Post-Filter | C: Hybrid | D: Materialized |
|------------|-------------------|--------------------|-----------|--------------------|
| Technical Feasibility | Medium (complex SQL) | High | High | Low (FULLTEXT limit) |
| User Impact | Accurate | Inaccurate pagination | Accurate (99%+) | Accurate |
| Simplicity | Medium | High | Medium | Low |
| Risk | Medium (SQL complexity) | Medium (over-fetch) | Low | High (coupling) |
| Scalability | Medium | Low (wasted I/O) | High | Medium (update cost) |

### Recommendation

**Alternative C: Hybrid**. Forum-level pre-filter is the established, proven pattern from all 4 legacy backends. It eliminates the vast majority of unauthorized results efficiently via a simple WHERE clause. Post-filter for shadow bans and moderator visibility handles the remaining edge cases without adding complexity to the cache key or the backend query. The post-filter overhead is negligible because these conditions affect <1% of results.

---

## Decision Area 6: Native Backend Schema Evolution

### Context

The native backend uses 3 dedicated tables: `search_wordlist` (vocabulary with word_count and word_common flag), `search_wordmatch` (word ↔ post associations with title_match flag), and `search_results` (cached result storage). MySQL/PG backends have no dedicated tables — they use DB engine FULLTEXT/GIN indexes directly. The research recommends adding a `frequency` column for TF-IDF ranking and a `forum_id` column to `search_wordmatch` for forum-scoped queries without JOINing the posts table.

### Alternative A: Preserve Legacy Schema (3 Tables, No Changes)

Keep the exact same schema: `search_wordlist(word_id, word_text, word_count, word_common)`, `search_wordmatch(post_id, word_id, title_match)`, `search_results(search_key, ...)`.

**Pros:**
- Zero migration risk — existing data works as-is
- Proven at scale across thousands of phpBB installations
- No schema-related bugs during the service rewrite
- Simplest implementation path for the native backend

**Cons:**
- No relevance ranking — results are ordered by date/ID, not relevance (phpBB's long-standing weakness)
- Forum-scoped queries still require JOIN to posts table (slower for large forums)
- Inherits N-way JOIN scalability problem for multi-word queries
- `search_results` table with LIKE invalidation — replaced by cache pool regardless

### Alternative B: Incremental Schema Evolution

Add targeted improvements: (1) `frequency` column (INT) to `search_wordmatch` for per-document term frequency, (2) `forum_id` column to `search_wordmatch` for forum-scoped query partition, (3) composite index on `(word_id, forum_id)` in `search_wordmatch`. Drop `search_results` table (replaced by cache pool).

**Pros:**
- Enables TF-IDF relevance ranking: `frequency / total_words_in_doc × IDF(word)`
- Forum-scoped searches become an index-only operation (no post table JOIN)
- Composite index dramatically improves multi-word AND queries within a forum
- Backward-preserving: old columns remain, new columns are additive

**Cons:**
- Migration script needed — ALTER TABLE on potentially large table (millions of rows) requires careful execution
- `forum_id` must be updated on topic move — new maintenance operation
- Index size increases (~20% more disk for forum_id + frequency columns)
- TF-IDF effectiveness is unverified for phpBB-typical content (short posts)

### Alternative C: Full Schema Redesign (Posting Lists)

Replace the wordmatch table with a posting-list approach: store per-word posting lists as serialized arrays or JSONB. Each row in `search_postings` stores `(word_id, posting_list BLOB)` where posting_list is a compressed array of `{post_id, frequency, forum_id, timestamp}`.

**Pros:**
- Single-row lookup per word instead of multi-row scan — potentially much faster for common words
- All per-document data (frequency, forum, time) available in one read
- Compressed posting lists use less disk than individual rows
- Closer to how real search engines (Lucene) store inverted indexes

**Cons:**
- Complete departure from proven schema — high implementation risk
- Atomic updates are hard: adding a post means read-modify-write the entire posting list
- BLOB/JSONB columns perform differently across MySQL/PostgreSQL
- Can't leverage SQL for filtering within posting lists (must deserialize in PHP)
- No phpBB precedent for this storage pattern — unfamiliar to contributors

### Alternative D: Hybrid (Evolved Tables + Relevance View)

Keep wordmatch as-is but add a materialized view or summary table `search_term_stats` with precomputed TF-IDF scores. Scores are refreshed during `optimize()` runs.

**Pros:**
- No schema change to wordmatch — zero migration risk for the core table
- Relevance ranking available via JOIN with precomputed scores
- Stats table can be rebuilt from scratch without affecting search functionality
- Decouples ranking quality from indexing speed

**Cons:**
- Stale ranking scores between optimize() runs — relevance is eventually consistent
- Extra table and maintenance job adds operational complexity
- JOIN with stats table may offset performance gains
- Doesn't solve the forum-scoped query problem (still needs post table JOIN)

### Trade-Off Matrix

| Perspective | A: Legacy Schema | B: Incremental Evolution | C: Posting Lists | D: Hybrid + View |
|------------|------------------|--------------------------|-----------------|------------------|
| Technical Feasibility | High | High | Medium | Medium-High |
| User Impact | None (no ranking) | Good (relevance!) | Good (relevance) | Medium (stale rank) |
| Simplicity | High | Medium | Low | Medium |
| Risk | Low | Low-Medium | High | Medium |
| Scalability | Low (N-way JOIN) | High (forum_id index) | Medium (BLOB ops) | Medium |

### Recommendation

**Alternative B: Incremental Schema Evolution**. Adding `frequency` and `forum_id` to `search_wordmatch` are low-risk, high-impact changes that enable the two most requested features: relevance ranking and fast forum-scoped search. The migration is a straightforward ALTER TABLE. Posting lists (C) is architecturally interesting but introduces too much risk for a system that needs to work reliably across shared hosting environments with varying DB engine support. The full schema redesign can be revisited if B proves insufficient at scale.

---

## Decision Area 7: Search Result Format

### Context

The question: what should `SearchService::searchPosts()` and `searchTopics()` return? Legacy returns raw arrays of post/topic IDs, with the controller doing hydration (fetching full post data, author names, forum titles). Research identifies three options spanning minimal (IDs only) to enriched (full DTOs with snippets and highlights).

### Alternative A: IDs Only

Return a `SearchResult` DTO containing `int[] $ids`, `int $totalCount`, `int $page`, `int $perPage`, `float $executionTimeMs`. Callers hydrate IDs into display objects via `ThreadsService`, `UserDisplayService`, etc.

**Pros:**
- Minimal coupling: search service knows nothing about post content rendering
- Single responsibility: search finds, controllers display
- Easy to cache: small payload (array of ints)
- No dependency on Threads service from within Search — clean service boundary
- Caller controls what data to hydrate (different views need different fields)

**Cons:**
- Always requires a hydration round-trip: search returns IDs → controller calls ThreadsService → render
- No relevance metadata (even if backend computed a score, it's lost)
- Can't support result snippets/highlighting without the caller re-fetching content
- Two service calls per search request (search + hydrate) instead of one

### Alternative B: Enriched DTOs

Return `SearchResultItem` DTOs with full display data: title, snippet (highlighted excerpt), author name, forum name, post date, relevance score.

**Pros:**
- Single call provides everything needed for rendering the search results page
- Snippet/highlighting can be computed where the query context is available (search service)
- Richer API — consumers don't need to know about hydration
- Relevance scores are preserved and available for display

**Cons:**
- Search service must depend on ThreadsService, UserDisplayService, HierarchyService — heavy coupling
- Snippet generation adds computation cost to every search
- Cached result payloads are much larger (full DTOs vs int arrays)
- Different consumers may need different enrichment (API vs web vs RSS) — one DTO doesn't fit all

### Alternative C: IDs + Score Metadata

Return `ScoredSearchResult` with `array<int, float> $idsWithScores` (ID → relevance score map), `int $totalCount`, pagination info, plus `string[] $matchedTerms` for highlighting hints.

**Pros:**
- Preserves relevance ranking information without coupling to content rendering
- `matchedTerms` enables caller-side highlighting without re-parsing the query
- Minimal payload increase over IDs-only (one float per result)
- Backends that don't support scoring return `0.0` — graceful degradation
- Caller has enough context to sort by relevance or highlight terms

**Cons:**
- Still requires hydration round-trip for display data
- Slightly more complex than pure IDs: callers must handle the score map
- `matchedTerms` may not be sufficient for phrase-level highlighting
- Two data structures to maintain instead of one

### Trade-Off Matrix

| Perspective | A: IDs Only | B: Enriched DTOs | C: IDs + Scores |
|------------|-------------|-------------------|-----------------|
| Technical Feasibility | High | Medium (deps) | High |
| User Impact | Neutral | Best (rich results) | Good (ranking visible) |
| Simplicity | High | Low | Medium |
| Risk | Low | Medium (coupling) | Low |
| Scalability | High (small payload) | Low (large payload) | High |

### Recommendation

**Alternative A: IDs Only** as the primary contract, with the architecture designed so Alternative C can be adopted when relevance ranking is implemented for the native backend. The search service should remain focused on efficient ID retrieval. Enrichment is the controller's concern. The `SearchResult` DTO (containing `ids[]`, `totalCount`, `page`, `perPage`, `executionTimeMs`) is the minimal, clean contract. When TF-IDF scoring is added, extending to `idsWithScores` is a backward-compatible evolution.

---

## Cross-Cutting Concerns

### Concern 1: Sphinx Backend Strategy

Sphinx is architecturally distinct from the other 3 backends: external daemon, no base class inheritance, delta-based indexing, bypasses caching. Decisions in areas 1, 2, and 4 all have Sphinx implications. **Recommendation**: Defer Sphinx to a separate adapter package. Keep the interface compatible but don't let Sphinx's unique requirements distort the architecture for the 3 DB-integrated backends.

### Concern 2: Cache Invalidation Granularity

Decision Areas 4 and 6 interact: if indexing is synchronous (Area 2), every post create/edit triggers cache invalidation. Aggressive flush-all invalidation (tag `search_results`) is safe but hurts hit rate under heavy posting. Word-level cache tags (linking cache entries to specific terms) enable surgical invalidation but add complexity. **Recommendation**: Start with flush-all on any content change. Monitor cache hit rate. Add word-level tag granularity as a future optimization if hit rate is unacceptable.

### Concern 3: Query Normalization for Cache Keys

Decision Areas 3 and 4 interact: the query parser (Area 3) must produce a normalized AST for cache key generation (Area 4). An AST parser naturally enables normalization (sort terms alphabetically, deduplicate, flatten trivial nesting) — a regex parser doesn't. This is another argument for Alternative B (AST) in Area 3.

### Concern 4: Backend Capability and Result Format Interaction

Decision Areas 1 and 7 interact: if backends declare `relevanceRanking: true` via capabilities (Area 1C), the result format (Area 7) should be able to carry scores. IDs-only (Area 7A) drops this information. This tension resolves if the DTO is designed with an optional `scores` field that populates only when relevant.

### Concern 5: Permission Model and Cache Model Interaction

Decision Areas 4 and 5 interact tightly: the caching model determines how permission information enters the cache key. Hybrid permissions (Area 5C) combined with permission-group hash (Area 4C) creates a coherent system: forum IDs form the group hash (pre-filter), shadow bans are post-filtered outside the cache layer.

---

## Deferred Ideas

| Idea | Rationale for Deferral |
|------|----------------------|
| **Elasticsearch/Meilisearch backend** | External search engine backends follow the same interface pattern as Sphinx. Once the interface is proven with 3 DB backends, adding external engine support is straightforward. Not needed for initial design. |
| **Faceted search** (filter by forum, author, date range as facets) | Useful feature but extends the search UI significantly. The backend interface can support it (add facet aggregation to `BackendSearchResult`), but UI/UX design is a separate concern. |
| **Search analytics dashboard** | `SearchPerformedEvent` captures data for analytics. Building a dashboard is a consumer of this event, not part of the search service design. |
| **Auto-suggestions / search-as-you-type** | Requires a different query path (prefix-heavy, sub-50ms latency target). Can be built as a separate endpoint using the same backend interface. |
| **Custom field indexing** (profile fields, custom BBCode attributes) | Extends `IndexableDocument` with custom fields. The pipeline architecture supports it (add fields to document, tokenize separately), but scope creep for initial design. |
| **Federated search** (search across multiple phpBB installations) | Entirely different architectural concern. Out of scope. |
| **Multi-language analyzers** (different tokenization per forum language) | The pipeline architecture supports per-language analyzers. Implementation requires language detection and per-forum analyzer config. Complexity is high; value is niche. Defer. |

---

## Summary of Recommendations

| Decision Area | Recommended Alternative | Confidence |
|---------------|------------------------|------------|
| 1. Backend Interface | **C: Fat Interface + Capabilities DTO** | High |
| 2. Indexing Strategy | **A: Fully Synchronous** (with async opt-in architecture) | High |
| 3. Query Parser | **B: Full AST with Recursive Descent** | High |
| 4. Result Caching | **C: Permission-Group Hash** | Medium-High |
| 5. Permission Timing | **C: Hybrid (Forum Pre-Filter + Edge Post-Filter)** | High |
| 6. Native Schema | **B: Incremental Evolution (frequency + forum_id)** | Medium-High |
| 7. Result Format | **A: IDs Only** (designed to evolve to C) | High |

### Key Assumptions

1. phpBB's primary deployment target remains shared hosting without queue/worker infrastructure
2. Typical forums have 3-5 effective permission groups covering 95%+ of users
3. TF-IDF relevance ranking will be effective for phpBB-typical content (forum posts of 50-500 words)
4. The 5-50ms synchronous indexing overhead is acceptable for typical posting rates
5. Sphinx adoption is low enough to justify deferring it to a separate adapter package

### If These Assumptions Prove Wrong

- If Assumption 1 changes (phpBB targets container/cloud deployments): Adopt async indexing (Area 2, Alternative C)
- If Assumption 2 is wrong (many per-user custom permissions): Fall back to shared cache + post-filter (Area 4, Alternative B)
- If Assumption 3 is wrong (TF-IDF ineffective for short posts): Drop frequency column, use date-based ranking, save index space
- If Assumption 4 is wrong (indexing latency is noticed): Implement coalesced sync (Area 2, Alternative D) or async opt-in
- If Assumption 5 is wrong (Sphinx is widely used): Elevate Sphinx to first-class backend, add external-daemon capability to interface
