"use client";
import { useEffect, useId, useState } from "react";
import Link from "next/link";
import type { CatalogSelection } from "@/lib/catalog/types";
import { catalogJourneyHref } from "@/lib/catalog/journey";
import { headlineQuery } from "@/lib/facilities/headline";
import {
  PRESENCE_TITLE,
  PRESENCE_READING_LIMIT,
  selectPresence,
  presenceReading,
  presenceSchema,
  PRESENCE_NOTES,
  type PresenceInput,
  type PresenceOrder,
} from "@/lib/facilities/presence";
import { formatCount, percentageFormatter } from "@/lib/format";
import { withBasePath } from "@/lib/base-path";
import { Modal } from "@/components/ui/modal";
const button = "min-h-11 rounded-md border border-border px-3 text-sm hover:bg-surface";
export function PresenceCard({
  input,
  selection,
  locationName,
  mapState,
}: {
  input: PresenceInput | null;
  selection: CatalogSelection;
  locationName: string;
  mapState?: string;
}) {
  const id = useId();
  const [areas, setAreas] = useState<string[]>([]);
  const [types, setTypes] = useState<string[]>([]);
  const [order, setOrder] = useState<PresenceOrder>("lowest");
  const [mode, setMode] = useState<"inline" | "maximize" | "present">("inline");
  const [expanded, setExpanded] = useState(false);
  const [slide, setSlide] = useState(0);
  const [notes, setNotes] = useState(false);
  const selectionKey = headlineQuery(selection).toString();
  const typeKey = JSON.stringify([...types].sort());
  const [loaded, setLoaded] = useState<{ key: string; input: PresenceInput | null }>({
    key: "",
    input: null,
  });
  const [retry, setRetry] = useState(0);
  const requestKey = `${selectionKey}|${typeKey}|${retry}`;
  const current = types.length === 0 ? input : loaded.key === requestKey ? loaded.input : null;
  const pending = types.length > 0 && loaded.key !== requestKey;
  useEffect(() => {
    if (typeKey === "[]" || !input) return;
    const controller = new AbortController();
    const timer = setTimeout(async () => {
      try {
        const query = new URLSearchParams(selectionKey);
        for (const type of JSON.parse(typeKey) as string[]) query.append("type", type);
        const response = await fetch(withBasePath(`/api/facilities/presence?${query}`), {
          signal: controller.signal,
        });
        if (!response.ok) throw new Error("Unavailable");
        const parsed = presenceSchema.safeParse(await response.json());
        if (
          !parsed.success ||
          JSON.stringify([...parsed.data.selectedTypes].sort()) !== typeKey ||
          parsed.data.code !== input?.code ||
          parsed.data.level !== input?.level ||
          JSON.stringify([...parsed.data.types].sort()) !==
            JSON.stringify([...input.types].sort()) ||
          parsed.data.rows.length !== input.rows.length ||
          parsed.data.rows.some(
            (a) =>
              !input.rows.some(
                (b) =>
                  a.code === b.code &&
                  a.level === b.level &&
                  a.barangays === b.barangays &&
                  a.present <= b.present,
              ),
          )
        )
          throw new Error("Invalid summary");
        if (!controller.signal.aborted) setLoaded({ key: requestKey, input: parsed.data });
      } catch {
        if (!controller.signal.aborted) setLoaded({ key: requestKey, input: null });
      }
    }, 200);
    return () => {
      clearTimeout(timer);
      controller.abort();
    };
  }, [selectionKey, typeKey, requestKey, input]);
  const result = selectPresence(current, areas, order);
  const page = Math.max(0, Math.min(slide, Math.ceil((result?.rows.length ?? 0) / 8) - 1));
  const title = PRESENCE_TITLE;
  const caption = `${locationName} · DOH NHFR · September 2026 snapshot`;
  const format = percentageFormatter(
    result?.rows.flatMap((a) => (a.share === null ? [] : [a.share])) ?? [],
  );
  const query = headlineQuery(selection, types);
  for (const area of [...new Set(areas)].sort()) query.append("area", area);
  query.set("order", order);
  query.set("notes", notes ? "1" : "0");
  const areaOptions = [...(input?.rows ?? [])].sort((a, b) => a.name.localeCompare(b.name, "en"));
  const typeOptions = [...(input?.types ?? [])].sort((a, b) => a.localeCompare(b, "en"));
  const actions = (
    <div className="flex flex-wrap items-start gap-2">
      <button
        type="button"
        className={button}
        onClick={() => setMode(mode === "maximize" ? "inline" : "maximize")}
      >
        {mode === "maximize" ? "Restore" : "Maximize"}
      </button>
      <details>
        <summary className={`${button} cursor-pointer content-center`}>Export</summary>
        <div className="mt-2 flex max-w-48 flex-col gap-1 rounded-md border border-border bg-background p-2">
          {["png", "svg", "pdf", "csv", "xlsx", "pptx"].map((format) => (
            <a
              key={format}
              className="min-h-11 content-center px-2 text-sm underline hover:text-accent"
              href={withBasePath(`/api/export/facilities/presence?${query}&format=${format}`)}
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
      <button
        type="button"
        className={button}
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
      {input ? (
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
                      Barangay presence facility types, multiple selection
                    </legend>
                    {typeOptions.length ? (
                      typeOptions.map((t) => (
                        <label
                          key={t}
                          className="flex min-h-11 cursor-pointer items-center gap-3 rounded px-2 text-sm hover:bg-surface"
                        >
                          <input
                            type="checkbox"
                            className="size-4 shrink-0 accent-accent"
                            checked={types.includes(t)}
                            onChange={(e) => toggle(t, e.target.checked, setTypes)}
                          />
                          <span className="min-w-0 break-words">{t}</span>
                        </label>
                      ))
                    ) : (
                      <p className="text-sm text-muted">
                        No facility types are recorded in this snapshot.
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
                  onChange={(e) => setOrder(e.target.value as PresenceOrder)}
                >
                  <option value="highest">Highest percentage first</option>
                  <option value="lowest">Lowest percentage first</option>
                  <option value="name">Name (A–Z)</option>
                </select>
              </div>
            </div>
          )}
          {result ? (
            <>
              <div aria-live="polite">
                <p className="my-3 text-base font-medium">
                  {presenceReading(result, types.length > 0)}
                </p>
                <p className="mb-2 break-words text-sm text-muted">
                  {areas.length
                    ? `Areas: ${areaOptions
                        .filter((a) => areas.includes(a.code))
                        .map((a) => a.name)
                        .join("; ")}`
                    : "All areas"}
                  {" · "}
                  {types.length ? `Types: ${types.join("; ")}` : "All recorded facility types"}
                </p>
              </div>
              <div className="mb-3 flex flex-wrap gap-x-5 gap-y-2 text-sm">
                <span>
                  <span
                    aria-hidden="true"
                    className="mr-2 inline-block size-3 rounded-sm bg-accent"
                  />
                  With a recorded facility
                </span>
                <span>
                  <span
                    aria-hidden="true"
                    className="mr-2 inline-block size-3 rounded-sm border border-border bg-surface"
                  />
                  {types.length ? "No facility of selected types recorded" : "No facility recorded"}
                </span>
              </div>
              <p className="mb-4 text-xs text-muted">
                Share of each area’s barangays · Scale: 0–100%.
              </p>
              {presenting && result.rows.length > 8 && (
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
                    Areas {page * 8 + 1}–{Math.min((page + 1) * 8, result.rows.length)} of{" "}
                    {result.rows.length}
                  </span>
                  <button
                    type="button"
                    className={`${button} disabled:opacity-50`}
                    disabled={(page + 1) * 8 >= result.rows.length}
                    onClick={() => setSlide(page + 1)}
                  >
                    Next areas
                  </button>
                </nav>
              )}
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
                        {a.share === null ? (
                          <div className="flex flex-wrap gap-x-1 tabular-nums">
                            <span>Percentage unavailable ·</span>
                            <span className="whitespace-nowrap">
                              {formatCount(a.present)} of {formatCount(a.barangays)} barangays
                            </span>
                          </div>
                        ) : (
                          <span className="whitespace-nowrap tabular-nums">
                            {format(a.share)} · {formatCount(a.present)} of{" "}
                            {formatCount(a.barangays)} barangays
                          </span>
                        )}
                      </div>
                      {a.share !== null && (
                        <div
                          role="img"
                          aria-label={`${a.name}: ${format(a.share)}, ${formatCount(a.present)} of ${formatCount(a.barangays)} barangays with a recorded facility; ${formatCount(a.barangays - a.present)} with no facility${types.length ? " of selected types" : ""} recorded`}
                          className="my-2 flex h-4 overflow-hidden rounded border border-border bg-surface"
                        >
                          <span className="h-full bg-accent" style={{ width: `${a.share}%` }} />
                        </div>
                      )}
                    </li>
                  ))}
              </ol>
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
            </>
          ) : (
            <div role="status" className="my-4 text-sm">
              {pending
                ? "Updating barangay presence…"
                : "The selected barangay presence could not be verified. Missing data is not a reported zero."}
              {!pending && (
                <button
                  type="button"
                  className={`${button} ml-2`}
                  onClick={() => setRetry((v) => v + 1)}
                >
                  Try again
                </button>
              )}
            </div>
          )}
        </>
      ) : (
        <p role="status" className="mt-4 text-sm">
          Barangay presence could not be verified for the complete area roster. Missing data is not
          a reported zero.
        </p>
      )}
      <p className="mt-4 text-sm text-muted">{PRESENCE_READING_LIMIT}</p>
      {!presenting && (
        <details className="mt-4 text-sm text-muted">
          <summary className="min-h-11 cursor-pointer content-center font-medium">
            About this figure
          </summary>
          <div className="mt-2 space-y-2 rounded-md bg-surface p-3">
            {PRESENCE_NOTES.map((note) => (
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
      aria-label="Barangay presence chart"
      data-testid="facility-presence-card"
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
        title={mode === "present" ? "Barangay presence · Present" : "Barangay presence · Maximized"}
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
