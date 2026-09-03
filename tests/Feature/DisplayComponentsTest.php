<?php

namespace Tests\Feature;

use App\Enums\RiskBand;
use App\Support\Tone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * The four read-only components from Stage 3c: stat-tile, risk-gauge, timeline
 * and chart.
 *
 * @see docs/06-ui-conventions.md — Blade components
 */
class DisplayComponentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // checklist-item nests a form control, which reads the error bag.
        view()->share('errors', new ViewErrorBag);
    }

    // ---------------------------------------------------------------- stat tile

    public function test_stat_tile_shows_its_label_value_and_unit(): void
    {
        $html = Blade::render('<x-stat-tile label="Mean latency" value="142" unit="ms" />');

        $this->assertStringContainsString('Mean latency', $html);
        $this->assertStringContainsString('142', $html);
        $this->assertStringContainsString('ms', $html);
        $this->assertStringContainsString('tabular-nums', $html);
    }

    public function test_stat_tile_takes_its_delta_colour_from_the_caller_not_the_direction(): void
    {
        // Rising uptime is good, rising latency is bad. A component that
        // inferred tone from the arrow would get half a dashboard wrong.
        $bad = Blade::render('<x-stat-tile label="Latency" value="142" delta="+18" trend="up" tone="urgent" />');
        $good = Blade::render('<x-stat-tile label="Uptime" value="99.9" delta="+0.2" trend="up" tone="healthy" />');

        $this->assertStringContainsString('text-red-600', $bad);
        $this->assertStringContainsString('text-emerald-600', $good);

        // Same trend, so the same arrow in both.
        $this->assertStringContainsString('M6 15l6-6 6 6', $bad);
        $this->assertStringContainsString('M6 15l6-6 6 6', $good);
    }

    public function test_stat_tile_becomes_a_link_only_when_given_a_destination(): void
    {
        $plain = Blade::render('<x-stat-tile label="Open" value="18" />');
        $linked = Blade::render('<x-stat-tile label="Open" value="18" href="/engineer/tickets" />');

        $this->assertStringNotContainsString('<a', $plain);
        $this->assertStringContainsString('<a', $linked);
        $this->assertStringContainsString('href="/engineer/tickets"', $linked);
    }

    // --------------------------------------------------------------- risk gauge

    public function test_risk_gauge_derives_its_band_when_one_is_not_supplied(): void
    {
        $html = Blade::render('<x-risk-gauge :score="78" />');

        $this->assertStringContainsString(RiskBand::High->label(), $html);

        // From Tone, not a literal. This asserted 'bg-amber-500' and broke when
        // High got its own tone on 2026-09-04 — a test failing for a colour
        // change that was the entire point of the change.
        $this->assertStringContainsString(Tone::solid(RiskBand::High->tone()), $html);
    }

    public function test_risk_gauge_reports_itself_as_a_meter_with_its_range(): void
    {
        $html = Blade::render('<x-risk-gauge :score="78" />');

        $this->assertStringContainsString('role="meter"', $html);
        $this->assertStringContainsString('aria-valuenow="78"', $html);
        $this->assertStringContainsString('aria-valuemin="0"', $html);
        $this->assertStringContainsString('aria-valuemax="100"', $html);
    }

    public function test_risk_gauge_clamps_a_score_outside_the_range(): void
    {
        // A width above 100% would overflow its track and paint over the page.
        $over = Blade::render('<x-risk-gauge :score="250" />');
        $under = Blade::render('<x-risk-gauge :score="-40" />');

        $this->assertStringContainsString('width: 100%', $over);
        $this->assertStringContainsString('width: 0%', $under);
    }

    public function test_risk_gauge_lists_the_factors_heaviest_first(): void
    {
        // The factors are the point — a bare number is not analytics. ADR-0007.
        $html = Blade::render(
            '<x-risk-gauge :score="78" :factors="$factors" />',
            ['factors' => ['days_since_inspect' => 4.2, 'fault_frequency' => 22.5, 'age' => 11.0]]
        );

        $this->assertStringContainsString('Fault Frequency', $html);
        $this->assertStringContainsString('Days Since Inspect', $html);

        $this->assertLessThan(
            strpos($html, 'Days Since Inspect'),
            strpos($html, 'Fault Frequency'),
            'The heaviest contributing factor should be listed first.'
        );
    }

    // ----------------------------------------------------------------- timeline

    public function test_timeline_shows_who_did_what_and_when(): void
    {
        $html = Blade::render('<x-timeline :events="$events" />', ['events' => [
            ['actor' => 'Yar Zar Min', 'action' => 'resolved the ticket', 'at' => Carbon::parse('2026-08-20 09:15'), 'tone' => 'healthy'],
        ]]);

        $this->assertStringContainsString('Yar Zar Min', $html);
        $this->assertStringContainsString('resolved the ticket', $html);
        $this->assertStringContainsString('bg-emerald-500', $html);
    }

    public function test_timeline_keeps_the_exact_timestamp_alongside_the_relative_one(): void
    {
        // "3 hours ago" is what you read at a glance; the exact time is what
        // you need when writing an incident up.
        $html = Blade::render('<x-timeline :events="$events" />', ['events' => [
            ['actor' => 'System', 'action' => 'raised a ticket', 'at' => Carbon::parse('2026-08-20 09:15')],
        ]]);

        $this->assertStringContainsString('datetime="'.Carbon::parse('2026-08-20 09:15')->toIso8601String().'"', $html);
        $this->assertStringContainsString('title="Thu 20 Aug 2026, 09:15"', $html);
    }

    public function test_timeline_falls_back_to_an_empty_state(): void
    {
        $html = Blade::render('<x-timeline :events="[]" />');

        $this->assertStringContainsString('Nothing has happened yet', $html);
    }

    // -------------------------------------------------------------------- chart

    public function test_chart_renders_a_canvas_and_hands_its_data_to_chartjs(): void
    {
        $html = Blade::render(
            '<x-chart type="line" :data="$data" label="Mean latency over 24 hours" height="180" />',
            ['data' => ['labels' => ['09:00', '10:00'], 'datasets' => [['label' => 'Latency', 'data' => [24, 31]]]]]
        );

        $this->assertStringContainsString('<canvas', $html);
        $this->assertStringContainsString('window.Chart', $html);
        $this->assertStringContainsString('height: 180px', $html);

        // @js() emits JSON.parse('...') with unicode-escaped quotes rather than
        // interpolating raw JSON into the attribute, which is what stops a
        // label containing a quote from breaking out of the expression.
        $this->assertStringContainsString('JSON.parse', $html);
        $this->assertStringContainsString('09:00', $html);
    }

    public function test_chart_gives_a_canvas_an_accessible_name(): void
    {
        // A canvas is opaque to a screen reader. Without this the chart is a
        // hole in the page. NFR-10.
        $html = Blade::render('<x-chart :data="[]" label="Mean latency over 24 hours" />');

        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('aria-label="Mean latency over 24 hours"', $html);
    }

    public function test_chart_colours_its_first_dataset_from_the_shared_tone_source(): void
    {
        // A canvas cannot use a Tailwind class, so this is the one surface that
        // leaves the class vocabulary — but not the single source.
        $html = Blade::render(
            '<x-chart :data="$data" tone="urgent" />',
            ['data' => ['labels' => [], 'datasets' => [['label' => 'Latency', 'data' => []]]]]
        );

        $this->assertStringContainsString('#ef4444', $html);
    }

    public function test_chart_does_not_override_a_colour_the_caller_chose(): void
    {
        $html = Blade::render(
            '<x-chart :data="$data" tone="urgent" />',
            ['data' => ['labels' => [], 'datasets' => [['label' => 'X', 'data' => [], 'borderColor' => '#123456']]]]
        );

        $this->assertStringContainsString('#123456', $html);
        $this->assertStringNotContainsString('#ef4444', $html);
    }
}
