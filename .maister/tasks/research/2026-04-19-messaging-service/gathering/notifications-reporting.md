# PM Notifications & Reporting System — Findings

## 1. PM Notification Type (`notification.type.pm`)

**Source**: `src/phpbb/forums/notification/type/pm.php` (full file, 207 lines)

### Class Hierarchy
- Extends `\phpbb\notification\type\base` which implements `\phpbb\notification\type\type_interface`
- `base` is defined in `src/phpbb/forums/notification/type/base.php:19`

### Availability Gate
```php
// pm.php:66-69
public function is_available()
{
    return ($this->config['allow_privmsg'] && $this->auth->acl_get('u_readpm'));
}
```
Notification only available when PMs enabled globally AND user has `u_readpm` permission.

### User Preferences
- Static `$notification_option` with `lang => 'NOTIFICATION_TYPE_PM'` — displayed in UCP notification settings
- No explicit `group` key → falls into default notification group
- Users control PM notifications through UCP notification options via `check_user_notification_options()` call

### Triggering — How a PM Notification Is Created
PM notification is triggered inside `submit_pm()` at the end of the function:

```php
// functions_privmsgs.php:1952-1966
$pm_data = array_merge($data_ary, array(
    'message_subject'  => $subject,
    'recipients'       => $recipients,
));

$phpbb_notifications = $phpbb_container->get('notification_manager');

if ($mode == 'edit')
{
    $phpbb_notifications->update_notifications('notification.type.pm', $pm_data);
}
else
{
    $phpbb_notifications->add_notifications('notification.type.pm', $pm_data);
}
```

