# Research Sources

## Codebase Sources

### Notification System (Category: codebase-notifications)

#### Core Files
- `src/phpbb/forums/notification/manager.php` — Main orchestrator (~500 lines)
- `src/phpbb/forums/notification/exception.php` — Notification exceptions

#### Notification Types
- `src/phpbb/forums/notification/type/type_interface.php` — Type contract
- `src/phpbb/forums/notification/type/base.php` — Base type implementation
- `src/phpbb/forums/notification/type/post.php` — Post notification (representative)
- `src/phpbb/forums/notification/type/topic.php` — Topic notification
- `src/phpbb/forums/notification/type/quote.php` — Quote notification
- `src/phpbb/forums/notification/type/bookmark.php` — Bookmark notification
- `src/phpbb/forums/notification/type/post_in_queue.php` — Queued post
- `src/phpbb/forums/notification/type/group_request.php` — Group request (aggregation example)

#### Delivery Methods
- `src/phpbb/forums/notification/method/method_interface.php` — Method contract
- `src/phpbb/forums/notification/method/base.php` — Base method
- `src/phpbb/forums/notification/method/board.php` — In-app board notifications
- `src/phpbb/forums/notification/method/email.php` — Email delivery
- `src/phpbb/forums/notification/method/messenger_base.php` — Email messenger base
- `src/phpbb/forums/notification/method/jabber.php` — Jabber delivery (reference only)

#### Email Queue & Cron
- `src/phpbb/forums/cron/task/core/queue.php` — Mail queue flush cron task

#### DI Configuration
- `src/phpbb/common/config/default/container/services_notification.yml` — Notification services

#### Database Schema
- `phpbb_dump.sql` — Full schema (tables: phpbb_notifications, phpbb_user_notifications, phpbb_notification_emails, phpbb_notification_types)

---

### REST API Infrastructure (Category: codebase-api)

#### Entry Point & Application
- `web/api.php` — API entry point
- `src/phpbb/api/event/auth_subscriber.php` — Authentication enforcement
- `src/phpbb/api/event/json_exception_subscriber.php` — JSON error handling

#### Controllers (patterns to follow)
- `src/phpbb/api/v1/controller/health.php` — Simplest controller (baseline)
- `src/phpbb/api/v1/controller/forums.php` — List/detail pattern
- `src/phpbb/api/v1/controller/topics.php` — CRUD with auth
- `src/phpbb/api/v1/controller/users.php` — User-specific endpoint
- `src/phpbb/api/v1/controller/auth.php` — Auth controller

#### Configuration
- `src/phpbb/common/config/default/container/services_api.yml` — API service definitions
- `src/phpbb/common/config/default/routing/routing.yml` — Route definitions

---

### Integration Points (Category: codebase-integration)

#### Cache Service
- `src/phpbb/forums/cache/service.php` — Legacy cache service
- `src/phpbb/forums/cache/driver/driver_interface.php` — Cache driver contract
- `src/phpbb/forums/cache/driver/redis.php` — Redis driver (production)
- `.maister/tasks/research/2026-04-19-cache-service/outputs/high-level-design.md` — New cache service design
- `.maister/tasks/research/2026-04-19-cache-service/outputs/research-report.md` — Cache research findings

#### Auth Service
- `src/phpbb/forums/auth/auth.php` — Legacy auth system
- `src/phpbb/api/event/auth_subscriber.php` — API auth enforcement
- `.maister/tasks/research/2026-04-18-hierarchy-service/outputs/high-level-design.md` — Related auth patterns

#### Event System
- `src/phpbb/common/config/default/container/services_event.yml` — Event service config
- `docs/events.md` — Event documentation

#### DI Container
- `src/phpbb/common/config/default/container/services.yml` — Main service container
- `src/phpbb/common/config/production/container/services.yml` — Production overrides

#### Routing
- `src/phpbb/common/config/default/container/services_routing.yml` — Routing services

---

## Documentation Sources

### Project Documentation
- `docs/events.md` — phpBB event system documentation
- `docs/coding-guidelines.html` — Coding standards
- `docs/auth_api.html` — Auth API documentation
- `.maister/docs/standards/backend/STANDARDS.md` — Backend standards
- `.maister/docs/standards/global/STANDARDS.md` — Global conventions

### Related Research Outputs
- `.maister/tasks/research/2026-04-19-cache-service/outputs/solution-exploration.md` — Cache solution exploration
- `.maister/tasks/research/2026-04-19-cache-service/outputs/high-level-design.md` — Cache high-level design
- `.maister/tasks/research/2026-04-19-cache-service/outputs/decision-log.md` — Cache decisions

### Language Files (email templates)
- `src/phpbb/language/en/email/topic_in_queue.txt` — Email template example
- `src/phpbb/language/en/email/short/post_in_queue.txt` — Short email template

---

## Configuration Sources

- `config.php` — Main application config
- `docker-compose.yml` — Infrastructure setup (Redis, DB)
- `composer.json` — Dependencies (Symfony components, etc.)
- `src/phpbb/common/config/default/container/services_notification.yml` — Notification DI config
- `src/phpbb/common/config/default/container/services_api.yml` — API DI config
- `src/phpbb/common/config/default/container/services_event.yml` — Event DI config

---

## External Sources

### Facebook-style Notifications (Category: external-patterns)
- Facebook notification API design patterns
- Notification grouping/aggregation algorithms
- Badge count optimization strategies
- REST API design for notifications (GitHub API, Slack API as references)

### Real-time Delivery (Category: external-patterns)
- Polling vs SSE vs WebSocket for PHP applications
- Long-polling implementation patterns
- PHP SSE implementation (ReactPHP, Swoole considerations)
- Mercure protocol (Symfony-native SSE hub)

### Notification REST APIs (Category: external-patterns)
- GitHub Notifications API (`GET /notifications`) — pagination, filtering, mark-as-read
- Slack API notification patterns
- Discord notification model
- Generic REST notification endpoint best practices

### phpBB Ecosystem (Category: external-phpbb)
- phpBB extension development documentation (notification hooks)
- phpBB community notification extensions (AON, advanced notifications)
- phpBB 3.3 notification system changelog and improvements
- phpBB GitHub repo notification-related PRs and issues

---

## Source Priority Matrix

| Source | Priority | Rationale |
|--------|----------|-----------|
| Notification manager + types | CRITICAL | Core system to wrap/extend |
| API controllers + routing | CRITICAL | Pattern to follow for new endpoint |
| Email method + queue | HIGH | Email delivery must be preserved |
| Cache service design | HIGH | Performance target depends on it |
| Auth subscriber | HIGH | Security enforcement pattern |
| External notification APIs | MEDIUM | Inspiration for API design |
| Polling/SSE/WS research | MEDIUM | Real-time strategy decision |
| phpBB extensions | LOW | Community patterns, nice-to-have |
