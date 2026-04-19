# Database Schema Analysis — User-Related Tables

## Table Inventory

| Table | PK | Auto-inc | Purpose |
|-------|-----|----------|---------|
| `phpbb_users` | `user_id` INT(10) UNSIGNED | Yes (115) | Core user entity — 68 columns covering identity, profile, prefs, auth, messaging, activity |
| `phpbb_groups` | `group_id` MEDIUMINT(8) UNSIGNED | Yes (15) | Group definitions — permissions, display, messaging limits |
| `phpbb_user_group` | **None** (indexes only) | No | Many-to-many user↔group join with leader/pending flags |
| `phpbb_banlist` | `ban_id` INT(10) UNSIGNED | Yes | Multi-mode bans (user/IP/email) with exclude/whitelist |
| `phpbb_sessions` | `session_id` CHAR(32) | No | Active session tracking (MD5 hex ID) |
| `phpbb_sessions_keys` | (`key_id`, `user_id`) composite | No | Persistent auto-login "remember me" keys |
| `phpbb_profile_fields` | `field_id` MEDIUMINT(8) UNSIGNED | Yes (21) | Custom profile field definitions (type, validation, visibility) |
| `phpbb_profile_fields_data` | `user_id` INT(10) UNSIGNED | No | Dynamic EAV-like data table — columns match defined fields |
| `phpbb_profile_fields_lang` | (`field_id`, `lang_id`, `option_id`) composite | No | Language-specific option values for dropdown fields |
| `phpbb_profile_lang` | (`field_id`, `lang_id`) composite | No | Language-specific field names and explanations |
| `phpbb_user_notifications` | UNIQUE(`item_type`,`item_id`,`user_id`,`method`) | No | Per-user notification preferences |
| `phpbb_notifications` | `notification_id` INT(10) UNSIGNED | Yes | Actual notification instances (inbox) |
| `phpbb_notification_types` | `notification_type_id` SMALLINT(4) UNSIGNED | Yes | Type registry |
| `phpbb_notification_emails` | (`notification_type_id`,`item_id`,`item_parent_id`,`user_id`) composite | No | Email digest tracking |
| `phpbb_log` | `log_id` INT(10) UNSIGNED | Yes | Admin/mod/user action log |
| `phpbb_login_attempts` | **None** (indexes only) | No | Per-IP/user failed login tracking |
| `phpbb_confirm` | (`session_id`, `confirm_id`) composite | No | CAPTCHA/confirmation code tracking |
| `phpbb_privmsgs_rules` | `rule_id` MEDIUMINT(8) UNSIGNED | Yes | Per-user PM auto-filtering rules |
| `phpbb_privmsgs_folder` | `folder_id` MEDIUMINT(8) UNSIGNED | Yes | Per-user PM custom folders |
| `phpbb_privmsgs_to` | **None** (indexes only) | No | PM delivery / receipt tracking |
| `phpbb_ranks` | `rank_id` MEDIUMINT(8) UNSIGNED | Yes (3) | Rank definitions (post-count or special) |
| `phpbb_zebra` | (`user_id`, `zebra_id`) composite | No | Friend/foe lists |
| `phpbb_bookmarks` | (`topic_id`, `user_id`) composite | No | Topic bookmarks per user |
| `phpbb_drafts` | `draft_id` INT(10) UNSIGNED | Yes | User-saved post drafts |
| `phpbb_forums_watch` | **None** (indexes only) | No | Forum subscription per user |
| `phpbb_topics_watch` | **None** (indexes only) | No | Topic subscription per user |
| `phpbb_acl_users` | **None** (indexes only) | No | Per-user permission overrides |
| `phpbb_acl_groups` | **None** (indexes only) | No | Per-group permission grants |
| `phpbb_oauth_accounts` | (`user_id`, `provider`) composite | No | OAuth provider account links |
| `phpbb_oauth_states` | **None** (indexes only) | No | OAuth flow state (transient) |
| `phpbb_oauth_tokens` | **None** (indexes only) | No | OAuth access tokens |
| `phpbb_warnings` | `warning_id` MEDIUMINT(8) UNSIGNED | Yes | Warning records per user |
| `phpbb_disallow` | `disallow_id` MEDIUMINT(8) UNSIGNED | Yes | Disallowed username patterns |

---

## Column Classification: `phpbb_users` (68 columns)

### Core Identity (10 columns)

