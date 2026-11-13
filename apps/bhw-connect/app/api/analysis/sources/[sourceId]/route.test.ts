import { it, expect, vi } from "vitest";
const { auth, call, read } = vi.hoisted(() => ({ auth: vi.fn(), call: vi.fn(), read: vi.fn() }));
vi.mock("@/lib/db/require-analysis", () => ({ getAnalysisAuthResult: auth }));
vi.mock("@/lib/analysis-sources/db", () => ({ sourceCall: call }));
vi.mock("@/lib/analysis-sources/storage", () => ({ readOriginal: read }));
const { GET } = await import("./route");
it("checks project access before exposing original file bytes", async () => {
  const id = "00000000-0000-4000-8000-000000000001";
  auth.mockResolvedValue({ status: "approved", user: { id } });
  call.mockRejectedValueOnce({ code: "42501" });
  expect(
    (
      await GET(new Request(`http://localhost/?projectId=${id}`), {
        params: Promise.resolve({ sourceId: id }),
      })
    ).status,
  ).toBe(403);
  expect(read).not.toHaveBeenCalled();
});
