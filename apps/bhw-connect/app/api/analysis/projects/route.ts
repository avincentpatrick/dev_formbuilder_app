import { deleteOriginal } from "@/lib/analysis-sources/storage";
import { NextResponse } from "next/server";
import { z } from "zod";
import { getAnalysisAuthResult } from "@/lib/db/require-analysis";
import { projectCall } from "@/lib/analysis-projects/db";
import { routeScopeSchema } from "@/lib/ai/route";
import { DATASET_SCOPE_IDS } from "@/lib/ai/scope-id";
const context = z.object({
  dataset: z.enum(DATASET_SCOPE_IDS).optional(),
  carriedScope: routeScopeSchema.nullable().optional(),
});
const schema = z.discriminatedUnion("operation", [
  z.object({
    operation: z.literal("create"),
    requestId: z.uuid(),
    title: z.string().trim().min(1).max(200).optional(),
    context: context.optional(),
  }),
  z.object({
    operation: z.literal("get"),
    projectId: z.uuid(),
    before: z.iso.datetime({ offset: true }).optional(),
  }),
  z.object({
    operation: z.literal("rename"),
    projectId: z.uuid(),
    title: z.string().trim().min(1).max(200),
    revision: z.number().int().nonnegative(),
  }),
  z.object({
    operation: z.literal("enqueue"),
    projectId: z.uuid(),
    question: z.string().trim().min(1).max(4000),
    requestId: z.uuid(),
    revision: z.number().int().nonnegative(),
    context: context.optional(),
  }),
  z.object({
    operation: z.literal("share"),
    projectId: z.uuid(),
    userId: z.uuid(),
    role: z.enum(["viewer", "editor"]),
  }),
  z.object({ operation: z.literal("unshare"), projectId: z.uuid(), userId: z.uuid() }),
  z.object({ operation: z.literal("delete"), projectId: z.uuid() }),
  z.object({ operation: z.literal("cancel"), projectId: z.uuid(), jobId: z.uuid() }),
  z.object({ operation: z.literal("retry"), projectId: z.uuid(), jobId: z.uuid() }),
]);
function projectView(value: unknown) {
  const project = value as { jobs?: Array<{ result?: Record<string, unknown> | null }> };
  if (!Array.isArray(project?.jobs)) return value;
  return {
    ...project,
    jobs: project.jobs.map((job) => {
      if (!job.result) return job;
      const result = { ...job.result };
      delete result.statistics;
      return { ...job, result };
    }),
  };
}
const headers = { "Cache-Control": "private, no-store" };
export async function GET() {
  return handle();
}
export async function POST(request: Request) {
  return handle(request);
}
async function handle(request?: Request) {
  const auth = await getAnalysisAuthResult();
  if (auth.status !== "approved")
    return NextResponse.json(
      { error: "Approved dashboard access required." },
      { status: auth.status === "signed_out" ? 401 : 403, headers },
    );
  try {
    if (
      request &&
      request.headers.get("content-type")?.split(";")[0].trim().toLowerCase() !== "application/json"
    )
      return NextResponse.json({ error: "JSON content type required" }, { status: 415, headers });
    if (!request) return NextResponse.json(await projectCall(auth.user.id, "list"), { headers });
    const parsed = schema.safeParse(await request.json().catch(() => null));
    if (!parsed.success)
      return NextResponse.json({ error: "Invalid project request." }, { status: 400, headers });
    const input = parsed.data;
    const projectId = "projectId" in input ? input.projectId : undefined;
    const result = await projectCall(auth.user.id, input.operation, projectId, input);
    if (input.operation === "delete" && projectId)
      await deleteOriginal(projectId).catch(() => undefined);
    return NextResponse.json(input.operation === "get" ? projectView(result) : result, { headers });
  } catch (error) {
    const code = (error as { code?: string }).code;
    const status = code === "42501" ? 403 : code === "40001" ? 409 : code === "22023" ? 400 : 503;
    return NextResponse.json(
      {
        error:
          status === 409
            ? "Project changed or another analysis is active. Refresh and try again."
            : "Project request could not be completed.",
      },
      { status, headers },
    );
  }
}
