import { expect, test } from "@playwright/test";

for (const width of [390, 1440]) {
  test(`BHW overview has usable figures, purpose links and supporting methodology at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: width === 390 ? 844 : 1000 });
    await page.goto("bhw");
    await expect(page.getByTestId("bhw-first-visual")).toBeVisible();
    const firstVisual = await page.getByTestId("bhw-first-visual").boundingBox();
    expect(firstVisual!.y).toBeLessThan(page.viewportSize()!.height - 120);
    const nav = page.getByRole("navigation", { name: "BHW analysis" });
    await nav.getByRole("link", { name: "Qualifications and training" }).click();
    await expect(page).toHaveURL(/#qualifications$/);
    await expect(
      page.getByRole("heading", { name: "Qualifications and training", exact: true }),
    ).toBeInViewport();
    const training = page
      .getByRole("heading", { name: "Training and certification", exact: true })
      .locator("..")
      .locator("..")
      .locator("..");
    // The committed seed need not contain a national certification observation.
    // Verify its honest unavailable state, or exercise the actual populated chart.
    const tableTab = training.getByRole("tab", { name: "Table", exact: true });
    if (await tableTab.count()) {
      await tableTab.click();
      await expect(training.getByRole("table")).toBeVisible();
    } else {
      await expect(
        training.getByText("No accepted training or certification observations are available."),
      ).toBeVisible();
    }
    await nav.getByRole("link", { name: "Data and methodology" }).click();
    await page.locator("#methodology summary").focus();
    await page.keyboard.press("Enter");
    await expect(
      page.getByRole("link", { name: "View national indicators and availability", exact: false }),
    ).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
