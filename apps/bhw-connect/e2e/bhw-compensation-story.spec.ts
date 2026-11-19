import { expect, test } from "@playwright/test";

for (const width of [390, 1440]) {
  test(`local compensation story retains scope and exact-table access at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.goto("bhw?location=region:01#support");
    const story = page.getByRole("navigation", { name: "Compensation story" });
    await expect(story).toBeVisible();
    await story.getByRole("link", { name: "2. Amounts among each payer’s recipients" }).click();
    await expect(page).toHaveURL(/location=region(:|%3A)01#honorarium-amounts/);
    const chartValues = page.getByText("Values used in this chart", { exact: true });
    await chartValues.focus();
    await page.keyboard.press("Enter");
    const table = page.getByRole("region", {
      name: "Honorarium distribution values, scroll horizontally for all statistics",
    });
    await expect(table).toBeVisible();
    await expect(table.getByRole("row")).toHaveCount(5);
    await table.focus();
    await expect(table).toBeFocused();
    await page.keyboard.press("ArrowRight");
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    await page.reload();
    await expect(page).toHaveURL(/location=region(:|%3A)01#honorarium-amounts/);
    await expect(table).not.toBeVisible();
    await chartValues.focus();
    await page.keyboard.press("Enter");
    await expect(table).toBeVisible();
    await story.getByRole("link", { name: "3. Combined amounts and their population" }).click();
    await expect(page).toHaveURL(/#combined-honorarium/);
    await page.goBack();
    await expect(page).toHaveURL(/#honorarium-amounts/);
    await page
      .getByRole("navigation", { name: "BHW analysis", exact: true })
      .getByRole("link", { name: "Data and methodology", exact: true })
      .click();
    await expect(page).toHaveURL(/location=region(:|%3A)01#methodology/);
    expect(new URL(page.url()).hash).toBe("#methodology");
  });
}
