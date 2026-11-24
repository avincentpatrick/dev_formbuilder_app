"use client";

import { useEffect, useState } from "react";
import type { CatalogDataset, CatalogSelection } from "@/lib/catalog/types";
import {
  acceptedHeadline,
  headlineQuery,
  headlineSchema,
  summaryMatches,
  type HeadlineSummary,
} from "@/lib/facilities/headline";
import { withBasePath } from "@/lib/base-path";
import { HeadlineValues } from "@/components/facilities/headline-values";
import { Modal } from "@/components/ui/modal";
import { NhfrOverviewFallback } from "./nhfr-overview-fallback";

const formats = ["png", "svg", "pdf", "csv", "xlsx", "pptx"] as const;

export function NhfrOverview({
  dataset,
  selection,
  locationName,
}: {
  dataset: CatalogDataset;
  selection: CatalogSelection;
  locationName: string;
}) {
  const base = acceptedHeadline(dataset, selection);
  const [types, setTypes] = useState<string[]>([]);
  const [options, setOptions] = useState<string[]>([]);
  const [result, setResult] = useState<{
    key: string;
    summary: HeadlineSummary | null;
    error?: string;
  } | null>(null);
  const [retry, setRetry] = useState(0);
  const [mode, setMode] = useState<"inline" | "maximize" | "present">("inline");
  const [includeNotes, setIncludeNotes] = useState(false);
  const key = headlineQuery(selection, types).toString();
  const baseKey = JSON.stringify(base);
  useEffect(() => {
    if (baseKey === "null") return;
    const accepted = JSON.parse(baseKey) as HeadlineSummary;
    const controller = new AbortController();
    const timer = setTimeout(async () => {
      try {
        const response = await fetch(withBasePath(`/api/facilities/headline?${key}`), {
          signal: controller.signal,
        });
        if (!response.ok) throw new Error("Summary unavailable");
        const summary = headlineSchema.parse(await response.json());
        const filtered = new URLSearchParams(key).getAll("type").length > 0;
        if (
          summary.total !== accepted.total ||
          summary.barangays !== accepted.barangays ||
          (!filtered && !summaryMatches(summary, accepted))
        )
          throw new Error("Summary mismatch");
        if (!controller.signal.aborted) {
          setResult({ key, summary });
          setOptions(summary.types);
        }
      } catch {
        if (!controller.signal.aborted)
          setResult({
            key,
            summary: null,
            error: "The selected figures could not be verified. Please try again.",
          });
      }
    }, 150);
    return () => {
      controller.abort();
      clearTimeout(timer);
    };
  }, [key, baseKey, retry]);
  if (!base)
    return (
      <NhfrOverviewFallback dataset={dataset} selection={selection} locationName={locationName} />
    );
  const current = result?.key === key ? result : null;
  const summary = current?.summary ?? (types.length === 0 ? base : null);
  const waiting = !current;
  const verified = !!current?.summary;
  const caption = `${locationName} · DOH National Health Facility Registry · September 2026 snapshot`;
  const content = (presenting = false) => (
    <div className="@container min-w-0">
      {!presenting && (
        <details className="my-5 border-b border-border pb-4">
          <summary className="min-h-11 cursor-pointer content-center text-sm font-medium">
            Facility types: {types.length ? `${types.length} selected` : "All facility types"}
          </summary>
          <p className="my-2 text-sm text-muted">
            Choose one or more types. This filter changes the headline only.
          </p>
          <button
            type="button"
            className="mb-2 min-h-11 rounded border border-border px-3 text-sm hover:bg-surface"
            onClick={() => setTypes([])}
          >
            All facility types
          </button>
          {options.length > 0 ? (
            <fieldset className="grid gap-1 @xl:grid-cols-2">
              <legend className="sr-only">Facility types, multiple selection</legend>
              {options.map((type) => (
                <label
                  key={type}
                  className="flex min-h-11 min-w-0 cursor-pointer items-center gap-3 rounded px-2 text-sm hover:bg-surface"
                >
                  <input
                    className="size-4 shrink-0 accent-accent"
                    type="checkbox"
                    checked={types.includes(type)}
                    onChange={(e) =>
                      setTypes((prev) =>
                        e.target.checked ? [...prev, type].sort() : prev.filter((t) => t !== type),
                      )
                    }
                  />
                  <span className="break-words">{type}</span>
                </label>
              ))}
            </fieldset>
          ) : (
            <p className="text-sm text-muted">
              {waiting
                ? "Loading facility types…"
                : verified
                  ? "No facility types are recorded for this location."
                  : "Facility types are unavailable until the summary can be verified."}
            </p>
          )}
        </details>
      )}
      <p className="my-3 break-words text-sm font-medium">
        {types.length ? `Selected: ${types.join("; ")}` : "All facility types"}
      </p>
      <div aria-live="polite" aria-busy={waiting}>
        {waiting && types.length > 0 && (
          <p role="status" className="mb-3 text-sm text-muted">
            {types.length ? "Updating the selected headline…" : "Checking facility-type details…"}
          </p>
        )}
        {current?.error && (
          <div role="status" className="mb-3 text-sm">
            <p>{current.error}</p>
            <button
              type="button"
              onClick={() => setRetry((n) => n + 1)}
              className="min-h-11 underline"
            >
              Retry
            </button>
          </div>
        )}
        {summary && (
          <HeadlineValues
            summary={summary}
            types={types}
            locationName={locationName}
            presenting={presenting}
          />
        )}
      </div>
      <footer className="mt-5 flex flex-wrap justify-between gap-2 border-t border-border pt-3 text-xs text-muted">
        <span>Source: DOH National Health Facility Registry</span>
        <span>Snapshot: September 2026</span>
      </footer>
    </div>
  );
  const exportParams = headlineQuery(selection, types);
  exportParams.set("notes", includeNotes ? "1" : "0");
  const toolbar = (
    <div className="flex flex-wrap items-start gap-2 text-sm">
      <button
        type="button"
        onClick={() => setMode("maximize")}
        className="min-h-11 rounded-md border border-border px-3 hover:bg-surface"
      >
        Maximize
      </button>
      <details className="max-w-full">
        <summary className="min-h-11 cursor-pointer content-center rounded-md border border-border px-3 hover:bg-surface">
          Export
        </summary>
        <div className="mt-2 flex max-w-full flex-col gap-1 rounded-md border border-border bg-background p-2">
          {verified ? (
            formats.map((format) => (
              <a
                key={format}
                className="min-h-11 content-center px-2 underline hover:text-accent"
                href={withBasePath(
                  `/api/export/facilities/headline?${exportParams}&format=${format}`,
                )}
              >
                {format.toUpperCase()}
              </a>
            ))
          ) : (
            <p className="max-w-48 p-2 text-xs text-muted">
              Exports become available when these figures are verified.
            </p>
          )}
          <label className="flex min-h-11 max-w-48 items-center gap-2 text-xs">
            <input
              type="checkbox"
              checked={includeNotes}
              onChange={(e) => setIncludeNotes(e.target.checked)}
            />
            Include technical notes in PDF
          </label>
        </div>
      </details>
      <button
        type="button"
        disabled={!summary || (waiting && types.length > 0)}
        onClick={() => setMode("present")}
        className="min-h-11 rounded-md border border-border px-3 hover:bg-surface disabled:opacity-50"
      >
        Present
      </button>
    </div>
  );
  return (
    <section
      data-testid="nhfr-overview"
      className="min-w-0 rounded-lg border border-border bg-background p-4 sm:p-6"
    >
      <div className="flex min-w-0 flex-wrap items-start justify-between gap-4">
        <div className="min-w-0 flex-1 basis-64">
          <h2 className="text-lg font-semibold tracking-tight">
            What health facilities are recorded here?
          </h2>
          <p className="mt-1 text-xs text-muted">{caption}</p>
        </div>
        {toolbar}
      </div>
      {content()}
      <Modal
        fullScreen={mode === "present"}
        open={mode !== "inline"}
        onClose={() => setMode("inline")}
        title={mode === "present" ? "Health facilities · Present" : "Health facilities · Maximized"}
        caption={caption}
      >
        {mode !== "inline" && (
          <div
            className={
              mode === "present" ? "mx-auto my-auto w-full max-w-6xl [&_h3]:text-lg" : undefined
            }
          >
            {mode === "maximize" && toolbar}
            {content(mode === "present")}
          </div>
        )}
      </Modal>
    </section>
  );
}
