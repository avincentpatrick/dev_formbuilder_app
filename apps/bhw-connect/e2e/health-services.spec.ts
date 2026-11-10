import { test, expect } from "@playwright/test";

// The refreshed development copy contains synthetic total-breakdown reports.
// Set to 0 only when running against an explicitly empty/unavailable fixture.
const hasReports = process.env.BHW_E2E_FHSIS_HAS_DATA !== "0";

test("health services is discoverable and drills down without borrowing census chrome", async ({
  page,
}) => {
  test.skip(!hasReports, "This path requires the populated synthetic fixture.");
  await page.goto("./");
  await page.getByRole("link", { name: "Health services dashboard", exact: true }).click();
  await expect(page).toHaveURL(/\/health-services$/);
  await expect(page.getByRole("heading", { name: "All service indicators" })).toBeVisible();
  await expect(
    page.getByRole("heading", { name: "Age and sex breakdowns", exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole("navigation", { name: "Health services", exact: true }),
  ).toBeVisible();
  await page.getByRole("link", { name: "REGION I (ILOCOS REGION)", exact: true }).click();
  await expect(page).toHaveURL(/\/health-services\/region\/01$/);
  await expect(page.getByRole("heading", { level: 1 })).toContainText("REGION I");
  await page.locator("table").getByRole("link", { name: "ILOCOS NORTE", exact: true }).click();
  await expect(page).toHaveURL(/\/health-services\/province\/01028$/);
  const firstTown = page.locator('table a[href*="/health-services/citymun/"]').first();
  await firstTown.click();
  await expect(page).toHaveURL(/\/health-services\/citymun\//);
  await expect(page.getByRole("heading", { name: "All service indicators" })).toBeVisible();
  await page
    .getByRole("navigation", { name: "Health services", exact: true })
    .getByRole("link", { name: "Methodology" })
    .click();
  await expect(page.getByRole("heading", { level: 1 })).toHaveText("Health services: methodology");
});

test("health services rejects unsupported and unknown geographies", async ({ page }) => {
  for (const path of [
    "health-services/unsupported/0102801001",
    "health-services/region/unknown",
    "health-services/barangay/unknown",
  ]) {
    await page.goto(path);
    await expect(page.getByRole("heading", { name: "Page not found", exact: true })).toBeVisible();
  }
});

test("an unavailable FHSIS release does not claim nationwide non-reporting", async ({ page }) => {
  test.skip(hasReports, "This case uses an explicitly empty-report fixture.");
  await page.goto("health-services");
  await expect(
    page.getByRole("status").filter({ hasText: "Health services data is not available" }),
  ).toBeVisible();
  await expect(page.getByText("Not reported in 2025", { exact: true })).toHaveCount(0);
  await page
    .getByRole("navigation", { name: "Health services", exact: true })
    .getByRole("link", { name: "Methodology" })
    .click();
  await expect(page.getByRole("heading", { level: 1 })).toHaveText("Health services: methodology");
});

test("barangay search destinations open their municipality overview", async ({ page }) => {
  for (const section of ["uuc-phc", "facilities", "health-services"]) {
    await page.goto(`${section}/barangay/0102801001`);
    await expect(page).toHaveURL(new RegExp(`/${section}/citymun/0102801$`));
  }
});
