import { selectDistribution, type DistributionInput } from "./distribution";
import { presenceSchema, PRESENCE_NOTES, type PresenceInput } from "./presence";
export const AREA_ORDERS = [
  "highest",
  "lowest",
  "presence-highest",
  "presence-lowest",
  "name",
] as const;
export type AreaOrder = (typeof AREA_ORDERS)[number];
export function selectAreaFigures(
  input: DistributionInput | null,
  presence: PresenceInput | null,
  areas: string[],
  types: string[],
  order: AreaOrder,
  search = "",
) {
  const distribution = selectDistribution(input, areas, types, "name");
  if (
    !input ||
    !distribution ||
    !presence ||
    !presenceSchema.safeParse(presence).success ||
    input.code !== presence.code ||
    input.level !== presence.level ||
    JSON.stringify([...new Set(types)].sort()) !==
      JSON.stringify([...presence.selectedTypes].sort()) ||
    presence.rows.length !== input.areas.length ||
    input.areas.some((a) => !presence.rows.some((p) => p.code === a.code && p.level === a.level))
  )
    return null;
  // Validate the full matching-type roster before applying display filters or search.
  const full = selectDistribution(input, [], types, "name")!;
  if (full.rows.some((a) => presence.rows.find((p) => p.code === a.code)!.present > a.count))
    return null;
  const term = search.trim().toLocaleLowerCase("en");
  const rows = distribution.rows
    .filter((a) => !term || a.name.toLocaleLowerCase("en").includes(term))
    .map((a) => {
      const p = presence.rows.find((p) => p.code === a.code)!;
      return {
        ...a,
        present: p.present,
        barangays: p.barangays,
        facilityShare:
          distribution.parent.count > 0 ? (100 * a.count) / distribution.parent.count : null,
        presenceShare: p.barangays > 0 ? (100 * p.present) / p.barangays : null,
      };
    })
    .sort((a, b) => {
      if (order === "presence-highest" || order === "presence-lowest") {
        if (a.presenceShare === null && b.presenceShare !== null) return 1;
        if (b.presenceShare === null && a.presenceShare !== null) return -1;
        const diff =
          order === "presence-highest"
            ? (b.presenceShare ?? 0) - (a.presenceShare ?? 0)
            : (a.presenceShare ?? 0) - (b.presenceShare ?? 0);
        if (diff) return diff;
      } else if (order !== "name") {
        const diff = order === "highest" ? b.count - a.count : a.count - b.count;
        if (diff) return diff;
      }
      return a.name.localeCompare(b.name, "en");
    });
  return {
    rows,
    parent: distribution.parent.count,
    selectedAreas: distribution.rows.length,
    count: rows.reduce((n, a) => n + a.count, 0),
    present: rows.reduce((n, a) => n + a.present, 0),
    barangays: rows.reduce((n, a) => n + a.barangays, 0),
  };
}
export type AreaFigures = NonNullable<ReturnType<typeof selectAreaFigures>>;
export type AreaFigureRow = AreaFigures["rows"][number];
export const AREA_NOTES = [
  "Facility share uses all facilities of the selected types across the full parent location. Barangay share uses each area's own barangay total. Filtering displayed areas or searching names changes neither denominator.",
  "The exact table follows the geographic distribution card's area/type selections. Its name search and row order affect the table only. While the table is open, Export and Present use the matching table rows. The separate barangay-presence card keeps its own selections.",
  PRESENCE_NOTES[0],
  PRESENCE_NOTES[2],
  "A zero parent facility total has no facility share. Zero barangays means no barangay share; a verified zero numerator with a positive denominator is 0%. Missing or inconsistent data never becomes zero. Unavailable barangay shares sort last in percentage orders.",
];
