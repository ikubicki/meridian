# Attachment System Integration Patterns

> Reference analysis for designing plugin architecture in `phpbb\threads`.
> Every integration point documented here = a hook/event needed in the new system.

---

## 1. Database Schema

### `phpbb_attachments` table

**Source**: `phpbb_dump.sql:808-831`

```sql
CREATE TABLE `phpbb_attachments` (
  `attach_id`          int unsigned NOT NULL AUTO_INCREMENT,
  `post_msg_id`        int unsigned NOT NULL DEFAULT 0,     -- FK → posts.post_id OR privmsgs.msg_id
  `topic_id`           int unsigned NOT NULL DEFAULT 0,     -- FK → topics.topic_id (denormalized)
  `in_message`         tinyint(1) unsigned NOT NULL DEFAULT 0,  -- 0=forum post, 1=PM
  `poster_id`          int unsigned NOT NULL DEFAULT 0,     -- FK → users.user_id
  `is_orphan`          tinyint(1) unsigned NOT NULL DEFAULT 1,  -- 1=uploaded but not yet linked to post
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

### Denormalized flags on other tables

| Table | Column | Purpose |
|-------|--------|---------|
| `phpbb_posts` | `post_attachment` tinyint(1) | Fast check if post has attachments (avoids JOIN) |
| `phpbb_topics` | `topic_attachment` tinyint(1) | Fast check if any post in topic has attachments |

**Plugin implication**: The new system needs:
- Its own `attachments` table (or equivalent)
- A way to register denormalized columns on `posts`/`topics` tables (or use metadata)
- Resync mechanism to fix stale flags

---

## 2. Attachment Lifecycle / Upload Flow

### 2.1 Two-phase upload (Orphan pattern)

Attachments are uploaded **BEFORE** the post is saved. They exist as "orphans" (`is_orphan=1`) until the post is submitted.

**Phase 1: Upload (immediate, on "Add file" click)**

1. User clicks "Add file" in posting form → triggers `add_file` POST
2. `web/posting.php:39` — `$refresh = (isset($_POST['add_file']) || ...)`
3. `web/posting.php:1042-1044` — Token check, then:
   ```php
   $message_parser->parse_attachments('fileupload', $mode, $forum_id, $submit, $preview, $refresh);
   ```
4. `message_parser.php:1542-1650` — `parse_attachments()` method:
   - Checks `max_attachments` config limit
   - Calls `$attachment_manager->upload($form_name, $forum_id)`
   - On success, INSERTs into `phpbb_attachments` with `is_orphan=1`
   - Shifts `[attachment=N]` BBCode indices up by 1 in message text
   - Prepends new attachment to `$this->attachment_data` array

5. `upload.php:120-196` — Actual file handling:
   - Init allowed extensions from cache (`obtain_attach_extensions`)
   - Handle upload via `files.types.form` or `files.types.local`
   - Validate image dimensions (if image category)
   - Validate filesize per extension or per config (`max_filesize`, `max_filesize_pm`)
   - Clean filename to unique pattern: `{user_id}_{random}`
   - Move file to `$config['upload_path']`
   - Create thumbnail if image + config enabled
   - **Event `core.modify_uploaded_file`** — modify filedata before final return
   - Check attachment quota (`attachment_quota` config)
   - Check disk space

**Phase 2: Adoption (on post submit)**

1. `web/posting.php:1490-1510` — Post data assembled, includes `attachment_data`:
   ```php
   'attachment_data' => $message_parser->attachment_data,
   ```
2. `functions_posting.php:2223-2300` — Inside `submit_post()`:
   - Iterates `$data_ary['attachment_data']`
   - For orphan attachments (`is_orphan=1`):
     - Verifies file exists on disk
     - UPDATEs attachment: `post_msg_id={post_id}`, `topic_id={topic_id}`, `is_orphan=0`, `poster_id={user_id}`
   - For existing attachments (`is_orphan=0`):
     - Only updates `attach_comment`
   - Increments `upload_dir_size` and `num_files` config counters

### 2.2 Plugin hook points needed for upload:

| When | What happens | Hook needed |
|------|-------------|-------------|
| Before upload validation | Extension/size checks | `pre_upload_validate` |
| After file stored on disk | File data populated | `post_upload` (equiv of `core.modify_uploaded_file`) |
| On orphan INSERT | DB record created | `attachment_created` |
| On post submit | Orphans adopted | `post_attachments_saved` |
| On attachment comment update | Comment changed | `attachment_updated` |

---

## 3. Integration Points with Posting

### 3.1 Posting form setup (`web/posting.php`)

**Permission check** — `posting.php:1921`:
```php
$form_enctype = (@ini_get('file_uploads') == '0' || ... ||
    !$config['allow_attachments'] || !$auth->acl_get('u_attach') ||
    !$auth->acl_get('f_attach', $forum_id)) ? '' : ' enctype="multipart/form-data"';
