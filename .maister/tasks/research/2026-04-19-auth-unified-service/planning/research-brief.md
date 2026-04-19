# Research Brief: Unified Auth Service (AuthN + AuthZ)

## Research Question

How should `phpbb\auth` be designed as a unified service responsible for both authentication and authorization, using stateless JWT tokens with a user/group token elevation model?

## Background

The cross-cutting assessment and reality check identified a critical gap: no service owns the authentication layer. The Auth research (2026-04-18) designed only authorization (ACL). The User research (2026-04-19) deferred authentication to Auth. This circular deferral left the entire AuthN layer — login, sessions, tokens — undesigned.

Additionally, there's a JWT vs DB token contradiction:
- Auth HLD references "Bearer JWT" throughout (20+ mentions)
- REST API ADR-002 defines DB-backed opaque tokens (`phpbb_api_tokens` table)

The user has now made the decision: **JWT-based stateless tokens**, resolving this contradiction.

## Key Design Decisions (User-Provided)

1. **Stateless JWT sessions** — no per-request DB lookups for authentication
2. **Two token types**:
   - **User token**: Contains user_id, basic claims. For standard forum operations.
   - **Group token**: Contains user_id + group_id. Required for ACP/MCP admin endpoints.
3. **Token elevation**: User holding a user token must explicitly obtain a group token to access admin endpoints. This is a deliberate security boundary.
4. **Unified service**: `phpbb\auth` handles both AuthN and AuthZ (not split across services)

## Research Type

**Mixed** — Technical codebase analysis + architecture design

## Scope

### In Scope
- JWT token structure, claims, signing
- Token issuance (login), validation, refresh, revocation
- User token ↔ group token elevation flow
- ACL/permission resolution from JWT claims
- Integration with existing `phpbb_acl_*` tables and bitfield system
- REST API subscriber chain (AuthN priority 16, AuthZ priority 8)
- Stateless session management
- CSRF protection strategies for JWT
- Login/logout flows
- Token blacklisting/revocation without server-side sessions
- Key management (signing keys)

### Out of Scope
- OAuth2/OpenID Connect provider
- Social login providers
- Two-factor authentication
- Frontend token storage implementation
- Admin panel UI

### Constraints
- Must use `firebase/php-jwt` (already in vendor)
- Must work with existing `phpbb_acl_*` tables
- PHP 8.2+, PSR-4 namespaces under `phpbb\auth\`
- Symfony DI container, EventDispatcher
- Subscriber priorities: AuthN=16, AuthZ=8

## Success Criteria

1. Clear JWT token structure for both user and group tokens
2. Complete authentication flow (login → token → validation → refresh/revocation)
3. Token elevation flow (user token → group token for admin)
4. ACL integration that reuses existing bitfield system without per-request DB queries
5. Resolution of all contradictions from prior Auth and REST API research
6. Concrete interface definitions and method signatures

## Prior Research References

- `2026-04-18-auth-service/` — ACL-only authorization design (reuse ACL parts)
- `2026-04-18-hierarchy-service/` — Forum permission context
- `2026-04-16-phpbb-rest-api/` — REST API subscriber chain, DB token design (superseded)
- `2026-04-19-users-service/` — User entity, group membership
- `cross-cutting-assessment.md` — Identified JWT vs DB token contradiction
- `verification/reality-check.md` — Identified AuthN circular deferral gap
