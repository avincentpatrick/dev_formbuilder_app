import { expect, test } from "@playwright/test";
import { parse } from "csv-parse/sync";
const scope = "catalog?mode=psgc&location=citymun%3A0102801&dataset=nhfr";
for (const width of [320, 390, 768, 1440]) {
  test(`facility mix retains comparison choices and fits ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(scope);
    const card = page.getByTestId("facility-mix-card");
    await expect(card.getByRole("button", { name: "Facility mix", exact: true })).toHaveAttribute(
      "aria-pressed",
      "true",
    );
    const note = card
      .locator("details")
      .filter({ has: page.getByText("About this figure", { exact: true }) });
    await expect(note).not.toHaveAttribute("open", "");
    await card.locator("summary").filter({ hasText: "Facility types:" }).click();
    const choices = card.getByRole("checkbox");
    const names = [
      await choices.nth(0).locator("..").innerText(),
      await choices.nth(1).locator("..").innerText(),
    ];
    await choices.nth(0).check();
    await choices.nth(1).check();
    await expect(card.getByRole("listitem")).toHaveCount(2);
    await expect(card).toContainText("2 of 3 registered facilities");
    await card.getByRole("button", { name: "Ownership within types", exact: true }).click();
    await card.getByLabel("Order types", { exact: true }).selectOption("name");
    await expect(card).toContainText("share within each type");
    await card.getByRole("button", { name: "Maximize", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await expect(dialog).toBeVisible();
    await expect(
      dialog.getByRole("button", { name: "Ownership within types", exact: true }),
    ).toHaveAttribute("aria-pressed", "true");
    await expect(dialog).toContainText(names[0].trim());
    await expect(dialog).toContainText(names[1].trim());
    await dialog.getByRole("button", { name: "Restore", exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await card.getByRole("button", { name: "Present", exact: true }).click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByText("About this figure", { exact: true })).toHaveCount(0);
    expect(await dialog.evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true);
    await page.keyboard.press("Escape");
    await note.locator("summary").click();
    await expect(note).toContainText("does not change that denominator");
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
test("comparison exports preserve multiple types, ownership view, ordering and exact shares", async ({
  page,
  request,
}) => {
  await page.goto(scope);
  const card = page.getByTestId("facility-mix-card");
  await card.locator("summary").filter({ hasText: "Facility types:" }).click();
  await card.getByRole("checkbox").nth(0).check();
  await card.getByRole("checkbox").nth(1).check();
  await card.getByRole("button", { name: "Ownership within types", exact: true }).click();
  await card.getByLabel("Order types", { exact: true }).selectOption("name");
  await card
    .locator("summary")
    .filter({ hasText: /^Export$/ })
    .click();
  for (const format of ["CSV", "XLSX", "SVG", "PNG", "PDF", "PPTX"]) {
    const href = (await card
      .getByRole("link", { name: format, exact: true })
      .getAttribute("href"))!;
    const params = new URL(href, "http://local").searchParams;
    expect(params.getAll("type")).toHaveLength(2);
    expect(params.get("view")).toBe("ownership");
    expect(params.get("order")).toBe("name");
    const response = await request.get(href);
    expect(response.status()).toBe(200);
    const body = await response.body();
    expect(body.length).toBeGreaterThan(100);
    if (format === "CSV") {
      const rows = parse(body, { bom: true, columns: true }) as Record<string, string>[];
      expect(rows).toHaveLength(2);
      expect(Number(rows[0]["Share of all facilities (%)"])).toBe(100 / 3);
      expect(rows[0]["Geographic code"]).toBe("0102801");
    }
    if (format === "PDF") expect(body.subarray(0, 4).toString()).toBe("%PDF");
    if (format === "PNG") expect([...body.subarray(0, 4)]).toEqual([137, 80, 78, 71]);
    if (["XLSX", "PPTX"].includes(format)) expect(body.subarray(0, 2).toString()).toBe("PK");
    if (format === "SVG") expect(body.toString()).toContain("Ownership within each facility type");
  }
});
test("invalid or unsupported comparison exports fail without a different area's data", async ({
  request,
}) => {
  for (const query of [
    "mode=all",
    "mode=psgc&location=barangay:0102801001",
    "mode=psgc&location=citymun:0102801&view=invalid",
    "mode=psgc&location=citymun:0102801&format=svg&format=pdf",
  ]) {
    const response = await request.get(`api/export/facilities/mix?format=csv&${query}`);
    expect(response.status()).toBe(400);
  }
  const response = await request.get(
    "api/export/facilities/mix?format=csv&mode=psgc&location=citymun:0102801&type=Invented",
  );
  expect(response.status()).toBe(503);
});
