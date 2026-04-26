# Requirements — M9 Search Service

## Opis inicjalny
Implementacja serwisu wyszukiwania M9 dla phpBB4 Meridian z pluggable driver abstraction konfigurowaną przez admina.

## Q&A

**Q: Jakie trzy drivery i ich priorytety?**  
A: FullText (MySQL MATCH AGAINST / PG tsvector / SQLite FTS5), Like (fallback), Elasticsearch (stub)

**Q: Jak konfiguracja drivera?**  
A: phpbb\config\ConfigRepository odczytuje `search_driver` z tabeli `phpbb_config`. Default: `fulltext`.

**Q: User journey — kto i jak wyszukuje?**  
A: Zalogowany użytkownik (JWT Bearer) wywołuje `GET /api/search?q=...&forum_id=...&page=...&per_page=...`. Wyniki stronicowane.

**Q: Co jest przeszukiwane?**  
A: Posty: `post_text` + `post_subject`. Tylko `post_visibility = 1` (publiczne).

**Q: Pola w odpowiedzi?**  
A: `postId`, `topicId`, `forumId`, `subject`, `excerpt` (200 znaków post_text), `postedAt`

**Q: Strategia testowania FullTextDriver?**  
A: Unit test z mockiem DBAL (nie wykonuje real SQL) + E2E Playwright na real MySQL (port 13306)

**Q: Reusable ConfigRepository — czy ma być kompletna CRUD?**  
A: Na M9 tylko read: `get(string $key, string $default = ''): string`

## Similar features
- `src/phpbb/notifications/` — kompletny moduł (wzorzec DI, Repository, Service, Controller)
- `src/phpbb/auth/` — JWT auth pattern (wzorzec `_api_user`)
- `src/phpbb/common/DTO/PaginationContext.php` — paginacja

## Functional Requirements

1. `GET /api/search?q={term}` — wyszukuje po `post_text` i `post_subject`
2. Opcjonalny filtr: `?forum_id={int}` — zawęża do forum
3. Paginacja: `?page=1&per_page=20`
4. Wymaga JWT Bearer token (401 jeśli brak)
5. Wybór drivera przez `phpbb_config.search_driver` (`fulltext`|`like`|`elasticsearch`)
6. FullTextDriver: MySQL `MATCH(post_text, post_subject) AGAINST(:q IN BOOLEAN MODE)`, PG `tsquery`, SQLite FTS5
7. LikeDriver: `(post_text LIKE :q OR post_subject LIKE :q)` gdzie `:q = '%term%'`
8. ElasticsearchDriver: stub — loguje warning, deleguje do LikeDriver
9. `phpbb\config\ConfigRepository::get(string $key, string $default): string`

## Scope Boundaries
**In scope**: phpbb\config namespace (only read), phpbb\search namespace, SearchController, tests
**Out of scope**: Admin UI, write to phpbb_config, search history, ACL per-forum, username JOIN

## Technical Considerations
- DBAL 4 QueryBuilder (nie ORM)
- Wykrywanie platformy: `$connection->getDatabasePlatform() instanceof MySQLPlatform`
- ElasticsearchDriver extends LikeDriver (delegacja, nie puste wyniki)
