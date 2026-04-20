# Forum-Index Mock – Accessibility Audit (WCAG 2.1 AA)

**Date:** 2026-04-20  
**Scope:** `mocks/forum-index/src/` (7 files + index.html)  
**Standard:** WCAG 2.1 Level AA  
**Verdict:** ⛔ FAIL — 14 violations identified (5 critical, 6 serious, 3 minor)

---

## Summary

The mock is primarily a visual prototype and was not built with accessibility in mind. The main issues are: **keyboard-inaccessible dropdown menu**, **missing form labels**, **removed focus outlines without replacement**, **extremely small base font-size**, and **data relationships expressed only visually**.

---

## Critical Violations (WCAG A)

### V-01: Keyboard-inaccessible hamburger dropdown
| | |
|---|---|
| **WCAG** | 2.1.1 Keyboard, 2.4.7 Focus Visible |
| **File** | `App.jsx` (HamburgerMenu), `styles.css` L284 |
| **Detail** | Menu opens only via CSS `:hover` (`.header-hamburger:hover > .dropdown`). There is no `onClick`/`onKeyDown`, no `aria-expanded`, no focus management. |
| **Impact** | Keyboard and screen-reader users cannot access FAQ, Login, Register, or any menu item. |
| **Fix** | Add click/Enter toggle, `aria-expanded`, focus-trap inside dropdown, Escape to close. |

### V-02: Missing labels on search inputs
| | |
|---|---|
| **WCAG** | 1.3.1 Info & Relationships, 4.1.2 Name/Role/Value |
| **File** | `App.jsx` — Header search (L45), Sticky search (L133) |
| **Detail** | `<input name="keywords" placeholder="Search…">` with no `<label>`, `aria-label`, or `aria-labelledby`. Placeholder text is NOT an accessible name per WCAG. |
| **Impact** | Screen readers announce "edit text" with no context. |
| **Fix** | Add `aria-label="Search the forum"` on both inputs. |

### V-03: Focus outline removed without replacement
| | |
|---|---|
| **WCAG** | 2.4.7 Focus Visible |
| **File** | `styles.css` — `.inputbox { outline: none; }` (L236), search inputs |
| **Detail** | Default browser focus ring is suppressed. Only a background-color transition hints at focus state. No custom ring on buttons. |
| **Impact** | Sighted keyboard users cannot see where focus is. |
| **Fix** | Add visible `:focus-visible` ring (e.g., `outline: 2px solid #105289; outline-offset: 2px`). |

### V-04: No skip-navigation link
| | |
|---|---|
| **WCAG** | 2.4.1 Bypass Blocks |
| **File** | `App.jsx` (top of `App()`) |
| **Detail** | No "Skip to main content" anchor before the header. |
| **Impact** | Keyboard users must tab through header/nav on every page load. |
| **Fix** | Add `<a href="#page-body" className="skip-link">Skip to content</a>` with off-screen styling that appears on focus. |

### V-05: Dropdown separator announced as empty list item
| | |
|---|---|
| **WCAG** | 4.1.2 Name/Role/Value |
| **File** | `App.jsx` L78 — `<li className="separator" />` |
| **Detail** | Empty `<li>` inside `role="menu"`. Screen readers announce "list item blank". |
| **Impact** | Confusing announcement; breaks menu semantics. |
| **Fix** | Change to `<li role="separator" aria-hidden="true" />`. |

---

## Serious Violations (WCAG AA)

### V-06: Extremely small base font-size
| | |
|---|---|
| **WCAG** | 1.4.4 Resize Text |
| **File** | `styles.css` L12 — `body { font-size: 10px; }` |
| **Detail** | All content resolves at 10–12px (1.1em of 10px = 11px). Users must zoom 160%+ to reach readable size. While technically "can be resized", the default is unusably small. |
| **Impact** | Low-vision users and all mobile users. Content at default is below readable threshold. |
| **Fix** | Set `body { font-size: 62.5%; }` (10px technique for REM) or preferably `font-size: 100%` and define sizes in rem. |

### V-07: Insufficient color contrast (multiple)
| | |
|---|---|
| **WCAG** | 1.4.3 Contrast (Minimum) — 4.5:1 for normal text |
| **File** | `styles.css` |
| **Cases** | |
| `.forum-icon-read { color: #8899aa }` on `#EEF5F9` | ≈2.3:1 ❌ |
| `.no-posts { color: #999 }` on `#EEF5F9` | ≈2.5:1 ❌ |
| `.header-search .inputbox { color: #fff }` on `#fff3` bg | Variable, often <3:1 ❌ |
| `.sticky-search-bar input { color: #fff }` on `rgba(255,255,255,0.2)` | <2:1 ❌ |
| **Fix** | Darken muted colors: `#8899aa` → `#5a6b7c`, `#999` → `#666`. Use solid bg behind input text. |

