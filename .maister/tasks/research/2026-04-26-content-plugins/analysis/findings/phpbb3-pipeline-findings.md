# phpBB3 Content Processing Pipeline — Findings

**Source category**: `phpbb3-pipeline`
**Evidence base**: Live codebase at `src/phpbb3/`, SQL dump at `phpbb_dump.sql`, constants at `src/phpbb3/common/constants.php`

---

## 1. Two-Stage Pipeline Overview

phpBB3 uses an explicit two-stage text processing model.

### Stage 1 — `generate_text_for_storage()` (pre-save)

**Source**: `src/phpbb3/common/functions_content.php:711–793`

```php
function generate_text_for_storage(
    &$text, &$uid, &$bitfield, &$flags,
    $allow_bbcode = false, $allow_urls = false, $allow_smilies = false,
    $allow_img_bbcode = true, $allow_flash_bbcode = true,
    $allow_quote_bbcode = true, $allow_url_bbcode = true,
    $mode = 'post'
)
```

What it does:
- Instantiates `parse_message` and calls `$message_parser->parse(...)`.
- Strips/transforms BBCode tags, replacing them with `[b:UID]...[/b:UID]` format, where UID is an 8-char random string.
- Returns three output values (by reference): `$uid`, `$bitfield`, `$flags`.
- Computes `$flags` as a bitmask of the *allow_** settings (not from actual content):
  ```php
  $flags = (($allow_bbcode) ? OPTION_FLAG_BBCODE : 0)
         + (($allow_smilies) ? OPTION_FLAG_SMILIES : 0)
         + (($allow_urls) ? OPTION_FLAG_LINKS : 0);
  ```
- Fires Symfony events `core.modify_text_for_storage_before` and `core.modify_text_for_storage_after` for extension points.
- Outputs the **intermediate text** (UID-tagged BBCode or s9e XML) — never raw HTML — for storage.

**Key result**: smilies are stored as `<!-- s:-) --><img src="{SMILIES_PATH}/..." /><!-- s:-) -->` placeholders in the old pipeline. Magic URL links are stored as `<!-- l --><a href="...">...</a><!-- l -->` markers.

### Stage 2 — `generate_text_for_display()` (pre-output)

**Source**: `src/phpbb3/common/functions_content.php:587–690`

```php
function generate_text_for_display($text, $uid, $bitfield, $flags, $censor_text = true)
```

What it does:
1. **s9e path** (if text starts with `<r` or `<t`):
   - Calls `$renderer->render($text)` — converts s9e XML → HTML.
   - Censor is handled by the renderer's `set_viewcensors()` toggle.
2. **Legacy path**:
   - Applies `censor_text($text)` first (word replacement from `phpbb_words`).
   - If `$uid` set and `$flags & OPTION_FLAG_BBCODE`: instantiates `bbcode` class, calls `bbcode_second_pass($text, $uid)` — replaces `[b:UID]` tags with HTML.
   - Calls `bbcode_nl2br($text)` for newline handling.
   - Calls `smiley_text($text, !($flags & OPTION_FLAG_SMILIES))` — replaces smiley placeholders with real `<img>` tags (or strips them to plain text if smilies disabled for user).

Fires events `core.modify_text_for_display_before` and `core.modify_text_for_display_after`.

### Stage 2b — `generate_text_for_edit()` (pre-edit form)

**Source**: `src/phpbb3/common/functions_content.php:796–835`

Calls `decode_message($text, $uid)` which strips UID suffixes and converts BBCode back to plain bracket syntax. Returns array with `allow_bbcode`, `allow_smilies`, `allow_urls` derived from the stored `$flags`.

---

## 2. What the Stored Columns Contain

Schema from `phpbb_dump.sql:2694–2735` (`phpbb_posts` table):

```sql
`post_text`        mediumtext NOT NULL,            -- the intermediate representation
`bbcode_uid`       varchar(8) NOT NULL DEFAULT '',  -- 8-char random suffix, e.g. "3g7k2a1b"
`bbcode_bitfield`  varchar(255) NOT NULL DEFAULT '', -- base64 bitmask of BBCodes present
`enable_bbcode`    tinyint(1) unsigned NOT NULL DEFAULT 1,  -- user toggled BBCode on
`enable_smilies`   tinyint(1) unsigned NOT NULL DEFAULT 1,  -- user toggled smilies on
`enable_magic_url` tinyint(1) unsigned NOT NULL DEFAULT 1,  -- user toggled magic URLs on
```

### `post_bbcode_uid` (= `bbcode_uid`)

