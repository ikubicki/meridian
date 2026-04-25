# System Architecture

## Overview
**phpBB4 "Meridian"** runs as a **hybrid system** — new PSR-4 services (`src/phpbb/`) coexist within the same Symfony 8.x kernel alongside the retained legacy `src/phpbb3/` layer, which serves as reference code during the transition. The long-term goal is to delete `src/phpbb3/` once all services are migrated (M10+).

---

## Architecture Pattern
**Pattern**: Vertical Service Decomposition — new services are fully independent slices

Each M0–M7 service follows the same layered structure:

```
src/phpbb/{service}/
├── Entity/         # final readonly class entities (hydrated via fromRow())
├── DTO/            # final readonly class DTOs (hydrated via fromEntity())
├── Enum/           # backed enums (int/string)
├── Repository/     # DBAL 4 repository implementing RepositoryInterface
├── Service/        # domain facade — no SQL, no HTTP, raises DomainEventCollection
├── Controller/     # thin REST layer — parse request, call service, dispatch events, return JsonResponse
└── Event/          # DomainEvent subclasses for this service
```

Services do **not** depend on each other directly. They communicate via Symfony domain events dispatched by the controller layer.

---

## New Services Layer (`src/phpbb/`)

| Module | Status | Namespace | Purpose |
|--------|--------|-----------|---------|
| `api/` | ✅ M4 | `phpbb\api\` | REST routing, JWT AuthSubscriber, shared request/response helpers |
| `auth/` | ✅ M3 | `phpbb\auth\` | JWT issuance, Argon2id, 5-layer ACL |
| `cache/` | ✅ M1 | `phpbb\cache\` | PSR-16 TagAwareCacheInterface, pool isolation |
| `common/` | ✅ M0 | `phpbb\common\` | DomainEvent, DomainEventCollection, PaginationContext, shared exceptions |
| `config/` | ✅ M0 | `phpbb\config\` | Symfony DI YAML configs, route definitions |
| `db/` | ✅ M0 | `phpbb\db\` | Doctrine DBAL 4 connection factory |
| `hierarchy/` | ✅ M5a | `phpbb\hierarchy\` | Forum/category tree (nested set), ForumRepository/Service/Controller |
| `messaging/` | ✅ M7 | `phpbb\messaging\` | Private conversations — ConversationRepository/MessageRepository/ParticipantRepository |
| `threads/` | ✅ M6 | `phpbb\threads\` | Topics + posts, Tiered Counter Pattern |
| `user/` | ✅ M2 | `phpbb\user\` | User entity, profile, ban service |

### Shared Patterns (M0–M7)

- **Entities & DTOs**: `final readonly class` — constructed via `fromRow(array $row): self` (Entity) and `fromEntity(Entity $e): self` (DTO)
- **Domain events**: All mutations return `DomainEventCollection`; controllers call `$collection->dispatch($dispatcher)` — never services
- **Counter pattern**: Hot counter (cache) → Cold counter (DB column) → Recalculation cron job (`cache.{service}` pool)
- **HTTP routing**: All REST endpoints under `/api/v1/` — versioned YAML route definitions in `src/phpbb/config/`
- **Auth**: Services are auth-unaware; `AuthSubscriber` enforces JWT bearer at controller layer
- **Extension model**: Services accept `RegisterXxxEvent` to allow decoration — no tagged DI service locators

---

## Legacy Layer (`src/phpbb3/`)

The original phpBB 3.3.15 codebase is preserved under `src/phpbb3/` and `web/` entry points. **It is not used by any new `src/phpbb/` module.** It exists as:
- Reference for schema and domain understanding
- Fallback for forum functionality not yet migrated (M8–M10)

Key legacy components:

| Location | Contents |
|----------|----------|
| `src/phpbb3/` | Legacy procedural functions, `global` injected state, Symfony 3.4 DI wiring |
| `web/*.php` | Legacy HTTP entry points (`viewtopic.php`, `posting.php`, etc.) |
| `web/app.php` | Symfony HttpKernel entry point (shared by both old and new routing) |

---

## HTTP Entry Points

| File | Routing | Purpose |
|------|---------|---------|
| `web/api.php` | Symfony HttpKernel | **New REST API** — all `/api/v1/` routes |
| `web/app.php` | Symfony HttpKernel | Legacy controller routes (shared kernel) |
| `web/index.php` | Direct PHP | Legacy forum index |
| `web/viewtopic.php` | Direct PHP | Legacy thread display |
| `bin/phpbbcli.php` | Symfony Console | CLI commands |

---

## Data Flow — New REST API

```
React SPA (browser)
    │  HTTP Bearer JWT
    ▼
web/api.php → Symfony HttpKernel
    │
    ├── AuthSubscriber — validates JWT, injects user identity
    │
    ├── Controller (thin layer)
    │       ├── Parses request (JSON body / query params)
    │       ├── Calls Service method
    │       ├── Receives DomainEventCollection
    │       ├── Dispatches events via EventDispatcher
    │       └── Returns JsonResponse
    │
    ├── Service (domain logic)
    │       ├── Calls Repository
    │       ├── Returns Entity or DomainEventCollection
    │       └── No SQL, no HTTP, no events dispatched here
    │
    └── Repository (data access)
            ├── Doctrine DBAL 4 Connection (injected)
            ├── Returns Entity via fromRow()
            └── Parameterized queries only

```

---

## Runtime Storage

| Directory | Purpose |
|-----------|---------|
| `cache/` | DI container cache, legacy Twig compiled templates |
| `store/` | Search indexes and runtime data store |
| `files/` | User-uploaded attachments (legacy) |
| `images/` | System images (avatars, ranks, smilies) |

---

## Security Architecture (new `src/phpbb/` layer)

- **Auth**: JWT bearer tokens — stateless, no session; `AuthSubscriber` validates on every request
- **SQL injection**: Doctrine DBAL 4 parameterized queries only — never raw string interpolation
- **Password hashing**: Argon2id via PHP 8 native `password_hash()` / `password_verify()`
- **ACL**: 5-layer permission resolver in `phpbb\auth\` — checked at controller layer, not service layer
- **No CSRF**: REST API is stateless (JWT) — CSRF not applicable
- **No `global`**: Constructor DI only — no `global $db`, no `global $config`

---

*Last Updated*: April 2026
