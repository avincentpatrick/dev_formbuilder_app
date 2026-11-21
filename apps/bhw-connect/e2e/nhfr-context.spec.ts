import { expect, test } from "@playwright/test";
for (const width of [390, 1440]) {
  test(`linked facility evidence preserves source years and scope at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.goto("catalog?mode=psgc&location=citymun%3A0102801&dataset=nhfr");
    const context = page.getByTestId("facility-context");
    await expect(context).toContainText("different years");
    await expect(context).toContainText("PSA census, July 2024");
    await expect(context).toContainText("BHW Headcount 2025");
    await expect(context).toContainText("DOH UUC-PHC list, 2025");
    await expect(
      context.getByRole("link", { name: "Explore population for this place", exact: false }),
    ).toHaveAttribute("href", /location=citymun%3A0102801&dataset=population/);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    await context
      .getByRole("link", { name: "Explore population for this place", exact: false })
      .click();
    await expect(page).toHaveURL(/location=citymun%3A0102801&dataset=population/);
    await page.goBack();
    await expect(context).toBeVisible();
  });
}
