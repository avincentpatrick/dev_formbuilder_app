import { expect, test } from "@playwright/test";

for (const width of [390, 1440]) {
  test(`overview details remain accessible without crowding the story at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 1000 });
    await page.goto("bhw#training-topics");
    await expect(page.getByTestId("bhw-overview")).toBeVisible();
    for (const section of ["training-topics", "honorarium-amounts", "combined-honorarium"]) {
      const group = page.locator(`#${section} > details`);
      const summary = group.locator(":scope > summary");
      await expect(group).not.toHaveAttribute("open", "");
      await summary.focus();
      await page.keyboard.press("Enter");
      await expect(group).toHaveAttribute("open", "");
      const measure = group.locator("details").first();
      await measure.locator(":scope > summary").focus();
      await page.keyboard.press("Enter");
      const exact = measure.getByRole("link", { name: "Full data and definition" });
      await expect(exact).toBeVisible();
      await expect(exact).toHaveAttribute("href", /indicator=bhw\./);
      await expect(measure.getByRole("link", { name: "Compare places" })).toBeVisible();
      await summary.focus();
      await page.keyboard.press("Space");
      await expect(group).not.toHaveAttribute("open", "");
      await expect(exact).not.toBeVisible();
    }
    await expect(
      page.locator('#demographics a[href*="indicator=bhw.demographics.education"]'),
    ).toHaveCount(0);
    await expect(
      page.locator('#qualifications a[href*="indicator=bhw.demographics.education"]'),
    ).toHaveCount(1);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
