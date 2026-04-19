# PM Filtering Rules Engine — Comprehensive Findings

## Overview

phpBB implements a per-user PM filtering/routing rules engine that automatically processes incoming private messages. Rules are evaluated at display time (when the user visits their PM folder), not at message delivery time. The system is defined entirely in procedural PHP across two main files.

**Key source files:**
- `src/phpbb/common/functions_privmsgs.php` — Constants, rule evaluation (`check_rule()`), message placement (`place_pm_into_folder()`)
- `src/phpbb/common/ucp/ucp_pm_options.php` — Rule CRUD UI (`message_options()`, `show_defined_rules()`)
- `src/phpbb/common/ucp/ucp_pm.php` — Trigger point that calls `place_pm_into_folder()`

---

## 1. Rule Schema (`phpbb_privmsgs_rules`)

**Source:** `phpbb_dump.sql:2827-2841`

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
);
```

### Column semantics:

| Column | Purpose |
|--------|---------|
| `rule_id` | Auto-increment PK |
| `user_id` | Owning user — rules are always per-user |
| `rule_check` | What to check (subject/sender/message/status/to) — maps to `CHECK_*` constants |
| `rule_connection` | How to compare — maps to `RULE_*` constants |
| `rule_string` | Text value for text-based comparisons (varchar 255) |
| `rule_user_id` | User ID for `RULE_IS_USER` comparisons |
| `rule_group_id` | Group ID for `RULE_IS_GROUP` comparisons |
| `rule_action` | What to do when matched — maps to `ACTION_*` constants |
| `rule_folder_id` | Target folder for `ACTION_PLACE_INTO_FOLDER` |

---

## 2. Check Types (`rule_check` — CHECK_* constants)

**Source:** `functions_privmsgs.php:51-55`

| Constant | Value | Description | Fields checked against |
|----------|-------|-------------|----------------------|
| `CHECK_SUBJECT` | 1 | Check message subject | `message_subject` |
| `CHECK_SENDER` | 2 | Check message sender | `username`, `friend`, `foe`, `author_id`, `author_in_group` |
| `CHECK_MESSAGE` | 3 | Check message body | `message_text` |
| `CHECK_STATUS` | 4 | Check message status | `pm_replied`, `pm_forwarded` |
| `CHECK_TO` | 5 | Check recipients | `to_address`, `bcc_address`, `user_in_group` |

---

## 3. Connection Operators (`rule_connection` — RULE_* constants)

**Source:** `functions_privmsgs.php:32-47`

| Constant | Value | Available for CHECK_ types | Condition type | Description |
|----------|-------|---------------------------|----------------|-------------|
| `RULE_IS_LIKE` | 1 | SUBJECT, SENDER, MESSAGE | text | Case-insensitive substring match via `preg_match()` |
| `RULE_IS_NOT_LIKE` | 2 | SUBJECT, SENDER, MESSAGE | text | Negated case-insensitive substring match |
| `RULE_IS` | 3 | SUBJECT, SENDER, MESSAGE | text | Exact equality (`==`) |
| `RULE_IS_NOT` | 4 | SUBJECT, SENDER, MESSAGE | text | Not equal (`!=`) |
| `RULE_BEGINS_WITH` | 5 | SUBJECT, SENDER | text | Case-insensitive regex `^` anchor match |
| `RULE_ENDS_WITH` | 6 | SUBJECT, SENDER | text | Case-insensitive regex `$` anchor match |
| `RULE_IS_FRIEND` | 7 | SENDER | none (boolean) | Sender is on user's friend list (zebra table `friend=1`) |
| `RULE_IS_FOE` | 8 | SENDER | none (boolean) | Sender is on user's foe list (zebra table `foe=1`) |
| `RULE_IS_USER` | 9 | SENDER | user | Sender's `author_id` matches `rule_user_id` |
| `RULE_IS_GROUP` | 10 | SENDER | group | Sender's group memberships contain `rule_group_id` |
| `RULE_ANSWERED` | 11 | STATUS | none (boolean) | Message has been replied to (`pm_replied == 1`) |
| `RULE_FORWARDED` | 12 | STATUS | none (boolean) | Message has been forwarded (`pm_forwarded == 1`) |
| _(13 skipped)_ | — | — | — | Not defined — gap in constants |
| `RULE_TO_GROUP` | 14 | TO | none (boolean) | Message was sent to a usergroup the recipient belongs to |
| `RULE_TO_ME` | 15 | TO | none (boolean) | User is explicitly in `to` or `bcc` address list |

**Note:** Constants 13 is not defined (gap). Total defined: 14 connection operators, not 15.

### Valid check-connection matrix (`$global_privmsgs_rules`)

**Source:** `functions_privmsgs.php:60-96`

```
CHECK_SUBJECT → IS_LIKE, IS_NOT_LIKE, IS, IS_NOT, BEGINS_WITH, ENDS_WITH
CHECK_SENDER  → IS_LIKE, IS_NOT_LIKE, IS, IS_NOT, BEGINS_WITH, ENDS_WITH, IS_FRIEND, IS_FOE, IS_USER, IS_GROUP
CHECK_MESSAGE → IS_LIKE, IS_NOT_LIKE, IS, IS_NOT
CHECK_STATUS  → ANSWERED, FORWARDED
CHECK_TO      → TO_GROUP, TO_ME
```

### Condition types (`$global_rule_conditions`)

**Source:** `functions_privmsgs.php:102-111`

Determines what UI input is shown for each rule:

| Rule | Condition | UI element |
|------|-----------|------------|
| RULE_IS_LIKE through RULE_ENDS_WITH | `text` | Free text input |
| RULE_IS_USER | `user` | Username picker |
| RULE_IS_GROUP | `group` | Group dropdown |
| All others (FRIEND/FOE/ANSWERED/FORWARDED/TO_GROUP/TO_ME) | none | No condition input needed |

---

## 4. Actions (`rule_action` — ACTION_* constants)

**Source:** `functions_privmsgs.php:48-50`, check_rule return logic at lines 333-376

| Constant | Value | Description | Details |
|----------|-------|-------------|---------|
| `ACTION_PLACE_INTO_FOLDER` | 1 | Move message to specified folder | Uses `rule_folder_id`. First folder-move action wins (subsequent folder moves for same message are skipped). |
| `ACTION_MARK_AS_READ` | 2 | Mark message as read | Sets `pm_unread = 0` in `phpbb_privmsgs_to` |
| `ACTION_MARK_AS_IMPORTANT` | 3 | Toggle important/starred flag | Toggles `pm_marked` flag (`1 - pm_marked`) |
| `ACTION_DELETE_MESSAGE` | 4 | Delete message | **Protected**: Messages from admins/moderators CANNOT be auto-deleted. `check_rule()` verifies the sender's permissions and returns `false` if sender has `a_` or `m_` or global `m_` ACL. |

### Delete action protection detail

**Source:** `functions_privmsgs.php:356-376`

```php
case ACTION_DELETE_MESSAGE:
    // Check for admins/mods - users are not allowed to remove those messages...
    $sql = 'SELECT user_id, user_type, user_permissions
        FROM ' . USERS_TABLE . '
        WHERE user_id = ' . (int) $message_row['author_id'];
    // ...
    $auth2 = new \phpbb\auth\auth();
    $auth2->acl($userdata);
    if (!$auth2->acl_get('a_') && !$auth2->acl_get('m_') && !$auth2->acl_getf_global('m_'))
    {
        return array('action' => $rule_row['rule_action'], ...);
    }
    return false; // Admin/mod message - rule does not apply
