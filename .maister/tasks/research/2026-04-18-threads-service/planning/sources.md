# Research Sources — `phpbb\threads` Service Design

---

## 1. posting-workflow

### Core Files
- `src/phpbb/common/functions_posting.php` (3009 LOC) — **Primary**: 16 functions
  - `submit_post()` L1668–L2712 (~1044 LOC) — Main posting orchestrator, modes: post/reply/edit/delete
  - `delete_post()` L1373–L1667 (~295 LOC) — Hard-delete and soft-delete dispatch
  - `update_post_information()` L262–L427 — Updates topic first/last post metadata
  - `topic_review()` L1071–L1372 — Topic review for reply/quote
  - `phpbb_bump_topic()` L2712–L2811 — Topic bumping logic
  - `phpbb_handle_post_delete()` L2848–L3009 — Post delete handler
  - `posting_gen_topic_types()` L475–L535 — Topic type selection (normal/sticky/announce/global)
  - `posting_gen_topic_icons()` L428–L474 — Topic icon selection
  - `posting_gen_inline_attachments()` L813–L843 — Inline attachment placeholders
  - `posting_gen_attachment_entry()` L844–L935 — Attachment form entry generation
  - `load_drafts()` L936–L1070 — Draft loading for posting form
  - `generate_smilies()` L21–L261 — Smiley selector
  - `get_img_size_format()` L536–L562 — Image dimension formatting
  - `get_supported_image_types()` L563–L632 — Supported image type detection
  - `create_thumbnail()` L633–L812 — Thumbnail generation
  - `phpbb_upload_popup()` L2812–L2847 — Upload popup window

- `web/posting.php` (2123 LOC) — Posting entry point
  - Mode routing: post, reply, quote, edit, delete, bump, smilies
  - Form validation, CSRF check (`check_form_key`)
  - Draft save/load switching
  - Poll form handling
  - Attachment upload integration
  - Calls to `submit_post()` with assembled data array

### Constants
- `src/phpbb/common/constants.php`
  - Topic types: `POST_NORMAL=0`, `POST_STICKY=1`, `POST_ANNOUNCE=2`, `POST_GLOBAL=3` (L115–118)
  - Topic status: `ITEM_UNLOCKED=0`, `ITEM_LOCKED=1`, `ITEM_MOVED=2` (L87–89)
  - Visibility: `ITEM_UNAPPROVED=0`, `ITEM_APPROVED=1`, `ITEM_DELETED=2`, `ITEM_REAPPROVE=3` (L91–94)
  - Forum flags: `FORUM_FLAG_POST_REVIEW=32`, `FORUM_FLAG_QUICK_REPLY=64` (L102–103)

### Events to Catalog
- `core.modify_submit_post_data` — Pre-submission data modification
- `core.submit_post_modify_sql_data` — SQL data modification
- `core.submit_post_end` — Post-submission side effects
- (scan for all `$phpbb_dispatcher->trigger_event` calls in functions_posting.php)

---

## 2. topic-post-schema

### SQL Dump — Table Definitions
Source: `phpbb_dump.sql`

| Table | DDL Line | Columns | Purpose |
|-------|----------|---------|---------|
| `phpbb_topics` | L3521 | 39 columns | Topic metadata, first/last post refs, poll data, visibility, counters |
| `phpbb_posts` | L2694 | 29 columns | Post content, BBCode metadata, edit tracking, visibility |
| `phpbb_topics_posted` | L3590 | 3 columns | Per-user "has posted in topic" flag |
| `phpbb_topics_track` | L3616 | 4 columns | Per-user topic read tracking |
| `phpbb_topics_watch` | L3643 | 3 columns | Topic subscription/notification |
| `phpbb_bookmarks` | L915 | 2 columns | Topic bookmarks per user |
| `phpbb_drafts` | L1468 | 7 columns | User drafts (topic or post) |
| `phpbb_poll_options` | L2641 | 4 columns | Poll choices per topic |
| `phpbb_poll_votes` | L2667 | 4 columns | Poll votes per user |
| `phpbb_attachments` | L808 | 15 columns | File attachments linked to posts/PMs |
| `phpbb_bbcodes` | L883 | — | Custom BBCode definitions |
| `phpbb_smilies` | L3343 | — | Smiley definitions |

### Key Schema Details (verified from dump)

