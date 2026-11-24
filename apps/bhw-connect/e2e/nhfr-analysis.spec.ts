import { expect, test } from "@playwright/test";
for (const width of [390, 1440]) {
  test(`facility mix explains denominators and retains exact detail at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.goto("catalog?mode=psgc&location=citymun%3A0102801&dataset=nhfr");
    const chart = page.getByRole("region", { name: "Facility mix chart", exact: true });
    await expect(chart).toContainText("What kinds of facilities are recorded?");
    await expect(chart).toContainText("Government:");
    await expect(chart).toContainText("Private:");
    await expect(chart).toContainText("own total");
    await chart.getByText("View exact figures", { exact: true }).click();
    await expect(
      page.getByRole("region", { name: "Facilities by type", exact: true }),
    ).toBeVisible();
    await expect(page.getByRole("search", { name: "Facility list" })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