| Column | Type | Notes |
|--------|------|-------|
| `user_id` | INT(10) UNSIGNED AUTO_INCREMENT | PK |
| `user_type` | TINYINT(2), default 0 | 0=NORMAL, 1=INACTIVE, 2=IGNORE (bots/anonymous), 3=FOUNDER |
| `group_id` | MEDIUMINT(8) UNSIGNED, default 3 | Default group (forum display color/rank source) |
| `username` | VARCHAR(255) | Display username |
| `username_clean` | VARCHAR(255), UNIQUE | Lowercased canonical form for lookups |
| `user_password` | VARCHAR(255) | Argon2id hash (or legacy hashes for older accounts) |
| `user_email` | VARCHAR(100) | Email, indexed |
| `user_ip` | VARCHAR(40) | Registration IP |
| `user_regdate` | INT(11) UNSIGNED | Unix timestamp of registration |
| `user_colour` | VARCHAR(6) | Hex colour for username display (inherited from default group) |

### Profile (8 columns)

| Column | Type | Notes |
|--------|------|-------|
| `user_avatar` | VARCHAR(255) | Avatar file path/URL |
| `user_avatar_type` | VARCHAR(255) | Avatar driver class name |
| `user_avatar_width` | SMALLINT(4) UNSIGNED | px |
| `user_avatar_height` | SMALLINT(4) UNSIGNED | px |
| `user_sig` | MEDIUMTEXT | Signature BBCode text |
| `user_sig_bbcode_uid` | VARCHAR(8) | BBCode unique ID for sig parsing |
| `user_sig_bbcode_bitfield` | VARCHAR(255) | Which BBCodes used in sig |
| `user_birthday` | VARCHAR(10) | Format "DD-MM-YYYY" or blank, indexed |
| `user_jabber` | VARCHAR(255) | Jabber/XMPP address |
| `user_rank` | MEDIUMINT(8) UNSIGNED | Special rank override (0 = use post count) |

### Preferences (16 columns)

| Column | Type | Notes |
|--------|------|-------|
| `user_lang` | VARCHAR(30) | Language ISO code |
| `user_timezone` | VARCHAR(100) | Timezone identifier |
| `user_dateformat` | VARCHAR(64) | PHP date format string |
| `user_style` | MEDIUMINT(8) UNSIGNED | Style/theme ID |
| `user_options` | INT(11) UNSIGNED, default 230271 | **Bitfield** — see below |
| `user_topic_show_days` | SMALLINT(4) UNSIGNED | Topic list age filter (0=all) |
| `user_topic_sortby_type` | VARCHAR(1), default 't' | Sort key (t=time, a=author, s=subject) |
| `user_topic_sortby_dir` | VARCHAR(1), default 'd' | Sort direction (a=asc, d=desc) |
| `user_post_show_days` | SMALLINT(4) UNSIGNED | Post list age filter |
| `user_post_sortby_type` | VARCHAR(1), default 't' | Sort key |
| `user_post_sortby_dir` | VARCHAR(1), default 'a' | Sort direction |
| `user_notify` | TINYINT(1) UNSIGNED | Auto-subscribe to topic on reply |
| `user_notify_pm` | TINYINT(1) UNSIGNED, default 1 | Email notification on new PM |
| `user_notify_type` | TINYINT(4) | 0=email, 1=jabber (deprecated) |
| `user_allow_pm` | TINYINT(1) UNSIGNED, default 1 | Allow receiving PMs |
| `user_allow_viewonline` | TINYINT(1) UNSIGNED, default 1 | Show in "Who is online" |
| `user_allow_viewemail` | TINYINT(1) UNSIGNED, default 1 | Allow others to see email |
| `user_allow_massemail` | TINYINT(1) UNSIGNED, default 1 | Receive mass emails from admin |

### Activity Counters (9 columns)

| Column | Type | Notes |
|--------|------|-------|
| `user_posts` | MEDIUMINT(8) UNSIGNED | Total post count |
| `user_lastvisit` | INT(11) UNSIGNED | Last login timestamp |
| `user_last_active` | INT(11) UNSIGNED | Last activity (page load) timestamp |
| `user_lastmark` | INT(11) UNSIGNED | Last "mark all forums read" timestamp |
| `user_lastpost_time` | INT(11) UNSIGNED | Last post timestamp |
| `user_lastpage` | VARCHAR(200) | Last page visited (URL fragment) |
| `user_last_search` | INT(11) UNSIGNED | Last search timestamp (flood control) |
| `user_warnings` | TINYINT(4) | Active warning count |
| `user_last_warning` | INT(11) UNSIGNED | Timestamp of last warning |

### Auth / Security (14 columns)

