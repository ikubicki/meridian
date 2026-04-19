# Database Schema Analysis — phpBB Search System

## 1. Table Definitions (Full DDL)

### 1.1 `phpbb_search_results` — Search Results Cache

```sql
CREATE TABLE `phpbb_search_results` (
  `search_key` varchar(32) NOT NULL DEFAULT '',   -- MD5 hash of search parameters
  `search_time` int(11) unsigned NOT NULL DEFAULT 0,  -- UNIX timestamp of last access
  `search_keywords` mediumtext NOT NULL,          -- space-separated keywords (for cache invalidation)
  `search_authors` mediumtext NOT NULL,           -- space-separated author IDs (for cache invalidation)
  PRIMARY KEY (`search_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

**Purpose**: Caches search result metadata so that result ID arrays (stored in phpBB's file/memory cache) can be invalidated when posts containing those keywords or by those authors are modified.

### 1.2 `phpbb_search_wordlist` — Word Dictionary (Native Backend Only)

```sql
CREATE TABLE `phpbb_search_wordlist` (
  `word_id` int(10) unsigned NOT NULL AUTO_INCREMENT,  -- Unique word ID
  `word_text` varchar(255) NOT NULL DEFAULT '',        -- The actual word (lowercased, cleaned)
  `word_common` tinyint(1) unsigned NOT NULL DEFAULT 0, -- Flag: 1 = common word (excluded from search)
  `word_count` mediumint(8) unsigned NOT NULL DEFAULT 0, -- Number of posts containing this word
  PRIMARY KEY (`word_id`),
  UNIQUE KEY `wrd_txt` (`word_text`),  -- Fast lookup by word text
  KEY `wrd_cnt` (`word_count`)         -- For finding common words (tidy operation)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

**Purpose**: Dictionary of all indexed words. Each unique word gets one row. `word_count` tracks document frequency for common word detection.

### 1.3 `phpbb_search_wordmatch` — Word-to-Post Mapping (Native Backend Only)

```sql
CREATE TABLE `phpbb_search_wordmatch` (
  `post_id` int(10) unsigned NOT NULL DEFAULT 0,       -- FK to phpbb_posts.post_id
  `word_id` int(10) unsigned NOT NULL DEFAULT 0,       -- FK to phpbb_search_wordlist.word_id
  `title_match` tinyint(1) unsigned NOT NULL DEFAULT 0, -- 0 = body match, 1 = subject match
  UNIQUE KEY `un_mtch` (`word_id`, `post_id`, `title_match`),  -- Prevents duplicates
  KEY `word_id` (`word_id`),   -- For finding all posts with a word
  KEY `post_id` (`post_id`)    -- For finding all words in a post (used during edit/delete)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

**Purpose**: Inverted index mapping words to posts. The `title_match` flag enables searching title-only or body-only.

---

## 2. Backend-Table Usage Matrix

| Table | Native | MySQL FULLTEXT | PostgreSQL | Sphinx |
|-------|--------|---------------|------------|--------|
| `phpbb_search_wordlist` | ✅ Read/Write (dictionary) | ❌ Not used | ❌ Not used | ❌ Not used |
| `phpbb_search_wordmatch` | ✅ Read/Write (inverted index) | ❌ Not used | ❌ Not used | ❌ Not used |
| `phpbb_search_results` | ✅ Cache invalidation | ✅ Cache invalidation | ✅ Cache invalidation | ❌ Not used |
| `phpbb_posts` | ✅ Read (JOIN in queries) | ✅ Read (FULLTEXT match) | ✅ Read (tsvector match) | ✅ Read (via Sphinx SQL source) |
| `phpbb_topics` | ✅ Read (JOIN for topic search) | ✅ Read (JOIN for topic search) | ✅ Read (JOIN for topic search) | ✅ Read (via Sphinx SQL source) |
| `phpbb_sphinx` | ❌ | ❌ | ❌ | ✅ Read/Write (max_doc_id counter) |

**Key Insight**: Only the **native** backend uses the 3 dedicated search tables. MySQL/PostgreSQL backends rely on database-engine-native indexing on `phpbb_posts` directly. Sphinx uses an external daemon with its own index files.

---

## 3. Post Table Integration

### Columns Read During Search/Indexing

| Column | Used By | Purpose |
|--------|---------|---------|
| `post_id` | All backends | Primary key, returned as search result |
| `topic_id` | All backends | Topic grouping for topic-level search |
| `forum_id` | All backends | Forum filtering (exclude forums) |
| `poster_id` | All backends | Author filtering |
| `post_time` | All backends | Date range filtering |
| `post_subject` | All backends | Title search field |
| `post_text` | All backends | Body search field |
| `post_visibility` | All backends | Filter approved/deleted/unapproved |
| `bbcode_uid` | Native (indirectly) | Stripped during text cleanup before indexing |

### Sphinx Additional Columns

Sphinx indexes these additional attributes via its SQL source query:
- `topic_first_post_id` (computed: `CASE WHEN p.post_id = t.topic_first_post_id THEN 1 ELSE 0 END`)
- `topic_last_post_time` (from topics table)
- `deleted` flag (virtual, set via `UpdateAttributes`)

---

## 4. SQL Query Patterns (Key Queries per Backend)

### 4.1 Native Backend

#### Keyword Search (core query structure)
```sql
SELECT DISTINCT p.post_id  -- or p.topic_id for topic search
FROM phpbb_search_wordmatch m0, phpbb_search_wordmatch m1, ...,
     phpbb_search_wordlist w0, ...
LEFT JOIN phpbb_posts p ON m0.post_id = p.post_id
LEFT JOIN phpbb_topics t ON p.topic_id = t.topic_id  -- when topic search
WHERE m0.word_id = {id}
  AND m1.word_id = {id}
  AND m1.post_id = m0.post_id      -- join multiple must-contain words
  AND m0.title_match = 1           -- when titleonly
  AND {post_visibility}
  AND p.forum_id NOT IN (...)      -- excluded forums
  -- NOT-contain via LEFT JOIN + IS NULL pattern
ORDER BY ...
```

#### Indexing (post creation)
```sql
-- 1. Find existing word IDs
SELECT word_id, word_text FROM phpbb_search_wordlist
WHERE word_text IN ('word1', 'word2', ...)

-- 2. Insert new words
INSERT INTO phpbb_search_wordlist (word_text, word_count) VALUES ('newword', 0), ...

-- 3. Create word-post associations
INSERT INTO phpbb_search_wordmatch (post_id, word_id, title_match)
SELECT {post_id}, word_id, {0|1}
FROM phpbb_search_wordlist WHERE word_text IN (...)

-- 4. Increment word counts
UPDATE phpbb_search_wordlist SET word_count = word_count + 1
WHERE word_text IN (...)
```

#### Index Removal (post deletion)
```sql
-- 1. Get word IDs for the post
SELECT w.word_id, w.word_text, m.title_match
FROM phpbb_search_wordmatch m, phpbb_search_wordlist w
WHERE m.post_id IN (...) AND w.word_id = m.word_id

-- 2. Decrement counts
UPDATE phpbb_search_wordlist SET word_count = word_count - 1
WHERE word_id IN (...) AND word_count > 0

-- 3. Remove associations
DELETE FROM phpbb_search_wordmatch WHERE post_id IN (...)
```

#### Tidy (common word removal)
```sql
-- Find common words (word_count > total_posts * threshold)
SELECT word_id, word_text FROM phpbb_search_wordlist
WHERE word_count > {floor(num_posts * common_threshold)}
   OR word_common = 1

-- Flag as common
UPDATE phpbb_search_wordlist SET word_common = 1 WHERE word_id IN (...)

-- Remove match entries for common words
DELETE FROM phpbb_search_wordmatch WHERE word_id IN (...)
```

### 4.2 MySQL FULLTEXT Backend

#### Keyword Search
```sql
SELECT DISTINCT p.post_id  -- or t.topic_id
FROM phpbb_topics t, phpbb_posts p
WHERE MATCH (p.post_subject, p.post_text) AGAINST ('{query}' IN BOOLEAN MODE)
  AND t.topic_id = p.topic_id
  AND p.post_visibility = {approved}
  AND p.forum_id NOT IN (...)
ORDER BY ...
LIMIT {block_size} OFFSET {start}
```

#### Index Creation
```sql
ALTER TABLE phpbb_posts MODIFY post_subject varchar(255) COLLATE utf8_unicode_ci DEFAULT '' NOT NULL,
  ADD FULLTEXT (post_subject);
ALTER TABLE phpbb_posts ADD FULLTEXT post_content (post_text, post_subject);
ALTER TABLE phpbb_posts ADD FULLTEXT post_text (post_text);
```

**Three FULLTEXT indexes created:**
1. `post_subject` — title-only search
2. `post_content` — combined subject+text (default search)
3. `post_text` — body-only search

#### Index Method (no-op for search tables)
The MySQL backend's `index()` method does NOT write to any search tables. It only calls `destroy_cache()` to invalidate cached results. MySQL's FULLTEXT index is maintained automatically by InnoDB.

### 4.3 PostgreSQL Backend

#### Keyword Search
```sql
SELECT p.post_id  -- or DISTINCT t.topic_id
FROM phpbb_topics t, phpbb_posts p
WHERE (to_tsvector('{ts_config}', p.post_subject || ' ' || p.post_text)
       @@ to_tsquery('{ts_config}', '{tsearch_query}'))
  AND t.topic_id = p.topic_id
  AND p.post_visibility = ...
  AND p.forum_id NOT IN (...)
ORDER BY ...
LIMIT {block_size} OFFSET {start}
```

#### Index Creation (3 GIN indexes on phpbb_posts)
```sql
CREATE INDEX phpbb_posts_{ts_name}_post_subject
  ON phpbb_posts USING gin (to_tsvector('{ts_name}', post_subject));

CREATE INDEX phpbb_posts_{ts_name}_post_content
  ON phpbb_posts USING gin (to_tsvector('{ts_name}', post_text));

CREATE INDEX phpbb_posts_{ts_name}_post_subject_content
  ON phpbb_posts USING gin (to_tsvector('{ts_name}', post_subject || ' ' || post_text));
```

#### Index Method (no-op for search tables)
Like MySQL, the PostgreSQL backend's `index()` method only calls `destroy_cache()`.

### 4.4 Sphinx Backend

#### Keyword Search (via SphinxClient API)
```php
$this->sphinx->SetFilter('topic_id', array($topic_id));
$this->sphinx->SetFilter('poster_id', $author_ary);
$this->sphinx->SetFilter('post_visibility', array(ITEM_APPROVED));
$this->sphinx->SetFilter('deleted', array(0));
$this->sphinx->SetFilter('forum_id', $search_forums);
$this->sphinx->SetFieldWeights(array("title" => 5, "data" => 1));
$this->sphinx->SetLimits($start, $per_page, SPHINX_MAX_MATCHES);
$result = $this->sphinx->Query($search_query, $this->indexes);
```

#### Index Source SQL (executed by Sphinx indexer daemon)
```sql
SELECT
    p.post_id AS id,
    p.forum_id, p.topic_id, p.poster_id, p.post_visibility,
    CASE WHEN p.post_id = t.topic_first_post_id THEN 1 ELSE 0 END as topic_first_post,
    p.post_time, p.post_subject,
    p.post_subject as title,
    p.post_text as data,
    t.topic_last_post_time,
    0 as deleted
FROM phpbb_posts p, phpbb_topics t
WHERE p.topic_id = t.topic_id
  AND p.post_id >= $start AND p.post_id <= $end
```

#### Index Update (on post edit)
```php
$this->sphinx->UpdateAttributes($this->indexes, array('forum_id', 'poster_id'),
    array($post_id => array($forum_id, $poster_id)));
```

#### Index Remove (soft delete via attribute)
```php
$this->sphinx->UpdateAttributes($this->indexes, array('deleted'), array($post_id => array(1)));
```

---

## 5. Caching Schema

### `phpbb_search_results` Table Structure

| Column | Type | Purpose |
|--------|------|---------|
| `search_key` | varchar(32), PK | MD5 hash of all search parameters (keywords, type, sort, filters) |
| `search_time` | int unsigned | UNIX timestamp, updated on each access; used for TTL-based cleanup |
| `search_keywords` | mediumtext | Space-separated keywords — used for LIKE-based cache invalidation |
| `search_authors` | mediumtext | Space-separated author IDs (format: ` 123 456 `) — used for cache invalidation |

### Cache Flow

1. **Search key generation**: `md5(implode('#', $search_params))` — deterministic hash of all parameters
2. **Cache check**: `$cache->get('_search_results_' . $search_key)` (file/memory cache stores actual post IDs)
3. **Cache store**: On first search, IDs array stored in phpBB cache + metadata row in `search_results` table
4. **Cache invalidation**: When a post is created/edited/deleted:
   - Find all `search_key` rows where `search_keywords LIKE '%word%'` or `search_authors LIKE '% author_id %'`
   - Destroy corresponding cache entries
5. **TTL cleanup**: `DELETE FROM phpbb_search_results WHERE search_time < (now - config['search_store_results'])`

### Configuration

- `search_store_results` — cache lifetime in seconds (config value)
- `search_block_size` — number of result IDs fetched per DB query (pagination block)
- Cache array structure: `[-1 => total_count, -2 => sort_dir, 0 => id, 1 => id, ...]`

---

## 6. Index Characteristics

### Native Backend — Inverted Index

| Characteristic | Detail |
|----------------|--------|
| **Index type** | Custom inverted index via `wordlist` + `wordmatch` tables |
| **Word min length** | Configurable: `fulltext_native_min_word_len` (default: 3) |
| **Word max length** | Configurable: `fulltext_native_max_word_len` (default: 14) |
| **Common word threshold** | `fulltext_native_common_thres` (percentage, e.g., 5% of total posts) |
| **Common word behavior** | Flagged `word_common = 1`, all wordmatch entries deleted |
| **Wildcard support** | Yes, via `*` → SQL `LIKE '%...'` on `word_text` |
| **CJK support** | Yes, handles Hangul/CJK character ranges with bigram splitting |
| **Storage overhead** | High — one row per word-post-position combination |
| **Typical scale** | `word_count` in wordlist tracks document frequency per word |

### MySQL FULLTEXT

| Characteristic | Detail |
|----------------|--------|
| **Index type** | InnoDB FULLTEXT (B-tree inverted index) |
| **Min word length** | `fulltext_mysql_min_word_len` (typically `ft_min_word_len`, default 4) |
| **Max word length** | `fulltext_mysql_max_word_len` (typically 84) |
| **Boolean mode** | Yes, `IN BOOLEAN MODE` with `+`, `-`, `*`, `"..."` operators |
| **Collation requirement** | `utf8_unicode_ci` (modified during `create_index`) |
| **Storage overhead** | Low — managed internally by MySQL engine |

### PostgreSQL ts_vector

| Characteristic | Detail |
|----------------|--------|
| **Index type** | GIN (Generalized Inverted Index) on `to_tsvector()` expressions |
| **Text search config** | Configurable: `fulltext_postgres_ts_name` (e.g., `'english'`, `'simple'`) |
| **Phrase search** | NOT supported (`$phrase_search = false`) |
| **Query syntax** | `to_tsquery()` with `&` (AND) and `|` (OR) operators |
| **Indexes created** | 3 GIN indexes (subject, text, combined) |
| **Storage overhead** | Low — managed internally by PostgreSQL |

### Sphinx

| Characteristic | Detail |
|----------------|--------|
| **Index type** | External daemon, file-based inverted index |
| **Architecture** | Main index + Delta index (merged hourly via `search_gc = 3600`) |
| **Min word length** | 2 characters (configured in sphinx.conf) |
| **Match modes** | `SPH_MATCH_EXTENDED` (boolean), `SPH_MATCH_ANY` |
| **Max matches** | `SPHINX_MAX_MATCHES = 20000` |
| **Soft deletes** | `deleted` attribute flag (not physically removed) |
| **Range stepping** | 5000 post IDs per batch during indexing |
| **Field weights** | Title: 5, Body: 1 (configurable per query) |

---

## 7. Schema Enhancement Opportunities

### 7.1 Native Backend Scalability Issues

1. **N+M JOIN explosion**: Searching for N words requires N aliases of `phpbb_search_wordmatch` in a single query, creating cartesian-like join patterns
2. **word_count staleness**: Decremented but never deleted (words with `word_count = 0` remain in `wordlist`)
3. **No relevance scoring**: Results are filtered by boolean presence only, no TF-IDF or BM25 ranking
4. **title_match duplication**: Words appearing in both title and body create 2 rows in `wordmatch`
5. **Missing covering index**: The `un_mtch` unique key is `(word_id, post_id, title_match)` but queries often filter by `post_id` first — `post_id` key helps but suboptimal for the main search path

### 7.2 Results Cache Limitations

1. **LIKE-based invalidation**: `search_keywords LIKE '%word%'` is O(n) scan for every cache invalidation
2. **No indexing on keywords/authors**: The `search_results` table has only a PK on `search_key`
3. **Mediumtext for small data**: `search_keywords` and `search_authors` store simple lists but use mediumtext

### 7.3 Cross-Backend Inconsistencies

1. **Sphinx doesn't use `search_results` cache** — bypasses the caching layer entirely
2. **MySQL/PostgreSQL `index()` is a no-op** — only invalidates cache, doesn't maintain any search-specific data structures
3. **Delete semantics differ**: Native physically removes wordmatch rows; Sphinx soft-deletes via attribute; MySQL/PG have nothing to delete

### 7.4 Potential Schema Improvements for New Search Service

| Improvement | Rationale |
|-------------|-----------|
| Replace `wordmatch` with batch-INSERT approach + separate title/body indexes | Reduce JOIN complexity |
| Add `relevance_score` or use DB-native ranking | Enable quality ranking |
| Add index on `search_results(search_time)` | Speed up TTL cleanup |
| Consider JSONB/array storage for result IDs instead of file cache | Atomic cache updates |
| Add `forum_id` to `wordmatch` | Enable forum-scoped queries without JOIN to posts |
| Partition `wordmatch` by `word_id` ranges | Reduce index scan size at scale |

### 7.5 `phpbb_sphinx` Counter Table

Referenced via `SPHINX_TABLE` constant but not present in the dump (created dynamically). Structure:
```sql
CREATE TABLE phpbb_sphinx (
  counter_id int NOT NULL PRIMARY KEY,
  max_doc_id int NOT NULL DEFAULT 0
);
-- Single row with counter_id=1 tracking last indexed post_id
```

---

## Summary

The phpBB search system uses a **pluggable backend architecture** where:
- **Native backend** maintains its own inverted index in 3 tables (`wordlist`, `wordmatch`, `results`)
- **MySQL/PostgreSQL backends** leverage database-engine-native full-text indexing on `phpbb_posts` directly, using only `search_results` for cache metadata
- **Sphinx backend** delegates entirely to an external daemon, using only `phpbb_sphinx` for delta tracking
- All backends (except Sphinx) share the `base.php` caching layer (`obtain_ids`/`save_ids`/`destroy_cache`)
