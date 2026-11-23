/**
 * The UUC for PHC list's editions — one per year the Department of Health publishes it.
 *
 * Each edition is its own `dim_dataset` row (`uuc-phc-<year>`), with its own facts and aggregates
 * under its own `dataset_id`; nothing is ever compared or summed across two of them except on the
 * changes page, which says so. The `/uuc-phc` section shows one edition at a time, chosen by the
 * `?edition=<year>` search parameter and defaulting to the newest edition that is loaded
 * (`lib/db/uuc-phc-edition.ts` decides "loaded"). See docs/UUC_PHC_2026_PLAN.md.
 *
 * Pure and dependency-free, so client components (the edition switch, the footer) and server code
 * read the same table.
 *
 * ⚠ The 2026 edition's issuance is a PLACEHOLDER until the source office's circular number is
 * confirmed: replace `ISSUANCE_PLACEHOLDER` below and the same marker in
 * supabase/migrations/20261124090000_seed_dim_dataset_uuc_phc_2026.sql before merging, and set
 * `issuanceConfirmed: true`. `grep -rn "to be confirmed" apps/bhw-connect` finds every copy.
 */

export const ISSUANCE_PLACEHOLDER = "DC No. to be confirmed";

export type UucEdition = {
  /** The list's year, as it appears in every heading ("the 2026 list"). */
  year: string;
  /** dim_dataset slug — every read of this edition goes through it. */
  slug: string;
  /** The issuance the list was published under, as cited on every page. */
  issuance: string;
  /** False while `issuance` is still the placeholder. */
  issuanceConfirmed: boolean;
};

/** Newest first: the first edition that is loaded is the section's default. */
export const UUC_EDITIONS: readonly UucEdition[] = [
  {
    year: "2026",
    slug: "uuc-phc-2026",
    issuance: ISSUANCE_PLACEHOLDER,
    issuanceConfirmed: false,
  },
  {
    year: "2025",
    slug: "uuc-phc-2025",
    issuance: "DC No. 2025-0549",
    issuanceConfirmed: true,
  },
];

/** The criteria every edition is assessed against. */
export const UUC_CRITERIA_ORDER = "DOH AO No. 2020-0023";

/** The search parameter that selects an edition. */
export const EDITION_PARAM = "edition";

export function editionByYear(year: string | null | undefined): UucEdition | undefined {
  if (!year) return undefined;
  return UUC_EDITIONS.find((e) => e.year === year);
}

export function editionBySlug(slug: string | null | undefined): UucEdition | undefined {
  if (!slug) return undefined;
  return UUC_EDITIONS.find((e) => e.slug === slug);
}

/** The edition before this one, or undefined for the earliest. */
export function previousEdition(edition: UucEdition): UucEdition | undefined {
  const i = UUC_EDITIONS.findIndex((e) => e.slug === edition.slug);
  return i >= 0 ? UUC_EDITIONS[i + 1] : undefined;
}

/**
 * The edition a request asks for, among those available (newest first). An unknown or unavailable
 * year falls back to the default rather than erroring: a shared link to an edition that is not
 * loaded here still opens the section on real data, and the switch shows which year that is.
 */
export function pickEdition(
  requested: string | null | undefined,
  available: readonly UucEdition[],
): UucEdition {
  const pool = available.length > 0 ? available : UUC_EDITIONS.slice(-1);
  return pool.find((e) => e.year === requested) ?? pool[0];
}

/**
 * `href` with `?edition=<year>` when the edition is not the default one; the default edition's
 * links stay clean, so a shared link to the current list keeps pointing at whatever is current.
 */
export function withEdition(href: string, edition: UucEdition, defaultEdition: UucEdition): string {
  if (edition.slug === defaultEdition.slug) return href;
  const [path, hash] = href.split("#", 2);
  const sep = path.includes("?") ? "&" : "?";
  return `${path}${sep}${EDITION_PARAM}=${edition.year}${hash ? `#${hash}` : ""}`;
}

/** "the 2026 UUC for PHC list" — the noun every page's copy is built around. */
export function editionListName(edition: UucEdition): string {
  return `${edition.year} UUC for PHC list`;
}

/** "Issued under DC No. 2025-0549 · criteria per DOH AO No. 2020-0023". */
export function editionIssuanceLine(edition: UucEdition): string {
  return `Issued under ${edition.issuance} · criteria per ${UUC_CRITERIA_ORDER}`;
}

/** The section's source line, as the footer and the exports cite it. */
export function editionSourceLine(edition: UucEdition): string {
  return `DOH Bureau of Local Health Systems Development — ${edition.year} list of Unserved and Underserved Communities for Primary Health Care (${edition.issuance})`;
}
