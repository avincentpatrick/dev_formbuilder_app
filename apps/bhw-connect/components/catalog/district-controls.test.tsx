import { expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { parseHTML } from "linkedom";
import { DistrictControls } from "./district-controls";
import type { DistrictGeography } from "@/lib/catalog/district-geography";
it("offers only constituent barangays and retains district/city fields in the leaf form", () => {
  const included = {
    code: "01001001",
    name: "Included",
    level: "barangay" as const,
    parentCode: "01001",
  };
  const outside = { ...included, code: "01001002", name: "Outside" };
  const geography: Extract<DistrictGeography, { state: "available" }> = {
    state: "available",
    choices: [{ code: "d1", name: "District 1", congress: 20 }],
    current: { code: "d1", name: "District 1", congress: 20 },
    selection: { mode: "district", districtCode: "d1", citymunCode: "01001" },
    scope: {
      issues: [],
      units: [included],
      cities: [
        {
          code: "01001",
          name: "Split city",
          level: "citymun",
          parentCode: "01",
          whole: false,
          barangays: [included],
          allBarangays: [included, outside],
        },
      ],
    },
    receipts: [],
    ownGaps: 1,
    globalGaps: { cities: 2, barangays: 3 },
  };
  const { document } = parseHTML(renderToStaticMarkup(<DistrictControls geography={geography} />));
  const form = document.querySelector('select[name="barangay"]')!.closest("form")!;
  expect(form.querySelector('input[name="district"]')?.getAttribute("value")).toBe("d1");
  expect(form.querySelector('input[name="citymun"]')?.getAttribute("value")).toBe("01001");
  expect([...form.querySelectorAll("option")].map((o) => o.getAttribute("value"))).toEqual([
    "",
    "01001001",
  ]);
  expect(document.documentElement.textContent).toContain("district barangays only");
  expect(document.querySelector('a[href="/catalog?mode=all"]')).not.toBeNull();
});
