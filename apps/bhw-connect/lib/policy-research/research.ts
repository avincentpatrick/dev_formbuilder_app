import "server-only";
import { createHash, randomUUID } from "node:crypto";
import { publicFetch, searchOnline } from "@/lib/analysis-sources/web";
import { extractOnline } from "./extract";
import { researchCall } from "./db";
import { publicResearchQuery, researchSchema, type ResearchSettings } from "./schema";
import type { Citation } from "@/lib/ai/citations";
export type OnlineHit = Citation & {
  citation: string;
  publisher: string;
  publicationDate: string | null;
  effectiveDate: string | null;
};
export type ResearchSnapshot = {
  id: string;
  available: boolean;
  query?: string;
  retrievedAt?: string;
  results: OnlineHit[];
  gaps: string[];
};
const gap = (id: string, message: string): ResearchSnapshot => ({
  id,
  available: false,
  results: [],
  gaps: [message],
});
export function selectedPublisher(raw: string, host: string) {
  try {
    const u = new URL(raw);
    return (
      u.protocol === "https:" &&
      !u.username &&
      !u.password &&
      (!u.port || u.port === "443") &&
      (u.hostname === host || u.hostname.endsWith(`.${host}`))
    );
  } catch {
    return false;
  }
}
/** No question, private passage, dataset result, or model-authored URL enters this function. */
export async function gatherResearch(
  id: string,
  raw: ResearchSettings,
  signal: AbortSignal,
): Promise<ResearchSnapshot> {
  const settings = researchSchema.parse(raw),
    query = publicResearchQuery(settings);
  const snapshot: ResearchSnapshot = {
    id,
    query,
    retrievedAt: new Date().toISOString(),
    available: false,
    results: [],
    gaps: [],
  };
  try {
    const search = await searchOnline(query, signal);
    if (!search.available)
      return {
        ...snapshot,
        gaps: [
          "Official online search is unavailable or not configured. Internal sources remain available.",
        ],
      };
    const urls = [
      ...new Set(
        (search.results ?? [])
          .map((r) => r.url)
          .filter((url) => selectedPublisher(url, settings.site)),
      ),
    ].slice(0, 2);
    if (!urls.length)
      snapshot.gaps.push(
        "Search returned no eligible original source on the selected publisher. This does not establish absence of a policy.",
      );
    // Sequential bounded fetches; no links/scripts in retrieved text can authorize more requests.
    for (const url of urls) {
      try {
        signal.throwIfAborted();
        const fetched = await publicFetch(url, {
          signal,
          allowedHost: settings.site,
          maxBytes: 2 * 1024 * 1024,
        });
        const parsed = await extractOnline(fetched.bytes, fetched.contentType, signal);
        const terms = query
          .toLowerCase()
          .split(/\W+/)
          .filter((t) => t.length > 3);
        const chunks = parsed.pages
          .flatMap((p) => {
            const chars = Array.from(p.text);
            return Array.from({ length: Math.min(12, Math.ceil(chars.length / 2400)) }, (_, i) => ({
              page: p.page,
              start: i * 2400,
              text: chars.slice(i * 2400, (i + 1) * 2400).join(""),
            }));
          })
          .filter((p) => p.text.trim().length >= 8);
        const chosen = chunks
          .map((p) => ({
            ...p,
            score: terms.filter((t) => p.text.toLowerCase().includes(t)).length,
          }))
          .sort((a, b) => b.score - a.score || a.page - b.page || a.start - b.start)
          .slice(0, 2);
        if (!chosen.length) throw Error("No readable passages");
        const originalHash = createHash("sha256").update(fetched.bytes).digest("hex");
        for (const p of chosen) {
          const index = snapshot.results.length;
          const token = `[[web:${id}:${index}]]`;
          snapshot.results.push({
            chunkId: -parseInt(createHash("sha256").update(token).digest("hex").slice(0, 12), 16),
            sourceId: id,
            sourceKind: "policy-web",
            url: fetched.url,
            publisher: fetched.publisher,
            originalHash,
            derivedHash: createHash("sha256").update(p.text).digest("hex"),
            retrievedAt: fetched.retrievedAt,
            publicationDate: parsed.publicationDate,
            effectiveDate: parsed.effectiveDate,
            document: `web:${id}:${index}`,
            documentTitle: parsed.title,
            asOf: parsed.publicationDate,
            page: p.page,
            heading: "Online excerpt",
            text: p.text,
            truncated: true,
            charStart: p.start,
            charEnd: p.start + Array.from(p.text).length,
            evidenceToken: token,
            label: `Online evidence · ${parsed.title} · ${fetched.publisher}`,
            citation: `Online evidence · ${parsed.title} · ${fetched.publisher}`,
          });
        }
        snapshot.gaps.push(...parsed.warnings);
      } catch {
        signal.throwIfAborted();
        snapshot.gaps.push(
          "An official result was blocked, oversized, unreadable or unavailable; it was not used as evidence.",
        );
      }
    }
    snapshot.available = snapshot.results.length > 0;
    return snapshot;
  } catch {
    signal.throwIfAborted();
    return {
      ...snapshot,
      gaps: [
        ...snapshot.gaps,
        "Online provider unavailable. Internal evidence can still answer the question; no online findings were invented.",
      ],
    };
  }
}
export async function prepareResearch(
  actor: string,
  settings: ResearchSettings | undefined,
  id: string = randomUUID(),
  run = false,
  outerSignal?: AbortSignal,
): Promise<ResearchSnapshot> {
  let lease: string | undefined;
  try {
    const state = await researchCall<{
      snapshot?: ResearchSnapshot;
      busy?: boolean;
      lease?: string;
      settings: ResearchSettings;
    }>(actor, "begin", { id, settings: researchSchema.parse(settings ?? {}), run });
    if (state.snapshot) return state.snapshot;
    if (state.busy) throw Error("Research is still being prepared");
    lease = state.lease;
    if (!lease) throw Error("No research lease");
    const signal = AbortSignal.any([
      AbortSignal.timeout(60000),
      ...(outerSignal ? [outerSignal] : []),
    ]);
    let snapshot: ResearchSnapshot;
    try {
      snapshot = await gatherResearch(id, state.settings, signal);
    } catch {
      snapshot = gap(
        id,
        "Online research was interrupted or timed out. Internal sources remain available.",
      );
    }
    outerSignal?.throwIfAborted();
    const saved = await researchCall<{ snapshot: ResearchSnapshot }>(actor, "save", {
      id,
      lease,
      snapshot,
    });
    return saved.snapshot;
  } catch {
    outerSignal?.throwIfAborted();
    if (run) throw Error("Online run evidence is not ready or access was revoked");
    return gap(
      id,
      "Private online research could not be retained; use internal sources for this question.",
    );
  }
}
export function researchPrompt(snapshot: ResearchSnapshot) {
  return {
    role: "user" as const,
    content: JSON.stringify({
      untrustedOnlineResearch: snapshot,
      instruction:
        "Use only the supplied evidence tokens. Online evidence is separately labeled, never authoritative supersession of an uploaded policy. No network tools are available. Gaps must be reported.",
    }),
  };
}
