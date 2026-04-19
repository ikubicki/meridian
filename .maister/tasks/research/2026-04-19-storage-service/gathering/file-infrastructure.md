# File Upload & Filesystem Infrastructure — Findings

## Files Investigated

| File | Purpose |
|------|---------|
| `src/phpbb/forums/files/upload.php` | Generic file upload orchestrator |
| `src/phpbb/forums/files/filespec.php` | File specification / per-file operations |
| `src/phpbb/forums/files/factory.php` | DI container-based service factory for file types |
| `src/phpbb/forums/files/types/type_interface.php` | Upload type interface |
| `src/phpbb/forums/files/types/base.php` | Abstract base for upload types |
| `src/phpbb/forums/files/types/form.php` | HTML form upload handler |
| `src/phpbb/forums/files/types/remote.php` | Remote URL upload handler |
| `src/phpbb/forums/files/types/local.php` | Local file (server-side) upload handler |
| `src/phpbb/forums/attachment/upload.php` | High-level attachment upload (uses files/upload) |
| `src/phpbb/forums/filesystem/filesystem_interface.php` | Filesystem abstraction interface |
| `src/phpbb/forums/filesystem/filesystem.php` | Filesystem implementation (wraps Symfony Filesystem) |
| `src/phpbb/forums/filesystem/exception/filesystem_exception.php` | Filesystem exception |
| `src/phpbb/forums/mimetype/guesser.php` | MIME type guesser orchestrator |
| `src/phpbb/forums/mimetype/guesser_interface.php` | MIME guesser interface |
| `src/phpbb/forums/mimetype/guesser_base.php` | Abstract base for MIME guessers |
| `src/phpbb/forums/mimetype/content_guesser.php` | Content-based MIME detection (mime_content_type) |
| `src/phpbb/forums/mimetype/extension_guesser.php` | Extension-based MIME mapping (~470 extensions) |
| `src/phpbb/forums/plupload/plupload.php` | Chunked upload support (Plupload JS integration) |
| `src/phpbb/common/functions_posting.php` | Thumbnail creation (GD-based) |
| `src/phpbb/forums/cache/service.php` | Extensions cache (obtain_attach_extensions) |

---

## 1. Upload Class (`phpbb\files\upload`)

**File**: `src/phpbb/forums/files/upload.php` (Lines 1–400, complete)

### Constructor DI Dependencies

```php
public function __construct(
    filesystem_interface $filesystem,   // phpbb\filesystem\filesystem_interface
    factory $factory,                   // phpbb\files\factory (DI container wrapper)
    language $language,                 // phpbb\language\language
    \bantu\IniGetWrapper\IniGetWrapper $php_ini,  // ini_get wrapper
    request_interface $request          // phpbb\request\request_interface
)
```

### Public Properties

| Property | Type | Default | Purpose |
|----------|------|---------|---------|
| `$allowed_extensions` | `array` | `[]` | Whitelist of allowed file extensions |
| `$max_filesize` | `int` | `0` | Max file size in bytes (0 = unlimited) |
| `$min_width` | `int` | `0` | Min image width |
| `$min_height` | `int` | `0` | Min image height |
| `$max_width` | `int` | `0` | Max image width |
| `$max_height` | `int` | `0` | Max image height |
| `$error_prefix` | `string` | `''` | Prefix for language error keys |
| `$upload_timeout` | `int` | `6` | Remote upload timeout (seconds) |

### Protected Property — Disallowed Content

```php
protected $disallowed_content = array(
    'body', 'head', 'html', 'img', 'plaintext',
    'a href', 'pre', 'script', 'table', 'title'
);
```
These are HTML tag names scanned in the first 256 bytes of uploaded files to prevent IE MIME sniffing attacks.

### Public Methods

