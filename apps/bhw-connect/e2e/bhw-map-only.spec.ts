import { expect, test } from "@playwright/test";

for (const [width, count] of [
  [390, 125],
  [1440, 0],
]) {
  test(`circle values and uninterrupted map at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    let requests = 0;
    await page.route("**/geo/regions.json", (route) =>
      route.fulfill({
        json: {
          type: "FeatureCollection",
          features: [
            {
              type: "Feature",
              properties: { geo_code: "01", name: "Test region" },
              geometry: {
                type: "Polygon",
                coordinates: [
                  [
                    [120, 10],
                    [122, 10],
                    [122, 12],
                    [120, 12],
                    [120, 10],
                  ],
                ],
              },
            },
          ],
        },
      }),
    );
    await page.route("**/api/dataset-map?**", (route) => {
      requests++;
      return route.fulfill({
        json: {
          state: "available",
          rows: [{ code: "01", name: "Test region", value: count }],
          total: count,
          area: "Philippines",
          childLevel: "region",
          source: "Synthetic",
          date: "2025-12-31",
          license: null,
          geometryUrl: `${route.request().url().split("/api/")[0]}/geo/regions.json`,
        },
      });
    });
    await page.goto("map?dataset=bhw-stepzero-2026");
    const canvas = page.locator('[data-map-state="ready"]');
    await expect(canvas).toBeVisible();
    async function clickCenter() {
      const box = await canvas.boundingBox();
      await canvas.click({ position: { x: box!.width / 2, y: box!.height / 2 } });
    }
    await clickCenter();
    const value = page.getByRole("status", { name: "Selected map value" });
    await expect(value).toContainText("Test region");
    await expect(value).toContainText(`${count} people`);
    await page.getByRole("button", { name: "Close map value" }).click();
    await expect(value).toBeHidden();
    await page.getByRole("button", { name: "Map only", exact: true }).click();
    const dialog = page.getByRole("dialog", { name: "Map only", exact: true });
    await expect(dialog).toBeVisible();
    await expect
      .poll(async () => {
        const box = await canvas.boundingBox();
        return { x: box?.x, y: box?.y, width: box?.width, height: box?.height };
      })
      .toEqual({ x: 0, y: 0, width, height: 844 });
    await expect(page.getByRole("group", { name: "Map viewport controls" })).toBeHidden();
    expect(await page.evaluate(() => document.body.style.overflow)).toBe("hidden");
    await clickCenter();
    await expect(value).toContainText(`${count} people`);
    await page.getByRole("button", { name: "Exit map only" }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByRole("button", { name: "Map only", exact: true })).toBeFocused();
    expect(await page.evaluate(() => document.body.style.overflow)).not.toBe("hidden");
    await page.getByRole("button", { name: "Map only", exact: true }).click();
    await page.keyboard.press("Escape");
    await expect(dialog).toBeHidden();
    expect(requests).toBe(1);
    await expect(page.locator(".maplibregl-map")).toHaveCount(1);
  });
}
