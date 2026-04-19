# Research Sources — `phpbb\messaging` Service

## 1. message-lifecycle

### Core Logic
| File | Lines | Content |
|------|-------|---------|
| `src/phpbb/common/functions_privmsgs.php` | L1629-1990 | `submit_pm()` — main send flow: validation, DB insert into `phpbb_privmsgs`, recipient rows in `phpbb_privmsgs_to`, outbox placement, notification trigger, attachment handling |
| `src/phpbb/common/functions_privmsgs.php` | L1991-2229 | `message_history()` — builds reply/quote/forward message chain, fetches thread via `root_level` |
| `src/phpbb/common/functions_privmsgs.php` | L1037-1210 | `delete_pm()` — soft-delete via `pm_deleted` flag in `phpbb_privmsgs_to`, full purge when all recipients delete |
| `src/phpbb/common/functions_privmsgs.php` | L883-957 | `update_unread_status()`, `mark_folder_read()` — read tracking state transitions |
| `src/phpbb/common/functions_privmsgs.php` | L958-1036 | `handle_mark_actions()` — mark important, mark read batch operations |
| `src/phpbb/common/functions_privmsgs.php` | L1211-1410 | `phpbb_delete_users_pms()` — cascade delete all PMs for deleted users |

### UCP Compose
| File | Lines | Content |
|------|-------|---------|
| `src/phpbb/common/ucp/ucp_pm_compose.php` | full (1670 LOC) | Compose form controller: modes (post/reply/quote/forward/edit), recipient parsing, attachment upload, draft save/load, BBCode preview, CSRF, form key validation |

### UCP View Message
| File | Lines | Content |
|------|-------|---------|
| `src/phpbb/common/ucp/ucp_pm_viewmessage.php` | full (482 LOC) | Single message display: message_row fetch, read-status update, print view, delete action, report link |

### UCP Router
| File | Lines | Content |
|------|-------|---------|
| `src/phpbb/common/ucp/ucp_pm.php` | full (445 LOC) | Main PM router: dispatches to compose/view/drafts/options based on `$mode`, handles folder navigation and sidebar data |

### Database Tables
- `phpbb_privmsgs` — message storage (subject, body, author, timestamps, edit metadata, attachment flag, reported flag)
- `phpbb_privmsgs_to` — per-recipient state (read, replied, forwarded, marked, deleted, folder assignment)

---

## 2. folder-system

### Core Logic
| File | Lines | Content |
|------|-------|---------|
| `src/phpbb/common/functions_privmsgs.php` | L116-225 | `get_folder()` — loads system folders (PRIVMSGS_INBOX=-1, PRIVMSGS_OUTBOX=-2, PRIVMSGS_SENTBOX=-3, PRIVMSGS_NO_BOX=-4) + custom folders from `phpbb_privmsgs_folder`, computes counts |
| `src/phpbb/common/functions_privmsgs.php` | L226-256 | `clean_sentbox()` — auto-prune sent messages when sentbox exceeds limit |
| `src/phpbb/common/functions_privmsgs.php` | L783-882 | `move_pm()` — move messages between folders, update `folder_id` in `phpbb_privmsgs_to`, recalculate `pm_count` |
| `src/phpbb/common/functions_privmsgs.php` | L1595-1628 | `get_folder_status()` — folder capacity check, status indicators (full, near-limit) |

### UCP Folder View
| File | Lines | Content |
|------|-------|---------|
| `src/phpbb/common/ucp/ucp_pm_viewfolder.php` | full (624 LOC) | Folder listing: pagination, sorting, message list for selected folder, batch actions (mark read, delete, move) |

### UCP Options (Folder CRUD)
| File | Lines | Content |
|------|-------|---------|
| `src/phpbb/common/ucp/ucp_pm_options.php` | partial | Folder management: create/rename/delete custom folders, `pm_max_boxes` limit enforcement |

### Configuration
| Config Key | Default | Effect |
|------------|---------|--------|
| `pm_max_msgs` | 50 | Max messages per folder |
| `pm_max_boxes` | 4 | Max custom folders per user |
| `full_folder_action` | 2 | What happens when folder is full (1=error, 2=hold in no-box) |

### Database Tables
- `phpbb_privmsgs_folder` — custom folders: `folder_id`, `user_id`, `folder_name`, `pm_count`
- `phpbb_privmsgs_to.folder_id` — folder assignment per recipient

---

