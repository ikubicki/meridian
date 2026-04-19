# Attachment Lifecycle — Full Findings

## Source Files Investigated

| File | Lines | Role |
|------|-------|------|
| `src/phpbb/forums/attachment/manager.php` | 107 | Facade — delegates to upload/delete/resync |
| `src/phpbb/forums/attachment/upload.php` | 339 | Upload handling, validation, thumbnail |
| `src/phpbb/forums/attachment/delete.php` | 480 | Deletion from DB + filesystem |
| `src/phpbb/forums/attachment/resync.php` | 150 | Recounts attachment flags on posts/topics/messages |
| `src/phpbb/forums/files/filespec.php` | ~530 | File object — naming, moving, validation |
| `src/phpbb/forums/files/upload.php` | ~380 | Low-level upload engine |
| `src/phpbb/common/message_parser.php` | ~1800 | Where orphan DB record is INSERTed |
| `src/phpbb/common/functions_posting.php` | 3009 | `submit_post()` — orphan adoption |
| `src/phpbb/common/functions_download.php` | ~780 | Serving files to browser |
| `web/download/file.php` | ~320 | Download entry point |
| `src/phpbb/forums/cron/task/core/tidy_plupload.php` | ~130 | Cron — cleans plupload temp files |

## DB Schema: `phpbb_attachments`

