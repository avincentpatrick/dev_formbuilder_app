import { expect, test } from "@playwright/test";

test("UUC and NHFR keep independent choices and enlarge with those choices", async ({ page }) => {
  await page.goto("./");
  const uuc = page.getByRole("article", { name: "UUC for PHC 2025 map", exact: true });
  const nhfr = page.getByRole("article", { name: "NHFR September 2026 map", exact: true });
  await uuc.scrollIntoViewIfNeeded();
  await expect(uuc.getByLabel("UUC for PHC 2025 area")).toBeVisible();
  await uuc.getByLabel("UUC for PHC 2025 area").selectOption("01");
  await nhfr.scrollIntoViewIfNeeded();
  await nhfr.getByLabel("NHFR September 2026 indicator").selectOption("nhfr.government");
  await expect(nhfr.getByRole("link", { name: "Enlarge NHFR September 2026 map" })).toHaveAttribute(
    "href",
    /indicator=nhfr.government/,
  );
  await expect(uuc.getByRole("link", { name: "Enlarge UUC for PHC 2025 map" })).toHaveAttribute(
    "href",
    /selected=01/,
  );
  await expect.poll(() => page.locator(".maplibregl-map").count()).toBeLessThanOrEqual(2);
  await nhfr.getByRole("link", { name: "Enlarge NHFR September 2026 map" }).click();
  await expect(page.getByLabel("Map dataset")).toHaveValue("nhfr-2026-09");
  await expect(
    page.getByText("Map unavailable. Use the area selector and complete table below."),
  ).toHaveCount(0);
  await expect(page.locator("canvas")).toHaveCount(1);
  await expect(page.getByLabel("NHFR September 2026 indicator")).toHaveValue("nhfr.government");
});

test("enlarged selection and dataset switch preserve supported geography", async ({ page }) => {
  await page.goto("map?dataset=uuc-phc-2025&level=region&code=01&selected=01028");
  await expect(page.getByLabel("UUC for PHC 2025 area")).toHaveValue("01028");
  await page.getByRole("button", { name: /Show areas within/ }).click();
  await expect(page.getByRole("link", { name: "Share this map selection" })).toHaveAttribute(
    "href",
    /level=province&code=01028/,
  );
  await page.getByLabel("Map dataset").selectOption("nhfr-2026-09");
  await expect(page).toHaveURL(/level=province&code=01028/);
  await expect(page.getByLabel("NHFR September 2026 area")).toBeVisible();
  await page.getByLabel("Map dataset").selectOption("ways-forward");
  await expect(page.getByText(/This roadmap has no ingested figures/)).toBeVisible();
  await expect(page.locator("canvas")).toHaveCount(0);
});

test("failed boundaries retain complete table and accessible selection", async ({ page }) => {
  await page.route("**/geo/regions.json", (route) =>
    route.fulfill({ status: 503, body: "Unavailable" }),
  );
  await page.goto("map?dataset=nhfr-2026-09");
  await expect(
    page.getByText("Map unavailable. Use the area selector and complete table below."),
  ).toBeVisible();
  await page.getByText(/Complete area table/).click();
  await expect(page.getByRole("table")).toBeVisible();
  await expect(page.getByLabel("NHFR September 2026 area")).toBeEnabled();
});

test("invalid dataset and period never fall back to BHW", async ({ page, request }) => {
  const response = await request.get("api/dataset-map?dataset=uuc-phc-2025&period=2026");
  expect(response.status()).toBe(400);
  await page.goto("map?dataset=unknown");
  await expect(page.getByLabel("Map dataset")).toHaveValue("");
  await expect(page.locator("canvas")).toHaveCount(0);
});

test("synthetic values preserve zero, missing report and missing geometry independently", async ({
  page,
}) => {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        rows: [
          { code: "01", name: "Synthetic zero", value: 0 },
          { code: "02", name: "Synthetic missing report", value: null },
          { code: "99", name: "Synthetic missing polygon", value: 42 },
        ],
        total: 42,
        area: "Synthetic test geography",
        childLevel: "region",
        source: "Synthetic test fixture",
        date: "2026-09-01",
        license: "Test only",
        geometryUrl: `${route.request().url().split("/api/")[0]}/geo/regions.json`,
      },
    }),
  );
  await page.goto("map?dataset=nhfr-2026-09");
  await page.getByText(/Complete area table/).click();
  await expect(page.getByRole("row", { name: "Synthetic zero 0 Available" })).toBeVisible();
  await expect(
    page.getByRole("row", { name: "Synthetic missing report Not reported Available" }),
  ).toBeVisible();
  await expect(
    page.getByRole("row", { name: "Synthetic missing polygon 42 Missing" }),
  ).toBeVisible();
  await expect(page.getByText(/Reported area total: 42 facilities/)).toBeVisible();
});

