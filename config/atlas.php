<?php

/*
|--------------------------------------------------------------------------
| Atlas NetCare
|--------------------------------------------------------------------------
|
| The project's single tuning surface. Every business rule that is a number
| lives here, never inline in code (hard rule 3, see AGENTS.md).
|
| This is a SHARED file. Announce changes to the team before merging — a
| conflict here blocks everyone.
|
| Reference: docs/05-architecture.md, docs/07-data-strategy.md
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Telemetry source
    |--------------------------------------------------------------------------
    |
    | Which driver supplies access point readings.
    |
    |   simulated  generated locally, no external dependency (default)
    |   snmp       polled from real access points, needs APU IT access
    |
    | PLANNED (M5): AppServiceProvider will bind the interface to the driver named
    | here. Nothing reads this key yet — app/Services/Telemetry does not exist.
    | Switching drivers will require no change outside that folder.
    | See docs/decisions/ADR-0002-telemetry-source-driver.md
    |
    */

    'telemetry_driver' => env('ATLAS_TELEMETRY_DRIVER', 'simulated'),

    /*
    |--------------------------------------------------------------------------
    | Demonstration data
    |--------------------------------------------------------------------------
    |
    | When true, DemoDataSeeder runs and populates demo assets, telemetry and
    | tickets, every row tagged is_demo. Set false for a clean deployable
    | system, and run `php artisan atlas:purge-demo-data` to remove data that
    | has already been seeded.
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
    | Atlas NetCare has no self-registration: an administrator creates every
    | account (FR-105). Somebody therefore has to create the first
    | administrator, and that is ReferenceSeeder's job.
    |
    | The password comes from the environment, never from this file. A
    | committed default password is a credential in the repository, and this
    | repository is shared with six other people.
    |
    | The seeded account is flagged must_change_password so the first sign-in
    | is expected to replace it.
    |
    */

    'first_admin' => [
        'name' => env('ATLAS_ADMIN_NAME', 'System Administrator'),
        'email' => env('ATLAS_ADMIN_EMAIL', 'admin@atlas.local'),
        'password' => env('ATLAS_ADMIN_PASSWORD', 'ChangeMeNow!2026'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard refresh
    |--------------------------------------------------------------------------
    |
    | Seconds between polls of the live dashboard endpoint. Telemetry is only
    | collected once a minute, so refreshing faster than this buys nothing.
    |
    | See docs/decisions/ADR-0005-ajax-polling-not-websockets.md
    |
    */

    'poll_interval' => (int) env('ATLAS_POLL_INTERVAL', 20),

    /*
    |--------------------------------------------------------------------------
    | Access point health thresholds
    |--------------------------------------------------------------------------
    |
    | An access point is DEGRADED when latency, packet loss or client
    | saturation breaches its threshold for `consecutive_breach` readings in a
    | row. Requiring consecutive breaches stops one noisy sample flipping an
    | access point's state.
    |
    | It is OFFLINE when no reading has arrived for `offline_after_min`.
    |
    | PROVISIONAL: these numbers are the working assumption recorded against
    | open question Q1. They have not been ratified by the team or checked
    | against a real APU access point. See docs/04-open-questions.md
    |
    */

    'thresholds' => [
        'latency_ms' => 120,
        'packet_loss_pct' => 5.0,
        'client_saturation' => 0.85,
        'consecutive_breach' => 3,
        'offline_after_min' => 5,
    ],

    /*
    |--------------------------------------------------------------------------
    | Predictive risk scoring
    |--------------------------------------------------------------------------
    |
    | Weights must sum to 1.0 — there is a unit test asserting this. Each
    | factor is normalised to 0..1, clamped, multiplied by its weight, summed
    | and scaled to 0-100.
    |
    | These weights are the team's engineering judgement, not values derived
    | from a training dataset. That must be stated in the report's Assumptions
    | section. See docs/decisions/ADR-0007-weighted-risk-not-ml.md
    |
    */

    'risk_weights' => [
        'age' => 0.25,
        'fault_frequency' => 0.30,
        'critical_fail_rate' => 0.25,
        'days_since_inspect' => 0.20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk bands
    |--------------------------------------------------------------------------
    |
    | Lower bound of each band, inclusive. A score of 40 is Medium, 64 is still
    | Medium, 65 is High. Read by RiskBand::fromScore().
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
    | Hours from a ticket being opened until it is due. Breaching this raises
    | an SLA alarm.
    |
    | Q3 was settled on 2026-08-28 and M4 implements it. TicketService stamps
    | `due_at` from these values once, when the ticket is raised — so editing a
    | number here changes what NEW tickets are promised and leaves existing
    | ones on the target they were given.
    |
    | These three lines are the whole SLA. If the team decides the targets are
    | wrong, this is the edit. See docs/04-open-questions.md
    |
    */

    'sla_hours' => [
        'critical' => 4,
        'medium' => 24,
        'low' => 72,
    ],

    /*
    |--------------------------------------------------------------------------
    | Data retention
    |--------------------------------------------------------------------------
    |
    | Raw telemetry is high volume: 40 access points at one reading a minute is
    | roughly 1.7 million rows a month. Raw readings are pruned after
    | `raw_days`; hourly aggregates are kept far longer because they are small.
    |
    | See open question Q10 in docs/04-open-questions.md
    |
    */

    'retention' => [
        'raw_days' => 90,
        'aggregate_months' => 12,
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    |
    | The sidebar is built once from this map and filtered by the signed-in
    | user's role. Seven people must never each invent their own navigation.
    |
    | Most routes listed here do not exist yet — they arrive with their
    | modules. The sidebar component skips any entry whose route is not
    | registered, so this map can be complete from day one without breaking
    | the application.
    |
    | See docs/08-ui-conventions.md
    |
    */

    'navigation' => [

        'assistant' => [
            ['label' => 'My Shift',    'route' => 'assistant.shifts.today',      'icon' => 'calendar'],
            ['label' => 'Inspections', 'route' => 'assistant.inspections.index', 'icon' => 'clipboard'],
            ['label' => 'My Tickets',  'route' => 'assistant.tickets.index',     'icon' => 'ticket'],
            ['label' => 'AP Status',   'route' => 'assistant.aps.index',         'icon' => 'wifi'],
        ],

        'engineer' => [
            ['label' => 'Dashboard',     'route' => 'engineer.dashboard',     'icon' => 'gauge'],
            ['label' => 'Access Points', 'route' => 'engineer.aps.index',     'icon' => 'wifi'],
            ['label' => 'Traffic Logs',  'route' => 'engineer.traffic.index', 'icon' => 'chart'],
            ['label' => 'Ticket Queue',  'route' => 'engineer.tickets.index', 'icon' => 'ticket'],
            ['label' => 'Maintenance',   'route' => 'engineer.risk.index',    'icon' => 'wrench'],
        ],

        'admin' => [
            ['label' => 'Users',           'route' => 'admin.users.index',           'icon' => 'users'],
            ['label' => 'Locations',       'route' => 'admin.locations.index',       'icon' => 'building'],
            ['label' => 'Lab PCs',         'route' => 'admin.lab-pcs.index',         'icon' => 'desktop'],
            ['label' => 'Access Points',   'route' => 'admin.aps.index',             'icon' => 'wifi'],
            ['label' => 'Checklists',      'route' => 'admin.checklists.index',      'icon' => 'clipboard'],
            ['label' => 'Shift Templates', 'route' => 'admin.shift-templates.index', 'icon' => 'calendar'],
            ['label' => 'Roster',          'route' => 'admin.roster.index',          'icon' => 'calendar'],
            ['label' => 'Audit Log',       'route' => 'admin.audit.index',           'icon' => 'shield'],
            ['label' => 'Reports',         'route' => 'admin.reports.index',         'icon' => 'chart'],
        ],

    ],

];
