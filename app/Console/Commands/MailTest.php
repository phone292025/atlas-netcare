<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Prove that mail actually leaves this machine.
 *
 * FR-102 emails a password reset link. Everything around that is covered by
 * the suite, but the suite fakes the mailer, so a green run says nothing at
 * all about whether SMTP works. The only honest test is to send one.
 *
 * The usual failure is MAIL_PASSWORD: a Google app password that was never
 * pasted, was pasted with the display spaces Google shows it with, or has
 * since been revoked. Laravel reports all three as one unhelpful line, so
 * this command reads the exception and says which it probably is.
 *
 *   php artisan atlas:mail-test
 *   php artisan atlas:mail-test someone@example.com
 *
 * Run it after changing anything under MAIL_ in .env, and once before the
 * demonstration — an app password can be revoked from the Google account
 * without anything here changing.
 *
 * @see docs/02-open-questions.md Q11
 */
class MailTest extends Command
{
    protected $signature = 'atlas:mail-test {to? : Where to send it, defaults to MAIL_FROM_ADDRESS}';

    protected $description = 'Send one real email, to prove SMTP is configured';

    public function handle(): int
    {
        $to = $this->argument('to') ?? config('mail.from.address');

        if (blank($to)) {
            $this->error('No recipient. Pass one, or set MAIL_FROM_ADDRESS in .env.');

            return self::FAILURE;
        }

        $mailer = config('mail.default');

        $this->line('');
        $this->line("  Mailer     <options=bold>{$mailer}</>");

        if ($mailer === 'log') {
            $this->line('  Host       —');
            $this->warn('');
            $this->warn('  MAIL_MAILER is `log`, so nothing will be sent anywhere.');
            $this->warn('  Laravel will write the message into storage/logs/laravel.log');
            $this->warn('  and the password reset screen will still claim it emailed you.');
            $this->line('');

            return self::FAILURE;
        }

        $this->line('  Host       '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port'));
        $this->line('  Username   '.config('mail.mailers.smtp.username'));
        $this->line('  From       '.config('mail.from.address'));
        $this->line('  To         <options=bold>'.$to.'</>');
        $this->line('');

        if (blank(config('mail.mailers.smtp.password'))) {
            $this->error('  MAIL_PASSWORD is empty. Paste a Google app password into .env.');
            $this->line('');
            $this->line('  Google Account -> Security -> 2-Step Verification -> App passwords.');
            $this->line('  Paste the 16 characters WITHOUT the spaces Google displays.');
            $this->line('');

            return self::FAILURE;
        }

        try {
            Mail::raw($this->body(), function ($message) use ($to) {
                $message->to($to)->subject('Atlas NetCare mail test');
            });
        } catch (TransportExceptionInterface $e) {
            $this->error('  The mail server refused it.');
            $this->line('');
            $this->line('  '.$e->getMessage());
            $this->line('');
            $this->hint($e->getMessage());

            return self::FAILURE;
        }

        $this->info("  Sent. Check {$to} — including the spam folder, which is where");
        $this->info('  the first message from a new app password usually lands.');
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Turn the SMTP server's wording into something actionable.
     *
     * Gmail answers a bad app password with a 535 and a support URL, which
     * reads like a server problem and is almost always a pasting problem.
     */
    private function hint(string $error): void
    {
        $hint = match (true) {
            str_contains($error, '535') || str_contains($error, 'Username and Password not accepted') => 'That is a rejected login. Check MAIL_PASSWORD is a Google APP password '
                    ."(not the account password), that you removed the spaces, and that\n"
                    .'  MAIL_USERNAME is the full address including @gmail.com.',

            str_contains($error, 'Connection could not be established')
                || str_contains($error, 'Connection timed out') => 'Nothing answered on that host and port. Campus and hotel networks '
                    ."commonly block outbound port 587; try a phone hotspot to find out\n"
                    .'  whether it is the network rather than the configuration.',

            str_contains($error, 'certificate') || str_contains($error, 'SSL') => 'A TLS problem. XAMPP ships without a CA bundle unless curl.cainfo and '
                    ."openssl.cafile are set in php.ini. Point both at a cacert.pem.\n",

            default => 'Search that message. It came from the mail server, not from Laravel.',
        };

        $this->line('  '.$hint);
        $this->line('');
    }

    private function body(): string
    {
        return <<<TXT
        This is the Atlas NetCare mail test.

        If you are reading it, SMTP works and FR-102 can deliver a password
        reset link to a real inbox.

        Sent {$this->now()} from {$this->host()}.
        TXT;
    }

    private function now(): string
    {
        return now()->setTimezone(config('atlas.calendar.timezone'))->format('D j M Y, H:i T');
    }

    private function host(): string
    {
        return gethostname() ?: 'an unknown machine';
    }
}
