import { expect, test } from "@playwright/test";

const payload = {
  state: "available",
  rows: [
    { code: "07", name: "Synthetic Central Visayas", value: 100 },
    { code: "02", name: "Synthetic unavailable region", value: null },
  ],
  total: 100,
  area: "Synthetic Philippines",
  childLevel: "region",
  source: "Synthetic fixture",
  date: "2025-12-31",
  license: "Test only",
  geometryUrl: "/bhw-connect/geo/regions.json",
};

for (const width of [390, 1440]) {
  test(`one transient data failure recovers without replacing unavailable values at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    const requests: string[] = [];
    await page.route("**/api/dataset-map?**", (route) => {
      requests.push(route.request().url());
      return requests.length === 1
        ? route.fulfill({
            status: 503,
            contentType: "text/plain",
            body: "Temporary gateway failure",
          })
        : route.fulfill({ json: payload });
    });
    await page.goto("map?dataset=bhw-stepzero-2026");
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible({ timeout: 15000 });
    expect(requests).toHaveLength(2);
    expect(requests[1]).toBe(requests[0]);
    if (width === 390)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByText(/Complete area table/).click();
    await expect(
      page.getByRole("row", { name: "Synthetic Central Visayas 100 Available", exact: true }),
    ).toBeVisible();
    await expect(
      page.getByRole("row", {
        name: "Synthetic unavailable region Not reported Available",
        exact: true,
      }),
    ).toBeVisible();
  });
}

test("two failures remain explicit and manual retry still works", async ({ page }) => {
  let requests = 0;
  await page.route("**/api/dataset-map?**", (route) => {
    requests++;
    return requests <= 2
      ? route.fulfill({ status: 503, json: { state: "failed" } })
      : route.fulfill({ json: payload });
  });
  await page.goto("map?dataset=bhw-stepzero-2026");
  await expect(
    page.getByText("Map data could not be loaded. This does not mean zero."),
  ).toBeVisible();
  expect(requests).toBe(2);
  await expect(page.locator('[data-map-state="ready"]')).toHaveCount(0);
  await page.getByRole("button", { name: "Retry map", exact: true }).click();
  await expect(page.locator('[data-map-state="ready"]')).toBeVisible({ timeout: 15000 });
  expect(requests).toBe(3);
});
