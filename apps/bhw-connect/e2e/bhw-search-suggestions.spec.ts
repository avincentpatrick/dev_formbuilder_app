import { expect, test } from "@playwright/test";
for (const width of [390, 1440]) {
  test(`typing offers suggestions and selection works at ${width}px`, async ({ browser }) => {
    const context = await browser.newContext({
      viewport: { width, height: 844 },
      hasTouch: width < 1024,
    });
    const page = await context.newPage();
    await page.route("**/api/bhw/suggestions?**", async (route) => {
      const q = new URL(route.request().url()).searchParams.get("q");
      if (q === "fail") return route.fulfill({ status: 503, body: "Unavailable" });
      await route.fulfill({
        json: {
          suggestions: [
            {
              label: "Manila",
              detail: "City/Municipality · 1339000 · Metro Manila",
              href: "/bhw/measure?indicator=bhw.total&location=citymun%3A1339000",
            },
          ],
          placesFailed: false,
        },
      });
    });
    await page.goto("bhw");
    const input = page.getByRole("combobox", { name: "Search BHW places and indicators" });
    await input.fill("man");
    const option = page.getByRole("option", { name: /Manila.*Metro Manila/ });
    await expect(option).toBeVisible();
    await expect(input).toHaveAttribute("aria-expanded", "true");
    await input.press("Escape");
    await expect(option).not.toBeVisible();
    await input.press("ArrowDown");
    await expect(option).toHaveAttribute("aria-selected", "true");
    if (width < 1024) await option.tap();
    else await input.press("Enter");
    await expect(page).toHaveURL(/indicator=bhw.total.*1339000/);
    await page.goto("bhw");
    await input.fill("fail");
    await expect(
      page.getByRole("status").filter({ hasText: "Place suggestions are unavailable" }),
    ).toBeVisible();
    await expect(page.getByRole("button", { name: "Search", exact: true })).toBeEnabled();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
      true,
    );
    await context.close();
  });
}

test("partial indicator suggestions use the existing definitions and retain map context", async ({
  page,
}) => {
  await page.goto(
    `bhw?${new URLSearchParams({ mapState: "dataset=bhw-stepzero-2026&level=region&code=01" })}`,
  );
  const input = page.getByRole("combobox", { name: "Search BHW places and indicators" });
  await input.fill("headc");
  const option = page
    .getByRole("listbox", { name: "BHW search suggestions" })
    .getByRole("option")
    .filter({ hasText: "Total BHWs (LGU-reported)" });
  await expect(option).toBeVisible();
  await expect(option).toHaveAttribute("href", /mapState=/);
  await input.fill("x");
  await expect(page.getByRole("listbox", { name: "BHW search suggestions" })).not.toBeVisible();
});

test("late suggestions cannot replace a newer query", async ({ page }) => {
  await page.route("**/api/bhw/suggestions?**", async (route) => {
    const q = new URL(route.request().url()).searchParams.get("q")!;
    if (q === "Old place") await new Promise((resolve) => setTimeout(resolve, 900));
    await route
      .fulfill({
        json: {
          suggestions: [{ label: q, detail: "Synthetic place", href: "/bhw" }],
          placesFailed: false,
        },
      })
      .catch(() => {});
  });
  await page.goto("bhw");
  const input = page.getByRole("combobox", { name: "Search BHW places and indicators" });
  const old = page.waitForRequest((request) => request.url().includes("q=Old+place"));
  await input.fill("Old place");
  await old;
  await input.fill("New place");
  await expect(page.getByRole("option", { name: /New place/ })).toBeVisible();
  await page.waitForTimeout(1000);
  await expect(page.getByRole("option", { name: /Old place/ })).toHaveCount(0);
  await expect(page.getByRole("option", { name: /New place/ })).toBeVisible();
});
