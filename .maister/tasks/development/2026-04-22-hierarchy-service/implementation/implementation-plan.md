# Implementation Plan: `phpbb\hierarchy` Service

## Overview

| Attribute | Value |
|---|---|
| Total Groups | 7 (A–G) |
| Total Steps | ~60 |
| Expected Tests | 48–58 total (2–8 per group + up to 10 in review group) |
| Has Testing Review Group | Yes (Group G includes test review) |
| Min Tests Required | 40 (per spec section 19) |

## Dependency Order

```
A (Common Events)
  └── B (Entity + DTOs + Enums)
        └── C (ForumRepository)
              ├── D (Plugin System)
              └── E (TreeService)
                    └── F (TrackingService + SubscriptionService)
                          └── G (HierarchyService + REST API)
```

---

## Group A: Prerequisites — Common Domain Events

### Dependencies
None.

### Files to Create

| File | Namespace |
|---|---|
| `src/phpbb/common/Event/DomainEvent.php` | `phpbb\common\Event` |
| `src/phpbb/common/Event/DomainEventCollection.php` | `phpbb\common\Event` |
| `tests/phpbb/common/Event/DomainEventCollectionTest.php` | `phpbb\Tests\common\Event` |

### Files to Modify
None.

### Implementation Notes

**`DomainEvent`** — abstract readonly base. All hierarchy domain events extend this.

```php
abstract readonly class DomainEvent
{
	public function __construct(
		public readonly int $entityId,
		public readonly int $actorId,
		public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
	) {
	}
}
```

**`DomainEventCollection`** — wraps `DomainEvent[]`, implements `\IteratorAggregate`. Never registered as a DI service — always instantiated with `new`.

```php
final class DomainEventCollection implements \IteratorAggregate
{
	public function __construct(private readonly array $events) {}

	public function dispatch(\Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher): void
	{
		foreach ($this->events as $event) {
			$dispatcher->dispatch($event);
		}
	}

	public function getIterator(): \ArrayIterator { return new \ArrayIterator($this->events); }
	public function all(): array { return $this->events; }
	public function first(): ?DomainEvent { return $this->events[0] ?? null; }
}
```

- File header: GPL-2.0 block + `declare(strict_types=1)` + no closing PHP tag + tab indentation.
- `DomainEventCollection` is NOT listed in `services.yaml` (I-10 FIX — it is a value object, not a service).

### Tests Required

**`DomainEventCollectionTest`** — extends `PHPUnit\Framework\TestCase`.

- [ ] `testDispatch_callsDispatcherForEachEvent` — create 2 stub events, assert `dispatch()` called twice on mock dispatcher.
- [ ] `testFirst_returnsFirstEvent` — collection of 2 events returns first correctly.
- [ ] `testFirst_emptyCollection_returnsNull` — empty collection returns null.
- [ ] `testAll_returnsAllEvents` — `all()` returns original array.
- [ ] `testGetIterator_isIterable` — can iterate over events with `foreach`.

### Acceptance Criteria

- [ ] `DomainEvent` is `abstract readonly` with three constructor-promoted properties.
- [ ] `DomainEventCollection` implements `\IteratorAggregate`.
- [ ] `dispatch()` calls `$dispatcher->dispatch($event)` for each event in order.
- [ ] `DomainEventCollection` is NOT in `services.yaml`.
- [ ] 5 tests pass (`DomainEventCollectionTest`).
- [ ] `composer test` passes after this group.

---

## Group B: Forum Entity + DTOs + Enums

### Dependencies
Group A must be complete.

### Files to Create

| File | Namespace |
|---|---|
| `src/phpbb/hierarchy/Entity/ForumType.php` | `phpbb\hierarchy\Entity` |
| `src/phpbb/hierarchy/Entity/ForumStatus.php` | `phpbb\hierarchy\Entity` |
| `src/phpbb/hierarchy/Entity/ForumStats.php` | `phpbb\hierarchy\Entity` |
| `src/phpbb/hierarchy/Entity/ForumLastPost.php` | `phpbb\hierarchy\Entity` |
| `src/phpbb/hierarchy/Entity/ForumPruneSettings.php` | `phpbb\hierarchy\Entity` |
| `src/phpbb/hierarchy/Entity/Forum.php` | `phpbb\hierarchy\Entity` |
| `src/phpbb/hierarchy/DTO/ForumDTO.php` | `phpbb\hierarchy\DTO` |
| `src/phpbb/hierarchy/DTO/CreateForumRequest.php` | `phpbb\hierarchy\DTO` |
| `src/phpbb/hierarchy/DTO/UpdateForumRequest.php` | `phpbb\hierarchy\DTO` |
| `tests/phpbb/hierarchy/Entity/ForumTest.php` | `phpbb\Tests\hierarchy\Entity` |

### Files to Modify
None.

### Implementation Notes

**`ForumType` enum** (backed int):
```php
enum ForumType: int
{
	case Category = 0;
	case Forum    = 1;
	case Link     = 2;
}
```

**`ForumStatus` enum** (backed int):
```php
enum ForumStatus: int
{
	case Unlocked = 0;
	case Locked   = 1;
}
```

**`ForumStats`** — `final readonly class`, no methods except computed:
- Properties: `postsApproved`, `postsUnapproved`, `postsSoftdeleted`, `topicsApproved`, `topicsUnapproved`, `topicsSoftdeleted` (all `int`).
- `totalPosts(): int` → sum of three post counters.
- `totalTopics(): int` → sum of three topic counters.

**`ForumLastPost`** — `final readonly class`:
- Properties: `postId`, `posterId` (int), `subject`, `posterName`, `posterColour` (string), `time` (int).

**`ForumPruneSettings`** — `final readonly class`:
- Properties: `enabled` (bool), `days`, `viewed`, `frequency`, `next` (all int).

**`Forum` entity** — `final readonly class`, 30 constructor-promoted properties.

All properties match the DB column mapping from spec section 4. Full property list (in constructor order):

```
int $id, string $name, string $description, string $descriptionBitfield,
int $descriptionOptions, string $descriptionUid, int $parentId, int $leftId,
int $rightId, ForumType $type, ForumStatus $status, string $image, string $rules,
string $rulesLink, string $rulesBitfield, int $rulesOptions, string $rulesUid,
string $link, string $password, int $style, int $topicsPerPage, int $flags,
int $options, bool $displayOnIndex, bool $displaySubforumList, bool $enableIndexing,
bool $enableIcons, ForumStats $stats, ForumLastPost $lastPost,
ForumPruneSettings $pruneSettings, array $parents
```

Derived (computed) methods — NO DB access, pure math on properties:
- `isLeaf(): bool` → `$this->rightId - $this->leftId === 1`
- `descendantCount(): int` → `(int)(($this->rightId - $this->leftId - 1) / 2)`
- `isCategory(): bool` → `$this->type === ForumType::Category`
- `isForum(): bool` → `$this->type === ForumType::Forum`
- `isLink(): bool` → `$this->type === ForumType::Link`

**`ForumDTO`** — `final readonly class`:
```
int $id, string $name, string $description, int $parentId, int $type, int $status,
int $leftId, int $rightId, bool $displayOnIndex, int $topicsApproved,
int $postsApproved, int $lastPostId, int $lastPostTime, string $lastPosterName,
string $link, array $parents
```
Named constructor: `static fromEntity(Forum $forum): self` — map `type->value`, `status->value`, stats fields, lastPost fields directly.

**`CreateForumRequest`** — NOT readonly (needs `withExtra()` which clones and mutates `$extra`):
- Constructor-promoted readonly properties: `name`, `type` (ForumType), `parentId=0`, `actorId=0`, `description=''`, `link=''`, `image=''`, `rules=''`, `rulesLink=''`, `password=''`, `style=0`, `topicsPerPage=0`, `flags=32`, `displayOnIndex=true`, `displaySubforumList=true`, `enableIndexing=true`, `enableIcons=false`.
- `private array $extra = []` (non-promoted).
- `withExtra(string $key, mixed $value): static` — clone self and set `$this->extra[$key] = $value`.
- `getExtra(string $key, mixed $default = null): mixed`.
- `getAllExtra(): array`.

**`UpdateForumRequest`** — all nullable except `$forumId` and `$actorId`:
- `int $forumId`, `int $actorId = 0`, then all optional: `?string $name`, `?ForumType $type`, `?int $parentId`, `?string $description`, `?string $link`, `?string $image`, `?string $rules`, `?string $rulesLink`, `?string $password`, `?bool $clearPassword`, `?int $style`, `?int $topicsPerPage`, `?int $flags`, `?bool $displayOnIndex`, `?bool $displaySubforumList`, `?bool $enableIndexing`, `?bool $enableIcons`.
- Same `$extra` pattern with `withExtra()`/`getExtra()`/`getAllExtra()`.

