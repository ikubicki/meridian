# PM Database Schema and Configuration

**Source**: `phpbb_dump.sql` (DDL + config data)
**Confidence**: High (100%) — direct DDL and INSERT statements from database dump

---

## 1. Core PM Tables

### 1.1 `phpbb_privmsgs` — Message Storage

**Source**: `phpbb_dump.sql:2754-2783`

```sql
CREATE TABLE `phpbb_privmsgs` (
  `msg_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `root_level` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `author_id` int(10) unsigned NOT NULL DEFAULT 0,
  `icon_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `author_ip` varchar(40) NOT NULL DEFAULT '',
  `message_time` int(11) unsigned NOT NULL DEFAULT 0,
  `enable_bbcode` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_smilies` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_magic_url` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `enable_sig` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `message_subject` varchar(255) NOT NULL DEFAULT '',
  `message_text` mediumtext NOT NULL,
  `message_edit_reason` varchar(255) NOT NULL DEFAULT '',
  `message_edit_user` int(10) unsigned NOT NULL DEFAULT 0,
  `message_attachment` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `bbcode_bitfield` varchar(255) NOT NULL DEFAULT '',
  `bbcode_uid` varchar(8) NOT NULL DEFAULT '',
  `message_edit_time` int(11) unsigned NOT NULL DEFAULT 0,
  `message_edit_count` smallint(4) unsigned NOT NULL DEFAULT 0,
  `to_address` text NOT NULL,
  `bcc_address` text NOT NULL,
  `message_reported` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`msg_id`),
  KEY `author_ip` (`author_ip`),
  KEY `message_time` (`message_time`),
  KEY `author_id` (`author_id`),
  KEY `root_level` (`root_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

**Column Details**:

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `msg_id` | int(10) unsigned, AUTO_INCREMENT | — | Primary key, unique message ID |
| `root_level` | mediumint(8) unsigned | 0 | Root message ID for conversation threading (0 = is root) |
| `author_id` | int(10) unsigned | 0 | FK → `phpbb_users.user_id` |
| `icon_id` | mediumint(8) unsigned | 0 | Message icon ID |
| `author_ip` | varchar(40) | '' | IP address of sender |
| `message_time` | int(11) unsigned | 0 | Unix timestamp when sent |
| `enable_bbcode` | tinyint(1) unsigned | 1 | BBCode enabled flag |
| `enable_smilies` | tinyint(1) unsigned | 1 | Smilies enabled flag |
| `enable_magic_url` | tinyint(1) unsigned | 1 | Auto-link URLs flag |
| `enable_sig` | tinyint(1) unsigned | 1 | Show signature flag |
| `message_subject` | varchar(255) | '' | Message subject line |
| `message_text` | mediumtext | — | Message body (BBCode-encoded) |
| `message_edit_reason` | varchar(255) | '' | Reason for last edit |
| `message_edit_user` | int(10) unsigned | 0 | User ID who last edited |
| `message_attachment` | tinyint(1) unsigned | 0 | Has attachments flag (boolean) |
| `bbcode_bitfield` | varchar(255) | '' | BBCode bitfield for rendering |
| `bbcode_uid` | varchar(8) | '' | BBCode unique ID for parsing |
| `message_edit_time` | int(11) unsigned | 0 | Unix timestamp of last edit |
| `message_edit_count` | smallint(4) unsigned | 0 | Number of times edited |
| `to_address` | text | — | Serialized recipient addresses (format: `u_<user_id>`) |
| `bcc_address` | text | — | Serialized BCC addresses (format: `u_<user_id>`) |
| `message_reported` | tinyint(1) unsigned | 0 | Has been reported flag |

**Indexes**:

| Index Name | Column(s) | Purpose |
|------------|-----------|---------|
| PRIMARY | `msg_id` | Unique message lookup |
| `author_ip` | `author_ip` | Admin lookups by IP |
| `message_time` | `message_time` | Chronological ordering |
| `author_id` | `author_id` | Lookup by sender |
| `root_level` | `root_level` | Thread/conversation grouping |

**Key Design Notes**:
- **One row per message** — recipient tracking is in `phpbb_privmsgs_to`
- `to_address` / `bcc_address` are **denormalized** serialized strings (e.g., `u_2:u_5:`) — the normalized version is in `phpbb_privmsgs_to`
- `root_level` enables conversation threading: replies share the root message's `msg_id`
- `message_attachment` is a boolean cache flag — actual attachment records in `phpbb_attachments`

---

### 1.2 `phpbb_privmsgs_to` — Recipient Tracking

**Source**: `phpbb_dump.sql:2858-2878`

