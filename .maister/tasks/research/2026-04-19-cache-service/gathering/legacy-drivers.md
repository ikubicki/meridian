# Legacy Cache Driver Implementations — Full Analysis

## Source Files

| File | Class | Extends | Role |
|------|-------|---------|------|
| `src/phpbb/forums/cache/driver/driver_interface.php` | `driver_interface` | — | Interface contract |
| `src/phpbb/forums/cache/driver/base.php` | `base` | implements `driver_interface` | Abstract base (shared SQL caching) |
| `src/phpbb/forums/cache/driver/memory.php` | `memory` | extends `base` | Abstract memory-backend base |
| `src/phpbb/forums/cache/driver/file.php` | `file` | extends `base` | Filesystem driver (DEFAULT) |
| `src/phpbb/forums/cache/driver/redis.php` | `redis` | extends `memory` | Redis driver |
| `src/phpbb/forums/cache/driver/memcached.php` | `memcached` | extends `memory` | Memcached driver |
| `src/phpbb/forums/cache/driver/apcu.php` | `apcu` | extends `memory` | APCu driver |
| `src/phpbb/forums/cache/driver/wincache.php` | `wincache` | extends `memory` | Windows Cache driver |
| `src/phpbb/forums/cache/driver/dummy.php` | `dummy` | extends `base` | No-op driver |

All classes live in namespace `phpbb\cache\driver`.

---

## 1. Interface Contract (`driver_interface`)

**File**: `driver_interface.php:19-170`

### Method Signatures

| Method | Signature | Return | Purpose |
|--------|-----------|--------|---------|
| `load()` | `public function load()` | `mixed` (data or false) | Load global cache |
| `unload()` | `public function unload()` | `null` | Unload cache object (calls save) |
| `save()` | `public function save()` | `null` | Persist modified objects |
| `tidy()` | `public function tidy()` | `null` | Garbage-collect expired entries |
| `get($var_name)` | `public function get(string $var_name)` | `mixed` (data or false) | Get cached value |
| `put($var_name, $var, $ttl)` | `public function put(string $var_name, mixed $var, int $ttl = 0)` | `null` | Store value with TTL |
| `purge()` | `public function purge()` | `null` | Purge all cache data |
| `destroy($var_name, $table)` | `public function destroy(string $var_name, string $table = '')` | `null` | Destroy specific entry or SQL entries by table |
| `_exists($var_name)` | `public function _exists(string $var_name)` | `bool` | Check if cache entry exists and not expired |
| `sql_load($query)` | `public function sql_load(string $query)` | `int\|bool` | Load SQL result from cache |
| `sql_save($db, $query, $query_result, $ttl)` | `public function sql_save(\phpbb\db\driver\driver_interface $db, string $query, mixed $query_result, int $ttl)` | `int\|mixed` | Cache SQL result |
| `sql_exists($query_id)` | `public function sql_exists(int $query_id)` | `bool` | Check cached SQL result exists |
| `sql_fetchrow($query_id)` | `public function sql_fetchrow(int $query_id)` | `array\|bool` | Fetch row from cached SQL |
| `sql_fetchfield($query_id, $field)` | `public function sql_fetchfield(int $query_id, string $field)` | `string\|bool` | Fetch single field |
| `sql_rowseek($rownum, $query_id)` | `public function sql_rowseek(int $rownum, int $query_id)` | `bool` | Seek to row in cached result |
| `sql_freeresult($query_id)` | `public function sql_freeresult(int $query_id)` | `bool` | Free cached result |
| `clean_query_id($query_id)` | `public function clean_query_id(object\|resource\|int\|string $query_id)` | `int\|string` | Normalize query IDs from DBMS |

### Key Design Observations

- **Dual-purpose interface**: Combines general key-value caching (`get`/`put`/`destroy`) with SQL result caching (`sql_*` methods) in a single interface.
- **"Global" cache pattern**: `load()`/`save()`/`unload()` lifecycle manages a "global" blob of vars persisted as a batch.
- **`_exists()` uses underscore prefix**: Legacy naming convention, not truly private.
- **`destroy()` double duty**: When `$var_name == 'sql'` and `$table` is provided, it destroys all SQL caches referencing that table. Otherwise destroys a single var.

---

## 2. Abstract Base Class (`base`)