**phpbb_topics** (39 cols):
- PK: `topic_id` (auto_increment)
- FK-like: `forum_id`, `topic_poster`, `topic_first_post_id`, `topic_last_post_id`, `topic_last_poster_id`
- State: `topic_status` (ITEM_UNLOCKED/LOCKED/MOVED), `topic_type` (POST_NORMAL/STICKY/ANNOUNCE/GLOBAL), `topic_visibility` (ITEM_*)
- Counters: `topic_posts_approved`, `topic_posts_unapproved`, `topic_posts_softdeleted`, `topic_views`
- Poll (embedded): `poll_title`, `poll_start`, `poll_length`, `poll_max_options`, `poll_last_vote`, `poll_vote_change`
- Soft-delete: `topic_delete_time`, `topic_delete_reason`, `topic_delete_user`
- Indexes: `forum_id`, `forum_id_type`, `last_post_time`, `fid_time_moved`, `topic_visibility`, `forum_vis_last`, `latest_topics`

**phpbb_posts** (29 cols):
- PK: `post_id` (auto_increment)
- FK-like: `topic_id`, `forum_id`, `poster_id`
- Content: `post_text` (mediumtext), `post_subject`, `post_checksum`
- BBCode: `bbcode_uid` (varchar 8), `bbcode_bitfield` (varchar 255)
- Flags: `enable_bbcode`, `enable_smilies`, `enable_magic_url`, `enable_sig`, `post_attachment`
- Edit tracking: `post_edit_time`, `post_edit_reason`, `post_edit_user`, `post_edit_count`, `post_edit_locked`
- Soft-delete: `post_visibility`, `post_delete_time`, `post_delete_reason`, `post_delete_user`
- Indexes: `forum_id`, `topic_id`, `poster_ip`, `poster_id`, `tid_post_time`, `post_username`, `post_visibility`

**phpbb_drafts** (7 cols):
- PK: `draft_id` (auto_increment)
- `user_id`, `topic_id`, `forum_id`, `save_time`, `draft_subject`, `draft_message` (mediumtext)

**phpbb_poll_options** (4 cols, no PK):
- `poll_option_id`, `topic_id`, `poll_option_text` (text), `poll_option_total`

**phpbb_poll_votes** (4 cols, no PK):
- `topic_id`, `poll_option_id`, `vote_user_id`, `vote_user_ip`

**phpbb_attachments** (15 cols):
- PK: `attach_id` (auto_increment)
- `post_msg_id`, `topic_id`, `in_message` (0=post, 1=PM), `poster_id`, `is_orphan`
- File: `physical_filename`, `real_filename`, `extension`, `mimetype`, `filesize`, `filetime`, `thumbnail`

---

## 3. content-format

### Legacy BBCode Pipeline
- `src/phpbb/common/bbcode.php` (712 LOC) — Base `bbcode` class, rendering BBCodes from stored format
- `src/phpbb/common/message_parser.php` (2086 LOC) — Parsing pipeline
  - `bbcode_firstpass` (L35–L1094) extends `bbcode` — First-pass BBCode parsing (text → DB storage format)
  - `parse_message` (L1095–L2086) extends `bbcode_firstpass` — Full message parser with validation, URL/email transforms
  - BBCode IDs: code, quote, attachment, b, i, url, img, size, color, u, list, email (L138–193)
  - `bbcode_uid` generation — 8-char unique ID embedded in stored BBCode tags
  - `bbcode_bitfield` — Bitmask of which BBCodes are used in a post

### Modern s9e TextFormatter
- `src/phpbb/forums/textformatter/parser_interface.php` — Parser contract
  - `parse($text)` → string
  - `disable_bbcode($name)`, `disable_bbcodes()`, `disable_censor()`, `disable_magic_url()`, `disable_smilies()`
- `src/phpbb/forums/textformatter/renderer_interface.php` — Renderer contract
  - `render($text)` → string (HTML)
  - `set_smilies_path()`, `get_viewcensors()`, `get_viewflash()`, `get_viewimg()`
