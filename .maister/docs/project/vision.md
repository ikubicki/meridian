# Project Vision

## Overview
**phpBB4 "Meridian"** is a ground-up modernisation of the phpBB 3.3.x forum engine — replacing its legacy PHP 5.x monolith with a clean **Symfony 8.x backend**, **JSON REST API**, and **React SPA** frontend, while preserving full data compatibility with existing phpBB 3.x installations.

> **Note:** This project is also a personal playground for learning and experimenting with AI-assisted development workflows applied to a real-world legacy codebase. The combination of a well-known domain, a large existing PHP codebase, and a clear modernisation target makes phpBB an ideal subject for exploring how AI agents can plan, implement, test, and verify incremental rewrites of production systems.

## Current State
- **Version**: phpBB 3.3.15 (legacy base) + new `phpbb\` modules M0–M8 implemented
- **Runtime**: PHP 8.5; minimum PHP 8.2
- **Status**: Active implementation — M0–M8 complete, M9–M10 planned
- **Tech Stack**: PHP 8.5, Symfony 8.x, Doctrine DBAL 4, JWT (firebase/php-jwt), Argon2id, React SPA, Playwright
- **Architecture**: Hybrid — legacy `phpbb3\` layer retained as reference; new PSR-4 services in `src/phpbb\` run alongside within the same Symfony kernel
- **Team**: Solo project (AI-assisted)

## Purpose
Meridian exists to demonstrate that a large, battle-tested PHP monolith can be systematically modernised module-by-module using clean architecture principles, modern PHP idioms, and AI-assisted development workflows — without losing data compatibility with the original system.

## What's Been Done (M0–M8)

| Milestone | Service | Delivered |
|-----------|---------|----------|
| M0 | Core Infrastructure | Symfony 8.x kernel, Docker, PSR-4 autoload, REST base |
| M1 | Cache Service | PSR-16 TagAwareCacheInterface, pool isolation |
| M2 | User Service | User entity, profile, bans, groups; `final readonly class` pattern |
| M3 | Auth Unified Service | JWT bearer tokens, Argon2id, 5-layer ACL resolver |
| M4 | REST API Framework | YAML routes, JWT AuthSubscriber, versioned `/api/v1/` |
| M5a | Hierarchy Service | Forum/category tree, nested set, domain events |
| M5b | Storage Service | Flysystem, UUID v7, `phpbb_stored_files`, quota + TTL + thumbnails |
| M6 | Threads Service | Topics + posts, Tiered Counter Pattern, DomainEventCollection |
| M7 | Messaging Service | Private conversations, thread-per-participant-set |
| M8 | Notifications Service | HTTP polling 30s, tag-aware cache, `NotificationDTO`, mark-read REST API |

## Key Concept Changes (Emerged During Implementation)

- **Doctrine DBAL 4** chosen over raw PDO — provides type-safe QueryBuilder and connection management without ORM overhead; all repositories use `createQueryBuilder()` exclusively (no raw SQL strings)
- **`final readonly class`** for all Entities and DTOs — immutability enforced at language level; constructed via `fromRow()` (DB→Entity) and `fromEntity()` (Entity→DTO) static factories
- **DomainEventCollection** is immutable — constructed with the full events array (`new DomainEventCollection([...])`); no `add()` / `merge()` / `count()` methods
- **Tiered Counter Pattern** — hot counter (cache) → cold counter (DB column) → recalculation cron; avoids N+1 UPDATE storms on high-traffic counters
- **Macrokernel extension model** — services register via domain events (`RegisterXxxEvent`) and decorators; no tagged DI service locators
- **IntegrationTestCase** — SQLite in-memory database for repository tests (no XmlDataSet, no `phpbb_database_test_case`)

## Goals (Next Phase — M9–M10)

- **M9**: Search Service — full-text search backends (MySQL FT + Sphinx + future)
- **M10**: React SPA Frontend — full React SPA consuming `/api/v1/`; retire legacy Twig/prosilver views

## Success Criteria
- All domain services (M0–M10) implemented with full unit and integration test coverage
- REST API serves all forum functionality via `/api/v1/`
- React SPA renders entire forum UI (complete break from server-rendered legacy)
- Playwright E2E tests cover critical user journeys
- CI pipeline runs on every commit: lint, tests, security audit
- Migration scripts convert PM data + attachments from legacy phpBB3 schema

---
*Last Updated*: April 2026
*Project Type*: Modernization rewrite — AI-assisted solo development
