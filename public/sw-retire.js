/*
 * The retiring service worker — M253 (`R-43038865`, `D236`, runbook §8.3).
 *
 * Until the switch to three addresses, a form opened at `https://iconnect.com.de/f/<form>` installed this app's
 * service worker there (`/sw.js`, scope `/f/`), which keeps the form's shell and answers from it offline. After the
 * switch that address is the portal, and the form lives at `https://collection.iconnect.com.de/f/<form>`. A browser
 * keeps the old worker and keeps showing the old shell until the worker itself is replaced, and a worker's script is
 * never fetched through a redirect. So the portal answers `/sw.js` with THIS file (an Apache rewrite on the portal
 * vhost): the browser's next update check installs it, it empties the old caches and removes itself, and the page
 * reloads into the portal's redirect to the form's new address.
 *
 * ⚠️ It cannot move unsent answers. They sit in this browser's storage for the OLD address, which no page at the new
 * address can read, so testers send their queue before the switch (the tester notice says so).
 *
 * No fetch handler, on purpose: while it lives, every request goes to the network, and so to the redirect.
 */
self.addEventListener('install', () => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            for (const key of await caches.keys()) {
                await caches.delete(key);
            }

            await self.registration.unregister();

            for (const client of await self.clients.matchAll({ type: 'window' })) {
                client.navigate(client.url);
            }
        })(),
    );
});
