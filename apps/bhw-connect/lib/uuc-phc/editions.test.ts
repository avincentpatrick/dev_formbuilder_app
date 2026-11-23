import { describe, expect, it } from "vitest";
import {
  ISSUANCE_PLACEHOLDER,
  UUC_EDITIONS,
  editionByYear,
  editionIssuanceLine,
  pickEdition,
  previousEdition,
  withEdition,
} from "./editions";

const E2026 = editionByYear("2026")!;
const E2025 = editionByYear("2025")!;

describe("UUC for PHC editions", () => {
  it("lists editions newest first, each its own dataset", () => {
    expect(UUC_EDITIONS.map((e) => e.slug)).toEqual(["uuc-phc-2026", "uuc-phc-2025"]);
    expect(previousEdition(E2026)).toBe(E2025);
    expect(previousEdition(E2025)).toBeUndefined();
  });

  it("flags an unconfirmed issuance, so the placeholder cannot pass for a circular number", () => {
    for (const e of UUC_EDITIONS)
      expect(e.issuanceConfirmed).toBe(e.issuance !== ISSUANCE_PLACEHOLDER);
    expect(E2025.issuance).toBe("DC No. 2025-0549");
    expect(editionIssuanceLine(E2025)).toBe(
      "Issued under DC No. 2025-0549 · criteria per DOH AO No. 2020-0023",
    );
  });

  it("opens on the newest loaded edition, and honours a request only for a loaded one", () => {
    expect(pickEdition(null, [E2026, E2025])).toBe(E2026);
    expect(pickEdition("2025", [E2026, E2025])).toBe(E2025);
    // 2026 not loaded (CI, the local copy): the section opens on 2025 and a 2026 link lands there.
    expect(pickEdition(null, [E2025])).toBe(E2025);
    expect(pickEdition("2026", [E2025])).toBe(E2025);
    expect(pickEdition("1999", [E2026, E2025])).toBe(E2026);
    // Nothing readable at all: still a real edition, never undefined.
    expect(pickEdition(null, [])).toBe(E2025);
  });

  it("keeps the default edition's links clean and names any other", () => {
    expect(withEdition("/uuc-phc/region/01", E2026, E2026)).toBe("/uuc-phc/region/01");
    expect(withEdition("/uuc-phc/region/01", E2025, E2026)).toBe("/uuc-phc/region/01?edition=2025");
    expect(withEdition("/uuc-phc?from=x#regions", E2025, E2026)).toBe(
      "/uuc-phc?from=x&edition=2025#regions",
    );
  });
});
