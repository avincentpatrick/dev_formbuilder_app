/** Public diagnostics distinguish server failures without exposing stderr, paths or file content. */
export function extractionFailure(error: unknown): { code: string; message: string } {
  const e = error as { code?: string; stderr?: string; killed?: boolean; message?: string } | null;
  if (/ERR_MODULE_NOT_FOUND|MODULE_NOT_FOUND/.test(e?.stderr ?? ""))
    return {
      code: "runtime_dependency_missing",
      message:
        "The server's file extractor could not start because a required runtime module is missing. Contact the dashboard administrator, then retry extraction after the server is repaired.",
    };
  if (e?.code === "EACCES" || e?.code === "EPERM" || /\b(EACCES|EPERM)\b/.test(e?.stderr ?? ""))
    return {
      code: "access_denied",
      message:
        "The server was denied access to an extraction file or runtime. Contact the dashboard administrator to check the service account's file permissions.",
    };
  if (e?.killed || e?.code === "ETIMEDOUT")
    return {
      code: "processing_limit",
      message:
        "File extraction exceeded the server's processing limit. Its contents have not been analysed; contact the dashboard administrator or split a large file.",
    };
  if (e?.code === "ENOENT")
    return {
      code: "extraction_file_missing",
      message:
        "A server extraction file or expected extraction output is missing. Contact the dashboard administrator; this does not establish that the uploaded file is empty or corrupt.",
    };
  if (e?.message === "Original content hash mismatch")
    return {
      code: "original_integrity_mismatch",
      message:
        "The stored original does not match its saved checksum. Contact the dashboard administrator before retrying extraction.",
    };
  return {
    code: "runtime_failed",
    message:
      "The server could not finish file extraction. Its contents have not been analysed. Retry extraction, and contact the dashboard administrator if it fails again.",
  };
}
