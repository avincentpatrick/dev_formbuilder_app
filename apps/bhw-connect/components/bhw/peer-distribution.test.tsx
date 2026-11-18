import React from "react";
import { renderToStaticMarkup } from "react-dom/server";
import { expect, it } from "vitest";
import { BhwPeerDistribution } from "./peer-distribution";
import type { MapPayload } from "@/lib/maps/prototype";
const data: MapPayload = {
  state: "available",
  area: "Synthetic region",
  childLevel: "province",
  source: "Synthetic approved source",
  date: "2025-01-01",
  license: null,
  geometryUrl: "",
  total: null,
  rows: [
    { code: "01", name: "First", value: 0 },
    { code: "02", name: "Second", value: 0 },
    { code: "03", name: "Missing", value: null },
  ],
};
const render = (payload = data, selected: string | null = "01") =>
  renderToStaticMarkup(
    <BhwPeerDistribution
      data={payload}
      selected={selected}
      unit="%"
      label="Synthetic share"
      period="2025"
      onSelect={() => {}}
    />,
  );
it("shows an all-zero distribution, ties, locality/peer scope and exact accessible values", () => {
  const html = render();
  expect(html).toContain('role="img"');
  expect(html).toContain("2 places share this rank");
  expect(html).toContain("one province");
  expect(html).toContain("1st of 2 provinces in Synthetic region");
  expect(html).toContain('aria-label="Peer ranks and exact values"');
  expect(html).not.toContain("NaN");
});
it("does not invent a marker for missing selected data", () => {
  const html = render(data, "03");
  expect(html).toContain("it is not ranked");
  expect(html).toContain("No selected marker");
});
it("does not expose values from navigation-only responses in a plot or ranking", () => {
  const html = render({
    ...data,
    valueSupport: "navigation-only",
    rows: [{ code: "01", name: "Rejected", value: 987654321 }],
  });
  expect(html).not.toContain("987654321");
  expect(html).not.toContain('role="img"');
  expect(html).toContain("No ranks or distribution");
});