| Method | Signature | Purpose |
|--------|-----------|---------|
| `reset_vars()` | `void` | Reset all constraints to defaults |
| `set_allowed_extensions($ext)` | `→ self` | Set allowed extension whitelist (fluent) |
| `set_allowed_dimensions($minW, $minH, $maxW, $maxH)` | `→ self` | Set image dimension constraints (fluent) |
| `set_max_filesize($size)` | `→ self` | Set max filesize (fluent) |
| `set_disallowed_content($content)` | `→ self` | Set disallowed HTML content strings (fluent) |
| `set_error_prefix($prefix)` | `→ self` | Set language key prefix for errors (fluent) |
| `handle_upload($type, ...)` | `→ filespec\|false` | Delegate upload to type handler via factory |
| `assign_internal_error($code)` | `→ string` | Map PHP `UPLOAD_ERR_*` constants to language strings |
| `common_checks($file)` | `void` | Run standard validation: filesize, filename chars, extension, content sniffing |
| `valid_extension($file)` | `→ bool` | Check extension against whitelist |
| `valid_dimensions($file)` | `→ bool` | Check image dimensions against constraints |
| `is_valid($form_name)` | `→ bool` | Check if a form upload is present |
| `valid_content($file)` | `→ bool` | Delegate to `filespec::check_content()` |
| `image_types()` | `→ array` (static) | Map `IMAGETYPE_*` constants to file extensions |

### Upload Flow (handle_upload)

```
handle_upload($type, ...$args)
  → factory->get($type)           // e.g., 'files.types.form'
  → type_class->set_upload($this)  // inject upload class into type
  → type_class->upload(...$args)   // delegate actual upload
  → returns filespec instance
```

The factory pattern uses the Symfony DI container to resolve type services by name (e.g., `'files.types.form'` → `phpbb\files\types\form`).

---

## 2. Filespec Class (`phpbb\files\filespec`)

**File**: `src/phpbb/forums/files/filespec.php` (Lines 1–580, complete)

### Constructor DI Dependencies

```php
public function __construct(
    \phpbb\filesystem\filesystem_interface $phpbb_filesystem,
    language $language,
    \bantu\IniGetWrapper\IniGetWrapper $php_ini,
    \FastImageSize\FastImageSize $imagesize,       // Fast image dimension reader
    $phpbb_root_path,
    \phpbb\mimetype\guesser $mimetype_guesser = null,  // Optional
    \phpbb\plupload\plupload $plupload = null          // Optional
)
```

