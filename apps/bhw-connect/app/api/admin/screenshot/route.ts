import { getAdminUser } from "@/lib/db/require-admin";
import { readScreenshot } from "@/lib/storage/screenshots";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

/**
 * One feedback screenshot, for an admin's eyes only — what a 60-second Supabase signed link was until
 * Meridian's `bhw-p3-identity`. `/api/*` never passes through `proxy.ts`, so this route asks
 * `getAdminUser()` itself. Anyone else, and any path that is not a screenshot's, gets the same bare 404,
 * so the route says nothing about which screenshots exist.
 */
export async function GET(request: Request) {
  const notFound = () => new Response("Not found", { status: 404, headers: { "Cache-Control": "no-store" } });

  if (!(await getAdminUser())) return notFound();

  const path = new URL(request.url).searchParams.get("path") ?? "";
  const screenshot = await readScreenshot(path);
  if (!screenshot) return notFound();

  return new Response(new Uint8Array(screenshot.bytes), {
    headers: {
      "Content-Type": screenshot.contentType,
      "Cache-Control": "private, no-store",
      "X-Content-Type-Options": "nosniff",
    },
  });
}
