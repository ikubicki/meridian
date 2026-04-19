# Topic & Post Database Schema

Source: `phpbb_dump.sql` (CREATE TABLE statements), `src/phpbb/common/constants.php`

---

## 1. phpbb_topics

The main topics table. Each row represents a forum thread.

### DDL

```sql
CREATE TABLE `phpbb_topics` (
  `topic_id`                    int(10) unsigned    NOT NULL AUTO_INCREMENT,
  `forum_id`                    mediumint(8) unsigned NOT NULL DEFAULT 0,
  `icon_id`                     mediumint(8) unsigned NOT NULL DEFAULT 0,
  `topic_attachment`            tinyint(1) unsigned NOT NULL DEFAULT 0,
  `topic_reported`              tinyint(1) unsigned NOT NULL DEFAULT 0,
  `topic_title`                 varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '',
  `topic_poster`                int(10) unsigned    NOT NULL DEFAULT 0,
  `topic_time`                  int(11) unsigned    NOT NULL DEFAULT 0,
  `topic_time_limit`            int(11) unsigned    NOT NULL DEFAULT 0,
  `topic_views`                 int(10) unsigned    NOT NULL DEFAULT 0,
  `topic_status`                tinyint(3)          NOT NULL DEFAULT 0,
  `topic_type`                  tinyint(3)          NOT NULL DEFAULT 0,
  `topic_first_post_id`         int(10) unsigned    NOT NULL DEFAULT 0,
  `topic_first_poster_name`     varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '',
  `topic_first_poster_colour`   varchar(6)          NOT NULL DEFAULT '',
  `topic_last_post_id`          int(10) unsigned    NOT NULL DEFAULT 0,
  `topic_last_poster_id`        int(10) unsigned    NOT NULL DEFAULT 0,
  `topic_last_poster_name`      varchar(255)        NOT NULL DEFAULT '',
  `topic_last_poster_colour`    varchar(6)          NOT NULL DEFAULT '',
  `topic_last_post_subject`     varchar(255)        NOT NULL DEFAULT '',
  `topic_last_post_time`        int(11) unsigned    NOT NULL DEFAULT 0,
  `topic_last_view_time`        int(11) unsigned    NOT NULL DEFAULT 0,
  `topic_moved_id`              int(10) unsigned    NOT NULL DEFAULT 0,
  `topic_bumped`                tinyint(1) unsigned NOT NULL DEFAULT 0,
  `topic_bumper`                mediumint(8) unsigned NOT NULL DEFAULT 0,
  `poll_title`                  varchar(255)        NOT NULL DEFAULT '',
  `poll_start`                  int(11) unsigned    NOT NULL DEFAULT 0,
  `poll_length`                 int(11) unsigned    NOT NULL DEFAULT 0,
  `poll_max_options`            tinyint(4)          NOT NULL DEFAULT 1,
  `poll_last_vote`              int(11) unsigned    NOT NULL DEFAULT 0,
  `poll_vote_change`            tinyint(1) unsigned NOT NULL DEFAULT 0,
  `topic_visibility`            tinyint(3)          NOT NULL DEFAULT 0,
  `topic_delete_time`           int(11) unsigned    NOT NULL DEFAULT 0,
  `topic_delete_reason`         varchar(255)        NOT NULL DEFAULT '',
  `topic_delete_user`           int(10) unsigned    NOT NULL DEFAULT 0,
  `topic_posts_approved`        mediumint(8) unsigned NOT NULL DEFAULT 0,
  `topic_posts_unapproved`      mediumint(8) unsigned NOT NULL DEFAULT 0,
  `topic_posts_softdeleted`     mediumint(8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`topic_id`),
  KEY `forum_id`        (`forum_id`),
  KEY `forum_id_type`   (`forum_id`, `topic_type`),
  KEY `last_post_time`  (`topic_last_post_time`),
  KEY `fid_time_moved`  (`forum_id`, `topic_last_post_time`, `topic_moved_id`),
  KEY `topic_visibility` (`topic_visibility`),
  KEY `forum_vis_last`  (`forum_id`, `topic_visibility`, `topic_last_post_id`),
  KEY `latest_topics`   (`forum_id`, `topic_last_post_time`, `topic_last_post_id`, `topic_moved_id`)
);
```

