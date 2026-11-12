import { test, expect } from "@playwright/test";

test("Ask the Data has no anonymous page or API answering path", async ({
  page,
  request,
  baseURL,
}) => {
  await page.goto("bhw");
  const link = page.getByRole("link", { name: "Ask the Data · approved users" });
  await expect(link).toBeVisible();
  await link.click();
  await expect(page).toHaveURL(/ask-the-data/);
  await expect(
    page.getByText("Sign in with your Meridian account", { exact: false }),
  ).toBeVisible();
  await expect(page.getByRole("textbox")).toHaveCount(0);
  for (const endpoint of ["api/ai/chat", "api/ai/assistant", "api/analysis/chat"]) {
    const response = await request.post(new URL(endpoint, baseURL!).toString(), {
      data: { messages: [{ role: "user", content: "How many BHWs?" }] },
    });
    expect(response.status()).toBe(401);
    expect(await response.json()).toEqual({ error: "Approved dashboard access required." });
  }
  await page.goto("ask-the-data/source/1");
  await expect(page).toHaveURL(/ask-the-data$/);
  await expect(page.getByRole("textbox")).toHaveCount(0);
});
