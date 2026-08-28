<?php

use App\Http\Controllers\Admin\AccessPointController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\LabPcController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardRedirect;
use App\Http\Controllers\ProfileController;
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
    });
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
