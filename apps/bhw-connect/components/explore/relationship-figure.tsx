"use client";

import { RelationshipScatter } from "./relationship-scatter";
import { useCallback, useMemo } from "react";
import { useFilterState } from "@/lib/filters/use-filter-state";
import { FigureCard } from "@/components/narrative/figure-card";
import { useExploreNav } from "@/components/explore/explore-nav";
import { MIN_LEADER_N } from "@/lib/analysis/thresholds";
import {
  REL_AXIS_META,
  REL_AXIS_OPTIONS,
  REL_EXTERNAL_INDICATOR_META,
  formatIndicatorValue,
} from "@/lib/analysis/map-indicators";
import { describeCorrelation, MIN_CORRELATION_N } from "@/lib/analysis/correlation";
import { logEvent } from "@/lib/usage/log-client";
import {
  REL_EXTERNAL_INDICATORS,
  type RelAxisIndicator,
  type GeoLevel,
} from "@/lib/filters/schema";
import { withBasePath } from "@/lib/base-path";

/** One child geo with all axis values, for the scatter (E1.4 / E4.4). `povertyIncidence` is the
 * external PSA SAE variable, present only at city/municipality grain. */
export type RelationshipPoint = {
  geoCode: string;
  geoName: string;
  nTotal: number | null;
  pctAccredited: number | null;
  anyHonorariumPct: number | null;
  householdsPerBhw: number | null;
  avgActiveYears: number | null;
  coveragePct: number | null;
  bhwPer1000: number | null;
  povertyIncidence: number | null;
};

function pick(p: RelationshipPoint, indicator: RelAxisIndicator): number | null {
  switch (indicator) {
    case "pct_accredited":
      return p.pctAccredited;
    case "any_honorarium_pct":
      return p.anyHonorariumPct;
    case "households_per_bhw":
      return p.householdsPerBhw;
    case "avg_active_years":
      return p.avgActiveYears;
    case "coverage_pct":
      return p.coveragePct;
    case "bhw_per_1000":
      return p.bhwPer1000;
    case "poverty_incidence":
      return p.povertyIncidence;
  }
}

/** Whether an axis choice is an external (non-workforce) variable — used for source stamping. */
function isExternal(indicator: RelAxisIndicator): boolean {
  return (REL_EXTERNAL_INDICATORS as readonly string[]).includes(indicator);
}

