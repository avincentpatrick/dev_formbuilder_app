import Link from "next/link";
import { getGeoByCode } from "@/lib/db/geo";
import { parseCatalogParams } from "@/lib/catalog/navigation";
import type { CatalogSelection } from "@/lib/catalog/types";
import type { Metadata } from "next";
import { Suspense } from "react";
import { getPublicDataset } from "@/lib/catalog/catalog";
import { BhwOverview } from "@/components/bhw/overview";
import { ChatLauncher } from "@/components/chat/chat-launcher";
import { BhwMapReturnLink } from "@/components/bhw/map-return-link";
import { restoredBhwMapHref } from "@/lib/maps/catalog-return";
import { BhwSearchForm } from "@/components/bhw/search-form";

export const metadata: Metadata = { title: "BHW 2025" };

async function OverviewContent({
  mapState,
  selection,
  areaName,
}: {
  mapState: string | string[] | undefined;
  selection: Extract<CatalogSelection, { mode: "psgc" }>;
  areaName: string;
}) {
  const dataset = await getPublicDataset("bhw", selection);
  return (
    <BhwOverview dataset={dataset} selection={selection} areaName={areaName} mapState={mapState} />
  );
}

export default async function Home({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  const returnMap = restoredBhwMapHref(params.mapState);
  const request = parseCatalogParams({
    mode: "psgc",
    location: params.location === undefined ? "national:PH" : params.location,
    dataset: "bhw",
  });
  const selection = request?.selection;
  const geo = selection?.mode === "psgc" ? await getGeoByCode(selection.code) : null;
  if (selection?.mode !== "psgc" || !geo || geo.geoLevel !== selection.level)
    return (
      <div className="mx-auto w-full max-w-6xl space-y-5 px-4 py-6">
        <h1 className="text-2xl font-semibold">Could not verify this locality</h1>
        <p role="status">
          Check the place or retry if the place service is unavailable. No national fallback has
          been applied.
        </p>
        <BhwSearchForm compact mapState={params.mapState} />
        <Link href="/bhw" className="underline">
          View Philippines dashboard
        </Link>
      </div>
    );
  return (
    <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6">
      <header className="space-y-4">
        <h1 className="text-2xl font-semibold tracking-tight sm:text-4xl">
          Barangay Health Workers
        </h1>
        <p className="max-w-3xl text-muted">
          Workforce, qualifications and support. {geo.geoName} · 2025.
        </p>
        {selection.level !== "national" && (
          <Link href="/bhw" className="inline-block underline">
            View Philippines dashboard
          </Link>
        )}
        <BhwSearchForm compact mapState={params.mapState} />
      </header>
      {params.mapState !== undefined && (
        <BhwMapReturnLink
          href={returnMap}
          scopeNote={`Dashboard figures describe ${geo.geoName}; map value filters and color groups do not change them.`}
        />
      )}
      <Suspense
        key={`${selection.level}:${selection.code}`}
        fallback={<p role="status">Loading public BHW figures…</p>}
      >
        <OverviewContent selection={selection} areaName={geo.geoName} mapState={params.mapState} />
      </Suspense>
      <div>
        <ChatLauncher geoCode={selection.code} geoLevel={selection.level} placement="inline" />
        <p className="mt-2 text-sm text-muted">
          Sign-in and access approval are required for Ask the Data. Public figures above are
          available without signing in.
        </p>
      </div>
    </div>
  );
}
