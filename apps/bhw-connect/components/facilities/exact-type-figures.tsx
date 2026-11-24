import type { NhfrTypeCount } from "@/lib/db/nhfr";
import { formatCount, percentageFormatter } from "@/lib/format";

/** Receives only rows from the card's reconciled type/ownership partition. */
export function ExactTypeFigures({
  rows,
  series = rows,
  total,
  presenting = false,
}: {
  rows: NhfrTypeCount[];
  series?: NhfrTypeCount[];
  total: number;
  presenting?: boolean;
}) {
  const percent = percentageFormatter(series.map((r) => (100 * r.nFacilities) / total));
  const shown = series.reduce((n, r) => n + r.nFacilities, 0);
  return (
    <section aria-label="Facilities by type" className="@container min-w-0">
      <h4 className="mb-2 font-semibold">Exact figures by facility type</h4>
      <p className="mb-3 text-sm">
        Share of all facilities here uses the full location total:{" "}
        <span className="whitespace-nowrap tabular-nums">{formatCount(total)} facilities</span>.
      </p>
      {rows.length ? (
        <>
          <div className="hidden @3xl:block">
            <table
              className={`w-full table-fixed border-collapse ${presenting ? "text-base" : "text-sm"}`}
            >
              <caption className="sr-only">
                Facility totals, government and private counts, and share of all facilities in this
                location
              </caption>
              <thead>
                <tr className="border-b border-border text-left">
                  <th scope="col" className="w-[36%] py-3 pr-3">
                    Facility type
                  </th>
                  <th scope="col" className="px-2 py-3 text-right">
                    Facilities
                  </th>
                  <th scope="col" className="px-2 py-3 text-right">
                    Share of all facilities here
                  </th>
                  <th scope="col" className="px-2 py-3 text-right">
                    Government
                  </th>
                  <th scope="col" className="py-3 pl-2 text-right">
                    Private
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.facilityType} className="border-b border-border last:border-0">
                    <th scope="row" className="break-words py-3 pr-3 text-left font-medium">
                      {r.facilityType}
                    </th>
                    <td className="whitespace-nowrap px-2 py-3 text-right tabular-nums">
                      {formatCount(r.nFacilities)}
                    </td>
                    <td className="whitespace-nowrap px-2 py-3 text-right tabular-nums">
                      {percent((100 * r.nFacilities) / total)}
                    </td>
                    <td className="whitespace-nowrap px-2 py-3 text-right tabular-nums">
                      {formatCount(r.nGovernment)}
                    </td>
                    <td className="whitespace-nowrap py-3 pl-2 text-right tabular-nums">
                      {formatCount(r.nPrivate)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <ul aria-label="Exact facility types" className="space-y-3 @3xl:hidden">
            {rows.map((r) => (
              <li key={r.facilityType} className="min-w-0 rounded-md border border-border p-3">
                <p className="break-words font-medium">{r.facilityType}</p>
                <p className="my-2 text-sm">
                  <span className="whitespace-nowrap tabular-nums">
                    {formatCount(r.nFacilities)} ({percent((100 * r.nFacilities) / total)})
                  </span>{" "}
                  of all facilities here
                </p>
                <dl className="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                  <div>
                    <dt className="text-muted">Government</dt>
                    <dd className="tabular-nums">{formatCount(r.nGovernment)}</dd>
                  </div>
                  <div>
                    <dt className="text-muted">Private</dt>
                    <dd className="tabular-nums">{formatCount(r.nPrivate)}</dd>
                  </div>
                </dl>
              </li>
            ))}
          </ul>
        </>
      ) : (
        <p role="status" className="my-3 text-sm">
          No selected facility types match this search. Clear the search to see the selected types.
        </p>
      )}
      <p className="mt-3 border-t border-border pt-3 text-sm">
        {presenting ? "All matching rows" : "Displayed rows"}:{" "}
        <span className="whitespace-nowrap font-medium tabular-nums">
          {formatCount(shown)} ({percent((100 * shown) / total)})
        </span>{" "}
        of all facilities here.
      </p>
    </section>
  );
}
