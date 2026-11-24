import Link from "next/link";
import { catalogJourneyHref } from "@/lib/catalog/journey";
import type { AreaFigures, AreaFigureRow } from "@/lib/facilities/area-figures";
import { formatCount, percentageFormatter } from "@/lib/format";
export function ExactAreaFigures({
  figures,
  rows = figures.rows,
  ownership = false,
  mapState,
}: {
  figures: AreaFigures;
  rows?: AreaFigureRow[];
  ownership?: boolean;
  mapState?: string;
}) {
  const facility = percentageFormatter(
    figures.rows.flatMap((a) => (a.facilityShare === null ? [] : [a.facilityShare])),
  );
  const presence = percentageFormatter(
    figures.rows.flatMap((a) => (a.presenceShare === null ? [] : [a.presenceShare])),
  );
  const link = (a: AreaFigureRow) => (
    <Link
      href={catalogJourneyHref({ mode: "psgc", level: a.level, code: a.code }, "nhfr", mapState)}
      className="break-words font-medium underline"
    >
      {a.name}
    </Link>
  );
  return (
    <section aria-label="Exact area figures" className="@container min-w-0">
      <h4 className="mb-2 font-semibold">Exact figures by area</h4>
      <p className="mb-3 text-sm">
        Facility shares use the parent total of{" "}
        <span className="whitespace-nowrap tabular-nums">
          {formatCount(figures.parent)} facilities
        </span>
        . Barangay shares use each area’s own barangay total.
      </p>
      {rows.length ? (
        <>
          <div className="hidden @4xl:block">
            <table className="w-full table-fixed border-collapse text-sm">
              <caption className="sr-only">
                Facility counts and share of parent, and barangays with a recorded facility and
                share of barangays
              </caption>
              <thead>
                <tr className="border-b border-border text-left">
                  <th scope="col" className="w-[28%] py-3 pr-3">
                    Area
                  </th>
                  {[
                    "Facilities",
                    "Share of parent’s facilities",
                    ...(ownership ? ["Government", "Private"] : []),
                    "Barangays with a recorded facility",
                    "Share of barangays",
                  ].map((label) => (
                    <th scope="col" key={label} className="px-2 py-3 text-right">
                      {label}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {rows.map((a) => (
                  <tr key={a.code} className="border-b border-border last:border-0">
                    <th scope="row" className="break-words py-3 pr-3 text-left">
                      {link(a)}
                    </th>
                    {[
                      formatCount(a.count),
                      a.facilityShare === null ? "Unavailable" : facility(a.facilityShare),
                      ...(ownership ? [formatCount(a.government), formatCount(a.private)] : []),
                      `${formatCount(a.present)} of ${formatCount(a.barangays)}`,
                      a.presenceShare === null ? "Unavailable" : presence(a.presenceShare),
                    ].map((value, i) => (
                      <td key={i} className="px-2 py-3 text-right tabular-nums">
                        <span className="whitespace-nowrap">{value}</span>
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <ul aria-label="Exact areas" className="space-y-3 @4xl:hidden">
            {rows.map((a) => (
              <li key={a.code} className="min-w-0 rounded-md border border-border p-3">
                {link(a)}
                <dl className="mt-3 grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                  <div className="col-span-2">
                    <dt className="text-muted">Facilities · share of parent</dt>
                    <dd className="tabular-nums">
                      <span className="whitespace-nowrap">
                        {formatCount(a.count)}
                        {a.facilityShare !== null ? ` (${facility(a.facilityShare)})` : ""}
                      </span>
                      {a.facilityShare === null ? " · share unavailable" : ""}
                    </dd>
                  </div>
                  <div className="col-span-2">
                    <dt className="text-muted">Barangays with a recorded facility</dt>
                    <dd className="tabular-nums">
                      <span className="whitespace-nowrap">
                        {formatCount(a.present)} of {formatCount(a.barangays)}
                        {a.presenceShare !== null ? ` (${presence(a.presenceShare)})` : ""}
                      </span>
                      {a.presenceShare === null ? " · share unavailable" : ""}
                    </dd>
                  </div>
                  {ownership && (
                    <>
                      <div>
                        <dt className="text-muted">Government</dt>
                        <dd className="tabular-nums">{formatCount(a.government)}</dd>
                      </div>
                      <div>
                        <dt className="text-muted">Private</dt>
                        <dd className="tabular-nums">{formatCount(a.private)}</dd>
                      </div>
                    </>
                  )}
                </dl>
              </li>
            ))}
          </ul>
        </>
      ) : (
        <p role="status" className="my-3 text-sm">
          No selected areas match this search. Clear the search to see the selected areas.
        </p>
      )}
      <p className="mt-3 border-t border-border pt-3 text-sm">
        All matching rows:{" "}
        <span className="whitespace-nowrap tabular-nums">
          {formatCount(figures.count)} facilities
        </span>
        ;{" "}
        <span className="whitespace-nowrap tabular-nums">
          {formatCount(figures.present)} of {formatCount(figures.barangays)} barangays
        </span>{" "}
        with a recorded facility.
      </p>
    </section>
  );
}
