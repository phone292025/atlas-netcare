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
 * @see docs/08-ui-conventions.md — Design tokens
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
