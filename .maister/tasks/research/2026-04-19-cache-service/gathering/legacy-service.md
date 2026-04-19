# Legacy Cache Service Wrapper & DI Configuration — Findings

## 1. Service Wrapper: `phpbb\cache\service`

**Source**: `src/phpbb/forums/cache/service.php` (Lines 1–356)

### Constructor & Dependencies

```php
public function __construct(
    \phpbb\cache\driver\driver_interface $driver,
    \phpbb\config\config $config,
    \phpbb\db\driver\driver_interface $db,
    \phpbb\event\dispatcher $dispatcher,
    string $phpbb_root_path,
    string $php_ext
)
```

The service stores all six dependencies as protected properties.

### Public Methods (directly defined)

| Method | Lines | Purpose |
|--------|-------|---------|
| `get_driver()` | 92–95 | Returns the underlying `driver_interface` |
| `set_driver($driver)` | 113–116 | Replaces the cache driver at runtime |
| `deferred_purge()` | 101–110 | Registers a `purge()` call on the `core.garbage_collection` event; idempotent (flag `$cache_purge_deferred`) |
| `__call($method, $arguments)` | 118–121 | **Delegates any unknown method** to `$this->driver` via `call_user_func_array` |
| `obtain_word_list()` | 126–145 | Fetches censored words from `WORDS_TABLE`, caches as `_word_censors` |
| `obtain_icons()` | 150–170 | Fetches topic icons from `ICONS_TABLE`, caches as `_icons` |
| `obtain_ranks()` | 175–200 | Fetches ranks from `RANKS_TABLE`, caches as `_ranks` (split into `special` / `normal`) |
| `obtain_attach_extensions($forum_id)` | 207–290 | Fetches allowed upload extensions, caches as `_extensions`, then filters by forum/PM context |
| `obtain_bots()` | 295–325 | Fetches active bots from `BOTS_TABLE`, caches as `_bots` |
| `obtain_cfg_items($style)` | 330–350 | Parses style.cfg file, caches as `_cfg_{style_path}`, re-parses when file is newer |
| `obtain_disallowed_usernames()` | 355–373 | Fetches disallowed usernames from `DISALLOW_TABLE`, caches as `_disallowed_usernames` |

### `__call()` Delegation Pattern

```php
public function __call($method, $arguments)
{
    return call_user_func_array(array($this->driver, $method), $arguments);
}
```

This means any call like `$cache->get('key')`, `$cache->put('key', $val)`, `$cache->purge()`, `$cache->destroy(...)`, `$cache->_exists(...)` is transparently forwarded to the driver. The service acts as a facade — callers cannot tell whether they have the service or the raw driver for these methods.

### Domain-Specific Cache Pattern

All `obtain_*()` methods follow the same pattern:

1. Try `$this->driver->get('_keyname')` — if cache hit, return
2. On miss: run SQL query against `$this->db`
3. Build result array
4. `$this->driver->put('_keyname', $result)` — store in cache
5. Return result

The **underscore prefix** (`_word_censors`, `_icons`, `_ranks`, etc.) is significant — in the file driver, keys starting with `_` are stored as **individual files** (`data_word_censors.php`, `data_icons.php`) rather than inside `data_global.php`.

### Events

The only event interaction is in `deferred_purge()`:
- Adds `[$this, 'purge']` as a listener on `core.garbage_collection`
- The `purge()` call itself is delegated to the driver via `__call()`

No events are fired from the `obtain_*()` methods.

---

## 2. Driver Interface: `phpbb\cache\driver\driver_interface`

**Source**: `src/phpbb/forums/cache/driver/driver_interface.php` (Lines 1–182)

### Full Method List

