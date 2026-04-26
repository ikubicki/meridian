# Symfony Patterns Findings — Content Middleware Pipeline

**Source Category**: `symfony-patterns`
**Gathered**: 2026-04-26
**Confidence**: High (100%) — direct code inspection

---

## 1. Symfony Version

**Source**: `composer.json`
**Finding**: All Symfony packages locked at `^8.0`.

```json
"symfony/dependency-injection": "^8.0",
"symfony/event-dispatcher": "^8.0",
"symfony/framework-bundle": "^8.0",
"symfony/http-kernel": "^8.0"
```

All Symfony 8 DI attributes (`#[AsTaggedItem]`, `#[AutoconfigureTag]`, `#[AutowireIterator]`, `#[AsEventListener]`) are fully available in vendor.

---

## 2. Global DI Defaults (services.yaml)

**Source**: `src/phpbb/config/services.yaml:1-5`

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false
```

- `autoconfigure: true` means any class implementing `EventSubscriberInterface` is automatically tagged `kernel.event_subscriber` — no explicit tag needed.
- `autowire: true` means constructor type-hints are resolved automatically.
- New interfaces used as constructor parameters do NOT need manual binding if their alias is registered.
- Only `phpbb\api\Controller\:` and `phpbb\api\EventSubscriber\:` use `resource:` directory scanning. Every other service is **explicitly listed**. New content processors must be added explicitly.

---

## 3. tagged_iterator / AutoconfigureTag — Not Currently Used

**Source**: grep over `src/` and `config/` for `tagged_iterator`, `tagged_locator`, `AutoconfigureTag`, `!tagged`
**Finding**: **Zero occurrences.** This pattern is available (vendor has the attribute files) but has never been adopted in this codebase.

Available in vendor (Symfony 8):
- `vendor/symfony/dependency-injection/Attribute/AutoconfigureTag.php`
- `vendor/symfony/dependency-injection/Attribute/AsTaggedItem.php`
- `vendor/symfony/dependency-injection/Attribute/AutowireIterator.php`

---

## 4. Existing Plugin-Registration Patterns

### 4a. RegisterXxxEvent + kernel.event_listener (canonical pattern)

**Source**: `src/phpbb/notifications/` module

This is the **established pattern** for ad-hoc plugin registration in this codebase.

**Event class** (`RegisterNotificationTypesEvent.php:24-38`):
```php
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

**Registry** (`TypeRegistry.php:36-47`) — lazy, dispatches on first use:
```php
private function initialize(): void
{
    if ($this->types !== null) { return; }

    $event = new RegisterNotificationTypesEvent();
    $this->dispatcher->dispatch($event);

    $this->types = [];
    foreach ($event->getTypes() as $type) {
        $this->types[$type->getTypeName()] = $type;
    }
}
```

**Plugin implementation** (`PostNotificationType.php:29`):
```php
public function register(RegisterNotificationTypesEvent $event): void
{
    $event->addType($this);
}
```

**Service wiring** (`services.yaml:362-363`):
```yaml
phpbb\notifications\Type\PostNotificationType:
    tags:
        - { name: kernel.event_listener, event: phpbb\notifications\Event\RegisterNotificationTypesEvent, method: register }
```

**Ordering (CRITICAL OBSERVATION)**: No `priority:` is set on any existing `kernel.event_listener` tag in `services.yaml`. The current notification types have **no defined order**. This does NOT mean priority is unavailable — it is simply not needed for notification types. For content middleware, ordering is essential.

---

### 4b. ForumBehaviorRegistry (manual push registry)

**Source**: `src/phpbb/hierarchy/Plugin/ForumBehaviorRegistry.php:19-53`

A plain PHP registry with explicit `register(ForumBehaviorInterface $behavior)` method. Services are wired in DI but no ordering mechanism exists. Registered as bare `~` in services.yaml:

```yaml
phpbb\hierarchy\Plugin\ForumBehaviorRegistry: ~
```

This pattern does not support easy ordering — it relies on DI construction order, which is non-deterministic. **Not suitable for an ordered pipeline.**

---

## 5. Priority Mechanisms Found in Codebase

### 5a. EventSubscriberInterface with inline priority (USED)

**Source**: `src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php:60-64`  
**Source**: `src/phpbb/api/EventSubscriber/AuthorizationSubscriber.php:33-37`

```php
public static function getSubscribedEvents(): array
{
    return [
        KernelEvents::REQUEST => ['onKernelRequest', 16],  // priority 16
    ];
}
```

```php
public static function getSubscribedEvents(): array
{
    return [
        KernelEvents::REQUEST => ['onKernelRequest', 8],   // priority 8
    ];
}
```

Auth runs at 16, authorization at 8 — auth first, then permission check. **Priority IS used for ordering**, just via `EventSubscriberInterface`, not via YAML tags.

### 5b. kernel.event_listener priority: attribute in YAML (AVAILABLE, not yet used)

`kernel.event_listener` tags support a `priority:` attribute but no existing entry uses it:

```yaml
# Available syntax (not used yet):
SomeService:
    tags:
        - { name: kernel.event_listener, event: SomeEvent, method: handle, priority: 100 }
```

Higher priority = listener runs **earlier** (before lower-priority listeners).

**For the RegisterContentMiddlewareEvent pattern**: if censor has `priority: 100` and bbcode has `priority: 10`, the event listener system calls censor's `register()` first → censor is added to the array first → pipeline runs censor before bbcode. ✓

---

## 6. Available Symfony 8 Attributes (Unused in Codebase)

### #[AsEventListener]

**Source**: `vendor/symfony/event-dispatcher/Attribute/AsEventListener.php`

```php
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class AsEventListener
{
    public function __construct(
        public ?string $event = null,
        public ?string $method = null,
        public int $priority = 0,
        public ?string $dispatcher = null,
    ) {}
}
```

Can replace YAML `kernel.event_listener` tags entirely. With `autoconfigure: true`, a class with this attribute needs no YAML tag.

### #[AutoconfigureTag]

**Source**: `vendor/symfony/dependency-injection/Attribute/AutoconfigureTag.php`

Applied to an **interface** — all implementations automatically get the tag:

```php
#[AutoconfigureTag('phpbb.content.middleware')]
interface ContentMiddlewareInterface { ... }
```

### #[AsTaggedItem]

**Source**: `vendor/symfony/dependency-injection/Attribute/AsTaggedItem.php`

```php
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
class AsTaggedItem
{
    public function __construct(
        public ?string $index = null,
        public ?int $priority = null,   // higher = earlier in iterator
    ) {}
}
```

Applied to a **class** to set its priority in tagged iterators.

### #[AutowireIterator]

**Source**: `vendor/symfony/dependency-injection/Attribute/AutowireIterator.php`

```php
#[\Attribute(\Attribute::TARGET_PARAMETER)]
class AutowireIterator extends Autowire
{
    public function __construct(
        string $tag,
        ?string $indexAttribute = null,
        ?string $defaultIndexMethod = null,
        ?string $defaultPriorityMethod = null,
        string|array $exclude = [],
        bool $excludeSelf = true,
    ) {}
}
```

Applied to a **constructor parameter** to inject a sorted iterable of tagged services.

---

## 7. Comparison: Two Viable Approaches

### Approach A — RegisterContentMiddlewareEvent (consistent with codebase)

**Pattern origin**: notifications module (`RegisterNotificationTypesEvent`)

**Ordering mechanism**: `priority:` on `kernel.event_listener` YAML tag. Higher priority listener fires first → adds its middleware first → pipeline runs it first.

**Services.yaml wiring example**:
```yaml
phpbb\content\Processor\CensorProcessor:
    tags:
        - { name: kernel.event_listener, event: phpbb\content\Event\RegisterContentMiddlewareEvent, method: register, priority: 100 }

phpbb\content\Processor\BbcodeProcessor:
    tags:
        - { name: kernel.event_listener, event: phpbb\content\Event\RegisterContentMiddlewareEvent, method: register, priority: 50 }

phpbb\content\Processor\MarkdownProcessor:
    tags:
        - { name: kernel.event_listener, event: phpbb\content\Event\RegisterContentMiddlewareEvent, method: register, priority: 30 }

phpbb\content\Processor\SmilesProcessor:
    tags:
        - { name: kernel.event_listener, event: phpbb\content\Event\RegisterContentMiddlewareEvent, method: register, priority: 10 }
```

**Pipeline class** receives `EventDispatcherInterface`, dispatches event lazily, collects ordered result:
```php
final class ContentMiddlewarePipeline
{
    private ?array $middlewares = null;

    public function __construct(private readonly EventDispatcherInterface $dispatcher) {}

    private function boot(): void
    {
        if ($this->middlewares !== null) { return; }
        $event = new RegisterContentMiddlewareEvent();
        $this->dispatcher->dispatch($event);
        $this->middlewares = $event->getMiddlewares();
    }

    public function process(string $text, array $context = []): string
    {
        $this->boot();
        foreach ($this->middlewares as $middleware) {
            $text = $middleware->process($text, $context);
        }
        return $text;
    }
}
```

**Event class** (supports ordering via addMiddleware — order determined by priority of listener, not insertion index):
```php
final class RegisterContentMiddlewareEvent
{
    private array $middlewares = [];

    public function addMiddleware(ContentMiddlewareInterface $middleware): void
    {
        $this->middlewares[] = $middleware;
    }

    /** @return ContentMiddlewareInterface[] */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }
}
```

**Pros**: Identical to established pattern, extensions add via `kernel.event_listener` tag, ordering visible in YAML.
**Cons**: Requires services.yaml entry per processor (explicit list pattern of this codebase); ordering only readable by looking at YAML priority values.

---

### Approach B — tagged_iterator with #[AsTaggedItem] (Symfony 8 idiomatic)

**Pattern origin**: New to this codebase, but fully supported by Symfony 8.

**Interface** (tag auto-applied to all implementors):
```php
#[AutoconfigureTag('phpbb.content.middleware')]
interface ContentMiddlewareInterface
{
    public function process(string $text, array $context): string;
}
```

