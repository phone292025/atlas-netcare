# UI Conventions

> **Last updated:** 2026-09-23 · **Decisions:** [ADR-0006](decisions/ADR-0006-alpine-chartjs-not-livewire.md), [ADR-0009](decisions/ADR-0009-themed-design-system.md)
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
| `layouts/guest.blade.php` | Login, forgot password, reset password | Two columns on a wide screen: what the system is on the left, the form on the right. One column on a phone. Pages pass `heading` and `intro` slots. |
| `layouts/app.blade.php` | Everything else | Left sidebar + topbar + content slot |

### The app shell

```
+------------+----------------------------------------------+
| Brand      | Topbar: section > page title      bell (count) |
| Search  K  +----------------------------------------------+
|            |                                              |
| Grouped,   |  <x-page-header> title + actions             |
| role-      |                                              |
| filtered   |  {{ $slot }}                                 |
| nav        |                                              |
|            |                                              |
| User card: |                                              |
| profile,   |                                              |
| theme,     |                                              |
| sign out   |                                              |
+------------+----------------------------------------------+
```

- **Command menu.** Ctrl+K (Cmd+K on a Mac) or `/` opens it anywhere. It offers every screen in the sidebar plus the role's common actions ("Raise a ticket", "Add a user"). The entries come from `AppLayout::commands()`.
- **Notifications** open as a popover from the bell, with the six newest and a link to the full list.
- **Assistants get a tab bar** along the bottom of a phone: their first four screens and "More", which opens the drawer.
- **Toasts, not banners,** for flash messages. See Interaction conventions.

**Build the sidebar once**, from a config array keyed by role. Seven people must never each invent their own navigation.

### Route names are fixed vocabulary

These names live in `config('atlas.navigation')` and the sidebar renders from them. **A module owner must use the name listed here**, not one of their own choosing.

This matters more than it looks: the sidebar silently skips any entry whose route is not registered. If M2's owner names their route `admin.assets.index` instead of `admin.lab-pcs.index`, nothing errors — the menu item just quietly disappears, and somebody loses an afternoon finding out why.

| Module | Route name | Screen |
|---|---|---|
| M6 | `assistant.shifts.today` | My shift today |
| M3 | `assistant.inspections.index` | Inspection rounds |
| M4 | `assistant.tickets.index` | My tickets |
| M5 | `assistant.aps.index` | AP status (read-only) |
| M4, M5 | `engineer.dashboard` | Engineer dashboard: their queue, waiting tickets, AP health |
| M5 | `engineer.aps.index` | Access point list |
| M5 | `engineer.traffic.index` | Traffic & congestion logs |
| M4 | `engineer.tickets.index` | Ticket queue |
| M7 | `engineer.risk.index` | Maintenance priority ranking |
| M1 | `admin.users.index` | User management |
| M2 | `admin.locations.index` | Locations |
| M2 | `admin.lab-pcs.index` | Lab PC inventory |
| M2 | `admin.aps.index` | Access point inventory |
| M3 | `admin.checklists.index` | Checklist templates |
| M6 | `admin.shift-templates.index` | Shift templates |
| M6 | `admin.roster.index` | Roster generator |
| M1 | `admin.audit.index` | Audit log |
| M7 | `admin.reports.index` | Reports |

Conventions: role prefix, kebab-case for multi-word resources (`lab-pcs`, `shift-templates`), and standard Laravel resource verbs (`index`, `create`, `edit`, `show`) beneath each. Adding a sidebar entry means adding a row here **and** to `config/atlas.php` in the same commit.

```php
// config/atlas.php, 'navigation'
'assistant' => [
    ['label' => 'My shift',        'route' => 'assistant.shifts.today',      'icon' => 'calendar-check', 'group' => 'My work'],
    ['label' => 'Inspections',     'route' => 'assistant.inspections.index', 'icon' => 'list-checks',    'group' => 'My work'],
    ['label' => 'My tickets',      'route' => 'assistant.tickets.index',     'icon' => 'ticket',         'group' => 'My work'],
    ['label' => 'Network monitor', 'route' => 'assistant.monitor.index',     'icon' => 'broadcast',      'group' => 'Network', 'short' => 'Network'],
    ['label' => 'Access points',   'route' => 'assistant.aps.index',         'icon' => 'wifi',           'group' => 'Network'],
],
```

