import { describe, expect, it } from "vitest";
import { siteUrl, VERCEL_SITE_URL } from "./site-url";

describe("siteUrl", () => {
  it("falls back to the Vercel address the app hard-coded, so an unconfigured build is unchanged", () => {
    expect(siteUrl(undefined)).toBe(VERCEL_SITE_URL);
    expect(siteUrl("  ")).toBe(VERCEL_SITE_URL);
  });

  it("keeps the base path and drops a trailing slash", () => {
    expect(siteUrl("https://staging.pitahc.gov.ph/bhw-connect/")).toBe(
      "https://staging.pitahc.gov.ph/bhw-connect",
    );
  });
});