### Column Reference

| Column | Purpose |
|--------|---------|
| `topic_id` | PK, auto-increment |
| `forum_id` | FK → phpbb_forums. 0 for global announcements |
| `icon_id` | FK → phpbb_icons. Topic icon |
| `topic_attachment` | Boolean flag: 1 if any post in topic has attachment |
| `topic_reported` | Boolean flag: 1 if any post in topic is reported |
| `topic_title` | Topic subject line (utf8 unicode_ci for search) |
| `topic_poster` | FK → phpbb_users.user_id. Original poster |
| `topic_time` | Unix timestamp — topic creation time |
| `topic_time_limit` | Unix timestamp — time limit for sticky/announce expiry (0 = no limit) |
| `topic_views` | **Denormalized counter** — view count |
| `topic_status` | Lock status (see ITEM_UNLOCKED/ITEM_LOCKED/ITEM_MOVED constants) |
| `topic_type` | Topic type (see POST_NORMAL/POST_STICKY/POST_ANNOUNCE/POST_GLOBAL) |
| `topic_first_post_id` | **Denormalized** FK → phpbb_posts.post_id |
| `topic_first_poster_name` | **Denormalized** — cached poster username |
| `topic_first_poster_colour` | **Denormalized** — cached group colour hex (e.g. "AA0000") |
| `topic_last_post_id` | **Denormalized** FK → phpbb_posts.post_id |
| `topic_last_poster_id` | **Denormalized** FK → phpbb_users.user_id |
| `topic_last_poster_name` | **Denormalized** — cached last poster username |
| `topic_last_poster_colour` | **Denormalized** — cached last poster group colour |
| `topic_last_post_subject` | **Denormalized** — cached last post subject |
| `topic_last_post_time` | **Denormalized** Unix timestamp — last post time (used for sorting) |
| `topic_last_view_time` | Unix timestamp — last time topic was viewed |
| `topic_moved_id` | If topic_status=ITEM_MOVED, this is the original topic_id (shadow topic) |
| `topic_bumped` | Boolean flag: 1 if topic was bumped |
| `topic_bumper` | FK → phpbb_users.user_id — who bumped |
| `poll_title` | Poll question text (empty = no poll) |
| `poll_start` | Unix timestamp — when poll was created |
| `poll_length` | Duration in seconds (0 = no expiry) |
| `poll_max_options` | Max options a user can vote for |
| `poll_last_vote` | Unix timestamp — last vote cast |
| `poll_vote_change` | Boolean: 1 if users can change votes |
| `topic_visibility` | Visibility state (see ITEM_UNAPPROVED/ITEM_APPROVED/ITEM_DELETED/ITEM_REAPPROVE) |
| `topic_delete_time` | Unix timestamp — soft-delete time |
| `topic_delete_reason` | Reason text for soft-delete |
| `topic_delete_user` | FK → phpbb_users — who soft-deleted |
| `topic_posts_approved` | **Denormalized counter** — approved post count |
| `topic_posts_unapproved` | **Denormalized counter** — unapproved/pending post count |
| `topic_posts_softdeleted` | **Denormalized counter** — soft-deleted post count |

### Indexes

