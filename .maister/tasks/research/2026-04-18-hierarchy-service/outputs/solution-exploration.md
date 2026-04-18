# Solution Exploration: `phpbb\hierarchy` Service Design

**Research Question**: Design `phpbb\hierarchy` service for category/forum management based on legacy nested set, with plugin architecture, excluding ACL.

**Date**: 2026-04-18

**Based on**: synthesis.md (cross-referenced analysis), research-report.md (comprehensive findings)

---

## Problem Reframing

### Research Question

How should the `phpbb\hierarchy` service be architected for a PHP 8.2+, PSR-4, direct-PDO codebase that replaces the legacy 4000+ LOC scattered across `nestedset.php`, `acp_forums.php`, `display_forums()`, `markread()`, and `watch_topic_or_forum()` — preserving nested set data, enabling plugins, and excluding ACL responsibility?

### How Might We Questions

1. **HMW model the three forum types** (CAT/POST/LINK) to maximize type safety without over-complicating queries against a single DB table?
2. **HMW implement tree operations** to maintain backward compatibility with 50k+ existing `left_id`/`right_id` records while fixing the dual-implementation problem?
3. **HMW decompose the monolithic legacy** into services that are independently testable yet cohesive enough to avoid shotgun surgery?
4. **HMW enable plugin extensibility** so extensions can add custom forum types and behaviors without modifying core service code?
5. **HMW design the tree operations API** to provide clean extension hooks while keeping the common case (CRUD + move) simple?

---

## Decision Area 1: Entity Model Strategy

### Why This Decision Matters

The forum entity model determines type safety, query complexity, and plugin extensibility. Legacy uses raw arrays with a `forum_type` integer discriminator over a single `phpbb_forums` table with 50 columns. The model must handle three fundamentally different behaviors (categories hold children only, forums hold posts, links redirect) while mapping to the same physical table.

### Alternative 1A: Single `Forum` Entity with `ForumType` Enum

**Description**: One `Forum` class holds all 50 columns as properties. A `ForumType` enum (Category=0, Forum=1, Link=2) discriminates behavior. Value objects (`ForumStats`, `ForumLastPost`, `ForumPruneSettings`) group related fields. The entity is immutable (readonly properties).

**Strengths**:
- Maps 1:1 to the database table — no ORM complexity, no JOIN overhead
- Single hydration path: one `SELECT *` → one entity constructor
- Plugins see one consistent type; no type-switching in plugin interfaces
- Maximum query simplicity: `SELECT * FROM phpbb_forums WHERE forum_id = ?` returns everything
- Backward-compatible: existing data stays untouched

**Weaknesses**:
- No compile-time enforcement that `link` is only set on `ForumType::Link` or that `stats` is meaningless on categories
- Properties like `link`, `password`, `pruneSettings` exist but are semantically null for some types
- IDE autocomplete shows irrelevant properties depending on forum type

**Best when**: The team values simplicity and direct DB mapping. The codebase has a small number of forum types (3), all sharing the same table, and runtime validation is acceptable.

**Evidence**: Synthesis §3.1 confirms all three types share the same table and tree. Research report §3.1 proposes this exact approach. Legacy code already uses type-discriminated arrays. The 50-column schema (synthesis §2.1) is uniform across types.

---

### Alternative 1B: Base `Node` + Specialized Subtypes (Inheritance)

**Description**: Abstract `Node` holds tree-structural fields (`id`, `parentId`, `leftId`, `rightId`, `name`, `description`). Three concrete classes: `Category extends Node`, `PostingForum extends Node` (adds stats, lastPost, prune), `ForumLink extends Node` (adds link URL, click tracking). A factory method hydrates the correct subtype based on `forum_type`.

**Strengths**:
- Type-safe: `ForumLink::getLink()` exists only where it's meaningful
- IDE-friendly: each subtype shows only its relevant properties
- Respects the "three fundamentally different behaviors" insight (synthesis §4, insight 6)
- Plugin `getSupportedTypes()` can type-hint specific subtypes for stronger contracts

**Weaknesses**:
- Hydration complexity: factory must switch on `forum_type`, constructors differ per subtype
- Query result processing requires type-switching: `match ($row['forum_type']) { 0 => new Category(...), ... }`
- Collections become `Node[]` — callers must `instanceof` check, losing type discriminator benefits
- Harder to extend: a plugin adding a 4th type must create a new subtype class, not just register metadata
- All three subtypes still map to the same DB table, so the separation is cosmetic from a persistence perspective

**Best when**: The team plans to extend forum types significantly (wiki forums, gallery forums) with genuinely different data structures, and is willing to pay the hydration complexity cost.

**Evidence**: Three forum types have meaningfully different behaviors (synthesis §2.1, insight 6 — "Forum type determines available behavior"). However, they share the same physical table and tree structure. Legacy `nestedset_forum` already used a single base with no subtypes.

---

### Alternative 1C: Single Entity + Typed Behavior Delegates

**Description**: One `Forum` entity (like 1A), but behavior that varies by type is delegated to `ForumTypeBehavior` strategy objects. A `ForumTypeBehaviorRegistry` maps `ForumType` → behavior class. Behaviors define: `canHaveContent()`, `canHaveChildren()`, `getEditableFields()`, `validate()`. The entity is a data bag; the behavior objects enforce rules.

**Strengths**:
- Simple entity model (1:1 with DB) AND type-safe behavior enforcement
- Plugin-extensible: register a new `ForumType` (e.g., `Wiki=3`) with its behavior class via `service_collection`
- Validation happens at the right layer (behavior delegate, not entity constructor)
- No inheritance hierarchy to manage; composition over inheritance
- Display and admin layers call `$behavior->canHaveContent()` to determine UI

