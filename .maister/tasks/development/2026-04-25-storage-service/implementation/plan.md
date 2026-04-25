# Implementation Plan: M5b Storage Service (`phpbb\storage`)

## Overview

| # | Task Group | Description | Blocked By |
|---|---|---|---|
| TG-1 | Breaking Change + Composer | Widen `DomainEvent::entityId` `int→string\|int`; `composer require league/flysystem:^3.0` | — |
| TG-2 | Enums + Exceptions | `AssetType`, `FileVisibility`, `VariantType`; 5 exception classes | TG-1 |
| TG-3 | Entities + DTOs | `StoredFile`, `StorageQuota`; 6 DTO classes | TG-2 |
| TG-4 | Contract Interfaces | 6 interfaces in `Contract/` | TG-3 |
| TG-5 | Domain Events | 7 event classes extending `DomainEvent` with `string` entityId | TG-1, TG-2 |
| TG-6 | Repositories + Adapter | `DbalStoredFileRepository`, `DbalStorageQuotaRepository`, `StorageAdapterFactory` | TG-3, TG-4 |
| TG-7 | Services | `QuotaService`, `OrphanService`, `UrlGenerator` | TG-5, TG-6 |
| TG-8 | StorageService Facade | Core orchestration, transactions, event dispatch | TG-7 |
| TG-9 | Variant System | `VariantGeneratorInterface`, `ThumbnailGenerator`, `ThumbnailListener` | TG-8 |
| TG-10 | Cron Jobs | `QuotaReconciliationJob`, `OrphanCleanupJob` | TG-7 |
| TG-11 | REST Controller | `FilesController` — `POST /api/v1/files` | TG-8 |
| TG-12 | DI Config + Migrations | `services.yaml` additions; SQL migration files | TG-6 through TG-11 |

**Total Task Groups:** 12  
**Expected Tests:** ~40–60 across all groups  
**Test files:**  
- `tests/phpbb/storage/Service/StorageServiceTest.php`  
- `tests/phpbb/storage/Service/OrphanServiceTest.php`  
- `tests/phpbb/storage/Quota/QuotaServiceTest.php`  
- `tests/phpbb/storage/Repository/DbalStoredFileRepositoryTest.php`  
- `tests/phpbb/storage/Repository/DbalStorageQuotaRepositoryTest.php`  
- `tests/phpbb/storage/Variant/ThumbnailListenerTest.php`  
- `tests/phpbb/api/Controller/FilesControllerTest.php`  

---

## Implementation Steps

---

### Task Group 1: Breaking Change + Composer
**Dependencies:** None  
**Estimated Steps:** 6

- [ ] 1.0 Complete breaking change and dependency addition
  - [ ] 1.1 Write 2 tests verifying `DomainEvent` accepts both `int` and `string` entityId
    - Test: existing subclass (`ConversationCreatedEvent`) still constructs with `int` entityId
    - Test: new `FileStoredEvent` stub constructs with `string` UUID hex entityId
  - [ ] 1.2 Modify `src/phpbb/common/Event/DomainEvent.php`
    - Change `public readonly int $entityId` → `public readonly string|int $entityId`
    - No other change to the file — constructor parameters and `$actorId` remain `int`
  - [ ] 1.3 Audit all 13 `extends DomainEvent` subclasses
    - Confirm none casts or type-checks `$entityId` as `int` in consuming code
    - Files: `src/phpbb/hierarchy/Event/*.php`, `src/phpbb/messaging/Event/*.php`, `src/phpbb/threads/Event/*.php`
    - Expected: zero changes needed (all pass `int` which satisfies `string|int`)
  - [ ] 1.4 Add `league/flysystem` to `composer.json`
    - Add `"league/flysystem": "^3.0"` to `require` block in `composer.json`
  - [ ] 1.5 Run `composer require league/flysystem:^3.0` inside Docker container
    - Command: `docker exec phpbb_app composer require league/flysystem:^3.0`
    - Verify `vendor/league/flysystem/` exists after install
  - [ ] 1.6 Run the 2 tests written in 1.1; confirm all existing `DomainEvent` tests still pass

**Acceptance Criteria:**
- `DomainEvent::entityId` is typed `string|int`
- All 13 existing event subclasses instantiate without modification
- `League\Flysystem\Filesystem` is importable in PHP

---

### Task Group 2: Enums + Exceptions
**Dependencies:** TG-1  
**Estimated Steps:** 12

- [ ] 2.0 Complete enums and exception classes
  - [ ] 2.1 Write 4 tests for enum behavior
    - Test: `AssetType::from('attachment')` returns `AssetType::Attachment`
    - Test: `AssetType::from('invalid')` throws `ValueError`
    - Test: `FileVisibility::from('public')` returns `FileVisibility::Public`
    - Test: `VariantType::from('thumbnail')` returns `VariantType::Thumbnail`
  - [ ] 2.2 Create `src/phpbb/storage/Enum/AssetType.php`
    - Backed enum `string`; cases: `Attachment = 'attachment'`, `Avatar = 'avatar'`, `Export = 'export'`
    - File header, `declare(strict_types=1)`, namespace `phpbb\storage\Enum`
  - [ ] 2.3 Create `src/phpbb/storage/Enum/FileVisibility.php`
    - Backed enum `string`; cases: `Public = 'public'`, `Private = 'private'`
  - [ ] 2.4 Create `src/phpbb/storage/Enum/VariantType.php`
    - Backed enum `string`; cases: `Thumbnail = 'thumbnail'`, `Webp = 'webp'`, `Medium = 'medium'`
  - [ ] 2.5 Create `src/phpbb/storage/Exception/FileNotFoundException.php`
    - `final class FileNotFoundException extends \RuntimeException`
    - No custom constructor — use default message parameter
  - [ ] 2.6 Create `src/phpbb/storage/Exception/QuotaExceededException.php`
    - `final class QuotaExceededException extends \RuntimeException`
  - [ ] 2.7 Create `src/phpbb/storage/Exception/UploadValidationException.php`
    - `final class UploadValidationException extends \RuntimeException`
  - [ ] 2.8 Create `src/phpbb/storage/Exception/OrphanClaimException.php`
    - `final class OrphanClaimException extends \RuntimeException`
  - [ ] 2.9 Create `src/phpbb/storage/Exception/StorageWriteException.php`
    - `final class StorageWriteException extends \RuntimeException`
  - [ ] 2.10 Verify `AssetType` → `FileVisibility` mapping logic (will be used in `StorageService`)
    - Inline mapping: `Avatar→Public`, `Attachment→Private`, `Export→Private`
    - Document this mapping in a comment inside `AssetType` enum (a `toVisibility()` method)
  - [ ] 2.11 Run only the 4 enum tests written in 2.1; ensure all pass

**Acceptance Criteria:**
- 3 backed `string` enums created in `src/phpbb/storage/Enum/`
- 5 exception classes created in `src/phpbb/storage/Exception/`
- `AssetType::toVisibility()` helper method resolves visibility per asset type
- All 4 enum tests pass

---

### Task Group 3: Entities + DTOs
**Dependencies:** TG-2  
**Estimated Steps:** 13

