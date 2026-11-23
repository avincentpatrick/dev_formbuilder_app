"use client";

import Link from "next/link";
import { usePathname, useSearchParams } from "next/navigation";
import type { ComponentProps } from "react";
import {
  EDITION_PARAM,
  UUC_EDITIONS,
  editionByYear,
  type UucEdition,
} from "@/lib/uuc-phc/editions";

/**
 * The edition the current URL asks for, or null when it asks for none (the default edition).
 * Only a year the section knows counts: an unknown `?edition=` is ignored, as the server ignores it.
 */
export function useEditionYear(): string | null {
  const params = useSearchParams();
  const year = params.get(EDITION_PARAM);
  return editionByYear(year) ? year : null;
}

/**
 * `href` with the current page's `?edition=` carried onto it, when it points inside the section.
 *
 * The default edition never appears in the URL (`withEdition` in lib/uuc-phc/editions.ts), so
 * carrying whatever the current URL holds is exactly right in both cases: a reader on the default
 * edition gets clean links, and one reading 2025 stays on 2025 as they move around the section.
 */
export function carryEdition(href: string, year: string | null): string {
  if (!year || !href.startsWith("/uuc-phc")) return href;
  const [path, hash] = href.split("#", 2);
  if (new RegExp(`[?&]${EDITION_PARAM}=`).test(path)) return href;
  const sep = path.includes("?") ? "&" : "?";
  return `${path}${sep}${EDITION_PARAM}=${year}${hash ? `#${hash}` : ""}`;
}

/** A section link that keeps the reader on the edition they are viewing. */
export function EditionLink({
  href,
  ...rest
}: Omit<ComponentProps<typeof Link>, "href"> & { href: string }) {
  const year = useEditionYear();
  return <Link href={carryEdition(href, year)} {...rest} />;
}

/**
 * The edition switch: one link per available edition, the current one marked. It keeps the page
 * and its other parameters, changing only `?edition=` — so switching on a province page lands on
 * the same province in the other year.
 */
export function EditionSwitch({
  availableYears,
  className,
}: {
  /** Editions this deployment carries, newest first. The first is the default. */
  availableYears: readonly string[];
  className?: string;
}) {
  const pathname = usePathname();
  const params = useSearchParams();
  const requested = params.get(EDITION_PARAM);
  const current =
    requested && availableYears.includes(requested) ? requested : (availableYears[0] ?? null);

  // One edition: a plain badge, as the header showed before there were two.
  if (availableYears.length <= 1) {
    return current ? (
      <span
        className={`rounded-full bg-accent-subtle px-2 py-0.5 text-xs font-medium text-accent ${className ?? ""}`}
      >
        {current}
      </span>
    ) : null;
  }

  const hrefFor = (year: string) => {
    const next = new URLSearchParams(params.toString());
    if (year === availableYears[0]) next.delete(EDITION_PARAM);
    else next.set(EDITION_PARAM, year);
    const query = next.toString();
    return query ? `${pathname}?${query}` : pathname;
  };

  return (
    <nav
      aria-label="List edition"
      className={`inline-flex items-center rounded-full border border-border p-0.5 text-xs font-medium ${className ?? ""}`}
    >
      {availableYears.map((year) => {
        const active = year === current;
        return (
          <Link
            key={year}
            href={hrefFor(year)}
            aria-current={active ? "page" : undefined}
            className={
              active
                ? "rounded-full bg-accent px-2.5 py-0.5 text-accent-foreground"
                : "rounded-full px-2.5 py-0.5 text-muted hover:text-foreground"
            }
          >
            {year}
          </Link>
        );
      })}
    </nav>
  );
}

/** The edition the URL selects among those available, for client components that print it. */
export function useCurrentEdition(availableYears: readonly string[]): UucEdition {
  const requested = useEditionYear();
  const year =
    requested && availableYears.includes(requested) ? requested : (availableYears[0] ?? null);
  return editionByYear(year) ?? UUC_EDITIONS[UUC_EDITIONS.length - 1];
}

/**
 * One fact about the edition the URL selects, as text — for server-rendered chrome (the section
 * footer, the correction note) that sits outside any page and so cannot read the page's params.
 */
export function EditionText({
  availableYears,
  field,
}: {
  availableYears: readonly string[];
  field: "year" | "issuance";
}) {
  const edition = useCurrentEdition(availableYears);
  return <>{field === "year" ? edition.year : edition.issuance}</>;
}
