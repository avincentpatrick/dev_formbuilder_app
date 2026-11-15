import { expect, it } from "vitest";
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { mkdtemp, writeFile, readFile, rm, symlink } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join, resolve } from "node:path";
const exec = promisify(execFile);
it("executes through a directory junction with spaces and rejects missing arguments", async () => {
  const dir = await mkdtemp(join(tmpdir(), "bhw cli entry "));
  try {
    const current = join(dir, "current release");
    await symlink(resolve("lib/analysis-sources"), current, "junction");
    const script = join(current, "extract-cli.mjs");
    const input = join(dir, "synthetic.csv"),
      output = join(dir, "result.json");
    await writeFile(input, "area,count\nA,2\nB,4\n");
    await exec(
      process.execPath,
      ["--preserve-symlinks-main", script, input, "synthetic.csv", "{}", output],
      { cwd: dir },
    );
    const result = JSON.parse(await readFile(output, "utf8"));
    expect(result.status).toBe("ready");
    expect(result.tables[0].rows.map((row: { count: number }) => row.count)).toEqual([2, 4]);
    await expect(exec(process.execPath, [script], { cwd: dir })).rejects.toMatchObject({ code: 1 });
  } finally {
    await rm(dir, { recursive: true, force: true });
  }
}, 20000);
