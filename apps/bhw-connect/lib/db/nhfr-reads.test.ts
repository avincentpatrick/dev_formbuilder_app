import { beforeEach, expect, it, vi } from "vitest";
import { getNhfrFacilities, getNhfrTypes } from "./nhfr";
const mock = vi.hoisted(() => ({ client: vi.fn(), dataset: vi.fn() }));
vi.mock("./supabase", () => ({ createSupabaseServerClient: mock.client }));
vi.mock("./dataset", () => ({
  DATASET_SLUGS: { nhfr: "nhfr-2026-09" },
  getDatasetIdBySlug: mock.dataset,
}));
vi.mock("./geo", () => ({ getChildGeos: vi.fn() }));
function response(data: unknown[] | null, error: unknown = null) {
  const query = {
    select: vi.fn().mockReturnThis(),
    eq: vi.fn().mockReturnThis(),
    order: vi.fn().mockReturnThis(),
    limit: vi.fn().mockReturnThis(),
    then: (resolve: (r: unknown) => unknown) => Promise.resolve(resolve({ data, error })),
  };
  mock.client.mockReturnValue({ from: () => query });
  return query;
}
beforeEach(() => {
  vi.clearAllMocks();
  mock.dataset.mockResolvedValue(11);
});
it("distinguishes failed or missing reads from successful empty lists", async () => {
  for (const data of [null, []]) {
    response(data, { message: "unavailable" });
    expect(await getNhfrFacilities("synthetic")).toBeNull();
    expect(await getNhfrTypes("synthetic", "citymun")).toBeNull();
  }
  response(null);
  expect(await getNhfrFacilities("synthetic")).toBeNull();
  response([]);
  expect(await getNhfrFacilities("synthetic")).toEqual([]);
  expect(await getNhfrTypes("synthetic", "citymun")).toEqual([]);
});
it("does not make a missing accepted source look like zero", async () => {
  mock.dataset.mockResolvedValue(null);
  expect(await getNhfrFacilities("synthetic")).toBeNull();
  expect(await getNhfrTypes("synthetic", "citymun")).toBeNull();
  expect(mock.client).not.toHaveBeenCalled();
});
it("retains source and locality filters and excludes contact fields from projection", async () => {
  const query = response([]);
  await getNhfrFacilities("0102801");
  expect(mock.dataset).toHaveBeenCalledWith("nhfr-2026-09");
  expect(query.eq.mock.calls).toEqual([
    ["dataset_id", 11],
    ["geo_code", "0102801"],
  ]);
  expect(query.select.mock.calls[0][0]).not.toMatch(/email|contact|phone|street/);
  expect(query.limit).toHaveBeenCalledWith(1000);
});
