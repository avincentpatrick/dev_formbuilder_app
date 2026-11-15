import { sizeLegend } from "@/lib/maps/symbols";
import type { MapRow } from "@/lib/maps/prototype";

export function SizeLegend({ rows }: { rows: MapRow[] }) {
  const sizes = sizeLegend(rows);
  return (
    <section
      aria-label="Circle size legend"
      className="space-y-2 rounded border border-border p-3 text-sm"
    >
      <p className="font-medium">Circle area represents BHW count</p>
      <div className="flex flex-wrap items-end gap-5">
        {sizes.map(({ value, radius }) => (
          <div key={value} className="text-center">
            <svg width={50} height={50} aria-hidden="true">
              <circle cx={25} cy={25} r={radius} fill="#0c747b" stroke="white" />
            </svg>
            <span>{value.toLocaleString("en-PH")} people</span>
          </div>
        ))}
      </div>
      {!sizes.length && <p>No positive counts are available for size comparison.</p>}
      <p>
        Hollow marker: reported zero. No circle: unavailable count. Areas without shapes remain in
        the table.
      </p>
      <p className="text-muted">
        Markers sit at approximate boundary centers and represent area totals, not worker addresses.
        Sizes are relative to the largest count in this area list.
      </p>
    </section>
  );
}
