import { expect, test } from "@playwright/test";

for (const width of [390, 1440]) {
  test(`BHW search preserves a chosen population and place at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto("bhw");
    const input = page.getByRole("combobox", { name: "Search BHW places and topics" });
    await input.fill("01 accreditation");
    await input.press("Enter");
    await expect(page).toHaveURL(/\/bhw-connect\/bhw\/search\?q=/);
    const results = page.getByRole("region", { name: "Search results" });
    await expect(
      results.getByRole("link", { name: "LGU-reported accreditation share" }),
    ).toBeVisible();
    const profiles = results.getByRole("link", { name: "Accreditation among validated profiles" });
    await profiles.focus();
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/indicator=bhw.profile-accreditation&location=region%3A01/);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(
      "Accreditation among validated profiles",
    );
    await expect(page.getByText(/REGION I/).first()).toBeVisible();
    await page.reload();
    await expect(page.getByRole("heading", { level: 1 })).toHaveText(
      "Accreditation among validated profiles",
    );
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    await page.goBack();
    await expect(page.getByRole("combobox")).toHaveValue("01 accreditation");
    await page.getByRole("combobox").fill("headcount");
    await page.getByRole("button", { name: "Search", exact: true }).click();
    await page.getByRole("link", { name: "Total BHWs (LGU-reported)", exact: true }).click();
    await expect(page).toHaveURL(/location=national%3APH/);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Total BHWs (LGU-reported)");
  });
}

test("unsupported comparison syntax does not silently pick places or a denominator", async ({
  page,
}) => {
  await page.goto("bhw/search?q=Compare+Cebu+and+Bohol");
  await expect(page.getByRole("status")).toContainText("not supported");
  await expect(page.getByRole("region", { name: "Search results" })).toHaveCount(0);
});
