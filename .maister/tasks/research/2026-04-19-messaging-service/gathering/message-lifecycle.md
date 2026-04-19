# Private Message Lifecycle — Complete Analysis

## Source Files Investigated
- `src/phpbb/common/functions_privmsgs.php` (2368 lines) — Core PM functions
- `src/phpbb/common/ucp/ucp_pm.php` (445 lines) — PM module router
- `src/phpbb/common/ucp/ucp_pm_compose.php` (1670 lines) — Compose/send flow
- `src/phpbb/common/ucp/ucp_pm_viewmessage.php` (482 lines) — View single message
- `src/phpbb/common/constants.php` — Folder constants
- `src/phpbb/forums/notification/type/pm.php` — PM notification type

---

## 1. Folder Constants & Special Box IDs

**Source**: `src/phpbb/common/constants.php:145-149`

```php
define('PRIVMSGS_HOLD_BOX', -4);   // Messages held when inbox is full
define('PRIVMSGS_NO_BOX', -3);     // Temporary: just received, not yet placed
define('PRIVMSGS_OUTBOX', -2);     // Sent but NOT yet read by any recipient
define('PRIVMSGS_SENTBOX', -1);    // Sent AND at least one recipient has viewed folder
define('PRIVMSGS_INBOX', 0);       // Default inbox
// Custom folders: positive integers (user-created via PRIVMSGS_FOLDER_TABLE)
```

**Full Folder Actions** (`constants.php:152-154`):
```php
define('FULL_FOLDER_NONE', -3);    // Use board default
define('FULL_FOLDER_DELETE', -2);  // Delete oldest messages
define('FULL_FOLDER_HOLD', -1);    // Hold new messages
```

---

## 2. The submit_pm() Flow — Step by Step

**Source**: `functions_privmsgs.php:1629-1989`

**Signature**: `function submit_pm($mode, $subject, &$data_ary, $put_in_outbox = true)`

**Modes**: `post`, `reply`, `quote`, `quotepost`, `forward`, `edit`

### Step 1: Event `core.submit_pm_before` (line 1659)
Allows extensions to modify `$mode`, `$subject`, `$data` before any DB work.

### Step 2: Build Recipient List (lines 1672-1729, only for non-edit modes)
- Iterates `$data_ary['address_list']` for types `u` (users) and `g` (groups)
- For groups: queries `USER_GROUP_TABLE` joined with `USERS_TABLE` to expand group → individual users
- Respects `user_allow_pm` setting (skips unless sender is admin/mod)
- Builds `$recipients` array: `[user_id => 'to'|'bcc']`
- Builds `$to[]` and `$bcc[]` arrays as strings like `u_123`, `g_456`
- **Triggers error** if no recipients found

### Step 3: Truncate Subject (line 1731)
```php
$subject = truncate_string($subject, $mode === 'post' ? 120 : 124);
```
Reply/forward subjects are slightly longer allowed (to accommodate `Re: ` / `Fwd: ` prefix).

### Step 4: Begin Transaction (line 1733)
```php
$db->sql_transaction('begin');
```
**All subsequent DB operations are transactional.**

### Step 5: Build SQL Data & Set Root Level (lines 1737-1810)

**For reply/quote mode**:
```php
$root_level = $data_ary['reply_from_root_level'] ?: $data_ary['reply_from_msg_id'];
```
Also sets `pm_replied = 1` on the original message's `PRIVMSGS_TO_TABLE` row for this user.

**For forward/post/quotepost**: Inserts new row into `PRIVMSGS_TABLE` with fields:
- `root_level`, `author_id`, `icon_id`, `author_ip`, `message_time`
- `enable_bbcode`, `enable_smilies`, `enable_magic_url`, `enable_sig`
- `message_subject`, `message_text`, `message_attachment`
- `bbcode_bitfield`, `bbcode_uid`
- `to_address` (colon-separated, e.g. `u_2:u_5`), `bcc_address`
- `message_reported` = 0

**For edit mode**: Updates existing row — sets `message_edit_count = message_edit_count + 1`, `message_edit_time`, and content fields. Does NOT update `to_address`/`bcc_address`.

### Step 6: Insert into PRIVMSGS_TABLE (lines 1812-1821)
- For new messages: `INSERT INTO PRIVMSGS_TABLE`, gets `$data_ary['msg_id'] = $db->sql_nextid()`
- For edit: `UPDATE PRIVMSGS_TABLE` with edit count increment

