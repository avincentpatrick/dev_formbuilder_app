import { ACCEPTED_RELEASES } from "./definitions";
import { hasAvailableValue } from "./summary";
import type { CatalogIndicator, CatalogSelection } from "./types";
import { formatCount, formatPct } from "@/lib/format";

export const NHFR_DEFINITION =
  "Registered health facilities in the DOH September 2026 snapshot, counted once per facility. This is an inventory of places, not a count of health workers or patient visits.";
export const NHFR_BEDS =
  "Sum of recorded bed capacity, not beds available today. Missing capacity is not evidence of zero beds, and the total does not establish completeness.";
export const NHFR_PRESENCE =
  "Distinct barangays with an attributed registered facility, out of the barangays in this reporting area. This describes recorded presence, not source reporting completeness, travel access, quality or sufficient capacity.";
export const NHFR_ATTRIBUTION =
  "Facilities without usable barangay attribution can remain in municipal totals but do not count toward barangay presence. A barangay with no attributed facility is not proof that no facility exists there. Split-city district figures include attributed facilities only.";

const units: Record<string, string> = {
  "nhfr.facilities": "facilities",
  "nhfr.government": "facilities",
  "nhfr.private": "facilities",
  "nhfr.beds": "beds",
  "nhfr.barangay-presence": "barangays",
};

/** Presentation only: reuse accepted observations without fetching or replacing scope. */
export function nhfrReading(indicator: CatalogIndicator | undefined, selection: CatalogSelection) {
  if (
    !indicator ||
    selection.mode === "all" ||
    !hasAvailableValue(indicator.state) ||
    indicator.sourceSlug !== ACCEPTED_RELEASES.nhfr ||
    !units[indicator.id] ||
    indicator.unit !== units[indicator.id] ||
    indicator.values.length !== 1
  )
    return null;
  if (
    selection.mode === "district" &&
    !selection.citymunCode &&
    !selection.barangayCode &&
    indicator.aggregation !== "sum"
  )
    return null;
  const { value, denominator } = indicator.values[0];
  if (!Number.isSafeInteger(value) || value < 0) return null;
  let display = formatCount(value);
  let denominatorNote: string | null = null;
  if (indicator.id === "nhfr.barangay-presence") {
    if (denominator != null) {
      if (!Number.isSafeInteger(denominator) || denominator < 0 || value > denominator) return null;
      display += ` of ${formatCount(denominator)}`;
      if (denominator > 0) display += ` (${formatPct((value / denominator) * 100, "single")})`;
      else
        denominatorNote =
          "No barangays recorded in the denominator; a percentage cannot be calculated.";
    } else denominatorNote = "Barangay denominator unavailable; a percentage cannot be calculated.";
  }
  return { display, denominatorNote, partial: indicator.state === "partial" };
}
