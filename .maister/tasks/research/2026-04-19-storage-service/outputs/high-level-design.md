# High-Level Design: `phpbb\storage` Service

## Design Overview

**Business context**: phpBB manages uploaded files through two completely separate subsystems — attachments (~1,076 LOC across manager/upload/delete/resync) and avatars (~1,480 LOC across manager + 4 drivers) — that share no code or abstractions despite having nearly identical infrastructure: upload → validate → randomize filename → store → track metadata. Legacy lacks per-user/per-forum quotas, has no orphan cleanup (abandoned uploads persist forever), only supports a single thumbnail size, and is tightly coupled to the local filesystem. The `phpbb\storage` service replaces both subsystems with a unified, plugin-consumable infrastructure layer.

**Chosen approach**: A **`StorageService` facade** backed by **Flysystem** (`league/flysystem`) for filesystem abstraction, a **single `phpbb_stored_files` table** for all asset types with an `asset_type` discriminator column, **UUID v7 `BINARY(16)` primary keys**, and a **counter-based quota system** with periodic reconciliation. File variants (thumbnails) are generated **asynchronously via event listeners** — never in the upload path. The service is **event-driven** — methods dispatch domain events (`FileStoredEvent`, `FileClaimedEvent`, `FileDeletedEvent`). File serving uses a **hybrid strategy**: public assets (avatars) via direct nginx URL, private assets (attachments) via PHP auth + X-Accel-Redirect. Orphan management uses a **DB-tracked `is_orphan` flag** with **24-hour cron TTL cleanup**. Directory layout is **flat** — `files/` for attachments, `images/avatars/upload/` for avatars — with UUID-based filenames for uniqueness.

**Key decisions:**
- **Single `stored_files` table** — all asset types in one table; plugin-specific metadata in separate plugin-owned tables (ADR-001)
- **UUID v7 as BINARY(16)** — time-sortable, non-enumerable, server-generated, no DB roundtrip for ID (ADR-002)
- **Flysystem integration** — `league/flysystem` for storage abstraction; local adapter now, S3/GCS later via config (ADR-003)
- **Async variant generation** — `FileStoredEvent` → listener generates thumbnail; variants stored as child rows with `parent_id` (ADR-004)
- **Counter + reconciliation quotas** — `phpbb_storage_quotas` table with atomic `UPDATE WHERE used_bytes + size <= max_bytes`; cron reconciliation as safety net (ADR-005)
- **Hybrid file serving** — public direct + private X-Accel-Redirect; fallback to PHP streaming (ADR-006)
- **Flat directory layout** — legacy-compatible `files/` and `images/avatars/upload/`; UUID filenames eliminate collisions (ADR-007)

---

## Architecture

### System Context (C4 Level 1)

```
                              ┌───────────────────┐
                              │   End User (Web)   │
                              │   Admin (ACP)      │
                              │   API Consumer      │
                              └────────┬───────────┘
                                       │ HTTP
                                       ▼
                              ┌───────────────────┐
                              │   phpBB App Layer  │
                              │  Controllers/API   │
                              │  (Auth middleware)  │
                              └────────┬───────────┘
                                       │ PHP method calls
                                       ▼
┌──────────────┐     ┌───────────────────────────────────┐     ┌──────────────┐
│ phpbb\auth   │     │      phpbb\storage                │────►│   nginx      │
│ (enforces    │     │      Service Layer                │     │ (X-Accel-    │
│  permissions │     │                                   │     │  Redirect /  │
│  on download)│     │  Flysystem ←→ Local / S3 / GCS   │     │  static)     │
└──────────────┘     └───────────┬───────────────────────┘     └──────────────┘
                                 │            ▲
                          PDO    │            │ Domain events
                                 ▼            │
                     ┌───────────────┐  ┌─────┴──────────────────────┐
                     │   MySQL / DB  │  │  Consumer Plugin Listeners  │
                     │ stored_files  │  │  AttachmentPlugin (threads) │
                     │ storage_quotas│  │  AvatarPlugin (user)        │
                     └───────────────┘  │  ThumbnailListener          │
                                        │  Future plugins...          │
                                        └────────────────────────────┘
```

**External systems**:
- **phpbb\auth** — Called by the API/download layer (NOT by storage) to enforce download permissions on private files
- **phpbb\threads** — Consumes storage via `AttachmentPlugin`; dispatches `PostCreatedEvent` → plugin calls `storage.claim()`
- **phpbb\user** — Consumes storage via `AvatarPlugin`; dispatches `ProfileUpdatedEvent` → plugin calls `storage.store()` / `storage.claim()`
- **phpbb\hierarchy** — Provides `forum_id` context for per-forum quota enforcement
- **nginx** — Serves public files directly; serves private files via X-Accel-Redirect after PHP auth
- **Cron** — Triggers orphan cleanup and quota reconciliation jobs

### Container Overview (C4 Level 2)

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            phpbb\storage                                    │
│                                                                             │
│  ┌───────────────────────────────────────────────────────────────────────┐  │
│  │                     StorageService (Facade)                           │  │
│  │                                                                       │  │
│  │  StoreFileRequest ──► validate ──► check quota ──► write (Flysystem) │  │
│  │       ──► insert DB ──► dispatch FileStoredEvent ──► return response  │  │
│  │                                                                       │  │
│  │  store() | retrieve() | delete() | claim() | getUrl() | exists()     │  │
│  └──┬──────────┬────────────┬──────────┬────────────┬───────────────────┘  │
│     │          │            │          │            │                       │
│  ┌──▼──┐  ┌───▼─────┐  ┌──▼──────┐  ┌▼─────────┐  ┌▼────────────┐       │
│  │Quota│  │Orphan   │  │Url      │  │Stored    │  │Flysystem   │       │
│  │Svc  │  │Svc      │  │Generator│  │FileRepo  │  │Adapter     │       │
│  │     │  │         │  │         │  │          │  │Factory     │       │
│  │check│  │mark     │  │public   │  │find/save │  │            │       │
│  │incr │  │claim    │  │private  │  │delete    │  │local now   │       │
│  │decr │  │cleanup  │  │fallback │  │orphans   │  │S3 later    │       │
│  │recon│  │         │  │         │  │          │  │            │       │
│  └──┬──┘  └────┬────┘  └────┬────┘  └────┬─────┘  └─────┬──────┘       │
│     │          │            │            │              │               │
│     └──────────┴────────┬───┴────────────┘              │               │
│                         │                               │               │
│                   ┌─────▼──────┐                  ┌─────▼──────┐       │
│                   │    PDO     │                  │ Flysystem  │       │
│                   │            │                  │ Filesystem │       │
│                   └────────────┘                  └────────────┘       │
│                                                                         │
│  ┌────────────────────────────────────────────────────────────────┐     │
│  │ EventDispatcher                                                │     │
│  │                                                                │     │
│  │ FileStoredEvent ──► ThumbnailListener (generate variant)      │     │
│  │ FileClaimedEvent ──► (logging, audit)                         │     │
│  │ FileDeletedEvent ──► (quota decrement, cleanup)               │     │
│  │ QuotaExceededEvent ──► (admin notification)                   │     │
│  │ OrphanCleanupEvent ──► (pre-deletion hook)                    │     │
│  └────────────────────────────────────────────────────────────────┘     │
│                                                                         │
│  ┌──────────────────────────────────────┐                               │
│  │ Cron Jobs                            │                               │
│  │                                      │                               │
│  │ OrphanCleanupJob (hourly)            │                               │
│  │ QuotaReconciliationJob (daily)       │                               │
│  └──────────────────────────────────────┘                               │
└─────────────────────────────────────────────────────────────────────────────┘

    Consumer Plugins (OUTSIDE phpbb\storage)
    ┌─────────────────────────────────────────────┐
    │ AttachmentPlugin (in phpbb\threads\plugin\)  │
    │   PostCreatedEvent  → storage.claim()        │
    │   PostDeletedEvent  → storage.delete()       │
    │   Stores to: phpbb_attachment_metadata       │
    └─────────────────────────────────────────────┘
    ┌─────────────────────────────────────────────┐
    │ AvatarPlugin (in phpbb\user\plugin\)         │
    │   ProfileUpdatedEvent → storage.claim()      │
    │   Stores to: phpbb_avatar_metadata           │
    └─────────────────────────────────────────────┘
