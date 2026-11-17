"use client";
import "maplibre-gl/dist/maplibre-gl.css";
import { useEffect, useMemo, useRef, useState } from "react";
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
  expanded,
  emphasis,
  compact = false,
  labels = false,
  excludedCodes = [],
  view = "choropleth",
}: {
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
  const container = useRef<HTMLDivElement>(null);
  const mapRef = useRef<import("maplibre-gl").Map | null>(null);
  const resetView = useRef<(() => void) | null>(null);
  const selectedRef = useRef(selected);
  useEffect(() => {
    selectedRef.current = selected;
    if (mapRef.current?.getLayer("selection"))
      mapRef.current.setFilter("selection", ["==", "geo_code", selected ?? ""]);
  }, [selected]);

  const callbacks = useRef({ onSelect, onGeometry, onCapture });
  const excludedKey = excludedCodes.join(",");
  const emphasisKey = emphasis?.join(",") ?? "";
  // A new renderer must not inherit the previous canvas's ready/error status.
  const renderVersion = useMemo(
    () => ({ url, rows, emphasisKey, view, labels }),
    [url, rows, emphasisKey, view, labels],
  );
  const [renderResult, setRenderResult] = useState<{
    version: typeof renderVersion;
    state: "ready" | "failed";
  } | null>(null);
  const ready = renderResult?.version === renderVersion && renderResult.state === "ready";
  const failed = renderResult?.version === renderVersion && renderResult.state === "failed";
  useEffect(() => {
    callbacks.current = { onSelect, onGeometry, onCapture };
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
          if (view === "circles") {
            map.addSource("symbols", {
              type: "geojson",
              data: { type: "FeatureCollection", features: points },
            });
            map.addLayer({
              id: "counts",
              type: "circle",
              source: "symbols",
              filter: [">", "value", 0],
              layout: { "circle-sort-key": ["-", 0, ["get", "value"]] },
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
              paint: {
                "circle-radius": 3,
                "circle-opacity": 0,
                "circle-stroke-width": 2,
                "circle-stroke-color": "#0c747b",
              },
            });
            for (const layer of ["counts", "zeros"])
              map.on("click", layer, (e) => {
                const code = e.features?.[0]?.properties?.geo_code;
                if (typeof code === "string") callbacks.current.onSelect(code);
              });
          }
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
                "text-radial-offset": view === "circles" ? 1.8 : 0.4,
                "text-allow-overlap": false,
              },
              paint: {
                "text-color": "#111827",
                "text-halo-color": "#ffffff",
                "text-halo-width": 1.5,
              },
            });
            map.on("click", "place-labels", (e) => {
              const code = e.features?.[0]?.properties?.geo_code;
              if (typeof code === "string") callbacks.current.onSelect(code);
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
          if (!bounds.isEmpty()) map.fitBounds(bounds, { padding: 20, animate: false });
          resetView.current = () => {
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
          map.on("click", "fill", (e) => {
            const symbolCode =
              view === "circles"
                ? map?.queryRenderedFeatures(e.point, { layers: ["counts", "zeros"] })[0]
                    ?.properties?.geo_code
                : undefined;
            const labelCode = labels
              ? map?.queryRenderedFeatures(e.point, { layers: ["place-labels"] })[0]?.properties
                  ?.geo_code
              : undefined;
            const code = labelCode ?? symbolCode ?? e.features?.[0]?.properties?.geo_code;
            if (typeof code === "string") callbacks.current.onSelect(code);
          });
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
  }, [url, rows, emphasisKey, view, labels, renderVersion]);
  return (
    <div>
      <div
        aria-label="Map viewport controls"
        role="group"
        className="mb-2 flex flex-wrap gap-3 text-sm"
      >
        <button
          type="button"
          className="min-h-11 min-w-11 rounded border border-border p-2"
          disabled={!ready || failed}
          onClick={() => mapRef.current?.zoomIn()}
          aria-label="Zoom in"
        >
          {compact ? "+" : "Zoom in"}
        </button>
        <button
          type="button"
          className="min-h-11 min-w-11 rounded border border-border p-2"
          disabled={!ready || failed}
          onClick={() => mapRef.current?.zoomOut()}
          aria-label="Zoom out"
        >
          {compact ? "−" : "Zoom out"}
        </button>
        <button
          type="button"
          className="min-h-11 min-w-11 rounded border border-border p-2"
          disabled={!ready || failed}
          onClick={() => resetView.current?.()}
        >
          Reset map view
        </button>
      </div>
      <div
        aria-hidden="true"
        data-map-state={failed ? "failed" : ready ? "ready" : "loading"}
        ref={container}
        className={
          compact
            ? "h-[32svh] min-h-60 w-full rounded-lg border border-border lg:h-[55vh]"
            : expanded
              ? "h-[55vh] min-h-64 w-full rounded-lg border border-border"
              : "h-64 w-full rounded-lg border border-border"
        }
      />
      {!ready && !failed && (
        <p role="status" className="text-sm">
          Loading map rendering…
        </p>
      )}
      {failed && (
        <div className="space-y-2 text-sm">
          <p role="status">Map unavailable. Use the area selector and complete table below.</p>
          <button type="button" className="underline" onClick={onRetry}>
            Retry map rendering
          </button>
        </div>
      )}
    </div>
  );
}
