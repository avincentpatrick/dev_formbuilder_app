import { expect, test } from "@playwright/test";
const rows = [
  { code: "0102802", name: "Bacarra", value: null },
  {
    code: "0102801",
    name: "Adams — synthetic long municipality name for readable tables",
    value: 0,
  },
  { code: "0102803", name: "Badoc", value: 123456 },
];
for (const width of [390, 1440]) {
  test(`Population table search and map return preserve scope at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.route("**/api/dataset-map?**", (route) =>
      route.fulfill({
        json: {
          state: "available",
          childLevel: "citymun",
          valueSupport: "supported",
          total: 123456,
          area: "Synthetic province",
          source: "Synthetic PSA source",
          date: "2024-07-01",
          license: "Test fixture",
          rows,
          geometryUrl: `${new URL(route.request().url()).origin}/bhw-connect/geo/citymun/01028.json`,
        },
      }),
    );
    await page.goto("map?dataset=psa-popcen-2024&level=province&code=01028");
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    const summary = page.getByText("Population area table (3 of 3)", { exact: true });
    await summary.focus();
    await page.keyboard.press("Enter");
    const table = page.getByRole("region", { name: "PSA population 2024 map table" });
    await expect(table.getByRole("row").nth(1)).toContainText("Adams");
    await expect(table.getByRole("row").nth(1).getByRole("cell").first()).toContainText(/^0/);
    await expect(table).toContainText("Unavailable — no accepted count");
    await expect(table).toContainText("123,456");
    const search = page.getByRole("searchbox", { name: "PSA population 2024 search areas" });
    await search.fill("0102802");
    await expect(table.getByRole("row")).toHaveCount(2);
    await table.getByRole("button", { name: "Bacarra", exact: true }).click();
    await expect(page).toHaveURL(/selected=0102802/);
    await page.getByRole("button", { name: "Clear area search" }).click();
    await page.getByRole("combobox", { name: "PSA population 2024 area" }).selectOption("0102801");
    await page.goBack();
    await expect(page.getByRole("combobox", { name: "PSA population 2024 area" })).toHaveValue(
      "0102802",
    );
    await page.goForward();
    await expect(page.getByRole("combobox", { name: "PSA population 2024 area" })).toHaveValue(
      "0102801",
    );
    await search.fill("no match");
    await expect(
      page.getByText("No matching areas. Clear the search or try another name or PSGC code."),
    ).toBeVisible();
    await expect(page.getByRole("combobox", { name: "PSA population 2024 area" })).toHaveValue(
      "0102801",
    );
    await search.fill("0102801");
    await page.reload();
    await expect(search).toHaveValue("0102801");
    await expect(page.getByRole("link", { name: "Share this map selection" })).toHaveAttribute(
      "href",
      /populationSearch=0102801/,
    );
    await page.getByRole("link", { name: "Open Population details for this selection" }).click();
    await expect(page).toHaveURL(/location=citymun%3A0102801/);
    await page
      .getByRole("navigation", { name: "Location ancestors" })
      .getByRole("link")
      .filter({ hasText: /ILOCOS NORTE/i })
      .click();
    await expect(page).toHaveURL(/location=province%3A01028/);
    await expect(page.getByTestId("population-overview")).toBeVisible();
    await page.getByRole("link", { name: "Return to previous Population map" }).click();
    await expect(search).toHaveValue("0102801");
    await expect(page.getByRole("combobox", { name: "PSA population 2024 area" })).toHaveValue(
      "0102801",
    );
    await page.getByRole("link", { name: "← Population dataset" }).click();
    await expect(page).toHaveURL(/location=citymun%3A0102801/);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}

test("Population map keeps partial province counts separate from published Manila and missing rows", async ({
  page,
}) => {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        childLevel: "province",
        valueSupport: "supported",
        total: 246912,
        area: "Synthetic region",
        source: "Synthetic PSA source",
        date: "2024-07-01",
        license: "Test fixture",
        geometryUrl: `${new URL(route.request().url()).origin}/bhw-connect/geo/province/13.json`,
        rows: [
          { code: "13806", name: "Manila", value: 123456 },
          { code: "13740", name: "Other province", value: 123456 },
          { code: "13750", name: "Missing province", value: null },
        ],
      },
    }),
  );
  await page.goto("map?dataset=psa-popcen-2024&level=region&code=13");
  await page.getByText("Population area table (3 of 3)", { exact: true }).click();
  const table = page.getByRole("region", { name: "PSA population 2024 map table" });
  await expect(table.getByRole("row").filter({ hasText: "PSGC 13806" })).toContainText(
    "Published Manila city population",
  );
  await expect(table.getByRole("row").filter({ hasText: "PSGC 13740" })).toContainText(
    "Partial total",
  );
  await expect(table.getByRole("row").filter({ hasText: "PSGC 13750" })).toContainText(
    "Unavailable — no accepted count",
  );
});
