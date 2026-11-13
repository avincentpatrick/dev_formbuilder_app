import { describe, it, expect, vi } from "vitest";
import { validatePublicUrl, searchOnline } from "./web";
describe("public retrieval boundary", () => {
  it("rejects loopback/private/special destinations and credentials", async () => {
    for (const url of [
      "http://example.com",
      "https://127.0.0.1",
      "https://[::1]",
      "https://10.0.0.1",
      "https://169.254.169.254",
      "https://user:pass@example.com",
      "https://example.com:8443",
    ])
      await expect(validatePublicUrl(url)).rejects.toThrow();
  });
  it("rejects DNS results containing a private address and pins a public result", async () => {
    const resolver = vi.fn(async () => [{ address: "127.0.0.1", family: 4 }]);
    await expect(validatePublicUrl("https://example.com", resolver as never)).rejects.toThrow(
      "Private",
    );
    resolver.mockResolvedValueOnce([{ address: "8.8.8.8", family: 4 }]);
    expect((await validatePublicUrl("https://example.com", resolver as never)).address).toBe(
      "8.8.8.8",
    );
  });
  it("reports unavailable search configuration without pretending to have searched", async () => {
    vi.stubEnv("BHW_WEB_SEARCH_API_KEY", "");
    expect(await searchOnline("synthetic query")).toMatchObject({ available: false });
    vi.unstubAllEnvs();
  });
});
