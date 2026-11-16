/**
 * Cloudflare Web Analytics loader for Inertia.
 *
 * The server (App\Support\CloudflareWebAnalytics) only renders the `cf-web-analytics-token` meta tag on full
 * page loads of public, non-sensitive routes for guests. This module then:
 *  - re-checks Do Not Track / Global Privacy Control, the landing URL and the referrer in the browser;
 *  - injects the beacon at most once per document (Inertia navigations never re-run it);
 *  - turns any Inertia GET navigation that would leave the tracked pages (private routes, query strings)
 *    into a full page load, so the beacon is never present while such a URL is in the address bar;
 *  - stops (full reload, so the server drops the beacon) as soon as the visitor signs in in another tab.
 */

export const BEACON_SRC = "https://static.cloudflareinsights.com/beacon.min.js";
export const BEACON_SCRIPT_ID = "cf-web-analytics-beacon";
export const TOKEN_META_NAME = "cf-web-analytics-token";
/** localStorage key holding the time an authenticated page was last rendered in any tab. Contains no personal data. */
export const AUTH_SIGNAL_KEY = "gjejportier:auth-signal";

/** Paths that may be visited in place once the beacon runs. Keep in sync with CloudflareWebAnalytics::PUBLIC_ROUTES. */
export const TRACKED_PATHS: readonly string[] = ["/", "/browse", "/how-it-works", "/faq", "/contact", "/privacy", "/terms"];

const TOKEN_PATTERN = /^[a-f0-9]{32}$/;

export type PrivacyNavigator = { doNotTrack?: string | null; globalPrivacyControl?: boolean; msDoNotTrack?: string | null };
export type PrivacyWindow = { doNotTrack?: string | null };
export type VisitLike = { url: URL; method: string; prefetch?: boolean };
export type AnalyticsRouter = {
  onBefore: (callback: (visit: VisitLike) => boolean | void) => unknown;
  onNavigate: (callback: (page: unknown) => void) => unknown;
  /**
   * Inertia "beforeUpdate": fires with every server page response (including `replace: true` visits, which skip
   * "navigate") before Inertia writes it to history, so before the beacon can see the navigation.
   */
  onBeforeUpdate: (callback: (page: unknown) => void) => unknown;
};
export type SignalStorage = { getItem(key: string): string | null; setItem(key: string, value: string): void };
/** The parts of the Navigation API (window.navigation, Chromium) used here. */
export type NavigateEventLike = { destination?: { url?: string; sameDocument?: boolean }; stopImmediatePropagation(): void };
export type NavigationLike = { addEventListener(type: "navigate", listener: (event: NavigateEventLike) => void): void };

function toUrl(url: URL | string, origin: string): URL | null {
  try { return typeof url === "string" ? new URL(url, origin) : url; } catch { return null; }
}

/** Same-origin, no query string, and one of the tracked public paths. */
export function isTrackableUrl(url: URL | string, origin: string): boolean {
  const u = toUrl(url, origin);
  return !!u && u.origin === origin && u.search === "" && TRACKED_PATHS.includes(u.pathname);
}

export function hasPrivacySignal(nav: PrivacyNavigator, win: PrivacyWindow = {}): boolean {
  const dnt = nav.doNotTrack ?? win.doNotTrack ?? nav.msDoNotTrack;
  return nav.globalPrivacyControl === true || dnt === "1" || dnt === "yes";
}

/**
 * The beacon reports document.referrer, which cannot be rewritten, so only start when it is privacy-safe:
 * empty, an origin-only external referrer (no path, query or credentials), or one of our own tracked public pages.
 */
export function isSafeReferrer(referrer: string, origin: string): boolean {
  if (!referrer) return true;
  const u = toUrl(referrer, origin);
  if (!u || u.search !== "" || u.hash !== "" || u.username !== "" || u.password !== "") return false;
  if (u.origin !== origin) return u.pathname === "/";
  return isTrackableUrl(u, origin);
}

/** True when the Inertia page props show a signed-in user. */
export function isAuthenticatedPage(page: unknown): boolean {
  const props = (page as { props?: { auth?: { user?: unknown } } } | null | undefined)?.props;
  return !!props?.auth?.user;
}

function browserStorage(): SignalStorage | null {
  try { return typeof window !== "undefined" ? window.localStorage : null; } catch { return null; }
}

/** Every tab records when it renders an authenticated page, so tracked tabs can notice a sign-in elsewhere. */
export function recordAuthSignal(page: unknown, storage: SignalStorage | null = browserStorage(), now: number = Date.now()): void {
  if (!storage || !isAuthenticatedPage(page)) return;
  try { storage.setItem(AUTH_SIGNAL_KEY, String(now)); } catch { /* storage full or blocked: nothing to record */ }
}

/** Fails closed: unreadable storage counts as "signed in", so analytics stops rather than risk tracking. */
export function signedInSince(since: number, storage: SignalStorage | null): boolean {
  if (!storage) return true;
  try {
    const value = Number(storage.getItem(AUTH_SIGNAL_KEY));
    return Number.isFinite(value) && value >= since;
  } catch { return true; }
}

