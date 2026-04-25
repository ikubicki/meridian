# Phase 1 Clarifications — M5b Storage Service

Date: 2026-04-25
Resolved by: user

---

## Q1: Scope of M5b

**Question**: Jaki zakres M5b implementujemy?

**Answer**: Full — wszystko z HLD (core + quota + orphan + variant/thumbnail)

**Impact**: Implement all 7 subsystems:
1. Core: store/retrieve/delete/claim + `phpbb_stored_files` table
2. Quota: `phpbb_storage_quotas` table + atomic enforcement
3. Orphan cleanup: `is_orphan` flag + 24h cron cleanup
4. Variant/thumbnail: async via `FileStoredEvent` listener, child rows with `parent_id`
5. REST API: POST /api/v1/files (file upload endpoint)
6. Flysystem adapter layer

---

## Q2: DomainEvent entityId type

**Question**: DomainEvent ma entityId: int — jak obsłużyć UUID (string) w events?

**Answer**: Zmień DomainEvent.entityId na string|int (breaking change)

**Impact**:
- Change `phpbb\common\Event\DomainEvent::entityId` from `int` to `string|int`
- Audit all existing event subclasses to verify they still work
- Storage events use `string` (UUID hex) for `entityId`

---

## Q3: Flysystem

**Question**: Storage adapter: dodać league/flysystem?

**Answer**: Tak — dodaj league/flysystem do composer.json

**Impact**:
- `composer require league/flysystem`
- Use `League\Flysystem\Filesystem` for all file I/O
- Local adapter now; swappable for S3/GCS later via DI

---

## Q4: REST API Controller

**Question**: Czy M5b zawiera REST API Controller?

**Answer**: Tak — POST /api/v1/files z file upload

**Impact**:
- Create `phpbb\api\Controller\FilesController` with `POST /api/v1/files`
- Handles multipart/form-data file upload
- Returns 201 + `FileStoredResponse` DTO
