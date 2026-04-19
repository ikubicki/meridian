# Research Report: phpbb\threads Service Design

**Research type**: Technical Architecture Research  
**Date**: 2026-04-18  
**Scope**: Design analysis for `phpbb\threads` — topic, post, poll, draft, and content pipeline services  

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Research Objectives](#2-research-objectives)
3. [Methodology](#3-methodology)
4. [Legacy System Analysis](#4-legacy-system-analysis)
5. [Domain Model](#5-domain-model)
6. [Content Pipeline](#6-content-pipeline)
7. [State Machines](#7-state-machines)
8. [Database Analysis](#8-database-analysis)
9. [Counter Management Strategy](#9-counter-management-strategy)
10. [Service Decomposition](#10-service-decomposition)
11. [Integration Architecture](#11-integration-architecture)
12. [Plugin Hook Design](#12-plugin-hook-design)
13. [Key Findings and Recommendations](#13-key-findings-and-recommendations)
14. [Open Design Questions](#14-open-design-questions)
15. [Appendices](#15-appendices)

---

## 1. Executive Summary

### What was researched
A comprehensive analysis of the legacy phpBB threads subsystem — encompassing topic/post creation, editing, deletion, content parsing/rendering, polls, drafts, visibility management, read tracking, and display — to inform the design of a modern `phpbb\threads` service.

### How it was researched
Seven detailed findings documents were analyzed, covering: posting workflow (~3200 LOC), database schema (9 tables), content format pipeline, polls/drafts implementation, topic display flow, soft-delete/visibility system, and attachment integration patterns. Three integration reference documents were reviewed for architectural consistency: hierarchy service design, auth service design, and user service specification.

### Key findings
- The legacy system is ~8,000 LOC across 5 main files, managing 9 tables with 20+ denormalized counters
- A 4-state visibility machine (Unapproved/Approved/Deleted/Reapprove) is the core state model
- The content pipeline (s9e TextFormatter) is deeply embedded and must be fully decoupled for plugin architecture
- Counter management is the primary correctness concern: manual increment/decrement across 3 table levels
- The `submit_post()` monolith (~1000 lines) handles 6 modes with complex branching

### Main conclusions
- Decompose into 10 focused services under a `ThreadsService` facade
- Content pipeline must be 100% plugin-based (BBCode, markdown, smilies, attachments = plugins)
- Counter management needs a dedicated `CounterService` with transactional guarantees
- Visibility is a first-class state machine with its own service
- Follow the established patterns from `phpbb\hierarchy`: event-driven returns, DTO decorators, auth-unaware

---

## 2. Research Objectives

### Primary research question
How should `phpbb\threads` be architected to encapsulate all topic/post functionality with a plugin-based content pipeline, event-driven API, and clean integration with hierarchy/auth/user services?

### Sub-questions
1. What entities, value objects, and relationships form the threads domain model?
2. How should the content pipeline work when BBCode, markdown, attachments are ALL plugins?
3. What state machines govern topic and post lifecycles including visibility?
4. How should denormalized counters be managed reliably?
5. What services and repositories are needed?
6. How does `phpbb\threads` integrate with hierarchy, auth, and user services?
7. What plugin hooks (events + decorators) are needed for extensibility?

### Scope
- **Included**: Topics, posts, polls, drafts, content pipeline, visibility, read tracking, subscriptions, counter management, integration points
- **Excluded**: Private messages, search backend implementation, notification delivery, moderator control panel UI, admin control panel

---

## 3. Methodology

### Research type and approach
Technical codebase analysis with cross-referencing against 3 sibling service designs for architectural consistency.

### Data sources
| Source | Lines analyzed | Coverage |
|--------|---------------|----------|
| posting-workflow.md | 609 | submit_post(), delete_post(), posting.php flow, events |
| topic-post-schema.md | 559 | 9 tables DDL, all columns, indexes, constants |
| content-format.md | 656 | s9e pipeline, parse/render/unparse, BBCode, smilies, magic URLs |
| polls-drafts.md | 520 | Poll CRUD, voting, draft lifecycle |
| topic-display.md | 740 | viewtopic.php, viewforum.php, pagination, read tracking |
| soft-delete-visibility.md | 392 | content_visibility class, 4-state machine, counter cascades |
| attachment-patterns.md | 509 | Attachment lifecycle, orphan pattern, plugin hook catalog |
| hierarchy high-level-design.md | 2299 | Integration reference — events, DTOs, decorator pattern |
| auth high-level-design.md | 1454 | Integration reference — permission model, AuthorizationService |
| user IMPLEMENTATION_SPEC.md | 1967 | Integration reference — User entity, PDO patterns |
| **Total** | **~9,705** | |

### Analysis framework
Technical Research Framework with Component Analysis, Pattern Analysis, and Flow Analysis applied across all sources.

---

## 4. Legacy System Analysis

### 4.1 Current Architecture

The threads subsystem is procedural code with no OOP service layer. Five main files contain all logic:

| File | LOC | Responsibility |
|------|-----|----------------|
| `web/posting.php` | 2,123 | Entry point: mode routing, form handling, validation, submit orchestration |
| `src/phpbb/common/functions_posting.php` | 3,009 | `submit_post()`, `delete_post()`, `update_post_information()`, `phpbb_bump_topic()`, `load_drafts()` |
| `web/viewtopic.php` | 2,425 | Topic display: post pagination, read tracking, quick reply, poll display |
| `src/phpbb/forums/content_visibility.php` | ~900 | Visibility state machine, counter management, SQL generation |
| `src/phpbb/common/message_parser.php` | ~1,800 | Content parsing: BBCode, attachments, polls, text validation |

**Additional supporting files**:
- `src/phpbb/forums/textformatter/s9e/` — s9e TextFormatter wrapper (parser, renderer, factory, utils)
- `src/phpbb/common/functions_content.php` — `generate_text_for_storage()`, `generate_text_for_display()`, `decode_message()`
- `src/phpbb/common/bbcode.php` — Legacy BBCode second-pass renderer
- `web/viewforum.php` — Forum topic listing (1,101 lines)

### 4.2 Code Quality Assessment

| Metric | Assessment |
|--------|-----------|
| **Cohesion** | LOW — `submit_post()` handles 6 modes in one function |
| **Coupling** | HIGH — posting.php directly queries 4+ tables, interleaves auth checks |
| **Testability** | NONE — all functions depend on global `$db`, `$user`, `$auth`, `$config` |
| **Error handling** | STRING-based — errors collected as string arrays, no typed exceptions |
| **Transaction safety** | PARTIAL — core SQL transactional, side effects post-commit |
| **Extension points** | 30+ events — but many are "modify SQL data" patterns, not clean domain events |

### 4.3 Legacy Event Catalog

The legacy system fires 30+ events. These map to the new architecture as follows:

| Legacy Event Pattern | New Pattern |
|---------------------|-------------|
| `core.modify_*_data` (before SQL) | Request DTO decorators |
| `core.modify_*_sql_data` (before SQL execution) | Request DTO decorators |
| `core.*_after` / `core.*_end` | Domain events |
| `core.text_formatter_*` (parse/render) | ContentPipeline plugin hooks |
| `core.modify_*_template_vars` | Response DTO decorators (for API) or view-layer concern |

---

## 5. Domain Model

### 5.1 Entity Design

#### Topic Entity (Aggregate Root)

```php
namespace phpbb\threads\entity;

final class Topic
{
    public function __construct(
        // Identity
        public readonly int $id,
        public readonly int $forumId,
        
        // Content
        public readonly string $title,
        public readonly int $iconId,
        
        // Type & Status
        public readonly TopicType $type,
        public readonly TopicStatus $status,
        public readonly Visibility $visibility,
        
        // Author
        public readonly int $posterId,
        public readonly int $createdAt,        // Unix timestamp
        
        // Denormalized first post info
        public readonly FirstPostInfo $firstPost,
        
        // Denormalized last post info
        public readonly LastPostInfo $lastPost,
        
        // Stats
        public readonly TopicStats $stats,
        
        // Soft-delete info
        public readonly ?DeleteInfo $deleteInfo,
        
        // Poll (embedded, nullable)
        public readonly ?PollConfig $poll,
        
        // Announce/Sticky
        public readonly int $timeLimit,        // 0 = no expiry
        
        // Display
        public readonly int $views,
        public readonly int $lastViewTime,
        
        // Move tracking
        public readonly int $movedToId,        // 0 = not moved
        
        // Bump
        public readonly bool $bumped,
        public readonly int $bumperId,
        
        // Flags
        public readonly bool $hasAttachments,
        public readonly bool $hasReports,
    ) {}
}
```

#### Post Entity

```php
namespace phpbb\threads\entity;

final class Post
{
    public function __construct(
        // Identity
        public readonly int $id,
        public readonly int $topicId,
        public readonly int $forumId,          // Denormalized from topic
        
        // Author
        public readonly int $posterId,
        public readonly string $posterIp,
        public readonly string $posterUsername, // For guests
        public readonly int $postedAt,         // Unix timestamp
        
        // Content
        public readonly PostContent $content,
        public readonly int $iconId,
        public readonly string $subject,
        
        // State
        public readonly Visibility $visibility,
        public readonly bool $countsTowardPostCount,
        
        // Edit tracking
        public readonly EditInfo $editInfo,
        
        // Soft-delete
        public readonly ?DeleteInfo $deleteInfo,
        
        // Flags
        public readonly bool $hasAttachments,
        public readonly bool $hasReports,
    ) {}
}
```

### 5.2 Value Objects

```php
// Enums
enum TopicType: int {
    case Normal = 0;
    case Sticky = 1;
    case Announce = 2;
    case Global = 3;
}

enum TopicStatus: int {
    case Unlocked = 0;
    case Locked = 1;
    case Moved = 2;
}

enum Visibility: int {
    case Unapproved = 0;
    case Approved = 1;
    case Deleted = 2;
    case Reapprove = 3;
    
    public function counterField(): string {
        return match($this) {
            self::Approved => 'approved',
            self::Unapproved, self::Reapprove => 'unapproved',
            self::Deleted => 'softdeleted',
        };
    }
}

// Value Objects
final readonly class PostContent {
    public function __construct(
        public string $rawText,              // What user typed
        public string $pluginMetadata,       // JSON: plugin parse results
        public int $flags,                   // Bitmask: which plugins were active
    ) {}
}

final readonly class EditInfo {
    public function __construct(
        public int $editTime,
        public int $editUserId,
        public int $editCount,
        public string $editReason,
        public bool $editLocked,
    ) {}
}

final readonly class DeleteInfo {
    public function __construct(
        public int $deleteTime,
        public int $deleteUserId,
        public string $deleteReason,
    ) {}
}

final readonly class FirstPostInfo {
    public function __construct(
        public int $postId,
        public string $posterName,
        public string $posterColour,
    ) {}
}

final readonly class LastPostInfo {
    public function __construct(
        public int $postId,
        public int $posterId,
        public string $posterName,
        public string $posterColour,
        public string $subject,
        public int $time,
    ) {}
}

final readonly class TopicStats {
    public function __construct(
        public int $postsApproved,
        public int $postsUnapproved,
        public int $postsSoftdeleted,
    ) {}
    
    public function totalPosts(): int {
        return $this->postsApproved + $this->postsUnapproved + $this->postsSoftdeleted;
    }
    
    public function displayReplies(): int {
        return max(0, $this->postsApproved - 1);
    }
}

final readonly class PollConfig {
    public function __construct(
        public string $title,
        public int $startTime,
        public int $lengthSeconds,       // 0 = no expiry
        public int $maxOptions,
        public int $lastVoteTime,
        public bool $allowVoteChange,
    ) {}
    
    public function isExpired(): bool {
        return $this->lengthSeconds > 0 
            && ($this->startTime + $this->lengthSeconds) < time();
    }
}

final readonly class PollOption {
    public function __construct(
        public int $optionId,            // Sequential within topic
        public int $topicId,
        public string $text,
        public int $totalVotes,          // Denormalized counter
    ) {}
}

final readonly class PollVote {
    public function __construct(
        public int $topicId,
        public int $optionId,
        public int $userId,
        public string $voterIp,
    ) {}
}

final readonly class Draft {
    public function __construct(
        public int $id,
        public int $userId,
        public int $topicId,             // 0 = new topic
        public int $forumId,
        public int $savedAt,
        public string $subject,
        public string $message,          // Raw text (NOT parsed)
    ) {}
}
```

### 5.3 Entity Relationships

```
Forum (from phpbb\hierarchy)
  │
  ├── 1:N ── Topic
  │            ├── topic_poster → User (from phpbb\user)
  │            ├── 1:N ── Post
  │            │            ├── poster_id → User
  │            │            └── 1:N ── Attachment (plugin-managed)
  │            ├── 0..1 ── PollConfig (embedded)
  │            │            └── 1:N ── PollOption
  │            │                        └── 1:N ── PollVote
  │            ├── N:M ── TopicPosted (user posted-in tracking)
  │            ├── N:M ── TopicTrack (user read tracking)
  │            ├── N:M ── TopicWatch (user subscriptions)
  │            └── N:M ── Bookmark
  │
  └── 1:N ── Draft (lightweight, independent)
```

---

## 6. Content Pipeline

### 6.1 Current Pipeline (Legacy)

The legacy pipeline is tightly coupled to s9e TextFormatter:

```
User Input (BBCode text)
    ↓
message_parser::parse()
    ↓ configures s9e parser (BBCodes, smilies, Autolink, Emoji)
    ↓ calls $parser->parse($text)
    ↓
s9e XML stored in post_text
    (e.g., <r><B><s>[b]</s>Bold<e>[/b]</e></B></r>)
    ↓
generate_text_for_display()
    ↓ detects s9e format via regex
    ↓ calls $renderer->render($xml)
    ↓
HTML output to template
```

The pipeline also handles:
- BBCode parsing (12+ built-in + custom BBCodes)
- Smiley/emoticon replacement
- Emoji support (Twemoji SVGs)
- Auto-linking of URLs and emails
- Word censoring
- Attachment inline markers (`[attachment=N]`)
- Quote nesting depth limits
- Per-user rendering preferences (viewimg, viewsmilies, viewflash, censoring)

### 6.2 Proposed Plugin-Based Pipeline

**Core stores**:
- `raw_text`: Exactly what the user typed — preserved for editing
- `plugin_metadata`: JSON object containing per-plugin parse results
- `content_flags`: Bitmask of active plugins at parse time

**Core owns only**:
- Text length validation
- Empty message detection
- Storage (raw_text + metadata)
- Pipeline orchestration (calling plugins in order)

**Everything else is a plugin**:

| Plugin | Parse | Render | Priority |
|--------|-------|--------|----------|
| BBCode | Convert `[tag]` → metadata entries | Metadata → HTML tags | 100 |
| Markdown | Convert markdown → metadata entries | Metadata → HTML | 100 |
| Smilies | Detect smiley codes → metadata | Metadata → `<img>` tags | 200 |
| Emoji | Detect unicode emoji → metadata | Metadata → Twemoji SVGs | 200 |
| AutoLink | Detect URLs/emails → metadata | Metadata → `<a>` tags | 300 |
| Attachment | Detect `[attachment=N]` → metadata | Replace markers → rendered attachment HTML | 400 |
| Censor | Not at parse time | Replace words at render time | 900 |

### 6.3 Pipeline Interfaces

```php
namespace phpbb\threads\content;

interface ContentPipelineInterface
{
    public function parse(string $rawText, ParseContext $context): ParseResult;
    public function render(ParseResult $content, RenderContext $context): string;
    public function unparse(ParseResult $content): string;
}

interface ContentPluginInterface
{
    public function getName(): string;
    public function getPriority(): int;
    
    /** Transform raw text, produce metadata */
    public function parse(string $text, ParseContext $ctx): PluginParseResult;
    
    /** Transform text using stored metadata, produce HTML */
    public function render(string $text, array $metadata, RenderContext $ctx): string;
    
    /** Reverse parse for editing */
    public function unparse(string $text, array $metadata): string;
}

final readonly class ParseContext
{
    public function __construct(
        public int $forumId,
        public int $userId,
        public string $mode,       // 'post', 'sig', 'pm'
        public array $config,      // max_chars, max_urls, etc.
    ) {}
}

final readonly class RenderContext
{
    public function __construct(
        public int $userId,
        public bool $viewSmilies,
        public bool $viewImages,
        public bool $viewCensored,
        public ?string $highlightWords,
    ) {}
}

final readonly class ParseResult
{
    public function __construct(
        public string $rawText,
        /** @var array<string, mixed> Plugin name → plugin-specific data */
        public array $pluginMetadata,
        public int $flags,
    ) {}
}
```

### 6.4 Migration Path

During transition, the pipeline adapter can bridge to s9e:

```php
class S9eCompatibilityPlugin implements ContentPluginInterface
{
    // Parse: delegate to s9e parser, store XML in metadata['s9e_xml']
    // Render: delegate to s9e renderer
    // Unparse: delegate to s9e unparser
}
```

This allows gradual migration: new content uses the plugin pipeline, old content uses the s9e compatibility plugin. The `raw_text` column is added to `phpbb_posts`; legacy content has raw_text retroactively populated by a reparser (similar to existing text reparser).

---

## 7. State Machines

### 7.1 Post Visibility State Machine

```
                    NEW POST
                       │
               ┌───────┴───────┐
               │ f_noapprove?  │
               └───┬───────┬───┘
                   │YES    │NO
                   ▼       ▼
             ┌──────────┐  ┌──────────────┐
             │ APPROVED │  │ UNAPPROVED   │
             │  (1)     │  │  (0)         │
             └────┬─────┘  └──────┬───────┘
                  │               │
    ┌─────────────┼───────────────┼──────────────┐
    │             │               │              │
    │  edit(no    │   approve     │  disapprove  │
    │  f_noapprove)              │  (HARD DELETE)│
    │             │               │              │
    │    ┌────────┴──┐   ┌───────┴──┐           │
    │    │ REAPPROVE │   │ approve  │           │
    │    │  (3)      │───┘          │           │
    │    └───────────┘              │           │
    │                               │           │
    │   soft_delete                  │           │
    │        │                      │           │
    │        ▼                      │           │
    │   ┌──────────┐               │           │
    │   │ DELETED  │◄──────────────┘           │
    │   │  (2)     │  (also soft_delete)       │
    │   └────┬─────┘                           │
    │        │                                 │
    │    restore                               │
    │        │                                 │
    │        ▼                                 │
    │   ┌──────────┐                           │
    │   │ APPROVED │                           │
    │   └──────────┘                           │
    │                                          │
    │   hard_delete (ANY STATE)                │
    │        │                                 │
    │        ▼                                 │
    │   ┌──────────────┐                       │
    │   │ ROW REMOVED  │◄──────────────────────┘
    │   └──────────────┘
    │
    └──────────────────────────────────────────
```

### 7.2 Topic Visibility State Machine

Topic visibility mirrors post visibility but with cascade behavior:

**Soft-delete topic** (APPROVED → DELETED):
- Sets `topic_visibility = DELETED`
- Cascades to all currently-APPROVED posts → DELETED
- Preserves individually-UNAPPROVED or individually-DELETED posts

**Restore topic** (DELETED → APPROVED):
- Sets `topic_visibility = APPROVED`
- Only restores posts with matching `post_delete_time == topic_delete_time`
- Posts that were individually soft-deleted BEFORE the topic remain DELETED

**Approve topic** (UNAPPROVED → APPROVED):
- Sets `topic_visibility = APPROVED`
- Cascades approval to all posts in the topic

### 7.3 Topic Status State Machine

```
UNLOCKED (0) ←──lock/unlock──→ LOCKED (1)

UNLOCKED/LOCKED ──move──→ Original becomes MOVED (2) with movedToId
                          New copy created at destination
```

### 7.4 Post Lifecycle Events by Transition

| From | To | Trigger | Counter Changes | Events |
|------|----|---------|----------------|--------|
| — | APPROVED | New post (f_noapprove) | topic_approved+1, forum_approved+1, num_posts+1, user_posts+1 | PostCreatedEvent |
| — | UNAPPROVED | New post (no f_noapprove) | topic_unapproved+1, forum_unapproved+1 | PostCreatedEvent |
| UNAPPROVED | APPROVED | Moderator approve | topic_unapproved-1, topic_approved+1, forum same, num_posts+1, user_posts+1 | PostVisibilityChangedEvent |
| UNAPPROVED | — | Moderator disapprove | topic_unapproved-1, forum_unapproved-1 (row deleted) | PostHardDeletedEvent |
| APPROVED | REAPPROVE | Edit without f_noapprove | topic_approved-1, topic_unapproved+1, forum same, num_posts-1, user_posts-1 | PostVisibilityChangedEvent |
| APPROVED | DELETED | Soft delete | topic_approved-1, topic_softdeleted+1, forum same, num_posts-1, user_posts-1 | PostVisibilityChangedEvent |
| DELETED | APPROVED | Restore | topic_softdeleted-1, topic_approved+1, forum same, num_posts+1, user_posts+1 | PostVisibilityChangedEvent |
| REAPPROVE | APPROVED | Re-approve | topic_unapproved-1, topic_approved+1, forum same, num_posts+1, user_posts+1 | PostVisibilityChangedEvent |
| Any | — | Hard delete | Decrement appropriate counter | PostHardDeletedEvent |

---

## 8. Database Analysis

### 8.1 Table Summary

| Table | PK | Rows (typical) | Key Purpose |
|-------|-----|-----------------|-------------|
| `phpbb_topics` | `topic_id` AUTO_INCREMENT | Thousands | Thread metadata, poll config, denormalized stats |
| `phpbb_posts` | `post_id` AUTO_INCREMENT | Tens of thousands | Post content, edit history, visibility |
| `phpbb_poll_options` | None (topic_id, option_id) | Low | Poll choices with vote counts |
| `phpbb_poll_votes` | None | Low-medium | Individual vote records |
| `phpbb_topics_posted` | (user_id, topic_id) | Medium-high | "Has user posted in topic?" tracking |
| `phpbb_topics_track` | (user_id, topic_id) | High | Per-topic read timestamps |
| `phpbb_topics_watch` | None | Medium | Topic subscription for notifications |
| `phpbb_bookmarks` | (topic_id, user_id) | Low-medium | User bookmarks on topics |
| `phpbb_drafts` | `draft_id` AUTO_INCREMENT | Low | Unsaved post compositions |

### 8.2 Index Analysis

**Critical Performance Indexes**:

| Table | Index | Columns | Usage |
|-------|-------|---------|-------|
| topics | `fid_time_moved` | forum_id, topic_last_post_time, topic_moved_id | Forum listing sorted by activity |
| topics | `forum_vis_last` | forum_id, topic_visibility, topic_last_post_id | Forum listing filtered by visibility |
| topics | `latest_topics` | forum_id, topic_last_post_time, topic_last_post_id, topic_moved_id | Optimized covering index for forum listing |
| posts | `tid_post_time` | topic_id, post_time | **Critical**: paginated post listing within topic |
| posts | `poster_id` | poster_id | Posts by user |
| posts | `post_visibility` | post_visibility | Visibility filtering |
| topics_track | PK | user_id, topic_id | Read status lookup |

**Optimization opportunities**:
- The `latest_topics` covering index is well-designed for the primary query pattern
- `tid_post_time` is the most important index for viewtopic — ensures efficient page navigation
- Missing: No composite index for `(topic_id, post_visibility, post_time)` which would optimize the common "approved posts in topic sorted by time" query

### 8.3 Denormalized Columns Inventory

**On phpbb_topics** (12 denormalized columns):

| Column | Source | Updated by |
|--------|--------|-----------|
| `topic_first_post_id` | MIN(post_id) WHERE approved | submit_post, delete_post |
| `topic_first_poster_name` | users.username via first post | submit_post, delete_post |
| `topic_first_poster_colour` | users.user_colour via first post | submit_post, delete_post |
| `topic_last_post_id` | MAX(post_id) WHERE approved | submit_post, delete_post, bump |
| `topic_last_poster_id` | poster_id of last approved post | submit_post, delete_post, bump |
| `topic_last_poster_name` | username of last approved post | submit_post, delete_post, bump |
| `topic_last_poster_colour` | user_colour of last approved post | submit_post, delete_post, bump |
| `topic_last_post_subject` | subject of last approved post | submit_post, delete_post, bump |
| `topic_last_post_time` | post_time of last approved post | submit_post, delete_post, bump |
| `topic_posts_approved` | COUNT WHERE visibility=1 | submit_post, visibility changes |
| `topic_posts_unapproved` | COUNT WHERE visibility IN (0,3) | submit_post, visibility changes |
| `topic_posts_softdeleted` | COUNT WHERE visibility=2 | visibility changes |

**On phpbb_posts** (1 denormalized column):

| Column | Source | Updated by |
|--------|--------|-----------|
| `forum_id` | topics.forum_id | Topic move |

**On phpbb_forums** via hierarchy (6 counter columns):

| Column | Source |
|--------|--------|
| `forum_posts_approved` | SUM of topic_posts_approved |
| `forum_posts_unapproved` | SUM of topic_posts_unapproved |
| `forum_posts_softdeleted` | SUM of topic_posts_softdeleted |
| `forum_topics_approved` | COUNT topics WHERE visibility=1 |
| `forum_topics_unapproved` | COUNT topics WHERE visibility IN (0,3) |
| `forum_topics_softdeleted` | COUNT topics WHERE visibility=2 |

Plus 6 `forum_last_post_*` columns matching the topic pattern.

### 8.4 Schema Changes for New Design

**New columns on phpbb_posts**:
```sql
ALTER TABLE phpbb_posts
    ADD COLUMN `raw_text` mediumtext NOT NULL AFTER `post_text`,
    ADD COLUMN `plugin_metadata` json DEFAULT NULL AFTER `raw_text`,
    ADD COLUMN `content_flags` int unsigned NOT NULL DEFAULT 0 AFTER `plugin_metadata`;
```

**Migration**: Backfill `raw_text` by unparsing `post_text` (s9e XML → BBCode text). Set `plugin_metadata` to `{"s9e_compat": true}` for legacy posts.

---

## 9. Counter Management Strategy

### 9.1 The Problem

Counter management is the #1 correctness concern. The legacy system maintains 20+ denormalized counters across 3 table levels (topic, forum, global). Every write operation must correctly increment/decrement the appropriate counters. A single miss results in a display bug visible to all users.

### 9.2 Counter Categories

**Post count counters** (per-visibility):
```
Topic level:  topic_posts_approved, topic_posts_unapproved, topic_posts_softdeleted
Forum level:  forum_posts_approved, forum_posts_unapproved, forum_posts_softdeleted
Global level: config.num_posts (approved only)
User level:   users.user_posts (approved only, when post_postcount=1)
```

**Topic count counters** (per-visibility):
```
Forum level:  forum_topics_approved, forum_topics_unapproved, forum_topics_softdeleted
Global level: config.num_topics (approved only)
```

**Metadata columns** (recalculated):
```
Topic: first/last post info (7 columns each)
Forum: last post info (6 columns)
Topic: topic_attachment flag
```

### 9.3 Proposed CounterService

```php
namespace phpbb\threads\service;

final class CounterService
{
    /**
     * Increment counters for a new post.
     * Updates topic_posts_*, forum_posts_*, num_posts, user_posts.
     */
    public function incrementPostCounters(
        int $topicId, 
        int $forumId, 
        Visibility $visibility,
        int $posterId,
        bool $countsTowardPostCount = true,
    ): void;
    
    /**
     * Decrement counters for a removed post.
     */
    public function decrementPostCounters(
        int $topicId,
        int $forumId,
        Visibility $visibility,
        int $posterId,
        bool $countsTowardPostCount = true,
    ): void;
    
    /**
     * Transfer counters when visibility changes.
     * Decrements old visibility counter, increments new visibility counter.
     */
    public function transferPostCounters(
        int $topicId,
        int $forumId,
        Visibility $fromVisibility,
        Visibility $toVisibility,
        int $posterId,
        bool $countsTowardPostCount = true,
    ): void;
    
    /**
     * Increment topic counters for a new topic.
     */
    public function incrementTopicCounters(
        int $forumId,
        Visibility $visibility,
    ): void;
    
    /**
     * Full resync: recalculate all counters from source data.
     * Used for repair/maintenance operations.
     */
    public function syncTopicCounters(int $topicId): void;
    public function syncForumCounters(int $forumId): void;
}
```

### 9.4 Counter Update Matrix

| Operation | topic_posts | forum_posts | forum_topics | num_posts | num_topics | user_posts |
|-----------|-------------|-------------|--------------|-----------|------------|------------|
| New topic (approved) | approved+1 | approved+1 | approved+1 | +1 | +1 | +1 |
| New topic (unapproved) | unapproved+1 | unapproved+1 | unapproved+1 | — | — | — |
| New reply (approved) | approved+1 | approved+1 | — | +1 | — | +1 |
| New reply (unapproved) | unapproved+1 | unapproved+1 | — | — | — | — |
| Approve post | unapproved-1, approved+1 | same | — | +1 | — | +1 |
| Soft-delete post | approved-1, softdeleted+1 | same | — | -1 | — | -1 |
| Restore post | softdeleted-1, approved+1 | same | — | +1 | — | +1 |
| Hard-delete post | -(current vis) | same | — | if approved: -1 | — | if approved: -1 |
| Soft-delete topic | (cascade to posts) | cascade + topics: approved-1, softdeleted+1 | — | -(approved posts) | -1 | -(per author) |
| Approve topic | (cascade) | cascade + topics: unapproved-1, approved+1 | — | +(post count) | +1 | +(per author) |

---

## 10. Service Decomposition

### 10.1 Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────────┐
│                        phpbb\threads                                    │
│                                                                         │
│  ┌───────────────────────────────────────────────────────────────────┐  │
│  │                     ThreadsService (Facade)                       │  │
│  │                                                                   │  │
│  │  Request DTO ──► RequestDecoratorChain ──► Service Logic          │  │
│  │       ──► Domain Event ──► EventDispatcher ──► ResponseDecorator  │  │
│  │       ──► Response DTO returned to caller                         │  │
│  └──┬───────┬──────────┬────────────┬──────────┬────────────────────┘  │
│     │       │          │            │          │                        │
│  ┌──▼──┐ ┌──▼───┐ ┌───▼────┐ ┌────▼────┐ ┌───▼────┐                  │
│  │Topic│ │Post  │ │Visibi- │ │Counter  │ │Topic   │                  │
│  │Repo │ │Repo  │ │lity    │ │Service  │ │Metadata│                  │
│  │     │ │      │ │Service │ │         │ │Service │                  │
│  │CRUD │ │CRUD  │ │state   │ │incr/dec │ │first/  │                  │
│  │query│ │paged │ │machine │ │transfer │ │last    │                  │
│  └─────┘ └──────┘ └────────┘ └─────────┘ └────────┘                  │
│                                                                         │
│  ┌──────┐ ┌──────┐ ┌────────────┐ ┌──────────────┐ ┌──────────┐      │
│  │Poll  │ │Draft │ │Read        │ │Subscription  │ │Content   │      │
│  │Svc   │ │Svc   │ │Tracking    │ │Service       │ │Pipeline  │      │
│  │      │ │      │ │Service     │ │              │ │(plugins) │      │
│  │vote  │ │save  │ │mark/check  │ │watch/unwatch │ │parse/    │      │
│  │CRUD  │ │load  │ │unread      │ │subscribers   │ │render    │      │
│  └──────┘ └──────┘ └────────────┘ └──────────────┘ └──────────┘      │
│                                                                         │
│  ┌────────────────────────────────────────────────────────────────┐     │
│  │ Request/Response Decorator Pipeline                            │     │
│  │                                                                │     │
│  │  CreateTopicRequest ──► [AttachmentDeco] ──► [BBCodeDeco] ──► │     │
│  │  TopicCreatedEvent  ◄── [AttachmentDeco] ◄── final response   │     │
│  └────────────────────────────────────────────────────────────────┘     │
└─────────────────────────────────────────────────────────────────────────┘
```

### 10.2 Directory Structure

```
src/phpbb/threads/
├── ThreadsService.php
├── ThreadsServiceInterface.php
│
├── dto/
│   ├── request/
│   │   ├── CreateTopicRequest.php
│   │   ├── CreateReplyRequest.php
│   │   ├── EditPostRequest.php
│   │   ├── DeletePostRequest.php
│   │   ├── SoftDeletePostRequest.php
│   │   ├── RestorePostRequest.php
│   │   ├── BumpTopicRequest.php
│   │   ├── LockTopicRequest.php
│   │   ├── MoveTopicRequest.php
│   │   ├── ChangeTopicTypeRequest.php
│   │   ├── CastVoteRequest.php
│   │   ├── SaveDraftRequest.php
│   │   └── GetTopicPostsRequest.php
│   └── response/
│       ├── TopicResponse.php
│       ├── PostResponse.php
│       ├── PostListResponse.php
│       ├── TopicListResponse.php
│       ├── PollResultsResponse.php
│       └── DraftResponse.php
│
├── entity/
│   ├── Topic.php
│   ├── Post.php
│   ├── PollOption.php
│   ├── PollVote.php
│   ├── Draft.php
│   ├── TopicType.php
│   ├── TopicStatus.php
│   ├── Visibility.php
│   ├── PostContent.php
│   ├── EditInfo.php
│   ├── DeleteInfo.php
│   ├── FirstPostInfo.php
│   ├── LastPostInfo.php
│   ├── TopicStats.php
│   └── PollConfig.php
│
├── repository/
│   ├── TopicRepositoryInterface.php
│   ├── TopicRepository.php
│   ├── PostRepositoryInterface.php
│   ├── PostRepository.php
│   ├── PollRepositoryInterface.php
│   ├── PollRepository.php
│   ├── DraftRepositoryInterface.php
│   ├── DraftRepository.php
│   ├── ReadTrackingRepositoryInterface.php
│   ├── ReadTrackingRepository.php
│   ├── SubscriptionRepositoryInterface.php
│   └── SubscriptionRepository.php
│
├── service/
│   ├── VisibilityService.php
│   ├── VisibilityServiceInterface.php
│   ├── CounterService.php
│   ├── CounterServiceInterface.php
│   ├── TopicMetadataService.php
│   ├── TopicMetadataServiceInterface.php
│   ├── PollService.php
│   ├── PollServiceInterface.php
│   ├── DraftService.php
│   ├── DraftServiceInterface.php
│   ├── ReadTrackingService.php
│   ├── ReadTrackingServiceInterface.php
│   ├── TopicSubscriptionService.php
│   └── TopicSubscriptionServiceInterface.php
│
├── content/
│   ├── ContentPipelineInterface.php
│   ├── ContentPipeline.php
│   ├── ContentPluginInterface.php
│   ├── ParseContext.php
│   ├── RenderContext.php
│   └── ParseResult.php
│
├── event/
│   ├── TopicCreatedEvent.php
│   ├── PostCreatedEvent.php
│   ├── PostEditedEvent.php
│   ├── PostVisibilityChangedEvent.php
│   ├── TopicVisibilityChangedEvent.php
│   ├── PostHardDeletedEvent.php
│   ├── TopicHardDeletedEvent.php
│   ├── TopicBumpedEvent.php
│   ├── TopicLockedEvent.php
│   ├── TopicMovedEvent.php
│   ├── TopicTypeChangedEvent.php
│   ├── PollCreatedEvent.php
│   ├── PollVoteCastEvent.php
│   ├── PollDeletedEvent.php
│   ├── DraftSavedEvent.php
│   └── DraftDeletedEvent.php
│
├── decorator/
│   ├── RequestDecoratorInterface.php
│   ├── ResponseDecoratorInterface.php
│   └── DecoratorPipeline.php
│
└── exception/
    ├── TopicNotFoundException.php
    ├── PostNotFoundException.php
    ├── DraftNotFoundException.php
    ├── InvalidVisibilityTransitionException.php
    ├── TopicLockedException.php
    └── PollExpiredException.php
```

### 10.3 ThreadsServiceInterface

```php
namespace phpbb\threads;

use phpbb\threads\dto\request\*;
use phpbb\threads\event\*;

interface ThreadsServiceInterface
{
    // ── Topic Operations (return domain events) ──
    
    public function createTopic(CreateTopicRequest $request): TopicCreatedEvent;
    public function createReply(CreateReplyRequest $request): PostCreatedEvent;
    public function editPost(EditPostRequest $request): PostEditedEvent;
    
    public function softDeletePost(SoftDeletePostRequest $request): PostVisibilityChangedEvent;
    public function restorePost(RestorePostRequest $request): PostVisibilityChangedEvent;
    public function hardDeletePost(DeletePostRequest $request): PostHardDeletedEvent;
    
    public function softDeleteTopic(SoftDeletePostRequest $request): TopicVisibilityChangedEvent;
    public function restoreTopic(RestorePostRequest $request): TopicVisibilityChangedEvent;
    public function hardDeleteTopic(DeletePostRequest $request): TopicHardDeletedEvent;
    
    public function approvePost(int $postId, int $moderatorId): PostVisibilityChangedEvent;
    public function approveTopic(int $topicId, int $moderatorId): TopicVisibilityChangedEvent;
    
    public function bumpTopic(BumpTopicRequest $request): TopicBumpedEvent;
    public function lockTopic(LockTopicRequest $request): TopicLockedEvent;
    public function moveTopic(MoveTopicRequest $request): TopicMovedEvent;
    public function changeTopicType(ChangeTopicTypeRequest $request): TopicTypeChangedEvent;
    
    // ── Query Operations (return DTOs, no events) ──
    
    public function getTopic(int $topicId): ?TopicResponse;
    public function getPost(int $postId): ?PostResponse;
    public function getTopicPosts(GetTopicPostsRequest $request): PostListResponse;
    public function getForumTopics(int $forumId, int $page, int $perPage): TopicListResponse;
    
    // ── Poll Operations ──
    
    public function castVote(CastVoteRequest $request): PollVoteCastEvent;
    public function getPollResults(int $topicId): PollResultsResponse;
    
    // ── Draft Operations ──
    
    public function saveDraft(SaveDraftRequest $request): DraftSavedEvent;
    public function loadDraft(int $draftId, int $userId): ?DraftResponse;
    public function deleteDraft(int $draftId, int $userId): DraftDeletedEvent;
    public function getUserDrafts(int $userId, ?int $forumId = null): array;
    
    // ── Tracking/Subscriptions ──
    
    public function markTopicRead(int $userId, int $topicId, int $markTime): void;
    public function isTopicUnread(int $userId, int $topicId): bool;
    
    public function subscribeTopic(int $userId, int $topicId): void;
    public function unsubscribeTopic(int $userId, int $topicId): void;
    public function isSubscribed(int $userId, int $topicId): bool;
    public function getTopicSubscribers(int $topicId, ?int $excludeUserId = null): array;
}
```

### 10.4 Service Responsibilities Matrix

| Service | Creates | Reads | Updates | Deletes | Events Emitted |
|---------|---------|-------|---------|---------|---------------|
| ThreadsService (facade) | Orchestrates | Delegates | Orchestrates | Orchestrates | All (via sub-services) |
| TopicRepository | Topic rows | By ID, by forum, paginated | Topic columns | Topic rows | None (data layer) |
| PostRepository | Post rows | By ID, by topic, paginated | Post columns | Post rows | None (data layer) |
| VisibilityService | — | Visibility SQL | Visibility + counters | — | PostVisibilityChanged, TopicVisibilityChanged |
| CounterService | — | — | Counter columns | — | None (internal) |
| TopicMetadataService | — | First/last post data | first/last post columns | — | None (internal) |
| PollService | Options, votes | Options, results, user votes | Options, vote counts | Options, votes | PollCreated, PollVoteCast, PollDeleted |
| DraftService | Draft rows | By ID, by user | — | Draft rows | DraftSaved, DraftDeleted |
| ReadTrackingService | Track rows | Unread status | Mark timestamps | Old track rows | None |
| TopicSubscriptionService | Watch rows | Subscribers, status | Notify status | Watch rows | None |
| ContentPipeline | — | — | — | — | ContentParse*, ContentRender* |

---

## 11. Integration Architecture

### 11.1 Integration with phpbb\hierarchy

**Purpose**: Forum context for topics, forum counter/last-post updates.

**Integration pattern**: Direct method calls within the same transaction.

```php
// Inside ThreadsService::createTopic()
$this->db->beginTransaction();

// ... create topic and first post ...

// Update forum counters via hierarchy
$this->hierarchyService->updateForumStats($forumId, new ForumStatsDelta(
    topicsApproved: $visibility === Visibility::Approved ? 1 : 0,
    topicsUnapproved: $visibility !== Visibility::Approved ? 1 : 0,
    postsApproved: $visibility === Visibility::Approved ? 1 : 0,
    postsUnapproved: $visibility !== Visibility::Approved ? 1 : 0,
));

// Update forum last post info
if ($visibility === Visibility::Approved) {
    $this->hierarchyService->updateForumLastPost($forumId, new ForumLastPostInfo(
        postId: $postId,
        posterId: $request->posterId,
        subject: $request->subject,
        time: $currentTime,
        posterName: $posterName,
        posterColour: $posterColour,
    ));
}

$this->db->commit();
```

**Methods needed on HierarchyServiceInterface** (additions):
- `updateForumStats(int $forumId, ForumStatsDelta $delta): void`
- `updateForumLastPost(int $forumId, ForumLastPostInfo $info): void`
- `recalculateForumLastPost(int $forumId): void` — for deletion scenarios

### 11.2 Integration with phpbb\auth

**Pattern**: Auth middleware enforces permissions BEFORE calling ThreadsService. The service is completely auth-unaware.

```php
// In API controller / middleware:
if (!$this->authService->isGranted($user, 'f_post', $forumId)) {
    throw new AccessDeniedException();
}

// Then call threads service without any auth checks
$event = $this->threadsService->createTopic($request);
```

**Permission mapping** (legacy → auth service):

| Operation | Required Permission | Check Location |
|-----------|-------------------|----------------|
| Create topic | `f_post` | API middleware |
| Reply | `f_reply` | API middleware |
| Edit own post | `f_edit` + time limit | API middleware |
| Edit any post | `m_edit` | API middleware |
| Soft-delete own | `f_softdelete` + conditions | API middleware |
| Soft-delete any | `m_softdelete` | API middleware |
| Hard-delete | `m_delete` | API middleware |
| Approve | `m_approve` | API middleware |
| Lock topic | `m_lock` or `f_user_lock` (own) | API middleware |
| Create poll | `f_poll` | API middleware |
| Vote | `f_vote` | API middleware |
| Change vote | `f_votechg` + poll allows | API middleware |
| Bypass approval | `f_noapprove` | ThreadsService (determines initial visibility) |

Note: `f_noapprove` is the ONE permission the threads service checks internally, because it determines the initial visibility state of new content. All other permissions are enforced externally.

### 11.3 Integration with phpbb\user

**Pattern**: Event-driven for counter updates, direct entity reference for author context.

```php
// ThreadsService receives User entity from caller
public function createTopic(CreateTopicRequest $request): TopicCreatedEvent
{
    // $request->posterId is the user_id from User entity
    
    // ... create topic and post ...
    
    // Emit event for user service to update user_posts, user_lastpost_time
    $this->eventDispatcher->dispatch(new PostCreatedEvent(
        postId: $postId,
        topicId: $topicId,
        forumId: $forumId,
        posterId: $request->posterId,
        visibility: $visibility,
        countsTowardPostCount: true,
    ));
}

// In phpbb\user event listener:
class PostCountListener
{
    public function onPostCreated(PostCreatedEvent $event): void
    {
        if ($event->visibility === Visibility::Approved && $event->countsTowardPostCount) {
            $this->userRepository->incrementPostCount($event->posterId);
            $this->userRepository->updateLastPostTime($event->posterId, time());
        }
    }
}
```

### 11.4 Integration Summary Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                         API Layer                                    │
│                                                                     │
│  JWT → phpbb\user (AuthN) → phpbb\auth (AuthZ) → Controller        │
│                                                                     │
│  Controller receives User entity + validated permissions            │
│  Controller calls phpbb\threads methods                             │
└──────────────────────────┬──────────────────────────────────────────┘
                           │
                           ▼
┌──────────────────────────────────────────────────────────────────────┐
│                     phpbb\threads                                     │
│                                                                      │
│  ┌─ Sync (in-transaction) ─┐    ┌─ Async (post-commit events) ──┐  │
│  │                          │    │                                 │  │
│  │  phpbb\hierarchy         │    │  phpbb\user (post count)       │  │
│  │  → updateForumStats()    │    │  Search plugin (index)         │  │
│  │  → updateForumLastPost() │    │  Notification plugin (email)   │  │
│  │                          │    │  Attachment plugin (adopt)      │  │
│  │  Own DB tables           │    │  Cache invalidation            │  │
│  │  → topics, posts, etc.   │    │  Read tracking                 │  │
│  └──────────────────────────┘    └────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────┘
```

---

## 12. Plugin Hook Design

### 12.1 Domain Event Catalog

Events are the primary extensibility mechanism. Each event is dispatched AFTER the database transaction commits.

| Event Class | Payload | Typical Listeners |
|-------------|---------|-------------------|
| `TopicCreatedEvent` | topic entity, first post entity, forum_id, visibility | Search (index), Notification (topic watchers), Attachment (adopt orphans) |
| `PostCreatedEvent` | post entity, topic_id, forum_id, visibility, is_reply, quoted_post_ids | Search (index), Notification (topic/bookmark/quote), Attachment (adopt), Draft (delete loaded) |
| `PostEditedEvent` | post (old + new), changed_fields, editor_id | Search (reindex), Notification (update quote notifs), Attachment (sync) |
| `PostVisibilityChangedEvent` | post_id(s), topic_id, forum_id, old_vis, new_vis, actor_id | User (post count), Search (visibility update), Notification (approval/queue) |
| `TopicVisibilityChangedEvent` | topic_id, forum_id, old_vis, new_vis, affected_post_ids, actor_id | User (post counts), Search, Notification |
| `PostHardDeletedEvent` | post_id, topic_id, forum_id, was_first_post, was_last_post, poster_id | Attachment (cascade delete), Search (deindex), Report cleanup |
| `TopicHardDeletedEvent` | topic_id, forum_id, all_post_ids, all_poster_ids | Attachment (cascade), Search (deindex), Bookmark cleanup, Watch cleanup |
| `TopicBumpedEvent` | topic_id, bumper_id, bump_time | (Logging only) |
| `TopicLockedEvent` | topic_id, new_status (locked/unlocked), actor_id | (Logging only) |
| `TopicMovedEvent` | topic_id, old_forum_id, new_forum_id, create_shadow | Attachment (update topic_id? No, post-level), Search (reindex forum) |
| `TopicTypeChangedEvent` | topic_id, old_type, new_type | (Display/cache invalidation) |
| `PollCreatedEvent` | topic_id, options, config | (Logging) |
| `PollVoteCastEvent` | topic_id, user_id, voted_option_ids, removed_option_ids | (Logging, real-time push) |
| `PollDeletedEvent` | topic_id | (Cleanup) |
| `DraftSavedEvent` | draft_id, user_id, forum_id, topic_id | (None currently) |
| `DraftDeletedEvent` | draft_id, user_id | (None currently) |

### 12.2 Content Pipeline Events

These fire DURING parse/render operations (synchronous, in-process):

| Event | Phase | Purpose | Example Plugin Use |
|-------|-------|---------|-------------------|
| `ContentValidateEvent` | Before parse | Validate raw text (length, content policy) | Spam filter, word blacklist |
| `ContentParseEvent` | During parse | Transform text, produce metadata | BBCode, Markdown, Smilies, AutoLink |
| `ContentPostParseEvent` | After parse | Modify parsed result | Quote depth limiter, empty check |
| `ContentPreRenderEvent` | Before render | Inject metadata for rendering | Quote author links, attachment data |
| `ContentRenderEvent` | During render | Transform content to HTML | BBCode renderer, Smiley renderer |
| `ContentPostRenderEvent` | After render | Post-process HTML | Censoring, search highlight, lazyload images |

### 12.3 Request/Response Decorator Pattern

Following the hierarchy service pattern, request DTOs have `withExtra()`/`getExtra()` for plugin data injection:

```php
// Attachment plugin decorates the CreateReplyRequest
class AttachmentRequestDecorator implements RequestDecoratorInterface
{
    public function decorate(object $request): object
    {
        if (!$request instanceof CreateReplyRequest) {
            return $request;
        }
        
        // Read attachment refs from request context
        $attachmentRefs = $this->parseAttachmentRefs($request);
        
        return $request->withExtra('attachment_refs', $attachmentRefs);
    }
}

// After post save, attachment plugin processes the event
class AttachmentEventListener
{
    public function onPostCreated(PostCreatedEvent $event): void
    {
        $attachmentRefs = $event->getExtra('attachment_refs');
        if ($attachmentRefs) {
            $this->adoptOrphans($event->postId, $event->topicId, $attachmentRefs);
        }
    }
}
```

### 12.4 Attachment Plugin Hook Mapping

Based on the attachment pattern analysis, the attachment plugin needs these hooks:

| Legacy Integration Point | New Hook | Type |
|--------------------------|----------|------|
| Upload file before post save | Pre-request (standalone upload endpoint) | Separate API |
| Validate attachment ownership | `CreateReplyRequest` decorator | Request decorator |
| Adopt orphans on post save | `PostCreatedEvent` listener | Domain event |
| Update `post_attachment` flag | `PostCreatedEvent` / `PostEditedEvent` listener | Domain event |
| Update `topic_attachment` flag | Same events | Domain event |
| Cascade delete on post delete | `PostHardDeletedEvent` listener | Domain event |
| Cascade delete on topic delete | `TopicHardDeletedEvent` listener | Domain event |
| Inline attachment rendering | `ContentRenderEvent` handler (ContentPlugin) | Content pipeline |
| Batch fetch for display | `PostListResponse` decorator | Response decorator |
| Resync stale flags | Admin/maintenance endpoint | Standalone API |

---

## 13. Key Findings and Recommendations

### Finding 1: Content Pipeline is the Biggest Architectural Change
**Confidence**: HIGH  
**Impact**: HIGH  

The current s9e TextFormatter is deeply embedded — the storage format IS the intermediate XML output. Moving to a plugin-based pipeline requires storing raw text separately and rethinking the parse/render lifecycle.

**Recommendation**: Store `raw_text` (original input) + `plugin_metadata` (JSON from plugins) + `content_flags`. Render lazily with caching. Use compatibility plugin for legacy s9e content.

### Finding 2: Counter Management is the Hardest Correctness Problem
**Confidence**: HIGH  
**Impact**: HIGH  

20+ denormalized counters across 3 table levels, all manually maintained. A single counter bug is visible to every user.

**Recommendation**: Dedicated `CounterService` with explicit methods per operation type. All counter updates within the same database transaction. Full resync capability for maintenance.

### Finding 3: Visibility Service is the Core State Machine
**Confidence**: HIGH  
**Impact**: HIGH  

The 4-state visibility model (Unapproved/Approved/Deleted/Reapprove) with cascade behavior on topic-level operations is the most complex business logic in the system.

**Recommendation**: Dedicated `VisibilityService` that is the ONLY entry point for visibility changes. It handles counter side effects, cascade logic, and first/last post recalculation triggers.

### Finding 4: Poll is an Aggregate Sub-Entity, Not Independent
**Confidence**: HIGH  
**Impact**: MEDIUM  

Poll metadata lives on the topics table. Polls are created/edited via first post only. Poll voting is an independent operation.

**Recommendation**: Poll configuration embedded in Topic entity. Separate `PollService` for voting CRUD with its own repository for options/votes tables.

### Finding 5: Draft is a Simple Bounded Context
**Confidence**: HIGH  
**Impact**: LOW  

7-column table, manual save only, no auto-purge, no attachments, no polls.

**Recommendation**: Lightweight `DraftService` with raw text storage. Good candidate for first implementation as confidence-builder. Add auto-purge cron via event.

### Finding 6: Two-Phase Query Pattern is Well-Proven
**Confidence**: HIGH  
**Impact**: MEDIUM  

Fetch IDs (paginated) then fetch full data. With reverse optimization for late pages.

**Recommendation**: Preserve this pattern in `PostRepository::findPaginated()` and `TopicRepository::findByForum()`. Performance is validated by years of production use.

### Finding 7: Forum Counter Updates Need Cross-Service Coordination
**Confidence**: HIGH  
**Impact**: HIGH  

Threads creates content IN forums. Forum statistics are owned by `phpbb\hierarchy`. These updates must be in the same transaction.

**Recommendation**: `phpbb\hierarchy` exposes `updateForumStats()` and `updateForumLastPost()` methods. Threads calls these within its transaction. This is a synchronous cross-service call, not an event.

---

## 14. Open Design Questions

### Q1: Storage Format Migration Strategy [HIGH PRIORITY]
How to handle the transition from s9e XML to raw text + JSON metadata for existing content? Options:
- A) Add columns, backfill via reparser (batch migration)
- B) Dual-format support indefinitely (complexity cost)
- C) Immediate full migration on first edit (lazy migration)

### Q2: Forum Counter Update Ownership [HIGH PRIORITY]
Should `phpbb\threads` directly update forum counter columns (cross-service DB write) or call hierarchy methods? The answer affects transaction boundaries and service coupling.

### Q3: Optimistic Concurrency for Post Edits [MEDIUM PRIORITY]
Legacy uses MD5 checksum to detect concurrent edit conflicts. Should the new design use:
- A) DB-level optimistic locking (version column)
- B) Checksum comparison (legacy pattern)
- C) Last-write-wins (simplest)

### Q4: Content Pipeline Caching Strategy [MEDIUM PRIORITY]
If rendering is lazy (per-user preferences affect output), how to cache efficiently?
- A) Cache per unique (content_hash, render_context_hash) pair
- B) Cache the "base" render, apply per-user transforms client-side
- C) Server-side render caching with invalidation on preference change

### Q5: Read Tracking for API Consumers [LOW PRIORITY]
Should the threads service handle read tracking internally, or should API consumers manage their own read state? Mobile apps may have different UX for "unread" than web.

### Q6: Global Announcements [LOW PRIORITY]
How should `TopicType::Global` topics be handled? They appear in all forums but belong to one forum. Does the threads service or the API layer handle cross-forum display?

### Q7: Shadow Topics for Moves [LOW PRIORITY]
When a topic is moved, should the new design create a shadow topic (redirect) like legacy, use HTTP redirects, or just update the forum_id? Shadow topics add complexity but preserve bookmarked URLs.

---

## 15. Appendices

### Appendix A: Table Schemas

*(Full DDL for all 9 tables documented in topic-post-schema.md — see gathering/topic-post-schema.md)*

**Tables**: phpbb_topics, phpbb_posts, phpbb_topics_posted, phpbb_topics_track, phpbb_topics_watch, phpbb_drafts, phpbb_poll_options, phpbb_poll_votes, phpbb_bookmarks

### Appendix B: Complete Event Catalog

| Event | Category | Timing |
|-------|----------|--------|
| TopicCreatedEvent | Topic lifecycle | Post-commit |
| PostCreatedEvent | Post lifecycle | Post-commit |
| PostEditedEvent | Post lifecycle | Post-commit |
| PostVisibilityChangedEvent | Visibility | Post-commit |
| TopicVisibilityChangedEvent | Visibility | Post-commit |
| PostHardDeletedEvent | Deletion | Post-commit |
| TopicHardDeletedEvent | Deletion | Post-commit |
| TopicBumpedEvent | Topic lifecycle | Post-commit |
| TopicLockedEvent | Topic lifecycle | Post-commit |
| TopicMovedEvent | Topic lifecycle | Post-commit |
| TopicTypeChangedEvent | Topic lifecycle | Post-commit |
| PollCreatedEvent | Poll | Post-commit |
| PollVoteCastEvent | Poll | Post-commit |
| PollDeletedEvent | Poll | Post-commit |
| DraftSavedEvent | Draft | Post-commit |
| DraftDeletedEvent | Draft | Post-commit |
| ContentValidateEvent | Content pipeline | Synchronous |
| ContentParseEvent | Content pipeline | Synchronous |
| ContentPostParseEvent | Content pipeline | Synchronous |
| ContentPreRenderEvent | Content pipeline | Synchronous |
| ContentRenderEvent | Content pipeline | Synchronous |
| ContentPostRenderEvent | Content pipeline | Synchronous |

### Appendix C: Visibility Constants

| Constant | Value | Counter Field | Description |
|----------|-------|---------------|-------------|
| ITEM_UNAPPROVED | 0 | `*_unapproved` | Pending first approval |
| ITEM_APPROVED | 1 | `*_approved` | Visible to all |
| ITEM_DELETED | 2 | `*_softdeleted` | Soft-deleted, visible to moderators |
| ITEM_REAPPROVE | 3 | `*_unapproved` | Edited, needs re-approval |

### Appendix D: Topic Type Constants

| Constant | Value | Display Behavior |
|----------|-------|-----------------|
| POST_NORMAL | 0 | Standard topic, sorted by activity |
| POST_STICKY | 1 | Pinned to top of forum listing |
| POST_ANNOUNCE | 2 | Announcement, shown in parent forum only |
| POST_GLOBAL | 3 | Global announcement, shown in ALL forums |

### Appendix E: Topic/Post Status Constants

| Constant | Value | Applies To |
|----------|-------|-----------|
| ITEM_UNLOCKED | 0 | Topic (open for replies) |
| ITEM_LOCKED | 1 | Topic (no new replies) |
| ITEM_MOVED | 2 | Topic (shadow redirect) |

### Appendix F: Legacy submit_post() Modes

| User Mode | Internal post_mode | Tables Affected |
|-----------|-------------------|-----------------|
| `post` | `post` | INSERT topics + posts, UPDATE forums + users |
| `reply` / `quote` | `reply` | INSERT posts, UPDATE topics + forums + users |
| `edit` (only post) | `edit_topic` | UPDATE posts + topics |
| `edit` (first post) | `edit_first_post` | UPDATE posts + topics |
| `edit` (last post) | `edit_last_post` | UPDATE posts + topics |
| `edit` (middle post) | `edit` | UPDATE posts |

### Appendix G: Notification Types from Legacy

| Mode | Visibility | Notification Types |
|------|-----------|-------------------|
| post (new topic) | APPROVED | `notification.type.quote`, `notification.type.topic` |
| reply/quote | APPROVED | `notification.type.quote`, `notification.type.bookmark`, `notification.type.post`, `notification.type.forum` |
| post (new topic) | UNAPPROVED | `notification.type.topic_in_queue` |
| reply/quote | UNAPPROVED | `notification.type.post_in_queue` |
| edit (first/topic) | REAPPROVE | `notification.type.topic_in_queue` |
| edit (other) | REAPPROVE | `notification.type.post_in_queue` |

### Appendix H: Source File Index

| Finding Document | Key Source Files Analyzed |
|-----------------|--------------------------|
| posting-workflow.md | web/posting.php, src/phpbb/common/functions_posting.php |
| topic-post-schema.md | phpbb_dump.sql, src/phpbb/common/constants.php |
| content-format.md | src/phpbb/common/message_parser.php, src/phpbb/common/functions_content.php, src/phpbb/forums/textformatter/s9e/*.php |
| polls-drafts.md | web/posting.php, src/phpbb/common/functions_posting.php, web/viewtopic.php |
| topic-display.md | web/viewtopic.php, web/viewforum.php, src/phpbb/forums/pagination.php |
| soft-delete-visibility.md | src/phpbb/forums/content_visibility.php, src/phpbb/common/mcp/mcp_queue.php |
| attachment-patterns.md | src/phpbb/forums/attachment/delete.php, src/phpbb/common/message_parser.php, web/posting.php |
