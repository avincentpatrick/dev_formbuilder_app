"use client";

import { geometryPositions } from "@/lib/geo/positions";

import "maplibre-gl/dist/maplibre-gl.css";
import { useEffect, useMemo, useRef, useState } from "react";
import { accent, muted } from "@/lib/charts/palette";
import { pointRadius, type BarangayPoint } from "@/lib/geo/facility-points";
import { loadMaplibre } from "./load-maplibre";

type GeoJson = {
  type: "FeatureCollection";
  features: {
    type: "Feature";
    properties: Record<string, unknown>;
    geometry: { type: "Point"; coordinates: [number, number] };
  }[];
};

function toGeoJson(points: BarangayPoint[], maxFacilities: number): GeoJson {
  return {
    type: "FeatureCollection",
    features: points.map((p) => ({
      type: "Feature",
      properties: {
        geo_code: p.geoCode,
        name: p.geoName,
        n: p.nFacilities,
        __r: pointRadius(p.nFacilities, maxFacilities),
      },
      geometry: { type: "Point", coordinates: [p.lon, p.lat] },
    })),
  };
}

/** Barangay representative points, with DOM controls and table-equivalent selection.
 * The canvas alone is hidden from assistive technology; the surrounding controls are usable.
 * Simplified outlines are context, never a reason to shift a representative point.
 */
