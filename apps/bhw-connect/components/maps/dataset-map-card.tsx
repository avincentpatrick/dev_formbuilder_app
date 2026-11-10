"use client";
import { CENSUS_ACTIVE_YEARS_ID } from "@/lib/maps/census-active-years";
import { isCensusProfileRate } from "@/lib/maps/census-accreditation";
import { STEPZERO_ACCREDITATION_ID, STEPZERO_ACCREDITATION_BINS, STEPZERO_ACCREDITATION_DENOMINATOR } from "@/lib/maps/stepzero-accreditation";
import { searchMapAreas } from "@/lib/maps/area-search";
import { composeMapPng } from "@/lib/maps/png";
import { datasetMapExport } from "@/lib/maps/export";
import { PROFILING_BINS } from "@/lib/maps/profiling";
import dynamic from "next/dynamic";
import Link from "next/link";
import { useEffect, useId, useMemo, useRef, useState } from "react";
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
import { loadMaplibre } from "./load-maplibre";
import { INCOME_BINS, INCOME_CLASSES, incomeClassLabel } from "@/lib/maps/income-class";
import { POVERTY_BINS } from "@/lib/maps/poverty";
import { MapLegend } from "./map-legend";
import { clampBarangayScope } from "@/lib/maps/barangay-support";
const loadDatasetCanvas = () => import("./dataset-canvas").then((m) => m.DatasetCanvas);
const DatasetCanvas = dynamic(loadDatasetCanvas, {
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
  const [search, setSearch] = useState({ scope: "", text: "" });
  const searchStatusId = useId();
  const searchScope = `${dataset}:${choice.level}:${choice.code}`;
  const searchText = search.scope === searchScope ? search.text : "";
  const [result, setResult] = useState<{ key: string; data: Result } | null>(null);
  const [geometry, setGeometry] = useState<{ key: string; codes: string[] } | null>(null);
  const [visible, setVisible] = useState(expanded);
  const active = useMapActivity(visible && metrics.length > 0);
  const [notice, setNotice] = useState<string | null>(null);
  const [retry, setRetry] = useState(0);
  const [renderAttempt, setRenderAttempt] = useState(0);
  const [capture, setCapture] = useState<{ key: string; run: () => Promise<HTMLCanvasElement> } | null>(null);
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);
  const ref = useRef<HTMLDivElement>(null);
  const query = mapHref({ ...choice, selected: null }).split("?")[1];
  const key = `${query}&retry=${retry}`;
  const exportVersion = `${key}:${choice.selected ?? ""}`;
  const currentExportVersion = useRef(exportVersion);
  useEffect(() => { currentExportVersion.current = exportVersion; }, [exportVersion]);
  useEffect(() => {
    if (expanded || !ref.current) return;
    const observer = new IntersectionObserver(([entry]) => setVisible(entry.isIntersecting));
    observer.observe(ref.current);
    return () => observer.disconnect();
  }, [expanded]);
  useEffect(() => {
    if (!active || result?.key === key) return;
    // A visible card with a lease may load renderer code alongside its aggregate request.
    // This creates no map/worker and reads no other dataset. The canvas still waits for data.
    void Promise.all([loadDatasetCanvas(), loadMaplibre()]).catch(() => {
      // The normal canvas load/error path remains responsible for user-facing failures.
    });
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
  const declaredAccreditation = dataset === "bhw-stepzero-2026" && choice.indicator === STEPZERO_ACCREDITATION_ID;
  const censusMean = dataset === "bhw-2025" && choice.indicator === CENSUS_ACTIVE_YEARS_ID;
  const censusRate = dataset === "bhw-2025" && isCensusProfileRate(choice.indicator);
  const accreditation = declaredAccreditation || censusRate;
  const profiling = dataset === "bhw-profiling-status-2026";
  const income = dataset === "lgu-income-class";
  const poverty = dataset === "psa-sae-poverty-2023";
  const navigationOnly = data?.valueSupport === "navigation-only";
  const bins = useMemo(
    () =>
      navigationOnly
        ? []
        : accreditation ? STEPZERO_ACCREDITATION_BINS
        : profiling
          ? PROFILING_BINS
          : income
            ? INCOME_BINS
            : poverty
              ? POVERTY_BINS
              : computeQuantileBins(data?.rows.map((r) => r.value) ?? []),
    [data, poverty, income, profiling, navigationOnly, accreditation],
  );
  const metric = metrics.find((m) => m.id === choice.indicator);
  const censusUncertainty = censusRate && !!metric?.catalogSpec?.attributes?.ciLow;
  const selected = data?.rows.find((r) => r.code === choice.selected);
  const shapeCodes = geometry?.key === key ? geometry.codes : null;
  const matchingAreas = useMemo(() => searchMapAreas(data?.rows ?? [], searchText), [data, searchText]);
  const selectedOutsideSearch = selected && !matchingAreas.some((row) => row.code === selected.code);
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
  async function downloadPng() {
    if (!data || !active || capture?.key !== key) return;
    setExporting(true); setExportError(null);
    try {
      const metadata = datasetMapExport(choice, data, bins, shapeCodes);
      const frame = await capture.run();
      if (currentExportVersion.current !== exportVersion) throw new Error("Selection changed");
      const blob = await composeMapPng(frame, metadata);
      if (currentExportVersion.current !== exportVersion) throw new Error("Selection changed");
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = `${choice.dataset}-${choice.indicator}-${choice.code}.png`;
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch {
      setExportError("Map image could not be captured. Try again or download the JSON data.");
    } finally { setExporting(false); }
  }
  function downloadData() {
    if (!data) return;
    const content = JSON.stringify(datasetMapExport(choice, data, bins, shapeCodes), null, 2);
    const url = URL.createObjectURL(new Blob([content], { type: "application/json" }));
    const link = document.createElement("a");
    link.href = url;
    link.download = `${choice.dataset}-${choice.indicator}-${choice.code}.json`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }
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
                // A barangay view only exists for indicators with a barangay reader.
                const next = clampBarangayScope({ ...choice, indicator: m.id, period: m.period });
                if (next.level !== choice.level) setTrail(trail.slice(0, -1));
                setNotice(next.level !== choice.level ? "This indicator has no barangay map, so it is shown for the province." : null);
                setChoice(next);
              }}
            >
              {metrics.map((m) => (
                <option key={m.id} value={m.id}>
                  {m.label}
                </option>
              ))}
            </select>
          </label>
          {notice && <p role="status" className="text-sm text-muted">{notice}</p>}
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
                  key={`${key}:render=${renderAttempt}`}
                  url={data.geometryUrl}
                  rows={data.rows}
                  bins={bins}
                  selected={choice.selected}
                  onSelect={(code) => setChoice((c) => ({ ...c, selected: code }))}
                  onGeometry={(codes) => setGeometry({ key, codes })}
                  onCapture={(run) => setCapture(run ? { key, run } : null)}
                  onRetry={() => {
                    setGeometry(null);
                    setCapture(null);
                    setExportError(null);
                    setRenderAttempt((attempt) => attempt + 1);
                  }}
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
                        : accreditation
                          ? "Fixed percentage breaks across areas. Gray means the published share or its denominator is unavailable, not zero percent."
                        : profiling
                          ? "Fixed share breaks: 0–<25%, 25–<50%, 50–<75%, 75–<100%, and 100% or more. Missing snapshots and zero denominators have no share."
                          : "Quantiles relative to displayed areas. Gray means no reported value."}{" "}
                  Missing shapes remain in the table. Use Ctrl/⌘ + scroll to zoom; two fingers to
                  pan.
                </p>
                <MapLegend
                  bins={bins}
                  categoryLabels={
                    profiling || accreditation
                      ? {
                          0: "0–<25%",
                          25: "25–<50%",
                          50: "50–<75%",
                          75: "75–<100%",
                          100: accreditation ? "100%" : "100% or more",
                        }
                      : income
                        ? Object.fromEntries(INCOME_CLASSES.map((c) => [c.value, c.label]))
                        : undefined
                  }
                  valueSuffix={poverty || profiling || accreditation ? "%" : censusMean ? " years" : ""}
                  noDataLabel={
                    accreditation ? "Unavailable published share — see table"
                    : profiling
                      ? "Missing snapshot or unavailable share — see table"
                      : income
                        ? "Unclassified or unmatched — see table"
                        : poverty
                          ? "Missing coverage (not zero)"
                          : undefined
                  }
                  hasNoData={!navigationOnly && data.rows.some((r) => r.value === null)}
                  hasSmallN={data.rows.some(row => row.smallSample === true)}
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
                {censusMean ? (
                  <>Reported area mean: {data.parentMean == null ? "Not reported" : `${data.parentMean} years`}.
                    Denominator: {metric?.denominator}. Missing active-year counts are excluded.
                    Parent means are reported separately, never averaged from children. Contributing sample size and confidence intervals are unavailable.</>
                ) : accreditation ? (
                  <>Reported area rate: {data.parentRate == null ? "Not reported" : `${data.parentRate}%`}.
                    Denominator: {metric?.denominator ?? STEPZERO_ACCREDITATION_DENOMINATOR}. Published percentages are not averaged across areas.
                    {censusRate ? (censusUncertainty ? "BHW Profiles percentage; published 95% Wilson intervals and small-sample notes appear below." : "Share receiving any honorarium; no confidence interval is supplied for this measure. This does not describe amounts or receipt from a particular payer.") : "This is the LGU declaration, not accreditation among validated Census profiles."}</>
                ) : profiling ? (
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
                    {data.totalNote ? ` ${data.totalNote}` : ""}
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
                Search areas by name or PSGC code
                <input type="search" maxLength={100}
                  aria-label={`${definition.title} search areas`}
                  aria-describedby={searchStatusId}
                  className="mt-1 block w-full rounded border border-border bg-background p-2"
                  value={searchText}
                  onChange={(e) => setSearch({ scope: searchScope, text: e.target.value })}
                />
              </label>
              {searchText && (
                <button type="button" className="text-sm underline"
                  onClick={() => setSearch({ scope: searchScope, text: "" })}>
                  Clear area search
                </button>
              )}
              <p id={searchStatusId} role="status" className="text-sm">
                {matchingAreas.length} matching areas of {data.rows.length}.
                {selectedOutsideSearch && " The selected area is retained outside these search results."}
              </p>
              <p className="text-sm text-muted">
                Search narrows the area selector only. The map, totals, complete table and downloads stay unchanged.
              </p>
              <label className="block text-sm">
                Select an area
                <select
                  aria-label={`${definition.title} area`}
                  className="mt-1 block w-full rounded border border-border bg-background p-2"
                  value={selected?.code ?? ""}
                  onChange={(e) => setChoice({ ...choice, selected: e.target.value || null })}
                >
                  <option value="">Choose an area</option>
                  {selectedOutsideSearch && (
                    <option value={selected.code}>{selected.name} (selected; outside search results)</option>
                  )}
                  {matchingAreas.map((r) => (
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
                  {(income || profiling || uucRoute || accreditation || censusMean) && <p>{selected.note}</p>}
                  {(poverty || censusUncertainty) && !navigationOnly && (
                    <p>
                      Published {censusRate ? 95 : 90}% confidence interval:{" "}
                      {selected.ciLow != null && selected.ciHigh != null
                        ? `${selected.ciLow}%–${selected.ciHigh}%`
                        : "Not available"}
                      .
                    </p>
                  )}
                  {data.childLevel === "region" || data.childLevel === "province" || (data.childLevel === "citymun" && data.barangayDrill) ? (
                    <button
                      className="underline"
                      type="button"
                      onClick={() => {
                        setTrail([...trail, choice]);
                        setChoice({
                          ...choice,
                          level: data.childLevel as "region" | "province" | "citymun",
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
                          : data.childLevel === "barangay"
                            ? "Barangays are the smallest area on this map."
                            : "Barangay polygons are not yet validated."}{" "}
                      {dashboard && (
                        <Link
                          className="underline"
                          href={`${dashboard === "/bhw" ? "/place" : dashboard}/citymun/${data.childLevel === "barangay" ? choice.code : selected.code}`}
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
                        {(poverty || censusUncertainty) && !navigationOnly && (
                          <th scope="col">Published {censusRate ? 95 : 90}% confidence interval</th>
                        )}
                        {(income || profiling || uucRoute || accreditation || censusMean) && !navigationOnly && (
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
                          {(poverty || censusUncertainty) && !navigationOnly && (
                            <td>
                              {r.ciLow != null && r.ciHigh != null
                                ? `${r.ciLow}%–${r.ciHigh}%`
                                : "Not available"}
                            </td>
                          )}
                          {(income || profiling || uucRoute || accreditation || censusMean) && !navigationOnly && (
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
      {exportError && <p role="status">{exportError}</p>}
      <div className="flex flex-wrap gap-4 text-sm underline">
        <Link prefetch={false} href={mapHref(choice)}>
          {expanded ? "Share this map selection" : `Enlarge ${definition.title} map`}
        </Link>
        {dashboard && <Link href={dashboard}>Open dashboard</Link>}
        {expanded && data && (
          <button type="button" onClick={downloadPng}
            disabled={!active || capture?.key !== key || exporting}
            className="disabled:opacity-50">
            {exporting ? "Preparing map image…" : "Download map image (PNG)"}
          </button>
        )}
        {data && (
          <button type="button" onClick={downloadData}>
            Download map data (JSON)
          </button>
        )}
      </div>
    </article>
  );
}
