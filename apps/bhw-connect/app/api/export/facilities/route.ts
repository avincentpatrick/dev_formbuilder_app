import { GET as reportGET } from "./report/route";
export const runtime = "nodejs";
/** Existing summary URLs now use the same verified full report; images arrive as page bundles. */
export async function GET(request: Request) {
  const url = new URL(request.url);
  if (!url.searchParams.has("format")) url.searchParams.set("format", "png");
  return reportGET(new Request(url, request));
}
