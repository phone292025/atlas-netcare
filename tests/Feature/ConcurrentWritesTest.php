<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ChecklistTemplate;
use App\Models\InspectionResult;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Maintenance\ChecklistService;
use App\Services\Shifts\ShiftService;
use App\Support\CampusClock;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The rules two requests at once could break, held where a second request
 * cannot get round them.
 *
 * Two kinds of test. The unique indexes are checked by writing past the
 * services, on both databases. The table locks are checked on PostgreSQL with
 * a second connection standing in for the other request: it holds the lock,
 * and the service has to wait for it before reading anything it decides on.
 * SQLite allows one writing transaction at a time, so it has no second
 * request to race.
 *
 * Unfinished inspections have their own race test in InspectionTest.
 */
class ConcurrentWritesTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------- checklist activation

    public function test_the_database_refuses_a_second_active_checklist(): void
    {
        ChecklistTemplate::factory()->active()->create();
        $second = ChecklistTemplate::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('checklist_templates')->where('id', $second->id)->update(['is_active' => true]);
    }

    public function test_activating_still_moves_the_active_checklist(): void
    {
        $first = ChecklistTemplate::factory()->active()->create();
        $second = ChecklistTemplate::factory()->create();

        app(ChecklistService::class)->activate($second);

        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
        $this->assertSame(1, ChecklistTemplate::query()->where('is_active', true)->count());
    }

    public function test_activation_waits_for_another_writer_before_turning_anything_off(): void
    {
        $template = ChecklistTemplate::factory()->create();

        $this->assertWaitsFor('checklist_templates', fn () => app(ChecklistService::class)->activate($template));
    }

    public function test_a_new_version_waits_before_counting_versions(): void
    {
        $template = ChecklistTemplate::factory()->create();

        $this->assertWaitsFor('checklist_templates', fn () => app(ChecklistService::class)->newVersionOf($template));
    }

    // ------------------------------------------------- roster generation

    public function test_roster_generation_waits_before_counting_what_a_slot_holds(): void
    {
        ShiftTemplate::factory()->onDay(1)->create();
        $this->assistant();

        $this->assertWaitsFor('shifts', fn () => app(ShiftService::class)->generateWeek($this->nextWeek()));
    }

    public function test_a_second_run_after_the_first_commits_adds_nothing(): void
    {
        ShiftTemplate::factory()->onDay(1)->needing(2)->create();
        $this->assistant();
        $this->assistant();

        $this->assertCount(2, app(ShiftService::class)->generateWeek($this->nextWeek()));
        $this->assertCount(0, app(ShiftService::class)->generateWeek($this->nextWeek()));
        $this->assertSame(2, Shift::count());
    }

    // ------------------------------------------------- one ticket per failed answer

    public function test_the_database_refuses_a_second_live_ticket_for_one_failed_answer(): void
    {
        $answer = InspectionResult::factory()->create();
        Ticket::factory()->create(['inspection_result_id' => $answer->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        Ticket::factory()->create(['inspection_result_id' => $answer->id]);
    }

    public function test_a_deleted_ticket_leaves_the_answer_free_to_be_raised_again(): void
    {
        $answer = InspectionResult::factory()->create();
        Ticket::factory()->create(['inspection_result_id' => $answer->id])->delete();

        Ticket::factory()->create(['inspection_result_id' => $answer->id]);

        $this->assertSame(1, Ticket::query()->where('inspection_result_id', $answer->id)->count());
    }

    // ------------------------------------------------- helpers

    /**
     * Another request holds the table as any writer does. The service must
     * stop at its own lock, before it reads, and not at a later write.
     */
    private function assertWaitsFor(string $table, callable $write): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Needs a second PostgreSQL connection.');
        }

        $other = $this->secondConnection();
        $other->beginTransaction();
        $other->statement("LOCK TABLE {$table} IN ROW EXCLUSIVE MODE");

        try {
            DB::statement("SET LOCAL lock_timeout = '300ms'");
            $write();
            $this->fail("The write did not wait for {$table}.");
        } catch (QueryException $e) {
            $this->assertSame('55P03', (string) $e->getCode(), $e->getMessage());
            $this->assertStringStartsWith("LOCK TABLE \"{$table}\"", $e->getSql());
        } finally {
            $other->rollBack();
            DB::statement("SET LOCAL lock_timeout = '0'");
        }
    }

    private function secondConnection(): Connection
    {
        config(['database.connections.second_request' => config('database.connections.pgsql')]);

        return DB::connection('second_request');
    }

    private function assistant(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => UserRole::Assistant, 'is_active' => true])->save();

        return $user;
    }

    private function nextWeek(): Carbon
    {
        return CampusClock::today()->addWeek()->startOfWeek();
    }
}
