# Research Brief: phpbb\hierarchy Service

## Research Question

Jak zaprojektować serwis `phpbb\hierarchy` odpowiedzialny za zarządzanie kategoriami i forami na bazie legacy nested set, z pluginową architekturą rozszerzeń, bez odpowiedzialności za uprawnienia ACL?

## Research Type

**Technical** — analiza legacy codebase + ekstrakcja do nowoczesnego serwisu PHP 8.2

## Context

Projekt phpBB modernizacji wyodrębnia serwisy z legacy kodu:
- `phpbb\user` — uwierzytelnianie, zarządzanie użytkownikami (zaprojektowany)
- `phpbb\auth` — autoryzacja, ACL, uprawnienia (zaprojektowany)
- **`phpbb\hierarchy`** — zarządzanie kategoriami i forami (DO ZAPROJEKTOWANIA)

Legacy system zarządza forami poprzez nested set tree (left_id/right_id) z ~30 kolumnami konfiguracyjnych w tabeli `phpbb_forums`. ACP (Admin Control Panel) obsługuje CRUD forów przez `acp_forums.php`. Wyświetlanie hierarchii odbywa się przez `display_forums()` w `functions_display.php`.

## Scope

### Included
- Legacy nested set implementation (`tree/nestedset.php`, `tree/nestedset_forum.php`)
- Forum/category CRUD (`acp_forums.php`)
- Forum display & navigation (`functions_display.php` → `display_forums()`)
- Forum DB tables: `phpbb_forums`, `phpbb_forums_access`, `phpbb_forums_track`, `phpbb_forums_watch`
- Forum attributes — all ~30 columns (settings, counters, metadata)
- Tree operations: move, copy, reorder, delete with subtree handling
- Forum tracking: read/unread status per user
- Forum subscriptions: watch lists, notification integration
- Plugin/component extension architecture design
- Integration with `phpbb\auth` (permission checks) and `phpbb\user` (user identity)

### Excluded
- ACL/permissions — odpowiedzialność `phpbb\auth`
- User management — odpowiedzialność `phpbb\user`
- Topic/post management — przyszły `phpbb\post` service
- Search, moderation, content visibility

## Success Criteria

1. Full mapping of legacy forum hierarchy code (classes, functions, DB schema)
2. Understanding of nested set algorithm as implemented in phpBB
3. Complete entity model for Category and Forum
4. Service interfaces with PHP 8.2 signatures
5. Plugin/extension architecture design
6. DB query patterns for all operations
7. Integration contract with `phpbb\auth` and `phpbb\user`
8. Event model for hierarchy changes
