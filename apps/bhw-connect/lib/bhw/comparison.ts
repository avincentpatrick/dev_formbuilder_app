import { restoredBhwMapHref } from "@/lib/maps/catalog-return";
import { BHW_MEASURES, parseBhwSearch } from "./search";
import type { GeoSearchResult } from "@/lib/db/search";

export type PlaceMatch = Omit<GeoSearchResult, "nTotal">;
export type PlaceGroup = { query: string; state: "available" | "failed"; matches: PlaceMatch[] };
export const publicPlace = ({
  geoCode,
  geoLevel,
  geoName,
  parentChain,
}: GeoSearchResult): PlaceMatch => ({ geoCode, geoLevel, geoName, parentChain });
export function placeLabel(place: PlaceMatch) {
  return [
    place.geoName,
    place.geoLevel,
    place.geoCode,
    place.parentChain.citymun,
    place.parentChain.province,
    place.parentChain.region,
  ]
    .filter(Boolean)
    .join(" · ");
}

/** Explicit initial grammar. Commas and 'and' separate places after the recognized
 * measure phrase is removed; its own 'and' (registered and accredited) is retained. */
export function parseComparisonSearch(query: string) {
  if (!/^compare\b/i.test(query.trim())) return null;
  const intent = parseBhwSearch(
    query
      .trim()
      .replace(/^compare\s*/i, "")
      .replace(/,\s*(?:and\s+)?/gi, " and "),
  );
  const places = intent.placeQuery.split(/\s+(?:and|versus|vs)\s+/i).map((p) => p.trim());
  return {
    measures: intent.measures,
    places,
    valid:
      query.length <= 100 &&
      intent.state === "ready" &&
      intent.measures.length > 0 &&
      places.length >= 2 &&
      places.length <= 10 &&
      places.every(Boolean),
  };
}

export function comparisonHref(
  measure: string,
  codes: readonly string[],
  focus?: string,
  mapState?: string | string[],
) {
  const query = new URLSearchParams({ measure });
  if (codes.length) query.set("geos", codes.join(","));
  if (focus && codes.includes(focus)) query.set("focus", focus);
  const restored = restoredBhwMapHref(mapState);
  if (restored) query.set("mapState", restored.split("?")[1]);
  return `/compare?${query}`;
}

export function parsePublicComparison(params: Record<string, string | string[] | undefined>) {
  const spec = BHW_MEASURES.find((s) => s.id === params.measure);
  if (!spec || Array.isArray(params.geos) || Array.isArray(params.focus) || params.districts)
    return null;
  const codes = params.geos ? params.geos.split(",") : [];
  if (
    codes.length > 20 ||
    codes.some((c) => !/^(PH|\d{2,10})$/.test(c)) ||
    new Set(codes).size !== codes.length
  )
    return null;
  if (params.focus && !codes.includes(params.focus)) return null;
  return { spec, codes, focus: params.focus ?? codes[0] };
}
