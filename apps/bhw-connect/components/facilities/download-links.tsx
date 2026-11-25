"use client";
import { useEffect, useId, useState } from "react";
import type { GeoLevel } from "@/lib/filters/schema";
import { withBasePath } from "@/lib/base-path";
import { formatCount } from "@/lib/format";
import {
  REPORT_SCOPE,
  REPORT_SNAPSHOT,
  REPORT_SOURCE,
  reportStatusSchema,
} from "@/lib/facilities/report";
export function DownloadLinks({
  geoLevel,
  geoCode,
  locationName,
}: {
  geoLevel: GeoLevel;
  geoCode: string;
  locationName: string;
}) {
  const id = useId();
  const [open, setOpen] = useState(false),
    [notes, setNotes] = useState(false),
    [attempt, setAttempt] = useState(0);
  const scope = new URLSearchParams({ geoLevel, geoCode }).toString();
  const key = `${scope}|${attempt}`;
  const [loaded, setLoaded] = useState<{
    key: string;
    data: ReturnType<typeof reportStatusSchema.parse> | null;
  }>({ key: "", data: null });
  const current = loaded.key === key ? loaded.data : null;
  const pending = loaded.key !== key;
  useEffect(() => {
    if (!open || loaded.key === key) return;
    const controller = new AbortController();
    (async () => {
      try {
        const response = await fetch(withBasePath(`/api/facilities/report?${scope}`), {
          signal: controller.signal,
        });
        if (!response.ok) throw Error("Unavailable");
        const parsed = reportStatusSchema.safeParse(await response.json());
        if (!parsed.success || parsed.data.code !== geoCode || parsed.data.level !== geoLevel)
          throw Error("Wrong scope");
        if (!controller.signal.aborted) setLoaded({ key, data: parsed.data });
      } catch {
        if (!controller.signal.aborted) setLoaded({ key, data: null });
      }
    })();
    return () => controller.abort();
  }, [open, key, scope, geoCode, geoLevel, loaded.key]);
  const query = new URLSearchParams(scope);
  query.set("notes", notes ? "1" : "0");
  const link = (format: string, label: string, primary = false) => (
    <a
      href={withBasePath(`/api/export/facilities/report?${query}&format=${format}`)}
      className={`min-h-11 content-center rounded-md border border-border px-3 py-2 text-sm font-medium ${primary ? "bg-accent text-accent-foreground" : "hover:bg-surface"}`}
    >
      {label}
    </a>
  );
  return (
    <section
      aria-label="Full location report"
      data-testid="facility-report-downloads"
      className="min-w-0 rounded-lg border border-border bg-background p-4 sm:p-6"
    >
      <h3 className="text-lg font-semibold">Download a report for this location</h3>
      <p className="mt-1 break-words text-sm font-medium">{locationName}</p>
      <p className="mt-1 text-xs text-muted">
        {REPORT_SOURCE} · {REPORT_SNAPSHOT} snapshot
      </p>
      <p className="mt-3 text-sm">{REPORT_SCOPE}</p>
      <details open={open} onToggle={(e) => setOpen(e.currentTarget.open)} className="mt-3">
        <summary className="min-h-11 cursor-pointer content-center text-sm font-medium">
          Choose report downloads
        </summary>
        <div className="mt-3 space-y-4">
          <p className="text-sm text-muted">
            The report includes the location overview, every recorded facility type, and all child
            areas where applicable. It contains aggregate figures; use the facility list for
            individual facilities.
          </p>
          {current ? (
            <>
              <p role="status" className="text-sm">
                Verified:{" "}
                <span className="whitespace-nowrap tabular-nums">
                  {formatCount(current.facilities)} facilities
                </span>{" "}
                across {current.types} recorded types
                {geoLevel === "citymun" ? "." : ` and ${current.areas} child areas.`}
              </p>
              <label
                htmlFor={id}
                className="flex min-h-11 cursor-pointer items-center gap-2 text-sm"
              >
                <input
                  id={id}
                  type="checkbox"
                  checked={notes}
                  onChange={(e) => setNotes(e.target.checked)}
                />
                Include technical notes in PDF, slides and image pages
              </label>
              <div className="flex flex-wrap gap-2">
                {link("pdf", "Download report (PDF)", true)}
                {link("pptx", "Presentation (PPTX)")}
              </div>
              <div className="flex flex-wrap gap-2">
                {link("png", "PNG pages (.zip)")}
                {link("svg", "SVG pages (.zip)")}
              </div>
              <p className="text-xs text-muted">
                Image downloads contain a ZIP of separate report pages. Every type and area is
                included across pages.
              </p>
              <div className="flex flex-wrap gap-2">
                {link("xlsx", "Aggregate tables (XLSX)")}
                {link("csv", "Aggregate tables (CSV)")}
              </div>
              <p className="text-xs text-muted">
                Data files include complete aggregate tables, exact values, denominators and source
                definitions.
              </p>
            </>
          ) : (
            <div role="status" className="text-sm">
              {pending
                ? "Checking the complete location report…"
                : "The complete location report is unavailable because its figures could not be verified. This is not a reported zero."}
              {!pending && (
                <button
                  type="button"
                  onClick={() => setAttempt((a) => a + 1)}
                  className="ml-2 min-h-11 rounded-md border border-border px-3"
                >
                  Try report again
                </button>
              )}
            </div>
          )}
        </div>
      </details>
    </section>
  );
}
