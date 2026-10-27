<?php

use App\Http\Controllers\Admin\AccessPointController;
use App\Http\Controllers\Admin\AgentController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ChecklistTemplateController;
use App\Http\Controllers\Admin\LabPcController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\NetworkDeviceController;
use App\Http\Controllers\Admin\RosterController;
use App\Http\Controllers\Admin\ShiftTemplateController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Api\DashboardDataController;
use App\Http\Controllers\Assistant\AccessPointStatusController;
use App\Http\Controllers\Assistant\InspectionController;
use App\Http\Controllers\Assistant\ShiftController;
use App\Http\Controllers\Assistant\TicketController as AssistantTicketController;
use App\Http\Controllers\DashboardRedirect;
use App\Http\Controllers\Engineer\DashboardController;
use App\Http\Controllers\Engineer\RiskController;
use App\Http\Controllers\Engineer\TicketController as EngineerTicketController;
use App\Http\Controllers\Engineer\TrafficLogController;
use App\Http\Controllers\NetworkMonitorController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ShiftCalendarController;
use App\Http\Controllers\TicketCommentController;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;

/*
| Route names are fixed vocabulary: the sidebar renders from the exact names in
| docs/06-ui-conventions.md, and a route named anything else will not appear in
| the menu. Groups are role-prefixed; a few shared routes are guarded by a
| Policy rather than a role, and say so.
*/

// No public landing page (NFR-03): "/" goes to your home screen, or to sign-in.
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('home')
        : redirect()->route('login');
})->name('welcome');

