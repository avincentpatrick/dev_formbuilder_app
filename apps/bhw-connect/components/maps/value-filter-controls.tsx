"use client";
import { useState } from "react";
import { parseCountRange, type CountRange } from "@/lib/maps/value-filter";
export function ValueFilterControls({
  range,
  onApply,
}: {
  range: CountRange;
  onApply: (range: CountRange) => void;
}) {
  const [min, setMin] = useState(range.valueMin?.toString() ?? "");
  const [max, setMax] = useState(range.valueMax?.toString() ?? "");
  const [error, setError] = useState<string | null>(null);
  return (
    <details className="rounded border border-border p-3">
      <summary className="cursor-pointer font-medium text-sm">Filter workforce counts</summary>
      <form
        className="mt-2 space-y-2 text-sm"
        onSubmit={(event) => {
          event.preventDefault();
          const parsed = parseCountRange(min || null, max || null);
          if (!parsed) {
            setError("Use nonnegative whole counts, with minimum no greater than maximum.");
            return;
          }
          setError(null);
          onApply(parsed);
        }}
      >
        <p>
          Include counts within these limits, including both ends. Blank means no limit. This
          filters values, not classification. Changing measure or dataset resets the range.
        </p>
        <div className="grid grid-cols-2 gap-2">
          <label>
            Minimum count
            <input
              aria-label="Minimum count"
              inputMode="numeric"
              value={min}
              onChange={(e) => setMin(e.target.value)}
              className="mt-1 w-full min-w-0 rounded border border-border bg-background p-2"
            />
          </label>
          <label>
            Maximum count
            <input
              aria-label="Maximum count"
              inputMode="numeric"
              value={max}
              onChange={(e) => setMax(e.target.value)}
              className="mt-1 w-full min-w-0 rounded border border-border bg-background p-2"
            />
          </label>
        </div>
        {error && <p role="alert">{error}</p>}
        <div className="flex flex-wrap gap-3">
          <button type="submit" className="min-h-11 underline">
            Apply count range
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
            Reset count range
          </button>
        </div>
      </form>
    </details>
  );
}
