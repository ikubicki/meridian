# Cross-Service Extension Point Analysis

## 1. Per-Service Extension Point Inventory

### 1.1 Threads Service (`phpbb\threads`)

| Category | Details |
|----------|---------|
| **Decorated Operations** | `createTopic()`, `createReply()`, `editPost()`, `getTopic()`, `getTopicWithPosts()`, `getForumTopics()` — all pass through `DecoratorPipeline` (request + response decorators) |
| **Events Dispatched** | `TopicCreatedEvent`, `TopicEditedEvent`, `TopicLockedEvent`, `TopicMovedEvent`, `TopicDeletedEvent`, `TopicTypeChangedEvent`, `PostCreatedEvent`, `PostEditedEvent`, `PostSoftDeletedEvent`, `PostRestoredEvent`, `PostHardDeletedEvent`, `VisibilityChangedEvent`, `ForumCountersChangedEvent`, `DraftSavedEvent`, `DraftDeletedEvent`, `ContentPreParseEvent`, `ContentPostParseEvent`, `ContentPreRenderEvent`, `ContentPostRenderEvent` |
| **Events Consumed** | None (auth-unaware; cross-service calls are synchronous to hierarchy) |
| **Extension Model** | **Lean core + plugin extensions** — Polls, ReadTracking, Subscriptions, Attachments are plugins extending via events + request/response decorators. `ContentPipeline` is a middleware chain (`ContentPluginInterface`). |
| **JSON Fields** | None — raw `post_text` only, no JSON metadata columns |
| **Special** | `ContentPluginInterface` — ordered middleware chain for parse+render pipeline |

### 1.2 Hierarchy Service (`phpbb\hierarchy`)

| Category | Details |
|----------|---------|
| **Decorated Operations** | `createForum()`, `updateForum()`, `deleteForum()`, `moveForum()`, `reorderForum()`, `getForum()`, `getTree()` — all pass through `DecoratorPipeline` |
| **Events Dispatched** | `ForumCreatedEvent`, `ForumUpdatedEvent`, `ForumDeletedEvent`, `ForumMovedEvent`, `ForumReorderedEvent`, `ForumMarkedReadEvent`, `AllForumsMarkedReadEvent`, `ForumSubscribedEvent`, `ForumUnsubscribedEvent`, `RegisterForumTypesEvent` |
| **Events Consumed** | None explicitly; cache invalidation listeners react to hierarchy's own events |
| **Extension Model** | Events + decorators + **ForumTypeRegistry** — plugins register custom forum types via `RegisterForumTypesEvent` (boot-time). `ForumTypeBehaviorInterface` delegates behavior per type. |
| **JSON Fields** | None |
| **Special** | `ForumTypeRegistry` — runtime type system allowing plugins to add entirely new forum behaviors |

### 1.3 Auth Service (`phpbb\auth`)

| Category | Details |
|----------|---------|
| **Decorated Operations** | None — no decorator pipeline documented |
| **Events Dispatched** | `PermissionsClearedEvent` (after cache invalidation), `PermissionDeniedEvent` (when route-level check fails) |
| **Events Consumed** | `UserGroupChangedEvent` (from User service) → clears permission cache for affected user |
| **Extension Model** | Minimal — primarily a consumer of other services' events. No explicit plugin extension points. Route YAML `_api_permission` defaults are the configuration interface. |
| **JSON Fields** | None — uses base-36 bitfield format in `user_permissions` column |
| **Special** | Read-only service from extension perspective; extensibility via new permission options in `acl_options` table |

### 1.4 Users Service (`phpbb\user`)

| Category | Details |
|----------|---------|
| **Decorated Operations** | `RegistrationService::register()` (request: CAPTCHA, terms, referral; response: welcome data), `ProfileService::getProfile()` (response: badges, reputation), `UserSearchService::search()` (request: custom filters; response: extra display data) |
| **Events Dispatched** | `UserCreatedEvent`, `UserActivatedEvent`, `UserDeactivatedEvent`, `UserDeletedEvent`, `UsernameChangedEvent`, `ProfileUpdatedEvent`, `PreferencesUpdatedEvent`, `PasswordChangedEvent`, `PasswordResetRequestedEvent`, `UserBannedEvent`, `UserUnbannedEvent`, `UserShadowBannedEvent`, `UserShadowBanRemovedEvent`, `UserGroupChangedEvent`, `DefaultGroupChangedEvent`, `UserTypeChangedEvent` |
| **Events Consumed** | `PostCreatedEvent`, `PostSoftDeletedEvent`, `PostRestoredEvent`, `VisibilityChangedEvent`, `TopicDeletedEvent` (all from Threads → `UserCounterService`) |
| **Extension Model** | DecoratorPipeline on register/profile/search + domain events for lifecycle operations. Same pattern as Threads/Hierarchy. |
| **JSON Fields** | `profile_fields JSON` (extensible profile data — plugins add keys without DDL), `preferences JSON` (user preferences replacing bitfield) |
| **Special** | `UserDisplayDTO` batch API for cross-service consumers; shadow ban decoration lives in Threads but calls User |

