import { NextResponse } from "next/server";
import { z } from "zod";
import { getStrictAdminUser } from "@/lib/db/require-admin";
import { getAnalysisAuthResult } from "@/lib/db/require-analysis";
import { listRegisteredDatasets } from "@/lib/db/dataset-registry";
import { reviewCall } from "@/lib/policy-review/db";
import { datasetDefinition } from "@/lib/policy-review/worker";
import { reviewCreateSchema } from "@/lib/policy-review/types";
export const runtime = "nodejs";
const reply = (data: unknown, status = 200) =>
  NextResponse.json(data, { status, headers: { "Cache-Control": "private, no-store" } });
async function actor() {
  const [admin, auth] = await Promise.all([getStrictAdminUser(), getAnalysisAuthResult()]);
  return admin && auth.status === "approved" && auth.user.id === admin.id ? admin.id : null;
}
const query = z.object({
  run: z.uuid().optional(),
  section: z.coerce.number().int().positive().optional(),
  offset: z.coerce.number().int().min(0).max(5000).default(0),
  topic: z.string().max(80).optional(),
});
export async function GET(request: Request) {
  const id = await actor();
  if (!id) return reply({ error: "Approved admin required." }, 403);
  const parsed = query.safeParse(Object.fromEntries(new URL(request.url).searchParams));
  if (!parsed.success || (parsed.data.section && !parsed.data.run))
    return reply({ error: "Invalid review selection." }, 400);
  try {
    return reply(
      await reviewCall(
        id,
        parsed.data.run ? (parsed.data.section ? "section" : "get") : "list",
        parsed.data,
      ),
    );
  } catch {
    return reply({ error: "Review unavailable or permission revoked." }, 503);
  }
}
const change = z.discriminatedUnion("operation", [
  reviewCreateSchema.extend({ operation: z.literal("create") }),
  z.object({ operation: z.literal("cancel"), run: z.uuid() }),
  z.object({
    operation: z.literal("resume"),
    run: z.uuid(),
    extraBudget: z.number().int().min(1).max(1000),
    retryFailed: z.boolean().default(false),
  }),
]);
async function body(request: Request) {
  const reader = request.body?.getReader();
  if (!reader) throw Error();
  let size = 0;
  const parts: Uint8Array[] = [];
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      size += value.length;
      if (size > 65536) {
        await reader.cancel();
        throw Error();
      }
      parts.push(value);
    }
  } finally {
    reader.releaseLock();
  }
  return JSON.parse(Buffer.concat(parts).toString("utf8"));
}
export async function POST(request: Request) {
  const id = await actor();
  if (!id) return reply({ error: "Approved admin required." }, 403);
  const origin = request.headers.get("origin");
  try {
    if (
      origin &&
      new URL(origin).host !== (request.headers.get("host") ?? new URL(request.url).host)
    )
      return reply({ error: "Same-origin request required." }, 403);
  } catch {
    return reply({ error: "Invalid origin." }, 403);
  }
  const parsed = change.safeParse(await body(request).catch(() => null));
  if (!parsed.success) return reply({ error: "Invalid review request." }, 400);
  try {
    const { operation, ...payload } = parsed.data;
    if (operation !== "create") return reply(await reviewCall(id, operation, payload));
    if (!("sources" in payload) || payload.sources.online)
      return reply({ error: "Online review is unavailable." }, 400);
    const available = await listRegisteredDatasets("internal");
    const definitions = available
      .filter(
        (d) =>
          payload.sources.datasetMode === "automatic" ||
          payload.sources.tables.includes(d.tableName),
      )
      .map(datasetDefinition);
    if (
      payload.sources.datasetMode === "restricted" &&
      payload.sources.tables.some((t) => !definitions.some((d) => d.tableName === t))
    )
      return reply({ error: "A selected table is unavailable; refresh your sources." }, 400);
    return reply(
      await reviewCall(id, "create", {
        ...payload,
        datasets: { capturedAt: new Date().toISOString(), exactRowsFrozen: false, definitions },
      }),
      201,
    );
  } catch {
    return reply(
      {
        error:
          "Review could not be saved. Narrow the collection if it exceeds 1,000 versions or 5,000 sections; retry with the same request.",
      },
      503,
    );
  }
}
