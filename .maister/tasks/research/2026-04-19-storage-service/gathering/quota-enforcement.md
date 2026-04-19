# Quota Enforcement and Limits — Findings

## Overview

The phpBB attachment/avatar system enforces limits at multiple layers: PHP ini limits, global phpBB config limits, per-extension-group limits, per-file dimension limits, and a global attachment quota. **No per-user or per-forum quotas exist.**

---

## 1. Global Attachment Quota (`attachment_quota`)

**Config value**: `attachment_quota` — total bytes allowed across ALL board attachments.  
**Default**: `52428800` (50 MB) — from `phpbb_dump.sql:1077`.

### Enforcement Location

**File**: `src/phpbb/forums/attachment/upload.php:277-291`

```php
protected function check_attach_quota()
{
    if ($this->config['attachment_quota'])
    {
        if (intval($this->config['upload_dir_size']) + $this->file->get('filesize') > $this->config['attachment_quota'])
        {
            $this->file_data['error'][] = $this->language->lang('ATTACH_QUOTA_REACHED');
            $this->file_data['post_attach'] = false;
            $this->file->remove();
            return false;
        }
    }
    return true;
}
```

### How Current Usage Is Calculated

`upload_dir_size` is a **dynamic config value** (stored in `phpbb_config` with `is_dynamic=1`).  
It is NOT recalculated on every upload. Instead, it is **incrementally updated**:

- **On post submit** (`src/phpbb/common/functions_posting.php:2300`):
  ```php
  $config->increment('upload_dir_size', $space_taken, false);
  ```
- **On PM attachment orphan adoption** (`src/phpbb/common/functions_privmsgs.php:1934`):
  ```php
  $config->increment('upload_dir_size', $space_taken, false);
  ```
- **On resync in ACP** (`src/phpbb/common/acp/acp_attachments.php:1338`):
  ```php
  $sql = 'SELECT COUNT(a.attach_id) AS num_files, SUM(' . $this->db->cast_expr_to_bigint('a.filesize') . ') AS upload_dir_size
      FROM ' . ATTACHMENTS_TABLE . " a
      WHERE a.is_orphan = 0 $limit";
  ```
- **On admin resync** (`src/phpbb/common/acp/acp_main.php:197`):
  ```php
  $config->set('upload_dir_size', (float) $db->sql_fetchfield('stat'), false);
  ```

**Key insight**: The quota check uses a potentially stale `upload_dir_size` value. It's only reconciled via admin resync. Race conditions possible under concurrent uploads.

### Error Message

Language key: `ATTACH_QUOTA_REACHED` → `"Sorry, the board attachment quota has been reached."` (`src/phpbb/language/en/posting.php:44`)

### ACP Configuration

**File**: `src/phpbb/common/acp/acp_attachments.php:178`
```php
'attachment_quota' => array('lang' => 'ATTACH_QUOTA', 'validate' => 'string', 'type' => 'custom', 'method' => 'max_filesize', 'explain' => true),
```

Value is stored in bytes but entered in KB/MB via dropdown (`src/phpbb/common/acp/acp_attachments.php:233`):
```php
if (in_array($config_name, array('attachment_quota', 'max_filesize', 'max_filesize_pm')))
{
    $size_var = $request->variable($config_name, '');
    $config_value = ($size_var == 'kb') ? round($config_value * 1024) : (($size_var == 'mb') ? round($config_value * 1048576) : $config_value);
}
```

---

## 2. Per-Upload File Size Limit (`max_filesize`)

**Config value**: `max_filesize` — maximum single file for forum posts.  
**Default**: `262144` (256 KB) — from `phpbb_dump.sql:1255`.

**PM config value**: `max_filesize_pm` — maximum single file for private messages.  
**Default**: `262144` (256 KB) — from `phpbb_dump.sql:1256`.

### Enforcement Location

**File**: `src/phpbb/forums/attachment/upload.php:140-152`

```php
if (!$this->auth->acl_get('a_') && !$this->auth->acl_get('m_', $forum_id))
{
    // Check Image Size, if it is an image
    if ($is_image)
    {
        $this->file->upload->set_allowed_dimensions(0, 0, $this->config['img_max_width'], $this->config['img_max_height']);
    }

    // Admins and mods are allowed to exceed the allowed filesize
    if (!empty($this->extensions[$this->file->get('extension')]['max_filesize']))
    {
        $allowed_filesize = $this->extensions[$this->file->get('extension')]['max_filesize'];
    }
    else
    {
        $allowed_filesize = ($is_message) ? $this->config['max_filesize_pm'] : $this->config['max_filesize'];
    }

    $this->file->upload->set_max_filesize($allowed_filesize);
}
```