### 1.5 Search Service (`phpbb\search`)

| Category | Details |
|----------|---------|
| **Decorated Operations** | None (uses events instead: `PreSearchEvent` / `PostSearchEvent` serve similar purpose) |
| **Events Dispatched** | `PreSearchEvent` (mutable query/options), `PostSearchEvent` (mutable result), `PreIndexEvent` (mutable document), `PostIndexEvent`, `SearchPerformedEvent` (analytics), `IndexRebuiltEvent` |
| **Events Consumed** | `PostCreatedEvent`, `PostEditedEvent`, `PostSoftDeletedEvent`, `PostRestoredEvent`, `PostHardDeletedEvent`, `TopicDeletedEvent`, `VisibilityChangedEvent`, `TopicMovedEvent` (all from Threads) |
| **Extension Model** | Event-driven extension via Pre/Post search/index events. Backend registration via tagged DI services (`phpbb.search.backend` tag). ISP interfaces allow new backends to implement only relevant capabilities. |
| **JSON Fields** | None |
| **Special** | `BackendInfoInterface` + `BackendFeatures` DTO for capability negotiation; pluggable `SearchAnalyzer` pipeline (CharFilter → Tokenizer → TokenFilter) |

### 1.6 Notifications Service (`phpbb\notifications`)

| Category | Details |
|----------|---------|
| **Decorated Operations** | None — uses registry pattern instead of decorators |
| **Events Dispatched** | `NotificationCreatedEvent`, `NotificationsMarkedReadEvent`, `NotificationsDeletedEvent` |
| **Events Consumed** | Consumes events from Threads/Messaging indirectly — forum code calls `createNotification()` in response to domain events |
| **Extension Model** | **Registry-based**: `NotificationTypeRegistry` (tagged `notification.type`), `NotificationMethodManager` (tagged `notification.method`). New types/methods via DI tags only — no code changes. |
| **JSON Fields** | `notification_data` column stores type-specific JSON blob per notification |
| **Special** | Dual extension axis: types (what) × methods (how). Both use tagged DI services. `AbstractNotificationType` base class for convenience. |

### 1.7 Cache Service (`phpbb\cache`)

| Category | Details |
|----------|---------|
| **Decorated Operations** | None |
| **Events Dispatched** | None (utility service, not domain service) |
| **Events Consumed** | None directly — each consuming service writes its own `CacheInvalidationSubscriber` |
| **Extension Model** | **Backend adapters** (`CacheBackendInterface` — Filesystem, Redis, Memcached, APCu, Database, Null) + **Marshallers** (`MarshallerInterface` — VarExport, Igbinary, PhpSerialize). Pool isolation via `CachePoolFactoryInterface`. |
| **JSON Fields** | N/A |
| **Special** | Infrastructure service — not directly extensible by plugins; extensibility via implementation swap (backend/marshaller) |

### 1.8 Storage Service (`phpbb\storage`)

| Category | Details |
|----------|---------|
| **Decorated Operations** | None (facade with direct event dispatch) |
| **Events Dispatched** | `FileStoredEvent`, `FileClaimedEvent`, `FileDeletedEvent`, `VariantGeneratedEvent`, `QuotaExceededEvent`, `QuotaReconciledEvent`, `OrphanCleanupEvent` |
| **Events Consumed** | None directly — consumer plugins (AttachmentPlugin, AvatarPlugin) react to domain events from other services and call storage methods |
| **Extension Model** | **Event-driven consumer model** — plugins listen to other services' events and call `StorageService` methods. `VariantGeneratorInterface` for custom file variants. `StorageAdapterFactory` for filesystem backends (Flysystem). `AssetType` enum for discriminated storage. |
| **JSON Fields** | None in owned tables — consumer plugins own their metadata tables externally |
| **Special** | Consumer plugins own their metadata (`phpbb_attachment_metadata`, `phpbb_avatar_metadata`) separately from the generic `stored_files` table |

