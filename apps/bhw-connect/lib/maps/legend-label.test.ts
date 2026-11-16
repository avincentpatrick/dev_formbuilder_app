import { expect, it } from "vitest";
import { mapBinLabel } from "./legend-label";
import { binIndexForValue } from "@/lib/charts/color-scale";
import { STEPZERO_ACCREDITATION_BINS } from "./stepzero-accreditation";
import { PROFILING_BINS } from "./profiling";
it("keeps percentage boundaries consistent with the renderer including exactly 100%", () => {
  const bins = STEPZERO_ACCREDITATION_BINS;
  expect(bins.map((bin, i) => mapBinLabel(bin, i, bins, "%"))).toEqual([
    "0–<25%",
    "25–<50%",
    "50–<75%",
    "75–<100%",
    "100%",
  ]);
  expect(binIndexForValue(25, bins)).toBe(1);
  expect(binIndexForValue(100, bins)).toBe(4);
});
it("preserves profiling's unbounded top category", () => {
  expect(mapBinLabel(PROFILING_BINS.at(-1)!, PROFILING_BINS.length - 1, PROFILING_BINS, "%")).toBe(
    "100% or more",
  );
});
it("retains distinct fine decimal quantiles and year units", () => {
  const bins = [
    { min: 0.01, max: 0.02, color: "#fff" },
    { min: 0.02, max: 0.03, color: "#000" },
  ];
  expect(bins.map((bin, i) => mapBinLabel(bin, i, bins, " years"))).toEqual([
    "0.01–<0.02 years",
    "0.02–0.03 years",
  ]);
});
it("renders a single value once, including zero", () => {
  const bins = [{ min: 0, max: 0, color: "#fff" }];
  expect(mapBinLabel(bins[0], 0, bins)).toBe("0");
});

it("trims interpolation noise while keeping adjacent machine-precision edges distinct", () => {
  const noisy = [
    { min: 0, max: 9.254999999999999, color: "#fff" },
    { min: 9.254999999999999, max: 16.42, color: "#000" },
  ];
  expect(noisy.map((bin, i) => mapBinLabel(bin, i, noisy, " years"))).toEqual([
    "0–<9.255 years",
    "9.255–16.42 years",
  ]);
  expect(noisy[0].max).toBe(9.254999999999999);
  const fine = [
    { min: 1, max: 1.0000000000000002, color: "#fff" },
    { min: 1.0000000000000002, max: 1.0000000000000004, color: "#000" },
  ];
  expect(fine.map((bin, i) => mapBinLabel(bin, i, fine))).toEqual([
    "1–<1.0000000000000002",
    "1.0000000000000002–1.0000000000000004",
  ]);
});

it("does not round large whole counts", () => {
  const bins = [{ min: 9007199254740991, max: Infinity, color: "#fff" }];
  expect(mapBinLabel(bins[0], 0, bins)).toBe("9,007,199,254,740,991 or more");
});
