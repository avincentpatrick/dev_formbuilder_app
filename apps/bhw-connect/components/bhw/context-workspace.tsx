"use client";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { percentageFormatter } from "@/lib/format";
import { ContextBivariate } from "./context-bivariate";
import { ContextReview } from "./context-review";
import { useEffect, useMemo, useState } from "react";
import {
  contextHref,
  contextLabels,
  contextDefinitions,
  contextIds,
  contextSupported,
  joinContext,
  type ContextChoice,
} from "@/lib/bhw/context";
import { mapHref, type MapPayload } from "@/lib/maps/prototype";
import { readMapResponse } from "@/lib/maps/read-map-response";
import { withBasePath } from "@/lib/base-path";
import { bhwLocalityHref } from "@/lib/maps/catalog-return";
import { RelationshipScatter } from "@/components/explore/relationship-scatter";
import { FigureCard } from "@/components/narrative/figure-card";
import { describeCorrelation } from "@/lib/analysis/correlation";

const number = (v: number) => v.toLocaleString("en", { maximumFractionDigits: 1 });
type Read = { key: string; head: MapPayload; context: MapPayload } | { key: string; error: string };
export function ContextWorkspace({ initial: choice }: { initial: ContextChoice }) {
  const router = useRouter();
  const { map, context } = choice;
  const meta = contextDefinitions[context];
  const { level, code } = map;
  const key = `${level}:${code}:${context}`;
  const [read, setRead] = useState<Read | null>(null);
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    const get = async (indicator: string, dataset = "bhw-stepzero-2026", period = "2025") => {
      const q = mapHref({
        dataset,
        period,
        level,
        code,
        selected: null,
        indicator,
      }).split("?")[1];
      const response = await readMapResponse(
        withBasePath(`/api/dataset-map?${q}`),
        controller.signal,
      );
      const data = await response.json();
      if (!response.ok || data.state !== "available")
        throw new Error(
          "The requested public source is unavailable or could not be read. No substitute was used.",
        );
      return data as MapPayload;
    };
    Promise.all([get("bhw.total"), get(meta.indicator, meta.dataset, meta.period)])
      .then(([head, value]) => {
        if (controller.signal.aborted) return;
        if (!joinContext(head, value, { level, code }, context))
          throw new Error(
            "The source date or geographic records do not match. No relationship is shown.",
          );
        setRead({ key, head, context: value });
      })
      .catch((e) => {
        if (!controller.signal.aborted)
          setRead({ key, error: e instanceof Error ? e.message : "Could not read context." });
      });
    return () => controller.abort();
    // Selection changes do not refetch the same geographic observations.
  }, [key, level, code, context, meta, retry]);
  const current = read?.key === key ? read : null;
  const rows = useMemo(
    () =>
      current && !("error" in current)
        ? joinContext(current.head, current.context, { level, code }, context)
        : null,
    [current, level, code, context],
  );
  const data = current && !("error" in current) ? current.head : null;
  const plotted = (rows ?? []).filter((r) => r.eligible);
  const selected = rows?.find((r) => r.code === map.selected);
  const childLabels = {
    region: "regions",
    province: "province-level areas (including separately reported cities)",
    citymun: "cities/municipalities",
    barangay: "barangays",
  };
  const label = contextLabels[context];
  const unit = meta.unit;
  const percent = percentageFormatter(
    (rows ?? []).flatMap((r) =>
      [r.context, r.ciLow ?? null, r.ciHigh ?? null].filter((v): v is number => v !== null),
    ),
  );
  const contextValue = (v: number) => (unit === "%" ? percent(v) : number(v));
  const suffix = unit === "%" ? "" : ` ${unit}`;
  const periodLabel = meta.external ? `BHW 2025 · context ${meta.period}` : "2025";
  const correlation = describeCorrelation(plotted.map((r) => [r.context!, r.headcount!]));
  const selectedHref = (code: string) =>
    contextHref({ ...choice, map: { ...map, selected: code } });
  return (
    <div className="mx-auto w-full max-w-6xl space-y-5 p-4 sm:p-6">
      <header className="space-y-3">
        <h1 className="text-2xl font-semibold">Workforce and context</h1>
        <p>How does reported BHW headcount vary with {label.toLowerCase()} across places?</p>
        <Link
          className="underline"
          href={bhwLocalityHref({ mode: "psgc", level: map.level, code: map.code })}
        >
          Return to this area’s dashboard
        </Link>
        <nav aria-label="Context sections" className="flex flex-wrap gap-4 text-sm underline">
          <a href="#context-review">Places for closer examination</a>
          <a href="#context-values">Exact values</a>
          <a href="#context-methodology">Sources and matching</a>
        </nav>
      </header>
      <nav aria-label="Context measure" className="flex flex-wrap gap-4">
        {contextIds
          .filter((id) => contextSupported(id, map))
          .map((value) => (
            <Link
              key={value}
              aria-current={context === value ? "page" : undefined}
              className="underline"
              href={contextHref({
                ...choice,
                context: value,
                view: contextDefinitions[value].external ? undefined : choice.view,
              })}
            >
              {contextLabels[value]}
            </Link>
          ))}
      </nav>
      <nav aria-label="Context view" className="flex flex-wrap gap-4">
        <Link
          className="underline"
          aria-current={!choice.view ? "page" : undefined}
          href={contextHref({ ...choice, view: undefined })}
        >
          Scatter and exact values
        </Link>
        {!meta.external && (
          <Link
            className="underline"
            aria-current={choice.view === "bivariate" ? "page" : undefined}
            href={contextHref({ ...choice, view: "bivariate" })}
          >
            Two-variable map
          </Link>
        )}
      </nav>
      <p className="text-sm">
        {meta.external
          ? `BHW headcount is from 2025; ${meta.label.toLowerCase()} is from ${meta.period}. This compares different dated observations in the accepted reporting geography, not same-year conditions or change over time.`
          : `Both axes use BHW Headcount 2025: LGU-reported BHWs and its source ${context}.`}{" "}
        These observations do not establish individual workloads, staffing need or causation.{" "}
        {unit !== "%" && "Larger places can have larger counts on both axes."}
      </p>
      {map.level !== "province" && (
        <p className="text-sm text-muted">
          Poverty context is available only for municipal peers in a supported province view. PSA
          population has no barangay context. External contexts open in the scatter view.
        </p>
      )}
      {!current ? (
        <p role="status">Loading compatible public observations…</p>
      ) : "error" in current ? (
        <div role="alert">
          <p>{current.error}</p>
          <button
            className="min-h-11 underline"
            onClick={() => {
              setRead(null);
              setRetry((n) => n + 1);
            }}
          >
            Retry context
          </button>
        </div>
      ) : null}
      {data && rows && (
        <>
          <h2 className="text-xl font-semibold">
            {data.area} · {periodLabel}
          </h2>
          <nav aria-label="Context geography" className="flex flex-wrap gap-3">
            {data.navigation?.parents
              .filter((parent) => contextSupported(context, parent))
              .map((parent) => (
                <Link
                  key={parent.code}
                  className="underline"
                  href={contextHref({
                    ...choice,
                    map: { ...map, level: parent.level, code: parent.code, selected: null },
                  })}
                >
                  {parent.name}
                </Link>
              ))}
          </nav>
          <FigureCard
            title={`BHW headcount and ${label.toLowerCase()}`}
            caption={`${data.area} · ${periodLabel} · ${plotted.length} paired geographic observations`}
            headline={
              correlation.kind === "described"
                ? `Spearman rank association: ${number(correlation.rho)} across ${correlation.n} places. This is not a causal finding.`
                : "No association summary: fewer than ten paired places or one axis has no variation."
            }
          >
            {choice.view === "bivariate" ? (
              <ContextBivariate
                key={key}
                rows={rows}
                data={data}
                label={label}
                unit={unit}
                selected={map.selected}
                onSelect={(code) => router.push(selectedHref(code), { scroll: false })}
                onRetry={() => {
                  setRead(null);
                  setRetry((n) => n + 1);
                }}
              />
            ) : plotted.length ? (
              <RelationshipScatter
                nonNegative
                xLabel={`${label} (${unit})`}
                yLabel="BHW headcount (people)"
                xFormat={contextValue}
                xMaximum={unit === "%" ? 100 : undefined}
                yFormat={number}
                ariaLabel={`BHW headcount against ${label.toLowerCase()} in ${data.area}. Each point opens locality details below.`}
                points={plotted.map((r) => ({
                  code: r.code,
                  x: r.context!,
                  y: r.headcount!,
                  href: withBasePath(selectedHref(r.code)),
                  label: `${r.name}: ${number(r.headcount!)} BHWs; ${contextValue(r.context!)}${suffix}. Inspect locality.`,
                }))}
              />
            ) : (
              <p>No paired observations to plot. This does not mean zero.</p>
            )}
          </FigureCard>
          <p>
            {plotted.length} of {rows.length} listed {childLabels[data.childLevel]} have both usable
            values. {rows.filter((r) => r.headcount === null).length} lack usable headcount;{" "}
            {rows.filter((r) => r.headcount !== null && r.context === null).length} more lack usable
            context. Missing and source-flagged small samples are excluded, not zero. Zero values
            remain. Missing map shapes do not exclude a place.
          </p>
          <section
            aria-label="Selected locality"
            className="space-y-2 rounded border border-border p-3"
          >
            {selected ? (
              <>
                <h2 className="font-semibold">{selected.name}</h2>
                <p>
                  Headcount:{" "}
                  {selected.headcount === null ? "Unavailable" : number(selected.headcount)} people.{" "}
                  {label}:{" "}
                  {selected.context === null ? "Unavailable" : contextValue(selected.context)}{" "}
                  {unit === "%" ? "" : unit}.
                </p>
                <Link
                  className="inline-block min-h-11 underline"
                  href={bhwLocalityHref({
                    mode: "psgc",
                    level: data.childLevel,
                    code: selected.code,
                  })}
                >
                  Open full locality dashboard
                </Link>
                {data.childLevel !== "barangay" &&
                  (data.childLevel !== "citymun" || data.barangayDrill) &&
                  contextSupported(context, { level: data.childLevel, code: selected.code }) && (
                    <Link
                      className="ml-4 inline-block min-h-11 underline"
                      href={contextHref({
                        ...choice,
                        map: {
                          ...map,
                          level: data.childLevel,
                          code: selected.code,
                          selected: null,
                        },
                      })}
                    >
                      Examine child areas
                    </Link>
                  )}
              </>
            ) : (
              <p role="status">
                {map.selected
                  ? "The shared selection is not in this area; no substitute was selected."
                  : "Select a point or table row to inspect a locality."}
              </p>
            )}
          </section>
          <ContextReview
            rows={rows}
            choice={choice}
            label={label}
            unit={unit}
            contextValue={contextValue}
          />
          <div
            role="region"
            id="context-values"
            aria-label="Context exact values"
            tabIndex={0}
            className="max-h-96 overflow-auto rounded border border-border"
          >
            <table className="w-full text-left text-sm">
              <caption className="p-3 text-left">
                Same-code joins within {data.area}; {childLabels[data.childLevel]}. All returned
                places, including excluded observations.
              </caption>
              <thead>
                <tr>
                  <th scope="col" className="p-2">
                    Place
                  </th>
                  <th scope="col" className="p-2">
                    BHW headcount (people)
                  </th>
                  <th scope="col" className="p-2">
                    {label} ({unit})
                  </th>
                  <th scope="col" className="p-2">
                    Included in plot
                  </th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.code} className="border-t border-border">
                    <th scope="row" className="p-2 font-normal">
                      <Link className="inline-block min-h-11 underline" href={selectedHref(r.code)}>
                        {r.name} ({r.code})
                      </Link>
                    </th>
                    <td className="p-2">
                      {r.headcount === null ? "Unavailable" : number(r.headcount)}
                    </td>
                    <td className="p-2">
                      {r.context === null ? "Unavailable" : contextValue(r.context)}
                      {unit === "%" && (
                        <span className="block text-xs">
                          {r.ciLow !== null &&
                          r.ciLow !== undefined &&
                          r.ciHigh !== null &&
                          r.ciHigh !== undefined
                            ? `90% CI: ${contextValue(r.ciLow)} to ${contextValue(r.ciHigh)}`
                            : "90% CI unavailable"}
                        </span>
                      )}
                    </td>
                    <td className="p-2">{r.eligible ? "Yes" : "No — incomplete or flagged"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <section
            id="context-methodology"
            aria-label="Context sources and matching"
            className="space-y-2 text-sm"
          >
            <h2 className="font-semibold">Sources and matching</h2>
            <p>{meta.note}</p>
            <p>
              Headcount: {data.source} · source date: {data.date} · people · 2025. Reuse terms:{" "}
              {data.license ?? "Not supplied by source metadata"}.
            </p>
            <p>
              Context: {current && !("error" in current) ? current.context.source : ""} · source
              date: {meta.date} · {unit} · {meta.period}. Reuse terms:{" "}
              {current && !("error" in current)
                ? (current.context.license ?? "Not supplied by source metadata")
                : "Unavailable"}
              .
            </p>
            <p>
              {meta.external
                ? "The accepted ingestion reconciles the external source to the reporting geography; this view joins the canonical records, without repeating name matching."
                : "Both axes use the same accepted release and reporting geography."}{" "}
              Joins require identical codes, names, levels and returned child-area rosters. Missing
              values are never filled from another source, parent or national figure. This does not
              certify nationwide historical boundaries. No ratio, average of local rates or change
              over time is inferred.
            </p>
            <Link
              className="underline"
              href={
                bhwLocalityHref({ mode: "psgc", level: map.level, code: map.code }) + "#methodology"
              }
            >
              Dashboard methodology for this area
            </Link>
          </section>
        </>
      )}
    </div>
  );
}
