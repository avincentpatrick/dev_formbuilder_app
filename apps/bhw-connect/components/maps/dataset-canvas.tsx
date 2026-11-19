"use client";
import "maplibre-gl/dist/maplibre-gl.css";
import Link from "next/link";
import { useEffect, useMemo, useRef, useState } from "react";
import type { MapCamera } from "@/lib/maps/zoom-geography";
import { formatCount, percentageFormatter } from "@/lib/format";
import { captureMapFrame } from "@/lib/maps/png";
import { loadMaplibre } from "./load-maplibre";
import { geometryPositions } from "@/lib/geo/positions";
import type { ColorBin } from "@/lib/charts/color-scale";
import { applyDatasetStyle } from "./dataset-style";
import type { MapRow } from "@/lib/maps/prototype";
import { mapLabelPoints } from "@/lib/maps/orientation";
import type { BoundaryCollection } from "@/lib/geo/locator-shapes";
import { boundaryCenter, symbolMaximum, symbolRadius } from "@/lib/maps/symbols";

export function DatasetCanvas({
  url,
  rows,
  bins,
  selected,
  onSelect,
  onGeometry,
  onCapture,
  onRetry,
  onZoomGeography,
  initialCamera,
  geographyLabel,
  metricLabel,
  mapOnly = false,
  tableOpen = false,
  onToggleTable,
  localityHref,
  onMapOnly,
  unit = "",
  formatValueLabel,
  expanded,
  emphasis,
  compact = false,
  labels = false,
  excludedCodes = [],
  view = "choropleth",
}: {
  mapOnly?: boolean;
  tableOpen?: boolean;
  onToggleTable?: () => void;
  localityHref?: (code: string) => string | null;
  onMapOnly?: () => void;
  unit?: string;
  formatValueLabel?: (value: number) => string;
  geographyLabel?: string;
  metricLabel?: string;
  initialCamera?: MapCamera;
  onZoomGeography?: (camera: MapCamera, direction: "in" | "out", focus: string | null) => void;
  url: string;
  rows: MapRow[];
  bins: ColorBin[];
  selected: string | null;
  onSelect: (code: string) => void;
  onGeometry: (codes: string[], geometry?: BoundaryCollection) => void;
  onCapture: (capture: (() => Promise<HTMLCanvasElement>) | null) => void;
  expanded: boolean;
  onRetry: () => void;
  /** Areas to outline strongly, e.g. one district's members among their province's municipalities. */
  emphasis?: readonly string[];
  /** BHW workspace fits a useful visual beside its legend on phones. */
  compact?: boolean;
  /** Collision-managed names from the accepted area rows; no remote font source. */
  labels?: boolean;
  excludedCodes?: readonly string[];
  view?: "circles" | "choropleth";
}) {
  const [dismissed, setDismissed] = useState<string | null>(null);
  const formatPercentage = useMemo(
    () => percentageFormatter(rows.flatMap((row) => (row.value === null ? [] : [row.value]))),
    [rows],
  );
  const [pinned, setPinned] = useState<string | null>(null);
  const [hovered, setHovered] = useState<string | null>(null);
  const [visibleCodes, setVisibleCodes] = useState<string[]>([]);
  const activeCode = mapOnly ? (pinned ?? hovered) : selected;
  const valueRow =
    activeCode !== dismissed ? rows.find((row) => row.code === activeCode) : undefined;
  const visibleSet = new Set(visibleCodes);
  const excludedSet = new Set(excludedCodes);
  const visibleRows = rows.filter((row) => visibleSet.has(row.code) && !excludedSet.has(row.code));
  const formatValue = (value: number | null) =>
    value === null
      ? "Not reported"
      : formatValueLabel
        ? formatValueLabel(value)
        : unit === "%"
          ? formatPercentage(value)
          : `${formatCount(value)} ${unit}`.trim();
  const profileHref = mapOnly && pinned && localityHref ? localityHref(pinned) : null;
  const cameraOnMount = useRef(initialCamera);
  const zoomIntent = useRef(false);
  const container = useRef<HTMLDivElement>(null);
  const mapRef = useRef<import("maplibre-gl").Map | null>(null);
  useEffect(() => {
    const element = container.current;
    if (!element) return;
    const wheel = () => {
      zoomIntent.current = true;
    };
    const touch = (event: TouchEvent) => {
      if (event.touches.length >= 2) zoomIntent.current = true;
    };
    element.addEventListener("wheel", wheel, { passive: true });
    element.addEventListener("touchstart", touch, { passive: true });
    element.addEventListener("dblclick", wheel);
    return () => {
      element.removeEventListener("wheel", wheel);
      element.removeEventListener("touchstart", touch);
      element.removeEventListener("dblclick", wheel);
    };
  }, []);

  const resetView = useRef<(() => void) | null>(null);
  const selectedRef = useRef(activeCode);
  useEffect(() => {
    selectedRef.current = activeCode;
    if (mapRef.current?.getLayer("selection"))
      mapRef.current.setFilter("selection", ["==", "geo_code", activeCode ?? ""]);
  }, [activeCode]);

  const callbacks = useRef({ onSelect, onGeometry, onCapture, onZoomGeography, mapOnly });
  const excludedKey = excludedCodes.join(",");
  const emphasisKey = emphasis?.join(",") ?? "";
  // A new renderer must not inherit the previous canvas's ready/error status.
  const renderVersion = useMemo(
    () => ({ url, rows, emphasisKey, labels }),
    [url, rows, emphasisKey, labels],
  );
  const [renderResult, setRenderResult] = useState<{
    version: typeof renderVersion;
    state: "ready" | "failed";
  } | null>(null);
  const ready = renderResult?.version === renderVersion && renderResult.state === "ready";
  const failed = renderResult?.version === renderVersion && renderResult.state === "failed";
  useEffect(() => {
    const map = mapRef.current;
    if (mapOnly) map?.cooperativeGestures.disable();
    else map?.cooperativeGestures.enable();
  }, [mapOnly, ready]);
  useEffect(() => {
    callbacks.current = {
      onSelect: (code: string) => {
        setDismissed(null);
        if (mapOnly) setPinned(code);
        else onSelect(code);
      },
      onGeometry,
      onCapture,
      onZoomGeography,
      mapOnly,
    };
  });
  const display = useRef({ rows, bins, excludedKey, view });
  useEffect(() => {
    display.current = { rows, bins, excludedKey, view };
    const map = mapRef.current;
    if (map?.getLayer("fill")) {
      applyDatasetStyle(map, rows, bins, excludedKey.split(",").filter(Boolean), view);
    }
  }, [rows, bins, excludedKey, view]);
  useEffect(() => {
    const abort = new AbortController();
    let map: import("maplibre-gl").Map | undefined;
    let disposed = false;
    let renderFailed = false;
    let resize: ResizeObserver | undefined;
    async function start() {
      try {
        const [gl, response] = await Promise.all([
          loadMaplibre(),
          fetch(url, { signal: abort.signal }),
        ]);
        if (!response.ok) throw new Error("Boundary unavailable");
        const geometry = await response.json();
        if (disposed || !container.current) return;
        if (geometry.type !== "FeatureCollection" || !Array.isArray(geometry.features))
          throw new Error("Invalid boundary");
        const byCode = new Map(rows.map((r) => [r.code, r]));
        const codes: string[] = [];
        const bounds = new gl.LngLatBounds();
        const maximum = symbolMaximum(rows);
        const points: GeoJSON.Feature<GeoJSON.Point>[] = [];
        // Retain only children in the authoritative geography table, not unrelated shapes.
        geometry.features = geometry.features.filter(
          (f: { properties?: { geo_code?: string }; geometry?: unknown }) => {
            const code = f.properties?.geo_code;
            return code && byCode.has(code) && geometryPositions(f.geometry).length > 0;
          },
        );
        for (const feature of geometry.features) {
          const code = feature.properties.geo_code;
          codes.push(code);
          const row = byCode.get(code)!;
          const center = boundaryCenter(feature.geometry);
          if (center && row.value !== null && Number.isFinite(row.value) && row.value >= 0)
            points.push({
              type: "Feature",
              geometry: { type: "Point", coordinates: center },
              properties: {
                geo_code: code,
                value: row.value,
                radius: symbolRadius(row.value, maximum),
              },
            });
          feature.properties.smallSample = row.value !== null && row.smallSample === true;
          for (const position of geometryPositions(feature.geometry)) bounds.extend(position);
        }
        callbacks.current.onGeometry(codes, geometry);
        map = new gl.Map({
          container: container.current,
          style: {
            version: 8,
            sources: {},
            layers: [
              { id: "background", type: "background", paint: { "background-color": "#f6f7f8" } },
            ],
          },
          center: [122, 12],
          zoom: 4,
          cooperativeGestures: true,
          attributionControl: false,
        });
        mapRef.current = map;
        resize = new ResizeObserver(() => map?.resize());
        resize.observe(container.current);
        map.on("error", () => {
          if (!disposed) {
            renderFailed = true;
            setRenderResult({ version: renderVersion, state: "failed" });
            callbacks.current.onCapture(null);
          }
        });
        map.on("load", () => {
          if (!map || disposed) return;
          map.resize();
          map.addSource("areas", { type: "geojson", data: geometry });
          map.addLayer({
            id: "fill",
            type: "fill",
            source: "areas",
            paint: {
              "fill-color": "#e5e7eb",
              "fill-opacity": ["case", ["==", ["get", "smallSample"], true], 0.4, 0.9],
            },
          });
          map.addLayer({
            id: "outline",
            type: "line",
            source: "areas",
            paint: { "line-color": "#fff", "line-width": 1 },
          });
          map.addLayer({
            id: "excluded-range",
            type: "line",
            source: "areas",
            filter: ["==", ["get", "excluded"], true],
            paint: { "line-color": "#6b7280", "line-width": 1.5, "line-dasharray": [3, 2] },
          });
          map.addLayer({
            id: "small-sample",
            type: "line",
            source: "areas",
            filter: ["==", "smallSample", true],
            paint: { "line-color": "#6b7280", "line-width": 2, "line-dasharray": [2, 2] },
          });
          if (emphasisKey)
            map.addLayer({
              id: "emphasis",
              type: "line",
              source: "areas",
              filter: ["in", ["get", "geo_code"], ["literal", emphasisKey.split(",")]],
              paint: { "line-color": "#374151", "line-width": 2 },
            });
          map.addLayer({
            id: "selection",
            type: "line",
            source: "areas",
            filter: ["==", "geo_code", selectedRef.current ?? ""],
            paint: { "line-color": "#111827", "line-width": 3 },
          });
          // Keep both views ready; switching visibility must not refetch or refit.
          map.addSource("symbols", {
            type: "geojson",
            data: { type: "FeatureCollection", features: points },
          });
          map.addLayer({
            id: "counts",
            type: "circle",
            source: "symbols",
            filter: [">", "value", 0],
            layout: { visibility: "none", "circle-sort-key": ["-", 0, ["get", "value"]] },
            paint: {
              "circle-radius": ["get", "radius"],
              "circle-color": "#0c747b",
              "circle-opacity": 0.85,
              "circle-stroke-width": 1,
              "circle-stroke-color": "white",
            },
          });
          map.addLayer({
            id: "zeros",
            type: "circle",
            source: "symbols",
            filter: ["==", "value", 0],
            layout: { visibility: "none" },
            paint: {
              "circle-radius": 3,
              "circle-opacity": 0,
              "circle-stroke-width": 2,
              "circle-stroke-color": "#0c747b",
            },
          });
          if (labels) {
            map.addSource("place-names", {
              type: "geojson",
              data: mapLabelPoints(rows, geometry),
            });
            map.addLayer({
              id: "place-labels",
              type: "symbol",
              source: "place-names",
              layout: {
                "text-field": ["get", "name"],
                "text-font": ["sans-serif"],
                "text-size": 13,
                "text-max-width": 10,
                "text-padding": 4,
                "text-variable-anchor": ["top", "bottom", "left", "right"],
                "text-radial-offset": 0.4,
                "text-allow-overlap": false,
              },
              paint: {
                "text-color": "#111827",
                "text-halo-color": "#ffffff",
                "text-halo-width": 1.5,
              },
            });
          }
          const currentDisplay = display.current;
          applyDatasetStyle(
            map,
            currentDisplay.rows,
            currentDisplay.bins,
            currentDisplay.excludedKey.split(",").filter(Boolean),
            currentDisplay.view,
          );
          map.addControl(new gl.ScaleControl({ unit: "metric", maxWidth: 120 }), "bottom-left");
          if (cameraOnMount.current) map.jumpTo(cameraOnMount.current);
          else if (!bounds.isEmpty()) map.fitBounds(bounds, { padding: 20, animate: false });
          let zoomStart = map.getZoom();
          map.on("zoomstart", (event) => {
            if ("originalEvent" in event && event.originalEvent) zoomIntent.current = true;
          });
          map.on("zoomend", () => {
            const intended = zoomIntent.current;
            zoomIntent.current = false;
            const previousZoom = zoomStart;
            if (map) zoomStart = map.getZoom();
            if (
              !intended ||
              !map ||
              disposed ||
              renderFailed ||
              !callbacks.current.onZoomGeography ||
              map.getZoom() === previousZoom
            )
              return;
            const center = map.getCenter();
            const point = map.project(center);
            const underCenter = map.queryRenderedFeatures(point, { layers: ["fill"] })[0]
              ?.properties?.geo_code;
            // Over water, use the closest visible area's boundary center, never an invented shape.
            const visible = new Set(
              map.queryRenderedFeatures({ layers: ["fill"] }).map((f) => f.properties?.geo_code),
            );
            let focus = typeof underCenter === "string" ? underCenter : null;
            if (!focus) {
              let distance = Infinity;
              for (const feature of geometry.features) {
                if (!visible.has(feature.properties.geo_code)) continue;
                const midpoint = boundaryCenter(feature.geometry);
                if (!midpoint) continue;
                const projected = map.project(midpoint);
                const d = Math.hypot(projected.x - point.x, projected.y - point.y);
                if (d < distance) {
                  distance = d;
                  focus = feature.properties.geo_code;
                }
              }
            }
            callbacks.current.onZoomGeography?.(
              { center: [center.lng, center.lat], zoom: map.getZoom() },
              map.getZoom() > previousZoom ? "in" : "out",
              focus,
            );
          });
          resetView.current = () => {
            zoomIntent.current = false;
            if (!bounds.isEmpty()) map?.fitBounds(bounds, { padding: 20, animate: false });
          };
          map.getCanvas().tabIndex = -1;
          map.once("idle", () => {
            if (!disposed && !renderFailed && map) {
              setRenderResult({ version: renderVersion, state: "ready" });
              const readyMap = map;
              callbacks.current.onCapture(codes.length ? () => captureMapFrame(readyMap) : null);
            }
          });
          const updateVisible = () => {
            if (!map || disposed || !callbacks.current.mapOnly) return;
            const codes = [
              ...new Set(
                map
                  .queryRenderedFeatures({ layers: ["fill"] })
                  .map((feature) => feature.properties?.geo_code)
                  .filter((code): code is string => typeof code === "string"),
              ),
            ].sort();
            setVisibleCodes((previous) =>
              previous.join(",") === codes.join(",") ? previous : codes,
            );
          };
          map.on("idle", updateVisible);
          const hitCode = (point: import("maplibre-gl").Point) => {
            const symbolCode =
              display.current.view === "circles"
                ? map?.queryRenderedFeatures(point, { layers: ["counts", "zeros"] })[0]?.properties
                    ?.geo_code
                : undefined;
            const labelCode = labels
              ? map?.queryRenderedFeatures(point, { layers: ["place-labels"] })[0]?.properties
                  ?.geo_code
              : undefined;
            const code =
              symbolCode ??
              labelCode ??
              map?.queryRenderedFeatures(point, { layers: ["fill"] })[0]?.properties?.geo_code;
            return typeof code === "string" &&
              (!callbacks.current.mapOnly || !display.current.excludedKey.split(",").includes(code))
              ? code
              : null;
          };
          map.on("click", (event) => {
            const code = hitCode(event.point);
            if (code) callbacks.current.onSelect(code);
          });
          map.on("mousemove", (event) => {
            if (!callbacks.current.mapOnly || map?.isMoving()) return;
            const code = hitCode(event.point);
            setHovered(code);
            if (map) map.getCanvas().style.cursor = code ? "pointer" : "";
          });
          map.getCanvas().addEventListener("mouseleave", () => setHovered(null));
        });
      } catch {
        if (!disposed) {
          renderFailed = true;
          setRenderResult({ version: renderVersion, state: "failed" });
          callbacks.current.onCapture(null);
        }
      }
    }
    void start();
    return () => {
      disposed = true;
      callbacks.current.onCapture(null);
      abort.abort();
      resize?.disconnect();
      map?.remove();
      mapRef.current = null;
      resetView.current = null;
    };
  }, [url, rows, emphasisKey, labels, renderVersion]);
  return (
    <div
      data-map-canvas
      className={mapOnly ? "relative flex h-full w-full flex-col lg:flex-row" : "relative"}
    >
      <div
        aria-label="Map viewport controls"
        role="group"
        className={
          mapOnly
            ? "hidden"
            : compact
              ? "absolute right-2 top-2 z-10 flex gap-1 rounded-lg border border-border bg-background p-1 text-sm lg:static lg:mb-2 lg:border-0 lg:p-0"
              : "mb-2 flex flex-wrap gap-3 text-sm"
        }
      >
        <button
          type="button"
          className="min-h-11 min-w-11 rounded border border-border p-2"
          disabled={!ready || failed}
          onClick={() => {
            zoomIntent.current = true;
            mapRef.current?.zoomIn();
          }}
          aria-label="Zoom in"
        >
          {compact ? "+" : "Zoom in"}
        </button>
        <button
          type="button"
          className="min-h-11 min-w-11 rounded border border-border p-2"
          disabled={!ready || failed}
          onClick={() => {
            zoomIntent.current = true;
            mapRef.current?.zoomOut();
          }}
          aria-label="Zoom out"
        >
          {compact ? "−" : "Zoom out"}
        </button>
        <button
          type="button"
          className="min-h-11 min-w-11 rounded border border-border p-2"
          disabled={!ready || failed}
          onClick={() => resetView.current?.()}
          aria-label="Reset map view"
        >
          {compact ? "Reset" : "Reset map view"}
        </button>
        {onMapOnly && (
          <button
            type="button"
            data-map-only-trigger
            className="min-h-11 rounded border border-border p-2"
            onClick={onMapOnly}
          >
            Map only
          </button>
        )}
      </div>
      <div className={mapOnly ? "relative min-h-0 min-w-0 flex-1" : "relative"}>
        <div
          aria-hidden="true"
          data-map-state={failed ? "failed" : ready ? "ready" : "loading"}
          ref={container}
          className={[
            ready ? "maplibregl-map" : "",
            mapOnly
              ? "h-full w-full"
              : compact
                ? "h-[60svh] min-h-80 w-full rounded-lg border border-border lg:h-[55vh]"
                : expanded
                  ? "h-[55vh] min-h-64 w-full rounded-lg border border-border"
                  : "h-64 w-full rounded-lg border border-border",
          ].join(" ")}
        />
        {geographyLabel && ready && !mapOnly && (
          <p
            className="pointer-events-none absolute bottom-2 right-2 rounded bg-background px-2 py-1 text-xs"
            aria-live="polite"
          >
            {geographyLabel}
          </p>
        )}
        {valueRow && ready && (
          <div
            role="status"
            aria-label="Selected map value"
            className="absolute bottom-10 left-2 right-2 z-20 flex items-start justify-between gap-3 rounded-lg border border-border bg-background p-3 text-left text-sm shadow-lg"
          >
            <div>
              <strong>{valueRow.name}</strong>
              <p>{formatValue(valueRow.value)}</p>
              {profileHref && (
                <Link href={profileHref} className="mt-2 inline-block underline">
                  Open locality profile
                </Link>
              )}
            </div>
            <button
              type="button"
              className="min-h-11 min-w-11 rounded border border-border px-2"
              aria-label="Close map value"
              onClick={() => {
                setDismissed(valueRow.code);
                if (mapOnly) {
                  setPinned(null);
                  setHovered(null);
                }
              }}
            >
              ×
            </button>
          </div>
        )}
      </div>
      {mapOnly && onToggleTable && (
        <button
          type="button"
          aria-expanded={tableOpen}
          onClick={onToggleTable}
          className="absolute left-2 top-2 z-30 min-h-11 rounded border border-border bg-background px-3 text-sm"
        >
          {tableOpen ? "Hide table" : "Show table"}
        </button>
      )}
      {mapOnly && tableOpen && (
        <section
          aria-label="Localities in view"
          className="h-[40dvh] shrink-0 overflow-auto border-t border-border bg-background p-3 lg:h-full lg:w-80 lg:border-l lg:border-t-0 lg:pt-16"
          tabIndex={0}
        >
          <h2 className="font-semibold">
            {geographyLabel?.replace(" figures", "") ?? "Localities"} in view ·{" "}
            {ready ? visibleRows.length : "…"}
          </h2>
          <p className="mb-2 text-xs text-muted">
            Updates when you pan or zoom. Values are for each whole locality.
          </p>
          {!ready ? (
            <p>Loading map values…</p>
          ) : visibleRows.length === 0 ? (
            <p>No matching localities in view.</p>
          ) : (
            <table className="w-full text-left text-sm">
              {metricLabel && <caption className="text-left">{metricLabel}</caption>}
              <thead>
                <tr>
                  <th scope="col">Locality</th>
                  <th scope="col">Value{unit ? ` (${unit})` : ""}</th>
                </tr>
              </thead>
              <tbody>
                {visibleRows.map((row) => (
                  <tr key={row.code} className={pinned === row.code ? "bg-surface" : ""}>
                    <th scope="row" className="font-normal">
                      <button
                        type="button"
                        className="min-h-11 text-left underline"
                        aria-pressed={pinned === row.code}
                        onClick={() => {
                          setDismissed(null);
                          setPinned(row.code);
                        }}
                      >
                        {row.name}
                      </button>
                    </th>
                    <td className="whitespace-nowrap">{formatValue(row.value)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </section>
      )}
      {!ready && !failed && (
        <p
          role="status"
          className={mapOnly ? "absolute left-2 top-16 bg-background p-3 text-sm" : "text-sm"}
        >
          Loading map rendering…
        </p>
      )}
      {failed && (
        <div
          className={
            mapOnly
              ? "absolute left-2 top-16 space-y-2 bg-background p-3 text-sm"
              : "space-y-2 text-sm"
          }
        >
          <p role="status">Map unavailable. Use the area selector and complete table below.</p>
          <button type="button" className="underline" onClick={onRetry}>
            Retry map rendering
          </button>
        </div>
      )}
    </div>
  );
}