test("WebGL failure leaves keyboard selection and the table usable", async ({ page }) => {
  await page.addInitScript(() => {
    const original = HTMLCanvasElement.prototype.getContext;
    HTMLCanvasElement.prototype.getContext = function (this: HTMLCanvasElement, ...args) {
      if (String(args[0]).includes("webgl")) return null;
      return original.apply(this, args);
    } as typeof original;
  });
  await page.goto("map?dataset=uuc-phc-2025");
  await expect(
    page.getByText("Map unavailable. Use the area selector and complete table below."),
  ).toBeVisible();
  await page.getByLabel("UUC for PHC 2025 area").focus();
  await expect(page.getByLabel("UUC for PHC 2025 area")).toBeFocused();
  await page.getByText(/Complete area table/).click();
  await expect(page.getByRole("table")).toBeVisible();
});

test("census, StepZero and PSA maps keep their own indicators and periods", async ({ page }) => {
  await page.goto("map?dataset=bhw-stepzero-2026&indicator=bhw.registered&level=region&code=01");
  await expect(page.getByLabel("StepZero 2026 indicator")).toHaveValue("bhw.registered");
  await page.getByLabel("Map dataset").selectOption("psa-popcen-2024");
  await expect(page).toHaveURL(/period=2024&level=region&code=01/);
  await expect(page.getByLabel("PSA population 2024 indicator")).toHaveValue(
    "population.residents",
  );
  await page.getByLabel("Map dataset").selectOption("bhw-2025");
  await expect(page).toHaveURL(/period=2025&level=region&code=01/);
  await expect(page.getByLabel("BHW Census 2025 indicator")).toHaveValue("bhw.validated-profiles");
});

test("many visible cards share at most two maps and only lease owners load rows", async ({
  page,
}) => {
  await page.setViewportSize({ width: 1400, height: 8000 });
  const datasets: string[] = [];
  page.on("request", (request) => {
    const url = new URL(request.url());
    if (url.pathname.endsWith("/api/dataset-map")) datasets.push(url.searchParams.get("dataset")!);
  });
  await page.goto("./");
  await expect(page.locator(".maplibregl-map")).toHaveCount(2);
  await expect.poll(() => new Set(datasets).size).toBe(2);
  expect(datasets).not.toContain("fhsis-2025");
  await page.setViewportSize({ width: 390, height: 844 });
  const population = page.getByRole("article", { name: "PSA population 2024 map", exact: true });
  await population.scrollIntoViewIfNeeded();
  await expect(population.getByLabel("PSA population 2024 area")).toBeVisible();
  await expect.poll(() => page.locator(".maplibregl-map").count()).toBeLessThanOrEqual(2);
  const before = datasets.filter((d) => d === "psa-popcen-2024").length;
  await page
    .getByRole("article", { name: "Ways forward map", exact: true })
    .scrollIntoViewIfNeeded();
  // Another supported card may be adjacent to Ways forward as adapters roll out.
  await expect(population.locator(".maplibregl-map")).toHaveCount(0);
  await expect.poll(() => page.locator(".maplibregl-map").count()).toBeLessThanOrEqual(2);
  await population.scrollIntoViewIfNeeded();
  await expect(population.locator("canvas")).toHaveCount(1);
  expect(datasets.filter((d) => d === "psa-popcen-2024").length).toBe(before);
});

test("poverty starts with geographic navigation, never regional rates or a parent total", async ({
  page,
}) => {
  await page.goto("map?dataset=psa-sae-poverty-2023");
  await expect(page.getByText(/Geography navigation only/)).toBeVisible();
  await page.getByLabel("PSA SAE poverty 2023 area").selectOption("01");
  await expect(page.locator("p").filter({ hasText: "Not published at this level" })).toBeVisible();
  await page.getByRole("button", { name: /Show areas within/ }).click();
  await page.getByLabel("PSA SAE poverty 2023 area").selectOption("01028");
  await page.getByRole("button", { name: /Show areas within/ }).click();
  await expect(page.getByText(/Fixed percentage breaks/)).toBeVisible();
  await expect(page.getByText(/Reported area total/)).toHaveCount(0);
  await expect(page.getByRole("link", { name: "Share this map selection" })).toHaveAttribute(
    "href",
    /period=2023&level=province&code=01028/,
  );
});

test("poverty table retains published decimal rates, uncertainty, and missing coverage", async ({
  page,
}) => {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        valueSupport: "supported",
        rows: [
          { code: "0102801", name: "Synthetic town", value: 12.345, ciLow: 10.125, ciHigh: 14.875 },
          {
            code: "0102802",
            name: "Synthetic excluded area",
            value: null,
            ciLow: null,
            ciHigh: null,
          },
        ],
        total: null,
        area: "Synthetic test province",
        childLevel: "citymun",
        source: "Synthetic test fixture",
        date: "2023-01-01",
        license: "Test only",
        geometryUrl: `${route.request().url().split("/api/")[0]}/geo/citymun/01028.json`,
      },
    }),
  );
  await page.goto("map?dataset=psa-sae-poverty-2023&level=province&code=01028");
  await page.getByText(/Complete area table/).click();
  await expect(
    page.getByRole("row", { name: /Synthetic town 12.345 10.125%–14.875%/ }),
  ).toBeVisible();
  await expect(
    page.getByRole("row", { name: /Synthetic excluded area Not reported Not available/ }),
  ).toBeVisible();
  await page.getByLabel("PSA SAE poverty 2023 area").selectOption("0102801");
  await expect(
    page.getByText("No barangay poverty estimates are published in this source."),
  ).toBeVisible();
  await expect(page.getByRole("button", { name: /Show areas within/ })).toHaveCount(0);
});

