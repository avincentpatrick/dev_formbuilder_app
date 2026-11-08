import { getDatasetMap } from "./definitions";

export type MapScope = { level: "national" | "region" | "province"; code: string };
export type MapChoice = MapScope & {
  dataset: string;
  indicator: string;
  period: string;
  selected: string | null;
};
export type MapRow = {
  code: string;
  name: string;
  value: number | null;
  ciLow?: number | null;
  ciHigh?: number | null;
  note?: string;
};
export type MapPayload = {
  state: "available";
  rows: MapRow[];
  total: number | null;
  totalNote?: string;
  area: string;
  childLevel: "region" | "province" | "citymun";
  source: string;
  date: string | null;
  license: string | null;
  geometryUrl: string;
  valueSupport?: "supported" | "navigation-only";
};
export function prototypeIndicators(slug: string) {
  const supported: Record<string, readonly string[]> = {
    "uuc-phc-2025": [
      "uuc.listed",
      "uuc.route.ip",
      "uuc.route.conflict",
      "uuc.route.four_ps",
      "uuc.route.health",
    ],
    "nhfr-2026-09": [
      "nhfr.facilities",
      "nhfr.government",
      "nhfr.private",
      "nhfr.type.bhs",
      "nhfr.type.rhu",
      "nhfr.type.hospital",
      "nhfr.type.birthing-home",
      "nhfr.beds",
      "nhfr.barangay-presence",
    ],
    "bhw-2025": ["bhw.validated-profiles"],
    "bhw-stepzero-2026": [
      "bhw.total",
      "bhw.registered",
      "bhw.registered-accredited",
      "bhw.nonregistered",
      "stepzero.population",
      "stepzero.households",
    ],
    "psa-popcen-2024": ["population.residents"],
    "psa-sae-poverty-2023": ["poverty.incidence"],
    "lgu-income-class": ["income-class"],
    "bhw-profiling-status-2026": ["encoded", "validated", "attested", "notEncoded"],
  };
  const ids = supported[slug];
  return ids ? getDatasetMap(slug)!.indicators.filter((i) => ids.includes(i.id)) : [];
}
export function initialMapChoice(dataset: string): MapChoice {
  const first = prototypeIndicators(dataset)[0];
  return {
    dataset,
    indicator: first?.id ?? "",
    period: first?.period ?? "",
    level: "national",
    code: "PH",
    selected: null,
  };
}
export function parseMapChoice(params: URLSearchParams): MapChoice | null {
  const dataset = params.get("dataset");
  if (!dataset || !getDatasetMap(dataset)) return null;
  const base = initialMapChoice(dataset);
  const indicator = params.get("indicator") ?? base.indicator;
  const period = params.get("period") ?? base.period;
  const level = params.get("level") ?? "national";
  const code = params.get("code") ?? "PH";
  if (!["national", "region", "province"].includes(level)) return null;
  if (
    !(level === "national"
      ? code === "PH"
      : level === "region"
        ? /^\d{2}$/.test(code)
        : /^\d{5}$/.test(code))
  )
    return null;
  const metrics = prototypeIndicators(dataset);
  if (
    metrics.length
      ? !metrics.some((i) => i.id === indicator && i.period === period)
      : indicator !== "" || period !== "" || level !== "national"
  )
    return null;
  const selected = params.get("selected");
  if (selected && !/^\d{2,7}$/.test(selected)) return null;
  return { dataset, indicator, period, level: level as MapScope["level"], code, selected };
}
export function mapHref(choice: MapChoice) {
  const params = new URLSearchParams({ dataset: choice.dataset });
  if (choice.indicator) {
    params.set("indicator", choice.indicator);
    params.set("period", choice.period);
  }
  params.set("level", choice.level);
  params.set("code", choice.code);
  if (choice.selected) params.set("selected", choice.selected);
  return `/map?${params}`;
}
/** Preserve zero and missing rows; never coerce absent, invalid or duplicate values into zero. */
export function joinMapRows(
  children: { geo_code: string; geo_name: string }[],
  rows: Record<string, unknown>[],
  column: string,
): MapRow[] {
  const index = new Map<string, Record<string, unknown>[]>();
  for (const row of rows) {
    const code = String(row.geo_code);
    index.set(code, [...(index.get(code) ?? []), row]);
  }
  return children.map((c) => {
    const matches = index.get(c.geo_code) ?? [];
    const n = matches.length === 1 ? matches[0][column] : null;
    return {
      code: c.geo_code,
      name: c.geo_name,
      value: typeof n === "number" && Number.isSafeInteger(n) && n >= 0 ? n : null,
    };
  });
}