| Key Name | Columns | Purpose |
|----------|---------|---------|
| PRIMARY | `topic_id` | PK lookup |
| `forum_id` | `forum_id` | List topics in a forum |
| `forum_id_type` | `forum_id, topic_type` | Filter by type within forum (stickies, announces) |
| `last_post_time` | `topic_last_post_time` | Global sort by activity |
| `fid_time_moved` | `forum_id, topic_last_post_time, topic_moved_id` | Forum listing sorted by time, excluding moved |
| `topic_visibility` | `topic_visibility` | Filter by visibility state |
| `forum_vis_last` | `forum_id, topic_visibility, topic_last_post_id` | Forum listing filtered by visibility |
| `latest_topics` | `forum_id, topic_last_post_time, topic_last_post_id, topic_moved_id` | Optimized forum listing query |

---

## 2. phpbb_posts

Individual posts within topics.

### DDL

```sql
CREATE TABLE `phpbb_posts` (
  `post_id`             int(10) unsigned    NOT NULL AUTO_INCREMENT,
  `topic_id`            int(10) unsigned    NOT NULL DEFAULT 0,
  `forum_id`            mediumint(8) unsigned NOT NULL DEFAULT 0,
  `poster_id`           int(10) unsigned    NOT NULL DEFAULT 0,
  `icon_id`             mediumint(8) unsigned NOT NULL DEFAULT 0,
  `poster_ip`           varchar(40)         NOT NULL DEFAULT '',
  `post_time`           int(11) unsigned    NOT NULL DEFAULT 0,
  `post_reported`       tinyint(1) unsigned NOT NULL DEFAULT 0,
  `enable_bbcode`       tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_smilies`      tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_magic_url`    tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_sig`          tinyint(1) unsigned NOT NULL DEFAULT 1,
  `post_username`       varchar(255)        NOT NULL DEFAULT '',
  `post_subject`        varchar(255) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci NOT NULL DEFAULT '',
  `post_text`           mediumtext          NOT NULL,
  `post_checksum`       varchar(32)         NOT NULL DEFAULT '',
  `post_attachment`     tinyint(1) unsigned NOT NULL DEFAULT 0,
  `bbcode_bitfield`     varchar(255)        NOT NULL DEFAULT '',
  `bbcode_uid`          varchar(8)          NOT NULL DEFAULT '',
  `post_postcount`      tinyint(1) unsigned NOT NULL DEFAULT 1,
  `post_edit_time`      int(11) unsigned    NOT NULL DEFAULT 0,
  `post_edit_reason`    varchar(255)        NOT NULL DEFAULT '',
  `post_edit_user`      int(10) unsigned    NOT NULL DEFAULT 0,
  `post_edit_count`     smallint(4) unsigned NOT NULL DEFAULT 0,
  `post_edit_locked`    tinyint(1) unsigned NOT NULL DEFAULT 0,
  `post_visibility`     tinyint(3)          NOT NULL DEFAULT 0,
  `post_delete_time`    int(11) unsigned    NOT NULL DEFAULT 0,
  `post_delete_reason`  varchar(255)        NOT NULL DEFAULT '',
  `post_delete_user`    int(10) unsigned    NOT NULL DEFAULT 0,
  PRIMARY KEY (`post_id`),
  KEY `forum_id`      (`forum_id`),
  KEY `topic_id`      (`topic_id`),
  KEY `poster_ip`     (`poster_ip`),
  KEY `poster_id`     (`poster_id`),
  KEY `tid_post_time` (`topic_id`, `post_time`),
  KEY `post_username` (`post_username`),
  KEY `post_visibility` (`post_visibility`)
);
```

### Column Reference

| Column | Purpose |
|--------|---------|
| `post_id` | PK, auto-increment |
| `topic_id` | FK → phpbb_topics. Parent topic |
| `forum_id` | FK → phpbb_forums. **Denormalized** from topic for query perf |
| `poster_id` | FK → phpbb_users. Author (ANONYMOUS=1 for guests) |
| `icon_id` | FK → phpbb_icons. Post icon |
| `poster_ip` | IP address of poster (varchar(40) for IPv6) |
| `post_time` | Unix timestamp — post creation time |
| `post_reported` | Boolean: 1 if post has been reported |
| `enable_bbcode` | Boolean: allow BBCode rendering |
| `enable_smilies` | Boolean: allow smiley rendering |
| `enable_magic_url` | Boolean: auto-link URLs |
| `enable_sig` | Boolean: show poster's signature |
| `post_username` | Guest username (if poster_id=ANONYMOUS) |
| `post_subject` | Post subject (utf8 unicode_ci) |
| `post_text` | Post body (mediumtext, BBCode-encoded with uid tags) |
| `post_checksum` | MD5 hash of post_text (duplicate detection) |
| `post_attachment` | Boolean: 1 if post has attachments |
| `bbcode_bitfield` | Bitfield indicating which BBCodes are used |
| `bbcode_uid` | 8-char unique ID embedded in BBCode tags |
| `post_postcount` | Boolean: 1 if this post increments user's post count |
| `post_edit_time` | Unix timestamp — last edit time |
| `post_edit_reason` | Reason for last edit |
| `post_edit_user` | FK → phpbb_users — who last edited |
| `post_edit_count` | **Denormalized counter** — total edit count |
| `post_edit_locked` | Boolean: 1 if editing is locked (moderator lock) |
| `post_visibility` | Visibility state (ITEM_UNAPPROVED/ITEM_APPROVED/ITEM_DELETED/ITEM_REAPPROVE) |
| `post_delete_time` | Unix timestamp — soft-delete time |
| `post_delete_reason` | Reason for soft-delete |
| `post_delete_user` | FK → phpbb_users — who soft-deleted |

### Indexes

| Key Name | Columns | Purpose |
|----------|---------|---------|
| PRIMARY | `post_id` | PK lookup |
| `forum_id` | `forum_id` | Posts in forum (for forum prune, etc.) |
| `topic_id` | `topic_id` | Posts in topic |
| `poster_ip` | `poster_ip` | IP-based moderation lookups |
| `poster_id` | `poster_id` | Posts by user |
| `tid_post_time` | `topic_id, post_time` | **Critical**: paginated post listing within a topic |
| `post_username` | `post_username` | Guest post lookups |
| `post_visibility` | `post_visibility` | Filter by visibility |

---

## 3. phpbb_topics_posted

Tracks which users have posted in which topics. Used for "posted in" icon display.

### DDL

```sql
CREATE TABLE `phpbb_topics_posted` (
  `user_id`       int(10) unsigned    NOT NULL DEFAULT 0,
  `topic_id`      int(10) unsigned    NOT NULL DEFAULT 0,
  `topic_posted`  tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`, `topic_id`)
);
```

### Column Reference

| Column | Purpose |
|--------|---------|
| `user_id` | FK → phpbb_users |
| `topic_id` | FK → phpbb_topics |
| `topic_posted` | Boolean: always 1 when row exists (row existence is the signal) |

**Composite PK**: (user_id, topic_id) — one row per user-topic pair.

---

## 4. phpbb_topics_track

Tracks per-user read status for topics (database-driven unread tracking for logged-in users).

### DDL

```sql
CREATE TABLE `phpbb_topics_track` (
  `user_id`     int(10) unsigned      NOT NULL DEFAULT 0,
  `topic_id`    int(10) unsigned      NOT NULL DEFAULT 0,
  `forum_id`    mediumint(8) unsigned NOT NULL DEFAULT 0,
  `mark_time`   int(11) unsigned      NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`, `topic_id`),
  KEY `forum_id` (`forum_id`),
  KEY `topic_id` (`topic_id`)
);
```

### Column Reference

| Column | Purpose |
|--------|---------|
| `user_id` | FK → phpbb_users |
| `topic_id` | FK → phpbb_topics |
| `forum_id` | FK → phpbb_forums (**denormalized** for efficient per-forum mark-read) |
| `mark_time` | Unix timestamp — posts with post_time ≤ mark_time are considered "read" |

**Composite PK**: (user_id, topic_id). A topic is "unread" for a user if topic_last_post_time > mark_time (or no row exists and topic is newer than forum-level mark time).

---

## 5. phpbb_topics_watch

Topic subscription / watch list for email notifications.

### DDL

```sql
CREATE TABLE `phpbb_topics_watch` (
  `topic_id`      int(10) unsigned    NOT NULL DEFAULT 0,
  `user_id`       int(10) unsigned    NOT NULL DEFAULT 0,
  `notify_status` tinyint(1) unsigned NOT NULL DEFAULT 0,
  KEY `topic_id`    (`topic_id`),
  KEY `user_id`     (`user_id`),
  KEY `notify_stat` (`notify_status`)
);
```

**Note**: No PRIMARY KEY — uses secondary indexes only.

### Column Reference

| Column | Purpose |
|--------|---------|
| `topic_id` | FK → phpbb_topics |
| `user_id` | FK → phpbb_users |
| `notify_status` | NOTIFY_YES=0 (send notification), NOTIFY_NO=1 (notification already sent, waiting for user to visit) |

---

## 6. phpbb_drafts

Saved drafts for posts/topics that haven't been submitted yet.

### DDL

```sql
CREATE TABLE `phpbb_drafts` (
  `draft_id`       int(10) unsigned      NOT NULL AUTO_INCREMENT,
  `user_id`        int(10) unsigned      NOT NULL DEFAULT 0,
  `topic_id`       int(10) unsigned      NOT NULL DEFAULT 0,
  `forum_id`       mediumint(8) unsigned NOT NULL DEFAULT 0,
  `save_time`      int(11) unsigned      NOT NULL DEFAULT 0,
  `draft_subject`  varchar(255)          NOT NULL DEFAULT '',
  `draft_message`  mediumtext            NOT NULL,
  PRIMARY KEY (`draft_id`),
  KEY `save_time` (`save_time`)
);
```

### Column Reference

| Column | Purpose |
|--------|---------|
| `draft_id` | PK, auto-increment |
| `user_id` | FK → phpbb_users — draft owner |
| `topic_id` | FK → phpbb_topics (0 = new topic draft, >0 = reply draft) |
| `forum_id` | FK → phpbb_forums — target forum |
| `save_time` | Unix timestamp — when draft was saved |
| `draft_subject` | Draft subject line |
| `draft_message` | Draft body text (BBCode) |

---

## 7. phpbb_poll_options

Poll answer choices. Linked to topic via topic_id (polls live on topics).

### DDL

```sql
CREATE TABLE `phpbb_poll_options` (
  `poll_option_id`    tinyint(4)          NOT NULL DEFAULT 0,
  `topic_id`          int(10) unsigned    NOT NULL DEFAULT 0,
  `poll_option_text`  text                NOT NULL,
  `poll_option_total` mediumint(8) unsigned NOT NULL DEFAULT 0,
  KEY `poll_opt_id` (`poll_option_id`),
  KEY `topic_id`    (`topic_id`)
);
```

**Note**: No PRIMARY KEY. Options are identified by (topic_id, poll_option_id) pair.

### Column Reference

| Column | Purpose |
|--------|---------|
| `poll_option_id` | Option index within the poll (0-based) |
| `topic_id` | FK → phpbb_topics — parent topic |
| `poll_option_text` | Display text for the option |
| `poll_option_total` | **Denormalized counter** — total votes for this option |

---

## 8. phpbb_poll_votes

Individual vote records.

### DDL

```sql
CREATE TABLE `phpbb_poll_votes` (
  `topic_id`        int(10) unsigned    NOT NULL DEFAULT 0,
  `poll_option_id`  tinyint(4)          NOT NULL DEFAULT 0,
  `vote_user_id`    int(10) unsigned    NOT NULL DEFAULT 0,
  `vote_user_ip`    varchar(40)         NOT NULL DEFAULT '',
  KEY `topic_id`      (`topic_id`),
  KEY `vote_user_id`  (`vote_user_id`),
  KEY `vote_user_ip`  (`vote_user_ip`)
);
```

**Note**: No PRIMARY KEY. Multiple rows per user if poll_max_options > 1.

### Column Reference

| Column | Purpose |
|--------|---------|
| `topic_id` | FK → phpbb_topics |
| `poll_option_id` | FK → phpbb_poll_options.poll_option_id (within topic) |
| `vote_user_id` | FK → phpbb_users (0 for guest) |
| `vote_user_ip` | IP of voter (for guest vote tracking) |

---

## 9. phpbb_bookmarks

User bookmarks on topics.

### DDL

```sql
CREATE TABLE `phpbb_bookmarks` (
  `topic_id`  int(10) unsigned NOT NULL DEFAULT 0,
  `user_id`   int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`topic_id`, `user_id`)
);
```

### Column Reference

| Column | Purpose |
|--------|---------|
| `topic_id` | FK → phpbb_topics |
| `user_id` | FK → phpbb_users |

**Composite PK**: (topic_id, user_id). Simple junction table.

---

## Constants & Enums

Source: `src/phpbb/common/constants.php`

### Topic Types (`topic_type` column)

| Constant | Value | Meaning |
|----------|-------|---------|
| `POST_NORMAL` | 0 | Regular topic |
| `POST_STICKY` | 1 | Sticky — pinned to top of forum |
| `POST_ANNOUNCE` | 2 | Announcement — pinned, shown in forum only |
| `POST_GLOBAL` | 3 | Global announcement — shown in ALL forums |

### Topic/Post Status (`topic_status` column)

| Constant | Value | Meaning |
|----------|-------|---------|
| `ITEM_UNLOCKED` | 0 | Open for posting |
| `ITEM_LOCKED` | 1 | Locked — no new replies |
| `ITEM_MOVED` | 2 | Shadow topic (moved elsewhere; `topic_moved_id` has real topic_id) |

### Visibility States (`topic_visibility`, `post_visibility` columns)

| Constant | Value | Meaning |
|----------|-------|---------|
| `ITEM_UNAPPROVED` | 0 | Pending moderation — not yet approved |
| `ITEM_APPROVED` | 1 | Visible — approved, not soft-deleted |
| `ITEM_DELETED` | 2 | Soft-deleted — hidden from normal users |
| `ITEM_REAPPROVE` | 3 | Edited post needs re-approval |

### Notification Status (`notify_status` in topics_watch)

| Constant | Value | Meaning |
|----------|-------|---------|
| `NOTIFY_YES` | 0 | Will send notification on next new post |
| `NOTIFY_NO` | 1 | Already notified, won't re-notify until user visits topic |

### Read Tracking (`topic_posted` tracking)

| Constant | Value | Meaning |
|----------|-------|---------|
| `TRACK_NORMAL` | 0 | Normal tracking |
| `TRACK_POSTED` | 1 | Track topics user posted in |

### Table Constants

| Constant | Table |
|----------|-------|
| `TOPICS_TABLE` | `phpbb_topics` |
| `TOPICS_POSTED_TABLE` | `phpbb_topics_posted` |
| `TOPICS_TRACK_TABLE` | `phpbb_topics_track` |
| `TOPICS_WATCH_TABLE` | `phpbb_topics_watch` |
| `POSTS_TABLE` | `phpbb_posts` |
| `POLL_OPTIONS_TABLE` | `phpbb_poll_options` |
| `POLL_VOTES_TABLE` | `phpbb_poll_votes` |
| `BOOKMARKS_TABLE` | `phpbb_bookmarks` |
| `DRAFTS_TABLE` | `phpbb_drafts` |

---

## Denormalized Counters Summary

These columns are maintained by phpBB code and must be synced manually (via `sync()` functions):

| Table | Column | What it counts |
|-------|--------|----------------|
| `phpbb_topics` | `topic_views` | Number of times topic was viewed |
| `phpbb_topics` | `topic_posts_approved` | Count of approved (visible) posts |
| `phpbb_topics` | `topic_posts_unapproved` | Count of unapproved posts |
| `phpbb_topics` | `topic_posts_softdeleted` | Count of soft-deleted posts |
| `phpbb_posts` | `post_edit_count` | Number of times post was edited |
| `phpbb_poll_options` | `poll_option_total` | Number of votes for this option |

---

## Timestamp Columns Summary

All timestamps are **Unix epoch integers** (int(11) unsigned).

| Table | Column | When updated |
|-------|--------|--------------|
| `phpbb_topics` | `topic_time` | Topic creation |
| `phpbb_topics` | `topic_time_limit` | Sticky/announce expiry (0 = never) |
| `phpbb_topics` | `topic_last_post_time` | When last post added (denormalized) |
| `phpbb_topics` | `topic_last_view_time` | When topic was last viewed by anyone |
| `phpbb_topics` | `topic_delete_time` | When topic was soft-deleted |
| `phpbb_topics` | `poll_start` | When poll was created |
| `phpbb_topics` | `poll_last_vote` | When last vote was cast |
| `phpbb_posts` | `post_time` | Post creation |
| `phpbb_posts` | `post_edit_time` | Last edit time |
| `phpbb_posts` | `post_delete_time` | When post was soft-deleted |
| `phpbb_topics_track` | `mark_time` | When user marked topic as read |
| `phpbb_drafts` | `save_time` | When draft was saved |

---

## Relationships (Logical FKs)

```
phpbb_forums.forum_id  ←──  phpbb_topics.forum_id
phpbb_users.user_id    ←──  phpbb_topics.topic_poster
phpbb_posts.post_id    ←──  phpbb_topics.topic_first_post_id
phpbb_posts.post_id    ←──  phpbb_topics.topic_last_post_id
phpbb_users.user_id    ←──  phpbb_topics.topic_last_poster_id
phpbb_icons.icon_id    ←──  phpbb_topics.icon_id