| Column | Type | Notes |
|--------|------|-------|
| `user_permissions` | MEDIUMTEXT | Compiled permission string (binary-encoded ACL cache) |
| `user_perm_from` | MEDIUMINT(8) UNSIGNED | User ID permissions are cloned from (0=own) |
| `user_passchg` | INT(11) UNSIGNED | Timestamp of last password change |
| `user_login_attempts` | TINYINT(4) | Consecutive failed login attempts |
| `user_inactive_reason` | TINYINT(2) | 0=active, 1=profile change, 2=admin deactivation, 3=new registration pending |
| `user_inactive_time` | INT(11) UNSIGNED | When user was deactivated |
| `user_actkey` | VARCHAR(32) | Activation key (registration/email change) |
| `user_actkey_expiration` | INT(11) UNSIGNED | Activation key expiry |
| `reset_token` | VARCHAR(64) | Password reset token |
| `reset_token_expiration` | INT(11) UNSIGNED | Reset token expiry |
| `user_newpasswd` | VARCHAR(255) | Temporary new password hash (pending confirmation) |
| `user_form_salt` | VARCHAR(32) | Per-user CSRF salt for form keys |
| `user_last_confirm_key` | VARCHAR(10) | Last CAPTCHA confirmation key |
| `user_new` | TINYINT(1) UNSIGNED, default 1 | Flag: user hasn't posted yet (for "newly registered" group logic) |

### Messaging (5 columns)

| Column | Type | Notes |
|--------|------|-------|
| `user_new_privmsg` | INT(4) | Count of new (unprocessed) PMs |
| `user_unread_privmsg` | INT(4) | Count of unread PMs |
| `user_last_privmsg` | INT(11) UNSIGNED | Timestamp of last PM received |
| `user_message_rules` | TINYINT(1) UNSIGNED | Whether user has PM routing rules defined |
| `user_full_folder` | INT(11) | Action when inbox full: -1=hold, -2=move to sent, -3=error, >0=folder_id |

### Admin/Moderation (5 columns)

| Column | Type | Notes |
|--------|------|-------|
| `user_emailtime` | INT(11) UNSIGNED | Last board-email-form timestamp (flood control) |
| `user_reminded` | TINYINT(4) | Times reminded about inactivity |
| `user_reminded_time` | INT(11) UNSIGNED | Last reminder timestamp |

---

## `user_options` Bitfield (INT, default 230271 = binary 0000 0000 0000 0011 1000 0011 1111)

Source: `src/phpbb/forums/user.php` line 52:

```php
var $keyoptions = array(
    'viewimg'       => 0,   // Display images inline
    'viewflash'     => 1,   // Display Flash content
    'viewsmilies'   => 2,   // Display smilies as images
    'viewsigs'      => 3,   // Display signatures
    'viewavatars'   => 4,   // Display avatars
    'viewcensors'   => 5,   // Apply word censoring
    'attachsig'     => 6,   // Attach signature to posts by default
    // bit 7 — unused/reserved
    'bbcode'        => 8,   // Enable BBCode in posts by default
    'smilies'       => 9,   // Enable smilies in posts by default
    // bits 10-14 — unused/reserved
    'sig_bbcode'    => 15,  // BBCode in signature allowed
    'sig_smilies'   => 16,  // Smilies in signature allowed
    'sig_links'     => 17,  // Magic URLs in signature allowed
);
```

**Default 230271 in binary**: bits 0,1,2,3,4,5,6,8,9,15,16,17 set = all display options ON + posting defaults ON + sig parsing ON.

---

## Column Classification: `phpbb_groups` (21 columns)

### Identity & Control

| Column | Type | Notes |
|--------|------|-------|
| `group_id` | MEDIUMINT(8) UNSIGNED AUTO_INCREMENT | PK |
| `group_type` | TINYINT(4), default 1 | **0=OPEN, 1=CLOSED, 2=HIDDEN, 3=SPECIAL** |
| `group_name` | VARCHAR(255) | Internal identifier (e.g., "ADMINISTRATORS") |
| `group_founder_manage` | TINYINT(1) UNSIGNED | Only founders can manage |
| `group_skip_auth` | TINYINT(1) UNSIGNED | Skip permission checks for group (optimization) |

### Description (BBCode-enabled)

| Column | Type | Notes |
|--------|------|-------|
| `group_desc` | TEXT | Group description text |
| `group_desc_bitfield` | VARCHAR(255) | BBCode bitfield |
| `group_desc_options` | INT(11) UNSIGNED, default 7 | BBCode options (flags for bbcode/urls/smilies) |
| `group_desc_uid` | VARCHAR(8) | BBCode UID |

### Display