- [ ] 3.0 Complete entity and DTO classes
  - [ ] 3.1 Write 4 tests for entity construction and DTO hydration
    - Test: `StoredFile` constructs with all properties accessible as typed readonly
    - Test: `StorageQuota::isExceeded(int $additionalBytes)` returns correct boolean
    - Test: `StoreFileRequest` sets all fields correctly
    - Test: `ClaimContext` stores `string|int` entityId without type coercion
  - [ ] 3.2 Create `src/phpbb/storage/Entity/StoredFile.php`
    - `final readonly class StoredFile`
    - Constructor properties: `string $id` (UUID hex), `AssetType $assetType`, `FileVisibility $visibility`, `string $originalName`, `string $physicalName`, `string $mimeType`, `int $filesize`, `string $checksum` (SHA-256 hex), `bool $isOrphan`, `?string $parentId`, `?VariantType $variantType`, `int $uploaderId`, `int $forumId`, `int $createdAt`, `?int $claimedAt`
    - File header, `declare(strict_types=1)`, namespace `phpbb\storage\Entity`
  - [ ] 3.3 Create `src/phpbb/storage/Entity/StorageQuota.php`
    - `final readonly class StorageQuota`
    - Properties: `int $userId`, `int $forumId`, `int $usedBytes`, `int $maxBytes`, `int $updatedAt`
    - Method: `public function isExceeded(int $additionalBytes): bool` → `$this->usedBytes + $additionalBytes > $this->maxBytes`
  - [ ] 3.4 Create `src/phpbb/storage/DTO/StoreFileRequest.php`
    - `final readonly class StoreFileRequest`
    - Properties: `AssetType $assetType`, `int $uploaderId`, `int $forumId`, `string $tmpPath`, `string $originalName`, `string $mimeType`, `int $filesize`
  - [ ] 3.5 Create `src/phpbb/storage/DTO/FileStoredResponse.php`
    - `final readonly class FileStoredResponse`
    - Properties: `string $fileId` (UUID hex), `string $url`, `string $mimeType`, `int $filesize`
    - Method: `public function toArray(): array` returning `['file_id' => ..., 'url' => ..., 'mime_type' => ..., 'filesize' => ...]`
  - [ ] 3.6 Create `src/phpbb/storage/DTO/ClaimContext.php`
    - `final readonly class ClaimContext`
    - Properties: `string $fileId`, `int $actorId`, `string $entityType`, `string|int $entityId`
  - [ ] 3.7 Create `src/phpbb/storage/DTO/FileClaimedResponse.php`
    - `final readonly class FileClaimedResponse`
    - Properties: `string $fileId`, `int $claimedAt`
    - Method: `public function toArray(): array`
  - [ ] 3.8 Create `src/phpbb/storage/DTO/FileDeletedResponse.php`
    - `final readonly class FileDeletedResponse`
    - Properties: `string $fileId`, `int $actorId`, `int $deletedAt`
    - Method: `public function toArray(): array`
  - [ ] 3.9 Create `src/phpbb/storage/DTO/FileInfo.php`
    - `final readonly class FileInfo`
    - Full metadata: `string $fileId`, `AssetType $assetType`, `FileVisibility $visibility`, `string $originalName`, `string $url`, `string $mimeType`, `int $filesize`, `bool $isOrphan`, `int $createdAt`, `?int $claimedAt`
    - Method: `public function toArray(): array`
  - [ ] 3.10 Run only the 4 tests written in 3.1: all must pass

**Acceptance Criteria:**
- 2 entity classes in `src/phpbb/storage/Entity/`
- 6 DTO classes in `src/phpbb/storage/DTO/`
- All `toArray()` methods return keys matching the API contract (`file_id`, `url`, `mime_type`, `filesize`)
- All 4 entity/DTO tests pass

---

### Task Group 4: Contract Interfaces
**Dependencies:** TG-3  
**Estimated Steps:** 10

- [ ] 4.0 Complete all 6 contract interfaces
  - [ ] 4.1 Write 2 tests validating interface shape through mock implementations
    - Test: a mock `StorageServiceInterface` implementor satisfies all 6 method signatures
    - Test: a mock `StorageQuotaRepositoryInterface` implementor implements `initDefault()` signature
  - [ ] 4.2 Create `src/phpbb/storage/Contract/StorageServiceInterface.php`
    - Methods: `store(StoreFileRequest): DomainEventCollection`, `retrieve(string): StoredFile`, `delete(string, int): DomainEventCollection`, `claim(ClaimContext): DomainEventCollection`, `getUrl(string): string`, `exists(string): bool`
  - [ ] 4.3 Create `src/phpbb/storage/Contract/StoredFileRepositoryInterface.php`
    - Methods: `findById(string): ?StoredFile`, `save(StoredFile): void`, `delete(string): void`, `findOrphansBefore(int): array`, `markClaimed(string, int): void`, `findVariants(string): array`
  - [ ] 4.4 Create `src/phpbb/storage/Contract/StorageQuotaRepositoryInterface.php`
    - Methods: `findByUserAndForum(int, int): ?StorageQuota`, `incrementUsage(int, int, int): bool`, `decrementUsage(int, int, int): void`, `reconcile(int, int, int): void`, `findAllUserForumPairs(): array`, `initDefault(int, int): void`
  - [ ] 4.5 Create `src/phpbb/storage/Contract/QuotaServiceInterface.php`
    - Methods: `checkAndReserve(int, int, int): void`, `release(int, int, int): void`, `reconcileAll(): DomainEventCollection`
  - [ ] 4.6 Create `src/phpbb/storage/Contract/OrphanServiceInterface.php`
    - Methods: `cleanupExpired(int): DomainEventCollection`
  - [ ] 4.7 Create `src/phpbb/storage/Contract/UrlGeneratorInterface.php`
    - Methods: `generateUrl(StoredFile): string`, `generatePublicUrl(string, AssetType): string`, `generatePrivateUrl(string): string`
  - [ ] 4.8 Run the 2 interface tests written in 4.1; all must pass

**Acceptance Criteria:**
- 6 interface files in `src/phpbb/storage/Contract/`
- All method signatures match the spec exactly (parameter types, return types, nullability)
- `StorageQuotaRepositoryInterface::initDefault()` present with correct signature

---

### Task Group 5: Domain Events
**Dependencies:** TG-1, TG-2  
**Estimated Steps:** 11

- [ ] 5.0 Complete all 7 domain event classes
  - [ ] 5.1 Write 4 tests for event construction
    - Test: `FileStoredEvent` constructs with `string` entityId; `$event->entityId` is the UUID hex
    - Test: `QuotaExceededEvent` constructs with `int` entityId (userId); `$event->entityId` is `int`
    - Test: `FileClaimedEvent::$actorId` is set correctly
    - Test: `OrphanCleanupEvent::$actorId === 0` (system actor)
  - [ ] 5.2 Create `src/phpbb/storage/Event/FileStoredEvent.php`
    - `final readonly class FileStoredEvent extends DomainEvent`
    - Constructor: `string $entityId` (fileId UUID hex), `int $actorId`, `string $fileId`, `AssetType $assetType`
    - Pass `$entityId` and `$actorId` to `parent::__construct()`
    - File header, `declare(strict_types=1)`, namespace `phpbb\storage\Event`
  - [ ] 5.3 Create `src/phpbb/storage/Event/FileClaimedEvent.php`
    - `final readonly class FileClaimedEvent extends DomainEvent`
    - Constructor: `string $entityId` (fileId UUID hex), `int $actorId`
  - [ ] 5.4 Create `src/phpbb/storage/Event/FileDeletedEvent.php`
    - `final readonly class FileDeletedEvent extends DomainEvent`
    - Constructor: `string $entityId` (fileId UUID hex), `int $actorId`
  - [ ] 5.5 Create `src/phpbb/storage/Event/VariantGeneratedEvent.php`
    - `final readonly class VariantGeneratedEvent extends DomainEvent`
    - Constructor: `string $entityId` (variantId UUID hex), `int $actorId = 0`, `string $parentId`
  - [ ] 5.6 Create `src/phpbb/storage/Event/QuotaExceededEvent.php`
    - `final readonly class QuotaExceededEvent extends DomainEvent`
    - Constructor: `int $entityId` (userId), `int $actorId`, `int $forumId`, `int $requestedBytes`, `int $maxBytes`
  - [ ] 5.7 Create `src/phpbb/storage/Event/QuotaReconciledEvent.php`
    - `final readonly class QuotaReconciledEvent extends DomainEvent`
    - Constructor: `int $entityId` (userId), `int $actorId = 0`, `int $forumId`, `int $oldBytes`, `int $newBytes`
  - [ ] 5.8 Create `src/phpbb/storage/Event/OrphanCleanupEvent.php`
    - `final readonly class OrphanCleanupEvent extends DomainEvent`
    - Constructor: `string $entityId` (fileId UUID hex), `int $actorId = 0`
  - [ ] 5.9 Run the 4 event tests written in 5.1; all must pass

