import "server-only";
import { getMeridianIdentity, type MeridianUser } from "./meridian-identity";
import { createSupabaseServiceClient } from "./service-client";

export type AnalysisAuthResult =
  | { status: "signed_out" }
  | { status: "unapproved"; user: MeridianUser | null }
  | { status: "approved"; user: MeridianUser };

/** No approval cache: revocation applies to the next request, independently of admin status. */
export async function getAnalysisAuthResult(): Promise<AnalysisAuthResult> {
  try {
    const identity = await getMeridianIdentity();
    if (identity.status === "signed_out") return { status: "signed_out" };
    if (identity.status === "refused") return { status: "unapproved", user: null };
    const { data, error } = await createSupabaseServiceClient()
      .from("analysis_users")
      .select("approved")
      .eq("user_id", identity.user.id)
      .maybeSingle();
    return !error && data?.approved === true
      ? { status: "approved", user: identity.user }
      : { status: "unapproved", user: identity.user };
  } catch {
    return { status: "signed_out" };
  }
}
