import "server-only";
import { FHSIS_OVER_100_NOTE } from "@/lib/db/fhsis";
import { CATALOG, catalogDetailHref, type IndicatorSpec } from "./definitions";
import { createCatalogReader, type CatalogReader, type Row } from "./reader";
import { applyComplementarySuppression } from "@/lib/db/area-profile-suppression";
import { datasetState, hasAvailableValue, summarizeCatalog } from "./summary";
import type {
  CatalogDataset,
  CatalogIndicator,
  CatalogSelection,
  PublicCatalog,
  PublicSource,
  PublicValue,
} from "./types";

const number = (value: unknown): number | null =>
  typeof value === "number" && Number.isFinite(value) ? value : null;
const text = (value: unknown): string => (typeof value === "string" ? value : "");
const date = (value: string | null): string | null =>
  value && Number.isFinite(Date.parse(value)) ? value : null;

/** Four concurrent indicators bound All-locations HEAD requests; area reads are coalesced by table. */
async function mapBounded<T, R>(items: readonly T[], fn: (item: T) => Promise<R>): Promise<R[]> {
  const out: R[] = new Array(items.length);
  let next = 0;
  await Promise.all(
    Array.from({ length: Math.min(4, items.length) }, async () => {
      while (next < items.length) {
        const index = next++;
        out[index] = await fn(items[index]);
      }
    }),
  );
  return out;
}

function base(spec: IndicatorSpec): CatalogIndicator {
  return {
    id: spec.id,
    label: spec.label,
    unit: spec.unit,
    state: "unavailable",
    reason: null,
    sourceSlug: spec.source,
    values: [],
    reportedAreaRecords: null,
  };
}

function selectedValues(
  spec: IndicatorSpec,
  rows: Row[],
  item: CatalogIndicator,
): CatalogIndicator {
  let matched = rows.filter((row) =>
    Object.entries(spec.filters ?? {}).every(([key, value]) => row[key] === value),
  );
  if (!matched.length)
    return { ...item, reason: "No accepted record is available for this location." };
  if (!spec.categoryColumn && matched.length !== 1) {
    return {
      ...item,
      state: "failed",
      reason: "The source returned ambiguous records for this measure.",
    };
  }
  // A malformed scalar must not masquerade as a source that did not report a value.
  if (matched.some((row) => row[spec.column] !== null && number(row[spec.column]) === null)) {
    return { ...item, state: "failed", reason: "The source returned an invalid measure." };
  }
  if (spec.suppression && matched.some((row) => typeof row[spec.suppression!] !== "boolean")) {
    return { ...item, state: "failed", reason: "The source returned an invalid privacy flag." };
  }
  let suppressed = false;
  if (spec.categoryColumn) {
    if (matched.some((r) => typeof r[spec.categoryColumn!] !== "string")) {
      return { ...item, state: "failed", reason: "The source returned an invalid category." };
    }
    const result = applyComplementarySuppression(
      matched.map((row) => ({
        dimension: spec.id,
        category: text(row[spec.categoryColumn!]),
        n: number(row[spec.column]),
        pct: null,
        isSuppressed: spec.suppression ? row[spec.suppression] === true : false,
      })),
    );
    suppressed = result.rows.some((r) => r.isSuppressed);
    if (result.unprotectable.length)
      return {
        ...item,
        state: "suppressed",
        reason: "This breakdown cannot be shown without exposing a withheld value.",
      };
    matched = result.rows
      .filter((r) => !r.isSuppressed)
      .map((r) => ({ [spec.column]: r.n, [spec.categoryColumn!]: r.category }));
  } else if (spec.suppression && matched.some((row) => row[spec.suppression!] === true)) {
    return {
      ...item,
      state: "suppressed",
      reason: "Withheld under the source's small-group privacy rule.",
    };
  }
  const values: PublicValue[] = matched.flatMap((row) => {
    const value = number(row[spec.column]);
    if (value === null) return [];
    const result: PublicValue = { value };
    if (spec.categoryColumn) result.label = text(row[spec.categoryColumn]);
    for (const [key, column] of Object.entries(spec.attributes ?? {})) {
      result[key as "numerator" | "denominator" | "ciLow" | "ciHigh"] = number(row[column]);
    }
    return [result];
  });
  if (!values.length)
    return {
      ...item,
      state: suppressed ? "suppressed" : "unavailable",
      reason: suppressed
        ? "Values are withheld to protect privacy."
        : "The accepted record has no reported value for this measure.",
    };
  const missing = values.length < matched.length;
  return {
    ...item,
    values,
    reportedAreaRecords: spec.categoryColumn ? null : 1,
    state: suppressed || missing || spec.partialReason ? "partial" : "available",
    reason:
      spec.partialReason ??
      (suppressed
        ? "Some categories are withheld, including complementary suppression."
        : missing
          ? "Some categories have no reported value."
          : null),
  };
}

