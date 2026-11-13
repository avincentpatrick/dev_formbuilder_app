import { expect, it, vi } from "vitest";
import { policySourcesSchema, type PolicyContext } from "./schema";
const { call } = vi.hoisted(() => ({ call: vi.fn() }));
vi.mock("./evidence", () => ({ policyCall: call }));
const { createPolicyTools } = await import("./tools");
const context: PolicyContext = {
  actor: "admin",
  sources: policySourcesSchema.parse({ mode: "active" }),
  records: [],
  keys: ["upload:selected"],
  tables: [],
};
it("reads full stored neighbors and cannot widen scope using tool arguments", async () => {
  call.mockResolvedValue([
    {
      chunk_id: 8,
      document: "upload:selected",
      document_title: "Policy",
      page: 3,
      page_to: 3,
      heading: null,
      content: "x".repeat(2400),
      char_start: 10,
      char_end: 2410,
      version_id: "version",
      extraction_id: "extraction",
      revision: 1,
      publication_date: null,
      effective_date: null,
      supersedes_id: null,
      warnings: [],
    },
  ]);
  const tool = createPolicyTools(context).find((t) => t.definition.name === "readPolicyPassage")!;
  const r = (await tool.execute({
    chunkId: 8,
    neighbors: 2,
    keys: ["upload:unselected"],
    historical: true,
  })) as { results: Array<{ text: string; truncated: boolean }> };
  expect(r.results[0].text.length).toBe(2400);
  expect(r.results[0].truncated).toBe(false);
  expect(call).toHaveBeenCalledWith("admin", "read", {
    chunk: 8,
    neighbors: 2,
    keys: ["upload:selected"],
    historical: false,
  });
});
it("does not convert unreadable or inaccessible passages into a finding of absence", async () => {
  call.mockRejectedValue(new Error("No access"));
  const r = (await createPolicyTools(context)
    .find((t) => t.definition.name === "readPolicyPassage")!
    .execute({ chunkId: 88 })) as { error: string };
  expect(r.error).toContain("Do not infer absence");
});