**Acceptance Criteria:**
- 7 event classes in `src/phpbb/storage/Event/`
- Events using UUID hex fileId pass `string` to parent; events using userId pass `int`
- `OrphanCleanupEvent` uses `$actorId = 0` (system)
- All 4 event tests pass

---

### Task Group 6: Repositories + Adapter
**Dependencies:** TG-3, TG-4  
**Estimated Steps:** 28

- [ ] 6.0 Complete both repositories and the Flysystem adapter factory
  - [ ] 6.1 Write 8 integration tests for `DbalStoredFileRepository` (SQLite in-memory, extend `IntegrationTestCase`)
    - Test: `save()` then `findById()` returns the same `StoredFile` with correct field values
    - Test: `findById()` returns `null` for unknown UUID
    - Test: `delete()` removes the row; subsequent `findById()` returns `null`
    - Test: `findOrphansBefore(timestamp)` returns only rows with `is_orphan=1` and `created_at < timestamp`
    - Test: `markClaimed(fileId, timestamp)` sets `is_orphan=0` and `claimed_at`
    - Test: `findVariants(parentId)` returns only child rows with matching `parent_id`
    - Test: `save()` with `parent_id` set inserts a variant row correctly
    - Test: `delete()` on a parent ID does NOT auto-delete children (code must handle it)
  - [ ] 6.2 Create `src/phpbb/storage/Repository/DbalStoredFileRepository.php`
    - `final class DbalStoredFileRepository implements StoredFileRepositoryInterface`
    - `private const TABLE = 'phpbb_stored_files'`
    - Constructor: `private readonly \Doctrine\DBAL\Connection $connection`
    - `findById(string $fileId): ?StoredFile`
      - Query: `SELECT * FROM phpbb_stored_files WHERE id = :id LIMIT 1` using `unhex(:id)` → `UNHEX(:id)`
      - Actually store `id` as `BINARY(16)`, use `HEX(id)` in SELECT and `UNHEX(:id)` in WHERE
      - Return `$this->hydrate($row)` or `null`
      - Wrap in try/catch `\Doctrine\DBAL\Exception` → throw `RepositoryException`
    - `save(StoredFile $file): void`
      - INSERT with all columns; use `UNHEX(:id)` for binary storage of UUID
      - Use named params; `$file->createdAt`, `$file->isOrphan` as int, `$file->parentId` nullable
    - `delete(string $fileId): void`
      - `DELETE FROM phpbb_stored_files WHERE id = UNHEX(:id)`
    - `findOrphansBefore(int $timestamp): array`
      - `SELECT *, HEX(id) as id, HEX(parent_id) as parent_id FROM ... WHERE is_orphan = 1 AND created_at < :ts`
      - Return `array<StoredFile>`
    - `markClaimed(string $fileId, int $claimedAt): void`
      - `UPDATE phpbb_stored_files SET is_orphan = 0, claimed_at = :claimedAt WHERE id = UNHEX(:id)`
    - `findVariants(string $parentId): array`
      - `SELECT *, HEX(id) as id, HEX(parent_id) as parent_id FROM ... WHERE parent_id = UNHEX(:parentId)`
    - `private function hydrate(array $row): StoredFile`
      - Map DB row to `StoredFile` entity; use `AssetType::from()`, `FileVisibility::from()`, `VariantType::tryFrom()` for enum hydration
      - Handle nullable `parent_id` and `claimed_at`
  - [ ] 6.3 Write 4 integration tests for `DbalStorageQuotaRepository` (SQLite in-memory)
    - Test: `findByUserAndForum()` returns `null` when no row exists
    - Test: `incrementUsage()` returns `true` and updates `used_bytes` when under quota
    - Test: `incrementUsage()` returns `false` when `used_bytes + bytes > max_bytes`
    - Test: `initDefault()` inserts row with `max_bytes = PHP_INT_MAX`; second call is no-op (INSERT IGNORE)
    - Test: `decrementUsage()` sets `used_bytes` to `GREATEST(0, used_bytes - :bytes)` (no negative)
    - Test: `reconcile()` updates `used_bytes` to exact value passed
    - Test: `findAllUserForumPairs()` returns all distinct `(user_id, forum_id)` pairs
  - [ ] 6.4 Create `src/phpbb/storage/Repository/DbalStorageQuotaRepository.php`
    - `final class DbalStorageQuotaRepository implements StorageQuotaRepositoryInterface`
    - `private const TABLE = 'phpbb_storage_quotas'`
    - Constructor: `private readonly \Doctrine\DBAL\Connection $connection`
    - `findByUserAndForum(int $userId, int $forumId): ?StorageQuota`
      - `SELECT * FROM phpbb_storage_quotas WHERE user_id = :userId AND forum_id = :forumId LIMIT 1`
    - `incrementUsage(int $userId, int $forumId, int $bytes): bool`
      - `UPDATE phpbb_storage_quotas SET used_bytes = used_bytes + :bytes, updated_at = :now WHERE user_id = :userId AND forum_id = :forumId AND used_bytes + :bytes <= max_bytes`
      - Return `$affectedRows > 0`
    - `decrementUsage(int $userId, int $forumId, int $bytes): void`
      - `UPDATE phpbb_storage_quotas SET used_bytes = GREATEST(0, used_bytes - :bytes), updated_at = :now WHERE user_id = :userId AND forum_id = :forumId`
    - `reconcile(int $userId, int $forumId, int $actualBytes): void`
      - `UPDATE phpbb_storage_quotas SET used_bytes = :actualBytes, updated_at = :now WHERE user_id = :userId AND forum_id = :forumId`
    - `findAllUserForumPairs(): array`
      - `SELECT user_id, forum_id FROM phpbb_storage_quotas`
      - Return `array<array{user_id: int, forum_id: int}>`
    - `initDefault(int $userId, int $forumId): void`
      - `INSERT IGNORE INTO phpbb_storage_quotas (user_id, forum_id, used_bytes, max_bytes, updated_at) VALUES (:userId, :forumId, 0, :maxBytes, :now)`
      - Use `\PHP_INT_MAX` for `max_bytes`
    - `private function hydrate(array $row): StorageQuota`
  - [ ] 6.5 Create `src/phpbb/storage/Adapter/StorageAdapterFactory.php`
    - `final class StorageAdapterFactory`
    - Constructor: `private readonly string $storagePath`
    - Method: `public function createForAssetType(AssetType $assetType): \League\Flysystem\FilesystemOperator`
      - Resolve sub-path per asset type:
        - `AssetType::Avatar` → `{storagePath}/images/avatars/upload/`
        - `AssetType::Attachment` → `{storagePath}/files/`
        - `AssetType::Export` → `{storagePath}/files/`
      - Return `new \League\Flysystem\Filesystem(new \League\Flysystem\Local\LocalFilesystemAdapter($subPath))`
  - [ ] 6.6 Run the 8+4 repository tests (12 total) written in 6.1 and 6.3; all must pass

