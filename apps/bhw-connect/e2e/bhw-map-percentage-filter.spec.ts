import { expect, test, type Page } from "@playwright/test";
async function fixture(page: Page, empty = false) {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        rows: [
          { code: "07012", name: "Bohol", value: empty ? null : 0 },
          { code: "07022", name: "Cebu", value: empty ? null : 100 },
          { code: "07306", name: "Cebu City", value: empty ? null : 50.5 },
          { code: "07311", name: "Lapu-Lapu", value: null },
          { code: "07313", name: "Mandaue", value: empty ? null : 25.5 },
        ],
        total: null,
        parentRate: 42.1,
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
  test(`percentage filter retains published rates and share state at ${width}px`, async ({
    page,
  }, testInfo) => {
    await page.setViewportSize({ width, height: 844 });
    await fixture(page);
    await page.goto(
      "map?dataset=bhw-stepzero-2026&indicator=bhw.declared-accreditation&level=region&code=07",
    );
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByText("Filter percentages", { exact: true }).click();
    await page.getByLabel("Minimum percentage (%)", { exact: true }).fill("25.5");
    await page.getByLabel("Maximum percentage (%)", { exact: true }).fill("101");
    await page.getByRole("button", { name: "Apply percentage range", exact: true }).click();
    await expect(page.getByRole("alert").filter({ hasText: "0 to 100" })).toBeVisible();
    await page.getByLabel("Maximum percentage (%)", { exact: true }).fill("50.5");
    await page.getByLabel("Maximum percentage (%)", { exact: true }).press("Enter");
    await expect(page).toHaveURL(/valueMin=25.5&valueMax=50.5/);
    await expect(page.getByRole("region", { name: "Ranked area counts", exact: true })).toHaveCount(
      0,
    );
    const pending = page.waitForEvent("download");
    await page.getByRole("button", { name: "Download map data (JSON)", exact: true }).click();
    const stream = await (await pending).createReadStream();
    const chunks = [];
    for await (const chunk of stream!) chunks.push(chunk);
    const data = JSON.parse(Buffer.concat(chunks).toString());
    expect(data.parentMeasure).toMatchObject({ kind: "rate", value: 42.1 });
    expect(data.rows).toHaveLength(5);
    expect(data.valueFilter).toMatchObject({
      unit: "%",
      minimum: 25.5,
      maximum: 50.5,
      included: 2,
      excluded: 2,
      unavailable: 1,
    });
    expect(data.legend.method).toBe("fixed");
    await page.goBack();
    await expect(page).not.toHaveURL(/valueMin=/);
    await page.goForward();
    await expect(page).toHaveURL(/valueMin=25.5/);
    if (width === 1440) {
      const png = page.waitForEvent("download");
      await page.getByRole("button", { name: "Download map image (PNG)", exact: true }).click();
      await (await png).saveAs(testInfo.outputPath("percentage-filter.png"));
    }
    await page.getByRole("link", { name: "Share this map selection" }).click();
    await expect(page).toHaveURL(/valueMax=50.5/);
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByText("Filter percentages", { exact: true }).click();
    await expect(page.getByLabel("Minimum percentage (%)", { exact: true })).toHaveValue("25.5");
    await page.getByRole("button", { name: "Reset percentage range", exact: true }).click();
    await expect(page).not.toHaveURL(/valueMin=/);
  });
}
test("percentage limits and scope are validated on direct links", async ({ page }) => {
  for (const suffix of ["valueMax=100.1", "valueMin=1&level=citymun&code=0702234"]) {
    await page.goto(`map?dataset=bhw-stepzero-2026&indicator=bhw.declared-accreditation&${suffix}`);
    await expect(page.getByText(/If a shared link has an unsupported indicator/)).toBeVisible();
  }
});
