import "server-only";
import { createHash } from "node:crypto";
import { headers } from "next/headers";
import { MERIDIAN_SESSION_COOKIE } from "./meridian-identity-cookie";

export { MERIDIAN_SESSION_COOKIE };

/**
 * Who is signed in, asked of Meridian (Meridian's ADR-0024 §D5, decision D178).
 *
 * On the testing box this app shares Meridian's origin, so the browser sends Meridian's session cookie
 * with every request here. This module forwards that ONE cookie — never the whole jar — to Meridian's
 * `GET /api/v1/auth/whoami` from the server, and reads back the person's id, name and email. Whether
 * that person administers BHW Connect is still this app's own answer: `admin_users`, in
 * `lib/db/require-admin.ts`. Supabase Auth is gone (Meridian's `bhw-p3-identity`).
 *
 * The cookie pair is forwarded exactly as the browser sent it. Laravel's cookie is an encrypted,
 * URL-encoded value, and decoding then re-encoding it can turn a `+` into a space on the way back.
 *
 * Every answer is cached for 30 seconds per cookie (by its hash), so an admin page costs Meridian one
 * call per visitor per half-minute. A failure — a timeout, a 5xx, Meridian's maintenance window — is
 * never cached and reads as signed out, which `require-admin.ts` turns into "no access": fail closed.
 */

export type MeridianUser = { id: string; name: string; email: string };

/** `refused` is Meridian's 403: signed in, but not an active, verified member of the workspace. */
export type MeridianIdentity =
  | { status: "signed_in"; user: MeridianUser }
  | { status: "refused" }
  | { status: "signed_out" };

export const WHOAMI_TIMEOUT_MS = 2_000;
export const WHOAMI_CACHE_MS = 30_000;
const CACHE_LIMIT = 500;

/** For local development without Meridian (`BHW_AUTH_MODE=stub`); give it an `admin_users` row to use it. */
export const STUB_USER: MeridianUser = {
  id: "00000000-0000-4000-8000-000000000001",
  name: "Local admin",
  email: "admin@localhost",
};

// Meridian's ids are uuids, and this one is written into uuid columns (usage_events.session_id,
// ai_regression_case.reported_by), so anything else is refused here rather than at the database.
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const cache = new Map<string, { identity: MeridianIdentity; expires: number }>();

/** The `meridian-session=…` pair out of a Cookie header, verbatim, or null when it is absent or empty. */
export function sessionCookiePair(cookieHeader: string | null | undefined, name = MERIDIAN_SESSION_COOKIE): string | null {
  if (!cookieHeader) return null;
  for (const part of cookieHeader.split(";")) {
    const pair = part.trim();
    if (pair.startsWith(`${name}=`) && pair.length > name.length + 1) return pair;
  }
  return null;
}

/** Ask Meridian about one cookie pair. Exported apart from the request plumbing so it can be tested. */
export async function lookupMeridianIdentity(
  pair: string | null,
  {
    baseUrl = process.env.MERIDIAN_INTERNAL_URL,
    fetchImpl = fetch,
    now = Date.now(),
  }: { baseUrl?: string; fetchImpl?: typeof fetch; now?: number } = {},
): Promise<MeridianIdentity> {
  if (!pair || !baseUrl?.trim()) return { status: "signed_out" };

  const key = createHash("sha256").update(pair).digest("hex");
  const hit = cache.get(key);
  if (hit && hit.expires > now) return hit.identity;

  let identity: MeridianIdentity;
  try {
    const response = await fetchImpl(`${baseUrl.trim().replace(/\/+$/, "")}/api/v1/auth/whoami`, {
      headers: {
        cookie: pair,
        accept: "application/json",
        // Marks the call as AJAX, so Meridian does not record it as the page to return to after a
        // password confirmation (Laravel's StartSession skips storing the URL of an AJAX GET).
        "x-requested-with": "XMLHttpRequest",
      },
      cache: "no-store",
      redirect: "manual",
      signal: AbortSignal.timeout(WHOAMI_TIMEOUT_MS),
    });

    if (response.status === 200) {
      const user = parseUser(await response.json());
      if (!user) return { status: "signed_out" };
      identity = { status: "signed_in", user };
    } else if (response.status === 401) {
      identity = { status: "signed_out" };
    } else if (response.status === 403) {
      identity = { status: "refused" };
    } else {
      return { status: "signed_out" };
    }
  } catch {
    return { status: "signed_out" };
  }

  if (cache.size >= CACHE_LIMIT) {
    const oldest = cache.keys().next().value;
    if (oldest !== undefined) cache.delete(oldest);
  }
  cache.set(key, { identity, expires: now + WHOAMI_CACHE_MS });
  return identity;
}

/** Who is signed in to Meridian on this request. */
export async function getMeridianIdentity(): Promise<MeridianIdentity> {
  if (process.env.BHW_AUTH_MODE === "stub") {
    // Never in a production server: there a stray stub setting grants nothing.
    return process.env.NODE_ENV === "production"
      ? { status: "signed_out" }
      : { status: "signed_in", user: STUB_USER };
  }
  const requestHeaders = await headers();
  return lookupMeridianIdentity(sessionCookiePair(requestHeaders.get("cookie")));
}

/** Test seam: forget every cached answer. */
export function clearMeridianIdentityCache(): void {
  cache.clear();
}

function parseUser(body: unknown): MeridianUser | null {
  const data = (body as { data?: unknown } | null)?.data as Record<string, unknown> | undefined;
  if (!data) return null;
  const { id, name, email } = data;
  if (typeof id !== "string" || !UUID.test(id)) return null;
  if (typeof name !== "string" || typeof email !== "string") return null;
  return { id, name, email };
}
