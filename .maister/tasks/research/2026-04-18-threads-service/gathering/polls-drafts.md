# Polls and Drafts Implementation Findings

## 1. POLLS

### 1.1 Database Schema

#### phpbb_topics — poll columns
**Source**: `phpbb_dump.sql:3547-3552`

```sql
poll_title        varchar(255) NOT NULL DEFAULT ''    -- Poll question text (BBCode-enabled)
poll_start        int(11) unsigned NOT NULL DEFAULT 0 -- Unix timestamp when poll was created
poll_length       int(11) unsigned NOT NULL DEFAULT 0 -- Duration in seconds (0 = no expiry)
poll_max_options  tinyint(4) NOT NULL DEFAULT 1       -- Max options user can select
poll_last_vote    int(11) unsigned NOT NULL DEFAULT 0 -- Unix timestamp of last vote cast
poll_vote_change  tinyint(1) unsigned NOT NULL DEFAULT 0 -- 1 = users can change their vote
```

Poll metadata lives directly on `phpbb_topics`. A topic either has a poll (`poll_start > 0`) or not.

#### phpbb_poll_options
**Source**: `phpbb_dump.sql:2641-2654`

```sql
CREATE TABLE phpbb_poll_options (
  poll_option_id    tinyint(4) NOT NULL DEFAULT 0,      -- Sequential ID within the topic (1-based)
  topic_id          int(10) unsigned NOT NULL DEFAULT 0, -- FK to phpbb_topics
  poll_option_text  text NOT NULL,                       -- Option text (BBCode-enabled)
  poll_option_total mediumint(8) unsigned NOT NULL DEFAULT 0, -- Vote count for this option
  KEY poll_opt_id (poll_option_id),
  KEY topic_id (topic_id)
);
```

No auto-increment PK — options are identified by `(topic_id, poll_option_id)` pair. `poll_option_id` is a tinyint (max 127 options).

#### phpbb_poll_votes
**Source**: `phpbb_dump.sql:2667-2680`

```sql
CREATE TABLE phpbb_poll_votes (
  topic_id        int(10) unsigned NOT NULL DEFAULT 0,  -- FK to phpbb_topics
  poll_option_id  tinyint(4) NOT NULL DEFAULT 0,        -- FK to poll_options
  vote_user_id    int(10) unsigned NOT NULL DEFAULT 0,   -- User who voted (0 for guests)
  vote_user_ip    varchar(40) NOT NULL DEFAULT '',       -- IP address of voter
  KEY topic_id (topic_id),
  KEY vote_user_id (vote_user_id),
  KEY vote_user_ip (vote_user_ip)
);
```

No unique constraint — vote deduplication is handled at application level. One row per user per option selected (multi-choice polls = multiple rows per user).

### 1.2 Poll Creation (posting.php + submit_post)

#### UI Input Gathering
**Source**: `web/posting.php:1016-1020`

When submitting/previewing, poll data is read from POST:
```php
$post_data['poll_title']        = $request->variable('poll_title', '', true);
$post_data['poll_length']       = $request->variable('poll_length', 0);
$post_data['poll_option_text']  = $request->variable('poll_option_text', '', true);
$post_data['poll_max_options']  = $request->variable('poll_max_options', 1);
$post_data['poll_vote_change']  = ($auth->acl_get('f_votechg', $forum_id) && $auth->acl_get('f_vote', $forum_id) && isset($_POST['poll_vote_change'])) ? 1 : 0;
```

`poll_option_text` is a single string with options separated by newlines.

#### Poll Array Assembly
**Source**: `web/posting.php:1274-1295`

A poll is only created when:
1. `poll_option_text` is non-empty
2. Mode is `post` OR (`edit` AND editing the first post)
3. User has `f_poll` permission in the forum

