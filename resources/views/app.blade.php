<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="{{ $shareDescription ?? __('Gjej portier, kontakto direkt në WhatsApp ose telefon dhe paguaj në dorë në fushë.') }}">
        @isset($shareUrl)
            <link rel="canonical" href="{{ $shareUrl }}">
            <meta property="og:type" content="profile">
            <meta property="og:url" content="{{ $shareUrl }}">
            <meta property="og:title" content="{{ $shareTitle }}">
            <meta property="og:description" content="{{ $shareDescription }}">
            <meta property="og:image" content="{{ $shareImage }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $shareTitle }}">
            <meta name="twitter:description" content="{{ $shareDescription }}">
            <meta name="twitter:image" content="{{ $shareImage }}">
        @endisset
        <title inertia>{{ $shareTitle ?? 'Gjej portier' }}</title>
        <link rel="icon" type="image/svg+xml" href="/brand/gjej-portier-symbol-dark.svg">
        @viteReactRefresh
        @vite('resources/js/app.tsx')
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
