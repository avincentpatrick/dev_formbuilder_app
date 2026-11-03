import { describe, expect, it } from "vitest";
import { meridianUrl } from "./meridian-url";

describe("meridianUrl", () => {
  it("is Meridian's page on the origin this app is served from, with no base path", () => {
    expect(meridianUrl("/login", "https://staging.pitahc.gov.ph/bhw-connect")).toBe("https://staging.pitahc.gov.ph/login");
    expect(meridianUrl("/", "https://staging.pitahc.gov.ph/bhw-connect/")).toBe("https://staging.pitahc.gov.ph/");
  });

  it("is null where no Meridian runs beside this app", () => {
    expect(meridianUrl("/login", undefined)).toBeNull();
    expect(meridianUrl("/login", "  ")).toBeNull();
    expect(meridianUrl("/login", "not a url")).toBeNull();
  });
});
