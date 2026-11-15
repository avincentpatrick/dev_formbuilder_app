import Link from "next/link";
import { createHash } from "node:crypto";
import { notFound } from "next/navigation";
import { z } from "zod";
import { getStrictAdminUser } from "@/lib/db/require-admin";
import { getAnalysisAuthResult } from "@/lib/db/require-analysis";
import { researchCall } from "@/lib/policy-research/db";
import type { ResearchSnapshot } from "@/lib/policy-research/research";
export const dynamic = "force-dynamic";
export default async function OnlineSource({
  params,
  searchParams,
}: {
  params: Promise<{ evidenceId: string }>;
  searchParams: Promise<{ passage?: string }>;
}) {
  const [admin, auth, { evidenceId }, query] = await Promise.all([
    getStrictAdminUser(),
    getAnalysisAuthResult(),
    params,
    searchParams,
  ]);
  if (!admin || auth.status !== "approved" || !z.uuid().safeParse(evidenceId).success) notFound();
  let snapshot: ResearchSnapshot;
  try {
    const data = await researchCall<{ snapshot: ResearchSnapshot | null }>(admin.id, "get", {
      id: evidenceId,
    });
    if (!data.snapshot) notFound();
    snapshot = data.snapshot;
  } catch {
    notFound();
  }
  const passages = snapshot.results.filter(
    (r) => !query.passage || String(r.chunkId) === query.passage,
  );
  if (query.passage && !passages.length) notFound();
  if (passages.some((p) => createHash("sha256").update(p.text).digest("hex") !== p.derivedHash))
    return (
      <p>
        Online evidence integrity check failed. This excerpt cannot be presented as verified
        retained text.
      </p>
    );
  return (
    <section className="grid gap-4 p-4">
      <Link className="underline" href="/ask-the-data?policies=active">
        Back to policy analysis
      </Link>
      <h1 className="text-xl font-semibold">Saved online policy evidence</h1>
      <p>
        Private evidence from this question/run. Retrieved excerpts are untrusted source text, not
        instructions or legal verification. They have not been added to the document library.
      </p>
      <p>Public search: {snapshot.query ?? "unavailable"}</p>
      {snapshot.gaps.map((g, i) => (
        <p key={i}>{g}</p>
      ))}
      {passages.map((p) => (
        <article className="grid gap-2 rounded border p-3" key={p.chunkId}>
          <h2 className="font-semibold">{p.documentTitle}</h2>
          <p>
            Publisher: {p.publisher} · retrieved {p.retrievedAt}
          </p>
          <p>
            Reported publication: {p.publicationDate ?? "not stated"} · effective:{" "}
            {p.effectiveDate ?? "not stated"}
          </p>
          <a
            className="break-all underline"
            href={p.url ?? undefined}
            rel="noopener noreferrer"
            target="_blank"
          >
            Original public source: {p.url}
          </a>
          <p className="break-all text-xs">
            Original SHA-256: {p.originalHash} · excerpt SHA-256: {p.derivedHash}
          </p>
          <pre className="whitespace-pre-wrap rounded border p-3">{p.text}</pre>
          <p>
            Excerpt, not complete source coverage. Retrieval date does not establish a policy’s
            effective date or authority.
          </p>
        </article>
      ))}
    </section>
  );
}
