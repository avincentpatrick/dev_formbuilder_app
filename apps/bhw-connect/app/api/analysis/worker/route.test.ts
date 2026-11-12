import { beforeEach, afterEach, describe, it, expect, vi } from "vitest";
const { call, execute } = vi.hoisted(() => ({ call: vi.fn(), execute: vi.fn() }));
vi.mock("@/lib/analysis-projects/db", () => ({ workerCall: call }));
vi.mock("@/lib/ai/analysis-chat", () => ({
  analysisInputSchema: { parse: (v: unknown) => v },
  analysisResponse: execute,
}));
const { POST } = await import("./route");
const ask = (secret?: string) =>
  new Request("http://localhost/api/analysis/worker", {
    method: "POST",
    headers: secret ? { authorization: `Bearer ${secret}` } : {},
  });
beforeEach(() => {
  vi.clearAllMocks();
  vi.stubEnv("CRON_SECRET", "synthetic-test-secret");
  call.mockImplementation(async (op: string) =>
    op === "claim" ? { id: "job", lease_id: "lease", input: { messages: [] } } : { active: true },
  );
  execute.mockResolvedValue(
    new Response(JSON.stringify({ type: "message", content: "Synthetic answer" }) + "\n"),
  );
});
afterEach(() => vi.unstubAllEnvs());
describe("durable worker gate and publication", () => {
  it("requires the configured secret before claiming a job", async () => {
    expect((await POST(ask())).status).toBe(401);
    vi.stubEnv("CRON_SECRET", "");
    expect((await POST(ask("synthetic-test-secret"))).status).toBe(401);
    expect(call).not.toHaveBeenCalled();
  });
  it("saves audited results only with the claimed lease", async () => {
    expect((await POST(ask("synthetic-test-secret"))).status).toBe(200);
    expect(call).toHaveBeenCalledWith(
      "finish",
      "job",
      "lease",
      expect.objectContaining({ result: expect.objectContaining({ answer: "Synthetic answer" }) }),
    );
  });
  it("does not publish after a cancelled/revoked lease", async () => {
    call.mockImplementation(async (op: string) =>
      op === "claim"
        ? { id: "job", lease_id: "lease", input: { messages: [] } }
        : { active: false },
    );
    const response = await POST(ask("synthetic-test-secret"));
    expect(await response.json()).toEqual({ worked: true, published: false });
    expect(call.mock.calls.some((c) => c[0] === "finish")).toBe(false);
  });
  it("records provider capacity failures as retryable failed jobs", async () => {
    execute.mockResolvedValueOnce(
      new Response(JSON.stringify({ type: "capacity", message: "No provider available" }) + "\n"),
    );
    await POST(ask("synthetic-test-secret"));
    expect(call).toHaveBeenCalledWith(
      "fail",
      "job",
      "lease",
      expect.objectContaining({ error: "No provider available" }),
    );
  });
});