### Tests Required

**`ForumTest`** — extends `PHPUnit\Framework\TestCase`. Use a helper `makeMinimalForum(array $overrides = [])` that builds a `Forum` with safe defaults.

- [ ] `testIsLeaf_leafNode_returnsTrue` — forum with `right_id = left_id + 1` returns `true`.
- [ ] `testIsLeaf_nonLeafNode_returnsFalse` — forum with children returns `false`.
- [ ] `testDescendantCount_twoChildren_returnsTwo` — `left=1, right=6` → `descendantCount() === 2`.
- [ ] `testDescendantCount_leafNode_returnsZero` — leaf returns 0.
- [ ] `testIsCategory_categoryType_returnsTrue` — `ForumType::Category` → `isCategory() === true`, others false.
- [ ] `testIsForum_forumType_returnsTrue` — `ForumType::Forum` → `isForum() === true`.
- [ ] `testIsLink_linkType_returnsTrue` — `ForumType::Link` → `isLink() === true`.
- [ ] `testForumStats_totalPosts_sumsAllCounters` — `ForumStats::totalPosts()` sums all three counters.

### Acceptance Criteria

- [ ] All enums have correct backing values (`Category=0, Forum=1, Link=2`, `Unlocked=0, Locked=1`).
- [ ] `Forum` is `final readonly` with 31 constructor properties.
- [ ] All five derived methods (`isLeaf`, `descendantCount`, `isCategory`, `isForum`, `isLink`) implemented with correct formulas.
- [ ] `ForumDTO::fromEntity()` maps `type->value` and `status->value` (not enum objects).
- [ ] `CreateForumRequest::withExtra()` returns a clone, not mutation of original.
- [ ] 8 tests pass (`ForumTest`).
- [ ] `composer test` passes after this group.

---

## Group C: ForumRepository (DBAL 4)

### Dependencies
Group B must be complete.

### Files to Create

| File | Namespace |
|---|---|
| `src/phpbb/hierarchy/Contract/ForumRepositoryInterface.php` | `phpbb\hierarchy\Contract` |
| `src/phpbb/hierarchy/Repository/DbalForumRepository.php` | `phpbb\hierarchy\Repository` |
| `tests/phpbb/hierarchy/Repository/DbalForumRepositoryTest.php` | `phpbb\Tests\hierarchy\Repository` |

### Files to Modify
None (services.yaml updated in Group G).

### Implementation Notes

**`ForumRepositoryInterface`** — full method contract from spec section 6 plus I-05 and I-07 corrections:

```php
interface ForumRepositoryInterface
{
	public function findById(int $id): ?Forum;
	public function findAll(): array;                     // array<int, Forum> keyed by forum_id
	public function findChildren(int $parentId): array;  // array<int, Forum> keyed by forum_id
	public function insertRaw(CreateForumRequest $request): int;
	public function update(UpdateForumRequest $request): Forum;
	public function delete(int $forumId): void;
	public function updateTreePosition(int $forumId, int $leftId, int $rightId, int $parentId): void;
	public function shiftLeftIds(int $threshold, int $delta): void;
	public function shiftRightIds(int $threshold, int $delta): void;
	public function updateParentId(int $forumId, int $parentId): void;  // I-05 FIX
	public function clearParentsCache(int $forumId): void;               // I-07 FIX
}
```

**`DbalForumRepository`** — `private const TABLE = 'phpbb_forums'`, constructor injects `\Doctrine\DBAL\Connection`.

**Pattern rules (apply to every method):**
1. DBAL named params: array keys WITHOUT `:` prefix (e.g. `['id' => $val]` not `[':id' => $val]`).
2. Every public method body: `try { ... } catch (\Doctrine\DBAL\Exception $e) { throw new RepositoryException('...', previous: $e); }`.
3. Exception class: `phpbb\db\Exception\RepositoryException`.

**`findById(int $id): ?Forum`**:
```sql
SELECT * FROM phpbb_forums WHERE forum_id = :id LIMIT 1
```
`fetchAssociative()` → `$row !== false ? $this->hydrate($row) : null`.

**`findAll(): array`**:
```sql
SELECT * FROM phpbb_forums ORDER BY left_id ASC
```
`fetchAllAssociative()` → map via `hydrate()`, key result by `$entity->id`.

**`findChildren(int $parentId): array`**:
```sql
SELECT * FROM phpbb_forums WHERE parent_id = :parentId ORDER BY left_id ASC
```
Returns keyed array by `forum_id`.

**`insertRaw(CreateForumRequest $request): int`** — I-04 FIX applies: `forum_parents` literal value is `'[]'` (JSON empty array), NOT `''`:

```sql
INSERT INTO phpbb_forums
    (forum_name, forum_type, forum_desc, forum_link, forum_status, parent_id,
     display_on_index, display_subforum_list, enable_indexing, enable_icons,
     forum_style, forum_image, forum_rules, forum_rules_link, forum_password,
     forum_topics_per_page, forum_flags, forum_parents, left_id, right_id,
     forum_posts_approved, forum_posts_unapproved, forum_posts_softdeleted,
     forum_topics_approved, forum_topics_unapproved, forum_topics_softdeleted,
     forum_last_post_id, forum_last_poster_id, forum_last_post_subject,
     forum_last_post_time, forum_last_poster_name, forum_last_poster_colour,
     prune_next, prune_days, prune_viewed, prune_freq, enable_prune)
VALUES
    (:forumName, :forumType, :forumDesc, :forumLink, :forumStatus, :parentId,
     :displayOnIndex, :displaySubforumList, :enableIndexing, :enableIcons,
     :forumStyle, :forumImage, :forumRules, :forumRulesLink, :forumPassword,
     :topicsPerPage, :forumFlags, '[]', 0, 0,
     0, 0, 0, 0, 0, 0,
     0, 0, '', 0, '', '',
     0, 0, 0, 0, 0)
```

Param array keys: `forumName`, `forumType` (→ `$request->type->value`), `forumDesc`, `forumLink`, `forumStatus` (→ `ForumStatus::Unlocked->value`), `parentId`, `displayOnIndex` (→ `(int) $request->displayOnIndex`), `displaySubforumList`, `enableIndexing`, `enableIcons`, `forumStyle` (→ `$request->style`), `forumImage`, `forumRules`, `forumRulesLink`, `forumPassword`, `topicsPerPage`, `forumFlags`.

Returns: `(int) $this->connection->lastInsertId()`.

**`update(UpdateForumRequest $request): Forum`** — dynamic SET clause:
- Build `$sets = []` / `$params = ['forumId' => $request->forumId]`.
- For each nullable property in request (except `forumId`, `actorId`): if not null, append to `$sets` and `$params`.
- Special: `$request->type !== null` → `'forum_type = :forumType'` with `$params['forumType'] = $request->type->value`.
- Special: `$request->clearPassword === true` → `'forum_password = :forumPassword'` with `$params['forumPassword'] = ''`.
- Execute: `UPDATE phpbb_forums SET {implode(', ', $sets)} WHERE forum_id = :forumId`.
- After update, reload: `return $this->findById($request->forumId) ?? throw new \InvalidArgumentException(...)`.

**`delete(int $forumId): void`**:
```sql
DELETE FROM phpbb_forums WHERE forum_id = :forumId
```

**`updateTreePosition(int $forumId, int $leftId, int $rightId, int $parentId): void`**:
```sql
UPDATE phpbb_forums
SET left_id = :leftId, right_id = :rightId, parent_id = :parentId
WHERE forum_id = :forumId
```

**`shiftLeftIds(int $threshold, int $delta): void`**:
```sql
UPDATE phpbb_forums SET left_id = left_id + :delta WHERE left_id >= :threshold
```

**`shiftRightIds(int $threshold, int $delta): void`**:
```sql
UPDATE phpbb_forums SET right_id = right_id + :delta WHERE right_id >= :threshold
```

**`updateParentId(int $forumId, int $parentId): void`** (I-05 FIX):
```sql
UPDATE phpbb_forums SET parent_id = :parentId WHERE forum_id = :forumId
```

**`clearParentsCache(int $forumId): void`** (I-07 FIX):
```sql
UPDATE phpbb_forums SET forum_parents = '[]' WHERE forum_id = :forumId
```

