<?php

namespace App\Enums\Contracts;

/**
 * A status that carries semantic colour.
 *
 * Enums return a tone, never a Tailwind class. <x-status-badge> maps tone to
 * classes in exactly one place, which is what keeps `critical` the same red in
 * a table, a chart and a gauge.
 *
 * Valid tones: healthy, attention, urgent, inactive.
 *
 * @see docs/08-ui-conventions.md — Design tokens
 */
interface HasTone
{
    public function tone(): string;
}
