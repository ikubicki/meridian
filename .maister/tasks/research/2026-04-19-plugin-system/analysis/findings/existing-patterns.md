# Existing Extension Patterns in phpBB Services

## 1. DecoratorPipeline Pattern

### Interface Definitions

Both `RequestDecoratorInterface` and `ResponseDecoratorInterface` are defined identically across services (hierarchy, threads, user). Each service has its own copy in `{service}\decorator\` namespace.

**RequestDecoratorInterface:**

```php
namespace phpbb\{service}\decorator;

interface RequestDecoratorInterface
{
    /** Whether this decorator applies to the given request. */
    public function supports(object $request): bool;

    /** Decorate the request. Return the (potentially modified) request. Use $request->withExtra() to add plugin-specific data. */
    public function decorateRequest(object $request): object;

    /** Execution priority. Lower = earlier in chain. */
    public function getPriority(): int;
}
```

**ResponseDecoratorInterface:**

```php
namespace phpbb\{service}\decorator;

interface ResponseDecoratorInterface
{
    public function supports(object $response): bool;

    /** Decorate the response. Return the (potentially enriched) response. Use $response->withExtra() to add plugin-specific data. */
    public function decorateResponse(object $response, object $request): object;

    public function getPriority(): int;
}
```

### DecoratorPipeline Implementation

```php
namespace phpbb\{service}\decorator;

final class DecoratorPipeline
{
    /** @var RequestDecoratorInterface[] sorted by priority */
    private array $requestDecorators = [];
    /** @var ResponseDecoratorInterface[] sorted by priority */
    private array $responseDecorators = [];

    public function addRequestDecorator(RequestDecoratorInterface $decorator): void
    {
        $this->requestDecorators[] = $decorator;
        usort($this->requestDecorators, fn($a, $b) => $a->getPriority() <=> $b->getPriority());
    }

    public function addResponseDecorator(ResponseDecoratorInterface $decorator): void
    {
        $this->responseDecorators[] = $decorator;
        usort($this->responseDecorators, fn($a, $b) => $a->getPriority() <=> $b->getPriority());
    }

    public function decorateRequest(object $request): object
    {
        foreach ($this->requestDecorators as $decorator) {
            if ($decorator->supports($request)) {
                $request = $decorator->decorateRequest($request);
            }
        }
        return $request;
    }

    public function decorateResponse(object $response, object $request): object
    {
        foreach ($this->responseDecorators as $decorator) {
            if ($decorator->supports($response)) {
                $response = $decorator->decorateResponse($response, $request);
            }
        }
        return $response;
    }
}
```

### Registration Mechanism

Decorators register via **Symfony DI tags** collected by a minimal compiler pass. This is NOT the legacy `service_collection` pattern but a targeted pipeline assembly.

**Tag conventions per service:**

| Service | Request Tag | Response Tag |
|---------|-------------|--------------|
| hierarchy | `hierarchy.request_decorator` | `hierarchy.response_decorator` |
| threads | `phpbb.threads.request_decorator` | `phpbb.threads.response_decorator` |
| user | (same pattern, service-specific prefix) | (same pattern) |

**Example YAML registration:**

```yaml
services:
    poll.request_decorator:
        class: phpbb\poll\PollRequestDecorator
        tags: ['phpbb.threads.request_decorator']

    poll.response_decorator:
        class: phpbb\poll\PollResponseDecorator
        tags: ['phpbb.threads.response_decorator']
```

### Priority/Ordering

- Lower priority number = earlier in chain
- Default convention: core decorators use 0-50, plugins use 100+
- `supports()` short-circuits — decorators only execute if they apply

### Data Flow: `withExtra()` Pattern

All request/response DTOs support a common extras mechanism:

```php
class CreateForumRequest
{
    private array $extra = [];

    // ...constructor with readonly properties...

    public function withExtra(string $key, mixed $value): static
    {
        $clone = clone $this;
        $clone->extra[$key] = $value;
        return $clone;
    }