**`private hydrate(array $row): Forum`** — construct Forum with named arguments. IMPORTANT: reads `forum_options` from `(int) $row['forum_options']`. Full mapping:

```php
return new Forum(
	id:                  (int) $row['forum_id'],
	name:                $row['forum_name'],
	description:         $row['forum_desc'],
	descriptionBitfield: $row['forum_desc_bitfield'],
	descriptionOptions:  (int) $row['forum_desc_options'],
	descriptionUid:      $row['forum_desc_uid'],
	parentId:            (int) $row['parent_id'],
	leftId:              (int) $row['left_id'],
	rightId:             (int) $row['right_id'],
	type:                ForumType::from((int) $row['forum_type']),
	status:              ForumStatus::from((int) $row['forum_status']),
	image:               $row['forum_image'],
	rules:               $row['forum_rules'],
	rulesLink:           $row['forum_rules_link'],
	rulesBitfield:       $row['forum_rules_bitfield'],
	rulesOptions:        (int) $row['forum_rules_options'],
	rulesUid:            $row['forum_rules_uid'],
	link:                $row['forum_link'],
	password:            $row['forum_password'],
	style:               (int) $row['forum_style'],
	topicsPerPage:       (int) $row['forum_topics_per_page'],
	flags:               (int) $row['forum_flags'],
	options:             (int) $row['forum_options'],
	displayOnIndex:      (bool) $row['display_on_index'],
	displaySubforumList: (bool) $row['display_subforum_list'],
	enableIndexing:      (bool) $row['enable_indexing'],
	enableIcons:         (bool) $row['enable_icons'],
	stats: new ForumStats(
		postsApproved:     (int) $row['forum_posts_approved'],
		postsUnapproved:   (int) $row['forum_posts_unapproved'],
		postsSoftdeleted:  (int) $row['forum_posts_softdeleted'],
		topicsApproved:    (int) $row['forum_topics_approved'],
		topicsUnapproved:  (int) $row['forum_topics_unapproved'],
		topicsSoftdeleted: (int) $row['forum_topics_softdeleted'],
	),
	lastPost: new ForumLastPost(
		postId:       (int) $row['forum_last_post_id'],
		posterId:     (int) $row['forum_last_poster_id'],
		subject:      $row['forum_last_post_subject'],
		time:         (int) $row['forum_last_post_time'],
		posterName:   $row['forum_last_poster_name'],
		posterColour: $row['forum_last_poster_colour'],
	),
	pruneSettings: new ForumPruneSettings(
		enabled:   (bool) $row['enable_prune'],
		days:      (int) $row['prune_days'],
		viewed:    (int) $row['prune_viewed'],
		frequency: (int) $row['prune_freq'],
		next:      (int) $row['prune_next'],
	),
	parents: $this->decodeParents($row['forum_parents']),
);
```

**`private decodeParents(string $raw): array`**:
```php
if ($raw === '' || $raw === '[]') return [];
$decoded = json_decode($raw, true);
if (is_array($decoded)) return $decoded;
$unserialized = @unserialize($raw);
return is_array($unserialized) ? $unserialized : [];
```

### Tests Required

**`DbalForumRepositoryTest`** — extends `phpbb\Tests\Integration\IntegrationTestCase`.

`setUpSchema()` creates `phpbb_forums` table with FULL DDL from spec section 16 (authoritative — all 45 columns). Then instantiates `$this->repository = new DbalForumRepository($this->connection)`.

Helper: `private function insertForum(array $overrides = []): int` — merges defaults (all required columns) with overrides, uses `$this->connection->executeStatement(...)`, returns `(int) $this->connection->lastInsertId()`.

- [ ] `testFindById_found_returnsHydratedForum` — insert one row, findById returns Forum with correct name, type enum, status enum.
- [ ] `testFindById_notFound_returnsNull` — findById(9999) returns null.
- [ ] `testFindAll_returnsAllForumsOrderedByLeftId` — insert 3 forums with different left_ids, findAll returns all 3 keyed by forum_id in left_id ASC order.
- [ ] `testFindChildren_returnsDirectChildrenOnly` — insert parent + 2 children + 1 grandchild; findChildren(parentId) returns exactly 2.
- [ ] `testFindChildren_emptyResult_returnsEmptyArray` — findChildren on childless forum returns `[]`.
- [ ] `testInsertRaw_persistsAllFields` — insertRaw returns >0 ID; findById confirms name, type, description stored correctly.
- [ ] `testInsertRaw_setsTreePositionToZero` — after insertRaw, findById shows `leftId===0` and `rightId===0`.
- [ ] `testInsertRaw_forumParentsInitialValueIsJsonArray` — after insertRaw, `forum_parents` column contains `'[]'` (I-04 FIX check).
- [ ] `testUpdate_changesOnlyNonNullFields` — insertRaw then update with only `name` set; confirm name changed, description unchanged.
- [ ] `testDelete_removesRow` — insertRaw then delete; findById returns null.
- [ ] `testShiftLeftIds_shiftsCorrectRows` — insert 3 rows with left_ids 1,3,5; shiftLeftIds(3, 2); confirm rows with left_id ≥ 3 shifted, row with left_id=1 unchanged.
- [ ] `testDecodeParents_jsonFormat_parsesCorrectly` — insert forum with `forum_parents = '{"1":{"forum_name":"Root"}}'`; hydrated entity has non-empty parents array.

### Acceptance Criteria

- [ ] `ForumRepositoryInterface` declares all 11 methods (including `updateParentId` and `clearParentsCache`).
- [ ] Every public method in `DbalForumRepository` has `try/catch (\Doctrine\DBAL\Exception $e)` wrapping.
- [ ] All DBAL param arrays use keys WITHOUT `:` prefix.
- [ ] `insertRaw` writes `'[]'` (not `''`) for `forum_parents`.
- [ ] `hydrate()` reads `forum_options` from row and passes it to `Forum` constructor.
- [ ] `ForumType::from()` and `ForumStatus::from()` used in `hydrate()` — no raw int comparisons.
- [ ] 12 tests pass (`DbalForumRepositoryTest`).
- [ ] `composer test` passes after this group.

---

## Group D: Plugin System + Behavior Registry + Domain Events

### Dependencies
Group B must be complete (uses `ForumType` enum and `CreateForumRequest`/`UpdateForumRequest`).

### Files to Create

| File | Namespace |
|---|---|
| `src/phpbb/hierarchy/Plugin/ForumTypeBehaviorInterface.php` | `phpbb\hierarchy\Plugin` |
| `src/phpbb/hierarchy/Plugin/CategoryBehavior.php` | `phpbb\hierarchy\Plugin` |
| `src/phpbb/hierarchy/Plugin/ForumBehavior.php` | `phpbb\hierarchy\Plugin` |
| `src/phpbb/hierarchy/Plugin/LinkBehavior.php` | `phpbb\hierarchy\Plugin` |
| `src/phpbb/hierarchy/Plugin/RequestDecoratorInterface.php` | `phpbb\hierarchy\Plugin` |
| `src/phpbb/hierarchy/Plugin/ResponseDecoratorInterface.php` | `phpbb\hierarchy\Plugin` |
| `src/phpbb/hierarchy/Plugin/ForumTypeRegistry.php` | `phpbb\hierarchy\Plugin` |
| `src/phpbb/hierarchy/Event/ForumCreatedEvent.php` | `phpbb\hierarchy\Event` |
| `src/phpbb/hierarchy/Event/ForumUpdatedEvent.php` | `phpbb\hierarchy\Event` |
| `src/phpbb/hierarchy/Event/ForumDeletedEvent.php` | `phpbb\hierarchy\Event` |
| `src/phpbb/hierarchy/Event/ForumMovedEvent.php` | `phpbb\hierarchy\Event` |
| `src/phpbb/hierarchy/Event/RegisterForumTypesEvent.php` | `phpbb\hierarchy\Event` |

### Files to Modify
None.

### Implementation Notes

**`ForumTypeBehaviorInterface`**:
```php
interface ForumTypeBehaviorInterface
{
	public function canHaveContent(): bool;
	public function canHaveChildren(): bool;
	public function requiresLink(): bool;
	public function getEditableFields(): array;   // string[]
	public function validate(CreateForumRequest|UpdateForumRequest $request): array; // string[] of errors
}
```

**Behavior implementations** (behaviors matrix from spec section 8):

