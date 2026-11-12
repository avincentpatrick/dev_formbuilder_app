import { beforeEach, describe, expect, it, vi } from "vitest";
const { auth, loop, cache } = vi.hoisted(() => ({ auth: vi.fn(), loop: vi.fn(), cache: vi.fn() }));
vi.mock("@/lib/db/require-analysis", () => ({ getAnalysisAuthResult: auth }));
vi.mock("@/lib/ai/agent-loop", () => ({ runToolLoop: loop }));
vi.mock("@/lib/ai/ask-cache", () => ({ lookupAskCache: cache, lookupAskCacheNearMatch: cache }));
const { POST: legacy } = await import("./route");
const { POST: internal } = await import("../assistant/route");
const { POST: current } = await import("../../analysis/chat/route");
beforeEach(() => {
  vi.clearAllMocks();
});
for (const [name, post] of [
  ["legacy", legacy],
  ["internal", internal],
  ["current", current],
] as const) {
  describe(name + " answering boundary", () => {
    for (const status of ["signed_out", "unapproved"] as const) {
      it("denies " + status + " before parsing, cache or providers", async () => {
        auth.mockResolvedValue({ status, user: null });
        const response = await post(
          new Request("http://localhost/api", { method: "POST", body: "invalid" }),
        );
        expect(response.status).toBe(status === "signed_out" ? 401 : 403);
        expect(cache).not.toHaveBeenCalled();
        expect(loop).not.toHaveBeenCalled();
      });
    }
    it("denies a newly revoked user on their next request", async () => {
      auth.mockResolvedValueOnce({ status: "approved", user: { id: "synthetic" } });
      expect(
        (await post(new Request("http://localhost/api", { method: "POST", body: "{}" }))).status,
      ).toBe(400);
      auth.mockResolvedValueOnce({ status: "unapproved", user: { id: "synthetic" } });
      expect(
        (await post(new Request("http://localhost/api", { method: "POST", body: "{}" }))).status,
      ).toBe(403);
      expect(loop).not.toHaveBeenCalled();
    });
  });
}
