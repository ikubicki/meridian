# Avatar System Architecture — Findings

## 1. Driver Interface (`driver_interface`)

**Source**: `src/phpbb/forums/avatar/driver/driver_interface.php` (Lines 1–136)

Every avatar driver must implement these methods:

| Method | Purpose | Returns |
|--------|---------|---------|
| `get_name()` | Service container name of the driver | `string` |
| `get_config_name()` | Config key fragment (e.g. `upload`, `local`, `remote`, `gravatar`) | `string` |
| `get_data($row)` | Avatar URL + dimensions for display | `array{src, width, height}` |
| `get_custom_html($user, $row, $alt)` | Optional custom HTML override | `string` |
| `prepare_form($request, $template, $user, $row, &$error)` | Populate template for avatar selection form | `bool` |
| `prepare_form_acp($user)` | ACP-specific config fields | `array` |
| `process_form($request, $template, $user, $row, &$error)` | Process submitted avatar form → return avatar data | `array{avatar, avatar_width, avatar_height}` |
| `delete($row)` | Clean up avatar file (if local) | `bool` |
| `get_template_name()` | UCP template name | `string` |
| `get_acp_template_name()` | ACP template name | `string` |

**Key observation**: `$row` always comes through `manager::clean_row()` which strips `user_` / `group_` prefixes, normalizing to keys `avatar`, `avatar_type`, `avatar_width`, `avatar_height`, `id`.

---

## 2. Base Driver Class (`driver`)

**Source**: `src/phpbb/forums/avatar/driver/driver.php` (Lines 1–157)

Abstract class providing:

### Dependencies
```php
protected $config;          // \phpbb\config\config
protected $imagesize;       // \FastImageSize\FastImageSize
protected $phpbb_root_path; // string
protected $php_ext;         // string
protected $path_helper;     // \phpbb\path_helper
protected $cache;           // \phpbb\cache\driver\driver_interface (nullable)
```

### Allowed extensions
```php
protected $allowed_extensions = ['gif', 'jpg', 'jpeg', 'png'];
```
(Lines 70–76) — No webp, no svg, no bmp.