```sql
CREATE TABLE `phpbb_privmsgs_to` (
  `msg_id` int(10) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `author_id` int(10) unsigned NOT NULL DEFAULT 0,
  `pm_deleted` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `pm_new` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `pm_unread` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `pm_replied` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `pm_marked` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `pm_forwarded` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `folder_id` int(11) NOT NULL DEFAULT 0,
  KEY `msg_id` (`msg_id`),
  KEY `author_id` (`author_id`),
  KEY `usr_flder_id` (`user_id`, `folder_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

**Column Details**:

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `msg_id` | int(10) unsigned | 0 | FK → `phpbb_privmsgs.msg_id` |
| `user_id` | int(10) unsigned | 0 | FK → `phpbb_users.user_id` (recipient or author) |
| `author_id` | int(10) unsigned | 0 | FK → `phpbb_users.user_id` (message author) |
| `pm_deleted` | tinyint(1) unsigned | 0 | Soft-delete flag |
| `pm_new` | tinyint(1) unsigned | 1 | New message flag (not yet seen) |
| `pm_unread` | tinyint(1) unsigned | 1 | Unread flag (seen but not opened) |
| `pm_replied` | tinyint(1) unsigned | 0 | User has replied flag |
| `pm_marked` | tinyint(1) unsigned | 0 | Starred/marked flag |
| `pm_forwarded` | tinyint(1) unsigned | 0 | User has forwarded flag |
| `folder_id` | int(11) | 0 | Which folder the PM is in (see constants below) |

**Indexes**:

| Index Name | Column(s) | Purpose |
|------------|-----------|---------|
| `msg_id` | `msg_id` | Lookup all recipients per message |
| `author_id` | `author_id` | Lookup sent items by author |
| `usr_flder_id` | `user_id`, `folder_id` | **Primary query path**: user's folder listing |

**Key Design Notes**:
- **NO PRIMARY KEY** — this is a junction table with non-unique composite
- Each recipient gets their **own row** for a given message → independent read/delete/folder tracking
- The **author also gets a row** (for sent box / outbox tracking)
- `folder_id` maps to constants (negative = system folders, positive = custom folders)
- `pm_new` vs `pm_unread`: `pm_new` is for "new message" notification counter, `pm_unread` tracks whether user opened it

---

### 1.3 `phpbb_privmsgs_folder` — Custom Folders

**Source**: `phpbb_dump.sql:2801-2813`

