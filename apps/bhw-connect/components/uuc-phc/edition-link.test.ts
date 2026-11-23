import { describe, expect, it } from "vitest";
import { carryEdition } from "./edition-link";

describe("carryEdition", () => {
  it("carries the edition on screen onto section links only", () => {
    expect(carryEdition("/uuc-phc/criteria", "2025")).toBe("/uuc-phc/criteria?edition=2025");
    expect(carryEdition("/uuc-phc/methodology#ask", "2025")).toBe(
      "/uuc-phc/methodology?edition=2025#ask",
    );
    expect(carryEdition("/explore", "2025")).toBe("/explore");
  });

  it("leaves links alone on the default edition, and never doubles the parameter", () => {
    expect(carryEdition("/uuc-phc/criteria", null)).toBe("/uuc-phc/criteria");
    expect(carryEdition("/uuc-phc?edition=2026", "2025")).toBe("/uuc-phc?edition=2026");
  });
});
