import { expect, test, type Page } from "@playwright/test";

// The made-up seed carries no district membership, so these fixtures stand in for
// /api/district-map. The geometry is the committed Ilocos Norte file; figures come from a fixture too.
const index = {
  state: "available",
  provinces: [{ code: "01028", name: "Synthetic Ilocos Norte" }],
  districts: [
    { code: "syn-1st", name: "Syn's 1st congressional district", province: "01028" },
    { code: "syn-2nd", name: "Syn's 2nd congressional district", province: "01028" },
  ],
};
const province = {
  state: "available",
  province: { code: "01028", name: "Synthetic Ilocos Norte" },
  municipalities: [
    { code: "0102801", name: "Adams", districts: ["syn-1st"], byBarangay: false },
    { code: "0102802", name: "Bacarra", districts: ["syn-1st", "syn-2nd"], byBarangay: true },
    { code: "0102803", name: "Badoc", districts: ["syn-2nd"], byBarangay: false },
    { code: "0102804", name: "Unplaced town", districts: [], byBarangay: false },
  ],
  districts: [
    { code: "syn-1st", name: "Syn's 1st congressional district" },
    { code: "syn-2nd", name: "Syn's 2nd congressional district" },
  ],
  otherProvinces: [],
  source: "Synthetic district source",
  date: "2025-06-30",
  license: "Test only",
  geometryUrl: "/bhw-connect/geo/citymun/01028.json",
};

async function fixtures(page: Page) {
  await page.route("**/api/district-map**", (route) => {
    const url = new URL(route.request().url());
    return route.fulfill({
      json: url.searchParams.has("province") || url.searchParams.has("district") ? province : index,
    });
  });
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        rows: [
          { code: "0102801", name: "Adams", value: 12 },
          { code: "0102802", name: "Bacarra", value: 0 },
          { code: "0102803", name: "Badoc", value: 30 },
          { code: "0102804", name: "Unplaced town", value: 5 },
        ],
        total: 47,
        area: "Synthetic Ilocos Norte",
        childLevel: "citymun",
        source: "Synthetic figures",
        date: "2025-01-01",
        license: "Test only",
        geometryUrl: "/bhw-connect/geo/citymun/01028.json",
      },
    }),
  );
}

test("districts are shown through their municipalities, never as outlines or totals", async ({
  page,
}) => {
  await fixtures(page);
  await page.goto("map?dataset=ph-legislative-districts");
  await expect(page.getByText("No district outlines are drawn.")).toBeVisible();
  await expect(
    page.getByText("Choose a province to see its cities and municipalities coloured by district."),
  ).toBeVisible();

  await page.getByLabel("District map province").selectOption("01028");
  await expect(page.locator("canvas")).toHaveCount(1);
  await expect(page.getByText("Divided between districts", { exact: true })).toBeVisible();
  await expect(page.getByRole("listitem").filter({ hasText: /^Syn 1st$/ })).toBeVisible();
  await expect(page.getByText("No district recorded — see table")).toBeVisible();
  await page.getByText("Cities and municipalities (4)").click();
  const table = page.getByRole("region", { name: "District map table" });
  await expect(table.getByRole("row", { name: /Bacarra/ })).toContainText(
    "Divided between Syn 1st and Syn 2nd",
  );
  await expect(table.getByRole("row", { name: /Unplaced town/ })).toContainText("not no district");
  await expect(page).toHaveURL(/view=province&province=01028/);

  await page.getByLabel("One district's municipalities, with a figure").check();
  await page.getByLabel("District map district").selectOption("syn-2nd");
  await expect(page.getByText("Outside this district")).toBeVisible();
  await page.getByText("Member cities and municipalities (2)").click();
  await expect(table.getByRole("row", { name: /Badoc/ })).toContainText("30");
  // A divided city keeps its zero and says the figure is the whole city's.
  await expect(table.getByRole("row", { name: /Bacarra/ })).toContainText("Whole-city figure");
  await expect(table.getByRole("row", { name: /Adams/ })).toHaveCount(0);
  await expect(page.getByText(/Reported area total/)).toHaveCount(0);
  await expect(page).toHaveURL(/view=district&province=01028&district=syn-2nd&figures=/);
});

test("a shared district link opens that district", async ({ page }) => {
  await fixtures(page);
  await page.goto("map?dataset=ph-legislative-districts&view=district&district=syn-1st");
  await expect(page.getByLabel("District map district")).toHaveValue("syn-1st");
  await page.getByText("Member cities and municipalities (2)").click();
  await expect(
    page.getByRole("region", { name: "District map table" }).getByRole("row", { name: /Adams/ }),
  ).toContainText("12");
});
