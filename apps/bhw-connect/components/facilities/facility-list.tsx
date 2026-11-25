"use client";
import { useState } from "react";
import Link from "next/link";
import Form from "next/form";
import { searchFacilities } from "@/lib/facilities/search";
import { formatCount } from "@/lib/format";
import type { NhfrFacility } from "@/lib/db/nhfr";
import { licensingLabel } from "@/lib/facilities/licensing-label";

/**
 * Every facility in a city/municipality — the leaf of the drill-down, and the level at which
 * "which ones, and where" is the actionable question.
 *
 * **Licence status renders what the source says and nothing more.** A blank is shown as "not
 * stated", never as "unlicensed": 63% of the register carries no status, overwhelmingly Barangay
 * Health Stations, which are not a licensed facility type. Rendering that absence as a compliance
 * failure would libel thousands of functioning facilities.
 *
 * **No addresses or contact details**, because they were never ingested — 91% of the source's
 * email addresses are personal webmail accounts (see ingestion/clean_nhfr.py). The barangay is
 * the location this section publishes.
 */
export function FacilityList({
  facilities,
  expectedCount,
  query = "",
  areaHref,
  filtered = false,
}: {
  facilities: NhfrFacility[] | null;
  query?: string;
  areaHref: string;
  expectedCount: number;
  filtered?: boolean;
}) {
  const [page, setPage] = useState(0);
  const [sort, setSort] = useState("name");
  if (facilities === null || (facilities.length === 0 && expectedCount > 0)) {
    return (
      <p role="status" className="text-sm text-muted">
        The facility list could not be loaded completely. This is not a reported zero. Reload to try
        again.
      </p>
    );
  }
  if (facilities.length === 0 && !filtered) {
    return (
      <p className="text-sm text-muted">
        No health facilities are on the DOH registry for this city or municipality.
      </p>
    );
  }

  const [formPath, formQuery] = areaHref.split("?");
  const scopeFields = Array.from(new URLSearchParams(formQuery).entries());

  // The query is bounded, so say so when the bound bit rather than showing a short list that
  // looks complete (the failure the district export had to fix).
  const truncated = facilities.length !== expectedCount;
  const matching = searchFacilities(facilities, query).sort(
    (a, b) =>
      (sort === "type"
        ? a.facilityType.localeCompare(b.facilityType)
        : sort === "barangay"
          ? (a.barangayName ?? "").localeCompare(b.barangayName ?? "")
          : 0) ||
      a.facilityName.localeCompare(b.facilityName, "en", { numeric: true }) ||
      a.facilityCode.localeCompare(b.facilityCode),
  );
  const currentPage = Math.min(page, Math.max(0, Math.ceil(matching.length / 25) - 1));

  return (
    <section aria-label="Facilities" id="facility-list-search" className="@container">
      <Form
        role="search"
        aria-label="Facility list"
        action={`${formPath}#facility-list-search`}
        prefetch={false}
        className="mb-4 space-y-2"
      >
        {scopeFields.map(([name, value]) => (
          <input key={name} type="hidden" name={name} value={value} />
        ))}
        <label htmlFor="facility-search" className="block text-sm font-medium">
          Search facilities by name, type or barangay
        </label>
        <div className="flex flex-wrap gap-2">
          <input
            key={query}
            id="facility-search"
            name="facilitySearch"
            type="search"
            maxLength={200}
            defaultValue={query}
            className="min-w-0 flex-1 rounded border border-border bg-background px-3 py-2 text-sm"
          />
          <button
            type="submit"
            className="rounded border border-border px-3 py-2 text-sm font-medium"
          >
            Search list
          </button>
          {query && (
            <Link href={`${areaHref}#facility-list-search`} className="px-3 py-2 text-sm underline">
              Clear search
            </Link>
          )}
        </div>
        <p className="text-xs text-muted">
          Search filters this list only. Area totals, map and map downloads stay unchanged.
          Matching-facility downloads follow this search.
        </p>
      </Form>
      <p role="status" className="mb-3 text-sm">
        {formatCount(matching.length)} matching {matching.length === 1 ? "facility" : "facilities"}{" "}
        of {formatCount(facilities.length)} loaded.
      </p>
      {matching.length === 0 && (
        <p className="mb-3 text-sm">
          {query
            ? "No facilities match this search. Clear it to see all loaded rows."
            : "No facilities match the selected types, ownership or barangay. Adjust those filters to see more facilities."}
        </p>
      )}
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-semibold">Facilities</h2>
        <label className="text-sm">
          Sort facilities{" "}
          <select
            className="min-h-11 max-w-full bg-background"
            value={sort}
            onChange={(e) => {
              setSort(e.target.value);
              setPage(0);
            }}
          >
            <option value="name">Name (A–Z)</option>
            <option value="type">Type</option>
            <option value="barangay">Barangay</option>
          </select>
        </label>
      </div>
      <div
        className="mt-2 hidden overflow-x-auto @3xl:block"
        role="region"
        aria-label="Scrollable facility list"
        tabIndex={0}
      >
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-b border-border text-left text-xs text-muted">
              <th scope="col" className="py-2 pr-3 font-medium">
                Facility
              </th>
              <th scope="col" className="py-2 pr-3 font-medium">
                Type
              </th>
              <th scope="col" className="py-2 pr-3 font-medium">
                Barangay
              </th>
              <th scope="col" className="py-2 pr-3 font-medium">
                Ownership
              </th>
              <th scope="col" className="py-2 text-right font-medium">
                Beds
              </th>
            </tr>
          </thead>
          <tbody>
            {matching.slice(currentPage * 25, (currentPage + 1) * 25).map((f) => (
              <tr key={f.facilityCode} className="border-b border-border last:border-0 align-top">
                <td className="py-2 pr-3">
                  <span className="font-medium">{f.facilityName}</span>
                  <span className="mt-0.5 block text-xs text-muted">
                    {licensingLabel(f.licensingStatus)}
                  </span>
                </td>
                <td className="py-2 pr-3 text-muted">{f.facilityType}</td>
                <td className="py-2 pr-3 text-muted">{f.barangayName ?? "—"}</td>
                <td className="py-2 pr-3 text-muted">
                  {f.ownershipMajor}
                  {f.ownershipSub && <span className="mt-0.5 block text-xs">{f.ownershipSub}</span>}
                </td>
                <td className="py-2 text-right tabular-nums text-muted">
                  {f.bedCapacity > 0 ? formatCount(f.bedCapacity) : "—"}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <ul aria-label="Facility records" className="mt-3 space-y-3 @3xl:hidden">
        {matching.slice(currentPage * 25, (currentPage + 1) * 25).map((f) => (
          <li key={f.facilityCode} className="min-w-0 rounded border border-border p-3 text-sm">
            <h3 className="break-words font-medium">{f.facilityName}</h3>
            <p className="break-words">
              {f.facilityType} · {f.ownershipMajor}
            </p>
            <p className="break-words">Barangay: {f.barangayName ?? "Not stated"}</p>
            <p>{licensingLabel(f.licensingStatus)}</p>
            <p>Beds: {f.bedCapacity > 0 ? formatCount(f.bedCapacity) : "Not recorded"}</p>
          </li>
        ))}
      </ul>
      {matching.length > 25 && (
        <nav
          aria-label="Facility list pages"
          className="my-3 flex flex-wrap items-center gap-3 text-sm"
        >
          <button
            className="min-h-11 rounded border px-3"
            disabled={!currentPage}
            onClick={() => setPage(currentPage - 1)}
          >
            Previous facilities
          </button>
          <span>
            Page {currentPage + 1} of {Math.max(1, Math.ceil(matching.length / 25))}
          </span>
          <button
            className="min-h-11 rounded border px-3"
            disabled={(currentPage + 1) * 25 >= matching.length}
            onClick={() => setPage(currentPage + 1)}
          >
            Next facilities
          </button>
        </nav>
      )}
      <p className="mt-3 border-t border-border pt-3 text-xs text-muted">
        {truncated ? (
          <>
            Incomplete list: {formatCount(facilities.length)} of {formatCount(expectedCount)}{" "}
            registered facilities loaded. Search covers the loaded rows only.
          </>
        ) : (
          <>
            {formatCount(facilities.length)} {facilities.length === 1 ? "facility" : "facilities"}{" "}
            listed. Beds shown as a dash or “Not recorded” have no positive capacity in this
            snapshot; this is not an assessment of services.
          </>
        )}
      </p>
    </section>
  );
}
