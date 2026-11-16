import { InnovationEndorsement } from "@/components/branding/innovation-endorsement";
import Link from "next/link";

export function Footer() {
  return (
    <footer className="mt-auto border-t border-border bg-surface">
      <div className="mx-auto flex max-w-6xl flex-col gap-3 px-4 py-8 text-sm text-muted sm:px-6">
        <p>
          Sources, reference dates and licenses vary by dataset. Check the source notes beside each
          figure before reusing data. Missing license information does not establish permission to
          reuse it.
        </p>
        <div className="flex flex-wrap gap-x-4 gap-y-1">
          <Link href="/" className="underline underline-offset-2 hover:text-accent">
            iconnect home
          </Link>
          <Link href="/methodology" className="underline underline-offset-2 hover:text-accent">
            Methodology
          </Link>
          <Link href="/privacy" className="underline underline-offset-2 hover:text-accent">
            Privacy
          </Link>
          <Link href="/feedback" className="underline underline-offset-2 hover:text-accent">
            Feedback
          </Link>
          <Link href="/roadmap" className="underline underline-offset-2 hover:text-accent">
            Roadmap
          </Link>
        </div>
        <p>BHW Connect is an independent public-interest project, not affiliated with DOH.</p>
        <InnovationEndorsement />
      </div>
    </footer>
  );
}
