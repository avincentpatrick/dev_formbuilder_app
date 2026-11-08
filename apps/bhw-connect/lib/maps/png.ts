import type { Map as LibreMap } from "maplibre-gl";
import type { datasetMapExport } from "./export";
import { NO_DATA_COLOR } from "@/lib/charts/color-scale";

type Context = ReturnType<typeof datasetMapExport>;

/** Copy synchronously during MapLibre's render event, before WebGL discards its drawing buffer. */
export function captureMapFrame(map: LibreMap): Promise<HTMLCanvasElement> {
  return new Promise((resolve, reject) => {
    const cleanup = () => {
      clearTimeout(timer);
      map.off("render", render);
      map.off("remove", removed);
      map.off("error", removed);
    };
    const removed = () => { cleanup(); reject(new Error("Map is no longer available")); };
    const render = () => {
      if (!map.loaded()) return;
      try {
        const source = map.getCanvas();
        if (!source.width || !source.height) throw new Error("Map has no image");
        const copy = document.createElement("canvas");
        // Bound export memory on high-DPI devices without changing the live map.
        copy.width = 1200;
        copy.height = Math.max(1, Math.round(1200 * source.height / source.width));
        if (copy.height > 4096) throw new Error("Map viewport is too tall to export");
        const context = copy.getContext("2d");
        if (!context) throw new Error("Image export unavailable");
        context.drawImage(source, 0, 0, copy.width, copy.height);
        cleanup(); resolve(copy);
      } catch (error) { cleanup(); reject(error); }
    };
    const timer = setTimeout(() => { cleanup(); reject(new Error("Map did not finish rendering")); }, 5000);
    map.on("render", render);
    map.on("remove", removed);
    map.on("error", removed);
    map.triggerRepaint();
  });
}

export function pngNotes(data: Context): string[] {
  const selected = data.rows.find((row) => row.code === data.selection.selected);
  const missing = data.rows.filter((row) => row.geometry === "missing").length;
  return [
    `${data.dataset.title} — ${data.indicator.label}`,
    `${data.indicator.period} | ${data.indicator.unit} | ${data.geography.area}`,
    `Current viewport. Selected: ${selected?.name ?? "none"}.`,
    `Legend: ${data.legend.method}. Gray: unavailable value or navigation-only geography; not zero.`,
    `Coverage: ${data.rows.length} areas in complete table; ${missing} without drawable geometry. The image shows the current viewport only.`,
    `Reported parent total: ${data.total ?? "not available / not applicable"}. ${data.totalNote}`,
    ...(data.indicator.denominator ? [`Denominator: ${data.indicator.denominator}`] : []),
    ...(selected ? [`Selected value: ${selected.value ?? "not available"}${selected.label ? ` (${selected.label})` : ""}. ${selected.note ?? ""}`] : []),
    ...(selected && data.indicator.confidenceLevel ? [`Published 90% confidence interval: ${selected.ciLow ?? "unavailable"} to ${selected.ciHigh ?? "unavailable"}.`] : []),
    `Source: ${data.source.title}. Source date: ${data.source.date ?? "not supplied"}. License: ${data.source.license ?? "not supplied"}.`,
    `Attribution: ${data.source.attribution}`,
    "Boundaries: faeldon/philippines-json-maps, 2023 PSGC series (adapted).",
    `Boundary acceptance: ${data.geography.boundaryVersion ?? "not yet assigned for this dataset"}. ${data.geography.note}`,
    `Disclosure: ${data.disclosure}`,
    "Download the JSON data for the complete table, uncertainty and area-specific source notes.",
  ];
}

/** Compose the real map image with readable metadata. No DOM screenshot or external fonts. */
export async function composeMapPng(frame: HTMLCanvasElement, data: Context): Promise<Blob> {
  const canvas = document.createElement("canvas");
  const context = canvas.getContext("2d");
  if (!context) throw new Error("Image export unavailable");
  const width = frame.width;
  const margin = 24;
  const lineHeight = 25;
  context.font = "18px sans-serif";
  const wrap = (text: string) => {
    const lines: string[] = [];
    let line = "";
    // Prefer whole words; split only an individual token too wide for the image.
    for (const word of text.split(/\s+/)) {
      const next = line ? `${line} ${word}` : word;
      if (context.measureText(next).width <= width - 2 * margin) { line = next; continue; }
      if (line) { lines.push(line); line = ""; }
      for (const character of word) {
        if (context.measureText(line + character).width > width - 2 * margin) {
          lines.push(line); line = "";
        }
        line += character;
      }
    }
    if (line) lines.push(line);
    return lines;
  };
  const notes = pngNotes(data);
  const heading = notes.slice(0, 2).flatMap(wrap);
  const footer = notes.slice(2).flatMap(wrap);
  const legend = data.legend.bins.map((bin) => ({ color: bin.color,
    text: "label" in bin && bin.label ? bin.label : `${bin.min}–${bin.openEnded ? "and above" : bin.max}` }));
  const headerHeight = margin * 2 + heading.length * lineHeight;
  canvas.width = width;
  canvas.height = headerHeight + frame.height + margin * 2 + (footer.length + legend.length + 1) * lineHeight;
  if (canvas.height > 16384) throw new Error("Export metadata exceeds image size limit; use JSON data");
  context.fillStyle = "white"; context.fillRect(0, 0, canvas.width, canvas.height);
  context.font = "18px sans-serif"; context.textBaseline = "top";
  context.fillStyle = "#111827";
  heading.forEach((line, i) => context.fillText(line, margin, margin + i * lineHeight));
  context.drawImage(frame, 0, headerHeight);
  let y = headerHeight + frame.height + margin;
  for (const item of [...legend, { color: NO_DATA_COLOR, text: "Unavailable value / navigation only" }]) {
    context.fillStyle = item.color; context.fillRect(margin, y, 18, 18);
    context.fillStyle = "#111827"; context.fillText(item.text, margin + 28, y); y += lineHeight;
  }
  footer.forEach((line) => { context.fillText(line, margin, y); y += lineHeight; });
  return new Promise((resolve, reject) => canvas.toBlob(
    (blob) => blob ? resolve(blob) : reject(new Error("PNG encoding failed")), "image/png",
  ));
}