**Weaknesses**:
- Two concepts to learn: entity + behavior delegate
- Behavior delegates must be looked up via registry — slightly more indirection
- Risk of behavior delegates becoming god objects if not scoped carefully
- Behavior interface must be designed carefully upfront or it becomes a kitchen sink

**Best when**: Plugin extensibility with custom forum types is a core requirement, and the team wants to avoid inheritance while keeping type-specific rules enforceable.

**Evidence**: Plugin architecture requires custom forum types (synthesis §4, insight 6: "type-specific behavior delegated via the plugin system"). The `HierarchyPluginInterface::getSupportedTypes()` in the research report already implies type-specific behavioral delegation.

---

### Alternative 1D: Anemic Entity + Rich Service Layer

**Description**: `Forum` is a plain data class (essentially a typed row). ALL behavior, validation, and type-specific logic lives in the service layer. No behavior delegates, no subtypes — services check `$forum->type` and branch accordingly. DTOs for create/update carry validation rules.

**Strengths**:
- Simplest possible entity model — pure data, zero logic
- All behavior concentrated in services — easy to find, easy to test
- No abstraction overhead: `if ($forum->type === ForumType::Link)` is explicit and greppable
- Familiar pattern for teams coming from procedural or CRUD-heavy codebases

**Weaknesses**:
- Type-checking conditionals scattered across services (every service that cares about type has its own `match`/`if`)
- Adding a new forum type requires touching multiple services
- Violates Open/Closed principle — services must be modified for new types
- Testing requires many branches per service method

**Best when**: The codebase is small, forum types are stable (no extensions will add new types), and the team prefers explicit conditionals over abstractions.

**Evidence**: Legacy code uses this exact approach — raw arrays with `forum_type` checks sprinkled everywhere. Synthesis §2.3 identifies this as an anti-pattern ("Missing Domain Objects: raw arrays everywhere"). Moving to typed entities is an improvement, but keeping them anemic recreates the same branching problem.

---

### Trade-Off Matrix: Entity Model

| Perspective | 1A: Single Entity | 1B: Inheritance | 1C: Entity + Delegates | 1D: Anemic + Service |
|---|---|---|---|---|
| **Technical Feasibility** | HIGH — trivial to implement | MEDIUM — factory + hydration complexity | MEDIUM-HIGH — registry + delegates | HIGH — trivial |
| **User Impact** | Neutral — internal design | Neutral | Neutral | Neutral |
| **Simplicity** | HIGH — one class, one table, done | LOW — 4 classes, type switching | MEDIUM — 2 concepts (entity + behavior) | HIGH on surface, LOW at scale |
| **Risk** | LOW — proven pattern | MEDIUM — may need rework if 4th type added | LOW — delegates are replaceable | MEDIUM — scattered type-checks |
| **Scalability** | MEDIUM — new types need new properties | LOW — new types need new classes | HIGH — register new type = register new delegate | LOW — new types require multi-service changes |

### Recommendation: Alternative 1C — Single Entity + Typed Behavior Delegates

**Rationale**: Combines the simplicity of a single entity (1:1 DB mapping, straightforward hydration) with extensible type-specific behavior via the plugin system. The `ForumTypeBehaviorRegistry` aligns naturally with phpBB's `service_collection` pattern. Plugins register new forum types by providing a behavior delegate — no core code changes needed.

**Key trade-offs accepted**: Slightly more indirection than 1A (behavior lookup via registry). Requires upfront design of the behavior interface.

**Key assumptions**: Plugin extensions will want to add custom forum types. If this assumption is wrong, 1A (single entity) is strictly simpler and equally sufficient.

**Why not 1A**: Adequate but doesn't leverage the plugin architecture for type-specific behavior. Would require service-level type-checking.
**Why not 1B**: Inheritance creates friction when plugins add new types. Factory hydration adds complexity without proportional benefit — all types share the same table.
**Why not 1D**: Recreates the legacy anti-pattern of scattered type-checking. Identified as a weakness in synthesis §2.3.

---

## Decision Area 2: Nested Set Implementation

### Why This Decision Matters

The tree storage model determines: (a) backward compatibility with existing `left_id`/`right_id` data, (b) performance of reads (display tree) vs. writes (move/create/delete), (c) implementation complexity, and (d) the concurrency model. The legacy system has a correct but dual-implemented nested set. This is the foundational data structure for the entire hierarchy.

### Alternative 2A: Port Legacy `nestedset.php` to PDO (Refined)

**Description**: Take the existing 870-LOC `nestedset.php` abstract class with its 17 methods, modernize to PHP 8.2 strict types, replace `$db->sql_query()` with PDO prepared statements, fix known bugs (extra argument in `remove_subset`), and add proper advisory locking. Keep the same nested set math. Resolve the insert semantics mismatch by combining insert+position atomically.

**Strengths**:
- Lowest risk: the nested set math is battle-tested over ~17 years
- Direct backward compatibility: same `left_id`/`right_id`/`parent_id` schema, zero data migration
- Known performance characteristics: `left_id` index already exists, query patterns are proven
- Addresses the core problem (dual implementation) by making the OOP class the single source of truth
- Fixes known bugs (synthesis §2.2, contradiction 3: `remove_subset` argument count)
- Combines ACP's efficient insert-at-position with OOP class structure (synthesis §2.2, contradiction 2)

**Weaknesses**:
- Inherits algorithmic complexity of nested set: every mutation shifts O(n) rows
- Advisory locking required for all mutations — single-writer bottleneck
- `forum_parents` serialized cache still needed (though can convert to JSON)
- Nested set is notoriously hard to debug when data gets corrupted

**Best when**: Backward compatibility is paramount, the tree is relatively small (< 1000 nodes), and write operations are infrequent (admin-only).

