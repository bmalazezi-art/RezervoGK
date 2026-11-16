import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import vm from 'node:vm';
import { test } from 'node:test';
import ts from 'typescript';

const root = resolve(import.meta.dirname, '../..');
const source = readFileSync(resolve(root, 'resources/js/lib/cloudflareAnalytics.ts'), 'utf8');
const compiled = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 } }).outputText;
const TOKEN = 'b986a4a614f049b68b3e885b48fea484';
const ORIGIN = 'https://gjejportier.online';

function load(globals = {}) {
  const module = { exports: {} };
  vm.runInNewContext(compiled, { module, exports: module.exports, URL, JSON, ...globals });
  return module.exports;
}

function memoryStorage(initial = {}) {
  const data = { ...initial };
  return { data, getItem: (key) => (key in data ? data[key] : null), setItem: (key, value) => { data[key] = String(value); } };
}

function browser({ href = `${ORIGIN}/`, landing = href, referrer = '', token = TOKEN, nav = {}, existingScript = false, storage = memoryStorage() } = {}) {
  const appended = [];
  const calls = { assign: [], reload: 0 };
  const location = { href, origin: ORIGIN, pathname: new URL(href).pathname, assign: (url) => calls.assign.push(url), reload: () => { calls.reload++; } };
  const document = {
    referrer,
    head: { appendChild: (el) => appended.push(el) },
    createElement: () => ({ attributes: {}, setAttribute(name, value) { this.attributes[name] = value; } }),
    getElementById: () => (existingScript ? {} : null),
    querySelector: (selector) => selector.startsWith('meta') ? (token === null ? null : { content: token }) : (existingScript ? {} : null),
  };
  const handlers = {};
  const listeners = {};
  const navigation = { addEventListener: (type, cb) => { (listeners[`navigation:${type}`] ??= []).push(cb); } };
  // Like Chromium, the native history methods fire the Navigation API "navigate" event synchronously.
  const nativeWrite = (url) => {
    const event = { destination: { url: new URL(url ?? href, ORIGIN).href, sameDocument: true }, stopped: false, stopImmediatePropagation() { this.stopped = true; } };
    for (const cb of listeners['navigation:navigate'] ?? []) { cb(event); if (event.stopped) break; }
    calls.historyWrites.push({ url, hiddenFromBeacon: event.stopped });
  };
  calls.historyWrites = [];
  const history = { pushState: (data, unused, url) => nativeWrite(url), replaceState: (data, unused, url) => nativeWrite(url) };
  const window = { location, localStorage: storage, navigation, history, addEventListener: (type, cb) => { (listeners[type] ??= []).push(cb); } };
  const router = { onBefore: (cb) => { handlers.before = cb; }, onNavigate: (cb) => { (handlers.navigates ??= []).push(cb); handlers.navigate = handlers.navigates[0]; }, onBeforeUpdate: (cb) => { handlers.beforeUpdate = cb; } };
  const lib = load({ document, window, navigator: nav, performance: { getEntriesByType: () => [{ name: landing }] }, Date, Number, String });
  return { lib, appended, calls, handlers, listeners, router, location, storage, history: window.history };
}

const visit = (path, extra = {}) => ({ url: new URL(path, ORIGIN), method: 'get', prefetch: false, ...extra });

test('only public, query-free paths are trackable in place', () => {
  const { isTrackableUrl } = load();
  for (const path of ['/', '/browse', '/how-it-works', '/faq', '/contact', '/privacy', '/terms']) assert.ok(isTrackableUrl(path, ORIGIN), path);
  for (const path of ['/browse?city=2', '/?utm_source=ig', '/dashboard', '/login', '/register', '/forgot-password', '/reset-password/abc',
    '/admin', '/admin/users/4', '/admin/login', '/admin/verify', '/payments/1/receipt', '/p/arben-krasniqi', '/keepers/7', '/pricing']) {
    assert.equal(isTrackableUrl(path, ORIGIN), false, path);
  }
  assert.equal(isTrackableUrl('https://evil.example/', ORIGIN), false);
});

test('Do Not Track and Global Privacy Control block the beacon', () => {
  const { hasPrivacySignal } = load();
  assert.equal(hasPrivacySignal({}), false);
  assert.equal(hasPrivacySignal({ doNotTrack: '0' }), false);
  assert.ok(hasPrivacySignal({ doNotTrack: '1' }));
  assert.ok(hasPrivacySignal({ msDoNotTrack: '1' }));
  assert.ok(hasPrivacySignal({}, { doNotTrack: '1' }));
  assert.ok(hasPrivacySignal({ globalPrivacyControl: true }));
});