test("expanded map follows container resizing without a window resize", async ({ page }) => {
  await page.goto("map?dataset=psa-sae-poverty-2023&level=province&code=01028");
  await expect(page.locator("canvas")).toHaveCount(1);
  await page.locator(".maplibregl-map").evaluate((el) => {
    el.style.height = "600px";
  });
  await expect
    .poll(() => page.locator("canvas").evaluate((el) => el.clientHeight))
    .toBeGreaterThanOrEqual(590);
});

test("income classes navigate to LGUs with categorical labels, no regional class or totals", async ({
  page,
}) => {
  await page.goto("map?dataset=lgu-income-class");
  await expect(page.getByText(/Geography navigation only. Choose a region/)).toBeVisible();
  await page.getByLabel("LGU income classification area").selectOption("01");
  await page.getByRole("button", { name: /Show areas within/ }).click();
  await expect(page.locator("ul").getByText("First class", { exact: true })).toBeVisible();
  await expect(page.locator("ul").getByText("Fifth class", { exact: true })).toBeVisible();
  await expect(page.getByText(/Reported area total/)).toHaveCount(0);
  await page.getByLabel("LGU income classification area").selectOption("01028");
  await page.getByRole("button", { name: /Show areas within/ }).click();
  await expect(page.getByRole("link", { name: "Share this map selection" })).toHaveAttribute(
    "href",
    /period=Effective\+2025-01-01&level=province&code=01028/,
  );
});

test("income table separates classified, unclassified and unmatched LGUs", async ({ page }) => {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        valueSupport: "supported",
        rows: [
          {
            code: "0102801",
            name: "Synthetic town",
            value: 2,
            note: "Source LGU type: municipality",
          },
          {
            code: "0102802",
            name: "Synthetic unclassified",
            value: null,
            note: "Unclassified in source",
          },
          {
            code: "0102803",
            name: "Synthetic unmatched",
            value: null,
            note: "No matched source record",
          },
        ],
        total: null,
        area: "Synthetic province",
        childLevel: "citymun",
        source: "Synthetic DOF fixture",
        date: "2024-11-05",
        license: "Test only",
        geometryUrl: `${route.request().url().split("/api/")[0]}/geo/citymun/01028.json`,
      },
    }),
  );
  await page.goto("map?dataset=lgu-income-class&level=province&code=01028");
  await page.getByText(/Complete area table/).click();
  await expect(
    page.getByRole("row", { name: /Synthetic town Second class Source LGU type: municipality/ }),
  ).toBeVisible();
  await expect(
    page.getByRole("row", { name: /Synthetic unclassified Unclassified in source/ }),
  ).toBeVisible();
  await expect(
    page.getByRole("row", { name: /Synthetic unmatched No matched source record/ }),
  ).toBeVisible();
  await page.getByLabel("LGU income classification area").selectOption("0102801");
  await expect(
    page.getByText(
      "Barangays have no independently published income class; this classification belongs to the named LGU.",
    ),
  ).toBeVisible();
  await expect(page.getByRole("button", { name: /Show areas within/ })).toHaveCount(0);
});

test("profiling stays hidden on the homepage and has its own stage map and shareable geography", async ({
  page,
}) => {
  await page.goto("./");
  await expect(
    page.getByRole("article", { name: "Profiling status 2026 map", exact: true }),
  ).toHaveCount(0);
  await page.goto("map?dataset=bhw-profiling-status-2026");
  await expect(page.getByLabel("Profiling status 2026 indicator")).toHaveValue("encoded");
  await page.getByLabel("Profiling status 2026 indicator").selectOption("attested");
  await expect(
    page.getByText(/Stages are mutually exclusive current workflow states/),
  ).toBeVisible();
  await page.getByLabel("Profiling status 2026 area").selectOption("01");
  await page.getByRole("button", { name: /Show areas within/ }).click();
  await expect(page.getByRole("link", { name: "Share this map selection" })).toHaveAttribute(
    "href",
    /indicator=attested&period=2026&level=region&code=01/,
  );
  await expect(page.getByText(/Reported area total/)).toHaveCount(0);
});

test("profiling shows over-headcount shares and unavailable denominators without capping", async ({
  page,
}) => {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        rows: [
          {
            code: "01",
            name: "Synthetic overshoot",
            value: 120,
            note: "120 records / 100 BHWs to profile; pipeline exceeds headcount; shares are not capped",
          },
          {
            code: "02",
            name: "Synthetic zero denominator",
            value: null,
            note: "5 records; zero BHW headcount — share unavailable",
          },
        ],
        total: null,
        area: "Philippines",
        childLevel: "region",
        source: "Synthetic snapshot",
        date: "2026-04-01",
        license: "Test only",
        geometryUrl: `${route.request().url().split("/api/")[0]}/geo/regions.json`,
      },
    }),
  );
  await page.goto("map?dataset=bhw-profiling-status-2026&indicator=attested");
  await page.getByText(/Complete area table/).click();
  await expect(
    page.getByRole("row", { name: /Synthetic overshoot 120 120 records/ }),
  ).toBeVisible();
  await expect(
    page.getByRole("row", {
      name: /Synthetic zero denominator Not reported 5 records; zero BHW headcount/,
    }),
  ).toBeVisible();
  await expect(page.locator("ul").getByText("100% or more", { exact: true })).toBeVisible();
});

