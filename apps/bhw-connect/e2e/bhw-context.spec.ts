import { expect, test } from "@playwright/test";
for (const width of [390, 1440])
  test(`context keeps pairs, scope and navigation at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    const errors: string[] = [];
    page.on("pageerror", (e) => errors.push(e.message));
    let fail = false;
    await page.route("**/api/dataset-map?**", (route) => {
      if (fail) return route.fulfill({ status: 503, json: { state: "failed" } });
      const indicator = new URL(route.request().url()).searchParams.get("indicator");
      return route.fulfill({
        json: {
          state: "available",
          area: "Synthetic Region VII",
          childLevel: "province",
          source: "Synthetic Headcount",
          date: "2025-01-01",
          license: null,
          total: 999,
          geometryUrl: "/bhw-connect/geo/provinces/07.json",
          navigation: {
            parents: [{ level: "national", code: "PH", name: "Philippines" }],
            complete: true,
          },
          rows: [
            { code: "07012", name: "Bohol", value: 0 },
            { code: "07022", name: "Cebu", value: indicator === "bhw.total" ? 50 : 100 },
            { code: "07306", name: "Cebu City", value: indicator === "bhw.total" ? null : 100 },
            { code: "07311", name: "Lapu-Lapu", value: 99999, smallSample: true },
          ],
        },
      });
    });
    await page.goto("bhw/context?level=region&code=07");
    await expect(page.getByText("2 of 4 listed", { exact: false })).toBeVisible();
    const table = page.getByRole("region", { name: "Context exact values", exact: true });
    await expect(table.getByRole("row")).toHaveCount(5);
    await expect(table).not.toContainText("99999");
    await table.getByRole("link", { name: "Cebu (07022)", exact: true }).click();
    const selected = page.getByRole("region", { name: "Selected locality", exact: true });
    await expect(selected).toContainText("Headcount: 50 people");
    await expect(
      selected.getByRole("link", { name: "Open full locality dashboard" }),
    ).toHaveAttribute("href", /location=province%3A07022/);
    await page.reload();
    await expect(selected).toContainText("Cebu");
    await page
      .getByRole("navigation", { name: "Context measure" })
      .getByRole("link", { name: "Source population", exact: true })
      .click();
    await expect(page).toHaveURL(/context=population.*selected=07022/);
    await expect(selected).toContainText("Source population: 100 people");
    await page.goBack();
    await expect(page).toHaveURL(/context=households/);
    const point = page
      .locator("svg a")
      .filter({ has: page.locator("circle") })
      .first();
    await point.focus();
    await point.press("Enter");
    await expect(selected).toContainText("Headcount: 0 people");
    await table.focus();
    await expect(table).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    expect(errors).toEqual([]);
    fail = true;
    await page.reload();
    await expect(page.getByRole("main").getByRole("alert")).toContainText("No substitute");
    await expect(table).toHaveCount(0);
    fail = false;
    await page.getByRole("button", { name: "Retry context" }).click();
    await expect(table).toBeVisible();
    await page.goto("bhw/context?level=region&code=bad");
    await expect(page.getByRole("main").getByRole("alert")).toContainText("No national fallback");
  });
