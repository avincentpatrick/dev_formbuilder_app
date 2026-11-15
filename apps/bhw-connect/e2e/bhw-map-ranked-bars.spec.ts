import { expect, test, type Page } from "@playwright/test";
async function fixture(page: Page, empty = false) {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        area: "Region VII",
        total: 225,
        childLevel: "province",
        source: "Synthetic fixture",
        date: "2025-12-31",
        license: "Test only",
        rows: [
          { code: "07012", name: "Bohol", value: empty ? null : 0 },
          { code: "07022", name: "Cebu", value: empty ? null : 100 },
          { code: "07306", name: "Cebu City", value: empty ? null : 100 },
          { code: "07311", name: "Lapu-Lapu", value: null },
          { code: "07313", name: "Mandaue", value: empty ? null : 25 },
        ],
        geometryUrl: `${route.request().url().split("/api/")[0]}/geo/provinces/07.json`,
      },
    }),
  );
}
for (const width of [390, 1440]) {
  test(`count bars and map selection stay linked at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await fixture(page);
    await page.goto("map?dataset=bhw-stepzero-2026&level=region&code=07");
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    const bars = page.getByRole("region", { name: "Ranked area counts", exact: true });
    await expect(bars.getByRole("button")).toHaveCount(4);
    await expect(bars.getByRole("button").first()).toContainText("Cebu (07022)");
    await expect(bars.getByRole("button").last()).toContainText("Bohol (07012)0");
    await page.getByLabel("BHW Headcount 2025 search areas").fill("Bohol");
    await expect(bars.getByRole("button")).toHaveCount(4);
    const huc = bars.getByRole("button", { name: /Cebu City/ });
    await huc.focus();
    await page.keyboard.press("Enter");
    await expect(huc).toHaveAttribute("aria-pressed", "true");
    await expect(page.getByLabel("BHW Headcount 2025 area", { exact: true })).toHaveValue("07306");
    await page.getByRole("button", { name: "Clear area search" }).click();
    await page.getByLabel("BHW Headcount 2025 area", { exact: true }).selectOption("07012");
    await expect(bars.getByRole("button", { name: /Bohol/ })).toHaveAttribute(
      "aria-pressed",
      "true",
    );
    await huc.click();
    if (width < 1024)
      await page.getByRole("button", { name: "Map and legend", exact: true }).click();
    await expect(page.locator('[data-locator-state="unavailable"]')).toBeVisible();
    await page.getByRole("link", { name: "Share this map selection" }).click();
    await expect(page).toHaveURL(/selected=07306/);
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await expect(bars.getByRole("button", { name: /Cebu City/ })).toHaveAttribute(
      "aria-pressed",
      "true",
    );
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
test("unavailable counts never become zero bars", async ({ page }) => {
  await fixture(page, true);
  await page.goto("map?dataset=bhw-stepzero-2026&level=region&code=07");
  await expect(page.getByText("No usable counts to rank. This does not mean zero.")).toBeVisible();
  await expect(
    page.getByRole("region", { name: "Ranked area counts", exact: true }).getByRole("button"),
  ).toHaveCount(0);
});
test("rate views do not inherit count bars", async ({ page }) => {
  await fixture(page);
  await page.goto(
    "map?dataset=bhw-stepzero-2026&level=region&code=07&indicator=bhw.declared-accreditation",
  );
  await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
  await expect(page.getByRole("region", { name: "Ranked area counts", exact: true })).toHaveCount(
    0,
  );
});
