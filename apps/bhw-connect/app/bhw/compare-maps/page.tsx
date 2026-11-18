import Link from "next/link";
import { getGeoByCode } from "@/lib/db/geo";
import { parseMapComparison } from "@/lib/bhw/map-comparison";
import { MapComparisonWorkspace } from "@/components/compare/map-comparison-workspace";

export const metadata = { title: "Compare BHW maps" };

export default async function CompareMapsPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const raw = await searchParams;
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(raw)) {
    for (const item of Array.isArray(value) ? value : value === undefined ? [] : [value])
      params.append(key, item);
  }
  const choice = parseMapComparison(params);
  const geos = choice
    ? await Promise.all([getGeoByCode(choice.a.code), getGeoByCode(choice.b.code)])
    : [];
  if (!choice || geos.some((g) => !g || g.geoLevel !== choice.a.level))
    return (
      <div className="mx-auto max-w-4xl space-y-4 p-4">
        <h1 className="text-2xl font-semibold">Invalid map comparison</h1>
        <p role="alert">
          Choose two different verified areas at the same geographic level and one supported BHW
          measure. Both maps use the same accepted release and period. No fallback was applied.
        </p>
        <Link href="/compare?measure=bhw.total" className="underline">
          Choose comparison places
        </Link>
      </div>
    );
  return <MapComparisonWorkspace initial={choice} />;
}
