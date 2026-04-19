# External Research: phpBB Notification Patterns & Ecosystem

## Sources
- Official phpBB Notification Tutorial: https://area51.phpbb.com/docs/dev/master/extensions/tutorial_notifications.html
- Official phpBB Events & Listeners Tutorial: https://area51.phpbb.com/docs/dev/master/extensions/tutorial_events.html
- Official phpBB Controllers & Routes Tutorial: https://area51.phpbb.com/docs/dev/master/extensions/tutorial_controllers.html
- phpBB GitHub Repository (main branch): https://github.com/phpbb/phpbb
- Local codebase analysis: `src/phpbb/`, `web/assets/javascript/core.js`

---

## 1. phpBB Notification Extension Points

### Architecture Overview (Official Docs)

phpBB's notification system (introduced 3.1, refactored in 3.2) has three core primitives:

| Concept | Description | Registration |
|---------|-------------|-------------|
| **Notification Type** | Defines what the notification is (e.g., new post, PM) | Symfony service tagged `notification.type` |
| **Notification Method** | Defines how it's delivered (board, email, webpush) | Symfony service tagged `notification.method` |
| **Notification Manager** | Orchestrates types & methods | `notification_manager` service |

### Creating Custom Notification Types

Per the official tutorial, a custom notification type requires 7 files:

```
vendor/extension/
├── config/services.yml          # Service registration
├── event/listener.php           # Event listener to trigger notifications
├── language/en/
│   ├── email/
│   │   ├── short/sample.txt     # Short email template (Jabber, deprecated in 4.0)
│   │   └── sample.txt           # Full email template
│   └── extension_common.php     # Language strings
├── notification/type/sample.php # Notification type class
└── ext.php                      # Enable/disable/purge hooks
```

### Service Registration Pattern

```yaml
services:
    vendor.extension.notification.type.sample:
        class: vendor\extension\notification\type\sample
        parent: notification.type.base    # Inherits from core base
        shared: false                     # New instance per notification
        tags: [{ name: notification.type }]  # Auto-discovery tag
        calls:
            - ['set_helper', ['@controller.helper']]
            - ['set_user_loader', ['@user_loader']]
```

**Key constraints:**
- `parent` parameter = inherits from `notification.type.base` (or another type like `notification.type.post`)
- `shared: false` = mandatory to prevent data leakage between instances
- `tags: notification.type` = how phpBB discovers notification types
- Cannot use `arguments` alongside `parent` — must use `calls` for additional DI

### Required Class Functions

Every notification type extending `\phpbb\notification\type\base` must implement:

| Function | Purpose |
|----------|---------|
| `get_type()` | Returns service identifier string |
| `notification_option` | Static array with `lang` and `group` keys for UCP display |
| `is_available()` | Whether to show in UCP preferences |
| `get_item_id($data)` | Returns item identifier (e.g., post_id) |
| `get_item_parent_id($data)` | Returns parent identifier (e.g., topic_id) |
| `find_users_for_notification($data, $options)` | Determines recipients |
| `users_to_query()` | Returns user IDs whose data needs pre-loading |
| `get_title()` | Notification display text |
| `get_url()` | URL when notification is clicked |
| `get_email_template()` | Email template path or `false` |
| `get_email_template_variables()` | Variables for email template |
| `create_insert_array($data, $pre_create_data)` | Stores notification-specific data |

### Optional Functions

| Function | Purpose |
|----------|---------|
| `get_avatar()` | User avatar HTML |
| `get_forum()` | Forum name display |
| `get_reason()` | Reason text |
| `get_reference()` | Reference text (e.g., subject) |
| `get_style_class()` | Custom CSS class |
| `get_redirect_url()` | URL after marking as read (if different from `get_url()`) |

### Base Services Available in Type Classes

From `\phpbb\notification\type\base`:
- `$this->auth` — `\phpbb\auth\auth`
- `$this->db` — `\phpbb\db\driver\driver_interface`
- `$this->language` — `\phpbb\language\language`
- `$this->user` — `\phpbb\user`
- `$this->phpbb_root_path` — string
- `$this->php_ext` — string
- `$this->user_notifications_table` — string

