import { expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { parseHTML } from "linkedom";
import { LocationControls } from "./location-controls";
import { parseCatalogParams } from "@/lib/catalog/navigation";
import type { CatalogGeography } from "@/lib/catalog/geography";
import type { CatalogId } from "@/lib/catalog/types";

const geography: Extract<CatalogGeography, { state: "available" }> = {
  state: "available",
  current: { code: "07", level: "region", name: "Region VII", parentCode: "PH" },
  ancestors: [{ code: "PH", level: "national", name: "Philippines", parentCode: null }],
  children: [{ code: "07022", level: "province", name: "Cebu", parentCode: "07" }],
};
function render(dataset: CatalogId | null) {
  return parseHTML(
    renderToStaticMarkup(
      <LocationControls
        geography={geography}
        selection={{ mode: "psgc", level: "region", code: "07" }}
        dataset={dataset}
      />,
    ),
  ).document;
}

it.each(["bhw", "nhfr", "population"] as const)(
  "keeps %s in the submitted location form and PSGC links",
  (dataset) => {
    const document = render(dataset);
    const form = document.querySelector("form")!;
    const params: Record<string, string> = {};
    for (const input of form.querySelectorAll('input[type="hidden"]'))
      params[input.getAttribute("name")!] = input.getAttribute("value")!;
    params.location = form.querySelector('option[value="province:07022"]')!.getAttribute("value")!;
    expect(parseCatalogParams(params)).toEqual({
      dataset,
      selection: { mode: "psgc", level: "province", code: "07022" },
    });
    const links = [...document.querySelectorAll("a")].filter((link) =>
      ["All locations", "National figures", "Philippines"].includes(link.textContent!),
    );
    expect(links).toHaveLength(3);
    for (const link of links) {
      const query = new URL(link.getAttribute("href")!, "https://example.test").searchParams;
      expect(parseCatalogParams(Object.fromEntries(query))?.dataset).toBe(dataset);
    }
  },
);

it("keeps all-datasets browsing unscoped by dataset", () => {
  const document = render(null);
  expect(document.querySelector('input[name="dataset"]')).toBeNull();
  for (const link of document.querySelectorAll("a"))
    expect(link.getAttribute("href")).not.toContain("dataset=");
});

it("explains the separate district selection and never builds an invalid empty-district detail URL", () => {
  const document = render("bhw");
  const link = [...document.querySelectorAll("a")].find(
    (a) => a.textContent === "Congressional districts",
  )!;
  const params = new URL(link.getAttribute("href")!, "https://example.test").searchParams;
  expect(parseCatalogParams(Object.fromEntries(params))).toEqual({
    selection: { mode: "district", districtCode: "" },
    dataset: null,
  });
  expect(document.documentElement.textContent).toContain("choose a district and then its dataset");
});