test("UUC qualifying routes preserve independent selections and share the chosen count", async ({
  page,
}) => {
  await page.goto("./");
  const uuc = page.getByRole("article", { name: "UUC for PHC 2025 map", exact: true });
  await uuc.scrollIntoViewIfNeeded();
  await uuc.getByLabel("UUC for PHC 2025 indicator").selectOption("uuc.route.health");
  await expect(uuc.getByText(/Routes overlap and must not be added together/)).toBeVisible();
  await expect(uuc.getByRole("link", { name: "Enlarge UUC for PHC 2025 map" })).toHaveAttribute(
    "href",
    /indicator=uuc.route.health&period=2025/,
  );
  const nhfr = page.getByRole("article", { name: "NHFR September 2026 map", exact: true });
  await expect(nhfr.getByLabel("NHFR September 2026 indicator")).toHaveValue("nhfr.facilities");
  await uuc.getByRole("link", { name: "Enlarge UUC for PHC 2025 map" }).click();
  await expect(page.getByLabel("UUC for PHC 2025 indicator")).toHaveValue("uuc.route.health");
});

test("UUC health-route table distinguishes zero, excluded and missing reports", async ({
  page,
}) => {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        rows: [
          {
            code: "01",
            name: "Synthetic partial",
            value: 4,
            note: "6 health-evaluable of 10 listed barangays; 4 excluded from health evaluation",
          },
          {
            code: "02",
            name: "Synthetic zero",
            value: 0,
            note: "0 health-evaluable of 5 listed barangays; 5 excluded from health evaluation; no evaluable denominator for a share",
          },
          { code: "03", name: "Synthetic missing", value: null, note: "No reported route count" },
        ],
        total: 20,
        totalNote: "30 health-evaluable of 40 listed barangays; 10 excluded from health evaluation",
        area: "Synthetic national",
        childLevel: "region",
        source: "Synthetic UUC",
        date: "2025-12-31",
        license: "Test only",
        geometryUrl: `${route.request().url().split("/api/")[0]}/geo/regions.json`,
      },
    }),
  );
  await page.goto("map?dataset=uuc-phc-2025&indicator=uuc.route.health");
  await page.getByText(/Complete area table/).click();
  await expect(
    page.getByRole("row", { name: /Synthetic partial 4 6 health-evaluable/ }),
  ).toBeVisible();
  await expect(
    page.getByRole("row", { name: /Synthetic zero 0 0 health-evaluable/ }),
  ).toBeVisible();
  await expect(
    page.getByRole("row", { name: /Synthetic missing Not reported No reported route count/ }),
  ).toBeVisible();
  await expect(page.getByText(/Reported area total: 20 barangays/)).toBeVisible();
});

test("map data download preserves complete rows and selected context when geometry fails", async ({ page }) => {
  await page.route("**/api/dataset-map?**", (route) => route.fulfill({ json: {
    state: "available", rows: [
      { code: "01", name: "Synthetic zero", value: 0 },
      { code: "02", name: "Synthetic missing", value: null },
      { code: "99", name: "Synthetic unplaced", value: 42 },
    ], total: 42, area: "Synthetic geography", childLevel: "region", source: "Synthetic source",
    date: "2026-09-01", license: "Test only", geometryUrl: "/bhw-connect/geo/regions.json",
  } }));
  await page.route("**/geo/regions.json", (route) => route.fulfill({ status: 503, body: "Unavailable" }));
  await page.goto("map?dataset=nhfr-2026-09&selected=99");
  const downloadPromise = page.waitForEvent("download");
  await page.getByRole("button", { name: "Download map data (JSON)" }).click();
  const download = await downloadPromise;
  const stream = await download.createReadStream();
  const chunks = [];
  for await (const chunk of stream!) chunks.push(chunk);
  const content = JSON.parse(Buffer.concat(chunks).toString());
  expect(content.selection).toMatchObject({ dataset: "nhfr-2026-09", selected: "99" });
  expect(content.indicator.period).toBe("September 2026");
  expect(content.rows.map((r: { value: number | null }) => r.value)).toEqual([0, null, 42]);
  expect(content.rows.every((r: { geometry: string }) => r.geometry === "not-checked")).toBe(true);
  expect(content.total).toBe(42);
  await page.getByLabel("Map dataset").selectOption("ways-forward");
  await expect(page.getByRole("button", { name: "Download map data (JSON)" })).toHaveCount(0);
});

