# Codebase Findings: phpBB Notification System Internals

## 1. Notification Manager (`src/phpbb/forums/notification/manager.php`)

### Constructor Dependencies (Lines 77-92)

```php
public function __construct(
    $notification_types,           // array — service_collection tagged notification.type
    $notification_methods,         // array — service_collection tagged notification.method
    ContainerInterface $phpbb_container,
    \phpbb\user_loader $user_loader,
    \phpbb\event\dispatcher_interface $phpbb_dispatcher,
    \phpbb\db\driver\driver_interface $db,
    \phpbb\cache\service $cache,
    \phpbb\language\language $language,
    \phpbb\user $user,
    $notification_types_table,     // string — %tables.notification_types%
    $user_notifications_table      // string — %tables.user_notifications%
)
```

DI Config: `services_notification.yml:2-12` — registered as `notification_manager`.

### Full Public API Surface

| Method | Signature | Lines | Purpose |
|--------|-----------|-------|---------|
| `load_notifications` | `(string $method_name, array $options = [])` | 97–135 | Delegates to method class for loading/counting |
| `mark_notifications_read` | `($notification_type_name, $item_id, $user_id, $time=false)` | 143–148 | **Deprecated** — wraps `mark_notifications()` |
| `mark_notifications` | `($notification_type_name, $item_id, $user_id, $time=false, $mark_read=true)` | 159–179 | Mark read/unread across all available methods |
| `mark_notifications_read_by_parent` | `($notification_type_name, $item_parent_id, $user_id, $time=false)` | 195–199 | **Deprecated** — wraps `mark_notifications_by_parent()` |
| `mark_notifications_by_parent` | `($notification_type_name, $item_parent_id, $user_id, $time=false, $mark_read=true)` | 208–226 | Mark read by parent identifier across methods |
| `mark_notifications_by_id` | `(string $method_name, $notification_id, $time=false, $mark_read=true)` | 233–242 | Mark specific notification IDs read for one method |
| `add_notifications` | `($notification_type_name, $data, array $options = [])` | 253–322 | Main entry point — dispatches events, finds users, queues |
| `add_notifications_for_users` | `($notification_type_name, $data, $notify_users)` | 329–413 | Low-level: creates insert arrays, adds to method queues, flushes |
| `update_notifications` | `($notification_type_name, array $data, array $options = [])` | 420–435 | Update notification data across all methods |
| `update_notification` | `(type_interface $notification, array $data, array $options = [])` | 442–455 | Update single notification type across methods |
| `delete_notifications` | `($notification_type_name, $item_id, $parent_id=false, $user_id=false)` | 462–480 | Delete notifications by type + item across methods |
| `get_subscription_types` | `()` | 487–524 | Returns all notification types grouped by category |
| `get_subscription_methods` | `()` | 531–548 | Returns all available method instances |
| `get_global_subscriptions` | `($user_id = false)` | 628–674 | Returns user's global subscription preferences |
| `add_subscription` | `($item_type, $item_id=0, $method=null, $user_id=false)` | 681–733 | Add notification subscription for user |
| `delete_subscription` | `($item_type, $item_id=0, $method=null, $user_id=false)` | 740–774 | Delete/disable notification subscription |
| `disable_notifications` | `(string $notification_type_name)` | 782–787 | Disable all of a type (for extension disable) |
| `enable_notifications` | `(string $notification_type_name)` | 822–827 | Re-enable all of a type |
| `purge_notifications` | `(string $notification_type_name)` | 795–816 | Remove all of a type (for extension purge) |
| `prune_notifications` | `(int $timestamp, $only_read=true)` | 834–840 | Delete old notifications |
| `get_default_methods` | `()` | 847–859 | Returns methods enabled by default |
| `get_item_type_class` | `(string $name, array $data = [])` | 907–914 | Factory: instantiate notification type |
| `get_method_class` | `(string $method_name)` | 921–924 | Factory: instantiate notification method |
| `get_notification_type_id` | `(string $name)` | 941–979 | Resolves type name → numeric ID (cached 1 week, auto-creates) |
| `get_notification_type_ids` | `($names)` | 986–999 | Batch version of above |
| `get_notified_users` | `($notification_type_name, array $options)` | 1007–1020 | Find already-notified users across all methods |