```

**Container responsibilities**:

| Container | Tech | Responsibility |
|-----------|------|----------------|
| StorageService | PHP 8.2 class | Facade — orchestrates store/retrieve/delete/claim, dispatches events, returns domain events |
| QuotaService | PHP 8.2 + PDO | Multi-level quota enforcement (global/user/forum), atomic reservation, reconciliation |
| OrphanService | PHP 8.2 + PDO | Orphan marking, claiming, TTL-based cleanup via cron |
| UrlGenerator | PHP 8.2 | Produces public URLs (direct nginx) or private URLs (PHP auth endpoint) based on visibility |
| StoredFileRepository | PHP 8.2 + PDO | CRUD on `phpbb_stored_files`, orphan queries, parent/variant queries |
| StorageQuotaRepository | PHP 8.2 + PDO | CRUD on `phpbb_storage_quotas`, atomic quota updates, SUM reconciliation |
| StorageAdapterFactory | PHP 8.2 | Creates Flysystem `FilesystemOperator` instances per asset_type from config |
| ThumbnailListener | PHP 8.2 + GD | Subscribes to `FileStoredEvent`; generates thumbnail variant asynchronously |
| EventDispatcher | Symfony EventDispatcher | Dispatches domain events to registered listeners |

---

## Key Components

### Directory Structure

```
src/phpbb/storage/
├── StorageService.php
├── StorageServiceInterface.php
│
├── contract/
│   ├── StorageServiceInterface.php
│   ├── QuotaServiceInterface.php
│   ├── OrphanServiceInterface.php
│   ├── UrlGeneratorInterface.php
│   ├── StoredFileRepositoryInterface.php
│   └── StorageQuotaRepositoryInterface.php
│
├── dto/
│   ├── StoreFileRequest.php
│   ├── FileStoredResponse.php
│   ├── ClaimContext.php
│   ├── FileClaimedResponse.php
│   ├── FileDeletedResponse.php
│   └── FileInfo.php
│
├── entity/
│   ├── StoredFile.php
│   └── StorageQuota.php
│
├── enum/
│   ├── AssetType.php
│   ├── FileVisibility.php
│   └── VariantType.php
│
├── event/
│   ├── FileStoredEvent.php
│   ├── FileClaimedEvent.php
│   ├── FileDeletedEvent.php
│   ├── VariantGeneratedEvent.php
│   ├── QuotaExceededEvent.php
│   ├── QuotaReconciledEvent.php
│   └── OrphanCleanupEvent.php
│
├── exception/
│   ├── FileNotFoundException.php           # extends phpbb\common\Exception\NotFoundException
│   ├── QuotaExceededException.php          # extends phpbb\common\Exception\ValidationException
│   ├── UploadValidationException.php       # extends phpbb\common\Exception\ValidationException
│   ├── OrphanClaimException.php            # extends phpbb\common\Exception\ConflictException
│   └── StorageWriteException.php           # extends phpbb\common\Exception\PhpbbException
│
├── adapter/
│   └── StorageAdapterFactory.php
│
├── quota/
│   ├── QuotaService.php
│   └── QuotaReconciliationJob.php
│
├── variant/
│   ├── VariantGeneratorInterface.php
│   ├── ThumbnailGenerator.php
│   └── ThumbnailListener.php
│
├── repository/
│   ├── StoredFileRepository.php
│   └── StorageQuotaRepository.php
│
├── service/
│   ├── StorageService.php
│   ├── OrphanService.php
│   └── UrlGenerator.php
│
└── orphan/
    └── OrphanCleanupJob.php
```

### Component Details

| Component | Purpose | Responsibilities | Key Interfaces | Dependencies |
|-----------|---------|------------------|----------------|--------------|
| StorageService | Central facade for all file operations | store(), retrieve(), delete(), claim(), getUrl(), exists() | `StorageServiceInterface` | QuotaService, OrphanService, UrlGenerator, StoredFileRepository, StorageAdapterFactory, EventDispatcher, TagAwareCacheInterface (`cache.storage`) |
| QuotaService | Multi-level quota enforcement | checkQuota(), incrementUsage(), decrementUsage(), reconcile() | `QuotaServiceInterface` | StorageQuotaRepository, PDO |
| OrphanService | Orphan lifecycle management | markOrphan(), claim(), cleanupExpired() | `OrphanServiceInterface` | StoredFileRepository, StorageAdapterFactory, EventDispatcher |
| UrlGenerator | URL production per visibility | generateUrl() — direct for public, proxy for private | `UrlGeneratorInterface` | Config (base URLs, X-Accel prefix) |
| StorageAdapterFactory | Flysystem filesystem creation | createForAssetType() — returns `FilesystemOperator` | — | Flysystem, Config (adapter mappings) |
| ThumbnailListener | Async thumbnail generation | Subscribes to FileStoredEvent, generates thumb if image | `VariantGeneratorInterface` | StorageService, GD extension |
| OrphanCleanupJob | Cron: delete expired orphans | Find orphans older than TTL, dispatch event, delete | — | OrphanService |
| QuotaReconciliationJob | Cron: correct quota drift | Recalculate SUM(filesize) per scope, update counters | — | QuotaService |

---

## Domain Model

### Enums

```php
<?php declare(strict_types=1);

namespace phpbb\storage\enum;

enum AssetType: string
{
    case Attachment = 'attachment';
    case Avatar     = 'avatar';
    case Export     = 'export';

    /** Default storage root path for this asset type */
    public function storagePath(): string
    {
        return match ($this) {
            self::Attachment => 'files/',
            self::Avatar     => 'images/avatars/upload/',
            self::Export     => 'store/',
        };
    }

    /** Default visibility for new files of this type */
    public function defaultVisibility(): FileVisibility
    {
        return match ($this) {
            self::Attachment => FileVisibility::Private,
            self::Avatar     => FileVisibility::Public,
            self::Export     => FileVisibility::Private,
        };
    }
}

enum FileVisibility: string
{
    case Public  = 'public';
    case Private = 'private';
}

enum VariantType: string
{
    case Thumbnail = 'thumbnail';
    case Webp      = 'webp';
    case Medium    = 'medium';
}
```

### StoredFile Entity

```php
<?php declare(strict_types=1);

namespace phpbb\storage\entity;

use phpbb\storage\enum\AssetType;
use phpbb\storage\enum\FileVisibility;
use phpbb\storage\enum\VariantType;

final class StoredFile
{
    public function __construct(
        // Identity — UUID v7 stored as BINARY(16), exposed as hex string
        public readonly string $id,

        // Classification
        public readonly AssetType $assetType,
        public readonly FileVisibility $visibility,

        // File metadata
        public readonly string $originalFilename,
        public readonly string $physicalFilename,
        public readonly string $mimeType,
        public readonly int $filesize,
        public readonly string $checksum,       // SHA-256

        // Orphan lifecycle
        public readonly bool $isOrphan,

        // Variant relationship
        public readonly ?string $parentId,       // NULL for originals, parent UUID for variants
        public readonly ?VariantType $variantType, // NULL for originals

        // Ownership
        public readonly int $uploaderId,
        public readonly int $forumId,            // 0 if not forum-scoped

        // Timestamps (Unix)
        public readonly int $createdAt,
        public readonly ?int $claimedAt,
    ) {}

    public function isImage(): bool
    {
        return str_starts_with($this->mimeType, 'image/');
    }

    public function isVariant(): bool
    {
        return $this->parentId !== null;
    }

    public function isClaimed(): bool
    {
        return !$this->isOrphan;
    }
}
```

### StorageQuota Entity

```php
<?php declare(strict_types=1);

namespace phpbb\storage\entity;

final class StorageQuota
{
    public function __construct(
        public readonly int $id,
        public readonly string $scope,       // 'global', 'user', 'forum'
        public readonly int $scopeId,        // 0 for global, user_id, forum_id
        public readonly int $usedBytes,
        public readonly int $maxBytes,       // 0 = unlimited
        public readonly int $updatedAt,
    ) {}

    public function isUnlimited(): bool
    {
        return $this->maxBytes === 0;
    }

    public function remainingBytes(): int
    {
        if ($this->isUnlimited()) {
            return PHP_INT_MAX;
        }

        return max(0, $this->maxBytes - $this->usedBytes);
    }

    public function wouldExceed(int $additionalBytes): bool
    {
        if ($this->isUnlimited()) {
            return false;
        }

        return ($this->usedBytes + $additionalBytes) > $this->maxBytes;
    }
}
```

---

## Service Architecture

### StorageServiceInterface (Facade)

```php
<?php declare(strict_types=1);

namespace phpbb\storage\contract;

use phpbb\storage\dto\StoreFileRequest;
use phpbb\storage\dto\ClaimContext;
use phpbb\storage\entity\StoredFile;
use phpbb\common\Event\DomainEventCollection;

interface StorageServiceInterface
{
    /**
     * Store a new file. Creates an unclaimed (orphan) record.
     * Validates extension/MIME/size, checks quota, writes via Flysystem,
     * inserts DB row with is_orphan=true, dispatches FileStoredEvent.
     *
     * @return DomainEventCollection Contains FileStoredEvent
     * @throws \phpbb\storage\exception\UploadValidationException
     * @throws \phpbb\storage\exception\QuotaExceededException
     * @throws \phpbb\storage\exception\StorageWriteException
     */
    public function store(StoreFileRequest $request): DomainEventCollection;

