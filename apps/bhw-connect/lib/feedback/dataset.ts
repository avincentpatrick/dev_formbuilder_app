import { DATASET_SLUGS } from "@/lib/db/dataset";
import { EDITION_PARAM, UUC_EDITIONS, editionByYear } from "@/lib/uuc-phc/editions";

/**
 * Which dataset a page is about, for `feedback.dataset_slug` (plan U6).
 *
 * Pure and dependency-free so it can run on the server request path without a database read: the
 * answer is a property of the route, and the route is all the request carries. Derived once at
 * write time rather than at read time, so triage filters on a column instead of re-deriving a
 * URL convention every time someone looks at the inbox — which is the whole point of the column.
 *
 * **Only sections that are one dataset's surface get a slug.** `/explore` and `/compare` render
 * BHW figures beside census population and SAE poverty; naming one dataset there would assert
 * something the page does not support, and a wrong slug is worse than none because it is
 * filterable. Those return null and are triaged by `page_path` exactly as before.
 *
 * Deliberately not derived on the client: a slug a caller can set is a slug a caller can set
 * wrongly, and both entry points (the form and the spot widget) already send the path.
 */

/** Longest prefix wins, so `/profiling-status/methodology` resolves like its section. */
const SECTIONS: readonly { prefix: string; slug: string }[] = [
  { prefix: "/health-services", slug: DATASET_SLUGS.fhsis },
  { prefix: "/uuc-phc", slug: DATASET_SLUGS.uucPhc },
  { prefix: "/profiling-status", slug: DATASET_SLUGS.profilingStatus },
  // The 2025 BHW Census: its landing page and the per-place profiles built from it.
  { prefix: "/bhw", slug: DATASET_SLUGS.profiled },
  { prefix: "/place", slug: DATASET_SLUGS.profiled },
  { prefix: "/facilities", slug: DATASET_SLUGS.nhfr },
];

export function datasetSlugForPath(pagePath: string | null | undefined): string | null {
  if (!pagePath) return null;
  // Compare against a normalized path: a trailing slash or a query string must not change the
  // answer, and `/bhwx` must not match `/bhw`.
  const path = pagePath.split(/[?#]/, 1)[0].replace(/\/+$/, "") || "/";
  for (const { prefix, slug } of SECTIONS) {
    if (path === prefix || path.startsWith(`${prefix}/`)) {
      return prefix === "/uuc-phc" ? uucEditionSlug(pagePath) : slug;
    }
  }
  return null;
}

/**
 * The UUC for PHC section shows one edition at a time: `?edition=<year>` names it, and no parameter
 * means the newest. A correction is about the list that was on screen, so it is tagged with that
 * edition. "The newest" is the newest the code knows of — the one the section opens on wherever it
 * is loaded; the column is not a foreign key and records intent, so that is the right answer even
 * on a database that has not loaded it.
 */
function uucEditionSlug(pagePath: string): string {
  const query = pagePath.split("#", 1)[0].split("?")[1] ?? "";
  const year = new URLSearchParams(query).get(EDITION_PARAM);
  return (editionByYear(year) ?? UUC_EDITIONS[0]).slug;
}
