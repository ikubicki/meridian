# Legacy Search Backends Analysis

## 1. Common Interface (Extracted from All Backends)

All backends share these public methods, forming the implicit contract:

### Constructor
```php
public function __construct(&$error, $phpbb_root_path, $auth, $config, $db, $user, $phpbb_dispatcher)
```
- Receives dependencies via constructor injection (not DI container)
- Sets `$error = false` on success, or truthy on failure
- Loads UTF tools

### Accessor Methods
| Method | Return | Purpose |
|--------|--------|---------|
| `get_name()` | `string` | Display name for ACP |
| `get_search_query()` | `string` | Tidied search query shown to user |
| `get_common_words()` | `array` | Words filtered out by length limits |
| `get_word_length()` | `array\|false` | `['min' => int, 'max' => int]` (Sphinx returns `false`) |

### Core Search Methods
| Method | Signature | Purpose |
|--------|-----------|---------|
| `split_keywords($keywords, $terms)` | `(string, string): bool` | Parse user query into internal representation. `$terms` is `'all'` or `'any'`. Returns `true` if valid keywords found. |
| `keyword_search(...)` | `(...): bool\|int` | Execute keyword search, return result count or `false` |
| `author_search(...)` | `(...): bool\|int` | Execute author-only search, return result count or `false` |

### keyword_search / author_search Parameters
```php
keyword_search($type, $fields, $terms, $sort_by_sql, $sort_key, $sort_dir, 
               $sort_days, $ex_fid_ary, $post_visibility, $topic_id, 
               $author_ary, $author_name, &$id_ary, &$start, $per_page)

author_search($type, $firstpost_only, $sort_by_sql, $sort_key, $sort_dir,
              $sort_days, $ex_fid_ary, $post_visibility, $topic_id,
              $author_ary, $author_name, &$id_ary, &$start, $per_page)
```

Key parameter values:
- `$type`: `'posts'` | `'topics'`
- `$fields`: `'titleonly'` | `'msgonly'` | `'firstpost'` | `'all'`
- `$terms`: `'all'` | `'any'`
- `$sort_key`: `'a'`(author) | `'t'`(time) | `'f'`(forum) | `'i'`(subject) | `'s'`(subject)

### Indexing Methods
| Method | Signature | Purpose |
|--------|-----------|---------|
| `index($mode, $post_id, &$message, &$subject, $poster_id, $forum_id)` | void | Index or re-index a post |
| `index_remove($post_ids, $author_ids, $forum_ids)` | void | Remove posts from index |
| `tidy()` | void | Periodic cleanup (GC) |

### Index Management
| Method | Signature | Purpose |
|--------|-----------|---------|
| `init()` | `string\|bool` | Validate backend prerequisites, returns error lang key or `false` |
| `create_index($acp_module, $u_action)` | `string\|bool` | Create fulltext index |
| `delete_index($acp_module, $u_action)` | `string\|bool` | Drop fulltext index |
| `index_created()` | `bool` | Check if index exists |
| `index_stats()` | `array` | Return stats for ACP display |
| `acp()` | `array` | Return ACP template + config vars |

### Base Class Methods (Inherited)
| Method | Purpose |
|--------|---------|
| `obtain_ids($search_key, &$result_count, &$id_ary, &$start, $per_page, $sort_dir)` | Retrieve cached results by search_key |
| `save_ids($search_key, $keywords, $author_ary, $result_count, &$id_ary, $start, $sort_dir)` | Cache search results |
| `destroy_cache($words, $authors = false)` | Invalidate cache entries matching words/authors |

---

## 2. Per-Backend Detailed Analysis

### 2.1 Base Class (`\phpbb\search\base`)

**Source**: `src/phpbb/forums/search/base.php`

#### Caching System

**Cache Key**: `_search_results_` + MD5 search_key  
**Storage**: phpBB ACM cache (file-based by default)  
**TTL**: `config['search_store_results']` seconds

**Cache Structure** (stored array):
- Index `-1` → total result count
- Index `-2` → sort direction (`'a'` or `'d'`)
- Index `N` → post/topic ID at position N

**Cache Retrieval Logic** (`obtain_ids`):
1. Try getting from cache by search_key
2. If sort direction differs, reverse the array
3. Extract IDs for requested page window
4. Return `SEARCH_RESULT_IN_CACHE`, `SEARCH_RESULT_NOT_IN_CACHE`, or `SEARCH_RESULT_INCOMPLETE`