function validateSelection(selection: CatalogSelection): void {
  if (selection.mode === "all") return;
  if (selection.mode === "district") {
    if (!selection.districtCode.trim()) throw new Error("A district code is required.");
    return;
  }
  if (
    selection.mode !== "psgc" ||
    !["national", "region", "province", "citymun", "barangay"].includes(selection.level) ||
    !selection.code ||
    (selection.level === "national" ? selection.code !== "PH" : !/^\d{2,10}$/.test(selection.code))
  ) {
    throw new Error("Invalid catalog geography selection.");
  }
}

/** Read-only public catalog. No Server Action, route, service-role access or admin payload. */
export async function getPublicCatalog(
  selection: CatalogSelection,
  reader: CatalogReader = createCatalogReader(),
): Promise<PublicCatalog> {
  validateSelection(selection);
  const sources = new Map<string, PublicSource>();
  const byDataset = new Map<string, CatalogIndicator[]>();
  // One pool across all datasets, not six pools of four requests each.
  await mapBounded(
    CATALOG.flatMap((dataset) => dataset.indicators.map((spec) => ({ dataset, spec }))),
    async ({ dataset, spec }) => {
      let item = base(spec);
      try {
        if (
          selection.mode === "district" ||
          (selection.mode === "psgc" && !spec.levels.includes(selection.level))
        ) {
          item = {
            ...item,
            state: "unsupported",
            reason:
              selection.mode === "district"
                ? "District-scoped aggregation awaits verified membership and measure rules."
                : "This measure is not supported by the catalog at this geographic level.",
          };
        } else {
          const source = await reader.source(spec.source);
          if (source.state !== "available") {
            item = {
              ...item,
              state: source.state,
              reason:
                source.state === "failed"
                  ? "Source metadata could not be read. Please try again."
                  : "The accepted source release is unavailable.",
            };
          } else {
            const info = source.value;
            sources.set(info.slug, {
              slug: info.slug,
              name: info.name,
              sourceName: info.sourceName,
              asOfDate: date(info.asOfDate),
              lastUpdatedAt: date(info.lastUpdatedAt),
            });
            let definitionReady = true;
            if (spec.table === "fact_fhsis_indicator") {
              const definitions = await reader.fhsisDefinitions();
              const def =
                definitions.state === "available"
                  ? definitions.rows.find((r) => r.indicator_key === spec.filters?.indicator_key)
                  : undefined;
              if (
                !def ||
                !text(def.unit) ||
                !text(def.label) ||
                !text(def.denominator_def) ||
                !text(def.numerator_def)
              ) {
                definitionReady = false;
                item = {
                  ...item,
                  state: definitions.state === "failed" ? "failed" : "unavailable",
                  reason: "The accepted indicator definition is unavailable.",
                };
              } else {
                item = {
                  ...item,
                  label: text(def.label),
                  unit: text(def.unit),
                  definition: {
                    numerator: text(def.numerator_def),
                    denominator: text(def.denominator_def),
                    sourceWorkbook: text(def.source_workbook),
                  },
                };
              }
            }
            if (definitionReady) {
              if (selection.mode === "all") {
                const probe = await reader.probe(spec, info.datasetId);
                item =
                  probe.state === "failed"
                    ? {
                        ...item,
                        state: "failed",
                        reason: "Availability could not be checked. Please try again.",
                      }
                    : {
                        ...item,
                        state:
                          probe.count > 0
                            ? probe.hasSuppressed || spec.partialReason
                              ? "partial"
                              : "available"
                            : probe.hasSuppressed
                              ? "suppressed"
                              : "unavailable",
                        reason:
                          probe.count > 0
                            ? (spec.partialReason ??
                              (probe.hasSuppressed
                                ? "Some source values are withheld."
                                : "Figures are available for reported locations; this is not a national statistic."))
                            : probe.hasSuppressed
                              ? "Source values are withheld."
                              : "No usable public observations were found in the accepted release.",
                        reportedAreaRecords: spec.categoryColumn ? null : probe.count,
                      };
              } else {
                const read = await reader.rows(spec, info.datasetId, selection);
                item =
                  read.state === "failed"
                    ? {
                        ...item,
                        state: "failed",
                        reason: "Figures could not be read. Please try again.",
                      }
                    : selectedValues(spec, read.rows, item);
                if (
                  spec.table === "fact_fhsis_indicator" &&
                  item.unit.startsWith("percent") &&
                  item.values.some((v) => v.value > 100)
                ) {
                  item = {
                    ...item,
                    reason: [item.reason, FHSIS_OVER_100_NOTE].filter(Boolean).join(" "),
                  };
                }
                if (
                  hasAvailableValue(item.state) &&
                  spec.table === "agg_population" &&
                  selection.level !== "citymun" &&
                  !(selection.level === "province" && selection.code === "13806")
                ) {
                  item = {
                    ...item,
                    state: "partial",
                    reason:
                      "Partial total from matched census areas; complete coverage has not been verified. Manila's province node stores its published census total. Do not repeat it for child areas.",
                  };
                }
                if (
                  hasAvailableValue(item.state) &&
                  spec.id === "uuc.route.health" &&
                  read.state === "available"
                ) {
                  const row = read.rows[0];
                  const listed = number(row?.n_listed),
                    evaluable = number(row?.n_health_evaluable);
                  if (listed !== null && evaluable !== null && evaluable < listed) {
                    item = {
                      ...item,
                      state: "partial",
                      reason:
                        "The health criterion excludes listed barangays without usable provincial benchmarks.",
                    };
                  }
                }
              }
            }
          }
        }
      } catch {
        item = {
          ...item,
          state: "failed",
          values: [],
          reason: "Figures could not be read. Please try again.",
        };
      }
      const list = byDataset.get(dataset.id) ?? [];
      list.push(item);
      byDataset.set(dataset.id, list);
    },
  );
  const datasets: CatalogDataset[] = CATALOG.map((spec) => {
    const unordered = byDataset.get(spec.id) ?? [];
    const indicators = spec.indicators.map((i) => unordered.find((v) => v.id === i.id)!);
    const slugs = new Set(spec.indicators.map((i) => i.source));
    return {
      id: spec.id,
      title: spec.title,
      category: spec.category,
      state: datasetState(indicators.map((i) => i.state)),
      availableIndicators: indicators.filter((i) => hasAvailableValue(i.state)).length,
      totalIndicators: indicators.length,
      supportedLevels: [...new Set(spec.indicators.flatMap((i) => i.levels))],
      detailHref: catalogDetailHref(spec, selection),
      sources: [...slugs].flatMap((slug) => (sources.has(slug) ? [sources.get(slug)!] : [])),
      indicators,
    };
  });
  return { selection, datasets, overview: summarizeCatalog(datasets) };
}
