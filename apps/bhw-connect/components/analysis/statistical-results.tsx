"use client";
import type { StatisticalResult } from "@/lib/analysis-statistics/types";
import { statisticCells, statisticColumns, statisticNotes } from "@/lib/analysis-statistics/export";
import { withBasePath } from "@/lib/base-path";
import { useState } from "react";
export function StatisticalResults({
  results,
  projectId,
  jobId,
}: {
  results: StatisticalResult[];
  projectId: string;
  jobId: string;
}) {
  const [status, setStatus] = useState("");
  return (
    <>
      {results.map((r, index) => {
        const path = withBasePath(
          `/api/analysis/projects/${projectId}/statistics/${jobId}/${index}`,
        );
        return (
          <section
            key={r.id}
            className="flex min-w-0 flex-col gap-3 rounded border border-border p-3"
          >
            <h4 className="font-semibold">
              Table {index + 1}. {r.title}
            </h4>
            {r.narrative.map((text, i) => (
              <p key={i} className="text-sm">
                {text}
              </p>
            ))}
            <div className="overflow-x-auto">
              <table className="text-sm">
                <caption className="sr-only">{r.title}</caption>
                <thead>
                  <tr>
                    {statisticColumns.map((c) => (
                      <th scope="col" key={c} className="border-b border-border p-2 text-left">
                        {c}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody>
                  {statisticCells(r).map((row, i) => (
                    <tr key={i}>
                      {row.map((v, j) => (
                        <td key={j} className="border-b border-border p-2">
                          {v}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            {r.plot.length > 0 && (
              <figure>
                <h4 className="font-semibold">
                  Figure {index + 1}. {r.title}
                </h4>
                {/* Authenticated private SVG must bypass the public image optimiser. */}
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img
                  src={path + "?format=svg"}
                  alt={`Estimates and available confidence intervals: ${r.title}`}
                  className="h-auto w-full"
                />
                <figcaption className="text-xs">
                  Available intervals reflect the recorded method and design. Units and limitations
                  appear below.
                </figcaption>
              </figure>
            )}
            <details>
              <summary>Methods, sources and scientific notes</summary>
              {statisticNotes(r).map((note, i) => (
                <p key={i} className="my-1 break-words text-xs">
                  {note}
                </p>
              ))}
              <pre className="overflow-x-auto text-xs">
                {JSON.stringify(r.diagnostics, null, 2)}
              </pre>
            </details>
            <nav aria-label="Statistical exports" className="flex flex-wrap gap-3">
              {["csv", "xlsx", "png", "svg", "json"].map((f) => (
                <a key={f} href={path + "?format=" + f} className="text-sm underline">
                  {f.toUpperCase()}
                </a>
              ))}
              <button
                className="text-sm underline"
                onClick={async () => {
                  setStatus("Re-running saved calculation…");
                  try {
                    const response = await fetch(path, { method: "POST" });
                    const data = await response.json();
                    setStatus(
                      !response.ok
                        ? (data.error ?? "Replay unavailable")
                        : data.reproduced
                          ? "Calculation reproduced exactly."
                          : "Runtime/result differs; review the returned method and software versions.",
                    );
                  } catch {
                    setStatus("Replay unavailable.");
                  }
                }}
              >
                Re-run saved calculation
              </button>
            </nav>
          </section>
        );
      })}
      {status && <p role="status">{status}</p>}
    </>
  );
}
