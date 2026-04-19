# Legacy Code Business Rules — User Service

Source files analyzed:
- `src/phpbb/forums/session.php` (1886 lines)
- `src/phpbb/common/functions_user.php` (3884 lines)
- `src/phpbb/forums/profilefields/manager.php`
- `src/phpbb/forums/profilefields/type/type_interface.php`

---

## Session Management Rules

### Rule: IP Validation (session.php:382–410)

**Business Logic**: When an existing session is found in DB, the system validates the client's IP by comparing N octets (IPv4) or N groups (IPv6), where N = `$config['ip_check']` (0–4). If `ip_check=0`, IP checking is effectively disabled.

- **IPv4**: Splits on `.`, takes first N octets, compares join.
- **IPv6**: Uses `short_ipv6()` helper to truncate to N groups then compares.
- If validation fails, session is invalidated and a new anonymous session is created.

**Edge Cases**:
- `ip_check = 0` disables IP checks entirely (useful behind load balancers).
- When `forwarded_for_check` is enabled, the `X-Forwarded-For` header is also validated — each IP in the header must pass `filter_var(FILTER_VALIDATE_IP)` or the entire header is discarded.
- For ban checking, if `forwarded_for_check` is enabled, ALL IPs (forwarded + direct) are checked against ban list.

### Rule: Browser Fingerprint Check (session.php:412–413)

**Business Logic**: If `$config['browser_check']` is enabled, the stored session browser string (first 149 chars, lowercased, trimmed) must match current User-Agent.

### Rule: Referer Validation (session.php:415–426)

**Business Logic**: Referer is validated on non-GET requests only (POST, PUT, etc.) when `$config['referer_validation']` is set. HEAD and TRACE are considered foul play. Validation checks the host matches and optionally the script path (`REFERER_VALIDATE_PATH` mode).

- Empty referer is accepted (user may have disabled it).
- Server port stripping is handled for non-standard ports.

### Rule: Session Expiry (session.php:435–447)

**Business Logic**: Two expiry modes:
1. **Normal sessions**: Expire after `$config['session_length']` seconds + 60 second grace period.
2. **Auto-login sessions**: Expire after `$config['max_autologin_time']` days (value * 86400 seconds + 60 second grace). If `allow_autologin` is disabled board-wide, auto-login sessions also expire.

### Rule: Auth Provider Session Validation (session.php:429–434)

**Business Logic**: The active auth provider's `validate_session()` method can veto a session. If it returns `false` (not `null`), the session is expired. `null` means "no opinion" (pass-through).

### Rule: Session Creation — Bot Detection (session.php:533–563)

**Business Logic**: On every new session, the system checks the bot list (cached). For each bot definition:
1. If `bot_agent` is set, match UA via regex (wildcards converted to `.*?`).
2. If `bot_ip` is set, check if client IP starts with any of the comma-separated bot IPs.
3. If both agent AND ip are defined, both must match.
4. Bot sessions are reused: same `session_id` is recycled if IP/browser still match.
5. Bot users are redirected (301) if they carry `?sid=` in URL.

### Rule: Session ID Generation (session.php:790)

**Business Logic**: `$this->session_id = md5(unique_id())` — MD5 of a unique random ID. Session is stored in `SESSIONS_TABLE`.

### Rule: Auto-Login Key Rotation (session.php:795–797, 1588–1643)

**Business Logic**: On session creation, if `session_autologin` is true:
- `set_login_key()` generates a new key: `unique_id(hexdec(substr(session_id, 0, 8)))`.
- The key is stored as `md5($key_id)` in `SESSIONS_KEYS_TABLE` (DB stores hash, cookie stores plaintext).
- If updating an existing key, it finds by `md5(old_key)` and replaces with `md5(new_key)`.
- The cookie gets the plaintext `$key_id`, DB stores `md5($key_id)`.
- Login validation: On autologin, joins `key_id = md5(cookie_k)` in the sessions keys table.