    /**
     * Retrieve file metadata by ID.
     *
     * @throws \phpbb\storage\exception\FileNotFoundException
     */
    public function retrieve(string $fileId): StoredFile;

    /**
     * Delete a file and all its variants. Decrements quota.
     * Dispatches FileDeletedEvent.
     *
     * @return DomainEventCollection Contains FileDeletedEvent
     * @throws \phpbb\storage\exception\FileNotFoundException
     */
    public function delete(string $fileId): DomainEventCollection;

    /**
     * Claim an orphan file — sets is_orphan=false, claimed_at=NOW().
     * Dispatches FileClaimedEvent.
     *
     * @return DomainEventCollection Contains FileClaimedEvent
     * @throws \phpbb\storage\exception\FileNotFoundException
     * @throws \phpbb\storage\exception\OrphanClaimException
     */
    public function claim(string $fileId, ClaimContext $context): DomainEventCollection;

    /**
     * Get the serving URL for a file.
     * Returns direct nginx URL for public files, PHP auth proxy URL for private.
     * If $variantType is specified, returns URL for that variant (or original if not generated).
     *
     * @throws \phpbb\storage\exception\FileNotFoundException
     */
    public function getUrl(string $fileId, ?string $variantType = null): string;

    /**
     * Check if a file exists by ID.
     */
    public function exists(string $fileId): bool;

    /**
     * Find all files owned by a specific claimer.
     *
     * @return StoredFile[]
     */
    public function findByOwner(string $assetType, int $ownerId): array;
}
```

### StorageService Implementation (Key Methods)

```php
<?php declare(strict_types=1);

namespace phpbb\storage\service;

use League\Flysystem\FilesystemOperator;
use phpbb\storage\adapter\StorageAdapterFactory;
use phpbb\storage\contract\StorageServiceInterface;
use phpbb\storage\contract\QuotaServiceInterface;
use phpbb\storage\contract\OrphanServiceInterface;
use phpbb\storage\contract\UrlGeneratorInterface;
use phpbb\storage\contract\StoredFileRepositoryInterface;
use phpbb\storage\dto\StoreFileRequest;
use phpbb\storage\dto\FileStoredResponse;
use phpbb\storage\dto\ClaimContext;
use phpbb\storage\dto\FileClaimedResponse;
use phpbb\storage\dto\FileDeletedResponse;
use phpbb\storage\entity\StoredFile;
use phpbb\storage\enum\AssetType;
use phpbb\storage\enum\VariantType;
use phpbb\storage\event\FileStoredEvent;
use phpbb\storage\event\FileClaimedEvent;
use phpbb\storage\event\FileDeletedEvent;
use phpbb\storage\exception\FileNotFoundException;
use phpbb\storage\exception\QuotaExceededException;
use phpbb\storage\exception\StorageWriteException;
use phpbb\storage\exception\UploadValidationException;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class StorageService implements StorageServiceInterface
{
    public function __construct(
        private readonly StorageAdapterFactory $adapterFactory,
        private readonly StoredFileRepositoryInterface $fileRepository,
        private readonly QuotaServiceInterface $quotaService,
        private readonly OrphanServiceInterface $orphanService,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly array $allowedExtensions,   // From config
        private readonly int $maxFileSize,            // From config, 0 = unlimited
    ) {}

    public function store(StoreFileRequest $request): FileStoredResponse
    {
        // 1. Validate extension whitelist
        $extension = $this->extractExtension($request->originalFilename);
        if (!in_array($extension, $this->allowedExtensions, true)) {
            throw new UploadValidationException("Extension '$extension' is not allowed.");
        }

        // 2. Validate MIME type (finfo on stream)
        $detectedMime = $this->detectMimeType($request->stream);
        $this->validateMimeVsExtension($detectedMime, $extension);

        // 3. Validate content (first 256 bytes for HTML injection)
        $this->scanContentHeader($request->stream);

        // 4. Check file size limit
        $filesize = $this->getStreamSize($request->stream);
        if ($this->maxFileSize > 0 && $filesize > $this->maxFileSize) {
            throw new UploadValidationException("File exceeds maximum size of {$this->maxFileSize} bytes.");
        }

        // 5. Check quota (global + user + optional forum)
        $this->quotaService->checkQuota(
            $request->uploaderId,
            $filesize,
            $request->forumId
        );

        // 6. Generate UUID v7 filename
        $fileId = $this->generateUuidV7();
        $physicalFilename = $fileId . '.' . $extension;

        // 7. Compute checksum
        $checksum = $this->computeChecksum($request->stream);

        // 8. Write via Flysystem
        $filesystem = $this->adapterFactory->createForAssetType($request->assetType);
        try {
            $filesystem->writeStream($physicalFilename, $request->stream);
        } catch (\Throwable $e) {
            throw new StorageWriteException("Failed to write file: {$e->getMessage()}", 0, $e);
        }

        // 9. Insert DB row (is_orphan = true)
        $storedFile = new StoredFile(
            id: $fileId,
            assetType: $request->assetType,
            visibility: $request->visibility,
            originalFilename: $request->originalFilename,
            physicalFilename: $physicalFilename,
            mimeType: $detectedMime,
            filesize: $filesize,
            checksum: $checksum,
            isOrphan: true,
            parentId: null,
            variantType: null,
            uploaderId: $request->uploaderId,
            forumId: $request->forumId,
            createdAt: time(),
            claimedAt: null,
        );
        $this->fileRepository->save($storedFile);

        // 10. Increment quota AFTER successful write + DB insert
        $this->quotaService->incrementUsage(
            $request->uploaderId,
            $filesize,
            $request->forumId
        );

        // 11. Dispatch event (async variant generation listens here)
        $event = new FileStoredEvent(
            fileId: $fileId,
            assetType: $request->assetType,
            mimeType: $detectedMime,
            filesize: $filesize,
            uploaderId: $request->uploaderId,
        );
        $this->eventDispatcher->dispatch($event);

        return new FileStoredResponse(
            fileId: $fileId,
            physicalFilename: $physicalFilename,
            filesize: $filesize,
            mimeType: $detectedMime,
            events: [$event],
        );
    }

    public function claim(string $fileId, ClaimContext $context): FileClaimedResponse
    {
        $file = $this->fileRepository->find($fileId);
        if ($file === null) {
            throw new FileNotFoundException("File '$fileId' not found.");
        }

        $this->orphanService->claim($fileId, $context);

        $event = new FileClaimedEvent(
            fileId: $fileId,
            claimerType: $context->claimerType,
            claimerId: $context->claimerId,
        );
        $this->eventDispatcher->dispatch($event);

        return new FileClaimedResponse(
            fileId: $fileId,
            claimedAt: time(),
            events: [$event],
        );
    }

    public function delete(string $fileId): FileDeletedResponse
    {
        $file = $this->fileRepository->find($fileId);
        if ($file === null) {
            throw new FileNotFoundException("File '$fileId' not found.");
        }

        // Delete all variants first
        $variants = $this->fileRepository->findByParent($fileId);
        foreach ($variants as $variant) {
            $this->deletePhysicalFile($variant);
            $this->fileRepository->delete($variant->id);
        }

        // Delete the original
        $this->deletePhysicalFile($file);
        $this->fileRepository->delete($fileId);

        // Decrement quota (original + variants total)
        $totalSize = $file->filesize + array_sum(array_map(fn($v) => $v->filesize, $variants));
        $this->quotaService->decrementUsage(
            $file->uploaderId,
            $totalSize,
            $file->forumId
        );

        $event = new FileDeletedEvent(
            fileId: $fileId,
            assetType: $file->assetType,
            filesize: $totalSize,
        );
        $this->eventDispatcher->dispatch($event);

        return new FileDeletedResponse(
            fileId: $fileId,
            events: [$event],
        );
    }

    public function getUrl(string $fileId, ?string $variantType = null): string
    {
        $file = $this->fileRepository->find($fileId);
        if ($file === null) {
            throw new FileNotFoundException("File '$fileId' not found.");
        }

        // If variant requested, look up variant row
        if ($variantType !== null) {
            $variants = $this->fileRepository->findByParent($fileId);
            foreach ($variants as $variant) {
                if ($variant->variantType?->value === $variantType) {
                    return $this->urlGenerator->generateUrl($variant);
                }
            }
            // Variant not generated yet → fall back to original
        }

        return $this->urlGenerator->generateUrl($file);
    }

    // ... retrieve(), exists(), findByOwner() follow same patterns
}
```

### Request/Response DTOs

```php
<?php declare(strict_types=1);

namespace phpbb\storage\dto;

use phpbb\storage\enum\AssetType;
use phpbb\storage\enum\FileVisibility;

