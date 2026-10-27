<?php

namespace App\Services\Telemetry;

use App\Models\Agent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Registering agents and the tokens they prove themselves with (ADR-0017).
 *
 * A token is `atl_` and 40 random letters and digits, about 238 bits, shown
 * once by whoever called issue(). Only its SHA-256 hash is stored. A random
 * token that long can't be guessed, so a slow password hash would only slow
 * every batch down.
 */
class AgentService
{
    public const TOKEN_PREFIX = 'atl_';

    private const TOKEN_LENGTH = 40;

    /**
     * Register an agent and issue its first token.
     *
     * @return array{agent: Agent, token: string}
     */
    public function register(string $name): array
    {
        return DB::transaction(function () use ($name): array {
            $agent = Agent::create(['name' => $name]);

            return ['agent' => $agent, 'token' => $this->issue($agent)];
        });
    }

    /**
     * Give an agent a new token. The old one stops working at once, so a
     * lost machine is locked out the moment someone rotates its token.
     */
    public function issue(Agent $agent): string
    {
        $token = self::TOKEN_PREFIX.Str::random(self::TOKEN_LENGTH);

        $agent->forceFill([
            'token_hash' => self::hash($token),
            'token_hint' => substr($token, -4),
            'token_issued_at' => now(),
        ])->save();

        return $token;
    }

    /**
     * Retire an agent for good: its token stops working, and the devices it
     * watched are left without an agent, ready to give to another. Its
     * readings keep pointing at it.
     */
    public function retire(Agent $agent): void
    {
        DB::transaction(function () use ($agent): void {
            $agent->devices()->update(['agent_id' => null]);
            $agent->delete();
        });
    }

    /** The agent this token belongs to, unless it is retired or the token is wrong. */
    public function authenticate(?string $token): ?Agent
    {
        if ($token === null || ! str_starts_with($token, self::TOKEN_PREFIX)) {
            return null;
        }

        return Agent::query()
            ->where('is_virtual', false)
            ->where('token_hash', self::hash($token))
            ->first();
    }

    /**
     * Note that the agent was heard from: when, from where, which version,
     * and how far its clock is from Atlas's. Written quietly, because it
     * changes on every batch and the readings are its history.
     */
    public function recordContact(Agent $agent, ?string $address, ?string $version, ?int $clockOffsetMs): void
    {
        $agent->forceFill(array_filter([
            'last_seen_at' => now(),
            'last_address' => $address,
            'version' => $version !== null ? Str::limit($version, 40, '') : null,
            'clock_offset_ms' => $clockOffsetMs,
        ], fn ($value) => $value !== null))->saveQuietly();
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