- **On new PM** (post/reply/quote/forward): `add_notifications()` is called
- **On PM edit**: `update_notifications()` is called (updates subject/data but doesn't re-notify)

### Multi-Recipient Handling
```php
// pm.php:96-111
public function find_users_for_notification($pm, $options = array())
{
    $options = array_merge(array(
        'ignore_users' => array(),
    ), $options);

    if (!count($pm['recipients'])) { return array(); }

    unset($pm['recipients'][$pm['from_user_id']]);

    $this->user_loader->load_users(array_keys($pm['recipients']));

    return $this->check_user_notification_options(array_keys($pm['recipients']), $options);
}
```
- **One notification per recipient** — each recipient in `$pm['recipients']` gets checked
- Sender is excluded from recipients (`unset($pm['recipients'][$pm['from_user_id']])`)
- `check_user_notification_options()` filters based on each user's notification preferences

### Data Stored in Notification
```php
// pm.php:195-201
public function create_insert_array($pm, $pre_create_data = array())
{
    $this->set_data('from_user_id', $pm['from_user_id']);
    $this->set_data('message_subject', $pm['message_subject']);
    parent::create_insert_array($pm, $pre_create_data);
}
```
Notification carries: `from_user_id`, `message_subject`, plus `item_id` = `msg_id`.

### Email Template
```php
// pm.php:148-150
public function get_email_template()
{
    return 'privmsg_notify';
}
```
Email template: **`privmsg_notify`** — sends email with variables:
- `AUTHOR_NAME` — sender username
- `SUBJECT` — PM subject (censored)
- `U_VIEW_MESSAGE` — link to `ucp.php?i=pm&mode=view&p={msg_id}`

### Notification URL
```php
// pm.php:174-177
public function get_url()
{
    return append_sid($this->phpbb_root_path . 'ucp.' . $this->php_ext, "i=pm&amp;mode=view&amp;p={$this->item_id}");
}
```
Links to `ucp.php?i=pm&mode=view&p={msg_id}`.

### Display
- **Title**: `NOTIFICATION_PM` language key with sender username
- **Reference**: `NOTIFICATION_REFERENCE` with message subject
- **Avatar**: Sender's avatar

### Notification Lifecycle
1. **Created**: On `submit_pm()` → `add_notifications('notification.type.pm', ...)`
2. **Marked read**: On `update_unread_status()` → `mark_notifications('notification.type.pm', $msg_id, $user_id)` (functions_privmsgs.php:898)
3. **Deleted**: On PM deletion → `delete_notifications('notification.type.pm', ...)` — happens in multiple places:
   - `delete_pm()` at functions_privmsgs.php:1170
   - Full user PM deletion at functions_privmsgs.php:1332, 1345, 1387

### Notification Methods Available
Based on `src/phpbb/forums/notification/method/`:
- **`board`** — In-browser notification (the popup/dropdown)
- **`email`** — Email notification via `messenger_base`
- **`jabber`** — Jabber/XMPP notification via `messenger_base`

Users can independently enable/disable each method per notification type in UCP.

---

## 2. User Unread PM Counters

**Source**: `src/phpbb/common/functions_privmsgs.php` (multiple locations)

### Two Counter Fields in `phpbb_users` Table

| Field | Meaning | Decremented when |
|-------|---------|-----------------|
| `user_new_privmsg` | PMs not yet placed into a folder (in NO_BOX/HOLD_BOX) | PM placed into folder, PM deleted |
| `user_unread_privmsg` | PMs not yet read (pm_unread=1) | PM read via `update_unread_status()`, PM deleted |

### On PM Send (functions_privmsgs.php:1832)
```php
$sql = 'UPDATE ' . USERS_TABLE . '
    SET user_new_privmsg = user_new_privmsg + 1, user_unread_privmsg = user_unread_privmsg + 1, user_last_privmsg = ' . time() . '
    WHERE ' . $db->sql_in_set('user_id', array_keys($recipients));
```
Both counters incremented by 1 for each recipient.

### On PM Read (functions_privmsgs.php:900-931)
```php
$phpbb_notifications->mark_notifications('notification.type.pm', $msg_id, $user_id);

$sql = 'UPDATE ' . PRIVMSGS_TO_TABLE . "
    SET pm_unread = 0
    WHERE msg_id = $msg_id AND user_id = $user_id AND folder_id = $folder_id AND pm_unread = 1";
// Then: user_unread_privmsg = user_unread_privmsg - 1
```
Notification is marked read simultaneously with the unread counter decrement.

### `update_pm_counts()` (functions_privmsgs.php:367-410)
Full recount function that queries actual counts from `phpbb_privmsgs_to` table and syncs to `phpbb_users`. Also repairs stale `pm_new` flags.

---

## 3. PM Report Notification (`notification.type.report_pm`)

**Source**: `src/phpbb/forums/notification/type/report_pm.php` (full file, 256 lines)

### Class Hierarchy
- Extends `\phpbb\notification\type\pm` (inherits PM notification base)

### Key Properties
```php
static public $notification_option = [
    'id'    => 'notification.type.report_pm',
    'lang'  => 'NOTIFICATION_TYPE_REPORT_PM',
    'group' => 'NOTIFICATION_GROUP_MODERATION',
];
protected $language_key = 'NOTIFICATION_REPORT_PM';
protected $permission = 'm_pm_report';
```
- In **moderation** notification group
- Requires `m_pm_report` permission to see/receive

### Availability
```php
// report_pm.php:90-94
public function is_available()
{
    return $this->config['allow_pm_report'] &&
        !empty($this->auth->acl_get($this->permission));
}
```
Requires both `allow_pm_report` config AND `m_pm_report` permission.

### Who Receives It — Moderators Only
```php
// report_pm.php:106-126
public function find_users_for_notification($post, $options = [])
{
    $post['forum_id'] = 0; // Global scope
    $auth_approve = $this->auth->acl_get_list(false, $this->permission, $post['forum_id']);
    // Exclude the reporter themselves
    if (($key = array_search($this->user->data['user_id'], ...)))
    {
        unset(...);
    }
    return $this->check_user_notification_options($auth_approve[...], ...);
}
```
- Finds all users with `m_pm_report` permission at global level (`forum_id = 0`)
- **Excludes the reporter** from notification recipients
- Notification goes to **moderators**, not the reported user

### Data Stored
```php
// report_pm.php:237-244
public function create_insert_array($post, $pre_create_data = [])
{
    $this->set_data('reporter_id', $this->user->data['user_id']);
    $this->set_data('reason_title', strtoupper($post['reason_title']));
    $this->set_data('reason_description', $post['reason_description']);
    $this->set_data('report_text', $post['report_text']);
    parent::create_insert_array($post, $pre_create_data); // stores from_user_id, message_subject
}
```
Carries: `reporter_id`, `reason_title`, `reason_description`, `report_text`, plus inherited `from_user_id`, `message_subject`.

### Display
- **Title**: `NOTIFICATION_REPORT_PM` with reporter's username
- **Reference**: Message subject
- **Reason**: Shows `report_text`, or localized `reason_title`, or `reason_description`
- **Avatar**: Reporter's avatar
- **URL**: Links to `mcp.php?r={report_id}&i=pm_reports&mode=pm_report_details`
- **CSS class**: `notification-reported`

### Email Template
```php
public function get_email_template() { return 'report_pm'; }
```
Template: **`report_pm`** with variables:
- `AUTHOR_NAME` — PM sender username
- `SUBJECT` — PM subject
- `TOPIC_TITLE` — deprecated alias for SUBJECT
- `U_VIEW_REPORT` — link to MCP report details

---

## 4. Report PM Closed Notification (`notification.type.report_pm_closed`)

**Source**: `src/phpbb/forums/notification/type/report_pm_closed.php` (full file, 171 lines)

### Purpose
Notifies the **reporter** when their PM report is resolved (closed).

### Key Properties
```php
static public $notification_option = [
    'id'    => 'notification.type.report_pm_closed',
    'lang'  => 'NOTIFICATION_TYPE_REPORT_PM_CLOSED',
    'group' => 'NOTIFICATION_GROUP_MISCELLANEOUS',
];
protected $language_key = 'NOTIFICATION_REPORT_PM_CLOSED';
public $email_template = 'report_pm_closed';
```

### Who Receives It — Reporter Only
```php
// report_pm_closed.php:72-82
public function find_users_for_notification($pm, $options = [])
{
    if ($pm['reporter'] == $this->user->data['user_id']) { return []; }
    return $this->check_user_notification_options([$pm['reporter']], $options);
}
```
- Only the original reporter
- Excludes case where moderator closes their own report

### Data Stored
```php
public function create_insert_array($pm, $pre_create_data = [])
{
    $this->set_data('closer_id', $pm['closer_id']);
    parent::create_insert_array($pm, $pre_create_data);
    $this->notification_time = time(); // Override time to closure time
}
```

### Display
- **Title**: `NOTIFICATION_REPORT_PM_CLOSED` with closer's username
- **Avatar**: Closer's (moderator's) avatar
- **Email variables**: `AUTHOR_NAME` (PM sender), `CLOSER_NAME` (moderator), `SUBJECT`, `U_VIEW_MESSAGE`