| Method | `CategoryBehavior` | `ForumBehavior` | `LinkBehavior` |
|---|---|---|---|
| `canHaveContent()` | `false` | `true` | `false` |
| `canHaveChildren()` | `true` | `true` | `false` |
| `requiresLink()` | `false` | `false` | `true` |
| `getEditableFields()` | basic fields | all fields | link fields |
| `validate()` | check no link set | return [] | check link not empty |

**`RequestDecoratorInterface`** (I-08 FIX):
```php
interface RequestDecoratorInterface
{
	public function supports(CreateForumRequest|UpdateForumRequest $request): bool;
	public function decorateRequest(CreateForumRequest|UpdateForumRequest $request): CreateForumRequest|UpdateForumRequest;
}
```

**`ResponseDecoratorInterface`** (I-08 FIX):
```php
interface ResponseDecoratorInterface
{
	public function supports(ForumDTO $dto): bool;
	public function decorateResponse(ForumDTO $dto): ForumDTO;
}
```

**`RegisterForumTypesEvent`** — NOT a `DomainEvent` (no parent class). Boot-time registry event:
```php
final class RegisterForumTypesEvent
{
	private array $registrations = [];

	public function register(ForumType|int $type, ForumTypeBehaviorInterface $behavior): void
	{
		$key = $type instanceof ForumType ? $type->value : $type;
		$this->registrations[$key] = $behavior;
	}

	public function getRegistrations(): array { return $this->registrations; }
}
```

**`ForumTypeRegistry`** — lazy-initializer, dispatches `RegisterForumTypesEvent`:
```php
final class ForumTypeRegistry
{
	private ?array $behaviors = null;

	public function __construct(private readonly \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher) {}

	public function getBehavior(ForumType $type): ForumTypeBehaviorInterface
	{
		if ($this->behaviors === null) {
			$this->initialize();
		}
		return $this->behaviors[$type->value]
			?? throw new \InvalidArgumentException("No behavior registered for forum type {$type->value}");
	}

	private function initialize(): void
	{
		// Register built-ins
		$this->behaviors = [
			ForumType::Category->value => new CategoryBehavior(),
			ForumType::Forum->value    => new ForumBehavior(),
			ForumType::Link->value     => new LinkBehavior(),
		];

		// Fire event for plugins
		$event = new RegisterForumTypesEvent();
		$this->dispatcher->dispatch($event);
		foreach ($event->getRegistrations() as $typeValue => $behavior) {
			$this->behaviors[$typeValue] = $behavior;
		}
	}
}
```

**Domain Events** — all `final readonly` extending `phpbb\common\Event\DomainEvent`:

`ForumCreatedEvent`:
```php
final readonly class ForumCreatedEvent extends DomainEvent
{
	public Forum $forum;
	public ?int $parentId;

	public function __construct(Forum $forum, int $actorId = 0)
	{
		parent::__construct($forum->id, $actorId);
		$this->forum = $forum;
		$this->parentId = $forum->parentId;
	}
}
```

`ForumUpdatedEvent`:
- Extra props: `Forum $forum`, `array $changedFields`.
- Constructor: `__construct(Forum $forum, array $changedFields, int $actorId = 0)`.
- Calls `parent::__construct($forum->id, $actorId)`.

`ForumDeletedEvent`:
- Extra props: `int $forumId`, `int $parentId`.
- Constructor: `__construct(int $forumId, int $parentId, int $actorId = 0)`.
- Calls `parent::__construct($forumId, $actorId)`.

`ForumMovedEvent`:
- Extra props: `Forum $forum`, `int $oldParentId`.
- Constructor: `__construct(Forum $forum, int $oldParentId, int $actorId = 0)`.
- Calls `parent::__construct($forum->id, $actorId)`.

### Tests Required

- [ ] `testGetBehavior_categoryType_returnsCategoryBehavior` — registry returns CategoryBehavior for Category type.
- [ ] `testGetBehavior_unknownType_throwsInvalidArgumentException` — invalid type value throws.
- [ ] `testCategoryBehavior_canHaveChildren_returnsTrue` — direct unit test on behavior.
- [ ] `testLinkBehavior_requiresLink_returnsTrue` — LinkBehavior returns true for requiresLink.
- [ ] `testRegisterForumTypesEvent_register_storesRegistration` — event accumulates registrations.

### Acceptance Criteria

- [ ] All four domain events extend `phpbb\common\Event\DomainEvent`.
- [ ] `RegisterForumTypesEvent` does NOT extend `DomainEvent`.
- [ ] `ForumTypeRegistry::getBehavior()` lazy-initializes (dispatches event on first call only).
- [ ] Built-in behaviors (Category, Forum, Link) registered automatically in `initialize()`.
- [ ] `RequestDecoratorInterface` and `ResponseDecoratorInterface` exist in `Plugin/` namespace.
- [ ] 5 tests pass.
- [ ] `composer test` passes after this group.

---

## Group E: TreeService (Nested Set)

### Dependencies
Groups B, C must be complete (`Forum` entity, `ForumRepositoryInterface`, `DbalForumRepository`).

### Files to Create

| File | Namespace |
|---|---|
| `src/phpbb/hierarchy/Contract/TreeServiceInterface.php` | `phpbb\hierarchy\Contract` |
| `src/phpbb/hierarchy/Service/TreeService.php` | `phpbb\hierarchy\Service` |
| `tests/phpbb/hierarchy/Service/TreeServiceTest.php` | `phpbb\Tests\hierarchy\Service` |

### Files to Modify
None.

### Implementation Notes

**`TreeServiceInterface`** — full method contract from spec section 6:
```php
interface TreeServiceInterface
{
	public function getSubtree(?int $rootId): array;      // array<int, Forum>
	public function getPath(int $forumId): array;         // array<int, Forum>
	public function insertAtPosition(int $forumId, int $parentId): void;
	public function removeNode(int $forumId): void;
	public function moveNode(int $forumId, int $newParentId): void;
	public function rebuildTree(): void;
}
```

**`TreeService`** constructor:
```php
public function __construct(
	private readonly ForumRepositoryInterface $repository,
	private readonly \Doctrine\DBAL\Connection $connection,
) {}
```

**`insertAtPosition(int $forumId, int $parentId): void`** — inside `$this->connection->transactional()`:

```
1. SELECT forum_id, left_id, right_id FROM phpbb_forums WHERE forum_id = :parentId FOR UPDATE
   → If not found: throw \InvalidArgumentException("Parent forum {$parentId} not found")
2. $insertPos = $parent['right_id']
3. $this->repository->shiftLeftIds(threshold: $insertPos, delta: 2)
4. $this->repository->shiftRightIds(threshold: $insertPos, delta: 2)
5. $this->repository->updateTreePosition(
       forumId: $forumId,
       leftId:  $insertPos,
       rightId: $insertPos + 1,
       parentId: $parentId,
   )
```

Note on `parentId=0` (root insert): insert without locking (no parent row). If `$parentId === 0`:
- Get max right_id from all forums to determine `$insertPos`.
- SQL: `SELECT COALESCE(MAX(right_id), 0) FROM phpbb_forums` → `$insertPos = $maxRight + 1`.
- Set leftId = `$insertPos`, rightId = `$insertPos + 1`.
- No shift needed (appending to end).
- Call `updateTreePosition($forumId, $insertPos, $insertPos + 1, 0)`.

**`removeNode(int $forumId): void`** — inside `$this->connection->transactional()`:

```
1. SELECT left_id, right_id FROM phpbb_forums WHERE forum_id = :forumId FOR UPDATE
   → If not found: throw \InvalidArgumentException
2. $width = $node['right_id'] - $node['left_id'] + 1
3. Zero-out subtree rows (mark for deletion):
   UPDATE phpbb_forums SET left_id = 0, right_id = 0
   WHERE left_id >= :leftId AND right_id <= :rightId
   (params: leftId => $node['left_id'], rightId => $node['right_id'])
4. $this->repository->shiftLeftIds(threshold: $node['right_id'] + 1, delta: -$width)
5. $this->repository->shiftRightIds(threshold: $node['right_id'] + 1, delta: -$width)
```

**`moveNode(int $forumId, int $newParentId): void`** — inside `$this->connection->transactional()`.

9-step algorithm (from spec section 10, with I-05 FIX for Step F):

