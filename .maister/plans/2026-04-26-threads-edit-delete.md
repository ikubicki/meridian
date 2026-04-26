# Plan: Uzupełnienie Threads Service — Edit & Delete

**Data:** 2026-04-26  
**Zadanie:** Dopełnienie modułu threads o brakujące operacje mutacji: edycja i usunięcie topic/post oraz uzupełnienie DTO o brakujące pola.

---

## Kontekst

Aktualny stan modułu:
- ✅ GET topics, GET topic, POST topic, GET posts, POST post  
- ❌ PATCH topic (edit title), PATCH post (edit content)  
- ❌ DELETE topic, DELETE post  
- ❌ `PostDTO` nie zawiera `authorUsername`, `createdAt`  
- ❌ Brak walidacji długości tytułu/treści  

Moderacja (lock, pin, move, visibility) należy do planowanego **M12** — poza zakresem.

---

## Zakres zmiany

| Obszar | Co dodajemy |
|--------|-------------|
| `ThreadsServiceInterface` | `updateTopic()`, `updatePost()`, `deleteTopic()`, `deletePost()` |
| `ThreadsService` | Implementacja 4 nowych metod |
| `DTO/UpdateTopicRequest` | Nowe DTO |
| `DTO/UpdatePostRequest` | Nowe DTO |
| `DTO/PostDTO` | Dodanie `authorUsername`, `createdAt` |
| `Event/TopicUpdatedEvent` | Nowy event |
| `Event/PostUpdatedEvent` | Nowy event |
| `Event/TopicDeletedEvent` | Nowy event |
| `Event/PostDeletedEvent` | Nowy event |
| `TopicRepositoryInterface` | `update()`, `delete()` |
| `PostRepositoryInterface` | `update()`, `delete()` |
| `DbalTopicRepository` | Implementacja `update()`, `delete()` |
| `DbalPostRepository` | Implementacja `update()`, `delete()` |
| `TopicsController` | `PATCH /topics/{id}`, `DELETE /topics/{id}` |
| `PostsController` | `PATCH /topics/{topicId}/posts/{postId}`, `DELETE /topics/{topicId}/posts/{postId}` |
| `config/default/container/routing/api.yml` | 4 nowe trasy |
| `services.yaml` | (bez zmian — aliasy już zarejestrowane) |
| Testy jednostkowe | Nowe testy dla repo + service + controller |
| Testy E2E | `threads.spec.ts` — nowe scenariusze edit/delete |

---

## Implementacja krok po kroku

### Krok 1 — nowe DTO

**`DTO/UpdateTopicRequest.php`**
```php
final readonly class UpdateTopicRequest
{
    public function __construct(
        public int    $topicId,
        public string $title,
        public int    $actorId,
    ) {}
}
```

**`DTO/UpdatePostRequest.php`**
```php
final readonly class UpdatePostRequest
{
    public function __construct(
        public int    $postId,
        public string $content,
        public int    $actorId,
    ) {}
}
```

**Rozszerzenie `PostDTO`** — dodać `authorUsername: string` i `createdAt: int`, zaktualizować `fromEntity()`.

---

### Krok 2 — nowe eventy

Cztery nowe klasy rozszerzające `DomainEvent`:
- `TopicUpdatedEvent` (`entityId`, `actorId`)
- `PostUpdatedEvent` (`entityId`, `actorId`)
- `TopicDeletedEvent` (`entityId`, `actorId`)
- `PostDeletedEvent` (`entityId`, `actorId`)

---

### Krok 3 — rozszerzenie kontraktów repozytoriów

`TopicRepositoryInterface`:
```php
public function update(int $topicId, string $title): void;
public function delete(int $topicId): void;
```

`PostRepositoryInterface`:
```php
public function update(int $postId, string $content): void;
public function delete(int $postId): void;
```

---

### Krok 4 — implementacja repozytoriów

**`DbalTopicRepository::update()`** — QueryBuilder `UPDATE phpbb_topics SET topic_title = :title WHERE topic_id = :id`  
**`DbalTopicRepository::delete()`** — soft delete: `UPDATE ... SET topic_visibility = 0` LUB hard delete: `DELETE` + relinkowanie `first_post_id`/`last_post_id` w forum.

> **Decyzja:** Soft delete (sprawdzić jak robi to phpBB3 — `topic_visibility = ITEM_DELETED = 2`). Hard delete jest destrukcyjny i wymaga kaskad (usunięcie postów, aktualizacja liczników forum).

**`DbalPostRepository::update()`** — `UPDATE phpbb_posts SET post_text = :content WHERE post_id = :id`  
**`DbalPostRepository::delete()`** — `DELETE FROM phpbb_posts WHERE post_id = :id` + `UPDATE phpbb_topics SET topic_posts_approved = topic_posts_approved - 1 WHERE topic_id = :topicId`

---

### Krok 5 — rozszerzenie ThreadsServiceInterface i ThreadsService

```php
public function updateTopic(UpdateTopicRequest $request): DomainEventCollection;
public function updatePost(UpdatePostRequest $request): DomainEventCollection;
public function deleteTopic(int $topicId, int $actorId): DomainEventCollection;
public function deletePost(int $postId, int $actorId): DomainEventCollection;
```