for (const viewportWidth of [1280, 390]) {
test(`PNG download includes a nonblank rendered WebGL map at ${viewportWidth}px`, async ({ page }, testInfo) => {
  await page.setViewportSize({ width: viewportWidth, height: 844 });
  await page.route("**/api/dataset-map?**", (route) => route.fulfill({ json: {
    state: "available", rows: [
      { code: "01028", name: "Synthetic Ilocos Norte", value: 0 },
      { code: "01029", name: "Synthetic Ilocos Sur", value: 20 },
      { code: "01033", name: "Synthetic La Union", value: 40 },
      { code: "01055", name: "Synthetic Pangasinan", value: 60 },
    ], total: 120, area: "Synthetic Region I", childLevel: "province",
    source: "Synthetic export fixture", date: "2026-09-01", license: "Test only",
    geometryUrl: "/bhw-connect/geo/provinces/01.json",
  } }));
  await page.goto("map?dataset=nhfr-2026-09&level=region&code=01");
  const button = page.getByRole("button", { name: "Download map image (PNG)" });
  await expect(button).toBeEnabled();
  const downloadPromise = page.waitForEvent("download");
  await button.click();
  const download = await downloadPromise;
  expect(download.suggestedFilename()).toBe("nhfr-2026-09-nhfr.facilities-01.png");
  await download.saveAs(testInfo.outputPath("map-export.png"));
  const stream = await download.createReadStream();
  const chunks = [];
  for await (const chunk of stream!) chunks.push(chunk);
  const bytes = Buffer.concat(chunks);
  expect(bytes.subarray(1, 4).toString()).toBe("PNG");
  const pixels = await page.evaluate(async (base64) => {
    const image = new Image(); image.src = `data:image/png;base64,${base64}`;
    await image.decode();
    const canvas = document.createElement("canvas"); canvas.width = image.width; canvas.height = image.height;
    const ctx = canvas.getContext("2d")!; ctx.drawImage(image, 0, 0);
    // The minimum colored area below exceeds all legend swatches combined.
    const rgba = ctx.getImageData(0, 100, canvas.width, canvas.height - 100).data;
    let colored = 0;
    for (let i = 0; i < rgba.length; i += 4) {
      if (Math.max(rgba[i], rgba[i + 1], rgba[i + 2]) - Math.min(rgba[i], rgba[i + 1], rgba[i + 2]) > 20) colored++;
    }
    return { width: image.width, height: image.height, colored };
  }, bytes.toString("base64"));
  expect(pixels.width).toBe(1200);
  expect(pixels.height).toBeGreaterThan(800);
  expect(pixels.colored).toBeGreaterThan(5000);
});
}

test("failed geometry cannot produce a PNG", async ({ page }) => {
  await page.route("**/geo/regions.json", (route) => route.fulfill({ status: 503, body: "Unavailable" }));
  await page.goto("map?dataset=nhfr-2026-09");
  await expect(page.getByText("Map unavailable. Use the area selector and complete table below.")).toBeVisible();
  await expect(page.getByRole("button", { name: "Download map image (PNG)" })).toBeDisabled();
  await expect(page.getByRole("button", { name: "Download map data (JSON)" })).toBeEnabled();
});

test("area search preserves complete data, missing shapes and selection", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  let requests = 0;
  await page.route("**/api/dataset-map?**", (route) => {
    requests++;
    return route.fulfill({ json: {
      state: "available", rows: [
        { code: "01", name: "Santo Niño", value: null },
        { code: "02", name: "San José", value: 0 },
        { code: "99", name: "Without shape", value: 42 },
      ], total: 42, area: "Synthetic geography", childLevel: "region", source: "Test",
      date: "2026-09-01", license: "Test", geometryUrl: "/bhw-connect/geo/regions.json",
    } });
  });
  await page.route("**/geo/regions.json", (route) => route.fulfill({ status: 503, body: "Unavailable" }));
  await page.goto("map?dataset=nhfr-2026-09&selected=99");
  const search = page.getByRole("searchbox", { name: "NHFR September 2026 search areas" });
  const selector = page.getByLabel("NHFR September 2026 area", { exact: true });
  await search.fill("NINO");
  await expect(page.getByText("1 matching areas of 3.", { exact: false })).toBeVisible();
  await expect(selector).toHaveValue("99");
  await expect(selector.locator("option")).toHaveCount(3);
  await search.fill("no matching place");
  await expect(page.getByText("0 matching areas of 3.", { exact: false })).toBeVisible();
  await expect(selector).toHaveValue("99");
  await page.getByRole("button", { name: "Clear area search" }).click();
  await search.fill("02");
  await search.press("Tab"); // The visible clear action is keyboard reachable.
  await expect(page.getByRole("button", { name: "Clear area search" })).toBeFocused();
  await selector.selectOption("02");
  await expect(page.getByRole("link", { name: "Share this map selection" })).toHaveAttribute("href", /selected=02/);
  await page.getByText(/Complete area table/).click();
  await expect(page.getByRole("table").getByRole("row")).toHaveCount(4);
  await expect(page.getByText(/Reported area total: 42 facilities/)).toBeVisible();
  expect(requests).toBe(1);
  const downloadPromise = page.waitForEvent("download");
  await page.getByRole("button", { name: "Download map data (JSON)" }).click();
  const download = await downloadPromise;
  const stream = await download.createReadStream();
  const chunks = [];
  for await (const chunk of stream!) chunks.push(chunk);
  const exported = JSON.parse(Buffer.concat(chunks).toString());
  expect(exported.rows.map((row: { code: string }) => row.code)).toEqual(["01", "02", "99"]);
  expect(exported.total).toBe(42);
  await page.getByLabel("NHFR September 2026 indicator").selectOption("nhfr.government");
  await expect(search).toHaveValue("02");
  await page.getByLabel("Map dataset").selectOption("uuc-phc-2025");
  await expect(page.getByRole("searchbox", { name: "UUC for PHC 2025 search areas" })).toHaveValue("");
});

