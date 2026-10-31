<?php

namespace App\Providers;

use App\Models\AccessPoint;
use App\Models\Agent;
use App\Models\ChecklistTemplate;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\NetworkDevice;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\Ticket;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Support\CampusClock;
use App\Support\ResetLinkOnScreen;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        Agent::class,
        Location::class,
        LabPc::class,
        AccessPoint::class,
        NetworkDevice::class,
        Ticket::class,
        Shift::class,
        ChecklistTemplate::class,
        ShiftTemplate::class,
    ];

    public function boot(): void
    {
        foreach (self::AUDITED as $model) {
            $model::observe(AuditObserver::class);
        }

        $this->composeResetPasswordEmail();
        $this->registerCampusClock();
        $this->limitIngest();
    }

    /**
     * Rate limits on the ingest API (ADR-0017). Per address before the token
     * is checked, so a caller without one can't keep the app busy, and per
     * agent after, so one agent can't crowd out the rest. An agent sends a
     * batch every 30 to 60 seconds, well inside both.
     */
    private function limitIngest(): void
    {
        RateLimiter::for('agent-address', fn (Request $request) => Limit::perMinute((int) config('atlas.ingest.per_address_per_minute'))
            ->by('ingest-address:'.$request->ip()));

        RateLimiter::for('agent', fn (Request $request) => Limit::perMinute((int) config('atlas.ingest.per_agent_per_minute'))
            ->by('ingest-agent:'.$request->attributes->get('agent')?->getKey()));
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
             * Local, debug on, no working mailer, and somebody at this
             * machine: see ResetLinkOnScreen.
             */
            if (self::resetLinkOnScreen(request())) {
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

    /**
     * ResetLinkOnScreen, asked about a live request: who sent it, to which
     * host, and whether a proxy forwarded it. The view asks the same.
     */
    public static function resetLinkOnScreen(Request $request): bool
    {
        return ResetLinkOnScreen::allowed(
            $request->server('REMOTE_ADDR'),
            $request->getHost(),
            $request->headers->has('X-Forwarded-For') || $request->headers->has('Forwarded') || $request->headers->has('Via'),
        );
    }
}
