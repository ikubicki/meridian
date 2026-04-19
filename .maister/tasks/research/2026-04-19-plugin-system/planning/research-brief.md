# Research Brief: Unified Plugin System

## Research Question

How to design a unified plugin system that enables domain service extension through request/response decorators, events, JSON metadata on main records, and plugin-owned tables?

## Research Type

**Mixed** — combines technical (codebase patterns), requirements (extensibility needs), and literature (plugin architecture best practices).

## Context

The phpBB platform is being rebuilt as isolated domain services (Auth, User, Threads, Hierarchy, Search, Notifications, Messaging, Cache, Storage). Each service already uses:
- **DecoratorPipeline** pattern for request/response decoration
- **Symfony EventDispatcher** for domain events
- **JSON columns** for extensible data (e.g., `profile_fields`, `preferences` on users)

A unified plugin system must provide a consistent way for third-party plugins to:
1. Hook into any service's decorator pipeline (add request/response decorators)
2. Subscribe to domain events across services
3. Store plugin-specific data on existing records via a `metadata` JSON field
4. Create and manage their own database tables when needed

## Scope

### Included
- Plugin lifecycle management (discovery, registration, activation, deactivation, uninstall)
- Integration with existing DecoratorPipeline pattern
- Integration with Symfony EventDispatcher
- JSON metadata field pattern on domain entities (how plugins read/write their data)
- Plugin-owned table schema management (creation, migration, removal)
- Plugin manifest/descriptor format
- DI container integration (plugin services registration)
- Plugin dependencies and version constraints
- Cross-service plugin capabilities (plugin extending multiple services)

### Excluded
- Legacy phpBB ext system backward compatibility
- Frontend/theme extension (focus on backend domain logic)
- Detailed admin UI design (only API/contract level)
- Plugin marketplace/distribution

### Constraints
- PHP 8.2+ (readonly classes, enums, match expressions)
- PDO with prepared statements (no legacy DBAL)
- Symfony DI container (YAML config)
- Symfony EventDispatcher
- Existing DecoratorPipeline (RequestDecoratorInterface, ResponseDecoratorInterface)
- PSR-4 under `phpbb\` namespace
- Must work with all domain services uniformly

## Success Criteria

1. Clear plugin manifest format that declares capabilities (decorators, events, metadata, tables)
2. Lifecycle management contract (install → activate → deactivate → uninstall)
3. Safe metadata access pattern (read/write JSON metadata on domain records without coupling)
4. Table schema management workflow (plugin declares DDL, system executes during install)
5. Integration points with DecoratorPipeline documented
6. Integration points with EventDispatcher documented
7. Plugin isolation guarantees (one plugin can't break another)