**Cache Save Logic** (`save_ids`):
1. Store or merge IDs into existing cache entry
2. Trim cache if > `20 * search_block_size` entries (sliding window)
3. Insert record into `SEARCH_RESULTS_TABLE` for tracking
4. Update `user_last_search` timestamp

**Cache Invalidation** (`destroy_cache`):
1. Find search results containing specific words (LIKE query on `search_keywords`)
2. Find search results matching specific author IDs
3. Destroy matching `_search_results_*` cache entries
4. Delete expired entries older than `search_store_results`

#### Database Tables Used
- `SEARCH_RESULTS_TABLE`: Tracks cached searches (search_key, search_time, search_keywords, search_authors)
- `USERS_TABLE`: Updates `user_last_search`

---

### 2.2 Native Fulltext (`\phpbb\search\fulltext_native`)

**Source**: `src/phpbb/forums/search/fulltext_native.php`  
**Extends**: `\phpbb\search\base`  
**Strategy**: Application-level inverted index using custom word tables

#### Configuration
| Config Key | Purpose | Default |
|-----------|---------|---------|
| `fulltext_native_min_chars` | Minimum word length to index | 3 |
| `fulltext_native_max_chars` | Maximum word length to index | 14 |
| `fulltext_native_load_upd` | Enable/disable index updates | 1 |
| `fulltext_native_common_thres` | % threshold to mark word as common | 5 |
| `max_num_search_keywords` | Max keywords per search | — |
| `search_block_size` | Results page block size | — |

#### Database Tables
| Table | Purpose |
|-------|---------|
| `SEARCH_WORDLIST_TABLE` | Dictionary: word_id, word_text, word_count, word_common |
| `SEARCH_WORDMATCH_TABLE` | Posting list: post_id, word_id, title_match |
| `SEARCH_RESULTS_TABLE` | Cached search results |
| `POSTS_TABLE` | Read for content filtering |
| `TOPICS_TABLE` | Join for topic-mode searches |
| `USERS_TABLE`, `FORUMS_TABLE` | Join for sort options |

#### Search Algorithm (`keyword_search`)

1. **Query Parsing** (`split_keywords`):
   - Tokenize with operators: `+` (must contain), `-` (must not), `|` (OR), `()` (groups), `*` (wildcard)
   - Look up word IDs from `SEARCH_WORDLIST_TABLE`
   - Skip common words (flagged `word_common = 1`)
   - Skip words outside min/max length (stored in `$this->common_words`)
   - Populate three arrays: `must_contain_ids`, `must_not_contain_ids`, `must_exclude_one_ids`
   - Limit keywords to `max_num_search_keywords`
   - Wildcards: `*` at end only → translates to `LIKE '%'` on word_text

2. **Search Execution**:
   - Generate MD5 search_key from all parameters
   - Check cache via `obtain_ids()`
   - Build complex JOIN query across `SEARCH_WORDMATCH_TABLE` (one alias per must-contain term)
   - `must_contain`: INNER JOIN per word (intersection)
   - `must_not_contain`: LEFT JOIN + IS NULL check
   - `must_exclude_one`: LEFT JOIN + `(IS NULL OR IS NULL)` check
   - Filter by: post_visibility, forum exclusion, sort_days, topic_id, author
   - Apply `title_match` filter for field-specific searches
   - Use `sql_query_limit` with `search_block_size`
   - Cache results via `save_ids()`

3. **Word Processing** (`split_message` / `cleanup`):
   - Strip BBCode tags and HTML
   - Normalize to NFC Unicode
   - Convert to lowercase
   - Split on non-alphanumeric boundaries
   - CJK/Hangul: each character indexed individually (space-separated)
   - Filter by min/max length (CJK exempt from min length)
   - Max word byte length: 255

#### Indexing Algorithm (`index`)

1. Split message and subject into word arrays
2. On `edit` mode: query existing word/post associations, compute diffs
3. On new post: all words are additions
4. Insert new words into `SEARCH_WORDLIST_TABLE` (if not exists)
5. Insert post↔word links into `SEARCH_WORDMATCH_TABLE`
6. Update `word_count` (+1 for adds, -1 for deletes)
7. Invalidate cache for affected words and authors

#### Index Removal (`index_remove`)
1. Query all word_ids linked to post_ids
2. Decrement `word_count` in wordlist
3. Delete all matches for post_ids from `SEARCH_WORDMATCH_TABLE`
4. Invalidate cache

#### Tidy / GC
1. Mark words as common if `word_count > num_posts * common_thres`
2. Delete wordmatch entries for common words
3. Invalidate cache

