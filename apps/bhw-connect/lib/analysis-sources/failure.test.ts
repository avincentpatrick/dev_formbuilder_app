import { describe, it, expect } from "vitest";
import { extractionFailure } from "./failure";
describe("source extraction failure diagnostics", () => {
  it("identifies an unavailable server module without exposing stderr or blaming the file", () => {
    const result = extractionFailure({
      code: 1,
      stderr:
        "ERR_MODULE_NOT_FOUND: Cannot find package 'exceljs' imported from C:\\private\\release\\analysis-extractor.mjs",
    });
    expect(result.code).toBe("runtime_dependency_missing");
    expect(result.message).toContain("server's file extractor");
    expect(result.message).not.toContain("C:\\private");
    expect(result.message).not.toContain("corrupt");
  });
  it("does not misreport a permission or missing-output failure as a processing limit", () => {
    expect(extractionFailure({ code: "EACCES" }).code).toBe("access_denied");
    expect(extractionFailure({ code: "ENOENT" }).code).toBe("extraction_file_missing");
    expect(extractionFailure({ killed: true }).code).toBe("processing_limit");
  });
  it("does not invent a file-format cause for an unknown runtime error", () => {
    expect(extractionFailure(new Error("private document text"))).toEqual({
      code: "runtime_failed",
      message: expect.stringContaining("server could not finish"),
    });
    expect(extractionFailure(new Error("private document text")).message).not.toContain(
      "private document text",
    );
  });
});