## 3. rules-engine

### Constants (functions_privmsgs.php L28-52)
| Category | Constants |
|----------|-----------|
| **Connection Operators** (RULE_*) | `RULE_IS_LIKE` (1), `RULE_IS_NOT_LIKE` (2), `RULE_IS` (3), `RULE_IS_NOT` (4), `RULE_BEGINS_WITH` (5), `RULE_ENDS_WITH` (6), `RULE_IS_FRIEND` (7), `RULE_IS_FOE` (8), `RULE_IS_USER` (9), `RULE_IS_GROUP` (10), `RULE_ANSWERED` (11), `RULE_FORWARDED` (12), `RULE_TO_GROUP` (14), `RULE_TO_ME` (15) |
| **Actions** (ACTION_*) | `ACTION_PLACE_INTO_FOLDER` (1), `ACTION_MARK_AS_READ` (2), `ACTION_MARK_AS_IMPORTANT` (3), `ACTION_DELETE_MESSAGE` (4) |
| **Check Types** (CHECK_*) | `CHECK_SUBJECT` (1), `CHECK_SENDER` (2), `CHECK_MESSAGE` (3), `CHECK_STATUS` (4), `CHECK_TO` (5) |

### Core Logic
| File | Lines | Content |
|------|-------|---------|
| `src/phpbb/common/functions_privmsgs.php` | L257-369 | `check_rule()` — evaluates single rule against message row: dispatches by `rule_check` type, applies `rule_connection` operator against `rule_string`/`rule_user_id`/`rule_group_id` |
| `src/phpbb/common/functions_privmsgs.php` | L416-782 | `place_pm_into_folder()` — main rule evaluation orchestrator: loads all user rules, iterates incoming messages, calls `check_rule()`, applies `rule_action` (folder placement, mark read, mark important, delete) |

### UCP Options (Rule CRUD)
| File | Lines | Content |
|------|-------|---------|
| `src/phpbb/common/ucp/ucp_pm_options.php` | partial | Rule management: define_rule_option form, add/edit/delete rules in `phpbb_privmsgs_rules` |

### Database Table
- `phpbb_privmsgs_rules` — `rule_id`, `user_id`, `rule_check`, `rule_connection`, `rule_string`, `rule_user_id`, `rule_group_id`, `rule_action`, `rule_folder_id`

---

## 4. participant-model

### Core Logic
| File | Lines | Content |
|------|-------|---------|
| `src/phpbb/common/functions_privmsgs.php` | L1411-1444 | `rebuild_header()` — builds `to_address`/`bcc_address` text encoding: `u_N` for user IDs, `g_N` for group IDs |
| `src/phpbb/common/functions_privmsgs.php` | L1445-1594 | `write_pm_addresses()` — resolves `to_address`/`bcc_address` strings back to user/group names for display |
| `src/phpbb/common/functions_privmsgs.php` | L2253-2282 | `phpbb_get_max_setting_from_group()` — resolve per-group PM limits (max messages) |
| `src/phpbb/common/functions_privmsgs.php` | L2283-2368 | `get_recipient_strings()` — batch-resolve recipients for message list views |
| `src/phpbb/common/functions_privmsgs.php` | L2230-2252 | `set_user_message_limit()` — apply group-based message limit overrides |

### UCP Compose (Recipient Handling)
| File | Lines | Content |
|------|-------|---------|
| `src/phpbb/common/ucp/ucp_pm_compose.php` | partial | Recipient input parsing, user/group lookup, `pm_max_recipients` enforcement, `user_allow_pm` check, foe blocking check, mass PM group support |

### Database Tables
- `phpbb_privmsgs.to_address` — text field: `u_2:u_5:g_3` encoded recipient list
- `phpbb_privmsgs.bcc_address` — text field: same encoding for BCC
- `phpbb_privmsgs_to` — one row per recipient: `msg_id`, `user_id`, `author_id`
- `phpbb_zebra` — friend/foe table: `user_id`, `zebra_id`, `friend`, `foe`

### Configuration
| Config Key | Default | Effect |
|------------|---------|--------|
| `pm_max_recipients` | 0 (unlimited) | Max recipients per message |
| `allow_mass_pm` | 1 | Enable sending PM to entire group |
| `allow_privmsg` | 1 | Global PM enable/disable |

