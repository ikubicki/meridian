# Services Architecture Plan

Comprehensive plan for the new service-based architecture replacing the legacy phpBB monolith.
Based on research tasks completed April 2026.

**Status**: M0–M7 implemented and fully tested. M8–M10 planned.

---

## Architecture Vision

Rewrite phpBB as a set of **standalone, PSR-4 services** under `phpbb\{service}\` namespace. Each service follows the **Repository → Service → Controller** layered pattern with:
- PHP 8.2+ (readonly classes, enums, match expressions); runtime PHP 8.4
- Doctrine DBAL 4 (type-safe query builder + prepared statements — no ORM, no legacy phpBB DBAL)
- Symfony 8.x DI Container (YAML-configured)
- Symfony 8.x EventDispatcher for domain events
- REST API via Symfony HttpKernel + JWT bearer auth
- React SPA frontend consuming REST API (full break from legacy templates)

**Approach**: Full rewrite — big bang cutover, legacy code treated as reference, not dependency.

---

## Service Inventory

### Infrastructure (Foundation)

| Service | Research | Purpose | Key Decision |
|---------|----------|---------|--------------|
| [Composer Autoload](../../tasks/research/2026-04-15-composer-autoload/) | Complete | PSR-4 autoloading for `phpbb\` | Delete custom class_loader, use Composer |
| [Root Path Elimination](../../tasks/research/2026-04-15-phpbb-root-path-elimination/) | Complete | Remove legacy `$phpbb_root_path` | `__DIR__`-based paths, `PHPBB_FILESYSTEM_ROOT` constant |
| [REST API Framework](../../tasks/research/2026-04-16-phpbb-rest-api/) | Complete | HTTP API layer | Symfony HttpKernel, YAML routes, JWT bearer tokens, versioned `/api/v1/` |

### Core Services

| Service | Research | Purpose | Key Decision |
|---------|----------|---------|--------------|
| [Cache Service](../../tasks/research/2026-04-19-cache-service/) | Complete | PSR-16 tag-aware caching | TagAwareCacheInterface, filesystem-first, pool isolation |
| [Auth Unified Service](../../tasks/research/2026-04-19-auth-unified-service/) | Complete | AuthN + AuthZ (JWT, ACL engine) | Unified auth — login, tokens, 5-layer permission resolver, bitfield cache. **Supersedes** `2026-04-18-auth-service/`. |
| [Hierarchy Service](../../tasks/research/2026-04-18-hierarchy-service/) | Complete | Forums, categories, subforums | 5-service decomposition, nested set, events+decorators |
| [Threads Service](../../tasks/research/2026-04-18-threads-service/) | Complete | Topics, posts, content pipeline | Lean core + plugins, s9e XML default + encoding_engine, hybrid counters |
| [Messaging Service](../../tasks/research/2026-04-19-messaging-service/) | Complete | Private messages | Thread-per-participant-set, pinned+archive, no folders |
| [Notifications Service](../../tasks/research/2026-04-19-notifications-service/) | Complete | Notifications, email, REST polling | Full rewrite, HTTP polling 30s, tag-aware cache, React frontend |
| [Storage Service](../../tasks/research/2026-04-19-storage-service/) | Complete | File/attachment storage | Flysystem, UUID v7, single `stored_files` table |

---

## Implementation Order

| Phase | Service(s) | Status |
|-------|-----------|--------|
| **0** | Composer Autoload + Root Path Elimination + Symfony Kernel | ✅ Done |
| **1** | Cache Service | ✅ Done |
| **2** | User Service | ✅ Done |
| **3** | Auth Service (Unified) | ✅ Done |
| **4** | REST API Framework | ✅ Done |
| **5a** | Hierarchy Service | ✅ Done |
| **5b** | Storage Service | ⚠️ Research done — not implemented |
| **6** | Threads Service | ✅ Done |
| **7** | Messaging Service | ✅ Done |
| **8** | Notifications Service | ⏳ Planned |
| **9** | Search Service | ⏳ Planned |
| **10** | React SPA Frontend | ⏳ Planned |

---

## Shared Patterns

All services follow these conventions:

- **Namespace**: `phpbb\{service}\` (PSR-4)
- **Layers**: Repository (PDO) → Service (facade) → Controller (REST)
- **DI**: Symfony Container, YAML service definitions
- **Events**: All mutations return `DomainEventCollection`; controllers dispatch. See [DOMAIN_EVENTS.md](../standards/backend/DOMAIN_EVENTS.md)
- **Auth**: Services are auth-unaware; ACL enforced by API layer subscriber
- **Cache**: All services MUST use `TagAwareCacheInterface` via pool isolation (`cache.{service}`). No exceptions
- **DB**: Doctrine DBAL 4 (`Connection` injection, type-safe query builder). Maximum phpBB3 schema compatibility, zero legacy code references
- **Entities**: `final readonly class` — Entities hydrated via `fromRow(array $row): self`; DTOs via `fromEntity(Entity $e): self`. No mutable objects.
- **IDs**: Integer autoincrement PKs + UUID v7 columns alongside. UUID as external ID
- **Schema**: Reuse existing `phpbb_*` tables. Additive-only changes. New tables only for redesigned features
- **Exceptions**: Shared `phpbb\common\Exception\*` base classes with HTTP error mapping
- **Counters**: Tiered Counter Pattern (hot cache → cold DB → recalculation). See [COUNTER_PATTERN.md](../standards/backend/COUNTER_PATTERN.md)
- **Content**: s9e XML default, `encoding_engine` column for format-aware ContentPipeline

---

## Cross-Cutting Decisions

All partially divergent patterns resolved in `tasks/research/cross-cutting-decisions-plan.md` (2026-04-20).

---

## Cross-Cutting Assessment

Full assessment: [cross-cutting-assessment.md](../../tasks/research/cross-cutting-assessment.md)

### Critical Items (resolved)

1. ~~**User Service not researched**~~ — Research complete (April 2026). Users HLD documents full service decomposition.
2. ~~**Extension model contradiction**~~ — Resolved: event-based plugin registration (macrokernel). All services use `RegisterXxxEvent` pattern; tagged DI dropped.
3. ~~**JWT vs DB token**~~ — Resolved: JWT bearer tokens. Auth HLD specifies Argon2id + JWT issuance.

### Gaps (research needed during implementation)

- Content Formatting Plugins (BBCode, Markdown, Smilies)
- Configuration Service (unified config access)
- Moderation / Admin services

### Resolved Decisions (2026-04-20)

- ✅ **Auth Service**: Unified service (`2026-04-19-auth-unified-service/`) supersedes old auth (`2026-04-18-auth-service/`)
- ✅ **Migration Strategy**: Big bang cutover, migration scripts for PM data + attachments
- ✅ **Frontend Strategy**: React SPA consuming REST API (complete break from legacy)
- ✅ **Testing Strategy**: PHPUnit unit tests + Playwright E2E
- ✅ **Symfony version**: 8.x (latest major)
- ✅ **Extension model**: Macrokernel (events + decorators, no tagged DI)
- ✅ **Token type**: JWT bearer tokens

---

## Dependency Graph

```
Composer Autoload → Root Path Elimination
    ├──→ Cache Service
    ├──→ User Service → Auth Service → REST API Framework
    │                                         ├──→ Hierarchy → Threads → Notifications
    │                                         ├──→ Storage → Messaging → Notifications
    │                                         └──→ Notifications (polling + events)
```

Solid arrows = hard dependency. No circular dependencies.

---

*Based on research completed April 15-19, 2026*
*Cross-cutting assessment: April 19, 2026*
