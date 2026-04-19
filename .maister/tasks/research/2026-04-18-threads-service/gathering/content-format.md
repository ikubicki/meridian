# Content Format & Text Processing Pipeline

## 1. Storage Format

### Database Schema — `phpbb_posts` table
**Source**: `phpbb_dump.sql:2694-2733`

Text-related columns in `phpbb_posts`:
```sql
`post_text`       mediumtext NOT NULL,        -- The parsed/stored post content
`bbcode_bitfield` varchar(255) NOT NULL DEFAULT '',  -- Which BBCodes are used
`bbcode_uid`      varchar(8)  NOT NULL DEFAULT '',   -- Unique ID for this parse session
`enable_bbcode`   tinyint(1)  NOT NULL DEFAULT 1,    -- Per-post toggle
`enable_smilies`  tinyint(1)  NOT NULL DEFAULT 1,    -- Per-post toggle
`enable_magic_url` tinyint(1) NOT NULL DEFAULT 1,    -- Per-post toggle
`enable_sig`      tinyint(1)  NOT NULL DEFAULT 1,    -- Show signature?
```

The same schema is duplicated for private messages in `phpbb_privmsgs` (`phpbb_dump.sql:2754-2783`), using `message_text` instead of `post_text`.

### What is actually stored in `post_text`?

**Two distinct formats coexist** depending on when text was parsed:

#### Format 1: s9e XML (modern, phpBB 3.2+)
Text is stored as **s9e TextFormatter XML**. The root element indicates the type:
- `<t>` — "plain text" (no rich markup needed, just escaped text)
- `<r>` — "rich text" (contains parsed BBCode/emojis/links as XML tags)

**Example from DB** (`phpbb_dump.sql:2742`):
```xml
<t>This is an example post in your phpBB3 installation. Everything seems to be working...</t>
```

A richer example would look like:
```xml
<r><B><s>[b]</s>Bold text<e>[/b]</e></B> and <URL url="https://example.com"><s>[url]</s>https://example.com<e>[/url]</e></URL></r>
```

The `<s>` and `<e>` elements preserve the original BBCode start/end tags for round-tripping back to edit mode.

**Detection**: `preg_match('#^<[rt][ >]#', $text)` is used everywhere to detect s9e XML format.
**Source**: `src/phpbb/common/functions_content.php:631`, `src/phpbb/common/message_parser.php:1357`

#### Format 2: Legacy BBCode with UID (phpBB 3.0/3.1)
Older content has BBCode tags annotated with a unique UID:
```
[b:abc12345]Bold text[/b:abc12345]
```
With `<br />` for newlines and HTML-entity-encoded special chars.

### Key Insight for New Design
The s9e format is a **parsed intermediate XML** — not raw text, not final HTML. This is the canonical storage format in modern phpBB. The legacy format with `:uid` tags is only kept for backward compatibility (text reparser can convert it).

---

## 2. BBCode UID

**Source**: `src/phpbb/common/message_parser.php:1120`, `src/phpbb/common/constants.php:173`

```php
// parse_message constructor (message_parser.php:1120)
$this->bbcode_uid = substr(base_convert(unique_id(), 16, 36), 0, BBCODE_UID_LEN);
```

```php
// constants.php:173
define('BBCODE_UID_LEN', 8);
```

**What it is**: An 8-character random alphanumeric string generated per parse session.

**Purpose**: In the **legacy format**, the UID is appended to every BBCode tag to create unique markers. This prevents:
- Confusion between user-typed `[b]` and actual BBCode tags
- Conflicts between nested BBCodes
- False matches during second-pass rendering

**Example transformation**:
```
User input:    [b]Hello[/b]
First pass:    [b:x1y2z3ab]Hello[/b:x1y2z3ab]
Stored in DB:  [b:x1y2z3ab]Hello[/b:x1y2z3ab]
Second pass:   <span style="font-weight: bold">Hello</span>
```

**In s9e format**: The UID is **not used** — s9e has its own XML-based format. However, `bbcode_uid` is still stored in the DB for backward compatibility and is still populated during `generate_text_for_storage()` (even though s9e ignores it).

**Source for UID injection into tags**: `src/phpbb/common/message_parser.php:320-321` (bbcode_size as example):
```php
return '[size=' . $stx . ':' . $this->bbcode_uid . ']' . $in . '[/size:' . $this->bbcode_uid . ']';
```

