# ACL Database Schema — Complete Findings

**Source**: Live MySQL queries against `phpbb_db` Docker container  
**Date**: 2026-04-18  
**Confidence**: High (100%) — direct database inspection

---

## 1. ACL Constants (from `src/phpbb/common/constants.php`)

```php
// Permission setting values (line 55-57)
define('ACL_NEVER', 0);   // Permission explicitly denied (cannot be overridden)
define('ACL_YES',   1);   // Permission granted
define('ACL_NO',   -1);   // Permission not set (can be overridden by group)

// Table name constants (lines 236-240)
define('ACL_GROUPS_TABLE',       $table_prefix . 'acl_groups');
define('ACL_OPTIONS_TABLE',      $table_prefix . 'acl_options');
define('ACL_ROLES_DATA_TABLE',   $table_prefix . 'acl_roles_data');
define('ACL_ROLES_TABLE',        $table_prefix . 'acl_roles');
define('ACL_USERS_TABLE',        $table_prefix . 'acl_users');
```

**Permission Resolution Logic**: `ACL_YES` (1) grants, `ACL_NEVER` (0) permanently denies (overrides YES from any group), `ACL_NO` (-1) is unset/neutral.

---

## 2. Table Schemas

### 2.1 `phpbb_acl_options` — Permission Definitions

Master list of all available permission flags.

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| auth_option_id | mediumint(8) unsigned | NO | PRI | NULL | auto_increment |
| auth_option | varchar(50) | NO | UNI | | |
| is_global | tinyint(1) unsigned | NO | | 0 | |
| is_local | tinyint(1) unsigned | NO | | 0 | |
| founder_only | tinyint(1) unsigned | NO | | 0 | |

**Indexes**:
- `PRIMARY` on `auth_option_id`
- `UNIQUE auth_option` on `auth_option`

**Row count**: 125 options

**Key semantics**:
- `is_global=1`: Permission applies board-wide (forum_id=0)
- `is_local=1`: Permission applies per-forum
- A permission can be both global AND local (e.g., moderator perms `m_*`)
- `founder_only=1`: Only board founders can have this permission (currently none set)

---

### 2.2 `phpbb_acl_roles` — Role Definitions

Named permission bundles that group multiple options.

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| role_id | mediumint(8) unsigned | NO | PRI | NULL | auto_increment |
| role_name | varchar(255) | NO | | | |
| role_description | text | NO | | NULL | |
| role_type | varchar(10) | NO | MUL | | |
| role_order | smallint(4) unsigned | NO | MUL | 0 | |

**Indexes**:
- `PRIMARY` on `role_id`
- `role_type` index
- `role_order` index

**Row count**: 48 (24 unique roles × 2 duplicates each — appears to be installation artifact)

**Note**: Each role name appears exactly twice with different `role_id` values. This is a database-specific artifact (likely from reinstallation). The role_name/role_description values are language keys, not display text.

---

### 2.3 `phpbb_acl_roles_data` — Role ↔ Permission Mappings

Maps which permissions each role grants.

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| role_id | mediumint(8) unsigned | NO | PRI | 0 | |
| auth_option_id | mediumint(8) unsigned | NO | PRI | 0 | |
| auth_setting | tinyint(2) | NO | | 0 | |

**Indexes**:
- `PRIMARY` composite on (`role_id`, `auth_option_id`)
- `ath_op_id` on `auth_option_id`

**Row count**: 422 mappings

**Semantics**: `auth_setting` uses ACL_YES (1), ACL_NO (-1), ACL_NEVER (0) values.

---

### 2.4 `phpbb_acl_users` — User-Level Permission Assignments

Direct permission assignments to individual users (overrides group permissions).

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| user_id | int(10) unsigned | NO | MUL | 0 | |
| forum_id | mediumint(8) unsigned | NO | | 0 | |
| auth_option_id | mediumint(8) unsigned | NO | MUL | 0 | |
| auth_role_id | mediumint(8) unsigned | NO | MUL | 0 | |
| auth_setting | tinyint(2) | NO | | 0 | |

