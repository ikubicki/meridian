# Search Service — Cross-Source Synthesis

## Research Question

"How should the new `phpbb\search` service be designed to implement legacy search mechanisms (pluggable backends, word indexing, result caching, query parsing) from scratch with complete isolation from the old solution?"

---

## Executive Summary

Five independent research probes converged on a consistent architectural picture: the legacy search system is a pluggable-backend strategy pattern with an implicit contract of ~15 methods, 3 dedicated tables (used only by the native backend), a two-tier caching model (DB metadata + file-cache IDs), and per-backend query translation. Cross-referencing reveals high alignment on the recommended direction — an orchestrator + strategy pattern with an explicit interface, AST-based query parsing, event-driven indexing, and hybrid permission filtering. The primary tensions cluster around 7 decision areas where legitimate trade-offs exist: backend interface granularity, indexing timing, query parser complexity, caching model, permission check timing, native schema evolution, and result format.

---

## 1. Cross-Source Analysis

### 1.1 Validated Findings (Confirmed by Multiple Sources)

| Finding | Sources Confirming | Confidence |
|---------|-------------------|------------|
| **4 backends exist: native, MySQL, PostgreSQL, Sphinx** | Legacy-backends, DB-schema, Admin-config | **High** |
| **Only native backend uses dedicated search tables (wordlist/wordmatch)** | Legacy-backends, DB-schema | **High** |
| **MySQL/PG index() is a no-op — only invalidates cache** | Legacy-backends, DB-schema, Admin-config | **High** |
| **Sphinx does NOT extend base class, skips result caching** | Legacy-backends, DB-schema, Cross-service | **High** |
| **Cache key includes permission-derived data (ex_fid_ary)** | Legacy-backends (search_key MD5), Literature (§4.1), Admin-config (search_store_results) | **High** |
| **Pre-filter at forum level is the established pattern** | Legacy-backends (ex_fid_ary), Cross-service (AuthorizationService::getGrantedForums), Literature (§7.2) | **High** |
| **Synchronous indexing for non-Sphinx backends** | Legacy-backends (index() in request path), Literature (§6.1), Admin-config (fulltext_native_load_upd) | **High** |
| **8 domain events from Threads feed the search indexer** | Cross-service (§1.1–1.8) | **High** |
| **Result caching uses two-tier: DB metadata + ACM file/memory** | Legacy-backends (base.php), DB-schema (search_results table), Admin-config (search_store_results) | **High** |
| **Tokenization is monolithic in legacy — no pipeline** | Legacy-backends (cleanup()), Literature (§1.2) | **High** |
| **23 extension events exist across backends** | Legacy-backends (§5) | **High** |
| **~30 config keys govern the search system** | Admin-config (§1) | **High** |

### 1.2 Contradictions Resolved

| Contradiction | Source A | Source B | Resolution |
|---------------|----------|----------|------------|
| **Sphinx caching** | Legacy-backends: "Sphinx does NOT use obtain_ids/save_ids" | Admin-config: "search_store_results applies to all backends" | **Resolved**: The config exists globally but Sphinx ignores it because it doesn't inherit base class. The new design should make caching opt-in at orchestrator level, not backend level. |
| **Phrase search capability** | Legacy-backends: "native and PostgreSQL don't support phrases" | Literature: recommends PhraseNode in AST | **Resolved**: The AST should support phrases universally; backends that can't execute them degrade to AND-of-words. Capability declaration (`BackendCapabilities.phraseSearch`) tells the orchestrator. |
| **Word length filtering location** | Legacy-backends: "done in split_keywords per backend" | Literature: "should be a TokenFilter in pipeline" | **Resolved**: Move to shared tokenization pipeline; backends shouldn't independently re-implement length filtering. Per-backend min/max config still exists (MySQL reads from server vars) but filtering logic is centralized. |
| **Permission in cache key** | Legacy-backends: "ex_fid_ary is part of MD5 search key" | Literature: "don't cache per-user → cache per-permission-group" | **Resolved**: Tension is real. Legacy bakes permissions into cache key, fragmenting cache. New design should separate: cache raw results (permission-independent) and filter at retrieval time, OR use a permission-group hash. Literature's approach is superior. |
| **Common word handling** | Legacy-backends: "frequency threshold via tidy()" + "words silently dropped" | Literature: "combine static stopwords + frequency threshold" | **Resolved**: Not a contradiction — literature extends the approach. New service should use static stopwords at index time + frequency-based common word flagging as maintenance. |

