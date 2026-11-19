"use client";
import { useId, useState } from "react";
import Link from "next/link";
import { filterTrainingOverview, type OverviewTrainingRow } from "@/lib/bhw/training-overview";
import { percentageFormatter } from "@/lib/format";

type Row = OverviewTrainingRow & { href: string; yearHref: string; compareHref: string };
export function TrainingTable({ rows }: { rows: Row[] }) {
  const sortId = useId();
  const [query, setQuery] = useState("");
  const [sort, setSort] = useState("topic");
  const filtered = filterTrainingOverview(rows, query, sort === "coverage");
  const format = percentageFormatter(
    rows.flatMap((r) => (r.coverage.value === null ? [] : [r.coverage.value])),
  );
  return (
    <div className="space-y-3">
      <div className="grid gap-3 sm:grid-cols-2">
        <label>
          Find a training topic
          <input
            className="mt-1 block w-full rounded border border-border bg-background p-2"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            placeholder="For example, blood pressure or TB"
            type="search"
          />
        </label>
        <div>
          <label htmlFor={sortId}>Sort topics</label>
          <select
            id={sortId}
            className="mt-1 block w-full rounded border border-border bg-background p-2"
            value={sort}
            onChange={(e) => setSort(e.target.value)}
          >
            <option value="topic">Topic name</option>
            <option value="coverage">Highest recorded training percentage</option>
          </select>
        </div>
      </div>
      <p role="status" className="text-sm text-muted">
        {filtered.length} of {rows.length} topics shown.
      </p>
      <p className="text-sm text-muted">
        Percentages use all checked profiles. Counts and bases are shown when supplied. The training
        year uses profiles recorded as trained in that topic, with a recorded year. Missing records
        do not prove that a worker has never been trained.
      </p>
      <div
        role="region"
        aria-label="Training topics table, scroll horizontally for all columns"
        tabIndex={0}
        className="max-w-full overflow-x-auto rounded border border-border focus-visible:outline-2"
      >
        <table className="w-full text-left text-sm">
          <caption className="sr-only">
            Recorded training percentage and median training year by topic
          </caption>
          <thead>
            <tr className="border-b border-border bg-surface">
              <th scope="col" className="min-w-56 p-3">
                Training topic
              </th>
              <th scope="col" className="min-w-48 p-3">
                Recorded as trained
              </th>
              <th scope="col" className="min-w-48 p-3">
                Training year (median)
              </th>
            </tr>
          </thead>
          <tbody>
            {filtered.map((row) => (
              <tr key={row.id} className="border-b border-border align-top">
                <th scope="row" className="p-3 font-medium">
                  <Link href={row.href} className="underline">
                    {row.label}
                  </Link>
                  {row.label !== row.sourceLabel && (
                    <details className="mt-2 text-xs font-normal">
                      <summary className="cursor-pointer">Original topic name</summary>
                      <p>{row.sourceLabel}</p>
                    </details>
                  )}
                  <Link href={row.compareHref} className="mt-2 block text-xs font-normal underline">
                    Compare this topic
                  </Link>
                </th>
                <td className="p-3">
                  {row.coverage.value === null ? (
                    row.coverage.status
                  ) : (
                    <>
                      <strong>{format(row.coverage.value)}</strong>
                      {row.coverage.numerator !== null && (
                        <p>
                          {row.coverage.numerator.toLocaleString("en-PH")} profiles recorded as
                          trained
                        </p>
                      )}
                      {row.coverage.denominator !== null && (
                        <p>Base: {row.coverage.denominator.toLocaleString("en-PH")} profiles</p>
                      )}
                      {row.coverage.status && <p>{row.coverage.status}</p>}
                    </>
                  )}
                </td>
                <td className="p-3">
                  {row.year === null ? (
                    row.yearStatus
                  ) : (
                    <>
                      <strong>{row.year}</strong>
                      {row.yearStatus && <p>{row.yearStatus}</p>}
                    </>
                  )}
                  <Link href={row.yearHref} className="mt-2 block underline">
                    Year details
                  </Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {filtered.length === 0 && <p>No matching topics. Try another word or clear the search.</p>}
    </div>
  );
}
