/** Display wording confirmed by the BHW owner on 19 November 2026.
 * Source observations and unrecognized categories remain unchanged. */
const educationLabels: Record<string, string> = {
  "elementary level": "Attended elementary school but did not graduate",
  "high school level": "Attended high school but did not graduate",
  "college level": "Attended college but did not graduate",
};

export function demographicLabel(id: string, label: string | undefined): string | undefined {
  if (label === undefined) return label;
  const key = label.trim().toLowerCase();
  if (id === "bhw.demographics.bloodtype" && key === "u") return "Unknown";
  if (id === "bhw.demographics.education")
    return Object.hasOwn(educationLabels, key) ? educationLabels[key] : label;
  return label;
}

export function demographicSourceNote(id: string): string {
  if (id === "bhw.demographics.bloodtype")
    return "The original source code “U” means “Unknown”: the blood type is unknown, not a separate blood group.";
  if (id === "bhw.demographics.education")
    return "The original source labels “Elementary Level”, “High School Level” and “College Level” mean the person attended that stage but did not graduate. Graduate categories remain separate.";
  return "";
}
