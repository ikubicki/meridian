# Research Plan — Search Service

## Research Question

How should the new `phpbb\search` service be designed to implement legacy search mechanisms (pluggable backends, word indexing, result caching, query parsing) from scratch with complete isolation from the old solution?

## Research Type Classification

**Mixed** — Technical (code analysis of 4 legacy backends + schema) + Requirements (cross-service contracts from existing HLDs) + Literature (modern search patterns, tokenization, inverted index design)

## Scope & Boundaries

**In scope:**
- All 4 search backends: fulltext_native, fulltext_mysql, fulltext_postgres, fulltext_sphinx
- Database schema: phpbb_search_wordlist, phpbb_search_wordmatch, phpbb_search_results
- Cross-service integration contracts (Threads, User, Auth, Hierarchy, Cache)
- Admin panel configuration and index rebuild mechanics
- Modern search design patterns and best practices

**Out of scope:**
- Actual implementation code
- Migration plan from old to new
- UI/template changes
- Extension/plugin system for third-party backends

## Methodology

### Primary Approach: Multi-Strategy Mixed Research

1. **Codebase Analysis** — Read all 4 backend implementations + base class to extract: public API contract, algorithm details, configuration keys, caching strategy, permission filtering
2. **Schema Analysis** — Full DDL review of search tables + how each backend uses them differently
3. **Cross-Service Contract Extraction** — Read HLD documents from Threads, User, Auth, Hierarchy, Cache services to identify integration points, events consumed/produced, permission checks needed
4. **Literature Review** — Modern search patterns: inverted index design, tokenization pipelines (stemming, stop words, normalization), query parsing (boolean, phrase, wildcard), result caching, plugin architectures
5. **Configuration Audit** — Admin panel code + config keys in DB dump to understand runtime configuration surface

### Analysis Framework

- **Comparative Matrix** — For each capability (indexing, querying, caching, permissions), compare how all 4 backends handle it
- **Contract Extraction** — Extract the implicit interface that all backends must satisfy
- **Gap Analysis** — Identify capabilities missing in legacy that modern search requires
- **Integration Map** — Map all touch points with other services

## Research Phases

### Phase 1: Broad Discovery
- Identify all search-related files in the codebase
- Map directory structure of search module
- Scan config keys related to search in DB dump
- List all HLD documents from dependent services

### Phase 2: Targeted Reading
- Read base.php to understand shared contract and caching mechanism
- Read each backend (native, mysql, postgres, sphinx) for implementation specifics
- Read acp_search.php for admin configuration surface
- Read cross-service HLD outputs for integration contracts

### Phase 3: Deep Dive
- Trace the full indexing flow (post creation → word extraction → storage)
- Trace the full search flow (query → parse → execute → cache → filter → return)
- Analyze permission filtering mechanism per backend
- Understand result caching (search_key generation, TTL, invalidation)

### Phase 4: Verification
- Cross-reference findings across backends for consistency
- Validate integration assumptions against HLD documents
- Confirm schema understanding matches code usage

## Gathering Strategy

### Instances: 5

| # | Category ID | Focus Area | Tools | Output Prefix |
|---|-------------|------------|-------|---------------|
| 1 | legacy-backends | All 4 search backends: contracts, algorithms, public API, caching, permission filtering | Read, Grep | legacy-backends |
| 2 | database-schema | Full schema analysis of 3 search tables + how each backend uses them differently | Read, Grep | database-schema |
| 3 | cross-service-contracts | Integration with Threads/User/Auth/Hierarchy/Cache — events, contracts, filtering | Read | cross-service-contracts |
| 4 | patterns-literature | Modern search patterns: tokenization, inverted indexes, query parsing, caching, plugin architectures | WebFetch | patterns-literature |
| 5 | admin-configuration | ACP search panel, config keys, index rebuild, settings management | Read, Grep | admin-configuration |

### Rationale

Five gatherers aligned to the 5 key investigation areas from the research brief. Each has a distinct, non-overlapping focus:
- **legacy-backends** focuses on _how_ each backend works (algorithms, API surface)
- **database-schema** focuses on _what_ data structures exist (DDL, indexes, relationships)
- **cross-service-contracts** focuses on _integration points_ with already-designed services
- **patterns-literature** focuses on _external knowledge_ (best practices, modern approaches)
- **admin-configuration** focuses on _operational surface_ (settings, rebuild, admin UX)

## Success Criteria

1. ✅ Complete public interface extracted from all 4 backends (methods, parameters, return types)
2. ✅ Full DDL documented for all 3 search tables with index analysis
3. ✅ Integration contracts defined: events consumed (PostCreated, PostDeleted, VisibilityChanged), permission checks (forum read ACL), shadow ban filtering
4. ✅ Modern search patterns documented: tokenization pipeline stages, inverted index variants, query parser grammar, caching strategies
5. ✅ All config keys identified with defaults, validation rules, and admin panel mapping
6. ✅ Comparative matrix showing how each backend handles: indexing, querying, caching, permissions, configuration

## Expected Outputs

1. **Research Report** (`outputs/research-report.md`) — Consolidated findings across all 5 gathering categories
2. **Solution Exploration** (`outputs/solution-exploration.md`) — Design options and trade-offs for the new service
3. **High-Level Design** (`outputs/high-level-design.md`) — Final architecture: classes, interfaces, DI wiring, event subscriptions, configuration schema
4. **Decision Log** (`outputs/decision-log.md`) — Key architectural decisions with rationale
