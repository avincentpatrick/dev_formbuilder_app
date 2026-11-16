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
  test(`mean filter retains published means and share state at ${width}px`, async ({
    page,
  }, testInfo) => {
    await page.setViewportSize({ width, height: 844 });
    await fixture(page);
    await page.goto("map?dataset=bhw-2025&indicator=bhw.active-years&level=region&code=07");
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByText("Filter mean active years", { exact: true }).click();
    await page.getByLabel("Minimum mean active years", { exact: true }).fill("12.34");
    await page.getByLabel("Maximum mean active years", { exact: true }).fill("-1");
    await page.getByRole("button", { name: "Apply years range", exact: true }).click();
    await expect(page.getByRole("alert").filter({ hasText: "nonnegative years" })).toBeVisible();
    await page.getByLabel("Maximum mean active years", { exact: true }).fill("20.5");
    await page.getByLabel("Maximum mean active years", { exact: true }).press("Enter");
    await expect(page).toHaveURL(/valueMin=12.34&valueMax=20.5/);
    await expect(page.getByRole("region", { name: "Ranked area counts", exact: true })).toHaveCount(
      0,
    );
    const pending = page.waitForEvent("download");
    await page.getByRole("button", { name: "Download map data (JSON)", exact: true }).click();
    const stream = await (await pending).createReadStream();
    const chunks = [];
    for await (const chunk of stream!) chunks.push(chunk);
    const data = JSON.parse(Buffer.concat(chunks).toString());
    expect(data.parentMeasure).toMatchObject({ kind: "mean", value: 9.87 });
    expect(data.rows).toHaveLength(5);
    expect(data.valueFilter).toMatchObject({
      unit: "years",
      minimum: 12.34,
      maximum: 20.5,
      included: 2,
      excluded: 2,
      unavailable: 1,
    });
    expect(data.legend.method).toBe("quantiles-relative-to-displayed-areas");
    expect(data.legend.bins[0].min).toBe(0);
    expect(data.legend.bins.at(-1).max).toBe(101.25);
    expect(data.indicator.denominator).toContain("contributing count not supplied");
    expect(data.indicator.confidenceLevel).toBeNull();
    await expect(page.getByLabel("Effective classification")).toContainText("child-area means");
    await page.goBack();
    await expect(page).not.toHaveURL(/valueMin=/);
    await page.goForward();
    await expect(page).toHaveURL(/valueMin=12.34/);
    if (width === 1440) {
      const png = page.waitForEvent("download");
      await page.getByRole("button", { name: "Download map image (PNG)", exact: true }).click();
      await (await png).saveAs(testInfo.outputPath("mean-filter.png"));
    }
    await page.getByRole("link", { name: "Share this map selection" }).click();
    await expect(page).toHaveURL(/valueMax=20.5/);
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByText("Filter mean active years", { exact: true }).click();
    await expect(page.getByLabel("Minimum mean active years", { exact: true })).toHaveValue(
      "12.34",
    );
    await page.getByRole("button", { name: "Reset years range", exact: true }).click();
    await expect(page).not.toHaveURL(/valueMin=/);
  });
}
test("mean limits and scope are validated on direct links", async ({ page }) => {
  for (const suffix of ["valueMax=-1", "valueMin=1&level=citymun&code=0702234"]) {
    await page.goto(`map?dataset=bhw-2025&indicator=bhw.active-years&${suffix}`);
    await expect(page.getByText(/If a shared link has an unsupported indicator/)).toBeVisible();
  }
});
