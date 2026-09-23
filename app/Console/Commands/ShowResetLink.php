<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Print the most recent password reset link out of the log.
 *
 * FR-102 is only demonstrable if somebody can follow the link. Real delivery
 * needs a Google app password, which is a key to a personal Google account and
 * can never live in the repository, so nobody cloning this project can send
 * mail without setting one up themselves.
 *
 * With MAIL_MAILER=log, which is what .env.example ships, Laravel writes the
 * whole message into storage/logs/laravel.log instead of sending it. The link
 * is genuinely there and genuinely works. It is just buried in a file that
 * grows to megabytes, which makes "read it out of the log" advice somebody
 * quietly ignores.
 *
 * This finds it. The reset flow can then be demonstrated end to end on a
 * fresh clone with no mail account, no app password and no downloads.
 *
 *   php artisan atlas:reset-link
 *
 * LOCAL ONLY. A reset token is a way into an account, and a command that
 * prints one on a deployed system would be a hole regardless of who can run
 * it. It reads the log and never creates a token.
 *
 * @see docs/02-open-questions.md Q11
 */
class ShowResetLink extends Command
{
    protected $signature = 'atlas:reset-link {--all : Show every link still in the log, newest first}';

    protected $description = 'Print the newest password reset link from the log, for local demonstration';

    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('  This only runs locally. A reset token is a way into an account.');

            return self::FAILURE;
        }

        // Where the `single` channel writes, which is where the log mailer's
        // messages land.
        $log = config('logging.channels.single.path', storage_path('logs/laravel.log'));

        if (! is_file($log)) {
            $this->warn('  No log file yet. Request a reset first, then run this again.');

            return self::FAILURE;
        }

        if (config('mail.default') !== 'log') {
            $this->warn('  MAIL_MAILER is "'.config('mail.default').'", so links are not written to the log.');
            $this->line('  Set MAIL_MAILER=log in .env if you want to demonstrate the reset without a mail account.');
            $this->newLine();
        }

        $links = $this->linksIn($log);

        if ($links === []) {
            $this->warn('  No reset link in the log.');
            $this->newLine();
            $this->line('  Open /forgot-password, enter one of the seeded addresses, then run this again.');
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();

        foreach ($this->option('all') ? $links : [$links[0]] as $link) {
            $this->line('  '.$link);
        }

        $this->newLine();
        $this->line('  Open it in a browser to set a new password.');

        if (! $this->option('all') && count($links) > 1) {
            $this->line('  '.(count($links) - 1).' older '.str('link')->plural(count($links) - 1)
                .' in the log. Use --all to see them.');
        }

        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * Every reset URL in the log, newest first.
     *
     * Read line by line rather than with file_get_contents: this log reaches
     * megabytes on a machine that has been running the collector, and pulling
     * all of it into memory to regex it would be careless.
     *
     * The link is HTML-escaped inside the logged message, so `&amp;` has to
     * come back before the URL is usable.
     *
     * @return list<string>
     */
    private function linksIn(string $path): array
    {
        $found = [];
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            if (! str_contains($line, 'reset-password/')) {
                continue;
            }

            if (preg_match_all($this->pattern(), $line, $m)) {
                foreach ($m[0] as $url) {
                    $found[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5);
                }
            }
        }

        fclose($handle);

        return array_values(array_reverse(array_unique($found)));
    }

    /**
     * The URL pattern, built here rather than written inline.
     *
     * The logged message repeats the link as markdown, "text](href)", so
     * brackets and parentheses have to terminate the match or the first
     * result comes back as two URLs glued together.
     */
    private function pattern(): string
    {
        $stop = '\s"'.chr(39).'<>\[\]()'.chr(92).chr(92);

        return '#https?://[^'.$stop.']+/reset-password/[^'.$stop.']+#';
    }
}
