import "server-only";
import { createSupabaseServiceClient } from "@/lib/db/service-client";
import { listRegisteredDatasets } from "@/lib/db/dataset-registry";
import {
  policySourcesSchema,
  type PolicySources,
  type PolicyRecord,
  type PolicyContext,
} from "./schema";
export async function policyCall<T>(
  actor: string,
  operation: string,
  payload: Record<string, unknown> = {},
): Promise<T> {
  const { data, error } = await createSupabaseServiceClient()
    .rpc(
      "policy_evidence_api" as never,
      { p_actor: actor, p_operation: operation, p_payload: payload } as never,
    )
    .abortSignal(AbortSignal.timeout(8000));
  if (error) throw new Error("Policy evidence is unavailable or outside the selected scope");
  return data as T;
}
export async function resolvePolicyContext(
  actor: string,
  raw: PolicySources,
): Promise<PolicyContext> {
  const sources = policySourcesSchema.parse(raw);
  if (sources.online)
    throw new Error(
      "Online policy research is unavailable; use internal sources for this question",
    );
  const records = await policyCall<PolicyRecord[]>(actor, "select", sources);
  if (!records.length || records.length > 100)
    throw new Error("Choose between 1 and 100 available policy revisions");
  let tables: string[] | undefined;
  if (sources.datasetMode === "restricted") {
    const registered = await listRegisteredDatasets("internal");
    const allowed = new Set(registered.map((r) => r.tableName));
    if (sources.tables.some((t) => !allowed.has(t)))
      throw new Error("A selected dataset table is not registered or available");
    tables = [...new Set(sources.tables)];
  }
  return { actor, sources, records, keys: records.map((r) => r.document), tables };
}
