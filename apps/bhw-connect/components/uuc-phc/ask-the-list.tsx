import { ChatLauncher } from "@/components/chat/chat-launcher";
import type { GeoLevel } from "@/lib/filters/schema";

/**
 * The section's "Ask the data" launcher (plan U8). A thin wrapper rather than four call sites
 * repeating the same five props: the starter questions and the placeholder are what a visitor
 * reads before typing anything, so they have to say the same thing on every page of the section,
 * and a fifth page added later should not be able to inherit the BHW ones by omission.
 *
 * **The starter questions are chosen for what they teach, not only for what they answer.** The
 * first two are questions this list can answer and the pages already render. The third is the one
 * this dataset actually attracts — *why* a barangay is on the list — and it is here deliberately,
 * because the honest answer is bounded: the recorded routes are reportable, the assessment behind
 * them is not, and a visitor is better served meeting that boundary in the first click than after
 * typing a question about their own barangay. Rule 2 of `UUC_PHC_SYSTEM_PROMPT` is what makes the
 * answer land that way.
 */
const starterQuestions = (year: string) => [
  `How many barangays here are on the ${year} list?`,
  "Which qualifying route did the most barangays here come in on?",
  "Why is a barangay on this list?",
];

export function AskTheList({
  geoCode,
  geoLevel,
  geoName,
  listYear,
}: {
  geoCode?: string;
  geoLevel?: GeoLevel;
  geoName?: string;
  /** The edition on screen. The assistant reads every edition and answers about the newest unless
   * asked about another (UUC_PHC_SYSTEM_PROMPT), so the questions name this page's year. */
  listYear: string;
}) {
  return (
    <ChatLauncher
      dataset="uuc-phc"
      geoCode={geoCode}
      geoLevel={geoLevel}
      geoName={geoName}
      starterQuestions={starterQuestions(listYear)}
      introLine={`Ask a question about the ${listYear} UUC for PHC list, or an earlier one.`}
      inputPlaceholder="Ask which barangays are listed, or how they qualified…"
      methodologyHref="/uuc-phc/methodology#ask"
    />
  );
}
