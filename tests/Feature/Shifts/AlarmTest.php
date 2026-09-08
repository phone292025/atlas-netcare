<?php

namespace Tests\Feature\Shifts;

use App\Enums\AlarmType;
use App\Enums\ApStatus;
use App\Enums\TicketPriority;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Alarm;
use App\Models\Shift;
use App\Models\ShiftTemplate;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Shifts\AlarmService;
use App\Services\Telemetry\ReadingData;
use App\Services\Telemetry\TelemetrySource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * M6 — alarms: the delivery mechanism for the whole system (FR-506, FR-212).
 *
 * @see App\Services\Shifts\AlarmService
 * @see docs/02-open-questions.md Q5
 */
class AlarmTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    private function service(): AlarmService
    {
        return app(AlarmService::class);
    }

    // ------------------------------------------------------- raise and send

    public function test_an_alarm_scheduled_for_later_is_not_sent_yet(): void
    {
        /*
         * The property the whole design rests on. A row with trigger_at in the
         * future is normal — that is what makes "remind me at 11:00" possible
         * — and it must not be visible or counted until it comes due.
         */
        $user = $this->userWith(UserRole::Assistant);

        $this->service()->raise(
            AlarmType::InspectionRound, $user, 'Round due', at: now()->addHours(2),
        );

        $this->assertSame(0, $this->service()->dispatchDue());
        $this->assertSame(0, $this->service()->unreadCount($user));
    }

    public function test_an_alarm_that_has_come_due_is_sent(): void
    {
        $user = $this->userWith(UserRole::Assistant);

        $this->service()->raise(
            AlarmType::InspectionRound, $user, 'Round due', at: now()->subMinute(),
        );

        $this->assertSame(1, $this->service()->dispatchDue());
        $this->assertSame(1, $this->service()->unreadCount($user));
    }

    public function test_dispatching_twice_does_not_resend(): void
    {
        // It runs every minute. Re-sending would make one reminder count
        // forever and the badge climb on its own.
        $user = $this->userWith(UserRole::Assistant);
        $this->service()->raise(AlarmType::ShiftStart, $user, 'Shift soon', at: now()->subMinute());

        $this->assertSame(1, $this->service()->dispatchDue());
        $this->assertSame(0, $this->service()->dispatchDue());
    }

    public function test_the_dispatch_command_sends_due_alarms(): void
    {
        $user = $this->userWith(UserRole::Assistant);
        $this->service()->raise(AlarmType::ShiftStart, $user, 'Shift soon', at: now()->subMinute());

        Artisan::call('atlas:dispatch-alarms');

        $this->assertNotNull(Alarm::first()->sent_at);
    }

    // ---------------------------------------------- the shared entry points

    public function test_a_role_wide_alarm_reaches_every_active_holder(): void
    {
        // How M5 tells "the engineers" an access point is down without having
        // to decide which engineer owns it.
        $this->userWith(UserRole::Engineer);
        $this->userWith(UserRole::Engineer);
        $this->userWith(UserRole::Assistant);

        $raised = $this->service()->raiseForRole(
            AlarmType::ApOffline, UserRole::Engineer, 'AP-L6-03 is not responding',
        );

        $this->assertCount(2, $raised);
    }

    public function test_a_deactivated_account_is_not_alarmed(): void
    {
        // An unread badge on an account nobody can sign into helps no one.
        $engineer = $this->userWith(UserRole::Engineer);
        $engineer->forceFill(['is_active' => false])->save();

        $raised = $this->service()->raiseForRole(
            AlarmType::ApOffline, UserRole::Engineer, 'AP-L6-03 is not responding',
        );

        $this->assertCount(0, $raised);
    }

    public function test_an_alarm_can_point_at_the_thing_it_is_about(): void
    {
        // So a reminder about a ticket can land on the ticket, rather than on
        // a list that mentions it.
        $engineer = $this->userWith(UserRole::Engineer);
        $ticket = Ticket::factory()->priority(TicketPriority::Critical)->create();

        $alarm = $this->service()->raise(
            AlarmType::SlaBreach, $engineer, 'Overdue', related: $ticket,
        );

        $this->assertTrue($alarm->fresh()->related->is($ticket));
    }

    // ------------------------------------------------------- unread counting

    public function test_the_unread_count_ignores_other_peoples_alarms(): void
    {
        $mine = $this->userWith(UserRole::Assistant);
        $theirs = $this->userWith(UserRole::Assistant);

        Alarm::factory()->count(3)->create(['user_id' => $mine->id]);
        Alarm::factory()->count(5)->create(['user_id' => $theirs->id]);

        $this->assertSame(3, $this->service()->unreadCount($mine));
    }

    public function test_reading_clears_the_count(): void
    {
        $user = $this->userWith(UserRole::Assistant);
        Alarm::factory()->count(4)->create(['user_id' => $user->id]);

        $this->service()->markAllRead($user);

        $this->assertSame(0, $this->service()->unreadCount($user));
    }

    // ------------------------------------------- the seams M4 and M5 use

    public function test_an_overdue_ticket_alarms_the_engineers_once(): void
    {
        /*
         * The M4 seam. Without the "already alarmed" guard this runs every
         * minute and re-alarms the same overdue ticket until somebody resolves
         * it, which is precisely how a notification bell gets muted.
         */
        $this->userWith(UserRole::Engineer);
        Ticket::factory()->breaching()->create();

        Artisan::call('atlas:dispatch-alarms');
        $this->assertSame(1, Alarm::where('type', AlarmType::SlaBreach)->count());

        Artisan::call('atlas:dispatch-alarms');
        $this->assertSame(1, Alarm::where('type', AlarmType::SlaBreach)->count());
    }

    public function test_a_ticket_within_its_target_raises_nothing(): void
    {
        $this->userWith(UserRole::Engineer);
        Ticket::factory()->create(['due_at' => now()->addDay()]);

        Artisan::call('atlas:dispatch-alarms');

        $this->assertSame(0, Alarm::where('type', AlarmType::SlaBreach)->count());
    }

    public function test_an_access_point_dropping_alarms_the_engineers(): void
    {
        // The M5 seam. The ticket is the record of work; the alarm is what
        // puts it in front of somebody.
        $this->userWith(UserRole::Admin);
        $engineer = $this->userWith(UserRole::Engineer);

        AccessPoint::factory()->create(['status' => ApStatus::Online->value]);

        // A driver whose access point does not answer. Until 2026-09-04 this
        // test forced "offline" with a stale last_seen_at instead, which only
        // worked because classify() had a bug: it marked a reachable reading
        // offline after any pause in collection.
        $this->app->instance(TelemetrySource::class, new class implements TelemetrySource
        {
            public function sourceLabel(): string
            {
                return 'simulated';
            }

            /** @return Builder<AccessPoint> */
            public function pollable(): Builder
            {
                return AccessPoint::query()->collectable();
            }

            /** @return Collection<int, ReadingData> */
            public function collect(): Collection
            {
                return $this->pollable()->get()
                    ->map(fn (AccessPoint $ap) => ReadingData::unreachable($ap, now(), 'simulated'));
            }
        });

        Artisan::call('atlas:collect-telemetry');

        $this->assertSame(1, Alarm::where('type', AlarmType::ApOffline)
            ->where('user_id', $engineer->id)->count());
    }

    // ------------------------------------------------------------ screens

    public function test_the_notifications_page_renders_when_empty(): void
    {
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('notifications.index'))
            ->assertOk();
    }

    public function test_opening_the_list_marks_everything_read(): void
    {
        // What people expect from a bell with a count on it. A badge that
        // stays lit after you have read everything is how people stop looking.
        $user = $this->userWith(UserRole::Assistant);
        Alarm::factory()->count(2)->create(['user_id' => $user->id, 'title' => 'Round due']);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            // Loaded before marking read, so the page still shows them.
            ->assertSee('Round due');

        $this->assertSame(0, $this->service()->unreadCount($user));
    }

    public function test_the_bell_shows_an_unread_count(): void
    {
        $user = $this->userWith(UserRole::Assistant);
        Alarm::factory()->count(3)->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('assistant.shifts.today'))
            ->assertOk()
            ->assertSee('3 unread notifications');
    }

    public function test_a_pending_alarm_is_not_in_the_badge(): void
    {
        $user = $this->userWith(UserRole::Assistant);
        Alarm::factory()->pending()->count(3)->create(['user_id' => $user->id]);

        $this->actingAs($user)
            ->get(route('assistant.shifts.today'))
            ->assertOk()
            ->assertDontSee('unread notifications');
    }

    public function test_opening_an_alarm_goes_to_what_it_is_about(): void
    {
        $engineer = $this->userWith(UserRole::Engineer);
        $ticket = Ticket::factory()->create();
        $alarm = Alarm::factory()->create([
            'user_id' => $engineer->id,
            'related_type' => $ticket->getMorphClass(),
            'related_id' => $ticket->id,
        ]);

        $this->actingAs($engineer)
            ->get(route('notifications.show', $alarm))
            ->assertRedirect(route('assistant.tickets.show', $ticket));

        $this->assertNotNull($alarm->fresh()->read_at);
    }

    public function test_you_cannot_open_somebody_elses_alarm(): void
    {
        $alarm = Alarm::factory()->create([
            'user_id' => $this->userWith(UserRole::Assistant)->id,
        ]);

        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('notifications.show', $alarm))
            ->assertForbidden();
    }

    // ------------------------------------------------------- shift screens

    public function test_the_assistant_home_screen_renders_with_no_shift(): void
    {
        /*
         * This is where UserRole::homeRoute() sends an assistant after signing
         * in, so "no shift today" is not an edge case — it is what most
         * assistants see most days.
         */
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('assistant.shifts.today'))
            ->assertOk()
            ->assertSee('Nothing rostered today');
    }

    public function test_the_assistant_home_screen_renders_with_a_shift(): void
    {
        $assistant = $this->userWith(UserRole::Assistant);
        Shift::factory()->inProgressWindow()->create(['user_id' => $assistant->id]);

        $this->actingAs($assistant)
            ->get(route('assistant.shifts.today'))
            ->assertOk()
            ->assertSee('Check in');
    }

    public function test_signing_in_as_an_assistant_lands_on_the_shift_screen(): void
    {
        // homeRoute() has pointed here since Sprint 0 and fell back to the
        // profile page because the route did not exist.
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('home'))
            ->assertRedirect(route('assistant.shifts.today'));
    }

    public function test_the_roster_week_view_renders(): void
    {
        $assistant = $this->userWith(UserRole::Assistant);
        Shift::factory()->create(['user_id' => $assistant->id]);

        $this->actingAs($assistant)
            ->get(route('assistant.shifts.index'))
            ->assertOk();
    }

    public function test_an_admin_can_reach_the_roster_and_templates(): void
    {
        $admin = $this->userWith(UserRole::Admin);

        $this->actingAs($admin)->get(route('admin.roster.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.shift-templates.index'))->assertOk();
    }

    public function test_an_assistant_cannot_reach_the_roster_generator(): void
    {
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('admin.roster.index'))
            ->assertForbidden();
    }

    public function test_an_admin_can_generate_a_roster_from_the_screen(): void
    {
        $this->userWith(UserRole::Assistant);
        ShiftTemplate::factory()->create();

        $this->actingAs($this->userWith(UserRole::Admin))
            ->post(route('admin.roster.generate'), ['week' => now()->addWeek()->startOfWeek()->toDateString()])
            ->assertRedirect();

        $this->assertSame(1, Shift::count());
    }
}
