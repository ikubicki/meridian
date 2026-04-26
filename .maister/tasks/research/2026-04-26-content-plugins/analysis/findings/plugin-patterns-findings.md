# Plugin Patterns Findings — phpBB4 Codebase

**Source category**: plugin-patterns  
**Date**: 2026-04-26  
**Task**: Content plugin injection architecture in phpBB4 (Symfony 8.x rewrite)

---

## Summary

phpBB4 uses **two distinct extension/plugin patterns** in the `src/phpbb/` namespace:

| Pattern | Used in | Registration | Lookup |
|---------|---------|-------------|--------|
| **Manual Registry** | `ForumBehaviorRegistry` | Caller calls `->register()` imperatively | `->getForType(string)` |
| **Event-Dispatched Registry** | `TypeRegistry`, `MethodManager` | Symfony `kernel.event_listener` tag on each type | `->getByName(string)`, `->all()` (lazy via `EventDispatcher::dispatch`) |

No `tagged_iterator`, `tagged_locator`, or `AutoconfigureTag` usage exists anywhere in `src/`. The preferred phpBB4 pattern is **event-dispatched lazy registry**.

---

## Pattern 1 — Manual Registry (`ForumBehaviorRegistry`)

### File: `src/phpbb/hierarchy/Plugin/ForumBehaviorInterface.php`

```php
namespace phpbb\hierarchy\Plugin;

use phpbb\hierarchy\Contract\RequestDecoratorInterface;
use phpbb\hierarchy\Contract\ResponseDecoratorInterface;

interface ForumBehaviorInterface extends RequestDecoratorInterface, ResponseDecoratorInterface
{
    public function supports(string $forumType): bool;
}
```

The interface composes two parent contracts:

**`src/phpbb/hierarchy/Contract/RequestDecoratorInterface.php`**:
```php
interface RequestDecoratorInterface
{
    public function decorateCreate(CreateForumRequest $request): CreateForumRequest;
    public function decorateUpdate(UpdateForumRequest $request): UpdateForumRequest;
}
```

**`src/phpbb/hierarchy/Contract/ResponseDecoratorInterface.php`**:
```php
interface ResponseDecoratorInterface
{
    public function decorateResponse(Forum $forum): Forum;
}
```

### File: `src/phpbb/hierarchy/Plugin/ForumBehaviorRegistry.php`

```php
namespace phpbb\hierarchy\Plugin;

final class ForumBehaviorRegistry
{
    /** @var ForumBehaviorInterface[] */
    private array $behaviors = [];

    public function register(ForumBehaviorInterface $behavior): void
    {
        $this->behaviors[] = $behavior;
    }

    /** @return ForumBehaviorInterface[] */
    public function getBehaviors(): array
    {
        return $this->behaviors;
    }

    /** @return ForumBehaviorInterface[] */
    public function getForType(string $forumType): array
    {
        return array_values(
            array_filter(
                $this->behaviors,
                static fn (ForumBehaviorInterface $b): bool => $b->supports($forumType),
            )
        );
    }

    public function count(): int
    {
        return count($this->behaviors);
    }
}
```

### How it is wired in `services.yaml`

```yaml
phpbb\hierarchy\Plugin\ForumBehaviorRegistry: ~
```

The registry is defined with no arguments (empty `~`). **No behavior implementations are automatically registered via DI**. The registry is populated imperatively by callers invoking `->register()`. This pattern is simpler but requires the caller to know about all plugins.

**Evidence**: `src/phpbb/config/services.yaml` lines 132–133. No other PHP file in `src/phpbb/` calls `ForumBehaviorRegistry::register()` — the registry exists but behaviors are not yet wired (the feature is scaffolded, not yet populated via DI).

---

## Pattern 2 — Event-Dispatched Lazy Registry (Notifications)

This is the **more mature, preferred pattern** in phpBB4 for extensible type systems. It uses Symfony's `kernel.event_listener` tag so that `EventDispatcher::dispatch()` calls all registered handlers on first use.

### File: `src/phpbb/notifications/Contract/NotificationTypeInterface.php`

```php
namespace phpbb\notifications\Contract;

interface NotificationTypeInterface
{
    public function getTypeName(): string;
}
```

### File: `src/phpbb/notifications/Event/RegisterNotificationTypesEvent.php`

```php
namespace phpbb\notifications\Event;

use phpbb\notifications\Contract\NotificationTypeInterface;

/**
 * Event used to collect available notification type registrations
 */
final class RegisterNotificationTypesEvent
{
    private array $types = [];

    public function addType(NotificationTypeInterface $type): void
    {
        $this->types[] = $type;
    }

    public function getTypes(): array
    {
        return $this->types;
    }
}
```

