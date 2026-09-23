<?php

namespace App\View\Components;

use App\Services\Shifts\AlarmService;
use Illuminate\View\Component;
use Illuminate\View\View;

class AppLayout extends Component
{
    public function __construct(private readonly AlarmService $alarms) {}

    /**
     * The signed-in shell. The notification count is fetched here rather than
     * in the layout, which is on every page and must not query.
     */
    public function render(): View
    {
        $user = auth()->user();

        return view('layouts.app', [
            'unreadAlarms' => $user ? $this->alarms->unreadCount($user) : 0,
        ]);
    }
}