```
Step A: Fetch node FOR UPDATE. Fetch new parent FOR UPDATE.
        Guard: if newParentId is within node subtree (newParent.left_id >= node.left_id AND newParent.right_id <= node.right_id):
               throw \InvalidArgumentException("Cannot move forum into its own subtree")
        $size = $node['right_id'] - $node['left_id'] + 1

Step B: Mark subtree as negative to exclude from shifts:
        UPDATE phpbb_forums
        SET left_id = left_id * -1, right_id = right_id * -1
        WHERE left_id >= :leftId AND right_id <= :rightId
        (params: leftId => $node['left_id'], rightId => $node['right_id'])

Step C: Close gap at old position:
        $this->repository->shiftLeftIds(threshold: $node['right_id'] + 1, delta: -$size)
        $this->repository->shiftRightIds(threshold: $node['right_id'] + 1, delta: -$size)
        (shiftLeftIds/shiftRightIds only affect rows WHERE left_id >= threshold — negative rows are excluded)

Step D: Re-fetch new parent (its position may have shifted):
        SELECT left_id, right_id FROM phpbb_forums WHERE forum_id = :newParentId

Step E: Open gap at new position ($insertPos = $newParent['right_id']):
        $this->repository->shiftLeftIds(threshold: $insertPos, delta: $size)
        $this->repository->shiftRightIds(threshold: $insertPos, delta: $size)

Step F: Calculate placement offset:
        $offset = $insertPos - ((-1) * $node['left_id'])
          => i.e. $offset = $insertPos + $node['left_id']

Step G: Place subtree (negate negative values and apply offset):
        UPDATE phpbb_forums
        SET left_id  = (left_id  * -1) + :offset,
            right_id = (right_id * -1) + :offset
        WHERE left_id < 0
        (params: offset => $offset)

Step H: Update parent_id of moved root only (I-05 FIX — use updateParentId not updateTreePosition):
        $this->repository->updateParentId(forumId: $forumId, parentId: $newParentId)

Step I: (done — tree is consistent)
```

**`getSubtree(?int $rootId): array`** — direct DBAL queries (not through repository interface):

```php
if ($rootId === null) {
    $rows = $this->connection->executeQuery('SELECT * FROM phpbb_forums ORDER BY left_id ASC')->fetchAllAssociative();
} else {
    $root = $this->connection->executeQuery(
        'SELECT left_id, right_id FROM phpbb_forums WHERE forum_id = :rootId',
        ['rootId' => $rootId]
    )->fetchAssociative();
    if ($root === false) return [];
    $rows = $this->connection->executeQuery(
        'SELECT * FROM phpbb_forums WHERE left_id >= :leftId AND right_id <= :rightId ORDER BY left_id ASC',
        ['leftId' => $root['left_id'], 'rightId' => $root['right_id']]
    )->fetchAllAssociative();
}
// hydrate via $this->repository... but repository hydrate() is private.
// Solution: use a separate DbalForumRepository->findById approach OR
// instantiate Forum directly in TreeService with inline hydration helper.
// Preferred: inject DbalForumRepository (concrete) so we can call findAll with the right WHERE.
// Alternative: TreeService fetches raw rows and delegates hydration via a public hydrate helper OR
// define a separate `hydrateRow(array $row): Forum` method on the repository interface.
// DECISION: Add hydration via a helper method in TreeService that duplicates the repository hydration.
// This avoids coupling TreeService to DbalForumRepository directly.
// Cleanest for phase 1: instantiate Forum directly in TreeService using the same column mapping.
// This means TreeService has its own private hydrateRow(array $row): Forum method.
```

> **Implementation decision**: `TreeService` has a `private function hydrateRow(array $row): Forum` that mirrors `DbalForumRepository::hydrate()`. Accept the duplication in phase 1. A shared hydration utility can be extracted in phase 2.

**`getPath(int $forumId): array`**:

```php
$node = $this->connection->executeQuery(
	'SELECT left_id, right_id FROM phpbb_forums WHERE forum_id = :forumId',
	['forumId' => $forumId]
)->fetchAssociative();
if ($node === false) return [];
$rows = $this->connection->executeQuery(
	'SELECT * FROM phpbb_forums WHERE left_id <= :nodeLeft AND right_id >= :nodeRight ORDER BY left_id ASC',
	['nodeLeft' => $node['left_id'], 'nodeRight' => $node['right_id']]
)->fetchAllAssociative();
// hydrate each row, key by forum_id
```

**`rebuildTree(): void`** — recursive PHP rebuild:

```php
// Fetch all forums grouped by parent_id
// Recursive function assigns left/right starting from 1
// Issues UPDATE for each node after computing its left/right
// O(3n) individual UPDATEs — acceptable for phase 1
```

Wrap the entire rebuild in a transaction.

### Tests Required

**`TreeServiceTest`** — extends `phpbb\Tests\Integration\IntegrationTestCase`. Uses same DDL as `DbalForumRepositoryTest`. Creates both `DbalForumRepository` and `TreeService` instances pointing at same `$this->connection`.

Helper: `private function insertForum(array $overrides = []): int` (same as Repository test).
Helper: `private function getRow(int $forumId): array` — fetches a single row as assoc array.

- [ ] `testInsertAtPosition_rootForum_getsLeftId1RightId2` — insert one forum with parentId=0; getRow shows `left_id=1, right_id=2`.
- [ ] `testInsertAtPosition_childForum_parentRightIdExpands` — insert parent (left=1,right=2), then insert child under it; parent now has `right_id=4`, child has `left_id=2, right_id=3`.
- [ ] `testInsertAtPosition_twoSiblings_consecutiveLeftRightIds` — two siblings under same parent get non-overlapping consecutive ranges.
- [ ] `testInsertAtPosition_parentNotFound_throwsInvalidArgumentException` — passing non-existent parentId throws `\InvalidArgumentException`.
- [ ] `testGetSubtree_nullRoot_returnsAllForumsInDfsOrder` — 3-node tree returned ordered by left_id.
- [ ] `testGetPath_returnsAncestorChainFromRootToNode` — 3-level hierarchy; getPath(deepest) returns [root, parent, deepest] in order.
- [ ] `testRemoveNode_closesGapInTree` — insert root+child; removeNode(child); root now has `left_id=1, right_id=2` (gap closed).
- [ ] `testMoveNode_reordersTreeCorrectly` — 3-forum tree; move one node under another; resulting left/right are consistent.

### Acceptance Criteria

- [ ] `TreeServiceInterface` declares all 6 methods.
- [ ] `insertAtPosition` handles root (parentId=0) and child positions.
- [ ] `removeNode` zeros out moved node's left/right before shifting others.
- [ ] `moveNode` uses negative-marking pattern (Steps B→G) to prevent shift conflicts.
- [ ] `moveNode` calls `repository->updateParentId()` (I-05 FIX) for parent update, NOT `updateTreePosition()`.
- [ ] `getSubtree` and `getPath` use direct DBAL queries.
- [ ] 8 tests pass (`TreeServiceTest`).
- [ ] `composer test` passes after this group.

---

## Group F: TrackingService + SubscriptionService

### Dependencies
Group A must be complete (`DomainEvent`/`DomainEventCollection` namespace present). No dependency on Groups B–E (these services use their own tables).

### Files to Create

| File | Namespace |
|---|---|
| `src/phpbb/hierarchy/Contract/TrackingServiceInterface.php` | `phpbb\hierarchy\Contract` |
| `src/phpbb/hierarchy/Service/TrackingService.php` | `phpbb\hierarchy\Service` |
| `src/phpbb/hierarchy/Contract/SubscriptionServiceInterface.php` | `phpbb\hierarchy\Contract` |
| `src/phpbb/hierarchy/Service/SubscriptionService.php` | `phpbb\hierarchy\Service` |
| `tests/phpbb/hierarchy/Service/TrackingServiceTest.php` | `phpbb\Tests\hierarchy\Service` |
| `tests/phpbb/hierarchy/Service/SubscriptionServiceTest.php` | `phpbb\Tests\hierarchy\Service` |

### Files to Modify
None.

### Implementation Notes

**`TrackingServiceInterface`**:
```php
interface TrackingServiceInterface
{
	public function markRead(int $userId, int $forumId): void;
	public function markAllRead(int $userId): void;
	public function isUnread(int $userId, int $forumId): bool;
	public function getUnreadStatus(int $userId, array $forumIds): array; // array<int, bool>
}
```

**`TrackingService`** — `private const TABLE = 'phpbb_forums_track'`, constructor `__construct(private readonly \Doctrine\DBAL\Connection $connection)`.

`markRead(int $userId, int $forumId): void` — platform-switched upsert:

```php
$platform = $this->connection->getDatabasePlatform();
$markTime = time();
if ($platform instanceof \Doctrine\DBAL\Platforms\MySQLPlatform) {
    $this->connection->executeStatement(
        'INSERT INTO phpbb_forums_track (user_id, forum_id, mark_time)
         VALUES (:userId, :forumId, :markTime)
         ON DUPLICATE KEY UPDATE mark_time = :markTime',
        ['userId' => $userId, 'forumId' => $forumId, 'markTime' => $markTime],
    );
} else {
    $this->connection->transactional(function (\Doctrine\DBAL\Connection $conn) use ($userId, $forumId, $markTime): void {
        $conn->executeStatement(
            'DELETE FROM phpbb_forums_track WHERE user_id = :userId AND forum_id = :forumId',
            ['userId' => $userId, 'forumId' => $forumId],
        );
        $conn->executeStatement(
            'INSERT INTO phpbb_forums_track (user_id, forum_id, mark_time) VALUES (:userId, :forumId, :markTime)',
            ['userId' => $userId, 'forumId' => $forumId, 'markTime' => $markTime],
        );
    });
}
```

`markAllRead(int $userId): void`:
```sql
DELETE FROM phpbb_forums_track WHERE user_id = :userId
```
(Phase 1 simplification — deletes all tracking rows for user.)

`isUnread(int $userId, int $forumId): bool`:
```sql
SELECT mark_time FROM phpbb_forums_track WHERE user_id = :userId AND forum_id = :forumId LIMIT 1
```
Returns `true` if `$row === false` (no tracking row = unread).

`getUnreadStatus(int $userId, array $forumIds): array`:
```php
use Doctrine\DBAL\ArrayParameterType;

$readRows = $this->connection->executeQuery(
    'SELECT forum_id FROM phpbb_forums_track WHERE user_id = :userId AND forum_id IN (?)',
    [$userId, $forumIds],
    [\Doctrine\DBAL\ParameterType::INTEGER, ArrayParameterType::INTEGER],
)->fetchFirstColumn();
$readSet = array_flip($readRows);
$result = [];
foreach ($forumIds as $id) {
    $result[$id] = !isset($readSet[$id]);
}
return $result;
```

Wrap all public methods in `try/catch (\Doctrine\DBAL\Exception $e)`.

---

**`SubscriptionServiceInterface`** (I-12 FIX — one `subscribe`, not two):
```php
interface SubscriptionServiceInterface
{
	public function subscribe(int $userId, int $forumId): void;
	public function unsubscribe(int $userId, int $forumId): void;
	public function isSubscribed(int $userId, int $forumId): bool;
	public function getSubscribers(int $forumId): array; // int[]
}
```

**`SubscriptionService`** — `private const TABLE = 'phpbb_forums_watch'`, constructor `__construct(private readonly \Doctrine\DBAL\Connection $connection)`.

`subscribe(int $userId, int $forumId): void` — platform-switched upsert:

MySQLPlatform:
```sql
INSERT INTO phpbb_forums_watch (forum_id, user_id, notify_status)
VALUES (:forumId, :userId, 1)
ON DUPLICATE KEY UPDATE notify_status = 1
```

Other (SQLite): DELETE + INSERT inside `transactional()`.

`unsubscribe(int $userId, int $forumId): void`:
```sql
DELETE FROM phpbb_forums_watch WHERE forum_id = :forumId AND user_id = :userId
```
Silent if no row (no affected-rows check needed).

`isSubscribed(int $userId, int $forumId): bool`:
```sql
SELECT notify_status FROM phpbb_forums_watch WHERE forum_id = :forumId AND user_id = :userId LIMIT 1
```
Returns `$row !== false`.

`getSubscribers(int $forumId): array`:
```sql
SELECT user_id FROM phpbb_forums_watch WHERE forum_id = :forumId AND notify_status = 1
```
Returns `fetchFirstColumn()` cast to `int[]`.

Wrap all public methods in `try/catch (\Doctrine\DBAL\Exception $e)`.

### Tests Required

**`TrackingServiceTest`** — `phpbb\Tests\Integration\IntegrationTestCase`. `setUpSchema()`:
```sql
CREATE TABLE phpbb_forums_track (
    user_id   INTEGER NOT NULL,
    forum_id  INTEGER NOT NULL,
    mark_time INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, forum_id)
)
```

- [ ] `testMarkRead_insertsTrackingRow` — markRead; check row exists in DB with mark_time > 0.
- [ ] `testMarkRead_idempotent_updatesExistingRow` — markRead twice; exactly 1 row in DB (no duplicate).
- [ ] `testIsUnread_noRow_returnsTrue` — no rows; isUnread returns true.
- [ ] `testIsUnread_afterMarkRead_returnsFalse` — markRead then isUnread returns false.
- [ ] `testGetUnreadStatus_mixedResult_correctBooleanMap` — 3 forums, 1 marked; getUnreadStatus returns correct bool per forum.
- [ ] `testMarkAllRead_deletesAllUserRows` — markRead 3 forums; markAllRead; all 3 rows gone.

**`SubscriptionServiceTest`** — `phpbb\Tests\Integration\IntegrationTestCase`. `setUpSchema()` (I-03 FIX — UNIQUE constraint):
```sql
CREATE TABLE phpbb_forums_watch (
    forum_id      INTEGER NOT NULL,
    user_id       INTEGER NOT NULL,
    notify_status INTEGER NOT NULL DEFAULT 0,
    UNIQUE(forum_id, user_id)
)
```

- [ ] `testSubscribe_insertsWatchRow` — subscribe; row exists with notify_status=1.
- [ ] `testSubscribe_idempotent_noError` — subscribe twice; exactly 1 row (no constraint violation).
- [ ] `testUnsubscribe_removesRow` — subscribe then unsubscribe; isSubscribed returns false.
- [ ] `testUnsubscribe_nonExistent_silentSuccess` — unsubscribe on non-existent row; no exception thrown.
- [ ] `testIsSubscribed_afterSubscribe_returnsTrue` — isSubscribed returns true after subscribe.
- [ ] `testGetSubscribers_returnsOnlyNotifyStatusOne` — insert rows with notify_status=0 and 1; getSubscribers returns only the notify_status=1 user.

### Acceptance Criteria

- [ ] `TrackingService::markRead` platform-switches between MySQL INSERT ON DUPLICATE KEY and SQLite DELETE+INSERT.
- [ ] `SubscriptionService::subscribe` platform-switches identically.
- [ ] `getUnreadStatus` uses `ArrayParameterType::INTEGER` for IN clause.
- [ ] `phpbb_forums_watch` DDL includes `UNIQUE(forum_id, user_id)` in test schema (I-03 FIX).
- [ ] Both interfaces have exactly the specified methods (no duplicate `subscribe` — I-12 FIX).
- [ ] All public methods have `try/catch (\Doctrine\DBAL\Exception $e)` wrapping.
- [ ] 12 tests pass (6 tracking + 6 subscription).
- [ ] `composer test` passes after this group.

---

## Group G: HierarchyService Facade + REST API + DI Wiring

### Dependencies
All previous groups (A–F) must be complete.

### Files to Create

| File | Namespace |
|---|---|
| `src/phpbb/hierarchy/Contract/HierarchyServiceInterface.php` | `phpbb\hierarchy\Contract` |
| `src/phpbb/hierarchy/Contract/SubscriptionServiceInterface.php` | Already created in F |
| `src/phpbb/hierarchy/Service/HierarchyService.php` | `phpbb\hierarchy\Service` |
| `tests/phpbb/hierarchy/Service/HierarchyServiceTest.php` | `phpbb\Tests\hierarchy\Service` |

### Files to Modify

| File | Change |
|---|---|
| `src/phpbb/api/Controller/ForumsController.php` | Replace mock with full HierarchyService integration |
| `src/phpbb/config/services.yaml` | Add hierarchy module block |

### Implementation Notes

**`HierarchyServiceInterface`**:
```php
interface HierarchyServiceInterface
{
	public function listForums(?int $parentId = null): array;           // array<int, ForumDTO>
	public function getForum(int $id): ?ForumDTO;
	public function getTree(?int $rootId = null): array;                // array<int, ForumDTO>
	public function getPath(int $id): array;                            // array<int, ForumDTO>
	public function createForum(CreateForumRequest $request): DomainEventCollection;
	public function updateForum(UpdateForumRequest $request): DomainEventCollection;
	public function deleteForum(int $forumId, int $actorId = 0): DomainEventCollection;
	public function moveForum(int $forumId, int $newParentId, int $actorId = 0): DomainEventCollection;
}
```

