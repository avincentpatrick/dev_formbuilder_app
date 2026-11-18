"use client";

import dynamic from "next/dynamic";
import Link from "next/link";
import { useCallback, useEffect, useMemo, useRef, useState, useSyncExternalStore } from "react";
import {
  comparisonMapMeasures,
  parseMapComparison,
  mapComparisonHref,
  comparisonBins,
  comparableMapPayloads,
  validComparisonPayload,
  drillComparison,
  parentComparison,
  type MapComparison,
} from "@/lib/bhw/map-comparison";
import { comparisonHref } from "@/lib/bhw/comparison";
import { mapHref, parseMapChoice, type MapChoice, type MapPayload } from "@/lib/maps/prototype";
import { readMapResponse } from "@/lib/maps/read-map-response";
import { withBasePath } from "@/lib/base-path";
import { percentageFormatter } from "@/lib/format";
import { formatValue } from "@/components/catalog/presentation";
import { MapLegend } from "@/components/maps/map-legend";
import { useMapActivity } from "@/components/maps/map-activity";
import { BOUNDARY_CREDIT } from "@/lib/maps/boundary-credit";
import { bhwLocalityHref } from "@/lib/maps/catalog-return";
import type { ColorBin } from "@/lib/charts/color-scale";

const DatasetCanvas = dynamic(
  () => import("@/components/maps/dataset-canvas").then((m) => m.DatasetCanvas),
  { ssr: false, loading: () => <p role="status">Loading map renderer…</p> },
);
const noCapture = () => {};
const phoneQuery = "(max-width: 767px)";
const subscribeSize = (notify: () => void) => {
  const media = window.matchMedia(phoneQuery);
  media.addEventListener("change", notify);
  return () => media.removeEventListener("change", notify);
};
const phoneSnapshot = () => window.matchMedia(phoneQuery).matches;
const serverPhone = () => true;
type Read =
  { state: "available"; payload: MapPayload } | { state: "failed" | "unavailable" | "unsupported" };

async function read(choice: MapChoice, signal: AbortSignal): Promise<Read> {
  const url = withBasePath(
    `/api/dataset-map?${mapHref({ ...choice, selected: null }).split("?")[1]}`,
  );
  const response = await readMapResponse(url, signal);
  const data = await response.json();
  if (data.state === "unsupported") return { state: "unsupported" };
  if (!response.ok) return { state: "failed" };
  if (data.state === "unavailable") return { state: "unavailable" };
  const unit = comparisonMapMeasures.find(({ metric }) => metric.id === choice.indicator)?.metric
    .unit;
  if (!validComparisonPayload(data, unit)) return { state: "failed" };
  return { state: "available", payload: data as MapPayload };
}