### Event Dispatching Points

1. **`core.notification_manager_add_notifications_before`** (Line ~280):  
   Fired before `find_users_for_notification()`. Can override entirely via `$add_notifications_override`.  
   Variables: `notification_type_name`, `data`, `notified_users`, `options`.

2. **`core.notification_manager_add_notifications`** (Line ~308):  
   Fired after `find_users_for_notification()` but before queueing. Allows filtering `$notify_users`.

3. **`core.notification_manager_add_notifications_for_users_modify_data`** (Line ~380):  
   Fired inside `add_notifications_for_users()` after dedup, before `pre_create_insert_array()`.

### add_notifications Flow (Lines 253–322)

1. Dispatch `core.notification_manager_add_notifications_before` event
2. If array of types → recursively call self for each, merging `ignore_users`
3. Call `find_users_for_notification($data, $options)` on the type class
4. Dispatch `core.notification_manager_add_notifications` event (filter `$notify_users`)
5. Call `add_notifications_for_users()` with filtered users

### add_notifications_for_users Flow (Lines 329–413)

1. Resolve `notification_type_id` from name
2. Get `item_id` from the type class
3. For each method, call `get_notified_users()` to deduplicate already-notified users
4. Dispatch `core.notification_manager_add_notifications_for_users_modify_data` event
5. Call `pre_create_insert_array()` for the type (batch data loading)
6. For each user → instantiate type, set `user_id`, call `create_insert_array()`
7. For each method preference → `add_to_queue()` on the method instance
8. `user_loader->load_users()` for all affected users
9. For each method → `$method->notify()` (flushes the queue)

### load_notifications Flow (Lines 97–135)

1. Get method class instance via `get_method_class()`
2. Verify it's a `method_interface` instance
3. Check `is_available()` → if not, return empty result
4. Delegate entirely to `$method->load_notifications($options)`

---

## 2. Notification Types

### type_interface (`src/phpbb/forums/notification/type/type_interface.php`)

Full interface contract (28 methods):

| Method | Returns | Purpose |
|--------|---------|---------|
| `get_type()` | string | Service name e.g. `notification.type.post` |
| `set_initial_data($data)` | void | Hydrate from DB row |
| `get_item_id($type_data)` | int | **Static** — extract item ID from data |
| `get_item_parent_id($type_data)` | int | **Static** — extract parent ID |
| `is_available()` | bool | Whether type shows in UCP settings |
| `find_users_for_notification($type_data, $options)` | array | Core: determine recipients |
| `users_to_query()` | array | User IDs needed for display |
| `get_load_special()` | array | Data keys for batch loading |
| `load_special($data, $notifications)` | void | Batch-load auxiliary data |
| `get_style_class()` | string | CSS class for notification |
| `get_title()` | string | HTML formatted title |
| `get_reference()` | string | HTML reference text |
| `get_forum()` | string | Forum name reference |
| `get_url()` | string | URL to the item |
| `get_redirect_url()` | string | URL after mark-read redirect |
| `get_unsubscribe_url($method)` | string | Unsubscribe URL |
| `get_avatar()` | string | Avatar HTML of the causer |
| `prepare_for_display()` | array | Template variables array |
| `get_email_template()` | string\|bool | Email template name |
| `get_email_template_variables()` | array | Email template vars |
| `pre_create_insert_array($type_data, $notify_users)` | array | Batch pre-load before insert |
| `create_insert_array($type_data, $pre_create_data)` | void | Build insert data |
| `get_insert_array()` | array | Return serialized insert data |
| `create_update_array($type_data)` | array | Build update data |
| `mark_read($return)` | string\|null | Mark notification read |
| `mark_unread($return)` | string\|null | Mark notification unread |

### base class (`src/phpbb/forums/notification/type/base.php`)