### Step 7: Insert Per-Recipient Rows (lines 1823-1848, non-edit only)

For each recipient, inserts into `PRIVMSGS_TO_TABLE`:
```php
$sql_ary[] = array(
    'msg_id'        => $data_ary['msg_id'],
    'user_id'       => $user_id,          // recipient
    'author_id'     => $from_user_id,     // sender
    'folder_id'     => PRIVMSGS_NO_BOX,   // -3, not yet placed
    'pm_new'        => 1,
    'pm_unread'     => 1,
    'pm_forwarded'  => ($mode == 'forward') ? 1 : 0
);
```
Uses `$db->sql_multi_insert()` for batch insert.

### Step 8: Update Recipient User Counters (line 1850)
```php
UPDATE USERS_TABLE SET
    user_new_privmsg = user_new_privmsg + 1,
    user_unread_privmsg = user_unread_privmsg + 1,
    user_last_privmsg = [timestamp]
WHERE user_id IN ([recipient_ids])
```

### Step 9: Put PM in Sender's Outbox (lines 1854-1865)
If `$put_in_outbox` is true (default), inserts another row into `PRIVMSGS_TO_TABLE`:
```php
'msg_id'      => $msg_id,
'user_id'     => $from_user_id,    // sender sees it
'author_id'   => $from_user_id,
'folder_id'   => PRIVMSGS_OUTBOX,  // -2
'pm_new'      => 0,
'pm_unread'   => 0,
'pm_forwarded'=> ($mode == 'forward') ? 1 : 0
```

### Step 10: Update Sender's Last Post Time (line 1868)

### Step 11: Process Attachments (lines 1875-1940)
- Verifies orphan attachments belong to current user
- Updates `ATTACHMENTS_TABLE`: sets `post_msg_id`, `is_orphan = 0`
- Increments global `upload_dir_size` and `num_files` config values

### Step 12: Delete Draft (lines 1943-1949)
If message was loaded from draft, deletes the draft row.

### Step 13: Commit Transaction (line 1951)
```php
$db->sql_transaction('commit');
```

### Step 14: Send Notifications (lines 1953-1965)
```php
$phpbb_notifications = $phpbb_container->get('notification_manager');
if ($mode == 'edit') {
    $phpbb_notifications->update_notifications('notification.type.pm', $pm_data);
} else {
    $phpbb_notifications->add_notifications('notification.type.pm', $pm_data);
}
```

### Step 15: Event `core.submit_pm_after` (line 1976)
Fires with `$mode`, `$subject`, `$data`, `$pm_data`.

### Step 16: Return `$data_ary['msg_id']`

---

## 3. Compose Modes — What Differs

**Source**: `ucp_pm_compose.php:24-670`

### Actions/Modes Available
- `post` — New PM from scratch
- `reply` — Reply to a PM (clears message body, keeps subject)
- `quote` — Reply with quoted original text
- `quotepost` — Quote a forum post into a new PM
- `forward` — Forward existing PM to new recipients
- `edit` — Edit a sent PM (only if in outbox)
- `delete` — Delete a PM

### Quote Flow (lines 1068-1115)
- Decodes BBCode from original message
- For `quotepost`: Creates link to original post, adds `[quote]` block
- For `quote`: Wraps message in quote BBCode with attributes (`author`, `time`, `user_id`, `msg_id`)
- Uses `phpbb_format_quote()` helper and `text_formatter.utils::generate_quote()`
- Subject gets `Re: ` prefix if not already present (line 1118)

### Forward Flow (lines 1131-1158)
- Creates **new message** (not linked to original)
- Builds forward header text:
  ```
  FWD_ORIGINAL_MESSAGE
  FWD_SUBJECT: <subject>
  FWD_DATE: <date>
  FWD_FROM: <author_link>
  FWD_TO: <original_recipients>
  
  [quote="<author>"]<original_text>[/quote]
  ```
- Subject gets `Fwd: ` prefix if not already present (line 1157)
- Sets `pm_forwarded = 1` on the per-recipient row in `PRIVMSGS_TO_TABLE`
- **Address list is NOT inherited** — sender must specify new recipients
- Forward is its own new message with a new `msg_id`

