import { expect, test, type Page } from "@playwright/test";

async function fixture(page: Page) {
  let requests = 0;
  await page.route("**/api/dataset-map?**", (route) => {
    requests++;
    return route.fulfill({
      json: {
        state: "available",
        rows: [
          { code: "01", name: "Synthetic zero", value: 0 },
          { code: "02", name: "Synthetic unavailable", value: null },
          { code: "03", name: "Synthetic count", value: 100 },
          { code: "99", name: "Synthetic missing shape", value: 25 },
        ],
        total: 125,
        area: "Philippines",
        childLevel: "region",
        source: "Synthetic fixture",
        date: "2025-12-31",
        license: "Test only",
        geometryUrl: `${route.request().url().split("/api/")[0]}/geo/regions.json`,
      },
    });
  });
  return () => requests;
}

for (const width of [320, 390, 1440]) {
  test(`workspace prioritizes the map with accessible controls and legend at ${width}px`, async ({
    browser,
  }) => {
    const context = await browser.newContext({
      viewport: { width, height: 844 },
      hasTouch: width < 1024,
    });
    const page = await context.newPage();
    const requests = await fixture(page);
    await page.goto("map?dataset=bhw-stepzero-2026");
    const canvas = page.locator('[data-map-state="ready"]');
    await expect(canvas).toBeVisible();
    for (const control of [
      page.getByLabel("BHW Headcount 2025 indicator", { exact: true }),
      page.getByRole("button", { name: "Reset map view" }),
    ]) {
      const box = await control.boundingBox();
      expect(box).not.toBeNull();
      expect(box!.y).toBeGreaterThanOrEqual(0);
      expect(box!.y + box!.height).toBeLessThan(844);
    }
    const initial = await canvas.boundingBox();
    expect(initial!.height).toBeGreaterThanOrEqual(width < 1024 ? 500 : 240);
    expect(initial!.y).toBeLessThan(width < 1024 ? 400 : 650);
    if (width < 1024) {
      expect(Math.min(844, initial!.y + initial!.height) - initial!.y).toBeGreaterThan(422);
      await expect(page.getByRole("region", { name: "Circle size legend" })).not.toBeVisible();
      await page.getByRole("button", { name: /map legend and reading guide/ }).click();
      await expect(page.getByRole("region", { name: "Circle size legend" })).toBeVisible();
      await page.getByRole("button", { name: /map legend and reading guide/ }).click();
    }
    const areas = page.getByRole("region", { name: "Areas and data", exact: true });
    if (width < 1024) {
      await expect(areas).not.toBeVisible();
      await page.getByRole("button", { name: "Areas and data", exact: true }).tap();
      await expect(areas).toBeVisible();
      await expect(canvas).not.toBeVisible();
      await expect(
        page.getByRole("button", { name: "Download map image (PNG)" }),
      ).not.toBeVisible();
    } else {
      const side = await areas.boundingBox();
      expect(side!.x).toBeGreaterThan(initial!.x + initial!.width);
    }
    await page.getByText(/Complete area table/).click();
    await areas.getByRole("button", { name: "Synthetic missing shape", exact: true }).click();
    await expect(page.getByLabel("BHW Headcount 2025 area", { exact: true })).toHaveValue("99");
    await page.getByLabel("BHW Headcount 2025 search areas").fill("zero");
    if (width < 1024) {
      await page.getByRole("button", { name: "Map and legend", exact: true }).focus();
      await page.keyboard.press("Enter");
    }
    await expect(canvas).toBeVisible();
    await expect(page.getByText(/Selected: Synthetic missing shape · 25 people/)).toBeVisible();
    await expect(page.getByRole("button", { name: "Download map image (PNG)" })).toBeEnabled();
    if (width === 390) {
      const downloaded = page.waitForEvent("download");
      await page.getByRole("button", { name: "Download map image (PNG)" }).click();
      const stream = await (await downloaded).createReadStream();
      const bytes: Buffer[] = [];
      for await (const chunk of stream!) bytes.push(Buffer.from(chunk));
      expect(Buffer.concat(bytes).subarray(0, 8)).toEqual(
        Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
      );
    }
    await page.getByRole("button", { name: "Zoom out", exact: true }).click();
    if (width < 1024) await page.getByRole("button", { name: "Areas and data", exact: true }).tap();
    await expect(page.getByLabel("BHW Headcount 2025 search areas")).toHaveValue("zero");
    await expect(page.getByLabel("BHW Headcount 2025 area", { exact: true })).toHaveValue("99");
    expect(requests()).toBe(1);
    expect(await page.locator(".maplibregl-map").count()).toBe(1);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    await context.close();
  });
}

test("phone details remain usable when boundaries fail, and map retry restores rendering", async ({
  page,
}) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await fixture(page);
  await page.route("**/geo/regions.json", (route) =>
    route.fulfill({ status: 503, body: "Unavailable" }),
  );
  await page.goto("map?dataset=bhw-stepzero-2026");
  await expect(page.getByRole("button", { name: "Retry map rendering" })).toBeVisible();
  await page.getByRole("button", { name: "Areas and data", exact: true }).click();
  await page.getByLabel("BHW Headcount 2025 area", { exact: true }).selectOption("01");
  await expect(page.getByText("Synthetic zero: 0 people", { exact: true })).toBeVisible();
  await page.getByRole("button", { name: "Map and legend", exact: true }).click();
  await page.unroute("**/geo/regions.json");
  await page.getByRole("button", { name: "Retry map rendering" }).click();
  await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
  await expect(page.getByText(/Selected: Synthetic zero · 0 people/)).toBeVisible();
});
