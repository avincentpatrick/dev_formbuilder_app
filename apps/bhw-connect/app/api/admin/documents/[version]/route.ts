import { getStrictAdminUser } from "@/lib/db/require-admin";
import { libraryCall, type Detail } from "@/lib/documents/library";
import { readDocument } from "@/lib/storage/documents";
import { z } from "zod";
export const runtime = "nodejs";
const headers = { "Cache-Control": "private, no-store", "X-Content-Type-Options": "nosniff" };
export async function GET(request: Request, { params }: { params: Promise<{ version: string }> }) {
  const admin = await getStrictAdminUser();
  if (!admin) return Response.json({ error: "Admin role required." }, { status: 403, headers });
  const { version } = await params;
  const url = new URL(request.url);
  const extraction = url.searchParams.get("extraction") || undefined;
  if (
    !z.uuid().safeParse(version).success ||
    (extraction && !z.uuid().safeParse(extraction).success)
  )
    return Response.json({ error: "Invalid version." }, { status: 400, headers });
  try {
    const detail = await libraryCall<Detail>(admin.id, "detail", { version, extraction });
    if (url.searchParams.get("download") === "original")
      return new Response(new Uint8Array(await readDocument(detail.storage_key)), {
        headers: {
          ...headers,
          "Content-Type": "application/pdf",
          "Content-Disposition": "attachment; filename=document.pdf",
        },
      });
    if (url.searchParams.get("download") === "text") {
      if (!detail.extraction)
        return Response.json({ error: "No extracted text yet." }, { status: 404, headers });
      return new Response(detail.extraction.canonical_text, {
        headers: {
          ...headers,
          "Content-Type": "text/plain; charset=utf-8",
          "Content-Disposition": "attachment; filename=document.txt",
        },
      });
    }
    return Response.json(detail, { headers });
  } catch {
    return Response.json({ error: "Document or file is unavailable." }, { status: 503, headers });
  }
}
