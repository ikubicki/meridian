# Admin Configuration & Management Analysis

## 1. Complete Configuration Key Inventory

### Global Search Settings (admin-editable)

| Config Key | Default Value | Type | Controls | User-Facing | Backend |
|---|---|---|---|---|---|
| `search_type` | `\phpbb\search\fulltext_native` | string | Active search backend class | No (admin) | All |
| `load_search` | `1` | bool | Global enable/disable of search | No (admin) | All |
| `search_interval` | `0` | float | Seconds between searches for registered users | Yes (flood control) | All |
| `search_anonymous_interval` | `0` | float | Seconds between searches for guests | Yes (flood control) | All |
| `limit_search_load` | `0` | float | CPU load threshold; 0=disabled | No (admin) | All |
| `min_search_author_chars` | `3` | integer | Min chars for author wildcard search | Yes (validation) | All |
| `max_num_search_keywords` | `10` | integer | Max keywords per search query | Yes (validation) | All |
| `default_search_return_chars` | `300` | integer | Default chars to return in results excerpt | Yes (display) | All |
| `search_store_results` | `1800` | integer | Seconds to cache search results | No (internal) | All |
| `search_block_size` | `250` | integer | SQL LIMIT per block when paginating results | No (internal) | All |
| `search_gc` | `7200` | integer | Garbage collection interval (seconds) | No (internal) | All |
| `search_last_gc` | `0` (dynamic) | integer | Timestamp of last GC run | No (internal) | All |
| `search_indexing_state` | `` (empty) | string | Comma-separated state during rebuild: `[backend_class],[action],[post_counter]` | No (internal) | All |

### Native Backend (`fulltext_native`) Settings

| Config Key | Default Value | Type | Controls | Admin-Editable |
|---|---|---|---|---|
| `fulltext_native_min_chars` | `3` | integer:0:255 | Minimum word length to index/search | Yes |
| `fulltext_native_max_chars` | `14` | integer:0:255 | Maximum word length to index/search | Yes |
| `fulltext_native_common_thres` | `5` | double:0:100 | Common word threshold (% of total posts) | Yes |
| `fulltext_native_load_upd` | `1` | bool | Update index on new posts (real-time) | Yes |

### MySQL Backend (`fulltext_mysql`) Settings

| Config Key | Default Value | Type | Controls | Admin-Editable |
|---|---|---|---|---|
| `fulltext_mysql_min_word_len` | `4` | integer | Min word length (read from MySQL `ft_min_word_len`) | No (display only) |
| `fulltext_mysql_max_word_len` | `254` | integer | Max word length (read from MySQL `ft_max_word_len`) | No (display only) |

### PostgreSQL Backend (`fulltext_postgres`) Settings

| Config Key | Default Value | Type | Controls | Admin-Editable |
|---|---|---|---|---|
| `fulltext_postgres_ts_name` | `simple` | string | PostgreSQL text search configuration name | Yes (dropdown from `pg_ts_config`) |
| `fulltext_postgres_min_word_len` | `4` | integer:0:255 | Minimum word length to search | Yes |
| `fulltext_postgres_max_word_len` | `254` | integer:0:255 | Maximum word length to search | Yes |

### Sphinx Backend (`fulltext_sphinx`) Settings

| Config Key | Default Value (in dump) | Type | Controls | Admin-Editable |
|---|---|---|---|---|
| `fulltext_sphinx_data_path` | (not in dump) | string | Data/log directory path for Sphinx | Yes |
| `fulltext_sphinx_host` | (not in dump) | string | Sphinx search daemon host | Yes |
| `fulltext_sphinx_port` | (not in dump) | string | Sphinx search daemon port (default 9312) | Yes |
| `fulltext_sphinx_indexer_mem_limit` | `512` | int | Memory limit for Sphinx indexer (MB) | Yes |
| `fulltext_sphinx_id` | (auto-generated) | int | Unique ID for this phpBB instance's Sphinx indexes | No (internal) |
| `fulltext_sphinx_stopwords` | `0` | bool | Enable/disable stopwords | Yes |

### Related Non-Search Configs

| Config Key | Default | Controls |
|---|---|---|
| `load_unreads_search` | `1` | Enable "unread posts" search mode |
| `load_anon_lastread` | `0` | Track last-read for guests (affects unread search) |
| `num_posts` | dynamic | Total post count (used by native common word threshold) |

---

## 2. Admin Panel Capabilities

**Source**: `src/phpbb/common/acp/acp_search.php`

### Two ACP Modes

#### Mode: `settings`
- **Backend selection**: Dropdown of all discovered search backends (via extension finder)
- **Backend switching**: Requires confirmation box; calls `$search->init()` on new backend
- **Global settings form**: Exposes all global config keys listed above
- **Backend-specific settings**: Each backend provides its own `acp()` method returning HTML template + config keys
- **Validation**: Type casting with min/max bounds (e.g., `integer:0:255`, `double:0:100`)
- **Logging**: Changes logged via `LOG_CONFIG_SEARCH` admin log entry
- **CSRF**: Uses `check_link_hash()` for form validation

