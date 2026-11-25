import { expect, test } from "@playwright/test";
import { parse } from "csv-parse/sync";
import { unzipSync, strFromU8 } from "fflate";
const scope = "catalog?mode=psgc&location=citymun%3A0102801&dataset=nhfr";
for (const width of [320, 390, 768, 1440])
  test(`full-location report stays unfiltered and fits ${width}px`, async ({ page }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(scope);
    const card = page.getByTestId("facility-report-downloads");
    await expect(card).toContainText("All facility types. Individual card filters do not apply.");
    await expect(card.getByRole("link")).toHaveCount(0);
    const mix = page.getByTestId("facility-mix-card");
    await mix
      .locator("summary")
      .filter({ hasText: /^Facility types:/ })
      .click();
    await mix.getByRole("checkbox").first().check();
    await expect(mix).toContainText("1 of 3 registered facilities");
    await card.getByText("Choose report downloads", { exact: true }).click();
    await expect(card).toContainText("Verified: 3 facilities across 3 recorded types");
    await card
      .getByRole("checkbox", {
        name: "Include technical notes in PDF, slides and image pages",
        exact: true,
      })
      .check();
    for (const name of [
      "Download report (PDF)",
      "Presentation (PPTX)",
      "PNG pages (.zip)",
      "SVG pages (.zip)",
      "Aggregate tables (XLSX)",
      "Aggregate tables (CSV)",
    ]) {
      const href = await card.getByRole("link", { name, exact: true }).getAttribute("href");
      const params = new URL(href!, "http://local").searchParams;
      expect(params.get("geoLevel")).toBe("citymun");
      expect(params.get("geoCode")).toBe("0102801");
      expect(params.get("notes")).toBe("1");
      expect([...params.keys()].sort()).toEqual(["format", "geoCode", "geoLevel", "notes"]);
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
test("downloads all six real formats with full-city values and optional technical appendix", async ({
  page,
  request,
}) => {
  await page.goto(scope);
  const card = page.getByTestId("facility-report-downloads");
  await card.getByText("Choose report downloads", { exact: true }).click();
  await expect(card).toContainText("Verified:");
  for (const name of [
    "Download report (PDF)",
    "Presentation (PPTX)",
    "PNG pages (.zip)",
    "SVG pages (.zip)",
    "Aggregate tables (XLSX)",
    "Aggregate tables (CSV)",
  ]) {
    const href = await card.getByRole("link", { name, exact: true }).getAttribute("href");
    const response = await request.get(href!);
    expect(response.status()).toBe(200);
    const bytes = await response.body();
    expect(bytes.length).toBeGreaterThan(100);
    if (name.includes("CSV")) {
      const rows = parse(bytes, { bom: true, columns: true }) as Record<string, string>[];
      expect(rows.filter((r) => r.Table === "Facility types")).toHaveLength(3);
      expect(rows.find((r) => r.Indicator === "Registered facilities")?.Value).toBe("3");
      expect(rows.every((r) => r["Location code"] === "0102801")).toBe(true);
    }
    if (name.includes("SVG")) {
      const files = unzipSync(bytes);
      expect(Object.keys(files)).toHaveLength(2);
      const body = Object.values(files)
        .map((f) => strFromU8(f))
        .join(" ");
      expect(body).not.toContain("About these figures");
      expect(body).toContain("All facility types. Individual card filters do not apply.");
    }
    if (name.includes("PNG")) {
      const files = unzipSync(bytes);
      expect(Object.keys(files)).toHaveLength(2);
      expect([...Object.values(files)[0].slice(0, 4)]).toEqual([137, 80, 78, 71]);
    }
  }
  await card.getByRole("checkbox").check();
  const response = await request.get(
    (await card.getByRole("link", { name: "SVG pages (.zip)", exact: true }).getAttribute("href"))!,
  );
  const files = unzipSync(await response.body());
  expect(Object.keys(files).length).toBeGreaterThan(2);
  expect(
    Object.values(files)
      .map((f) => strFromU8(f))
      .join(" "),
  ).toContain("About these figures");
});
test("failed verification and a wrong-scope response cannot expose report links, with retry recovery", async ({
  page,
}) => {
  let mode = "fail";
  await page.route("**/api/facilities/report?*", (route) =>
    route.fulfill(
      mode === "fail"
        ? { status: 503, json: { error: "Unavailable" } }
        : {
            json: {
              code: mode === "wrong" ? "0102802" : "0102801",
              level: "citymun",
              name: "Synthetic",
              facilities: 3,
              types: 3,
              areas: 0,
            },
          },
    ),
  );
  await page.goto(scope);
  const card = page.getByTestId("facility-report-downloads");
  await card.getByText("Choose report downloads", { exact: true }).click();
  await expect(card).toContainText("not a reported zero");
  await expect(card.getByRole("link")).toHaveCount(0);
  mode = "wrong";
  await card.getByRole("button", { name: "Try report again", exact: true }).click();
  await expect(card).toContainText("not a reported zero");
  await expect(card.getByRole("link")).toHaveCount(0);
  mode = "ready";
  await card.getByRole("button", { name: "Try report again", exact: true }).click();
  await expect(card).toContainText("Verified: 3 facilities");
  await expect(card.getByRole("link")).toHaveCount(6);
});
