import { mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { isScreenshotPath, readScreenshot, saveScreenshot, SCREENSHOT_FOLDER } from "./screenshots";

const SESSION = "3f8a1c2e-5b6d-4e7f-8a9b-0c1d2e3f4a5b";
const FILE = "0d6fead7-616a-43d9-88ef-55f315eb964f";

let root: string;

beforeEach(() => {
  root = mkdtempSync(join(tmpdir(), "bhw-screenshots-"));
});

afterEach(() => {
  rmSync(root, { recursive: true, force: true });
});

describe("the screenshot store", () => {
  it("writes into the bucket's layout and reads it back with its type", async () => {
    const path = `${SESSION}/${FILE}.jpg`;
    expect(await saveScreenshot(path, new Uint8Array([1, 2, 3]), root)).toBe(true);

    expect(readFileSync(join(root, SCREENSHOT_FOLDER, SESSION, `${FILE}.jpg`))).toEqual(Buffer.from([1, 2, 3]));
    expect(await readScreenshot(path, root)).toEqual({ bytes: Buffer.from([1, 2, 3]), contentType: "image/jpeg" });
  });

  it("never overwrites a screenshot", async () => {
    const path = `${SESSION}/${FILE}.png`;
    await saveScreenshot(path, new Uint8Array([1]), root);

    await expect(saveScreenshot(path, new Uint8Array([2]), root)).rejects.toThrow();
    expect((await readScreenshot(path, root))?.bytes).toEqual(Buffer.from([1]));
  });

  it("refuses every path that is not one the feedback route writes", async () => {
    writeFileSync(join(root, "secret.txt"), "not yours");
    for (const path of [
      "../secret.txt",
      `${SESSION}/../../secret.txt`,
      `/${SESSION}/${FILE}.jpg`,
      `C:\\meridian\\bhw\\.env`,
      `${SESSION}/${FILE}.gif`,
      `${SESSION}\\${FILE}.jpg`,
      `${SESSION.toUpperCase()}/${FILE}.jpg`,
      "",
    ]) {
      expect(isScreenshotPath(path)).toBe(false);
      expect(await readScreenshot(path, root)).toBeNull();
      expect(await saveScreenshot(path, new Uint8Array([9]), root)).toBe(false);
    }
  });

  it("answers null for a well-formed path with no file", async () => {
    expect(await readScreenshot(`${SESSION}/${FILE}.jpg`, root)).toBeNull();
  });
});