function MapPanel({
  side,
  choice,
  data,
  bins,
  unit,
  display,
  onSelect,
  onRetry,
}: {
  side: "a" | "b";
  choice: MapChoice;
  data: MapPayload;
  bins: ColorBin[];
  unit: string;
  display: (n: number) => string;
  onSelect: (code: string) => void;
  onRetry: () => void;
}) {
  const [geometry, setGeometry] = useState<string[] | null>(null);
  const missing = geometry === null ? null : data.rows.filter((r) => !geometry.includes(r.code));
  const selected = data.rows.find((r) => r.code === choice.selected);
  const locality = selected
    ? { mode: "psgc" as const, level: data.childLevel, code: selected.code }
    : { mode: "psgc" as const, level: choice.level, code: choice.code };
  return (
    <section
      aria-label={`Map ${side.toUpperCase()} comparison`}
      className="min-w-0 space-y-3 rounded border border-border p-3"
    >
      <h2 className="text-xl font-semibold">
        Map {side.toUpperCase()} · {data.area}
      </h2>
      <p className="text-sm">
        {data.childLevel} areas · {unit} · {choice.period}. Geographic scale is shown on this map.
      </p>
      <DatasetCanvas
        url={data.geometryUrl}
        rows={data.rows}
        bins={bins}
        selected={selected?.code ?? null}
        onSelect={onSelect}
        onGeometry={setGeometry}
        onCapture={noCapture}
        onRetry={onRetry}
        expanded
        labels
        unit={unit}
        formatValueLabel={display}
        geographyLabel={data.childLevel}
      />
      <label className="block">
        Selected area in map {side.toUpperCase()}
        <select
          className="mt-1 block w-full rounded border border-border bg-surface p-2"
          value={selected?.code ?? ""}
          onChange={(e) => onSelect(e.target.value)}
        >
          <option value="">Choose an area</option>
          {data.rows.map((row) => (
            <option key={row.code} value={row.code}>
              {row.name} ({row.code})
            </option>
          ))}
        </select>
      </label>
      {choice.selected && !selected && (
        <p role="status">
          The shared selection is not a child of this area. Choose an area; no substitute was
          selected.
        </p>
      )}
      {selected && (
        <p role="status">
          {selected.name}: {selected.value === null ? "Not reported" : display(selected.value)}.{" "}
          {selected.note}
        </p>
      )}
      <Link href={bhwLocalityHref(locality)} className="inline-block underline">
        Open dashboard for {selected?.name ?? data.area}
      </Link>
      <p className="text-sm text-muted">
        {missing === null
          ? "Checking boundary coverage…"
          : `${missing.length} of ${data.rows.length} areas lack a matching shape. They remain in the selector and table; no position is invented.`}
      </p>
      <div
        role="region"
        aria-label={`Exact values for map ${side.toUpperCase()}`}
        tabIndex={0}
        className="max-h-80 overflow-auto rounded border border-border"
      >
        <table className="w-full text-left text-sm">
          <caption className="sr-only">
            {data.area}: exact {unit} values and boundary availability
          </caption>
          <thead>
            <tr>
              <th scope="col" className="p-2">
                Area
              </th>
              <th scope="col" className="p-2">
                Value
              </th>
              <th scope="col" className="p-2">
                Boundary
              </th>
            </tr>
          </thead>
          <tbody>
            {data.rows.map((row) => (
              <tr key={row.code} className="border-t border-border">
                <th scope="row" className="p-2 font-normal">
                  <button
                    className="min-h-11 text-left underline"
                    onClick={() => onSelect(row.code)}
                  >
                    {row.name} ({row.code})
                  </button>
                </th>
                <td className="p-2">
                  {row.value === null ? "Not reported" : display(row.value)}
                  {row.note && <p className="text-xs text-muted">{row.note}</p>}
                </td>
                <td className="p-2">
                  {geometry === null
                    ? "Checking"
                    : geometry.includes(row.code)
                      ? "Available"
                      : "Missing shape"}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <p className="text-xs text-muted">
        Source: {data.source} · source date: {data.date ?? "not supplied"} · data license:{" "}
        {data.license ?? "not supplied"}.
      </p>
    </section>
  );
}

export function MapComparisonWorkspace({ initial }: { initial: MapComparison }) {
  const [choice, setChoice] = useState(initial);
  const latest = useRef(initial);
  const [invalidHistory, setInvalidHistory] = useState(false);
  const change = useCallback((next: MapComparison) => {
    latest.current = next;
    window.history.pushState(null, "", withBasePath(mapComparisonHref(next)));
    setChoice(next);
    setInvalidHistory(false);
  }, []);
  useEffect(() => {
    const restore = () => {
      if (window.location.pathname !== withBasePath("/bhw/compare-maps")) return;
      const next = parseMapComparison(new URLSearchParams(window.location.search));
      setInvalidHistory(!next);
      if (next) {
        latest.current = next;
        setChoice(next);
      }
    };
    window.addEventListener("popstate", restore);
    return () => window.removeEventListener("popstate", restore);
  }, []);
  const small = useSyncExternalStore(subscribeSize, phoneSnapshot, serverPhone);
  // Reserve the existing two-map budget; phones render only their active panel.
  const activeA = useMapActivity(!invalidHistory),
    activeB = useMapActivity(!invalidHistory);
  const [retry, setRetry] = useState(0);
  const requestA = mapHref({ ...choice.a, selected: null }),
    requestB = mapHref({ ...choice.b, selected: null });
  const key = `${requestA}|${requestB}|${retry}`;
  const [result, setResult] = useState<{ key: string; reads: [Read, Read] } | null>(null);
  useEffect(() => {
    if (!activeA || !activeB) return;
    const abort = new AbortController();
    const a = parseMapChoiceQuery(requestA),
      b = parseMapChoiceQuery(requestB);
    void Promise.all([
      read(a, abort.signal).catch(() => ({ state: "failed" as const })),
      read(b, abort.signal).catch(() => ({ state: "failed" as const })),
    ]).then((reads) => {
      if (!abort.signal.aborted) setResult({ key, reads });
    });
    return () => abort.abort();
  }, [requestA, requestB, key, activeA, activeB]);
  const reads = result?.key === key ? result.reads : null;
  const a = reads?.[0].state === "available" ? reads[0].payload : null;
  const b = reads?.[1].state === "available" ? reads[1].payload : null;
  const entry = comparisonMapMeasures.find(({ metric }) => metric.id === choice.a.indicator)!;
  const compatible = Boolean(a && b && comparableMapPayloads(a, b));
  const bins = useMemo(
    () => (a && b && compatible ? comparisonBins(entry.metric.unit, a, b) : []),
    [a, b, compatible, entry.metric.unit],
  );
  const percent = useMemo(
    () =>
      percentageFormatter(
        [...(a?.rows ?? []), ...(b?.rows ?? [])].flatMap((r) =>
          r.value === null ? [] : [r.value],
        ),
      ),
    [a, b],
  );
  const display = (n: number) =>
    entry.metric.unit === "%" ? percent(n) : formatValue({ value: n }, entry.metric.unit);
  const drill = a && b ? drillComparison(choice, a, b) : null;
  const parent = a && b ? parentComparison(choice, a, b) : null;
  const select = (side: "a" | "b", code: string) => {
    const current = latest.current;
    change({ ...current, [side]: { ...current[side], selected: code || null } });
  };
  return (
    <div className="mx-auto w-full max-w-7xl space-y-5 px-4 py-6">
      <Link
        href={comparisonHref(choice.a.indicator, choice.geos, choice.returnFocus, choice.mapState)}
        className="underline"
      >
        ← Return to place comparison
      </Link>
      <h1 className="text-2xl font-semibold">Compare BHW maps</h1>
      <p>
        Both maps use one measure, accepted release and period. Headcount and profiles describe
        different populations; this is a geographic comparison, not change over time.
      </p>
      <label className="block">
        Shared map measure
        <select
          className="mt-1 block w-full rounded border border-border bg-surface p-2"
          value={choice.a.indicator}
          onChange={(e) => {
            const next = comparisonMapMeasures.find(({ metric }) => metric.id === e.target.value)!;
            const update = (old: MapChoice) => ({
              ...old,
              dataset: next.dataset,
              indicator: next.metric.id,
              period: next.metric.period,
              selected: null,
            });
            change({ ...choice, a: update(choice.a), b: update(choice.b) });
          }}
        >
          {comparisonMapMeasures.map(({ metric }) => (
            <option key={metric.id} value={metric.id}>
              {metric.label}
            </option>
          ))}
        </select>
      </label>
      <p className="text-sm">
        {entry.metric.label} ·{" "}
        {choice.a.dataset === "bhw-stepzero-2026" ? "BHW Headcount 2025" : "BHW Profiles 2025"} ·{" "}
        {entry.metric.unit}.{" "}
        {entry.metric.denominator && `Denominator: ${entry.metric.denominator}.`}
      </p>
      <p className="text-sm text-muted">
        Pan and zoom each geographic area independently using its own scale bar. Select a child on
        each side, then drill both together. Previous workspace filters and color groups are not
        applied here; they remain in the return link.
      </p>
      <div className="flex flex-wrap gap-3">
        <button
          className="min-h-11 rounded border border-border p-2 disabled:opacity-50"
          disabled={!drill || !compatible}
          onClick={() => drill && change(drill)}
        >
          Drill both selected areas
        </button>
        <button
          className="min-h-11 rounded border border-border p-2 disabled:opacity-50"
          disabled={!parent || !compatible}
          onClick={() => parent && change(parent)}
        >
          Parent areas on both maps
        </button>
        <button
          className="min-h-11 rounded border border-border p-2"
          onClick={() => change(initial)}
        >
          Reset original comparison
        </button>
      </div>
      {compatible && !drill && (
        <p className="text-sm text-muted">
          Drilling needs a selected child on each map and supported detail for both areas. At the
          last supported level, use each area’s dashboard link for its available figures.
        </p>
      )}
      <nav aria-label="Phone map comparison" className="flex gap-3 md:hidden">
        {(["a", "b"] as const).map((side) => (
          <button
            key={side}
            aria-pressed={choice.focus === side}
            className="min-h-11 rounded border border-border p-3"
            onClick={() => change({ ...choice, focus: side })}
          >
            Map {side.toUpperCase()}
          </button>
        ))}
      </nav>
      {invalidHistory ? (
        <p role="alert">
          Invalid shared comparison settings. No fallback or previous figures are shown.
        </p>
      ) : !reads ? (
        <p role="status">Loading both map areas…</p>
      ) : !a || !b ? (
        <div role="status" className="space-y-3">
          <p>
            Both maps must load before comparing. Map A: {reads[0].state}. Map B: {reads[1].state}.
            Failed, unavailable or unsupported data does not mean zero.
          </p>
          <button
            className="min-h-11 rounded border border-border p-2"
            onClick={() => setRetry((n) => n + 1)}
          >
            Retry both maps
          </button>
        </div>
      ) : !compatible ? (
        <div role="alert">
          <p>
            These responses do not have matching geographic units and source dates, or the measure
            is unsupported for the child areas. No like-for-like map comparison is shown.
          </p>
          <button className="min-h-11 underline" onClick={() => setRetry((n) => n + 1)}>
            Retry both maps
          </button>
        </div>
      ) : (
        <>
          <section
            aria-label="Shared comparison legend"
            className="space-y-2 rounded border border-border p-3"
          >
            <h2 className="font-semibold">Common color breaks for both maps</h2>
            <p className="text-sm">
              {entry.metric.unit === "%"
                ? "Fixed 0–100% groups, shared by both maps. Percentages are not summed or averaged."
                : "Quantile breaks use all reported child-area values from both maps together; ties may reduce the number of groups. Parent totals and means are not recomputed."}{" "}
              Missing shapes do not remove values from the scale or tables.
            </p>
            <MapLegend
              bins={bins}
              valueSuffix={entry.metric.unit === "%" ? "%" : ` ${entry.metric.unit}`}
              hasNoData
              hasSmallN={[...a.rows, ...b.rows].some((r) => r.smallSample)}
              noDataLabel="Not reported or missing shape — see each table"
            />
          </section>
          <div className="grid gap-4 md:grid-cols-2">
            {(["a", "b"] as const)
              .filter((side) => !small || choice.focus === side)
              .map((side) => (
                <MapPanel
                  key={side + mapHref({ ...choice[side], selected: null })}
                  side={side}
                  choice={choice[side]}
                  data={side === "a" ? a : b}
                  bins={bins}
                  unit={entry.metric.unit}
                  display={display}
                  onSelect={(code) => select(side, code)}
                  onRetry={() => setRetry((n) => n + 1)}
                />
              ))}
          </div>
        </>
      )}
      <Link href={mapComparisonHref(choice)} className="inline-block underline">
        Share this paired-map comparison
      </Link>
      <p className="text-xs text-muted">
        Boundaries: {BOUNDARY_CREDIT.attribution} · {BOUNDARY_CREDIT.license}. Boundary terms do not
        replace each data source’s license. Missing data licensing remains explicit above.
      </p>
    </div>
  );
}

function parseMapChoiceQuery(href: string): MapChoice {
  // Constructed only from settings already checked by parseMapComparison.
  return parseMapChoice(new URLSearchParams(href.split("?")[1]))!;
}