```sql
CREATE TABLE `phpbb_privmsgs_folder` (
  `folder_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `folder_name` varchar(255) NOT NULL DEFAULT '',
  `pm_count` mediumint(8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`folder_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

**Column Details**:

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `folder_id` | mediumint(8) unsigned, AUTO_INCREMENT | — | Primary key, referenced by `privmsgs_to.folder_id` |
| `user_id` | int(10) unsigned | 0 | FK → `phpbb_users.user_id` (folder owner) |
| `folder_name` | varchar(255) | '' | User-defined folder name |
| `pm_count` | mediumint(8) unsigned | 0 | Cached count of messages in folder |

**Indexes**:

| Index Name | Column(s) | Purpose |
|------------|-----------|---------|
| PRIMARY | `folder_id` | Unique folder lookup |
| `user_id` | `user_id` | List folders for a user |

**Key Design Notes**:
- Only stores **custom** user-created folders (positive IDs)
- System folders (Inbox, Outbox, Sentbox) are **virtual** — represented by negative `folder_id` constants
- `pm_count` is a **denormalized cache** updated when messages move in/out

---

### 1.4 `phpbb_privmsgs_rules` — Message Filtering Rules

**Source**: `phpbb_dump.sql:2827-2845`

```sql
CREATE TABLE `phpbb_privmsgs_rules` (
  `rule_id` mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `rule_check` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `rule_connection` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `rule_string` varchar(255) NOT NULL DEFAULT '',
  `rule_user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `rule_group_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `rule_action` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `rule_folder_id` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`rule_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

**Column Details**:

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `rule_id` | mediumint(8) unsigned, AUTO_INCREMENT | — | Primary key |
| `user_id` | int(10) unsigned | 0 | FK → `phpbb_users.user_id` (rule owner) |
| `rule_check` | mediumint(8) unsigned | 0 | What field to check (subject, sender, group, etc.) |
| `rule_connection` | mediumint(8) unsigned | 0 | Match condition (is, is not, contains, etc.) |
| `rule_string` | varchar(255) | '' | String to match against |
| `rule_user_id` | int(10) unsigned | 0 | Specific user ID to match (if rule is user-based) |
| `rule_group_id` | mediumint(8) unsigned | 0 | Specific group ID to match (if rule is group-based) |
| `rule_action` | mediumint(8) unsigned | 0 | Action to take (move to folder, delete, etc.) |
| `rule_folder_id` | int(11) | 0 | Target folder for move action |

---

## 2. Folder ID Constants

**Source**: `src/phpbb/common/constants.php:145-155`

```php
// Private messaging - Do NOT change these values
define('PRIVMSGS_HOLD_BOX', -4);   // Messages held (when inbox full + hold action)
define('PRIVMSGS_NO_BOX', -3);     // No box assigned
define('PRIVMSGS_OUTBOX', -2);     // Outbox (sent but not yet read by recipient)
define('PRIVMSGS_SENTBOX', -1);    // Sent box (sent and read by at least one recipient)
define('PRIVMSGS_INBOX', 0);       // Inbox

// Full Folder Actions
define('FULL_FOLDER_NONE', -3);    // No action when folder is full
define('FULL_FOLDER_DELETE', -2);  // Delete oldest messages
define('FULL_FOLDER_HOLD', -1);    // Hold new messages in hold box
// Positive values = move to specific folder_id
```

**Folder ID mapping in `privmsgs_to.folder_id`**:

| folder_id | Constant | Meaning |
|-----------|----------|---------|
| -4 | `PRIVMSGS_HOLD_BOX` | Message held because inbox was full |
| -3 | `PRIVMSGS_NO_BOX` | Not assigned to any folder |
| -2 | `PRIVMSGS_OUTBOX` | Still in outbox (recipient hasn't read yet) |
| -1 | `PRIVMSGS_SENTBOX` | Moved to sentbox (recipient has read) |
| 0 | `PRIVMSGS_INBOX` | User's inbox |
| >0 | Custom folder | Maps to `phpbb_privmsgs_folder.folder_id` |

---

## 3. Related Tables

### 3.1 `phpbb_users` — PM-Related Columns

**Source**: `phpbb_dump.sql:3963-4045`

| Column | Type | Default | Purpose |
|--------|------|---------|---------|
| `user_new_privmsg` | int(4) | 0 | **Counter**: number of new (unnotified) PMs |
| `user_unread_privmsg` | int(4) | 0 | **Counter**: number of unread PMs |
| `user_last_privmsg` | int(11) unsigned | 0 | Unix timestamp of last PM received (flood control) |
| `user_message_rules` | tinyint(1) unsigned | 0 | Whether user has PM filter rules enabled |
| `user_full_folder` | int(11) | -3 | Action when inbox full (maps to FULL_FOLDER_* constants or folder_id) |
| `user_allow_pm` | tinyint(1) unsigned | 1 | Whether user allows PMs at all |
| `user_notify_pm` | tinyint(1) unsigned | 1 | Whether user wants email notification on new PM |

**`user_full_folder` values**:
- `-3` (`FULL_FOLDER_NONE`): No action, reject new messages
- `-2` (`FULL_FOLDER_DELETE`): Delete oldest messages automatically
- `-1` (`FULL_FOLDER_HOLD`): Hold new messages in hold box
- `>0`: Move to specific custom folder ID

---

### 3.2 `phpbb_zebra` — Friend/Foe List

**Source**: `phpbb_dump.sql:4165-4174`

```sql
CREATE TABLE `phpbb_zebra` (
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `zebra_id` int(10) unsigned NOT NULL DEFAULT 0,
  `friend` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `foe` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`, `zebra_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

**PM Relevance**: 
- If `foe = 1`, PMs from that user are **blocked** (user cannot send PM to someone who has them as foe)
- If `friend = 1`, used for PM rules/filtering (allow PMs only from friends)
- Both flags are mutually exclusive per row

---

### 3.3 `phpbb_drafts` — PM Drafts

**Source**: `phpbb_dump.sql:1468-1483`

```sql
CREATE TABLE `phpbb_drafts` (
  `draft_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `topic_id` int(10) unsigned NOT NULL DEFAULT 0,
  `forum_id` mediumint(8) unsigned NOT NULL DEFAULT 0,
  `save_time` int(11) unsigned NOT NULL DEFAULT 0,
  `draft_subject` varchar(255) NOT NULL DEFAULT '',
  `draft_message` mediumtext NOT NULL,
  PRIMARY KEY (`draft_id`),
  KEY `save_time` (`save_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

**PM Draft Behavior**:
- PM drafts use the **same table** as post drafts
- **Differentiation**: PM drafts have `topic_id = 0` **and** `forum_id = 0`
- For post drafts: `forum_id > 0` (and optionally `topic_id > 0` for replies)
- UCP module `ucp_pm_drafts` (referenced in modules table line 2331) manages PM drafts
- **Limitation**: Draft table does NOT store recipient addresses — only subject + body

---

### 3.4 `phpbb_attachments` — PM Attachments

**Source**: `phpbb_dump.sql:808-832`

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

**PM Attachment Mechanism**:
- **Shared table** for both post and PM attachments
- **`in_message` column** is the discriminator:
  - `in_message = 0` → attachment belongs to a **post** (`post_msg_id` = post_id)
  - `in_message = 1` → attachment belongs to a **PM** (`post_msg_id` = msg_id from `phpbb_privmsgs`)
- `topic_id` is `0` for PM attachments
- `is_orphan = 1` → uploaded but not yet associated (during compose); set to `0` on message send
- `phpbb_privmsgs.message_attachment` is a boolean cache: `1` if message has attachments

---

### 3.5 `phpbb_reports` — PM Reports

**Source**: `phpbb_dump.sql:3103-3125`

```sql
CREATE TABLE `phpbb_reports` (
  `report_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `reason_id` smallint(4) unsigned NOT NULL DEFAULT 0,
  `post_id` int(10) unsigned NOT NULL DEFAULT 0,
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `user_notify` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `report_closed` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `report_time` int(11) unsigned NOT NULL DEFAULT 0,
  `report_text` mediumtext NOT NULL,
  `pm_id` int(10) unsigned NOT NULL DEFAULT 0,
  `reported_post_enable_bbcode` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `reported_post_enable_smilies` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `reported_post_enable_magic_url` tinyint(1) unsigned NOT NULL DEFAULT 1,
  `reported_post_text` mediumtext NOT NULL,
  `reported_post_uid` varchar(8) NOT NULL DEFAULT '',
  `reported_post_bitfield` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`report_id`),
  KEY `post_id` (`post_id`),
  KEY `pm_id` (`pm_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_bin;
```

**PM Report Mechanism**:
- **Shared table** for post AND PM reports
- **`pm_id` column** distinguishes PM reports:
  - `pm_id = 0` → this is a **post report** (use `post_id`)
  - `pm_id > 0` → this is a **PM report** (FK → `phpbb_privmsgs.msg_id`, `post_id = 0`)
- `reported_post_text` / `reported_post_uid` / `reported_post_bitfield` — snapshot of reported content at report time
- `phpbb_privmsgs.message_reported` is a boolean cache: `1` if message has been reported
- MCP module `mcp_pm_reports` handles PM report management (source: phpbb_dump.sql:2298-2300)

**Report reasons** (`phpbb_reports_reasons`):
- Same reasons used for both post and PM reports
- Default: warez, spam, off_topic, other

---

## 4. Configuration Values

### 4.1 PM-Related Config Keys

**Source**: `phpbb_dump.sql` — `phpbb_config` INSERT statements

| Key | Default Value | Description |
|-----|---------------|-------------|
| `allow_privmsg` | `'1'` | Master switch: enable/disable private messaging system-wide |
| `allow_pm_attach` | `'0'` | Allow attachments in PMs (disabled by default!) |
| `allow_pm_report` | `'1'` | Allow users to report PMs to moderators |
| `allow_sig_pm` | `'1'` | Allow signatures in PMs |
| `pm_max_msgs` | `'50'` | Maximum messages per folder per user |
| `pm_max_boxes` | `'4'` | Maximum custom folders a user can create |
| `pm_max_recipients` | `'0'` | Maximum recipients per PM (0 = unlimited) |
| `pm_edit_time` | `'0'` | Time limit for editing sent PMs in seconds (0 = unlimited) |
| `full_folder_action` | `'2'` | Default action when folder is full (line 1177) |

---

### 4.2 PM-Related ACL Permissions

**Source**: `phpbb_dump.sql` — `phpbb_acl_options` INSERT statements

**User-level permissions** (u_ prefix):

| Permission | Auth Option ID | Purpose |
|------------|---------------|---------|
| `u_sendpm` | 122 | Can send private messages |
| `u_readpm` | 117 | Can read private messages |
| `u_pm_attach` | 106 | Can attach files to PMs |
| `u_pm_bbcode` | 107 | Can use BBCode in PMs |
| `u_pm_delete` | 108 | Can delete own PMs |
| `u_pm_download` | 109 | Can download PM attachments |
| `u_pm_edit` | 110 | Can edit own sent PMs |
| `u_pm_emailpm` | 111 | Can email PMs to self |
| `u_pm_flash` | 112 | Can use flash in PMs |
| `u_pm_forward` | 113 | Can forward PMs |
| `u_pm_img` | 114 | Can use images in PMs |
| `u_pm_printpm` | 115 | Can print PMs |
| `u_pm_smilies` | 116 | Can use smilies in PMs |

**Moderator-level permissions** (m_ prefix):

| Permission | Auth Option ID | Purpose |
|------------|---------------|---------|
| `m_pm_report` | 47 | Can manage PM reports |

---

## 5. Table Relationships

```
phpbb_users.user_id ─────────────────┐
    │                                  │
    ├─→ phpbb_privmsgs.author_id       │
    │       │                          │
    │       └─→ phpbb_privmsgs_to.msg_id
    │               │
    ├─→ phpbb_privmsgs_to.user_id
    │       │
    │       └─→ phpbb_privmsgs_folder.folder_id (when > 0)
    │
    ├─→ phpbb_privmsgs_folder.user_id
    │
    ├─→ phpbb_privmsgs_rules.user_id
    │
    ├─→ phpbb_zebra.user_id / zebra_id
    │
    ├─→ phpbb_drafts.user_id
    │
    ├─→ phpbb_attachments.poster_id (where in_message=1)
    │
    └─→ phpbb_reports.user_id (where pm_id > 0)

phpbb_privmsgs.msg_id ──┬─→ phpbb_privmsgs_to.msg_id (1:N, one per recipient + author)
                         ├─→ phpbb_attachments.post_msg_id (where in_message=1)
                         └─→ phpbb_reports.pm_id (where pm_id > 0)
```

### Relationship Summary

| From | To | Cardinality | Join Condition |
|------|----|-------------|----------------|
| `privmsgs` → `privmsgs_to` | 1:N | `privmsgs.msg_id = privmsgs_to.msg_id` |
| `privmsgs_to` → `privmsgs_folder` | N:1 | `privmsgs_to.folder_id = privmsgs_folder.folder_id` (when folder_id > 0) |
| `privmsgs` → `attachments` | 1:N | `privmsgs.msg_id = attachments.post_msg_id AND attachments.in_message = 1` |
| `privmsgs` → `reports` | 1:N | `privmsgs.msg_id = reports.pm_id` (when pm_id > 0) |
| `users` → `privmsgs` | 1:N | `users.user_id = privmsgs.author_id` |
| `users` → `privmsgs_to` | 1:N | `users.user_id = privmsgs_to.user_id` |
| `users` → `zebra` | 1:N | `users.user_id = zebra.user_id` |
| `users` → `privmsgs_folder` | 1:N | `users.user_id = privmsgs_folder.user_id` |

---

## 6. Message Lifecycle

### Sending Flow
1. Author composes message → creates row in `phpbb_privmsgs`
2. For each recipient: creates row in `phpbb_privmsgs_to` with `folder_id = PRIVMSGS_INBOX`, `pm_new = 1`, `pm_unread = 1`
3. Author's own copy: creates row in `phpbb_privmsgs_to` with `folder_id = PRIVMSGS_OUTBOX`
4. Recipient's `user_new_privmsg` and `user_unread_privmsg` counters incremented
5. If `user_notify_pm = 1`, email notification sent

### Reading Flow
1. Recipient opens message → `pm_unread = 0`, `pm_new = 0`
2. Author's copy moves: `folder_id` changes from `PRIVMSGS_OUTBOX` (-2) → `PRIVMSGS_SENTBOX` (-1)
3. User counters decremented

### Folder Full Behavior
When inbox reaches `pm_max_msgs`:
- `FULL_FOLDER_NONE` (-3): New messages rejected
- `FULL_FOLDER_DELETE` (-2): Oldest messages auto-deleted
- `FULL_FOLDER_HOLD` (-1): New messages go to `PRIVMSGS_HOLD_BOX` (-4)
- `> 0`: New messages moved to specified custom folder

---

## 7. Gaps and Notes

- **No foreign keys** in DDL — all referential integrity is enforced at application level
- `phpbb_privmsgs_to` has **no PRIMARY KEY** — just three non-unique indexes. This could be a performance concern for very large tables
- `to_address`/`bcc_address` in `phpbb_privmsgs` store a **redundant** serialized version of recipients (format: `u_<id>:u_<id>:`)
- PM drafts in `phpbb_drafts` do **not** store recipients — only subject and body. Recipients would need to be re-entered when loading a draft
- The `user_full_folder` default is `-3` (FULL_FOLDER_NONE), meaning new messages are rejected when full by default. But `full_folder_action` config has default `2` suggesting a system-level override
- `pm_max_recipients = 0` means unlimited recipients — potential spam vector if not controlled via permissions
