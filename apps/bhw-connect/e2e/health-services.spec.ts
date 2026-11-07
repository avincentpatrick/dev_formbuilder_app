import { test, expect } from "@playwright/test";

test("health services is discoverable and drills down without borrowing census chrome", async ({
  page,
}) => {
  test.skip(
    process.env.BHW_E2E_FHSIS_HAS_DATA !== "1",
    "The committed synthetic seed has no FHSIS total-breakdown reports; use the populated fixture for this path.",
  );
  await page.goto("./");
  await page
    .getByRole("link")
    .filter({ has: page.getByRole("heading", { name: "Health services", exact: true }) })
    .click();
  await expect(page).toHaveURL(/\/health-services$/);
  await expect(page.getByRole("heading", { name: "All service indicators" })).toBeVisible();
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
  for (const path of ["health-services/barangay/0102801001", "health-services/region/unknown"]) {
    await page.goto(path);
    await expect(page.getByRole("heading", { name: "Page not found", exact: true })).toBeVisible();
  }
});

test("an unavailable FHSIS release does not claim nationwide non-reporting", async ({ page }) => {
  test.skip(process.env.BHW_E2E_FHSIS_HAS_DATA === "1", "This case uses the empty-report seed.");
  await page.goto("health-services");
  await expect(page.getByRole("status")).toContainText("Health services data is not available");
  await expect(page.getByText("Not reported in 2025", { exact: true })).toHaveCount(0);
  await page
    .getByRole("navigation", { name: "Health services", exact: true })
    .getByRole("link", { name: "Methodology" })
    .click();
  await expect(page.getByRole("heading", { level: 1 })).toHaveText("Health services: methodology");
});