#### Query Syntax Support
| Syntax | Meaning |
|--------|---------|
| `word` or `+word` | Must contain |
| `-word` | Must not contain |
| `(word1\|word2)` | Must contain at least one |
| `-(word1\|word2)` | Must not contain at least one |
| `word*` | Wildcard (trailing only, single wildcard limit) |
| `word1 word2` | Both must be present (AND) |

---

### 2.3 MySQL Fulltext (`\phpbb\search\fulltext_mysql`)

**Source**: `src/phpbb/forums/search/fulltext_mysql.php`  
**Extends**: `\phpbb\search\base`  
**Strategy**: MySQL native FULLTEXT indexes with `MATCH ... AGAINST ... IN BOOLEAN MODE`

#### Configuration
| Config Key | Purpose |
|-----------|---------|
| `fulltext_mysql_min_word_len` | From MySQL `ft_min_word_len` / `innodb_ft_min_token_size` |
| `fulltext_mysql_max_word_len` | From MySQL `ft_max_word_len` / `innodb_ft_max_token_size` |
| `max_num_search_keywords` | Max keywords |
| `search_block_size` | Block size for results |

#### Database Usage
- **Reads**: `POSTS_TABLE` (FULLTEXT indexes on `post_subject`, `post_text`, combined `post_content`)
- **Index types**: 3 FULLTEXT indexes:
  - `post_subject` (subject only)
  - `post_text` (body only)
  - `post_content` (subject + text combined)
- **Engine requirement**: MyISAM, InnoDB (≥5.6.8), or Aria

#### Search Algorithm (`keyword_search`)

1. **Query Parsing** (`split_keywords`):
   - Convert natural language: `and` → `+`, `or` → `|`, `not` → `-`
   - Split on non-letter/non-number boundaries (Unicode-aware: `\p{L}\p{N}`)
   - Handle quoted phrases
   - Remove InnoDB-incompatible patterns: `+*`, `+-`, trailing `+/-`
   - Filter by min/max word length
   - Build boolean mode query: default words get `+` prefix (must-contain)
   - `'any'` mode: no prefix (any match)

2. **SQL Construction**:
   ```sql
   SELECT DISTINCT p.post_id  -- or t.topic_id
   FROM phpbb_posts p [, phpbb_topics t]
   WHERE MATCH(sql_match) AGAINST('search_query' IN BOOLEAN MODE)
     AND [visibility] AND [forum exclusions] AND [time filter]
   ORDER BY sort_column
   ```

3. **Field Mapping**:
   - `titleonly` → `MATCH(p.post_subject)`
   - `msgonly` → `MATCH(p.post_text)`
   - `firstpost` / `all` → `MATCH(p.post_subject, p.post_text)`

#### Indexing (`index`)
- **No custom indexing logic** — destroys cache only
- MySQL maintains FULLTEXT index automatically on INSERT/UPDATE/DELETE
- `split_message()` used only for cache invalidation word list

#### Index Management
- `create_index()`: `ALTER TABLE phpbb_posts ADD FULLTEXT (post_subject)`, etc.
- `delete_index()`: `ALTER TABLE phpbb_posts DROP INDEX post_subject`, etc.
- Requires `utf8_unicode_ci` collation

---

### 2.4 PostgreSQL Fulltext (`\phpbb\search\fulltext_postgres`)

**Source**: `src/phpbb/forums/search/fulltext_postgres.php`  
**Extends**: `\phpbb\search\base`  
**Strategy**: PostgreSQL `tsvector` / `tsquery` with GIN indexes

#### Configuration
| Config Key | Purpose |
|-----------|---------|
| `fulltext_postgres_min_word_len` | Minimum word length |
| `fulltext_postgres_max_word_len` | Maximum word length |
| `fulltext_postgres_ts_name` | Text search configuration name (e.g., `'english'`, `'simple'`) |

#### Database Usage
- **Reads**: `POSTS_TABLE` with `to_tsvector() @@ to_tsquery()` operations
- **GIN Indexes**:
  - `{POSTS_TABLE}_{ts_name}_post_subject` → `to_tsvector(ts_name, post_subject)`
  - `{POSTS_TABLE}_{ts_name}_post_content` → `to_tsvector(ts_name, post_text)`
  - `{POSTS_TABLE}_{ts_name}_post_subject_content` → `to_tsvector(ts_name, post_subject || ' ' || post_text)`

#### Search Algorithm