**Dependencies** (constructor, Lines 91–105):
- `\phpbb\db\driver\driver_interface $db`
- `\phpbb\language\language $language`
- `\phpbb\user $user`
- `\phpbb\auth\auth $auth`
- `$phpbb_root_path`, `$php_ext`
- `$user_notifications_table`

Additionally injected via setter:
- `set_notification_manager(\phpbb\notification\manager)` — also sets `$notification_type_id`

**Key Data Structure** (Lines 65–79):
```
$data = [
    'notification_type_id' => int,
    'item_id'              => int,       // e.g. post_id, msg_id
    'item_parent_id'       => int,       // e.g. topic_id, forum_id
    'user_id'              => int,
    'notification_read'    => bool,
    'notification_time'    => int,       // unix timestamp
    'notification_data'    => array,     // serialized blob, type-specific
]
```

**`notification_data`** is `serialize()`d on write, `unserialize()`d on read (Line 119). Stored as TEXT column in DB.

**`prepare_for_display()`** (Lines 283–305) returns:
```php
[
    'NOTIFICATION_ID'   => int,
    'STYLING'           => string,       // CSS class
    'AVATAR'            => string,       // HTML avatar
    'FORMATTED_TITLE'   => string,       // HTML title
    'REFERENCE'         => string,
    'FORUM'             => string,
    'REASON'            => string,
    'URL'               => string,
    'TIME'              => string,       // formatted date
    'UNREAD'            => bool,
    'U_MARK_READ'       => string,       // mark-read URL with hash
]
```

**`check_user_notification_options()`** (Lines 413–496):
Core helper that queries `phpbb_user_notifications` and merges with `get_default_methods()`.
Returns `array<user_id => array<method_name>>`.

**`get_authorised_recipients()`** (Lines 551–574):
Filters users by `f_read` ACL permission, then delegates to `check_user_notification_options()`.

### post.php — Post Reply Notifications (`src/phpbb/forums/notification/type/post.php`)

- **Extends**: `base`
- **Type**: `notification.type.post`
- **Option Group**: `NOTIFICATION_GROUP_POSTING`
- **Availability**: `$config['allow_topic_notify']`
- **item_id**: `post_id`
- **item_parent_id**: `topic_id`

**`find_users_for_notification()`** (Lines 121–168):
1. Queries `TOPICS_WATCH_TABLE` for users watching the topic (excluding poster)
2. Filters by `f_read` ACL via `get_authorised_recipients()`
3. **Responder coalescence**: Checks for existing unread notifications for same topic via `notification_manager->get_notified_users()`. Instead of creating new notifications, updates existing ones with `add_responders()`.

**`notification_data` fields**: `poster_id`, `topic_title`, `post_subject`, `post_username`, `forum_id`, `forum_name`, `responders[]`

**`add_responders()`** (Lines 400–463):
- Max 25 responders per notification
- Each responder: `{ poster_id, username }`
- Guarded: serialized data must be < 4000 chars to avoid SQL TEXT overflow
- Returns merged data array for `update_notification()`

**`pre_create_insert_array()`** (Lines 371–387):
Queries `TOPICS_TRACK_TABLE` to get `mark_time` per user. If user has already read the topic past notification time, marks as pre-read.

### topic.php — New Topic Notifications (`src/phpbb/forums/notification/type/topic.php`)

- **Type**: `notification.type.topic`
- **Availability**: `$config['allow_forum_notify']`
- **item_id**: `topic_id`
- **item_parent_id**: `forum_id`

**`find_users_for_notification()`** (Lines 113–130):
- Queries `FORUMS_WATCH_TABLE` for forum subscribers (excluding poster)
- Filters by `f_read` ACL

### pm.php — Private Message Notifications (`src/phpbb/forums/notification/type/pm.php`)

- **Type**: `notification.type.pm`
- **Availability**: `$config['allow_privmsg'] && acl_get('u_readpm')`
- **item_id**: `msg_id`
- **item_parent_id**: `0` (no parent)