    public function getExtra(string $key, mixed $default = null): mixed
    {
        return $this->extra[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function getAllExtra(): array
    {
        return $this->extra;
    }
}
```

Key characteristics:
- Immutable cloning (`clone $this` + set = new instance)
- Untyped (`mixed`) — no compile-time key validation
- Accumulated across decorators — each decorator can add/override keys
- Available to both service logic and event subscribers via `$event->request->getExtra('key')`

### Services Using DecoratorPipeline

| Service | Decorated Operations | Use Cases |
|---------|---------------------|-----------|
| **hierarchy** | createForum, updateForum, moveForum, deleteForum | Wiki forum fields, gallery metadata |
| **threads** | createTopic, createReply, editPost, getTopicPosts | Polls, attachments, read tracking |
| **user** | register, getProfile, search | CAPTCHA, badges, reputation, custom fields |
| **search** | Pre/Post search, Pre/Post index | Permission filtering, shadow ban, caching |
| **messaging** | Send message | (MessageSendDecorator interface) |

---

## 2. EventDispatcher Pattern

### Infrastructure

All services use **Symfony EventDispatcher** (`EventSubscriberInterface`). The project defines a thin internal contract:

```php
interface EventDispatcherInterface
{
    public function dispatch(object $event): void;
}
```

This wraps Symfony's `EventDispatcherInterface` for testability and DI isolation.

### Event Naming Conventions

Events follow a strict naming pattern:

```
{Entity}{Action}Event
```

**Examples:**
- `ForumCreatedEvent`, `ForumUpdatedEvent`, `ForumDeletedEvent`, `ForumMovedEvent`
- `TopicCreatedEvent`, `TopicEditedEvent`, `TopicLockedEvent`, `TopicMovedEvent`
- `PostCreatedEvent`, `PostEditedEvent`, `PostSoftDeletedEvent`, `PostRestoredEvent`
- `UserCreatedEvent`, `UserBannedEvent`, `UserDeletedEvent`, `PasswordChangedEvent`
- `DraftSavedEvent`, `DraftDeletedEvent`

**Dispatch naming**: Event FQCN is the event name (Symfony convention). No string event names.

### Event Payload Pattern

All events are `final readonly class` with constructor-promoted properties:

```php
final readonly class ForumCreatedEvent
{
    public function __construct(
        public Forum $forum,
        public ForumResponse $response,
    ) {}
}

final readonly class PostCreatedEvent
{
    // Carries enough data for listeners to act without additional queries
    public int $postId;
    public int $topicId;
    public int $forumId;
    public int $posterId;
    public Visibility $visibility;
    public bool $isFirstPost;
    public object $request; // original request with extras
}

final readonly class UserCreatedEvent
{
    public function __construct(
        public int $userId,
        public string $username,
        public string $email,
        public int $timestamp,
    ) {}
}
```

Key payload rules:
- Events carry **enough data for listeners to act without additional queries**
- Mutation events embed both entity and decorated response
- Events carry the original request (with `getExtra()` data) so subscribers can access decorator-added data
- Events are dispatched **after** the database transaction commits

### Two Event Categories

1. **Mutation events** (returned by service methods + dispatched):
   - Service method returns the event as its value
   - Same event is dispatched to EventDispatcher for listeners
   - Example: `$event = $hierarchy->createForum($request); // ForumCreatedEvent`

2. **Signal events** (dispatched only, not returned):
   - Internal lifecycle events for side effects
   - Example: `ForumCountersChangedEvent`, `ContentPreParseEvent`

### Subscription Pattern

Plugins subscribe as standard Symfony `EventSubscriberInterface`:

```php
final class WikiForumListener implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            RegisterForumTypesEvent::class => 'onRegisterTypes',
            ForumCreatedEvent::class       => 'onForumCreated',
            ForumDeletedEvent::class       => 'onForumDeleted',
        ];
    }
}
```

Registration in DI:

```yaml
services:
    wiki.event_subscriber:
        class: acme\wiki_forum\listener\WikiForumListener
        tags: ['kernel.event_subscriber']
```

### Boot-Time Events (Special)

For extensible registries, a boot-time event pattern is used:

```php
// Dispatched during service initialization
$event = new RegisterForumTypesEvent($this->typeRegistry);
$this->dispatcher->dispatch($event);
```

Subscribers call `$event->register(new WikiForumBehavior())` to add custom types.

### Cross-Service Event Flow

| Producer | Events | Consumers |
|----------|--------|-----------|
| threads | `PostCreatedEvent`, `PostSoftDeletedEvent`, `PostRestoredEvent`, `VisibilityChangedEvent`, `TopicDeletedEvent` | user (counter), search (index), notifications (notify) |
| threads | `ForumCountersChangedEvent` | hierarchy (update forum stats) |
| user | `UserCreatedEvent`, `UserDeletedEvent`, `UserBannedEvent`, `UsernameChangedEvent` | auth (cache), threads (denorm), notifications |
| hierarchy | `ForumCreatedEvent`, `ForumDeletedEvent`, `ForumMovedEvent` | cache (invalidate), wiki plugin |

---

## 3. JSON Column Pattern

### Usage in User Service

Two JSON columns on `phpbb_users`:

```sql
ALTER TABLE phpbb_users
    ADD COLUMN profile_fields JSON DEFAULT NULL
        COMMENT 'Extensible profile data replacing pf_* columns',
    ADD COLUMN preferences JSON DEFAULT NULL
        COMMENT 'User preferences replacing user_options bitfield + individual pref columns';
```

### profile_fields — Schema-Free Extensible Data

**Storage:** `{"location": "Warsaw", "interests": "PHP", "website": "https://example.com"}`

**Characteristics:**
- Schema-free — plugins add keys without DDL migrations
- `NULL` means empty (no fields set)
- Associative array structure
- Hydrated into `UserProfile::$profileFields` as `array`
- No DB-side validation — schema validation is PHP-side only

**Access pattern:**
- Read: `ProfileService::getProfile(int $userId): UserProfile` → `$profile->profileFields`
- Write: `ProfileService::updateProfile(int $userId, UpdateProfileDTO): UserProfile`
- The `UpdateProfileDTO` carries a `profileFields` array that gets JSON-encoded and written

**Extension integration:**
- Plugins add custom profile fields by writing to the JSON via RequestDecorators on `updateProfile`
- ResponseDecorators on `getProfile` can add computed display data from profile_fields
- No per-field type enforcement at DB level — PHP DTOs provide type safety

### preferences — Typed Sparse Storage

**Storage:** `{"viewImages": true, "viewSmilies": false, "language": "pl", "timezone": "Europe/Warsaw"}`

**Characteristics:**
- Only **non-default** values stored (sparse representation)
- `NULL` means "use all defaults"
- Mapped to typed `UserPreferences` DTO with ~30 properties
- Defaults applied in PHP code, not DB defaults

**Access pattern:**
- Read: `PreferencesService::getPreferences(int $userId): UserPreferences`
  - Loads JSON from DB, merges with PHP defaults, returns typed DTO
- Write: `PreferencesService::updatePreferences(int $userId, UpdatePreferencesDTO): UserPreferences`
  - Merges new values, strips defaults (sparse), JSON-encodes, writes

**Schema validation:**
- PHP-side only via `UserPreferences` DTO (typed properties with defaults)
- Unknown keys silently ignored on read
- No `JSON_SCHEMA_VALID()` or MySQL `CHECK` constraints

### How External Consumers Access JSON Fields

1. **Read**: Services expose JSON data via typed DTOs (e.g., `UserProfile::$profileFields`, `UserPreferences`)
2. **Write**: Consumers send `UpdateProfileDTO` or `UpdatePreferencesDTO` with new values
3. **Plugins**: Use RequestDecorators to inject additional keys before save
4. **Cross-service**: `UserDisplayDTO` (lightweight batch) does NOT expose JSON fields — only core display data

---

## 4. Common Integration Points

### Where All Three Patterns Intersect

**Registration flow (user service):**
1. `DecoratorPipeline` runs request decorators (e.g., CAPTCHA plugin validates token via `getExtra()`)
2. Service executes core logic (INSERT user, JSON columns initialized as NULL)
3. `UserCreatedEvent` dispatched (listeners react: welcome notification, group setup)
4. `DecoratorPipeline` runs response decorators (enrich created user with welcome data)

**Topic creation (threads service):**
1. `DecoratorPipeline` runs request decorators (Poll plugin adds `poll_config` via `withExtra()`, Attachment plugin adds `attachment_refs`)
2. Service executes core logic
3. `TopicCreatedEvent` dispatched — carries `$event->request` with all extras
4. `PollEventSubscriber` reads `$event->request->getExtra('poll_config')` → creates poll
5. `AttachmentEventSubscriber` reads `$event->request->getExtra('attachment_refs')` → adopts orphans
6. Response decorators enrich the response (read tracking markers, poll data)

**Forum creation (hierarchy service):**
1. Request decorators add type-specific fields (Wiki plugin adds `wiki_default_page` etc.)
2. Service inserts forum in DB
3. `ForumCreatedEvent` dispatched with entity + decorated response
4. Wiki plugin listener initializes wiki structure for the new forum
5. Cache listener invalidates forum cache
6. Response sent to caller with full event (entity + extras)

### The Shared Flow Pattern

```
Request DTO → DecoratorPipeline::decorateRequest()
  → Service Logic (DB operations)
    → Domain Event dispatched to EventDispatcher
      → Subscribers react (side effects using request extras)
  → DecoratorPipeline::decorateResponse()
    → Response DTO (with extras) returned to caller
```

This exact flow is replicated in hierarchy, threads, user, and messaging services.

### JSON + Decorators Integration

- Decorators can read JSON field values from the request (e.g., validate profile_fields schema)
- Decorators can inject extra JSON keys into the response
- Event subscribers can trigger JSON updates on other entities (cross-service)
- The `withExtra()` mechanism on DTOs is analogous to the JSON column pattern — both are schema-free key-value maps

---

## 5. Gaps and Limitations Discovered

### DecoratorPipeline Gaps

1. **No shared base interface** — Each service defines its own copy of `RequestDecoratorInterface`, `ResponseDecoratorInterface`, and `DecoratorPipeline`. No shared contract in a common package.
2. **No type safety on extras** — `withExtra()` uses `mixed` values with string keys. No compile-time validation that a key exists or has the expected type.
3. **No decorator lifecycle management** — Decorators are stateless; no `setUp()`/`tearDown()` hooks. If a request decorator needs cleanup after response, it must also register as a response decorator.
4. **Processing overhead** — Every decorator runs `supports()` on every request/response. Mitigated by short-circuit but scales linearly with decorator count.
5. **No error handling in pipeline** — If a decorator throws, behavior is undefined. No try/catch wrapper or decorator isolation.
6. **Messaging uses different interface** — Messaging has `MessageSendDecorator` (not `RequestDecoratorInterface` + `ResponseDecoratorInterface`), suggesting the pattern isn't fully unified.

### EventDispatcher Gaps

1. **No event ordering guarantees between listeners** — Symfony EventDispatcher supports priority but it's not enforced in the HLDs.
2. **No error isolation** — If one listener throws, subsequent listeners don't execute. No dead-letter queue or retry mechanism.
3. **No async events** — All events dispatch synchronously post-commit. No background queue for expensive operations.
4. **Circular dependency risk** — Service A dispatches event → Service B listener calls Service A method. No safeguards documented.
5. **Event discovery is implicit** — No registry of "which events exist with which payloads." Must read source code or docs.

### JSON Column Gaps

1. **No cross-service JSON schema coordination** — If plugin X adds `profile_fields.badges` and plugin Y adds `profile_fields.badges` with different structure, conflict is undetected.
2. **No indexing on JSON fields** — No documented use of MySQL JSON indexes (`JSON_EXTRACT` virtual columns). Performance concern for queries filtering by JSON values.
3. **No versioning** — No way to migrate JSON schema across versions. Old data with missing keys relies on PHP defaults.
4. **Limited query capability** — Cannot efficiently JOIN or WHERE on JSON field values across tables.
5. **Storage efficiency** — Sparse storage (only non-defaults) saves space but makes raw DB inspection harder.

### Unresolved Cross-Pattern Issues

1. **Extension model contradiction** — services-architecture.md notes: "Hierarchy/Threads use events+decorators; Notifications uses tagged DI. Need unified ADR." Not yet resolved.
2. **No unified plugin manifest** — A plugin's capabilities (which events it subscribes to, which decorators it registers) are spread across YAML tags. No single "plugin.json" descriptor.
3. **decorator + event ordering** — When both a response decorator and an event listener modify the response, the ordering between them is unclear.
