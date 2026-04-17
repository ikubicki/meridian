# REST API Standards

Conventions for the `src/phpbb/api/` layer (phpBB Vibed REST API, PHP 8.3).

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
Use a top-level key matching the resource name (plural for collections, singular for single items):

```json
// Collection:
{ "forums": [...], "total": 3 }

// Single resource:
{ "topic": { "id": 1, "title": "..." } }

// Created resource:
{ "user": { "id": 100, "username": "alice" }, "token": "eyJ..." }
```

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

```json
{
    "user_id": 2,
    "username": "admin",
    "admin": true,
    "iat": 1700000000,
    "exp": 1700003600
}
```

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

- Controllers are plain PHP classes — no base class required for simple controllers
- Constructor injection via Symfony DI; use `readonly` constructor promotion for dependencies
- One public action method per route (not `__invoke` unless the controller has only one route)
- Method names must match the route action: `index()`, `show(int $id)`, `create()`, `update()`, `delete()`
- Controllers must not contain business logic — delegate to service classes when logic grows

```php
namespace phpbb\api\v1\controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class ForumsController
{
    public function __construct(
        private readonly ForumService $forumService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $forums = $this->forumService->listAll();
        return new JsonResponse(['forums' => $forums, 'total' => count($forums)]);
    }

    public function show(int $id): JsonResponse
    {
        $forum = $this->forumService->findById($id);
        if ($forum === null) {
            return new JsonResponse(['error' => 'Forum not found', 'status' => 404], 404);
        }
        return new JsonResponse(['forum' => $forum]);
    }
}
```

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