**`find_users_for_notification()`** (Lines 100–115):
- Uses `$pm['recipients']` directly (already known from PM creation)
- Filters out sender, delegates to `check_user_notification_options()`

**`notification_data` fields**: `from_user_id`, `message_subject`

### quote.php — Quote Notifications (`src/phpbb/forums/notification/type/quote.php`)

- **Extends**: `post` (not `base` directly)
- **Type**: `notification.type.quote`
- **Availability**: always `true`

**`find_users_for_notification()`** (Lines 77–103):
- Parses post text with `$this->utils->get_outermost_quote_authors()`
- Resolves usernames to user_ids via `USERS_TABLE` query
- Filters by `f_read` ACL

**`update_notifications()`** (Lines 111–142):
Custom logic — diffs old vs new quoted users, adds/removes notifications accordingly.

---

## 3. Delivery Methods

### method_interface (`src/phpbb/forums/notification/method/method_interface.php`)

| Method | Signature | Purpose |
|--------|-----------|---------|
| `get_type()` | `(): string` | Method name |
| `is_enabled_by_default()` | `(): bool` | Default activation |
| `is_available()` | `(): bool` | User-level availability check |
| `get_notified_users($type_id, $options)` | `(): array` | Already-notified user lookup |
| `load_notifications($options)` | `(): array` | Load user notifications with counts |
| `add_to_queue($notification)` | `(): void` | Add to send queue |
| `notify()` | `(): void` | Flush queue, persist/send |
| `update_notification($notification, $data, $options)` | `(): void` | Update existing notifications |
| `mark_notifications($type_id, $item_id, $user_id, $time, $mark_read)` | `(): void` | Mark by type+item |
| `mark_notifications_by_parent($type_id, $parent_id, $user_id, $time, $mark_read)` | `(): void` | Mark by parent |
| `mark_notifications_by_id($notification_id, $time, $mark_read)` | `(): void` | Mark by notification ID |
| `delete_notifications($type_id, $item_id, $parent_id, $user_id)` | `(): void` | Delete |
| `prune_notifications($timestamp, $only_read)` | `(): void` | Cleanup old |
| `purge_notifications($type_id)` | `(): void` | Purge all of type |

### method/base.php — Abstract Base

- Holds `$queue` array and `$notification_manager` reference
- All write methods are no-ops by default (only `board` overrides them)
- `load_notifications()` returns empty, `is_enabled_by_default()` returns `false`

### board.php — In-App Notifications (`src/phpbb/forums/notification/method/board.php`)

**Dependencies** (constructor, Lines 52–68):
- `\phpbb\user_loader $user_loader`
- `\phpbb\db\driver\driver_interface $db`
- `\phpbb\cache\driver\driver_interface $cache`
- `\phpbb\user $user`
- `\phpbb\config\config $config`
- `$notification_types_table`, `$notifications_table`

**`is_available()`**: `$config['allow_board_notifications']`  
**`is_enabled_by_default()`**: `true`

#### load_notifications() (Lines 137–258)

**Default options**:
```php
[
    'notification_id' => false,
    'user_id'         => $user->data['user_id'],
    'order_by'        => 'notification_time',
    'order_dir'       => 'DESC',
    'limit'           => 0,     // 0 = no limit
    'start'           => 0,
    'all_unread'      => false,
    'count_unread'    => false,
    'count_total'     => false,
]
```

**Execution flow** (3 separate SQL queries):

1. **Count unread** (if `count_unread` enabled):
   ```sql
   SELECT COUNT(n.notification_id) AS unread_count
   FROM phpbb_notifications n, phpbb_notification_types nt
   WHERE n.user_id = ? AND n.notification_read = 0
     AND nt.notification_type_id = n.notification_type_id
     AND nt.notification_type_enabled = 1
   ```

2. **Count total** (if `count_total` enabled):
   ```sql
   SELECT COUNT(n.notification_id) AS total_count
   FROM phpbb_notifications n, phpbb_notification_types nt
   WHERE n.user_id = ? 
     AND nt.notification_type_id = n.notification_type_id
     AND nt.notification_type_enabled = 1
   ```

