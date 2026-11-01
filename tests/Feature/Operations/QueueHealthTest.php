<?php

namespace Tests\Feature\Operations;

use App\Enums\AlarmType;
use App\Enums\UserRole;
use App\Models\Alarm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** atlas:check-queue: a stopped or failing worker reaches the administrators. */
class QueueHealthTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->admin->forceFill(['role' => UserRole::Admin, 'is_active' => true])->save();
    }

    private function waitingJob(int $minutesAgo): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null,
            'available_at' => now()->subMinutes($minutesAgo)->getTimestamp(), 'created_at' => now()->subMinutes($minutesAgo)->getTimestamp(),
        ]);
    }

    private function queueAlarms(): int
    {
        return Alarm::where('type', AlarmType::QueueUnhealthy)->count();
    }

    public function test_a_healthy_queue_says_nothing(): void
    {
        $this->waitingJob(1);

        $this->artisan('atlas:check-queue')->assertSuccessful();

        $this->assertSame(0, $this->queueAlarms());
    }

    public function test_a_job_waiting_too_long_means_the_worker_is_down_and_the_admins_are_told_once(): void
    {
        $this->waitingJob((int) config('atlas.queue.stuck_after_min') + 1);

        $this->artisan('atlas:check-queue')->expectsOutputToContain('worker looks stopped')->assertSuccessful();
        $this->artisan('atlas:check-queue')->assertSuccessful();

        $this->assertSame(1, $this->queueAlarms());
        $this->assertSame($this->admin->id, Alarm::where('type', AlarmType::QueueUnhealthy)->sole()->user_id);

        $this->travel((int) config('atlas.queue.alert_every_min') + 1)->minutes();
        $this->artisan('atlas:check-queue')->assertSuccessful();
        $this->assertSame(2, $this->queueAlarms(), 'Still down an hour later, and nobody was reminded.');
    }

    public function test_jobs_that_keep_failing_are_reported(): void
    {
        foreach (range(1, (int) config('atlas.queue.failed_jobs_alert')) as $i) {
            DB::table('failed_jobs')->insert(['uuid' => "u-{$i}", 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        }

        $this->artisan('atlas:check-queue')->expectsOutputToContain('background jobs failed')->assertSuccessful();

        $this->assertSame(1, $this->queueAlarms());
    }
}
