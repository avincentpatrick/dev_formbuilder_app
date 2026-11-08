/** Search only the authoritative child rows already loaded, including rows without shapes or values. */
export function searchMapAreas<T extends { code: string; name: string }>(rows: readonly T[], query: string): T[] {
  const normalize = (text: string) => text.normalize("NFKD").replace(/\p{M}/gu, "").toLocaleLowerCase("en");
  const terms = normalize(query).trim().split(/\s+/).filter(Boolean);
  return rows.filter((row) => {
    const name = normalize(row.name);
    return terms.every((term) => name.includes(term) || row.code.includes(term));
  });
}
