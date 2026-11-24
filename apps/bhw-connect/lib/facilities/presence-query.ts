import { parseCatalogParams } from "@/lib/catalog/navigation";
export function parsePresenceQuery(params: URLSearchParams) {
  const parsed = parseCatalogParams(Object.fromEntries(params));
  const types = [...new Set(params.getAll("type"))].sort();
  const areas = [...new Set(params.getAll("area"))].sort();
  const order = params.get("order") ?? "lowest";
  if (
    !parsed ||
    parsed.selection.mode !== "psgc" ||
    [
      "mode",
      "location",
      "dataset",
      "order",
      "notes",
      "format",
      "district",
      "citymun",
      "barangay",
    ].some((k) => params.getAll(k).length > 1) ||
    types.length > 200 ||
    types.some((t) => !t || t.length > 200) ||
    areas.length > 1000 ||
    areas.some((a) => !/^\d{2,10}$/.test(a)) ||
    (order !== "highest" && order !== "lowest" && order !== "name")
  )
    return null;
  const { selection } = parsed;
  if (
    selection.level !== "national" &&
    selection.level !== "region" &&
    selection.level !== "province"
  )
    return null;
  return {
    code: selection.code,
    level: selection.level,
    types,
    areas,
    order: order as "highest" | "lowest" | "name",
  };
}