**Acceptance Criteria:**
- `DbalStoredFileRepository` stores/retrieves UUID as `BINARY(16)` using `HEX()`/`UNHEX()`
- `DbalStorageQuotaRepository::incrementUsage()` returns `bool` (not void)
- `initDefault()` uses `INSERT IGNORE` semantics
- `StorageAdapterFactory::createForAssetType()` returns a configured `Filesystem` instance
- All 12 repository tests pass

---

### Task Group 7: Services
**Dependencies:** TG-5, TG-6  
**Estimated Steps:** 18

- [ ] 7.0 Complete `QuotaService`, `OrphanService`, and `UrlGenerator`
  - [ ] 7.1 Write 6 unit tests for `QuotaService` (mocked `StorageQuotaRepositoryInterface` and `Connection`)
    - Test: `checkAndReserve()` - happy path: `incrementUsage()` returns `true` → no exception
    - Test: `checkAndReserve()` - quota full: `incrementUsage()` returns `false`, row exists → throws `QuotaExceededException` and dispatches `QuotaExceededEvent`
    - Test: `checkAndReserve()` - missing row: `incrementUsage()` returns `false`, `findByUserAndForum()` returns `null` → calls `initDefault()` → retries `incrementUsage()` → succeeds
    - Test: `checkAndReserve()` - missing row + retry still fails → throws `QuotaExceededException`
    - Test: `release()` calls `decrementUsage()` with correct params
    - Test: `reconcileAll()` calls `findAllUserForumPairs()` and `reconcile()` per pair; returns `DomainEventCollection` with `QuotaReconciledEvent` per pair where value changed
  - [ ] 7.2 Create `src/phpbb/storage/Quota/QuotaService.php`
    - `final class QuotaService implements QuotaServiceInterface`
    - Constructor: `private readonly StorageQuotaRepositoryInterface $quotaRepo`, `private readonly \Doctrine\DBAL\Connection $connection`, `private readonly \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher`
    - `checkAndReserve(int $userId, int $forumId, int $bytes): void`
      - Call `$this->quotaRepo->incrementUsage($userId, $forumId, $bytes)`
      - If returns `false`:
        - Call `findByUserAndForum($userId, $forumId)`
        - If `null` → call `initDefault($userId, $forumId)` → retry `incrementUsage()` once → if still `false` → fall through to exception
        - If not `null` (row exists, quota actually full) or retry failed → dispatch `QuotaExceededEvent` → throw `QuotaExceededException`
    - `release(int $userId, int $forumId, int $bytes): void`
      - Call `$this->quotaRepo->decrementUsage($userId, $forumId, $bytes)`
    - `reconcileAll(): DomainEventCollection`
      - `findAllUserForumPairs()` → for each pair:
        - SELECT `SUM(filesize)` from `phpbb_stored_files` WHERE `uploader_id = userId AND forum_id = forumId`
        - Call `$this->quotaRepo->reconcile($userId, $forumId, $actualBytes)`
        - Add `QuotaReconciledEvent` to collection
      - Return `DomainEventCollection`
  - [ ] 7.3 Write 4 unit tests for `OrphanService` (mocked repos, adapter)
    - Test: `cleanupExpired()` calls `findOrphansBefore()` with `time() - 86400` threshold
    - Test: each orphan triggers: Flysystem `delete()`, quota `release()`, `StoredFileRepository::delete()`, `OrphanCleanupEvent` dispatched
    - Test: Flysystem failure on one orphan is caught and logged; processing continues to next orphan
    - Test: `cleanupExpired()` returns `DomainEventCollection` with one event per successfully deleted orphan
  - [ ] 7.4 Create `src/phpbb/storage/Service/OrphanService.php`
    - `final class OrphanService implements OrphanServiceInterface`
    - Constructor: `private readonly StoredFileRepositoryInterface $fileRepo`, `private readonly StorageAdapterFactory $adapterFactory`, `private readonly QuotaServiceInterface $quotaService`, `private readonly \Doctrine\DBAL\Connection $connection`, `private readonly \Psr\Log\LoggerInterface $logger`
    - `cleanupExpired(int $olderThanTimestamp): DomainEventCollection`
      - `$orphans = $this->fileRepo->findOrphansBefore($olderThanTimestamp)`
      - For each orphan: wrap in individual try/catch
        - `$this->connection->beginTransaction()`
        - `$filesystem = $this->adapterFactory->createForAssetType($orphan->assetType)`
        - `$filesystem->delete($orphan->physicalName)` (best effort)
        - `$this->quotaService->release($orphan->uploaderId, $orphan->forumId, $orphan->filesize)`
        - `$this->fileRepo->delete($orphan->id)`
        - `$this->connection->commit()`
        - Add `OrphanCleanupEvent` to collection
        - On any exception: `$this->connection->rollBack()`, `$this->logger->error(...)`, continue
      - Return `DomainEventCollection`
  - [ ] 7.5 Write 3 unit tests for `UrlGenerator`
    - Test: `generateUrl()` for `Avatar` file returns direct nginx URL (`{baseUrl}/images/avatars/upload/{physicalName}`)
    - Test: `generateUrl()` for `Attachment` file (visibility=private) returns PHP auth URL (`{baseUrl}/api/v1/files/{fileId}/download`)
    - Test: `generatePublicUrl()` for `Export` returns `{baseUrl}/files/{physicalName}`
  - [ ] 7.6 Create `src/phpbb/storage/Service/UrlGenerator.php`
    - `final class UrlGenerator implements UrlGeneratorInterface`
    - Constructor: `private readonly string $baseUrl`
    - `generateUrl(StoredFile $file): string`
      - If `$file->visibility === FileVisibility::Public` → `generatePublicUrl($file->physicalName, $file->assetType)`
      - Else → `generatePrivateUrl($file->id)`
    - `generatePublicUrl(string $physicalName, AssetType $assetType): string`
      - `Avatar` → `"{$this->baseUrl}/images/avatars/upload/{$physicalName}"`
      - `Export`, `Attachment` public → `"{$this->baseUrl}/files/{$physicalName}"`
    - `generatePrivateUrl(string $fileId): string`
      - Return `"{$this->baseUrl}/api/v1/files/{$fileId}/download"`
  - [ ] 7.7 Run 6+4+3 = 13 tests from 7.1, 7.3, 7.5; all must pass

**Acceptance Criteria:**
- `QuotaService::checkAndReserve()` follows the B-1 fix: missing row → `initDefault()` → retry once
- `OrphanService` uses per-file transactions; individual failure does not abort the loop
- `UrlGenerator` discriminates on `FileVisibility` (not `AssetType`) for URL type selection
- All 13 service tests pass

---

### Task Group 8: StorageService Facade
**Dependencies:** TG-7  
**Estimated Steps:** 20

