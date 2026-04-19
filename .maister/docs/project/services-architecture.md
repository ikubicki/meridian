# Services Architecture Plan

Comprehensive plan for the new service-based architecture replacing the legacy phpBB monolith.
Based on 10 completed research tasks (April 2026).

**Status**: Research complete, pre-implementation alignment in progress.

---

## Architecture Vision

Rewrite phpBB as a set of **standalone, PSR-4 services** under `phpbb\{service}\` namespace. Each service follows the **Repository → Service → Controller** layered pattern with:
- PHP 8.2+ (readonly classes, enums, match expressions)
- PDO prepared statements (no legacy DBAL)
- Symfony DI Container (YAML-configured)
- Symfony EventDispatcher for domain events
- REST API via Symfony HttpKernel + bearer token auth

**Approach**: Full rewrite — legacy code treated as reference, not dependency.

---

## Service Inventory

### Infrastructure (Foundation)

| Service | Research | Purpose | Key Decision |
|---------|----------|---------|--------------|
| [Composer Autoload](../../tasks/research/2026-04-15-composer-autoload/) | Complete | PSR-4 autoloading for `phpbb\` | Delete custom class_loader, use Composer |
| [Root Path Elimination](../../tasks/research/2026-04-15-phpbb-root-path-elimination/) | Complete | Remove legacy `$phpbb_root_path` | `__DIR__`-based paths, `PHPBB_FILESYSTEM_ROOT` constant |
| [REST API Framework](../../tasks/research/2026-04-16-phpbb-rest-api/) | Complete | HTTP API layer | Symfony HttpKernel, YAML routes, DB opaque tokens, versioned `/api/v1/` |

### Core Services

| Service | Research | Purpose | Key Decision |
|---------|----------|---------|--------------|
| [Cache Service](../../tasks/research/2026-04-19-cache-service/) | Complete | PSR-16 tag-aware caching | TagAwareCacheInterface, filesystem-first, pool isolation |
| [Auth Service](../../tasks/research/2026-04-18-auth-service/) | Complete | Authorization (ACL engine) | AuthZ only, 5-layer permission resolver, bitfield cache |
| [Hierarchy Service](../../tasks/research/2026-04-18-hierarchy-service/) | Complete | Forums, categories, subforums | 5-service decomposition, nested set, events+decorators |
| [Threads Service](../../tasks/research/2026-04-18-threads-service/) | Complete | Topics, posts, content pipeline | Lean core + plugins, raw text storage, hybrid counters |
| [Messaging Service](../../tasks/research/2026-04-19-messaging-service/) | Complete | Private messages | Thread-per-participant-set, pinned+archive, no folders |
| [Notifications Service](../../tasks/research/2026-04-19-notifications-service/) | Complete | Notifications, email, REST polling | Full rewrite, HTTP polling 30s, tag-aware cache, React frontend |
| [Storage Service](../../tasks/research/2026-04-19-storage-service/) | Complete | File/attachment storage | Flysystem, UUID v7, single `stored_files` table |

---

## Implementation Order

| Phase | Service(s) | Rationale |
|-------|-----------|-----------|
| **0** | Composer Autoload + Root Path Elimination | Infrastructure prerequisites |
| **1** | Cache Service | Foundational utility, no upstream deps |
| **2** | User Service ⚠️ | Auth depends on User entity — **research needed** |
| **3** | Auth Service | Depends on User + Cache |
| **4** | REST API Framework | Depends on Auth for token subscriber |
| **5a** | Hierarchy Service | No service deps, Threads depends on it |
| **5b** | Storage Service | No service deps, Messaging needs it |
| **6** | Threads Service | Depends on Hierarchy |
| **7** | Messaging Service | Depends on Storage |
| **8** | Notifications Service | Depends on all event sources + Cache |

---

## Shared Patterns

All services follow these conventions:

- **Namespace**: `phpbb\{service}\` (PSR-4)
- **Layers**: Repository (PDO) → Service (facade) → Controller (REST)
- **DI**: Symfony Container, YAML service definitions
- **Events**: Symfony EventDispatcher for domain events
- **Auth**: Services are auth-unaware; ACL enforced by API layer subscriber
- **Cache**: TagAwareCacheInterface from cache-service (pools + tag invalidation)
- **DB**: Direct PDO with prepared statements, explicit JOINs
- **Entities**: `final readonly class` for value objects, PHP 8.2 enums for types

---

## Cross-Cutting Assessment

Full assessment: [cross-cutting-assessment.md](../../tasks/research/cross-cutting-assessment.md)

### Critical Items (must resolve before implementation)

1. **User Service not researched** — Auth depends on `User` entity, all services need `user_id`
2. **Extension model contradiction** — Hierarchy/Threads use events+decorators; Notifications uses tagged DI. Need unified ADR.
3. **JWT vs DB token** — Auth HLD says JWT, REST API designs DB opaque tokens. Must align to one approach.

### Gaps (research needed during implementation)

- Search Service (Threads expects SearchPlugin)
- Content Formatting Plugins (BBCode, Markdown, Smilies)
- Migration Strategy (legacy data → new schemas)
- Frontend Strategy (React islands? Full SPA?)
- Session Management (token lifecycle)
- Configuration Service (unified config access)
- Moderation / Admin services

---

## Dependency Graph

```
Composer Autoload → Root Path Elimination
    ├──→ Cache Service
    ├──→ User Service ⚠️ → Auth Service → REST API Framework
    │                                         ├──→ Hierarchy → Threads → Notifications
    │                                         ├──→ Storage → Messaging → Notifications
    │                                         └──→ Notifications (polling + events)
```

Solid arrows = hard dependency. No circular dependencies.

---

*Based on research completed April 15-19, 2026*
*Cross-cutting assessment: April 19, 2026*