phpbb_topics.topic_id  ←──  phpbb_posts.topic_id
phpbb_forums.forum_id  ←──  phpbb_posts.forum_id  (denormalized)
phpbb_users.user_id    ←──  phpbb_posts.poster_id
phpbb_icons.icon_id    ←──  phpbb_posts.icon_id

phpbb_topics.topic_id  ←──  phpbb_topics_posted.topic_id
phpbb_users.user_id    ←──  phpbb_topics_posted.user_id

phpbb_topics.topic_id  ←──  phpbb_topics_track.topic_id
phpbb_users.user_id    ←──  phpbb_topics_track.user_id
phpbb_forums.forum_id  ←──  phpbb_topics_track.forum_id  (denormalized)

phpbb_topics.topic_id  ←──  phpbb_topics_watch.topic_id
phpbb_users.user_id    ←──  phpbb_topics_watch.user_id

phpbb_topics.topic_id  ←──  phpbb_poll_options.topic_id
phpbb_topics.topic_id  ←──  phpbb_poll_votes.topic_id
phpbb_users.user_id    ←──  phpbb_poll_votes.vote_user_id

phpbb_topics.topic_id  ←──  phpbb_bookmarks.topic_id
phpbb_users.user_id    ←──  phpbb_bookmarks.user_id

phpbb_topics.topic_id  ←──  phpbb_drafts.topic_id
phpbb_users.user_id    ←──  phpbb_drafts.user_id
phpbb_forums.forum_id  ←──  phpbb_drafts.forum_id
```

**Note**: phpBB does NOT use actual FOREIGN KEY constraints — all relationships are enforced in application code. The database engine is InnoDB but relies on application-level integrity.
