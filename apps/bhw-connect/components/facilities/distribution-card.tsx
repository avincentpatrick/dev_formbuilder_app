"use client";
import { useId, useState } from "react";
import Link from "next/link";
import type { CatalogSelection } from "@/lib/catalog/types";
import { catalogJourneyHref } from "@/lib/catalog/journey";
import { headlineQuery } from "@/lib/facilities/headline";
import {
  distributionTitle,
  selectDistribution,
  distributionReading,
  DISTRIBUTION_NOTES,
  type DistributionInput,
  type DistributionOrder,
} from "@/lib/facilities/distribution";
import { formatCount, percentageFormatter } from "@/lib/format";
import { withBasePath } from "@/lib/base-path";
import { selectAreaFigures, AREA_NOTES, type AreaOrder } from "@/lib/facilities/area-figures";
import type { PresenceInput } from "@/lib/facilities/presence";
import { ExactAreaFigures } from "./exact-area-figures";
import { useAreaPresence } from "./use-area-presence";
import { Modal } from "@/components/ui/modal";
const button = "min-h-11 rounded-md border border-border px-3 text-sm hover:bg-surface";
export function DistributionCard({
  input,
  presence = null,
  selection,
  locationName,
  mapState,
}: {
  input: DistributionInput | null;
  presence?: PresenceInput | null;
  selection: CatalogSelection;
  locationName: string;
  mapState?: string;
}) {
  const id = useId();
  const [areas, setAreas] = useState<string[]>([]);
  const [types, setTypes] = useState<string[]>([]);
  const [order, setOrder] = useState<DistributionOrder>("highest");
  const [mode, setMode] = useState<"inline" | "maximize" | "present">("inline");
  const [expanded, setExpanded] = useState(false);
  const [slide, setSlide] = useState(0);
  const [notes, setNotes] = useState(false);
  const [exactOpen, setExactOpen] = useState(false);
  const [search, setSearch] = useState("");
  const [tableOrder, setTableOrder] = useState<AreaOrder | "chart">("chart");
  const [ownership, setOwnership] = useState(false);
  const union = useAreaPresence(presence, selection, types, exactOpen);
  const exactOrder = tableOrder === "chart" ? order : tableOrder;
  const figures = selectAreaFigures(input, union.data, areas, types, exactOrder, search);
  const result = selectDistribution(input, areas, types, order);
  const activeCount = exactOpen ? (figures?.rows.length ?? 0) : (result?.rows.length ?? 0);
  const canExport = !exactOpen || !!figures?.rows.length;
  const page = Math.max(0, Math.min(slide, Math.ceil(activeCount / 8) - 1));
  const title = distributionTitle(selection.mode === "psgc" ? selection.level : "national");
  const caption = `${locationName} · DOH NHFR · September 2026 snapshot`;
  const format = percentageFormatter(
    result && result.parent.count > 0
      ? result.rows.map((r) => (100 * r.count) / result.parent.count)
      : [],
  );
  const query = headlineQuery(selection, types);
  for (const area of [...new Set(areas)].sort()) query.append("area", area);
  query.set("order", exactOpen ? exactOrder : order);
  if (exactOpen) {
    query.set("view", "table");
    query.set("ownership", ownership ? "1" : "0");
    if (search.trim()) query.set("q", search.trim());
  }
  query.set("notes", notes ? "1" : "0");
  const areaOptions = [...(input?.areas ?? [])].sort((a, b) => a.name.localeCompare(b.name, "en"));
  const typeOptions = [...(input?.types ?? [])].sort((a, b) => a.type.localeCompare(b.type, "en"));
  const actions = (
    <div className="flex flex-wrap items-start gap-2">
      <button
        type="button"
        className={button}
        onClick={() => setMode(mode === "maximize" ? "inline" : "maximize")}
      >
        {mode === "maximize" ? "Restore" : "Maximize"}
      </button>
      {canExport ? (
        <details>
          <summary className={`${button} cursor-pointer content-center`}>Export</summary>
          <div className="mt-2 flex max-w-48 flex-col gap-1 rounded-md border border-border bg-background p-2">
            {["png", "svg", "pdf", "csv", "xlsx", "pptx"].map((format) => (
              <a
                key={format}
                className="min-h-11 content-center px-2 text-sm underline hover:text-accent"
                href={withBasePath(
                  `/api/export/facilities/${exactOpen ? "areas" : "distribution"}?${query}&format=${format}`,
                )}
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
        <span className="content-center text-sm text-muted">
          Exact figures are not ready to export
        </span>
      )}
      <button
        type="button"
        className={button}
        disabled={!canExport}
        onClick={() => {
          setSlide(0);
          setMode("present");
        }}
      >
        Present
      </button>
    </div>
  );
  const toggle = (
    value: string,
    checked: boolean,
    set: (fn: (prev: string[]) => string[]) => void,
  ) => set((prev) => (checked ? [...prev, value].sort() : prev.filter((v) => v !== value)));
  const content = (presenting = false, context = "inline") => (
    <div className="@container min-w-0">
      {result && input ? (
        <>
          {!presenting && (
            <div className="my-4 space-y-3 border-b border-border pb-4">
              <p className="text-sm text-muted">
                Choose areas and facility types for this comparison. Other cards keep their own
                selections.
              </p>
              <div className="grid min-w-0 gap-3 @xl:grid-cols-2">
                <details className="min-w-0">
                  <summary className="min-h-11 cursor-pointer content-center text-sm font-medium">
                    Areas: {areas.length ? `${areas.length} selected` : "All areas"}
                  </summary>
                  <button type="button" className={`${button} my-2`} onClick={() => setAreas([])}>
                    All areas
                  </button>
                  <fieldset className="space-y-1">
                    <legend className="sr-only">Areas, multiple selection</legend>
                    {areaOptions.map((a) => (
                      <label
                        key={a.code}
                        className="flex min-h-11 cursor-pointer items-center gap-3 rounded px-2 text-sm hover:bg-surface"
                      >
                        <input
                          type="checkbox"
                          className="size-4 shrink-0 accent-accent"
                          checked={areas.includes(a.code)}
                          onChange={(e) => toggle(a.code, e.target.checked, setAreas)}
                        />
                        <span className="min-w-0 break-words">{a.name}</span>
                      </label>
                    ))}
                  </fieldset>
                </details>
                <details className="min-w-0">
                  <summary className="min-h-11 cursor-pointer content-center text-sm font-medium">
                    Facility types:{" "}
                    {types.length ? `${types.length} selected` : "All recorded types"}
                  </summary>
                  <button type="button" className={`${button} my-2`} onClick={() => setTypes([])}>
                    All recorded types
                  </button>
                  <fieldset className="space-y-1">
                    <legend className="sr-only">
                      Geographic facility types, multiple selection
                    </legend>
                    {typeOptions.length ? (
                      typeOptions.map((t) => (
                        <label
                          key={t.type}
                          className="flex min-h-11 cursor-pointer items-center gap-3 rounded px-2 text-sm hover:bg-surface"
                        >
                          <input
                            type="checkbox"
                            className="size-4 shrink-0 accent-accent"
                            checked={types.includes(t.type)}
                            onChange={(e) => toggle(t.type, e.target.checked, setTypes)}
                          />
                          <span className="min-w-0 break-words">{t.type}</span>
                        </label>
                      ))
                    ) : (
                      <p className="text-sm text-muted">
                        No facility types are recorded in this parent location.
                      </p>
                    )}
                  </fieldset>
                </details>
              </div>
              <div className="grid max-w-full gap-1 text-sm">
                <label htmlFor={`${id}-${context}`}>Order areas</label>
                <select
                  id={`${id}-${context}`}
                  className="min-h-11 max-w-full rounded-md border border-border bg-background px-2 text-base @xl:w-fit"
                  value={order}
                  onChange={(e) => setOrder(e.target.value as DistributionOrder)}
                >
                  <option value="highest">Most facilities first</option>
                  <option value="lowest">Fewest facilities first</option>
                  <option value="name">Name (A–Z)</option>
                </select>
              </div>
            </div>
          )}
          <div aria-live="polite">
            <p className="my-3 text-sm font-medium">
              {distributionReading(result, areas.length > 0, locationName)}
            </p>
            <p className="mb-2 break-words text-sm text-muted">
              {areas.length
                ? `Areas: ${areaOptions
                    .filter((a) => areas.includes(a.code))
                    .map((a) => a.name)
                    .join("; ")}`
                : "All areas"}{" "}
              · {types.length ? `Types: ${types.join("; ")}` : "All recorded facility types"}
            </p>
            <p className="mb-3 text-sm">
              Total for {locationName}:{" "}
              <span className="whitespace-nowrap font-medium tabular-nums">
                {formatCount(result.parent.count)} facilities
              </span>
              {types.length > 0 ? " of the selected types" : ""}.
            </p>
          </div>
          {!(presenting && exactOpen) && (
            <>
              <div className="mb-3 flex flex-wrap gap-x-5 gap-y-2 text-sm">
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
              <p className="mb-4 text-xs text-muted">
                Common count scale: 0–{formatCount(result.maximum)} facilities.
              </p>
            </>
          )}
          {presenting && exactOpen && search.trim() && (
            <p className="my-3 text-sm">Exact-area search: {search.trim()}</p>
          )}
          {presenting && activeCount > 8 && (
            <nav
              aria-label="Area comparison slides"
              className="my-3 flex flex-wrap items-center gap-3"
            >
              <button
                type="button"
                className={`${button} disabled:opacity-50`}
                disabled={page === 0}
                onClick={() => setSlide(page - 1)}
              >
                Previous areas
              </button>
              <span role="status" className="text-sm">
                Areas {page * 8 + 1}–{Math.min((page + 1) * 8, activeCount)} of {activeCount}
              </span>
              <button
                type="button"
                className={`${button} disabled:opacity-50`}
                disabled={(page + 1) * 8 >= activeCount}
                onClick={() => setSlide(page + 1)}
              >
                Next areas
              </button>
            </nav>
          )}
          {presenting && exactOpen ? (
            figures ? (
              <ExactAreaFigures
                figures={figures}
                rows={figures.rows.slice(page * 8, (page + 1) * 8)}
                ownership={ownership}
                mapState={mapState}
              />
            ) : (
              <p role="status">Exact area figures are unavailable.</p>
            )
          ) : (
            <ol aria-label="Areas compared" className="space-y-5">
              {result.rows
                .slice(
                  presenting ? page * 8 : 0,
                  presenting ? (page + 1) * 8 : expanded ? result.rows.length : 8,
                )
                .map((a) => (
                  <li key={a.code}>
                    <div className="flex flex-wrap justify-between gap-x-4 gap-y-1 text-sm">
                      <Link
                        href={catalogJourneyHref(
                          { mode: "psgc", level: a.level, code: a.code },
                          "nhfr",
                          mapState,
                        )}
                        className="min-w-0 break-words font-medium underline"
                      >
                        {a.name}
                      </Link>
                      <div className="flex flex-wrap gap-x-1">
                        <span className="whitespace-nowrap tabular-nums">
                          {result.parent.count > 0
                            ? `${formatCount(a.count)} (${format((100 * a.count) / result.parent.count)})`
                            : `${formatCount(a.count)} · share unavailable`}
                        </span>
                        {result.parent.count > 0 && (
                          <span className="text-muted">of this total</span>
                        )}
                      </div>
                    </div>
                    <div
                      role="img"
                      aria-label={`${a.name}: ${a.government} government and ${a.private} private facilities`}
                      className="my-2 flex h-4 overflow-hidden rounded bg-surface"
                    >
                      {result.maximum > 0 && (
                        <>
                          <span
                            className="h-full bg-accent"
                            style={{ width: `${(100 * a.government) / result.maximum}%` }}
                          />
                          <span
                            className="h-full bg-muted/60"
                            style={{ width: `${(100 * a.private) / result.maximum}%` }}
                          />
                        </>
                      )}
                    </div>
                    <div className="flex flex-wrap gap-x-5 gap-y-1 text-sm text-muted">
                      <span className="whitespace-nowrap">
                        Government: {formatCount(a.government)}
                      </span>
                      <span className="whitespace-nowrap">Private: {formatCount(a.private)}</span>
                    </div>
                  </li>
                ))}
            </ol>
          )}
          {!presenting && result.rows.length > 8 && (
            <button
              type="button"
              className={`${button} mt-4`}
              aria-expanded={expanded}
              onClick={() => setExpanded((v) => !v)}
            >
              {expanded ? "Show first 8 areas" : `Show all ${result.rows.length} areas`}
            </button>
          )}
          {!presenting && (
            <details
              open={exactOpen}
              onToggle={(e) => setExactOpen(e.currentTarget.open)}
              className="mt-4 border-t border-border pt-3"
            >
              <summary className="min-h-11 cursor-pointer content-center text-sm font-medium">
                View exact area figures
              </summary>
              <div className="mt-3 space-y-3">
                <p className="text-sm text-muted">
                  This table follows the areas and facility types selected above. Export and Present
                  use matching table rows while it is open. The separate barangay-presence card
                  keeps its own selections.
                </p>
                <div className="grid gap-3 @xl:grid-cols-2">
                  <div className="grid min-w-0 gap-2">
                    <label htmlFor={`${id}-${context}-search`} className="text-sm font-medium">
                      Search exact areas by name
                    </label>
                    <input
                      id={`${id}-${context}-search`}
                      type="search"
                      value={search}
                      maxLength={200}
                      onChange={(e) => {
                        setSearch(e.target.value);
                        setSlide(0);
                      }}
                      className="min-h-11 w-full rounded-md border border-border bg-background px-3 text-base"
                    />
                    <p className="text-xs text-muted">
                      Search narrows this table only. Area and type selections stay the same.
                    </p>
                    {search && (
                      <button
                        type="button"
                        className={`${button} w-fit`}
                        onClick={() => setSearch("")}
                      >
                        Clear area search
                      </button>
                    )}
                  </div>
                  <div className="grid min-w-0 content-start gap-2">
                    <label htmlFor={`${id}-${context}-exact-order`} className="text-sm font-medium">
                      Order exact areas
                    </label>
                    <select
                      id={`${id}-${context}-exact-order`}
                      value={tableOrder}
                      onChange={(e) => {
                        setTableOrder(e.target.value as AreaOrder | "chart");
                        setSlide(0);
                      }}
                      className="min-h-11 max-w-full rounded-md border border-border bg-background px-2 text-base"
                    >
                      <option value="chart">Use chart order</option>
                      <option value="highest">Most facilities first</option>
                      <option value="lowest">Fewest facilities first</option>
                      <option value="presence-highest">Highest barangay share first</option>
                      <option value="presence-lowest">Lowest barangay share first</option>
                      <option value="name">Name (A–Z)</option>
                    </select>
                  </div>
                </div>
                <label className="flex min-h-11 items-center gap-2 text-sm">
                  <input
                    type="checkbox"
                    checked={ownership}
                    onChange={(e) => setOwnership(e.target.checked)}
                  />
                  Show government/private counts
                </label>
                {figures ? (
                  <>
                    <p role="status" className="text-sm text-muted">
                      Showing {figures.rows.length} of {figures.selectedAreas} selected areas
                      {search.trim() ? ` matching “${search.trim()}”` : ""}.
                    </p>
                    <ExactAreaFigures figures={figures} ownership={ownership} mapState={mapState} />
                  </>
                ) : (
                  <div role="status" className="text-sm">
                    {union.pending
                      ? "Updating exact area figures…"
                      : "The combined area figures could not be verified. Missing data is not a reported zero."}
                    {!union.pending && (
                      <button type="button" className={`${button} ml-2`} onClick={union.retry}>
                        Try exact figures again
                      </button>
                    )}
                  </div>
                )}
              </div>
            </details>
          )}
        </>
      ) : (
        <p role="status" className="mt-4 text-sm">
          The complete area and facility-type distribution could not be verified. No ranking is
          shown. Missing data is not a reported zero.
        </p>
      )}
      {!presenting && (
        <details className="mt-4 text-sm text-muted">
          <summary className="min-h-11 cursor-pointer content-center font-medium">
            About this figure
          </summary>
          <div className="mt-2 space-y-2 rounded-md bg-surface p-3">
            {[...DISTRIBUTION_NOTES, ...AREA_NOTES].map((note) => (
              <p key={note}>{note}</p>
            ))}
          </div>
        </details>
      )}
      <footer className="mt-4 flex flex-wrap justify-between gap-2 border-t border-border pt-3 text-xs text-muted">
        <span>Source: DOH National Health Facility Registry</span>
        <span>Snapshot: September 2026</span>
      </footer>
    </div>
  );
  return (
    <section
      aria-label="Facility distribution chart"
      data-testid="facility-distribution-card"
      className="min-w-0 rounded-lg border border-border bg-background p-4 sm:p-6"
    >
      <div className="flex min-w-0 flex-wrap items-start justify-between gap-4">
        <div className="min-w-0 flex-1 basis-64">
          <h3 className="text-lg font-semibold">{title}</h3>
          <p className="mt-1 text-xs text-muted">{caption}</p>
        </div>
        {result && actions}
      </div>
      {content()}
      <Modal
        open={mode !== "inline"}
        onClose={() => setMode("inline")}
        fullScreen={mode === "present"}
        title={
          mode === "present"
            ? "Geographic distribution · Present"
            : "Geographic distribution · Maximized"
        }
        caption={caption}
      >
        {mode !== "inline" && (
          <div className={mode === "present" ? "mx-auto my-auto w-full max-w-6xl" : undefined}>
            {mode === "maximize" && result && actions}
            {content(mode === "present", "modal")}
          </div>
        )}
      </Modal>
    </section>
  );
}
