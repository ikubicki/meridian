# Research Brief: Search Service

## Research Question

How should the new `phpbb\search` service be designed to implement legacy search mechanisms (pluggable backends, word indexing, result caching, query parsing) from scratch with complete isolation from the old solution?

## Research Type

**Mixed** — combines technical research (understanding legacy mechanisms) with requirements research (defining new service contracts) and literature research (modern search patterns, best practices).

## Context

The phpBB platform is being rebuilt as a set of isolated services under `phpbb\*` namespace. The Search service must provide full-text search capabilities equivalent to legacy phpBB but built from scratch using modern PHP patterns. The legacy system provides 4 backends (native inverted index, MySQL FULLTEXT, PostgreSQL tsvector, Sphinx) and uses 3 database tables (`phpbb_search_wordlist`, `phpbb_search_wordmatch`, `phpbb_search_results`).

### Key Legacy Mechanisms to Preserve

1. **Pluggable backends** — ability to swap search engine without code changes
2. **Native inverted index** — word splitting → word list → post-word mapping (title vs body)
3. **Query syntax** — operators (+, -, |), parentheses grouping, wildcard (*)
4. **Result caching** — MD5 key → cached post IDs with TTL
5. **Indexing at post time** — create/edit/delete triggers index updates
6. **Common word detection** — high-frequency words flagged and optionally excluded
7. **Word length constraints** — min/max character filters
8. **Special searches** — egosearch (own posts), newposts, unreadposts
9. **Search modes** — return posts vs return topics
10. **Admin controls** — backend selection, index rebuild, settings

### Cross-Service Integration Points

| Service | Integration |
|---------|-------------|
| **Threads** | Post CRUD events → index updates; search returns post/topic IDs for Threads to hydrate |
| **User** | Shadow ban filtering (exclude shadow-banned authors from results unless viewer is the banned user) |
| **Auth** | Permission checks (forum-level read access filtering) |
| **Hierarchy** | Forum tree traversal for forum-scoped searches |

## Scope

### Included

- Search backend plugin architecture (contract + implementations)
- Word indexing pipeline (tokenization, normalization, storage)
- Query parsing (operators, grouping, wildcards)
- Result caching (strategy, invalidation)
- Integration with Threads (event-driven indexing)
- Integration with User (shadow ban filtering)
- Integration with Auth (permission-based result filtering)
- REST API design
- Admin API (backend switch, index rebuild, settings)
- Event/decorator extensibility model
- Database schema (based on existing tables but potentially enhanced)

### Excluded

- Any direct coupling with legacy code
- Legacy DBAL usage (all PDO)
- Global variables or non-DI patterns
- Elasticsearch/Meilisearch (future phase, out of current scope)
- Natural language processing beyond basic tokenization

### Constraints

- PHP 8.2+ (readonly, enums, match, named args)
- PDO prepared statements
- Symfony DI (YAML container config)
- Symfony EventDispatcher
- DecoratorPipeline pattern (from Hierarchy/Threads/User)
- PSR-4 autoloading under `phpbb\search`

## Success Criteria

1. Clear backend contract (`SearchBackendInterface`) that covers all 4 legacy backends
2. Indexing pipeline defined with tokenizer/normalizer/storage separation
3. Query parser specification with all legacy operators supported
4. Result caching strategy with clear invalidation rules
5. Cross-service contracts defined (Threads events consumed, Auth permission checks)
6. REST API endpoints specified
7. Extension points identified (decorators + events)
8. Migration path from legacy tables documented