**File**: `base.php:17-225`

### Properties (base.php:19-24)

```php
var $vars = array();              // In-memory key-value store
var $is_modified = false;         // Dirty flag for save()
var $sql_rowset = array();        // Cached SQL results (query_id => rows[])
var $sql_row_pointer = array();   // Row cursor per query_id
var $cache_dir = '';              // Filesystem cache directory
```

Note: Uses legacy `var` keyword (PHP4 compat syntax), not `protected`.

### Provided Shared Logic

| Method | Lines | What it does |
|--------|-------|-------------|
| `purge()` | 29-77 | Iterates `cache_dir`, deletes files matching `container_*`, `autoload_*`, `url_matcher*`, `url_generator*`, `sql_*`, `data_*`. Resets `$vars`, `$sql_rowset`, `$sql_row_pointer`. Calls `opcache_reset()` if available. |
| `unload()` | 82-91 | Calls `save()`, then resets all arrays. |
| `sql_load($query)` | 96-109 | Normalizes whitespace, computes `md5($query)` as key, calls `$this->_read('sql_' . $query_id)`. Populates `$sql_rowset` and `$sql_row_pointer`. |
| `sql_exists($query_id)` | 114-118 | Checks `isset($this->sql_rowset[$query_id])`. |
| `sql_fetchrow($query_id)` | 123-131 | Returns next row from `$sql_rowset[$query_id]` using `$sql_row_pointer[$query_id]++`. |
| `sql_fetchfield($query_id, $field)` | 136-143 | Returns specific field from current row. |
| `sql_rowseek($rownum, $query_id)` | 148-156 | Sets `$sql_row_pointer[$query_id] = $rownum`. |
| `sql_freeresult($query_id)` | 161-171 | Unsets `$sql_rowset[$query_id]` and pointer. |
| `remove_file($filename, $check)` | 180-190 | `@unlink($filename)` with optional writable check. |
| `remove_dir($dir)` | 198-220 | Recursive directory delete via `DirectoryIterator`. |
| `clean_query_id($query_id)` | 225-234 | Normalizes resource/object query IDs to int via `get_resource_id()` or `spl_object_id()`. |

### Methods NOT Provided (must be implemented by subclasses)

- `load()` — how to load the "global" cache blob
- `save()` — how to persist modified global vars
- `tidy()` — how to garbage-collect
- `get($var_name)` — how to read a single var
- `put($var_name, $var, $ttl)` — how to write a single var
- `destroy($var_name, $table)` — how to destroy entries
- `_exists($var_name)` — how to check existence
- `sql_save(...)` — how to persist SQL results (partly shared via `_read`/`_write` in subclasses)

### Key Pattern: `_read()` / `_write()` Internal API

Subclasses implement `_read($var)` and `_write($var, $data, $ttl)` — the base class calls these from `sql_load()`. This is an **informal internal API** (no interface) that separates storage mechanism from caching logic.

---

## 3. File Driver (`file`) — THE DEFAULT

**File**: `file.php:20-638`
**Extends**: `base` (directly, NOT via `memory`)

### Constructor (file.php:37-46)

```php
function __construct($cache_dir = null)
{
    $this->cache_dir = $cache_dir ?? (PHPBB_FILESYSTEM_ROOT . 'cache/production/');
    $this->filesystem = new \phpbb\filesystem\filesystem();
    if ($this->filesystem->is_writable(dirname($this->cache_dir)) && !is_dir($this->cache_dir))
    {
        mkdir($this->cache_dir, 0777, true);
    }
}
```

- Default dir: `PHPBB_FILESYSTEM_ROOT . 'cache/production/'`
- Auto-creates directory if writable.
- Has `$var_expires` array (file.php:22) for per-var TTL tracking in global cache.

### Storage Format

**Individual files** (file.php:514-533, `_write()`):
```
<?php exit; ?>
{expiration_timestamp}
{query_text}      [only for sql_ files]
{byte_length_of_serialized_data}
{serialized_data}
```

**Global data file** (`data_global.php`, file.php:555-575):
```
<?php exit; ?>
{var1_expiration}
{byte_length_of_var_name+serialized_data}
{var_name}
{serialized_data}
{var2_expiration}
...
```

The `<?php exit; ?>` header prevents direct PHP execution if the file is accessed via web.

