import { NextResponse } from "next/server";
import { z } from "zod";
import { getAnalysisAuthResult } from "@/lib/db/require-analysis";
import { projectCall } from "@/lib/analysis-projects/db";
export async function GET(
  _request: Request,
  { params }: { params: Promise<{ projectId: string; jobId: string }> },
) {
  const auth = await getAnalysisAuthResult();
  if (auth.status !== "approved")
    return NextResponse.json(
      { error: "Access required" },
      { status: auth.status === "signed_out" ? 401 : 403 },
    );
  const parsed = z.object({ projectId: z.uuid(), jobId: z.uuid() }).safeParse(await params);
  if (!parsed.success) return NextResponse.json({ error: "Invalid request" }, { status: 400 });
  try {
    const artifact = await projectCall(auth.user.id, "artifact", parsed.data.projectId, {
      jobId: parsed.data.jobId,
    });
    return new Response(JSON.stringify(artifact, null, 2), {
      headers: {
        "Content-Type": "application/json",
        "Content-Disposition": `attachment; filename="analysis-${parsed.data.jobId}.json"`,
        "Cache-Control": "private, no-store",
      },
    });
  } catch {
    return NextResponse.json({ error: "Result unavailable" }, { status: 403 });
  }
}