test("portal card searches remain independent", async ({ page }) => {
  await page.goto("./");
  const uuc = page.getByRole("article", { name: "UUC for PHC 2025 map", exact: true });
  const nhfr = page.getByRole("article", { name: "NHFR September 2026 map", exact: true });
  await uuc.scrollIntoViewIfNeeded();
  await uuc.getByRole("searchbox").fill("01");
  await nhfr.scrollIntoViewIfNeeded();
  await expect(nhfr.getByRole("searchbox")).toHaveValue("");
  await nhfr.getByRole("searchbox").fill("02");
  await expect(uuc.getByRole("searchbox")).toHaveValue("01");
});

test("phone portal loads only visible datasets and disposes maps without losing state", async ({ page }) => {
  await page.setViewportSize({ width: 390, height: 844 });
  const requests: string[] = [];
  page.on("request", (request) => {
    const url = new URL(request.url());
    if (url.pathname.endsWith("/api/dataset-map")) requests.push(url.searchParams.get("dataset")!);
  });
  await page.addInitScript(() => {
    const state = window as typeof window & { mapPeak: number };
    state.mapPeak = 0;
    new MutationObserver(() => {
      state.mapPeak = Math.max(state.mapPeak, document.querySelectorAll(".maplibregl-map").length);
    }).observe(document, { childList: true, subtree: true });
  });
  await page.goto("./");
  await page.waitForLoadState("networkidle");
  expect(requests).toEqual([]);
  await expect(page.locator(".maplibregl-map")).toHaveCount(0);
  const uuc = page.getByRole("article", { name: "UUC for PHC 2025 map", exact: true });
  await uuc.scrollIntoViewIfNeeded();
  await expect(uuc.locator('[data-map-state="ready"]')).toHaveCount(1);
  await uuc.getByLabel("UUC for PHC 2025 area", { exact: true }).selectOption("01");
  await uuc.getByRole("searchbox").fill("01");
  expect(new Set(requests)).toEqual(new Set(["uuc-phc-2025"]));
  const firstReads = requests.filter((slug) => slug === "uuc-phc-2025").length;
  const nhfr = page.getByRole("article", { name: "NHFR September 2026 map", exact: true });
  await nhfr.scrollIntoViewIfNeeded();
  await expect(nhfr.locator('[data-map-state="ready"]')).toHaveCount(1);
  await expect(uuc.locator(".maplibregl-map")).toHaveCount(0);
  expect(new Set(requests)).toEqual(new Set(["uuc-phc-2025", "nhfr-2026-09"]));
  await uuc.getByText(/Map paused/).scrollIntoViewIfNeeded();
  await expect(uuc.locator('[data-map-state="ready"]')).toHaveCount(1);
  await expect(uuc.getByLabel("UUC for PHC 2025 area", { exact: true })).toHaveValue("01");
  await expect(uuc.getByRole("searchbox")).toHaveValue("01");
  expect(requests.filter((slug) => slug === "uuc-phc-2025")).toHaveLength(firstReads);
  await page.evaluate(() => window.scrollTo(0, 0));
  await expect(page.locator(".maplibregl-map")).toHaveCount(0);
  expect(await page.evaluate(() => (window as typeof window & { mapPeak: number }).mapPeak)).toBeLessThanOrEqual(2);
});

test("tall desktop portal keeps the two-map limit when several cards are visible", async ({ page }) => {
  await page.setViewportSize({ width: 1280, height: 3000 });
  await page.addInitScript(() => {
    const state = window as typeof window & { mapPeak: number };
    state.mapPeak = 0;
    new MutationObserver(() => {
      state.mapPeak = Math.max(state.mapPeak, document.querySelectorAll(".maplibregl-map").length);
    }).observe(document, { childList: true, subtree: true });
  });
  await page.goto("./");
  await page.getByRole("heading", { name: "Dataset maps", exact: true }).scrollIntoViewIfNeeded();
  await expect(page.locator('[data-map-state="ready"]')).toHaveCount(2);
  expect(await page.evaluate(() => (window as typeof window & { mapPeak: number }).mapPeak)).toBeLessThanOrEqual(2);
});

