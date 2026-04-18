# Forum Database Schema — Complete Findings

**Source**: `phpbb_dump.sql` (CREATE TABLE statements, lines 1695–1850)
**Additional sources**: `src/phpbb/common/constants.php`, `src/phpbb/forums/tree/nestedset_forum.php`, `src/phpbb/common/acp/acp_forums.php`
**Confidence**: High (100%) — direct SQL DDL extraction

---

## 1. Table Schemas

### 1.1 `phpbb_forums` — Main forum table

**Source**: `phpbb_dump.sql:1695-1750`

```sql
CREATE TABLE `phpbb_forums` (
  `forum_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `parent_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `left_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `right_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_parents` mediumtext NOT NULL,
  `forum_name` varchar(255) NOT NULL DEFAULT '',
  `forum_desc` text NOT NULL,
  `forum_desc_bitfield` varchar(255) NOT NULL DEFAULT '',
  `forum_desc_options` int(11) unsigned NOT NULL DEFAULT 7,
  `forum_desc_uid` varchar(8) NOT NULL DEFAULT '',
  `forum_link` varchar(255) NOT NULL DEFAULT '',
  `forum_password` varchar(255) NOT NULL DEFAULT '',
  `forum_style` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_image` varchar(255) NOT NULL DEFAULT '',
  `forum_rules` text NOT NULL,
  `forum_rules_link` varchar(255) NOT NULL DEFAULT '',
  `forum_rules_bitfield` varchar(255) NOT NULL DEFAULT '',
  `forum_rules_options` int(11) unsigned NOT NULL DEFAULT 7,
  `forum_rules_uid` varchar(8) NOT NULL DEFAULT '',
  `forum_topics_per_page` smallint(4) unsigned NOT NULL DEFAULT 0,
  `forum_type` tinyint(4) NOT NULL DEFAULT 0,
  `forum_status` tinyint(4) NOT NULL DEFAULT 0,
  `forum_last_post_id` int(10) unsigned NOT NULL DEFAULT 0,
  `forum_last_poster_id` int(10) unsigned NOT NULL DEFAULT 0,
  `forum_last_post_subject` varchar(255) NOT NULL DEFAULT '',
  `forum_last_post_time` int(11) unsigned NOT NULL DEFAULT 0,
  `forum_last_poster_name` varchar(255) NOT NULL DEFAULT '',
  `forum_last_poster_colour` varchar(6) NOT NULL DEFAULT '',
  `forum_flags` tinyint(4) NOT NULL DEFAULT 32,
  `display_on_index` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_indexing` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_icons` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_prune` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `prune_next` int(11) unsigned NOT NULL DEFAULT 0,
  `prune_days` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `prune_viewed` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `prune_freq` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `display_subforum_list` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `display_subforum_limit` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `forum_options` int(20) unsigned NOT NULL DEFAULT 0,
  `enable_shadow_prune` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `prune_shadow_days` mediumint(8) unsigned NOT NULL DEFAULT 7,
  `prune_shadow_freq` mediumint(8) unsigned NOT NULL DEFAULT 1,
  `prune_shadow_next` int(11) NOT NULL DEFAULT 0,
  `forum_posts_approved` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_posts_unapproved` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_posts_softdeleted` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_topics_approved` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_topics_unapproved` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `forum_topics_softdeleted` mediumint(8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`forum_id`),
  KEY `left_right_id` (`left_id`,`right_id`),
  KEY `forum_lastpost_id` (`forum_last_post_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

**Total columns**: 50

### 1.2 `phpbb_forums_access` — Password-protected forum access tracking

**Source**: `phpbb_dump.sql:1773-1778`

```sql
CREATE TABLE `phpbb_forums_access` (
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `session_id` char(32) NOT NULL DEFAULT '',
  PRIMARY KEY (`forum_id`,`user_id`,`session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

**Purpose**: Tracks which users have entered the correct password for password-protected forums during a given session.

### 1.3 `phpbb_forums_track` — Read tracking per forum

**Source**: `phpbb_dump.sql:1797-1802`

```sql
CREATE TABLE `phpbb_forums_track` (
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `mark_time` int(11) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`,`forum_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

**Purpose**: Stores when a user last marked a forum as read. Used for "new posts" indicators.

### 1.4 `phpbb_forums_watch` — Forum subscriptions

**Source**: `phpbb_dump.sql:1821-1828`

```sql
CREATE TABLE `phpbb_forums_watch` (
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `notify_status` tinyint(1) unsigned NOT NULL DEFAULT 0,
  KEY `forum_id` (`forum_id`),
  KEY `user_id` (`user_id`),
  KEY `notify_stat` (`notify_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

**Purpose**: Tracks which users are subscribed to (watching) a forum for email notifications. `notify_status` indicates whether notification has already been sent for the current batch.

**Note**: No PRIMARY KEY — only indexes. Allows multiple rows per user/forum combination (legacy design).

---

## 2. Column Groups (phpbb_forums)

### 2.1 Identity

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `forum_id` | mediumint(8) unsigned | AUTO_INCREMENT | Primary key |
| `forum_name` | varchar(255) | '' | Display name |
| `forum_desc` | text | — | BBCode-formatted description |
| `forum_desc_bitfield` | varchar(255) | '' | BBCode parsing bitfield |
| `forum_desc_options` | int(11) unsigned | 7 | BBCode options bitmask |
| `forum_desc_uid` | varchar(8) | '' | BBCode unique ID for this text |

### 2.2 Hierarchy (Nested Set)

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `parent_id` | mediumint(8) unsigned | 0 | Direct parent forum ID (0 = root) |
| `left_id` | mediumint(8) unsigned | 0 | Nested set left boundary |
| `right_id` | mediumint(8) unsigned | 0 | Nested set right boundary |
| `forum_parents` | mediumtext | — | Serialized cache of parent chain (forum_id → [name, type]) |
| `forum_type` | tinyint(4) | 0 | Node type: FORUM_CAT(0), FORUM_POST(1), FORUM_LINK(2) |

**Nested set invariants**:
- A node's children have `left_id` between parent's `left_id` and `right_id`
- Leaf nodes have `right_id = left_id + 1`
- `(right_id - left_id - 1) / 2` = number of descendants

**Evidence from `nestedset_forum.php:28-43`**: The nested set maps `item_id` → `forum_id` and `item_parents` → `forum_parents`. Columns `forum_id`, `forum_name`, `forum_type` are cached in the parent chain.

### 2.3 Counters (Post/Topic Statistics)

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `forum_posts_approved` | mediumint(8) unsigned | 0 | Count of approved posts |
| `forum_posts_unapproved` | mediumint(8) unsigned | 0 | Count of unapproved posts |
| `forum_posts_softdeleted` | mediumint(8) unsigned | 0 | Count of soft-deleted posts |
| `forum_topics_approved` | mediumint(8) unsigned | 0 | Count of approved topics |
| `forum_topics_unapproved` | mediumint(8) unsigned | 0 | Count of unapproved topics |
| `forum_topics_softdeleted` | mediumint(8) unsigned | 0 | Count of soft-deleted topics |

**Note**: Legacy columns `forum_posts` and `forum_topics` / `forum_topics_real` are NOT present in this schema. phpBB 3.1+ replaced them with the approved/unapproved/softdeleted triplets.

### 2.4 Settings & Flags

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `forum_status` | tinyint(4) | 0 | ITEM_UNLOCKED(0) or ITEM_LOCKED(1) |
| `forum_flags` | tinyint(4) | 32 | Bitmask of forum behavior flags |
| `forum_options` | int(20) unsigned | 0 | Additional options bitmask |
| `forum_password` | varchar(255) | '' | Hashed password for protected access |
| `forum_style` | mediumint(8) unsigned | 0 | Override style ID (0 = use board default) |
| `forum_topics_per_page` | smallint(4) unsigned | 0 | Topics per page override (0 = use board default) |
| `display_on_index` | tinyint(1) unsigned | 1 | Show on board index |
| `enable_indexing` | tinyint(1) unsigned | 1 | Enable full-text search indexing |
| `enable_icons` | tinyint(1) unsigned | 1 | Enable topic icons |
| `display_subforum_list` | tinyint(1) unsigned | 1 | Show subforums list |
| `display_subforum_limit` | tinyint(1) unsigned | 0 | Limit subforum display |

**forum_flags bitmask** (from `constants.php:97-103`):

| Constant | Value | Meaning |
|----------|-------|---------|
| `FORUM_FLAG_LINK_TRACK` | 1 | Track link clicks |
| `FORUM_FLAG_PRUNE_POLL` | 2 | Prune old polls |
| `FORUM_FLAG_PRUNE_ANNOUNCE` | 4 | Prune announcements |
| `FORUM_FLAG_PRUNE_STICKY` | 8 | Prune sticky topics |
| `FORUM_FLAG_ACTIVE_TOPICS` | 16 | Show active topics |
| `FORUM_FLAG_POST_REVIEW` | 32 | Enable post review |
| `FORUM_FLAG_QUICK_REPLY` | 64 | Enable quick reply |

**Default `forum_flags` = 32** means POST_REVIEW is enabled by default.

### 2.5 Last Post Metadata (Denormalized)

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `forum_last_post_id` | int(10) unsigned | 0 | ID of last post in forum |
| `forum_last_poster_id` | int(10) unsigned | 0 | User ID of last poster |
| `forum_last_post_subject` | varchar(255) | '' | Subject of last post |
| `forum_last_post_time` | int(11) unsigned | 0 | Unix timestamp of last post |
| `forum_last_poster_name` | varchar(255) | '' | Username of last poster |
| `forum_last_poster_colour` | varchar(6) | '' | User colour hex code of last poster |

**Note**: All denormalized from `phpbb_posts` and `phpbb_users` tables. Updated on each new post for display performance.

### 2.6 Pruning Settings

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `enable_prune` | tinyint(1) unsigned | 0 | Enable auto-pruning |
| `prune_next` | int(11) unsigned | 0 | Unix timestamp for next prune run |
| `prune_days` | mediumint(8) unsigned | 0 | Prune topics older than N days |
| `prune_viewed` | mediumint(8) unsigned | 0 | Prune unviewed topics after N days |
| `prune_freq` | mediumint(8) unsigned | 0 | Prune frequency in days |
| `enable_shadow_prune` | tinyint(1) unsigned | 0 | Enable shadow topic pruning |
| `prune_shadow_days` | mediumint(8) unsigned | 7 | Prune shadow topics after N days |
| `prune_shadow_freq` | mediumint(8) unsigned | 1 | Shadow prune frequency in days |
| `prune_shadow_next` | int(11) | 0 | Next shadow prune run timestamp |

### 2.7 Display / Rules

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `forum_image` | varchar(255) | '' | Path to forum icon image |
| `forum_rules` | text | — | BBCode-formatted forum rules text |
| `forum_rules_link` | varchar(255) | '' | External URL for rules page |
| `forum_rules_bitfield` | varchar(255) | '' | BBCode bitfield for rules |
| `forum_rules_options` | int(11) unsigned | 7 | BBCode options for rules |
| `forum_rules_uid` | varchar(8) | '' | BBCode UID for rules |

### 2.8 Link-type Specific

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `forum_link` | varchar(255) | '' | External URL for FORUM_LINK type forums |

---

## 3. Relationships (Foreign Keys)

phpBB uses **no declared foreign key constraints** (all InnoDB tables, but no `FOREIGN KEY` clauses). Relationships are enforced in application code.

### 3.1 Self-referential (Hierarchy)

| Relationship | Column | References | Notes |
|-------------|--------|------------|-------|
| Parent-child | `phpbb_forums.parent_id` | `phpbb_forums.forum_id` | 0 = root-level node |

### 3.2 Forum satellite tables → phpbb_forums

| Table | Column | References | Cardinality |
|-------|--------|------------|-------------|
| `phpbb_forums_access` | `forum_id` | `phpbb_forums.forum_id` | many-to-one |
| `phpbb_forums_track` | `forum_id` | `phpbb_forums.forum_id` | many-to-one |
| `phpbb_forums_watch` | `forum_id` | `phpbb_forums.forum_id` | many-to-one |

### 3.3 Content tables → phpbb_forums

| Table | Column | References | Notes |
|-------|--------|------------|-------|
| `phpbb_topics` | `forum_id` | `phpbb_forums.forum_id` | Topics belong to a forum |
| `phpbb_posts` | `forum_id` | `phpbb_forums.forum_id` | Denormalized from topic, for query performance |

### 3.4 ACL tables → phpbb_forums

| Table | Column | References | Notes |
|-------|--------|------------|-------|
| `phpbb_acl_groups` | `forum_id` | `phpbb_forums.forum_id` | Per-forum group permissions (0 = global) |
| `phpbb_acl_users` | `forum_id` | `phpbb_forums.forum_id` | Per-forum user permissions (0 = global) |

### 3.5 Denormalized references within phpbb_forums

| Column | References Table | References Column |
|--------|-----------------|-------------------|
| `forum_last_post_id` | `phpbb_posts` | `post_id` |
| `forum_last_poster_id` | `phpbb_users` | `user_id` |
| `forum_style` | `phpbb_styles` | `style_id` |

---

## 4. Indexes

### 4.1 phpbb_forums

| Index Name | Columns | Type | Purpose |
|-----------|---------|------|---------|
| PRIMARY | `forum_id` | PRIMARY | PK lookup |
| `left_right_id` | `left_id, right_id` | KEY | Nested set tree traversal queries |
| `forum_lastpost_id` | `forum_last_post_id` | KEY | Last post lookups |

### 4.2 phpbb_forums_access

| Index Name | Columns | Type | Purpose |
|-----------|---------|------|---------|
| PRIMARY | `forum_id, user_id, session_id` | PRIMARY | Composite: unique per forum+user+session |

### 4.3 phpbb_forums_track

| Index Name | Columns | Type | Purpose |
|-----------|---------|------|---------|
| PRIMARY | `user_id, forum_id` | PRIMARY | Composite: one tracking entry per user+forum |

### 4.4 phpbb_forums_watch

| Index Name | Columns | Type | Purpose |
|-----------|---------|------|---------|
| `forum_id` | `forum_id` | KEY | Find watchers of a forum |
| `user_id` | `user_id` | KEY | Find forums a user watches |
| `notify_stat` | `notify_status` | KEY | Find pending notifications |

**Note**: `phpbb_forums_watch` has NO primary key — only three separate indexes.

---

## 5. Sample Data

**Source**: `phpbb_dump.sql:1758-1762`

### Row 1 (forum_id=1): Category at root level
```
forum_id=1, parent_id=0, left_id=1, right_id=4
forum_name='Your first category'
forum_type=0 (FORUM_CAT)
forum_status=0 (UNLOCKED)
forum_flags=32 (POST_REVIEW)
display_on_index=1
forum_last_post_id=1, forum_last_poster_id=2
forum_last_post_subject='', forum_last_poster_name='admin'
forum_last_poster_colour='AA0000'
forum_last_post_time=1776276588
forum_posts_approved=0, forum_topics_approved=0
All prune settings=0 (disabled)
```

### Row 2 (forum_id=2): Posting forum under category
```
forum_id=2, parent_id=1, left_id=2, right_id=3
forum_name='Your first forum'
forum_desc='Description of your first forum.'
forum_type=1 (FORUM_POST)
forum_status=0 (UNLOCKED)
forum_flags=48 (POST_REVIEW=32 + ACTIVE_TOPICS=16)
display_on_index=1
forum_last_post_id=1, forum_last_poster_id=2
forum_last_post_subject='Welcome to phpBB3', forum_last_poster_name='admin'
forum_last_poster_colour='AA0000'
forum_last_post_time=1776276588
enable_prune=0, prune_days=7, prune_viewed=7, prune_freq=1
forum_posts_approved=1, forum_topics_approved=1
```

### Nested Set Visualization from Sample Data

```
Root (id=0)
├── Category (id=1, L=1, R=4, type=CAT)
│   └── Forum (id=2, L=2, R=3, type=POST)
├── Category (id=3, L=1, R=4, type=CAT)    ← duplicate seed data
│   └── Forum (id=4, L=2, R=3, type=POST)  ← duplicate seed data
```

**Note**: Rows 3 and 4 are exact duplicates of 1 and 2 (left/right IDs overlap). This appears to be a seed data artifact — in production, left/right IDs would be globally unique within the tree.

---

## 6. forum_type Analysis

**Source**: `constants.php:84-86`, `acp_forums.php:555`

### Type Constants

| Constant | Value | Label in UI |
|----------|-------|-------------|
| `FORUM_CAT` | 0 | TYPE_CAT — Category |
| `FORUM_POST` | 1 | TYPE_FORUM — Forum (postable) |
| `FORUM_LINK` | 2 | TYPE_LINK — Link |

### Type-Specific Column Usage

| Column Group | FORUM_CAT (0) | FORUM_POST (1) | FORUM_LINK (2) |
|-------------|---------------|-----------------|-----------------|
| **Hierarchy** (parent_id, left/right_id) | ✅ Used | ✅ Used | ✅ Used |
| **forum_link** | ❌ Empty | ❌ Empty | ✅ URL stored |
| **forum_password** | ❌ Not applicable | ✅ Optional | ❌ Not applicable |
| **Counters** (posts/topics) | ❌ Always 0 | ✅ Maintained | ❌ Always 0 |
| **Last post metadata** | ❌ Empty/inherited | ✅ Maintained | ❌ Empty |
| **Pruning** | ❌ Not applicable | ✅ Configurable | ❌ Not applicable |
| **forum_status** (lock) | ❌ Forced UNLOCKED | ✅ Lockable | ❌ Forced UNLOCKED |
| **forum_flags** | ACTIVE_TOPICS used | All flags applicable | LINK_TRACK used |
| **Display settings** | ✅ Used | ✅ Used | ✅ display_on_index used |

**Evidence from `acp_forums.php:180-191`**:
- FORUM_LINK: `display_on_index` set separately via `link_display_on_index`
- FORUM_LINK and FORUM_CAT: `forum_status` forced to `ITEM_UNLOCKED`
- FORUM_POST: `show_active` uses `display_recent`; FORUM_CAT uses `display_active`

### Key Behavioral Differences

1. **Categories** (type=0): Containers only. Cannot receive posts. Can show "active topics" from children.
2. **Forums** (type=1): Primary postable nodes. Full counter/pruning/password support.
3. **Links** (type=2): Redirects to external URL. `forum_link` holds the URL. `FORUM_FLAG_LINK_TRACK` enables click counting. No content storage.

---

## 7. Schema Summary

| Table | Columns | Indexes | Has PK | Purpose |
|-------|---------|---------|--------|---------|
| `phpbb_forums` | 50 | 3 | ✅ `forum_id` | Main forum definition + hierarchy |
| `phpbb_forums_access` | 3 | 1 (PK only) | ✅ composite | Password access grants |
| `phpbb_forums_track` | 3 | 1 (PK only) | ✅ composite | Read tracking timestamps |
| `phpbb_forums_watch` | 3 | 3 (no PK) | ❌ | Forum notification subscriptions |

**Engine**: All tables use InnoDB.
**Charset**: utf8mb3 with utf8mb3_bin collation.
**No foreign key constraints declared** — all referential integrity is in application code.
