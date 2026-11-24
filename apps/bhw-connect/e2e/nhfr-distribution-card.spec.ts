import { expect, test } from "@playwright/test";
import esbuild from "esbuild";
import { readFileSync, readdirSync } from "node:fs";
import type { DistributionInput } from "../lib/facilities/distribution";

// The committed dev copy has no complete parent NHFR cross-tab. Exercise the
// actual client component using generated public-aggregate fixtures; reader and
// export tests separately cover the complete server boundary and real files.
let bundle = "",
  css = "";
const fixture: DistributionInput = {
  code: "PH",
  level: "national",
  name: "Synthetic parent",
  total: { count: 0, government: 0, private: 0 },
  areas: [],
  types: [],
  cells: [],
};
for (let i = 0; i < 18; i++) {
  const code = String(i + 1).padStart(2, "0"),
    hospital = i === 17 ? 0 : i + 1,
    clinic = i === 17 ? 0 : 18 - i,
    government = Math.floor(hospital / 2) + clinic,
    privateCount = hospital - Math.floor(hospital / 2);
  fixture.areas.push({
    code,
    name: `Area ${code}`,
    level: "region",
    count: hospital + clinic,
    government,
    private: privateCount,
  });
  if (hospital)
    fixture.cells.push({
      code,
      type: "Hospital",
      count: hospital,
      government: Math.floor(hospital / 2),
      private: privateCount,
    });
  if (clinic)
    fixture.cells.push({ code, type: "Clinic", count: clinic, government: clinic, private: 0 });
  fixture.total.count += hospital + clinic;
  fixture.total.government += government;
  fixture.total.private += privateCount;
}
for (const type of ["Clinic", "Hospital"]) {
  const cells = fixture.cells.filter((c) => c.type === type);
  fixture.types.push({
    type,
    count: cells.reduce((s, c) => s + c.count, 0),
    government: cells.reduce((s, c) => s + c.government, 0),
    private: cells.reduce((s, c) => s + c.private, 0),
  });
}
test.beforeAll(async () => {
  const result = await esbuild.build({
    stdin: {
      contents: `import React from 'react';import {createRoot} from 'react-dom/client';import {DistributionCard} from './components/facilities/distribution-card';let input=${JSON.stringify(fixture)};const mode=new URLSearchParams(location.search).get('case');if(mode==='incomplete')input.cells.pop();if(mode==='zero'){input={...input,total:{count:0,government:0,private:0},areas:input.areas.map(a=>({...a,count:0,government:0,private:0})),types:[],cells:[]};}createRoot(document.getElementById('root')).render(<DistributionCard input={input} selection={{mode:'psgc',level:'national',code:'PH'}} locationName="Synthetic parent" mapState="dataset=nhfr-2026-09&indicator=nhfr.facilities&level=region&code=01&selected=01028"/>);`,
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
  await page.route("**/__distribution-fixture*", (route) =>
    route.fulfill({
      contentType: "text/html",
      body: `<meta name="viewport" content="width=device-width, initial-scale=1"><style>${css}</style><div id="root" style="font-family:system-ui,sans-serif;max-width:1104px;margin:auto;padding:16px"></div><script>${bundle.replaceAll("</script", "<\\/script")}</script>`,
    }),
  );
  await page.goto(`__distribution-fixture${mode ? `?case=${mode}` : ""}`);
  return page.getByTestId("facility-distribution-card");
}
for (const width of [320, 390, 768, 1440])
  test(`geographic filters, fixed denominators and viewing modes fit ${width}px`, async ({
    page,
  }) => {
    await page.setViewportSize({ width, height: 900 });
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
    await card.getByRole("checkbox", { name: "Clinic", exact: true }).check();
    await expect(card).toContainText("38 of 323 facilities");
    await card.getByRole("checkbox", { name: "Clinic", exact: true }).uncheck();
    await expect(card).toContainText("3 of 153 facilities");
    await expect(card).toContainText("0–17 facilities");
    await expect(card.getByRole("listitem")).toHaveCount(2);
    await card.getByLabel("Order areas", { exact: true }).selectOption("lowest");
    await expect(card.getByRole("listitem").first()).toContainText("Area 01");
    await card.getByRole("button", { name: "Maximize", exact: true }).click();
    const dialog = page.getByRole("dialog");
    await expect(dialog).toContainText("3 of 153 facilities");
    await expect(dialog.getByLabel("Order areas", { exact: true })).toHaveValue("lowest");
    await dialog.getByRole("button", { name: "Restore", exact: true }).click();
    await card.getByRole("button", { name: "Present", exact: true }).click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByText("About this figure", { exact: true })).toHaveCount(0);
    expect(await dialog.evaluate((n) => n.scrollWidth <= n.clientWidth)).toBe(true);
    await page.keyboard.press("Escape");
    const link = card.getByRole("link", { name: "Area 01", exact: true });
    await expect(link).toHaveAttribute("href", /location=region%3A01.*dataset=nhfr.*mapState=/);
    await card
      .locator("summary")
      .filter({ hasText: /^Export$/ })
      .click();
    for (const format of ["CSV", "XLSX", "SVG", "PNG", "PDF", "PPTX"]) {
      const href = await card.getByRole("link", { name: format, exact: true }).getAttribute("href");
      const query = new URL(href!, "http://local").searchParams;
      expect(query.getAll("area")).toEqual(["01", "02"]);
      expect(query.getAll("type")).toEqual(["Hospital"]);
      expect(query.get("order")).toBe("lowest");
    }
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
  });
test("all areas are presented exactly once and zero areas remain visible", async ({ page }) => {
  const card = await open(page);
  await card.getByRole("button", { name: "Present", exact: true }).click();
  const dialog = page.getByRole("dialog");
  const names: string[] = [];
  for (let i = 0; i < 3; i++) {
    names.push(...(await dialog.getByRole("listitem").locator("a").allTextContents()));
    if (i < 2) await dialog.getByRole("button", { name: "Next areas", exact: true }).click();
  }
  expect(names).toHaveLength(18);
  expect(new Set(names).size).toBe(18);
  await expect(dialog).toContainText("Area 18");
  await expect(dialog).toContainText("0 (0%)");
});
test("incomplete data is unavailable and a verified zero denominator never becomes a zero share", async ({
  page,
}) => {
  let card = await open(page, "incomplete");
  await expect(card).toContainText("No ranking is shown");
  await expect(card.getByRole("button", { name: "Maximize", exact: true })).toHaveCount(0);
  await page.unroute("**/__distribution-fixture*");
  card = await open(page, "zero");
  await expect(card).toContainText("No facilities in this selection");
  await expect(card).toContainText("share unavailable");
  await expect(card.getByRole("list", { name: "Areas compared", exact: true })).not.toContainText(
    "0%",
  );
});