**Indexes**:
- `user_id` on `user_id`
- `auth_option_id` on `auth_option_id`
- `auth_role_id` on `auth_role_id`

**Row count**: 2

**Note**: No PRIMARY KEY — uses index-only lookups. Permissions are assigned either via `auth_role_id` (whole role) or via `auth_option_id` + `auth_setting` (individual permission). When `auth_role_id > 0`, the `auth_option_id` and `auth_setting` are 0 (role-based). When `auth_option_id > 0`, it's a direct permission setting.

---

### 2.5 `phpbb_acl_groups` — Group-Level Permission Assignments

Permission assignments to groups (primary mechanism for most users).

| Column | Type | Null | Key | Default | Extra |
|--------|------|------|-----|---------|-------|
| group_id | mediumint(8) unsigned | NO | MUL | 0 | |
| forum_id | mediumint(8) unsigned | NO | | 0 | |
| auth_option_id | mediumint(8) unsigned | NO | MUL | 0 | |
| auth_role_id | mediumint(8) unsigned | NO | MUL | 0 | |
| auth_setting | tinyint(2) | NO | | 0 | |

**Indexes**:
- `group_id` on `group_id`
- `auth_opt_id` on `auth_option_id`
- `auth_role_id` on `auth_role_id`

**Row count**: 154

**Same dual-mode as acl_users**: Either `auth_role_id` (role-based) or `auth_option_id` + `auth_setting` (direct).

---

### 2.6 `phpbb_users` — Permission Cache Columns

| Column | Type | Null | Default |
|--------|------|------|---------|
| user_permissions | mediumtext | NO | NULL |
| user_perm_from | mediumint(8) unsigned | NO | 0 |

**`user_permissions`**: Pre-computed packed permission string. Base-36 encoded, newline-separated per forum. Each line represents permissions for one forum context. Length ~252 chars for typical users.

**`user_perm_from`**: user_id whose permissions this user is "switched to" (via `a_switchperm`). 0 = using own permissions.

**Sample data**:
- Anonymous (user_id=1): `00000000000g13ydmo\nhwby9w000000\nhwby9w000000\n...` (252 bytes)
- admin (user_id=2): `zik0zjzik0zjzik0zi\nzik0zjzih7uo\nzik0zjzih7uo\n...` (252 bytes)

**Format**: Each line = one forum context (line 0 = global/forum_id=0, line N = forum_id N). Characters encode groups of permission bits in base-36.

---

## 3. Table Relationships

```
phpbb_acl_options (125 permission definitions)
     │
     ├──► phpbb_acl_roles_data.auth_option_id  ◄── phpbb_acl_roles.role_id
     │         (maps permissions to roles)              (24 unique roles)
     │
     ├──► phpbb_acl_users.auth_option_id   (direct per-user permission)
     │         └── also: auth_role_id → phpbb_acl_roles  (role-based per-user)
     │         └── also: user_id → phpbb_users
     │         └── also: forum_id → phpbb_forums (0 = global)
     │
     └──► phpbb_acl_groups.auth_option_id  (direct per-group permission)
               └── also: auth_role_id → phpbb_acl_roles  (role-based per-group)
               └── also: group_id → phpbb_groups
               └── also: forum_id → phpbb_forums (0 = global)

phpbb_users.user_permissions  (cached computed permission string)
```

**Permission Resolution Flow**:
1. Collect all group permissions for the user's groups (from `acl_groups`)
2. Apply user-specific permissions (from `acl_users`) as overrides
3. Roles are expanded: `auth_role_id` → look up all permissions in `acl_roles_data`
4. Merge: `ACL_YES` from any source grants, `ACL_NEVER` from any source blocks permanently
5. Cache result in `phpbb_users.user_permissions`

---

## 4. Permission Options — Full Catalog

### 4.1 Admin Permissions (`a_*`) — 42 options, all `is_global=1`

