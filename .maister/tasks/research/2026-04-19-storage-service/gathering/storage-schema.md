# Storage-Related Database Schema & Configuration

## Source: `phpbb_dump.sql`

---

## 1. Table: `phpbb_attachments`

**Purpose**: Stores metadata for all uploaded file attachments (post attachments and PM attachments).

### DDL

```sql
CREATE TABLE `phpbb_attachments` (
  `attach_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `post_msg_id` int(10) unsigned NOT NULL DEFAULT 0,
  `topic_id` int(10) unsigned NOT NULL DEFAULT 0,
  `in_message` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `poster_id` int(10) unsigned NOT NULL DEFAULT 0,
  `is_orphan` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `physical_filename` varchar(255) NOT NULL DEFAULT '',
  `real_filename` varchar(255) NOT NULL DEFAULT '',
  `download_count` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `attach_comment` text NOT NULL,
  `extension` varchar(100) NOT NULL DEFAULT '',
  `mimetype` varchar(100) NOT NULL DEFAULT '',
  `filesize` int(20) unsigned NOT NULL DEFAULT 0,
  `filetime` int(11) unsigned NOT NULL DEFAULT 0,
  `thumbnail` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`attach_id`),
  KEY `filetime` (`filetime`),
  KEY `post_msg_id` (`post_msg_id`),
  KEY `topic_id` (`topic_id`),
  KEY `poster_id` (`poster_id`),
  KEY `is_orphan` (`is_orphan`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

### Column Details

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `attach_id` | int(10) unsigned, PK, AUTO_INCREMENT | — | Unique attachment identifier |
| `post_msg_id` | int(10) unsigned | 0 | FK to `phpbb_posts.post_id` or `phpbb_privmsgs.msg_id` (polymorphic, depends on `in_message`) |
| `topic_id` | int(10) unsigned | 0 | FK to `phpbb_topics.topic_id` (0 for PM attachments) |
| `in_message` | tinyint(1) unsigned | 0 | 0 = post attachment, 1 = private message attachment |
| `poster_id` | int(10) unsigned | 0 | FK to `phpbb_users.user_id` — who uploaded |
| `is_orphan` | tinyint(1) unsigned | **1** | 1 = uploaded but not yet attached to a post (pending), 0 = attached. Default is orphan — set to 0 on post submit |
| `physical_filename` | varchar(255) | '' | Server-side filename on disk (hashed/salted, e.g., `2_a1b2c3d4e5f6.ext`) |
| `real_filename` | varchar(255) | '' | Original user-provided filename |
| `download_count` | mediumint(8) unsigned | 0 | Number of times the file has been downloaded |
| `attach_comment` | text | '' | User-provided description/comment for the attachment |
| `extension` | varchar(100) | '' | File extension (lowercase, e.g., `jpg`, `pdf`) |
| `mimetype` | varchar(100) | '' | MIME type (e.g., `image/jpeg`, `application/pdf`) |
| `filesize` | int(20) unsigned | 0 | File size in **bytes** |
| `filetime` | int(11) unsigned | 0 | Unix timestamp of upload time |
| `thumbnail` | tinyint(1) unsigned | 0 | 1 = thumbnail has been generated, 0 = no thumbnail |

### Indexes

| Index Name | Column(s) | Purpose |
|-----------|-----------|---------|
| PRIMARY | `attach_id` | Unique row lookup |
| `filetime` | `filetime` | Sort/filter by upload time |
| `post_msg_id` | `post_msg_id` | Look up attachments for a specific post/PM |
| `topic_id` | `topic_id` | Look up all attachments in a topic |
| `poster_id` | `poster_id` | Look up all attachments by a user |
| `is_orphan` | `is_orphan` | Orphan cleanup cron queries |

### Notes
- No data rows present in dump (empty table).
- `physical_filename` is generated server-side — never matches `real_filename`. Format: `{poster_id}_{hash}` (see `filespec.php`).
- `is_orphan` defaults to **1** — files start as orphans during upload, then get claimed when the post is submitted.
- Polymorphic FK: `post_msg_id` points to different tables based on `in_message` value.

---

## 2. Table: `phpbb_extension_groups`

**Purpose**: Defines groups of file extensions with shared settings (category, permissions, size limits).

### DDL

```sql
CREATE TABLE `phpbb_extension_groups` (
  `group_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `group_name` varchar(255) NOT NULL DEFAULT '',
  `cat_id` tinyint(2) NOT NULL DEFAULT 0,
  `allow_group` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `download_mode` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `upload_icon` varchar(255) NOT NULL DEFAULT '',
  `max_filesize` int(20) unsigned NOT NULL DEFAULT 0,
  `allowed_forums` text NOT NULL,
  `allow_in_pm` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`group_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

### Column Details

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `group_id` | mediumint(8) unsigned, PK, AUTO_INCREMENT | — | Unique group identifier |
| `group_name` | varchar(255) | '' | Language key or display name for the group |
| `cat_id` | tinyint(2) | 0 | Attachment category constant (see Constants section below). Determines display behavior (inline image vs download link) |
| `allow_group` | tinyint(1) unsigned | 0 | 1 = extensions in this group are allowed for upload, 0 = disabled |
| `download_mode` | tinyint(1) unsigned | 1 | Download mode (1 = inline, other values for physical download) |
| `upload_icon` | varchar(255) | '' | Path to icon displayed next to attachments of this type |
| `max_filesize` | int(20) unsigned | 0 | Max file size in bytes for this group (0 = use global `max_filesize`) |
| `allowed_forums` | text | '' | Comma-separated forum IDs where this group is allowed (empty = all forums) |
| `allow_in_pm` | tinyint(1) unsigned | 0 | 1 = allow in private messages, 0 = disallow |

### Sample Data

| group_id | group_name | cat_id | allow_group | download_mode | upload_icon | max_filesize | allowed_forums | allow_in_pm |
|----------|-----------|--------|-------------|---------------|-------------|-------------|----------------|-------------|
| 1 | IMAGES | 1 | 1 | 1 | '' | 0 | '' | 0 |
| 2 | ARCHIVES | 0 | 1 | 1 | '' | 0 | '' | 0 |
| 3 | PLAIN_TEXT | 0 | 0 | 1 | '' | 0 | '' | 0 |
| 4 | DOCUMENTS | 0 | 0 | 1 | '' | 0 | '' | 0 |
| 5 | DOWNLOADABLE_FILES | 0 | 0 | 1 | '' | 0 | '' | 0 |
| 6 | IMAGES | 1 | 1 | 1 | '' | 0 | '' | 0 |
| 7 | ARCHIVES | 0 | 1 | 1 | '' | 0 | '' | 0 |
| 8 | PLAIN_TEXT | 0 | 0 | 1 | '' | 0 | '' | 0 |
| 9 | DOCUMENTS | 0 | 0 | 1 | '' | 0 | '' | 0 |
| 10 | DOWNLOADABLE_FILES | 0 | 0 | 1 | '' | 0 | '' | 0 |

**Observation**: Groups 1–5 and 6–10 are duplicates. This appears to be a double-insert from the installation. Only groups 1–5 are the canonical groups. Groups 6–10 have the same names and settings.

### Key observations:
- Only IMAGES (`cat_id=1`) and ARCHIVES are **allowed** (`allow_group=1`).
- PLAIN_TEXT, DOCUMENTS, DOWNLOADABLE_FILES are **disabled** by default (`allow_group=0`).
- No per-group `max_filesize` overrides are set (all 0 = use global).
- No forum restrictions (`allowed_forums` is empty for all).
- PM attachments are disabled for all groups (`allow_in_pm=0`).

---

## 3. Table: `phpbb_extensions`

**Purpose**: Maps individual file extensions to extension groups.

### DDL

```sql
CREATE TABLE `phpbb_extensions` (
  `extension_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `group_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `extension` varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY (`extension_id`)
) ENGINE=InnoDB AUTO_INCREMENT=109 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

### Column Details

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `extension_id` | mediumint(8) unsigned, PK, AUTO_INCREMENT | — | Unique extension record ID |
| `group_id` | mediumint(8) unsigned | 0 | FK to `phpbb_extension_groups.group_id` |
| `extension` | varchar(100) | '' | File extension without dot (e.g., `jpg`, `pdf`) |

### Sample Data by Group

**Group 1 — IMAGES** (allowed, cat_id=1 → inline display):
`gif`, `png`, `jpeg`, `jpg`, `tif`, `tiff`, `tga`

**Group 2 — ARCHIVES** (allowed):
`gtar`, `gz`, `tar`, `zip`, `rar`, `ace`, `torrent`, `tgz`, `bz2`, `7z`

**Group 3 — PLAIN_TEXT** (disabled):
`txt`, `c`, `h`, `cpp`, `hpp`, `diz`, `csv`, `ini`, `log`, `js`, `xml`

**Group 4 — DOCUMENTS** (disabled):
`xls`, `xlsx`, `xlsm`, `xlsb`, `doc`, `docx`, `docm`, `dot`, `dotx`, `dotm`, `pdf`, `ai`, `ps`, `ppt`, `pptx`, `pptm`, `odg`, `odp`, `ods`, `odt`, `rtf`

**Group 5 — DOWNLOADABLE_FILES** (disabled):
`mp3`, `mpeg`, `mpg`, `ogg`, `ogm`

**Note**: Extensions 55–108 duplicate extensions 1–54 (mapped to groups 1–5 again). These are duplicates — same as the extension_groups duplication.

---

## 4. Table: `phpbb_config` (Schema)

```sql
CREATE TABLE `phpbb_config` (
  `config_name` varchar(255) NOT NULL DEFAULT '',
  `config_value` varchar(255) NOT NULL DEFAULT '',
  `is_dynamic` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`config_name`),
  KEY `is_dynamic` (`is_dynamic`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

`is_dynamic` = 1 means the value changes frequently and is excluded from cache.

---

## 5. Storage/Upload Configuration (from `phpbb_config`)

### 5.1 Attachment Feature Flags

| Config Key | Value | Dynamic | Description |
|-----------|-------|---------|-------------|
| `allow_attachments` | `1` | 0 | Master switch: enable/disable attachments globally |
| `allow_pm_attach` | `0` | 0 | Allow attachments in private messages |

### 5.2 Upload Limits & Quotas

| Config Key | Value | Dynamic | Description |
|-----------|-------|---------|-------------|
| `max_filesize` | `262144` (256 KB) | 0 | Maximum file size per attachment in bytes |
| `max_filesize_pm` | `262144` (256 KB) | 0 | Maximum file size per PM attachment in bytes |
| `max_attachments` | `3` | 0 | Maximum number of attachments per post |
| `max_attachments_pm` | `1` | 0 | Maximum number of attachments per PM |
| `attachment_quota` | `52428800` (50 MB) | 0 | Total disk quota for all attachments in bytes |

### 5.3 Upload Paths & System

| Config Key | Value | Dynamic | Description |
|-----------|-------|---------|-------------|
| `upload_path` | `files` | 0 | Directory for attachment storage (relative to phpBB root) |
| `upload_icons_path` | `images/upload_icons` | 0 | Path to upload icon images |
| `upload_dir_size` | `0` | **1** (dynamic) | Current total size of upload directory in bytes (updated dynamically) |

### 5.4 Image & Thumbnail Settings

| Config Key | Value | Dynamic | Description |
|-----------|-------|---------|-------------|
| `img_create_thumbnail` | `0` | 0 | Auto-generate thumbnails for image attachments (0 = disabled) |
| `img_display_inlined` | `1` | 0 | Display images inline in posts |
| `img_max_height` | `0` | 0 | Max image height in pixels (0 = no limit) |
| `img_max_width` | `0` | 0 | Max image width in pixels (0 = no limit) |
| `img_link_height` | `0` | 0 | Display as link if image exceeds this height (0 = don't convert to link) |
| `img_link_width` | `0` | 0 | Display as link if image exceeds this width (0 = don't convert to link) |
| `img_max_thumb_width` | `400` | 0 | Maximum thumbnail width in pixels |
| `img_min_thumb_filesize` | `12000` (12 KB) | 0 | Minimum file size to generate a thumbnail (skip for very small images) |
| `img_quality` | `85` | 0 | JPEG compression quality for thumbnails (0–100) |
| `img_strip_metadata` | `0` | 0 | Strip EXIF/metadata from uploaded images |

### 5.5 Avatar Settings

| Config Key | Value | Dynamic | Description |
|-----------|-------|---------|-------------|
| `allow_avatar` | `1` | 0 | Master switch: enable avatars |
| `allow_avatar_upload` | `1` | 0 | Allow uploading custom avatars |
| `allow_avatar_local` | `0` | 0 | Allow selecting from gallery |
| `allow_avatar_remote` | `0` | 0 | Allow linking remote avatar URLs |
| `allow_avatar_remote_upload` | `0` | 0 | Allow uploading avatar from remote URL |
| `allow_avatar_gravatar` | `0` | 0 | Allow Gravatar integration |
| `avatar_filesize` | `6144` (6 KB) | 0 | Maximum avatar file size in bytes |
| `avatar_max_height` | `90` | 0 | Maximum avatar height in pixels |
| `avatar_max_width` | `90` | 0 | Maximum avatar width in pixels |
| `avatar_min_height` | `20` | 0 | Minimum avatar height in pixels |
| `avatar_min_width` | `20` | 0 | Minimum avatar width in pixels |
| `avatar_path` | `images/avatars/upload` | 0 | Storage path for uploaded avatars |
| `avatar_gallery_path` | `images/avatars/gallery` | 0 | Path to avatar gallery images |
| `avatar_salt` | `8fe48759f3b20fe7c212b7e838478fe0` | 0 | Salt used in avatar filename generation |

### 5.6 Secure Downloads

| Config Key | Value | Dynamic | Description |
|-----------|-------|---------|-------------|
| `secure_downloads` | `0` | 0 | Enable secure download mode (referer checking) |
| `secure_allow_deny` | `1` | 0 | 1 = allowlist mode, 0 = denylist mode for referer check |
| `secure_allow_empty_referer` | `1` | 0 | Allow downloads with empty/missing referer |

### 5.7 Content Verification & Safety

| Config Key | Value | Dynamic | Description |
|-----------|-------|---------|-------------|
| `check_attachment_content` | `1` | 0 | Scan attachment content for dangerous patterns |
| `mime_triggers` | `body\|head\|html\|img\|plaintext\|a href\|pre\|script\|table\|title` | 0 | Patterns to detect in file content — blocks uploads containing HTML/script tags |
| `remote_upload_verify` | `0` | 0 | Verify remote URLs before downloading |

### 5.8 Plupload (Chunked Upload)

| Config Key | Value | Dynamic | Description |
|-----------|-------|---------|-------------|
| `plupload_salt` | `247325349a9730fdc9b7b781bf49d38f` | 0 | Salt for plupload temporary file naming |
| `plupload_last_gc` | `0` | **1** (dynamic) | Timestamp of last plupload garbage collection |

### 5.9 Post Image Size Limits

| Config Key | Value | Dynamic | Description |
|-----------|-------|---------|-------------|
| `max_post_img_height` | `0` | 0 | Max image height in post content (BBCode [img], 0 = no limit) |
| `max_post_img_width` | `0` | 0 | Max image width in post content (BBCode [img], 0 = no limit) |

### 5.10 Statistics (Dynamic)

| Config Key | Value | Dynamic | Description |
|-----------|-------|---------|-------------|
| `num_files` | `0` | **1** | Total number of attachments stored |
| `upload_dir_size` | `0` | **1** | Current upload directory size in bytes |

---

## 6. Attachment Category Constants

**Source**: `src/phpbb/common/constants.php` (lines 168–170) and `src/phpbb/common/compatibility_globals.php` (lines 20–23)

### Active Constants

| Constant | Value | Description |
|----------|-------|-------------|
| `ATTACHMENT_CATEGORY_NONE` | 0 | No special category — standard download link |
| `ATTACHMENT_CATEGORY_IMAGE` | 1 | Inline image — displayed within post body |
| `ATTACHMENT_CATEGORY_THUMB` | 4 | Thumbnail display — **runtime only**, never stored in DB |

### Deprecated Constants (compatibility_globals.php)

| Constant | Value | Deprecated Since | Description |
|----------|-------|-----------------|-------------|
| `ATTACHMENT_CATEGORY_WM` | 2 | 3.2 | Windows Media streaming |
| `ATTACHMENT_CATEGORY_RM` | 3 | 3.2 | Real Media streaming |
| `ATTACHMENT_CATEGORY_FLASH` | 5 | 3.3 | Flash/SWF files |
| `ATTACHMENT_CATEGORY_QUICKTIME` | 6 | 3.2 | QuickTime/MOV files |

**Usage**: The `cat_id` column in `phpbb_extension_groups` maps to these constants. Only values 0 (NONE) and 1 (IMAGE) are used in practice.

---

## 7. Entity Relationships

```
phpbb_extension_groups (1) ──< phpbb_extensions (N)
       │                            │
       │ group_id                   │ extension (matched by name)
       │                            │
       │ cat_id → ATTACHMENT_CATEGORY_* constants
       │ allow_group → determines if uploads allowed
       │ max_filesize → per-group override (0 = use global)
       │
       └─────────────── (logical) ──> phpbb_attachments
                                       │
                                       │ extension column matches
                                       │ phpbb_extensions.extension
                                       │
                                       ├── post_msg_id → phpbb_posts (when in_message=0)
                                       ├── post_msg_id → phpbb_privmsgs (when in_message=1)
                                       ├── topic_id → phpbb_topics
                                       └── poster_id → phpbb_users
```

### Relationship Flow

1. **Extension validation**: On upload, the file extension is looked up in `phpbb_extensions` to find the `group_id`.
2. **Group check**: The `phpbb_extension_groups` row for that `group_id` determines:
   - Is the group allowed? (`allow_group`)
   - Is PM allowed? (`allow_in_pm`)
   - Per-group size limit? (`max_filesize`, 0 = use global `config['max_filesize']`)
   - Is it restricted to specific forums? (`allowed_forums`)
   - What category for display? (`cat_id` → inline image vs download link)
3. **Storage**: The attachment row in `phpbb_attachments` stores the metadata. The physical file goes to `{upload_path}/{physical_filename}`.
4. **Display**: When rendering, `cat_id` determines if the file is shown inline (IMAGE) or as a download link (NONE).

---

## 8. Storage Path Summary

| What | Config Key | Default Value | Actual Path |
|------|-----------|---------------|-------------|
| Attachments | `upload_path` | `files` | `{phpbb_root}/files/` |
| Upload icons | `upload_icons_path` | `images/upload_icons` | `{phpbb_root}/images/upload_icons/` |
| Uploaded avatars | `avatar_path` | `images/avatars/upload` | `{phpbb_root}/images/avatars/upload/` |
| Avatar gallery | `avatar_gallery_path` | `images/avatars/gallery` | `{phpbb_root}/images/avatars/gallery/` |
| Ranks images | `ranks_path` | `images/ranks` | `{phpbb_root}/images/ranks/` |
| Smilies | `smilies_path` | `images/smilies` | `{phpbb_root}/images/smilies/` |
| Icons | `icons_path` | `images/icons` | `{phpbb_root}/images/icons/` |

---

## 9. Key Design Observations

1. **No FK constraints**: None of the tables use actual foreign key constraints — all relationships are enforced in application code.
2. **Orphan pattern**: Attachments default to `is_orphan=1` and must be explicitly claimed. A cron job cleans up unclaimed orphans.
3. **Polymorphic FK**: `post_msg_id` in `phpbb_attachments` points to either posts or PMs based on `in_message` flag — no separate columns.
4. **Flat file storage**: All attachments go into a single `files/` directory (no subdirectories by date/user). Physical filenames are hashed to prevent collisions.
5. **No file deduplication**: Each upload creates a new physical file even if content is identical.
6. **Extension validation is whitelist-based**: Only extensions listed in `phpbb_extensions` with an allowed group can be uploaded. Unknown extensions are rejected.
7. **Size limits cascade**: Global `max_filesize` → per-group `max_filesize` (if non-zero) → `attachment_quota` (total).
8. **Avatar storage is separate**: Avatars use a completely separate path and configuration from post attachments.
9. **Thumbnail generation disabled by default**: `img_create_thumbnail=0`. When enabled, thumbnails are stored alongside originals.
10. **Secure downloads disabled**: `secure_downloads=0`. When enabled, phpBB checks HTTP referer before serving files through `download/file.php`.
