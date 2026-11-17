import type { CatalogSelection } from "@/lib/catalog/types";
import { catalogHref } from "@/lib/catalog/navigation";
import { mapHref, parseMapChoice, type MapChoice } from "./prototype";

const isBhw = (choice: MapChoice) =>
  choice.dataset === "bhw-stepzero-2026" || choice.dataset === "bhw-2025";

/** Carry a public analysis snapshot, never a redirect URL or data payload. */
export function bhwCatalogReturnHref(choice: MapChoice): string | null {
  if (!isBhw(choice)) return null;
  const query = new URLSearchParams(
    catalogHref({ mode: "psgc", level: choice.level, code: choice.code }, "bhw").split("?")[1],
  );
  query.set("mapState", mapHref(choice).split("?")[1]);
  return `/catalog?${query}`;
}

/** Rebuild the destination from validated settings so no arbitrary URL can become a link. */
export function restoredBhwMapHref(raw: string | string[] | undefined): string | null {
  if (typeof raw !== "string" || !raw || raw.length > 4096) return null;
  const choice = parseMapChoice(new URLSearchParams(raw));
  return choice && isBhw(choice) ? mapHref(choice) : null;
}

/** Open the shared dashboard at the map area and preserve the full map snapshot. */
export function bhwOverviewReturnHref(choice: MapChoice): string | null {
  if (!isBhw(choice)) return null;
  return bhwLocalityHref(
    { mode: "psgc", level: choice.level, code: choice.code },
    mapHref(choice).split("?")[1],
  );
}

/** Keep a validated map return snapshot when navigating back from comparison. */
export function bhwOverviewHref(raw?: string | string[]): string {
  const restored = restoredBhwMapHref(raw);
  if (!restored) return "/bhw";
  return `/bhw?${new URLSearchParams({ mapState: restored.split("?")[1] })}`;
}

/** Catalog scope stays independent of the validated map return snapshot. */
export function bhwScopedCatalogHref(
  selection: Extract<CatalogSelection, { mode: "psgc" }>,
  raw?: string | string[],
): string {
  const query = new URLSearchParams(catalogHref(selection, "bhw").split("?")[1]);
  const restored = restoredBhwMapHref(raw);
  if (restored) query.set("mapState", restored.split("?")[1]);
  return `/catalog?${query}`;
}

/** National methodology keeps its scope while carrying only validated return settings. */
export function bhwNationalCatalogHref(raw?: string | string[]): string {
  return bhwScopedCatalogHref({ mode: "psgc", level: "national", code: "PH" }, raw);
}

/** Dashboard geography is explicit; map filters never change its figures. */
export function bhwLocalityHref(
  selection: Extract<CatalogSelection, { mode: "psgc" }>,
  raw?: string | string[],
): string {
  const query = new URLSearchParams();
  if (selection.level !== "national") query.set("location", `${selection.level}:${selection.code}`);
  const restored = restoredBhwMapHref(raw);
  if (restored) query.set("mapState", restored.split("?")[1]);
  return query.size ? `/bhw?${query}` : "/bhw";
}
