import { catalogHref } from "./navigation";
import type { CatalogId, CatalogSelection } from "./types";
import { bhwOverviewHref, restoredBhwMapHref } from "@/lib/maps/catalog-return";

/** A BHW return snapshot is navigation context, never a catalog data filter. */
export function catalogJourneyHref(
  selection: CatalogSelection,
  dataset?: CatalogId | null,
  raw?: string | string[],
): string {
  if (selection.mode === "all" && dataset === "bhw") return bhwOverviewHref(raw);
  const query = new URLSearchParams(catalogHref(selection, dataset).split("?")[1]);
  const restored = !dataset || dataset === "bhw" ? restoredBhwMapHref(raw) : null;
  if (restored) query.set("mapState", restored.split("?")[1]);
  return `/catalog?${query}`;
}
