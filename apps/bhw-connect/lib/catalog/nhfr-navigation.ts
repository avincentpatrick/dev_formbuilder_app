import { catalogHref } from "./navigation";
import { mapHref, parseMapChoice } from "@/lib/maps/prototype";

export function restoredNhfrMapHref(raw?: string | string[]): string | null {
  if (typeof raw !== "string" || !raw || raw.length > 4096) return null;
  const choice = parseMapChoice(new URLSearchParams(raw));
  return choice?.dataset === "nhfr-2026-09" ? mapHref(choice) : null;
}

export function nhfrOverviewHref(raw?: string | string[]): string {
  const query = new URLSearchParams(
    catalogHref({ mode: "psgc", level: "national", code: "PH" }, "nhfr").split("?")[1],
  );
  if (raw !== undefined) query.set("mapState", restoredNhfrMapHref(raw)?.split("?")[1] ?? "");
  return `/catalog?${query}`;
}