### Reply Flow
- Empty message body (line 555): `$message_parser->message = ($action == 'reply') ? '' : $message_text;`
- Subject gets `Re: ` prefix
- Default recipient: original author (line 483)
- If `reply_to_all=1`: Rebuilds TO header from original `to_address`, adds original author, removes self

### Edit Flow (lines 222-238, 283-300)
- SQL enforces: message must be in **OUTBOX** (`folder_id = PRIVMSGS_OUTBOX`)
- If message is not found (recipient already read it → moved to SENTBOX): triggers `NO_EDIT_READ_MESSAGE`
- Time constraint (in viewmessage.php line 261, compose line 520):
  ```php
  $can_edit_pm = ($message_row['message_time'] > time() - ($config['pm_edit_time'] * 60) || !$config['pm_edit_time'])
      && $folder_id == PRIVMSGS_OUTBOX
      && $auth->acl_get('u_pm_edit');
  ```
  - If `pm_edit_time = 0`, no time limit
  - Otherwise, edit window = `pm_edit_time` minutes from send time
  - **Only possible while message is in OUTBOX** (no recipient has viewed their folder yet)

---

## 4. Message States — Full Lifecycle

### Per-Recipient State Fields (PRIVMSGS_TO_TABLE)

| Field | Type | Description |
|-------|------|-------------|
| `msg_id` | int | FK to PRIVMSGS_TABLE |
| `user_id` | int | The recipient (or sender for outbox/sentbox) |
| `author_id` | int | Original message author |
| `folder_id` | int | Current folder (-4 to 0 or custom positive) |
| `pm_new` | tinyint | 1 = just arrived, not placed yet |
| `pm_unread` | tinyint | 1 = not read by this user |
| `pm_replied` | tinyint | 1 = user replied to this PM |
| `pm_marked` | tinyint | 1 = marked as important/starred |
| `pm_forwarded` | tinyint | 1 = this is a forwarded message |
| `pm_deleted` | tinyint | 1 = sender deleted from outbox before read |

### State Diagram

```
COMPOSE → submit_pm()
    ├── PRIVMSGS_TABLE: New row (message content)
    ├── PRIVMSGS_TO_TABLE (per recipient):
    │       folder_id = NO_BOX (-3)
    │       pm_new = 1, pm_unread = 1
    └── PRIVMSGS_TO_TABLE (sender):
            folder_id = OUTBOX (-2)
            pm_new = 0, pm_unread = 0

RECIPIENT VIEWS FOLDER → place_pm_into_folder()
    ├── Process rules → may auto-delete, mark read, mark important, move to custom folder
    ├── Default: folder_id → INBOX (0), pm_new → 0
    ├── If inbox full + HOLD: folder_id → HOLD_BOX (-4)
    └── SENDER's OUTBOX row → moves to SENTBOX (-1)
        (This happens in same place_pm_into_folder() call)

RECIPIENT READS MESSAGE → update_unread_status()
    ├── pm_unread → 0
    ├── user_unread_privmsg decremented
    └── Notification marked as read

RECIPIENT REPLIES → submit_pm(mode='reply')
    └── pm_replied → 1 on original message's TO row

RECIPIENT FORWARDS → submit_pm(mode='forward')
    └── Creates entirely new message (new msg_id)
    └── pm_forwarded = 1 on the NEW per-recipient rows

SENDER EDITS → submit_pm(mode='edit')
    ├── Only if still in OUTBOX (no recipient placed it yet)
    ├── message_edit_count++, message_edit_time updated
    └── Content fields updated in PRIVMSGS_TABLE

RECIPIENT MARKS IMPORTANT → handle_mark_actions('mark_important')
    └── pm_marked = 1 - pm_marked (toggle)

DELETE (from any folder) → delete_pm()
    ├── If from OUTBOX:
    │   ├── DELETE sender's PRIVMSGS_TO_TABLE row
    │   ├── message_text set to '' in PRIVMSGS_TABLE
    │   └── pm_deleted = 1 on ALL recipient rows
    ├── If from other folder:
    │   └── DELETE user's PRIVMSGS_TO_TABLE row only
    ├── After: Check if ANY PRIVMSGS_TO_TABLE rows remain for this msg_id
    │   └── If none: DELETE from PRIVMSGS_TABLE + delete attachments
    └── Update user's unread/new counters
```

