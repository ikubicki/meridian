# Codebase Analysis — M5b Storage Service

## Summary

The codebase does NOT contain any `src/phpbb/storage/` module. All patterns must be modelled
after `src/phpbb/messaging/` (M7 — most recent complete service implementation).

---

## Existing Patterns (reference: messaging/)

### Entity
```php
final readonly class Conversation
{
    public function __construct(
        public int $id,
        public string $participantHash,
        ...
    ) {}
}
```
- `final readonly class` with typed constructor
- No `fromRow()` on entity itself — repository hydrates via private `hydrate()` method
- No setters — immutable value objects

### Repository
- Extends nothing — plain class implementing a `Contract/` interface
- Constructor: `public function __construct(private readonly Connection $connection)`
- `const TABLE = 'phpbb_...'`
- All queries via DBAL4 prepared statements (`:param` named params)
- Throws `phpbb\db\Exception\RepositoryException` on DBAL failures
- Private `hydrate(array $row): Entity` method for entity construction
- Pagination via `PaginationContext` + `PaginatedResult` from `phpbb\common\`

### Service / Facade
- Constructor injects repositories + `Connection` (for transactions)
- Returns `DomainEventCollection` from mutating operations
- `$connection->beginTransaction()` / `commit()` / `rollBack()` for multi-step ops

### Controller (REST)
- Namespace: `phpbb\api\Controller\`
- Symfony route attributes: `#[Route('/path', name: 'api_v1_...', methods: ['GET'])]`
- User from request: `$request->attributes->get('_api_user')`
- HTTP status codes: 200 list, 201 created, 204 no-content, 400 validation, 404 not-found
- Dispatches events: `$events->dispatch($this->dispatcher)`

### DomainEvent
```php
abstract readonly class DomainEvent
{
    public function __construct(
        public readonly int $entityId,   // <-- to be changed to string|int
        public readonly int $actorId,
        public readonly \DateTimeImmutable $occurredAt = new \DateTimeImmutable(),
    ) {}
}
```
**Breaking change needed**: `entityId: int` → `string|int` to support UUID-keyed events.

### DI Configuration (services.yaml)
```yaml
phpbb\some\Repository\DbalFooRepository:
    arguments:
        $connection: '@Doctrine\DBAL\Connection'

phpbb\some\Contract\FooRepositoryInterface:
    alias: phpbb\some\Repository\DbalFooRepository

phpbb\some\Service\FooService:
    arguments:
        $repository: '@phpbb\some\Contract\FooRepositoryInterface'
        ...

phpbb\some\Contract\FooServiceInterface:
    alias: phpbb\some\Service\FooService
    public: true
```

### Routes (routes.yaml)
Single attribute-based entry covers all controllers in `phpbb\api\Controller\`:
```yaml
controllers:
    resource:
        path: '../api/Controller/'
        namespace: phpbb\api\Controller
    type: attribute
    prefix: /api/v1
```
No per-controller routing config needed.

### Tests
- **Unit**: Mocked dependencies, extends `TestCase`, `#[Test]` attribute
- **Integration**: In-memory SQLite (`IntegrationTestCase`), sets up schema in `setUpSchema()`
- **Convention**: `tests/phpbb/[module]/[Layer]/[ClassName]Test.php`

---

## Dependencies

| Dependency | Status | Notes |
|------------|--------|-------|
| doctrine/dbal ^4.0 | ✅ present | Used by all repositories |
| symfony/event-dispatcher ^8.0 | ✅ present | Event dispatch in controllers |
| league/flysystem | ❌ missing | Must add to composer.json |

---

## Existing Modules (confirmation)
```
src/phpbb/
├── api/          ✅
├── auth/         ✅
├── cache/        ✅
├── common/       ✅
├── config/       ✅
├── db/           ✅
├── hierarchy/    ✅
├── messaging/    ✅
├── threads/      ✅
├── user/         ✅
└── storage/      ❌ — does NOT exist (to be created)
```

---

## Risk Assessment

- **Risk Level**: Medium-High
- **Reasons**:
  - Breaking change to `DomainEvent::entityId` (affects all existing modules)
  - New external dependency (league/flysystem)
  - UUID v7 generation with `random_bytes()` (no library UUIDs)
  - File serving security (X-Accel-Redirect for private files)
  - DB migration for `phpbb_stored_files` + `phpbb_storage_quotas` tables
