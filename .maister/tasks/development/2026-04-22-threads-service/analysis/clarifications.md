# Phase 1 Clarifications

## Q1: Primary goal of the "threads service"?
**Answer**: Extract to service layer — refactor TopicsController: extract business logic into a new ThreadsService + DbalTopicRepository following the hierarchy module pattern. Same routes, clean architecture.

## Q2: Module namespace?
**Answer**: `phpbb\threads\` — uses "threads" terminology matching the original request.

## Q3: Should the service also handle posts (replies)?
**Answer**: Topics + Posts together — combine topic and post handling in one module; service covers both phpbb_topics and phpbb_posts.

## Q4: Domain events for mutations?
**Answer**: Yes, follow hierarchy pattern — emit TopicCreatedEvent / PostCreatedEvent etc. via DomainEventCollection.

---

## Summary
The task is to:
1. Create a new `src/phpbb/threads/` module with `phpbb\threads\` namespace
2. Implement ThreadsService + PostsService (or combined) with proper Service/Repository/Entity/DTO/Contract layers
3. Cover both phpbb_topics AND phpbb_posts tables
4. Publish domain events (TopicCreatedEvent, PostCreatedEvent) following DomainEventCollection pattern
5. Refactor TopicsController and PostsController to delegate to the new service layer
6. Register all new services in services.yaml
