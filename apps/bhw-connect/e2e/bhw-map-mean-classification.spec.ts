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
  test(`mean classification restores quantile defaults and shared custom breaks at ${width}px`, async ({
    page,
  }, testInfo) => {
    await page.setViewportSize({ width, height: 844 });
    await fixture(page);
    await page.goto(
      "map?dataset=bhw-2025&indicator=bhw.active-years&level=region&code=07&valueMin=12.34",
    );
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByText("Color classification", { exact: true }).click();
    await expect(page.getByLabel("Grouping method")).toHaveValue("quantile");
    await page.getByLabel("Grouping method").selectOption("custom");
    await page.getByLabel("Custom cut points", { exact: true }).fill("0,12.34");
    await page.getByRole("button", { name: "Apply classification", exact: true }).click();
    await expect(
      page.getByRole("alert").filter({ hasText: "positive, finite, increasing" }),
    ).toBeVisible();
    await page.getByLabel("Custom cut points", { exact: true }).fill("12.34,101.25");
    await page.getByLabel("Custom cut points", { exact: true }).press("Enter");
    await expect(page).toHaveURL(/classification=custom&classBreaks=12.34%2C101.25/);
    await expect(page.getByLabel("Effective classification")).toContainText(
      "Mean active years start at zero",
    );
    const pending = page.waitForEvent("download");
    await page.getByRole("button", { name: "Download map data (JSON)", exact: true }).click();
    const stream = await (await pending).createReadStream();
    const chunks = [];
    for await (const chunk of stream!) chunks.push(chunk);
    const data = JSON.parse(Buffer.concat(chunks).toString());
    expect(data.legend.method).toBe("custom-mean-year-cut-points");
    expect(data.legend.bins.map((b: { label: string }) => b.label)).toEqual([
      "0–<12.34 years",
      "12.34–<101.25 years",
      "101.25 years or more",
    ]);
    expect(data.rows).toHaveLength(5);
    expect(data.parentMeasure.value).toBe(9.87);
    expect(data.valueFilter.minimum).toBe(12.34);
    await page.goBack();
    await expect(page).not.toHaveURL(/classification=/);
    await page.goForward();
    await expect(page).toHaveURL(/classification=custom/);
    if (width === 1440) {
      const png = page.waitForEvent("download");
      await page.getByRole("button", { name: "Download map image (PNG)", exact: true }).click();
      await (await png).saveAs(testInfo.outputPath("mean-classification.png"));
    }
    await page.getByRole("link", { name: "Share this map selection" }).click();
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByText("Color classification", { exact: true }).click();
    await expect(page.getByLabel("Custom cut points", { exact: true })).toHaveValue("12.34,101.25");
    await page.getByLabel("Grouping method").selectOption("equal");
    await page.getByLabel("Requested groups").selectOption("3");
    await page.getByRole("button", { name: "Apply classification", exact: true }).click();
    await expect(page).toHaveURL(/classification=equal&classCount=3/);
    await expect(page.getByLabel("Effective classification")).toContainText("3 actual groups");
    await page.getByText("Color classification", { exact: true }).click();
    await page.getByRole("button", { name: "Reset classification", exact: true }).click();
    await expect(page).not.toHaveURL(/classification=/);
    await expect(page).toHaveURL(/valueMin=12.34/);
    await expect(page.getByLabel("Effective classification")).toContainText("Quantiles:");
  });
}