**`HierarchyService`** constructor:
```php
public function __construct(
	private readonly ForumRepositoryInterface $repository,
	private readonly TreeServiceInterface $treeService,
	private readonly \Doctrine\DBAL\Connection $connection,
	private readonly ForumTypeRegistry $typeRegistry,
	private readonly array $requestDecorators = [],    // RequestDecoratorInterface[]
	private readonly array $responseDecorators = [],   // ResponseDecoratorInterface[]
) {}
```

Note: `EventDispatcherInterface` is NOT injected — per DOMAIN_EVENTS.md, dispatching is the controller's responsibility.

**`listForums(?int $parentId = null): array`** — C-03 FIX:
```php
$forums = $this->repository->findChildren($parentId ?? 0);
$dtos = array_map(fn(Forum $f) => ForumDTO::fromEntity($f), $forums);
// Apply response decorators to each DTO
return $this->applyResponseDecorators($dtos);
```
`findChildren(0)` returns root-level forums (parent_id = 0). NOT `findAll()`.

**`getForum(int $id): ?ForumDTO`**:
```php
$forum = $this->repository->findById($id);
if ($forum === null) return null;
$dto = ForumDTO::fromEntity($forum);
foreach ($this->responseDecorators as $dec) {
    if ($dec->supports($dto)) {
        $dto = $dec->decorateResponse($dto);
    }
}
return $dto;
```

**`getTree(?int $rootId = null): array`**:
```php
$forums = $this->treeService->getSubtree($rootId);
$dtos = array_map(fn(Forum $f) => ForumDTO::fromEntity($f), $forums);
return $this->applyResponseDecorators($dtos);
```

**`getPath(int $id): array`**:
```php
$forums = $this->treeService->getPath($id);
$dtos = array_map(fn(Forum $f) => ForumDTO::fromEntity($f), $forums);
return $this->applyResponseDecorators($dtos);
```

**`createForum(CreateForumRequest $request): DomainEventCollection`**:
```php
// 1. Apply request decorators
foreach ($this->requestDecorators as $dec) {
    if ($dec->supports($request)) {
        $request = $dec->decorateRequest($request);
    }
}
// 2. Validate via type registry
$errors = $this->typeRegistry->getBehavior($request->type)->validate($request);
if (!empty($errors)) {
    throw new \InvalidArgumentException(implode(', ', $errors));
}
// 3. Transactional insert + position
$forum = null;
$this->connection->transactional(function () use ($request, &$forum): void {
    $forumId = $this->repository->insertRaw($request);
    $this->treeService->insertAtPosition($forumId, $request->parentId);
    $forum = $this->repository->findById($forumId);
});
return new DomainEventCollection([new ForumCreatedEvent($forum, $request->actorId)]);
```

**`updateForum(UpdateForumRequest $request): DomainEventCollection`**:
```php
foreach ($this->requestDecorators as $dec) {
    if ($dec->supports($request)) {
        $request = $dec->decorateRequest($request);
    }
}
$forum = $this->repository->update($request);
$changedFields = array_keys(array_filter([
    'name'        => $request->name !== null,
    'description' => $request->description !== null,
    // ... etc
]));
return new DomainEventCollection([new ForumUpdatedEvent($forum, $changedFields, $request->actorId)]);
```

**`deleteForum(int $forumId, int $actorId = 0): DomainEventCollection`** — C-01 FIX applies:
```php
// 1. Fetch forum to get parentId for event
$forum = $this->repository->findById($forumId);
if ($forum === null) {
    throw new \InvalidArgumentException("Forum {$forumId} not found");
}
// 2. Guard: C-01 FIX — reject non-leaf forums
$children = $this->repository->findChildren($forumId);
if (!empty($children)) {
    throw new \InvalidArgumentException(
        "Cannot delete forum {$forumId}: it has " . count($children) . " direct child forum(s). Move or delete children first."
    );
}
// 3. Remove from tree + delete row
$this->connection->transactional(function () use ($forumId): void {
    $this->treeService->removeNode($forumId);
    $this->repository->delete($forumId);
});
return new DomainEventCollection([new ForumDeletedEvent($forumId, $forum->parentId, $actorId)]);
```

**`moveForum(int $forumId, int $newParentId, int $actorId = 0): DomainEventCollection`** — I-07 FIX applies:
```php
$oldForum = $this->repository->findById($forumId);
if ($oldForum === null) {
    throw new \InvalidArgumentException("Forum {$forumId} not found");
}
$this->treeService->moveNode($forumId, $newParentId);

// I-07 FIX: Invalidate forum_parents cache for moved subtree
$subtree = $this->treeService->getSubtree($forumId);
foreach (array_keys($subtree) as $id) {
    $this->repository->clearParentsCache($id);
}

$forum = $this->repository->findById($forumId);
return new DomainEventCollection([new ForumMovedEvent($forum, $oldForum->parentId, $actorId)]);
```

Private helper `applyResponseDecorators(array $dtos): array` — applies each decorator to each DTO that is supported.

---

**`ForumsController`** — completely replace with:

```php
class ForumsController
{
	public function __construct(
		private readonly HierarchyServiceInterface $hierarchyService,
		private readonly \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher,
	) {}
```

Routes table (complete, including C-02 FIX — move route):

| Method | Path | Route name | Auth |
|---|---|---|---|
| GET | `/forums` | `api_v1_forums_index` | No |
| GET | `/forums/{forumId}` | `api_v1_forums_show` | No |
| POST | `/forums` | `api_v1_forums_create` | Yes (JWT + acp flag) |
| PATCH | `/forums/{forumId}` | `api_v1_forums_update` | Yes (JWT + acp flag) |
| DELETE | `/forums/{forumId}` | `api_v1_forums_delete` | Yes (JWT + acp flag) |
| GET | `/forums/{forumId}/children` | `api_v1_forums_children` | No |
| GET | `/forums/{forumId}/path` | `api_v1_forums_path` | No |
| PATCH | `/forums/{forumId}/move` | `api_v1_forums_move` | Yes (JWT + acp flag) |

Auth guard helper (C-04 FIX — correct 401/403 ordering):
```php
private function checkAuth(Request $request): ?JsonResponse
{
    $token = $request->attributes->get('_api_token');
    if ($token === null) {
        return new JsonResponse(['error' => 'Authentication required', 'status' => 401], 401);
    }
    if (!in_array('acp', $token->flags ?? [], true)) {
        return new JsonResponse(['error' => 'Insufficient permissions', 'status' => 403], 403);
    }
    return null;
}

private function getActorId(Request $request): int
{
    return $request->attributes->get('_api_token')?->userId ?? 0;
}
```

**`index()`** — calls `$this->hierarchyService->listForums()`, returns `{ "data": [...], "meta": { "total": N } }`.

**`show(int $forumId)`** — calls `getForum($forumId)`, returns 200 with `{"data": {...}}` or 404 with `{"error": "Forum not found", "status": 404}`.

**`create(Request $request)`**:
1. `checkAuth($request)` → early return if non-null.
2. `$body = json_decode($request->getContent(), true) ?? []`.
3. Validate: `name` required (string), `type` required (0, 1, or 2). Missing → 422 `{"errors": [...]}`.
4. Build `CreateForumRequest($body['name'], ForumType::from((int)$body['type']), parentId: (int)($body['parent_id'] ?? 0), actorId: $this->getActorId($request), ...)`.
5. Call `createForum()`, dispatch events.
6. Extract `$events->first()->forum->id`, call `getForum()`, return 201.
7. Catch `\InvalidArgumentException` → 422.

**`update(int $forumId, Request $request)`**:
1. `checkAuth($request)` → early return if non-null.
2. Parse body, build `UpdateForumRequest($forumId, actorId: ..., name: ..., ...)` from non-null body fields only.
3. Call `updateForum()`, dispatch events.
4. Return 200 with fresh DTO.
5. Catch `\InvalidArgumentException` → 404.

**`delete(int $forumId, Request $request)`**:
1. `checkAuth($request)` → early return if non-null.
2. Call `deleteForum($forumId, actorId)`, dispatch events.
3. Return 204 empty response.
4. Catch `\InvalidArgumentException` from children check (C-01 FIX) → 400.
5. Catch `\InvalidArgumentException` from not found → 404.

**`children(int $forumId)`** — calls `$this->hierarchyService->listForums($forumId)`, returns `{ "data": [...], "meta": { "total": N } }`.