### Rule: Session Creation — Active Session Limiting (session.php:770–783)

**Business Logic**: If `$config['active_sessions']` is set and this is a brand new session (not replacing existing), count sessions in last 60 seconds. If over limit, return 503 Service Unavailable.

### Rule: Form Salt / CSRF Token (session.php:812–828)

**Business Logic**: On session creation for non-bots, if user has only 1 concurrent session or empty `user_form_salt`, regenerate `user_form_salt = unique_id()`. Otherwise just update `user_last_active`.

### Rule: Cookie Expiry Calculation (session.php:805–808)

**Business Logic**: Cookie lifetime = `max_autologin_time` days, or 365 days (31536000s) if `max_autologin_time` is 0. Three cookies set: `_u` (user_id), `_k` (autologin key), `_sid` (session_id).

### Rule: Session Kill (session.php:846–906)

**Business Logic**: Logout sequence:
1. DELETE session row from `SESSIONS_TABLE`.
2. Fire `core.session_kill_after` event.
3. Call auth provider `logout()`.
4. If user is not anonymous: update `user_lastvisit`, delete specific autologin key from `SESSIONS_KEYS_TABLE` (only the matching key, not all).
5. Set all cookies to expired (time - 31536000).
6. Optionally create a new anonymous session.

### Rule: Session Garbage Collection (session.php:926–1087)

**Business Logic**: GC runs when `time_now > session_last_gc + session_gc`:
1. Update `user_lastvisit` and `user_lastpage` from the most recent expired session for each user.
2. DELETE all sessions older than `session_length`.
3. Update `session_last_gc` config.
4. Delete autologin keys older than `max_autologin_time` days.
5. Run CAPTCHA garbage collection.
6. Delete login attempts older than `ip_login_limit_time`.

### Rule: Reset Login Keys (session.php:1652–1695)

**Business Logic**: On password change:
1. DELETE all keys from `SESSIONS_KEYS_TABLE` for user.
2. Get most recent session time, update `user_lastvisit`.
3. DELETE all sessions EXCEPT the current one.
4. If current user has a cookie key, regenerate it via `set_login_key()`.

---

## Ban System Rules

### Rule: Ban Check Algorithm (session.php:1142–1267)

**Business Logic**: The full ban check flow:
1. Query `BANLIST_TABLE` with appropriate filters for user_id, IP(s), email.
2. For each ban row:
   - Skip if `ban_end < time()` and `ban_end != 0` (expired).
   - **IP matching**: Uses wildcard regex (`str_replace('\*', '.*?')`) against user IPs.
   - **User ID matching**: Exact integer match.
   - **Email matching**: Wildcard regex against user email.
3. If any ban matches AND `ban_exclude = 1` → user is **NOT** banned (exclusion overrides). **Exclusion breaks the loop immediately.**
4. If banned and not excluded, ban continues searching for an exclusion. Only after all rows processed (no exclusion found) is the user considered banned.
5. **FOUNDERS are never checked** — `check_ban_for_current_session` skips users with `user_type == USER_FOUNDER`.

**Ban Trigger Identification**: Tracks whether ban was triggered by `user`, `ip`, or `email` for display purposes.

**Cache**: Anonymous user ban checks are cached for 3600s. Registered users use TTL=0 (no cache).

### Rule: Ban Creation (functions_user.php:950–1295)

**Business Logic**: `user_ban()` flow:
1. Delete stale (expired) bans first.
2. Compute `ban_end`: `ban_len` * 60 seconds from now, or parse `YYYY-MM-DD` for custom date, or 0 for permanent.
3. **Founder protection**: Founders can never be banned. Their user_ids, emails, and usernames are excluded from ban lists.
4. **Self-ban prevention**: Cannot ban yourself.
5. **Mode-specific processing**:
   - `user`: Resolve usernames to IDs via `username_clean`. Refuses if target not found.
   - `ip`: Supports: single IPv4, IPv4 ranges (x.x.x.x - y.y.y.y expanded to individual entries or wildcards), wildcard (`*`), IPv6 prefix wildcards, and hostnames (resolved via `gethostbynamel()`).
   - `email`: Wildcard patterns, max 100 chars.