- [ ] 8.0 Complete `StorageService` core orchestration
  - [ ] 8.1 Write helper function `generateUuidV7(): string` (private static or standalone)
    - Pure PHP implementation per spec; no external UUID library
    - Algorithm:
      ```
      $ms = (int)(microtime(true) * 1000);
      $bytes = str_pad('', 16, "\0");
      for ($i = 5; $i >= 0; $i--) { $bytes[$i] = chr($ms & 0xFF); $ms >>= 8; }
      $rand = random_bytes(10);
      $bytes = substr($bytes, 0, 6) . $rand;
      $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);  // version = 7
      $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);  // variant = 10xxxxxx
      return bin2hex($bytes);  // 32-char hex
      ```
  - [ ] 8.2 Write 8 unit tests for `StorageService` (mocked all dependencies)
    - Test: `store()` happy path — calls `quotaService->checkAndReserve()`, writes file, saves to repo, returns `DomainEventCollection` containing `FileStoredEvent`
    - Test: `store()` validates `assetType` is valid `AssetType` (throws `UploadValidationException` on unknown value)
    - Test: `store()` validates `filesize > 0` and `mimeType` non-empty (throws `UploadValidationException`)
    - Test: `store()` quota exceeded — `checkAndReserve()` throws `QuotaExceededException`, no file is written
    - Test: `store()` Flysystem write failure — rolls back DB transaction, calls `quotaService->release()`, throws `StorageWriteException`
    - Test: `delete()` happy path — retrieves file, deletes from Flysystem, decrements quota, deletes from repo, dispatches `FileDeletedEvent`
    - Test: `claim()` happy path — calls `markClaimed()`, dispatches `FileClaimedEvent`
    - Test: `claim()` on already-claimed file — throws `OrphanClaimException`
    - Test: `exists()` returns `true` if `findById()` returns a file; `false` if `null`
  - [ ] 8.3 Create `src/phpbb/storage/StorageService.php`
    - `final class StorageService implements StorageServiceInterface`
    - Constructor:
      ```php
      private readonly StoredFileRepositoryInterface $fileRepo,
      private readonly QuotaServiceInterface $quotaService,
      private readonly OrphanServiceInterface $orphanService,
      private readonly UrlGeneratorInterface $urlGenerator,
      private readonly StorageAdapterFactory $adapterFactory,
      private readonly \Doctrine\DBAL\Connection $connection,
      private readonly \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher,
      ```
    - `store(StoreFileRequest $request): DomainEventCollection`
      - Validate `$request->mimeType !== ''` and `$request->filesize > 0`; throw `UploadValidationException`
      - Validate `$request->assetType` is a valid `AssetType` (already typed, but check enum from string if needed)
      - Call `$this->quotaService->checkAndReserve($request->uploaderId, $request->forumId, $request->filesize)` — BEFORE transaction
      - `$fileId = $this->generateUuidV7()`
      - `$physicalName = $fileId`
      - `$visibility = $request->assetType->toVisibility()`
      - Compute `$checksum = hash_file('sha256', $request->tmpPath)`
      - `$this->connection->beginTransaction()`
      - try:
        - `$filesystem = $this->adapterFactory->createForAssetType($request->assetType)`
        - `$filesystem->write($physicalName, file_get_contents($request->tmpPath))`
        - Build `StoredFile` entity with all fields; `$isOrphan = true`, `$createdAt = time()`
        - `$this->fileRepo->save($file)`
        - `$this->connection->commit()`
        - `$event = new FileStoredEvent($fileId, $request->uploaderId, $fileId, $request->assetType)`
        - `$this->dispatcher->dispatch($event, 'phpbb.storage.file_stored')`
        - Return `new DomainEventCollection([$event])`
      - catch:
        - `$this->connection->rollBack()`
        - `$this->quotaService->release($request->uploaderId, $request->forumId, $request->filesize)`
        - throw `new StorageWriteException(..., previous: $e)`
    - `retrieve(string $fileId): StoredFile`
      - `$file = $this->fileRepo->findById($fileId) ?? throw new FileNotFoundException("File {$fileId} not found")`
      - Return `$file`
    - `delete(string $fileId, int $actorId): DomainEventCollection`
      - Retrieve file (throw `FileNotFoundException` if absent)
      - `$this->connection->beginTransaction()`
      - try:
        - `$filesystem = $this->adapterFactory->createForAssetType($file->assetType)`
        - `$filesystem->delete($file->physicalName)` (best effort; FileNotFoundException from Flysystem ignored)
        - `$this->quotaService->release($file->uploaderId, $file->forumId, $file->filesize)`
        - `$variants = $this->fileRepo->findVariants($fileId)`
        - For each variant: `$filesystem->delete($variant->physicalName)`, `$this->fileRepo->delete($variant->id)`
        - `$this->fileRepo->delete($fileId)`
        - `$this->connection->commit()`
        - Dispatch and return `DomainEventCollection([new FileDeletedEvent($fileId, $actorId)])`
      - catch: rollBack, re-throw
    - `claim(ClaimContext $ctx): DomainEventCollection`
      - Retrieve file; throw `FileNotFoundException` if absent
      - If `!$file->isOrphan` → throw `new OrphanClaimException("File {$ctx->fileId} is already claimed")`
      - `$this->fileRepo->markClaimed($ctx->fileId, time())`
      - Dispatch and return `DomainEventCollection([new FileClaimedEvent($ctx->fileId, $ctx->actorId)])`
    - `getUrl(string $fileId): string`
      - Return `$this->urlGenerator->generateUrl($this->retrieve($fileId))`
    - `exists(string $fileId): bool`
      - Return `$this->fileRepo->findById($fileId) !== null`
    - `private function generateUuidV7(): string` — per spec algorithm
  - [ ] 8.4 Run the 8 unit tests from 8.2 and the 2 from 1.1; all must pass (10 total for this group)

**Acceptance Criteria:**
- `store()` calls `checkAndReserve()` BEFORE `beginTransaction()` (post-audit fix)
- On Flysystem failure: `rollBack()` called, `release()` called for quota compensation
- `claim()` throws `OrphanClaimException` if `is_orphan = 0`
- UUID v7 generated server-side via pure PHP `random_bytes()`
- `delete()` removes variant rows before deleting parent
- All 10 tests pass

---

### Task Group 9: Variant System
**Dependencies:** TG-8  
**Estimated Steps:** 12

