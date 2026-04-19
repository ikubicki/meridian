# Participant Model & Recipient Handling — Findings

## 1. Database Schema

### `phpbb_privmsgs` table (message header)
**Source**: `phpbb_dump.sql:2754-2785`

Key columns for addressing:
```sql
`to_address`  text NOT NULL,   -- serialized TO recipients
`bcc_address` text NOT NULL,   -- serialized BCC recipients
`author_id`   int(10) unsigned NOT NULL DEFAULT 0,
```

The `to_address` and `bcc_address` columns store a **colon-delimited string** of typed IDs:
- Format: `u_<user_id>` for users, `g_<group_id>` for groups
- Multiple entries separated by colons: `u_2:u_5:g_3`
- This is the **denormalized header** — it records _who_ was addressed, not who actually received it

### `phpbb_privmsgs_to` table (per-recipient state)
**Source**: `phpbb_dump.sql:2858-2872`

```sql
CREATE TABLE `phpbb_privmsgs_to` (
  `msg_id`        int(10) unsigned NOT NULL DEFAULT 0,
  `user_id`       int(10) unsigned NOT NULL DEFAULT 0,
  `author_id`     int(10) unsigned NOT NULL DEFAULT 0,
  `pm_deleted`    tinyint(1) unsigned NOT NULL DEFAULT 0,
  `pm_new`        tinyint(1) unsigned NOT NULL DEFAULT 1,
  `pm_unread`     tinyint(1) unsigned NOT NULL DEFAULT 1,
  `pm_replied`    tinyint(1) unsigned NOT NULL DEFAULT 0,
  `pm_marked`     tinyint(1) unsigned NOT NULL DEFAULT 0,
  `pm_forwarded`  tinyint(1) unsigned NOT NULL DEFAULT 0,
  `folder_id`     int(11) NOT NULL DEFAULT 0,
  KEY `msg_id` (`msg_id`),
  KEY `author_id` (`author_id`),
  KEY `usr_flder_id` (`user_id`,`folder_id`)
);
```

**One row per recipient per message.** Per-user state fields:
- `pm_new` / `pm_unread` — new/read tracking
- `pm_replied` — set to 1 when user replies (tracked in `submit_pm()`)
- `pm_marked` — "important" flag
- `pm_forwarded` — set when this is a forwarded copy
- `pm_deleted` — soft delete flag
- `folder_id` — which folder the user has this message in (inbox, custom, etc.)

**The `author_id` is denormalized** from `phpbb_privmsgs` into each row — this lets queries filter by author without joining.

### `phpbb_zebra` table (friend/foe)
**Source**: `phpbb_dump.sql:4165-4173`

```sql
CREATE TABLE `phpbb_zebra` (
  `user_id`   int(10) unsigned NOT NULL DEFAULT 0,
  `zebra_id`  int(10) unsigned NOT NULL DEFAULT 0,
  `friend`    tinyint(1) unsigned NOT NULL DEFAULT 0,
  `foe`       tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`user_id`,`zebra_id`)
);
```

A user entry with `foe=1` means `user_id` has marked `zebra_id` as a foe. Used by PM rules engine for auto-actions (not blocking at send time — see §7).

---

## 2. Recipient Serialization Format

**Source**: `functions_privmsgs.php:1411-1443` (`rebuild_header`)

The `to_address` and `bcc_address` columns use a serialized string format:

```
u_2:u_5:g_3
```

The `rebuild_header()` function parses this back into a structured array:

```php
function rebuild_header($check_ary)
{
    $address = array();
    foreach ($check_ary as $check_type => $address_field)
    {
        // Split Addresses into users and groups
        preg_match_all('/:?(u|g)_([0-9]+):?/', $address_field, $match);

        $u = $g = array();
        foreach ($match[1] as $id => $type)
        {
            ${$type}[] = (int) $match[2][$id];
        }

        $_types = array('u', 'g');
        foreach ($_types as $type)
        {
            if (count(${$type}))
            {
                foreach (${$type} as $id)
                {
                    $address[$type][$id] = $check_type;
                }
            }
        }
    }
    return $address;
}
```