```

---

## 5. `check_rule()` Algorithm

**Source:** `functions_privmsgs.php:257-380`

**Signature:** `function check_rule(&$rules, &$rule_row, &$message_row, $user_id)`

### Parameters:
- `$rules` — The `$global_privmsgs_rules` matrix (check → connection → field mapping)
- `$rule_row` — Single row from `phpbb_privmsgs_rules` table
- `$message_row` — Enriched message data (PM row + username + friend/foe status + groups)
- `$user_id` — The recipient user ID

### Algorithm:

1. **Validate rule exists in matrix**: If `$rules[$rule_row['rule_check']][$rule_row['rule_connection']]` is not set, return `false`.

2. **Get check array**: Lookup which message field to compare: `$check_ary = $rules[$rule_check][$rule_connection]`

3. **Get the actual value to check**: `$check0 = $message_row[$check_ary['check0']]`

4. **Evaluate connection operator** (switch on `rule_connection`):
   - **RULE_IS_LIKE**: `preg_match("/" . preg_quote(rule_string) . "/i", check0)` — case-insensitive substring
   - **RULE_IS_NOT_LIKE**: negated version of above
   - **RULE_IS**: exact equality `$check0 == $rule_row['rule_string']`
   - **RULE_IS_NOT**: inequality `$check0 != $rule_row['rule_string']`
   - **RULE_BEGINS_WITH**: `preg_match("/^" . preg_quote(rule_string) . "/i", check0)`
   - **RULE_ENDS_WITH**: `preg_match("/" . preg_quote(rule_string) . "$/i", check0)`
   - **RULE_IS_FRIEND / RULE_IS_FOE / RULE_ANSWERED / RULE_FORWARDED**: `$check0 == 1` (boolean flag)
   - **RULE_IS_USER**: `$check0 == $rule_row['rule_user_id']`
   - **RULE_IS_GROUP**: `in_array($rule_row['rule_group_id'], $check0)` — check0 is array of group IDs
   - **RULE_TO_GROUP**: Checks both `to` and `bcc` address arrays for group prefix `g_`
   - **RULE_TO_ME**: Checks both `to` and `bcc` address arrays for user prefix `u_`

5. **If no match**: return `false`

6. **If match, evaluate action**:
   - `ACTION_PLACE_INTO_FOLDER`: return `{action, folder_id}`
   - `ACTION_MARK_AS_READ` / `ACTION_MARK_AS_IMPORTANT`: return `{action, pm_unread, pm_marked}`
   - `ACTION_DELETE_MESSAGE`: Check sender permissions first (admin/mod protection), then return action or `false`

---

## 6. `place_pm_into_folder()` Flow — Full Algorithm

**Source:** `functions_privmsgs.php:416-770`

**Signature:** `function place_pm_into_folder(&$global_privmsgs_rules, $release = false)`

### Trigger Point

**Source:** `ucp_pm.php:249-253`

Called when user visits PM folder view, **only if `user_new_privmsg > 0`**:

```php
if ($user->data['user_new_privmsg'] && ($action == 'view_folder' || $action == 'view_message'))
{
    $return = place_pm_into_folder($global_privmsgs_rules, $release);
}
```

### Detailed Flow:

#### Step 1: Early exit
If `user_new_privmsg == 0`, return immediately with `{not_moved: 0, removed: 0}`.

#### Step 2: Release held messages (optional)
If `$release == true`, move all messages from `PRIVMSGS_HOLD_BOX` to `PRIVMSGS_NO_BOX` (re-process them).

#### Step 3: Retrieve unplaced messages
Query all messages in `PRIVMSGS_NO_BOX` (folder_id = NO_BOX) for the user, joining with `phpbb_privmsgs` and `phpbb_users` to get author info.

#### Step 4: Branch — rules enabled or not

**If `user_message_rules == 0`** (no rules):
- All messages get `action => false` (no special handling, will land in INBOX).

**If `user_message_rules == 1`** (rules exist):

1. **Load ALL user rules**: `SELECT * FROM phpbb_privmsgs_rules WHERE user_id = $user_id`

2. **Load zebra (friend/foe) data**: `SELECT zebra_id, friend, foe FROM phpbb_zebra WHERE user_id = $user_id`

3. **Build enriched check rows** for each message:
   ```php
   array_merge($row, array(
       'to'              => explode(':', $row['to_address']),
       'bcc'             => explode(':', $row['bcc_address']),
       'friend'          => zebra[$author_id]['friend'] ?? 0,
       'foe'             => zebra[$author_id]['foe'] ?? 0,
       'user_in_group'   => $user->data['group_id'],
       'author_in_group' => array()
   ));
   ```

4. **Load author group memberships**: Query `phpbb_user_group` for all message authors.

5. **For each message, iterate ALL rules** (in `rule_id ASC` order — insertion order):
   ```php
   foreach ($user_rules as $rule_row) {
       if (($action = check_rule(...)) !== false) {
           $is_match = true;
           $action_ary[$msg_id][] = $action;
       }
   }
   ```
   
   **CRITICAL: ALL-MATCH semantics** — Every matching rule's action is collected. Multiple rules can match the same message. This is NOT first-match-stops.

6. If no rule matched, add `action => false` (message goes to inbox).

#### Step 5: Execute collected actions

For each message, iterate its action array:

1. **Folder placement**: Only the **FIRST** `ACTION_PLACE_INTO_FOLDER` is applied. Subsequent folder moves for the same message are skipped (`$folder_action` flag).
2. **Mark as read**: Adds to `$unread_ids` batch.
3. **Delete message**: Adds to `$delete_ids` batch.
4. **Mark important**: Adds to `$important_ids` batch.
5. **Default**: If no folder action was applied AND message wasn't deleted → move to INBOX.

**Action execution order** (explicit comment in code: "Do not change the order of processing"):
1. Delete messages (`delete_pm()`)
2. Mark messages as read (`UPDATE pm_unread = 0`)
3. Mark messages as important (`UPDATE pm_marked = 1 - pm_marked`)
4. Move into folders (with full-folder handling)

#### Step 6: Full-folder handling

When moving messages to a folder that would exceed `message_limit`:

- **`FULL_FOLDER_DELETE`**: Delete oldest messages (by `msg_id ASC`) from destination folder to make room.
- **`FULL_FOLDER_HOLD`**: Move messages to `PRIVMSGS_HOLD_BOX` instead (held for later release).
- **Numeric folder ID**: Redirect overflow messages to a different folder.
- **`FULL_FOLDER_NONE`**: Falls back to global config `full_folder_action`.

#### Step 7: Finalize
- Move processed outbox messages to sentbox.
- Call `update_pm_counts()` to recalculate `user_unread_privmsg` and `user_new_privmsg`.
- Return count of not-moved (held) and removed messages.

---

## 7. Rule CRUD — User Interface

**Source:** `ucp_pm_options.php` — `message_options()` function

### Create Rule (lines 293-330)

- Triggered by `$_POST['add_rule']`
- CSRF protected: `check_form_key('ucp_pm_options')`
- Collects: `check_option`, `rule_option`, `cond_option`, `action_option` (format: `action_id|folder_id`), `rule_string`, `rule_user_id`, `rule_group_id`
- **Duplicate check**: Queries for exact match on all fields before inserting.
- Inserts into `phpbb_privmsgs_rules`
- Sets `user_message_rules = 1` on user record to enable rule evaluation

### Delete Rule (lines 332-370)

- Triggered by `$_POST['delete_rule']`
- Uses `confirm_box()` for confirmation
- Deletes single rule by `rule_id` AND `user_id`
- After deletion, checks if any rules remain. If not, resets `user_message_rules = 0` on user record

### No Edit/Update

There is **no rule edit functionality**. Users must delete and re-create rules. No reordering UI exists.

### Display Rules (`show_defined_rules()`)

**Source:** `ucp_pm_options.php:861-892`

- Queries all rules `ORDER BY rule_id ASC`
- Renders each rule's check type, connection operator, string value, action, and folder name

---

## 8. Rule Limits

**Source:** `ucp_pm_options.php:316-323`

```php
$sql = 'SELECT COUNT(rule_id) AS num_rules
    FROM ' . PRIVMSGS_RULES_TABLE . '
    WHERE user_id = ' . (int) $user->data['user_id'];