`ThreadsService` — każda metoda:
1. Pobranie encji (404 jeśli brak)
2. ACL check (owner OR moderator — przez porównanie `actorId`)
3. Wywołanie repo
4. Return `DomainEventCollection([new XxxEvent(...)])`

---

### Krok 6 — nowe endpointy API

**`TopicsController`:**
```
PATCH  /api/v1/topics/{topicId}   → updateTopic()  → 200 TopicDTO
DELETE /api/v1/topics/{topicId}   → deleteTopic()  → 204 No Content
```

**`PostsController`:**
```
PATCH  /api/v1/topics/{topicId}/posts/{postId}  → updatePost()  → 200 PostDTO
DELETE /api/v1/topics/{topicId}/posts/{postId}  → deletePost()  → 204 No Content
```

ACL dla PATCH/DELETE: wymagany JWT; `$request->actorId === $topic->posterId` OR moderator flag (uproszczone: tylko owner na początku).

**`api.yml`** — 4 nowe trasy z `methods: [PATCH]` i `methods: [DELETE]`.

---

### Krok 7 — testy jednostkowe

Rozszerzyć istniejące lub dodać nowe pliki testowe:
- `DbalTopicRepositoryTest` — testy dla `update()` i `delete()`
- `DbalPostRepositoryTest` — testy dla `update()` i `delete()`
- `ThreadsServiceTest` — testy dla `updateTopic()`, `updatePost()`, `deleteTopic()`, `deletePost()` (includując scenariusze nieautoryzowanego aktora)

---

### Krok 8 — testy E2E

Nowe testy w `threads.spec.ts`:
- PATCH topic: sukces (200, zaktualizowany tytuł), brak auth (401), wrong actor (403), not found (404), pusta wartość (422)
- PATCH post: analogicznie
- DELETE topic: sukces (204), CASCADE (czy posty znikają?), brak auth (401), zły aktor (403)
- DELETE post: sukces (204), czy `postCount` topicu się zmniejsza

---

## Applicable Standards

### `standards/global/STANDARDS.md`
- Nagłówek pliku `phpBB4 "Meridian"` / `Irek Kubicki` — każdy nowy plik
- `declare(strict_types=1)` — obowiązkowo
- `php-cs-fixer` po zmianach

### `standards/backend/STANDARDS.md`
- `final readonly class` dla nowych DTO i Event
- Constructor property promotion z `readonly`
- Doctrine DBAL 4 `createQueryBuilder()` — brak raw SQL
- Named parameters (`:name`) — bez pozycyjnych `?`
- `UPDATABLE_FIELDS` whitelist w repozytoriach przy UPDATE (`update()` akceptuje tylko whitelistowane kolumny)
- Controller thin routing layer — żadnej logiki biznesowej

### `standards/backend/REST_API.md`
- 200 dla PATCH (sukces update), 204 dla DELETE (brak body)
- 401 dla brakującego tokenu, 403 dla złego właściciela, 404 dla nieistniejącego zasobu
- 422 dla semantycznie niepoprawnego żądania (pusty tytuł)
- Error shape: `{ "error": "...", "status": NNN }`
- Trasy: `api_v1_topics_update`, `api_v1_topics_delete`, `api_v1_posts_update`, `api_v1_posts_delete`

### `standards/backend/DOMAIN_EVENTS.md`
- Nowe eventy rozszerzają `DomainEvent`; `entityId`, `actorId`, `occurredAt` (auto)
- `DomainEventCollection` konstruowana z inline array — brak `add()`/`merge()`
- Dispatcher w kontrolerze, nie w service

### `standards/testing/STANDARDS.md`
- `#[Test]` attribute (nie `@test`)
- Namespace `phpbb\Tests\threads\`
- AAA pattern w każdym teście
- `Interface&MockObject` intersection type dla mocków

---

## Standards Compliance Checklist

- [ ] Każdy nowy plik PHP ma nagłówek copyright `phpBB4 "Meridian"` / `Irek Kubicki`
- [ ] `declare(strict_types=1)` w każdym nowym pliku
- [ ] Nowe DTO i Eventy to `final readonly class`
- [ ] Repozytoria używają tylko `createQueryBuilder()` — zero raw SQL strings
- [ ] Named parameters (`:name`) w wszystkich zapytaniach
- [ ] `DbalTopicRepository::update()` ma `UPDATABLE_FIELDS` whitelist
- [ ] `DbalPostRepository::update()` ma `UPDATABLE_FIELDS` whitelist
- [ ] Kontrolery są thin — tylko parse request, call service, return response
- [ ] PATCH zwraca 200 z pełnym DTO; DELETE zwraca 204 (brak body)
- [ ] 401 przy braku JWT, 403 przy złym właścicielu, 404 przy brak zasobu, 422 przy walidacji
- [ ] `DomainEventCollection` tworzona z inline array w service; dispatch w kontrolerze
- [ ] Nowe Eventy: `entityId`, `actorId` — bez `occurredAt` w konstruktorze
- [ ] Testy z `#[Test]` attribute, namespace `phpbb\Tests\threads\`, AAA pattern
- [ ] Mock properties jako `Interface&MockObject` intersection type
- [ ] `php-cs-fixer` przechodzi po zmianach
- [ ] `composer test` — 494+ testów, 0 failures
- [ ] `composer test:e2e` — nowe scenariusze PATCH/DELETE przechodzą
