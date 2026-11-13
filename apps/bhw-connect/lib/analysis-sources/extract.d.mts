import type { Extraction, SourceTable } from "./types";
export function extractFile(
  bytes: Uint8Array,
  name: string,
  options?: { crs?: string },
): Promise<Extraction>;
export function tableProfile(name: string, rows: Record<string, unknown>[]): SourceTable;
export function unpack(bytes: Uint8Array): Record<string, Uint8Array>;
