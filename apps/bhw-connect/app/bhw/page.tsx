import type { Metadata } from "next";
import { Suspense } from "react";
import { getPublicDataset } from "@/lib/catalog/catalog";
import { BhwOverview } from "@/components/bhw/overview";
import { ChatLauncher } from "@/components/chat/chat-launcher";
import { BhwMapReturnLink } from "@/components/bhw/map-return-link";
import { restoredBhwMapHref } from "@/lib/maps/catalog-return";
import { BhwSearchForm } from "@/components/bhw/search-form";

export const metadata: Metadata = { title: "BHW 2025" };

async function OverviewContent({ mapState }: { mapState: string | string[] | undefined }) {
  const dataset = await getPublicDataset("bhw", { mode: "psgc", level: "national", code: "PH" });
  return <BhwOverview dataset={dataset} mapState={mapState} />;
}

export default async function Home({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const params = await searchParams;
  const returnMap = restoredBhwMapHref(params.mapState);
  return (
    <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6">
      <header className="space-y-4">
        <h1 className="text-2xl font-semibold tracking-tight sm:text-4xl">
          Barangay Health Workers
        </h1>
        <p className="max-w-3xl text-muted">
          Workforce, qualifications and support. Philippines · 2025.
        </p>
        <BhwSearchForm compact mapState={params.mapState} />
      </header>
      {params.mapState !== undefined && (
        <BhwMapReturnLink
          href={returnMap}
          scopeNote="Overview figures remain national and are not filtered by the map settings."
        />
      )}
      <Suspense fallback={<p role="status">Loading public BHW figures…</p>}>
        <OverviewContent mapState={params.mapState} />
      </Suspense>
      <div>
        <ChatLauncher geoCode="PH" geoLevel="national" placement="inline" />
      </div>
    </div>
  );
}
