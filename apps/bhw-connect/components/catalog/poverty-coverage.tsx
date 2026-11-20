"use client";
import { useEffect, useState } from "react";
import Link from "next/link";
import type { CatalogPlace } from "@/lib/catalog/geography";
import { catalogJourneyHref } from "@/lib/catalog/journey";
import { povertyCoverage } from "@/lib/catalog/poverty-coverage";
import { povertyMapChoice } from "@/lib/catalog/poverty-navigation";
import { mapHref } from "@/lib/maps/prototype";
import { readMapResponse } from "@/lib/maps/read-map-response";
import { withBasePath } from "@/lib/base-path";

export function PovertyCoverage({
  places,
  mapState,
}: {
  places: CatalogPlace[];
  mapState?: string;
}) {
  const [results, setResults] = useState<Record<string, string>>({});
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    const abort = new AbortController();
    const provinces = places.filter(
      (place) => place.level === "province" && place.code !== "13806",
    );
    let next = 0;
    // Bounded requests, only within the selected region. Reuse accepted map joins/publication rules.
    void Promise.all(
      Array.from({ length: Math.min(3, provinces.length) }, async () => {
        while (next < provinces.length && !abort.signal.aborted) {
          const place = provinces[next++];
          let label: string;
          try {
            const query = mapHref(povertyMapChoice(place.code)).split("?")[1];
            const response = await readMapResponse(
              withBasePath(`/api/dataset-map?${query}`),
              abort.signal,
            );
            const data = await response.json();
            if (!response.ok && data.state !== "unsupported")
              throw new Error("Coverage request failed");
            label = povertyCoverage(data);
          } catch {
            label = povertyCoverage({ state: "failed" });
          }
          if (!abort.signal.aborted) setResults((current) => ({ ...current, [place.code]: label }));
        }
      }),
    );
    return () => abort.abort();
  }, [places, retry]);
  const national = places.length > 0 && places.every((place) => place.level === "region");
  const ListContainer = national ? "details" : "div";
  return (
    <section
      className="space-y-3 rounded-xl border border-border p-4"
      aria-label="Find covered poverty areas"
      data-testid="poverty-coverage"
    >
      <h2 className="text-lg font-semibold">Find covered areas</h2>
      <p className="text-sm">
        Choose an area to see its official estimate, if loaded, and its municipal SAE list. Coverage
        below counts usable municipal records, not people, land area or provincial poverty rates.
        Zero estimates count as available.
      </p>
      <ListContainer>
        {national && (
          <summary className="cursor-pointer font-medium">
            Browse regions and municipal coverage
          </summary>
        )}
        <ul className="mt-3 space-y-3">
          {places.map((place) => (
            <li key={place.code}>
              <Link
                prefetch={false}
                className="underline"
                href={catalogJourneyHref(
                  { mode: "psgc", level: place.level, code: place.code },
                  "poverty",
                  mapState,
                )}
              >
                {place.name}
              </Link>
              <p className="text-sm text-muted" role="status">
                {place.level === "region"
                  ? "Open this region to check provincial municipal coverage"
                  : place.code === "13806"
                    ? "Complete municipal comparison unavailable: Manila reporting geography mismatch"
                    : place.level !== "province"
                      ? "Open this place to check its accepted source records"
                      : (results[place.code] ?? "Checking coverage…")}
              </p>
            </li>
          ))}
        </ul>
      </ListContainer>
      {Object.values(results).some((text) => /could not/.test(text)) && (
        <button
          className="underline"
          type="button"
          onClick={() => {
            setResults({});
            setRetry((n) => n + 1);
          }}
        >
          Retry coverage checks
        </button>
      )}
      <p className="text-xs text-muted">
        Links follow the app’s reporting hierarchy. Source exclusions and historical boundaries
        still apply, including BARMM and the Special Geographic Areas. An unavailable record is not
        zero.
      </p>
    </section>
  );
}
