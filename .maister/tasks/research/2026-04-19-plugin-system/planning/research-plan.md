# Research Plan: Unified Plugin System

## Research Overview

### Research Question
How to design a unified plugin system that enables domain service extension through request/response decorators, events, JSON metadata on main records, and plugin-owned tables?

### Research Type Classification
**Mixed** — Technical (existing codebase patterns) + Requirements (extensibility contract) + Literature (industry plugin architectures)

### Scope & Boundaries
- **In scope**: Plugin lifecycle, manifest format, decorator integration, event integration, metadata JSON access, plugin-owned tables, DI integration, cross-service plugins, isolation guarantees
- **Out of scope**: Legacy phpBB ext backward compatibility, frontend/theme extension, admin UI design, marketplace/distribution

---

## Methodology

### Primary Approach
1. **Pattern extraction** from existing HLD files — identify the DecoratorPipeline, EventDispatcher, and JSON column conventions already established
2. **Cross-service comparison** — catalog extension points uniformly across all 8+ services
3. **Legacy reference analysis** — understand phpBB's `phpbb\extension\manager` and migration system as design input (not for compatibility)
4. **Industry comparison** — synthesize plugin patterns from WordPress, Symfony Bundles, Laravel Packages, Shopware Apps
5. **Infrastructure gap analysis** — identify what's needed beyond current patterns (schema management, autoloading, isolation)

### Fallback Strategies
- If existing HLD files lack sufficient detail on extension points → read source code in `src/phpbb/`
- If legacy ext system is unclear from code → check phpBB developer documentation patterns
- If industry patterns are too broad → focus on Symfony Bundles (closest stack match)

### Analysis Framework

| Dimension | Question | Evidence Sources |
|-----------|----------|-----------------|
| **Uniformity** | Can every service expose the same 4 extension mechanisms? | Cross-service HLD comparison |
| **Lifecycle** | What install/activate/deactivate/uninstall steps are needed? | Legacy ext system + industry patterns |
| **Isolation** | How to prevent one plugin from breaking another? | DI scoping, namespace isolation, error boundaries |
| **Schema** | How do plugins declare and migrate their tables? | Legacy migrations + Doctrine patterns |
| **Metadata** | How do plugins read/write JSON fields on records they don't own? | Users HLD (profile_fields pattern) |
| **Discovery** | How does the system find and load plugins? | PSR-4 + manifest + DI tagged services |
| **Cross-service** | How does a plugin extend multiple services atomically? | Symfony Bundle registration model |

---

## Research Phases

### Phase 1: Broad Discovery
- Catalog all service HLD files for extension mechanisms
- Map DecoratorPipeline interface contracts
- Identify JSON column implementations
- List all domain events across services
- Inspect legacy extension manager/provider code

### Phase 2: Targeted Reading
- Read DecoratorPipeline registration via DI tags (threads HLD lines 1040-1060)
- Read JSON metadata access pattern (users HLD — profileFields, preferences)
- Read legacy `extension_interface.php` lifecycle (enable_step, disable_step, purge_step)
- Read legacy `metadata_manager.php` for manifest/composer.json patterns
- Read Search service ISP pattern as model for capability interfaces

### Phase 3: Deep Dive
- Analyze how DI container compilation collects tagged decorators per service
- Investigate cross-service event flow (e.g., Threads emits → Notifications, Search consume)
- Study legacy migration system (`phpbb\db\migration\migration`) for schema management patterns
- Compare Symfony Bundle `Extension` class + CompilerPass with current needs

### Phase 4: Verification
- Confirm all services use the same decorator tag convention
- Verify JSON column pattern is consistent (or propose standardization)
- Validate that proposed plugin mechanisms cover the Poll, ReadTracking, Attachment examples already in HLD files
- Check for conflicts between plugin isolation and cross-service capabilities

---

## Gathering Strategy

### Instances: 5

| # | Category ID | Focus Area | Tools | Output Prefix |
|---|------------|------------|-------|---------------|
| 1 | existing-patterns | DecoratorPipeline interfaces, DI tag registration, JSON column access, EventDispatcher usage across services | Read, Grep | existing-patterns |
| 2 | cross-service-analysis | Extension points each service exposes (decorator tags, events dispatched/consumed, JSON fields), common patterns and divergences | Read, Grep | cross-service |
| 3 | legacy-ext-system | Legacy `phpbb\extension\manager`, `extension_interface`, `metadata_manager`, migration system, DI extension classes | Read, Grep | legacy-ext |
| 4 | literature-patterns | Plugin architecture patterns from WordPress (hooks/filters), Symfony Bundles (Extension+CompilerPass), Laravel Packages (ServiceProvider), Shopware (App system + rule builder) | Conceptual analysis | literature |
| 5 | infrastructure-needs | Schema management (DDL declarations, migration ordering), DI container integration (per-plugin service files, tagged services, compiler passes), PSR-4 autoloading, namespace isolation, error boundaries, dependency resolution | Read, Grep | infrastructure |

### Rationale
The 5-category split maps directly to the research question's sub-domains:
- Categories 1-2 establish **what already exists** (technical foundation)
- Category 3 provides **reference material** from the domain (what worked/didn't in phpBB)
- Category 4 brings **external wisdom** (proven industry patterns)
- Category 5 identifies **infrastructure gaps** (what needs building beyond the domain logic)

This split ensures no overlap: existing-patterns focuses on *how* mechanisms work; cross-service focuses on *what* each service exposes; legacy focuses on *historical reference*; literature brings *external patterns*; infrastructure focuses on *operational concerns*.

---

## Success Criteria

1. ✅ **Plugin manifest format defined** — clear YAML/JSON schema declaring capabilities (decorators, events, metadata keys, tables)
2. ✅ **Lifecycle contract documented** — install → activate → deactivate → uninstall with rollback semantics
3. ✅ **Metadata access pattern proven** — safe read/write of JSON metadata on domain records without coupling plugins to table schemas
4. ✅ **Table schema management workflow** — plugin declares DDL, system executes/rolls back at lifecycle transitions
5. ✅ **DecoratorPipeline integration documented** — how plugins register request/response decorators per service via DI tags
6. ✅ **EventDispatcher integration documented** — how plugins subscribe to cross-service events
7. ✅ **Plugin isolation guarantees specified** — namespace isolation, error boundaries, dependency constraints
8. ✅ **Cross-service plugin model** — single plugin can extend N services atomically

---

## Expected Outputs

| Output | Format | Purpose |
|--------|--------|---------|
| `outputs/high-level-design.md` | Markdown HLD | Complete plugin system architecture |
| (within HLD) Plugin Manifest Schema | YAML example | Declare plugin capabilities |
| (within HLD) Lifecycle State Machine | Diagram/table | Install/activate/deactivate/uninstall |
| (within HLD) Integration Contracts | PHP interfaces | Plugin ↔ Platform contracts |
| (within HLD) DI Registration Model | YAML examples | How plugins wire into container |
| (within HLD) ADR Log | Numbered decisions | Key design choices with rationale |