### 1.3 Confidence Assessment Per Area

| Area | Confidence | Basis |
|------|-----------|-------|
| Backend contract extraction (methods + signatures) | **High** — 4 backends analyzed, common surface clear | All 4 backends share 15 methods; source code extracted |
| Database schema and table usage | **High** — DDL from dump, cross-validated against code | Actual SQL verified against backend code |
| Event contracts (consumed/dispatched) | **High** — derived from Threads service design | Event payloads documented with types |
| Tokenization pipeline design | **High** — industry patterns well understood | Elasticsearch model is de facto standard |
| Query parser specification | **Medium-High** — AST approach solid, operator mapping clear | Legacy parsers analyzed; some edge cases in CJK/Unicode |
| Caching strategy | **Medium** — two valid approaches, trade-off is real | Literature proposes permission-independent cache; legacy used per-permission cache |
| Permission filtering model | **Medium-High** — pre-filter at forum level validated | All backends use ex_fid_ary; shadow ban is post-filter |
| Relevance ranking for native backend | **Medium** — TF-IDF recommended but untested in this context | Legacy has NO ranking; adding it is new ground |
| Admin rebuild/management | **High** — ACP code fully analyzed | State machine, batch sizes, progress tracking documented |
| Sphinx replacement strategy | **Low-Medium** — external daemon adds complexity | Sphinx is architecturally different; unclear if new service should support it |

---

## 2. Patterns and Themes

### 2.1 Architectural Patterns

| Pattern | Evidence | Prevalence | Quality |
|---------|----------|-----------|---------|
| **Strategy pattern for backends** | All 4 backends implement same methods; config selects active backend | Core pattern | Implicit (no interface), duck-typed |
| **Orchestrator with delegated execution** | base.php handles caching; backends handle search/index | Core pattern | Partially implemented (Sphinx breaks it) |
| **Event-driven integration** | 8 consumed events, 2 dispatched events | Designed for new system | Well-specified |
| **Two-tier caching** | DB metadata + ACM file cache | All backends except Sphinx | Functional but fragmented |
| **Pre-filter permissions** | ex_fid_ary computed before search | All backends | Consistent |
| **Batch processing with state machine** | Index rebuild uses search_indexing_state config | Admin operations | Robust (resumable) |

### 2.2 Implementation Patterns

| Pattern | Current State | Recommended Evolution |
|---------|--------------|----------------------|
| Monolithic tokenization | Single `cleanup()` function in native | Pipeline: CharFilter → Tokenizer → TokenFilter chain |
| Direct SQL construction | Each backend builds SQL inline | AST → backend-specific translator |
| Constructor injection (non-DI) | `new $class_name(&$error, ...)` | Symfony DI container, tagged services |
| Config-driven backend selection | `$config['search_type']` = class FQCN | Service tag `phpbb.search.backend` + config ID |
| Per-backend event hooks | 23 `core.search_*` events | Unified pre/post search events at orchestrator level |

### 2.3 Cross-Cutting Themes

**Theme: Backend Asymmetry**
- Native maintains its own inverted index; MySQL/PG delegate to DB engine; Sphinx delegates to external daemon
- This asymmetry is fundamental and must be preserved — the interface should accommodate passthrough backends (MySQL/PG) and active-indexing backends (native) equally

**Theme: Caching vs Freshness**
- Legacy caches aggressively (30 min default TTL) with keyword-based invalidation
- LIKE-based invalidation on `search_results.search_keywords` is O(n) scan — a scalability bottleneck
- New design must choose: fine-grained invalidation (complex) vs short TTL (simpler, lower hit rate)

**Theme: Permission Fragmentation**
- Forum permissions create a combinatorial explosion of cache keys
- Legacy approach: bake `ex_fid_ary` into cache key → O(permission_groups × queries) cache entries
- Literature approach: cache raw results, filter at retrieval → O(queries) cache entries
- Tension is between cache hit rate (literature wins) and response time (legacy avoids re-filtering)

---

## 3. Key Tensions and Trade-Offs

### 3.1 Synchronous vs Async Indexing