| ID | Option | Description |
|----|--------|-------------|
| 49 | `a_` | Can access admin panel (base permission) |
| 50 | `a_aauth` | Can manage admin permissions |
| 51 | `a_attach` | Can manage attachment settings |
| 52 | `a_authgroups` | Can manage group permissions |
| 53 | `a_authusers` | Can manage user permissions |
| 54 | `a_backup` | Can backup/restore database |
| 55 | `a_ban` | Can manage bans |
| 56 | `a_bbcode` | Can manage BBCode |
| 57 | `a_board` | Can manage board settings |
| 58 | `a_bots` | Can manage bots |
| 59 | `a_clearlogs` | Can clear logs |
| 60 | `a_email` | Can manage email settings |
| 61 | `a_extensions` | Can manage extensions |
| 62 | `a_fauth` | Can manage forum permissions |
| 63 | `a_forum` | Can manage forums |
| 64 | `a_forumadd` | Can add forums |
| 65 | `a_forumdel` | Can delete forums |
| 66 | `a_group` | Can manage groups |
| 67 | `a_groupadd` | Can add groups |
| 68 | `a_groupdel` | Can delete groups |
| 69 | `a_icons` | Can manage icons/smilies |
| 70 | `a_jabber` | Can manage Jabber settings |
| 71 | `a_language` | Can manage language packs |
| 72 | `a_mauth` | Can manage moderator permissions |
| 73 | `a_modules` | Can manage modules |
| 74 | `a_names` | Can manage usernames |
| 75 | `a_phpinfo` | Can view phpinfo |
| 76 | `a_profile` | Can manage profile fields |
| 77 | `a_prune` | Can prune forums |
| 78 | `a_ranks` | Can manage ranks |
| 79 | `a_reasons` | Can manage report reasons |
| 80 | `a_roles` | Can manage roles |
| 81 | `a_search` | Can manage search settings |
| 82 | `a_server` | Can manage server settings |
| 83 | `a_styles` | Can manage styles |
| 84 | `a_switchperm` | Can switch user permissions |
| 85 | `a_uauth` | Can manage user permissions |
| 86 | `a_user` | Can manage users |
| 87 | `a_userdel` | Can delete users |
| 88 | `a_viewauth` | Can view permissions |
| 89 | `a_viewlogs` | Can view logs |
| 90 | `a_words` | Can manage word censors |

### 4.2 Forum Permissions (`f_*`) — 33 options, all `is_local=1`

| ID | Option | Description |
|----|--------|-------------|
| 1 | `f_` | Can see forum (base permission) |
| 2 | `f_announce` | Can post announcements |
| 3 | `f_announce_global` | Can post global announcements |
| 4 | `f_attach` | Can attach files |
| 5 | `f_bbcode` | Can use BBCode |
| 6 | `f_bump` | Can bump topics |
| 7 | `f_delete` | Can delete own posts |
| 8 | `f_download` | Can download attachments |
| 9 | `f_edit` | Can edit own posts |
| 10 | `f_email` | Can email topic |
| 11 | `f_flash` | Can use Flash BBCode |
| 12 | `f_icons` | Can use topic icons |
| 13 | `f_ignoreflood` | Can ignore flood limit |
| 14 | `f_img` | Can use [img] BBCode |
| 15 | `f_list` | Can see forum in list |
| 16 | `f_list_topics` | Can see topics in forum |
| 17 | `f_noapprove` | Can post without approval |
| 18 | `f_poll` | Can create polls |
| 19 | `f_post` | Can start new topics |
| 20 | `f_postcount` | Can increment post counter |
| 21 | `f_print` | Can print topics |
| 22 | `f_read` | Can read forum |
| 23 | `f_reply` | Can reply to topics |
| 24 | `f_report` | Can report posts |
| 25 | `f_search` | Can search forum |
| 26 | `f_sigs` | Can use signatures |
| 27 | `f_smilies` | Can use smilies |
| 33 | `f_softdelete` | Can soft-delete own posts |
| 28 | `f_sticky` | Can post sticky topics |
| 29 | `f_subscribe` | Can subscribe to topics |
| 30 | `f_user_lock` | Can lock own topics |
| 31 | `f_vote` | Can vote in polls |
| 32 | `f_votechg` | Can change vote |

