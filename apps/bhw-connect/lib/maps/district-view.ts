import { computeQuantileBins, type ColorBin } from "@/lib/charts/color-scale";
import { isReleasePublic } from "@/lib/releases/visibility";
import { isCensusProfileRate } from "./census-accreditation";
import { getDatasetMap } from "./definitions";
import { POVERTY_BINS } from "./poverty";
import { prototypeIndicators, type MapRow } from "./prototype";
import { STEPZERO_ACCREDITATION_BINS, STEPZERO_ACCREDITATION_ID } from "./stepzero-accreditation";
import { MAP_SUBJECTS } from "./subjects";

/**
 * The legislative-district map, drawn through member cities and municipalities only
 * (docs/DECISIONS.md, 2026-11-11). No district has a validated outline (0 of 250), so this view never
 * dissolves members into a district shape and never adds members into a district total: each
 * municipality keeps its own polygon and its own published figure. Districts are slugs, not PSGC
 * codes; a municipality's district comes from `geo_district_map`, single-source and correctable.
 */
export const DISTRICT_MAP_SLUG = "ph-legislative-districts";

export type DistrictRef = { code: string; name: string };
export type MembershipRow = { district_code: string; geo_code: string; geo_level: string };
export type MunicipalityDistricts = {
  code: string;
  name: string;
  /** District codes, ordered by district name. Empty when the mapping places it nowhere. */
  districts: string[];
  /** Placed barangay by barangay: a city divided between districts, or one only partly placed. */
  byBarangay: boolean;
};

