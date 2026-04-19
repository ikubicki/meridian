# Research Brief: phpbb\cache Utility Service

## Research Question

How should the `phpbb\cache` utility service be designed to provide a reusable caching layer with filesystem default and configurable external backends (Redis, Memcached, database), usable by all other phpbb services?

## Research Type

**Mixed** — Technical (legacy analysis + interface design) + Literature (PSR standards, industry patterns)

## Context

This is a foundational utility service that ALL other phpbb services depend on:
- `phpbb\threads` — query caching, counter caching, metadata caching
- `phpbb\messaging` — counter caching, conversation list caching
- `phpbb\storage` — metadata caching, quota caching
- `phpbb\auth` — permission caching, session data
- `phpbb\user` — profile caching

The legacy system already has a functional cache with 7 drivers (file, Redis, Memcached, APCu, WinCache, memory, dummy). The new service should modernize the interface while preserving the multi-backend architecture.

## Legacy System Summary

- **Location**: `src/phpbb/forums/cache/`
- **Architecture**: Service wrapper + driver strategy pattern
- **Drivers**: file (default), redis, memcached, apcu, wincache, memory, dummy
- **Data types cached**: SQL query results, domain data (ACL, bots, icons, word censors, extensions), compiled containers, routing
- **Configuration**: `config.php` → `acm_type` → DI parameter → driver class
- **No external libraries** — all drivers are native implementations

## Scope

### Included
- Legacy cache system analysis (drivers, service, data types)
- Cache driver interface design (PSR-6/PSR-16 compliance)
- Backend configuration (filesystem, Redis, Memcached, APCu, database)
- Cache key namespacing for multi-service usage
- TTL strategies and invalidation
- Cache warming and stampede prevention
- Serialization strategies
- Integration with DI container
- Tag-based invalidation

### Excluded
- HTTP caching / CDN
- Browser-side caching
- Compiled container/routing cache (Symfony infrastructure)
- Template caching (Twig handles its own)

### Constraints
- Reusable by ALL phpbb services
- Default to filesystem (zero-config simple deployments)
- Event-driven architecture (consistent with other services)
- PSR-4 namespace: `phpbb\cache`
- PHP 8.2+, MySQL/MariaDB
- No legacy extension system
- DI via Symfony container

## Design Questions

1. **Interface**: Should the service implement PSR-6 (Cache Item Pool) or PSR-16 (Simple Cache) or both? Or a custom interface?
2. **Namespacing**: How should cache keys be namespaced per-service to avoid collisions?
3. **Backends**: Which backends to support? How to configure/swap at runtime?
4. **Invalidation**: How to handle cache invalidation — TTL only, event-driven, tag-based, or combination?
5. **Serialization**: PHP serialize, JSON, igbinary, msgpack — which strategy?
6. **Stampede protection**: How to prevent cache stampede on expiry (locking, probabilistic early expiry)?
7. **Multi-tier**: Should the service support multi-tier caching (e.g., APCu L1 + Redis L2)?
8. **SQL caching**: Should SQL query caching be part of this service or handled by the DB layer?

## Success Criteria

1. Clear interface contract usable by any service without coupling to backend
2. Zero-config filesystem default that works out of the box
3. Simple configuration to switch to Redis/Memcached
4. Namespace isolation — services can't accidentally overwrite each other's keys
5. Efficient invalidation strategy (not just global purge)
6. PSR compliance consideration (interop with ecosystem)
7. Migration path from legacy `phpbb\forums\cache\*`
8. Performance ≥ legacy system (no regression)
