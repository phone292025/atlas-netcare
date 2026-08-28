<?php

namespace App\Providers;

use App\Models\AccessPoint;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Models whose every write is recorded in audit_logs.
     *
     * Add a model here and it is audited — there is no second step, and no
     * call site to forget. Modules M2 to M7 append to this list as their
     * tables arrive.
     *
     * @see App\Observers\AuditObserver
     * @see docs/03-requirements.md FR-509, NFR-06
     */
    private const AUDITED = [
        User::class,
        Location::class,
        LabPc::class,
        AccessPoint::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        foreach (self::AUDITED as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
