<?php

namespace Tests\Feature\Assistant;

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
 * How far today's rounds have got, room by room: on the rounds page, where
 * an assistant picks a room, and on My shift, their home screen.
 *
 * @see App\Services\Maintenance\InspectionService::roundsToday()
 */
class RoundsTodayTest extends TestCase
{
    use RefreshDatabase;

    private User $assistant;

    private ChecklistTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assistant = User::factory()->create();
        $this->assistant->forceFill(['role' => UserRole::Assistant, 'is_active' => true])->save();

        $this->template = ChecklistTemplate::factory()->active()->create();
        ChecklistItem::factory()->create(['checklist_template_id' => $this->template->id, 'sort_order' => 1]);
    }

    private function room(string $code, string $name): Location
    {
        return Location::factory()->create(['code' => $code, 'name' => $name]);
    }

    private function machine(Location $room, string $tag): LabPc
    {
        return LabPc::factory()->create(['asset_tag' => $tag, 'location_id' => $room->id]);
    }

    private function checkedToday(LabPc $machine, InspectionOutcome $outcome = InspectionOutcome::Pass): void
    {
        Inspection::factory()->completed($outcome)->create([
            'lab_pc_id' => $machine->id,
            'user_id' => $this->assistant->id,
            'checklist_template_id' => $this->template->id,
        ]);
    }

    public function test_the_rounds_page_shows_how_far_each_room_has_got_today(): void
    {
        $lab = $this->room('L6-IT-01', 'Level 6 IT Lab 01');
        $this->checkedToday($this->machine($lab, 'APU-0001'));
        $this->checkedToday($this->machine($lab, 'APU-0002'), InspectionOutcome::Fail);
        $this->machine($lab, 'APU-0003');

        $library = $this->room('LIB-L3', 'Library');
        $this->machine($library, 'APU-0101');

        $this->actingAs($this->assistant)
            ->get(route('assistant.inspections.index'))
            ->assertOk()
            ->assertSeeInOrder(['Level 6 IT Lab 01', '2 of 3 checked today, 1 fault'])
            ->assertSeeInOrder(['Library', 'Not started today']);
    }

    public function test_a_room_whose_round_is_done_says_so(): void
    {
        $lab = $this->room('L6-IT-01', 'Level 6 IT Lab 01');
        $this->checkedToday($this->machine($lab, 'APU-0001'));
        $this->checkedToday($this->machine($lab, 'APU-0002'));

        $this->actingAs($this->assistant)
            ->get(route('assistant.inspections.index'))
            ->assertOk()
            ->assertSee('All 2 checked today');
    }

    public function test_my_shift_shows_the_rounds_today(): void
    {
        $lab = $this->room('L6-IT-01', 'Level 6 IT Lab 01');
        $this->checkedToday($this->machine($lab, 'APU-0001'));
        $this->machine($lab, 'APU-0002');

        $this->actingAs($this->assistant)
            ->get(route('assistant.shifts.today'))
            ->assertOk()
            ->assertSee('Rounds today')
            ->assertSee('1 of 2 checked today');
    }
}