```

**Required permissions**: `u_attach` (user can attach) + `f_attach` (forum allows attachments) + `allow_attachments` config.

**Plupload configuration** — `posting.php:2095-2099`:
```php
$max_files = ($auth->acl_get('a_') || $auth->acl_get('m_', $forum_id)) ? 0 : (int) $config['max_attachments'];
$plupload->configure($cache, $template, $s_action, $forum_id, $max_files);
```

**Template vars** — `posting.php:1995`:
```php
'S_ATTACH_DATA' => json_encode($message_parser->attachment_data),
```

### 3.2 Edit mode: Loading existing attachments (`web/posting.php:677-688`)

```php
if ($post_data['post_attachment'] && !$submit && !$refresh && !$preview && $mode == 'edit')
{
    $sql = 'SELECT attach_id, is_orphan, attach_comment, real_filename, filesize
        FROM ' . ATTACHMENTS_TABLE . "
        WHERE post_msg_id = $post_id
            AND in_message = 0
            AND is_orphan = 0
        ORDER BY attach_id DESC";
    $message_parser->attachment_data = array_merge(
        $message_parser->attachment_data, $db->sql_fetchrowset($result)
    );
}
```

### 3.3 Submitted attachment data validation (`message_parser.php:1834-1930`)

`get_submitted_attachment_data()` — Called on EVERY postback:
- Reads `attachment_data[]` from POST
- Separates orphans from adopted attachments
- Validates each attach_id belongs to the poster (security check)
- Rebuilds `$this->attachment_data` from verified DB records only

**Security pattern**: Never trust client-supplied attachment data. Always re-verify ownership from DB.

### 3.4 Post save — `submit_post()` attachment handling

**In `functions_posting.php`**:

Posts table gets `post_attachment` flag:
- `Line 1824`: `'post_attachment' => (!empty($data_ary['attachment_data'])) ? 1 : 0` (for post/reply)
- `Line 1897`: Same for edit mode

Topics table gets `topic_attachment` flag:
- `Line 1931`: `'topic_attachment' => (!empty($data_ary['attachment_data'])) ? 1 : 0` (new topic)
- `Line 1984`: For replies: `(!empty($data_ary['attachment_data']) || ... $data_ary['topic_attachment']) ? ', topic_attachment = 1' : ''`
- `Line 2033`: For edits: `'topic_attachment' => (!empty($data_ary['attachment_data'])) ? 1 : (isset($data_ary['topic_attachment']) ? $data_ary['topic_attachment'] : 0)`

### 3.5 Draft save deletes orphans (`web/posting.php:816-818`)

```php
$attachment_manager = $phpbb_container->get('attachment.manager');
$attachment_manager->delete('attach', array_column($message_parser->attachment_data, 'attach_id'));
```

When saving a draft, any uploaded attachments (orphans) are deleted since drafts don't support attachments.

---

## 4. Delete Cascade

### 4.1 `delete.php` — Manager pattern

**Source**: `src/phpbb/forums/attachment/delete.php`

Delete modes:
| Mode | SQL column | Description |
|------|-----------|-------------|
| `post` | `post_msg_id` (+ `in_message=0`) | Delete all attachments for post(s) |
| `message` | `post_msg_id` (+ `in_message=1`) | Delete all attachments for PM(s) |
| `topic` | `topic_id` | Delete all attachments for topic(s) |
| `attach` | `attach_id` | Delete specific attachment(s) |
| `user` | `poster_id` | Delete all attachments by user(s) |

**Flow**:
1. Collect attachment info (physical filenames, post/topic IDs) — `collect_attachment_info()`
2. Delete DB records — `delete_attachments_from_db()`
3. Delete files from filesystem — `remove_from_filesystem()`
4. If resync enabled: Update `post_attachment` and `topic_attachment` flags via `resync` class
5. Decrement global counters: `upload_dir_size`, `num_files`

### 4.2 Events fired during deletion

| Event | When | Available vars |
|-------|------|---------------|
| `core.delete_attachments_collect_data_before` | Before collecting data | mode, ids, resync, sql_id |
| `core.delete_attachments_before` | Before DB delete | mode, ids, resync, sql_id, post_ids, topic_ids, message_ids, physical |
| `core.delete_attachments_from_database_after` | After DB delete | mode, ids, resync, sql_id, post_ids, topic_ids, message_ids, physical, num_deleted |
| `core.delete_attachments_from_filesystem_after` | After filesystem delete | All above + space_removed, files_removed |

### 4.3 Resync class (`resync.php`)

Handles syncing `post_attachment`, `topic_attachment`, and PM attachment flags after deletions. Queries `phpbb_attachments` to check if any attachments remain, then updates the parent table flag.

**Plugin implication**: When posts/topics are deleted, the threads service must fire an event that the attachment plugin listens to, triggering cascade deletion.

---

## 5. Inline Attachments (BBCode)

### 5.1 BBCode format

```
[attachment=N]filename.jpg[/attachment]
```

Where `N` is the 0-based index in the `attachment_data` array (NOT the attach_id).

### 5.2 During message parsing (`message_parser.php`)

**BBCode definition** — `message_parser.php:148`:
```php
'attachment' => array('bbcode_id' => BBCODE_ID_ATTACH,
    'regexp' => array('#\[attachment=([0-9]+)\](.*?)\[/attachment\]#uis' => ...))