test("retrying failed geometry retains choices and avoids rereading dataset values", async ({ page }) => {
  let geometryReads = 0;
  let dataReads = 0;
  page.on("request", (request) => { if (request.url().includes("/api/dataset-map?")) dataReads++; });
  await page.route("**/geo/regions.json", (route) => {
    geometryReads++;
    return geometryReads === 1 ? route.fulfill({ status: 503, body: "Temporary failure" }) : route.continue();
  });
  await page.goto("map?dataset=nhfr-2026-09&selected=01");
  const retry = page.getByRole("button", { name: "Retry map rendering" });
  await expect(retry).toBeVisible();
  await page.getByRole("searchbox").fill("01");
  await expect(page.getByRole("button", { name: "Download map image (PNG)" })).toBeDisabled();
  await retry.focus();
  await page.keyboard.press("Enter");
  await expect(page.locator('[data-map-state="ready"]')).toHaveCount(1);
  await expect(retry).toHaveCount(0);
  await expect(page.getByRole("searchbox")).toHaveValue("01");
  await expect(page.getByLabel("NHFR September 2026 area", { exact: true })).toHaveValue("01");
  await expect(page.getByRole("button", { name: "Download map image (PNG)" })).toBeEnabled();
  expect(dataReads).toBe(1);
  expect(geometryReads).toBe(2);
  await expect(page.locator(".maplibregl-map")).toHaveCount(1);
});

test("repeated rendering failure leaves table and JSON available", async ({ page }) => {
  let dataReads = 0;
  page.on("request", (request) => { if (request.url().includes("/api/dataset-map?")) dataReads++; });
  await page.route("**/geo/regions.json", (route) => route.fulfill({ status: 503, body: "Unavailable" }));
  await page.goto("map?dataset=uuc-phc-2025");
  for (let attempt = 0; attempt < 2; attempt++) {
    await page.getByRole("button", { name: "Retry map rendering" }).click();
    await expect(page.locator('[data-map-state="failed"]')).toHaveCount(1);
  }
  await page.getByText(/Complete area table/).click();
  await expect(page.getByRole("table")).toBeVisible();
  await expect(page.getByRole("button", { name: "Download map data (JSON)" })).toBeEnabled();
  await expect(page.getByRole("button", { name: "Download map image (PNG)" })).toBeDisabled();
  expect(dataReads).toBe(1);
  await expect(page.locator(".maplibregl-map")).toHaveCount(0);
});

test("renderer retry can recover after WebGL becomes available", async ({ page }) => {
  await page.addInitScript(() => {
    const state = window as typeof window & { blockMapContext: boolean };
    state.blockMapContext = true;
    const original = HTMLCanvasElement.prototype.getContext;
    HTMLCanvasElement.prototype.getContext = function (this: HTMLCanvasElement, ...args) {
      if (state.blockMapContext && String(args[0]).includes("webgl")) return null;
      return original.apply(this, args);
    } as typeof original;
  });
  await page.goto("map?dataset=uuc-phc-2025&selected=01");
  await expect(page.locator('[data-map-state="failed"]')).toHaveCount(1);
  await page.evaluate(() => { (window as typeof window & { blockMapContext: boolean }).blockMapContext = false; });
  await page.getByRole("button", { name: "Retry map rendering" }).click();
  await expect(page.locator('[data-map-state="ready"]')).toHaveCount(1);
  await expect(page.getByLabel("UUC for PHC 2025 area", { exact: true })).toHaveValue("01");
  await expect(page.getByRole("button", { name: "Download map image (PNG)" })).toBeEnabled();
  await expect(page.locator(".maplibregl-map")).toHaveCount(1);
});

test("StepZero source population and households keep their own dates, values and exports", async ({ page }) => {
  await page.route("**/api/dataset-map?**", (route) => {
    if (new URL(route.request().url()).searchParams.get("dataset") !== "bhw-stepzero-2026") return route.continue();
    const column = new URL(route.request().url()).searchParams.get("indicator");
    return route.fulfill({ json: {
      state: "available", rows: [
        { code: "01", name: "Synthetic zero", value: 0 },
        { code: "02", name: "Synthetic missing", value: null },
        { code: "99", name: "Synthetic unplaced", value: column === "stepzero.households" ? 12 : 42 },
      ], total: column === "stepzero.households" ? 25 : 123, area: "Synthetic geography",
      childLevel: "region", source: "StepZero synthetic source", date: "2026-09-01",
      license: "Test only", geometryUrl: "/bhw-connect/geo/regions.json",
    } });
  });
  await page.goto("map?dataset=bhw-stepzero-2026&indicator=stepzero.population&period=2026");
  await expect(page.getByLabel("StepZero 2026 indicator")).toHaveValue("stepzero.population");
  await page.getByText(/Complete area table/).click();
  await expect(page.getByRole("row", { name: /Synthetic zero 0/ })).toBeVisible();
  await expect(page.getByRole("row", { name: /Synthetic missing Not reported/ })).toBeVisible();
  await expect(page.getByText(/Reported area total: 123 people/)).toBeVisible();
  await page.getByLabel("StepZero 2026 indicator").selectOption("stepzero.households");
  await expect(page.getByText(/Reported area total: 25 households/)).toBeVisible();
  const downloadPromise = page.waitForEvent("download");
  await page.getByRole("button", { name: "Download map data (JSON)" }).click();
  const stream = await (await downloadPromise).createReadStream();
  const chunks = [];
  for await (const chunk of stream!) chunks.push(chunk);
  const exported = JSON.parse(Buffer.concat(chunks).toString());
  expect(exported.indicator).toMatchObject({ id: "stepzero.households", period: "2026", unit: "households" });
  expect(exported.rows.map((row: { value: number | null }) => row.value)).toEqual([0, null, 12]);
  expect(exported.total).toBe(25);
  await page.getByLabel("Map dataset").selectOption("psa-popcen-2024");
  await expect(page.getByLabel("PSA population 2024 indicator")).toHaveValue("population.residents");
  await expect(page.getByRole("link", { name: "Share this map selection" })).toHaveAttribute("href", /period=2024/);
});

