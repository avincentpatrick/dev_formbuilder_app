import { expect, test } from "@playwright/test";
for (const width of [390, 1440]) {
  test(`facility search retains scope, full names and totals at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.goto("facilities/citymun/0102801");
    const list = page.getByRole("region", { name: "Facilities", exact: true });
    await expect(list).toContainText("Synthetic Station 2 with a long complete facility name");
    await expect(list).toContainText("Synthetic Station 10");
    const totals = page.getByRole("group", { name: "Facility totals" });
    const originalTotals = await totals.innerText();
    const download = page.getByRole("link", { name: "Summary (PNG)", exact: true });
    const originalDownload = await download.getAttribute("href");
    const form = page.getByRole("search", { name: "Facility list" });
    const input = form.getByRole("searchbox");
    await input.fill("clinic north");
    await form.getByRole("button", { name: "Search list" }).click();
    await expect(page).toHaveURL(/facilities\/citymun\/0102801\?facilitySearch=clinic\+north/);
    await expect(input).toHaveValue("clinic north");
    await expect(list).toContainText("1 matching facility of 3 loaded");
    await expect(list).toContainText("Synthetic Station 2 with a long complete facility name");
    await expect(list).not.toContainText("Synthetic Station 10");
    await expect(totals).toHaveText(originalTotals, { useInnerText: true });
    await expect(download).toHaveAttribute("href", originalDownload!);
    await expect(list).toContainText("Area totals, map and downloads stay unchanged");
    await page.reload();
    await expect(input).toHaveValue("clinic north");
    await input.fill("no-synthetic-match");
    await input.press("Enter");
    await expect(list).toContainText("No facilities match this search");
    await expect(list).not.toContainText("No health facilities are on the DOH registry");
    await page.goBack();
    await expect(input).toHaveValue("clinic north");
    await page.goForward();
    await expect(input).toHaveValue("no-synthetic-match");
    await form.getByRole("link", { name: "Clear search" }).click();
    await expect(input).toHaveValue("");
    await expect(list).toContainText("3 matching facilities of 3 loaded");
    await expect(list).toContainText("Licence status not stated");
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
