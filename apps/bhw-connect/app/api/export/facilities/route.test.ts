import { expect, it, vi } from "vitest";
import { GET } from "./route";
const read = vi.hoisted(() =>
  vi.fn(async (request: Request) => new Response(new URL(request.url).searchParams.toString())),
);
vi.mock("./report/route", () => ({ GET: read }));
it("sends legacy summary URLs through the verified report handler with page bundles by default", async () => {
  const response = await GET(new Request("http://localhost/?geoCode=PH&geoLevel=national"));
  expect(await response.text()).toBe("geoCode=PH&geoLevel=national&format=png");
});