1. **Query Parsing** (`split_keywords`):
   - Similar Unicode-aware splitting as MySQL
   - Builds two parallel queries:
     - `$this->search_query` — display version
     - `$this->tsearch_query` — PostgreSQL tsquery operators: `&` (AND), `|` (OR), `!` (NOT)
   - Operator mapping: `+word` → `&word`, `-word` → `&!word`, `|word` → `|word`

2. **SQL Construction**:
   ```sql
   SELECT p.post_id
   FROM phpbb_posts p
   WHERE to_tsvector('english', post_subject || ' ' || post_text) 
         @@ to_tsquery('english', 'word1 & word2 & !word3')
     AND [filters]
   ORDER BY sort
   ```

3. **Phrase Search**: Not supported (`$this->phrase_search = false`)

4. **Transactions**: Uses `sql_transaction('begin'/'commit')` around search queries

#### Indexing (`index`)
- No custom indexing — PostgreSQL maintains tsvector indexes automatically
- Only destroys cache on content change

---

### 2.5 Sphinx Fulltext (`\phpbb\search\fulltext_sphinx`)

**Source**: `src/phpbb/forums/search/fulltext_sphinx.php`  
**Does NOT extend**: `\phpbb\search\base` (standalone class)  
**Strategy**: External Sphinx searchd daemon via SphinxClient API

#### Configuration
| Config Key | Purpose |
|-----------|---------|
| `fulltext_sphinx_host` | Sphinx server hostname (default: `'localhost'`) |
| `fulltext_sphinx_port` | Sphinx server port (default: `9312`) |
| `fulltext_sphinx_data_path` | Path to Sphinx data directory |
| `fulltext_sphinx_indexer_mem_limit` | Indexer memory limit in MB |
| `fulltext_sphinx_id` | Unique identifier for this phpBB installation |

#### Constants
```php
SPHINX_MAX_MATCHES = 20000
SPHINX_CONNECT_RETRIES = 3
SPHINX_CONNECT_WAIT_TIME = 300  // microseconds
```

#### Database Usage
- **Own table**: `SPHINX_TABLE` (counter_id, max_doc_id) — tracks delta indexing boundary
- **Sphinx SQL source** pulls from: `POSTS_TABLE` + `TOPICS_TABLE`
- **Indexed fields**: `post_subject` (as `title`), `post_text` (as `data`)
- **Filter attributes**: `forum_id`, `topic_id`, `poster_id`, `post_visibility`, `topic_first_post`, `post_time`, `topic_last_post_time`, `deleted`

#### Search Algorithm (`keyword_search`)

1. **Query Parsing** (`split_keywords`):
   - Minimal parsing — delegates to Sphinx extended query syntax
   - `'all'` mode: `SPH_MATCH_EXTENDED` + replaces `or`→`|`, `not`→`-`
   - `'any'` mode: `SPH_MATCH_ANY` + strips special chars
   - `sphinx_clean_search_string()`: handles hyphenated words, apostrophes, quorum (`/N`), proximity (`~N`), strict order (`<<`), exact match (`=`)

2. **Sphinx API calls**:
   - `SetSortMode()` / `SetGroupBy()` for sorting/grouping
   - `SetFilter('topic_id', ...)`, `SetFilter('forum_id', ...)`, etc.
   - `SetFilter('post_visibility', [ITEM_APPROVED])` — hardcoded approved-only
   - `SetFilter('deleted', [0])` — exclude soft-deleted
   - `SetFieldWeights(['title' => 5, 'data' => 1])` for relevance
   - `SetLimits($start, $per_page, max(SPHINX_MAX_MATCHES, ...))`
   - `Query($search_query, $indexes)` — executes search

3. **Field-specific**:
   - `titleonly`: `@title` prefix + filter `topic_first_post = 1`
   - `msgonly`: `@data` prefix
   - `firstpost`: filter `topic_first_post = 1`

4. **Retry logic**: 3 retries on connection failure (errno=111)

5. **No caching**: Does not use `obtain_ids`/`save_ids` (no base class inheritance)

#### Author Search
Delegates to `keyword_search()` with `SPH_MATCH_FULLSCAN` mode and empty query.

#### Indexing (`index`)
- On `edit`: calls `sphinx->UpdateAttributes()` to update `forum_id`, `poster_id`
- On `reply`/`quote`: updates `topic_last_post_time` for all posts in topic
- On `post`: no action (Sphinx delta picks up new posts)
- **No actual text re-indexing** — relies on Sphinx's periodic re-indexing

