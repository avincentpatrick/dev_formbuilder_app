"use client";

import Link from "next/link";

export default function CatalogError({ reset }: { reset: () => void }) {
  return (
    <section
      role="alert"
      className="mx-auto my-8 w-full max-w-3xl rounded-xl border border-border bg-surface p-6"
    >
      <h1 className="text-xl font-semibold">The catalog could not be loaded</h1>
      <p className="mt-2 text-sm text-muted">
        This does not mean the sources have no data. Please try again.
      </p>
      <div className="mt-4 flex flex-wrap gap-4">
        <button onClick={reset} className="rounded-md bg-accent px-4 py-2 text-white">
          Try again
        </button>
        <Link href="/catalog" className="py-2 underline">
          All locations
        </Link>
      </div>
    </section>
  );
}
