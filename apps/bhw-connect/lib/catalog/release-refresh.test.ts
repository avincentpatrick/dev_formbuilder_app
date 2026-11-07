import { expect, it, vi } from "vitest";
import type { CatalogReader } from "./reader";

// Simulate the deliberate registry edit used to accept a replacement snapshot.
vi.mock("./definitions", async (original) => {
  const actual = await original<typeof import("./definitions")>();
  return {
    ...actual,
    CATALOG: actual.CATALOG.map((dataset) =>
      dataset.id === "nhfr"
        ? {
            ...dataset,
            indicators: dataset.indicators.map((spec) => ({
              ...spec,
              source: "nhfr-replacement-fixture",
            })),
          }
        : dataset,
    ),
  };
});
import { getPublicCatalog } from "./catalog";

it("switches an accepted release and refreshes its metadata without duplicate identities or stale values", async () => {
  let datasetId = 71;
  const source = vi.fn<CatalogReader["source"]>(async (slug) =>
    slug === "nhfr-replacement-fixture"
      ? {
          state: "available",
          value: {
            datasetId,
            slug,
            name: "Replacement fixture",
            sourceName: "Synthetic NHFR",
            license: null,
            asOfDate: "2026-10-01",
            lastUpdatedAt: `2026-11-0${datasetId === 71 ? 1 : 2}T00:00:00Z`,
          },
        }
      : { state: "unavailable" },
  );
  const rows = vi.fn<CatalogReader["rows"]>(async (_spec, id) => ({
    state: "available",
    rows: [{ n_facilities: id === 71 ? 10 : 12 }],
  }));
  const reader: CatalogReader = {
    source,
    rows,
    privacy: async () => "clear",
    probe: async () => ({ state: "available", count: 1, hasSuppressed: false }),
    fhsisDefinitions: async () => ({ state: "available", rows: [] }),
  };
  const selection = { mode: "psgc", level: "citymun", code: "0102801" } as const;
  const first = await getPublicCatalog(selection, reader);
  datasetId = 72;
  const next = await getPublicCatalog(selection, reader);
  for (const result of [first, next]) {
    expect(result.datasets).toHaveLength(6);
    expect(result.datasets.filter((d) => d.id === "nhfr")).toHaveLength(1);
    expect(result.overview.totalIndicators).toBe(110);
  }
  const before = first.datasets.find((d) => d.id === "nhfr")!;
  const after = next.datasets.find((d) => d.id === "nhfr")!;
  expect(before.indicators[0].values[0].value).toBe(10);
  expect(after.indicators[0].values[0].value).toBe(12);
  expect(after.sources[0].lastUpdatedAt).not.toBe(before.sources[0].lastUpdatedAt);
  expect(source.mock.calls.some(([slug]) => slug === "nhfr-2026-09")).toBe(false);
  expect(after.indicators.map((i) => i.id)).toEqual(before.indicators.map((i) => i.id));
});
