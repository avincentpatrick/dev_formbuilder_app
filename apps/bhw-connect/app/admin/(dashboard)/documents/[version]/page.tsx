import Link from "next/link";
import { notFound } from "next/navigation";
import { z } from "zod";
import { getStrictAdminUser } from "@/lib/db/require-admin";
import { libraryCall, type Detail } from "@/lib/documents/library";
import { DocumentControls } from "@/components/admin/document-library";
import { withBasePath } from "@/lib/base-path";
export default async function DocumentPage({
  params,
  searchParams,
}: {
  params: Promise<{ version: string }>;
  searchParams: Promise<{ page?: string; extraction?: string }>;
}) {
  const admin = await getStrictAdminUser();
  if (!admin) notFound();
  const { version } = await params;
  const query = await searchParams;
  if (
    !z.uuid().safeParse(version).success ||
    (query.extraction && !z.uuid().safeParse(query.extraction).success)
  )
    notFound();
  let detail: Detail;
  try {
    detail = await libraryCall<Detail>(admin.id, "detail", {
      version,
      extraction: query.extraction,
    });
  } catch {
    return <p role="alert">The document is unavailable. Try again later.</p>;
  }
  const extraction = detail.extraction;
  const index = Math.max(
    0,
    Math.min((extraction?.page_map.length ?? 1) - 1, (Number(query.page) || 1) - 1),
  );
  const page = extraction?.page_map[index];
  const text =
    extraction && page
      ? Array.from(extraction.canonical_text).slice(page.char_start, page.char_end).join("")
      : "";
  const revision = extraction ? `&extraction=${extraction.id}` : "";
  return (
    <div className="flex flex-col gap-4">
      <Link href="/admin/documents" className="underline">
        ← Document library
      </Link>
      <h2 className="text-xl font-semibold">{detail.title}</h2>
      <p>
        {detail.issuer ?? "Agency not stated"} · {detail.document_type ?? "Type not stated"} ·{" "}
        {detail.archived ? "Archived" : detail.availability}
      </p>
      <p className="text-sm">
        Publication: {detail.publication_date ?? "not stated"} · Effective:{" "}
        {detail.effective_date ?? "not stated"} · Embeddings: {detail.embedding_status}
      </p>
      <p className="text-xs break-all">
        Version {detail.id}
        {detail.supersedes_id && (
          <>
            {" "}
            · Supersedes{" "}
            <Link className="underline" href={`/admin/documents/${detail.supersedes_id}`}>
              {detail.supersedes_id}
            </Link>
          </>
        )}
      </p>
      <div className="flex flex-wrap gap-3">
        <a
          href={withBasePath(`/api/admin/documents/${version}?download=original`)}
          className="underline"
        >
          Download original PDF
        </a>
        {extraction && (
          <a
            href={withBasePath(`/api/admin/documents/${version}?download=text${revision}`)}
            className="underline"
          >
            Download complete extracted text
          </a>
        )}
      </div>
      {!detail.archived && detail.availability.startsWith("available") && (
        <div className="flex flex-wrap gap-3">
          <Link className="underline" href={`/ask-the-data?libraryVersion=${version}`}>
            Ask about this document
          </Link>
          {detail.collection_key && (
            <Link
              className="underline"
              href={`/ask-the-data?libraryCollection=${encodeURIComponent(detail.collection_key)}`}
            >
              Ask about this collection
            </Link>
          )}
        </div>
      )}
      <p className="text-sm text-muted">
        OCR and reading order can be inaccurate. Blank/unreadable pages are limitations, not
        evidence that a requirement is absent. Upload dates are not effective dates.
      </p>
      {detail.archived && detail.extraction && (
        <Link
          className="underline"
          href={`/ask-the-data?policies=selected&version=${version}&historical=1`}
        >
          Ask about this historical version
        </Link>
      )}
      <DocumentControls detail={{ ...detail, extraction: null }} />
      <section>
        <h3 className="font-semibold">Processing history</h3>
        <ul className="grid gap-2">
          {detail.jobs.map((j) => (
            <li key={j.id} className="rounded border p-2 text-sm">
              {j.kind}: {j.stage} · {j.progress}% · attempt {j.attempts}/3{" "}
              {j.safe_error && <p role="status">{j.safe_error}</p>}
            </li>
          ))}
        </ul>
      </section>
      {extraction ? (
        <section className="grid gap-3">
          <h3 className="font-semibold">Extracted text · revision {extraction.revision}</h3>
          <p className="text-xs">
            Parser {extraction.parser_version}; OCR {extraction.ocr_version ?? "not used"};
            languages {extraction.languages.join(", ") || "none"}.
          </p>
          <nav aria-label="PDF pages" className="flex flex-wrap gap-2">
            {extraction.page_map.map((p) => (
              <Link
                className="rounded border p-2 text-xs"
                aria-current={p.page === page?.page ? "page" : undefined}
                key={p.page}
                href={`/admin/documents/${version}?page=${p.page}${revision}`}
              >
                PDF {p.page}
              </Link>
            ))}
          </nav>
          {page && (
            <>
              <h4 className="font-semibold">
                Physical PDF page {page.page} · {page.method}
              </h4>
              <ul>
                {page.warnings.map((w) => (
                  <li key={w} className="text-sm">
                    Warning: {w.replaceAll("_", " ")}
                  </li>
                ))}
              </ul>
              <pre className="max-h-[36rem] overflow-auto whitespace-pre-wrap rounded border p-3 text-sm">
                {text || "No readable text on this page."}
              </pre>
            </>
          )}
          <details>
            <summary>All page warnings ({extraction.warnings.length})</summary>
            <ul>
              {extraction.warnings.map((w, i) => (
                <li key={i}>
                  PDF page {w.page}: {w.code.replaceAll("_", " ")}
                </li>
              ))}
            </ul>
          </details>
          <details>
            <summary>Extraction revisions (retained for citations)</summary>
            <ul>
              {detail.revisions.map((r) => (
                <li key={r.id}>
                  <Link
                    className="underline"
                    href={`/admin/documents/${version}?extraction=${r.id}`}
                  >
                    Revision {r.revision} · {r.created_at}
                  </Link>
                </li>
              ))}
            </ul>
          </details>
        </section>
      ) : (
        <p>
          No extracted text yet. Processing continues independently of this page. A queued job
          requires the local worker to be running.
        </p>
      )}
    </div>
  );
}