| Column | Type | Notes |
|--------|------|-------|
| `group_display` | TINYINT(1) UNSIGNED | Show group in user's profile |
| `group_avatar` | VARCHAR(255) | Group avatar path |
| `group_avatar_type` | VARCHAR(255) | Avatar driver |
| `group_avatar_width` | SMALLINT(4) UNSIGNED | px |
| `group_avatar_height` | SMALLINT(4) UNSIGNED | px |
| `group_rank` | MEDIUMINT(8) UNSIGNED | Associated rank |
| `group_colour` | VARCHAR(6) | Username colour hex |
| `group_legend` | MEDIUMINT(8) UNSIGNED | Legend display order (0=hidden) |

### Limits

| Column | Type | Notes |
|--------|------|-------|
| `group_sig_chars` | MEDIUMINT(8) UNSIGNED | Max signature characters |
| `group_receive_pm` | TINYINT(1) UNSIGNED | Can receive PMs |
| `group_message_limit` | MEDIUMINT(8) UNSIGNED | PM folder size limit |
| `group_max_recipients` | MEDIUMINT(8) UNSIGNED | Max PM recipients per message |

### `group_type` Semantics

| Value | Constant | Meaning |
|-------|----------|---------|
| 0 | GROUP_OPEN | Anyone can join |
| 1 | GROUP_CLOSED | Join requires approval |
| 2 | GROUP_HIDDEN | Membership invisible to non-members |
| 3 | GROUP_SPECIAL | System groups (GUESTS, REGISTERED, BOTS, ADMINISTRATORS, etc.) — not manageable by users |

### Special Groups in this DB

| group_id | group_name | Notes |
|----------|-----------|-------|
| 1 | GUESTS | For anonymous user (user_id=1) |
| 2 | REGISTERED | All registered users |
| 3 | REGISTERED_COPPA | Under-13 COPPA compliance |
| 4 | GLOBAL_MODERATORS | Moderators |
| 5 | ADMINISTRATORS | Admins (founder_manage=1) |
| 6 | BOTS | Bot accounts |
| 7 | NEWLY_REGISTERED | New users (restricted permissions) |

---

## Column Classification: `phpbb_user_group`

| Column | Type | Notes |
|--------|------|-------|
| `group_id` | MEDIUMINT(8) UNSIGNED | FK → phpbb_groups |
| `user_id` | INT(10) UNSIGNED | FK → phpbb_users |
| `group_leader` | TINYINT(1) UNSIGNED | Is this user a group leader (can manage) |
| `user_pending` | TINYINT(1) UNSIGNED, default 1 | Pending membership approval |

### Design Notes
- **NO PRIMARY KEY** — only separate indexes on `group_id`, `user_id`, `group_leader`
- Allows **duplicate rows** (the dump shows duplicates — this is a known quirk)
- No composite unique constraint! Logic relies on application-level deduplication
- `user_pending=1` by default — must explicitly confirm membership

---

## Column Classification: `phpbb_banlist`

| Column | Type | Notes |
|--------|------|-------|
| `ban_id` | INT(10) UNSIGNED AUTO_INCREMENT | PK |
| `ban_userid` | INT(10) UNSIGNED | Banned user ID (0 if IP/email ban) |
| `ban_ip` | VARCHAR(40) | Banned IP/range (empty if user/email ban) |
| `ban_email` | VARCHAR(100) | Banned email pattern (empty if user/IP ban) |
| `ban_start` | INT(11) UNSIGNED | Ban start timestamp |
| `ban_end` | INT(11) UNSIGNED | Ban end timestamp (0=permanent) |
| `ban_exclude` | TINYINT(1) UNSIGNED | **1=whitelist entry** (exclude from ban) |
| `ban_reason` | VARCHAR(255) | Internal reason (admin-only) |
| `ban_give_reason` | VARCHAR(255) | Reason shown to banned user |

### Multi-mode Pattern
One table handles three ban types via mutually-exclusive populated columns:
- User ban: `ban_userid > 0`, `ban_ip=''`, `ban_email=''`
- IP ban: `ban_userid=0`, `ban_ip='x.x.x.x'`, `ban_email=''`
- Email ban: `ban_userid=0`, `ban_ip=''`, `ban_email='*@domain.com'`

### Index Strategy
- `ban_end` — for expiry cleanup
- `ban_user` (`ban_userid`, `ban_exclude`) — check if user banned
- `ban_email` (`ban_email`, `ban_exclude`) — check email during registration
- `ban_ip` (`ban_ip`, `ban_exclude`) — check IP on every request

### `ban_exclude` Pattern
A row with `ban_exclude=1` creates a **whitelist exception** — e.g., ban entire IP range but exclude specific IPs.

---

## Column Classification: `phpbb_sessions`

