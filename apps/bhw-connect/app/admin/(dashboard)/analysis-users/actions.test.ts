import { beforeEach, describe, expect, it, vi } from "vitest";
const { admin, upsert } = vi.hoisted(() => ({ admin: vi.fn(), upsert: vi.fn() }));
vi.mock("@/lib/db/require-admin", () => ({ getAdminUser: admin }));
vi.mock("@/lib/db/service-client", () => ({
  createSupabaseServiceClient: () => ({ from: () => ({ upsert }) }),
}));
vi.mock("next/cache", () => ({ revalidatePath: vi.fn() }));
vi.mock("next/navigation", () => ({
  redirect: (url: string) => {
    throw new Error(url);
  },
}));
const { setAnalysisAccess } = await import("./actions");
const userId = "00000000-0000-4000-8000-000000000001";
function form(decision = "approve", id = userId) {
  const f = new FormData();
  f.set("userId", id);
  f.set("decision", decision);
  return f;
}
beforeEach(() => {
  vi.clearAllMocks();
  admin.mockResolvedValue({ id: userId, role: "admin" });
  upsert.mockResolvedValue({ error: null });
});
describe("analysis approval administration", () => {
  it("denies signed-out users and editors before a database mutation", async () => {
    for (const actor of [null, { id: userId, role: "editor" }]) {
      admin.mockResolvedValueOnce(actor);
      await expect(setAnalysisAccess(form())).rejects.toThrow("Administrator access required");
    }
    expect(upsert).not.toHaveBeenCalled();
  });
  it("validates target identity and decision", async () => {
    await expect(setAnalysisAccess(form("approve", "invalid"))).rejects.toThrow("result=invalid");
    await expect(setAnalysisAccess(form("unexpected"))).rejects.toThrow("result=invalid");
    expect(upsert).not.toHaveBeenCalled();
  });
  it("records approve/revoke and the session actor", async () => {
    for (const decision of ["approve", "revoke"]) {
      const f = form(decision);
      f.set("updated_by", "forged");
      await expect(setAnalysisAccess(f)).rejects.toThrow("result=saved");
      expect(upsert).toHaveBeenLastCalledWith(
        expect.objectContaining({
          user_id: userId,
          approved: decision === "approve",
          updated_by: userId,
        }),
      );
    }
  });
  it("does not claim success when the write fails", async () => {
    upsert.mockResolvedValueOnce({ error: { message: "offline" } });
    await expect(setAnalysisAccess(form())).rejects.toThrow("result=failed");
  });
});