6. **Duplicate handling**: If entity already banned with same exclude flag, the old ban is deleted and re-inserted with new length.
7. **Forced logout**: On ban (not exclude), matching sessions are immediately deleted. For user bans, `SESSIONS_KEYS_TABLE` entries are also deleted.
8. **Logging**: Added to admin log, moderator log, and user notes (for user bans).
9. **Cache invalidation**: `$cache->destroy('sql', BANLIST_TABLE)`.

### Rule: Unban (functions_user.php:1378–1445)

**Business Logic**:
1. Delete stale bans.
2. Delete ban rows by `ban_id`.
3. Log to admin, moderator, and user notes.
4. Invalidate ban cache.

---

## User Lifecycle Rules

### Rule: User Add (functions_user.php:195–397)

**Business Logic**: Required fields: `username`, `group_id`, `user_email`, `user_type`. The flow:
1. **Username sanitization**: `utf8_clean_string()` — empty clean name = rejection.
2. **Email**: Stored lowercase.
3. **Defaults applied**: timezone from board config, dateformat, language, style, `user_options=230271`, `user_regdate=time()`, `user_passchg=time()`, `user_lastmark=time()`, `user_form_salt=unique_id()`.
4. **Default notifications**: email on `notification.type.post` and `notification.type.topic`.
5. **INSERT into USERS_TABLE**.
6. **Custom profile fields**: INSERT into `PROFILE_FIELDS_DATA_TABLE` if provided.
7. **Group membership**: INSERT into `USER_GROUP_TABLE` with `user_pending=0`.
8. **Set default group**: Call `group_set_user_default()` to cascade group properties.
9. **Newly Registered group**: If `new_member_post_limit` config is set and `user_new=1`, add to NEWLY_REGISTERED special group. If `new_member_group_default`, make it the default group.
10. **Stats update**: If user_type is NORMAL or FOUNDER, increment `num_users`, update `newest_user_*` config.
11. **Notification subscriptions**: Subscribe to configured notifications.

### Rule: User Delete (functions_user.php:404–735)

**Business Logic**: Accepts `$mode` (retain|remove) and array of user IDs. Full cleanup:
1. **Reports**: Unmark posts/topics reported by these users.
2. **Avatar removal**: Delete uploaded avatars.
3. **Auth provider unlinking**: Call `unlink_account()` on all providers.
4. **Post handling**:
   - `retain`: Reassign posts to ANONYMOUS, set `post_username` to either guest or original username. Update forums, topics accordingly. Add post count to anonymous user.
   - `remove`: Call `delete_posts('poster_id', $user_ids)` which cascades to attachments, etc.
5. **Table cleanup** (direct DELETE): `USERS_TABLE`, `USER_GROUP_TABLE`, `TOPICS_WATCH_TABLE`, `FORUMS_WATCH_TABLE`, `ACL_USERS_TABLE`, `TOPICS_TRACK_TABLE`, `TOPICS_POSTED_TABLE`, `FORUMS_TRACK_TABLE`, `PROFILE_FIELDS_DATA_TABLE`, `MODERATOR_CACHE_TABLE`, `DRAFTS_TABLE`, `BOOKMARKS_TABLE`, `SESSIONS_KEYS_TABLE`, `PRIVMSGS_FOLDER_TABLE`, `PRIVMSGS_RULES_TABLE`, OAuth tables, user notifications.
6. **Edit attribution reset**: `post_edit_user`, `message_edit_user`, `post_delete_user`, `topic_delete_user` → ANONYMOUS.
7. **Log cleanup**: DELETE reportee entries, set `user_id` to ANONYMOUS in log.
8. **Zebra** (friends/foes): DELETE both directions.
9. **Ban list**: DELETE bans for these users.
10. **Sessions**: DELETE all sessions.
11. **Private messages**: Call `phpbb_delete_users_pms()`.
12. **Notifications**: Delete `admin_activate_user` notifications.
13. **Stats**: Update `num_users`, update `newest_user_*` if necessary.