| Column | Type | Notes |
|--------|------|-------|
| `session_id` | CHAR(32) | PK — MD5 hex string |
| `session_user_id` | INT(10) UNSIGNED | FK → phpbb_users (1 = guest) |
| `session_last_visit` | INT(11) UNSIGNED | Previous visit timestamp (set on session start) |
| `session_start` | INT(11) UNSIGNED | Session creation time |
| `session_time` | INT(11) UNSIGNED | Last activity time (indexed for GC) |
| `session_ip` | VARCHAR(40) | Client IP |
| `session_browser` | VARCHAR(150) | User-Agent string |
| `session_forwarded_for` | VARCHAR(255) | X-Forwarded-For header |
| `session_page` | VARCHAR(255) | Last page URL |
| `session_viewonline` | TINYINT(1) UNSIGNED, default 1 | Visible in "who's online" |
| `session_autologin` | TINYINT(1) UNSIGNED | Created via remember-me |
| `session_admin` | TINYINT(1) UNSIGNED | Admin panel session |
| `session_forum_id` | MEDIUMINT(8) UNSIGNED | Current forum context |

### Indexes
- PK: `session_id`
- `session_time` — for garbage collection (delete expired sessions)
- `session_user_id` — lookup by user
- `session_fid` — "who's online in forum X" queries

---

## Column Classification: `phpbb_sessions_keys`

| Column | Type | Notes |
|--------|------|-------|
| `key_id` | CHAR(32) | MD5 hash of the remember-me cookie value |
| `user_id` | INT(10) UNSIGNED | FK → phpbb_users |
| `last_ip` | VARCHAR(40) | Last used IP |
| `last_login` | INT(11) UNSIGNED | Last auto-login timestamp |

### PK: (`key_id`, `user_id`) — composite
### Index: `last_login` — for cleanup of stale keys

---

## Column Classification: `phpbb_profile_fields` (Custom Profile System)

### Field Definition (24 columns)

| Column | Type | Notes |
|--------|------|-------|
| `field_id` | MEDIUMINT(8) UNSIGNED AUTO_INCREMENT | PK |
| `field_name` | VARCHAR(255) | Internal name (e.g., "phpbb_location") |
| `field_type` | VARCHAR(100) | Driver class (e.g., "profilefields.type.string", "profilefields.type.url") |
| `field_ident` | VARCHAR(20) | Short identifier for `pf_` column in data table |
| `field_length` | VARCHAR(20) | Display size (e.g., "20", "3|30" for textarea rows|cols) |
| `field_minlen` | VARCHAR(255) | Minimum length |
| `field_maxlen` | VARCHAR(255) | Maximum length |
| `field_novalue` | VARCHAR(255) | "No value" display text |
| `field_default_value` | VARCHAR(255) | Default value |
| `field_validation` | VARCHAR(128) | Regex validation pattern |
| `field_required` | TINYINT(1) UNSIGNED | Required on registration |
| `field_show_on_reg` | TINYINT(1) UNSIGNED | Show during registration |
| `field_hide` | TINYINT(1) UNSIGNED | Only visible to admins |
| `field_no_view` | TINYINT(1) UNSIGNED | Don't show on profile |
| `field_active` | TINYINT(1) UNSIGNED | Field enabled |
| `field_order` | MEDIUMINT(8) UNSIGNED | Display order |
| `field_show_profile` | TINYINT(1) UNSIGNED | Show on profile page |
| `field_show_on_vt` | TINYINT(1) UNSIGNED | Show on viewtopic |
| `field_show_novalue` | TINYINT(1) UNSIGNED | Show even if empty |
| `field_show_on_pm` | TINYINT(1) UNSIGNED | Show in PM view |
| `field_show_on_ml` | TINYINT(1) UNSIGNED | Show in memberlist |
| `field_is_contact` | TINYINT(1) UNSIGNED | Is a contact method |
| `field_contact_desc` | VARCHAR(255) | Contact link description lang key |
| `field_contact_url` | VARCHAR(255) | Contact URL template (%s = value) |

### `phpbb_profile_fields_data` — Dynamic Column Pattern

Columns are added/removed dynamically as profile fields are defined:
```sql
user_id INT(10) UNSIGNED -- PK
pf_phpbb_interests MEDIUMTEXT
pf_phpbb_occupation MEDIUMTEXT
pf_phpbb_location VARCHAR(255)
pf_phpbb_facebook VARCHAR(255)
pf_phpbb_icq VARCHAR(255)
pf_phpbb_skype VARCHAR(255)
pf_phpbb_twitter VARCHAR(255)
pf_phpbb_youtube VARCHAR(255)
pf_phpbb_website VARCHAR(255)
pf_phpbb_yahoo VARCHAR(255)
```

