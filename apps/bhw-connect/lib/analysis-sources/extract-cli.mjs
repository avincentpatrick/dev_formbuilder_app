// Dedicated executable entry point: do not infer execution from path/URL equality.
// Windows short paths, case and release junctions can represent the same file differently.
import { readFile, writeFile } from "node:fs/promises";
import { extractFile } from "./extract.mjs";

const [input, name, options = "{}", output] = process.argv.slice(2);
if (!input || !name)
  throw new Error("Usage: analysis-extractor <input> <name> [options-json] [output]");
const result = await extractFile(new Uint8Array(await readFile(input)), name, JSON.parse(options));
if (output) await writeFile(output, JSON.stringify(result));
else process.stdout.write(JSON.stringify(result));