### Extension Lifecycle Hooks (ext.php)

**Critical requirement**: Extensions with notification types MUST manage their lifecycle:

```php
class ext extends \phpbb\extension\base
{
    public function enable_step($old_state)
    {
        if ($old_state === false) {
            $notification_manager = $this->container->get('notification_manager');
            $notification_manager->enable_notifications('vendor.extension.notification.type.sample');
            return 'notification';
        }
        return parent::enable_step($old_state);
    }

    public function disable_step($old_state)  { /* ... disable_notifications() ... */ }
    public function purge_step($old_state)     { /* ... purge_notifications() ... */ }
}
```

**If not done**: Throws uncaught exceptions, board becomes inaccessible.

### PHP Events for Notification Customization

| Event | Location | Purpose |
|-------|----------|---------|
| `core.notification_manager_add_notifications_before` | `manager.php:264` | Modify data before notifications are added |
| `core.notification_manager_add_notifications` | `manager.php:306` | Hook after notification types are resolved |
| `core.notification_manager_add_notifications_for_users_modify_data` | `manager.php:374` | Modify per-user notification data |

### Notification Manager API

```php
$notification_manager->add_notifications($type_name, $data);
$notification_manager->update_notifications($type_name, $data, $options);
$notification_manager->delete_notifications($type_name, $item_ids, $parent_id, $user_id);
$notification_manager->mark_notifications($type_name, $item_id, $user_id, $time, $mark_read);
$notification_manager->mark_notifications_by_parent($type_name, $parent_id, ...);
$notification_manager->mark_notifications_by_id($method_name, $notification_id, ...);
$notification_manager->load_notifications($method_name, $options);
$notification_manager->enable_notifications($type_name);
$notification_manager->disable_notifications($type_name);
$notification_manager->purge_notifications($type_name);
```

---

## 2. phpBB AJAX Notification Patterns

### Notification Badge in Page Header

**Source**: `src/phpbb/common/functions.php:4132-4148`

Notifications are loaded on every page render in `page_header()`:

```php
if ($config['load_notifications'] && $config['allow_board_notifications']
    && $user->data['user_id'] != ANONYMOUS && $user->data['user_type'] != USER_IGNORE)
{
    $phpbb_notifications = $phpbb_container->get('notification_manager');
    $notifications = $phpbb_notifications->load_notifications('notification.method.board', [
        'all_unread' => true,
        'limit'      => 5,
    ]);
    foreach ($notifications['notifications'] as $notification) {
        $template->assign_block_vars('notifications', $notification->prepare_for_display());
    }
}
```

**Key insight**: Notifications are loaded server-side on every page load (no AJAX polling). The notification count badge is rendered in template and updated only on page reload or explicit AJAX mark-read actions.

### AJAX Callbacks for Notification Interaction

**Source**: `phpBB/styles/prosilver/template/ajax.js:101-115` (GitHub)

phpBB uses jQuery-based AJAX callbacks registered with `phpbb.addAjaxCallback()`:

```js
// Mark all notifications read
phpbb.addAjaxCallback('notification.mark_all_read', function(res) {
    if (typeof res.success !== 'undefined') {
        phpbb.markNotifications($('[data-notification-unread="true"]'), 0);
        phpbb.toggleDropdown.call($('#notification-button'));
        phpbb.closeDarkenWrapper(3000);
    }
});

// Mark single notification read
phpbb.addAjaxCallback('notification.mark_read', function(res) {
    if (typeof res.success !== 'undefined') {
        var unreadCount = Number($('#notification-button strong').html()) - 1;
        phpbb.markNotifications($(this).parent('[data-notification-unread="true"]'), unreadCount);
    }
});
```

### `phpbb.markNotifications()` Utility

**Source**: `phpBB/styles/prosilver/template/ajax.js:124-141` (GitHub)

