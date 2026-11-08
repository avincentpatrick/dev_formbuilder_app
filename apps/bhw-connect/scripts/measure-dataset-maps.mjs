/** Opt-in local/production read-only measurement. Run separately from CI correctness tests.
 * node scripts/measure-dataset-maps.mjs http://127.0.0.1:3100/bhw-connect/
 */
import { chromium } from 'playwright';
const base = new URL(process.argv[2] ?? 'http://127.0.0.1:3100/bhw-connect/');
if (!['http:', 'https:'].includes(base.protocol) || base.username || base.password ||
    !base.pathname.endsWith('/bhw-connect/') || base.search || base.hash)
  throw new Error('Supply an HTTP(S) /bhw-connect/ base URL without credentials');
const browser = await chromium.launch({ headless: true });
try {
  const results = [];
  for (const { mode, run } of ['portal', 'fullscreen'].flatMap(mode =>
    Array.from({ length: 3 }, (_, index) => ({ mode, run: index + 1 })))) {
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, deviceScaleFactor: 1 });
    try {
      const page = await context.newPage();
      page.setDefaultTimeout(120000);
      page.setDefaultNavigationTimeout(120000);
      const cdp = await context.newCDPSession(page);
      await cdp.send('Network.enable');
      await cdp.send('Network.setCacheDisabled', { cacheDisabled: true });
      await cdp.send('Network.emulateNetworkConditions', { offline: false, latency: 80,
        downloadThroughput: 4 * 1024 * 1024 / 8, uploadThroughput: 1024 * 1024 / 8 });
      await cdp.send('Emulation.setCPUThrottlingRate', { rate: 4 });
      const requested = [];
      page.on('request', request => {
        const url = new URL(request.url());
        if (url.pathname.endsWith('/api/dataset-map')) requested.push(url.searchParams.get('dataset'));
      });
      const started = performance.now();
      await page.goto(new URL(mode === 'portal' ? './' : 'map?dataset=uuc-phc-2025', base).href, { waitUntil: 'domcontentloaded' });
      const card = page.getByRole('article', { name: 'UUC for PHC 2025 map', exact: true });
      await card.waitFor();
      const beforeScrollRequests = [...requested];
      const visibleStart = performance.now();
      if (mode === 'portal') await card.scrollIntoViewIfNeeded();
      await card.locator('[data-map-state="ready"]').waitFor();
      const ready = performance.now();
      const resources = await page.evaluate(() => performance.getEntriesByType('resource')
        .filter(entry => /\/(geo\/|api\/dataset-map|maplibre-gl-worker)/.test(new URL(entry.name).pathname))
        .map(entry => ({ path: new URL(entry.name).pathname, transferSize: entry.transferSize,
          encodedBodySize: entry.encodedBodySize, durationMs: entry.duration >= 0 ? Math.round(entry.duration) : null,
          timingValid: entry.duration >= 0 })));
      results.push({ mode, run, navigationToReadyMs: Math.round(ready - started),
        afterDocumentAndScrollReadyMs: Math.round(ready - visibleStart),
        targetMs: 3000, meetsPostDocumentTarget: ready - visibleStart <= 3000,
        beforeScrollRequests, requestedDatasets: requested, liveMaps: await page.locator('.maplibregl-map').count(), resources });
    } finally { await context.close(); }
  }
  console.log(JSON.stringify({ measuredAt: new Date().toISOString(), origin: base.origin,
    browser: browser.version(), profile: { viewport: '390x844', cpuSlowdown: 4, latencyMs: 80,
      downloadBytesPerSecond: 524288, uploadBytesPerSecond: 131072, browserCache: 'disabled', runsPerScenario: 3 },
    caveat: 'Chromium emulation, not a real handset. Server caches are not reset. Post-document timing is not cold navigation latency. No production claim from local results.', results }, null, 2));
} finally { await browser.close(); }
