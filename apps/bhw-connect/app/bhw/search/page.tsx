import Link from "next/link";
import { BhwSearchForm } from "@/components/bhw/search-form";
import { searchGeo, type GeoSearchResult } from "@/lib/db/search";
import { bhwMeasureHref, levelLabel, parseBhwSearch } from "@/lib/bhw/search";
import { catalogHref } from "@/lib/catalog/navigation";
import { parseComparisonSearch, publicPlace, type PlaceGroup } from "@/lib/bhw/comparison";
import { ComparisonQueryResolver } from "@/components/compare/query-resolver";

export const metadata = { title: "Search BHW places and indicators" };

export default async function BhwSearchPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  const query = typeof params.q === "string" ? params.q : "";
  const comparison = parseComparisonSearch(query);
  if (comparison?.valid) {
    const groups: PlaceGroup[] = await Promise.all(
      comparison.places.map(async (place) => {
        try {
          return {
            query: place,
            state: "available" as const,
            matches: (await searchGeo(place, 12, { throwOnError: true })).map(publicPlace),
          };
        } catch {
          return { query: place, state: "failed" as const, matches: [] };
        }
      }),
    );
    return (
      <div className="mx-auto w-full max-w-4xl space-y-6 px-4 py-6">
        <Link href="/bhw" className="underline">
          ← BHW overview
        </Link>
        <h1 className="text-2xl font-semibold">Search BHW places and indicators</h1>
        <BhwSearchForm query={query} />
        <ComparisonQueryResolver
          key={query}
          groups={groups}
          measures={comparison.measures.map(({ id, label }) => ({ id, label }))}
        />
      </div>
    );
  }
  const intent = parseBhwSearch(query);
  let places: GeoSearchResult[] = [];
  let failed = false;
  if (intent.state === "ready" && intent.placeQuery) {
    try {
      places = await searchGeo(intent.placeQuery, 12, { throwOnError: true });
    } catch {
      failed = true;
    }
  } else if (intent.state === "ready") {
    places = [
      {
        geoCode: "PH",
        geoLevel: "national",
        geoName: "Philippines",
        parentChain: {},
        nTotal: null,
      },
    ];
  }
  return (
    <div className="mx-auto w-full max-w-4xl space-y-6 px-4 py-6">
      <Link href="/bhw" className="underline">
        ← BHW overview
      </Link>
      <h1 className="text-2xl font-semibold">Search BHW places and indicators</h1>
      <BhwSearchForm query={query.slice(0, 100)} />
      {intent.state === "unsupported" && (
        <p role="status">
          This query is not supported. Try a place and indicator, or “Compare Cebu and Bohol
          accreditation”. Separate places with “and” or commas and include a supported measure.
          Trends and predictions are not supported.
        </p>
      )}
      {failed && (
        <p role="alert">
          Place search could not be loaded. Submit the search again to retry; this does not mean
          there are no matching places.
        </p>
      )}
      {intent.state === "ready" && !failed && places.length === 0 && (
        <p role="status">
          No matching places found. Try the place name or PSGC code on its own, or a supported
          indicator such as headcount or accreditation.
        </p>
      )}
      {places.length > 0 && (
        <section aria-label="Search results" className="space-y-4">
          <p className="text-sm text-muted">
            {intent.placeQuery
              ? "Choose a place. Results may include approximate matches; check its level, code and parent names. Up to 12 matches are shown; refine the name if yours is missing."
              : "National scope · choose a measure."}{" "}
            Search matches do not establish that an observation is available.
          </p>
          {intent.measures.length > 1 && (
            <p className="rounded-lg border border-border p-3">
              Choose the intended measure. LGU-reported headcount and validated profiles are
              different populations. Payer-specific honorarium measures can overlap.
            </p>
          )}
          {places.map((place) => (
            <article key={place.geoCode} className="space-y-3 rounded-lg border border-border p-4">
              <h2 className="text-lg font-semibold">{place.geoName}</h2>
              <p className="text-sm text-muted">
                {levelLabel[place.geoLevel]} · {place.geoCode} ·{" "}
                {[place.parentChain.citymun, place.parentChain.province, place.parentChain.region]
                  .filter(Boolean)
                  .join(" · ") || "No parent names supplied"}
              </p>
              {intent.measures.length ? (
                <ul className="space-y-3">
                  {intent.measures.map((measure) => (
                    <li key={measure.id}>
                      <Link
                        prefetch={false}
                        className="underline"
                        href={bhwMeasureHref(measure.id, place.geoLevel, place.geoCode)}
                      >
                        {measure.label}
                      </Link>
                      {!measure.levels.includes(place.geoLevel) && (
                        <span className="block text-sm text-muted">
                          Not supported at this geographic level. Open for the explanation; scope
                          will be retained.
                        </span>
                      )}
                    </li>
                  ))}
                </ul>
              ) : (
                <Link
                  prefetch={false}
                  className="underline"
                  href={catalogHref(
                    { mode: "psgc", level: place.geoLevel, code: place.geoCode },
                    "bhw",
                  )}
                >
                  View BHW indicators for {place.geoName}
                </Link>
              )}
            </article>
          ))}
        </section>
      )}
    </div>
  );
}
