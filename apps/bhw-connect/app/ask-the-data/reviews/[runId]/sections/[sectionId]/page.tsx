import Link from "next/link";
import { notFound } from "next/navigation";
import { z } from "zod";
import { getStrictAdminUser } from "@/lib/db/require-admin";
import { getAnalysisAuthResult } from "@/lib/db/require-analysis";
import { reviewCall } from "@/lib/policy-review/db";
import type { ReviewSection } from "@/lib/policy-review/types";
export const dynamic = "force-dynamic";
export default async function ReviewSource({
  params,
}: {
  params: Promise<{ runId: string; sectionId: string }>;
}) {
  const [admin, auth, { runId, sectionId }] = await Promise.all([
    getStrictAdminUser(),
    getAnalysisAuthResult(),
    params,
  ]);
  if (
    !admin ||
    auth.status !== "approved" ||
    !z.uuid().safeParse(runId).success ||
    !/^\d+$/.test(sectionId) ||
    !Number.isSafeInteger(Number(sectionId))
  )
    notFound();
  let section: ReviewSection & { source_text: string; integrity_ok: boolean };
  try {
    section = await reviewCall(admin.id, "section", { run: runId, section: Number(sectionId) });
  } catch {
    notFound();
  }
  if (!section.integrity_ok)
    return (
      <p>Source integrity check failed. This section cannot be presented as verified evidence.</p>
    );
  return (
    <section className="grid gap-3 p-4">
      <Link className="underline" href="/ask-the-data?policies=active">
        Back to policy analysis
      </Link>
      <h1 className="text-xl font-semibold">
        {section.metadata.title} · PDF page {section.page}
      </h1>
      <p className="break-all text-sm">
        Version {section.metadata.version_id} · extraction {section.metadata.extraction_id} ·
        revision {section.metadata.revision}
      </p>
      <p>
        Publication {section.metadata.publication_date ?? "not stated"} · effective{" "}
        {section.metadata.effective_date ?? "not stated"}
      </p>
      <p>
        Canonical characters {section.char_start}–{section.char_end} · {section.status}
      </p>
      <p className="break-all text-xs">Verified SHA-256: {section.content_sha256}</p>
      <pre className="whitespace-pre-wrap rounded border p-3">{section.source_text}</pre>
      <p className="text-sm">
        This exact extraction and bounded section were frozen at review start. Original text remains
        canonical; findings do not replace it.
      </p>
      <Link
        className="underline"
        href={`/admin/documents/${section.metadata.version_id}?extraction=${section.metadata.extraction_id}&page=${section.page}`}
      >
        Read this extraction and page warnings
      </Link>
    </section>
  );
}