| Method | Signature | Purpose |
|--------|-----------|---------|
| `load()` | `→ mixed` | Load global cache state |
| `unload()` | `→ null` | Save & release |
| `save()` | `→ null` | Persist modified objects |
| `tidy()` | `→ null` | Garbage-collect expired entries |
| `get($var_name)` | `→ mixed\|false` | Read a cache key |
| `put($var_name, $var, $ttl)` | `→ null` | Write a cache key with TTL |
| `purge()` | `→ null` | Wipe all cache data |
| `destroy($var_name, $table)` | `→ null` | Remove a specific key, or all SQL caches mentioning `$table` |
| `_exists($var_name)` | `→ bool` | Check if key exists and not expired |
| `sql_load($query)` | `→ int\|false` | Load cached SQL result by query string |
| `sql_save($db, $query, $query_result, $ttl)` | `→ int\|mixed` | Cache a SQL query result |
| `sql_exists($query_id)` | `→ bool` | Check if SQL rowset exists |
| `sql_fetchrow($query_id)` | `→ array\|false` | Fetch next row from cached SQL result |
| `sql_fetchfield($query_id, $field)` | `→ string\|false` | Fetch field from current row |
| `sql_rowseek($rownum, $query_id)` | `→ bool` | Seek to row in cached result |
| `sql_freeresult($query_id)` | `→ bool` | Free cached SQL rowset |
| `clean_query_id($query_id)` | `→ int\|string` | Normalize query ID type |

### Two Cache Subsystems

The interface defines **two parallel subsystems**:

1. **Key-Value Cache** (`get`/`put`/`destroy`/`_exists`) — for arbitrary data
2. **SQL Result Cache** (`sql_load`/`sql_save`/`sql_fetchrow`/etc.) — for caching entire DB result sets

---

## 3. Available Cache Drivers

**Source**: `src/phpbb/forums/cache/driver/` directory

| Driver | Class | Backend |
|--------|-------|---------|
| `file` | `phpbb\cache\driver\file` | PHP serialized files in `cache/production/` |
| `apcu` | `phpbb\cache\driver\apcu` | APCu shared memory |
| `memcached` | `phpbb\cache\driver\memcached` | Memcached daemon |
| `redis` | `phpbb\cache\driver\redis` | Redis server |
| `wincache` | `phpbb\cache\driver\wincache` | Windows Cache Extension |
| `dummy` | `phpbb\cache\driver\dummy` | No-op (testing) |

Inheritance: `driver_interface` ← `base` (abstract, implements sql_* methods) ← `file` / `memory` (abstract) ← `apcu`/`memcached`/`redis`/`wincache`

---

## 4. DI Configuration

### config.php — Driver Selection

**Source**: `src/phpbb/common/config/config.php` (Lines 1–17)

```php
$acm_type = 'phpbb\\cache\\driver\\file';
```

This is the **default** cache driver. In production, `config.php` at the project root would override it, but it's currently empty — so the system uses `src/phpbb/common/config/config.php`.

### DI Extension: `acm_type` → Driver Class

**Source**: `src/phpbb/forums/di/extension/config.php` (Lines 39–81)

```php
public function load(array $config, ContainerBuilder $container)
{
    $parameters = array(
        // ...
        'cache.driver.class' => $this->convert_30_acm_type($this->config_php->get('acm_type')),
        // ...
    );
    // sets them as container parameters
}

protected function convert_30_acm_type($acm_type)
{
    if (preg_match('#^[a-z]+$#', $acm_type))
    {
        return 'phpbb\\cache\\driver\\' . $acm_type;
    }
    return $acm_type;
}
```

**Flow**:
1. `config.php` defines `$acm_type` (e.g., `'file'` or `'phpbb\\cache\\driver\\file'`)
2. `config` DI extension reads it via `$this->config_php->get('acm_type')`
3. `convert_30_acm_type()` handles backward compatibility — if it's just a simple name like `file`, prefixes `phpbb\cache\driver\`; if it's already a FQCN, passes through
4. Sets container parameter `cache.driver.class` to the resolved FQCN

### Service Definitions in `services.yml`

**Source**: `src/phpbb/common/config/default/container/services.yml` (Lines 44–57)

```yaml
cache:
    class: phpbb\cache\service
    arguments:
         - '@cache.driver'
         - '@config'
         - '@dbal.conn'
         - '@dispatcher'
         - '%core.filesystem_root_path%'
         - '%core.php_ext%'

cache.driver:
    class: '%cache.driver.class%'
    arguments:
        - '%core.cache_dir%'
```

**Key observations**:
- `cache.driver` is the **raw driver** — class determined at runtime by `%cache.driver.class%` parameter
- `cache` is the **service wrapper** — takes the driver + config + db + dispatcher
- The only constructor arg for the driver is `%core.cache_dir%` (the cache directory path)