**Key insight**: Admins (`a_` permission) and moderators (`m_` on forum) **bypass** both filesize and dimension checks entirely.

### Actual Size Check

**File**: `src/phpbb/forums/files/upload.php:259-264` (in `common_checks()`):
```php
if ($this->max_filesize && ($file->get('filesize') > $this->max_filesize || $file->get('filesize') == 0))
{
    $max_filesize = get_formatted_filesize($this->max_filesize, false);
    $file->error[] = $this->language->lang($this->error_prefix . 'WRONG_FILESIZE', $max_filesize['value'], $max_filesize['unit']);
}
```

Note: a filesize of 0 is also rejected (catches the case where PHP truncated the file).

---

## 3. Per-Extension-Group Limits (`extension_groups.max_filesize`)

### How It Works

Each extension group (e.g., "Images", "Archives") has its own `max_filesize` column in `phpbb_extension_groups` table.

**Schema** (`phpbb_dump.sql:1528`):
```sql
`max_filesize` int(20) unsigned NOT NULL DEFAULT 0,
```

**Value of 0 = use global `max_filesize`**. The ACP displays the global value when group's value is 0:

**File**: `src/phpbb/common/acp/acp_attachments.php:732-734`
```php
if ($ext_group_row['max_filesize'] == 0)
{
    $ext_group_row['max_filesize'] = (int) $config['max_filesize'];
}
```

When saving, if group's max_filesize equals global, it's stored as 0:

**File**: `src/phpbb/common/acp/acp_attachments.php:573`
```php
if ($max_filesize == $config['max_filesize'])
{
    $max_filesize = 0;
}
```

### Cache Layer

Extensions are cached via `obtain_attach_extensions()` (`src/phpbb/forums/cache/service.php:225-337`):

```php
$sql = 'SELECT e.extension, g.*
    FROM ' . EXTENSIONS_TABLE . ' e, ' . EXTENSION_GROUPS_TABLE . ' g
    WHERE e.group_id = g.group_id
        AND (g.allow_group = 1 OR g.allow_in_pm = 1)';
```

Each extension in cache has `max_filesize` from its group. The upload class picks it up:
```php
if (!empty($this->extensions[$this->file->get('extension')]['max_filesize']))
{
    $allowed_filesize = $this->extensions[$this->file->get('extension')]['max_filesize'];
}
```

### Forum-Specific Extensions

Extension groups can have `allowed_forums` (serialized array). If set, extensions in that group are only allowed in those forums. This is enforced in `obtain_attach_extensions()`:

```php
$allowed_forums = ($row['allowed_forums']) ? unserialize(trim($row['allowed_forums'])) : array();
if ($row['allow_group'])
{
    $extensions['_allowed_post'][$extension] = (!count($allowed_forums)) ? 0 : $allowed_forums;
}
```

And during filtering by forum_id:
```php
if (is_array($check))
{
    $allowed = (!in_array($forum_id, $check)) ? false : true;
}
```

**This is NOT a per-forum quota — it only controls WHICH extensions are allowed in which forums, not how much space.**

---

## 4. PHP Limits (`upload_max_filesize`, `post_max_size`)

### Current Docker Config

**File**: `docker/php/php.ini:2-3`
```ini
upload_max_filesize = 20M
post_max_size = 20M
```

### Enforcement — File Types Base Class

**File**: `src/phpbb/forums/files/types/base.php:34-51`

```php
public function check_upload_size($file)
{
    // PHP Upload filesize exceeded
    if ($file->get('filename') == 'none')
    {
        $max_filesize = $this->php_ini->getString('upload_max_filesize');
        // ... format error message
        $file->error[] = (empty($max_filesize))
            ? $this->language->lang($this->upload->error_prefix . 'PHP_SIZE_NA')
            : $this->language->lang($this->upload->error_prefix . 'PHP_SIZE_OVERRUN', $max_filesize, ...);
    }
    return $file;
}
```

When PHP rejects a file (exceeds `upload_max_filesize`), the filename becomes `'none'`. This is caught before phpBB's own checks.

### Enforcement — Upload Error Codes

**File**: `src/phpbb/forums/files/upload.php:210-248` (`assign_internal_error()`):

