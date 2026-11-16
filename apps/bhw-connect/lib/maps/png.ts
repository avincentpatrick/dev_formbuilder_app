import { MIN_LEADER_N } from "@/lib/analysis/thresholds";
import type { Map as LibreMap } from "maplibre-gl";
import type { datasetMapExport } from "./export";
import { NO_DATA_COLOR } from "@/lib/charts/color-scale";

type Context = ReturnType<typeof datasetMapExport>;
export type MapFrame = HTMLCanvasElement & {
  cssPixelScale?: number;
  scaleBar?: { label: string; pixels: number };
};

/** Reuse the metric MapLibre control; do not introduce a second distance formula. */
export function captureMetricScale(container: HTMLElement, cssPixelScale: number) {
  const control = container.querySelector<HTMLElement>(".maplibregl-ctrl-scale");
  const label = control?.textContent?.trim().replace(/\s+/g, " ");
  const width = control?.getBoundingClientRect().width ?? 0;
  const match = label?.match(/^(\d+(?:\.\d+)?) (m|km)$/);
  const pixels = width * cssPixelScale;
  if (!match || Number(match[1]) <= 0 || !Number.isFinite(pixels) || pixels <= 0 || pixels > 1152)
    return undefined;
  return { label: label!, pixels };
}

/** Copy synchronously during MapLibre's render event, before WebGL discards its drawing buffer. */
export function captureMapFrame(map: LibreMap): Promise<MapFrame> {
  return new Promise((resolve, reject) => {
    const cleanup = () => {
      clearTimeout(timer);
      map.off("render", render);
      map.off("remove", removed);
      map.off("error", removed);
    };
    const removed = () => {
      cleanup();
      reject(new Error("Map is no longer available"));
    };
    const render = () => {
      if (!map.loaded()) return;
      try {
        const source = map.getCanvas();
        if (!source.width || !source.height) throw new Error("Map has no image");
        const copy: MapFrame = document.createElement("canvas");
        // Bound export memory on high-DPI devices without changing the live map.
        copy.width = 1200;
        // MapLibre radii are CSS pixels, while the WebGL buffer may be high-DPI.
        copy.cssPixelScale = copy.width / (source.clientWidth || source.width);
        copy.scaleBar = captureMetricScale(map.getContainer(), copy.cssPixelScale);
        copy.height = Math.max(1, Math.round((1200 * source.height) / source.width));
        if (copy.height > 4096) throw new Error("Map viewport is too tall to export");
        const context = copy.getContext("2d");
        if (!context) throw new Error("Image export unavailable");
        context.drawImage(source, 0, 0, copy.width, copy.height);
        cleanup();
        resolve(copy);
      } catch (error) {
        cleanup();
        reject(error);
      }
    };
    const timer = setTimeout(() => {
      cleanup();
      reject(new Error("Map did not finish rendering"));
    }, 5000);
    map.on("render", render);
    map.on("remove", removed);
    map.on("error", removed);
    map.triggerRepaint();
  });
}

