# Research Plan: Database Service

## Research Overview

**Research Question**: How to design a database service for phpBB rebuild that provides declarative YAML→DDL schema management, schema versioning, and multi-engine support (MySQL 8+, PostgreSQL 14+, SQLite)?

**Research Type**: Mixed (Technical + Literature)

**Scope**: YAML schema format, YAML→DDL pipeline, schema versioning, schema introspection, multi-engine abstraction, connection management, query builder, plugin system integration, transactions.

**Constraints**: PHP 8.2+, PDO, Symfony DI, no Doctrine ORM, must serve as foundation for plugin system's `schema.yml`.

---

## 1. Research Objectives

### Primary Questions

1. **YAML Schema Format** — What structure best expresses tables, columns, indexes, constraints, and foreign keys in a declarative YAML document?
2. **YAML→DDL Pipeline** — How to compile YAML definitions into platform-specific DDL statements across MySQL 8+, PostgreSQL 14+, SQLite?
3. **Schema Versioning** — How to track schema state over time; compute diffs; generate incremental DDL?
4. **Multi-Engine Abstraction** — How to abstract type mapping, SQL dialect differences, and engine-specific features without leaking?
5. **Query Builder** — What API design provides type-safe, multi-engine query construction with parameterized queries?
6. **Connection Management** — How to manage PDO connections, transactions, and retry logic cleanly?
7. **Integration** — How does this service become the foundation for plugin system's `SchemaCompiler`, `SchemaDiffer`, `DdlGenerator`?

### Sub-Questions

- What abstract column types map across all three engines without loss?
- How to handle engine-specific constraints (MySQL `UNSIGNED`, PostgreSQL `CHECK`, SQLite type affinity)?
- What schema introspection API reads INFORMATION_SCHEMA / sqlite_master uniformly?
- How do existing services (threads, hierarchy, users) consume the database service?
- How to safely handle destructive schema changes (drop column/table)?
- What transaction isolation levels and advisory locking patterns are needed?

---

## 2. Methodology

### Approach: Multi-Source Analysis

1. **Internal Pattern Extraction** — Read existing HLDs (threads, hierarchy, users, plugin system) to extract established conventions for DB usage (repository pattern, PDO injection, entity hydration, two-phase pagination).
2. **Industry Solution Analysis** — Study Doctrine DBAL, Laravel Schema Builder, Phinx, cycle/database for patterns in type abstraction, DDL generation, schema diffing, query building.
3. **Engine Documentation** — Review MySQL 8, PostgreSQL 14, SQLite DDL syntax differences for type mapping tables.
4. **Synthesis** — Combine internal conventions with best industry patterns, respecting project constraints (no ORM, PDO-based, Symfony DI).

### Analysis Method

- Extract → Compare → Evaluate → Synthesize
- Each finding rated on: complexity, fit with project constraints, extensibility, plugin system compatibility

---

## 3. Gathering Strategy

### Instances: 5

| # | Category ID | Focus Area | Key Sources | What to Extract |
|---|------------|------------|-------------|-----------------|
| 1 | `internal-patterns` | How existing services use the database | Threads/Hierarchy/Users HLDs, repository interfaces | Repository pattern, PDO usage, entity hydration, pagination, transaction handling, naming conventions |
| 2 | `schema-management` | YAML→DDL compilation and schema diffing | Plugin system HLD (Schema System section), Doctrine DBAL Schema, Phinx | Schema definition format, diff algorithms, operation types, type mapping, DDL generation strategies |
| 3 | `multi-engine` | Cross-engine type mapping and SQL dialect differences | MySQL 8 docs, PostgreSQL 14 docs, SQLite docs, Doctrine DBAL type system | Type mapping tables, DDL syntax differences, introspection queries per engine, unsupported features per engine |
| 4 | `query-builder` | Query builder API design patterns | Doctrine DBAL QueryBuilder, Laravel Query Builder, CakePHP Database, Cycle DBAL | Fluent API patterns, expression building, parameter binding, join handling, subqueries, raw escape hatches |
| 5 | `versioning-connection` | Schema versioning strategy + connection management | Phinx, Flyway concepts, Laravel migrations, PDO connection pooling patterns | Version tracking tables, snapshot vs migration approach, rollback strategies, connection lifecycle, transaction management |

### Rationale

The 5-category split isolates concerns that have minimal overlap:
- **Internal patterns** grounds the design in existing project conventions (non-negotiable constraints).
- **Schema management** is the most complex subsystem — needs dedicated deep-dive.
- **Multi-engine** is a pure reference task (documentation extraction, comparison tables).
- **Query builder** is an independent API design question with many prior art examples.
- **Versioning + connection** pairs two smaller topics that share infrastructure concerns.

---

## 4. Analysis Framework

### Evaluation Criteria

| Criterion | Weight | Description |
|-----------|--------|-------------|
| **Project Fit** | 30% | Compatibility with existing conventions (PDO, repository pattern, Symfony DI, no ORM) |
| **Multi-Engine Parity** | 25% | Works identically across MySQL 8+, PostgreSQL 14+, SQLite |
| **Extensibility** | 20% | Plugin system can extend schema/types without modifying core |
| **Simplicity** | 15% | Minimal abstraction layers, readable code, low learning curve |
| **Safety** | 10% | Prevents data loss from schema changes, SQL injection via query builder |

### Synthesis Structure

For each subsystem, the analysis should produce:
1. **Pattern options** — 2-3 viable approaches extracted from sources
2. **Trade-off matrix** — pros/cons scored against criteria
3. **Recommended approach** — with justification
4. **API sketch** — interface/class signatures showing the recommended design
5. **Integration notes** — how it connects to plugin system and existing services

---

## 5. Success Criteria

Research is "enough" when we can answer:

- [ ] YAML schema format is fully specified (column types, constraints, indexes, FKs, table options)
- [ ] Type mapping table covers all abstract types → MySQL/PostgreSQL/SQLite concrete types
- [ ] Schema diff algorithm is designed (what operations exist, how to detect each)
- [ ] Introspection queries defined per engine (how to read current state)
- [ ] DDL generation pattern selected (strategy per engine, how to handle engine-specific syntax)
- [ ] Query builder API sketched (fluent interface, parameter binding, raw expressions)
- [ ] Connection management pattern defined (PDO wrapper, transaction API, reconnection)
- [ ] Schema versioning strategy chosen (snapshot-based diff vs migration files vs hybrid)
- [ ] Integration with plugin system `schema.yml` is concrete (shared types, composition model)
- [ ] Architecture diagram shows all components and their relationships

---

## 6. Expected Outputs

| Output | File | Content |
|--------|------|---------|
| Research findings per category | `analysis/findings/{category-id}.md` | Raw findings organized by sub-question |
| Synthesis report | `outputs/research-report.md` | Combined analysis with recommendations |
| Architecture design | `outputs/high-level-design.md` | Component architecture, API sketches, type mappings |
| Type mapping reference | `outputs/type-mapping.md` | Complete abstract→concrete type mapping table |
