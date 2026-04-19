# Research Brief: Database Service

## Research Question

How to design a database service for the phpBB rebuild that provides:
1. **Declarative YAML→DDL schema management** — tables defined in YAML, compiled to platform-specific DDL
2. **Schema versioning** — track schema state, diff between versions, generate incremental DDL
3. **Multi-engine support** — MySQL 8+, PostgreSQL 14+, SQLite (dev/test)

## Research Type

**Mixed** — Technical (architecture patterns, implementation approaches) + Literature (industry best practices for schema management)

## Scope

### Included
- Schema definition format (YAML structure for tables, columns, indexes, constraints)
- YAML→DDL compilation pipeline (parser, type mapper, DDL generator per engine)
- Schema versioning strategy (snapshot-based diff, version tracking)
- Schema introspection (reading current DB state)
- Multi-engine abstraction (type mapping, SQL dialect differences)
- Connection management (PDO wrapper, connection pooling concepts)
- Query builder (type-safe query construction, parameterized queries)
- Integration with plugin system (`schema.yml` from plugin research DA-5)
- Transaction management

### Excluded
- Legacy phpBB DBAL (does not exist — greenfield)
- ORM / Entity mapping (explicit design decision: raw PDO + query builder)
- NoSQL databases
- Replication / clustering / sharding
- Database-specific advanced features (partitioning, materialized views)

### Constraints
- PHP 8.2+ (readonly classes, enums, match expressions)
- PDO as the base driver abstraction
- Symfony DI container for service wiring
- Must serve as the foundation for plugin system's `schema.yml` (DA-5)
- No Doctrine ORM (but Doctrine DBAL patterns may inspire)

## Success Criteria

1. Clear YAML schema format specification covering all common DDL operations
2. Architecture for multi-engine DDL generation (extensible to new engines)
3. Schema diff algorithm design (detect additions, removals, modifications)
4. Versioning strategy (how state is tracked, how diffs are computed)
5. Query builder API design (type-safe, multi-engine)
6. Connection management pattern
7. Integration point with plugin system clearly defined

## Context from Prior Research

The plugin system research (`.maister/tasks/research/2026-04-19-plugin-system/`) selected **DA-5: Declarative YAML→DDL** as the schema management approach. The plugin HLD defines:
- `SchemaCompiler` — parses YAML, validates types
- `SchemaIntrospector` — reads current DB state
- `SchemaDiffer` — compares declared vs current state
- `DdlGenerator` — converts diff operations to SQL
- `SchemaExecutor` — runs DDL

This research should design the **full database service** that these plugin-system components are built upon.
