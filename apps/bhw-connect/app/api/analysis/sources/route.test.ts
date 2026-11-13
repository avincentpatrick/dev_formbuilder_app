import { beforeEach, describe, it, expect, vi } from "vitest";
const { auth, call, save, remove } = vi.hoisted(() => ({
  auth: vi.fn(),
  call: vi.fn(),
  save: vi.fn(),
  remove: vi.fn(),
}));
vi.mock("@/lib/db/require-analysis", () => ({ getAnalysisAuthResult: auth }));
vi.mock("@/lib/analysis-sources/db", () => ({ sourceCall: call }));
vi.mock("@/lib/analysis-sources/storage", () => ({ saveOriginal: save, deleteOriginal: remove }));
const { POST, PATCH, GET } = await import("./route");
const id = "00000000-0000-4000-8000-000000000001";
const req = () =>
  new Request(`http://localhost/api/analysis/sources?projectId=${id}`, {
    method: "POST",
    headers: { "X-File-Name": "synthetic.csv" },
    body: "count\n12",
  });
beforeEach(() => {
  vi.clearAllMocks();
  auth.mockResolvedValue({ status: "approved", user: { id } });
  call.mockImplementation(async (_a: string, _p: string, op: string) =>
    op === "access" ? { role: "owner" } : op === "create" ? { id, status: "uploading" } : [],
  );
});
describe("attachment API access and immutable storage", () => {
  it("denies anonymous and viewer uploads before writing bytes", async () => {
    auth.mockResolvedValueOnce({ status: "signed_out" });
    expect((await POST(req())).status).toBe(401);
    call.mockResolvedValueOnce({ role: "viewer" });
    expect((await POST(req())).status).toBe(403);
    expect(save).not.toHaveBeenCalled();
  });
  it("hashes and stores the original with the session actor and project", async () => {
    expect((await POST(req())).status).toBe(200);
    expect(save).toHaveBeenCalledWith(id, id, expect.any(Buffer));
    expect(call).toHaveBeenCalledWith(
      id,
      id,
      "create",
      expect.objectContaining({
        kind: "file",
        name: "synthetic.csv",
        bytes: 8,
        sha256: expect.stringMatching(/^[a-f0-9]{64}$/),
      }),
    );
  });
  it("does not list sources after approval revocation or mutate as a viewer", async () => {
    auth.mockResolvedValueOnce({ status: "unapproved" });
    expect((await GET(new Request(`http://localhost/?projectId=${id}`))).status).toBe(403);
    call.mockRejectedValueOnce({ code: "42501" });
    const response = await PATCH(
      new Request("http://localhost/", {
        method: "PATCH",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ projectId: id, sourceId: id, operation: "delete" }),
      }),
    );
    expect(response.status).toBe(403);
    expect(remove).not.toHaveBeenCalled();
  });
});
