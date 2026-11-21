import { expect, test } from "@playwright/test";

// Synthetic public-API responses: never production source records.
const payload = {
  state: "available",
  childLevel: "citymun",
  valueSupport: "supported",
  total: null,
  area: "Synthetic province",
  source: "Synthetic PSA fixture",
  date: "2023-12-31",
  license: "Test fixture",
  geometryUrl: "",
  rows: [
    { code: "0102802", name: "Bacarra", value: null, ciLow: null, ciHigh: null },
    {
      code: "0102801",
      name: "Adams — a deliberately long municipality label for readable comparison",
      value: 0,
      ciLow: -0.2,
      ciHigh: 4.7,
    },
    { code: "0102803", name: "Badoc", value: 12.34, ciLow: 8.2, ciHigh: 16.9 },
  ],
};
for (const width of [390, 1440]) {
  test(`Poverty municipal discovery preserves zero, uncertainty, search and locality at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.route("**/api/dataset-map?**", (route) => route.fulfill({ json: payload }));
    await page.goto("catalog?mode=psgc&location=province%3A01028&dataset=poverty");
    const list = page.getByTestId("poverty-municipalities");
    await expect(list).toContainText("3 of 3 places shown");
    await expect(list.getByRole("button", { name: "Chart", exact: true })).toHaveAttribute(
      "aria-pressed",
      "true",
    );
    await expect(
      list.getByRole("img", { name: /Adams.*0%.*90% confidence interval: -0.2% to 4.7%/ }),
    ).toBeVisible();
    await expect(list.getByRole("listitem").nth(1)).toContainText(
      "Unavailable — no usable accepted estimate",
    );
    await list.getByRole("button", { name: "Table", exact: true }).focus();
    await page.keyboard.press("Enter");
    await expect(list.getByRole("row").nth(1)).toContainText("Adams");
    await expect(list.getByRole("row").nth(1)).toContainText("0%");
    await expect(list.getByRole("row").nth(1)).toContainText("-0.2% to 4.7%");
    await expect(list.getByRole("row").nth(2)).toContainText(
      "Unavailable — no usable accepted estimate",
    );
    const search = list.getByRole("searchbox");
    await search.fill("0102801");
    await expect(list).toContainText("1 of 3 places shown");
    await list.getByRole("button", { name: "Chart", exact: true }).click();
    await expect(list.getByRole("img", { name: /Adams/ })).toBeVisible();
    await page.reload();
    await expect(search).toHaveValue("0102801");
    const place = list.getByRole("link", { name: /Adams/ });
    await place.focus();
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/location=citymun%3A0102801/);
    await expect(list).toContainText("Selected place");
    await expect(search).toHaveValue("0102801");
    await page.goBack();
    await expect(page).toHaveURL(/location=province%3A01028/);
    await expect(search).toHaveValue("0102801");
    await page.goForward();
    await expect(page).toHaveURL(/location=citymun%3A0102801/);
    await expect(
      list.getByRole("link", { name: "Explore this province on the Poverty map" }),
    ).toHaveAttribute("href", /code=01028.*selected=0102801/);
    await search.fill("no such place");
    await expect(list).toContainText("No matching places");
    await list.getByRole("button", { name: "Clear municipality search" }).click();
    await expect(list).toContainText("3 of 3 places shown");
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
test("Poverty municipal request failure, withheld and unavailable states stay distinct", async ({
  page,
}) => {
  for (const [state, text] of [
    ["failed", "request failure"],
    ["suppressed", "withheld"],
    ["unavailable", "unavailable from the accepted release"],
    ["unsupported", "unsupported for this scope"],
  ]) {
    await page.route("**/api/dataset-map?**", (route) =>
      route.fulfill({ status: state === "failed" ? 500 : 200, json: { state } }),
    );
    await page.goto("catalog?mode=psgc&location=province%3A01028&dataset=poverty");
    await expect(page.getByTestId("poverty-municipalities")).toContainText(text);
    await expect(page.getByTestId("poverty-municipalities").getByRole("table")).toHaveCount(0);
    await page.unroute("**/api/dataset-map?**");
  }
});
for (const width of [390, 1440]) {
  test(`Poverty map selection returns to details and survives history at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.route("**/api/dataset-map?**", (route) =>
      route.fulfill({
        json: {
          ...payload,
          geometryUrl: `${new URL(route.request().url()).origin}/bhw-connect/geo/citymun/01028.json`,
        },
      }),
    );
    await page.goto(
      "map?dataset=psa-sae-poverty-2023&indicator=poverty.incidence&period=2023&level=province&code=01028&povertyProvince=01028&povertySearch=0102802",
    );
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    const area = page.getByRole("combobox", { name: / area$/ });
    await area.selectOption("0102801");
    await expect(page).toHaveURL(/selected=0102801/);
    await area.selectOption("0102802");
    await expect(page).toHaveURL(/selected=0102802/);
    await page.goBack();
    await expect(area).toHaveValue("0102801");
    await page.goForward();
    await expect(area).toHaveValue("0102802");
    await page.reload();
    await expect(area).toHaveValue("0102802");
    await page.getByRole("link", { name: "Open Poverty details for this selection" }).click();
    await expect(page).toHaveURL(/location=citymun%3A0102802/);
    await expect(page.getByTestId("poverty-municipalities").getByRole("searchbox")).toHaveValue(
      "0102802",
    );
    await expect(page.getByTestId("poverty-municipalities")).toContainText("Selected place");
    await page
      .getByRole("navigation", { name: "Location ancestors" })
      .getByRole("link")
      .filter({ hasText: /ILOCOS NORTE/i })
      .click();
    await expect(page).toHaveURL(/location=province%3A01028/);
    await page.getByRole("link", { name: "Return to previous Poverty map" }).click();
    await expect(page).toHaveURL(/povertySearch=0102802/);
    await expect(page).toHaveURL(/selected=0102802/);
    await expect(area).toHaveValue("0102802");
  });
}