---

## 2. Common Decorator Patterns

### Pattern: Request/Response DecoratorPipeline

Shared by **Threads**, **Hierarchy**, and **Users** — the three primary domain services with rich CRUD operations.

```php
interface RequestDecoratorInterface
{
    public function supports(object $request): bool;
    public function decorateRequest(object $request): object;
    public function getPriority(): int;
}

interface ResponseDecoratorInterface
{
    public function supports(object $response): bool;
    public function decorateResponse(object $response, object $request): object;
    public function getPriority(): int;
}
```

**Mechanics**:
- DTOs carry `private array $extra = []` with `withExtra(key, value)` / `getExtra(key)` / `getAllExtra()` (immutable clone pattern)
- Pipeline collects tagged decorators, sorts by priority, runs request chain before logic, response chain after
- Decorators self-select via `supports()` — enables single decorator class to handle multiple DTO types

**Services using this pattern**:
| Service | Decorated DTOs (Request) | Decorated DTOs (Response) |
|---------|--------------------------|---------------------------|
| Threads | `CreateTopicRequest`, `CreateReplyRequest`, `EditPostRequest`, `GetTopicPostsRequest` | `TopicViewResponse`, `ForumTopicsResponse`, `PostResponse` |
| Hierarchy | `CreateForumRequest`, `UpdateForumRequest`, `MoveForumRequest`, `DeleteForumRequest`, `ReorderForumRequest` | `ForumResponse`, `DeleteForumResponse`, `MoveForumResponse`, `ReorderForumResponse`, `TreeResponse` |
| Users | `CreateUserDTO`, `UpdateProfileDTO`, `UserSearchCriteria` | User/Profile/Search responses |

### Why Search/Notifications/Storage Don't Use Decorators

- **Search**: Uses `PreSearchEvent`/`PostSearchEvent` with mutable payloads — functionally equivalent but event-based
- **Notifications**: Uses registry pattern — types define behavior, methods define delivery; no "generic DTO decoration" needed
- **Storage**: Infrastructure layer — consumers call it, not the reverse
- **Auth**: Read-only query service — no user-facing CRUD to decorate
- **Cache**: Utility — backend/marshaller swap covers extensibility

---

## 3. Common Event Patterns

### 3.1 Event Naming Convention

All services follow **`{Entity}{Action}Event`** naming:

| Pattern | Examples |
|---------|----------|
| `{Entity}CreatedEvent` | `TopicCreatedEvent`, `ForumCreatedEvent`, `UserCreatedEvent`, `NotificationCreatedEvent`, `FileStoredEvent` |
| `{Entity}UpdatedEvent` / `EditedEvent` | `ForumUpdatedEvent`, `PostEditedEvent`, `ProfileUpdatedEvent` |
| `{Entity}DeletedEvent` | `TopicDeletedEvent`, `ForumDeletedEvent`, `UserDeletedEvent`, `FileDeletedEvent` |
| `{Entity}MovedEvent` | `TopicMovedEvent`, `ForumMovedEvent` |
| `{Entity}{State}Event` | `PostSoftDeletedEvent`, `PostRestoredEvent`, `TopicLockedEvent`, `UserBannedEvent`, `UserActivatedEvent` |
| `Pre{Action}Event` / `Post{Action}Event` | `PreSearchEvent`, `PostSearchEvent`, `PreIndexEvent`, `PostIndexEvent`, `ContentPreParseEvent`, `ContentPostRenderEvent` |
| `{Action}ChangedEvent` | `VisibilityChangedEvent`, `TopicTypeChangedEvent`, `DefaultGroupChangedEvent`, `UserGroupChangedEvent` |

### 3.2 Event Payload Patterns

| Pattern | Services | Description |
|---------|----------|-------------|
| **Entity ID + context** | All | Events carry IDs, not full entities, for lightweight dispatch |
| **Old + New state** | Hierarchy (`ForumUpdatedEvent`), Search (`VisibilityChangedEvent`) | Enables differential behavior |
| **Affected ID arrays** | Hierarchy (`deletedIds[]`), Threads (`allPosterIds[]`) | For batch operations |
| **Mutable payload** | Search (`PreSearchEvent`, `PostSearchEvent`) | Allows modification before/after core logic |
| **Readonly payload** | Threads, Hierarchy, Users (most events) | Side-effect triggers |
| **Response DTO attached** | Hierarchy (events carry decorated `ForumResponse`) | Caller can access decorated response |