All operations wrapped in a single DB transaction.

### Rule: User Active Flip (functions_user.php:743–930)

**Business Logic**: Toggles user between NORMAL↔INACTIVE states:
1. Skips USER_IGNORE and USER_FOUNDER (never deactivated).
2. On deactivation: Reset login keys (force logout).
3. Sets `user_inactive_time` and `user_inactive_reason` (INACTIVE_REGISTER, INACTIVE_PROFILE, INACTIVE_MANUAL, INACTIVE_REMIND).
4. Updates `num_users` config (decrement on deactivate, increment on activate).
5. Clears ACL prefetch cache.
6. Updates `newest_username`.

### Rule: Username Update (functions_user.php:133–189)

**Business Logic**: When username changes, updates across:
- `FORUMS_TABLE.forum_last_poster_name`
- `MODERATOR_CACHE_TABLE.username`
- `POSTS_TABLE.post_username`
- `TOPICS_TABLE.topic_first_poster_name` and `topic_last_poster_name`
- Updates only where the old name matches AND the poster is not ANONYMOUS.
- Updates `newest_username` config if it was the old name.
- Purges moderator cache SQL.

---

## Validation Rules

### Rule: Username Validation (functions_user.php:1761–1858)

**Constraints**:
1. **4-byte Unicode (emojis)**: Rejected — `[\x{10000}-\x{10FFFF}]`.
2. **Quote character**: `"` and `&quot;` forbidden.
3. **Empty clean name**: Rejected after `utf8_clean_string()`.
4. **Invisible/special Unicode**: `\x{180E}\x{2005}-\x{200D}\x{202F}\x{205F}\x{2060}\x{FEFF}` forbidden.
5. **Character set** (configurable via `$config['allow_name_chars']`):
   - `USERNAME_CHARS_ANY`: `.+` (any character)
   - `USERNAME_ALPHA_ONLY`: `[A-Za-z0-9]+`
   - `USERNAME_ALPHA_SPACERS`: `[A-Za-z0-9-[\]_+ ]+`
   - `USERNAME_LETTER_NUM`: `[\p{Lu}\p{Ll}\p{N}]+` (Unicode letters/numbers)
   - `USERNAME_LETTER_NUM_SPACERS`: `[-\]_+ [\p{Lu}\p{Ll}\p{N}]+`
   - `USERNAME_ASCII` (default): `[\x01-\x7F]+`
6. **Uniqueness**: Check `username_clean` in `USERS_TABLE`. Also check against group names (case-insensitive `LOWER(group_name)`).
7. **Disallowed names**: Cached list of regex patterns checked against clean username.

### Rule: Password Validation (functions_user.php:1870–1904)

**Constraints** (configurable via `$config['pass_complex']`):
- `PASS_TYPE_ANY`: No complexity check.
- `PASS_TYPE_CASE`: Requires uppercase `\p{Lu}` AND lowercase `\p{Ll}`.
- `PASS_TYPE_ALPHA`: Above + numbers `\p{N}`.
- `PASS_TYPE_SYMBOL`: Above + symbols `[^\p{Lu}\p{Ll}\p{N}]`.

Note: No minimum length check in `validate_password()` itself — that's handled by `validate_string()` separately.

### Rule: Email Validation (functions_user.php:1914–1935)

**Constraints**:
1. Regex check via `get_preg_expression('email')`.
2. If `$config['email_check_mx']`: DNS check for A or MX record on domain.
3. `validate_user_email()` additionally checks: email not banned, email not already in use (unless `allow_emailreuse` config is true).

---

## Group Management Rules

### Rule: Group Create/Update (functions_user.php:2340–2523)