- [ ] 9.0 Complete variant generator and thumbnail listener
  - [ ] 9.1 Write 5 unit tests for `ThumbnailListener` (mocked `ThumbnailGenerator`, `StoredFileRepository`, `AdapterFactory`)
    - Test: listener skips if `$event->assetType` is `AssetType::Attachment` (non-image-compatible)
    - Test: listener calls `ThumbnailGenerator::generate()` for `AssetType::Avatar`
    - Test: on GD failure, listener catches exception and does NOT re-throw (upload response unaffected)
    - Test: on success, listener calls `$fileRepo->save()` with a child `StoredFile` where `parentId = event->fileId` and `variantType = VariantType::Thumbnail`
    - Test: `VariantGeneratedEvent` is dispatched after successful thumbnail creation
  - [ ] 9.2 Create `src/phpbb/storage/Variant/VariantGeneratorInterface.php`
    - Interface with single method: `public function generate(string $sourcePath): string` (returns temp file path of generated variant)
  - [ ] 9.3 Create `src/phpbb/storage/Variant/ThumbnailGenerator.php`
    - `final class ThumbnailGenerator implements VariantGeneratorInterface`
    - `generate(string $sourcePath): string`
      - `$source = imagecreatefromstring(file_get_contents($sourcePath))`
      - Create thumbnail (e.g., 150×150): `$thumb = imagecreatetruecolor(150, 150)`
      - `imagecopyresampled($thumb, $source, 0, 0, 0, 0, 150, 150, imagesx($source), imagesy($source))`
      - Write to `sys_get_temp_dir() . '/' . uniqid('thumb_', true) . '.jpg'`
      - `imagejpeg($thumb, $tmpPath, 85)`
      - `imagedestroy($source); imagedestroy($thumb)`
      - Return `$tmpPath`
    - Throws `\RuntimeException` on GD failure (listener will catch it)
  - [ ] 9.4 Create `src/phpbb/storage/Variant/ThumbnailListener.php`
    - `final class ThumbnailListener`
    - Constructor: `private readonly VariantGeneratorInterface $generator`, `private readonly StoredFileRepositoryInterface $fileRepo`, `private readonly StorageAdapterFactory $adapterFactory`, `private readonly \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher`, `private readonly \Psr\Log\LoggerInterface $logger`
    - `public function __invoke(FileStoredEvent $event): void`
      - Skip if `$event->assetType !== AssetType::Avatar` (only generate thumbnails for avatars)
        - Could be extended to check MIME type contains `image/`; for now skip non-avatar types
      - try:
        - Get original file: `$file = $this->fileRepo->findById($event->fileId)`
        - `$filesystem = $this->adapterFactory->createForAssetType($event->assetType)`
        - Read original: `$tmpOriginal = sys_get_temp_dir() . '/' . uniqid('orig_', true)`; `file_put_contents($tmpOriginal, $filesystem->read($file->physicalName))`
        - `$thumbTmpPath = $this->generator->generate($tmpOriginal)`
        - `$thumbId = generateUuidV7()` — duplicate the UUID function or extract to a shared helper
        - Build child `StoredFile` with `parentId = $file->id`, `variantType = VariantType::Thumbnail`, `isOrphan = false`
        - `$filesystem->write($thumbId, file_get_contents($thumbTmpPath))`
        - `$this->fileRepo->save($thumbEntity)`
        - `$this->dispatcher->dispatch(new VariantGeneratedEvent($thumbId, 0, $event->fileId), 'phpbb.storage.variant_generated')`
        - Cleanup temp files
      - catch (`\Throwable $e`):
        - `$this->logger->error('Thumbnail generation failed', ['file' => $event->fileId, 'error' => $e->getMessage()])`
        - Return (never re-throw)
  - [ ] 9.5 Extract `generateUuidV7()` to a standalone function in `src/phpbb/storage/generateUuidV7.php`
    - Move the private method from `StorageService` to a file-level function: `function phpbb\storage\generateUuidV7(): string`
    - Require autoloading via `composer.json` `files` array, or place in a helper class `UuidGenerator::v7(): string`
    - Preferred: `final class UuidGenerator { public static function v7(): string {...} }` in `src/phpbb/storage/UuidGenerator.php`
    - Update `StorageService` and `ThumbnailListener` to use `UuidGenerator::v7()`
  - [ ] 9.6 Run the 5 listener tests from 9.1; all must pass

**Acceptance Criteria:**
- `ThumbnailListener::__invoke()` never throws — all exceptions caught and logged
- Thumbnail stored as a child row with `parent_id` and `variant_type = 'thumbnail'`
- `VariantGeneratedEvent` dispatched on success
- Non-image-compatible asset types are skipped without error
- All 5 variant tests pass

---

### Task Group 10: Cron Jobs
**Dependencies:** TG-7  
**Estimated Steps:** 8

- [ ] 10.0 Complete quota reconciliation and orphan cleanup cron jobs
  - [ ] 10.1 Write 4 unit tests for the cron jobs
    - Test: `QuotaReconciliationJob::run()` calls `QuotaService::reconcileAll()` and dispatches all returned events
    - Test: `OrphanCleanupJob::run()` calls `OrphanService::cleanupExpired(time() - 86400)` with correct timestamp
    - Test: `OrphanCleanupJob::run()` dispatches all events returned by `cleanupExpired()`
    - Test: `QuotaReconciliationJob::run()` is safe when `reconcileAll()` returns empty collection
  - [ ] 10.2 Create `src/phpbb/storage/Quota/QuotaReconciliationJob.php`
    - `final class QuotaReconciliationJob`
    - Constructor: `private readonly QuotaServiceInterface $quotaService`, `private readonly \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher`
    - `public function run(): void`
      - `$events = $this->quotaService->reconcileAll()`
      - For each event in collection: `$this->dispatcher->dispatch($event, ...)`
  - [ ] 10.3 Create `src/phpbb/storage/Orphan/OrphanCleanupJob.php`
    - `final class OrphanCleanupJob`
    - Constructor: `private readonly OrphanServiceInterface $orphanService`, `private readonly \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher`
    - `public function run(): void`
      - `$threshold = time() - 86400`
      - `$events = $this->orphanService->cleanupExpired($threshold)`
      - For each event: `$this->dispatcher->dispatch($event, ...)`
  - [ ] 10.4 Run the 4 cron job tests from 10.1; all must pass

**Acceptance Criteria:**
- `OrphanCleanupJob` passes `time() - 86400` as the threshold (24 hours ago)
- Both jobs delegate entirely to the services (no business logic in the job classes)
- Both jobs dispatch all returned domain events
- All 4 cron tests pass

---

### Task Group 11: REST Controller
**Dependencies:** TG-8  
**Estimated Steps:** 14

- [ ] 11.0 Complete `FilesController` for `POST /api/v1/files`
  - [ ] 11.1 Write 6 unit tests for `FilesController` (mocked `StorageServiceInterface` and `EventDispatcherInterface`)
    - Test: `store()` with no authenticated user returns 401
    - Test: `store()` with missing `file` field returns 400 with `{"error": "...", "code": "missing_file"}`
    - Test: `store()` with invalid `asset_type` returns 400 with `{"error": "...", "code": "invalid_asset_type"}`
    - Test: `store()` happy path returns 201 with JSON body matching `FileStoredResponse::toArray()`
    - Test: `store()` when `StorageService::store()` throws `QuotaExceededException` returns 400 with quota error body
    - Test: `store()` when `StorageService::store()` throws `StorageWriteException` returns 500
    - Test: `store()` file size exceeds limit returns 413
  - [ ] 11.2 Create `src/phpbb/api/Controller/FilesController.php`
    - `class FilesController`
    - Constructor: `private readonly StorageServiceInterface $storageService`, `private readonly \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher`
    - `#[Route('/api/v1/files', name: 'api_v1_files_store', methods: ['POST'])]`
    - `public function store(Request $request): JsonResponse`
      - Auth check: `$user = $request->attributes->get('_api_user')` → if null, return `new JsonResponse(['error' => 'Unauthorized'], 401)`
      - Get uploaded file: `$uploadedFile = $request->files->get('file')` → if null or not valid, return 400
      - File size check: if `$uploadedFile->getSize() > MAX_UPLOAD_BYTES` → return 413 `['error' => 'File too large']`
        - `private const MAX_UPLOAD_BYTES = 50 * 1024 * 1024` (50 MB; adjust per project config)
      - Validate `asset_type`: `$assetTypeStr = $request->request->get('asset_type', '')` → try `AssetType::from($assetTypeStr)` → catch `ValueError` → return 400 `['error' => 'Invalid asset_type', 'code' => 'invalid_asset_type']`
      - Detect MIME server-side: `$finfo = finfo_open(FILEINFO_MIME_TYPE)` → `$mimeType = finfo_file($finfo, $uploadedFile->getPathname())` → `finfo_close($finfo)` — ignore client Content-Type
      - `$forumId = (int)$request->request->get('forum_id', 0)`
      - Build `StoreFileRequest`:
        ```php
        $storeRequest = new StoreFileRequest(
            assetType: $assetType,
            uploaderId: $user->id,
            forumId: $forumId,
            tmpPath: $uploadedFile->getPathname(),
            originalName: $uploadedFile->getClientOriginalName(),
            mimeType: $mimeType,
            filesize: $uploadedFile->getSize(),
        )
        ```
      - try:
        - `$events = $this->storageService->store($storeRequest)`
        - Extract `FileStoredEvent` from events to build response URL (or call `storageService->getUrl()`)
        - Return `new JsonResponse($response->toArray(), 201)`
      - catch `QuotaExceededException` → 400 `['error' => 'Quota exceeded', 'code' => 'quota_exceeded']`
      - catch `UploadValidationException $e` → 400 `['error' => $e->getMessage(), 'code' => 'validation_error']`
      - catch `StorageWriteException` → 500 `['error' => 'Storage write failed']`
  - [ ] 11.3 Run the 6 controller tests from 11.1; all must pass

