# Modernization Roadmap

## Current State
- **Project**: phpBB4 "Meridian" — ground-up modernisation of phpBB 3.3.15
- **Runtime**: PHP 8.4 (minimum PHP 8.2); Symfony 8.x; Doctrine DBAL 4
- **Architecture**: Hybrid — legacy `phpbb3\` retained as reference; new PSR-4 services in `src/phpbb\` M0–M7 complete
- **Test coverage**: PHPUnit 10 (unit + integration) + Playwright E2E
- **Developer**: Solo (AI-assisted)
- **Status**: **M0–M7 implemented and passing** — M5b (Storage) skipped, M8–M10 planned

---

## Service Rewrite Plan

Full details: [services-architecture.md](services-architecture.md) | Assessment: [cross-cutting-assessment.md](../../tasks/research/cross-cutting-assessment.md)

### M0: Infrastructure Foundation ✅ Done
- [x] **Composer PSR-4 autoload** — Composer for `phpbb\` namespace; legacy class_loader deleted
- [x] **Root path elimination** — `PHPBB_FILESYSTEM_ROOT` constant, `__DIR__`-based paths
- [x] **Symfony 8.x Kernel** — `src/phpbb/Kernel.php`, Docker, `composer test` / `composer test:e2e` scripts
- [ ] **GitHub Actions CI pipeline** — Lint + test + `composer audit` `Effort: S`

### M1: Cache Service ✅ Done
- [x] **Cache Service** — PSR-16 `TagAwareCacheInterface`, filesystem-first, pool isolation per service

### M2: User Service ✅ Done
- [x] **User Service** — `phpbb\user\` — User entity, profile, groups, ban service; `final readonly class` pattern established

### M3: Auth Unified Service ✅ Done
- [x] **Auth Unified Service** — `phpbb\auth\` — JWT bearer tokens (firebase/php-jwt), Argon2id, 5-layer ACL resolver

### M4: REST API Framework ✅ Done
- [x] **REST API Framework** — Symfony HttpKernel, YAML routes, `AuthSubscriber`, versioned `/api/v1/`

### M5a: Hierarchy Service ✅ Done
- [x] **Hierarchy Service** — `phpbb\hierarchy\` — forums, categories, nested set tree, domain events

### M5b: Storage Service ⚠️ Research Done — Not Implemented
- [ ] **Storage Service** — `phpbb\storage\` — Flysystem, UUID v7, single `stored_files` table; research complete but implementation skipped (no attachment support in M7 Messaging)

### M6: Threads Service ✅ Done
- [x] **Threads Service** — `phpbb\threads\` — topics + posts, Tiered Counter Pattern, `DomainEventCollection`

### M7: Messaging Service ✅ Done
- [x] **Messaging Service** — `phpbb\messaging\` — thread-per-participant-set, conversations + messages + participants

### M8: Notifications Service ⏳ Planned
- [ ] **Notifications Service** — `phpbb\notifications\` — HTTP polling (30s), tag-aware cache, React frontend

### M9: Search Service ⏳ Planned
- [ ] **Search Service** — `phpbb\search\` — MySQL FT + Sphinx + pluggable ISP backends

### M10: React SPA Frontend ⏳ Planned
- [ ] **React SPA** — full SPA consuming `/api/v1/`; Vite + TypeScript; retire Twig/prosilver

### Supporting Services (future)
- [ ] **Content Formatting Plugins** — BBCode, Markdown, Smilies
- [ ] **Moderation Service** — Reports, queue, mod actions
- [ ] **Configuration Service** — Unified config access
- [ ] **Admin Panel** — ACP service/API

### Cross-Cutting (resolved 2026-04-20)
- [x] **User Service research** — Complete (`2026-04-19-users-service/`)
- [x] **Auth consolidation** — Auth Unified supersedes old Auth Service
- [x] **Extension model ADR** — Macrokernel: events+decorators, no tagged DI
- [x] **Token type ADR** — JWT bearer tokens
- [x] **Migration strategy** — Big bang cutover + migration scripts (PM, attachments)
- [x] **Frontend strategy** — React SPA, complete break from legacy
- [x] **Testing strategy** — PHPUnit unit tests + Playwright E2E
- [x] **Symfony version** — 8.x (latest major)

---

## Implementation Strategy

### Approach: Full Service Rewrite (Big Bang)
- Legacy code = reference, not dependency
- **Big bang cutover** — no coexistence period, old system fully retired
- Each service owns its domain completely (Repository → Service → Controller)
- Services communicate via Symfony events (loose coupling)
- REST API is the only interface for the React SPA frontend
- Maximum backward compat with existing data (not 100% — acceptable given codebase age)
- No rollback strategy — new system, fix-forward only
- **Migration scripts**: PM data (`phpbb_privmsgs*` → messaging) + attachments (`phpbb_attachments` → stored_files)

### Implementation Order
1. Infrastructure (autoload, root path, cache)
2. User + Auth + REST API framework
3. Domain services (hierarchy, threads, storage, messaging)
4. Cross-cutting services (notifications, search, moderation)

---

## Risk Mitigation

- **Service-by-service**: Implement and test one service at a time
- **Research-backed**: Every service has completed architectural research with ADRs
- **Cross-cutting assessment**: Alignment issues identified and tracked
- **Test-driven**: Each service gets full PHPUnit coverage before next service begins
- **Fallback**: Legacy `includes/` remains functional throughout transition

---

*Assessment based on project analysis performed April 2026*
*Effort Scale*: `XS` < 1 day | `S` 2-3 days | `M` 1 week | `L` 2+ weeks
