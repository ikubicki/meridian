# M11a Plugin System — Codebase Analysis

## 1. Injection Points

### ThreadsService::createPost / updatePost
**File**: `src/phpbb/threads/ThreadsService.php`

```php
public function createPost(CreatePostRequest $request): DomainEventCollection
    // content from $request->content → passed raw to $postRepository->insert()

public function updatePost(UpdatePostRequest $request): DomainEventCollection  
    // content from $request->content → passed raw to $postRepository->updateContent()
```

- **PRE_SAVE injection**: Call `$this->pipeline->processForSave($content, $ctx)` BEFORE `$postRepository->insert()`
- Search indexer is called AFTER insert — will index **processed** content (desired behavior)

### PostsController::postToArray
**File**: `src/phpbb/api/Controller/PostsController.php`

```php
private function postToArray(PostDTO $dto): array
{
    return [
        'id'             => $dto->id,
        'topicId'        => $dto->topicId,
        'forumId'        => $dto->forumId,
        'authorId'       => $dto->authorId,
        'authorUsername' => $dto->authorUsername,
        'content'        => $dto->content,   // ← inject PRE_OUTPUT here
        'createdAt'      => $dto->createdAt,
    ];
}
```

- **PRE_OUTPUT injection**: Replace `$dto->content` with `$pipeline->processForOutput($dto->content, $ctx)`

## 2. AutoconfigureTag / AutowireIterator Status

- **Status**: NOT USED anywhere in codebase
- `autoconfigure: true` and `autowire: true` are globally set in `services.yaml`
- Current plugin pattern: event-dispatch (`TypeRegistry` + `RegisterNotificationTypesEvent`)
- M11a will introduce first use of `#[AutoconfigureTag]` + `#[AutowireIterator]`

## 3. Symfony Messenger

- **Status**: NOT INSTALLED
- No `symfony/messenger` in `composer.json`
- No `messenger:` key in any config file
- **Decision**: Defer async MediaPlugin to M11b — install Messenger in dedicated step or use sync-only for M11a

## 4. ConfigService Pattern 

```yaml
# services.yaml
phpbb\config\Contract\ConfigServiceInterface:
    alias: phpbb\config\Service\ConfigService
    public: true
```

Pattern: Interface → Service aliases, `public: true` for API controllers.
Update-then-insert upsert with `UniqueConstraintViolationException` catch.

## 5. Metadata Column Status

- **None of the 5 target tables have `metadata` column**
- Migration needed: `ALTER TABLE ... ADD COLUMN metadata MEDIUMTEXT NULL`
- Tables: `phpbb_posts`, `phpbb_topics`, `phpbb_forums`, `phpbb_users`, `phpbb_attachments`

## 6. src/phpbb Directory Structure

```
src/phpbb/
├── Kernel.php
├── api/          (REST API — Controller, DTO, EventSubscriber)
├── auth/         (M3 — Service, Contract, Repository)
├── cache/        (Cache layer — CachePool, backends, marshallers)
├── common/       (Shared domain events)
├── config/       (M13 — Service, Contract, packages/, services.yaml)
├── db/           (DBAL factory + exceptions)
├── hierarchy/    (M7 — Service, Contract, Repository, Plugin)
├── messaging/    (M9 — Service, Contract)
├── migrations/   (SQL migration files)
├── notifications/ (M8 — Method, Service, Contract, Type, Repository, DTO, Event, Listener, Entity, TypeRegistry)
├── search/       (M10 — Contract, Service, Index)
├── storage/      (M12 — Service, Contract)
├── threads/      (M6 — Service, Contract, Repository, DTO, Entity, Event)
└── user/         (M2 — Service, Contract, Repository, DTO, Entity)
```

**New module path**: `src/phpbb/content/` following M6-style structure.

## 7. services.yaml

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false
```

- `autoconfigure: true` enables `#[AutoconfigureTag]` auto-registration
- Tagged iterators (`#[AutowireIterator]`) work automatically

## 8. Test Pattern

```php
namespace phpbb\Tests\[module]\[subdomain];

class ServiceNameTest extends TestCase
{
    private SomeInterface&MockObject $dep;
    private ServiceName $sut;

    protected function setUp(): void
    {
        $this->dep = $this->createMock(SomeInterface::class);
        $this->sut = new ServiceName($this->dep);
    }

    #[Test]
    public function methodNameDescribesExpectedBehavior(): void
    {
        // arrange → act → assert
    }
}
```

- Namespace: `phpbb\Tests\[module]\[subdomain]`
- Test class named: `{ServiceName}Test`
- Use `#[Test]` attribute (PHPUnit 10)
- Mock via `createMock()` + `->method()` chain

## 9. Risk Register

| ID | Risk | Severity | Mitigation |
|----|------|----------|-----------|
| R1 | Messenger not installed | HIGH | Defer async to M11b; implement sync-only MediaPipeline for M11a |
| R2 | No existing AutoconfigureTag pattern | MEDIUM | Document new pattern in STANDARDS.md after M11a |
| R3 | ThreadsService constructor needs pipeline | MEDIUM | Add `PostContentPipelineInterface` to constructor |
| R4 | Search indexer must index processed content | MEDIUM | Pipeline runs before insert → indexer gets processed content (✅) |
| R5 | ConfigTextService missing | LOW | Create as part of M11a |
| R6 | postToArray needs pipeline dep | LOW | Inject pipeline into PostsController |

## 10. Decisions Made

- **Messenger async**: Defer fully to M11b. MediaPipeline in M11a dispatches synchronously.
- **Content format**: No `content_format` DB column — `canProcess()` self-selection per ADR-005.
- **ConfigTextService**: Create simple service mirroring ConfigService pattern.
- **metadata column**: 5 × ALTER TABLE migration files for phpbb_posts, topics, forums, users, attachments.
