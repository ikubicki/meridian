# Research Sources — Search Service

## Category 1: Legacy Backends

### Core Search Module
- `src/phpbb/forums/search/base.php` — Base class with shared caching (obtain_ids, save_ids), synonym/ignore word loading
- `src/phpbb/forums/search/fulltext_native.php` — PHP-native inverted index using wordlist/wordmatch tables
- `src/phpbb/forums/search/fulltext_mysql.php` — MySQL FULLTEXT index backend
- `src/phpbb/forums/search/fulltext_postgres.php` — PostgreSQL tsquery/tsvector backend
- `src/phpbb/forums/search/fulltext_sphinx.php` — Sphinx search engine integration

### Search Entry Point
- `web/search.php` — Front controller for search requests (query handling, result display)

### UTF/Tokenization Support
- `src/phpbb/common/utf/data/search_indexer_*.php` — Unicode normalization tables for word splitting

### Key Patterns to Extract
- Public method signatures (keyword_search, author_search, index, delete_index)
- Constructor dependencies
- Configuration consumption
- Permission filtering approach
- Error handling patterns

## Category 2: Database Schema

### SQL Dump (DDL)
- `phpbb_dump.sql` lines 3169–3250 — All 3 search tables:
  - `phpbb_search_results` (line 3175) — Cached search results keyed by search_key hash
  - `phpbb_search_wordlist` (line 3200) — Word dictionary (word_id, word_text, word_common, word_count)
  - `phpbb_search_wordmatch` (line 3230) — Post-word mapping (post_id, word_id, title_match)

### Schema Details to Capture
- Column types, sizes, defaults
- Index definitions (PRIMARY, UNIQUE, KEY)
- Foreign key relationships (implicit via post_id, word_id)
- Engine and charset settings

### Config Table References
- `phpbb_dump.sql` line 1329 — `search_store_results` = 1800 (cache TTL in seconds)
- `phpbb_dump.sql` line 1330 — `search_type` = `\phpbb\search\fulltext_native` (active backend)

## Category 3: Cross-Service Contracts

### Threads Service HLD
- `.maister/tasks/research/2026-04-18-threads-service/outputs/high-level-design.md`
- **Events to consume**: PostCreated, PostDeleted, PostEdited, VisibilityChanged (soft-delete/approve)
- **Data needed**: post_id, topic_id, forum_id, post_text, post_subject, poster_id, post_visibility

### Auth Service HLD
- `.maister/tasks/research/2026-04-18-auth-service/outputs/high-level-design.md`
- **Integration**: forum-level read permission (f_read, f_search ACL options)
- **Contract needed**: Check if user can search in forum(s)

### Hierarchy Service HLD
- `.maister/tasks/research/2026-04-18-hierarchy-service/outputs/high-level-design.md`
- **Integration**: Forum scoping — get all forum_ids user has access to, forum tree traversal for sub-forum inclusion

### Users Service HLD
- `.maister/tasks/research/2026-04-19-users-service/outputs/high-level-design.md`
- **Integration**: Shadow ban filtering via ShadowBanService::isShadowBanned()
- **Contract needed**: Filter results by non-shadow-banned authors

### Cache Service HLD
- `.maister/tasks/research/2026-04-19-cache-service/outputs/high-level-design.md`
- **Integration**: Result caching pattern, cache key generation, TTL management, invalidation strategy

### Notifications Service HLD
- `.maister/tasks/research/2026-04-19-notifications-service/outputs/high-level-design.md`
- **Reference**: Event subscription patterns, EventDispatcher usage

### ACL Options (DB Dump)
- `phpbb_dump.sql` line 141 — `f_search` (forum-level search permission)
- `phpbb_dump.sql` line 197 — `a_search` (admin search permission)
- `phpbb_dump.sql` line 235 — `u_search` (user-level search permission)

## Category 4: Patterns & Literature

### Modern Search Design
- Inverted index architecture: posting lists, term frequency, document frequency
- Tokenization pipeline: normalization → splitting → stop-word removal → stemming → lowercasing
- Query parsing: boolean operators (AND/OR/NOT), phrase matching, wildcard support, operator precedence
- Relevance scoring: TF-IDF, BM25 basics

### Caching Strategies
- Result set caching (keyed by normalized query + params)
- Query plan caching
- Incremental invalidation on document change vs full rebuild

### Plugin/Backend Architecture Patterns
- Strategy pattern for swappable backends
- Interface segregation (Indexer vs Searcher vs Admin)
- DecoratorPipeline for cross-cutting concerns (permission filtering, shadow ban, caching)
- Factory pattern for backend instantiation from config

### Event-Driven Indexing
- Async vs sync indexing trade-offs
- Event sourcing for index consistency
- Batch indexing / rebuild patterns

### Reference Resources
- MySQL FULLTEXT documentation: indexing modes (natural language, boolean), min word length, stopwords
- PostgreSQL full-text search: tsvector, tsquery, ranking functions, dictionary configuration
- Sphinx/Manticore: real-time indexes, distributed search, attribute filtering

## Category 5: Admin Configuration

### ACP Module Files
- `src/phpbb/common/acp/acp_search.php` — Admin search management (backend switch, index rebuild, settings form)
- `src/phpbb/common/acp/info/acp_search.php` — Module info registration
- `web/adm/style/acp_search.html` — Admin template for search settings

### Config Keys (from DB dump and code)
- `search_type` — Active backend class (default: `\phpbb\search\fulltext_native`)
- `search_store_results` — Result cache TTL in seconds (default: 1800)
- Expected additional keys (to verify in code):
  - `min_search_chars` — Minimum word length for indexing
  - `max_search_chars` — Maximum word length for indexing
  - `search_interval` — Flood control between searches
  - `search_anonymous_interval` — Flood control for guests
  - `search_block_size` — Batch size for index rebuild
  - `load_search` — Enable/disable search globally

### Features to Document
- Backend switching workflow (drop old index, create new)
- Index rebuild mechanism (batched processing, progress tracking)
- Search statistics display
- Word list management (common words threshold)
