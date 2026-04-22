# Requirements — Threads Service

## Initial Task Description
Implement a threads service for the phpBB vibed REST API project.

## Q&A Summary

### Phase 1 Clarifications
- **Goal**: Extract to service layer — refactor TopicsController into a proper `phpbb\threads\` module following the hierarchy module pattern
- **Namespace**: `phpbb\threads\`
- **Scope**: Topics + Posts together (phpbb_topics + phpbb_posts)
- **Events**: Yes, follow DomainEventCollection pattern

### Phase 2 Decisions
- **Transaction boundary**: ThreadsService owns cross-table transaction (inject Connection into service)
- **Tests**: Full coverage — ThreadsServiceTest, DbalTopicRepositoryTest, DbalPostRepositoryTest, refactored TopicsControllerTest
- **Interface**: Single ThreadsServiceInterface

### Phase 5 Requirements
- **POST create response**: Return full topic data `{data: {topic}}` (not just `{status: 'created'}`)
- **Operations scope**: All necessary endpoints for a complete solution
- **PostsController**: Yes, ThreadsService covers reply posts (POST /topics/{topicId}/posts)

## Functional Requirements Summary

### API Endpoints (complete solution)
1. `GET /forums/{forumId}/topics` — list topics with pagination, ACL-gated (f_read)
2. `GET /topics/{topicId}` — get single topic, ACL-gated (f_read on forum)
3. `POST /forums/{forumId}/topics` — create topic + first post atomically, ACL-gated (f_post), **returns full topic data**
4. `GET /topics/{topicId}/posts` — list posts in a topic with pagination, ACL-gated (f_read) [new]
5. `POST /topics/{topicId}/posts` — create reply post, ACL-gated (f_reply) [from PostsController]

### Service Layer
- `ThreadsServiceInterface` — single interface covering all topic and post methods
- `ThreadsService` — final class, injects `TopicRepositoryInterface`, `PostRepositoryInterface`, `Connection` (for transactions)
- Cross-table transaction ownership: service calls `beginTransaction()` / `commit()` / `rollBack()` wrapping both repos

### Repository Layer
- `DbalTopicRepository` — covers phpbb_topics: findById, findByForum (paginated), insert, updateFirstLastPost
- `DbalPostRepository` — covers phpbb_posts: findById, findByTopic (paginated), insert

### Entity & DTO Layer
- `Topic` entity — final readonly, maps phpbb_topics columns
- `Post` entity — final readonly, maps phpbb_posts columns
- `TopicDTO` — final readonly, API response shape for topics
- `PostDTO` — final readonly, API response shape for posts

### Domain Events
- `TopicCreatedEvent` — fired after topic creation
- `PostCreatedEvent` — fired after post/reply creation

### Tests
- `ThreadsServiceTest` — unit test with mocked repositories
- `DbalTopicRepositoryTest` — integration test against real DBAL
- `DbalPostRepositoryTest` — integration test against real DBAL
- `TopicsControllerTest` — refactor to mock ThreadsServiceInterface (currently mocks Connection)

## Similar Features Identified
- `phpbb\hierarchy\` module — primary template for all patterns
- `phpbb\user\Service\UserSearchService` — pagination pattern reference

## Scope Boundaries

### In scope
- New `src/phpbb/threads/` module
- Refactor TopicsController, PostsController
- DI registration in services.yaml
- Full test coverage

### Out of scope
- Thread locking, pinning, subscriptions, read tracking
- PATCH /topics/{topicId} (update topic title) — deferred
- DELETE /topics/{topicId} — deferred
- Moderation operations — deferred
- Frontend/UI changes

## Technical Considerations
- Preserve all existing route URLs and response shapes (except: POST create now returns full topic data)
- ACL checks remain in controller layer (not service)
- Anonymous user fallback: `$userRepository->findById(1)` when `_api_user` is null
- `post_subject = 'Re: ' . topic_title` for reply posts (preserve existing logic)
- Pagination uses `PaginationContext` and `PaginatedResult<T>` from existing infrastructure