---

## 3. BBCode Bitfield

**Source**: `src/phpbb/common/message_parser.php:58-97`

The bitfield is a **base64-encoded bitmask** that tracks which BBCodes are used in a post. Each built-in BBCode has an assigned bit position:

```php
// constants.php:180-192
define('BBCODE_ID_QUOTE',  0);
define('BBCODE_ID_B',      1);
define('BBCODE_ID_I',      2);
define('BBCODE_ID_URL',    3);
define('BBCODE_ID_IMG',    4);
define('BBCODE_ID_SIZE',   5);
define('BBCODE_ID_COLOR',  6);
define('BBCODE_ID_U',      7);
define('BBCODE_ID_CODE',   8);
define('BBCODE_ID_LIST',   9);
define('BBCODE_ID_EMAIL',  10);
define('BBCODE_ID_FLASH',  11);
define('BBCODE_ID_ATTACH', 12);
```

Custom BBCodes get IDs > 12 from the `bbcodes` table.

**How it's built** (`message_parser.php:64-92`):
```php
function parse_bbcode()
{
    $this->bbcode_bitfield = '';
    $bitfield = new bitfield();
    
    foreach ($this->bbcodes as $bbcode_name => $bbcode_data)
    {
        foreach ($bbcode_data['regexp'] as $regexp => $replacement)
        {
            if (preg_match($regexp, $this->message))
            {
                // ... apply replacement ...
                $bitfield->set($bbcode_data['bbcode_id']);
            }
        }
    }
    
    $this->bbcode_bitfield = $bitfield->get_base64();
}
```

**Purpose during rendering**: The bitfield allows the renderer to **only load templates for BBCodes actually used** in a post, instead of loading all templates. This is a performance optimization.

**In legacy second-pass rendering** (`src/phpbb/common/bbcode.php:68-100`):
```php
function bbcode_second_pass(&$message, $bbcode_uid = '', $bbcode_bitfield = false)
{
    $bitfield = new bitfield($this->bbcode_bitfield);
    $bbcodes_set = $bitfield->get_all_set();
    
    foreach ($bbcodes_set as $bbcode_id)
    {
        // Only process BBCodes whose bit is set
        foreach ($this->bbcode_cache[$bbcode_id] as $type => $array) { ... }
    }
}
```

---

## 4. Enable Flags

**Source**: `src/phpbb/common/constants.php:110-112`, `src/phpbb/common/functions_content.php:753`

```php
define('OPTION_FLAG_BBCODE',  1);  // Bit 0
define('OPTION_FLAG_SMILIES', 2);  // Bit 1
define('OPTION_FLAG_LINKS',   4);  // Bit 2
```

These are compiled into a single integer stored with the text:
```php
$flags = (($allow_bbcode) ? OPTION_FLAG_BBCODE : 0)
       + (($allow_smilies) ? OPTION_FLAG_SMILIES : 0)
       + (($allow_urls) ? OPTION_FLAG_LINKS : 0);
```

**Per-post DB columns** control which features WERE enabled when text was parsed:
- `enable_bbcode` — whether BBCode parsing was active
- `enable_smilies` — whether smiley replacement was active
- `enable_magic_url` — whether auto-linking was active
- `enable_sig` — whether to show user signature (display-time only, not text processing)

These are checked both at **parse time** (to configure the parser) and at **display time** (in legacy rendering path to decide which second-pass operations to apply).

---

## 5. Parse Pipeline (User Input → DB Storage)

### Entry Points

Two main entry points:

#### A. `generate_text_for_storage()` — Simple API
**Source**: `src/phpbb/common/functions_content.php:711-795`

Used for forum descriptions, group descriptions, profile fields etc.:
```php
function generate_text_for_storage(&$text, &$uid, &$bitfield, &$flags,
    $allow_bbcode, $allow_urls, $allow_smilies, ...)
{
    // 1. Event: core.modify_text_for_storage_before
    
    // 2. Compute flags bitmask
    $flags = (OPTION_FLAG_BBCODE * $allow_bbcode) + ...;
    
    // 3. Create parser and parse
    $message_parser = new parse_message($text);
    $message_parser->parse($allow_bbcode, $allow_urls, $allow_smilies, ...);
    
    // 4. Extract results
    $text = $message_parser->message;      // s9e XML
    $uid = $message_parser->bbcode_uid;    // 8-char UID
    $bitfield = $message_parser->bbcode_bitfield;  // base64 bitmask
    
    // 5. Event: core.modify_text_for_storage_after
}
```

