# File Download & Serving Logic

## 1. Entry Point: `web/download/file.php`

The single entry point handles **two distinct modes** determined by the `avatar` GET parameter:

### Branch 1: Avatar Serving (Lines 36–138)

Triggered when `$_GET['avatar']` is set. **Lightweight bootstrap** — does NOT start a full user session:
- Loads startup, class loader, config, constants, functions_download, UTF tools
- Builds the DI container
- Does NOT call `$user->session_begin()`

```
Source: web/download/file.php:36-138
```

**Avatar URL pattern**: `download/file.php?avatar={filename}`

Where filename format is: `[g]{user_id}_{timestamp}.{ext}`
- Prefix `g` = group avatar (stripped before numeric parsing)
- `{user_id}` = integer (extracted via `(int) $filename`)
- `{timestamp}` = integer after underscore (used for cache validation)
- `{ext}` = file extension

**Avatar validation flow** (file.php:108–137):
1. Check first char — `g` prefix means group avatar
2. Reject if no dot in filename → 403 Forbidden
3. Extract extension, timestamp, numeric ID
4. Call `set_modified_headers($stamp, $browser)` — may return 304
5. Reject if extension not in `['png', 'gif', 'jpg', 'jpeg']` → 403 Forbidden
6. Reject if filename resolves to `0` → 403 Forbidden
7. Call `send_avatar_to_browser()`
8. Call `file_gc()` (close DB + exit)

### Branch 2: Attachment Download (Lines 140–318)

Falls through when no `avatar` param. **Full bootstrap** via `common.php`:

```php
// file.php:140-146
include($phpbb_filesystem_root . 'src/phpbb/common/common.php');
require($phpbb_filesystem_root . 'src/phpbb/common/functions_download.php');

$attach_id = $request->variable('id', 0);
$mode = $request->variable('mode', '');
$thumbnail = $request->variable('t', false);
```

**Attachment URL patterns** (from functions_content.php usage):
| Pattern | Purpose | Source |
|---------|---------|--------|
| `download/file.php?id={attach_id}` | Direct download | functions_content.php:1338 |
| `download/file.php?id={attach_id}&mode=view` | Inline view (images) | functions_content.php:1345 |
| `download/file.php?id={attach_id}&t=1` | Thumbnail | functions_content.php:1358 |
| `download/file.php?mode=view&id={attach_id}` | ACP/posting preview | acp_attachments.php:1118 |

**Session handling** (file.php:149–151):
```php
$user->session_begin(false);  // false = do NOT update session page timestamp
$auth->acl($user->data);
$user->setup('viewtopic');
```

---

## 2. Attachment Download Flow (Step by Step)

### Step 1: Global Checks (file.php:155–162)
```php
if (!$config['allow_attachments'] && !$config['allow_pm_attach'])
    → 404 'ATTACHMENT_FUNCTIONALITY_DISABLED'

if (!$attach_id)
    → 404 'NO_ATTACHMENT_SELECTED'
```

### Step 2: Attachment Lookup (file.php:164–173)
```php
$sql = 'SELECT attach_id, post_msg_id, topic_id, in_message, poster_id,
        is_orphan, physical_filename, real_filename, extension,
        mimetype, filesize, filetime
    FROM ' . ATTACHMENTS_TABLE . "
    WHERE attach_id = $attach_id";
```
Note: `$attach_id` is cast to int via `$request->variable('id', 0)` so it's safe.

