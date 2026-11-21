"use client";

import { useEffect, useId, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { withBasePath } from "@/lib/base-path";
import { percentageFormatter } from "@/lib/format";
import { catalogJourneyHref } from "@/lib/catalog/journey";
import { povertyMapChoice, povertySearchSuffix } from "@/lib/catalog/poverty-navigation";
import { mapHref, type MapPayload } from "@/lib/maps/prototype";
import { readMapResponse } from "@/lib/maps/read-map-response";
import { FigureCard } from "@/components/narrative/figure-card";
import {
  PovertyIntervalChart,
  povertyChartDomain,
  povertyInterval,
} from "./poverty-interval-chart";
import { POVERTY_UNCERTAINTY } from "@/lib/catalog/poverty-reading";

type Result =
  MapPayload | { state: "loading" | "failed" | "unavailable" | "suppressed" | "unsupported" };

/** Existing map API owns acceptance, joins and publication; this view never reads raw records. */
export function PovertyMunicipalities({
  province,
  name,
  selected,
  mapState,
}: {
  province: string;
  name: string;
  selected: string | null;
  mapState?: string;
}) {
  const params = useSearchParams();
  const search =
    params.get("povertyProvince") === province
      ? (params.get("povertySearch") ?? "").slice(0, 100)
      : "";
  const statusId = useId();
  const [view, setView] = useState<"chart" | "table">("chart");
  const [retry, setRetry] = useState(0);
  const [result, setResult] = useState<{ province: string; value: Result } | null>(null);
  const data: Result = result?.province === province ? result.value : { state: "loading" };
  useEffect(() => {
    if (province === "13806") return;
    const abort = new AbortController();
    const query = mapHref(povertyMapChoice(province)).split("?")[1];
    readMapResponse(withBasePath(`/api/dataset-map?${query}`), abort.signal)
      .then(async (response) => {
        const value = (await response.json()) as Result;
        if (!response.ok && value.state !== "unsupported") throw new Error("Request failed");
        if (
          value.state === "available" &&
          (value.childLevel !== "citymun" ||
            value.valueSupport !== "supported" ||
            !Array.isArray(value.rows))
        )
          throw new Error("Unsupported rows");
        if (!abort.signal.aborted) setResult({ province, value });
      })
      .catch(() => {
        if (!abort.signal.aborted) setResult({ province, value: { state: "failed" } });
      });
    return () => abort.abort();
  }, [province, retry]);
  if (province === "13806")
    return (
      <p role="status">
        A complete municipal comparison is unavailable for Manila: the source districts and
        reporting geography do not match. Partial district records are not a complete Manila
        estimate.
      </p>
    );
  const rows =
    data.state === "available"
      ? [...data.rows].sort(
          (a, b) =>
            a.name.localeCompare(b.name, "en", { numeric: true }) || a.code.localeCompare(b.code),
        )
      : [];
  const domain = povertyChartDomain(rows);
  const filtered = rows.filter((row) =>
    `${row.name} ${row.code}`.toLocaleLowerCase().includes(search.trim().toLocaleLowerCase()),
  );
  const format = percentageFormatter(
    rows.flatMap((row) =>
      row.value === null
        ? []
        : [
            row.value,
            ...(typeof row.ciLow === "number" && typeof row.ciHigh === "number"
              ? [row.ciLow, row.ciHigh]
              : []),
          ],
    ),
  );
  function changeSearch(value: string) {
    const query = new URLSearchParams(window.location.search);
    if (value) {
      query.set("povertySearch", value.slice(0, 100));
      query.set("povertyProvince", province);
    } else {
      query.delete("povertySearch");
      query.delete("povertyProvince");
    }
    window.history.replaceState(null, "", withBasePath(`/catalog?${query}`));
  }
  function detailHref(code: string) {
    const href = catalogJourneyHref({ mode: "psgc", level: "citymun", code }, "poverty", mapState);
    return search
      ? `${href}&${new URLSearchParams({ povertySearch: search, povertyProvince: province })}`
      : href;
  }
  return (
    <FigureCard
      title={`Cities and municipalities in ${name}`}
      caption="PSA Small Area Estimates · 2023 · people"
      headline="Compare estimates alongside their uncertainty. Places are listed alphabetically, not ranked by need."
    >
      <div className="space-y-4" data-testid="poverty-municipalities">
        <p className="text-sm">
          Dots show estimates; lines show published 90% confidence intervals. All places use the
          same scale. A wider interval means less precision.
        </p>
        <Link
          href={mapHref(povertyMapChoice(province, selected)) + povertySearchSuffix(params)}
          className="inline-block underline"
        >
          Explore this province on the Poverty map
        </Link>
        {data.state === "available" ? (
          <>
            <label className="block">
              Find a city or municipality
              <input
                type="search"
                maxLength={100}
                value={search}
                onChange={(event) => changeSearch(event.target.value)}
                aria-describedby={statusId}
                className="mt-1 block w-full rounded border border-border bg-background p-2"
              />
            </label>
            {search && (
              <button type="button" className="underline" onClick={() => changeSearch("")}>
                Clear municipality search
              </button>
            )}
            <p id={statusId} role="status" className="text-sm">
              {rows.filter((row) => row.value !== null).length} of {rows.length} places have usable
              estimates. {filtered.length} of {rows.length} places shown. Search filters this list
              only. Unavailable estimates remain listed.
            </p>
            <div role="group" aria-label="Municipal comparison view" className="flex gap-2">
              {(["chart", "table"] as const).map((choice) => (
                <button
                  key={choice}
                  type="button"
                  aria-pressed={view === choice}
                  onClick={() => setView(choice)}
                  className={`rounded border px-4 py-2 text-sm font-medium focus-visible:outline-2 focus-visible:outline-offset-2 ${view === choice ? "border-accent bg-accent-subtle text-accent" : "border-border"}`}
                >
                  {choice === "chart" ? "Chart" : "Table"}
                </button>
              ))}
            </div>
            {view === "chart" ? (
              <ul
                aria-label="Municipal poverty comparison"
                className="divide-y divide-border rounded border border-border"
              >
                {filtered.map((row) => (
                  <li
                    key={row.code}
                    className={`min-w-0 p-3 sm:p-4 ${row.code === selected ? "bg-accent-subtle" : ""}`}
                  >
                    <div className="grid min-w-0 gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,2fr)] sm:items-center">
                      <div className="min-w-0">
                        <Link
                          prefetch={false}
                          href={detailHref(row.code)}
                          aria-current={row.code === selected ? "location" : undefined}
                          className="break-words font-medium underline"
                        >
                          {row.name}
                        </Link>
                        <span className="mt-1 block text-xs text-muted">
                          PSGC {row.code}
                          {row.code === selected ? " · Selected place" : ""}
                        </span>
                      </div>
                      {row.value === null ? (
                        <p className="text-sm text-muted">
                          Unavailable — no usable accepted estimate
                        </p>
                      ) : (
                        <PovertyIntervalChart
                          point={row}
                          confidence={90}
                          label={row.name}
                          domain={domain}
                          format={format}
                        />
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            ) : (
              <div
                className="overflow-x-auto rounded border border-border"
                role="region"
                aria-label="Municipal poverty estimates"
                tabIndex={0}
              >
                <table className="w-full text-left text-sm">
                  <caption className="p-3 text-left">
                    2023 estimates within {name}: the share of people below the poverty threshold.
                    Open a place for exact data and accepted source records.
                  </caption>
                  <thead className="border-b border-border bg-surface">
                    <tr>
                      <th scope="col" className="p-3">
                        City or municipality
                      </th>
                      <th scope="col" className="p-3">
                        Poverty estimate
                      </th>
                      <th scope="col" className="p-3">
                        Published 90% confidence interval
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {filtered.map((row) => (
                      <tr
                        key={row.code}
                        className={`border-b border-border ${row.code === selected ? "bg-accent-subtle" : ""}`}
                      >
                        <th scope="row" className="p-3 font-normal">
                          <Link
                            prefetch={false}
                            href={detailHref(row.code)}
                            aria-current={row.code === selected ? "location" : undefined}
                            className="underline"
                          >
                            {row.name}
                          </Link>
                          <span className="mt-1 block text-xs text-muted">
                            PSGC {row.code}
                            {row.code === selected ? " · Selected place" : ""}
                          </span>
                        </th>
                        <td className="p-3">
                          {row.value === null
                            ? "Unavailable — no usable accepted estimate"
                            : format(row.value)}
                        </td>
                        <td className="p-3">
                          {row.value !== null && povertyInterval(row)
                            ? `${format(row.ciLow!)} to ${format(row.ciHigh!)}`
                            : "Unavailable"}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            {!filtered.length && (
              <p>No matching places. Clear the search or try another name or PSGC code.</p>
            )}
            <details className="text-sm text-muted">
              <summary className="cursor-pointer font-medium">Methodology and source</summary>
              <p className="mt-3">
                {POVERTY_UNCERTAINTY} No provincial average or poor-person count is calculated.
              </p>
              <p className="mt-3">
                Source: {data.source}. Reference year: 2023.{" "}
                {data.date ? `Source date: ${data.date}.` : "Source date unavailable."}{" "}
                {data.license ? `License: ${data.license}.` : "License information unavailable."}{" "}
                Published interval bounds are not clipped to 0–100%. Source exclusions and
                geographic mismatches remain applicable; an absent estimate is not zero.
              </p>
            </details>
          </>
        ) : (
          <div role="status">
            {data.state === "loading"
              ? "Loading municipal estimates…"
              : data.state === "failed"
                ? "Municipal estimates could not be loaded. This is a request failure, not missing data or zero."
                : data.state === "suppressed"
                  ? "Municipal estimates are withheld under the applicable publication rules."
                  : data.state === "unsupported"
                    ? "Municipal comparison is unsupported for this scope."
                    : "Municipal estimates are unavailable from the accepted release."}
            {data.state === "failed" && (
              <button
                type="button"
                className="ml-2 underline"
                onClick={() => {
                  setResult(null);
                  setRetry((value) => value + 1);
                }}
              >
                Try municipal estimates again
              </button>
            )}
          </div>
        )}
      </div>
    </FigureCard>
  );
}
