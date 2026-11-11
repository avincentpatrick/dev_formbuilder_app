import { describe, expect, it } from "vitest";
import { isReleasePublic, PROFILING_STATUS_SLUG } from "./visibility";

describe("release visibility", () => {
  it("hides the 2026 profiling-status snapshot", () => {
    expect(isReleasePublic(PROFILING_STATUS_SLUG)).toBe(false);
  });
  it("keeps every other release public, NHFR September 2026 included", () => {
    for (const slug of ["bhw-2025", "bhw-stepzero-2026", "nhfr-2026-09", "uuc-phc-2025"])
      expect(isReleasePublic(slug)).toBe(true);
  });
});
