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
		public int $entityId,
		public int $actorId,
		public \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
	) {}
}
```

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
| `occurredAt` | `DateTimeImmutable` | Timestamp of the event |

Additional fields are service-specific (e.g., `forumId` on `TopicCreatedEvent`).

---

## DomainEventCollection

```php
namespace phpbb\common\Event;

final class DomainEventCollection implements \IteratorAggregate, \Countable
{
	/** @param DomainEvent[] $events */
	public function __construct(
		private array $events = [],
	) {}

	public function add(DomainEvent $event): void
	{
		$this->events[] = $event;
	}

	public function merge(self $other): self
	{
		return new self([...$this->events, ...$other->events]);
	}

	public function getIterator(): \ArrayIterator
	{
		return new \ArrayIterator($this->events);
	}

	public function count(): int
	{
		return count($this->events);
	}

	public function isEmpty(): bool
	{
		return $this->events === [];
	}
}
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
