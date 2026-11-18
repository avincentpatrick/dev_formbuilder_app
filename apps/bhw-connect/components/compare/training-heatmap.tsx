"use client";

import { useState } from "react";
import Link from "next/link";
import { percentageFormatter } from "@/lib/format";
import { comparisonHref } from "@/lib/bhw/comparison";
import { sortTrainingRows, type TrainingRow } from "@/lib/bhw/training-comparison";

export function TrainingHeatmap({
  rows,
  places,
  measure,
  focus,
  mapState,
}: {
  rows: TrainingRow[];
  places: { geoCode: string; geoName: string }[];
  measure: string;
  focus?: string;
  mapState?: string;
}) {
  const [sort, setSort] = useState("topic");
  const ordered = sortTrainingRows(rows, sort === "topic" ? null : Number(sort));
  const format = percentageFormatter(
    rows.flatMap((r) => r.cells.flatMap((c) => (c.value === null ? [] : [c.value]))),
  );
  return (
    <section aria-labelledby="training-matrix-heading" className="min-w-0 space-y-3">
      <h2 id="training-matrix-heading" className="text-xl font-semibold">
        Training across selected places
      </h2>
      <p>
        BHW Profiles 2025 · percentage of validated profiles trained in each topic. Topics overlap;
        do not add these percentages. Each place has its own denominator.
      </p>
      <p className="text-sm text-muted">
        Color intensity uses the same 0–100% scale for every cell. Exact values and availability are
        written in the table; no color interpretation is required. This compares the selected
        places, not a ranking of all peers.
      </p>
      <label className="block">
        Sort training topics
        <select
          className="mt-2 block w-full rounded border border-border bg-surface p-2"
          value={sort}
          onChange={(e) => setSort(e.target.value)}
        >
          <option value="topic">Topic name</option>
          {places.map((place, i) => (
            <option key={place.geoCode} value={i}>
              Highest coverage in {place.geoName} ({place.geoCode})
            </option>
          ))}
        </select>
      </label>
      <p className="text-sm text-muted">
        Equal percentages sort by topic name; unavailable values appear last when sorting by
        coverage. Select a topic to inspect its sources and exact counts below.
      </p>
      <div
        role="region"
        aria-label="Training comparison table, scroll horizontally for all places"
        tabIndex={0}
        className="max-w-full overflow-x-auto rounded border border-border focus-visible:outline-2"
      >
        <table className="w-full border-collapse text-sm">
          <caption className="sr-only">
            Training topics by selected place; exact percentages with reported counts and
            availability
          </caption>
          <thead>
            <tr>
              <th scope="col" className="min-w-48 p-3 text-left">
                Training topic
              </th>
              {places.map((p) => (
                <th key={p.geoCode} scope="col" className="min-w-44 p-3 text-left">
                  {p.geoName} · {p.geoCode}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {ordered.map((row) => (
              <tr key={row.id} className="border-t border-border">
                <th scope="row" className="p-3 text-left font-medium">
                  <Link
                    className="underline"
                    aria-current={row.id === measure ? "page" : undefined}
                    href={`${comparisonHref(
                      row.id,
                      places.map((p) => p.geoCode),
                      focus,
                      mapState,
                    )}#comparison-measure`}
                  >
                    {row.label}
                  </Link>
                </th>
                {row.cells.map((cell, i) => (
                  <td key={places[i].geoCode} className="p-3 align-top">
                    {cell.value === null ? (
                      <span>{cell.status}</span>
                    ) : (
                      <>
                        <span className="block font-semibold">{format(cell.value)}</span>
                        <span
                          aria-hidden="true"
                          className="my-1 block h-2 rounded bg-seq-6"
                          style={{ opacity: 0.1 + cell.value * 0.009 }}
                        />
                        <span className="block text-xs text-muted">
                          Trained: {cell.numerator?.toLocaleString("en-PH") ?? "not supplied"};
                          base: {cell.denominator?.toLocaleString("en-PH") ?? "not supplied"}
                        </span>
                        {cell.status && (
                          <span className="block text-xs text-muted">{cell.status}</span>
                        )}
                      </>
                    )}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </section>
  );
}
