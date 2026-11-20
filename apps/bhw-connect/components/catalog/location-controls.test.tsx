import { expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { parseHTML } from "linkedom";
import { LocationControls } from "./location-controls";
import { parseCatalogParams } from "@/lib/catalog/navigation";
const region = { code: "01", name: "Example region", level: "region" as const, parentCode: "PH" };
const national = { code: "PH", name: "Philippines", level: "national" as const, parentCode: null };
it("retains BHW in forms and ancestors but explicitly starts a new district selection", () => {
  const { document } = parseHTML(
    renderToStaticMarkup(
      <LocationControls
        selection={{ mode: "psgc", level: "region", code: "01" }}
        dataset="bhw"
        mapState="dataset=bhw-2025"
        geography={{
          state: "available",
          current: region,
          ancestors: [national],
          children: [
            { code: "01028", name: "Example province", level: "province", parentCode: "01" },
          ],
        }}
      />,
    ),
  );
  const form = document.querySelector("form")!;
  expect(form.querySelector('input[name="dataset"]')?.getAttribute("value")).toBe("bhw");
  expect(form.querySelector('input[name="mapState"]')?.getAttribute("value")).toContain(
    "dataset=bhw-2025",
  );
  for (const link of document.querySelectorAll('a[href^="/catalog?"]')) {
    const query = new URLSearchParams(link.getAttribute("href")!.split("?")[1]);
    const request = parseCatalogParams(Object.fromEntries(query));
    expect(request).not.toBeNull();
    expect(request?.dataset).toBe(query.get("mode") === "district" ? null : "bhw");
    expect(query.has("mapState")).toBe(true);
  }
});

it("retains Population map context in ancestor links and location forms", () => {
  const { document } = parseHTML(
    renderToStaticMarkup(
      <LocationControls
        selection={{ mode: "psgc", level: "region", code: "01" }}
        dataset="population"
        mapState="dataset=psa-popcen-2024&level=province&code=01028&selected=0102801&populationScope=province%3A01028&populationSearch=Adams"
        geography={{
          state: "available",
          current: region,
          ancestors: [national],
          children: [
            { code: "01028", name: "Example province", level: "province", parentCode: "01" },
          ],
        }}
      />,
    ),
  );
  expect(document.querySelector('input[name="mapState"]')?.getAttribute("value")).toContain(
    "populationSearch=Adams",
  );
  for (const link of document.querySelectorAll('a[href^="/catalog?"]')) {
    const query = new URLSearchParams(link.getAttribute("href")!.split("?")[1]);
    if (query.get("mode") !== "district")
      expect(query.get("mapState")).toContain("selected=0102801");
  }
});