```php
$poll = array(
    'poll_title'        => $post_data['poll_title'],
    'poll_length'       => $post_data['poll_length'],
    'poll_max_options'  => $post_data['poll_max_options'],
    'poll_option_text'  => $post_data['poll_option_text'],
    'poll_start'        => $post_data['poll_start'],
    'poll_last_vote'    => $post_data['poll_last_vote'],
    'poll_vote_change'  => $post_data['poll_vote_change'],
    'enable_bbcode'     => $post_data['enable_bbcode'],
    'enable_urls'       => $post_data['enable_urls'],
    'enable_smilies'    => $post_data['enable_smilies'],
    'img_status'        => $img_status
);
$message_parser->parse_poll($poll);
```

`parse_poll()` splits `poll_option_text` by newlines into `poll['poll_options']` array and validates/parses BBCode in each option and the title.

#### Storage in submit_post() — New Post Mode
**Source**: `src/phpbb/common/functions_posting.php:1935-1955`

For `post` mode (new topic), if `poll_ary['poll_options']` is set and non-empty:

```php
$poll_start = ($poll_ary['poll_start']) ? $poll_ary['poll_start'] : $current_time;
$poll_length = $poll_ary['poll_length'] * 86400;  // Converts days → seconds
```

Merged into TOPICS_TABLE insert:
```php
'poll_title'        => $poll_ary['poll_title'],
'poll_start'        => $poll_start,
'poll_max_options'  => $poll_ary['poll_max_options'],
'poll_length'       => $poll_length,
'poll_vote_change'  => $poll_ary['poll_vote_change']
```

**Key detail**: `poll_length` is stored in **seconds** in DB but entered as **days** in UI. Conversion: `$poll_length * 86400`.

#### Storage in submit_post() — Edit Mode
**Source**: `src/phpbb/common/functions_posting.php:2004-2030`

For `edit_topic` / `edit_first_post`, poll fields are set to 0/empty if `poll_ary['poll_options']` is not set:

```php
'poll_title'        => (isset($poll_ary['poll_options'])) ? $poll_ary['poll_title'] : '',
'poll_start'        => (isset($poll_ary['poll_options'])) ? $poll_start : 0,
'poll_max_options'  => (isset($poll_ary['poll_options'])) ? $poll_ary['poll_max_options'] : 1,
'poll_length'       => (isset($poll_ary['poll_options'])) ? $poll_length : 0,
'poll_vote_change'  => (isset($poll_ary['poll_vote_change'])) ? $poll_ary['poll_vote_change'] : 0,
```

### 1.3 Poll Options CRUD
**Source**: `src/phpbb/common/functions_posting.php:2158-2210`

After the topic/post update, poll options are synced:

```php
if (isset($poll_ary['poll_options']))
{
    // In edit mode: fetch existing options
    $cur_poll_options = array();
    if ($mode == 'edit') {
        // SELECT * FROM POLL_OPTIONS_TABLE WHERE topic_id = ... ORDER BY poll_option_id
    }

    for ($i = 0; $i < count($poll_ary['poll_options']); $i++)
    {
        if (empty($cur_poll_options[$i])) {
            // INSERT — new option appended at end (id = count(cur) + 1 + count(inserts))
            $sql_insert_ary[] = array(
                'poll_option_id'   => (int) count($cur_poll_options) + 1 + count($sql_insert_ary),
                'topic_id'         => $data_ary['topic_id'],
                'poll_option_text' => $poll_ary['poll_options'][$i]
            );
        } else if ($poll_ary['poll_options'][$i] != $cur_poll_options[$i]) {
            // UPDATE — change text of existing option
        }
    }

    // Bulk insert new options
    $db->sql_multi_insert(POLL_OPTIONS_TABLE, $sql_insert_ary);

    // DELETE excess options if new count < old count
    if (count($poll_ary['poll_options']) < count($cur_poll_options)) {
        // DELETE FROM POLL_OPTIONS_TABLE WHERE poll_option_id > new_count AND topic_id = ...
    }

    // RESET VOTES if option count changed during edit
    if ($mode == 'edit' && count($poll_ary['poll_options']) != count($cur_poll_options)) {
        $db->sql_query('DELETE FROM ' . POLL_VOTES_TABLE . ' WHERE topic_id = ' . $data_ary['topic_id']);
        $db->sql_query('UPDATE ' . POLL_OPTIONS_TABLE . ' SET poll_option_total = 0 WHERE topic_id = ' . $data_ary['topic_id']);
    }
}
```

