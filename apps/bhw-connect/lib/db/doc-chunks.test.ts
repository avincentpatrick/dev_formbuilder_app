import { describe, expect, it, vi } from "vitest";
const { row } = vi.hoisted(() => ({ row: { status: "approved", key: "synthetic", admin: false } }));
vi.mock("./require-admin", () => ({
  getStrictAdminUser: async () => (row.admin ? { id: "synthetic-admin", role: "admin" } : null),
}));
vi.mock("./service-client", () => ({
  createSupabaseServiceClient: () => ({
    from: () => ({
      select: () => ({
        eq: () => ({
          maybeSingle: async () => ({
            data: {
              chunk_id: 1,
              doc_source: { key: row.key, title: "Synthetic document", status: row.status },
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

it("denies an uploaded library citation to an approved non-admin", async () => {
  row.status = "approved";
  row.key = "upload:11111111-1111-4111-8111-111111111111";
  expect(await getDocChunk(1, true)).toBeNull();
  row.key = "synthetic";
});

it("keeps retired upload citations readable to strict admins", async () => {
  row.status = "rejected";
  row.key = "upload:11111111-1111-4111-8111-111111111111";
  row.admin = true;
  expect((await getDocChunk(1, true))?.document).toBe(row.key);
  row.status = "approved";
  row.key = "synthetic";
  row.admin = false;
});
