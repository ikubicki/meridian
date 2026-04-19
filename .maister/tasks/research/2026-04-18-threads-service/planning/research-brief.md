# Research Brief: phpbb\threads Service

## Research Question

Jak zaprojektować serwis `phpbb\threads` odpowiedzialny za tematy i posty, z content pipeline pluginowym (BBCode/markdown/attachmenty jako pluginy), event-driven API, auth via middleware `phpbb\auth`?

## Research Type

**Technical** — analiza legacy codebase + ekstrakcja do nowoczesnego serwisu PHP 8.2

## Context

Projekt phpBB modernizacji — wyodrębnione serwisy:
- `phpbb\user` — uwierzytelnianie, zarządzanie użytkownikami (zaprojektowany)
- `phpbb\auth` — autoryzacja, ACL, middleware REST API (zaprojektowany)
- `phpbb\hierarchy` — kategorie i fora, nested set, tracking (zaprojektowany)
- **`phpbb\threads`** — tematy i posty (DO ZAPROJEKTOWANIA)

Kluczowa decyzja architektoniczna: **attachmenty, BBCode, markdown i inne formaty treści to pluginy**, nie rdzeń serwisu. Serwis threads przechowuje surowy tekst + metadane — transformacja i wzbogacanie odbywa się przez content pipeline oparty na events + request/response decorators.

## Scope

### Included
- Legacy posting workflow: `posting.php` (entry point), `functions_posting.php` (submit_post ~500 LOC, delete_post, topic_review)
- Topic/post display: `viewtopic.php` (entry point), `functions_display.php` (topic helpers)
- Post submission state machine: modes (post, reply, quote, edit, delete, bump, smilies)
- Topic types: NORMAL(0), STICKY(1), ANNOUNCE(2), GLOBAL(3)
- Topic states: lock, pin, move, split, merge
- Soft-delete: visibility states (APPROVED, UNAPPROVED, REAPPROVE, DELETED, SOFTDELETED)
- Polls: `phpbb_poll_options`, `phpbb_poll_votes`, poll CRUD
- Drafts: `phpbb_drafts`, load/save/delete
- Post text storage: `post_text`, `bbcode_uid`, `bbcode_bitfield`, `enable_bbcode`, `enable_smilies`, `enable_magic_url`
- Content pipeline architecture: how plugins hook into text processing
- Attachment integration as plugin (not core) — interface contract only
- BBCode/markdown as content format plugins — interface contract only
- DB tables: `phpbb_topics`, `phpbb_posts`, `phpbb_topics_posted`, `phpbb_drafts`, `phpbb_poll_options`, `phpbb_poll_votes`
- Counters: `forum_posts`, `forum_topics`, `user_posts`, topic post counts

### Excluded
- ACL/permissions → `phpbb\auth` middleware
- Forum hierarchy → `phpbb\hierarchy` (provides forum_id context)
- User identity → `phpbb\user` (provides user_id context)
- Search indexing → future `phpbb\search` service
- Moderation queue → future moderation service
- Notification dispatch → separate concern, listens to thread events
- Legacy extension system → dropped

## Key Design Constraints (from prior decisions)

1. **Event-driven API**: Service methods return domain events (e.g., `createPost()` → `PostCreatedEvent`)
2. **Plugin via events + decorators**: Plugins extend through event listeners + request/response DTO decorators
3. **Content is plugin-processed**: Core stores raw text. BBCode plugin decorates response with rendered HTML. Attachment plugin decorates request with file refs and response with download URLs. Markdown plugin is alternative formatter.
4. **Auth is external**: The service trusts that the caller (middleware) already verified permissions. Service receives `userId` and `forumId` as context, not User/Forum objects.

## Success Criteria

1. Full mapping of legacy posting/topic code (classes, functions, DB schema)
2. Understanding of submit_post() workflow and all modes
3. Complete entity model for Topic and Post
4. Content pipeline architecture — how plugins transform text
5. Service interfaces with PHP 8.2 signatures
6. Request/Response DTOs as decoration targets
7. Domain event model
8. DB query patterns for all operations
9. Integration contracts with hierarchy, auth, user services
