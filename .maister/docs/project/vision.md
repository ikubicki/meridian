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
- Upgrade Symfony 3.4 (EOL) → Symfony 6.x/7.x LTS
- Upgrade Twig 1.x/2.x → 3.x
- Introduce PHP 8.x strict typing, named arguments, enums, and readonly properties throughout `phpbb/` layer
- Add static analysis tooling (PHPStan level 5+) to the project
- Set up a CI/CD pipeline (GitHub Actions: lint + test + security audit)
- Reduce reliance on `global` variables in `includes/` by progressively expanding the DI Container surface
- Bring the test suite (`tests/`) into the repository for local development feedback loops

## Evolution
phpBB began as a PHP 4/procedural codebase. Over 3.x series releases, the `phpbb/` namespace was introduced with PSR-4 classes, Symfony DI, and an extension plugin system. This project continues that trajectory — completing the modernization arc toward a fully typed, dependency-injected, well-tested PHP 8 application.

## Success Criteria
- All Symfony components upgraded to 6.x/7.x with no regressions
- PHPStan analysis passes at level 5 with zero errors
- CI pipeline runs on every commit: lint, tests, security audit
- `includes/` global function count reduced by 30%+ (replaced with DI services)
- Developer onboarding: new contributor can run the project locally within 15 minutes using documented setup

---
*Last Updated*: April 2026
*Project Type*: Existing/Mature — Modernization focus