**Important**: Adding/removing options in edit mode **resets all votes** (because option IDs shift). Only text changes preserve votes.

### 1.4 Poll Deletion
**Source**: `web/posting.php:983-1008`

Triggered by `$_POST['poll_delete']` in edit mode:

```php
if ($poll_delete && $mode == 'edit' && count($post_data['poll_options']) &&
    ((!$post_data['poll_last_vote'] && $post_data['poster_id'] == $user->data['user_id'] && $auth->acl_get('f_delete', $forum_id))
     || $auth->acl_get('m_delete', $forum_id)))
{
    // DELETE FROM POLL_OPTIONS_TABLE WHERE topic_id = ...
    // DELETE FROM POLL_VOTES_TABLE WHERE topic_id = ...
    // UPDATE TOPICS_TABLE SET poll_title='', poll_start=0, poll_length=0,
    //     poll_last_vote=0, poll_max_options=0, poll_vote_change=0
}
```

**Permissions**: Author can delete if no votes cast yet (`!poll_last_vote`) + `f_delete` perm. Moderators can always delete (`m_delete`).

### 1.5 Voting
**Source**: `web/viewtopic.php:857-1080`

#### Can-Vote Logic
**Source**: `web/viewtopic.php:905-910`

```php
$s_can_vote = ($auth->acl_get('f_vote', $forum_id) &&
    (($topic_data['poll_length'] != 0 && $topic_data['poll_start'] + $topic_data['poll_length'] > time()) || $topic_data['poll_length'] == 0) &&
    $topic_data['topic_status'] != ITEM_LOCKED &&
    $topic_data['forum_status'] != ITEM_LOCKED &&
    (!count($cur_voted_id) ||
    ($auth->acl_get('f_votechg', $forum_id) && $topic_data['poll_vote_change']))) ? true : false;
```

User can vote if:
1. Has `f_vote` permission
2. Poll not expired (or no expiry)
3. Topic and forum not locked
4. Has not already voted, OR (`f_votechg` perm AND poll allows vote change)

#### Vote Change Process
**Source**: `web/viewtopic.php:958-1010`

When `$update && $s_can_vote`:

1. **Validation**: Check `count($voted_id) > 0`, `<= poll_max_options`, not a converted poll, form key valid
2. **Add new votes**: For each option in `$voted_id` not in `$cur_voted_id`:
   - `UPDATE poll_options SET poll_option_total = poll_option_total + 1`
   - `INSERT INTO poll_votes (topic_id, poll_option_id, vote_user_id, vote_user_ip)`
3. **Remove old votes**: For each option in `$cur_voted_id` not in `$voted_id`:
   - `UPDATE poll_options SET poll_option_total = poll_option_total - 1`
   - `DELETE FROM poll_votes WHERE topic_id AND poll_option_id AND vote_user_id`
4. **Guest tracking**: Cookie-based (`poll_{topic_id}` cookie, 1 year TTL)
5. **Update `poll_last_vote`**: `UPDATE topics SET poll_last_vote = time()`

#### AJAX Support
**Source**: `web/viewtopic.php:1037-1072`

Voting returns JSON response for AJAX requests:
```php
$data = array(
    'NO_VOTES'     => $user->lang['NO_VOTES'],
    'success'      => true,
    'user_votes'   => array_flip($valid_user_votes),
    'vote_counts'  => $vote_counts,
    'total_votes'  => array_sum($vote_counts),
    'can_vote'     => !count($valid_user_votes) || ($auth->acl_get('f_votechg', $forum_id) && $topic_data['poll_vote_change']),
);
```

### 1.6 Poll Display (viewtopic)
**Source**: `web/viewtopic.php:1080-1175`

