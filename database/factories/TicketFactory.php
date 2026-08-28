<?php

namespace Database\Factories;

use App\Enums\TicketCategory;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Models\LabPc;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * Tickets for tests and demo data.
 *
 * Defaults to a freshly opened ticket about a lab PC. States further along the
 * lifecycle are their own methods, but a test that cares about the TRANSITION
 * must go through TicketService — a factory that writes `resolved` directly
 * proves nothing about whether the machine allows it.
 *
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    protected $model = Ticket::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $openedAt = now()->subHours($this->faker->numberBetween(1, 40));
        $priority = $this->faker->randomElement(TicketPriority::cases());

        return [
            'ticket_number' => 'TKT-'.now()->year.'-'.str_pad((string) $this->faker->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'title' => $this->faker->randomElement([
                'Machine will not power on',
                'Monitor flickering badly',
                'Keyboard unresponsive',
                'No network on this workstation',
            ]),
            'description' => $this->faker->sentence(12),
            'category' => $this->faker->randomElement(TicketCategory::values()),
            'priority' => $priority->value,
            'status' => TicketStatus::Open->value,
            'ticketable_type' => (new LabPc)->getMorphClass(),
            'ticketable_id' => LabPc::factory(),
            'reported_by' => User::factory(),
            'opened_at' => $openedAt,
            'due_at' => $openedAt->copy()->addHours($priority->slaHours()),
        ];
    }

    public function priority(TicketPriority $priority): static
    {
        return $this->state(fn (array $attributes) => [
            'priority' => $priority->value,
            'due_at' => Carbon::parse($attributes['opened_at'])->addHours($priority->slaHours()),
        ]);
    }

    public function status(TicketStatus $status): static
    {
        return $this->state(['status' => $status->value]);
    }

    /** Assigned and acknowledged, ready to be worked. */
    public function assignedTo(User $engineer): static
    {
        return $this->state([
            'assigned_to' => $engineer->id,
            'status' => TicketStatus::Acknowledged->value,
            'acknowledged_at' => now(),
        ]);
    }

    /** Past its SLA target, for the breach sweep and the queue's warning. */
    public function breaching(): static
    {
        return $this->state([
            'opened_at' => now()->subDays(9),
            'due_at' => now()->subDays(8),
            'status' => TicketStatus::Open->value,
        ]);
    }

    /** @see LabPcFactory::demo() */
    public function demo(): static
    {
        return $this->afterCreating(fn (Ticket $ticket) => $ticket->forceFill(['is_demo' => true])->save());
    }
}
