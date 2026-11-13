import "server-only";
import { createSupabaseServiceClient } from "@/lib/db/service-client";
import type { Json } from "@/lib/db/database.types";
export async function projectCall(
  actor: string,
  operation: string,
  project?: string,
  payload: Json = {},
) {
  const { data, error } = await createSupabaseServiceClient().rpc("analysis_project_api", {
    p_actor: actor,
    p_operation: operation,
    p_project: project,
    p_payload: payload,
  });
  if (error) throw Object.assign(new Error("Project request failed"), { code: error.code });
  return data;
}
export async function workerCall(
  operation: string,
  job?: string,
  lease?: string,
  payload: Json = {},
) {
  const { data, error } = await createSupabaseServiceClient().rpc("analysis_worker_api", {
    p_operation: operation,
    p_job: job,
    p_lease: lease,
    p_payload: payload,
  });
  if (error) throw new Error("Worker database operation failed");
  return data;
}