Pattern: `pf_` + `field_ident` from definition table = column name.

### `phpbb_profile_fields_lang` — Dropdown Option Translations

Stores localized option values for dropdown-type custom fields.

---

## Column Classification: `phpbb_user_notifications`

| Column | Type | Notes |
|--------|------|-------|
| `item_type` | VARCHAR(165) | Notification class name (e.g., "notification.type.post") |
| `item_id` | INT(10) UNSIGNED | Forum/topic ID (0 = global preference) |
| `user_id` | INT(10) UNSIGNED | FK → phpbb_users |
| `method` | VARCHAR(165) | Delivery method class (e.g., "notification.method.email", "notification.method.board") |
| `notify` | TINYINT(1) UNSIGNED, default 1 | Enabled/disabled flag |

### Key Pattern
- UNIQUE: (`item_type`, `item_id`, `user_id`, `method`)
- `item_id=0` means "default for all instances of this type"
- `item_id>0` means "override for specific forum/topic"
- User can have multiple delivery methods per notification type

---

## Column Classification: `phpbb_zebra` (Friend/Foe)

| Column | Type | Notes |
|--------|------|-------|
| `user_id` | INT(10) UNSIGNED | The user who added the relationship |
| `zebra_id` | INT(10) UNSIGNED | The target user |
| `friend` | TINYINT(1) UNSIGNED | 1=friend |
| `foe` | TINYINT(1) UNSIGNED | 1=foe (posts hidden) |

PK: (`user_id`, `zebra_id`) — one row per pair, mutually exclusive friend/foe.

---

## Column Classification: `phpbb_login_attempts`

| Column | Type | Notes |
|--------|------|-------|
| `attempt_ip` | VARCHAR(40) | Source IP |
| `attempt_browser` | VARCHAR(150) | User-Agent |
| `attempt_forwarded_for` | VARCHAR(255) | X-Forwarded-For |
| `attempt_time` | INT(11) UNSIGNED | Timestamp |
| `user_id` | INT(10) UNSIGNED | Target user (0 if unknown) |
| `username` | VARCHAR(255) | Attempted username |
| `username_clean` | VARCHAR(255) | Cleaned version |

### NO PRIMARY KEY. Indexes:
- `att_ip` (`attempt_ip`, `attempt_time`)
- `att_for` (`attempt_forwarded_for`, `attempt_time`)
- `att_time` (`attempt_time`)
- `user_id` (`user_id`)

---

## Column Classification: `phpbb_oauth_accounts`

| Column | Type | Notes |
|--------|------|-------|
| `user_id` | INT(10) UNSIGNED | FK → phpbb_users |
| `provider` | VARCHAR(255) | Provider name ("google", "facebook") |
| `oauth_provider_id` | TEXT | External provider user ID |

PK: (`user_id`, `provider`) — one account per provider per user.

---

## Column Classification: `phpbb_warnings`

| Column | Type | Notes |
|--------|------|-------|
| `warning_id` | MEDIUMINT(8) UNSIGNED AUTO_INCREMENT | PK |
| `user_id` | INT(10) UNSIGNED | Warned user |
| `post_id` | INT(10) UNSIGNED | Warning attached to post (0 if general) |
| `log_id` | INT(10) UNSIGNED | FK → phpbb_log for audit trail |
| `warning_time` | INT(11) UNSIGNED | Timestamp |

---

## Entity Mapping (phpbb_users → New Service Entities)

### UserIdentity Entity
- `user_id`, `user_type`, `username`, `username_clean`, `user_email`, `user_password`, `user_ip`, `user_regdate`, `user_colour`

### UserProfile Entity
- `user_avatar`, `user_avatar_type`, `user_avatar_width`, `user_avatar_height`
- `user_sig`, `user_sig_bbcode_uid`, `user_sig_bbcode_bitfield`
- `user_birthday`, `user_jabber`, `user_rank`
- + All `phpbb_profile_fields_data` columns

### UserPreferences Entity
- `user_lang`, `user_timezone`, `user_dateformat`, `user_style`
- `user_options` (bitfield)
- `user_topic_show_days`, `user_topic_sortby_type`, `user_topic_sortby_dir`
- `user_post_show_days`, `user_post_sortby_type`, `user_post_sortby_dir`
- `user_notify`, `user_notify_pm`, `user_notify_type`
- `user_allow_pm`, `user_allow_viewonline`, `user_allow_viewemail`, `user_allow_massemail`