### File Naming

- `clean_varname()` (file.php:625-628): `str_replace(array('/', '\\'), '-', $varname)`
- General vars: `data_{varname}.php` (file.php:174 — `'data' . $var_name`)
- "Private" vars (starting with `_`): `data_{varname}.php` (same, but the underscore is part of the name: `data_foo.php`)
- SQL cache: `sql_{md5_of_query}.php`
- Global blob: `data_global.php`

### Two-Tier Storage Pattern

**Non-underscore vars** (`$var_name[0] != '_'`):
- Stored in the in-memory `$this->vars` array
- Persisted as a batch in `data_global.php` via `save()`
- TTL tracked in `$this->var_expires` per var

**Underscore-prefixed vars** (`$var_name[0] == '_'`):
- Each stored in its own individual file: `data{$var_name}.php`
- Written immediately via `_write()`
- Not part of the global batch

### TTL Handling

- `put()` default TTL: **31536000** (1 year) (file.php:173)
- TTL stored as absolute expiration timestamp = `time() + $ttl`
- For global vars: TTL stored in `$this->var_expires[$var_name]` and written into `data_global.php`
- For individual files: TTL stored as the second line of the file
- `_exists()` (file.php:240-257): Checks `time() > $this->var_expires[$var_name]`
- `_read()` (file.php:310-390): Checks `time() >= $expires` and skips expired entries
- `tidy()` (file.php:102-143): Iterates cache directory, reads expiration from files, deletes expired

### Locking

- Uses `\phpbb\lock\flock` for file writes (file.php:545-546)
- `$lock = new \phpbb\lock\flock($file); $lock->acquire();` / `$lock->release();`

### Serialization

- Uses PHP `serialize()`/`unserialize()` (file.php:572, 381)

### SQL Caching (file.php:264-296)

```php
function sql_save(...$db, $query, $query_result, $ttl)
{
    $query = preg_replace('/[\n\r\s\t]+/', ' ', $query);
    $query_id = md5($query);
    // Fetch all rows into $this->sql_rowset[$query_id]
    while ($row = $db->sql_fetchrow($query_result)) { ... }
    $db->sql_freeresult($query_result);
    // Write to sql_{md5}.php with TTL and query text
    $this->_write('sql_' . $query_id, $this->sql_rowset[$query_id], $ttl + time(), $query);
    return $query_id;
}
```

- Query key: `md5(normalized_query)`
- Stores the original query text in the cache file (for `destroy('sql', $table)` table-name matching)
- `destroy('sql', $table)` (file.php:199-235): Iterates all `sql_*` files, reads the query text from line 3, checks if table name appears in it, deletes matching files

### OPcache Integration

- `_write()` calls `opcache_invalidate($file)` after writing (file.php:589)
- `purge()` (inherited from base) calls `opcache_reset()` (base.php:69)

---

## 4. Memory Abstract Class (`memory`)

**File**: `memory.php:17-247`
**Extends**: `base`
**Extended by**: `redis`, `memcached`, `apcu`, `wincache`

### Constructor (memory.php:27-46)

```php
function __construct($cache_dir = null)
{
    global $dbname, $table_prefix, $acm_type;
    $this->cache_dir = $cache_dir ?? (PHPBB_FILESYSTEM_ROOT . 'cache/production/');
    $this->key_prefix = substr(md5($dbname . $table_prefix), 0, 8) . '_';
    // Validates $this->extension is loaded
    // Validates $this->function exists (if set)
}
```

### Key Prefix

- `$this->key_prefix = substr(md5($dbname . $table_prefix), 0, 8) . '_'` (memory.php:33)
- 8-char hex prefix from md5 of dbname+table_prefix — isolates multiple phpBB installs sharing the same cache backend
- Example: `a1b2c3d4_`

### Internal API (must be implemented by concrete drivers)

| Method | Purpose |
|--------|---------|
| `_read($var)` | Fetch raw value from backend |
| `_write($var, $data, $ttl)` | Store value in backend |
| `_delete($var)` | Remove value from backend |

Optional:
| `_isset($var)` | Check if key exists (default returns `true`) |

### Differences from File Driver

