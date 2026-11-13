import { beforeEach, describe, it, expect, vi } from "vitest";
const m = vi.hoisted(() => ({ admin: vi.fn(), auth: vi.fn(), call: vi.fn(), datasets: vi.fn() }));
vi.mock("@/lib/db/require-admin", () => ({ getStrictAdminUser: m.admin }));
vi.mock("@/lib/db/require-analysis", () => ({ getAnalysisAuthResult: m.auth }));
vi.mock("@/lib/policy-review/db", () => ({ reviewCall: m.call }));
vi.mock("@/lib/policy-review/worker", () => ({ datasetDefinition: (d: unknown) => d }));
vi.mock("@/lib/db/dataset-registry", () => ({ listRegisteredDatasets: m.datasets }));
const { GET, POST } = await import("./route");
const id = "00000000-0000-4000-8000-000000000001";
const input = () => ({
  operation: "create",
  requestKey: id,
  question: "Review requirements",
  sources: { mode: "selected", versions: [id], datasetMode: "restricted", tables: ["agg_test"] },
  budget: 2,
});
const post = (payload: unknown, origin = "http://localhost") =>
  POST(
    new Request("http://localhost/api/admin/policy-reviews", {
      method: "POST",
      headers: { "content-type": "application/json", origin },
      body: JSON.stringify(payload),
    }),
  );
beforeEach(() => {
  vi.clearAllMocks();
  m.admin.mockResolvedValue({ id });
  m.auth.mockResolvedValue({ status: "approved", user: { id } });
  m.call.mockResolvedValue({ id });
  m.datasets.mockResolvedValue([{ tableName: "agg_test", grain: "one geography" }]);
});
describe("private full-review request boundary", () => {
  it("requires strict admin AND approved analysis on every read and write", async () => {
    m.admin.mockResolvedValue(null);
    expect((await post(input())).status).toBe(403);
    expect((await GET(new Request("http://localhost/api/admin/policy-reviews"))).status).toBe(403);
    expect(m.call).not.toHaveBeenCalled();
    m.admin.mockResolvedValue({ id });
    m.auth.mockResolvedValue({ status: "unapproved", user: { id } });
    expect((await post(input())).status).toBe(403);
  });
  it("freezes registry definitions on the server instead of trusting caller supplied dictionaries", async () => {
    const res = await post({ ...input(), datasets: { definitions: [{ tableName: "private" }] } });
    expect(res.status).toBe(201);
    expect(m.call).toHaveBeenCalledWith(
      id,
      "create",
      expect.objectContaining({
        datasets: {
          capturedAt: expect.any(String),
          exactRowsFrozen: false,
          definitions: [{ tableName: "agg_test", grain: "one geography" }],
        },
      }),
    );
  });
  it("rejects cross-origin changes, invalid budgets and oversized bodies", async () => {
    expect((await post(input(), "http://foreign.test")).status).toBe(403);
    expect((await post({ ...input(), budget: 1001 })).status).toBe(400);
    expect((await post({ ...input(), question: "x".repeat(70000) })).status).toBe(400);
    expect(m.call).not.toHaveBeenCalled();
  });
  it("fails closed on missing registered tables and online mode", async () => {
    m.datasets.mockResolvedValue([]);
    expect((await post(input())).status).toBe(400);
    expect((await post({ ...input(), sources: { ...input().sources, online: true } })).status).toBe(
      400,
    );
    expect(m.call).not.toHaveBeenCalled();
  });
  it("retains explicit policy-only scope with no dataset tables", async () => {
    expect((await post({ ...input(), sources: { ...input().sources, tables: [] } })).status).toBe(
      201,
    );
    expect(m.call).toHaveBeenCalledWith(
      id,
      "create",
      expect.objectContaining({ datasets: expect.objectContaining({ definitions: [] }) }),
    );
  });
  it("passes owner identity to section reads and validates identifiers before database access", async () => {
    expect(
      (await GET(new Request(`http://localhost/api/admin/policy-reviews?run=${id}&section=2`)))
        .status,
    ).toBe(200);
    expect(m.call).toHaveBeenCalledWith(id, "section", { run: id, section: 2, offset: 0 });
    m.call.mockClear();
    expect(
      (await GET(new Request("http://localhost/api/admin/policy-reviews?section=2"))).status,
    ).toBe(400);
    expect(m.call).not.toHaveBeenCalled();
  });
  it("returns private noncacheable errors without provider or database details", async () => {
    m.call.mockRejectedValue(new Error("secret database internals"));
    const res = await GET(new Request(`http://localhost/api/admin/policy-reviews?run=${id}`));
    expect(res.status).toBe(503);
    expect(res.headers.get("cache-control")).toBe("private, no-store");
    expect(await res.text()).not.toContain("secret");
  });
});
