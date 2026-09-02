<?php

namespace Tests\Unit;

use App\Support\Tone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tone is the one file that decides what a status colour looks like anywhere in
 * the system. Stage 3b added a second surface to it, which is exactly when a
 * mapping starts drifting: someone restyles the badge, forgets the banner, and
 * 'urgent' becomes two different reds that nobody notices until a marker does.
 *
 * These tests pin the property that matters — every surface agrees on the
 * colour family for a given tone — rather than pinning the hex values, so a
 * deliberate palette change stays a one-line edit.
 *
 * @see docs/06-ui-conventions.md — Design tokens
 */
class ToneTest extends TestCase
{
    public static function tones(): array
    {
        return array_map(fn ($tone) => [$tone], Tone::all());
    }

    public function test_it_knows_the_four_documented_tones(): void
    {
        $this->assertSame(['healthy', 'attention', 'urgent', 'neutral'], Tone::all());
    }

    #[DataProvider('tones')]
    public function test_every_tone_has_classes_for_every_surface(string $tone): void
    {
        foreach (['badge', 'banner', 'accent'] as $surface) {
            $this->assertNotSame('', trim(Tone::$surface($tone)), "{$surface}() returned nothing for {$tone}");
        }
    }

    /**
     * The regression this whole class exists to prevent.
     */
    #[DataProvider('tones')]
    public function test_badge_banner_and_accent_agree_on_the_colour_family(string $tone): void
    {
        $family = fn (string $classes) => preg_match('/-([a-z]+)-\d{2,3}/', $classes, $m) ? $m[1] : null;

        $badge = $family(Tone::badge($tone));

        $this->assertNotNull($badge, "could not read a colour family out of badge() for {$tone}");
        $this->assertSame($badge, $family(Tone::banner($tone)), "banner() disagrees with badge() about {$tone}");
        $this->assertSame($badge, $family(Tone::accent($tone)), "accent() disagrees with badge() about {$tone}");
    }

    /**
     * An unknown tone must be loud. Falling back to grey would hide a broken
     * status badge until someone happened to look closely at it.
     */
    public function test_an_unknown_tone_renders_visibly_wrong(): void
    {
        foreach (['badge', 'banner'] as $surface) {
            $this->assertStringContainsString('fuchsia', Tone::$surface('not-a-tone'));
            $this->assertStringContainsString('fuchsia', Tone::$surface(null));
        }

        $this->assertStringContainsString('fuchsia', Tone::accent('not-a-tone'));
    }

    /**
     * Tailwind scans source for literal class names. A class with a variable in
     * it is generated as nothing at all and renders unstyled, with no error.
     */
    #[DataProvider('tones')]
    public function test_no_surface_builds_a_class_from_a_variable(string $tone): void
    {
        foreach (['badge', 'banner', 'accent'] as $surface) {
            $classes = Tone::$surface($tone);

            $this->assertStringNotContainsString('{', $classes);
            $this->assertStringNotContainsString('$', $classes);
        }
    }
}
