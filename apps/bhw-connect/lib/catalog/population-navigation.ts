import { mapDetailsHref } from "./map-details-navigation";
import { mapHref, parseMapChoice, type MapChoice } from "@/lib/maps/prototype";

export const POPULATION_MAP = "psa-popcen-2024";
/** Search is scoped to the currently displayed child list, never a population filter. */
export function populationSearch(params: URLSearchParams, choice: MapChoice): string {
  const text = params.get("populationSearch");
  return params.getAll("populationSearch").length === 1 &&
    params.getAll("populationScope").length === 1 &&
    params.get("populationScope") === `${choice.level}:${choice.code}` &&
    text &&
    text.length <= 100
    ? text
    : "";
}
export function populationSearchSuffix(choice: MapChoice, text: string): string {
  return text && text.length <= 100
    ? `&${new URLSearchParams({ populationScope: `${choice.level}:${choice.code}`, populationSearch: text })}`
    : "";
}
export function restoredPopulationMapHref(raw?: string | string[]): string | null {
  if (typeof raw !== "string" || raw.length > 4096) return null;
  const params = new URLSearchParams(raw);
  const choice = parseMapChoice(params);
  return choice?.dataset === POPULATION_MAP
    ? mapHref(choice) + populationSearchSuffix(choice, populationSearch(params, choice))
    : null;
}
export function populationCatalogReturnHref(choice: MapChoice, search = ""): string {
  return mapDetailsHref(choice, "population", populationSearchSuffix(choice, search));
}
