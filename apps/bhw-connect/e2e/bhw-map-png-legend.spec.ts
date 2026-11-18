import { expect, test, type Page } from "@playwright/test";
import { missingCebuShapes } from "./helpers/missing-cebu-shapes";
async function fixture(page: Page, empty = false) {
  await page.route("**/api/dataset-map?**", (route) => {
    const url = new URL(route.request().url());
    const province = url.searchParams.get("level") === "province";
    return route.fulfill({
      json: {
        state: "available",
        rows: province
          ? [
              { code: "0702201", name: "Alcantara", value: empty ? null : 0 },
              { code: "0702202", name: "Alcoy", value: empty ? null : 100 },
            ]
          : [
              { code: "07012", name: "Bohol", value: empty ? null : 0 },
              { code: "07022", name: "Cebu", value: empty ? null : 100 },
              { code: "07306", name: "Cebu City", value: empty ? null : 50 },
              { code: "07311", name: "Lapu-Lapu", value: null },
              { code: "07313", name: "Mandaue", value: empty ? null : 25 },
            ],
        total: 999,
        area: province ? "Cebu" : "Region VII",
        childLevel: province ? "citymun" : "province",
        navigation: { parents: [], complete: true },
        source: "Synthetic fixture",
        date: "2025-12-31",
        license: "Test only",
        geometryUrl: `${url.origin}/bhw-connect/geo/${province ? "citymun/07022" : "provinces/07"}.json`,
      },
    });
  });
}
test.use({ deviceScaleFactor: 2 });
for (const width of [390, 1440]) {
  test(`circle PNG exports size and metric legends at ${width}px and high DPI`, async ({
    page,
  }, testInfo) => {
    await page.setViewportSize({ width, height: 844 });
    await fixture(page);
    await page.goto("map?dataset=bhw-stepzero-2026&level=region&code=07&valueMin=25");
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    await expect(page.locator('[data-locator-state="ready"]')).toBeVisible();
    const scale = page.locator(".maplibregl-ctrl-scale");
    await expect(scale).toHaveText(/\d+(?:\.\d+)?\s+(?:km|m)/);
    const initialScale = await scale.innerText();
    const pending = page.waitForEvent("download");
    await page.getByRole("button", { name: "Download map image (PNG)", exact: true }).click();
    const download = await pending;
    await download.saveAs(testInfo.outputPath(`circle-legend-${width}.png`));
    const stream = await download.createReadStream();
    const chunks = [];
    for await (const chunk of stream!) chunks.push(chunk);
    const png = Buffer.concat(chunks);
    expect(png.subarray(1, 4).toString()).toBe("PNG");
    expect(png.readUInt32BE(16)).toBe(1200);
    expect(png.readUInt32BE(20)).toBeGreaterThan(500);
    await page.getByRole("button", { name: "Zoom in", exact: true }).click();
    // Zoom now changes geographic detail. Wait for that transition before
    // exporting: a changed scale alone can still belong to the outgoing map.
    // An export started there is correctly cancelled when its scope changes.
    await expect(page).toHaveURL(/level=province&code=07022/);
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    await expect(scale).not.toHaveText(initialScale);
    const zoomed = page.waitForEvent("download");
    await page.getByRole("button", { name: "Download map image (PNG)", exact: true }).click();
    await (await zoomed).saveAs(testInfo.outputPath(`metric-zoom-${width}.png`));
    await expect(
      page.getByRole("button", { name: "Download map image (PNG)", exact: true }),
    ).toBeEnabled();
  });
}

test("missing selected shape exports without an invented locator", async ({ page }, testInfo) => {
  await missingCebuShapes(page);
  await page.setViewportSize({ width: 1440, height: 844 });
  await fixture(page);
  await page.goto("map?dataset=bhw-stepzero-2026&level=region&code=07");
  await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
  await expect(page.locator('[data-locator-state="ready"]')).toBeVisible();
  await page.getByLabel("BHW Headcount 2025 area", { exact: true }).selectOption("07306");
  await expect(page.locator('[data-locator-state="unavailable"]')).toBeVisible();
  await expect(page.locator("[data-locator-state] svg")).toHaveCount(0);
  const pending = page.waitForEvent("download");
  await page.getByRole("button", { name: "Download map image (PNG)", exact: true }).click();
  await (await pending).saveAs(testInfo.outputPath("missing-shape-locator.png"));
});
