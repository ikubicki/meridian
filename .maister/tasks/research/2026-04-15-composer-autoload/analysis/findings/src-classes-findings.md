# Source: PHP Classes in src/phpbb/ — PSR-4 Autoload Analysis

**Research question**: How can all `require`/`include` statements be replaced with Composer PSR-4 autoloading?  
**Gathered**: 2026-04-15  
**Source category**: `src-classes`

---

## 1. Total File Count

| Metric | Count |
|--------|-------|
| Total PHP files in `src/phpbb/` | **1,086** |
| Files WITH `namespace` declaration | **867** (79.8%) |
| Files WITHOUT `namespace` declaration | **219** (20.2%) |

---

## 2. PHP Files by Major Subdirectory

| Subdirectory | Total PHP Files | With Namespace | Without Namespace |
|---|---|---|---|
| `src/phpbb/forums/` | **852** | 852 (100%) | 0 |
| `src/phpbb/common/` | **173** | 0 (0%) | 173 (100%) |
| `src/phpbb/language/` | **38** | 0 (0%) | 38 (100%) |
| `src/phpbb/ext/phpbb/viglink/` | **14** | 12 (86%) | 2 (language files) |
| `src/phpbb/install/` | **9** | 3 (33%) | 6 (entry points) |
| `src/phpbb/admin/` | **0** | — | — |
| `src/phpbb/styles/` | **0** | — | — |

---

## 3. Namespace → Directory Mapping (PSR-4 Analysis)

### Primary mapping (852 files, fully PSR-4 compliant)

