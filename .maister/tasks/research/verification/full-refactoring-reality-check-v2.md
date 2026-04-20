# Full Refactoring Reality Assessment V2: phpBB3 → Modern Service Architecture

**Date**: 2026-04-20 (evening)
**Scope**: Delta assessment from V1 (earlier today) + complete readiness verification
**Previous**: `full-refactoring-reality-check.md` (V1, 2026-04-20 morning)
**Assessor**: Reality assessor agent (fresh independent assessment)

---

## Status: ⚠️ CONDITIONAL GO — Can Start `phpbb\common` + Database + Cache Tomorrow, But Infrastructure Day Zero Is Required First

**Bottom line**: Since the V1 assessment this morning, **5 decision documents** (D9–D13) were added, resolving the last major architectural ambiguities. All critical decisions are now made. However, **zero infrastructure was enacted** — composer.json still requires PHP 7.2 and Symfony 3.4, no phpunit.xml exists, no tests exist, no CI exists. The project advanced from "85% decisions made" to "~95% decisions made" — but remains at 0% implementation infrastructure. You CANNOT open your IDE tomorrow and start writing a service. You need a half-day infrastructure sprint first.

---

## A. DECISIONS COMPLETENESS — ~95% (up from ~71%)

### What Was Decided Since V1

| Decision | ID | Status | Impact |
|---|---|---|---|
| Migration Strategy (big bang + scripts) | D9 | ✅ New | Unblocks go-live planning |
| Frontend Strategy (React SPA) | D10 | ✅ New | Unblocks Frontend development |
| Testing Strategy (PHPUnit + Playwright) | D11 | ✅ New | Unblocks test infrastructure |
| Auth Consolidation (Unified supersedes old) | D12 | ✅ New | Eliminates ambiguity |
| Symfony Version (8.x) | D13 | ✅ New | Sets framework target |

### Remaining Undecided (~5%)

| Decision Area | Status | Blocking? |
|---|---|---|
| Content Formatting Plugins | ❌ Unresearched | NO — not needed until Threads ContentPipeline phase |
| Configuration Service | ❌ Unresearched | NO — can use Symfony 8.x config initially |
| Moderation Service | ❌ Unresearched | NO — can implement after core services |
| Admin Panel | ❌ Unresearched | NO — can implement last |
| Plugin ↔ Database Service schema integration | ⚠️ Undefined | NO — Plugin System is Phase 8+ |

**Verdict**: All decisions needed to START implementation are made. The remaining gaps are Phase 3+ services that aren't on the critical path.

---

## B. PREVIOUS GAP VERIFICATION — Status of Every Known Issue

### From V1 (earlier today)

| Gap | V1 Status | V2 REALITY | Evidence |
|---|---|---|---|
| **B.1** Auth Service Overlap | Claimed RESOLVED | ✅ **Confirmed RESOLVED** | `SUPERSEDED.md` exists at `2026-04-18-auth-service/`. D12 decision documented. services-architecture.md updated. |
| **B.2** Migration Strategy | Claimed RESOLVED | ✅ **Confirmed RESOLVED** | D9 in cross-cutting-decisions-plan.md defines big bang + 2 migration scripts. Clear and actionable. |
| **B.3** 19 Doc Inconsistencies | No claim of resolution | ❌ **STILL OPEN — ALL 19** | V2 audit file is untouched. No HLD file was modified. The findings are documented but zero fixes applied. |
| **B.4** Frontend Strategy | Claimed RESOLVED | ✅ **Confirmed RESOLVED** | D10 defines React SPA. tech-stack.md updated. vision.md updated. |
| **B.5** Testing Strategy | Claimed RESOLVED | ⚠️ **DECISION resolved, INFRASTRUCTURE not** | D11 says "PHPUnit 10+ and Playwright" but: no phpunit.xml, no test directory, no playwright.config.ts, composer.json still requires phpunit ^7.0 |
| **B.6** Content Formatting Plugins | No claim | ❌ **STILL OPEN** | No research, no HLD, no decision. Pipeline in Threads HLD is a shell awaiting plugin implementations. |
| **B.7** Stale Cross-Cutting Assessment | No claim | ❌ **STILL OPEN** | F13 confirms: stale Mermaid diagram, "NOT RESEARCHED" labels for User Service, resolved TODOs shown as open |

### From 2026-04-19 Reality Check

