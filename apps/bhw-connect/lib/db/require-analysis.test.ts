import { beforeEach, describe, expect, it, vi } from "vitest";
const { identity, result, select, eq } = vi.hoisted(() => ({
  identity: vi.fn(),
  result: vi.fn(),
  select: vi.fn(),
  eq: vi.fn(),
}));
vi.mock("./meridian-identity", () => ({ getMeridianIdentity: identity }));
vi.mock("./service-client", () => ({
  createSupabaseServiceClient: () => ({ from: () => ({ select }) }),
}));
const { getAnalysisAuthResult } = await import("./require-analysis");
const user = {
  id: "00000000-0000-4000-8000-000000000001",
  name: "Synthetic",
  email: "test@example.test",
};
beforeEach(() => {
  vi.clearAllMocks();
  identity.mockResolvedValue({ status: "signed_in", user });
  select.mockReturnValue({ eq });
  eq.mockReturnValue({ maybeSingle: result });
  result.mockResolvedValue({ data: null, error: null });
});
describe("analysis approval", () => {
  it("does not query approval for signed-out or refused identities", async () => {
    identity.mockResolvedValueOnce({ status: "signed_out" });
    expect(await getAnalysisAuthResult()).toEqual({ status: "signed_out" });
    identity.mockResolvedValueOnce({ status: "refused" });
    expect(await getAnalysisAuthResult()).toEqual({ status: "unapproved", user: null });
    expect(select).not.toHaveBeenCalled();
  });
  it("requires an explicit true approval, even for an administrator identity", async () => {
    for (const data of [null, { approved: false }]) {
      result.mockResolvedValueOnce({ data, error: null });
      expect((await getAnalysisAuthResult()).status).toBe("unapproved");
    }
    result.mockResolvedValueOnce({ data: { approved: true }, error: null });
    expect(await getAnalysisAuthResult()).toEqual({ status: "approved", user });
    expect(eq).toHaveBeenCalledWith("user_id", user.id);
  });
  it("reads fresh approval after revocation", async () => {
    result
      .mockResolvedValueOnce({ data: { approved: true } })
      .mockResolvedValueOnce({ data: { approved: false } });
    expect((await getAnalysisAuthResult()).status).toBe("approved");
    expect((await getAnalysisAuthResult()).status).toBe("unapproved");
    expect(result).toHaveBeenCalledTimes(2);
  });
  it("fails closed on database and identity errors", async () => {
    result.mockResolvedValueOnce({ data: { approved: true }, error: { message: "failure" } });
    expect((await getAnalysisAuthResult()).status).toBe("unapproved");
    identity.mockRejectedValueOnce(new Error("offline"));
    expect((await getAnalysisAuthResult()).status).toBe("signed_out");
  });
});