### 3.3 Event Consumption Topology

```
Threads ──events──► Search (index), Users (counters), Notifications (via controller), Cache (invalidation)
Hierarchy ──events──► Cache (invalidation), Users (lastmark update via AllForumsMarkedReadEvent)
Users ──events──► Auth (permission cache clear), Threads (denormalized name updates), Notifications (cascade)
Storage ──events──► ThumbnailListener (variant gen), Consumer plugins (claim tracking)
Notifications ──events──► Cache (invalidation only)
Auth ──events──► (self cache only — PermissionsClearedEvent)
Cache ──events──► (none — utility consumer)
Search ──events──► (analytics/audit only — SearchPerformedEvent)
```

---

## 4. Cross-Service Extension Needs

A plugin that extends phpBB will commonly need to hook into multiple services simultaneously:

### Example: "Polls" Plugin

| Service | Extension Type | What It Does |
|---------|---------------|--------------|
| Threads | Request decorator on `CreateTopicRequest` | Inject poll data into request |
| Threads | Response decorator on `TopicViewResponse` | Attach poll display data to response |
| Threads | Event listener on `TopicCreatedEvent` | Create poll rows in plugin-owned table |
| Threads | Event listener on `TopicDeletedEvent` | Delete poll data |
| Notifications | New type via `notification.type` tag | Notify users when poll they voted in closes |

### Example: "Badges/Reputation" Plugin

| Service | Extension Type | What It Does |
|---------|---------------|--------------|
| Users | Response decorator on `getProfile()` | Add badge list to profile response |
| Users | Event listener on `UserCreatedEvent` | Initialize badge tracking |
| Threads | Event listener on `PostCreatedEvent` | Award "helpful" badges |
| Notifications | New type via `notification.type` tag | Notify user when badge earned |

### Example: "Wiki Forum" Plugin  

| Service | Extension Type | What It Does |
|---------|---------------|--------------|
| Hierarchy | Forum type via `RegisterForumTypesEvent` | Register `WikiForumBehavior` |
| Hierarchy | Request/Response decorators | Add wiki-specific fields to create/view |
| Hierarchy | Event listener on `ForumCreatedEvent`/`ForumDeletedEvent` | Init/cleanup wiki structures |
| Threads | Content pipeline plugin | Wiki markup rendering |
| Search | Event listener on `PreIndexEvent` | Add wiki metadata to indexed document |

### Example: "Advanced Attachments" Plugin

| Service | Extension Type | What It Does |
|---------|---------------|--------------|
| Storage | Event listener on `FileStoredEvent` | Generate additional variants (webp, medium) |
| Threads | Request decorator on `CreateReplyRequest` | Validate attachment limits |
| Threads | Event listener on `PostCreatedEvent` | Claim orphan files via `StorageService::claim()` |
| Threads | Response decorator on `PostResponse` | Attach file metadata to post display |

---

## 5. Identified Gaps

### 5.1 No Unified Decorator Interface Package

Each service (Threads, Hierarchy, Users) defines its **own** `RequestDecoratorInterface` and `ResponseDecoratorInterface` in its own namespace. The interfaces are identical in shape but technically different types. This means:
- A plugin can't implement a single decorator that works across multiple services without per-service implementations
- No shared base package like `phpbb\plugin\DecoratorInterface`

