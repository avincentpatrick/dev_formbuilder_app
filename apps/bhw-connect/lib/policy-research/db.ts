import "server-only";
import { createSupabaseServiceClient } from "@/lib/db/service-client";
export async function researchCall<T>(
  actor: string,
  operation: string,
  payload: Record<string, unknown>,
): Promise<T> {
  const { data, error } = await createSupabaseServiceClient()
    .rpc(
      "policy_research_api" as never,
      { p_actor: actor, p_operation: operation, p_payload: payload } as never,
    )
    .abortSignal(AbortSignal.timeout(10000));
  if (error) throw Error("Private online evidence is unavailable");
  return data as T;
}
