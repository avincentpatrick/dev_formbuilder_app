"use client";

import { withBasePath } from "@/lib/base-path";
import { meridianUrl } from "@/lib/meridian-url";

/**
 * Signs out of Meridian, which is the one sign-in on this site (Meridian's decision D178): a POST to its
 * `/logout` carrying the `XSRF-TOKEN` cookie Laravel sets for a script to send back, then home to BHW
 * Connect. Where no Meridian runs beside this app, there is nothing to sign out of.
 */
export function SignOutButton() {
  async function handleSignOut() {
    const logout = meridianUrl("/logout");
    if (logout) {
      const token = xsrfToken();
      await fetch(logout, {
        method: "POST",
        credentials: "same-origin",
        headers: {
          accept: "application/json",
          "x-requested-with": "XMLHttpRequest",
          ...(token ? { "x-xsrf-token": token } : {}),
        },
      }).catch(() => undefined);
    }
    window.location.assign(withBasePath("/"));
  }

  return (
    <button
      type="button"
      onClick={handleSignOut}
      className="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-surface"
    >
      Sign out
    </button>
  );
}

function xsrfToken(): string | null {
  const prefix = "XSRF-TOKEN=";
  const pair = document.cookie
    .split(";")
    .map((part) => part.trim())
    .find((part) => part.startsWith(prefix));
  return pair ? decodeURIComponent(pair.slice(prefix.length)) : null;
}
