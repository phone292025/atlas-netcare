<?php

namespace Tests\Feature\Admin;

use App\Enums\ChecklistResult;
use App\Enums\UserRole;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\InspectionResult;
use App\Models\User;
use App\Services\Maintenance\ChecklistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M3 — checklist templates and the versioning rule (Q2).
 *
 * The rule these tests exist for: a template that has been used is never
 * modified. An inspection's answers point at specific items, so editing a used
 * template would rewrite what somebody recorded standing at a machine.
 *
 * @see docs/modules/M3-checklists-and-inspections.md
 * @see docs/04-open-questions.md Q2
 */
class ChecklistTemplateTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->userWith(UserRole::Admin);
    }

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Standard Lab PC Check',
            'description' => 'Routine round check.',
            'items' => [
                ['label' => 'Machine powers on', 'category' => 'boot', 'is_critical' => '1'],
                ['label' => 'Monitor is stable', 'category' => 'display', 'is_critical' => '0'],
            ],
        ], $overrides);
    }

    /** A template that has been used by an inspection, so it is locked. */
    private function usedTemplate(): ChecklistTemplate
    {
        $template = ChecklistTemplate::factory()->active()->create();
        $item = ChecklistItem::factory()->create(['checklist_template_id' => $template->id]);

        $inspection = Inspection::factory()->completed()->create([
            'checklist_template_id' => $template->id,
        ]);

        InspectionResult::factory()->create([
            'inspection_id' => $inspection->id,
            'checklist_item_id' => $item->id,
            'result' => ChecklistResult::Ok->value,
        ]);

        return $template->fresh();
    }

    // ------------------------------------------------------------ access

    public function test_an_assistant_cannot_reach_checklist_templates(): void
    {
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('admin.checklists.index'))
            ->assertForbidden();
    }

    public function test_an_engineer_cannot_create_a_template(): void
    {
        $this->actingAs($this->userWith(UserRole::Engineer))
            ->post(route('admin.checklists.store'), $this->payload())
            ->assertForbidden();

        $this->assertDatabaseCount('checklist_templates', 0);
    }

    // ------------------------------------------------------------ create

    public function test_an_admin_can_build_a_template_with_ordered_items(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.checklists.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $template = ChecklistTemplate::first();

        $this->assertSame(1, $template->version);
        $this->assertCount(2, $template->items);

        // Position in the submitted array IS the order.
        $this->assertSame('Machine powers on', $template->items[0]->label);
        $this->assertSame(1, $template->items[0]->sort_order);
        $this->assertSame('Monitor is stable', $template->items[1]->label);
        $this->assertSame(2, $template->items[1]->sort_order);
    }

    public function test_the_critical_flag_is_saved_per_item(): void
    {
        // It is the entire difference between fail and pass_with_issues.
        $this->actingAs($this->admin())
            ->post(route('admin.checklists.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $items = ChecklistTemplate::first()->items;

        $this->assertTrue($items[0]->is_critical);
        $this->assertFalse($items[1]->is_critical);
    }

    public function test_a_template_with_no_items_is_rejected(): void
    {
        /*
         * A checklist with no questions would let an assistant "complete" an
         * inspection having answered nothing, and it would derive a Pass — a
         * machine certified working by a form that asked nothing.
         */
        $this->actingAs($this->admin())
            ->post(route('admin.checklists.store'), $this->payload(['items' => []]))
            ->assertSessionHasErrors('items');

        $this->assertDatabaseCount('checklist_templates', 0);
    }

    public function test_a_new_template_is_not_active_until_activated(): void
    {
        $this->actingAs($this->admin())
            ->post(route('admin.checklists.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertFalse(ChecklistTemplate::first()->is_active);
    }

    // ------------------------------------------------------------ editing

    public function test_editing_an_unused_template_changes_it_in_place(): void
    {
        // Nothing depends on a draft, and versioning one would fill the list
        // with noise.
        $template = ChecklistTemplate::factory()->create();
        ChecklistItem::factory()->create(['checklist_template_id' => $template->id]);

        $this->actingAs($this->admin())
            ->patch(route('admin.checklists.update', $template), $this->payload(['name' => 'Renamed']))
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ChecklistTemplate::count());
        $this->assertSame('Renamed', $template->fresh()->name);
    }

    public function test_editing_a_used_template_creates_a_new_version(): void
    {
        $template = $this->usedTemplate();

        $this->actingAs($this->admin())
            ->patch(route('admin.checklists.update', $template), $this->payload([
                'name' => $template->name,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ChecklistTemplate::count());

        $new = ChecklistTemplate::orderByDesc('version')->first();
        $this->assertSame(2, $new->version);
    }

    public function test_the_old_version_keeps_its_items_so_past_inspections_still_read(): void
    {
        /*
         * The whole reason versioning exists. If the edit mutated the original
         * items, a six-month-old inspection would render against questions
         * that were never asked, or lose ones that were.
         */
        $template = $this->usedTemplate();
        $originalItemId = $template->items->first()->id;
        $originalLabel = $template->items->first()->label;
        $originalCount = $template->items->count();

        $this->actingAs($this->admin())
            ->patch(route('admin.checklists.update', $template), $this->payload([
                'name' => $template->name,
            ]))
            // Asserting the edit SUCCEEDED matters as much as what survives.
            // Without it this test passes against a version of the service
            // that ignores the lock: the in-place item replace then dies on
            // the foreign key, the request 500s, and the old items survive
            // for entirely the wrong reason.
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $old = $template->fresh()->load('items');

        $this->assertCount($originalCount, $old->items);
        $this->assertSame($originalItemId, $old->items->first()->id);
        $this->assertSame($originalLabel, $old->items->first()->label);

        // And the inspection's recorded answer still resolves to the question
        // that was actually asked.
        $answer = InspectionResult::firstWhere('checklist_item_id', $originalItemId);
        $this->assertNotNull($answer);
        $this->assertSame($originalLabel, $answer->item->label);
    }

    public function test_the_new_version_inherits_being_active(): void
    {
        // Otherwise an edit silently leaves the system with no active
        // checklist and assistants cannot start a round.
        $template = $this->usedTemplate();

        $this->actingAs($this->admin())
            ->patch(route('admin.checklists.update', $template), $this->payload([
                'name' => $template->name,
            ]));

        $new = ChecklistTemplate::orderByDesc('version')->first();

        $this->assertTrue($new->is_active);
        $this->assertFalse($template->fresh()->is_active);
    }

    // ------------------------------------------------------------ activation

    public function test_activating_one_template_deactivates_every_other(): void
    {
        /*
         * Two active templates would make ChecklistTemplate::current() depend
         * on row order, and two assistants inspecting the same rack would
         * answer different questions.
         */
        $first = ChecklistTemplate::factory()->active()->create(['name' => 'First']);
        $second = ChecklistTemplate::factory()->create(['name' => 'Second']);

        $this->actingAs($this->admin())
            ->patch(route('admin.checklists.activate', $second))
            ->assertRedirect(route('admin.checklists.index'));

        $this->assertFalse($first->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
        $this->assertSame(1, ChecklistTemplate::where('is_active', true)->count());
    }

    public function test_the_current_template_is_the_active_one(): void
    {
        ChecklistTemplate::factory()->create(['name' => 'Old draft']);
        $active = ChecklistTemplate::factory()->active()->create(['name' => 'In use']);

        $this->assertSame($active->id, ChecklistTemplate::current()?->id);
    }

    public function test_current_is_null_when_nothing_is_active(): void
    {
        // A real state on a fresh install. Callers must handle it rather than
        // assuming a template exists.
        ChecklistTemplate::factory()->create();

        $this->assertNull(ChecklistTemplate::current());
    }

    // ------------------------------------------------------------ deleting

    public function test_a_used_template_cannot_be_deleted(): void
    {
        $template = $this->usedTemplate();

        $this->actingAs($this->admin())
            ->delete(route('admin.checklists.destroy', $template))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('checklist_templates', ['id' => $template->id]);
    }

    public function test_the_active_template_cannot_be_deleted(): void
    {
        // Deleting it would leave assistants with nothing to fill in.
        $template = ChecklistTemplate::factory()->active()->create();
        ChecklistItem::factory()->create(['checklist_template_id' => $template->id]);

        $this->actingAs($this->admin())
            ->delete(route('admin.checklists.destroy', $template))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('checklist_templates', ['id' => $template->id]);
    }

    public function test_an_unused_draft_can_be_deleted(): void
    {
        $template = ChecklistTemplate::factory()->create();
        ChecklistItem::factory()->create(['checklist_template_id' => $template->id]);

        $this->actingAs($this->admin())
            ->delete(route('admin.checklists.destroy', $template))
            ->assertRedirect(route('admin.checklists.index'));

        $this->assertDatabaseMissing('checklist_templates', ['id' => $template->id]);
    }

    // ------------------------------------------------------------ versioning maths

    public function test_a_new_version_never_collides_with_an_existing_one(): void
    {
        /*
         * Editing v2 while v3 already exists must not try to create a second
         * v3. The next number comes from the highest version sharing the name,
         * not from this template's own version + 1.
         */
        $v1 = ChecklistTemplate::factory()->create(['name' => 'Shared', 'version' => 1]);
        ChecklistTemplate::factory()->create(['name' => 'Shared', 'version' => 3]);

        $new = app(ChecklistService::class)->newVersionOf($v1);

        $this->assertSame(4, $new->version);
    }
}
