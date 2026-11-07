import { expect, test } from "@playwright/test";

// Synthetic search responses isolate navigation from database availability.
const places = [
  { geoLevel: "region", geoCode: "01", geoName: "Ilocos Region" },
  { geoLevel: "province", geoCode: "01028", geoName: "Ilocos Norte" },
  { geoLevel: "citymun", geoCode: "0102801", geoName: "Adams" },
  { geoLevel: "barangay", geoCode: "0102801001", geoName: "Poblacion" },
];
test.beforeEach(async ({ page }) => {
  await page.route("**/api/geo/search?*", (route) =>
    route.fulfill({
      json: {
        results: [
          ...places.map((place) => ({
            kind: "geo",
            ...place,
            nTotal: null,
            parentChain: { province: "Ilocos Norte" },
          })),
          {
            kind: "district",
            districtCode: "test-district",
            districtName: "Example district",
            matchedMemberName: "Adams",
            bhwTotal: null,
          },
        ],
      },
    }),
  );
});

test("portal searches all dataset destinations and supports keyboard selection", async ({
  page,
}) => {
  await page.goto("./");
  const input = page.locator("input[role=combobox]");
  await input.fill("Ilocos");
  await expect(page.getByRole("listbox").getByRole("option")).toHaveCount(17);
  for (const place of places) {
    for (const section of ["place", "uuc-phc", "facilities", "health-services"]) {
      await expect(
        page.locator(`[role=option] a[href$="/${section}/${place.geoLevel}/${place.geoCode}"]`),
      ).toHaveCount(1);
    }
  }
  await input.fill(" Ilocos ");
  await expect(page.getByRole("listbox").getByRole("option")).toHaveCount(17);
  await input.press("Escape");
  await expect(page.getByRole("listbox")).toHaveCount(0);
  await input.focus();
  await input.press("ArrowDown");
  await input.press("Enter");
  await expect(page).toHaveURL(/\/uuc-phc\/region\/01$/);
});

for (const section of ["uuc-phc", "facilities", "health-services"]) {
  test(`${section} keeps place searches within its dataset`, async ({ page }) => {
    await page.goto(section);
    await page.locator("input[role=combobox]").fill("Ilocos");
    await expect(page.getByRole("listbox").getByRole("option")).toHaveCount(4);
    await expect(page.getByRole("listbox")).not.toContainText("No profile data yet");
    await expect(
      page.locator(`[role=option] a[href$="/${section}/barangay/0102801001"]`),
    ).toHaveCount(1);
  });
}

test("district search finds member matches", async ({ page }) => {
  await page.goto("districts");
  await page
    .getByRole("combobox", {
      name: "Find a district or member city, municipality, or barangay",
      exact: true,
    })
    .fill("Adams");
  await expect(page.getByRole("listbox").getByRole("option")).toHaveCount(1);
  await expect(page.getByRole("listbox").getByRole("option")).toContainText("Example district");
});

test("search handles empty and failed responses on mobile", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  await page.route("**/api/geo/search?*", (route) => route.fulfill({ json: { results: [] } }));
  await page.goto("./");
  await page.locator("input[role=combobox]").fill("Unknown");
  await expect(page.getByText("No matching places found.")).toBeVisible();
  await page.route("**/api/geo/search?*", (route) => route.fulfill({ status: 500, json: {} }));
  await page.locator("input[role=combobox]").fill("Retry");
  await expect(
    page.getByText("Couldn't search — check your connection and try again."),
  ).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(
    true,
  );
});
