import { expect, test } from "@playwright/test";

for (const width of [390, 1440]) {
  test(`search retains the original map across analysis destinations at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
    const mapState =
      "dataset=bhw-2025&indicator=bhw.active-years&level=region&code=07&selected=07306&valueMin=12.34";
    await page.goto(`bhw?${new URLSearchParams({ mapState })}`);
    const back = page.getByRole("link", { name: "Return to previous BHW map", exact: true });
    const original = await back.getAttribute("href");
    const search = page.getByRole("searchbox", { name: "Search BHW places and indicators" });
    await search.fill("01 headcount");
    await search.press("Enter");
    await expect(page.getByRole("region", { name: "Search results" })).toBeVisible();
    await page.reload();
    await expect(back).toHaveAttribute("href", original!);
    await page.getByRole("link", { name: "Total BHWs (LGU-reported)", exact: true }).click();
    await expect(page).toHaveURL(/location=region%3A01/);
    await expect(back).toHaveAttribute("href", original!);
    await search.fill("01");
    await search.press("Enter");
    await page.getByRole("link", { name: /^View BHW indicators for / }).click();
    await expect(page.getByRole("heading", { name: "Dataset catalog", exact: true })).toBeVisible();
    await expect(back).toHaveAttribute("href", original!);
    await page.goBack();
    await search.fill("Compare 01 and 02 headcount");
    await search.press("Enter");
    await expect(
      page.getByRole("heading", { name: "Resolve comparison", exact: true }),
    ).toBeVisible();
    await page.reload();
    await expect(back).toHaveAttribute("href", original!);
    await page
      .getByRole("combobox", { name: "Choose match for 01", exact: true })
      .selectOption("01");
    await page
      .getByRole("combobox", { name: "Choose match for 02", exact: true })
      .selectOption("02");
    await page.getByRole("button", { name: "Open comparison", exact: true }).click();
    await expect(
      page.getByRole("heading", { name: "Compare BHW places", exact: true }),
    ).toBeVisible();
    expect(new URL(page.url()).searchParams.get("geos")).toBe("01,02");
    await expect(back).toHaveAttribute("href", original!);
  });
}
