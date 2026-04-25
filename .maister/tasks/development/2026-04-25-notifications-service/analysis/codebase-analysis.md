# Codebase Analysis: M8 Notifications Service

## Key Files

### Architecture Templates
- `src/phpbb/messaging/Entity/Message.php` — entity pattern (`final readonly class`, `fromRow()`)
- `src/phpbb/messaging/Repository/DbalMessageRepository.php` — DBAL repo pattern (QB-only, `hydrate()`)
- `src/phpbb/messaging/MessagingService.php` — service facade pattern
- `src/phpbb/api/Controller/MessagesController.php` — REST controller pattern
- `src/phpbb/messaging/Event/MessageCreatedEvent.php` — DomainEvent subclass
- `src/phpbb/common/Event/DomainEvent.php` — base class (`entityId`, `actorId`, `occurredAt`)
- `src/phpbb/common/Event/DomainEventCollection.php` — dispatch()
- `src/phpbb/cache/TagAwareCacheInterface.php` — `setTagged()`, `invalidateTags()`, `getOrCompute()`
- `src/phpbb/cache/CachePoolFactory.php` — `getPool('notifications')`
- `src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php` — JWT → `_api_user`
- `src/phpbb/config/services.yaml` — DI registration pattern
- `src/phpbb/config/routes.yaml` — auto-discovers `#[Route]` attributes

### DB Schema (legacy tables reused)
- `phpbb_notifications` — notification_id, type_id, item_id, item_parent_id, user_id, read, time, data (TEXT)
- `phpbb_notification_types` — type_id, type_name, enabled
- `phpbb_user_notifications` — user subscription prefs

## Patterns
- **Entity**: `final readonly class` with named constructor `fromRow()`, no business logic
- **Repository**: Doctrine DBAL QB-only, `setParameter()`, private `hydrate()` method
- **Service**: constructor DI via interfaces, returns `DomainEventCollection` for mutations
- **Controller**: thin routing layer, reads `_api_user`, calls service, dispatches events, returns JsonResponse
- **DI**: explicit `alias` entries for interfaces → implementations in services.yaml
- **Auth**: `$request->attributes->get('_api_user')` — set by AuthenticationSubscriber

## Integration Points
- `TagAwareCacheInterface::getOrCompute(key, fn, ttl, tags)` — use for count + list caching
- `CachePoolFactory::getPool('notifications')` — isolated namespace
- `EventDispatcherInterface` — inject into service; controller calls `$events->dispatch($dispatcher)`
- `_api_user` attr on Request — Symfony kernel sets it pre-controller

## Gaps (to implement)
- `src/phpbb/notifications/` — entire namespace missing
- NotificationsController REST endpoints (4)
- DI service registration in services.yaml
- DB migration for index optimization
- Unit + E2E tests

## Reuse Opportunities
All messaging patterns can be directly adapted: entity, repo, service, controller, events, tests.
