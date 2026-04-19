# Research Sources: `phpbb\storage` Service Design

---

## 1. attachment-lifecycle

### Key Files
| File | Lines | Purpose |
|------|-------|---------|
| `src/phpbb/forums/attachment/manager.php` | ~100 | Orchestrator — wraps delete, resync, upload; facade for consumers |
| `src/phpbb/forums/attachment/upload.php` | 339 | Upload flow — validation, extension check, quota check, file move, DB insert |
| `src/phpbb/forums/attachment/delete.php` | 480 | Delete flow — by post/topic/user/attach mode, unlink physical file, DB cleanup |
| `src/phpbb/forums/attachment/resync.php` | 124 | Resync attachment counts on posts/topics after deletes |

### DI Configuration
| File | Purpose |
|------|---------|
| `src/phpbb/common/config/default/container/services_attachment.yml` | Full DI wiring: attachment.manager, attachment.delete, attachment.resync, attachment.upload |

### Entry Points
| File | Trigger |
|------|---------|
| `web/posting.php` | File upload during post composition (form submit or AJAX plupload) |
| `web/adm/` | Admin orphan management, attachment settings |

### Grep Targets
- `is_orphan` — Orphan flag lifecycle (create as orphan → adopt on post submit)
- `create_thumbnail` / `img_create_thumbnail` — Thumbnail generation trigger
- `attach_quota` / `attachment_quota` — Global quota enforcement
- `ATTACH_QUOTA_REACHED` — Error path for quota exceeded

---

## 2. avatar-system

### Key Files
| File | Lines | Purpose |
|------|-------|---------|
| `src/phpbb/forums/avatar/manager.php` | 375 | Driver registry, handles enable/disable per driver, delegates to drivers |
| `src/phpbb/forums/avatar/driver/driver_interface.php` | 127 | Interface contract — get_data(), prepare_form(), process_form(), delete(), get_custom_html() |
| `src/phpbb/forums/avatar/driver/driver.php` | 152 | Base class — config access, image size validation, path helpers |
| `src/phpbb/forums/avatar/driver/upload.php` | 332 | Upload driver — file validation, move to avatar_path, filename = user_id + salt |
| `src/phpbb/forums/avatar/driver/remote.php` | 237 | Remote URL driver — validates remote URL, checks dimensions |
| `src/phpbb/forums/avatar/driver/local.php` | 207 | Gallery driver — reads from avatar gallery directory |
| `src/phpbb/forums/avatar/driver/gravatar.php` | ~100 | Gravatar driver — constructs URL from email hash |

### DI Configuration
| File | Purpose |
|------|---------|
| `src/phpbb/common/config/default/container/services_avatar.yml` | Driver registration via `avatar.driver` tag, `avatar.driver_collection` (service_collection) |

### Config Keys (from phpbb_dump.sql)
| Key | Default | Purpose |
|-----|---------|---------|
| `allow_avatar` | 1 | Master avatar toggle |
| `allow_avatar_upload` | 1 | Enable upload driver |
| `allow_avatar_local` | 0 | Enable gallery driver |
| `allow_avatar_remote` | 0 | Enable remote URL driver |
| `allow_avatar_remote_upload` | 0 | Enable URL fetch driver |
| `allow_avatar_gravatar` | 0 | Enable gravatar driver |
| `avatar_filesize` | 6144 | Max avatar file size (bytes) |
| `avatar_max_height` | 90 | Max avatar height (px) |
| `avatar_max_width` | 90 | Max avatar width (px) |
| `avatar_min_height` | 20 | Min avatar height (px) |
| `avatar_min_width` | 20 | Min avatar width (px) |
| `avatar_path` | images/avatars/upload | Upload storage directory |
| `avatar_salt` | (hash) | Filename salt for security |

### Entry Points
| File | Trigger |
|------|---------|
| `web/ucp.php` | User profile avatar change |
| `web/download/file.php` | Avatar serving (query param `?avatar=`) |

---

## 3. file-infrastructure