---

## 5. PM Report Handler

**Source**: `src/phpbb/forums/report/report_handler_pm.php` (full file, 128 lines)

### Class Hierarchy
- Extends `phpbb\report\report_handler` (abstract, in `src/phpbb/forums/report/report_handler.php`)
- Implements `report_handler_interface`
- Dependencies injected: `$db`, `$dispatcher`, `$config`, `$auth`, `$user`, `$notification` (manager)

### Report Flow: `add_report($id, $reason_id, $report_text, $user_notify)`

**Step 1 — Validation** via `validate_report_request($id)`:
- Checks `allow_pm_report` config → throws `pm_reporting_disabled_exception`
- Queries `phpbb_privmsgs` + `phpbb_privmsgs_to` to verify user is author or recipient
- Checks `message_reported` flag → throws `already_reported_exception` if already reported
- Stores full PM data in `$this->report_data`

**Step 2 — Reason lookup**:
- Queries `phpbb_reports_reasons` for the given `reason_id`
- If reason is "other" and report_text is empty → throws `empty_report_exception`

**Step 3 — Create report record** (via parent `create_report()`):
Inserts into `phpbb_reports` table with:
```php
$report_data = array(
    'reason_id'                  => $reason_id,
    'post_id'                    => 0,       // 0 = PM report (not post)
    'pm_id'                      => $id,
    'user_notify'                => $user_notify,
    'report_text'                => $report_text,
    'reported_post_text'         => $this->report_data['message_text'],  // Full PM content stored!
    'reported_post_uid'          => $this->report_data['bbcode_uid'],
    'reported_post_bitfield'     => $this->report_data['bbcode_bitfield'],
    'reported_post_enable_bbcode'    => ...,
    'reported_post_enable_smilies'   => ...,
    'reported_post_enable_magic_url' => ...,
);
```

