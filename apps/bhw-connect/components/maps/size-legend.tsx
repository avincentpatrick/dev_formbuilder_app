import { sizeLegend } from "@/lib/maps/symbols";
import type { MapRow } from "@/lib/maps/prototype";

export function SizeLegend({ rows, compact = false }: { rows: MapRow[]; compact?: boolean }) {
  const sizes = sizeLegend(rows);
  const explanation = (
    <>
      <p>
        Hollow marker: reported zero. No circle: unavailable count. Areas without shapes remain in
        the table.
      </p>
      <p className="text-muted">
        Markers sit at approximate boundary centers and represent area totals, not worker addresses.
        Sizes are relative to the largest count in this area list.
      </p>
    </>
  );
  return (
    <section
      aria-label="Circle size legend"
      className={
        compact
          ? "space-y-1 rounded border border-border p-1 text-sm"
          : "space-y-2 rounded border border-border p-3 text-sm"
      }
    >
      <p className={compact ? "text-xs font-medium" : "font-medium"}>
        Circle area represents BHW count
      </p>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
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
      {compact ? (
        <>
          <p className="text-xs">
            Area totals, not worker locations. Hollow: zero · No circle: unavailable.
          </p>
          <details className="text-xs">
            <summary className="cursor-pointer">How to read circles</summary>
            <div className="mt-2 space-y-2">{explanation}</div>
          </details>
        </>
      ) : (
        explanation
      )}
    </section>
  );
}
