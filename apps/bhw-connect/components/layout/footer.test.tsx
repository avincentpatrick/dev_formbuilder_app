import { expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { Footer } from "./footer";
it("does not assign one release's source or a fallback license to every figure", () => {
  const html = renderToStaticMarkup(<Footer />);
  expect(html).toContain("Sources, reference dates and licenses vary by dataset");
  expect(html).toContain("Missing license information does not establish");
  expect(html).not.toContain("creativecommons.org");
  expect(html).not.toContain("CC BY 4.0");
  expect(html).not.toContain("Official DOH");
});
