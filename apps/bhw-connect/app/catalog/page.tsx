import type { Metadata } from "next";
import Link from "next/link";
import { Suspense } from "react";
import { IconnectLogo } from "@/components/branding/iconnect-logo";
import { parseCatalogParams, type CatalogParams } from "@/lib/catalog/navigation";
import { CatalogContent } from "@/components/catalog/catalog-content";
import CatalogLoading from "./loading";

export const metadata: Metadata = {
  title: { absolute: "Dataset catalog · iconnect" },
  description:
    "Explore accepted public health, population and poverty data by location, with coverage and sources.",
};

export default async function CatalogPage({
  searchParams,
}: {
  searchParams: Promise<CatalogParams>;
}) {
  const params = await searchParams;
  const request = parseCatalogParams(params);
  return (
    <div className="mx-auto w-full max-w-6xl space-y-6 px-4 py-8 sm:px-6">
      <header className="space-y-4">
        <Link href="/" aria-label="iconnect home" className="inline-block w-40">
          <IconnectLogo className="w-full" />
        </Link>
        <h1 className="text-3xl font-semibold tracking-tight">Dataset catalog</h1>
        <p className="max-w-3xl text-muted">
          Explore health workforce, facilities, primary care access, health services, population and
          poverty. Figures keep their own sources, dates and geographic limits.
        </p>
      </header>
      {request ? (
        <Suspense key={JSON.stringify(request)} fallback={<CatalogLoading />}>
          <CatalogContent request={request} />
        </Suspense>
      ) : (
        <section role="alert" className="rounded-xl border border-border bg-surface p-6">
          <h2 className="font-semibold">This catalog selection is not supported</h2>
          <p className="mt-2 text-sm">
            Choose All locations, a PSGC location, or a congressional district and its constituent
            members.
          </p>
          <Link href="/catalog" className="mt-3 inline-block underline">
            Start at All locations
          </Link>
        </section>
      )}
      <footer className="border-t border-border pt-5 text-sm text-muted">
        Public aggregate data only. Small groups are withheld where required.{" "}
        <Link href="/privacy" className="underline">
          Privacy
        </Link>{" "}
        ·{" "}
        <Link href="/" className="underline">
          Back to iconnect
        </Link>
      </footer>
    </div>
  );
}