### Container Parameters

**Source**: `src/phpbb/common/config/default/container/parameters.yml`

The `parameters.yml` does **not** define `core.cache_dir` or `cache.driver.class` — these are set:
- `cache.driver.class` → by `di/extension/config.php` (from `acm_type`)
- `core.cache_dir` → presumably by the container builder / environment setup

### Runtime Switching

To switch the cache driver at runtime:
1. Change `$acm_type` in `config.php`
2. The DI container rebuild picks it up via `convert_30_acm_type()`
3. Or call `$cache->set_driver($newDriver)` on the service wrapper in code

---

## 5. Cache Consumers — DI Service Dependencies

### Services depending on `@cache` (the service wrapper)

| Service ID | YAML File | Purpose |
|------------|-----------|---------|
| `ext.manager` | `services.yml:126` | Extension manager |
| `group_helper` | `services.yml:141` | Group display helper |
| `version_helper` | `services.yml:189` | Version checking |
| `notification_manager` | `services_notification.yml:11` | Notification management |
| `attachment.upload` | `services_attachment.yml:32` | Attachment upload handling |
| `console.command.db.migrate` | `services_console.yml:92` | DB migration command |
| `console.command.db.revert` | `services_console.yml:104` | DB revert command |
| `console.command.thumbnail.generate` | `services_console.yml:220` | Thumbnail generation |
| `migrator.tool.module` | `services_migrator.yml:46` | Migration tool (modules) |
| `migrator.tool.permission` | `services_migrator.yml:59` | Migration tool (permissions) |

### Services depending on `@cache.driver` (raw driver)

| Service ID | YAML File | Purpose |
|------------|-----------|---------|
| `class_loader` | `services.yml:67` | PSR-4 class loading with cache |
| `class_loader.ext` | `services.yml:77` | Extension class loading |
| `config` (phpbb\config\db) | `services.yml:83` | Config storage |
| `controller.helper` | `services.yml:99` | Controller helper |
| `console.command.cache.purge` | `services_console.yml:20` | Cache purge command |
| `console.command.reparser.list` | `services_console.yml:182` | Text reparser list |
| All feed services (7) | `services_feed.yml` | RSS feed generators |
| All text_formatter services (3) | `services_text_formatter.yml` | BBCode/text parsing |
| `notification.method.board` | `services_notification.yml:204` | Board notification method |
| `module.manager` | `services_module.yml:5` | ACP/MCP module management |
| All avatar drivers (4) | `services_avatar.yml` | Avatar loading/caching |
| `content.visibility` | `services_content.yml:28` | Content visibility |
| `cron.task.core.tidy_cache` | `services_cron.yml:102` | Scheduled cache cleanup |
| `hook.finder` | `services_hook.yml:7` | Hook file discovery |

**Pattern**: Lower-level infrastructure services use `@cache.driver` directly, while higher-level domain services use `@cache` (the service wrapper) which provides the `obtain_*()` domain methods.

---

## 6. Representative Cache Usages in PHP Code

### Usage via `$cache->obtain_*()` (service wrapper domain methods)

```php
// web/viewtopic.php:619
$ranks = $cache->obtain_ranks();

// web/viewtopic.php:622
$icons = $cache->obtain_icons();

// web/viewtopic.php:628
$extensions = $cache->obtain_attach_extensions($forum_id);

// web/viewforum.php:457
$icons = $cache->obtain_icons();

// src/phpbb/forums/group/helper.php:246
$ranks = $this->cache->obtain_ranks();

// src/phpbb/forums/attachment/upload.php:245
$this->extensions = $this->cache->obtain_attach_extensions(($is_message) ? false : (int) $forum_id);
```

### Usage via delegated driver methods (get/put/destroy/_exists/purge)