### File: `src/phpbb/notifications/TypeRegistry.php`

```php
namespace phpbb\notifications;

use phpbb\notifications\Contract\NotificationTypeInterface;
use phpbb\notifications\Event\RegisterNotificationTypesEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class TypeRegistry
{
    private ?array $types = null;

    public function __construct(private readonly EventDispatcherInterface $dispatcher)
    {
    }

    private function initialize(): void
    {
        if ($this->types !== null) {
            return;
        }

        $event = new RegisterNotificationTypesEvent();
        $this->dispatcher->dispatch($event);

        $this->types = [];
        foreach ($event->getTypes() as $type) {
            $this->types[$type->getTypeName()] = $type;
        }
    }

    public function getByName(string $typeName): NotificationTypeInterface
    {
        $this->initialize();
        if (!isset($this->types[$typeName])) {
            throw new \InvalidArgumentException("Unknown notification type: $typeName");
        }
        return $this->types[$typeName];
    }

    public function all(): array
    {
        $this->initialize();
        return $this->types;
    }
}
```

### File: `src/phpbb/notifications/Type/PostNotificationType.php` (concrete type example)

```php
namespace phpbb\notifications\Type;

use phpbb\notifications\Contract\NotificationTypeInterface;
use phpbb\notifications\Event\RegisterNotificationTypesEvent;

final class PostNotificationType implements NotificationTypeInterface
{
    public function getTypeName(): string
    {
        return 'notification.type.post';
    }

    public function register(RegisterNotificationTypesEvent $event): void
    {
        $event->addType($this);
    }
}
```

### How these are wired in `services.yaml` (lines ~360–378)

```yaml
phpbb\notifications\TypeRegistry:
    arguments:
        $dispatcher: '@Symfony\Component\EventDispatcher\EventDispatcherInterface'

phpbb\notifications\Type\PostNotificationType:
    tags:
        - { name: kernel.event_listener, event: phpbb\notifications\Event\RegisterNotificationTypesEvent, method: register }

phpbb\notifications\Type\TopicNotificationType:
    tags:
        - { name: kernel.event_listener, event: phpbb\notifications\Event\RegisterNotificationTypesEvent, method: register }
```

The **same pattern is repeated** for delivery methods:

```yaml
phpbb\notifications\MethodManager:
    arguments:
        $dispatcher: '@Symfony\Component\EventDispatcher\EventDispatcherInterface'

phpbb\notifications\Method\BoardNotificationMethod:
    tags:
        - { name: kernel.event_listener, event: phpbb\notifications\Event\RegisterDeliveryMethodsEvent, method: register }

phpbb\notifications\Method\EmailNotificationMethod:
    tags:
        - { name: kernel.event_listener, event: phpbb\notifications\Event\RegisterDeliveryMethodsEvent, method: register }
```

---

## Pattern 3 — `EventSubscriberInterface` (Infrastructure / Middleware)

Used for Symfony kernel event hooks (request/response pipeline). Not for extensible plugin registries — only for hard-wired infrastructure subscribers.

### File: `src/phpbb/notifications/Listener/CacheInvalidationSubscriber.php`

```php
final class CacheInvalidationSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            NotificationReadEvent::class  => 'onNotificationRead',
            NotificationsReadAllEvent::class => 'onNotificationsReadAll',
        ];
    }
    // ...
}
```

### File: `src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php`

```php
class AuthenticationSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 16],
        ];
    }
    // ...
}
```

These are auto-configured (`autoconfigure: true` in `services.yaml`), so no manual tag is needed — Symfony picks them up automatically.

---

## Pattern 4 — `kernel.event_listener` tag (non-subscriber, targeted method)

Used in `ThumbnailListener` for storage events:

```yaml
phpbb\storage\Variant\ThumbnailListener:
    # ...
    tags:
        - { name: kernel.event_listener, event: phpbb.storage.file_stored, method: onFileStored }
```

This is the same mechanism the notification type registry uses internally — each type's `register()` method fires when `RegisterNotificationTypesEvent` is dispatched.

---

## Tagged Services — Verdict

`tagged_iterator` and `tagged_locator` are **not used anywhere** in `src/`. The codebase uses the event-dispatch pattern instead of DI container service locators.

---

## Recommended Pattern for `ContentProcessorInterface`

Based on the evidence above, the **event-dispatched lazy registry** (Pattern 2) is the established phpBB4 idiom. A `ContentProcessorInterface` should follow the same structure:

### Recommended interface model