| Aspect | Synchronous | Async (Queue) | Delta (Sphinx-style) |
|--------|-------------|---------------|---------------------|
| **Latency on post save** | +5-50ms | ~0ms | ~0ms |
| **Consistency** | Immediate | Eventual (seconds) | Eventual (minutes-hours) |
| **Infrastructure** | None | Queue system required | Cron + external indexer |
| **Complexity** | Low | Medium | Medium |
| **phpBB context** | Typical deployments lack queues | Shared hosting = no workers | Only Sphinx uses this today |

**Resolution**: All sources agree synchronous is appropriate for phpBB's deployment context. The event-driven architecture (Cross-service §1) doesn't require a queue — Symfony's synchronous event dispatcher suffices. Async can be added later as an opt-in for high-traffic installations.

### 3.2 Pre-Filter vs Post-Filter for Permissions

| Aspect | Pre-Filter | Post-Filter | Hybrid |
|--------|-----------|-------------|--------|
| **Pagination accuracy** | Accurate | Inaccurate (gaps) | Accurate for forums, approximate for visibility |
| **Cache sharing** | Poor (per-permission-group) | Excellent (one cache per query) | Good (per-permission-group-hash) |
| **Wasted I/O** | None | Fetches unauthorized results | Minimal |
| **Implementation** | Forum IDs in WHERE clause | Filter after retrieval | Forum pre-filter + visibility post-filter |

**Resolution**: Cross-service (§6) and Literature (§7.3) both recommend **hybrid**: pre-filter at forum level (WHERE forum_id IN ...), post-filter for visibility states (unapproved, soft-deleted) and shadow bans. This matches legacy behavior while allowing better cache sharing through permission-group hashing.

### 3.3 Cache Granularity

| Aspect | Per-User (legacy) | Shared + Post-Filter (literature) | Per-Permission-Group |
|--------|-------------------|-----------------------------------|---------------------|
| **Hit rate** | Low (fragmented) | High (shared) | Medium-High |
| **Correctness** | Exact | Exact (filter at retrieval) | Exact |
| **Invalidation** | Must find all affected keys | Fewer keys to invalidate | Moderate |
| **Memory** | High (many copies) | Low (one copy) | Moderate |

**Resolution**: Literature's permission-independent caching with post-filter is theoretically optimal. However, this requires over-fetching to guarantee `perPage` results after filtering, and re-filtering on every cache hit. The **permission-group hash** approach (Literature §7.4) is the pragmatic compromise: users with identical forum access share cache entries, dramatically reducing fragmentation while keeping pre-filtered pagination accurate.

### 3.4 Backend Capability Differences

| Capability | Native | MySQL | PostgreSQL | Sphinx |
|-----------|--------|-------|-----------|--------|
| Phrase search | ❌ | ✅ | ❌ | ✅ |
| Relevance ranking | ❌ (new: TF-IDF possible) | ✅ (InnoDB TF-IDF) | ✅ (ts_rank) | ✅ (BM25) |
| Wildcard | Trailing only | Trailing only | Prefix (`:*`) | Prefix + infix |
| Custom word index | ✅ | ❌ (DB-managed) | ❌ (DB-managed) | ❌ (daemon-managed) |
| Real-time indexing | ✅ | ✅ (auto) | ✅ (auto) | ❌ (delta) |
| Ext. service required | ❌ | ❌ | ❌ | ✅ |

**Resolution**: The `BackendCapabilities` DTO (Literature §5.2) is the correct solution. The orchestrator queries capabilities and degrades gracefully — phrases become AND-of-words on native/PG, wildcards are unsupported on PG, etc. The UI adapts based on active backend capabilities.

### 3.5 Word-Level vs Document-Level Indexing

| Approach | Used by | Index size | Query flexibility | Maintenance |
|----------|---------|-----------|------------------|-------------|
| **Word-level** (inverted index) | Native | Large (N words × M posts) | Full boolean control | High (word counts, common words) |
| **Document-level** (FULLTEXT) | MySQL, PG | Managed by DB engine | DB-engine dependent | Low (auto-maintained) |
| **External** | Sphinx | External files | Full Sphinx syntax | Medium (delta merge) |

**Resolution**: This is not a choice — it's inherent to the backend type. The interface must accommodate both: native backend implements `IndexerInterface` (writes to word tables), while MySQL/PG backends are passthrough (no-op `index()`, DB engine handles it). The key insight from DB-schema analysis: only native uses `search_wordlist` and `search_wordmatch` — these tables are backend-private, not shared.