**Each processor**:
```php
#[AsTaggedItem(priority: 100)]
final class CensorProcessor implements ContentMiddlewareInterface { ... }

#[AsTaggedItem(priority: 50)]
final class BbcodeProcessor implements ContentMiddlewareInterface { ... }
```

**Pipeline** (no event, no dispatcher — pure DI):
```php
final class ContentMiddlewarePipeline
{
    /** @param iterable<ContentMiddlewareInterface> $middlewares */
    public function __construct(
        #[AutowireIterator('phpbb.content.middleware')]
        private readonly iterable $middlewares,
    ) {}

    public function process(string $text, array $context = []): string
    {
        foreach ($this->middlewares as $middleware) {
            $text = $middleware->process($text, $context);
        }
        return $text;
    }
}
```

**Services.yaml** (only need to register the class, no explicit tags):
```yaml
phpbb\content\Processor\CensorProcessor: ~
phpbb\content\Processor\BbcodeProcessor: ~
phpbb\content\ContentMiddlewarePipeline: ~
```

The `#[AutoconfigureTag]` on the interface + `autoconfigure: true` in `_defaults` means each implementation is automatically tagged `phpbb.content.middleware`. `#[AsTaggedItem(priority: N)]` on the class sets its priority in the iterator. `#[AutowireIterator]` on the constructor parameter injects the sorted iterable (higher priority = earlier).

**Pros**: No boilerplate register event; priority is visible in the PHP file itself; clean DI; no lazy dispatch needed; Pipeline is simpler (no `boot()` pattern).
**Cons**: Introduces first use of `tagged_iterator` attributes — a new pattern in this codebase; ordering not visible in services.yaml (must check each PHP file).

---

## 8. Recommendation

**Use Approach A (`RegisterContentMiddlewareEvent`)** for the initial implementation because:

1. **Pattern consistency**: Identical to the existing `RegisterNotificationTypesEvent` / `RegisterDeliveryMethodsEvent` pattern — any dev familiar with the codebase can onboard immediately.
2. **Extension model**: Future phpBB4 extensions register new processors by adding a `kernel.event_listener` tag — the same mechanism already documented for notifications.
3. **Ordering visibility**: Priority integers in `services.yaml` are all in one file — easy to audit middleware order without reading multiple PHP files.
4. **No new DI concepts**: Does not require teaching `tagged_iterator` / `#[AutoconfigureTag]` to contributors.

**Exception**: If the team decides to refactor all plugin registries to the `tagged_iterator` model in the future (Approach B), that migration is straightforward — the interface and processor logic stay identical, only the wiring changes.

---

## 9. Priority Scale for Content Middlewares

Based on the established convention (`KernelEvents::REQUEST` at priority 16 / 8):

| Processor     | Recommended Priority | Rationale |
|---------------|---------------------|-----------|
| CensorProcessor  | 100 | Run first — removes words before any rendering |
| MarkdownProcessor | 50 | Convert Markdown to HTML before BBCode |
| BbcodeProcessor | 30 | Render BBCode after Markdown |
| SmilesProcessor  | 10 | Run last — replace text patterns in final output |

Higher number = runs earlier in pipeline = added to array first by event dispatcher.

---

## 10. Sources Investigated

| Source | Path | Finding |
|--------|------|---------|
| Symfony version | `composer.json` | ^8.0 for all packages |
| Global DI config | `src/phpbb/config/services.yaml:1-5` | autowire+autoconfigure on, explicit service list |
| tagged_iterator grep | `src/`, `config/` | Zero occurrences — not used |
| RegisterNotificationTypesEvent | `src/phpbb/notifications/Event/` | Canonical plugin-registration event pattern |
| TypeRegistry | `src/phpbb/notifications/TypeRegistry.php` | Lazy dispatcher boot pattern |
| PostNotificationType | `src/phpbb/notifications/Type/PostNotificationType.php` | register(Event) method pattern |
| services.yaml listener tags | `src/phpbb/config/services.yaml:356-376` | kernel.event_listener, no priority used |
| ForumBehaviorRegistry | `src/phpbb/hierarchy/Plugin/` | Alternative registry, no ordering |
| AuthenticationSubscriber | `src/phpbb/api/EventSubscriber/AuthenticationSubscriber.php` | getSubscribedEvents with priority=16 |
| AuthorizationSubscriber | `src/phpbb/api/EventSubscriber/AuthorizationSubscriber.php` | getSubscribedEvents with priority=8 |
| AsEventListener | `vendor/symfony/event-dispatcher/Attribute/AsEventListener.php` | Available, supports priority |
| AsTaggedItem | `vendor/symfony/dependency-injection/Attribute/AsTaggedItem.php` | Available, supports priority |
| AutoconfigureTag | `vendor/symfony/dependency-injection/Attribute/AutoconfigureTag.php` | Available |
| AutowireIterator | `vendor/symfony/dependency-injection/Attribute/AutowireIterator.php` | Available |
