# Plugin System Research — Synthesis

## Research Question

How to design a unified plugin system for a phpBB rebuild (PHP 8.2+, Symfony DI, PDO) that supports request/response decorators, domain events, metadata JSON fields, and plugin-owned tables?

---

## Executive Summary

The existing codebase already implements 80% of the extension infrastructure — DecoratorPipeline, Symfony EventDispatcher, JSON columns, tagged DI services, and a legacy migration framework. The gap is **unification**: each service defines its own copies of interfaces, the extension points vary across services (decorators vs events vs registries), and there is no plugin lifecycle manager or manifest format. The recommended approach combines Shopware's lifecycle model (install → activate → deactivate → update → uninstall), Symfony's DI-driven registration (tagged services + compiler passes), and the existing DecoratorPipeline/EventDispatcher patterns — with a shared interface package and a thin `PluginManager` orchestrating the lifecycle.

---

## Cross-Source Analysis

### Validated Findings (High Confidence — Multiple Sources Confirm)

| Finding | Sources | Confidence |
|---------|---------|------------|
| DecoratorPipeline is the standard extension mechanism for CRUD services | existing-patterns, cross-service | **High** |
| EventDispatcher (Symfony) is used universally for side effects | existing-patterns, cross-service, legacy-ext | **High** |
| DI tags + compiler passes are the current registration mechanism | existing-patterns, infrastructure-needs | **High** |
| Migration framework with `depends_on()` DAG is robust and reusable | legacy-ext, infrastructure-needs | **High** |
| Real plugins (Polls, Attachments, Wiki) already use decorator+event combination | existing-patterns, cross-service | **High** |
| JSON columns work for schema-free extension (Users service) | existing-patterns | **High** |
| Shopware lifecycle (5 stages) is the most complete PHP model | literature-patterns | **High** |
| Compile-time container eliminates plugin boot overhead | infrastructure-needs, literature-patterns | **High** |

### Contradictions Identified

| Contradiction | Resolution |
|---------------|------------|
| **Search uses events where others use decorators** for pre/post processing | Accept divergence — Search's `PreSearchEvent`/`PostSearchEvent` with mutable payloads is functionally equivalent. Document both patterns as first-class. |
| **Threads ADR-001 chose NO JSON metadata** but plugin system needs per-post metadata | Respect ADR-001 for MVP. Plugins requiring per-post data use own tables. Revisit adding `metadata JSON` to `phpbb_topics` (less contentious than posts). |
| **Each service defines its own decorator interfaces** vs need for unified interface | Extract shared `phpbb\plugin\decorator\{Request,Response}DecoratorInterface` in a common package. Services re-export or alias them. |
| **Messaging uses `MessageSendDecorator`** instead of Request/Response pattern | Normalize to Request/Response pattern in Messaging service, or accept as domain-specific variant. |
| **Legacy ext uses `src/phpbb/ext/`** vs recommendation for new `plugins/` dir | Use new `plugins/` directory. Legacy path reserved for backward compatibility if needed during transition. |

### Confidence Assessment

| Category | High Confidence | Medium Confidence | Low / Uncertain |
|----------|:-:|:-:|:-:|
| Decorator integration model | 5 findings | 1 | 0 |
| Event system design | 6 findings | 1 | 1 (async events) |
| Schema management | 4 findings | 1 | 1 (FK strategy) |
| JSON metadata | 3 findings | 2 | 1 (performance at scale) |
| Plugin isolation | 2 findings | 3 | 2 (PHP sandbox limits) |
| Lifecycle management | 3 findings | 2 | 0 |

---

## Patterns and Themes

### Pattern 1: Decorator + Event Combination (Prevalence: Universal)

Every domain service that supports plugin extension uses the same flow:
```
Request → DecoratorPipeline::decorateRequest() → Core Logic → Event Dispatch → DecoratorPipeline::decorateResponse() → Response
```
**Quality**: Well-established across Threads, Hierarchy, Users. Consistent but not yet unified (duplicate interfaces).

### Pattern 2: Tagged DI Service Collection (Prevalence: Universal)

All extension points register via Symfony DI tags:
- Decorators: `phpbb.{service}.request_decorator`, `phpbb.{service}.response_decorator`
- Event subscribers: `kernel.event_subscriber`
- Registries: `notification.type`, `notification.method`, `phpbb.search.backend`
- Content pipeline: service-specific tags

**Quality**: Battle-tested Symfony pattern. Compiler passes collect and wire at build time; zero runtime discovery cost.

### Pattern 3: Immutable DTO with `withExtra()` (Prevalence: All Decorator Services)

Request/Response DTOs use `clone + set` to carry plugin data:
```php
$request = $request->withExtra('poll_config', $config);
```
**Quality**: Clean immutable pattern. Weakness: no type safety (string keys, `mixed` values). Acceptable trade-off for open extensibility.

### Pattern 4: Boot-Time Registry Events (Prevalence: 2 Services)

Hierarchy (`RegisterForumTypesEvent`) and Notifications (type/method registries) use events or DI tags to build runtime registries during boot.

**Quality**: Good for type-based extension (add new forum types, notification methods). Not needed for every service.

### Pattern 5: JSON Metadata for Schema-Free Extension (Prevalence: 1 Service)

Users service uses `profile_fields JSON` and `preferences JSON` for extensible data without DDL.

**Quality**: Proven pattern (Shopware custom fields). Under-utilized — only Users has it. Recommendation: extend to Hierarchy (`forum_metadata`) and Topics (`topic_metadata`).

### Pattern 6: Migration-Based Schema Management (Prevalence: Legacy System)

