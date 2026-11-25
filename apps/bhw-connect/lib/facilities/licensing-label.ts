export function licensingLabel(status: string | null): string {
  if (status === "With License") return "Licensed";
  if (status === "Without License") return "No current licence recorded";
  return "Licence status not stated";
}
