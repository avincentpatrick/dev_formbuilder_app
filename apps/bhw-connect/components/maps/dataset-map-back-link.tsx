"use client";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { parseMapChoice } from "@/lib/maps/prototype";
import { subjectOfRelease } from "@/lib/maps/subjects";
import { catalogHref } from "@/lib/catalog/navigation";
import type { CatalogId } from "@/lib/catalog/types";

/** Native workspace history can change geography without rerendering the server page. */
export function DatasetMapBackLink() {
  const params = useSearchParams();
  const choice = parseMapChoice(new URLSearchParams(params.toString()));
  const subject = choice ? subjectOfRelease(choice.dataset) : undefined;
  // Back to the card this map belongs to; districts have their own page rather than a catalog card.
  const back = !subject
    ? { href: "/", label: "Dataset portal" }
    : subject.id === "districts"
      ? { href: "/districts", label: "Legislative districts" }
      : {
          href: catalogHref(
            choice && subject.id === "bhw"
              ? { mode: "psgc", level: choice.level, code: choice.code }
              : { mode: "all" },
            subject.id as CatalogId,
          ),
          label: `${subject.title} dataset`,
        };
  return (
    <Link href={back.href} className="text-sm underline">
      ← {back.label}
    </Link>
  );
}
