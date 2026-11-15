import { z } from "zod";
export const RESEARCH_TOPICS = {
  "barangay-health-workers": "barangay health workers policy issuance",
  referrals: "primary health care referral policy issuance",
  accreditation: "barangay health worker accreditation issuance",
  benefits: "barangay health workers benefits honorarium law",
  traditional: "traditional complementary health care policy issuance",
} as const;
export const researchSchema = z.object({
  site: z.enum(["doh.gov.ph", "pitahc.gov.ph", "officialgazette.gov.ph"]).default("doh.gov.ph"),
  topic: z
    .enum(["barangay-health-workers", "referrals", "accreditation", "benefits", "traditional"])
    .default("barangay-health-workers"),
  reference: z
    .string()
    .regex(/^[0-9-]{0,24}$/)
    .default(""),
});
export type ResearchSettings = z.infer<typeof researchSchema>;
/** Only fixed public vocabulary and a numeric issuance reference leave this boundary. */
export function publicResearchQuery(raw: ResearchSettings) {
  const s = researchSchema.parse(raw);
  return `site:${s.site} ${RESEARCH_TOPICS[s.topic]}${s.reference ? ` ${s.reference}` : ""}`;
}
