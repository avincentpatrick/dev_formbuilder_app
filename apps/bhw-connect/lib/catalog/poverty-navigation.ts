import { catalogHref } from "./navigation";
import { initialMapChoice, mapHref, parseMapChoice, type MapChoice } from "@/lib/maps/prototype";

export const POVERTY_MAP = "psa-sae-poverty-2023";
export function povertyMapChoice(province: string, selected: string | null = null): MapChoice {
  return { ...initialMapChoice(POVERTY_MAP), level: "province", code: province, selected };
}
export function restoredPovertyMapHref(raw?: string | string[]): string | null {
  if (typeof raw !== "string" || raw.length > 4096) return null;
  const choice = parseMapChoice(new URLSearchParams(raw));
  return choice?.dataset === POVERTY_MAP
    ? mapHref(choice) + povertySearchSuffix(new URLSearchParams(raw))
    : null;
}
export function povertySearchSuffix(params: Pick<URLSearchParams, "get">): string {
  const province = params.get("povertyProvince");
  const search = params.get("povertySearch");
  return province && /^\d{5}$/.test(province) && search && search.length <= 100
    ? `&${new URLSearchParams({ povertyProvince: province, povertySearch: search })}`
    : "";
}

export function povertyCatalogReturnHref(choice: MapChoice, search = ""): string {
  const level = choice.selected
    ? choice.level === "province"
      ? "citymun"
      : choice.level === "region"
        ? "province"
        : "region"
    : choice.level;
  const query = new URLSearchParams(
    catalogHref({ mode: "psgc", level, code: choice.selected ?? choice.code }, "poverty").split(
      "?",
    )[1],
  );
  query.set("mapState", mapHref(choice).split("?")[1] + search);
  return `/catalog?${query}${search}`;
}