test('landing URLs and referrers with query strings or private paths are not reported', () => {
  const { shouldLoadBeacon } = load();
  const base = { token: TOKEN, origin: ORIGIN, currentUrl: `${ORIGIN}/`, landingUrl: `${ORIGIN}/`, referrer: '', navigator: {} };
  assert.ok(shouldLoadBeacon(base));
  assert.ok(shouldLoadBeacon({ ...base, referrer: 'https://www.instagram.com/' }));
  assert.ok(shouldLoadBeacon({ ...base, referrer: `${ORIGIN}/faq` }));
  assert.equal(shouldLoadBeacon({ ...base, token: '' }), false);
  assert.equal(shouldLoadBeacon({ ...base, token: '"><script>' }), false);
  assert.equal(shouldLoadBeacon({ ...base, currentUrl: `${ORIGIN}/?utm_source=ig`, landingUrl: `${ORIGIN}/?utm_source=ig` }), false);
  assert.equal(shouldLoadBeacon({ ...base, landingUrl: `${ORIGIN}/browse?city=1` }), false);
  assert.equal(shouldLoadBeacon({ ...base, referrer: `${ORIGIN}/dashboard` }), false);
  assert.equal(shouldLoadBeacon({ ...base, referrer: `${ORIGIN}/reset-password/secret-token` }), false);
  assert.equal(shouldLoadBeacon({ ...base, referrer: 'https://l.facebook.com/l.php?u=x' }), false);
  assert.equal(shouldLoadBeacon({ ...base, referrer: 'https://news.example/teams/arben-krasniqi' }), false);
  assert.equal(shouldLoadBeacon({ ...base, navigator: { globalPrivacyControl: true } }), false);
});

test('beacon is injected once with the configured token and SPA tracking', () => {
  const env = browser();
  assert.equal(env.lib.startCloudflareAnalytics(env.router), true);
  assert.equal(env.lib.startCloudflareAnalytics(env.router), false);
  assert.equal(env.appended.length, 1);
  const [script] = env.appended;
  assert.equal(script.src, 'https://static.cloudflareinsights.com/beacon.min.js');
  assert.equal(script.type, 'module');
  assert.equal(script.id, 'cf-web-analytics-beacon');
  assert.deepEqual(JSON.parse(script.attributes['data-cf-beacon']), { token: TOKEN, spa: true });
});

test('nothing is injected without the server token, with privacy signals or when a beacon already exists', () => {
  for (const options of [{ token: null }, { nav: { doNotTrack: '1' } }, { nav: { globalPrivacyControl: true } }, { existingScript: true }, { href: `${ORIGIN}/browse?city=1` }]) {
    const env = browser(options);
    assert.equal(env.lib.startCloudflareAnalytics(env.router), false, JSON.stringify(options));
    assert.equal(env.appended.length, 0);
    assert.equal(env.handlers.before, undefined);
  }
});

test('navigating to private pages or query URLs leaves the tracked document', () => {
  const env = browser({ href: `${ORIGIN}/browse` });
  env.lib.startCloudflareAnalytics(env.router);
  assert.equal(env.handlers.before(visit('/faq')), undefined);
  assert.equal(env.handlers.before(visit('/')), undefined);
  assert.equal(env.handlers.before(visit('/dashboard')), false);
  assert.equal(env.handlers.before(visit('/browse?city=2')), false);
  assert.equal(env.handlers.before(visit('/p/arben-krasniqi')), false);
  assert.deepEqual(env.calls.assign, [`${ORIGIN}/dashboard`, `${ORIGIN}/browse?city=2`, `${ORIGIN}/p/arben-krasniqi`]);
  assert.equal(env.handlers.before(visit('/dashboard', { prefetch: true })), undefined);
  assert.equal(env.handlers.before(visit('/locale', { method: 'post' })), undefined);
  assert.equal(env.calls.assign.length, 3);

  env.handlers.navigate();
  assert.equal(env.calls.reload, 0);
  env.location.href = `${ORIGIN}/login`;
  env.handlers.navigate();
  assert.equal(env.calls.reload, 1);
});

test('client allowlist matches the server route allowlist', () => {
  const php = readFileSync(resolve(root, 'app/Support/CloudflareWebAnalytics.php'), 'utf8');
  const routes = php.match(/PUBLIC_ROUTES = \[([^\]]+)\]/)[1].match(/'([^']+)'/g).map((name) => name.slice(1, -1));
  const web = readFileSync(resolve(root, 'routes/web.php'), 'utf8');
  const { TRACKED_PATHS } = load();
  const paths = routes.map((name) => {
    const match = web.match(new RegExp(`Route::(?:get|inertia)\\('([^']+)'[^;]*->name\\('${name}'\\)`, 's'));
    assert.ok(match, `route ${name} exists`);
    return match[1];
  });
  assert.deepEqual([...TRACKED_PATHS].sort(), paths.sort());
});

