<?php

namespace App\Support;

/**
 * The single source of truth for what a tone looks like.
 *
 * Enums return a tone — 'healthy', 'attention', 'urgent', 'neutral' — and never
 * a colour. This class turns a tone into Tailwind classes. Change a colour here
 * and it changes in every badge, pill, banner and table at once.
 *
 * WHY THIS IS A PHP CLASS AND NOT config/atlas.php
 *
 * Tailwind scans source files for literal class names. A class assembled at
 * runtime from config ("bg-{$colour}-50") is never seen by the scanner, is
 * purged from the build, and renders unstyled with no error at all. So every
 * string below is written out in full, and `./app/**\/*.php` is listed in the
 * `content` array of tailwind.config.js so this file is scanned.
 *
 * WHY IT MOVED OUT OF badge.blade.php
 *
 * Stage 3a put the mapping in that component because a badge was the only
 * surface that needed it. Stage 3b added a second — the <x-alert> banner — and
 * a banner cannot reuse a badge's classes: it is a different size, has a border
 * and tints an icon. Two surfaces, one vocabulary, so the vocabulary moved
 * somewhere both can read it. <x-risk-gauge> in Stage 3c will be the third.
 *
 * ADDING A SURFACE: add a method here. Never write a match on $tone anywhere
 * else — that is how 'urgent' ends up as two different reds.
 *
 * @see docs/06-ui-conventions.md — Design tokens
 */
final class Tone
{
    public const HEALTHY = 'healthy';

    public const ATTENTION = 'attention';

    public const URGENT = 'urgent';

    public const NEUTRAL = 'neutral';

    /**
     * Every tone the design system knows about.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::HEALTHY, self::ATTENTION, self::URGENT, self::NEUTRAL];
    }

    /**
     * Small inline chip — <x-badge>, <x-status-badge>, <x-priority-pill>.
     */
    public static function badge(?string $tone): string
    {
        return match ($tone) {
            self::HEALTHY => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::ATTENTION => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            self::URGENT => 'bg-red-50 text-red-700 ring-red-600/20',
            self::NEUTRAL => 'bg-slate-50 text-slate-600 ring-slate-500/20',
            default => self::unknown(),
        };
    }

    /**
     * Full-width message surface — <x-alert>.
     *
     * Deliberately a heavier treatment than a badge: a flash message has to be
     * readable from across the room during a demo, a badge sits inside a table
     * row and must not shout.
     */
    public static function banner(?string $tone): string
    {
        return match ($tone) {
            self::HEALTHY => 'bg-emerald-50 text-emerald-900 ring-emerald-600/20',
            self::ATTENTION => 'bg-amber-50 text-amber-900 ring-amber-600/20',
            self::URGENT => 'bg-red-50 text-red-900 ring-red-600/20',
            self::NEUTRAL => 'bg-slate-50 text-slate-800 ring-slate-500/20',
            default => self::unknown(),
        };
    }

    /**
     * Foreground-only tint, for an icon or a rule sitting on a tinted surface.
     */
    public static function accent(?string $tone): string
    {
        return match ($tone) {
            self::HEALTHY => 'text-emerald-600',
            self::ATTENTION => 'text-amber-600',
            self::URGENT => 'text-red-600',
            self::NEUTRAL => 'text-slate-500',
            default => 'text-fuchsia-600',
        };
    }

    /**
     * Solid fill — <x-risk-gauge>, progress bars, anything where the colour is
     * the data rather than a tint behind text.
     *
     * The badge and banner surfaces are deliberately pale because text sits on
     * them. Nothing sits on a gauge fill, so it can carry full saturation.
     */
    public static function solid(?string $tone): string
    {
        return match ($tone) {
            self::HEALTHY => 'bg-emerald-500',
            self::ATTENTION => 'bg-amber-500',
            self::URGENT => 'bg-red-500',
            self::NEUTRAL => 'bg-slate-400',
            default => 'bg-fuchsia-500',
        };
    }

    /**
     * A selectable choice chip in its CHECKED state — <x-checklist-item>.
     *
     * Written out as literal `peer-checked:` strings rather than derived from
     * badge() with a prefix. Deriving them would build the class name at
     * runtime, Tailwind would never see it in a scanned file, and every
     * selected choice on the inspection form would render colourless.
     */
    public static function choice(?string $tone): string
    {
        return match ($tone) {
            self::HEALTHY => 'peer-checked:bg-emerald-50 peer-checked:text-emerald-700 peer-checked:ring-emerald-600',
            self::ATTENTION => 'peer-checked:bg-amber-50 peer-checked:text-amber-800 peer-checked:ring-amber-600',
            self::URGENT => 'peer-checked:bg-red-50 peer-checked:text-red-700 peer-checked:ring-red-600',
            self::NEUTRAL => 'peer-checked:bg-slate-100 peer-checked:text-slate-700 peer-checked:ring-slate-400',
            default => 'peer-checked:bg-fuchsia-100 peer-checked:text-fuchsia-900 peer-checked:ring-fuchsia-600',
        };
    }

    /**
     * The same four tones as a hex value, for Chart.js.
     *
     * A canvas cannot use a Tailwind class, so this is the one surface that
     * has to leave the class vocabulary. The values match the Tailwind 500
     * scale used by solid() — if one changes, change both, which is precisely
     * why they live next to each other rather than in a chart config
     * somewhere.
     */
    public static function chart(?string $tone): string
    {
        return match ($tone) {
            self::HEALTHY => '#10b981',   // emerald-500
            self::ATTENTION => '#f59e0b', // amber-500
            self::URGENT => '#ef4444',    // red-500
            self::NEUTRAL => '#94a3b8',   // slate-400
            default => '#d946ef',         // fuchsia-500
        };
    }

    /**
     * An unrecognised tone is a bug, not a styling choice.
     *
     * Rendering it fuchsia makes it impossible to miss on screen. Falling back
     * to grey would hide the mistake until someone noticed a status badge that
     * had quietly stopped meaning anything.
     */
    private static function unknown(): string
    {
        return 'bg-fuchsia-100 text-fuchsia-900 ring-fuchsia-600/40';
    }
}
