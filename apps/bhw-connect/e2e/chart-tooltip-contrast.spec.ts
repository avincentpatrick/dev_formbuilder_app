import { expect, test } from "@playwright/test";

for (const theme of ["dark", "light"]) {
  test(`tapped chart labels remain readable on a phone in ${theme} mode`, async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.addInitScript((theme) => {
      localStorage.setItem(
        "bhw-settings",
        JSON.stringify({ theme, palette: "teal", fontSize: "base" }),
      );
    }, theme);
    await page.goto("bhw?location=region:01");
    const chart = page.locator("#education svg");
    await expect(chart).toBeVisible();
    const bar = chart.locator('g[aria-label="bar"] rect').first();
    await bar.click();
    const tip = chart.locator('g[aria-label="tip"]');
    await expect(tip).toBeVisible();
    const colors = await tip.evaluate((tip) => {
      const path = tip.querySelector("path")!;
      const text = tip.querySelector("text")!;
      return { background: getComputedStyle(path).fill, text: getComputedStyle(text).fill };
    });
    expect(colors.background).toBe(theme === "dark" ? "rgb(24, 29, 30)" : "rgb(246, 247, 248)");
    const luminance = (rgb: string) => {
      const channels = rgb
        .match(/\d+/g)!
        .slice(0, 3)
        .map(Number)
        .map((v) => {
          const c = v / 255;
          return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
        });
      return channels[0] * 0.2126 + channels[1] * 0.7152 + channels[2] * 0.0722;
    };
    const a = luminance(colors.text),
      b = luminance(colors.background);
    expect((Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)).toBeGreaterThanOrEqual(4.5);
  });
}