test('L2: external referrers are accepted only as a bare origin, never with a path', () => {
  const { isSafeReferrer } = load();
  for (const referrer of ['', 'https://www.instagram.com/', 'https://www.google.com', 'https://l.facebook.com/', `${ORIGIN}/`, `${ORIGIN}/browse`, `${ORIGIN}/faq`]) {
    assert.ok(isSafeReferrer(referrer, ORIGIN), referrer);
  }
  for (const referrer of ['https://www.instagram.com/p/abc123/', 'https://news.example/teams/arben-krasniqi', 'https://forum.example/thread/42',
    'https://example.com/?q=x', 'https://user:secret@example.com/', 'https://example.com/#frag', 'not a url',
    `${ORIGIN}/dashboard`, `${ORIGIN}/reset-password/secret-token`, `${ORIGIN}/p/arben-krasniqi`, `${ORIGIN}/browse?city=1`]) {
    assert.equal(isSafeReferrer(referrer, ORIGIN), false, referrer);
  }
});

test('L1: authenticated pages record a timestamp-only sign-in signal; guest pages record nothing', () => {
  const { recordAuthSignal, watchAuthSignal, AUTH_SIGNAL_KEY } = load();
  const storage = memoryStorage();
  recordAuthSignal({ props: { auth: { user: null } } }, storage, 1000);
  recordAuthSignal(undefined, storage, 1000);
  assert.deepEqual(storage.data, {});
  recordAuthSignal({ props: { auth: { user: { id: 7, name: 'Arben', email: 'a@example.com' } } } }, storage, 1234);
  assert.deepEqual(storage.data, { [AUTH_SIGNAL_KEY]: '1234' });

  let navigate;
  const watched = memoryStorage();
  watchAuthSignal({ onBefore: () => {}, onNavigate: (cb) => { navigate = cb; }, onBeforeUpdate: () => {} }, watched);
  navigate({ props: { auth: { user: { id: 1 } } } });
  assert.match(watched.data[AUTH_SIGNAL_KEY], /^\d+$/);
  assert.doesNotThrow(() => recordAuthSignal({ props: { auth: { user: { id: 1 } } } }, { getItem: () => null, setItem: () => { throw new Error('quota'); } }));
});

test('L1: sign-in forms submitted with replace: true (no "navigate" event) still record the signal', () => {
  const { watchAuthSignal, AUTH_SIGNAL_KEY } = load({ Date, Number, String });
  const handlers = {};
  const storage = memoryStorage();
  watchAuthSignal({ onBefore: () => {}, onNavigate: (cb) => { handlers.navigate = cb; }, onBeforeUpdate: (cb) => { handlers.beforeUpdate = cb; } }, storage);
  handlers.beforeUpdate({ props: { auth: { user: null } }, url: '/login' });
  assert.deepEqual(storage.data, {}, 'a failed or guest response records nothing');
  handlers.beforeUpdate({ props: { auth: { user: { id: 4 } } }, url: '/dashboard' });
  assert.match(storage.data[AUTH_SIGNAL_KEY], /^\d+$/);
});

test('L1: a sign-in in another tab turns the next in-app navigation into a full reload', () => {
  const env = browser({ href: `${ORIGIN}/browse` });
  assert.equal(env.lib.startCloudflareAnalytics(env.router, env.storage), true);
  assert.equal(env.handlers.before(visit('/faq')), undefined);

  env.storage.setItem(env.lib.AUTH_SIGNAL_KEY, String(Date.now() + 5));
  assert.equal(env.handlers.before(visit('/faq')), false);
  assert.equal(env.handlers.before(visit('/')), false);
  assert.deepEqual(env.calls.assign, [`${ORIGIN}/faq`, `${ORIGIN}/`]);
  assert.equal(env.handlers.before(visit('/faq', { prefetch: true })), undefined);
});

test('L1: back/forward after a sign-in elsewhere reloads before other popstate listeners run', () => {
  const env = browser({ href: `${ORIGIN}/faq` });
  env.lib.startCloudflareAnalytics(env.router, env.storage);
  assert.equal(env.listeners.popstate.length, 1);
  const event = () => ({ stopped: false, stopImmediatePropagation() { this.stopped = true; } });

  const before = event();
  env.listeners.popstate[0](before);
  assert.equal(before.stopped, false);
  assert.equal(env.calls.reload, 0);

  env.storage.setItem(env.lib.AUTH_SIGNAL_KEY, String(Date.now() + 5));
  const after = event();
  env.listeners.popstate[0](after);
  assert.equal(after.stopped, true);
  assert.equal(env.calls.reload, 1);
});

