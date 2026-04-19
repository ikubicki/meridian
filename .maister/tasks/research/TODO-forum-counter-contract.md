# TODO: Forum Counter Update Contract (Threads ↔ Hierarchy)

**Source**: Cross-cutting assessment §7.5
**Priority**: ⚠️ High — must be resolved before Threads service implementation
**Status**: 🔜 Needs dedicated research

## Problem

Threads HLD assumes it can call `$hierarchyService->updateForumStats()` and `$hierarchyService->updateForumLastPost()` synchronously within the same transaction.

Hierarchy HLD **does not define** these methods on any public interface. The `HierarchyService` facade and `ForumRepository` don't list them.

This is a **one-way dependency assumption** — Threads expects an API that Hierarchy hasn't committed to providing.

## Questions to Answer

1. **API ownership**: Should Hierarchy expose `updateForumStats()` / `updateForumLastPost()` as part of its public interface? Or should Threads write directly to `phpbb_forums` columns?
2. **Transaction boundary**: If Threads calls Hierarchy synchronously, how is the transaction coordinated across two services sharing PDO? Does Hierarchy own the transaction, Threads, or a coordinator?
3. **Event-driven alternative**: Should counter updates be **async via events** instead of synchronous calls? Threads emits `PostCreatedEvent`, Hierarchy listens and updates its own counters. Simpler contract but eventual consistency.
4. **Counter consistency**: If event-driven, how to handle the window where counters are stale? Is eventual consistency acceptable for forum stats?
5. **Batch recalculation**: Should there be a `recalculateForumStats()` job for self-healing after failures?

## Acceptance Criteria

- [ ] Agreed contract between Threads and Hierarchy for counter updates
- [ ] Decision: synchronous call vs event-driven vs hybrid
- [ ] Hierarchy HLD updated with counter update methods if synchronous
- [ ] Transaction coordination strategy documented
