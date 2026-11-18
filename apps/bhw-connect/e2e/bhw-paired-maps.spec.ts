import { expect, test } from "@playwright/test";

for (const width of [390, 1440]) {
  test(`paired maps share breaks and retain scope through navigation at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 844 });
    let failB = false;
    await page.route("**/api/dataset-map?**", async (route) => {
      const p = new URL(route.request().url()).searchParams;
      const code = p.get("code")!;
      const sideB = code.startsWith("02");
      if (failB && sideB) return route.fulfill({ status: 503, json: { state: "failed" } });
      const province = p.get("level") === "province";
      const percent = p.get("indicator") === "bhw.any-honorarium";
      const values = sideB ? [10, percent ? 100 : 1000] : [0, null, 30];
      const codes = province
        ? sideB
          ? ["0201501", "0201502"]
          : ["0102801", "0102802", "0199999"]
        : sideB
          ? ["02009", "02015"]
          : ["01028", "01029", "01999"];
      const prefix = new URL(route.request().url()).pathname.split("/api/")[0];
      await route.fulfill({
        json: {
          state: "available",
          area: `Synthetic ${sideB ? "B" : "A"}`,
          source: "Synthetic comparison fixture",
          date: "2025-01-01",
          license: "Test only",
          total: null,
          childLevel: province ? "citymun" : "province",
          barangayDrill: false,
          geometryUrl: `${prefix}/geo/${province ? "citymun" : "provinces"}/${code}.json`,
          navigation: {
            complete: true,
            parents: province
              ? [{ level: "region", code: sideB ? "02" : "01", name: "Synthetic parent" }]
              : [],
          },
          rows: codes.map((c, i) => ({
            code: c,
            name: `Synthetic ${sideB ? "B" : "A"} ${i + 1}`,
            value: values[i],
          })),
        },
      });
    });
    await page.goto("compare?measure=bhw.total&geos=01,02&focus=02");
    await page.getByRole("button", { name: "Open paired maps" }).click();
    await expect(
      page.getByRole("heading", { name: "Compare BHW maps", exact: true }),
    ).toBeVisible();
    const panel = (side: string) =>
      page.getByRole("region", { name: `Map ${side} comparison`, exact: true });
    const show = async (side: string) => {
      if (width < 768) await page.getByRole("button", { name: `Map ${side}`, exact: true }).click();
    };
    await expect(page.getByRole("region", { name: "Shared comparison legend" })).toHaveCount(1);
    await expect(panel("A").getByRole("cell", { name: "Not reported", exact: true })).toBeVisible();
    await expect(
      panel("A").getByText("1 of 3 areas lack a matching shape.", { exact: false }),
    ).toBeVisible();
    await expect(panel("A").locator('[data-map-state="ready"]')).toBeVisible();
    await panel("A").getByRole("combobox").selectOption("01028");
    await expect(
      panel("A").getByRole("link", { name: "Open dashboard for Synthetic A 1" }),
    ).toHaveAttribute("href", /location=province%3A01028/);
    await show("B");
    await panel("B").getByRole("combobox").selectOption("02015");
    await expect(page.getByRole("button", { name: "Drill both selected areas" })).toBeEnabled();
    await page.reload();
    await expect(panel(width < 768 ? "B" : "A").getByRole("combobox")).toHaveValue(
      width < 768 ? "02015" : "01028",
    );
    await page.getByRole("button", { name: "Drill both selected areas" }).click();
    await expect(page).toHaveURL(/a=province%3A01028&b=province%3A02015/);
    await expect(page.getByRole("button", { name: "Parent areas on both maps" })).toBeEnabled();
    await page.getByRole("button", { name: "Parent areas on both maps" }).click();
    await expect(page).toHaveURL(/a=region%3A01&b=region%3A02/);
    await page.goBack();
    await expect(page).toHaveURL(/a=province%3A01028&b=province%3A02015/);
    await page
      .getByRole("combobox", { name: "Shared map measure" })
      .selectOption("bhw.any-honorarium");
    await expect(page.getByText("Fixed 0–100% groups", { exact: false })).toBeVisible();
    await expect(
      page.getByRole("link", { name: "Return to place comparison", exact: false }),
    ).toHaveAttribute("href", /geos=01%2C02&focus=02/);
    const table = panel(width < 768 ? "B" : "A").getByRole("region", { name: /Exact values/ });
    await table.focus();
    await expect(table).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    failB = true;
    await page.reload();
    await expect(page.getByText("Map B: failed.", { exact: false })).toBeVisible();
    await expect(panel("A")).toHaveCount(0);
    await expect(panel("B")).toHaveCount(0);
    failB = false;
    await page.getByRole("button", { name: "Retry both maps" }).click();
    await expect(panel(width < 768 ? "B" : "A")).toBeVisible();
    await page.goto("bhw/compare-maps?measure=bhw.total&a=region:01&b=province:02015");
    await expect(page.getByRole("heading", { name: "Invalid map comparison" })).toBeVisible();
    await expect(panel("A")).toHaveCount(0);
  });
}