test("StepZero accreditation uses a published parent rate and fixed percentage legend", async ({ page }) => {
  await page.route("**/api/dataset-map?**", (route) => route.fulfill({ json: {
    state: "available", rows: [
      { code: "01", name: "Synthetic zero", value: 0, note: "Published share" },
      { code: "02", name: "Synthetic decimal", value: 33.33, note: "Published share" },
      { code: "99", name: "Synthetic undefined", value: null, note: "No valid positive StepZero BHW denominator or numerator." },
    ], total: null, parentRate: 25, area: "Synthetic geography", childLevel: "region", source: "Synthetic StepZero",
    date: "2026-09-01", license: "Test only", geometryUrl: "/bhw-connect/geo/regions.json",
  } }));
  await page.goto("map?dataset=bhw-stepzero-2026&indicator=bhw.declared-accreditation&period=2026");
  await expect(page.getByText(/Reported area rate: 25%/)).toBeVisible();
  await expect(page.getByText(/Denominator: All LGU-declared BHWs/)).toBeVisible();
  await expect(page.getByText(/Fixed percentage breaks across areas/)).toBeVisible();
  await expect(page.getByText(/Reported area total:/)).toHaveCount(0);
  await page.getByText(/Complete area table/).click();
  await expect(page.getByRole("row", { name: /Synthetic decimal 33.33/ })).toBeVisible();
  await expect(page.getByRole("row", { name: /Synthetic undefined Not reported/ })).toBeVisible();
  const downloadPromise = page.waitForEvent("download");
  await page.getByRole("button", { name: "Download map data (JSON)" }).click();
  const stream = await (await downloadPromise).createReadStream();
  const chunks = [];
  for await (const chunk of stream!) chunks.push(chunk);
  const exported = JSON.parse(Buffer.concat(chunks).toString());
  expect(exported.parentMeasure).toEqual({ kind: "rate", value: 25, unit: "%" });
  expect(exported.total).toBeNull();
  expect(exported.legend.method).toBe("fixed");
  expect(exported.indicator.period).toBe("2026");
});

test("Census accreditation preserves profile denominator, Wilson intervals and small-sample notes", async ({ page }) => {
  await page.route("**/api/dataset-map?**", route => route.fulfill({ json: {
    state: "available", rows: [{ code: "01", name: "Synthetic Census region", value: 33.33,
      ciLow: 6.15, ciHigh: 79.23, smallSample: true, note: "Published Census percentage; 3 validated profiles. Few BHWs profiled — unstable." }],
    total: null, parentRate: 25, area: "Philippines", childLevel: "region",
    source: "Synthetic Census release", date: "2025-12-31", license: "Test only", geometryUrl: "/bhw-connect/geo/regions.json",
  } }));
  await page.goto("map?dataset=bhw-2025&indicator=bhw.profile-accreditation");
  await expect(page.locator('[data-map-state="ready"]')).toHaveCount(1);
  await expect(page.getByText("Few BHWs profiled — unstable", { exact: true })).toBeVisible();
  await expect(page.getByText(/Reported area rate: 25%/)).toBeVisible();
  await expect(page.getByText(/Denominator: Validated BHW Census profiles/)).toBeVisible();
  await page.getByText("Complete area table (1)").click();
  await expect(page.getByRole("columnheader", { name: "Published 95% confidence interval" })).toBeVisible();
  await expect(page.getByRole("cell", { name: "6.15%–79.23%" })).toBeVisible();
  await expect(page.getByRole("cell", { name: /Few BHWs profiled/ })).toBeVisible();
  const downloaded = page.waitForEvent("download");
  await page.getByRole("button", { name: "Download map data (JSON)" }).click();
  const stream = await (await downloaded).createReadStream();
  const chunks = []; for await (const chunk of stream!) chunks.push(chunk);
  const data = JSON.parse(Buffer.concat(chunks).toString());
  expect(data.indicator).toMatchObject({ period: "2025", confidenceLevel: 0.95 });
  expect(data.parentMeasure).toMatchObject({ kind: "rate", value: 25 });
  expect(data.rows[0]).toMatchObject({ value: 33.33, ciLow: 6.15, ciHigh: 79.23, smallSample: true });
});
