"use client";
import { PROFILING_BINS } from "@/lib/maps/profiling";
import dynamic from "next/dynamic";
import Link from "next/link";
import { useEffect, useMemo, useRef, useState } from "react";
import { getDatasetMap } from "@/lib/maps/definitions";
import {
  initialMapChoice,
  mapHref,
  prototypeIndicators,
  type MapChoice,
  type MapPayload,
} from "@/lib/maps/prototype";
import { withBasePath } from "@/lib/base-path";
import { computeQuantileBins } from "@/lib/charts/color-scale";
import { useMapActivity } from "./map-activity";
import { INCOME_BINS, INCOME_CLASSES, incomeClassLabel } from "@/lib/maps/income-class";
import { POVERTY_BINS } from "@/lib/maps/poverty";
import { MapLegend } from "./map-legend";
const DatasetCanvas = dynamic(() => import("./dataset-canvas").then((m) => m.DatasetCanvas), {
  ssr: false,
  loading: () => (
    <p role="status" className="h-64">
      Loading map…
    </p>
  ),
});

type Result = MapPayload | { state: "unavailable" | "failed"; key: string };
export function DatasetMapCard({
  dataset,
  initial,
  expanded = false,
  onChoiceChange,
}: {
  dataset: string;
  initial?: MapChoice;
  expanded?: boolean;
  onChoiceChange?: (choice: MapChoice) => void;
}) {
  const definition = getDatasetMap(dataset)!;
  const metrics = prototypeIndicators(dataset);
  const [choice, setChoice] = useState(() => initial ?? initialMapChoice(dataset));
  useEffect(() => {
    onChoiceChange?.(choice);
  }, [choice, onChoiceChange]);
  const [trail, setTrail] = useState<MapChoice[]>([]);
  const [result, setResult] = useState<{ key: string; data: Result } | null>(null);
  const [geometry, setGeometry] = useState<{ key: string; codes: string[] } | null>(null);
  const [visible, setVisible] = useState(expanded);
  const active = useMapActivity(visible && metrics.length > 0);
  const [retry, setRetry] = useState(0);
  const ref = useRef<HTMLDivElement>(null);
  const query = mapHref({ ...choice, selected: null }).split("?")[1];
  const key = `${query}&retry=${retry}`;
  useEffect(() => {
    if (expanded || !ref.current) return;
    const observer = new IntersectionObserver(([entry]) => setVisible(entry.isIntersecting));
    observer.observe(ref.current);
    return () => observer.disconnect();
  }, [expanded]);
  useEffect(() => {
    if (!active || result?.key === key) return;
    const controller = new AbortController();
    fetch(withBasePath(`/api/dataset-map?${query}`), { signal: controller.signal })
      .then(async (response) => {
        if (!response.ok) throw new Error("Read failed");
        const data = await response.json();
        if (data.state !== "available" && data.state !== "unavailable")
          throw new Error("Read failed");
        if (!controller.signal.aborted) setResult({ key, data });
      })
      .catch(() => {
        if (!controller.signal.aborted) setResult({ key, data: { state: "failed", key } });
      });
    return () => controller.abort();
  }, [active, query, key, result?.key]);
  const data = result?.key === key && result.data.state === "available" ? result.data : null;
  const uucRoute = dataset === "uuc-phc-2025" && choice.indicator.startsWith("uuc.route.");
  const profiling = dataset === "bhw-profiling-status-2026";
  const income = dataset === "lgu-income-class";
  const poverty = dataset === "psa-sae-poverty-2023";
  const navigationOnly = data?.valueSupport === "navigation-only";
  const bins = useMemo(
    () =>
      navigationOnly
        ? []
        : profiling
          ? PROFILING_BINS
          : income
            ? INCOME_BINS
            : poverty
              ? POVERTY_BINS
              : computeQuantileBins(data?.rows.map((r) => r.value) ?? []),
    [data, poverty, income, profiling, navigationOnly],
  );
  const metric = metrics.find((m) => m.id === choice.indicator);
  const selected = data?.rows.find((r) => r.code === choice.selected);
  const shapeCodes = geometry?.key === key ? geometry.codes : null;
  const dashboard =
    dataset === "nhfr-2026-09"
      ? "/facilities"
      : dataset === "uuc-phc-2025"
        ? "/uuc-phc"
        : dataset === "bhw-2025" || dataset === "bhw-stepzero-2026"
          ? "/bhw"
          : profiling
            ? "/profiling-status"
            : null;
  function reset() {
    setTrail([]);
    setChoice(initialMapChoice(dataset));
  }
  return (
    <article
      aria-label={`${definition.title} map`}
      className="min-w-0 space-y-4 rounded-xl border border-border bg-background p-5"
    >
      <h2 className="text-xl font-semibold">{definition.title}</h2>
      {!metrics.length ? (
        <div className="flex min-h-48 items-center rounded-lg bg-surface p-5">
          <p>
            Map not available yet.{" "}
            {definition.slug === "ways-forward"
              ? "This roadmap has no ingested figures."
              : "Dataset-specific values and boundaries are awaiting verification. No values are shaded."}
          </p>
        </div>
      ) : (
        <>
          <label className="block text-sm">
            Indicator
            <select
              aria-label={`${definition.title} indicator`}
              className="mt-1 block w-full rounded border border-border bg-background p-2"
              value={choice.indicator}
              onChange={(e) => {
                const m = metrics.find((i) => i.id === e.target.value)!;
                setChoice({ ...choice, indicator: m.id, period: m.period });
              }}
            >
              {metrics.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.label}
                </option>
              ))}
            </select>
          </label>
          <p className="text-sm">
            {metric?.period} · {metric?.unit} · {data?.area ?? choice.code}
          </p>
          <nav
            aria-label={`${definition.title} map navigation`}
            className="flex gap-4 text-sm underline"
          >
            <button type="button" onClick={reset}>
              Philippines
            </button>
            {trail.map((step, index) => (
              <button
                key={`${step.level}-${step.code}`}
                type="button"
                onClick={() => {
                  setChoice({ ...step, indicator: choice.indicator, period: choice.period });
                  setTrail(trail.slice(0, index));
                }}
              >
                {step.code}
              </button>
            ))}
          </nav>
          <div ref={ref} className="min-h-64">
            {!active ? (
              <p className="min-h-64 bg-surface p-4">
                Map paused. Scroll this card into view or enlarge it to explore. Your selection is
                retained.
              </p>
            ) : data ? (
              <>
                <DatasetCanvas
                  key={key}
                  url={data.geometryUrl}
                  rows={data.rows}
                  bins={bins}
                  selected={choice.selected}
                  onSelect={(code) => setChoice((c) => ({ ...c, selected: code }))}
                  onGeometry={(codes) => setGeometry({ key, codes })}
                  expanded={expanded}
                />
                <p className="text-xs text-muted">
                  {navigationOnly
                    ? income
                      ? "Geography navigation only. Choose a region to see published LGU classes, then a province for its cities and municipalities."
                      : "Geography navigation only. Choose a province to see published city/municipality estimates."
                    : income
                      ? "Published classes, not income amounts or relative quantiles. Gray indicates an unclassified or unmatched area; see the table for the reason."
                      : poverty
                        ? "Fixed percentage breaks across provinces. Gray means missing coverage, including excluded HUCs; it does not mean zero poverty."
                        : profiling
                          ? "Fixed share breaks: 0–<25%, 25–<50%, 50–<75%, 75–<100%, and 100% or more. Missing snapshots and zero denominators have no share."
                          : "Quantiles relative to displayed areas. Gray means no reported value."}{" "}
                  Missing shapes remain in the table. Use Ctrl/⌘ + scroll to zoom; two fingers to
                  pan.
                </p>
                <MapLegend
                  bins={bins}
                  categoryLabels={
                    profiling
                      ? {
                          0: "0–<25%",
                          25: "25–<50%",
                          50: "50–<75%",
                          75: "75–<100%",
                          100: "100% or more",
                        }
                      : income
                        ? Object.fromEntries(INCOME_CLASSES.map((c) => [c.value, c.label]))
                        : undefined
                  }
                  valueSuffix={poverty || profiling ? "%" : ""}
                  noDataLabel={
                    profiling
                      ? "Missing snapshot or unavailable share — see table"
                      : income
                        ? "Unclassified or unmatched — see table"
                        : poverty
                          ? "Missing coverage (not zero)"
                          : undefined
                  }
                  hasNoData={!navigationOnly && data.rows.some((r) => r.value === null)}
                  hasSmallN={false}
                />
              </>
            ) : (
              <p role="status" className="min-h-64 bg-surface p-4">
                {result?.key !== key
                  ? "Loading dataset map…"
                  : result.data.state === "unavailable"
                    ? "This dataset is unavailable. No values are shaded."
                    : "Map data could not be loaded. This does not mean zero."}
              </p>
            )}
          </div>
          {result?.key === key && result.data.state === "failed" && (
            <button type="button" className="underline" onClick={() => setRetry((r) => r + 1)}>
              Retry map
            </button>
          )}
          {data && (
            <>
              <p className="text-sm">
                {profiling ? (
                  "Stages are mutually exclusive current workflow states. Shares use each area’s BHW headcount, rounded to whole percentages, not pipeline totals. No regional average is calculated. Not yet encoded is the headcount minus all pipeline stages, floored at zero."
                ) : income ? (
                  "Income classes are published for LGUs. No total or average class is calculated; barangays have no independently published class. Manila submunicipal entries reflect the city classification."
                ) : poverty ? (
                  "Poverty incidence is published only for cities/municipalities. No provincial, regional or national rate is calculated here."
                ) : (
                  <>
                    Reported area total:{" "}
                    {data.total === null ? "Not reported" : data.total.toLocaleString()}{" "}
                    {metric?.unit}. The total includes areas without drawable shapes.
                  </>
                )}
              </p>
              {uucRoute && (
                <p className="text-sm">
                  Published qualifying-route counts, not new classifications or averages of source
                  percentages. Routes overlap and must not be added together. The health route uses
                  only barangays with evaluable provincial benchmarks. {data.totalNote}
                </p>
              )}
              <label className="block text-sm">
                Select an area
                <select
                  aria-label={`${definition.title} area`}
                  className="mt-1 block w-full rounded border border-border bg-background p-2"
                  value={selected?.code ?? ""}
                  onChange={(e) => setChoice({ ...choice, selected: e.target.value || null })}
                >
                  <option value="">Choose an area</option>
                  {data.rows.map((r) => (
                    <option key={r.code} value={r.code}>
                      {r.name}
                    </option>
                  ))}
                </select>
              </label>
              {selected && (
                <div className="space-y-2 text-sm">
                  <p>
                    {selected.name}:{" "}
                    {navigationOnly
                      ? "Not published at this level"
                      : income
                        ? (incomeClassLabel(selected.value) ??
                          selected.note ??
                          "No matched source record")
                        : (selected.value ?? "Not reported")}{" "}
                    {selected.value !== null && !income ? metric?.unit : ""}
                  </p>
                  {(income || profiling || uucRoute) && <p>{selected.note}</p>}
                  {poverty && !navigationOnly && (
                    <p>
                      Published 90% confidence interval:{" "}
                      {selected.ciLow != null && selected.ciHigh != null
                        ? `${selected.ciLow}%–${selected.ciHigh}%`
                        : "Not available"}
                      .
                    </p>
                  )}
                  {data.childLevel !== "citymun" ? (
                    <button
                      className="underline"
                      type="button"
                      onClick={() => {
                        setTrail([...trail, choice]);
                        setChoice({
                          ...choice,
                          level: data.childLevel as "region" | "province",
                          code: selected.code,
                          selected: null,
                        });
                      }}
                    >
                      Show areas within {selected.name}
                    </button>
                  ) : (
                    <p>
                      {income
                        ? "Barangays have no independently published income class; this classification belongs to the named LGU."
                        : poverty
                          ? "No barangay poverty estimates are published in this source."
                          : "Barangay polygons are not yet validated."}{" "}
                      {dashboard && (
                        <Link
                          className="underline"
                          href={`${dashboard === "/bhw" ? "/place" : dashboard}/citymun/${selected.code}`}
                        >
                          Open municipal dashboard and barangay information
                        </Link>
                      )}
                    </p>
                  )}
                </div>
              )}
              <details>
                <summary className="cursor-pointer text-sm font-medium">
                  Complete area table ({data.rows.length})
                </summary>
                <div
                  className="max-h-80 overflow-auto"
                  tabIndex={0}
                  role="region"
                  aria-label={`${definition.title} map table`}
                >
                  <table className="w-full text-left text-sm">
                    <caption>
                      {metric?.label} · {metric?.period}
                    </caption>
                    <thead>
                      <tr>
                        <th scope="col">Area</th>
                        <th scope="col">{metric?.unit}</th>
                        {poverty && !navigationOnly && (
                          <th scope="col">Published 90% confidence interval</th>
                        )}
                        {(income || profiling || uucRoute) && !navigationOnly && (
                          <th scope="col">Source context</th>
                        )}
                        <th scope="col">Geometry</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.rows.map((r) => (
                        <tr key={r.code}>
                          <th scope="row" className="py-2 font-normal">
                            {r.name}
                          </th>
                          <td>
                            {navigationOnly
                              ? "Not published at this level"
                              : income
                                ? (incomeClassLabel(r.value) ??
                                  r.note ??
                                  "No matched source record")
                                : (r.value ?? "Not reported")}
                          </td>
                          {poverty && !navigationOnly && (
                            <td>
                              {r.ciLow != null && r.ciHigh != null
                                ? `${r.ciLow}%–${r.ciHigh}%`
                                : "Not available"}
                            </td>
                          )}
                          {(income || profiling || uucRoute) && !navigationOnly && (
                            <td>{r.note ?? "—"}</td>
                          )}
                          <td>
                            {shapeCodes
                              ? shapeCodes.includes(r.code)
                                ? "Available"
                                : "Missing"
                              : "Not checked"}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </details>
              <p className="text-xs text-muted">
                Source: {data.source}. Source date: {data.date ?? "Not provided"}. License:{" "}
                {data.license ?? "Not provided"}.
              </p>
            </>
          )}
        </>
      )}
      <p className="text-xs text-muted">{definition.disclosure}</p>
      {metrics.length > 0 && (
        <p className="text-xs text-muted">
          Boundary context: existing self-hosted PSGC shapes, incomplete in some areas. Historical
          boundary compatibility and a detailed basemap remain under review.
        </p>
      )}
      <div className="flex flex-wrap gap-4 text-sm underline">
        <Link prefetch={false} href={mapHref(choice)}>
          {expanded ? "Share this map selection" : `Enlarge ${definition.title} map`}
        </Link>
        {dashboard && <Link href={dashboard}>Open dashboard</Link>}
      </div>
    </article>
  );
}