final class StoreFileRequest
{
    /**
     * @param resource $stream         File content as a PHP stream
     * @param string $originalFilename User's original filename
     * @param AssetType $assetType     Classification (attachment, avatar, etc.)
     * @param int $uploaderId          User ID of the uploader
     * @param int $forumId             Forum context for quota (0 if not applicable)
     * @param FileVisibility $visibility Public or private serving
     */
    public function __construct(
        public readonly mixed $stream,
        public readonly string $originalFilename,
        public readonly AssetType $assetType,
        public readonly int $uploaderId,
        public readonly int $forumId = 0,
        public readonly FileVisibility $visibility = FileVisibility::Private,
    ) {}
}

final class FileStoredResponse
{
    public function __construct(
        public readonly string $fileId,
        public readonly string $physicalFilename,
        public readonly int $filesize,
        public readonly string $mimeType,
        /** @var object[] Domain events dispatched during this operation */
        public readonly array $events = [],
    ) {}
}

final class ClaimContext
{
    /**
     * @param string $claimerType What is claiming (e.g., 'post', 'user_avatar', 'group_avatar')
     * @param int $claimerId      ID of the claiming entity (post_id, user_id, group_id)
     */
    public function __construct(
        public readonly string $claimerType,
        public readonly int $claimerId,
    ) {}
}

final class FileClaimedResponse
{
    public function __construct(
        public readonly string $fileId,
        public readonly int $claimedAt,
        /** @var object[] */
        public readonly array $events = [],
    ) {}
}

final class FileDeletedResponse
{
    public function __construct(
        public readonly string $fileId,
        /** @var object[] */
        public readonly array $events = [],
    ) {}
}

/**
 * Read-only DTO for file info (returned by retrieve).
 */
final class FileInfo
{
    public function __construct(
        public readonly string $fileId,
        public readonly string $assetType,
        public readonly string $originalFilename,
        public readonly string $mimeType,
        public readonly int $filesize,
        public readonly string $checksum,
        public readonly bool $isOrphan,
        public readonly string $url,
        public readonly int $createdAt,
        public readonly ?int $claimedAt,
    ) {}
}
```

---

## Flysystem Integration

### StorageAdapterFactory

```php
<?php declare(strict_types=1);

namespace phpbb\storage\adapter;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use phpbb\storage\enum\AssetType;

/**
 * Creates Flysystem FilesystemOperator instances per asset type.
 *
 * Configuration maps asset_type → adapter config.
 * Default: LocalFilesystemAdapter with the asset type's storage path.
 * Future: S3Adapter, GcsAdapter via config change — no code change.
 */
final class StorageAdapterFactory
{
    /** @var array<string, FilesystemOperator> Cached instances */
    private array $filesystems = [];

    /**
     * @param string $rootPath       Absolute path to phpBB root (e.g., /var/www/phpbb/)
     * @param array  $adapterConfig  Per-asset-type config overrides
     */
    public function __construct(
        private readonly string $rootPath,
        private readonly array $adapterConfig = [],
    ) {}

    public function createForAssetType(AssetType $assetType): FilesystemOperator
    {
        $key = $assetType->value;

        if (isset($this->filesystems[$key])) {
            return $this->filesystems[$key];
        }

        // Default: LocalFilesystemAdapter pointing to the asset type's storage path
        $storagePath = $this->adapterConfig[$key]['path']
            ?? $this->rootPath . $assetType->storagePath();

        $adapter = new LocalFilesystemAdapter($storagePath);
        $this->filesystems[$key] = new Filesystem($adapter);

        return $this->filesystems[$key];
    }
}
```

**Configuration examples**:

```php
// Default — local filesystem, legacy-compatible paths
$factory = new StorageAdapterFactory(
    rootPath: '/var/www/phpbb/',
    adapterConfig: [
        'attachment' => ['path' => '/var/www/phpbb/files/'],
        'avatar'     => ['path' => '/var/www/phpbb/images/avatars/upload/'],
    ],
);

// Future — S3 for attachments, local for avatars
// Only config change, no code change:
// 'attachment' => ['adapter' => 's3', 'bucket' => 'phpbb-files', 'prefix' => 'attachments/']
```

---

## Upload Pipeline

### Flow

```
StoreFileRequest (stream, filename, asset_type, uploader_id, forum_id, visibility)
    │
    ▼
┌──────────────────────────────────────────────────┐
│  1. VALIDATE EXTENSION                            │
│     Extract extension from original_filename      │
│     Check against allowedExtensions whitelist     │
│     → UploadValidationException if disallowed     │
├──────────────────────────────────────────────────┤
│  2. VALIDATE MIME TYPE                            │
│     finfo_open(FILEINFO_MIME_TYPE) on stream      │
│     Cross-check MIME vs extension                 │
│     → UploadValidationException if mismatch       │
├──────────────────────────────────────────────────┤
│  3. SCAN CONTENT HEADER                           │
│     Read first 256 bytes                          │
│     Check for HTML tags (<html, <script, etc.)    │
│     → UploadValidationException if detected       │
├──────────────────────────────────────────────────┤
│  4. CHECK FILE SIZE                               │
│     Stream size vs per-file max_filesize          │
│     → UploadValidationException if exceeded       │
├──────────────────────────────────────────────────┤
│  5. CHECK QUOTA                                   │
│     QuotaService.checkQuota(uploader_id, size,   │
│       forum_id)                                   │
│     Checks: per-file → user → forum → global     │
│     → QuotaExceededException if any exceeded      │
├──────────────────────────────────────────────────┤
│  6. GENERATE UUID v7 FILENAME                     │
│     $fileId = generateUuidV7()                    │
│     $physical = $fileId . '.' . $extension        │
├──────────────────────────────────────────────────┤
│  7. COMPUTE CHECKSUM                              │
│     SHA-256 of file content                       │
├──────────────────────────────────────────────────┤
│  8. WRITE VIA FLYSYSTEM                           │
│     $filesystem->writeStream($physical, $stream)  │
│     → StorageWriteException on failure            │
├──────────────────────────────────────────────────┤
│  9. INSERT DB ROW                                 │
│     StoredFile with is_orphan=true                │
├──────────────────────────────────────────────────┤
│  10. INCREMENT QUOTA                              │
│      QuotaService.incrementUsage(...)             │
│      (After successful write — no phantom usage)  │
├──────────────────────────────────────────────────┤
│  11. DISPATCH FileStoredEvent                     │
│      → ThumbnailListener picks up if image        │
│      → Other listeners (logging, indexing, etc.)  │
└──────────────────────────────────────────────────┘
    │
    ▼
FileStoredResponse (fileId, physicalFilename, filesize, mimeType, events[])
```

### Validation Preserved from Legacy

| Step | Check | Legacy Source | Criticality |
|------|-------|-------------|-------------|
| 1 | Extension in allowed whitelist | `upload.php:valid_extension()` | **Critical** — prevents executables |
| 2 | MIME re-detection via `finfo` | `filespec.php:move_file()` | **Critical** — catches type spoofing |
| 3 | HTML content in first 256 bytes | `filespec.php:check_content()` | **High** — prevents IE MIME sniffing XSS |
| 4 | Image type vs extension cross-check | `filespec.php:move_file()` | **High** — blocks `.php` renamed to `.jpg` |
| 5 | Double extension stripping | `filespec.php:clean_filename()` | **High** — blocks `file.php.jpg` |
| 6 | Randomized physical filenames (UUID) | `filespec.php:clean_filename('unique')` | **Critical** — no user-controlled paths |

---

## Variant System (Async)

### Architecture

```
Upload completes → FileStoredEvent dispatched
                         │
                         ▼
               ThumbnailListener.onFileStored()
                         │
                    Is image?
                    Size > 12KB?
                    Supported format?
                         │
                     ┌───┴───┐
                     │  No   │ → return (no variant)
                     └───┬───┘
                         │ Yes
                         ▼
               Generate thumbnail (GD)
               max 400px width, proportional
               JPEG quality 90
                         │
                         ▼
               StorageService.store() with parent_id
               → Creates StoredFile row:
                 parent_id = original.id
                 variant_type = 'thumbnail'
                 is_orphan = false (variants auto-claimed)
                         │
                         ▼
               VariantGeneratedEvent dispatched
```

### VariantGeneratorInterface

```php
<?php declare(strict_types=1);

namespace phpbb\storage\variant;

use phpbb\storage\entity\StoredFile;

interface VariantGeneratorInterface
{
    /** Variant type identifier (e.g., 'thumbnail', 'webp') */
    public function getType(): string;

    /** Whether this generator can process the given file */
    public function supports(StoredFile $file): bool;

    /**
     * Generate the variant from the source file.
     *
     * @param string $sourcePath Local path to the original file
     * @return VariantResult     Generated file info (stream, mime, dimensions)
     */
    public function generate(string $sourcePath): VariantResult;
}