#### B. `parse_message::parse()` — Full API
**Source**: `src/phpbb/common/message_parser.php:1127-1293`

Used by posting.php for posts/topics. Steps:

```
1. Length validation (min/max chars based on mode: 'post', 'sig', 'pm')
2. Event: core.message_parser_check_message
3. Get text_formatter.parser service (s9e parser)
4. Configure parser:
   - Enable/disable BBCodes, magic URLs, smilies
   - Enable/disable specific BBCodes (img, flash, quote, url)
   - Set limits (max_font_size, max_img_height, max_img_width, max_smilies, max_urls)
5. Parse: $this->message = $parser->parse(html_entity_decode($this->message))
   → Returns s9e XML (the parsed intermediate format)
6. Remove deeply-nested quotes (if max_quote_depth > 0)
7. Check for empty message post-parse
8. Collect parser errors → warn_msg
```

### s9e Parser Internals
**Source**: `src/phpbb/forums/textformatter/s9e/parser.php:76-105`

```php
public function parse($text)
{
    // Event: core.text_formatter_s9e_parse_before (modify text before parsing)
    $xml = $this->parser->parse($text);
    // Event: core.text_formatter_s9e_parse_after (modify parsed XML)
    return $xml;
}
```

The s9e library handles all transformations internally:
- BBCode matching and conversion to XML tags
- Smiley/emoticon matching → `<E>` tags
- Auto-linking URLs → `<URL>` tags via Autolink plugin
- Auto-linking emails → via Autoemail plugin
- Emoji conversion → with Twemoji SVG rendering
- Word censoring → via Censor plugin (stored as separate helper, not in XML)

### What the s9e Parser Produces

For input: `Hello [b]world[/b] :) https://example.com`

Output XML would be approximately:
```xml
<r>Hello <B><s>[b]</s>world<e>[/b]</e></B> <E>:)</E> <URL url="https://example.com"><LINK_TEXT text="https://example.com">https://example.com</LINK_TEXT></URL></r>
```

---

## 6. Render Pipeline (DB → Display HTML)

### Entry Point: `generate_text_for_display()`
**Source**: `src/phpbb/common/functions_content.php:587-685`

```php
function generate_text_for_display($text, $uid, $bitfield, $flags, $censor_text = true)
{
    // Event: core.modify_text_for_display_before
    
    if (preg_match('#^<[rt][ >]#', $text))
    {
        // ========== s9e FORMAT ==========
        $renderer = $phpbb_container->get('text_formatter.renderer');
        
        // Configure censoring based on user prefs
        $renderer->set_viewcensors($censor_text);
        
        // Render XML → HTML
        $text = $renderer->render($text);
    }
    else
    {
        // ========== LEGACY FORMAT ==========
        // 1. Apply word censoring
        $text = censor_text($text);
        
        // 2. Parse BBCodes (second pass: UID-tagged BBCode → HTML)
        if ($uid && ($flags & OPTION_FLAG_BBCODE))
        {
            $bbcode = new bbcode($bitfield);
            $bbcode->bbcode_second_pass($text, $uid);
        }
        
        // 3. Convert newlines to <br>
        $text = bbcode_nl2br($text);
        
        // 4. Convert smiley markers to <img> tags
        $text = smiley_text($text, !($flags & OPTION_FLAG_SMILIES));
    }
    
    // Event: core.modify_text_for_display_after
    return $text;
}
```

### s9e Renderer Internals
**Source**: `src/phpbb/forums/textformatter/s9e/renderer.php:229-272`

```php
public function render($xml)
{
    // 1. Inject dynamic quote metadata (author links, dates)
    if (isset($this->quote_helper))
        $xml = $this->quote_helper->inject_metadata($xml);
    
    // 2. Event: core.text_formatter_s9e_render_before
    
    // 3. Render XML → HTML using cached PHP renderer class
    $html = $this->renderer->render($xml);
    
    // 4. Apply censoring to HTML output
    if (isset($this->censor) && $this->viewcensors)
        $html = $this->censor->censorHtml($html, true);
    
    // 5. Event: core.text_formatter_s9e_render_after
    return $html;
}
```

