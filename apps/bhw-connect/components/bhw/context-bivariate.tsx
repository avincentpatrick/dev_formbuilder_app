"use client";
import dynamic from "next/dynamic";
import { useMemo, useState } from "react";
import { contextBivariate, jointColors } from "@/lib/bhw/context-bivariate";
import type { ContextRow } from "@/lib/bhw/context-review";
import type { MapPayload } from "@/lib/maps/prototype";
import { BOUNDARY_CREDIT } from "@/lib/maps/boundary-credit";
const DatasetCanvas = dynamic(
  () => import("@/components/maps/dataset-canvas").then((m) => m.DatasetCanvas),
  { ssr: false, loading: () => <p role="status">Loading joint map…</p> },
);
const noCapture = () => {};
const number = (v: number) => v.toLocaleString("en", { maximumFractionDigits: 1 });
export function ContextBivariate({
  rows,
  data,
  label,
  unit,
  selected,
  onSelect,
  onRetry,
}: {
  rows: ContextRow[];
  data: MapPayload;
  label: string;
  unit: string;
  selected: string | null;
  onSelect: (code: string) => void;
  onRetry: () => void;
}) {
  const joint = useMemo(() => contextBivariate(rows), [rows]);
  const mapRows = useMemo(
    () => joint?.groups.map((r) => ({ code: r.code, name: r.name, value: r.group })) ?? [],
    [joint],
  );
  const [geometry, setGeometry] = useState<string[] | null>(null);
  if (!joint)
    return (
      <p role="status">
        The joint map needs at least four usable paired areas. The exact table remains available.
      </p>
    );
  const describe = (i: number) =>
    `${String.fromCharCode(65 + i)}: headcount ${i >= 2 ? ">" : "≤"} ${number(joint.headMedian)} people; ${label.toLowerCase()} ${i % 2 ? ">" : "≤"} ${number(joint.contextMedian)} ${unit}`;
  const missing =
    geometry === null
      ? null
      : joint.groups.filter((r) => r.group !== null && !geometry.includes(r.code)).length;
  return (
    <section aria-label="Joint workforce and context map" className="space-y-3">
      <h3 className="font-semibold">Two-variable map: headcount and {label.toLowerCase()}</h3>
      <p className="text-sm">
        Each axis splits at the median of the same eligible pairs as the scatter. Equal-to-median
        values stay in the lower group. These exploratory groups are not staffing standards or need
        categories. Groups can be empty.
      </p>
      <div role="region" aria-label="Joint map legend" tabIndex={0} className="overflow-auto">
        <table className="w-full text-left text-sm">
          <caption className="sr-only">
            Joint median groups; columns are context and rows are headcount
          </caption>
          <thead>
            <tr>
              <th scope="col" className="p-2">
                BHW headcount
              </th>
              <th scope="col" className="p-2">
                {label} ≤ {number(joint.contextMedian)} {unit}
              </th>
              <th scope="col" className="p-2">
                {label} &gt; {number(joint.contextMedian)} {unit}
              </th>
            </tr>
          </thead>
          <tbody>
            {[1, 0].map((y) => (
              <tr key={y}>
                <th scope="row" className="p-2">
                  {y ? "Above" : "At or below"} {number(joint.headMedian)} people
                </th>
                {[0, 1].map((x) => {
                  const i = y * 2 + x;
                  return (
                    <td key={i} className="p-2">
                      <span
                        aria-hidden="true"
                        className="mr-2 inline-block h-5 w-5 border border-border align-middle"
                        style={{ backgroundColor: jointColors[i] }}
                      />
                      {String.fromCharCode(65 + i)} · {joint.counts[i]}{" "}
                      {joint.counts[i] === 1 ? "area" : "areas"}
                    </td>
                  );
                })}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="text-sm">
        Grey = no usable pair.{" "}
        {missing === null
          ? "Checking shapes…"
          : `${missing} eligible areas lack matching shapes; their values and groups remain in the table.`}
      </p>
      <DatasetCanvas
        key={data.geometryUrl}
        url={data.geometryUrl}
        rows={mapRows}
        bins={joint.bins}
        selected={selected}
        onSelect={onSelect}
        onGeometry={setGeometry}
        onCapture={noCapture}
        onRetry={onRetry}
        expanded
        labels
        unit="joint group"
        formatValueLabel={describe}
        geographyLabel={data.childLevel}
      />
      <p className="text-xs text-muted">
        {BOUNDARY_CREDIT.attribution} ·{" "}
        <a className="underline" href={BOUNDARY_CREDIT.licenseUrl}>
          {BOUNDARY_CREDIT.license}
        </a>
      </p>
      <div
        role="region"
        aria-label="Joint map groups by area"
        tabIndex={0}
        className="max-h-72 overflow-auto"
      >
        <table className="w-full text-left text-sm">
          <caption className="sr-only">
            Exact joint group for every returned area; source values are in the Context exact values
            table
          </caption>
          <thead>
            <tr>
              <th scope="col" className="p-2">
                Area
              </th>
              <th scope="col" className="p-2">
                Joint group
              </th>
            </tr>
          </thead>
          <tbody>
            {joint.groups.map((r) => (
              <tr key={r.code} className="border-t border-border">
                <th scope="row" className="p-2 font-normal">
                  <button
                    className="min-h-11 underline"
                    aria-pressed={selected === r.code}
                    onClick={() => onSelect(r.code)}
                  >
                    {r.name} ({r.code})
                  </button>
                </th>
                <td className="p-2">{r.group === null ? "No usable pair" : describe(r.group)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
