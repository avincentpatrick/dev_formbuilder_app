import { beforeEach, describe, expect, it, vi } from "vitest";
import { getActiveDataset, getDatasetBySlug, readDatasetBySlug } from "./dataset";
const mock = vi.hoisted(() => ({ client: vi.fn() }));
vi.mock("./supabase", () => ({ createSupabaseServerClient: mock.client }));
const row = {
  dataset_id: 9,
  slug: "bhw-2025",
  name: "BHW",
  source_name: "DOH",
  license: null,
  as_of_date: "2025-12-31",
  last_updated_at: "2026-11-01T00:00:00Z",
};
function response(data: typeof row | null, error: unknown = null) {
  const query = {
    select: vi.fn().mockReturnThis(),
    eq: vi.fn().mockReturnThis(),
    maybeSingle: vi.fn().mockResolvedValue({ data, error }),
  };
  mock.client.mockReturnValue({ from: vi.fn(() => query) });
  return query;
}
beforeEach(() => vi.clearAllMocks());
describe("typed source reads and legacy compatibility", () => {
  it("distinguishes failed and missing metadata while nullable callers retain their contract", async () => {
    response(null);
    expect(await readDatasetBySlug("nhfr-2026-09")).toEqual({ state: "unavailable" });
    expect(await getDatasetBySlug("nhfr-2026-09")).toBeNull();
    response(null, { message: "secret" });
    expect(await readDatasetBySlug("nhfr-2026-09")).toEqual({ state: "failed" });
    expect(await getDatasetBySlug("nhfr-2026-09")).toBeNull();
    mock.client.mockImplementation(() => {
      throw new Error("private");
    });
    expect(await readDatasetBySlug("nhfr-2026-09")).toEqual({ state: "failed" });
  });
  it("preserves the active-profile guard and companion slug lookup semantics", async () => {
    const q = response(row);
    expect(await getActiveDataset()).toMatchObject({ slug: "bhw-2025", datasetId: 9 });
    expect(q.eq.mock.calls).toEqual([
      ["slug", "bhw-2025"],
      ["status", "active"],
    ]);
    q.eq.mockClear();
    expect(await getDatasetBySlug("uuc-phc-2025")).toMatchObject({ datasetId: 9 });
    expect(q.eq.mock.calls).toEqual([["slug", "uuc-phc-2025"]]);
  });
});