### Renderer Configuration
The renderer respects per-user display preferences:
```php
// renderer.php — configure_user()
$this->set_viewcensors($censor);
$this->set_viewflash($user->optionget('viewflash'));
$this->set_viewimg($user->optionget('viewimg'));
$this->set_viewsmilies($user->optionget('viewsmilies'));
```

These translate to XSL parameters (`$S_VIEWIMG`, `$S_VIEWSMILIES`, `$S_VIEWFLASH`, `$S_VIEWCENSORS`) that control conditional rendering in XSL templates.

---

## 7. s9e TextFormatter vs Legacy BBCode

### Architecture

**s9e TextFormatter** is the **primary and active** text processing system since phpBB 3.2. The legacy BBCode classes are retained only for backward compatibility with content stored in the old UID-based format.

| Aspect | s9e TextFormatter | Legacy BBCode |
|--------|-------------------|---------------|
| **Storage format** | XML (`<r>...</r>` or `<t>...</t>`) | BBCode with UID `:uid` suffixes |
| **Parse** | `parser_interface::parse()` → XML | `bbcode_firstpass::parse_bbcode()` → UID-tagged BBCode |
| **Render** | `renderer_interface::render()` → HTML | `bbcode::bbcode_second_pass()` → HTML |
| **Configuration** | `factory::get_configurator()` builds s9e Configurator | Hardcoded regex arrays in `bbcode_init()` |
| **Detection** | `preg_match('#^<[rt][ >]#', $text)` | Anything that doesn't match s9e |
| **Extension** | Plugins (BBCodes, Autolink, Emoticons, Emoji, Censor) | Custom BBCode table + regex |

### Service Container Registration
The s9e system is registered as services:
- `text_formatter.parser` → `\phpbb\textformatter\s9e\parser`
- `text_formatter.renderer` → `\phpbb\textformatter\s9e\renderer`
- `text_formatter.utils` → `\phpbb\textformatter\s9e\utils`

### Factory & Caching
**Source**: `src/phpbb/forums/textformatter/s9e/factory.php`

The factory:
1. Creates an s9e `Configurator` object
2. Registers default BBCodes with definitions and XSL templates
3. Loads custom BBCodes from DB (`data_access::get_bbcodes()`)
4. Loads smilies from DB (`data_access::get_smilies()`)
5. Loads censored words from DB (`data_access::get_censored_words()`)
6. Configures Autolink/Autoemail plugins
7. Configures Emoji plugin with Twemoji
8. Compiles everything into an s9e Parser and Renderer
9. Caches both (renderer as a generated PHP class in cache dir)

---

## 8. Smilies Handling

### In s9e Pipeline
**Source**: `src/phpbb/forums/textformatter/s9e/factory.php:318-338`

Smilies are loaded from the DB and registered with s9e's Emoticons plugin:
```php
foreach ($this->data_access->get_smilies() as $row)
{
    $configurator->Emoticons->set(
        $row['code'],  // e.g. ':)'
        '<img class="smilies" src="{$T_SMILIES_PATH}/' . $row['smiley_url'] . '" 
              width="' . $row['smiley_width'] . '" height="' . $row['smiley_height'] . '" 
              alt="{.}" title="' . $row['emotion'] . '"/>'
    );
}

// Conditional rendering
$configurator->Emoticons->notIfCondition = 'not($S_VIEWSMILIES)';

// Only parse after: start of text, newline, space, dot, or ]
$configurator->Emoticons->notAfter = '[^\\n .\\]]';

// Don't parse if immediately followed by a word character
$configurator->Emoticons->notBefore = '\\w';
```

**Runtime**: The renderer's `$S_VIEWSMILIES` parameter controls whether smilies are rendered as images or kept as text.

### In Legacy Pipeline
**Source**: `src/phpbb/common/message_parser.php:1478-1510`

Smilies are wrapped in HTML comment markers:
```php
$replace[] = '<!-- s' . $row['code'] . ' --><img src="{SMILIES_PATH}/' . $row['smiley_url'] . '" ... /><!-- s' . $row['code'] . ' -->';
```

---

## 9. Magic URLs (Auto-linking)