### V-08: Breadcrumbs lack `<nav>` landmark and aria
| | |
|---|---|
| **WCAG** | 1.3.1 Info & Relationships |
| **File** | `App.jsx` — ActionBar, StickyHeader (breadcrumbs as bare `<ul>`) |
| **Detail** | No `<nav aria-label="Breadcrumb">` wrapper. No `aria-current="page"`. |
| **Fix** | Wrap in `<nav aria-label="Breadcrumb">`, add `aria-current="page"` to the last item. |

### V-09: Missing `aria-expanded` on hamburger button
| | |
|---|---|
| **WCAG** | 4.1.2 Name/Role/Value |
| **File** | `App.jsx` L68 |
| **Detail** | `<button aria-label="Menu">` is correct for name, but has no `aria-expanded` state. |
| **Fix** | Add state: `aria-expanded={menuOpen}` and `aria-controls="main-menu"`. |

### V-10: Grid-like forum data without table semantics
| | |
|---|---|
| **WCAG** | 1.3.1 Info & Relationships |
| **File** | `ForumList.jsx`, `ForumRow.jsx` |
| **Detail** | The layout mimics a data table (header: Topics / Posts / Last Post; rows: forum data) but uses `<dl>`/`<dd>` without programmatic header-to-cell association. |
| **Impact** | Screen reader users cannot navigate columns or understand data relationships. |
| **Fix** | Either use a proper `<table>` with `<th scope="col">` or add `role="grid"` with `aria-labelledby` linking headers to cells. |

### V-11: Sticky search button has no accessible name
| | |
|---|---|
| **WCAG** | 4.1.2 Name/Role/Value |
| **File** | `App.jsx` L118 — `<button className="sticky-search-btn" title="Search">` |
| **Detail** | `title` is not consistently announced by screen readers. Button has only a Material icon inside. |
| **Fix** | Add `aria-label="Search"`. |

---

## Minor / Best Practice Issues

### V-12: `role="menu"` / `role="menuitem"` without keyboard interaction
| | |
|---|---|
| **WCAG** | Best practice (ARIA Authoring Practices) |
| **File** | `App.jsx` L74-82 |
| **Detail** | ARIA menu pattern requires arrow-key navigation between items, Home/End keys, and type-ahead. None of this is implemented. |
| **Fix** | Either implement full keyboard menu pattern or remove `role="menu"`/`role="menuitem"` and use plain list of links. |

### V-13: `lang="en"` mismatch with Polish content
| | |
|---|---|
| **WCAG** | 3.1.1 Language of Page, 3.1.2 Language of Parts |
| **File** | `index.html` L2, `data.js` (Polish forum names) |
| **Detail** | `<html lang="en">` but content includes "DYSKUSJE OGÓLNE", "Przedstaw się", etc. |
| **Fix** | Set `lang="pl"` or mark Polish sections with `lang="pl"` attribute. |

### V-14: Truncated link text not fully accessible
| | |
|---|---|
| **WCAG** | Best practice (2.4.4 Link Purpose) |
| **File** | `ForumRow.jsx` L126-128 (`truncate()` to 26 chars) |
| **Detail** | `title` attribute has full text but is inconsistently supported by assistive tech on touch/mobile. |
| **Fix** | Use `aria-label` with full text instead of or in addition to `title`. |

---

## What's Done Right ✓

- `role="banner"` on header bar
- `role="search"` on search container
- `<main id="page-body">` landmark
- `<footer>` semantic element
- `aria-label="Menu"` on hamburger button
- `aria-hidden="true"` on decorative link icons
- `<time datetime="...">` with ISO date strings
- `alt` text on forum status `<img>` elements
- `rel="noopener"` on external links
- Responsive media query (visual, not an a11y requirement)

---

## Priority Remediation Plan

| Priority | Issue | Effort |
|----------|-------|--------|
| P0 | V-01 Keyboard dropdown | Medium — requires JS state |
| P0 | V-02 Search labels | Trivial — add `aria-label` |
| P0 | V-03 Focus indicators | Low — add `:focus-visible` |
| P1 | V-04 Skip link | Trivial |
| P1 | V-06 Font-size | Low — switch to rem |
| P1 | V-07 Contrast | Low — darken colors |
| P1 | V-09 aria-expanded | Trivial |
| P1 | V-10 Table semantics | Medium — restructure HTML |
| P1 | V-11 Sticky search label | Trivial |
| P2 | V-05, V-08, V-12-14 | Low effort each |

---

## Conclusion

The mock scores approximately **4/10** on WCAG 2.1 AA. It has correct landmark structure (`main`, `footer`, `role="banner"`, `role="search"`) but fails on interaction accessibility (keyboard, focus), labelling, and color contrast. The most impactful fix is making the dropdown keyboard-accessible and adding missing `aria-label` attributes.
