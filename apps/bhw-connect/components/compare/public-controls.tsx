"use client";

import { useRef, useState, useTransition } from "react";
import { useRouter } from "next/navigation";
import { withBasePath } from "@/lib/base-path";
import { comparisonHref, placeLabel, type PlaceMatch } from "@/lib/bhw/comparison";
import { SelectedGeoChips, type SelectedGeo } from "./selected-geo-chips";

export function PublicCompareControls({
  places,
  measure,
  measures,
  focus,
}: {
  places: SelectedGeo[];
  measure: string;
  measures: { id: string; label: string }[];
  focus?: string;
}) {
  const router = useRouter();
  const [pending, startTransition] = useTransition();
  const [query, setQuery] = useState("");
  const [replace, setReplace] = useState("");
  const [hits, setHits] = useState<PlaceMatch[]>([]);
  const [status, setStatus] = useState<"idle" | "loading" | "done" | "failed">("idle");
  const requestId = useRef(0);
  const codes = places.map((p) => p.geoCode);
  const navigate = (next: string[], nextMeasure = measure) =>
    startTransition(() => router.push(comparisonHref(nextMeasure, next, focus)));
  return (
    <section aria-label="Comparison controls" className="space-y-4">
      <label className="block">
        Comparison measure
        <select
          disabled={pending}
          value={measure}
          onChange={(e) => navigate(codes, e.target.value)}
          className="mt-1 block w-full rounded border border-border bg-background p-3"
        >
          {measures.map((m) => (
            <option key={m.id} value={m.id}>
              {m.label}
            </option>
          ))}
        </select>
      </label>
      <SelectedGeoChips
        places={places}
        onRemove={(code) => navigate(codes.filter((c) => c !== code))}
        onClear={() => navigate([])}
      />
      {pending && <p role="status">Updating comparison…</p>}
      <form
        className="space-y-2"
        onSubmit={async (e) => {
          e.preventDefault();
          const id = ++requestId.current;
          setStatus("loading");
          setHits([]);
          try {
            const response = await fetch(
              withBasePath(`/api/bhw/places?q=${encodeURIComponent(query.trim())}`),
            );
            if (!response.ok) throw new Error("Search failed");
            const body = await response.json();
            if (id === requestId.current) {
              setHits(body.results);
              setStatus("done");
            }
          } catch {
            if (id === requestId.current) setStatus("failed");
          }
        }}
      >
        <label className="block">
          Add or replace a place
          <input
            type="search"
            maxLength={100}
            required
            value={query}
            onChange={(e) => {
              ++requestId.current;
              setQuery(e.target.value);
              setHits([]);
              setStatus("idle");
            }}
            className="mt-1 block w-full rounded border border-border bg-background p-3"
          />
        </label>
        <label className="block">
          Selection action
          <select
            value={replace}
            onChange={(e) => setReplace(e.target.value)}
            className="mt-1 block w-full rounded border border-border bg-background p-3"
          >
            <option value="">Add a place</option>
            {places.map((p) => (
              <option key={p.geoCode} value={p.geoCode}>
                Replace {p.geoName} ({p.geoCode})
              </option>
            ))}
          </select>
        </label>
        <button
          className="rounded bg-accent px-4 py-3 text-accent-foreground"
          disabled={pending || status === "loading"}
        >
          Find places
        </button>
      </form>
      {status === "loading" && <p role="status">Loading matching places…</p>}
      {status === "failed" && (
        <p role="alert">Place search failed. Try again; this does not mean no matches.</p>
      )}
      {status === "done" && !hits.length && (
        <p role="status">No matching places. Try another name or PSGC code.</p>
      )}
      {codes.length >= 4 && (
        <p role="status">
          At most four places can be compared. Remove a place or choose Replace to change the
          selection.
        </p>
      )}
      {hits.length > 0 && (
        <ul aria-label="Matching comparison places" className="space-y-2">
          {hits.map((p) => (
            <li key={p.geoCode}>
              <button
                disabled={pending || codes.includes(p.geoCode) || (!replace && codes.length >= 4)}
                className="w-full rounded border border-border p-3 text-left disabled:opacity-50"
                onClick={() => {
                  if (codes.includes(p.geoCode) || (!replace && codes.length >= 4)) return;
                  navigate(
                    replace
                      ? codes.map((c) => (c === replace ? p.geoCode : c))
                      : [...codes, p.geoCode],
                  );
                  setHits([]);
                  setQuery("");
                  setReplace("");
                  setStatus("idle");
                }}
              >
                {placeLabel(p)}
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