| Aspect | `file` | `memory` subclasses |
|--------|--------|-------------------|
| Global vars storage | `data_global.php` file | Single key `global` in backend |
| Load global | Parse multi-entry file | `_read('global')`, returns whole array |
| Save global | Serialize each var to file | `_write('global', $this->vars, 2592000)` |
| Default TTL (`put`) | `31536000` (1 year) | `2592000` (30 days) |
| TTL for global blob | Per-var in file | 2592000 for entire blob |
| Tidy | Iterates files, deletes expired | No-op (backends auto-expire) |
| SQL destroy by table | Scans file contents for table name | Maintains `sql_{table_name}` index keys |

### SQL Caching in Memory Drivers (memory.php:155-215)

**Much more sophisticated table tracking than file driver:**

```php
function sql_save(...)
{
    // Parse FROM/JOIN to extract table names
    preg_match_all('/(?:FROM ...)|(?:JOIN ...)/', $query, $regs, ...)
    
    foreach ($tables as $table_name)
    {
        // Read existing index for this table
        $temp = $this->_read('sql_' . $table_name);
        // Add this query's md5 to the table's index
        $temp[$query_id] = true;
        // Store table index with TTL=0 (never expire)
        $this->_write('sql_' . $table_name, $temp, 0);
    }
    
    // Store actual rowset
    $this->_write('sql_' . $query_id, $this->sql_rowset[$query_id], $ttl);
}
```

- Maintains reverse index: `sql_{table_name}` → `[md5_1 => true, md5_2 => true, ...]`
- `destroy('sql', $table)` (memory.php:127-147): Reads the table index, deletes each query cache, then deletes the index
- File driver does string matching on stored query text instead

### `_exists()` Handling (memory.php:221-234)

- For underscore vars: delegates to `_isset()` which defaults to `true` (memory.php:246)
- For regular vars: checks `$this->vars` array after loading global

### `get()` Behavior (memory.php:86-99)

- Underscore vars: `_read($var_name)` directly from backend
- Regular vars: from `$this->vars[$var_name]` (loaded from global blob)

### `put()` Behavior (memory.php:104-115)

- Underscore vars: `_write($var_name, $var, $ttl)` directly to backend
- Regular vars: into `$this->vars[$var_name]`, sets `is_modified = true` (persisted on `save()`)

---

## 5. Redis Driver (`redis`)

**File**: `redis.php:26-159`
**Extends**: `memory`

### Connection Configuration (redis.php:18-24, 56-92)

| Constant | Default | Purpose |
|----------|---------|---------|
| `PHPBB_ACM_REDIS_HOST` | `'localhost'` | Redis server host |
| `PHPBB_ACM_REDIS_PORT` | `6379` | Redis server port |
| `PHPBB_ACM_REDIS_PASSWORD` | (undefined) | Optional auth password |
| `PHPBB_ACM_REDIS_DB` | (undefined) | Optional database number |

### Constructor Details

```php
$this->redis = new \Redis();
$this->redis->connect(PHPBB_ACM_REDIS_HOST, PHPBB_ACM_REDIS_PORT);
$this->redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
$this->redis->setOption(\Redis::OPT_PREFIX, $this->key_prefix);
```

- Uses PHP `Redis` extension (`phpredis`)
- **Serialization**: `\Redis::SERIALIZER_PHP` — phpredis handles serialize/unserialize automatically
- **Key prefix**: Set at Redis client level via `OPT_PREFIX` — so all keys automatically get the `{8char}_` prefix
- **Auth**: Uses `$this->redis->auth(PHPBB_ACM_REDIS_PASSWORD)` if constant defined
- **DB select**: `$this->redis->select(PHPBB_ACM_REDIS_DB)` if constant defined
- Supports custom connection args via `func_get_args()` passed to `connect()` (redis.php:69-73)

### Storage Implementation

```php
function _read($var)  { return $this->redis->get($var); }                           // redis.php:118
function _write($var, $data, $ttl = 2592000) { 
    return $this->redis->set($var, $data, ['EXAT' => time() + $ttl]);               // redis.php:133
}
function _delete($var) { return $this->redis->delete($var) > 0; }                   // redis.php:146
```

- TTL: Uses `EXAT` (absolute expiration timestamp) in Redis SET command
- Default TTL: 2592000 (30 days, inherited from memory)
- Key prefix applied by phpredis `OPT_PREFIX`, so `_read('global')` → Redis key `a1b2c3d4_global`
- **Memcached prefixes keys manually** but Redis uses the `OPT_PREFIX` automatic feature

