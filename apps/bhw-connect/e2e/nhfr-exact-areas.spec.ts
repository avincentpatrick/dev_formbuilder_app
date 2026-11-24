import { expect, test } from "@playwright/test";
import esbuild from "esbuild";
import { readFileSync, readdirSync } from "node:fs";
import { areaFixture, filteredAreaPresence } from "../lib/facilities/area-figures.fixtures";

// The committed dev copy has no complete parent NHFR cross-tab. Exercise the
// actual client component using generated public-aggregate fixtures; reader and
// export tests separately cover the complete server boundary and real files.
let bundle = "",
  css = "";
const { input: fixture, presence } = areaFixture();
test.beforeAll(async () => {
  const result = await esbuild.build({
    stdin: {
      contents: `import React from 'react';import {createRoot} from 'react-dom/client';import {DistributionCard} from './components/facilities/distribution-card';let input=${JSON.stringify(fixture)},presence=${JSON.stringify(presence)};const mode=new URLSearchParams(location.search).get('case');if(mode==='incomplete')presence={...presence,rows:presence.rows.slice(1)};if(mode==='zero'){input={...input,total:{count:0,government:0,private:0},areas:input.areas.map(a=>({...a,count:0,government:0,private:0})),types:[],cells:[]};presence={...presence,rows:presence.rows.map(p=>({...p,present:0}))};}createRoot(document.getElementById('root')).render(<DistributionCard input={input} presence={presence} selection={{mode:'psgc',level:'national',code:'PH'}} locationName="Synthetic parent" mapState="dataset=nhfr-2026-09&indicator=nhfr.facilities&level=region&code=01&selected=01028"/>);`,
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
    plugins: [
      {
        name: "fixture-link",
        setup(build) {
          build.onResolve({ filter: /^next\/link$/ }, () => ({
            path: "link",
            namespace: "fixture",
          }));
          build.onLoad({ filter: /.*/, namespace: "fixture" }, () => ({
            contents:
              "import React from 'react';export default function Link({href,children,...props}){return React.createElement('a',{...props,href},children)}",
            loader: "js",
            resolveDir: process.cwd(),
          }));
        },
      },
    ],
  });
  bundle = result.outputFiles[0].text;
  css = readdirSync(".next/static/chunks")
    .filter((f) => f.endsWith(".css"))
    .map((f) => readFileSync(`.next/static/chunks/${f}`, "utf8"))
    .join("\n");
});
async function open(page: import("@playwright/test").Page, mode = "") {
  await page.route("**/__areas-fixture*", (route) =>
    route.fulfill({
      contentType: "text/html",
      body: `<meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}</style><div id="root" style="font-family:system-ui,sans-serif;max-width:1104px;margin:auto;padding:16px"></div><script>${bundle.replaceAll("</script", "<\\/script")}</script>`,
    }),
  );
  await page.goto(`__areas-fixture${mode ? `?case=${mode}` : ""}`);
  return page.getByTestId("facility-distribution-card");
}
async function mockPresence(page: import("@playwright/test").Page) {
  await page.route("**/api/facilities/presence?*", (route) =>
    route.fulfill({
      json: filteredAreaPresence(new URL(route.request().url()).searchParams.getAll("type")),
    }),
  );
}
for (const width of [320, 390, 768, 1440])
  test(`exact area figures keep selections, two denominators and responsive state at ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
    await mockPresence(page);
    const card = await open(page);
    const panel = card
      .locator("details")
      .filter({ has: page.getByText("View exact area figures", { exact: true }) });
    await expect(panel).not.toHaveAttribute("open", "");
    await card
      .locator("summary")
      .filter({ hasText: /^Areas:/ })
      .click();
    await card.getByRole("checkbox", { name: "Area 01", exact: true }).check();
    await card.getByRole("checkbox", { name: "Area 02", exact: true }).check();
    await panel.locator("summary").click();
    const figures = panel.getByRole("region", { name: "Exact area figures", exact: true });
    await expect(figures).toContainText("323 facilities");
    await expect(figures).toContainText("7 of 30 barangays");
    await card
      .locator("summary")
      .filter({ hasText: /^Facility types:/ })
      .click();
    await card.getByRole("checkbox", { name: "Hospital", exact: true }).check();
    await expect(figures).toContainText("153 facilities");
    await expect(figures).toContainText("3 of 30 barangays");
    await card.getByRole("checkbox", { name: "Station", exact: true }).check();
    await expect(figures).toContainText("323 facilities");
    await expect(figures).toContainText("7 of 30 barangays");
    await panel.getByLabel("Order exact areas", { exact: true }).selectOption("presence-lowest");
    const visibleLinks = figures.getByRole("link");
    await expect(visibleLinks.first()).toHaveText("Area 02");
    await panel
      .getByRole("searchbox", { name: "Search exact areas by name", exact: true })
      .fill("Area 01");
    await expect(panel).toContainText("Showing 1 of 2 selected areas");
    await expect(figures).toContainText("3 of 10");
    await expect(figures).toContainText("30%");
    await expect(
      card.getByRole("list", { name: "Areas compared", exact: true }).getByRole("listitem"),
    ).toHaveCount(2);
    await panel
      .getByRole("checkbox", { name: "Show government/private counts", exact: true })
      .check();
    await card.getByRole("button", { name: "Maximize", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await expect(
      dialog.getByRole("searchbox", { name: "Search exact areas by name", exact: true }),
    ).toHaveValue("Area 01");
    await expect(dialog.getByLabel("Order exact areas", { exact: true })).toHaveValue(
      "presence-lowest",
    );
    await dialog.getByRole("button", { name: "Restore", exact: true }).click();
    await card.getByRole("button", { name: "Present", exact: true }).click();
    await expect(
      dialog.getByRole("region", { name: "Exact area figures", exact: true }),
    ).toContainText("323 facilities");
    await expect(dialog.getByRole("list", { name: "Areas compared", exact: true })).toHaveCount(0);
    expect(await dialog.evaluate((n) => n.scrollWidth <= n.clientWidth)).toBe(true);
    await page.keyboard.press("Escape");
    await card
      .locator("summary")
      .filter({ hasText: /^Export$/ })
      .click();
    for (const format of ["CSV", "XLSX", "SVG", "PNG", "PDF", "PPTX"]) {
      const href = await card.getByRole("link", { name: format, exact: true }).getAttribute("href");
      expect(href).toContain("/api/export/facilities/areas?");
      const query = new URL(href!, "http://local").searchParams;
      expect(query.getAll("type")).toEqual(["Hospital", "Station"]);
      expect(query.getAll("area")).toEqual(["01", "02"]);
      expect(query.get("q")).toBe("Area 01");
      expect(query.get("ownership")).toBe("1");
      expect(query.get("order")).toBe("presence-lowest");
    }
    await expect(figures.getByRole("link", { name: "Area 01", exact: true })).toHaveAttribute(
      "href",
      /location=region%3A01.*dataset=nhfr.*mapState=/,
    );
    await panel.getByRole("searchbox").fill("unmatched");
    await expect(figures).toContainText("No selected areas match");
    await expect(card.getByRole("button", { name: "Present", exact: true })).toBeDisabled();
    await expect(card.getByRole("link", { name: "CSV", exact: true })).toHaveCount(0);
    await panel.getByRole("button", { name: "Clear area search", exact: true }).click();
    await expect(panel).toContainText("Showing 2 of 2 selected areas");
    await panel.locator("summary").click();
    await card
      .locator("summary")
      .filter({ hasText: /^Export$/ })
      .click();
    await expect(card.getByRole("link", { name: "CSV", exact: true })).toHaveAttribute(
      "href",
      /facilities\/distribution\?/,
    );
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
test("all 18 areas appear once in exact presentation, including an unavailable barangay share", async ({
  page,
}) => {
  await page.setViewportSize({ width: 320, height: 900 });
  const card = await open(page);
  await card.getByText("View exact area figures", { exact: true }).click();
  await card.getByRole("button", { name: "Present", exact: true }).click();
  const dialog = page.getByRole("dialog");
  const names: string[] = [];
  for (let i = 0; i < 3; i++) {
    await expect(
      dialog.getByRole("region", { name: "Exact area figures", exact: true }),
    ).toContainText("323 facilities");
    names.push(
      ...(await dialog
        .getByRole("list", { name: "Exact areas", exact: true })
        .getByRole("link")
        .allTextContents()),
    );
    if (i < 2) await dialog.getByRole("button", { name: "Next areas", exact: true }).click();
  }
  expect(names).toHaveLength(18);
  expect(new Set(names).size).toBe(18);
  await expect(dialog).toContainText("share unavailable");
  expect(await dialog.evaluate((n) => n.scrollWidth <= n.clientWidth)).toBe(true);
});
test("pending, failed and superseded presence cannot expose stale combined values or files", async ({
  page,
}) => {
  let fail = true,
    started = false;
  await page.route("**/api/facilities/presence?*", async (route) => {
    const types = new URL(route.request().url()).searchParams.getAll("type");
    if (types.length === 1) {
      started = true;
      await new Promise((r) => setTimeout(r, 500));
    }
    await route
      .fulfill(
        fail
          ? { status: 503, json: { error: "Unavailable" } }
          : { json: filteredAreaPresence(types) },
      )
      .catch(() => {});
  });
  const card = await open(page);
  const panel = card
    .locator("details")
    .filter({ has: page.getByText("View exact area figures", { exact: true }) });
  await panel.locator("summary").click();
  await card
    .locator("summary")
    .filter({ hasText: /^Facility types:/ })
    .click();
  await card.getByRole("checkbox", { name: "Hospital", exact: true }).check();
  await expect(panel).toContainText("Updating exact area figures");
  await expect(panel.getByRole("region", { name: "Exact area figures", exact: true })).toHaveCount(
    0,
  );
  await expect(card.getByRole("link", { name: "CSV", exact: true })).toHaveCount(0);
  await expect(panel).toContainText("combined area figures could not be verified");
  fail = false;
  await panel.getByRole("button", { name: "Try exact figures again", exact: true }).click();
  await expect(
    panel.getByRole("region", { name: "Exact area figures", exact: true }),
  ).toContainText("153 facilities");
  started = false;
  await card.getByRole("checkbox", { name: "Hospital", exact: true }).uncheck();
  await card.getByRole("checkbox", { name: "Station", exact: true }).check();
  await expect.poll(() => started).toBe(true);
  await card.getByRole("checkbox", { name: "Hospital", exact: true }).check();
  await expect(
    panel.getByRole("region", { name: "Exact area figures", exact: true }),
  ).toContainText("323 facilities");
  await page.waitForTimeout(700);
  await expect(
    panel.getByRole("region", { name: "Exact area figures", exact: true }),
  ).toContainText("323 facilities");
});
test("incomplete rosters are unavailable and verified zero facilities keep barangay zero distinct", async ({
  page,
}) => {
  let card = await open(page, "incomplete");
  await card.getByText("View exact area figures", { exact: true }).click();
  await expect(card).toContainText("combined area figures could not be verified");
  await expect(card.getByRole("region", { name: "Exact area figures", exact: true })).toHaveCount(
    0,
  );
  await page.unroute("**/__areas-fixture*");
  card = await open(page, "zero");
  await card.getByText("View exact area figures", { exact: true }).click();
  const region = card.getByRole("region", { name: "Exact area figures", exact: true });
  await expect(region).toContainText("share unavailable");
  await expect(region).toContainText("0%");
});
