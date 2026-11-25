import { expect, test } from "@playwright/test";
for (const width of [320, 390, 768, 1440])
  test(`barangay filters, selection, dialogs and exports at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto("catalog?mode=psgc&location=citymun%3A0102801&dataset=nhfr");
    const card = page.getByTestId("barangay-explorer");
    await expect(card).toContainText("3 selected facilities");
    const table = card.getByRole("region", { name: "Exact barangay figures", exact: true });
    await expect(table.getByRole("table")).toBeVisible();
    const first = table.locator("tbody tr").first().getByRole("button");
    await first.click();
    await expect(card.getByRole("button", { name: "Clear barangay", exact: true })).toBeVisible();
    await card.getByRole("button", { name: "Clear barangay", exact: true }).click();
    await card
      .locator("summary")
      .filter({ hasText: /^Facility types:/ })
      .click();
    await card.getByRole("checkbox", { name: "Clinic", exact: true }).check();
    await expect(card).toContainText("1 selected facility");
    await card.getByRole("checkbox", { name: "Hospital", exact: true }).check();
    await expect(card).toContainText("2 selected facilities");
    await card.getByLabel("Ownership", { exact: true }).selectOption("Private");
    await expect(card).toContainText("1 selected facility");
    await card.getByRole("button", { name: "Maximize map", exact: true }).click();
    const dialog = page.getByRole("dialog", {
      name: "Facilities recorded by barangay",
      exact: true,
    });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel("Ownership", { exact: true })).toHaveValue("Private");
    expect(await dialog.evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true);
    await page.keyboard.press("Escape");
    await card.getByRole("button", { name: "Present map", exact: true }).click();
    await expect(dialog).toContainText("Private");
    await page.keyboard.press("Escape");
    await card.getByRole("button", { name: "Reset map and list filters", exact: true }).click();
    await expect(card).toContainText("3 selected facilities");
    await card.getByText("Download selected map and tables", { exact: true }).click();
    const link = card.getByRole("link", { name: "CSV · all filtered barangays", exact: true });
    const response = await page.request.get((await link.getAttribute("href"))!);
    expect(response.status()).toBe(200);
    expect(await response.text()).toContain("Mapping status");
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
test("map-load failure retains barangay and facility tables", async ({ page }) => {
  await page.route("**/maplibre-gl-csp-worker.js", (r) => r.abort());
  await page.goto("facilities/citymun/0102801");
  const card = page.getByTestId("barangay-explorer");
  await expect(
    card.getByRole("region", { name: "Exact barangay figures", exact: true }),
  ).toBeVisible();
  await expect(card.getByRole("region", { name: "Facilities", exact: true })).toContainText(
    "Synthetic Station 10",
  );
});