**Step 4 — Mark PM as reported**:
```php
$sql = 'UPDATE ' . PRIVMSGS_TABLE . ' SET message_reported = 1 WHERE msg_id = ' . $id;
```

**Step 5 — Grant ANONYMOUS user access to PM** (for MCP viewing):
```php
$sql_ary = array(
    'msg_id'     => $id,
    'user_id'    => ANONYMOUS,
    'author_id'  => (int) $this->report_data['author_id'],
    'pm_deleted' => 0,
    'pm_new'     => 0,
    'pm_unread'  => 0,
    'folder_id'  => PRIVMSGS_INBOX,
);
// INSERT INTO phpbb_privmsgs_to ...
```
This is the **privacy mechanism**: a row for `user_id = ANONYMOUS` is inserted into `privmsgs_to`, which allows the MCP report view to access PM content without giving moderators direct PM access. The report also stores a copy of the message text in `reported_post_text`.

**Step 6 — Trigger notification**:
```php
$this->notifications->add_notifications('notification.type.report_pm', array_merge($this->report_data, $row, array(
    'report_text'   => $report_text,
    'from_user_id'  => $this->report_data['author_id'],
    'report_id'     => $report_id,
)));
```

### Privacy Implications
- **Moderators CAN read the PM content** — the full message text is copied to `reported_post_text` in the reports table AND an ANONYMOUS access row is added to `privmsgs_to`
- The reported user is **NOT notified** of the report
- Only moderators with `m_pm_report` permission see the report notification

---

## 6. MCP PM Reports Panel

**Source**: `src/phpbb/common/mcp/mcp_pm_reports.php` (full file, ~370 lines)

### Module Structure
Class `mcp_pm_reports` — MCP module with modes:
1. **`pm_report_details`** — View a single PM report
2. **`pm_reports`** — List open PM reports
3. **`pm_reports_closed`** — List closed PM reports

### Actions Available

**Close action** (`action == 'close'`):
- Delegates to `close_report()` in `mcp_reports.php` (shared function)
- Sets `report_closed = 1` in reports table
- Clears `message_reported = 0` on the PM
- Notifies reporter via `notification.type.report_pm_closed` if `user_notify` was set
- Deletes `notification.type.report_pm` notifications for moderators
- Logs `LOG_PM_REPORT_CLOSED`

**Delete action** (`action == 'delete'`):
- Delegates to `close_report()` with action='delete'
- Deletes report from `phpbb_reports` table entirely
- Clears `message_reported = 0`
- Additionally: **deletes the ANONYMOUS inbox copy** via `delete_pm(ANONYMOUS, $close_report_posts, PRIVMSGS_INBOX)`
- Deletes `notification.type.report_pm` notifications
- Notifies reporter if `user_notify` set
- Logs `LOG_PM_REPORT_DELETED`