| Gap | Previous Status | V2 REALITY | Evidence |
|---|---|---|---|
| **GAP-3** JWT vs DB Token | ❌ OPEN | ✅ **RESOLVED** | Auth Unified HLD explicitly specifies: JWT HS256 access token (15-min TTL) + opaque SHA-256 refresh token. `TokenServiceInterface` with `issueAccessToken()`, `refresh()`. No ambiguity. |
| **GAP-5** Hierarchy Counter Update API | ❌ OPEN | ✅ **RESOLVED** | D8 changes the pattern entirely: Threads emits events, Hierarchy's `ForumStatsSubscriber` consumes them. No direct API needed. Hierarchy HLD lines 1345-1396 implement the subscriber. |
| **GAP-7** post_text format migration | ❌ OPEN | ✅ **RESOLVED** | D7 decides: keep s9e XML, add `encoding_engine VARCHAR(16) DEFAULT 's9e'`. No bulk migration. ContentPipeline detects format via column. |
| **NEW-1** AuthN/Session ownership | ❌ CRITICAL | ✅ **RESOLVED** | Auth Unified HLD defines `AuthenticationServiceInterface` with `login()`, `logout()`, `elevate()`, `refresh()`. Complete session/token lifecycle. |
| **NEW-2** Plugin ↔ Database integration | ⚠️ HIGH | ⚠️ **STILL OPEN** | No document clarifies whether Plugin's SchemaEngine imports Database service components or independently implements them. Not blocking for Phase 0-4. |
| **NEW-3** No Testing Infrastructure | ⚠️ MEDIUM | ⚠️ **DECISION made, INFRA missing** | D11 defines strategy. Zero infrastructure exists. No phpunit.xml, no tests/, composer requires phpunit ^7.0 (not 10+). |

### Summary: Gap Resolution Scorecard

- **Resolved since 2026-04-19**: 5 gaps (GAP-3, GAP-5, GAP-7, NEW-1, B.1/B.2/B.4)
- **Resolved since V1 today**: 0 new gaps resolved (D9-D13 were the resolution, already captured in V1)
- **Still open**: B.3 (19 docs), B.6 (content plugins), B.7 (stale assessment), NEW-2 (plugin↔DB)
- **Partially resolved**: B.5/NEW-3 (testing: decision yes, infrastructure no)

---

## C. INFRASTRUCTURE READINESS — ❌ NOT READY

This is the most critical finding. Despite excellent decision-making, **nothing was enacted in code or configuration**.

### composer.json — STALE