3. **Main fetch** (paginated):
   ```sql
   SELECT n.*, nt.notification_type_name
   FROM phpbb_notifications n, phpbb_notification_types nt
   WHERE n.user_id = ?
     [AND n.notification_id = ? | AND n.notification_id IN (?)]
     AND nt.notification_type_id = n.notification_type_id
     AND nt.notification_type_enabled = 1
   ORDER BY n.{order_by} {order_dir}
   LIMIT {limit} OFFSET {start}
   ```

4. **Additional unread fetch** (if `all_unread` and there are already fetched results):
   Fetches remaining unread notifications not already in the result set.

5. **Post-processing**: For each row:
   - Instantiate type class via `notification_manager->get_item_type_class()`
   - Collect `users_to_query()` and `get_load_special()` per type
   - Bulk `user_loader->load_users()` 
   - Call `load_special()` per type

#### notify() (Lines 261–275)

Uses `\phpbb\db\sql_insert_buffer` for batch INSERT into `phpbb_notifications`.

#### mark_notifications() (Lines 282–296)

```sql
UPDATE phpbb_notifications SET notification_read = {0|1}
WHERE notification_time <= {time}
  [AND notification_type_id = ? | AND notification_type_id IN (?)]
  [AND user_id = ? | AND user_id IN (?)]
  [AND item_id = ? | AND item_id IN (?)]
```

#### mark_notifications_by_id() (Lines 320–329)

```sql
UPDATE phpbb_notifications SET notification_read = {0|1}
WHERE notification_time <= {time}
  AND notification_id = ? | AND notification_id IN (?)
```

#### delete_notifications() (Lines 335–344)

```sql
DELETE FROM phpbb_notifications
WHERE notification_type_id = ?
  AND item_id = ? | AND item_id IN (?)
  [AND item_parent_id = ?]
  [AND user_id = ?]
```

#### prune_notifications() (Lines 350–357)

```sql
DELETE FROM phpbb_notifications
WHERE notification_time < ?
  [AND notification_read = 1]
```
Also updates `config['read_notification_last_gc']`.

### email.php — Email Notifications (`src/phpbb/forums/notification/method/email.php`)

**Dependencies**: `user_loader`, `user`, `config`, `db`, `phpbb_root_path`, `php_ext`, `$notification_emails_table`

**`is_available()`**: `config['email_enable'] && !empty(user.data['user_email'])`

**`notify()`** flow:
1. Insert rows into `phpbb_notification_emails` table (deduplication tracker)
2. Call `notify_using_messenger(NOTIFY_EMAIL)` (from `messenger_base`)

**`messenger_base::notify_using_messenger()`** (Lines 74–136):
1. Collect all user_ids from queue
2. Filter out banned users (`phpbb_get_banned_user_ids()`)
3. Load remaining users via `user_loader`
4. For each notification: instantiate `\messenger`, set template + addresses, assign vars, send
5. `$messenger->save_queue()` — phpBB queues emails for batch sending

**Deduplication**: `phpbb_notification_emails` tracks (type_id, item_id, parent_id, user_id). `mark_notifications()` and `mark_notifications_by_parent()` DELETE from this table.

### jabber.php — Jabber/XMPP Notifications

Same pattern as email via `messenger_base`, uses `NOTIFY_IM`.  
**`is_available()`**: requires `jab_enable`, `jab_host`, `jab_username` config + user has `user_jabber`.

---

## 4. Database Schema

### phpbb_notifications (main storage)

Source: `phpbb_dump.sql:2534-2549`

```sql
CREATE TABLE phpbb_notifications (
  notification_id      INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  notification_type_id SMALLINT(4) UNSIGNED NOT NULL DEFAULT 0,
  item_id              MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT 0,
  item_parent_id       MEDIUMINT(8) UNSIGNED NOT NULL DEFAULT 0,
  user_id              INT(10) UNSIGNED NOT NULL DEFAULT 0,
  notification_read    TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  notification_time    INT(11) UNSIGNED NOT NULL DEFAULT 1,
  notification_data    TEXT NOT NULL,
  PRIMARY KEY (notification_id),
  KEY item_ident (notification_type_id, item_id),
  KEY user (user_id, notification_read)
);
```

