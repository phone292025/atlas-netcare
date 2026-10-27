<?php

namespace App\View\Components;

use App\Enums\UserRole;
use App\Services\Shifts\AlarmService;
use App\Support\Icons;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * The signed-in shell, and everything it shows on every page: the grouped
 * navigation, the breadcrumb, the notification preview and the command menu.
 *
 * Worked out here once per request so the layout only renders. The layout is
 * on every page and must not query.
 *
 * @see resources/views/layouts/app.blade.php
 */
class AppLayout extends Component
{
    /** How many notifications the bell's preview lists. */
    private const PREVIEW = 6;

    public function __construct(private readonly AlarmService $alarms) {}

    public function render(): View
    {
        $user = auth()->user();
        $role = $user?->role instanceof UserRole ? $user->role : null;
        $entries = collect($role?->navigation() ?? [])->map(fn (array $entry) => $entry + [
            'group' => null,
            'exists' => Route::has($entry['route']),
            'active' => $this->isActive($entry['route']),
        ]);

        $current = $entries->firstWhere('active', true);

        return view('layouts.app', [
            'navigation' => $entries->groupBy(fn (array $entry) => $entry['group'] ?? ''),
            'section' => $current['group'] ?? null,
            'sectionHome' => $current && $current['exists'] ? route($current['route']) : null,
            'sectionLabel' => $current['label'] ?? null,
            'unreadAlarms' => $user ? $this->alarms->unreadCount($user) : 0,
            'recentAlarms' => $user ? $this->alarms->recentFor($user, self::PREVIEW) : collect(),
            'commands' => $this->commands($entries, $role),
            'isAssistant' => $role === UserRole::Assistant,
        ]);
    }

    /**
     * admin.users.index matches the whole admin.users.* section, so a create or
     * edit screen keeps its menu item highlighted. engineer.dashboard has no
     * resource segment, so it matches exactly.
     */
    private function isActive(string $route): bool
    {
        return substr_count($route, '.') >= 2
            ? request()->routeIs(Str::beforeLast($route, '.').'.*')
            : request()->routeIs($route);
    }

    /**
     * What the command menu offers: every screen this person can open, then
     * the actions they take most. Only routes that exist.
     *
     * @return list<array{label: string, hint: string, icon: string, url: string, svg: string}>
     */
    private function commands($entries, ?UserRole $role): array
    {
        $screens = $entries->where('exists', true)->map(fn (array $entry) => [
            'label' => $entry['label'],
            'hint' => $entry['group'] ?? 'Go to',
            'icon' => $entry['icon'],
            'url' => route($entry['route']),
        ]);

        $actions = collect(match ($role) {
            UserRole::Assistant => [
                ['Raise a ticket', 'assistant.tickets.create', 'plus'],
                ['Start an inspection round', 'assistant.inspections.index', 'list-checks'],
                ['My week', 'assistant.shifts.index', 'calendar'],
            ],
            UserRole::Engineer => [
                ['Raise a ticket', 'assistant.tickets.create', 'plus'],
                ['My tickets', 'engineer.tickets.index', 'ticket', ['mine' => 1]],
            ],
            UserRole::Admin => [
                ['Add a user', 'admin.users.create', 'plus'],
                ['Add a lab PC', 'admin.lab-pcs.create', 'plus'],
                ['Add an access point', 'admin.aps.create', 'plus'],
                ['Add a network device', 'admin.devices.create', 'plus'],
            ],
            default => [],
        })->filter(fn (array $a) => Route::has($a[1]))->map(fn (array $a) => [
            'label' => $a[0],
            'hint' => 'Action',
            'icon' => $a[2],
            'url' => route($a[1], $a[3] ?? []),
        ]);

        // Each entry carries its glyph's markup, because the menu is filtered in
        // the browser and cannot render a Blade component there.
        return collect([
            ...$screens->values()->all(),
            ...$actions->values()->all(),
            ['label' => 'Notifications', 'hint' => 'Go to', 'icon' => 'bell', 'url' => route('notifications.index')],
            ['label' => 'Profile', 'hint' => 'Go to', 'icon' => 'user-circle', 'url' => route('profile.edit')],
        ])->map(fn (array $item) => $item + ['svg' => Icons::markup($item['icon'])])->all();
    }
}