```php
// src/phpbb/forums/config/db.php:123
$this->cache->destroy('config');

// src/phpbb/forums/lock/posting.php:68-73
if ($this->cache->_exists($this->lock_name) && !$this->config->offsetExists('ci_tests_no_lock_posting'))
// ...
$this->cache->put($this->lock_name, true, $this->config['flood_interval']);

// src/phpbb/forums/version_helper.php:376-414
$info = $this->cache->get($cache_file);
// ...
$this->cache->put($cache_file, $info, 86400); // 24 hours

// src/phpbb/forums/textformatter/s9e/factory.php:183-421
$renderer_data = $this->cache->get($this->cache_key_renderer);
$this->cache->put($this->cache_key_parser, $parser);
$this->cache->put($this->cache_key_renderer, $renderer_data);

// src/phpbb/forums/controller/helper.php:341-371
if (!$this->cache->get('_cron.lock_check'))
$this->cache->put('_cron.lock_check', true, 60);

// src/phpbb/forums/finder.php:77-542
$this->cached_queries = ($this->cache) ? $this->cache->get($this->cache_name) : false;
$this->cache->put($this->cache_name, $this->cached_queries);

// src/phpbb/forums/console/command/cache/purge.php:80
$this->cache->purge();

// src/phpbb/forums/module/module_manager.php:237-238
$this->cache->destroy('_modules_' . $cache_class);
$this->cache->destroy('sql', $this->modules_table);

// src/phpbb/forums/notification/manager.php:980
$this->cache->destroy('sql', $this->notification_types_table);
```

### Most Common Operations (by frequency)

1. **`get()`/`put()`** — generic key-value storage (version data, locks, text formatter, finders)
2. **`destroy()`** — cache invalidation (config changes, module changes, notification types)
3. **`obtain_*()`** — domain data (ranks, icons, extensions, bots, censored words)
4. **`purge()`** — full cache wipe (migrations, admin actions)
5. **`_exists()`** — existence checks (posting locks)

### SQL Result Caching (via DB driver)

SQL caching is **not called on the cache service** — it's called on the **DB driver** with a TTL parameter:

```php
// src/phpbb/forums/db/driver/mysqli.php:186-250
function sql_query($query = '', $cache_ttl = 0)
{
    $cache = $phpbb_app_container !== null ? $phpbb_app_container->getCache() : null;
    
    $this->query_result = ($cache && $cache_ttl) ? $cache->sql_load($query) : false;
    
    if ($this->query_result === false)
    {
        // Execute real query
        $this->query_result = @mysqli_query($this->db_connect_id, $query);
        
        if ($cache && $cache_ttl)
        {
            $this->query_result = $cache->sql_save($this, $query, $this->query_result, $cache_ttl);
        }
    }
}
```

**Important**: The DB driver calls `$cache->sql_load()` and `$cache->sql_save()` — but `$cache` here is obtained from the **container** (`$phpbb_app_container->getCache()`), which resolves to the **cache.driver** (raw driver), NOT the service wrapper. The sql_* methods are defined on `driver_interface` and implemented in `base`.

Common TTL values used in codebase: `120`, `600`, `3600`, `7200`, `86400`, `604800`.

---

## 7. Cache Data File Formats (File Driver)

### `data_global.php` — Multi-Key Global Store

**Source**: `cache/production/data_global.php`

Format:
```
<?php exit; ?>
{expiration_timestamp}
{byte_count}
{var_name}
{serialized_data}
{expiration_timestamp}
{byte_count}
{var_name}
{serialized_data}
...
```

Contains multiple key-value pairs in a single file. Currently stores:
- `mysqli_version` → `"10.11.16-MariaDB-ubu2204"`
- `config` → serialized array of 316 board configuration values

Keys **without** underscore prefix go into `data_global.php`.

### `data_*.php` — Individual Data Cache Files

**Source**: `cache/production/data_bots.php`, `data_ranks.php`, `data_icons.php`, etc.

Format:
```
<?php exit; ?>
{expiration_timestamp}
{byte_count}
{serialized_data}
```

Example `data_ranks.php`:
```
<?php exit; ?>
1808001552
268
a:1:{s:7:"special";a:2:{i:1;a:4:{...}i:2;a:4:{...}}}
```

- Line 1: PHP exit guard (prevents direct execution)
- Line 2: Expiration timestamp (Unix epoch)
- Line 3: Byte length of serialized data
- Line 4+: Serialized PHP array

Keys **with** underscore prefix (e.g., `_ranks`) are stored as individual files (`data_ranks.php`).

### `sql_*.php` — Cached SQL Query Results

**Source**: `cache/production/sql_3e34796286cb810b99597106afdc2dce.php`