Route::middleware('auth')->group(function () {
    // Sends each role to its own starting screen. See DashboardRedirect.
    Route::get('/home', DashboardRedirect::class)->name('home');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');

    // M1: accounts and the audit log.
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except('destroy');

        // Deactivate, never delete: a person's inspections and tickets must
        // survive them leaving (FR-106, NFR-06).
        Route::patch('users/{user}/active', [UserController::class, 'toggleActive'])
            ->name('users.toggle-active');

        Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');

        // M2: locations and inventory. `aps` because the sidebar's fixed name
        // is admin.aps.index.
        Route::resource('locations', LocationController::class)->except('show');

        // withTrashed('show'): retiring hides an asset but keeps its history
        // reachable, instead of the binding 404ing.
        Route::resource('lab-pcs', LabPcController::class)
            ->parameters(['lab-pcs' => 'lab_pc'])
            ->withTrashed(['show']);
        Route::patch('lab-pcs/{lab_pc}/restore', [LabPcController::class, 'restore'])
            ->name('lab-pcs.restore')->withTrashed();

        Route::resource('aps', AccessPointController::class)
            ->parameters(['aps' => 'access_point'])
            ->withTrashed(['show']);
        Route::patch('aps/{access_point}/restore', [AccessPointController::class, 'restore'])
            ->name('aps.restore')->withTrashed();

        // Switches, routers and servers (ADR-0016).
        Route::resource('devices', NetworkDeviceController::class)
            ->parameters(['devices' => 'network_device'])
            ->withTrashed(['show']);
        Route::patch('devices/{network_device}/restore', [NetworkDeviceController::class, 'restore'])
            ->name('devices.restore')->withTrashed();

        // Probe agents and their tokens (ADR-0017). Retired agents keep a
        // readable page; retiring is final, so there is no restore.
        Route::resource('agents', AgentController::class)
            ->only(['index', 'create', 'store', 'show', 'destroy'])
            ->withTrashed(['show']);
        Route::post('agents/{agent}/token', [AgentController::class, 'rotate'])->name('agents.rotate');

        // M3: checklist templates. No `show`: the edit screen is the only
        // rendering of a template.
        Route::resource('checklists', ChecklistTemplateController::class)
            ->parameters(['checklists' => 'checklist'])
            ->except('show');

        Route::patch('checklists/{checklist}/activate', [ChecklistTemplateController::class, 'activate'])
            ->name('checklists.activate');

        // M6: shift patterns and the roster.
        Route::resource('shift-templates', ShiftTemplateController::class)
            ->parameters(['shift-templates' => 'shift_template'])
            ->except('show');

        Route::get('roster', [RosterController::class, 'index'])->name('roster.index');
        Route::post('roster/generate', [RosterController::class, 'generate'])->name('roster.generate');

        // FR-408: how a real week diverges from the generated pattern.
        Route::patch('roster/{shift}/reassign', [RosterController::class, 'reassign'])
            ->name('roster.reassign');
        Route::delete('roster/week', [RosterController::class, 'clear'])->name('roster.clear');
        Route::delete('roster/{shift}', [RosterController::class, 'destroy'])->name('roster.destroy');
    });

    /*
    | M3: inspection rounds. `edit` is the checklist form and `update` submits
    | it; standard resource verbs rather than an invented one.
    */
    Route::middleware('role:assistant')->prefix('assistant')->name('assistant.')->group(function () {
        Route::resource('inspections', InspectionController::class)
            ->except('show');
    });

    /*
    | M4: raising and reading your own tickets. Guarded by TicketPolicy, not a
    | role: an engineer who finds a fault must be able to file it too. The
    | `assistant.` name is fixed vocabulary, not a claim about who may use it.
    */
    Route::prefix('assistant')->name('assistant.')->group(function () {
        Route::resource('tickets', AssistantTicketController::class)
            ->only(['index', 'create', 'store', 'show']);
    });

    // M7: reports (FR-410), read only, for administrators and engineers. The
    // `admin.` name is the sidebar's fixed vocabulary.
    Route::middleware('role:admin,engineer')->prefix('admin')->name('admin.')->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    });

    Route::middleware('role:engineer,admin')->prefix('engineer')->name('engineer.')->group(function () {
        Route::get('tickets', [EngineerTicketController::class, 'index'])->name('tickets.index');
        Route::get('tickets/{ticket}', [EngineerTicketController::class, 'show'])->name('tickets.show');

        // M7: maintenance priority (FR-309, FR-310). `{type}` is matched
        // against a whitelist, never used to build a class name.
        Route::get('risk', [RiskController::class, 'index'])->name('risk.index');
        Route::get('risk/{type}/{id}', [RiskController::class, 'show'])
            ->whereIn('type', ['lab-pc', 'access-point'])
            ->whereNumber('id')
            ->name('risk.show');

        // M5: telemetry and the access point dashboard. The dashboard is the
        // engineer's overview; the access point list is its own screen.
        Route::get('dashboard', [DashboardController::class, 'overview'])->name('dashboard');
        Route::get('aps', [DashboardController::class, 'index'])->name('aps.index');
        Route::get('aps/{accessPoint}', [DashboardController::class, 'show'])->name('aps.show');
        Route::get('traffic', [TrafficLogController::class, 'index'])->name('traffic.index');
    });

    // M5: read-only access point status for an assistant on a round (FR-209).
    Route::middleware('role:assistant')->prefix('assistant')->name('assistant.')->group(function () {
        Route::get('aps', [AccessPointStatusController::class, 'index'])->name('aps.index');

        // M6: an assistant's own shift. `shifts.today` is their home screen,
        // so it has to work when they have no shift at all.
        Route::get('shifts/today', [ShiftController::class, 'today'])->name('shifts.today');
        Route::get('shifts', [ShiftController::class, 'index'])->name('shifts.index');
        Route::post('shifts/{shift}/check-in', [ShiftController::class, 'checkIn'])->name('shifts.check-in');
        Route::post('shifts/{shift}/check-out', [ShiftController::class, 'checkOut'])->name('shifts.check-out');

        // Shifts to the phone's own calendar, which then does the reminding.
        Route::get('shifts/{shift}/calendar', [ShiftCalendarController::class, 'download'])
            ->name('shifts.calendar');

        Route::post('shifts/calendar/link', [ShiftCalendarController::class, 'regenerate'])
            ->name('shifts.calendar.link');
        Route::delete('shifts/calendar/link', [ShiftCalendarController::class, 'revoke'])
            ->name('shifts.calendar.revoke');
    });

    // M6: notifications for everybody. Alarm::scopeVisibleTo() decides which.
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/{alarm}', [NotificationController::class, 'show'])->name('notifications.show');

    // The dashboards' polling endpoint (FR-302, ADR-0005). Behind `auth`: a
    // campus network's layout is not public information.
    Route::get('api/dashboard', DashboardDataController::class)->name('api.dashboard');

    /*
    | M5: the Network monitor (FR-312 to FR-314). One page reached through each
    | role's prefix, because sidebar names carry the role; an assistant gets it
    | without the controls. Taking a sample and forcing a scenario write
    | telemetry and can raise tickets, so they are engineer and administrator
    | only, and SimulationService refuses both unless the simulated driver is
    | active.
    */
    Route::middleware('role:engineer,admin')->prefix('engineer')->name('engineer.')->group(function () {
        Route::get('monitor', [NetworkMonitorController::class, 'index'])->name('monitor.index');
    });
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('monitor', [NetworkMonitorController::class, 'index'])->name('monitor.index');
    });
    Route::middleware('role:assistant')->prefix('assistant')->name('assistant.')->group(function () {
        Route::get('monitor', [NetworkMonitorController::class, 'index'])->name('monitor.index');
    });

    Route::middleware('role:admin,engineer,assistant')->group(function () {
        Route::get('api/monitor', [NetworkMonitorController::class, 'data'])->name('api.monitor');
        Route::get('api/monitor/aps/{accessPoint}', [NetworkMonitorController::class, 'show'])
            ->name('api.monitor.aps.show');
    });

    Route::middleware('role:admin,engineer')->prefix('monitor')->name('monitor.')->group(function () {
        Route::post('sample', [NetworkMonitorController::class, 'sample'])->name('sample');
        Route::post('simulation', [NetworkMonitorController::class, 'simulate'])->name('simulate');
    });

    /*
    | Shared ticket actions, guarded by TicketPolicy rather than a role:
    | closing and reopening belong to the reporter, who may be an assistant.
    | Assignment (FR-307) is deliberately not a transition.
    */
    Route::patch('tickets/{ticket}/transition', [EngineerTicketController::class, 'transition'])
        ->name('tickets.transition');

    Route::patch('tickets/{ticket}/assign', [EngineerTicketController::class, 'assign'])
        ->name('tickets.assign');

    Route::post('tickets/{ticket}/comments', [TicketCommentController::class, 'store'])
        ->name('tickets.comments.store');

    // A submitted inspection is evidence behind tickets and risk scores, so
    // engineers and administrators read it too. Guarded by InspectionPolicy.
    Route::get('inspections/{inspection}', [InspectionController::class, 'show'])
        ->name('assistant.inspections.show');
});

/*
| The shift subscription feed, deliberately OUTSIDE `auth`: a calendar app
| polls it with no session, so the 48-character token in the URL is the
| credential. An unknown token is a flat 404, regenerating the link revokes
| every old copy, and it is throttled as the one route reachable without
| signing in.
*/
Route::get('calendar/{token}/atlas-shifts.ics', [ShiftCalendarController::class, 'feed'])
    ->middleware('throttle:60,1')
    ->name('shifts.calendar.feed');

// The component gallery, registered only when APP_ENV=local so no deployed
// copy can reach it. Add to it rather than building a throwaway review page.
if (app()->environment('local')) {
    Route::get('/dev/gallery', function () {
        $rows = collect(range(1, 47))->map(fn ($i) => ['id' => $i]);

        return view('dev.gallery', [
            'paginator' => new LengthAwarePaginator(
                $rows->forPage(3, 10), $rows->count(), 10, 3, ['path' => url('/dev/gallery')]
            ),
        ]);
    })->middleware('auth')->name('dev.gallery');
}

require __DIR__.'/auth.php';
