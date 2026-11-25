import {
  exploreFacilities,
  type ExplorationInput,
  type ExplorationFilters,
} from "@/lib/facilities/exploration";
import { geometryPositions } from "@/lib/geo/positions";
import { pointRadius } from "@/lib/geo/facility-points";
import { escapeXml } from "./render-png";
import { formatCount } from "@/lib/format";
export function explorationFigure(input: ExplorationInput, filters: ExplorationFilters) {
  const result = exploreFacilities(input, filters);
  if (!result) throw new Error("Incomplete inventory");
  let y = 40;
  const parts: string[] = [];
  const text = (value: string, size = 16) => {
    // Wrap even unbroken long names to keep metadata inside the figure.
    const lines = value.match(/.{1,95}(?:\s|$)|.{1,95}/g) ?? [""];
    for (const line of lines) {
      parts.push(`<text x="30" y="${y}" font-size="${size}">${escapeXml(line.trim())}</text>`);
      y += 24;
    }
  };
  text("Facilities recorded by barangay", 24);
  text(input.name);
  text(`Types: ${filters.types.join("; ") || "All recorded types"}`);
  text(`Ownership: ${filters.ownership || "All ownership"}`);
  text(
    `${formatCount(result.facilities.length)} selected ${result.facilities.length === 1 ? "facility" : "facilities"}; barangays with a matching record: ${formatCount(result.present)} of ${formatCount(result.rows.length)}.`,
  );
  text(
    `Facilities mapped: ${formatCount(result.facilities.length - result.data.nFacilitiesNotPlaced)}; ${formatCount(result.missingGeometry)} without geometry; ${formatCount(result.unassigned)} without matched barangay.`,
  );
  text(
    "Circles represent barangays, not buildings. Complete extent; outline is simplified context.",
    14,
  );
  const top = y + 15;
  // Web Mercator, matching MapLibre's default projection. Padding retains full circles.
  const project = ([lon, lat]: [number, number]) => ({
    x: (lon * Math.PI) / 180,
    y: Math.log(Math.tan(Math.PI / 4 + (Math.max(-85, Math.min(85, lat)) * Math.PI) / 360)),
  });
  const outlinePositions = (input.outline ?? []).flatMap(geometryPositions).map(project);
  const projected = result.data.points.map((p) => ({
    p,
    x: (p.lon * Math.PI) / 180,
    y: Math.log(Math.tan(Math.PI / 4 + (Math.max(-85, Math.min(85, p.lat)) * Math.PI) / 360)),
  }));
  if (projected.length) {
    const extent = [...projected, ...outlinePositions];
    const minX = Math.min(...extent.map((p) => p.x)),
      maxX = Math.max(...extent.map((p) => p.x));
    const minY = Math.min(...extent.map((p) => p.y)),
      maxY = Math.max(...extent.map((p) => p.y));
    const scale = Math.min(
      880 / Math.max(maxX - minX, 0.00001),
      400 / Math.max(maxY - minY, 0.00001),
    );
    function rings(value: unknown): [number, number][][] {
      if (!Array.isArray(value) || !value.length) return [];
      if (
        value.every(
          (v) =>
            Array.isArray(v) && v.length >= 2 && Number.isFinite(v[0]) && Number.isFinite(v[1]),
        )
      )
        return [value as [number, number][]];
      return value.flatMap(rings);
    }
    for (const geometry of input.outline ?? []) {
      const coordinates =
        geometry && typeof geometry === "object" && "coordinates" in geometry
          ? geometry.coordinates
          : null;
      const d = rings(coordinates)
        .map(
          (ring) =>
            ring
              .map((position, i) => {
                const p = project(position);
                return `${i ? "L" : "M"}${500 + (p.x - (minX + maxX) / 2) * scale},${top + 220 - (p.y - (minY + maxY) / 2) * scale}`;
              })
              .join(" ") + "Z",
        )
        .join(" ");
      if (d) parts.push(`<path d="${d}" fill="#f6f7f8" stroke="#c8cfd3" fill-rule="evenodd"/>`);
    }
    for (const { p, x, y: py } of projected)
      parts.push(
        `<circle cx="${500 + (x - (minX + maxX) / 2) * scale}" cy="${top + 220 - (py - (minY + maxY) / 2) * scale}" r="${pointRadius(p.nFacilities, result.data.maxFacilities)}" fill="${p.nFacilities ? "#0a6e6e" : "none"}" fill-opacity="0.65" stroke="#57616a"/>`,
      );
    y = top + 470;
  } else {
    y = top + 35;
    text("Map geometry unavailable. See the complete barangay table.");
  }
  const legend = [
    ...new Set([0, 1, Math.round(result.data.maxFacilities / 2), result.data.maxFacilities]),
  ]
    .filter((n) => n <= result.data.maxFacilities)
    .sort((a, b) => a - b);
  legend.forEach((n, i) =>
    parts.push(
      `<circle cx="${50 + i * 230}" cy="${y}" r="${pointRadius(n, result.data.maxFacilities)}" fill="${n ? "#0a6e6e" : "none"}" stroke="#57616a"/><text x="${75 + i * 230}" y="${y + 5}" font-size="14">${n ? formatCount(n) + (n === 1 ? " facility" : " facilities") : "No facility recorded"}</text>`,
    ),
  );
  y += 45;
  text(
    "Sizes have a minimum visible radius. Presence does not establish access or service adequacy.",
    14,
  );
  text("Source: DOH NHFR, September 2026. Geometry: representative barangay points.", 14);
  text("Geometry source: faeldon/philippines-json-maps, 2023 PSGC series.", 14);
  text("Unmapped barangays remain in table downloads. No facility coordinates are provided.", 14);
  return {
    svg: `<svg xmlns="http://www.w3.org/2000/svg" width="1000" height="${y + 20}"><rect width="100%" height="100%" fill="white"/><g font-family="DejaVu Sans, sans-serif" fill="#1a1d1e">${parts.join("")}</g></svg>`,
    height: y + 20,
  };
}