**Indexes**:
- `PRIMARY KEY (notification_id)` — auto-increment PK
- `item_ident (notification_type_id, item_id)` — used by `get_notified_users()`, `delete_notifications()`
- `user (user_id, notification_read)` — **critical for count_unread and load queries**

### phpbb_notification_types (type registry)

Source: `phpbb_dump.sql:2509-2517`

```sql
CREATE TABLE phpbb_notification_types (
  notification_type_id      SMALLINT(4) UNSIGNED NOT NULL AUTO_INCREMENT,
  notification_type_name    VARCHAR(255) NOT NULL DEFAULT '',
  notification_type_enabled TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (notification_type_id),
  UNIQUE KEY type (notification_type_name)
);
```

Cached for 1 week via `$db->sql_query($sql, 604800)` in `get_notification_type_id()`.

### phpbb_user_notifications (subscription preferences)

Source: `phpbb_dump.sql:3815-3827`

```sql
CREATE TABLE phpbb_user_notifications (
  item_type VARCHAR(165) NOT NULL DEFAULT '',
  item_id   INT(10) UNSIGNED NOT NULL DEFAULT 0,
  user_id   INT(10) UNSIGNED NOT NULL DEFAULT 0,
  method    VARCHAR(165) NOT NULL DEFAULT '',
  notify    TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
  UNIQUE KEY itm_usr_mthd (item_type, item_id, user_id, method),
  KEY user_id (user_id),
  KEY uid_itm_id (user_id, item_id),
  KEY usr_itm_tpe (user_id, item_type, item_id)
);
```

### phpbb_notification_emails (email dedup tracker)

Source: `phpbb_dump.sql:2484-2491`, migration `v33x/add_notification_emails_table.php`

```sql
CREATE TABLE phpbb_notification_emails (
  notification_type_id SMALLINT(4) UNSIGNED NOT NULL DEFAULT 0,
  item_id              INT(10) UNSIGNED NOT NULL DEFAULT 0,
  item_parent_id       INT(10) UNSIGNED NOT NULL DEFAULT 0,
  user_id              INT(10) UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (notification_type_id, item_id, item_parent_id, user_id)
);
```

---

## 5. Existing Notification Count/Badge Logic

### Header Badge (functions.php, Lines 4132–4148)

```php
// Output the notifications
$notifications = false;
if ($config['load_notifications'] && $config['allow_board_notifications'] 
    && $user->data['user_id'] != ANONYMOUS 
    && $user->data['user_type'] != USER_IGNORE)
{
    $phpbb_notifications = $phpbb_container->get('notification_manager');
    $notifications = $phpbb_notifications->load_notifications('notification.method.board', [
        'all_unread'  => true,
        'limit'       => 5,
    ]);
    foreach ($notifications['notifications'] as $notification) {
        $template->assign_block_vars('notifications', $notification->prepare_for_display());
    }
}
```

Template variables set (Lines 4194–4200):
```php
'UNREAD_NOTIFICATIONS_COUNT' => $notifications['unread_count'],
'NOTIFICATIONS_COUNT'        => $notifications['unread_count'],
'U_VIEW_ALL_NOTIFICATIONS'   => 'ucp.php?i=ucp_notifications',
'U_MARK_ALL_NOTIFICATIONS'   => 'ucp.php?i=ucp_notifications&mode=notification_list&mark=all&token=...',
'U_NOTIFICATION_SETTINGS'    => 'ucp.php?i=ucp_notifications&mode=notification_options',
'S_NOTIFICATIONS_DISPLAY'    => $config['load_notifications'] && $config['allow_board_notifications'],
```

**Key observation**: The header loads **both** the unread count AND the 5 most recent notifications in a single `load_notifications()` call. This triggers up to 3 SQL queries (count unread, fetch 5, fetch remaining unread not in first 5).

