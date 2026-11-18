import { expect, test, type Page } from "@playwright/test";
const rows = [
  { code: "07012", name: "Bohol", value: 0 },
  { code: "07022", name: "Cebu", value: 100 },
  { code: "07306", name: "Cebu City", value: 50 },
  { code: "07311", name: "Lapu-Lapu", value: null },
  { code: "07313", name: "Mandaue", value: 25 },
];
async function fixture(page: Page) {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        rows,
        total: 999,
        area: "Region VII",
        childLevel: "province",
        navigation: { parents: [], complete: true },
        source: "Synthetic fixture",
        date: "2025-12-31",
        license: "Test only",
        geometryUrl: `${new URL(route.request().url()).origin}/bhw-connect/geo/provinces/07.json`,
      },
    }),
  );
}
for (const width of [390, 1440]) {
  test(`count filtering preserves totals, missing shapes and shared exports at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    await fixture(page);
    await page.goto("map?dataset=bhw-stepzero-2026&level=region&code=07");
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page
      .locator(".maplibregl-canvas")
      .evaluate((canvas) => canvas.setAttribute("data-canvas-instance", "original"));
    await page.getByText("Filter workforce counts", { exact: true }).click();
    await page.getByLabel("Minimum count", { exact: true }).fill("25");
    await page.getByLabel("Maximum count", { exact: true }).fill("50");
    await page.getByRole("button", { name: "Apply count range", exact: true }).click();
    await expect(page.locator(".maplibregl-canvas")).toHaveAttribute(
      "data-canvas-instance",
      "original",
    );
    const bars = page.getByRole("region", { name: "Ranked area counts", exact: true });
    await expect(bars.getByRole("button")).toHaveCount(2);
    await expect(bars.getByRole("button").first()).toContainText("Cebu City");
    await expect(page.getByText(/Reported area total: 999/)).toBeVisible();
    await page.getByText(/Complete area table/).click();
    await expect(
      page.getByRole("row", { name: "Bohol 0 Excluded by range Available", exact: true }),
    ).toBeVisible();
    await expect(
      page.getByRole("row", { name: "Cebu City 50 Included Missing", exact: true }),
    ).toBeVisible();
    await expect(
      page.getByRole("row", { name: "Lapu-Lapu Not reported Unavailable Missing", exact: true }),
    ).toBeVisible();
    const download = page.waitForEvent("download");
    await page.getByRole("button", { name: "Download map data (JSON)" }).click();
    const result = await download;
    const stream = await result.createReadStream();
    const chunks = [];
    for await (const chunk of stream!) chunks.push(chunk);
    const exported = JSON.parse(Buffer.concat(chunks).toString());
    expect(exported.total).toBe(999);
    expect(exported.rows).toHaveLength(5);
    expect(exported.valueFilter).toMatchObject({
      minimum: 25,
      maximum: 50,
      included: 2,
      excluded: 2,
      unavailable: 1,
    });
    expect(exported.legend.symbols.maximum).toBe(100);
    await bars.getByRole("button", { name: /Cebu City/ }).click();
    await page.getByRole("link", { name: "Share this map selection" }).click();
    await expect(page).toHaveURL(/valueMin=25&valueMax=50/);
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    await expect(page.getByText(/2 included, 2 excluded, 1 unavailable/)).toBeVisible();
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await expect(bars.getByRole("button", { name: /Cebu City/ })).toHaveAttribute(
      "aria-pressed",
      "true",
    );
    await page.getByText("Filter workforce counts", { exact: true }).click();
    await page.getByRole("button", { name: "Reset count range", exact: true }).click();
    await expect(bars.getByRole("button")).toHaveCount(4);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
}
test("invalid and empty ranges are explicit; indicator changes reset the range", async ({
  page,
}) => {
  await fixture(page);
  await page.goto("map?dataset=bhw-stepzero-2026&level=region&code=07");
  await page.getByText("Filter workforce counts", { exact: true }).click();
  await page.getByLabel("Minimum count", { exact: true }).fill("51");
  await page.getByLabel("Maximum count", { exact: true }).fill("50");
  await page.getByRole("button", { name: "Apply count range" }).click();
  await expect(page.getByRole("alert").filter({ hasText: "Use nonnegative" })).toContainText(
    "minimum no greater than maximum",
  );
  await page.getByLabel("Maximum count", { exact: true }).fill("99");
  await page.getByRole("button", { name: "Apply count range" }).click();
  await expect(
    page.getByText("No counts within this range. The complete table remains available."),
  ).toBeVisible();
  await page
    .getByLabel("BHW Headcount 2025 indicator", { exact: true })
    .selectOption("bhw.declared-accreditation");
  await expect(page.getByText("Count range reset for the new indicator.")).toBeVisible();
  await expect(page.getByText("Filter workforce counts", { exact: true })).toHaveCount(0);
});
test("unsupported range share links never silently discard the range", async ({ page }) => {
  await page.goto("map?dataset=bhw-stepzero-2026&valueMin=10&valueMax=1");
  await expect(page.getByText(/If a shared link has an unsupported indicator/)).toBeVisible();
  await expect(page.locator('[data-map-state="ready"]')).toHaveCount(0);
});

test("a shared choropleth restores the range and changing dataset resets it", async ({ page }) => {
  await fixture(page);
  await page.goto(
    "map?dataset=bhw-stepzero-2026&level=region&code=07&view=choropleth&valueMin=25&valueMax=50",
  );
  await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
  await expect(page.getByText(/2 included, 2 excluded, 1 unavailable/)).toBeVisible();
  await page.getByLabel("Map dataset", { exact: true }).selectOption("bhw-2025");
  await expect(page).not.toHaveURL(/valueMin|valueMax/);
  await expect(page).toHaveURL(/view=choropleth/);
  await expect(
    page.getByRole("region", { name: "Ranked area counts", exact: true }).getByRole("button"),
  ).toHaveCount(4);
});

test("a replacement canvas shows loading and failure instead of stale ready status", async ({
  page,
}) => {
  await fixture(page);
  let requests = 0;
  let release!: () => void;
  const held = new Promise<void>((resolve) => {
    release = resolve;
  });
  let observed!: () => void;
  const requested = new Promise<void>((resolve) => {
    observed = resolve;
  });
  await page.route("**/geo/provinces/07.json", async (route) => {
    requests += 1;
    if (requests === 1) return route.continue();
    if (requests === 2) {
      observed();
      await held;
      return route.fulfill({ status: 503, body: "Synthetic boundary failure" });
    }
    return route.continue();
  });
  await page.goto("map?dataset=bhw-stepzero-2026&level=region&code=07");
  await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
  await page
    .getByLabel("BHW Headcount 2025 indicator", { exact: true })
    .selectOption("bhw.registered");
  await requested;
  await expect(page.locator("[data-map-state]")).toHaveAttribute("data-map-state", "loading");
  await expect(page.getByRole("button", { name: "Zoom in", exact: true })).toBeDisabled();
  release();
  await expect(page.locator("[data-map-state]")).toHaveAttribute("data-map-state", "failed");
  await page.getByRole("button", { name: "Retry map rendering", exact: true }).click();
  await expect(page.locator("[data-map-state]")).toHaveAttribute("data-map-state", "ready");
  await expect(page.getByRole("button", { name: "Zoom in", exact: true })).toBeEnabled();
});
