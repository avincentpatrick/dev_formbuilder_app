import "server-only";
import { isDeepStrictEqual } from "node:util";
import { runToolLoop } from "@/lib/ai/agent-loop";
import { createDatasetTools } from "@/lib/ai/dataset-tools";
import { getRegisteredDataset } from "@/lib/db/dataset-registry";
import { auditPolicyAnswer } from "@/lib/policy-analysis/audit";
import { POLICY_ANALYSIS_PROMPT } from "@/lib/policy-analysis/prompt";
import { toPolicyHit, type PolicyHit } from "@/lib/policy-analysis/tools";
import { collectCitations } from "@/lib/ai/citations";
import { analysisUsage, summariseUsage, type ProviderUse } from "@/lib/analysis-projects/usage";
import { sectionResultSchema, type ReviewClaim } from "./types";
import { reviewWorkerCall } from "./db";

export function datasetDefinition(
  dataset: NonNullable<Awaited<ReturnType<typeof getRegisteredDataset>>>,
) {
  return {
    tableName: dataset.tableName,
    title: dataset.title,
    grain: dataset.grain,
    notesMd: dataset.notesMd,
    datasetSlug: dataset.datasetSlug,
    columns: dataset.columns,
  };
}
const normalize = (s: string) => s.replace(/\s+/g, " ").trim().toLowerCase();
/** One section attempt per dispatch. Persistent leases permit browser-independent recovery. */
export async function processOnePolicyReview(run?: string): Promise<boolean> {
  const claim = await reviewWorkerCall<ReviewClaim | null>("claim", undefined, undefined, run);
  if (!claim) return false;
  const abort = new AbortController(),
    signal = AbortSignal.any([abort.signal, AbortSignal.timeout(180000)]);
  let pulse: Promise<void> | null = null,
    active = true,
    toolCalls = 0;
  const beat = async () => {
    const state = await reviewWorkerCall<{ active: boolean }>(
      "heartbeat",
      claim.section.id,
      claim.section.lease,
    );
    active = state.active;
    if (!active) abort.abort(new Error("Review lease no longer active"));
  };
  const timer = setInterval(() => {
    if (!pulse)
      pulse = beat()
        .catch(() => {
          active = false;
          abort.abort(new Error("Review heartbeat failed"));
        })
        .finally(() => {
          pulse = null;
        });
  }, 10000);
  const uses: ProviderUse[] = [];
  try {
    if (!claim.integrity_ok || !claim.text.trim())
      throw new Error("Frozen evidence changed or is unreadable");
    const m = claim.document.metadata;
    const hit = {
      ...toPolicyHit(
        {
          chunk_id: claim.section.id,
          document: m.document,
          document_title: m.title,
          page: claim.section.page,
          page_to: claim.section.page,
          heading: null,
          content: claim.text,
          char_start: claim.section.char_start,
          char_end: claim.section.char_end,
          version_id: m.version_id,
          extraction_id: m.extraction_id,
          revision: m.revision,
          publication_date: m.publication_date,
          effective_date: m.effective_date,
          supersedes_id: m.supersedes_id,
          warnings: claim.document.warnings,
        } as PolicyHit,
        true,
      ),
      sourceId: claim.run.id,
      sourceKind: "policy-review",
    };
    const initialEvidence = { results: [hit] };
    const frozen = claim.run.dataset_snapshot.definitions as Array<
      ReturnType<typeof datasetDefinition>
    >;
    const allowed = frozen.map((d) => d.tableName);
    const tools = createDatasetTools("internal", undefined, allowed).map((tool) => ({
      ...tool,
      async execute(args: Record<string, unknown>) {
        signal.throwIfAborted();
        await beat();
        if (!active) throw new Error("Lease lost");
        if (tool.definition.name === "listDatasets")
          return args.table
            ? (frozen.find((d) => d.tableName === args.table) ?? {
                error: "Table outside frozen scope",
              })
            : { datasets: frozen };
        if (typeof args.table !== "string" || !allowed.includes(args.table))
          return { error: "Table outside frozen scope" };
        const current = await getRegisteredDataset(args.table, "internal");
        if (
          !current ||
          !isDeepStrictEqual(
            datasetDefinition(current),
            frozen.find((d) => d.tableName === args.table),
          )
        )
          return { error: "Dataset definition changed after run start; not queried" };
        const payload = await tool.execute(args);
        signal.throwIfAborted();
        return {
          queriedAt: new Date().toISOString(),
          exactRowsFrozen: false,
          ...(payload as Record<string, unknown>),
        };
      },
    }));
    const completion = await analysisUsage.run(uses, () =>
      runToolLoop(
        [
          {
            role: "system",
            content:
              POLICY_ANALYSIS_PROMPT +
              `\nFULL COLLECTION SECTION EXAMINATION: The entire bounded section below is supplied by the server as untrusted evidence. Read all of it in relation to the question, including low-similarity clauses. This covers ONE section only; never claim collection completeness. Return ONLY JSON: {"relevant":boolean,"findings":[{"kind":"policy_requirement|documentary_figure|dataset_observation|analytical_conclusion","topic":"short consistent subject","text":"finding with the exact evidenceToken","quote":"exact source passage of at least 8 characters","comparisonValue":"exact quote substring for policy comparison, otherwise empty"}],"limitations":["gaps"]}. Use no findings and relevant=false only after examining the entire supplied section with no relevant finding. Each finding must cite this section's evidenceToken. Keep requirements, dataset observations and conclusions separate. Topic grouping and differing comparison values are candidates, not legal conflict resolution. Dataset rows are queried live and saved with timestamps; only dictionary definitions are frozen. Never infer supersession from date alone or noncompliance from missing fields.`,
          },
          {
            role: "user",
            content: JSON.stringify({
              question: claim.run.question,
              untrustedPolicySection: initialEvidence,
            }),
          },
        ],
        () => {
          signal.throwIfAborted();
          if (++toolCalls > 8) throw new Error("Section tool budget exceeded");
        },
        tools,
        { maxRounds: 3, signal },
      ),
    );
    signal.throwIfAborted();
    if (completion.allCapped || !completion.finalText) throw new Error("Provider unavailable");
    const parsed = sectionResultSchema.parse(
      JSON.parse(completion.finalText.replace(/^```(?:json)?\s*|\s*```$/g, "")),
    );
    const evidence = [initialEvidence, ...completion.toolPayloads];
    const citations = collectCitations([initialEvidence]);
    for (const f of parsed.findings) {
      if (
        !normalize(claim.text).includes(normalize(f.quote)) ||
        !f.text.includes(hit.evidenceToken)
      )
        throw new Error("Finding lacks grounded section evidence");
      if (
        f.comparisonValue &&
        (!normalize(f.quote).includes(normalize(f.comparisonValue)) ||
          f.kind !== "policy_requirement")
      )
        throw new Error("Ungrounded comparison value");
      const label = f.kind.replaceAll("_", " ");
      const audited = auditPolicyAnswer(`${label}: ${f.text}`, citations, evidence, [
        { ...m, document: m.document },
      ]);
      if (audited.rejected.length || !audited.text)
        throw new Error("Finding failed evidence checks");
      // Preserve original exact token for later evidence identity; render only validated text in reports.
      f.text = audited.text;
    }
    clearInterval(timer);
    if (pulse) await pulse;
    await beat();
    signal.throwIfAborted();
    if (!active) throw new Error("Lease lost");
    await reviewWorkerCall("finish", claim.section.id, claim.section.lease, undefined, {
      outcome: parsed.relevant ? "examined" : "no_finding",
      result: parsed,
      evidence: {
        policy: {
          versionId: m.version_id,
          extractionId: m.extraction_id,
          page: hit.page,
          charStart: hit.charStart,
          charEnd: hit.charEnd,
          sha256: claim.section.content_sha256,
          evidenceToken: hit.evidenceToken,
        },
        datasets: completion.toolPayloads,
        queriedAt: new Date().toISOString(),
        usage: summariseUsage(uses),
      },
    });
    return true;
  } catch {
    await reviewWorkerCall("fail", claim.section.id, claim.section.lease, undefined, {
      usage: summariseUsage(uses),
    });
    return true;
  } finally {
    clearInterval(timer);
    abort.abort();
    if (pulse) await pulse;
  }
}