```

**Stored format** — `message_parser.php:477`:
```php
return '[attachment=' . $stx . ':' . $this->bbcode_uid . ']<!-- ia' . $stx . ' -->' . trim($in) . '<!-- ia' . $stx . ' -->[/attachment:' . $this->bbcode_uid . ']';
```

The `<!-- ia{N} -->` HTML comments are used later for replacement during display.

### 5.3 Index shifting

When a new attachment is added, all existing `[attachment=N]` indices are incremented:
```php
// message_parser.php:1645-1646 (on submit upload)
$this->message = preg_replace_callback('#\[attachment=([0-9]+)\](.*?)\[\/attachment\]#', function ($match) {
    return '[attachment='.($match[1] + 1).']' . $match[2] . '[/attachment]';
}, $this->message);
```

When an attachment is deleted, indices are decremented and the deleted one removed:
```php
// message_parser.php:1718-1719
return ($match[1] == $index) ? '' : (($match[1] > $index) ? '[attachment=' . ($match[1] - 1) . ']...' : $match[0]);
```

### 5.4 Display rendering (`functions_content.php:1161-1450`)

`parse_attachments()` function:
1. Loads full attachment data from DB if missing
2. For each attachment, determines display category:
   - `ATTACHMENT_CATEGORY_IMAGE` — inline image
   - `ATTACHMENT_CATEGORY_THUMB` — thumbnail with link
   - `ATTACHMENT_CATEGORY_NONE` — download link
3. Applies `attachment.html` template for each attachment
4. Replaces `<!-- ia{N} -->` markers in message with rendered HTML
5. Non-inline attachments are appended at the end of the post

**Event**: `core.parse_attachments_modify_template_data` — Modify template vars per attachment.

**Plugin implication**: The new system needs:
- A BBCode-like syntax for inline attachments (or a Markdown-compatible equivalent)
- A rendering pipeline hook where the attachment plugin can substitute placeholders with HTML

---

## 6. Display Integration (viewtopic.php)

### 6.1 Optimization: Topic-level attachment flag

`viewtopic.php:626-629`:
```php
if ($topic_data['topic_attachment'])
{
    $extensions = $cache->obtain_attach_extensions($forum_id);
}
```

Extensions are only loaded if the topic has ANY attachments (optimization).

### 6.2 Per-post attachment check

`viewtopic.php:1340`:
```php
if ($row['post_attachment'] && $config['allow_attachments'])
{
    $attach_list[] = $row['post_id'];
}
```

Posts with `post_attachment=1` are collected, then attachments fetched in SINGLE query.

### 6.3 Batch attachment fetch

`viewtopic.php:1600-1612`:
```php
$sql = 'SELECT *
    FROM ' . ATTACHMENTS_TABLE . '
    WHERE ' . $db->sql_in_set('post_msg_id', $attach_list) . '
        AND in_message = 0
    ORDER BY attach_id DESC, post_msg_id ASC';
