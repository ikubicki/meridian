# Research Plan: Content Plugin Injection Architecture

## Research Overview

**Research question**: How to inject content-type plugins (bbcode, markdown, smiles, censor) to process content before saving or before sending in responses?

**Research type**: Mixed — technical codebase analysis + Symfony architectural pattern research

**Scope**:
- Pre-save content processing (controller / service layer)
- Pre-response transformation (serializer / DTO mapping layer)
- Plugin registration mechanism (tagged DI services, EventDispatcher, registry)
- phpBB 3.x processing pipeline (pattern reference)
- Extensibility without touching core

**Out of scope**: Frontend rendering (React), raw HTML/CSS styling

---

## Methodology

### Primary approach
Codebase analysis of the existing phpbb4 plugin patterns (`ForumBehaviorRegistry`, `TypeRegistry`), followed by mapping how Symfony 8.x tagged services / `#[AutoconfigureTag]` can implement a `ContentProcessorPipelineInterface`.

### Fallback
If tagged DI is insufficient (e.g. ordering constraints), fall back to EventDispatcher pattern already used by `TypeRegistry` / `RegisterNotificationTypesEvent`.

### Analysis framework

**Component identification**
- Which service/layer owns content at write-time (pre-save)?
- Which service/layer owns content at read-time (pre-response)?

**Pattern recognition**
- phpBB 3.x: two-stage pipeline (`generate_text_for_storage` → save; `generate_text_for_display` → render)
- phpBB4 existing: `ForumBehaviorRegistry.register()` (manual push); `TypeRegistry` (event-dispatch lazy init)

**Flow analysis**
- Write path: `Request body → Controller → CreatePostRequest → ThreadsService → Repository`.  
  Where to inject: between Controller and CreatePostRequest (sanitize/validate) or inside ThreadsService before `$this->postRepository->insert(...)`.
- Read path: `Repository → Entity → DTO → Controller::postToArray() → JsonResponse`.  
  Where to inject: inside `postToArray()` or in a dedicated `ContentRenderingPipeline` called from `postToArray()`.

**Integration mapping**
- `services.yaml` already has `autoconfigure: true` / `autowire: true` globally.
- `tagged_iterator` / `#[AutoconfigureTag]` are available without extra compiler passes.
- `EventDispatcherInterface` is already injected into all controllers and some services.

---

## Research Phases

### Phase 1 — Broad discovery (codebase)
**Goal**: Catalogue all current plugin/hook/event points and content-bearing paths.

Actions:
1. Find all classes in `src/phpbb/` that implement or reference a Registry, Pipeline, or Plugin interface.
2. Trace the complete write path for Post and Message content (controller → service → repository).
3. Trace the complete read path for Post and Message content (repository → DTO → controller → JSON).
4. Find every place `content`, `text`, `body` fields traverse the phpbb4 layer.

### Phase 2 — Pattern reference (phpBB 3.x)
**Goal**: Understand the two-stage phpBB3 content pipeline pattern and what hooks are available.

Actions:
1. Read `src/phpbb3/common/functions_content.php`: `generate_text_for_storage()`, `generate_text_for_display()`, `censor_text()`, `smiley_text()`.
2. Read `src/phpbb3/common/message_parser.php`: how BBCode UID/bitfield/flags are computed and stored.
3. Read `src/phpbb3/common/bbcode.php` (if present): BBCode class structure.
4. Understand what metadata (uid, bitfield, flags) phpBB3 stores alongside raw text and why.

### Phase 3 — Symfony pattern research
**Goal**: Identify the canonical Symfony 8 pattern for ordered tagged-service pipelines.

Actions:
1. Investigate Symfony `#[AutoconfigureTag]`, `tagged_iterator`, `$priority` attribute.
2. Investigate `Symfony\Component\HttpKernel\KernelEvents::VIEW` / `KernelEvents::RESPONSE` for pre-response transformation subscribers.
3. Investigate `Symfony\Component\Serializer` normalizer chain as an analogy for content rendering pipeline.
4. Assess `EventSubscriberInterface` vs. tagged service for this use case.

### Phase 4 — Verification & recommendation
**Goal**: Cross-reference findings, resolve open questions, produce architecture recommendation.

Actions:
1. Verify that the recommended injection point does not violate existing tests or contracts.
2. Confirm that adding a new processor (e.g. markdown) requires zero changes to core service classes.
3. Confirm pre-save and pre-response pipelines can behave differently (e.g. censor runs pre-save; smilies render pre-response).
4. Draft recommended interface (`ContentProcessorInterface`) and service names.

---

## Gathering Strategy

### Instances: 5

| # | Category ID | Focus Area | Tools | Output Prefix |
|---|------------|------------|-------|---------------|
| 1 | codebase-write-path | Trace content write path: controllers → services → repositories for Post & Message | Grep, Read | write-path |
| 2 | codebase-read-path | Trace content read path: DTOs → controller serialization → JSON for Post & Message | Grep, Read | read-path |
| 3 | codebase-plugin-patterns | Existing phpbb4 plugin/registry/event registration patterns (ForumBehaviorRegistry, TypeRegistry, notifications) | Grep, Read | plugin-patterns |
| 4 | phpbb3-pipeline | phpBB 3.x two-stage content pipeline: generate_text_for_storage, generate_text_for_display, censor_text, smiley_text, message_parser | Read | phpbb3-pipeline |
| 5 | symfony-patterns | Symfony 8 tagged DI (tagged_iterator, AutoconfigureTag, priority), KernelEvents::VIEW, serializer normalizer chain | WebSearch, WebFetch | symfony-patterns |

### Rationale
- **5 instances** because the research spans two distinct codebases (phpbb4, phpbb3) plus external Symfony docs, and the write vs. read paths need separate analysis to avoid conflating pre-save with pre-response concerns.
- `codebase-write-path` and `codebase-read-path` are split to avoid confusion between storage format (uid/bitfield) and rendered output format (HTML/JSON).
- `codebase-plugin-patterns` focuses purely on existing extension mechanisms to discover what patterns are already established in this project.

---

## Success Criteria

1. Both content touch-points identified (pre-save in service layer, pre-response in DTO→JSON mapping).
2. Best registration mechanism selected and justified (tagged DI vs. event-dispatch vs. manual registry).
3. phpBB3 two-stage pipeline understood well enough to replicate split responsibilities in phpBB4.
4. At least one concrete Symfony 8 pattern (with code sketch) recommended for the processor interface.
5. Extension point confirmed: adding a new processor (e.g. markdown) requires only: implement interface + register service tag — zero changes to core.
6. Open questions answered: should censor run pre-save or pre-response? Can smilies be deferred to pre-response only?

---

## Expected Outputs

| File | Description |
|------|-------------|
| `analysis/findings/write-path-*.md` | Write-path analysis per gatherer |
| `analysis/findings/read-path-*.md` | Read-path analysis |
| `analysis/findings/plugin-patterns-*.md` | Existing plugin pattern analysis |
| `analysis/findings/phpbb3-pipeline-*.md` | phpBB3 reference pipeline analysis |
| `analysis/findings/symfony-patterns-*.md` | Symfony 8 pattern reference |
| `outputs/architecture-recommendation.md` | Final recommended architecture with code sketches |
| `outputs/open-questions.md` | Any remaining ambiguities for implementation |
