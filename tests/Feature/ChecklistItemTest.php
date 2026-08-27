<?php

namespace Tests\Feature;

use App\Enums\ChecklistResult;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * The inspection checklist is the most-used screen in the system: an assistant
 * works down a rack of machines on a phone, one hand, standing up.
 *
 * NFR-05 requires it to be operable that way and UAT tests it at 375px, so the
 * things worth pinning here are the ones that quietly stop being true —
 * the tap target being the whole box rather than a radio dot, the remark
 * appearing only when there is something to say, and the selected state having
 * colour at all.
 *
 * @see docs/modules/M3-checklists-and-inspections.md
 * @see docs/03-requirements.md FR-205, NFR-05
 */
class ChecklistItemTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        view()->share('errors', new ViewErrorBag);
    }

    private function item(int $id = 7, string $label = 'Monitor displays correctly', bool $critical = false): object
    {
        return (object) ['id' => $id, 'label' => $label, 'is_critical' => $critical];
    }

    private function render(object $item, array $extra = []): string
    {
        return Blade::render(
            '<x-checklist-item :item="$item" '.($extra['attrs'] ?? '').' />',
            ['item' => $item] + ($extra['data'] ?? [])
        );
    }

    public function test_it_offers_every_checklist_result(): void
    {
        $html = $this->render($this->item());

        foreach (ChecklistResult::cases() as $case) {
            $this->assertStringContainsString('value="'.$case->value.'"', $html);
            $this->assertStringContainsString($case->label(), $html);
        }
    }

    public function test_the_whole_box_is_the_tap_target_not_the_radio_dot(): void
    {
        // sr-only on the input plus a styled sibling means the label is the
        // hit area. A 16px radio dot is not usable one-handed on a phone.
        $html = $this->render($this->item());

        $this->assertStringContainsString('peer sr-only', $html);
        $this->assertStringContainsString('<label', $html);
        $this->assertStringContainsString('flex-1', $html);
    }

    public function test_the_selected_state_has_colour_from_the_shared_tone_source(): void
    {
        // If Tone::choice() ever returned classes Tailwind cannot see, every
        // selected choice would render identical to an unselected one and the
        // form would look broken without erroring.
        $html = $this->render($this->item());

        $this->assertStringContainsString('peer-checked:bg-emerald-50', $html);
        $this->assertStringContainsString('peer-checked:bg-red-50', $html);
        $this->assertStringContainsString('peer-checked:bg-slate-100', $html);
    }

    public function test_it_names_its_inputs_so_they_post_as_an_array(): void
    {
        $html = $this->render($this->item(id: 12));

        $this->assertStringContainsString('name="results[12][result]"', $html);
        $this->assertStringContainsString('name="results[12][remark]"', $html);
    }

    public function test_a_critical_item_says_so_before_it_is_answered(): void
    {
        // A critical failure makes the whole inspection a fail rather than
        // pass_with_issues. The assistant should know that before tapping.
        $plain = $this->render($this->item(critical: false));
        $critical = $this->render($this->item(critical: true));

        $this->assertStringNotContainsString('Critical', $plain);
        $this->assertStringContainsString('Critical', $critical);
    }

    public function test_the_remark_is_bound_to_the_issue_choice_only(): void
    {
        // A clean machine should be three taps, not six. A rack of thirty clean
        // machines is the normal case.
        $html = $this->render($this->item());

        $this->assertStringContainsString(
            'x-show="result === \''.ChecklistResult::Issue->value.'\'"',
            $html
        );
    }

    public function test_a_previously_chosen_result_comes_back_checked(): void
    {
        $html = Blade::render(
            '<x-checklist-item :item="$item" result="issue" />',
            ['item' => $this->item()]
        );

        $this->assertMatchesRegularExpression('/value="issue"[^>]*checked/', $html);
        $this->assertStringContainsString("result: 'issue'", $html);
    }

    public function test_a_validation_error_is_shown_against_the_item(): void
    {
        view()->share('errors', (new ViewErrorBag)->put('default', new MessageBag([
            'results.7.result' => ['Choose a result for this item.'],
        ])));

        $html = $this->render($this->item(id: 7));

        $this->assertStringContainsString('Choose a result for this item.', $html);
        $this->assertStringContainsString('ring-red-300', $html);
    }
}