---

## 5. Outbox vs Sentbox — The Key Difference

**Source**: `functions_privmsgs.php:750-770` (inside `place_pm_into_folder()`)

- **Outbox** (`folder_id = -2`): Message sent but **no recipient has viewed their PM folder yet** (message is "in transit")
  - The sender can still **edit** the message while it's here
  - All outbox messages show as "unread": `$num_unread[PRIVMSGS_OUTBOX] = $num_messages[PRIVMSGS_OUTBOX]` (line 169 in `get_folder()`)
- **Sentbox** (`folder_id = -1`): At least one recipient's folder-placement has occurred → message moved from Outbox to Sentbox
  - The transition happens in `place_pm_into_folder()` line 763:
    ```php
    UPDATE PRIVMSGS_TO_TABLE SET folder_id = PRIVMSGS_SENTBOX
    WHERE folder_id = PRIVMSGS_OUTBOX
    AND msg_id IN ([messages being placed for recipients])
    ```
  - **Editing is no longer possible** once in Sentbox
- **Sentbox auto-cleanup**: `clean_sentbox()` (line 226) deletes oldest messages when sentbox exceeds `message_limit`

---

## 6. Delete Flow Details

**Source**: `functions_privmsgs.php:1037-1210`

### `delete_pm($user_id, $msg_ids, $folder_id)`

1. **Event `core.delete_pm_before`** — allows extensions to act before delete
2. Gathers PM info (unread/new counts) for the user's rows
3. **Transaction begin**
4. **If deleting from OUTBOX** (sender deleting before anyone read):
   - DELETE sender's `PRIVMSGS_TO_TABLE` row
   - **Blank the message text**: `UPDATE PRIVMSGS_TABLE SET message_text = '' WHERE ...`
   - **Set `pm_deleted = 1`** on ALL remaining recipient rows — recipients will see "message deleted by sender"
5. **If deleting from other folder** (recipient removing from their view):
   - DELETE only THIS user's `PRIVMSGS_TO_TABLE` row
6. Update custom folder `pm_count` if applicable
7. Update user's `user_unread_privmsg` and `user_new_privmsg` counters
8. Delete notifications via `notification_manager->delete_notifications()`
9. **Physical cleanup**: Check if ANY `PRIVMSGS_TO_TABLE` rows remain for these `msg_ids`
   - If no rows remain → DELETE from `PRIVMSGS_TABLE` + delete attachments via `attachment_manager`
10. **Transaction commit**

### Recipient View of Deleted Message
When viewing a message with `pm_deleted = 1` (in `ucp_pm_viewmessage.php:74-80`):
```php
if ($message_row['pm_deleted']) {
    // Returns 403 + 'NO_AUTH_READ_REMOVED_MESSAGE'
    trigger_error($message);
}
```

---

## 7. Threading / root_level

**Source**: `functions_privmsgs.php:1746-1752` (in `submit_pm()`)

```php
case 'reply':
case 'quote':
    $root_level = $data_ary['reply_from_root_level']
        ? $data_ary['reply_from_root_level']
        : $data_ary['reply_from_msg_id'];
```

- `root_level` in `PRIVMSGS_TABLE` stores the **root message ID** of a conversation chain
- For the first message in a chain: `root_level = 0`
- For replies: `root_level` = the root of the original, or the original `msg_id` if it was itself a root
- Used by `message_history()` (line 1991) to display conversation thread:
  ```php
  if (!$message_row['root_level']) {
      // This IS a root: find messages with root_level = this msg_id, or msg_id = this msg_id
      $sql_where .= " AND (p.root_level = $msg_id OR (p.root_level = 0 AND p.msg_id = $msg_id))";
  } else {
      // This is a reply: find all with same root_level, plus the root itself
      $sql_where .= " AND (p.root_level = " . $message_row['root_level'] . ' OR p.msg_id = ' . $message_row['root_level'] . ')';
  }
  ```
- History is displayed ordered by `message_time DESC`
- This is a **flat threading model** — no nested replies, just a chain linked by `root_level`

---

## 8. BCC Handling

**Source**: `functions_privmsgs.php:1686-1700`

