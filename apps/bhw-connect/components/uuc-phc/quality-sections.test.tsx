import { describe, expect, it } from "vitest";
import { renderToStaticMarkup } from "react-dom/server";
import { ReconciliationSection, isSuluMove } from "./quality-sections";
import type { UucPhcPublishedDelta } from "@/lib/db/uuc-phc-quality";

const row = (geoCode: string, geoName: string, nPublished: number, nListed: number): UucPhcPublishedDelta => ({
  geoCode,
  geoLevel: "region",
  geoName,
  sourceLabel: geoName,
  nPublished,
  nListed,
  delta: nListed - nPublished,
  sourcePage: 37,
  sourceAsOf: "2025-10-01",
});

const barmm = row("19", "BARMM", 400, 313);
const ix = row("09", "REGION IX", 523, 610);

describe("ReconciliationSection with Sulu filed under Region IX", () => {
  it("recognises the Sulu move only as equal and opposite BARMM / Region IX differences", () => {
    expect(isSuluMove([barmm, ix])).toBe(true);
    expect(isSuluMove([barmm, row("09", "REGION IX", 523, 600)])).toBe(false);
    expect(isSuluMove([row("05", "REGION V", 757, 760), ix])).toBe(false);
  });

  it("still renders when the national total agrees and two regions differ", () => {
    const html = renderToStaticMarkup(<ReconciliationSection deltas={[barmm, ix]} />);
    expect(html).toContain("The national total agrees");
    expect(html).toContain("This difference is a geography choice.");
    expect(html).toContain("313");
    expect(html).toContain("610");
    expect(html).not.toContain("Why they differ is not recorded");
  });

  it("keeps the generic reading for any other difference", () => {
    const national: UucPhcPublishedDelta = { ...row("PH", "TOTAL", 5987, 5991), geoLevel: "national" };
    const html = renderToStaticMarkup(
      <ReconciliationSection deltas={[national, row("04", "REGION IV-A", 195, 200)]} />,
    );
    expect(html).toContain("Why they differ is not recorded");
    expect(html).not.toContain("geography choice");
  });

  it("still says the two agree when nothing differs", () => {
    expect(renderToStaticMarkup(<ReconciliationSection deltas={[]} />)).toContain("the two agree");
  });
});
