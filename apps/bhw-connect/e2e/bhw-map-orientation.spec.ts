import { expect, test, type Page } from "@playwright/test";

async function regionFixture(page: Page) {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        rows: [
          { code: "07012", name: "Bohol", value: 0 },
          { code: "07022", name: "Cebu", value: 100 },
          { code: "07306", name: "Cebu City", value: 50 },
          { code: "07311", name: "Lapu-Lapu", value: null },
          { code: "07313", name: "Mandaue", value: 25 },
        ],
        total: 175,
        area: "Region VII",
        childLevel: "province",
        source: "Synthetic fixture",
        date: "2025-12-31",
        license: "Test only",
        geometryUrl: `${route.request().url().split("/api/")[0]}/geo/provinces/07.json`,
      },
    }),
  );
}

for (const width of [390, 1440]) {
  test(`national locator retains actual geography and missing shapes at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    const errors: string[] = [];
    page.on("pageerror", (error) => errors.push(error.message));
    await regionFixture(page);
    await page.goto("map?dataset=bhw-stepzero-2026&level=region&code=07");
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    const locator = page.locator("[data-locator-state]");
    await expect(locator).toHaveAttribute("data-locator-state", "ready");
    await expect(locator.getByText(/Highlighted available child boundaries/)).toBeVisible();
    const scope = await locator.locator("path").last().getAttribute("d");
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByLabel("BHW Headcount 2025 area", { exact: true }).selectOption("07022");
    if (width < 1024)
      await page.getByRole("button", { name: "Map and legend", exact: true }).click();
    await expect(locator).toHaveAttribute("data-locator-state", "ready");
    await expect(locator.getByText("Cebu", { exact: true })).toBeVisible();
    expect(await locator.locator("path").last().getAttribute("d")).not.toBe(scope);
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByLabel("BHW Headcount 2025 area", { exact: true }).selectOption("07306");
    if (width < 1024)
      await page.getByRole("button", { name: "Map and legend", exact: true }).click();
    await expect(locator).toHaveAttribute("data-locator-state", "unavailable");
    await expect(locator.locator("svg")).toHaveCount(0);
    await expect(locator.getByText(/No supplied shape/)).toBeVisible();
    await page.getByRole("link", { name: "Share this map selection" }).click();
    await expect(page).toHaveURL(/selected=07306/);
    await expect(locator).toHaveAttribute("data-locator-state", "unavailable");
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}

test("national locator failure can retry without taking down the thematic map", async ({
  page,
}) => {
  await regionFixture(page);
  let attempts = 0;
  await page.route("**/geo/regions.json", (route) =>
    ++attempts === 1
      ? route.fulfill({ status: 503, body: "Synthetic boundary failure" })
      : route.continue(),
  );
  await page.goto("map?dataset=bhw-stepzero-2026&level=region&code=07");
  await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
  await expect(page.getByRole("button", { name: "Retry national locator" })).toBeVisible();
  await page.getByRole("button", { name: "Retry national locator" }).click();
  await expect(page.locator('[data-locator-state="ready"]')).toBeVisible();
  await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
});

test("an empty accepted boundary list cannot produce a locator position", async ({ page }) => {
  await regionFixture(page);
  await page.route("**/geo/provinces/07.json", (route) =>
    route.fulfill({ json: { type: "FeatureCollection", features: [] } }),
  );
  await page.goto("map?dataset=bhw-stepzero-2026&level=region&code=07");
  await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
  await expect(page.locator('[data-locator-state="unavailable"]')).toBeVisible();
  await expect(page.locator("[data-locator-state] svg")).toHaveCount(0);
  await page.getByText(/Complete area table/).click();
  await expect(page.getByRole("row", { name: "Cebu City 50 Missing", exact: true })).toBeVisible();
});
