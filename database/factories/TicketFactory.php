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

    /**
     * The last sequence number this process has handed out.
     *
     * COUNTED IN MEMORY, and that is the load-bearing part.
     * `Ticket::factory()->count(40)->create()` resolves every `definition()`
     * BEFORE it inserts any of them, so a factory that only asked the
     * database for the highest number would read the same answer forty times
     * and hand out forty identical references, every one but the first
     * failing on the unique index.
     *
     * But it also takes the database's highest when that is higher, every
     * time: tickets raised by something else since the last one, such as the
     * collector while the demo is seeded, already hold the next numbers. Read
     * once at the start, it reused a number the collector had just taken and
     * seeding failed on the unique index.
     *
     * It is never reset. Across a test run the numbers climb past whatever the
     * database has been truncated back to, which keeps them unique and is
     * harmless: the tests of factory numbers check that they are distinct
     * and in a row, not what they start at, and the ones that check the
     * sequence itself use TicketService on an empty database.
     */
    private static ?int $sequence = null;

    /**
     * The next reference, counted rather than invented.
     *
     * This used to be `numberBetween(1, 99999)`, which made seeded tickets
     * random and pushed the highest to around 96,000 — so every real ticket
     * raised afterwards continued from there, because `reserveNumber()` takes
     * the highest and adds one. Nothing broke; the numbers simply stopped
     * being the readable sequence the format exists for.
     */
    private function nextNumber(): string
    {
        self::$sequence = max(self::$sequence ?? 0, Ticket::highestSequenceThisYear());

        return Ticket::formatNumber(++self::$sequence);
    }

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $openedAt = now()->subHours($this->faker->numberBetween(1, 40));
        $priority = $this->faker->randomElement(TicketPriority::cases());

        return [
            'ticket_number' => $this->nextNumber(),
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
