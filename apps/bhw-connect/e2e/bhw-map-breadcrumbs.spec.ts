import { expect, test, type Page } from "@playwright/test";
async function fixture(page: Page, incomplete = false) {
  await page.route("**/api/dataset-map?**", (route) => {
    const url = new URL(route.request().url());
    const province = url.searchParams.get("level") === "province";
    return route.fulfill({
      json: {
        state: "available",
        rows: [
          {
            code: province ? "0702234" : "07022",
            name: province ? "City of Naga" : "Cebu",
            value: 10,
          },
        ],
        total: 10,
        area: province ? "Cebu" : "Region VII",
        childLevel: province ? "citymun" : "province",
        navigation: {
          parents:
            province && !incomplete ? [{ level: "region", code: "07", name: "Region VII" }] : [],
          complete: !incomplete,
        },
        source: "Synthetic fixture",
        date: "2025-12-31",
        license: "Test only",
        geometryUrl: `${url.origin}/bhw-connect/geo/${province ? "citymun/07022" : "provinces/07"}.json`,
      },
    });
  });
}
for (const width of [390, 1440]) {
  test(`direct map breadcrumbs retain measure and view at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await fixture(page);
    await page.goto(
      "map?dataset=bhw-stepzero-2026&level=province&code=07022&indicator=bhw.registered&view=choropleth&selected=0702234",
    );
    const trail = page
      .getByRole("navigation", { name: "Map geography", exact: true })
      .filter({ has: page.getByText("Cebu", { exact: true }) });
    await expect(trail.locator('[aria-current="page"]')).toHaveText("Cebu");
    const parent = trail.getByRole("link", { name: "Region VII", exact: true });
    await expect(parent).toHaveAttribute("href", /indicator=bhw.registered/);
    await expect(parent).toHaveAttribute("href", /view=choropleth/);
    await expect(parent).not.toHaveAttribute("href", /selected=/);
    await parent.focus();
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/level=region&code=07/);
    await expect(
      page
        .getByRole("navigation", { name: "Map geography", exact: true })
        .locator('[aria-current="page"]')
        .last(),
    ).toHaveText("Region VII");
    await page.goBack();
    await expect(trail.locator('[aria-current="page"]')).toHaveText("Cebu");
    await expect(page).toHaveURL(/selected=0702234/);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
test("missing parent names do not invent hierarchy or block the map", async ({ page }) => {
  await fixture(page, true);
  await page.goto("map?dataset=bhw-stepzero-2026&level=province&code=07022");
  await expect(
    page.getByText("Some parent place names are unavailable. No parent links are inferred."),
  ).toBeVisible();
  await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
  await expect(page.getByRole("link", { name: "Region VII", exact: true })).toHaveCount(0);
});

test("direct municipality links resolve both named ancestors", async ({ page }) => {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        rows: [],
        total: null,
        area: "City of Naga",
        childLevel: "barangay",
        navigation: {
          parents: [
            { level: "region", code: "07", name: "Region VII" },
            { level: "province", code: "07022", name: "Cebu" },
          ],
          complete: true,
        },
        source: "Synthetic fixture",
        date: "2025-12-31",
        license: "Test only",
        geometryUrl: `${new URL(route.request().url()).origin}/bhw-connect/geo/regions.json`,
      },
    }),
  );
  await page.goto("map?dataset=bhw-stepzero-2026&level=citymun&code=0702234");
  const trail = page
    .getByRole("navigation", { name: "Map geography", exact: true })
    .filter({ has: page.getByText("City of Naga", { exact: true }) });
  await expect(trail.getByRole("link", { name: "Region VII", exact: true })).toHaveAttribute(
    "href",
    /level=region&code=07/,
  );
  await expect(trail.getByRole("link", { name: "Cebu", exact: true })).toHaveAttribute(
    "href",
    /level=province&code=07022/,
  );
  await expect(trail.locator('[aria-current="page"]')).toHaveText("City of Naga");
});
