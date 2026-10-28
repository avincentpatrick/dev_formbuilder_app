/**
 * The app's public address — origin plus base path, no trailing slash — for the places that need
 * an absolute URL: `metadataBase`, the sitemap, `robots.txt`, and the OpenRouter attribution
 * header.
 *
 * Set by `NEXT_PUBLIC_SITE_URL` (`https://staging.pitahc.gov.ph/bhw-connect` on the testing box).
 * Unset, it is the Vercel address these four places hard-coded before the app came into Meridian's
 * repository, so a laptop or Vercel build behaves exactly as it did.
 */
export const VERCEL_SITE_URL = "https://bhw-connect-jongsky25s-projects.vercel.app";

export const SITE_URL = siteUrl(process.env.NEXT_PUBLIC_SITE_URL);

export function siteUrl(value: string | undefined): string {
  const trimmed = (value ?? "").trim().replace(/\/+$/, "");
  return trimmed || VERCEL_SITE_URL;
}