Poll options loaded from `POLL_OPTIONS_TABLE` joined with first post (for BBCode UID):

```php
$sql = 'SELECT o.*, p.bbcode_bitfield, p.bbcode_uid
    FROM POLL_OPTIONS_TABLE o, POSTS_TABLE p
    WHERE o.topic_id = $topic_id
      AND p.post_id = {topic_first_post_id}
      AND p.topic_id = o.topic_id
    ORDER BY o.poll_option_id';
```

Each option produces template vars:
```php
'POLL_OPTION_ID'         => $poll_option['poll_option_id'],
'POLL_OPTION_CAPTION'    => $poll_option['poll_option_text'],  // parsed BBCode
'POLL_OPTION_RESULT'     => $poll_option['poll_option_total'],
'POLL_OPTION_PERCENT'    => $option_pct_txt,      // e.g. "45%"
'POLL_OPTION_PERCENT_REL'=> $option_pct_rel_txt,  // relative to most voted
'POLL_OPTION_PCT'        => round($option_pct * 100),
'POLL_OPTION_WIDTH'      => round($option_pct * 250),  // bar width in px
'POLL_OPTION_VOTED'      => in_array(option_id, $cur_voted_id),
'POLL_OPTION_MOST_VOTES' => $option_most_votes,
```

Overall poll template vars:
```php
'POLL_QUESTION'    => $topic_data['poll_title'],  // parsed BBCode
'TOTAL_VOTES'      => $poll_total,
'L_MAX_VOTES'      => "Select up to {poll_max_options} options",
'L_POLL_LENGTH'    => "Poll runs until {date}" or "Poll ended at {date}" or "",
'S_HAS_POLL'       => true,
'S_CAN_VOTE'       => $s_can_vote,
'S_DISPLAY_RESULTS'=> $s_display_results,
'S_IS_MULTI_CHOICE'=> $poll_max_options > 1,
```

Results shown when: user can't vote, OR user already voted, OR `?view=viewpoll`.

### 1.7 Poll Loading for Edit
**Source**: `web/posting.php:576-631`

When loading existing topic data for edit:
```php
$post_data['poll_length'] = $post_data['poll_length'] / 86400;  // seconds → days for UI
$post_data['poll_start']  = (int) $post_data['poll_start'];
$post_data['poll_options'] = array();

if ($post_data['poll_start']) {
    // SELECT poll_option_text FROM POLL_OPTIONS_TABLE WHERE topic_id ORDER BY poll_option_id
    // Populate $post_data['poll_options'][]
}
```

Original poll data is captured for comparison:
```php
$original_poll_data = array(
    'poll_title'        => $post_data['poll_title'],
    'poll_length'       => $post_data['poll_length'],
    'poll_max_options'  => $post_data['poll_max_options'],
    'poll_option_text'  => implode("\n", $post_data['poll_options']),
    'poll_start'        => $post_data['poll_start'],
    'poll_last_vote'    => $post_data['poll_last_vote'],
    'poll_vote_change'  => $post_data['poll_vote_change'],
);
```

---

## 2. DRAFTS

### 2.1 Database Schema
**Source**: `phpbb_dump.sql:1468-1478`

```sql
CREATE TABLE phpbb_drafts (
  draft_id       int(10) unsigned NOT NULL AUTO_INCREMENT,
  user_id        int(10) unsigned NOT NULL DEFAULT 0,   -- FK to phpbb_users
  topic_id       int(10) unsigned NOT NULL DEFAULT 0,   -- FK to phpbb_topics (0 = new topic or PM)
  forum_id       mediumint(8) unsigned NOT NULL DEFAULT 0, -- FK to phpbb_forums (0 = PM)
  save_time      int(11) unsigned NOT NULL DEFAULT 0,   -- Unix timestamp when saved
  draft_subject  varchar(255) NOT NULL DEFAULT '',       -- Subject line
  draft_message  mediumtext NOT NULL,                    -- Message body (parsed BBCode)
  PRIMARY KEY (draft_id),
  KEY save_time (save_time)
);
```