```js
phpbb.markNotifications = function($popup, unreadCount) {
    $popup.removeClass('bg2');
    $popup.find('a.mark_read').remove();
    $popup.each(function() {
        var link = $(this).find('a');
        link.attr('href', link.attr('data-real-url'));
    });
    $('strong', '#notification-button').html(unreadCount);
    if (!unreadCount) {
        $('#mark_all_notifications').remove();
        $('#notification-button > strong').addClass('hidden');
    }
    // Update page title
    var $title = $('title');
    var originalTitle = $title.text().replace(/(\((\d+)\))/, '');
    $title.text((unreadCount ? '(' + unreadCount + ')' : '') + originalTitle);
};
```

**Key insight**: No polling mechanism exists in stock phpBB. The notification count only updates via:
1. Full page reload (server-side rendering)
2. User clicks "mark read" (AJAX callback updates DOM)

### Notification Dropdown Template

**Source**: `src/phpbb/styles/prosilver/template/notification_dropdown.html`

The dropdown uses simple `data-ajax` attributes to trigger AJAX callbacks:
- `data-ajax="notification.mark_all_read"` on "Mark all read" link
- `data-ajax="notification.mark_read"` on individual mark-read icons
- `data-real-url` attribute stores the actual notification target URL (separate from mark-read URL)

Template events available for extension:
- `{% EVENT notification_dropdown_footer_before %}`
- `{% EVENT notification_dropdown_footer_after %}`

---

## 3. Web Push Notifications (phpBB 4.0 / 3.3.x)

### Architecture

**Source**: phpBB GitHub `phpBB/phpbb/ucp/controller/webpush.php`, `phpBB/assets/javascript/webpush.js`, `phpBB/styles/all/js/push_worker.js.twig`

phpBB added Web Push as a native notification method (method `notification.method.webpush`):

| Component | File | Role |
|-----------|------|------|
| Controller | `phpbb/ucp/controller/webpush.php` | Handles subscribe/unsubscribe/notification fetch via AJAX |
| JS Client | `assets/javascript/webpush.js` | Manages push subscription via Service Worker |
| Service Worker | `styles/all/js/push_worker.js.twig` | Receives push events, fetches notification data, shows native notification |
| UCP Integration | `styles/prosilver/template/ucp_notifications_webpush.html` | Settings UI |
| DB Tables | `notification_webpush`, `push_subscriptions` | Push data + subscriptions |

### Service Worker Flow

```
1. Push event received → parse JSON (item_id, type_id, user_id, token)
2. Fetch notification data via POST to /push/notification endpoint
3. Response: { heading, title, text, avatar: { src }, url }
4. Show native notification with icon and link
5. notificationclick → open new browser tab to notification URL
```

### WebPush Controller (`webpush.php`)

```php
public function notification(): JsonResponse
{
    if (!$this->request->is_ajax() || $this->user->data['is_bot']
        || $this->user->data['user_type'] == USER_INACTIVE)
    {
        throw new http_exception(Response::HTTP_FORBIDDEN, 'NO_AUTH_OPERATION');
    }
    // Fetch notification data from notification_webpush table
    // Return as JSON with heading, title, text, avatar, url
}
```

### Key Pattern: Session Handling for Push

The `is_push_notification_request()` method in `session.php` prevents `user_last_active` from being updated on push notification data fetches — important for "who's online" accuracy.

---

## 4. phpBB Frontend Patterns

### JavaScript Framework

phpBB uses **jQuery** (3.6.0 as of 3.3.x):
- Loaded from Google CDN by default: `//ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js`
- Configurable via `load_jquery_url` config setting
- No modern framework (React, Vue, etc.) — all vanilla jQuery

### Core JavaScript Architecture

**Source**: `web/assets/javascript/core.js`

The `phpbb` global object provides:
- `phpbb.ajaxCallbacks` — Registry of AJAX response handlers
- `phpbb.addAjaxCallback(name, fn)` — Register new AJAX callbacks
- `phpbb.loadingIndicator()` — Loading spinner management
- `phpbb.alert()` / `phpbb.confirm()` — Modal dialogs
- `phpbb.toggleDropdown` — Dropdown toggle/positioning
- `phpbb.registerDropdown()` — Register dropdown menus