test('L1: Navigation API navigations are hidden from the beacon after a sign-in elsewhere or to untracked URLs', () => {
  const env = browser({ href: `${ORIGIN}/faq` });
  env.lib.startCloudflareAnalytics(env.router, env.storage);
  assert.equal(env.listeners['navigation:navigate'].length, 1);
  const navigate = (url, sameDocument = true) => {
    const event = { destination: { url: new URL(url, ORIGIN).href, sameDocument }, stopped: false, stopImmediatePropagation() { this.stopped = true; } };
    env.listeners['navigation:navigate'][0](event);
    return event.stopped;
  };

  assert.equal(navigate('/'), false, 'tracked back/forward stays visible to the beacon');
  assert.equal(navigate('/dashboard'), true, 'untracked same-document destination is hidden');
  assert.equal(navigate('/browse?city=2'), true);
  assert.equal(navigate('/dashboard', false), false, 'cross-document navigations unload the beacon anyway');

  env.storage.setItem(env.lib.AUTH_SIGNAL_KEY, String(Date.now() + 5));
  assert.equal(navigate('/'), true, 'after a sign-in elsewhere even tracked back/forward is hidden');
});

test('L1 race: a signed-in response to this tab\'s own visit is hidden from the beacon before the other tab signals', () => {
  // Order in Chromium: Inertia "beforeUpdate" (server page) -> history.pushState -> Navigation API "navigate"
  // (beacon counts here) -> Inertia "navigate". The other tab has set the session cookie but not yet its signal.
  const env = browser({ href: `${ORIGIN}/` });
  env.lib.watchAuthSignal(env.router, env.storage);
  env.lib.startCloudflareAnalytics(env.router, env.storage);
  assert.equal(env.handlers.before(visit('/faq')), undefined, 'visit starts while the tab still looks like a guest');

  env.handlers.beforeUpdate({ url: '/faq', props: { auth: { user: null } } });
  env.history.pushState({ page: 'encrypted' }, '', '/faq');
  env.handlers.beforeUpdate({ url: '/faq', props: { auth: { user: { id: 4 } } } });
  env.history.pushState({ page: 'encrypted' }, '', '/faq');
  assert.deepEqual(env.calls.historyWrites.map(w => w.hiddenFromBeacon), [false, true]);
});

test('L1 bfcache: a page restored after a sign-in reloads before the beacon handles pageshow', () => {
  const env = browser({ href: `${ORIGIN}/faq` });
  env.lib.startCloudflareAnalytics(env.router, env.storage);
  assert.equal(env.listeners.pageshow.length, 1);
  const show = (persisted) => {
    const event = { persisted, stopped: false, stopImmediatePropagation() { this.stopped = true; } };
    env.listeners.pageshow[0](event);
    return event.stopped;
  };

  assert.equal(show(true), false, 'restored while still a guest: keep the beacon');
  assert.equal(show(false), false);
  assert.equal(env.calls.reload, 0);

  env.storage.setItem(env.lib.AUTH_SIGNAL_KEY, String(Date.now() + 5));
  assert.equal(show(false), false, 'a normal first load is never interrupted');
  assert.equal(show(true), true);
  assert.equal(env.calls.reload, 1);
});

test('L1: a navigation that lands on an authenticated page reloads immediately', () => {
  const env = browser({ href: `${ORIGIN}/` });
  env.lib.startCloudflareAnalytics(env.router, env.storage);
  env.handlers.navigate({ props: { auth: { user: null } } });
  assert.equal(env.calls.reload, 0);
  env.handlers.navigate({ props: { auth: { user: { id: 3 } } } });
  assert.equal(env.calls.reload, 1);
});

test('L1: stale sign-in signals do not stop analytics; unreadable storage fails closed', () => {
  const stale = browser({ storage: memoryStorage({ 'gjejportier:auth-signal': '1000' }) });
  assert.equal(stale.lib.startCloudflareAnalytics(stale.router, stale.storage), true);
  assert.equal(stale.handlers.before(visit('/faq')), undefined);

  for (const storage of [null, { getItem: () => { throw new Error('blocked'); }, setItem: () => {} }]) {
    const env = browser({ storage });
    assert.equal(env.lib.startCloudflareAnalytics(env.router, storage), false);
    assert.equal(env.appended.length, 0);
    assert.equal(env.handlers.before, undefined);
  }

  const defaults = browser();
  assert.equal(defaults.lib.startCloudflareAnalytics(defaults.router), true, 'uses window.localStorage by default');
});
