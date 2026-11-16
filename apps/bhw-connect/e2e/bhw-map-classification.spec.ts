import { expect, test, type Page } from "@playwright/test";
async function fixture(page: Page, empty = false) {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        rows: [
          { code: "07012", name: "Bohol", value: empty ? null : 0 },
          { code: "07022", name: "Cebu", value: empty ? null : 100 },
          { code: "07306", name: "Cebu City", value: empty ? null : 50 },
          { code: "07311", name: "Lapu-Lapu", value: null },
          { code: "07313", name: "Mandaue", value: empty ? null : 25 },
        ],
        total: 999,
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
  test(`classification edits preserve filters, values and shared settings at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    await fixture(page);
    await page.goto(
      "map?dataset=bhw-stepzero-2026&level=region&code=07&view=choropleth&valueMin=25",
    );
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByText("Color classification", { exact: true }).click();
    await page.getByLabel("Grouping method", { exact: true }).selectOption("equal");
    await page.getByLabel("Requested groups", { exact: true }).selectOption("4");
    await page.getByRole("button", { name: "Apply classification", exact: true }).click();
    const bars = page.getByRole("region", { name: "Ranked area counts", exact: true });
    await expect(bars.getByRole("button")).toHaveCount(3);
    await page.getByText("Color classification", { exact: true }).click();
    await page.getByLabel("Grouping method", { exact: true }).selectOption("custom");
    await page.getByLabel("Custom cut points", { exact: true }).fill("50, 25");
    await page.getByRole("button", { name: "Apply classification", exact: true }).click();
    await expect(page.getByRole("alert").filter({ hasText: "Choose 2–7 groups" })).toBeVisible();
    await page.getByLabel("Custom cut points", { exact: true }).fill("25, 50");
    await page.getByLabel("Custom cut points", { exact: true }).press("Enter");
    await expect(bars.getByRole("button")).toHaveCount(3);
    const pending = page.waitForEvent("download");
    await page.getByRole("button", { name: "Download map data (JSON)" }).click();
    const stream = await (await pending).createReadStream();
    const chunks = [];
    for await (const chunk of stream!) chunks.push(chunk);
    const data = JSON.parse(Buffer.concat(chunks).toString());
    expect(data.total).toBe(999);
    expect(data.rows).toHaveLength(5);
    expect(data.legend.bins.map((b: { label: string }) => b.label)).toEqual([
      "0–<25",
      "25–<50",
      "50 or more",
    ]);
    expect(data.classification).toMatchObject({
      method: "custom",
      cutPoints: [25, 50],
      actualGroups: 3,
    });
    expect(data.valueFilter.minimum).toBe(25);
    await page.getByRole("link", { name: "Share this map selection" }).click();
    await expect(page).toHaveURL(/classification=custom/);
    await expect(page).toHaveURL(/classBreaks=25%2C50/);
    await expect(page.getByLabel("Effective classification")).toContainText(
      "Custom cut points: 3 actual groups",
    );
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByText("Color classification", { exact: true }).click();
    await page.getByRole("button", { name: "Reset classification", exact: true }).click();
    await expect(bars.getByRole("button")).toHaveCount(3);
    await page.getByRole("link", { name: "Share this map selection" }).click();
    await expect(page).not.toHaveURL(/classification=/);
    await expect(page).toHaveURL(/valueMin=25/);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
test("classification resets on indicator change and unsupported links are rejected", async ({
  page,
}) => {
  await fixture(page);
  await page.goto(
    "map?dataset=bhw-stepzero-2026&view=choropleth&classification=custom&classBreaks=25,50",
  );
  await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
  await page
    .getByLabel("BHW Headcount 2025 indicator", { exact: true })
    .selectOption("bhw.declared-accreditation");
  await expect(
    page.getByText("Color classification and count range reset for the new indicator."),
  ).toBeVisible();
  await expect(page).not.toHaveURL(/classification=/);
  await page.getByText("Color classification", { exact: true }).click();
  await expect(page.getByLabel("Grouping method", { exact: true })).toHaveValue("fixed");
  await page.goto("map?dataset=bhw-stepzero-2026&classification=custom&classBreaks=50,25");
  await expect(page.getByText(/If a shared link has an unsupported indicator/)).toBeVisible();
  await expect(page.locator('[data-map-state="ready"]')).toHaveCount(0);
});
test("an empty response reports zero actual groups without inventing boundaries", async ({
  page,
}) => {
  await fixture(page, true);
  await page.goto(
    "map?dataset=bhw-stepzero-2026&view=choropleth&classification=equal&classCount=7",
  );
  await expect(page.getByLabel("Effective classification")).toContainText(
    "0 actual groups (7 requested)",
  );
  await expect(page.getByText("No usable counts to rank. This does not mean zero.")).toBeVisible();
});
