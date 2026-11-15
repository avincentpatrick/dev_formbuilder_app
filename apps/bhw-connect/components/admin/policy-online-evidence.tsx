import Link from "next/link";
import type { ResearchSnapshot } from "@/lib/policy-research/research";
export function PolicyOnlineEvidence({ value }: { value: unknown }) {
  if (!value || typeof value !== "object" || !("results" in value) || !Array.isArray(value.results))
    return null;
  const snapshot = value as ResearchSnapshot & { sourceCount?: number };
  return (
    <aside className="grid gap-2 rounded border p-3 text-sm" aria-label="Online policy evidence">
      <h4 className="font-semibold">Online evidence · separate from uploaded policies</h4>
      <Link className="underline" href={`/ask-the-data/online/${snapshot.id}`}>
        Open saved online research ({snapshot.sourceCount ?? snapshot.results.length} excerpts)
      </Link>
      {snapshot.gaps.map((g, i) => (
        <p key={i}>{g}</p>
      ))}
      {snapshot.results.map((p) => (
        <div key={p.chunkId}>
          <Link
            className="underline"
            href={`/ask-the-data/online/${snapshot.id}?passage=${p.chunkId}`}
          >
            {p.documentTitle}
          </Link>
          <p>
            {p.publisher} · retrieved {p.retrievedAt}
          </p>
          <p>
            Reported publication {p.publicationDate ?? "not stated"} · effective{" "}
            {p.effectiveDate ?? "not stated"}
          </p>
          <blockquote className="border-l-2 pl-2">{p.text}</blockquote>
        </div>
      ))}
      <p className="text-xs">
        These excerpts do not establish legal supersession or complete online coverage. Originals
        have not been added to the policy library.
      </p>
    </aside>
  );
}
