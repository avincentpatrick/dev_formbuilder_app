import { expect, test } from "@playwright/test";

for (const [width, initialView] of [
  [390, "choropleth"],
  [1440, "circles"],
] as const) {
  test(`view changes retain the canvas and boundaries from ${initialView} at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    let boundaryRequests = 0;
    let dataRequests = 0;
    page.on("request", (request) => {
      if (request.url().endsWith("/geo/regions.json")) boundaryRequests++;
      if (request.url().includes("/api/dataset-map?")) dataRequests++;
    });
    await page.route("**/api/dataset-map?**", (route) =>
      route.fulfill({
        json: {
          state: "available",
          rows: [
            { code: "01", name: "Synthetic zero", value: 0 },
            { code: "02", name: "Synthetic positive", value: 100 },
          ],
          total: 100,
          area: "Philippines",
          childLevel: "region",
          source: "Synthetic fixture",
          date: "2025-12-31",
          license: "Test only",
          geometryUrl: `${route.request().url().split("/api/")[0]}/geo/regions.json`,
        },
      }),
    );
    await page.goto(`map?dataset=bhw-stepzero-2026&view=${initialView}&selected=02`);
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    const canvas = page.locator(".maplibregl-canvas");
    await canvas.evaluate((node) => node.setAttribute("data-original-canvas", "yes"));
    const initialRequests = boundaryRequests;
    const view = page.getByLabel("BHW map view", { exact: true });
    for (const next of initialView === "circles"
      ? ["choropleth", "circles"]
      : ["circles", "choropleth"]) {
      await view.selectOption(next);
      await expect(page).toHaveURL(new RegExp(`view=${next}`));
      await expect(view).toHaveValue(next);
      // Allow the display effect and browser paint to run before checking identity.
      await page.evaluate(
        () => new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve))),
      );
      await expect(canvas).toHaveAttribute("data-original-canvas", "yes");
      await expect(page.locator("[data-map-state]")).toHaveAttribute("data-map-state", "ready");
      expect(boundaryRequests).toBe(initialRequests);
      expect(dataRequests).toBe(1);
      await expect(page.getByLabel("BHW Headcount 2025 area", { exact: true })).toHaveValue("02");
    }
  });
}