### In s9e Pipeline
**Source**: `src/phpbb/forums/textformatter/s9e/factory.php:475-508`

Two s9e plugins handle auto-linking:
- **Autoemail** — detects email addresses
- **Autolink** — detects URLs (including www. prefixed)

```php
$configurator->plugins->load('Autoemail');
$configurator->plugins->load('Autolink', array('matchWww' => true));
```

**Link text truncation**: A custom `link_helper` service (`src/phpbb/forums/textformatter/s9e/link_helper.php`) creates `LINK_TEXT` tags that:
1. Strip the board URL from local links
2. Truncate long URLs for display (while preserving the full URL in the `href`)

```php
// link_helper.php:51-73
public function generate_link_text_tag(Tag $tag, Parser $parser)
{
    // Creates a LINK_TEXT tag that replaces the visible text of the link
    $link_text_tag = $parser->addSelfClosingTag('LINK_TEXT', $start, $length, 10);
    $link_text_tag->setAttribute('text', $text);
}
```

### Enabling/Disabling
```php
// parser.php:131-135
public function disable_magic_url()
{
    $this->parser->disablePlugin('Autoemail');
    $this->parser->disablePlugin('Autolink');
}
```

---

## 10. Text Length Limits

**Source**: `src/phpbb/common/message_parser.php:1163-1184`

Limits are **mode-dependent** (post, sig, pm) and read from config:

```php
// Maximum check
$message_length = ($mode == 'post') 
    ? utf8_strlen($this->message) 
    : utf8_strlen(preg_replace('#\[\/?[a-z\*\+\-]+(?:=\S+?)?\]#ius', '', $this->message));
    // For non-post modes, BBCode tags are stripped before measuring

if ((int) $config['max_' . $mode . '_chars'] > 0 && $message_length > (int) $config['max_' . $mode . '_chars'])
{
    $this->warn_msg[] = 'TOO_MANY_CHARS_LIMIT';
}

// Minimum check (post mode only)
if ($mode === 'post')
{
    if (!$message_length || $message_length < (int) $config['min_post_chars'])
    {
        $this->warn_msg[] = 'TOO_FEW_CHARS';
    }
}
```

Additional per-mode limits configured on the parser:
- `max_font_size` — Maximum `[size=N]` value
- `max_img_height` / `max_img_width` — Image dimension limits  
- `max_smilies` — Maximum smilies per post (enforced via s9e tag limits)
- `max_urls` — Maximum URLs per post
- `max_quote_depth` — Nested quote depth limit (enforced post-parse)

---

## 11. Edit/Decode Pipeline (DB → Edit Form)

### `generate_text_for_edit()`
**Source**: `src/phpbb/common/functions_content.php:799-830`

```php
function generate_text_for_edit($text, $uid, $flags)
{
    // Event: core.modify_text_for_edit_before
    decode_message($text, $uid);
    // Event: core.modify_text_for_edit_after
    
    return array(
        'allow_bbcode'  => ($flags & OPTION_FLAG_BBCODE) ? 1 : 0,
        'allow_smilies' => ($flags & OPTION_FLAG_SMILIES) ? 1 : 0,
        'allow_urls'    => ($flags & OPTION_FLAG_LINKS) ? 1 : 0,
        'text'          => $text
    );
}
```

### `decode_message()`
**Source**: `src/phpbb/common/functions_content.php:497-558`

Converts stored format back to user-editable BBCode:

```php
function decode_message(&$message, $bbcode_uid = '')
{
    if (preg_match('#^<[rt][ >]#', $message))
    {
        // s9e format: unparse XML back to BBCode
        $message = htmlspecialchars(
            $phpbb_container->get('text_formatter.utils')->unparse($message)
        );
    }
    else
    {
        // Legacy format: strip UID from BBCode tags
        $match = array('<br />', "[/*:m:$bbcode_uid]", ":u:$bbcode_uid", ":o:$bbcode_uid", ":$bbcode_uid");
        $replace = array("\n", '', '', '', '');
        $message = str_replace($match, $replace, $message);
        // Also strip magic URL/smiley HTML markers
    }
}
```

### s9e Unparse
**Source**: `src/phpbb/forums/textformatter/s9e/utils.php:139-142`

```php
public function unparse($xml)
{
    return \s9e\TextFormatter\Unparser::unparse($xml);
}
```

