import { expect, test } from "@playwright/test";

for (const width of [390, 1440]) {
  test(`facilities entry opens national scope with map return at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    const mapState =
      "dataset=nhfr-2026-09&indicator=nhfr.facilities&level=region&code=01&selected=01028";
    await page.goto(`catalog?${new URLSearchParams({ mode: "all", dataset: "nhfr", mapState })}`);
    await expect(page).toHaveURL(/location=national%3APH/);
    const back = page.getByRole("link", {
      name: "Return to previous Health facilities map",
      exact: true,
    });
    await expect(back).toHaveAttribute("href", /selected=01028/);
    await page.reload();
    await expect(back).toHaveAttribute("href", /selected=01028/);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}

test("facilities card title and action both open the national card", async ({ page }) => {
  await page.goto("./");
  const action = page.getByRole("link", { name: "Explore health facilities", exact: false });
  const title = page.getByRole("link", { name: "Health facilities", exact: true });
  await expect(action).toHaveAttribute("href", /mode=psgc&location=national%3APH&dataset=nhfr/);
  await expect(title).toHaveAttribute("href", (await action.getAttribute("href")) as string);
  await action.click();
  await expect(page.getByTestId("nhfr-overview")).toBeVisible();
  await expect(page).toHaveURL(/location=national%3APH/);
});
