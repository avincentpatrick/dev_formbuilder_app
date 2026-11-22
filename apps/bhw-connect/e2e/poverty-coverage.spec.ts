import { expect, test } from "@playwright/test";
for (const width of [390, 1440]) {
  test(`regional poverty coverage exposes available, zero and missing municipal records at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.route("**/api/dataset-map?**", async (route) => {
      await route.fulfill({
        json: {
          state: "available",
          childLevel: "citymun",
          valueSupport: "supported",
          rows: [
            { code: "synthetic-a", name: "Synthetic zero", value: 0 },
            { code: "synthetic-b", name: "Synthetic missing", value: null },
          ],
        },
      });
    });
    await page.goto("catalog?mode=psgc&location=region%3A01&dataset=poverty");
    const coverage = page.getByTestId("poverty-coverage");
    await expect(
      coverage.getByText("1 of 2 cities/municipalities have usable SAE estimates").first(),
    ).toBeVisible();
    await expect(page.getByTestId("poverty-official-overview")).toContainText(
      "does not mean PSA has no published estimate",
    );
    const province = coverage.getByRole("link", { name: /ILOCOS NORTE/i });
    await province.focus();
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/location=province%3A01028/);
    await expect(page.getByRole("link", { name: /See cities and municipalities/ })).toHaveAttribute(
      "href",
      "#poverty-municipal-list",
    );
    await page.goBack();
    await expect(coverage).toBeVisible();
    await page.reload();
    await expect(
      coverage.getByText("1 of 2 cities/municipalities have usable SAE estimates").first(),
    ).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
test("coverage failure remains a retryable failure, not zero coverage", async ({ page }) => {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({ status: 500, json: { state: "failed" } }),
  );
  await page.goto("catalog?mode=psgc&location=region%3A01&dataset=poverty");
  const coverage = page.getByTestId("poverty-coverage");
  await expect(coverage.getByRole("button", { name: "Retry coverage checks" })).toBeVisible();
  await expect(coverage).not.toContainText("0 of");
  await page.unroute("**/api/dataset-map?**");
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({ json: { state: "suppressed" } }),
  );
  await coverage.getByRole("button", { name: "Retry coverage checks" }).click();
  await expect(coverage.getByText("Withheld", { exact: true }).first()).toBeVisible();
});
