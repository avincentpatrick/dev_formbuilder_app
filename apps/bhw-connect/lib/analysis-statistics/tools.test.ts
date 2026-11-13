import { it, expect, vi, beforeEach } from "vitest";
const { call, execute, registered, query } = vi.hoisted(() => ({
  call: vi.fn(),
  execute: vi.fn(),
  registered: vi.fn(),
  query: vi.fn(),
}));
vi.mock("@/lib/analysis-sources/db", () => ({ sourceCall: call }));
vi.mock("./execute", () => ({ executeStatistic: execute }));
vi.mock("@/lib/db/dataset-registry", () => ({ getRegisteredDataset: registered }));
vi.mock("@/lib/db/service-client", () => ({
  createSupabaseServiceClient: () => ({ from: query }),
}));
import { createStatisticalTools } from "./tools";
const sourceId = "00000000-0000-4000-8000-000000000001";
const spec = {
  method: "describe",
  title: "Synthetic count summary",
  x: "count",
  unit: "people",
  design: { population: "census", unit: "area", independent: false },
  designStatement: "One row represents one disjoint area.",
};
beforeEach(() => {
  vi.resetAllMocks();
});
it("reads the whole private table rather than its first preview rows, with bound project ACL", async () => {
  const rows = Array.from({ length: 250 }, (_, i) => ({ count: i < 100 ? 1 : 3 }));
  call.mockImplementation(async (_actor, _project, op) =>
    op === "access"
      ? { role: "owner" }
      : {
          status: "ready",
          name: "synthetic.csv",
          id: sourceId,
          sha256: "hash",
          created_at: "date",
          metadata: {},
          content: { derivedHash: "derived", tables: [{ name: "CSV", rows }] },
        },
  );
  execute.mockResolvedValue({ kind: "statistical-result" });
  const save = vi.fn();
  await createStatisticalTools("actor", "permitted", save)[0].execute({
    sourceId,
    spec,
    projectId: "stolen",
  });
  expect(call).toHaveBeenCalledWith("actor", "permitted", "get", { sourceId });
  expect(execute.mock.calls[0][0].rows).toHaveLength(250);
  expect(save).toHaveBeenCalledOnce();
});
it("does not calculate after denied access", async () => {
  call.mockRejectedValue(new Error("Access denied"));
  expect(
    await createStatisticalTools("actor", "permitted", vi.fn())[0].execute({ sourceId, spec }),
  ).toMatchObject({ kind: "unsupported-statistical-analysis" });
  expect(execute).not.toHaveBeenCalled();
});
it("refuses a truncated dashboard response instead of calculating a biased preview", async () => {
  call.mockResolvedValue({ role: "owner" });
  registered.mockResolvedValue({
    title: "Synthetic",
    columns: [{ name: "count", isQueryable: true }],
    tableName: "synthetic",
  });
  query.mockReturnValue({
    select: () => ({
      range: () => Promise.resolve({ data: [{ count: 1 }], error: null, count: 250 }),
    }),
  });
  const result = await createStatisticalTools("actor", "p", vi.fn())[0].execute({
    datasetTable: "synthetic",
    spec,
  });
  expect(result).toMatchObject({ error: expect.stringContaining("Complete table") });
  expect(execute).not.toHaveBeenCalled();
});
it("keeps generated tool schema compatible with the existing Gemini adapter", () => {
  const schema = JSON.stringify(createStatisticalTools("a", "p", vi.fn())[0].definition.parameters);
  expect(schema).not.toContain('"$schema"');
  expect(schema).not.toContain('"additionalProperties"');
  expect(schema).not.toContain('"default"');
  expect(schema).toContain('"method"');
});
it("describes filters with explicit array items rather than an unsupported dynamic object schema", () => {
  const filters = createStatisticalTools("a", "p", vi.fn())[0].definition.parameters.properties
    .filters as { type: string; items: { properties: { column: { type: string } } } };
  expect(filters.type).toBe("array");
  expect(filters.items.properties.column.type).toBe("string");
});
