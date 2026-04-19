# Decision Log

## ADR-001: Backend Interface Granularity

### Status
Accepted

### Context
The legacy search system has 4 backends (native, MySQL, PostgreSQL, Sphinx) sharing an implicit 15-method contract with no formal interface. Backends differ fundamentally: native maintains its own inverted index (indexing is real work), MySQL/PG delegate to DB engine FULLTEXT (indexing is a no-op), Sphinx talks to an external daemon. A fat unified interface would force MySQL/PG to implement `indexPost()` as a semantically dishonest no-op. The research recommended a fat interface + capabilities DTO (Alternative C) for simplicity, but the user chose Interface Segregation (Alternative B).

### Decision Drivers
- MySQL/PG backends should never expose `indexPost()` — they don't index; the DB engine does
- Compile-time clarity about what each backend supports vs runtime capability checks
- Extensibility: new capability = new interface, no changes to existing backends
- Only 3-4 backends exist; `instanceof` cost is bounded and predictable

### Considered Options
1. **Fat Unified Interface** — single 12-15 method interface, all backends implement everything
2. **Interface Segregation (ISP)** — `SearcherInterface`, `IndexerInterface`, `IndexAdminInterface`, `BackendInfoInterface`
3. **Fat Interface + Capabilities DTO** — single interface with `getCapabilities()` runtime declarations
4. **Abstract Base + Traits** — base class with optional mixin traits

### Decision Outcome
Chosen option: **Interface Segregation (ISP)**, because it provides compile-time truthfulness about backend capabilities. MySQL/PG backends implement `SearcherInterface` + `BackendInfoInterface` only — there is no `indexPost()` to call on them, preventing semantic errors. The orchestrator performs `instanceof` checks before delegating indexing and admin operations.

### Consequences

#### Good
- No-op stubs eliminated — if it implements the interface, the method does real work
- Adding a new capability (e.g., `SuggestionInterface`) requires zero changes to existing backends
- Type-safe delegation — calling `indexPost()` on a non-`IndexerInterface` is a compile error
- Each interface is independently testable with focused mocks

#### Bad
- Orchestrator requires `instanceof` checks at ~4 delegation points (index, remove, admin, optimize)
- Backend registration/discovery is slightly more complex (must resolve multiple interface types)
- Harder to answer "what does this backend do?" at a glance without checking implemented interfaces
- More interface files to maintain (4 instead of 1)

---

## ADR-002: Indexing Strategy

### Status
Accepted

### Context
Content indexing keeps the search index current with forum posts. Legacy uses synchronous indexing for native/MySQL/PG (index in the same HTTP request as post save). phpBB typically runs on shared hosting without queue infrastructure (no workers, no message brokers). The 8 domain events from Threads provide complete lifecycle coverage. The research recommended fully synchronous as the initial strategy with async as a future evolution. The user chose sync-default with an explicit async opt-in seam from day one.

### Decision Drivers
- phpBB's primary deployment target is shared hosting — no queue infrastructure available
- Immediate search consistency: new posts must be findable right after creation
- High-traffic installations (container/cloud) need a future path to async without redesign
- The seam must exist from day one even if async is not implemented yet

### Considered Options
1. **Fully Synchronous** — index in same request, no strategy abstraction
2. **Fully Asynchronous (Queue-Based)** — Symfony Messenger with DB transport
3. **Sync-Default + Async Opt-In** — `IndexingStrategyInterface` with strategy pattern
4. **Coalesced Synchronous** — defer to `register_shutdown_function()`

### Decision Outcome
Chosen option: **Sync-Default + Async Opt-In**, because it delivers zero-infrastructure indexing for shared hosting while providing a clean seam for high-traffic deployments. `SynchronousIndexingStrategy` calls backend methods directly. `AsynchronousIndexingStrategy` is a stub that throws `\LogicException` — its existence ensures the DI wiring and strategy pattern are proven from day one. A config flag `search_indexing_async: false` controls which strategy is injected.

### Consequences

#### Good
- Works immediately on shared hosting with no additional infrastructure
- Strategy pattern is proven via tests even before async implementation exists
- Future async adoption requires only implementing the strategy + adding queue transport — no orchestrator changes
- Two execution modes can share the same test suite with strategy mocking

#### Bad
- Two strategies to maintain and test (even though one is a stub)
- Async mode requires separate documentation and setup instructions when eventually implemented
- Edge case behavior differs between modes (error handling, consistency guarantees)
- Config flag adds minor complexity to admin UX

---

## ADR-003: Query Parser Complexity

### Status
Accepted

### Context
Legacy phpBB duplicates query parsing across 4 backends, each handling operators differently. Supported operators: `+` (MUST), `-` (MUST_NOT), `|` (OR within parentheses), `()` (grouping), `*` (trailing wildcard). Phrase search (`"..."`) only works on MySQL and Sphinx. The research unanimously recommended AST-based parsing — the user confirmed.

