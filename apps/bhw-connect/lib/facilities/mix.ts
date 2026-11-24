import type { NhfrTypeCount } from "@/lib/db/nhfr";
import { facilityMix } from "./analysis";
import { formatCount, formatPct } from "@/lib/format";

export type MixView = "mix" | "ownership";
export type MixOrder = "count" | "name";
export const MIX_NOTES = [
  "The facility mix uses all registered facilities in the selected location as its denominator. Selecting types does not change that denominator.",
  "Ownership shares use each facility type's own total. Government and private describe ownership, not cost or eligibility for care.",
  "Selected types are combined using OR, with each facility counted once. This card's filter is independent of the headline and other charts.",
  "Registry categories describe recorded facilities, not services operating today, care quality or adequate capacity. Types without a recorded row are not ranked here.",
];

export function selectMix(
  items: NhfrTypeCount[] | null,
  total: number,
  selected: string[],
  order: MixOrder,
) {
  const all = facilityMix(items, total);
  if (!all || selected.some((t) => !all.some((i) => i.facilityType === t))) return null;
  return all
    .filter((i) => !selected.length || selected.includes(i.facilityType))
    .sort(
      (a, b) =>
        (order === "count" ? b.nFacilities - a.nFacilities : 0) ||
        a.facilityType.localeCompare(b.facilityType, "en"),
    );
}
export function mixReading(rows: NhfrTypeCount[], total: number, selected: boolean, view: MixView) {
  const count = rows.reduce((n, r) => n + r.nFacilities, 0);
  if (view === "ownership") {
    const government = rows.reduce((n, r) => n + r.nGovernment, 0);
    return count > 0
      ? `Government owns ${formatPct((100 * government) / count, "single")} of the facilities in ${selected ? "the selected types" : "this location"}.`
      : "No selected facilities have a recorded ownership share.";
  }
  if (selected)
    return `These types account for ${formatCount(count)} of ${formatCount(total)} registered facilities here (${formatPct((100 * count) / total, "single")}).`;
  const largest = Math.max(...rows.map((r) => r.nFacilities));
  const leaders = rows.filter((r) => r.nFacilities === largest);
  return leaders.length === 1
    ? `${leaders[0].facilityType} is the most common recorded type here: ${formatCount(largest)} ${largest === 1 ? "facility" : "facilities"} (${formatPct((100 * largest) / total, "single")}).`
    : `${leaders.length} types share the largest recorded count, with ${formatCount(largest)} ${largest === 1 ? "facility" : "facilities"} each.`;
}