Source: [phpbb_dump.sql](phpbb_dump.sql#L808-L832)

```sql
CREATE TABLE `phpbb_attachments` (
  `attach_id`          int unsigned NOT NULL AUTO_INCREMENT,
  `post_msg_id`        int unsigned NOT NULL DEFAULT 0,
  `topic_id`           int unsigned NOT NULL DEFAULT 0,
  `in_message`         tinyint(1) unsigned NOT NULL DEFAULT 0,
  `poster_id`          int unsigned NOT NULL DEFAULT 0,
  `is_orphan`          tinyint(1) unsigned NOT NULL DEFAULT 1,
  `physical_filename`  varchar(255) NOT NULL DEFAULT '',
  `real_filename`      varchar(255) NOT NULL DEFAULT '',
  `download_count`     mediumint unsigned NOT NULL DEFAULT 0,
  `attach_comment`     text NOT NULL,
  `extension`          varchar(100) NOT NULL DEFAULT '',
  `mimetype`           varchar(100) NOT NULL DEFAULT '',
  `filesize`           int unsigned NOT NULL DEFAULT 0,
  `filetime`           int unsigned NOT NULL DEFAULT 0,
  `thumbnail`          tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`attach_id`),
  KEY `filetime` (`filetime`),
  KEY `post_msg_id` (`post_msg_id`),
  KEY `topic_id` (`topic_id`),
  KEY `poster_id` (`poster_id`),
  KEY `is_orphan` (`is_orphan`)
);
```

Key observations:
- `is_orphan` defaults to **1** — every attachment starts as an orphan
- `post_msg_id` is overloaded — stores either `post_id` or `msg_id`, disambiguated by `in_message`
- `physical_filename` is the on-disk name (randomized), `real_filename` is original user filename
- Indices on `is_orphan`, `poster_id`, `post_msg_id`, `topic_id`, `filetime`

---

## 1. Upload Flow (Step-by-Step)

### Entry Point

The upload is triggered during post composition (not post submit). The `parse_attachments()` method in `message_parser` calls:

```php
// message_parser.php:1597
$attachment_manager = $phpbb_container->get('attachment.manager');
$filedata = $attachment_manager->upload($form_name, $forum_id, false, '', $is_message);
```

### Step-by-step Flow

#### Step 1: Manager delegates to upload class
[manager.php](src/phpbb/forums/attachment/manager.php#L96-L104): `manager::upload()` → `upload::upload()`

#### Step 2: Initialize allowed extensions
[upload.php](src/phpbb/forums/attachment/upload.php#L239-L250): `init_files_upload()`:
- Loads disallowed content (MIME triggers) from config
- Obtains allowed extensions per forum from cache: `$cache->obtain_attach_extensions($forum_id)`
- Sets allowed extensions on the upload handler

#### Step 3: Handle the file upload
[upload.php](src/phpbb/forums/attachment/upload.php#L131-L136):
```php
$this->file = ($local)
    ? $this->files_upload->handle_upload('files.types.local', $local_storage, $local_filedata)
    : $this->files_upload->handle_upload('files.types.form', $form_name);
```
Returns a `filespec` object.

#### Step 4: Size/dimension validation (non-admin/mod only)
[upload.php](src/phpbb/forums/attachment/upload.php#L142-L157):
- Images: check `img_max_width` / `img_max_height`
- File size: per-extension max, or global `max_filesize` / `max_filesize_pm`
- Admins and mods bypass these limits

#### Step 5: Generate unique physical filename
[upload.php](src/phpbb/forums/attachment/upload.php#L160):
```php
$this->file->clean_filename('unique', $this->user->data['user_id'] . '_');
```

This calls `filespec::clean_filename('unique', ...)` → [filespec.php](src/phpbb/forums/files/filespec.php#L230-L231):
```php
case 'unique':
    $this->realname = $prefix . md5(unique_id());
```

**Physical filename format: `{user_id}_{md5(unique_id())}`** — no file extension on disk.

#### Step 6: Move file to upload directory
[upload.php](src/phpbb/forums/attachment/upload.php#L164):
```php
$this->file->move_file($this->config['upload_path'], false, !$is_image);
```
- `filespec::move_file()` ([filespec.php](src/phpbb/forums/files/filespec.php#L404-L530)):
  - Destination = `$phpbb_root_path . $config['upload_path'] . '/' . $realname`
  - Uses `copy()` or `move_uploaded_file()` depending on `open_basedir`
  - Removes temporary file after move
  - Applies chmod (read+write)
  - For images: validates image type, dimensions, reads width/height

#### Step 7: Image validation
[upload.php](src/phpbb/forums/attachment/upload.php#L260-L274): `check_image()`:
- If category is image but file isn't actually an image → remove + error
- Guards against IE extension-spoofing exploit

#### Step 8: Fill file data
[upload.php](src/phpbb/forums/attachment/upload.php#L330-L336): `fill_file_data()`:
```php
$this->file_data['filesize'] = $this->file->get('filesize');
$this->file_data['mimetype'] = $this->file->get('mimetype');
$this->file_data['extension'] = $this->file->get('extension');
$this->file_data['physical_filename'] = $this->file->get('realname');
$this->file_data['real_filename'] = $this->file->get('uploadname');
$this->file_data['filetime'] = time();
```

#### Step 9: Event — `core.modify_uploaded_file`
[upload.php](src/phpbb/forums/attachment/upload.php#L178-L193): Fires after file data populated, before quota checks.

#### Step 10: Quota and disk space checks
- [upload.php](src/phpbb/forums/attachment/upload.php#L286-L300): `check_attach_quota()` — checks `upload_dir_size + filesize > attachment_quota`
- [upload.php](src/phpbb/forums/attachment/upload.php#L308-L324): `check_disk_space()` — `disk_free_space()` check

If either fails → file removed, error returned.

#### Step 11: Thumbnail creation
[upload.php](src/phpbb/forums/attachment/upload.php#L217-L229): `create_thumbnail()`:
- Only for images when `config['img_create_thumbnail']` is enabled
- Thumbnail stored as `thumb_{realname}` in same directory
- Uses `create_thumbnail()` global function

#### Step 12: Return file data array
Returns `$this->file_data` with keys: `post_attach`, `error[]`, `filesize`, `mimetype`, `extension`, `physical_filename`, `real_filename`, `filetime`, `thumbnail`

### Step 13: DB INSERT as orphan
Back in `message_parser.php` ([line 1607-1622](src/phpbb/common/message_parser.php#L1607-L1622)):
```php
$sql_ary = array(
    'physical_filename' => $filedata['physical_filename'],
    'attach_comment'    => $this->filename_data['filecomment'],
    'real_filename'     => $filedata['real_filename'],
    'extension'         => $filedata['extension'],
    'mimetype'          => $filedata['mimetype'],
    'filesize'          => $filedata['filesize'],
    'filetime'          => $filedata['filetime'],
    'thumbnail'         => $filedata['thumbnail'],
    'is_orphan'         => 1,           // ← ALWAYS orphan
    'in_message'        => ($is_message) ? 1 : 0,
    'poster_id'         => $user->data['user_id'],
);
// Event: core.modify_attachment_sql_ary_on_submit
$db->sql_query('INSERT INTO ' . ATTACHMENTS_TABLE . ' ' . $db->sql_build_array('INSERT', $sql_ary));
```

**Critical**: The DB INSERT happens in `message_parser`, NOT in the upload class. The upload class only handles filesystem operations. The `is_orphan=1` is hardcoded here.

---

## 2. Orphan Pattern

### Creation
Every uploaded attachment starts with `is_orphan = 1`. The file exists on disk and has a DB row, but `post_msg_id = 0` and `topic_id = 0`.

### Orphan access control
During preview/editing, orphan attachments are served with session ID validation:
```php
// functions_posting.php:896
$download_link = append_sid("...download/file.php", 'mode=view&id=' . $attach_row['attach_id'],
    true, ($attach_row['is_orphan']) ? $user->session_id : false);
```

### Adoption (orphan→owned)
Happens in `submit_post()` — see Section 3 below.

### Cleanup of stale orphans
**There is NO dedicated cron task for orphan attachment cleanup in the attachments table.**

The only cleanup cron is `tidy_plupload` ([tidy_plupload.php](src/phpbb/forums/cron/task/core/tidy_plupload.php#L82-L108)) which:
- Cleans files in `{upload_path}/plupload/` directory
- Deletes files older than 86400 seconds (24 hours)
- Identifies plupload files by `plupload_salt` prefix
- Runs every 24 hours (`plupload_last_gc`)
- This is for **plupload chunk temp files**, NOT for orphan DB records

**Gap**: Orphan DB rows with `is_orphan=1` that were never adopted (user abandoned post) have no automatic cleanup mechanism visible in the scanned code. The physical files persist, and the DB rows persist indefinitely.

---

## 3. Adoption — `submit_post()` Detail

Source: [functions_posting.php](src/phpbb/common/functions_posting.php#L2222-L2310)

### Step-by-step adoption flow:

#### Step 1: Collect orphan IDs
```php
$orphan_rows = array();
foreach ($data_ary['attachment_data'] as $pos => $attach_row) {
    $orphan_rows[(int) $attach_row['attach_id']] = array();
}
```

#### Step 2: Verify orphans exist and belong to user
```php
$sql = 'SELECT attach_id, filesize, physical_filename
    FROM ' . ATTACHMENTS_TABLE . '
    WHERE ' . $db->sql_in_set('attach_id', array_keys($orphan_rows)) . '
        AND is_orphan = 1
        AND poster_id = ' . $user->data['user_id'];
```
**Security**: Only adopts orphans owned by current user (`poster_id` check).

#### Step 3: Process each attachment
For **already-adopted** attachments (`is_orphan = 0`):
```php
$sql = 'UPDATE ' . ATTACHMENTS_TABLE . "
    SET attach_comment = '" . $db->sql_escape($attach_row['attach_comment']) . "'
    WHERE attach_id = " . (int) $attach_row['attach_id'] . '
        AND is_orphan = 0';
```
Only comment is updated.

For **orphaned** attachments (`is_orphan = 1`):
```php
$attach_sql = array(
    'post_msg_id'    => $data_ary['post_id'],
    'topic_id'       => $data_ary['topic_id'],
    'is_orphan'      => 0,                    // ← adopted!
    'poster_id'      => $poster_id,
    'attach_comment' => $attach_row['attach_comment'],
);
$sql = 'UPDATE ' . ATTACHMENTS_TABLE . ' SET ' . $db->sql_build_array('UPDATE', $attach_sql) . '
    WHERE attach_id = ' . $attach_row['attach_id'] . '
        AND is_orphan = 1
        AND poster_id = ' . $user->data['user_id'];
```

#### Step 4: Verify physical file exists
```php
if (!@file_exists($phpbb_root_path . $config['upload_path'] . '/' .
    utf8_basename($orphan_rows[$attach_row['attach_id']]['physical_filename'])))
{
    continue;  // skip silently if file missing
}
```

#### Step 5: Update global counters
```php
if ($space_taken && $files_added) {
    $config->increment('upload_dir_size', $space_taken, false);
    $config->increment('num_files', $files_added, false);
}
```
**Note**: Counters only increment on adoption, not on initial upload. Orphans don't count toward storage stats.

### Transaction handling
**No explicit transaction wrapping.** Each SQL statement executes independently. If the process crashes between adoption updates, some attachments may be adopted while others remain orphans. The verification (`is_orphan = 1 AND poster_id = user`) prevents double-adoption but doesn't guarantee atomicity.

---

## 4. Serving/Download Flow

### Entry point: `web/download/file.php`

[file.php](web/download/file.php#L155-L320)

#### Step 1: Load attachment record
```php
$sql = 'SELECT attach_id, post_msg_id, topic_id, in_message, poster_id, is_orphan,
    physical_filename, real_filename, extension, mimetype, filesize, filetime
    FROM ' . ATTACHMENTS_TABLE . "
    WHERE attach_id = $attach_id";
```

#### Step 2: Authorization
- **Orphans**: Only viewable by admin with `a_attach` or the original poster. Session ID verified.
- **Post attachments**: `phpbb_download_handle_forum_auth()` — checks topic access + `u_download` permission + content visibility
- **PM attachments**: `phpbb_download_handle_pm_auth()` — checks PM recipient/sender

#### Step 3: Extension check
After auth, verifies the extension is still allowed (may have been disabled after posting).

#### Step 4: Determine display method
```
display_cat = ATTACHMENT_CATEGORY_IMAGE | ATTACHMENT_CATEGORY_THUMB | ATTACHMENT_CATEGORY_NONE
download_mode = PHYSICAL_LINK | INLINE  (from extension config)
```

#### Step 5: Increment download count (non-image, non-orphan, non-thumbnail, non-byte-range)
[functions_download.php](src/phpbb/common/functions_download.php#L645-L656):
```php
function phpbb_increment_downloads($db, $ids) {
    $sql = 'UPDATE ' . ATTACHMENTS_TABLE . '
        SET download_count = download_count + 1
        WHERE ' . $db->sql_in_set('attach_id', $ids);
}
```

#### Step 6: Send file
[functions_download.php](src/phpbb/common/functions_download.php#L123-L180): `send_file_to_browser()`:
- Constructs path: `$phpbb_root_path . $upload_dir . '/' . $attachment['physical_filename']`
- Forces `application/octet-stream` for non-images (security)
- Flushes output buffer
- Sends appropriate headers
- **Event**: `core.send_file_to_browser_before` fires before sending

---

## 5. Deletion Flow

### Entry: `manager::delete($mode, $ids, $resync)`
[manager.php](src/phpbb/forums/attachment/manager.php#L55-L62): Delegates to `delete::delete()`

### Step-by-step deletion:

#### Step 1: Validate and normalize IDs
[delete.php](src/phpbb/forums/attachment/delete.php#L212-L229): `set_attachment_ids()` — casts to int, deduplicates

#### Step 2: Set SQL constraints by mode
[delete.php](src/phpbb/forums/attachment/delete.php#L237-L260): `set_sql_constraints()`:
| Mode | `sql_id` column | Extra WHERE |
|------|----------------|-------------|
| `post` | `post_msg_id` | `AND in_message = 0` |
| `message` | `post_msg_id` | `AND in_message = 1` |
| `topic` | `topic_id` | — |
| `user` | `poster_id` | — |
| `attach` | `attach_id` | — |

#### Step 3: Event — `core.delete_attachments_collect_data_before`

#### Step 4: Collect attachment info
[delete.php](src/phpbb/forums/attachment/delete.php#L268-L296): `collect_attachment_info()`:
- Queries `ATTACHMENTS_TABLE` for: `post_msg_id, topic_id, in_message, physical_filename, thumbnail, filesize, is_orphan`
- Builds arrays: `$post_ids`, `$topic_ids`, `$message_ids`, `$physical[]`
- Skips resync data for orphans (`is_orphan` check)

#### Step 5: Event — `core.delete_attachments_before`

#### Step 6: Delete from DB
[delete.php](src/phpbb/forums/attachment/delete.php#L350-L360):
```php
$sql = 'DELETE FROM ' . ATTACHMENTS_TABLE . '
    WHERE ' . $this->db->sql_in_set($this->sql_id, $this->ids);
$this->num_deleted = $this->db->sql_affectedrows();
```

#### Step 7: Event — `core.delete_attachments_from_database_after`

#### Step 8: Remove from filesystem
[delete.php](src/phpbb/forums/attachment/delete.php#L369-L388): `remove_from_filesystem()`:
```php
foreach ($this->physical as $file_ary) {
    if ($this->unlink_attachment($file_ary['filename'], 'file', true) && !$file_ary['is_orphan']) {
        $space_removed += $file_ary['filesize'];
        $files_removed++;
    }
    if ($file_ary['thumbnail']) {
        $this->unlink_attachment($file_ary['filename'], 'thumbnail', true);
    }
}
```
- Only non-orphan files count toward storage decrement
- Thumbnails are deleted separately via `thumb_` prefix

#### Step 9: Safe unlink with reference counting
[delete.php](src/phpbb/forums/attachment/delete.php#L447-L480): `unlink_attachment()`:
```php
$sql = 'SELECT COUNT(attach_id) AS num_entries
    FROM ' . ATTACHMENTS_TABLE . "
    WHERE physical_filename = '" . $this->db->sql_escape(utf8_basename($filename)) . "'";
// Do not remove file if at least one additional entry with the same name exist.
if (($entry_removed && $num_entries > 0) || (!$entry_removed && $num_entries > 1)) {
    return false;
}
```
**Important**: Physical files may be shared across multiple DB records (topic copying). The file is only deleted when the last reference is removed.

#### Step 10: Event — `core.delete_attachments_from_filesystem_after`

#### Step 11: Update global counters
```php
$this->config->increment('upload_dir_size', $space_removed * (-1), false);
$this->config->increment('num_files', $files_removed * (-1), false);
```

#### Step 12: Resync
If `$resync = true`, calls:
```php
$this->resync->resync('post', $this->post_ids);
$this->resync->resync('message', $this->message_ids);
$this->resync->resync('topic', $this->topic_ids);
```

---

## 6. Resync

Source: [resync.php](src/phpbb/forums/attachment/resync.php)

### Purpose
Updates the `{type}_attachment` flag (e.g., `post_attachment`, `topic_attachment`, `message_attachment`) on parent records when attachments are deleted.

### Logic
[resync.php](src/phpbb/forums/attachment/resync.php#L92-L133):
1. Queries `ATTACHMENTS_TABLE` for which IDs still have non-orphan attachments
2. Computes `$ids = array_diff($ids, $remaining_ids)` — IDs that no longer have any attachments
3. Sets `{type}_attachment = 0` on those IDs in the parent table

### Type mapping
| Type | Attachment key | Parent table | Parent key |
|------|---------------|-------------|------------|
| `message` | `post_msg_id` (+ `in_message=1, is_orphan=0`) | `PRIVMSGS_TABLE` | `msg_id` |
| `post` | `post_msg_id` (+ `in_message=0, is_orphan=0`) | `POSTS_TABLE` | `post_id` |
| `topic` | `topic_id` (+ `is_orphan=0`) | `TOPICS_TABLE` | `topic_id` |

### When triggered
- After attachment deletion (if `$resync=true`)
- Can be called independently via `manager::resync()`

---

## 7. Class APIs

### `manager` — [attachment/manager.php](src/phpbb/forums/attachment/manager.php)

| Method | Signature | Description |
|--------|-----------|-------------|
| `__construct` | `(delete $delete, resync $resync, upload $upload)` | DI constructor |
| `delete` | `(string $mode, mixed $ids, bool $resync = true): int\|bool` | Delete by mode (post/message/topic/attach/user) |
| `unlink` | `(string $filename, string $mode = 'file', bool $entry_removed = false): bool` | Delete physical file only |
| `resync` | `(string $type, array $ids): void` | Resync attachment flags |
| `upload` | `(string $form_name, int $forum_id, bool $local = false, string $local_storage = '', bool $is_message = false, array $local_filedata = []): array` | Upload and return file data |

### `upload` — [attachment/upload.php](src/phpbb/forums/attachment/upload.php)

**Constructor dependencies (10)**:
```php
__construct(
    auth $auth,
    service $cache,              // phpbb\cache\service
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

| Method | Visibility | Description |
|--------|-----------|-------------|
| `upload(...)` | public | Main upload method, returns file data array |
| `create_thumbnail()` | protected | Creates thumbnail if config enabled |
| `init_files_upload()` | protected | Sets allowed extensions, disallowed content |
| `check_image()` | protected | Validates image files |
| `check_attach_quota()` | protected | Checks attachment storage quota |
| `check_disk_space()` | protected | Checks disk free space |
| `fill_file_data()` | protected | Populates file_data array from filespec |

### `delete` — [attachment/delete.php](src/phpbb/forums/attachment/delete.php)

**Constructor dependencies (6)**:
```php
__construct(
    config $config,
    driver_interface $db,
    dispatcher $dispatcher,
    filesystem $filesystem,
    resync $resync,
    string $phpbb_root_path
)
```

| Method | Visibility | Description |
|--------|-----------|-------------|
| `delete(string $mode, mixed $ids, bool $resync = true)` | public | Full deletion pipeline |
| `unlink_attachment(string $filename, string $mode = 'file', bool $entry_removed = false)` | public | Physical file removal with refcount safety |
| `set_attachment_ids($ids)` | protected | Normalize/validate IDs |
| `set_sql_constraints($mode)` | private | Set SQL WHERE based on delete mode |
| `collect_attachment_info($resync)` | protected | Gather physical filenames + parent IDs |
| `delete_attachments_from_db($mode, $ids, $resync)` | protected | SQL DELETE |
| `remove_from_filesystem($mode, $ids, $resync)` | protected | Physical file + thumbnail removal |

### `resync` — [attachment/resync.php](src/phpbb/forums/attachment/resync.php)

**Constructor dependencies (1)**: `driver_interface $db`

| Method | Visibility | Description |
|--------|-----------|-------------|
| `resync(string $type, array $ids)` | public | Recalculate attachment flags |
| `set_type_constraints(string $type)` | protected | Set table/column mapping |

---

## 8. Physical Filename Generation

Source: [filespec.php](src/phpbb/forums/files/filespec.php#L205-L250)

```php
public function clean_filename($mode = 'unique', $prefix = '', $user_id = '')
```

Modes:
| Mode | Format | Example |
|------|--------|---------|
| `unique` | `{prefix}{md5(unique_id())}` | `42_a1b2c3d4e5f6...` (no extension) |
| `real` | `{prefix}{sanitized_lowercase_name}.{ext}` | `42_my_document.pdf` |
| `unique_ext` | `{prefix}{md5(unique_id())}.{ext}` | `42_a1b2c3d4e5f6....jpg` |
| `avatar` | `{prefix}{user_id}.{ext}` | `42.jpg` |

**For attachments**: always `unique` mode with prefix `{user_id}_`. Result: **no file extension on disk**. Format: `{user_id}_{32-char-md5-hex}`.

`unique_id()` is a phpBB function that generates a unique string using `mt_rand()` + `microtime()`.

---

## 9. Events Fired During Attachment Operations

### Upload events
| Event | Location | When |
|-------|----------|------|
| `core.modify_uploaded_file` | upload.php:178 | After file data filled, before quota check |
| `core.modify_attachment_sql_ary_on_submit` | message_parser.php:1615 | Before DB INSERT of orphan |
| `core.modify_attachment_data_on_submit` | message_parser.php:1639 | After DB INSERT, before merging into attachment_data |

### Deletion events
| Event | Location | When |
|-------|----------|------|
| `core.delete_attachments_collect_data_before` | delete.php:119 | Before querying attachment info |
| `core.delete_attachments_before` | delete.php:327 | Before SQL DELETE |
| `core.delete_attachments_from_database_after` | delete.php:166 | After SQL DELETE, before filesystem removal |
| `core.delete_attachments_from_filesystem_after` | delete.php:406 | After filesystem removal, before counter update |

### Download events
| Event | Location | When |
|-------|----------|------|
| `core.download_file_send_to_browser_before` | file.php:268 | Before sending file to browser |
| `core.send_file_to_browser_before` | functions_download.php:168 | Inside send function, before headers |

### Template events
| Event | Location | When |
|-------|----------|------|
| `core.modify_default_attachments_template_vars` | functions_posting.php:869 | When rendering attachment UI |

---

## 10. Error Handling

### Upload errors
Errors are accumulated in `$this->file_data['error']` array (strings). Sources:
- `NO_UPLOAD_FORM_FOUND` — no valid form upload detected
- `ATTACHED_IMAGE_NOT_IMAGE` — file claims image extension but isn't an image
- `ATTACH_QUOTA_REACHED` — storage quota exceeded
- `ATTACH_DISK_FULL` — disk_free_space check (admin-only message)
- `GENERAL_UPLOAD_ERROR` — move_file failure
- `IMAGE_FILETYPE_INVALID` / `IMAGE_FILETYPE_MISMATCH` — image type validation
- `TOO_MANY_ATTACHMENTS` — per-post attachment limit reached
- `ATTACH_COMMENT_NO_EMOJIS` — emoji in comment (4-byte UTF-8)

On error: file is removed from disk (`$this->file->remove()`), `post_attach` set to `false`.

### Deletion errors
- `unlink_attachment()` returns `false` silently if file doesn't exist or is shared
- Catches `filesystem_exception` on unlink — swallows and returns false
- No explicit error reporting to caller, just returns `0` for `num_deleted`

### Download errors
- 404 + `ERROR_NO_ATTACHMENT` if not found or no permission
- 403 + `LINKAGE_FORBIDDEN` if hotlinking blocked
- 403 + `EXTENSION_DISABLED_AFTER_POSTING` if extension was disabled after upload

---

## 11. Transaction Handling

**There is NO explicit transaction wrapping anywhere in the attachment lifecycle.**

- **Upload**: File written to disk → DB INSERT happens separately in message_parser. If DB INSERT fails after file is written, orphan file remains on disk with no DB record.
- **Adoption**: Each attachment UPDATE is independent. Partial adoption is possible if process terminates mid-loop.
- **Deletion**: DB DELETE → filesystem unlink are separate operations. DB deleted but file not unlinked leaves orphan files. File unlinked but DB not deleted is prevented by doing DB first.
- **Counters**: `config->increment()` for `upload_dir_size` and `num_files` is also non-transactional — can drift.

---

## 12. Key Architecture Observations

1. **Two-phase commit pattern**: Upload creates file+orphan, then adoption links to post. This supports preview/draft but creates cleanup obligations.

2. **No transaction safety**: The entire attachment lifecycle relies on "best effort" with no rollback capability.

3. **Shared physical files**: Topic copying can create multiple DB records pointing to the same physical file. `unlink_attachment()` has reference counting to prevent premature deletion.

4. **Counter drift risk**: `upload_dir_size` and `num_files` are maintained via increments/decrements, not recalculated. Long-term drift is possible.

5. **Orphan leak**: No cron cleans orphan DB records. Only plupload temp chunks are cleaned. Users who start uploading but never submit leave permanent orphans.

6. **Flat file storage**: All files go to a single `config['upload_path']` directory with no subdirectories/sharding. Physical filenames are `{user_id}_{md5}` — globally unique but all in one folder.

7. **Security**: Non-image files are always served as `application/octet-stream`. Orphans require session_id for access. Adoption verifies `poster_id` ownership.
