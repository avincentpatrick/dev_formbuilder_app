import { getAnalysisAuthResult } from "@/lib/db/require-analysis";
import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { getDocChunk } from "@/lib/db/doc-chunks";

export const dynamic = "force-dynamic";

export default async function AssistantSourcePage({
  params,
}: {
  params: Promise<{ chunkId: string }>;
}) {
  if ((await getAnalysisAuthResult()).status !== "approved") redirect("/ask-the-data");
  const { chunkId } = await params;
  const id = Number(chunkId);
  if (!Number.isInteger(id) || id < 1) notFound();

  const chunk = await getDocChunk(id, true);
  if (!chunk) notFound();

  const uploaded = chunk.document.startsWith("upload:");
  const pageLabel =
    chunk.pageTo > chunk.pageFrom
      ? `${uploaded ? "PDF pages" : "Slides"} ${chunk.pageFrom}–${chunk.pageTo}`
      : `${uploaded ? "PDF page" : "Slide"} ${chunk.pageFrom}`;

  return (
    <div className="flex flex-col gap-4">
      <div>
        <Link href="/ask-the-data" className="text-xs text-muted hover:underline">
          ← Back to the assistant
        </Link>
        <h2 className="mt-2 text-lg font-semibold">
          {chunk.documentTitle} — {pageLabel.toLowerCase()}
        </h2>
        {chunk.heading && <p className="text-sm text-muted">{chunk.heading}</p>}
      </div>

      {chunk.versionId && (
        <section className="grid gap-2 rounded border p-3 text-xs">
          <p className="break-all">Immutable version: {chunk.versionId}</p>
          <p className="break-all">
            Extraction: {chunk.extractionId} · revision {chunk.revision}
          </p>
          <Link
            className="underline"
            href={`/admin/documents/${chunk.versionId}?extraction=${chunk.extractionId}&page=${chunk.pageFrom}`}
          >
            Read this exact extraction and its page warnings
          </Link>
        </section>
      )}
      <dl className="grid grid-cols-2 gap-x-6 gap-y-2 rounded-lg border border-border p-4 text-xs sm:grid-cols-4">
        <div>
          <dt className="text-muted">Document</dt>
          <dd className="font-mono">{chunk.document}</dd>
        </div>
        <div>
          <dt className="text-muted">As of</dt>
          <dd>{chunk.asOf ?? "not stated"}</dd>
        </div>
        <div>
          <dt className="text-muted">Chunk</dt>
          <dd className="font-mono">#{chunk.chunkId}</dd>
        </div>
        <div>
          <dt className="text-muted">Characters</dt>
          <dd className="font-mono">
            {chunk.charStart.toLocaleString()}–{chunk.charEnd.toLocaleString()}
          </dd>
        </div>
      </dl>

      <div>
        <h3 className="mb-2 text-sm font-medium">Stored text, verbatim</h3>
        <pre className="overflow-x-auto whitespace-pre-wrap rounded-lg border border-border bg-surface p-4 text-xs leading-relaxed">
          {chunk.content}
        </pre>
        <p className="mt-2 text-xs text-muted">
          Read from <span className="font-mono">doc_chunk</span> by id just now — not the copy the
          answer was built from. A quotation that does not appear above did not come from this
          slide.
        </p>
      </div>

      <p className="text-xs text-muted">
        Extracted by <span className="font-mono">{chunk.extractor}</span> from{" "}
        <span className="font-mono">{chunk.sourcePath}</span>. Slide numbering is the PDF&apos;s,
        which is what the plan cites by; the deck&apos;s own printed numbers are absent from most
        slides and offset from the PDF page by a varying amount.
      </p>
    </div>
  );
}
