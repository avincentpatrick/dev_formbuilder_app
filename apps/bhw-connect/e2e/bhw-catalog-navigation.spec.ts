import { expect, test } from "@playwright/test";
for (const width of [390, 1440]) {
  test(`catalog browsing retains BHW and map context at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.goto(
      `catalog?${new URLSearchParams({ mode: "all", dataset: "bhw", mapState: "dataset=bhw-2025&indicator=bhw.active-years&level=region&code=07&selected=07306&valueMin=12.34" })}`,
    );
    const back = page.getByRole("link", { name: "Return to previous BHW map", exact: true });
    const original = await back.getAttribute("href");
    await page.getByRole("link", { name: "National figures", exact: true }).click();
    await expect(page).toHaveURL(/location=national%3APH/);
    await expect(page.getByTestId("catalog-scope")).toHaveText("Philippines");
    await expect(page).toHaveURL((url) => url.searchParams.get("dataset") === "bhw");
    await page.locator('select[name="location"]').selectOption("region:01");
    await page.getByRole("button", { name: "Show datasets", exact: true }).click();
    await expect(page).toHaveURL(/location=region%3A01/);
    await expect(page).toHaveURL((url) => url.searchParams.get("dataset") === "bhw");
    await page.reload();
    await expect(back).toHaveAttribute("href", original!);
    await page
      .getByRole("navigation", { name: "Location ancestors" })
      .getByRole("link", { name: "Philippines", exact: true })
      .click();
    await expect(page).toHaveURL((url) => url.searchParams.get("dataset") === "bhw");
    await page
      .getByRole("link", { name: "← All datasets for this selection", exact: true })
      .click();
    await expect(page).toHaveURL((url) => !url.searchParams.has("dataset"));
    await expect(back).toHaveAttribute("href", original!);
    await page.getByRole("link", { name: "View BHW indicators →", exact: true }).click();
    await expect(page).toHaveURL((url) => url.searchParams.get("dataset") === "bhw");
    await expect(back).toHaveAttribute("href", original!);
    await page.getByRole("link", { name: "All locations", exact: true }).click();
    await expect(page).toHaveURL(/mode=all/);
    await expect(page).toHaveURL((url) => url.searchParams.get("dataset") === "bhw");
    await expect(back).toHaveAttribute("href", original!);
    await page.goBack();
    await expect(back).toHaveAttribute("href", original!);
  });
}
