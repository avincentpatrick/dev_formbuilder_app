import Link from "next/link";
import { notFound } from "next/navigation";
import { getGeoByCode } from "@/lib/db/geo";
import {
  getFhsisDefinitions,
  getFhsisBreakdowns,
  fhsisAreaHref,
  fhsisBreakdownLabel,
} from "@/lib/db/fhsis";

export const dynamic = "force-dynamic";
export const metadata = { title: "Morbidity age and sex · FHSIS 2025" };

export default async function MorbidityBreakdowns({
  params,
}: {
  params: Promise<{ indicator: string; geoCode: string }>;
}) {
  const { indicator, geoCode } = await params;
  if (!/^morb_\d{2}_\d{3}$/.test(indicator) || !/^(PH|\d{2,7})$/.test(geoCode)) notFound();
  const [geo, definitions] = await Promise.all([getGeoByCode(geoCode), getFhsisDefinitions()]);
  if (!geo || geo.geoLevel === "barangay") notFound();
  if (!definitions) return <p role="status">Morbidity data is temporarily unavailable.</p>;
  const definition = definitions.find(
    (d) => d.indicator_key === indicator && d.program_area === "morbidity",
  );
  if (!definition) notFound();
  const rows = await getFhsisBreakdowns(geoCode, geo.geoLevel, indicator);
  return (
    <div className="flex flex-col gap-6">
      <Link href={fhsisAreaHref(geo.geoLevel, geoCode)} className="underline">
        Back to health services
      </Link>
      <h1 className="text-2xl font-semibold break-words">{definition.label}</h1>
      <p>{geo.geoName} · January–December 2025 · FHSIS partial release</p>
      <p className="text-sm text-muted">
        These are cumulative case reports, not unique patients. Region XII’s May report is missing.
        The source counts blank submitted cells as zero, so zero does not establish complete
        reporting. All-age totals and age groups are different views of the same cases; do not add
        them together. Overlapping ICD categories must not be added as distinct cases.
      </p>
      <p className="text-sm text-muted">
        Age and sex figures are counts only; the source supplies no matching population
        denominators.
      </p>
      {rows === null ? (
        <p role="status">Breakdown data is temporarily unavailable.</p>
      ) : rows.length === 0 ? (
        <p>No separate morbidity breakdown was published for this area.</p>
      ) : (
        <div
          className="overflow-x-auto"
          role="region"
          aria-label="Morbidity breakdowns"
          tabIndex={0}
        >
          <table className="w-full text-left text-sm">
            <caption className="sr-only">Age and sex case counts for {geo.geoName}</caption>
            <thead>
              <tr>
                <th scope="col" className="p-3">
                  Age / sex
                </th>
                <th scope="col" className="p-3">
                  Reported cases
                </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.breakdown} className="border-t border-border">
                  <th scope="row" className="p-3 font-medium">
                    {fhsisBreakdownLabel(r.breakdown)}
                  </th>
                  <td className="p-3">
                    {r.numerator === null ? "Not reported" : r.numerator.toLocaleString("en-PH")}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <details className="text-sm text-muted">
        <summary className="cursor-pointer">Definition and source</summary>
        <p className="mt-2">{definition.numerator_def}</p>
        <p className="break-words">
          {definition.source_workbook}; provincial companion workbook for province/HUC rows.
        </p>
      </details>
    </div>
  );
}
