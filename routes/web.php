<?php

use App\Http\Controllers\Admin\AccessPointController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\ChecklistTemplateController;
use App\Http\Controllers\Admin\LabPcController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Api\DashboardDataController;
use App\Http\Controllers\Assistant\AccessPointStatusController;
use App\Http\Controllers\Assistant\InspectionController;
use App\Http\Controllers\Assistant\TicketController as AssistantTicketController;
use App\Http\Controllers\DashboardRedirect;
use App\Http\Controllers\Engineer\DashboardController;
use App\Http\Controllers\Engineer\TicketController as EngineerTicketController;
use App\Http\Controllers\Engineer\TrafficLogController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TicketCommentController;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Route;

/*
| Route names are fixed vocabulary. Before adding one, check the table in
| docs/08-ui-conventions.md — the sidebar renders from those exact names, and a
| route named anything else will not appear in the menu.
|
| Role-guarded groups arrive with their modules:
|
|   Route::middleware('role:assistant')->prefix('assistant')->name('assistant.')
|   Route::middleware('role:engineer')->prefix('engineer')->name('engineer.')
|   Route::middleware('role:admin')->prefix('admin')->name('admin.')
*/

/*
| There is no public landing page, by design. Atlas NetCare is an internal,
| privilege-based system with no student-facing entry point (proposal Keynote,
| NFR-03), so "/" sends a signed-in user to their own home screen and everyone
| else to the sign-in form.
|
| This replaces Laravel's welcome page, which advertised the framework — a
| marker opening the system saw "Deploy now" and a Laracasts link rather than
| the project.
*/
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

    /*
    | M1 — Identity, roles and audit log.
    |
    | Route names come from the table in docs/08-ui-conventions.md. The sidebar
    | renders from those exact names; anything else silently vanishes from the
    | menu.
    */
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::resource('users', UserController::class)->except('destroy');

        // Deactivate, never delete — a person's inspections and tickets must
        // survive them leaving (FR-106, NFR-06).
        Route::patch('users/{user}/active', [UserController::class, 'toggleActive'])
            ->name('users.toggle-active');

        Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');

        /*
        | M2 — locations and asset inventory.
        |
        | `aps` rather than `access-points` in the URL and route name, because
        | docs/08-ui-conventions.md fixes it as admin.aps.index and the sidebar
        | renders from that exact name.
        */
        Route::resource('locations', LocationController::class)->except('show');

        /*
        | `withTrashed('show')` on both resources is what makes "retiring
        | hides it but keeps its history reachable" true. Without it the
        | model binding 404s the moment a machine is retired, and the
        | history the retirement was supposed to preserve is unreachable.
        */
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

        /*
        | M3 — checklist templates.
        |
        | `checklists` in the URL and route name, because
        | docs/08-ui-conventions.md fixes it as admin.checklists.index.
        | The model is ChecklistTemplate, so the parameter is spelled out.
        |
        | No `show`: a template's only readable form is the edit screen, and
        | a second read-only rendering of the same list of items would be one
        | more thing to keep in step.
        */
        Route::resource('checklists', ChecklistTemplateController::class)
            ->parameters(['checklists' => 'checklist'])
            ->except('show');

        Route::patch('checklists/{checklist}/activate', [ChecklistTemplateController::class, 'activate'])
            ->name('checklists.activate');
    });

    /*
    | M3 — inspection rounds.
    |
    | Only `assistant.inspections.index` is fixed vocabulary; the rest are
    | standard resource verbs beneath it, which the convention allows.
    |
    | `edit` is the checklist form and `update` submits it. That is an odd
    | fit for the words, but the alternative is inventing a verb — and the
    | sidebar, the tests and six other people all read the standard set.
    */
    Route::middleware('role:assistant')->prefix('assistant')->name('assistant.')->group(function () {
        Route::resource('inspections', InspectionController::class)
            ->except('show');
    });

    /*
    | M4 — ticketing.
    |
    | Raising and reading your own tickets is not assistant-only: an engineer
    | who finds a fault while working on something else must be able to file
    | it, or faults stop being recorded. So this group is guarded by
    | TicketPolicy rather than by a role, and `assistant.tickets.*` is a name
    | from the fixed vocabulary rather than a claim about who may use it.
    */
    Route::prefix('assistant')->name('assistant.')->group(function () {
        Route::resource('tickets', AssistantTicketController::class)
            ->only(['index', 'create', 'store', 'show']);
    });

    // The queue itself is engineer furniture, so it stays role-guarded.
    Route::middleware('role:engineer,admin')->prefix('engineer')->name('engineer.')->group(function () {
        Route::get('tickets', [EngineerTicketController::class, 'index'])->name('tickets.index');
        Route::get('tickets/{ticket}', [EngineerTicketController::class, 'show'])->name('tickets.show');

        /*
        | M5 — telemetry and the AP dashboard.
        |
        | `engineer.dashboard`, `engineer.aps.index` and
        | `engineer.traffic.index` are fixed vocabulary from
        | docs/08-ui-conventions.md; the sidebar renders from those exact
        | names.
        */
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('aps', [DashboardController::class, 'index'])->name('aps.index');
        Route::get('aps/{accessPoint}', [DashboardController::class, 'show'])->name('aps.show');
        Route::get('traffic', [TrafficLogController::class, 'index'])->name('traffic.index');
    });

    // M5 — read-only AP status for an assistant on a round (FR-209).
    Route::middleware('role:assistant')->prefix('assistant')->name('assistant.')->group(function () {
        Route::get('aps', [AccessPointStatusController::class, 'index'])->name('aps.index');
    });

    /*
    | The dashboard's polling endpoint (FR-302, ADR-0005).
    |
    | Behind `auth` deliberately: the access point layout of a campus network
    | is not public information, and an unauthenticated JSON endpoint would
    | publish it.
    */
    Route::get('api/dashboard', DashboardDataController::class)->name('api.dashboard');

    /*
    | Both shared endpoints are guarded by TicketPolicy, not by a role.
    |
    | Transitions cannot live in the engineer group: closing and reopening
    | belong to the person who reported the fault, and an assistant behind
    | `role:engineer` could never reach the route that lets them say "this was
    | not actually fixed". Which of the six moves each person may make is a
    | per-record question, and that is a Policy's job.
    */
    Route::patch('tickets/{ticket}/transition', [EngineerTicketController::class, 'transition'])
        ->name('tickets.transition');

    Route::post('tickets/{ticket}/comments', [TicketCommentController::class, 'store'])
        ->name('tickets.comments.store');

    /*
    | The summary of a submitted inspection is readable by engineers and
    | admins too — it is the evidence behind a ticket and a risk score —
    | so it sits outside the assistant-only group and is guarded by
    | InspectionPolicy instead.
    */
    Route::get('inspections/{inspection}', [InspectionController::class, 'show'])
        ->name('assistant.inspections.show');
});

/*
| Component gallery.
|
| Registered ONLY when APP_ENV=local, so it cannot be reached on any deployed
| copy. It exists because seventeen shared components were built before any
| screen used them: seven module owners need to see what already exists rather
| than each building their own version of a card.
|
| It doubles as the source of screenshots for the Screens Design chapter, and
| as a visual regression check — a component that breaks shows up here before
| it shows up in somebody's module.
*/
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
