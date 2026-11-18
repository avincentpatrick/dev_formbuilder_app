import { expect, test } from "@playwright/test";
import { missingCebuShapes } from "./helpers/missing-cebu-shapes";
for (const width of [390, 1440]) {
  test(`peer position retains scope, ties and exclusions at ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 844 });
    await missingCebuShapes(page);
    await page.route("**/api/dataset-map?**", (route) =>
      route.fulfill({
        json: {
          state: "available",
          area: "Synthetic Region VII",
          childLevel: "province",
          source: "Synthetic peer fixture",
          date: "2025-01-01",
          license: "Test only",
          total: 999,
          navigation: { parents: [], complete: true },
          geometryUrl: "/bhw-connect/geo/provinces/07.json",
          rows: [
            { code: "07012", name: "Bohol", value: 0 },
            { code: "07022", name: "Cebu", value: 50 },
            { code: "07306", name: "Cebu City", value: 50 },
            { code: "07311", name: "Lapu-Lapu", value: null },
            { code: "07313", name: "Mandaue", value: 100, smallSample: true },
          ],
        },
      }),
    );
    await page.goto("map?dataset=bhw-stepzero-2026&level=region&code=07");
    await expect(page.locator('[data-map-state="ready"]')).toBeVisible();
    const openData = async () => {
      if (width < 1024)
        await page.getByRole("button", { name: "Areas and data", exact: true }).click();
    };
    await openData();
    const peers = page.getByRole("region", { name: "Locality position among peers", exact: true });
    await expect(peers).toContainText("3 of 5 listed province-level areas are eligible");
    await expect(peers).toContainText(
      "1 without a usable public value, 1 flagged as small samples, 0 outside",
    );
    const ranks = peers.getByRole("region", { name: "Peer ranks and exact values" });
    await expect(ranks.getByRole("row")).toHaveCount(4);
    await ranks.getByRole("button", { name: "Cebu City (07306)", exact: true }).click();
    await expect(peers.getByRole("status")).toContainText(
      "Cebu City ranks 1st of 3 province-level areas in Synthetic Region VII",
    );
    await expect(peers.getByRole("status")).toContainText("2 places share this rank");
    await expect(page).toHaveURL(/selected=07306/);
    await page.reload();
    await openData();
    await expect(
      ranks.getByRole("button", { name: "Cebu City (07306)", exact: true }),
    ).toHaveAttribute("aria-pressed", "true");
    await ranks.getByRole("button", { name: "Bohol (07012)", exact: true }).click();
    await expect(peers.getByRole("status")).toContainText("Bohol ranks 3rd");
    await page.goBack();
    await expect(peers.getByRole("status")).toContainText("Cebu City ranks 1st");
    await page.getByText("Filter workforce counts", { exact: true }).click();
    await page.getByLabel("Minimum count", { exact: true }).fill("60");
    await page.getByRole("button", { name: "Apply count range", exact: true }).click();
    await expect(peers).toContainText("0 of 5 listed province-level areas are eligible");
    await expect(peers.getByRole("status")).toContainText("outside the chosen value range");
    await expect(peers.getByRole("img")).toHaveCount(0);
    await page.getByText("Filter workforce counts", { exact: true }).click();
    await page.getByRole("button", { name: "Reset count range", exact: true }).click();
    await expect(ranks.getByRole("row")).toHaveCount(4);
    await ranks.focus();
    await expect(ranks).toBeFocused();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    await expect(page.getByText(/Reported area total: 999/)).toBeVisible();
  });
}
