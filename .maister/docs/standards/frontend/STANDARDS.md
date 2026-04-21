# Frontend Standards (React SPA)

Scope: frontend mock and SPA layer, based on patterns used in `mocks/forum-index`.

## 1. Stack & Runtime

- Use React 18+ with functional components and hooks.
- Use Vite for dev/build (`dev`, `build`, `preview` scripts).
- Use ES modules (`type: module`).
- Prefer routing libraries (`react-router-dom`) for navigation/stateful URLs.

## 2. File Organization

- Keep app entry in `src/main.jsx` and root view in `src/App.jsx`.
- Keep reusable UI pieces in `src/components/`.
- Keep styles split by concern in `src/styles/` (no single monolithic CSS file).
- Import local component styles from the component file when practical.

## 3. Component Conventions

- Use named props with clear contracts (`topic`, `posts`, `onBack`, `onTopicClick`).
- Keep view state local with `useState`.
- Keep side effects in `useEffect` and always clean up listeners.
- Prefer small helper functions for formatting and UI derivations (e.g. date formatting, avatar color fallback).

## 4. Styling Conventions

- Use plain CSS modules-by-file (global CSS files per feature area).
- Use class-based styling; avoid inline styles except dynamic visual values that must be computed at runtime (e.g. avatar color/size).
- Prefer subtle separators and neutral surfaces over heavy gradients for content cards/lists.
- Keep motion lightweight (`transition` on focus/hover/expand states).

## 5. Interaction Patterns

- Use route-based navigation (e.g. `react-router-dom`) instead of manual `view` toggles.
- For expandable input panels, use deterministic rules from component state (e.g. expanded when focused or textarea has content).
- Keep buttons semantic (`button` for actions, `a` for navigation-like anchors).

## 6. Accessibility

- Provide `aria-label` for interactive elements without visible text.
- Use `aria-current` for active breadcrumb item.
- Use `aria-expanded` + `aria-controls` for collapsible sections.
- Preserve keyboard focus behavior and explicit `:focus`/`:focus-visible` styling decisions.

## 7. Iconography

- Use Google Material Symbols as the primary icon set.
- Keep icon names explicit in markup and style icon size from CSS utility classes.
- Pair icon-only buttons with `aria-label` and `title`.

## 8. Data & Mocking

- Keep mock data in `src/data.js` with explicit constants for forum types.
- Keep dates as unix timestamps and format at render time.
- Keep naming consistent with phpBB-like field names for easier backend mapping.

## 9. Language & Copy

- Keep user-facing copy consistent within a screen (Polish in topic mock UI).
- Keep technical/internal naming in English where it improves maintainability.

## 10. Do / Avoid

Preferred:

```jsx
<button type="button" aria-label="Wyślij" title="Wyślij">
  <span className="material-symbols-outlined">reply</span>
</button>
```

Avoid:

```jsx
<div onClick={sendMessage}>Send</div>
```
