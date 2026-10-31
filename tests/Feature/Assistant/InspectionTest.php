<?php

namespace Tests\Feature\Assistant;

use App\Enums\ChecklistResult;
use App\Enums\DeviceStatus;
use App\Enums\InspectionOutcome;
use App\Enums\UserRole;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\LabPc;
use App\Models\User;
use App\Services\Maintenance\InspectionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M3 — completing an inspection.
 *
 * The derived outcome is the module. An assistant records what they saw and
 * the system decides what it adds up to, so the tests that matter are the ones
 * proving that arithmetic cannot be talked out of its answer.
 *
 * @see docs/modules/M3-checklists-and-inspections.md
 */
class InspectionTest extends TestCase
{
    use RefreshDatabase;

    private function assistant(): User
    {
        return $this->userWith(UserRole::Assistant);
    }

    private function userWith(UserRole $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role, 'is_active' => true])->save();

        return $user->fresh();
    }

    /**
     * A template with one critical item and one ordinary one — the smallest
     * checklist that can produce all three outcomes.
     */
    private function template(): ChecklistTemplate
    {
        $template = ChecklistTemplate::factory()->active()->create();

        ChecklistItem::factory()->critical()->create([
            'checklist_template_id' => $template->id,
            'label' => 'Machine powers on',
            'sort_order' => 1,
        ]);

        ChecklistItem::factory()->create([
            'checklist_template_id' => $template->id,
            'label' => 'Headphone socket works',
            'sort_order' => 2,
        ]);

        return $template->load('items');
    }

    /** @param  array<int, string>  $answers  item index => result value */
    private function completeWith(array $answers): Inspection
    {
        $template = $this->template();
        $inspection = Inspection::factory()->create([
            'checklist_template_id' => $template->id,
            'lab_pc_id' => LabPc::factory()->create()->id,
            'user_id' => $this->assistant()->id,
        ]);

        $payload = [];

        foreach ($template->items->values() as $index => $item) {
            $payload[$item->id] = ['result' => $answers[$index], 'remark' => 'noted'];
        }

        return app(InspectionService::class)->complete($inspection, $payload);
    }

    // ------------------------------------------------- the derived outcome

    public function test_all_ok_is_a_pass(): void
    {
        $inspection = $this->completeWith([
            ChecklistResult::Ok->value,
            ChecklistResult::Ok->value,
        ]);

        $this->assertSame(InspectionOutcome::Pass, $inspection->overall_result);
    }

    public function test_an_issue_on_an_ordinary_item_is_a_pass_with_issues(): void
    {
        $inspection = $this->completeWith([
            ChecklistResult::Ok->value,
            ChecklistResult::Issue->value,
        ]);

        $this->assertSame(InspectionOutcome::PassWithIssues, $inspection->overall_result);
    }

    public function test_an_issue_on_a_critical_item_is_a_fail(): void
    {
        $inspection = $this->completeWith([
            ChecklistResult::Issue->value,
            ChecklistResult::Ok->value,
        ]);

        $this->assertSame(InspectionOutcome::Fail, $inspection->overall_result);
    }

    public function test_one_critical_issue_outranks_any_number_of_ordinary_ones(): void
    {
        $inspection = $this->completeWith([
            ChecklistResult::Issue->value,
            ChecklistResult::Issue->value,
        ]);

        $this->assertSame(InspectionOutcome::Fail, $inspection->overall_result);
    }

    public function test_not_applicable_is_not_a_failure(): void
    {
        /*
         * An assistant marking "external monitor" N/A on a machine that has no
         * external monitor has not found a fault. Counting it as one would
         * make every such machine look broken, and would put a Fail on a
         * critical item nobody could ever answer OK.
         */
        $inspection = $this->completeWith([
            ChecklistResult::Na->value,
            ChecklistResult::Na->value,
        ]);

        $this->assertSame(InspectionOutcome::Pass, $inspection->overall_result);
    }

    // ------------------------------------------------- what completing does

    public function test_completing_stamps_the_machine_as_inspected(): void
    {
        // M2 leaves last_inspected_at for M3 to write; M7 reads it as a risk
        // factor and the lab PC show page renders it.
        $inspection = $this->completeWith([
            ChecklistResult::Ok->value,
            ChecklistResult::Ok->value,
        ]);

        $this->assertNotNull($inspection->labPc->fresh()->last_inspected_at);
    }

    public function test_a_remark_is_kept_only_against_an_issue(): void
    {
        // A remark left over on an item flipped back to OK would read as
        // "left USB port dead" attached to a pass.
        $inspection = $this->completeWith([
            ChecklistResult::Ok->value,
            ChecklistResult::Issue->value,
        ]);

        $byResult = $inspection->results->keyBy(fn ($r) => $r->result->value);

        $this->assertNull($byResult[ChecklistResult::Ok->value]->remark);
        $this->assertSame('noted', $byResult[ChecklistResult::Issue->value]->remark);
    }

    public function test_an_answer_for_another_templates_item_is_ignored(): void
    {
        /*
         * Item ids arrive from form field names. Without the guard in
         * InspectionService, an edited request could attach an answer
         * belonging to a different template — and the outcome would then be
         * derived partly from questions this inspection never asked.
         */
        $template = $this->template();
        $foreign = ChecklistItem::factory()->critical()->create();

        $inspection = Inspection::factory()->create([
            'checklist_template_id' => $template->id,
            'user_id' => $this->assistant()->id,
        ]);

        $payload = [$foreign->id => ['result' => ChecklistResult::Issue->value]];

        foreach ($template->items as $item) {
            $payload[$item->id] = ['result' => ChecklistResult::Ok->value];
        }

        $completed = app(InspectionService::class)->complete($inspection, $payload);

        $this->assertSame(InspectionOutcome::Pass, $completed->overall_result);
        $this->assertCount(2, $completed->results);
    }

    public function test_a_second_submit_is_refused_and_doubles_nothing(): void
    {
        // A phone on bad campus Wi-Fi is the normal case here, so a double
        // submit must not count the same issue twice, nor rewrite a record
        // the policy treats as finished evidence.
        $template = $this->template();
        $inspection = Inspection::factory()->create([
            'checklist_template_id' => $template->id,
            'user_id' => $this->assistant()->id,
        ]);

        $service = app(InspectionService::class);
        $answers = $template->items->mapWithKeys(
            fn ($item) => [$item->id => ['result' => ChecklistResult::Ok->value]]
        )->all();

        $service->complete($inspection, $answers);
        $finishedAt = $inspection->fresh()->completed_at;

        try {
            $service->complete($inspection->fresh(), $answers);
            $this->fail('A second submit should be refused.');
        } catch (DomainException) {
        }

        $this->assertCount(2, $inspection->fresh()->results);
        $this->assertTrue($finishedAt->eq($inspection->fresh()->completed_at));
    }

    // ------------------------------------------------- starting a round

    public function test_starting_twice_resumes_the_same_inspection(): void
    {
        // An assistant on bad Wi-Fi taps start twice and must get one
        // inspection, not two competing records of the same visit.
        $this->template();
        $assistant = $this->assistant();
        $labPc = LabPc::factory()->create();

        $service = app(InspectionService::class);

        $first = $service->start($labPc, $assistant);
        $second = $service->start($labPc, $assistant);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Inspection::count());
    }

    public function test_starting_without_an_active_template_is_reported_not_crashed(): void
    {
        // A fresh install. The assistant can do nothing about it, so the
        // screen must say who can rather than return a 500.
        $labPc = LabPc::factory()->create();

        $this->actingAs($this->assistant())
            ->post(route('assistant.inspections.store'), ['lab_pc_id' => $labPc->id])
            ->assertSessionHas('error');

        $this->assertSame(0, Inspection::count());
    }

    // ------------------------------------------------- the form and its rules

    /**
     * The form renders for an inspection with nothing answered yet.
     *
     * Which is every inspection at the moment it starts, and was the gap that
     * let a 500 into the most-used screen in the system: every other test of
     * this route asserts a 403, so none of them ever rendered it. Reading a
     * missing key off the saved-answers Collection with subscript syntax
     * threw, and the whole checklist form died.
     */
    public function test_the_checklist_form_renders_for_a_fresh_inspection(): void
    {
        $template = $this->template();
        $assistant = $this->assistant();
        $inspection = Inspection::factory()->create([
            'checklist_template_id' => $template->id,
            'user_id' => $assistant->id,
        ]);

        $this->actingAs($assistant)
            ->get(route('assistant.inspections.edit', $inspection))
            ->assertOk()
            ->assertSee('Machine powers on')
            ->assertSee('Headphone socket works');
    }

    public function test_a_resumed_inspection_shows_the_answers_already_given(): void
    {
        $template = $this->template();
        $assistant = $this->assistant();
        $inspection = Inspection::factory()->create([
            'checklist_template_id' => $template->id,
            'user_id' => $assistant->id,
        ]);

        $inspection->results()->create([
            'checklist_item_id' => $template->items->last()->id,
            'result' => ChecklistResult::Issue->value,
            'remark' => 'Crackles on the left channel.',
        ]);

        $this->actingAs($assistant)
            ->get(route('assistant.inspections.edit', $inspection))
            ->assertOk()
            ->assertSee('Crackles on the left channel.');
    }

    public function test_the_summary_renders_with_its_issues(): void
    {
        $inspection = $this->completeWith([
            ChecklistResult::Issue->value,
            ChecklistResult::Ok->value,
        ]);

        $this->actingAs($inspection->user)
            ->get(route('assistant.inspections.show', $inspection))
            ->assertOk()
            ->assertSee('Machine powers on')
            ->assertSee('Fail');
    }

    public function test_the_service_itself_refuses_a_checklist_with_items_unanswered(): void
    {
        /*
         * deriveOutcome() looks for failures among the answers it has, and an
         * unanswered item is not a failure. Called with one answer out of the
         * whole checklist, complete() derived Pass and stamped the machine
         * inspected. The form has always refused this; the service, which owns
         * the rule, did not. Found by driving it against the live database,
         * where it accepted 1 of 13.
         */
        $template = $this->template();
        $labPc = LabPc::factory()->create(['last_inspected_at' => null]);
        $inspection = Inspection::factory()->create([
            'checklist_template_id' => $template->id,
            'lab_pc_id' => $labPc->id,
            'user_id' => $this->assistant()->id,
        ]);

        $onlyTheFirst = [$template->items->first()->id => ['result' => ChecklistResult::Ok->value]];

        try {
            app(InspectionService::class)->complete($inspection, $onlyTheFirst);
            $this->fail('An inspection with an item unanswered was accepted.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('1 of 2 still have no answer', $e->getMessage());
        }

        $fresh = $inspection->fresh();

        // Refused as a whole: nothing half-saved, nothing stamped.
        $this->assertTrue($fresh->isInProgress(), 'A refused inspection was marked complete.');
        $this->assertNull($fresh->overall_result);
        $this->assertSame(0, $fresh->results()->count(), 'The answers from a refused submission were kept.');
        $this->assertNull($labPc->fresh()->last_inspected_at, 'The machine was stamped as inspected anyway.');
    }

    public function test_a_refusal_from_the_service_reaches_the_assistant_as_a_message(): void
    {
        // The form refuses an incomplete checklist first, so this can only be
        // forced. A service refusal must never become a 500.
        $template = $this->template();
        $assistant = $this->assistant();
        $inspection = Inspection::factory()->create([
            'checklist_template_id' => $template->id,
            'user_id' => $assistant->id,
        ]);

        $this->mock(InspectionService::class)
            ->shouldReceive('complete')
            ->andThrow(new DomainException('Something the form did not catch.'));

        $payload = $template->items->mapWithKeys(fn ($item) => [$item->id => ['result' => ChecklistResult::Ok->value]])->all();

        $this->actingAs($assistant)
            ->from(route('assistant.inspections.edit', $inspection))
            ->patch(route('assistant.inspections.update', $inspection), ['results' => $payload])
            ->assertRedirect(route('assistant.inspections.edit', $inspection))
            ->assertSessionHas('error', 'Something the form did not catch.');
    }

    public function test_an_unfinished_inspection_sends_its_owner_back_to_the_form(): void
    {
        /*
         * The summary opens with a status badge on `overall_result`, and that
         * column is null until the checklist is submitted. Rendering it anyway
         * called tone() on null: a 500 on a real record, for the one person
         * entitled to look at it. The controller said in a comment that it
         * redirected here; it did not.
         */
        $inspection = Inspection::factory()->create([
            'user_id' => $this->assistant()->id,
            'checklist_template_id' => $this->template()->id,
        ]);

        $this->actingAs($inspection->user)
            ->get(route('assistant.inspections.show', $inspection))
            ->assertRedirect(route('assistant.inspections.edit', $inspection));
    }

    public function test_an_engineer_cannot_read_an_inspection_that_is_still_being_filled_in(): void
    {
        // Engineers and admins read inspections as the evidence behind a
        // ticket and a risk score. A half-answered checklist is not that yet.
        $inspection = Inspection::factory()->create([
            'user_id' => $this->assistant()->id,
            'checklist_template_id' => $this->template()->id,
        ]);

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('assistant.inspections.show', $inspection))
            ->assertForbidden();
    }

    public function test_the_rounds_index_renders(): void
    {
        // Caught a HAVING clause against a withCount alias, which is invalid
        // SQL without a GROUP BY and which SQLite refuses outright.
        $this->template();
        LabPc::factory()->create();

        $this->actingAs($this->assistant())
            ->get(route('assistant.inspections.index'))
            ->assertOk();
    }

    public function test_the_machine_list_for_a_location_renders(): void
    {
        // Caught a missing LabPc::inspections() relation — M2 owns the model,
        // M3 is what gave it inspections to count.
        $this->template();
        $labPc = LabPc::factory()->create();

        $this->actingAs($this->assistant())
            ->get(route('assistant.inspections.create', ['location' => $labPc->location_id]))
            ->assertOk()
            ->assertSee($labPc->asset_tag);
    }

    public function test_a_machine_already_checked_today_is_marked_as_such(): void
    {
        $this->template();
        $labPc = LabPc::factory()->create();

        Inspection::factory()->completed()->create([
            'lab_pc_id' => $labPc->id,
            'checklist_template_id' => ChecklistTemplate::current()->id,
        ]);

        /*
         * The marker element, not the bare words. A card subtitle once read
         * "Already checked today are marked", so asserting 'checked today'
         * passed whether the machine was marked or not: it matched the
         * subtitle. It had never been able to fail. The attribute survives a
         * restyle; the words and classes do not.
         */
        $this->actingAs($this->assistant())
            ->get(route('assistant.inspections.create', ['location' => $labPc->location_id]))
            ->assertOk()
            ->assertSee('data-checked-today', false);
    }

    public function test_an_assistant_can_submit_a_full_checklist_through_the_form(): void
    {
        $template = $this->template();
        $assistant = $this->assistant();
        $inspection = Inspection::factory()->create([
            'checklist_template_id' => $template->id,
            'user_id' => $assistant->id,
        ]);

        $this->actingAs($assistant)
            ->patch(route('assistant.inspections.update', $inspection), [
                'results' => $template->items->mapWithKeys(
                    fn ($item) => [$item->id => ['result' => ChecklistResult::Ok->value]]
                )->all(),
                'notes' => 'All good.',
            ])
            ->assertRedirect(route('assistant.inspections.show', $inspection))
            ->assertSessionHasNoErrors();

        $this->assertSame(InspectionOutcome::Pass, $inspection->fresh()->overall_result);
    }

    public function test_an_unanswered_item_is_rejected(): void
    {
        /*
         * The failure this exists for: `results.*.result` only fires for keys
         * that are PRESENT, so an item nobody touched sends nothing, has no
         * key, and no rule runs against it. Without the after() check the form
         * validates and the outcome derives as Pass — a machine certified
         * working on questions nobody answered.
         */
        $template = $this->template();
        $assistant = $this->assistant();
        $inspection = Inspection::factory()->create([
            'checklist_template_id' => $template->id,
            'user_id' => $assistant->id,
        ]);

        $first = $template->items->first();

        $this->actingAs($assistant)
            ->patch(route('assistant.inspections.update', $inspection), [
                'results' => [$first->id => ['result' => ChecklistResult::Ok->value]],
            ])
            ->assertSessionHasErrors("results.{$template->items->last()->id}.result");

        $this->assertNull($inspection->fresh()->completed_at);
    }

    public function test_a_refused_checklist_says_at_the_top_how_many_items_are_missing(): void
    {
        // The page reloads at the top, and on a phone the missed item can be
        // far below. Found in an end-to-end run of the seeded install.
        $template = $this->template();
        $assistant = $this->assistant();
        $inspection = Inspection::factory()->create([
            'checklist_template_id' => $template->id,
            'user_id' => $assistant->id,
        ]);
        $form = route('assistant.inspections.edit', $inspection);
        $missing = $template->items->count() - 1;

        $this->actingAs($assistant)->from($form)
            ->patch(route('assistant.inspections.update', $inspection), [
                'results' => [$template->items->first()->id => ['result' => ChecklistResult::Ok->value]],
            ])
            ->assertRedirect($form);

        $this->actingAs($assistant)->get($form)
            ->assertOk()
            ->assertSee($missing.' '.($missing === 1 ? 'item still needs' : 'items still need').' an answer')
            ->assertSee('Answer this item before submitting.');
    }

    // ------------------------------------------------- authorisation

    public function test_an_assistant_cannot_open_someone_elses_inspection(): void
    {
        $inspection = Inspection::factory()->create([
            'user_id' => $this->assistant()->id,
            'checklist_template_id' => $this->template()->id,
        ]);

        $this->actingAs($this->assistant())
            ->get(route('assistant.inspections.edit', $inspection))
            ->assertForbidden();
    }

    public function test_a_submitted_inspection_can_no_longer_be_edited(): void
    {
        /*
         * An inspection is a signed statement that a named person checked a
         * machine on a date. Once submitted it is evidence — getting it wrong
         * means inspecting the machine again, which is honest.
         */
        $assistant = $this->assistant();
        $inspection = Inspection::factory()->completed()->create([
            'user_id' => $assistant->id,
            'checklist_template_id' => $this->template()->id,
        ]);

        $this->actingAs($assistant)
            ->get(route('assistant.inspections.edit', $inspection))
            ->assertForbidden();
    }

    public function test_a_submitted_inspection_cannot_be_abandoned(): void
    {
        $assistant = $this->assistant();
        $inspection = Inspection::factory()->completed()->create([
            'user_id' => $assistant->id,
            'checklist_template_id' => $this->template()->id,
        ]);

        $this->actingAs($assistant)
            ->delete(route('assistant.inspections.destroy', $inspection))
            ->assertForbidden();

        $this->assertDatabaseHas('inspections', ['id' => $inspection->id]);
    }

    public function test_an_engineer_can_read_an_inspection_summary(): void
    {
        // It is the evidence behind a ticket and a risk score.
        $inspection = Inspection::factory()->completed()->create([
            'user_id' => $this->assistant()->id,
            'checklist_template_id' => $this->template()->id,
        ]);

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('assistant.inspections.show', $inspection))
            ->assertOk();
    }

    public function test_an_engineer_cannot_start_an_inspection(): void
    {
        $this->template();

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('assistant.inspections.index'))
            ->assertForbidden();
    }

    public function test_a_retired_machine_cannot_be_inspected(): void
    {
        // An inspection of hardware that is physically gone from the room is a
        // record nothing else acknowledges.
        $pc = LabPc::factory()->create(['status' => DeviceStatus::Retired->value]);
        $pc->delete();

        $this->expectException(DomainException::class);

        app(InspectionService::class)->start($pc, $this->assistant());
    }

    public function test_an_operational_machine_can_still_be_inspected(): void
    {
        $this->template();

        $inspection = app(InspectionService::class)->start(LabPc::factory()->create(), $this->assistant());

        $this->assertNotNull($inspection->id);
    }
}
