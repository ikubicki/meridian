# REST API Standards

Conventions for the `src/phpbb/api/` layer (phpBB Vibed REST API, PHP 8.3).

> **⚠️ Authoritative contract**
> The OpenAPI specification at
> `.maister/tasks/research/2026-04-20-rest-api-spec/outputs/openapi.yaml`
> is the **single source of truth** for all endpoint contracts (request/response shapes,
> status codes, auth requirements, permission annotations).
> In case of any conflict between this document and the OpenAPI spec, **the spec wins**.
> Update the spec first, then update this document to match.

## Routing

- Routes are defined in `config/default/container/routing/api.yml`
- Route naming: `api_v1_<resource>_<action>` — e.g., `api_v1_forums_index`, `api_v1_auth_login`
- All routes must be prefixed with `/api/v{n}/` where `n` is the API version
- Versioned routes allow backward-compatible evolution

## Request & Response Format

- All endpoints consume and produce `application/json`
- Controllers must return a `Symfony\Component\HttpFoundation\JsonResponse`
- Never output raw `echo`, `print`, or `header()` calls from controllers

```php
return new JsonResponse(['forums' => $forums, 'total' => count($forums)]);
return new JsonResponse(['error' => 'Not found'], 404);
```

## HTTP Status Codes

| Code | Use case |
|------|----------|
| `200` | Successful GET / PATCH |
| `201` | Resource created (POST) — include `Location` header when possible |
| `400` | Malformed request (bad JSON, missing required fields structure) |
| `401` | Missing or invalid JWT token |
| `403` | Authenticated but not authorized (insufficient permissions) |
| `404` | Resource not found |
| `409` | Conflict — e.g., duplicate username/email on signup |
| `422` | Validation failed — request is well-formed but semantically invalid |
| `500` | Unexpected server error — log details, return safe generic message |

## Response Shape Conventions

### Success response
Always use `data` as the top-level key. Paginated collections include a `meta` object:

```json
// Collection (paginated):
{ "data": [...], "meta": { "total": 42, "page": 1, "perPage": 25, "lastPage": 2 } }

// Single resource:
{ "data": { "id": 1, "title": "..." } }

// Created resource:
{ "data": { "id": 100, "username": "alice" } }
```

> **See [openapi.yaml](../../../../.maister/tasks/research/2026-04-20-rest-api-spec/outputs/openapi.yaml)**
> for the canonical response schema of every endpoint.

### Error response
Always use `error` as the key for the human-readable message:

```json
{ "error": "Username already taken", "status": 409 }
```

### Validation error response (422)
Collect **all** validation errors before responding — never fail fast on the first error:

```json
{
    "errors": [
        { "field": "username", "message": "Username is required" },
        { "field": "password", "message": "Password must be at least 8 characters" }
    ]
}
```

## Authentication

- All protected endpoints require a Bearer token in the `Authorization` header
- Token format: `Authorization: Bearer <JWT>`
- JWT validation is handled exclusively in `src/phpbb/api/event/AuthSubscriber.php` — controllers do **not** validate tokens
- Decoded JWT claims are stored as a request attribute named `_api_token`
- Controllers retrieve claims via: `$request->attributes->get('_api_token')`

### Public endpoints (no token required)
- `GET /api/v1/health`
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/signup`

All other `/api/` routes require a valid token.

### JWT payload structure

> ⚠️ The canonical JWT claim names are defined in the OpenAPI spec (`TokenPair` schema
> and the `## Authentication` section of `info.description`). The spec wins on any conflict.

```json
{
    "sub": "2",
    "username": "alice",
    "utype": 0,
    "gen": 1,
    "pv": 0,
    "flags": ["acp"],
    "kid": "key-2026",
    "iat": 1700000000,
    "exp": 1700000900
}
```

| Claim | Type | Description |
|---|---|---|
| `sub` | string | User ID (as string, per JWT spec) |
| `username` | string | Current display username |
| `utype` | integer | User type: 0=Normal, 1=Inactive, 2=Bot, 3=Founder |
| `gen` | integer | Token generation — increment on `POST /auth/logout-all` to invalidate all sessions |
| `pv` | integer | Password version — increment on password change to invalidate older tokens |
| `flags` | string[] | Elevated scope claims granted at `/auth/elevate` (e.g. `["acp"]`, `["mcp"]`) |
| `kid` | string | Signing key ID for rotation support |

## Validation Pattern

Validation logic lives in the controller action. Collect errors into an array and return `422` with the full list:

```php
public function signup(Request $request): JsonResponse
{
    $body = json_decode($request->getContent(), true) ?? [];
    $errors = [];

    if (empty($body['username'])) {
        $errors[] = ['field' => 'username', 'message' => 'Username is required'];
    }
    if (empty($body['password']) || strlen($body['password']) < 8) {
        $errors[] = ['field' => 'password', 'message' => 'Password must be at least 8 characters'];
    }

    if (!empty($errors)) {
        return new JsonResponse(['errors' => $errors], 422);
    }

    // ... proceed with creation
}
```

