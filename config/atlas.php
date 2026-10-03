<?php

/*
|--------------------------------------------------------------------------
| Atlas NetCare
|--------------------------------------------------------------------------
|
| The project's single tuning surface. Every business rule that is a number
| lives here, never inline in code (hard rule 3, see AGENTS.md).
|
| A SHARED file: announce changes to the team before merging.
|
| Reference: docs/03-architecture.md, docs/05-data-strategy.md
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Telemetry source
    |--------------------------------------------------------------------------
    |
    | Which driver supplies access point readings, bound in
    | AppServiceProvider. An unknown name throws rather than falling back.
    |
    |   simulated  generated locally, no external dependency (default)
    |   snmp       polled from real access points; a stub until APU IT grants
    |              access
    |
    | See docs/decisions/ADR-0002-telemetry-source-driver.md
    |
    */

    'telemetry_driver' => env('ATLAS_TELEMETRY_DRIVER', 'simulated'),

    /*
    |--------------------------------------------------------------------------
    | Filling a collection gap
    |--------------------------------------------------------------------------
    |
    | With the simulated driver, the first round after the scheduler has been
    | off fills the silence with simulated readings, so the charts do not come
    | back empty. At most this many hours back, the longest chart's window. A
    | real driver never fills: a gap in real telemetry is a fact.
    |
    */

    'gap_fill_hours' => 24,

    /*
    |--------------------------------------------------------------------------
    | Demonstration data
    |--------------------------------------------------------------------------
    |
    | When true, DemoDataSeeder populates demo accounts, assets, telemetry and
    | tickets, every row tagged is_demo. Set false for a clean deployable
    | system; `php artisan atlas:purge-demo-data` removes what was seeded.
    |
    | See docs/decisions/ADR-0003-single-database-demo-flag.md
    |
    */

    'demo_mode' => (bool) env('ATLAS_DEMO_MODE', true),

    /*
    |--------------------------------------------------------------------------
    | First administrator
    |--------------------------------------------------------------------------
    |
    | There is no self-registration (FR-105), so ReferenceSeeder creates the
    | first administrator, flagged must_change_password.
    |
    | The password has no fallback here. .env.example supplies a documented
    | starting password, which the first sign-in forces the administrator to
    | replace. If .env leaves it empty, ReferenceSeeder refuses to create the
    | account and says so, rather than inventing one.
    |
    */

    'first_admin' => [
        'name' => env('ATLAS_ADMIN_NAME', 'System Administrator'),
        'email' => env('ATLAS_ADMIN_EMAIL', 'admin@atlas.local'),
        'password' => env('ATLAS_ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo accounts
    |--------------------------------------------------------------------------
    |
    | One sign-in per role, so the engineer and assistant screens can be
    | opened. Created only in demo mode, tagged is_demo, and not flagged
    | must_change_password. Two assistants, so "an assistant sees only their
    | own work" can be shown. One shared password, which is published in the
    | README: set your own before the app is reachable beyond localhost.
    |
    */

    'demo_users' => [
        'password' => env('ATLAS_DEMO_PASSWORD', 'AtlasDemo!2026'),

        'engineer' => [
            'name' => env('ATLAS_DEMO_ENGINEER_NAME', 'Yar Zar Min'),
            'email' => env('ATLAS_DEMO_ENGINEER_EMAIL', 'yar@apu.test'),
        ],

        'assistant' => [
            'name' => env('ATLAS_DEMO_ASSISTANT_NAME', 'Swan Yee Htet'),
            'email' => env('ATLAS_DEMO_ASSISTANT_EMAIL', 'swan@apu.test'),
        ],

        'assistant_two' => [
            'name' => env('ATLAS_DEMO_ASSISTANT2_NAME', 'Nyan Lin Tun'),
            'email' => env('ATLAS_DEMO_ASSISTANT2_EMAIL', 'nyan@apu.test'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Can mail actually leave this machine?
    |--------------------------------------------------------------------------
    |
    | False when MAIL_MAILER is `log` (the reset link lands in
    | storage/logs/laravel.log) or when there is no MAIL_PASSWORD (every send
    | throws). It only changes what the reset screen TELLS the user; a send is
    | always attempted.
    |
    | @see docs/02-open-questions.md Q11
    |
    */

    'mail_configured' => env('MAIL_MAILER', 'log') !== 'log'
        && filled(env('MAIL_PASSWORD')),

    /*
    |--------------------------------------------------------------------------
    | Dashboard refresh
    |--------------------------------------------------------------------------
    |
    | Seconds between polls of the live endpoints. Telemetry arrives once a
    | minute, so faster buys nothing. See ADR-0005.
    |
    */

    'poll_interval' => (int) env('ATLAS_POLL_INTERVAL', 20),

    /*
    |--------------------------------------------------------------------------
    | Access point health thresholds
    |--------------------------------------------------------------------------
    |
    | DEGRADED when latency, packet loss or client saturation breaches its
    | threshold for `consecutive_breach` readings in a row, so one noisy sample
    | cannot flip the state. OFFLINE after `offline_after_min` without a
    | reading.
    |
    | `degraded_alert_cooldown_min`: after alerting the engineers that an
    | access point is degraded, stay quiet about it for this long. At peak an
    | access point near a threshold flaps every few minutes, and an alert per
    | flap is noise.
    |
    | Working assumptions (Q1), not yet checked against a real APU access
    | point. See docs/02-open-questions.md
    |
    */

    'thresholds' => [
        'latency_ms' => 120,
        'packet_loss_pct' => 5.0,
        'client_saturation' => 0.85,
        'consecutive_breach' => 3,
        'offline_after_min' => 5,
        'degraded_alert_cooldown_min' => 60,
    ],

    /*
    |--------------------------------------------------------------------------
    | Predictive risk scoring
    |--------------------------------------------------------------------------
    |
    | Each set must sum to 1.0 (a unit test checks). Every factor is normalised
    | to 0..1, clamped, weighted, summed and scaled to 0-100. A lab PC is scored
    | on its history; an access point on telemetry TRENDS.
    |
    | Change a weight, run `php artisan atlas:recalculate-risk`, and the
    | ranking reorders. The weights are engineering judgement, not trained
    | values. See docs/decisions/ADR-0007-weighted-risk-not-ml.md
    |
    */

    'risk_weights' => [

        'lab_pc' => [
            'age' => 0.25,
            'fault_frequency' => 0.30,
            'critical_fail_rate' => 0.25,
            'days_since_inspect' => 0.20,
        ],

        'access_point' => [
            'latency_trend' => 0.30,
            'packet_loss_trend' => 0.25,
            'offline_events' => 0.30,
            'client_saturation' => 0.15,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk scoring inputs
    |--------------------------------------------------------------------------
    |
    | The ceilings that turn a measurement into a 0..1 factor. A six-year-old
    | PC scores 1.0 on age; ten tickets in ninety days is as bad as faults get;
    | a month without an inspection is a full-weight concern. Access point
    | trends are measured over two weeks: long enough that one bad evening does
    | not move them, short enough to still be a warning.
    |
    */

    'risk_inputs' => [
        'pc_life_years' => 6,
        'fault_window_days' => 90,
        'fault_ceiling' => 10,
        'inspect_ceiling_days' => 30,

        'trend_days' => 14,
        'latency_ceiling_ms' => 120,
        'packet_loss_ceiling_pct' => 5.0,
        'offline_ceiling' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk bands
    |--------------------------------------------------------------------------
    |
    | Lower bound of each band, inclusive: 64 is Medium, 65 is High. Read by
    | RiskBand::fromScore().
    |
    */

    'risk_bands' => [
        'low' => 0,
        'medium' => 40,
        'high' => 65,
        'critical' => 85,
    ],

    /*
    |--------------------------------------------------------------------------
    | Ticket service level
    |--------------------------------------------------------------------------
    |
    | Hours from opening until a ticket is due; passing it raises an SLA
    | alarm (Q3). Stamped into `due_at` when a ticket is raised, so an edit
    | here changes what NEW tickets are promised.
    |
    */

    'sla_hours' => [
        'critical' => 4,
        'medium' => 24,
        'low' => 72,
    ],

    /*
    | The lowest priority that alerts every engineer when a person raises a
    | ticket. Anything lower waits in the queue, where an engineer finds it
    | on their next look: a four-hour target cannot wait for that, a day can.
    | 'low' alerts on every ticket; null on none.
    */
    'new_ticket_alert_from' => env('ATLAS_NEW_TICKET_ALERT_FROM', 'critical'),

    /*
    |--------------------------------------------------------------------------
    | Data retention
    |--------------------------------------------------------------------------
    |
    | Raw telemetry is high volume, about 1.7 million rows a month for forty
    | access points, so it is pruned after `raw_days`. Hourly aggregates are
    | small and kept far longer. See Q10.
    |
    */

    'retention' => [
        'raw_days' => 90,
        'aggregate_months' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Shifts and reminders
    |--------------------------------------------------------------------------
    |
    | `check_in_grace_min`   how early an assistant may check in
    | `reminder_before_min`  how long before a shift its reminder is sent
    | `round_interval_min`   how often an inspection-round reminder fires
    |                        during a shift; lower it and the bell becomes
    |                        noise
    |
    | See docs/modules/M6-shifts-and-alarms.md
    |
    */

    'shifts' => [
        'check_in_grace_min' => 15,
        'reminder_before_min' => 30,
        'round_interval_min' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Calendar export, and the campus clock
    |--------------------------------------------------------------------------
    |
    | `timezone` is the campus wall clock, NOT config('app.timezone'), which
    | stays UTC. CampusClock and the calendar export read it.
    |
    | `reminder_min` is the default alert on an exported shift; the assistant
    | can change it in their own calendar.
    |
    | See docs/modules/M6-shifts-and-alarms.md
    |
    */

    'calendar' => [
        'timezone' => env('ATLAS_CALENDAR_TIMEZONE', 'Asia/Kuala_Lumpur'),
        'reminder_min' => 30,
        'name' => 'Atlas NetCare shifts',

        /*
         * Whether to offer the roster SUBSCRIPTION. Off by default: the new
         * Outlook accepts only https:// and refuses a private address, and
         * Google fetches from its own servers, so a feed served from a laptop
         * fails for two of the three major clients. Turn it on once deployed
         * behind HTTPS. Downloading a single shift works everywhere.
         */
        'subscription' => (bool) env('ATLAS_CALENDAR_SUBSCRIPTION', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    |
    | The sidebar, one list per role, drawn in groups by `group`. The command
    | menu offers the same entries. An entry whose route is not registered
    | shows struck through while APP_DEBUG is on, so a mistyped name is
    | visible, and is hidden otherwise. `short` is the label under the icon
    | in the assistant's phone tab bar, where a long one gets cut off.
    |
    */

    'navigation' => [

        'assistant' => [
            ['label' => 'My shift',        'route' => 'assistant.shifts.today',      'icon' => 'calendar-check', 'group' => 'My work'],
            ['label' => 'Inspections',     'route' => 'assistant.inspections.index', 'icon' => 'list-checks',    'group' => 'My work'],
            ['label' => 'My tickets',      'route' => 'assistant.tickets.index',     'icon' => 'ticket',         'group' => 'My work'],
            ['label' => 'Network monitor', 'route' => 'assistant.monitor.index',     'icon' => 'broadcast',      'group' => 'Network', 'short' => 'Network'],
            ['label' => 'Access points',   'route' => 'assistant.aps.index',         'icon' => 'wifi',           'group' => 'Network'],
        ],

        'engineer' => [
            ['label' => 'Dashboard',       'route' => 'engineer.dashboard',     'icon' => 'dashboard',  'group' => 'Overview'],
            ['label' => 'Network monitor', 'route' => 'engineer.monitor.index', 'icon' => 'broadcast',  'group' => 'Network'],
            ['label' => 'Access points',   'route' => 'engineer.aps.index',     'icon' => 'wifi',       'group' => 'Network'],
            ['label' => 'Traffic',         'route' => 'engineer.traffic.index', 'icon' => 'chart-line', 'group' => 'Network'],
            ['label' => 'Ticket queue',    'route' => 'engineer.tickets.index', 'icon' => 'ticket',     'group' => 'Operations'],
            ['label' => 'Maintenance',     'route' => 'engineer.risk.index',    'icon' => 'wrench',     'group' => 'Operations'],
        ],

        'admin' => [
            ['label' => 'Network monitor', 'route' => 'admin.monitor.index',         'icon' => 'broadcast',   'group' => 'Overview'],
            ['label' => 'Reports',         'route' => 'admin.reports.index',         'icon' => 'chart',       'group' => 'Overview'],
            ['label' => 'Users',           'route' => 'admin.users.index',           'icon' => 'users',       'group' => 'People'],
            ['label' => 'Roster',          'route' => 'admin.roster.index',          'icon' => 'calendar',    'group' => 'People'],
            ['label' => 'Shift templates', 'route' => 'admin.shift-templates.index', 'icon' => 'clock',       'group' => 'People'],
            ['label' => 'Locations',       'route' => 'admin.locations.index',       'icon' => 'building',    'group' => 'Assets'],
            ['label' => 'Lab PCs',         'route' => 'admin.lab-pcs.index',         'icon' => 'desktop',     'group' => 'Assets'],
            ['label' => 'Access points',   'route' => 'admin.aps.index',             'icon' => 'wifi',        'group' => 'Assets'],
            ['label' => 'Checklists',      'route' => 'admin.checklists.index',      'icon' => 'list-checks', 'group' => 'Assets'],
            ['label' => 'Audit log',       'route' => 'admin.audit.index',           'icon' => 'shield',      'group' => 'Oversight'],
        ],

    ],
];
