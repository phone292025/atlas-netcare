<?php

namespace Tests\Feature;

use App\Enums\TicketPriority;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * The form controls are the most reused components in the system — every admin
 * CRUD screen and the inspection form are built out of them.
 *
 * What is worth testing here is the wiring a caller cannot see and would not
 * notice missing: the label pointing at its own control, the error being
 * announced with the field rather than as a stray paragraph, and old input
 * surviving a failed validation.
 *
 * @see docs/03-requirements.md NFR-10
 */
class FormComponentsTest extends TestCase
{
    private function renderWithErrors(string $template, array $errors = [], array $old = [], array $data = []): string
    {
        $bag = new ViewErrorBag;

        if ($errors !== []) {
            $bag->put('default', new MessageBag($errors));
        }

        view()->share('errors', $bag);

        if ($old !== []) {
            // old() reads through the request's session, and a request built
            // outside the HTTP cycle has none attached. Without this the helper
            // silently returns its default and the assertion looks like a
            // component bug rather than a test-harness one.
            session()->flashInput($old);
            $this->app['request']->setLaravelSession($this->app['session.store']);
        }

        return Blade::render($template, $data);
    }

    public function test_the_label_points_at_its_own_control(): void
    {
        $html = $this->renderWithErrors('<x-form.input name="asset_tag" label="Asset tag" />');

        $this->assertStringContainsString('for="asset-tag"', $html);
        $this->assertStringContainsString('id="asset-tag"', $html);
        $this->assertStringContainsString('name="asset_tag"', $html);
    }

    public function test_an_array_style_name_still_produces_a_usable_id(): void
    {
        // results[12][remark] must become a valid DOM id, or the label stops
        // focusing the control on the inspection form.
        $html = $this->renderWithErrors('<x-form.textarea name="results[12][remark]" label="Remark" />');

        $this->assertStringContainsString('id="results-12-remark"', $html);
        $this->assertStringContainsString('for="results-12-remark"', $html);
    }

    public function test_an_error_is_shown_and_announced_with_the_field(): void
    {
        $html = $this->renderWithErrors(
            '<x-form.input name="asset_tag" label="Asset tag" />',
            ['asset_tag' => ['Asset tag APU-1042 is already in use.']]
        );

        $this->assertStringContainsString('Asset tag APU-1042 is already in use.', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="asset-tag-error"', $html);
        $this->assertStringContainsString('id="asset-tag-error"', $html);
    }

    public function test_an_array_style_name_finds_its_error_under_the_dotted_key(): void
    {
        // The input is named results[12][result]; the validator stores the
        // error under results.12.result. A caller should never have to know.
        $html = $this->renderWithErrors(
            '<x-form.input name="results[12][result]" label="Result" />',
            ['results.12.result' => ['Choose a result.']]
        );

        $this->assertStringContainsString('Choose a result.', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
    }

    public function test_help_text_is_announced_with_the_field(): void
    {
        $html = $this->renderWithErrors(
            '<x-form.input name="asset_tag" label="Asset tag" help="Printed on the case." />'
        );

        $this->assertStringContainsString('aria-describedby="asset-tag-help"', $html);
        $this->assertStringContainsString('id="asset-tag-help"', $html);
    }

    public function test_old_input_survives_a_failed_validation(): void
    {
        // Without this a rejected form comes back blank and the user retypes
        // everything — the most common complaint in a usability session.
        $html = $this->renderWithErrors(
            '<x-form.input name="asset_tag" label="Asset tag" value="ORIGINAL" />',
            ['asset_tag' => ['Nope.']],
            ['asset_tag' => 'WHAT-THEY-TYPED']
        );

        $this->assertStringContainsString('value="WHAT-THEY-TYPED"', $html);
        $this->assertStringNotContainsString('value="ORIGINAL"', $html);
    }

    public function test_a_required_field_is_marked_for_sighted_and_screen_reader_users_alike(): void
    {
        $html = $this->renderWithErrors('<x-form.input name="name" label="Name" required />');

        $this->assertStringContainsString('required', $html);
        $this->assertStringContainsString('(required)', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_select_takes_its_options_straight_from_an_enum(): void
    {
        $html = $this->renderWithErrors(
            '<x-form.select name="priority" label="Priority" :options="$options" selected="medium" />',
            data: ['options' => TicketPriority::options()]
        );

        foreach (TicketPriority::cases() as $case) {
            $this->assertStringContainsString('value="'.$case->value.'"', $html);
            $this->assertStringContainsString($case->label(), $html);
        }

        $this->assertMatchesRegularExpression('/value="medium"[^>]*selected/', $html);
    }

    public function test_select_placeholder_is_disabled_so_it_cannot_be_submitted(): void
    {
        $html = $this->renderWithErrors(
            '<x-form.select name="location_id" label="Location" placeholder="Choose a location" />'
        );

        $this->assertMatchesRegularExpression('/<option value=""[^>]*disabled/', $html);
        $this->assertStringContainsString('Choose a location', $html);
    }

    public function test_textarea_renders_its_value_as_content_not_an_attribute(): void
    {
        $html = $this->renderWithErrors(
            '<x-form.textarea name="notes" label="Notes" value="Handover text" rows="6" />'
        );

        $this->assertStringContainsString('rows="6"', $html);
        $this->assertStringContainsString('>Handover text</textarea>', $html);
    }
}
