<!doctype html>
<html lang="en-GB">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>DevStep</title>
    <link rel="manifest" href="/manifest.webmanifest">
    <meta name="theme-color" content="#1f4fd8">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="DevStep">
    <link rel="icon" type="image/png" sizes="192x192" href="/icons/icon-192.png">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <meta name="description" content="A guided practice companion for working developers: know what to practise next, start with the time you have, and see evidence that you are improving.">
    <script>
        // Apply the saved theme before first paint so there is no flash (A-14).
        try { var t = localStorage.getItem('devstep.theme'); if (t === 'light' || t === 'dark') document.documentElement.dataset.theme = t; } catch (e) {}
    </script>
    @vite(['resources/css/app.css', 'resources/js/main.tsx'])
    <script>window.__DEVSTEP__ = @json(['pilotFormUrl' => config('devstep.pilot_form_url'), 'appVersion' => config('app.version')]);</script>
</head>
<body>
    <div id="root"></div>
    <noscript>DevStep needs JavaScript to run the practice player.</noscript>
</body>
</html>