```

Attachments grouped by `post_msg_id` for efficient per-post assignment.

### 6.4 Self-healing resync on display

`viewtopic.php:1616-1650` — If no attachments found but `post_attachment=1`, resets the flag:
```php
if (!count($attachments))
{
    $sql = 'UPDATE ' . POSTS_TABLE . ' SET post_attachment = 0 WHERE ...';
    // Also potentially resets topic_attachment
}
```

**Plugin implication**: The display pipeline needs a hook for plugins to inject their rendered content into posts. Attachment rendering is currently tightly integrated — the new system should provide:
- `post.before_render` event for fetching plugin data
- `post.render_content` event for content transformation (inline attachment replacement)
- `post.after_render` event for appending additional content blocks

---

## 7. Orphan Handling

### 7.1 Lifecycle

1. **Created as orphan**: `is_orphan=1` on INSERT during upload (`message_parser.php:1609-1621`)
2. **Adopted on submit**: `is_orphan=0` in `submit_post()` (`functions_posting.php:2278-2293`)
3. **Deleted on cancel/draft**: Via `attachment_manager->delete()` (`posting.php:818`)
4. **Admin cleanup**: ACP has orphan management (`acp_attachments.html:369`)
5. **Security**: Orphans can only be accessed with valid session ID in download URL

### 7.2 Orphan protection

- Upload always verifies `poster_id = $user->data['user_id']` before adoption
- Orphan downloads require session_id in URL: `posting.php:896`
  ```php
  $download_link = append_sid("...download/file.php", '...', true, ($attach_row['is_orphan']) ? $user->session_id : false);
  ```

**Plugin implication**: Any plugin handling uploads needs the same orphan pattern. The core should provide:
- An orphan registry (upload → temporary ownership → adoption or cleanup)
- A cron task for cleaning up stale orphans

---

## 8. Quota / Limits

### 8.1 Config-based limits

| Config key | Purpose |
|------------|---------|
| `allow_attachments` | Global on/off |
| `max_attachments` | Max attachments per post |
| `max_attachments_pm` | Max attachments per PM |
| `max_filesize` | Max file size (bytes) |
| `max_filesize_pm` | Max file size for PMs |
| `img_max_width` | Max image width |
| `img_max_height` | Max image height |
| `attachment_quota` | Total disk quota for all attachments |
| `upload_dir_size` | Current total size (tracked, not calculated) |
| `num_files` | Current total file count |
| `img_create_thumbnail` | Auto-create thumbnails |
| `img_min_thumb_filesize` | Min size to create thumbnail |
| `img_max_thumb_width` | Thumbnail max width |
| `check_attachment_content` | Check file content for exploits |
| `mime_triggers` | Disallowed content patterns |

### 8.2 Per-extension limits

Extensions table stores per-extension max filesize. Checked in `upload.php:159-168`:
```php
if (!empty($this->extensions[$this->file->get('extension')]['max_filesize']))
{
    $allowed_filesize = $this->extensions[$this->file->get('extension')]['max_filesize'];
}
```

### 8.3 Permission-based bypass

**Admins and mods** bypass filesize/dimension restrictions (`upload.php:152`):
```php
if (!$this->auth->acl_get('a_') && !$this->auth->acl_get('m_', $forum_id))
{
    // Apply size limits only for regular users
}
```

**Max attachments** bypass (`message_parser.php:1592`):
```php
if ($num_attachments < $cfg['max_attachments'] || $auth->acl_get('a_') || $auth->acl_get('m_', $forum_id))
```

---

## 9. Events / Extension Points Catalog

### Upload events
| Event | File | Purpose |
|-------|------|---------|
| `core.modify_uploaded_file` | `upload.php:184` | Modify filedata after upload |
| `core.modify_attachment_sql_ary_on_submit` | `message_parser.php:1613` | Modify DB insert on form submit |
| `core.modify_attachment_sql_ary_on_upload` | `message_parser.php:1758` | Modify DB insert on add-file/preview |
| `core.modify_attachment_data_on_submit` | `message_parser.php:1635` | Modify attachment_data array on submit |
| `core.modify_attachment_data_on_upload` | `message_parser.php:1780` | Modify attachment_data array on upload |

### Delete events
| Event | File | Purpose |
|-------|------|---------|
| `core.delete_attachments_collect_data_before` | `delete.php:112` | Before data collection |
| `core.delete_attachments_before` | `delete.php:322` | Before DB deletion |
| `core.delete_attachments_from_database_after` | `delete.php:146` | After DB deletion |
| `core.delete_attachments_from_filesystem_after` | `delete.php:394` | After filesystem deletion |

### Display events
| Event | File | Purpose |
|-------|------|---------|
| `core.parse_attachments_modify_template_data` | `functions_content.php:1395` | Modify per-attachment template |
| `core.modify_default_attachments_template_vars` | `functions_posting.php:872` | Modify attachment box defaults |
| `core.modify_inline_attachments_template_vars` | `functions_posting.php:917` | Modify inline attachment display |
| `core.thumbnail_create_before` | `functions_posting.php:696` | Custom thumbnail creation |

---

## 10. Architecture Summary for Plugin Design

### Current coupling points (must become hooks):

```
┌─────────────────────┐
│    web/posting.php   │ ← UI form setup, add_file/delete_file handling
│                      │    HOOKS: form_setup, file_action
├──────────────────────┤
│   message_parser     │ ← parse_attachments(), BBCode [attachment=N]
│                      │    HOOKS: parse_content, render_bbcode
├──────────────────────┤
│   submit_post()      │ ← Orphan adoption, post_attachment flag, topic_attachment flag
│                      │    HOOKS: pre_save, post_save, flag_update
├──────────────────────┤
│  viewtopic.php       │ ← Batch fetch, inline rendering, self-healing
│                      │    HOOKS: post_display_data, content_render
├──────────────────────┤
│  delete cascade      │ ← Post/topic delete → attachment delete → file delete → resync
│                      │    HOOKS: entity_deleted, cleanup
└──────────────────────┘
```

### Key data flows the plugin must intercept:

1. **Form rendering**: Plugin registers that posting form needs file upload capability
2. **File upload**: Plugin handles file storage, validation, DB record creation
3. **Orphan tracking**: Plugin manages temporary uploads before post save
4. **Post save**: Plugin receives `post_saved` event, adopts orphans, updates denormalized flags
5. **Content parsing**: Plugin registers BBCode handler or content transformer for `[attachment=N]`
6. **Post display**: Plugin receives batch of post IDs, fetches attachments, injects into render pipeline
7. **Post/topic deletion**: Plugin receives `post_deleted`/`topic_deleted`, cascades to attachment cleanup
8. **Resync**: Plugin can be triggered to fix stale denormalized flags

### Recommended plugin interface:

```php
interface PostingPluginInterface
{
    /** Register form components (called during posting form setup) */
    public function registerFormComponents(PostingForm $form): void;

    /** Handle file actions during posting (add/delete) */
    public function handleFileAction(string $action, PostingContext $ctx): void;

    /** Process data before post save */
    public function beforeSave(PostData $data): void;

    /** Process data after post save (adopt orphans, etc.) */
    public function afterSave(PostData $data, int $postId): void;

    /** Transform post content for display (inline replacements) */
    public function transformContent(string $content, array $postIds): string;

    /** Fetch plugin data for batch of posts (called once per page) */
    public function fetchDisplayData(array $postIds): array;

    /** Handle cascade deletion */
    public function onPostDeleted(array $postIds): void;
    public function onTopicDeleted(array $topicIds): void;
}
```
