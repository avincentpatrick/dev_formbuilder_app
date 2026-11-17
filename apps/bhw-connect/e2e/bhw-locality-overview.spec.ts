import { expect, test } from "@playwright/test";

for (const width of [390, 1440]) {
  test(`place selection retains the visual dashboard at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.goto("bhw");
    const input = page.getByRole("combobox", { name: "Search BHW places and indicators" });
    await input.fill("Ilocos");
    const region = page
      .getByRole("option")
      .and(page.locator('[href*="location=region%3A01"]'))
      .first();
    await expect(region).toHaveAttribute("href", /\/bhw\?location=region%3A01/);
    await region.click();
    await expect(page).toHaveURL(/\/bhw\?location=region%3A01/);
    await expect(page.getByTestId("bhw-first-visual")).toBeVisible();
    await expect(page.locator("#workforce-heading")).toContainText("Workforce in");
    for (const id of ["qualifications", "support", "methodology"])
      await expect(page.locator(`#${id}`)).toHaveCount(1);
    await expect(
      page.getByRole("heading", { name: "National workforce", exact: true }),
    ).toHaveCount(0);
    await expect(
      page
        .getByRole("navigation", { name: "BHW analysis" })
        .getByRole("link", { name: "Compare places", exact: true }),
    ).toHaveAttribute("href", /geos=01/);
    await page.reload();
    await expect(page.locator("#workforce-heading")).toContainText("Workforce in");
    await page.getByRole("link", { name: "View Philippines dashboard", exact: true }).click();
    await expect(page.locator("#workforce-heading")).toHaveText("National workforce");
    await page.goBack();
    await expect(page.locator("#workforce-heading")).toContainText("Workforce in");
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}

test("province, municipality and barangay retain all dashboard sections on direct links", async ({
  page,
}) => {
  for (const location of ["province:01028", "citymun:0102801", "barangay:0102801001"]) {
    await page.goto(`bhw?${new URLSearchParams({ location })}`);
    await expect(page.getByTestId("bhw-first-visual")).toBeVisible();
    for (const id of ["workforce", "qualifications", "support", "methodology"])
      await expect(page.locator(`#${id}`)).toHaveCount(1);
    await expect(page.locator("#workforce-heading")).toContainText("Workforce in");
  }
});

test("invalid locality does not fall back to national figures", async ({ page }) => {
  await page.goto("bhw?location=province%3A01");
  await expect(page.getByRole("heading", { name: "Could not verify this locality" })).toBeVisible();
  await expect(page.getByTestId("bhw-overview")).toHaveCount(0);
});
