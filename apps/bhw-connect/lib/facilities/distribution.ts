import type { GeoLevel } from "@/lib/filters/schema";
import { validCount } from "./analysis";
import { formatCount, formatPct } from "@/lib/format";
export type DistributionLevel = "national" | "region" | "province";
export type DistributionOrder = "highest" | "lowest" | "name";
export type InventoryCount = { count: number; government: number; private: number };
export type DistributionArea = InventoryCount & { code: string; name: string; level: GeoLevel };
export type DistributionType = InventoryCount & { type: string };
export type DistributionCell = DistributionType & { code: string };
export type DistributionInput = {
  code: string;
  level: DistributionLevel;
  name: string;
  total: InventoryCount;
  areas: DistributionArea[];
  types: DistributionType[];
  cells: DistributionCell[];
};
export const DISTRIBUTION_NOTES = [
  "Counts describe registered facilities in the September 2026 NHFR snapshot. They are not adjusted for population or need and do not establish adequate provision, operating services or care quality.",
  "An area's share uses all facilities of the selected types in the full parent location. Selecting fewer areas does not change that denominator. When that total is zero, shares are unavailable rather than zero percent.",
  "Bars share a count scale from zero to the largest count in the full parent roster for the selected facility types. Government and private describe ownership, not cost or eligibility for care.",
  "Facility types use OR. Area/type cells are checked against every area's counts and the parent's complete type and ownership totals before an absent type row can be interpreted as zero. Failed or incomplete reads do not become zero.",
  "These filters change this geographic comparison only. Barangay presence is a separate measure; a high facility count is not evidence of widespread presence or travel access.",
];
const empty = (): InventoryCount => ({ count: 0, government: 0, private: 0 });
const valid = (r: InventoryCount) =>
  [r.count, r.government, r.private].every(validCount) && r.government + r.private === r.count;
const add = (a: InventoryCount, b: InventoryCount) => {
  a.count += b.count;
  a.government += b.government;
  a.private += b.private;
};
const equal = (a: InventoryCount, b: InventoryCount) =>
  a.count === b.count && a.government === b.government && a.private === b.private;
export function validateDistribution(input: DistributionInput | null): input is DistributionInput {
  if (!input || !valid(input.total) || !input.areas.length || !input.code || !input.name)
    return false;
  const level = { national: "region", region: "province", province: "citymun" }[input.level];
  if (
    !level ||
    new Set(input.areas.map((a) => a.code)).size !== input.areas.length ||
    new Set(input.types.map((t) => t.type)).size !== input.types.length
  )
    return false;
  const areas = new Map(input.areas.map((a) => [a.code, empty()]));
  const types = new Map(input.types.map((t) => [t.type, empty()]));
  const seen = new Set<string>();
  const areaTotal = empty(),
    typeTotal = empty();
  for (const a of input.areas) {
    if (!a.code || !a.name || a.level !== level || !valid(a)) return false;
    add(areaTotal, a);
  }
  for (const t of input.types) {
    if (!t.type || !valid(t)) return false;
    add(typeTotal, t);
  }
  if (!equal(areaTotal, input.total) || !equal(typeTotal, input.total)) return false;
  for (const cell of input.cells) {
    const area = areas.get(cell.code),
      type = types.get(cell.type);
    const key = JSON.stringify([cell.code, cell.type]);
    if (!area || !type || seen.has(key) || !valid(cell)) return false;
    seen.add(key);
    add(area, cell);
    add(type, cell);
  }
  return (
    input.areas.every((a) => equal(a, areas.get(a.code)!)) &&
    input.types.every((t) => equal(t, types.get(t.type)!))
  );
}
export function selectDistribution(
  input: DistributionInput | null,
  selectedAreas: string[],
  selectedTypes: string[],
  order: DistributionOrder,
) {
  if (
    !validateDistribution(input) ||
    selectedAreas.some((code) => !input.areas.some((a) => a.code === code)) ||
    selectedTypes.some((type) => !input.types.some((t) => t.type === type))
  )
    return null;
  const all = new Map(input.areas.map((a) => [a.code, { ...a, ...empty() }]));
  const parent = empty();
  for (const t of input.types)
    if (!selectedTypes.length || selectedTypes.includes(t.type)) add(parent, t);
  for (const cell of input.cells)
    if (!selectedTypes.length || selectedTypes.includes(cell.type)) add(all.get(cell.code)!, cell);
  const rows = [...all.values()]
    .filter((a) => !selectedAreas.length || selectedAreas.includes(a.code))
    .sort(
      (a, b) =>
        (order === "highest" ? b.count - a.count : order === "lowest" ? a.count - b.count : 0) ||
        a.name.localeCompare(b.name, "en"),
    );
  return {
    rows,
    parent,
    maximum: Math.max(...[...all.values()].map((a) => a.count)),
    selectedCount: rows.reduce((n, a) => n + a.count, 0),
  };
}
export type DistributionResult = NonNullable<ReturnType<typeof selectDistribution>>;
export function distributionReading(result: DistributionResult, selected: boolean, name: string) {
  const location = name === "Philippines" ? "the Philippines" : name;
  if (result.parent.count === 0)
    return `No facilities in this selection are recorded in ${location} in this snapshot.`;
  if (selected)
    return `The selected areas account for ${formatCount(result.selectedCount)} of ${formatCount(result.parent.count)} facilities in ${location} (${formatPct((100 * result.selectedCount) / result.parent.count, "single")}).`;
  const top = [...result.rows]
    .sort((a, b) => b.count - a.count || a.name.localeCompare(b.name, "en"))
    .slice(0, 3);
  const leaders = result.rows.filter((a) => a.count === top[0].count);
  if (leaders.length > 1)
    return `${leaders.length} areas share the largest recorded count, with ${formatCount(top[0].count)} facilities each.`;
  if (
    result.rows.length <= 3 ||
    result.rows.filter((a) => a.count >= top[top.length - 1].count).length > top.length
  )
    return `${top[0].name} has the largest recorded count: ${formatCount(top[0].count)} facilities (${formatPct((100 * top[0].count) / result.parent.count, "single")}).`;
  const count = top.reduce((n, a) => n + a.count, 0);
  return top.length === 1
    ? `${top[0].name} accounts for ${formatCount(count)} of ${formatCount(result.parent.count)} facilities in ${location}.`
    : `The ${top.length} areas with the most facilities together account for ${formatPct((100 * count) / result.parent.count, "single")} of facilities in ${location}.`;
}

export function distributionTitle(level: string) {
  return level === "national"
    ? "How are facilities distributed across regions?"
    : level === "region"
      ? "How are facilities distributed across provinces?"
      : "How are facilities distributed across cities and municipalities?";
}
