"use client";
import { SelectableBarChart } from "@/components/charts/bar-chart-client";
import { rankedCounts } from "@/lib/maps/ranking";
import type { MapRow } from "@/lib/maps/prototype";

export function RankedMapBars({
  rows,
  area,
  label,
  unit,
  period,
  selected,
  onSelect,
}: {
  rows: MapRow[];
  area: string;
  label: string;
  unit: string;
  period: string;
  selected: string | null;
  onSelect: (code: string) => void;
}) {
  const { ranked, excluded } = rankedCounts(rows);
  return (
    <section aria-label="Ranked area counts" className="space-y-2 rounded border border-border p-3">
      <h3 className="font-semibold">Compare area counts</h3>
      <p className="text-sm">
        {label} · {period} · {unit}
      </p>
      <p className="text-xs text-muted">
        {ranked.length} of {rows.length} child areas in {area}, largest count first. {excluded}{" "}
        without a usable count are unranked and remain in the selector and table. Areas without
        shapes stay included. Equal counts are tied, ordered by name then code. Search changes only
        the area selector.
      </p>
      <p className="text-xs text-muted">
        Bars start at zero and use a common scale within this view. Zero has no filled bar. Select a
        bar to update the map and area details. A low count alone does not show a shortage. Bars are
        not included in the map PNG; JSON contains the complete area values.
      </p>
      {ranked.length ? (
        <div
          className="max-h-80 overflow-auto"
          role="region"
          aria-label="Scrollable area count bars"
          tabIndex={0}
        >
          <SelectableBarChart
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
          No usable counts to rank. This does not mean zero.
        </p>
      )}
    </section>
  );
}
