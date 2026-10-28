import { createServerClient } from "@supabase/ssr";
import { NextResponse, type NextRequest } from "next/server";
import { contentSecurityPolicy, createNonce } from "@/lib/csp";

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
 * 2. **`/admin`** refreshes the Supabase Auth session cookie on every request (required by
 *    `@supabase/ssr` for token refresh to work in Server Components, which can't write cookies
 *    themselves — see lib/db/supabase-auth-server.ts) and redirects an unauthenticated visitor to
 *    `/admin/login`. This is a UX shortcut, not the security boundary: the actual admin_users role
 *    check happens server-side in `app/admin/(dashboard)/layout.tsx` via `getAdminAuthResult()`
 *    regardless of what this decides, per BUILD_PLAN.md §8 2.5 ("non-admin authenticated user is
 *    denied").
 */
export async function proxy(request: NextRequest) {
  const nonce = createNonce();
  const csp = contentSecurityPolicy(nonce, { dev: process.env.NODE_ENV === "development" });

  // Built again whenever the Supabase client rewrites the request's cookies, so the page renders
  // with both the refreshed session and the nonce.
  const next = () => {
    const headers = new Headers(request.headers);
    headers.set("x-nonce", nonce);
    headers.set("Content-Security-Policy", csp);
    const res = NextResponse.next({ request: { headers } });
    res.headers.set("Content-Security-Policy", csp);
    return res;
  };

  let response = next();

  if (!request.nextUrl.pathname.startsWith("/admin")) return response;

  const url = process.env.NEXT_PUBLIC_SUPABASE_URL;
  const anonKey = process.env.NEXT_PUBLIC_SUPABASE_ANON_KEY;
  if (!url || !anonKey) return response;

  const supabase = createServerClient(url, anonKey, {
    cookies: {
      getAll() {
        return request.cookies.getAll();
      },
      setAll(cookiesToSet) {
        for (const { name, value } of cookiesToSet) {
          request.cookies.set(name, value);
        }
        response = next();
        for (const { name, value, options } of cookiesToSet) {
          response.cookies.set(name, value, options);
        }
      },
    },
  });

  const {
    data: { user },
  } = await supabase.auth.getUser();

  const isLoginPage = request.nextUrl.pathname === "/admin/login";
  if (!user && !isLoginPage) {
    // From nextUrl, not new URL("/admin/login", request.url): nextUrl carries the base path, so the
    // redirect lands on /bhw-connect/admin/login on the box and on /admin/login everywhere else.
    const login = request.nextUrl.clone();
    login.pathname = "/admin/login";
    login.search = "";
    return NextResponse.redirect(login);
  }

  return response;
}

export const config = {
  // A literal, as Next requires (it reads this statically). All relative to the base path; Next
  // prefixes matchers itself.
  matcher: [
    // Every /admin request, prefetches included, as before: the session refresh must see them all.
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