export function pngNotes(data: Context, hasScaleBar = false): string[] {
  const selected = data.rows.find((row) => row.code === data.selection.selected);
  const missing = data.rows.filter((row) => row.geometry === "missing").length;
  const unchecked = data.rows.filter((row) => row.geometry === "not-checked").length;
  const selectedValue =
    selected?.value == null
      ? "not available"
      : `${selected.value}${data.indicator.unit === "%" ? "%" : ` ${data.indicator.unit}`}`;
  return [
    `${data.dataset.title} — ${data.indicator.label}`,
    `${data.indicator.period} | ${data.indicator.unit} | ${data.geography.area}`,
    ...(data.classification
      ? [`Color settings (choropleth only): ${data.classification.note}`]
      : []),
    `Current viewport. Selected: ${selected?.name ?? "none"}.`,
    ...(data.valueFilter
      ? [
          `Count range: ${data.valueFilter.minimum ?? "no minimum"} to ${data.valueFilter.maximum ?? "no maximum"}, inclusive. ${data.valueFilter.included} included; ${data.valueFilter.excluded} excluded; ${data.valueFilter.unavailable} unavailable. ${data.valueFilter.note} Selected area: ${selected?.filterState ?? "none"}.`,
        ]
      : []),
    ...(data.dataset.slug === "bhw-stepzero-2026" || data.dataset.slug === "bhw-2025"
      ? [
          "Place labels use approximate boundary centers and avoid overlaps; some names may be omitted at this zoom. The national locator is not included in this image.",
          hasScaleBar
            ? "Metric scale is approximate near the map center at capture time; map scale varies with location."
            : "Metric scale is unavailable for this capture; no distance bar is inferred.",
        ]
      : []),
    `Legend: ${data.legend.method}. ${data.legend.symbols ? "Neutral shapes provide geographic context; counts use circles." : "Gray: unavailable value or navigation-only geography; not zero."}`,
    ...(data.legend.symbols
      ? [
          data.legend.symbols.note,
          `Circle size reference counts: ${data.legend.symbols.sizes.map((s) => s.value.toLocaleString("en-PH")).join(", ") || "no positive counts"}; largest count ${data.legend.symbols.maximum.toLocaleString("en-PH")}.`,
        ]
      : []),
    ...(data.rows.some((row) => "smallSample" in row && row.smallSample)
      ? [
          `Faded fills and dashed outlines: few BHWs profiled — unstable (fewer than ${MIN_LEADER_N} profiles).`,
        ]
      : []),
    `Coverage: ${data.rows.length} areas in complete table; ${missing} without drawable geometry; ${unchecked} with geometry not checked. The image shows the current viewport only.`,
    `Reported parent ${data.parentMeasure.kind}: ${data.parentMeasure.value ?? "not available / not applicable"}${data.parentMeasure.kind === "rate" ? "%" : data.parentMeasure.kind === "mean" ? ` ${data.parentMeasure.unit}` : ""}. ${data.totalNote}`,
    ...(data.indicator.denominator ? [`Denominator: ${data.indicator.denominator}`] : []),
    ...(selected
      ? [
          `Selected value: ${selectedValue}${selected.label ? ` (${selected.label})` : ""}. ${selected.note ?? ""}`,
        ]
      : []),
    ...(selected && data.indicator.confidenceLevel
      ? [
          `Published ${data.indicator.confidenceLevel! * 100}% confidence interval: ${selected.ciLow ?? "unavailable"} to ${selected.ciHigh ?? "unavailable"}.`,
        ]
      : []),
    `Source: ${data.source.title}. Source date: ${data.source.date ?? "not supplied"}. License: ${data.source.license ?? "not supplied"}.`,
    `Attribution: ${data.source.attribution}`,
    "Boundaries: faeldon/philippines-json-maps, 2023 PSGC series (adapted).",
    `Boundary acceptance: ${data.geography.boundaryVersion ?? "not yet assigned for this dataset"}. ${data.geography.note}`,
    `Disclosure: ${data.disclosure}`,
    "Download the JSON data for the complete table, uncertainty and area-specific source notes.",
  ];
}

