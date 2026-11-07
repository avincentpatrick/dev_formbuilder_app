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
  await page.locator('select[name="location"]').selectOption("barangay:0102801001");
  await page.getByRole("button", { name: "Show datasets" }).click();
  await expect(page).toHaveURL(/location=barangay%3A0102801001/);
  await expect(
    page.getByText("This is the barangay level. Only supported source figures are shown."),
  ).toBeVisible();
  await expect(page.getByRole("region", { name: "Datasets not shown" })).toContainText(
    "FHSIS is not available at barangay level",
  );
  await page.reload();
  await expect(page).toHaveURL(/location=barangay%3A0102801001/);
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
  // The streamed server content may be replaced while Next finishes hydration.
  // Reacquire the live control before testing native keyboard traversal.
  await expect(async () => {
    await select.focus();
    await expect(select).toBeFocused({ timeout: 500 });
  }).toPass({ timeout: 10_000 });
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
  // All-locations probes stream behind a loading state; allow shared CI database contention.
  await expect(page.getByTestId("catalog-scope")).toHaveText("All locations", { timeout: 10_000 });
});

test("district mode clears PSGC selection and retains an empty or selected district safely", async ({
  page,
}) => {
  await page.goto("catalog?mode=psgc&location=region%3A01");
  await page.getByRole("link", { name: "Congressional districts", exact: true }).click();
  await expect(page).toHaveURL(/catalog\?mode=district$/);
  const district = page.getByLabel("Congressional district", { exact: true });
  await expect(district).toBeVisible();
  const options = await district
    .locator("option")
    .evaluateAll((nodes) => nodes.slice(1).map((n) => (n as HTMLOptionElement).value));
  if (options.length) {
    await district.selectOption(options[0]);
    await page.getByRole("button", { name: "Show district" }).click();
    await expect(page.getByTestId("catalog-scope")).toBeVisible();
    const url = page.url();
    await page.reload();
    await expect(page).toHaveURL(url);
    await page.getByText("Mapping provenance and gaps", { exact: true }).click();
    await expect(
      page.getByRole("link", { name: "View source receipts, mapping gaps and correction history" }),
    ).toBeVisible();
  }
  await page.getByRole("link", { name: "PSGC / All locations", exact: true }).click();
  await expect(page).toHaveURL(/mode=all$/);
  await expect(page.getByTestId("catalog-scope")).toHaveText("All locations", { timeout: 10_000 });
});

test("home starts with the catalog and every available card has consistent details", async ({
  page,
}) => {
  await page.goto("./");
  await expect(page.getByRole("region", { name: "Catalog overview" })).toBeVisible();
  const cards = page.locator("article");
  await expect(cards.first()).toBeVisible();
  const links = await cards
    .locator("h2 a")
    .evaluateAll((nodes) => nodes.map((n) => n.getAttribute("href")!));
  expect(links.length).toBeGreaterThan(0);
  expect(links.length).toBeLessThanOrEqual(6);
  for (const href of links) {
    await page.goto(href);
    await expect(page.getByRole("table")).toBeVisible();
    await expect(page.getByText("Accepted release:", { exact: false }).first()).toBeVisible();
    await expect(page.getByRole("table")).not.toContainText("Definition unavailable.");
    await page.getByRole("link", { name: "All datasets for this selection", exact: false }).click();
    await expect(page.getByTestId("catalog-scope")).toHaveText("All locations", {
      timeout: 10_000,
    });
  }
  await page.getByRole("link", { name: "Congressional districts", exact: true }).click();
  await expect(page.getByLabel("Congressional district", { exact: true })).toBeVisible();
});
