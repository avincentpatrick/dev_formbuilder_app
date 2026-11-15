import { expect, it } from "vitest";
import { BHW_MEASURES, bhwMeasureHref, parseBhwSearch } from "./search";

it("separates the indicator phrase before geography matching and preserves accreditation ambiguity", () => {
  for (const query of ["Cebu accreditation", "accreditation in Cebu", "accreditation Cebu"]) {
    const intent = parseBhwSearch(query);
    expect(intent.placeQuery).toBe("cebu");
    expect(intent.measures.map((s) => s.id)).toEqual([
      "bhw.declared-accreditation",
      "bhw.profile-accreditation",
    ]);
  }
  expect(parseBhwSearch("Cebu LGU accreditation").measures.map((s) => s.id)).toEqual([
    "bhw.declared-accreditation",
  ]);
  expect(parseBhwSearch("Cebu profile accreditation").measures.map((s) => s.id)).toEqual([
    "bhw.profile-accreditation",
  ]);
});

it("handles indicator-only queries and every catalog measure without importing hidden releases", () => {
  expect(parseBhwSearch("headcount").placeQuery).toBe("");
  for (const spec of BHW_MEASURES) {
    expect(parseBhwSearch(spec.id).measures.map((s) => s.id)).toContain(spec.id);
  }
  expect(BHW_MEASURES).toHaveLength(66);
  expect(parseBhwSearch("Cebu basic life support").measures[0].id).toBe(
    "bhw.training.basic_life_support",
  );
});

it("retains place names/codes and distinguishes unsupported actions from empty input", () => {
  expect(parseBhwSearch("Poblacion").placeQuery).toBe("Poblacion");
  expect(parseBhwSearch("0102801001").placeQuery).toBe("0102801001");
  expect(parseBhwSearch(" ").state).toBe("empty");
  for (const q of [
    "Compare Cebu and Bohol accreditation",
    "Cebu vs Bohol",
    "forecast headcount",
    "x".repeat(101),
  ]) {
    expect(parseBhwSearch(q).state).toBe("unsupported");
  }
  expect(bhwMeasureHref("bhw.total", "barangay", "0102801001")).toBe(
    "/bhw/measure?indicator=bhw.total&location=barangay%3A0102801001",
  );
});