export function RelationshipFigure({
  points,
  childLevel,
  childLevelLabelPlural,
  caption,
}: {
  points: RelationshipPoint[];
  childLevel: GeoLevel;
  childLevelLabelPlural: string;
  caption: string;
}) {
  const { startTransition } = useExploreNav();
  const [{ relX, relY }, setFilters] = useFilterState({ startTransition });

  const childPlural = childLevelLabelPlural.toLowerCase();
  const xMeta = REL_AXIS_META[relX];
  const yMeta = REL_AXIS_META[relY];

  const setAxis = useCallback(
    (axis: "relX" | "relY", value: RelAxisIndicator) => {
      logEvent("rel_axis_change", { meta: { axis, indicator: value, childLevel } });
      setFilters({ [axis]: value });
    },
    [childLevel, setFilters],
  );

  // External variables (poverty) carry data only at city/municipality grain. Offer them only
  // where at least one child has a value — but always keep a currently-selected external axis in
  // the list so a stale permalink's <select> still shows its choice (the scatter then degrades to
  // "not enough data"). The map's own indicator switcher never lists these.
  const hasExternalData = points.some((p) =>
    REL_EXTERNAL_INDICATORS.some((k) => pick(p, k) !== null),
  );
  const axisOptions = REL_AXIS_OPTIONS.filter(
    (o) => !o.external || hasExternalData || o.value === relX || o.value === relY,
  );

  // Source stamp for any external axis on the figure (identity rule: external data is cited).
  const externalCaptions = [relX, relY]
    .filter(isExternal)
    .map((k) => REL_EXTERNAL_INDICATOR_META[k as keyof typeof REL_EXTERNAL_INDICATOR_META].caption);
  const sourceNote = Array.from(new Set(externalCaptions)).join(" · ");

  const plotted = useMemo(
    () =>
      points
        .map((p) => ({ p, x: pick(p, relX), y: pick(p, relY) }))
        .filter(
          (d): d is { p: RelationshipPoint; x: number; y: number } => d.x !== null && d.y !== null,
        ),
    [points, relX, relY],
  );

  // Correlation excludes small-N children (their rate is unstable); they still
  // plot, as hollow dots.
  const corrPairs = useMemo(
    () =>
      plotted
        .filter((d) => d.p.nTotal !== null && d.p.nTotal >= MIN_LEADER_N)
        .map((d): [number, number] => [d.x, d.y]),
    [plotted],
  );
  const desc = useMemo(() => describeCorrelation(corrPairs), [corrPairs]);

  const headline = useMemo(() => {
    const tail = " This compares places, not individual BHWs.";
    if (desc.kind === "insufficient") {
      return `Too few ${childPlural} with enough profiled BHWs to assess a pattern between ${xMeta.headlinePhrase} and ${yMeta.headlinePhrase}.`;
    }
    if (desc.strength === "none") {
      return `There's no clear link between ${xMeta.headlinePhrase} and ${yMeta.headlinePhrase} across these ${childPlural}.${tail}`;
    }
    const yDir = desc.direction === "positive" ? "higher" : "lower";
    return `Places with a higher ${xMeta.headlinePhrase} tend to have a ${yDir} ${yMeta.headlinePhrase} — a ${desc.strength} link.${tail}`;
  }, [desc, xMeta, yMeta, childPlural]);

  const maxN = Math.max(1, ...plotted.map((d) => d.p.nTotal ?? 0));
  const radius = (n: number | null) => {
    if (!n || maxN <= 0) return 3.5;
    return 3.5 + 5.5 * Math.sqrt(Math.min(1, n / maxN));
  };

  const hasSmallN = plotted.some((d) => d.p.nTotal !== null && d.p.nTotal < MIN_LEADER_N);
  const xf = (v: number) => formatIndicatorValue(v, xMeta.suffix);
  const yf = (v: number) => formatIndicatorValue(v, yMeta.suffix);

  return (
    <FigureCard
      title={`${yMeta.label} vs ${xMeta.label}, across ${childPlural}`}
      caption={sourceNote ? `${caption} · ${sourceNote}` : caption}
      headline={headline}
      technicalDetails={
        <p>
          {desc.kind === "described" ? (
            <>
              Spearman rank correlation ρ = {desc.rho.toFixed(2)} across {desc.n} {childPlural} with
              at least {MIN_LEADER_N} profiled BHWs.{" "}
            </>
          ) : (
            <>
              Needs at least {MIN_CORRELATION_N} {childPlural} with enough profiled BHWs.{" "}
            </>
          )}
          Hollow points have fewer than {MIN_LEADER_N} profiled BHWs and are excluded from the
          correlation. This is a place-level comparison (ecological), not a statement about
          individual BHWs — see{" "}
          <a
            href={withBasePath("/methodology#relationships")}
            className="underline hover:text-accent"
          >
            methodology
          </a>
          .
        </p>
      }
    >
      <div className="flex flex-col gap-4">
        <div className="flex flex-wrap items-end gap-3">
          <label className="flex flex-col gap-1 text-xs font-medium text-muted">
            Horizontal axis
            <select
              value={relX}
              onChange={(e) => setAxis("relX", e.target.value as RelAxisIndicator)}
              className="rounded-md border border-border bg-background px-2 py-1.5 text-sm text-foreground"
            >
              {axisOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </label>
          <label className="flex flex-col gap-1 text-xs font-medium text-muted">
            Vertical axis
            <select
              value={relY}
              onChange={(e) => setAxis("relY", e.target.value as RelAxisIndicator)}
              className="rounded-md border border-border bg-background px-2 py-1.5 text-sm text-foreground"
            >
              {axisOptions.map((opt) => (
                <option key={opt.value} value={opt.value}>
                  {opt.label}
                </option>
              ))}
            </select>
          </label>
        </div>

        {plotted.length > 0 ? (
          <RelationshipScatter
            xLabel={xMeta.axisLabel}
            yLabel={yMeta.axisLabel}
            xFormat={xf}
            yFormat={yf}
            ariaLabel={`Scatter plot: each point is a ${childLevelLabelPlural.toLowerCase().replace(/s$/, "")}. Horizontal axis ${xMeta.axisLabel}, vertical axis ${yMeta.axisLabel}. Points link to each place's page.`}
            points={plotted.map(({ p, x, y }) => ({
              code: p.geoCode,
              x,
              y,
              radius: radius(p.nTotal),
              hollow: p.nTotal !== null && p.nTotal < MIN_LEADER_N,
              href: withBasePath(`/place/${childLevel}/${p.geoCode}`),
              label: `${p.geoName}: ${xMeta.label} ${xf(x)}, ${yMeta.label} ${yf(y)}${p.nTotal !== null ? ` (${p.nTotal.toLocaleString()} profiled)` : ""}. Open place page.`,
            }))}
          />
        ) : (
          <p className="text-sm text-muted">
            Not enough {childPlural} have data for both indicators to plot a relationship.
          </p>
        )}

        {hasSmallN && (
          <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted">
            <span className="flex items-center gap-1.5">
              <span
                aria-hidden="true"
                className="inline-block h-2.5 w-2.5 rounded-full border border-muted"
              />
              Hollow = fewer than {MIN_LEADER_N} profiled (excluded from the correlation)
            </span>
            <span>Dot size ∝ profiled BHWs</span>
          </div>
        )}
      </div>
    </FigureCard>
  );
}
