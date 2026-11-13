import { randomUUID, createHash } from "node:crypto";
import { z } from "zod";
import { NextResponse } from "next/server";
import { getAnalysisAuthResult } from "@/lib/db/require-analysis";
import { sourceCall } from "@/lib/analysis-sources/db";
import { saveOriginal, deleteOriginal } from "@/lib/analysis-sources/storage";
import type { ProjectSource } from "@/lib/analysis-sources/types";
const MAX_BYTES = 25 * 1024 * 1024;
export const runtime = "nodejs";
export async function POST(request: Request) {
  const auth = await getAnalysisAuthResult();
  if (auth.status !== "approved")
    return NextResponse.json(
      { error: "Approved access required" },
      { status: auth.status === "signed_out" ? 401 : 403 },
    );
  const projectId = new URL(request.url).searchParams.get("projectId");
  if (!z.uuid().safeParse(projectId).success)
    return NextResponse.json({ error: "Invalid project" }, { status: 400 });
  let source: ProjectSource | undefined;
  try {
    const project = (await sourceCall(auth.user.id, projectId!, "access")) as { role: string };
    if (project.role === "viewer")
      return NextResponse.json({ error: "Editing access required" }, { status: 403 });
    const name = decodeURIComponent(request.headers.get("x-file-name") ?? "")
      .replace(/[\x00-\x1f]/g, "")
      .slice(0, 200);
    if (!/\.(csv|xlsx|xls|pdf|docx|doc|pptx|ppt|geojson|json|zip)$/i.test(name))
      return NextResponse.json({ error: "Unsupported attachment format" }, { status: 400 });
    if (!request.body) return NextResponse.json({ error: "No file" }, { status: 400 });
    const reader = request.body.getReader();
    const chunks: Uint8Array[] = [];
    let count = 0;
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      count += value.byteLength;
      if (count > MAX_BYTES) {
        await reader.cancel();
        return NextResponse.json(
          { error: "Upload exceeds the 25 MiB processing limit; split the file." },
          { status: 413 },
        );
      }
      chunks.push(value);
    }
    const bytes = Buffer.concat(chunks);
    if (!bytes.length) return NextResponse.json({ error: "File is empty" }, { status: 400 });
    source = (await sourceCall(auth.user.id, projectId!, "create", {
      sourceId: randomUUID(),
      name,
      sha256: createHash("sha256").update(bytes).digest("hex"),
      bytes: bytes.length,
      kind: "file",
    })) as unknown as ProjectSource;
    if (["uploading", "failed", "blocked"].includes(source.status)) {
      await saveOriginal(projectId!, source.id, bytes);
      await sourceCall(auth.user.id, projectId!, "ready", { sourceId: source.id });
    }
    return NextResponse.json(
      { id: source.id },
      { headers: { "Cache-Control": "private, no-store" } },
    );
  } catch (error) {
    if (source) {
      try {
        await sourceCall(auth.user.id, projectId!, "get", { sourceId: source.id });
      } catch {
        await deleteOriginal(projectId!, source.id).catch(() => undefined);
      }
    }
    return NextResponse.json(
      { error: "Attachment could not be saved. Check project access and try again." },
      { status: (error as { code?: string }).code === "42501" ? 403 : 503 },
    );
  }
}
export async function GET(request: Request) {
  const auth = await getAnalysisAuthResult();
  if (auth.status !== "approved")
    return NextResponse.json(
      { error: "Access required" },
      { status: auth.status === "signed_out" ? 401 : 403 },
    );
  const projectId = new URL(request.url).searchParams.get("projectId");
  if (!z.uuid().safeParse(projectId).success)
    return NextResponse.json({ error: "Invalid project" }, { status: 400 });
  try {
    return NextResponse.json(await sourceCall(auth.user.id, projectId!, "list"), {
      headers: { "Cache-Control": "private, no-store" },
    });
  } catch {
    return NextResponse.json({ error: "Sources unavailable" }, { status: 403 });
  }
}
const metadata = z.object({
  reportingPeriod: z.string().max(200).optional(),
  grain: z.string().max(500).optional(),
  unit: z.string().max(200).optional(),
  denominator: z.string().max(500).optional(),
  geographyVersion: z.string().max(200).optional(),
  joinKeys: z.string().max(500).optional(),
  crs: z.string().max(3000).optional(),
});
export async function PATCH(request: Request) {
  const auth = await getAnalysisAuthResult();
  if (auth.status !== "approved")
    return NextResponse.json(
      { error: "Access required" },
      { status: auth.status === "signed_out" ? 401 : 403 },
    );
  if (request.headers.get("content-type")?.split(";")[0] !== "application/json")
    return NextResponse.json({ error: "JSON required" }, { status: 415 });
  const input = z
    .object({
      projectId: z.uuid(),
      sourceId: z.uuid(),
      operation: z.enum(["metadata", "retry", "delete"]),
      metadata: metadata.optional(),
    })
    .safeParse(await request.json().catch(() => null));
  if (!input.success)
    return NextResponse.json({ error: "Invalid source request" }, { status: 400 });
  try {
    await sourceCall(auth.user.id, input.data.projectId, input.data.operation, {
      sourceId: input.data.sourceId,
      metadata: input.data.metadata ?? {},
    });
    if (input.data.operation === "delete")
      await deleteOriginal(input.data.projectId, input.data.sourceId).catch(() => undefined);
    return NextResponse.json({ ok: true });
  } catch {
    return NextResponse.json({ error: "Source could not be changed" }, { status: 403 });
  }
}
