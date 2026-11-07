import { describe, expect, it } from "vitest";
import { formatValue, omissionReason, previewIndicators } from "./presentation";
import type { CatalogDataset } from "@/lib/catalog/types";
const dataset: CatalogDataset = {
  id: "nhfr",
  title: "NHFR",
  category: "Facilities",
  state: "partial",
  availableIndicators: 1,
  totalIndicators: 9,
  supportedLevels: ["national"],
  detailHref: "/facilities",
  sources: [],
  indicators: [
    {
      id: "nhfr.facilities",
      label: "Facilities",
      unit: "facilities",
      state: "available",
      reason: null,
      sourceSlug: "nhfr",
      values: [{ value: 0 }],
      reportedAreaRecords: 1,
    },
    {
      id: "nhfr.type.hospital",
      label: "Hospitals",
      unit: "facilities",
      state: "failed",
      reason: "Failed",
      sourceSlug: "nhfr",
      values: [],
      reportedAreaRecords: null,
    },
  ],
};
describe("catalog presentation", () => {
  it("keeps zero previews and excludes failed peers", () => {
    expect(previewIndicators(dataset).map((i) => i.id)).toEqual(["nhfr.facilities"]);
    expect(formatValue({ value: 0 }, "facilities")).toBe("0 facilities");
    expect(formatValue({ value: 1 }, "facilities")).toBe("1 facility");
    expect(formatValue({ value: 1 }, "barangays")).toBe("1 barangay");
    expect(formatValue({ value: 120 }, "percent (0-100, may exceed)")).toBe("120%");
    expect(formatValue({ value: 500 }, "per 100,000")).toBe("500 per 100,000");
  });
  it("explains failures without asserting no source data", () => {
    expect(omissionReason({ ...dataset, state: "failed" }, { mode: "all" })).toContain(
      "Could not load",
    );
    expect(omissionReason({ ...dataset, state: "suppressed" }, { mode: "all" })).toContain(
      "withheld",
    );
  });
});