### UserActivity Entity (Counters)
- `user_posts`, `user_lastvisit`, `user_last_active`, `user_lastmark`
- `user_lastpost_time`, `user_lastpage`, `user_last_search`
- `user_warnings`, `user_last_warning`

### UserAuth Entity (Security/Credentials)
- `user_permissions`, `user_perm_from`, `user_passchg`, `user_login_attempts`
- `user_inactive_reason`, `user_inactive_time`
- `user_actkey`, `user_actkey_expiration`
- `reset_token`, `reset_token_expiration`, `user_newpasswd`
- `user_form_salt`, `user_last_confirm_key`, `user_new`

### UserMessaging Entity (Denormalized Counters)
- `user_new_privmsg`, `user_unread_privmsg`, `user_last_privmsg`
- `user_message_rules`, `user_full_folder`

### UserAdmin Entity (Moderation Meta)
- `user_emailtime`, `user_reminded`, `user_reminded_time`

---

## Unmapped / Controversial Columns

| Column | Issue |
|--------|-------|
| `group_id` on users | Denormalized "default group" — also exists in user_group join |
| `user_permissions` | Compiled ACL blob — should be computed, not stored (or at least cached separately) |
| `user_perm_from` | Permission cloning — specific to legacy admin workflow |
| `user_colour` | Duplicates group_colour — denormalization for perf |
| `user_new_privmsg` / `user_unread_privmsg` | Denormalized counters — should be derived from `phpbb_privmsgs_to` |
| `user_message_rules` | Boolean cached from existence of rows in `phpbb_privmsgs_rules` |
| `user_posts` | Denormalized count — could be SUM from posts table |
| `user_warnings` | Denormalized — could be COUNT from `phpbb_warnings` |
| `user_newpasswd` | Storing hashed pending password — modern approach uses only tokens |
| `user_lastpage` | Privacy concern — stores last visited URL |

---

## Index Analysis

### `phpbb_users` Indexes
| Index | Columns | Optimizes |
|-------|---------|-----------|
| PRIMARY | `user_id` | PK lookup |
| `username_clean` | UNIQUE on `username_clean` | Login by username, uniqueness enforcement |
| `user_birthday` | `user_birthday` | Birthday list queries |
| `user_type` | `user_type` | Filter active/inactive/bot users |
| `user_email` | `user_email` | Email lookup during registration/recovery |

### Missing Indexes (Performance Concerns)
- **No index on `user_lastvisit`** — needed for "inactive users" admin queries
- **No index on `user_last_active`** — needed for "who was online today" 
- **No index on `user_posts`** — needed for sort by post count in memberlist
- **No index on `group_id`** — needed for "users in default group X" queries
- **No composite index on (`user_type`, `username_clean`)** — common combined filter

### `phpbb_user_group` Indexes
| Index | Columns | Notes |
|-------|---------|-------|
| `group_id` | `group_id` | Get members of a group |
| `user_id` | `user_id` | Get groups for a user |
| `group_leader` | `group_leader` | Find all group leaders |

**Missing**: No composite (`group_id`, `user_id`) UNIQUE — allows duplicates!

### `phpbb_sessions` Indexes
| Index | Columns | Notes |
|-------|---------|-------|
| PRIMARY | `session_id` | Session lookup by cookie |
| `session_time` | `session_time` | GC: delete old sessions |
| `session_user_id` | `session_user_id` | Find sessions for user |
| `session_fid` | `session_forum_id` | "Who's online in forum" |

---

## Schema Design Notes

### Gotchas

1. **`phpbb_user_group` has no PK and allows duplicates** — the dump clearly shows duplicate rows. Must handle gracefully during migration.

2. **`user_options` is a bitfield** — cannot be queried per-flag via SQL efficiently. Must decode in PHP.

3. **`phpbb_profile_fields_data` is an EAV hybrid** — columns are added/removed by admin. Schema is dynamic. Any ORM mapping must accommodate this.

4. **Timestamps are Unix INT(11)** — not DATETIME. All time values are seconds since epoch.

5. **`user_type` values are overloaded** — 2 means "ignore" which covers BOTH anonymous user AND bots. Must distinguish via group membership.

6. **`ban_exclude` inversion** — a "ban" row with `ban_exclude=1` means "do NOT ban this entity". Counter-intuitive.

7. **`user_full_folder` negative values** — -1, -2, -3 are sentinel values for different behaviors, positive integers are folder IDs.

8. **`username_clean` is the canonical lookup** — login always normalizes to lowercase clean form. Original `username` preserves display casing.

9. **Session ID is MD5 hex (32 chars)** — not cryptographically strong by modern standards but changing would break all existing sessions.

