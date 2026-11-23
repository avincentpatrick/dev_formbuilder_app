import { expect, it } from "vitest";
import { facilityContext } from "./context";
import { summarizeCatalog } from "@/lib/catalog/summary";
import type { CatalogDataset, CatalogIndicator, CatalogSelection } from "@/lib/catalog/types";
const scope: CatalogSelection = { mode: "psgc", level: "citymun", code: "0102801" };
const make = (
  id: CatalogDataset["id"],
  indicatorId: string,
  slug: string,
  unit: string,
  value: number,
  date: string,
): CatalogDataset => ({
  id,
  title: id,
  category: id,
  state: "available",
  availableIndicators: 1,
  totalIndicators: 1,
  supportedLevels: ["citymun"],
  detailHref: null,
  sources: [{ slug, name: slug, sourceName: null, asOfDate: date, lastUpdatedAt: null }],
  indicators: [
    {
      id: indicatorId,
      label: indicatorId,
      unit,
      sourceSlug: slug,
      state: "available",
      reason: null,
      values: [{ value }],
      reportedAreaRecords: 1,
    },
  ],
});
export const datasets = [
  make("nhfr", "nhfr.facilities", "nhfr-2026-09", "facilities", 5, "2026-09-01"),
  make("population", "population.residents", "psa-popcen-2024", "people", 10000, "2024-07-01"),
];
const build = (data = datasets, selection: CatalogSelection = scope) => ({
  selection,
  datasets: data,
  overview: summarizeCatalog(data),
});
it("calculates mixed-year inventory density only for complete municipal counts with verified dates", () => {
  expect(facilityContext(build())?.density).toBe(50);
  for (const level of ["national", "region", "province", "barangay"] as const)
    expect(
      facilityContext(
        build(datasets, { mode: "psgc", level, code: level === "national" ? "PH" : "01" }),
      )?.density ?? null,
    ).toBeNull();
  expect(facilityContext(build(datasets, { mode: "district", districtCode: "test" }))).toBeNull();
});
it("rejects partial, suppressed, malformed and wrong-release denominators and zero population", () => {
  for (const patch of [
    { state: "partial" },
    { state: "suppressed" },
    { state: "failed" },
    { unit: "households" },
    { sourceSlug: "future" },
    { values: [{ value: 0 }] },
    { values: [{ value: -1 }] },
    { values: [{ value: 10000 }, { value: 10000 }] },
  ] as Partial<CatalogIndicator>[]) {
    const data = [
      datasets[0],
      { ...datasets[1], indicators: [{ ...datasets[1].indicators[0], ...patch }] },
    ];
    expect(facilityContext(build(data))?.density).toBeNull();
  }
  expect(
    facilityContext(build([datasets[0], { ...datasets[1], sources: [] }]))?.density,
  ).toBeNull();
});
it("uses UUC's own denominator and never reconstructs a suppressed poverty estimate", () => {
  const uuc = make("uuc-phc", "uuc.listed", "uuc-phc-2025", "barangays", 2, "2025-01-01");
  uuc.indicators[0].values = [{ value: 2, denominator: 8 }];
  const poverty = make(
    "poverty",
    "poverty.incidence",
    "psa-sae-poverty-2023",
    "%",
    17,
    "2023-12-31",
  );
  poverty.indicators[0].state = "suppressed";
  const result = facilityContext(build([...datasets, uuc, poverty]));
  expect(result?.uucShare).toBe(25);
  expect(result?.povertyItems).toEqual([]);
  uuc.indicators[0].values = [{ value: 2, denominator: 1 }];
  expect(facilityContext(build([...datasets, uuc]))?.uucShare).toBeNull();
});
it("names the UUC list's year from the edition read, even where this place has no usable count", () => {
  const read2025 = make("uuc-phc", "uuc.listed", "uuc-phc-2025", "barangays", 2, "2025-01-01");
  read2025.indicators[0].values = [];
  expect(facilityContext(build([...datasets, read2025]))).toMatchObject({
    uuc: null,
    uucYear: "2025",
  });
  const read2026 = make("uuc-phc", "uuc.listed", "uuc-phc-2026", "barangays", 2, "2026-01-01");
  expect(facilityContext(build([...datasets, read2026]))?.uucYear).toBe("2026");
  expect(facilityContext(build([...datasets, { ...read2026, sources: [] }]))?.uucYear).toBeNull();
});