**Evidence**: Synthesis §8 conclusion 1: "The nested set algorithm is sound — keep the mathematical model." Synthesis §2.2 contradiction 1: "The new service MUST use the OOP class as the single source of truth." Research report §7.2 documents exact SQL for all operations.

---

### Alternative 2B: Fresh Nested Set Implementation (Clean Room)

**Description**: Write a brand new `TreeService` from scratch using nested set theory but with a modern PHP 8.2 design. Use enums for operation types, readonly DTOs for tree positions, gap-based numbering (leave gaps between `left_id`/`right_id` values for cheaper inserts), and built-in tree integrity validation. Do not port any legacy code.

**Strengths**:
- Clean design: no legacy baggage, no compromises for backward compatibility with PHP 5.x patterns
- Gap-based numbering reduces shift operations: inserts between existing nodes don't require updating all siblings
- Built-in integrity checks can detect and repair corruption at query time
- Can use `RETURNING` clause (MySQL 8.0.21+, PostgreSQL) for atomic insert+read

**Weaknesses**:
- New code = new bugs. The legacy math is proven; a rewrite may introduce subtle off-by-one errors
- Gap-based numbering eventually requires renumbering when gaps fill — adds complexity
- Requires careful testing against large trees to match legacy performance
- Data migration: existing tight-numbered trees must be renumbered to introduce gaps (or gaps only appear for new inserts)
- More development time for equivalent functionality

**Best when**: Starting a greenfield project without existing data, or performance requirements demand fewer write-amplification operations.

**Evidence**: Synthesis §3.1 notes `regenerate_left_right_ids` is O(3n) queries — a gap strategy could reduce frequency. However, synthesis §8 conclusion 1 says "keep the mathematical model" and the research report §2.3 says nested set is "correct and battle-tested."

---

### Alternative 2C: Materialized Path (Path Enumeration)

**Description**: Replace nested set with materialized paths. Each forum stores a `path` column like `/1/5/12/` representing its ancestry. Tree queries use `LIKE '/1/5/%'` for subtree, `path` column for ancestry. Sibling ordering via a separate `position` column.

**Strengths**:
- Conceptually simpler than nested set: path is human-readable, easy to debug
- Subtree queries are intuitive: `WHERE path LIKE :prefix%`
- Inserts are cheap: just set the path, no sibling shifting
- Moves are moderate: update path prefix for moved subtree
- No advisory locking needed for most operations (path updates are self-contained)