### Report Detail View (pm_report_details mode)
Shows:
- Report reason (title + description)
- Report text (reporter's comment)
- **Full PM content** — rendered with BBCode, smilies, etc.
- PM attachments (if user has `u_pm_download`)
- PM author info (username, profile link, IP)
- Reporter info (username, profile link)
- Links to: user notes (reporter + author), warn user (reporter + author)
- Option to close or delete report

Key template variables:
```php
'S_MCP_REPORT'    => true,
'S_PM'            => true,
'POST_PREVIEW'    => $message,    // Full rendered PM content
'POST_SUBJECT'    => $pm_info['message_subject'],
'POST_IP'         => $pm_info['author_ip'],
```

### Moderator Actions from Report View
Via template links:
- **View reporter notes**: `mcp.php?i=notes&mode=user_notes&u={reporter_id}`
- **View author notes**: `mcp.php?i=notes&mode=user_notes&u={author_id}`
- **Warn reporter**: `mcp.php?i=warn&mode=warn_user&u={reporter_id}` (requires `m_warn`)
- **Warn PM author**: `mcp.php?i=warn&mode=warn_user&u={author_id}` (requires `m_warn`)

### Reports List View
Shows paginated list of open/closed reports with:
- PM author (full profile link)
- Reporter (full profile link)
- PM subject
- PM time, report time
- Recipients list
- Attachment icon

---

## 7. Close Report Flow (Shared Function)

**Source**: `src/phpbb/common/mcp/mcp_reports.php:607-870`

### `close_report($report_id_list, $mode, $action, $pm = false)`

Shared between post reports and PM reports. When `$pm = true`:

1. **Auth check**: `$auth->acl_getf_global('m_report')`
2. **Confirm box**: Shows confirm dialog
3. **On confirm**:
   - Fetches report details + PM data via `phpbb_get_pm_data()`
   - Builds list of reports, identifies reporters who requested notification (`user_notify`)
   - If `action == 'close'`: `UPDATE reports SET report_closed = 1`
   - If `action == 'delete'`: `DELETE FROM reports`
   - Clears `message_reported = 0` on PMs
   - If delete: `delete_pm(ANONYMOUS, $close_report_posts, PRIVMSGS_INBOX)` — removes ANONYMOUS access copy
4. **Notification cleanup**:
   ```php
   $phpbb_notifications->delete_notifications('notification.type.report_pm', $report['pm_id']);
   ```
5. **Reporter notification** (if `user_notify` && not closed):
   ```php
   $phpbb_notifications->add_notifications('notification.type.report_pm_closed', array_merge($post_info[$post_id], array(
       'reporter'      => $reporter['user_id'],
       'closer_id'     => $user->data['user_id'],
       'from_user_id'  => $post_info[$post_id]['author_id'],
   )));
   ```

---

## 8. Integration Summary

### With phpBB Notification System
All three notification types implement `\phpbb\notification\type\type_interface` through the `base` class:
- **`pm`**: Standard recipient notification
- **`report_pm`** extends `pm`: Moderator notification (inherits data structure)
- **`report_pm_closed`** extends `pm`: Reporter notification (inherits data structure)

All use `notification_manager->add_notifications()`, `mark_notifications()`, `delete_notifications()`, `update_notifications()`.

### With Moderation System
- PM reports stored in same `phpbb_reports` table as post reports (differentiated by `post_id = 0` AND `pm_id > 0`)
- Close/delete action uses shared `close_report()` function with `$pm = true` parameter
- MCP module `pm_reports` registered separately from post `reports`
- Uses shared MCP template `mcp_post` for detail view, `mcp_reports` for list view

### Notification Methods (Delivery Channels)
Located in `src/phpbb/forums/notification/method/`:
| Method | Class | Description |
|--------|-------|-------------|
| Board | `board` | In-app notification (popup/dropdown) |
| Email | `email` | Email delivery extending `messenger_base` |
| Jabber | `jabber` | XMPP delivery extending `messenger_base` |

### User PM Counter Maintenance
- `user_new_privmsg`: Incremented on send, decremented on folder placement or deletion
- `user_unread_privmsg`: Incremented on send, decremented on read (`update_unread_status()`) or deletion
- `update_pm_counts()`: Full recount/repair function
- Both counters are in `phpbb_users` table for fast access in templates

### "User Has Unread PM" Display
The `user_unread_privmsg` and `user_new_privmsg` fields on `$user->data` are used by the overall page template to show "You have X unread messages" indicator. The phpBB notification popup combines board notifications with these PM counts.

---

## 9. Key Observations for New Service Design

1. **PM notification is tightly coupled to `submit_pm()`** — the notification trigger is inline at the end of that function, not in a separate service
2. **Report creates a copy of PM content** in report table + ANONYMOUS inbox entry — this is the mechanism for moderator access to private messages
3. **Reported user is never notified** of the report — only moderators get `report_pm` notification
4. **Reporter is notified on close** only if they checked `user_notify` — via `report_pm_closed` notification type
5. **Three notification types form a chain**: `pm` (send) → `report_pm` (report to mods) → `report_pm_closed` (closure to reporter)
6. **All three extend `pm`** — `report_pm` and `report_pm_closed` inherit from `pm` and override key methods
7. **Counter management is scattered** — `user_unread_privmsg`/`user_new_privmsg` are manipulated in at least 8 places in `functions_privmsgs.php`
8. **Permission gate**: `m_pm_report` for moderator report access, `allow_pm_report` config for feature toggle
