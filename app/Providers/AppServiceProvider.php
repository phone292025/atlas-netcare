<?php

namespace App\Providers;

use App\Models\AccessPoint;
use App\Models\ChecklistTemplate;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\Ticket;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Telemetry\SimulatedTelemetrySource;
use App\Services\Telemetry\SnmpTelemetrySource;
use App\Services\Telemetry\TelemetrySource;
use App\Support\CampusClock;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Models whose every write is recorded in audit_logs. Add a model here and
     * it is audited; there is no call site to forget.
     *
     * Tickets because they carry the state machine, so "who closed this and
     * when" is answerable. Shifts because they are attendance: who was given
     * one, who took it over, who removed it. The two templates because they
     * are configuration that changes what everybody else's work means.
     *
     * @see App\Observers\AuditObserver
     * @see docs/01-requirements.md FR-509, NFR-06
     */
    private const AUDITED = [
        User::class,
        Location::class,
        LabPc::class,
        AccessPoint::class,
        NetworkDevice::class,
        Ticket::class,
        Shift::class,
        ChecklistTemplate::class,
        ShiftTemplate::class,
    ];

    /**
     * The one place the telemetry driver is chosen (ADR-0002). Everything
     * downstream depends on the interface, so going live is this binding
     * resolving differently.
     *
     * An unknown driver name throws rather than falling back to the simulator:
     * a typo that silently showed invented data as real is the worst outcome.
     *
     * @see docs/decisions/ADR-0002-telemetry-source-driver.md
     */
    public function register(): void
    {
        $this->app->bind(TelemetrySource::class, function () {
            $driver = config('atlas.telemetry_driver');

            return match ($driver) {
                'simulated' => new SimulatedTelemetrySource,
                'snmp', 'live' => new SnmpTelemetrySource,
                default => throw new InvalidArgumentException(
                    "Unknown telemetry driver [{$driver}]. Set ATLAS_TELEMETRY_DRIVER to 'simulated' or 'snmp'."
                ),
            };
        });
    }

    public function boot(): void
    {
        foreach (self::AUDITED as $model) {
            $model::observe(AuditObserver::class);
        }

        $this->composeResetPasswordEmail();
        $this->registerCampusClock();
    }

    /**
     * `->campus()` on any date: the same moment, on the campus wall clock, for
     * SHOWING a stored UTC moment to a person:
     *
     *     {{ $shift->checked_in_at->campus()->format('H:i') }}
     *
     * Returns a copy, so the attribute is never mutated. Not a static closure,
     * because Carbon binds the date it is called on to $this.
     *
     * @see App\Support\CampusClock
     */
    private function registerCampusClock(): void
    {
        Carbon::macro('campus', function () {
            /** @var Carbon $this */
            return $this->copy()->setTimezone(CampusClock::timezone());
        });
    }

    /**
     * The password reset email (FR-102).
     *
     * Laravel's wording is written for a public signup form. Here only an
     * administrator creates accounts, so an unrequested reset means somebody
     * typed a colleague's address, and the email says so. The expiry comes
     * from config, so the sentence cannot state the wrong number.
     *
     * @see docs/01-requirements.md FR-102
     * @see docs/02-open-questions.md Q11
     */
    private function composeResetPasswordEmail(): void
    {
        ResetPassword::toMailUsing(function (User $user, string $token) {
            $minutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], false));

            /*
             * On a machine that cannot send mail, put the link on the screen,
             * so a fresh clone can still use the flow. The token is real,
             * single-use and still expires.
             *
             * All three conditions: local, debug on, and no working mailer. A
             * deployment fails the first two, and a machine that can send mail
             * has no reason to print a reset token on a page.
             */
            if (app()->environment('local')
                && config('app.debug')
                && ! config('atlas.mail_configured')) {
                session()->flash('reset_link', $url);
            }

            return (new MailMessage)
                ->subject('Reset your Atlas NetCare password')
                ->greeting("Hello {$user->name},")
                ->line('Somebody asked to reset the password on your Atlas NetCare account.')
                ->action('Choose a new password', $url)
                ->line("This link stops working in {$minutes} minutes, and once you have used it.")
                ->line('If that was not you, your password has not changed and nothing has happened yet. Tell an administrator, because on this system only an administrator can create an account, so somebody knows your address.')
                ->salutation('Atlas NetCare, Asia Pacific University');
        });
    }
}