**Key observations**:
- No `poll_*` fields — poll data is NOT stored with drafts
- `topic_id = 0, forum_id > 0` → draft for a new topic
- `topic_id > 0` → draft for a reply
- `topic_id = 0, forum_id = 0` → PM draft
- `save_time` indexed — likely for cleanup queries

### 2.2 Draft Save
**Source**: `web/posting.php:786-895`

**Trigger**: Manual only — via `$_POST['save']` button. **No auto-save**.

```php
if ($save && $user->data['is_registered'] && $auth->acl_get('u_savedrafts') && ($mode == 'reply' || $mode == 'post' || $mode == 'quote'))
```

**Requirements to save**:
1. User is registered
2. Has `u_savedrafts` permission
3. Mode is `reply`, `post`, or `quote` (NOT `edit`)
4. Both subject and message must be non-empty

**Save flow**:
1. Read subject/message from POST
2. Encode 4-byte UTF-8 chars (emoji) to NCR hex
3. Present a **confirmation box** (CSRF protection via confirm_box)
4. Parse message (BBCode etc.)
5. INSERT into DRAFTS_TABLE:
   ```php
   $db->sql_build_array('INSERT', array(
       'user_id'        => (int) $user->data['user_id'],
       'topic_id'       => (int) $topic_id,
       'forum_id'       => (int) $forum_id,
       'save_time'      => (int) $current_time,
       'draft_subject'  => (string) $subject,
       'draft_message'  => (string) $message_parser->message  // parsed BBCode
   ))
   ```
6. Delete orphan attachments
7. Redirect to forum/topic view

**Important**: The message is stored as **parsed BBCode** (`$message_parser->message`), not raw text.

### 2.3 Draft Load
**Source**: `web/posting.php:904-930`

**Automatic loading** when `draft_id` (`d` parameter) is present in URL:

```php
if ($draft_id && ($mode == 'reply' || $mode == 'quote' || $mode == 'post') && $user->data['is_registered'] && $auth->acl_get('u_savedrafts'))
{
    $sql = 'SELECT draft_subject, draft_message
        FROM ' . DRAFTS_TABLE . "
        WHERE draft_id = $draft_id
            AND user_id = " . $user->data['user_id'];
    // ...
    $post_data['post_subject'] = $row['draft_subject'];
    $message_parser->message = $row['draft_message'];
    $template->assign_var('S_DRAFT_LOADED', true);
}
```

**Security**: Draft is loaded only if `user_id` matches current user.

### 2.4 Draft Listing / Overview
**Source**: `web/posting.php:924-930`

When user clicks "Load Draft" button (`$_POST['load']`):

```php
if ($load && ($mode == 'reply' || $mode == 'quote' || $mode == 'post') && $post_data['drafts'])
{
    load_drafts($topic_id, $forum_id);
}
```

### 2.5 load_drafts() Function
**Source**: `src/phpbb/common/functions_posting.php:936-1090`

```php
function load_drafts($topic_id = 0, $forum_id = 0, $id = 0, $pm_action = '', $msg_id = 0)
```

**Query**: Joins DRAFTS_TABLE with FORUMS_TABLE, filtered by user_id and optionally by forum_id/topic_id. Sorted by `save_time DESC`.

**Scope detection**:
- `topic_id = 0 AND forum_id = 0` → PM drafts only
- Otherwise → filter by forum/topic

**For each draft**, generates an "Insert URL" that links back to posting.php with the draft_id:
- Draft for topic reply: `posting.php?t={topic_id}&mode=reply&d={draft_id}`
- Draft for new topic: `posting.php?f={forum_id}&mode=post&d={draft_id}`
- PM draft: `ucp.php?i={id}&mode=compose&d={draft_id}`

Template block: `draftrow` with `DRAFT_ID`, `DATE`, `DRAFT_SUBJECT`, `TITLE`, `U_VIEW`, `U_INSERT`, `S_LINK_PM/TOPIC/FORUM`.

