# TODO: Content Storage Migration (s9e XML → Raw Text)

**Source**: Cross-cutting assessment §7.4
**Priority**: ⚠️ High — must be resolved before Threads service implementation
**Status**: 🔜 Needs dedicated research

## Problem

Threads ADR-001 decides: "raw text only — single `post_text` column, full parse+render on every display."

But the existing `phpbb_posts.post_text` column contains **s9e XML** (pre-parsed BBCode with `bbcode_uid`, `bbcode_bitfield`, `enable_bbcode` flags). The Threads service reuses this legacy table — it does not create new tables.

## Questions to Answer

1. **Migration feasibility**: Can s9e XML be reliably converted back to raw BBCode/text? Is s9e's XML format losslessly reversible?
2. **Scale**: How many posts exist? What's the estimated migration time for a large board (1M+ posts)?
3. **Dual-format ContentPipeline**: Can the pipeline detect s9e XML vs raw text and handle both? What's the performance cost?
4. **Migration strategy**: Big-bang one-time migration vs lazy migration (convert on read, write back) vs dual-format forever?
5. **Messaging impact**: New `messaging_messages` table starts clean with raw text — no migration needed there. Only `phpbb_posts` is affected.

## Acceptance Criteria

- [ ] Research report with recommended approach
- [ ] Decision record (ADR) documenting the chosen strategy
- [ ] If migration chosen: estimated effort, rollback plan, data integrity verification approach
- [ ] If dual-format chosen: ContentPipeline design for format detection