### Purge / Unload

- `purge()`: Calls `$this->redis->flushDB()` then `parent::purge()` (redis.php:105)
- `unload()`: Calls `parent::unload()` then `$this->redis->close()` (redis.php:98)

---

## 6. Memcached Driver (`memcached`)

**File**: `memcached.php:17-155`
**Extends**: `memory`

### Connection Configuration (memcached.php:19-38)

| Constant | Default | Purpose |
|----------|---------|---------|
| `PHPBB_ACM_MEMCACHED_HOST` | `'localhost'` | Memcached host |
| `PHPBB_ACM_MEMCACHED_PORT` | `11211` | Memcached port |
| `PHPBB_ACM_MEMCACHED_COMPRESS` | `true` | Enable compression |
| `PHPBB_ACM_MEMCACHED` | `'{host}/{port}'` | Full server string |

### Constructor Details (memcached.php:54-86)

```php
$this->memcached = new \Memcached();
$this->memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, true);
// Multiple servers: "host1/port1,host2/port2"
foreach (explode(',', $memcached_servers) as $u) {
    preg_match('#(.*)/(\d+)#', $u, $parts);
    $server_list[] = [trim($parts[1]), (int) trim($parts[2])];
}
$this->memcached->addServers($server_list);
```

- Uses PHP `Memcached` extension (not `Memcache`)
- **Binary protocol** enabled
- **Multi-server support**: Comma-separated `host/port` pairs
- **Compression**: On by default, configurable via `PHPBB_ACM_MEMCACHED_COMPRESS`
- Validates connection via `$this->memcached->getStats()`

### Storage Implementation

```php
function _read($var)  { return $this->memcached->get($this->key_prefix . $var); }    // memcached.php:111
function _write($var, $data, $ttl = 2592000) {
    // Try replace first (atomic update), fallback to set (new key)
    if (!$this->memcached->replace($this->key_prefix . $var, $data, $ttl)) {
        return $this->memcached->set($this->key_prefix . $var, $data, $ttl);         // memcached.php:123-127
    }
}
function _delete($var) { return $this->memcached->delete($this->key_prefix . $var); }// memcached.php:136
```