**`path(int $forumId)`** — calls `$this->hierarchyService->getPath($forumId)`, returns `{ "data": [...], "meta": { "total": N } }`.

**`move(int $forumId, Request $request)`** (C-02 FIX):
1. `checkAuth($request)` → early return.
2. `$newParentId = (int)($body['new_parent_id'] ?? 0)`.
3. Call `moveForum($forumId, $newParentId, $actorId)`, dispatch events.
4. Return 200 with fresh DTO.
5. Catch `\InvalidArgumentException` → 404 or 422.

---

**`services.yaml`** — add hierarchy module block after auth module section (I-10 FIX: no DomainEventCollection entry):

```yaml
    # ---------------------------------------------------------------------------
    # Hierarchy module (M5)
    # ---------------------------------------------------------------------------

    phpbb\hierarchy\Repository\DbalForumRepository: ~

    phpbb\hierarchy\Contract\ForumRepositoryInterface:
        alias: phpbb\hierarchy\Repository\DbalForumRepository

    phpbb\hierarchy\Service\TreeService: ~

    phpbb\hierarchy\Contract\TreeServiceInterface:
        alias: phpbb\hierarchy\Service\TreeService

    phpbb\hierarchy\Service\TrackingService: ~

    phpbb\hierarchy\Contract\TrackingServiceInterface:
        alias: phpbb\hierarchy\Service\TrackingService

    phpbb\hierarchy\Service\SubscriptionService: ~

    phpbb\hierarchy\Contract\SubscriptionServiceInterface:
        alias: phpbb\hierarchy\Service\SubscriptionService

    phpbb\hierarchy\Plugin\ForumTypeRegistry: ~

    phpbb\hierarchy\Service\HierarchyService:
        arguments:
            $requestDecorators: []
            $responseDecorators: []

    phpbb\hierarchy\Contract\HierarchyServiceInterface:
        alias: phpbb\hierarchy\Service\HierarchyService
        public: true
```

Note: `Doctrine\DBAL\Connection` and `EventDispatcherInterface` are already auto-wired by Symfony. No explicit argument declarations needed for standard type-hinted constructor params.

### Tests Required

**`HierarchyServiceTest`** — `PHPUnit\Framework\TestCase` (no DB). All dependencies mocked.

Setup helper:
```php
private function makeService(array $overrides = []): HierarchyService
{
    $this->repository = $overrides['repository'] ?? $this->createMock(ForumRepositoryInterface::class);
    $this->treeService = $overrides['treeService'] ?? $this->createMock(TreeServiceInterface::class);
    $this->connection = $this->createMock(\Doctrine\DBAL\Connection::class);
    $this->connection->method('transactional')
        ->willReturnCallback(fn($cb) => $cb($this->connection));
    $this->registry = $this->createMock(ForumTypeRegistry::class);
    return new HierarchyService($this->repository, $this->treeService, $this->connection, $this->registry);
}
```

- [ ] `testGetForum_found_returnsMappedDto` — repository returns Forum; getForum returns ForumDTO with correct `id`.
- [ ] `testGetForum_notFound_returnsNull` — repository returns null; getForum returns null.
- [ ] `testListForums_noParentId_delegatesToFindChildrenZero` — listForums(null) calls `repository->findChildren(0)` (C-03 FIX — NOT `findAll()`).
- [ ] `testCreateForum_callsInsertRawThenInsertAtPosition` — verify `repository->insertRaw()` called once then `treeService->insertAtPosition()` called once.
- [ ] `testCreateForum_returnsCollectionWithCreatedEvent` — returned collection's `first()` is `ForumCreatedEvent`.
- [ ] `testUpdateForum_returnsCollectionWithUpdatedEvent` — returned collection's `first()` is `ForumUpdatedEvent`.
- [ ] `testDeleteForum_withChildren_throwsInvalidArgumentException` — repository->findChildren returns non-empty; deleteForum throws (C-01 FIX).
- [ ] `testDeleteForum_leaf_callsRemoveNodeAndDelete` — repository->findChildren returns `[]`; verify `treeService->removeNode()` then `repository->delete()` called.
- [ ] `testMoveForum_returnsCollectionWithMovedEvent` — returned collection's `first()` is `ForumMovedEvent`.

### Acceptance Criteria

- [ ] `HierarchyServiceInterface` declares 8 methods (4 read, 4 mutation).
- [ ] `listForums(null)` calls `findChildren(0)` NOT `findAll()` (C-03 FIX).
- [ ] `deleteForum` checks for children BEFORE removing from tree (C-01 FIX).
- [ ] All 4 mutation methods return `DomainEventCollection`.
- [ ] `ForumsController` has 8 routes (including `/move` — C-02 FIX).
- [ ] Auth guard checks token existence first (401), then acp flag (403) — in that order (C-04 FIX).
- [ ] `services.yaml` has all hierarchy entries; no `DomainEventCollection` entry (I-10 FIX); `HierarchyServiceInterface` alias is `public: true`.
- [ ] 9 tests pass (`HierarchyServiceTest`).
- [ ] `composer test` passes with minimum 40 total tests green.
- [ ] `composer test:e2e` passes (existing E2E tests not broken by controller changes).
- [ ] `composer cs:fix` produces no changes.

---

## Execution Order Summary

| # | Group | Steps | Key Deliverable |
|---|---|---|---|
| 1 | A: Common Events | ~8 | `DomainEvent` + `DomainEventCollection` base classes |
| 2 | B: Entity + DTOs | ~12 | `Forum` entity, 2 enums, 3 VOs, 3 DTOs |
| 3 | C: ForumRepository | ~15 | Full DBAL 4 CRUD with 11 repository methods |
| 4 | D: Plugin System | ~10 | 3 behaviors, registry, 4 domain events, 2 decorator interfaces |
| 5 | E: TreeService | ~12 | Nested set with 3 algorithms + traversal queries |
| 6 | F: Tracking + Subscription | ~10 | 2 services, platform-switched upserts |
| 7 | G: HierarchyService + API | ~15 | Facade, 8 REST routes, DI wiring |

## Standards Compliance

Follow standards from `.maister/docs/standards/`:

- **`global/STANDARDS.md`**: PascalCase class names, tab indentation, GPL-2.0 file header, no closing `?>` tag.
- **`backend/STANDARDS.md`**: `declare(strict_types=1)`, `readonly` constructor promotion, PHP 8.2 backed enums, named arguments, constructor-only DI, no `global`, single-quote strings.
- **`backend/REST_API.md`**: `JsonResponse`, `data` top-level key, correct HTTP codes (201, 204, 400, 401, 403, 404, 422), thin controllers, JWT via `_api_token` attribute.
- **`backend/DOMAIN_EVENTS.md`**: Mutations return `DomainEventCollection`; controllers call `$events->dispatch($dispatcher)`; services do NOT dispatch internally.
- **`testing/STANDARDS.md`**: `#[Test]` attribute (not `@test` annotation), `IntegrationTestCase` for all DB tests, `createMock()` only, AAA structure, `assertSame()` preferred over `assertEquals()`.

## Key Implementation Notes

1. **DBAL 4 named params**: array keys WITHOUT `:` prefix → `['id' => $val]` not `[':id' => $val]`.
2. **Platform-switched upsert**: `$conn->getDatabasePlatform() instanceof MySQLPlatform` → `ON DUPLICATE KEY UPDATE` / else `transactional(DELETE + INSERT)`.
3. **Exception wrapping**: every public method in repositories/services wrapped in `try {} catch (\Doctrine\DBAL\Exception $e) { throw new RepositoryException('...', previous: $e); }`.
4. **`IntegrationTestCase`**: extends for all repo/tree/tracking/subscription tests; `setUpSchema()` creates SQLite tables via `$this->connection->executeStatement(...)`.
5. **`transactional()` in unit tests**: `$connection->method('transactional')->willReturnCallback(fn($cb) => $cb($connection))`.
6. **`forum_parents` always JSON**: written as `'[]'` on insert (I-04), reset to `'[]'` by `clearParentsCache` (I-07).
7. **C-01 FIX**: `deleteForum` guards children FIRST, THEN removes from tree.
8. **C-03 FIX**: `listForums(null)` calls `findChildren(0)` — NOT `findAll()`.
9. **I-05 FIX**: `moveNode` Step H uses `updateParentId()` — a distinct repository method for setting parent_id only.
10. **I-10 FIX**: `DomainEventCollection` is NOT registered in `services.yaml` — it's a value object, always instantiated with `new`.
