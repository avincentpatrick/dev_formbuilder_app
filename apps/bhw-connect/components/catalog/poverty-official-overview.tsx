import { PovertyIntervalChart } from "./poverty-interval-chart";
import Link from "next/link";
import { FigureCard } from "@/components/narrative/figure-card";
import { StatValue } from "@/components/home/stat-value";
import { percentageFormatter } from "@/lib/format";
import { OFFICIAL_POVERTY_URL, OFFICIAL_POVERTY_DEFINITION } from "@/lib/catalog/poverty-official";
import { catalogJourneyHref } from "@/lib/catalog/journey";
import { ACCEPTED_RELEASES } from "@/lib/catalog/definitions";
import type { CatalogDataset, CatalogSelection } from "@/lib/catalog/types";

export function PovertyOfficialOverview({
  dataset,
  selection,
  mapState,
  locationName = "Selected reporting area",
}: {
  dataset: CatalogDataset;
  selection: CatalogSelection;
  mapState?: string;
  locationName?: string;
}) {
  const item = dataset.indicators.find((i) => i.id === "poverty.official-incidence");
  if (
    selection.mode === "district" ||
    (selection.mode === "psgc" && selection.level === "barangay")
  )
    return null;
  // Municipal SAE remains the main local series; show official city records only when matched.
  if (
    selection.mode === "psgc" &&
    selection.level === "citymun" &&
    (!item ||
      item.reason?.startsWith("No matched") ||
      (item.state === "unavailable" &&
        !dataset.sources.some((s) => s.slug === ACCEPTED_RELEASES.povertyOfficial)))
  )
    return null;
  const value =
    selection.mode !== "all" &&
    item?.sourceSlug === ACCEPTED_RELEASES.povertyOfficial &&
    (item.state === "available" || item.state === "partial") &&
    item.values.length === 1
      ? item.values[0]
      : null;
  const valid =
    value && Number.isFinite(value.value) && value.value >= 0 && value.value <= 100 ? value : null;
  const interval =
    valid &&
    typeof valid.ciLow === "number" &&
    Number.isFinite(valid.ciLow) &&
    typeof valid.ciHigh === "number" &&
    Number.isFinite(valid.ciHigh) &&
    valid.ciLow <= valid.ciHigh;
  const format = percentageFormatter(
    valid ? [valid.value, ...(interval ? [valid.ciLow!, valid.ciHigh!] : [])] : [],
  );
  return (
    <FigureCard
      title="Official poverty estimate for this reporting area"
      caption={`${locationName} · PSA Full-Year Official Poverty Statistics · 2023 r2 · people`}
      headline={
        valid
          ? "The dot shows the estimate; the line shows its 95% confidence interval. A wider interval means less precision."
          : "Official survey estimates use their own reporting boundaries and source coverage."
      }
    >
      <div className="space-y-3" data-testid="poverty-official-overview">
        {selection.mode === "all" ? (
          <p>
            All locations is an availability view.{" "}
            <Link
              className="underline"
              href={catalogJourneyHref(
                { mode: "psgc", level: "national", code: "PH" },
                "poverty",
                mapState,
              )}
            >
              View the Philippines national estimate →
            </Link>
          </p>
        ) : valid ? (
          <>
            <p className="text-sm text-muted">
              Estimated share of people below the poverty threshold
            </p>
            <StatValue value={format(valid.value)} />
            <PovertyIntervalChart point={valid} confidence={95} label={locationName} />
            <p className="text-sm">{item?.reason}</p>
          </>
        ) : (
          <p role="status">
            {item?.state === "failed"
              ? "The official estimate could not be loaded or verified. Try again; this is not zero."
              : item?.state === "suppressed"
                ? item.reason
                : item?.reason?.includes("PSA ")
                  ? item.reason
                  : "No matched official 2023 estimate is loaded for this area yet. This does not mean PSA has no published estimate."}
          </p>
        )}
        <details className="text-sm text-muted">
          <summary className="cursor-pointer font-medium">How to read this estimate</summary>
          <div className="mt-3 space-y-3">
            <p>{OFFICIAL_POVERTY_DEFINITION}</p>
            <p className="text-sm">
              The published interval describes statistical uncertainty; it does not contain 95% of
              individual incomes. Source areas may differ from current administrative boundaries. No
              local value is replaced with a national or parent-area rate.
            </p>
            <a className="inline-block underline" href={OFFICIAL_POVERTY_URL}>
              Original PSA tables and footnotes (Excel)
            </a>
            <p className="text-sm text-muted">
              This view deliberately uses the 2023 r2 release alongside 2023 municipal SAE. Later
              revisions and 2025 estimates are separate vintages, not silently substituted. The
              municipal map continues to show SAE estimates only.
            </p>
          </div>
        </details>
      </div>
    </FigureCard>
  );
}
