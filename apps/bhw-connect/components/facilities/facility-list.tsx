import Link from "next/link";
import Form from "next/form";
import { searchFacilities } from "@/lib/facilities/search";
import { formatCount } from "@/lib/format";
import { licensingLabel, type NhfrFacility } from "@/lib/db/nhfr";

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
}: {
  facilities: NhfrFacility[] | null;
  query?: string;
  areaHref: string;
  expectedCount: number;
}) {
  if (facilities === null || (facilities.length === 0 && expectedCount > 0)) {
    return (
      <p role="status" className="text-sm text-muted">
        The facility list could not be loaded completely. This is not a reported zero. Reload to try
        again.
      </p>
    );
  }
  if (facilities.length === 0) {
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
  const matching = searchFacilities(facilities, query);

  return (
    <section aria-label="Facilities" id="facility-list-search">
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
          Search filters this list only. Area totals, map and downloads stay unchanged.
        </p>
      </Form>
      <p role="status" className="mb-3 text-sm">
        {formatCount(matching.length)} matching {matching.length === 1 ? "facility" : "facilities"}{" "}
        of {formatCount(facilities.length)} loaded.
      </p>
      {matching.length === 0 && (
        <p className="mb-3 text-sm">
          No facilities match this search. Clear it to see all loaded rows.
        </p>
      )}
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-semibold">Facilities</h2>
        <span className="text-xs text-muted">A–Z</span>
      </div>
      <div
        className="mt-2 overflow-x-auto"
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
            {matching.map((f) => (
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
      <p className="mt-3 border-t border-border pt-3 text-xs text-muted">
        {truncated ? (
          <>
            Incomplete list: {formatCount(facilities.length)} of {formatCount(expectedCount)}{" "}
            registered facilities loaded. Search covers the loaded rows only.
          </>
        ) : (
          <>
            {formatCount(facilities.length)} {facilities.length === 1 ? "facility" : "facilities"}{" "}
            listed. A dash under Beds means the facility records no bed capacity — most out-patient
            facilities do not.
          </>
        )}
      </p>
    </section>
  );
}