Legacy migration framework with `depends_on()`, `update_schema()`, `revert_schema()` is fully reusable.

**Quality**: Battle-tested, supports forward/revert, step-based execution within PHP time limits.

---

## Key Insights

### Insight 1: The System Is 80% Built

**Evidence**: DecoratorPipeline, EventDispatcher, DI tags, migration framework, JSON columns all exist. What's missing is the orchestration layer (PluginManager, manifest, lifecycle).

**Implication**: The plugin system design is primarily a **unification and lifecycle** problem, not a greenfield architecture.

### Insight 2: Service Divergence Is the Main Risk

**Evidence**: 3 services use decorators, 1 uses mutable events for the same purpose, 1 uses registries, 3 have minimal/no extension points. Auth and Cache are essentially closed to plugins.

**Implication**: Must define clear "extensibility tiers" — not every service needs every extension mechanism. Accept that infrastructure services (Cache, Auth) may only expose events.

### Insight 3: Cross-Service Plugins Are the Norm, Not the Exception

**Evidence**: All 4 example plugins (Polls, Badges, Wiki, Attachments) touch 3-5 services. No real plugin is single-service.

**Implication**: The manifest and lifecycle must support multi-service declaration. Plugin enable/disable must be atomic across all service hooks.

### Insight 4: PHP Sandboxing Is Impractical

**Evidence**: infrastructure-needs analysis confirms no process-level isolation in PHP. Best available: namespace enforcement, DI scoping, error boundaries.

**Implication**: Focus on convention-based isolation + compile-time enforcement (compiler passes) rather than attempting runtime sandboxing.

### Insight 5: JSON Metadata Needs Namespacing, Not Schema

**Evidence**: No cross-service JSON schema coordination exists (gap 5.8). Shopware uses prefixed keys + batched cleanup on uninstall.

**Implication**: Enforce `{vendor}.{plugin}.{key}` namespacing convention for JSON metadata. No DB-level schema validation; PHP-side validation only.

### Insight 6: Legacy Step-Based Execution Is Worth Preserving

**Evidence**: Legacy enable/disable uses step-based pattern to handle long migrations within `max_execution_time`. Unique to phpBB among compared frameworks.

**Implication**: Plugin install/uninstall must support resumable step-based execution, especially for migration-heavy plugins.

---

## Relationships and Dependencies

```
PluginManager (NEW)
  ├── reads plugin.yml manifests
  ├── resolves plugin-to-plugin dependencies (DAG)
  ├── delegates to Migrator (EXISTING) for schema management
  ├── triggers DI container rebuild (EXISTING infrastructure)
  └── manages state in phpbb_plugins table (NEW)

DecoratorPipeline (EXISTS per-service, NEEDS unification)
  ├── shared interfaces → phpbb\plugin\decorator\
  ├── tagged services collected by CompilerPass (EXISTS)
  └── error boundary wrapper (NEW)

EventDispatcher (EXISTS — Symfony)
  ├── subscribers registered via kernel.event_subscriber tag (EXISTS)
  └── priority supported (EXISTS)

MetadataAccessor (NEW)
  ├── namespaced read/write to JSON columns
  ├── bulk operations (get all keys for a vendor.plugin)
  └── cleanup on uninstall (JSON_REMOVE)

Migration Framework (EXISTS — reusable as-is)
  ├── depends_on() for ordering
  ├── update_schema() / revert_schema()
  └── step-based execution
```

---

## Gaps and Uncertainties

| Gap | Impact | Status |
|-----|--------|--------|
| No shared decorator interface package | Medium — forces per-service implementations | **Solvable**: extract shared package |
| No unified plugin manifest | High — capabilities scattered across YAML | **Solvable**: define `plugin.yml` format |
| Threads ADR-001 forbids JSON metadata on posts | Medium — forces separate tables for per-post plugin data | **Uncertain**: revisit ADR or accept constraint |
| No async event mechanism | Low (MVP) — all events synchronous | **Deferred**: add queue integration post-MVP |
| Auth service is closed to plugins | Low-Medium — limits auth-extending plugins | **Accept**: auth extension via `acl_options` table only |
| No error boundary in DecoratorPipeline | High — one broken decorator crashes entire request | **Solvable**: add try/catch + logging |
| FK strategy for plugin tables | Low — app-level constraints sufficient | **Decision**: no DB-level FKs (recommended) |

---

## Conclusions

### Primary Conclusions

1. **Build a thin orchestration layer** (PluginManager + manifest + lifecycle) on top of existing infrastructure. Do not redesign the extension mechanisms themselves.

2. **Extract shared decorator interfaces** into `phpbb\plugin\decorator\` — this is the highest-value unification task with lowest risk.

3. **Adopt Shopware's lifecycle model** (install → activate → deactivate → update → uninstall) with phpBB's step-based execution for long migrations.

4. **Use `composer.json` extra section** for plugin metadata (leverages existing ecosystem) plus optional `plugin.yml` for phpBB-specific declarations.

5. **Add JSON metadata columns** to `phpbb_forums` and `phpbb_topics` tables (core migration). Leave `phpbb_posts` without metadata per ADR-001.

6. **Enforce plugin isolation** via `PluginServicePrefixPass` (compile-time) + error boundaries (runtime) + namespace convention (design-time).

### Secondary Conclusions

- Accept Search's event-based pre/post pattern as a valid alternative to decorators.
- Plugin-to-plugin dependencies should be declared and resolved at install time.
- `keepUserData` flag on uninstall is essential (Shopware teaches this lesson well).
- Lazy loading via Symfony proxies eliminates plugin boot performance concerns even at 50+ plugins.
