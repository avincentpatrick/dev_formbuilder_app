import Link from "next/link";
import { withBasePath } from "@/lib/base-path";
import { catalogHref } from "@/lib/catalog/navigation";
import type { DistrictGeography } from "@/lib/catalog/district-geography";

export function DistrictControls({
  geography,
}: {
  geography: Extract<DistrictGeography, { state: "available" }>;
}) {
  const { selection, current, scope } = geography;
  const city = scope.cities.find((c) => c.code === selection.citymunCode);
  const barangay = city?.barangays.find((b) => b.code === selection.barangayCode);
  return (
    <section
      aria-label="District location"
      className="space-y-4 rounded-xl border border-border bg-surface p-5"
    >
      <nav aria-label="Geographic mode" className="flex flex-wrap gap-3 text-sm">
        <Link
          prefetch={false}
          href={catalogHref({ mode: "all" })}
          className="rounded-md border border-border px-3 py-2 underline"
        >
          PSGC / All locations
        </Link>
        <Link
          prefetch={false}
          href={catalogHref({ mode: "district", districtCode: "" })}
          aria-current="page"
          className="rounded-md border border-border px-3 py-2 underline"
        >
          Congressional districts
        </Link>
      </nav>
      <form
        action={withBasePath("/catalog")}
        method="get"
        className="flex flex-wrap items-end gap-3"
      >
        <input type="hidden" name="mode" value="district" />
        <div className="min-w-0 flex-1 basis-64">
          <label htmlFor="catalog-district" className="mb-1 block text-sm font-medium">
            Congressional district
          </label>
          <select
            id="catalog-district"
            name="district"
            required
            defaultValue={current?.code ?? ""}
            className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
          >
            <option value="" disabled>
              Select a district
            </option>
            {geography.choices.map((d) => (
              <option key={d.code} value={d.code}>
                {d.name} · Congress {d.congress}
              </option>
            ))}
          </select>
        </div>
        <button className="rounded-md bg-accent px-4 py-2 text-sm font-semibold text-white">
          Show district
        </button>
      </form>
      {current && (
        <>
          <nav aria-label="District ancestors" className="flex flex-wrap gap-3 text-sm">
            <Link
              prefetch={false}
              className="underline"
              href={catalogHref({ mode: "district", districtCode: current.code })}
            >
              {current.name}
            </Link>
            {city && (
              <Link
                prefetch={false}
                className="underline"
                href={catalogHref({
                  mode: "district",
                  districtCode: current.code,
                  citymunCode: city.code,
                })}
              >
                {city.name}
                {!city.whole ? " (district barangays only)" : ""}
              </Link>
            )}
            {barangay && <span aria-current="location">{barangay.name}</span>}
          </nav>
          {!barangay && (city?.barangays.length ?? scope.cities.length) > 0 && (
            <form
              action={withBasePath("/catalog")}
              method="get"
              className="flex flex-wrap items-end gap-3"
            >
              <input type="hidden" name="mode" value="district" />
              <input type="hidden" name="district" value={current.code} />
              {city && <input type="hidden" name="citymun" value={city.code} />}
              <div className="min-w-0 flex-1 basis-64">
                <label htmlFor="catalog-district-member" className="mb-1 block text-sm font-medium">
                  {city ? "Constituent barangay" : "Constituent municipality/city"}
                </label>
                <select
                  id="catalog-district-member"
                  name={city ? "barangay" : "citymun"}
                  defaultValue=""
                  required
                  className="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                >
                  <option value="" disabled>
                    Select a member
                  </option>
                  {city
                    ? city.barangays.map((b) => (
                        <option key={b.code} value={b.code}>
                          {b.name}
                        </option>
                      ))
                    : scope.cities.map((c) => (
                        <option key={c.code} value={c.code}>
                          {c.name}
                          {c.whole ? " · whole municipality/city" : " · district barangays only"}
                        </option>
                      ))}
                </select>
              </div>
              <button className="rounded-md bg-accent px-4 py-2 text-sm font-semibold text-white">
                Show member
              </button>
            </form>
          )}
          <p className="text-sm text-muted">
            Congress {current.congress}. Figures cover accepted mapped members only. Selecting a
            municipality inside a split district keeps only its constituent barangays.
          </p>
          <details className="rounded-lg border border-border p-3 text-sm">
            <summary className="cursor-pointer font-medium">Mapping provenance and gaps</summary>
            <p className="mt-3">
              {geography.ownGaps} gap(s) attributable to this district. Across the mapping:{" "}
              {geography.globalGaps.cities} uncovered municipalities/cities and{" "}
              {geography.globalGaps.barangays} unplaced barangays. Shared gaps cannot always be
              assigned to one district; no missing membership is guessed.
            </p>
            <p className="mt-2">
              {geography.receipts.filter((r) => r.corroboration === "single_source").length} live
              membership receipt(s) rely on a single source under the mapping’s published exception.
              The public correction history remains available.
            </p>
            <ul className="mt-2 space-y-1">
              {[
                ...new Set(
                  geography.receipts.map(
                    (r) => `${r.sourceKind} · retrieved ${r.retrievedAt.slice(0, 10)}`,
                  ),
                ),
              ].map((label) => (
                <li key={label}>{label}</li>
              ))}
            </ul>
            <Link
              prefetch={false}
              href={`/districts/${encodeURIComponent(current.code)}`}
              className="mt-3 inline-block underline"
            >
              View source receipts, mapping gaps and correction history
            </Link>
          </details>
          {scope.issues.length > 0 && (
            <div role="status">
              <p className="font-medium">
                District figures are withheld until membership can be verified.
              </p>
              <ul className="mt-2 list-inside list-disc text-sm">
                {scope.issues.map((issue) => (
                  <li key={issue}>{issue}</li>
                ))}
              </ul>
            </div>
          )}
        </>
      )}
    </section>
  );
}