### 4.3 Moderator Permissions (`m_*`) — 15 options, mixed global/local

| ID | Option | is_global | is_local | Description |
|----|--------|-----------|----------|-------------|
| 34 | `m_` | 1 | 1 | Is moderator (base) |
| 35 | `m_approve` | 1 | 1 | Can approve posts |
| 46 | `m_ban` | 1 | 0 | Can ban users (global only) |
| 36 | `m_chgposter` | 1 | 1 | Can change post author |
| 37 | `m_delete` | 1 | 1 | Can delete posts |
| 38 | `m_edit` | 1 | 1 | Can edit posts |
| 39 | `m_info` | 1 | 1 | Can view post details |
| 40 | `m_lock` | 1 | 1 | Can lock topics |
| 41 | `m_merge` | 1 | 1 | Can merge topics |
| 42 | `m_move` | 1 | 1 | Can move topics |
| 47 | `m_pm_report` | 1 | 0 | Can handle PM reports (global only) |
| 43 | `m_report` | 1 | 1 | Can handle reports |
| 45 | `m_softdelete` | 1 | 1 | Can soft-delete posts |
| 44 | `m_split` | 1 | 1 | Can split topics |
| 48 | `m_warn` | 1 | 0 | Can warn users (global only) |

### 4.4 User Permissions (`u_*`) — 35 options, all `is_global=1`

| ID | Option | Description |
|----|--------|-------------|
| 91 | `u_` | Has user permissions (base) |
| 92 | `u_attach` | Can attach files |
| 93 | `u_chgavatar` | Can change avatar |
| 94 | `u_chgcensors` | Can change censors display |
| 95 | `u_chgemail` | Can change email |
| 96 | `u_chggrp` | Can change default group |
| 97 | `u_chgname` | Can change username |
| 98 | `u_chgpasswd` | Can change password |
| 99 | `u_chgprofileinfo` | Can change profile info |
| 100 | `u_download` | Can download attachments |
| 101 | `u_emoji` | Can use emoji |
| 102 | `u_hideonline` | Can hide online status |
| 103 | `u_ignoreflood` | Can ignore flood limit |
| 104 | `u_masspm` | Can mass PM users |
| 105 | `u_masspm_group` | Can mass PM groups |
| 106 | `u_pm_attach` | Can attach files in PM |
| 107 | `u_pm_bbcode` | Can use BBCode in PM |
| 108 | `u_pm_delete` | Can delete PMs |
| 109 | `u_pm_download` | Can download PM attachments |
| 110 | `u_pm_edit` | Can edit PMs |
| 111 | `u_pm_emailpm` | Can email PMs |
| 112 | `u_pm_flash` | Can use Flash in PM |
| 113 | `u_pm_forward` | Can forward PMs |
| 114 | `u_pm_img` | Can use images in PM |
| 115 | `u_pm_printpm` | Can print PMs |
| 116 | `u_pm_smilies` | Can use smilies in PM |
| 117 | `u_readpm` | Can read PMs |
| 118 | `u_savedrafts` | Can save drafts |
| 119 | `u_search` | Can use search |
| 120 | `u_sendemail` | Can send email to users |
| 121 | `u_sendim` | Can send instant messages |
| 122 | `u_sendpm` | Can send PMs |
| 123 | `u_sig` | Can use signature |
| 124 | `u_viewonline` | Can view who is online |
| 125 | `u_viewprofile` | Can view user profiles |

---

## 5. Roles — Full Catalog (24 unique roles)

### 5.1 Admin Roles (`a_`)

| role_id | Role Name | Permissions |
|---------|-----------|-------------|
| 1 | ROLE_ADMIN_STANDARD | 30 of 42 admin perms — excludes sensitive: `a_aauth`, `a_backup`, `a_email`, `a_jabber`, `a_language`, `a_modules`, `a_phpinfo`, `a_profile`, `a_roles`, `a_search`, `a_server`, `a_styles` |
| 4 | ROLE_ADMIN_FULL | All 42 admin permissions |
| 2 | ROLE_ADMIN_FORUM | Forum management subset (a_forum, a_forumadd, a_forumdel, a_fauth, a_prune) |
| 3 | ROLE_ADMIN_USERGROUP | User/group management subset (a_user, a_userdel, a_group, a_groupadd, a_groupdel, etc.) |

