<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Decides, per full page request, whether the Cloudflare Web Analytics beacon may run.
 *
 * Only guests on an allowlist of public, non-sensitive GET routes are eligible. Requests with
 * a query string, a Do Not Track / Global Privacy Control signal, or a prefetch purpose are not.
 * The browser re-checks these conditions before injecting the beacon (resources/js/lib/cloudflareAnalytics.ts).
 */
class CloudflareWebAnalytics
{
    /** Public marketing/information routes whose URLs never contain personal data. */
    public const PUBLIC_ROUTES = ['home', 'browse', 'how-it-works', 'faq', 'contact', 'privacy', 'terms'];

    /** Public goalkeeper profiles. Their URLs carry a name-based slug or a profile id, so they are opt-in. */
    public const PROFILE_ROUTES = ['keepers.public', 'keepers.show'];

    public static function tokenFor(Request $request): ?string
    {
        $config = (array) config('services.cloudflare_web_analytics', []);
        $token = trim((string) ($config['token'] ?? ''));

        if (! filter_var($config['enabled'] ?? false, FILTER_VALIDATE_BOOL) || ! preg_match('/^[a-f0-9]{32}$/', $token)) {
            return null;
        }

        return self::isTrackable($request, filter_var($config['track_public_profiles'] ?? false, FILTER_VALIDATE_BOOL)) ? $token : null;
    }

    public static function isTrackable(Request $request, bool $includeProfiles = false): bool
    {
        if (! $request->isMethod('GET')
            || (string) $request->server('QUERY_STRING', '') !== ''
            || $request->user() !== null
            || $request->header('DNT') === '1'
            || $request->header('Sec-GPC') === '1'
            || self::isPrefetch($request)) {
            return false;
        }

        $routes = $includeProfiles ? [...self::PUBLIC_ROUTES, ...self::PROFILE_ROUTES] : self::PUBLIC_ROUTES;

        return in_array($request->route()?->getName(), $routes, true);
    }

    private static function isPrefetch(Request $request): bool
    {
        $purpose = strtolower((string) $request->header('Purpose').' '.(string) $request->header('Sec-Purpose'));

        return str_contains($purpose, 'prefetch')
            || str_contains($purpose, 'prerender')
            || $request->header('X-Inertia-Prefetch') === 'true';
    }
}