---

## 4. Relationships and Dependencies

### 4.1 Service Dependency Map

```
┌─────────────────────────────────────────────────────────┐
│                    API Controller                        │
│   searchPosts() / searchTopics()                        │
└───────────────────────┬─────────────────────────────────┘
                        │
                        ▼
┌─────────────────────────────────────────────────────────┐
│                 SearchService (Orchestrator)              │
│   - Rate limiting (search_interval)                      │
│   - Load checking (limit_search_load)                    │
│   - Permission resolution (AuthorizationService)         │
│   - Query parsing (QueryParser → AST)                    │
│   - Cache check/store (CachePool)                        │
│   - Backend delegation (SearchBackendInterface)          │
│   - Shadow ban filtering (ShadowBanService)              │
│   - Result enrichment (optional)                         │
└───┬──────────┬──────────┬──────────┬────────────────────┘
    │          │          │          │
    ▼          ▼          ▼          ▼
┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐
│ Native │ │ MySQL  │ │ Postgres│ │ Sphinx │
│Backend │ │Backend │ │Backend │ │Backend │
└───┬────┘ └───┬────┘ └───┬────┘ └───┬────┘
    │          │          │          │
    ▼          ▼          ▼          ▼
┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐
│wordlist│ │FULLTEXT│ │GIN/    │ │Sphinx  │
│wordmtch│ │indexes │ │tsvector│ │daemon  │
└────────┘ └────────┘ └────────┘ └────────┘
```

### 4.2 Event Flow

```
Threads Service ──────────────────────> Search Indexing Listeners
  PostCreatedEvent          ──>  index(postId, subject, body)
  PostEditedEvent           ──>  reindex(postId, newSubject, newBody)
  PostSoftDeletedEvent      ──>  removeFromIndex(postId)
  PostRestoredEvent         ──>  addToIndex(postId)  [fetch content]
  PostHardDeletedEvent      ──>  removeFromIndex(postId)
  TopicDeletedEvent         ──>  batchRemove(postIds[])
  VisibilityChangedEvent    ──>  addOrRemove(affectedPostIds[], newVisibility)
  TopicMovedEvent           ──>  updateForumId(topicId, newForumId)

Search Service ──────────────────────> Consumers
  SearchPerformedEvent      ──>  Analytics, rate limiting audit
  IndexRebuiltEvent         ──>  Admin notification
```

### 4.3 Data Flow Through A Search Request

```
User query string
    │
    ▼
[Rate limit check] → reject if too soon (user_last_search)
    │
    ▼
[Load check] → reject if CPU load > threshold
    │
    ▼
[QueryParser.parse()] → AST (TermNode, BooleanNode, PhraseNode, NotNode, PrefixNode)
    │
    ▼
[Tokenize query terms] → apply same pipeline as indexing (lowercase, min/max length, etc.)
    │
    ▼
[Resolve permissions] → AuthorizationService.getGrantedForums(user, 'f_read')
    │                  → Compute permission-group hash
    ▼
[Check cache] → key = hash(normalized_ast + sort + permission_hash + page)
    │
    ├── HIT → return cached IDs
    │
    ▼ MISS
[Backend.search(ast, options)] → backend-specific execution
    │
    ▼
[Post-filter] → shadow ban filter, visibility edge cases
    │
    ▼
[Store in cache] → save with TTL + tags for invalidation
    │
    ▼
[Return SearchResult] → {ids[], totalCount, page, perPage, executionTimeMs}
```

---

## 5. Gaps and Uncertainties

### 5.1 Information Gaps

| Gap | Impact | Mitigation |
|-----|--------|-----------|
| **No performance benchmarks** for native backend at scale (>100K posts) | Can't validate TF-IDF overhead estimate | Benchmark during implementation; set performance budget |
| **Sphinx replacement strategy** unclear — support in new service? | Affects interface design (external service patterns) | Decision area: include or exclude Sphinx from initial scope |
| **CJK bigram tokenization** edge cases not fully explored | May affect East Asian forum deployments | Implement with comprehensive test suite; match legacy behavior first |
| **Multi-database support** — how to handle native backend on PostgreSQL? | Schema DDL differs; word table behavior may differ | Test native backend against both MySQL and PostgreSQL |
| **Permission change propagation timing** | If permissions change mid-request, cached results may be stale | Accept eventual consistency (short TTL on permission cache) |

