import type { Page } from "@playwright/test";

/** Keep missing-shape behavior covered explicitly after the real HUC assets are repaired. */
export async function missingCebuShapes(page: Page) {
  await page.route("**/geo/provinces/07.json", async (route) => {
    const response = await route.fetch();
    const data = await response.json();
    data.features = data.features.filter((feature: { properties: { geo_code: string } }) =>
      ["07012", "07022"].includes(feature.properties.geo_code),
    );
    await route.fulfill({ response, json: data });
  });
}
