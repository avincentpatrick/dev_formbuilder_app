import { beforeEach, describe, it, expect, vi } from "vitest";
const { auth, call } = vi.hoisted(() => ({ auth: vi.fn(), call: vi.fn() }));
vi.mock("@/lib/db/require-analysis", () => ({ getAnalysisAuthResult: auth }));
vi.mock("@/lib/analysis-projects/db", () => ({ projectCall: call }));
const { GET, POST } = await import("./route");
const id = "00000000-0000-4000-8000-000000000001";
const request = (body: unknown) =>
  new Request("http://localhost/api/analysis/projects", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
beforeEach(() => {
  vi.clearAllMocks();
  auth.mockResolvedValue({ status: "approved", user: { id } });
  call.mockResolvedValue({ id });
});
describe("project API boundary", () => {
  it("denies anonymous/revoked users before project operations", async () => {
    for (const status of ["signed_out", "unapproved"]) {
      auth.mockResolvedValueOnce({ status });
      expect((await GET()).status).toBe(status === "signed_out" ? 401 : 403);
    }
    expect(call).not.toHaveBeenCalled();
  });
  it("rejects browser form content types before mutation", async () => {
    const response = await POST(
      new Request("http://localhost/api/analysis/projects", {
        method: "POST",
        body: JSON.stringify({ operation: "create", requestId: id }),
      }),
    );
    expect(response.status).toBe(415);
    expect(call).not.toHaveBeenCalled();
  });
  it("takes the actor from the session, not JSON", async () => {
    expect(
      (await POST(request({ operation: "create", requestId: id, actor: "forged" }))).status,
    ).toBe(200);
    expect(call).toHaveBeenCalledWith(id, "create", undefined, {
      operation: "create",
      requestId: id,
    });
  });
  it("rejects invalid target IDs and surfaces conflicts without leaking database messages", async () => {
    expect((await POST(request({ operation: "get", projectId: "bad" }))).status).toBe(400);
    call.mockRejectedValueOnce({ code: "40001", message: "private SQL details" });
    const response = await POST(request({ operation: "get", projectId: id }));
    expect(response.status).toBe(409);
    expect(await response.text()).not.toContain("private SQL");
  });
});

it("keeps full calculation input snapshots out of routine project polling", async () => {
  call.mockResolvedValueOnce({
    id,
    jobs: [
      {
        id,
        result: {
          answer: "Verified summary",
          events: [],
          statistics: [{ manifest: { rows: [{ privateValue: 123 }] } }],
        },
      },
    ],
  });
  const response = await POST(request({ operation: "get", projectId: id }));
  const body = await response.json();
  expect(body.jobs[0].result).toEqual({ answer: "Verified summary", events: [] });
});
