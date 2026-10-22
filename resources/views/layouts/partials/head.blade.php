{{--
    Everything both layouts put in <head>.

    The inline script runs before first paint and sets data-theme to "light" or
    "dark": the viewer's own choice if they made one, otherwise their device's
    setting. Without it a dark-mode viewer sees a white flash on every page.
    The choice lives in this browser only; the server never knows it.

    The error pages pass `bare`: they can render before a session exists, so
    they skip the CSRF token, which has nowhere to come from.
--}}
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
@unless ($bare ?? false)
    <meta name="csrf-token" content="{{ csrf_token() }}">
@endunless
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f7f9" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">

<title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>

<link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">

<script>
    (() => {
        let choice = 'system';
        try {
            choice = localStorage.getItem('atlas-theme') || 'system';
        } catch (e) {}
        const dark = choice === 'dark'
            || (choice === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
        document.documentElement.dataset.theme = dark ? 'dark' : 'light';
    })();
</script>

@vite(['resources/css/app.css', 'resources/js/app.js'])