export function watchAuthSignal(router: AnalyticsRouter, storage: SignalStorage | null = browserStorage()): void {
  router.onNavigate((page) => recordAuthSignal(page, storage));
  // The sign-in, registration and admin-code forms submit with `replace: true`, which skips "navigate". Recording
  // at "beforeUpdate" also catches a signed-in response to a tracked tab's own visit (a sign-in in another tab that
  // has not written its signal yet) before that page reaches history and the beacon.
  router.onBeforeUpdate((page) => recordAuthSignal(page, storage));
}

export function shouldLoadBeacon(input: {
  token: string; origin: string; currentUrl: string; landingUrl: string; referrer: string;
  navigator: PrivacyNavigator; window?: PrivacyWindow;
}): boolean {
  const current = toUrl(input.currentUrl, input.origin);
  const landing = toUrl(input.landingUrl, input.origin);
  return TOKEN_PATTERN.test(input.token)
    && !hasPrivacySignal(input.navigator, input.window)
    && !!current && current.origin === input.origin && current.search === ""
    && !!landing && landing.origin === input.origin && landing.search === "" && landing.pathname === current.pathname
    && isSafeReferrer(input.referrer, input.origin);
}

let started = false;

/** Injects the beacon once and installs the navigation guard. Returns true when the beacon was injected. */
export function startCloudflareAnalytics(router: AnalyticsRouter, storage: SignalStorage | null = browserStorage()): boolean {
  if (started || typeof document === "undefined" || typeof window === "undefined") return false;
  started = true;

  const token = document.querySelector<HTMLMetaElement>(`meta[name="${TOKEN_META_NAME}"]`)?.content.trim() ?? "";
  const origin = window.location.origin;
  const navEntry = typeof performance !== "undefined" && typeof performance.getEntriesByType === "function"
    ? (performance.getEntriesByType("navigation")[0] as PerformanceNavigationTiming | undefined) : undefined;
  const landingUrl = navEntry?.name || window.location.href;

  if (!shouldLoadBeacon({ token, origin, currentUrl: window.location.href, landingUrl, referrer: document.referrer, navigator, window: window as PrivacyWindow })) return false;
  if (document.getElementById(BEACON_SCRIPT_ID) || document.querySelector(`script[src^="${BEACON_SRC}"]`)) return false;
  // Without readable storage a sign-in in another tab cannot be detected, so do not start at all.
  if (!storage) return false;
  const startedAt = Date.now();
  if (signedInSince(startedAt, storage)) return false;
  const signedInElsewhere = () => signedInSince(startedAt, storage);

  const landingPath = window.location.pathname;
  const allowedInPlace = (url: URL | string) => {
    const u = toUrl(url, origin);
    return !!u && (isTrackableUrl(u, origin) || (u.origin === origin && u.search === "" && u.pathname === landingPath));
  };

  router.onBefore((visit) => {
    if (visit.prefetch || visit.method.toLowerCase() !== "get") return;
    if (allowedInPlace(visit.url) && !signedInElsewhere()) return;
    window.location.assign(visit.url.href);
    return false;
  });
  // Where the Navigation API exists (Chromium), the beacon counts a same-document navigation from the "navigate"
  // event, which fires before "popstate" and also for back/forward. Registered before the beacon loads, so this
  // listener runs first and hides the navigation from the beacon when the visitor has signed in (in any tab,
  // including a signed-in response to this tab's own visit, see watchAuthSignal) or the destination is not a
  // tracked URL; the popstate/onNavigate handlers below then reload.
  (window as { navigation?: NavigationLike }).navigation?.addEventListener("navigate", (event) => {
    const url = event.destination?.url;
    if (event.destination?.sameDocument === false) return;
    if (url && allowedInPlace(url) && !signedInElsewhere()) return;
    event.stopImmediatePropagation();
  });
  // A page restored from the back/forward cache keeps this document and its beacon, which restarts its measurements
  // on "pageshow". If the visitor signed in meanwhile, reload instead so the server decides again. Capture phase and
  // registered before the beacon, so this runs before the beacon's own capture-phase pageshow listener.
  window.addEventListener("pageshow", (event) => {
    if (!(event as { persisted?: boolean }).persisted || !signedInElsewhere()) return;
    event.stopImmediatePropagation();
    window.location.reload();
  }, true);
  // Back/forward does not fire Inertia's "before" event. Registered before the beacon loads, so this runs first
  // and, in browsers without the Navigation API, also stops the beacon's own popstate handling.
  window.addEventListener("popstate", (event) => {
    if (!signedInElsewhere()) return;
    event.stopImmediatePropagation();
    window.location.reload();
  });
  // Defence in depth: leave this document if a visit ended somewhere untracked or for a signed-in visitor.
  router.onNavigate((page) => {
    if (!allowedInPlace(window.location.href) || isAuthenticatedPage(page) || signedInElsewhere()) window.location.reload();
  });

  const script = document.createElement("script");
  script.id = BEACON_SCRIPT_ID;
  script.type = "module";
  script.src = BEACON_SRC;
  script.referrerPolicy = "no-referrer";
  script.setAttribute("data-cf-beacon", JSON.stringify({ token, spa: true }));
  document.head.appendChild(script);
  return true;
}