Format:
```
<?php exit; ?>
{expiration_timestamp}
{SQL_query_text}
{byte_count}
{serialized_rowset_array}
```

Example `sql_3e34796286cb810b99597106afdc2dce.php`:
```
<?php exit; ?>
1777070374
SELECT notification_type_id, notification_type_name FROM phpbb_notification_types
887
a:8:{i:0;a:2:{...}i:1;a:2:{...}...}
```

- Filename: `sql_` + MD5 hash of normalized query
- Line 3: The original SQL query (for table-based invalidation via `destroy('sql', $table)`)
- The data is a numerically-indexed array of associative row arrays

### Lock Files

`sql_*.php.lock` and `data_global.php.lock` files exist — used by `\phpbb\lock\flock` for concurrent write safety.

### File Generation in the Driver

**Source**: `src/phpbb/forums/cache/driver/file.php`, `_write()` method (Lines 537–618)

```php
function _write($filename, $data = null, $expires = 0, $query = '')
{
    $lock = new \phpbb\lock\flock($file);
    $lock->acquire();
    
    fwrite($handle, '<' . '?php exit; ?' . '>');  // PHP guard
    
    if ($filename == 'data_global') {
        // Write multiple vars with individual expirations
        foreach ($this->vars as $var => $data) {
            fwrite($handle, "\n" . $this->var_expires[$var] . "\n");
            fwrite($handle, strlen($data . $var) . "\n");
            fwrite($handle, $var . "\n");
            fwrite($handle, $data);
        }
    } else {
        fwrite($handle, "\n" . $expires . "\n");
        if (strpos($filename, 'sql_') === 0) {
            fwrite($handle, $query . "\n");  // Store SQL for invalidation
        }
        fwrite($handle, strlen($data) . "\n");
        fwrite($handle, $data);
    }
    
    // opcache invalidated, chmod applied
    $lock->release();
}
```

### Key-Value Split Logic (`get`/`put`)

```php
function get($var_name)
{
    if ($var_name[0] == '_') {
        // Individual file: data{$var_name}.php
        return $this->_read('data' . $var_name);
    } else {
        // From data_global.php (loaded into $this->vars)
        return ($this->_exists($var_name)) ? $this->vars[$var_name] : false;
    }
}

function put($var_name, $var, $ttl = 31536000)
{
    if ($var_name[0] == '_') {
        // Write individual file
        $this->_write('data' . $var_name, $var, time() + $ttl);
    } else {
        // Store in memory, write to data_global on save()/unload()
        $this->vars[$var_name] = $var;
        $this->var_expires[$var_name] = time() + $ttl;
        $this->is_modified = true;
    }
}
```

Default TTL for `put()` in file driver: **31536000 seconds** (1 year).

### SQL Cache Invalidation via `destroy('sql', $table)`

```php
function destroy($var_name, $table = '')
{
    if ($var_name == 'sql' && !empty($table)) {
        // Scan all sql_*.php files
        // Read the query string from line 3
        // If query mentions any of the $table names, delete the file
    }
}
```

This is a **brute-force scan** — opens every `sql_*.php` file to check if its query contains the table name. Used for invalidation when data in those tables changes.

---

## 8. Summary of Architecture

```
config.php ($acm_type)
    ↓
di/extension/config.php → convert_30_acm_type() → %cache.driver.class%
    ↓
DI Container:
    cache.driver  (class: %cache.driver.class%, args: [%core.cache_dir%])
        ↓
    cache         (class: phpbb\cache\service, args: [@cache.driver, @config, @dbal.conn, @dispatcher, ...])
        ↓
    Consumers:
        - Domain code uses @cache (obtain_*, get, put, destroy via __call)
        - Infrastructure uses @cache.driver directly
        - DB driver calls cache.driver.sql_load/sql_save transparently
```

**Two access patterns**:
1. **High-level** (`@cache`): Used by most phpBB services. Provides `obtain_*()` domain methods plus transparent delegation to driver methods via `__call()`.
2. **Low-level** (`@cache.driver`): Used by infrastructure (config, class_loader, feeds, text_formatter, avatar drivers). Direct access to `driver_interface` methods. Required when you need the driver directly (e.g., `config\db` which IS a dependency of the service wrapper, so it can't use `@cache`).