- `UPLOAD_ERR_INI_SIZE` → reports PHP's `upload_max_filesize`
- `UPLOAD_ERR_FORM_SIZE` → reports phpBB's max_filesize
- `UPLOAD_ERR_PARTIAL` → partial upload error
- `UPLOAD_ERR_NO_FILE` → no file uploaded

### Plupload Chunk Size Calculation

**File**: `src/phpbb/forums/plupload/plupload.php:293-310`

```php
public function get_chunk_size()
{
    $max = 0;
    $limits = [
        $this->php_ini->getBytes('memory_limit'),
        $this->php_ini->getBytes('upload_max_filesize'),
        $this->php_ini->getBytes('post_max_size'),
    ];
    foreach ($limits as $limit_type)
    {
        if ($limit_type > 0)
        {
            $max = ($max !== 0) ? min($limit_type, $max) : $limit_type;
        }
    }
    return floor($max / 2);
}
```

Plupload splits files into chunks that are half the most restrictive PHP limit. This allows uploading files larger than `upload_max_filesize` via chunking.

---

## 5. Avatar Limits

### Config Values (from `phpbb_dump.sql`)

| Config Key | Default Value | Description |
|---|---|---|
| `avatar_filesize` | `6144` (6 KB) | Maximum avatar file size in bytes |
| `avatar_max_width` | `90` | Maximum width in pixels |
| `avatar_max_height` | `90` | Maximum height in pixels |
| `avatar_min_width` | `20` | Minimum width in pixels |
| `avatar_min_height` | `20` | Minimum height in pixels |

### Enforcement Location

**File**: `src/phpbb/forums/avatar/driver/upload.php:102-113`

```php
$upload = $this->files_factory->get('upload')
    ->set_error_prefix('AVATAR_')
    ->set_allowed_extensions($this->allowed_extensions)
    ->set_max_filesize($this->config['avatar_filesize'])
    ->set_allowed_dimensions(
        $this->config['avatar_min_width'],
        $this->config['avatar_min_height'],
        $this->config['avatar_max_width'],
        $this->config['avatar_max_height'])
    ->set_disallowed_content((isset($this->config['mime_triggers']) ? explode('|', $this->config['mime_triggers']) : false));
```

### Avatar Allowed Extensions

**File**: `src/phpbb/forums/avatar/driver/driver.php:68-73`

```php
protected $allowed_extensions = array(
    'gif',
    'jpg',
    'jpeg',
    'png',
);
```

Hardcoded array — NOT configurable via ACP. No webp support in the default list.

### Avatar Dimensions Check

Dimensions are checked via the same `valid_dimensions()` method in `files/upload.php:299-315`:

```php
public function valid_dimensions($file)
{
    if (!$this->max_width && !$this->max_height && !$this->min_width && !$this->min_height)
    {
        return true;
    }
    if (($file->get('width') > $this->max_width && $this->max_width) ||
        ($file->get('height') > $this->max_height && $this->max_height) ||
        ($file->get('width') < $this->min_width && $this->min_width) ||
        ($file->get('height') < $this->min_height && $this->min_height))
    {
        return false;
    }
    return true;
}
```

### Avatar Quota

**There is NO avatar quota** — no limit on total avatar storage space. Each user can have one avatar, so the implicit limit is `avatar_filesize * number_of_users`.

---

## 6. Image Dimension Checks (Attachment Images)

### Config Values (from `phpbb_dump.sql`)

| Config Key | Default Value | Description |
|---|---|---|
| `img_max_width` | `0` (unlimited) | Max image attachment width |
| `img_max_height` | `0` (unlimited) | Max image attachment height |
| `img_link_width` | `0` (unlimited) | Width at which images become links |
| `img_link_height` | `0` (unlimited) | Height at which images become links |

### Enforcement

Dimension checks for attachments are set in `src/phpbb/forums/attachment/upload.php:137-139`:
```php
if ($is_image)
{
    $this->file->upload->set_allowed_dimensions(0, 0, $this->config['img_max_width'], $this->config['img_max_height']);
}
```

**Only for non-admin/non-mod users** (the entire block is inside `if (!$this->auth->acl_get('a_') && !$this->auth->acl_get('m_', $forum_id))`).

Actual dimensions are read from the file after `move_file()` in `filespec.php:508-516`:
```php
$this->image_info = $this->imagesize->getImageSize($this->destination_file, $this->mimetype);
if ($this->image_info !== false)
{
    $this->width = $this->image_info['width'];
    $this->height = $this->image_info['height'];
}
```

### Plupload Client-Side Resize