**Business Logic**:
1. **Name validation**: 1–60 chars, uniqueness check via `group_validate_groupname()`.
2. **Type validation**: Must be GROUP_OPEN, GROUP_CLOSED, GROUP_HIDDEN, GROUP_SPECIAL, or GROUP_FREE.
3. **Legend/Teampage positioning**: Managed by `\phpbb\groupposition\` services.
4. **User attribute cascade**: When group is updated, attributes (`group_colour`, `group_rank`, `group_avatar*`) cascade to users whose default group is this one — BUT only if:
   - Avatar: only set for users who have no avatar currently.
   - Rank: only set for users who have rank=0.
   - Colour: always set for all users in that default group.
5. **Skip auth**: If `group_skip_auth` changes, ACL prefetch is cleared for all group members.
6. **Colour propagation**: Updates `forum_last_poster_colour`, `topic_first_poster_colour`, `topic_last_poster_colour` across forum/topic tables.

### Rule: Group User Add (functions_user.php:2751–2927)

**Business Logic**:
1. Validate user IDs exist (call `user_get_id_name()`).
2. Check existing membership — skip users already in group.
3. Leader promotion: If adding as leader and already member (non-leader), update instead of insert.
4. Insert with `user_pending` flag if requested.
5. If `$default=true`, call `group_user_attributes('default', ...)` to make it user's default group.
6. Clear ACL prefetch.
7. If pending, send `notification.type.group_request` notification.

### Rule: Group User Delete (functions_user.php:2949–3100)

**Business Logic**:
1. **Default group reassignment priority**: `ADMINISTRATORS > GLOBAL_MODERATORS > NEWLY_REGISTERED > [REGISTERED_COPPA] > REGISTERED > BOTS > GUESTS`.
2. When removing user from their current default group, the system finds the highest-priority special group they still belong to and makes that the new default.
3. Removes old group's avatar/rank from user before assigning new group's attributes.
4. Clears ACL prefetch.
5. Deletes `notification.type.group_request` notifications for removed users.

### Rule: Group Set User Default (functions_user.php:3437–3560)

**Business Logic**: Cascade mechanism for group→user property propagation:
- `user_colour` = `group_colour` (always set)
- `user_rank` = `group_rank` (only if user's current rank is 0)
- `user_avatar*` = `group_avatar*` (only if user has no avatar)
- Updates `group_id` on user record.
- After colour change: updates `forum_last_poster_colour`, `topic_first_poster_colour`, `topic_last_poster_colour`, and `newest_user_colour` config.
- Purges `MODERATOR_CACHE_TABLE` SQL cache.

### Rule: Group Memberships Query (functions_user.php:3583–3630)

**Business Logic**: `group_memberships()` can query by group_id(s), user_id(s), or both. Always filters `user_pending = 0`. Returns full rows from USER_GROUP_TABLE joined with username/email. Can return bool (first match) via `$return_bool`.

---

## DNSBL System

### Rule: DNSBL Checking (session.php:1455–1535)

**Business Logic**:
- Only IPv4 supported (IPv6 returns false immediately).
- Checks **Spamhaus SBL** (`sbl.spamhaus.org`) always.
- Checks **SpamCop** (`bl.spamcop.net`) only for `register` mode.
- Must be listed on **ALL** configured servers to be considered blacklisted.
- Spamhaus error responses (`127.255.255.254` = open resolver, `127.255.255.255` = volume limit) disable DNSBL globally and log a critical entry.

---

## Profile Field System

### Storage Model

**Tables**:
- `phpbb_profile_fields` (`$fields_table`): Field definitions (name, type, order, active, visibility flags).
- `phpbb_profile_fields_data` (`$fields_data_table`): User data. One row per user, columns `pf_<field_ident>` for each field.
- `phpbb_profile_fields_lang` (`$fields_lang_table`): Field labels/explanations per language.
- `phpbb_profile_fields_data_lang` (`$fields_data_lang_table`): Dropdown/option labels per language.

**Dynamic columns**: Each field adds a `pf_<ident>` column to the data table (managed by DB schema migrations).

### Type System

Types are registered as services implementing `type_interface`:
- `type_bool` — Boolean (checkbox or yes/no radio).
- `type_int` — Integer.
- `type_string` — Single-line text.
- `type_text` — Multi-line text.
- `type_url` — URL.
- `type_date` — Date picker.
- `type_dropdown` — Single-select dropdown with predefined options.

Each type handles:
- `get_profile_field()`: Extract value from request.
- `validate_profile_field()`: Return error or `false`.
- `get_default_field_value()`: Default for new users.
- `process_field_row()`: Render for template.

### CPF Data Handling

- `build_insert_sql_array()`: When inserting a new user, fills in default values for fields NOT already provided.
- `update_profile_field_data()`: Tries UPDATE first; if 0 affected rows, does INSERT (upsert pattern).
- `submit_cp_field()`: Validates on-submit; strips 4-byte UTF-8 via `utf8_encode_ucr()`.

### Visibility Flags

Fields have multiple visibility controls:
- `field_active` — Master switch.
- `field_show_on_reg` — Show during registration.
- `field_show_profile` — Show on profile edit.
- `field_hide` — Hidden from non-admins/mods.
- `field_no_view` — Never shown in public profiles.
- `field_required` — Mandatory.

---

## Key Observations

### Patterns and Gotchas

1. **Session ID = MD5**: Session IDs are MD5 hashes (32 hex chars). Auto-login keys stored as `md5(plaintext_key)` in DB — compare mechanism relies on hashing cookie value.

2. **Founders are immune**: `USER_FOUNDER` type bypasses ban checks entirely (`check_ban_for_current_session` skips them). They also can't be deactivated via `user_active_flip`.

3. **Exclusion overrides ban**: A single `ban_exclude=1` row matching a user immediately clears all bans for that check. This is checked per-call, not cached at user level.

4. **Bot session recycling**: Bots don't get new sessions — they reuse existing ones. Only one session per bot at a time (old ones are deleted if IP/browser doesn't match).

5. **Group priority for default reassignment**: Hard-coded order. If user is removed from their default group, the highest priority special group they belong to becomes default.

6. **Colour cascades widely**: Changing a group's colour triggers updates across FORUMS_TABLE, TOPICS_TABLE, and config. This is expensive. The new service should consider async/queued updates.

7. **user_options field**: Uses a bitmask integer (default 230271) for multiple boolean preferences. This should be decomposed in the new service.

8. **Post deletion has two modes**: `retain` (reassign to ANONYMOUS) vs `remove` (hard delete). The choice affects forum counters, topic state, and attachment cleanup.

9. **Session page tracking**: `session_page` is always the LAST page visited (except on first visit). The system uses a `session_created` flag to detect the very first pageview.

10. **user_new leave mechanism**: When `user_posts >= new_member_post_limit`, user leaves NEWLY_REGISTERED group on next session update. Checked in `update_session_infos()`.

11. **Email ban wildcard matching**: Uses `*` as wildcard in ban patterns, converted to `.*?` regex for matching, and `%` for SQL LIKE when querying sessions to delete.

12. **Profile field upsert**: CPF data uses try-UPDATE-then-INSERT pattern, not real upsert. Race condition possible but unlikely in single-user context.

13. **Cache invalidation on ban change**: Always destroys `'sql', BANLIST_TABLE` cache entry regardless of whether ban was actually added.

14. **Login attempt cleanup tied to GC**: Failed login attempts are only cleaned during session_gc (cron), not on each login attempt. Accumulation possible under load.

15. **Cookie security**: `HttpOnly` by default, `Secure` flag controlled by `cookie_secure` config. SameSite not explicitly set (relies on browser defaults). Cookie domain and path configurable.

16. **Active session counting**: Rate limiting counts sessions in last 60 seconds. This only triggers for the very first session for a client (`empty($this->data['session_time'])`).
