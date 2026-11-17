import { expect, test } from "@playwright/test";
for (const width of [390, 1440]) {
  test(`zoom loads finer counts and returns to parents at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    const requested: string[] = [];
    await page.route("**/api/dataset-map?**", (route) => {
      const level = (new URL(route.request().url()).searchParams.get("level") ?? "national") as
        "national" | "region" | "province" | "citymun";
      requested.push(level);
      const child = {
        national: "region",
        region: "province",
        province: "citymun",
        citymun: "barangay",
      }[level]!;
      const code = { national: "01", region: "01028", province: "0102801", citymun: "0102801001" }[
        level
      ]!;
      return route.fulfill({
        json: {
          state: "available",
          rows: [
            {
              code,
              name: `Synthetic ${child}`,
              value: { national: 100, region: 60, province: 20, citymun: 5 }[level],
            },
          ],
          total: 100,
          area: `Synthetic ${level}`,
          childLevel: child,
          barangayDrill: true,
          navigation: {
            complete: true,
            parents:
              level === "citymun"
                ? [
                    { level: "region", code: "01", name: "Region" },
                    { level: "province", code: "01028", name: "Province" },
                  ]
                : level === "province"
                  ? [{ level: "region", code: "01", name: "Region" }]
                  : [],
          },
          source: "Fixture",
          date: null,
          license: null,
          geometryUrl: `${new URL(route.request().url()).origin}/bhw-connect/geo/zoom-${code}.json`,
        },
      });
    });
    await page.route("**/geo/zoom-*.json", (route) => {
      const code = route
        .request()
        .url()
        .match(/zoom-(\d+)\.json/)![1];
      const d = code.length === 2 ? 12 : code.length === 5 ? 3 : code.length === 7 ? 0.8 : 0.2;
      return route.fulfill({
        json: {
          type: "FeatureCollection",
          features: [
            {
              type: "Feature",
              properties: { geo_code: code },
              geometry: {
                type: "Polygon",
                coordinates: [
                  [
                    [122 - d, 12 - d],
                    [122 + d, 12 - d],
                    [122 + d, 12 + d],
                    [122 - d, 12 + d],
                    [122 - d, 12 - d],
                  ],
                ],
              },
            },
          ],
        },
      });
    });
    await page.goto("map?dataset=bhw-stepzero-2026&valueMin=2");
    const ready = page.locator('[data-map-state="ready"]');
    await expect(ready).toBeVisible();
    for (const level of ["region", "province", "citymun"] as const) {
      for (
        let attempt = 0;
        attempt < 5 && new URL(page.url()).searchParams.get("level") !== level;
        attempt++
      ) {
        if (width === 1440 && level === "region") {
          await ready.hover();
          await page.keyboard.down("Control");
          await page.mouse.wheel(0, -600);
          await page.keyboard.up("Control");
        } else await page.getByRole("button", { name: "Zoom in", exact: true }).click();
        await page.waitForTimeout(400);
        await expect(ready).toBeVisible();
      }
      await expect(page).toHaveURL((url) => url.searchParams.get("level") === level);
      await expect(page).toHaveURL(/valueMin=2/);
      await expect(
        page.getByText(
          {
            region: "Province figures",
            province: "City/municipality figures",
            citymun: "Barangay figures",
          }[level]!,
          { exact: true },
        ),
      ).toBeVisible();
    }
    for (const level of ["province", "region", "national"]) {
      for (
        let attempt = 0;
        attempt < 5 && (new URL(page.url()).searchParams.get("level") ?? "national") !== level;
        attempt++
      ) {
        await page.getByRole("button", { name: "Zoom out", exact: true }).click();
        await page.waitForTimeout(400);
        await expect(ready).toBeVisible();
      }
      await expect(page).toHaveURL(
        (url) => (url.searchParams.get("level") ?? "national") === level,
      );
    }
    await expect
      .poll(() => requested)
      .toEqual(["national", "region", "province", "citymun", "province", "region", "national"]);
    await page.reload();
    await expect(ready).toBeVisible();
  });
}
