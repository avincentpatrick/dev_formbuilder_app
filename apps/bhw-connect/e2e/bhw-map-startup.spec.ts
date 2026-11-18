import { expect, test } from "@playwright/test";

test("national boundaries load while the map's aggregate response is still pending", async ({
  page,
}) => {
  let releaseData!: () => void;
  const dataGate = new Promise<void>((resolve) => {
    releaseData = resolve;
  });
  let boundaryRequests = 0;
  page.on("request", (request) => {
    if (request.url().endsWith("/geo/regions.json")) boundaryRequests++;
  });
  await page.route("**/api/dataset-map?**", async (route) => {
    await dataGate;
    await route.fulfill({
      json: {
        state: "available",
        rows: [{ code: "01", name: "Synthetic region", value: 100 }],
        total: 100,
        area: "Philippines",
        childLevel: "region",
        source: "Synthetic fixture",
        date: "2025-12-31",
        license: "Test only",
        geometryUrl: `${route.request().url().split("/api/")[0]}/geo/regions.json`,
      },
    });
  });
  try {
    await page.goto("map?dataset=bhw-stepzero-2026");
    await expect.poll(() => boundaryRequests).toBe(1);
    await expect(page.locator('[data-map-state="ready"]')).toHaveCount(0);
  } finally {
    releaseData();
  }
  await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
  expect(boundaryRequests).toBe(1);
});

for (const query of [
  "dataset=bhw-stepzero-2026&level=province&code=07022",
  "dataset=not-a-dataset",
]) {
  test(`does not preload national boundaries for ${query}`, async ({ page }) => {
    await page.route("**/api/dataset-map?**", (route) =>
      route.fulfill({ json: { state: "unavailable" } }),
    );
    await page.goto(`map?${query}`);
    await expect(page.locator('link[rel="preload"][as="fetch"]')).toHaveCount(0);
  });
}