### User Columns (phpbb_users)
- `user_allow_pm` — per-user opt-out flag
- `user_new_privmsg` — count of new PMs (notification badge)
- `user_unread_privmsg` — count of unread PMs
- `user_last_privmsg` — timestamp anti-flood

---

## 5. pm-schema

### Primary Tables

#### phpbb_privmsgs (L2754-2783 in phpbb_dump.sql)
| Column | Type | Purpose |
|--------|------|---------|
| `msg_id` | INT UNSIGNED AUTO_INCREMENT PK | Message ID |
| `root_level` | MEDIUMINT UNSIGNED | Conversation threading (0=root, else msg_id of root) |
| `author_id` | INT UNSIGNED | Author user ID |
| `icon_id` | MEDIUMINT UNSIGNED | Message icon |
| `author_ip` | VARCHAR(40) | Author IP |
| `message_time` | INT UNSIGNED | Send timestamp |
| `enable_bbcode` | TINYINT(1) | BBCode enabled |
| `enable_smilies` | TINYINT(1) | Smilies enabled |
| `enable_magic_url` | TINYINT(1) | Auto-link URLs |
| `enable_sig` | TINYINT(1) | Show signature |
| `message_subject` | VARCHAR(255) | Subject line |
| `message_text` | MEDIUMTEXT | Message body (BBCode) |
| `message_edit_reason` | VARCHAR(255) | Edit reason |
| `message_edit_user` | INT UNSIGNED | Editor user ID |
| `message_attachment` | TINYINT(1) | Has attachments flag |
| `bbcode_bitfield` | VARCHAR(255) | BBCode data |
| `bbcode_uid` | VARCHAR(8) | BBCode UID |
| `message_edit_time` | INT UNSIGNED | Last edit timestamp |
| `message_edit_count` | SMALLINT(4) | Edit counter |
| `to_address` | TEXT | Encoded recipient list |
| `bcc_address` | TEXT | Encoded BCC list |
| `message_reported` | TINYINT(1) | Reported flag |
| **Indexes** | | `author_ip`, `message_time`, `author_id`, `root_level` |

#### phpbb_privmsgs_to (L2858-2882)
| Column | Type | Purpose |
|--------|------|---------|
| `msg_id` | INT UNSIGNED | FK to phpbb_privmsgs |
| `user_id` | INT UNSIGNED | Recipient user ID |
| `author_id` | INT UNSIGNED | Author (denormalized) |
| `pm_deleted` | TINYINT(1) | Soft-delete flag |
| `pm_new` | TINYINT(1) | New message flag |
| `pm_unread` | TINYINT(1) | Unread flag |
| `pm_replied` | TINYINT(1) | User has replied |
| `pm_marked` | TINYINT(1) | Marked/starred |
| `pm_forwarded` | TINYINT(1) | User has forwarded |
| `folder_id` | INT | Folder assignment (-1=inbox, -2=outbox, -3=sentbox, custom ID) |
| **Indexes** | | `msg_id`, `author_id`, `(user_id, folder_id)` |

#### phpbb_privmsgs_folder (L2801-2820)
| Column | Type | Purpose |
|--------|------|---------|
| `folder_id` | MEDIUMINT UNSIGNED AUTO_INCREMENT PK | Folder ID |
| `user_id` | INT UNSIGNED | Owner user ID |
| `folder_name` | VARCHAR(255) | Folder display name |
| `pm_count` | MEDIUMINT UNSIGNED | Cached message count |
| **Indexes** | | `user_id` |

#### phpbb_privmsgs_rules (L2827-2852)
| Column | Type | Purpose |
|--------|------|---------|
| `rule_id` | MEDIUMINT UNSIGNED AUTO_INCREMENT PK | Rule ID |
| `user_id` | INT UNSIGNED | Owner user ID |
| `rule_check` | MEDIUMINT UNSIGNED | What to check (CHECK_SUBJECT=1..CHECK_TO=5) |
| `rule_connection` | MEDIUMINT UNSIGNED | How to match (RULE_IS_LIKE=1..RULE_TO_ME=15) |
| `rule_string` | VARCHAR(255) | String match value |
| `rule_user_id` | INT UNSIGNED | User match value |
| `rule_group_id` | MEDIUMINT UNSIGNED | Group match value |
| `rule_action` | MEDIUMINT UNSIGNED | What to do (ACTION_PLACE_INTO_FOLDER=1..ACTION_DELETE=4) |
| `rule_folder_id` | INT | Target folder for placement action |
| **Indexes** | | `user_id` |

