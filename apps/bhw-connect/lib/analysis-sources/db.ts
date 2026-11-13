import "server-only";
import { createSupabaseServiceClient } from "@/lib/db/service-client";
import type { Json } from "@/lib/db/database.types";
export async function sourceCall(
  actor: string,
  project: string,
  operation: string,
  payload: Json = {},
) {
  const { data, error } = await createSupabaseServiceClient().rpc("analysis_source_api", {
    p_actor: actor,
    p_project: project,
    p_operation: operation,
    p_payload: payload,
  });
  if (error) throw Object.assign(new Error("Source request failed"), { code: error.code });
  return data;
}
export async function sourceWorker(
  operation: string,
  source?: string,
  lease?: string,
  payload: Json = {},
) {
  const { data, error } = await createSupabaseServiceClient().rpc("analysis_source_worker_api", {
    p_operation: operation,
    p_source: source,
    p_lease: lease,
    p_payload: payload,
  });
  if (error) throw new Error("Source worker database request failed");
  return data;
}