**Acceptance Criteria:**
- MIME type detected server-side via `finfo_file()` on the temp file path — client Content-Type ignored
- Response body keys match spec: `file_id`, `url`, `mime_type`, `filesize`
- HTTP status codes: 201 (success), 400 (validation/quota), 401 (no auth), 413 (too large), 500 (Flysystem error)
- Route attribute: `#[Route('/api/v1/files', name: 'api_v1_files_store', methods: ['POST'])]`
- All 6 controller tests pass

---

### Task Group 12: DI Config + SQL Migrations
**Dependencies:** TG-6 through TG-11  
**Estimated Steps:** 10

- [ ] 12.0 Complete DI registration and database migration files
  - [ ] 12.1 Write 2 smoke tests confirming DI wiring
    - Test: `StorageServiceInterface` can be resolved from the container (integration smoke test)
    - Test: `FilesController` can be instantiated from the container
  - [ ] 12.2 Create `migrations/phpbb_stored_files.sql`
    - Full `CREATE TABLE phpbb_stored_files` DDL per spec Data Model section
    - Include all 3 indexes: `idx_uploader (uploader_id)`, `idx_orphan (is_orphan, created_at)`, `idx_parent (parent_id)`
    - Use `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
  - [ ] 12.3 Create `migrations/phpbb_storage_quotas.sql`
    - Full `CREATE TABLE phpbb_storage_quotas` DDL per spec Data Model section
    - Composite PRIMARY KEY `(user_id, forum_id)`
    - `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
  - [ ] 12.4 Add storage module DI block to `src/phpbb/config/services.yaml`
    - Add comment header: `# Storage module (M5b)`
    - Register `DbalStoredFileRepository` with `$connection` arg
    - Register `StoredFileRepositoryInterface` alias
    - Register `DbalStorageQuotaRepository` with `$connection` arg
    - Register `StorageQuotaRepositoryInterface` alias
    - Register `StorageAdapterFactory` with `$storagePath: '%kernel.project_dir%'`
    - Register `UrlGenerator` with `$baseUrl: '%app.base_url%'`
    - Register `UrlGeneratorInterface` alias
    - Register `QuotaService` with `$quotaRepo`, `$connection`, `$dispatcher` args
    - Register `QuotaServiceInterface` alias
    - Register `OrphanService` with `$fileRepo`, `$adapterFactory`, `$quotaService`, `$connection`, `$logger` args
    - Register `OrphanServiceInterface` alias
    - Register `StorageService` with all 7 constructor args
    - Register `StorageServiceInterface` alias as `public: true`
    - Register `ThumbnailListener` with `$generator`, `$fileRepo`, `$adapterFactory`, `$dispatcher`, `$logger` args + tag `kernel.event_listener` for event `phpbb.storage.file_stored`
    - Register `FilesController` (no explicit args needed if aliases are public; ensure controller tag)
    - Register `QuotaReconciliationJob` with `$quotaService`, `$dispatcher` args
    - Register `OrphanCleanupJob` with `$orphanService`, `$dispatcher` args
    - Register `UuidGenerator` (no args — static helper class)
    - Register `ThumbnailGenerator` (no args)
  - [ ] 12.5 Clear app cache and verify services resolve
    - `docker exec phpbb_app rm -rf cache/phpbb4/production cache/installer`
    - Restart container or call `php bin/phpbbcli.php cache:clear`
  - [ ] 12.6 Run the 2 DI smoke tests from 12.1 and then the full `composer test` suite

**Acceptance Criteria:**
- Both migration SQL files are syntactically valid and executable on MySQL 8+
- All storage services register without circular dependencies or missing argument errors
- `StorageServiceInterface` is `public: true` (needed by controllers / cron callers)
- `ThumbnailListener` tagged as `kernel.event_listener` for `phpbb.storage.file_stored`
- `composer test` passes (all 40–60 storage tests + all pre-existing tests)
- `composer cs:fix` produces no changes

---

### Task Group 13: Test Review & Gap Analysis
**Dependencies:** TG-2 through TG-12

- [ ] 13.0 Review and fill critical test gaps
  - [ ] 13.1 Review all tests written in TG-1 through TG-12 (~40–60 existing tests)
  - [ ] 13.2 Identify coverage gaps for this feature only
    - Check: SHA-256 checksum computed in `store()` and stored correctly
    - Check: `store()` variant event dispatched to listener (integration path)
    - Check: `DbalStoredFileRepository::findOrphansBefore()` returns variants too (or not — verify intent)
    - Check: `QuotaService` reconciliation computes `actualBytes` with correct SQL
    - Check: `UrlGenerator` for all 3 `AssetType` values
  - [ ] 13.3 Write up to 8 additional strategic tests
    - Test: `StorageService::store()` computes and stores correct SHA-256 checksum
    - Test: `DbalStoredFileRepository` hydrates `variantType` enum correctly from DB row
    - Test: `QuotaService::reconcileAll()` handles empty pairs list (returns empty collection)
    - Test: `FilesController` returns correct `file_id` in 201 response body
    - Test: `OrphanCleanupJob` processes multiple orphans independently (second is processed even if first fails)
    - Test: `StorageService::delete()` deletes variant rows before parent row
    - Test: `ThumbnailListener` does not generate thumbnail for `AssetType::Attachment`
    - Test: `DbalStorageQuotaRepository::initDefault()` uses `PHP_INT_MAX` for `max_bytes`
  - [ ] 13.4 Run all feature-specific tests (expect 48–68 total)
  - [ ] 13.5 Run `composer test` — all must pass
  - [ ] 13.6 Run `composer cs:fix` — no changes expected in new files

**Acceptance Criteria:**
- All feature-specific tests pass (~48–68 total)
- No more than 8 additional tests added in this group
- Full `composer test` passes
- `composer cs:fix` produces no changes in storage module files

---

## Execution Order