/** "Bohol's 3rd congressional district" → "Bohol 3rd"; any other shape is kept as it is. */
export function shortDistrictName(name: string): string {
  const match = /^(.+?)['’]s (.+?) congressional district$/i.exec(name.trim());
  if (!match) return name;
  return match[2].toLowerCase() === "lone" ? `${match[1]} (lone)` : `${match[1]} ${match[2]}`;
}

export function compareDistricts(a: DistrictRef, b: DistrictRef): number {
  return a.name.localeCompare(b.name, "en", { numeric: true });
}

/**
 * Which districts each municipality belongs to. A barangay row counts for the municipality it sits
 * in (the first seven digits of its PSGC code), so a city divided between districts lists all of them.
 */
export function assignMunicipalities(
  children: readonly { geo_code: string; geo_name: string }[],
  membership: readonly MembershipRow[],
  districts: readonly DistrictRef[],
): MunicipalityDistricts[] {
  const order = new Map(
    [...districts].sort(compareDistricts).map((d, index) => [d.code, index] as const),
  );
  const byMunicipality = new Map<string, { codes: Set<string>; byBarangay: boolean }>();
  for (const row of membership) {
    const level = row.geo_level;
    if (level !== "citymun" && level !== "barangay") continue;
    const code = level === "barangay" ? row.geo_code.slice(0, 7) : row.geo_code;
    const entry = byMunicipality.get(code) ?? { codes: new Set<string>(), byBarangay: false };
    entry.codes.add(row.district_code);
    entry.byBarangay ||= level === "barangay";
    byMunicipality.set(code, entry);
  }
  return children.map((child) => {
    const entry = byMunicipality.get(child.geo_code);
    const codes = [...(entry?.codes ?? [])].sort(
      (a, b) => (order.get(a) ?? Infinity) - (order.get(b) ?? Infinity) || a.localeCompare(b),
    );
    return {
      code: child.geo_code,
      name: child.geo_name,
      districts: codes,
      byBarangay: entry?.byBarangay ?? false,
    };
  });
}

/** Okabe–Ito first, then further distinct hues; a province holds at most about eight districts. */
export const DISTRICT_COLORS = [
  "#0072b2",
  "#e69f00",
  "#009e73",
  "#cc79a7",
  "#56b4e9",
  "#d55e00",
  "#f0e442",
  "#7f3c8d",
  "#3969ac",
  "#11a579",
  "#e73f74",
  "#80ba5a",
] as const;
/** A municipality divided between districts: one colour of its own, never one district's. */
export const SHARED_VALUE = 0;
export const SHARED_COLOR = "#6b7280";

function nameOf(districts: readonly DistrictRef[], code: string): string {
  const found = districts.find((d) => d.code === code);
  return found ? shortDistrictName(found.name) : code;
}

/** "A and B", "A, B and C". */
function listNames(names: string[]): string {
  return names.length <= 1
    ? (names[0] ?? "")
    : `${names.slice(0, -1).join(", ")} and ${names[names.length - 1]}`;
}

export function districtNote(m: MunicipalityDistricts, districts: readonly DistrictRef[]): string {
  if (m.districts.length === 0)
    return "No district recorded in the public mapping. That means no answer, not no district.";
  const names = m.districts.map((code) => nameOf(districts, code));
  if (m.districts.length > 1) return `Divided between ${listNames(names)}, barangay by barangay.`;
  return m.byBarangay
    ? `${names[0]}, placed barangay by barangay; barangays the mapping has not placed are not shown.`
    : names[0];
}

/**
 * View 1, a province by district: each municipality coloured by its district, a divided one in the
 * shared colour, an unplaced one left grey. The value is a category index, never a figure.
 */
export function provinceDistrictLayer(
  municipalities: readonly MunicipalityDistricts[],
  districts: readonly DistrictRef[],
): { rows: MapRow[]; bins: ColorBin[]; labels: Record<number, string>; present: DistrictRef[] } {
  const presentCodes = new Set(municipalities.flatMap((m) => m.districts));
  const present = districts.filter((d) => presentCodes.has(d.code)).sort(compareDistricts);
  const index = new Map(present.map((d, i) => [d.code, i + 1] as const));
  const bins: ColorBin[] = [];
  const labels: Record<number, string> = {};
  if (municipalities.some((m) => m.districts.length > 1)) {
    bins.push({ min: SHARED_VALUE, max: SHARED_VALUE, color: SHARED_COLOR });
    labels[SHARED_VALUE] = "Divided between districts";
  }
  present.forEach((d, i) => {
    bins.push({ min: i + 1, max: i + 1, color: DISTRICT_COLORS[i % DISTRICT_COLORS.length] });
    labels[i + 1] = shortDistrictName(d.name);
  });
  const rows = municipalities.map((m) => ({
    code: m.code,
    name: m.name,
    value:
      m.districts.length === 0
        ? null
        : m.districts.length > 1
          ? SHARED_VALUE
          : (index.get(m.districts[0]) ?? null),
    note: districtNote(m, districts),
  }));
  return { rows, bins, labels, present };
}

/** Below every published figure: counts and shares are never negative. */
export const OUTSIDE_VALUE = -1;
export const OUTSIDE_COLOR = "#f3f4f6";

/** Fixed breaks where the other maps use fixed breaks, so one figure reads the same everywhere. */
export function memberBins(
  dataset: string,
  indicator: string,
  values: (number | null)[],
): ColorBin[] {
  if (
    (dataset === "bhw-stepzero-2026" && indicator === STEPZERO_ACCREDITATION_ID) ||
    (dataset === "bhw-2025" && isCensusProfileRate(indicator))
  )
    return STEPZERO_ACCREDITATION_BINS;
  if (dataset === "psa-sae-poverty-2023") return POVERTY_BINS;
  return computeQuantileBins(values);
}

/**
 * View 2, one district: its member municipalities shaded by their own published figures, every other
 * municipality in the province drawn pale as context. `table` lists members only; the figure of a
 * divided city is the whole city's, and says so.
 */
export function districtMemberLayer(
  valueRows: readonly MapRow[],
  municipalities: readonly MunicipalityDistricts[],
  districtCode: string,
  districts: readonly DistrictRef[],
  bins: (memberValues: (number | null)[]) => ColorBin[],
): { canvasRows: MapRow[]; table: MapRow[]; bins: ColorBin[] } {
  const values = new Map(valueRows.map((r) => [r.code, r]));
  const table: MapRow[] = municipalities
    .filter((m) => m.districts.includes(districtCode))
    .map((m) => {
      const row = values.get(m.code);
      const divided =
        m.districts.length > 1
          ? `Whole-city figure: the city is ${districtNote(m, districts).replace(/^Divided/, "divided")}`
          : m.byBarangay
            ? "Whole-city figure; the mapping places this city barangay by barangay."
            : undefined;
      return {
        code: m.code,
        name: m.name,
        value: row?.value ?? null,
        ...(row?.ciLow !== undefined ? { ciLow: row.ciLow, ciHigh: row.ciHigh } : {}),
        ...(row?.smallSample ? { smallSample: true } : {}),
        note: [row?.note, divided].filter(Boolean).join(" ") || undefined,
      };
    });
  const members = new Map(table.map((r) => [r.code, r]));
  const memberScale = bins(table.map((r) => r.value));
  return {
    table,
    bins: [{ min: OUTSIDE_VALUE, max: OUTSIDE_VALUE, color: OUTSIDE_COLOR }, ...memberScale],
    canvasRows: municipalities.map((m) => {
      const member = members.get(m.code);
      return member ?? { code: m.code, name: m.name, value: OUTSIDE_VALUE };
    }),
  };
}

export type DistrictIndicatorOption = {
  dataset: string;
  datasetTitle: string;
  indicator: string;
  label: string;
  period: string;
  unit: string;
};

/**
 * The published municipal figures a district view can show: every public release's map indicator
 * that exists at city/municipality level. Income classes are categories, not figures, and stay on
 * their own map.
 */
export function districtIndicatorOptions(): DistrictIndicatorOption[] {
  return MAP_SUBJECTS.flatMap((s) => s.releases)
    .filter((slug) => slug !== DISTRICT_MAP_SLUG && slug !== "lgu-income-class")
    .filter((slug) => getDatasetMap(slug) && isReleasePublic(slug))
    .flatMap((slug) =>
      prototypeIndicators(slug)
        .filter((i) => i.levels.includes("citymun") && i.catalogSpec)
        .map((i) => ({
          dataset: slug,
          datasetTitle: getDatasetMap(slug)!.title,
          indicator: i.id,
          label: i.label,
          period: i.period,
          unit: i.unit,
        })),
    );
}

export type DistrictView = {
  view: "province" | "district";
  province: string | null;
  district: string | null;
  dataset: string;
  indicator: string;
};

/** A shareable district-map state, from `/map?dataset=ph-legislative-districts&…`. */
export function parseDistrictView(params: URLSearchParams): DistrictView {
  const options = districtIndicatorOptions();
  const fallback = options[0];
  const province = params.get("province");
  const district = params.get("district");
  const option =
    options.find(
      (o) => o.dataset === params.get("figures") && o.indicator === params.get("figure"),
    ) ?? fallback;
  const validDistrict = district && /^[a-z0-9][a-z0-9-]{1,80}$/.test(district) ? district : null;
  return {
    view: params.get("view") === "district" && validDistrict ? "district" : "province",
    province: province && /^\d{5}$/.test(province) ? province : null,
    district: validDistrict,
    dataset: option?.dataset ?? "",
    indicator: option?.indicator ?? "",
  };
}

export function districtViewHref(state: DistrictView): string {
  const params = new URLSearchParams({ dataset: DISTRICT_MAP_SLUG, level: "national", code: "PH" });
  params.set("view", state.view);
  if (state.province) params.set("province", state.province);
  if (state.district) params.set("district", state.district);
  if (state.view === "district") {
    params.set("figures", state.dataset);
    params.set("figure", state.indicator);
  }
  return `/map?${params}`;
}
