"use client";

import { useEffect, useRef, useState } from "react";
import type { BarDatum } from "@/lib/charts/bar-chart";
import { styleAxisTitles } from "@/lib/charts/style-axis";
import { usePaletteAccent } from "@/lib/charts/use-palette-accent";

export function BarChartClient({
  data,
  xLabel,
  yLabel,
  valueSuffix,
  valueFormat,
  width,
  barHeight,
  fill,
}: {
  data: BarDatum[];
  xLabel?: string;
  yLabel?: string;
  valueSuffix?: string;
  valueFormat?: (n: number) => string;
  /** Fixed plot width in px. Omit to fill the container's width instead —
   * used by the enlarged modal so the chart uses all available space rather
   * than a hardcoded value that leaves the rest of the modal empty. */
  width?: number;
  /** Px height per bar row, passed through to horizontalBarSpec. */
  barHeight?: number;
  /** Bar fill color (hex), set by the chart's recolor swatch control. */
  fill?: string;
}) {
  const containerRef = useRef<HTMLDivElement>(null);
  const [measuredWidth, setMeasuredWidth] = useState<number>();
  // Callers that don't recolor the bars fall back to the live palette accent (a
  // concrete hex Plot can use) so the chart tracks the appearance setting.
  const paletteAccent = usePaletteAccent();
  const resolvedFill = fill ?? paletteAccent;

  // Track the container's width so the chart can fill it exactly (no fixed
  // width left over as dead space) when the caller doesn't force one.
  useEffect(() => {
    if (width != null) return;
    const el = containerRef.current;
    if (!el) return;
    const observer = new ResizeObserver((entries) => {
      const w = entries[0]?.contentRect.width;
      if (w) setMeasuredWidth(Math.round(w));
    });
    observer.observe(el);
    return () => observer.disconnect();
  }, [width]);

  const plotWidth = width ?? measuredWidth;

  useEffect(() => {
    if (plotWidth == null) return;
    let plot: (SVGSVGElement | HTMLElement) & { remove: () => void };
    let cancelled = false;

    Promise.all([import("@observablehq/plot"), import("@/lib/charts/bar-chart")]).then(
      ([Plot, { horizontalBarSpec }]) => {
        if (cancelled || !containerRef.current) return;
        plot = Plot.plot(
          horizontalBarSpec(data, {
            xLabel,
            yLabel,
            valueSuffix,
            valueFormat,
            width: plotWidth,
            barHeight,
            fill: resolvedFill,
          }),
        );
        // Bold/enlarge the axis titles so they read clearly against the tick labels.
        styleAxisTitles(plot);
        // The wrapping div already carries role="img" + a full text aria-label
        // (below); Plot's internal <g aria-label="..."> marks on plain <g>
        // elements otherwise trip aria-prohibited-attr, so hide the SVG itself
        // from the accessibility tree rather than exposing it redundantly.
        plot.setAttribute("aria-hidden", "true");
        containerRef.current.replaceChildren(plot);
      },
    );

    return () => {
      cancelled = true;
      plot?.remove();
    };
  }, [data, xLabel, yLabel, valueSuffix, valueFormat, plotWidth, barHeight, resolvedFill]);

  const format = valueFormat ?? ((n: number) => n.toLocaleString());

  return (
    <div
      ref={containerRef}
      role="img"
      aria-label={`${xLabel ?? "Chart"}: ${data.map((d) => `${d.label} ${format(d.value)}${valueSuffix ?? ""}`).join(", ")}`}
      // text-foreground sets CSS `color`, which Plot's text (rendered with
      // fill: currentColor, see lib/charts/bar-chart.ts + style-axis.ts) inherits
      // — so axis/tick/value labels stay readable in dark mode instead of Plot's
      // default near-black fill.
      className="w-full overflow-x-auto text-foreground"
    />
  );
}

/** Selectable counterpart to the Plot chart: full names and native buttons work on touch/keyboard. */
export function SelectableBarChart({
  data,
  maximum: suppliedMaximum,
  selected,
  onSelect,
  label,
}: {
  data: BarDatum[];
  maximum?: number;
  selected: string | null;
  onSelect: (code: string) => void;
  label: string;
}) {
  const fill = usePaletteAccent();
  const maximum = suppliedMaximum ?? Math.max(0, ...data.map((row) => row.value));
  return (
    <ul aria-label={label} className="space-y-2">
      {data.map((row) => (
        <li key={row.geoCode}>
          <button
            type="button"
            aria-pressed={selected === row.geoCode}
            onClick={() => {
              if (row.geoCode) onSelect(row.geoCode);
            }}
            className="w-full rounded border border-border p-2 text-left text-sm hover:bg-surface focus-visible:outline-2 focus-visible:outline-accent"
          >
            <span className="flex flex-wrap justify-between gap-x-3">
              <span className="min-w-0 break-words">{row.label}</span>
              <span className="font-medium tabular-nums">{row.value.toLocaleString()}</span>
            </span>
            {selected === row.geoCode && <span className="block font-semibold">Selected</span>}
            <span aria-hidden="true" className="mt-1 block h-3 border-l border-border bg-surface">
              <span
                className="block h-full"
                style={{
                  backgroundColor: fill,
                  width: `${maximum > 0 ? (row.value / maximum) * 100 : 0}%`,
                }}
              />
            </span>
          </button>
        </li>
      ))}
    </ul>
  );
}
