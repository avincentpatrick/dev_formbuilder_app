import { expect, test } from "@playwright/test";
for (const width of [390, 1440]) {
  test(`legacy BHW card URL opens figures directly at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    const mapState = "dataset=bhw-stepzero-2026&level=region&code=07&selected=07306";
    await page.goto(`catalog?${new URLSearchParams({ mode: "all", dataset: "bhw", mapState })}`);
    await expect(page).toHaveURL((url) => url.pathname === "/bhw-connect/bhw");
    await expect(page.getByTestId("bhw-first-visual")).toBeVisible();
    await expect(
      page.getByText("This is an availability summary, not a national data table.", {
        exact: false,
      }),
    ).toHaveCount(0);
    await expect(
      page.getByRole("link", { name: "Return to previous BHW map", exact: true }),
    ).toHaveAttribute("href", /selected=07306/);
    await page.reload();
    await expect(page.getByTestId("bhw-first-visual")).toBeVisible();
  });
}

test("portal BHW card action and bottom link share the overview destination", async ({ page }) => {
  await page.goto("./");
  const action = page.getByRole("link", { name: "Open BHW overview", exact: false });
  const footer = page.getByRole("link", { name: "BHW dashboard", exact: true });
  await expect(action).toHaveAttribute("href", "/bhw-connect/bhw");
  await expect(footer).toHaveAttribute("href", "/bhw-connect/bhw");
  await action.click();
  await expect(page.getByTestId("bhw-first-visual")).toBeVisible();
});
