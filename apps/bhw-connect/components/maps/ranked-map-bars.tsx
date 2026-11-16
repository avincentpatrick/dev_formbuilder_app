"use client";
import { SelectableBarChart } from "@/components/charts/bar-chart-client";
import { valueFilterState, hasValueFilter, type CountRange } from "@/lib/maps/value-filter";
import { symbolMaximum } from "@/lib/maps/symbols";
import { rankedCounts } from "@/lib/maps/ranking";
import type { MapRow } from "@/lib/maps/prototype";

export function RankedMapBars({
  rows,
  range = {},
  area,
  label,
  unit,
  period,
  selected,
  onSelect,
}: {
  rows: MapRow[];
  range?: CountRange;
  area: string;
  label: string;
  unit: string;
  period: string;
  selected: string | null;
  onSelect: (code: string) => void;
}) {
  const { ranked: allRanked, excluded } = rankedCounts(rows);
  const ranked = allRanked.filter((row) => valueFilterState(row, range) === "Included");
  return (
    <section aria-label="Ranked area counts" className="space-y-2 rounded border border-border p-3">
      <h3 className="font-semibold">Compare area counts</h3>
      <p className="text-sm">
        {label} · {period} · {unit}
      </p>
      <p className="text-xs text-muted">
        {ranked.length} of {rows.length} child areas in {area}, largest count first. {excluded}{" "}
        without a usable count are unranked and remain in the selector and table. Areas without
        shapes stay eligible.{" "}
        {hasValueFilter(range) &&
          `${allRanked.length - ranked.length} counts excluded by range; scale uses all areas.`}{" "}
        Equal counts are tied, ordered by name then code. Search changes only the area selector.
      </p>
      <p className="text-xs text-muted">
        Bars start at zero and use a common scale within this view. Zero has no filled bar. Select a
        bar to update the map and area details. A low count alone does not show a shortage. The map
        PNG includes up to 20 included areas, largest count first, with omissions stated; JSON
        contains all area values.
      </p>
      {ranked.length ? (
        <div
          className="max-h-80 overflow-auto"
          role="region"
          aria-label="Scrollable area count bars"
          tabIndex={0}
        >
          <SelectableBarChart
            maximum={symbolMaximum(rows)}
            data={ranked.map((row) => ({
              label: `${row.name} (${row.code})`,
              value: row.value,
              geoCode: row.code,
            }))}
            selected={selected}
            onSelect={onSelect}
            label={`Area counts within ${area}`}
          />
        </div>
      ) : (
        <p role="status" className="text-sm">
          {hasValueFilter(range)
            ? "No counts within this range. The complete table remains available."
            : "No usable counts to rank. This does not mean zero."}
        </p>
      )}
    </section>
  );
}
