<?php

namespace App\Providers;

use App\Models\AccessPoint;
use App\Models\ChecklistTemplate;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\ShiftTemplate;
use App\Models\Ticket;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Services\Telemetry\SimulatedTelemetrySource;
use App\Services\Telemetry\SnmpTelemetrySource;
use App\Services\Telemetry\TelemetrySource;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

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
     * @see docs/01-requirements.md FR-509, NFR-06
     */
    private const AUDITED = [
        User::class,
        Location::class,
        LabPc::class,
        AccessPoint::class,

        /*
         * Tickets carry the only state machine in the
         * system, and were the one module with no history at all — which was
         * exactly backwards. It surfaced when a ticket turned up holding a
         * closed_at eight hours before its own opened_at and there was
         * nothing to reconstruct what had happened to it.
         *
         * Every transition now leaves a row naming the actor, the change and
         * the IP, which is what makes "who closed this and when" answerable.
         */
        Ticket::class,

        /*
         * Both are administrator-editable configuration that
         * changes what everybody else's work means: a checklist template
         * decides what an inspection asks, and a shift template decides who is
         * rostered. Changing either silently was possible until now, and
         * "who changed the checklist, and when" was unanswerable — which
         * matters more than usual here, because M3 versions templates
         * precisely so history stays meaningful.
         */
        ChecklistTemplate::class,
        ShiftTemplate::class,
    ];

    /**
     * The one place the telemetry driver is chosen.
     *
     * This binding IS the swap ADR-0002 is about: everything downstream —
     * TelemetryService, the collector command, the dashboard, the charts —
     * depends on the interface and cannot tell which implementation it got.
     * Going live is this line resolving differently.
     *
     * An unrecognised driver name throws rather than falling back to the
     * simulator. A typo in .env that silently produced invented data on a
     * system meant to be showing real readings is the worst outcome available
     * here.
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
    }

    /**
     * The password reset email (FR-102).
     *
     * Laravel's stock version says "Hello!", "Regards, Atlas NetCare" and
     * "If you did not request a password reset, no further action is
     * required", which is wording written for a public signup form. On an
     * internal system where an administrator creates every account, an
     * unrequested reset email means somebody typed a colleague's address into
     * the form, and the right advice is to say so rather than to ignore it.
     *
     * Done here with toMailUsing() rather than by overriding
     * sendPasswordResetNotification() on User, because there is one email and
     * no reason for the model to know how it is worded.
     *
     * The expiry is read from config rather than written into the sentence.
     * Someone will change auth.passwords.users.expire eventually, and an
     * email that states the wrong number is worse than one that omits it.
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
             * On a machine that cannot send mail, put the link on the screen.
             *
             * Delivery needs a Google app password, which is a key to a
             * personal account and can never be committed, so nobody cloning
             * this project can send mail. Without this, a reviewer clicking
             * "forgot password" reaches a dead end and FR-102 looks broken
             * when it is only undelivered.
             *
             * The token is real, single-use and still expires. This changes
             * where the link is shown, not how the flow works.
             *
             * THREE CONDITIONS, ALL REQUIRED. Local environment, debug on,
             * and no working mailer. A deployment fails the first two, and a
             * machine that can genuinely send mail has no reason to print a
             * reset token on a page anybody can open.
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