### AJAX Pattern

phpBB's AJAX is declarative via `data-ajax` HTML attributes:

```html
<a href="{URL}" data-ajax="callback_name">Click</a>
```

When clicked, `core.js` intercepts, makes AJAX POST to the `href`, and passes response to the named callback.

For manual AJAX:
```js
$.ajax({
    url: url,
    type: 'POST',
    data: data,
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
    success: function(res) { ... },
    error: errorHandler
});
```

Server checks `$request->is_ajax()` to detect AJAX requests.

### Template System

- phpBB uses **Twig** (version 2 in 3.3.x) with custom extensions
- Legacy `<!-- IF -->`, `<!-- BEGIN -->` syntax still used alongside Twig `{% %}` blocks
- Template files: `styles/prosilver/template/*.html`
- Template assignment: `$template->assign_vars()`, `$template->assign_block_vars()`
- Extension template events: `{% EVENT event_name %}` — inject HTML at hook points

### prosilver Notification Template Structure

**Source**: `notification_dropdown.html`

```
#notification_list (dropdown container)
├── .header (title + settings/mark-all-read links)
├── ul (notification list)
│   └── li (per notification)
│       ├── a.notification-block (clickable wrapper)
│       │   ├── avatar image
│       │   └── .notification_text
│       │       ├── .notification-title
│       │       ├── .notification-reference
│       │       ├── .notification-forum
│       │       ├── .notification-reason
│       │       └── .notification-time
│       └── a.mark_read (mark-read icon, with data-ajax)
├── {% EVENT notification_dropdown_footer_before %}
├── .footer (See All link)
│   └── .webpush-subscribe (if webpush enabled)
└── {% EVENT notification_dropdown_footer_after %}
```

---

## 5. phpBB REST API Approaches

### Official API Structure (in this codebase)

**Source**: Codebase analysis from session notes

This phpBB fork already has a REST API:
- Entry point: `web/api.php` → `api.application->run()`
- Application wrapper: `phpbb\core\Application` (wraps Symfony HttpKernel)
- API namespace: `phpbb\api\v1\controller\*`
- Controllers return `Symfony\Component\HttpFoundation\JsonResponse`
- Routes: `api.yml` with `/api/v1/` prefix
- DI config: `services_api.yml`

### Extension Route Pattern (Standard phpBB)

Extensions add routes via `config/routing.yml`:

```yaml
acme_demo_route:
    path: /demo/{name}
    defaults: { _controller: acme.demo.controller:handle, name: "world" }
```

All extension routes go through `app.php` (can be hidden with URL rewriting).

### Controller Pattern

```php
class main
{
    public function __construct(
        \phpbb\config\config $config,
        \phpbb\controller\helper $helper,
        \phpbb\language\language $language,
        \phpbb\template\template $template
    ) { /* ... */ }

    public function handle($name)
    {
        // Never use trigger_error() — always return Response/JsonResponse
        return $this->helper->render('@acme_demo/demo_body.html', $name);
    }
}
```

### JSON Response from Controllers

For AJAX/API endpoints, controllers should return:
```php
return new \Symfony\Component\HttpFoundation\JsonResponse($data, $statusCode);
```

The WebPush controller demonstrates this pattern for notification data endpoints.

---

## 6. Performance Considerations

### Current Notification Loading Performance

**Source**: `functions.php:4137-4148`

- Notifications loaded on **every page request** for authenticated users
- Limited to 5 most recent unread (`'all_unread' => true, 'limit' => 5`)
- Uses `notification.method.board`'s `load_notifications()` which:
  - Queries `phpbb_notifications` table with JOINs
  - Instantiates type objects for each notification
  - Calls `prepare_for_display()` on each

### Board Method Storage

**Source**: `phpbb/notification/method/board.php` (GitHub)

