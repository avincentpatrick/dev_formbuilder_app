import type { Metadata } from "next";
import Link from "next/link";
import { DatasetMapPage } from "@/components/maps/dataset-map-page";
import { parseMapChoice } from "@/lib/maps/prototype";
export const metadata: Metadata = { title: "Dataset maps" };
export default async function MapPage({
  searchParams,
}: {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
}) {
  const raw = await searchParams;
  const params = new URLSearchParams();
  for (const [key, value] of Object.entries(raw))
    if (typeof value === "string") params.set(key, value);
  return (
    <div className="mx-auto w-full max-w-6xl space-y-6 px-4 py-8">
      <Link href="/" className="text-sm underline">
        ← Dataset portal
      </Link>
      <h1 className="text-3xl font-semibold">Dataset maps</h1>
      <DatasetMapPage choice={parseMapChoice(params)} />
    </div>
  );
}