- BCC works at the **address list level**: `$data_ary['address_list']['u'][$user_id] = 'bcc'`
- Both TO and BCC recipients get identical `PRIVMSGS_TO_TABLE` rows — the BCC flag is NOT stored per-recipient
- **BCC is stored in the message itself**: `to_address` and `bcc_address` are colon-separated strings in `PRIVMSGS_TABLE`
  - Format: `u_2:u_5` for users, `g_3` for groups
- When displaying recipients (`write_pm_addresses()` in line 1445), BCC addresses are shown to the **sender** but hidden from TO recipients
- Recipients can see who was in the TO list (via `to_address`) but NOT the BCC list
- In `ucp_pm_viewmessage.php:93`: `write_pm_addresses(array('to' => $message_row['to_address'], 'bcc' => $message_row['bcc_address']), $author_id)` — the function conditionally shows BCC only to the message author

---

## 9. Message Content Fields

**Source**: `functions_privmsgs.php:1758-1800` (PRIVMSGS_TABLE columns)

| Field | Description |
|-------|-------------|
| `msg_id` | Auto-increment PK |
| `root_level` | Root message ID for threading (0 if root) |
| `author_id` | Sender user ID |
| `icon_id` | PM icon (topic icon equivalent) |
| `author_ip` | Sender IP address |
| `message_time` | Unix timestamp of send |
| `message_subject` | Subject text (max 120-124 chars) |
| `message_text` | Full message body (with BBCode UIDs) |
| `message_attachment` | 0/1 flag for attachments |
| `enable_bbcode` | BBCode enabled flag |
| `enable_smilies` | Smilies enabled flag |
| `enable_magic_url` | Auto-link URLs flag |
| `enable_sig` | Show signature flag |
| `bbcode_bitfield` | Bitfield of used BBCodes |
| `bbcode_uid` | Unique ID for BBCode parsing |
| `to_address` | Colon-separated TO recipients (e.g. `u_2:u_5:g_3`) |
| `bcc_address` | Colon-separated BCC recipients |
| `message_reported` | 0/1 reported flag |
| `message_edit_time` | Last edit timestamp |
| `message_edit_count` | Number of edits |
| `message_edit_user` | User who last edited |

---

## 10. Rule System (Auto-Folder Placement)

**Source**: `functions_privmsgs.php:416-780`

When `place_pm_into_folder()` runs (triggered when user views their PM folder and `user_new_privmsg > 0`):

1. If user has no rules (`user_message_rules = 0`): all messages go to INBOX
2. If user has rules: evaluates each rule against each new message
3. **Rule checks**: subject, sender, message text, status (replied/forwarded), TO/BCC membership
4. **Rule actions**:
   - `ACTION_PLACE_INTO_FOLDER` (1) — move to specific folder
   - `ACTION_MARK_AS_READ` (2) — set pm_unread = 0
   - `ACTION_MARK_AS_IMPORTANT` (3) — toggle pm_marked
   - `ACTION_DELETE_MESSAGE` (4) — auto-delete (except from admins/mods)
5. **Full folder handling**: if destination folder is full:
   - `FULL_FOLDER_DELETE`: delete oldest messages to make room
   - `FULL_FOLDER_HOLD`: place in HOLD_BOX (-4), shown as "not moved"
   - Custom folder redirect: move to alternative folder

**After placement**: OUTBOX → SENTBOX transition for sender (line 763)

---

## 11. Events Fired During PM Operations

### submit_pm() events:
| Event | When | Variables |
|-------|------|-----------|
| `core.submit_pm_before` | Before any DB work | `mode`, `subject`, `data` |
| `core.submit_pm_after` | After commit + notifications | `mode`, `subject`, `data`, `pm_data` |

### compose_pm() events:
| Event | When |
|-------|------|
| `core.ucp_pm_compose_modify_data` | Before compose form processing |
| `core.ucp_pm_compose_compose_pm_basic_info_query_before` | Before SQL to get message data |
| `core.ucp_pm_compose_compose_pm_basic_info_query_after` | After getting message data |
| `core.ucp_pm_compose_quotepost_query_after` | After quotepost query |
| `core.ucp_pm_compose_predefined_message` | New message defaults |
| `core.ucp_pm_compose_modify_parse_before` | Before message parsing |
| `core.ucp_pm_compose_modify_parse_after` | After message parsing |
| `core.ucp_pm_compose_modify_bbcode_status` | Override BBCode status |
| `core.ucp_pm_compose_template` | Before template vars assigned |
| `core.pm_modify_message_subject` | Subject modification for reply/quote |
| `core.message_list_actions` | Address list management |

