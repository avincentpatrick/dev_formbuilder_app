/**
 * A page of Meridian's, the application this one shares an address with on the testing box
 * (Meridian's ADR-0024): its sign-in, its home, its sign-out. Built from the origin of
 * `NEXT_PUBLIC_SITE_URL`, never from a root-relative string: this app's base path must NOT be added,
 * which is the one thing `withBasePath()` and the base-path census exist to make sure of.
 *
 * Null wherever this app runs without Meridian beside it (a laptop, a preview): `NEXT_PUBLIC_SITE_URL`
 * is set only on the box, so nothing links to a page that is not there.
 */
export function meridianUrl(path: string, siteUrl: string | undefined = process.env.NEXT_PUBLIC_SITE_URL): string | null {
  const trimmed = siteUrl?.trim();
  if (!trimmed) return null;
  try {
    return `${new URL(trimmed).origin}${path}`;
  } catch {
    return null;
  }
}