| Item | Current State | Required State | Gap |
|---|---|---|---|
| PHP requirement | `^7.2 \|\| ^8.0.0` | `^8.2` | ❌ Wrong |
| Platform config | `"php": "7.2"` | `"php": "8.2"` | ❌ Wrong |
| Symfony version | `~3.4` (all components) | `^8.0` | ❌ Wrong |
| PHPUnit | `^7.0` (require-dev) | `^10.0` | ❌ Wrong |
| PSR-4 entries | `phpbb\core\`, `phpbb\`, `phpbb\admin\`, `phpbb\common\`, `phpbb\api\`, `phpbb\install\` | + `phpbb\database\`, `phpbb\cache\`, `phpbb\user\`, `phpbb\auth\`, `phpbb\hierarchy\`, `phpbb\threads\`, `phpbb\messaging\`, `phpbb\notifications\`, `phpbb\storage\`, `phpbb\search\` | ❌ Missing 10 entries |
| `phpbb\common\` autoload target | Points to `src/phpbb/common/` (legacy includes/ stuff) | Should point to NEW common package or coexist | ⚠️ CONFLICT — existing `src/phpbb/common/` contains legacy code (functions.php, startup.php, etc.) |

### Critical `phpbb\common\` Namespace Conflict

**This is a REAL blocker.** The `phpbb\common\` PSR-4 entry maps to `src/phpbb/common/` which currently contains legacy procedural code (`functions.php`, `startup.php`, `functions_posting.php`, `constants.php`, etc.). The new architecture demands `phpbb\common\Exception\*`, `phpbb\common\Event\*`, `phpbb\common\ValueObject\*` here.

**Options**:
1. Move legacy common/ elsewhere (breaking legacy entry points)
2. Place new common package at a different namespace
3. Coexist — put new classes alongside legacy files (messy but functional since PSR-4 resolves by class name)

Since it's big-bang with no coexistence, **Option 1 is correct** — but it's a non-trivial bootstrap step.

### Testing Infrastructure — ZERO

| Item | Exists? |
|---|---|
| phpunit.xml | ❌ No |
| tests/ directory | ❌ No |
| Any .php test file | ❌ No (zero in entire project) |
| playwright.config.ts | ❌ No |
| Base TestCase class | ❌ No |
| Test database fixtures | ❌ No |
| Mock factories | ❌ No |

### CI/CD — ZERO

| Item | Exists? |
|---|---|
| .github/workflows/ at project root | ❌ No |
| Any CI configuration | ❌ No (only a viglink extension has its own tests.yml) |
| Code linting pipeline | ❌ No |
| Static analysis config (phpstan.neon) | ❌ No |

### Docker — MOSTLY OK

| Item | Status |
|---|---|
| PHP 8.2-fpm-alpine | ✅ Running |
| MariaDB 10.11 | ✅ Running |
| Nginx | ✅ Running |
| Redis | ❌ Missing (needed for cache service beyond filesystem) |
| Test database | ❌ Missing |

### Existing Code State (8 API files)

| File | Framework | Symfony Version | Usable For New Architecture? |
|---|---|---|---|
| `src/phpbb/core/Application.php` | Sf HttpKernel | 3.4 API (`HttpKernelInterface`) | ⚠️ Interface compatible, but instantiation tied to Sf 3.4 DI |
| `src/phpbb/api/event/auth_subscriber.php` | Sf EventDispatcher | 3.4 API (`GetResponseEvent`) | ❌ Must rewrite — `GetResponseEvent` removed in Sf 5+ |
| `src/phpbb/api/event/json_exception_subscriber.php` | Sf EventDispatcher | 3.4 API | ❌ Must rewrite |
| `src/phpbb/api/v1/controller/*.php` | Sf HttpFoundation | 3.4 | ⚠️ Potentially salvageable — Request/Response objects still exist in Sf 8 |

**Verdict**: The existing 8 API files are prototypes written against Symfony 3.4 APIs. They will need rewriting for Symfony 8.x. They're useful as reference for route design but not as production code.

---

## D. DOCUMENTATION QUALITY — 19 V2 Findings Still Unfixed. Do They Matter?

### Pragmatic Impact Assessment

| Finding | Severity | Blocks Which Phase? | ACTUALLY matters? |
|---|---|---|---|
| **F2** (Notifications tagged DI residue) | HIGH | Phase 8 (Notifications) | ⚠️ Yes — but Phase 8 is months away |
| **F3** (Threads individual event returns) | HIGH | Phase 6 (Threads) | ⚠️ Yes — interface contract wrong |
| **F5** (Hierarchy individual event returns) | HIGH | Phase 5a (Hierarchy) | ⚠️ Yes — interface contract wrong |
| **F6** (Messaging custom result types) | HIGH | Phase 7 (Messaging) | ⚠️ Yes — interface contract wrong |
| **F1** (Threads raw text comments) | MEDIUM | Phase 6 | Not really — cosmetic comment |
| **F7** (Users void returns) | MEDIUM | Phase 2 | ⚠️ Yes — must fix before starting Users |
| **F8** (Users no cache) | MEDIUM | Phase 2 | ⚠️ Yes — architectural omission |
| **F9** (Users exceptions undoc) | MEDIUM | Phase 2 | Mild — will be detailed in impl spec |
| **F10** (Storage DTO returns) | MEDIUM | Phase 5b | ⚠️ Yes — pattern violation |
| **F11** (Notifications wrong returns) | MEDIUM | Phase 8 | Deferred concern |
| **F12** (Threads forum counters) | MEDIUM | Phase 6 | ⚠️ Yes — code to delete |
| **F13** (Cross-cutting assessment stale) | MEDIUM | Never | No — cosmetic, doesn't affect code |
| **F16** (Messaging events no base class) | MEDIUM | Phase 7 | ⚠️ Yes — must inherit DomainEvent |
| **F18** (Notifications dispatch pattern) | MEDIUM | Phase 8 | Deferred concern |
| **F4** (Threads synthesis stale) | LOW | Never | No — synthesis is historical |
| **F14** (services-arch raw text label) | LOW | Never | No — cosmetic |
| **F15** (Cross-cutting stale sync ref) | LOW | Never | No — cosmetic |
| **F17** (Hierarchy cache.hierarchy missing) | LOW | Phase 5a | Mild — will be added during impl |
| **F19** (Threads Post missing property) | LOW | Phase 6 | ⚠️ Yes — but trivial fix |

### Verdict on 19 Findings

**Fix-as-you-go is acceptable.** The findings don't block starting implementation of Phase 0–1 (Infrastructure, Cache, Database). For Phase 2 (Users), fix F7+F8 first. For each subsequent phase, fix the relevant findings at the start of its implementation spec.

**DO NOT spend a day batch-fixing all 19.** Fix per-phase as you encounter them. The standards documents (DOMAIN_EVENTS.md, COUNTER_PATTERN.md, STANDARDS.md) are the source of truth — HLD inconsistencies are overridden by standards.

---

## E. WHAT ACTUALLY CHANGED SINCE V1 — Delta Assessment

### What Improved (Decisions)

| Change | Impact | Real difference? |
|---|---|---|
| D9: Migration Strategy documented | Know it's big-bang + 2 scripts | ✅ YES — removes ambiguity for go-live planning |
| D10: Frontend = React SPA | Know frontend approach | ✅ YES — eliminates Twig/islands/SSR question permanently |
| D11: Testing = PHPUnit + Playwright | Know testing layers | ⚠️ PARTIALLY — strategy yes, execution zero |
| D12: Auth Unified is authority | Eliminates dual-HLD confusion | ✅ YES — no more "which auth doc?" |
| D13: Symfony 8.x target | Know framework version | ⚠️ PARTIALLY — declared but not enacted in composer.json |
| SUPERSEDED.md added | Old auth clearly marked | ✅ YES — prevents accidental use |
| docs/ updated (vision, roadmap, tech-stack, services-arch) | Consistent documentation | Cosmetic — doesn't change code |

### What Stayed The Same

| Item | V1 State | V2 State | Delta |
|---|---|---|---|
| Production service code | 0 files | 0 files | **Zero** |
| Test files | 0 | 0 | **Zero** |
| phpunit.xml | Missing | Missing | **Zero** |
| CI/CD | None | None | **Zero** |
| composer.json deps | PHP 7.2 + Sf 3.4 | PHP 7.2 + Sf 3.4 | **Zero** |
| PSR-4 entries for services | 0 new | 0 new | **Zero** |
| HLD inconsistencies fixed | 0/19 | 0/19 | **Zero** |
| `phpbb\common\` conflict | Unaddressed | Unaddressed | **Zero** |
| Redis in Docker | Missing | Missing | **Zero** |

### Honest Delta Summary

**Today's changes were purely documentation/decision changes.** Not a single file that affects buildability, testability, or runnability was modified. The project's ability to compile, run tests, or execute new service code is identical to yesterday.

---

## F. SERVICE-BY-SERVICE IMPLEMENTATION READINESS

### Can a Developer Start Each Service Tomorrow?

| Phase | Service | Research Sufficient? | Ambiguities Blocking? | Dependency Clear? | VERDICT |
|---|---|---|---|---|---|
| 0 | `phpbb\common` package | ✅ D4 defines structure | None | None (foundation) | 🟢 **START** — after infra sprint |
| 1 | Database Service | ✅ 1,394-line HLD, 4-layer arch | None | None (self-contained) | 🟢 **START** — after infra sprint |
| 1 | Cache Service | ✅ 1,096-line HLD | None | None (self-contained) | 🟢 **START** — after infra sprint |
| 2 | User Service | ✅ 921-line HLD, 8 ADRs | F7, F8, F9 (fixable in 30 min) | Needs `phpbb\common` first | 🟡 Fix 3 findings, then START |
| 3 | Auth Unified | ✅ 1,098-line HLD | None critical | Needs User entity | 🟢 After User entities exist |
| 4 | REST API | ✅ 747-line HLD | Existing code is Sf 3.4 — rewrite | Needs Auth for subscriber | 🟡 Rewrite required, not extend |
| 5a | Hierarchy | ✅ 2,345-line HLD | F5, F17 (30 min fix) | None | 🟡 Fix 2 findings, then START |
| 5b | Storage | ✅ 2,057-line HLD | F10 (pattern question) | None | 🟡 Fix 1 finding, then START |
| 6 | Threads | ✅ 2,102-line HLD | F1, F3, F12, F19 (1 hour fix) | Needs Hierarchy events | 🟡 Most HLD fixes needed |
| 7 | Messaging | ✅ 1,055-line HLD | F6, F16 (45 min fix) | Needs Storage | 🟡 Fix 2 findings, then START |
| 8 | Notifications | ✅ 1,532-line HLD | F2, F11, F18 (1 hour fix) | Needs all event sources | 🟡 Significant fixes, last anyway |
| 8+ | Search | ✅ 946-line HLD | None significant | Needs Threads | 🟢 After Threads |
| 8+ | Plugin System | ✅ 1,178-line HLD | NEW-2 (DB integration) | Needs Database | 🟡 Clarify integration first |
| — | Config Service | ❌ No research | — | — | 🔴 Research needed |
| — | Moderation | ❌ No research | — | — | 🔴 Research needed |
| — | Admin Panel | ❌ No research | — | — | 🔴 Research needed |

---

## G. THE HONEST INFRASTRUCTURE SPRINT — What Must Happen Before Line 1

These are the EXACT steps needed before writing the first production service class:

### Day Zero Tasks (4-6 hours)

| # | Task | Why | Effort |
|---|---|---|---|
| **1** | Update `composer.json`: `"php": "^8.2"`, platform `"php": "8.2"` | Can't use enums, readonly, match without 8.2 baseline | 5 min |
| **2** | Replace Symfony `~3.4` with `^8.0` OR add new `symfony/*: ^8.0` deps alongside (see note below) | New code needs modern EventDispatcher, DI, HttpKernel | 30 min + `composer update` |
| **3** | Replace `"phpunit/phpunit": "^7.0"` with `"^10.0"` in require-dev | D11 requires PHPUnit 10+ attributes | 5 min |
| **4** | Resolve `phpbb\common\` namespace conflict — move legacy common/ to `src/phpbb/legacy/` or rename PSR-4 entry | New `phpbb\common\Exception\*` needs this namespace | 30-60 min |
| **5** | Add PSR-4 entries for `phpbb\database\`, `phpbb\cache\` → `src/phpbb/database/`, `src/phpbb/cache/` | Autoloading for first services | 5 min |
| **6** | Create `phpunit.xml` at project root | Test runner needs configuration | 15 min |
| **7** | Create `tests/` directory + `BaseTestCase.php` | All service tests extend this | 15 min |
| **8** | Add Redis to `docker-compose.yml` | Cache service needs a real backend to test | 10 min |
| **9** | Run `composer update` and fix breakages | Validate dependency resolution | 30-60 min |

**CRITICAL NOTE on Step 2 (Symfony upgrade)**: This is the biggest risk. Symfony 3.4 → 8.0 is a 5-major-version jump. The legacy entry points (`web/app.php`, `web/index.php`, etc.) deeply depend on Symfony 3.4 DI container and HttpKernel. 

**Options**:
- **A) Clean break**: Delete all Symfony 3.4 deps, add 8.0. Legacy web/ entry points will break. Acceptable since it's big-bang.
- **B) Parallel**: Keep Sf 3.4 for legacy, add Sf 8.0 with different package names. Impossible — Composer can't have two versions of the same package.
- **C) Gradual**: Upgrade 3.4 → 4.4 → 5.4 → 6.4 → 7.4 → 8.0. Insane for a rewrite.

**Recommendation: Option A.** Since D9 is "big bang, no coexistence" — the legacy entry points are dead code anyway. Rip out Sf 3.4, install Sf 8.0, accept that `web/*.php` files no longer function. New services don't use them.

---

## H. DEPLOYMENT DECISION

### ⚠️ CONDITIONAL GO — Start After Infrastructure Sprint

```
Can I start writing production service code tomorrow morning?
├─ As-is, right now?
│  └─ ❌ NO — composer.json won't even resolve for new code
├─ After 4-6 hour infrastructure sprint?
│  └─ ✅ YES — Database + Cache + phpbb\common can begin
├─ For ALL services?
│  └─ ⚠️ NO — fix relevant HLD findings per-phase (lightweight, 15-60 min each)
├─ For production deployment?
│  └─ ❌ NO — months of implementation ahead, zero code exists
```

### Clear Signal

| Question | Answer |
|---|---|
| Are all critical architectural decisions made? | ✅ YES (95%+ — remainder doesn't block) |
| Is the research quality sufficient to start coding? | ✅ YES — 43K+ lines, concrete interfaces, SQL schemas, method signatures |
| Is the development environment ready? | ❌ NO — needs infrastructure sprint |
| Can implementation start after infra sprint? | ✅ YES — Database, Cache, phpbb\common immediately |
| Are 19 doc inconsistencies blocking? | ❌ NO — fix per-phase, standards are source of truth |
| Is there any remaining ambiguity that blocks development? | ❌ NO — for Phase 0-3 (which is months of work) |

---

## I. REALISTIC NEXT 5 STEPS — Exact Actions

### Step 1: Infrastructure Sprint (Day 0 — half day)

Execute the 9 tasks from section G. This results in:
- Modern composer.json (PHP 8.2, Symfony 8.x, PHPUnit 10)
- Working autoload for `phpbb\common\`, `phpbb\database\`, `phpbb\cache\`
- Empty phpunit.xml + tests/ structure
- Redis in Docker

### Step 2: Build `phpbb\common` Package (2-3 days)

Create the shared foundation all services depend on:
- `phpbb\common\Exception\*` (6 exception classes with HTTP mapping)
- `phpbb\common\Event\DomainEvent` + `DomainEventCollection`
- `phpbb\common\ValueObject\UserId`, `Pagination`
- `phpbb\common\Contract\*`
- **With full PHPUnit tests** — establish the testing pattern here

### Step 3: Build Database Service (1-2 weeks)

Implement the 4-layer database service:
- `ConnectionInterface` (5 methods, lazy PDO, table prefix, driver detection)
- `TransactionManager`
- Schema management (for migrations)
- **With full PHPUnit tests** against test MariaDB

### Step 4: Build Cache Service (1 week)

Implement PSR-16 tag-aware cache:
- `TagAwareCacheInterface` implementation
- Filesystem adapter (default)
- Redis adapter (optional backend)
- Pool factory (`cache.database`, `cache.auth`, etc.)
- **With full PHPUnit tests** against Redis

### Step 5: Fix Users HLD + Create Implementation Spec (1 day)

Before starting User Service code:
- Fix F7 (void → DomainEventCollection)
- Fix F8 (add TagAwareCacheInterface)
- Fix F9 (document exception hierarchy)
- Write fresh implementation spec (aligned with current architecture)
- Begin User Service implementation

---

## J. WHAT THE PREVIOUS V1 GOT WRONG

The V1 assessment (this morning) was accurate in its content but slightly optimistic in framing:

1. **V1 said "Ready to Begin Individual Service Implementation"** — technically true but misleading. You can't write a PHP class that uses enums when composer.json requires PHP 7.2. The infrastructure isn't ready.

2. **V1 said B.1, B.2 "RESOLVED"** — correct that decisions were made, but the word "resolved" implies enacted. They're decided, not resolved in code.

3. **V1 said "2 services ready immediately (Database, Cache)"** — only true AFTER infrastructure sprint. Without it, there's no autoload, no test runner, no correct Symfony version.

4. **V1's Tier 0 action plan is correct** — but it wasn't executed. Someone reading V1 might think it was done.

---

## K. RISK REGISTER — What Could Still Derail This

| Risk | Probability | Impact | Mitigation |
|---|---|---|---|
| Symfony 8.0 not released / unstable | HIGH (8.0 is unreleased as of real-world 2024) | HIGH — must use 7.x instead | Use `^7.2` or latest stable, not 8.x |
| Composer dependency conflicts during upgrade | MEDIUM | MEDIUM — could eat a full day | Spike first: fresh `composer.json` with only new deps |
| `phpbb\common\` namespace conflict messier than expected | MEDIUM | LOW — half day | Option: use `phpbb\shared\` namespace instead |
| Solo developer burnout at 8-12 month timeline | HIGH | CRITICAL | Ship vertical slices (e.g., working forum read-only) not horizontal layers |
| HLD findings proliferate during implementation (designs don't survive contact with code) | HIGH | MEDIUM | Accept HLDs are guides, not contracts. Deviate when code reality differs. |

---

## L. SUMMARY — One Paragraph

The phpBB-vibed project completed an extraordinary research and decision phase — 15 services architecturally designed across 43K+ lines, 13 cross-cutting decisions made, all critical ambiguities resolved. But the gap between "decided" and "ready to code" remains: composer.json is locked to PHP 7.2/Symfony 3.4, there are zero tests, zero CI, and a namespace conflict on `phpbb\common\`. One focused infrastructure sprint (half day) bridges this gap. After that, Database Service, Cache Service, and `phpbb\common` can begin immediately. The 19 documentation inconsistencies are NOT blockers — fix them per-phase as you encounter them. The honest answer: **you need 4-6 hours of unglamorous plumbing work before the first real service class can exist.**

---

*Assessment based on reading all 52 research documents, 5 verification reports, actual code files, composer.json, Docker configuration, and complete project filesystem inspection. No code was modified during this assessment.*
