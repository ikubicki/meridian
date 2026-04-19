# Modernization Roadmap

## Current State Assessment
- **Version**: phpBB 3.3.15
- **Technology Age**: Symfony 3.4 (EOL), Twig 1.x/2.x, PHP 7.2+ baseline
- **Technical Debt**: High — procedural legacy layer, no strict typing, no CI/CD, no static analysis
- **Architecture**: Hybrid monolith in active modernization (legacy `includes/` + modern `phpbb/`)
- **Test coverage**: Test suite not co-located (upstream-only), limited local feedback loop
- **Developer**: Solo

---

## Service Rewrite Plan

Full details: [services-architecture.md](services-architecture.md) | Assessment: [cross-cutting-assessment.md](../../tasks/research/cross-cutting-assessment.md)

### Phase 0: Infrastructure Foundation
- [x] **Composer PSR-4 autoload** — Delete custom class_loader, use Composer for `phpbb\` `Research: Complete`
- [x] **Root path elimination** — Replace `$phpbb_root_path` with `PHPBB_FILESYSTEM_ROOT` constant `Research: Complete`
- [ ] **GitHub Actions CI pipeline** — Lint + test + `composer audit` `Effort: S`

### Phase 1: Core Infrastructure Services
- [x] **Cache Service** — PSR-16 TagAwareCacheInterface, filesystem-first, pool isolation `Research: Complete`
- [ ] **User Service** — User entity, authentication, session management `Research: NEEDED ⚠️`
- [x] **Auth Service** — AuthZ engine, 5-layer permission resolver, bitfield cache `Research: Complete`
- [x] **REST API Framework** — Symfony HttpKernel, YAML routes, DB token auth `Research: Complete`

### Phase 2: Domain Services
- [x] **Hierarchy Service** — Forums, categories, subforums, nested set `Research: Complete`
- [x] **Storage Service** — Flysystem, UUID v7, single `stored_files` table `Research: Complete`
- [x] **Threads Service** — Topics, posts, content pipeline, hybrid counters `Research: Complete`
- [x] **Messaging Service** — Thread-per-participant-set, pinned+archive `Research: Complete`
- [x] **Notifications Service** — Full rewrite, HTTP polling 30s, React frontend `Research: Complete`

### Phase 3: Supporting Services (research needed)
- [ ] **Search Service** — Full-text search backends
- [ ] **Content Formatting Plugins** — BBCode, Markdown, Smilies
- [ ] **Moderation Service** — Reports, queue, mod actions
- [ ] **Configuration Service** — Unified config access
- [ ] **Admin Panel** — ACP service/API

### Cross-Cutting (must resolve before implementation)
- [ ] **User Service research** — Blocks Auth → REST API → all services
- [ ] **Extension model ADR** — Tagged DI vs events+decorators alignment
- [ ] **Token type ADR** — JWT vs DB opaque token alignment
- [ ] **Migration strategy** — Legacy data → new schemas
- [ ] **Frontend strategy** — React islands vs full SPA

---

## Implementation Strategy

### Approach: Full Service Rewrite
- Legacy code = reference, not dependency
- Each service owns its domain completely (Repository → Service → Controller)
- Services communicate via Symfony events (loose coupling)
- REST API is the primary interface for frontend
- React for interactive components (notifications, messaging)

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
