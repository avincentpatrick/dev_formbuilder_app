import { beforeEach, expect, it, vi } from "vitest";
import { isValidElement, type ReactNode } from "react";
import Home from "./page";
import { getGeoByCode } from "@/lib/db/geo";
import { getPublicDataset } from "@/lib/catalog/catalog";
vi.mock("@/lib/db/geo", () => ({ getGeoByCode: vi.fn() }));
vi.mock("@/lib/catalog/catalog", () => ({ getPublicDataset: vi.fn() }));
vi.mock("@/components/bhw/overview", () => ({ BhwOverview: () => null }));
vi.mock("@/components/bhw/search-form", () => ({ BhwSearchForm: () => null }));
vi.mock("@/components/chat/chat-launcher", () => ({ ChatLauncher: () => null }));
function content(node: ReactNode): ReactNode {
  if (!isValidElement<{ children?: ReactNode }>(node)) return null;
  if (typeof node.type === "function" && node.type.name === "OverviewContent") return node;
  for (const child of [node.props.children].flat()) {
    const found = content(child);
    if (found) return found;
  }
  return null;
}
beforeEach(() => vi.resetAllMocks());
for (const [level, code] of [
  ["region", "01"],
  ["province", "01028"],
  ["citymun", "0102801"],
  ["barangay", "0102801001"],
] as const) {
  it(`reads only the selected ${level} and carries its name to the same overview`, async () => {
    vi.mocked(getGeoByCode).mockResolvedValue({
      geoCode: code,
      geoLevel: level,
      geoName: "Chosen place",
      incomeClass: null,
    });
    const page = await Home({ searchParams: Promise.resolve({ location: `${level}:${code}` }) });
    const element = content(page) as React.ReactElement<{ selection: unknown; areaName: string }>;
    expect(element.props.selection).toEqual({ mode: "psgc", level, code });
    expect(element.props.areaName).toBe("Chosen place");
    const render = element.type as (props: unknown) => Promise<ReactNode>;
    await render(element.props);
    expect(getPublicDataset).toHaveBeenCalledExactlyOnceWith("bhw", { mode: "psgc", level, code });
  });
}
it("rejects invalid or mismatched places without reading national figures", async () => {
  vi.mocked(getGeoByCode).mockResolvedValue({
    geoCode: "01",
    geoLevel: "region",
    geoName: "Region",
    incomeClass: null,
  });
  for (const location of ["province:01", "bad", ["region:01", "region:02"]]) {
    const page = await Home({ searchParams: Promise.resolve({ location }) });
    expect(content(page)).toBeNull();
  }
  expect(getPublicDataset).not.toHaveBeenCalled();
});
