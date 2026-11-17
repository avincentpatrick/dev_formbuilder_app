import { expect, test, type Page } from "@playwright/test";
async function fixture(page: Page, empty = false) {
  await page.route("**/api/dataset-map?**", (route) =>
    route.fulfill({
      json: {
        state: "available",
        rows: [
          { code: "07012", name: "Bohol", value: empty ? null : 0 },
          { code: "07022", name: "Cebu", value: empty ? null : 101.25 },
          { code: "07306", name: "Cebu City", value: empty ? null : 20.5 },
          { code: "07311", name: "Lapu-Lapu", value: null },
          { code: "07313", name: "Mandaue", value: empty ? null : 12.34 },
        ],
        total: null,
        parentMean: 9.87,
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
  test(`overview restores edited BHW analysis after reload at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await fixture(page);
    await page.goto(
      "map?dataset=bhw-2025&indicator=bhw.active-years&level=region&code=07&view=choropleth&classification=custom&classBreaks=10,20&valueMin=5&valueMax=101.25",
    );
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await page.getByText("Filter mean active years", { exact: true }).click();
    await page.getByLabel("Minimum mean active years", { exact: true }).fill("12.34");
    await page.getByRole("button", { name: "Apply years range", exact: true }).click();
    await page.getByLabel("BHW Profiles 2025 area", { exact: true }).selectOption("07306");
    await expect(page).toHaveURL(/selected=07306/);
    const beforeComparison = page.url();
    await page.getByRole("link", { name: "Compare selected place", exact: true }).click();
    await expect(page).toHaveURL(/compare\?measure=bhw.active-years&geos=07306&mapState=/);
    await expect(page.getByRole("heading", { level: 1 })).toHaveText("Compare BHW places");
    await expect(
      page.getByRole("combobox", { name: "Comparison measure", exact: true }),
    ).toHaveValue("bhw.active-years");
    await page.reload();
    await expect(page).toHaveURL(/compare\?measure=bhw.active-years&geos=07306&mapState=/);
    const returnFromComparison = page.getByRole("link", {
      name: "Return to previous BHW map",
      exact: true,
    });
    await expect(returnFromComparison).toHaveAttribute(
      "href",
      new URL(beforeComparison).pathname + new URL(beforeComparison).search,
    );
    await page
      .getByRole("combobox", { name: "Comparison measure", exact: true })
      .selectOption("bhw.total");
    await expect(page).toHaveURL(/measure=bhw.total/);
    await expect(
      page.getByRole("link", { name: "Shareable comparison link", exact: true }),
    ).toHaveAttribute("href", /mapState=/);
    await page.reload();
    await expect(returnFromComparison).toHaveAttribute(
      "href",
      new URL(beforeComparison).pathname + new URL(beforeComparison).search,
    );
    await returnFromComparison.click();
    await expect(page).toHaveURL(beforeComparison);
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    const previous = page.url();
    await page.getByRole("link", { name: "Open dashboard", exact: true }).click();
    await expect(page).toHaveURL(/bhw\?location=region%3A07&mapState=/);
    await page.reload();
    await expect(
      page.getByText(/Workforce, qualifications and support\..*REGION VII/i),
    ).toBeVisible();
    await expect(page.getByText(/Dashboard figures describe.*REGION VII/i)).toBeVisible();
    const back = page.getByRole("link", { name: "Return to previous BHW map", exact: true });
    await expect(back).toHaveAttribute(
      "href",
      new URL(previous).pathname + new URL(previous).search,
    );
    const explorer = page.getByRole("link", {
      name: "Map explorer · previous settings",
      exact: true,
    });
    await expect(explorer).toHaveAttribute(
      "href",
      new URL(previous).pathname + new URL(previous).search,
    );
    const fresh = page.getByRole("link", {
      name: /^Explore headcount for /,
      exact: false,
    });
    const freshParams = new URL((await fresh.getAttribute("href"))!, page.url()).searchParams;
    expect(freshParams.get("dataset")).toBe("bhw-stepzero-2026");
    expect(freshParams.get("code")).toBe("07");
    expect(freshParams.has("valueMin")).toBe(false);
    await explorer.click();
    await expect(page).toHaveURL(previous);
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    await page.goBack();
    await expect(explorer).toBeVisible();
    // Overview and comparison keep their own scope while retaining the same way back.
    const overviewBeforeCompare = page.url();
    await page
      .getByRole("navigation", { name: "BHW analysis" })
      .getByRole("link", { name: "Compare places", exact: true })
      .click();
    await expect(
      page.getByRole("heading", { name: "Compare BHW places", exact: true }),
    ).toBeVisible();
    expect(new URL(page.url()).searchParams.get("mapState")).toBe(
      new URL(previous).search.slice(1),
    );
    expect(new URL(page.url()).searchParams.get("geos")).toBe("07");
    await page.reload();
    await page.goBack();
    await expect(page).toHaveURL(overviewBeforeCompare);
    await page.reload();
    await expect(back).toHaveAttribute(
      "href",
      new URL(previous).pathname + new URL(previous).search,
    );
    // Purpose sections must keep the return snapshot, including on reload/history.
    const overviewUrl = page.url();
    const nav = page.getByRole("navigation", { name: "BHW analysis" });
    for (const [label, section] of [
      ["Qualifications and training", "qualifications"],
      ["Support and compensation", "support"],
      ["Data and methodology", "methodology"],
      ["Workforce distribution", "workforce"],
    ]) {
      await nav.getByRole("link", { name: label, exact: true }).click();
      await expect(page).toHaveURL(`${overviewUrl}#${section}`);
      await expect(page.locator(`#${section}:visible`)).toBeInViewport();
      await expect(back).toHaveAttribute(
        "href",
        new URL(previous).pathname + new URL(previous).search,
      );
    }
    await page.reload();
    await expect(page).toHaveURL(`${overviewUrl}#workforce`);
    await expect(back).toBeVisible();
    await page.goBack();
    await expect(page).toHaveURL(`${overviewUrl}#methodology`);
    await page.goForward();
    await expect(page).toHaveURL(`${overviewUrl}#workforce`);
    await nav.getByRole("link", { name: "Data and methodology", exact: true }).click();
    // History navigation can retain a hidden copy of the previous route.
    // Keep strict matching, but target the summary the reader can interact with.
    await page.locator("#methodology > summary:visible").click();
    await page
      .getByRole("link", { name: /^View indicators and availability for /, exact: false })
      .click();
    await expect(page).toHaveURL(/catalog\?/);
    const catalogQuery = new URL(page.url()).searchParams;
    expect(catalogQuery.get("location")).toBe("region:07");
    expect(catalogQuery.get("dataset")).toBe("bhw");
    expect(catalogQuery.get("mapState")).toBe(new URL(previous).search.slice(1));
    await page.reload();
    await expect(back).toHaveAttribute(
      "href",
      new URL(previous).pathname + new URL(previous).search,
    );
    await expect(page.getByText(/Catalog figures keep their own scope/)).toBeVisible();
    await back.click();
    await expect(page).toHaveURL(previous);
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    await expect(page.getByLabel("Effective classification")).toContainText(
      "Custom cut points: 3 actual groups",
    );
    if (width < 1024)
      await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    await expect(page.getByLabel("BHW Profiles 2025 area", { exact: true })).toHaveValue("07306");
    await page.getByText("Filter mean active years", { exact: true }).click();
    await expect(page.getByLabel("Minimum mean active years", { exact: true })).toHaveValue(
      "12.34",
    );
    await page.goBack();
    await expect(back).toBeVisible();
    await page.goForward();
    await expect(page).toHaveURL(previous);
  });
}
test("invalid or non-BHW return settings are explained without a return link", async ({ page }) => {
  for (const raw of [
    "dataset=bhw-2025&valueMin=-1",
    "https://example.com",
    "dataset=bhw-profiling-status-2026",
  ]) {
    await page.goto(`bhw?mapState=${encodeURIComponent(raw)}`);
    await expect(
      page.getByText(
        "The previous map settings are invalid or unsupported. Open a new map from the dataset details.",
      ),
    ).toBeVisible();
    await expect(
      page.getByRole("link", { name: "Return to previous BHW map", exact: true }),
    ).toHaveCount(0);
  }
});
