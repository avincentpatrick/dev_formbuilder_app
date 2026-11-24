import { expect, test } from "@playwright/test";
import { parse } from "csv-parse/sync";
const scope = "catalog?mode=psgc&location=citymun%3A0102801&dataset=nhfr";
for (const width of [320, 390, 768, 1440])
  test(`exact figures share selections, search independently and fit ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(scope);
    const card = page.getByTestId("facility-mix-card");
    const panel = card
      .locator("details")
      .filter({ has: page.getByText("View exact figures", { exact: true }) });
    await expect(panel).not.toHaveAttribute("open", "");
    await expect(page.getByRole("region", { name: "Facilities by type", exact: true })).toHaveCount(
      0,
    );
    await card.locator("summary").filter({ hasText: "Facility types:" }).click();
    const choices = card.getByRole("checkbox");
    const first = (await choices.nth(0).locator("..").innerText()).trim();
    await choices.nth(0).check();
    await choices.nth(1).check();
    await panel.locator("summary").click();
    const figures = panel.getByRole("region", { name: "Facilities by type", exact: true });
    await expect(figures).toContainText("2 (66.7%)");
    await expect(panel).toContainText("Showing 2 of 2 selected types");
    await card.getByLabel("Order types", { exact: true }).selectOption("lowest");
    await panel
      .getByRole("searchbox", { name: "Search exact figures by type name", exact: true })
      .fill(first);
    await expect(panel).toContainText("Showing 1 of 2 selected types");
    await expect(figures).toContainText("1 (33.3%)");
    await expect(
      card
        .getByRole("list", { name: "Facility types compared", exact: true })
        .getByRole("listitem"),
    ).toHaveCount(2);
    await card.getByRole("button", { name: "Maximize", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await expect(
      dialog.getByRole("searchbox", { name: "Search exact figures by type name", exact: true }),
    ).toHaveValue(first);
    await expect(dialog.getByLabel("Order types", { exact: true })).toHaveValue("lowest");
    await dialog.getByRole("button", { name: "Restore", exact: true }).click();
    await card.getByRole("button", { name: "Present", exact: true }).click();
    await expect(
      dialog.getByRole("region", { name: "Facilities by type", exact: true }),
    ).toBeVisible();
    await expect(
      dialog.getByRole("list", { name: "Facility types compared", exact: true }),
    ).toHaveCount(0);
    await expect(dialog).toContainText(`Exact-figure search: ${first}`);
    expect(await dialog.evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true);
    await page.keyboard.press("Escape");
    await card
      .locator("summary")
      .filter({ hasText: /^Export$/ })
      .click();
    for (const format of ["CSV", "XLSX", "SVG", "PNG", "PDF", "PPTX"]) {
      const href = await card.getByRole("link", { name: format, exact: true }).getAttribute("href");
      const params = new URL(href!, "http://local").searchParams;
      expect(params.get("view")).toBe("table");
      expect(params.get("q")).toBe(first);
      expect(params.getAll("type")).toHaveLength(2);
      expect(params.get("order")).toBe("lowest");
    }
    await panel.getByRole("searchbox").fill("no-matching-type");
    await expect(figures).toContainText("No selected facility types match this search");
    await expect(card.getByRole("link", { name: "CSV", exact: true })).toHaveCount(0);
    await expect(card.getByRole("button", { name: "Present", exact: true })).toBeDisabled();
    await panel.getByRole("button", { name: "Clear table search", exact: true }).click();
    await expect(panel).toContainText("Showing 2 of 2 selected types");
    await panel.locator("summary").click();
    await card
      .locator("summary")
      .filter({ hasText: /^Export$/ })
      .click();
    const chartHref = await card
      .getByRole("link", { name: "CSV", exact: true })
      .getAttribute("href");
    expect(new URL(chartHref!, "http://local").searchParams.get("view")).toBe("mix");
    expect(new URL(chartHref!, "http://local").searchParams.has("q")).toBe(false);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
test("table exports deliver all six formats for the same visible results", async ({
  page,
  request,
}) => {
  await page.goto(scope);
  const card = page.getByTestId("facility-mix-card");
  await card.getByText("View exact figures", { exact: true }).click();
  await card
    .getByRole("searchbox", { name: "Search exact figures by type name", exact: true })
    .fill("hospital");
  await card
    .locator("summary")
    .filter({ hasText: /^Export$/ })
    .click();
  for (const format of ["CSV", "XLSX", "SVG", "PNG", "PDF", "PPTX"]) {
    const href = await card.getByRole("link", { name: format, exact: true }).getAttribute("href");
    const response = await request.get(href!);
    expect(response.status()).toBe(200);
    const bytes = await response.body();
    expect(bytes.length).toBeGreaterThan(100);
    if (format === "CSV") {
      const rows = parse(bytes, { bom: true, columns: true }) as Record<string, string>[];
      expect(rows).toHaveLength(1);
      expect(rows[0]["Facility type"]).toBe("Hospital");
      expect(rows[0]["Area facilities"]).toBe("3");
      expect(Number(rows[0]["Share of all facilities (%)"])).toBe(100 / 3);
      expect(rows[0]["Exact-figure search"]).toBe("hospital");
    }
    if (format === "SVG") expect(bytes.toString()).toContain("Exact figures by facility type");
  }
});
test("long exact tables present every matching type once and retain the full subtotal", async ({
  page,
}) => {
  const { default: esbuild } = await import("esbuild");
  const { readFileSync, readdirSync } = await import("node:fs");
  const items = Array.from({ length: 18 }, (_, i) => ({
    facilityType: `Synthetic Type ${String(i + 1).padStart(2, "0")} with a long category name`,
    nFacilities: i + 1,
    nGovernment: i + 1,
    nPrivate: 0,
  }));
  const result = await esbuild.build({
    stdin: {
      contents: `import React from 'react';import {createRoot} from 'react-dom/client';import {FacilityMixCard} from './components/facilities/facility-mix-card';createRoot(document.getElementById('root')).render(<FacilityMixCard items={${JSON.stringify(items)}} total={171} selection={{mode:'psgc',level:'national',code:'PH'}} locationName="Synthetic parent"/>);`,
      resolveDir: process.cwd(),
      loader: "tsx",
    },
    bundle: true,
    write: false,
    platform: "browser",
    format: "iife",
    jsx: "automatic",
    tsconfig: "tsconfig.json",
    define: {
      "process.env.NODE_ENV": '"production"',
      "process.env.NEXT_PUBLIC_BASE_PATH": '"/bhw-connect"',
    },
  });
  const css = readdirSync(".next/static/chunks")
    .filter((f) => f.endsWith(".css"))
    .map((f) => readFileSync(`.next/static/chunks/${f}`, "utf8"))
    .join("\n");
  await page.route("**/__exact-pagination", (route) =>
    route.fulfill({
      contentType: "text/html",
      body: `<meta name="viewport" content="width=device-width,initial-scale=1"><style>${css}</style><div id="root" style="font-family:system-ui;padding:16px"></div><script>${result.outputFiles[0].text.replaceAll("</script", "<\\/script")}</script>`,
    }),
  );
  await page.setViewportSize({ width: 320, height: 900 });
  await page.goto("__exact-pagination");
  const card = page.getByTestId("facility-mix-card");
  await card.getByText("View exact figures", { exact: true }).click();
  await card.getByRole("button", { name: "Present", exact: true }).click();
  const dialog = page.getByRole("dialog");
  const names: string[] = [];
  for (let i = 0; i < 3; i++) {
    await expect(
      dialog.getByRole("region", { name: "Facilities by type", exact: true }),
    ).toContainText("171 (100%)");
    names.push(
      ...(await dialog
        .getByRole("list", { name: "Exact facility types", exact: true })
        .locator("li > p:first-child")
        .allTextContents()),
    );
    if (i < 2) await dialog.getByRole("button", { name: "Next types", exact: true }).click();
  }
  expect(names).toHaveLength(18);
  expect(new Set(names).size).toBe(18);
  expect(await dialog.evaluate((el) => el.scrollWidth <= el.clientWidth)).toBe(true);
});
