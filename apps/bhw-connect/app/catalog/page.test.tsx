import { expect, it, vi } from "vitest";
const { redirect } = vi.hoisted(() => ({
  redirect: vi.fn((href: string) => {
    throw new Error(`redirect:${href}`);
  }),
}));
vi.mock("next/navigation", () => ({ redirect }));
vi.mock("@/components/catalog/catalog-content", () => ({ CatalogContent: () => null }));
import CatalogPage from "./page";
import { bhwOverviewHref } from "@/lib/maps/catalog-return";

it("sends legacy all-location BHW entries to the visual overview with valid map context", async () => {
  for (const mode of [undefined, "all"]) {
    const mapState =
      "dataset=bhw-2025&indicator=bhw.active-years&level=region&code=07&selected=07306";
    await expect(
      CatalogPage({ searchParams: Promise.resolve({ mode, dataset: "bhw", mapState }) }),
    ).rejects.toThrow(`redirect:${bhwOverviewHref(mapState)}`);
  }
});
it("keeps scoped data, unrelated datasets and invalid requests in the catalog", async () => {
  for (const params of [
    { mode: "psgc", location: "region:01", dataset: "bhw" },
    { mode: "district", district: "example", dataset: "bhw" },
    { mode: "all", dataset: "nhfr" },
    { mode: "all", location: "region:01", dataset: "bhw" },
  ])
    await expect(CatalogPage({ searchParams: Promise.resolve(params) })).resolves.toBeDefined();
});

it("retains an invalid-map notice without forwarding untrusted context", async () => {
  await expect(
    CatalogPage({
      searchParams: Promise.resolve({
        mode: "all",
        dataset: "bhw",
        mapState: "https://example.test",
      }),
    }),
  ).rejects.toThrow("redirect:/bhw?mapState=");
});
