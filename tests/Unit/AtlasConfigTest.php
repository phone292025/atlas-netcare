<?php

namespace Tests\Unit;

use App\Enums\TicketPriority;
use App\Enums\UserRole;
use Tests\TestCase;

/**
 * config/atlas.php is the project's single tuning surface and a shared file
 * that seven people edit. These tests catch the two failure modes that would
 * otherwise surface as confusing behaviour rather than an error: a missing key,
 * and weights that no longer sum to 1.
 *
 * @see docs/05-architecture.md — Configuration
 */
class AtlasConfigTest extends TestCase
{
    public function test_every_expected_top_level_key_is_present(): void
    {
        foreach ([
            'telemetry_driver',
            'demo_mode',
            'poll_interval',
            'thresholds',
            'risk_weights',
            'risk_bands',
            'sla_hours',
            'retention',
            'navigation',
        ] as $key) {
            $this->assertNotNull(config("atlas.{$key}"), "config/atlas.php is missing '{$key}'");
        }
    }

    public function test_risk_weights_sum_to_one(): void
    {
        // If these drift, every risk score silently scales wrong and no error
        // is ever raised. See ADR-0007.
        $this->assertEqualsWithDelta(1.0, array_sum(config('atlas.risk_weights')), 0.0001);
    }

    public function test_risk_bands_are_ordered_and_start_at_zero(): void
    {
        $bands = config('atlas.risk_bands');

        $this->assertSame(0, $bands['low'], 'The lowest band must start at zero or scores below it band nowhere.');
        $this->assertGreaterThan($bands['low'], $bands['medium']);
        $this->assertGreaterThan($bands['medium'], $bands['high']);
        $this->assertGreaterThan($bands['high'], $bands['critical']);
    }

    public function test_every_ticket_priority_has_an_sla(): void
    {
        foreach (TicketPriority::cases() as $priority) {
            $this->assertGreaterThan(0, $priority->slaHours(), "{$priority->value} has no SLA configured");
        }
    }

    public function test_more_urgent_priorities_have_shorter_slas(): void
    {
        $this->assertLessThan(TicketPriority::Medium->slaHours(), TicketPriority::Critical->slaHours());
        $this->assertLessThan(TicketPriority::Low->slaHours(), TicketPriority::Medium->slaHours());
    }

    public function test_every_role_has_a_navigation_menu(): void
    {
        foreach (UserRole::cases() as $role) {
            $this->assertNotEmpty($role->navigation(), "{$role->value} has no navigation entries");
        }
    }

    public function test_navigation_entries_are_well_formed(): void
    {
        foreach (UserRole::cases() as $role) {
            foreach ($role->navigation() as $entry) {
                $this->assertArrayHasKey('label', $entry);
                $this->assertArrayHasKey('route', $entry);
                $this->assertArrayHasKey('icon', $entry);
                $this->assertNotSame('', trim($entry['label']));
                $this->assertStringStartsWith(
                    $role->value.'.',
                    $entry['route'],
                    "Navigation route '{$entry['route']}' is not namespaced under the {$role->value} role"
                );
            }
        }
    }

    public function test_thresholds_are_sane(): void
    {
        $t = config('atlas.thresholds');

        $this->assertGreaterThan(0, $t['latency_ms']);
        $this->assertGreaterThan(0, $t['packet_loss_pct']);
        $this->assertGreaterThan(0, $t['client_saturation']);
        $this->assertLessThanOrEqual(1, $t['client_saturation'], 'Saturation is a ratio, not a percentage.');
        $this->assertGreaterThanOrEqual(1, $t['consecutive_breach'], 'One reading must never flip an access point state on its own.');
    }
}
