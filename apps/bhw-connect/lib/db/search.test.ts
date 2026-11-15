import { beforeEach, expect, it, vi } from "vitest";
import { searchGeo } from "./search";
import { createSupabaseServerClient } from "./supabase";

vi.mock("./supabase", () => ({ createSupabaseServerClient: vi.fn() }));
const rpc = vi.fn();
const single = vi.fn();
const eq = vi.fn(() => ({ maybeSingle: single }));
const select = vi.fn(() => ({ eq }));
const from = vi.fn(() => ({ select }));
beforeEach(() => {
  vi.clearAllMocks();
  vi.mocked(createSupabaseServerClient).mockReturnValue({ rpc, from } as unknown as ReturnType<
    typeof createSupabaseServerClient
  >);
});
it("reports RPC failures to opted-in callers while retaining legacy fallback", async () => {
  rpc.mockResolvedValue({ data: null, error: { message: "internal detail" } });
  await expect(searchGeo("Cebu", 12, { throwOnError: true })).rejects.toThrow(
    "Geography search failed",
  );
  await expect(searchGeo("Cebu")).resolves.toEqual([]);
  rpc.mockResolvedValue({ data: [], error: null });
  await expect(searchGeo("Unknown", 12, { throwOnError: true })).resolves.toEqual([]);
});
it("matches codes exactly without depending on the text search index", async () => {
  single.mockResolvedValue({
    data: { geo_code: "01", geo_level: "region", geo_name: "Example region" },
    error: null,
  });
  expect(await searchGeo("01", 12, { throwOnError: true })).toEqual([
    { geoCode: "01", geoLevel: "region", geoName: "Example region", nTotal: null, parentChain: {} },
  ]);
  expect(eq).toHaveBeenCalledWith("geo_code", "01");
  expect(rpc).not.toHaveBeenCalled();
  single.mockResolvedValue({ data: null, error: { message: "unavailable" } });
  await expect(searchGeo("01", 12, { throwOnError: true })).rejects.toThrow(
    "Geography search failed",
  );
});
