<?php

namespace Tests\Unit;

use App\Support\Tone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stage 3c added three more surfaces to Tone: solid(), choice() and chart().
 *
 * The risk that grows with every surface is drift — someone restyles one and
 * 'urgent' quietly becomes two different reds. ToneTest pins that property for
 * the class-based surfaces; this pins it for the three added in 3c, including
 * chart(), which is the only surface that leaves the Tailwind vocabulary
 * entirely and so cannot be checked the same way.
 *
 * @see docs/06-ui-conventions.md — Design tokens
 */
class ToneSurfacesTest extends TestCase
{
    /** @return array<int, array{string}> */
    public static function tones(): array
    {
        return array_map(fn ($tone) => [$tone], Tone::all());
    }

    #[DataProvider('tones')]
    public function test_every_tone_has_a_solid_fill_and_a_choice_state(string $tone): void
    {
        $this->assertNotSame('', trim(Tone::solid($tone)));
        $this->assertNotSame('', trim(Tone::choice($tone)));
    }

    #[DataProvider('tones')]
    public function test_solid_and_choice_agree_with_the_badge_colour_family(string $tone): void
    {
        $family = fn (string $classes) => preg_match('/-([a-z]+)-\d{2,3}/', $classes, $m) ? $m[1] : null;

        $this->assertSame(
            $family(Tone::badge($tone)),
            $family(Tone::solid($tone)),
            "solid() uses a different colour family from badge() for {$tone}"
        );

        $this->assertSame(
            $family(Tone::badge($tone)),
            $family(Tone::choice($tone)),
            "choice() uses a different colour family from badge() for {$tone}"
        );
    }

    #[DataProvider('tones')]
    public function test_choice_classes_are_all_peer_checked_variants(string $tone): void
    {
        // A class without the variant would apply unconditionally and every
        // choice on the inspection form would look selected at once.
        foreach (preg_split('/\s+/', trim(Tone::choice($tone))) as $class) {
            $this->assertStringStartsWith('peer-checked:', $class, "'{$class}' is not a checked-state class");
        }
    }

    #[DataProvider('tones')]
    public function test_chart_returns_a_hex_colour_not_a_class(string $tone): void
    {
        // A canvas cannot use a Tailwind class. If this ever returns one, every
        // chart in the system draws in the browser default colour instead.
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', Tone::chart($tone));
    }

    public function test_unknown_tones_are_visibly_wrong_on_every_new_surface(): void
    {
        $this->assertStringContainsString('fuchsia', Tone::solid('nonsense'));
        $this->assertStringContainsString('fuchsia', Tone::choice('nonsense'));
        $this->assertSame('#d946ef', Tone::chart('nonsense'));
    }

    public function test_the_source_never_assembles_a_class_at_runtime(): void
    {
        // Tailwind only generates classes it can see written out in full. A
        // class assembled at runtime is purged and renders as nothing, with no
        // error anywhere. This is the trap Stage 3c walked into once already.
        //
        // Comments are stripped before scanning: the docblocks in Tone.php
        // quote "bg-{$colour}-50" as the example of what NOT to do, and a naive
        // scan flags the warning as the offence.
        $code = '';

        foreach (token_get_all(file_get_contents(dirname(__DIR__, 2).'/app/Support/Tone.php')) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], strict: true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        $this->assertStringNotContainsString('{$', $code, 'A class is being interpolated at runtime.');
        $this->assertStringNotContainsString('str_replace', $code, 'A class is being assembled at runtime.');
        $this->assertStringNotContainsString('sprintf', $code, 'A class is being assembled at runtime.');
    }
}
