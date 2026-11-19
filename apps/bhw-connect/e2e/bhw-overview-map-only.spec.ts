import { expect, test } from "@playwright/test";
for (const width of [390, 1440]) {
  test(`overview map-only exploration keeps profile navigation explicit at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.route("**/api/dataset-map?**", (route) =>
      route.fulfill({
        json: {
          state: "available",
          rows: [
            { code: "01", name: "Region I", value: 0 },
            { code: "02", name: "Region II", value: 20 },
            { code: "03", name: "Region III", value: null },
          ],
          total: 20,
          area: "Philippines",
          childLevel: "region",
          navigation: { parents: [], complete: true },
          source: "Synthetic fixture",
          date: "2025-12-31",
          license: "Test only",
          geometryUrl: `${new URL(route.request().url()).origin}/bhw-connect/geo/regions.json`,
        },
      }),
    );
    await page.goto("bhw?mapState=dataset%3Dbhw-2025%26indicator%3Dbhw.active-years");
    await expect(page.getByTestId("bhw-first-visual")).toBeVisible();
    const preview = page.getByRole("region", {
      name: "Workforce distribution preview",
      exact: true,
    });
    await preview.scrollIntoViewIfNeeded();
    await expect(preview.locator('[data-map-state="ready"]')).toBeVisible();
    const overviewUrl = page.url();
    await preview.getByRole("button", { name: "Map only", exact: true }).click();
    await page.getByRole("button", { name: "Show table", exact: true }).click();
    const visibleTable = page.getByRole("region", { name: "Localities in view" });
    await expect(visibleTable).toContainText("Not reported");
    await visibleTable.getByRole("button", { name: "Region II", exact: true }).click();
    await expect(page.getByRole("status", { name: "Selected map value" })).toContainText(
      "20 people",
    );
    expect(page.url()).toBe(overviewUrl);
    await expect(page.getByRole("dialog", { name: "Map only", exact: true })).toBeVisible();
    await expect(page.getByRole("link", { name: "Open locality profile" })).toHaveAttribute(
      "href",
      /location=region%3A02/,
    );
    await page.getByRole("button", { name: "Exit map only" }).click();
    await expect(preview.getByRole("button", { name: "Map only", exact: true })).toBeFocused();
    await expect(preview.locator('[data-map-state="ready"]')).toBeVisible();
    expect(page.url()).toBe(overviewUrl);
    await preview.getByRole("button", { name: "Map only", exact: true }).click();
    await page
      .getByRole("region", { name: "Localities in view" })
      .getByRole("button", { name: "Region II", exact: true })
      .click();
    await page.getByRole("link", { name: "Open locality profile" }).click();
    await expect(page).toHaveURL(/bhw\?location=region%3A02/);
  });
}
