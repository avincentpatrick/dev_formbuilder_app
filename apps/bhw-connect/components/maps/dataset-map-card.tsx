"use client";
import { CENSUS_ACTIVE_YEARS_ID } from "@/lib/maps/census-active-years";
import { isCensusProfileRate } from "@/lib/maps/census-accreditation";
import {
  STEPZERO_ACCREDITATION_ID,
  STEPZERO_ACCREDITATION_BINS,
  STEPZERO_ACCREDITATION_DENOMINATOR,
} from "@/lib/maps/stepzero-accreditation";
import { searchMapAreas } from "@/lib/maps/area-search";
import { composeMapPng, type MapFrame } from "@/lib/maps/png";
import { datasetMapExport } from "@/lib/maps/export";
import { PROFILING_BINS } from "@/lib/maps/profiling";
import dynamic from "next/dynamic";
import Link from "next/link";
import {
  useCallback,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type SetStateAction,
} from "react";
import { getDatasetMap } from "@/lib/maps/definitions";
import {
  initialMapChoice,
  parseMapChoice,
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
import type { BoundaryCollection, LocatorMap } from "@/lib/geo/locator-shapes";
import {
  hasValueFilter,
  valueFilterState,
  valueFilterSummary,
  valueRangeKind,
} from "@/lib/maps/value-filter";
import {
  countClassificationBins,
  percentageClassificationBins,
  classificationDescription,
} from "@/lib/maps/classification";
import { bhwMapComparisonHref } from "@/lib/maps/comparison-entry";
import { bhwOverviewReturnHref } from "@/lib/maps/catalog-return";
import { ClassificationControls } from "./classification-controls";
import { ValueFilterControls } from "./value-filter-controls";
import { Breadcrumbs } from "@/components/layout/breadcrumbs";
import { RankedMapBars } from "./ranked-map-bars";
import { NationalMapLocator } from "./national-map-locator";
import { SizeLegend } from "./size-legend";
import { mapView, supportsCircles } from "@/lib/maps/symbols";
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
  // Embedded previews do not own the page URL. Only the BHW map workspace
  // records analysis edits; Next integrates native history without a server reload.
  const historyEnabled =
    Boolean(onChoiceChange) && (dataset === "bhw-stepzero-2026" || dataset === "bhw-2025");
  const [choice, updateChoice] = useState(() => initial ?? initialMapChoice(dataset));
  const latestChoice = useRef(choice);
  const setChoice = useCallback(
    (action: SetStateAction<MapChoice>) => {
      const previous = latestChoice.current;
      const next = typeof action === "function" ? action(previous) : action;
      if (mapHref(previous) === mapHref(next)) return;
      latestChoice.current = next;
      if (historyEnabled) window.history.pushState(null, "", withBasePath(mapHref(next)));
      updateChoice(next);
    },
    [historyEnabled],
  );
  useEffect(() => {
    onChoiceChange?.(choice);
  }, [choice, onChoiceChange]);
  const [trail, setTrail] = useState<MapChoice[]>([]);
  useEffect(() => {
    if (!historyEnabled) return;
    const restore = () => {
      const next = parseMapChoice(new URLSearchParams(window.location.search));
      // Cross-route/dataset entries belong to Next's router, not this card.
      if (window.location.pathname !== withBasePath("/map") || next?.dataset !== dataset) return;
      latestChoice.current = next;
      updateChoice(next);
      setTrail([]);
    };
    window.addEventListener("popstate", restore);
    return () => window.removeEventListener("popstate", restore);
  }, [dataset, historyEnabled]);
  const [search, setSearch] = useState({ scope: "", text: "" });
  const searchStatusId = useId();
  const searchScope = `${dataset}:${choice.level}:${choice.code}`;
  const searchText = search.scope === searchScope ? search.text : "";
  const [result, setResult] = useState<{ key: string; data: Result } | null>(null);
  const [geometry, setGeometry] = useState<{
    key: string;
    codes: string[];
    collection?: BoundaryCollection;
  } | null>(null);
  const [visible, setVisible] = useState(expanded);
  const active = useMapActivity(visible && metrics.length > 0);
  const [notice, setNotice] = useState<string | null>(null);
  const [retry, setRetry] = useState(0);
  const [renderAttempt, setRenderAttempt] = useState(0);
  const [capture, setCapture] = useState<{
    key: string;
    run: () => Promise<HTMLCanvasElement>;
  } | null>(null);
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);
  const ref = useRef<HTMLDivElement>(null);
  const locatorExport = useRef<{ key: string; locator: LocatorMap | null } | null>(null);
  const query = mapHref({
    ...choice,
    selected: null,
    valueMin: undefined,
    valueMax: undefined,
    classification: undefined,
    classCount: undefined,
    classBreaks: undefined,
  }).split("?")[1];
  const key = `${query}&retry=${retry}`;
  const classificationKey = `${choice.classification}:${choice.classCount}:${choice.classBreaks?.join(",")}`;
  const exportVersion = `${key}:${choice.selected ?? ""}:${choice.valueMin ?? ""}:${choice.valueMax ?? ""}:${classificationKey}`;
  const currentExportVersion = useRef(exportVersion);
  useEffect(() => {
    currentExportVersion.current = exportVersion;
  }, [exportVersion]);
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
  const declaredAccreditation =
    dataset === "bhw-stepzero-2026" && choice.indicator === STEPZERO_ACCREDITATION_ID;
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
        : accreditation
          ? choice.classification
            ? percentageClassificationBins(
                {
                  classification: choice.classification,
                  classCount: choice.classCount,
                  classBreaks: choice.classBreaks,
                },
                data?.rows.map((r) => r.value) ?? [],
              )
            : STEPZERO_ACCREDITATION_BINS
          : profiling
            ? PROFILING_BINS
            : income
              ? INCOME_BINS
              : poverty
                ? POVERTY_BINS
                : supportsCircles({ dataset, indicator: choice.indicator }) || censusMean
                  ? countClassificationBins(
                      {
                        classification: choice.classification,
                        classCount: choice.classCount,
                        classBreaks: choice.classBreaks,
                      },
                      data?.rows.map((r) => r.value) ?? [],
                    )
                  : computeQuantileBins(data?.rows.map((r) => r.value) ?? []),
    [
      data,
      poverty,
      income,
      profiling,
      navigationOnly,
      accreditation,
      censusMean,
      dataset,
      choice.indicator,
      choice.classification,
      choice.classCount,
      choice.classBreaks,
    ],
  );
  const metric = metrics.find((m) => m.id === choice.indicator);
  const view = mapView(choice);
  const rangeKind = valueRangeKind(choice);
  const rangeLabel =
    rangeKind === "percentage"
      ? "Percentage"
      : rangeKind === "years"
        ? "Mean active years"
        : "Count";
  const filtering = hasValueFilter(choice);
  const filterSummary = data && filtering ? valueFilterSummary(data.rows, choice) : null;
  const excludedCodes =
    data && filtering
      ? data.rows
          .filter((row) => valueFilterState(row, choice) === "Excluded by range")
          .map((row) => row.code)
      : [];
  const bhw = dataset === "bhw-stepzero-2026" || dataset === "bhw-2025";
  const workspace = bhw && expanded;
  const [panel, setPanel] = useState<"map" | "areas">("map");
  const panelId = useId();
  const censusUncertainty = censusRate && !!metric?.catalogSpec?.attributes?.ciLow;
  const selected = data?.rows.find((r) => r.code === choice.selected);
  const comparisonHref = bhwMapComparisonHref(choice, data);
  const locatorKey = `${key}:${selected?.code ?? ""}`;
  const trackLocator = useCallback(
    (locator: LocatorMap | null) => {
      locatorExport.current = { key: locatorKey, locator };
    },
    [locatorKey],
  );
  const shapeCodes = geometry?.key === key ? geometry.codes : null;
  const matchingAreas = useMemo(
    () => searchMapAreas(data?.rows ?? [], searchText),
    [data, searchText],
  );
  const selectedOutsideSearch =
    selected && !matchingAreas.some((row) => row.code === selected.code);
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
  const legend = data ? (
    view === "circles" ? (
      <SizeLegend rows={data.rows} compact={workspace} />
    ) : (
      <div className="space-y-1">
        {rangeKind && (
          <p className="text-xs font-medium">
            {choice.classification === "custom"
              ? "Custom cut points"
              : choice.classification === "equal"
                ? "Equal-width groups"
                : rangeKind === "percentage" && !choice.classification
                  ? "Fixed percentage breaks"
                  : "Quantiles"}{" "}
            · {bins.length} groups · all child areas
          </p>
        )}
        <MapLegend
          bins={bins}
          categoryLabels={
            profiling || (accreditation && !choice.classification)
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
            accreditation
              ? "Unavailable published share — see table"
              : profiling
                ? "Missing snapshot or unavailable share — see table"
                : income
                  ? "Unclassified or unmatched — see table"
                  : poverty
                    ? "Missing coverage (not zero)"
                    : undefined
          }
          hasNoData={!navigationOnly && data.rows.some((r) => r.value === null)}
          hasSmallN={data.rows.some((row) => row.smallSample === true)}
        />
      </div>
    )
  ) : null;
  async function downloadPng() {
    if (!data || !active || capture?.key !== key) return;
    setExporting(true);
    setExportError(null);
    try {
      const metadata = datasetMapExport(choice, data, bins, shapeCodes);
      const locator =
        locatorExport.current?.key === locatorKey ? locatorExport.current.locator : null;
      const frame: MapFrame = await capture.run();
      if (locator)
        frame.locator = {
          map: locator,
          name: selected?.name ?? data.area,
          selected: Boolean(selected),
        };
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
    } finally {
      setExporting(false);
    }
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
      className={`min-w-0 rounded-xl border border-border bg-background ${workspace ? "space-y-2 p-3 lg:p-4" : "space-y-4 p-5"}`}
    >
      {!workspace && <h2 className="text-xl font-semibold">{definition.title}</h2>}
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
          <div className={workspace ? "grid grid-cols-2 gap-3" : "space-y-4"}>
            <label className="block min-w-0 text-sm">
              Indicator
              <select
                aria-label={`${definition.title} indicator`}
                className="mt-1 block w-full min-w-0 rounded border border-border bg-background p-2"
                value={choice.indicator}
                onChange={(e) => {
                  const m = metrics.find((i) => i.id === e.target.value)!;
                  // A barangay view only exists for indicators with a barangay reader.
                  const next = clampBarangayScope({
                    ...choice,
                    valueMin: undefined,
                    valueMax: undefined,
                    classification: undefined,
                    classCount: undefined,
                    classBreaks: undefined,
                    indicator: m.id,
                    period: m.period,
                  });
                  if (next.view === "circles" && !supportsCircles(next)) next.view = "choropleth";
                  if (next.level !== choice.level) setTrail(trail.slice(0, -1));
                  setNotice(
                    next.level !== choice.level
                      ? "This indicator has no barangay map, so it is shown for the province."
                      : choice.classification
                        ? `Color classification and ${rangeLabel.toLowerCase()} range reset for the new indicator.`
                        : filtering
                          ? `${rangeLabel} range reset for the new indicator.`
                          : null,
                  );
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
            {bhw && (
              <label className="block text-sm">
                Map view
                <select
                  aria-label="BHW map view"
                  value={view}
                  className="mt-1 block w-full min-w-0 rounded border border-border bg-background p-2"
                  onChange={(e) =>
                    setChoice({ ...choice, view: e.target.value as "circles" | "choropleth" })
                  }
                >
                  {supportsCircles(choice) && (
                    <option value="circles">Proportional circles — count by area</option>
                  )}
                  <option value="choropleth">Choropleth — color by value</option>
                </select>
                {!supportsCircles(choice) && (
                  <span className="text-muted">
                    This measure uses color; proportional count circles are not supported.
                  </span>
                )}
              </label>
            )}
          </div>
          {notice && (
            <p role="status" className="text-sm text-muted">
              {notice}
            </p>
          )}
          <div
            className={
              workspace
                ? "flex flex-wrap items-center justify-between gap-x-3 gap-y-1"
                : "space-y-4"
            }
          >
            <p className="text-sm">
              {metric?.period} · {metric?.unit} · {data?.area ?? choice.code}
            </p>
            {workspace && choice.level !== "national" && data ? (
              <div className="min-w-0 space-y-1">
                <Breadcrumbs
                  label="Map geography"
                  items={[
                    {
                      label: "Philippines",
                      href: mapHref({ ...choice, level: "national", code: "PH", selected: null }),
                    },
                    ...(data.navigation?.parents ?? []).map((parent) => ({
                      label: parent.name,
                      href: mapHref({
                        ...choice,
                        level: parent.level,
                        code: parent.code,
                        selected: null,
                      }),
                    })),
                    { label: data.area },
                  ]}
                />
                {data.navigation?.complete === false && (
                  <p role="status" className="text-xs text-muted">
                    Some parent place names are unavailable. No parent links are inferred.
                  </p>
                )}
              </div>
            ) : (
              <nav
                aria-label={`${definition.title} map navigation`}
                className="flex gap-4 text-sm underline"
              >
                <button
                  type="button"
                  onClick={reset}
                  aria-label={
                    workspace && choice.level === "national"
                      ? "Reset map selection to Philippines"
                      : undefined
                  }
                >
                  {workspace && choice.level === "national" ? "Reset" : "Philippines"}
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
            )}
          </div>
          {workspace && (
            <div role="group" aria-label="Map workspace panels" className="flex gap-2 lg:hidden">
              <button
                type="button"
                aria-pressed={panel === "map"}
                aria-controls={`${panelId}-map`}
                className="min-h-11 flex-1 rounded border border-border px-3 text-sm aria-pressed:bg-surface"
                onClick={() => setPanel("map")}
              >
                Map and legend
              </button>
              <button
                type="button"
                aria-pressed={panel === "areas"}
                aria-controls={`${panelId}-areas`}
                className="min-h-11 flex-1 rounded border border-border px-3 text-sm aria-pressed:bg-surface"
                onClick={() => setPanel("areas")}
              >
                Areas and data
              </button>
            </div>
          )}
          <div
            className={
              workspace
                ? "grid min-w-0 gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]"
                : "space-y-4"
            }
          >
            <div
              ref={ref}
              id={`${panelId}-map`}
              role={workspace ? "region" : undefined}
              aria-label={workspace ? "Map and legend" : undefined}
              className={
                workspace
                  ? `${panel === "map" ? "block" : "hidden"} min-w-0 space-y-2 lg:block`
                  : "min-h-64"
              }
            >
              {!active ? (
                <p className="min-h-64 bg-surface p-4">
                  Map paused. Scroll this card into view or enlarge it to explore. Your selection is
                  retained.
                </p>
              ) : data ? (
                <>
                  {workspace && legend}
                  <DatasetCanvas
                    key={`${key}:render=${renderAttempt}:range=${choice.valueMin}:${choice.valueMax}:classification=${classificationKey}`}
                    url={data.geometryUrl}
                    rows={data.rows}
                    bins={bins}
                    selected={choice.selected}
                    onSelect={(code) => setChoice((c) => ({ ...c, selected: code }))}
                    onGeometry={(codes, collection) => setGeometry({ key, codes, collection })}
                    onCapture={(run) => setCapture(run ? { key, run } : null)}
                    onRetry={() => {
                      setGeometry(null);
                      setCapture(null);
                      setExportError(null);
                      setRenderAttempt((attempt) => attempt + 1);
                    }}
                    expanded={expanded}
                    compact={workspace}
                    labels={bhw}
                    excludedCodes={excludedCodes}
                    view={view}
                  />
                  {rangeKind && view === "choropleth" && (
                    <p className="text-xs text-muted" aria-label="Effective classification">
                      {classificationDescription(choice, bins, rangeKind ?? "count")}
                    </p>
                  )}
                  {filterSummary && (
                    <p role="status" className="rounded border border-border p-2 text-sm">
                      {rangeLabel} range{rangeKind === "percentage" ? " (%)" : ""}:{" "}
                      {choice.valueMin ?? "no minimum"} to {choice.valueMax ?? "no maximum"},
                      inclusive. {filterSummary.included} included, {filterSummary.excluded}{" "}
                      excluded, {filterSummary.unavailable} unavailable. Published{" "}
                      {rangeKind === "percentage"
                        ? "parent rate"
                        : rangeKind === "years"
                          ? "parent mean"
                          : "total"}{" "}
                      and complete table unchanged. White shapes with dashed outlines are excluded.{" "}
                      {rangeKind !== "count"
                        ? "Value filtering does not change the color breaks."
                        : "Scales and classification use all areas."}
                      {selected && ` Selected area: ${valueFilterState(selected, choice)}.`}
                    </p>
                  )}
                  {workspace && (choice.level !== "national" || selected) && (
                    <NationalMapLocator
                      onLocator={trackLocator}
                      geometry={geometry?.key === key ? geometry.collection : undefined}
                      selected={selected?.code ?? null}
                      name={selected?.name ?? data.area}
                    />
                  )}
                  {bhw && (
                    <p className="text-xs text-muted">
                      Place labels adjust with zoom to avoid overlaps. Use the area selector for
                      every name, including missing shapes.
                    </p>
                  )}
                  {workspace && selected && (
                    <p role="status" className="rounded bg-surface p-2 text-sm">
                      Selected: {selected.name} · {selected.value ?? "Not reported"}
                      {selected.value !== null ? ` ${metric?.unit}` : ""}. Use Areas and data for
                      details and drill-down.
                    </p>
                  )}
                  <p className="text-xs text-muted">
                    {view === "circles"
                      ? "Circle area is proportional to the count. Hollow markers identify reported zero; missing counts have no circle. "
                      : navigationOnly
                        ? income
                          ? "Geography navigation only. Choose a region to see published LGU classes, then a province for its cities and municipalities."
                          : "Geography navigation only. Choose a province to see published city/municipality estimates."
                        : income
                          ? "Published classes, not income amounts or relative quantiles. Gray indicates an unclassified or unmatched area; see the table for the reason."
                          : poverty
                            ? "Fixed percentage breaks across provinces. Gray means missing coverage, including excluded HUCs; it does not mean zero poverty."
                            : accreditation
                              ? `${choice.classification ? "Color groups use all reported child-area shares." : "Fixed percentage breaks across areas."} Gray means the published share or its denominator is unavailable, not zero percent.`
                              : profiling
                                ? "Fixed share breaks: 0–<25%, 25–<50%, 50–<75%, 75–<100%, and 100% or more. Missing snapshots and zero denominators have no share."
                                : censusMean
                                  ? "Color classification uses all reported child-area means. Gray means no reported value."
                                  : supportsCircles(choice)
                                    ? "Color classification uses all reported child-area counts. Gray means no reported value."
                                    : "Quantiles relative to displayed areas. Gray means no reported value."}{" "}
                    Missing shapes remain in the table. Use Ctrl/⌘ + scroll to zoom; two fingers to
                    pan.
                  </p>
                  {workspace && (accreditation || censusMean) && (
                    <p className="text-xs text-muted">
                      Map denominator: {metric?.denominator ?? STEPZERO_ACCREDITATION_DENOMINATOR}.
                      {censusMean
                        ? " Missing active-year counts are excluded; contributing sample size is unavailable."
                        : " Published shares are not averaged across places."}
                    </p>
                  )}
                  {!workspace && legend}
                  {shapeCodes && data.rows.some((r) => !shapeCodes.includes(r.code)) && (
                    <p role="status" className="rounded border border-border p-3 text-sm">
                      {data.rows.filter((r) => !shapeCodes.includes(r.code)).length} of{" "}
                      {data.rows.length} areas have no drawable shape. Their values remain in the
                      total, selector and complete table. No substitute positions are inferred.
                    </p>
                  )}
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
              {result?.key === key && result.data.state === "failed" && (
                <button type="button" className="underline" onClick={() => setRetry((r) => r + 1)}>
                  Retry map
                </button>
              )}
            </div>
            <div
              id={`${panelId}-areas`}
              role={workspace ? "region" : undefined}
              aria-label={workspace ? "Areas and data" : undefined}
              className={
                workspace
                  ? `${panel === "areas" ? "block" : "hidden"} min-w-0 space-y-4 lg:block lg:max-h-[75vh] lg:overflow-auto lg:pr-2`
                  : "space-y-4"
              }
            >
              {workspace && <h2 className="font-semibold">Areas and data</h2>}
              {workspace && !data && (
                <p role="status">
                  Area details will appear when data is available. Map loading and retry controls
                  are in Map and legend.
                </p>
              )}
              {data && (
                <>
                  <p className="text-sm">
                    {censusMean ? (
                      <>
                        Reported area mean:{" "}
                        {data.parentMean == null ? "Not reported" : `${data.parentMean} years`}.
                        Denominator: {metric?.denominator}. Missing active-year counts are excluded.
                        Parent means are reported separately, never averaged from children.
                        Contributing sample size and confidence intervals are unavailable.
                      </>
                    ) : accreditation ? (
                      <>
                        Reported area rate:{" "}
                        {data.parentRate == null ? "Not reported" : `${data.parentRate}%`}.
                        Denominator: {metric?.denominator ?? STEPZERO_ACCREDITATION_DENOMINATOR}.
                        Published percentages are not averaged across areas.
                        {censusRate
                          ? censusUncertainty
                            ? "BHW Profiles percentage; published 95% Wilson intervals and small-sample notes appear below."
                            : "Share receiving any honorarium; no confidence interval is supplied for this measure. This does not describe amounts or receipt from a particular payer."
                          : "This is the LGU declaration, not accreditation among validated Census profiles."}
                      </>
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
                      Published qualifying-route counts, not new classifications or averages of
                      source percentages. Routes overlap and must not be added together. The health
                      route uses only barangays with evaluable provincial benchmarks.{" "}
                      {data.totalNote}
                    </p>
                  )}
                  {workspace && rangeKind && !navigationOnly && view === "choropleth" && (
                    <ClassificationControls
                      kind={rangeKind}
                      key={`${choice.indicator}:${classificationKey}`}
                      settings={choice}
                      onApply={(settings) =>
                        setChoice((current) => ({
                          ...current,
                          classification: settings.classification,
                          classCount: settings.classCount,
                          classBreaks: settings.classBreaks,
                        }))
                      }
                    />
                  )}
                  {workspace && rangeKind && !navigationOnly && (
                    <ValueFilterControls
                      kind={rangeKind}
                      key={`${choice.indicator}:${choice.valueMin}:${choice.valueMax}`}
                      range={choice}
                      onApply={(range) =>
                        setChoice((current) => ({
                          ...current,
                          valueMin: range.valueMin,
                          valueMax: range.valueMax,
                        }))
                      }
                    />
                  )}
                  <label className="block text-sm">
                    Search areas by name or PSGC code
                    <input
                      type="search"
                      maxLength={100}
                      aria-label={`${definition.title} search areas`}
                      aria-describedby={searchStatusId}
                      className="mt-1 block w-full min-w-0 rounded border border-border bg-background p-2"
                      value={searchText}
                      onChange={(e) => setSearch({ scope: searchScope, text: e.target.value })}
                    />
                  </label>
                  {searchText && (
                    <button
                      type="button"
                      className="text-sm underline"
                      onClick={() => setSearch({ scope: searchScope, text: "" })}
                    >
                      Clear area search
                    </button>
                  )}
                  <p id={searchStatusId} role="status" className="text-sm">
                    {matchingAreas.length} matching areas of {data.rows.length}.
                    {selectedOutsideSearch &&
                      " The selected area is retained outside these search results."}
                  </p>
                  <p className="text-sm text-muted">
                    Search narrows the area selector only. The map, totals, complete table and
                    downloads stay unchanged.
                  </p>
                  <label className="block text-sm">
                    Select an area
                    <select
                      aria-label={`${definition.title} area`}
                      className="mt-1 block w-full min-w-0 rounded border border-border bg-background p-2"
                      value={selected?.code ?? ""}
                      onChange={(e) => setChoice({ ...choice, selected: e.target.value || null })}
                    >
                      <option value="">Choose an area</option>
                      {selectedOutsideSearch && (
                        <option value={selected.code}>
                          {selected.name} (selected; outside search results)
                        </option>
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
                      {comparisonHref && (
                        <div>
                          <Link href={comparisonHref} className="underline">
                            Compare selected place
                          </Link>
                          <p className="text-xs text-muted">
                            Opens this indicator and place. Choose another place at the same level.
                            Comparison uses published values; map filters and color groups do not
                            apply.
                          </p>
                        </div>
                      )}
                      {(income || profiling || uucRoute || accreditation || censusMean) && (
                        <p>{selected.note}</p>
                      )}
                      {(poverty || censusUncertainty) && !navigationOnly && (
                        <p>
                          Published {censusRate ? 95 : 90}% confidence interval:{" "}
                          {selected.ciLow != null && selected.ciHigh != null
                            ? `${selected.ciLow}%–${selected.ciHigh}%`
                            : "Not available"}
                          .
                        </p>
                      )}
                      {data.childLevel === "region" ||
                      data.childLevel === "province" ||
                      (data.childLevel === "citymun" && data.barangayDrill) ? (
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
                  {workspace && supportsCircles(choice) && metric && (
                    <RankedMapBars
                      range={choice}
                      rows={data.rows}
                      area={data.area}
                      label={metric.label}
                      unit={metric.unit}
                      period={metric.period}
                      selected={selected?.code ?? null}
                      onSelect={(code) => setChoice((current) => ({ ...current, selected: code }))}
                    />
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
                              <th scope="col">
                                Published {censusRate ? 95 : 90}% confidence interval
                              </th>
                            )}
                            {(income || profiling || uucRoute || accreditation || censusMean) &&
                              !navigationOnly && <th scope="col">Source context</th>}
                            {filtering && <th scope="col">{rangeLabel} range</th>}
                            <th scope="col">Geometry</th>
                          </tr>
                        </thead>
                        <tbody>
                          {data.rows.map((r) => (
                            <tr key={r.code}>
                              <th scope="row" className="py-2 font-normal">
                                <button
                                  type="button"
                                  className="text-left underline"
                                  onClick={() => setChoice({ ...choice, selected: r.code })}
                                >
                                  {r.name}
                                </button>
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
                              {(income || profiling || uucRoute || accreditation || censusMean) &&
                                !navigationOnly && <td>{r.note ?? "—"}</td>}
                              {filtering && <td>{valueFilterState(r, choice)}</td>}
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
            </div>
          </div>
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
        <Link prefetch={false} href={mapHref(bhw ? { ...choice, view } : choice)}>
          {expanded ? "Share this map selection" : `Enlarge ${definition.title} map`}
        </Link>
        {dashboard && (
          <Link
            href={dashboard === "/bhw" ? (bhwOverviewReturnHref(choice) ?? dashboard) : dashboard}
          >
            Open dashboard
          </Link>
        )}
        {expanded && data && (
          <button
            type="button"
            onClick={downloadPng}
            disabled={!active || capture?.key !== key || exporting}
            className={
              workspace && panel === "areas"
                ? "hidden disabled:opacity-50 lg:inline"
                : "disabled:opacity-50"
            }
          >
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
