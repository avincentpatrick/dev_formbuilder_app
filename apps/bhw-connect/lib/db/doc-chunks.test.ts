import { describe, expect, it, vi } from "vitest";
const { row } = vi.hoisted(() => ({ row: { status: "approved" } }));
vi.mock("./service-client", () => ({
  createSupabaseServiceClient: () => ({
    from: () => ({
      select: () => ({
        eq: () => ({
          maybeSingle: async () => ({
            data: {
              chunk_id: 1,
              doc_source: { key: "synthetic", title: "Synthetic document", status: row.status },
            },
          }),
        }),
      }),
    }),
  }),
}));
const { getDocChunk } = await import("./doc-chunks");
describe("analyst source visibility", () => {
  it("serves approved sources to approved-user viewers", async () => {
    row.status = "approved";
    expect((await getDocChunk(1, true))?.document).toBe("synthetic");
  });
  it("does not expose unreviewed sources through guessed chunk IDs", async () => {
    row.status = "auto";
    expect(await getDocChunk(1, true)).toBeNull();
    expect((await getDocChunk(1))?.document).toBe("synthetic");
  });
});
