# ADR-0009: Rebuild the interface on themed tokens, Geist and Phosphor

**Status:** Accepted
**Date:** 2026-09-23
**Deciders:** Group Atlas

## Context

The back end was strong and the interface was the weakest part of the system. Screens were built one module at a time, each with its own hand-picked Tailwind classes: `slate-500` here, `gray-400` there, three different button recipes, headings in title case on one screen and sentence case on the next. Users said it was not friendly.

Three specific problems needed a decision rather than a restyle:

- **No dark theme, and no way to add one.** Colours were literal Tailwind classes in about ninety views. Adding dark mode by hand would have meant a `dark:` variant beside every one.
- **Icons were hand-copied SVG paths**, inconsistent in weight and size, pasted into `<x-icon>` one at a time.
- **The font was the browser default** on some machines and Figtree from a third-party CDN on others (the error pages), so the system looked different depending on where it was opened.

## Decision

**Colours are CSS variables, and Tailwind reads them.** `resources/css/app.css` defines every colour as RGB channels on `:root`, and again under `:root[data-theme='dark']`. `tailwind.config.js` points each scale (`slate`, `brand`, `emerald`, `amber`, `orange`, `red`) and a small set of semantic tokens (`canvas`, `surface`, `sunken`, `line`, `fg`, `fg-muted`, `fg-subtle`) at those variables. A view writes `text-fg-muted` or `bg-surface` once and gets the right colour in both themes. Existing `slate-*` classes theme correctly too, because the scale itself is swapped.

**The theme follows the device, with a per-browser override.** An inline script in `layouts/partials/head.blade.php` sets `data-theme` before first paint, from `localStorage['atlas-theme']` (`system`, `light` or `dark`). The choice is made in the user menu or on Profile. The server never stores it.

**Three new npm dev dependencies:**

| Package | Why |
|---|---|
| `@fontsource-variable/geist` | The interface font, self-hosted through Vite. No CDN request, so it renders the same offline and on every machine. |
| `@fontsource-variable/geist-mono` | Figures that must line up or be read exactly: asset tags, MAC and IP addresses, ticket numbers. |
| `@phosphor-icons/core` | One consistent icon family, MIT licensed, in regular and filled weights. |

The icons are **not** loaded at runtime. `npm run icons` (`scripts/build-icons.mjs`) copies the glyphs the app names into `resources/icons/phosphor.php`, which is committed. `App\Support\Icons` reads that file, so rendering a page never needs `node_modules`, and an unknown name falls back to a visible `help` glyph instead of an error.

**One accent colour.** Cobalt `brand-600` is for primary buttons, links, focus and the active navigation item. It is kept apart from the four status tones so a button never reads as a status.

**Writing rules for anything on screen:** sentence case, plain words, and no em or en dashes. Enum labels were changed to match (`In progress`, `Pass with issues`, `Critical risk`).

## Consequences

**Easier**
- Dark mode costs nothing per screen. A new view that uses the tokens is themed on arrival.
- One button recipe (`.btn`, `.btn-primary`, `.btn-secondary`, `.btn-ghost`, `.btn-danger`, or `<x-button>`), one card, one table, one form vocabulary.
- Adding an icon is one line in the map and `npm run icons`.

**Harder**
- A colour cannot be checked by reading its class name alone: `slate-100` is light grey in the light theme and near-black in the dark one. Check both themes before calling a screen done.
- Chart.js cannot read a Tailwind class. Charts take their axis and grid colours from the variables through `themeChartDefaults()` in `resources/js/app.js`, and a page that colours its own series uses `window.themeToken()`.
- `resources/icons/phosphor.php` is generated. Edit the map in `scripts/build-icons.mjs`, never the output.

## Alternatives considered

**Tailwind's `dark:` variant on every class.** Rejected: roughly doubles the class list on every element in ninety views, and every future screen has to remember it. A missed `dark:` is invisible until somebody switches theme.

**An icon font or a Blade icon package.** A font loads every glyph and renders blurry at small sizes; a Composer package is a PHP dependency for something that is, in the end, a few dozen SVG paths. Generating a PHP array keeps runtime dependencies at zero.

**Inter as the font.** It is the default of nearly every dashboard template, which is exactly why the system looked generic. Geist has the same legibility with tabular figures and a matching monospace.