### Default implementations
- `get_custom_html()` → returns `''` (no custom HTML)
- `prepare_form_acp()` → returns `[]` (no ACP fields)
- `delete()` → returns `true` (no-op for non-file-based drivers)
- `get_config_name()` → strips `phpbb\avatar\driver\` from class name
- `get_acp_template_name()` → `'acp_avatar_options_' . $this->get_config_name() . '.html'`

---

## 3. Upload Driver — File Upload Flow

**Source**: `src/phpbb/forums/avatar/driver/upload.php` (Lines 1–340)

### Extra dependencies (beyond base driver)
```php
protected $filesystem;    // \phpbb\filesystem\filesystem_interface
protected $dispatcher;    // \phpbb\event\dispatcher_interface
protected $files_factory; // \phpbb\files\factory
```

### Upload flow (`process_form()`, Lines 108–267)

1. **Pre-check**: `can_upload()` verifies avatar path exists, is writable, and `file_uploads` INI is on
2. **Upload source**: Either:
   - **Form upload**: `$upload->handle_upload('files.types.form', 'avatar_upload_file')`
   - **Remote URL upload** (if `allow_avatar_remote_upload` is enabled): `$upload->handle_upload('files.types.remote', $url)` — URL is validated with regex patterns blocking IP addresses and custom ports
3. **Upload config**:
   ```php
   $upload->set_allowed_extensions($this->allowed_extensions)    // gif, jpg, jpeg, png
           ->set_max_filesize($this->config['avatar_filesize'])  // default: 6144 bytes
           ->set_allowed_dimensions(
               $this->config['avatar_min_width'],    // default: 20px
               $this->config['avatar_min_height'],   // default: 20px
               $this->config['avatar_max_width'],    // default: 90px
               $this->config['avatar_max_height'])   // default: 90px
           ->set_disallowed_content(explode('|', $this->config['mime_triggers']));
   ```
4. **Filename cleaning**:
   ```php
   $prefix = $this->config['avatar_salt'] . '_';
   $file->clean_filename('avatar', $prefix, $row['id']);
   ```
   (Line 176) — The `clean_filename('avatar', ...)` method generates: `{avatar_salt}_{user_id}.{ext}`
5. **Move file**: `$file->move_file($destination, true)` — `$destination` = `$this->config['avatar_path']` (default: `images/avatars/upload`)
6. **Event**: `core.avatar_driver_upload_move_file_before` fires before the move
7. **Old avatar cleanup**: If old avatar had different extension, `$this->delete($row)` removes it
8. **Return value**:
   ```php
   return [
       'avatar'        => $row['id'] . '_' . time() . '.' . $file->get('extension'),
       'avatar_width'  => $file->get('width'),
       'avatar_height' => $file->get('height'),
   ];
   ```

### File naming convention

**Physical file on disk**: `{avatar_salt}_{user_id}.{ext}`
- Example: `8fe48759f3b20fe7c212b7e838478fe0_42.jpg`

**Value stored in DB `avatar` column**: `{user_id}_{timestamp}.{ext}`
- Example: `42_1713484800.jpg`
- The timestamp serves as a cache-buster in the URL

### Storage path

Default: `images/avatars/upload/` relative to `$phpbb_root_path`

### Avatar deletion (`delete()`, Lines 275–315)

```php
$filename = $this->phpbb_root_path . $destination . '/' . $prefix . $row['id'] . '.' . $ext;
// → images/avatars/upload/{avatar_salt}_{user_id}.{ext}
```
- Fires event `core.avatar_driver_upload_delete_before`
- Uses `$this->filesystem->remove($filename)`

---

## 4. Avatar Serving

**Source**: `web/download/file.php` (Lines 33–155), `src/phpbb/common/functions_download.php` (Lines 22–97)

### Serving mechanism — `file.php` proxy

Uploaded avatars are **NOT** served via direct URL. They are served through `web/download/file.php?avatar={avatar_value}`.

**URL format**: `web/download/file.php?avatar=42_1713484800.jpg`
- For group avatars: `web/download/file.php?avatar=g42_1713484800.jpg` (prefixed with `g`)

### Serving flow (`file.php`, Lines 33–150):

1. Detect `$_GET['avatar']` — triggers lightweight bootstrap (no `common.php`, just `startup.php` + individual requires)
2. Parse filename:
   - Strip leading `g` for group avatars
   - Extract extension: `$ext = substr(strrchr($filename, '.'), 1)`
   - Extract timestamp: `$stamp = (int) substr(stristr($filename, '_'), 1)`
   - Extract numeric user ID: `$filename = (int) $filename`
3. **Validation**:
   - Must contain a dot (otherwise 403)
   - Must have extension in `['png', 'gif', 'jpg', 'jpeg']` (otherwise 403)
   - Must have a non-zero numeric filename (otherwise 403)
   - `set_modified_headers($stamp, $browser)` — sets HTTP cache headers, returns `true` if 304 can be sent
4. Call `send_avatar_to_browser()`

### `send_avatar_to_browser()` (`functions_download.php`, Lines 22–97):

```php
$prefix = $config['avatar_salt'] . '_';
$image_dir = $config['avatar_path'];
$file_path = $phpbb_root_path . $image_dir . '/' . $prefix . $file;
```
- Constructs: `{phpbb_root}/images/avatars/upload/{avatar_salt}_{user_id}.{ext}`
- Sets `Content-Type` via `getimagesize()`
- Sets `Cache-Control: public` + 1-year `Expires` header
- Streams file via `readfile()`, fallback to `fread()` loop
- Returns 404 if file not found

### Upload driver's `get_data()` (Line 73-80):
```php
return [
    'src'    => $root_path . 'web/download/file.' . $this->php_ext . '?avatar=' . $row['avatar'],
    'width'  => $row['avatar_width'],
    'height' => $row['avatar_height'],
];
```
Confirmed: always routes through `file.php` proxy.

---

## 5. Gallery / Local Driver

**Source**: `src/phpbb/forums/avatar/driver/local.php` (Lines 1–200)

### How it works
- Pre-bundled avatar images stored in `images/avatars/gallery/` (configured via `avatar_gallery_path`)
- Images are organized in subdirectories (categories)
- **No file upload** — user picks from existing gallery

### Serving — Direct URL (no proxy)
```php
// local.php get_data(), Line 26-32
return [
    'src'    => $root_path . $this->config['avatar_gallery_path'] . '/' . $row['avatar'],
    'width'  => $row['avatar_width'],
    'height' => $row['avatar_height'],
];
```
- DB stores: `{category}/{filename}` e.g. `Animals/cat.gif`
- Served directly from filesystem, **NOT** through `file.php`

### Gallery scanning (`get_avatar_list()`, Lines 152–200)
- Uses `RecursiveDirectoryIterator` to scan gallery path
- Filters by allowed extensions (gif, jpg, jpeg, png)
- Measures dimensions via `FastImageSize`
- Results cached for 24 hours (cache key: `_avatar_local_list_{lang}`)

### `delete()` — inherited no-op
Gallery avatars are shared assets, never deleted when user changes avatar.

---

## 6. Remote URL Driver

**Source**: `src/phpbb/forums/avatar/driver/remote.php` (Lines 1–244)

### How it works
- User provides an external image URL
- URL is validated and stored directly in DB
- **No file is downloaded or stored locally**

### Serving — Direct external URL
```php
// remote.php get_data(), Line 25-30
return [
    'src'    => $row['avatar'],   // raw external URL
    'width'  => $row['avatar_width'],
    'height' => $row['avatar_height'],
];
```

### URL validation (Lines 66–125)
1. Prepend `https://` if no scheme
2. Validate string length (5–255)
3. Regex validation blocks:
   - IP addresses (IPv4 and IPv6 — SSRF protection)
   - Custom ports (RFC 3986)
   - Must end with allowed extension
