import "server-only";
import { createSupabaseServiceClient } from "@/lib/db/service-client";
export async function reviewCall<T>(
  actor: string,
  operation: string,
  payload: Record<string, unknown> = {},
): Promise<T> {
  const { data, error } = await createSupabaseServiceClient()
    .rpc(
      "policy_review_api" as never,
      { p_actor: actor, p_operation: operation, p_payload: payload } as never,
    )
    .abortSignal(AbortSignal.timeout(20000));
  if (error) throw new Error("Review is unavailable or permission was revoked");
  return data as T;
}
export async function reviewWorkerCall<T>(
  operation: string,
  section?: number,
  lease?: string,
  run?: string,
  payload: Record<string, unknown> = {},
): Promise<T> {
  const { data, error } = await createSupabaseServiceClient()
    .rpc(
      "policy_review_worker" as never,
      {
        p_operation: operation,
        p_section: section,
        p_lease: lease,
        p_run: run,
        p_payload: payload,
      } as never,
    )
    .abortSignal(AbortSignal.timeout(15000));
  if (error) throw new Error("Review worker state could not be saved");
  return data as T;
}