`group` is the heading the entry sits under in the sidebar. `short` is the label under the icon in the assistant's phone tab bar, where a long one is cut off. Labels are sentence case.

Below `lg` the sidebar becomes a drawer, opened from the menu button in the topbar.

---

## Blade components

Live in `resources/views/components/`. Build these in Sprint 0, before any module screens.

| Component | Props | Used by |
|---|---|---|
| `<x-page-header>` | `title`, `subtitle`, actions slot | Every page |
| `<x-card>` | `title`, `subtitle`, `flush`, header + footer slots | Everywhere |
| `<x-empty-state>` | `title`, `message`, `icon`, action slot | **Every list view** |
| `<x-stat-tile>` | `label`, `value`, `unit`, `delta`, `trend`, `tone`, `icon`, `href`, sparkline slot | Dashboards and summary rows |
| `<x-status-badge>` | `:status` (any enum implementing `HasTone`) | Tickets, assets, shifts, alarms |
| `<x-priority-pill>` | `:priority` | Ticket lists and detail |
| `<x-risk-gauge>` | `:score`, `:band` | Asset detail, risk ranking |
| `<x-data-table>` | `headers`, `flat`, rows slot, `filters` / `empty` / `pagination` slots. `flat` drops the frame when the table sits inside a card. | Every index page |
| `<x-form.field>` | *internal* — label, help and error scaffolding the three controls share | Not used directly |
| `<x-form.input>` | `name`, `label`, `help`, `type` — error display built in | Every form |
| `<x-form.select>` | `name`, `label`, `options`, `selected`, plus **`placeholder`** (disabled prompt, for forms) or **`blank`** (selectable "any", for filter bars) — one or the other, never both | Every form |
| `<x-form.textarea>` | `name`, `label`, `rows` | Every form |
| `<x-chart>` | `type`, `:data`, `:options` — Chart.js wrapper | Dashboard, AP detail, traffic, reports |
| `<x-checklist-item>` | `:item`, `:result` — OK / Issue / N/A with expanding remark | Inspection form |
| `<x-timeline>` | `:events` — actor, action, timestamp | Ticket detail, audit log |
| `<x-modal>` | `name`, `title`, `maxWidth`, footer slot — Alpine-driven | Confirmations, quick forms |
| `<x-alert>` | `type` (success, warning, error, info) plus `title`, `dismissible` | Inline notices on a page |
| `<x-pagination>` | `:paginator` | Every index page |
| `<x-button>` | `variant` (primary, secondary, ghost, danger), `size` (sm, lg), `href`, `icon`, `type` | Every action. A link when given `href`. |
| `<x-icon>` | `name`, `fill` | Everywhere. Names are the map in `scripts/build-icons.mjs`. |
| `<x-badge>` | `tone`, `dot` | Labels that are not an enum status |
| `<x-avatar>` | `name`, `size` (sm, md, lg, xl) | People: assignees, reporters, the user menu |
| `<x-tab-nav>` | `tabs` (label, url, count, active, tone) | Views over one list: the ticket queue, My tickets, Users |
| `<x-ticket-due>` | `:ticket` | "Overdue by 3 hours" or "Due in 2 days", coloured by how close |
| `<x-search-input>` | `name`, `placeholder` | The search box at the start of a list toolbar |

**All of these are built.** See them rendered together at **`/dev/gallery`**, which is registered only when `APP_ENV=local`. Look there before building anything — the component you need probably already exists. If one genuinely does not, say so rather than hand-rolling it on your own screen.

### Named error bags

All three form controls take a **`bag`** prop. Two forms on one page validate into separate bags — the profile screen is the existing case — and a field reading the default bag renders **no error at all**, silently:

```blade
<x-form.input name="password" label="New password" type="password" bag="updatePassword" />
```

### One form vocabulary

Breeze's `<x-text-input>`, `<x-input-label>`, `<x-input-error>` and `<x-primary-button>` have been **deleted**. Breeze's error component had no `id`, `aria-describedby` or `aria-invalid` linking it to its input, hardcoded indigo rather than the brand token, and used `border-gray-300` against our `border-slate-300`. Every form in the system now uses `<x-form.*>`, and a test asserts the Breeze components never come back.

