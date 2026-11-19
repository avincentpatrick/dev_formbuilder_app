import { expect, test } from "@playwright/test";
for (const width of [390, 1440])
  test(`review reasons and locality profile stay scoped at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await page.route("**/api/dataset-map?**", (route) => {
      const head = new URL(route.request().url()).searchParams.get("indicator") === "bhw.total";
      return route.fulfill({
        json: {
          state: "available",
          area: "Synthetic Region VII",
          childLevel: "province",
          source: "Synthetic source",
          date: "2025-01-01",
          license: null,
          total: 999,
          geometryUrl: "/bhw-connect/geo/provinces/07.json",
          navigation: { parents: [], complete: true },
          rows: [
            { code: "07012", name: "Bohol", value: head ? 0 : 100 },
            { code: "07022", name: "Cebu", value: head ? 10 : 90 },
            { code: "07306", name: "Cebu City", value: head ? 20 : 20 },
            { code: "07311", name: "Lapu-Lapu", value: head ? 30 : 10 },
            { code: "07313", name: "Mandaue", value: head ? null : 1000 },
          ],
        },
      });
    });
    await page.goto("bhw/context?level=region&code=07&context=households");
    const review = page.getByRole("region", { name: "Places for closer examination", exact: true });
    await expect(review).toBeVisible();
    const gaps = review.getByRole("region", { name: "Check reporting gaps", exact: true });
    await expect(gaps).toContainText("Mandaue");
    await expect(gaps).toContainText("not a zero-workforce finding");
    const zeros = review.getByRole("region", {
      name: "Check reported zero headcounts",
      exact: true,
    });
    await expect(zeros).toContainText("Bohol");
    await expect(zeros).not.toContainText("Mandaue");
    const contrasts = review.getByRole("region", {
      name: "Compare lower headcount with larger context",
      exact: true,
    });
    await expect(contrasts.getByRole("listitem")).toHaveCount(2);
    await expect(contrasts).toContainText("median 15");
    await expect(contrasts).toContainText("median 55");
    await contrasts.getByRole("link", { name: "Cebu (07022)", exact: true }).click();
    await expect(page).toHaveURL(/selected=07022/);
    const selected = page.getByRole("region", { name: "Selected locality", exact: true });
    await expect(selected).toContainText("Headcount: 10 people");
    await selected.getByRole("link", { name: "Open full locality dashboard" }).click();
    await expect(page).toHaveURL(/bhw\?location=province%3A07022/);
    await expect(page.locator("#workforce-heading")).toHaveText("Workforce in CEBU");
    await expect(page.locator("#qualifications")).toBeVisible();
    await expect(page.locator("#support")).toBeVisible();
    await page.goBack();
    await expect(selected).toContainText("Cebu");
    await expect(contrasts).toContainText("median 55");
    await gaps.focus();
    await expect(gaps).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