**Recommendation**: Extract a shared `phpbb\plugin\decorator\` package with the common interfaces.

### 5.2 Search Uses Events Where Others Use Decorators

Search's `PreSearchEvent`/`PostSearchEvent` (mutable payload) serves the same purpose as request/response decorators but uses a different mechanism. This creates inconsistency:
- Threads/Hierarchy/Users: Decorators for pre/post processing
- Search: Events for pre/post processing

**Impact**: Plugin developers must learn two patterns. A "search enhancement" plugin extends differently from a "threads enhancement" plugin.

### 5.3 Auth Service Has No Extension Points

The Auth service dispatches only 2 events (`PermissionsClearedEvent`, `PermissionDeniedEvent`) and has no decorator pipeline. A plugin that needs to:
- Add custom permission resolution logic (e.g., time-based permissions)
- Add new permission types beyond the 4 existing prefixes (a_, f_, m_, u_)
- Hook into the authorization decision process

...has no clean way to do so. The only seam is the `acl_options` table (add new options).

**Recommendation**: Consider a `PreAuthorizationCheckEvent` or strategy interface for custom resolution layers.

### 5.4 Storage Has No Decorator Pipeline

Storage service is purely event-driven. If a plugin needs to:
- Transform file content during upload (watermarking, virus scan)
- Enforce custom validation rules beyond extension/MIME/size
- Modify the storage path or behavior

...it must do so only via `FileStoredEvent` (post-store). There's no pre-store hook for validation enrichment.

**Recommendation**: Add `PreStoreEvent` (mutable, cancellable) to allow validation/transformation plugins.

### 5.5 Cache Service — No Extension Discovery Pattern

Plugins that add new cacheable entities have no standard pattern for:
- Registering their own cache tags
- Getting their own isolated cache pool
- Declaring invalidation rules

Each service manually creates its own `CacheInvalidationSubscriber`. There's no standard "register my cache needs" mechanism for plugins.

### 5.6 Notifications — No Decorator Layer for Delivery

While notification types and methods are fully extensible via registries, there's no way to:
- Modify notifications before delivery (e.g., add custom data, suppress conditionally)
- Intercept the delivery pipeline (e.g., rate-limit per-user, batch differently)

Missing a `PreNotifyEvent` or delivery decorator chain.

### 5.7 No Cross-Service Plugin Manifest

There is no single place where a plugin declares "I extend Threads via decorator X, Notifications via type Y, and listen to events Z". Each extension point is registered independently via DI YAML tags. This makes plugin dependency tracking and lifecycle management harder.

### 5.8 JSON Fields — Only in Users

Only the Users service uses JSON columns for extensible data (`profile_fields`, `preferences`). Other services that might benefit:
- **Threads**: `post_metadata JSON` for plugin-specific per-post data (polls votes, reactions, etc.) — currently explicitly excluded by ADR
- **Storage**: `stored_files` has no extensible metadata column; plugins must own separate tables
- **Hierarchy**: No `forum_metadata JSON` for plugin-specific per-forum settings

The Threads HLD explicitly chose NO JSON metadata (ADR-001: "raw text only"). This forces plugins to maintain separate tables for any per-post data.

---

## 6. Summary Table

| Service | Decorators | Events Dispatched | Events Consumed | Registry | JSON Fields | Plugin Model |
|---------|:----------:|:-----------------:|:---------------:|:--------:|:-----------:|:------------:|
| **Threads** | ✅ Full | 19 events | 0 | ContentPlugin chain | None | Decorators + Events + ContentPipeline |
| **Hierarchy** | ✅ Full | 10 events | 0 | ForumTypeRegistry | None | Decorators + Events + TypeRegistry |
| **Users** | ✅ Partial (3 ops) | 16 events | 5 (from Threads) | None | 2 (`profile_fields`, `preferences`) | Decorators + Events |
| **Search** | ❌ (uses events) | 6 events | 8 (from Threads) | Backend tags | None | Events + Tagged backends |
| **Notifications** | ❌ | 3 events | Indirect (called by controllers) | Type + Method registries | `notification_data` | Registries + Events |
| **Storage** | ❌ | 7 events | 0 (consumers call it) | AssetType enum | None | Events + Adapter factory |
| **Auth** | ❌ | 2 events | 1 (from Users) | None | None | Minimal (table config) |
| **Cache** | ❌ | 0 events | 0 | Backend + Marshaller swap | N/A | Infrastructure swap |

---

## 7. Services Explicitly Mentioning "Plugin" or "Extension"

| Service | Explicit Mentions |
|---------|-------------------|
| **Threads** | "plugin-extensible OOP layer", "Cross-cutting features are plugins", "plugins extend via events + decorators", `ContentPluginInterface` |
| **Hierarchy** | "plugins extend via domain events + request/response DTO decorators", `RegisterForumTypesEvent`, "plugin types", "Plugin Event Listeners" |
| **Users** | "DecoratorPipeline", "plugins add keys without DDL" (JSON profile fields) |
| **Search** | "Extensions can modify query before execution", "Extensions can modify/augment results" |
| **Notifications** | "Extensibility-first", "Extension Points Summary" table, "new notification types added via tagged DI services" |
| **Storage** | "plugin-consumable infrastructure layer", "Consumer Plugin Listeners" (AttachmentPlugin, AvatarPlugin) |
| **Auth** | No explicit plugin/extension mentions |
| **Cache** | No explicit plugin/extension mentions |