### The rule that makes data-source swapping invisible

**Components take data as props and never query.**

`<x-stat-tile :value="$avgLatency">` has no idea whether that number came from a simulator or a live access point — and it must not. This is what lets us change data sources without touching the frontend.

Paired with this: **every list view needs a real `<x-empty-state>`**, because once demo data is purged the app must look deliberate rather than broken.

---

## Design tokens

Every colour is a CSS variable in `resources/css/app.css`, defined once for the light theme and once for the dark one, and `tailwind.config.js` reads them. Write the token, never a hex value, and the screen is themed in both modes. See [ADR-0009](decisions/ADR-0009-themed-design-system.md).

### Surfaces and text

| Token | Use |
|---|---|
| `bg-canvas` | The page behind everything |
| `bg-surface` | Cards, tables, the sidebar, inputs |
| `bg-sunken` | Wells inside a surface: table headers, chips, empty tracks |
| `border-line` / `border-line-strong` | Dividers / input borders and hover edges |
| `text-fg` / `text-fg-muted` / `text-fg-subtle` | Body text / secondary text / hints and placeholders |
| `brand-*` | The one accent: primary buttons, links, focus, the active nav item |

The `slate` scale is themed too, so an older view that still says `text-slate-500` keeps working, but new work uses the tokens above. **Check both themes** before calling a screen done: `slate-100` is pale grey in one and near-black in the other.

### Type, shape and depth

- **Geist** for everything, **Geist Mono** for identifiers that must be read exactly: asset tags, AP codes, MAC and IP addresses, ticket numbers. Both are self-hosted.
- Tabular numerals are on by default in tables, `time` and `.font-mono`. Add `tabular-nums` anywhere else digits line up.
- Page titles `text-2xl` (26px from `sm`) semibold; card titles 15px semibold; body `text-sm`.
- Radii: `rounded-md` for controls, `rounded-lg` for chips and inner panels, `rounded-xl` for cards. Nothing else.
- Shadows are `shadow-xs` on cards and `shadow-md` on things that float. Z-index uses the named scale: `z-sticky`, `z-drawer`, `z-popover`, `z-modal`, `z-toast`.
- Cards pad `p-5`. Page sections are `space-y-6` apart.

### Semantic status colours

The same mapping in badges, gauges, charts and tables. If `critical` is one red in a table, it is that red in the chart too.

| Tone | Meaning | Applies to | Tailwind |
|---|---|---|---|
| `healthy` | Working as intended | `operational`, `online`, `resolved`, `closed`, `completed`, `checked_in`, `ok`, `pass`, risk band `low` | `emerald` |
| `attention` | In play, needs someone eventually | `degraded`, `medium` priority, and **every outstanding ticket state** — `open`, `acknowledged`, `in_progress`, `reopened` — plus `pass_with_issues`, risk band `medium`/`high` | `amber` |
| `urgent` | Act now | `faulty`, `offline`, `critical` priority, `missed`, `fail`, `issue`, SLA breached, risk band `critical` | `red` |
| `neutral` | Low salience — either not in play, or deliberately quiet | `retired`, `na`, `maintenance`, `scheduled`, `low` priority | `slate` |

**Two rules that follow from this table.**

**Status carries progress; priority carries urgency.** A ticket's *status* badge never uses `urgent`, however serious the ticket is — otherwise a brand-new low-priority ticket renders the same red as an offline access point. Urgency is the priority pill's job. This is why `TicketStatus::Open` is `attention`, not `urgent`.

**`neutral` means low visual weight, not "dead".** It covers both things that are out of play (`retired`, `maintenance`) and things deliberately kept quiet (`low` priority, `scheduled`). It was called `inactive` in Stage 2 and renamed, because a low-priority ticket is very much active.

> Tone is not exclusive to status fields. `AlarmType` also implements `HasTone` so the notification list can show severity. Any enum implementing `App\Enums\Contracts\HasTone` may be passed to `<x-status-badge>`.

#### Where the mapping lives

`app/Support/Tone.php`, and nowhere else. It exposes one method per **surface**:

| Method | Surface | Used by |
|---|---|---|
| `Tone::badge()` | small inline chip | `<x-badge>`, and through it `<x-status-badge>` and `<x-priority-pill>` |
| `Tone::banner()` | full-width message | `<x-alert>` |
| `Tone::accent()` | foreground tint only | icons and rules sitting on a tinted surface |

Adding a surface means adding a method there. **Never write a `match` on a tone anywhere else** — that is how `urgent` ends up as two different reds, one of which nobody notices until a marker does.

The tones are `healthy`, `attention`, `elevated`, `urgent` and `neutral`, running emerald, amber, orange, red, slate.

**Adding one is a real decision, not a tweak.** `elevated` was added on 2026-09-04 because `RiskBand::Medium` and `RiskBand::High` both returned `attention`, so M7's headline chart drew two of its four bars identically and a reader could not tell a High-risk machine from a Medium one. Every surface method needs a case for a new tone, `Tone::all()` needs it, and two tests deliberately spell the list out so that adding one fails loudly until every surface is covered. Two other tests had written the vocabulary out by hand instead of reading `Tone::all()`, and both broke — they now read from the source.

Every class string is written out in full, and `./app/**/*.php` is in the `content` array of `tailwind.config.js` so the file is scanned. Tailwind reads source for literal class names: a class assembled at runtime (`bg-{$colour}-50`) is generated as nothing, purged from the build, and renders unstyled **with no error at all**. This is the one place where a "no magic values in code" instinct produces a broken build.

An unrecognised tone renders fuchsia on purpose. A bug should be visible, not silently grey.

> **Semantic colour is not the brand colour.** Pick one brand accent for links, primary buttons and active nav; keep it distinct from all four status colours so a coloured button never reads as a status.

### Writing on screen

- **Sentence case** for every heading, button, label and badge. "Raise a ticket", not "Raise A Ticket". Enum labels follow the same rule.
- **No em or en dashes** in anything a user reads. Use a full stop, a comma, or "to" for a range ("21 Sep to 27 Sep"). A time range is written `09:00-13:00`.
- **Plain words.** Say what happens ("Take it", "Hand to somebody else"), not what the system calls it.

---

## Responsive rules

Different roles use different devices. Design for both, differently.

| Role | Primary device | Requirements |
|---|---|---|
| **Assistant** | 📱 **Mobile first** | The checklist must be fully operable one-handed at 375 px. Large tap targets, one item per row, no horizontal scroll, no hover-only interactions. This is NFR-05. **Measured at 375px on 2026-09-04**: all 39 choice buttons exactly 44px tall, three across at 98px each, no horizontal scroll, submit bar genuinely `position: sticky`, and the remark box hidden until Issue is tapped. |
| **Engineer** | 🖥️ **Desktop first** | Dense tables, multi-series charts, filter bars, keyboard-navigable. Degrade to stacked cards below 768 px rather than horizontally scrolling tables. The assistant's My Tickets does this (`md:hidden` cards, `max-md:hidden` table, one query); it is the pattern for any list an assistant reads on a phone. |
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
- **Flash messages go through the layout, not your page.** `layouts/partials/flash.blade.php` shows a toast for four session keys: `status` (Breeze's), `success`, `warning` and `error`. Redirect with `->with('success', 'Ticket resolved.')` and it appears. Success fades after six seconds, a warning after ten, and an error stays until it is closed. Do not add your own banner to a page.
- **Lists filter as you choose.** Selects in a list toolbar submit on change; search submits on Enter. A "Clear filters" link appears only when something is narrowed.

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
Dashboard (their queue, tickets waiting for somebody, AP health) · Access point list · AP detail with 24h chart · Traffic & congestion logs · Ticket queue · Ticket detail · Maintenance priority ranking · Asset risk detail · Notifications

### Admin
Users · Locations · Lab PC inventory · Access point inventory · Checklist templates · Checklist items · Shift templates · Roster generator · Audit log · Reports

### Shared
Login · Forgot password · Reset password · Profile · 403 · 404 · 419 · 429 · 500 · 503

The error pages live in `resources/views/errors/` on their own shell, not `layouts/app.blade.php`, so they render when the thing that failed is the signed-in user. Until 2026-09-04 they were Laravel's stock pages.