**Key**: Uses `FastImageSize\FastImageSize` for reading image dimensions (not GD's `getimagesize()`).

### Properties (all protected)

| Property | Type | Purpose |
|----------|------|---------|
| `$filename` | `string` | Temp file path |
| `$realname` | `string` | Sanitized / generated destination name |
| `$uploadname` | `string` | Original upload name (preserved) |
| `$mimetype` | `string` | Detected MIME type |
| `$extension` | `string` | File extension (lowercase) |
| `$filesize` | `int` | File size in bytes |
| `$width` | `int` | Image width (0 for non-images) |
| `$height` | `int` | Image height (0 for non-images) |
| `$image_info` | `array` | Result of FastImageSize detection |
| `$destination_file` | `string` | Full destination path after move |
| `$destination_path` | `string` | Destination directory |
| `$file_moved` | `bool` | Whether file has been moved to destination |
| `$local` | `bool` | Whether file is from local filesystem |
| `$error` | `array` | Error messages (public) |

### Key Methods

#### `set_upload_ary($upload_ary)` (Lines 105–139)

Initializes filespec from `$_FILES`-like array:
- Sets `$filename = $upload_ary['tmp_name']`
- Strips Opera mime-type noise (`; name`)
- Defaults mimetype to `'application/octet-stream'` if empty
- Extracts lowercase extension via `self::get_extension()`
- Tries to get real filesize from temp file

#### `clean_filename($mode, $prefix, $user_id)` (Lines 170–207)

Modes:
- **`'real'`**: Strips ALL extensions (not just last!), replaces bad chars, lowercases, URL-encodes, re-adds single extension
- **`'unique'`**: `$prefix . md5(unique_id())` — no extension!
- **`'avatar'`**: `$prefix . $user_id . '.' . $extension`
- **`'unique_ext'`** (default): `$prefix . md5(unique_id()) . '.' . $extension`

**Security note on `'real'` mode** (Line 179): Strips everything from first `.` onward — prevents double-extension attacks like `file.php.jpg`.

#### `check_content($disallowed_content)` (Lines 288–307)

```php
$fp = @fopen($this->filename, 'rb');
$ie_mime_relevant = fread($fp, 256);  // Read first 256 bytes
fclose($fp);
foreach ($disallowed_content as $forbidden) {
    if (stripos($ie_mime_relevant, '<' . $forbidden) !== false) {
        return false;  // HTML tag found — reject
    }
}
```
Scans first 256 bytes for HTML-like content to prevent IE MIME sniffing XSS.

#### `move_file($destination, $overwrite, $skip_image_check, $chmod)` (Lines 400–570)

Full move logic:
1. Prepend `$phpbb_root_path` to destination → `$this->destination_path`
2. Check destination directory exists
3. Choose upload mode based on `open_basedir`:
   - **No open_basedir**: `copy` first, fallback `move_uploaded_file`
   - **open_basedir set**: `move_uploaded_file` first, fallback `copy`
   - **Local mode**: `copy` only
4. `@unlink()` temp file after transfer
5. **`phpbb_chmod()`** on destination with `CHMOD_READ | CHMOD_WRITE` (default)
6. Re-read filesize from destination
7. **MIME re-detection** via `$this->mimetype_guesser->guess()` on moved file
8. **Image validation** (if not skipped):
   - Uses `FastImageSize::getImageSize()` with mimetype hint
   - Extracts width/height
   - Cross-checks image type constant against extension via `upload::image_types()`
   - Validates extension matches image type (prevents `.php` renamed to `.jpg`)
   - Ensures non-zero dimensions
9. Calls `additional_checks()` for filesize/dimension re-validation

#### `get_mimetype($filename)` (Lines 247–258)

```php
public function get_mimetype($filename)
{
    if ($this->mimetype_guesser !== null) {
        $mimetype = $this->mimetype_guesser->guess($filename, $this->uploadname);
        if ($mimetype !== 'application/octet-stream') {
            $this->mimetype = $mimetype;
        }
    }
    return $this->mimetype;
}
```
Uses the MIME guesser chain, but keeps previous value if guesser returns `application/octet-stream`.

---

## 3. Upload Type Handlers

### Type Interface (`phpbb\files\types\type_interface`)

```php
interface type_interface {
    public function upload();                     // Handle upload, return filespec|false
    public function set_upload(upload $upload);     // Inject parent upload instance
}
```

### Base Class (`phpbb\files\types\base`)

Abstract class with:
- `check_upload_size($file)` — Checks PHP's `upload_max_filesize` INI limit
- `set_upload($upload)` — Stores reference to `upload` instance

### Form Upload (`phpbb\files\types\form`) — Lines 1–155

**DI**: `factory`, `language`, `php_ini`, `plupload`, `request`

Flow:
1. `$this->request->file($form_name)` — get `$_FILES` data
2. `$this->plupload->handle_upload($form_name)` — handle chunked uploads, merge array
3. Create `filespec` via factory
4. Check for PHP upload errors via `$this->upload->assign_internal_error()`
5. Check empty file (size == 0)
6. Check PHP upload size limit
7. Verify `is_uploaded_file()`
8. Run `$this->upload->common_checks($file)` (extension, content, filesize)

### Remote Upload (`phpbb\files\types\remote`) — Lines 1–230

**DI**: `config`, `factory`, `language`, `php_ini`, `request`, `phpbb_root_path`

Flow:
1. **URL validation**: `preg_match('#^(https?://).*?\.(' . allowed_extensions . ')$#i', $url)` — only HTTP/HTTPS, must end with allowed extension
2. Parse URL, extract extension from path
3. Calculate max file size from upload settings or PHP INI
4. **Download via Guzzle** with timeout and optional SSL verification (`remote_upload_verify` config)
5. Check content length against size limit
6. Write to temp file via `tempnam(sys_get_temp_dir(), unique_id() . '-')`
7. Create `filespec` with `local_mode = true`
8. Run `common_checks()`

**Security note**: Remote uploads do NOT verify `is_uploaded_file()` — they use `local_mode`.

### Local Upload (`phpbb\files\types\local`) — Lines 1–130

**DI**: `factory`, `language`, `php_ini`, `request`

Used for server-side file moves (e.g., during updates/migrations). Sets `local_mode = true`, bypasses `is_uploaded_file()` check.

---

## 4. Files Factory (`phpbb\files\factory`)

**File**: `src/phpbb/forums/files/factory.php` (Lines 1–60)

Simple DI container wrapper:

```php
public function get($name)
{
    $name = (strpos($name, '.') === false) ? 'files.' . $name : $name;
    return $this->container->get($name);
}
```

Maps short names to DI service IDs:
- `'filespec'` → `'files.filespec'`
- `'files.types.form'` → passed through
- `'files.types.remote'` → passed through
- `'files.types.local'` → passed through

---

## 5. MIME Type Detection

### Architecture

Three-layer prioritized guesser chain (`phpbb\mimetype\guesser`):

| Guesser | Method | Priority | Fallback |
|---------|--------|----------|----------|
| `content_guesser` | `mime_content_type()` | Default (0) | Returns PHP's built-in detection |
| `extension_guesser` | Extension → map lookup | Default (0) | ~470 extension mappings |
| (Symfony `FileinfoMimeTypeGuesser` also possible via DI) | `finfo_file()` | - | - |

### Guesser Interface

```php
interface guesser_interface {
    public function is_supported(): bool;
    public function guess($file, $file_name = ''): string;
    public function get_priority(): int;
    public function set_priority($priority): void;
}
```

### Content Guesser (`content_guesser`)

```php
public function is_supported() {
    return function_exists('mime_content_type') && is_callable('mime_content_type');
}
public function guess($file, $file_name = '') {
    return mime_content_type($file);   // Uses file content, not extension
}
```

### Extension Guesser (`extension_guesser`)

- Contains a hardcoded map of ~470 extensions → MIME types (Lines 24–470)
- Always supported (`is_supported()` returns `true`)
- Maps via `pathinfo($file_name, PATHINFO_EXTENSION)` lookup
- Used as fallback when content detection returns `application/octet-stream`

### Guesser Priority Logic (`guesser::choose_mime_type`)

```php
// If guess is null or octet-stream → keep previous
// If current is octet-stream or guess contains '/' → use guess
// Otherwise keep current
```
Guessers sorted highest priority first. Result is the best non-generic guess.

---

## 6. Filesystem Abstraction

### Interface (`phpbb\filesystem\filesystem_interface`)

**File**: `src/phpbb/forums/filesystem/filesystem_interface.php` (Lines 1–300)

#### Permission Constants

```php
const CHMOD_ALL = 7;
const CHMOD_READ = 4;
const CHMOD_WRITE = 2;
const CHMOD_EXECUTE = 1;
```

#### Methods

| Method | Purpose |
|--------|---------|
| `chgrp($files, $group, $recursive)` | Change file group |
| `chmod($files, $perms, $recursive, $force_link)` | Basic chmod |
| `chown($files, $user, $recursive)` | Change file owner |
| `clean_path($path)` | Remove `.` and `..` from path |
| `copy($origin, $target, $override)` | Copy file |
| `dump_file($filename, $content)` | Atomic write |
| `exists($files)` | Check file existence |
| `is_absolute_path($path)` | Check if path is absolute |
| `is_readable($files, $recursive)` | Check readability |
| `is_writable($files, $recursive)` | Check writability |
| `make_path_relative($end, $start)` | Relative path calculation |
| `mirror($origin, $target, $iterator, $options)` | Mirror directory |
| `mkdir($dirs, $mode)` | Create directories recursively |
| `phpbb_chmod($file, $perms, $recursive, $force_link)` | Smart chmod with owner detection |
| `realpath($path)` | Resolve symlinks |
| `remove($files)` | Delete files/dirs |
| `rename($origin, $target, $overwrite)` | Rename/move |
| `symlink($origin, $target, $copy_on_windows)` | Create symlinks |
| `touch($files, $time, $access_time)` | Set timestamps |

### Implementation (`phpbb\filesystem\filesystem`)

**File**: `src/phpbb/forums/filesystem/filesystem.php` (Lines 1–400+)

- Wraps **Symfony's `Filesystem` component** (`\Symfony\Component\Filesystem\Filesystem`)
- `phpbb_chmod()` is a sophisticated custom method that:
  1. Detects file owner/group using `fileowner()`/`filegroup()`
  2. Compares with PHP process UID via `posix_getuid()`/`posix_getgroups()`
  3. Sets appropriate permissions based on ownership relationship
  4. Auto-adds execute bit for directories
- Windows compatibility: `is_writable()` uses custom `phpbb_is_writable()` fallback

---

## 7. Thumbnail Generation

**File**: `src/phpbb/common/functions_posting.php` (Lines 534–810)

### Size Calculation — `get_img_size_format($width, $height)`

```php
$max_width = $config['img_max_thumb_width'] ?: 400;
// Scale proportionally to fit max_width
if ($width > $height) {
    // scale by width
} else {
    // scale by height
}
```
Config key: `img_max_thumb_width` (default: 400px). Always scales proportionally.

### Supported Image Types — `get_supported_image_types($type)`

Checks GD extension for support of: `IMG_GIF`, `IMG_JPG`, `IMG_PNG`, `IMG_WBMP`, `IMG_WEBP`.
Returns GD version info (1 or 2).

### Thumbnail Creation — `create_thumbnail($source, $destination, $mimetype)`

**Lines 633–810**

1. **Minimum filesize check**: Skips if file ≤ `$config['img_min_thumb_filesize']`
2. **Get dimensions**: `getimagesize($source)` (NOTE: uses GD's getimagesize, not FastImageSize)
3. **Calculate scaled size** via `get_img_size_format()`
4. **Skip if thumbnail would be larger** than original
5. **Event hook**: `core.thumbnail_create_before` — allows extensions to replace GD (e.g., ImageMagick)
6. **GD processing**:
   - Load source based on type (`imagecreatefromgif/jpeg/png/wbmp/webp`)
   - GD v1: `imagecopyresized()` (no antialiasing)
   - GD v2: `imagecreatetruecolor()` + `imagecopyresampled()` (bicubic)
   - Preserves alpha transparency (`imagealphablending(false)`, `imagesavealpha(true)`)
   - JPEG quality: **90**
7. **chmod** destination with `CHMOD_READ | CHMOD_WRITE`

### Thumbnail Naming Convention

Set in `attachment/upload.php` Line 190:
```php
$destination = $this->file->get('destination_path') . '/thumb_' . $this->file->get('realname');
```
Prefix: **`thumb_`** + physical filename.

### Config Keys for Thumbnails

| Config Key | Purpose |
|------------|---------|
| `img_create_thumbnail` | Boolean — enable thumbnail creation |
| `img_max_thumb_width` | Max thumbnail width (default 400) |
| `img_min_thumb_filesize` | Min filesize to create thumbnail |
| `img_max_width` | Max image width for uploads |
| `img_max_height` | Max image height for uploads |
| `img_quality` | JPEG quality (used by plupload resize) |
| `img_strip_metadata` | Strip EXIF metadata (used by plupload) |

---

## 8. Plupload / Chunked Upload Support

**File**: `src/phpbb/forums/plupload/plupload.php` (Lines 1–470)

### Constructor DI

```php
public function __construct(
    $phpbb_root_path,
    \phpbb\config\config $config,
    \phpbb\request\request_interface $request,
    \phpbb\user $user,
    \bantu\IniGetWrapper\IniGetWrapper $php_ini,
    \phpbb\mimetype\guesser $mimetype_guesser
)
```

### Chunked Upload Flow (`handle_upload`)

1. Check if chunked (`chunks` request var ≥ 2)
2. Prepare temporary directory: `{upload_path}/plupload/`
3. Temp file path: `{plupload_dir}/{salt}_{md5(filename)}{extension}`
4. Each chunk: `move_uploaded_file()` → append to `.part` file
5. Last chunk: rename `.part` → final, return merged file array with MIME guess
6. Between chunks: Return JSON response and exit

### Chunk Size Calculation (`get_chunk_size`)

Takes minimum of `memory_limit`, `upload_max_filesize`, `post_max_size` and divides by 2.

### Client-Side Configuration (`configure`)

Generates Plupload JS config with:
- Extension filters grouped by extension group name
- Max file sizes per group
- Chunk size
- Auto-resize settings (based on `img_max_width`, `img_max_height`, `img_quality`)

### Temp File Cleanup

Temp chunks stored in `{upload_path}/plupload/`. No automatic cleanup mechanism visible — relies on chunk assembly completing.

---

## 9. Attachment Upload Orchestrator (`phpbb\attachment\upload`)

**File**: `src/phpbb/forums/attachment/upload.php` (Lines 1–370)

### Constructor DI (10 dependencies!)

```php
public function __construct(
    auth $auth,
    service $cache,
    config $config,
    \phpbb\files\upload $files_upload,
    language $language,
    guesser $mimetype_guesser,
    dispatcher $phpbb_dispatcher,
    plupload $plupload,
    user $user,
    $phpbb_root_path
)
```

### Main Upload Method — `upload($form_name, $forum_id, $local, $local_storage, $is_message, $local_filedata)`

Full orchestration flow:

1. **`init_files_upload()`**: Configure disallowed content from `$config['mime_triggers']` and load allowed extensions from cache
2. **Handle upload**: Delegate to `files.types.form` or `files.types.local`
3. **Image dimension constraints**: Set from `$config['img_max_width']`/`$config['img_max_height']` (only for non-admin users)
4. **Filesize limits**: Per-extension from DB (`max_filesize`), or global (`max_filesize`/`max_filesize_pm`)
5. **Clean filename**: `unique` mode with user ID prefix → `{user_id}_{md5}.{ext}`
6. **Move file**: To `$config['upload_path']` (default: `files/`)
7. **Check image validity**: Verify MIME matches category
8. **Event hook**: `core.modify_uploaded_file` — post-upload modification
9. **Check quota**: `$config['attachment_quota']` vs `$config['upload_dir_size']`
10. **Check disk space**: `disk_free_space()`
11. **Create thumbnail**: If image + `img_create_thumbnail` config enabled

### File Data Return Array

```php
[
    'post_attach'         => bool,
    'error'               => array,
    'filesize'            => int,
    'mimetype'            => string,
    'extension'           => string,
    'physical_filename'   => string,  // Generated unique name
    'real_filename'       => string,  // Original upload name
    'filetime'            => int,     // time()
    'thumbnail'           => int,     // 0 or 1
]
```

---

## 10. Allowed Extensions Management

**Source**: `src/phpbb/forums/cache/service.php` (Lines 225–335)

Extensions are managed via two database tables:
- **`EXTENSIONS_TABLE`** — individual extensions (e.g., `jpg`, `png`, `pdf`)
- **`EXTENSION_GROUPS_TABLE`** — groups with shared settings

### Cache Structure (`obtain_attach_extensions`)

```php
[
    '_allowed_post' => ['jpg' => 0, 'png' => [forum_ids], ...],  // 0 = all forums
    '_allowed_pm'   => ['jpg' => 0, ...],
    'jpg' => [
        'display_cat'    => int,    // ATTACHMENT_CATEGORY_IMAGE etc.
        'download_mode'  => int,
        'upload_icon'    => string,
        'max_filesize'   => int,    // Per-extension max size
        'allow_group'    => bool,
        'allow_in_pm'    => bool,
        'group_name'     => string,
    ],
    ...
]
```

**Per-forum filtering**: Extensions can be restricted to specific forums via `allowed_forums` serialized array in the group row.

---

## 11. Security Measures Summary

| Measure | Location | Description |
|---------|----------|-------------|
| **Extension whitelist** | `upload::valid_extension()` | Only DB-configured extensions allowed |
| **Content sniffing prevention** | `filespec::check_content()` | Scans first 256 bytes for HTML tags |
| **Disallowed content** | `upload::$disallowed_content` | Default: `body, head, html, img, script, table, title, a href, pre, plaintext` |
| **Configurable MIME triggers** | `attachment/upload::init_files_upload()` | `$config['mime_triggers']` pipe-separated list |
| **Double extension prevention** | `filespec::clean_filename('real')` | Strips everything from first dot |
| **Unique filename generation** | `filespec::clean_filename('unique_ext')` | `md5(unique_id()).ext` — no user-controlled path |
| **Image type vs extension check** | `filespec::move_file()` Lines 530–550 | Cross-checks `IMAGETYPE_*` constant against extension |
| **MIME re-detection after move** | `filespec::move_file()` Line 520 | Re-guesses MIME from moved file content |
| **Image category validation** | `attachment/upload::check_image()` | Rejects non-images in image category |
| **is_uploaded_file() check** | `filespec::is_uploaded()` | Verifies PHP actually uploaded the file |
| **URL validation (remote)** | `remote::remote_upload()` | Regex: only `https?://` + allowed extension |
| **Upload quota** | `attachment/upload::check_attach_quota()` | Global attachment size limit |
| **Disk space check** | `attachment/upload::check_disk_space()` | `disk_free_space()` check |
| **chmod after move** | `filespec::move_file()` | Sets `CHMOD_READ \| CHMOD_WRITE` (no execute) |
| **Admin/mod bypass** | `attachment/upload::upload()` | Admins/mods skip filesize and dimension checks |

---

## 12. File Permissions After Upload

Default chmod mask in `filespec::move_file()`:
```php
$chmod = ($chmod === false)
    ? filesystem_interface::CHMOD_READ | filesystem_interface::CHMOD_WRITE
    : $chmod;
// = 6 (rw-)
```

The `phpbb_chmod()` method in filesystem then expands this based on file owner detection:
- **Owner**: always gets `CHMOD_READ | CHMOD_WRITE` (plus execute if specified)
- **Group/Other**: gets the requested permission bits
- **Directories**: automatically get execute bit added if any permission bit is set

Thumbnails also get `CHMOD_READ | CHMOD_WRITE` after creation.

---

## 13. Temp File Handling

| Source | Temp Location | Cleanup |
|--------|---------------|---------|
| **Form upload** | PHP's `upload_tmp_dir` | `@unlink()` in `filespec::move_file()` after copy/move |
| **Remote upload** | `sys_get_temp_dir()` via `tempnam()` | `@unlink()` in `filespec::move_file()` |
| **Plupload chunks** | `{upload_path}/plupload/` | Chunks deleted after assembly; `.part` renamed to final |
| **Failed uploads** | Various | `filespec::remove()` calls `@unlink($destination_file)` |

**Gap**: No cron-based cleanup for orphaned plupload chunks if client abandons upload mid-stream.

---

## 14. Key Observations for Storage Service Design

1. **No storage abstraction layer**: Files are always written to local filesystem via `move_file()` with hardcoded  `$this->phpbb_root_path . $destination`. No interface for swapping to S3/cloud.

2. **Tight coupling**: `filespec::move_file()` combines file movement, permission setting, MIME detection, image validation, and dimension checking in one 170-line method.

3. **Two validation passes**: `common_checks()` runs before move, `additional_checks()` runs after move — filesize is checked twice.

4. **Thumbnail generation is procedural**: Lives in `functions_posting.php` as a plain function, not OOP. Uses GD only. Has event hook for extension.

5. **MIME detection happens twice**: Once from `$_FILES['type']` (client-provided, unreliable), then re-detected after file move via `mimetype_guesser`.

6. **Extension system is complete**: Per-forum, per-group, per-PM filtering with caching. Max filesize per extension group. Display category determines handling.

7. **Plupload provides client-side resize**: Via JS, before upload — can strip metadata, resize images to max dimensions.

8. **FastImageSize vs GD**: Filespec uses `FastImageSize` for dimension reading (fast, no memory issues), but thumbnail creation uses GD's `getimagesize()` + `imagecreatefrom*()`.
