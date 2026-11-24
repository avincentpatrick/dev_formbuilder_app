import { expect, it } from "vitest";
import { headlineRows, headlineSvg, headlinePng } from "./nhfr-headline";
const summary = {
  total: 4,
  count: 3,
  government: 2,
  private: 1,
  beds: 12,
  present: 1,
  barangays: 3,
  unattributed: 1,
  types: ["Hospital", "Barangay Health Station"],
};
it("exports unrounded shares and explicit undefined percentages at zero denominators", () => {
  expect(
    headlineRows(summary, ["Hospital"]).find((r) => r.indicator === "Recorded barangay presence")
      ?.value,
  ).toBe(100 / 3);
  const zero = {
    ...summary,
    count: 0,
    government: 0,
    private: 0,
    present: 0,
    unattributed: 0,
    barangays: 0,
  };
  expect(
    headlineRows(zero, []).find((r) => r.indicator === "Recorded barangay presence")?.value,
  ).toBeNull();
  expect(headlineRows(zero, []).find((r) => r.indicator === "Government share")?.value).toBeNull();
});
it("omits beds for station-only headlines and includes them for hospital combinations", () => {
  expect(headlineRows(summary, ["Barangay Health Station"]).some((r) => r.unit === "beds")).toBe(
    false,
  );
  expect(
    headlineRows(summary, ["Barangay Health Station", "Hospital"]).some((r) => r.unit === "beds"),
  ).toBe(true);
});
it("escapes source labels, preserves all selected types and makes technical notes optional", async () => {
  const types = ["Hospital", "Rural Health Unit"];
  const plain = headlineSvg(summary, types, "A < B & C");
  expect(plain.svg).toContain("A &lt; B &amp; C");
  expect(plain.svg).toContain("Hospital; Rural Health Unit");
  expect(plain.svg).not.toContain("About these figures");
  const noted = headlineSvg(summary, types, "Synthetic", true);
  expect(noted.svg).toContain("About these figures");
  expect(noted.height).toBeGreaterThan(plain.height);
  const png = await headlinePng(plain.svg);
  expect([...png.slice(0, 8)]).toEqual([137, 80, 78, 71, 13, 10, 26, 10]);
});
