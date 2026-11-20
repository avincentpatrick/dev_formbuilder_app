import { expect, test } from "@playwright/test";
import { missingCebuShapes } from "./helpers/missing-cebu-shapes";
for (const width of [390, 1440]) {
  test(`audited external contexts retain dates, scope and unavailable estimates at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.route("**/api/dataset-map?**", (route) => {
      const indicator = new URL(route.request().url()).searchParams.get("indicator");
      const poverty = indicator === "poverty.incidence";
      const psa = indicator === "population.residents";
      return route.fulfill({
        json: {
          state: "available",
          area: "Synthetic province",
          childLevel: "citymun",
          source: poverty ? "PSA poverty" : psa ? "PSA population" : "Headcount",
          date: poverty ? "2023-12-31" : psa ? "2024-07-01" : "2025-01-01",
          license: null,
          total: null,
          geometryUrl: "/bhw-connect/geo/citymun/01028.json",
          navigation: { parents: [], complete: true },
          rows: [
            {
              code: "0102801",
              name: "First",
              value: poverty ? 1.234 : psa ? 1000 : 10,
              ciLow: -0.2,
              ciHigh: 4.7,
            },
            { code: "0102802", name: "Second", value: poverty ? 1.235 : 20 },
            { code: "0102803", name: "Third", value: poverty ? null : 30 },
            { code: "0102804", name: "Fourth", value: poverty ? 50 : 40 },
          ],
        },
      });
    });
    await page.goto("bhw/context?level=province&code=01028&context=poverty");
    const table = page.getByRole("region", { name: "Context exact values", exact: true });
    await expect(table).toContainText("1.23%");
    await expect(
      page.getByText(
        "Synthetic province · BHW 2025 · context 2023 · 3 paired geographic observations",
        { exact: true },
      ),
    ).toBeVisible();
    await expect(table).toContainText("90% CI: -0.2% to 4.7%");
    await expect(table.getByRole("row").filter({ hasText: "Third" })).toContainText("Unavailable");
    await expect(
      page.getByRole("heading", { name: "Synthetic province · BHW 2025 · context 2023" }),
    ).toBeVisible();
    await expect(page.getByRole("link", { name: "Two-variable map", exact: true })).toHaveCount(0);
    await table.getByRole("link", { name: "First (0102801)", exact: true }).click();
    await expect(
      page.getByRole("region", { name: "Selected locality", exact: true }),
    ).toContainText("1.23%");
    await page.getByRole("link", { name: "PSA population 2024", exact: true }).click();
    await expect(page).toHaveURL(/context=psa-population.*selected=0102801/);
    await expect(table).toContainText("1,000");
    await expect(
      page.getByRole("heading", { name: "Synthetic province · BHW 2025 · context 2024" }),
    ).toBeVisible();
    await page.reload();
    await expect(table).toContainText("1,000");
    await page.goBack();
    await expect(table).toContainText("1.23%");
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    await page.goto("bhw/context?level=province&code=13806&context=poverty");
    await expect(page.getByRole("main").getByRole("alert")).toContainText("No national fallback");
  });
  test(`joint map shares thresholds, selection and exact values at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    await missingCebuShapes(page);
    await page.route("**/api/dataset-map?**", (route) => {
      const head = new URL(route.request().url()).searchParams.get("indicator") === "bhw.total";
      return route.fulfill({
        json: {
          state: "available",
          area: "Synthetic VII",
          childLevel: "province",
          source: "Headcount",
          date: "2025-01-01",
          license: null,
          total: null,
          geometryUrl: "/bhw-connect/geo/provinces/07.json",
          navigation: { parents: [], complete: true },
          rows: [
            { code: "07012", name: "Bohol", value: 0 },
            { code: "07022", name: "Cebu", value: head ? 0 : 100 },
            { code: "07306", name: "Cebu City", value: head ? 100 : 0 },
            { code: "07311", name: "Lapu-Lapu", value: 100 },
            { code: "07313", name: "Mandaue", value: null },
          ],
        },
      });
    });
    await page.goto("bhw/context?level=region&code=07&context=households&view=bivariate");
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    const legend = page.getByRole("region", { name: "Joint map legend", exact: true });
    await expect(legend).toContainText("50 households");
    await expect(
      page.getByRole("region", { name: "Joint workforce and context map", exact: true }),
    ).toContainText("2 eligible areas lack matching shapes");
    await expect(legend).toContainText("A · 1 area");
    const groups = page.getByRole("region", { name: "Joint map groups by area", exact: true });
    await expect(groups).toContainText("No usable pair");
    await groups.getByRole("button", { name: "Cebu City (07306)", exact: true }).click();
    await expect(page).toHaveURL(/selected=07306.*view=bivariate/);
    await expect(page.getByRole("status", { name: "Selected map value" })).toContainText(
      "C: headcount > 50 people",
    );
    await expect(
      page.getByRole("region", { name: "Selected locality", exact: true }),
    ).toContainText("Headcount: 100 people");
    await page.reload();
    await expect(groups.getByRole("button", { name: "Cebu City (07306)" })).toHaveAttribute(
      "aria-pressed",
      "true",
    );
    await page.getByRole("link", { name: "Scatter and exact values", exact: true }).click();
    await expect(page).not.toHaveURL(/view=bivariate/);
    await page.goBack();
    await expect(legend).toBeVisible();
    await legend.focus();
    await expect(legend).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
