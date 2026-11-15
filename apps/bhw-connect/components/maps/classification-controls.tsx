"use client";
import { useState } from "react";
import {
  parseCountClassification,
  classificationBreaksText,
  type CountClassification,
} from "@/lib/maps/classification";
export function ClassificationControls({
  settings,
  onApply,
}: {
  settings: CountClassification;
  onApply: (settings: CountClassification) => void;
}) {
  const [method, setMethod] = useState(settings.classification ?? "quantile");
  const [count, setCount] = useState(String(settings.classCount ?? 5));
  const [breaks, setBreaks] = useState(classificationBreaksText(settings.classBreaks ?? []));
  const [error, setError] = useState<string | null>(null);
  return (
    <details className="rounded border border-border p-3">
      <summary className="cursor-pointer text-sm font-medium">Color classification</summary>
      <form
        className="mt-2 space-y-2 text-sm"
        onSubmit={(event) => {
          event.preventDefault();
          const parsed = parseCountClassification(
            method,
            method === "custom" ? null : count,
            method === "custom"
              ? breaks
                  .split(",")
                  .map((part) => part.trim())
                  .join(",")
              : null,
          );
          if (!parsed) {
            setError(
              "Choose 2–7 groups, or 1–6 positive, finite, increasing cut points separated by commas.",
            );
            return;
          }
          setError(null);
          onApply(parsed);
        }}
      >
        <p>
          Color groups do not filter areas or change values. Automatic groups use all child areas.
          Changing measure or dataset resets classification.
        </p>
        <label className="block">
          Grouping method
          <select
            aria-label="Grouping method"
            value={method}
            onChange={(e) => setMethod(e.target.value as typeof method)}
            className="mt-1 w-full rounded border border-border bg-background p-2"
          >
            <option value="quantile">Quantiles — similar numbers of areas</option>
            <option value="equal">Equal-width count intervals</option>
            <option value="custom">Custom cut points</option>
          </select>
        </label>
        {method === "custom" ? (
          <label className="block">
            Custom cut points
            <input
              aria-label="Custom cut points"
              value={breaks}
              onChange={(e) => setBreaks(e.target.value)}
              placeholder="25, 50, 100"
              className="mt-1 w-full min-w-0 rounded border border-border bg-background p-2"
            />
            <span className="block text-xs text-muted">
              1–6 increasing positive numbers. First group starts at 0; last group has no upper
              limit. Exploratory, not official standards.
            </span>
          </label>
        ) : (
          <label className="block">
            Requested groups
            <select
              aria-label="Requested groups"
              value={count}
              onChange={(e) => setCount(e.target.value)}
              className="mt-1 w-full rounded border border-border bg-background p-2"
            >
              {[2, 3, 4, 5, 6, 7].map((n) => (
                <option key={n} value={n}>
                  {n}
                </option>
              ))}
            </select>
          </label>
        )}
        {error && <p role="alert">{error}</p>}
        <div className="flex flex-wrap gap-3">
          <button type="submit" className="min-h-11 underline">
            Apply classification
          </button>
          <button
            type="button"
            className="min-h-11 underline"
            onClick={() => {
              setMethod("quantile");
              setCount("5");
              setBreaks("");
              setError(null);
              onApply({});
            }}
          >
            Reset classification
          </button>
        </div>
      </form>
    </details>
  );
}
