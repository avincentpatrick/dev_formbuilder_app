import type { Metadata } from "next";
import Link from "next/link";
import { DatasetMapPage } from "@/components/maps/dataset-map-page";
import { parseMapChoice } from "@/lib/maps/prototype";
import { DISTRICT_MAP_SLUG, parseDistrictView } from "@/lib/maps/district-view";
import { MAP_SUBJECTS, subjectMapHref, subjectOfRelease } from "@/lib/maps/subjects";
import { catalogHref } from "@/lib/catalog/navigation";
import type { CatalogId } from "@/lib/catalog/types";
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
  // Back to the card this map belongs to; districts have their own page rather than a catalog card.
  const back = !subject
    ? { href: "/", label: "Dataset portal" }
    : subject.id === "districts"
      ? { href: "/districts", label: "Legislative districts" }
      : {
          href: catalogHref({ mode: "all" }, subject.id as CatalogId),
          label: `${subject.title} dataset`,
        };
  return (
    <div className="mx-auto w-full max-w-6xl space-y-6 px-4 py-8">
      <Link href={back.href} className="text-sm underline">
        ← {back.label}
      </Link>
      <h1 className="text-3xl font-semibold">
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
