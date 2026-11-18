import { expect, test } from "@playwright/test";
import { missingCebuShapes } from "./helpers/missing-cebu-shapes";

test("an empty boundary collection keeps the table usable and finishes loading", async ({
  page,
}) => {
  await page.route("**/geo/regions.json", (route) =>
    route.fulfill({ json: { type: "FeatureCollection", features: [] } }),
  );
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        rows: [{ code: "01", name: "Undrawable area", value: 42 }],
        total: 42,
        area: "Philippines",
        childLevel: "region",
        source: "Synthetic",
        date: "2025-12-31",
        license: null,
        geometryUrl: `${route.request().url().split("/api/")[0]}/geo/regions.json`,
      },
    }),
  );
  await page.goto("map?dataset=bhw-stepzero-2026");
  await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
  await expect(
    page.getByText("1 of 1 areas have no drawable shape.", { exact: false }),
  ).toBeVisible();
  await expect(page.getByRole("button", { name: "Download map image (PNG)" })).toBeDisabled();
  await page.getByText(/Complete area table/).click();
  await expect(
    page.getByRole("row", { name: "Undrawable area 42 Missing", exact: true }),
  ).toBeVisible();
});

for (const width of [390, 1440]) {
  test(`BHW count symbols, controls and shared views work at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.route("**/api/dataset-map?**", (route) =>
      route.fulfill({
        json: {
          state: "available",
          rows: [
            { code: "01", name: "Synthetic zero", value: 0 },
            { code: "02", name: "Synthetic unavailable", value: null },
            { code: "03", name: "Synthetic positive", value: 100 },
            { code: "99", name: "Synthetic missing shape", value: 25 },
          ],
          total: 125,
          area: "Philippines",
          childLevel: "region",
          source: "Synthetic fixture",
          date: "2025-12-31",
          license: "Test only",
          geometryUrl: `${route.request().url().split("/api/")[0]}/geo/regions.json`,
        },
      }),
    );
    await page.goto("map?dataset=bhw-stepzero-2026");
    await expect(page.getByRole("combobox", { name: "BHW map view", exact: true })).toHaveValue(
      "circles",
    );
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    if (width < 1024)
      await page.getByRole("button", { name: /map legend and reading guide/ }).click();
    await expect(page.getByRole("region", { name: "Circle size legend" })).toBeVisible();
    await expect(
      page.getByText("1 of 4 areas have no drawable shape.", { exact: false }),
    ).toBeVisible();
    await page.getByRole("button", { name: "Zoom out", exact: true }).click();
    await page.getByRole("button", { name: "Reset map view", exact: true }).focus();
    await page.keyboard.press("Enter");
    await expect(page.locator(".maplibregl-ctrl-scale")).toBeVisible();
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByText(/Complete area table/).click();
    await page.getByRole("button", { name: "Synthetic missing shape", exact: true }).click();
    await expect(page.getByLabel("BHW Headcount 2025 area")).toHaveValue("99");
    const jsonDownload = page.waitForEvent("download");
    await page.getByRole("button", { name: "Download map data (JSON)" }).click();
    const download = await jsonDownload;
    const stream = await download.createReadStream();
    const chunks: Buffer[] = [];
    for await (const chunk of stream!) chunks.push(Buffer.from(chunk));
    const exported = JSON.parse(Buffer.concat(chunks).toString());
    expect(exported.selection.view).toBe("circles");
    expect(exported.legend.symbols.maximum).toBe(100);
    expect(exported.rows.find((r: { code: string }) => r.code === "99")).toMatchObject({
      value: 25,
      geometry: "missing",
    });
    await page
      .getByRole("combobox", { name: "BHW map view", exact: true })
      .selectOption("choropleth");
    await page.getByRole("link", { name: "Share this map selection" }).click();
    await expect(page).toHaveURL(/view=choropleth/);
    await page.reload();
    await expect(page.getByRole("combobox", { name: "BHW map view", exact: true })).toHaveValue(
      "choropleth",
    );
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}

test("Region VII retains three HUCs with no shapes and scopes the return link", async ({
  page,
}) => {
  await missingCebuShapes(page);
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        rows: [
          { code: "07012", name: "Bohol", value: 0 },
          { code: "07022", name: "Cebu", value: 100 },
          { code: "07306", name: "Cebu City", value: 50 },
          { code: "07311", name: "Lapu-Lapu", value: null },
          { code: "07313", name: "Mandaue", value: 25 },
        ],
        total: 175,
        area: "Region VII",
        childLevel: "province",
        source: "Synthetic fixture",
        date: "2025-12-31",
        license: "Test only",
        geometryUrl: `${route.request().url().split("/api/")[0]}/geo/provinces/07.json`,
      },
    }),
  );
  await page.goto("map?dataset=bhw-stepzero-2026&level=region&code=07");
  await expect(
    page.getByText("3 of 5 areas have no drawable shape.", { exact: false }),
  ).toBeVisible();
  await expect(page.getByRole("link", { name: "← BHW dataset" })).toHaveAttribute(
    "href",
    /location=region%3A07&dataset=bhw/,
  );
  await page.getByText(/Complete area table/).click();
  await expect(page.getByRole("row", { name: "Cebu City 50 Missing", exact: true })).toBeVisible();
  await expect(page.getByText(/Reported area total: 175 people/)).toBeVisible();
});
