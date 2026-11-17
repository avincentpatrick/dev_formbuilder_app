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
  test(`percentage classification restores fixed defaults and shared custom breaks at ${width}px`, async ({
    page,
  }, testInfo) => {
    await page.setViewportSize({ width, height: 844 });
    await fixture(page);
    await page.goto(
      "map?dataset=bhw-stepzero-2026&indicator=bhw.declared-accreditation&level=region&code=07&valueMin=25.5",
    );
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByText("Color classification", { exact: true }).click();
    await expect(page.getByLabel("Grouping method")).toHaveValue("fixed");
    await page.getByLabel("Grouping method").selectOption("custom");
    await page.getByLabel("Custom cut points", { exact: true }).fill("25.5,100");
    await page.getByRole("button", { name: "Apply classification", exact: true }).click();
    await expect(
      page.getByRole("alert").filter({ hasText: "strictly between 0 and 100" }),
    ).toBeVisible();
    await page.getByLabel("Custom cut points", { exact: true }).fill("25.5,75");
    await page.getByLabel("Custom cut points", { exact: true }).press("Enter");
    await expect(page).toHaveURL(/classification=custom&classBreaks=25.5%2C75/);
    await expect(page.getByLabel("Effective classification")).toContainText("Groups cover 0–100%");
    const pending = page.waitForEvent("download");
    await page.getByRole("button", { name: "Download map data (JSON)", exact: true }).click();
    const stream = await (await pending).createReadStream();
    const chunks = [];
    for await (const chunk of stream!) chunks.push(chunk);
    const data = JSON.parse(Buffer.concat(chunks).toString());
    expect(data.legend.method).toBe("custom-percentage-cut-points");
    expect(data.legend.bins.map((b: { label: string }) => b.label)).toEqual([
      "0–<25.5%",
      "25.5–<75%",
      "75–100%",
    ]);
    expect(data.rows).toHaveLength(5);
    expect(data.parentMeasure.value).toBe(42.1);
    expect(data.valueFilter.minimum).toBe(25.5);
    await page.goBack();
    await expect(page).not.toHaveURL(/classification=/);
    await page.goForward();
    await expect(page).toHaveURL(/classification=custom/);
    if (width === 1440) {
      const png = page.waitForEvent("download");
      await page.getByRole("button", { name: "Download map image (PNG)", exact: true }).click();
      await (await png).saveAs(testInfo.outputPath("percentage-classification.png"));
    }
    // Open the share URL as a fresh navigation. A same-URL client refresh can
    // otherwise replace controls after the test has already opened them.
    const sharedUrl = await page
      .getByRole("link", { name: "Share this map selection" })
      .getAttribute("href");
    expect(sharedUrl).not.toBeNull();
    await page.goto(sharedUrl!);
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByText("Color classification", { exact: true }).click();
    await expect(page.getByLabel("Custom cut points", { exact: true })).toHaveValue("25.5,75");
    await page.getByLabel("Grouping method").selectOption("equal");
    await page.getByLabel("Requested groups").selectOption("3");
    await page.getByRole("button", { name: "Apply classification", exact: true }).click();
    await expect(page).toHaveURL(/classification=equal&classCount=3/);
    await expect(page.getByLabel("Effective classification")).toContainText("3 actual groups");
    await page.getByText("Color classification", { exact: true }).click();
    await page.getByRole("button", { name: "Reset classification", exact: true }).click();
    await expect(page).not.toHaveURL(/classification=/);
    await expect(page).toHaveURL(/valueMin=25.5/);
    await expect(page.getByLabel("Effective classification")).toContainText(
      "Fixed percentage breaks",
    );
  });
}
