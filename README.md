# phpBB4 "Meridian"

A ground-up modernisation of the phpBB forum engine — replacing its legacy PHP 5.x monolith with a
clean **Symfony 8.x backend**, **JSON REST API**, and **React SPA** frontend, while preserving
full data compatibility with existing phpBB 3.x installations.

> **Note:** This project is also my personal playground for learning and experimenting with
> AI-assisted development workflows applied to a real-world legacy codebase. The combination of
> a well-known domain (forum software), a large existing PHP codebase, and a clear modernisation
> target makes phpBB an ideal subject for exploring how AI agents can plan, implement, test, and
> verify incremental rewrites of production systems.

---

## Goal

phpBB 3.x is a mature, widely deployed forum platform with a well-understood domain model. Its
architecture, however, pre-dates modern PHP practices: global state, procedural controllers,
template-driven server rendering, and tightly coupled database access make it hard to extend,
test, and scale.

**Meridian** rewrites the engine module by module, targeting:

- **PHP 8.2+** with strict types, readonly properties, and named arguments throughout
- **Symfony 8.x** kernel with dependency injection, event dispatcher, and HTTP foundation
- **Doctrine DBAL 4** for safe, type-aware database access (no raw string interpolation)
- **JWT-based authentication** (access + refresh tokens, Argon2id password hashing)
- **REST API** as the single integration surface — no server-rendered HTML
- **React SPA** as the reference frontend, consuming the REST API
- **PHPUnit 10 + Playwright** ensuring every service and endpoint is covered before merge

The legacy `phpbb3\` codebase is kept intact during the transition. New modules under `phpbb\`
run side-by-side within the same Symfony kernel and gradually take over until the old layer
can be deleted entirely.

---

## How We Get There

The project is broken into vertical milestones, each delivering a self-contained, production-ready
service with its own repository layer, service facade, REST controller, and full test coverage.

| Milestone | Service | Status |
|-----------|---------|--------|
| M0 | Core Infrastructure (Symfony kernel, Docker, CI) | ✅ Done |
| M1 | Cache Service (`phpbb\cache`) | ✅ Done |
| M2 | User Service (`phpbb\user`) | ✅ Done |
| M3 | Auth Unified Service (`phpbb\auth`) — JWT + ACL | ✅ Done |
| M4 | REST API Framework — routing, auth middleware | ✅ Done |
| M5a | Hierarchy Service (`phpbb\hierarchy`) — forums/categories | ✅ Done |
| M5b | Storage Service (`phpbb\storage`) — file/attachment storage | ⚠️ Research done, not implemented |
| M6 | Threads Service (`phpbb\threads`) — topics + posts | ✅ Done |
| M7 | Messaging Service (`phpbb\messaging`) — private conversations | ✅ Done |
| M8 | Notifications Service (`phpbb\notifications`) | ⏳ Planned |
| M9 | Search Service (`phpbb\search`) | ⏳ Planned |
| M10 | React SPA Frontend | ⏳ Planned |

Security reviews (OWASP Top 10) and load tests (k6) are scheduled between milestones as
explicit checkpoints, not afterthoughts.

Full milestone detail: [.maister/MILESTONES.md](.maister/MILESTONES.md)

---

## Tech Stack

| Layer | Technology |
|-------|-----------|
| Runtime | PHP 8.2+, PHP-FPM (Alpine) |
| Framework | Symfony 8.x (HttpKernel, DI, EventDispatcher) |
| Database | MariaDB 10.x via Doctrine DBAL 4 |
| Auth | JWT (firebase/php-jwt), Argon2id |
| Web server | Nginx |
| Unit tests | PHPUnit 10 (`#[Test]` attributes) |
| E2E tests | Playwright (TypeScript) |
| Code style | PHP CS Fixer |
| Containers | Docker + Docker Compose |
| Frontend (SPA) | React + Vite (in progress) |

---

## Project Structure

```
src/
  phpbb/              # New modules (PSR-4, namespace phpbb\)
    api/              # REST controllers + request/response layer
    auth/             # Authentication + authorisation (JWT, ACL)
    cache/            # Tag-aware cache service
    common/           # Shared value objects, domain events, pagination
    config/           # Symfony DI config (services.yaml, routes.yaml)
    db/               # DBAL connection factory + migrations
    hierarchy/        # Forum/category tree
    messaging/        # Private conversations (M7)
    threads/          # Topics + posts
    user/             # User entities, ban service
  phpbb3/             # Legacy code (untouched, temporary)
tests/
  phpbb/              # PHPUnit unit + integration tests
  e2e/                # Playwright end-to-end tests
.maister/             # Project docs, standards, task plans, milestones
```

---

## Running Locally

```bash
# Start the full stack (PHP-FPM, Nginx, MariaDB)
docker compose up -d

# API base URL
http://localhost:8181/api/v1/

# Health check
curl http://localhost:8181/api/v1/health
```

---

## Tests

```bash
# PHPUnit (unit + integration)
composer test

# Playwright E2E
composer test:e2e

# PHP CS Fixer (auto-fix)
composer cs:fix
```

All three must pass before any change is considered complete.

Current coverage: **339 PHPUnit tests · 54 E2E tests · 0 CS issues**

---

## Coding Standards

- `declare(strict_types=1)` in every PHP file
- PSR-4 autoloading under `phpbb\` namespace
- Dependency injection — no `global`, no `static` service locators
- PDO prepared statements / DBAL query builder — never raw user input in SQL
- Controllers are thin: validate input, call service, return JSON response
- Every mutation method returns `DomainEventCollection`; controllers dispatch events
- New code ships with PHPUnit tests; critical flows ship with Playwright E2E tests

Full standards: [.maister/docs/standards/](.maister/docs/standards/)

---

## License

GNU General Public License, version 2 (GPL-2.0).  
Original phpBB code © phpBB Limited. New modules © Irek Kubicki / codebuilders.pl.  
See [docs/CREDITS.txt](docs/CREDITS.txt) for full attribution.
