<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Refuse to start a copy with settings that would expose it.
 *
 * Runs twice in the container's start script. With --before-migrations it
 * checks only the settings, which need no database, before anything writes
 * to it: an unsafe production copy changes nothing. With --after-migrations
 * it checks what only the database knows, the demo accounts. Without either
 * it checks both. Every problem it finds is fatal when APP_ENV=production, and also
 * whenever the stack is reachable from other machines (ATLAS_BIND beyond
 * loopback), whatever APP_ENV says: the container exits, Docker restarts it,
 * and `docker compose logs app` says what to change. On a stack only this
 * machine can reach the same findings are warnings, because a development
 * machine runs with debug on and the demo accounts on purpose.
 * ATLAS_ALLOW_UNSAFE_NETWORK=true lets a demonstration on a trusted Wi-Fi
 * start anyway, and says so.
 *
 * Each check is a setting that has a safe value Atlas cannot choose for the
 * owner: their own database password, their HTTPS address, debug off, no
 * accounts with the published demo password.
 *
 * @see docs/11-installation-guide.md, "Before anyone else can reach it"
 */
class CheckDeployment extends Command
{
    protected $signature = 'atlas:check-deployment
        {--before-migrations : Only the settings, which need no database}
        {--after-migrations : Only what is in the database}';

    protected $description = 'Check the settings a production copy must not run with';

    /** Passwords that are on the internet next to this project's name, or nearly. */
    private const KNOWN_PASSWORDS = ['', 'atlas', 'password', 'secret', 'postgres', 'root', 'changeme'];

    public function handle(): int
    {
        $problems = match (true) {
            (bool) $this->option('before-migrations') => $this->settingProblems(),
            (bool) $this->option('after-migrations') => $this->databaseProblems(),
            default => $this->problems(),
        };
        $reachable = ! config('atlas.published_on_loopback');
        $fatal = app()->environment('production') || ($reachable && ! config('atlas.allow_unsafe_network'));

        if ($problems === []) {
            $this->info('Deployment settings: nothing unsafe found.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $fatal ? $this->error("  {$problem}") : $this->warn("  {$problem}");
        }

        if (! $fatal) {
            $this->line($reachable
                ? '  Starting anyway, because ATLAS_ALLOW_UNSAFE_NETWORK is on. Anyone on this network can reach these settings.'
                : '  Fine while only this machine can reach it. Published to the network, or with APP_ENV=production, these stop the app from starting.');

            return self::SUCCESS;
        }

        $this->error('Refusing to start: fix the settings above in .env, then run docker compose up -d again.'
            .($reachable && ! app()->environment('production') ? ' For a short demonstration on a Wi-Fi you trust, ATLAS_ALLOW_UNSAFE_NETWORK=true starts it anyway.' : ''));

        return self::FAILURE;
    }

    /** @return list<string> */
    public function problems(): array
    {
        return [...$this->settingProblems(), ...$this->databaseProblems()];
    }

    /**
     * What the environment says, without a query.
     *
     * @return list<string>
     */
    public function settingProblems(): array
    {
        $problems = [];
        $connection = config('database.connections.'.config('database.default'));

        if (config('app.debug')) {
            $problems[] = 'APP_DEBUG is on, so an error page shows code, queries and settings to whoever hit it. Set APP_DEBUG=false.';
        }

        if (blank(config('app.key'))) {
            $problems[] = 'APP_KEY is empty, so sessions and encrypted values have no key.';
        }

        if (($connection['driver'] ?? null) !== 'sqlite') {
            $password = (string) ($connection['password'] ?? '');

            if (in_array(strtolower($password), self::KNOWN_PASSWORDS, true) || strlen($password) < 12) {
                $problems[] = 'DB_PASSWORD is empty, a well-known password or shorter than 12 characters. Set a long random one.';
            }
        }

        if (config('atlas.demo_mode')) {
            $problems[] = 'ATLAS_DEMO_MODE is on, so seeding would create accounts with a published password. Set ATLAS_DEMO_MODE=false.';
        }

        if (! config('atlas.published_on_loopback') && ! str_starts_with((string) config('app.url'), 'https://')) {
            $problems[] = 'The app is reachable from other machines and APP_URL is not https://, so passwords and agent tokens would cross the network in the clear. Set ATLAS_SERVER_NAME and ATLAS_URL=https://... in .env: under Docker, compose builds APP_URL from ATLAS_URL (docs/11-installation-guide.md).';
        }

        if (config('atlas.ingest.allow_plain_http')) {
            $problems[] = 'ATLAS_AGENTS_ALLOW_PLAIN_HTTP is on, so agents may send their tokens over plain HTTP.';
        }

        // Production only: a demonstration on a trusted Wi-Fi has nothing
        // worth a second disk yet.
        if (app()->environment('production') && ! config('atlas.backups.elsewhere')) {
            if (! config('atlas.backups.expected')) {
                $problems[] = 'No scheduled backups: COMPOSE_PROFILES does not include backup. Add it, or set ATLAS_BACKUPS_ELSEWHERE=true if this server is backed up another way.';
            } elseif (blank(config('atlas.backups.copy_dir'))) {
                $problems[] = 'ATLAS_BACKUP_COPY_DIR is empty, so every backup is on the same disk as the database and is lost with it. Set it to another disk or a mounted network share, or set ATLAS_BACKUPS_ELSEWHERE=true if backups/ is copied off this machine another way.';
            }
        }

        return $problems;
    }

    /** @return list<string> */
    public function databaseProblems(): array
    {
        if (Schema::hasTable('users') && User::query()->where('is_demo', true)->exists()) {
            return ['Demo accounts exist, and their password is published. Run php artisan atlas:purge-demo-data.'];
        }

        return [];
    }
}
