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

/** The overview keeps its national figures; the snapshot is only a way back to the map. */
export function bhwOverviewReturnHref(choice: MapChoice): string | null {
  if (!isBhw(choice)) return null;
  const query = new URLSearchParams({ mapState: mapHref(choice).split("?")[1] });
  return `/bhw?${query}`;
}
