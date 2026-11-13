import Link from "next/link";
import { redirect, notFound } from "next/navigation";
import { z } from "zod";
import { getAnalysisAuthResult } from "@/lib/db/require-analysis";
import { sourceCall } from "@/lib/analysis-sources/db";
import { withBasePath } from "@/lib/base-path";
import type { ProjectSource } from "@/lib/analysis-sources/types";
export const dynamic = "force-dynamic";
export default async function SourcePage({
  params,
  searchParams,
}: {
  params: Promise<{ projectId: string; sourceId: string }>;
  searchParams: Promise<{ version?: string }>;
}) {
  const auth = await getAnalysisAuthResult();
  if (auth.status !== "approved") redirect("/ask-the-data");
  const input = z.object({ projectId: z.uuid(), sourceId: z.uuid() }).safeParse(await params);
  if (!input.success) notFound();
  let source: ProjectSource;
  try {
    source = (await sourceCall(auth.user.id, input.data.projectId, "get", {
      sourceId: input.data.sourceId,
    })) as unknown as ProjectSource;
  } catch {
    notFound();
  }
  const expected = (await searchParams).version;
  return (
    <section className="mx-auto flex w-full max-w-4xl flex-col gap-4 px-4 py-8">
      <Link href={`/ask-the-data/projects/${input.data.projectId}`} className="underline">
        Back to project
      </Link>
      <h1 className="break-words text-xl font-semibold">{source.name}</h1>
      <p>
        {source.kind === "web" ? "Retrieved online evidence" : "Private project attachment"} ·{" "}
        {source.status}
      </p>
      {source.error && <p>{source.error}</p>}
      <p className="text-sm">
        Retrieved/stored: {source.provenance?.retrievedAt ?? source.created_at}. Reporting period:{" "}
        {source.metadata.reportingPeriod ?? "not documented"}.
      </p>
      {source.provenance?.publisher && (
        <p className="text-sm">Publisher domain: {source.provenance.publisher}</p>
      )}
      {source.content?.profile.publicationDate && (
        <p className="text-sm">
          Publication date stated by the source: {source.content.profile.publicationDate}
        </p>
      )}
      {expected && expected !== source.content?.derivedHash && (
        <p role="status">
          This source has been re-extracted since that analysis. The saved analysis record retains
          the cited passage and version.
        </p>
      )}
      <p className="break-all text-xs text-muted">Original SHA-256: {source.sha256}</p>
      {source.url && (
        <a href={source.url} className="break-all underline">
          Online location
        </a>
      )}
      <a
        href={withBasePath(`/api/analysis/sources/${source.id}?projectId=${input.data.projectId}`)}
        className="underline"
      >
        Download original
      </a>
      {source.content?.profile.warnings.map((w, i) => (
        <p key={i} className="text-sm">
          {w}
        </p>
      ))}
      {source.content?.pages.map((p, i) => (
        <section key={i}>
          <h2 className="font-semibold">{p.locator}</h2>
          <p className="whitespace-pre-wrap break-words text-sm">{p.text}</p>
        </section>
      ))}
      {source.content?.tables.map((t, i) => (
        <section key={i} className="overflow-x-auto">
          <h2 className="font-semibold">{t.name}</h2>
          <p className="text-sm">
            {t.rows.length} rows. Preview shows the first 20; the original contains the full source.
          </p>
          <table className="my-3 text-sm">
            <thead>
              <tr>
                {t.columns.map((c) => (
                  <th key={c} className="border border-border p-2 text-left">
                    {c}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {t.rows.slice(0, 20).map((r, i) => (
                <tr key={i}>
                  {t.columns.map((c) => (
                    <td key={c} className="border border-border p-2">
                      {r[c] === null
                        ? "–"
                        : typeof r[c] === "object"
                          ? "Geometry / structured value"
                          : String(r[c])}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      ))}
    </section>
  );
}