4. Fire event: `core.ucp_profile_avatar_upload_validation`
5. Dimension validation:
   - Tries `FastImageSize::getImageSize($url)` to get remote image dimensions
   - Falls back to user-supplied width/height
   - Enforces min/max dimension config
6. Content-Type check: opens stream, reads headers, verifies `image/` prefix

### `delete()` — inherited no-op
No local file to clean up.

---

## 7. Gravatar Driver

**Source**: `src/phpbb/forums/avatar/driver/gravatar.php` (Lines 1–200)

### How it works
- User provides email address
- Gravatar URL is dynamically constructed from SHA-256 hash of lowercase trimmed email

### URL construction (`get_gravatar_url()`, Lines 170–200):
```php
const GRAVATAR_URL = '//gravatar.com/avatar/';

$url = self::GRAVATAR_URL . hash('sha256', strtolower(trim($row['avatar'])));
if ($row['avatar_width'] || $row['avatar_height']) {
    $url .= '?s=' . max($row['avatar_width'], $row['avatar_height']);
}
```
- Fires event: `core.get_gravatar_url_after`

### DB stores: Email address in `avatar` column
### Custom HTML: Overrides `get_custom_html()` directly producing `<img>` tag
### Dimension handling: If user doesn't provide dimensions, defaults to `min(avatar_max_width, avatar_max_height)`
### `delete()` — inherited no-op

---

## 8. Manager Class

**Source**: `src/phpbb/forums/avatar/manager.php` (Lines 1–400)

### Dependencies
```php
protected $config;            // \phpbb\config\config
protected $phpbb_dispatcher;  // \phpbb\event\dispatcher_interface
protected $avatar_drivers;    // array — service collection of avatar drivers
```

### Driver registration (Lines 73–83)
Drivers are passed via Symfony DI service container as a service collection. Each is registered by its `get_name()` return value.

### `get_driver($avatar_type, $load_enabled = true)` (Lines 91–120)
- Lazy-loads enabled drivers list
- Handles legacy integer constants: `AVATAR_GALLERY` → `avatar.driver.local`, `AVATAR_UPLOAD` → `avatar.driver.upload`, `AVATAR_REMOTE` → `avatar.driver.remote`
- Returns `null` for unknown/disabled types

### `is_enabled($driver)` (Lines 262–267)
```php
return $this->config["allow_avatar_{$config_name}"];
```
Checks config flag per driver: `allow_avatar_upload`, `allow_avatar_local`, `allow_avatar_remote`, `allow_avatar_gravatar`.

### `clean_row($row, $prefix)` (Lines 200–222) — **Static method**
- Strips `user_` or `group_` prefix from array keys
- For groups: prefixes `id` with `g` → `'g' . $output['id']`
- Default row: `['avatar' => '', 'avatar_type' => '', 'avatar_width' => 0, 'avatar_height' => 0]`

### `handle_avatar_delete($db, $user, $avatar_data, $table, $prefix)` (Lines 315–358)
1. Get driver for current avatar type → call `$driver->delete($avatar_data)`
2. UPDATE the entity table (user or group) to clear all avatar columns
3. **If prefix is `group_`**: also UPDATE `phpbb_users` to clear avatar for all users who had that group avatar
4. Fires event: `core.avatar_manager_avatar_delete_after`

### `prefix_avatar_columns($prefix, $data)` (Lines 366–378)
Utility to add `user_` or `group_` prefix back to avatar column names for DB updates.

---

## 9. Database Schema — User & Group Avatar Columns

### Users table (`phpbb_users`)

**Source**: `phpbb_dump.sql` (Lines 4016–4019)

```sql
user_avatar         varchar(255) NOT NULL DEFAULT '',
user_avatar_type    varchar(255) NOT NULL DEFAULT '',
user_avatar_width   smallint(4) unsigned NOT NULL DEFAULT 0,
user_avatar_height  smallint(4) unsigned NOT NULL DEFAULT 0,
```