### Related Tables

#### phpbb_drafts (L1468-1480) — shared with forum posts
- `draft_id`, `user_id`, `topic_id`, `forum_id`, `save_time`, `draft_subject`, `draft_message`
- PM drafts identified by `topic_id = 0 AND forum_id = 0`

#### phpbb_attachments (L808-831) — shared with forum posts
- `attach_id`, `post_msg_id`, `topic_id`, `in_message`, `poster_id`, `is_orphan`, `physical_filename`, `real_filename`, `download_count`, etc.
- PM attachments identified by `in_message = 1`, `post_msg_id` = msg_id

#### phpbb_zebra (L4165-4172) — friend/foe list
- `user_id`, `zebra_id`, `friend`, `foe`

### Config Entries (phpbb_config)
| Key | Default | Line |
|-----|---------|------|
| `allow_privmsg` | 1 | L1064 |
| `allow_pm_attach` | 0 | L1060 |
| `allow_pm_report` | 1 | L1061 |
| `allow_mass_pm` | 1 | L1055 |
| `pm_max_msgs` | 50 | L1295 |
| `pm_max_boxes` | 4 | L1294 |
| `pm_max_recipients` | 0 | L1296 |
| `pm_edit_time` | 0 | L1293 |
| `full_folder_action` | 2 | L1177 |

### phpbb_users PM Columns (L3996-4011)
- `user_new_privmsg` (INT) — new PM count
- `user_unread_privmsg` (INT) — unread PM count
- `user_last_privmsg` (TIMESTAMP) — last PM sent (flood control)
- `user_allow_pm` (TINYINT) — user opt-in/out

### Migration Files
- `src/phpbb/forums/db/migration/data/v30x/release_3_0_0.php` — original schema creation (4 PM tables + user columns)
- `src/phpbb/forums/db/migration/data/v31x/m_pm_report.php` — PM reporting permission migration

---

## 6. notifications-reporting

### Notification Types
| File | LOC | Class | Purpose |
|------|-----|-------|---------|
| `src/phpbb/forums/notification/type/pm.php` | 205 | `phpbb\notification\type\pm` | PM received notification: `is_available()` checks `allow_privmsg` + `u_readpm`, `find_users_for_notification()`, `create_insert_array()`, `get_email_template()` → `privmsg_notify` |
| `src/phpbb/forums/notification/type/report_pm.php` | 258 | `phpbb\notification\type\report_pm` | PM reported notification: extends `pm` base, notifies moderators with `m_report` permission |
| `src/phpbb/forums/notification/type/report_pm_closed.php` | 183 | `phpbb\notification\type\report_pm_closed` | Report closed notification: notifies original reporter when MCP closes report |

### Report Handler
| File | LOC | Class | Purpose |
|------|-----|-------|---------|
| `src/phpbb/forums/report/report_handler_pm.php` | 137 | `phpbb\report\report_handler_pm` | Creates PM report: validates `allow_pm_report`, sets `message_reported = 1` on `phpbb_privmsgs`, inserts into `phpbb_reports` |
| `src/phpbb/forums/report/report_handler.php` | — | `phpbb\report\report_handler` | Base class: shared report creation logic |
| `src/phpbb/forums/report/report_handler_interface.php` | — | interface | `validate()`, `create_report()` contract |

### MCP (Moderator Control Panel)
| File | LOC | Purpose |
|------|-----|---------|
| `src/phpbb/common/mcp/mcp_pm_reports.php` | 330 | PM report list/detail/close views, report actions (close, delete reported PM) |
| `src/phpbb/common/mcp/info/mcp_pm_reports.php` | — | Module info registration |

### Report Exception
- `src/phpbb/forums/report/exception/pm_reporting_disabled_exception.php` — thrown when `allow_pm_report = 0`

### Attachment Integration
| File | Purpose |
|------|---------|
| `src/phpbb/forums/attachment/delete.php` | PM attachment deletion hooks (`message_ids` array in events) |
| `src/phpbb/forums/attachment/resync.php` | PM attachment resync using `PRIVMSGS_TABLE` |

### PM Counter Management
| File | Lines | Content |
|------|-------|---------|
| `src/phpbb/common/functions_privmsgs.php` | L370-415 | `update_pm_counts()` — recalculates `user_new_privmsg`, `user_unread_privmsg` from `phpbb_privmsgs_to` flags |
