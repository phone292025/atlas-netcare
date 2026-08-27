import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Atlas NetCare design tokens.
 *
 * Semantic status colour is deliberately NOT defined here. The four tones map
 * to Tailwind's own emerald / amber / red / slate scales, and the mapping lives
 * in exactly one place: app/Support/Tone.php.
 *
 * It has to be a literal class string, because Tailwind scans source for class
 * names — a class assembled at runtime from config would be purged from the
 * build and silently render unstyled. That is also why `./app/**\/*.php` is in
 * the content array below: without it, Tone.php is never scanned and every
 * badge and alert in the system renders colourless.
 *
 * `brand` is the accent for links, primary buttons and active navigation. It is
 * deliberately none of the four status hues, so a coloured button never reads
 * as a status.
 *
 * @see docs/08-ui-conventions.md
 */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './app/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    50: '#eef2ff',
                    100: '#e0e7ff',
                    200: '#c7d2fe',
                    300: '#a5b4fc',
                    400: '#818cf8',
                    500: '#6366f1',
                    600: '#4f46e5',
                    700: '#4338ca',
                    800: '#3730a3',
                    900: '#312e81',
                },
            },
        },
    },

    plugins: [forms],
};
