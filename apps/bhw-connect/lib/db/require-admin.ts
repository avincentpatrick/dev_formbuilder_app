import "server-only";
import { getMeridianIdentity } from "./meridian-identity";
import { createSupabaseServiceClient } from "./service-client";

export type AdminUser = { id: string; email: string | null; role: "admin" | "editor" };

export type AdminAuthResult =
  | { status: "signed_out" }
  | { status: "not_admin"; email: string | null }
  | { status: "ok"; admin: AdminUser };

/**
 * The single admin-access check, used by the `/admin` layout (via `getAdminAuthResult`, which
 * distinguishes "not signed in" from "signed in but not an admin" for the UI) and every admin
 * server action (via `getAdminUser`, which only needs the pass/fail result). Deliberately fails
 * closed: any error — including missing env vars — resolves to "not authorized," never throws
 * and never grants access on a failure. This is the opposite default from the AI features
 * (lib/ai/*), which fail *open* to keep the public site available; an admin-access check failing
 * safe means denying access, not any risk of granting it.
 *
 * Who is signed in comes from Meridian (`lib/db/meridian-identity.ts`, Meridian's decision D178);
 * whether they administer BHW Connect is still `admin_users`, keyed by their Meridian user id. An
 * account Meridian refuses (not an active member of its workspace) is "not an admin" here, never
 * "signed out": it IS signed in, and sending it to sign in again would go round in a circle.
 */
export async function getAdminAuthResult(): Promise<AdminAuthResult> {
  try {
    const identity = await getMeridianIdentity();
    if (identity.status === "signed_out") return { status: "signed_out" };
    if (identity.status === "refused") return { status: "not_admin", email: null };

    const { user } = identity;
    const service = createSupabaseServiceClient();
    const { data } = await service.from("admin_users").select("role").eq("user_id", user.id).maybeSingle();
    if (!data) return { status: "not_admin", email: user.email };

    return { status: "ok", admin: { id: user.id, email: user.email, role: data.role } };
  } catch {
    return { status: "signed_out" };
  }
}

export async function getAdminUser(): Promise<AdminUser | null> {
  const result = await getAdminAuthResult();
  return result.status === "ok" ? result.admin : null;
}
