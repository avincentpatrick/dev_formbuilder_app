import { expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { FacilityAnalysis } from "./facility-analysis";
const props = {
  types: [{ facilityType: "Clinic", nFacilities: 3, nGovernment: 1, nPrivate: 2 }],
  areas: [],
  total: 3,
  expectedCodes: [],
  selection: { mode: "psgc", level: "national", code: "PH" } as const,
};
it("explains each denominator and keeps unavailable geography out of rankings", () => {
  const html = renderToStaticMarkup(<FacilityAnalysis {...props} />);
  expect(html).toContain("3 (100%)");
  expect(html).toContain("1 (33.3%)");
  expect(html).toContain("2 (66.7%)");
  expect(html).toContain("No ranking is shown");
  expect(html).toContain("not adjusted for population");
});
it("does not chart partial types as a full partition or manufacture shares at zero", () => {
  expect(renderToStaticMarkup(<FacilityAnalysis {...props} total={4} />)).toContain(
    "cannot be compared",
  );
  expect(renderToStaticMarkup(<FacilityAnalysis {...props} total={0} types={[]} />)).toContain(
    "No registered facilities to divide",
  );
});
