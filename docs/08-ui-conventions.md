# UI Conventions

> **Last updated:** 2026-08-26 · **Decision:** [ADR-0006](decisions/ADR-0006-alpine-chartjs-not-livewire.md)
>
> Seven people building screens will produce seven different interfaces unless this file is followed. Consistency is a directly marked criterion.

---

## Stack

| Layer | Tool | Used for |
|---|---|---|
| Templating | **Blade** | All server-rendered HTML |
| Styling | **Tailwind CSS** via Vite | Everything. No custom CSS files except `app.css` for `@apply` shortcuts. |
| Interactions | **Alpine.js** | Dropdowns, modals, tabs, the dashboard poll timer, expanding remark fields |
| Charts | **Chart.js** | Telemetry graphs, traffic logs, report charts |

No React, no Vue, no Livewire. If a screen seems to need one, it does not — raise it as an open question.

---

## Layouts

Two, and only two.

| Layout | Used by | Structure |
|---|---|---|
| `layouts/guest.blade.php` | Login, forgot password, reset password | Centred card on a plain ground. No navigation. |
| `layouts/app.blade.php` | Everything else | Left sidebar + topbar + content slot |

### The app shell

```
┌──────────┬────────────────────────────────────────────┐
│          │  Topbar: page title · 🔔 notifications · user menu │
│ Sidebar  ├────────────────────────────────────────────┤
│          │                                            │
│ role-    │  <x-page-header> title + actions           │
│ filtered │                                            │
│ nav      │  {{ $slot }}                               │
│          │                                            │
└──────────┴────────────────────────────────────────────┘
```

**Build the sidebar once**, from a config array keyed by role. Seven people must never each invent their own navigation.

```php
// config/atlas.php → 'navigation'
'assistant' => [
    ['label' => 'My Shift',     'route' => 'assistant.shifts.today', 'icon' => 'calendar'],
    ['label' => 'Inspections',  'route' => 'assistant.inspections.index', 'icon' => 'clipboard'],
    ['label' => 'My Tickets',   'route' => 'assistant.tickets.index', 'icon' => 'ticket'],
    ['label' => 'AP Status',    'route' => 'assistant.aps.index', 'icon' => 'wifi'],
],
```

On mobile the sidebar collapses to a hamburger drawer driven by Alpine.

---

## Blade components

Live in `resources/views/components/`. Build these in Sprint 0, before any module screens.

| Component | Props | Used by |
|---|---|---|
| `<x-page-header>` | `title`, `subtitle`, actions slot | Every page |
| `<x-card>` | `title`, footer slot | Everywhere |
| `<x-empty-state>` | `title`, `message`, action slot | **Every list view** |
| `<x-stat-tile>` | `label`, `value`, `delta`, `trend`, sparkline slot | All three dashboards |
| `<x-status-badge>` | `:status` (any status enum) | Tickets, assets, shifts |
| `<x-priority-pill>` | `:priority` | Ticket lists and detail |
| `<x-risk-gauge>` | `:score`, `:band` | Asset detail, risk ranking |
| `<x-data-table>` | `headers`, rows slot, sort/filter/pagination slots | Every index page |
| `<x-form.input>` | `name`, `label`, `help`, `type` — error display built in | Every form |
| `<x-form.select>` | `name`, `label`, `options`, `selected` | Every form |
| `<x-form.textarea>` | `name`, `label`, `rows` | Every form |
| `<x-chart>` | `type`, `:data`, `:options` — Chart.js wrapper | Dashboard, AP detail, traffic, reports |
| `<x-checklist-item>` | `:item`, `:result` — OK / Issue / N/A with expanding remark | Inspection form |
| `<x-timeline>` | `:events` — actor, action, timestamp | Ticket detail, audit log |
| `<x-modal>` | `name`, `title` — Alpine-driven | Confirmations, quick forms |
| `<x-alert>` | `type` — success / warning / error | Flash messages |
| `<x-pagination>` | `:paginator` | Every index page |

### The rule that makes data-source swapping invisible

**Components take data as props and never query.**

`<x-stat-tile :value="$avgLatency">` has no idea whether that number came from a simulator or a live access point — and it must not. This is what lets us change data sources without touching the frontend.

Paired with this: **every list view needs a real `<x-empty-state>`**, because once demo data is purged the app must look deliberate rather than broken.

---

## Design tokens

Define these in `tailwind.config.js` before the first screen is built, so nobody hand-picks colours later.