/** Compose the real map image with readable metadata. No DOM screenshot or external fonts. */
export async function composeMapPng(frame: MapFrame, data: Context): Promise<Blob> {
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
      if (context.measureText(next).width <= width - 2 * margin) {
        line = next;
        continue;
      }
      if (line) {
        lines.push(line);
        line = "";
      }
      for (const character of word) {
        if (context.measureText(line + character).width > width - 2 * margin) {
          lines.push(line);
          line = "";
        }
        line += character;
      }
    }
    if (line) lines.push(line);
    return lines;
  };
  const notes = pngNotes(data, Boolean(frame.scaleBar));
  const heading = notes.slice(0, 2).flatMap(wrap);
  const footer = notes.slice(2).flatMap(wrap);
  const legend = data.legend.bins.map((bin) => ({
    color: bin.color,
    text:
      "label" in bin && bin.label
        ? bin.label
        : `${bin.min}–${bin.openEnded ? "and above" : bin.max}`,
  }));
  const scale = frame.cssPixelScale ?? 1;
  if (!Number.isFinite(scale) || scale <= 0) throw new Error("Invalid map image scale");
  const symbols = data.legend.symbols;
  const symbolRows = symbols
    ? [
        ...symbols.sizes.map(({ value, radius }) => ({
          radius: radius * scale,
          label: `${value.toLocaleString("en-PH")} ${data.indicator.unit}`,
          zero: false,
        })),
        { radius: 3 * scale, label: "0 — reported zero (hollow marker)", zero: true },
      ]
    : [];
  const symbolHeight = symbols
    ? lineHeight +
      (symbols.sizes.length ? 0 : lineHeight) +
      symbolRows.reduce((sum, row) => sum + Math.max(lineHeight, Math.ceil(row.radius * 2 + 16)), 0)
    : 0;
  const maxRadius = Math.max(0, ...symbolRows.map((row) => row.radius));
  const headerHeight = margin * 2 + heading.length * lineHeight;
  canvas.width = width;
  canvas.height =
    headerHeight +
    frame.height +
    symbolHeight +
    (frame.scaleBar ? 75 : 0) +
    margin * 2 +
    (footer.length + legend.length + 1) * lineHeight;
  if (canvas.height > 16384)
    throw new Error("Export metadata exceeds image size limit; use JSON data");
  context.fillStyle = "white";
  context.fillRect(0, 0, canvas.width, canvas.height);
  context.font = "18px sans-serif";
  context.textBaseline = "top";
  context.fillStyle = "#111827";
  heading.forEach((line, i) => context.fillText(line, margin, margin + i * lineHeight));
  context.drawImage(frame, 0, headerHeight);
  let y = headerHeight + frame.height + margin;
  if (frame.scaleBar) {
    context.fillText(`Scale near map center: ${frame.scaleBar.label}`, margin, y);
    context.fillRect(margin, y + 35, frame.scaleBar.pixels, 2);
    context.fillRect(margin, y + 29, 2, 8);
    context.fillRect(margin + frame.scaleBar.pixels - 2, y + 29, 2, 8);
    y += 75;
  }
  if (symbols) {
    context.fillText("Circle area represents BHW count", margin, y);
    y += lineHeight;
    if (!symbols.sizes.length) {
      context.fillText("No positive counts are available for size comparison.", margin, y);
      y += lineHeight;
    }
    for (const row of symbolRows) {
      const height = Math.max(lineHeight, Math.ceil(row.radius * 2 + 16));
      context.beginPath();
      context.arc(margin + maxRadius + 4, y + height / 2, row.radius, 0, Math.PI * 2);
      if (!row.zero) {
        context.fillStyle = "rgba(12, 116, 123, 0.85)";
        context.fill();
      }
      context.strokeStyle = row.zero ? "#0c747b" : "white";
      context.lineWidth = (row.zero ? 2 : 1) * scale;
      context.stroke();
      context.fillStyle = "#111827";
      context.fillText(row.label, margin + maxRadius * 2 + 24, y + (height - lineHeight) / 2);
      y += height;
    }
  }
  for (const item of [
    ...legend,
    {
      color: NO_DATA_COLOR,
      text: data.legend.symbols
        ? "Geographic context; counts use circles, hollow markers mean zero"
        : "Unavailable value / navigation only",
    },
  ]) {
    context.fillStyle = item.color;
    context.fillRect(margin, y, 18, 18);
    context.fillStyle = "#111827";
    context.fillText(item.text, margin + 28, y);
    y += lineHeight;
  }
  footer.forEach((line) => {
    context.fillText(line, margin, y);
    y += lineHeight;
  });
  return new Promise((resolve, reject) =>
    canvas.toBlob(
      (blob) => (blob ? resolve(blob) : reject(new Error("PNG encoding failed"))),
      "image/png",
    ),
  );
}