#### Index Removal
- Updates `deleted` attribute to `1` via `UpdateAttributes()`
- Post not actually removed until Sphinx re-indexes

#### Delta Indexing Architecture
- Main index: all posts up to `max_doc_id`
- Delta index: posts with `post_id >= max_doc_id`
- Search queries both: `index_phpbb_{id}_delta;index_phpbb_{id}_main`
- GC (`search_gc`) set to 3600s (merge delta to main hourly)

---

## 3. Comparative Matrix

### Features × Backends

| Feature | Native | MySQL | PostgreSQL | Sphinx |
|---------|--------|-------|-----------|--------|
| **Extends base** | ✅ | ✅ | ✅ | ❌ |
| **Result caching** | ✅ (ACM) | ✅ (ACM) | ✅ (ACM) | ❌ |
| **Own word tables** | ✅ | ❌ | ❌ | ❌ |
| **Auto-indexing** | ❌ (manual) | ✅ (DB engine) | ✅ (DB engine) | Partial (delta) |
| **Phrase search** | ❌ | ✅ (quotes) | ❌ | ✅ (quotes) |
| **Wildcard search** | ✅ (trailing `*`) | ✅ (trailing `*`) | ❌ | ✅ (prefix/infix) |
| **Boolean operators** | ✅ (+/-/\|/()) | ✅ (Boolean mode) | ✅ (tsquery) | ✅ (Extended) |
| **Relevance ranking** | ❌ | MySQL native | PostgreSQL native | ✅ (BM25/SPH) |
| **CJK support** | ✅ (char-by-char) | Depends on config | Via pg tsconfig | Via charset_table |
| **External service** | ❌ | ❌ | ❌ | ✅ (searchd) |
| **DB requirement** | Any | MySQL/MariaDB | PostgreSQL | MySQL or PostgreSQL |
| **Minimum word len** | Configurable | From MySQL vars | Configurable | Via sphinx.conf |
| **Common word filtering** | ✅ (threshold %) | MySQL built-in | PG stopwords | Sphinx stopwords |
| **Soft-delete aware** | Via post_visibility SQL | Via post_visibility SQL | Via post_visibility SQL | `deleted` attribute |

### Performance Characteristics

| Aspect | Native | MySQL | PostgreSQL | Sphinx |
|--------|--------|-------|-----------|--------|
| **Index size** | Large (word tables) | Part of table | Part of table | External files |
| **Index update cost** | High (multiple queries) | Low (automatic) | Low (automatic) | Low (attribute update) |
| **Search complexity** | O(n) JOINs per keyword | Single MATCH query | Single tsquery | Single API call |
| **Scalability** | Poor (>100k posts) | Moderate | Good | Excellent |
| **Memory usage** | Low | MySQL buffer pool | PostgreSQL shared_buffers | Dedicated (configurable) |

---

## 4. Extracted Business Rules

### Search Constraints
1. **Empty query prohibition**: All backends return `false` if no valid keywords found
2. **Negation-only prohibition**: Native/PG reject queries with only negative terms (`must_contain_ids` must be non-empty)
3. **Keyword limit**: `max_num_search_keywords` config caps the number of terms (triggers error if exceeded)
4. **Word length filtering**: Words outside `[min, max]` range are silently dropped into `common_words`
5. **Page boundary correction**: If `$start >= $result_count`, auto-adjust to last valid page
6. **Block-based retrieval**: Results fetched in blocks of `search_block_size`, not entire result set

### Caching Rules
1. **Search key = MD5 hash** of all parameters (words, type, fields, terms, sort, forums, visibility, authors)
2. **Bidirectional cache**: Same cache entry serves both ASC and DESC (reversed on retrieval)
3. **Cache invalidation triggers**: Any post create/edit/delete invalidates caches containing affected words or authors
4. **Cache expiry**: `search_store_results` seconds (configurable)
5. **Cache size cap**: Max `20 * search_block_size` entries per search_key

### Indexing Rules
1. **Edit mode differential**: Only process word adds/removes vs. current index
2. **Word count tracking** (native only): Maintained via +1/-1 on word_count
3. **Common word threshold** (native): Words in >`common_thres`% of posts are marked common and excluded from matching
4. **Disabled indexer** (native): If `fulltext_native_load_upd = 0`, index() is a no-op
5. **Post removal**: Native removes wordmatch entries; MySQL/PG rely on DB auto-update; Sphinx marks as deleted