#### Mode: `index`
- **Create index**: Starts batch indexing of all posts
- **Delete index**: Starts batch removal of all index data
- **Index status display**: Shows per-backend whether index exists + statistics
- **Cancel operation**: Stops in-progress indexing, resets state
- **Progress tracking**: Shows progress bar, posts/second rate, remaining count
- **Continue indexing**: Resumes from last saved state if interrupted

### Backend Discovery

```php
$finder->extension_suffix('_backend')
    ->extension_directory('/search')
    ->core_path('src/phpbb/forums/search/')
    ->get_classes();
```

Backends are discovered from core path and extensions with `_backend` suffix.

---

## 3. Index Rebuild Process

**Source**: `src/phpbb/common/acp/acp_search.php` lines 300-510

### Batch Processing

| Parameter | Value | Source |
|---|---|---|
| Batch size | `100` posts | `$this->batch_size = 100` (hardcoded) |
| Memory limit | `128M` | `ini_set('memory_limit', '128M')` at entry |
| Time control | `still_on_time()` | phpBB utility — checks PHP max_execution_time |

### State Machine

State stored in `phpbb_config.search_indexing_state` as comma-separated values:
- `state[0]` = backend class name (e.g., `\phpbb\search\fulltext_native`)
- `state[1]` = action (`create` or `delete`)
- `state[2]` = `post_counter` (last processed post_id)

### Create Index Flow

1. Admin selects backend, clicks "Create index"
2. System queries `MAX(post_id)` from posts table
3. Loop: fetch posts with `post_id > post_counter ORDER BY post_id ASC LIMIT 100`
4. For each post: check `enable_indexing` forum flag, call `$search->index('post', ...)`
5. After each batch: call `save_state()` → persists to config
6. Check `still_on_time()` — if time expired, save state & `meta_refresh(1)` to continue
7. Display progress: posts processed, rate (rows/second), progress bar
8. After all posts: call `$search->tidy()` for cleanup
9. Clear state, log `LOG_SEARCH_INDEX_CREATED`

### Delete Index Flow

Similar to create but calls `$search->index_remove($ids, $posters, $forum_ids)` per batch, or `$search->delete_index()` if backend supports bulk deletion.

### Progress Tracking

```php
function get_post_index_progress(int $post_counter)
```
- Counts posts with `post_id <= $post_counter` (done)
- Counts posts with `post_id > $post_counter` (remaining)
- Calculates percentage: `(done / total) * 100`
- Returns: `VALUE`, `TOTAL`, `PERCENTAGE`, `REMAINING`

### Error Handling

- Backend `create_index()` / `delete_index()` can return error string
- On error: reset state, show error with back link
- Time limit: graceful pause via `still_on_time()` (no hard crashes)
- Resumable: state saved to DB, admin can resume or cancel

---

## 4. Rate Limiting & Flood Protection

**Source**: `web/search.php` lines 118-128

### Mechanism

```php
$interval = ($user->data['user_id'] == ANONYMOUS)
    ? $config['search_anonymous_interval']
    : $config['search_interval'];

if ($interval && !in_array($search_id, ['unreadposts', 'unanswered', 'active_topics', 'egosearch'])
    && !$auth->acl_get('u_ignoreflood'))
{
    if ($user->data['user_last_search'] > time() - $interval)
    {
        trigger_error('NO_SEARCH_TIME', remaining_seconds);
    }
}
```

### Key Details

| Aspect | Detail |
|---|---|
| Tracking field | `user_last_search` in users table |
| Exempt searches | `unreadposts`, `unanswered`, `active_topics`, `egosearch` |
| Permission bypass | `u_ignoreflood` ACL permission |
| Registered interval | `search_interval` config (default: 0 = no limit) |
| Anonymous interval | `search_anonymous_interval` config (default: 0 = no limit) |
| Error message | Shows remaining seconds until next allowed search |

---

## 5. System Load Management

**Source**: `web/search.php` lines 108-113

### Load Check

```php
if ($user->load && $config['limit_search_load']
    && ($user->load > doubleval($config['limit_search_load'])))
{
    trigger_error('NO_SEARCH_LOAD');
}
```

### Global Disable

```php
if (!$config['load_search'])
{
    trigger_error('NO_SEARCH');
}
```

### Summary

| Config | Default | Effect |
|---|---|---|
| `load_search` | `1` | `0` completely disables search for all users |
| `limit_search_load` | `0` | CPU load threshold; `0` = no load checking |
| Load source | `$user->load` | System CPU load average (populated during session_begin) |

