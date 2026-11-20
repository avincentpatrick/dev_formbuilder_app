import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { expect, it, vi } from "vitest";
vi.mock("next/navigation", () => ({ useSearchParams: () => new URLSearchParams() }));
import { PovertyMunicipalities } from "./poverty-municipalities";
it("does not present mismatched Manila districts as a complete municipal comparison", () => {
  const html = renderToStaticMarkup(
    <PovertyMunicipalities province="13806" name="Manila" selected={null} />,
  );
  expect(html).toContain("source districts and reporting geography do not match");
  expect(html).not.toContain("<table");
  expect(html).not.toContain("Loading");
});