- `src/phpbb/forums/textformatter/s9e/parser.php` (414 LOC) — s9e parser implementation
- `src/phpbb/forums/textformatter/s9e/renderer.php` (313 LOC) — s9e renderer implementation
- `src/phpbb/forums/textformatter/s9e/factory.php` (682 LOC) — Parser/renderer factory with BBCode registration
- `src/phpbb/forums/textformatter/s9e/utils.php` — Utility functions
- `src/phpbb/forums/textformatter/s9e/quote_helper.php` — Quote handling
- `src/phpbb/forums/textformatter/s9e/link_helper.php` — URL/link handling
- `src/phpbb/forums/textformatter/s9e/bbcode_merger.php` — BBCode merging
- `src/phpbb/forums/textformatter/data_access.php` — DB access for textformatter config
- `src/phpbb/forums/textformatter/cache_interface.php` — Caching contract
- `src/phpbb/forums/textformatter/utils_interface.php` — Utils contract
- `src/phpbb/forums/textformatter/acp_utils_interface.php` — Admin utils

### Content Storage Format
- Posts store s9e-parsed XML (wrapped in `<t>` or `<r>` tags) in `post_text`
- `bbcode_uid` and `bbcode_bitfield` columns store legacy compatibility metadata
- Rendering pipeline: `post_text` → s9e `renderer->render()` → HTML
- Sample stored text: `<t>This is an example post...</t>` (simple text, no BBCodes)

### Message Form Classes
- `src/phpbb/forums/message/message.php` — Core message handling
- `src/phpbb/forums/message/form.php` — Base form
- `src/phpbb/forums/message/topic_form.php` — Topic-specific form
- `src/phpbb/forums/message/admin_form.php` — Admin form
- `src/phpbb/forums/message/user_form.php` — User form

---

## 4. polls-drafts

### Poll Tables
- `phpbb_poll_options` (L2641) — Options per topic: `poll_option_id`, `topic_id`, `poll_option_text`, `poll_option_total`
- `phpbb_poll_votes` (L2667) — Votes per user: `topic_id`, `poll_option_id`, `vote_user_id`, `vote_user_ip`
- Poll metadata stored ON `phpbb_topics`: `poll_title`, `poll_start`, `poll_length`, `poll_max_options`, `poll_last_vote`, `poll_vote_change`

### Poll Logic in Code
- `src/phpbb/common/functions_posting.php` → `submit_post()` L1668+ — Poll insert/update/delete within topic creation/edit
- `web/posting.php` — Poll form data collection (`$poll` array assembly)
- `web/viewtopic.php` — Poll display and voting UI

### Draft Table
- `phpbb_drafts` (L1468) — `draft_id`, `user_id`, `topic_id`, `forum_id`, `save_time`, `draft_subject`, `draft_message`

### Draft Logic in Code
- `src/phpbb/common/functions_posting.php` → `load_drafts()` L936–L1070
- `web/posting.php` — Draft save (INSERT) and load (SELECT + template) handling

---

## 5. topic-display

### Primary Files
- `web/viewtopic.php` (2425 LOC) — Main topic view
  - URL parameter parsing (topic_id, post_id, start)
  - Authentication/permission checks
  - Topic data fetch from `phpbb_topics`
  - Post query construction with pagination
  - Post processing loop: text rendering, attachment association, user info
  - Template variable assignment
  - View counter increment
  - Topic tracking (read/unread)

- `src/phpbb/common/functions_display.php` (1781 LOC) — Display helpers
  - Topic status/icon generation
  - Topic row building
  - Forum listing helpers
  - Tracking/notification display
  - Online status

### Rendering Pipeline Steps (in viewtopic.php)
1. Query posts (with visibility filter)
2. Fetch user data for poster info
3. Fetch attachments for posts (batch)
4. Fetch custom profile fields
5. For each post: render text via s9e renderer, inline attachments, build template row
6. Assign pagination

### Related
- `src/phpbb/forums/pagination.php` — Pagination helper

---

## 6. soft-delete-visibility

### Primary File
- `src/phpbb/forums/content_visibility.php` (910 LOC) — namespace `phpbb\content_visibility`
  - Constructor deps: `auth`, `config`, `event_dispatcher`, `db`, `user`, table names
  - `can_soft_delete($forum_id, $poster_id, $post_locked)` L102 — Permission check
  - `get_count($mode, $data, $forum_id)` L124 — Get approved/unapproved/softdeleted counts
  - `is_visible($mode, $forum_id, $data)` L145 — Check if content is visible to current user
  - `get_visibility_sql($mode, $forum_id, $table_alias)` L187 — SQL WHERE clause for visibility filtering
  - `get_forums_visibility_sql($mode, $forum_ids, $table_alias)` L250 — Multi-forum visibility SQL
  - `get_global_visibility_sql($mode, $exclude_forum_ids, $table_alias)` L311 — Global announcements visibility
  - `set_post_visibility($visibility, $post_id, ...)` L376 — Change post visibility + counter sync
  - `set_topic_visibility($visibility, $topic_id, ...)` L714 — Change topic visibility + counter sync
  - `add_post_to_statistic($data, &$sql_data)` L835 — Increment counters
  - `remove_post_from_statistic($data, &$sql_data)` L856 — Decrement counters
  - `remove_topic_from_statistic($data, &$sql_data)` L889 — Remove topic from stats

