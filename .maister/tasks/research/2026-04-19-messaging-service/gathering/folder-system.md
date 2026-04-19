# PM Folder System — Comprehensive Findings

## 1. System Folder Constants

Defined in `src/phpbb/common/constants.php` (lines 145–149):

```php
define('PRIVMSGS_HOLD_BOX', -4);
define('PRIVMSGS_NO_BOX', -3);
define('PRIVMSGS_OUTBOX', -2);
define('PRIVMSGS_SENTBOX', -1);
define('PRIVMSGS_INBOX', 0);
```

**Comment in source**: `// Private messaging - Do NOT change these values`

### Purpose of Each System Folder

| Constant | Value | Purpose |
|---|---|---|
| `PRIVMSGS_INBOX` | `0` | Default incoming message folder. Messages land here after processing rules. Not stored in `phpbb_privmsgs_folder` table — it's a virtual folder tracked only via `folder_id` in `phpbb_privmsgs_to`. |
| `PRIVMSGS_SENTBOX` | `-1` | Auto-populated when a sent message is read by at least one recipient. Messages move from OUTBOX → SENTBOX automatically in `place_pm_into_folder()`. Subject to auto-cleanup via `clean_sentbox()`. |
| `PRIVMSGS_OUTBOX` | `-2` | Holds sent messages not yet read by any recipient. All messages in OUTBOX are treated as unread (`num_unread[PRIVMSGS_OUTBOX] = num_messages[PRIVMSGS_OUTBOX]` — line 159). Sender can still delete/edit while here. |
| `PRIVMSGS_NO_BOX` | `-3` | Temporary staging area for newly received messages. Messages arrive here first, then `place_pm_into_folder()` processes rules and moves them to their destination. Excluded from folder listing queries (`folder_id <> PRIVMSGS_NO_BOX`). |
| `PRIVMSGS_HOLD_BOX` | `-4` | Messages held when destination folder is full and user's "full folder action" is set to HOLD. Not visible in folder UI. Released back to NO_BOX when user frees space. |

### Full Folder Action Constants

Defined in `src/phpbb/common/constants.php` (lines 152–154):

```php
define('FULL_FOLDER_NONE', -3);   // Use system default
define('FULL_FOLDER_DELETE', -2); // Delete oldest messages to make room
define('FULL_FOLDER_HOLD', -1);   // Hold new messages (put in HOLD_BOX)
```

If `>= 0`, the value is a `folder_id` — messages overflow into that specific folder.

---

## 2. Database Schema

### `phpbb_privmsgs_folder` — Custom User Folders

Source: `phpbb_dump.sql` (lines 2801–2810)

```sql
CREATE TABLE `phpbb_privmsgs_folder` (
  `folder_id`   mediumint(8) unsigned NOT NULL AUTO_INCREMENT,
  `user_id`     int(10) unsigned NOT NULL DEFAULT 0,
  `folder_name` varchar(255) NOT NULL DEFAULT '',
  `pm_count`    mediumint(8) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`folder_id`),
  KEY `user_id` (`user_id`)
);
```

**Key observations**:
- Only **custom** folders are stored here. System folders (INBOX, OUTBOX, SENTBOX) have no row.
- `pm_count` is a **denormalized counter** — maintained by increment/decrement on move/delete operations.
- `folder_id` is auto-increment, so custom folders get positive IDs (1, 2, 3...).
- Scoped per `user_id` — each user has their own independent folder set.

### `phpbb_privmsgs_to` — Message-to-User-Folder Mapping

Source: `phpbb_dump.sql` (lines 2858–2873)