```
TG-1  (no deps)
 ├─ TG-2 (after TG-1)
 │   └─ TG-3 (after TG-2)
 │       └─ TG-4 (after TG-3)
 │           └─ TG-6 (after TG-3, TG-4)
 │               └─ TG-7 (after TG-5, TG-6)
 │                   ├─ TG-8 (after TG-7)
 │                   │   ├─ TG-9 (after TG-8)
 │                   │   └─ TG-11 (after TG-8)
 │                   └─ TG-10 (after TG-7)
 └─ TG-5 (after TG-1, TG-2)

TG-12 (after TG-6 through TG-11)
TG-13 (after all)
```

1. TG-1: Breaking change + composer (0 deps)
2. TG-2: Enums + Exceptions (after TG-1)
3. TG-3: Entities + DTOs (after TG-2)
4. TG-4: Interfaces (after TG-3)
5. TG-5: Domain Events (after TG-1, TG-2) — can be done in parallel with TG-4
6. TG-6: Repositories + Adapter (after TG-3, TG-4)
7. TG-7: Services (after TG-5, TG-6)
8. TG-8: StorageService Facade (after TG-7)
9. TG-9: Variant System (after TG-8)
10. TG-10: Cron Jobs (after TG-7) — can be done in parallel with TG-8/TG-9/TG-11
11. TG-11: REST Controller (after TG-8)
12. TG-12: DI Config + Migrations (after TG-6 through TG-11)
13. TG-13: Test Review (after all)

---

## Test Strategy

| TG | Test File | Type | Count | Coverage Target |
|----|-----------|------|-------|----------------|
| TG-1 | `tests/phpbb/common/Event/DomainEventTest.php` | Unit | 2 | `entityId` type widening |
| TG-2 | `tests/phpbb/storage/Enum/AssetTypeTest.php` | Unit | 4 | Enum from/from failure, toVisibility |
| TG-3 | `tests/phpbb/storage/Entity/StoredFileTest.php` | Unit | 4 | Entity + DTO construction |
| TG-4 | `tests/phpbb/storage/Contract/InterfaceTest.php` | Unit | 2 | Interface shape validation |
| TG-5 | `tests/phpbb/storage/Event/StorageEventTest.php` | Unit | 4 | Event construction with string/int entityId |
| TG-6 | `tests/phpbb/storage/Repository/DbalStoredFileRepositoryTest.php` | Integration | 8 | All CRUD + orphan + variant ops |
| TG-6 | `tests/phpbb/storage/Repository/DbalStorageQuotaRepositoryTest.php` | Integration | 7 | All quota ops including initDefault |
| TG-7 | `tests/phpbb/storage/Quota/QuotaServiceTest.php` | Unit | 6 | checkAndReserve paths, release, reconcile |
| TG-7 | `tests/phpbb/storage/Service/OrphanServiceTest.php` | Unit | 4 | cleanup, failure isolation |
| TG-7 | `tests/phpbb/storage/Service/UrlGeneratorTest.php` | Unit | 3 | All URL generation paths |
| TG-8 | `tests/phpbb/storage/Service/StorageServiceTest.php` | Unit | 8 | All store/delete/claim/exists paths |
| TG-9 | `tests/phpbb/storage/Variant/ThumbnailListenerTest.php` | Unit | 5 | skip, success, GD failure isolation |
| TG-10 | `tests/phpbb/storage/Cron/CronJobTest.php` | Unit | 4 | Delegate + dispatch |
| TG-11 | `tests/phpbb/api/Controller/FilesControllerTest.php` | Unit | 6 | All HTTP status codes + MIME detection |
| TG-13 | (same files) | Unit/Integration | ≤8 | Gap coverage |

**Total: ~75 tests across all groups (including up to 8 from TG-13)**

---

## Acceptance Criteria (linked to spec FRs)

| FR | Criteria | Covered By |
|----|----------|-----------|
| FR-01 | UUID v7 generated server-side in pure PHP; 32-char hex output | TG-8 |
| FR-02 | `store()`: quota reserved before TX; checksum stored; `is_orphan=1`; event dispatched | TG-8, TG-6 |
| FR-03 | `retrieve()` returns `StoredFile`; throws `FileNotFoundException` | TG-8 |
| FR-04 | `delete()` with TX; variants deleted; quota decremented; event dispatched | TG-8, TG-6 |
| FR-05 | `claim()` sets `is_orphan=0`; throws `OrphanClaimException` if already claimed | TG-8 |
| FR-06 | `getUrl()` returns nginx URL (public) or PHP-auth URL (private) | TG-7, TG-8 |
| FR-07 | `exists()` returns `bool` via DB lookup only | TG-8 |
| FR-08 | Atomic `UPDATE WHERE` quota; B-1 fix: missing row → `initDefault()` → retry | TG-7, TG-6 |
| FR-09 | `QuotaReconciliationJob` recomputes `used_bytes` from `SUM(filesize)` | TG-10, TG-7 |
| FR-10 | `OrphanCleanupJob` deletes `is_orphan=1 AND created_at < now-86400`; per-file TX | TG-10, TG-7 |
| FR-11 | `ThumbnailListener` never throws; generates child row with `parent_id` | TG-9 |
| FR-12 | `POST /api/v1/files` with JWT auth; MIME via `finfo_file()`; 201/400/401/413 responses | TG-11 |
| FR-13 | `DomainEvent::entityId` widened to `string\|int`; all existing events unchanged | TG-1 |

---

## Standards Compliance

Follow standards from `.maister/docs/standards/`:

- **global/** — File headers, PHPDoc, tabs for indentation, no closing PHP tag
- **backend/STANDARDS.md** — `final readonly class` for entities/DTOs, DBAL4 prepared statements with named params, `RepositoryException` wrapper, `DomainEventCollection` return type, no `global` in OOP code, DI via constructor
- **backend/REST_API.md** — `#[Route]` attributes on controller methods, `_api_user` from `$request->attributes->get()`, HTTP status codes per spec (201/400/401/413/500), structured error body `{"error": "...", "code": "..."}`
- **testing/STANDARDS.md** — `#[Test]` attribute on all test methods, `IntegrationTestCase` base for SQLite-backed repo tests, isolated mocks with `createMock()`, descriptive test method names using snake_case

## Key Implementation Notes

1. **Quota-before-transaction order**: `checkAndReserve()` executes an atomic `UPDATE` that commits immediately — it is NOT wrapped in the outer DB transaction. On Flysystem failure, quota must be released explicitly via `release()`.

2. **B-1 missing row fix**: If `incrementUsage()` returns `false` and `findByUserAndForum()` returns `null`, call `initDefault()` (INSERT IGNORE) and retry once. If row exists but quota is full, dispatch `QuotaExceededEvent` and throw `QuotaExceededException`.

3. **MIME detection (M-1 fix)**: Controller uses `finfo_open(FILEINFO_MIME_TYPE)` + `finfo_file($finfo, $tmpPath)` on the uploaded file's temp path. The client-provided `Content-Type` header is completely ignored.

4. **Binary UUID storage**: Store `id` as `BINARY(16)` using `UNHEX(:id)` in INSERT/WHERE and `HEX(id) AS id` in SELECT. Expose as 32-char hex string everywhere outside the DB.

5. **Variant isolation**: `ThumbnailListener` wraps its entire body in `try { ... } catch (\Throwable $e) { $this->logger->error(...); return; }`. Upload success must not depend on thumbnail success.

6. **UUID v7 reuse**: Extract `UuidGenerator::v7()` as a static method on a dedicated class to avoid code duplication between `StorageService` and `ThumbnailListener`.
