# Findings: phpBB3 Features Not Covered in M0–M14

**Research date**: 2026-04-25
**Sources investigated**: `web/` (13 PHP entry points), `src/phpbb3/common/ucp/` (12 modules),
`src/phpbb3/common/mcp/` (10 modules), `src/phpbb3/common/acp/` (25 modules),
`src/phpbb3/forums/` (feed, cron, captcha, avatar, notification subsystems)

---

## Entry Points Analysis (`web/` directory)

| File | What it does | M0–M14 coverage |
|---|---|---|
| `index.php` | Board index — forum list | M5a (hierarchy), M10 (frontend) |
| `viewforum.php` | Topic list per forum | M5a + M6, M10 |
| `viewtopic.php` | Read a thread / posts | M6, M10 |
| `posting.php` | Post editor (new topic / reply / edit); includes draft save/load, smilies popup | M6 partially — drafts & editor UX **not covered** |
| `search.php` | Full-text search | M9 |
| `mcp.php` | Moderator Control Panel — queue, ban, warn, logs, notes | M12 partially — warnings, bans, logs, notes **not covered** |
| `ucp.php` | User Control Panel — profile, prefs, groups, attachments, drafts, notifications, PMs | M2 + M7 + M8 partially — many sub-features **not covered** |
| `memberlist.php` | Member directory, public profiles, team page, contact admin, live search | **Not covered** |
| `viewonline.php` | Who's online list | **Not covered** |
| `feed.php` | RSS/Atom feeds (board, forum, topic) | **Not covered** |
| `report.php` | Report a post/PM (redirects to controller) | M12 (reports) — covered |
| `faq.php` | FAQ and BBCode help pages | **Not covered** |
| `cron.php` | Trigger scheduled tasks | **Not covered** |

---

## Uncovered Feature Areas

### 1. RSS / Atom Feeds

- **phpBB3 location**: `web/feed.php`, `src/phpbb3/forums/feed/` (full feed subsystem — board/forum/topic feeds)
- **What it does**: Generates RFC-compliant Atom/RSS feeds for the whole board, per-forum, and per-topic. Allows users to consume new posts in any feed reader.
- **M0–M14 coverage**: None
- **Impact**: **High** — standard web content syndication; expected by many forum users and search indexers

---

### 2. Who's Online

- **phpBB3 location**: `web/viewonline.php`, `src/phpbb3/forums/viewonline_helper.php`
- **What it does**: Lists all currently active sessions (members and guests) with their current location, sorted by username/join/location. Supports admin whois IP lookup.
- **M0–M14 coverage**: None
- **Impact**: **Medium** — community transparency feature; frequently visible on forum index pages

---

### 3. Member Directory & Public Profiles

- **phpBB3 location**: `web/memberlist.php`
- **What it does**: Searchable member list; public profile view; group member list; team/moderator list page; live username search (AJAX); contact-admin contact form.
- **M0–M14 coverage**: None (M2 builds the *internal* user service but no public-facing member list or profile view API)
- **Impact**: **High** — fundamental social feature of any forum; public profiles are expected by all users

---

### 4. Registration Flow (CAPTCHA, Email Verification, COPPA)

- **phpBB3 location**: `src/phpbb3/common/ucp/ucp_register.php`, `src/phpbb3/common/acp/acp_captcha.php`
- **What it does**: Full registration flow: terms agreement, CAPTCHA challenge (Q&A, reCAPTCHA, etc.), email activation link, admin approval option, COPPA gate, account resend/activation.
- **M0–M14 coverage**: M3 (Auth) covers JWT login only — no registration endpoint, no CAPTCHA service, no email activation pipeline described
- **Impact**: **High** — a forum without a registration flow cannot acquire users

---

### 5. Outbound Email System (Messenger / Mail Queue)

- **phpBB3 location**: `src/phpbb3/common/functions_messenger.php` — `messenger` class; mail queue in DB; Jabber IM support
- **What it does**: Sends transactional emails (password reset, account activation, admin mass-email, topic watch notifications, PM notifications). Uses a queue table + cron flush. Supports templated messages per language.
- **M0–M14 coverage**: None — notifications (M8) are HTTP-only polling; no outgoing email pipeline is planned
- **Impact**: **High** — password reset alone is a hard requirement; email-based topic subscriptions are a core forum feature