```sql
CREATE TABLE `phpbb_privmsgs_to` (
  `msg_id`       int(10) unsigned NOT NULL DEFAULT 0,
  `user_id`      int(10) unsigned NOT NULL DEFAULT 0,
  `author_id`    int(10) unsigned NOT NULL DEFAULT 0,
  `pm_deleted`   tinyint(1) unsigned NOT NULL DEFAULT 0,
  `pm_new`       tinyint(1) unsigned NOT NULL DEFAULT 1,
  `pm_unread`    tinyint(1) unsigned NOT NULL DEFAULT 1,
  `pm_replied`   tinyint(1) unsigned NOT NULL DEFAULT 0,
  `pm_marked`    tinyint(1) unsigned NOT NULL DEFAULT 0,
  `pm_forwarded` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `folder_id`    int(11) NOT NULL DEFAULT 0,
  KEY `msg_id` (`msg_id`),
  KEY `author_id` (`author_id`),
  KEY `usr_flder_id` (`user_id`, `folder_id`)
);
```

**Key observations**:
- `folder_id` column is **the canonical location** of a message for a specific user. This is how a message's current folder is tracked.
- One row per (msg_id, user_id) combination — each recipient/sender has their own row.
- `pm_new` flag — set to 1 for newly arrived messages. Only valid in `NO_BOX` and `HOLD_BOX` (cleared when moved to a real folder).
- `pm_unread` — per-user unread tracking.
- `pm_deleted` — soft-delete flag, used when removing from OUTBOX (message text gets blanked but row kept for other recipients).
- Composite index `usr_flder_id` optimizes per-user folder queries.

---

## 3. `get_folder()` — Build Complete Folder Tree

Source: `src/phpbb/common/functions_privmsgs.php` (lines 116–222)

### Signature
```php
function get_folder($user_id, $folder_id = false)
```

### Algorithm

**Step 1: Count messages per folder from `privmsgs_to`** (lines 127–140)
```php
$sql = 'SELECT folder_id, COUNT(msg_id) as num_messages, SUM(pm_unread) as num_unread
    FROM ' . PRIVMSGS_TO_TABLE . "
    WHERE user_id = $user_id
        AND folder_id <> " . PRIVMSGS_NO_BOX . '
    GROUP BY folder_id';
```
- Queries the `privmsgs_to` table directly — **not** using the denormalized `pm_count`.
- Excludes `NO_BOX` (staging area).
- Returns real-time counts grouped by folder_id.

**Step 2: Ensure system folders are initialized** (lines 143–158)
```php
$available_folder = array(PRIVMSGS_INBOX, PRIVMSGS_OUTBOX, PRIVMSGS_SENTBOX);
```
Sets default 0 for any missing system folder.

**Step 3: Special OUTBOX unread treatment** (line 159)
```php
$num_unread[PRIVMSGS_OUTBOX] = $num_messages[PRIVMSGS_OUTBOX];
```
All OUTBOX messages count as unread (they haven't been delivered yet).

**Step 4: Build folder array** (lines 161–195)

Order of construction:
1. **INBOX** (value 0) — first
2. **Custom folders** — queried from `phpbb_privmsgs_folder` table (lines 168–179):
   ```php
   $sql = 'SELECT folder_id, folder_name, pm_count
       FROM ' . PRIVMSGS_FOLDER_TABLE . "
       WHERE user_id = $user_id";
   ```
   Note: Uses `pm_count` from the folder table for `num_messages`, but gets `unread_messages` from the Step 1 query results.
3. **OUTBOX** (value -2)
4. **SENTBOX** (value -1)

**Step 5: Assign template vars** (lines 197–212)

Iterates through folders and assigns `folder` block vars to template:
- `FOLDER_ID`, `FOLDER_NAME`, `NUM_MESSAGES`, `UNREAD_MESSAGES`
- `U_FOLDER` — URL uses named paths for system folders (`inbox`, `outbox`, `sentbox`), numeric IDs for custom
- `S_CUR_FOLDER` — whether this is the currently viewed folder
- `S_CUSTOM_FOLDER` — `true` when `folder_id > 0`

**Step 6: Validate requested folder** (line 214)
```php
if ($folder_id !== false && $folder_id !== PRIVMSGS_HOLD_BOX && !isset($folder[$folder_id]))
{
    trigger_error('UNKNOWN_FOLDER');
}
```
HOLD_BOX is allowed even though it's not in the array (it's a system-internal folder).