- `load_notifications()` queries with `ORDER BY notification_time DESC`
- Uses `sql_insert_buffer` for batch inserts (efficient for bulk notifications)
- Notification data is serialized PHP (`serialize()`) in `notification_data` column

### Scaling Concerns

1. **No pagination in dropdown**: Only last 5 unread shown in dropdown; full list in UCP has pagination
2. **Per-page-load DB queries**: `load_notifications()` runs a DB query + instantiates objects on every page
3. **No caching of notification data**: Notifications are always fresh from DB (no cache layer)
4. **`find_users_for_notification()` can be heavy**: For topic/forum subscriptions, may query many users
5. **`notification_data` column uses PHP `serialize()`**: Not indexable, requires deserialization
6. **No deduplication logic in base**: Item_id + parent_id combination prevents duplicate notifications (built into `add_notifications()`)

### WebPush Performance Pattern

The WebPush implementation shows a modern approach:
- Service Worker handles push events asynchronously
- Separate endpoint (`/push/notification`) doesn't update `user_last_active`
- Data fetched lazily (only when push event fires)
- Uses lightweight JSON responses

---

## 7. Design Constraints for Our Notification API Service

### Must Follow

1. **Symfony DI**: All services registered in YAML, tagged appropriately
2. **Notification type pattern**: Extend `\phpbb\notification\type\base`, implement required methods
3. **Notification method pattern**: Extend `\phpbb\notification\method\base`, implement method interface
4. **Event system**: Use `core.notification_manager_*` events for hooks
5. **Template events**: Use `{% EVENT %}` hooks in templates (e.g., `notification_dropdown_footer_before/after`)
6. **Language system**: All user-visible strings through `$this->language->lang()`
7. **jQuery for frontend**: No modern JS framework available; use `phpbb.addAjaxCallback()` pattern
8. **`data-ajax` pattern**: Standard way to bind AJAX behavior to links/forms
9. **CSRF protection**: `generate_link_hash()` / `check_link_hash()` for AJAX links; form tokens for POST
10. **Controller responses**: Return `JsonResponse` for API endpoints, never `trigger_error()`

### Should Consider

1. **Backward compatibility**: The `prepare_for_display()` return format is expected by templates
2. **UCP integration**: Notification preferences go in UCP via `notification_option` static variable
3. **Mark-read routes**: phpBB has `phpbb_notifications_mark_read` route with hash verification
4. **Extension template events**: Limited but usable (footer of notification dropdown)
5. **No native AJAX polling**: If we want real-time, we need to add our own polling/SSE/WebSocket mechanism
6. **WebPush as model**: The webpush implementation shows how phpBB adds new delivery methods; our API service should follow similar patterns

### Anti-Patterns to Avoid

1. **Don't bypass notification manager**: Always go through `notification_manager` for sending/managing
2. **Don't render templates from API**: API endpoints should return JSON only
3. **Don't ignore `shared: false`**: Notification type services must create new instances
4. **Don't skip ext.php lifecycle**: Enable/disable/purge hooks are mandatory
5. **Don't store sensitive data in `notification_data`**: It's a serialized blob visible to anyone who can read the notification

---

## 8. Summary of Key Patterns for Reuse

| Pattern | What to Reuse | Where |
|---------|--------------|-------|
| Service tagging | `notification.type` and `notification.method` tags | DI config |
| Base class inheritance | `\phpbb\notification\type\base` | Type classes |
| Manager orchestration | `notification_manager->add_notifications()` | Sending |
| AJAX callbacks | `phpbb.addAjaxCallback()` + `data-ajax` attribute | Frontend |
| Mark-read DOM update | `phpbb.markNotifications()` | Frontend |
| Template events | `notification_dropdown_footer_before/after` | Template hooks |
| Controller + JSON response | `JsonResponse` pattern from webpush controller | API endpoints |
| Session awareness | `is_push_notification_request()` pattern | Performance |
| Batch insert | `sql_insert_buffer` for bulk notifications | Storage |
| Custom item ID | Config counter + `config->increment()` | When no natural ID exists |