---

### 6. Read Tracking ("New Posts" Markers)

- **phpBB3 location**: `src/phpbb3/common/functions_display.php`, `TOPICS_TRACK_TABLE`, `FORUMS_TRACK_TABLE` — DB-based read tracking
- **What it does**: Tracks which topics/posts each logged-in user has read. Displays "new" icons on forums and topics; powers "View new posts" / "View unread posts" links. Falls back to cookie-based tracking for guests.
- **M0–M14 coverage**: None — M6 (Threads) builds topics/posts data but has no read-state layer
- **Impact**: **High** — one of the most-used daily UX features; determines the "I've already read this" indicator for every user visit

---

### 7. Topic & Forum Watching / Email Subscriptions

- **phpBB3 location**: `TOPICS_WATCH_TABLE`, `FORUMS_WATCH_TABLE`, `src/phpbb3/common/functions_posting.php:2431`, `functions_display.php:1332`
- **What it does**: Users subscribe to a topic or forum; when new posts appear they receive an email notification (or IM). Separate from in-app notification system (M8).
- **M0–M14 coverage**: None — M8 covers in-app HTTP polling notifications; email-based watch/subscription is a distinct unplanned feature
- **Impact**: **High** — the primary way phpBB users stay informed about topics without visiting the board

---

### 8. User Preferences (Language, Timezone, Date Format, Style)

- **phpBB3 location**: `src/phpbb3/common/ucp/ucp_prefs.php` — `personal`, `post`, `display` modes
- **What it does**: Per-user settings: notification method, date format, interface language, forum style/theme, timezone, DST, post visibility defaults, board-wide topic display options.
- **M0–M14 coverage**: M2 stores the `user` entity (includes preference columns) but no UCP preferences API nor React UI is described
- **Impact**: **High** — i18n and timezone support are baseline expectations for international communities

---

### 9. Friends & Foes (Zebra System)

- **phpBB3 location**: `src/phpbb3/common/ucp/ucp_zebra.php`, `ZEBRA_TABLE`
- **What it does**: Users can mark other users as "friend" (highlight posts) or "foe" (hide posts/block PMs). A simplistic social/blocking primitive.
- **M0–M14 coverage**: None
- **Impact**: **Medium** — community safety feature; foe blocking is relied on by users to filter harassing content

---

### 10. Post Drafts

- **phpBB3 location**: `DRAFTS_TABLE` (schema.json:522), `web/posting.php` (save/load buttons), `ucp_main.php` (draft list)
- **What it does**: Saves incomplete post or topic compositions to DB so the user can resume later from UCP. Draft list is shown in UCP front page.
- **M0–M14 coverage**: None — M6 covers post CRUD but no draft lifecycle
- **Impact**: **Medium** — quality-of-life posting feature; prevents data loss on long compositions

---

### 11. User Signatures

- **phpBB3 location**: `src/phpbb3/common/ucp/ucp_profile.php` (sig mode), `user_sig` column in `phpbb_users` (schema.json:3330)
- **What it does**: Users compose a BBCode signature appended to every post. Rendered by the text formatter. Configuration allows admin to restrict length and BBCode tags.
- **M0–M14 coverage**: None — M11 covers BBCode rendering engine but not the signature feature itself
- **Impact**: **Medium** — cultural staple of forum communities; widely expected

---

### 12. Avatar System

- **phpBB3 location**: `src/phpbb3/forums/avatar/` (full driver, manager, helper classes), avatar upload/remote/Gravatar drivers
- **What it does**: User profile pictures. Supports upload to server, remote URL, Gravatar, and gallery. Image validation, resize, deletion. Shown in posts, memberlist, and profiles.
- **M0–M14 coverage**: None — M5b (Storage) provides file upload infrastructure but the avatar user-facing flow (driver selection, resize, per-user linking) is not described
- **Impact**: **High** — visual user identity; expected in every modern forum

---

### 13. User Ranks

