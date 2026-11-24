import { z } from "zod";
import { formatCount } from "@/lib/format";
export type PresenceOrder = "highest" | "lowest" | "name";
const count = z.number().int().nonnegative().max(Number.MAX_SAFE_INTEGER);
export const presenceSchema = z
  .object({
    code: z.string(),
    name: z.string(),
    level: z.enum(["national", "region", "province"]),
    types: z.array(z.string().min(1)),
    selectedTypes: z.array(z.string().min(1)),
    rows: z.array(
      z.object({
        code: z.string(),
        name: z.string(),
        level: z.enum(["region", "province", "citymun"]),
        present: count,
        barangays: count,
      }),
    ),
  })
  .refine((i) => {
    const child = { national: "region", region: "province", province: "citymun" }[i.level];
    return (
      i.rows.length > 0 &&
      new Set(i.rows.map((a) => a.code)).size === i.rows.length &&
      new Set(i.types).size === i.types.length &&
      new Set(i.selectedTypes).size === i.selectedTypes.length &&
      i.selectedTypes.every((t) => i.types.includes(t)) &&
      i.rows.every((a) => a.level === child && a.present <= a.barangays)
    );
  });
export type PresenceInput = z.infer<typeof presenceSchema>;
export function selectPresence(input: PresenceInput | null, areas: string[], order: PresenceOrder) {
  if (
    !input ||
    !presenceSchema.safeParse(input).success ||
    areas.some((c) => !input.rows.some((a) => a.code === c))
  )
    return null;
  const rows = input.rows
    .filter((a) => !areas.length || areas.includes(a.code))
    .map((a) => ({ ...a, share: a.barangays > 0 ? (100 * a.present) / a.barangays : null }))
    .sort((a, b) => {
      if (order !== "name") {
        if (a.share === null && b.share !== null) return 1;
        if (b.share === null && a.share !== null) return -1;
        const diff =
          order === "highest" ? (b.share ?? 0) - (a.share ?? 0) : (a.share ?? 0) - (b.share ?? 0);
        if (diff) return diff;
      }
      return a.name.localeCompare(b.name, "en");
    });
  return {
    rows,
    present: rows.reduce((s, a) => s + a.present, 0),
    barangays: rows.reduce((s, a) => s + a.barangays, 0),
  };
}
export type PresenceResult = NonNullable<ReturnType<typeof selectPresence>>;
export const PRESENCE_TITLE = "How many barangays have a recorded health facility?";
export const PRESENCE_READING_LIMIT = "People may also use facilities in nearby barangays.";
export function presenceReading(result: PresenceResult, filtered = false) {
  const facility = filtered ? "a recorded facility of the selected types" : "a recorded facility";
  if (!result.barangays)
    return "There is no barangay total for this selection, so a percentage cannot be shown.";
  const fraction = result.present / result.barangays;
  const ten = Math.round(fraction * 10);
  const sentence =
    ten > 0 && ten < 10
      ? `About ${ten} in every 10 barangays have ${facility}`
      : fraction === 0
        ? `No barangays in this selection have ${facility}`
        : fraction === 1
          ? `Every barangay in this selection has ${facility}`
          : `${fraction < 0.1 ? "Fewer than 1" : "More than 9"} in every 10 barangays have ${facility}`;
  return `${sentence} (${formatCount(result.present)} of ${formatCount(result.barangays)} barangays).`;
}
export const PRESENCE_NOTES = [
  "Presence means at least one facility in the September 2026 NHFR snapshot has a usable barangay code belonging to its recorded city or municipality. A barangay is counted once even when it has several facilities or selected types.",
  "Facility types use OR. Each area's denominator remains all its barangays, including those without a recorded facility. Selecting fewer areas does not change any area's percentage. The overall reading divides the selected areas' combined distinct barangay count by their combined barangay total; it is not an average of percentages.",
  "No facility recorded does not prove no facility exists. Facilities without usable barangay attribution remain in facility totals but do not contribute to barangay presence. Presence does not measure travel access, population need, staffing, services, quality or sufficient capacity.",
  "Bars use a fixed 0–100% scale. Zero barangays means a percentage is unavailable; a verified zero presence with a positive denominator is 0%. Missing or inconsistent records do not become zero. Unavailable percentages sort after available percentages.",
  "This card's area and facility-type selections are independent of other cards. Source geography, the complete child roster, unfiltered facility totals and barangay presence must reconcile before filtering or exporting.",
];
