<?php

namespace Tests\Feature\Tickets;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\ChecklistItem;
use App\Models\ChecklistTemplate;
use App\Models\Inspection;
use App\Models\InspectionResult;
use App\Models\LabPc;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M4 — who may see and do what, and whether the screens render.
 *
 * Every route here has at least one test that fetches it successfully. M3
 * shipped three 500s onto routes whose tests all asserted 403s, so a route
 * that is only ever tested failing has never actually been rendered.
 *
 * @see docs/progress/decision-log.md — 2026-08-28
 */
class TicketAccessTest extends TestCase
{
    use RefreshDatabase;

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
            'title' => 'Machine will not power on',
            'description' => 'No fan, no lights when the button is pressed.',
            'category' => 'hardware',
            'priority' => TicketPriority::Critical->value,
        ], $overrides);
    }

    // ------------------------------------------------------------ rendering

    public function test_the_engineer_queue_renders_when_empty(): void
    {
        // Empty is what every screen looks like on the day it ships.
        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('engineer.tickets.index'))
            ->assertOk();
    }

    public function test_the_engineer_queue_renders_with_tickets(): void
    {
        // Also exercises the priority ordering, which is raw SQL and would be
        // MySQL-only if anybody reached for FIELD().
        Ticket::factory()->count(3)->create();
        Ticket::factory()->breaching()->create();

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('engineer.tickets.index'))
            ->assertOk()
            ->assertSee('past due');
    }

    public function test_the_ticket_detail_renders(): void
    {
        $engineer = $this->userWith(UserRole::Engineer);
        $ticket = Ticket::factory()->create();

        $this->actingAs($engineer)
            ->get(route('engineer.tickets.show', $ticket))
            ->assertOk()
            ->assertSee($ticket->ticket_number)
            // Buttons render from the state machine, so Open offers exactly
            // one move.
            ->assertSee('Acknowledge');
    }

    public function test_the_assistant_ticket_list_renders_when_empty(): void
    {
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('assistant.tickets.index'))
            ->assertOk();
    }

    public function test_the_raise_ticket_form_renders(): void
    {
        LabPc::factory()->create();

        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('assistant.tickets.create'))
            ->assertOk();
    }

    // ------------------------------------------------------------ raising

    public function test_an_assistant_can_raise_a_ticket(): void
    {
        $labPc = LabPc::factory()->create();

        $this->actingAs($this->userWith(UserRole::Assistant))
            ->post(route('assistant.tickets.store'), $this->payload([
                'ticketable_type' => 'lab_pc',
                'ticketable_id' => $labPc->id,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('tickets', [
            'title' => 'Machine will not power on',
            'status' => TicketStatus::Open->value,
        ]);
    }

    public function test_a_ticket_can_be_raised_without_naming_a_machine(): void
    {
        /*
         * "No network anywhere on Level 6." The form posts a fixed
         * ticketable_type alongside an optional machine picker, so without
         * clearing the orphan type this is rejected by required_with — a real
         * fault the system would refuse to record.
         */
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->post(route('assistant.tickets.store'), $this->payload([
                'ticketable_type' => 'lab_pc',
                'ticketable_id' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('tickets', 1);
    }

    public function test_an_engineer_may_also_raise_a_ticket(): void
    {
        // They find faults while working on something else. Making them ask an
        // assistant to file it is how faults stop being recorded.
        $this->actingAs($this->userWith(UserRole::Engineer))
            ->post(route('assistant.tickets.store'), $this->payload())
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('tickets', 1);
    }

    // ------------------------------------------------------------ visibility

    public function test_an_assistant_cannot_open_someone_elses_ticket(): void
    {
        $ticket = Ticket::factory()->create([
            'reported_by' => $this->userWith(UserRole::Assistant)->id,
        ]);

        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('assistant.tickets.show', $ticket))
            ->assertForbidden();
    }

    public function test_an_assistant_can_open_their_own_ticket(): void
    {
        $reporter = $this->userWith(UserRole::Assistant);
        $ticket = Ticket::factory()->create(['reported_by' => $reporter->id]);

        $this->actingAs($reporter)
            ->get(route('assistant.tickets.show', $ticket))
            ->assertOk()
            ->assertSee($ticket->ticket_number);
    }

    public function test_an_assistant_cannot_reach_the_engineer_queue(): void
    {
        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('engineer.tickets.index'))
            ->assertForbidden();
    }

    public function test_an_internal_note_is_hidden_from_the_reporter(): void
    {
        /*
         * The one leak that would actually matter here. Filtering happens in
         * the query scope, never in Blade — a view that decides visibility is
         * one misplaced @if from showing an assistant what the engineers said
         * about their report.
         */
        $reporter = $this->userWith(UserRole::Assistant);
        $ticket = Ticket::factory()->create(['reported_by' => $reporter->id]);

        TicketComment::factory()->internal()->create([
            'ticket_id' => $ticket->id,
            'body' => 'This one always breaks, third time this term.',
        ]);
        TicketComment::factory()->create([
            'ticket_id' => $ticket->id,
            'body' => 'Looking at this tomorrow morning.',
        ]);

        $this->actingAs($reporter)
            ->get(route('assistant.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('Looking at this tomorrow morning.')
            ->assertDontSee('This one always breaks');
    }

    public function test_an_engineer_sees_internal_notes(): void
    {
        $ticket = Ticket::factory()->create();
        TicketComment::factory()->internal()->create([
            'ticket_id' => $ticket->id,
            'body' => 'This one always breaks, third time this term.',
        ]);

        $this->actingAs($this->userWith(UserRole::Engineer))
            ->get(route('engineer.tickets.show', $ticket))
            ->assertOk()
            ->assertSee('This one always breaks');
    }

    public function test_a_refused_comment_stays_internal(): void
    {
        // The box came back unticked after an empty comment was refused, so
        // the retry went to the reporter.
        $ticket = Ticket::factory()->create();

        $page = $this->actingAs($this->userWith(UserRole::Engineer))
            ->from(route('engineer.tickets.show', $ticket))
            ->followingRedirects()
            ->post(route('tickets.comments.store', $ticket), ['body' => '', 'is_internal' => '1'])
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<input type="checkbox" name="is_internal"[^>]*\bchecked\b/', $page);
    }

    public function test_an_assistant_cannot_post_an_internal_note(): void
    {
        // The checkbox is not rendered for them, but a checkbox that is merely
        // absent from the page is not a permission check.
        $reporter = $this->userWith(UserRole::Assistant);
        $ticket = Ticket::factory()->create(['reported_by' => $reporter->id]);

        $this->actingAs($reporter)
            ->post(route('tickets.comments.store', $ticket), [
                'body' => 'Trying to hide this.',
                'is_internal' => '1',
            ]);

        $this->assertFalse($ticket->comments()->first()->is_internal);
    }

    // ------------------------------------------------------------ transitions

    public function test_an_assistant_cannot_acknowledge_a_ticket(): void
    {
        $reporter = $this->userWith(UserRole::Assistant);
        $ticket = Ticket::factory()->create(['reported_by' => $reporter->id]);

        $this->actingAs($reporter)
            ->patch(route('tickets.transition', $ticket), ['status' => TicketStatus::Acknowledged->value])
            ->assertForbidden();

        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
    }

    public function test_an_engineer_cannot_resolve_a_ticket_assigned_to_someone_else(): void
    {
        // Otherwise two engineers are both "working on" one ticket without
        // either knowing — the coordination problem this module replaces.
        $owner = $this->userWith(UserRole::Engineer);
        $other = $this->userWith(UserRole::Engineer);

        $ticket = Ticket::factory()->assignedTo($owner)->create([
            'status' => TicketStatus::InProgress->value,
        ]);

        $this->actingAs($other)
            ->patch(route('tickets.transition', $ticket), [
                'status' => TicketStatus::Resolved->value,
                'resolution_notes' => 'Claiming this one.',
            ])
            ->assertForbidden();
    }

    public function test_resolving_without_notes_is_rejected(): void
    {
        // "No silent closures."
        $engineer = $this->userWith(UserRole::Engineer);
        $ticket = Ticket::factory()->assignedTo($engineer)->create([
            'status' => TicketStatus::InProgress->value,
        ]);

        $this->actingAs($engineer)
            ->patch(route('tickets.transition', $ticket), [
                'status' => TicketStatus::Resolved->value,
            ])
            ->assertSessionHasErrors('resolution_notes');

        $this->assertSame(TicketStatus::InProgress, $ticket->fresh()->status);
    }

    public function test_an_illegal_transition_posted_by_hand_is_refused(): void
    {
        /*
         * The whole reason the machine lives in the service. The UI never
         * offers this button — but the button is a suggestion, and this is a
         * hand-written request that ignores it.
         */
        $engineer = $this->userWith(UserRole::Engineer);
        $ticket = Ticket::factory()->create(['status' => TicketStatus::Open->value]);

        $this->actingAs($engineer)
            ->patch(route('tickets.transition', $ticket), [
                'status' => TicketStatus::Closed->value,
            ])
            ->assertSessionHas('error');

        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
    }

    // ------------------------------------------ the hand-off from M3 (FR-207)

    public function test_the_form_is_prefilled_from_a_failed_checklist_item(): void
    {
        /*
         * The demo's best moment: a failed item already knows the machine, the
         * location and the fault, so raising a ticket asks for none of it
         * again. M3 built the panel; this is the other half.
         */
        [$assistant, $inspection, $result] = $this->failedInspection();

        $this->actingAs($assistant)
            ->get(route('assistant.tickets.create', [
                'inspection' => $inspection->id,
                'item' => $result->checklist_item_id,
            ]))
            ->assertOk()
            ->assertSee('Machine powers on', false)
            ->assertSee('Does not power on at all.', false)
            // A critical item failing is what makes an inspection a Fail, so
            // the priority arrives matching.
            ->assertSee('Filled in from your inspection');
    }

    public function test_the_prefill_ignores_somebody_elses_inspection(): void
    {
        // Falls back to a blank form rather than 403ing — the assistant can
        // still raise the ticket by hand, and refusing outright helps nobody.
        [, $inspection, $result] = $this->failedInspection();

        $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('assistant.tickets.create', [
                'inspection' => $inspection->id,
                'item' => $result->checklist_item_id,
            ]))
            ->assertOk()
            ->assertDontSee('Filled in from your inspection');
    }

    public function test_a_ticket_raised_from_an_inspection_keeps_the_link_back(): void
    {
        [$assistant, $inspection, $result] = $this->failedInspection();

        $this->actingAs($assistant)
            ->post(route('assistant.tickets.store'), $this->payload([
                'ticketable_type' => 'lab_pc',
                'ticketable_id' => $inspection->lab_pc_id,
                'inspection_id' => $inspection->id,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('tickets', ['inspection_id' => $inspection->id]);
    }

    public function test_the_prefilled_title_says_the_check_failed(): void
    {
        // Checklist items say what should be true. The bare label put
        // "Machine powers on" in the queue for a machine that does not.
        [$assistant, $inspection, $result] = $this->failedInspection();

        $this->actingAs($assistant)
            ->get(route('assistant.tickets.create', [
                'inspection' => $inspection->id,
                'item' => $result->checklist_item_id,
            ]))
            ->assertSee('value="Failed check: Machine powers on"', false);
    }

    public function test_a_failed_check_is_ticketed_once(): void
    {
        [$assistant, $inspection, $result] = $this->failedInspection();
        $raise = fn () => $this->actingAs($assistant)->post(route('assistant.tickets.store'), $this->payload([
            'ticketable_type' => 'lab_pc',
            'ticketable_id' => $inspection->lab_pc_id,
            'inspection_id' => $inspection->id,
            'inspection_result_id' => $result->id,
        ]));

        $raise()->assertSessionHasNoErrors();
        $raise()->assertSessionHas('error');

        $this->assertSame(1, Ticket::where('inspection_result_id', $result->id)->count());
    }

    public function test_coming_back_to_a_ticketed_check_goes_to_the_inspection(): void
    {
        [$assistant, $inspection, $result] = $this->failedInspection();
        $ticket = Ticket::factory()->create([
            'reported_by' => $assistant->id,
            'inspection_id' => $inspection->id,
            'inspection_result_id' => $result->id,
        ]);

        $this->actingAs($assistant)
            ->get(route('assistant.tickets.create', [
                'inspection' => $inspection->id,
                'item' => $result->checklist_item_id,
            ]))
            ->assertRedirect(route('assistant.inspections.show', $inspection))
            ->assertSessionHas('warning', "That fault was already raised as {$ticket->ticket_number}.");
    }

    public function test_the_inspection_shows_the_ticket_instead_of_offering_another(): void
    {
        [$assistant, $inspection, $result] = $this->failedInspection();
        $inspection->forceFill(['completed_at' => now(), 'overall_result' => 'fail'])->save();
        $ticket = Ticket::factory()->create([
            'reported_by' => $assistant->id,
            'inspection_id' => $inspection->id,
            'inspection_result_id' => $result->id,
        ]);

        $this->actingAs($assistant)
            ->get(route('assistant.inspections.show', $inspection))
            ->assertOk()
            ->assertSee($ticket->ticket_number)
            ->assertDontSee('Raise ticket');
    }

    public function test_a_check_from_another_inspection_is_refused(): void
    {
        // The answer must belong to the inspection the ticket names, or a
        // crafted POST could mark somebody else's fault as ticketed.
        [$assistant, $inspection] = $this->failedInspection();
        [, , $otherResult] = $this->failedInspection();

        $this->actingAs($assistant)
            ->post(route('assistant.tickets.store'), $this->payload([
                'inspection_id' => $inspection->id,
                'inspection_result_id' => $otherResult->id,
            ]))
            ->assertSessionHasErrors('inspection_result_id');
    }

    /**
     * An assistant's completed inspection with one failed critical item.
     *
     * @return array{User, Inspection, InspectionResult}
     */
    private function failedInspection(): array
    {
        $assistant = $this->userWith(UserRole::Assistant);

        $template = ChecklistTemplate::factory()->active()->create();
        $item = ChecklistItem::factory()->critical()->create([
            'checklist_template_id' => $template->id,
            'label' => 'Machine powers on',
        ]);

        $inspection = Inspection::factory()->create([
            'checklist_template_id' => $template->id,
            'user_id' => $assistant->id,
        ]);

        $result = InspectionResult::factory()->issue('Does not power on at all.')->create([
            'inspection_id' => $inspection->id,
            'checklist_item_id' => $item->id,
        ]);

        return [$assistant, $inspection, $result];
    }

    public function test_the_reporter_may_close_and_reopen(): void
    {
        // Q3's transition table says "Reporter or engineer" for closing, and
        // the reporter keeping the last word is what makes that safe.
        $reporter = $this->userWith(UserRole::Assistant);
        $engineer = $this->userWith(UserRole::Engineer);

        $ticket = Ticket::factory()->assignedTo($engineer)->create([
            'reported_by' => $reporter->id,
            'status' => TicketStatus::Resolved->value,
            'resolved_at' => now(),
        ]);

        $this->actingAs($reporter)
            ->patch(route('tickets.transition', $ticket), ['status' => TicketStatus::Reopened->value])
            ->assertSessionHasNoErrors();

        $this->assertSame(TicketStatus::Reopened, $ticket->fresh()->status);
    }

    // ------------------------------------------------------------ the form's shape

    public function test_the_machine_picker_is_grouped_by_location(): void
    {
        // One select of every machine in the estate was fine at 9 machines and
        // is not at 250. Native <optgroup>s, so it works on a phone and with a
        // screen reader, without JavaScript.
        $lab = LabPc::factory()->create(['asset_tag' => 'GRP-LAB-01']);
        $library = LabPc::factory()->create(['asset_tag' => 'GRP-LIB-01']);

        $page = $this->actingAs($this->userWith(UserRole::Assistant))
            ->get(route('assistant.tickets.create'))
            ->assertOk();

        $page->assertSee('<optgroup label="'.e($lab->location->label()).'"', escape: false);
        $page->assertSee('<optgroup label="'.e($library->location->label()).'"', escape: false);
        $page->assertSee('GRP-LAB-01');
        $page->assertSee('GRP-LIB-01');
    }

    public function test_the_assistant_list_is_cards_on_a_phone_and_a_table_on_a_desk(): void
    {
        // Both are in the markup; CSS decides which shows at which width —
        // md:hidden on the cards, max-md:hidden on the table. A six-column
        // table at 375px showed one column and hid the rest behind a sideways
        // scroll nobody could see.
        $assistant = $this->userWith(UserRole::Assistant);
        Ticket::factory()->create(['reported_by' => $assistant->id, 'title' => 'Card and row']);

        $page = $this->actingAs($assistant)->get(route('assistant.tickets.index'))->assertOk();

        $page->assertSee('id="mobile-ticket-list"', escape: false);
        $page->assertSee('max-md:hidden');
        $this->assertSame(
            2,
            substr_count($page->getContent(), 'Card and row'),
            'The ticket should appear once as a card and once as a row.',
        );
    }
}
