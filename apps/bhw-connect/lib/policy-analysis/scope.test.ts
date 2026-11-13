import { expect, it, vi } from "vitest";
import { policySourcesSchema, type PolicyContext } from "./schema";
const { query, list, get } = vi.hoisted(() => ({ query: vi.fn(), list: vi.fn(), get: vi.fn() }));
vi.mock("@/lib/ai/query-dataset", async () => ({
  ...(await vi.importActual<typeof import("@/lib/ai/query-dataset")>("@/lib/ai/query-dataset")),
  executeQueryDataset: query,
}));
vi.mock("@/lib/db/dataset-registry", () => ({
  listRegisteredDatasets: list,
  getRegisteredDataset: get,
  describeDataset: () => ({}),
}));
const { createInternalTools } = await import("@/lib/ai/dataset-tools");
const context = (tables: string[]): PolicyContext => ({
  actor: "admin",
  sources: policySourcesSchema.parse({ mode: "active", datasetMode: "restricted", tables }),
  records: [],
  keys: ["upload:selected"],
  tables,
});
it("policy-only scope omits alternate numeric and graph paths and denies registry queries", async () => {
  list.mockResolvedValue([{ tableName: "other", title: "Other", grain: "one place" }]);
  const tools = createInternalTools({ policyContext: context([]) });
  expect(tools.map((t) => t.definition.name)).not.toContain("getIndicatorByGeo");
  expect(tools.map((t) => t.definition.name)).not.toContain("traverseGraph");
  expect(tools.map((t) => t.definition.name)).not.toContain("searchOnline");
  expect(tools.map((t) => t.definition.name)).not.toContain("readOnlineSource");
  const result = (await tools
    .find((t) => t.definition.name === "queryDataset")!
    .execute({ table: "other" })) as { error: string };
  expect(result.error).toContain("outside");
  expect(query).not.toHaveBeenCalled();
  const dictionary = (await tools
    .find((t) => t.definition.name === "listDatasets")!
    .execute({ table: "other" })) as { error: string };
  expect(dictionary.error).toContain("outside");
  expect(get).not.toHaveBeenCalled();
});
it("allows registered query execution only for explicitly selected tables", async () => {
  query.mockResolvedValue({ rows: [{ count: 42 }] });
  const tool = createInternalTools({ policyContext: context(["allowed"]) }).find(
    (t) => t.definition.name === "queryDataset",
  )!;
  expect(await tool.execute({ table: "allowed", aggregate: { op: "count" } })).toEqual({
    rows: [{ count: 42 }],
  });
  expect(query).toHaveBeenCalledWith(
    { table: "allowed", aggregate: { op: "count" } },
    "internal",
    undefined,
  );
});
it("requires explicit historical selection and bounds input rather than enabling web tools", () => {
  expect(policySourcesSchema.safeParse({ mode: "active", historical: true }).success).toBe(false);
  expect(policySourcesSchema.safeParse({ mode: "selected" }).success).toBe(false);
  expect(policySourcesSchema.parse({ mode: "active" }).online).toBe(false);
});
