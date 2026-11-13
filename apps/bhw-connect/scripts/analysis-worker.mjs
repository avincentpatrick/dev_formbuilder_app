// Run as a separate BHW Windows service. It uses only Node built-ins and the app's job endpoint.
const endpoint = new URL(
  process.env.BHW_ANALYSIS_WORKER_URL ?? "http://127.0.0.1:3100/bhw-connect/api/analysis/worker",
);
if (!process.env.CRON_SECRET) throw new Error("CRON_SECRET is required");
if (!["127.0.0.1", "localhost", "[::1]"].includes(endpoint.hostname))
  throw new Error("Worker endpoint must be loopback");
let stopped = false;
process.on("SIGTERM", () => {
  stopped = true;
});
process.on("SIGINT", () => {
  stopped = true;
});
while (!stopped) {
  try {
    const response = await fetch(endpoint, {
      method: "POST",
      redirect: "error",
      headers: { authorization: `Bearer ${process.env.CRON_SECRET}` },
      signal: AbortSignal.timeout(300_000),
    });
    if (!response.ok) throw new Error(`Worker HTTP ${response.status}`);
    const result = await response.json();
    if (!result.worked) await new Promise((resolve) => setTimeout(resolve, 2000));
  } catch (error) {
    console.error(error instanceof Error ? error.message : "Worker request failed");
    await new Promise((resolve) => setTimeout(resolve, 5000));
  }
}