## Controller Conventions

### Thin-layer rule (enforced for all controllers)

Controllers **must not** implement any business logic. Their sole responsibilities are:

1. Parse and validate the request (input shape, required fields)
2. Build the appropriate DTO / context object
3. Call the relevant service method
4. Map the service result or caught exception to a `JsonResponse`

If you find yourself writing an `if` that is not about request validation or HTTP response mapping, the logic belongs in a service.

```php
// ✅ Correct — controller is a thin router/mapper
public function index(Request $request): JsonResponse
{
    $ctx    = PaginationContext::fromQuery($request->query);
    $result = $this->forumService->listAll($ctx);

    return new JsonResponse([
        'data' => array_map(fn ($f) => $this->serialize($f), $result->items),
        'meta' => [
            'total'      => $result->total,
            'page'       => $result->page,
            'perPage'    => $result->perPage,
            'totalPages' => $result->totalPages(),
        ],
    ]);
}

// ❌ Wrong — business logic leaking into controller
public function index(Request $request): JsonResponse
{
    $forums = $this->forumService->listAll();
    $forums = array_filter($forums, fn ($f) => $f->isVisible()); // ← belongs in service
    // ...
}
```

### Other controller rules

- Controllers are plain PHP classes — no base class required for simple controllers
- Constructor injection via Symfony DI; use `readonly` constructor promotion for dependencies
- One public action method per route (not `__invoke` unless the controller has only one route)
- Method names must match the route action: `index()`, `show(int $id)`, `create()`, `update()`, `delete()`

```php
namespace phpbb\api\Controller;

use phpbb\api\DTO\PaginationContext;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ForumsController
{
    public function __construct(
        private readonly ForumService $forumService,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $ctx    = PaginationContext::fromQuery($request->query);
        $result = $this->forumService->listAll($ctx);

        return new JsonResponse([
            'data' => $result->items,
            'meta' => [
                'total'      => $result->total,
                'page'       => $result->page,
                'perPage'    => $result->perPage,
                'totalPages' => $result->totalPages(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $forum = $this->forumService->findById($id);
        if ($forum === null) {
            return new JsonResponse(['error' => 'Forum not found', 'status' => 404], 404);
        }
        return new JsonResponse(['data' => $forum]);
    }
}
```

## Pagination Context

All list/search endpoints must use `phpbb\api\DTO\PaginationContext` (or a domain-specific DTO that embeds it) — never pass raw `$page` / `$perPage` integers as separate arguments to service methods.

### `PaginationContext` fields

| Field | Type | Default | Source query param |
|-------|---------|---------|--------------------|
| `page` | `int` | `1` | `?page=` |
| `perPage` | `int` | `25` (max 100) | `?perPage=` |
| `sort` | `?string` | `null` | `?sort=` |
| `sortOrder` | `string` | `'asc'` | `?order=` |

### Controller usage

Build the context using the named constructor — never construct manually in a controller action:

```php
$ctx = PaginationContext::fromQuery($request->query);
$result = $this->someService->list($ctx);  // $result is PaginatedResult<T>
```

### Service method signature

Service methods that return paginated data accept `PaginationContext` as their first (or only) parameter — never raw integers:

```php
// ✅ Correct
public function listAll(PaginationContext $ctx): PaginatedResult { ... }
public function search(UserSearchCriteria $criteria): PaginatedResult { ... }

// ❌ Wrong — raw pagination arguments
public function listAll(int $page, int $perPage, string $sort = 'name'): array { ... }
```

Domain-specific filter DTOs (e.g. `UserSearchCriteria`) must include their own pagination fields following the same names (`page`, `perPage`, `sort`, `sortOrder`) and default values, or embed `PaginationContext` directly.

### `PaginatedResult<T>` response meta

Every paginated response must include the full `meta` block:

```json
{
    "data": [...],
    "meta": {
        "total":      100,
        "page":       1,
        "perPage":    25,
        "totalPages": 4
    }
}
```

Use `PaginatedResult::totalPages()` — never compute `ceil($total / $perPage)` inline in a controller.

## Input Handling

- Parse JSON body with `json_decode($request->getContent(), true)` — always pass `true` for array mode
- Guard against `null` result (malformed JSON): `$body = json_decode(...) ?? []`
- Never read `$_GET`, `$_POST`, or `$_REQUEST` directly — use `$request->query->get()` / `$request->request->get()`
- Query string parameters: `$request->query->get('forum_id')`

## Versioning Strategy

- API version is part of the URL path: `/api/v1/`, `/api/v2/`
- Breaking changes require a new version prefix and new route group
- Non-breaking additions (new optional fields, new endpoints) may be added to the existing version
- Deprecated endpoints must be documented with a sunset date and replacement route