### Step 3: Hotlink Protection (file.php:177–179)
```php
if (!download_allowed())
    → 403 'LINKAGE_FORBIDDEN'
```
See [Section 5](#5-secure-downloads--hotlink-protection) for details.

### Step 4: Scope Check — Is attachment for forum post or PM? (file.php:185–191)
```php
if (!$attachment['in_message'] && !$config['allow_attachments'])
    → 404 'ATTACHMENT_FUNCTIONALITY_DISABLED'
if ($attachment['in_message'] && !$config['allow_pm_attach'])
    → 404 'ATTACHMENT_FUNCTIONALITY_DISABLED'
```

### Step 5: Orphan Handling (file.php:201–216)
If `is_orphan` is true:
- Allowed only for admins with `a_attach` permission OR the original poster
- Must also have `u_pm_download` (PM) or `u_download` (post) permission
- Extensions loaded from cache with all extensions enabled (`obtain_attach_extensions(true)`)

### Step 6: Authentication for Non-Orphans

**Forum attachments** (file.php:220–238):
1. `phpbb_download_handle_forum_auth($db, $auth, $topic_id)`:
   - Loads topic + forum data (forum_id, forum_name, forum_password, parent_id)
   - Checks topic visibility via `content.visibility` service
   - Requires `u_download` AND `f_download` for the specific forum
   - Handles forum password protection (`login_forum_box()`)
   - 403 `SORRY_AUTH_VIEW_ATTACH` if no permission
   
   Source: functions_download.php:667–717

2. Checks post visibility: loads post's `forum_id`, `poster_id`, `post_visibility`
   - Uses `$phpbb_content_visibility->is_visible('post', ...)` — 404 if soft-deleted

**PM attachments** (file.php:240–244):
1. `phpbb_download_handle_pm_auth($db, $auth, $user_id, $msg_id)`:
   - Requires `u_pm_download` permission
   - Checks user is either recipient or author of the PM (query `PRIVMSGS_TO_TABLE`)
   - Has event `core.modify_pm_attach_download_auth` for extensions
   
   Source: functions_download.php:719–783

### Step 7: Extension Check (file.php:249–253)
```php
if (!extension_allowed($post_row['forum_id'], $attachment['extension'], $extensions))
    → 403 'EXTENSION_DISABLED_AFTER_POSTING'
```
`extension_allowed()` (functions_content.php:1478–1488) checks against cached allowed extensions for the specific forum. Returns `false` if extension was disabled after the file was uploaded.

### Step 8: Determine Display Category (file.php:256–263)
```php
$download_mode = (int) $extensions[$attachment['extension']]['download_mode'];
$display_cat = $extensions[$attachment['extension']]['display_cat'];
```
If user has disabled image viewing (`!$user->optionget('viewimg')`), forces `ATTACHMENT_CATEGORY_NONE`.

Extension event `core.download_file_send_to_browser_before` fires here with all relevant vars.

### Step 9: Thumbnail Handling (file.php:289–291)
```php
if ($thumbnail) {
    $attachment['physical_filename'] = 'thumb_' . $attachment['physical_filename'];
}
```
Thumbnails are served by prepending `thumb_` to the physical filename. Same auth checks, same code path, just different file.

### Step 10: Download Count Increment (file.php:292–295)
```php
else if ($display_cat == ATTACHMENT_CATEGORY_NONE
         && !$attachment['is_orphan']
         && !phpbb_http_byte_range($attachment['filesize']))
{
    phpbb_increment_downloads($db, $attachment['attach_id']);
}
```
Download count is incremented ONLY when:
- Display category is `NONE` (i.e., non-image/non-inline files)
- Attachment is NOT an orphan
- NOT a range request (resume) — prevents inflating count on partial downloads

`phpbb_increment_downloads()` (functions_download.php:645–663):
```php
$sql = 'UPDATE ' . ATTACHMENTS_TABLE . '
    SET download_count = download_count + 1
    WHERE ' . $db->sql_in_set('attach_id', $ids);
```

**Important**: Images displayed inline (`ATTACHMENT_CATEGORY_IMAGE`) get their download count incremented separately in `functions_content.php:1356` when the post is **viewed**, not when the image is served.

### Step 11: IE Workaround for Images (file.php:297–302)
Old IE (<=7) gets images wrapped in an HTML page via `wrap_img_in_html()` instead of direct serving. Only for `ATTACHMENT_CATEGORY_IMAGE` with `mode=view`.

### Step 12: Serve the File (file.php:305–318)
```php
if ($download_mode == PHYSICAL_LINK) {
    redirect($phpbb_root_path . $config['upload_path'] . '/' . $attachment['physical_filename']);
} else {
    send_file_to_browser($attachment, $config['upload_path'], $display_cat);
}
file_gc();
```
`PHYSICAL_LINK` (constant=2): redirects to the actual file path (deprecated/discouraged).
`INLINE_LINK` (constant=1, default): streams via `send_file_to_browser()`.

---

## 3. `send_file_to_browser()` — File Streaming Function

Source: `functions_download.php:123–298`

### 3.1 File Existence Check (Line 134)
```php
$filename = $phpbb_root_path . $upload_dir . '/' . $attachment['physical_filename'];
if (!@file_exists($filename))
    → 404 'ERROR_NO_ATTACHMENT'
```

### 3.2 MIME Type Security (Lines 140–143)
```php
// Force application/octetstream for ALL non-image files — security precaution
if ($category != ATTACHMENT_CATEGORY_IMAGE || strpos($attachment['mimetype'], 'image') !== 0) {
    $attachment['mimetype'] = (strpos(strtolower($user->browser), 'msie') !== false
        || strpos(strtolower($user->browser), 'opera') !== false)
        ? 'application/octetstream' : 'application/octet-stream';
}
```
**Critical security measure**: Only images retain their true MIME type. Everything else is forced to `application/octet-stream` to prevent browser execution of uploaded HTML/JS/SVG files.

### 3.3 Event: `core.send_file_to_browser_before` (Line 158)
Allows extensions to modify attachment data, upload dir, category, filename, or size before serving.

### 3.4 Filesize Reconciliation (Lines 184–191)
If actual file size differs from DB record, updates the DB (but NOT for thumbnails):
```php
if ($size > 0 && $size != $attachment['filesize']
    && strpos($attachment['physical_filename'], 'thumb_') === false) {
    $sql = 'UPDATE ' . ATTACHMENTS_TABLE . ' SET filesize = ' . (int) $size
        . ' WHERE attach_id = ' . (int) $attachment['attach_id'];
}
```

### 3.5 HTTP Headers (Lines 200–232)

**Cache-Control**:
```php
header('Cache-Control: private');
```
Always `private` — never cached by shared proxies.

**Content-Type**:
```php
header('Content-Type: ' . $attachment['mimetype']);
```

**X-Content-Type-Options** (IE 8+):
```php
header('X-Content-Type-Options: nosniff');
```

**Content-Disposition logic**:
| Condition | Disposition | Reason |
|-----------|-------------|--------|
| IE ≤7 (any file) | `attachment` | Security — IE tries to sniff content |
| Modern browser + image MIME | `inline` | Display in browser |
| Modern browser + non-image | `attachment` | Force download |

```php
// Modern browsers (Line 221-229):
header('Content-Disposition: '
    . ((strpos($attachment['mimetype'], 'image') === 0) ? 'inline' : 'attachment')
    . '; ' . header_filename(html_entity_decode($attachment['real_filename'], ENT_COMPAT)));
```

**X-Download-Options** (IE 8+, non-images):
```php
header('X-Download-Options: noopen');
```
Prevents IE "Open" button which could execute content in site context.

### 3.6 DB Close Before Streaming (Line 234)
```php
file_gc(false);  // false = don't exit, just close DB + cache
```

### 3.7 Conditional Caching — `set_modified_headers()` (Line 236)
```php
if (!set_modified_headers($attachment['filetime'], $user->browser))
```

`set_modified_headers()` (functions_download.php:441–470):
- Reads `If-Modified-Since` header
- If cached copy is still valid → sends **304 Not Modified** + cache headers + returns `true`
- Otherwise sends `Last-Modified` header + returns `false` (proceed to send file)

**Cache headers sent**:
```php
header('Cache-Control: private');
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');  // +1 year
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $stamp) . ' GMT');
```

No ETag support — only Last-Modified/If-Modified-Since.

### 3.8 Accelerated Sending — X-Accel-Redirect / X-Sendfile (Lines 242–255)

Supports offloading file transfer to the web server if constants are defined:

```php
if (defined('PHPBB_ENABLE_X_ACCEL_REDIRECT') && PHPBB_ENABLE_X_ACCEL_REDIRECT) {
    // nginx X-Accel-Redirect
    header('X-Accel-Redirect: ' . $user->page['root_script_path'] . $upload_dir . '/' . $attachment['physical_filename']);
    exit;
}
else if (defined('PHPBB_ENABLE_X_SENDFILE') && PHPBB_ENABLE_X_SENDFILE && !phpbb_http_byte_range($size)) {
    // Lighttpd X-Sendfile (needs absolute path, no range support)
    header('X-Sendfile: ' . __DIR__ . "/../$upload_dir/{$attachment['physical_filename']}");
    exit;
}
```

### 3.9 PHP Streaming with Range Support (Lines 257–289)

When no accelerated send is available:

```php
header("Content-Length: $size");
@set_time_limit(0);  // No time limit for large files

$fp = @fopen($filename, 'rb');
```

**Range Request Support** (RFC 2616 Section 14.35):
```php
if ($range = phpbb_http_byte_range($size)) {
    fseek($fp, $range['byte_pos_start']);
    send_status_line(206, 'Partial Content');
    header('Content-Range: bytes ' . $range['byte_pos_start'] . '-' . $range['byte_pos_end'] . '/' . $range['bytes_total']);
    header('Content-Length: ' . $range['bytes_requested']);
    // Read in 8KB chunks up to range end
}
```

Fallback: `@readfile($filename)` if `fopen()` fails.

**Chunk size**: 8192 bytes (8 KB).

---

## 4. `send_avatar_to_browser()` — Avatar Serving

Source: `functions_download.php:21–93`

### Key Differences from Attachment Serving:
| Aspect | Avatars | Attachments |
|--------|---------|-------------|
| Auth | None (public) | Session + ACL checks |
| MIME | Real image MIME from `getimagesize()` | Forced octet-stream for non-images |
| Cache | `Cache-Control: public` | `Cache-Control: private` |
| Expires | +1 year (31536000s) | +1 year (after 304 check) |
| Range requests | No | Yes |
| Download count | No | Yes (non-image files only) |
| X-Accel-Redirect | No | Yes (if configured) |
| Content-Disposition | `inline` (modern) / `attachment` (IE≤7) | Varies by MIME type |

### Avatar File Path Construction (Lines 26–40):
```php
$prefix = $config['avatar_salt'] . '_';
$image_dir = $config['avatar_path'];
// Path traversal prevention:
$image_dir = str_replace(array('../', '..\\', './', '.\\'), '', $image_dir);
if ($image_dir && ($image_dir[0] == '/' || $image_dir[0] == '\\')) {
    $image_dir = '';
}
$file_path = $phpbb_root_path . $image_dir . '/' . $prefix . $file;
```

Final path pattern: `{phpbb_root}/{avatar_path}/{avatar_salt}_{[g]userid}.{ext}`

### Avatar Streaming (Lines 42–87):
- Uses `getimagesize()` to determine real Content-Type
- Sets `Content-Length`
- Uses `readfile()` first, falls back to `fread()` in 8KB chunks
- 404 if file doesn't exist or headers already sent

---

## 5. Secure Downloads / Hotlink Protection

Source: `functions_download.php:325–438`, called from file.php:177

### `download_allowed()` Function

**When disabled** (`$config['secure_downloads'] = false`): Always returns `true` — no restrictions.

**When enabled**:
1. Reads `Referer` HTTP header
2. If no referer: allowed/denied based on `$config['secure_allow_empty_referer']`
3. Parses referer URL to extract hostname
4. Resolves hostname to IP(s) via `gethostbynamel()`
5. **Own server always allowed**: matches against `$config['server_name']`
6. Checks against `SITELIST_TABLE` in DB:
   - Allow/deny list based on `$config['secure_allow_deny']` mode
   - Supports wildcards in IP and hostname matching
   - Supports exclusion rules (`ip_exclude`)

**No token-based protection** — purely referer-based.

---

## 6. HTTP Range Requests

Source: `functions_download.php:504–639`

### `phpbb_http_byte_range($filesize)`:
- Reads `Range` HTTP header
- Validates `bytes=` prefix
- Parses range string (e.g., `0-499`, `500-999`)
- **Only supports contiguous ranges** — no multipart
- Returns array: `byte_pos_start`, `byte_pos_end`, `bytes_requested`, `bytes_total`
- Returns `false` for invalid or non-existent ranges

### Response for Range Requests:
```
HTTP/1.1 206 Partial Content
Content-Range: bytes {start}-{end}/{total}
Content-Length: {requested_bytes}
```

---

## 7. Error Handling Summary

| Condition | HTTP Status | Error Key |
|-----------|-------------|-----------|
| Java user agent / applet | Silent `exit` | — |
| Avatar: no dot in filename | 403 Forbidden | — |
| Avatar: disallowed extension | 403 Forbidden | — |
| Avatar: zero filename | 403 Forbidden | — |
| Avatar: file not found | 404 Not Found | — |
| Attachments disabled globally | 404 | `ATTACHMENT_FUNCTIONALITY_DISABLED` |
| No attach_id | 404 | `NO_ATTACHMENT_SELECTED` |
| Attachment row not found in DB | 404 | `ERROR_NO_ATTACHMENT` |
| Hotlink protection denied | 403 | `LINKAGE_FORBIDDEN` |
| Post/topic not visible (soft deleted) | 404 | `ERROR_NO_ATTACHMENT` |
| No download permission (forum) | 403 | `SORRY_AUTH_VIEW_ATTACH` |
| No download permission (PM) | 403 | `SORRY_AUTH_VIEW_ATTACH` / `ERROR_NO_ATTACHMENT` |
| Extension disabled after posting | 403 | `EXTENSION_DISABLED_AFTER_POSTING` |
| Physical file missing from disk | 404 | `ERROR_NO_ATTACHMENT` |
| File unreadable / headers sent | 500 | `UNABLE_TO_DELIVER_FILE` |
| Physical link mode, dir missing | 500 | `PHYSICAL_DOWNLOAD_NOT_POSSIBLE` |

---

## 8. Rate Limiting

**No rate limiting exists** anywhere in the download pipeline. No per-user throttle, no bandwidth limiting, no concurrent download cap. The only throttle-like feature is `@set_time_limit(0)` ensuring PHP doesn't timeout on large files.

---

## 9. Thumbnail Serving

Thumbnails use the **exact same code path** as regular attachments:
- URL: `download/file.php?id={attach_id}&t=1`
- `$thumbnail = $request->variable('t', false)` — boolean flag
- Physical file modification: `'thumb_' . $attachment['physical_filename']`
- Same auth checks apply
- Download count is **NOT incremented** for thumbnails (the thumbnail branch runs before download count logic)
- Same MIME type handling, same headers

---

## 10. URL Construction from Content Display

Source: `functions_content.php:1300–1390`

When posts are rendered, attachment URLs are built based on display category:

```php
// All attachments get a base download link:
$download_link = append_sid("{$phpbb_root_path}web/download/file.php", 'id=' . $attachment['attach_id']);

switch ($display_cat) {
    case ATTACHMENT_CATEGORY_IMAGE:
        // Inline image: download/file.php?id=X (for <img src>)
        $inline_link = append_sid("...download/file.php", 'id=' . $attachment['attach_id']);
        $download_link .= '&amp;mode=view';  // Click-through adds mode=view
        $update_count_ary[] = $attachment['attach_id'];  // Count on page view
        break;

    case ATTACHMENT_CATEGORY_THUMB:
        // Thumbnail src: download/file.php?id=X&t=1
        $thumbnail_link = append_sid("...download/file.php", 'id=' . $attachment['attach_id'] . '&amp;t=1');
        $download_link .= '&amp;mode=view';
        $update_count_ary[] = $attachment['attach_id'];  // Count on page view
        break;

    default:  // ATTACHMENT_CATEGORY_NONE
        // Regular download: download/file.php?id=X
        $l_downloaded_viewed = 'DOWNLOAD_COUNTS';  // Shows "downloaded N times"
        break;
}
```

---

## 11. Constants Reference

Source: `constants.php:155–170`

```php
define('INLINE_LINK', 1);        // Default: PHP streams the file
define('PHYSICAL_LINK', 2);      // Redirect to physical file (deprecated)

define('ATTACHMENT_CATEGORY_NONE', 0);   // Regular file download
define('ATTACHMENT_CATEGORY_IMAGE', 1);  // Inline image display
define('ATTACHMENT_CATEGORY_THUMB', 4);  // Thumbnail (runtime only, not in DB)
```

---

## 12. `header_filename()` Utility

Source: `functions_download.php:302–321`

Generates browser-compatible Content-Disposition filename:
```php
function header_filename($file) {
    if (strpos($user_agent, 'MSIE') !== false || strpos($user_agent, 'Konqueror') !== false) {
        return "filename=" . rawurlencode($file);  // Legacy encoding
    }
    return "filename*=UTF-8''" . rawurlencode($file);  // RFC 5987
}
```

---

## 13. Key Observations for Storage Service Design

1. **No abstraction layer**: File serving is tightly coupled to local filesystem (`fopen`, `readfile`, `@file_exists`)
2. **X-Accel-Redirect/X-Sendfile support exists** but requires compile-time constants — useful pattern for future storage abstraction
3. **Avatar serving is completely separate** from attachment serving — different auth model, different caching, different bootstrap
4. **No streaming abstraction**: PSR-7 StreamInterface is not used; raw PHP file I/O throughout
5. **Security model is sound**: MIME forcing to octet-stream, nosniff headers, download-only disposition for non-images
6. **Hotlink protection is referer-based only** — trivially bypassable but standard for phpBB
7. **Download counting is split**: images counted on page view (functions_content.php), files counted on download (file.php)
8. **Range request support** exists but only for PHP-streamed files (not X-Sendfile/X-Accel)
9. **8KB chunk size** for streaming — standard PHP practice
10. **Config keys involved**: `upload_path`, `avatar_path`, `avatar_salt`, `allow_attachments`, `allow_pm_attach`, `secure_downloads`, `secure_allow_empty_referer`, `secure_allow_deny`, `img_display_inlined`, `img_link_width`, `img_link_height`, `force_server_vars`, `server_name`
