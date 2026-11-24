"use client";

import { useId, useState } from "react";
import type { NhfrTypeCount } from "@/lib/db/nhfr";
import type { CatalogSelection } from "@/lib/catalog/types";
import {
  selectMix,
  searchMixRows,
  mixReading,
  MIX_NOTES,
  type MixView,
  type MixOrder,
} from "@/lib/facilities/mix";
import { headlineQuery } from "@/lib/facilities/headline";
import { formatCount, percentageFormatter } from "@/lib/format";
import { withBasePath } from "@/lib/base-path";
import { ExactTypeFigures } from "./exact-type-figures";
import { Modal } from "@/components/ui/modal";

const formats = ["png", "svg", "pdf", "csv", "xlsx", "pptx"];
const buttonClass = "min-h-11 rounded-md border border-border px-3 text-sm hover:bg-surface";

export function FacilityMixCard({
  items,
  total,
  selection,
  locationName,
}: {
  items: NhfrTypeCount[] | null;
  total: number;
  selection: CatalogSelection;
  locationName: string;
}) {
  const orderId = useId();
  const [selected, setSelected] = useState<string[]>([]);
  const [view, setView] = useState<Exclude<MixView, "table">>("mix");
  const [order, setOrder] = useState<MixOrder>("count");
  const [mode, setMode] = useState<"inline" | "maximize" | "present">("inline");
  const [slide, setSlide] = useState(0);
  const [expanded, setExpanded] = useState(false);
  const [notes, setNotes] = useState(false);
  const [exactOpen, setExactOpen] = useState(false);
  const [search, setSearch] = useState("");
  const rows = selectMix(items, total, selected, order);
  const exactRows = rows ? searchMixRows(rows, search) : [];
  const activeRows = exactOpen ? exactRows : rows;
  const activeView = exactOpen ? "table" : view;
  const options = [...(items ?? [])].sort((a, b) =>
    a.facilityType.localeCompare(b.facilityType, "en"),
  );
  const shareFormat = percentageFormatter(rows?.map((r) => (100 * r.nFacilities) / total) ?? []);
  const ownershipFormat = percentageFormatter(
    rows?.flatMap((r) =>
      r.nFacilities > 0
        ? [(100 * r.nGovernment) / r.nFacilities, (100 * r.nPrivate) / r.nFacilities]
        : [],
    ) ?? [],
  );
  const page = Math.max(0, Math.min(slide, Math.ceil((activeRows?.length ?? 0) / 8) - 1));
  const caption = `${locationName} · DOH NHFR · September 2026 snapshot`;
  const params = headlineQuery(selection, selected);
  params.set("view", activeView);
  if (exactOpen && search.trim()) params.set("q", search.trim());
  params.set("order", order);
  params.set("notes", notes ? "1" : "0");
  const actions = (
    <div className="flex flex-wrap items-start gap-2">
      <button
        type="button"
        className={buttonClass}
        onClick={() => setMode(mode === "maximize" ? "inline" : "maximize")}
      >
        {mode === "maximize" ? "Restore" : "Maximize"}
      </button>
      {activeRows?.length ? (
        <details>
          <summary className={`${buttonClass} cursor-pointer content-center`}>Export</summary>
          <div className="mt-2 flex max-w-48 flex-col gap-1 rounded-md border border-border bg-background p-2">
            {formats.map((format) => (
              <a
                className="min-h-11 content-center px-2 text-sm underline hover:text-accent"
                key={format}
                href={withBasePath(`/api/export/facilities/mix?${params}&format=${format}`)}
              >
                {format.toUpperCase()}
              </a>
            ))}
            <label className="flex min-h-11 items-center gap-2 text-xs">
              <input type="checkbox" checked={notes} onChange={(e) => setNotes(e.target.checked)} />
              Include technical notes in PDF
            </label>
          </div>
        </details>
      ) : (
        <span className="content-center text-sm text-muted">No matching rows to export</span>
      )}
      <button
        type="button"
        className={buttonClass}
        disabled={!activeRows?.length}
        onClick={() => {
          setSlide(0);
          setMode("present");
        }}
      >
        Present
      </button>
    </div>
  );
  const content = (presenting = false, context = "inline") => (
    <div className="@container min-w-0">
      {!presenting && rows && (
        <div className="my-4 space-y-3 border-b border-border pb-4">
          <div role="group" aria-label="Facility comparison view" className="flex flex-wrap gap-2">
            {(
              [
                ["mix", "Facility mix"],
                ["ownership", "Ownership within types"],
              ] as const
            ).map(([value, label]) => (
              <button
                type="button"
                key={value}
                aria-pressed={view === value}
                onClick={() => setView(value)}
                className={`${buttonClass} ${view === value ? "bg-accent text-accent-foreground hover:bg-accent" : ""}`}
              >
                {label}
              </button>
            ))}
          </div>
          <div className="flex flex-wrap items-start gap-4">
            <details className="min-w-0 flex-1 basis-60">
              <summary className="min-h-11 cursor-pointer content-center text-sm font-medium">
                Facility types:{" "}
                {selected.length ? `${selected.length} selected` : "All recorded types"}
              </summary>
              <p className="my-2 text-sm text-muted">
                Choose one or more types recorded here. This filter changes this comparison only.
              </p>
              <button type="button" className={buttonClass} onClick={() => setSelected([])}>
                All recorded types
              </button>
              <fieldset className="mt-2 grid gap-1 @xl:grid-cols-2">
                <legend className="sr-only">Facility comparison types, multiple selection</legend>
                {options.map((item) => (
                  <label
                    key={item.facilityType}
                    className="flex min-h-11 cursor-pointer items-center gap-3 rounded px-2 text-sm hover:bg-surface"
                  >
                    <input
                      type="checkbox"
                      className="size-4 shrink-0 accent-accent"
                      checked={selected.includes(item.facilityType)}
                      onChange={(e) => {
                        const checked = e.target.checked;
                        setSelected((prev) =>
                          checked
                            ? [...prev, item.facilityType].sort()
                            : prev.filter((t) => t !== item.facilityType),
                        );
                      }}
                    />
                    <span className="min-w-0 break-words">{item.facilityType}</span>
                  </label>
                ))}
              </fieldset>
            </details>
            <div className="grid min-w-0 max-w-full gap-1 text-sm">
              <label htmlFor={`${orderId}-${context}`}>Order types</label>
              <select
                id={`${orderId}-${context}`}
                className="min-h-11 max-w-full rounded-md border border-border bg-background px-2 text-base"
                value={order}
                onChange={(e) => setOrder(e.target.value as MixOrder)}
              >
                <option value="count">Most facilities first</option>
                <option value="lowest">Fewest facilities first</option>
                <option value="name">Name (A–Z)</option>
              </select>
            </div>
          </div>
        </div>
      )}
      {rows ? (
        <>
          <div aria-live="polite">
            <p className="my-3 text-sm font-medium">
              {mixReading(
                presenting && exactOpen ? exactRows : rows,
                total,
                selected.length > 0 || (presenting && exactOpen && !!search.trim()),
                presenting ? activeView : view,
              )}
            </p>
            <p className="mb-3 break-words text-sm text-muted">
              {selected.length ? `Selected: ${selected.join("; ")}` : "All recorded types"} ·{" "}
              {presenting && exactOpen
                ? "Exact figures · share of all facilities here"
                : view === "mix"
                  ? "Share of all facilities here"
                  : "Government/private share within each type"}
            </p>
          </div>
          {!(presenting && exactOpen) && (
            <div className="mb-4 flex flex-wrap gap-x-5 gap-y-2 text-sm">
              <span>
                <span
                  aria-hidden="true"
                  className="mr-2 inline-block size-3 rounded-sm bg-accent"
                />
                Government
              </span>
              <span>
                <span
                  aria-hidden="true"
                  className="mr-2 inline-block size-3 rounded-sm bg-muted/60"
                />
                Private
              </span>
            </div>
          )}
          {presenting && exactOpen && search.trim() && (
            <p className="my-3 text-sm text-muted">Exact-figure search: {search.trim()}</p>
          )}
          {presenting && (activeRows?.length ?? 0) > 8 && (
            <nav
              aria-label="Facility type slides"
              className="my-4 flex flex-wrap items-center gap-3"
            >
              <button
                type="button"
                disabled={page === 0}
                onClick={() => setSlide(page - 1)}
                className={`${buttonClass} disabled:opacity-50`}
              >
                Previous types
              </button>
              <span role="status" className="text-sm">
                Types {page * 8 + 1}–{Math.min((page + 1) * 8, activeRows?.length ?? 0)} of{" "}
                {activeRows?.length}
              </span>
              <button
                type="button"
                disabled={(page + 1) * 8 >= (activeRows?.length ?? 0)}
                onClick={() => setSlide(page + 1)}
                className={`${buttonClass} disabled:opacity-50`}
              >
                Next types
              </button>
            </nav>
          )}
          {presenting && exactOpen ? (
            <ExactTypeFigures
              rows={exactRows.slice(page * 8, (page + 1) * 8)}
              series={exactRows}
              total={total}
              presenting
            />
          ) : (
            <ol aria-label="Facility types compared" className="space-y-5">
              {rows
                .slice(
                  presenting ? page * 8 : 0,
                  presenting ? (page + 1) * 8 : expanded ? rows.length : 8,
                )
                .map((r) => {
                  const denominator = view === "mix" ? total : r.nFacilities;
                  return (
                    <li key={r.facilityType}>
                      <div className="flex flex-wrap justify-between gap-x-4 gap-y-1 text-sm">
                        <h4 className="min-w-0 break-words font-medium">{r.facilityType}</h4>
                        <div className="flex flex-wrap gap-x-1">
                          <span className="whitespace-nowrap tabular-nums">
                            {`${formatCount(r.nFacilities)} (${shareFormat((100 * r.nFacilities) / total)})`}
                          </span>
                          <span className="text-muted">of all facilities</span>
                        </div>
                      </div>
                      <div
                        role="img"
                        aria-label={`${r.facilityType}: ${r.nGovernment} government and ${r.nPrivate} private facilities`}
                        className="my-2 flex h-4 overflow-hidden rounded bg-surface"
                      >
                        {denominator > 0 && (
                          <>
                            <span
                              className="h-full bg-accent"
                              style={{ width: `${(100 * r.nGovernment) / denominator}%` }}
                            />
                            <span
                              className="h-full bg-muted/60"
                              style={{ width: `${(100 * r.nPrivate) / denominator}%` }}
                            />
                          </>
                        )}
                      </div>
                      <div className="flex flex-wrap gap-x-5 gap-y-1 text-sm text-muted">
                        <span>
                          Government:{" "}
                          <span className="whitespace-nowrap tabular-nums">
                            {formatCount(r.nGovernment)}
                            {r.nFacilities > 0
                              ? ` (${ownershipFormat((100 * r.nGovernment) / r.nFacilities)})`
                              : ""}
                          </span>
                        </span>
                        <span>
                          Private:{" "}
                          <span className="whitespace-nowrap tabular-nums">
                            {formatCount(r.nPrivate)}
                            {r.nFacilities > 0
                              ? ` (${ownershipFormat((100 * r.nPrivate) / r.nFacilities)})`
                              : ""}
                          </span>
                        </span>
                      </div>
                    </li>
                  );
                })}
            </ol>
          )}
          {!presenting && rows.length > 8 && (
            <button
              type="button"
              aria-expanded={expanded}
              onClick={() => setExpanded((v) => !v)}
              className={`${buttonClass} mt-4`}
            >
              {expanded ? "Show first 8 types" : `Show all ${rows.length} types`}
            </button>
          )}
          {!presenting && (
            <details
              open={exactOpen}
              onToggle={(e) => setExactOpen(e.currentTarget.open)}
              className="mt-4 border-t border-border pt-3"
            >
              <summary className="min-h-11 cursor-pointer content-center text-sm font-medium">
                View exact figures
              </summary>
              <div className="mt-3 space-y-3">
                <p className="text-sm text-muted">
                  These figures use the same selected types and order as the chart. Export and
                  Present use this table while it is open.
                </p>
                <div className="grid max-w-lg gap-2">
                  <label htmlFor={`${orderId}-${context}-search`} className="text-sm font-medium">
                    Search exact figures by type name
                  </label>
                  <input
                    id={`${orderId}-${context}-search`}
                    type="search"
                    maxLength={200}
                    value={search}
                    onChange={(e) => {
                      setSearch(e.target.value);
                      setSlide(0);
                    }}
                    className="min-h-11 w-full rounded-md border border-border bg-background px-3 text-base"
                  />
                  <p className="text-xs text-muted">
                    Search narrows this table only. Your type selections and the chart stay the
                    same.
                  </p>
                  {search && (
                    <button
                      type="button"
                      className={`${buttonClass} w-fit`}
                      onClick={() => setSearch("")}
                    >
                      Clear table search
                    </button>
                  )}
                </div>
                <p role="status" className="text-sm text-muted">
                  Showing {exactRows.length} of {rows.length} selected types
                  {search.trim() ? ` matching “${search.trim()}”` : ""}.
                </p>
                <ExactTypeFigures rows={exactRows} total={total} />
              </div>
            </details>
          )}
          {!presenting && (
            <details className="mt-4 text-sm text-muted">
              <summary className="min-h-11 cursor-pointer content-center font-medium">
                About this figure
              </summary>
              <div className="mt-2 space-y-2 rounded-md bg-surface p-3">
                {MIX_NOTES.map((note) => (
                  <p key={note}>{note}</p>
                ))}
                <p>
                  Area total: {formatCount(total)} registered facilities. The complete type and
                  ownership counts have been checked against this total. Chart downloads include
                  every selected type in the selected order. When exact figures are open, downloads
                  include matching table rows.
                </p>
              </div>
            </details>
          )}
        </>
      ) : (
        <p role="status" className="mt-3 text-sm">
          {total === 0
            ? "No registered facilities to divide into shares."
            : "Facility mix cannot be compared until all type and ownership counts reconcile with the area total. Exact figures and downloads are unavailable for this incomplete comparison."}
        </p>
      )}
      <footer className="mt-4 flex flex-wrap justify-between gap-2 border-t border-border pt-3 text-xs text-muted">
        <span>Source: DOH National Health Facility Registry</span>
        <span>Snapshot: September 2026</span>
      </footer>
    </div>
  );
  return (
    <section
      aria-label="Facility mix chart"
      data-testid="facility-mix-card"
      className="min-w-0 rounded-lg border border-border bg-background p-4 sm:p-6"
    >
      <div className="flex min-w-0 flex-wrap items-start justify-between gap-4">
        <div className="min-w-0 flex-1 basis-64">
          <h3 className="text-lg font-semibold">What kinds of facilities are recorded?</h3>
          <p className="mt-1 text-xs text-muted">{caption}</p>
        </div>
        {rows && actions}
      </div>
      {content()}
      <Modal
        open={mode !== "inline"}
        onClose={() => setMode("inline")}
        title={mode === "present" ? "Facility types · Present" : "Facility types · Maximized"}
        caption={caption}
        fullScreen={mode === "present"}
      >
        {mode !== "inline" && (
          <div className={mode === "present" ? "mx-auto my-auto w-full max-w-6xl" : undefined}>
            {mode === "maximize" && rows && actions}
            {content(mode === "present", "modal")}
          </div>
        )}
      </Modal>
    </section>
  );
}
