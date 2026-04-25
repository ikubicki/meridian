# Gap Analysis — M5b Storage Service

Date: 2026-04-25

## Task Characteristics

| Characteristic | Value | Rationale |
|----------------|-------|-----------|
| has_reproducible_defect | false | New feature, no existing defect |
| modifies_existing_code | true | DomainEvent.entityId type change; services.yaml; composer.json |
| creates_new_entities | true | StoredFile, StorageQuota, 7 events, 5 exceptions, 6 interfaces |
| involves_data_operations | true | phpbb_stored_files + phpbb_storage_quotas tables |
| ui_heavy | false | Backend-only service; REST endpoint for uploads |

**Risk Level**: Medium-High

Rationale:
- Breaking change to `DomainEvent::entityId` (affects all existing modules and their tests)
- New external dependency (`league/flysystem`) must be installed
- UUID v7 generation from `random_bytes()` (no library)
- File-serving security (private files via X-Accel-Redirect)
- Quota atomicity (`UPDATE WHERE used_bytes + ? <= max_bytes`)
- Multipart file upload handling in REST controller

---

## Decisions Needed

### Critical
_(none — user resolved all scope/architecture decisions in Phase 1 clarifications)_

### Important
_(none — research ADR-001 through ADR-007 fully accepted by project owner)_

---

## Detailed Gap List

### New Files to Create

#### Core Module
| File | Type |
|------|------|
| src/phpbb/storage/StorageService.php | Facade service |
| src/phpbb/storage/Contract/StorageServiceInterface.php | Interface |
| src/phpbb/storage/Contract/QuotaServiceInterface.php | Interface |
| src/phpbb/storage/Contract/OrphanServiceInterface.php | Interface |
| src/phpbb/storage/Contract/UrlGeneratorInterface.php | Interface |
| src/phpbb/storage/Contract/StoredFileRepositoryInterface.php | Interface |
| src/phpbb/storage/Contract/StorageQuotaRepositoryInterface.php | Interface |

#### DTOs
| File | Type |
|------|------|
| src/phpbb/storage/DTO/StoreFileRequest.php | Input DTO |
| src/phpbb/storage/DTO/FileStoredResponse.php | Output DTO |
| src/phpbb/storage/DTO/ClaimContext.php | Input DTO |
| src/phpbb/storage/DTO/FileClaimedResponse.php | Output DTO |
| src/phpbb/storage/DTO/FileDeletedResponse.php | Output DTO |
| src/phpbb/storage/DTO/FileInfo.php | Query DTO |

#### Entities
| File | Type |
|------|------|
| src/phpbb/storage/Entity/StoredFile.php | Domain entity |
| src/phpbb/storage/Entity/StorageQuota.php | Domain entity |

#### Enums
| File | Type |
|------|------|
| src/phpbb/storage/Enum/AssetType.php | Backed enum |
| src/phpbb/storage/Enum/FileVisibility.php | Backed enum |
| src/phpbb/storage/Enum/VariantType.php | Backed enum |

#### Events (7)
| File | Type |
|------|------|
| src/phpbb/storage/Event/FileStoredEvent.php | Domain event |
| src/phpbb/storage/Event/FileClaimedEvent.php | Domain event |
| src/phpbb/storage/Event/FileDeletedEvent.php | Domain event |
| src/phpbb/storage/Event/VariantGeneratedEvent.php | Domain event |
| src/phpbb/storage/Event/QuotaExceededEvent.php | Domain event |
| src/phpbb/storage/Event/QuotaReconciledEvent.php | Domain event |
| src/phpbb/storage/Event/OrphanCleanupEvent.php | Domain event |

#### Exceptions (5)
| File | Type |
|------|------|
| src/phpbb/storage/Exception/FileNotFoundException.php | Domain exception |
| src/phpbb/storage/Exception/QuotaExceededException.php | Domain exception |
| src/phpbb/storage/Exception/UploadValidationException.php | Domain exception |
| src/phpbb/storage/Exception/OrphanClaimException.php | Domain exception |
| src/phpbb/storage/Exception/StorageWriteException.php | Domain exception |

#### Adapter
| File | Type |
|------|------|
| src/phpbb/storage/Adapter/StorageAdapterFactory.php | Factory |

#### Repositories
| File | Type |
|------|------|
| src/phpbb/storage/Repository/DbalStoredFileRepository.php | DBAL repository |
| src/phpbb/storage/Repository/DbalStorageQuotaRepository.php | DBAL repository |

#### Services
| File | Type |
|------|------|
| src/phpbb/storage/Service/OrphanService.php | Domain service |
| src/phpbb/storage/Service/UrlGenerator.php | URL generation |

#### Quota
| File | Type |
|------|------|
| src/phpbb/storage/Quota/QuotaService.php | Quota enforcement |
| src/phpbb/storage/Quota/QuotaReconciliationJob.php | Cron job |

#### Variant/Thumbnail
| File | Type |
|------|------|
| src/phpbb/storage/Variant/VariantGeneratorInterface.php | Interface |
| src/phpbb/storage/Variant/ThumbnailGenerator.php | Implementation |
| src/phpbb/storage/Variant/ThumbnailListener.php | Event listener |

#### Orphan Cleanup
| File | Type |
|------|------|
| src/phpbb/storage/Orphan/OrphanCleanupJob.php | Cron job |

#### REST API
| File | Type |
|------|------|
| src/phpbb/api/Controller/FilesController.php | REST controller |

#### Database Migrations
| File | Type |
|------|------|
| migrations/phpbb_stored_files.sql | SQL schema |
| migrations/phpbb_storage_quotas.sql | SQL schema |

#### Tests (unit + integration)
| File | Type |
|------|------|
| tests/phpbb/storage/Service/StorageServiceTest.php | Unit |
| tests/phpbb/storage/Service/OrphanServiceTest.php | Unit |
| tests/phpbb/storage/Service/UrlGeneratorTest.php | Unit |
| tests/phpbb/storage/Quota/QuotaServiceTest.php | Unit |
| tests/phpbb/storage/Repository/DbalStoredFileRepositoryTest.php | Integration |
| tests/phpbb/storage/Repository/DbalStorageQuotaRepositoryTest.php | Integration |
| tests/phpbb/api/Controller/FilesControllerTest.php | Unit |

---

### Files to Modify (Breaking Change + DI Config)

| File | Change |
|------|--------|
| src/phpbb/common/Event/DomainEvent.php | `entityId: int` → `string\|int` |
| src/phpbb/config/services.yaml | Add storage module service definitions |
| composer.json | Add `league/flysystem` dependency |

---

## Summary

- **New files**: ~45 PHP files + 2 SQL migration files + 7+ test files
- **Modified files**: 3 (DomainEvent, services.yaml, composer.json)
- **Runtime**: Docker container phpbb_app (PHP 8.4-FPM)
- **Database**: MariaDB (SQLite for tests)
- **Flysystem**: local adapter for production, tests use VFS or real `/tmp`