### Return Value
```php
return $folder;
```
Returns associative array: `folder_id => ['folder_name', 'num_messages', 'unread_messages']`

---

## 4. Custom Folders — CRUD Operations

All in `src/phpbb/common/ucp/ucp_pm_options.php`.

### Create (lines 80–126)

**Trigger**: `$_POST['addfolder']`

**Validations**:
1. CSRF check via `check_form_key('ucp_pm_options')`
2. Folder name not empty
3. Duplicate name check per user:
   ```php
   SELECT folder_name FROM phpbb_privmsgs_folder
   WHERE folder_name = '{escaped_name}' AND user_id = {user_id}
   ```
4. Max folder count check:
   ```php
   SELECT COUNT(folder_id) as num_folder FROM phpbb_privmsgs_folder
   WHERE user_id = {user_id}
   ```
   Compared against `$config['pm_max_boxes']` (default: 4).

**Insert**:
```php
INSERT INTO phpbb_privmsgs_folder (user_id, folder_name) VALUES (...)
```
`pm_count` defaults to 0.

### Rename (lines 139–174)

**Trigger**: `$_POST['rename_folder']`

**Validations**:
1. CSRF check
2. New name not empty
3. Folder must exist and belong to user

**Update**: Direct `UPDATE ... SET folder_name = ...`

### Delete / Remove (lines 179–280)

**Trigger**: `$_POST['remove_folder']`

**Options** (`remove_action`):
1. **Move messages** (`remove_action == 1`): `move_pm()` to `$move_to` folder (cannot be same folder)
2. **Delete messages** (`remove_action == 2`): `delete_pm()` for all messages

**Process**:
1. Requires `confirm_box()` confirmation
2. Gathers all `msg_id` from folder
3. Executes chosen action (move or delete)
4. Deletes folder row: `DELETE FROM phpbb_privmsgs_folder WHERE ...`
5. If deleted folder was `user_full_folder`, resets to INBOX:
   ```php
   UPDATE phpbb_users SET user_full_folder = PRIVMSGS_INBOX WHERE user_id = ...
   ```
6. Updates any rules targeting the deleted folder:
   ```php
   UPDATE phpbb_privmsgs_rules SET rule_folder_id = {move_to|INBOX}
   WHERE rule_folder_id = {removed_folder_id}
   ```

---

## 5. `move_pm()` — Moving Messages Between Folders

Source: `src/phpbb/common/functions_privmsgs.php` (lines 783–895)

### Signature
```php
function move_pm($user_id, $message_limit, $move_msg_ids, $dest_folder, $cur_folder_id)
```

### Guard Conditions (line 798)
Cannot move:
- **To**: `NO_BOX`, `OUTBOX`, `SENTBOX`
- **From**: `NO_BOX`, `OUTBOX`
- **Same folder**: `$cur_folder_id != $dest_folder`

### Capacity Check

**For custom folders** (dest_folder != INBOX):
```php
SELECT folder_id, folder_name, pm_count FROM phpbb_privmsgs_folder
WHERE folder_id = {dest} AND user_id = {user_id}
```
Checks: `pm_count + count(move_msg_ids) > message_limit`

**For INBOX**:
```php
SELECT COUNT(msg_id) FROM phpbb_privmsgs_to
WHERE folder_id = PRIVMSGS_INBOX AND user_id = {user_id}
```
Checks: `num_messages + count(move_msg_ids) > message_limit`

If over limit → error: `NOT_ENOUGH_SPACE_FOLDER`.

### Move Operation
```php
UPDATE phpbb_privmsgs_to
SET folder_id = {dest_folder}
WHERE folder_id = {cur_folder_id}
    AND user_id = {user_id}
    AND msg_id IN ({move_msg_ids})
```

