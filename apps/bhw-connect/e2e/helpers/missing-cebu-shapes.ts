import type { Page } from "@playwright/test";
import { readFile } from "node:fs/promises";
import { join } from "node:path";

/** Keep missing-shape behavior covered explicitly after the real HUC assets are repaired. */
export async function missingCebuShapes(page: Page) {
  // Prepare the fixture before navigation: a route.fetch() can outlive test teardown.
  const data = JSON.parse(
    await readFile(join(process.cwd(), "public/geo/provinces/07.json"), "utf8"),
  );
  data.features = data.features.filter((feature: { properties: { geo_code: string } }) =>
    ["07012", "07022"].includes(feature.properties.geo_code),
  );
  await page.route("**/geo/provinces/07.json", (route) => route.fulfill({ json: data }));
}
