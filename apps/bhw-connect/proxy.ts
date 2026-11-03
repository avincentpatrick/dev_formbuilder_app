import { NextResponse, type NextRequest } from "next/server";
import { contentSecurityPolicy, createNonce } from "@/lib/csp";
import { MERIDIAN_SESSION_COOKIE } from "@/lib/db/meridian-identity-cookie";

/**
 * Two jobs, on two sets of paths. Named `proxy.ts` (not `middleware.ts`) per Next.js 16's file
 * convention rename.
 *
 * 1. **Every page** gets the Content-Security-Policy and a fresh nonce (`lib/csp.ts` says why: on the
 *    testing box this app shares Meridian's origin). The nonce goes on the request twice — in its
 *    own `Content-Security-Policy` header, which is where Next looks for the nonce to stamp on its
 *    own scripts, and as `x-nonce`, which the root layout reads for the one inline script of ours
 *    (`NoFlashScript`). Next's own router prefetches (an RSC payload, not HTML) and static files
 *    are skipped. A browser's `Purpose: prefetch` is NOT skipped: that is an HTML page the browser
 *    may later show, so it carries the policy like any other.
 *
 * 2. **`/admin`** sends a visitor with no Meridian session cookie at all to `/admin/login`, which
 *    explains that admins sign in with their Meridian account. It makes no network call: whether the
 *    cookie is a live session, and whether its owner is an admin, is decided server-side in
 *    `app/admin/(dashboard)/layout.tsx` via `getAdminAuthResult()`. This is a UX shortcut, not the
 *    security boundary, per BUILD_PLAN.md §8 2.5 ("non-admin authenticated user is denied").
 */
export function proxy(request: NextRequest) {
  const nonce = createNonce();
  const csp = contentSecurityPolicy(nonce, { dev: process.env.NODE_ENV === "development" });

  const headers = new Headers(request.headers);
  headers.set("x-nonce", nonce);
  headers.set("Content-Security-Policy", csp);
  const response = NextResponse.next({ request: { headers } });
  response.headers.set("Content-Security-Policy", csp);

  const { pathname } = request.nextUrl;
  if (!pathname.startsWith("/admin") || pathname === "/admin/login") return response;
  if (request.cookies.get(MERIDIAN_SESSION_COOKIE)?.value) return response;

  // From nextUrl, not new URL("/admin/login", request.url): nextUrl carries the base path, so the
  // redirect lands on /bhw-connect/admin/login on the box and on /admin/login everywhere else.
  const login = request.nextUrl.clone();
  login.pathname = "/admin/login";
  login.search = "";
  return NextResponse.redirect(login);
}

export const config = {
  // A literal, as Next requires (it reads this statically). All relative to the base path; Next
  // prefixes matchers itself.
  matcher: [
    // Every /admin request, prefetches included: the redirect above must see them all.
    "/admin/:path*",
    // The home page on its own line. Measured: under a base path the pattern below becomes
    // `/bhw-connect/(…)`, which needs a slash after the prefix, so `/bhw-connect` itself went
    // unmatched and was served without a policy.
    {
      source: "/",
      missing: [{ type: "header", key: "next-router-prefetch" }],
    },
    // Every other page, for the CSP. API routes answer JSON and static files carry no HTML.
    {
      source: "/((?!api|_next/static|_next/image|favicon.ico|geo|img).*)",
      missing: [{ type: "header", key: "next-router-prefetch" }],
    },
  ],
};
