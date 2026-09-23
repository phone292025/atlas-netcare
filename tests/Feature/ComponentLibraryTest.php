<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Tone;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The shared component library. Every screen is built from these, so a fault
 * here is a fault on every page at once.
 *
 * WHAT THESE TESTS CANNOT DO: they assert on rendered HTML, so they cannot
 * evaluate CSS; a sidebar once sat off-screen on every desktop with the suite
 * green. Where a class name is load-bearing these tests pin it, and the
 * components are still checked in a real browser.
 *
 * @see docs/06-ui-conventions.md — Blade components
 */
class ComponentLibraryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The layout owns the page's only <h1>. A second one here would leave a
     * screen reader with no way to tell which heading is the page.
     */
    public function test_page_header_renders_an_h2_not_an_h1(): void
    {
        $html = Blade::render('<x-page-header title="Ticket queue" />');

        $this->assertStringContainsString('<h2', $html);
        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringContainsString('Ticket queue', $html);
    }

    public function test_page_header_renders_a_subtitle_and_actions(): void
    {
        $html = Blade::render(
            '<x-page-header title="Ticket queue" subtitle="18 open, 3 breaching">'
            .'<x-slot:actions><a href="/new">Raise ticket</a></x-slot:actions>'
            .'</x-page-header>'
        );

        $this->assertStringContainsString('18 open, 3 breaching', $html);
        $this->assertStringContainsString('Raise ticket', $html);
    }

    public function test_page_header_omits_the_subtitle_block_when_there_is_none(): void
    {
        $this->assertStringNotContainsString('<p', Blade::render('<x-page-header title="Users" />'));
    }

    public function test_card_renders_a_title_and_a_footer(): void
    {
        $html = Blade::render(
            '<x-card title="Access point health">Body text'
            .'<x-slot:footer><a href="/aps">View all</a></x-slot:footer>'
            .'</x-card>'
        );

        $this->assertStringContainsString('Access point health', $html);
        $this->assertStringContainsString('Body text', $html);
        $this->assertStringContainsString('View all', $html);
    }

    /**
     * A table inside a card would otherwise be inset twice — the card's p-6
     * plus the table's own cell padding.
     */
    public function test_card_flush_drops_the_body_padding(): void
    {
        $this->assertStringContainsString('p-6', Blade::render('<x-card>rows</x-card>'));
        $this->assertStringNotContainsString('p-6', Blade::render('<x-card flush>rows</x-card>'));
    }

    public function test_empty_state_renders_its_message_and_action(): void
    {
        $html = Blade::render(
            '<x-empty-state title="No tickets yet" message="Tickets appear here." icon="ticket">'
            .'<x-slot:action><a href="/new">Raise the first ticket</a></x-slot:action>'
            .'</x-empty-state>'
        );

        $this->assertStringContainsString('No tickets yet', $html);
        $this->assertStringContainsString('Tickets appear here.', $html);
        $this->assertStringContainsString('Raise the first ticket', $html);
    }

    /**
     * The alert must not carry its own colour table. It reads the same one the
     * badges read, so a palette change moves both together.
     */
    public function test_alert_takes_its_colour_from_the_shared_tone_source(): void
    {
        $expected = [
            'success' => Tone::HEALTHY,
            'warning' => Tone::ATTENTION,
            'error' => Tone::URGENT,
        ];

        foreach ($expected as $type => $tone) {
            $html = Blade::render('<x-alert type="'.$type.'">Message</x-alert>');
            $marker = explode(' ', Tone::banner($tone))[0];

            $this->assertStringContainsString($marker, $html, "alert type={$type} did not use the {$tone} banner");
            $this->assertStringContainsString('role="alert"', $html);
        }
    }

    public function test_alert_makes_an_unrecognised_type_visible(): void
    {
        $this->assertStringContainsString('fuchsia', Blade::render('<x-alert type="informational">Message</x-alert>'));
    }

    public function test_alert_is_only_dismissible_when_asked(): void
    {
        $this->assertStringNotContainsString('Dismiss message', Blade::render('<x-alert type="success">Saved</x-alert>'));
        $this->assertStringContainsString('Dismiss message', Blade::render('<x-alert type="success" dismissible>Saved</x-alert>'));
    }

    public function test_modal_renders_dialog_semantics_and_answers_to_its_name(): void
    {
        $html = Blade::render(
            '<x-modal name="confirm-deactivate" title="Deactivate this account?">They will be signed out.'
            .'<x-slot:footer><button>Deactivate</button></x-slot:footer>'
            .'</x-modal>'
        );

        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString("'confirm-deactivate'", $html);
        $this->assertStringContainsString('aria-labelledby="modal-title-confirm-deactivate"', $html);
        $this->assertStringContainsString('Deactivate this account?', $html);
        $this->assertStringContainsString('They will be signed out.', $html);
    }

    public function test_data_table_renders_its_headers_and_rows(): void
    {
        $html = Blade::render(
            '<x-data-table :headers="$headers"><tr><td>TK-1042</td><td>118</td></tr></x-data-table>',
            ['headers' => ['Reference', ['label' => 'Latency', 'align' => 'right']]]
        );

        $this->assertStringContainsString('Reference', $html);
        $this->assertStringContainsString('text-right', $html);
        $this->assertStringContainsString('TK-1042', $html);
    }

    /**
     * ComponentSlot::isEmpty() compares against '' exactly, and a foreach over
     * an empty collection still emits the whitespace between its tags. The slot
     * looks blank on screen but is not an empty string, so the component trims
     * before deciding — without that, an empty list renders a table header
     * sitting above nothing.
     */
    public function test_data_table_shows_the_empty_slot_when_the_rows_are_only_whitespace(): void
    {
        $html = Blade::render(
            '<x-data-table :headers="[\'Reference\']">'
            ."\n    \n    \n"
            .'<x-slot:empty><p>No tickets match these filters</p></x-slot:empty>'
            .'</x-data-table>'
        );

        $this->assertStringContainsString('No tickets match these filters', $html);
        $this->assertStringNotContainsString('<table', $html);
    }

    public function test_data_table_falls_back_to_an_honest_empty_state_without_a_slot(): void
    {
        $html = Blade::render('<x-data-table :headers="[\'Reference\']"></x-data-table>');

        $this->assertStringContainsString('Nothing to show yet', $html);
    }

    /**
     * The page body must never scroll sideways — the scroll belongs to the
     * table's own container. NFR-05 and the responsive rules both depend on it.
     */
    public function test_data_table_keeps_horizontal_scroll_in_its_own_container(): void
    {
        $html = Blade::render('<x-data-table :headers="[\'A\']"><tr><td>1</td></tr></x-data-table>');

        $this->assertStringContainsString('overflow-x-auto', $html);
    }

    /**
     * Sorting is a link so it survives a refresh and a shared URL. Resetting
     * `page` matters: without it, re-sorting from page 7 lands on page 7 of a
     * completely different ordering.
     */
    public function test_data_table_sort_link_toggles_direction_and_resets_the_page(): void
    {
        Route::middleware('web')->get('/sortable', fn () => Blade::render(
            '<x-data-table :headers="$headers"><tr><td>row</td></tr></x-data-table>',
            ['headers' => [['label' => 'Priority', 'sort' => 'priority']]]
        ));

        $this->get('/sortable?sort=priority&direction=asc&page=7')
            ->assertSee('direction=desc', false)
            ->assertDontSee('page=7', false)
            ->assertSee('aria-sort="ascending"', false);

        $this->get('/sortable?sort=priority&direction=desc')
            ->assertSee('direction=asc', false)
            ->assertSee('aria-sort="descending"', false);
    }

    public function test_pagination_renders_nothing_when_everything_fits_on_one_page(): void
    {
        $html = Blade::render('<x-pagination :paginator="$paginator" />', [
            'paginator' => $this->paginator(total: 4, perPage: 25, page: 1),
        ]);

        $this->assertSame('', trim($html));
    }

    public function test_pagination_shows_the_range_and_marks_the_current_page(): void
    {
        $html = Blade::render('<x-pagination :paginator="$paginator" />', [
            'paginator' => $this->paginator(total: 57, perPage: 25, page: 2),
        ]);

        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('26', $html);   // firstItem
        $this->assertStringContainsString('50', $html);   // lastItem
        $this->assertStringContainsString('57', $html);   // total
        $this->assertStringContainsString('rel="prev"', $html);
        $this->assertStringContainsString('rel="next"', $html);
    }

    public function test_pagination_disables_previous_on_the_first_page(): void
    {
        $html = Blade::render('<x-pagination :paginator="$paginator" />', [
            'paginator' => $this->paginator(total: 57, perPage: 25, page: 1),
        ]);

        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertStringNotContainsString('rel="prev"', $html);
    }

    /**
     * Forty pages of numbers is not navigation, it is wallpaper.
     */
    public function test_pagination_windows_a_long_page_list_with_an_ellipsis(): void
    {
        $html = Blade::render('<x-pagination :paginator="$paginator" />', [
            'paginator' => $this->paginator(total: 1000, perPage: 25, page: 20),
        ]);

        $this->assertStringContainsString('&hellip;', $html);
        $this->assertStringContainsString('Go to page 1"', $html);
        $this->assertStringContainsString('Go to page 40"', $html);
        $this->assertStringNotContainsString('Go to page 10"', $html);
    }

    /**
     * The shell's flash messages go through the same component, so a success
     * banner on one module's screen is the same green as on another's.
     */
    public function test_the_shell_renders_a_flash_message_through_the_alert_component(): void
    {
        $user = User::factory()->create();
        $marker = explode(' ', Tone::banner(Tone::URGENT))[0];

        $this->actingAs($user)
            ->withSession(['error' => 'Asset tag APU-1042 is already in use.'])
            ->get(route('profile.edit'))
            ->assertSee('Asset tag APU-1042 is already in use.')
            ->assertSee($marker, false);
    }

    private function paginator(int $total, int $perPage, int $page): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            new Collection(range(1, min($perPage, $total))),
            $total,
            $perPage,
            $page,
            ['path' => 'http://localhost/tickets'],
        );
    }
}
