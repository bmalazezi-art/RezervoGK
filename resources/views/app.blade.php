<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="description" content="{{ $shareDescription ?? __('Gjej portier, kontakto drejtpërdrejt me WhatsApp ose telefon për ndeshjen tënde.') }}">
        <link rel="canonical" href="{{ $shareUrl ?? url()->current() }}">
        <meta property="og:site_name" content="Gjej portier">
        <meta property="og:type" content="{{ isset($shareUrl) ? 'profile' : 'website' }}">
        <meta property="og:url" content="{{ $shareUrl ?? url()->current() }}">
        <meta property="og:title" content="{{ $shareTitle ?? 'Gjej portier' }}">
        <meta property="og:description" content="{{ $shareDescription ?? __('Gjej portier, kontakto drejtpërdrejt me WhatsApp ose telefon për ndeshjen tënde.') }}">
        <meta property="og:image" content="{{ $shareImage ?? url('/brand/og-image.png') }}">
        @unless(isset($shareImage))
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
        @endunless
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $shareTitle ?? 'Gjej portier' }}">
        <meta name="twitter:description" content="{{ $shareDescription ?? __('Gjej portier, kontakto drejtpërdrejt me WhatsApp ose telefon për ndeshjen tënde.') }}">
        <meta name="twitter:image" content="{{ $shareImage ?? url('/brand/og-image.png') }}">
        <title inertia>{{ $shareTitle ?? 'Gjej portier' }}</title>
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" type="image/svg+xml" href="/brand/gjej-portier-app-icon.svg">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="theme-color" content="#0a2421">
        @php($cloudflareAnalyticsToken = \App\Support\CloudflareWebAnalytics::tokenFor(request()))
        @if($cloudflareAnalyticsToken)
            <meta name="cf-web-analytics-token" content="{{ $cloudflareAnalyticsToken }}">
        @endif
        @viteReactRefresh
        @vite('resources/js/app.tsx')
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