**Input**: `array('to' => 'u_2:u_5:g_3', 'bcc' => 'u_7')`
**Output**: `array('u' => array(2 => 'to', 5 => 'to', 7 => 'bcc'), 'g' => array(3 => 'to'))`

The return maps each user/group ID to which field (to/bcc) they belong to.

### Building the serialized string (in `submit_pm`)
**Source**: `functions_privmsgs.php:1680-1700`

```php
$recipients = $to = $bcc = array();
foreach ($_types as $ug_type)
{
    if (isset($data_ary['address_list'][$ug_type]) && count($data_ary['address_list'][$ug_type]))
    {
        foreach ($data_ary['address_list'][$ug_type] as $id => $field)
        {
            $field = ($field == 'to') ? 'to' : 'bcc';
            if ($ug_type == 'u')
            {
                $recipients[$id] = $field;
            }
            ${$field}[] = $ug_type . '_' . $id;  // e.g., "u_2" or "g_3"
        }
    }
}
// ...
'to_address'  => implode(':', $to),   // "u_2:u_5:g_3"
'bcc_address' => implode(':', $bcc),  // "u_7"
```

**Key insight**: Group IDs (`g_N`) are stored directly in the address string. When the message is delivered, group membership is expanded to individual users (see §5), but the address header preserves the original group reference.

---

## 3. `write_pm_addresses()` — Display Resolution

**Source**: `functions_privmsgs.php:1445-1594`

This function resolves the serialized `to_address`/`bcc_address` strings into displayable user/group names and assigns template variables. Called when viewing a PM.

**Signature**: `write_pm_addresses($check_ary, $author_id, $plaintext = false)`

**Parameters**:
- `$check_ary` — `array('to' => 'u_2:u_5', 'bcc' => 'u_7')` or pre-parsed array
- `$author_id` — the message author's user_id
- `$plaintext` — if true, returns flat array of username strings (used for forwarding)

**BCC Visibility Logic** (critical):
```php
if ($check_type == 'to' || $author_id == $user->data['user_id'] || $row['user_id'] == $user->data['user_id'])
{
    // Show this address
}
```

