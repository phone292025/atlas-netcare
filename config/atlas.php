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
| Reference: docs/03-architecture.md, docs/05-data-strategy.md
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
        /*
         * NO FALLBACK, DELIBERATELY.
         *
         * This used to default to a password that is published in
         * .env.example and therefore in the public repository. A machine with
         * no ATLAS_ADMIN_PASSWORD set would silently seed the highest
         * privilege account in the system with a credential anybody could
         * read, and nothing on screen would say so.
         *
         * Null instead, and ReferenceSeeder refuses to create the account
         * without one. Failing to seed is a visible problem somebody fixes in
         * a minute; a known admin password is an invisible one.
         */
        'password' => env('ATLAS_ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Demo accounts
    |--------------------------------------------------------------------------
    |
    | One sign-in per role, so the engineer and assistant screens can actually
    | be opened. Created by DemoDataSeeder, so they exist ONLY when
    | ATLAS_DEMO_MODE is true, and every one is tagged is_demo — which means
    | `php artisan atlas:purge-demo-data` takes them away and leaves the real
    | administrator behind.
    |
    | They are NOT flagged must_change_password, unlike the first
    | administrator: being made to change the password before you can look at
    | a screen defeats the point of a demo account.
    |
    | Two assistants on purpose. A single one cannot demonstrate that an
    | assistant sees only their own inspections and tickets, which is a rule
    | worth being able to show rather than assert.
    |
    | The password is one value for all three. These accounts exist to be
    | shared with six teammates and a marker; per-account passwords would just
    | be three things to lose.
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
    | FR-102 sends a password reset link by email, and there are two separate
    | ways for that to silently do nothing:
    |
    |   MAIL_MAILER=log     nothing is sent; Laravel writes the message, reset
    |                       token and all, into storage/logs/laravel.log
    |   smtp with no auth   every send throws, because the app password that
    |                       MAIL_PASSWORD wants has not been pasted in yet
    |
    | Laravel cannot tell either of those apart from a working mailer, so the
    | reset screen asks this instead of asking config('mail.default'). It only
    | ever changes what the user is TOLD — a false value never stops a send
    | being attempted, because a wrong guess here must not disable the feature.
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
    | against a real APU access point. See docs/02-open-questions.md
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
    | Each set must sum to 1.0 — there is a unit test asserting it per set.
    | Every factor is normalised to 0..1, clamped, multiplied by its weight,
    | summed and scaled to 0-100.
    |
    | TWO SETS, because the two asset types fail in different ways and the
    | evidence available for each is not the same. A lab PC is scored on its
    | history: how old it is, how often it breaks, how badly it fails
    | inspection, how long since anybody looked at it. An access point has
    | telemetry instead, so it is scored on TRENDS — a link degrading over two
    | weeks is the signal, not a single bad reading, which is weather.
    |
    | Tuning these live during the demonstration is the point of them being
    | here. Change a weight, run `php artisan atlas:recalculate-risk`, and the
    | ranking reorders in front of the room.
    |
    | These weights are the team's engineering judgement, not values derived
    | from a training dataset. That must be stated in the report's Assumptions
    | section. See docs/decisions/ADR-0007-weighted-risk-not-ml.md
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
    | The denominators that turn a raw measurement into a 0..1 factor. Named
    | rather than written into the service, because every one of them is an
    | assumption somebody may be asked to defend.
    |
    | `pc_life_years` is the expected service life a machine's age is measured
    | against: a six-year-old PC scores 1.0 on age. `fault_window_days` and
    | `fault_ceiling` say that ten tickets in ninety days is as bad as the
    | fault factor gets. `inspect_ceiling_days` says a month without an
    | inspection is a full-weight concern.
    |
    | For access points, `trend_days` is the window a trend is measured over.
    | Two weeks is long enough that one bad evening does not move it and short
    | enough to still be a warning rather than a history lesson.
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
    | wrong, this is the edit. See docs/02-open-questions.md
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
    | See open question Q10 in docs/02-open-questions.md
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
    | `check_in_grace_min` — how early an assistant may check in. Deliberately
    | generous: somebody arriving fifteen minutes early should not be told to
    | wait, because a system that refuses trains people to ignore it.
    |
    | `reminder_before_min` — how long before a shift starts its reminder is
    | sent.
    |
    | `round_interval_min` — how often an inspection-round reminder fires
    | during a shift. A four-hour shift at 120 gets one reminder, at the
    | halfway point. Lower it and the bell becomes noise; noise is how people
    | learn to ignore notifications.
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
    | Calendar export
    |--------------------------------------------------------------------------
    |
    | Assistants add their shifts to their own phone calendar, so the phone's
    | alarm system does the reminding. It works with the app closed, the phone
    | locked and no internet, which nothing we could build would.
    |
    | `timezone` is the campus wall clock, and it is NOT config('app.timezone').
    | The application runs in UTC while shifts are stored as a date and a local
    | time, so a calendar emitting UTC would put every shift eight hours out.
    | Every exported event carries this zone explicitly.
    |
    | `reminder_min` is how long before a shift the phone should alert. The
    | assistant can change it once the event is in their calendar; this is only
    | the default we ship.
    |
    | See docs/modules/M6-shifts-and-alarms.md
    |
    */

    'calendar' => [
        'timezone' => env('ATLAS_CALENDAR_TIMEZONE', 'Asia/Kuala_Lumpur'),
        'reminder_min' => 30,
        'name' => 'Atlas NetCare shifts',

        /*
         * Whether to offer the roster SUBSCRIPTION on the shift screen.
         *
         * OFF by default, because subscribing needs a URL the calendar
         * application can actually fetch, and a development server cannot be
         * one. The new Outlook accepts only https:// for a subscribed
         * calendar and refuses a private address outright; Google Calendar
         * fetches from its own servers and cannot see a machine on your
         * Wi-Fi. Only Apple's clients accept a plain http:// feed on a local
         * network.
         *
         * So on a laptop the button fails for two of the three major clients,
         * and a control that fails in front of somebody is worse than one
         * that is not there.
         *
         * The feed itself works and is tested. Turn this on once the system
         * is deployed behind HTTPS and it becomes the better option: subscribe
         * once and nothing is ever downloaded again.
         *
         * Downloading a single shift is unaffected and works everywhere.
         */
        'subscription' => (bool) env('ATLAS_CALENDAR_SUBSCRIPTION', false),
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
    | See docs/06-ui-conventions.md
    |
    */

    'navigation' => [

        'assistant' => [
            ['label' => 'My Shift',        'route' => 'assistant.shifts.today',      'icon' => 'calendar'],
            ['label' => 'Network Monitor', 'route' => 'assistant.monitor.index',     'icon' => 'broadcast'],
            ['label' => 'Inspections',     'route' => 'assistant.inspections.index', 'icon' => 'clipboard'],
            ['label' => 'My Tickets',      'route' => 'assistant.tickets.index',     'icon' => 'ticket'],
            ['label' => 'AP Status',       'route' => 'assistant.aps.index',         'icon' => 'wifi'],
        ],

        'engineer' => [
            ['label' => 'Dashboard',       'route' => 'engineer.dashboard',     'icon' => 'gauge'],
            ['label' => 'Network Monitor', 'route' => 'engineer.monitor.index', 'icon' => 'broadcast'],
            ['label' => 'Access Points',   'route' => 'engineer.aps.index',     'icon' => 'wifi'],
            ['label' => 'Traffic Logs',    'route' => 'engineer.traffic.index', 'icon' => 'chart'],
            ['label' => 'Ticket Queue',    'route' => 'engineer.tickets.index', 'icon' => 'ticket'],
            ['label' => 'Maintenance',     'route' => 'engineer.risk.index',    'icon' => 'wrench'],
        ],

        'admin' => [
            ['label' => 'Network Monitor', 'route' => 'admin.monitor.index',         'icon' => 'broadcast'],
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
