"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { comparisonHref, placeLabel, type PlaceGroup } from "@/lib/bhw/comparison";

export function ComparisonQueryResolver({
  groups,
  measures,
}: {
  groups: PlaceGroup[];
  measures: { id: string; label: string }[];
}) {
  const router = useRouter();
  const [measure, setMeasure] = useState(measures.length === 1 ? measures[0].id : "");
  const [selected, setSelected] = useState<string[]>(groups.map(() => ""));
  const [included, setIncluded] = useState(groups.map(() => groups.length <= 4));
  const codes = selected.filter((_, i) => included[i]);
  const valid =
    !!measure &&
    codes.length >= 2 &&
    codes.length <= 4 &&
    codes.every(Boolean) &&
    new Set(codes).size === codes.length;
  return (
    <form
      className="space-y-5"
      onSubmit={(e) => {
        e.preventDefault();
        if (valid) router.push(comparisonHref(measure, codes));
      }}
    >
      <h2 className="text-xl font-semibold">Resolve comparison</h2>
      <p>
        Choose each place and the intended measure. LGU-reported headcount and validated profiles
        describe different populations.
      </p>
      {groups.length > 4 && (
        <p role="status">
          Comparisons support two to four places. Choose which requests to include below; none have
          been discarded.
        </p>
      )}
      <label className="block">
        Comparison measure
        <select
          className="mt-1 block w-full rounded border border-border bg-background p-3"
          value={measure}
          onChange={(e) => setMeasure(e.target.value)}
        >
          <option value="">Choose a measure</option>
          {measures.map((m) => (
            <option key={m.id} value={m.id}>
              {m.label}
            </option>
          ))}
        </select>
      </label>
      {groups.map((group, index) => (
        <fieldset key={index} className="space-y-2 rounded border border-border p-3">
          <legend className="px-1 font-medium">
            Place {index + 1}: {group.query}
          </legend>
          {groups.length > 4 && (
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={included[index]}
                onChange={(e) =>
                  setIncluded(included.map((v, i) => (i === index ? e.target.checked : v)))
                }
              />
              Include {group.query}
            </label>
          )}
          {group.state === "failed" ? (
            <p role="alert">Could not load matches. Submit the search again to retry.</p>
          ) : !group.matches.length ? (
            <p>No matching places. Refine this name in the search above.</p>
          ) : (
            <label className="block">
              Choose match for {group.query}
              <select
                className="mt-1 block w-full min-w-0 rounded border border-border bg-background p-3"
                value={selected[index]}
                onChange={(e) =>
                  setSelected(selected.map((v, i) => (i === index ? e.target.value : v)))
                }
              >
                <option value="">Choose a place</option>
                {group.matches.map((p) => (
                  <option key={p.geoCode} value={p.geoCode}>
                    {placeLabel(p)}
                  </option>
                ))}
              </select>
            </label>
          )}
        </fieldset>
      ))}
      <p className="text-sm text-muted">
        Select two to four distinct places at the same geographic level. Up to 12 matches per
        request are shown; refine names if needed.
      </p>
      <button
        disabled={!valid}
        className="rounded bg-accent px-4 py-3 text-accent-foreground disabled:opacity-50"
      >
        Open comparison
      </button>
    </form>
  );
}
