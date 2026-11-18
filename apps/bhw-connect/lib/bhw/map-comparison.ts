import {
  prototypeIndicators,
  parseMapChoice,
  type MapChoice,
  type MapPayload,
} from "@/lib/maps/prototype";
import { countClassificationBins, percentageClassificationBins } from "@/lib/maps/classification";
import { restoredBhwMapHref } from "@/lib/maps/catalog-return";
import { withBasePath } from "@/lib/base-path";

export const comparisonMapMeasures = ["bhw-stepzero-2026", "bhw-2025"].flatMap((dataset) =>
  prototypeIndicators(dataset)
    .filter((m) => m.id.startsWith("bhw."))
    .map((metric) => ({ dataset, metric })),
);
export type MapComparison = {
  a: MapChoice;
  b: MapChoice;
  focus: "a" | "b";
  geos: string[];
  returnFocus?: string;
  mapState?: string;
};

export function parseMapComparison(params: URLSearchParams): MapComparison | null {
  const keys = [
    "measure",
    "a",
    "b",
    "selectedA",
    "selectedB",
    "focus",
    "geos",
    "returnFocus",
    "mapState",
  ];
  if (
    [...params.keys()].some((k) => !keys.includes(k)) ||
    keys.some((k) => params.getAll(k).length > 1)
  )
    return null;
  const measure = params.get("measure");
  const entry = comparisonMapMeasures.find(({ metric }) => metric.id === measure);
  if (!entry) return null;
  const scope = (key: "a" | "b") => {
    const parts = params.get(key)?.split(":");
    if (parts?.length !== 2) return null;
    const choice = new URLSearchParams({
      dataset: entry.dataset,
      indicator: entry.metric.id,
      period: entry.metric.period,
      level: parts[0],
      code: parts[1],
      view: "choropleth",
    });
    const selected = params.get(key === "a" ? "selectedA" : "selectedB");
    if (selected !== null) choice.set("selected", selected);
    return parseMapChoice(choice);
  };
  const a = scope("a"),
    b = scope("b");
  const focus = params.get("focus") ?? "a";
  if (!a || !b || a.level !== b.level || a.code === b.code || (focus !== "a" && focus !== "b"))
    return null;
  const geos = params.has("geos") ? params.get("geos")!.split(",") : [a.code, b.code];
  if (
    geos.length > 4 ||
    geos.length < 2 ||
    new Set(geos).size !== geos.length ||
    geos.some((c) => !/^\d{2,10}$/.test(c))
  )
    return null;
  const returnFocus = params.get("returnFocus") ?? undefined;
  if (returnFocus && !geos.includes(returnFocus)) return null;
  const mapState = params.get("mapState");
  const restored = restoredBhwMapHref(mapState ?? undefined);
  if (mapState !== null && !restored) return null;
  return { a, b, focus, geos, returnFocus, mapState: restored?.split("?")[1] };
}

export function mapComparisonHref(choice: MapComparison) {
  const params = new URLSearchParams({
    measure: choice.a.indicator,
    a: `${choice.a.level}:${choice.a.code}`,
    b: `${choice.b.level}:${choice.b.code}`,
    focus: choice.focus,
    geos: choice.geos.join(","),
  });
  if (choice.a.selected) params.set("selectedA", choice.a.selected);
  if (choice.b.selected) params.set("selectedB", choice.b.selected);
  if (choice.returnFocus) params.set("returnFocus", choice.returnFocus);
  if (choice.mapState) params.set("mapState", choice.mapState);
  return `/bhw/compare-maps?${params}`;
}

/** One set of breaks for BOTH independent geographic units. Never average
 * parent rates/means or exclude areas merely because their geometry is missing. */
export function comparisonBins(unit: string, a: MapPayload, b: MapPayload) {
  const values = [...a.rows, ...b.rows].map((r) => r.value);
  return unit === "%"
    ? percentageClassificationBins(
        { classification: "custom", classBreaks: [20, 40, 60, 80] },
        values,
      )
    : countClassificationBins({ classification: "quantile", classCount: 5 }, values);
}

export function comparableMapPayloads(a: MapPayload, b: MapPayload) {
  return (
    a.childLevel === b.childLevel &&
    a.source === b.source &&
    a.date === b.date &&
    a.valueSupport !== "navigation-only" &&
    b.valueSupport !== "navigation-only"
  );
}

export function validComparisonPayload(value: unknown, unit = ""): value is MapPayload {
  if (!value || typeof value !== "object") return false;
  const p = value as MapPayload;
  return (
    p.state === "available" &&
    typeof p.source === "string" &&
    typeof p.area === "string" &&
    (p.date === null || typeof p.date === "string") &&
    (p.license === null || typeof p.license === "string") &&
    (p.navigation === undefined ||
      (p.navigation !== null &&
        typeof p.navigation.complete === "boolean" &&
        Array.isArray(p.navigation.parents) &&
        p.navigation.parents.every(
          (parent) =>
            parent &&
            typeof parent.code === "string" &&
            typeof parent.name === "string" &&
            ["national", "region", "province", "citymun"].includes(parent.level),
        ))) &&
    ["region", "province", "citymun", "barangay"].includes(p.childLevel) &&
    typeof p.geometryUrl === "string" &&
    [withBasePath("/geo/"), withBasePath("/maps/")].some((prefix) =>
      p.geometryUrl.startsWith(prefix),
    ) &&
    Array.isArray(p.rows) &&
    new Set(p.rows.map((r) => r?.code)).size === p.rows.length &&
    p.rows.every(
      (r) =>
        r &&
        typeof r.code === "string" &&
        /^\d{2,10}$/.test(r.code) &&
        typeof r.name === "string" &&
        (r.value === null ||
          (typeof r.value === "number" &&
            Number.isFinite(r.value) &&
            r.value >= 0 &&
            (unit !== "%" || r.value <= 100))) &&
        (r.note === undefined || typeof r.note === "string"),
    )
  );
}

export function drillComparison(
  choice: MapComparison,
  a: MapPayload,
  b: MapPayload,
): MapComparison | null {
  if (!comparableMapPayloads(a, b) || a.childLevel === "barangay") return null;
  if (choice.a.selected === choice.b.selected) return null;
  if (a.childLevel === "citymun" && (!a.barangayDrill || !b.barangayDrill)) return null;
  if (
    !a.rows.some((r) => r.code === choice.a.selected) ||
    !b.rows.some((r) => r.code === choice.b.selected)
  )
    return null;
  return {
    ...choice,
    a: { ...choice.a, level: a.childLevel, code: choice.a.selected!, selected: null },
    b: { ...choice.b, level: a.childLevel, code: choice.b.selected!, selected: null },
  };
}

export function parentComparison(
  choice: MapComparison,
  a: MapPayload,
  b: MapPayload,
): MapComparison | null {
  const level =
    choice.a.level === "citymun"
      ? "province"
      : choice.a.level === "province"
        ? "region"
        : "national";
  const pa = a.navigation?.parents.find((p) => p.level === level);
  const pb = b.navigation?.parents.find((p) => p.level === level);
  if (!a.navigation?.complete || !b.navigation?.complete || !pa || !pb || pa.code === pb.code)
    return null;
  return {
    ...choice,
    a: { ...choice.a, level, code: pa.code, selected: null },
    b: { ...choice.b, level, code: pb.code, selected: null },
  };
}
