import { beforeEach, expect, it, vi } from "vitest";
import { policySourcesSchema } from "./schema";
const { rpc, registered } = vi.hoisted(() => ({ rpc: vi.fn(), registered: vi.fn() }));
vi.mock("@/lib/db/service-client", () => ({ createSupabaseServiceClient: () => ({ rpc }) }));
vi.mock("@/lib/db/dataset-registry", () => ({ listRegisteredDatasets: registered }));
const { resolvePolicyContext } = await import("./evidence");
beforeEach(() => {
  vi.resetAllMocks();
  rpc.mockReturnValue({
    abortSignal: async () => ({
      data: [{ document: "upload:server-validated", title: "Policy", warnings: [] }],
      error: null,
    }),
  });
  registered.mockResolvedValue([{ tableName: "allowed" }]);
});
it("refuses online policy mode before any DB or provider transport is invoked", async () => {
  await expect(
    resolvePolicyContext("admin", policySourcesSchema.parse({ online: true })),
  ).rejects.toThrow("Online policy research is unavailable");
  expect(rpc).not.toHaveBeenCalled();
});
it("derives source keys from the server inventory and validates restricted table names", async () => {
  const context = await resolvePolicyContext(
    "admin",
    policySourcesSchema.parse({ datasetMode: "restricted", tables: ["allowed"], keys: ["forged"] }),
  );
  expect(context.keys).toEqual(["upload:server-validated"]);
  expect(context.tables).toEqual(["allowed"]);
  await expect(
    resolvePolicyContext(
      "admin",
      policySourcesSchema.parse({ datasetMode: "restricted", tables: ["not_registered"] }),
    ),
  ).rejects.toThrow("not registered");
});