This uses the `<s>` (start) and `<e>` (end) XML elements to reconstruct the original BBCode text. That's why these elements exist in the stored XML — they enable **lossless round-tripping**.

---

## 12. Text Reparser

**Source**: `src/phpbb/forums/textreparser/base.php:230-270`

The reparser migrates content from legacy format to s9e XML format. It:
1. Reads stored text from DB
2. Guesses which features were enabled (BBCode, smilies, magic URLs)
3. Calls `generate_text_for_storage()` to re-parse with the s9e pipeline
4. Saves the new XML format back

```php
// base.php:230
$bbcodes = array('flash', 'img', 'quote', 'url');
foreach ($bbcodes as $bbcode) {
    $record['enable_' . $bbcode . '_bbcode'] = $this->guess_bbcode($record, $bbcode);
}
```

---

## 13. Event Hooks (Extension Points)

The text pipeline exposes numerous phpBB events for extension plugins:

### Parse-time Events
1. `core.modify_text_for_storage_before` — Before any parsing
2. `core.message_parser_check_message` — After length checks, before parser
3. `core.text_formatter_s9e_parse_before` — Just before s9e parses
4. `core.text_formatter_s9e_parse_after` — After s9e parses (XML available)
5. `core.modify_text_for_storage_after` — After all parsing complete
6. `core.modify_bbcode_init` — Modify BBCode definitions at init

### Render-time Events
1. `core.modify_text_for_display_before` — Before rendering
2. `core.text_formatter_s9e_render_before` — Just before s9e renders
3. `core.text_formatter_s9e_render_after` — After s9e renders (HTML available)
4. `core.modify_text_for_display_after` — After all rendering complete

### Configuration Events
1. `core.text_formatter_s9e_configure_before` — Before default config
2. `core.text_formatter_s9e_configure_after` — After all config, before finalize
3. `core.text_formatter_s9e_configure_finalize` — After finalize, before cache
4. `core.text_formatter_s9e_parser_setup` — When parser service is constructed
5. `core.text_formatter_s9e_renderer_setup` — When renderer service is constructed

---

## 14. Implications for New Plugin-Based Design

### What the Current Pipeline Does (that needs plugin hooks)

1. **BBCode Parsing**: Converts `[tag]...[/tag]` to intermediate format. In new design → **BBCode plugin**
2. **Markdown Parsing**: Not in current phpBB (would be new) → **Markdown plugin**
3. **Smiley/Emoticon Replacement**: Text patterns → image tags → **Smilies plugin**
4. **Emoji Rendering**: Unicode emoji → Twemoji SVGs → **Emoji plugin**
5. **Auto-linking**: Bare URLs/emails → clickable links → **Magic URL plugin**
6. **Word Censoring**: Text replacement → **Censor plugin**
7. **Attachments**: `[attachment=N]` → inline attachment display → **Attachments plugin**
8. **Quote Processing**: Nested quotes with metadata → **Quote plugin**
9. **Code Highlighting**: `[code=php]` → highlighted code → **Code highlighting plugin**

### Storage Format Considerations

The current s9e XML format is powerful but:
- **Tightly coupled** to s9e library (PHP-only)
- **Not easily consumable** by a REST API or SPA frontend
- **Round-tripping** depends on `<s>`/`<e>` markers in XML

For API-first design, consider:
- Store **raw source text** (what user typed) separately from parsed output
- Or store an **API-friendly intermediate format** (JSON AST instead of proprietary XML)
- Enable **server-side rendering** for SEO and **client-side rendering** for interactivity

### Key Plugin Hook Points Needed

Based on the current pipeline analysis:

| Phase | Current | Plugin Hook Needed |
|-------|---------|-------------------|
| Pre-parse validation | Length checks, event hooks | `content.validate_before_parse` |
| Text transformation | s9e plugins (BBCode, Emoticons, Autolink) | `content.parse` (ordered plugin chain) |
| Post-parse cleanup | Quote depth, empty check | `content.validate_after_parse` |
| Pre-render transformation | Quote metadata injection | `content.pre_render` |
| Rendering | s9e renderer (XML → HTML) | `content.render` (ordered plugin chain) |
| Post-render | Censoring, event hooks | `content.post_render` |
| Unparse (for editing) | s9e Unparser | `content.unparse` (reverse chain) |
