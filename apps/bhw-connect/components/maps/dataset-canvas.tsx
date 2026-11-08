"use client";
import "maplibre-gl/dist/maplibre-gl.css";
import { useEffect, useRef, useState } from "react";
import { loadMaplibre } from "./load-maplibre";
import { geometryPositions } from "@/lib/geo/positions";
import { colorForValue, NO_DATA_COLOR, type ColorBin } from "@/lib/charts/color-scale";
import type { MapRow } from "@/lib/maps/prototype";

export function DatasetCanvas({
  url,
  rows,
  bins,
  selected,
  onSelect,
  onGeometry,
  expanded,
}: {
  url: string;
  rows: MapRow[];
  bins: ColorBin[];
  selected: string | null;
  onSelect: (code: string) => void;
  onGeometry: (codes: string[]) => void;
  expanded: boolean;
}) {
  const container = useRef<HTMLDivElement>(null);
  const mapRef = useRef<import("maplibre-gl").Map | null>(null);
  const selectedRef = useRef(selected);
  useEffect(() => {
    selectedRef.current = selected;
    if (mapRef.current?.getLayer("selection"))
      mapRef.current.setFilter("selection", ["==", "geo_code", selected ?? ""]);
  }, [selected]);
  const [failed, setFailed] = useState(false);
  const callbacks = useRef({ onSelect, onGeometry });
  useEffect(() => {
    callbacks.current = { onSelect, onGeometry };
  });
  useEffect(() => {
    const abort = new AbortController();
    let map: import("maplibre-gl").Map | undefined;
    let disposed = false;
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
          feature.properties.color =
            row.value === null ? NO_DATA_COLOR : colorForValue(row.value, bins);
          for (const position of geometryPositions(feature.geometry)) bounds.extend(position);
        }
        callbacks.current.onGeometry(codes);
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
          if (!disposed) setFailed(true);
        });
        map.on("load", () => {
          if (!map || disposed) return;
          map.resize();
          map.addSource("areas", { type: "geojson", data: geometry });
          map.addLayer({
            id: "fill",
            type: "fill",
            source: "areas",
            paint: { "fill-color": ["get", "color"], "fill-opacity": 0.9 },
          });
          map.addLayer({
            id: "outline",
            type: "line",
            source: "areas",
            paint: { "line-color": "#fff", "line-width": 1 },
          });
          map.addLayer({
            id: "selection",
            type: "line",
            source: "areas",
            filter: ["==", "geo_code", selectedRef.current ?? ""],
            paint: { "line-color": "#111827", "line-width": 3 },
          });
          if (!bounds.isEmpty()) map.fitBounds(bounds, { padding: 20, animate: false });
          map.getCanvas().tabIndex = -1;
          map.on("click", "fill", (e) => {
            const code = e.features?.[0]?.properties?.geo_code;
            if (typeof code === "string") callbacks.current.onSelect(code);
          });
        });
      } catch {
        if (!disposed) setFailed(true);
      }
    }
    void start();
    return () => {
      disposed = true;
      abort.abort();
      resize?.disconnect();
      map?.remove();
      mapRef.current = null;
    };
  }, [url, rows, bins]);
  return (
    <div>
      <div
        aria-hidden="true"
        ref={container}
        className={
          expanded
            ? "h-[55vh] min-h-64 w-full rounded-lg border border-border"
            : "h-64 w-full rounded-lg border border-border"
        }
      />
      {failed && (
        <p role="status" className="text-sm">
          Map unavailable. Use the area selector and complete table below.
        </p>
      )}
    </div>
  );
}