If `img_max_width` and `img_max_height` are set, plupload resizes images client-side before upload (`src/phpbb/forums/plupload/plupload.php:262-273`):

```php
public function generate_resize_string()
{
    $resize = '';
    if ($this->config['img_max_height'] > 0 && $this->config['img_max_width'] > 0)
    {
        $resize = sprintf(
            'resize: {width: %d, height: %d, quality: %d, preserve_headers: %s},',
            (int) $this->config['img_max_width'],
            (int) $this->config['img_max_height'],
            (int) $this->config['img_quality'],
            $preserve_headers_value
        );
    }
    return $resize;
}
```

---

## 7. Multiple File Limits (Max Attachments Per Post/PM)

### Config Values

| Config Key | Default Value | Description |
|---|---|---|
| `max_attachments` | `3` | Max attachments per forum post |
| `max_attachments_pm` | `1` | Max attachments per private message |

### Enforcement

**File**: `src/phpbb/common/message_parser.php:1582-1588`

```php
$cfg['max_attachments'] = ($is_message) ? $config['max_attachments_pm'] : $config['max_attachments'];
// ...
if ($num_attachments < $cfg['max_attachments'] || $auth->acl_get('a_') || $auth->acl_get('m_', $forum_id))
{
    // proceed with upload
}
```

Error when exceeded (`message_parser.php:1664`):
```php
$error[] = $user->lang('TOO_MANY_ATTACHMENTS', (int) $cfg['max_attachments']);
```

**Admins and moderators bypass this limit** (`$auth->acl_get('a_') || $auth->acl_get('m_', $forum_id)`).

### Client-Side Enforcement

In `web/posting.php:2098-2099`:
```php
$max_files = ($auth->acl_get('a_') || $auth->acl_get('m_', $forum_id)) ? 0 : (int) $config['max_attachments'];
$plupload->configure($cache, $template, $s_action, $forum_id, $max_files);
```

`0` means unlimited for admins/mods. Plupload enforces this client-side too via `MAX_ATTACHMENTS` template var.

---

## 8. Extension Validation

### Allowed Extensions

Set during upload init (`src/phpbb/forums/attachment/upload.php:245-246`):
```php
$this->extensions = $this->cache->obtain_attach_extensions((($is_message) ? false : (int) $forum_id));
$this->files_upload->set_allowed_extensions(array_keys($this->extensions['_allowed_']));
```

### Validation Check

**File**: `src/phpbb/forums/files/upload.php:280-283`
```php
public function valid_extension($file)
{
    return (in_array($file->get('extension'), $this->allowed_extensions)) ? true : false;
}
```

Error message: `$this->error_prefix . 'DISALLOWED_EXTENSION'`

### Content Sniffing (Anti-MIME Abuse)

**File**: `src/phpbb/forums/files/upload.php:34`  
Default disallowed content strings:
```php
protected $disallowed_content = array('body', 'head', 'html', 'img', 'plaintext', 'a href', 'pre', 'script', 'table', 'title');
```

Configurable via `mime_triggers` config. Checked via `$file->check_content($this->disallowed_content)` in `filespec.php:367`.

---

## 9. PM Attachment Limits

PM attachments use **separate config values**:

| Config | Post Value | PM Value |
|---|---|---|
| `max_filesize` / `max_filesize_pm` | `262144` | `262144` |
| `max_attachments` / `max_attachments_pm` | `3` | `1` |
| `allow_attachments` / `allow_pm_attach` | bool | bool |

PM uploads use `$is_message = true` which selects PM-specific values.

PM extensions are filtered by `allow_in_pm` flag on extension group (not by forum):
```php
if ($row['allow_in_pm'])
{
    $extensions['_allowed_pm'][$extension] = 0;
}
```

**The global `attachment_quota` applies to ALL attachments (post + PM combined).** There is no separate PM quota.

---

## 10. Disk Space Check

**File**: `src/phpbb/forums/attachment/upload.php:298-319`

```php
protected function check_disk_space()
{
    if (function_exists('disk_free_space'))
    {
        $free_space = @disk_free_space($this->phpbb_root_path);
        if ($free_space <= $this->file->get('filesize'))
        {
            if ($this->auth->acl_get('a_'))
            {
                $this->file_data['error'][] = $this->language->lang('ATTACH_DISK_FULL');
            }
            else
            {
                $this->file_data['error'][] = $this->language->lang('ATTACH_QUOTA_REACHED');
            }
            $this->file_data['post_attach'] = false;
            $this->file->remove();
            return false;
        }
    }
    return true;
}
```