$result = $db->sql_query($sql);
$num_rules = (int) $db->sql_fetchfield('num_rules');

if ($num_rules >= 5000)
{
    trigger_error('RULE_LIMIT_REACHED');
}
```

**Max rules per user: 5000** — Hardcoded, not configurable.

---

## 9. Rule Evaluation Timing

Rules are **NOT applied at message receive/delivery time**. They are applied **lazily, on-demand** when the user views their PM folder.

**Source:** `ucp_pm.php:249-253`

```php
if ($user->data['user_new_privmsg'] && ($action == 'view_folder' || $action == 'view_message'))
{
    $return = place_pm_into_folder($global_privmsgs_rules, $release);
}
```

Messages initially land in `PRIVMSGS_NO_BOX` (unplaced) with `pm_new = 1`. When the user next visits their PM, `place_pm_into_folder()` processes all unplaced messages through their rules.

**No retroactive application**: Rules only apply to NEW unplaced messages. Changing rules does not re-process already-placed messages. The only way to re-process is the `release` mechanism (moves HOLD_BOX back to NO_BOX for reprocessing).

---

## 10. Global Rules vs User Rules

**There are NO admin-level / global rules.** Every rule belongs to a specific `user_id`. The system is entirely per-user.

The only "global" aspect is:
- `full_folder_action` config setting — what happens site-wide when user hasn't set their own full-folder preference (`FULL_FOLDER_NONE`)
- The admin can configure `pm_max_boxes` — maximum custom folders per user

---

## 11. Foe/Friend List Integration (Zebra Table)

**Source:** `phpbb_dump.sql:4165-4173`

```sql
CREATE TABLE `phpbb_zebra` (
  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
  `zebra_id` int(10) unsigned NOT NULL DEFAULT 0,
  `friend` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `foe` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`,`zebra_id`)
);
```

### How IS_FRIEND / IS_FOE works:

**Source:** `functions_privmsgs.php:490-505`

During `place_pm_into_folder()`, for each message the system:

1. Loads the user's complete zebra list: `SELECT zebra_id, friend, foe FROM phpbb_zebra WHERE user_id = $user_id`
2. Enriches each message row:
   ```php
   'friend' => (isset($zebra[$row['author_id']])) ? $zebra[$row['author_id']]['friend'] : 0,
   'foe'    => (isset($zebra[$row['author_id']])) ? $zebra[$row['author_id']]['foe'] : 0,
   ```
3. In `check_rule()`, `RULE_IS_FRIEND` and `RULE_IS_FOE` simply check: `$check0 == 1`

So: The `friend`/`foe` columns in `phpbb_zebra` are boolean flags. A `zebra_id` (the other user) can be marked as friend (1) or foe (1). If the message author's `zebra_id` entry has `foe=1`, the `IS_FOE` rule matches.

**Important**: `RULE_IS_FRIEND` and `RULE_IS_FOE` are only available under `CHECK_SENDER` — they check the message **author's** friend/foe status relative to the rule-owning user.

### UI availability note:

**Source:** `ucp_pm_options.php:693-702`

The `IS_FRIEND` and `IS_FOE` rule options are only shown in the UI if the corresponding UCP module is loaded:
```php
if (!$module->loaded('zebra', 'friends'))
{
    $exclude[RULE_IS_FRIEND] = true;
}
if (!$module->loaded('zebra', 'foes'))
{
    $exclude[RULE_IS_FOE] = true;
}
```

---

## 12. Folder Management (Related)

**Source:** `ucp_pm_options.php:82-275`

Folders are managed in the same options page:
- **Create folder**: Limited by `$config['pm_max_boxes']`
- **Rename folder**: Standard name update
- **Delete folder**: Moves messages to another folder OR deletes them. Also updates any rules referencing the deleted folder to point to the new destination (or INBOX if messages were deleted).

Rule-folder dependency cleanup on folder delete:
```php
$sql = 'UPDATE ' . PRIVMSGS_RULES_TABLE . ' SET rule_folder_id = ';
$sql .= ($remove_action == 1) ? $move_to : PRIVMSGS_INBOX;
$sql .= ' WHERE rule_folder_id = ' . $remove_folder_id;
```

---

## 13. `user_message_rules` Flag

**Source:** Various locations in `ucp_pm_options.php`

The `users.user_message_rules` column is a performance optimization flag:
- Set to `1` when a rule is added (line 328)
- Set to `0` when the last rule is deleted (line 405)
- Checked in `place_pm_into_folder()` to skip the entire rule evaluation machinery if no rules exist

---

## 14. Architectural Summary

```
Message arrives → stored in phpbb_privmsgs_to with folder_id = PRIVMSGS_NO_BOX, pm_new = 1
                → user_new_privmsg incremented on user record

User visits PM  → ucp_pm.php checks user_new_privmsg > 0
                → calls place_pm_into_folder()
                    → if user_message_rules == 0: all messages → INBOX
                    → if user_message_rules == 1:
                        → load ALL user rules (ORDER BY rule_id)
                        → load zebra (friend/foe list)
                        → load author group memberships
                        → for each unplaced message:
                            → for each rule (ALL-MATCH):
                                → check_rule(): evaluate condition
                                → if match: collect action
                        → batch execute actions:
                            1. Delete
                            2. Mark read
                            3. Mark important
                            4. Move to folders (first folder-move wins per message)
                            5. Default: unmatched messages → INBOX
                        → handle full-folder overflow
                        → update PM counts
```

### Key Design Decisions:
1. **Lazy evaluation** — rules run on UI access, not on delivery
2. **ALL-match** — all rules are evaluated against each message (not first-match-stops)
3. **First folder-move wins** — only the first matching `PLACE_INTO_FOLDER` action takes effect per message
4. **Non-folder actions stack** — a message can be both marked read AND marked important by different rules
5. **Admin/mod protection** — auto-delete rules cannot remove messages from admins/mods
6. **No rule editing** — delete and recreate only
7. **Rule limit: 5000** — hardcoded per user
8. **No global rules** — purely per-user system