export function PointMap({
  points,
  maxFacilities,
  outlineUrl,
  outlineGeoCode,
  selectedCode = "",
  onSelect,
}: {
  points: BarangayPoint[];
  maxFacilities: number;
  outlineUrl: string | null;
  outlineGeoCode: string;
  selectedCode?: string;
  onSelect?: (code: string) => void;
}) {
  const containerRef = useRef<HTMLDivElement>(null);
  const tooltipRef = useRef<HTMLDivElement>(null);
  const mapRef = useRef<import("maplibre-gl").Map | undefined>(undefined);
  const [mapReady, setMapReady] = useState(false);
  const [failed, setFailed] = useState(false);
  const selectRef = useRef(onSelect);
  useEffect(() => {
    selectRef.current = onSelect;
  }, [onSelect]);

  const geojson = useMemo(() => toGeoJson(points, maxFacilities), [points, maxFacilities]);
  // Latest-value ref so the one-time init effect's `load` handler reads the current features
  // without re-initializing the map on every render (ChoroplethMap's `dataRef` precedent).
  const geojsonRef = useRef(geojson);
  useEffect(() => {
    geojsonRef.current = geojson;
  });

  useEffect(() => {
    let cancelled = false;
    let map: import("maplibre-gl").Map | undefined;
    const timeout = setTimeout(() => {
      if (!cancelled) setFailed(true);
    }, 15000);

    Promise.all([
      loadMaplibre(),
      // A missing or unparseable outline is cosmetic, so it resolves to null rather than
      // rejecting and taking the whole map with it.
      outlineUrl
        ? fetch(outlineUrl)
            .then((r) => r.json())
            .catch(() => null)
        : Promise.resolve(null),
    ])
      .then(([maplibregl, outline]) => {
        if (cancelled || !containerRef.current) return;

        const outlineFeatures = (
          (outline?.features ?? []) as { properties?: { geo_code?: string }; geometry: unknown }[]
        ).filter((f) => f.properties?.geo_code === outlineGeoCode && f.geometry);

        map = new maplibregl.Map({
          container: containerRef.current,
          style: {
            version: 8,
            sources: {},
            layers: [
              { id: "background", type: "background", paint: { "background-color": "#f6f7f8" } },
            ],
          },
          center: [122, 12],
          zoom: 4.2,
          attributionControl: false,
          // Ctrl/Cmd+wheel to zoom, two-finger touch pan — a plain wheel/one-finger drag scrolls
          // the page instead of being trapped (E0.3).
          cooperativeGestures: true,
        });
        mapRef.current = map;
        map.on("error", () => {
          if (!cancelled) setFailed(true);
        });
        map.addControl(new maplibregl.NavigationControl({ showCompass: false }), "top-right");
        map.getCanvas().tabIndex = -1;
        map.getCanvas().setAttribute("aria-hidden", "true");
        // Controls remain keyboard accessible while the canvas is decorative.
        containerRef.current.querySelectorAll("button, a").forEach((el) => {
          (el as HTMLElement).tabIndex = 0;
        });

        map.on("load", () => {
          if (!map) return;

          if (outlineFeatures.length > 0) {
            map.addSource("outline", {
              type: "geojson",
              data: { type: "FeatureCollection", features: outlineFeatures } as never,
            });
            map.addLayer({
              id: "outline-fill",
              type: "fill",
              source: "outline",
              paint: { "fill-color": "#ffffff", "fill-opacity": 0.9 },
            });
            map.addLayer({
              id: "outline-line",
              type: "line",
              source: "outline",
              paint: { "line-color": "#c8cfd3", "line-width": 1 },
            });
          }

          map.addSource("barangays", {
            type: "geojson",
            data: geojsonRef.current as never,
            promoteId: "geo_code",
          });
          // Two layers, not one paint expression: an empty barangay is a different *kind* of thing
          // from a barangay with one facility, and a hollow ring says that where a very small
          // filled dot would read as "almost none" (BUILD_PLAN §5 — no meaning by color alone;
          // fill vs. outline is the second channel).
          map.addLayer({
            id: "barangay-empty",
            type: "circle",
            source: "barangays",
            filter: ["==", ["get", "n"], 0],
            paint: {
              "circle-radius": ["get", "__r"],
              "circle-color": "rgba(0,0,0,0)",
              "circle-stroke-color": muted,
              "circle-stroke-width": 1,
              "circle-stroke-opacity": 0.7,
            },
          });
          map.addLayer({
            id: "barangay-facilities",
            type: "circle",
            source: "barangays",
            filter: [">", ["get", "n"], 0],
            paint: {
              "circle-radius": ["get", "__r"],
              "circle-color": accent,
              "circle-opacity": 0.65,
              "circle-stroke-color": "#ffffff",
              "circle-stroke-width": 1,
            },
          });

          const bounds = new maplibregl.LngLatBounds();
          for (const feature of geojsonRef.current.features) {
            bounds.extend(feature.geometry.coordinates);
          }
          for (const feature of outlineFeatures) {
            for (const position of geometryPositions(feature.geometry)) bounds.extend(position);
          }
          const fitAll = () => {
            if (map && !bounds.isEmpty()) {
              map.fitBounds(bounds, { padding: 32, maxZoom: 13, animate: false });
            }
          };
          fitAll();

          // "Reset view" control — re-runs the initial fitBounds (E0.3).
          const ResetControl = class {
            _container?: HTMLDivElement;
            onAdd() {
              const el = document.createElement("div");
              el.className = "maplibregl-ctrl maplibregl-ctrl-group";
              const btn = document.createElement("button");
              btn.type = "button";
              btn.tabIndex = 0;
              btn.title = "Reset view";
              btn.setAttribute("aria-label", "Reset view");
              btn.textContent = "⤢";
              btn.style.fontSize = "14px";
              btn.addEventListener("click", () => fitAll());
              el.appendChild(btn);
              this._container = el;
              return el;
            }
            onRemove() {
              this._container?.remove();
            }
          };
          map.addControl(new ResetControl(), "top-right");

          // Keep injected controls keyboard accessible.
          containerRef.current?.querySelectorAll("button, a").forEach((el) => {
            (el as HTMLElement).tabIndex = 0;
          });

          const LAYERS = ["barangay-facilities", "barangay-empty"];

          const showTooltip = (
            point: { x: number; y: number },
            properties: Record<string, unknown>,
          ) => {
            const tip = tooltipRef.current;
            if (!tip) return;
            const n = Number(properties.n ?? 0);
            const lines = [
              String(properties.name ?? ""),
              n === 0
                ? "No facility recorded"
                : `${n.toLocaleString()} health ${n === 1 ? "facility" : "facilities"} registered here`,
            ];
            tip.replaceChildren(
              ...lines.map((text, i) => {
                const div = document.createElement("div");
                div.textContent = text;
                if (i === 0) div.style.fontWeight = "600";
                else div.style.opacity = "0.85";
                return div;
              }),
            );
            tip.style.display = "block";
            const rect = containerRef.current?.getBoundingClientRect();
            const maxX = (rect?.width ?? 320) - tip.offsetWidth - 8;
            tip.style.left = `${Math.max(8, Math.min(point.x + 12, maxX))}px`;
            tip.style.top = `${Math.max(8, Math.min(point.y + 12, (rect?.height ?? 320) - tip.offsetHeight - 8))}px`;
          };
          const hideTooltip = () => {
            if (tooltipRef.current) tooltipRef.current.style.display = "none";
          };

          for (const layer of LAYERS) {
            map.on("mouseenter", layer, () => {
              if (map) map.getCanvas().style.cursor = "pointer";
            });
            map.on("mousemove", layer, (e) => {
              const properties = e.features?.[0]?.properties;
              if (properties) showTooltip(e.point, properties);
            });
            map.on("mouseleave", layer, () => {
              if (map) map.getCanvas().style.cursor = "";
              hideTooltip();
            });
          }
          // Touch has no hover: a tap on a dot reads it out, a tap elsewhere dismisses. Same flow,
          // and harmless on mouse.
          map.on("click", (e) => {
            if (!map) return;
            const hit = map.queryRenderedFeatures(e.point, { layers: LAYERS })[0];
            if (hit?.properties) {
              showTooltip(e.point, hit.properties);
              selectRef.current?.(String(hit.properties.geo_code));
            } else hideTooltip();
          });

          map.addLayer({
            id: "selected-barangay",
            type: "circle",
            source: "barangays",
            filter: ["==", ["get", "geo_code"], ""],
            paint: {
              "circle-radius": ["+", ["get", "__r"], 4],
              "circle-color": "transparent",
              "circle-stroke-color": "#111827",
              "circle-stroke-width": 3,
            },
          });
          clearTimeout(timeout);
          setFailed(false);
          setMapReady(true);
        });
      })
      .catch(() => {
        if (!cancelled) setFailed(true);
      });

    return () => {
      cancelled = true;
      clearTimeout(timeout);
      setMapReady(false);
      mapRef.current = undefined;
      map?.remove();
    };
  }, [outlineUrl, outlineGeoCode]);

  // Repaint without re-initializing when the points change (a client-side navigation between two
  // city/municipality pages reuses this component). Gated on a signature so an unrelated
  // re-render doesn't trigger a needless `setData` — ChoroplethMap's precedent.
  const pointSignature = useMemo(
    () => JSON.stringify([maxFacilities, points.map((p) => [p.geoCode, p.nFacilities])]),
    [points, maxFacilities],
  );
  useEffect(() => {
    const map = mapRef.current;
    if (!map || !mapReady) return;
    const source = map.getSource("barangays") as { setData?: (data: unknown) => void } | undefined;
    source?.setData?.(geojsonRef.current);
    if (tooltipRef.current) tooltipRef.current.style.display = "none";
  }, [pointSignature, mapReady]);

  useEffect(() => {
    if (mapReady && mapRef.current?.getLayer("selected-barangay"))
      mapRef.current.setFilter("selected-barangay", ["==", ["get", "geo_code"], selectedCode]);
  }, [selectedCode, mapReady]);
  return (
    <div className="relative">
      {failed && (
        <p role="status">The interactive map could not be loaded. Use the barangay table below.</p>
      )}
      <div
        ref={containerRef}
        role="region"
        aria-label="Barangay map; select barangays using the table below"
        className="h-80 w-full overflow-hidden rounded-md border border-border presentation:h-[60vh]"
      />
      <div
        ref={tooltipRef}
        aria-hidden="true"
        className="pointer-events-none absolute z-10 hidden max-w-[220px] rounded-md border border-border bg-background px-2 py-1 text-xs shadow-md"
        style={{ display: "none" }}
      />
    </div>
  );
}
