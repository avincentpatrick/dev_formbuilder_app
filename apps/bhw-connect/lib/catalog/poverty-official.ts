import type { CatalogIndicator } from "./types";

export const OFFICIAL_POVERTY_URL =
  "https://psa.gov.ph/system/files/phdsd/2023%20Full%20Year%20Official%20Poverty%20Statistics_r2.xlsx";
const finite = (value: unknown): value is number =>
  typeof value === "number" && Number.isFinite(value);

/** Fail closed on ambiguous rows, missing contracts or malformed published states. */
export function officialPovertyIndicator(
  rows: Record<string, unknown>[],
  item: CatalogIndicator,
): CatalogIndicator {
  const matched = rows.filter((row) => row.reference_year === 2023);
  if (!matched.length)
    return {
      ...item,
      values: [],
      reason:
        "No matched official 2023 record is loaded for this area. This does not establish that PSA published no estimate.",
    };
  const row = matched[0];
  const invalid = () => ({
    ...item,
    values: [],
    state: "failed" as const,
    reason: "The official estimate or its geographic contract could not be verified.",
  });
  if (
    matched.length !== 1 ||
    typeof row.is_suppressed !== "boolean" ||
    !["source_label", "source_table", "boundary_note", "publication_note"].every(
      (key) => typeof row[key] === "string" && (row[key] as string).trim(),
    ) ||
    !["tab2", "tab2a"].includes(String(row.source_table))
  )
    return invalid();
  const note = `Source reporting area: ${row.source_label} (${row.source_table === "tab2" ? "Table 2" : "Table 2a"}). ${row.boundary_note} ${row.publication_note}`;
  if (row.is_suppressed)
    return {
      ...item,
      values: [],
      state: "suppressed",
      reason: `Withheld under the source publication rules. ${note}`,
    };
  if (row.value_status === "not_absolute_zero")
    return {
      ...item,
      values: [],
      state: "unavailable",
      reason: `PSA publishes a dash: it is not absolute zero and does not mean there are no poor people. ${note}`,
    };
  if (row.value_status === "unavailable")
    return {
      ...item,
      values: [],
      state: "unavailable",
      reason: `PSA reports this estimate as unavailable. ${note}`,
    };
  if (
    row.value_status !== "available" ||
    !finite(row.poverty_incidence) ||
    row.poverty_incidence < 0 ||
    row.poverty_incidence > 100 ||
    (row.cv !== null && (!finite(row.cv) || row.cv < 0)) ||
    ![row.ci_low, row.ci_high].every((n) => n === null || finite(n)) ||
    (finite(row.ci_low) && finite(row.ci_high) && row.ci_low > row.ci_high)
  )
    return invalid();
  return {
    ...item,
    state: "available",
    reportedAreaRecords: 1,
    values: [
      {
        value: row.poverty_incidence,
        ciLow: row.ci_low as number | null,
        ciHigh: row.ci_high as number | null,
      },
    ],
    reason: `${note}${(finite(row.cv) && row.cv > 10) || /Original footnote markers:.*\bc\//.test(String(row.publication_note)) ? " Use with caution: the published coefficient of variation exceeds 10%." : ""}`,
  };
}
