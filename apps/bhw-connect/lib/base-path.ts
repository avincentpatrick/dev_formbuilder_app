/**
 * The path prefix the app is served under, and the two helpers that apply it where Next's
 * `basePath` does not.
 *
 * `next.config.ts` sets `basePath` from `NEXT_PUBLIC_BASE_PATH`. It is empty on a laptop and on
 * Vercel, so every helper here is then the identity, and `/bhw-connect` on the testing box and in
 * CI's build (Meridian's ADR-0024, decision D172). Next prefixes `next/link`, `router.push`,
 * `redirect()` and its own assets by itself. It does NOT prefix a raw `<a href="/…">`, a
 * `fetch("/…")`, a `window.location` assignment, or the URL of a file under `public/` handed to a
 * fetch — those go through `withBasePath()`. `base-path-census.test.ts` holds the literal ones at
 * zero.
 *
 * `NEXT_PUBLIC_BASE_PATH` is read as a literal `process.env.NEXT_PUBLIC_…` member so Next inlines it
 * into client bundles at build time; a computed lookup would read `undefined` in the browser.
 */
export const BASE_PATH = normaliseBasePath(process.env.NEXT_PUBLIC_BASE_PATH);

/** `""`, or a prefix with one leading slash and no trailing one: `"/bhw-connect"`. */
export function normaliseBasePath(value: string | undefined): string {
  const trimmed = (value ?? "").trim().replace(/^\/+|\/+$/g, "");
  return trimmed ? `/${trimmed}` : "";
}

/**
 * Prefix a root-relative path (`/api/feedback`, `/geo/regions.json`, `/methodology#ai`) with the
 * base path. Anything else — a relative path, a hash, a protocol-relative or absolute URL — is
 * returned unchanged. Apply it once, where the URL is used; it does not detect a path that already
 * carries the prefix, because an app path may legitimately start with the same characters.
 */
export function withBasePath(path: string, basePath: string = BASE_PATH): string {
  if (!basePath || !path.startsWith("/") || path.startsWith("//")) return path;
  return `${basePath}${path}`;
}

/**
 * The inverse, for a browser `location.pathname` (which carries the prefix, unlike
 * `usePathname()`): `/bhw-connect/explore` → `/explore`, `/bhw-connect` → `/`.
 */
export function stripBasePath(pathname: string, basePath: string = BASE_PATH): string {
  if (!basePath) return pathname;
  if (pathname === basePath) return "/";
  return pathname.startsWith(`${basePath}/`) ? pathname.slice(basePath.length) : pathname;
}

// mutation(M192 J1): deliberately wrong, to prove the bhw-connect job goes red.
export const mutationJ1: number = "not a number";
