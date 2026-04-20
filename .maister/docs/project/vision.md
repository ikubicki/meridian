# Project Vision

## Overview
**phpBB Vibed** is a modernization fork/derivative of the phpBB 3.3.x forum software — a mature, open-source PHP forum platform being actively refactored and evolved to meet modern development standards.

## Current State
- **Version**: phpBB 3.3.15
- **Age**: 10+ years of active development history (v30x → v33x migration lineage)
- **Status**: Active development / modernization in progress
- **Tech Stack**: PHP 7.2+/8.x, Symfony 3.4 DI/Events, Twig 1.x/2.x, custom multi-DB DBAL
- **Architecture**: Hybrid monolith — legacy procedural layer (`includes/`) + modern OOP with DI Container (`phpbb/`)
- **Team**: Solo project

## Purpose
phpBB Vibed exists to modernize the phpBB forum codebase — applying current PHP 8.x idioms, updated dependencies, improved tooling, and better developer experience — while preserving the production-tested stability and rich feature set that phpBB is known for.

## Goals (Next 6–12 Months)

### Phase 1: Full Service-Based Rewrite
- Rewrite phpBB as standalone PSR-4 services under `phpbb\{service}\` namespace
- PHP 8.2+ with readonly classes, enums, match expressions throughout
- Direct PDO with prepared statements (replacing legacy DBAL)
- Symfony 8.x DI Container, EventDispatcher, HttpKernel
- REST API via Symfony HttpKernel + JWT bearer auth
- React SPA frontend consuming REST API (complete break from server-rendered legacy)
- Big bang cutover — no legacy coexistence
- 15 service researches completed — see [services-architecture.md](services-architecture.md)

### Phase 2: Infrastructure
- PSR-16 tag-aware cache service (filesystem-first, pool isolation)
- Symfony 8.x DI Container (YAML-configured) for all service wiring
- Symfony EventDispatcher for domain events and extensibility
- CI/CD pipeline (GitHub Actions: lint + test + security audit)
- PHPStan level 5+ static analysis
- PHPUnit 10+ unit tests for all service code
- Playwright E2E tests for full user flows

## Evolution
phpBB began as a PHP 4/procedural codebase. Over 3.x series releases, the `phpbb/` namespace was introduced with PSR-4 classes, Symfony DI, and an extension plugin system. **phpBB Vibed takes the next leap** — a full service rewrite treating legacy code as reference rather than dependency. Each domain (auth, forums, threads, messaging, notifications, storage) becomes a standalone service with clean interfaces, PDO data access, and REST API exposure.

## Success Criteria
- All 12+ services implemented with full unit test coverage
- REST API serves all forum functionality via `/api/v1/`
- React SPA renders entire forum UI (complete break from Twig/prosilver)
- Playwright E2E tests cover critical user journeys
- PHPStan analysis passes at level 5 with zero errors
- CI pipeline runs on every commit: lint, tests, security audit
- Developer onboarding: new contributor can run the project locally within 15 minutes using documented setup
- Migration scripts successfully convert PM data and attachments from legacy schema

---
*Last Updated*: April 2026
*Project Type*: Existing/Mature — Modernization focus
