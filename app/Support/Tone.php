<?php

namespace App\Support;

/**
 * The single source of truth for what a tone looks like.
 *
 * Enums return a tone ('healthy', 'attention', 'elevated', 'urgent',
 * 'neutral'), never a colour, and this class turns it into Tailwind classes,
 * one method per surface. Change a colour here and it changes everywhere.
 *
 * WHY A PHP CLASS AND NOT CONFIG. Tailwind scans source for literal class
 * names. A class built at runtime ("bg-{$colour}-50") is never seen, is
 * purged from the build, and renders unstyled with no error. So every string
 * below is written out in full, and `./app/**\/*.php` is in the `content`
 * array of tailwind.config.js so this file is scanned.
 *
 * ADDING A SURFACE: add a method here. Never write a match on a tone anywhere
 * else; that is how 'urgent' ends up as two different reds.
 *
 * @see docs/06-ui-conventions.md — Design tokens
 */
final class Tone
{
    public const HEALTHY = 'healthy';

    public const ATTENTION = 'attention';

    /**
     * Worse than ATTENTION, not yet URGENT, so the four risk bands get four
     * colours rather than two ambers.
     */
    public const ELEVATED = 'elevated';

    public const URGENT = 'urgent';

    public const NEUTRAL = 'neutral';

    /**
     * Every tone the design system knows about.
     *
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::HEALTHY, self::ATTENTION, self::ELEVATED, self::URGENT, self::NEUTRAL];
    }

    /**
     * Small inline chip — <x-badge>, <x-status-badge>, <x-priority-pill>.
     */
    public static function badge(?string $tone): string
    {
        return match ($tone) {
            self::HEALTHY => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            self::ATTENTION => 'bg-amber-50 text-amber-800 ring-amber-600/20',
            self::ELEVATED => 'bg-orange-50 text-orange-800 ring-orange-600/20',
            self::URGENT => 'bg-red-50 text-red-700 ring-red-600/20',
            self::NEUTRAL => 'bg-slate-50 text-slate-600 ring-slate-500/20',
            default => self::unknown(),
        };
    }

    /**
     * Full-width message surface — <x-alert>. Heavier than a badge: a flash
     * message has to be read at a glance, a badge in a table row must not
     * shout.
     */
    public static function banner(?string $tone): string
    {
        return match ($tone) {
            self::HEALTHY => 'bg-emerald-50 text-emerald-900 ring-emerald-600/20',
            self::ATTENTION => 'bg-amber-50 text-amber-900 ring-amber-600/20',
            self::ELEVATED => 'bg-orange-50 text-orange-900 ring-orange-600/20',
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
            self::ELEVATED => 'text-orange-600',
            self::URGENT => 'text-red-600',
            self::NEUTRAL => 'text-slate-500',
            default => 'text-fuchsia-600',
        };
    }

    /**
     * Solid fill — <x-risk-gauge>, progress bars, anything where the colour is
     * the data. Full saturation, since no text sits on it.
     */
    public static function solid(?string $tone): string
    {
        return match ($tone) {
            self::HEALTHY => 'bg-emerald-500',
            self::ATTENTION => 'bg-amber-500',
            self::ELEVATED => 'bg-orange-500',
            self::URGENT => 'bg-red-500',
            self::NEUTRAL => 'bg-slate-400',
            default => 'bg-fuchsia-500',
        };
    }

    /**
     * A selectable choice chip in its CHECKED state — <x-checklist-item>.
     * Literal `peer-checked:` strings, not badge() with a prefix added at
     * runtime, which Tailwind would never see.
     */
    public static function choice(?string $tone): string
    {
        return match ($tone) {
            self::HEALTHY => 'peer-checked:bg-emerald-50 peer-checked:text-emerald-700 peer-checked:ring-emerald-600',
            self::ATTENTION => 'peer-checked:bg-amber-50 peer-checked:text-amber-800 peer-checked:ring-amber-600',
            self::ELEVATED => 'peer-checked:bg-orange-50 peer-checked:text-orange-800 peer-checked:ring-orange-600',
            self::URGENT => 'peer-checked:bg-red-50 peer-checked:text-red-700 peer-checked:ring-red-600',
            self::NEUTRAL => 'peer-checked:bg-slate-100 peer-checked:text-slate-700 peer-checked:ring-slate-400',
            default => 'peer-checked:bg-fuchsia-100 peer-checked:text-fuchsia-900 peer-checked:ring-fuchsia-600',
        };
    }

    /**
     * The tones as hex values, for Chart.js: a canvas cannot use a Tailwind
     * class. They match the scale solid() uses, and live beside it so both
     * change together.
     */
    public static function chart(?string $tone): string
    {
        return match ($tone) {
            self::HEALTHY => '#10b981',   // emerald-500
            self::ATTENTION => '#f59e0b', // amber-500
            self::ELEVATED => '#f97316',  // orange-500
            self::URGENT => '#ef4444',    // red-500
            self::NEUTRAL => '#94a3b8',   // slate-400
            default => '#d946ef',         // fuchsia-500
        };
    }

    /**
     * An unrecognised tone is a bug, so it renders fuchsia, impossible to miss,
     * rather than a grey that hides the mistake.
     */
    private static function unknown(): string
    {
        return 'bg-fuchsia-100 text-fuchsia-900 ring-fuchsia-600/40';
    }
}
