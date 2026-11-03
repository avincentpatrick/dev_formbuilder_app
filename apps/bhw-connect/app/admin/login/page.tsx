import { redirect } from "next/navigation";
import { getAdminAuthResult } from "@/lib/db/require-admin";
import { meridianUrl } from "@/lib/meridian-url";

/**
 * BHW Connect has no password of its own any more: its admins sign in with their Meridian account
 * (Meridian's decision D178), on the site this app shares an address with. Meridian's sign-in takes no
 * return address — one would be an open redirect — so this page says how to come back instead: the
 * BHW Connect link in Meridian's top bar.
 *
 * Anyone already signed in goes on to `/admin`, whose layout either lets them in or says they are not
 * an admin.
 */
export const dynamic = "force-dynamic";

export default async function AdminLoginPage() {
  const result = await getAdminAuthResult();
  if (result.status !== "signed_out") redirect("/admin");

  const signIn = meridianUrl("/login");

  return (
    <div className="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center gap-6 px-4 py-16">
      <div>
        <h1 className="text-xl font-semibold tracking-tight">Admin sign in</h1>
        <p className="mt-1 text-sm text-muted">BHW Connect staff only.</p>
      </div>

      <p className="text-sm">
        BHW Connect&apos;s admins sign in with their account on this site. Sign in there, then come back
        with the <strong>BHW Connect</strong> link at the top of the page.
      </p>

      {signIn ? (
        <a
          href={signIn}
          className="rounded-md bg-accent px-4 py-2 text-center text-sm font-medium text-accent-foreground"
        >
          Sign in
        </a>
      ) : (
        <p className="text-sm text-muted">
          Sign-in works where this app runs beside Meridian. On a laptop, set BHW_AUTH_MODE=stub.
        </p>
      )}
    </div>
  );
}