### Key Files
| File | Lines | Purpose |
|------|-------|---------|
| `src/phpbb/forums/files/upload.php` | 391 | Generic upload handler — set_allowed_extensions, set_max_filesize, handle_upload via type classes |
| `src/phpbb/forums/files/filespec.php` | 587 | File specification object — wraps uploaded file, provides get/set for name, size, ext, thumbnail, move_file(), clean_filename() |
| `src/phpbb/forums/files/factory.php` | ~50 | File spec + type factory from DI container |
| `src/phpbb/forums/files/types/type_interface.php` | ~30 | Interface for upload types |
| `src/phpbb/forums/files/types/base.php` | ~50 | Base upload type |
| `src/phpbb/forums/files/types/form.php` | ~100 | HTML form file upload type |
| `src/phpbb/forums/files/types/local.php` | ~80 | Local filesystem upload type |
| `src/phpbb/forums/files/types/remote.php` | ~120 | Remote URL fetch upload type |

### MIME Detection Chain
| File | Lines | Purpose |
|------|-------|---------|
| `src/phpbb/forums/mimetype/guesser.php` | ~80 | Composite guesser — iterates collection by priority |
| `src/phpbb/forums/mimetype/guesser_interface.php` | ~30 | Guesser interface |
| `src/phpbb/forums/mimetype/guesser_base.php` | ~40 | Base with priority support |
| `src/phpbb/forums/mimetype/content_guesser.php` | ~60 | Content-based MIME detection |
| `src/phpbb/forums/mimetype/extension_guesser.php` | ~80 | Extension-based MIME mapping |

### DI Configuration
| File | Purpose |
|------|---------|
| `src/phpbb/common/config/default/container/services_files.yml` | files.upload, files.filespec, files.factory, files.types.{form,local,remote} |
| `src/phpbb/common/config/default/container/services_mimetype_guesser.yml` | MIME guesser collection with priority chain |
| `src/phpbb/common/config/default/container/services_filesystem.yml` | Filesystem service |

### Filesystem Abstraction
| File | Lines | Purpose |
|------|-------|---------|
| `src/phpbb/forums/filesystem/filesystem.php` | 913 | Full filesystem ops — copy, remove, mkdir, chmod, realpath, clean path |
| `src/phpbb/forums/filesystem/filesystem_interface.php` | ~100 | Filesystem interface |
| `src/phpbb/forums/filesystem/exception/filesystem_exception.php` | ~30 | Filesystem exception |

### Plupload Integration
| File | Lines | Purpose |
|------|-------|---------|
| `src/phpbb/forums/plupload/plupload.php` | 420 | Chunked upload support, temp directory management, chunk assembly |
| `src/phpbb/forums/cron/task/core/tidy_plupload.php` | ~60 | Cron — cleans up orphaned plupload temp files |

---

## 4. storage-schema

### Database Tables

#### `phpbb_attachments` (SQL dump line 808)
```
attach_id        INT UNSIGNED PK AUTO_INCREMENT
post_msg_id      INT UNSIGNED    — FK to posts or privmsgs
topic_id         INT UNSIGNED    — Denormalized topic FK
in_message       TINYINT(1)      — 0=post, 1=PM
poster_id        INT UNSIGNED    — FK to users
is_orphan        TINYINT(1)      — 1=not yet attached to post
physical_filename VARCHAR(255)   — On-disk filename (hash-based)
real_filename    VARCHAR(255)    — Original user filename
download_count   MEDIUMINT       — Incremented on serve
attach_comment   TEXT            — User-provided description
extension        VARCHAR(100)    — File extension
mimetype         VARCHAR(100)    — Detected MIME type
filesize         INT UNSIGNED    — File size in bytes
filetime         INT UNSIGNED    — Upload timestamp
thumbnail        TINYINT(1)      — Whether thumbnail exists
```
**Indexes**: filetime, post_msg_id, topic_id, poster_id, is_orphan

