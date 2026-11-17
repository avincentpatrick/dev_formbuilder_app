import { expect, test } from "@playwright/test";
for (const width of [390, 1440]) {
  test(`overview synchronizes the locality with its headcount map at ${width}px`, async ({
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
    if (width < 1024)
      await preview.getByRole("button", { name: /map legend and reading guide/ }).click();
    await expect(
      preview.getByText("Circle area represents BHW count", { exact: false }),
    ).toBeVisible();
    if (width < 1024)
      await preview.getByRole("button", { name: "Areas and data", exact: true }).click();
    await expect(
      preview.getByRole("region", { name: "Ranked area counts", exact: true }),
    ).toBeVisible();
    await preview.getByLabel("BHW Headcount 2025 area", { exact: true }).selectOption("02");
    await expect(page).toHaveURL(/bhw\?location=region%3A02/);
    await expect(page.getByTestId("bhw-first-visual")).toContainText(/REGION II/i);
    const enlarge = page.getByRole("link", { name: /^Enlarge .* map$/ });
    const destination = new URL((await enlarge.getAttribute("href"))!, page.url());
    expect(destination.searchParams.get("level")).toBe("region");
    expect(destination.searchParams.get("code")).toBe("02");
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
