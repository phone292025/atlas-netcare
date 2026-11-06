<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * atlas:check-deployment, which the container runs before serving: fatal in
 * production, warnings anywhere else.
 */
class CheckDeploymentTest extends TestCase
{
    use RefreshDatabase;

    /** Settings a production copy can safely start with. */
    private function safeProduction(): void
    {
        $this->app['env'] = 'production';

        config([
            'app.debug' => false,
            'app.url' => 'https://atlas.lab.internal',
            'atlas.demo_mode' => false,
            'atlas.published_on_loopback' => false,
            'atlas.ingest.allow_plain_http' => false,
            'atlas.backups.expected' => true,
            'atlas.backups.copy_dir' => '/mnt/nas/atlas-backups',
            'atlas.backups.elsewhere' => false,
            'database.connections.'.config('database.default').'.password' => 'a7f3c9e1b2d84f6a',
        ]);
    }

    public function test_a_safe_production_copy_starts(): void
    {
        $this->safeProduction();

        $this->artisan('atlas:check-deployment')->assertSuccessful()->expectsOutputToContain('nothing unsafe found');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function unsafeSettings(): array
    {
        return [
            'debug on' => [['app.debug' => true], 'APP_DEBUG'],
            'no app key' => [['app.key' => ''], 'APP_KEY'],
            'the old compose password' => [['password' => 'atlas'], 'DB_PASSWORD'],
            'no database password' => [['password' => ''], 'DB_PASSWORD'],
            'a short database password' => [['password' => 'k3y!x9'], 'DB_PASSWORD'],
            'demo mode on' => [['atlas.demo_mode' => true], 'ATLAS_DEMO_MODE'],
            'reachable over plain http' => [['app.url' => 'http://atlas.lab.internal'], 'https://'],
            'agents allowed plain http' => [['atlas.ingest.allow_plain_http' => true], 'ATLAS_AGENTS_ALLOW_PLAIN_HTTP'],
            'no scheduled backups' => [['atlas.backups.expected' => false], 'COMPOSE_PROFILES'],
            'backups only on this disk' => [['atlas.backups.copy_dir' => ''], 'ATLAS_BACKUP_COPY_DIR'],
        ];
    }

    /** @param  array<string, mixed>  $setting */
    #[DataProvider('unsafeSettings')]
    public function test_production_refuses_to_start_on_an_unsafe_setting(array $setting, string $named): void
    {
        $this->safeProduction();

        if (array_key_exists('password', $setting)) {
            // SQLite has no password, so these run in the PostgreSQL suite.
            if (DB::getDriverName() === 'sqlite') {
                $this->markTestSkipped('SQLite has no database password.');
            }

            $setting = ['database.connections.'.config('database.default').'.password' => $setting['password']];
        }

        config($setting);

        $this->artisan('atlas:check-deployment')->assertFailed()->expectsOutputToContain($named);
    }

    public function test_a_production_server_backed_up_another_way_can_say_so(): void
    {
        $this->safeProduction();
        config(['atlas.backups.expected' => false, 'atlas.backups.copy_dir' => '', 'atlas.backups.elsewhere' => true]);

        $this->artisan('atlas:check-deployment')->assertSuccessful();
    }

    public function test_a_reachable_development_copy_needs_no_second_backup_disk(): void
    {
        $this->safeProduction();
        $this->app['env'] = 'local';
        config(['atlas.backups.copy_dir' => '', 'atlas.backups.expected' => false]);

        $this->artisan('atlas:check-deployment')->assertSuccessful()->expectsOutputToContain('nothing unsafe found');
    }

    public function test_production_refuses_to_start_while_demo_accounts_exist(): void
    {
        $this->safeProduction();
        User::factory()->create()->forceFill(['is_demo' => true])->save();

        $this->artisan('atlas:check-deployment')->assertFailed()->expectsOutputToContain('atlas:purge-demo-data');
    }

    public function test_the_settings_are_checked_before_migrations_without_touching_the_database(): void
    {
        $this->safeProduction();
        config(['app.debug' => true]);
        User::factory()->create()->forceFill(['is_demo' => true])->save();
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });

        $this->artisan('atlas:check-deployment --before-migrations')
            ->assertFailed()
            ->expectsOutputToContain('APP_DEBUG')
            ->doesntExpectOutputToContain('Demo accounts');

        $this->assertSame(0, $queries, 'The check before migrations may not need a database.');
    }

    public function test_the_demo_accounts_are_checked_after_migrations(): void
    {
        $this->safeProduction();
        config(['app.debug' => true]);
        User::factory()->create()->forceFill(['is_demo' => true])->save();

        $this->artisan('atlas:check-deployment --after-migrations')
            ->assertFailed()
            ->expectsOutputToContain('atlas:purge-demo-data')
            ->doesntExpectOutputToContain('APP_DEBUG');
    }

    public function test_the_container_checks_its_settings_before_it_migrates(): void
    {
        $start = file_get_contents(base_path('docker/app/start.sh'));
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", $start)),
            fn ($line) => str_starts_with($line, 'php artisan '),
        ));

        $before = array_search('php artisan atlas:check-deployment --before-migrations', $lines, true);
        $migrate = array_search('php artisan migrate --force', $lines, true);
        $after = array_search('php artisan atlas:check-deployment --after-migrations', $lines, true);

        $this->assertIsInt($before);
        $this->assertIsInt($after);
        $this->assertLessThan($migrate, $before, 'The settings are checked before the database is migrated.');
        $this->assertGreaterThan($migrate, $after);
    }

    public function test_plain_http_is_fine_while_only_this_machine_can_reach_it(): void
    {
        $this->safeProduction();
        config(['app.url' => 'http://localhost:8000', 'atlas.published_on_loopback' => true]);

        $this->artisan('atlas:check-deployment')->assertSuccessful();
    }

    public function test_a_development_copy_reachable_from_the_network_refuses_unsafe_settings(): void
    {
        $this->app['env'] = 'local';
        config(['app.debug' => true, 'atlas.demo_mode' => true, 'atlas.published_on_loopback' => false]);

        $this->artisan('atlas:check-deployment')
            ->assertFailed()
            ->expectsOutputToContain('ATLAS_ALLOW_UNSAFE_NETWORK=true starts it anyway');
    }

    public function test_a_demonstration_on_a_trusted_wifi_can_start_when_asked_and_is_told_so(): void
    {
        $this->app['env'] = 'local';
        config(['app.debug' => true, 'atlas.published_on_loopback' => false, 'atlas.allow_unsafe_network' => true]);

        $this->artisan('atlas:check-deployment')
            ->assertSuccessful()
            ->expectsOutputToContain('Anyone on this network can reach these settings');
    }

    public function test_the_override_does_not_reach_production(): void
    {
        $this->safeProduction();
        config(['app.debug' => true, 'atlas.allow_unsafe_network' => true]);

        $this->artisan('atlas:check-deployment')->assertFailed();
    }

    public function test_a_development_machine_is_warned_and_still_starts(): void
    {
        $this->app['env'] = 'local';
        config(['app.debug' => true, 'atlas.demo_mode' => true, 'atlas.published_on_loopback' => true]);

        $this->artisan('atlas:check-deployment')
            ->assertSuccessful()
            ->expectsOutputToContain('APP_DEBUG')
            ->expectsOutputToContain('Fine while only this machine can reach it');
    }
}
