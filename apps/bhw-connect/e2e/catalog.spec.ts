import { expect, test } from "@playwright/test";

test("catalog preserves PSGC scope through navigation, refresh and history", async ({ page }) => {
  await page.goto("catalog");
  await expect(page.getByTestId("catalog-scope")).toHaveText("All locations");
  await expect(page.getByRole("region", { name: "Catalog overview" })).toBeVisible();
  await page.getByRole("link", { name: "National figures", exact: true }).click();
  await expect(page.getByTestId("catalog-scope")).toContainText("Philippines");
  await expect(page).toHaveURL(/location=national%3APH/);
  const select = page.locator('select[name="location"]');
  await select.selectOption("region:01");
  await page.getByRole("button", { name: "Show datasets" }).click();
  await expect(page).toHaveURL(/location=region%3A01/);
  await expect(page.getByTestId("catalog-scope")).toContainText("REGION I");
  await select.selectOption("province:01028");
  await page.getByRole("button", { name: "Show datasets" }).click();
  await expect(page).toHaveURL(/location=province%3A01028/);
  await page.locator('select[name="location"]').selectOption("citymun:0102801");
  await page.getByRole("button", { name: "Show datasets" }).click();
  await expect(page).toHaveURL(/location=citymun%3A0102801/);
  await expect(
    page.getByText("Barangay figures are not yet available in this catalog."),
  ).toBeVisible();
  await page
    .getByRole("navigation", { name: "Location ancestors" })
    .getByRole("link", { name: /REGION I/ })
    .click();
  await expect(page).toHaveURL(/location=region%3A01/);
  await page.reload();
  await expect(page.getByTestId("catalog-scope")).toContainText("REGION I");
  await page.getByRole("link", { name: "All locations", exact: true }).click();
  await expect(page.getByTestId("catalog-scope")).toHaveText("All locations");
  await page.goBack();
  await expect(page.getByTestId("catalog-scope")).toContainText("REGION I");
});

test("catalog rejects ambiguous links and fits a mobile keyboard flow", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.goto("catalog?mode=psgc&location=region:01&location=region:02");
  await expect(
    page.getByRole("alert").filter({ hasText: "This catalog selection is not supported" }),
  ).toBeVisible();
  await page.goto("catalog");
  await expect(page.getByTestId("catalog-scope")).toHaveText("All locations");
  const select = page.locator('select[name="location"]');
  await select.focus();
  await expect(select).toBeFocused();
  await select.selectOption("region:01");
  await page.keyboard.press("Tab");
  await expect(page.getByRole("button", { name: "Show datasets" })).toBeFocused();
  await page.keyboard.press("Enter");
  await expect(page.getByTestId("catalog-scope")).toContainText("REGION I");
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});

test("catalog opens available-source details without claiming national values", async ({
  page,
}) => {
  await page.goto("catalog");
  const firstCard = page.locator("article").first();
  await expect(firstCard).toBeVisible();
  await firstCard.getByRole("link").first().click();
  await expect(page).toHaveURL(/mode=all.*dataset=/);
  await expect(
    page.getByText("This is an availability summary, not a national data table.", { exact: false }),
  ).toBeVisible();
  await page.reload();
  await expect(page.getByRole("table")).toBeVisible();
  await page.getByRole("link", { name: "All datasets for this selection", exact: false }).click();
  await expect(page.getByTestId("catalog-scope")).toHaveText("All locations");
});
