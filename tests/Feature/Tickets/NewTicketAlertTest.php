<?php

namespace Tests\Feature\Tickets;

use App\Enums\AlarmType;
use App\Enums\TicketPriority;
use App\Enums\UserRole;
use App\Models\Alarm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Engineers are told about an urgent ticket when it is raised, not only when
 * it goes overdue. Found by raising a critical ticket as an assistant and
 * watching nobody's bell: its four-hour target was running with nobody told.
 *
 * @see App\Services\Tickets\TicketService::announce()
 * @see config/atlas.php new_ticket_alert_from
 */
class NewTicketAlertTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(UserRole $role, bool $active = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => $active])->save();

        return $user;
    }

    private function raise(User $reporter, TicketPriority $priority): void
    {
        $this->actingAs($reporter)
            ->post(route('assistant.tickets.store'), [
                'title' => 'No network in the lab',
                'description' => 'Every machine in the room has lost its connection.',
                'category' => 'network',
                'priority' => $priority->value,
            ])
            ->assertSessionHasNoErrors();
    }

    private function alerts(): int
    {
        return Alarm::where('type', AlarmType::TicketRaised)->count();
    }

    public function test_a_critical_ticket_alerts_every_active_engineer(): void
    {
        $engineers = [$this->userWith(UserRole::Engineer), $this->userWith(UserRole::Engineer)];
        $this->userWith(UserRole::Engineer, active: false);

        $this->raise($this->userWith(UserRole::Assistant), TicketPriority::Critical);

        $this->assertEqualsCanonicalizing(
            array_map(fn (User $u) => $u->id, $engineers),
            Alarm::where('type', AlarmType::TicketRaised)->pluck('user_id')->all(),
        );
    }

    public function test_a_medium_ticket_waits_in_the_queue(): void
    {
        $this->userWith(UserRole::Engineer);

        $this->raise($this->userWith(UserRole::Assistant), TicketPriority::Medium);

        $this->assertSame(0, $this->alerts());
    }

    public function test_the_engineer_who_raised_it_is_not_told_about_it(): void
    {
        $reporter = $this->userWith(UserRole::Engineer);
        $colleague = $this->userWith(UserRole::Engineer);

        $this->raise($reporter, TicketPriority::Critical);

        $this->assertSame([$colleague->id], Alarm::where('type', AlarmType::TicketRaised)->pluck('user_id')->all());
    }

    public function test_the_threshold_is_configurable(): void
    {
        $this->userWith(UserRole::Engineer);
        config(['atlas.new_ticket_alert_from' => 'low']);

        $this->raise($this->userWith(UserRole::Assistant), TicketPriority::Low);

        $this->assertSame(1, $this->alerts());

        config(['atlas.new_ticket_alert_from' => null]);
        $this->raise($this->userWith(UserRole::Assistant), TicketPriority::Critical);

        $this->assertSame(1, $this->alerts(), 'Null switches the alert off.');
    }

    public function test_the_alert_arrives_now_and_names_the_ticket(): void
    {
        $engineer = $this->userWith(UserRole::Engineer);

        $this->raise($this->userWith(UserRole::Assistant), TicketPriority::Critical);

        $alarm = Alarm::where('user_id', $engineer->id)->sole();

        $this->assertStringStartsWith('TKT-', $alarm->title);
        $this->assertStringContainsString('No network in the lab', $alarm->title);
        $this->assertTrue($alarm->trigger_at->lte(now()));
    }
}
