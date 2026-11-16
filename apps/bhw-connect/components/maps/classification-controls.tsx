"use client";
import { useState } from "react";
import {
  parseCountClassification,
  parsePercentageClassification,
  classificationBreaksText,
  type CountClassification,
} from "@/lib/maps/classification";
export function ClassificationControls({
  settings,
  kind = "count",
  onApply,
}: {
  settings: CountClassification;
  kind?: "count" | "percentage" | "years";
  onApply: (settings: CountClassification) => void;
}) {
  const defaultMethod = kind === "percentage" ? "fixed" : "quantile";
  const [method, setMethod] = useState<string>(settings.classification ?? defaultMethod);
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
          const parsed = (
            kind === "percentage" ? parsePercentageClassification : parseCountClassification
          )(
            method === "fixed" ? null : method,
            method === "custom" || method === "fixed" ? null : count,
            method === "custom"
              ? breaks
                  .split(",")
                  .map((part) => part.trim())
                  .join(",")
              : null,
          );
          if (!parsed) {
            setError(
              kind === "percentage"
                ? "Choose 2–7 groups, or 1–6 increasing cut points strictly between 0 and 100, separated by commas."
                : "Choose 2–7 groups, or 1–6 positive, finite, increasing cut points separated by commas.",
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
          {kind === "years" &&
            " Cut points are in mean active years. Published means, denominators and the unavailable contributing count are unchanged."}
        </p>
        <label className="block">
          Grouping method
          <select
            aria-label="Grouping method"
            value={method}
            onChange={(e) => setMethod(e.target.value as typeof method)}
            className="mt-1 w-full rounded border border-border bg-background p-2"
          >
            {kind === "percentage" && (
              <option value="fixed">Fixed percentage breaks (default)</option>
            )}
            <option value="quantile">Quantiles — similar numbers of areas</option>
            <option value="equal">
              Equal-width{" "}
              {kind === "percentage" ? "percentage" : kind === "years" ? "year" : "count"} intervals
            </option>
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
              placeholder={
                kind === "percentage"
                  ? "25, 50, 75"
                  : kind === "years"
                    ? "5, 10, 20"
                    : "25, 50, 100"
              }
              className="mt-1 w-full min-w-0 rounded border border-border bg-background p-2"
            />
            <span className="block text-xs text-muted">
              {kind === "percentage"
                ? "1–6 increasing numbers strictly between 0 and 100. Groups cover 0–100%."
                : "1–6 increasing positive numbers. First group starts at 0; last group has no upper limit."}{" "}
              Exploratory, not official standards.
            </span>
          </label>
        ) : method !== "fixed" ? (
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
        ) : null}
        {error && <p role="alert">{error}</p>}
        <div className="flex flex-wrap gap-3">
          <button type="submit" className="min-h-11 underline">
            Apply classification
          </button>
          <button
            type="button"
            className="min-h-11 underline"
            onClick={() => {
              setMethod(defaultMethod);
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
