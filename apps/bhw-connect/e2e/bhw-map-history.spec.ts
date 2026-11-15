import { expect, test, type Page } from "@playwright/test";
async function fixture(page: Page, empty = false) {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        rows: [
          { code: "07012", name: "Bohol", value: empty ? null : 0 },
          { code: "07022", name: "Cebu", value: empty ? null : 100 },
          { code: "07306", name: "Cebu City", value: empty ? null : 50 },
          { code: "07311", name: "Lapu-Lapu", value: null },
          { code: "07313", name: "Mandaue", value: empty ? null : 25 },
        ],
        total: 999,
        area: "Region VII",
        childLevel: "province",
        navigation: { parents: [], complete: true },
        source: "Synthetic fixture",
        date: "2025-12-31",
        license: "Test only",
        geometryUrl: `${new URL(route.request().url()).origin}/bhw-connect/geo/provinces/07.json`,
      },
    }),
  );
}
for (const width of [390, 1440]) {
  test(`map history restores edits and survives reload at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await fixture(page);
    await page.goto(
      "map?dataset=bhw-stepzero-2026&level=region&code=07&view=choropleth&valueMin=25",
    );
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByText("Color classification", { exact: true }).click();
    await page.getByLabel("Grouping method", { exact: true }).selectOption("custom");
    await page.getByLabel("Custom cut points", { exact: true }).fill("25,50");
    await page.getByRole("button", { name: "Apply classification", exact: true }).click();
    await expect(page).toHaveURL(/classBreaks=25%2C50/);
    await page.getByLabel("BHW Headcount 2025 area", { exact: true }).selectOption("07306");
    await expect(page).toHaveURL(/selected=07306/);
    await page.getByLabel("BHW map view", { exact: true }).selectOption("circles");
    await expect(page).toHaveURL(/view=circles/);
    await page.goBack();
    await expect(page.getByLabel("BHW map view", { exact: true })).toHaveValue("choropleth");
    await page.goBack();
    await expect(page.getByLabel("BHW Headcount 2025 area", { exact: true })).toHaveValue("");
    await expect(page).toHaveURL(/classification=custom/);
    await page.goBack();
    await expect(page).not.toHaveURL(/classification=/);
    await expect(page.getByLabel("Effective classification")).toContainText("Quantiles");
    await page.goForward();
    await expect(page.getByLabel("Effective classification")).toContainText("Custom cut points");
    await page.goForward();
    await expect(page.getByLabel("BHW Headcount 2025 area", { exact: true })).toHaveValue("07306");
    await page.getByLabel("Map dataset").selectOption("bhw-2025");
    await expect(page).toHaveURL(/dataset=bhw-2025/);
    await page.goBack();
    await expect(page.getByLabel("Map dataset")).toHaveValue("bhw-stepzero-2026");
    await expect(page.getByLabel("Effective classification")).toContainText("Custom cut points");
    await page.reload();
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await expect(page.getByLabel("BHW Headcount 2025 area", { exact: true })).toHaveValue("07306");
    await expect(page.getByLabel("Effective classification")).toContainText("Custom cut points");
    await expect(page).toHaveURL(/valueMin=25/);
    await page.getByLabel("Map dataset").selectOption("bhw-2025");
    await expect(page).toHaveURL(/dataset=bhw-2025/);
    await page.goBack();
    await expect(page.getByLabel("Map dataset")).toHaveValue("bhw-stepzero-2026");
    await expect(page.getByLabel("Effective classification")).toContainText("Custom cut points");
    await expect(page).toHaveURL(/selected=07306/);
  });
}
