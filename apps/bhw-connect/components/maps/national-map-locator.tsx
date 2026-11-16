"use client";
import { useEffect, useId, useMemo, useState } from "react";
import { withBasePath } from "@/lib/base-path";
import type { BoundaryCollection, LocatorMap } from "@/lib/geo/locator-shapes";
import { nationalLocator } from "@/lib/maps/orientation";
import { LocatorMapGraphic } from "@/components/place/locator-map";

export function NationalMapLocator({
  geometry,
  selected,
  name,
  onLocator,
}: {
  geometry?: BoundaryCollection;
  selected: string | null;
  name: string;
  onLocator?: (locator: LocatorMap | null) => void;
}) {
  const id = useId();
  const [country, setCountry] = useState<BoundaryCollection | null>(null);
  const [failed, setFailed] = useState(false);
  const [attempt, setAttempt] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    fetch(withBasePath("/geo/regions.json"), { signal: controller.signal })
      .then(async (response) => {
        if (!response.ok) throw new Error("Country boundary unavailable");
        const collection = await response.json();
        if (
          collection.type !== "FeatureCollection" ||
          !Array.isArray(collection.features) ||
          collection.features.length === 0
        )
          throw new Error("Invalid country boundary");
        if (!controller.signal.aborted) {
          setCountry(collection);
          setFailed(false);
        }
      })
      .catch(() => {
        if (!controller.signal.aborted) setFailed(true);
      });
    return () => controller.abort();
  }, [attempt]);
  const locator = useMemo(
    () => (country && geometry ? nationalLocator(country, geometry, selected) : null),
    [country, geometry, selected],
  );
  const status = failed
    ? "failed"
    : !geometry
      ? "unavailable"
      : !country
        ? "loading"
        : locator
          ? "ready"
          : "unavailable";
  useEffect(() => {
    onLocator?.(status === "ready" ? locator : null);
  }, [locator, status, onLocator]);
  return (
    <figure
      aria-labelledby={id}
      data-locator-state={status}
      className="flex flex-wrap items-center gap-3 rounded border border-border p-2 text-xs"
    >
      {locator && !failed && <LocatorMapGraphic locator={locator} />}
      <figcaption id={id} className="min-w-0 flex-1 space-y-1">
        <p className="font-semibold">Location in Philippines</p>
        <p>{name}</p>
        {status === "ready" ? (
          <p>
            {selected
              ? "Highlighted supplied boundary."
              : "Highlighted available child boundaries, not a complete parent outline."}{" "}
            A ring marks shapes too small to see. This shows location, not another measure or worker
            addresses.
          </p>
        ) : (
          <p role="status">
            {status === "failed"
              ? "National locator could not load."
              : status === "loading"
                ? "Waiting for boundary location."
                : !geometry
                  ? "Map boundaries are not available for the locator. Retry map rendering if it failed."
                  : "No supplied shape for this location. No position is inferred."}{" "}
            The selector and table remain available.
          </p>
        )}
        {failed && (
          <button
            type="button"
            className="underline"
            onClick={() => {
              setFailed(false);
              setAttempt((value) => value + 1);
            }}
          >
            Retry national locator
          </button>
        )}
        <p className="text-muted">
          Historical boundary compatibility remains unverified. Available locators are included in
          the map PNG; unavailable locations are explicitly noted.
        </p>
      </figcaption>
    </figure>
  );
}