**Weaknesses**:
- **Breaking backward compatibility**: requires data migration from `left_id`/`right_id` to path column
- `LIKE` prefix queries can't use standard B-tree indexes efficiently (though computed columns or GIN indexes help)
- Path length limits: very deep trees (unlikely for forums, but a constraint)
- Ordering within siblings requires a separate column (`position`) and gap/shift management
- Ancestor count and depth must be computed (not embedded in structure like nested set's `(right-left-1)/2`)
- No direct equivalent of nested set's single-query "all nodes in DFS order"

**Best when**: The application has frequent writes and shallow trees, and backward compatibility with `left_id`/`right_id` is not required.

**Evidence**: Research report §7.2 shows nested set's `getFullTree()` is a single `SELECT * ORDER BY left_id` — materialized path can't match this simplicity. Synthesis notes forum trees are typically shallow (3-5 levels) which would suit materialized paths, but §6.1 lists "large trees with 500+ forums" as a concern, and the existing `left_id` index is already built for this.

---

### Alternative 2D: Closure Table

**Description**: Add a `forum_closure` table storing all ancestor-descendant relationships: `(ancestor_id, descendant_id, depth)`. One row per pair. The existing `phpbb_forums` table keeps `parent_id` for direct parent. Tree queries JOIN against the closure table.

**Strengths**:
- Most flexible tree model: any tree query (ancestors, descendants, subtree, depth-limited) is a simple JOIN
- Inserts are cheap: add N closure rows (N = depth of insertion point)
- Deletes are clean: remove all closure rows referencing the node
- No shifting of sibling data; O(N) where N = subtree size for structural changes
- Can coexist with `left_id`/`right_id` during transition (additive, not replacing)

**Weaknesses**:
- **New table required**: `forum_closure` with O(n*d) rows (n nodes * avg depth)
- Move operations require deleting and reinserting closure rows for entire subtree — complexity comparable to nested set
- No inherent sibling ordering — requires `position` column (same as materialized path)
- DFS pre-order traversal requires additional sorting logic (not natural like `ORDER BY left_id`)
- Data migration: must generate closure table from existing `parent_id` or `left_id`/`right_id`
- More JOINs for every query: `SELECT f.* FROM phpbb_forums f JOIN forum_closure c ON ...`

**Best when**: The application needs frequent ad-hoc tree queries (arbitrary depth slicing, DAG support) and write performance is more important than read simplicity.

**Evidence**: Research report §7.2 shows current read queries use `ORDER BY left_id` for DFS — closure table would need `ORDER BY` computed from path depth + position. Synthesis §5.4 shows `getTree()` → `getSubtree()` → `hydrateNodes()` pipeline relies on ordered node IDs, which nested set provides naturally.

---

### Trade-Off Matrix: Nested Set Implementation

| Perspective | 2A: Port Legacy | 2B: Fresh Nested Set | 2C: Materialized Path | 2D: Closure Table |
|---|---|---|---|---|
| **Technical Feasibility** | HIGH — proven math, known SQL | MEDIUM — proven theory, new implementation | MEDIUM — requires data migration + schema change | MEDIUM — new table, migration |
| **User Impact** | None — invisible change | None — invisible | None if migration is smooth | None if migration is smooth |
| **Simplicity** | HIGH — known patterns | MEDIUM — new code, same theory | MEDIUM — simpler concept, but `LIKE` queries | LOW — extra table, JOINs, no natural ordering |
| **Risk** | LOW — battle-tested math | MEDIUM — new bugs possible | HIGH — breaking change to core data model | HIGH — new table, complex moves |
| **Scalability** | MEDIUM — O(n) writes, O(1) reads | MEDIUM-HIGH — gaps reduce shifts | HIGH writes, MEDIUM reads | MEDIUM both — O(subtree) moves |

### Recommendation: Alternative 2A — Port Legacy `nestedset.php` to PDO (Refined)

**Rationale**: The nested set algorithm is proven over 17 years. The existing `left_id`/`right_id` data in production requires zero migration. Write frequency for tree mutations is very low (admin-only operations), so O(n) shifts are acceptable. The key improvement is unifying the dual implementation (ACP raw SQL + OOP class) into a single `TreeService`.

**Key trade-offs accepted**: O(n) row shifts on mutations; advisory locking requirement. These are acceptable for infrequent admin operations on typically small trees (< 500 forums).

**Key assumptions**: Forum tree mutations remain admin-only and infrequent. If a future feature (user-created categories, real-time tree editing) changes this, revisit toward 2C or 2D.

**Why not 2B**: Same math, more risk. A clean-room rewrite invites subtle bugs without proportional benefit. Gap-based numbering adds complexity that isn't needed for small, infrequently-mutated trees.
**Why not 2C**: Breaking backward compatibility for an algorithm that works. Materialized paths don't provide DFS ordering natively, which is the primary read pattern (`ORDER BY left_id`).
**Why not 2D**: Highest complexity for the least common queries. Closure tables shine when tree traversal patterns are diverse and unpredictable — forum trees have exactly two patterns (full tree + subtree), both well-served by nested set.

---

## Decision Area 3: Service Decomposition

### Why This Decision Matters

The number and granularity of services determines testability, cognitive load for contributors, deployment flexibility, and the surface area for plugin integration. Too few services recreate the legacy monolith; too many create shotgun surgery for simple changes.

### Alternative 3A: Five Services (Research Report Proposal)

**Description**: `HierarchyService` (facade), `ForumRepository` (CRUD), `TreeService` (nested set), `TrackingService` (read status), `SubscriptionService` (watch/notify). Each has its own interface. The facade coordinates operations of all four.

**Strengths**:
- Clean separation of concerns: tree math, persistence, tracking, and subscriptions are genuinely independent
- Each service is independently testable with mock dependencies
- Tracking and subscriptions have completely different schemas, update patterns, and UI flows (synthesis §4, insight 2)
- Facade provides a convenient single entry point while preserving internal modularity
- Plugins can depend on specific sub-services (e.g., only `TreeServiceInterface`)
- Aligns with research report's detailed interface signatures (§5.1–5.5)

**Weaknesses**:
- Five interfaces + five implementations + facade + plugin interface = significant initial surface area
- Facade may become a thin pass-through that adds indirection without value
- Simple operations (e.g., "display forum index") require coordinating 3+ services
- DI configuration has 5+ service definitions

**Best when**: The team values testability and expects tracking, subscriptions, and tree operations to evolve independently.

**Evidence**: Synthesis §4 insight 2: "Tracking and subscriptions are separate concerns" with "completely different schemas, different update patterns, different UI flows." Research report §4.1–4.6 provides detailed responsibility boundaries. Synthesis §8 conclusion 2: "Five services are needed."

---

### Alternative 3B: Three Services (Merged Tracking + Subscriptions)

**Description**: `HierarchyService` (facade + tracking + subscriptions), `ForumRepository` (CRUD), `TreeService` (nested set). Tracking and subscription methods live directly on the facade since they're always accessed in the context of forums.

**Strengths**:
- Fewer classes: 3 vs. 5, less DI configuration
- Callers always go through one service — no need to decide which service to call
- Tracking and subscriptions are small enough (5-6 methods each) to coexist without bloating the facade
- Simpler mental model for contributors

**Weaknesses**:
- `HierarchyService` grows large: CRUD + tree coordination + tracking + subscriptions = 20+ public methods
- Harder to test tracking logic in isolation — must instantiate or mock the full facade
- If tracking evolves (e.g., Redis-backed tracking, real-time notifications), it's trapped inside the facade
- Violates SRP: the facade now does coordination AND domain logic

**Best when**: The codebase is small, the team is small, and reducing class count matters more than strict separation.

**Evidence**: Tracking methods are short (synthesis §7.3: 4 modes, ~230 LOC for all of `markread()`). Subscription methods are even simpler. But synthesis §4 insight 2 explicitly recommends against merging them because they have "completely different schemas."

---

### Alternative 3C: Six Services (Separate Read Model)

**Description**: Same as 3A, plus a `ForumTreeReadModel` that maintains a denormalized, pre-computed tree structure optimized for display. The read model is rebuilt on tree mutations (event-driven) and provides instant tree retrieval without nested set JOIN queries.

**Strengths**:
- Display queries hit a pre-computed structure — zero nested set math at read time
- Naturally supports caching: the read model IS the cache, rebuilt on mutation events
- Decouples read patterns (frequent) from write patterns (rare)
- Could back the read model with Redis/memcached for high-traffic boards
- Read model handles `forum_parents` denormalization automatically

**Weaknesses**:
- Eventual consistency: mutations must be followed by read model rebuild
- One more service to implement, test, and maintain
- Cache invalidation complexity: which mutations trigger which read model updates?
- Overkill for most phpBB installations (< 100 forums, served from MySQL query cache)
- Legacy `forum_parents` column already serves as a read model (crude but functional)

**Best when**: Forum tree reads are extremely frequent (high-traffic portal pages), the tree is large (500+ forums), and the team is comfortable with eventual consistency.

**Evidence**: Synthesis §3.1 notes `forum_parents` is "Fragile but effective" — it's already a poor man's read model. Research report §7.2 shows `getFullTree()` is a single `SELECT * ORDER BY left_id` — already very fast. A dedicated read model adds complexity without clear evidence of need.

---

### Alternative 3D: Four Services (No Facade)

**Description**: `ForumRepository`, `TreeService`, `TrackingService`, `SubscriptionService` — four independent services with no facade. Controllers and consumers compose whichever services they need via DI. No coordinating layer.

**Strengths**:
- No facade class to maintain — one less layer of indirection
- Each service is truly independent with its own interface
- Controllers inject only what they need (ISP — Interface Segregation Principle)
- No risk of facade becoming a god class

**Weaknesses**:
- Cross-cutting operations (create forum = insert + position + event dispatch + plugin notify) must be coordinated somewhere — either in controllers or in a "use case" class, recreating the facade
- Event dispatch becomes each service's responsibility or duplicated in controllers
- Plugin coordination (calling `onForumCreated` for all plugins) has no natural home
- Multiple consumers doing the same orchestration = DRY violation

**Best when**: The team prefers thin services and wants controllers to own the orchestration. Works if the number of consumers is very small (1-2 controllers).

**Evidence**: Research report §5.3/5.4 data flows show operations that span multiple services (create = insert + position + events + plugins). Without a facade, this orchestration logic moves to controllers, which is the anti-pattern synthesis §2.3 identifies ("Inline Validation: mixes validation + persistence").

---

### Trade-Off Matrix: Service Decomposition

| Perspective | 3A: Five Services | 3B: Three (Merged) | 3C: Six (Read Model) | 3D: Four (No Facade) |
|---|---|---|---|---|
| **Technical Feasibility** | HIGH | HIGH | MEDIUM — read model rebuild logic | HIGH |
| **User Impact** | Neutral | Neutral | Better display perf on large trees | Neutral |
| **Simplicity** | MEDIUM — 5 classes | HIGH — 3 classes | LOW — 6 classes + rebuild | MEDIUM — 4 classes, dispersed orchestration |
| **Risk** | LOW | LOW — but SRP violation | MEDIUM — consistency edge cases | MEDIUM — orchestration duplication |
| **Scalability** | HIGH | MEDIUM — facade bloat | HIGHEST — read path scales independently | HIGH — but orchestration doesn't |

### Recommendation: Alternative 3A — Five Services

**Rationale**: Tracking and subscriptions are genuinely separate concerns per synthesis evidence. The facade provides a natural coordination point for cross-cutting operations (event dispatch, plugin notification, transaction orchestration). Five services is the sweet spot between monolith (3B) and over-engineering (3C).

**Key trade-offs accepted**: More initial surface area. Facade risks becoming a pass-through. Acceptable because the facade owns orchestration logic that doesn't belong in any sub-service.

**Key assumptions**: Tracking and subscriptions will evolve independently (e.g., Redis-backed tracking, webhook-based subscriptions). If they're permanent frozen in their current form, 3B is simpler.

**Why not 3B**: Merging tracking+subscriptions into the facade violates SRP and makes independent testing harder. Synthesis explicitly recommends against this.
**Why not 3C**: Premature optimization. `SELECT * FROM phpbb_forums ORDER BY left_id` on a typical 50-200 forum tree is sub-millisecond. A read model adds complexity without demonstrated need.
**Why not 3D**: Orchestration logic (create = insert + position + events + plugins) must live somewhere. Without a facade, it migrates to controllers, creating duplication and coupling.

---

## Decision Area 4: Plugin Architecture

### Why This Decision Matters

The plugin architecture determines how extensions add custom forum types, behaviors, and data to the hierarchy. It must balance discoverability (easy to find what plugins are loaded), developer experience (easy to write a plugin), and alignment with phpBB conventions (existing extension developers already know the patterns). This decision also affects the event model and hook surface.

### Alternative 4A: `service_collection` + Tagged Services (phpBB Pattern)

**Description**: Plugins implement `HierarchyPluginInterface` and register as DI services with a `hierarchy.plugin` tag. A compiler pass auto-discovers tagged services into an `ordered_service_collection`. The `HierarchyService` facade iterates the collection at lifecycle points (create, delete, validate). Extension hooks via event dispatcher complement the plugin interface.

**Strengths**:
- Proven phpBB pattern: auth providers, notification types, OAuth services all use it (synthesis §4, insight 5)
- Familiar to phpBB extension developers — zero learning curve for the registration mechanism
- Lazy loading: plugins aren't instantiated until first use (via service container proxy)
- Ordering via `order` tag attribute for predictable execution
- IDE-friendly: tagged services appear in DI configuration, easy to discover
- Dual extensibility: interface for deep integration, events for lightweight hooks

**Weaknesses**:
- Compiler pass is a build-time concept — no runtime plugin registration
- Plugin interface must be designed upfront; adding methods is a BC break
- Debug discovery: finding which plugins are loaded requires inspecting container or config
- All plugins must implement the full interface (or use an abstract base with no-op defaults)

**Best when**: Following phpBB conventions is important, the plugin surface is well-defined upfront, and the team wants the same patterns used everywhere in the platform.

**Evidence**: Synthesis §4 insight 5: "The `service_collection` pattern is the right plugin architecture. Battle-tested, understood by phpBB developers, IDE-friendly." Research report §6.2 provides complete DI YAML configuration. All phpBB core plugin systems use this pattern.

---

### Alternative 4B: PHP 8.2 Attributes for Discovery

**Description**: Plugins annotate their class with `#[HierarchyPlugin(name: 'custom_wiki', types: [ForumType::Forum])]`. A compiler pass scans for the attribute instead of DI tags. Registration is reduced to the class annotation + standard service definition (or even auto-wired).

**Strengths**:
- Self-documenting: the plugin's metadata lives on the class itself, not in YAML config
- PHP 8.2 native feature — no custom tag/compiler pass concepts to learn
- Reduces configuration: no `tags: [{name: hierarchy.plugin}]` in YAML; attribute IS the tag
- IDE can navigate to the attribute definition for documentation
- Compile-time validation: attributes can enforce required parameters

**Weaknesses**:
- Breaks phpBB convention: no existing phpBB extension system uses PHP attributes for service discovery
- Requires a custom compiler pass to scan for attributes anyway — adds to framework complexity
- Existing extension developers must learn a new pattern
- Attribute scanning is slower than tag-based discovery (reflection vs. configuration)
- Cannot express ordering easily (attributes have no equivalent of tag `order` parameter without adding an `order` parameter to the attribute)

**Best when**: Building outside the phpBB ecosystem, or leading a convention migration where PHP 8.2 attributes become the standard for all discovery.

**Evidence**: Research report §6.4 documents the existing compiler pass → tag discovery flow. Synthesis §3.1 shows all current plugin systems use tags. Adopting attributes would be a convention break not justified by proportional benefit for this single service.

---

### Alternative 4C: Interface-Only (No Auto-Discovery)

**Description**: Extensions implement `HierarchyPluginInterface` and explicitly register their plugin in their extension's `services.yml` with a specific service name pattern (`hierarchy.plugin.*`). No compiler pass, no service collection, no tags. The `HierarchyService` receives plugins as explicit constructor arguments.

**Strengths**:
- Maximum simplicity: no compiler pass, no tag system, no service collection
- Completely transparent: the DI configuration explicitly lists every plugin
- Easy to debug: constructor arguments are visible in the service definition
- No framework magic — any DI container (even manual wiring) works

**Weaknesses**:
- Doesn't scale: adding a plugin requires modifying the core service definition (or using YAML merging)
- No lazy loading: all plugins instantiated at construction time
- No ordering mechanism without manual argument ordering
- Breaks phpBB convention: extensions normally register services with tags for auto-discovery
- Extensions must know the exact constructor parameter name/position in the core service

**Best when**: The system has a fixed, small number of plugins (2-3) and auto-discovery overhead isn't justified.

**Evidence**: Research report §6.4 explicitly describes the auto-discovery flow as a strength. Synthesis §3.1 identifies `service_collection` as "phpBB standard" with "lazy loading prevents performance penalty." The interface-only approach loses these benefits.

---

### Alternative 4D: Event-Only Architecture (No Plugin Interface)

**Description**: No `HierarchyPluginInterface`. All extensibility via the event dispatcher. Extensions register event listeners for `hierarchy.forum.pre_create`, `hierarchy.forum.post_create`, etc. Custom forum types register via a configuration event (`hierarchy.types.register`) dispatched at boot. Custom attributes via `hierarchy.display.enrich` event.

**Strengths**:
- Simplest plugin API: just subscribe to events, no interface to implement
- Loosely coupled: plugins and core service don't share an interface contract
- Familiar pattern: phpBB's existing `core.acp_*` events work this way
- Adding new extension points = adding new events (no interface BC break)
- Multiple listeners per event, each independently testable

**Weaknesses**:
- Loose typing: event payloads are arrays, not typed contracts
- No compile-time guarantee that a plugin handles required lifecycle events
- Hard to discover what events exist — requires documentation or code reading
- Custom forum type registration via events is awkward (when does the event fire? who collects responses?)
- Event listeners can't return values cleanly — must mutate event data (phpBB compact/extract pattern)
- Validation becomes scattered: each listener adds its own validation, no aggregation mechanism

**Best when**: Extension points are lightweight (logging, analytics, side effects) rather than structural (new forum types, custom validation).

**Evidence**: Synthesis §3.1 notes events use "compact()/extract() pattern" which is "phpBB standard but awkward." Research report §8.3 shows events for CRUD hooks but keeps structural extension (types, validation) on the interface. The dual approach (interface + events) from the research report covers both deep and lightweight extension needs.

---

### Trade-Off Matrix: Plugin Architecture

| Perspective | 4A: service_collection | 4B: PHP Attributes | 4C: Interface-Only | 4D: Events-Only |
|---|---|---|---|---|
| **Technical Feasibility** | HIGH — existing pattern | MEDIUM — new compiler pass | HIGH — simplest | HIGH — existing dispatcher |
| **User Impact** | Best DX for phpBB devs | New pattern to learn | Manual wiring = friction | Familiar but fragile |
| **Simplicity** | MEDIUM — tags + collection | MEDIUM — attributes + scanning | HIGH — explicit wiring | MEDIUM — many events |
| **Risk** | LOW — proven in phpBB | MEDIUM — convention break | LOW | MEDIUM — loose typing |
| **Scalability** | HIGH — lazy loading, ordered | HIGH — attribute metadata | LOW — manual wiring | MEDIUM — listener proliferation |

### Recommendation: Alternative 4A — `service_collection` + Tagged Services

**Rationale**: This is the phpBB standard. It's battle-tested, familiar to extension developers, supports lazy loading, and provides ordering. The research report's dual approach (interface for structural extension + events for lightweight hooks) is the best of both worlds. No convention break, no learning curve.

**Key trade-offs accepted**: Compiler pass required (already exists in phpBB). Interface must be designed upfront (mitigated by providing an `AbstractHierarchyPlugin` base class with no-op defaults).

**Key assumptions**: phpBB's DI and service collection infrastructure remains available in the new architecture. If the project migrates away from Symfony DI, this approach breaks.

**Why not 4B**: Convention break for marginal benefit. phpBB developers know tags; introducing attributes creates inconsistency across the platform.
**Why not 4C**: Doesn't scale for third-party extensions. Manual wiring creates coupling between core and extensions.
**Why not 4D**: Events alone can't express structural concepts (custom forum types, validation rules) cleanly. The interface provides a typed contract for deep integration.

---

## Decision Area 5: Tree Operations API Style

### Why This Decision Matters

The API style determines how consumers (controllers, ACP modules, CLI tools) interact with tree operations, how plugins hook into the operation lifecycle, and whether undo/redo or audit logging is feasible. It also affects the complexity of the facade and the learning curve for new contributors.

### Alternative 5A: Direct Service Methods

**Description**: `HierarchyService` exposes methods like `moveForum(int $forumId, int $newParentId)`, `reorderForum(int $forumId, int $delta)`, `createForum(CreateForumData $data)`. Each method directly calls the tree service, repository, event dispatcher, and plugins. DTOs for input, entities for output.

**Strengths**:
- Simplest API: call a method, get a result — no intermediate objects
- IDE-friendly: method names appear in autocomplete with parameter types
- Easy to test: mock the service, assert method calls
- Minimal indirection: controller → facade method → tree + repository
- Natural home for transaction boundaries: method = transaction scope
- Research report §5.1 already provides complete interface signatures for this approach

**Weaknesses**:
- No command object = no automatic undo/redo story
- Plugin hooks are positional (pre/post events), not composable
- Audit logging requires explicit `$this->logger->log()` in each method
- Complex operations (delete with content migration + subforum handling) need many parameters

**Best when**: The operation set is stable, undo/redo is not required, and the team values simplicity over flexibility.

**Evidence**: Research report §5.1 `HierarchyServiceInterface` uses this exact pattern with clear method signatures. Legacy `acp_forums.php` uses direct function calls. Synthesis §5.3/5.4 data flows show straightforward method → method chains.

---

### Alternative 5B: Command Pattern (CQRS-Lite)

**Description**: Each operation is a command object: `MoveForumCommand`, `CreateForumCommand`, `DeleteForumCommand`, `ReorderForumCommand`. A `HierarchyCommandHandler` processes commands. Commands are immutable value objects. Results are returned as `CommandResult` objects.

```php
$cmd = new MoveForumCommand(forumId: 5, newParentId: 2);
$result = $handler->execute($cmd);
```

**Strengths**:
- Commands are serializable — enables audit trails, undo history, and event sourcing
- Each command is independently validatable before execution
- Command middleware pipeline: logging → validation → authorization → execution → events
- Plugins can register command decorators or middleware
- Clean separation: command construction (controller) vs. execution (handler)
- Testable: assert commands were dispatched with correct parameters

**Weaknesses**:
- Significant boilerplate: one command class + one handler per operation (10+ classes for basic CRUD+tree ops)
- Over-engineering for a system where undo/redo is not a requirement
- Middleware pipeline adds indirection — harder to follow the code path
- phpBB ecosystem has no command bus convention — new pattern to learn
- Transaction boundaries become less obvious (handler vs. middleware responsibility?)

**Best when**: The system needs audit logging, undo/redo, or asynchronous operation processing. Or the team plans to add CQRS later.

**Evidence**: No evidence in synthesis or research report supports command pattern. The user constraints don't mention undo/redo or audit trails. Synthesis §3.2 identifies "Facade (proposed)" not "Command Handler (proposed)" — the research points away from this approach. However, synthesis §5.3 data flow shows pre/post events that could be formalized as middleware.

---

### Alternative 5C: Event-Driven (Domain Events as Primary API)

**Description**: Operations produce domain events as their primary output. `HierarchyService::moveForum()` returns `ForumMovedEvent`. Callers and plugins subscribe to events for side effects. The event log serves as an audit trail. No separate command objects — methods still exist but events drive downstream behavior.

**Strengths**:
- Natural audit trail: event stream records all hierarchy changes
- Loose coupling: side effects (cache invalidation, notification, ACL cleanup) are event listeners
- Events are already planned (research report §8.1 — 14 events) — this makes them first-class
- Replay/debugging: event log enables reconstructing operation history
- Aligns with synthesis §3.1 "Observer" pattern already proven in phpBB

**Weaknesses**:
- Domain events as return values change method signatures: `moveForum()` returns `ForumMovedEvent` instead of `void`
- Eventual consistency concerns if listeners fail: what if the tree moved but the cache listener failed?
- Event ordering: listeners must execute in correct order for consistency
- Higher cognitive load: tracing a "move forum" operation requires following the event chain
- Debugging is harder: "why did X happen?" requires tracing event listeners

**Best when**: The system has many independent side effects per operation, and event-driven architecture is an established team pattern.

**Evidence**: Research report §8.1 already defines 14 events for the hierarchy service. Synthesis §3.1 notes the "Observer" pattern is "already proven in phpBB." However, making events the *primary* API (rather than supplementary hooks) goes beyond evidence — the research report uses events as hooks, not as return values.

---

### Alternative 5D: Fluent Builder for Complex Operations

**Description**: Simple operations use direct methods (`getForum()`, `moveForum()`). Complex operations use a builder pattern:

```php
$hierarchy->deleteForum(42)
    ->moveContentTo(10)
    ->moveSubforumsTo(5)
    ->execute();
```

This replaces the many-parameter `deleteForum(int $forumId, string $contentAction, ?int $moveContentTo, ...)` signature.

**Strengths**:
- Complex operations (delete with content/subforum decisions) become readable
- Builder validates step-by-step: can enforce "must specify content action before execute"
- IDE guides the workflow: `.moveContentTo()` appears after `.deleteForum()`
- Simple operations remain simple (direct method calls)
- Natural plugin hook point: builder collects context, `execute()` runs the full pipeline

**Weaknesses**:
- Builder classes are additional boilerplate (one per complex operation)
- Mixing two API styles (direct methods + builders) increases cognitive load
- Builder state management adds complexity (mutable intermediate state)
- Testing builders requires more setup (build + execute)
- Only 1-2 operations (delete, maybe batch operations) are complex enough to warrant a builder

**Best when**: The API has several multi-step operations with many optional parameters.

**Evidence**: Research report §5.1 shows `deleteForum()` with 5 parameters — a genuine readability concern. However, this is the only method with this complexity. Other methods have 1-3 parameters. Synthesis data flows §5.3/5.4 show creation and display flows are straightforward.

---

### Trade-Off Matrix: Tree Operations API Style

| Perspective | 5A: Direct Methods | 5B: Command Pattern | 5C: Event-Driven | 5D: Fluent Builder |
|---|---|---|---|---|
| **Technical Feasibility** | HIGH — simplest | MEDIUM — boilerplate | MEDIUM — event infrastructure | MEDIUM — builder classes |
| **User Impact** | Best — familiar, simple | Neutral — more to learn | Neutral | Good for complex ops |
| **Simplicity** | HIGHEST | LOW — many classes | MEDIUM — event tracing | MEDIUM — mixed styles |
| **Risk** | LOW | MEDIUM — over-engineering | MEDIUM — consistency concerns | LOW |
| **Scalability** | MEDIUM — params grow | HIGH — composable pipeline | HIGH — decoupled listeners | MEDIUM — per-operation |

### Recommendation: Alternative 5A — Direct Service Methods

**Rationale**: Direct methods with DTOs for input and entities for output is the simplest approach that satisfies all stated requirements. The research report already provides complete interface signatures. Events supplement the API for plugin hooks (not replace it). The one complex method (`deleteForum` with 5 params) can use a DTO (`DeleteForumOptions`) rather than introducing a builder for a single case.

**Key trade-offs accepted**: No automatic undo/redo. No audit trail without explicit logging. These are not stated requirements.

**Key assumptions**: Tree mutation operations remain simple CRUD + move + reorder. If complex multi-step operations proliferate (batch moves, conditional reparenting), reconsider 5B or 5D.

**Why not 5B**: Over-engineering. No evidence of undo/redo or async processing requirements. Introduces ~10 command/handler classes for marginal benefit.
**Why not 5C**: Events should supplement the API, not BE the API. Making events the primary return type changes the entire calling convention. Research report uses events as hooks alongside methods, which is the right balance.
**Why not 5D**: Only `deleteForum()` is complex enough for a builder. A single DTO (`DeleteForumOptions`) solves the parameter problem without introducing a second API style. If more complex operations emerge, builders can be introduced later.

---

## User Preferences (from Stated Constraints)

| Constraint | Impact on Decisions |
|---|---|
| **PHP 8.2+** | Enables enums (`ForumType`), readonly properties, intersection types. All alternatives assume this. |
| **PSR-4, `phpbb\` namespace** | All entity/service classes under `phpbb\hierarchy\*`. |
| **Direct PDO** | No legacy `$db` driver. Parameterized queries. |
| **Symfony DI** | Enables `service_collection`, compiler passes, tagged services. |
| **Plugin architecture** | Strong weight toward 4A (service_collection) and 1C (behavior delegates). |
| **NO ACL responsibility** | Hierarchy returns full data; display/API layer filters by permission. |

---

## Recommended Approach Summary

| Decision Area | Recommended | Runner-Up | Confidence |
|---|---|---|---|
| 1. Entity Model | **1C: Single Entity + Behavior Delegates** | 1A (Single Entity) if plugins won't add types | MEDIUM-HIGH |
| 2. Nested Set Implementation | **2A: Port Legacy to PDO** | 2B (Fresh) if legacy code is too tangled | HIGH |
| 3. Service Decomposition | **3A: Five Services** | 3B (Three) for smaller teams | HIGH |
| 4. Plugin Architecture | **4A: service_collection + Tags** | Unanimous — no close runner-up | HIGH |
| 5. API Style | **5A: Direct Service Methods** | 5D (Builder) if complex ops grow | HIGH |

The recommended combination produces an architecture that:
- Maps cleanly to the existing database (zero data migration)
- Follows phpBB conventions (service_collection, event dispatcher, PSR-4)
- Separates concerns into testable, independent services
- Enables plugin extensibility for custom forum types and behaviors
- Keeps the API simple and familiar (direct methods + events)

---

## Deferred Ideas

| Idea | Why Deferred | Worth Revisiting When |
|---|---|---|
| **Read model / CQRS split** (3C) | No evidence of read performance problems. `ORDER BY left_id` is sub-millisecond for typical trees. | Board exceeds 500 forums or real-time index pages are needed |
| **Materialized path migration** (2C) | Breaking change to working data model. Nested set serves current patterns well. | User-created categories or frequent tree mutations become requirements |
| **Command bus / undo-redo** (5B) | No stated requirement. Adds ~10 classes of boilerplate. | Admin audit logging or undo feature is requested |
| **GraphQL API for tree queries** | Interesting for headless/SPA frontends, but out of scope for the service layer design | Frontend migration to SPA/headless architecture |
| **Async tree mutation processing** | Current ops are synchronous and fast enough. Advisory locking assumes synchronous | High-concurrency admin scenarios or distributed deployment |
| **JSON column for `forum_parents`** | Research report recommends converting from serialized PHP to JSON. Deferred from architecture decision to implementation detail. | During implementation of `TreeService` cache management |
| **Forum archival (soft-delete trees)** | Useful feature but expands scope beyond CRUD + tree operations | Product requirement for archiving/restoring forum subtrees |
