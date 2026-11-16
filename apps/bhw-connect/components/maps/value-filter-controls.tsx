"use client";
import { classificationBreaksText } from "@/lib/maps/classification";
import { useState } from "react";
import { parseCountRange, parsePercentageRange, type CountRange } from "@/lib/maps/value-filter";
export function ValueFilterControls({
  range,
  kind = "count",
  onApply,
}: {
  range: CountRange;
  kind?: "count" | "percentage";
  onApply: (range: CountRange) => void;
}) {
  const percentage = kind === "percentage";
  const [min, setMin] = useState(
    range.valueMin === undefined ? "" : classificationBreaksText([range.valueMin]),
  );
  const [max, setMax] = useState(
    range.valueMax === undefined ? "" : classificationBreaksText([range.valueMax]),
  );
  const [error, setError] = useState<string | null>(null);
  return (
    <details className="rounded border border-border p-3">
      <summary className="cursor-pointer font-medium text-sm">
        {percentage ? "Filter percentages" : "Filter workforce counts"}
      </summary>
      <form
        className="mt-2 space-y-2 text-sm"
        onSubmit={(event) => {
          event.preventDefault();
          const parsed = (percentage ? parsePercentageRange : parseCountRange)(
            min || null,
            max || null,
          );
          if (!parsed) {
            setError(
              percentage
                ? "Use percentages from 0 to 100, with minimum no greater than maximum."
                : "Use nonnegative whole counts, with minimum no greater than maximum.",
            );
            return;
          }
          setError(null);
          onApply(parsed);
        }}
      >
        <p>
          Include {percentage ? "percentages (0–100%)" : "counts"} within these limits, including
          both ends. Blank means no limit. This filters values, not classification. Changing measure
          or dataset resets the range.
        </p>
        <div className="grid grid-cols-2 gap-2">
          <label>
            {percentage ? "Minimum percentage (%)" : "Minimum count"}
            <input
              aria-label={percentage ? "Minimum percentage (%)" : "Minimum count"}
              inputMode={percentage ? "decimal" : "numeric"}
              value={min}
              onChange={(e) => setMin(e.target.value)}
              className="mt-1 w-full min-w-0 rounded border border-border bg-background p-2"
            />
          </label>
          <label>
            {percentage ? "Maximum percentage (%)" : "Maximum count"}
            <input
              aria-label={percentage ? "Maximum percentage (%)" : "Maximum count"}
              inputMode={percentage ? "decimal" : "numeric"}
              value={max}
              onChange={(e) => setMax(e.target.value)}
              className="mt-1 w-full min-w-0 rounded border border-border bg-background p-2"
            />
          </label>
        </div>
        {error && <p role="alert">{error}</p>}
        <div className="flex flex-wrap gap-3">
          <button type="submit" className="min-h-11 underline">
            Apply {kind} range
          </button>
          <button
            type="button"
            className="min-h-11 underline"
            onClick={() => {
              setMin("");
              setMax("");
              setError(null);
              onApply({});
            }}
          >
            Reset {kind} range
          </button>
        </div>
      </form>
    </details>
  );
}