- An 8-character alphanumeric string generated uniquely per post at parse time.
- Appended to every opening/closing BBCode tag in `post_text`, e.g. `[b:3g7k2a1b]bold[/b:3g7k2a1b]`.
- Purpose: prevents stale tags from being re-parsed if the parser's tag set changes.
- If no BBCodes were used, both `uid` and `bitfield` are stored as empty string.
  - **Source**: `functions_content.php:766–771`:
    ```php
    // If the bbcode_bitfield is empty, there is no need for the uid to be stored.
    if (!$message_parser->bbcode_bitfield)
    {
        $uid = '';
    }
    ```

### `post_bbcode_bitfield` (= `bbcode_bitfield`)

- Base64-encoded bitmask indicating *which* BBCodes appear in the post text.
- Each bit position = one BBCode ID from `phpbb_bbcodes` table.
- Used at display time so the renderer only instantiates handlers for BBCodes actually present.
- Empty string = no BBCodes present; in that case `uid` is also cleared.

### `enable_bbcode` / `enable_smilies` / `enable_magic_url`

- Boolean columns (tinyint 0/1).
- Stored per-post; reflect the **user's choice** at post time (toggles in the post form).
- Compiled into the `flags` integer for `generate_text_for_display`:
  ```php
  // From feed/controller/feed.php:354
  $options = (($row['enable_bbcode']) ? OPTION_FLAG_BBCODE : 0)
           + (($row['enable_smilies'])  ? OPTION_FLAG_SMILIES : 0)
           + (($row['enable_magic_url'])? OPTION_FLAG_LINKS : 0);
  ```
- There is **no single `bbcode_options` column** in phpBB3; the flags are three separate columns.

### OPTION_FLAG constants

**Source**: `src/phpbb3/common/constants.php:110–112`

```php
define('OPTION_FLAG_BBCODE',  1);
define('OPTION_FLAG_SMILIES', 2);
define('OPTION_FLAG_LINKS',   4);
```

---

## 3. Smilies: Storage vs Display

### How smilies are stored (legacy pipeline)

At parse time (`generate_text_for_storage`), the message parser looks up the `phpbb_smilies` table and replaces text codes (e.g., `:-)`) with a placeholder:

```
<!-- s:-) --><img src="{SMILIES_PATH}/icon_e_smile.gif" width="15" height="17" alt=":-)" title="Smile" /><!-- s:-) -->
```

The `{SMILIES_PATH}` token is a literal placeholder — **not** a resolved URL. The actual image path is substituted at display time.

### How smilies are displayed (legacy pipeline)

**Source**: `src/phpbb3/common/functions_content.php:1123–1151`

```php
function smiley_text($text, $force_option = false)
{
    if ($force_option || !$config['allow_smilies'] || !$user->optionget('viewsmilies'))
    {
        // Strip: replace with the alt text only
        return preg_replace('#<!-- s(.*?) --><img ...><!-- s\1 -->#', '\1', $text);
    }
    else
    {
        // Expand: replace {SMILIES_PATH} with real root path
        return preg_replace(..., '<img class="smilies" src="' . $root_path . $config['smilies_path'] . '/\2 />', $text);
    }
}
```

**Conclusion**: Smilies are **partly storage, partly display**.
- The *code-to-placeholder* transformation happens at storage time.
- The *placeholder-to-HTML* (or placeholder-to-text) transformation happens at display time.
- The *user viewsmilies preference* is checked at display time, not storage time.

### How smilies are stored (s9e / new pipeline)

In phpBB 3.1+/3.2+, the s9e textformatter parser stores smilies inside the XML stored representation (`<E>:D</E>` wrapped in an `<EMOJI>` or smiley tag). The renderer's XSLT template conditionally outputs an `<img>` or plain text based on the `$S_VIEWSMILIES` XSL parameter.

**Source**: `src/phpbb3/forums/textformatter/s9e/renderer.php:59,137–164`

```php
$this->set_viewsmilies($user->optionget('viewsmilies'));
```

---

## 4. Word Censor: Display-Only

### How censor works

**Source**: `src/phpbb3/common/functions_content.php:1071–1110`

```php
function censor_text($text)
{
    static $censors;
    // ...
    $censors = $cache->obtain_word_list();
    // ...
    return preg_replace($censors['match'], $censors['replace'], $text);
}
```

The word list comes from `phpbb_words` table:

```sql
-- phpbb_dump.sql:4177
CREATE TABLE `phpbb_words` (
  `word_id`     int(10) unsigned NOT NULL AUTO_INCREMENT,
  `word`        varchar(255) NOT NULL DEFAULT '',
  `replacement` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`word_id`)
);
```

