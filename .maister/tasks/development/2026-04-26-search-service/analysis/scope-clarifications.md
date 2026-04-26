# Scope Clarifications — M9 Search Service

## Krytyczne decyzje

### 1. Konfiguracja drivera wyszukiwania
**Decyzja**: Nowe `phpbb\config\ConfigRepository` (reusable)
- Czyta z tabeli `phpbb_config` (kolumny: `config_name PK`, `config_value`)
- Klucz: `search_driver` (wartości: `fulltext` | `like` | `elasticsearch`)
- Fallback default: `fulltext`
- **Scope M9**: ConfigRepository tworzymy jako część tego milestone (side-effect reusable)

### 2. Testowanie FullTextDriver
**Decyzja**: Unit testy z mockiem DBAL + E2E na real MySQL  
- PHPUnit: mock `Connection` zwraca tablicę wyników, nie wykonuje realnego MATCH AGAINST
- E2E (Playwright): zapytania na real MySQL w Docker (port 13306) — weryfikuje prawdziwy FULLTEXT
- **Nie używamy** FTS5 ani specjalnych SQLite setup

## Ważne decyzje (auto-defaults)

### 3. SearchResultDTO — minimalne pola
`postId`, `topicId`, `forumId`, `subject`, `excerpt` (pierwsze 200 znaków `post_text`), `postedAt`
Bez JOIN do username (MVP — można dodać w M10+)

### 4. Widoczność postów
Tylko `post_visibility = 1` (publiczne posty, MVP)
Pełne ACL per-forum w przyszłości

## Podsumowanie architektury

```
phpbb\config\ConfigRepository        ← nowy (side-benefit reusable)
phpbb\search\Contract\SearchDriverInterface
phpbb\search\Driver\FullTextDriver   ← MySQL MATCH...AGAINST / PG tsvector / SQLite FTS5
phpbb\search\Driver\LikeDriver       ← LIKE fallback
phpbb\search\Driver\ElasticsearchDriver ← stub
phpbb\search\Service\SearchService   ← wybiera driver przez ConfigRepository
phpbb\search\DTO\SearchResultDTO
phpbb\api\Controller\SearchController
```
