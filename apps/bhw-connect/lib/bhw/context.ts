import { parseMapChoice, type MapChoice, type MapPayload } from "@/lib/maps/prototype";
import { validComparisonPayload } from "./map-comparison";

export type ContextChoice = { map: MapChoice; context: "households" | "population" };
export function parseContext(params: URLSearchParams): ContextChoice | null {
  const keys = ["level", "code", "context", "selected"];
  if (
    [...params.keys()].some((k) => !keys.includes(k)) ||
    keys.some((k) => params.getAll(k).length > 1)
  )
    return null;
  const context = params.get("context") ?? "households";
  if (context !== "households" && context !== "population") return null;
  const q = new URLSearchParams(params);
  q.delete("context");
  q.set("dataset", "bhw-stepzero-2026");
  const map = parseMapChoice(q);
  return map ? { map, context } : null;
}
export function contextHref(choice: ContextChoice) {
  const p = new URLSearchParams({
    level: choice.map.level,
    code: choice.map.code,
    context: choice.context,
  });
  if (choice.map.selected) p.set("selected", choice.map.selected);
  return `/bhw/context?${p}`;
}
export const contextLabels = { households: "Source households", population: "Source population" };
/** Same accepted source/date and exact child-code roster. No cross-release fallback or spatial inference. */
export function joinContext(head: unknown, context: unknown, scope: Pick<MapChoice, "level">) {
  if (!validComparisonPayload(head) || !validComparisonPayload(context)) return null;
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
    head.source !== context.source ||
    head.date !== "2025-01-01" ||
    context.date !== head.date ||
    head.valueSupport === "navigation-only" ||
    context.valueSupport === "navigation-only" ||
    head.rows.length !== context.rows.length
  )
    return null;
  const byCode = new Map(context.rows.map((r) => [r.code, r]));
  if (head.rows.some((r) => !byCode.has(r.code) || byCode.get(r.code)!.name !== r.name))
    return null;
  const usable = (r: MapPayload["rows"][number]) =>
    r.value !== null && Number.isSafeInteger(r.value) && r.value >= 0 && !r.smallSample;
  return head.rows.map((r) => {
    const x = byCode.get(r.code)!;
    return {
      code: r.code,
      name: r.name,
      headcount: usable(r) ? r.value : null,
      context: usable(x) ? x.value : null,
      eligible: usable(r) && usable(x),
    };
  });
}
