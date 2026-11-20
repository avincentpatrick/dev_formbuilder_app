import { catalogHref } from "./navigation";
import type { CatalogId } from "./types";
import { mapHref, type MapChoice } from "@/lib/maps/prototype";

/** Selected child scope and validated map context are separate from catalog data filters. */
export function mapDetailsHref(choice: MapChoice, dataset: CatalogId, search = ""): string {
  const level = choice.selected
    ? choice.level === "province"
      ? "citymun"
      : choice.level === "region"
        ? "province"
        : choice.level === "citymun"
          ? "barangay"
          : "region"
    : choice.level;
  const query = new URLSearchParams(
    catalogHref({ mode: "psgc", level, code: choice.selected ?? choice.code }, dataset).split(
      "?",
    )[1],
  );
  query.set("mapState", mapHref(choice).split("?")[1] + search);
  return `/catalog?${query}${search}`;
}
