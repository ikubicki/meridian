# Synthesis: Content Plugin Injection Architecture — phpBB4

**Research question**: How to inject content-type plugins (bbcode, markdown, smiles, censor) that
process content before saving or before sending in responses — with MINIMAL configuration for plugin
authors, ideally zero core file modifications required?

**Sources synthesised**: 5 findings files (write-path, read-path, plugin-patterns, phpbb3-pipeline,
symfony-patterns)
**Date**: 2026-04-26

---

## 1. Cross-Source Analysis

### 1.1 Validated findings (confirmed by ≥ 2 independent sources)

| Finding | Confirmed by | Confidence |
|---------|-------------|-----------|
| Content is stored RAW in `phpbb_posts.post_text` with no transformation | write-path, read-path | **High** |
| `PostsController::postToArray()` is the single output serialization chokepoint | read-path, write-path (response path) | **High** |
| `ThreadsService::createPost()` / `updatePost()` are the only write-path entry points before DB | write-path | **High** |
| `autoconfigure: true` + `autowire: true` are global DI defaults | symfony-patterns, read-path (services.yaml note) | **High** |
| `#[AutoconfigureTag]`, `#[AsTaggedItem]`, `#[AutowireIterator]` are in vendor Symfony 8 | symfony-patterns | **High** |
| No tagged_iterator / AutoconfigureTag used anywhere in `src/` | symfony-patterns, plugin-patterns | **High** |
| Censor in phpBB3 is display-only (never stored) | phpbb3-pipeline | **High** |
| Smilies in phpBB3 are hybrid: placeholder stored, img rendered at display | phpbb3-pipeline | **High** |
| BBCode in phpBB3 spans both stages: parsed (intermediate) at save, rendered at display | phpbb3-pipeline | **High** |
| `EventSubscriberInterface` priority is used in auth subscribers (priority 16 / 8) | symfony-patterns | **High** |

### 1.2 Contradictions resolved

**Contradiction**: `symfony-patterns` findings recommended Approach A (RegisterContentMiddlewareEvent)
as "more consistent with the codebase." The user's architectural constraint, however, explicitly rules
out this approach because the event-listener registration pattern requires one `services.yaml` entry
**per plugin** — which is a core-file modification that plugin authors must make.

**Resolution**: Approach B (`#[AutoconfigureTag]` on the interface + `#[AutowireIterator]` in the
pipeline) is the correct choice given the stated constraint. The `symfony-patterns` findings themselves
document this approach as fully viable under Symfony 8 and the global `autoconfigure: true` setting.
The recommendation for Approach A was predicated on consistency with existing patterns — but did NOT
account for the zero-core-modification plugin author requirement.

### 1.3 Confidence assessment

- **High confidence**: injection points, raw storage, Symfony attribute availability, phpBB3 stage
  boundaries
- **Medium confidence**: exact `ContentStage` enumeration values and whether "both stages" is needed
  for any built-in plugin (phpBB4 could simplify by storing raw and only rendering at output)
- **Low confidence**: whether `#[AsTaggedItem]` priority ordering is sufficient without additional
  metadata (e.g., a `stage()` method vs a `supportsStage()` predicate)

---

## 2. Pattern Analysis

### 2.1 The two existing plugin patterns in phpBB4