- **phpBB3 location**: `src/phpbb3/common/acp/acp_ranks.php`, `RANKS_TABLE`
- **What it does**: Post-count-based or special (manually assigned) rank titles displayed under usernames (e.g., "Junior Member", "Senior Member", "Moderator"). Admin manages rank thresholds and images.
- **M0–M14 coverage**: None
- **Impact**: **Medium** — gamification / community hierarchy signal; traditional forum feature

---

### 14. FAQ / BBCode Help Pages

- **phpBB3 location**: `web/faq.php`, `src/phpbb3/forums/help/` controllers
- **What it does**: Static FAQ page for the board rules. Separate BBCode reference page explaining all formatting tags. Linked from the post editor.
- **M0–M14 coverage**: None — M11 covers the formatting engine but not the help/FAQ page feature
- **Impact**: **Low–Medium** — useful docs surface; reduces support burden for new users

---

### 15. Cron / Scheduled Task Framework

- **phpBB3 location**: `web/cron.php` (redirect only), `src/phpbb3/forums/cron/` (manager + tasks — email queue flush, orphan cleanup, search index, read-mark pruning, etc.)
- **What it does**: Pluggable scheduled-task framework. Tasks are triggered by page visits or a real cron job. Built-in tasks: flush email queue, prune old sessions, prune read-mark tables, rebuild search index, garbage-collect old PMs/drafts.
- **M0–M14 coverage**: None as a service — M5b mentions an orphan cleanup job but no general cron infrastructure is planned
- **Impact**: **High** — many features depend on background jobs (email flushing, pruning, search reindex). Without it the email queue never drains.

---

### 16. Warning System

- **phpBB3 location**: `src/phpbb3/common/mcp/mcp_warn.php`
- **What it does**: Moderators issue formal warnings to users (optionally notified by PM/email). Warnings accumulate; admins set point thresholds that auto-ban users. Warn history visible in MCP.
- **M0–M14 coverage**: None — M12 covers reports and queue but not the warning/points system
- **Impact**: **Medium** — structured disciplinary tooling; important for active communities

---

### 17. Moderator Notes on Users

- **phpBB3 location**: `src/phpbb3/common/mcp/mcp_notes.php`
- **What it does**: Moderators attach private notes to user accounts (visible to mods only). Used to track prior behaviour, warnings context, ban reasons.
- **M0–M14 coverage**: None
- **Impact**: **Medium** — internal moderation memory across staff rotations

---

### 18. CAPTCHA / Anti-Spam System

- **phpBB3 location**: `src/phpbb3/forums/captcha/` (factory + drivers — Q&A, GD, reCAPTCHA), `acp_captcha.php`
- **What it does**: Pluggable CAPTCHA at registration, guest posting, and contact forms. Supports Q&A (custom questions), image GD CAPTCHA, reCAPTCHA v2/v3. Also tracks registration attempt counts.
- **M0–M14 coverage**: None (mentioned as a thing to check in the research brief, but absent from all milestones)
- **Impact**: **High** — without anti-spam gating, registration bots are an immediate production problem

---

### 19. Word Censorship / Filter

- **phpBB3 location**: `src/phpbb3/common/acp/acp_words.php`, applied during post rendering and username validation
- **What it does**: Admin-defined word replacement rules applied to all post content and optionally topic titles. Supports regex. Rules cached.
- **M0–M14 coverage**: None — M11 covers content formatting but not content moderation filters
- **Impact**: **Medium** — legal/community policy requirement for many boards

---

### 20. Extension / Plugin System

- **phpBB3 location**: `src/phpbb3/common/acp/acp_extensions.php`, `src/phpbb3/forums/extension/` (manager, ext base, event integration)
- **What it does**: Enable/disable/update third-party extensions. Extensions can add routes, events, templates, language strings, migrations. ACP extension manager shows version, metadata, migration status.
- **M0–M14 coverage**: Roadmap ADR says "Extension model ADR — Macrokernel: events+decorators, no tagged DI" — decision made, but no milestone for implementing the extension infrastructure itself
- **Impact**: **High** — the phpBB ecosystem depends on extensions; without it phpBB4 is a closed system

---

### 21. Language Pack Management

