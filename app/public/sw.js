/* DevStep service worker (docs/14). Purpose: the app opens instantly and still opens offline.
 *
 * Privacy rule: authenticated API responses are NEVER cached. Learners may share a device, and answers,
 * drafts and evidence must not outlive their session. Only these are cached:
 *   - the app shell (the same HTML for every route; it carries no personal data)
 *   - fingerprinted build assets and icons
 *   - the public sample scenario and roadmap catalogue (no personal data, no answer keys)
 * Drafts are kept by the app itself in localStorage, not here.
 */
const VERSION = 'devstep-v1';
const SHELL = `${VERSION}-shell`;
const ASSETS = `${VERSION}-assets`;
const PUBLIC_API = `${VERSION}-public-api`;
const PUBLIC_API_PATHS = ['/v1/guest/sample', '/v1/roadmaps'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(SHELL).then((c) => c.add(new Request('/', { cache: 'reload' }))).then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('message', (event) => {
    // Sign-out clears everything cached for this origin that could be user-specific (nothing today, kept as a guard).
    if (event.data === 'clear-public-api') event.waitUntil(caches.delete(PUBLIC_API));
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return; // never touch writes
    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;

    // Authenticated or personal API: network only, never stored.
    if (url.pathname.startsWith('/v1/')) {
        if (PUBLIC_API_PATHS.some((p) => url.pathname === p || url.pathname.startsWith(`${p}/`))) {
            event.respondWith(staleWhileRevalidate(req, PUBLIC_API));
        }
        return;
    }
    if (url.pathname === '/up') return;

    // Fingerprinted assets never change: cache first.
    if (url.pathname.startsWith('/build/assets/')) {
        event.respondWith(cacheFirst(req, ASSETS));
        return;
    }
    if (url.pathname.startsWith('/icons/') || url.pathname === '/manifest.webmanifest') {
        event.respondWith(staleWhileRevalidate(req, ASSETS));
        return;
    }

    // Page navigations: network first, fall back to the cached shell so the app opens offline.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).then((res) => {
                if (res.ok) {
                    const copy = res.clone();
                    caches.open(SHELL).then((c) => c.put('/', copy));
                }
                return res;
            }).catch(() => caches.open(SHELL).then((c) => c.match('/')).then((r) => r || offlineResponse())),
        );
    }
});

async function cacheFirst(req, name) {
    const cache = await caches.open(name);
    const hit = await cache.match(req);
    if (hit) return hit;
    const res = await fetch(req);
    if (res.ok) cache.put(req, res.clone());
    return res;
}

async function staleWhileRevalidate(req, name) {
    const cache = await caches.open(name);
    const hit = await cache.match(req);
    const network = fetch(req).then((res) => {
        if (res.ok) cache.put(req, res.clone());
        return res;
    }).catch(() => hit);
    return hit || network;
}

function offlineResponse() {
    return new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>DevStep is offline</title>'
        + '<body style="font:16px system-ui;max-width:32rem;margin:3rem auto;padding:0 1rem"><h1>You are offline</h1>'
        + '<p>DevStep could not reach the network and has no saved copy of the app yet. Reconnect and reload. Anything you already wrote is kept on this device.</p></body>',
    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}