### 5.2 Forum Roles (`f_`)

| role_id | Role Name | Key Permissions |
|---------|-----------|-----------------|
| 16 | ROLE_FORUM_NOACCESS | No permissions granted |
| 17 | ROLE_FORUM_READONLY | 8 perms: f_, f_download, f_list, f_list_topics, f_print, f_read, f_search, f_subscribe |
| 18 | ROLE_FORUM_LIMITED | 21 perms: readonly + post, reply, edit, BBCode, images, smilies, vote, report, softdelete |
| 22 | ROLE_FORUM_LIMITED_POLLS | Limited + f_poll |
| 15 | ROLE_FORUM_STANDARD | 26 perms: limited + bump, sticky, delete, attach, icons, subscribe, votechg (no announce, flash, ignoreflood) |
| 21 | ROLE_FORUM_POLLS | Standard + f_poll |
| 14 | ROLE_FORUM_FULL | All 33 forum permissions |
| 20 | ROLE_FORUM_ONQUEUE | Standard but WITHOUT f_noapprove (posts require approval) |
| 19 | ROLE_FORUM_BOT | Minimal: f_, f_download, f_list, f_list_topics, f_print, f_read, f_search |
| 24 | ROLE_FORUM_NEW_MEMBER | Similar to limited but with restrictions |

### 5.3 Moderator Roles (`m_`)

| role_id | Role Name | Key Permissions |
|---------|-----------|-----------------|
| 11 | ROLE_MOD_STANDARD | Most mod perms except m_ban, m_chgposter |
| 12 | ROLE_MOD_SIMPLE | Edit, delete, lock, report only |
| 10 | ROLE_MOD_FULL | All 15 moderator permissions |
| 13 | ROLE_MOD_QUEUE | Approve queue management only |

### 5.4 User Roles (`u_`)

| role_id | Role Name | Key Permissions |
|---------|-----------|-----------------|
| 6 | ROLE_USER_STANDARD | 29 of 35 user perms — excludes: u_chggrp, u_chgname, u_ignoreflood, u_pm_flash, u_pm_forward, u_viewonline |
| 7 | ROLE_USER_LIMITED | Subset without PM-heavy features |
| 5 | ROLE_USER_FULL | All 35 user permissions |
| 8 | ROLE_USER_NOPM | Standard minus all PM permissions |
| 9 | ROLE_USER_NOAVATAR | Standard minus u_chgavatar |
| 23 | ROLE_USER_NEW_MEMBER | Reduced permissions for newly registered |

---

## 6. Group Permission Assignments (live data)

### Default Groups and Their Permissions

| Group | group_id | Global Roles | Forum Roles | Notes |
|-------|----------|-------------|-------------|-------|
| **ADMINISTRATORS** | 5 | ROLE_ADMIN_STANDARD (1), ROLE_USER_FULL (5) | ROLE_FORUM_FULL (14) + ROLE_MOD_FULL (10) per forum | Full access everywhere |
| **GLOBAL_MODERATORS** | 4 | ROLE_MOD_FULL (10), ROLE_USER_FULL (5) | ROLE_FORUM_POLLS (21) per forum | Moderate all forums |
| **REGISTERED** | 2 | ROLE_USER_STANDARD (6) | ROLE_FORUM_STANDARD (15) per forum | Normal users |
| **REGISTERED_COPPA** | 3 | ROLE_USER_STANDARD (6) | ROLE_FORUM_STANDARD (15) per forum | Under-13 users (same as registered) |
| **NEWLY_REGISTERED** | 7 | ROLE_USER_NEW_MEMBER (23) | ROLE_FORUM_NEW_MEMBER (24) per forum | Restricted new users |
| **GUESTS** | 1 | Direct: u_ (1), u_download (1), u_search (1) | ROLE_FORUM_READONLY (17) per forum | Read-only, individual permissions |
| **BOTS** | 6 | (none) | ROLE_FORUM_BOT (19) per forum | Minimal access |

