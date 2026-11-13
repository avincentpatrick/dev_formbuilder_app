import { NextResponse } from "next/server";
import { z } from "zod";
import { getAnalysisAuthResult } from "@/lib/db/require-analysis";
import { sourceCall } from "@/lib/analysis-sources/db";
import { readOriginal } from "@/lib/analysis-sources/storage";
import type { ProjectSource } from "@/lib/analysis-sources/types";
export async function GET(request: Request, { params }: { params: Promise<{ sourceId: string }> }) {
  const auth = await getAnalysisAuthResult();
  if (auth.status !== "approved")
    return NextResponse.json(
      { error: "Access required" },
      { status: auth.status === "signed_out" ? 401 : 403 },
    );
  const input = z.object({ sourceId: z.uuid(), projectId: z.uuid() }).safeParse({
    ...(await params),
    projectId: new URL(request.url).searchParams.get("projectId"),
  });
  if (!input.success) return NextResponse.json({ error: "Invalid request" }, { status: 400 });
  try {
    const source = (await sourceCall(auth.user.id, input.data.projectId, "get", {
      sourceId: input.data.sourceId,
    })) as unknown as ProjectSource;
    const bytes = await readOriginal(input.data.projectId, source.id, source.sha256);
    return new Response(new Uint8Array(bytes), {
      headers: {
        "Content-Type": "application/octet-stream",
        "Content-Disposition": `attachment; filename*=UTF-8''${encodeURIComponent(source.name)}`,
        "Cache-Control": "private, no-store",
        "X-Content-Type-Options": "nosniff",
      },
    });
  } catch {
    return NextResponse.json({ error: "Source unavailable" }, { status: 403 });
  }
}
