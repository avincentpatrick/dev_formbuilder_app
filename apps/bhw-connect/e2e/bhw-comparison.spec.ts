import { expect, test } from "@playwright/test";

for (const width of [390, 1440]) {
  test(`public comparison resolves search and retains two-to-four-place state at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto("bhw/search?q=Compare+01+and+02+accreditation");
    const open = page.getByRole("button", { name: "Open comparison" });
    await expect(open).toBeDisabled();
    await page
      .getByRole("combobox", { name: "Comparison measure", exact: true })
      .selectOption("bhw.declared-accreditation");
    await page
      .getByRole("combobox", { name: "Choose match for 01", exact: true })
      .selectOption("01");
    await page
      .getByRole("combobox", { name: "Choose match for 02", exact: true })
      .selectOption("02");
    await open.click();
    await expect(page).toHaveURL(/measure=bhw.declared-accreditation&geos=01%2C02/);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Compare BHW places");
    await page
      .getByRole("combobox", { name: "Comparison measure", exact: true })
      .selectOption("bhw.total");
    await expect(page).toHaveURL(/measure=bhw.total&geos=01%2C02/);
    async function choose(code: string, replace = "") {
      await page
        .getByRole("combobox", { name: "Selection action", exact: true })
        .selectOption(replace);
      await page.getByRole("searchbox", { name: "Add or replace a place" }).fill(code);
      await page.getByRole("button", { name: "Find places", exact: true }).click();
      await page
        .getByRole("list", { name: "Matching comparison places" })
        .getByRole("button")
        .click();
    }
    await choose("03");
    await expect(page).toHaveURL(/geos=01%2C02%2C03/);
    await choose("04", "02");
    await expect(page).toHaveURL(/geos=01%2C04%2C03/);
    await choose("05");
    await expect(page).toHaveURL(/geos=01%2C04%2C03%2C05/);
    await expect(
      page
        .getByRole("list", { name: "Places selected for comparison" })
        .getByRole("button", { name: /^Remove/ }),
    ).toHaveCount(4);
    if (width === 390) {
      await page
        .getByRole("navigation", { name: "Comparison place details" })
        .getByRole("link")
        .nth(1)
        .click();
      await expect(page).toHaveURL(/focus=04/);
    }
    await page.reload();
    await expect(
      page.getByRole("combobox", { name: "Comparison measure", exact: true }),
    ).toHaveValue("bhw.total");
    await expect(page.getByRole("link", { name: "Shareable comparison link" })).toHaveAttribute(
      "href",
      /geos=01%2C04%2C03%2C05/,
    );
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    await page
      .getByRole("list", { name: "Places selected for comparison" })
      .getByRole("button", { name: /^Remove/ })
      .last()
      .click();
    await expect(page).not.toHaveURL(/%2C05/);
    await page.goBack();
    await expect(page).toHaveURL(/%2C05/);
  });
}

test("requests over four retain every requested place and let readers choose", async ({ page }) => {
  await page.goto("bhw/search?q=Compare+01,+02,+03,+04,+05+headcount");
  await expect(page.getByRole("status")).toContainText("none have been discarded");
  await expect(page.getByRole("checkbox")).toHaveCount(5);
  for (const code of ["01", "05"]) {
    await page.getByLabel(`Include ${code}`, { exact: true }).check();
    await page
      .getByRole("combobox", { name: `Choose match for ${code}`, exact: true })
      .selectOption(code);
  }
  await page.getByRole("button", { name: "Open comparison" }).click();
  await expect(page).toHaveURL(/geos=01%2C05/);
  await page.goto("compare?measure=bhw.total&geos=01,02,03,04,05");
  await expect(
    page.getByText("Choose at most four places using Remove below.", { exact: false }),
  ).toBeVisible();
  const chips = page.getByRole("list", { name: "Places selected for comparison" });
  await expect(chips.getByRole("button", { name: /^Remove/ })).toHaveCount(5);
  await chips
    .getByRole("button", { name: /^Remove/ })
    .last()
    .click();
  await expect(page).toHaveURL(/geos=01%2C02%2C03%2C04/);
});

test("failed and empty add-place searches remain different", async ({ page }) => {
  await page.goto("compare?measure=bhw.total");
  await page.route("**/api/bhw/places?*", (route) =>
    route.fulfill({ status: 503, json: { error: "failed" } }),
  );
  await page.getByRole("searchbox", { name: "Add or replace a place" }).fill("Example");
  await page.getByRole("button", { name: "Find places", exact: true }).click();
  await expect(page.getByRole("main").getByRole("alert")).toContainText("Place search failed");
  await page.unroute("**/api/bhw/places?*");
  await page.route("**/api/bhw/places?*", (route) => route.fulfill({ json: { results: [] } }));
  await page.getByRole("button", { name: "Find places", exact: true }).click();
  await expect(page.getByText("No matching places. Try another name or PSGC code.")).toBeVisible();
  await expect(page.getByRole("main").getByRole("alert")).toHaveCount(0);
});
