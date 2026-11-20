import { parseMapChoice, type MapChoice, type MapPayload } from "@/lib/maps/prototype";
import { validComparisonPayload } from "./map-comparison";

export const contextDefinitions = {
  households: {
    label: "Source households",
    unit: "households",
    dataset: "bhw-stepzero-2026",
    indicator: "stepzero.households",
    period: "2025",
    date: "2025-01-01",
    external: false,
    note: "Households reported by the same Headcount source, not assigned households per individual BHW.",
  },
  population: {
    label: "Source population",
    unit: "people",
    dataset: "bhw-stepzero-2026",
    indicator: "stepzero.population",
    period: "2025",
    date: "2025-01-01",
    external: false,
    note: "Population reported by the same Headcount source; not a PSA census substitute.",
  },
  "psa-population": {
    label: "PSA population 2024",
    unit: "people",
    dataset: "psa-popcen-2024",
    indicator: "population.residents",
    period: "2024",
    date: "2024-07-01",
    external: true,
    note: "PSA POPCEN 2024 residents were matched at ingestion to the BHW reporting geography. Regional/provincial figures are sums of matched municipalities, not the published PSA totals. LGUs absent from the reporting roster remain outside this universe. Manila's census total is stored at its province-level node; its districts have no census substitute.",
  },
  poverty: {
    label: "PSA poverty incidence 2023",
    unit: "%",
    dataset: "psa-sae-poverty-2023",
    indicator: "poverty.incidence",
    period: "2023",
    date: "2023-12-31",
    external: true,
    note: "PSA SAE 2023 estimates the share of people below the poverty threshold. Accepted ingestion maps source codes/names to the reporting geography, including NIR. HUCs, Special Geographic Areas, Pateros and Kalayaan have documented source gaps. Manila's differently grouped districts are excluded from this relationship. Published 90% confidence bounds are shown when available; they are not clamped to 0–100%.",
  },
} as const;
export type ContextId = keyof typeof contextDefinitions;
export const contextIds = Object.keys(contextDefinitions) as ContextId[];
export const contextLabels = Object.fromEntries(
  contextIds.map((id) => [id, contextDefinitions[id].label]),
) as Record<ContextId, string>;
export type ContextChoice = { map: MapChoice; context: ContextId; view?: "bivariate" };
export function contextSupported(context: ContextId, scope: Pick<MapChoice, "level" | "code">) {
  if (context === "poverty") return scope.level === "province" && scope.code !== "13806";
  return context !== "psa-population" || scope.level !== "citymun";
}
export function parseContext(params: URLSearchParams): ContextChoice | null {
  const keys = ["level", "code", "context", "selected", "view"];
  if (
    [...params.keys()].some((k) => !keys.includes(k)) ||
    keys.some((k) => params.getAll(k).length > 1)
  )
    return null;
  const context = params.get("context") ?? "households";
  if (!contextIds.includes(context as ContextId)) return null;
  const id = context as ContextId;
  const view = params.get("view");
  if (view !== null && view !== "scatter" && view !== "bivariate") return null;
  if (view === "bivariate" && contextDefinitions[id].external) return null;
  const q = new URLSearchParams(params);
  q.delete("context");
  q.delete("view");
  q.set("dataset", "bhw-stepzero-2026");
  const map = parseMapChoice(q);
  return map && contextSupported(id, map)
    ? { map, context: id, ...(view === "bivariate" ? { view } : {}) }
    : null;
}
export function contextHref(choice: ContextChoice) {
  const p = new URLSearchParams({
    level: choice.map.level,
    code: choice.map.code,
    context: choice.context,
  });
  if (choice.map.selected) p.set("selected", choice.map.selected);
  if (choice.view) p.set("view", choice.view);
  return `/bhw/context?${p}`;
}
/** Exact canonical reporting-code roster; only audited accepted contexts and explicit dates. */
export function joinContext(
  head: unknown,
  context: unknown,
  scope: Pick<MapChoice, "level"> & Partial<Pick<MapChoice, "code">>,
  id: ContextId = "households",
) {
  const meta = contextDefinitions[id];
  if (
    !contextSupported(id, { level: scope.level, code: scope.code ?? "" }) ||
    !validComparisonPayload(head) ||
    !validComparisonPayload(context, meta.unit)
  )
    return null;
  const child = {
    national: "region",
    region: "province",
    province: "citymun",
    citymun: "barangay",
  }[scope.level];
  if (
    head.childLevel !== child ||
    context.childLevel !== child ||
    head.area !== context.area ||
    (!meta.external && head.source !== context.source) ||
    !head.source.trim() ||
    !context.source.trim() ||
    head.date !== "2025-01-01" ||
    context.date !== meta.date ||
    head.valueSupport === "navigation-only" ||
    context.valueSupport === "navigation-only" ||
    head.rows.length !== context.rows.length
  )
    return null;
  const byCode = new Map(context.rows.map((r) => [r.code, r]));
  if (head.rows.some((r) => !byCode.has(r.code) || byCode.get(r.code)!.name !== r.name))
    return null;
  const usable = (r: MapPayload["rows"][number], percent = false) =>
    r.value !== null &&
    (percent ? Number.isFinite(r.value) && r.value <= 100 : Number.isSafeInteger(r.value)) &&
    r.value >= 0 &&
    !r.smallSample;
  return head.rows.map((r) => {
    const x = byCode.get(r.code)!;
    const valid = usable(x, meta.unit === "%");
    const interval =
      valid && Number.isFinite(x.ciLow) && Number.isFinite(x.ciHigh) && x.ciLow! <= x.ciHigh!;
    return {
      code: r.code,
      name: r.name,
      headcount: usable(r) ? r.value : null,
      context: valid ? x.value : null,
      eligible: usable(r) && valid,
      ...(meta.unit === "%"
        ? { ciLow: interval ? x.ciLow! : null, ciHigh: interval ? x.ciHigh! : null }
        : {}),
    };
  });
}
