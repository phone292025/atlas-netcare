<?php

namespace Tests\Feature\Operations;

use App\Enums\AlarmType;
use App\Enums\UserRole;
use App\Models\Alarm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * atlas:check-backups: a backup that is late, failed, unproven or not copied
 * off this machine reaches the administrators. The status file is the one
 * docker/postgres/bin/backup-loop.sh writes.
 */
class BackupHealthTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $status;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();

        $this->status = tempnam(sys_get_temp_dir(), 'atlas-backup-status');
        unlink($this->status);
        config(['atlas.backups.status_file' => $this->status, 'atlas.backups.expected' => true]);
    }

    protected function tearDown(): void
    {
        @unlink($this->status);

        parent::tearDown();
    }

    /** @param  array<string, string|int>  $values */
    private function writeStatus(array $values): void
    {
        $lines = ['started='.now()->subDays(3)->getTimestamp(), 'every_hours=24', 'copy=no'];
        foreach ($values as $key => $value) {
            $lines = array_values(array_filter($lines, fn ($line) => ! str_starts_with($line, "{$key}=")));
            $lines[] = "{$key}={$value}";
        }

        file_put_contents($this->status, implode("\n", $lines)."\n");
    }

    /** @param  array<string, string|int>  $values */
    private function goodBackup(int $hoursAgo, array $values = []): void
    {
        $this->writeStatus([
            'last_success' => now()->subHours($hoursAgo)->getTimestamp(),
            'last_file' => 'atlas-20261106-020000Z.dump',
            'verified' => 'yes',
            ...$values,
        ]);
    }

    private function backupAlarms(): int
    {
        return Alarm::where('type', AlarmType::BackupUnhealthy)->count();
    }

    public function test_a_recent_proven_backup_says_nothing(): void
    {
        $this->goodBackup(3);

        $this->artisan('atlas:check-backups')->expectsOutputToContain('on time')->assertSuccessful();

        $this->assertSame(0, $this->backupAlarms());
    }

    public function test_a_late_backup_tells_the_administrators_once_per_period(): void
    {
        $this->goodBackup(24 + (int) config('atlas.backups.late_after_hours') + 1);

        $this->artisan('atlas:check-backups')->expectsOutputToContain('hours old')->assertSuccessful();
        $this->artisan('atlas:check-backups')->assertSuccessful();

        $this->assertSame(1, $this->backupAlarms());
        $this->assertSame($this->admin->id, Alarm::where('type', AlarmType::BackupUnhealthy)->sole()->user_id);

        $this->travel((int) config('atlas.backups.alert_every_min') + 1)->minutes();
        $this->artisan('atlas:check-backups');
        $this->assertSame(2, $this->backupAlarms());
    }

    public function test_a_failed_attempt_after_the_last_good_one_is_reported_with_its_reason(): void
    {
        $this->goodBackup(20, [
            'last_failure' => now()->subHour()->getTimestamp(),
            'last_error' => 'atlas-20261106-020000Z.dump did not restore: ERROR: relation missing',
        ]);

        $this->artisan('atlas:check-backups')->expectsOutputToContain('did not restore')->assertSuccessful();
        $this->assertSame(1, $this->backupAlarms());
    }

    public function test_a_failure_before_the_last_good_backup_is_over(): void
    {
        $this->goodBackup(2, ['last_failure' => now()->subHours(5)->getTimestamp(), 'last_error' => 'pg_dump failed']);

        $this->artisan('atlas:check-backups')->assertSuccessful();
        $this->assertSame(0, $this->backupAlarms());
    }

    public function test_backups_nobody_restores_are_reported(): void
    {
        $this->goodBackup(2, ['verified' => 'no']);

        $this->artisan('atlas:check-backups')->expectsOutputToContain('ATLAS_BACKUP_VERIFY')->assertSuccessful();
    }

    public function test_a_copy_off_this_machine_that_fell_behind_is_reported(): void
    {
        $this->goodBackup(2, ['copy' => 'yes', 'last_copy' => now()->subHours(26)->getTimestamp()]);
        $this->artisan('atlas:check-backups')->expectsOutputToContain('ATLAS_BACKUP_COPY_DIR');

        $this->goodBackup(2, ['copy' => 'yes', 'last_copy' => now()->subHours(2)->getTimestamp()]);
        $this->artisan('atlas:check-backups')->expectsOutputToContain('on time');
    }

    public function test_a_copy_on_the_same_disk_as_the_backups_is_reported(): void
    {
        $this->goodBackup(2, ['copy' => 'yes', 'copy_same_disk' => 'yes', 'last_copy' => now()->subHour()->getTimestamp()]);

        $this->artisan('atlas:check-backups')->expectsOutputToContain('same disk')->assertSuccessful();
        $this->assertSame(1, $this->backupAlarms());
    }

    public function test_a_backup_service_that_never_wrote_its_status_is_reported(): void
    {
        $this->artisan('atlas:check-backups')->expectsOutputToContain('never written its status')->assertSuccessful();
    }

    public function test_a_backup_service_that_has_not_managed_one_yet_is_given_time_then_reported(): void
    {
        $this->writeStatus(['started' => now()->subMinutes(30)->getTimestamp()]);
        $this->artisan('atlas:check-backups')->assertSuccessful();
        $this->assertSame(0, $this->backupAlarms());

        $this->writeStatus(['started' => now()->subHours(3)->getTimestamp()]);
        $this->artisan('atlas:check-backups')->expectsOutputToContain('No backup has succeeded');
    }

    public function test_a_stack_without_the_backup_service_expects_nothing(): void
    {
        config(['atlas.backups.expected' => false]);

        $this->artisan('atlas:check-backups')->assertSuccessful();
        $this->assertSame(0, $this->backupAlarms());
    }

    public function test_the_backup_profile_is_what_says_backups_are_expected(): void
    {
        foreach (['' => false, 'backup' => true, 'monitoring,backup' => true, 'backups-old' => false] as $profiles => $expected) {
            putenv("ATLAS_COMPOSE_PROFILES={$profiles}");
            putenv('ATLAS_BACKUPS_EXPECTED');
            $config = require base_path('config/atlas.php');

            $this->assertSame($expected, $config['backups']['expected'], "COMPOSE_PROFILES={$profiles}");
        }
        putenv('ATLAS_COMPOSE_PROFILES');
    }
}
