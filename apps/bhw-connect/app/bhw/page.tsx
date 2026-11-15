import type { Metadata } from "next";
import { Suspense } from "react";
import { getPublicDataset } from "@/lib/catalog/catalog";
import { BhwOverview } from "@/components/bhw/overview";
import { ChatLauncher } from "@/components/chat/chat-launcher";
import { BhwSearchForm } from "@/components/bhw/search-form";

export const metadata: Metadata = { title: "BHW 2025" };

async function OverviewContent() {
  const dataset = await getPublicDataset("bhw", { mode: "psgc", level: "national", code: "PH" });
  return <BhwOverview dataset={dataset} />;
}

export default function Home() {
  return (
    <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 px-4 py-6 sm:px-6">
      <header className="space-y-4">
        <h1 className="text-2xl font-semibold tracking-tight sm:text-4xl">
          Barangay Health Workers
        </h1>
        <p className="max-w-3xl text-muted">
          Workforce, qualifications and support. Philippines · 2025.
        </p>
        <BhwSearchForm />
      </header>
      <Suspense fallback={<p role="status">Loading public BHW figures…</p>}>
        <OverviewContent />
      </Suspense>
      <ChatLauncher geoCode="PH" geoLevel="national" />
    </div>
  );
}
