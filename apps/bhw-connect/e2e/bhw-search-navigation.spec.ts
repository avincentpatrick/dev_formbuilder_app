import { expect, test } from "@playwright/test";

test("search updates the URL without replacing the hydrated document", async ({ page }) => {
  await page.goto("bhw/search", { waitUntil: "networkidle" });
  await page.evaluate(() => document.documentElement.setAttribute("data-search-document", "original"));
  await page.getByRole("searchbox").fill("01 headcount");
  await page.getByRole("button", { name: "Search", exact: true }).click();
  await expect(page.getByRole("region", { name: "Search results" })).toBeVisible();
  await expect(page.locator("html")).toHaveAttribute("data-search-document", "original");
  await expect(page).toHaveURL(/\/bhw-connect\/bhw\/search\?q=01/);
  await page.goBack();
  await expect(page).toHaveURL(/\/bhw-connect\/bhw\/search$/);
});

test("search retains GET navigation and map context without JavaScript", async ({ browser, baseURL }) => {
  const context = await browser.newContext({ javaScriptEnabled: false, baseURL });
  try {
    const page = await context.newPage();
    await page.goto(`bhw/search?${new URLSearchParams({ mapState: "dataset=bhw-stepzero-2026&level=region&code=01" })}`);
    const back = page.getByRole("link", { name: "Return to previous BHW map", exact: true });
    const original = await back.getAttribute("href");
    await page.getByRole("searchbox").fill("01 headcount");
    await page.getByRole("button", { name: "Search", exact: true }).click();
    // The existing loading.tsx streams results and needs JavaScript to reveal them.
    // This checks the native GET destination, not full no-JavaScript rendering.
    await expect(page).toHaveURL((url) => url.pathname === "/bhw-connect/bhw/search" && url.searchParams.get("q") === "01 headcount");
    expect(new URL(page.url()).searchParams.get("mapState")).toBe(original!.split("?")[1]);
  } finally {
    await context.close();
  }
});