- **Key prefix applied manually** (`$this->key_prefix . $var`), unlike Redis which uses `OPT_PREFIX`
- **Write strategy**: `replace()` first (fails if key doesn't exist), then `set()` — optimistic update pattern
- **Serialization**: Handled internally by Memcached extension (uses PHP serialization by default)
- **TTL**: Passed as relative seconds to Memcached (which interprets values > 30 days as absolute timestamps)

### Purge / Unload

- `purge()`: `$this->memcached->flush()` then `parent::purge()` (memcached.php:98)
- `unload()`: `parent::unload()` then `unset($this->memcached)` (memcached.php:91) — destroys object rather than close()

---

## 7. APCu Driver (`apcu`)

**File**: `apcu.php:17-76`
**Extends**: `memory`

### Simplest Memory Driver

No constructor override — uses `memory::__construct()` with `$extension = 'apcu'`.

### Storage Implementation

```php
function _read($var)  { return apcu_fetch($this->key_prefix . $var); }              // apcu.php:39
function _write($var, $data, $ttl = 2592000) { 
    return apcu_store($this->key_prefix . $var, $data, $ttl);                       // apcu.php:52
}
function _delete($var) { return apcu_delete($this->key_prefix . $var); }            // apcu.php:63
```

- **Key prefix applied manually** (same pattern as Memcached)
- **Serialization**: APCu stores PHP values natively in shared memory — no serialization needed
- **TTL**: `apcu_store()` accepts relative TTL in seconds

### Purge (apcu.php:27-36)

```php
function purge()
{
    if (PHP_SAPI !== 'cli' || @ini_get('apc.enable_cli'))
    {
        apcu_delete(new \APCUIterator('#^' . $this->key_prefix . '#'));
    }
    parent::purge();
}
```

- Uses `APCUIterator` with regex to selectively delete only this phpBB install's keys
- **CLI guard**: APCu doesn't work in CLI by default — checks `apc.enable_cli` config
- Does NOT flush all APCu entries (unlike Redis `flushDB` or Memcached `flush`)

---

## 8. WinCache Driver (`wincache`)

**File**: `wincache.php:17-72`
**Extends**: `memory`

### Storage Implementation

```php
function _read($var) {
    $success = false;
    $result = wincache_ucache_get($this->key_prefix . $var, $success);
    return ($success) ? $result : false;                                             // wincache.php:36-40
}
function _write($var, $data, $ttl = 2592000) { 
    return wincache_ucache_set($this->key_prefix . $var, $data, $ttl);              // wincache.php:52
}
function _delete($var) { 
    return wincache_ucache_delete($this->key_prefix . $var);                        // wincache.php:62
}
```

- **Key prefix applied manually**
- `_read()` uses the `$success` by-reference parameter of `wincache_ucache_get()`
- **Purge**: `wincache_ucache_clear()` — clears ALL entries (not prefix-scoped)

---

## 9. Dummy Driver (`dummy`)

**File**: `dummy.php:17-141`
**Extends**: `base` (directly, NOT via `memory`)

### Complete No-Op Implementation

- `load()` → `return true;` (dummy.php:30)
- `unload()` → empty (dummy.php:38)
- `save()` → empty (dummy.php:45)
- `get($var_name)` → `return false;` (dummy.php:64)
- `put(...)` → empty (dummy.php:71)
- `purge()` → empty (dummy.php:78)
- `destroy(...)` → empty (dummy.php:85)
- `_exists(...)` → `return false;` (dummy.php:92)
- `sql_load(...)` → `return false;` (dummy.php:99)
- `sql_save(...)` → `return $query_result;` (dummy.php:106 — passes through original DB result)
- `sql_exists(...)` → `return false;` (dummy.php:113)
- `sql_fetchrow(...)` → `return false;` (dummy.php:120)
- `sql_fetchfield(...)` → `return false;` (dummy.php:127)
- `sql_rowseek(...)` → `return false;` (dummy.php:134)
- `sql_freeresult(...)` → `return false;` (dummy.php:141)

**Overrides ALL methods from base** — including `sql_*` methods that base provides. This ensures zero side effects.

`tidy()` (dummy.php:52-58): Still updates `cache_last_gc` config if container is available.

---

## 10. Cross-Cutting Analysis

### Inheritance Hierarchy

```
driver_interface
  └── base (abstract)
        ├── file (DEFAULT)
        ├── dummy (no-op)
        └── memory (abstract)
              ├── redis
              ├── memcached  
              ├── apcu
              └── wincache
```

Two branches:
1. **File branch**: `base` → `file` — manages its own filesystem storage
2. **Memory branch**: `base` → `memory` → `{redis,memcached,apcu,wincache}` — adds `key_prefix`, internal `_read`/`_write`/`_delete` API, and smart SQL table indexing

### TTL Comparison

| Driver | Default TTL (`put`) | How stored | Enforcement |
|--------|-------------------|------------|-------------|
| `file` | 31536000 (1 year) | Absolute timestamp in file header | Checked on read + tidy() GC |
| `memory`/all | 2592000 (30 days) | Passed to backend as relative TTL | Backend auto-expires |
| `dummy` | 0 (ignored) | Not stored | N/A |

### Serialization Comparison

| Driver | Serialization Method | Where |
|--------|---------------------|-------|
| `file` | PHP `serialize()`/`unserialize()` | In `_read()`/`_write()` methods |
| `redis` | `\Redis::SERIALIZER_PHP` (phpredis handles it) | Set via `OPT_SERIALIZER` |
| `memcached` | Memcached extension default (PHP serialize) | Internal to extension |
| `apcu` | Native (stores PHP values in shared memory) | No serialization needed |
| `wincache` | Native (stores PHP values in shared memory) | No serialization needed |

### Key Format Comparison

| Driver | Key format | Example |
|--------|-----------|---------|
| `file` | Filename: `{type}{varname}.php` | `data_global.php`, `sql_abc123.php`, `data_foo.php` |
| `redis` | `{8char_prefix}{var}` (via OPT_PREFIX) | `a1b2c3d4_global`, `a1b2c3d4_sql_abc123` |
| `memcached` | `{8char_prefix}{var}` (manual prefix) | `a1b2c3d4_global`, `a1b2c3d4_sql_abc123` |
| `apcu` | `{8char_prefix}{var}` (manual prefix) | `a1b2c3d4_global`, `a1b2c3d4_sql_abc123` |
| `wincache` | `{8char_prefix}{var}` (manual prefix) | `a1b2c3d4_global`, `a1b2c3d4_sql_abc123` |

### SQL Caching Comparison

| Aspect | `file` driver | `memory` drivers |
|--------|--------------|-----------------|
| Query key | `md5(normalized_query)` | `md5(normalized_query)` |
| Query normalization | `preg_replace('/[\n\r\s\t]+/', ' ', $query)` | Same |
| Table tracking for destroy | Stores raw query text in file, scans with `strpos()` | Maintains `sql_{table_name}` index maps |
| Destroy by table | O(n) file scan + string match | O(1) index lookup + batch delete |
| TTL | Stored as absolute timestamp in file | Passed as relative seconds to backend |
| Storage | `sql_{md5}.php` file | `sql_{md5}` key in backend |

### Global Vars Pattern

All drivers maintain a **two-tier storage model**:

1. **"Regular" vars** (no underscore prefix): Batched into a single `global` / `data_global.php` blob. Loaded together on first access, saved together on `unload()` or modify. This amortizes I/O for frequently-accessed config values.

2. **"Private" vars** (underscore prefix, e.g. `_foo`): Each stored independently. Used for larger/less-frequent data that shouldn't be loaded with every request.

### Purge Behavior

| Driver | Purge scope | Method |
|--------|-------------|--------|
| `file` | Deletes matching files in cache_dir | `DirectoryIterator` + `unlink` (via `base::purge()`) |
| `redis` | `flushDB()` — flushes current DB | May affect other apps on same Redis DB |
| `memcached` | `flush()` — flushes entire server | Affects ALL memcached users |
| `apcu` | `apcu_delete(APCUIterator)` — prefix-scoped | Only deletes this install's keys |
| `wincache` | `wincache_ucache_clear()` — clears all | Affects ALL wincache users |
| `dummy` | No-op | — |

### Connection Lifecycle

| Driver | On construct | On unload | Persistent? |
|--------|-------------|-----------|-------------|
| `file` | Creates dir if needed | Saves + resets arrays | N/A (filesystem) |
| `redis` | `$redis->connect()` | `$redis->close()` | No (per-request) |
| `memcached` | `new Memcached()` + `addServers()` | `unset($this->memcached)` | No |
| `apcu` | Extension check only | Reset arrays (parent) | N/A (shared memory) |
| `wincache` | Extension check only | Reset arrays (parent) | N/A (shared memory) |

---

## 11. Key Findings Summary

1. **Interface is monolithic**: 17 methods combining KV cache + SQL result cache in one interface. The SQL methods (`sql_load`, `sql_save`, `sql_exists`, `sql_fetchrow`, `sql_fetchfield`, `sql_rowseek`, `sql_freeresult`) are a significant portion of the contract.

2. **`_read`/`_write`/`_delete` informal API**: Not part of the interface, but every concrete driver implements these. They represent the actual storage abstraction.

3. **File driver is the only one that extends `base` directly** (besides `dummy`). All others go through the `memory` abstraction which adds key prefixing, extension validation, and smart SQL table tracking.

4. **SQL table tracking diverges**: File driver does brute-force string matching on stored query text. Memory drivers maintain proper reverse indexes (`sql_{table_name}` → `[query_ids...]`). This is a significant architecture difference.

5. **No PSR-6/PSR-16 compliance**: The interface is entirely phpBB-specific. No `CacheItemInterface`, no `CacheInterface`.

6. **Legacy PHP style**: Uses `var` instead of visibility keywords, `function` without visibility, underscore-prefixed "private" methods that are actually public.

7. **Global cache batch pattern** adds complexity but optimizes for phpBB's access pattern where many small config values are read on every request.

8. **TTL inconsistency**: File driver defaults to 1 year, memory drivers default to 30 days. The interface contract says `$ttl = 0` but file driver overrides to `$ttl = 31536000`.

9. **Purge isolation varies wildly**: APCu is the only driver that scopes its purge to this phpBB install's keys. Redis flushes the whole DB, Memcached flushes everything, WinCache clears all.

10. **No connection pooling or retry logic** in any driver. Single connection, no reconnect on failure.
