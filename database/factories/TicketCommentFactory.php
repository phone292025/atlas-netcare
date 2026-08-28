<?php

namespace Database\Factories;

use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TicketComment>
 */
class TicketCommentFactory extends Factory
{
    protected $model = TicketComment::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'user_id' => User::factory(),
            'body' => $this->faker->sentence(10),
            'is_internal' => false,
        ];
    }

    /** Engineer-to-engineer. Never shown to the assistant who reported it. */
    public function internal(): static
    {
        return $this->state(['is_internal' => true]);
    }
}
