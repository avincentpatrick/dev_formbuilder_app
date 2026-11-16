import { expect, test, type Page } from "@playwright/test";
async function fixture(page: Page, empty = false) {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        rows: [
          { code: "07012", name: "Bohol", value: empty ? null : 0 },
          { code: "07022", name: "Cebu", value: empty ? null : 101.25 },
          { code: "07306", name: "Cebu City", value: empty ? null : 20.5 },
          { code: "07311", name: "Lapu-Lapu", value: null },
          { code: "07313", name: "Mandaue", value: empty ? null : 12.34 },
        ],
        total: null,
        parentMean: 9.87,
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
  test(`catalog restores edited BHW analysis after reload at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await fixture(page);
    await page.goto(
      "map?dataset=bhw-2025&indicator=bhw.active-years&level=region&code=07&view=choropleth&classification=custom&classBreaks=10,20&valueMin=5&valueMax=101.25",
    );
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByText("Filter mean active years", { exact: true }).click();
    await page.getByLabel("Minimum mean active years", { exact: true }).fill("12.34");
    await page.getByRole("button", { name: "Apply years range", exact: true }).click();
    await page.getByLabel("BHW Profiles 2025 area", { exact: true }).selectOption("07306");
    await expect(page).toHaveURL(/selected=07306/);
    const previous = page.url();
    await page.getByRole("link", { name: "← BHW dataset", exact: true }).click();
    await expect(page).toHaveURL(/catalog.*mapState=/);
    await page.reload();
    const back = page.getByRole("link", { name: "Return to previous BHW map", exact: true });
    await expect(back).toHaveAttribute(
      "href",
      new URL(previous).pathname + new URL(previous).search,
    );
    await back.click();
    await expect(page).toHaveURL(previous);
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    await expect(page.getByLabel("Effective classification")).toContainText(
      "Custom cut points: 3 actual groups",
    );
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await expect(page.getByLabel("BHW Profiles 2025 area", { exact: true })).toHaveValue("07306");
    await page.getByText("Filter mean active years", { exact: true }).click();
    await expect(page.getByLabel("Minimum mean active years", { exact: true })).toHaveValue(
      "12.34",
    );
    await page.goBack();
    await expect(back).toBeVisible();
    await page.goForward();
    await expect(page).toHaveURL(previous);
  });
}
test("invalid or non-BHW return settings are explained without a return link", async ({ page }) => {
  for (const raw of [
    "dataset=bhw-2025&valueMin=-1",
    "https://example.com",
    "dataset=bhw-profiling-status-2026",
  ]) {
    await page.goto(`catalog?mode=all&dataset=bhw&mapState=${encodeURIComponent(raw)}`);
    await expect(
      page.getByText(
        "The previous map settings are invalid or unsupported. Open a new map from the dataset details.",
      ),
    ).toBeVisible();
    await expect(
      page.getByRole("link", { name: "Return to previous BHW map", exact: true }),
    ).toHaveCount(0);
  }
});