10. **`user_permissions` is a giant compiled blob** — stored to avoid recomputing permissions on every request. Must be invalidated on any ACL change.

### Denormalization Decisions

| Denormalized Column | Source of Truth | Reason |
|--------------------|--------------------|--------|
| `user_posts` | COUNT from posts table | Performance — avoid COUNT on every profile view |
| `user_warnings` | COUNT from `phpbb_warnings` | Same |
| `user_new_privmsg` | COUNT from `phpbb_privmsgs_to` WHERE pm_new=1 | Same |
| `user_unread_privmsg` | COUNT from `phpbb_privmsgs_to` WHERE pm_unread=1 | Same |
| `user_colour` | `phpbb_groups.group_colour` for default group | Avoid JOIN on every username display |
| `user_message_rules` | EXISTS in `phpbb_privmsgs_rules` | Avoid query just to check |

### Legacy Decisions to Preserve
- `username_clean` UNIQUE index — critical for login security
- `user_type` enum semantics (0,1,2,3) — too many places depend on it
- Session ID format (CHAR(32)) — breaking change too risky
- `ban_exclude` pattern — baked into ACL checking logic everywhere

### Legacy Decisions to Fix
- Add proper composite PK to `phpbb_user_group` (or at least UNIQUE constraint)
- Move `user_permissions` to a cache table (it's the ACL cache, not user data)
- Deprecate `user_newpasswd` — use only token-based reset flow
- Consider moving `user_jabber` to profile_fields_data (deprecated protocol)

---

## Shadow Ban Schema Proposal

### Option A: Extend `phpbb_banlist` (Minimal Change)

Add a `ban_mode` column:

```sql
ALTER TABLE phpbb_banlist 
ADD COLUMN ban_mode TINYINT(1) UNSIGNED NOT NULL DEFAULT 0 
COMMENT '0=hard ban (block), 1=shadow ban (user sees normal, others dont)';
```

**Pros**: Reuses existing ban infrastructure, expiry logic, exclude pattern
**Cons**: "Shadow" is very different from "ban" semantically; ban check code needs splitting

### Option B: Extend `phpbb_users` (Flag on User)

```sql
ALTER TABLE phpbb_users
ADD COLUMN user_shadow_banned TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
ADD COLUMN user_shadow_ban_start INT(11) UNSIGNED NOT NULL DEFAULT 0,
ADD COLUMN user_shadow_ban_end INT(11) UNSIGNED NOT NULL DEFAULT 0,
ADD COLUMN user_shadow_ban_reason VARCHAR(255) NOT NULL DEFAULT '';
```

**Pros**: Simple check per user, no join needed, fast
**Cons**: Only works for user bans (not IP/email), adding more columns to already-bloated table

### Option C: New Table (Recommended)

```sql
CREATE TABLE phpbb_shadow_bans (
    shadow_ban_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT(10) UNSIGNED NOT NULL DEFAULT 0,
    ban_start INT(11) UNSIGNED NOT NULL DEFAULT 0,
    ban_end INT(11) UNSIGNED NOT NULL DEFAULT 0,
    ban_reason VARCHAR(255) NOT NULL DEFAULT '',
    created_by INT(10) UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (shadow_ban_id),
    KEY user_id (user_id),
    KEY ban_end (ban_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;
```

**Plus a flag for fast checking**:
```sql
ALTER TABLE phpbb_users
ADD COLUMN user_shadow_banned TINYINT(1) UNSIGNED NOT NULL DEFAULT 0;
```

**Pros**: 
- Clean separation of concerns
- Can store history (multiple shadow ban records)
- Audit trail via `created_by`
- Denormalized flag on user for fast per-request check
- Expiry logic isolated

**Cons**: Extra JOIN for detailed info (but flag avoids it for hot path)

### Shadow Ban Behavior (to implement in service layer)
1. Banned user's posts appear normal **to them**
2. Posts hidden from everyone else (or shown as "deleted")
3. PMs from shadow-banned user silently not delivered
4. User remains "online" from their perspective
5. No notification to the user about the ban
6. Admin dashboard shows shadow ban status

---

## Summary Statistics

- **Total user-related tables**: 33
- **Total columns in `phpbb_users`**: 68
- **Denormalized counters**: 6
- **Tables without PK**: 5 (`phpbb_user_group`, `phpbb_login_attempts`, `phpbb_forums_watch`, `phpbb_topics_watch`, `phpbb_privmsgs_to`)
- **Dynamic schema table**: 1 (`phpbb_profile_fields_data`)
- **Bitfield columns**: 1 (`user_options` — 12 flags over 18 bits)
