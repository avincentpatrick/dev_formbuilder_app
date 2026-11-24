import { expect, test } from "@playwright/test";
import esbuild from "esbuild";
import { readFileSync, readdirSync } from "node:fs";
import type { PresenceInput } from "../lib/facilities/presence";

// The committed dev copy has no complete parent NHFR cross-tab. Exercise the
// actual client component using generated public-aggregate fixtures; reader and
// export tests separately cover the complete server boundary and real files.
let bundle = "",
  css = "";
const fixture: PresenceInput = {
  code: "PH",
  name: "Synthetic parent",
  level: "national",
  types: ["Hospital", "Station"],
  selectedTypes: [],
  rows: Array.from({ length: 18 }, (_, i) => ({
    code: String(i + 1).padStart(2, "0"),
    name: `Area ${String(i + 1).padStart(2, "0")}`,
    level: "region",
    present: i >= 16 ? 0 : i + 5,
    barangays: i === 17 ? 0 : 100,
  })),
};
test.beforeAll(async () => {
  const result = await esbuild.build({
    stdin: {
      contents: `import React from 'react';import {createRoot} from 'react-dom/client';import {PresenceCard} from './components/facilities/presence-card';let input=${JSON.stringify(fixture)};const mode=new URLSearchParams(location.search).get('case');if(mode==='incomplete')input=null;if(mode==='zero'){input={...input,rows:input.rows.map(a=>({...a,present:0,barangays:0}))};}createRoot(document.getElementById('root')).render(<PresenceCard input={input} selection={{mode:'psgc',level:'national',code:'PH'}} locationName="Synthetic parent" mapState="dataset=nhfr-2026-09&indicator=nhfr.facilities&level=region&code=01&selected=01028"/>);`,
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
  await page.route("**/__presence-fixture*", (route) =>
    route.fulfill({
      contentType: "text/html",
      body: `<meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}</style><div id="root" style="font-family:system-ui,sans-serif;max-width:1104px;margin:auto;padding:16px"></div><script>${bundle.replaceAll("</script", "<\\/script")}</script>`,
    }),
  );
  await page.goto(`__presence-fixture${mode ? `?case=${mode}` : ""}`);
  return page.getByTestId("facility-presence-card");
}
for (const width of [320, 390, 768, 1440])
  test(`presence multi-selections, fixed per-area denominators and views fit ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
    await mockSummary(page);
    const card = await open(page);
    await card
      .locator("summary")
      .filter({ hasText: /^Areas:/ })
      .click();
    await card.getByRole("checkbox", { name: "Area 01", exact: true }).check();
    await card.getByRole("checkbox", { name: "Area 02", exact: true }).check();
    await card
      .locator("summary")
      .filter({ hasText: /^Facility types:/ })
      .click();
    await card.getByRole("checkbox", { name: "Hospital", exact: true }).check();
    await card.getByRole("checkbox", { name: "Station", exact: true }).check();
    await expect(card).toContainText("11 of 200 barangays");
    await expect(card.getByRole("listitem").first()).toContainText("5% · 5 of 100 barangays");
    await card.getByRole("checkbox", { name: "Station", exact: true }).uncheck();
    await expect(card).toContainText("3 of 200 barangays");
    await expect(card.getByRole("listitem")).toHaveCount(2);
    await card.getByLabel("Order areas", { exact: true }).selectOption("highest");
    await expect(card.getByRole("listitem").first()).toContainText("Area 02");
    await card.getByRole("button", { name: "Maximize", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await expect(dialog).toContainText("3 of 200 barangays");
    await expect(dialog.getByLabel("Order areas", { exact: true })).toHaveValue("highest");
    await dialog.getByRole("button", { name: "Restore", exact: true }).click();
    await card.getByRole("button", { name: "Present", exact: true }).click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByText("About this figure", { exact: true })).toHaveCount(0);
    expect(await dialog.evaluate((n) => n.scrollWidth <= n.clientWidth)).toBe(true);
    await page.keyboard.press("Escape");
    await expect(card.getByRole("link", { name: "Area 01", exact: true })).toHaveAttribute(
      "href",
      /location=region%3A01.*dataset=nhfr.*mapState=/,
    );
    await card
      .locator("summary")
      .filter({ hasText: /^Export$/ })
      .click();
    for (const format of ["CSV", "XLSX", "SVG", "PNG", "PDF", "PPTX"]) {
      const href = await card.getByRole("link", { name: format, exact: true }).getAttribute("href");
      const query = new URL(href!, "http://local").searchParams;
      expect(query.getAll("area")).toEqual(["01", "02"]);
      expect(query.getAll("type")).toEqual(["Hospital"]);
      expect(query.get("order")).toBe("highest");
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
async function mockSummary(page: import("@playwright/test").Page) {
  await page.route("**/api/facilities/presence?*", async (route) => {
    const types = new URL(route.request().url()).searchParams.getAll("type");
    const data = {
      ...fixture,
      selectedTypes: types,
      rows: fixture.rows.map((a, i) => ({
        ...a,
        present: i >= 16 ? 0 : types.includes("Station") ? i + 5 : i + 1,
      })),
    };
    await route.fulfill({ json: data });
  });
}
test("all 18 areas appear once in presentation, with zero and unavailable denominators distinct", async ({
  page,
}) => {
  const card = await open(page);
  await card.getByRole("button", { name: "Present", exact: true }).click();
  const dialog = page.getByRole("dialog");
  const names: string[] = [];
  let text = "";
  for (let i = 0; i < 3; i++) {
    names.push(...(await dialog.getByRole("listitem").locator("a").allTextContents()));
    text += await dialog.innerText();
    if (i < 2) await dialog.getByRole("button", { name: "Next areas", exact: true }).click();
  }
  expect(names).toHaveLength(18);
  expect(new Set(names).size).toBe(18);
  expect(text).toContain("0% · 0 of 100 barangays");
  expect(text).toMatch(/Percentage unavailable ·\s*0 of 0 barangays/);
});
test("failed filtering hides stale figures and downloads, then retries without losing selection", async ({
  page,
}) => {
  let fail = true;
  await page.route("**/api/facilities/presence?*", async (route) => {
    await new Promise((r) => setTimeout(r, 300));
    await route.fulfill(
      fail
        ? { status: 503, json: { error: "Unavailable" } }
        : { json: { ...fixture, selectedTypes: ["Hospital"] } },
    );
  });
  const card = await open(page);
  await card
    .locator("summary")
    .filter({ hasText: /^Facility types:/ })
    .click();
  await card.getByRole("checkbox", { name: "Hospital", exact: true }).check();
  await expect(card).toContainText("Updating barangay presence");
  await expect(card.getByRole("listitem")).toHaveCount(0);
  await expect(card.getByText("Export", { exact: true })).toHaveCount(0);
  await expect(card).toContainText("selected barangay presence could not be verified");
  fail = false;
  await card.getByRole("button", { name: "Try again", exact: true }).click();
  await expect(card.getByRole("listitem")).toHaveCount(8);
  await expect(card.getByRole("checkbox", { name: "Hospital", exact: true })).toBeChecked();
});
test("a superseded response cannot overwrite the latest OR selection", async ({ page }) => {
  let started = false;
  await page.route("**/api/facilities/presence?*", async (route) => {
    const types = new URL(route.request().url()).searchParams.getAll("type");
    if (types.length === 1) {
      started = true;
      await new Promise((r) => setTimeout(r, 800));
    }
    const data = {
      ...fixture,
      selectedTypes: types,
      rows: fixture.rows.map((a, i) => ({
        ...a,
        present: i >= 16 ? 0 : types.includes("Station") ? i + 5 : i + 1,
      })),
    };
    await route.fulfill({ json: data }).catch(() => {});
  });
  const card = await open(page);
  await card
    .locator("summary")
    .filter({ hasText: /^Facility types:/ })
    .click();
  await card.getByRole("checkbox", { name: "Hospital", exact: true }).check();
  await expect.poll(() => started).toBe(true);
  await card.getByRole("checkbox", { name: "Station", exact: true }).check();
  await expect(card.getByRole("listitem").filter({ hasText: "Area 01" })).toContainText(
    "5% · 5 of 100",
  );
  await page.waitForTimeout(900);
  await expect(card.getByRole("listitem").filter({ hasText: "Area 01" })).toContainText(
    "5% · 5 of 100",
  );
});
test("incomplete data and zero denominators never become 0%", async ({ page }) => {
  await page.setViewportSize({ width: 320, height: 900 });
  let card = await open(page, "incomplete");
  await expect(card).toContainText("could not be verified");
  await expect(card.getByRole("button", { name: "Maximize", exact: true })).toHaveCount(0);
  await page.unroute("**/__presence-fixture*");
  card = await open(page, "zero");
  await expect(card).toContainText("percentage cannot be shown");
  await expect(card).toContainText("Percentage unavailable");
  await expect(card.getByRole("list", { name: "Areas compared" })).not.toContainText("0%");
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
});
