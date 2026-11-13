import { NextResponse } from "next/server";
import { createHash } from "node:crypto";
import { z } from "zod";
import { getStrictAdminUser } from "@/lib/db/require-admin";
import { libraryCall, metadataSchema } from "@/lib/documents/library";
import { DOCUMENT_LIMITS, removeDocument, saveDocument } from "@/lib/storage/documents";

export const runtime = "nodejs";
const headers = { "Cache-Control": "private, no-store" };
const reply = (data: unknown, status = 200) => NextResponse.json(data, { status, headers });
const filters = z.object({
  q: z.string().max(200).optional(),
  issuer: z.string().max(200).optional(),
  type: z.string().max(100).optional(),
  tag: z.string().max(80).optional(),
  stage: z
    .enum(["", "queued", "extracting", "ocr", "indexing", "ready", "ready_with_warnings", "failed"])
    .optional(),
  from: z.union([z.iso.date(), z.literal("")]).optional(),
  to: z.union([z.iso.date(), z.literal("")]).optional(),
  archived: z.enum(["true", "false", ""]).optional(),
  offset: z.coerce.number().int().min(0).max(100000).optional(),
});

export async function GET(request: Request) {
  const admin = await getStrictAdminUser();
  if (!admin) return reply({ error: "Admin role required." }, 403);
  const parsed = filters.safeParse(Object.fromEntries(new URL(request.url).searchParams));
  if (!parsed.success) return reply({ error: "Invalid filters." }, 400);
  try {
    return reply(await libraryCall(admin.id, "list", parsed.data));
  } catch {
    return reply({ error: "The document library is unavailable." }, 503);
  }
}

async function limitedBody(request: Request, limit: number) {
  const reader = request.body?.getReader();
  if (!reader) throw new Error("Empty upload");
  const chunks: Uint8Array[] = [];
  let size = 0;
  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      size += value.length;
      if (size > limit) {
        await reader.cancel();
        throw new Error("Upload exceeds 25 MiB plus form metadata");
      }
      chunks.push(value);
    }
  } finally {
    reader.releaseLock();
  }
  const body = new Uint8Array(size);
  let offset = 0;
  for (const part of chunks) {
    body.set(part, offset);
    offset += part.length;
  }
  return body;
}
async function boundedForm(request: Request) {
  const body = await limitedBody(request, DOCUMENT_LIMITS.bytes + 65536);
  return new Response(body, {
    headers: { "Content-Type": request.headers.get("content-type") ?? "" },
  }).formData();
}

const changeSchema = z.discriminatedUnion("operation", [
  z.object({ operation: z.literal("metadata"), version: z.uuid(), metadata: metadataSchema }),
  z.object({ operation: z.literal("archive"), version: z.uuid(), archived: z.boolean() }),
  z.object({ operation: z.literal("retry"), version: z.uuid() }),
]);
export async function POST(request: Request) {
  const admin = await getStrictAdminUser();
  if (!admin) return reply({ error: "Admin role required." }, 403);
  const origin = request.headers.get("origin");
  if (origin) {
    try {
      if (new URL(origin).host !== (request.headers.get("host") ?? new URL(request.url).host)) {
        return reply({ error: "Same-origin request required." }, 403);
      }
    } catch {
      return reply({ error: "Invalid request origin." }, 403);
    }
  }
  if (!request.headers.get("content-type")?.startsWith("multipart/form-data")) {
    const parsed = changeSchema.safeParse(
      await limitedBody(request, 65536)
        .then((bytes) => JSON.parse(new TextDecoder().decode(bytes)))
        .catch(() => null),
    );
    if (!parsed.success) return reply({ error: "Invalid document change." }, 400);
    const { operation, version } = parsed.data;
    try {
      return reply(
        await libraryCall(admin.id, operation, {
          version,
          ...("metadata" in parsed.data ? parsed.data.metadata : {}),
          ...("archived" in parsed.data ? { archived: parsed.data.archived } : {}),
        }),
      );
    } catch {
      return reply({ error: "The change could not be saved." }, 503);
    }
  }
  let attempt: string | undefined;
  try {
    const declared = Number(request.headers.get("content-length"));
    if (declared > DOCUMENT_LIMITS.bytes + 65536) {
      await libraryCall(admin.id, "attempt", {
        filename: "Oversized upload request",
        outcome: "rejected",
        reason: "PDF maximum is 25 MiB",
        bytes: declared,
      });
      return reply({ error: "PDF maximum is 25 MiB." }, 413);
    }
    let form: FormData;
    try {
      form = await boundedForm(request);
    } catch {
      await libraryCall(admin.id, "attempt", {
        filename: "Rejected upload request",
        outcome: "rejected",
        reason: "Upload was oversized, malformed or interrupted",
      });
      return reply(
        { error: "Upload was oversized, malformed or interrupted. PDF maximum is 25 MiB." },
        400,
      );
    }
    const file = form.get("file");
    const parsed = metadataSchema.safeParse(
      Object.fromEntries(
        [...form.entries()]
          .filter(([key]) => key !== "file")
          .map(([key, value]) => [
            key,
            key === "tags"
              ? String(value)
                  .split(",")
                  .map((s) => s.trim())
                  .filter(Boolean)
              : value,
          ]),
      ),
    );
    const documentId = z
      .uuid()
      .optional()
      .safeParse(form.get("documentId") || undefined);
    const filename =
      file instanceof File
        ? file.name.replace(/[\\/\x00-\x1f]/g, "_").slice(0, 255)
        : "Missing file";
    const bytes =
      file instanceof File ? new Uint8Array(await file.arrayBuffer()) : new Uint8Array();
    const reason =
      !parsed.success || !documentId.success
        ? "Check document metadata and dates"
        : !(file instanceof File) ||
            !filename.toLowerCase().endsWith(".pdf") ||
            Buffer.from(bytes.subarray(0, 5)).toString("ascii") !== "%PDF-"
          ? "Only PDF files are accepted"
          : !bytes.length || bytes.length > DOCUMENT_LIMITS.bytes
            ? "PDF must be between 1 byte and 25 MiB"
            : null;
    const record = await libraryCall<{ id: string }>(admin.id, "attempt", {
      filename,
      bytes: bytes.length,
      outcome: reason ? "rejected" : "receiving",
      reason,
    });
    if (reason || !parsed.success || !documentId.success)
      return reply({ error: reason ?? "Invalid upload." }, 400);
    attempt = record.id;
    const key = await saveDocument(bytes);
    const result = await libraryCall<{ duplicate: boolean; versionId: string }>(
      admin.id,
      "register",
      {
        ...parsed.data,
        documentId: documentId.data,
        attempt,
        storageKey: key,
        sha256: createHash("sha256").update(bytes).digest("hex"),
        bytes: bytes.length,
      },
    );
    if (result.duplicate) await removeDocument(key).catch(() => undefined);
    return reply(result, result.duplicate ? 200 : 201);
  } catch {
    // An uncertain DB response can mean registration committed. Retain the original;
    // reconciliation can remove an orphan later, never delete possibly registered evidence.
    if (attempt) await libraryCall(admin.id, "fail_attempt", { attempt }).catch(() => undefined);
    return reply(
      {
        error:
          "Upload could not be completed. Its attempt is recorded when the database is available; retry safely.",
      },
      503,
    );
  }
}