### Permission Check Stack (in order)

1. `u_search` permission — user can search
2. `f_search` (global) — at least one forum allows search
3. `load_search` config — search globally enabled
4. `limit_search_load` — CPU load under threshold
5. `search_interval` / `search_anonymous_interval` — flood protection

---

## 6. Backend-Specific Configuration

### Native (`fulltext_native`)

**ACP config return**:
```php
'config' => array(
    'fulltext_native_load_upd' => 'bool',
    'fulltext_native_min_chars' => 'integer:0:255',
    'fulltext_native_max_chars' => 'integer:0:255',
    'fulltext_native_common_thres' => 'double:0:100'
)
```

**Internal usage**:
- `search_block_size` for result pagination (250 per SQL query)
- Common word threshold: words appearing in > X% of posts are excluded
- `tidy()` recalculates common words when `num_posts >= 100`
- Index removal via DELETE/TRUNCATE on `SEARCH_RESULTS_TABLE`, `SEARCH_WORDLIST_TABLE`, `SEARCH_WORDMATCH_TABLE`

### MySQL (`fulltext_mysql`)

**ACP config return**: Empty config array (settings are display-only from MySQL variables)

**Read-only display**: `ft_min_word_len` and `ft_max_word_len` from MySQL server variables.

**Index**: Uses MySQL FULLTEXT INDEX on `post_subject` and `post_text` columns.

### PostgreSQL (`fulltext_postgres`)

**ACP config return**:
```php
'config' => array(
    'fulltext_postgres_ts_name' => 'string',
    'fulltext_postgres_min_word_len' => 'integer:0:255',
    'fulltext_postgres_max_word_len' => 'integer:0:255'
)
```

**Index creation** uses GIN indexes:
- `{table}_{ts_name}_post_subject` — GIN on `to_tsvector(ts_name, post_subject)`
- `{table}_{ts_name}_post_content` — GIN on `to_tsvector(ts_name, post_text)`
- `{table}_{ts_name}_post_subject_content` — GIN on combined subject+text

### Sphinx (`fulltext_sphinx`)

**ACP config return**:
```php
'config' => array(
    'fulltext_sphinx_data_path' => 'string',
    'fulltext_sphinx_host' => 'string',
    'fulltext_sphinx_port' => 'string',
    'fulltext_sphinx_indexer_mem_limit' => 'int'
)
```

**Auto-generated config file**: Renders `sphinx.conf` with:
- Main + delta index architecture
- SQL source queries against phpBB posts table
- Indexer memory limit from config
- searchd listen address from host:port
- Log paths from data_path

---

## 7. Result Caching System

**Source**: `src/phpbb/forums/search/base.php`

### Cache Architecture

| Component | Purpose |
|---|---|
| `SEARCH_RESULTS_TABLE` | DB table tracking active search keys + timestamps |
| `_search_results_{key}` | phpBB cache entries with actual result IDs |
| `search_store_results` | TTL in seconds (default 1800 = 30 min) |
| `search_gc` | GC interval for cleaning expired results |

### Cache Flow

1. Before search: `obtain_ids()` checks cache for existing results
2. Returns `SEARCH_RESULT_IN_CACHE` or `SEARCH_RESULT_NOT_IN_CACHE`
3. After search: `save_ids()` stores result IDs in cache with TTL
4. Cache stores: result IDs by position, total count, sort direction
5. `destroy_cache($words)` invalidates caches matching modified words/authors
6. GC: `DELETE FROM SEARCH_RESULTS_TABLE WHERE search_time < (now - search_store_results)`

### Block-Based Storage

Cache keeps only 2 blocks (`search_block_size` = 250) around the current position, discarding distant results to limit memory use.

---

## 8. Config Migration Path (for new service)

### Must Carry Forward

| Config | Reason |
|---|---|
| `search_interval` | Rate limiting is essential |
| `search_anonymous_interval` | Guest protection |
| `max_num_search_keywords` | Query complexity limit |
| `min_search_author_chars` | Input validation |
| `default_search_return_chars` | Result display |
| `load_search` | Global kill switch |
| `limit_search_load` | Load protection (adapt to new metrics) |

### Can Be Redesigned

| Config | Reason |
|---|---|
| `search_type` | New service has unified backend |
| `search_block_size` | Internal pagination detail |
| `search_store_results` | New caching strategy |
| `search_gc` | New GC approach |
| `search_indexing_state` | New rebuild mechanism |
| `fulltext_*` | Backend-specific, replaced by new search engine config |

### New Service Should Add

| Concept | Why |
|---|---|
| Per-user search quota | More granular than interval |
| Index version tracking | For zero-downtime reindexing |
| Search quality metrics | Relevance feedback |
| Async index rebuild status | API-based progress instead of meta_refresh |
| Per-forum index configuration | Replace `enable_indexing` forum flag |
