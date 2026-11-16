import { expect, test } from "@playwright/test";

for (const width of [390, 1440]) {
  test(`comparison measure details retain the map return at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    const mapState =
      "dataset=bhw-2025&indicator=bhw.active-years&level=region&code=07&selected=07306&view=choropleth&valueMin=12.34&classification=custom&classBreaks=10,20";
    await page.goto(
      `compare?${new URLSearchParams({ measure: "bhw.total", geos: "01,02", mapState })}`,
    );
    const returnLink = page.getByRole("link", { name: "Return to previous BHW map", exact: true });
    const original = await returnLink.getAttribute("href");
    const detail = page
      .locator('a[href*="/bhw/measure?"]')
      .filter({ hasText: /Open this measure/ });
    // Region I is present in the synthetic development seed, regardless of other regions' availability.
    const regionOne = detail.and(page.locator('a[href*="location=region%3A01"]'));
    await regionOne.click();
    await expect(page).toHaveURL(/bhw\/measure\?/);
    expect(new URL(page.url()).searchParams.get("location")).toBe("region:01");
    await page.reload();
    await expect(returnLink).toHaveAttribute("href", original!);
    await expect(
      page.getByText(
        "Measure figures use the selected place and indicator; map filters and color groups do not apply.",
      ),
    ).toBeVisible();
    const measureUrl = page.url();
    await page.getByRole("link", { name: /^All BHW indicators for / }).click();
    await expect(page.getByRole("heading", { name: "Dataset catalog", exact: true })).toBeVisible();
    expect(new URL(page.url()).searchParams.get("location")).toBe("region:01");
    expect(new URL(page.url()).searchParams.get("dataset")).toBe("bhw");
    await page.reload();
    await expect(returnLink).toHaveAttribute("href", original!);
    await expect(
      page.getByText("Catalog figures keep their own scope.", { exact: false }),
    ).toBeVisible();
    await page.goBack();
    await expect(page).toHaveURL(measureUrl);
    await expect(returnLink).toHaveAttribute("href", original!);
    await page
      .getByRole("link", { name: "Compare this measure with another place", exact: true })
      .click();
    await expect(
      page.getByRole("heading", { name: "Compare BHW places", exact: true }),
    ).toBeVisible();
    expect(new URL(page.url()).searchParams.get("geos")).toBe("01");
    await expect(returnLink).toHaveAttribute("href", original!);
    await page.goBack();
    await expect(page).toHaveURL(measureUrl);
    await page.getByRole("link", { name: "← BHW overview", exact: true }).click();
    await expect(
      page.getByRole("heading", { name: "Barangay Health Workers", exact: true }),
    ).toBeVisible();
    await expect(returnLink).toHaveAttribute("href", original!);
  });
}
