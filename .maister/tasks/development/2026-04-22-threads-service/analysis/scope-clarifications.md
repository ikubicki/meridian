# Phase 2 Scope Clarifications

## Decision 1: Transaction boundary ownership
**Answer**: Option A — ThreadsService owns the transaction. Service injects Connection and wraps both repository calls (insertTopic + insertPost) in beginTransaction/commit/rollBack. Keeps repositories single-table, consistent with hierarchy pattern.

## Decision 2: Test refactoring scope
**Answer**: Option A — Full coverage. Refactor TopicsControllerTest, add ThreadsServiceTest, DbalTopicRepositoryTest, DbalPostRepositoryTest. Matches hierarchy module standard.

## Decision 3: Service interface design
**Answer**: Option A — Single ThreadsServiceInterface. One interface covering both topic and post methods. Simpler DI, matches module name.

---

## Scope Boundaries (confirmed)

### In scope:
- New `src/phpbb/threads/` module with `phpbb\threads\` namespace
- Entity: Topic, Post (final readonly)
- DTO: TopicDTO, PostDTO
- Contract: ThreadsServiceInterface, TopicRepositoryInterface, PostRepositoryInterface
- Repository: DbalTopicRepository (phpbb_topics), DbalPostRepository (phpbb_posts)
- Service: ThreadsService (inject Connection + both repos, own cross-table transaction)
- Events: TopicCreatedEvent, PostCreatedEvent
- Controller refactor: TopicsController + PostsController delegate to ThreadsServiceInterface
- DI registration in services.yaml
- Full test suite: ThreadsServiceTest, DbalTopicRepositoryTest, DbalPostRepositoryTest, update TopicsControllerTest

### Out of scope:
- Thread subscriptions, read tracking, locking (deferred to future tasks)
- PostsController refactor beyond the immediate delegation (if separate concern)
- UI changes (no frontend affected)
- Route URL changes (routes stay identical)
- Response shape changes (JSON responses stay identical)
