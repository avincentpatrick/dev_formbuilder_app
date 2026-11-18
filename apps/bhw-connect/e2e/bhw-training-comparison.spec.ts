import { expect, test } from "@playwright/test";

for (const width of [390, 1440]) {
  test(`training matrix retains comparison scope and supports sorting at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.goto("compare?measure=bhw.training.basic_life_support&geos=01,02,03,04&focus=02");
    const matrix = page.getByRole("region", {
      name: "Training comparison table, scroll horizontally for all places",
    });
    await expect(matrix).toBeVisible();
    await expect(matrix.getByRole("row")).toHaveCount(45);
    await expect(matrix.getByRole("columnheader")).toHaveCount(5);
    const sort = page.getByRole("combobox", { name: "Sort training topics" });
    await sort.selectOption("0");
    await expect(sort).toHaveValue("0");
    await matrix.focus();
    await expect(matrix).toBeFocused();
    await page.keyboard.press("ArrowRight");
    const topic = matrix.getByRole("link", { name: "Dengue", exact: true });
    await expect(topic).toHaveAttribute("href", /geos=01%2C02%2C03%2C04&focus=02/);
    await topic.click();
    await expect(page).toHaveURL(/measure=bhw.training.dengue&geos=01%2C02%2C03%2C04&focus=02/);
    await expect(page.getByRole("heading", { name: "Dengue", exact: true })).toBeInViewport();
    await page.reload();
    await expect(matrix).toBeVisible();
    await expect(page).toHaveURL(/measure=bhw.training.dengue&geos=01%2C02%2C03%2C04&focus=02/);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    await page
      .getByRole("combobox", { name: "Comparison measure", exact: true })
      .selectOption("bhw.total");
    await expect(matrix).toHaveCount(0);
    await page.goBack();
    await expect(matrix).toBeVisible();
  });
}
