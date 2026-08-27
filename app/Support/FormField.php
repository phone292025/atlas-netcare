<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Shared derivations for form controls.
 *
 * Four Blade files need to agree on these: <x-form.field> renders the label and
 * the error message, and the three controls render the input the label points
 * at. If any two of them derived an id differently, the label would stop
 * focusing its control and the error would stop being announced with it — both
 * silent failures that only a screen reader or a careful tap on a phone would
 * catch.
 *
 * So the derivations live here, once.
 *
 * @see resources/views/components/form/field.blade.php
 * @see docs/03-requirements.md NFR-10
 */
final class FormField
{
    /**
     * A DOM id safe for `for` / `id` and for aria references.
     *
     * `items[0][result]` becomes `items-0-result`.
     */
    public static function id(string $name): string
    {
        return Str::slug(str_replace(['[]', '[', ']'], ['', '-', ''], $name), '-');
    }

    /**
     * The key Laravel validates a control under.
     *
     * `items[0][result]` becomes `items.0.result`. Callers should never have to
     * think about this: they name the input the way HTML wants it and the error
     * is found the way the validator stored it.
     */
    public static function errorKey(string $name): string
    {
        return str_replace(['[]', '[', ']'], ['', '.', ''], $name);
    }
}
