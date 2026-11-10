import { describe, expect, it } from "vitest";
import { datasetState, summarizeCatalog } from "./summary";
import type { CatalogDataset, CatalogIndicator } from "./types";
const indicator = (state: CatalogIndicator["state"], id = "canonical"): CatalogIndicator => ({
  id,
  label: "Measure",
  unit: "people",
  state,
  sourceSlug: "accepted",
  reason: null,
  values: [],
  reportedAreaRecords: null,
});
function dataset(indicators: CatalogIndicator[]): CatalogDataset {
  return {
    id: "bhw",
    title: "BHW",
    category: "Workforce",
    state: "partial",
    availableIndicators: indicators.filter((i) => ["available", "partial"].includes(i.state))
      .length,
    totalIndicators: indicators.length,
    supportedLevels: ["national"],
    detailHref: null,
    indicators,
    sources: [
      { slug: "accepted", name: "Source", sourceName: "DOH", asOfDate: null, lastUpdatedAt: null },
    ],
  };
}
describe("overview semantics", () => {
  it("does not double count canonical indicators or source dates across appearances", () => {
    const d = dataset([
      indicator("available"),
      indicator("partial"),
      indicator("failed", "failed"),
      indicator("suppressed", "suppressed"),
    ]);
    const result = summarizeCatalog([d]);
    expect(result.availableIndicators).toBe(1);
    expect(result.totalIndicators).toBe(3);
    expect(result.failedIndicators).toBe(1);
    expect(result.suppressedIndicators).toBe(1);
    expect(result.freshness).toEqual({
      oldestSourceDate: null,
      newestSourceDate: null,
      latestUpdate: null,
      sourcesWithUnknownDate: 1,
    });
  });
  it("compares timestamps chronologically, preserving missing dates rather than guessing", () => {
    const d = dataset([
      indicator("available"),
      { ...indicator("available", "second"), sourceSlug: "second" },
    ]);
    d.sources = [
      {
        slug: "accepted",
        name: "First",
        sourceName: null,
        asOfDate: "2024-01-01",
        lastUpdatedAt: "2026-11-07T10:00:00+08:00",
      },
      {
        slug: "second",
        name: "Second",
        sourceName: null,
        asOfDate: "2025-01-01",
        lastUpdatedAt: "2026-11-07T03:00:00Z",
      },
    ];
    expect(summarizeCatalog([d]).freshness).toEqual({
      oldestSourceDate: "2024-01-01",
      newestSourceDate: "2025-01-01",
      latestUpdate: "2026-11-07T03:00:00Z",
      sourcesWithUnknownDate: 0,
    });
  });
  it("keeps partial availability, suppression, failure and unsupported distinct", () => {
    expect(datasetState(["available", "failed"])).toBe("partial");
    expect(datasetState(["partial", "available"])).toBe("partial");
    expect(datasetState(["suppressed", "unavailable"])).toBe("suppressed");
    expect(datasetState(["unavailable", "failed"])).toBe("failed");
    expect(datasetState(["unsupported", "unsupported"])).toBe("unsupported");
    expect(datasetState(["available", "available"])).toBe("available");
  });
});