### Semantic status colours

The same mapping in badges, gauges, charts and tables. If `critical` is one red in a table, it is that red in the chart too.

| Meaning | Applies to | Tailwind |
|---|---|---|
| **Healthy** | `operational`, `online`, `resolved`, `closed`, `completed`, risk band `low` | `emerald` |
| **Attention** | `degraded`, `medium` priority, `in_progress`, `acknowledged`, risk band `medium`/`high` | `amber` |
| **Urgent** | `faulty`, `offline`, `critical`, `missed`, SLA breached, risk band `critical` | `red` |
| **Inactive** | `retired`, `na`, `maintenance` | `slate` |

> **Semantic colour is not the brand colour.** Pick one brand accent for links, primary buttons and active nav; keep it distinct from all four status colours so a coloured button never reads as a status.

### Type and spacing

- One font family throughout. One type scale — do not invent sizes per page.
- Tabular numerals (`tabular-nums`) wherever digits line up in a column: latency, client counts, ticket numbers, dates.
- Consistent spacing scale. If a card has `p-6`, every card has `p-6`.

---

## Responsive rules

Different roles use different devices. Design for both, differently.

| Role | Primary device | Requirements |
|---|---|---|
| **Assistant** | 📱 **Mobile first** | The checklist must be fully operable one-handed at 375 px. Large tap targets, one item per row, no horizontal scroll, no hover-only interactions. This is NFR-05 and it is tested. |
| **Engineer** | 🖥️ **Desktop first** | Dense tables, multi-series charts, filter bars, keyboard-navigable. Degrade to stacked cards below 768 px rather than horizontally scrolling tables. |
| **Admin** | 🖥️ Desktop | Standard CRUD. Consistent index / create / edit / show pattern throughout. |

Any table wider than the viewport gets `overflow-x: auto` on **its own container** — the page body must never scroll sideways.

---

## Standard CRUD pattern

Every admin resource follows the same four screens. Build one, copy the shape.

| Screen | Route | Contains |
|---|---|---|
| Index | `admin.{resource}.index` | `<x-page-header>` + Create button, filter bar, `<x-data-table>`, `<x-pagination>`, `<x-empty-state>` |
| Create | `admin.{resource}.create` | `<x-card>` with a form of `<x-form.*>` components, Cancel + Save |
| Edit | `admin.{resource}.edit` | Same form, pre-filled, plus a destructive action behind `<x-modal>` confirmation |
| Show | `admin.{resource}.show` | Read-only detail, related records, `<x-timeline>` of audit entries |

---

## Interaction conventions

- **Destructive actions always confirm** via `<x-modal>`. Never a bare delete button.
- **A control says what happens.** The button says "Resolve ticket", the toast says "Ticket resolved". Not "Submit" / "Success".
- **Errors explain the fix.** "Asset tag APU-1042 is already in use" — not "Validation failed".
- **Loading states exist.** The dashboard poll shows a subtle indicator; form submits disable the button.
- **Keyboard focus is visible everywhere.** Never `outline: none` without a replacement.
- **Respect `prefers-reduced-motion`.**

---

## The flow that matters most

Get this one right and the demo carries itself:

> An assistant opens the app on their phone mid-shift, sees today's round, taps into a lab, works down the PC list, marks a monitor as failing — and the system offers a pre-filled ticket with the machine, location, failed item and suggested priority already populated. They add one sentence and submit. On the engineer's dashboard the ticket appears in the queue within one poll cycle, and the machine's risk score rises overnight.

That single path touches five of the seven modules and demonstrates the whole system in ninety seconds. **Build it end-to-end early and rehearse it.**

---

## Screen inventory

Section F item 15 requires every screen documented as a user manual. Keep this list current — it becomes that chapter.

### Assistant (mobile-first)
My shift today · Start inspection round · Select PC · Checklist form · Inspection summary · My tickets · Raise ticket · AP status (read-only) · Notifications · Shift handover

### Engineer (desktop-first)
AP health dashboard · AP detail with 24h chart · Traffic & congestion logs · Ticket queue · Ticket detail · Maintenance priority ranking · Asset risk detail · Notifications

### Admin
Users · Locations · Lab PC inventory · Access point inventory · Checklist templates · Checklist items · Shift templates · Roster generator · Audit log · Reports

### Shared
Login · Forgot password · Reset password · Profile · 403 · 404
