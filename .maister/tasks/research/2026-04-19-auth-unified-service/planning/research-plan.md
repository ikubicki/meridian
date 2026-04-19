# Research Plan: Unified Auth Service (AuthN + AuthZ)

## Research Overview

**Research Question**: How should `phpbb\auth` be designed as a unified service handling both AuthN and AuthZ, with stateless JWT tokens and user/group token elevation model?

**Research Type**: Mixed (technical codebase analysis + architecture design)

**Scope**: JWT token design, user/group token elevation, ACL integration with bitfield system, REST API subscriber chain, login/logout flows, key management, CSRF protection

**Boundaries**: Excludes OAuth2 provider, social login, 2FA, frontend storage, admin panel UI

---

## Methodology

### Primary Approach
1. **Legacy codebase analysis** — Understand current AuthN (session, login_box, providers) and AuthZ (acl_* methods, bitfield cache, role system)
2. **Prior research synthesis** — Reconcile Auth HLD (ACL-only), Users HLD (group membership), REST API HLD (subscriber chain), cross-cutting assessment (JWT vs DB contradiction)
3. **Library API review** — Map `firebase/php-jwt` capabilities to design requirements
4. **Architecture design derivation** — Synthesize JWT token structure, elevation flow, ACL claim embedding from gathered data

### Fallback Strategies
- If bitfield system is too complex for JWT claims → investigate permission digest/hash approach
- If prior research has unresolvable contradictions → flag for user decision
- If firebase/php-jwt lacks needed features → identify extension points

### Analysis Framework
- **AuthN flow mapping**: login → credential verification → token issuance → token validation → refresh → revocation
- **AuthZ integration**: JWT claims → permission resolution → ACL check (without DB hit)
- **Elevation model**: user token → elevation request → group token → admin endpoint access
- **Security boundaries**: signing keys, token expiry, CSRF, replay protection

---

## Research Phases

### Phase 1: Broad Discovery
- Identify all auth-related files (providers, session, ACL class)
- Map database schema for `phpbb_acl_*`, `phpbb_groups`, `phpbb_user_group` tables
- Locate firebase/php-jwt API surface
- Catalog all prior research outputs relevant to this design

### Phase 2: Targeted Reading
- Read auth provider interface and DB provider (credential verification logic)
- Read session.php (session creation, validation, destruction)
- Read auth.php ACL methods (acl_get, acl_getf, acl_gets, acl_cache, bitfield handling)
- Read prior HLDs for auth-service, users-service, rest-api (subscriber chain, ADRs)
- Read cross-cutting assessment and reality-check for contradiction details

### Phase 3: Deep Dive
- Trace login flow: ucp.php → login_box → provider→login() → session_create
- Understand ACL prefetch: how bitfields are loaded, cached, and queried
- Analyze group-to-permission mapping: phpbb_acl_groups → roles → options
- Map firebase/php-jwt encode/decode/verify API and key types supported
- Identify what claims can encode permission state without per-request DB queries

### Phase 4: Verification
- Cross-reference ACL bitfield size vs JWT payload size limits
- Validate that elevation model covers all ACP/MCP access patterns
- Confirm firebase/php-jwt supports required algorithms (RS256/ES256)
- Verify subscriber chain priorities (AuthN@16, AuthZ@8) are consistent with prior REST API design

---

## Gathering Strategy

### Instances: 5

| # | Category ID | Focus Area | Tools | Output Prefix |
|---|------------|------------|-------|---------------|
| 1 | legacy-auth | Current phpBB authentication: providers, session management, login flows, password hashing | Grep, Read | legacy-auth |
| 2 | legacy-acl | Current phpBB ACL system: acl_* methods, bitfield cache, role system, permission tables | Grep, Read | legacy-acl |
| 3 | prior-research | All prior research outputs: auth-service HLD/ADRs, users-service HLD, rest-api HLD, cross-cutting, reality-check | Read | prior-research |
| 4 | jwt-patterns | firebase/php-jwt library API, JWT standards (RFC 7519), token refresh, revocation, key management | Read, WebFetch | jwt-patterns |
| 5 | group-acl-model | phpBB group system, group types, group-to-permission mapping, ACP/MCP permission assignment | Grep, Read | group-acl-model |

### Rationale
The research spans five distinct knowledge domains that can be investigated independently:
- **legacy-auth** and **legacy-acl** are separate subsystems in the codebase (different classes, different concerns)
- **prior-research** is document-heavy synthesis work that doesn't overlap with live code reading
- **jwt-patterns** is external library/standards focused — no overlap with phpBB code
- **group-acl-model** bridges groups (user-service domain) with permissions (auth-service domain) — distinct from the raw ACL methods

---

## Success Criteria

1. ✅ JWT token structure defined for both user tokens and group tokens (claims, expiry, signing)
2. ✅ Complete authentication flow documented: login → token issuance → validation → refresh → revocation
3. ✅ Token elevation flow specified: user token → group token request → elevated access
4. ✅ ACL integration strategy that reuses existing bitfield system without per-request DB queries
5. ✅ All contradictions from prior research resolved (JWT vs DB tokens, AuthN ownership gap)
6. ✅ Concrete interface definitions with method signatures for the unified auth service
7. ✅ Security analysis: CSRF protection, replay attack mitigation, key rotation strategy

---

## Expected Outputs

1. **Research Report** (`outputs/research-report.md`) — Complete findings organized by research area
2. **High-Level Design** (`outputs/high-level-design.md`) — Unified auth service architecture with:
   - JWT token structure (user + group)
   - Service interfaces and class diagram
   - Authentication flow sequences
   - Elevation flow sequences
   - ACL claim resolution strategy
3. **Decision Log** (`outputs/decision-log.md`) — ADRs for key design choices:
   - ADR: JWT signing algorithm selection
   - ADR: Permission encoding in JWT (bitfield digest vs permission list)
   - ADR: Token refresh strategy (sliding window vs explicit refresh)
   - ADR: Revocation without server state
   - ADR: Group token scope and lifetime
