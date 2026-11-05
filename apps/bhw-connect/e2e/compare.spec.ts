import { test, expect } from "@playwright/test";

/**
 * Regression coverage for the /compare interactive flow. The page's controls
 * write filter state through nuqs; the server component reads it back through
 * `loadFilterState`. These went through different URL keys once
 * (`?compareGeos=` written, `?geos=` read), which made every add/remove
 * control a no-op while permalinks kept working — invisible to unit tests and
 * to any verification done via hand-built URLs. This spec drives the real
 * click path end to end.
 */
test("compare: quick-add two regions, see head-to-head, remove one", async ({ page }) => {
  await page.goto("compare");

  // Empty state offers region quick-add chips.
  await expect(page.getByText("Start by comparing regions")).toBeVisible();
  await page.getByRole("button", { name: "+ REGION IV-A (CALABARZON)" }).click();

  // The URL must use the server's param name, never the state key.
  await page.waitForURL(/[?&]geos=/);
  expect(page.url()).not.toContain("compareGeos");

  // One place selected -> same-level peer suggestions; add the top peer.
  await expect(page.getByText(/Compare REGION IV-A \(CALABARZON\) with a peer region/)).toBeVisible();
  await page.getByRole("button", { name: /^\+ REGION/ }).first().click();

  // Two same-level places -> the comparison actually renders: one column per place. It does not look for
  // "Head to head", which needs two places with a value: this runs on the committed made-up database, where
  // only one region has figures, so the strip correctly hides itself there.
  await page.waitForURL(/[?&]geos=[^&]*(,|%2C)/);
  await expect(page.getByRole("heading", { name: "Accreditation" })).toHaveCount(2);

  // Removing via a selection chip round-trips through the URL too.
  await page
    .getByRole("button", { name: /^Remove REGION IV-A \(CALABARZON\)/ })
    .first()
    .click();
  await expect(page.getByText(/is added — search above or pick a suggestion/)).toBeVisible();
});