**Pattern**: Groups use `forum_id=0` for global permissions and per-forum entries (forum_id 1-18) for local permissions. Each forum gets the same role assignment.

### Admin User Direct Permissions (user_id=2)

| user_id | forum_id | auth_option_id | auth_role_id | auth_setting |
|---------|----------|----------------|--------------|--------------|
| 2 | 0 | 0 | 5 (ROLE_USER_FULL) | 0 |
| 2 | 0 | 0 | 5 (ROLE_USER_FULL) | 0 |

**Note**: Admin has ROLE_USER_FULL assigned directly at user level in addition to group-based permissions. This is a redundant backup ensuring admin always has full user permissions.

---

## 7. Permission Assignment Modes

phpBB supports two parallel modes for assigning permissions in both `acl_users` and `acl_groups`:

### Mode 1: Role-Based Assignment
```
auth_role_id = <role_id>    (e.g., 14 for ROLE_FORUM_FULL)
auth_option_id = 0           (unused)
auth_setting = 0             (unused — settings come from acl_roles_data)
```
The role_id is expanded via `acl_roles_data` join during permission resolution.

### Mode 2: Direct Permission Assignment
```
auth_role_id = 0             (unused)
auth_option_id = <option_id> (e.g., 119 for u_search)
auth_setting = 1 or -1       (ACL_YES or ACL_NO)
```
Used for fine-grained individual overrides (e.g., GUESTS get just 3 specific user permissions).

---

## 8. user_permissions Cache Format

**Column**: `phpbb_users.user_permissions` (mediumtext)

**Format**: Newline-delimited string where each line represents a forum context:
- Line 0 → global permissions (forum_id=0)
- Line N → permissions for forum_id=N

**Encoding**: Base-36 (0-9, a-z) packed bitfield. Each character encodes multiple permission bits.

**Sample** (admin): `zik0zjzik0zjzik0zi` (global line — near-max values = most permissions set)  
**Sample** (anonymous): `00000000000g13ydmo` (global line — mostly zeros with some flags)

**`user_perm_from`**: When non-zero, indicates the user is viewing the board with another user's permissions (admin `a_switchperm` feature). 0 = own permissions.

**Cache invalidation**: Permission cache is cleared (set to empty string) when:
- User/group permissions are changed
- Roles are modified
- User group membership changes
- Triggered via `$auth->acl_clear_prefetch()`

---

## 9. Summary Statistics

| Table | Row Count | Description |
|-------|-----------|-------------|
| phpbb_acl_options | 125 | Permission definitions (42 admin + 33 forum + 15 mod + 35 user) |
| phpbb_acl_roles | 48 | 24 unique roles (duplicated in this install) |
| phpbb_acl_roles_data | 422 | Role-to-permission mappings |
| phpbb_acl_users | 2 | User-specific permission overrides |
| phpbb_acl_groups | 154 | Group permission assignments (7 groups × ~22 entries each) |

---

## 10. Key Design Observations

1. **No foreign keys**: All relationships are implicit (no FOREIGN KEY constraints). Referential integrity is maintained by application code.

2. **No primary key on acl_users/acl_groups**: These tables use non-unique indexes only, allowing multiple assignments per user/group/forum combination.

3. **Dual assignment mode**: Both role-based and direct permission assignments coexist in the same tables, distinguished by whether `auth_role_id` or `auth_option_id` is non-zero.

4. **Computed cache**: The `user_permissions` column in `phpbb_users` is a performance optimization — pre-computed packed permission string to avoid complex JOINs on every page load.

5. **Role duplication**: All 24 roles appear twice in this installation (48 total rows), likely from a reinstallation or migration artifact.

6. **Global vs Local split**: Admin (`a_*`) and User (`u_*`) permissions are global-only. Forum (`f_*`) permissions are local-only. Moderator (`m_*`) permissions are both — can be assigned globally or per-forum.