### 2.6 Draft Existence Check
**Source**: `web/posting.php:718-731`

Before entering the main form logic, phpBB checks if user has drafts for the current context:

```php
if ($user->data['is_registered'] && $auth->acl_get('u_savedrafts') && ($mode == 'reply' || $mode == 'post' || $mode == 'quote'))
{
    $sql = 'SELECT draft_id FROM DRAFTS_TABLE
        WHERE user_id = {user_id}' .
        (forum_id filter) . (topic_id filter) . (exclude current draft_id);
    // Sets $post_data['drafts'] = true if results exist
}
```

This determines the `S_HAS_DRAFTS` template variable.

### 2.7 Draft Deletion After Post
**Source**: `src/phpbb/common/functions_posting.php:2399-2405`

After a post is successfully submitted, if a draft was loaded:

```php
$draft_id = $request->variable('draft_loaded', 0);
if ($draft_id) {
    $sql = 'DELETE FROM DRAFTS_TABLE
        WHERE draft_id = $draft_id
            AND user_id = {user_id}';
    $db->sql_query($sql);
}
```

The `draft_loaded` hidden field is set in `web/posting.php:1919`:
```php
$s_hidden_fields .= ($draft_id || isset($_REQUEST['draft_loaded'])) ?
    '<input type="hidden" name="draft_loaded" value="' . $request->variable('draft_loaded', $draft_id) . '" />' : '';
```

### 2.8 Draft Cleanup / Purging

**No automatic purge mechanism found** in the codebase for drafts. There is no cron job or scheduled task that deletes old drafts. The `save_time` index on the table suggests this was intended but not implemented (or handled by external cleanup).

Drafts are only deleted:
1. When the draft is loaded and the post is submitted (see 2.7)
2. Manually by the user through UCP (not investigated here)

### 2.9 Draft Scope
**Source**: Schema + `web/posting.php:787`

Drafts can be saved for:
- **New topics** (`mode == 'post'`) — `forum_id` set, `topic_id = 0`
- **Replies** (`mode == 'reply'`) — both `forum_id` and `topic_id` set
- **Quotes** (`mode == 'quote'`) — treated same as reply
- **PMs** — `forum_id = 0, topic_id = 0` (handled in ucp.php, not investigated here)

Draft save is **NOT available** in `edit` mode:
```php
'S_SAVE_ALLOWED' => ($auth->acl_get('u_savedrafts') && $user->data['is_registered'] && $mode != 'edit')
```
**Source**: `web/posting.php:1984`

---

## 3. Summary of Key Design Decisions

### Polls
| Aspect | Implementation |
|--------|---------------|
| **Storage** | Metadata on `phpbb_topics`, options in `phpbb_poll_options`, votes in `phpbb_poll_votes` |
| **Duration** | Stored in seconds, UI input in days, conversion: `days * 86400` |
| **Vote changes** | Requires both `poll_vote_change` on topic AND `f_votechg` ACL permission |
| **Option editing** | Adding/removing options resets all votes; text-only changes preserve votes |
| **Guest voting** | Cookie-based tracking (1 year TTL), no DB record |
| **BBCode** | Both poll title and options support BBCode (parsed using first post's bbcode_uid) |
| **Max options** | tinyint(4) = max 127 options per poll |
| **AJAX voting** | Supported — returns JSON with updated counts and vote status |

### Drafts
| Aspect | Implementation |
|--------|---------------|
| **Storage** | Single table `phpbb_drafts`, 7 columns |
| **Save trigger** | Manual button only, no auto-save |
| **Message format** | Stored as parsed BBCode |
| **Scope** | Topics, replies, quotes, PMs — NOT edits |
| **Confirmation** | Uses `confirm_box()` before saving |
| **Cleanup** | Only deleted on successful post; no automated purge |
| **Permission** | `u_savedrafts` ACL required |
| **Poll data** | NOT included in drafts |
