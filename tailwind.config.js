import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/**
 * Atlas NetCare design tokens.
 *
 * Every colour resolves through a CSS variable defined in resources/css/app.css,
 * once for the light theme and once for the dark. Class names therefore mean a
 * role, not a swatch: `bg-white` is "a panel", `text-slate-500` is "muted text",
 * and both follow the theme without a `dark:` variant anywhere.
 *
 * New code should prefer the semantic names (`bg-surface`, `border-line`,
 * `text-fg-muted`). The Tailwind hue names stay mapped so every existing class
 * keeps working and follows the theme too.
 *
 * Status hues (emerald, amber, orange, red) are for status only and are chosen
 * in exactly one place, app/Support/Tone.php. `brand` is the single accent, and
 * is none of them, so a button never reads as a status.
 *
 * `./app/**\/*.php` is scanned because Tone.php holds literal class strings.
 */
const scale = (hue) =>
    Object.fromEntries(
        [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].map((shade) => [
            shade,
            `rgb(var(--c-${hue}-${shade}) / <alpha-value>)`,
        ]),
    );

const token = (name) => `rgb(var(--${name}) / <alpha-value>)`;

export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './app/**/*.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"Geist Variable"', 'Geist', ...defaultTheme.fontFamily.sans],
                mono: ['"Geist Mono Variable"', '"Geist Mono"', ...defaultTheme.fontFamily.mono],
            },

            fontSize: {
                // One step below text-xs, for table headers and meta lines.
                '2xs': ['0.6875rem', { lineHeight: '1rem' }],
            },

            colors: {
                // A panel: white in light, the surface colour in dark.
                white: token('surface'),

                canvas: token('canvas'),
                surface: token('surface'),
                raised: token('raised'),
                sunken: token('sunken'),
                line: {
                    DEFAULT: token('line'),
                    strong: token('line-strong'),
                },
                fg: {
                    DEFAULT: token('fg'),
                    muted: token('fg-muted'),
                    subtle: token('fg-subtle'),
                },
                accent: {
                    DEFAULT: token('c-brand-600'),
                    fg: token('accent-fg'),
                    soft: token('c-brand-50'),
                },

                slate: scale('slate'),
                brand: scale('brand'),
                emerald: scale('emerald'),
                amber: scale('amber'),
                orange: scale('orange'),
                red: scale('red'),
            },

            // Text on a filled button is always white, in both themes.
            textColor: {
                white: '#ffffff',
            },

            borderRadius: {
                sm: '4px',
                DEFAULT: '6px',
                md: '8px',
                lg: '10px',
                xl: '14px',
                '2xl': '18px',
            },

            boxShadow: {
                xs: '0 1px 2px rgb(var(--shadow) / 0.06)',
                sm: '0 1px 2px rgb(var(--shadow) / 0.06), 0 1px 3px rgb(var(--shadow) / 0.05)',
                md: '0 4px 12px -2px rgb(var(--shadow) / 0.10), 0 2px 4px rgb(var(--shadow) / 0.05)',
                lg: '0 16px 40px -12px rgb(var(--shadow) / 0.28), 0 4px 10px rgb(var(--shadow) / 0.08)',
            },

            ringColor: {
                DEFAULT: token('c-brand-500'),
            },

            transitionTimingFunction: {
                out: 'cubic-bezier(0.16, 1, 0.3, 1)',
            },

            zIndex: {
                // One scale for the shell. Nothing else should need a z-index.
                sticky: '30',
                drawer: '40',
                popover: '50',
                modal: '60',
                toast: '70',
            },
        },
    },

    plugins: [forms],
};