Words are loaded into cache as regex patterns (`get_censor_preg_expression()`).

**Source**: `src/phpbb3/forums/cache/service.php:135–151`

```php
$censors['match'][]   = get_censor_preg_expression($row['word']);
$censors['replace'][] = $row['replacement'];
$this->driver->put('_word_censors', $censors);
```

### Where censor is applied

- `generate_text_for_display()` — main display path.
- `smiley_text` / notification subjects in legacy `censor_text()` calls.
- Feed controller: `censor_text($this->feed_helper->generate_content(...))`.
- Never applied in `generate_text_for_storage()`.

Users with `u_chgcensors` permission and `allow_nocensors` config can opt out:

```php
// renderer.php:159
$censor = $user->optionget('viewcensors')
    || !$config['allow_nocensors']
    || !$auth->acl_get('u_chgcensors');
$this->set_viewcensors($censor);
```

**Conclusion**: Censor is **display-only**. The raw word is always stored. This is by design — if the word list changes, old posts automatically get the new replacement on next render.

---

## 5. Stage Boundary Summary

| Transform | Where applied | Storage impact |
|---|---|---|
| BBCode tag parsing → UID-tagged intermediate | `generate_text_for_storage` | Yes — stored |
| Magic URL detection and wrapping | `generate_text_for_storage` | Yes — markers stored |
| Smiley code → `{SMILIES_PATH}` placeholder | `generate_text_for_storage` | Yes — placeholder stored |
| BBCode rendering (UID-tags → HTML) | `generate_text_for_display` | No — ephemeral |
| Magic URL rendering (markers → `<a>` tags) | `generate_text_for_display` | No — ephemeral |
| Smiley placeholder → `<img>` (or plain text) | `generate_text_for_display` / `smiley_text()` | No — ephemeral |
| Word censor | `generate_text_for_display` / `censor_text()` | **Never stored** |
| `decode_message` (UID-tags → plain BBCode) | `generate_text_for_edit` | No — ephemeral |

---

## 6. Implications for phpBB4 REST API Design

### Raw vs Rendered content in API responses

The phpBB3 model exposes this distinction cleanly: `post_text` is the stored intermediate, and the final HTML is rendered on demand.

For a phpBB4 REST API:

1. **Raw endpoint** (`?format=raw` or `Accept: text/x-bbcode`): output `post_text` directly. Useful for editors and migration tools. Equivalent to `generate_text_for_edit()`.

2. **Rendered endpoint** (default `Accept: text/html`): apply the full display pipeline server-side. Equivalent to `generate_text_for_display()`.

3. **Censor**: must always be applied on the server side for rendered responses. The API must not expose uncensored text to clients without a permission check (`u_chgcensors`).

4. **Smilies**: the `enable_smilies` flag from the post row should be passed to the renderer. Smily expansion (placeholder → `<img>`) should happen server-side for HTML responses. For raw/BBCode responses, `<!-- s... -->` placeholders should either be stripped to text codes or documented clearly.

5. **Bitfield/UID in API response**: `bbcode_uid` and `bbcode_bitfield` are internal implementation details. They should **not** appear in public API responses. The transformation must be absorbed into the server-side pipeline transparently.

6. **Content-Type negotiation**: Because censor and smilies are display-time concerns, caching strategy must be per-user (or at least per user-options hash) if caching rendered HTML responses.

### No phpBB4 intermediate storage equivalent yet

The research shows no `phpbb\` namespace equivalent of `generate_text_for_storage` / `generate_text_for_display` in `src/` (outside the phpbb3 legacy directory). The content pipeline is a key open design question for phpBB4.

---

## 7. Evidence File Index

| File | Purpose |
|---|---|
| `src/phpbb3/common/functions_content.php` | Core pipeline functions |
| `src/phpbb3/common/constants.php:110–112` | OPTION_FLAG constants |
| `src/phpbb3/forums/textformatter/s9e/factory.php` | s9e textformatter factory (smilies, bbcodes) |
| `src/phpbb3/forums/textformatter/s9e/parser.php` | s9e parse → XML for storage |
| `src/phpbb3/forums/textformatter/s9e/renderer.php` | s9e XML → HTML (viewcensors, viewsmilies) |
| `src/phpbb3/forums/textreparser/base.php` | Migration reparser (decode → re-encode cycle) |
| `src/phpbb3/forums/cache/service.php:135–151` | Word censors cache loading |
| `phpbb_dump.sql:2694–2735` | phpbb_posts schema |
| `phpbb_dump.sql:3343–3360` | phpbb_smilies schema |
| `phpbb_dump.sql:4177–4185` | phpbb_words schema |
