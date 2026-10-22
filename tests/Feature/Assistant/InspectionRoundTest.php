<?php

namespace Tests\Feature\Assistant;

use App\Enums\ChecklistResult;
use App\Enums\InspectionOutcome;
use App\Enums\UserRole;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A round: one assistant working through every machine in a room.
 *
 * The room screen shows how far the round has got, desk by desk, and offers
 * the next machine. A clean machine goes straight on to the next one. A
 * machine with an issue stops at its summary first, because that is where
 * its ticket gets raised.
 *
 * @see docs/modules/M3-checklists-and-inspections.md
 */
class InspectionRoundTest extends TestCase
{
    use RefreshDatabase;

    private User $assistant;

    private ChecklistTemplate $template;

    private Location $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assistant = User::factory()->create();
        $this->assistant->forceFill(['role' => UserRole::Assistant, 'is_active' => true])->save();

        $this->template = ChecklistTemplate::factory()->active()->create();
        ChecklistItem::factory()->create([
            'checklist_template_id' => $this->template->id,
            'label' => 'Machine powers on',
            'sort_order' => 1,
        ]);
        $this->template->load('items');

        $this->room = Location::factory()->create(['name' => 'Level 6 IT Lab 01']);
    }

    private function machine(string $tag): LabPc
    {
        return LabPc::factory()->create(['asset_tag' => $tag, 'location_id' => $this->room->id]);
    }

    private function checkedToday(LabPc $machine, InspectionOutcome $outcome = InspectionOutcome::Pass): void
    {
        Inspection::factory()->completed($outcome)->create([
            'lab_pc_id' => $machine->id,
            'user_id' => $this->assistant->id,
            'checklist_template_id' => $this->template->id,
        ]);
    }

    private function inspecting(LabPc $machine): Inspection
    {
        return Inspection::factory()->create([
            'lab_pc_id' => $machine->id,
            'user_id' => $this->assistant->id,
            'checklist_template_id' => $this->template->id,
        ]);
    }

    /** @return array<int, array{result: string, remark: string}> */
    private function answers(ChecklistResult $result): array
    {
        return $this->template->items
            ->mapWithKeys(fn ($item) => [$item->id => ['result' => $result->value, 'remark' => 'Flickers on the left edge.']])
            ->all();
    }

    // ------------------------------------------------------------ the room

    public function test_the_room_shows_how_far_the_round_has_got(): void
    {
        $this->checkedToday($this->machine('APU-0001'));
        $this->checkedToday($this->machine('APU-0002'), InspectionOutcome::PassWithIssues);
        $this->machine('APU-0003');
        $this->machine('APU-0004');

        $this->actingAs($this->assistant)
            ->get(route('assistant.inspections.create', ['location' => $this->room->id]))
            ->assertOk()
            ->assertSee('2 of 4 checked today, 1 with a fault')
            ->assertSee('data-desk="checked"', false)
            ->assertSee('data-desk="fault"', false)
            ->assertSee('data-desk="next"', false)
            ->assertSee('data-desk="todo"', false)
            ->assertSee('Inspect APU-0003');
    }

    public function test_a_finished_round_says_so(): void
    {
        $this->checkedToday($this->machine('APU-0001'));

        $this->actingAs($this->assistant)
            ->get(route('assistant.inspections.create', ['location' => $this->room->id]))
            ->assertOk()
            ->assertSee('Every machine here is checked today')
            ->assertDontSee('data-desk="next"', false);
    }

    // ------------------------------------------------------------ the form

    public function test_the_form_says_where_the_machine_is_and_what_comes_next(): void
    {
        $first = $this->machine('APU-0001');
        $this->machine('APU-0002');

        $this->actingAs($this->assistant)
            ->get(route('assistant.inspections.edit', $this->inspecting($first)))
            ->assertOk()
            ->assertSee('Machine 1 of 2')
            ->assertSee('Save, then APU-0002');
    }

    // ------------------------------------------------------ moving along it

    public function test_a_clean_machine_goes_straight_on_to_the_next_one(): void
    {
        $first = $this->machine('APU-0001');
        $second = $this->machine('APU-0002');
        $inspection = $this->inspecting($first);

        $response = $this->actingAs($this->assistant)->patch(
            route('assistant.inspections.update', $inspection),
            ['results' => $this->answers(ChecklistResult::Ok), 'then' => 'next'],
        );

        $next = Inspection::query()->inProgress()
            ->where('lab_pc_id', $second->id)->where('user_id', $this->assistant->id)->sole();

        $response->assertRedirect(route('assistant.inspections.edit', $next))
            ->assertSessionHas('success', 'APU-0001 passed. Now APU-0002.');
        $this->assertSame(InspectionOutcome::Pass, $inspection->fresh()->overall_result);
    }

    public function test_a_machine_with_an_issue_stops_at_its_summary_first(): void
    {
        $first = $this->machine('APU-0001');
        $second = $this->machine('APU-0002');
        $inspection = $this->inspecting($first);

        $this->actingAs($this->assistant)
            ->patch(route('assistant.inspections.update', $inspection), ['results' => $this->answers(ChecklistResult::Issue), 'then' => 'next'])
            ->assertRedirect(route('assistant.inspections.show', $inspection));

        $this->assertFalse(
            Inspection::query()->where('lab_pc_id', $second->id)->exists(),
            'The next machine was started before the issue had its ticket.',
        );

        $this->actingAs($this->assistant)
            ->get(route('assistant.inspections.show', $inspection))
            ->assertOk()
            ->assertSee('Inspect APU-0002');
    }

    public function test_the_last_machine_ends_at_its_summary(): void
    {
        $this->checkedToday($this->machine('APU-0001'));
        $inspection = $this->inspecting($this->machine('APU-0002'));

        $this->actingAs($this->assistant)
            ->patch(route('assistant.inspections.update', $inspection), ['results' => $this->answers(ChecklistResult::Ok), 'then' => 'next'])
            ->assertRedirect(route('assistant.inspections.show', $inspection));
    }

    public function test_the_next_machine_is_the_one_after_this_in_the_room(): void
    {
        // Along the room, not back to the start: APU-0001 is still waiting
        // too, but after APU-0002 comes APU-0003.
        $this->machine('APU-0001');
        $second = $this->machine('APU-0002');
        $third = $this->machine('APU-0003');

        $this->actingAs($this->assistant)->patch(
            route('assistant.inspections.update', $this->inspecting($second)),
            ['results' => $this->answers(ChecklistResult::Ok), 'then' => 'next'],
        );

        $this->assertTrue(Inspection::query()->inProgress()->where('lab_pc_id', $third->id)->exists());
    }
}