### Visibility Constants
- `ITEM_UNAPPROVED = 0` — Pending moderation
- `ITEM_APPROVED = 1` — Published/visible
- `ITEM_DELETED = 2` — Soft-deleted
- `ITEM_REAPPROVE = 3` — Edited, needs re-approval

### Counter Fields (on phpbb_topics)
- `topic_posts_approved` — Count of approved posts
- `topic_posts_unapproved` — Count of unapproved posts
- `topic_posts_softdeleted` — Count of soft-deleted posts

### Counter Fields (on phpbb_forums — via hierarchy service)
- `forum_posts_approved`, `forum_posts_unapproved`, `forum_posts_softdeleted`
- `forum_topics_approved`, `forum_topics_unapproved`, `forum_topics_softdeleted`

---

## 7. attachment-patterns

### Attachment Module (OOP, namespaced)
- `src/phpbb/forums/attachment/manager.php` (99 LOC) — Facade: delegates to delete/resync/upload
  - Properties: `$delete`, `$resync`, `$upload` (typed)
  - Acts as composition root for attachment operations
- `src/phpbb/forums/attachment/upload.php` (339 LOC) — File upload handling
- `src/phpbb/forums/attachment/delete.php` (480 LOC) — Attachment deletion (physical + DB)
- `src/phpbb/forums/attachment/resync.php` (124 LOC) — Re-synchronize attachment flags

### Attachment Integration Points (in posting workflow)
- `functions_posting.php` → `posting_gen_inline_attachments()` L813 — Generates inline `[attachment=N]` placeholders
- `functions_posting.php` → `posting_gen_attachment_entry()` L844 — Attachment form entry in posting form
- `submit_post()` — Attachment association (orphan → post linkage via `post_msg_id`, `is_orphan=0`)
- `web/posting.php` — Upload handling during post creation
- BBCode `[attachment=N]` — Parsed to `BBCODE_ID_ATTACH` in message_parser (L148–151)

### Attachment DB Schema
- `phpbb_attachments` (L808) — 15 columns
  - Links: `post_msg_id` → post/PM, `topic_id` → topic, `poster_id` → user
  - Orphan pattern: `is_orphan=1` during upload, set to `0` on post submission
  - Type: `in_message` (0=forum post, 1=PM)

### Plugin Pattern Extraction
Key observations for generalizing to content pipeline plugins:
- Attachments are a **separate module** (`phpbb\attachment\*`) with its own manager
- They hook into posting via **BBCode** (`[attachment=N]`) — content references
- They have an **orphan lifecycle** (uploaded before post exists, linked on submit)
- They have **independent CRUD** (upload/delete/resync) not embedded in submit_post()
- They affect **post flags** (`post_attachment`, `topic_attachment`) — metadata coupling
- Display: attachment data is fetched in batch during viewtopic.php, injected into post rendering

---

## Design Reference — Existing Services

### `phpbb\user` IMPLEMENTATION_SPEC.md (verified)
Located: `src/phpbb/user/IMPLEMENTATION_SPEC.md` (66KB)
Key patterns to follow:
- No legacy imports, no globals
- Constructor injection, interface-first
- DTOs for multi-field inputs (no assoc arrays)
- Domain exceptions per error case
- Events for side-effects (EventDispatcherInterface)
- PDO with prepared statements
- Unix timestamps in DB, DateTimeImmutable in entities
- Repository pattern per aggregate
- Contract/ directory for interfaces

### API Event Architecture
- `src/phpbb/api/event/auth_subscriber.php` — Auth middleware as event subscriber
- `src/phpbb/api/event/json_exception_subscriber.php` — Exception handling
- `src/phpbb/api/v1/controller/` — RESTful controller pattern
