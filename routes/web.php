<?php

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

Route::get('/', function () {
    return auth()->check() ? redirect()->route('home') : view('welcome');
})->name('welcome');

Route::middleware('auth')->group(function () {
    // Sends each role to its own starting screen. See DashboardRedirect.
    Route::get('/home', DashboardRedirect::class)->name('home');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
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
