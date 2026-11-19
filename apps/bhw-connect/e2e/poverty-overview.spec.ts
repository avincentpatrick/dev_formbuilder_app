import { expect, test } from "@playwright/test";

for (const width of [390, 1440]) {
  test(`Poverty explains municipal scope and retains exact data and navigation at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.goto("catalog?mode=all&dataset=poverty");
    const overview = page.getByTestId("poverty-overview");
    await expect(overview).toContainText("No national estimate is shown");
    await expect(overview).toContainText("people, not households");
    await page.locator('select[name="location"]').selectOption("region:01");
    await page.getByRole("button", { name: "Show datasets", exact: true }).click();
    await expect(page).toHaveURL(/dataset=poverty.*location=region%3A01/);
    await expect(overview).toContainText("no local value is replaced");
    await page.locator('select[name="location"]').selectOption("province:01028");
    await page.getByRole("button", { name: "Show datasets", exact: true }).click();
    await page.locator('select[name="location"]').selectOption("citymun:0102801");
    await page.getByRole("button", { name: "Show datasets", exact: true }).click();
    await expect(page).toHaveURL(/location=citymun%3A0102801/);
    await expect(overview).toContainText("2023 · people");
    const details = page.getByText("Exact data, availability and sources", { exact: true });
    const disclosure = page.locator("details").filter({ has: details });
    // Streaming hydration can replace the focused server node. Reacquire the
    // native summary, as in the existing catalog keyboard tests.
    await expect(async () => {
      if (!(await disclosure.evaluate((node) => node.hasAttribute("open")))) {
        await details.focus();
        await expect(details).toBeFocused({ timeout: 500 });
        await page.keyboard.press("Enter");
      }
      await expect(disclosure).toHaveAttribute("open", "", { timeout: 500 });
    }).toPass();
    // Seed availability is not proof of a populated live estimate. Both exact
    // data and the explicit no-data status must remain accessible in this region.
    await expect(disclosure).toContainText(/Source|source|figures/);
    const mapLink = page.getByRole("link", { name: "Open full Poverty map", exact: true });
    await expect(mapLink).toHaveAttribute("href", /dataset=psa-sae-poverty-2023/);
    await page.reload();
    await expect(page).toHaveURL(/location=citymun%3A0102801/);
    await expect(overview).toBeVisible();
    await page
      .getByRole("navigation", { name: "Location ancestors" })
      .getByRole("link")
      .filter({ hasText: /ILOCOS NORTE/i })
      .click();
    await expect(page).toHaveURL(/location=province%3A01028.*dataset=poverty/);
    await page.goBack();
    await expect(page).toHaveURL(/location=citymun%3A0102801/);
    await page.goForward();
    await expect(page).toHaveURL(/location=province%3A01028/);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
