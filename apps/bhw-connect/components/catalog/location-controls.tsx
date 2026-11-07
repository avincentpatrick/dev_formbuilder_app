import Link from "next/link";
import { withBasePath } from "@/lib/base-path";
import { catalogHref } from "@/lib/catalog/navigation";
import type { CatalogGeography } from "@/lib/catalog/geography";
import type { CatalogSelection } from "@/lib/catalog/types";

export function LocationControls({
  geography,
  selection,
}: {
  geography: Extract<CatalogGeography, { state: "available" }>;
  selection: CatalogSelection;
}) {
  return (
    <section
      aria-label="Catalog location"
      className="rounded-xl border border-border bg-surface p-5"
    >
      <div className="flex flex-wrap items-center gap-3">
        <Link
          prefetch={false}
          href={catalogHref({ mode: "all" })}
          aria-current={selection.mode === "all" ? "page" : undefined}
          className="rounded-md border border-border px-3 py-2 text-sm hover:border-accent focus-visible:outline-2 focus-visible:outline-accent"
        >
          All locations
        </Link>
        <Link
          prefetch={false}
          href={catalogHref({ mode: "psgc", level: "national", code: "PH" })}
          aria-current={
            selection.mode === "psgc" && selection.level === "national" ? "page" : undefined
          }
          className="rounded-md border border-border px-3 py-2 text-sm hover:border-accent focus-visible:outline-2 focus-visible:outline-accent"
        >
          National figures
        </Link>
        <span className="text-sm text-muted">Browse by PSGC</span>
      </div>
      {geography.ancestors.length > 0 && (
        <nav aria-label="Location ancestors" className="mt-4 flex flex-wrap gap-2 text-sm">
          {geography.ancestors.map((place) => (
            <Link
              prefetch={false}
              key={place.code}
              className="underline underline-offset-4"
              href={catalogHref({ mode: "psgc", level: place.level, code: place.code })}
            >
              {place.name}
            </Link>
          ))}
          <span aria-current="location">{geography.current?.name}</span>
        </nav>
      )}
      {geography.children.length > 0 ? (
        <form
          action={withBasePath("/catalog")}
          method="get"
          className="mt-4 flex flex-wrap items-end gap-3"
        >
          <input type="hidden" name="mode" value="psgc" />
          <div className="min-w-0 flex-1 basis-64">
            <label htmlFor="catalog-location" className="mb-1 block text-sm font-medium">
              {geography.current && geography.current.level !== "national"
                ? `Browse within ${geography.current.name}`
                : "Choose a region"}
            </label>
            <select
              id="catalog-location"
              name="location"
              required
              defaultValue=""
              className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
            >
              <option value="" disabled>
                Select a location
              </option>
              {geography.children.map((place) => (
                <option key={place.code} value={`${place.level}:${place.code}`}>
                  {place.name} · {place.level === "citymun" ? "city/municipality" : place.level}
                </option>
              ))}
            </select>
          </div>
          <button
            type="submit"
            className="rounded-md bg-accent px-4 py-2 text-sm font-semibold text-white"
          >
            Show datasets
          </button>
        </form>
      ) : (
        <p className="mt-4 text-sm text-muted">
          {geography.current?.level === "barangay"
            ? "This is the barangay level. Only supported source figures are shown."
            : "No further locations are listed here."}
        </p>
      )}
      <p className="mt-3 text-sm text-muted">
        All locations shows where data is available. National figures describe the country only
        where the source supports them.{" "}
        <Link href="/districts" className="underline underline-offset-4">
          Congressional district mapping
        </Link>{" "}
        remains available separately.
      </p>
    </section>
  );
}
