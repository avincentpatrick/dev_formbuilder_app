import type { Metadata } from "next";
import Link from "next/link";
import { DatasetMapPage } from "@/components/maps/dataset-map-page";
import { parseMapChoice } from "@/lib/maps/prototype";
import { DISTRICT_MAP_SLUG, parseDistrictView } from "@/lib/maps/district-view";
import { MAP_SUBJECTS, subjectMapHref, subjectOfRelease } from "@/lib/maps/subjects";
import type { CatalogId } from "@/lib/catalog/types";
import { DatasetMapBackLink } from "@/components/maps/dataset-map-back-link";
export const metadata: Metadata = { title: "Dataset maps" };
export default async function MapPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const raw = await searchParams;
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(raw)) {
    if (typeof value === "string") params.append(key, value);
    else if (Array.isArray(value)) for (const item of value) params.append(key, item);
  }
  const choice = parseMapChoice(params);
  const subject = choice ? subjectOfRelease(choice.dataset) : undefined;
  return (
    <div
      className={`mx-auto w-full ${subject?.id === "bhw" ? "max-w-screen-2xl space-y-2 px-3 py-2" : "max-w-6xl space-y-6 px-4 py-8"}`}
    >
      <DatasetMapBackLink />
      <h1 className={subject?.id === "bhw" ? "text-2xl font-semibold" : "text-3xl font-semibold"}>
        {subject ? `${subject.title} map` : "Dataset maps"}
      </h1>
      <DatasetMapPage
        choice={choice}
        districtView={choice?.dataset === DISTRICT_MAP_SLUG ? parseDistrictView(params) : undefined}
      />
      {!choice && (
        <nav aria-label="Maps by dataset" className="flex flex-wrap gap-4 text-sm underline">
          {MAP_SUBJECTS.filter((s) => s.id !== "districts").map((s) => {
            const href = subjectMapHref(s.id as CatalogId, { mode: "all" });
            return href ? (
              <Link key={s.id} href={href}>
                {s.title} map
              </Link>
            ) : null;
          })}
        </nav>
      )}
    </div>
  );
}
