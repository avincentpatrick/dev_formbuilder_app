/**
 * The Content-Security-Policy every page is served with, and the per-request nonce it carries.
 *
 * WHY THIS EXISTS. On the testing box the app is served under Meridian's own origin
 * (`staging.pitahc.gov.ph/bhw-connect`, Meridian's ADR-0024 and decision D172). Meridian's session
 * cookie reaches these pages, and its `XSRF-TOKEN` cookie is readable by script — so a script
 * injected into any BHW page could act as whoever is signed in to Meridian. The policy's job is to
 * make an injected script not run: only scripts carrying this request's nonce execute
 * (`'strict-dynamic'` extends that trust to the chunks they load), and nothing can frame the app.
 *
 * WHAT IT COSTS. A nonce is minted per request, so a page that carries one cannot be prerendered:
 * the root layout reads it, which renders every page on request. Measured on the route table when
 * this was added: every `○`/`●` route became `ƒ`. The data underneath is unchanged.
 *
 * WHAT IT DELIBERATELY ALLOWS. `style-src 'unsafe-inline'`: server-rendered `style=""` attributes
 * (charts, maps, React's `style={}`) need it, and an injected style cannot act as the signed-in
 * user. `img-src data: blob:` and `worker-src blob:`: html2canvas-pro's exports. MapLibre's worker,
 * a `blob:` before MapLibre 6, is now a module file served from `'self'`
 * (`components/maps/load-maplibre.ts`). In development only, `'unsafe-eval'` and `ws:` for React's
 * refresh and the dev server.
 */
export function contentSecurityPolicy(nonce: string, { dev }: { dev: boolean }): string {
  const directives: Record<string, string[]> = {
    "default-src": ["'self'"],
    "script-src": [
      "'self'",
      `'nonce-${nonce}'`,
      "'strict-dynamic'",
      ...(dev ? ["'unsafe-eval'"] : []),
    ],
    "style-src": ["'self'", "'unsafe-inline'"],
    "img-src": ["'self'", "data:", "blob:"],
    "font-src": ["'self'", "data:"],
    "connect-src": ["'self'", ...(dev ? ["ws:"] : [])],
    "worker-src": ["'self'", "blob:"],
    "child-src": ["'self'", "blob:"],
    "object-src": ["'none'"],
    "base-uri": ["'self'"],
    "form-action": ["'self'"],
    "frame-ancestors": ["'none'"],
  };
  return Object.entries(directives)
    .map(([name, values]) => `${name} ${values.join(" ")}`)
    .join("; ");
}

/** A fresh nonce: 128 random bits, base64. Web Crypto, so it runs wherever the proxy runs. */
export function createNonce(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16));
  return btoa(String.fromCharCode(...bytes));
}
