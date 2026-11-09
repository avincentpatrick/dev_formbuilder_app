import { CENSUS_ACTIVE_YEARS_ID } from "./census-active-years";
import { isCensusProfileRate } from "./census-accreditation";
import type { ColorBin } from "@/lib/charts/color-scale";
import { getDatasetMap } from "./definitions";
import { prototypeIndicators, type MapChoice, type MapPayload } from "./prototype";
import { STEPZERO_ACCREDITATION_ID } from "./stepzero-accreditation";
import { incomeClassLabel } from "./income-class";

/** Export only the public fields already shown. Never recover withheld values or filter by geometry. */
export function datasetMapExport(
  choice: MapChoice,
  data: MapPayload,
  bins: ColorBin[],
  shapeCodes: readonly string[] | null,
) {
  const definition = getDatasetMap(choice.dataset);
  const metric = prototypeIndicators(choice.dataset).find(
    (m) => m.id === choice.indicator && m.period === choice.period,
  );
  if (!definition || !metric) throw new Error("Unsupported map export");
  const navigationOnly = data.valueSupport === "navigation-only";
  const income = choice.dataset === "lgu-income-class";
  const declaredAccreditation = choice.dataset === "bhw-stepzero-2026" && choice.indicator === STEPZERO_ACCREDITATION_ID;
  const censusMean = choice.dataset === "bhw-2025" && choice.indicator === CENSUS_ACTIVE_YEARS_ID;
  const censusRate = choice.dataset === "bhw-2025" && isCensusProfileRate(choice.indicator);
  const accreditation = declaredAccreditation || censusRate;
  const fixed = accreditation || income || choice.dataset === "psa-sae-poverty-2023" ||
    choice.dataset === "bhw-profiling-status-2026";
  return {
    schemaVersion: 1,
    dataset: { slug: definition.slug, title: definition.title },
    selection: { ...choice },
    indicator: { id: metric.id, label: metric.label, unit: metric.unit,
      period: metric.period, denominator: metric.denominator,
      confidenceLevel: censusRate && metric.catalogSpec?.attributes?.ciLow ? 0.95 : choice.dataset === "psa-sae-poverty-2023" ? 0.9 : null },
    source: { title: data.source, date: data.date, license: data.license,
      attribution: definition.attribution },
    disclosure: definition.disclosure,
    geography: { area: data.area, childLevel: data.childLevel,
      boundaryVersion: definition.boundaryVersion, asset: data.geometryUrl,
      note: "Geometry availability does not establish historical boundary acceptance." },
    legend: {
      method: navigationOnly ? "not-applicable" : fixed ? "fixed" : "quantiles-relative-to-displayed-areas",
      bins: navigationOnly ? [] : bins.map((bin) => ({
        min: bin.min, max: Number.isFinite(bin.max) ? bin.max : null,
        openEnded: !Number.isFinite(bin.max), color: bin.color,
        ...(income ? { label: incomeClassLabel(bin.min) } : {}),
      })),
    },
    parentMeasure: { kind: censusMean ? "mean" : accreditation ? "rate" : "total",
      value: navigationOnly ? null : censusMean ? data.parentMean ?? null : accreditation ? data.parentRate ?? null : data.total,
      unit: metric.unit },
    total: navigationOnly ? null : data.total,
    totalNote: data.totalNote ?? (censusMean ? "Published parent mean; never an average of child means." : accreditation ? "Published parent rate; never an average of child percentages." : "Reported parent total, not a sum of drawable areas; no aggregate is inferred when absent."),
    rows: data.rows.map((row) => ({
      code: row.code, name: row.name,
      value: navigationOnly ? null : row.value,
      valueState: navigationOnly ? "not-published-at-this-level" : row.value === null ? "not-reported-or-unavailable" : "available",
      ...(income ? { label: navigationOnly ? null : incomeClassLabel(row.value) } : {}),
      ciLow: navigationOnly ? null : row.ciLow ?? null,
      ciHigh: navigationOnly ? null : row.ciHigh ?? null,
      note: row.note ?? null,
      ...(censusRate ? { smallSample: row.smallSample === true } : {}),
      geometry: shapeCodes === null ? "not-checked" : shapeCodes.includes(row.code) ? "available" : "missing",
    })),
  };
}