### Mark Notification Read on Index (web/index.php, Lines 42–80)

- `GET /index.php?mark_notification=ID&hash=HASH` → loads notification, calls `mark_read()`, AJAX response or redirect
- Uses `check_link_hash()` for CSRF protection

### UCP Notifications Page (ucp/ucp_notifications.php, Lines 118–185)

- Full paginated list with `count_total: true`
- Pagination via `$config['topics_per_page']`
- Mark all: `mark_notifications(false, false, $user_id, $time)` across all types
- Mark selected: `mark_notifications_by_id('notification.method.board', $mark_read, $time)`

### No Existing REST API Endpoint

Searched `src/phpbb/api/` — **no notification-related API controllers exist**. All notification display/interaction goes through:
1. Legacy `page_header()` in `functions.php` (header dropdown)
2. `web/index.php` (mark-read handler)
3. `ucp_notifications.php` (full UCP page)

---

## 6. Performance Observations

### Index Adequacy for Common Queries

| Query Pattern | Used Index | Assessment |
|---|---|---|
| Count unread: `WHERE user_id=? AND notification_read=0` | `user (user_id, notification_read)` | **Good** — covering index for the WHERE clause |
| Fetch recent: `WHERE user_id=? ORDER BY notification_time DESC LIMIT 5` | `user (user_id, notification_read)` | **Suboptimal** — index doesn't cover `notification_time` for ordering; requires filesort |
| Delete by type+item: `WHERE notification_type_id=? AND item_id=?` | `item_ident (notification_type_id, item_id)` | **Good** |
| Mark read by time+type+item: complex WHERE | Partial index use | **Acceptable** — UPDATE queries are less frequent |

### Missing Index

**`(user_id, notification_time DESC)`** — would optimize the most common query (fetch recent for user) by avoiding filesort. Currently the `user` index only covers `(user_id, notification_read)`.

### N+1 Risk

The JOINs with `phpbb_notification_types` use implicit cross-join syntax (`FROM n, nt WHERE nt.id = n.id`). This is equivalent to an INNER JOIN but less readable. The `notification_types` table is tiny (< 25 rows) so this is not a real performance concern.

### Serialization of notification_data

`notification_data` is PHP `serialize()`d. This means:
- Cannot query/filter by notification_data contents in SQL
- Size limited to TEXT column (~65KB), but code guards at 4000 chars for responders
- Deserialization on every notification load

---

## 7. Extension Points for New Service

### Key Leverage Points

1. **`notification_manager` service** — well-defined public API can be injected into new controller
2. **`load_notifications()` options** — already supports `limit`, `start`, `count_unread`, `count_total`, `all_unread`
3. **`prepare_for_display()`** — returns a clean template variable array that can be easily converted to JSON
4. **`mark_notifications_by_id()`** — ready for REST `PATCH` endpoint
5. **Events** — Allow intercepting notification flow without modifying core
6. **DI service collections** — New types/methods can be registered via tagged services

### Gaps the New Service Must Address

1. **No REST API** — Must create controller(s) for GET (list + count) and PATCH (mark read)
2. **Count-only endpoint** — Current `load_notifications()` always does a full fetch when counting; a lightweight count-only query is needed for polling
3. **No push mechanism** — Current system is purely pull (header reload); need SSE/WebSocket or polling endpoint
4. **`notification_data` serialization** — For API output, need to deserialize and expose relevant fields (title, URL, avatar, time, unread status)
5. **Missing `notification_time` index** — For efficient "recent notifications" queries used by the API

### DI Configuration Reference

All notification services defined in:  
`src/phpbb/common/config/default/container/services_notification.yml`

Type inheritance pattern:
- `notification.type.base` → abstract parent with core deps
- `notification.type.post` → extends base, adds `user_loader`, `config`
- `notification.type.quote` → extends post, adds `text_formatter.utils`
- `notification.type.approve_post` → extends post (no additional deps)

All type services are `shared: false` (new instance per use).