```php
namespace phpbb\content\Contract;

interface ContentProcessorInterface
{
    public function getProcessorName(): string;
    public function supports(string $contentType): bool;  // for type-filtered dispatch
    public function process(ContentContext $context): ContentContext;
}
```

### Recommended event model

```php
namespace phpbb\content\Event;

use phpbb\content\Contract\ContentProcessorInterface;

final class RegisterContentProcessorsEvent
{
    private array $processors = [];

    public function addProcessor(ContentProcessorInterface $processor): void
    {
        $this->processors[] = $processor;
    }

    public function getProcessors(): array
    {
        return $this->processors;
    }
}
```

### Recommended registry model (mirrors `TypeRegistry`)

```php
namespace phpbb\content;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class ContentProcessorRegistry
{
    private ?array $processors = null;

    public function __construct(private readonly EventDispatcherInterface $dispatcher) {}

    private function initialize(): void
    {
        if ($this->processors !== null) return;
        $event = new Event\RegisterContentProcessorsEvent();
        $this->dispatcher->dispatch($event);
        $this->processors = [];
        foreach ($event->getProcessors() as $p) {
            $this->processors[$p->getProcessorName()] = $p;
        }
    }

    /** @return ContentProcessorInterface[] */
    public function getForType(string $contentType): array
    {
        $this->initialize();
        return array_values(
            array_filter($this->processors, fn ($p) => $p->supports($contentType))
        );
    }
}
```

### `services.yaml` wiring model

```yaml
phpbb\content\ContentProcessorRegistry:
    arguments:
        $dispatcher: '@Symfony\Component\EventDispatcher\EventDispatcherInterface'

phpbb\content\Processor\BbcodeProcessor:
    tags:
        - { name: kernel.event_listener, event: phpbb\content\Event\RegisterContentProcessorsEvent, method: register }

phpbb\content\Processor\MentionProcessor:
    tags:
        - { name: kernel.event_listener, event: phpbb\content\Event\RegisterContentProcessorsEvent, method: register }
```

Each concrete processor implements `register(RegisterContentProcessorsEvent $event): void` that calls `$event->addProcessor($this)`.

---

## Key Facts

- `services.yaml` global defaults: `autowire: true`, `autoconfigure: true` — subscribers are auto-tagged; explicit listener tags required for non-subscriber classes.
- `ForumBehaviorRegistry` is **not yet called** from any production code — it is scaffolded only.
- `TypeRegistry` and `MethodManager` are the **live, battle-tested** pattern.
- No pipeline/chain-of-responsibility (`Pipeline`, `HandlerChain`, `ProcessorChain`) classes exist — these patterns are not in use.
- `tagged_iterator`/`tagged_locator` are not used — event dispatch is preferred.

---

## Source Citations

| Finding | Source | Lines |
|---------|--------|-------|
| `ForumBehaviorInterface` definition | `src/phpbb/hierarchy/Plugin/ForumBehaviorInterface.php` | 1–24 |
| `RequestDecoratorInterface` | `src/phpbb/hierarchy/Contract/RequestDecoratorInterface.php` | 1–27 |
| `ResponseDecoratorInterface` | `src/phpbb/hierarchy/Contract/ResponseDecoratorInterface.php` | 1–24 |
| `ForumBehaviorRegistry` full impl | `src/phpbb/hierarchy/Plugin/ForumBehaviorRegistry.php` | 1–53 |
| `NotificationTypeInterface` | `src/phpbb/notifications/Contract/NotificationTypeInterface.php` | 1–22 |
| `RegisterNotificationTypesEvent` | `src/phpbb/notifications/Event/RegisterNotificationTypesEvent.php` | 1–38 |
| `TypeRegistry` (lazy init via EventDispatcher) | `src/phpbb/notifications/TypeRegistry.php` | 1–65 |
| `PostNotificationType` (concrete example) | `src/phpbb/notifications/Type/PostNotificationType.php` | 1–35 |
| `MethodManager` (second event-registry example) | `src/phpbb/notifications/MethodManager.php` | 1–62 |
| `CacheInvalidationSubscriber` | `src/phpbb/notifications/Listener/CacheInvalidationSubscriber.php` | 1–60 |
| `AuthenticationSubscriber` | `src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php` | 1–80 |
| `ThumbnailListener` (kernel.event_listener tag) | `src/phpbb/storage/Variant/ThumbnailListener.php` | 1–80 |
| All service wiring | `src/phpbb/config/services.yaml` | 1–400 |
| `ForumBehaviorRegistry` test (usage pattern) | `tests/phpbb/hierarchy/Plugin/ForumBehaviorRegistryTest.php` | 1–112 |