| Pattern | Where | Ordering | Core-file change per plugin | Verdict |
|---------|-------|----------|---------------------------|---------|
| Manual push registry (`ForumBehaviorRegistry`) | hierarchy module | None | services.yaml entry required | Not suitable |
| Event-dispatched lazy registry (`TypeRegistry`, `MethodManager`) | notifications module | Via `priority:` in YAML tag | services.yaml entry required | Ruled out by constraint |
| **tagged_iterator + `#[AutoconfigureTag]`** | **NEW — not yet in src/** | `#[AsTaggedItem(priority: N)]` on class | **None — auto-discovered** | **RECOMMENDED** |

### 2.2 Why tagged_iterator wins for this use case

The key differentiator is **who pays the configuration cost**:

- Event pattern: core phpBB4 has the event class + registry. Each plugin author must add a
  `kernel.event_listener` YAML tag in their own services.yaml. Adds one tag per plugin, but that
  tag lives in the plugin's own config — BUT unless the plugin has a Bundle that auto-loads its
  DI configuration, this still requires the core `services.yaml` to include it.
  
- tagged_iterator: core phpBB4 puts `#[AutoconfigureTag('phpbb.content_plugin')]` on the interface.
  The `autoconfigure: true` global default means ANY class in the container that implements the
  interface is **automatically** tagged `phpbb.content_plugin` — no YAML tag required, not even
  in the plugin's own config file, as long as the plugin's service is registered (via bundle or
  resource scan).

### 2.3 phpBB3 pipeline stages → phpBB4 mapping

phpBB3 used a two-stage model for good reasons (intermediate formats, bitfield tracking). For phpBB4
REST API, a **simplified two-stage model** without intermediate formats is recommended:

| phpBB3 concept | phpBB3 stage | phpBB4 stage | Notes |
|---------------|-------------|-------------|-------|
| BBCode parser (→UID-tagged intermediate) | PRE_SAVE | PRE_SAVE | phpBB4: parse/validate; OR skip and do all at PRE_OUTPUT |
| BBCode renderer (UID-tags → HTML) | PRE_OUTPUT | PRE_OUTPUT | Converts stored markup to HTML |
| Smilies code → `{SMILIES_PATH}` placeholder | PRE_SAVE | **PRE_OUTPUT only** | phpBB4 simplification: store raw emoticon code |
| Smilies placeholder → `<img>` | PRE_OUTPUT | PRE_OUTPUT | Part of smilies expansion |
| Word censor | PRE_OUTPUT only | PRE_OUTPUT only | Never stored — adapts to word-list changes |
| Markdown | N/A | PRE_OUTPUT only | Store raw MD, render at read time |
| Magic URLs | PRE_SAVE + PRE_OUTPUT | PRE_SAVE optional | Could pre-process at save for search indexing |

**Key simplification for phpBB4**: Since the API stores raw content and renders on demand, there is
no intermediate format (no UID/bitfield bookkeeping). The `PRE_SAVE` stage primarily handles
**validation, sanitization, and storage-format normalization** (e.g., stripping disallowed HTML),
while `PRE_OUTPUT` handles **rendering and display-time transforms** (bbcode→HTML, censor, smiley
expansion).

---

## 3. Key Insights

### 3.1 `#[AutoconfigureTag]` on interface = zero-config discovery (HIGH confidence)

When `#[AutoconfigureTag('phpbb.content_plugin')]` is placed on `ContentPluginInterface`, every class
that implements this interface and is registered in the Symfony container will automatically receive
the `phpbb.content_plugin` service tag — no YAML, no event listener, no `register()` call needed.
This is precisely the mechanism Symfony 8 was designed for, and `autoconfigure: true` is already the
global default in this codebase.

**Implication**: A plugin author's minimum work is:
1. `class MyPlugin implements ContentPluginInterface { … }` — triggers auto-tagging
2. Register the service (unavoidable Symfony requirement)

Priority/ordering is OPTIONAL via `#[AsTaggedItem(priority: N)]`.

### 3.2 `postToArray()` is the canonical pre-output injection point (HIGH confidence)

Confirmed by both read-path and write-path findings. All three response paths (GET list, POST create
response, PATCH update response) go through `PostsController::postToArray()`. Injecting a
`ContentPipeline` into `PostsController` and calling it here:
- Covers 100% of read output
- Does not alter DTOs (edit APIs continue to receive raw markup)
- Requires one constructor parameter addition

### 3.3 `ThreadsService` is the canonical pre-save injection point (HIGH confidence)

`ThreadsService::createPost()` and `updatePost()` are the only service methods that call
`postRepository->insert()` / `postRepository->updateContent()`. The content string passes through
unchanged. Injecting the pipeline at the service layer (before repository calls) ensures:
- Search indexer also gets pre-processed content
- No need to modify the repository or entity layers
- Both create and update paths covered by modifying one class

### 3.4 Censor must always be PRE_OUTPUT (HIGH confidence from phpBB3 lessons)

The phpBB3 design decision to **never store censored content** is sound and must be preserved in
phpBB4. Word lists change over time; if censored replacements were stored, old posts would show
outdated censored content (e.g., the original "bad word" if the replacement changes). By applying
censor only at display time, the pipeline always uses the current word list.

**Additional implication**: the search indexer (called in `ThreadsService`) should receive PRE_SAVE
output (not PRE_OUTPUT), so it indexes actual stored content without censor distortions.

### 3.5 Smilies should be PRE_OUTPUT only in phpBB4 (MEDIUM confidence)

phpBB3 stored smiley placeholders, adding complexity. In phpBB4, if smilies are a PRE_OUTPUT-only
plugin, the raw text code (e.g., `:-)`) is stored and rendered to `<img>` only in responses. This
simplifies storage and makes the edit form trivially show the original text codes.

### 3.6 Priority ordering is self-contained in plugin PHP files (HIGH confidence)

`#[AsTaggedItem(priority: N)]` is applied to the plugin class — the ordering decision is co-located
with the implementation. Higher `priority` = earlier in the sorted iterable = processed first.
Recommended scale:

| Plugin | Stage | Priority | Rationale |
|--------|-------|----------|-----------|
| CensorProcessor | PRE_OUTPUT | 100 | Must run first — removes sensitive words before any format rendering |
| BbcodeProcessor | PRE_OUTPUT | 50 | Render BBCode after censor |
| MarkdownProcessor | PRE_OUTPUT | 40 | Render Markdown after censor |
| SmilesProcessor | PRE_OUTPUT | 20 | Expand smilies in near-final text |
| HtmlSanitizerProcessor | PRE_SAVE | 80 | Strip dangerous input before storage |

---

## 4. Relationships and Dependencies

```
ContentPluginInterface  ──────── #[AutoconfigureTag('phpbb.content_plugin')]
        │
        │  implements
        ├── CensorProcessor           #[AsTaggedItem(priority: 100)]
        ├── BbcodeProcessor           #[AsTaggedItem(priority: 50)]
        ├── MarkdownProcessor         #[AsTaggedItem(priority: 40)]
        ├── SmilesProcessor           #[AsTaggedItem(priority: 20)]
        └── HtmlSanitizerProcessor    #[AsTaggedItem(priority: 80)]

ContentPipeline
    ┌── #[AutowireIterator('phpbb.content_plugin')] → iterable<ContentPluginInterface>
    ├── processForSave(content, context): string
    │       foreach plugin where supportsStage(PRE_SAVE)
    └── processForOutput(content, context): string
            foreach plugin where supportsStage(PRE_OUTPUT)

Injection points:
    ThreadsService::createPost()  ── $pipeline->processForSave($content, $ctx)
    ThreadsService::updatePost()  ── $pipeline->processForSave($content, $ctx)
    PostsController::postToArray() ─ $pipeline->processForOutput($dto->content, $ctx)
```

---

## 5. Gaps and Uncertainties

| Gap | Impact | Suggested resolution |
|-----|--------|---------------------|
| `ContentStage` enum vs bitfield vs string | Medium | Use PHP 8.1+ backed enum `ContentStage { PRE_SAVE; PRE_OUTPUT; }` |
| Whether any built-in plugin needs BOTH stages | Low | Only BBCode if intermediate format is used; simplify by making BBCode PRE_OUTPUT only |
| Context shape for plugin invocations | Medium | Define `array $context = ['userId' => int, 'forumId' => int, 'stage' => ContentStage]` |
| Search indexer receiving pre-save content | Low | Already confirmed in write-path findings; no change needed unless search needs HTML |
| Plugin service scanning: explicit list vs resource scan | Medium | Current codebase uses explicit list for non-controller services; plugin bundles need their own resource scan |
| Thread safety / lazy vs eager initialisation | Low | `iterable` from tagged_iterator is lazy by default in Symfony |

---

## 6. Conclusions

### Primary
**Use `#[AutoconfigureTag]` on `ContentPluginInterface` + `#[AutowireIterator]` in `ContentPipeline`.**
This is the only approach that satisfies the zero-core-modification constraint for plugin authors while
providing deterministic ordered pipeline execution.

### Secondary
1. phpBB4 should use a **simplified two-stage model** (PRE_SAVE, PRE_OUTPUT) without phpBB3's
   intermediate UID/bitfield format — store raw markup, render at output.
2. Censor MUST be PRE_OUTPUT only — a lesson directly validated by phpBB3 design.
3. Smilies SHOULD be PRE_OUTPUT only in phpBB4 (simplification over phpBB3).
4. BBCode in phpBB4 should start as PRE_OUTPUT only; migrate to PRE_SAVE if performance requires it.
5. The `#[AutoconfigureTag]` pattern, while new to this codebase, is the natural evolution path for
   the existing event-dispatch registry pattern — same concept, less boilerplate.