**Rules**:
- **TO addresses**: Always shown to everyone viewing the message
- **BCC addresses**: Only shown if:
  - The current viewer IS the message author (`$author_id == $user->data['user_id']`), OR
  - The current viewer IS the BCC recipient themselves (for groups: if they're a member)
- **Regular TO recipients CANNOT see BCC recipients** — they only see the TO list

**Template output**:
- Sets `S_TO_RECIPIENT` / `S_BCC_RECIPIENT` template vars
- Assigns `to_recipient` / `bcc_recipient` template block rows with user/group details

**For groups**: Resolves group_id to group name/colour, and cross-references with `USER_GROUP_TABLE` to mark if any user is also in the group (`in_group` field).

---

## 4. `get_recipient_strings()` — Batch Address Resolution

**Source**: `functions_privmsgs.php:2283-2380`

Used in PM listing contexts (folder views) to resolve recipient display strings for multiple messages at once.

```php
function get_recipient_strings($pm_by_id)
```

**Process**:
1. Calls `rebuild_header()` for each message to parse `to_address`/`bcc_address`
2. Collects all unique user and group IDs across messages
3. Batch-loads usernames and group names in two queries
4. Generates formatted HTML links (with coloured usernames) for each message
5. Returns `$address_list[$message_id][]` — array of formatted recipient strings per message

**NOTE**: This function does NOT filter BCC visibility — it resolves all addresses (both TO and BCC) into display strings. The BCC filtering must be done by the caller or template.

---

## 5. Group Messaging — Resolution & Permissions

### Group availability
**Source**: `ucp_pm_compose.php:143-168`

Groups shown in compose UI are filtered:
```php
if ($config['allow_mass_pm'] && $auth->acl_get('u_masspm_group'))
{
    $sql = 'SELECT g.group_id, g.group_name, g.group_type, g.group_colour
        FROM ' . GROUPS_TABLE . ' g';
    // If not admin: LEFT JOIN to user_group, filter hidden groups
    $sql .= 'g.group_receive_pm = 1
        ORDER BY g.group_type DESC, g.group_name ASC';
}
```

**Group must have `group_receive_pm = 1`** to appear as a PM target. Hidden groups only visible to members.

### Group expansion in `submit_pm()`
**Source**: `functions_privmsgs.php:1706-1730`

When a group is in the address list, its members are expanded at delivery time:
```php
if (isset($data_ary['address_list']['g']) && count($data_ary['address_list']['g']))
{
    $sql_allow_pm = (!$auth->acl_gets('a_', 'm_') && !$auth->acl_getf_global('m_'))
        ? ' AND u.user_allow_pm = 1' : '';

    $sql = 'SELECT u.user_type, ug.group_id, ug.user_id
        FROM ' . USERS_TABLE . ' u, ' . USER_GROUP_TABLE . ' ug
        WHERE ' . $db->sql_in_set('ug.group_id', array_keys($data_ary['address_list']['g'])) . '
            AND ug.user_pending = 0
            AND u.user_id = ug.user_id
            AND u.user_type IN (' . USER_NORMAL . ', ' . USER_FOUNDER . ')' .
            $sql_allow_pm;

    while ($row = $db->sql_fetchrow($result))
    {
        $field = ($data_ary['address_list']['g'][$row['group_id']] == 'to') ? 'to' : 'bcc';
        $recipients[$row['user_id']] = $field;
    }
}
```

**Key behaviors**:
- Group members are expanded into individual `$recipients` entries
- Pending group members excluded (`user_pending = 0`)
- Only `USER_NORMAL` and `USER_FOUNDER` types included
- If sender is not admin/mod: users with `user_allow_pm = 0` filtered out
- The `to_address`/`bcc_address` columns still store `g_N` (group reference preserved)
- But `privmsgs_to` table gets individual user rows

---

## 6. Max Recipients Enforcement

**Source**: `ucp_pm_compose.php:589-636`

```php
// Get maximum number of allowed recipients
$max_recipients = phpbb_get_max_setting_from_group($db, $user->data['user_id'], 'max_recipients');

// If it is 0, there is no limit set and we use the maximum value within the config.
$max_recipients = (!$max_recipients) ? $config['pm_max_recipients'] : $max_recipients;
```

**Enforcement steps**:
1. Check from user's group settings first (per-group override)
2. Fall back to global `pm_max_recipients` config
3. For reply-to-all: limit is increased if original TO list exceeds it:
   ```php
   if (($action == 'reply' || $action == 'quote') && $max_recipients && $reply_to_all)
   {
       $list = rebuild_header(array('to' => $post['to_address']));
       $list = (!empty($list['u'])) ? $list['u'] : array();
       $list[$post['author_id']] = 'to';
       unset($list[$user->data['user_id']]);
       $max_recipients = ($max_recipients < count($list)) ? count($list) : $max_recipients;
   }
   ```
4. If exceeded, address list is truncated:
   ```php
   if (!empty($address_list['u']) && $max_recipients && count($address_list['u']) > $max_recipients)
   {
       $address_list = get_recipients($address_list, $max_recipients);
       $error[] = $user->lang('TOO_MANY_RECIPIENTS', $max_recipients);
   }
   ```

**Note**: `$max_recipients` counts user entries in `address_list['u']`, NOT expanded group members.

### Mass PM permissions
**Source**: `ucp_pm_compose.php:91`

```php
$select_single = ($config['allow_mass_pm'] && $auth->acl_get('u_masspm')) ? false : true;
```

Three permission levels:
| Permission | Effect |
|---|---|
| `allow_mass_pm` config = 0 | Everyone limited to 1 recipient |
| `u_masspm` | Can send to multiple individual users |
| `u_masspm_group` | Can send to groups |

If `allow_mass_pm` is off or user lacks `u_masspm`, only 1 recipient allowed:
```php
if ((!$config['allow_mass_pm'] || !$auth->acl_get('u_masspm')) && num_recipients($address_list) > 1)
{
    $address_list = get_recipients($address_list, 1);
    $error[] = $user->lang('TOO_MANY_RECIPIENTS', 1);
}
```

---

## 7. Blocking / Foe System

### Not a send-time block — it's a rule-based action system

The foe list (**`phpbb_zebra`** with `foe=1`) does **NOT** prevent PM delivery at compose time. Instead, it works through the **PM rules engine**.

**Source**: `functions_privmsgs.php:460-550`

The rules system is invoked when messages are placed into folders. It checks:
```php
$sql = 'SELECT zebra_id, friend, foe
    FROM ' . ZEBRA_TABLE . "
    WHERE user_id = $user_id";
```

Then builds check rows with foe status:
```php
$check_rows[] = array_merge($row, array(
    'friend' => (isset($zebra[$row['author_id']])) ? $zebra[$row['author_id']]['friend'] : 0,
    'foe'    => (isset($zebra[$row['author_id']])) ? $zebra[$row['author_id']]['foe'] : 0,
));
```

**RULE_IS_FOE** (`functions_privmsgs.php:297`): Simply checks `$check0 == 1` (foe flag is set).

**Possible rule actions**: Place into folder, mark as read, mark as important, **DELETE message**. So a user can set up a rule: "If sender is foe → delete message" which effectively blocks PMs.

### What IS checked at send time

**Source**: `ucp_pm_compose.php:1524-1600` (`handle_message_list_actions`)

These checks happen when adding recipients:
1. **`user_allow_pm = 0`**: User has disabled PMs globally → removed from list (unless sender is admin/mod)
2. **`user_type = INACTIVE` with `INACTIVE_MANUAL`**: Manually deactivated → removed
3. **`u_readpm` permission**: User lacks permission to read PMs → removed
4. **Banned users**: Banned → removed

**No foe check at send time.** The foe system is recipient-side, not sender-side.

---

## 8. Author as Participant (Sender in `privmsgs_to`)

**Source**: `functions_privmsgs.php:1839-1851`

The sender IS stored in `privmsgs_to` — but separately, in the outbox:

```php
// Put PM into outbox
if ($put_in_outbox)
{
    $db->sql_query('INSERT INTO ' . PRIVMSGS_TO_TABLE . ' ' . $db->sql_build_array('INSERT', array(
        'msg_id'        => (int) $data_ary['msg_id'],
        'user_id'       => (int) $data_ary['from_user_id'],
        'author_id'     => (int) $data_ary['from_user_id'],
        'folder_id'     => PRIVMSGS_OUTBOX,
        'pm_new'        => 0,
        'pm_unread'     => 0,
        'pm_forwarded'  => ($mode == 'forward') ? 1 : 0))
    );
}
```

**Key findings**:
- Sender gets a row with `folder_id = PRIVMSGS_OUTBOX`
- Sender row has `pm_new=0`, `pm_unread=0` (they wrote it, they've read it)
- Both `user_id` and `author_id` point to the sender
- The `$put_in_outbox` parameter allows skipping this (e.g., for system-generated PMs)

### Recipient rows (for comparison):
```php
$sql_ary[] = array(
    'msg_id'        => (int) $data_ary['msg_id'],
    'user_id'       => (int) $user_id,          // the recipient
    'author_id'     => (int) $data_ary['from_user_id'], // still the sender
    'folder_id'     => PRIVMSGS_NO_BOX,          // unprocessed (rules will place)
    'pm_new'        => 1,
    'pm_unread'     => 1,
    'pm_forwarded'  => ($mode == 'forward') ? 1 : 0
);
```

**So `author_id` in `privmsgs_to` always = the message sender, while `user_id` = the participant (recipient or sender).**

---

## 9. Reply & Reply-All

**Source**: `ucp_pm_compose.php:453-474`

### Normal Reply (single recipient)
```php
if ($action == 'quotepost' || !$reply_to_all)
{
    $address_list = array('u' => array($post['author_id'] => 'to'));
}
```
Just replies to the original author.

### Reply-to-All
```php
else
{
    // We try to include every previously listed member from the TO Header
    $address_list = rebuild_header(array('to' => $post['to_address']));

    // Add the author
    $address_list['u'][$post['author_id']] = 'to';

    // Remove self
    if (isset($address_list['u'][$user->data['user_id']]))
    {
        unset($address_list['u'][$user->data['user_id']]);
    }
}
```

**Key behaviors**:
- Reply-all rebuilds from `to_address` only — **BCC recipients are NOT included** in reply-all
- The original author is always added as a TO recipient
- The current user (replier) is removed from the list
- Groups from the original TO header are included (`$address_list['g']` returned by `rebuild_header`)
- The `max_recipients` limit is dynamically raised to accommodate reply-all:
  ```php
  $max_recipients = ($max_recipients < count($list)) ? count($list) : $max_recipients;
  ```

---

## 10. Address List Data Structure (In-Memory)

Throughout the compose flow, the address list uses this structure:

```php
$address_list = array(
    'u' => array(
        <user_id> => 'to' | 'bcc',
        <user_id> => 'to' | 'bcc',
    ),
    'g' => array(
        <group_id> => 'to' | 'bcc',
    ),
);
```

This is:
- Submitted via hidden form fields: `address_list[u][<id>]=to`
- Managed by `handle_message_list_actions()` (add/remove users/groups)
- Passed to `submit_pm()` via `$pm_data['address_list']`
- Serialized to `to_address`/`bcc_address` via `implode(':', $to)` / `implode(':', $bcc)`

---

## 11. Forward Handling

**Source**: `ucp_pm_compose.php:1133-1165`

When forwarding, the TO recipients from the original message are resolved for display in the forward header:
```php
$fwd_to_field = write_pm_addresses(array('to' => $post['to_address']), 0, true);
// ...
$forward_text[] = sprintf($user->lang['FWD_TO'], implode($user->lang['COMMA_SEPARATOR'], $fwd_to_field['to']));
```

The `plaintext=true` parameter returns flat username arrays instead of template blocks.

---

## 12. Recipient Validation Summary

Complete validation chain in `handle_message_list_actions()` (`ucp_pm_compose.php:1415-1600`):

| Check | Condition | Result |
|-------|-----------|--------|
| Username resolution | `user_get_id_name()` finds no match | Error: PM_NO_USERS |
| ANONYMOUS check | `user_id == ANONYMOUS` | Silently skipped |
| Inactive user | `user_type=INACTIVE` + `INACTIVE_MANUAL` | Removed, error |
| PM disabled | `user_allow_pm = 0` (unless sender is admin/mod) | Removed, PM_USERS_REMOVED_NO_PM |
| No read permission | User lacks `u_readpm` | Removed, PM_USERS_REMOVED_NO_PERMISSION |
| Banned | User is banned | Removed, PM_USERS_REMOVED_NO_PERMISSION |
| Group PM denied | No `u_masspm_group` or `allow_mass_pm=0` | Error: NO_AUTH_GROUP_MESSAGE |
| Mass PM denied | No `u_masspm` or `allow_mass_pm=0` with >1 recipient | Trimmed to 1 |
| Too many recipients | Count > `pm_max_recipients` | Trimmed to limit |
| No recipients | Empty address list on submit | Error: NO_RECIPIENT |

**Notable absence**: No foe/friend check at send time. No "this user has blocked you" error.

---

## 13. `num_recipients()` and `get_recipients()` Helper Functions

**Source**: `ucp_pm_compose.php:1638-1670`

```php
function num_recipients($address_list)
{
    $num_recipients = 0;
    foreach ($address_list as $field => $adr_ary)
    {
        $num_recipients += count($adr_ary);
    }
    return $num_recipients;
}
```

Counts total entries across `u` and `g` arrays.

```php
function get_recipients($address_list, $num_recipients = 1)
```

Returns first N recipients from the address list (used for truncation when limit exceeded).

---

## Summary of Key Design Decisions

1. **Dual storage**: Address headers (`to_address`/`bcc_address`) in `phpbb_privmsgs` for display, individual rows in `phpbb_privmsgs_to` for per-user state
2. **Group references preserved**: `g_N` stored in address strings even after expansion to individual users
3. **BCC is truly blind**: Only visible to sender and the BCC'd user themselves
4. **Reply-all excludes BCC**: Only TO recipients included when replying to all
5. **Sender is a participant**: Gets their own `privmsgs_to` row in OUTBOX folder
6. **Foe blocking is recipient-side**: Via rules engine, not send-time prevention
7. **Max recipients is per-user-count**: Groups count as 1 toward the limit, but expand to many `privmsgs_to` rows
8. **Validation is progressive**: Users are silently removed, errors collected, compose page re-shown