| Column | Purpose | Example values |
|--------|---------|---------------|
| `user_avatar` | Driver-specific value | Upload: `42_1713484800.jpg`, Remote: `https://example.com/img.png`, Gravatar: `user@email.com`, Local: `Animals/cat.gif` |
| `user_avatar_type` | Driver service name | `avatar.driver.upload`, `avatar.driver.remote`, `avatar.driver.local`, `avatar.driver.gravatar` |
| `user_avatar_width` | Width in px | `90` |
| `user_avatar_height` | Height in px | `90` |

### Groups table (`phpbb_groups`)

**Source**: `phpbb_dump.sql` (Lines 1858–1861)

```sql
group_avatar         varchar(255) NOT NULL DEFAULT '',
group_avatar_type    varchar(255) NOT NULL DEFAULT '',
group_avatar_width   smallint(4) unsigned NOT NULL DEFAULT 0,
group_avatar_height  smallint(4) unsigned NOT NULL DEFAULT 0,
```

Same structure, same semantics. Groups support avatars identically to users.

---

## 10. Configuration Settings

**Source**: `phpbb_dump.sql` (Lines 1041–1090), `src/phpbb/install/schemas/schema_data.sql` (Lines 11–60)

### Enable/disable flags

| Config key | Default | DB dump value | Description |
|------------|---------|---------------|-------------|
| `allow_avatar` | `1` | `1` | Master avatar switch |
| `allow_avatar_upload` | `1` | `1` | Enable upload driver |
| `allow_avatar_local` | `0` | `0` | Enable gallery driver |
| `allow_avatar_remote` | `0` | `0` | Enable remote URL driver |
| `allow_avatar_remote_upload` | `0` | `0` | Enable remote URL in upload form |
| `allow_avatar_gravatar` | `0` | `0` | Enable gravatar driver |

### Size/path settings

| Config key | Default | DB dump value | Description |
|------------|---------|---------------|-------------|
| `avatar_filesize` | `6144` | `6144` | Max upload size in **bytes** (6 KB) |
| `avatar_max_width` | `90` | `90` | Max width in px |
| `avatar_max_height` | `90` | `90` | Max height in px |
| `avatar_min_width` | `20` | `20` | Min width in px |
| `avatar_min_height` | `20` | `20` | Min height in px |
| `avatar_path` | `images/avatars/upload` | `images/avatars/upload` | Upload storage directory |
| `avatar_gallery_path` | `images/avatars/gallery` | `images/avatars/gallery` | Gallery directory |
| `avatar_salt` | `phpbb_avatar` | `8fe48759f3b20fe7c212b7e838478fe0` | Salt prepended to uploaded filenames |

---

## 11. Architecture Summary

### Driver pattern
```
driver_interface
      ↑
   driver (abstract base)
   ↑      ↑       ↑        ↑
upload   local   remote   gravatar
```

### Storage model per driver

| Driver | File stored locally? | DB `avatar` value | Serving mechanism |
|--------|---------------------|-------------------|-------------------|
| `upload` | Yes — `images/avatars/upload/{salt}_{id}.{ext}` | `{id}_{timestamp}.{ext}` | `file.php?avatar=` proxy |
| `local` | Pre-existing gallery images | `{category}/{filename}` | Direct URL to gallery path |
| `remote` | No | Full external URL | Direct external URL |
| `gravatar` | No | Email address | Gravatar CDN URL (constructed) |

### Key design observations

1. **Asymmetric naming**: Physical filename on disk (`{salt}_{id}.ext`) differs from DB value (`{id}_{timestamp}.ext`). The `file.php` serving code reconstructs the physical path by combining config salt + parsed numeric ID + extension.

2. **Lightweight bootstrap for avatar serving**: `file.php` uses a minimal bootstrap when `?avatar=` param is present — no `common.php`, just startup + constants + functions + DI container. This optimizes avatar serving performance.

3. **Group avatar propagation**: When a group avatar is deleted, all users referencing that avatar have their `user_avatar` cleared too.

4. **Cache-busting**: The timestamp in the DB `avatar` value (`{id}_{timestamp}`) is used by `file.php` for `If-Modified-Since` / 304 handling via `set_modified_headers($stamp, $browser)`.

5. **SSRF protection in remote drivers**: Both the upload driver's remote upload and the remote driver block IP addresses (IPv4/IPv6) and custom ports in URLs.

6. **Extension points**: Events at `core.avatar_driver_upload_move_file_before`, `core.avatar_driver_upload_delete_before`, `core.avatar_manager_avatar_delete_after`, `core.ucp_profile_avatar_upload_validation`, `core.get_gravatar_url_after`.

7. **No image resizing**: There is no server-side image resizing. The system enforces min/max dimensions at upload time and rejects images that don't comply.