All 852 files in `src/phpbb/forums/` follow PSR-4 exactly with `phpbb\` as the root namespace:

| Directory (relative to `src/phpbb/forums/`) | Namespace |
|---|---|
| `./` | `phpbb\` |
| `attachment/` | `phpbb\attachment\` |
| `auth/` | `phpbb\auth\` |
| `auth/provider/` | `phpbb\auth\provider\` |
| `auth/provider/oauth/service/` | `phpbb\auth\provider\oauth\service\` |
| `avatar/driver/` | `phpbb\avatar\driver\` |
| `cache/driver/` | `phpbb\cache\driver\` |
| `captcha/plugins/` | `phpbb\captcha\plugins\` |
| `config/` | `phpbb\config\` |
| `console/command/` | `phpbb\console\command\` |
| `controller/` | `phpbb\controller\` |
| `cron/task/core/` | `phpbb\cron\task\core\` |
| `db/driver/` | `phpbb\db\driver\` |
| `db/migration/data/v33x/` | `phpbb\db\migration\data\v33x\` |
| `db/migration/tool/` | `phpbb\db\migration\tool\` |
| `db/tools/` | `phpbb\db\tools\` |
| `di/extension/` | `phpbb\di\extension\` |
| `di/pass/` | `phpbb\di\pass\` |
| `extension/di/` | `phpbb\extension\di\` |
| `filesystem/` | `phpbb\filesystem\` |
| `files/types/` | `phpbb\files\types\` |
| `install/` (subdir within forums/) | `phpbb\install\` |
| `install/module/install_database/task/` | `phpbb\install\module\install_database\task\` |
| `language/` | `phpbb\language\` |
| `notification/method/` | `phpbb\notification\method\` |
| `notification/type/` | `phpbb\notification\type\` |
| `passwords/driver/` | `phpbb\passwords\driver\` |
| `profilefields/type/` | `phpbb\profilefields\type\` |
| `report/controller/` | `phpbb\report\controller\` |
| `search/sphinx/` | `phpbb\search\sphinx\` |
| `template/twig/extension/` | `phpbb\template\twig\extension\` |
| `template/twig/node/` | `phpbb\template\twig\node\` |
| `template/twig/tokenparser/` | `phpbb\template\twig\tokenparser\` |
| `textformatter/s9e/` | `phpbb\textformatter\s9e\` |
| `textreparser/plugins/` | `phpbb\textreparser\plugins\` |
| `tree/` | `phpbb\tree\` |
| `ucp/controller/` | `phpbb\ucp\controller\` |

**Verification evidence**:
- `src/phpbb/forums/auth/auth.php` → `namespace phpbb\auth; class auth` ✓
- `src/phpbb/forums/template/twig/extension/username.php` → `namespace phpbb\template\twig\extension; class username` ✓
- `src/phpbb/forums/db/migration/data/v33x/add_notification_emails_table.php` → `namespace phpbb\db\migration\data\v33x;` ✓

### Secondary mapping — `phpbb\convert\` (3 files)

Files in `src/phpbb/install/convert/` use `phpbb\convert\` namespace:

| File | Namespace | Class |
|---|---|---|
| `src/phpbb/install/convert/convert.php` | `phpbb\convert` | `convert` |
| `src/phpbb/install/convert/convertor.php` | `phpbb\convert` | `convertor` |
| `src/phpbb/install/convert/controller/convertor.php` | `phpbb\convert\controller` | (class) |

→ Requires separate PSR-4 entry: `"phpbb\\convert\\": "src/phpbb/install/convert/"`

### Extension mapping — `phpbb\viglink\` (12 files)

Files in `src/phpbb/ext/phpbb/viglink/` (excluding language files):

| Directory | Namespace |
|---|---|
| `ext/phpbb/viglink/` | `phpbb\viglink\` |
| `ext/phpbb/viglink/acp/` | `phpbb\viglink\acp\` |
| `ext/phpbb/viglink/cron/` | `phpbb\viglink\cron\` |
| `ext/phpbb/viglink/event/` | `phpbb\viglink\event\` |
| `ext/phpbb/viglink/migrations/` | `phpbb\viglink\migrations\` |

→ Requires entry: `"phpbb\\viglink\\": "src/phpbb/ext/phpbb/viglink/"`  
Note: The extension's own `composer.json` does **not** declare an `autoload` section — it relies on the main project's autoloader.

---

## 4. Non-Namespaceable Files (Cannot Be PSR-4 Autoloaded)

### 4a. Purely procedural — functions only (53 files in `common/`)

These contain only functions/constants and **cannot** be classmap or PSR-4 autoloaded. They require Composer `files` autoload or explicit `require`:

| File | Content |
|---|---|
| `common/functions.php` | Core phpBB functions |
| `common/functions_privmsgs.php` | Private message functions |
| `common/functions_admin.php` | Admin helper functions |
| `common/functions_mcp.php` | MCP-related functions |
| `common/functions_compatibility.php` | BC compat functions |
| `common/functions_posting.php` | Posting functions |
| `common/functions_display.php` | Display/rendering functions |
| `common/functions_user.php` | User functions |
| `common/functions_acp.php` | ACP functions |
| `common/functions_download.php` | File download functions |
| `common/functions_convert.php` | Conversion functions |
| `common/functions_database_helper.php` | DB helper functions |
| `common/functions_jabber.php` | Jabber functions |
| `common/functions_compress.php` | Compression functions |
| `common/functions_transfer.php` | FTP/file transfer |
| `common/functions_messenger.php` | Messenger functions |
| `common/startup.php` | Bootstrap startup logic |
| `common/common.php` | Main include bootstrap |
| `common/compatibility_globals.php` | Global variable compat shims |
| `common/constants.php` | Global define() constants |
| `common/config/config.php` | Runtime-generated DB config (NOT autoloadable) |
| `common/utf/utf_tools.php` | UTF-8 utility functions |
| `common/utf/data/*.php` (14 files) | Search indexer data arrays |
| `common/mcp/mcp_front.php` | MCP front (no class) |
| `common/mcp/mcp_topic.php` | MCP topic (no class) |
| `common/mcp/mcp_post.php` | MCP post (no class) |
| `common/mcp/mcp_forum.php` | MCP forum (no class) |
| `common/ucp/ucp_pm_options.php` | UCP PM options (no class) |
| `common/ucp/ucp_pm_viewfolder.php` | UCP PM view folder (no class) |
| `common/ucp/ucp_pm_viewmessage.php` | UCP PM view message (no class) |
| `common/ucp/ucp_pm_compose.php` | UCP PM compose (no class) |

### 4b. Legacy module classes WITHOUT namespace (120 files in `common/`)

These files declare classes but **without** a namespace declaration. They use the phpBB 3.0/3.1 legacy convention where `class acp_board` lives in `acp/acp_board.php`. They can technically use Composer `classmap` autoload but **cannot** use PSR-4 without first adding namespace declarations.

**Legacy ACP modules** (`common/acp/*.php` — ~25 files):
```
class acp_board, acp_ban, acp_users, acp_groups, acp_bots, acp_search,
acp_styles, acp_language, acp_modules, acp_profile, acp_captcha,
acp_extensions, acp_forums, acp_database, acp_attachments, ...
```

**Legacy ACP info files** (`common/acp/info/*.php` — ~20 files):
```
class acp_board_info, acp_ban_info, ... (each returns module metadata)
```

**Legacy UCP modules** (`common/ucp/*.php` — ~16 files):
```
class ucp_main, ucp_profile, ucp_register, ucp_pm, ucp_notifications, ...
```

**Legacy MCP modules** (`common/mcp/*.php` with classes — ~11 files):
```
class mcp_ban, mcp_warn, mcp_reports, mcp_pm_reports, mcp_main, ...
```

**Other legacy class files**:
```
common/message_parser.php  → class parse_message extends bbcode_firstpass
common/bbcode.php          → class bbcode
common/sphinxapi.php       → class SphinxClient
common/diff/engine.php, diff.php, renderer.php → diff classes
common/questionnaire/questionnaire.php
common/functions_jabber.php → class jabber
common/functions_compress.php → class compress, compress_zip
common/functions_transfer.php → class ftp_base, transfer, ftp
common/functions_messenger.php → class messenger, queue
common/functions_module.php → class p_master, module_auth
```

### 4c. Install entry points (6 files in `src/phpbb/install/`)

Not autoloadable — they are bootstraps/scripts:
- `install/app.php` — Symfony application bootstrap
- `install/phpinfo.php` — phpinfo script
- `install/phpbbcli.php` — CLI bootstrap
- `install/startup.php` — startup bootstrap
- `install/convertors/convert_phpbb20.php` — legacy convertor data
- `install/convertors/functions_phpbb20.php` — legacy convertor functions

### 4d. Language files (38 files in `src/phpbb/language/` + 2 in ext/)

All language files define/populate the `$lang` array — no classes, no namespaces. Must be `require`d at runtime by the language subsystem.

---

## 5. Required PSR-4 Entries for `composer.json`

```json
"autoload": {
    "psr-4": {
        "phpbb\\": "src/phpbb/forums/",
        "phpbb\\convert\\": "src/phpbb/install/convert/",
        "phpbb\\viglink\\": "src/phpbb/ext/phpbb/viglink/"
    },
    "classmap": [
        "src/phpbb/common/acp/",
        "src/phpbb/common/mcp/",
        "src/phpbb/common/ucp/",
        "src/phpbb/common/diff/",
        "src/phpbb/common/questionnaire/",
        "src/phpbb/common/bbcode.php",
        "src/phpbb/common/message_parser.php",
        "src/phpbb/common/sphinxapi.php",
        "src/phpbb/common/functions_jabber.php",
        "src/phpbb/common/functions_compress.php",
        "src/phpbb/common/functions_transfer.php",
        "src/phpbb/common/functions_messenger.php",
        "src/phpbb/common/functions_module.php"
    ],
    "files": [
        "src/phpbb/common/constants.php",
        "src/phpbb/common/functions.php",
        "src/phpbb/common/functions_compatibility.php",
        "src/phpbb/common/functions_admin.php",
        "src/phpbb/common/functions_privmsgs.php",
        "src/phpbb/common/functions_mcp.php",
        "src/phpbb/common/functions_posting.php",
        "src/phpbb/common/functions_display.php",
        "src/phpbb/common/functions_user.php",
        "src/phpbb/common/functions_acp.php",
        "src/phpbb/common/functions_download.php",
        "src/phpbb/common/functions_database_helper.php",
        "src/phpbb/common/functions_convert.php",
        "src/phpbb/common/utf/utf_tools.php"
    ]
}
```

> **Note**: `common/startup.php`, `common/common.php`, `common/compatibility_globals.php`, and `common/config/config.php` are explicit bootstraps that must remain as manual `require` calls — they set up the runtime environment before autoloading is active, or contain runtime-generated content.

> **Note**: `common/utf/data/*.php` files are loaded on-demand by `utf_tools.php` — they should remain as explicit includes within that file.

> **Note**: Language files must remain `require`d by the phpBB language system — they are NOT autoloadable.

---

## 6. PSR-4 Compliance Summary

| Location | Status | Action Needed |
|---|---|---|
| `src/phpbb/forums/` | ✅ Fully PSR-4 compliant | Add `"phpbb\\": "src/phpbb/forums/"` to composer.json |
| `src/phpbb/install/convert/` | ✅ PSR-4 compliant | Add `"phpbb\\convert\\": "src/phpbb/install/convert/"` |
| `src/phpbb/ext/phpbb/viglink/` | ✅ PSR-4 compliant (except 2 lang files) | Add `"phpbb\\viglink\\": "src/phpbb/ext/phpbb/viglink/"` |
| `src/phpbb/common/` (120 class files) | ⚠️ Classes without namespace | Use `classmap` OR add namespace declarations |
| `src/phpbb/common/` (53 procedural files) | ❌ Not autoloadable | Must use `files` autoload or runtime `require` |
| `src/phpbb/language/` (38 files) | ❌ Not autoloadable | Runtime `require` only |
| `src/phpbb/install/` (6 entry points) | ❌ Not autoloadable | Runtime `require` only |

---

## 7. Key Findings

1. **867 of 1,086 files (80%) are already PSR-4 compliant** — they live in `src/phpbb/forums/` and all declare the correct `phpbb\*` namespace matching their directory path. No code changes required for these files.

2. **Single PSR-4 root**: The entire modern phpBB class library maps to a single rule: `phpbb\ → src/phpbb/forums/`. All sub-namespaces (`phpbb\auth\`, `phpbb\db\`, `phpbb\install\`, etc.) are subdirectories within `forums/`.

3. **Legacy `common/` is the main challenge**: 173 files with 0 namespace declarations. Of these, 120 have classes (legacy ACP/UCP/MCP module pattern without namespace) and 53 are purely procedural. The legacy classes can use `classmap` autoload; the procedural files require `files` autoload.

4. **`phpbb\convert\` is stranded**: 3 files in `src/phpbb/install/convert/` use `phpbb\convert\` namespace but live outside the `forums/` directory tree. They need their own PSR-4 entry.

5. **No namespace conflicts detected**: All 867 namespaced files use a consistent `phpbb\` hierarchy. No two files in different directories declare the same fully-qualified class name.

6. **Viglink extension**: Lacks `autoload` in its own `composer.json`. The 12 namespaced classes need either a root-level composer entry or the extension's `composer.json` to declare autoloading.

---

**Confidence**: High (100%) — Based on direct file inspection, grep analysis of all 1,086 files, and verified PSR-4 path/namespace alignment with concrete examples.