final class VariantResult
{
    public function __construct(
        /** @var resource */
        public readonly mixed $stream,
        public readonly string $mimeType,
        public readonly int $width,
        public readonly int $height,
    ) {}
}
```

### ThumbnailGenerator

```php
<?php declare(strict_types=1);

namespace phpbb\storage\variant;

use phpbb\storage\entity\StoredFile;

final class ThumbnailGenerator implements VariantGeneratorInterface
{
    public function __construct(
        private readonly int $maxWidth = 400,
        private readonly int $minSourceSize = 12000,  // Skip tiny images
        private readonly int $jpegQuality = 90,
    ) {}

    public function getType(): string
    {
        return 'thumbnail';
    }

    public function supports(StoredFile $file): bool
    {
        return $file->isImage()
            && $file->filesize > $this->minSourceSize
            && in_array(
                $this->getExtension($file->physicalFilename),
                ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                true
            );
    }

    public function generate(string $sourcePath): VariantResult
    {
        [$origWidth, $origHeight, $imageType] = getimagesize($sourcePath);

        if ($origWidth <= $this->maxWidth) {
            // Image is already small enough — skip
            throw new \RuntimeException('Image does not need thumbnail.');
        }

        $ratio = $this->maxWidth / $origWidth;
        $newWidth = $this->maxWidth;
        $newHeight = (int) round($origHeight * $ratio);

        $source = $this->createImageFromFile($sourcePath, $imageType);
        $thumb = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG/GIF
        if ($imageType === IMAGETYPE_PNG || $imageType === IMAGETYPE_GIF) {
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
        }

        imagecopyresampled($thumb, $source, 0, 0, 0, 0, $newWidth, $newHeight, $origWidth, $origHeight);
        imagedestroy($source);

        // Write to temp stream
        $stream = fopen('php://temp', 'r+');
        imagejpeg($thumb, $stream, $this->jpegQuality);
        imagedestroy($thumb);
        rewind($stream);

        return new VariantResult(
            stream: $stream,
            mimeType: 'image/jpeg',
            width: $newWidth,
            height: $newHeight,
        );
    }

