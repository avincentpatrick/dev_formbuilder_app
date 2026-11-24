import { expect, test } from "@playwright/test";
const scope = "mode=psgc&location=citymun%3A0102801";
for (const width of [320, 390, 768, 1440]) {
  test(`headline supports multiple types, notes and viewing modes at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(`catalog?${scope}&dataset=nhfr`);
    const overview = page.getByTestId("nhfr-overview");
    await overview.locator("summary").filter({ hasText: "Facility types:" }).click();
    const checks = overview.getByRole("checkbox");
    await expect(checks.first()).toBeVisible();
    const first = await checks.nth(0).locator("..").innerText();
    const second = await checks.nth(1).locator("..").innerText();
    await checks.nth(0).check();
    await checks.nth(1).check();
    await expect(overview).toContainText("2 selected");
    await expect(overview).not.toContainText("Updating the selected headline");
    await expect(overview).toContainText(
      `Selected: ${[first.trim(), second.trim()].sort().join("; ")}`,
    );
    const notes = overview
      .locator("details")
      .filter({ has: page.getByText("About this figure", { exact: true }) });
    await expect(notes.first()).not.toHaveAttribute("open", "");
    await notes.first().locator("summary").click();
    await expect(notes.first()).toContainText("each facility is counted once");
    await overview.getByRole("button", { name: "Maximize", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText("2 selected");
    await dialog.locator("summary").filter({ hasText: "Facility types:" }).click();
    await expect(dialog.getByRole("checkbox", { name: first.trim(), exact: true })).toBeChecked();
    await page.keyboard.press("Escape");
    await expect(dialog).not.toBeVisible();
    await overview.getByRole("button", { name: "Present", exact: true }).click();
    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText("Selected:");
    await expect(dialog.getByText("About this figure", { exact: true }).first()).not.toBeVisible();
    expect(await dialog.evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true);
    await page.keyboard.press("Escape");
    await overview.getByRole("button", { name: "All facility types", exact: true }).click();
    await expect(overview).toContainText("Facility types: All facility types");
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
test("all six downloads use the same combined type selection", async ({ page, request }) => {
  await page.goto(`catalog?${scope}&dataset=nhfr`);
  const overview = page.getByTestId("nhfr-overview");
  await overview.locator("summary").filter({ hasText: "Facility types:" }).click();
  const checks = overview.getByRole("checkbox");
  await expect(checks.first()).toBeVisible();
  await checks.nth(0).check();
  await checks.nth(1).check();
  await expect(overview).not.toContainText("Updating the selected headline");
  await overview
    .locator("summary")
    .filter({ hasText: /^Export$/ })
    .click();
  for (const format of ["CSV", "XLSX", "SVG", "PNG", "PDF", "PPTX"]) {
    const link = overview.getByRole("link", { name: format, exact: true });
    await expect(link).toBeVisible();
    const href = (await link.getAttribute("href"))!;
    expect(new URL(href, "http://local").searchParams.getAll("type")).toHaveLength(2);
    const response = await request.get(href);
    expect(response.status()).toBe(200);
    expect(response.headers()["content-disposition"]).toContain(`.${format.toLowerCase()}"`);
    const body = await response.body();
    expect(body.length).toBeGreaterThan(100);
    if (format === "PDF") expect(body.toString("ascii", 0, 4)).toBe("%PDF");
    if (format === "PNG") expect([...body.subarray(0, 4)]).toEqual([137, 80, 78, 71]);
    if (["XLSX", "PPTX"].includes(format)) expect(body.toString("ascii", 0, 2)).toBe("PK");
    if (format === "SVG") expect(body.toString()).toContain("Selected types:");
  }
});
test("failed filter reads never substitute the previous selection or enable its exports", async ({
  page,
}) => {
  await page.goto(`catalog?${scope}&dataset=nhfr`);
  const overview = page.getByTestId("nhfr-overview");
  await overview.locator("summary").filter({ hasText: "Facility types:" }).click();
  await expect(overview.getByRole("checkbox").first()).toBeVisible();
  await page.route("**/api/facilities/headline?*", (route) =>
    route.fulfill({ status: 503, json: { error: "Unavailable" } }),
  );
  await overview.getByRole("checkbox").first().check();
  await expect(overview).toContainText("could not be verified");
  await expect(overview.getByText("Who owns these facilities?", { exact: true })).not.toBeVisible();
  await overview
    .locator("summary")
    .filter({ hasText: /^Export$/ })
    .click();
  await expect(overview.getByRole("link", { name: "PNG", exact: true })).toHaveCount(0);
  await page.unroute("**/api/facilities/headline?*");
  await overview.getByRole("button", { name: "Retry", exact: true }).click();
  await expect(overview).not.toContainText("could not be verified");
  await expect(overview.getByText("Who owns these facilities?", { exact: true })).toBeVisible();
});