- **phpBB3 location**: `src/phpbb3/common/acp/acp_language.php`, `src/phpbb3/language/`
- **What it does**: Admin can install, edit, and delete language packs. Individual language file strings are editable from ACP. Language selection per user (M8 user prefs). Language files cover all UI strings.
- **M0–M14 coverage**: None — React SPA (M10) will need i18n but no language management service is planned
- **Impact**: **Medium** — essential for non-English communities; international phpBB adoption depends on it

---

### 22. Mass Email to Users

- **phpBB3 location**: `src/phpbb3/common/acp/acp_email.php`
- **What it does**: Admin sends email blast to all users or a group (using mail queue). Supports per-user language. Uses the messenger/email system.
- **M0–M14 coverage**: None
- **Impact**: **Low–Medium** — admin operational tool; needed for announcements and maintenance notices

---

### 23. Bot / Spider Management

- **phpBB3 location**: `src/phpbb3/common/acp/acp_bots.php`
- **What it does**: Defines known search engine bots by user agent string or IP. Bots are tracked as a special user type, shown in "Who's Online", exempt from session limits.
- **M0–M14 coverage**: None
- **Impact**: **Low** — SEO and analytics benefit; low urgency

---

### 24. Forum & User Pruning

- **phpBB3 location**: `src/phpbb3/common/acp/acp_prune.php`
- **What it does**: Bulk-delete old posts/topics from a forum matching age/reply count criteria. Bulk-deactivate or delete inactive user accounts. Useful for forum housekeeping.
- **M0–M14 coverage**: None
- **Impact**: **Low** — admin housekeeping utility; not user-facing

---

### 25. Attachment Management (UCP)

- **phpBB3 location**: `src/phpbb3/common/ucp/ucp_attachments.php`
- **What it does**: Users view and delete their own attachments across all posts (paginated list with sort). Enforces per-user attachment quota.
- **M0–M14 coverage**: M5b covers file storage service but the user-facing "my attachments" management UI is not described
- **Impact**: **Medium** — privacy and storage management for users

---

### 26. Contact Admin Form

- **phpBB3 location**: `web/memberlist.php?mode=contactadmin`
- **What it does**: Public contact form allowing anyone (including guests) to send a message to the board administration without needing an account.
- **M0–M14 coverage**: None
- **Impact**: **Low–Medium** — accessibility for non-members; useful for abuse/legal contact

---

### 27. Group Membership (UCP)

- **phpBB3 location**: `src/phpbb3/common/ucp/ucp_groups.php` — membership and manage modes
- **What it does**: Users join/leave groups, request membership for closed groups, change their default (displayed) group. Group leaders manage member lists without full admin access.
- **M0–M14 coverage**: M2 includes group data model; no UCP group interaction API is described
- **Impact**: **Medium** — community organisation via groups is a core phpBB feature

---

## Summary

| Priority | Feature | Milestone Coverage |
|---|---|---|
| **High** | CAPTCHA / Anti-spam | None |
| **High** | Registration flow (email activation, COPPA) | None |
| **High** | Outbound Email System (messenger + queue) | None |
| **High** | Cron / Scheduled Tasks framework | None |
| **High** | Read Tracking ("new posts" markers) | None |
| **High** | Avatar System | None |
| **High** | Topic/Forum watching (email subscriptions) | None |
| **High** | Member Directory & Public Profiles | None |
| **High** | RSS / Atom Feeds | None |
| **High** | Extension / Plugin System | Decision ADR only, no implementation milestone |
| **Medium** | User Preferences (language, timezone, style) | None (data model in M2 only) |
| **Medium** | Who's Online | None |
| **Medium** | User Ranks | None |
| **Medium** | Friends / Foes (Zebra) | None |
| **Medium** | User Signatures | None |
| **Medium** | Post Drafts | None |
| **Medium** | Warning System | None |
| **Medium** | Moderator Notes | None |
| **Medium** | Word Censorship | None |
| **Medium** | Language Pack Management | None |
| **Medium** | Group Membership (UCP) | M2 data model only |
| **Medium** | Attachment Management (UCP) | M5b storage only |
| **Low** | FAQ / BBCode Help Pages | None |
| **Low** | Mass Email | None |
| **Low** | Contact Admin Form | None |
| **Low** | Bot / Spider Management | None |
| **Low** | Forum & User Pruning | None |
