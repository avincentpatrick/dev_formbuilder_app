import { defineConfig } from "@playwright/test";

// Set by CI's bhw-connect job, which has already built the app under /bhw-connect, started it against
// the seeded database and rendered five pages. It ends in a slash, and the specs navigate with relative
// paths ("explore", not "/explore"), because a leading slash would drop the /bhw-connect prefix.
const externalBaseURL = process.env.BHW_E2E_BASE_URL;

export default defineConfig({
  testDir: "./e2e",
  timeout: 30_000,
  fullyParallel: true,
  retries: process.env.CI ? 1 : 0,
  reporter: process.env.CI ? "github" : "list",
  use: {
    baseURL: externalBaseURL ?? "http://localhost:3100/",
    trace: "retain-on-failure",
    // Use the browser pre-installed in this environment rather than downloading
    // the default headless-shell variant. CI has its own browsers via the
    // official Playwright GitHub Action instead (see .github/workflows/ci.yml).
    launchOptions: process.env.CI ? {} : { executablePath: "/opt/pw-browsers/chromium" },
  },
  webServer: externalBaseURL
    ? undefined
    : {
        command: "npm run build && npm run start -- -p 3100",
        url: "http://localhost:3100",
        reuseExistingServer: !process.env.CI,
        timeout: 180_000,
      },
});