### view_message() events:
| Event | When |
|-------|------|
| `core.ucp_pm_view_message_before` | Before message display prep |
| `core.ucp_pm_view_messsage` | Before template assign (deprecated, typo) |
| `core.ucp_pm_view_message` | Before template assign (correct name) |

### delete_pm() events:
| Event | When |
|-------|------|
| `core.delete_pm_before` | Before delete processing |

### message_history() events:
| Event | When |
|-------|------|
| `core.message_history_modify_sql_ary` | Before history query |
| `core.message_history_modify_rowset` | After getting history rows |

---

## 12. Transaction Handling

### submit_pm() — **Fully transactional**
- `$db->sql_transaction('begin')` at line 1733
- All INSERTs/UPDATEs wrapped
- `$db->sql_transaction('commit')` at line 1951
- Notifications sent AFTER commit (outside transaction)

### delete_pm() — **Fully transactional**
- `$db->sql_transaction('begin')` 
- All DELETEs/UPDATEs wrapped
- Physical file cleanup (attachments) happens before commit
- `$db->sql_transaction('commit')`

### place_pm_into_folder() — **NOT wrapped in a single transaction**
- Individual queries for moves, marks, deletes
- Could theoretically leave partial state if interrupted

---

## 13. Key Authorization Checks

| Permission | Used For |
|------------|----------|
| `u_sendpm` | Can send private messages |
| `u_readpm` | Can read private messages |
| `u_pm_delete` | Can delete PMs |
| `u_pm_edit` | Can edit PMs (in outbox) |
| `u_pm_forward` | Can forward PMs |
| `u_masspm` | Can send to multiple users |
| `u_masspm_group` | Can send to groups |
| `u_pm_bbcode` | Can use BBCode in PMs |
| `u_pm_smilies` | Can use smilies in PMs |
| `u_pm_attach` | Can attach files to PMs |
| `u_pm_download` | Can download PM attachments |
| `u_pm_printpm` | Can print PMs |
| `u_pm_img` | Can use images in PMs |
| `u_pm_flash` | Can use flash in PMs |
| `u_sig` | Can use signature |
| `u_savedrafts` | Can save PM drafts |

### Config flags:
| Config | Effect |
|--------|--------|
| `allow_privmsg` | Master PM on/off |
| `pm_edit_time` | Minutes allowed for editing (0 = unlimited) |
| `forward_pm` | PM forwarding enabled |
| `allow_mass_pm` | Mass PM enabled |
| `pm_max_recipients` | Max TO recipients |
| `allow_pm_attach` | PM attachments enabled |
| `allow_pm_report` | PM reporting enabled |
| `print_pm` | PM printing enabled |
| `flood_interval` | Anti-flood seconds between sends |

---

## 14. Read Status Update

**Source**: `functions_privmsgs.php:883-935` — `update_unread_status()`

Called from `ucp_pm.php:361` when viewing a message:
```php
update_unread_status($message_row['pm_unread'], $message_row['msg_id'], $user->data['user_id'], $folder_id);
```

1. Marks notification as read via `notification_manager->mark_notifications()`
2. Sets `pm_unread = 0` in `PRIVMSGS_TO_TABLE`
3. If affected rows > 0: decrements `user_unread_privmsg` in `USERS_TABLE`
4. Handles edge case of negative unread count (resets to 0)

---

## 15. Summary of Key Architectural Patterns

1. **Two-table design**: `PRIVMSGS_TABLE` (one row per message) + `PRIVMSGS_TO_TABLE` (one row per recipient + one for sender)
2. **Lazy folder placement**: Messages start in `NO_BOX` and get placed into folders when recipient views their PM section
3. **Outbox→Sentbox is triggered by recipient activity**, not by a timer or async job
4. **Soft delete from outbox**: Sets `pm_deleted=1` on recipient rows + blanks message text
5. **Hard delete from inbox/folders**: Removes user's `PRIVMSGS_TO_TABLE` row; physical message deleted only when ALL rows gone
6. **Forward creates a new message** — no linking back to original `msg_id`
7. **Edit only possible in Outbox** — once any recipient places it, it's locked
8. **Rule processing runs synchronously** during folder view, not at send time