### Counter Updates (lines 870–885)
- **Source custom folder**: `pm_count = pm_count - num_moved`
- **Destination custom folder** (not INBOX): `pm_count = pm_count + num_moved`
- **INBOX and system folders**: No `pm_count` update (they don't have rows in `phpbb_privmsgs_folder`).

### Return
```php
return $num_moved;  // from sql_affectedrows()
```

---

## 6. `clean_sentbox()` — Auto-Cleanup When Over Limit

Source: `src/phpbb/common/functions_privmsgs.php` (lines 226–253)

### Signature
```php
function clean_sentbox($num_sentbox_messages)
```

### Algorithm
1. Check: `$user->data['message_limit'] && $num_sentbox_messages > $user->data['message_limit']`
2. Select oldest messages to delete (ordered by `message_time ASC`):
   ```php
   SELECT t.msg_id
   FROM phpbb_privmsgs_to t, phpbb_privmsgs p
   WHERE t.msg_id = p.msg_id
       AND t.user_id = {user_id}
       AND t.folder_id = PRIVMSGS_SENTBOX
   ORDER BY p.message_time ASC
   LIMIT ({num_sentbox_messages} - {message_limit})
   ```
3. Deletes exactly the overflow count: `$num_sentbox_messages - $user->data['message_limit']`
4. Calls `delete_pm($user_id, $delete_ids, PRIVMSGS_SENTBOX)`

**Key insight**: SENTBOX has special treatment — it auto-cleans by deleting oldest messages. No hold/error behavior for SENTBOX overflow. This is called during message placement after OUTBOX → SENTBOX move.

---

## 7. `update_pm_counts()` — Resync User-Level Counters

Source: `src/phpbb/common/functions_privmsgs.php` (lines 370–413)

### What It Updates

**user_unread_privmsg** (lines 376–382):
```php
SELECT COUNT(msg_id) FROM phpbb_privmsgs_to
WHERE pm_unread = 1 AND folder_id <> PRIVMSGS_OUTBOX AND user_id = {user_id}
```
- Counts all unread messages except OUTBOX.
- OUTBOX excluded because its "unread" status is artificial.

**user_new_privmsg** (lines 385–391):
```php
SELECT COUNT(msg_id) FROM phpbb_privmsgs_to
WHERE pm_new = 1 AND folder_id IN (PRIVMSGS_NO_BOX, PRIVMSGS_HOLD_BOX) AND user_id = {user_id}
```
- "New" only applies to messages still in staging (NO_BOX/HOLD_BOX).

**Writes to users table** (lines 393–397):
```php
UPDATE phpbb_users SET
    user_unread_privmsg = {count},
    user_new_privmsg = {count}
WHERE user_id = {user_id}
```

### Repair Logic (lines 401–409)
If `user_new_privmsg` is 0, clears any stale `pm_new = 1` flags in folders that shouldn't have them:
```php
UPDATE phpbb_privmsgs_to SET pm_new = 0
WHERE pm_new = 1
    AND folder_id NOT IN (PRIVMSGS_NO_BOX, PRIVMSGS_HOLD_BOX)
    AND user_id = {user_id}
```

---

## 8. `place_pm_into_folder()` — New Message Routing

Source: `src/phpbb/common/functions_privmsgs.php` (lines 417–780)

### Purpose
Routes messages from `NO_BOX` (staging) into destination folders based on user rules.

### Algorithm

**Step 1: Release HOLD_BOX** (if `$release = true`, lines 431–436):
```php
UPDATE phpbb_privmsgs_to SET folder_id = PRIVMSGS_NO_BOX
WHERE folder_id = PRIVMSGS_HOLD_BOX AND user_id = {user_id}
```

**Step 2: Retrieve unplaced messages** (lines 449–453):
```php
SELECT t.*, p.*, u.username, u.user_id, u.group_id
FROM phpbb_privmsgs_to t, phpbb_privmsgs p, phpbb_users u
WHERE t.user_id = {user_id}
    AND p.author_id = u.user_id
    AND t.folder_id = PRIVMSGS_NO_BOX
    AND t.msg_id = p.msg_id
```

**Step 3: Apply rules** (if `user_message_rules` enabled):
- Loads all user rules from `phpbb_privmsgs_rules`
- Loads zebra (friend/foe) list
- For each message, checks rules via `check_rule()` — first matching rule wins
- Rule actions: `ACTION_PLACE_INTO_FOLDER`, `ACTION_MARK_AS_READ`, `ACTION_MARK_AS_IMPORTANT`, `ACTION_DELETE_MESSAGE`

**Step 4: Default placement** — messages with no matching rule go to `PRIVMSGS_INBOX`:
```php
$move_into_folder[PRIVMSGS_INBOX][] = $msg_id;
```

**Step 5: Handle full folders** (lines 680–750):
- Calculates `full_folder_action` from user setting or system default
- If folder would exceed `message_limit`:
  - `FULL_FOLDER_DELETE` → deletes oldest messages by msg_id
  - `FULL_FOLDER_HOLD` → moves to HOLD_BOX instead
  - `>= 0` (folder_id) → redirects to that folder; if also full, falls back to system default

**Step 6: OUTBOX → SENTBOX migration** (lines 753–758):
```php
UPDATE phpbb_privmsgs_to SET folder_id = PRIVMSGS_SENTBOX
WHERE folder_id = PRIVMSGS_OUTBOX AND msg_id IN ({action_msg_ids})
```
This happens for every message that was just placed — the sender's copy moves from OUTBOX to SENTBOX once at least one recipient processes it.

**Step 7: Update counts** → calls `update_pm_counts()`

**Return**: `array('not_moved' => {hold_count}, 'removed' => {deleted_count})`

---

## 9. `get_folder_status()` — Folder Capacity Information

Source: `src/phpbb/common/functions_privmsgs.php` (lines 1595–1624)

```php
function get_folder_status($folder_id, $folder)
```

### Returns
```php
array(
    'folder_name'  => $folder['folder_name'],
    'cur'          => $folder['num_messages'],
    'remaining'    => $message_limit - $num_messages,  // 0 if no limit
    'max'          => $user->data['message_limit'],
    'percent'      => floor(($num_messages / $message_limit) * 100),
    'message'      => "Folder status: X of Y messages (Z%)"
)
```

Used to display folder capacity in the UI.

---

## 10. `delete_pm()` — Message Deletion Logic

Source: `src/phpbb/common/functions_privmsgs.php` (lines 1037–1210)

### Key Behaviors by Folder Type

**OUTBOX deletion** (lines 1116–1133):
- Removes `privmsgs_to` row for the sender
- **Blanks message text** in `privmsgs` table (safety: recipients lose content)
- Sets `pm_deleted = 1` on all other recipients' `privmsgs_to` rows (soft delete)

**All other folders** (lines 1136–1141):
- Simply deletes the `privmsgs_to` row for this user/folder

**Custom folder pm_count update** (lines 1143–1148):
```php
if (!in_array($folder_id, array(PRIVMSGS_INBOX, PRIVMSGS_OUTBOX, PRIVMSGS_SENTBOX, PRIVMSGS_NO_BOX)))
{
    UPDATE phpbb_privmsgs_folder SET pm_count = pm_count - {num_deleted}
    WHERE folder_id = {folder_id}
}
```
Only custom folders have `pm_count` to decrement.

**Orphan cleanup** (lines 1172–1195):
After deleting `privmsgs_to` rows, checks if any `msg_id` has no remaining rows in `privmsgs_to`. If so:
- Deletes attachments via `attachment.manager`
- Deletes the actual `privmsgs` row

This ensures the message body is only deleted when **all** users have removed it.

---

## 11. Per-User View Model

Each user has an **independent view** of the folder system:

- The `phpbb_privmsgs_to` table has one row per `(msg_id, user_id)` — each with its own `folder_id`.
- When user A sends a PM to user B:
  - User A gets a row with `folder_id = PRIVMSGS_OUTBOX`
  - User B gets a row with `folder_id = PRIVMSGS_NO_BOX` (staging)
- User B moving the message to a custom folder only changes B's `folder_id` — A's row is unaffected.
- Deleting from one user's view doesn't affect the other — the message is only physically deleted when all `privmsgs_to` rows are gone.

---

## 12. Folder Quotas

### Quota Type: Per-Folder (Enforced at Message Limit Level)

The limit is `$user->data['message_limit']` — set per-user (from user/group settings). It applies **independently to each folder**.

**Evidence from `move_pm()`** (line 818):
```php
if ($message_limit && $row['pm_count'] + count($move_msg_ids) > $message_limit)
```
Each folder checked individually against same `message_limit`.

**Evidence from `get_folder_status()`** (line 1611):
```php
'remaining' => ($user->data['message_limit']) ? $user->data['message_limit'] - $folder['num_messages'] : 0,
```
Remaining calculated per folder.

### How Enforced
- **On move**: `move_pm()` checks capacity before moving, errors if exceeded.
- **On new message arrival**: `place_pm_into_folder()` checks capacity and applies `full_folder_action`.
- **SENTBOX special**: `clean_sentbox()` auto-deletes oldest when over limit.
- **Limit of 0**: Means unlimited (`if ($user->data['message_limit'] && ...)`).

---

## 13. Folder Listing Query Pattern (UI)

`get_pm_from()` in `src/phpbb/common/ucp/ucp_pm_viewfolder.php` (lines 406–510):

### Main Message Query Pattern
```php
$folder_sql = 't.folder_id = ' . (int) $folder_id;

SELECT t.*, p.*, u.*
FROM phpbb_privmsgs_to t, phpbb_privmsgs p, phpbb_users u
WHERE $folder_sql
    AND t.user_id = $user_id
    AND t.msg_id = p.msg_id
    AND p.author_id = u.user_id
    [AND p.message_time >= $min_post_time]
ORDER BY {sort_column} {sort_dir}
LIMIT {topics_per_page} OFFSET {start}
```

### Count Pattern
- **With time filter**: runs a COUNT query
- **Without time filter**: uses pre-fetched `$folder[$folder_id]['num_messages']` (from `get_folder()`)

### Sort Options
- **For OUTBOX/SENTBOX**: time, subject (no author — all from current user)
- **For other folders**: author, time, subject

### Move Options in Folder View (lines 86–100)
```php
if ($folder_id != PRIVMSGS_NO_BOX && $folder_id != PRIVMSGS_OUTBOX)
```
Move dropdown excludes OUTBOX, SENTBOX, and current folder. Only shows INBOX and custom folders.

---

## 14. Message Lifecycle Through Folders

```
Sender composes → OUTBOX (sender's row, folder_id = -2)
                 → NO_BOX (each recipient's row, folder_id = -3)

Recipient logs in → place_pm_into_folder() triggers:
    NO_BOX → [rules check] → INBOX (or custom folder via rule)
                            → HOLD_BOX (if target full + HOLD action)
                            → deleted (if rule says delete)
    
    Simultaneously: Sender's OUTBOX → SENTBOX (folder_id -2 → -1)

User manually moves → move_pm() → any INBOX/custom folder
                   → Cannot move to OUTBOX, SENTBOX, NO_BOX

Deletion → delete_pm() → removes privmsgs_to row
        → If no rows remain for msg_id → deletes privmsgs row + attachments
```

---

## 15. Summary of Key Data Patterns

| Aspect | Pattern |
|---|---|
| **Folder location** | `folder_id` column in `phpbb_privmsgs_to` |
| **System folder counts** | Derived via `COUNT(msg_id)` from `privmsgs_to` |
| **Custom folder counts** | Denormalized `pm_count` in `phpbb_privmsgs_folder` |
| **Unread tracking** | `pm_unread` flag per-user in `privmsgs_to` |
| **New message flag** | `pm_new` — only valid in NO_BOX/HOLD_BOX |
| **User-level counters** | `user_unread_privmsg`, `user_new_privmsg` in `phpbb_users` |
| **Custom folder limit** | `config['pm_max_boxes']` — max number of custom folders per user |
| **Message limit** | `user.message_limit` — per-folder capacity |
| **Full folder behavior** | `user.user_full_folder` → DELETE/HOLD/redirect to folder |
| **Orphan deletion** | Physical message deleted only when all `privmsgs_to` rows removed |
