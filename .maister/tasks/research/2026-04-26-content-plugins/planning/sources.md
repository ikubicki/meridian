# Research Sources

## Codebase Sources — phpBB 4

### Key Files to Read

| File | Why |
|------|-----|
| `src/phpbb/api/Controller/PostsController.php` | Write path (create/update), read path (`postToArray()`), current content flow |
| `src/phpbb/api/Controller/MessagesController.php` | Write path (send/edit), read path (`messageToArray()`), current content flow |
| `src/phpbb/threads/ThreadsService.php` | Service layer for post content; insertion point for pre-save processing |
| `src/phpbb/messaging/MessageService.php` | Service layer for message content; insertion point for pre-save processing |
| `src/phpbb/threads/DTO/PostDTO.php` | `content` field type; where `fromEntity()` maps raw DB text |
| `src/phpbb/messaging/DTO/MessageDTO.php` | `text` field type; where `fromEntity()` maps raw DB text |
| `src/phpbb/threads/DTO/CreatePostRequest.php` | Carries raw content into service; pre-save injection target |
| `src/phpbb/messaging/DTO/Request/SendMessageRequest.php` | Carries raw text into service; pre-save injection target |
| `src/phpbb/threads/Repository/DbalPostRepository.php` | What columns are stored (text, bbcode_uid, bbcode_bitfield, etc.) |
| `src/phpbb/messaging/Repository/DbalMessageRepository.php` | What columns are stored for messages |
| `src/phpbb/hierarchy/Plugin/ForumBehaviorRegistry.php` | **Existing plugin registry pattern** — manual `register()` push model |
| `src/phpbb/hierarchy/Plugin/ForumBehaviorInterface.php` | Interface contract (decorator pattern) |
| `src/phpbb/hierarchy/Contract/RequestDecoratorInterface.php` | Decorator contract for write path |
| `src/phpbb/hierarchy/Contract/ResponseDecoratorInterface.php` | Decorator contract for read path |
| `src/phpbb/notifications/TypeRegistry.php` | **Event-dispatch lazy registration pattern** |
| `src/phpbb/notifications/Event/RegisterNotificationTypesEvent.php` | Collector event shape |
| `src/phpbb/notifications/Contract/NotificationTypeInterface.php` | Type contract for notifications (analogy for content processor) |
| `src/phpbb/common/Event/DomainEventCollection.php` | How events are batched and dispatched |
| `src/phpbb/api/EventSubscriber/ExceptionSubscriber.php` | KernelEvents subscriber pattern for pre-response interception |
| `src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php` | Another pre-response/pre-request subscriber — ordering reference |
| `src/phpbb/config/services.yaml` | DI wiring; check for existing `tagged_iterator` usage and `_instanceof` blocks |

### File Patterns to Glob

```
src/phpbb/**/Plugin/**/*.php           # all plugin-related classes
src/phpbb/**/Contract/**Interface.php  # all interfaces (look for processor/pipeline)
src/phpbb/**/Event/**Event.php         # all domain events
src/phpbb/api/EventSubscriber/**/*.php # all kernel event subscribers
src/phpbb/**/Repository/Dbal*.php      # all repositories (column audit for text fields)
```

### Grep Patterns

```
# Find all EventDispatcher injection points
grep -r "EventDispatcherInterface" src/phpbb/

# Find all tagged_iterator / AutoconfigureTag usage (may be absent — that is itself a finding)
grep -r "tagged_iterator\|AutoconfigureTag\|_instanceof" src/phpbb/

# Find all existing content/text/body serialization in controllers
grep -rn "postToArray\|messageToArray\|toArray\|'content'\|'text'" src/phpbb/api/Controller/

# Find all columns that store raw post/message body (uid, bitfield, flags)
grep -rn "bbcode_uid\|bbcode_bitfield\|bbcode_flags\|enable_bbcode\|enable_smilies" src/phpbb/
```

---

## Codebase Sources — phpBB 3 (reference)

### Key Files to Read

| File | Why |
|------|-----|
| `src/phpbb3/common/functions_content.php` | `generate_text_for_storage()`, `generate_text_for_display()`, `censor_text()`, `smiley_text()` — the canonical two-stage phpBB3 pipeline |
| `src/phpbb3/common/message_parser.php` | `MessageParser` class: how BBCode UID, bitfield, and flags are computed; where smilies are toggled |
| `src/phpbb3/common/bbcode.php` | BBCode rendering class structure and bitfield semantics |
| `src/phpbb3/common/constants.php` | `OPTION_FLAG_BBCODE`, `OPTION_FLAG_SMILIES`, etc. — flags that control which processors run |

### Grep Patterns

```
# Locate all dispatcher hook points in the content pipeline
grep -n "trigger_event" src/phpbb3/common/functions_content.php

# Understand what metadata (uid, bitfield, flags) is stored versus rendered
grep -n "uid\|bitfield\|flags\|OPTION_FLAG" src/phpbb3/common/functions_content.php | head -40
```

---

## Configuration Sources

| File | Why |
|------|-----|
| `src/phpbb/config/services.yaml` | Check DI wiring: `_defaults`, `_instanceof`, any existing tag blocks |
| `src/phpbb/config/services_test.yaml` | Test overrides — understand what can safely be aliased for testing |
| `src/phpbb/config/packages/framework.yaml` | Symfony kernel config; check if serializer component is enabled |
| `composer.json` | Confirm `symfony/serializer` and `symfony/event-dispatcher` versions present |
| `phpunit.xml` | Test suite configuration — relevant if processors need unit-testable interfaces |

---

## External Sources

| Resource | URL | Purpose |
|----------|-----|---------|
| Symfony tagged services | https://symfony.com/doc/current/service_container/tags.html | `tagged_iterator`, `#[AutoconfigureTag]`, `$priority` |
| Symfony `_instanceof` | https://symfony.com/doc/current/service_container/parent_services.html | Auto-tagging all implementors of an interface |
| `KernelEvents::VIEW` / `KernelEvents::RESPONSE` | https://symfony.com/doc/current/reference/events.html | Pre-response transformation subscriber |
| Symfony Serializer normalizer chain | https://symfony.com/doc/current/serializer.html | Pattern analogy for ordered content processing pipeline |
| phpBB3 Extension system (event docs) | https://area51.phpbb.com/docs/dev/master/extensions/skeleton_ext_tutorial.html | phpBB3 `$phpbb_dispatcher->trigger_event()` extension hook pattern |
