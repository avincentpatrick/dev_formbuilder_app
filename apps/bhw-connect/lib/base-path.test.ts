import { describe, expect, it } from "vitest";
import { normaliseBasePath, stripBasePath, withBasePath } from "./base-path";

describe("normaliseBasePath", () => {
  it.each([
    [undefined, ""],
    ["", ""],
    ["/", ""],
    ["bhw-connect", "/bhw-connect"],
    ["/bhw-connect/", "/bhw-connect"],
    ["  /bhw-connect  ", "/bhw-connect"],
  ])("%j → %j", (value, expected) => {
    expect(normaliseBasePath(value)).toBe(expected);
  });
});

describe("withBasePath", () => {
  it("is the identity with no base path, which is every laptop and Vercel build", () => {
    expect(withBasePath("/api/feedback", "")).toBe("/api/feedback");
  });

  it("prefixes a root-relative path, its query and its hash", () => {
    expect(withBasePath("/api/geo/search?q=cebu", "/bhw-connect")).toBe(
      "/bhw-connect/api/geo/search?q=cebu",
    );
    expect(withBasePath("/methodology#ai", "/bhw-connect")).toBe("/bhw-connect/methodology#ai");
    expect(withBasePath("/", "/bhw-connect")).toBe("/bhw-connect/");
  });

  it("leaves a relative path, a hash, and a protocol-relative or absolute URL alone", () => {
    for (const url of [
      "methodology",
      "#main-content",
      "//cdn.example.org/x.js",
      "https://example.org/x",
    ]) {
      expect(withBasePath(url, "/bhw-connect")).toBe(url);
    }
  });
});

describe("stripBasePath", () => {
  it("returns the app path a browser location carries under the prefix", () => {
    expect(stripBasePath("/bhw-connect/explore", "/bhw-connect")).toBe("/explore");
    expect(stripBasePath("/bhw-connect", "/bhw-connect")).toBe("/");
  });

  it("does not strip a path that merely starts with the same characters", () => {
    expect(stripBasePath("/bhw-connectivity", "/bhw-connect")).toBe("/bhw-connectivity");
  });

  it("is the identity with no base path", () => {
    expect(stripBasePath("/explore", "")).toBe("/explore");
  });
});
