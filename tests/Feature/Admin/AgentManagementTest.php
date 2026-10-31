<?php

namespace Tests\Feature\Admin;

use App\Enums\AgentContact;
use App\Enums\UserRole;
use App\Models\AccessPoint;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Telemetry\AgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Probe agents and their tokens (ADR-0017, roadmap task 1.2).
 *
 * The token is the only thing standing between the ingest API and anyone who
 * can reach the port, so most of these are about where it is and isn't kept.
 */
class AgentManagementTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    /** The token shown on the page, read from its input. */
    private function tokenIn(string $html): string
    {
        preg_match('/value="(atl_[A-Za-z0-9]{40})"/', $html, $match);
        $this->assertNotEmpty($match, 'No token on the page.');

        return $match[1];
    }

    public function test_only_an_admin_reaches_the_agents_screens(): void
    {
        foreach ([UserRole::Engineer, UserRole::Assistant] as $role) {
            $this->actingAs($this->userWith($role))->get(route('admin.agents.index'))->assertForbidden();
        }

        $this->actingAs($this->userWith(UserRole::Admin))->get(route('admin.agents.index'))
            ->assertOk()
            ->assertSee('No agents yet');
    }

    public function test_registering_shows_the_token_once_and_stores_only_its_hash(): void
    {
        $response = $this->actingAs($this->userWith(UserRole::Admin))
            ->post(route('admin.agents.store'), ['name' => '  Level 5 probe '])
            ->assertOk();

        $token = $this->tokenIn($response->getContent());
        $agent = Agent::sole();

        $this->assertSame('Level 5 probe', $agent->name);
        $this->assertSame(hash('sha256', $token), $agent->token_hash);
        $this->assertSame(substr($token, -4), $agent->token_hint);
        $this->assertStringNotContainsString($token, json_encode(session()->all()), 'The token went into the session.');
        $this->assertTrue(app(AgentService::class)->authenticate($token)->is($agent));

        // The agent's page never shows it again.
        $this->actingAs($this->userWith(UserRole::Admin))->get(route('admin.agents.show', $agent))
            ->assertOk()
            ->assertDontSee($token);
    }

    public function test_a_wrong_or_malformed_token_authenticates_nobody(): void
    {
        ['token' => $token] = app(AgentService::class)->register('Probe');
        $agents = app(AgentService::class);

        $this->assertNull($agents->authenticate(null));
        $this->assertNull($agents->authenticate(''));
        // A different last character. Always 'x' failed whenever the token
        // already ended in one, about one run in sixty-two.
        $this->assertNull($agents->authenticate(substr($token, 0, -1).(str_ends_with($token, 'x') ? 'y' : 'x')));
        $this->assertNull($agents->authenticate(hash('sha256', $token)), 'The stored hash must not work as a token.');
    }

    public function test_a_new_token_stops_the_old_one_at_once(): void
    {
        ['agent' => $agent, 'token' => $old] = app(AgentService::class)->register('Probe');

        $response = $this->actingAs($this->userWith(UserRole::Admin))
            ->post(route('admin.agents.rotate', $agent))
            ->assertOk()
            ->assertSee('The old token stopped working just now.');

        $new = $this->tokenIn($response->getContent());

        $this->assertNotSame($old, $new);
        $this->assertNull(app(AgentService::class)->authenticate($old));
        $this->assertTrue(app(AgentService::class)->authenticate($new)->is($agent));
    }

    public function test_retiring_stops_the_token_and_frees_its_devices(): void
    {
        ['agent' => $agent, 'token' => $token] = app(AgentService::class)->register('Probe');
        $ap = AccessPoint::factory()->create(['agent_id' => $agent->id]);

        $this->actingAs($this->userWith(UserRole::Admin))
            ->delete(route('admin.agents.destroy', $agent))
            ->assertRedirect(route('admin.agents.index'));

        $this->assertSoftDeleted($agent);
        $this->assertNull(app(AgentService::class)->authenticate($token));
        $this->assertNull($ap->fresh()->agent_id);
    }

    public function test_a_retired_agents_name_is_not_reused(): void
    {
        ['agent' => $agent] = app(AgentService::class)->register('Probe');
        app(AgentService::class)->retire($agent);

        $this->actingAs($this->userWith(UserRole::Admin))
            ->post(route('admin.agents.store'), ['name' => 'Probe'])
            ->assertSessionHasErrors('name');
    }

    public function test_the_audit_log_records_the_agent_but_never_its_token_hash(): void
    {
        $this->actingAs($this->userWith(UserRole::Admin));
        ['agent' => $agent] = app(AgentService::class)->register('Probe');
        app(AgentService::class)->issue($agent);

        $logged = AuditLog::forSubject($agent)->get();

        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString($agent->fresh()->token_hash, $logged->toJson());
    }

    public function test_contact_follows_when_the_agent_was_last_heard_from(): void
    {
        $agent = Agent::factory()->create();
        $this->assertSame(AgentContact::Never, $agent->contact());

        $agent->forceFill(['last_seen_at' => now()->subMinute()])->save();
        $this->assertSame(AgentContact::Connected, $agent->contact());

        $agent->forceFill(['last_seen_at' => now()->subMinutes((int) config('atlas.ingest.silent_after_minutes') + 1)])->save();
        $this->assertSame(AgentContact::Silent, $agent->contact());
    }

    public function test_an_access_point_is_given_to_an_agent_from_its_form(): void
    {
        $agent = Agent::factory()->create(['name' => 'Level 6 probe']);
        $ap = AccessPoint::factory()->create();
        $admin = $this->userWith(UserRole::Admin);

        $this->actingAs($admin)->get(route('admin.aps.edit', $ap))->assertOk()->assertSee('Level 6 probe');

        $this->actingAs($admin)->patch(route('admin.aps.update', $ap), [
            'code' => $ap->code,
            'location_id' => $ap->location_id,
            'mac_address' => $ap->mac_address,
            'client_capacity' => 50,
            'status' => 'online',
            'agent_id' => $agent->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame($agent->id, $ap->fresh()->agent_id);
        $this->actingAs($admin)->get(route('admin.agents.show', $agent))->assertOk()->assertSee($ap->code);
    }

    public function test_a_retired_agent_cannot_be_given_a_device(): void
    {
        $agent = Agent::factory()->create();
        $agent->delete();
        $ap = AccessPoint::factory()->create();

        $this->actingAs($this->userWith(UserRole::Admin))->patch(route('admin.aps.update', $ap), [
            'code' => $ap->code,
            'location_id' => $ap->location_id,
            'mac_address' => $ap->mac_address,
            'client_capacity' => 50,
            'status' => 'online',
            'agent_id' => $agent->id,
        ])->assertSessionHasErrors('agent_id');
    }

    public function test_a_name_with_an_apostrophe_is_escaped_once_in_its_dialogs(): void
    {
        // title="Retire {{ $name }}?" was escaped by Blade and again by the
        // component, so the heading read "O&#039;Brien".
        ['agent' => $agent] = app(AgentService::class)->register("O'Brien lab probe");

        $this->actingAs($this->userWith(UserRole::Admin))
            ->get(route('admin.agents.show', $agent))
            ->assertOk()
            ->assertSee('Retire O&#039;Brien lab probe?', false)
            ->assertDontSee('&amp;#039;', false);
    }
}