    private function createImageFromFile(string $path, int $type): \GdImage
    {
        return match ($type) {
            IMAGETYPE_JPEG => imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => imagecreatefrompng($path),
            IMAGETYPE_GIF  => imagecreatefromgif($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default        => throw new \RuntimeException("Unsupported image type: $type"),
        };
    }

    private function getExtension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
}
```

### ThumbnailListener

```php
<?php declare(strict_types=1);

namespace phpbb\storage\variant;

use phpbb\storage\contract\StorageServiceInterface;
use phpbb\storage\dto\StoreFileRequest;
use phpbb\storage\enum\AssetType;
use phpbb\storage\enum\FileVisibility;
use phpbb\storage\event\FileStoredEvent;
use phpbb\storage\event\VariantGeneratedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ThumbnailListener
{
    public function __construct(
        private readonly StorageServiceInterface $storage,
        private readonly ThumbnailGenerator $generator,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function onFileStored(FileStoredEvent $event): void
    {
        $file = $this->storage->retrieve($event->fileId);

        if (!$this->generator->supports($file)) {
            return;
        }

        // Get local path for GD processing
        // (Flysystem LocalAdapter: the physical file on disk)
        try {
            $result = $this->generator->generate(
                $this->resolveLocalPath($file)
            );
        } catch (\Throwable) {
            // Thumbnail generation is non-critical — log and continue
            return;
        }

        // Store variant as a child StoredFile
        $variantRequest = new StoreFileRequest(
            stream: $result->stream,
            originalFilename: 'thumb_' . $file->originalFilename,
            assetType: $file->assetType,
            uploaderId: $file->uploaderId,
            forumId: $file->forumId,
            visibility: $file->visibility,
        );

        // Internal store with parent_id — variant is auto-claimed
        $this->storeVariant($variantRequest, $file->id, 'thumbnail');
    }
}
```

### On-Demand Fallback

If a variant URL is requested but no variant row exists yet (e.g., listener hasn't run), the `UrlGenerator` returns the original file URL. Future enhancement: the file serving controller can trigger on-demand generation and redirect.

---

## Quota System

### QuotaServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\storage\contract;

interface QuotaServiceInterface
{
    /**
     * Check if the upload would be allowed under all applicable quotas.
     * Does NOT modify counters — read-only check.
     *
     * Check order: per-file limit → user quota → forum quota → global quota.
     *
     * @throws \phpbb\storage\exception\QuotaExceededException
     */
    public function checkQuota(int $uploaderId, int $sizeBytes, int $forumId = 0): void;

    /**
     * Atomically increment usage counters for all applicable scopes.
     * Called AFTER successful file write.
     */
    public function incrementUsage(int $uploaderId, int $sizeBytes, int $forumId = 0): void;

    /**
     * Decrement usage counters for all applicable scopes.
     * Called AFTER successful file delete.
     */
    public function decrementUsage(int $uploaderId, int $sizeBytes, int $forumId = 0): void;

    /**
     * Recalculate actual usage from SUM(filesize) and correct counter drift.
     * Run by cron job or triggered manually by admin.
     */
    public function reconcile(?string $scope = null, ?int $scopeId = null): void;

    /**
     * Get current usage for a scope.
     */
    public function getUsage(string $scope, int $scopeId): int;
}
```

### Atomic Reservation SQL

```sql
-- Check + reserve in one atomic statement (no TOCTOU race)
UPDATE phpbb_storage_quotas
SET used_bytes = used_bytes + :size,
    updated_at = :now
WHERE scope = :scope
  AND scope_id = :scope_id
  AND (max_bytes = 0 OR used_bytes + :size <= max_bytes);

-- affected_rows = 1 → success (quota not exceeded)
-- affected_rows = 0 → quota exceeded → throw QuotaExceededException
```

### Reconciliation

```php
// Cron job — runs daily
public function reconcile(?string $scope = null, ?int $scopeId = null): void
{
    // Calculate actual usage from stored_files table
    $actualUsage = $this->fileRepository->sumFilesizeByScope($scope, $scopeId);

    // Get current counter
    $quota = $this->quotaRepository->findByScope($scope, $scopeId);

    if ($quota !== null && $quota->usedBytes !== $actualUsage) {
        $this->quotaRepository->updateUsedBytes($scope, $scopeId, $actualUsage);

        $this->eventDispatcher->dispatch(new QuotaReconciledEvent(
            scope: $scope,
            scopeId: $scopeId,
            oldUsedBytes: $quota->usedBytes,
            newUsedBytes: $actualUsage,
        ));
    }
}
```

---

## File Serving

### Strategy

| File Type | Visibility | Serving Method | Cache-Control | Auth |
|-----------|-----------|----------------|---------------|------|
| Avatars | Public | Direct nginx static URL | `public, max-age=31536000` | None |
| Attachments (images) | Private | PHP auth → X-Accel-Redirect | `private` | Session + ACL |
| Attachments (other) | Private | PHP auth → X-Accel-Redirect | `private` | Session + ACL |
| Fallback (no X-Accel) | Private | PHP reads file via Flysystem → streams response | `private` | Session + ACL |

### UrlGenerator

```php
<?php declare(strict_types=1);

namespace phpbb\storage\service;

use phpbb\storage\contract\UrlGeneratorInterface;
use phpbb\storage\entity\StoredFile;
use phpbb\storage\enum\FileVisibility;

final class UrlGenerator implements UrlGeneratorInterface
{
    public function __construct(
        private readonly string $publicBaseUrl,     // e.g., '/images/avatars/upload'
        private readonly string $privateBaseUrl,    // e.g., '/download'
        private readonly string $phpbbRootUrl,      // e.g., '/'
    ) {}

    public function generateUrl(StoredFile $file): string
    {
        if ($file->visibility === FileVisibility::Public) {
            // Direct nginx URL — no PHP involved
            return $this->phpbbRootUrl
                . $file->assetType->storagePath()
                . $file->physicalFilename;
        }

        // Private — proxy through PHP auth controller
        return $this->privateBaseUrl . '/' . $file->id;
    }
}
```

### File Server Controller (Download Endpoint)

```php
<?php declare(strict_types=1);

namespace phpbb\storage\service;

use phpbb\storage\contract\StorageServiceInterface;
use phpbb\storage\entity\StoredFile;
use phpbb\storage\enum\FileVisibility;

/**
 * HTTP controller for serving private files.
 * Auth middleware (outside storage) enforces permissions BEFORE this is called.
 */
final class FileServerController
{
    public function __construct(
        private readonly StorageServiceInterface $storage,
        private readonly StorageAdapterFactory $adapterFactory,
        private readonly ?string $accelRedirectPrefix,  // e.g., '/protected-files/'
    ) {}

    public function serve(string $fileId, ?string $variantType = null): void
    {
        $file = $this->storage->retrieve($fileId);

        // Resolve to variant if requested
        if ($variantType !== null) {
            $file = $this->resolveVariant($file, $variantType) ?? $file;
        }

        // Security headers
        header('X-Content-Type-Options: nosniff');

        if ($file->isImage()) {
            header('Content-Type: ' . $file->mimeType);
            header('Content-Disposition: inline; filename="' . $this->sanitizeFilename($file->originalFilename) . '"');
        } else {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $this->sanitizeFilename($file->originalFilename) . '"');
            header('X-Download-Options: noopen');
        }

        // Cache control
        if ($file->visibility === FileVisibility::Public) {
            header('Cache-Control: public, max-age=31536000');
        } else {
            header('Cache-Control: private, no-store');
        }

        header('Content-Length: ' . $file->filesize);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $file->createdAt) . ' GMT');

        // 304 Not Modified check
        if ($this->isNotModified($file)) {
            http_response_code(304);
            return;
        }

        // X-Accel-Redirect if configured (nginx serves the file, not PHP)
        if ($this->accelRedirectPrefix !== null) {
            header('X-Accel-Redirect: ' . $this->accelRedirectPrefix . $file->physicalFilename);
            return;
        }

        // Fallback: PHP streams the file via Flysystem
        $filesystem = $this->adapterFactory->createForAssetType($file->assetType);
        $stream = $filesystem->readStream($file->physicalFilename);
        fpassthru($stream);
        fclose($stream);
    }
}
```

---

## Orphan Lifecycle

### Flow

```
1. User starts composing a post / uploading an avatar
   │
   ▼
2. AJAX/form upload → StorageService.store()
   → DB row: is_orphan=true, created_at=NOW
   → Quota reserved (counted against user limit)
   → Returns fileId to client
   │
   ▼
3a. User submits form → Consumer plugin calls StorageService.claim()
    → DB: is_orphan=false, claimed_at=NOW
    → FileClaimedEvent dispatched
    │
    OR
    │
3b. User abandons form → file stays orphan
    │
    ▼ (after TTL)
4. OrphanCleanupJob (cron, hourly)
   → SELECT WHERE is_orphan=true AND created_at < NOW()-86400
   → Dispatch OrphanCleanupEvent (listeners can intervene)
   → For each: delete physical file + variants via Flysystem
   → DELETE DB rows
   → Decrement quota counters
```

### OrphanServiceInterface

```php
<?php declare(strict_types=1);

namespace phpbb\storage\contract;

use phpbb\storage\dto\ClaimContext;

interface OrphanServiceInterface
{
    /**
     * Claim an orphan file — associates it with an owner.
     * Validates that the file is actually an orphan and owned by the claiming user.
     *
     * @throws \phpbb\storage\exception\FileNotFoundException
     * @throws \phpbb\storage\exception\OrphanClaimException If not orphan or ownership mismatch
     */
    public function claim(string $fileId, ClaimContext $context): void;

    /**
     * Find and delete all orphan files older than the configured TTL.
     * Called by the cron job.
     *
     * @return int Number of orphans cleaned up
     */
    public function cleanupExpired(): int;
}
```

### OrphanCleanupJob

```php
<?php declare(strict_types=1);

namespace phpbb\storage\orphan;

use phpbb\storage\contract\OrphanServiceInterface;
use phpbb\storage\contract\StorageServiceInterface;
use phpbb\storage\contract\StoredFileRepositoryInterface;
use phpbb\storage\event\OrphanCleanupEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class OrphanCleanupJob
{
    private const DEFAULT_TTL = 86400; // 24 hours

    public function __construct(
        private readonly StoredFileRepositoryInterface $fileRepository,
        private readonly StorageServiceInterface $storage,
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly int $orphanTtl = self::DEFAULT_TTL,
    ) {}

    /**
     * Run by phpBB cron, typically hourly.
     */
    public function run(): int
    {
        $cutoff = time() - $this->orphanTtl;
        $orphans = $this->fileRepository->findOrphansOlderThan($cutoff);

        if (empty($orphans)) {
            return 0;
        }

        $fileIds = array_map(fn($f) => $f->id, $orphans);

        // Dispatch event — listeners can intervene (e.g., extend TTL for drafts)
        $event = new OrphanCleanupEvent(fileIds: $fileIds);
        $this->eventDispatcher->dispatch($event);

        // Delete each orphan via StorageService (handles variants, quota)
        $cleaned = 0;
        foreach ($event->fileIds as $fileId) {
            try {
                $this->storage->delete($fileId);
                $cleaned++;
            } catch (\Throwable) {
                // Log and continue — don't let one failure block the batch
            }
        }

        return $cleaned;
    }
}
```

---

## Domain Events Catalog

```php
<?php declare(strict_types=1);

namespace phpbb\storage\event;

use phpbb\storage\enum\AssetType;

/** Dispatched after a file is successfully stored (upload complete). */
final class FileStoredEvent
{
    public function __construct(
        public readonly string $fileId,
        public readonly AssetType $assetType,
        public readonly string $mimeType,
        public readonly int $filesize,
        public readonly int $uploaderId,
    ) {}
}

/** Dispatched after an orphan file is claimed by a consumer. */
final class FileClaimedEvent
{
    public function __construct(
        public readonly string $fileId,
        public readonly string $claimerType,
        public readonly int $claimerId,
    ) {}
}

/** Dispatched after a file (and its variants) is deleted. */
final class FileDeletedEvent
{
    public function __construct(
        public readonly string $fileId,
        public readonly AssetType $assetType,
        public readonly int $filesize,
    ) {}
}

/** Dispatched after a variant is generated and stored. */
final class VariantGeneratedEvent
{
    public function __construct(
        public readonly string $fileId,
        public readonly string $parentId,
        public readonly string $variantType,
    ) {}
}

/** Dispatched when an upload is rejected due to quota. */
final class QuotaExceededEvent
{
    public function __construct(
        public readonly string $scope,
        public readonly int $scopeId,
        public readonly int $attemptedSize,
        public readonly int $currentUsage,
        public readonly int $maxBytes,
    ) {}
}

/** Dispatched after cron reconciliation corrects counter drift. */
final class QuotaReconciledEvent
{
    public function __construct(
        public readonly string $scope,
        public readonly int $scopeId,
        public readonly int $oldUsedBytes,
        public readonly int $newUsedBytes,
    ) {}
}

/** Dispatched before orphan files are deleted. Listeners can modify fileIds to skip specific files. */
final class OrphanCleanupEvent
{
    /** @var string[] File IDs to be cleaned up (mutable — listeners can remove entries) */
    public array $fileIds;

    public function __construct(array $fileIds)
    {
        $this->fileIds = $fileIds;
    }
}
```

---

## Repository Contracts

### StoredFileRepositoryInterface

```php
<?php declare(strict_types=1);

namespace phpbb\storage\contract;

use phpbb\storage\entity\StoredFile;

interface StoredFileRepositoryInterface
{
    /**
     * Find a stored file by ID.
     */
    public function find(string $id): ?StoredFile;

    /**
     * Find all variants of a parent file.
     *
     * @return StoredFile[]
     */
    public function findByParent(string $parentId): array;

    /**
     * Find all files owned by an asset_type + uploader combination.
     *
     * @return StoredFile[]
     */
    public function findByOwner(string $assetType, int $uploaderId): array;

    /**
     * Find unclaimed (orphan) files older than the given timestamp.
     *
     * @return StoredFile[]
     */
    public function findOrphansOlderThan(int $createdBefore): array;

    /**
     * Persist a stored file record.
     */
    public function save(StoredFile $file): void;

    /**
     * Delete a stored file record by ID.
     */
    public function delete(string $id): void;

    /**
     * Mark a file as claimed (is_orphan=false, claimed_at=timestamp).
     */
    public function markClaimed(string $id, int $claimedAt): void;

    /**
     * Calculate total filesize for quota reconciliation.
     * Filters by scope: 'global' (all files), 'user' (by uploader_id), 'forum' (by forum_id).
     */
    public function sumFilesizeByScope(string $scope, int $scopeId): int;
}
```

### StorageQuotaRepositoryInterface

```php
<?php declare(strict_types=1);

namespace phpbb\storage\contract;

use phpbb\storage\entity\StorageQuota;

interface StorageQuotaRepositoryInterface
{
    /**
     * Find quota record for a specific scope.
     */
    public function findByScope(string $scope, int $scopeId): ?StorageQuota;

    /**
     * Atomically increment used_bytes. Returns true if successful (within limit).
     * Returns false if the increment would exceed max_bytes.
     */
    public function atomicIncrement(string $scope, int $scopeId, int $bytes): bool;

    /**
     * Decrement used_bytes (floor at 0).
     */
    public function decrement(string $scope, int $scopeId, int $bytes): void;

    /**
     * Update used_bytes to an exact value (for reconciliation).
     */
    public function updateUsedBytes(string $scope, int $scopeId, int $usedBytes): void;

    /**
     * Create or update a quota record.
     */
    public function save(StorageQuota $quota): void;
}
```

---

## Database Schema

```sql
-- ===========================================================================
-- phpbb_stored_files — unified file metadata for all asset types
-- ===========================================================================
CREATE TABLE phpbb_stored_files (
    id                 BINARY(16)       NOT NULL,
    asset_type         VARCHAR(32)      NOT NULL,             -- 'attachment', 'avatar', 'export'
    original_filename  VARCHAR(255)     NOT NULL,
    physical_filename  VARCHAR(255)     NOT NULL,             -- UUID-based: {uuid}.{ext}
    mime_type          VARCHAR(100)     NOT NULL,
    filesize           BIGINT UNSIGNED  NOT NULL,
    checksum           VARCHAR(64)      NOT NULL,             -- SHA-256 hex
    is_orphan          TINYINT(1)       NOT NULL DEFAULT 1,
    parent_id          BINARY(16)       DEFAULT NULL,         -- NULL for originals, parent UUID for variants
    variant_type       VARCHAR(32)      DEFAULT NULL,         -- 'thumbnail', 'webp', etc.
    uploader_id        INT UNSIGNED     NOT NULL DEFAULT 0,
    forum_id           INT UNSIGNED     NOT NULL DEFAULT 0,   -- 0 if not forum-scoped
    visibility         ENUM('public','private') NOT NULL DEFAULT 'private',
    created_at         INT UNSIGNED     NOT NULL,
    claimed_at         INT UNSIGNED     DEFAULT NULL,

    PRIMARY KEY (id),
    INDEX idx_orphan_created (is_orphan, created_at),         -- Orphan cleanup queries
    INDEX idx_parent (parent_id),                             -- Variant lookups
    INDEX idx_asset_uploader (asset_type, uploader_id),       -- findByOwner queries
    INDEX idx_forum (forum_id)                                -- Per-forum quota reconciliation
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ===========================================================================
-- phpbb_storage_quotas — denormalized usage counters per scope
-- ===========================================================================
CREATE TABLE phpbb_storage_quotas (
    id          INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    scope       ENUM('global','user','forum') NOT NULL,
    scope_id    INT UNSIGNED     NOT NULL DEFAULT 0,          -- 0 for global, user_id, forum_id
    used_bytes  BIGINT UNSIGNED  NOT NULL DEFAULT 0,
    max_bytes   BIGINT UNSIGNED  NOT NULL DEFAULT 0,          -- 0 = unlimited
    updated_at  INT UNSIGNED     NOT NULL,

    PRIMARY KEY (id),
    UNIQUE INDEX idx_scope (scope, scope_id)                  -- One row per scope+id
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**UUID v7 storage**: `BINARY(16)` — stored as raw bytes for index efficiency. Application converts to/from hex string (`bin2hex` / `hex2bin`) with optional dash formatting for display.

**Sample data**:

```sql
-- Global quota: 50MB
INSERT INTO phpbb_storage_quotas (scope, scope_id, used_bytes, max_bytes, updated_at)
VALUES ('global', 0, 0, 52428800, UNIX_TIMESTAMP());

-- Per-user quota: 10MB for user 42
INSERT INTO phpbb_storage_quotas (scope, scope_id, used_bytes, max_bytes, updated_at)
VALUES ('user', 42, 0, 10485760, UNIX_TIMESTAMP());

-- Per-forum quota: 100MB for forum 7
INSERT INTO phpbb_storage_quotas (scope, scope_id, used_bytes, max_bytes, updated_at)
VALUES ('forum', 7, 0, 104857600, UNIX_TIMESTAMP());
```

---

## Plugin Consumer Contract

### How AttachmentPlugin Uses Storage

```php
<?php declare(strict_types=1);

// Located in: phpbb\threads\plugin\AttachmentPlugin

namespace phpbb\threads\plugin;

use phpbb\storage\contract\StorageServiceInterface;
use phpbb\storage\dto\ClaimContext;
use phpbb\threads\event\PostCreatedEvent;
use phpbb\threads\event\PostHardDeletedEvent;

final class AttachmentPlugin
{
    public function __construct(
        private readonly StorageServiceInterface $storage,
    ) {}

    /**
     * On post submit — claim orphan attachment files.
     */
    public function onPostCreated(PostCreatedEvent $event): void
    {
        foreach ($event->attachmentFileIds as $fileId) {
            $this->storage->claim($fileId, new ClaimContext(
                claimerType: 'post',
                claimerId: $event->postId,
            ));
        }
    }

    /**
     * On post hard-delete — delete associated attachment files.
     */
    public function onPostDeleted(PostHardDeletedEvent $event): void
    {
        $files = $this->storage->findByOwner('attachment', $event->posterId);
        foreach ($files as $file) {
            $this->storage->delete($file->id);
        }
    }

    /**
     * Get download URL for an attachment (private, auth-gated).
     */
    public function getDownloadUrl(string $fileId, ?string $variantType = null): string
    {
        return $this->storage->getUrl($fileId, $variantType);
    }
}
```

### How AvatarPlugin Uses Storage

```php
<?php declare(strict_types=1);

// Located in: phpbb\user\plugin\AvatarPlugin

namespace phpbb\user\plugin;

use phpbb\storage\contract\StorageServiceInterface;
use phpbb\storage\dto\ClaimContext;
use phpbb\storage\dto\StoreFileRequest;
use phpbb\storage\enum\AssetType;
use phpbb\storage\enum\FileVisibility;

final class AvatarPlugin
{
    public function __construct(
        private readonly StorageServiceInterface $storage,
    ) {}

    /**
     * Upload and immediately claim a new avatar.
     * Avatars skip the orphan phase — claim happens right after store.
     *
     * @param resource $stream
     */
    public function uploadAvatar(mixed $stream, string $filename, int $userId): string
    {
        $response = $this->storage->store(new StoreFileRequest(
            stream: $stream,
            originalFilename: $filename,
            assetType: AssetType::Avatar,
            uploaderId: $userId,
            visibility: FileVisibility::Public,
        ));

        // Immediately claim — no orphan phase for avatars
        $this->storage->claim($response->fileId, new ClaimContext(
            claimerType: 'user_avatar',
            claimerId: $userId,
        ));

        return $response->fileId;
    }

    /**
     * Replace an existing avatar — delete old, upload new.
     */
    public function replaceAvatar(mixed $stream, string $filename, int $userId): string
    {
        // Delete old avatar(s) for this user
        $existing = $this->storage->findByOwner('avatar', $userId);
        foreach ($existing as $file) {
            $this->storage->delete($file->id);
        }

        return $this->uploadAvatar($stream, $filename, $userId);
    }

    /**
     * Get public URL for a user's avatar.
     */
    public function getAvatarUrl(int $userId): ?string
    {
        $files = $this->storage->findByOwner('avatar', $userId);

        if (empty($files)) {
            return null;
        }

        return $this->storage->getUrl($files[0]->id);
    }
}
```

---

## Integration Points

### With Threads (via AttachmentPlugin)

| Trigger | Event | Storage Action | Direction |
|---------|-------|---------------|-----------|
| Post form upload | — (direct API call) | `storage.store()` → returns fileId to JS | Threads → Storage |
| Post submit | `PostCreatedEvent` | `storage.claim(fileId, 'post', postId)` for each | Threads → Storage |
| Post hard-delete | `PostHardDeletedEvent` | `storage.delete(fileId)` for each | Threads → Storage |
| Post view | — (response decorator) | `storage.getUrl(fileId, 'thumbnail')` | Threads → Storage |

### With User (via AvatarPlugin)

| Trigger | Event | Storage Action | Direction |
|---------|-------|---------------|-----------|
| Profile avatar upload | — (direct API call) | `storage.store()` + `storage.claim()` | User → Storage |
| Avatar change | `ProfileUpdatedEvent` | Delete old + store/claim new | User → Storage |
| User deletion | `UserDeletedEvent` | `storage.delete()` all user files | User → Storage |
| Profile view | — (response data) | `storage.getUrl(fileId)` → public URL | User → Storage |

### With Auth (middleware)

| Trigger | Flow | Storage Role |
|---------|------|-------------|
| Download private file | HTTP → auth middleware → `FileServerController.serve()` | Storage serves file after auth passes |
| Download public file | HTTP → nginx direct | Storage not involved (static path) |

### With Hierarchy (forum context)

| Interaction | Data | Purpose |
|-------------|------|---------|
| Upload with forum context | `forum_id` on `StoreFileRequest` | Per-forum quota enforcement |
| Quota admin | Forum ID → quota scope | Admin sets per-forum storage limits |

---

## Security

| Measure | Implementation | Why Critical |
|---------|---------------|-------------|
| Extension whitelist | Config-driven allow list checked on every upload | Prevents executable file uploads |
| MIME validation | `finfo` detection after write, cross-checked vs extension | Catches type spoofing (`.php` renamed to `.jpg`) |
| Content header scan | First 256 bytes checked for `<html>`, `<script>`, etc. | Prevents IE MIME-sniffing XSS |
| UUID filenames | All physical filenames are UUID-based; no user input in paths | Eliminates directory traversal and path injection |
| Flysystem path safety | Flysystem normalizes paths, rejects `..` components | Defense in depth for path traversal |
| `X-Content-Type-Options: nosniff` | Set on all served files | Prevents browser MIME sniffing |
| `Content-Type: application/octet-stream` | Forced for non-image downloads | Prevents browser execution of downloaded files |
| `Content-Disposition: attachment` | For non-image files | Forces download instead of inline rendering |
| `X-Download-Options: noopen` | For non-image files | Prevents IE "Open" button |
| X-Accel-Redirect | Internal nginx location; path never exposed to browser | Private file paths hidden from clients |
| Orphan ownership check | `uploader_id` verified on claim and orphan access | Prevents claiming someone else's uploads |
| Atomic quota SQL | `UPDATE WHERE used_bytes + size <= max_bytes` | Prevents quota bypass via concurrent uploads |

---

## Migration / Legacy Compatibility

### Strategy: Zero-Copy + Metadata Backfill

1. **Phase 1: Schema addition** — Create `phpbb_stored_files` and `phpbb_storage_quotas` tables alongside existing tables. Legacy code untouched.

2. **Phase 2: Metadata backfill** (background migration job):
   ```sql
   -- For each legacy attachment:
   INSERT INTO phpbb_stored_files (id, asset_type, original_filename, physical_filename,
       mime_type, filesize, checksum, is_orphan, uploader_id, forum_id, visibility,
       created_at, claimed_at)
   SELECT UUID_V7(), 'attachment', real_filename, physical_filename,
       mimetype, filesize, '', CASE WHEN is_orphan THEN 1 ELSE 0 END,
       poster_id, topic_id_to_forum_id, 'private',
       filetime, CASE WHEN is_orphan = 0 THEN filetime ELSE NULL END
   FROM phpbb_attachments;

   -- For each legacy avatar (user_avatar_type = 'avatar.driver.upload'):
   INSERT INTO phpbb_stored_files (id, asset_type, original_filename, physical_filename,
       mime_type, filesize, checksum, is_orphan, uploader_id, visibility,
       created_at, claimed_at)
   VALUES (UUID_V7(), 'avatar', '', '{salt}_{user_id}.{ext}',
       '', 0, '', 0, user_id, 'public',
       user_regdate, user_regdate);
   ```

3. **Phase 3: New code writes via StorageService**. Legacy `phpbb_attachments` table remains for AttachmentPlugin's own domain data (`download_count`, `attach_comment`, etc.). AttachmentPlugin references `stored_files.id` via a FK.

4. **Phase 4: Optional physical migration** — Admin-triggered job moves files from `files/` flat directory to new naming convention if desired. Not required — Flysystem LocalAdapter works with existing flat paths.

### Existing Directory Layout (Preserved)

```
files/                          ← Attachment files (flat, legacy-compatible)
    42_a1b2c3d4e5f6.jpg         ← Legacy: {user_id}_{md5}
    0193a5e7-...-.jpg            ← New: UUID.ext (same directory)

images/avatars/upload/          ← Avatar files (flat, legacy-compatible)
    8fe4_42.jpg                  ← Legacy: {salt}_{user_id}.ext
    0193a5e8-...-.jpg            ← New: UUID.ext (same directory)
```

---

## Design Decisions

| # | Decision | Rationale | ADR |
|---|----------|-----------|-----|
| 1 | Single `stored_files` table | Simplest schema; no JOINs for file ops; plugin-specific data in plugin tables | ADR-001 |
| 2 | UUID v7 as BINARY(16) | Non-enumerable, time-sortable, server-generated, compact storage | ADR-002 |
| 3 | Flysystem for storage abstraction | Battle-tested, S3/GCS adapters available, reduces testing burden | ADR-003 |
| 4 | Async variant generation via events | Keeps upload path fast; thumbnails generated by listener after FileStoredEvent | ADR-004 |
| 5 | Counter + reconciliation quotas | O(1) check, atomic SQL prevents races, cron recalculates as safety net | ADR-005 |
| 6 | Hybrid file serving | Public (direct nginx) + private (PHP auth + X-Accel) — two security models | ADR-006 |
| 7 | Flat directory layout | Zero migration for existing files; UUID filenames prevent collisions | ADR-007 |

See [decision-log.md](decision-log.md) for full MADR-format ADRs.

---

## Concrete Examples

### Example 1: User Uploads an Attachment to a Post

**Given** a registered user composing a reply in forum 7, with 2MB used of 10MB user quota
**When** the user uploads a 500KB JPEG image via the post form
**Then**:
1. `StorageService.store()` validates extension (jpg ✓), MIME (image/jpeg ✓), content scan (no HTML ✓), size (500KB < max ✓)
2. `QuotaService.checkQuota(userId=42, size=500000, forumId=7)` passes — user has 8MB remaining
3. File written to `files/0193a5e7-8b3c-7def-abcd-1234567890ab.jpg` via Flysystem LocalAdapter
4. DB row inserted: `is_orphan=true`, `asset_type='attachment'`, `visibility='private'`
5. Quota incremented: user 42 → 2.5MB used
6. `FileStoredEvent` dispatched → `ThumbnailListener` generates 400px-wide thumbnail → stored as variant with `parent_id`
7. Client receives `fileId` → displays preview
8. On form submit, `AttachmentPlugin.onPostCreated()` calls `storage.claim(fileId, 'post', postId)` → `is_orphan=false`

### Example 2: User Changes Avatar

**Given** user 42 has an existing avatar (stored_file `abc...`)
**When** the user uploads a new 80KB PNG avatar
**Then**:
1. `AvatarPlugin.replaceAvatar()` calls `storage.delete('abc...')` — old avatar + variants removed
2. `storage.store()` with `AssetType::Avatar`, `FileVisibility::Public`
3. File written to `images/avatars/upload/0193a5e8-1234-7890-abcd-fedcba987654.png`
4. `storage.claim()` called immediately (no orphan phase for avatars)
5. Public URL: `/images/avatars/upload/0193a5e8-1234-7890-abcd-fedcba987654.png` — served directly by nginx

### Example 3: Orphan Cleanup After Abandoned Post

**Given** user 99 uploaded 3 files while composing a reply 26 hours ago, then closed the browser
**When** the hourly `OrphanCleanupJob` runs
**Then**:
1. Query: `SELECT * FROM phpbb_stored_files WHERE is_orphan=1 AND created_at < (NOW - 86400)` → finds 3 files
2. `OrphanCleanupEvent` dispatched with `fileIds = [f1, f2, f3]` — no listener intervenes
3. For each file: Flysystem deletes physical file + any variant files, DB rows deleted
4. Quota decremented for user 99: `-{totalSize}` bytes from user quota, global quota, forum quota

---

## Out of Scope

- **Content association logic** — which post owns which attachment is AttachmentPlugin's concern, not storage's
- **Permission/ACL checks** — auth middleware enforces download permissions; storage trusts the caller
- **CDN proxy / signed URLs** — deferred to cloud adapter implementation
- **Image manipulation beyond thumbnails** — watermarking, cropping, etc. are future `VariantGeneratorInterface` implementations
- **Chunked upload / Plupload integration** — upload chunking is a transport-layer concern handled before `StorageService.store()` is called
- **Download counting** — consumer responsibility (AttachmentPlugin tracks `download_count` in its own metadata table)
- **Extension group management** — ACP/admin concern; storage receives the allowed extension list as config
- **File deduplication** — rated Low severity; complicates ownership/deletion model
- **Group avatar specifics** — deferred pending `owner_type = 'group_avatar'` confirmation
- **Virus/malware scanning** — future `UploadPipeline` validator; architecture supports it via additional validation step

---

## Success Criteria

1. **Any consumer can store/claim/delete files** through `StorageServiceInterface` without knowledge of the physical storage location or filesystem details
2. **Quota is enforced atomically** — concurrent uploads cannot bypass limits; reconciliation detects and corrects drift
3. **Orphan files are cleaned up within 25 hours** — no more "abandoned uploads persist forever" legacy gap
4. **Thumbnails are generated asynchronously** — upload latency is not affected by image processing
5. **Public files are served at nginx speed** — no PHP overhead for avatar serving
6. **Private files require authentication** — X-Accel-Redirect ensures PHP handles auth while nginx handles I/O
7. **Existing files continue to work** — legacy `files/` and `images/avatars/upload/` paths are preserved; zero-copy migration