### Decision Drivers
- Centralized parsing eliminates per-backend re-interpretation of query syntax
- AST enables systematic graceful degradation (phrases → AND on native/PG)
- Query normalization (sort terms, dedup) produces consistent cache keys
- Phrase search is the most requested extension — AST supports it natively

### Considered Options
1. **Regex-Based Legacy-Compatible** — flat structure of must/must_not/or arrays
2. **Full AST with Recursive Descent** — typed nodes (`TermNode`, `PhraseNode`, `BooleanNode`, `PrefixNode`, `NotNode`)
3. **Two-Mode Parser** — simple mode (legacy) + advanced mode (AST)
4. **PEG Grammar Parser** — formal grammar definition with generated parser

### Decision Outcome
Chosen option: **Full AST with Recursive Descent**, because the ~200-line recursive descent parser provides enormous extensibility for minimal additional complexity over regex. The AST unifies parsing across all backends — each receives the same structured tree. Per-backend `QueryTranslatorInterface` implementations convert the AST to native query syntax. Query normalization for cache-key consistency comes free from the tree structure.

### Consequences

#### Good
- Single parser shared across all backends — no parsing duplication
- Phrase search supported from day one (backends that can't handle it degrade to AND)
- Query normalization enables cache-key consistency (same query = same key regardless of term order)
- New operators (field specifiers, proximity) are new node types — no parser rewrite

#### Bad
- More complex to implement than regex (~200 LOC vs ~80 LOC)
- Slight runtime overhead: parse → AST → translate vs direct regex → SQL
- Requires comprehensive test suite for edge cases (unbalanced parentheses, empty groups, CJK)
- Risk of over-engineering if phpBB never extends beyond legacy operators

---

## ADR-004: Result Caching Model

### Status
Accepted

### Context
Legacy phpBB bakes the user's excluded forum IDs (`ex_fid_ary`) into the cache key via MD5 hash, creating a combinatorial explosion: each unique permission set generates separate cache entries for the same query. LIKE-based invalidation (`search_keywords LIKE '%word%'`) is O(n). Sphinx bypasses caching entirely. The research identified permission-group hashing as the pragmatic balance — the user confirmed.

### Decision Drivers
- Typical phpBB installations have 3-5 effective permission groups — cache sharing is dramatic
- Pre-filtered pagination must remain accurate (no over-fetching needed)
- Tag-based invalidation replaces LIKE-based scan — O(1) per tag
- No post-filter needed on cache hit for forum-level permissions

### Considered Options
1. **Per-Permission-Set (Legacy)** — `ex_fid_ary` baked into cache key
2. **Shared Cache + Post-Filter** — cache raw results, filter per-request
3. **Permission-Group Hash** — group by identical readable forum IDs
4. **Two-Layer Cache** — raw results (short TTL) + per-group filtered (longer TTL)

### Decision Outcome
Chosen option: **Permission-Group Hash**, because in typical phpBB installations, 3-5 permission groups (guest, registered, moderator, admin, custom) cover 95%+ of users. Cache entries are shared across large user segments, improving hit rates by an order of magnitude over legacy while preserving accurate pre-filtered pagination. `permGroupHash = md5(implode(',', sort($readableForumIds)))`.

### Consequences

#### Good
- ~5 cache entries per query instead of ~hundreds (one per unique permission set)
- Pre-filtered results: pagination is accurate, no post-filter needed for forum permissions
- Hash computation is cheap: sort array, implode, md5
- Compatible with tag-based invalidation via `phpbb\cache` pool

#### Bad
- Permission-group hash must be computed (or cached) on every search request
- Permission changes invalidate affected group's cache entries (flush-all tag for simplicity)
- Forums with many per-user custom permissions degrade toward per-user caching
- Shadow bans still require a separate post-filter step (outside cache boundary)

---

## ADR-005: Permission Check Timing

### Status
Accepted

### Context
Forum read permissions determine which search results a user can see. Legacy pre-filters with `WHERE forum_id IN (...)`. However, per-post visibility (unapproved, soft-deleted) and shadow bans can't be handled purely via pre-filter without exploding SQL complexity or cache keys. The research recommended hybrid — the user confirmed.

### Decision Drivers
- Forum-level pre-filter eliminates ~95% of unauthorized results via cheap WHERE clause
- Shadow bans affect < 0.1% of posters — post-filter overhead is negligible
- Moderator visibility varies per-forum — too complex to pre-filter efficiently
- Clean separation: backend handles spatial (forum) filtering, orchestrator handles contextual filtering

### Considered Options
1. **Pure Pre-Filter** — all permissions in WHERE clause (complex SQL with per-forum visibility)
2. **Pure Post-Filter** — search without constraints, filter after retrieval (wasted I/O)
3. **Hybrid** — forum-level pre-filter + shadow ban / moderator visibility post-filter
4. **Materialized Permissions in Index** — store `readable_by_group` in index (bloat, coupling)

### Decision Outcome
Chosen option: **Hybrid (forum pre-filter + edge-case post-filter)**, because forum-level pre-filter is the established, proven pattern from all 4 legacy backends. It efficiently handles the dominant access-control dimension. Post-filter for shadow bans and moderator visibility handles the remaining ~5% edge cases without adding complexity to the cache key or backend query.

### Consequences

#### Good
- ~95% of unauthorized results eliminated before backend returns data
- Pagination accurate for the dominant permission dimension (forum access)
- Shadow ban check is a batch operation — single call for all result poster IDs
- Moderator visibility resolved per-request without fragmenting cache

#### Bad
- Two permission check points — slightly higher conceptual complexity
- Post-filter may occasionally reduce result count below `perPage` (shadow ban case — very rare)
- Clear boundary needed: what's pre-filtered vs post-filtered must be documented
- Moderator visibility edge case may cause minor pagination inaccuracy in rare cases

---

## ADR-006: Native Backend Schema Evolution

### Status
Accepted

### Context
The native backend uses 3 tables: `search_wordlist` (word_id, word_text, word_count, word_common), `search_wordmatch` (post_id, word_id, title_match), `search_results` (cache). The research recommended adding `frequency` (for TF-IDF ranking) and `forum_id` (for forum-scoped queries without JOIN) to `search_wordmatch`. The user chose to preserve the legacy schema without schema changes, preferring zero migration risk now with a future path to additive evolution.

### Decision Drivers
- Zero migration risk: thousands of existing installations keep their data as-is
- No unverified changes: TF-IDF effectiveness for phpBB content (short posts) is untested
- The code architecture must not preclude future additive schema changes
- `search_results` table is functionally replaced by cache pool regardless

### Considered Options
1. **Preserve Legacy Schema** — wordlist + wordmatch + results, same columns
2. **Incremental Evolution** — add `frequency` and `forum_id` to `search_wordmatch`
3. **Full Redesign (Posting Lists)** — BLOB-based posting lists per word
4. **Hybrid + Stats View** — unchanged tables + precomputed TF-IDF view

### Decision Outcome
Chosen option: **Preserve Legacy Schema**, because it eliminates migration risk during the service rewrite. The existing 3-table schema is proven at scale. The DTO-based indexing contract (`IndexableDocument`) and ISP interfaces mean adding `frequency` or `forum_id` columns later is a purely internal backend change — no interface modifications needed.

### Consequences

#### Good
- Zero migration effort — existing data works immediately with new service
- No schema-related bugs reduce risk during the major service rewrite
- Proven schema across thousands of phpBB installations
- Future schema evolution is an internal backend concern behind `IndexerInterface`

#### Bad
- No relevance ranking in native backend — results ordered by date/ID only (phpBB's long-standing weakness)
- Forum-scoped queries still require JOIN to posts table (slower for large forums)
- N-way JOIN scalability problem for multi-word queries remains
- `search_results` LIKE-based invalidation table retained during migration (functionally replaced by cache pool)

---

## ADR-007: Search Result Format

### Status
Accepted

### Context
The question is what `SearchService::searchPosts()` returns. Legacy returns raw arrays of post/topic IDs with controllers doing hydration. The research unanimously recommended IDs only — the user confirmed.

### Decision Drivers
- Minimal coupling: search service knows nothing about post content rendering
- Single responsibility: search finds, controllers display
- Small cache payload: array of ints vs full DTOs
- No dependency on Threads service from within Search — clean service boundary
- Different consumers need different enrichment (web vs API vs RSS)

### Considered Options
1. **IDs Only** — `SearchResult { ids: int[], totalCount, page, perPage, executionTimeMs }`
2. **Enriched DTOs** — full display data (title, snippet, author, date, relevance score)
3. **IDs + Score Metadata** — `array<int, float>` with matched terms for highlighting

### Decision Outcome
Chosen option: **IDs Only**, because the search service should remain focused on efficient ID retrieval. Enrichment (titles, snippets, author display) is the controller's concern using `ThreadsService` and `UserDisplayService`. The `SearchResult` DTO is minimal: `ids[]`, `totalCount`, `page`, `perPage`, `executionTimeMs`. When TF-IDF scoring is eventually added (after schema evolution, ADR-006), extending to IDs+scores is a backward-compatible DTO change.

### Consequences

#### Good
- Search service has zero dependency on post content or rendering
- Cache payloads are small and fast to serialize/deserialize
- Any consumer (web, API, RSS, CLI) can hydrate with exactly the data it needs
- Clean service boundary — search never calls ThreadsService

#### Bad
- Always requires a hydration round-trip: search returns IDs → controller calls ThreadsService
- No relevance metadata carried even if backend computed a score (lost until schema evolves)
- Can't support result snippets/highlighting without controller re-fetching content
- Two service calls per search request (search + hydrate) instead of one