#### `phpbb_extension_groups` (SQL dump line 1521)
```
group_id         MEDIUMINT PK AUTO_INCREMENT
group_name       VARCHAR(255)   — E.g. IMAGES, ARCHIVES, PLAIN_TEXT
cat_id           TINYINT(2)     — Category (1=images with thumbnails)
allow_group      TINYINT(1)     — Whether group is allowed
download_mode    TINYINT(1)     — 1=inline, 0=force download
upload_icon      VARCHAR(255)   — Icon for extension group
max_filesize     INT UNSIGNED   — Per-group size limit (0=use global)
allowed_forums   TEXT           — Forum IDs where allowed (empty=all)
allow_in_pm      TINYINT(1)    — Allowed in PMs
```

#### `phpbb_extensions` (SQL dump line 1562)
```
extension_id     MEDIUMINT PK AUTO_INCREMENT
group_id         MEDIUMINT     — FK to extension_groups
extension        VARCHAR(100)  — E.g. jpg, png, zip, pdf
```

### Config Entries (from phpbb_config inserts)

#### Attachment Config
| Key | Default | Line | Purpose |
|-----|---------|------|---------|
| `allow_attachments` | 1 | 1039 | Master attachment toggle |
| `allow_pm_attach` | 0 | 1060 | Allow attachments in PMs |
| `attachment_quota` | 52428800 | 1077 | Global attachment quota (50MB) |
| `check_attachment_content` | 1 | 1115 | Content-based MIME sniffing |
| `max_filesize` | 262144 | 1255 | Global max file size (256KB) |
| `max_filesize_pm` | 262144 | 1256 | PM max file size (256KB) |
| `upload_path` | files | 1373 | Attachment upload directory |
| `secure_downloads` | 0 | 1333 | Force all downloads through file.php |
| `secure_allow_deny` | 1 | 1331 | Secure download allow/deny mode |

#### Image Config
| Key | Default | Line | Purpose |
|-----|---------|------|---------|
| `img_create_thumbnail` | 0 | 1194 | Enable thumbnail generation |
| `img_max_thumb_width` | 400 | 1199 | Thumbnail max width |
| `img_max_height` | 0 | 1198 | Inline image max height (0=unlimited) |
| `img_max_width` | 0 | 1200 | Inline image max width (0=unlimited) |
| `img_link_height` | 0 | 1196 | Height threshold for auto-linking |
| `img_link_width` | 0 | 1197 | Width threshold for auto-linking |
| `img_strip_metadata` | 0 | 1203 | Strip EXIF metadata on upload |

#### Plupload Config
| Key | Default | Line | Purpose |
|-----|---------|------|---------|
| `plupload_last_gc` | 0 | 1291 | Last plupload garbage collection time |
| `plupload_salt` | (hash) | 1292 | Plupload temp filename salt |

#### Avatar Config
| Key | Default | Line | Purpose |
|-----|---------|------|---------|
| `allow_avatar` | 1 | 1041 | Master avatar toggle |
| `allow_avatar_upload` | 1 | 1046 | Upload driver toggle |
| `allow_avatar_local` | 0 | 1043 | Gallery driver toggle |
| `allow_avatar_remote` | 0 | 1044 | Remote URL driver toggle |
| `allow_avatar_remote_upload` | 0 | 1045 | Remote fetch driver toggle |
| `allow_avatar_gravatar` | 0 | 1042 | Gravatar driver toggle |
| `avatar_filesize` | 6144 | 1083 | Max avatar file size (6KB) |
| `avatar_max_height` | 90 | 1085 | Max avatar height |
| `avatar_max_width` | 90 | 1086 | Max avatar width |
| `avatar_min_height` | 20 | 1087 | Min avatar height |
| `avatar_min_width` | 20 | 1088 | Min avatar width |
| `avatar_path` | images/avatars/upload | 1089 | Avatar storage directory |
| `avatar_salt` | (hash) | 1090 | Avatar filename salt |

---

## 5. download-serving

### Key Files
| File | Lines | Purpose |
|------|-------|---------|
| `web/download/file.php` | ~250 | Main entry point — dual mode: avatar serving (lines 40-155) and attachment serving (lines 157+) |
| `src/phpbb/common/functions_download.php` | 796 | Helper functions: send_file_to_browser(), file_gc(), download permission checks, phpbb_download_handle_forum_auth(), phpbb_download_handle_pm_auth(), extension_allowed() |

