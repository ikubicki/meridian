# Domain Events Standard

Standard for domain event handling across all phpBB services.

---

## Core Principle

All service mutations MUST return domain events. Services do NOT dispatch events themselves — the controller or calling layer dispatches after a successful operation.

---

## Return Convention

### Mutation Methods Return `DomainEventCollection`

```php
use phpbb\common\Event\DomainEventCollection;

public function createTopic(int $forumId, string $title, string $body): DomainEventCollection
{
	$topicId = $this->repository->insert($forumId, $title, $body);

	return new DomainEventCollection([
		new TopicCreatedEvent($topicId, $forumId, $this->actorId),
	]);
}
```

### Query Methods Return Entities/DTOs

```php
// Queries never produce events
public function getTopic(int $topicId): Topic
public function listTopics(int $forumId, int $page): PaginatedResult
```

---

## Event Class Structure

### Base Class: `phpbb\common\Event\DomainEvent`

```php
namespace phpbb\common\Event;

abstract readonly class DomainEvent
{
	public function __construct(
		public readonly int $entityId,
		public readonly int $actorId,
		public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
	) {}
}
```

> **`occurredAt`**: Never pass this argument explicitly — it defaults to `new \DateTimeImmutable()` (current time). There is no `timestamp` parameter. Passing `timestamp: time()` is a compile error.

### Service-Specific Events

```php
namespace phpbb\threads\Event;

use phpbb\common\Event\DomainEvent;

final readonly class TopicCreatedEvent extends DomainEvent
{
	public function __construct(
		int $entityId,
		public int $forumId,
		int $actorId,
	) {
		parent::__construct($entityId, $actorId);
	}
}
```

---

## Naming Convention

```
{Entity}{Action}Event
```

| Action | Examples |
|--------|----------|
| Created | `TopicCreatedEvent`, `PostCreatedEvent`, `MessageSentEvent` |
| Updated | `TopicUpdatedEvent`, `PostEditedEvent`, `ForumRenamedEvent` |
| Deleted | `TopicDeletedEvent`, `PostDeletedEvent`, `ConversationArchivedEvent` |
| State change | `TopicLockedEvent`, `TopicPinnedEvent`, `UserBannedEvent` |

---

## Dispatch Responsibility

```
Service Layer          Controller Layer           Event Subscribers
───────────────        ─────────────────          ─────────────────
createTopic()    →     $events = $service->       $dispatcher->dispatch($events)
returns events         createTopic(...)                │
                                                      ├──► NotificationSubscriber
                                                      ├──► ForumStatsSubscriber
                                                      └──► SearchIndexSubscriber
```

**Why controllers dispatch**: Services stay pure and testable. Event dispatch happens after the HTTP response is committed (or in the same request cycle, before response).

---

## Minimum Event Payload

Every `DomainEvent` MUST include:

| Field | Type | Description |
|-------|------|-------------|
| `entityId` | `int` | Primary key of affected entity |
| `actorId` | `int` | User who triggered the action |
| `occurredAt` | `DateTimeImmutable` | Timestamp of the event (auto-set — never pass explicitly) |

> Do **not** pass `occurredAt` or `timestamp` manually. The default `new \DateTimeImmutable()` is always correct.

Additional fields are service-specific (e.g., `forumId` on `TopicCreatedEvent`).

---

## DomainEventCollection

The actual class has **no** `add()`, `merge()`, `count()`, or `isEmpty()` methods. The constructor **requires** the events array — there is no default.

```php
namespace phpbb\common\Event;

final class DomainEventCollection implements \IteratorAggregate
{
	public function __construct(private readonly array $events)
	{
	}

	public function dispatch(\Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher): void { ... }

	public function getIterator(): \ArrayIterator
	{
		return new \ArrayIterator($this->events);
	}

	public function all(): array
	{
		return $this->events;
	}

	public function first(): ?DomainEvent
	{
		return $this->events[0] ?? null;
	}
}
```

### Anti-Pattern: Mutable Accumulator

```php
// ❌ WRONG — DomainEventCollection has no add() method and requires array in constructor
$events = new DomainEventCollection();        // ERROR: Expected 1 argument, found 0
$events->add(new ConversationCreatedEvent(    // ERROR: Undefined method 'add'
	entityId: $id,
	actorId: $userId,
	timestamp: time(),                        // ERROR: Unknown named argument $timestamp
));
return $events;

// ✅ CORRECT — inline array, no mutation, occurredAt is auto-set
return new DomainEventCollection([
	new ConversationCreatedEvent(entityId: $id, actorId: $userId),
]);
```

### Empty Collection (Idempotent Cases)

When an operation has nothing to report (e.g. early return on idempotent path), return an empty collection — never `null`.

```php
// ✅ For methods that may return no events (idempotent cases)
return new DomainEventCollection([]);
```

---

## Event Inheritance Rule

All service-specific domain events MUST extend `phpbb\common\Event\DomainEvent`. This ensures:
- Consistent base payload (`entityId`, `actorId`, `occurredAt`)
- Type safety in `DomainEventCollection` (accepts only `DomainEvent` instances)
- Uniform serialization for event logs / audit trail

```php
// ✅ CORRECT — extends DomainEvent
namespace phpbb\messaging\Event;

use phpbb\common\Event\DomainEvent;

final readonly class MessageSentEvent extends DomainEvent
{
	public function __construct(
		int $entityId,          // message_id
		int $actorId,           // sender_id
		public int $conversationId,
		public array $recipientIds,
	) {
		parent::__construct($entityId, $actorId);
	}
}
```

```php
// ❌ WRONG — standalone event without base class
final readonly class MessageSentEvent
{
	public function __construct(
		public int $messageId,
		public int $senderId,
	) {}
}
```

---

## Return Type: When to Use Individual Events vs DomainEventCollection

**Default**: All mutation methods return `DomainEventCollection`.

**Exception**: A method MAY return a single typed event (`TopicCreatedEvent`) when ALL of these hold:
1. The method always produces exactly ONE event (never zero, never multiple)
2. The caller needs typed access to event payload (e.g., `$event->topicId` for redirect)
3. The event still extends `DomainEvent` (satisfies inheritance rule)
4. The controller wraps it in `DomainEventCollection` before dispatch

When in doubt, return `DomainEventCollection` — it's always safe.

```php
// Acceptable — single typed return with DomainEvent base
public function createTopic(CreateTopicRequest $req): TopicCreatedEvent;
// Controller: $dispatcher->dispatch(new DomainEventCollection([$event]));

// Preferred — collection return (always safe)
public function createTopic(CreateTopicRequest $req): DomainEventCollection;
// Controller: $dispatcher->dispatch($events);
```

---

## Anti-Patterns

- **DO NOT** dispatch events inside service methods — services return events only
- **DO NOT** use events for queries or data fetching — events are for side effects
- **DO NOT** put business logic in event subscribers that should be in the service
- **DO NOT** create events for internal state changes that no external consumer needs
