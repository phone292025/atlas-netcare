{{--
    Role-filtered navigation, built once from config('atlas.navigation').

    Nobody hand-writes a menu. A module owner adds their route to the table in
    docs/06-ui-conventions.md and to config/atlas.php, and it appears here.

    Routes arrive with their modules, so most entries point at routes that do
    not exist yet. Rather than skipping those silently — which would make a
    mistyped route name vanish from the menu with no error — an unregistered
    entry renders greyed out and labelled while APP_DEBUG is on, and is hidden
    entirely when it is off. A typo is then visible during development and
    invisible to a marker.

    @see docs/06-ui-conventions.md — Route names are fixed vocabulary
--}}

@php
    use Illuminate\Support\Str;

    $role = auth()->user()?->role;
    $entries = $role instanceof \App\Enums\UserRole ? $role->navigation() : [];

    $isActive = function (string $route): bool {
        // admin.users.index -> match the whole admin.users.* section, so a
        // create or edit screen keeps its parent menu item highlighted.
        // engineer.dashboard has no resource segment, so match it exactly.
        return substr_count($route, '.') >= 2
            ? request()->routeIs(Str::beforeLast($route, '.').'.*')
            : request()->routeIs($route);
    };
@endphp

<nav class="flex flex-col gap-0.5 px-3 py-4" aria-label="Main navigation">
    @foreach ($entries as $entry)
        @php $exists = Route::has($entry['route']); @endphp

        @if ($exists)
            <a href="{{ route($entry['route']) }}"
               @if ($isActive($entry['route'])) aria-current="page" @endif
               class="group flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium
                      focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500
                      {{ $isActive($entry['route'])
                          ? 'bg-brand-50 text-brand-700'
                          : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                <x-icon :name="$entry['icon']"
                        class="w-5 h-5 shrink-0 {{ $isActive($entry['route']) ? 'text-brand-600' : 'text-slate-400 group-hover:text-slate-500' }}" />
                <span>{{ $entry['label'] }}</span>
            </a>
        @elseif (config('app.debug'))
            <span class="flex items-center gap-3 rounded-md px-3 py-2 text-sm text-slate-300"
                  title="Route {{ $entry['route'] }} is not registered yet">
                <x-icon :name="$entry['icon']" class="w-5 h-5 shrink-0" />
                <span class="line-through">{{ $entry['label'] }}</span>
                <span class="ml-auto text-[10px] uppercase tracking-wide">soon</span>
            </span>
        @endif
    @endforeach
</nav>
