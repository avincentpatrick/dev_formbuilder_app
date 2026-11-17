import { NextResponse } from "next/server";
import { searchGeo } from "@/lib/db/search";
import { bhwMeasureHref, levelLabel, parseBhwSearch, suggestBhwMeasures } from "@/lib/bhw/search";
import { bhwLocalityHref } from "@/lib/maps/catalog-return";

export async function GET(request: Request) {
  const params = new URL(request.url).searchParams;
  const q = params.get("q")?.trim() ?? "";
  if (q.length < 2 || q.length > 100)
    return NextResponse.json({ error: "Enter 2–100 characters." }, { status: 400 });
  const intent = parseBhwSearch(q);
  const mapState = params.get("mapState") ?? undefined;
  const suggestions: { label: string; detail: string; href: string }[] = [];
  let placesFailed = false;
  if (intent.state === "ready" && intent.placeQuery) {
    try {
      const places = await searchGeo(intent.placeQuery, 6, { throwOnError: true });
      for (const place of places) {
        const detail = [
          levelLabel[place.geoLevel],
          place.geoCode,
          place.parentChain.citymun,
          place.parentChain.province,
          place.parentChain.region,
        ]
          .filter(Boolean)
          .join(" · ");
        if (intent.measures.length) {
          for (const measure of intent.measures.slice(0, 2))
            suggestions.push({
              label: `${place.geoName} — ${measure.label}`,
              detail,
              href: bhwMeasureHref(measure.id, place.geoLevel, place.geoCode, mapState),
            });
        } else
          suggestions.push({
            label: place.geoName,
            detail,
            href: bhwLocalityHref(
              { mode: "psgc", level: place.geoLevel, code: place.geoCode },
              mapState,
            ),
          });
      }
    } catch {
      placesFailed = true;
    }
  }
  if (intent.state === "ready" && (!intent.placeQuery || !intent.measures.length)) {
    for (const measure of suggestBhwMeasures(q))
      suggestions.push({
        label: measure.label,
        detail: "Indicator · Philippines",
        href: bhwMeasureHref(measure.id, "national", "PH", mapState),
      });
  }
  return NextResponse.json({ suggestions: suggestions.slice(0, 8), placesFailed });
}