**Key insight**: Non-admins see the generic "quota reached" message even when the actual problem is disk full — security by obscurity.

---

## 11. Post-Upload Validation Flow

The upload flow in `attachment/upload.php:upload()` follows this sequence:

1. **Init**: Set allowed extensions, disallowed content (`init_files_upload()`)
2. **Upload**: Handle file via `files_upload->handle_upload()` — this triggers:
   - filespec `set_upload_ary()` — reads size, name, extension
   - `check_upload_size()` — PHP limit check
   - `common_checks()` — max_filesize, extension, filename, content checks
3. **Dimension check**: `set_allowed_dimensions()` for images (non-admin only)
4. **Move file**: `$this->file->move_file()` — moves to upload_path
   - After move: re-reads filesize, extracts dimensions from image
   - Image type validation (extension matches actual image type)
5. **Image validation**: `check_image()` — ensures files in image category are actual images
6. **Fill file data**: `fill_file_data()` — filesize, mimetype, extension, etc.
7. **Event**: `core.modify_uploaded_file` — allows extensions to modify
8. **Quota check**: `check_attach_quota()` — global quota
9. **Disk check**: `check_disk_space()`
10. **Thumbnail**: `create_thumbnail()` if applicable

**The file is saved to disk BEFORE the quota check.** If quota is exceeded, the file is then removed. This means the file temporarily exists on disk past the quota limit.

---

## 12. GAPS — What Does NOT Exist

### Per-User Quota
- **Does NOT exist.** No config key, no code, no DB schema for per-user attachment quotas.
- Any user can upload up to the global limits regardless of how much they've already uploaded.
- The only per-user limit is `max_attachments` (per-post, not total).

### Per-Forum Quota
- **Does NOT exist.** Extension groups can restrict WHICH extensions go to which forums, but there's no byte-level quota per forum.
- `allowed_forums` on `extension_groups` is a whitelist/blacklist for extension availability, not a storage budget.

### Per-User Avatar Quota
- **Implicit only** — each user can have exactly one avatar, limited to `avatar_filesize`. No tracking of total avatar storage.

### Granular PM Quota
- No separate PM attachment quota (shares global `attachment_quota`).
- `max_filesize_pm` only limits individual file size.

---

## 13. Summary of All Limit Config Keys

| Config Key | Default | Scope | Enforced In |
|---|---|---|---|
| `attachment_quota` | 52428800 (50MB) | Global board | `attachment/upload.php:279` |
| `max_filesize` | 262144 (256KB) | Per file (posts) | `attachment/upload.php:149` |
| `max_filesize_pm` | 262144 (256KB) | Per file (PMs) | `attachment/upload.php:149` |
| `max_attachments` | 3 | Per post | `message_parser.php:1582` |
| `max_attachments_pm` | 1 | Per PM | `message_parser.php:1582` |
| `img_max_width` | 0 (unlimited) | Per image attachment | `attachment/upload.php:138` |
| `img_max_height` | 0 (unlimited) | Per image attachment | `attachment/upload.php:138` |
| `avatar_filesize` | 6144 (6KB) | Per avatar upload | `avatar/driver/upload.php:106` |
| `avatar_max_width` | 90 | Per avatar | `avatar/driver/upload.php:108` |
| `avatar_max_height` | 90 | Per avatar | `avatar/driver/upload.php:109` |
| `avatar_min_width` | 20 | Per avatar | `avatar/driver/upload.php:108` |
| `avatar_min_height` | 20 | Per avatar | `avatar/driver/upload.php:109` |
| `extension_groups.max_filesize` | 0 (=global) | Per extension group | `attachment/upload.php:144-146` |

### PHP Limits (external)

| PHP Directive | Docker Default | Checked In |
|---|---|---|
| `upload_max_filesize` | 20M | `files/types/base.php:39`, `plupload.php:299` |
| `post_max_size` | 20M | `plupload.php:300` |
| `memory_limit` | (system) | `plupload.php:298` |

---

## 14. Admin/Moderator Bypasses

Admins (`a_` permission) and moderators (`m_` on forum) bypass:
- Per-file size limit (`max_filesize` / `max_filesize_pm`)
- Per-extension-group size limit
- Image dimension limits (`img_max_width`, `img_max_height`)
- Max attachments per post (`max_attachments` / `max_attachments_pm`)

They do NOT bypass:
- Global attachment quota (`attachment_quota`)
- Disk space check
- Extension allowlist
- PHP limits (`upload_max_filesize`, `post_max_size`)