### Content Processing
1. **BBCode stripping**: Remove `[code]...[/code]`, all BBcode tags before indexing
2. **HTML entity decode**: Convert entities back to characters
3. **Unicode normalization**: NFC normalization
4. **Case folding**: Lowercase ASCII (other scripts via Unicode conv tables)
5. **CJK handling** (native): Each CJK/Hangul character = separate indexed word (space-separated)
6. **Max word bytes**: 255 (native only, limited by DB column)

### Visibility & Authorization
1. **post_visibility**: SQL condition passed in from calling code, applied as WHERE clause
2. **Forum exclusion**: `$ex_fid_ary` — forums to NOT search (inverse of allowed forums)
3. **Sphinx limitation**: Hardcoded to `ITEM_APPROVED` only (cannot search unapproved/soft-deleted even as moderator)

---

## 5. Extension Points Found in Code

### Events in Native Backend
| Event | Trigger Point | Key Variables |
|-------|--------------|---------------|
| `core.search_native_by_keyword_modify_search_key` | Before keyword search cache check | search_key_array, type, fields, terms, sort params |
| `core.search_native_keywords_count_query_before` | Before count query execution | sql_array, must_*_ids, total_results, author_ary |
| `core.search_native_by_author_modify_search_key` | Before author search cache check | search_key_array, type, sort params |
| `core.search_native_author_count_query_before` | Before author count query | select, sql_sort_*, sql_author, type |
| `core.search_native_index_before` | Before updating word index | mode, post_id, message, subject, words, split_text/title, cur_words |
| `core.search_native_delete_index_before` | Before dropping index | sql_queries, stats |

### Events in MySQL Backend
| Event | Trigger Point |
|-------|--------------|
| `core.search_mysql_by_keyword_modify_search_key` | Before keyword cache check |
| `core.search_mysql_keywords_main_query_before` | Before main keyword SQL |
| `core.search_mysql_by_author_modify_search_key` | Before author cache check |
| `core.search_mysql_author_query_before` | Before author SQL |
| `core.search_mysql_index_before` | Before index update (cache destroy) |
| `core.search_mysql_create_index_before` | Before CREATE INDEX |
| `core.search_mysql_delete_index_before` | Before DROP INDEX |

### Events in PostgreSQL Backend
| Event | Trigger Point |
|-------|--------------|
| `core.search_postgres_by_keyword_modify_search_key` | Before keyword cache check |
| `core.search_postgres_keywords_main_query_before` | Before main keyword SQL |
| `core.search_postgres_by_author_modify_search_key` | Before author cache check |
| `core.search_postgres_author_count_query_before` | Before author count SQL |
| `core.search_postgres_index_before` | Before index update |
| `core.search_postgres_create_index_before` | Before CREATE INDEX |
| `core.search_postgres_delete_index_before` | Before DROP INDEX |

### Events in Sphinx Backend
| Event | Trigger Point |
|-------|--------------|
| `core.search_sphinx_keywords_modify_options` | Before Sphinx query execution (modify filters/sort) |
| `core.search_sphinx_modify_config_data` | During sphinx.conf generation |
| `core.search_sphinx_index_before` | Before index attribute update |

### Extension Pattern
All events use `extract($this->phpbb_dispatcher->trigger_event('event.name', compact($vars)))` pattern, which allows extensions to modify variables by reference in `$vars` array.

---

## 6. Key Observations for New Service Design

1. **No formal interface/contract**: The search backend "interface" is implicit — there's no PHP interface or abstract method declarations. New service should define explicit `SearchBackendInterface`.

2. **Tight coupling to global state**: Native backend accesses `$phpbb_app_container` globally in base class. New service should use proper DI.

3. **Result pagination is internal**: The search method directly handles `$start`, `$per_page`, and cache-aware pagination. Could be extracted to a separate pagination layer.

4. **Cache strategy varies**: Sphinx doesn't cache at all; others use identical ACM-based caching. A unified caching layer could abstract this.

5. **Indexing responsibility split**: MySQL/PG don't actually index (DB does it), Sphinx partially indexes (attribute updates only), Native fully manages its own index. The new interface should clarify which operations are real vs. no-ops.

6. **Event naming inconsistency**: Each backend has its own set of similarly-named but separate events. A unified event system would reduce extension complexity.

7. **Visibility model limitation**: Sphinx hardcodes ITEM_APPROVED. The new service should handle visibility uniformly across all backends.

8. **sort_by_sql array**: Passed in from the caller — contains raw SQL fragments for each possible sort type. The new service should encapsulate sorting properly.