### Avatar Serving Flow (file.php lines 40-155)
- Input: `?avatar=g123_456.jpg` (g prefix = group avatar)
- Validates extension (png, gif, jpg, jpeg only)
- Sends HTTP caching headers (If-Modified-Since)
- Calls `send_avatar_to_browser()` from functions_download.php

### Attachment Serving Flow (file.php lines 157+)
- Input: `?id=123&mode=view&t=1` (t=thumbnail)
- Starts session, checks ACL
- Validates `allow_attachments` / `allow_pm_attach` config
- Loads attachment row from DB by attach_id
- Permission gates:
  - `download_allowed()` — global download permission
  - Orphan check: only admin or poster can access
  - Forum-level: `phpbb_download_handle_forum_auth()` — checks topic's forum permissions
  - PM-level: `phpbb_download_handle_pm_auth()` — checks PM recipient/sender
  - Extension allowed: `extension_allowed()` — verifies extension still permitted
- Post visibility check via `content.visibility` service
- Increments `download_count`
- Determines send mode: inline vs force download (from extension_group.download_mode)
- Calls `send_file_to_browser()` from functions_download.php

### Grep Targets
- `send_file_to_browser` — Final output function
- `send_avatar_to_browser` — Avatar output function
- `download_allowed` — Global download permission check
- `set_modified_headers` — HTTP caching
- `phpbb_download_handle_forum_auth` — Forum-level auth for downloads
- `extension_allowed` — Extension whitelist for serving

---

## 6. quota-enforcement

### Key Code Sections

#### In `attachment/upload.php`
| Method/Region | Lines | Purpose |
|---------------|-------|---------|
| Extension-specific filesize check | ~143-153 | Uses `extensions[ext]['max_filesize']` (from extension_groups.max_filesize), falls back to `config['max_filesize']` or `config['max_filesize_pm']` |
| `check_attach_quota()` | ~277-290 | Compares `config['upload_dir_size'] + filesize` against `config['attachment_quota']` (global quota) |
| `check_disk_space()` | ~295-315 | PHP `@disk_free_space()` on upload dir, errors with ATTACH_QUOTA_REACHED if insufficient |

#### In `files/upload.php`
| Method | Lines | Purpose |
|--------|-------|---------|
| `set_max_filesize()` | TBD | Sets max filesize for validation |
| Extension whitelist setup | TBD | `set_allowed_extensions()` configures allowed extensions |

#### In `files/filespec.php`
| Method | Lines | Purpose |
|--------|-------|---------|
| Filesize validation | TBD | Compares against max_filesize during upload type processing |
| Image dimension check | TBD | Uses `upload_imagesize` service for width/height validation |

### Config Keys for Quota
| Key | Default | Scope |
|-----|---------|-------|
| `attachment_quota` | 52428800 (50MB) | Global — total size of all attachments |
| `max_filesize` | 262144 (256KB) | Per-file — individual file size limit for forum posts |
| `max_filesize_pm` | 262144 (256KB) | Per-file — individual file size limit for PMs |
| `avatar_filesize` | 6144 (6KB) | Per-file — individual avatar size limit |
| Extension group `max_filesize` | 0 (=global) | Per-extension-group — overrides global per-file limit |
| `upload_dir_size` | dynamic | Tracked total — current total size of upload directory |

### Orphan Cleanup
| File | Purpose |
|------|---------|
| `src/phpbb/forums/cron/task/core/tidy_plupload.php` | Cron task — removes stale plupload temp chunks |
| Orphan management in ACP | Admin can claim/delete orphaned attachments (is_orphan=1) |

### Gaps in Legacy (New Design Needs)
- **No per-user quota** — No config or code for user-level attachment quota
- **No per-forum quota** — `allowed_forums` on extension_groups limits WHERE but not HOW MUCH
- **No variant tracking** — Thumbnail is boolean flag, no general variant/transform system
- **No storage adapter** — Hardcoded filesystem paths, no S3/external storage abstraction