### 5.2 Unverified Claims

| Claim | Source | Verification Needed |
|-------|--------|-------------------|
| "TF-IDF adds minimal overhead to native search" | Literature | Requires benchmarking with frequency column |
| "Permission-group hashing reduces fragmentation 80%+" | Literature §7.4 | Requires analysis of real forum permission data |
| "Static stopwords at index time are sufficient" | Literature §2.4 | Must analyze phpBB's multilingual deployment patterns |
| "Batch size 100 is optimal for index rebuild" | Admin-config | May need tuning per deployment (memory, execution time) |

### 5.3 Unresolved Inconsistencies

| Inconsistency | Details |
|---------------|---------|
| **Sphinx visibility hardcoded to ITEM_APPROVED** | All other backends support moderator visibility via `$post_visibility`. If Sphinx support continues, this limitation must be addressed. |
| **Native word length config vs MySQL/PG server-defined** | Native allows admin to change min/max; MySQL reads from server variables. The new service must handle both patterns. |
| **Event payload includes raw text (PostCreatedEvent)** vs **fetch from repository (PostRestoredEvent)** | Inconsistent data sourcing. Should the indexer always receive text in the event, or always fetch from repository? |

---

## 6. Synthesis by Framework

### Technical Analysis (Component + Pattern + Flow)

**Components identified**: SearchService orchestrator, QueryParser, TokenizationPipeline, SearchBackendInterface (4 implementations), CacheLayer, EventListeners, PermissionFilter, ShadowBanFilter, AdminController.

**Design patterns**: Strategy (backends), Pipeline (tokenization), Observer (event-driven indexing), Orchestrator (SearchService), Capability Declaration (BackendCapabilities), AST (query parsing).

**Critical flows**: (1) Search query execution (parsed → cached → delegated → filtered → returned), (2) Content indexing (event → tokenize → delegate to backend), (3) Index rebuild (batch iterate → per-post index → progress tracking → resumable).

### Requirements Analysis (Need + Constraint + Gap)

**Stated requirements**: Pluggable backends, word indexing, result caching, query parsing, complete isolation from legacy.

**Implicit requirements**: Rate limiting, load protection, admin management UI, index rebuild capability, backward-compatible search syntax, CJK support, permission filtering, shadow ban awareness.

**Constraints**: No queue infrastructure (shared hosting), PHP 8.2+ only, Symfony DI container, must work on both MySQL and PostgreSQL, synchronous request lifecycle.

**Gaps**: No stated requirement for relevance ranking (but strongly recommended), no stated requirement for Sphinx support (decision area).

---

## 7. Conclusions

### Primary Conclusions

1. **The backend contract is well-defined**: 15 methods across 4 backends converge on a clear interface. The new `SearchBackendInterface` should formalize this with ~10 methods (search, index, remove, rebuild, stats, capabilities, availability check, optimize, getName).

2. **Orchestrator pattern is the correct architecture**: Cross-cutting concerns (caching, permissions, rate limiting, shadow bans) belong in the orchestrator, not in backends. Legacy already partially implements this via `base.php`, but Sphinx breaks it.

3. **Event-driven indexing is validated**: The 8 Threads events provide complete coverage of all content lifecycle states. Synchronous dispatch is appropriate.

4. **Query parsing must be extracted and shared**: Legacy duplicates parsing logic across 4 backends. An AST-based parser with backend-specific translators is the clear improvement.

5. **Caching strategy should evolve**: Permission-group hashing provides the best balance of hit rate vs correctness. Moving away from LIKE-based invalidation to tag-based invalidation is essential for scale.

### Secondary Conclusions

6. The native backend's 3-table schema is sound but needs evolution (add `frequency` column for TF-IDF, consider adding `forum_id` to wordmatch for partition-like performance).

7. Backend-specific ACP configuration should be preserved through the `acp()` method pattern or equivalent — each backend has unique settings.

8. The 23 legacy extension events should NOT be replicated 1:1. Instead, provide 4-6 orchestrator-level events (`PreSearch`, `PostSearch`, `PreIndex`, `PostIndex`, `PreRebuild`, `PostRebuild`).

9. Sphinx support is architecturally distinct (external daemon, no base class, no caching). It may be better as a separate adapter package rather than a first-class backend.

10. Rate limiting and load protection should be middleware/decorator concerns, not embedded in the search controller.
